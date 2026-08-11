<?php
/**
 * Core logic for creating a system checkpoint.
 * Supports a callback for real-time progress reporting (used by worker).
 */
if (!function_exists('sc_get_db_live_fingerprint')) {
    function sc_get_db_live_fingerprint($realPath) {
        if (!file_exists($realPath)) return null;
        
        $fpData = [
            'main_size' => filesize($realPath),
            'main_mtime' => filemtime($realPath),
            'main_hash' => md5_file($realPath)
        ];

        $wal = $realPath . '-wal';
        if (file_exists($wal)) {
            $fpData['wal_size'] = filesize($wal);
            $fpData['wal_mtime'] = filemtime($wal);
            $fpData['wal_hash'] = md5_file($wal);
        } else {
            $fpData['wal'] = 'none';
        }

        $shm = $realPath . '-shm';
        if (file_exists($shm)) {
            $fpData['shm_size'] = filesize($shm);
            $fpData['shm_mtime'] = filemtime($shm);
        } else {
            $fpData['shm'] = 'none';
        }

        $journal = $realPath . '-journal';
        if (file_exists($journal)) {
            $fpData['journal_size'] = filesize($journal);
            $fpData['journal_mtime'] = filemtime($journal);
        } else {
            $fpData['journal'] = 'none';
        }

        return [
            'composite_hash' => md5(json_encode($fpData)),
            'details' => $fpData
        ];
    }
}

function sc_perform_create($zipPath, $clientState = null, $callback = null, $type = 'auto', $baseRef = null) {
    $root = CJOS_PATH_ROOT;
    $relStorage = str_replace($root . '/', '', CJOS_PATH_STORAGE);
    $relBackups = str_replace($root . '/', '', CJOS_PATH_APP . '/backups');
    
    if ($type === 'auto') {
        $type = 'diff';
        $majors = glob(CJOS_PATH_APP . "/backups/checkpoints/MAJOR_*.zip");
        if (!empty($majors)) {
            rsort($majors);
            $baseRef = basename($majors[0]);
        } else {
            $type = 'major';
        }
    }

    if ($callback) {
        $modeLabel = ($type === 'major') ? "FULL SYSTEM (MAJOR)" : "DIFFERENTIAL (CHANGES ONLY)";
        $callback('log', "Mode: $modeLabel");
        if ($baseRef) $callback('log', "Comparing against: $baseRef");
    }

    // STRICT ENFORCEMENT: Ensure filename prefix matches the resolved type.
    $dir = dirname($zipPath);
    $base = basename($zipPath);
    
    // Strip any existing system prefixes to get the clean label
    $cleanLabel = preg_replace('/^(MAJOR|DIFF)_\d{8}_\d{6}_/', '', $base);
    if ($cleanLabel === $base) $cleanLabel = preg_replace('/^(MAJOR|DIFF)_/', '', $base);
    
    // Re-apply the correct prefix based on the FINAL resolved type
    $prefix = ($type === 'major') ? 'MAJOR_' : 'DIFF_';
    $zipPath = $dir . '/' . $prefix . $cleanLabel;

    // Standardized Exclusions
    $exDirs = [$relStorage, '.git', 'vendor', 'node_modules', 'backups'];
    $exFiles = ['access_log', 'access.log', 'error_log', 'client_state_backup.zip'];
    if ($callback) $callback('log', "Scanning filesystem...");

    // PASS 1: DISCOVERY
$allFiles =[];
$manifest =[];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
foreach ($it as $file) {
    $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));
    $parts = explode('/', $rel);
        
    if (in_array($parts[0], $exDirs)) continue;
    if (strpos($rel, $relBackups) === 0) continue;
        
    $fn = $file->getFilename();
    if (in_array($fn, $exFiles) || substr($fn, -4) === '-wal' || substr($fn, -4) === '-shm' || strpos($fn, '.tmp') !== false || substr($fn, -4) === '.bak') continue;
        
    $real = $file->getRealPath();
$allFiles[$rel] = ['real' => $real, 'hash' => md5_file($real), 'size' => $file->getSize()];
    }
        
    $total = count($allFiles);
if ($callback) $callback('log', "Discovered $total files to secure.");

