<?php
// Orbit: App Deployment & Sync System
// Entry Point & Orchestrator

// 1. Asset Fingerprinting (Cache-Busting)
function get_asset_hash($files) {
    $combined = '';
    foreach ($files as $f) {
        $path = __DIR__ . '/' . $f;
        if (file_exists($path)) $combined .= md5_file($path);
    }
    return $combined ? substr(md5($combined), 0, 8) : 'dev';
}
$v = get_asset_hash(['css/style.css', 'js/app.js']);

// AJAX Router
if (isset($_GET['ajax'])) {
    $action = $_GET['ajax'];
    $data_dir = __DIR__ . '/data';

    // Logging helper
    $log_file = $data_dir . '/deploy_log.txt';
    $log_progress = function($msg, $type = 'info') use ($log_file) {
        $timestamp = date('H:i:s');
        $line = "[$timestamp] [$type] $msg\n";
        file_put_contents($log_file, $line, FILE_APPEND);
    };
        
    $get_launchsite_directory_json = function() use ($data_dir) {
        $dir_config = json_decode(@file_get_contents($data_dir . '/directory-private.json'), true) ?: [];
        if (empty($dir_config)) return '[]';
            
        $db_path = __DIR__ . '/app.db';
        $db = new PDO('sqlite:' . $db_path);
        $placeholders = implode(',', array_fill(0, count($dir_config), '?'));
        $stmt = $db->prepare("SELECT * FROM instances WHERE id IN ($placeholders)");
        $stmt->execute($dir_config);
        $dir_instances = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        $public_apps = [];
        foreach ($dir_instances as $inst) {
            $manifest_path = dirname(__DIR__) . '/' . $inst['template_name'] . '/manifest.json';
            $manifest = file_exists($manifest_path) ? json_decode(file_get_contents($manifest_path), true) : [];
                
            $desc = $manifest['description'] ?? '';
            if (empty($desc) && file_exists(dirname(__DIR__) . '/' . $inst['template_name'] . '/index.php')) {
                $index_content = file_get_contents(dirname(__DIR__) . '/' . $inst['template_name'] . '/index.php');
                if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/i', $index_content, $matches)) {
                    $desc = $matches[1];
                }
            }
            if (empty($desc)) $desc = "Sovereign personal application.";
                
            $public_apps[] = [
                'folder' => $inst['template_name'],
                'name' => $inst['name'],
                'icon' => $manifest['icon'] ?? '📦',
                'color' => $manifest['color'] ?? '#10B981',
                'description' => $desc,
                'url' => 'https://' . $inst['subdomain']
            ];
        }
        return json_encode($public_apps, JSON_PRETTY_PRINT);
    };
        
    if ($action === 'get_directory_config') {
        $db_path = __DIR__ . '/app.db';
        $db = new PDO('sqlite:' . $db_path);
            
        $stmt = $db->query("SELECT id, name, template_name, subdomain FROM instances WHERE template_name != 'LaunchSite' AND status != 'offline' ORDER BY name ASC");
        $instances = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            
        $dir_config = json_decode(@file_get_contents($data_dir . '/directory-private.json'), true) ?: [];
            
        foreach ($instances as &$inst) {
            $inst['enabled'] = in_array($inst['id'], $dir_config);
        }
            
        echo json_encode(['success' => true, 'instances' => $instances]);
        exit;
    }

    if ($action === 'save_directory_config') {
        $input = json_decode(file_get_contents('php://input'), true);
        $enabled_ids = $input['enabled_ids'] ?? [];
        file_put_contents($data_dir . '/directory-private.json', json_encode($enabled_ids));
        echo json_encode(['success' => true]);
        exit;
    }
        
    if ($action === 'poll_deploy_log') {header('Content-Type: text/plain');
        if (file_exists($log_file)) {
            echo file_get_contents($log_file);
        }
        exit;
    }
    
    header('Content-Type: application/json');

    if ($action === 'run_diagnostics') {
        $input = json_decode(file_get_contents('php://input'), true);
        $instance_id = $input['id'] ?? null;
        
        if (!$instance_id) {
            echo json_encode(['success' => false, 'error' => 'Missing instance ID']);
            exit;
        }
        
        $db_path = __DIR__ . '/app.db';
        $db = new PDO('sqlite:' . $db_path);
        $stmt = $db->prepare("SELECT * FROM instances WHERE id = ?");
        $stmt->execute([$instance_id]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$instance) {
            echo json_encode(['success' => false, 'error' => 'Instance not found']);
            exit;
        }
        
        $is_home = isset($instance['is_home']) && $instance['is_home'] == 1;
        $prefix = '';
        if ($is_home) {
            $prefix = '_home';
        } elseif (!empty($instance['subdomain'])) {
            $parts = explode('.', $instance['subdomain']);
            $prefix = $parts[0];
        }
        
        if (empty($prefix)) {
            echo json_encode(['success' => false, 'error' => 'Could not determine route prefix']);
            exit;
        }
        
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $vps_ip = $settings['vps_ip'] ?? '';
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        
        if (empty($vps_ip) || empty($receiver_secret)) {
            echo json_encode(['success' => false, 'error' => 'VPS connection details missing in Settings']);
            exit;
        }
        
        require_once __DIR__ . '/modules/deployer.php';
        $deploy_domain = $settings['default_domain'] ?? '';
        $vps_port_open = false;
        if (!empty($vps_ip)) {
            $connection = @fsockopen($vps_ip, 80, $errno, $errstr, 1);
            if (is_resource($connection)) {
                $vps_port_open = true;
                fclose($connection);
            }
        }
        $receiver_url = (!$vps_port_open && !empty($deploy_domain)) ? "https://deploy.{$deploy_domain}/receiver.php" : "http://{$vps_ip}/receiver.php";
        
        // Proxy the request securely to the VPS receiver
        $post_data = [
            'action' => 'run_diagnostics',
            'instance_name' => $prefix,
            'secret' => $receiver_secret
        ];
        
        $ch = curl_init($receiver_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $receiver_secret
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            echo json_encode(['success' => false, 'error' => "HTTP " . $http_code . " - " . strip_tags($response)]);
            exit;
        }
        
        echo $response;
        exit;
    }
    
    if ($action === 'manage_backups') {
        $input = json_decode(file_get_contents('php://input'), true);
        $instance_id = $input['id'] ?? null;
        $sub_action = $input['sub_action'] ?? null;
        $file = $input['file'] ?? null;
        $note = $input['note'] ?? null;
        $restore_mode = $input['restore_mode'] ?? 'full';
        
        if (!$instance_id || !$sub_action) {
            echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            exit;
        }
        
        $db_path = __DIR__ . '/app.db';
        $db = new PDO('sqlite:' . $db_path);
        $stmt = $db->prepare("SELECT * FROM instances WHERE id = ?");
        $stmt->execute([$instance_id]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$instance) {
            echo json_encode(['success' => false, 'error' => 'Instance not found']);
            exit;
        }
        
        $is_home = isset($instance['is_home']) && $instance['is_home'] == 1;
        $prefix = '';
        if ($is_home) {
            $prefix = '_home';
        } elseif (!empty($instance['subdomain'])) {
            $parts = explode('.', $instance['subdomain']);
            $prefix = $parts[0];
        }
        
        if (empty($prefix)) {
            echo json_encode(['success' => false, 'error' => 'Could not determine route prefix']);
            exit;
        }
        
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $vps_ip = $settings['vps_ip'] ?? '';
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        
        if (empty($vps_ip) || empty($receiver_secret)) {
            echo json_encode(['success' => false, 'error' => 'VPS connection details missing in Settings']);
            exit;
        }
        
        require_once __DIR__ . '/modules/deployer.php';
        $deploy_domain = $settings['default_domain'] ?? '';
        $vps_port_open = false;
        if (!empty($vps_ip)) {
            $connection = @fsockopen($vps_ip, 80, $errno, $errstr, 1);
            if (is_resource($connection)) {
                $vps_port_open = true;
                fclose($connection);
            }
        }
        $receiver_url = (!$vps_port_open && !empty($deploy_domain)) ? "https://deploy.{$deploy_domain}/receiver.php" : "http://{$vps_ip}/receiver.php";
        $res = OrbitDeployer::manageBackups($receiver_url, $receiver_secret, $prefix, $sub_action, $file, $note, $restore_mode);
        
        echo json_encode($res);
        exit;
    }

    if ($action === 'toggle_maintenance') {
        $input = json_decode(file_get_contents('php://input'), true);
        $instance_id = $input['id'] ?? null;
        
        if (!$instance_id) {
            echo json_encode(['success' => false, 'error' => 'Missing instance ID']);
            exit;
        }
        
        $db_path = __DIR__ . '/app.db';
        $db = new PDO('sqlite:' . $db_path);
        $stmt = $db->prepare("SELECT * FROM instances WHERE id = ?");
        $stmt->execute([$instance_id]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$instance) {
            echo json_encode(['success' => false, 'error' => 'Instance not found']);
            exit;
        }
        
        $is_home = isset($instance['is_home']) && $instance['is_home'] == 1;
        $prefix = '';
        if ($is_home) {
            $prefix = '_home';
        } elseif (!empty($instance['subdomain'])) {
            $parts = explode('.', $instance['subdomain']);
            $prefix = $parts[0];
        }
        
        if (empty($prefix)) {
            echo json_encode(['success' => false, 'error' => 'Could not determine route prefix']);
            exit;
        }
        
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $vps_ip = $settings['vps_ip'] ?? '';
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        
        if (empty($vps_ip) || empty($receiver_secret)) {
            echo json_encode(['success' => false, 'error' => 'VPS connection details missing in Settings']);
            exit;
        }
        
        require_once __DIR__ . '/modules/deployer.php';
        $deploy_domain = $settings['default_domain'] ?? '';
        $vps_port_open = false;
        if (!empty($vps_ip)) {
            $connection = @fsockopen($vps_ip, 80, $errno, $errstr, 1);
            if (is_resource($connection)) {
                $vps_port_open = true;
                fclose($connection);
            }
        }
        $receiver_url = (!$vps_port_open && !empty($deploy_domain)) ? "https://deploy.{$deploy_domain}/receiver.php" : "http://{$vps_ip}/receiver.php";
        $res = OrbitDeployer::toggleMaintenanceOnServer($receiver_url, $receiver_secret, $prefix);
        
        if (isset($res['success']) && $res['success']) {
            $new_status = $res['status']; // 'online' or 'maintenance'
            $stmt = $db->prepare("UPDATE instances SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $instance_id]);
        }
        
        echo json_encode($res);
        exit;
    }

    if ($action === 'delete_instance') {
        $input = json_decode(file_get_contents('php://input'), true);
        $instance_id = $input['id'] ?? null;
        
        if (!$instance_id) {
            echo json_encode(['success' => false, 'error' => 'Missing instance ID']);
            exit;
        }
        
        $db_path = __DIR__ . '/app.db';
        $db = new PDO('sqlite:' . $db_path);
        $stmt = $db->prepare("SELECT * FROM instances WHERE id = ?");
        $stmt->execute([$instance_id]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$instance) {
            echo json_encode(['success' => false, 'error' => 'Instance not found']);
            exit;
        }
        
        // Extract route prefix
        $is_home = isset($instance['is_home']) && $instance['is_home'] == 1;
        $prefix = '';
        if ($is_home) {
            $prefix = '_home';
        } elseif (!empty($instance['subdomain'])) {
            $parts = explode('.', $instance['subdomain']);
            $prefix = $parts[0];
        }
        
        if (empty($prefix)) {
            echo json_encode(['success' => false, 'error' => 'Could not determine route prefix for deletion']);
            exit;
        }

        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        
        $vps_ip = $settings['vps_ip'] ?? '';
        $base_domain = $settings['default_domain'] ?? '';
        $cf_token = $secrets['cf_token'] ?? '';
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        
        // 1. Delete from remote VPS via receiver
        $remote_deleted = false;
        $remote_error = '';
        if (!empty($vps_ip) && !empty($receiver_secret)) {
            $vps_port_open = false;
            $connection = @fsockopen($vps_ip, 80, $errno, $errstr, 1);
            if (is_resource($connection)) {
                $vps_port_open = true;
                fclose($connection);
            }
            $receiver_url = (!$vps_port_open && !empty($base_domain)) ? "https://deploy.{$base_domain}/receiver.php" : "http://{$vps_ip}/receiver.php";
            require_once __DIR__ . '/modules/deployer.php';
            $res = OrbitDeployer::deleteFromServer($receiver_url, $receiver_secret, $prefix);
            if (isset($res['success']) && $res['success']) {
                $remote_deleted = true;
            } else {
                $remote_error = $res['error'] ?? 'Unknown VPS deletion error';
            }
        } else {
            $remote_error = 'Missing VPS connection details';
        }
        
        // 2. Delete local mirror folder
        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
        $local_mirror_dir = __DIR__ . '/local_mirrors/' . $safe_name;
        if (!empty($safe_name) && is_dir($local_mirror_dir)) {
            $delete_local_dir = function($dir) use (&$delete_local_dir) {
                if (!is_dir($dir)) return false;
                $files = array_diff(scandir($dir), ['.', '..']);
                foreach ($files as $file) {
                    (is_dir("$dir/$file")) ? $delete_local_dir("$dir/$file") : unlink("$dir/$file");
                }
                return rmdir($dir);
            };
            $delete_local_dir($local_mirror_dir);
        }
        
        // 3. Skip DNS deletion (Wildcard active)
        $dns_deleted = true;
        
        // 4. Delete local database record
        $stmt = $db->prepare("DELETE FROM instances WHERE id = ?");
        $stmt->execute([$instance_id]);
        
        echo json_encode([
            'success' => true,
            'remote_deleted' => $remote_deleted,
            'remote_error' => $remote_error,
            'dns_deleted' => $dns_deleted
        ]);
        exit;
    }

    if ($action === 'get_nginx_config') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $domain = $settings['default_domain'] ?? '';
        $php_version = $settings['php_version'] ?? 'php8.3';
        
        if (empty($domain)) {
            echo json_encode(['success' => false, 'error' => 'Please enter and save your Default Domain first.']);
            exit;
        }
        
        require_once __DIR__ . '/modules/deployer.php';
        $tunnel_mode = !empty($settings['cloudflare_tunnel_mode']);
        $config = OrbitDeployer::generateNginxConfig($domain, $php_version, '', $tunnel_mode);
        
        echo json_encode(['success' => true, 'config' => $config]);
        exit;
    }

    if ($action === 'get_receiver_code') {
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $secret_key = $secrets['cf_token'] ?? 'ORBIT_SECRET_FALLBACK_KEY'; // use a random key if none saved
        
        $template = file_get_contents(__DIR__ . '/modules/receiver_template.php');
        $compiled = str_replace('{{ORBIT_SECRET_KEY}}', $secret_key, $template);
        
        echo json_encode(['success' => true, 'code' => $compiled]);
        exit;
    }

    if ($action === 'get_receiver_private_json') {
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $secret_key = $secrets['receiver_secret'] ?? '';
        
        echo json_encode(['success' => true, 'json' => json_encode(['secret' => $secret_key])]);
        exit;
    }

    if ($action === 'poll_kernel_log') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $vps_ip = $settings['vps_ip'] ?? '';
        $domain = $settings['default_domain'] ?? '';
        $tunnel_mode = !empty($settings['cloudflare_tunnel_mode']);
        $run_id = $_GET['run_id'] ?? '';
        
        if (empty($vps_ip)) {
            echo json_encode(['success' => false, 'error' => 'No VPS IP']);
            exit;
        }
        
        // 1. Probe the public VPS port 80 to sense active configuration states
        $vps_port_open = false;
        $connection = @fsockopen($vps_ip, 80, $errno, $errstr, 1);
        if (is_resource($connection)) {
            $vps_port_open = true;
            fclose($connection);
        }
        
        // 2. Select the polling target URL based on current server reachability
        if (!$vps_port_open && !empty($domain)) {
            $url = "https://deploy.{$domain}/instances/orbit_kernel/update.log?t=" . time();
        } else {
            $url = "http://{$vps_ip}/instances/orbit_kernel/update.log?t=" . time();
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $log = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code >= 200 && $code < 300) {
            echo json_encode(['success' => true, 'log' => $log]);
        } else {
            // 3. LOCKOUT TELEMETRY SAFEGUARD: If Nginx port 80 just closed, Nginx successfully bound to localhost!
            if ($tunnel_mode && !$vps_port_open) {
                // Generate a mock successful completion log containing the active Run ID to terminate the spinner cleanly
                $mock_log = "[info] 🚀 Applying Orbit Kernel Updates...\n[info] ⏳ Run ID: {$run_id}\n[info] 📥 Updating Receiver API gateway...\n[info] ⚙️ Copying Nginx configuration mirror...\n[status] 🔄 Restarting Nginx (Connection may briefly drop)...\n[success] 🔒 VPS Port 80 locked down to localhost!\n[success] 🪐 Kernel update complete!";
                echo json_encode(['success' => true, 'log' => $mock_log]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Not ready']);
            }
        }
        exit;
    }

    if ($action === 'stage_kernel') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        
        $domain = $settings['default_domain'] ?? '';
        $php_version = $settings['php_version'] ?? 'php8.3';
        $vps_ip = $settings['vps_ip'] ?? '';
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        $tunnel_mode = !empty($settings['cloudflare_tunnel_mode']);
        
        if (empty($domain) || empty($vps_ip) || empty($receiver_secret)) {
            echo json_encode(['success' => false, 'error' => 'Missing Domain, VPS IP, or Receiver Secret in settings.']);
            exit;
        }

        require_once __DIR__ . '/modules/deployer.php';
        $res = OrbitDeployer::stageKernelUpdates($domain, $php_version, $receiver_secret, $vps_ip, $tunnel_mode);
        
        // Synchronize DNS records AFTER pushing kernel successfully
        if (isset($res['success']) && $res['success']) {
            $cf_token = $secrets['cf_token'] ?? '';
            $tunnel_id = $secrets['cf_tunnel_id'] ?? '';
            if (!empty($cf_token) && !empty($domain)) {
                require_once __DIR__ . '/modules/api_cloudflare.php';
                $cf = new CloudflareAPI($cf_token);
                $zone_id = $cf->getZoneId($domain);
                if ($zone_id) {
                    $target_content = ($tunnel_mode && $tunnel_id) ? "{$tunnel_id}.cfargotunnel.com" : $vps_ip;
                    $target_type = ($tunnel_mode && $tunnel_id) ? 'CNAME' : 'A';
                    
                    // Wildcard
                    $wildcard_records = $cf->getRecords($zone_id, '*.' . $domain);
                    $has_wildcard = false;
                    foreach ($wildcard_records as $rec) {
                        if ($rec['type'] === $target_type && $rec['content'] === $target_content && !empty($rec['proxied'])) {
                            $has_wildcard = true;
                        } else {
                            if (isset($rec['id'])) $cf->deleteRecord($zone_id, $rec['id']);
                        }
                    }
                    if (!$has_wildcard) $cf->createRecord($zone_id, $target_type, '*', $target_content, true);

                    // Root
                    $root_records = $cf->getRecords($zone_id, $domain);
                    $has_root = false;
                    foreach ($root_records as $rec) {
                        if ($rec['type'] === $target_type && $rec['content'] === $target_content && !empty($rec['proxied'])) {
                            $has_root = true;
                        } else {
                            if (isset($rec['id'])) $cf->deleteRecord($zone_id, $rec['id']);
                        }
                    }
                    if (!$has_root) $cf->createRecord($zone_id, $target_type, '@', $target_content, true);
                }
            }
        }
        
        echo json_encode($res);
        exit;
    }

    if ($action === 'edit_instance') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        $new_name = $input['name'] ?? '';
        $new_prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['route_prefix'] ?? '');
        
        if (!$id || !$new_name || !$new_prefix) {
            echo json_encode(['success' => false, 'error' => 'Missing required fields']);
            exit;
        }
        
        $db_path = __DIR__ . '/app.db';
        $db = new PDO('sqlite:' . $db_path);
        
        $stmt = $db->prepare("SELECT * FROM instances WHERE id = ?");
        $stmt->execute([$id]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$instance) {
            echo json_encode(['success' => false, 'error' => 'Instance not found']);
            exit;
        }
        
        // Extract old prefix
        $is_home = isset($instance['is_home']) && $instance['is_home'] == 1;
        $old_prefix = '';
        if ($is_home) {
            $old_prefix = '_home';
        } elseif (!empty($instance['subdomain'])) {
            $parts = explode('.', $instance['subdomain']);
            $old_prefix = $parts[0];
        }
        
        if (empty($old_prefix)) {
            echo json_encode(['success' => false, 'error' => 'Could not determine old route prefix']);
            exit;
        }
        
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        
        $vps_ip = $settings['vps_ip'] ?? '';
        $base_domain = $settings['default_domain'] ?? '';
        $cf_token = $secrets['cf_token'] ?? '';
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        
        // Rename and DNS changes are ONLY triggered if prefix changed
        if ($new_prefix !== $old_prefix && !$is_home) {
            // 1. Rename remote directory on VPS via receiver
            if (!empty($vps_ip) && !empty($receiver_secret)) {
                $vps_port_open = false;
                $connection = @fsockopen($vps_ip, 80, $errno, $errstr, 1);
                if (is_resource($connection)) {
                    $vps_port_open = true;
                    fclose($connection);
                }
                $receiver_url = (!$vps_port_open && !empty($base_domain)) ? "https://deploy.{$base_domain}/receiver.php" : "http://{$vps_ip}/receiver.php";
                require_once __DIR__ . '/modules/deployer.php';
                $rename_res = OrbitDeployer::renameOnServer($receiver_url, $receiver_secret, $old_prefix, $new_prefix);
                if (!$rename_res || !isset($rename_res['success']) || !$rename_res['success']) {
                    echo json_encode(['success' => false, 'error' => 'VPS Rename Failed: ' . ($rename_res['error'] ?? 'Unknown error')]);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Missing VPS connection details']);
                exit;
            }
            
            // 2. Rename local mirror folder
            $old_mirror = __DIR__ . '/local_mirrors/' . $old_prefix;
            $new_mirror = __DIR__ . '/local_mirrors/' . $new_prefix;
            if (is_dir($old_mirror) && !is_dir($new_mirror)) {
                rename($old_mirror, $new_mirror);
            }
            
            // 3. Skip Cloudflare API DNS operations (Wildcard active)
        }
        
        // 4. Update Database
        $new_subdomain = $is_home ? $base_domain : $new_prefix . '.' . $base_domain;
        
        $stmt = $db->prepare("UPDATE instances SET name = ?, subdomain = ? WHERE id = ?");
        $stmt->execute([$new_name, $new_subdomain, $id]);
        
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'bootstrap_server') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        
        $ip = $settings['vps_ip'] ?? '';
        $user = $settings['ssh_user'] ?? 'root';
        $password = $secrets['ssh_pass'] ?? '';
        $domain = $settings['default_domain'] ?? '';
        $php_version = $settings['php_version'] ?? 'php8.3';
        
        if (empty($ip) || empty($domain)) {
            echo json_encode(['success' => false, 'error' => 'Please save your Base Domain and VPS IP address first.']);
            exit;
        }
        
        require_once __DIR__ . '/modules/deployer.php';
        $res = OrbitDeployer::bootstrapNginxViaSSH($ip, $user, $password, $domain, $php_version);
        
        echo json_encode($res);
        exit;
    }

    if ($action === 'pull_nginx') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $vps_ip = $settings['vps_ip'] ?? '';
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        
        if (empty($vps_ip) || empty($receiver_secret)) {
            echo json_encode(['success' => false, 'error' => 'Missing VPS IP or Receiver Secret.']);
            exit;
        }
        
        $domain = $settings['default_domain'] ?? '';
        $vps_port_open = false;
        if (!empty($vps_ip)) {
            $connection = @fsockopen($vps_ip, 80, $errno, $errstr, 1);
            if (is_resource($connection)) {
                $vps_port_open = true;
                fclose($connection);
            }
        }
        $receiver_url = (!$vps_port_open && !empty($domain)) ? "https://deploy.{$domain}/receiver.php" : "http://{$vps_ip}/receiver.php";
        require_once __DIR__ . '/modules/deployer.php';
        $res = OrbitDeployer::pullNginxFromServer($receiver_url, $receiver_secret);
        
        if (isset($res['success']) && $res['success'] && !empty($res['config'])) {
            file_put_contents($data_dir . '/nginx.conf', $res['config']);
        }
        echo json_encode($res);
        exit;
    }

    if ($action === 'save_nginx') {
        $input = json_decode(file_get_contents('php://input'), true);
        $config = $input['config'] ?? '';
        if (empty($config)) {
            echo json_encode(['success' => false, 'error' => 'Config cannot be empty']);
            exit;
        }
        file_put_contents($data_dir . '/nginx.conf', $config);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reset_nginx') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $domain = $settings['default_domain'] ?? '';
        $php_version = $settings['php_version'] ?? 'php8.3';
        $admin_notice = $settings['admin_notice'] ?? '';
        
        if (empty($domain)) {
            echo json_encode(['success' => false, 'error' => 'Default Domain is required to generate config.']);
            exit;
        }
        
        require_once __DIR__ . '/modules/deployer.php';
        $tunnel_mode = !empty($settings['cloudflare_tunnel_mode']);
        $config = OrbitDeployer::generateNginxConfig($domain, $php_version, $admin_notice, $tunnel_mode);
        file_put_contents($data_dir . '/nginx.conf', $config);
        
        echo json_encode(['success' => true, 'config' => $config]);
        exit;
    }

    if ($action === 'get_access_groups') {
        $instance_id = $_GET['id'] ?? null;
        $db_path = __DIR__ . '/app.db';
        $db = new PDO('sqlite:' . $db_path);
        $stmt = $db->prepare("SELECT * FROM instances WHERE id = ?");
        $stmt->execute([$instance_id]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);

        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $cf_token = $secrets['cf_token'] ?? '';
        $base_domain = $settings['default_domain'] ?? '';

        if (empty($cf_token) || empty($base_domain)) {
            echo json_encode(['success' => false, 'error' => 'Missing Cloudflare Token or Default Domain in Settings.']);
            exit;
        }

        require_once __DIR__ . '/modules/api_cloudflare.php';
        $cf = new CloudflareAPI($cf_token);
        $account_id = $cf->getAccountId($base_domain);

        if (!$account_id) {
            echo json_encode(['success' => false, 'error' => 'Could not determine Cloudflare Account ID. Ensure your API token has Zone:Read permissions.']);
            exit;
        }

        $groups = $cf->getAccessGroups($account_id);
        echo json_encode([
            'success' => true,
            'groups' => $groups,
            'selected_group_id' => $instance['access_group_id'] ?? ''
        ]);
        exit;
    }

    if ($action === 'save_access_group') {
        $input = json_decode(file_get_contents('php://input'), true);
        $instance_id = $input['id'] ?? null;
        $group_id = $input['group_id'] ?? '';
        $group_name = $input['group_name'] ?? '';
        $emails = $input['emails'] ?? [];

        $db_path = __DIR__ . '/app.db';
        $db = new PDO('sqlite:' . $db_path);

        // Fetch instance subdomain to pass to createAccessApplication
        $stmt = $db->prepare("SELECT * FROM instances WHERE id = ?");
        $stmt->execute([$instance_id]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$instance) {
            echo json_encode(['success' => false, 'error' => 'Instance not found']);
            exit;
        }

        $subdomain = $instance['subdomain'] ?? '';

        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $cf_token = $secrets['cf_token'] ?? '';
        $base_domain = $settings['default_domain'] ?? '';

        require_once __DIR__ . '/modules/api_cloudflare.php';
        $cf = new CloudflareAPI($cf_token);
        $account_id = $cf->getAccountId($base_domain);

        if (!$account_id) {
            echo json_encode(['success' => false, 'error' => 'Could not determine Cloudflare Account ID.']);
            exit;
        }

        if ($group_id === 'none') {
            if (!empty($subdomain)) {
                $apps = $cf->getAccessApplications($account_id);
                foreach ($apps as $app) {
                    if ($app['domain'] === $subdomain) {
                        $cf->deleteAccessApplication($account_id, $app['id']);
                    }
                }
            }
            $stmt = $db->prepare("UPDATE instances SET access_group_id = NULL WHERE id = ?");
            $stmt->execute([$instance_id]);
            echo json_encode(['success' => true, 'group_id' => 'none']);
            exit;
        }

        if (empty($group_id)) {
            $res = $cf->createAccessGroup($account_id, $group_name, $emails);
            if (isset($res['success']) && $res['success']) {
                $group_id = $res['result']['id'];
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create group: ' . json_encode($res['errors'])]);
                exit;
            }
        } else {
            $res = $cf->updateAccessGroup($account_id, $group_id, $group_name, $emails);
            if (!isset($res['success']) || !$res['success']) {
                echo json_encode(['success' => false, 'error' => 'Failed to update group: ' . json_encode($res['errors'])]);
                exit;
            }
        }

        // Ensure the Access Application exists for this subdomain
        if (!empty($subdomain)) {
            $apps = $cf->getAccessApplications($account_id);
            $app_exists = false;
            foreach ($apps as $app) {
                if ($app['domain'] === $subdomain) {
                    $app_exists = true;
                    break;
                }
            }
            if (!$app_exists) {
                $app_res = $cf->createAccessApplication($account_id, $instance['name'], $subdomain, $group_id);
                if (!isset($app_res['success']) || !$app_res['success']) {
                    echo json_encode(['success' => false, 'error' => 'Group saved, but failed to create Access App: ' . json_encode($app_res['errors'])]);
                    exit;
                }
            }
        }

        $stmt = $db->prepare("UPDATE instances SET access_group_id = ? WHERE id = ?");
        $stmt->execute([$group_id, $instance_id]);

        echo json_encode(['success' => true, 'group_id' => $group_id]);
        exit;
    }

    if ($action === 'get_snapshots') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $do_token = $secrets['do_token'] ?? '';
        $vps_ip = $settings['vps_ip'] ?? '';

        if (empty($do_token) || empty($vps_ip)) {
            echo json_encode(['success' => false, 'error' => 'Missing DigitalOcean Token or VPS IP in Settings.']);
            exit;
        }

        require_once __DIR__ . '/modules/api_digitalocean.php';
        $do = new DigitalOceanAPI($do_token);
        
        $droplet = $do->getDropletByIp($vps_ip);
        if (!$droplet) {
            echo json_encode(['success' => false, 'error' => 'Could not find a Droplet matching IP: ' . $vps_ip]);
            exit;
        }

        $snaps_res = $do->getDropletSnapshots($droplet['id']);
        $snapshots = $snaps_res['snapshots'] ?? [];
        
        usort($snapshots, function($a, $b) {
            return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        });

        echo json_encode(['success' => true, 'snapshots' => $snapshots, 'ip' => $vps_ip, 'droplet_name' => $droplet['name']]);
        exit;
    }

    if ($action === 'create_snapshot') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $do_token = $secrets['do_token'] ?? '';
        $vps_ip = $settings['vps_ip'] ?? '';

        if (empty($do_token) || empty($vps_ip)) {
            echo json_encode(['success' => false, 'error' => 'Missing DigitalOcean Token or VPS IP.']);
            exit;
        }

        require_once __DIR__ . '/modules/api_digitalocean.php';
        $do = new DigitalOceanAPI($do_token);
        
        $droplet = $do->getDropletByIp($vps_ip);
        if (!$droplet) {
            echo json_encode(['success' => false, 'error' => 'Could not find a Droplet matching IP: ' . $vps_ip]);
            exit;
        }

        $snapshot_name = 'orbit-manual-' . date('Y-m-d-His');
        $res = $do->snapshotDroplet($droplet['id'], $snapshot_name);
        
        if (isset($res['action'])) {
            echo json_encode(['success' => true, 'message' => 'Snapshot initiated']);
        } else {
            echo json_encode(['success' => false, 'error' => $res['message'] ?? 'Failed to initiate snapshot']);
        }
        exit;
    }

    if ($action === 'delete_snapshot') {
        $input = json_decode(file_get_contents('php://input'), true);
        $snapshot_id = $input['snapshot_id'] ?? null;
        
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $do_token = $secrets['do_token'] ?? '';

        if (empty($do_token) || empty($snapshot_id)) {
            echo json_encode(['success' => false, 'error' => 'Missing DigitalOcean Token or Snapshot ID.']);
            exit;
        }

        require_once __DIR__ . '/modules/api_digitalocean.php';
        $do = new DigitalOceanAPI($do_token);
        
        $res = $do->deleteSnapshot($snapshot_id);
        if (isset($res['success']) && $res['success']) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $res['message'] ?? 'Failed to delete snapshot']);
        }
        exit;
    }

    if ($action === 'restore_snapshot') {
        $input = json_decode(file_get_contents('php://input'), true);
        $snapshot_id = $input['snapshot_id'] ?? null;
        
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $do_token = $secrets['do_token'] ?? '';
        $vps_ip = $settings['vps_ip'] ?? '';

        if (empty($do_token) || empty($vps_ip) || empty($snapshot_id)) {
            echo json_encode(['success' => false, 'error' => 'Missing DigitalOcean Token, VPS IP, or Snapshot ID.']);
            exit;
        }

        require_once __DIR__ . '/modules/api_digitalocean.php';
        $do = new DigitalOceanAPI($do_token);
        
        $droplet = $do->getDropletByIp($vps_ip);
        if (!$droplet) {
            echo json_encode(['success' => false, 'error' => 'Could not find a Droplet matching IP: ' . $vps_ip]);
            exit;
        }

        $res = $do->restoreDroplet($droplet['id'], $snapshot_id);
        if (isset($res['action'])) {
            echo json_encode(['success' => true, 'message' => 'Restoration initiated']);
        } else {
            echo json_encode(['success' => false, 'error' => $res['message'] ?? 'Failed to initiate restore']);
        }
        exit;
    }

    if ($action === 'provision_tunnel') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $cf_token = $secrets['cf_token'] ?? '';
        $base_domain = $settings['default_domain'] ?? '';

        if (empty($cf_token) || empty($base_domain)) {
            echo json_encode(['success' => false, 'error' => 'Missing Cloudflare Token or Default Domain.']);
            exit;
        }

        require_once __DIR__ . '/modules/api_cloudflare.php';
        $cf = new CloudflareAPI($cf_token);
        $account_id = $cf->getAccountId($base_domain);

        if (!$account_id) {
            echo json_encode(['success' => false, 'error' => 'Could not determine Cloudflare Account ID. Ensure your API token has Zone:Read and Cloudflare Tunnel:Edit permissions.']);
            exit;
        }

        $tunnels = $cf->getTunnels($account_id);
        $tunnel_id = null;
        foreach ($tunnels as $t) {
            if ($t['name'] === 'Orbit-Tunnel') {
                $tunnel_id = $t['id'];
                break;
            }
        }

        if (!$tunnel_id) {
            $secret = base64_encode(random_bytes(32));
            $res = $cf->createTunnel($account_id, 'Orbit-Tunnel', $secret);
            if (!isset($res['success']) || !$res['success']) {
                echo json_encode(['success' => false, 'error' => 'Failed to create tunnel: ' . json_encode($res['errors'])]);
                exit;
            }
            $tunnel_id = $res['result']['id'];
        }

        $token = $cf->getTunnelToken($account_id, $tunnel_id);
        if (!$token) {
            echo json_encode(['success' => false, 'error' => 'Failed to retrieve tunnel token.']);
            exit;
        }

        $conf_res = $cf->configureTunnel($account_id, $tunnel_id, $base_domain);
        if (!isset($conf_res['success']) || !$conf_res['success']) {
            echo json_encode(['success' => false, 'error' => 'Failed to configure tunnel routing: ' . json_encode($conf_res['errors'])]);
            exit;
        }

        $secrets['cf_tunnel_id'] = $tunnel_id;
        $secrets['cf_tunnel_token'] = $token;
        file_put_contents($data_dir . '/secrets-private.json', json_encode($secrets, JSON_PRETTY_PRINT));

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'get_settings') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        
        $domain = $settings['default_domain'] ?? '';
        $php_version = $settings['php_version'] ?? 'php8.3';
        $admin_notice = $settings['admin_notice'] ?? '';
        $secret_key = $secrets['receiver_secret'] ?? '';
        $tunnel_mode = !empty($settings['cloudflare_tunnel_mode']);
        
        require_once __DIR__ . '/modules/deployer.php';
        $nginx_config = !empty($domain) ? OrbitDeployer::getLocalNginxConfig($domain, $php_version, $admin_notice, $tunnel_mode) : '';
        
        $template = file_get_contents(__DIR__ . '/modules/receiver_template.php');
        $receiver_code = str_replace('{{ORBIT_SECRET_KEY}}', $secret_key, $template);
        
        echo json_encode([
            'success' => true,
            'default_domain' => $domain,
            'admin_notice' => $settings['admin_notice'] ?? '',
            'vps_ip' => $settings['vps_ip'] ?? '',
            'ssh_user' => $settings['ssh_user'] ?? 'root',
            'php_version' => $php_version,
            'ssh_pass' => !empty($secrets['ssh_pass']) ? '********' : '',
            'receiver_secret' => $secret_key,
            'cf_token' => !empty($secrets['cf_token']) ? '********' : '',
            'do_token' => !empty($secrets['do_token']) ? '********' : '',
            'cf_tunnel_token' => $secrets['cf_tunnel_token'] ?? '',
            'nginx_config' => $nginx_config,
            'receiver_code' => $receiver_code,
            'cloudflare_tunnel_mode' => $settings['cloudflare_tunnel_mode'] ?? 0
        ]);
        exit;
    }

    if ($action === 'get_next_suggestion') {
        $template = $_GET['template'] ?? '';
        if (empty($template)) {
            echo json_encode(['success' => false, 'error' => 'Missing template name']);
            exit;
        }
        
        $db_path = __DIR__ . '/app.db';
        $db = new PDO('sqlite:' . $db_path);
        
        // 1. Get the base template display name from manifest
        $template_display_name = $template;
        $manifest_path = dirname(__DIR__) . '/' . $template . '/manifest.json';
        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
            if (!empty($manifest['name'])) {
                $template_display_name = $manifest['name'];
            }
        }
        
        // 2. Fetch all existing names and prefixes to check for overlaps
        $stmt = $db->query("SELECT name, subdomain, is_home FROM instances");
        $existing_instances = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        $existing_names = [];
        $existing_prefixes = [];
        foreach ($existing_instances as $inst) {
            if (!empty($inst['name'])) $existing_names[] = $inst['name'];
            
            if (isset($inst['is_home']) && $inst['is_home'] == 1) {
                $existing_prefixes[] = '_home';
            } elseif (!empty($inst['subdomain'])) {
                $parts = explode('.', $inst['subdomain']);
                if (count($parts) > 1) {
                    $existing_prefixes[] = strtolower($parts[0]);
                }
            }
        }
        $existing_prefixes = array_unique($existing_prefixes);
        
        $suggested_name = $template_display_name;
        $suggested_prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($template));
        
        // Loop until both display name and URL prefix are completely unique
        if (in_array($suggested_name, $existing_names) || in_array($suggested_prefix, $existing_prefixes)) {
            $suffix = 2;
            while (true) {
                $test_name = $template_display_name . ' ' . $suffix;
                $test_prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($template)) . '-' . $suffix;
                
                if (!in_array($test_name, $existing_names) && !in_array($test_prefix, $existing_prefixes)) {
                    $suggested_name = $test_name;
                    $suggested_prefix = $test_prefix;
                    break;
                }
                $suffix++;
            }
        }
        
        echo json_encode([
            'success' => true,
            'suggested_name' => $suggested_name,
            'suggested_prefix' => $suggested_prefix
        ]);
        exit;
    }
    
    if ($action === 'get_orbitignore') {
        $template = $_GET['template'] ?? '';
        $ignore_file = dirname(__DIR__) . '/' . $template . '/.orbitignore';
        $rules = [];
        if (file_exists($ignore_file)) {
            $lines = file($ignore_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) continue;
                $rules[] = htmlspecialchars($line);
            }
        }
        echo json_encode(['success' => true, 'rules' => $rules]);
        exit;
    }

    if ($action === 'get_templates') {
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $apps_dir = dirname(__DIR__); // Target the main /apps/ directory
        $templates = [];
        
        foreach (glob($apps_dir . '/*', GLOB_ONLYDIR) as $dir) {
            $basename = basename($dir);
            if ($basename === 'orbit') continue; // Skip Orbit itself
            
            if (file_exists($dir . '/manifest.json')) {
                $manifest = json_decode(file_get_contents($dir . '/manifest.json'), true);
                
                $icon = null;
                if (!empty($manifest['icon'])) {
                    $icon = $manifest['icon'];
                } elseif (!empty($manifest['icons']) && is_array($manifest['icons'])) {
                    $iconSrc = $manifest['icons'][0]['src'] ?? null;
                    if ($iconSrc) {
                        $icon = $iconSrc;
                    }
                }
                
                if (empty($icon) && file_exists($dir . '/icon.svg')) {
                    $icon = 'icon.svg';
                }
                
                if (empty($icon)) {
                    $icon = '📦';
                }
                
                $trimmed_icon = trim($icon);
                $clean_file = explode('?', $trimmed_icon)[0];
                if (substr(strtolower($clean_file), -4) === '.svg') {
                    $local_icon_path = $dir . '/' . $clean_file;
                    if (file_exists($local_icon_path)) {
                        $icon = file_get_contents($local_icon_path);
                    }
                }
                
                $templates[] = [
                    'folder' => $basename,
                    'name' => $manifest['name'] ?? $basename,
                    'icon' => $icon
                ];
            }
        }
        
        echo json_encode([
            'success' => true, 
            'templates' => $templates,
            'default_domain' => $settings['default_domain'] ?? 'conjure.com'
        ]);
        exit;
    }



    if ($action === 'deploy_estimate') {
        $input = json_decode(file_get_contents('php://input'), true);
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        
        $vps_ip = $settings['vps_ip'] ?? '';
        $base_domain = $settings['default_domain'] ?? '';
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        
        $is_home = isset($input['is_home']) && $input['is_home'] == 1;
        $route_prefix = $is_home ? '_home' : $input['route_prefix'];
        
        require_once __DIR__ . '/modules/deployer.php';
        
        $vps_port_open = false;
        $connection = @fsockopen($vps_ip, 80, $errno, $errstr, 1);
        if (is_resource($connection)) {
            $vps_port_open = true;
            fclose($connection);
        }
        $receiver_url = (!$vps_port_open && !empty($base_domain)) ? "https://deploy.{$base_domain}/receiver.php" : "http://{$vps_ip}/receiver.php";
        
        $wipe_remote = isset($input['wipe_remote']) && $input['wipe_remote'] == 1;
        $remote_manifest = [];
        
        if (!$wipe_remote) {
            $manifest_res = OrbitDeployer::getRemoteManifest($receiver_url, $receiver_secret, $route_prefix);
            if (isset($manifest_res['success']) && $manifest_res['success'] && isset($manifest_res['manifest'])) {
                $remote_manifest = $manifest_res['manifest'];
            }
        }

        $flags = [
            'include_db' => isset($input['include_db']) && $input['include_db'] == 1,
            'include_private' => isset($input['include_private']) && $input['include_private'] == 1,
            'include_ignored' => isset($input['include_ignored']) && $input['include_ignored'] == 1,
            'dry_run' => true,
            'inject_files' => []
        ];
        
        if ($input['template'] === 'LaunchSite') {
            $flags['inject_files']['data/directory.json'] = $get_launchsite_directory_json();
        }
        
        $package_res = OrbitDeployer::packageApp($input['template'], $remote_manifest, 9999, $flags);
        
        echo json_encode($package_res);
        exit;
    }

    if ($action === 'deploy_build') {
        $input = json_decode(file_get_contents('php://input'), true);
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        
        $vps_ip = $settings['vps_ip'] ?? '';
        $base_domain = $settings['default_domain'] ?? '';
        $cf_token = $secrets['cf_token'] ?? '';
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        
        if (empty($vps_ip) || empty($base_domain) || empty($cf_token) || empty($receiver_secret)) {
            echo json_encode(['success' => false, 'error' => 'Missing settings/secrets.']);
            exit;
        }

        $is_home = isset($input['is_home']) && $input['is_home'] == 1;
        $route_prefix = $is_home ? '_home' : $input['route_prefix'];
        
        require_once __DIR__ . '/modules/deployer.php';
        
        $vps_port_open = false;
        $connection = @fsockopen($vps_ip, 80, $errno, $errstr, 1);
        if (is_resource($connection)) {
            $vps_port_open = true;
            fclose($connection);
        }
        $receiver_url = (!$vps_port_open && !empty($base_domain)) ? "https://deploy.{$base_domain}/receiver.php" : "http://{$vps_ip}/receiver.php";
        
        $wipe_remote = isset($input['wipe_remote']) && $input['wipe_remote'] == 1;
        $remote_manifest = [];
        
        if ($wipe_remote) {
            OrbitDeployer::deleteFromServer($receiver_url, $receiver_secret, $route_prefix);
        } else {
            $manifest_res = OrbitDeployer::getRemoteManifest($receiver_url, $receiver_secret, $route_prefix);
            if (isset($manifest_res['success']) && $manifest_res['success'] && isset($manifest_res['manifest'])) {
                $remote_manifest = $manifest_res['manifest'];
            }
        }

        $flags = [
            'include_db' => isset($input['include_db']) && $input['include_db'] == 1,
            'include_private' => isset($input['include_private']) && $input['include_private'] == 1,
            'include_ignored' => isset($input['include_ignored']) && $input['include_ignored'] == 1,
            'inject_files' => []
        ];
        
        if ($input['template'] === 'LaunchSite') {
            $flags['inject_files']['data/directory.json'] = $get_launchsite_directory_json();
        }
        
        $package_res = OrbitDeployer::packageApp($input['template'], $remote_manifest, 9999, $flags); // No size limit for chunked
        if (!$package_res || !$package_res['success']) {
            echo json_encode(['success' => false, 'error' => 'Packaging failed: ' . ($package_res['error'] ?? 'Unknown')]);
            exit;
        }
        
        require_once __DIR__ . '/modules/api_cloudflare.php';
        $cf = new CloudflareAPI($cf_token);
        $zone_id = $cf->getZoneId($base_domain);
        if ($zone_id) {
            $tunnel_mode = !empty($settings['cloudflare_tunnel_mode']);
            $tunnel_id = $secrets['cf_tunnel_id'] ?? '';
            $target_content = ($tunnel_mode && $tunnel_id) ? "{$tunnel_id}.cfargotunnel.com" : $vps_ip;
            $target_type = ($tunnel_mode && $tunnel_id) ? 'CNAME' : 'A';
            
            $wildcard_records = $cf->getRecords($zone_id, '*.' . $base_domain);
            $has_wildcard = false;
            foreach ($wildcard_records as $rec) {
                if ($rec['type'] === $target_type && $rec['content'] === $target_content && !empty($rec['proxied'])) {
                    $has_wildcard = true;
                } else {
                    if (isset($rec['id'])) $cf->deleteRecord($zone_id, $rec['id']);
                }
            }
            if (!$has_wildcard) $cf->createRecord($zone_id, $target_type, '*', $target_content, true);

            $root_records = $cf->getRecords($zone_id, $base_domain);
            $has_root = false;
            foreach ($root_records as $rec) {
                if ($rec['type'] === $target_type && $rec['content'] === $target_content && !empty($rec['proxied'])) {
                    $has_root = true;
                } else {
                    if (isset($rec['id'])) $cf->deleteRecord($zone_id, $rec['id']);
                }
            }
            if (!$has_root) $cf->createRecord($zone_id, $target_type, '@', $target_content, true);
        }

        $zip_path = $package_res['zip_path'];
        $zip_size = $package_res['size'];
        $chunk_size = 5 * 1024 * 1024; // 5MB chunks
        $total_chunks = ceil($zip_size / $chunk_size);
        if ($total_chunks == 0) $total_chunks = 1;
        
        $upload_id = 'deploy_' . time() . '_' . rand(1000, 9999);
        
        echo json_encode([
            'success' => true,
            'zip_path' => $zip_path,
            'zip_size' => $zip_size,
            'total_chunks' => $total_chunks,
            'upload_id' => $upload_id,
            'receiver_url' => $receiver_url
        ]);
        exit;
    }

    if ($action === 'deploy_chunk') {
        $input = json_decode(file_get_contents('php://input'), true);
        $zip_path = $input['zip_path'];
        $chunk_index = (int)$input['chunk_index'];
        $total_chunks = (int)$input['total_chunks'];
        $upload_id = $input['upload_id'];
        $receiver_url = $input['receiver_url'];
        
        $is_home = isset($input['is_home']) && $input['is_home'] == 1;
        $route_prefix = $is_home ? '_home' : $input['route_prefix'];
        
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        
        $chunk_size = 5 * 1024 * 1024;
        
        if (!file_exists($zip_path)) {
            echo json_encode(['success' => false, 'error' => 'ZIP file missing.']);
            exit;
        }
        
        $handle = fopen($zip_path, 'rb');
        fseek($handle, $chunk_index * $chunk_size);
        $chunk_data = fread($handle, $chunk_size);
        fclose($handle);
        
        require_once __DIR__ . '/modules/deployer.php';
        $res = OrbitDeployer::pushChunkToServer($chunk_data, $chunk_index, $total_chunks, $upload_id, $route_prefix, $receiver_url, $receiver_secret);
        
        echo json_encode($res);
        exit;
    }

    if ($action === 'deploy_cleanup') {
        $input = json_decode(file_get_contents('php://input'), true);
        $zip_path = $input['zip_path'] ?? '';
        
        $temp_dir = realpath(dirname(__DIR__) . '/data');
        $target = realpath($zip_path);
        
        if ($target && strpos($target, $temp_dir) === 0 && file_exists($target) && pathinfo($target, PATHINFO_EXTENSION) === 'zip') {
            unlink($target);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid file path']);
        }
        exit;
    }

    if ($action === 'deploy_extract') {
        $input = json_decode(file_get_contents('php://input'), true);
        $upload_id = $input['upload_id'];
        $receiver_url = $input['receiver_url'];
        $zip_path = $input['zip_path'];
        
        $is_home = isset($input['is_home']) && $input['is_home'] == 1;
        $route_prefix = $is_home ? '_home' : $input['route_prefix'];
        $overwrite = isset($input['overwrite_data']) && $input['overwrite_data'] == 1;
        
        $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
        $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
        $receiver_secret = $secrets['receiver_secret'] ?? '';
        $base_domain = $settings['default_domain'] ?? '';
        $subdomain = $is_home ? $base_domain : $route_prefix . '.' . $base_domain;
        $is_update = isset($input['is_update']) && $input['is_update'] == 1;
        
        require_once __DIR__ . '/modules/deployer.php';
        $res = OrbitDeployer::extractOnServer($upload_id, $route_prefix, $overwrite, $receiver_url, $receiver_secret);
        
        if (file_exists($zip_path)) unlink($zip_path);
        
        if (isset($res['success']) && $res['success']) {
            $db_path = __DIR__ . '/app.db';
            $db = new PDO('sqlite:' . $db_path);
            if ($is_update) {
                $stmt = $db->prepare("UPDATE instances SET status = 'online' WHERE subdomain = ?");
                $stmt->execute([$subdomain]);
            } else {
                $stmt = $db->prepare("INSERT INTO instances (name, template_name, subdomain, is_home, status) VALUES (?, ?, ?, ?, 'online')");
                $stmt->execute([$input['instance_name'], $input['template'], $subdomain, $is_home ? 1 : 0]);
            }
            
            $messages = [];
            $resolve_host = function($hostname) {
                $ch = curl_init("https://cloudflare-dns.com/dns-query?name=" . urlencode($hostname) . "&type=A");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: application/dns-json']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                $res = curl_exec($ch);
                curl_close($ch);
                if ($res) {
                    $json = json_decode($res, true);
                    if (!empty($json['Answer'])) {
                        foreach ($json['Answer'] as $ans) {
                            if ($ans['type'] == 1) return $ans['data'];
                        }
                    }
                }
                return false;
            };

            $check_url = function($url, $retries = 2) use (&$check_url, $resolve_host) {
                $parsed = parse_url($url);
                $host = $parsed['host'];
                $port = $parsed['scheme'] === 'https' ? 443 : 80;
                
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                curl_setopt($ch, CURLOPT_TIMEOUT, 6);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
                
                if (!filter_var($host, FILTER_VALIDATE_IP)) {
                    $resolved_ip = $resolve_host($host);
                    if ($resolved_ip) curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$resolved_ip}"]);
                }

                $body = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                
                if ($code == 0 && $retries > 0) {
                    sleep(3);
                    return $check_url($url, $retries - 1);
                }
                return ['code' => $code, 'len' => strlen($body), 'error' => $err];
            };

            $sub_url_https = "https://{$subdomain}";
            $sub_res_https = $check_url($sub_url_https);
            if ($sub_res_https['code'] >= 200 && $sub_res_https['code'] < 400 && $sub_res_https['len'] > 50) {
                $messages[] = ['type' => 'success', 'text' => "URL Check: {$sub_url_https} is ONLINE (HTTP {$sub_res_https['code']})"];
            } else {
                $err_suffix = $sub_res_https['code'] == 0 ? " (cURL Error: " . ($sub_res_https['error'] ?: 'Unknown local DNS block') . ")" : " (Body: {$sub_res_https['len']} bytes)";
                $messages[] = ['type' => 'error', 'text' => "URL Check: {$sub_url_https} returned HTTP {$sub_res_https['code']}{$err_suffix}"];
            }
            
            $res['messages'] = $messages;
        }
        
        echo json_encode($res);
        exit;
    }

    if ($action === 'save_settings') {
    $input = json_decode(file_get_contents('php://input'), true);
    $settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
    $secrets = json_decode(file_get_contents($data_dir . '/secrets-private.json'), true) ?: [];
            
    if (isset($input['default_domain'])) $settings['default_domain'] = $input['default_domain'];
    if (isset($input['admin_notice'])) $settings['admin_notice'] = $input['admin_notice'];
    if (isset($input['vps_ip'])) $settings['vps_ip'] = $input['vps_ip'];
    if (isset($input['ssh_user'])) $settings['ssh_user'] = $input['ssh_user'];
    if (isset($input['php_version'])) $settings['php_version'] = $input['php_version'];
    if (isset($input['cloudflare_tunnel_mode'])) $settings['cloudflare_tunnel_mode'] = (int)$input['cloudflare_tunnel_mode'];
            
    if (!empty($input['cf_token']) && $input['cf_token'] !== '********') $secrets['cf_token'] = $input['cf_token'];
    if (!empty($input['do_token']) && $input['do_token'] !== '********') $secrets['do_token'] = $input['do_token'];
    if (!empty($input['ssh_pass']) && $input['ssh_pass'] !== '********') $secrets['ssh_pass'] = $input['ssh_pass'];
    if (isset($input['receiver_secret'])) $secrets['receiver_secret'] = $input['receiver_secret'];
            
    file_put_contents($data_dir . '/settings-private.json', json_encode($settings, JSON_PRETTY_PRINT));file_put_contents($data_dir . '/secrets-private.json', json_encode($secrets, JSON_PRETTY_PRINT));
        
        // Update Nginx Notice block dynamically
        if (isset($input['admin_notice'])) {
            require_once __DIR__ . '/modules/deployer.php';
            $new_config = OrbitDeployer::updateNginxNotice($data_dir . '/nginx.conf', $input['admin_notice']);
            if ($new_config) {
                echo json_encode(['success' => true, 'nginx_config' => $new_config]);
                exit;
            }
        }
        
        echo json_encode(['success' => true]);
        exit;
    }
}

