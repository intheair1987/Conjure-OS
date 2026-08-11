<?php
// ==============================================================================
// PLUGIN: AppMaker
// DESCRIPTION: Micro-App Grid.
// Purpose: A home screen for standalone apps residing in the root /apps/ folder.
// Features: Iframe execution, Long-press context menus, Zip Export, Reordering.
// ==============================================================================

$am_root = CJOS_PATH_APPS;
$am_config_file = CJOS_PATH_DATA . '/app-maker-config.json';

if (!is_dir($am_root)) mkdir($am_root, 0777, true);

// --- 0. NETWORK HELPERS ---
// --- 0.5. FIREWALL-PROOF AUTO-DISCOVERY HANDLER ---
if (isset($_REQUEST['plugin_action']) && $_REQUEST['plugin_action'] === 'am_report_domain') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    
    $domain = preg_replace('/[^a-zA-Z0-9.:-]/', '', $_REQUEST['domain'] ?? '');
    if (strpos($domain, '.ts.net') !== false) {
        $current_config = file_exists($am_config_file) ? json_decode(file_get_contents($am_config_file), true) : [];
        if (!isset($current_config['tailscale_domain']) || $current_config['tailscale_domain'] !== $domain) {
            $current_config['tailscale_domain'] = $domain;
            file_put_contents($am_config_file, json_encode($current_config, JSON_PRETTY_PRINT));
        }
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_REQUEST['plugin_action']) && $_REQUEST['plugin_action'] === 'am_report_port') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    
    $port = preg_replace('/[^0-9]/', '', $_REQUEST['port'] ?? '');
    if ($port) {
        $current_config = file_exists($am_config_file) ? json_decode(file_get_contents($am_config_file), true) : [];
        if (!isset($current_config['non_ssl_port']) || $current_config['non_ssl_port'] !== $port) {
            $current_config['non_ssl_port'] = $port;
            file_put_contents($am_config_file, json_encode($current_config, JSON_PRETTY_PRINT));
        }
    }
    echo json_encode(['status' => 'success']);
    exit;
}

function am_discover_local_https_port() {
    $ports = [];
    
    // 1. Read listening ports natively from /proc/net/tcp (works reliably on Android/Linux)
    if (file_exists('/proc/net/tcp')) {
        $lines = @file('/proc/net/tcp');
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 4) {
                    $state = $parts[3];
                    if ($state === '0A') { // '0A' is TCP_LISTEN in /proc/net/tcp
                        $local = explode(':', $parts[1]);
                        if (count($local) === 2) {
                            $portHex = $local[1];
                            $port = hexdec($portHex);
                            $server_port = isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 0;
                            if ($port > 0 && $port != $server_port && !in_array($port, $ports)) {
                                $ports[] = $port;
                            }
                        }
                    }
                }
            }
        }
    }
    
    // 2. Add typical fallback HTTPS ports
    $candidates = [443, 8443, 8003, 8081, 8000];
    foreach ($candidates as $c) {
        $server_port = isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 0;
        if ($c != $server_port && !in_array($c, $ports)) {
            $ports[] = $c;
        }
    }

    // 3. Fast non-blocking handshake check to isolate the actual secure port
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);
    
    $tailscale_ip = am_get_tailscale_ip();
    $hosts = $tailscale_ip ? [$tailscale_ip, '127.0.0.1'] : ['127.0.0.1'];
    
    foreach ($ports as $port) {
        if (in_array($port, [22, 23, 25, 3306, 5432, 6379, 9000, 27017])) continue;
        
        foreach ($hosts as $host) {
            $fp = @stream_socket_client(
                'ssl://' . $host . ':' . $port,
                $errno,
                $errstr,
                0.5, // Increased timeout to 0.5s for Android KSweb reliability
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if ($fp !== false) {
                fclose($fp);
                return $port;
            }
        }
    }
    
    return null;
}

function am_get_tailscale_domain() {
    global $am_config_file;
    $domain = null;
    
    // 1. Try to query Tailscale Serve config directly first (gives 100% accurate hostname + port automatically)
    $os = strtoupper(substr(PHP_OS, 0, 3));
    $cmds = [];
    if ($os === 'WIN') {
        $cmds[] = 'tailscale.exe serve status --json';
    } else {
        $cmds[] = 'tailscale serve status --json';
        $cmds[] = '/usr/bin/tailscale serve status --json';
        $cmds[] = '/data/data/com.termux/files/usr/bin/tailscale serve status --json';
    }
    
    foreach ($cmds as $cmd) {
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code === 0 && !empty($out)) {
            $jsonStr = implode('', $out);
            $data = json_decode($jsonStr, true);
            if (isset($data['Web']) && is_array($data['Web'])) {
                foreach (array_keys($data['Web']) as $key) {
                    if (strpos($key, '.ts.net') !== false) {
                        return rtrim($key, '.'); // Returns e.g. 'your-node.ts.net:8003'
                    }
                }
            }
        }
    }

    // 2. Try learned cache second (survives CLI execution blocks on Android)
    if (file_exists($am_config_file)) {
        $config = json_decode(file_get_contents($am_config_file), true);
        if (!empty($config['tailscale_domain'])) {
            $domain = $config['tailscale_domain'];
        }
    }

    // 3. Try native reverse DNS lookup (gets domain without port)
    if (!$domain) {
        $ip = am_get_tailscale_ip();
        if ($ip) {
            $hostname = @gethostbyaddr($ip);
            if ($hostname && $hostname !== $ip && strpos($hostname, '.ts.net') !== false) {
                $domain = rtrim($hostname, '.');
            }
        }
    }

    // 4. Try legacy tailscale status JSON CLI as a final fallback
    if (!$domain) {
        $status_cmds = $os === 'WIN' ? ['tailscale.exe status --json'] : [
            'tailscale status --json',
            '/usr/bin/tailscale status --json',
            '/data/data/com.termux/files/usr/bin/tailscale status --json'
        ];
        foreach ($status_cmds as $cmd) {
            $out = [];
            $code = 1;
            @exec($cmd . ' 2>&1', $out, $code);
            if ($code === 0 && !empty($out)) {
                $jsonStr = implode('', $out);
                $data = json_decode($jsonStr, true);
                if (isset($data['Self']['DNSName'])) {
                    $domain = rtrim($data['Self']['DNSName'], '.');
                }
            }
        }
    }

    if ($domain) {
        // Strip any existing port from the domain name (cleans up any poisoned cache)
        $domain = explode(':', $domain)[0];
        
        // Automatically discover and attach the active local HTTPS port
        $https_port = am_discover_local_https_port();
        if ($https_port && $https_port != 443) {
            return $domain . ':' . $https_port;
        }
        return $domain;
    }

    return null;
}

function am_get_tailscale_ip() {
    // 1. Try native PHP interfaces discovery first (safest and works under Android/KSweb sandboxing)
    if (function_exists('net_get_interfaces')) {
        $interfaces = @net_get_interfaces();
        if ($interfaces !== false) {
            foreach ($interfaces as $name => $info) {
                if (isset($info['unicast']) && is_array($info['unicast'])) {
                    foreach ($info['unicast'] as $unicast) {
                        $ip = $unicast['address'] ?? '';
                        // CGNAT range: 100.64.0.0 to 100.127.255.255
                        if (preg_match('/^100\.(6[4-9]|[7-9]\d|1[0-1]\d|12[0-7])\.\d{1,3}\.\d{1,3}$/', $ip)) {
                            return $ip;
                        }
                    }
                }
            }
        }
    }

    // 2. Command query fallback
    $os = strtoupper(substr(PHP_OS, 0, 3));
    $cmds = [];
    if ($os === 'WIN') {
        $cmds[] = 'tailscale.exe ip -4';
    } else {
        $cmds[] = 'tailscale ip -4';
        $cmds[] = '/usr/bin/tailscale ip -4';
        $cmds[] = '/data/data/com.termux/files/usr/bin/tailscale ip -4';
    }
    
    foreach ($cmds as $cmd) {
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code === 0 && !empty($out)) {
            $ip = trim(implode('', $out));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }
    }
    
    // 3. Script configuration parsing fallback
    $output = [];
    if ($os === 'WIN') {
        @exec('ipconfig', $output);
    } else {
        @exec('ip addr 2>&1 || ifconfig 2>&1', $output);
    }
    
    foreach ($output as $line) {
        if (preg_match_all('/\b100\.(6[4-9]|[7-9]\d|1[0-1]\d|12[0-7])\.\d{1,3}\.\d{1,3}\b/', $line, $matches)) {
            foreach ($matches[0] as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $ip;
                }
            }
        }
    }
    
    return null;
}

function am_get_runtime_info() {
    $runtime_paths = [
        '/storage/emulated/0/Conjure_Config/runtime_active.json',
        '/sdcard/Conjure_Config/runtime_active.json',
        sys_get_temp_dir() . '/../Conjure_Config/runtime_active.json'
    ];
    $is_runtime = false;
    $http_port = 8001;
    $https_port = 8000;
    
    foreach ($runtime_paths as $path) {
        if (file_exists($path)) {
            $data = json_decode(@file_get_contents($path), true);
            if (is_array($data) && isset($data['status']) && $data['status'] === 'RUNNING') {
                $is_runtime = true;
                if (!empty($data['http_port'])) $http_port = (int)$data['http_port'];
                if (!empty($data['https_port'])) $https_port = (int)$data['https_port'];
                break;
            }
        }
    }
    
    if (isset($_SERVER['HTTP_X_CONJURE_RUNTIME'])) {
        $is_runtime = true;
    }
    
    return [
        'is_runtime' => $is_runtime,
        'http_port' => $http_port,
        'https_port' => $https_port,
        'mdns_host' => 'conjure.local',
        'tailscale_domain' => am_get_tailscale_domain()
    ];
}

function am_get_lan_ip() {
    // 1. Try native PHP interfaces discovery first
    if (function_exists('net_get_interfaces')) {
        $interfaces = @net_get_interfaces();
        if ($interfaces !== false) {
            foreach ($interfaces as $name => $info) {
                if (isset($info['unicast']) && is_array($info['unicast'])) {
                    foreach ($info['unicast'] as $unicast) {
                        $ip = $unicast['address'] ?? '';
                        if (preg_match('/\b(192\.168\.\d{1,3}\.\d{1,3}|10\.(?!(6[4-9]|[7-9]\d|1[0-1]\d|12[0-7])\.)\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3})\b/', $ip)) {
                            return $ip;
                        }
                    }
                }
            }
        }
    }

    // 2. Command query fallback
    $os = strtoupper(substr(PHP_OS, 0, 3));
    $output = [];
    if ($os === 'WIN') {
        @exec('ipconfig', $output);
    } else {
        @exec('ip addr 2>&1 || ifconfig 2>&1', $output);
    }
    
    foreach ($output as $line) {
        if (preg_match_all('/\b(192\.168\.\d{1,3}\.\d{1,3}|10\.(?!(6[4-9]|[7-9]\d|1[0-1]\d|12[0-7])\.)\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3})\b/', $line, $matches)) {
            foreach ($matches[0] as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $ip;
                }
            }
        }
    }
    return null;
}

