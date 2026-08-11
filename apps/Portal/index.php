<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['action']) && $input['action'] === 'save_layout') {
        $configFile = __DIR__ . '/../../app/data/app-maker-config.json';
        $config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
        if (!is_array($config)) $config = [];
        
        $config['order'] = $input['order'] ?? ($config['order'] ?? []);
        $config['dock'] = $input['dock'] ?? ($config['dock'] ?? []);
        $config['folders'] = $input['folders'] ?? ($config['folders'] ?? []);
        $config['dock_labels'] = isset($input['dock_labels']) ? (bool)$input['dock_labels'] : ($config['dock_labels'] ?? false);
        $config['hide_status_on_launch'] = isset($input['hide_status_on_launch']) ? (bool)$input['hide_status_on_launch'] : ($config['hide_status_on_launch'] ?? false);
        $config['wallpaper_blur'] = isset($input['wallpaper_blur']) ? (int)$input['wallpaper_blur'] : ($config['wallpaper_blur'] ?? 40);
        $config['disable_wallpaper'] = isset($input['disable_wallpaper']) ? (bool)$input['disable_wallpaper'] : ($config['disable_wallpaper'] ?? false);
        $config['show_icon_backdrops'] = isset($input['show_icon_backdrops']) ? (bool)$input['show_icon_backdrops'] : ($config['show_icon_backdrops'] ?? true);
        $config['line_length'] = isset($input['line_length']) ? (int)$input['line_length'] : ($config['line_length'] ?? 16);
        $config['box_size'] = isset($input['box_size']) ? (int)$input['box_size'] : ($config['box_size'] ?? 2);
        
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

function get_asset_hash($files) {
    $combined = '';
    foreach ($files as $f) {
        $path = __DIR__ . '/' . $f;
        if (file_exists($path)) $combined .= md5_file($path);
    }
    return $combined ? substr(md5($combined), 0, 8) : 'dev';
}

function get_lan_ip() {
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
    $os = strtoupper(substr(PHP_OS, 0, 3));
    $output = [];
    if ($os === 'WIN') { @exec('ipconfig', $output); }
    else { @exec('ip addr 2>&1 || ifconfig 2>&1', $output); }
    foreach ($output as $line) {
        if (preg_match_all('/\b(192\.168\.\d{1,3}\.\d{1,3}|10\.(?!(6[4-9]|[7-9]\d|1[0-1]\d|12[0-7])\.)\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3})\b/', $line, $matches)) {
            foreach ($matches[0] as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
            }
        }
    }
    return null;
}

function get_runtime_info() {
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
    
    $ts_domain = null;
    $configFile = __DIR__ . '/../../app/data/app-maker-config.json';
    if (file_exists($configFile)) {
        $configData = json_decode(file_get_contents($configFile), true);
        if (!empty($configData['tailscale_domain'])) {
            $ts_domain = $configData['tailscale_domain'];
        }
    }
    
    return [
        'is_runtime' => $is_runtime,
        'http_port' => $http_port,
        'https_port' => $https_port,
        'mdns_host' => 'conjure.local',
        'tailscale_domain' => $ts_domain
    ];
}

function get_tailscale_ip() {
    if (function_exists('net_get_interfaces')) {
        $interfaces = @net_get_interfaces();
        if ($interfaces !== false) {
            foreach ($interfaces as $name => $info) {
                if (isset($info['unicast']) && is_array($info['unicast'])) {
                    foreach ($info['unicast'] as $unicast) {
                        $ip = $unicast['address'] ?? '';
                        if (preg_match('/^100\.(6[4-9]|[7-9]\d|1[0-1]\d|12[0-7])\.\d{1,3}\.\d{1,3}$/', $ip)) return $ip;
                    }
                }
            }
        }
    }
    $os = strtoupper(substr(PHP_OS, 0, 3));
    $cmds = [];
    if ($os === 'WIN') { $cmds[] = 'tailscale.exe ip -4'; }
    else { $cmds[] = 'tailscale ip -4'; $cmds[] = '/usr/bin/tailscale ip -4'; $cmds[] = '/data/data/com.termux/files/usr/bin/tailscale ip -4'; }
    foreach ($cmds as $cmd) {
        $out = []; $code = 1; @exec($cmd . ' 2>&1', $out, $code);
        if ($code === 0 && !empty($out)) {
            $ip = trim(implode('', $out));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
        }
    }
    $output = [];
    if ($os === 'WIN') { @exec('ipconfig', $output); }
    else { @exec('ip addr 2>&1 || ifconfig 2>&1', $output); }
    foreach ($output as $line) {
        if (preg_match_all('/\b100\.(6[4-9]|[7-9]\d|1[0-1]\d|12[0-7])\.\d{1,3}\.\d{1,3}\b/', $line, $matches)) {
            foreach ($matches[0] as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
            }
        }
    }
    return null;
}

$v = get_asset_hash(['css/style.css', 'js/app.js']);

// Scan for apps
$apps = [];
$foldersDir = array_filter(glob(__DIR__ . '/../*'), 'is_dir');
foreach ($foldersDir as $f) {
    $id = basename($f);
    if ($id === 'Portal') continue;
    $manifestPath = $f . '/manifest.json';
    if (file_exists($manifestPath)) {
        $data = json_decode(file_get_contents($manifestPath), true);
        $svgPath = $f . '/icon.svg';
        $apps[] = [
            'id' => $id,
            'name' => $data['name'] ?? $id,
            'icon' => $data['icon'] ?? '📦',
            'svg' => file_exists($svgPath) ? '../' . $id . '/icon.svg' : null,
            'path' => '../' . $id . '/index.php',
            'color' => $data['color'] ?? null
        ];
    }
}

// Sort and format apps based on system config
$configFile = __DIR__ . '/../../app/data/app-maker-config.json';
$config = [];
if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true);
}
$dockOrder = $config['dock'] ?? [];
$order = $config['order'] ?? [];
$folders = $config['folders'] ?? [];

