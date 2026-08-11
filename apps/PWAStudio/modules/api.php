<?php
header('Content-Type: application/json');
$action = $_GET['action'] ?? '';
$dataDir = __DIR__ . '/../data/variants';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

function ensure_backup_dir($appName) {
    $parentDir = __DIR__ . '/../data/backups';
    if (!is_dir($parentDir)) {
        mkdir($parentDir, 0777, true);
    }
    $orbitIgnore = $parentDir . '/.orbitignore';
    $gitIgnore = $parentDir . '/.gitignore';
    if (!file_exists($orbitIgnore)) {
        file_put_contents($orbitIgnore, "*\n");
    }
    if (!file_exists($gitIgnore)) {
        file_put_contents($gitIgnore, "*\n");
    }
    $appBackupDir = $parentDir . '/' . $appName;
    if (!is_dir($appBackupDir)) {
        mkdir($appBackupDir, 0777, true);
    }
    return $appBackupDir;
}

if ($action === 'get_cdn_index') {
    $dbName = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['db'] ?? 'Lucide');
    
    if ($dbName === 'Local') {
        $localDir = __DIR__ . '/../data/local_svgs';
        $icons = [];
        if (is_dir($localDir)) {
            foreach (glob($localDir . '/*.svg') as $file) {
                $icons[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
        echo json_encode(['success' => true, 'icons' => $icons]);
        exit;
    }

    $registryUrls = [
        'Lucide' => 'https://unpkg.com/lucide-static@latest/icons/',
        'Feather' => 'https://unpkg.com/feather-icons/dist/icons/',
        'Phosphor' => 'https://unpkg.com/phosphor-icons@1.4.2/src/regular/'
    ];
    
    if (!isset($registryUrls[$dbName])) {
        echo json_encode(['error' => 'Invalid database']);
        exit;
    }
    
    $url = $registryUrls[$dbName];
    $cacheFile = __DIR__ . '/../data/cdn_cache_v2_' . $dbName . '.json';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        echo file_get_contents($cacheFile);
        exit;
    }
    
    $context = stream_context_create([
        'http' => [
            'ignore_errors' => true, 
            'timeout' => 10,
            'header' => "User-Agent: ConjureOS/1.0\r\n"
        ]
    ]);
    $html = @file_get_contents($url, false, $context);
    
    $icons = [];
    if ($html) {
        if (preg_match_all('/href="[^"]*?([^\/"]+)\.svg"/', $html, $matches)) {
            $icons = array_values(array_unique($matches[1]));
        }
    }
    
    if (!empty($icons)) {
        $payload = json_encode(['success' => true, 'icons' => $icons]);
        file_put_contents($cacheFile, $payload);
        echo $payload;
    } else {
        if (file_exists($cacheFile)) {
            echo file_get_contents($cacheFile);
        } else {
            echo json_encode(['error' => 'Failed to fetch CDN index']);
        }
    }
    exit;
}

if ($action === 'upload_local_svg') {
    $localDir = __DIR__ . '/../data/local_svgs';
    if (!is_dir($localDir)) mkdir($localDir, 0777, true);
    
    if (!isset($_FILES['svg_file']) || $_FILES['svg_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Upload failed']);
        exit;
    }
    
    $file = $_FILES['svg_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'svg') {
        echo json_encode(['error' => 'Only SVG files are allowed']);
        exit;
    }
    
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = strtolower(trim($filename, '-')) . '.svg';
    
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $counter = 1;
    while (file_exists($localDir . '/' . $filename)) {
        $filename = $base . '-' . $counter . '.svg';
        $counter++;
    }
    
    move_uploaded_file($file['tmp_name'], $localDir . '/' . $filename);
    echo json_encode(['success' => true, 'filename' => pathinfo($filename, PATHINFO_FILENAME)]);
    exit;
}

if ($action === 'save_draft') {
    $input = json_decode(file_get_contents('php://input'), true);
    $appName = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['appName'] ?? '');
    $draft = $input['draft'] ?? [];
    
    if (!$appName) {
        echo json_encode(['error' => 'Invalid app name']);
        exit;
    }

    $appDir = $dataDir . '/' . $appName;
    if (!is_dir($appDir)) mkdir($appDir, 0777, true);
    
    $draftsFile = $appDir . '/metadata.json';
    $drafts = file_exists($draftsFile) ? json_decode(file_get_contents($draftsFile), true) : [];
    
    array_unshift($drafts, $draft); // Add to top
    file_put_contents($draftsFile, json_encode($drafts, JSON_PRETTY_PRINT));
    
    echo json_encode(['success' => true, 'drafts' => $drafts]);
    exit;
}

