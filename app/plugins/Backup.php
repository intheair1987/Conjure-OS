<?php
// ==============================================================================
// PLUGIN: Backup & Export
// DESCRIPTION: System ZIP & TXT Export.
// 1. Full System Backup (ZIP): Absolute snapshot, no exclusions.
// 2. Source Code Export (TXT): Safe for AI, excludes private keys/audio.
// 3. Batch Processing: 200 files/batch with live MB size tracking.
// ==============================================================================

// ------------------------------------------------------------------------------
// 1. BACKEND HANDLERS
// ------------------------------------------------------------------------------

$bk_config_file = CJOS_PATH_DATA . '/backup-config.json';

// --- STREAMING ZIP CLASS ---
class SimpleZipStream {
    private $output;
    private $entries = [];
    private $offset = 0;

    public function __construct($file = 'php://output') {
        $this->output = fopen($file, 'w');
    }

    public function addFile($filePath, $zipPath) {
        if (!file_exists($filePath)) return;
        
        $fileSize = filesize($filePath);
        $crc = hash_file('crc32b', $filePath);
        $crcDec = hexdec($crc);
        
        // Local File Header
        $header = "\x50\x4b\x03\x04";
        $header .= "\x0a\x00"; // Version
        $header .= "\x00\x00"; // Flags
        $header .= "\x00\x00"; // Method (Store)
        $header .= pack("v", 0); // Time (Placeholder)
        $header .= pack("v", 0); // Date (Placeholder)
        $header .= pack("V", $crcDec);
        $header .= pack("V", $fileSize); // Compressed
        $header .= pack("V", $fileSize); // Uncompressed
        $header .= pack("v", strlen($zipPath));
        $header .= pack("v", 0); // Extra len
        $header .= $zipPath;

        fwrite($this->output, $header);
        $this->entries[] = [
            'name' => $zipPath,
            'offset' => $this->offset,
            'crc' => $crcDec,
            'size' => $fileSize
        ];
        $this->offset += strlen($header) + $fileSize;

        // Stream File Content
        $fp = fopen($filePath, 'rb');
        while (!feof($fp)) {
            fwrite($this->output, fread($fp, 8192));
            flush(); // Keep connection alive
        }
        fclose($fp);
    }

    public function finish() {
        $cdrStart = $this->offset;
        $count = 0;

        // Central Directory
        foreach ($this->entries as $e) {
            $cdr = "\x50\x4b\x01\x02";
            $cdr .= "\x00\x00"; // Version made by
            $cdr .= "\x0a\x00"; // Version needed
            $cdr .= "\x00\x00"; // Flags
            $cdr .= "\x00\x00"; // Method
            $cdr .= pack("v", 0); // Time
            $cdr .= pack("v", 0); // Date
            $cdr .= pack("V", $e['crc']);
            $cdr .= pack("V", $e['size']);
            $cdr .= pack("V", $e['size']);
            $cdr .= pack("v", strlen($e['name']));
            $cdr .= pack("v", 0); // Extra
            $cdr .= pack("v", 0); // Comment
            $cdr .= pack("v", 0); // Disk start
            $cdr .= pack("v", 0); // Attrs
            $cdr .= pack("V", 32); // Ext attrs
            $cdr .= pack("V", $e['offset']);
            $cdr .= $e['name'];
            fwrite($this->output, $cdr);
            $count++;
            $this->offset += strlen($cdr);
        }

        $cdrSize = $this->offset - $cdrStart;

        // End of Central Directory
        $eocd = "\x50\x4b\x05\x06";
        $eocd .= "\x00\x00"; // Disk num
        $eocd .= "\x00\x00"; // Disk CDR start
        $eocd .= pack("v", $count);
        $eocd .= pack("v", $count);
        $eocd .= pack("V", $cdrSize);
        $eocd .= pack("V", $cdrStart);
        $eocd .= "\x00\x00"; // Comment len
        fwrite($this->output, $eocd);
        
        fclose($this->output);
    }
}

if (!function_exists('bk_log')) {
    function bk_log($type, $msg) {
        $logFile = CJOS_PATH_DATA . '/backup-log.json';
        $logs = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
        if (!is_array($logs)) $logs = [];
        $logs[] = ['time' => date('Y-m-d H:i:s'), 'type' => $type, 'msg' => $msg];
        if (count($logs) > 100) $logs = array_slice($logs, -100);
        file_put_contents($logFile, json_encode($logs));
    }
}

if (!function_exists('is_clean_export_excluded')) {
    function is_clean_export_excluded($relPath) {
        static $cleanApps = null;
        static $cleanApkProjects = null;
        if ($cleanApps === null) {
            $cleanAppsFile = CJOS_PATH_DATA . '/backup-clean-apps-private.json';
            $data = file_exists($cleanAppsFile) ? json_decode(file_get_contents($cleanAppsFile), true) : [];
            if (is_array($data) && isset($data['included'])) {
                $cleanApps = $data['included'];
                $cleanApkProjects = $data['apk_projects'] ?? null;
            } else {
                $cleanApps = is_array($data) ? $data : [];
                $cleanApkProjects = null;
            }
        }

        $norm = str_replace('\\', '/', $relPath);
        $norm = ltrim($norm, '/');
        $normLower = strtolower($norm);
        
        // 1. AppMaker Apps inclusions and sanitation
        if (strpos($norm, 'apps/') === 0) {
            $parts = explode('/', $norm);
            $appId = $parts[1] ?? '';
            
            // Exclude app entirely if not selected for clean export
            if (!$appId || !in_array($appId, $cleanApps)) {
                return true;
            }

            // Exclude all apps/*/data/ folders except for ApkStudio
            if (isset($parts[2]) && strtolower($parts[2]) === 'data' && $appId !== 'ApkStudio') {
                return true;
            }

            // Special filtering for ApkStudio projects
            if ($appId === 'ApkStudio' && count($parts) >= 5 && $parts[2] === 'data' && $parts[3] === 'projects') {
                $projId = $parts[4];
                if (is_array($cleanApkProjects) && !in_array($projId, $cleanApkProjects)) {
                    return true;
                }
            }
            
            // If app IS included, ensure app content is sanitized:
            // Exclude private files (*-private.*)
            if (strpos(basename($norm), '-private') !== false) return true;
            
            // Exclude databases (*.db, *.db-shm, *.db-wal, *.sqlite) & journal files
            if (preg_match('/\.(db|db-shm|db-wal|sqlite|sqlite3)$/i', $norm) || strpos($normLower, '-journal') !== false) return true;
            
            // Exclude git / temp / backups inside app
            if (strpos($normLower, '.git') !== false) return true;
            
            return false;
        } elseif ($norm === 'apps') {
            return true;
        }
        
        // 2. Exclude recordings & backups
        if (strpos($normLower, 'recordings/') === 0 || strpos($norm, 'app/backups/') === 0 || strpos($norm, 'backups/') === 0) return true;
        
        // 3. Exclude private files (*-private.*)
        if (strpos(basename($norm), '-private') !== false) return true;
        
        // 4. Exclude project planner user projects (data/projects/)
        if (strpos($norm, 'app/data/projects/') === 0 || strpos($norm, 'data/projects/') === 0) return true;
        
        // Exclude vault folder (app/data/vault/)
        if (strpos($norm, 'app/data/vault/') === 0 || strpos($norm, 'data/vault/') === 0) return true;
        
        // 5. Exclude edit logs & edit log history & auto-generated system structure
        $base = basename($norm);
        if (strpos($base, 'edit-log') === 0) return true;
        if ($base === 'system_structure.md') return true;
        
        // 6. Exclude databases (*.db, *.db-shm, *.db-wal) EXCEPT app/data/demo/demo.db
        if (preg_match('/\.(db|db-shm|db-wal)$/i', $norm)) {
            if ($norm !== 'app/data/demo/demo.db' && $norm !== 'data/demo/demo.db') {
                return true;
            }
        }
        
        // 7. Exclude temp files / backups / staged patches
        if (strpos($norm, 'conjure.db-journal') !== false) return true;
        if (strpos($norm, '.tmp') !== false) return true;
        if (strpos($norm, 'data/temp_backup.zip') !== false) return true;
        if (strpos($norm, 'data/temp_cloud_backup.zip') !== false) return true;
        if (strpos($norm, 'data/backup_manifest.json') !== false) return true;
        if (strpos($norm, 'data/backup-log.json') !== false) return true;
        if (strpos($norm, 'staged_patches/') !== false) return true;
        if (strpos($norm, '.git') !== false) return true;

        return false;
    }
}