// --- 1. BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {

    // A. GET APP LIST (Scans manifests)
    if ($_POST['plugin_action'] === 'am_get_apps') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $folders = array_filter(glob($am_root . '/*'), 'is_dir');
        $apps = [];
        
        foreach ($folders as $folder) {
            $id = basename($folder);
            $manifestPath = $folder . '/manifest.json';
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                $apps[] = [
                    'id' => $id,
                    'name' => $manifest['name'] ?? $id,
                    'icon' => $manifest['icon'] ?? '📦',
                    'color' => $manifest['color'] ?? 'var(--primary)',
                    'path' => 'apps/' . $id . '/index.php'
                ];
            }
        }
        
        $config = file_exists($am_config_file) ? json_decode(file_get_contents($am_config_file), true) : ['order' => []];
        $runtime = am_get_runtime_info();
        $ips = [
            'tailscale' => am_get_tailscale_ip(),
            'tailscale_domain' => am_get_tailscale_domain(),
            'lan' => am_get_lan_ip()
        ];
        
        echo json_encode(['status' => 'success', 'apps' => $apps, 'config' => $config, 'ips' => $ips, 'runtime' => $runtime]);
        exit;
    }

    // B. SAVE CONFIG (Layout + Exclusions + Protection)
    if ($_POST['plugin_action'] === 'am_save_layout' || $_POST['plugin_action'] === 'am_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $current = file_exists($am_config_file) ? json_decode(file_get_contents($am_config_file), true) : [];
        
        // sui.api sends these as arrays already, but we handle both for safety
        if (isset($_POST['order'])) {
            $current['order'] = is_array($_POST['order']) ? $_POST['order'] : json_decode($_POST['order'], true);
        }
        if (isset($_POST['folders'])) {
            $current['folders'] = is_array($_POST['folders']) ? $_POST['folders'] : json_decode($_POST['folders'], true);
        }
        if (isset($_POST['dock'])) {
            $current['dock'] = is_array($_POST['dock']) ? $_POST['dock'] : json_decode($_POST['dock'], true);
        }
        if (isset($_POST['excluded'])) {
            $current['excluded'] = is_array($_POST['excluded']) ? $_POST['excluded'] : json_decode($_POST['excluded'], true);
        }
        if (isset($_POST['protected'])) {
            $current['protected'] = is_array($_POST['protected']) ? $_POST['protected'] : json_decode($_POST['protected'], true);
        }
        if (isset($_POST['push_offset'])) {
            $current['push_offset'] = (int)$_POST['push_offset'];
        }
        
        file_put_contents($am_config_file, json_encode($current, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // C. CHECK FOR CHANGES (Live Reload)
    if ($_POST['plugin_action'] === 'am_check_changes') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $id = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['id']);
        $path = $am_root . '/' . $id;
        $hashStr = "";
        if (is_dir($path)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, ['php', 'js', 'css'])) {
                    $hashStr .= $file->getMTime();
                }
            }
        }
        echo json_encode(['status' => 'success', 'hash' => md5($hashStr)]);
        exit;
    }

    // D. DELETE APP
    if ($_POST['plugin_action'] === 'am_delete_app') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $id = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['id']);
        $path = $am_root . '/' . $id;
        if (is_dir($path)) {
            // Recursive delete helper
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $file) {
                if ($file->isDir()) rmdir($file->getRealPath());
                else unlink($file->getRealPath());
            }
            rmdir($path);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Folder not found']);
        }
        exit;
    }
}