if ($action === 'get_drafts') {
    $appName = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['app'] ?? '');
    $presetsFile = $dataDir . '/../presets.json';
    $presets = file_exists($presetsFile) ? json_decode(file_get_contents($presetsFile), true) : [];
    
    if (!$appName) {
        echo json_encode(['success' => true, 'drafts' => [], 'presets' => $presets]);
        exit;
    }
    
    $draftsFile = $dataDir . '/' . $appName . '/metadata.json';
    $drafts = file_exists($draftsFile) ? json_decode(file_get_contents($draftsFile), true) : [];
    
    echo json_encode(['success' => true, 'drafts' => $drafts, 'presets' => $presets]);
    exit;
}

if ($action === 'save_preset') {
    $input = json_decode(file_get_contents('php://input'), true);
    $preset = $input['preset'] ?? [];
    
    $presetsFile = $dataDir . '/../presets.json';
    $presets = file_exists($presetsFile) ? json_decode(file_get_contents($presetsFile), true) : [];
    
    array_unshift($presets, $preset);
    file_put_contents($presetsFile, json_encode($presets, JSON_PRETTY_PRINT));
    
    echo json_encode(['success' => true, 'presets' => $presets]);
    exit;
}

if ($action === 'delete_preset') {
    $input = json_decode(file_get_contents('php://input'), true);
    $presetId = (int)($input['presetId'] ?? 0);
    
    $presetsFile = $dataDir . '/../presets.json';
    if (file_exists($presetsFile)) {
        $presets = json_decode(file_get_contents($presetsFile), true);
        if (is_array($presets)) {
            $initialCount = count($presets);
            $presets = array_values(array_filter($presets, function($p) use ($presetId) {
                return (int)($p['id'] ?? 0) !== $presetId;
            }));
            if (count($presets) < $initialCount) {
                file_put_contents($presetsFile, json_encode($presets, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'presets' => $presets]);
                exit;
            }
        }
    }
    
    echo json_encode(['error' => 'Preset not found']);
    exit;
}

if ($action === 'delete_draft') {
    $input = json_decode(file_get_contents('php://input'), true);
    $appName = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['appName'] ?? '');
    $draftId = (int)($input['draftId'] ?? 0);
    
    if (!$appName || !$draftId) {
        echo json_encode(['error' => 'Missing app name or draft ID']);
        exit;
    }

    $appDir = $dataDir . '/' . $appName;
    $draftsFile = $appDir . '/metadata.json';
    
    if (file_exists($draftsFile)) {
        $drafts = json_decode(file_get_contents($draftsFile), true);
        if (is_array($drafts)) {
            $initialCount = count($drafts);
            $drafts = array_values(array_filter($drafts, function($d) use ($draftId) {
                return (int)($d['id'] ?? 0) !== $draftId;
            }));
            if (count($drafts) < $initialCount) {
                file_put_contents($draftsFile, json_encode($drafts, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'drafts' => $drafts]);
                exit;
            }
        }
    }
    
    echo json_encode(['error' => 'Draft not found']);
    exit;
}

if ($action === 'scan_single_app') {
    $appName = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['app'] ?? '');
    if (!$appName) {
        echo json_encode(['error' => 'Missing app name']);
        exit;
    }
    require_once __DIR__ . '/scanner.php';
    $scanner = new PWAScanner(realpath(__DIR__ . '/../../'));
    $apps = $scanner->scanApps();
    foreach ($apps as $app) {
        if ($app['folder'] === $appName) {
            echo json_encode(['success' => true, 'app' => $app]);
            exit;
        }
    }
    echo json_encode(['error' => 'App not found']);
    exit;
}