if (isset($_POST['plugin_action'])) {

    // --- BATCH ZIP: INIT (DEPRECATED BUT KEPT FOR COMPATIBILITY) ---
    if ($_POST['plugin_action'] === 'bk_init') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $rootPath = CJOS_PATH_ROOT;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS), 
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        $fileList = [];
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $relativePath = substr($file->getRealPath(), strlen($rootPath) + 1);
                // Exclude the temp files we are about to create/use
                if ($relativePath === 'data/temp_backup.zip') continue;
                if ($relativePath === 'data/backup_manifest.json') continue;
                $fileList[] = $relativePath;
            }
        }

        if (!is_dir(CJOS_PATH_DATA)) mkdir(CJOS_PATH_DATA, 0777, true);
        file_put_contents(CJOS_PATH_DATA . '/backup_manifest.json', json_encode($fileList));
        
        // Initialize fresh ZIP with placeholder to ensure valid header
        $zipPath = CJOS_PATH_DATA . '/temp_backup.zip';
        if(file_exists($zipPath)) unlink($zipPath);
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
            $zip->addFromString('backup_info.txt', 'Full System Backup Generated: ' . date('Y-m-d H:i:s'));
            $zip->close();
        }

        echo json_encode(['status' => 'success', 'total' => count($fileList)]);
        exit;
    }

    // --- BATCH ZIP: PROCESS CHUNK ---
    if ($_POST['plugin_action'] === 'bk_process') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $start = (int)$_POST['start'];
        $batch = (int)$_POST['batch'];
        $root = CJOS_PATH_ROOT;
        $zipPath = CJOS_PATH_DATA . '/temp_backup.zip';
        $manifestPath = CJOS_PATH_DATA . '/backup_manifest.json';

        if (!file_exists($manifestPath)) {
            echo json_encode(['status' => 'error', 'message' => 'Manifest missing']); exit;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $slice = array_slice($manifest, $start, $batch);
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === TRUE) {
            foreach ($slice as $relPath) {
                $fullPath = $root . '/' . $relPath;
                if (file_exists($fullPath)) {
                    $zip->addFile($fullPath, $relPath);
                }
            }
            $zip->close();
            
            // Integrity: Get real size on disk
            clearstatcache(true, $zipPath);
            $currentSize = file_exists($zipPath) ? filesize($zipPath) : 0;

            echo json_encode([
                'status' => 'success', 
                'zip_size' => $currentSize
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Zip append failed']);
        }
        exit;
    }

    // --- CONFIG HANDLERS ---
    if ($_POST['plugin_action'] === 'bk_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $conf = ['show_zip_shortcut' => false, 'show_code_shortcut' => false, 'chunk_size' => 850];
        if (file_exists($bk_config_file)) $conf = json_decode(file_get_contents($bk_config_file), true);
        echo json_encode(['status' => 'success', 'config' => $conf]);
        exit;
    }

    if ($_POST['plugin_action'] === 'bk_get_sizes') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $root = CJOS_PATH_ROOT;
        $relBackups = str_replace($root . '/', '', CJOS_PATH_APP . '/backups');
        $relRecordings = str_replace($root . '/', '', CJOS_PATH_STORAGE);
        
        $relBackups = str_replace('\\', '/', $relBackups);
        $relRecordings = str_replace('\\', '/', $relRecordings);

        $fullSize = 0;
        $noSnapSize = 0;
        $noSnapNoRecSize = 0;
        $cleanSize = 0;
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), 
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $realPath = $file->getRealPath();
                $relPath = str_replace('\\', '/', substr($realPath, strlen($root) + 1));
                
                // Match exclusions in bk_stream_live
                if (strpos($relPath, 'conjure.db-journal') !== false) continue;
                if (strpos($relPath, 'data/temp_backup.zip') !== false) continue;
                if (strpos($relPath, '.git') !== false) continue;

                $sz = $file->getSize();
                $fullSize += $sz;

                $isSnap = (strpos($relPath, $relBackups) === 0);
                $isRec = (strpos($relPath, $relRecordings) === 0 || strpos(strtolower($relPath), 'recordings/') === 0);

                if (!$isSnap) {
                    $noSnapSize += $sz;
                }
                if (!$isSnap && !$isRec) {
                    $noSnapNoRecSize += $sz;
                }
                if (!is_clean_export_excluded($relPath)) {
                    $cleanSize += $sz;
                }
            }
        }

        // Fetch latest backup file info from Dropbox
        $priv_file = CJOS_PATH_DATA . '/backup-private.json';
        $priv = file_exists($priv_file) ? json_decode(file_get_contents($priv_file), true) : [];
        $dbx_token = $priv['dropbox_token'] ?? '';
        $lastCloudDate = 'Never';

        if (!empty($dbx_token)) {
            $conf_file = CJOS_PATH_DATA . '/backup-config.json';
            $conf = file_exists($conf_file) ? json_decode(file_get_contents($conf_file), true) : [];
            $subfolder = $conf['dropbox_subfolder'] ?? '/ConjureBackups';

            $ch = curl_init('https://api.dropboxapi.com/2/files/list_folder');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $dbx_token,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['path' => rtrim($subfolder, '/')]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3-second constraint prevents blocking the UI on sluggish networks
            $res = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($res, true);
            if (isset($data['entries'])) {
                $zipFiles = array_filter($data['entries'], function($e) { 
                    return $e['.tag'] === 'file' && strpos($e['name'], '.zip') !== false; 
                });
                if (!empty($zipFiles)) {
                    usort($zipFiles, function($a, $b) { 
                        return strtotime($b['server_modified']) - strtotime($a['server_modified']); 
                    });
                    $latest = reset($zipFiles);
                    $lastCloudDate = date('M j, Y, H:i', strtotime($latest['server_modified']));
                }
            } elseif (isset($data['error_summary']) && strpos($data['error_summary'], 'path/not_found') !== false) {
                $lastCloudDate = 'Never (No Folder)';
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'full' => round($fullSize / 1024 / 1024, 1) . ' MB',
            'no_snaps' => round($noSnapSize / 1024 / 1024, 1) . ' MB',
            'no_snaps_no_recs' => round($noSnapNoRecSize / 1024 / 1024, 1) . ' MB',
            'clean' => round($cleanSize / 1024 / 1024, 1) . ' MB',
            'last_cloud' => $lastCloudDate
        ]);
        exit;
    }

    if ($_POST['plugin_action'] === 'bk_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $conf = [
            'show_zip_shortcut' => ($_POST['show_zip_shortcut'] === 'true'),
            'show_code_shortcut' => ($_POST['show_code_shortcut'] === 'true'),
            'chunk_size' => (int)($_POST['chunk_size'] ?? 850),
            'excluded_plugins' => isset($_POST['excluded_plugins']) ? json_decode($_POST['excluded_plugins'], true) : []
        ];
        file_put_contents($bk_config_file, json_encode($conf));
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['plugin_action'] === 'bk_get_cloud_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $conf = file_exists($bk_config_file) ? json_decode(file_get_contents($bk_config_file), true) : [];
        $priv_file = CJOS_PATH_DATA . '/backup-private.json';
        $priv = file_exists($priv_file) ? json_decode(file_get_contents($priv_file), true) : [];
        
        if (empty($priv['api_token'])) {
            $priv['api_token'] = bin2hex(random_bytes(16));
            file_put_contents($priv_file, json_encode($priv));
        }

        echo json_encode([
            'status' => 'success',
            'dropbox_token' => $priv['dropbox_token'] ?? '',
            'subfolder' => $conf['dropbox_subfolder'] ?? '/ConjureBackups',
            'default_option' => $conf['cloud_default_option'] ?? 'no_snaps_no_recs',
            'retention_limit' => $conf['cloud_retention_limit'] ?? 5,
            'api_token' => $priv['api_token']
        ]);
        exit;
    }

    if ($_POST['plugin_action'] === 'bk_save_cloud_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $conf = file_exists($bk_config_file) ? json_decode(file_get_contents($bk_config_file), true) : [];
        $priv_file = CJOS_PATH_DATA . '/backup-private.json';
        $priv = file_exists($priv_file) ? json_decode(file_get_contents($priv_file), true) : [];

        $conf['dropbox_subfolder'] = $_POST['subfolder'] ?? '/ConjureBackups';
        $conf['cloud_default_option'] = $_POST['default_option'] ?? 'no_snaps_no_recs';
        $conf['cloud_retention_limit'] = (int)($_POST['retention_limit'] ?? 5);
        file_put_contents($bk_config_file, json_encode($conf));

        $priv['dropbox_token'] = $_POST['dropbox_token'] ?? '';
        file_put_contents($priv_file, json_encode($priv));

        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['plugin_action'] === 'bk_get_logs') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $logFile = CJOS_PATH_DATA . '/backup-log.json';
        $logs = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
        echo json_encode(['status' => 'success', 'logs' => $logs]);
        exit;
    }

    if ($_POST['plugin_action'] === 'bk_clear_logs') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $logFile = CJOS_PATH_DATA . '/backup-log.json';
        if (file_exists($logFile)) unlink($logFile);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['plugin_action'] === 'bk_get_clean_app_options') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $appsRoot = CJOS_PATH_APPS;
        $folders = array_filter(glob($appsRoot . '/*'), 'is_dir');
        $apps = [];
        
        foreach ($folders as $folder) {
            $id = basename($folder);
            $manifestPath = $folder . '/manifest.json';
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                $apps[] = [
                    'id' => $id,
                    'name' => $manifest['name'] ?? $id,
                    'icon' => $manifest['icon'] ?? '📦'
                ];
            }
        }

        // Scan ApkStudio projects if present
        $apkProjects = [];
        $apkProjectsDir = CJOS_PATH_APPS . '/ApkStudio/data/projects';
        if (is_dir($apkProjectsDir)) {
            $pFolders = array_filter(glob($apkProjectsDir . '/*'), 'is_dir');
            foreach ($pFolders as $pf) {
                $pId = basename($pf);
                $apkProjects[] = [
                    'id' => $pId,
                    'name' => $pId
                ];
            }
        }
        
        $cleanAppsFile = CJOS_PATH_DATA . '/backup-clean-apps-private.json';
        $data = file_exists($cleanAppsFile) ? json_decode(file_get_contents($cleanAppsFile), true) : [];
        
        if (is_array($data) && isset($data['included'])) {
            $included = $data['included'];
            $includedApkProjects = $data['apk_projects'] ?? array_column($apkProjects, 'id');
        } else {
            $included = is_array($data) ? $data : [];
            $includedApkProjects = array_column($apkProjects, 'id');
        }
        
        echo json_encode([
            'status' => 'success', 
            'apps' => $apps, 
            'included' => $included,
            'apk_projects' => $apkProjects,
            'included_apk_projects' => $includedApkProjects
        ]);
        exit;
    }

    if ($_POST['plugin_action'] === 'bk_save_clean_app_options') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $cleanAppsFile = CJOS_PATH_DATA . '/backup-clean-apps-private.json';
        $existing = file_exists($cleanAppsFile) ? json_decode(file_get_contents($cleanAppsFile), true) : [];
        
        $included = isset($_POST['included']) ? (is_array($_POST['included']) ? $_POST['included'] : json_decode($_POST['included'], true)) : (isset($existing['included']) ? $existing['included'] : (is_array($existing) ? $existing : []));
        $apkProjects = isset($_POST['apk_projects']) ? (is_array($_POST['apk_projects']) ? $_POST['apk_projects'] : json_decode($_POST['apk_projects'], true)) : ($existing['apk_projects'] ?? []);
        
        $payload = [
            'included' => array_values($included),
            'apk_projects' => array_values($apkProjects)
        ];
        
        file_put_contents($cleanAppsFile, json_encode($payload, JSON_PRETTY_PRINT));
        
        echo json_encode(['status' => 'success']);
        exit;
    }
}