// D. STANDALONE LAUNCHER (GET)
if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'am_launcher') {
    while (ob_get_level()) ob_end_clean();
    
    $folders = array_filter(glob($am_root . '/*'), 'is_dir');
    $apps = [];
    foreach ($folders as $folder) {
        $id = basename($folder);
        $manifestPath = $folder . '/manifest.json';
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $apps[] = [
                'id' => $id,
                'name' => $manifest['name'] ?? $id,
                'icon' => $manifest['icon'] ?? '📦',
                'color' => $manifest['color'] ?? '#5856D6',
                'path' => 'apps/' . $id . '/index.php'
            ];
        }
    }
    
    $config = file_exists($am_config_file) ? json_decode(file_get_contents($am_config_file), true) : ['order' => []];
    if (!empty($config['order'])) {
        usort($apps, function($a, $b) use ($config) {
            $idxA = array_search($a['id'], $config['order']);
            $idxB = array_search($b['id'], $config['order']);
            return ($idxA === false ? 999 : $idxA) - ($idxB === false ? 999 : $idxB);
        });
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
        <title>App Portal</title>
        <style>
            :root {
                /* LIGHT THEME (Default) */
                --bg: #f4f4f5;
                --card-bg: rgba(0, 0, 0, 0.03);
                --card-border: rgba(0, 0, 0, 0.08);
                --text-main: #09090b;
                --text-dim: #71717a;
                --accent: #5856D6;
                --glass: rgba(0, 0, 0, 0.02);
                --sheet-bg: #ffffff;
                --btn-bg: rgba(0, 0, 0, 0.05);
                --toast-bg: #18181b;
                --toast-text: #ffffff;
                --grad-1: rgba(88, 86, 214, 0.08);
                --grad-2: rgba(255, 45, 85, 0.05);
                --shadow-icon: 0 10px 25px rgba(0, 0, 0, 0.06), 0 4px 10px rgba(0, 0, 0, 0.04);
                --shadow-splash: 0 30px 60px rgba(0, 0, 0, 0.12);
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    /* DARK THEME */
                    --bg: #09090b;
                    --shadow-icon: 0 12px 30px rgba(0, 0, 0, 0.4), inset 0 -4px 8px rgba(0, 0, 0, 0.1);
                    --shadow-splash: 0 30px 100px rgba(0, 0, 0, 0.9), 0 0 50px var(--accent);
                    --card-bg: rgba(255, 255, 255, 0.03);
                    --card-border: rgba(255, 255, 255, 0.08);
                    --text-main: #fafafa;
                    --text-dim: #a1a1aa;
                    --glass: rgba(255, 255, 255, 0.02);
                    --sheet-bg: #1c1c1e;
                    --btn-bg: rgba(255, 255, 255, 0.05);
                    --toast-bg: #ffffff;
                    --toast-text: #000000;
                    --grad-1: rgba(88, 86, 214, 0.15);
                    --grad-2: rgba(255, 45, 85, 0.1);
                }
            }

            body { 
                margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; 
                background: var(--bg); color: var(--text-main); 
                min-height: 100vh; /* Fallback */
                min-height: 100dvh; /* Dynamic Viewport Height */
                display: flex; flex-direction: column; align-items: center;
                background-image: 
                    radial-gradient(circle at 0% 0%, var(--grad-1) 0%, transparent 50%),
                    radial-gradient(circle at 100% 100%, var(--grad-2) 0%, transparent 50%);
                background-attachment: fixed;
                overflow-x: hidden;
                overflow-anchor: none; /* Prevent browser from 'correcting' scroll during animations */
                -webkit-tap-highlight-color: transparent;
            }
            
            .container {
                width: 100%; max-width: 800px; 
                padding: 60px 24px; 
                /* Respect mobile safe areas (home indicators/notches) */
                padding-bottom: calc(60px + env(safe-area-inset-bottom));
                box-sizing: border-box;
                animation: amFadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            }

            @keyframes amFadeUp {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .header { margin-bottom: 50px; text-align: center; }
            .header h1 { margin: 0; font-size: 36px; font-weight: 900; letter-spacing: -1px; background: linear-gradient(to bottom, var(--text-main), var(--text-dim)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
            .header p { color: var(--text-dim); font-size: 12px; margin-top: 10px; text-transform: uppercase; letter-spacing: 3px; font-weight: 700; }
            
            .grid { 
                display: grid; 
                grid-template-columns: repeat(auto-fill, minmax(85px, 1fr)); 
                gap: 32px 20px; 
                width: 100%;
            }

            .app-item {
                display: flex; flex-direction: column; align-items: center; gap: 12px;
                text-decoration: none; color: inherit; position: relative;
                cursor: pointer; transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
                -webkit-user-select: none;
                user-select: none;
                -webkit-touch-callout: none;
            }
            .app-item:active { transform: scale(0.9); }

            .icon-box { 
                width: 72px; height: 72px; border-radius: 18px; 
                display: flex; align-items: center; justify-content: center; 
                font-size: 36px; position: relative;
                background: #fff;
                box-shadow: var(--shadow-icon);
                border: 1px solid var(--card-border);
            }
            .icon-box > svg {
                width: 100% !important;
                height: 100% !important;
                display: block;
            }
            .icon-box:has(svg) {
                background: transparent !important;
                border: none !important;
                box-shadow: none !important;
            }
            .icon-box:has(svg)::after {
                display: none;
            }
            /* Reflection effect */
            .icon-box::after {
                content: ''; position: absolute; top: 100%; left: 10%; right: 10%; height: 20px;
                background: inherit; filter: blur(15px); opacity: 0.3; z-index: -1;
            }

            .app-name { 
                font-size: 12px; font-weight: 600; text-align: center; 
                color: var(--text-main); width: 100%;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
                transition: opacity 0.3s ease;
            }

            /* LAUNCHING STATE */
            body.is-launching .app-item:not(.active-target) {
                opacity: 0;
                transform: scale(0.5);
                pointer-events: none;
            }
            body.is-launching .header, body.is-launching .footer {
                opacity: 0;
                filter: blur(10px);
                transform: translateY(-20px);
            }
            
            /* Premium Expo-Out Easing (Fast start, long smooth glide) */
            .app-item { transition: all 0.8s cubic-bezier(0.19, 1, 0.22, 1); }
            
            /* SPLASH ANIMATION */
            body.is-launching .app-item.active-target { 
                transform: translate(var(--tx, 0), var(--ty, 0)) scale(3); 
                z-index: 2000; 
            }
            body.is-launching .app-item.active-target .icon-box { 
                box-shadow: var(--shadow-splash);
                border-color: rgba(255,255,255,0.6);
            }
            body.is-launching .app-item.active-target .app-name { 
                opacity: 1;
                /* Remove truncation constraints during splash */
                white-space: nowrap;
                overflow: visible;
                text-overflow: clip;
                width: auto;
                
                transform: translateY(12px) scale(0.4); 
                font-size: 32px;
                font-weight: 900;
                letter-spacing: -0.5px;
                background: linear-gradient(to bottom, var(--text-main), var(--text-dim));
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }

            /* ACTION SHEET */
            .overlay {
                position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0); 
                backdrop-filter: blur(0px);
                -webkit-backdrop-filter: blur(0px);
                z-index: 1000; 
                display: flex; align-items: flex-end; justify-content: center;
                pointer-events: none;
                visibility: hidden;
                transition: background 0.4s ease, backdrop-filter 0.4s ease, visibility 0.4s;
            }
            .overlay.active { 
                background: rgba(0,0,0,0.6); 
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                pointer-events: auto;
                visibility: visible;
            }

            .sheet {
                background: var(--sheet-bg); width: 100%; max-width: 440px;
                border-radius: 32px 32px 0 0; padding: 24px 16px;
                padding-bottom: calc(24px + env(safe-area-inset-bottom));
                transform: translateY(100%); 
                transition: transform 0.5s cubic-bezier(0.32, 0.72, 0, 1);
                box-sizing: border-box;
                position: relative;
                box-shadow: 0 -10px 40px rgba(0,0,0,0.3);
            }
            /* Grab Handle */
            .sheet::before {
                content: ''; position: absolute; top: 10px; left: 50%; 
                transform: translateX(-50%); width: 36px; height: 5px; 
                background: var(--btn-bg); border-radius: 10px;
            }
            .overlay.active .sheet { transform: translateY(0); }

            @media (min-width: 600px) {
                .overlay { align-items: center; padding: 20px; }
                .sheet { 
                    border-radius: 28px; 
                    transform: scale(0.9) translateY(30px); 
                    opacity: 0; 
                    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease;
                    padding-bottom: 24px;
                }
                .sheet::before { display: none; }
                .overlay.active .sheet { transform: scale(1) translateY(0); opacity: 1; }
            }

            .sheet-title { font-size: 11px; font-weight: 900; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; text-align: center; }
            
            .sheet-btn {
                width: 100%; padding: 16px; background: var(--btn-bg);
                border: none; border-radius: 14px; margin-bottom: 8px;
                font-size: 16px; font-weight: 600; color: var(--text-main);
                display: flex; align-items: center; gap: 14px; cursor: pointer;
            }
            .sheet-btn:active { background: rgba(128,128,128,0.1); }
            .sheet-btn.cancel { background: transparent; margin-top: 8px; justify-content: center; color: var(--text-dim); }

            @media (min-width: 600px) {
                .sheet { border-radius: 24px; margin-bottom: 40px; transform: translateY(20px) scale(0.95); opacity: 0; }
                .overlay.active .sheet { transform: translateY(0) scale(1); opacity: 1; }
            }

            .toast {
    position: fixed; bottom: 40px; background: var(--toast-bg); color: var(--toast-text);
    padding: 10px 20px; border-radius: 30px; font-weight: 700; font-size: 13px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2); z-index: 2000;
    transform: translateY(100px); transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.toast.active { transform: translateY(0); }
.sheet-section-title { 
    font-size: 10px; font-weight: 800; color: var(--text-dim); 
    text-transform: uppercase; letter-spacing: 1px; 
    margin: 18px 0 8px 4px; text-align: left; opacity: 0.8;
    border-bottom: 1px solid var(--card-border);
    padding-bottom: 4px;
}
        </style></head>
    <body>
        <div class="container">
            <div class="header">
                <h1>App Portal</h1>
                <p>Standalone Launcher</p>
            </div>
            
            <div class="grid">
    <?php foreach ($apps as $app): ?>
        <div class="app-item" 
             onclick="amLaunch('<?php echo $app['path']; ?>', this)"
             oncontextmenu="amMenu(event, <?php echo htmlspecialchars(json_encode($app)); ?>)"
             data-app-id="<?php echo $app['id']; ?>"><div class="icon-box" style="background: <?php echo $app['color']; ?>;">
                            <?php echo $app['icon']; ?>
                        </div>
                        <div class="app-name"><?php echo $app['name']; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="menu-overlay" class="overlay" onclick="if(event.target===this) amCloseMenu()">
            <div class="sheet">
                <div id="menu-title" class="sheet-title">App Options</div>
                <div id="menu-options"></div>
                <button class="sheet-btn cancel" onclick="amCloseMenu()">Cancel</button>
            </div>
        </div>

        <div id="toast" class="toast">URL Copied</div>

        <script>
            // Fix "Creeping Scroll" on refresh
            if ('scrollRestoration' in history) {
                history.scrollRestoration = 'manual';
            }
            window.scrollTo(0, 0);

            // Silent client-side Tailscale HTTPS auto-discovery
            if (window.location.hostname.indexOf('.ts.net') !== -1 && window.location.protocol === 'https:') {
                const reportUrl = 'index.php?plugin_action=am_report_domain&domain=' + encodeURIComponent(window.location.host);
                fetch(reportUrl).catch(() => {});
            }
            
            // Client-side non-SSL port reporting
            if (window.location.protocol === 'http:') {
                const port = window.location.port || '80';
                fetch('index.php?plugin_action=am_report_port&port=' + port).catch(() => {});
            }

            window.CJOS_AM_CONFIG = <?php echo json_encode($config); ?>;
            window.CJOS_RUNTIME = <?php echo json_encode(am_get_runtime_info()); ?>;

            window.CJOS_IPS = {
                tailscale: <?php echo json_encode(am_get_tailscale_ip()); ?>,
                tailscale_domain: <?php echo json_encode(am_get_tailscale_domain()); ?>,
                lan: <?php echo json_encode(am_get_lan_ip()); ?>
            };

            let activeApp = null;
            let lpTimer = null;

            // Long Press Detection for Mobile
            document.querySelectorAll('.app-item').forEach(el => {
                el.addEventListener('touchstart', (e) => {
                    const app = JSON.parse(el.getAttribute('oncontextmenu').match(/amMenu\(event, (.*)\)/)[1]);
                    lpTimer = setTimeout(() => {
                        if (window.navigator.vibrate) navigator.vibrate(15);
                        amMenu(e, app);
                    }, 600);
                }, {passive: false});
                el.addEventListener('touchend', () => clearTimeout(lpTimer));
                el.addEventListener('touchmove', () => clearTimeout(lpTimer));
            });

            // Handle "Back" button from browser history
            window.addEventListener('pageshow', (event) => {
                // Reset everything if we are coming back from a cached state (Back button)
                document.body.classList.remove('is-launching');
                document.querySelectorAll('.app-item').forEach(el => {
                    el.classList.remove('active-target');
                    el.style.removeProperty('--tx');
                    el.style.removeProperty('--ty');
                });
                amCloseMenu();
            });

            function amLaunch(path, el = null) {
                if (document.body.classList.contains('is-launching')) return;

                let target = el;
                if (!target) {
                    const appId = activeApp ? activeApp.id : null;
                    if (appId) target = document.querySelector(`.app-item[data-app-id="${appId}"]`);
                }

                if (target) {
                    // 1. Calculate Centering Math
                    const rect = target.getBoundingClientRect();
                    const centerX = window.innerWidth / 2;
                    const centerY = window.innerHeight / 2;
                    const elCenterX = rect.left + rect.width / 2;
                    const elCenterY = rect.top + rect.height / 2;
                    
                    // Set CSS variables for the transform
                    target.style.setProperty('--tx', `${centerX - elCenterX}px`);
                    target.style.setProperty('--ty', `${centerY - elCenterY}px`);
                    target.classList.add('active-target');
                }

                document.body.classList.add('is-launching');
                amCloseMenu();

                // 2. Splash Delay: Wait for the "Center Stage" animation to complete
                setTimeout(() => {
                    window.location.href = "../../" + path;
                }, 850);
            }

            function amGetAlternativeUrls(appPath) {
                const runtime = window.CJOS_RUNTIME || {};
                const isRuntime = !!runtime.is_runtime || (typeof window.RuntimeBridge !== 'undefined');
                
                const baseDir = window.location.pathname.split('index.php')[0];
                const rawPath = baseDir + appPath;
                const cleanParts = [];
                for (let p of rawPath.split('/')) {
                    if (p === '..') cleanParts.pop();
                    else if (p !== '.' && p !== '') cleanParts.push(p);
                }
                const resolvedPath = '/' + cleanParts.join('/');

                const urls = [];
                const addedUrls = new Set();

                const addUrl = (label, url) => {
                    if (url && !addedUrls.has(url)) {
                        addedUrls.add(url);
                        urls.push({ label, url });
                    }
                };

                // 1. Current Visiting Host (Always Included First)
                addUrl(`Current Host (${window.location.host})`, window.location.origin + resolvedPath);

                // 2. Localhost & 127.0.0.1 Links
                const currentPort = window.location.port ? `:${window.location.port}` : '';
                const currentProto = window.location.protocol;
                addUrl(`Localhost (127.0.0.1${currentPort})`, `${currentProto}//127.0.0.1${currentPort}${resolvedPath}`);
                addUrl(`Localhost (localhost${currentPort})`, `${currentProto}//localhost${currentPort}${resolvedPath}`);

                // 3. mDNS Links for Runtime
                if (isRuntime) {
                    const httpPortStr = (runtime.http_port && runtime.http_port !== 80) ? `:${runtime.http_port}` : '';
                    const httpsPortStr = (runtime.https_port && runtime.https_port !== 443) ? `:${runtime.https_port}` : '';
                    const mdnsHost = runtime.mdns_host || 'conjure.local';

                    addUrl(`mDNS HTTP (${mdnsHost}${httpPortStr})`, `http://${mdnsHost}${httpPortStr}${resolvedPath}`);
                    addUrl(`mDNS HTTPS (${mdnsHost}${httpsPortStr})`, `https://${mdnsHost}${httpsPortStr}${resolvedPath}`);
                }

                // 4. Local LAN IP
                const lanIp = window.CJOS_IPS && window.CJOS_IPS.lan;
                if (lanIp) {
                    addUrl(`LAN IP (${lanIp}${currentPort})`, `${currentProto}//${lanIp}${currentPort}${resolvedPath}`);
                }

                // 5. Tailscale Domain
                const tsDomain = (runtime.tailscale_domain) || (window.CJOS_IPS && window.CJOS_IPS.tailscale_domain);
                if (tsDomain) {
                    let cleanDomain = tsDomain.replace(/^https?:\/\//, '');
                    if (!cleanDomain.includes(':')) {
                        if (runtime.https_port && runtime.https_port !== 443) {
                            cleanDomain += `:${runtime.https_port}`;
                        } else if (window.location.port && window.location.port !== '80' && window.location.port !== '443') {
                            cleanDomain += `:${window.location.port}`;
                        }
                    }
                    addUrl(`Tailscale HTTPS (${cleanDomain})`, `https://${cleanDomain}${resolvedPath}`);
                }
                
                return urls;
            }

            function amMenu(e, app) {
                if (e) e.preventDefault();
                activeApp = app;
                const overlay = document.getElementById('menu-overlay');
                const options = document.getElementById('menu-options');
                document.getElementById('menu-title').innerText = app.name;

                options.innerHTML = `
                    <div class="sheet-section-title" style="margin-top: 0;">Execution</div>
                    <button class="sheet-btn" onclick="amLaunch('${app.path}')">✨ Open App</button>
                    <button class="sheet-btn" onclick="window.open('../../${app.path}', '_blank')">🚀 Open in New Tab</button>
                    <button class="sheet-btn" onclick="window.open('../../${app.path}', 'AppWindow', 'width=500,height=800')">🪟 Open in New Window</button>
                    
                    <div class="sheet-section-title">Sharing</div>
                    <button class="sheet-btn" onclick="amShowCopyOptions('${app.path}', '${app.name.replace(/'/g, "\\'")}')">🔗 Copy App URL</button>
                    
                    <div class="sheet-section-title">Developer Tools</div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-bottom:8px;">
                        <button class="sheet-btn" style="margin-bottom:0; font-size:13px;" onclick="window.location.href='index.php?plugin_action=ce_download_app_source&mode=full&folder=${app.id}'">📄 Full Source</button>
                        <button class="sheet-btn" style="margin-bottom:0; font-size:13px;" onclick="window.location.href='index.php?plugin_action=ce_download_app_source&mode=clean&folder=${app.id}'">🧹 Clean Source</button>
                    </div>
                    
                    <div class="sheet-section-title">Packaging & Export</div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-bottom:8px;">
                        <button class="sheet-btn" style="margin-bottom:0; font-size:13px;" onclick="window.location.href='index.php?plugin_action=am_export_zip&mode=full&id=${app.id}'">📦 Full ZIP</button>
                        <button class="sheet-btn" style="margin-bottom:0; font-size:13px;" onclick="window.location.href='index.php?plugin_action=am_export_zip&mode=clean&id=${app.id}'">🧹 Clean ZIP</button>
                    </div>
                    
                    <div class="sheet-section-title">Danger Zone</div>
                    <button class="sheet-btn" style="color:#FF3B30; margin-top:4px; border:1px solid rgba(255,59,48,0.2); background:rgba(255,59,48,0.05);" onclick="amDeleteApp('${app.id}')">🗑️ Delete App</button>
                `;

                overlay.classList.add('active');
            }

            function amShowCopyOptions(appPath, appName) {
                const options = document.getElementById('menu-options');
                const title = document.getElementById('menu-title');
                title.innerText = "Copy URL: " + appName;
                
                const urls = amGetAlternativeUrls(appPath);
                let html = '';
                urls.forEach(opt => {
                    const escapedUrl = opt.url.replace(/'/g, "\\'");
                    html += `<button class="sheet-btn" style="font-size:14px; padding:12px 16px;" onclick="amCopy('${escapUrl}')">📋 ${opt.label}</button>`;
                });
                
                html += `<button class="sheet-btn cancel" onclick="amRestoreMainMenu()">&larr; Back</button>`;
                options.innerHTML = html;
            }

            function amRestoreMainMenu() {
                if (activeApp) {
                    amMenu(null, activeApp);
                }
            }

            function amDeleteApp(id) {
                if (!confirm(`Permanently delete "${id}"? \n\nIMPORTANT: Please ensure you have downloaded a Full Export ZIP before proceeding.`)) return;
                
                const fd = new FormData();
                fd.append('plugin_action', 'am_delete_app');
                fd.append('id', id);

                fetch('index.php', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            location.reload();
                        } else {
                            alert("Error: " + data.message);
                        }
                    });
            }

            function amCloseMenu() {
                document.getElementById('menu-overlay').classList.remove('active');
            }

            function amCopy(text) {
                navigator.clipboard.writeText(text).then(() => {
                    amCloseMenu();
                    const t = document.getElementById('toast');
                    t.classList.add('active');
                    setTimeout(() => t.classList.remove('active'), 2000);
                });
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// E. ZIP EXPORT (GET)
if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'am_export_zip') {
    $id = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id']);
    $mode = $_GET['mode'] ?? 'clean'; // Default to clean for safety
    $path = $am_root . '/' . $id;
    
    if (is_dir($path)) {
        $zipName = $id . '_' . ucfirst($mode) . '_Export.zip';
        $zipPath = sys_get_temp_dir() . '/' . $zipName;
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($path) + 1);
                    
                    if ($mode === 'clean') {
                        // SECURITY: Exclude any private secrets from the export
                        if (strpos(basename($filePath), '-private.json') !== false) continue;
                        // ISOLATION: Exclude local databases and journals from distributable ZIPs
                        if (pathinfo($filePath, PATHINFO_EXTENSION) === 'db' || strpos($filePath, '-journal') !== false) continue;
                    }
                    
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();
            
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.$zipName.'"');
            readfile($zipPath);
            unlink($zipPath);
            exit;
        }
    }
    die("Export failed.");
}