if ($action === 'save_emojis') {
    $input = json_decode(file_get_contents('php://input'), true);
    $emojis = $input['emojis'] ?? [];
    if (!is_array($emojis)) {
        echo json_encode(['error' => 'Invalid emoji list']);
        exit;
    }
        
    $settingsFile = __DIR__ . '/../data/settings.json';
    $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
    $settings['emojis'] = $emojis;
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'save_colors') {
    $input = json_decode(file_get_contents('php://input'), true);
    $colors = $input['colors'] ?? [];
    if (!is_array($colors)) {
        echo json_encode(['error' => 'Invalid color list']);
        exit;
    }
        
    $settingsFile = __DIR__ . '/../data/settings.json';
    $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
    $settings['colors'] = $colors;
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'save_secure_press') {
    $input = json_decode(file_get_contents('php://input'), true);
    $enabled = isset($input['securePressEnabled']) ? (bool)$input['securePressEnabled'] : true;
    
    $settingsFile = __DIR__ . '/../data/settings.json';
    $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
    $settings['securePressEnabled'] = $enabled;
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
    exit;
}if ($action === 'restore_backup') {
    $input = json_decode(file_get_contents('php://input'), true);
    $appName = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['appName'] ?? '');
        
    if (!$appName) {
        echo json_encode(['error' => 'Missing app name']);
        exit;
    }

    $appDir = realpath(__DIR__ . '/../../') . '/' . $appName;
    $indexPath = $appDir . '/index.php';
    $manifestPath = $appDir . '/manifest.json';
    $iconPath = $appDir . '/icon.svg';

    $backupDir = __DIR__ . '/../data/backups/' . $appName;
    $bakPath = $backupDir . '/index.php.bak';
    $manifestBak = $backupDir . '/manifest.json.bak';
    $iconBak = $backupDir . '/icon.svg.bak';
    $hashesPath = $backupDir . '/hashes.json';
        
    if (!is_dir($backupDir)) {
        echo json_encode(['error' => 'No backup directory found for this app']);
        exit;
    }

    // Safety Hash Check: Verify if final files have been edited since PWA Studio patched them
    if (file_exists($hashesPath)) {
        $hashes = json_decode(file_get_contents($hashesPath), true);
        if (is_array($hashes)) {
            if (isset($hashes['index.php']) && file_exists($indexPath)) {
                if (md5_file($indexPath) !== $hashes['index.php']) {
                    echo json_encode(['error' => 'index.php has been modified since it was patched by PWA Studio. Restoration aborted to protect your custom edits.']);
                    exit;
                }
            }
            if (isset($hashes['manifest.json']) && file_exists($manifestPath)) {
                if (md5_file($manifestPath) !== $hashes['manifest.json']) {
                    echo json_encode(['error' => 'manifest.json has been modified since it was patched by PWA Studio. Restoration aborted to protect your custom edits.']);
                    exit;
                }
            }
            if (isset($hashes['icon.svg']) && file_exists($iconPath)) {
                if (md5_file($iconPath) !== $hashes['icon.svg']) {
                    echo json_encode(['error' => 'icon.svg has been modified since it was patched by PWA Studio. Restoration aborted to protect your custom edits.']);
                    exit;
                }
            }
        }
    }

    $success = false;
        
    // 1. Revert index.php
    if (file_exists($bakPath)) {
        if (copy($bakPath, $indexPath)) {
            unlink($bakPath);
            $success = true;
        }
    }
        
    // 2. Revert manifest.json
    if (file_exists($manifestBak)) {
        if (copy($manifestBak, $manifestPath)) {
            unlink($manifestBak);
            $success = true;
        }
    }
        
    // 3. Revert or clean up icon.svg
    if (file_exists($iconBak)) {
        if (copy($iconBak, $iconPath)) {
            unlink($iconBak);
            $success = true;
        }
    } else {
        // If there was no original icon.svg before we ran PWA Studio, safely clean it up
        if (file_exists($iconPath)) {
            unlink($iconPath);
            $success = true;
        }
    }
    
    // Clean up hashes file and backups folder
    if (file_exists($hashesPath)) {
        unlink($hashesPath);
    }
    @rmdir($backupDir);
        
    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'No backup files found to restore']);
    }
    exit;
}if ($action === 'apply_pwa') {
    $input = json_decode(file_get_contents('php://input'), true);
    $appName = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['appName'] ?? '');
    $svgContent = $input['svgContent'] ?? '';
    $bgColor = $input['bgColor'] ?? '#E5E7EB';
    
    if (!$appName || !$svgContent) {
        echo json_encode(['error' => 'Missing app name or SVG content']);
        exit;
    }

    $appDir = realpath(__DIR__ . '/../../') . '/' . $appName;
    if (!is_dir($appDir)) {
        echo json_encode(['error' => 'App directory not found']);
        exit;
    }

    // Ensure central backups directory is ready and has ignore files
    $backupDir = ensure_backup_dir($appName);

    // 1. Write icon.svg (Protect original file with central backup)
    $iconPath = $appDir . '/icon.svg';
    $iconBak = $backupDir . '/icon.svg.bak';
    if (file_exists($iconPath) && !file_exists($iconBak)) {
        copy($iconPath, $iconBak);
    }
    file_put_contents($iconPath, $svgContent);

    $v = time(); // Cache buster for aggressive mobile browsers

    // 2. Update manifest.json (Protect original file with central backup)
    $manifestPath = $appDir . '/manifest.json';
    $manifestBak = $backupDir . '/manifest.json.bak';
    if (file_exists($manifestPath) && !file_exists($manifestBak)) {
        copy($manifestPath, $manifestBak);
    }
    $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
    if (!is_array($manifest)) $manifest = [];
    
    $manifest['display'] = 'standalone';
    $manifest['theme_color'] = $bgColor;
    $manifest['background_color'] = $bgColor;
    $manifest['color'] = $bgColor; // Sync Conjure OS ecosystem color
    $manifest['icon'] = $svgContent; // Sync Conjure OS ecosystem icon
    $manifest['icons'] = [
    [
        "src" => "icon.svg?v={$v}",
        "sizes" => "512x512",
        "type" => "image/svg+xml",
        "purpose" => "any"
    ]
];file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // 3. Patch index.php (Defensive Injection — Protect original file with central backup)
$indexPath = $appDir . '/index.php';
$indexBak = $backupDir . '/index.php.bak';
if (file_exists($indexPath)) {
    if (!file_exists($indexBak)) {
        copy($indexPath, $indexBak);
    }
    $html = file_get_contents($indexPath);

    if (preg_match('/<meta\s+name=["\']viewport["\']\s+content=["\']([^"\']+)["\']\s*\/?>/i', $html, $vpMatch)) {
        if (strpos($vpMatch[1], 'viewport-fit=cover') === false) {
            $newVpContent = rtrim($vpMatch[1], ';, ') . ', viewport-fit=cover';
            $html = preg_replace('/<meta\s+name=["\']viewport["\']\s+content=["\']([^"\']+)["\']\s*\/?>/i', '<meta name="viewport" content="' . $newVpContent . '">', $html);
        }
        $vpInjected = "";
    } else {
        $vpInjected = "\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover\">";
    }
            
    $injection = "<!-- CONJURE_PWA_START -->" . $vpInjected . "\n" .
             "  <meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n" .
             "  <meta name=\"apple-mobile-web-app-status-bar-style\" content=\"default\">\n" .
             "  <meta name=\"apple-mobile-web-app-title\" content=\"{$appName}\">\n" .
             "  <meta name=\"theme-color\" content=\"{$bgColor}\">\n" .
             "  <link rel=\"apple-touch-icon\" href=\"icon.svg?v={$v}\">\n" .
             "  <link rel=\"icon\" type=\"image/svg+xml\" href=\"icon.svg?v={$v}\">\n" .
             "  <link rel=\"manifest\" href=\"manifest.json?v={$v}\">\n" .
             "  <!-- CONJURE_PWA_END -->";if (preg_match('/<!-- CONJURE_PWA_START -->.*?<!-- CONJURE_PWA_END -->/s', $html)) {
        $html = preg_replace('/<!-- CONJURE_PWA_START -->.*?<!-- CONJURE_PWA_END -->/s', $injection, $html);
    } else {
        $html = str_ireplace('</head>', "  " . $injection . "\n</head>", $html);
    }
    file_put_contents($indexPath, $html);
}
// 4. Calculate hashes of newly created/patched files (final files)$hashesPath = $backupDir . '/hashes.json';
    $hashes = [
        'index.php' => file_exists($indexPath) ? md5_file($indexPath) : null,
        'manifest.json' => file_exists($manifestPath) ? md5_file($manifestPath) : null,
        'icon.svg' => file_exists($iconPath) ? md5_file($iconPath) : null,
    ];
    file_put_contents($hashesPath, json_encode($hashes, JSON_PRETTY_PRINT));

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'apply_batch_pwa') {
    $input = json_decode(file_get_contents('php://input'), true);
    $appsData = $input['apps'] ?? [];
    
    if (empty($appsData)) {
        echo json_encode(['error' => 'No apps provided']);
        exit;
    }

    $batchId = time();
    $batchDir = __DIR__ . '/../data/batch_backups/' . $batchId;
    mkdir($batchDir, 0777, true);

    $meta = [
        'date' => date('Y-m-d H:i:s', $batchId),
        'apps' => []
    ];
    $hashes = [];

    foreach ($appsData as $appData) {
        $appName = preg_replace('/[^a-zA-Z0-9_-]/', '', $appData['appName']);
        $svgContent = $appData['svgContent'];
        $bgColor = $appData['bgColor'];
        
        $appDir = realpath(__DIR__ . '/../../') . '/' . $appName;
        if (!is_dir($appDir)) continue;

        $meta['apps'][] = $appName;
        $appBackupDir = $batchDir . '/' . $appName;
        mkdir($appBackupDir, 0777, true);

        $indexPath = $appDir . '/index.php';
        $manifestPath = $appDir . '/manifest.json';
        $iconPath = $appDir . '/icon.svg';

        if (file_exists($indexPath)) copy($indexPath, $appBackupDir . '/index.php.bak');
        if (file_exists($manifestPath)) copy($manifestPath, $appBackupDir . '/manifest.json.bak');
        if (file_exists($iconPath)) copy($iconPath, $appBackupDir . '/icon.svg.bak');

        file_put_contents($iconPath, $svgContent);
        
        $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
        if (!is_array($manifest)) $manifest = [];
        $manifest['display'] = 'standalone';
        $manifest['theme_color'] = $bgColor;
        $manifest['background_color'] = $bgColor;
        $manifest['color'] = $bgColor;
        $manifest['icon'] = $svgContent;
        $manifest['icons'] = [[
            "src" => "icon.svg?v={$batchId}",
            "sizes" => "512x512",
            "type" => "image/svg+xml",
            "purpose" => "any"
        ]];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (file_exists($indexPath)) {
            $html = file_get_contents($indexPath);

            if (preg_match('/<meta\s+name=["\']viewport["\']\s+content=["\']([^"\']+)["\']\s*\/?>/i', $html, $vpMatch)) {
                if (strpos($vpMatch[1], 'viewport-fit=cover') === false) {
                    $newVpContent = rtrim($vpMatch[1], ';, ') . ', viewport-fit=cover';
                    $html = preg_replace('/<meta\s+name=["\']viewport["\']\s+content=["\']([^"\']+)["\']\s*\/?>/i', '<meta name="viewport" content="' . $newVpContent . '">', $html);
                }
                $vpInjected = "";
            } else {
                $vpInjected = "\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover\">";
            }

            $injection = "<!-- CONJURE_PWA_START -->" . $vpInjected . "\n" .
                         "  <meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n" .
                         "  <meta name=\"apple-mobile-web-app-status-bar-style\" content=\"default\">\n" .
                         "  <meta name=\"apple-mobile-web-app-title\" content=\"{$appName}\">\n" .
                         "  <meta name=\"theme-color\" content=\"{$bgColor}\">\n" .
                         "  <link rel=\"apple-touch-icon\" href=\"icon.svg?v={$batchId}\">\n" .
                         "  <link rel=\"icon\" type=\"image/svg+xml\" href=\"icon.svg?v={$batchId}\">\n" .
                         "  <link rel=\"manifest\" href=\"manifest.json?v={$batchId}\">\n" .
                         "  <!-- CONJURE_PWA_END -->";
            if (preg_match('/<!-- CONJURE_PWA_START -->.*?<!-- CONJURE_PWA_END -->/s', $html)) {
                $html = preg_replace('/<!-- CONJURE_PWA_START -->.*?<!-- CONJURE_PWA_END -->/s', $injection, $html);
            } else {
                $html = str_ireplace('</head>', "  " . $injection . "\n</head>", $html);
            }
            file_put_contents($indexPath, $html);
        }

        $hashes[$appName] = [
            'index.php' => file_exists($indexPath) ? md5_file($indexPath) : null,
            'manifest.json' => file_exists($manifestPath) ? md5_file($manifestPath) : null,
            'icon.svg' => file_exists($iconPath) ? md5_file($iconPath) : null,
        ];
    }

    file_put_contents($batchDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));
    file_put_contents($batchDir . '/hashes.json', json_encode($hashes, JSON_PRETTY_PRINT));

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'get_batch_backups') {
    $backupsDir = __DIR__ . '/../data/batch_backups';
    $backups = [];
    if (is_dir($backupsDir)) {
        foreach (scandir($backupsDir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $batchDir = $backupsDir . '/' . $item;
            if (is_dir($batchDir) && file_exists($batchDir . '/meta.json')) {
                $meta = json_decode(file_get_contents($batchDir . '/meta.json'), true);
                $backups[] = [
                    'id' => $item,
                    'date' => $meta['date'] ?? '',
                    'appCount' => count($meta['apps'] ?? [])
                ];
            }
        }
    }
    usort($backups, function($a, $b) {
        return $b['id'] <=> $a['id'];
    });
    echo json_encode(['success' => true, 'backups' => $backups]);
    exit;
}

if ($action === 'delete_batch_backup') {
    $input = json_decode(file_get_contents('php://input'), true);
    $batchId = preg_replace('/[^0-9]/', '', $input['batchId'] ?? '');
    if (!$batchId) {
        echo json_encode(['error' => 'Missing batch ID']);
        exit;
    }
    $batchDir = __DIR__ . '/../data/batch_backups/' . $batchId;
    if (is_dir($batchDir)) {
        $it = new RecursiveDirectoryIterator($batchDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($batchDir);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'restore_batch_backup') {
    $input = json_decode(file_get_contents('php://input'), true);
    $batchId = preg_replace('/[^0-9]/', '', $input['batchId'] ?? '');
    $skipDrifted = $input['skipDrifted'] ?? false;
    
    if (!$batchId) {
        echo json_encode(['error' => 'Missing batch ID']);
        exit;
    }

    $batchDir = __DIR__ . '/../data/batch_backups/' . $batchId;
    if (!is_dir($batchDir)) {
        echo json_encode(['error' => 'Batch backup not found']);
        exit;
    }

    $meta = json_decode(file_get_contents($batchDir . '/meta.json'), true);
    $hashes = json_decode(file_get_contents($batchDir . '/hashes.json'), true);
    
    $driftedApps = [];
    $appsToRestore = [];

    foreach ($meta['apps'] as $appName) {
        $appDir = realpath(__DIR__ . '/../../') . '/' . $appName;
        $indexPath = $appDir . '/index.php';
        $manifestPath = $appDir . '/manifest.json';
        $iconPath = $appDir . '/icon.svg';

        $appHashes = $hashes[$appName] ?? [];
        $isDrifted = false;

        if (isset($appHashes['index.php']) && file_exists($indexPath) && md5_file($indexPath) !== $appHashes['index.php']) $isDrifted = true;
        if (isset($appHashes['manifest.json']) && file_exists($manifestPath) && md5_file($manifestPath) !== $appHashes['manifest.json']) $isDrifted = true;
        if (isset($appHashes['icon.svg']) && file_exists($iconPath) && md5_file($iconPath) !== $appHashes['icon.svg']) $isDrifted = true;

        if ($isDrifted) {
            $driftedApps[] = $appName;
        } else {
            $appsToRestore[] = $appName;
        }
    }

    if (!empty($driftedApps) && !$skipDrifted) {
        echo json_encode([
            'requires_confirmation' => true,
            'drifted_apps' => $driftedApps
        ]);
        exit;
    }

    foreach ($appsToRestore as $appName) {
        $appDir = realpath(__DIR__ . '/../../') . '/' . $appName;
        $appBackupDir = $batchDir . '/' . $appName;

        if (file_exists($appBackupDir . '/index.php.bak')) {
            copy($appBackupDir . '/index.php.bak', $appDir . '/index.php');
        }
        if (file_exists($appBackupDir . '/manifest.json.bak')) {
            copy($appBackupDir . '/manifest.json.bak', $appDir . '/manifest.json');
        }
        if (file_exists($appBackupDir . '/icon.svg.bak')) {
            copy($appBackupDir . '/icon.svg.bak', $appDir . '/icon.svg');
        } else {
            if (file_exists($appDir . '/icon.svg')) unlink($appDir . '/icon.svg');
        }
    }

    if (empty($driftedApps)) {
        $it = new RecursiveDirectoryIterator($batchDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($batchDir);
    }

    echo json_encode(['success' => true, 'restored' => $appsToRestore, 'skipped' => $driftedApps]);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
?>