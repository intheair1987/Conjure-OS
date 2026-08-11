<?php
/**
 * ORBIT RECEIVER SCRIPT
 * ---------------------------------------------------------
 * This file should be placed in the web root of your server.
 * e.g., /var/www/html/receiver.php
 * It securely accepts ZIP uploads from Orbit, backs up the 
 * old instance, toggles maintenance mode, and safely extracts
 * the new code without overwriting user databases.
 */

// Load secret from local configuration file if available, falling back to constant
$secret = '{{ORBIT_SECRET_KEY}}';
$private_file = __DIR__ . '/receiver-private.json';

if (file_exists($private_file)) {
    $private_data = json_decode(file_get_contents($private_file), true);
    if (!empty($private_data['secret'])) {
        $secret = $private_data['secret'];
    }
}

define('ORBIT_SECRET', $secret);

// 1. Security & Authentication
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

// Multi-layered Authorization header extraction (Nginx and FastCGI friendly)
$auth_header = '';
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'authorization') {
            $auth_header = $value;
            break;
        }
    }
}
if (empty($auth_header)) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}

$token = str_replace('Bearer ', '', $auth_header);

// Fallback: Check post parameters if the header got completely stripped by a proxy, firewall, or .htaccess
if (empty($token) && isset($_POST['secret'])) {
    $token = $_POST['secret'];
}

if (empty($token) || $token !== ORBIT_SECRET) {
    http_response_code(401);
    die(json_encode([
        'success' => false, 
        'error' => 'Unauthorized',
        'debug' => [
            'has_header' => !empty($auth_header),
            'token_len' => strlen($token)
        ]
    ]));
}

// 2. Validate Instance Target
$instance_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['instance_name'] ?? $_POST['old_name'] ?? '');
if (!$instance_name) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Invalid instance name']));
}

$base_dir = __DIR__ . '/instances/' . $instance_name;
$backup_dir = __DIR__ . '/backups';

if (!is_dir($backup_dir)) {
    if (!@mkdir($backup_dir, 0755, true)) {
        http_response_code(500);
        die(json_encode([
            'success' => false, 
            'error' => "Permission Denied: Cannot create /backups directory. Please SSH into your VPS and run:\nsudo mkdir -p /var/www/html/backups && sudo chown -R www-data:www-data /var/www/html/backups"
        ]));
    }
}

if (!is_writable($backup_dir)) {
    http_response_code(500);
    die(json_encode([
        'success' => false, 
        'error' => "Permission Denied: The /backups directory is not writable by the web server. Please SSH into your VPS and run:\nsudo chown -R www-data:www-data /var/www/html/backups"
    ]));
}

// Force ZipArchive / libzip to use our writable backups folder for temporary files
// to bypass unwriteable system /tmp directory configurations on the VPS
putenv('TMPDIR=' . $backup_dir);
@ini_set('sys_temp_dir', $backup_dir);

// 4. Action Router (Deploy vs. Backup Management)
$action = $_POST['action'] ?? 'deploy';

if ($action === 'upload_chunk') {
    $upload_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id']);
    $chunk_index = (int)$_POST['chunk_index'];
    $total_chunks = (int)$_POST['total_chunks'];
    
    $target_file = $backup_dir . '/' . $upload_id . '.part';
    
    $chunk_file = $_FILES['chunk']['tmp_name'];
    $in = fopen($chunk_file, 'rb');
    $out = fopen($target_file, $chunk_index === 0 ? 'wb' : 'ab');
    if ($in && $out) {
        while (!feof($in)) {
            fwrite($out, fread($in, 8192));
        }
        fclose($in);
        fclose($out);
    } else {
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Failed to write chunk on server.']));
    }
    
    if ($chunk_index === $total_chunks - 1) {
        $final_zip = $backup_dir . '/' . $upload_id . '.zip';
        rename($target_file, $final_zip);
        echo json_encode(['success' => true, 'complete' => true]);
    } else {
        echo json_encode(['success' => true, 'complete' => false]);
    }
    exit;
}