// --- 2. PAGE VIEW ---
$am_page = <<<'HTML'
<div class="scroll-view" id="app-maker-view">
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
        <div class="page-title" style="margin-bottom:0; padding-top:0;">Apps</div>
        <div style="display:flex; gap:8px;">
            <button id="am-settings-btn" onclick="amOpenSettings()" class="icon-btn" style="background:var(--btn-bg); width:34px; height:34px; border-radius:50%; color:var(--text-primary); display:flex; align-items:center; justify-content:center; padding:0; border:none; outline:none;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px; height:16px;"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg></button>
            <button id="am-edit-btn" onclick="amToggleEdit()" class="icon-btn" style="background:var(--btn-bg); width:34px; height:34px; border-radius:50%; color:var(--text-primary); display:flex; align-items:center; justify-content:center; padding:0; border:none; outline:none;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px; height:16px;"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg></button>
            <button id="am-refresh-grid-btn" onclick="amRefreshGrid(this)" class="icon-btn" style="background:var(--btn-bg); width:34px; height:34px; border-radius:50%; color:var(--text-primary); display:flex; align-items:center; justify-content:center; padding:0; border:none; outline:none; transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px; height:16px;"><path d="M23 4v6h-6"></path><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg></button>
        </div>
    </div>

    <!-- DOCK SECTION -->
    <div id="am-dock-section" style="margin-bottom: 32px; padding: 16px; background: rgba(128,128,128,0.04); border: 1px dashed var(--border-color); border-radius: 20px; display: none;">
        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 16px; text-align: center;">Dock Items</div>
        <div id="am-dock-grid" style="
            display: grid; 
            grid-template-columns: repeat(4, minmax(0, 1fr)); 
            gap: 24px 10px;
        "></div>
    </div>

    <!-- APP GRID -->
    <div id="am-grid" style="
        display: grid; 
        grid-template-columns: repeat(4, minmax(0, 1fr)); 
        gap: 24px 10px;
        padding-top: 12px;
        padding-bottom: 120px;
    "></div>
</div>

