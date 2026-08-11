<?php
/**
 * Orbit Deployer Engine
 * Handles packaging AppMaker folders into ZIPs and transmitting them to the Receiver.
 */
class OrbitDeployer {

    /**
     * Fetches the file hash manifest from the remote server for differential syncing.
     */
    public static function getRemoteManifest($receiver_url, $secret, $instance_name) {
        $data = [
            'action' => 'get_manifest',
            'instance_name' => $instance_name,
            'secret' => $secret
        ];
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Hashing large files takes time
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secret
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true) ?: ['success' => false, 'error' => 'Invalid manifest response'];
    }
    
    /**
     * Packages a target AppMaker app into a ZIP file, skipping files present in the remote manifest.
     * Performs a dry-run check BEFORE zipping to guarantee fast aborts on oversized payloads.
     * @return array Responds with status and size metadata, or ZIP path on success.
     */
    public static function packageApp($template_folder, $remote_manifest = [], $max_mb = 95, $flags = []) {
        $source_dir = dirname(__DIR__, 3) . '/apps/' . $template_folder;
        $temp_dir = dirname(__DIR__) . '/data';
        
        $include_db = $flags['include_db'] ?? true;
        $include_private = $flags['include_private'] ?? false;
        $include_ignored = $flags['include_ignored'] ?? false;
        $dry_run = $flags['dry_run'] ?? false;
        $inject_files = $flags['inject_files'] ?? [];
        
        // Define default standard ignore patterns
        $ignore_rules = ['.git', '.DS_Store', 'Thumbs.db', 'node_modules'];
        
        // Load template-specific ignore rules from .orbitignore if present
        $ignore_file = $source_dir . '/.orbitignore';
        if (file_exists($ignore_file)) {
            $lines = file($ignore_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) continue;
                    $ignore_rules[] = $line;
                }
            }
        }
        
        if (!is_dir($source_dir)) return ['success' => false, 'error' => 'Source directory not found'];
        if (!is_dir($temp_dir)) mkdir($temp_dir, 0777, true);

        // --- INSTANT DRY-RUN PRE-SCAN ---
        $files_to_package = [];
        $total_unzipped_size = 0;
        
        foreach ($inject_files as $rel => $content) {
            $size = strlen($content);
            $files_to_package[$rel] = [
                'is_injected' => true,
                'content' => $content,
                'rel' => $rel,
                'size' => $size
            ];
            $total_unzipped_size += $size;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($source_dir) + 1);
                $normalizedPath = str_replace('\\', '/', $relativePath);
                
                // Global Security Mandate: Never package or leak files ending in -private.json
                if (!$include_private && substr($normalizedPath, -13) === '-private.json') {
                    continue;
                }
                
                $is_stateful = (strpos($normalizedPath, 'app.db') !== false || strpos($normalizedPath, 'data/') === 0 || substr($normalizedPath, -7) === '.sqlite');
                if (!$include_db && $is_stateful) {
                    continue;
                }
                
                // Match against ignore rules
                $is_ignored = false;
                foreach ($ignore_rules as $rule) {
                    $clean_rule = trim($rule, '/');
                    if (empty($clean_rule)) continue;
                    
                    if (
                        $normalizedPath === $clean_rule || 
                        strpos($normalizedPath, $clean_rule . '/') === 0 || 
                        basename($normalizedPath) === $clean_rule ||
                        (function_exists('fnmatch') && fnmatch($clean_rule, $normalizedPath))
                    ) {
                        $is_ignored = true;
                        break;
                    }
                }
                if (!$include_ignored && $is_ignored) continue;
                
                // Differential Sync Check
                $local_hash = md5_file($filePath);
                if (isset($remote_manifest[$normalizedPath]) && $remote_manifest[$normalizedPath] === $local_hash) {
                    continue; // Skip identical files
                }
                
                $file_size = $file->getSize();
                $files_to_package[$normalizedPath] = [
                    'path' => $filePath,
                    'rel' => $relativePath,
                    'size' => $file_size
                ];
                $total_unzipped_size += $file_size;
            }
        }

        // Check if uncompressed size exceeds max limits BEFORE zipping