if ($action === 'extract_deploy') {
    $upload_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id']);
    $overwrite_data = isset($_POST['overwrite_data']) && $_POST['overwrite_data'] === '1';
    
    $zip_path = $backup_dir . '/' . $upload_id . '.zip';
    if (!file_exists($zip_path)) {
        http_response_code(404);
        die(json_encode(['success' => false, 'error' => 'Uploaded ZIP not found on server.']));
    }
    
    $maintenance_flag = $base_dir . '/maintenance.flag';
    file_put_contents($maintenance_flag, 'Deploying update...');
    
    $zip = new ZipArchive();
    if ($zip->open($zip_path) === TRUE) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (strpos($filename, '..') !== false || substr($filename, 0, 1) === '/') continue;
            
            $target_path = $base_dir . '/' . $filename;
            $is_stateful = (
                strpos($filename, 'app.db') !== false || 
                (strpos($filename, 'data/') === 0 && strpos($filename, 'data/directory.json') === false) || 
                strpos($filename, '.sqlite') !== false
            );
            
            if (!$overwrite_data && $is_stateful && file_exists($target_path)) continue;
            
            $dir = dirname($target_path);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            
            if (substr($filename, -1) !== '/') {
                @copy("zip://" . $zip_path . "#" . $filename, $target_path);
            }
        }
        $zip->close();
        unlink($zip_path);
    } else {
        unlink($maintenance_flag);
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Failed to open uploaded ZIP package']));
    }
    
    if (file_exists($maintenance_flag)) unlink($maintenance_flag);
    if (function_exists('opcache_reset')) opcache_reset();
    
    echo json_encode(['success' => true, 'message' => 'Deployment extracted successfully']);
    exit;
}

if ($action === 'run_diagnostics') {
    $diag_file = $base_dir . '/orbit_diag_temp.php';
    if (!is_dir($base_dir)) {
        echo json_encode(['success' => false, 'error' => 'Instance directory not found.']);
        exit;
    }
    if (!file_exists($base_dir . '/index.php')) {
        echo json_encode(['success' => false, 'error' => 'index.php not found in instance folder.']);
        exit;
    }
    
    // Create the secure temporary diagnostic file
    $diag_code = '<?php
    error_reporting(E_ALL);
    ini_set("display_errors", 1);
    
    chdir("' . $base_dir . '");
    require_once "index.php";
    ';
    
    file_put_contents($diag_file, $diag_code);
    
    $output = [];
    $return_var = 0;
    // Execute the diagnostic script locally via CLI to capture fatal crashes safely
    @exec("php -f " . escapeshellarg($diag_file) . " 2>&1", $output, $return_var);
    @unlink($diag_file);
    
    // Clean up output (strip HTML tags for readable console log)
    $raw_output = implode("\n", $output);
    $clean_output = html_entity_decode(strip_tags($raw_output), ENT_QUOTES, 'UTF-8');
    
    echo json_encode([
        'success' => true,
        'output' => $clean_output,
        'raw_output' => $raw_output,
        'exit_code' => $return_var
    ]);
    exit;
}

if ($action === 'check_instance') {
    if (is_dir($base_dir)) {
        echo json_encode(['success' => true, 'exists' => true, 'message' => 'Instance folder already exists.']);
    } else {
        echo json_encode(['success' => true, 'exists' => false, 'message' => 'Instance folder is available.']);
    }
    exit;
}

if ($action === 'get_manifest') {
    $manifest = [];
    if (is_dir($base_dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($base_dir) + 1);
                $relativePath = str_replace('\\', '/', $relativePath); // Normalize slashes
                $manifest[$relativePath] = md5_file($filePath);
            }
        }
    }
    echo json_encode(['success' => true, 'manifest' => $manifest]);
    exit;
}

if ($action === 'toggle_maintenance') {
    $maintenance_flag = $base_dir . '/maintenance.flag';
    if (file_exists($maintenance_flag)) {
        @unlink($maintenance_flag);
        echo json_encode(['success' => true, 'status' => 'online', 'message' => 'Maintenance mode disabled.']);
    } else {
        @file_put_contents($maintenance_flag, 'Manual maintenance mode');
        echo json_encode(['success' => true, 'status' => 'maintenance', 'message' => 'Maintenance mode enabled.']);
    }
    exit;
}