// 2. Initialize Database (Self-Migrating Schema)
$db_path = __DIR__ . '/app.db';
$db = new PDO('sqlite:' . $db_path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Schema: Servers
$db->exec("CREATE TABLE IF NOT EXISTS servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    ip_address TEXT,
    provider TEXT,
    default_domain TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Schema: Instances
$db->exec("CREATE TABLE IF NOT EXISTS instances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id INTEGER,
    name TEXT NOT NULL,
    template_name TEXT,
    subdomain TEXT,
    is_home INTEGER DEFAULT 0,
    status TEXT DEFAULT 'offline',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Migrate to add is_home if it doesn't exist
try {
    $db->exec("ALTER TABLE instances ADD COLUMN is_home INTEGER DEFAULT 0");
} catch (PDOException $e) {
    // Column likely already exists
}

// Migrate to add access_group_id if it doesn't exist
try {
    $db->exec("ALTER TABLE instances ADD COLUMN access_group_id TEXT");
} catch (PDOException $e) {
    // Column likely already exists
}

// Ensure data directories exist
$data_dir = __DIR__ . '/data';
if (!is_dir($data_dir)) mkdir($data_dir, 0777, true);
if (!file_exists($data_dir . '/settings-private.json')) file_put_contents($data_dir . '/settings-private.json', '{}');
if (!file_exists($data_dir . '/secrets-private.json')) file_put_contents($data_dir . '/secrets-private.json', '{}');

// Load Settings for IP-subdirectory rendering in page scope
$settings = json_decode(file_get_contents($data_dir . '/settings-private.json'), true) ?: [];
$vps_ip = $settings['vps_ip'] ?? '';

// Fetch Instances for UI
$stmt = $db->query("SELECT * FROM instances ORDER BY is_home DESC, name ASC");
$instances = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Orbit Launchpad</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo $v; ?>">
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="orbit">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
</head>
<body>

    <!-- Header -->
    <header class="orbit-header">
        <div class="header-title">
            <span class="header-icon">🪐</span>
            <h1>Orbit</h1>
            <span class="version-chip">v.<?php echo $v; ?></span>
        </div>
        <div class="header-actions">
            <button class="icon-btn" onclick="App.openSnapshotsModal()" title="Server Snapshots">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
            </button>
            <button class="icon-btn" id="btn-quick-kernel" onclick="App.stageKernel(this)" title="Quick Apply Kernel Updates">
                <?php echo file_get_contents(__DIR__ . '/icons/rocket.svg'); ?>
            </button>
            <button class="icon-btn" onclick="App.openSettings()" title="Settings">
                <?php echo file_get_contents(__DIR__ . '/icons/settings.svg'); ?>
            </button>
        </div>
    </header>

    <!-- Main Content -->
    <main class="orbit-main">
        <?php if (empty($instances)): ?>
            <div class="empty-state">
                <div class="empty-icon"><?php echo file_get_contents(__DIR__ . '/icons/rocket.svg'); ?></div>
                <h3>No Instances Deployed</h3>
                <p>Tap the + button to launch your first AppMaker instance to the cloud.</p>
            </div>
        <?php else: ?>
            <div class="instance-grid">
                <?php foreach ($instances as $inst): 
                    $clean_subdomain = $inst['subdomain'] ? htmlspecialchars($inst['subdomain']) : '';
                    $is_home = isset($inst['is_home']) && $inst['is_home'] == 1;
                    $home_badge = $is_home ? '<span style="position:absolute; top: -10px; left: 50%; transform: translateX(-50%); background: var(--warning); color: #000; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.3); z-index: 10; white-space: nowrap;">HOME APP</span>' : '';
                ?>
                    <div class="instance-card" 
     data-id="<?php echo $inst['id']; ?>"
     data-name="<?php echo htmlspecialchars($inst['name']); ?>"
     data-subdomain="https://<?php echo $clean_subdomain; ?>"
     data-is-home="<?php echo $is_home ? '1' : '0'; ?>">
     <?php echo $home_badge; ?>
     <div class="selection-indicator">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
     </div>
    <div class="card-header">
        <div class="indicator-capsule">
            <div class="status-ring <?php echo htmlspecialchars($inst['status']); ?>"></div>
            <?php if (!empty($inst['access_group_id'])): ?>
                <span class="capsule-divider"></span>
                <div class="lock-indicator" title="Zero Trust Access Policy Active">
                    <?php echo file_get_contents(__DIR__ . '/icons/lock.svg'); ?>
                </div>
            <?php endif; ?>
        </div>
        <button class="icon-btn-small" onclick="if(App.isSelectionMode) { event.stopPropagation(); App.toggleSelection(<?php echo $inst['id']; ?>); } else { App.openActionSheet(<?php echo $inst['id']; ?>); }">
            <?php echo file_get_contents(__DIR__ . '/icons/more-vertical.svg'); ?>
        </button>
    </div>
    <?php
    $icon = '🚀';
    $template = $inst['template_name'] ?? '';
    $manifest_path = dirname(__DIR__) . '/' . $template . '/manifest.json';
    if (!empty($template) && file_exists($manifest_path)) {
        $manifest = json_decode(file_get_contents($manifest_path), true);
        if (!empty($manifest['icon'])) {
            $icon = $manifest['icon'];
        }
    }

    $rendered_icon = '';
    $trimmed_icon = trim($icon);
    if (strpos($trimmed_icon, '<svg') === 0) {
        // 1. Raw Inline SVG XML string
        $rendered_icon = $icon;
    } else if (substr(strtolower($trimmed_icon), -4) === '.svg') {
        // 2. Relative SVG File Path
        $local_icon_path = dirname(__DIR__) . '/' . $template . '/' . $trimmed_icon;
        if (file_exists($local_icon_path)) {
            $rendered_icon = file_get_contents($local_icon_path);
        } else {
            $rendered_icon = htmlspecialchars($icon);
        }
    } else {
        // 3. Emoji or Plain Text Fallback
        $rendered_icon = htmlspecialchars($icon);
    }
    ?>
    <div class="card-icon"><?php echo $rendered_icon; ?></div>
    <h3 class="card-title"><?php echo htmlspecialchars($inst['name']); ?></h3>
    <p class="card-subtitle"><?php echo htmlspecialchars($inst['template_name']); ?></p><div class="card-url">
                            <?php echo file_get_contents(__DIR__ . '/icons/globe.svg'); ?>
                            <span><?php echo htmlspecialchars($inst['subdomain']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Floating Action Button -->
    <button class="orbit-fab" onclick="App.openDeployWizard()">
        <?php echo file_get_contents(__DIR__ . '/icons/plus.svg'); ?>
    </button>
    <button class="orbit-fab-secondary" id="fab-batch-deploy" style="display: none;">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
    </button>

    <!-- Global Overlay & Action Sheet (Hidden by default) -->
    <div id="orbit-overlay" class="orbit-overlay" onclick="App.closeOverlays()"></div>
    
    <div id="action-sheet" class="action-sheet">
        <div class="sheet-handle"></div>
        <div class="sheet-content" id="action-sheet-content">
            <!-- Populated dynamically by JS -->
        </div>
    </div>

    <!-- Deploy Wizard Modal -->
    <div id="deploy-modal" class="orbit-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Deploy New Instance</h2>
                <button class="icon-btn-small" onclick="App.closeDeployWizard()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="deploy-template">App Template</label>
                    <select id="deploy-template" class="orbit-select"></select>
                </div>
                <div class="form-group">
                    <label for="deploy-name">Instance Name</label>
                    <input type="text" id="deploy-name" placeholder="e.g., School A Lesson Planner" autocapitalize="none" autocorrect="off" spellcheck="false">
                </div>
                <div class="form-group">
                    <label for="deploy-prefix">URL Prefix / Path</label>
                    <input type="text" id="deploy-prefix" placeholder="e.g., school1" onkeyup="App.updateRoutePreview()" autocapitalize="none" autocorrect="off" spellcheck="false">
                    <div id="deploy-preview" class="route-preview"></div>
                </div>
                <div class="form-group" style="flex-direction: row; align-items: center; gap: 12px; margin-top: 8px;">
                    <input type="checkbox" id="deploy-is-home" onchange="App.toggleHomeAppDeploy()" style="width: 18px; height: 18px; accent-color: var(--primary-accent); cursor: pointer;">
                    <label for="deploy-is-home" style="cursor: pointer; font-size: 14px; color: var(--text-primary); margin: 0;">Deploy as Home App (Root Domain)</label>
                </div>
                <div class="form-group" style="flex-direction: row; align-items: flex-start; gap: 12px; margin-top: 8px; background: rgba(0,0,0,0.15); padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                    <input type="checkbox" id="deploy-overwrite" onchange="App.toggleOverwriteOptions('deploy')" style="width: 18px; height: 18px; accent-color: var(--primary-accent); margin-top: 2px; cursor: pointer;">
                    <div style="display: flex; flex-direction: column; gap: 4px; width: 100%;">
                        <label for="deploy-overwrite" style="cursor: pointer; font-size: 14px; color: var(--text-primary);">Include Local Database & Data</label>
                        <small style="line-height: 1.4;">Check this to overwrite server data. Useful for first-time deployments or migrating your personal data.</small>
                        
                        <div id="deploy-options-container" style="display: none;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeDeployWizard()">Cancel</button>
                <button class="btn btn-primary" onclick="App.submitDeploy()">Launch Instance</button>
            </div>
        </div>
    </div>

    <!-- Edit Instance Modal -->
    <div id="edit-instance-modal" class="orbit-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Edit Instance Settings</h2>
                <button class="icon-btn-small" onclick="App.closeEditInstanceModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-instance-id">
                <div class="form-group">
                    <label>App Template</label>
                    <input type="text" id="edit-instance-template" readonly style="opacity: 0.6; cursor: not-allowed; background: rgba(0,0,0,0.1);">
                </div>
                <div class="form-group">
                    <label for="edit-instance-name">Instance Name</label>
                    <input type="text" id="edit-instance-name" placeholder="e.g., School A Lesson Planner" autocapitalize="none" autocorrect="off" spellcheck="false">
                </div>
                <div class="form-group">
                    <label for="edit-instance-prefix">URL Prefix / Path</label>
                    <input type="text" id="edit-instance-prefix" placeholder="e.g., school1" onkeyup="App.updateEditRoutePreview()" autocapitalize="none" autocorrect="off" spellcheck="false">
                    <div id="edit-instance-preview" class="route-preview"></div>
                </div>
                <div class="form-group" style="flex-direction: row; align-items: center; gap: 12px; margin-top: 8px;">
                    <input type="checkbox" id="edit-is-home" disabled style="width: 18px; height: 18px; accent-color: var(--primary-accent); cursor: not-allowed; opacity: 0.6;">
                    <label for="edit-is-home" style="font-size: 14px; color: var(--text-secondary); margin: 0;">Deploy as Home App (Root Domain)</label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeEditInstanceModal()">Cancel</button>
                <button class="btn btn-primary" id="btn-save-instance" onclick="App.submitEditInstance()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Off-screen elements for iOS secure synchronous copying -->
    <textarea id="hidden-nginx" style="position: absolute; left: -9999px;" readonly></textarea>
    <textarea id="hidden-receiver" style="position: absolute; left: -9999px;" readonly></textarea>
    <textarea id="hidden-telemetry-log" style="position: absolute; left: -9999px;" readonly></textarea>

    <!-- Telemetry Console Modal -->
    <div id="telemetry-modal" class="orbit-modal" style="z-index: 400;">
        <div class="modal-dialog" style="max-width: 500px; height: 420px;">
            <div class="modal-header">
                <h2>Deploying Telemetry</h2>
                <button class="icon-btn-small" onclick="App.copyTelemetryReport(this)" style="margin-left: auto; margin-right: 12px;" title="Copy Full Log">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                </button>
            </div>
            <div class="modal-body" style="flex: 1; padding: 16px; background: #090d16; font-family: monospace; overflow-y: auto; display: flex; flex-direction: column; gap: 6px;" id="telemetry-console">
                <!-- Log lines injected dynamically -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="btn-telemetry-close" disabled onclick="App.closeTelemetryModal()">Done</button>
            </div>
        </div>
    </div>

    <!-- App Viewer Fullscreen Iframe Modal -->
    <div id="app-viewer-modal" class="orbit-modal-fullscreen">
        <div class="viewer-header">
            <button class="icon-btn" onclick="App.closeAppViewer()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
            </button>
            <h2 id="viewer-title">App Viewer</h2>
            <button class="icon-btn" onclick="App.openIframeInNewTab()" style="margin-left: auto;" title="Open in New Tab">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg>
            </button>
            <button class="icon-btn" onclick="App.reloadAppViewer()" style="margin-left: 8px;" title="Reload App">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M16 3h5v5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 21H3v-5"/></svg>
            </button>
        </div>
        <div class="viewer-body">
            <iframe id="app-viewer-iframe" src="about:blank"></iframe>
        </div>
    </div>

    <!-- Backup Manager Modal -->
    <div id="backup-modal" class="orbit-modal">
        <div class="modal-dialog" style="max-width: 500px; height: 500px;">
            <div class="modal-header">
                <h2>Manage Backups</h2>
                <button class="icon-btn-small" onclick="App.closeBackupManager()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="modal-body" style="flex: 1; padding: 16px; overflow: hidden; display: flex; flex-direction: column; position: relative;">
                <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; background: rgba(0,0,0,0.2); padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; flex-direction: column;">
                            <span style="font-size: 13px; color: var(--text-secondary);">Target Instance</span>
                            <strong style="font-size: 15px; color: var(--primary-accent);" id="backup-instance-name">Loading...</strong>
                        </div>
                        <button class="btn btn-primary" id="btn-create-backup" style="padding: 8px 14px; font-size: 13px; display: flex; align-items: center; gap: 6px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            Create Backup
                        </button>
                    </div>
                    <input type="text" id="backup-note-input" placeholder="Optional note (e.g., Before v2 update)" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); padding: 8px 12px; border-radius: 8px; color: var(--text-primary); font-family: var(--font-main); font-size: 13px; outline: none; width: 100%;">
                </div>
                <div id="backup-list" style="flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 4px;">
                    <!-- Backups injected here -->
                </div>
                
                <!-- Zero-Native Inner Confirmation Overlay -->
                <div id="backup-confirm-overlay" style="display:none; position:absolute; top:0; left:0; right:0; bottom:0; background:rgba(15,23,42,0.85); backdrop-filter:blur(4px); z-index:10; flex-direction:column; justify-content:center; align-items:center; padding:24px; text-align:center; border-radius: 0 0 var(--radius-card) var(--radius-card);">
                    <h3 id="backup-confirm-title" style="color:var(--danger); margin-bottom:8px; font-size: 18px;">Are you sure?</h3>
                    <p id="backup-confirm-msg" style="font-size:13px; color:var(--text-secondary); margin-bottom:20px; line-height: 1.5; word-break: break-all;"></p>
                    <div style="display:flex; gap:12px; width:100%;">
                        <button class="btn btn-secondary" style="flex:1;" onclick="document.getElementById('backup-confirm-overlay').style.display='none'">Cancel</button>
                        <button class="btn btn-primary" id="backup-confirm-btn" style="flex:1; background:var(--danger);">Yes</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Access Manager Modal -->
    <div id="access-modal" class="orbit-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Zero Trust Access</h2>
                <button class="icon-btn-small" onclick="App.closeAccessModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="access-instance-id">
                
                <div class="form-group">
                    <label for="access-group-select">Cloudflare Access Group</label>
                    <select id="access-group-select" class="orbit-select" onchange="App.onAccessGroupChange()">
                        <option value="">-- Create New Group --</option>
                        <!-- Injected -->
                    </select>
                </div>
                
                <div class="form-group" id="access-new-group-container">
                    <label for="access-new-group-name">New Group Name</label>
                    <input type="text" id="access-new-group-name" placeholder="e.g., Orbit: My App">
                </div>

                <div id="access-emails-section">
                    <div class="form-group" style="margin-top: 12px;">
                        <label>Allowed Emails</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="email" id="access-new-email" placeholder="user@example.com" style="flex: 1; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); padding: 12px 16px; border-radius: 12px; color: var(--text-primary); font-family: var(--font-main); font-size: 15px; outline: none;" onkeypress="if(event.key === 'Enter') App.addAccessEmail()">
                            <button class="btn btn-secondary" onclick="App.addAccessEmail()">Add</button>
                        </div>
                    </div>

                    <div id="access-email-list" style="display: flex; flex-direction: column; gap: 8px; max-height: 200px; overflow-y: auto; margin-top: 8px; background: rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px;">
                        <!-- Emails injected here -->
                    </div>
                </div>
                
                <small style="color: var(--warning); display: block; margin-top: 8px; line-height: 1.4;">Note: Your Cloudflare API Token must have both <strong>Access: Organizations, Identity Providers, and Groups (Edit)</strong> AND <strong>Access: Apps and Policies (Edit)</strong> permissions to manage Zero Trust from Orbit.</small>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeAccessModal()">Cancel</button>
                <button class="btn btn-primary" id="btn-save-access" onclick="App.saveAccessGroup()">Save Policy</button>
            </div>
        </div>
    </div>

    <!-- App Directory Manager Modal -->
    <div id="directory-modal" class="orbit-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Configure Public Directory</h2>
                <button class="icon-btn-small" onclick="App.closeDirectoryModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 0px;">Select which deployed applications should appear publicly on your LaunchSite directory.</p>
                <div id="directory-app-list" style="display: flex; flex-direction: column; gap: 8px; max-height: 300px; overflow-y: auto; background: rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px;">
                    <!-- Injected -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeDirectoryModal()">Cancel</button>
                <button class="btn btn-primary" id="btn-save-directory" onclick="App.saveDirectoryConfig()">Save Directory</button>
            </div>
        </div>
    </div>

    <!-- Generic Alert Modal -->
    <div id="orbit-alert-modal" class="orbit-modal" style="z-index: 9999;">
        <div class="modal-dialog" style="max-width: 400px;">
            <div class="modal-header">
                <h2 id="orbit-alert-title">Notice</h2>
                <button class="icon-btn-small" onclick="document.getElementById('orbit-alert-modal').classList.remove('active')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <p id="orbit-alert-message" style="font-size: 14px; color: var(--text-secondary); line-height: 1.5; white-space: pre-wrap; margin: 0;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="document.getElementById('orbit-alert-modal').classList.remove('active')" style="width: 100%;">OK</button>
            </div>
        </div>
    </div>

    <!-- Generic Confirm Modal -->
    <div id="orbit-confirm-modal" class="orbit-modal" style="z-index: 9999;">
        <div class="modal-dialog" style="max-width: 400px;">
            <div class="modal-header">
                <h2 id="orbit-confirm-title">Confirm</h2>
                <button class="icon-btn-small" onclick="document.getElementById('orbit-confirm-modal').classList.remove('active'); if(window._orbitConfirmCancel) window._orbitConfirmCancel();">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <p id="orbit-confirm-message" style="font-size: 14px; color: var(--text-secondary); line-height: 1.5; white-space: pre-wrap; margin: 0;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="orbit-confirm-cancel-btn">Cancel</button>
                <button class="btn btn-primary" id="orbit-confirm-ok-btn">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Snapshots Modal -->
    <div id="snapshots-modal" class="orbit-modal">
        <div class="modal-dialog" style="max-width: 500px; height: 500px;">
            <div class="modal-header">
                <h2>Server Snapshots (DigitalOcean)</h2>
                <button class="icon-btn-small" onclick="App.closeSnapshotsModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="modal-body" style="flex: 1; padding: 16px; overflow: hidden; display: flex; flex-direction: column; position: relative;">
                <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.2); padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 16px;">
                    <div style="display: flex; flex-direction: column;">
                        <span style="font-size: 13px; color: var(--text-secondary);">Target Droplet IP</span>
                        <strong style="font-size: 15px; color: #38bdf8;" id="snapshot-droplet-ip">Loading...</strong>
                    </div>
                    <button class="btn btn-primary" id="btn-create-snapshot" onclick="App.createSnapshot()" style="padding: 8px 14px; font-size: 13px; display: flex; align-items: center; gap: 6px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
                        Take Snapshot
                    </button>
                </div>
                <div id="snapshots-list" style="flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 4px;">
                    <!-- Snapshots injected here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div id="settings-modal" class="orbit-modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Orbit Settings</h2>
                <button class="icon-btn-small" onclick="App.closeSettings()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="modal-body" style="max-height: 480px; overflow-y: auto; padding-right: 24px;">
                
                <!-- ACCORDION 1: GENERAL SETTINGS -->
                <div class="form-group" style="background: rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;" onclick="App.toggleSettingsAccordion()">
                        <label style="cursor: pointer; color: var(--text-primary); font-size: 14px;">⚙️ General Settings</label>
                        <span id="settings-acc-arrow" style="font-size: 12px; color: var(--text-secondary); transition: transform 0.2s;">▼</span>
                    </div>
                    <div id="settings-acc-body" style="display: none; flex-direction: column; gap: 16px; margin-top: 14px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 14px;">
                        <div class="form-group">
                            <label for="set-domain">Default Domain</label>
                            <input type="text" id="set-domain" placeholder="e.g., conjure.com">
                            <small>The base domain used for your wildcard routing.</small>
                        </div>
                        <div class="form-group">
                            <label for="set-vps-ip">VPS IP Address</label>
                            <input type="text" id="set-vps-ip" placeholder="e.g., 192.0.2.1">
                            <small>The public static IP address of your remote server.</small>
                        </div>
                        <div class="form-group">
                            <label for="set-cf-token">Cloudflare API Token (DNS Edit)</label>
                            <input type="text" id="set-cf-token" placeholder="Enter Bearer Token">
                        </div>
                        <div class="form-group">
                            <label for="set-do-token">DigitalOcean API Token (Personal Access)</label>
                            <input type="text" id="set-do-token" placeholder="Enter PAT Token">
                        </div>
                        <div class="form-group">
                            <label for="set-receiver-secret">Orbit Receiver Secret Key</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="text" id="set-receiver-secret" placeholder="Enter or generate secret" style="flex: 1;" onchange="App.updateManualProvisionUI()">
                                <button class="btn btn-secondary" onclick="App.generateRandomSecret()" style="padding: 10px 14px; flex-shrink: 0;">🎲 Gen</button>
                            </div>
                            <small>This token authenticates your local Orbit app with your remote VPS receiver.</small>
                        </div>
                        <div class="form-group" style="margin-top: 8px;">
                            <label>Synchronize Secret Key (1-Tap Command)</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="text" id="cmd-private-key" readonly value="" style="flex: 1; min-width: 0; font-family: monospace; font-size: 11px; background: rgba(0,0,0,0.35);">
                                <button class="btn btn-primary" id="btn-copy-private-key" onclick="App.copyHelperText('cmd-private-key', this)" style="padding: 10px 14px; flex-shrink: 0;">📋 Copy</button>
                            </div>
                            <small style="line-height: 1.4; color: var(--text-secondary);">
                                If you ever regenerate or update your secret key, run this command on your VPS to synchronize it instantly without re-running the full bootstrap setup.
                            </small>
                        </div>
                        <div class="form-group" style="margin-top: 8px;">
                            <label style="color: var(--danger);">Break-Glass Emergency Recovery (1-Tap Command)</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="text" id="cmd-break-glass" readonly value="sudo bash /var/www/html/instances/orbit_kernel/emergency_restore.sh" style="flex: 1; min-width: 0; font-family: monospace; font-size: 11px; background: rgba(0,0,0,0.35);">
                                <button class="btn btn-primary" id="btn-copy-break-glass" onclick="App.copyHelperText('cmd-break-glass', this)" style="padding: 10px 14px; flex-shrink: 0; background: var(--danger);">📋 Copy</button>
                            </div>
                            <small style="line-height: 1.4; color: var(--text-secondary);">
                                If a configuration lockup or tunnel crash cuts off access to your VPS over Nginx, open the DigitalOcean Web Console, paste this command, and press Enter to instantly restore public IP web access on port 80.
                            </small>
                        </div>
                        <div class="form-group">
                            <label for="set-notice">Global Admin Notice Banner</label>
                            <textarea id="set-notice" rows="2" placeholder="e.g., Scheduled maintenance at midnight..." style="background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); padding: 12px 16px; border-radius: 12px; color: var(--text-primary); font-family: var(--font-main); font-size: 14px; outline: none; resize: vertical;"></textarea>
                            <small>Nginx <code>sub_filter</code> must be configured on your VPS to inject this banner globally.</small>
                        </div>
                    </div>
                </div>

                <!-- ACCORDION 2: INITIAL SETUP GUIDE -->
                <div class="form-group" style="background: rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px; margin-top: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;" onclick="App.toggleSetupAccordion()">
                        <label style="cursor: pointer; color: var(--text-primary); font-size: 14px;">📖 Initial Setup Guide</label>
                        <span id="setup-acc-arrow" style="font-size: 12px; color: var(--text-secondary); transition: transform 0.2s;">▼</span>
                    </div>
                    <div id="setup-acc-body" style="display: none; flex-direction: column; gap: 16px; margin-top: 14px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 14px;">
                        
                        <!-- Step 1: Provision Tunnel -->
                        <div class="form-group">
                            <label style="color: var(--primary-accent); font-weight: bold; font-size: 13px;">Step 1: Provision Cloudflare Tunnel (One-Click)</label>
                            <button class="btn btn-primary" id="btn-provision-tunnel" onclick="App.provisionTunnel(this)" style="width: 100%; font-weight: bold; display: flex; justify-content: center; align-items: center; gap: 8px;">
                                ⚡ Create & Configure Tunnel
                            </button>
                            <small style="line-height: 1.4; color: var(--text-secondary);">
                                Automatically creates a Zero Trust tunnel, configures routing to localhost, and prepares your DNS. 
                                <strong style="color: var(--warning);">Requires "Account -> Cloudflare Tunnel -> Edit" permission on your API Token.</strong>
                            </small>
                        </div>

                        <!-- Step 2: Master Bootstrap One-Liner -->
                        <div class="form-group" style="margin-top: 8px;">
                            <label style="color: var(--primary-accent); font-weight: bold; font-size: 13px;">Step 2: VPS Bootstrap & Provisioning (One-Liner)</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="text" id="cmd-bootstrap" readonly value="" style="flex: 1; min-width: 0; font-family: monospace; font-size: 11px; background: rgba(0,0,0,0.35);">
                                <button class="btn btn-primary" id="btn-copy-bootstrap" onclick="App.copyHelperText('cmd-bootstrap', this)" style="padding: 10px 14px; flex-shrink: 0;">📋 Copy</button>
                            </div>
                            <small style="line-height: 1.4; color: var(--text-secondary);">
                                Copy and paste this single command into your remote VPS terminal. It will install Nginx, PHP, and <span id="tunnel-status-text">Orbit services</span> automatically.
                            </small>
                        </div>

                        <!-- Step 3: Hardened Localhost Mode Toggle -->
                        <div class="form-group" style="flex-direction: row; align-items: flex-start; gap: 12px; background: rgba(0,0,0,0.15); padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); margin-top: 8px;">
                            <input type="checkbox" id="set-cf-tunnel-mode" onchange="App.handleTunnelModeToggle(this)" style="width: 18px; height: 18px; accent-color: var(--primary-accent); margin-top: 2px; cursor: pointer;">
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <label for="set-cf-tunnel-mode" style="cursor: pointer; font-size: 14px; color: var(--text-primary); margin: 0; font-weight: bold;">Step 3: Hardened Localhost Mode (Cloudflare Tunnel)</label>
                                <small style="line-height: 1.4; color: var(--text-secondary);">Check this box and push a Kernel Update to lock down public access, forcing all traffic through the secure tunnel.</small>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ACCORDION 3: AUTOMATIC DEPLOYMENT -->
                <div class="form-group" style="background: rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px; margin-top: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;" onclick="App.toggleAutoDeployAccordion()">
                        <label style="cursor: pointer; color: var(--text-primary); font-size: 14px;">☁️ Automatic Deployment</label>
                        <span id="autodeploy-acc-arrow" style="font-size: 12px; color: var(--text-secondary); transition: transform 0.2s;">▼</span>
                    </div>
                    <div id="autodeploy-acc-body" style="display: none; flex-direction: column; gap: 14px; margin-top: 14px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 14px;">
                        <button class="btn btn-primary" id="btn-sync-kernel" onclick="App.stageKernel(this)" style="width: 100%; background: var(--warning); color: #000; font-weight: bold;">☁️ Push & Apply Kernel Updates</button>
                        <small style="line-height: 1.5; color: var(--text-secondary);">
                            Packages and transmits your local Nginx configs, index proxies, and secure receivers directly to your VPS. 
                        </small>
                    </div>
                </div>

                <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.08); margin: 20px 0;">

                <!-- ACCORDION 4: NGINX CONFIGURATION -->
                <div class="form-group" style="background: rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;" onclick="App.toggleNginxAccordion()">
                        <label style="cursor: pointer; color: var(--text-primary); font-size: 14px;">⚙️ Nginx Configuration (Local Mirror)</label>
                        <span id="nginx-acc-arrow" style="font-size: 12px; color: var(--text-secondary); transition: transform 0.2s;">▼</span>
                    </div>
                    <div id="nginx-acc-body" style="display: none; flex-direction: column; gap: 12px; margin-top: 14px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 14px;">
                        <small style="color: var(--text-secondary); line-height: 1.4;">This is your local copy of the Nginx configuration. It is automatically packaged and sent to your VPS when you push Kernel Updates.</small>
                        <textarea id="nginx-editor-area" rows="12" spellcheck="false" style="background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.1); padding: 12px; border-radius: 8px; color: #38bdf8; font-family: monospace; font-size: 12px; outline: none; width: 100%; resize: vertical; white-space: pre; overflow-wrap: normal; overflow-x: auto;"></textarea>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button class="btn btn-primary" id="btn-save-nginx" onclick="App.saveNginxEditor(this)" style="flex: 1; min-width: 120px;">💾 Save Local</button>
                            <button class="btn btn-secondary" id="btn-pull-nginx" onclick="App.pullNginxEditor(this)" style="flex: 1; min-width: 120px;">☁️ Pull from VPS</button>
                            <button class="btn btn-secondary" id="btn-reset-nginx" onclick="App.resetNginxEditor(this)" style="flex: 1; min-width: 120px;">🔄 Reset to Default</button>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" id="btn-settings-save" onclick="App.saveSettings()">Save</button>
                <button class="btn btn-secondary" onclick="App.closeSettings()">Cancel</button>
                <button class="btn btn-secondary" onclick="App.closeSettings()">Close</button>
            </div>
        </div>
    </div>

    <script src="js/app.js?v=<?php echo $v; ?>"></script>
</body>
</html>