<style>
    @keyframes amJiggle { 
        0% { transform: rotate(-1deg); } 
        50% { transform: rotate(1deg); } 
        100% { transform: rotate(-1deg); } 
    }
    .am-icon-wrap { 
        display: flex; flex-direction: column; align-items: center; gap: 8px; 
        cursor: pointer; position: relative; transition: transform 0.2s; 
        -webkit-user-select: none;
        user-select: none;
        -webkit-touch-callout: none;
    }
    .am-icon-wrap:active { transform: scale(0.9); }
    .am-icon-container {
        position: relative;
        width: 64px;
        height: 64px;
    }
    .am-icon { 
        width: 64px; height: 64px; border-radius: 16px; 
        display: flex; align-items: center; justify-content: center; 
        font-size: 32px; box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        background: white; border: 1px solid rgba(0,0,0,0.05);
    }
    .am-icon > svg {
        width: 100% !important;
        height: 100% !important;
        display: block;
    }
    .am-icon:has(svg) {
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
    }
    .am-header-icon-wrap svg {
        width: 100% !important;
        height: 100% !important;
        display: block;
    }
    .am-label { 
        font-size: 11px; font-weight: 600; color: var(--text-primary); 
        text-align: center; width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; 
    }
    
    body.am-jiggle .am-icon-wrap { animation: amJiggle 0.3s infinite ease-in-out; cursor: grab; }
    .am-delete-badge { 
        position: absolute; top: -8px; left: -8px; width: 22px; height: 22px; 
        background: #FF3B30; color: white; border-radius: 50%; 
        display: none; align-items: center; justify-content: center; 
        font-weight: 800; font-size: 14px; border: 2px solid white; z-index: 5;
    }
    body.am-jiggle .am-delete-badge { display: flex; }

    /* Viewport Lock during Edit */
    body.am-jiggle .horizontal-viewport { overflow: hidden !important; touch-action: none !important; }
    body.am-jiggle .page-view { overflow: hidden !important; }
    body.am-jiggle #am-grid { touch-action: none; }
    
    .am-icon-wrap.dragging { opacity: 0.5; transform: scale(1.1); cursor: grabbing; z-index: 100; }
    .am-icon-wrap.drag-over .am-icon { outline: 3px dashed var(--primary); outline-offset: 2px; }
    .am-icon-wrap.drag-over-folder .am-icon { outline: 3px dashed #34C759; outline-offset: 2px; }
    .am-icon-wrap.insert-before::before { content: ''; position: absolute; left: -7.5px; top: -16px; bottom: -16px; width: 5px; background: var(--primary); border-radius: 2.5px; z-index: 10; box-shadow: 0 0 8px rgba(0,0,0,0.2); }
    .am-icon-wrap.insert-after::after { content: ''; position: absolute; right: -7.5px; top: -16px; bottom: -16px; width: 5px; background: var(--primary); border-radius: 2.5px; z-index: 10; box-shadow: 0 0 8px rgba(0,0,0,0.2); }

    /* Respect Safe Zone only when Floating Command Bar is active */
    body.fcb-mode .am-dynamic-app-page iframe {
        /* Use user-defined offset, falling back to system safe zone */
        margin-bottom: var(--am-push-offset, var(--fr-sz-h, 0px));
        
        /* High-Fidelity Feathering: Multi-stop eased gradient eliminates the "hard line" at the transition */
        -webkit-mask-image: linear-gradient(
            to bottom, 
            black 0%, 
            black calc(100% - 80px), 
            rgba(0,0,0,0.8) calc(100% - 50px), 
            rgba(0,0,0,0.4) calc(100% - 25px), 
            rgba(0,0,0,0.1) calc(100% - 10px), 
            transparent 100%
        );
        mask-image: linear-gradient(
            to bottom, 
            black 0%, 
            black calc(100% - 80px), 
            rgba(0,0,0,0.8) calc(100% - 50px), 
            rgba(0,0,0,0.4) calc(100% - 25px), 
            rgba(0,0,0,0.1) calc(100% - 10px), 
            transparent 100%
        );
        
        /* Ensure smooth rendering of the mask */
        -webkit-backface-visibility: hidden;
        backface-visibility: hidden;
    }

    /* Ensure the container background matches the fade target */
    .am-dynamic-app-page {
        background: var(--bg-color) !important;
    }
</style>
HTML;

$plugin_pages[] = $am_page;

$plugin_tools[] = [
    'name' => 'App Maker',
    'desc' => 'Micro-app grid',
    'sui_icon' => 'grid',
    'color' => 'rgba(142, 142, 147, 0.12)',
    'icon_color' => 'var(--text-primary)',
    'action' => "dashNavToPage('app-maker-view')",
    'linked_page' => 'app-maker-view'
];

if(!isset($plugin_overlays)) $plugin_overlays = [];

$plugin_overlays[] = <<<'HTML'
<div id="am-folder-overlay" class="shared-menu-overlay" onclick="if(event.target===this) amCloseFolder()" style="z-index:9400; background:rgba(0,0,0,0.4); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); display:none; align-items:center; justify-content:center;">
    <div id="am-folder-sheet" style="transform:scale(0.9); opacity:0; transition:all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); width:88%; max-width:340px; background:var(--bg-color); border:1px solid var(--border-color); border-radius:32px; padding:28px 24px; display:flex; flex-direction:column; max-height:70vh; box-shadow:0 20px 50px rgba(0,0,0,0.15); pointer-events:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <div id="am-folder-title" style="font-size:24px; font-weight:800; color:var(--text-primary);">Folder</div>
            <button onclick="amCloseFolder()" style="background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--text-primary); display:flex; align-items:center; justify-content:center; cursor:pointer;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px;height:16px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div id="am-folder-grid" style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:24px 10px; overflow-y:auto; padding-top:12px; padding-bottom:20px;"></div>
    </div>
</div>
HTML;

$plugin_overlays[] = <<<'HTML'
<!-- APP SETTINGS OVERLAY --><div id="am-settings-overlay" class="shared-menu-overlay" style="z-index:9500;">
    <div id="am-settings-sheet" class="shared-bottom-sheet" style="height:70vh;">
        <div style="padding:20px 24px; background:var(--bg-color); border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
            <div style="font-size:18px; font-weight:700; color:var(--text-primary);">App Maker Settings</div>
            <button onclick="amCloseSettings()" style="background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--text-primary); display:flex; align-items:center; justify-content:center; cursor:pointer;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div style="flex:1; overflow-y:auto; padding:24px;">
            <div style="margin-bottom:24px; padding-bottom:24px; border-bottom:1px solid var(--border-color);">
                <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">Interface Customization</div>
                <div class="setting-item vertical" style="padding:0; border:none; background:none;">
                    <label class="setting-label" style="font-size:13px;">Bottom Clearance (Command Bar)</label>
                    <div class="setting-desc">Adjust how much the app is pushed up to avoid the Command Bar. 0 = Full Screen.</div>
                    <div style="display:flex; align-items:center; gap:12px; margin-top:10px;">
                        <input type="range" id="am-push-offset-slider" min="0" max="150" step="2" oninput="amUpdatePushOffset(this.value)" onchange="amSaveSettingsGlobal()" style="flex:1;">
                        <span id="am-push-offset-val" style="font-weight:700; color:var(--primary); min-width:40px;">80px</span>
                    </div>
                </div>
            </div>

            <div style="margin-bottom:24px; padding-bottom:24px; border-bottom:1px solid var(--border-color);">
                <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">Standalone Access</div>
                <a href="index.php?plugin_action=am_launcher" target="_blank" style="
                    display:flex; align-items:center; justify-content:center; gap:10px;
                    background:var(--primary); color:white; text-decoration:none;
                    padding:14px; border-radius:14px; font-weight:700; font-size:14px;
                    box-shadow: 0 4px 12px rgba(0,122,255,0.2);
                ">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                    Open Standalone Launcher
                </a>
                <p style="font-size:12px; color:var(--text-secondary); margin-top:12px; line-height:1.4;">Open a dedicated, full-screen portal to launch your apps or export them as ZIPs without the Conjure interface.</p>
            </div>

            <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">Export Context Management</div>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px; line-height:1.4;">Select which apps are included in the "Export Source Code" (.txt) file. Turn apps off to give the AI a cleaner, more focused context.</p>
            <div id="am-context-list" style="display:flex; flex-direction:column; gap:10px;"></div>
        </div>
    </div>
</div>
HTML;

// --- 3. JS LOGIC ---
$plugin_js .= <<<'JS'
// --- APP MAKER ENGINE ---

const pencilSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px; height:16px;"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>`;
const checkSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px; height:16px;"><polyline points="20 6 9 17 4 12"></polyline></svg>`;

let amApps = [];
let amIsEditing = false;

const findClosestAppItem = (clientX, clientY) => {
    const items = Array.from(document.querySelectorAll('#am-grid .am-icon-wrap, #am-dock-grid .am-icon-wrap, #am-folder-grid .am-icon-wrap'));
    let closest = null;
    let minDist = Infinity;
    items.forEach(el => {
        if (el.classList.contains('dragging')) return;
        const rect = el.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;
        const dist = Math.pow(clientX - cx, 2) + Math.pow(clientY - cy, 2);
        if (dist < minDist) {
            minDist = dist;
            closest = el;
        }
    });
    return minDist < 22500 ? closest : null;
};
let amFolders = {};
let amOrder = [];
let amDock = [];
let amExcludedIds = [];
let amProtectedIds = [];
let amPushOffset = 80;
let amIps = {};
let amConfig = {};

let amLiveReloadInterval = null;
let amLastAppHash = null;
let amLiveReloadActiveAppId = null;
let amOpenFolderId = null;

function amStartLiveReload() {
    if (!amLiveReloadActiveAppId || document.visibilityState !== "visible") return;
    if (amLiveReloadInterval) clearInterval(amLiveReloadInterval);
    
    amLiveReloadInterval = setInterval(() => {
        if (!amLiveReloadActiveAppId || document.visibilityState !== "visible") {
            amStopLiveReload();
            return;
        }
        amPerformLiveCheck(amLiveReloadActiveAppId);
    }, 2000);
}

function amStopLiveReload() {
    if (amLiveReloadInterval) {
        clearInterval(amLiveReloadInterval);
        amLiveReloadInterval = null;
    }
}

document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") {
        if (amLiveReloadActiveAppId) amStartLiveReload();
    } else {
        amStopLiveReload();
    }
});

function amInit() {
    amFetchApps();
    
    // Silent client-side Tailscale HTTPS auto-discovery
    if (window.location.hostname.indexOf('.ts.net') !== -1 && window.location.protocol === 'https:') {
        const reportUrl = 'index.php?plugin_action=am_report_domain&domain=' + encodeURIComponent(window.location.host);
        fetch(reportUrl).catch(() => {});
    }
    
    // Client-side non-SSL port reporting
    if (window.location.protocol === 'http:') {
        const port = window.location.port || '80';
        fetch('index.php?plugin_action=am_report_port&port=' + port).catch(() => {});
    }
}

// Listen for Lazy Hydration
window.addEventListener('cjos-hydrated', (e) => {
    if (e.detail.id === 'app-maker-view') {
        amInit();
    }
});

async function amFetchApps() {
    try {
        const data = await window.sui.api('am_get_apps', {}, { toast: false });
        if (data) {
            amApps = data.apps;
            amExcludedIds = data.config.excluded || [];
            amProtectedIds = data.config.protected || [];
            amPushOffset = data.config.push_offset ?? 80;
            amIps = data.ips || {};
            amConfig = data.config || {};
            if (data.runtime) window.CJOS_RUNTIME = data.runtime;
            amFolders = data.config.folders || {};
            amOrder = data.config.order || [];
            amDock = data.config.dock || [];
            
            // Apply initial offset
            document.documentElement.style.setProperty('--am-push-offset', amPushOffset + 'px');
            
            const allAppIds = amApps.map(a => a.id);
const seenIds = new Set();

// 1. Clean and track Dock items first (Dock has layout priority)
amDock = amDock.filter(id => {
    if (id.startsWith('folder_')) {
        if (amFolders[id] && !seenIds.has(id)) {
            seenIds.add(id);
            return true;
        }
    } else {
        if (allAppIds.includes(id) && !seenIds.has(id)) {
            seenIds.add(id);
            return true;
        }
    }
    return false;
});
                    
// 2. Clean and track folders next
for (let fId in amFolders) {
    if (seenIds.has(fId)) continue;

    amFolders[fId].apps = amFolders[fId].apps.filter(id => {
        if (allAppIds.includes(id) && !seenIds.has(id)) {
            seenIds.add(id);
            return true;
        }
        return false;
    });
    if (amFolders[fId].apps.length === 0) {
        delete amFolders[fId];
    }
}
                    
// 3. Clean main grid order
amOrder = amOrder.filter(id => {
    if (id.startsWith('folder_')) {
        return !!amFolders[id] && !seenIds.has(id);
    } else {
        if (allAppIds.includes(id) && !seenIds.has(id)) {
            seenIds.add(id);
            return true;
        }
        return false;
    }
});
                    
// 4. Append untracked items to the main grid
allAppIds.forEach(id => {
    if (!seenIds.has(id)) {
        amOrder.push(id);
    }
});amRenderGrid();
        }
    } catch(e) {}
}

function amRenderGrid() {
    const grid = document.getElementById('am-grid');
    if (!grid) return;
    grid.innerHTML = "";

    const dockSection = document.getElementById('am-dock-section');
    const dockGrid = document.getElementById('am-dock-grid');
    if (dockSection && dockGrid) {
        dockGrid.innerHTML = "";
        if (amIsEditing || amDock.length > 0) {
            dockSection.style.display = "block";
            for (let i = 0; i < 4; i++) {
                const id = amDock[i];
                if (id) {
                    if (id.startsWith('folder_')) {
                        const folder = amFolders[id];
                        if (folder) dockGrid.appendChild(amCreateFolderElement(id, folder, i, true));
                    } else {
                        const app = amApps.find(a => a.id === id);
                        if (app) dockGrid.appendChild(amCreateAppElement(app, i, false, true));
                    }
                } else if (amIsEditing) {
                    const slot = document.createElement('div');
                    slot.className = 'am-icon-wrap empty-slot';
                    slot.dataset.id = 'empty-dock-slot';
                    slot.innerHTML = `<div class="am-icon" style="background:none; border:2px dashed var(--border-color); box-shadow:none; cursor:default;"></div>`;
                    amAttachDragEvents(slot, 'empty-dock-slot', false, true);
                    dockGrid.appendChild(slot);
                }
            }
        } else {
            dockSection.style.display = "none";
        }
    }

    amOrder.forEach((id, index) => {
        if (id.startsWith('folder_')) {
            const folder = amFolders[id];
            if (!folder) return;
            grid.appendChild(amCreateFolderElement(id, folder, index, false));
        } else {
            const app = amApps.find(a => a.id === id);
            if (!app) return;
            grid.appendChild(amCreateAppElement(app, index, false, false));
        }
    });
}