// --- PASS 1.1: FETCH BASE DATABASE STATE & MANIFEST (IF DIFFERENTIAL) ---
$baseDbState = [];
$baseManifest = [];
if ($type === 'diff' && $baseRef) {
    $baseZipPath = CJOS_PATH_APP . '/backups/checkpoints/' . $baseRef;
    if (file_exists($baseZipPath)) {
        $baseZip = new ZipArchive();
        if ($baseZip->open($baseZipPath) === TRUE) {
            $baseDbStateJson = $baseZip->getFromName('sc_db_state.json');
            if ($baseDbStateJson) {
                $baseDbState = json_decode($baseDbStateJson, true) ?: [];
            }
            $baseManifestJson = $baseZip->getFromName('sc_manifest.json');
            if ($baseManifestJson) {
                $baseManifest = json_decode($baseManifestJson, true) ?: [];
            }
            $baseZip->close();
        }
    }
}

// --- PASS 1.5: DATABASE STAGING (VACUUM INTO -> IN-MEMORY WITH SIDECAR FINGERPRINTING) ---
$stagedDbContents = [];
$currentDbState = [];
$dbStagingDir = CJOS_PATH_APP . '/backups/checkpoints/db_staging_' . uniqid();
$dbStagingCreated = false;

foreach ($allFiles as $rel => &$data) {
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if ($ext === 'db' || $ext === 'sqlite') {
        $liveFp = sc_get_db_live_fingerprint($data['real']);
            
        // Check if database + sidecar WAL/SHM/journal files are unchanged since base snapshot
        if ($type === 'diff' && isset($baseDbState[$rel]) && isset($baseManifest[$rel]) 
            && isset($baseDbState[$rel]['composite_hash']) 
            && $baseDbState[$rel]['composite_hash'] === ($liveFp['composite_hash'] ?? '')) {
                
            $currentDbState[$rel] = $baseDbState[$rel];
            $data['hash'] = $baseManifest[$rel];
            if ($callback) $callback('log', "Database unchanged (skipping vacuum): $rel");
            continue;
        }

        if (!$dbStagingCreated) {
            if (!is_dir($dbStagingDir)) mkdir($dbStagingDir, 0777, true);
            $dbStagingCreated = true;
        }
        if ($callback) $callback('log', "Mirroring database state: $rel");
            
        $tempCopyName = md5($rel) . '.db';
        $tempCopyPath = $dbStagingDir . '/' . $tempCopyName;
            
        try {
            $tempPdo = new PDO("sqlite:" . $data['real']);
            $tempPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $tempPdo->exec("PRAGMA busy_timeout = 15000;"); // Wait up to 15s if DB is locked
            $tempPdo->exec("PRAGMA wal_checkpoint(TRUNCATE);"); // Force flush WAL to main DB
            $tempPdo->exec("VACUUM INTO " . $tempPdo->quote($tempCopyPath));
            $tempPdo = null;

            if (!file_exists($tempCopyPath)) {
                throw new Exception("Temporary staging file was not created.");
            }

            $content = file_get_contents($tempCopyPath);
            if ($content === false) {
                throw new Exception("Failed to read temporary staging file.");
            }

            $stagedDbContents[$rel] = $content;
                            
            // Normalize header bytes in memory for logical binary hash parity
            $normalized = $content;
            if (strlen($normalized) >= 100) {
                $normalized = substr_replace($normalized, str_repeat("\x00", 4), 24, 4);
                $normalized = substr_replace($normalized, str_repeat("\x00", 4), 40, 4);
                $normalized = substr_replace($normalized, str_repeat("\x00", 8), 92, 8);
            }
                            
            $data['hash'] = md5($normalized);
            $data['size'] = strlen($content);
            $currentDbState[$rel] = $liveFp;
            @unlink($tempCopyPath);
        } catch (Exception $e) {
            if ($callback) {
                $callback('log', "FATAL ERROR: Database staging failed for $rel.", "error");
                $callback('log', "Reason: " . $e->getMessage(), "error");
            }
            if (file_exists($tempCopyPath)) {
                @unlink($tempCopyPath);
            }
            if ($dbStagingCreated && is_dir($dbStagingDir)) {
                @rmdir($dbStagingDir);
            }
            return false;
        }
    }
}
unset($data);

if ($dbStagingCreated) {
    @rmdir($dbStagingDir);
}

$filesToAdd = [];
$deletedFiles = [];
$diffMeta = null;
$estCompressedBytes = 0;