$allItems = [];
foreach ($apps as $app) {
    $allItems[$app['id']] = ['type' => 'app', 'app' => $app];
}
foreach ($folders as $fid => $f) {
    $allItems[$fid] = ['type' => 'folder', 'id' => $fid, 'name' => $f['name'], 'apps' => $f['apps']];
    foreach ($f['apps'] as $aid) {
        unset($allItems[$aid]);
    }
}

$dockItems = [];
foreach ($dockOrder as $id) {
    if (isset($allItems[$id])) {
        $dockItems[] = $allItems[$id];
        unset($allItems[$id]);
    }
}

$gridItems = [];
foreach ($order as $id) {
    if (isset($allItems[$id])) {
        $gridItems[] = $allItems[$id];
        unset($allItems[$id]);
    }
}

foreach ($allItems as $item) {
    $gridItems[] = $item;
}

$totalApps = count($apps);
$totalFolders = count($folders);

$free = @disk_free_space("/");
$total = @disk_total_space("/");
$storagePct = $total ? round((($total - $free) / $total) * 100, 1) . '%' : '57.3%';

function render_app_item($item, $isDock = false) {
    global $apps;
    if ($item['type'] === 'app') {
        $app = $item['app'];
        $iconHtml = !empty($app['svg']) 
            ? '<img src="'.$app['svg'].'" style="width:100%; height:100%; object-fit:contain; display:block;" alt="">' 
            : $app['icon'];
        
        $appJson = htmlspecialchars(json_encode($app), ENT_QUOTES, 'UTF-8');
        
        echo '<div class="app-item" data-id="'.$app['id'].'" data-type="app" data-app-json="'.$appJson.'">';
        echo '<div class="icon-container" style="background: '.($app['color'] ?? '#5856D6').';">';
        echo $iconHtml;
        echo '</div>';
        $nameClass = $isDock ? 'app-name dock-label' : 'app-name';
        echo '<div class="'.$nameClass.'">'.$app['name'].'</div>';
        echo '</div>';
    } else {
        $folder = $item;
        echo '<div class="app-item" data-id="'.$folder['id'].'" data-type="folder">';
        echo '<div class="icon-container" style="background: rgba(255,255,255,0.1); border: 1px solid var(--card-border); display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr; gap:4px; padding:6px;">';
        $count = 0;
        foreach ($folder['apps'] as $appId) {
            if ($count >= 4) break;
            foreach ($apps as $a) {
                if ($a['id'] === $appId) {
                    $isSvg = strpos(trim($a['icon']), '<svg') === 0;
                    if ($isSvg || !empty($a['svg'])) {
                        echo '<div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; overflow:hidden; transform:scale(1.05);">';
                        echo !empty($a['svg']) ? '<img src="'.$a['svg'].'" style="width:100%; height:100%; object-fit:contain;" alt="">' : $a['icon'];
                        echo '</div>';
                    } else {
                        echo '<div style="background:'.($a['color'] ?? '#5856D6').'; width:100%; height:100%; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:10px; overflow:hidden;">';
                        echo $a['icon'];
                        echo '</div>';
                    }
                    $count++;
                    break;
                }
            }
        }
        echo '</div>';
        $nameClass = $isDock ? 'app-name dock-label' : 'app-name';
        echo '<div class="'.$nameClass.'">'.$folder['name'].'</div>';
        echo '</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Portal</title>
    <link rel="stylesheet" href="css/style.css?v=<?php
echo $v; ?>">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#000000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js').catch(err => console.log('SW failed:', err));
            });
        }
    </script>
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="Portal">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
</head>
<?php
$dockLabels = $config['dock_labels'] ?? false; ?>
<?php
$dockLabels = $config['dock_labels'] ?? false; 
$wallpaperBlur = $config['wallpaper_blur'] ?? 40;
$disableWallpaper = $config['disable_wallpaper'] ?? false;
$showIconBackdrops = $config['show_icon_backdrops'] ?? true;
$boxSize = $config['box_size'] ?? 2;
?>
<body class="theme-midnight <?php
echo $dockLabels ? 'dock-labels-enabled' : ''; ?> <?php
echo $disableWallpaper ? 'wallpaper-disabled' : ''; ?> <?php
echo !$showIconBackdrops ? 'hide-icon-backdrops' : ''; ?>" style="--wallpaper-blur: <?php
echo $wallpaperBlur; ?>px; --box-size: <?php
echo $boxSize; ?>px;">
  <script>
    (function() {
        // 1. Frame Mode
        var defaultFrame = window.innerWidth > 600;
        var stored = localStorage.getItem('portal_frame');
        if (stored !== null) {
            defaultFrame = (stored === 'true');
        }
        if (!defaultFrame) {
            document.body.classList.add('frame-disabled');
        }
        
        // 2. Icon Size (Prevents morphing animation on load)
        var storedIconSize = localStorage.getItem('portal_icon_size');
        if (storedIconSize !== null) {
            document.documentElement.style.setProperty('--icon-size', storedIconSize + 'px');
        }
        
        // 3. Grid Columns (Prevents layout shift on load)
        var storedCols = localStorage.getItem('portal_cols');
        if (storedCols === '3') {
            var style = document.createElement('style');
            style.innerHTML = '#main-app-grid { grid-template-columns: repeat(3, var(--icon-size, 54px)) !important; }';
            document.head.appendChild(style);
        }
        
        // 4. Theme (Prevents dark-to-light background flash)
        var storedTheme = localStorage.getItem('portal_theme');
        if (storedTheme === 'theme-paper') {
            document.body.classList.remove('theme-midnight');
            document.body.classList.add('theme-paper');
        }
    })();
  </script>

  <div class="phone-container">
    <div class="phone-btn btn-volume-up"></div>
    <div class="phone-btn btn-volume-down"></div>
    <div class="phone-btn btn-power"></div>

    <div class="phone-screen" id="phone-screen">
      <div class="screen-wallpaper" id="screen-wallpaper">
        <div class="wallpaper-shapes">
          <div class="shape shape1"></div>
          <div class="shape shape2"></div>
        </div>
      </div>

      <div class="notch">
        <div class="notch-camera"></div>
        <div class="notch-sensor"></div>
      </div>

      <div class="status-bar">
        <div class="status-left" id="status-time">09:41</div>
        <div class="status-right" style="display:flex; align-items:center; gap:6px;">
          <!-- Modern Cellular staircase signal bars -->
          <svg class="status-icon" id="status-cellular-icon" viewBox="0 0 24 24" style="width:14px; height:14px; color:currentColor;">
            <rect x="2" y="16" width="3" height="4" rx="0.5" id="sig-bar-1" fill="currentColor" />
            <rect x="7" y="12" width="3" height="8" rx="0.5" id="sig-bar-2" fill="currentColor" />
            <rect x="12" y="8" width="3" height="12" rx="0.5" id="sig-bar-3" fill="currentColor" />
            <rect x="17" y="4" width="3" height="16" rx="0.5" id="sig-bar-4" fill="currentColor" />
          </svg>
          
          <!-- Modern Wi-Fi arc icon -->
          <svg class="status-icon" id="status-wifi-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px;">
            <path d="M5 12.55a11 11 0 0 1 14.08 0" />
            <path d="M1.42 9a16 16 0 0 1 21.16 0" />
            <path d="M8.5 16.1a5.5 5.5 0 0 1 7 0" />
            <line x1="12" y1="20" x2="12.01" y2="20" stroke-width="3.5" />
          </svg>
          
          <!-- Modern iOS style hollow battery with live level fill -->
          <div class="battery-wrap" id="status-battery-icon" style="position:relative; width:20px; height:11px; border: 1.5px solid currentColor; border-radius:3.3px; padding:1.2px; display:flex; align-items:center; margin-left: 2px;">
            <div id="status-battery-fill" style="width:100%; height:100%; background-color:currentColor; border-radius:1px; transition:width 0.3s, background-color:0.3s;"></div>
            <div style="position:absolute; right:-3px; top:2.5px; width:1.5px; height:3.5px; background-color:currentColor; border-top-right-radius:1px; border-bottom-right-radius:1px;"></div>
          </div>
        </div>
      </div>

      <div class="scrollable-screen-area">
        
        <div class="dashboard-widget">
          <div class="dash-header">
            <div>
              <div class="dash-greeting" id="dash-greeting">Hello, Explorer</div>
              <div class="dash-subtitle">System Status: Nominal</div>
            </div>
            <div style="display:flex; gap:8px;">
              <div class="settings-trigger" id="search-trigger" onclick="portalToggleSearch()" title="Search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px; height:16px;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              </div>
              <div class="settings-trigger" id="edit-mode-trigger" onclick="portalToggleEditMode()" title="Edit Layout">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px; height:16px;"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
              </div>
              <div class="settings-trigger" id="settings-trigger" title="Settings">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
              </div>
            </div>
          </div>
          <div class="stats-row">
            <div class="stat-item">
              <div class="stat-value" id="stat-apps-count"><?php