function amCreateAppElement(app, index, inFolder = false, inDock = false) {
    const wrap = document.createElement('div');
    wrap.className = 'am-icon-wrap';
    if (inDock) wrap.className += ' dock-item';
    wrap.dataset.id = app.id;
    wrap.dataset.index = index;
    wrap.setAttribute('draggable', amIsEditing ? 'true' : 'false');
    
    const isProtected = amProtectedIds.includes(app.id);
    wrap.innerHTML = `
        <div class="am-icon-container">
            <div class="am-delete-badge" onclick="event.stopPropagation(); amDeleteApp('${app.id}')">&times;</div>
            ${isProtected ? `<div style="position:absolute; top:-6px; right:-6px; width:22px; height:22px; background:#34C759; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid white; z-index:5; box-shadow:0 2px 8px rgba(0,0,0,0.2);"><img src="app/data/icons/shield-check.svg" style="width:14px; height:14px; filter:invert(1);"></div>` : ''}
            <div class="am-icon" style="background:${app.color}">${app.icon}</div>
        </div>
        <div class="am-label">${app.name}</div>
    `;
    
    amAttachDragEvents(wrap, app.id, inFolder, inDock);

    wrap.onclick = () => {
        if(amIsEditing) return;
        amOpenApp(app);
    };

    return wrap;
}

function amCreateFolderElement(folderId, folder, index, inDock = false) {
    const wrap = document.createElement('div');
    wrap.className = 'am-icon-wrap am-folder-wrap';
    if (inDock) wrap.className += ' dock-item';
    wrap.dataset.id = folderId;
    wrap.dataset.index = index;
    wrap.setAttribute('draggable', amIsEditing ? 'true' : 'false');
    
    let miniIcons = '';
    for (let i=0; i<Math.min(4, folder.apps.length); i++) {
        const app = amApps.find(a => a.id === folder.apps[i]);
        if (app) {
            const isSvg = app.icon.trim().startsWith('<svg');
            if (isSvg) {
                miniIcons += `<div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; overflow:hidden; transform:scale(1.05);">${app.icon}</div>`;
            } else {
                miniIcons += `<div style="background:${app.color}; width:100%; height:100%; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:12px; overflow:hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">${app.icon}</div>`;
            }
        }
    }
    
    wrap.innerHTML = `
        <div class="am-icon-container">
            <div class="am-delete-badge" onclick="event.stopPropagation(); amDeleteFolder('${folderId}')">&times;</div>
            <div class="am-icon" style="background: var(--primary, #1B4332); box-shadow: 0 8px 20px rgba(0,0,0,0.08); border: 1px solid rgba(0,0,0,0.05); display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr; gap:5px; padding:7px; border-radius: 50%; width: 64px; height: 64px; box-sizing: border-box; overflow: hidden; justify-content: center; align-items: center;">
                ${miniIcons}
            </div>
        </div>
        <div class="am-label">${folder.name}</div>
    `;
    
    amAttachDragEvents(wrap, folderId, false, inDock);

    wrap.onclick = (e) => {
        if (e) e.stopPropagation();
        if (amIsEditing) return;
        amOpenFolder(folderId);
    };

    return wrap;
}

function amAttachDragEvents(wrap, id, inFolder, inDock = false) {
    if (id === 'empty-dock-slot') {
        // Empty slots are only droppable targets, not draggable origins
        return;
    }

    wrap.ondragstart = (e) => {
        e.preventDefault();
    };

    let startX = 0, startY = 0, currentTarget = null;
    let isDraggingPointer = false;
    let pressTimer = null;
    let lastDropTargetId = null;
    let lastDropAction = null;

    wrap.onpointerdown = (e) => {
        if (!amIsEditing) {
            if (!id.startsWith('folder_')) {
                startX = e.clientX;
                startY = e.clientY;
                
                if (pressTimer) clearTimeout(pressTimer);
                pressTimer = setTimeout(() => {
                    pressTimer = null;
                    const app = amApps.find(a => a.id === id);
                    if (app) amShowContextMenu(app);
                    window.sui.haptic('medium');
                }, 600);
            }
            return;
        }
        
        isDraggingPointer = true;
        startX = e.clientX;
        startY = e.clientY;
        
        wrap.classList.add('dragging');
        window.sui.haptic('light');
        
        try {
            wrap.setPointerCapture(e.pointerId);
        } catch(err) {}
    };

    wrap.onpointermove = (e) => {
        if (!amIsEditing) {
            if (pressTimer) {
                const dist = Math.sqrt(Math.pow(e.clientX - startX, 2) + Math.pow(e.clientY - startY, 2));
                if (dist > 10) {
                    clearTimeout(pressTimer);
                    pressTimer = null;
                }
            }
            return;
        }

        if (!isDraggingPointer) return;
        
        // Enforce 25px dead-zone threshold for visual stability
        const dragDist = Math.sqrt(Math.pow(e.clientX - startX, 2) + Math.pow(e.clientY - startY, 2));
        if (dragDist < 25) {
            document.querySelectorAll('.am-icon-wrap').forEach(el => {
                el.classList.remove('drag-over', 'drag-over-folder', 'insert-before', 'insert-after');
            });
            currentTarget = null;
            lastDropTargetId = null;
            lastDropAction = null;
            return;
        }
        
        const dropTarget = findClosestAppItem(e.clientX, e.clientY);
        
        document.querySelectorAll('.am-icon-wrap').forEach(el => {
            el.classList.remove('drag-over', 'drag-over-folder', 'insert-before', 'insert-after');
        });
        
        if (dropTarget && dropTarget !== wrap) {
            const toId = dropTarget.dataset.id;
            const rect = dropTarget.getBoundingClientRect();
            const relX = e.clientX - rect.left;
            const width = rect.width;
            let dropAction = null;
            
            if (toId === 'empty-dock-slot') {
                dropTarget.classList.add('drag-over');
                dropAction = 'dock';
            } else if (relX < width * 0.35) {
                dropTarget.classList.add('insert-before');
                dropAction = 'before';
            } else if (relX > width * 0.65) {
                dropTarget.classList.add('insert-after');
                dropAction = 'after';
            } else {
                if (!inFolder && !toId.startsWith('folder_')) {
                    dropTarget.classList.add('drag-over-folder');
                } else if (toId.startsWith('folder_')) {
                    dropTarget.classList.add('drag-over-folder');
                } else {
                    dropTarget.classList.add('insert-after');
                    dropAction = 'after';
                }
                if (!dropAction) dropAction = 'folder';
            }
            // Check for state changes to trigger haptic feedback
            if (toId !== lastDropTargetId || dropAction !== lastDropAction) {
                lastDropTargetId = toId;
                lastDropAction = dropAction;
                if (window.sui && window.sui.haptic) {
                    window.sui.haptic('light');
                } else if (navigator.vibrate) {
                    navigator.vibrate(10);
                }
            }
            currentTarget = dropTarget;
            currentTarget.dropAction = dropAction;
        } else {
            currentTarget = null;
            if (lastDropTargetId !== null || lastDropAction !== null) {
                lastDropTargetId = null;
                lastDropAction = null;
                if (window.sui && window.sui.haptic) {
                    window.sui.haptic('light');
                } else if (navigator.vibrate) {
                    navigator.vibrate(5);
                }
            }
        }
    };

    const endDrag = (e) => {
        if (!amIsEditing) {
            if (pressTimer) {
                clearTimeout(pressTimer);
                pressTimer = null;
            }
            return;
        }

        if (!isDraggingPointer) return;
        isDraggingPointer = false;
        
        wrap.classList.remove('dragging');
        wrap.style.removeProperty('pointer-events');
        
        try {
            wrap.releasePointerCapture(e.pointerId);
        } catch(err) {}
        
        document.querySelectorAll('.am-icon-wrap').forEach(el => {
            el.classList.remove('drag-over', 'drag-over-folder', 'insert-before', 'insert-after');
        });
        
        if (currentTarget) {
            const toId = currentTarget.dataset.id;
            amHandleDrop(id, toId, inFolder, inDock, currentTarget.dropAction);
            currentTarget = null;
        } else if (inFolder) {
            const sheet = document.getElementById('am-folder-sheet');
            if (sheet) {
                const rect = sheet.getBoundingClientRect();
                const insideX = e.clientX >= rect.left && e.clientX <= rect.right;
                const insideY = e.clientY >= rect.top && e.clientY <= rect.bottom;
                
                if (!insideX || !insideY) {
                    amRemoveFromCurrentPos(id);
                    amOrder.push(id);
                    amRenderFolderView(amOpenFolderId);
                    amRenderGrid();
                    window.sui.toast("Moved to Grid");
                }
            }
        } else if (inDock) {
            const section = document.getElementById('am-dock-section');
            if (section) {
                const rect = section.getBoundingClientRect();
                const insideX = e.clientX >= rect.left && e.clientX <= rect.right;
                const insideY = e.clientY >= rect.top && e.clientY <= rect.bottom;
                
                if (!insideX || !insideY) {
                    amRemoveFromCurrentPos(id);
                    amOrder.push(id);
                    amRenderGrid();
                    window.sui.toast("Moved to Grid");
                }
            }
        }
    };

    wrap.onpointerup = endDrag;
    wrap.onpointercancel = endDrag;
}