$total_mb = round($total_unzipped_size / 1048576, 2);
        
if ($dry_run) {
    return [
        'success' => true,
        'file_count' => count($files_to_package),
        'estimated_bytes' => $total_unzipped_size,
        'estimated_mb' => $total_mb
    ];
}

if ($total_mb > $max_mb) {$file_sizes = [];
            foreach ($files_to_package as $rel => $info) {
                $file_sizes[$rel] = $info['size'];
            }
            arsort($file_sizes);
            $top_files = array_slice($file_sizes, 0, 10);
            
            return [
                'success' => false,
                'error' => 'PAYLOAD_TOO_LARGE',
                'total_mb' => $total_mb,
                'top_files' => $top_files
            ];
        }

        // --- ACTUAL ZIP COMPRESSION STAGE ---
        $zip_path = $temp_dir . '/deploy_payload_' . time() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($files_to_package as $rel => $info) {
                if (isset($info['is_injected']) && $info['is_injected']) {
                    $zip->addFromString($info['rel'], $info['content']);
                } else {
                    $zip->addFile($info['path'], $info['rel']);
                }
            }
            $zip->close();
            return [
                'success' => true,
                'zip_path' => $zip_path,
                'size' => filesize($zip_path)
            ];
        }
        
        return ['success' => false, 'error' => 'ZIP_CREATION_FAILED'];
    }

    /**
     * Transmits the ZIP payload to the remote receiver endpoint.
     */
    public static function pushToServer($zip_path, $receiver_url, $secret, $instance_name, $overwrite_data) {
        if (!file_exists($zip_path)) return ['success' => false, 'error' => 'ZIP file not found'];

        $cfile = new CURLFile($zip_path, 'application/zip', 'package.zip');
        
        $data = [
            'package' => $cfile,
            'instance_name' => $instance_name,
            'overwrite_data' => $overwrite_data ? '1' : '0',
            'secret' => $secret // Secure POST parameter fallback in case HTTP Headers are stripped
        ];
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15); // 15s server check limit
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);       // Allow up to 10 mins for massive uploads
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secret
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'Transport Error (cURL): ' . $error];
        }
        
        if ($http_code !== 200) {
            $msg = "HTTP {$http_code}";
            if ($http_code === 404) $msg .= " Not Found (Verify receiver.php is uploaded to /var/www/html/)";
            if ($http_code === 401) $msg .= " Unauthorized (Verify your secret keys match in Orbit and receiver.php)";
            if ($http_code === 500) $msg .= " Internal Server Error (Check VPS php-fpm or nginx error logs)";
            if ($http_code === 502) $msg .= " Bad Gateway (Verify php-fpm service is running on VPS)";
            if ($http_code === 403) $msg .= " Forbidden (Verify directory ownership of /var/www/html/ on VPS)";
            
            $clean_response = trim(substr(strip_tags($response), 0, 120));
            return [
                'success' => false, 
                'error' => "{$msg}. Server Payload: " . ($clean_response ? "\"{$clean_response}...\"" : "Empty Body")
            ];
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null) {
            $clean_response = trim(substr(strip_tags($response), 0, 120));
            return [
                'success' => false, 
                'error' => 'Malformed response (Server returned non-JSON data). Server Payload: ' . ($clean_response ? "\"{$clean_response}...\"" : "Empty Body")
            ];
        }
        
        return $decoded;
    }

    public static function pushChunkToServer($chunk_data, $chunk_index, $total_chunks, $upload_id, $instance_name, $receiver_url, $secret) {
        $tmp_file = tempnam(sys_get_temp_dir(), 'chk');
        file_put_contents($tmp_file, $chunk_data);
        
        $cfile = new CURLFile($tmp_file, 'application/octet-stream', 'chunk.dat');
        
        $data = [
            'action' => 'upload_chunk',
            'chunk' => $cfile,
            'chunk_index' => $chunk_index,
            'total_chunks' => $total_chunks,
            'upload_id' => $upload_id,
            'instance_name' => $instance_name,
            'secret' => $secret
        ];
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secret]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        unlink($tmp_file);
        
        if ($error) return ['success' => false, 'error' => 'cURL Error: ' . $error];
        if ($http_code !== 200) return ['success' => false, 'error' => "HTTP {$http_code} - " . strip_tags($response)];
        
        return json_decode($response, true) ?: ['success' => false, 'error' => 'Invalid JSON from receiver'];
    }
    
    public static function extractOnServer($upload_id, $instance_name, $overwrite_data, $receiver_url, $secret) {
        $data = [
            'action' => 'extract_deploy',
            'upload_id' => $upload_id,
            'instance_name' => $instance_name,
            'overwrite_data' => $overwrite_data ? '1' : '0',
            'secret' => $secret
        ];
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secret]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) return ['success' => false, 'error' => 'cURL Error: ' . $error];
        if ($http_code !== 200) return ['success' => false, 'error' => "HTTP {$http_code} - " . strip_tags($response)];
        
        return json_decode($response, true) ?: ['success' => false, 'error' => 'Invalid JSON from receiver'];
    }

    /**
     * Sends a backup management request to the remote receiver.
     */
    public static function manageBackups($receiver_url, $secret, $instance_name, $action, $file = null, $note = null, $restore_mode = 'full') {
        $data = [
            'action' => $action,
            'instance_name' => $instance_name,
            'secret' => $secret
        ];
        if ($file) $data['file'] = $file;
        if ($note) $data['note'] = $note;
        if ($restore_mode) $data['restore_mode'] = $restore_mode;
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secret
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => "HTTP " . $http_code . " - " . strip_tags($response)];
        }
        
        return json_decode($response, true) ?: ['success' => false, 'error' => 'Invalid server response'];
    }

    /**
     * Sends a deletion request to the remote receiver.
     */
    public static function deleteFromServer($receiver_url, $secret, $instance_name) {
        $data = [
            'action' => 'delete',
            'instance_name' => $instance_name,
            'secret' => $secret
        ];
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secret
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => "HTTP " . $http_code];
        }
        
        return json_decode($response, true) ?: ['success' => false, 'error' => 'Invalid server response'];
    }

    /**
     * Sends a directory rename request to the remote receiver.
     */
    public static function renameOnServer($receiver_url, $secret, $old_prefix, $new_prefix) {
        $data = [
            'action' => 'rename',
            'old_name' => $old_prefix,
            'new_name' => $new_prefix,
            'instance_name' => $old_prefix,
            'secret' => $secret
        ];
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secret
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => "HTTP " . $http_code . " - " . strip_tags($response)];
        }
        
        return json_decode($response, true) ?: ['success' => false, 'error' => 'Invalid server response'];
    }

    /**
     * Reads the local Nginx mirror file, generating it if it doesn't exist yet.
     */
    public static function getLocalNginxConfig($domain, $php_version, $admin_notice = '', $tunnel_mode = false) {
        $file = dirname(__DIR__) . '/data/nginx.conf';
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        $config = self::generateNginxConfig($domain, $php_version, $admin_notice, $tunnel_mode);
        if (!empty($domain)) {
            file_put_contents($file, $config);
        }
        return $config;
    }

    /**
     * Dynamically injects or updates the Admin Notice sub_filter block in the local Nginx config.
     */
    public static function updateNginxNotice($config_path, $notice) {
        if (!file_exists($config_path)) return false;
        $config = file_get_contents($config_path);
        
        $notice_html = '';
        if (!empty($notice)) {
            $safe_notice = htmlspecialchars($notice, ENT_QUOTES, 'UTF-8');
            $inner_html = "<div id=\"orbit-admin-notice\" style=\"position:fixed;top:0;left:0;right:0;background:#f59e0b;color:#000;text-align:center;padding:10px 40px;font-family:system-ui,sans-serif;font-size:14px;font-weight:600;z-index:999999;box-shadow:0 4px 10px rgba(0,0,0,0.2);display:flex;justify-content:center;align-items:center;box-sizing:border-box;\"><span style=\"flex:1;word-break:break-word;\">{$safe_notice}</span><button onclick=\"document.getElementById(&quot;orbit-admin-notice&quot;).style.display=&quot;none&quot;;\" style=\"background:none;border:none;font-size:20px;line-height:1;cursor:pointer;color:#000;padding:0 8px;margin-right:-20px;\">&times;</button></div></body>";
            $notice_html = "    sub_filter '</body>' '{$inner_html}';\n    sub_filter_once on;";
        }
        
        $pattern = '/[ \t]*# \[ORBIT_ADMIN_NOTICE_START\].*?# \[ORBIT_ADMIN_NOTICE_END\]/s';
        $replacement = "    # [ORBIT_ADMIN_NOTICE_START]\n" . ($notice_html ? $notice_html . "\n" : "") . "    # [ORBIT_ADMIN_NOTICE_END]";
        
        if (preg_match($pattern, $config)) {
            $config = preg_replace($pattern, $replacement, $config);
        } else {
            // Fallback for older configs: inject right after absolute_redirect off;
            $fallback_pattern = '/(absolute_redirect off;)/';
            $replacement_fallback = "$1\n\n" . $replacement;
            $config = preg_replace($fallback_pattern, $replacement_fallback, $config);
        }
        
        file_put_contents($config_path, $config);
        return $config;
    }

    /**
     * Pulls the live Nginx configuration from the remote receiver.
     */
    public static function pullNginxFromServer($receiver_url, $secret) {
        $data = [
            'action' => 'pull_nginx',
            'instance_name' => 'orbit_kernel',
            'secret' => $secret
        ];
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secret
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => "HTTP " . $http_code . " - " . strip_tags($response)];
        }
        
        return json_decode($response, true) ?: ['success' => false, 'error' => 'Invalid server response'];
    }

    /**
     * Toggles the maintenance mode flag on the server.
     */
    public static function toggleMaintenanceOnServer($receiver_url, $secret, $instance_prefix) {
        $data = [
            'action' => 'toggle_maintenance',
            'instance_name' => $instance_prefix,
            'secret' => $secret
        ];
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secret
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => "HTTP " . $http_code . " - " . strip_tags($response)];
        }
        
        return json_decode($response, true) ?: ['success' => false, 'error' => 'Invalid server response'];
    }

    /**
     * Generates a fully customized dual-routing Nginx Server Block config
     */
    /**
     * Checks if an instance directory already exists on the server.
     */
    public static function checkInstanceOnServer($receiver_url, $secret, $instance_name) {
        $data = [
            'action' => 'check_instance',
            'instance_name' => $instance_name,
            'secret' => $secret
        ];
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secret
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => "HTTP " . $http_code];
        }
        
        return json_decode($response, true) ?: ['success' => false, 'error' => 'Invalid server response'];
    }

    public static function generateNginxConfig($domain, $php_version, $admin_notice = '', $tunnel_mode = false) {
        $listen = $tunnel_mode ? "127.0.0.1:80" : "80";
        $notice_html = '';
        if (!empty($admin_notice)) {
            $safe_notice = htmlspecialchars($admin_notice, ENT_QUOTES, 'UTF-8');
            $inner_html = "<div id=\"orbit-admin-notice\" style=\"position:fixed;top:0;left:0;right:0;background:#f59e0b;color:#000;text-align:center;padding:10px 40px;font-family:system-ui,sans-serif;font-size:14px;font-weight:600;z-index:999999;box-shadow:0 4px 10px rgba(0,0,0,0.2);display:flex;justify-content:center;align-items:center;box-sizing:border-box;\"><span style=\"flex:1;word-break:break-word;\">{$safe_notice}</span><button onclick=\"document.getElementById(&quot;orbit-admin-notice&quot;).style.display=&quot;none&quot;;\" style=\"background:none;border:none;font-size:20px;line-height:1;cursor:pointer;color:#000;padding:0 8px;margin-right:-20px;\">&times;</button></div></body>";
            $notice_html = "    sub_filter '</body>' '{$inner_html}';\n    sub_filter_once on;\n";
        }

        return <<<CONF
# ORBIT DUAL-ROUTING INFRASTRUCTURE KERNEL

# 1. Wildcard Subdomains (*.domain.com)
server {
    listen {$listen};
    # Match subdomains, explicitly excluding 'www'
    server_name ~^(?!www\.)(?<subdomain>.+)\.{$domain}$;
    
    # Allow large file uploads (ZIP payloads)
    client_max_body_size 512M;
    
    # Dynamic root for subdomains
    root /var/www/html/instances/\$subdomain;
    index index.php index.html;

    # Prevent absolute redirects from triggering protocol downgrade loops
    absolute_redirect off;

    # [ORBIT_ADMIN_NOTICE_START]
{$notice_html}    # [ORBIT_ADMIN_NOTICE_END]

    # [ORBIT_MAINTENANCE_START]
    if (-f /var/www/html/instances/\$subdomain/maintenance.flag) {
        return 503;
    }
    error_page 503 @maintenance;
    location @maintenance {
        root /var/www/html;
        rewrite ^(.*)$ /instances/orbit_kernel/maintenance.html break;
    }
    # [ORBIT_MAINTENANCE_END]

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/{$php_version}-fpm.sock;
        fastcgi_param PHP_VALUE "upload_max_filesize=512M \\n post_max_size=512M";
    }
}