if (isset($_GET['plugin_action'])) {
    
    // --- ACTION: HEADLESS CLOUD BACKUP ---
    if ($_GET['plugin_action'] === 'bk_cloud_backup') {
        while (ob_get_level()) ob_end_clean();
        ignore_user_abort(true); // Allow upload to finish even if browser drops connection
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        header('Content-Type: application/json');

        $priv_file = CJOS_PATH_DATA . '/backup-private.json';
        $conf_file = CJOS_PATH_DATA . '/backup-config.json';
        $priv = file_exists($priv_file) ? json_decode(file_get_contents($priv_file), true) : [];
        $conf = file_exists($conf_file) ? json_decode(file_get_contents($conf_file), true) : [];

        $token = $_GET['api_token'] ?? '';
        if (empty($priv['api_token']) || $token !== $priv['api_token']) {
            bk_log('error', 'Unauthorized cloud backup attempt (Invalid API Token).');
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }

        $dbx_token = $priv['dropbox_token'] ?? '';
        if (empty($dbx_token)) {
            bk_log('error', 'Dropbox token missing. Cannot perform backup.');
            echo json_encode(['status' => 'error', 'message' => 'Dropbox token missing']);
            exit;
        }

        $opt = $conf['cloud_default_option'] ?? 'no_snaps_no_recs';
        $subfolder = $conf['dropbox_subfolder'] ?? '/ConjureBackups';
        $limit = (int)($conf['cloud_retention_limit'] ?? 5);

        bk_log('info', "Cloud backup initiated via API. Option: '$opt'.");

        $tempZip = CJOS_PATH_DATA . '/temp_cloud_backup.zip';
        if (file_exists($tempZip)) unlink($tempZip);

        $stream = new SimpleZipStream($tempZip);
        $root = CJOS_PATH_ROOT;
        $relBackups = str_replace('\\', '/', str_replace($root . '/', '', CJOS_PATH_APP . '/backups'));
        $relRecordings = str_replace('\\', '/', str_replace($root . '/', '', CJOS_PATH_STORAGE));

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), 
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $realPath = $file->getRealPath();
                $relPath = str_replace('\\', '/', substr($realPath, strlen($root) + 1));
                
                if (strpos($relPath, 'conjure.db-journal') !== false) continue;
                if (strpos($relPath, 'data/temp_backup.zip') !== false) continue;
                if (strpos($relPath, 'data/temp_cloud_backup.zip') !== false) continue;
                if (strpos($relPath, '.git') !== false) continue;
                
                $isSnap = (strpos($relPath, $relBackups) === 0);
                $isRec = (strpos($relPath, $relRecordings) === 0 || strpos(strtolower($relPath), 'recordings/') === 0);

                if ($opt === 'no_snaps' && $isSnap) continue;
                if ($opt === 'no_snaps_no_recs' && ($isSnap || $isRec)) continue;
                if ($opt === 'clean_export' && is_clean_export_excluded($relPath)) continue;

                $stream->addFile($realPath, $relPath);
            }
        }
        $stream->finish();

        $zipSize = file_exists($tempZip) ? filesize($tempZip) : 0;
        $zipSizeMB = round($zipSize / 1024 / 1024, 1);
        bk_log('success', "ZIP compiled successfully. Size: {$zipSizeMB} MB.");

        // Upload to Dropbox
        $filename = date('Ymd_His') . '_ConjureSystem_' . $opt . '.zip';
        $dropbox_path = rtrim($subfolder, '/') . '/' . $filename;
        bk_log('info', "Uploading to Dropbox: {$dropbox_path}");

        $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
        $api_args = json_encode(['path' => $dropbox_path, 'mode' => 'overwrite', 'autorename' => false, 'mute' => true]);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $dbx_token,
            'Dropbox-API-Arg: ' . $api_args,
            'Content-Type: application/octet-stream'
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($tempZip));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        unlink($tempZip);

        if ($httpCode === 200) {
            bk_log('success', "Dropbox upload successful (200 OK).");
        } else {
            $err = json_decode($response, true);
            $errMsg = $err['error_summary'] ?? $response;
            bk_log('error', "Dropbox upload failed: {$errMsg}");
            echo json_encode(['status' => 'error', 'message' => 'Upload failed']);
            exit;
        }

        // Pruning
        bk_log('info', "Pruning active. Limit: {$limit}. Fetching file list...");
        $ch2 = curl_init('https://api.dropboxapi.com/2/files/list_folder');
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $dbx_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch2, CURLOPT_POST, 1);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(['path' => rtrim($subfolder, '/')]));
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        $listRes = curl_exec($ch2);
        curl_close($ch2);

        $listData = json_decode($listRes, true);
        if (isset($listData['entries'])) {
            $files = array_filter($listData['entries'], function($e) { return $e['.tag'] === 'file' && strpos($e['name'], '.zip') !== false; });
            usort($files, function($a, $b) { return strtotime($a['server_modified']) - strtotime($b['server_modified']); });
            
            $total = count($files);
            if ($total > $limit) {
                $to_delete = array_slice($files, 0, $total - $limit);
                $entries_to_delete = array_map(function($f) { return ['path' => $f['path_lower']]; }, $to_delete);
                
                $ch3 = curl_init('https://api.dropboxapi.com/2/files/delete_batch');
                curl_setopt($ch3, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $dbx_token,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch3, CURLOPT_POST, 1);
                curl_setopt($ch3, CURLOPT_POSTFIELDS, json_encode(['entries' => $entries_to_delete]));
                curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
                curl_exec($ch3);
                curl_close($ch3);

                foreach ($to_delete as $del) {
                    bk_log('success', "Deleted oldest cloud file: '{$del['name']}'");
                }
            }
        }

        bk_log('success', "Backup cycle completed successfully.");
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    // --- ACTION: STREAM LIVE ZIP ---
    if ($_GET['plugin_action'] === 'bk_stream_live') {
        // 1. Disable Buffering & Limits
        while (ob_get_level()) ob_end_clean();
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        
        // 2. Send Headers for Immediate Download
        $isClean = isset($_GET['clean_export']) && $_GET['clean_export'] === 'true';
        $name = date('Ymd_His') . ($isClean ? '_ConjureCleanExport.zip' : '_ConjureSystem.zip');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        flush(); // Force headers to browser

        // 3. Start Streaming
        $stream = new SimpleZipStream();
        $root = CJOS_PATH_ROOT;
        $excludeSnaps = isset($_GET['exclude_snapshots']) && $_GET['exclude_snapshots'] === 'true';
        $excludeRecs = isset($_GET['exclude_recordings']) && $_GET['exclude_recordings'] === 'true';
        $relBackups = str_replace($root . '/', '', CJOS_PATH_APP . '/backups');
        $relRecordings = str_replace($root . '/', '', CJOS_PATH_STORAGE);
        
        $relBackups = str_replace('\\', '/', $relBackups);
        $relRecordings = str_replace('\\', '/', $relRecordings);

        // Recursive Iterator
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), 
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $realPath = $file->getRealPath();
                $relPath = str_replace('\\', '/', substr($realPath, strlen($root) + 1));
                
                // Exclude temp/system files
                if (strpos($relPath, 'conjure.db-journal') !== false) continue;
                if (strpos($relPath, 'data/temp_backup.zip') !== false) continue;
                if (strpos($relPath, '.git') !== false) continue;

                if ($isClean) {
                    if (is_clean_export_excluded($relPath)) continue;
                } else {
                    if ($excludeSnaps && strpos($relPath, $relBackups) === 0) continue;
                    if ($excludeRecs && (strpos($relPath, $relRecordings) === 0 || strpos(strtolower($relPath), 'recordings/') === 0)) continue;
                }

                $stream->addFile($realPath, $relPath);
            }
        }

        $stream->finish();
        exit;
    }

    // --- ACTION: PREPARE OR DOWNLOAD EXPORT (STATELESS CHUNKING) ---
    if (isset($_GET['plugin_action']) && in_array($_GET['plugin_action'], ['bk_prepare_export', 'bk_download_part'])) {
        while (ob_get_level()) ob_end_clean();
        set_time_limit(0); ini_set('memory_limit', '512M');
        $rootPath = CJOS_PATH_ROOT;
        
        $disallowed_extensions = [
            'png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'bmp',
            'mp3', 'ogg', 'wav', 'flac', 'aac', 'm4a',
            'mp4', 'mkv', 'avi', 'mov', 'webm',
            'zip', 'tar', 'gz', 'bz2', '7z', 'rar',
            'db', 'db-shm', 'db-wal', 'sqlite', 'sqlite3',
            'exe', 'dll', 'so', 'dylib', 'a', 'o', 'obj', 'bin', 'apk', 'jar', 'class',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'ttf', 'otf', 'woff', 'woff2', 'eot'
        ];
        $ignored_dirs = ['Recordings', 'recordings', 'backups', '.git', '.idea', 'vscode', 'vendor', 'node_modules'];
        $ignored_files = ['conjure.db', 'conjure.db-journal', '.DS_Store', 'error_log', 'Project_Code_', 'temp_export_', 'sys_map.json'];

        // 1. Gather File List
        $file_list = [];
        $md_file_list = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        
        // Load AppMaker Exclusions
        $am_conf_file = CJOS_PATH_DATA . '/app-maker-config.json';
        $am_excluded = [];
        if (file_exists($am_conf_file)) {
            $am_data = json_decode(file_get_contents($am_conf_file), true);
            $am_excluded = $am_data['excluded_from_export'] ?? [];
        }

        $ui_config_file = CJOS_PATH_DATA . '/ui-config.json';
        $ui_config = file_exists($ui_config_file) ? json_decode(file_get_contents($ui_config_file), true) : [];
        $enabled_map = $ui_config['plugins_enabled'] ?? [];
        
        $bk_conf_data = file_exists($bk_config_file) ? json_decode(file_get_contents($bk_config_file), true) : [];
        $excluded_plugins = $bk_conf_data['excluded_plugins'] ?? [];

        $relApp = str_replace($rootPath . '/', '', CJOS_PATH_APP);
        $relData = str_replace($rootPath . '/', '', CJOS_PATH_DATA);

        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($rootPath) + 1));
            $parts = explode('/', $rel);
            if (in_array($parts[0], $ignored_dirs)) continue; 
            if (strpos($rel, $relApp . '/backups') === 0 || strpos($rel, 'backups/') === 0) continue;
            if (strpos($rel, $relData . '/projects/') === 0) continue; 
            
            // AppMaker Context Filter
            if ($parts[0] === 'apps' && isset($parts[1])) {
                if (in_array($parts[1], $am_excluded)) continue;
            }
            foreach($ignored_files as $ig) { if (strpos(basename($rel), $ig) !== false) continue 2; }
            $relPlugins = str_replace($rootPath . '/', '', CJOS_PATH_PLUGINS);
            if (strpos(basename($rel), '-private') !== false) continue;
            if (strpos($rel, $relPlugins . '/') === 0 && count($parts) === (count(explode('/', $relPlugins)) + 1)) {
                $pName = basename($rel, '.php');
                if (isset($enabled_map['plugin_' . $pName]) && ($enabled_map['plugin_' . $pName] === 'false' || $enabled_map['plugin_' . $pName] === false)) continue;
                if (in_array($pName, $excluded_plugins)) continue;
            }
            $fullPath = $rootPath . '/' . $rel;
            if (function_exists('ce_is_binary_file') && ce_is_binary_file($fullPath)) continue;

            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if ($ext === 'md') $md_file_list[] = $rel;
            else $file_list[] = $rel;
        }
        sort($file_list);
        sort($md_file_list);

        // 2. Generate Universal Header (AI Instructions + MD Files)
        $header = "";
        if (function_exists('get_ai_sys_instructions')) $header .= get_ai_sys_instructions();

        // --- GENERAL MD FILES INJECTION ---
        foreach ($md_file_list as $f) {
            $fPath = $rootPath . '/' . $f;
            if (is_readable($fPath)) {
                $header .= "================================================================================\n";
                $header .= "DOCUMENTATION FILE: $f\n";
                $header .= "================================================================================\n";
                $header .= "```markdown\n" . file_get_contents($fPath) . "\n```\n\n";
            }
        }

        // --- PROJECT PLANNER INJECTION (MULTI-SUPPORT) ---
        $pp_config_file = CJOS_PATH_DATA . '/project-planner-config.json';
        if (file_exists($pp_config_file)) {
            $pp_conf = json_decode(file_get_contents($pp_config_file), true);
            $active_list = [];
            if (!empty($pp_conf['active_projects'])) $active_list = $pp_conf['active_projects'];
            elseif (!empty($pp_conf['active_project'])) $active_list = [$pp_conf['active_project']];
            
            foreach ($active_list as $filename) {
                $plan_path = CJOS_PATH_DATA . '/projects/' . $filename;
                if (file_exists($plan_path)) {
                    $plan_content = file_get_contents($plan_path);
                    $header .= "################################################################################\n";
                    $header .= "### ACTIVE PROJECT MASTER PLAN\n";
                    $header .= "### File: " . $filename . "\n";
                    $header .= "################################################################################\n";
                    $header .= "PROJECT MANAGEMENT PROTOCOL:\n";
                    $header .= "1. CONDITIONAL MAINTENANCE: Only update this plan if your current task directly relates to the project described.\n";
                    $header .= "2. SCOPE VERIFICATION: Check the 'Scope' or 'Relevant Files' section below. If the files you are patching are not listed, do not modify this plan.\n";
                    $header .= "3. BLUEPRINT PROTECTION: Files starting with '_' (like _Blueprint.md) are templates for reference only. NEVER update them unless specifically asked to change the structure of the planning system itself.\n";
                    $header .= "4. YAML INTEGRITY: If updating, ensure the Status and LastUpdated fields are updated to reflect reality.\n";
                    $header .= "--------------------------------------------------------------------------------\n\n";
                    $header .= $plan_content . "\n\n";
                    
                    // Check for linked Audit Checklist
                    $auditPath = CJOS_PATH_DATA . '/projects/' . str_replace('.md', '.audit.json', $filename);
                    if (file_exists($auditPath)) {
                        $header .= "\nLINKED REFACTOR CHECKLIST (JSON):\n";
                        $header .= "File: " . str_replace('.md', '.audit.json', $filename) . "\n";
                        $header .= "```json\n" . file_get_contents($auditPath) . "\n```\n";
                    }

                    $header .= "################################################################################\n\n";
                }
            }
        }

        // 2.5 Dry Run Chunking to Map Files to Parts
        $bk_conf = file_exists($bk_config_file) ? json_decode(file_get_contents($bk_config_file), true) : [];
        $chunkLimit = ((int)($bk_conf['chunk_size'] ?? 850)) * 1000;
        
        $file_to_part_map = [];
        foreach ($md_file_list as $f) { $file_to_part_map[$f] = 1; }
        
        $tempHeader = $header; // Baseline size (Instructions + MDs)
        $currentSize = strlen($tempHeader);
        $currentPart = 1;

        foreach ($file_list as $f) {
            $fPath = $rootPath . '/' . $f;
            if (!is_readable($fPath)) continue;
            $contentLen = strlen(file_get_contents($fPath)) + 200; // +200 for block headers
            
            if ($currentSize + $contentLen > $chunkLimit && $currentSize > strlen($tempHeader)) {
                $currentPart++;
                $currentSize = $contentLen;
            } else {
                $currentSize += $contentLen;
            }
            $file_to_part_map[$f] = $currentPart;
        }

        // 2.6 Generate Tree with Part Annotations
        $tree = [];
        $all_files = array_merge($md_file_list, $file_list);
        foreach ($all_files as $path) {
            $p = explode('/', $path); $curr = &$tree;
            foreach ($p as $part) {
                if (!isset($curr[$part])) $curr[$part] = [];
                $curr = &$curr[$part];
            }
            $curr['__path'] = $path; // Leaf node marker
        }

        $renderNode = function($node, $indent = "") use (&$renderNode, $file_to_part_map) {
            $out = ""; 
            $keys = array_filter(array_keys($node), function($k) { return $k !== '__path'; });
            sort($keys);
            foreach ($keys as $i => $key) {
                $isLast = ($i === count($array_keys = array_values($keys)) - 1);
                $connector = $isLast ? "└── " : "├── "; 
                $childIndent = $isLast ? "    " : "│   ";
                
                if (isset($node[$key]['__path'])) {
                    $pNum = $file_to_part_map[$node[$key]['__path']] ?? 1;
                    $out .= $indent . $connector . $key . " [Part $pNum]\n";
                } else {
                    $out .= $indent . $connector . $key . "/\n" . $renderNode($node[$key], $indent . $childIndent);
                }
            }
            return $out;
        };
        $header .= "================================================================================\nPROJECT STRUCTURE (Part Mapping)\n================================================================================\n```txt\n" . $renderNode($tree) . "```\n\n";

        // 3. Chunking Logic (Re-Calculated on every request for stateless purity)
        $bk_conf = file_exists($bk_config_file) ? json_decode(file_get_contents($bk_config_file), true) : [];
        $chunkLimit = ((int)($bk_conf['chunk_size'] ?? 850)) * 1000;
        
        $chunks = []; 
        $currentChunk = $header;
        $currentFiles = [...$md_file_list]; // MD files are already in the header (Part 1)
        
        $relData = str_replace($rootPath . '/', '', CJOS_PATH_DATA);
        foreach ($file_list as $f) {
            $fPath = $rootPath . '/' . $f; if (!is_readable($fPath)) continue;
            $content = file_get_contents($fPath);
            if ($f === $relData . '/edit-log.json') {
                $logData = json_decode($content, true);
                $el_conf = file_exists(CJOS_PATH_DATA . '/edit-log-config.json') ? json_decode(file_get_contents(CJOS_PATH_DATA . '/edit-log-config.json'), true) : [];
                $limit = isset($el_conf['export_limit']) ? (int)$el_conf['export_limit'] : 15;
                if (is_array($logData) && count($logData) > $limit) $content = json_encode(array_slice($logData, -$limit), JSON_PRETTY_PRINT);
            }
            $ext = pathinfo($f, PATHINFO_EXTENSION);
            $lang = ($ext === 'php') ? 'php' : (($ext === 'js') ? 'javascript' : (($ext === 'css') ? 'css' : (($ext === 'json') ? 'json' : 'txt')));
            $fileBlock = "================================================================================\nFILE START: $f\n================================================================================\n```$lang\n$content\n```\n\n";
            
            // Logic: If adding this file exceeds the limit, finalize chunk and start new one
            if (strlen($currentChunk) + strlen($fileBlock) > $chunkLimit && $currentChunk !== "") {
                $manifest = "================================================================================\n";
                $manifest .= "FILES IN THIS PART (" . count($currentFiles) . "):\n";
                $manifest .= "================================================================================\n";
                $manifest .= implode("\n", $currentFiles) . "\n\n";
                
                $chunks[] = $manifest . $currentChunk;
                
                $currentChunk = $fileBlock;
                $currentFiles = [$f];
            } else { 
                $currentChunk .= $fileBlock; 
                $currentFiles[] = $f;
            }
        }

        if ($currentChunk !== "") {
            $manifest = "================================================================================\n";
            $manifest .= "FILES IN THIS PART (" . count($currentFiles) . "):\n";
            $manifest .= "================================================================================\n";
            $manifest .= implode("\n", $currentFiles) . "\n\n";
            $chunks[] = $manifest . $currentChunk;
        }

        // 4. Respond based on Action
        if ($_GET['plugin_action'] === 'bk_prepare_export') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'parts' => count($chunks)]);
            exit;
        } else {
            $partIdx = (int)$_GET['part'] - 1;
            if (isset($chunks[$partIdx])) {
                $ts = date('md_Hi');
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="Project_Code_' . $ts . '_Part_' . ($partIdx+1) . '.txt"');
                echo $chunks[$partIdx];
                exit;
            } else { die("Invalid Part"); }
        }
    }

    // --- ACTION: EXPORT CODEBASE (LEGACY WRAPPER) --- 
    // Kept to prevent syntax errors if old code calls it, but effectively disabled
    if (false) {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $rootPath = CJOS_PATH_ROOT;
        $allowed_extensions = ['php', 'js', 'css', 'html', 'json', 'sql', 'htaccess', 'txt', 'md'];
        $ignored_dirs = ['Recordings', '.git', '.idea', 'vscode', 'vendor', 'node_modules'];
        $ignored_files = ['conjure.db', 'conjure.db-journal', '.DS_Store', 'error_log', 'Project_Code_'];

        // 1. Gather File List
        $file_list = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        $ui_config = file_exists(CJOS_PATH_DATA . '/ui-config.json') ? json_decode(file_get_contents(CJOS_PATH_DATA . '/ui-config.json'), true) : [];
        $enabled_map = $ui_config['plugins_enabled'] ?? [];

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $rel = str_replace('\\', '/', substr($path, strlen($rootPath) + 1));
            $fileName = $file->getFilename();
            $parts = explode('/', $rel);
            $relBackups = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_APP . '/backups');