function amHandleDrop(fromId, toId, inFolder, inDock = false, dropAction = 'before') {
    if (fromId === toId) return;

    if (toId === 'empty-dock-slot') {
        const activeDockItems = amDock.filter(x => x !== fromId).length;
        if (activeDockItems >= 4) {
            window.sui.toast("Dock is full (maximum 4 items)");
            return;
        }
        amRemoveFromCurrentPos(fromId);
        amDock.push(fromId);
        amRenderGrid();
        window.sui.haptic('medium');
        return;
    }

    const toInDock = amDock.includes(toId);

    if (dropAction === 'folder') {
        if (fromId.startsWith('folder_')) {
            window.sui.toast("Cannot put a folder inside a folder");
            return;
        }
        if (toId.startsWith('folder_')) {
            amRemoveFromCurrentPos(fromId);
            amFolders[toId].apps.push(fromId);
            amRenderGrid();
            window.sui.haptic('medium');
            return;
        } else if (!inFolder && !inDock) {
            window.openInput("New Folder", "Folder Name", "Folder", (name) => {
                if (name) {
                    const newFolderId = 'folder_' + Date.now();
                    amFolders[newFolderId] = { name: name, apps: [toId, fromId] };
                    
                    const toIdx = amOrder.indexOf(toId);
                    if (toIdx !== -1) amOrder[toIdx] = newFolderId;
                    
                    const fromIdx = amOrder.indexOf(fromId);
                    if (fromIdx !== -1) amOrder.splice(fromIdx, 1);
                    
                    amRenderGrid();
                    window.sui.haptic('success');
                }
            });
            return;
        }
    }

    // Move logic
    if (inFolder && amOpenFolderId && amFolders[amOpenFolderId]) {
        const fApps = amFolders[amOpenFolderId].apps;
        const fromIdx = fApps.indexOf(fromId);
        if (fromIdx !== -1) fApps.splice(fromIdx, 1);
        else amRemoveFromCurrentPos(fromId);
        
        const tIdx = fApps.indexOf(toId);
        if (tIdx !== -1) {
            fApps.splice(dropAction === 'after' ? tIdx + 1 : tIdx, 0, fromId);
        } else {
            fApps.push(fromId);
        }
        amRenderFolderView(amOpenFolderId);
        amRenderGrid();
        window.sui.haptic('medium');
        return;
    }

    if (toInDock) {
        const activeDockItems = amDock.filter(x => x !== fromId).length;
        if (activeDockItems >= 4 && !amDock.includes(fromId)) {
            window.sui.toast("Dock is full (maximum 4 items)");
            return;
        }
        amRemoveFromCurrentPos(fromId);
        const tIdx = amDock.indexOf(toId);
        if (tIdx !== -1) {
            amDock.splice(dropAction === 'after' ? tIdx + 1 : tIdx, 0, fromId);
        } else {
            amDock.push(fromId);
        }
    } else {
        amRemoveFromCurrentPos(fromId);
        const tIdx = amOrder.indexOf(toId);
        if (tIdx !== -1) {
            amOrder.splice(dropAction === 'after' ? tIdx + 1 : tIdx, 0, fromId);
        } else {
            amOrder.push(fromId);
        }
    }
    amRenderGrid();
    window.sui.haptic('medium');
}

function amRemoveFromCurrentPos(id) {
    const idx = amOrder.indexOf(id);
    if (idx !== -1) {
        amOrder.splice(idx, 1);
        return;
    }
    const dIdx = amDock.indexOf(id);
    if (dIdx !== -1) {
        amDock.splice(dIdx, 1);
        return;
    }
    for (let fId in amFolders) {
        const fIdx = amFolders[fId].apps.indexOf(id);
        if (fIdx !== -1) {
            amFolders[fId].apps.splice(fIdx, 1);
            if (amFolders[fId].apps.length === 0) {
                delete amFolders[fId];
                const folderIdx = amOrder.indexOf(fId);
                if (folderIdx !== -1) amOrder.splice(folderIdx, 1);
            }
            return;
        }
    }
}

window.amDeleteFolder = function(folderId) {
    window.openConfirm("Delete Folder", "Are you sure? Apps inside will be moved back to the main grid.", () => {
        const apps = amFolders[folderId].apps;
        delete amFolders[folderId];
        
        const idx = amOrder.indexOf(folderId);
        if (idx !== -1) {
            amOrder.splice(idx, 1, ...apps);
        } else {
            amOrder.push(...apps);
        }
        
        amRenderGrid();
        window.sui.haptic('success');
    }, true);
};