# 2. Main Domain (Home App)
server {
    listen {$listen};
    server_name {$domain} www.{$domain};
    
    # Allow large file uploads (ZIP payloads)
    client_max_body_size 512M;
    
    # Root fixed to the special Home App instance
    root /var/www/html/instances/_home;
    index index.php index.html;

    # Prevent absolute redirects from triggering protocol downgrade loops
    absolute_redirect off;

    # [ORBIT_ADMIN_NOTICE_START]
{$notice_html}    # [ORBIT_ADMIN_NOTICE_END]

    # [ORBIT_MAINTENANCE_START]
    if (-f /var/www/html/instances/_home/maintenance.flag) {
        return 503;
    }
    error_page 503 @maintenance_home;
    location @maintenance_home {
        root /var/www/html;
        rewrite ^(.*)$ /instances/orbit_kernel/maintenance.html break;
    }
    # [ORBIT_MAINTENANCE_END]

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/{$php_version}-fpm.sock;
        fastcgi_param PHP_VALUE "upload_max_filesize=512M \\n post_max_size=512M";
    }
}

# 3. Deployer API & Receiver (Tunnel Endpoint)
server {
    listen {$listen};
    server_name deploy.{$domain};
    
    client_max_body_size 512M;
    root /var/www/html;
    index index.php index.html;
    absolute_redirect off;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/{$php_version}-fpm.sock;
        fastcgi_param PHP_VALUE "upload_max_filesize=512M \\n post_max_size=512M";
    }
}