if ($action === 'delete') {
    // Enable Maintenance Mode (Zero-Modification Nginx Intercept) first to drain connections
    $maintenance_flag = $base_dir . '/maintenance.flag';
    if (is_dir($base_dir)) {
        file_put_contents($maintenance_flag, 'Deleting instance...');
        
        // Recursive helper to delete folder
        $delete_directory = function($dir) use (&$delete_directory) {
            if (!is_dir($dir)) return false;
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                (is_dir("$dir/$file")) ? $delete_directory("$dir/$file") : unlink("$dir/$file");
            }
            return rmdir($dir);
        };
        $delete_directory($base_dir);
    }
    
    // Clean up remote backups for this specific instance
    if (is_dir($backup_dir)) {
        foreach (glob($backup_dir . '/' . $instance_name . '_backup_*.zip') as $backup_file) {
            unlink($backup_file);
        }
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Instance and its backup packages deleted successfully'
    ]);
    exit;
}

if ($action === 'rename') {
    $old_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['old_name'] ?? '');
    $new_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['new_name'] ?? '');
    if (!$old_name || !$new_name) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Invalid rename parameters']));
    }
    $old_path = __DIR__ . '/instances/' . $old_name;
    $new_path = __DIR__ . '/instances/' . $new_name;
    
    if (!is_dir($old_path)) {
        if (is_dir($new_path)) {
            echo json_encode(['success' => true, 'message' => 'Target directory already exists, no rename needed.']);
            exit;
        }
        http_response_code(404);
        die(json_encode(['success' => false, 'error' => 'Source directory not found']));
    }
    
    if (is_dir($new_path)) {
        http_response_code(409);
        die(json_encode(['success' => false, 'error' => 'Target directory already exists']));
    }
    
    if (rename($old_path, $new_path)) {
        // Also rename any backup zip archives associated with this prefix
        foreach (glob($backup_dir . '/' . $old_name . '_backup_*.zip') as $backup_file) {
            $new_backup_file = str_replace($old_name . '_backup_', $new_name . '_backup_', $backup_file);
            rename($backup_file, $new_backup_file);
        }
        echo json_encode(['success' => true, 'message' => 'Directory and backup metadata renamed successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to rename directory on server']);
    }
    exit;
}

if ($action === 'pull_nginx') {
    $config_path = '/etc/nginx/sites-available/default';
    if (file_exists($config_path)) {
        $content = file_get_contents($config_path);
        echo json_encode(['success' => true, 'config' => $content]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Nginx config file not found on server']);
    }
    exit;
}

if ($action === 'list_backups') {
    $list = [];
    if (is_dir($backup_dir)) {
        foreach (glob($backup_dir . '/' . $instance_name . '_backup_*.zip') as $file) {
            $filename = basename($file);
            $note = '';
            if (preg_match('/_-\[(.*?)\]\.zip$/', $filename, $matches)) {
                $note = str_replace('-', ' ', $matches[1]);
            }
            $list[] = [
                'file' => $filename,
                'size' => filesize($file),
                'time' => filemtime($file),
                'note' => $note
            ];
        }
        usort($list, function($a, $b) { return $b['time'] - $a['time']; });
    }
    echo json_encode(['success' => true, 'backups' => $list]);
    exit;
}

if ($action === 'create_backup') {
    if (!is_dir($base_dir)) {
        http_response_code(404);
        die(json_encode(['success' => false, 'error' => 'Instance directory does not exist']));
    }
    
    $note_input = $_POST['note'] ?? '';
    // Chinese-Safe Filter: Permit alphanumeric, spaces, hyphens, underscores, and CJK Han Unicode characters. Strip emojis.
    $safe_note = preg_replace('/[^a-zA-Z0-9_\s\-\x{4e00}-\x{9fa5}]/u', '', $note_input);
    $slug_note = preg_replace('/\s+/', '-', trim($safe_note));
    $suffix = !empty($slug_note) ? '_-[' . $slug_note . ']' : '';
    
    $backup_file = $backup_dir . '/' . $instance_name . '_backup_' . date('Y-m-d_H-i-s') . $suffix . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        try {
            $files_added = 0;
            // Add a meta file to guarantee the ZIP is never completely empty
            $zip->addFromString('orbit_backup_meta.txt', "Backup generated on: " . date('Y-m-d H:i:s') . "\nInstance: " . $instance_name);
            
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($base_dir) + 1);
                    if ($zip->addFile($filePath, $relativePath)) {
                        $files_added++;
                    }
                }
            }
            
            if ($zip->close()) {
                echo json_encode(['success' => true, 'message' => 'Backup created successfully', 'files_added' => $files_added]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'ZipArchive failed to save: ' . $zip->getStatusString()]);
            }
        } catch (Exception $e) {
            @$zip->close();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Directory scan failed: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create backup ZIP (Permission denied or disk full)']);
    }
    exit;
}