window.amOpenFolder = function(folderId) {
    amOpenFolderId = folderId;
    const folder = amFolders[folderId];
    if (!folder) return;
    
    const titleEl = document.getElementById('am-folder-title');
    if (titleEl) {
        titleEl.innerHTML = `
            ${folder.name} 
            ${amIsEditing ? `<button onclick="amRenameFolder('${folderId}')" style="background:none; border:none; color:var(--text-secondary); cursor:pointer; vertical-align:middle; margin-left:6px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg></button>` : ''}
        `;
    }
    amRenderFolderView(folderId);
    
    const overlay = document.getElementById('am-folder-overlay');
    const sheet = document.getElementById('am-folder-sheet');
    if (!overlay || !sheet) return;

    overlay.style.display = 'flex';
    void overlay.offsetWidth;
    overlay.classList.add('visible');
    sheet.style.transform = 'scale(1)';
    sheet.style.opacity = '1';
};

window.amCloseFolder = function() {
    amOpenFolderId = null;
    const overlay = document.getElementById('am-folder-overlay');
    const sheet = document.getElementById('am-folder-sheet');
    if (!overlay || !sheet) return;

    overlay.classList.remove('visible');
    sheet.style.transform = 'scale(0.9)';
    sheet.style.opacity = '0';
    setTimeout(() => {
        if (!amOpenFolderId) {
            overlay.style.display = 'none';
        }
    }, 300);
};

window.amRenameFolder = function(folderId) {
    window.openInput("Rename Folder", "Folder Name", amFolders[folderId].name, (val) => {
        if (val) {
            amFolders[folderId].name = val;
            document.getElementById('am-folder-title').innerHTML = `
                ${val} 
                ${amIsEditing ? `<button onclick="amRenameFolder('${folderId}')" style="background:none; border:none; color:var(--text-secondary); cursor:pointer;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg></button>` : ''}
            `;
            amRenderGrid();
        }
    });
};

function amRenderFolderView(folderId) {
    const grid = document.getElementById('am-folder-grid');
    if (!grid) return;
    grid.innerHTML = "";
    
    const folder = amFolders[folderId];
    folder.apps.forEach((appId, index) => {
        const app = amApps.find(a => a.id === appId);
        if (app) {
            grid.appendChild(amCreateAppElement(app, index, true));
        }
    });
}

window.amOpenSettings = function() {
    const overlay = document.getElementById('am-settings-overlay');
    overlay.classList.add('visible');
    
    // Initialize Slider UI
    const slider = document.getElementById('am-push-offset-slider');
    const label = document.getElementById('am-push-offset-val');
    if (slider) {
        slider.value = amPushOffset;
        slider.style.setProperty('--range-pct', ((amPushOffset - 0) / (150 - 0)) * 100 + '%');
    }
    if (label) label.innerText = amPushOffset + 'px';

    amRenderContextList();
};

window.amUpdatePushOffset = function(val) {
    amPushOffset = parseInt(val);
    const label = document.getElementById('am-push-offset-val');
    if (label) label.innerText = amPushOffset + 'px';
    document.documentElement.style.setProperty('--am-push-offset', amPushOffset + 'px');
};

window.amSaveSettingsGlobal = async function() {
    await window.sui.api('am_save_config', {
        order: amOrder,
        dock: amDock,
        folders: amFolders,
        excluded: amExcludedIds,
        protected: amProtectedIds,
        push_offset: amPushOffset
    }, { toast: false });
};

window.amCloseSettings = function() {
    document.getElementById('am-settings-overlay').classList.remove('visible');
};

function amRenderContextList() {
    const cont = document.getElementById('am-context-list');
    if (!cont) return;
    cont.innerHTML = "";

    amApps.forEach(app => {
        const isIncluded = !amExcludedIds.includes(app.id);
        const item = document.createElement('div');
        item.style.cssText = "background:var(--card-bg); border:1px solid var(--border-color); border-radius:14px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center;";
        item.innerHTML = `
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="font-size:20px;">${app.icon}</div>
                <div>
                    <div style="font-weight:600; font-size:15px; color:var(--text-primary);">${app.name}</div>
                    <div style="font-size:11px; color:var(--text-secondary); opacity:0.7;">${isIncluded ? 'Included in Export' : 'Excluded from Export'}</div>
                </div>
            </div>
            ${window.suiSwitch('am-exc-' + app.id, isIncluded, `amToggleExclusion('${app.id}', this.checked)`)}
        `;
        cont.appendChild(item);
    });
}

window.amToggleExclusion = async function(id, included) {
    if (included) {
        amExcludedIds = amExcludedIds.filter(x => x !== id);
    } else {
        if (!amExcludedIds.includes(id)) amExcludedIds.push(id);
    }
    
    amSaveSettingsGlobal();
    amRenderContextList();
};

function amOpenApp(app) {
    // If a folder was open, close it immediately
    amCloseFolder();

    const viewport = document.querySelector('.horizontal-viewport');
    const amPageView = document.getElementById('app-maker-view').closest('.page-view');
    if (!viewport || !amPageView) return;

    // 1. Prevent multiple instances of the same app page
    const existing = document.getElementById('am-dynamic-app-page');
    if (existing) {
        const oldIframe = existing.querySelector('iframe');
        if (oldIframe) {
            try { oldIframe.contentWindow?.stop?.(); } catch(e) {}
            oldIframe.src = 'about:blank';
        }
        existing.remove();
    }

    // 2. Create the Dynamic Page
    const newPage = document.createElement('div');
    newPage.className = 'page-view am-dynamic-app-page';
    newPage.id = 'am-dynamic-app-page';
    
    // 3. Inject App Structure (Iframe + Header + Top Spacer)
    // We use a 15px buffer to "calm down" the layout and ensure 100% visibility.
    newPage.innerHTML = `
        <div style="height:calc(var(--header-base-height) + var(--inner-padding-top) + 15px); flex-shrink:0; background:var(--header-bg);"></div>
        <div style="height:54px; background:var(--header-bg); backdrop-filter:blur(30px); -webkit-backdrop-filter:blur(30px); display:flex; align-items:center; justify-content:space-between; padding:0 20px; border-bottom:1px solid rgba(0,0,0,0.05); flex-shrink:0; z-index:10;">
            <div style="font-size:11px; font-weight:800; color:var(--text-primary); text-transform:uppercase; letter-spacing:2px; display:flex; align-items:center; gap:10px;">
                <span class="am-header-icon-wrap" style="width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:20px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));">${app.icon}</span> ${app.name}
            </div>
            <div style="display:flex; gap:12px; align-items:center;">
                <div style="display:flex; align-items:center; gap:6px; background:var(--btn-bg); padding:4px 10px; border-radius:20px; border:1px solid var(--border-color);">
                    <span style="font-size:9px; font-weight:900; color:var(--text-secondary); letter-spacing:1px;">LIVE</span>
                    ${window.suiSwitch('am-live-reload-toggle', false, `amToggleLiveReload('${app.id}', this.checked)`)}
                </div>
                <button id="am-refresh-btn-manual" onclick="amRefreshDynamicApp(this)" style="background:var(--btn-bg); border:none; width:32px; height:32px; border-radius:50%; color:var(--text-primary); cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0; transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px; height:16px;"><path d="M23 4v6h-6"></path><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                </button>
                <button onclick="amCloseDynamicApp()" style="background:var(--primary); border:none; width:32px; height:32px; border-radius:50%; color:white; font-weight:800; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0; box-shadow:0 4px 12px rgba(0,122,255,0.3); transition: transform 0.1s;">&times;</button>
            </div>
        </div>
        <iframe src="${app.path}" style="flex:1; width:100%; border:none; background:white;"></iframe>
    `;

    // 4. Insert to the right of App Maker
    amPageView.after(newPage);

    // 5. Push History State (for Android Back Override)
    if (typeof aboEnabled !== "undefined" && aboEnabled) {
        history.pushState({ am_open: true }, null, window.location.href);
    }

    // 6. Scroll to it
    setTimeout(() => {
        if (typeof window.showAboShield === "function") window.showAboShield();
        newPage.scrollIntoView({ behavior: 'smooth', inline: 'start' });
    }, 50);
}

window.amCloseDynamicApp = function() {
    // Cleanup Live Reload
    amLiveReloadActiveAppId = null;
    amStopLiveReload();
    amLastAppHash = null;

    const viewport = document.querySelector('.horizontal-viewport');
    const amPageView = document.getElementById('app-maker-view').closest('.page-view');
    const dynamicPage = document.getElementById('am-dynamic-app-page');

    if (!amPageView || !dynamicPage) return;

    // Immediately stop iframe execution and terminate background scripts
    const iframe = dynamicPage.querySelector('iframe');
    if (iframe) {
        try { iframe.contentWindow?.stop?.(); } catch(e) {}
        iframe.src = 'about:blank';
    }

    // 1. Scroll back to App Maker Home
    amPageView.scrollIntoView({ behavior: 'smooth', inline: 'start' });

    // 2. Wait for scroll animation to finish, then destroy
    setTimeout(() => {
        dynamicPage.remove();
    }, 500);
};

function amGetAlternativeUrls(appPath) {
    const runtime = window.CJOS_RUNTIME || {};
    const isRuntime = !!runtime.is_runtime || (typeof window.RuntimeBridge !== 'undefined');
    
    const baseDir = window.location.pathname.split('index.php')[0];
    const rawPath = baseDir + appPath;
    const cleanParts = [];
    for (let p of rawPath.split('/')) {
        if (p === '..') cleanParts.pop();
        else if (p !== '.' && p !== '') cleanParts.push(p);
    }
    const resolvedPath = '/' + cleanParts.join('/');

    const urls = [];
    const addedUrls = new Set();

    const addUrl = (label, url) => {
        if (url && !addedUrls.has(url)) {
            addedUrls.add(url);
            urls.push({ label, url });
        }
    };

    // 1. Current Visiting Host (Always Included First)
    addUrl(`Current Host (${window.location.host})`, window.location.origin + resolvedPath);

    // 2. Localhost & 127.0.0.1 Links
    const currentPort = window.location.port ? `:${window.location.port}` : '';
    const currentProto = window.location.protocol;
    addUrl(`Localhost (127.0.0.1${currentPort})`, `${currentProto}//127.0.0.1${currentPort}${resolvedPath}`);
    addUrl(`Localhost (localhost${currentPort})`, `${currentProto}//localhost${currentPort}${resolvedPath}`);

    // 3. mDNS Links for Runtime
    if (isRuntime) {
        const httpPortStr = (runtime.http_port && runtime.http_port !== 80) ? `:${runtime.http_port}` : '';
        const httpsPortStr = (runtime.https_port && runtime.https_port !== 443) ? `:${runtime.https_port}` : '';
        const mdnsHost = runtime.mdns_host || 'conjure.local';

        addUrl(`mDNS HTTP (${mdnsHost}${httpPortStr})`, `http://${mdnsHost}${httpPortStr}${resolvedPath}`);
        addUrl(`mDNS HTTPS (${mdnsHost}${httpsPortStr})`, `https://${mdnsHost}${httpsPortStr}${resolvedPath}`);
    }

    // 4. Local LAN IP
    const lanIp = window.CJOS_IPS && window.CJOS_IPS.lan;
    if (lanIp) {
        addUrl(`LAN IP (${lanIp}${currentPort})`, `${currentProto}//${lanIp}${currentPort}${resolvedPath}`);
    }

    // 5. Tailscale Domain
    const tsDomain = (runtime.tailscale_domain) || (window.CJOS_IPS && window.CJOS_IPS.tailscale_domain);
    if (tsDomain) {
        let cleanDomain = tsDomain.replace(/^https?:\/\//, '');
        if (!cleanDomain.includes(':')) {
            if (runtime.https_port && runtime.https_port !== 443) {
                cleanDomain += `:${runtime.https_port}`;
            } else if (window.location.port && window.location.port !== '80' && window.location.port !== '443') {
                cleanDomain += `:${window.location.port}`;
            }
        }
        addUrl(`Tailscale HTTPS (${cleanDomain})`, `https://${cleanDomain}${resolvedPath}`);
    }
    
    return urls;
}

function amShowContextMenu(app) {
    if (typeof window.openPicker !== "function") return;
    
    const url = window.location.href.split('index.php')[0] + app.path;
    const isProtected = amProtectedIds.includes(app.id);
    
    const options = [
        { label: "Execution & Launch", type: "header" },
        { label: "🚀 Open in New Tab", value: "tab" },
        { label: "🪟 Open in New Window", value: "window" },
        
        { label: "Sharing", type: "header" },
        { label: "🔗 Copy App URL", value: "copy" },
        
        { label: "Developer Tools", type: "header" },
        { label: "📄 Full Source (.txt)", value: "source_full" },
        { label: "🧹 Clean Source (.txt)", value: "source_clean" },
        
        { label: "Packaging & Export", type: "header" },
        { label: "📦 Full Export (ZIP)", value: "zip_full" },
        { label: "🧹 Clean Export (ZIP)", value: "zip_clean" },
        
        { label: "System & Governance", type: "header" },
        { label: isProtected ? "🔓 Remove System Protection" : "🛡️ Protect from Restore", value: "toggle_shield" },
        
        { label: "Danger Zone", type: "header" },
        { label: "🗑️ Delete App", value: "delete", color: "var(--danger)" }
    ];

    window.openPicker(app.name, options, null, async (val) => {
        if (val === "toggle_shield") {
            if (isProtected) {
                amProtectedIds = amProtectedIds.filter(id => id !== app.id);
            } else {
                amProtectedIds.push(app.id);
            }
            await window.sui.api('am_save_config', {
                order: amOrder,
                folders: amFolders,
                excluded: amExcludedIds,
                protected: amProtectedIds,
                push_offset: amPushOffset
            }, { toast: isProtected ? "Protection Removed" : "App Shielded" });
            amRenderGrid();
            return;
        }
        if (val === "delete") {
            window.amDeleteApp(app.id);
            return;
        }
        if (val === "tab") window.open(app.path, '_blank');
        if (val === "window") window.open(app.path, 'AppWindow', 'width=400,height=700');
        if (val === "copy") {
            const urls = amGetAlternativeUrls(app.path);
            const copyOptions = urls.map(opt => ({
                label: opt.label,
                value: opt.url
            }));
            
            setTimeout(() => {
                window.openPicker("Copy URL: " + app.name, copyOptions, null, (selectedUrl) => {
                    navigator.clipboard.writeText(selectedUrl);
                    if (window.sui && window.sui.toast) {
                        window.sui.toast("URL Copied");
                    } else {
                        const t = document.getElementById("toast");
                        if(t) { t.innerText = "URL Copied"; t.classList.add("show"); setTimeout(()=>t.classList.remove("show"), 2000); }
                    }
                });
            }, 100);
        }
        if (val === "source_full") {
            window.location.href = `index.php?plugin_action=ce_download_app_source&mode=full&folder=${app.id}`;
        }
        if (val === "source_clean") {
            window.location.href = `index.php?plugin_action=ce_download_app_source&mode=clean&folder=${app.id}`;
        }
        if (val === "zip_full") {
            window.location.href = `index.php?plugin_action=am_export_zip&mode=full&id=${app.id}`;
        }
        if (val === "zip_clean") {
            window.location.href = `index.php?plugin_action=am_export_zip&mode=clean&id=${app.id}`;
        }
    });
}

window.amToggleEdit = function() {
    amIsEditing = !amIsEditing;
    document.body.classList.toggle('am-jiggle', amIsEditing);
    const btn = document.getElementById('am-edit-btn');
    if (btn) {
        if (amIsEditing) {
            btn.innerHTML = checkSvg;
            btn.style.background = "var(--primary)";
            btn.style.color = "white";
            amRenderGrid();
        } else {
            btn.innerHTML = pencilSvg;
            btn.style.background = "var(--btn-bg)";
            btn.style.color = "var(--text-primary)";
            // Save Order
            amSaveSettingsGlobal();
            amRenderGrid();
        }
    }
};

window.amRefreshGrid = async function(btn) {
    if (btn) {
        btn.style.transform = 'rotate(360deg)';
        setTimeout(() => { btn.style.transform = 'rotate(0deg)'; }, 300);
    }
    if (window.sui && window.sui.haptic) window.sui.haptic('light');
    await amFetchApps();
    if (window.sui && window.sui.toast) window.sui.toast("Apps Refreshed");
};

window.amDeleteApp = function(id) {
    const msg = `Permanently delete "${id}" and all its files? This cannot be undone.<br><br><a href="index.php?plugin_action=am_export_zip&mode=full&id=${id}" style="display:block; padding:12px; background:var(--btn-bg); color:var(--primary); border-radius:10px; text-decoration:none; text-align:center; font-weight:700; border:1px solid var(--border-color);">📥 Download Full Backup ZIP</a>`;
    
    window.openConfirm("Delete App", msg, async () => {
        const data = await window.sui.api('am_delete_app', { id: id }, { toast: "App Deleted" });
        if(data) {
            amApps = amApps.filter(a => a.id !== id);
            amRemoveFromCurrentPos(id);
            amSaveSettingsGlobal();
            amRenderGrid();
            if (amOpenFolderId) amRenderFolderView(amOpenFolderId);
        }
    }, true);
};

window.amRefreshDynamicApp = function(btn) {
    const iframe = document.querySelector('#am-dynamic-app-page iframe');
    if (iframe) {
        if (btn) btn.style.transform = 'rotate(180deg)';
        setTimeout(() => { if(btn) btn.style.transform = 'rotate(0deg)'; }, 300);
        const currentSrc = iframe.src;
        iframe.src = '';
        iframe.src = currentSrc;
        if (window.sui && window.sui.haptic) window.sui.haptic('light');
    }
};

window.amToggleLiveReload = function(appId, enabled) {
    amStopLiveReload();
    amLiveReloadActiveAppId = enabled ? appId : null;
    amLastAppHash = null;

    if (enabled) {
        window.sui.toast("Live Reload Active", { toast: true });
        amStartLiveReload();
    } else {
        window.sui.toast("Live Reload Disabled");
    }
};

async function amPerformLiveCheck(appId) {
    try {
        const data = await window.sui.api('am_check_changes', { id: appId }, { toast: false, errorToast: false });
        if (data && data.status === 'success') {
            if (amLastAppHash !== null && amLastAppHash !== data.hash) {
                console.log("[AppMaker] Change detected, reloading...");
                const btn = document.getElementById('am-refresh-btn-manual');
                amRefreshDynamicApp(btn);
            }
            amLastAppHash = data.hash;
        }
    } catch(e) {
        console.error("[AppMaker] Live check failed", e);
    }
}
JS;
?>