if (in_array($parts[0], $ignored_dirs)) continue; if (strpos($rel, $relBackups) === 0) continue;
            foreach($ignored_files as $ig) { if (strpos($fileName, $ig) !== false) continue 2; }
            if ($parts[0] === 'data' && strpos($fileName, '-private') !== false) continue;
            if ($parts[0] === 'plugins' && count($parts) === 2) {
                $pName = basename($fileName, '.php');
                if (isset($enabled_map['plugin_' . $pName]) && ($enabled_map['plugin_' . $pName] === 'false' || $enabled_map['plugin_' . $pName] === false)) continue;
            }
            if ($file->isFile() && in_array(pathinfo($path, PATHINFO_EXTENSION), $allowed_extensions)) $file_list[] = $rel;
        }
        sort($file_list);

        // 2. Generate Universal Header (Instructions + Tree)
        $header = "";
        if (function_exists('get_ai_sys_instructions')) $header .= get_ai_sys_instructions();

        $tree = [];
        foreach ($file_list as $path) {
            $p = explode('/', $path);
            $curr = &$tree;
            foreach ($p as $part) { if (!isset($curr[$part])) $curr[$part] = []; $curr = &$curr[$part]; }
        }
        $renderNode = function($node, $indent = "") use (&$renderNode) {
            $out = ""; $keys = array_keys($node);
            foreach ($keys as $i => $key) {
                $isLast = ($i === count($keys) - 1); $connector = $isLast ? "└── " : "├── "; $childIndent = $isLast ? "    " : "│   ";
                if (empty($node[$key])) $out .= $indent . $connector . $key . "\n";
                else $out .= $indent . $connector . $key . "/\n" . $renderNode($node[$key], $indent . $childIndent);
            }
            return $out;
        };
        $header .= "================================================================================\nPROJECT STRUCTURE\n================================================================================\n```txt\n" . $renderNode($tree) . "```\n\n";

        // 3. Chunking Logic
        $chunks = [];
        $currentChunk = $header;
        $chunkLimit = 850000; // ~830KB limit to stay safely under 1MB per file

        foreach ($file_list as $f) {
            $content = file_get_contents($rootPath . '/' . $f);
            if ($f === 'data/edit-log.json') {
                $logData = json_decode($content, true);
                if (is_array($logData) && count($logData) > 15) $content = json_encode(array_slice($logData, -15), JSON_PRETTY_PRINT);
            }
            $ext = pathinfo($f, PATHINFO_EXTENSION);
            $lang = ($ext === 'php') ? 'php' : (($ext === 'js') ? 'javascript' : (($ext === 'css') ? 'css' : (($ext === 'json') ? 'json' : 'txt')));
            $fileBlock = "================================================================================\nFILE START: $f\n================================================================================\n```$lang\n$content\n```\n\n";

            if (strlen($currentChunk) + strlen($fileBlock) > $chunkLimit && $currentChunk !== $header) {
                $chunks[] = $currentChunk;
                $currentChunk = $header . $fileBlock;
            } else {
                $currentChunk .= $fileBlock;
            }
        }
        $chunks[] = $currentChunk;

        // 4. Output as ZIP if multiple parts, else TXT
        if (count($chunks) > 1) {
            $zipName = 'Codebase_Parts_' . date('Ymd_Hi') . '.zip';
            $zipPath = sys_get_temp_dir() . '/' . $zipName;
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($chunks as $idx => $data) {
                $zip->addFromString('Project_Code_Part_' . ($idx + 1) . '.txt', $data);
            }
            $zip->close();
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipName . '"');
            readfile($zipPath); unlink($zipPath); exit;
        } else {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="Project_Code_' . date('Ymd_Hi') . '.txt"');
            echo $currentChunk; exit;
        }
    }
}