if ($type === 'diff' && $baseRef) {
    $baseZipPath = CJOS_PATH_APP . '/backups/checkpoints/' . $baseRef;
    if (!file_exists($baseZipPath)) {
        if ($callback) $callback('log', "Base snapshot missing. Falling back to Major.", "warn");
        $type = 'major';
        $zipPath = str_replace('DIFF_', 'MAJOR_', $zipPath);
    } else {
        if (!empty($baseManifest)) {
            foreach ($allFiles as $rel => $data) {
                if (!isset($baseManifest[$rel]) || $baseManifest[$rel] !== $data['hash']) {
                    $filesToAdd[$rel] = $data;
                    $size = $data['size'];
                    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                    if (in_array($ext, ['php', 'js', 'css', 'json', 'md', 'txt', 'html', 'svg', 'csv'])) {
                        $estCompressedBytes += $size * 0.22;
                    } elseif ($ext === 'db' || $ext === 'sqlite') {
                        $estCompressedBytes += $size * 0.35;
                    } else {
                        $estCompressedBytes += $size * 0.98;
                    }
                }
            }
            foreach ($baseManifest as $rel => $hash) {
                if (!isset($allFiles[$rel])) {
                    $deletedFiles[] = $rel;
                }
            }
            $diffMeta = [
                'type' => 'differential',
                'base_ref' => $baseRef,
                'deleted' => $deletedFiles,
                'created_at' => date('Y-m-d H:i:s')
            ];
        } else {
            $type = 'major';
            $zipPath = str_replace('DIFF_', 'MAJOR_', $zipPath);
        }
    }
}// Always generate the full manifest representing the target state
foreach ($allFiles as $rel => $data) {
    $manifest[$rel] = $data['hash'];
}

if ($type === 'major') {
    $filesToAdd = $allFiles;
    foreach ($filesToAdd as $rel => $f) {
        $size = $f['size'];
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (in_array($ext, ['php', 'js', 'css', 'json', 'md', 'txt', 'html', 'svg', 'csv'])) {
            $estCompressedBytes += $size * 0.22;
        } elseif ($ext === 'db' || $ext === 'sqlite') {
            $estCompressedBytes += $size * 0.35;
        } else {
            $estCompressedBytes += $size * 0.98;
        }
    }
}

// PASS 2: STAGING
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $idx = 0;
    $addTotal = count($filesToAdd);
            
    // Throttle progress and logging every 10 files to prevent script buffer overhead
    foreach ($filesToAdd as $rel => $f) {
        if (isset($stagedDbContents[$rel])) {
            $zip->addFromString($rel, $stagedDbContents[$rel]);
        } else {
            $zip->addFile($f['real'], $rel);
        }
                    
        if ($callback) {
            if ($idx % 10 === 0 || $idx === $addTotal - 1) {
                $pct = $addTotal > 0 ? ($idx / $addTotal) * 90 : 90;
                $callback('progress', $pct, "Staging: " . ($idx + 1) . "/$addTotal");
                $callback('task', "Securing: " . $rel);
            }
        }
        $idx++;
    }if ($clientState) {
        if ($callback) $callback('log', "Injecting client-side state...");
        $zip->addFromString('client_state.json', $clientState);
    }

    // Always save the full manifest so the restorer has a single source of truth
    $zip->addFromString('sc_manifest.json', json_encode($manifest));
    $zip->addFromString('sc_db_state.json', json_encode($currentDbState, JSON_PRETTY_PRINT));
    
    if ($type === 'diff' && $diffMeta) {
        $zip->addFromString('sc_diff.json', json_encode($diffMeta));
    }

    if ($callback) {
        $estMb = max(0.01, round($estCompressedBytes / (1024 * 1024), 2));
        $callback('log', "STAGING COMPLETE. Finalizing archive...", "warn");
        $callback('task', "COMPRESSING & WRITING...");
        $callback('progress', 95, "COMPRESSING_EST:$estMb");
    }
        
    $closed = $zip->close();
        
    if ($closed && file_exists($zipPath)) {
        if ($callback) {
            $callback('progress', 100, "DONE");
            $msg = ($type === 'major') 
                ? "SUCCESS: Full system secured ($addTotal files)." 
                : "SUCCESS: $addTotal changed files secured (out of $total total).";
            $callback('log', $msg, "success");
        }
        return true;
    }
}
return false;
}