if ($action === 'delete_backup') {
    $file = basename($_POST['file'] ?? '');
    if (!$file || strpos($file, $instance_name . '_backup_') !== 0) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Invalid backup file']));
    }
    $path = $backup_dir . '/' . $file;
    if (file_exists($path)) {
        unlink($path);
        echo json_encode(['success' => true, 'message' => 'Backup deleted']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Backup not found']);
    }
    exit;
}

if ($action === 'restore_backup') {
    $file = basename($_POST['file'] ?? '');
    if (!$file || strpos($file, $instance_name . '_backup_') !== 0) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Invalid backup file']));
    }
    $path = $backup_dir . '/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        die(json_encode(['success' => false, 'error' => 'Backup not found']));
    }
    
    $restore_mode = $_POST['restore_mode'] ?? 'full'; // 'full', 'data_only', 'code_only'
    $maintenance_flag = $base_dir . '/maintenance.flag';
    file_put_contents($maintenance_flag, 'Restoring backup...');
    
    $delete_directory = function($dir) use (&$delete_directory, $maintenance_flag, $restore_mode, $base_dir) {
        if (!is_dir($dir)) return false;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $f) {
            $p = "$dir/$f";
            if ($p === $maintenance_flag) continue;
            
            // Reconstruct relative path to evaluate statefulness
            $relativePath = substr($p, strlen($base_dir) + 1);
            $is_stateful = (
                strpos($relativePath, 'app.db') !== false || 
                strpos($relativePath, 'data/') === 0 || 
                substr($relativePath, -3) === '.db' || 
                substr($relativePath, -7) === '.sqlite'
            );
            
            // Surgical Deletion Guards
            if ($restore_mode === 'code_only' && $is_stateful) continue; // Preserve live data
            if ($restore_mode === 'data_only' && !$is_stateful) continue; // Preserve live code
            
            if (is_dir($p)) {
                $delete_directory($p);
                @rmdir($p); // Fails safely if directory contains preserved files
            } else {
                unlink($p);
            }
        }
        return true;
    };
    $delete_directory($base_dir);
    
    $zip = new ZipArchive();
    if ($zip->open($path) === TRUE) {
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                
                // SECURITY MANDATE: Zip Slip Traversal Guard
                if (strpos($filename, '..') !== false || substr($filename, 0, 1) === '/') {
                    continue; // Skip dangerous file paths attempting to escape the instance directory
                }
                
                $target_path = $base_dir . '/' . $filename;
                
                $is_stateful = (
                    strpos($filename, 'app.db') !== false || 
                    strpos($filename, 'data/') === 0 || 
                    substr($filename, -3) === '.db' || 
                    substr($filename, -7) === '.sqlite'
                );
                
                // Surgical Extraction Guards
                if ($restore_mode === 'code_only' && $is_stateful) continue;
                if ($restore_mode === 'data_only' && !$is_stateful) continue;
                
                $dir = dirname($target_path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                
                if (substr($filename, -1) !== '/') {
                    @copy("zip://" . $path . "#" . $filename, $target_path);
                }
            }
            $zip->close();
            if (file_exists($maintenance_flag)) unlink($maintenance_flag);
            if (function_exists('opcache_reset')) opcache_reset();
            echo json_encode(['success' => true, 'message' => 'Backup restored successfully']);
        } catch (Exception $e) {
            @$zip->close();
            if (file_exists($maintenance_flag)) unlink($maintenance_flag);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Extraction failed: ' . $e->getMessage()]);
        }
    } else {
        if (file_exists($maintenance_flag)) unlink($maintenance_flag);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to open backup ZIP']);
    }
    exit;
}