// ------------------------------------------------------------------------------
// 2. FRONTEND SETTINGS UI
// ------------------------------------------------------------------------------
if (!isset($plugin_overlays)) $plugin_overlays = [];

$plugin_overlays[] = <<<'HTML'
<div id="bk-apk-projects-overlay" class="shared-menu-overlay" style="z-index:9600;">
    <div id="bk-apk-projects-sheet" class="shared-bottom-sheet" style="max-height:80vh; display:flex; flex-direction:column;">
        <div style="padding:16px 20px; background:var(--bg-color); border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
            <div>
                <div style="font-size:16px; font-weight:700; color:var(--text-primary);">APK Studio Projects</div>
                <div style="font-size:11px; color:var(--text-secondary); margin-top:2px;">Select projects to include in clean export</div>
            </div>
            <button onclick="bkCloseApkProjectsModal()" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; color:var(--text-primary); display:flex; align-items:center; justify-content:center; cursor:pointer;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px; height:14px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div style="flex:1; overflow-y:auto; padding:16px 20px;">
            <div id="bk-apk-projects-list" style="display:flex; flex-direction:column; gap:8px;"></div>
        </div>
        <div style="padding:12px 20px; padding-bottom:calc(16px + var(--fr-sz-h, 85px) + env(safe-area-inset-bottom)); background:var(--bg-color); border-top:1px solid var(--border-color); flex-shrink:0;">
            <button onclick="bkCloseApkProjectsModal()" class="text-btn" style="background:var(--primary); color:var(--primary-text); width:100%; border-radius:10px; padding:12px; font-weight:700; font-size:13px; border:none;">
                Done
            </button>
        </div>
    </div>
</div>

<div id="bk-clean-overlay" class="shared-menu-overlay" style="z-index:9500;" onclick="if(event.target===this) bkCloseCleanExportMenu(true)">
    <div id="bk-clean-sheet" class="shared-bottom-sheet" style="max-height:85vh; display:flex; flex-direction:column;">
        <div style="padding:20px 24px; background:var(--bg-color); border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
            <div>
                <div style="font-size:18px; font-weight:700; color:var(--text-primary);">Clean Export Settings</div>
                <div style="font-size:12px; color:var(--text-secondary); margin-top:2px;">Select AppMaker apps to include in clean export</div>
            </div>
            <button onclick="bkCloseCleanExportMenu(true)" style="background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--text-primary); display:flex; align-items:center; justify-content:center; cursor:pointer;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px; height:16px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div style="flex:1; overflow-y:auto; padding:20px 24px;">
            <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Include AppMaker Apps</div>
            <p style="font-size:12px; color:var(--text-secondary); margin-top:0; margin-bottom:16px; line-height:1.4;">Selected apps will be included in the clean ZIP and automatically sanitized of private keys and database files.</p>
            <div id="bk-modal-clean-apps-list" style="display:flex; flex-direction:column; gap:8px;"></div>
        </div>
        <div style="padding:16px 24px; padding-bottom:calc(20px + var(--fr-sz-h, 85px) + env(safe-area-inset-bottom)); background:var(--bg-color); border-top:1px solid var(--border-color); display:flex; flex-direction:column; gap:8px; flex-shrink:0; z-index:10;">
            <button id="bk-modal-dl-btn" onclick="bkDownloadCleanZipDirect(this)" class="text-btn" style="background:var(--primary); color:var(--primary-text); width:100%; border-radius:12px; padding:14px; font-weight:700; font-size:14px; border:none; box-shadow:0 4px 12px rgba(0,122,255,0.2); text-align:center;">
                📥 Download Clean Export ZIP
            </button>
        </div>
    </div>
</div>
HTML;

$plugin_settings_map['Backup'] = <<<'HTML'
    <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" onload="window.bkRefreshSizes()" style="display:none;">
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Header Shortcuts</label>
            <span class="setting-desc">Show quick-download icons in the settings header.</span>
        </div>
        <div style="display:flex; flex-direction:column; gap:12px; align-items: flex-end;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Zip</span>
                <div data-sui-switch="true" data-sui-id="bk-show-zip-toggle" data-sui-onchange="bkToggleShortcuts()"></div>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Txt</span>
                <div data-sui-switch="true" data-sui-id="bk-show-code-toggle" data-sui-onchange="bkToggleShortcuts()"></div>
            </div>
        </div>
    </div>

    <div class="setting-item vertical">
        <label class="setting-label">Full System Backup</label>
        <div class="setting-desc">Download the absolute snapshot including all settings, audio, and database as a ZIP file.</div>
        <div style="display:flex; flex-direction:column; gap:8px; margin-top:4px;">
            <button id="bk-btn-full" onclick="bkDownloadZip(this)" class="text-btn" style="background-color: var(--primary); color: var(--primary-text); width: 100%; border-radius: 12px; padding: 12px; font-weight: 600; box-shadow: 0 4px 10px rgba(0,122,255,0.2);">Full System Backup</button>
            <button id="bk-btn-no-snaps" onclick="bkDownloadZip(this, true)" class="text-btn" style="background-color: var(--btn-bg); color: var(--text-primary); width: 100%; border-radius: 12px; padding: 12px; font-weight: 600; border: 1px solid var(--border-color);">No Snapshots</button>
            <button id="bk-btn-no-snaps-no-recs" onclick="bkDownloadZip(this, true, true)" class="text-btn" style="background-color: var(--btn-bg); color: var(--text-primary); width: 100%; border-radius: 12px; padding: 12px; font-weight: 600; border: 1px solid var(--border-color);">No Snapshots & No Recordings</button>
        </div>
    </div>

    <div class="setting-item vertical">
        <label class="setting-label">Clean System Export</label>
        <div class="setting-desc">Download a clean distribution ZIP excluding private keys, user databases, edit logs, project plans, and recordings. Select AppMaker apps to include in the distribution.</div>

        <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
            <button id="bk-btn-clean" onclick="bkOpenCleanExportMenu(this)" class="text-btn" style="background-color: var(--btn-bg); color: var(--text-primary); width: 100%; border-radius: 12px; padding: 12px; font-weight: 600; border: 1px solid var(--border-color);">Clean Export ZIP Options</button>
        </div>
    </div>

    <div class="setting-item vertical">
        <label class="setting-label">Export Source Code</label>
        <div class="setting-desc">Split large exports into manageable chunks for AI uploads.</div>

        <div id="bk-excluded-section" style="display:none; margin-bottom:12px; padding:12px; background:var(--btn-bg); border-radius:12px; border:1px solid var(--border-color);">
            <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Excluded from Export</div>
            <div id="bk-excluded-list" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
        </div>
        
        <div class="setting-item vertical" style="padding: 10px 0; border:none;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:12px; font-weight:700; color:var(--text-secondary);">Chunk Size</span>
                <span id="bk-chunk-val" style="font-size:12px; font-weight:700; color:var(--primary);">850 KB</span>
            </div>
            <input type="range" id="bk-chunk-slider" min="100" max="1000" step="50" value="850" oninput="document.getElementById('bk-chunk-val').innerText = this.value + ' KB'" onchange="bkSaveConfig()" style="margin-top:8px;">
        </div>

        <button onclick="bkPrepareExport(this)" class="text-btn" style="width:100%; background: var(--primary); color: var(--primary-text); border-radius: 12px; padding: 12px; font-weight: 600; margin-top:4px; box-shadow: 0 4px 12px rgba(0,122,255,0.2); border:none;">
            Analyze & Export Code
        </button>
    </div>

    <div class="setting-item vertical" style="margin-top:24px;">
        <label class="setting-label">☁️ Dropbox Cloud Backup</label>
        <div class="setting-desc">Configure headless, single-target Dropbox backups with rolling retention and API triggers.</div>

        <div style="display:flex; flex-direction:column; gap:12px; margin-top:12px;">
            <div class="form-group" style="width:100%; box-sizing:border-box;">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; display:block; margin-bottom:4px;">Access Token</label>
                <div style="display:flex; gap:8px; align-items:center; width:100%; box-sizing:border-box;">
                    <input type="text" id="bk-cloud-token" class="input-secret-key" autocomplete="off" data-bwignore="true" data-1p-ignore="true" data-lpignore="true" spellcheck="false" placeholder="sl.a.your-token-pasted-here" style="flex:1; min-width:0; background:var(--input-bg); color:var(--input-text); border:1px solid var(--border-color); border-radius:8px; padding:10px; font-family:monospace; font-size:12px; box-sizing:border-box;">
                    <button type="button" onclick="bkToggleTokenVisibility(event)" class="text-btn" style="background:var(--btn-bg); color:var(--text-primary); border:1px solid var(--border-color); border-radius:8px; padding:0 12px; font-weight:600; font-size:12px; height:38px; display:flex; align-items:center; justify-content:center; box-sizing:border-box;">Show</button>
                </div>
            </div>
            
            <div class="form-group" style="width:100%; box-sizing:border-box;">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; display:block; margin-bottom:4px;">Target Subfolder</label>
                <input type="text" id="bk-cloud-subfolder" placeholder="/ConjureBackups" style="width:100%; background:var(--input-bg); color:var(--input-text); border:1px solid var(--border-color); border-radius:8px; padding:10px; font-size:13px; box-sizing:border-box;">
            </div>

            <div style="display:flex; gap:12px; width:100%; box-sizing:border-box;">
                <div class="form-group" style="flex:2; box-sizing:border-box;">
                    <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; display:block; margin-bottom:4px;">Default Config</label>
                    <select id="bk-cloud-option" style="width:100%; background:var(--input-bg); color:var(--input-text); border:1px solid var(--border-color); border-radius:8px; padding:10px; font-size:13px; box-sizing:border-box;">
                        <option value="full">Full System Backup</option>
                        <option value="no_snaps">No Snapshots</option>
                        <option value="no_snaps_no_recs">No Snapshots & No Recordings</option>
                        <option value="clean_export">Clean System Export</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1; box-sizing:border-box;">
                    <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; display:block; margin-bottom:4px;">Retention</label>
                    <select id="bk-cloud-limit" style="width:100%; background:var(--input-bg); color:var(--input-text); border:1px solid var(--border-color); border-radius:8px; padding:10px; font-size:13px; box-sizing:border-box;">
                        <option value="1">1</option>
                        <option value="3">3</option>
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="20">20</option>
                    </select>
                </div>
            </div>

            <button onclick="bkSaveCloudConfig()" class="text-btn" style="width:100%; background:var(--btn-bg); color:var(--text-primary); border:1px solid var(--border-color); border-radius:8px; padding:10px; font-weight:600; box-sizing:border-box;">Save Cloud Settings</button>

            <div class="form-group" style="margin-top:8px; width:100%; box-sizing:border-box;">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; display:block; margin-bottom:4px;">Headless cURL Trigger</label>
                <div style="display:flex; gap:8px; align-items:center; width:100%; box-sizing:border-box;">
                    <input type="text" id="bk-cloud-curl" readonly style="flex:1; min-width:0; background:var(--input-bg); color:var(--input-text); border:1px solid var(--border-color); border-radius:8px; padding:10px; font-family:monospace; font-size:11px; opacity:0.7; box-sizing:border-box;">
                    <button onclick="bkCopyCurlCommand()" class="text-btn" style="background:var(--btn-bg); color:var(--text-primary); border:1px solid var(--border-color); border-radius:8px; padding:0 12px; font-weight:600; font-size:12px; height:38px; display:flex; align-items:center; justify-content:center; box-sizing:border-box;">Copy</button>
                </div>
            </div>

            <button id="bk-btn-cloud-run" onclick="bkRunCloudBackup()" class="text-btn" style="background-color: var(--primary); color: var(--primary-text); width: 100%; border-radius: 12px; padding: 12px; font-weight: 600; box-shadow: 0 4px 10px rgba(0,122,255,0.2); margin-top:8px;">📤 Upload to Dropbox Now</button>

            <!-- Console -->
            <div style="margin-top:16px; background:#1e1e1e; border-radius:12px; border:1px solid #333; overflow:hidden;">
                <div style="background:#2d2d2d; padding:8px 12px; display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#aaa; font-size:11px; font-family:monospace; font-weight:700;">Console Debug Terminal</span>
                    <div style="display:flex; gap:8px;">
                        <button onclick="bkCopyLogs()" style="background:none; border:none; color:#4dabf7; font-size:11px; cursor:pointer; font-weight:600;">Copy</button>
                        <button onclick="bkClearLogs()" style="background:none; border:none; color:#ff6b6b; font-size:11px; cursor:pointer; font-weight:600;">Clear</button>
                    </div>
                </div>
                <div id="bk-cloud-console" style="padding:12px; height:180px; overflow-y:auto; font-family:monospace; font-size:11px; color:#ddd; line-height:1.5;">
                    <!-- Logs go here -->
                </div>
            </div>
        </div>
    </div>