echo $totalApps; ?></div>
              <div class="stat-label">Apps</div>
            </div>
            <div class="stat-item">
              <div class="stat-value" id="stat-folders"><?php
echo $totalFolders; ?></div>
              <div class="stat-label">Folders</div>
            </div>
            <div class="stat-item">
              <div class="stat-value" id="stat-storage"><?php
echo $storagePct; ?></div>
              <div class="stat-label">Storage</div>
            </div>
          </div>
        </div>

        <div class="portal-search-container" id="portal-search-container" style="display:none; margin-bottom: 20px; animation: portalSearchSlideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1);">
          <div style="position:relative; display:flex; align-items:center; background:var(--card-bg); border:1px solid var(--card-border); border-radius:18px; padding:12px 16px; backdrop-filter:blur(12px); box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:18px; height:18px; margin-right:12px;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" id="portal-search-input" placeholder="Search apps..." style="background:transparent; border:none; outline:none; color:var(--text-primary); font-size:15px; width:100%; font-weight:600;">
            <button id="portal-search-clear" onclick="portalClearSearch()" style="background:none; border:none; color:var(--text-secondary); cursor:pointer; display:none; padding:4px; margin-left:8px; outline:none;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:18px; height:18px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
          </div>
        </div>

        <div class="app-grid grid-cols-4" id="main-app-grid">
          <?php