// 3. Pre-Flight Backup (Safety First) / First-time directory provision
// This only executes for active 'deploy' action requests
if (is_dir($base_dir)) {
    $backup_file = $backup_dir . '/' . $instance_name . '_backup_' . date('Y-m-d_H-i-s') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_dir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($base_dir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
    }
} else {
    // First-time deployment, create instance directory with write validation
    if (!@mkdir($base_dir, 0755, true)) {
        http_response_code(500);
        die(json_encode([
            'success' => false, 
            'error' => "Failed to create directory: {$base_dir}. Please check your server's folder permissions and ownership."
        ]));
    }
}

// 5. Enable Maintenance Mode (Zero-Modification Nginx Intercept)
$maintenance_flag = $base_dir . '/maintenance.flag';
file_put_contents($maintenance_flag, 'Deploying update...');

// 6. Extract Package (With Stateful Protection)
$overwrite_data = isset($_POST['overwrite_data']) && $_POST['overwrite_data'] === '1';

if (isset($_FILES['package']) && $_FILES['package']['error'] === UPLOAD_ERR_OK) {
    $zip = new ZipArchive();
    if ($zip->open($_FILES['package']['tmp_name']) === TRUE) {
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            
            // SECURITY MANDATE: Zip Slip Traversal Guard
            if (strpos($filename, '..') !== false || substr($filename, 0, 1) === '/') {
                continue; // Skip dangerous file paths attempting to escape the instance directory
            }
            
            $target_path = $base_dir . '/' . $filename;
            
            // DATA PRESERVATION MANDATE (Rule 15):
            // Never overwrite existing SQLite databases or the settings/data directories,
            // UNLESS the user explicitly requested a full data overwrite.
            $is_stateful = (
                strpos($filename, 'app.db') !== false || 
                (strpos($filename, 'data/') === 0 && strpos($filename, 'data/directory.json') === false) || 
                strpos($filename, '.sqlite') !== false
            );
            
            if (!$overwrite_data && $is_stateful && file_exists($target_path)) {
                continue; // Skip extraction, preserve live user data
            }
            
            // Ensure target subdirectory exists with error monitoring
            $dir = dirname($target_path);
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    unlink($maintenance_flag);
                    http_response_code(500);
                    die(json_encode([
                        'success' => false, 
                        'error' => "Failed to create folder structure: {$dir}. Verify the parent folder is writable by the web server process (www-data)."
                    ]));
                }
            }
            
            // Extract file directly from zip stream with safety checks
            if (substr($filename, -1) !== '/') {
                if (!@copy("zip://" . $_FILES['package']['tmp_name'] . "#" . $filename, $target_path)) {
                    unlink($maintenance_flag);
                    http_response_code(500);
                    die(json_encode([
                        'success' => false, 
                        'error' => "Failed to write file: {$target_path}. Verify directory permissions and disk space."
                    ]));
                }
            }
        }
        $zip->close();
    } else {
        unlink($maintenance_flag); // Abort maintenance
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Failed to open uploaded ZIP package']));
    }
} else {
    unlink($maintenance_flag);
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'No deployment package received']));
}

// 6. Clear Maintenance Mode & Resume Traffic
if (file_exists($maintenance_flag)) {
    unlink($maintenance_flag);
}

// 7. Clear OPcache to ensure new PHP files are served immediately
if (function_exists('opcache_reset')) {
    opcache_reset();
}

if ($instance_name === 'orbit_kernel') {
    // Trigger the apply script instantly in the background, detaching it so PHP can return the HTTP response
    exec("nohup sudo /bin/bash /var/www/html/instances/orbit_kernel/apply.sh > /var/www/html/instances/orbit_kernel/update.log 2>&1 &");
}

echo json_encode([
    'success' => true, 
    'message' => 'Deployment successful', 
    'instance' => $instance_name
]);