HTML;

// ------------------------------------------------------------------------------
// 3. JAVASCRIPT LOGIC
// ------------------------------------------------------------------------------
$plugin_js .= <<<'JS'
// --- BACKUP & EXPORT JS ---

window.addEventListener("load", bkLoadConfig);
window.addEventListener("load", bkLoadCloudConfig);
window.addEventListener("load", bkLoadCleanApps);
let bkLogInterval = null;

let bkCleanAppsIncluded = [];

async function bkLoadCleanApps() {
    try {
        const data = await window.sui.api("bk_get_clean_app_options", {}, { toast: false });
        if (data && data.status === 'success') {
            bkCleanAppsIncluded = data.included || [];
            bkRenderCleanAppsList(data.apps || [], bkCleanAppsIncluded);
        }
    } catch(e) {}
}

function bkRenderCleanAppsList(apps, included) {
    const list = document.getElementById("bk-clean-apps-list");
    if (!list) return;

    if (!apps || apps.length === 0) {
        list.innerHTML = '<div style="font-size:12px; color:var(--text-secondary); text-align:center; padding:8px;">No AppMaker apps found.</div>';
        return;
    }

    list.innerHTML = "";
    apps.forEach(app => {
        const isChecked = included.includes(app.id);
        const item = document.createElement("div");
        item.style.cssText = "background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; padding:8px 12px; display:flex; justify-content:space-between; align-items:center;";
        item.innerHTML = `
            <div style="display:flex; align-items:center; gap:8px; min-width:0;">
                <span style="font-size:16px;">${app.icon}</span>
                <span style="font-size:13px; font-weight:600; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${app.name}</span>
            </div>
            ${window.suiSwitch('bk-clean-app-' + app.id, isChecked, `bkToggleCleanApp('${app.id}', this.checked)`)}
        `;
        list.appendChild(item);
    });
}

window.bkToggleCleanApp = async function(appId, checked) {
    if (checked) {
        if (!bkCleanAppsIncluded.includes(appId)) bkCleanAppsIncluded.push(appId);
    } else {
        bkCleanAppsIncluded = bkCleanAppsIncluded.filter(id => id !== appId);
    }

    await window.sui.api("bk_save_clean_app_options", { included: bkCleanAppsIncluded }, { toast: false });
    bkRefreshSizes();
};
let bkLogObserver = null;

function initBkLogObserver() {
    const target = document.getElementById('bk-cloud-console');
    if (!target) return;

    if (bkLogObserver) bkLogObserver.disconnect();

    bkLogObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                bkPollLogs();
                if (!bkLogInterval) bkLogInterval = setInterval(bkPollLogs, 5000);
            } else {
                if (bkLogInterval) {
                    clearInterval(bkLogInterval);
                    bkLogInterval = null;
                }
            }
        });
    }, { threshold: 0.1 });

    bkLogObserver.observe(target);
}

window.bkToggleTokenVisibility = function(e) {
    const input = document.getElementById('bk-cloud-token');
    const btn = e.currentTarget;
    if (!input) return;
    const isMasked = input.classList.contains('input-secret-key');
    if (isMasked) {
        input.classList.remove('input-secret-key');
        btn.innerText = 'Hide';
    } else {
        input.classList.add('input-secret-key');
        btn.innerText = 'Show';
    }
};

async function bkLoadCloudConfig() {
    try {
        const data = await window.sui.api("bk_get_cloud_config", {}, { toast: false });
        if (data && data.status === 'success') {
            document.getElementById('bk-cloud-token').value = data.dropbox_token || '';
            document.getElementById('bk-cloud-subfolder').value = data.subfolder || '/ConjureBackups';
            document.getElementById('bk-cloud-option').value = data.default_option || 'no_snaps_no_recs';
            document.getElementById('bk-cloud-limit').value = data.retention_limit || 5;
            
            const url = new URL(window.location.href);
            const baseUrl = url.origin + url.pathname;
            document.getElementById('bk-cloud-curl').value = `curl "${baseUrl}?plugin_action=bk_cloud_backup&api_token=${data.api_token}"`;
        }
        bkPollLogs();
    } catch(e) {}
}

window.bkSaveCloudConfig = async function() {
    const token = document.getElementById('bk-cloud-token').value.trim();
    const subfolder = document.getElementById('bk-cloud-subfolder').value.trim();
    const opt = document.getElementById('bk-cloud-option').value;
    const limit = document.getElementById('bk-cloud-limit').value;
    
    await window.sui.api("bk_save_cloud_config", { dropbox_token: token, subfolder, default_option: opt, retention_limit: limit });
    if (window.sui && window.sui.toast) window.sui.toast("Cloud settings saved");
};

window.bkCopyCurlCommand = function() {
    const curl = document.getElementById('bk-cloud-curl').value;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(curl);
        if (window.sui && window.sui.toast) window.sui.toast("Command copied");
    }
};