# 4. IP Catch-all (System APIs & Fallback)
server {
    listen {$listen} default_server;
    server_name _;
    
    client_max_body_size 512M;
    root /var/www/html;
    index index.php index.html;
    absolute_redirect off;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/{$php_version}-fpm.sock;
        fastcgi_param PHP_VALUE "upload_max_filesize=512M \\n post_max_size=512M";
    }
}
CONF;
    }

    /**
     * Programmatically connects to the VPS via SSH to write the Nginx config and reload Nginx
     */
    public static function bootstrapNginxViaSSH($ip, $user, $password, $domain, $php_version) {
        $config_content = self::getLocalNginxConfig($domain, $php_version);
        $config_escaped = escapeshellarg($config_content);
        
        // Target paths on the VPS
        $nginx_path = '/etc/nginx/sites-available/default';
        
        // Command sequence: Write configuration, test, and restart Nginx + PHP-FPM
        $remote_command = "echo {$config_escaped} | sudo tee {$nginx_path} && sudo nginx -t && sudo systemctl restart nginx && sudo systemctl restart {$php_version}-fpm";
        
        if (!empty($password)) {
            // Check if sshpass is available locally to supply password to SSH interactive terminal
            $has_sshpass = shell_exec('which sshpass');
            if (!$has_sshpass) {
                return [
                    'success' => false, 
                    'error' => 'sshpass is missing from your host device. Please install it, configure SSH Public Key Auth, or execute the setup manually.',
                    'config' => $config_content
                ];
            }
            $cmd = "sshpass -p " . escapeshellarg($password) . " ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 {$user}@{$ip} " . escapeshellarg($remote_command);
        } else {
            // Fallback: Attempt standard SSH assuming Public Key Authentication is already configured
            $cmd = "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 {$user}@{$ip} " . escapeshellarg($remote_command);
        }
        
        $output = [];
        $return_var = 0;
        @exec($cmd . ' 2>&1', $output, $return_var);
        
        if ($return_var !== 0) {
            return [
                'success' => false,
                'error' => 'SSH Execution Failed: ' . implode("\n", $output),
                'config' => $config_content
            ];
        }
        
        return ['success' => true];
    }

    /**
     * Stages the Orbit Kernel (Nginx config, Receiver, Apply script) and pushes it to the VPS.
     */
    public static function stageKernelUpdates($domain, $php_version, $receiver_secret, $vps_ip, $tunnel_mode = false) {
        $temp_dir = dirname(__DIR__) . '/data/kernel_stage_' . time();
        if (!is_dir($temp_dir)) mkdir($temp_dir, 0777, true);
        
        // 1. Load Local Nginx Config
        $nginx_config = self::getLocalNginxConfig($domain, $php_version, '', $tunnel_mode);
        file_put_contents($temp_dir . '/nginx.conf', $nginx_config);
        
        // 2. Generate Receiver
        $template_path = dirname(__DIR__) . '/modules/receiver_template.php';
        $template = file_exists($template_path) ? file_get_contents($template_path) : '';
        $receiver_code = str_replace('{{ORBIT_SECRET_KEY}}', $receiver_secret, $template);
        file_put_contents($temp_dir . '/receiver.php', $receiver_code);
        
        // 3. Generate HTML Maintenance Page
        $maintenance_html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapid Maintenance Mode</title>
    <style>
        :root {
            --bg: #0f172a;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --accent: #f59e0b;
        }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        body::before {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(245,158,11,0.15) 0%, transparent 70%);
            transform: translate(-50%, -50%);
            z-index: -1;
            filter: blur(40px);
            animation: pulse 4s infinite alternate ease-in-out;
        }
        @keyframes pulse {
            0% { transform: translate(-50%, -50%) scale(0.9); opacity: 0.8; }
            100% { transform: translate(-50%, -50%) scale(1.2); opacity: 1; }
        }
        .card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 24px;
            padding: 40px;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        }
        .icon {
            font-size: 48px;
            margin-bottom: 24px;
            display: inline-block;
            animation: float 3s infinite ease-in-out;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        h1 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        p {
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .loader {
            width: 24px;
            height: 24px;
            border: 3px solid rgba(255,255,255,0.1);
            border-radius: 50%;
            border-top-color: var(--accent);
            display: inline-block;
            animation: spin 1s infinite linear;
        }
        @keyframes spin {
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🪐</div>
        <h1>Rapid Update in Progress</h1>
        <p>We are executing a lightning-fast system upgrade. This page will automatically refresh and resume traffic once the process is complete.</p>
        <div class="loader"></div>
    </div>
    <script>
        setTimeout(function() {
            location.reload();
        }, 10000);
    </script>
</body>
</html>
HTML;
        file_put_contents($temp_dir . '/maintenance.html', $maintenance_html);
        
        $run_id = time();
        
        // 4. Generate Apply Script
        $apply_script = <<<BASH
#!/bin/bash
sleep 2 # Allow receiver.php to return HTTP 200 before restarting services
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

echo "[info] 🚀 Applying Orbit Kernel Updates..."
echo "[info] ⏳ Run ID: {$run_id}"

# 0. Check and install missing PHP extensions
echo "[info] 📦 Checking for missing {$php_version} extensions..."
MISSING_PKGS=""
for ext in sqlite3 zip mbstring curl; do
    pkg="{$php_version}-\$ext"
    if ! dpkg -s "\$pkg" >/dev/null 2>&1; then
        MISSING_PKGS="\$MISSING_PKGS \$pkg"
    fi
done

if [ ! -z "\$MISSING_PKGS" ]; then
    echo "[status] 📥 Installing missing extensions:\$MISSING_PKGS..."
    apt-get update && apt-get install -y \$MISSING_PKGS
    echo "[success] 📦 Extensions installed successfully."
    RESTART_PHP=true
else
    echo "[info] 📦 All {$php_version} extensions are already installed."
fi

# 1. Update Receiver
echo "[info] 📥 Updating Receiver API gateway..."
cp /var/www/html/instances/orbit_kernel/receiver.php /var/www/html/receiver.php
chown www-data:www-data /var/www/html/receiver.php

# 2. Update Nginx
echo "[info] ⚙️ Copying Nginx configuration mirror..."
cp /var/www/html/instances/orbit_kernel/nginx.conf /etc/nginx/sites-available/default

# 3. Restart Services
echo "[status] 🔄 Restarting Nginx (Connection may briefly drop)..."
nginx -t && systemctl restart nginx

if [ "\$RESTART_PHP" = true ]; then
    echo "[status] 🔄 Restarting {$php_version}-fpm to apply changes..."
    systemctl restart {$php_version}-fpm
fi

echo "[success] 🪐 Kernel update complete!"
BASH;
// CRITICAL: Force Linux line endings (\n) to prevent bash "command not found" errors on the VPS
$apply_script = str_replace(["\r\n", "\r"], ["\n", "\n"], $apply_script);
file_put_contents($temp_dir . '/apply.sh', $apply_script);
        
// 5. Zip the files
$zip_path = dirname(__DIR__) . '/data/kernel_payload_' . time() . '.zip';
$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $zip->addFile($temp_dir . '/nginx.conf', 'nginx.conf');
    $zip->addFile($temp_dir . '/receiver.php', 'receiver.php');
    $zip->addFile($temp_dir . '/apply.sh', 'apply.sh');
    $zip->addFile($temp_dir . '/maintenance.html', 'maintenance.html');
    $zip->close();
} else {
    return ['success' => false, 'error' => 'Failed to create kernel ZIP payload'];
}// 6. Push to Server (Using existing deployment pipeline)
$vps_port_open = false;
if (!empty($vps_ip)) {
    $connection = @fsockopen($vps_ip, 80, $errno, $errstr, 1);
    if (is_resource($connection)) {
        $vps_port_open = true;
        fclose($connection);
    }
}
$receiver_url = (!$vps_port_open && !empty($domain)) ? "https://deploy.{$domain}/receiver.php" : "http://{$vps_ip}/receiver.php";
$result = self::pushToServer($zip_path, $receiver_url, $receiver_secret, 'orbit_kernel', true);
        
// 7. Cleanup
unlink($temp_dir . '/nginx.conf');
unlink($temp_dir . '/receiver.php');
unlink($temp_dir . '/apply.sh');
unlink($temp_dir . '/maintenance.html');
rmdir($temp_dir);
if (file_exists($zip_path)) unlink($zip_path);
        
$result['target_url'] = $receiver_url; // Attach target URL for UI logging
if (isset($result['success']) && $result['success']) {
    $result['run_id'] = $run_id;
}
        
return $result;
    }
}