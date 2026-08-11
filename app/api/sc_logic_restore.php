<?php
/**
 * Core logic for restoring a system checkpoint.
 */
function sc_get_protected_apps() {
    $configFile = CJOS_PATH_DATA . '/app-maker-config.json';
    if (file_exists($configFile)) {
        $data = json_decode(file_get_contents($configFile), true);
        if (isset($data['protected']) && is_array($data['protected'])) {
            return $data['protected'];
        }
    }
    return[];
}

if (!function_exists('sc_get_logical_hash')) {
    function sc_get_logical_hash($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (($ext === 'db' || $ext === 'sqlite') && file_exists($path)) {
            $content = @file_get_contents($path);
            if ($content !== false && strlen($content) >= 100) {
                $content = substr_replace($content, str_repeat("\x00", 4), 24, 4);
                $content = substr_replace($content, str_repeat("\x00", 4), 40, 4);
                $content = substr_replace($content, str_repeat("\x00", 8), 92, 8);
                return md5($content);
            }
        }
        return file_exists($path) ? @md5_file($path) : '';
    }
}

function sc_perform_restore($zipPath, $callback = null, $skipFolders =[]) {
    $root = CJOS_PATH_ROOT;
    $relApp = str_replace($root . '/', '', CJOS_PATH_APP);
    $relStorage = str_replace($root . '/', '', CJOS_PATH_STORAGE);
    $relBackups = str_replace($root . '/', '', CJOS_PATH_APP . '/backups');

    $isDiff = false;
    $baseRef = null;
    $targetManifest = [];
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath) === TRUE) {
        $manifestJson = $zip->getFromName('sc_manifest.json');
        if ($manifestJson) {
            $raw = json_decode($manifestJson, true);
            if (is_array($raw)) {
                foreach ($raw as $k => $v) $targetManifest[str_replace('\\', '/', $k)] = $v;
            }
        }
        
        $diffJson = $zip->getFromName('sc_diff.json');
        if ($diffJson) {
            $diffMeta = json_decode($diffJson, true);
            if ($diffMeta && isset($diffMeta['base_ref'])) {
                $isDiff = true;
                $baseRef = $diffMeta['base_ref'];
            }
        }
        $zip->close();
    } else {
        return false;
    }

    // --- FALLBACK: MANIFEST RECONSTRUCTION ---
    // If sc_manifest.json is missing (common in interrupted large backups), reconstruct it.
    if (empty($targetManifest)) {
        if ($callback) $callback('log', "Manifest missing. Reconstructing from archive...", "warn");
        
        if ($isDiff && $baseRef) {
            $baseZipPath = CJOS_PATH_APP . '/backups/checkpoints/' . $baseRef;
            if (file_exists($baseZipPath)) {
                $bz = new ZipArchive();
                if ($bz->open($baseZipPath) === TRUE) {
                    $baseManifest = json_decode($bz->getFromName('sc_manifest.json'), true) ?: [];
                    $bz->close();
                    
                    $z = new ZipArchive();
                    if ($z->open($zipPath) === TRUE) {
                        $diffMeta = json_decode($z->getFromName('sc_diff.json'), true);
                        $deleted = $diffMeta['deleted'] ?? [];
                        foreach ($deleted as $d) unset($baseManifest[str_replace('\\', '/', $d)]);
                        
                        for($i = 0; $i < $z->numFiles; $i++) {
                            $name = str_replace('\\', '/', $z->getNameIndex($i));
                            if (in_array($name, ['sc_diff.json', 'client_state.json', 'sc_manifest.json'])) continue;
                            $baseManifest[$name] = "reconstructed"; 
                        }
                        $targetManifest = $baseManifest;
                        $z->close();
                    }
                }
            }
        } else {
            // Major reconstruction: The manifest is simply every file currently in the ZIP.
            $z = new ZipArchive();
            if ($z->open($zipPath) === TRUE) {
                for($i = 0; $i < $z->numFiles; $i++) {
                    $name = str_replace('\\', '/', $z->getNameIndex($i));
                    if (in_array($name, ['sc_diff.json', 'client_state.json', 'sc_manifest.json'])) continue;
                    $targetManifest[$name] = "reconstructed"; 
                }
                $z->close();
            }
        }
    }

    if (empty($targetManifest)) {
        if ($callback) $callback('log', "ERROR: Could not resolve target manifest.", "error");
        return false;
    }

    $runningLogic = [
        "recovery.php",
        str_replace('\\', '/', $relApp) . "/plugins/SystemCheckpoint.php",
        "app/api/checkpoint_worker.php",
        "app/api/sc_logic_create.php",
        "app/api/sc_logic_restore.php",
        "app/api/sc_logic_bunker.php"
    ];

    // --- PHASE 1: CLEANUP (Delete files not in manifest) ---
    if ($callback) $callback('log', "Syncing environment: Checking for ghost files...");
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), 
        RecursiveIteratorIterator::CHILD_FIRST
    );

    $ghostCount = 0;
    foreach ($it as $file) {
        // Normalize path for cross-platform comparison
        $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));
        $parts = explode('/', $rel);
        
        if ($parts[0] === $relStorage) continue;
        if (strpos($rel, $relBackups) === 0 || $parts[0] === "backups") continue;
        if (in_array($file->getFilename(), ["conjure.db", "conjure.db-journal", "conjure.db-wal", "conjure.db-shm", "patch-history-private.json"])) continue;
        if (in_array($rel, $runningLogic)) continue;
        if ($file->getFilename() === "client_state.json") continue;

        $isSkipped = false;
        foreach ($skipFolders as $skipFolder) {
            $skipPath = 'apps/' . $skipFolder;
            if (strpos($rel, $skipPath . '/') === 0 || $rel === $skipPath) {
                $isSkipped = true;
                break;
            }
        }
        if ($isSkipped) continue;

        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            if (!isset($targetManifest[$rel])) {
                if ($callback) $callback('task', "Removing ghost: $rel");
                @unlink($file->getRealPath());
                $ghostCount++;
            }
        }
    }
    if ($callback) {
        if ($ghostCount > 0) $callback('log', "Cleanup complete: Removed $ghostCount ghost files.");
        else $callback('log', "Cleanup complete: No ghost files found.");
    }

    // --- PHASE 2: SMART UPDATE (Extract only if missing or hash mismatch) ---
    if ($callback) $callback('log', "Syncing environment: Updating changed/missing files...");
    
    $zCurrent = new ZipArchive();
    $zBase = null;
    
    if ($zCurrent->open($zipPath) !== TRUE) return false;
    if ($isDiff) {
        $zBase = new ZipArchive();
        $baseZipPath = CJOS_PATH_APP . '/backups/checkpoints/' . $baseRef;
        if ($zBase->open($baseZipPath) !== TRUE) {
            // FAILSAFE: The parent is missing, but we don't abort.
            // If this is a "Full Differential" (Poisoned Major), it contains everything anyway.
            if ($callback) $callback('log', "WARNING: Parent snapshot ($baseRef) not found. Attempting standalone recovery from current archive...", "warn");
            $zBase = null; 
        }
    }

    $total = count($targetManifest);
    $idx = 0;
    $updateCount = 0;
    
    // Count how many actually need work first for better logging
    foreach ($targetManifest as $rel => $targetHash) {
        $isSkipped = false;
        $bn = basename($rel);
        if (in_array($bn, ['patch-history-private.json', 'conjure.db', 'conjure.db-journal', 'conjure.db-wal', 'conjure.db-shm'])) $isSkipped = true;
        foreach ($skipFolders as $skipFolder) {
            $skipPath = 'apps/' . $skipFolder;
            if (strpos($rel, $skipPath . '/') === 0 || $rel === $skipPath) { $isSkipped = true; break; }
        }
        if ($isSkipped) continue;
        if (!file_exists($root . '/' . $rel) || sc_get_file_hash($root . '/' . $rel) !== $targetHash) $updateCount++;
    }
    $logFrequency = ($updateCount < 50) ? 1 : 20;

    foreach ($targetManifest as $rel => $targetHash) {
        $isSkipped = false;
        $bn = basename($rel);
        if (in_array($bn, ['patch-history-private.json', 'conjure.db', 'conjure.db-journal', 'conjure.db-wal', 'conjure.db-shm'])) $isSkipped = true;
        foreach ($skipFolders as $skipFolder) {
            $skipPath = 'apps/' . $skipFolder;
            if (strpos($rel, $skipPath . '/') === 0 || $rel === $skipPath) { $isSkipped = true; break; }
        }
        if ($isSkipped) continue;

        $fullPath = $root . '/' . $rel;
        $needsUpdate = false;
        $isMissing = !file_exists($fullPath);

        if ($isMissing) {
            $needsUpdate = true;
        } else {
            $currentHash = sc_get_file_hash($fullPath);
            if ($currentHash !== $targetHash) $needsUpdate = true;
        }

        if ($needsUpdate) {
            if ($callback && ($idx % $logFrequency === 0 || $idx === $total - 1)) {
                $label = $isMissing ? "Restoring: $rel (Missing)" : "Updating: $rel (Modified)";
                $callback('task', $label);
            }
            
            // Ensure parent directory exists before extraction
            $dir = dirname($fullPath);
            if (!is_dir($dir)) mkdir($dir, 0777, true);

            // Prevent SQLite WAL Mismatch Corruption for App DBs
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if (($ext === 'db' || $ext === 'sqlite') && basename($fullPath) !== 'conjure.db') {
                @unlink($fullPath . '-wal');
                @unlink($fullPath . '-shm');
            }

            if ($zCurrent->locateName($rel) !== false) {
                $zCurrent->extractTo($root, $rel);
            } elseif ($zBase && $zBase->locateName($rel) !== false) {
                $zBase->extractTo($root, $rel);
            }
        }

        if ($callback && ($idx % 20 === 0 || $idx === $total - 1)) {
            $pct = ($idx / $total) * 100;
            $callback('progress', $pct, "Syncing: " . ($idx + 1) . "/$total");
        }
        $idx++;
    }

    // Extract client state if present
    $clientState = $zCurrent->getFromName('client_state.json');
    
    $zCurrent->close();
    if ($zBase) $zBase->close();

    if ($callback) $callback('log', "SUCCESS: System state synchronized.", "success");
    return $clientState ? json_decode($clientState, true) : true;
}

if (!function_exists('sc_get_file_hash')) {
    function sc_get_file_hash($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (($ext === 'db' || $ext === 'sqlite') && file_exists($path)) {
            $content = @file_get_contents($path);
            if ($content !== false && strlen($content) >= 100) {
                $content = substr_replace($content, str_repeat("\x00", 4), 24, 4);
                $content = substr_replace($content, str_repeat("\x00", 4), 40, 4);
                $content = substr_replace($content, str_repeat("\x00", 8), 92, 8);
                return md5($content);
            }
        }
        return file_exists($path) ? @md5_file($path) : '';
    }
}