window.bkRunCloudBackup = async function() {
    const btn = document.getElementById('bk-btn-cloud-run');
    const origText = btn.innerText;
    btn.innerText = "Processing...";
    btn.style.opacity = "0.6";
    btn.style.pointerEvents = "none";
    
    // Start rapid polling
    if (bkLogInterval) clearInterval(bkLogInterval);
    bkLogInterval = setInterval(bkPollLogs, 1000);

    let initialLogCount = 0;
    try {
        const initLogs = await window.sui.api("bk_get_logs", {}, { toast: false });
        if (initLogs && initLogs.logs) initialLogCount = initLogs.logs.length;
    } catch(e) {}

    try {
        const curl = document.getElementById('bk-cloud-curl').value;
        const match = curl.match(/api_token=([^"]+)/);
        if (match) {
            const url = `index.php?plugin_action=bk_cloud_backup&api_token=${match[1]}`;
            fetch(url).catch(() => {}); // Fire and forget
        }
    } catch(e) { }

    // Poll logs to reset button state
    const checkInterval = setInterval(async () => {
        try {
            const logData = await window.sui.api("bk_get_logs", {}, { toast: false });
            if (logData && logData.logs && logData.logs.length > initialLogCount) {
                const newLogs = logData.logs.slice(initialLogCount);
                let finished = false;
                for (const l of newLogs) {
                    if (l.msg.includes("Backup cycle completed successfully.") || 
                        l.msg.includes("Dropbox upload failed") || 
                        l.msg.includes("Unauthorized")) {
                        finished = true; break;
                    }
                }
                if (finished) {
                    clearInterval(checkInterval);
                    btn.innerText = origText;
                    btn.style.opacity = "1";
                    btn.style.pointerEvents = "auto";
                    if (bkLogInterval) {
                        clearInterval(bkLogInterval);
                        bkLogInterval = null;
                    }
                    bkPollLogs();
                    initBkLogObserver();
                }
            }
        } catch(err) {}
    }, 2000);
    
    // Safety timeout (15 mins)
    setTimeout(() => {
        clearInterval(checkInterval);
        if (btn.innerText === "Processing...") {
            btn.innerText = origText;
            btn.style.opacity = "1";
            btn.style.pointerEvents = "auto";
        }
    }, 15 * 60 * 1000);
};

window.bkPollLogs = async function() {
    if (document.hidden) return; // Battery-Friendly: Skip if tab is backgrounded or screen locked
    const cons = document.getElementById('bk-cloud-console');
    if (!cons || cons.offsetParent === null) return; // Battery-Friendly: Skip if console element is collapsed or invisible

    try {
        const data = await window.sui.api("bk_get_logs", {}, { toast: false });
        if (data && data.logs && data.logs.length > 0) {
            cons.innerHTML = data.logs.map(l => {
                let color = '#ccc';
                if (l.type === 'error') color = '#ff6b6b';
                if (l.type === 'success') color = '#51cf66';
                return `<div><span style="color:#888;">[${l.time.split(' ')[1]}]</span> <span style="color:${color}; font-weight:bold;">[${l.type.toUpperCase()}]</span> ${l.msg}</div>`;
            }).join('');
            cons.scrollTop = cons.scrollHeight;
        } else {
            cons.innerHTML = '<div style="color:#666;">No logs available.</div>';
        }
    } catch(e) {}
};

window.bkClearLogs = async function() {
    await window.sui.api("bk_clear_logs", {}, { toast: false });
    bkPollLogs();
};

window.bkCopyLogs = function() {
    const cons = document.getElementById('bk-cloud-console');
    if (navigator.clipboard && cons) {
        navigator.clipboard.writeText(cons.innerText);
        if (window.sui && window.sui.toast) window.sui.toast("Logs copied");
    }
};

window.addEventListener("load", () => {
    initBkLogObserver();
});

window._bkSizes = null;
window.bkRefreshSizes = async function() {
    try {
        const data = await window.sui.api('bk_get_sizes', {}, { toast: false });
        if (data) {
            window._bkSizes = data;
            const fullBtn = document.getElementById('bk-btn-full');
            const noSnapBtn = document.getElementById('bk-btn-no-snaps');
            const noSnapNoRecBtn = document.getElementById('bk-btn-no-snaps-no-recs');
            const cleanBtn = document.getElementById('bk-btn-clean');
            if (fullBtn) fullBtn.innerText = `Full System Backup (${data.full})`;
            if (noSnapBtn) noSnapBtn.innerText = `No Snapshots (${data.no_snaps})`;
            if (noSnapNoRecBtn) noSnapNoRecBtn.innerText = `No Snapshots & No Recordings (${data.no_snaps_no_recs})`;
            if (cleanBtn) cleanBtn.innerText = `Clean Export ZIP (${data.clean || '0 MB'})`;
        }
    } catch(e) {}
};
const bkRefreshSizes = window.bkRefreshSizes;

async function bkLoadConfig() {
    bkRefreshSizes();
    try {
        const data = await window.sui.api("bk_get_config", {}, { toast: false });
        if (data) {
            const zT = document.getElementById("bk-show-zip-toggle");
            const cT = document.getElementById("bk-show-code-toggle");
            const chunkS = document.getElementById("bk-chunk-slider");
            
            if (zT) zT.checked = data.config.show_zip_shortcut;
            if (cT) cT.checked = data.config.show_code_shortcut;
            if (chunkS) {
                const val = data.config.chunk_size || 850;
                chunkS.value = val;
                document.getElementById('bk-chunk-val').innerText = val + ' KB';
            }
            bkRenderHeaderBtns(data.config.show_zip_shortcut, data.config.show_code_shortcut);
        }
    } catch(e) {}
}

window.bkSaveConfig = async function() {
    const showZip = document.getElementById("bk-show-zip-toggle").checked;
    const showCode = document.getElementById("bk-show-code-toggle").checked;
    const chunkSize = document.getElementById("bk-chunk-slider")?.value || 850;
    
    await window.sui.api("bk_save_config", {
        show_zip_shortcut: showZip,
        show_code_shortcut: showCode,
        chunk_size: chunkSize
    }, { toast: false });

    bkRenderHeaderBtns(showZip, showCode);
};

window.bkToggleShortcuts = window.bkSaveConfig;

// --- PLUGIN EXCLUSION LOGIC ---
let bkExcludedPlugins = [];

window.addEventListener("load", () => {
    bkFetchExclusions();
});

function legacy_bk_longpress_listener() {
    const settingsCont = document.getElementById("settings-scroll-container");
    if (!settingsCont) return;

    let lpTimer = null;
    settingsCont.addEventListener("touchstart", (e) => {
        const block = e.target.closest(".plugin-block");
        if (!block) return;
        const name = block.id.replace("plg-row-", "");
        
        lpTimer = setTimeout(() => {
            if (window.sui && window.sui.haptic) window.sui.haptic("medium");
            bkOpenExclusionPicker(name);
        }, 700);
    }, { passive: true });

    settingsCont.addEventListener("touchend", () => clearTimeout(lpTimer));
    settingsCont.addEventListener("touchmove", () => clearTimeout(lpTimer));
}

async function bkFetchExclusions() {
    const data = await window.sui.api("bk_get_config", {}, { toast: false });
    if (data) {
        bkExcludedPlugins = data.config.excluded_plugins || [];
        bkApplyExclusionVisuals();
    }
}

function bkApplyExclusionVisuals() {
    document.querySelectorAll(".plugin-block").forEach(block => {
        const name = block.id.replace("plg-row-", "");
        const label = block.querySelector(".setting-label");
        if (!label) return;
        
        if (bkExcludedPlugins.includes(name)) {
            label.style.opacity = "0.4";
            label.innerHTML = name + ' <span style="font-size:9px; font-weight:900; color:var(--danger); opacity:0.6;">(EXPORT EXCLUDED)</span>';
        } else {
            label.style.opacity = "1";
            label.innerHTML = name;
        }
    });
    bkRenderExclusionList();
}

function bkRenderExclusionList() {
    const section = document.getElementById("bk-excluded-section");
    const list = document.getElementById("bk-excluded-list");
    if (!section || !list) return;

    if (bkExcludedPlugins.length === 0) {
        section.style.display = "none";
        return;
    }

    section.style.display = "block";
    list.innerHTML = "";

    bkExcludedPlugins.forEach(name => {
        const badge = document.createElement("div");
        badge.style.cssText = "background:var(--card-bg); color:var(--text-primary); border:1px solid var(--border-color); padding:4px 10px; border-radius:8px; font-size:11px; font-weight:600; display:flex; align-items:center; gap:6px; cursor:pointer;";
        badge.innerHTML = `${name} <span style="color:var(--danger); font-weight:900; font-size:14px; line-height:1;">&times;</span>`;
        badge.onclick = (e) => {
            e.stopPropagation();
            bkToggleExclusionStatus(name);
        };
        list.appendChild(badge);
    });
}

async function bkToggleExclusionStatus(pluginName) {
    const isExcluded = bkExcludedPlugins.includes(pluginName);
    if (isExcluded) {
        bkExcludedPlugins = bkExcludedPlugins.filter(p => p !== pluginName);
    } else {
        bkExcludedPlugins.push(pluginName);
    }
    
    // Pull current UI states
    const showZip = document.getElementById("bk-show-zip-toggle")?.checked || false;
    const showCode = document.getElementById("bk-show-code-toggle")?.checked || false;
    const chunkSize = document.getElementById("bk-chunk-slider")?.value || 850;

    await window.sui.api("bk_save_config", {
        show_zip_shortcut: showZip,
        show_code_shortcut: showCode,
        chunk_size: chunkSize,
        excluded_plugins: bkExcludedPlugins
    }, { toast: false });
    bkApplyExclusionVisuals();
}

function legacy_bkOpenExclusionPicker(pluginName) {
    if (typeof window.openPicker !== "function") return;
    const isExcluded = bkExcludedPlugins.includes(pluginName);

    const options = [
        { 
            label: isExcluded ? "Include in Source Export" : "Exclude from Source Export", 
            value: "toggle" 
        }
    ];

    window.openPicker(`Export Policy: ${pluginName}`, options, null, async (val) => {
        if (val === "toggle") {
            if (isExcluded) {
                bkExcludedPlugins = bkExcludedPlugins.filter(p => p !== pluginName);
            } else {
                bkExcludedPlugins.push(pluginName);
            }
            
            // Save to server
            await window.sui.api("bk_save_config", {
                show_zip_shortcut: document.getElementById("bk-show-zip-toggle").checked,
                show_code_shortcut: document.getElementById("bk-show-code-toggle").checked,
                chunk_size: document.getElementById("bk-chunk-slider").value,
                excluded_plugins: bkExcludedPlugins
            }, { toast: false });
            bkApplyExclusionVisuals();
            
            const t = document.getElementById("toast");
            if(t) { t.innerText = isExcluded ? "Included in Export" : "Excluded from Export"; t.classList.add("show"); setTimeout(()=>t.classList.remove("show"), 2000); }
        }
    });
}

window.bkTriggerCloudBackupDirect = async function() {
    if (window.sui && window.sui.toast) window.sui.toast("Cloud backup initiated...");
    try {
        const data = await window.sui.api("bk_get_cloud_config", {}, { toast: false });
        if (data && data.status === 'success') {
            let initialLogCount = 0;
            try {
                const initLogs = await window.sui.api("bk_get_logs", {}, { toast: false });
                if (initLogs && initLogs.logs) initialLogCount = initLogs.logs.length;
            } catch(e) {}

            const url = `index.php?plugin_action=bk_cloud_backup&api_token=${data.api_token}`;
            
            // Fire and forget to prevent 60s browser timeout errors
            fetch(url).catch(() => {});
            
            // Poll logs in the background for completion
            const checkInterval = setInterval(async () => {
                if (document.hidden) return; // Battery Friendly
                try {
                    const logData = await window.sui.api("bk_get_logs", {}, { toast: false });
                    if (logData && logData.logs && logData.logs.length > initialLogCount) {
                        const newLogs = logData.logs.slice(initialLogCount);
                        let finished = false;
                        let success = false;
                        
                        for (const l of newLogs) {
                            if (l.msg.includes("Backup cycle completed successfully.")) {
                                finished = true; success = true; break;
                            } else if (l.msg.includes("Dropbox upload failed") || l.msg.includes("Unauthorized")) {
                                finished = true; success = false; break;
                            }
                        }
                        
                        if (finished) {
                            clearInterval(checkInterval);
                            if (success) {
                                if (window.sui && window.sui.toast) window.sui.toast("Cloud backup uploaded!");
                            } else {
                                if (window.sui && window.sui.toast) window.sui.toast("Cloud backup failed.");
                            }
                        }
                    }
                } catch(err) {}
            }, 3000);
            
            // Safety timeout (15 minutes)
            setTimeout(() => clearInterval(checkInterval), 15 * 60 * 1000);
        }
    } catch(e) {
        if (window.sui && window.sui.toast) window.sui.toast("Connection error.");
    }
};

async function bkOpenZipPicker(btn) {
    if (typeof window.openPicker !== 'function') {
        bkDownloadZip(btn);
        return;
    }
        
    // Visually indicate size recalculation/fetching in progress
    btn.style.opacity = "0.5";
    btn.style.pointerEvents = "none";
    await window.bkRefreshSizes();
    btn.style.opacity = "1";
    btn.style.pointerEvents = "auto";

    const fullLabel = window._bkSizes ? `Full System Backup (${window._bkSizes.full})` : "Full System Backup";
    const noSnapLabel = window._bkSizes ? `No Snapshots (${window._bkSizes.no_snaps})` : "No Snapshots";
    const cleanNoSnapNoRecLabel = window._bkSizes ? `No Snapshots & No Recordings (${window._bkSizes.no_snaps_no_recs})` : "No Snapshots & No Recordings";
    const cleanExportLabel = window._bkSizes ? `Clean Export ZIP (${window._bkSizes.clean || '0 MB'})` : "Clean Export ZIP";

    const lastCloudDate = (window._bkSizes && window._bkSizes.last_cloud) ? window._bkSizes.last_cloud : 'Never';
    const cloudLabel = `Upload to Cloud (Last: ${lastCloudDate})`;

    const options = [
        { label: fullLabel, value: "full" },
        { label: noSnapLabel, value: "no_snaps" },
        { label: cleanNoSnapNoRecLabel, value: "no_snaps_no_recs" },
        { label: cleanExportLabel, value: "clean_export" },
        { label: cloudLabel, value: "cloud_upload" }
    ];
    window.openPicker("Download Backup ZIP", options, null, (val) => {
        if (val === "full") bkDownloadZip(btn, false, false, false);
        if (val === "no_snaps") bkDownloadZip(btn, true, false, false);
        if (val === "no_snaps_no_recs") bkDownloadZip(btn, true, true, false);
        if (val === "clean_export") bkOpenCleanExportMenu(btn);
        if (val === "cloud_upload") bkTriggerCloudBackupDirect();
    });
}function bkRenderHeaderBtns(showZip, showCode) {
    const container = document.getElementById("settings-header-actions");
    if (!container) return;
    let zipBtn = document.getElementById("bk-header-zip-btn");
    if (!showZip) { if (zipBtn) zipBtn.remove(); } 
    else if (!zipBtn) {
        zipBtn = document.createElement("button");
        zipBtn.id = "bk-header-zip-btn";
        zipBtn.title = "Backup ZIP Options";
        zipBtn.style.cssText = "background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;";
        zipBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px; height:16px;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>`;
        zipBtn.onclick = (e) => { e.stopPropagation(); bkOpenZipPicker(zipBtn); };
        container.appendChild(zipBtn);
    }
    let codeBtn = document.getElementById("bk-header-code-btn");
    if (!showCode) { if (codeBtn) codeBtn.remove(); } 
    else if (!codeBtn) {
        codeBtn = document.createElement("button");
        codeBtn.id = "bk-header-code-btn";
        codeBtn.title = "Source Code TXT";
        codeBtn.style.cssText = "background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;";
        codeBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px; height:16px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>`;
        codeBtn.onclick = (e) => { e.stopPropagation(); bkDownloadCode(codeBtn); };
        container.appendChild(codeBtn);
    }
}

function bkDownloadZip(btn, excludeSnapshots = false, excludeRecordings = false, cleanExport = false) {
    // Stream Mode: Direct Redirect
    let url = "index.php?plugin_action=bk_stream_live&nocache=" + Date.now();
    if (cleanExport) {
        url += "&clean_export=true";
    } else {
        if (excludeSnapshots) url += "&exclude_snapshots=true";
        if (excludeRecordings) url += "&exclude_recordings=true";
    }
    window.location.href = url;
}

window.bkOpenCleanExportMenu = async function(btn) {
    const overlay = document.getElementById('bk-clean-overlay');
    if (!overlay) {
        bkDownloadZip(btn, false, false, true);
        return;
    }
    
    overlay.classList.add('visible');
    await bkLoadCleanAppsModal();
};

window.bkCloseCleanExportMenu = function(returnToZipPicker = true) {
    const overlay = document.getElementById('bk-clean-overlay');
    if (overlay) overlay.classList.remove('visible');

    if (returnToZipPicker) {
        setTimeout(() => {
            const btn = document.getElementById('bk-header-zip-btn') || document.getElementById('bk-btn-clean');
            bkOpenZipPicker(btn);
        }, 200);
    }
};

let bkAllApkProjects = [];
let bkIncludedApkProjects = [];

async function bkLoadCleanAppsModal() {
    const list = document.getElementById("bk-modal-clean-apps-list");
    if (list) list.innerHTML = '<div style="font-size:12px; color:var(--text-secondary); text-align:center; padding:12px;">Loading apps...</div>';

    try {
        const data = await window.sui.api("bk_get_clean_app_options", {}, { toast: false });
        if (data && data.status === 'success') {
            bkCleanAppsIncluded = data.included || [];
            bkAllApkProjects = data.apk_projects || [];
            bkIncludedApkProjects = data.included_apk_projects || [];
            bkRenderCleanAppsModalList(data.apps || [], bkCleanAppsIncluded);
            bkUpdateModalZipSize();
        }
    } catch(e) {}
}

function bkRenderCleanAppsModalList(apps, included) {
    const list = document.getElementById("bk-modal-clean-apps-list");
    if (!list) return;

    if (!apps || apps.length === 0) {
        list.innerHTML = '<div style="font-size:12px; color:var(--text-secondary); text-align:center; padding:12px;">No AppMaker apps found.</div>';
        return;
    }

    list.innerHTML = "";
    apps.forEach(app => {
        const isChecked = included.includes(app.id);
        const item = document.createElement("div");
        item.style.cssText = "background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center;";
        
        let extraBtn = '';
        if (app.id === 'ApkStudio') {
            const count = bkIncludedApkProjects.length;
            extraBtn = `<button type="button" onclick="bkOpenApkProjectsModal(event)" style="background:var(--btn-bg); border:1px solid var(--border-color); color:var(--text-primary); border-radius:8px; padding:4px 8px; font-size:11px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:5px; margin-right:8px;">
                📂 Projects <span style="background:var(--primary); color:white; border-radius:10px; padding:1px 6px; font-size:10px;">${count}</span>
            </button>`;
        }

        item.innerHTML = `
            <div style="display:flex; align-items:center; gap:10px; min-width:0; flex:1;">
                <span style="font-size:20px;">${app.icon}</span>
                <span style="font-size:14px; font-weight:600; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${app.name}</span>
            </div>
            <div style="display:flex; align-items:center;">
                ${extraBtn}
                ${window.suiSwitch('bk-clean-modal-app-' + app.id, isChecked, `bkToggleCleanAppModal('${app.id}', this.checked)`)}
            </div>
        `;
        list.appendChild(item);
    });
}

window.bkOpenApkProjectsModal = function(e) {
    if (e) e.stopPropagation();
    const overlay = document.getElementById('bk-apk-projects-overlay');
    if (!overlay) return;
    
    bkRenderApkProjectsList();
    overlay.classList.add('visible');
};

window.bkCloseApkProjectsModal = function() {
    const overlay = document.getElementById('bk-apk-projects-overlay');
    if (overlay) overlay.classList.remove('visible');
    
    bkLoadCleanAppsModal();
};

function bkRenderApkProjectsList() {
    const list = document.getElementById("bk-apk-projects-list");
    if (!list) return;

    if (!bkAllApkProjects || bkAllApkProjects.length === 0) {
        list.innerHTML = '<div style="font-size:12px; color:var(--text-secondary); text-align:center; padding:12px;">No APK Studio projects found.</div>';
        return;
    }

    list.innerHTML = "";
    bkAllApkProjects.forEach(proj => {
        const isChecked = bkIncludedApkProjects.includes(proj.id);
        const item = document.createElement("div");
        item.style.cssText = "background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center;";
        item.innerHTML = `
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:16px;">📱</span>
                <span style="font-size:13px; font-weight:600; color:var(--text-primary);">${proj.name}</span>
            </div>
            ${window.suiSwitch('bk-apk-proj-' + proj.id, isChecked, `bkToggleApkProjectModal('${proj.id}', this.checked)`)}
        `;
        list.appendChild(item);
    });
}

window.bkToggleApkProjectModal = async function(projId, checked) {
    if (checked) {
        if (!bkIncludedApkProjects.includes(projId)) bkIncludedApkProjects.push(projId);
    } else {
        bkIncludedApkProjects = bkIncludedApkProjects.filter(id => id !== projId);
    }

    await window.sui.api("bk_save_clean_app_options", { 
        included: bkCleanAppsIncluded,
        apk_projects: bkIncludedApkProjects 
    }, { toast: false });
    
    bkUpdateModalZipSize();
};

window.bkToggleCleanAppModal = async function(appId, checked) {
    if (checked) {
        if (!bkCleanAppsIncluded.includes(appId)) bkCleanAppsIncluded.push(appId);
    } else {
        bkCleanAppsIncluded = bkCleanAppsIncluded.filter(id => id !== appId);
    }

    await window.sui.api("bk_save_clean_app_options", { included: bkCleanAppsIncluded }, { toast: false });
    bkUpdateModalZipSize();
};

async function bkUpdateModalZipSize() {
    const btn = document.getElementById('bk-modal-dl-btn');
    if (btn) btn.innerText = "Recalculating size...";
    await bkRefreshSizes();
    if (btn && window._bkSizes) {
        btn.innerText = `📥 Download Clean Export ZIP (${window._bkSizes.clean || '0 MB'})`;
    }
}

window.bkDownloadCleanZipDirect = function(btn) {
    bkCloseCleanExportMenu(false);
    bkDownloadZip(btn, false, false, true);
};

window.bkDownloadCleanZip = function(btn) {
    bkOpenCleanExportMenu(btn);
};

let bkDownloadedParts = [];

window.bkPrepareExport = async function(btn) {
    const originalText = btn.innerText;
    btn.innerText = "Calculating...";
    btn.style.opacity = "0.7";
    btn.style.pointerEvents = "none";

    // UI container cleared

    try {
        // Using GET with a timestamp to bypass the Firewall and prevent caching
        const url = `index.php?plugin_action=bk_prepare_export&t=${Date.now()}`;
        const res = await fetch(url);
        const data = await res.json();

        if (data.status === "success") {
            bkDownloadedParts = [];
            bkShowExportPicker(data.parts);
            btn.innerText = "Re-Analyze Export";
            return; 
        } else {
            window.openConfirm("Export Failed", "Export failed: " + (data.message || "Unknown error"), null, false, "OK", null);
            btn.innerText = originalText;
        }
    } catch (e) {
        window.openConfirm("Network Error", "Network error during analysis.", null, false, "OK", null);
        btn.innerText = originalText;
    }
    
    btn.style.opacity = "1";
    btn.style.pointerEvents = "auto";
};

// Update Header Button to trigger the Prepare function
function bkShowExportPicker(totalParts) {
    if (typeof window.openPicker !== "function") {
        window.openConfirm("Plugin Required", "SharedUI plugin is required for this menu.", null, false, "OK", null);
        return;
    }
    const options = [];
    for (let i = 1; i <= totalParts; i++) {
        const isDone = bkDownloadedParts.includes(i);
        options.push({
            label: isDone 
                ? `<div style=\"display:flex; align-items:center; gap:10px; opacity:0.4; filter:grayscale(1);\">
                    <svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#34C759\" stroke-width=\"3\" style=\"width:18px; height:18px;\"><polyline points=\"20 6 9 17 4 12\"></polyline></svg>
                    <span style=\"text-decoration:line-through;\">Part ${i} (Downloaded)</span>
                   </div>` 
                : `Download Part ${i}`,
            value: i
        });
    }
    window.openPicker("Source Code Export", options, null, (idx) => bkDownloadSinglePart(idx, totalParts));
}

function bkDownloadSinglePart(i, total) {
    if (!bkDownloadedParts.includes(i)) bkDownloadedParts.push(i);
    let dlFrame = document.getElementById('bk-dl-frame');
    if (!dlFrame) {
        dlFrame = document.createElement('iframe');
        dlFrame.id = 'bk-dl-frame';
        dlFrame.style.display = 'none';
        document.body.appendChild(dlFrame);
    }
    dlFrame.src = `index.php?plugin_action=bk_download_part&part=${i}&nocache=${Date.now()}`;
    // Re-open picker after short delay
    setTimeout(() => bkShowExportPicker(total), 500);
}

function bkDownloadCode(btn) {
    // Locate the main Prepare button in the settings panel and click it
    // This ensures the user sees the generated part buttons.
    const mainBtn = document.querySelector('button[onclick="bkPrepareExport(this)"]');
    if (mainBtn) {
        // Scroll to button
        mainBtn.scrollIntoView({ behavior: "smooth", block: "center" });
        // Trigger click
        mainBtn.click();
    } else {
        window.openConfirm("Notice", "Please open Backup Settings to generate the export.", null, false, "OK", null);
    }
}
JS;
?>