foreach ($gridItems as $item) render_app_item($item, false); ?>
        </div>

      </div>

      <div class="dock-anchor">
        <div class="dock-grid dock-glow" id="dock-app-grid">
          <?php
for ($i = 0; $i < 4; $i++) {
              if (isset($dockItems[$i])) {
                  render_app_item($dockItems[$i], true);
              } else {
                  echo '<div class="app-item empty-slot dock-slot" data-slot="'.$i.'"><div class="icon-container empty-icon"></div></div>';
              }
          }
          ?>
        </div>
        <div class="home-indicator-bar" id="home-indicator-bar"></div>
      </div>

      <div class="panel-backdrop" id="panel-backdrop"></div>

      <div class="control-panel" id="control-panel">
        <div class="panel-header">
          <span class="panel-title">Launcher Panel</span>
          <span class="panel-close" id="panel-close">&times;</span>
        </div>
        
        <div class="panel-body">
          
          <!-- SECTION 1: SYSTEM CONTROLS -->
          <div class="panel-section">
            <span class="panel-section-header">System Controls</span>
            <div class="panel-section-card">
              <div class="setting-control">
                <span>Light/Paper Mode</span>
                <label class="switch">
                  <input type="checkbox" id="theme-toggle">
                  <span class="slider"></span>
                </label>
              </div>
              <div class="setting-control">
                <span>Interface Synthesizer</span>
                <label class="switch">
                  <input type="checkbox" id="audio-toggle" checked>
                  <span class="slider"></span>
                </label>
              </div>
              <div class="setting-control">
                <span>Show Phone Wrapper</span>
                <label class="switch">
                  <input type="checkbox" id="frame-toggle" checked>
                  <span class="slider"></span>
                </label>
              </div>
              <div class="setting-control">
                <span>Prefer Native App</span>
                <label class="switch">
                  <input type="checkbox" id="inapp-toggle" checked>
                  <span class="slider"></span>
                </label>
              </div>
            </div>
          </div>

          <!-- SECTION 2: WORKSPACE LAYOUT -->
          <div class="panel-section">
            <span class="panel-section-header">Workspace Layout</span>
            <div class="panel-section-card">
              <div class="setting-control" style="flex-direction: column; align-items: flex-start; gap: 8px;">
                <span style="font-size: 11px; margin-bottom: 4px;">Columns Density</span>
                <div class="chip-container">
                  <div class="chip active" id="cols-btn-4">Dense (4 cols)</div>
                  <div class="chip" id="cols-btn-3">Comfort (3 cols)</div>
                </div>
              </div>
              <div class="setting-control" style="flex-direction: column; align-items: flex-start; gap: 6px;">
                <div style="display: flex; justify-content: space-between; width: 100%;">
                  <span>Icon Sizing</span>
                  <span id="range-val-icon">54px</span>
                </div>
                <input type="range" id="icon-size-range" min="40" max="64" value="54" style="width:100%;">
              </div>
              <div class="setting-control">
                <span>Show App Names</span>
                <label class="switch">
                  <input type="checkbox" id="dock-labels-toggle">
                  <span class="slider"></span>
                </label>
              </div>
              <div class="setting-control">
                <span>Show Light-Mode Backdrops</span>
                <label class="switch">
                  <input type="checkbox" id="icon-backdrops-toggle" checked>
                  <span class="slider"></span>
                </label>
              </div>
            </div>
          </div>

          <!-- SECTION 3: EDIT MODE INDICATORS -->
          <div class="panel-section">
            <span class="panel-section-header">Edit Mode Indicators</span>
            <div class="panel-section-card">
              <div class="setting-control" style="flex-direction: column; align-items: flex-start; gap: 6px;">
                <div style="display: flex; justify-content: space-between; width: 100%;">
                  <span>Insertion Line Length</span>
                  <span id="range-val-line">16px</span>
                </div>
                <input type="range" id="line-length-range" min="0" max="40" value="16" style="width:100%;">
              </div>
              <div class="setting-control" style="flex-direction: column; align-items: flex-start; gap: 6px;">
                <div style="display: flex; justify-content: space-between; width: 100%;">
                  <span>Dotted Box Size</span>
                  <span id="range-val-box">2px</span>
                </div>
                <input type="range" id="box-size-range" min="0" max="12" value="2" style="width:100%;">
              </div>
            </div>
          </div>

          <!-- SECTION 4: ATMOSPHERE & VIEWPORTS -->
          <div class="panel-section">
            <span class="panel-section-header">Atmosphere & Viewports</span>
            <div class="panel-section-card">
              <div class="setting-control" style="flex-direction: column; align-items: flex-start; gap: 6px;">
                <div style="display: flex; justify-content: space-between; width: 100%;">
                  <span>Wallpaper Blur</span>
                  <span id="range-val-blur">40px</span>
                </div>
                <input type="range" id="blur-slider" min="0" max="80" value="40" style="width:100%;">
              </div>
              <div class="setting-control">
                <span>Disable Wallpaper</span>
                <label class="switch">
                  <input type="checkbox" id="disable-wallpaper-toggle">
                  <span class="slider"></span>
                </label>
              </div>
              <div class="setting-control">
                <span>Hide Status Bar in Apps</span>
                <label class="switch">
                  <input type="checkbox" id="hide-status-toggle">
                  <span class="slider"></span>
                </label>
              </div>
            </div>
          </div>

        </div> <!-- End panel-body -->
      </div>

      <div class="context-backdrop" id="context-backdrop"></div>
      <div class="context-menu" id="custom-context-menu"></div>

      <!-- Custom Folder Input Modal -->
      <div id="portal-input-modal" class="panel-backdrop flex-center" style="z-index: 9999;">
        <div class="custom-modal-panel">
          <div class="modal-title">New Folder</div>
          <input type="text" id="portal-folder-name-input" placeholder="Enter folder name..." class="modal-text-input">
          <div class="modal-footer">
            <button onclick="portalCloseInputModal()" class="modal-btn cancel-btn">Cancel</button>
            <button onclick="portalCommitModalAction()" class="modal-btn confirm-btn">Create</button>
          </div>
        </div>
      </div>

      <!-- iOS-Style Folder Overlay -->
      <div class="folder-overlay" id="portal-folder-overlay">
        <div class="folder-panel" id="portal-folder-panel">
          <div class="folder-header">
            <input type="text" id="portal-folder-name" class="folder-name-input" spellcheck="false" autocomplete="off">
          </div>
          <div class="folder-body" id="folder-app-grid">
            <!-- Icons injected here -->
          </div>
        </div>
      </div>

      <div class="app-viewport" id="app-viewport">
        <div class="app-viewport-header">
          <button class="back-btn" id="app-back-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back
          </button>
          <div style="display:flex; align-items:center; gap:6px;">
            <span class="viewport-title" id="app-viewport-title">Folder</span>
            <button id="folder-rename-btn" style="background:none; border:none; color:var(--text-secondary); cursor:pointer; padding:4px; display:flex; align-items:center; justify-content:center; outline:none; transition:color 0.2s;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            </button>
          </div>
          <div style="width: 16px;"></div>
        </div>
        <div class="app-viewport-body" id="app-viewport-body">
        </div>
      </div>

      <div class="app-viewport" id="app-launch-viewport" style="z-index: 250;">
        <div class="app-viewport-header">
          <button class="back-btn" id="app-launch-back-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back
          </button>
          <span class="viewport-title" id="app-launch-title">App Sandbox</span>
          <div style="width: 16px;"></div>
        </div>
        <div class="app-viewport-body" id="app-launch-body" style="padding:0; overflow:hidden;">
        </div>
      </div>

    </div>
  </div>

  <div id="toast" class="toast"></div>

  <script>
    window.CJOS_AM_CONFIG = <?php
echo json_encode($config ?? []); ?>;
    window.CJOS_RUNTIME = <?php
echo json_encode(get_runtime_info()); ?>;
    window.CJOS_APPS = <?php
echo json_encode($apps); ?>;
    window.CJOS_FOLDERS = <?php
echo json_encode($folders); ?>;
    window.CJOS_IPS = {
        tailscale_domain: <?php
echo json_encode($config['tailscale_domain'] ?? null); ?>,
        lan: <?php
echo json_encode(get_lan_ip()); ?>,
        tailscale: <?php
echo json_encode(get_tailscale_ip()); ?>
    };
    
    if (window.location.protocol === 'http:') {
        const port = window.location.port || '80';
        fetch('../../index.php?plugin_action=am_report_port&port=' + port).catch(() => {});
    }
  </script>
  <script src="js/app.js?v=<?php
echo $v; ?>"></script>




</body>
</html>