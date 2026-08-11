<?php
class PWAScanner {
    private $appsDir;

    public function __construct($appsDir) {
        $this->appsDir = $appsDir;
    }

    public function scanApps() {
        $apps = [];
        if (!is_dir($this->appsDir)) return $apps;

        $iterator = new DirectoryIterator($this->appsDir);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot() || !$fileinfo->isDir()) continue;
            
            $appName = $fileinfo->getFilename();

            $appPath = $fileinfo->getPathname();
            $indexPath = $appPath . '/index.php';
            $manifestPath = $appPath . '/manifest.json';

            $appData = [
                'folder' => $appName,
                'name' => $appName,
                'has_index' => file_exists($indexPath),
                'has_manifest' => file_exists($manifestPath),
                'icon' => '📦',
                'color' => '#E5E7EB',
                'pwa_meta' => false,
                'pwa_touch_icon' => false,
                'pwa_manifest_link' => false,
                'manifest_standalone' => false,
                'manifest_icons' => false
            ];

            if ($appData['has_manifest']) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                if ($manifest) {
                    if (isset($manifest['name'])) $appData['name'] = $manifest['name'];
                    if (isset($manifest['icon'])) {
                        $appData['icon'] = $manifest['icon'];
                    } elseif (isset($manifest['icons']) && is_array($manifest['icons']) && !empty($manifest['icons'])) {
                        $iconSrc = $manifest['icons'][0]['src'] ?? null;
                        if ($iconSrc && file_exists($appPath . '/' . $iconSrc)) {
                            $iconContent = file_get_contents($appPath . '/' . $iconSrc);
                            if (strpos(trim($iconContent), '<svg') === 0) {
                                $appData['icon'] = trim($iconContent);
                            }
                        }
                    }
                    if (isset($manifest['color'])) {
                        $appData['color'] = $manifest['color'];
                    } elseif (isset($manifest['theme_color'])) {
                        $appData['color'] = $manifest['theme_color'];
                    } elseif (isset($manifest['background_color'])) {
                        $appData['color'] = $manifest['background_color'];
                    }
                    if (isset($manifest['display']) && $manifest['display'] === 'standalone') $appData['manifest_standalone'] = true;
                    if (isset($manifest['icons']) && !empty($manifest['icons'])) $appData['manifest_icons'] = true;
                }
            }

            if ($appData['has_index']) {
                $indexContent = file_get_contents($indexPath);
                if (stripos($indexContent, 'apple-mobile-web-app-capable') !== false) $appData['pwa_meta'] = true;
                if (stripos($indexContent, 'apple-touch-icon') !== false) $appData['pwa_touch_icon'] = true;
                if (stripos($indexContent, 'rel="manifest"') !== false) $appData['pwa_manifest_link'] = true;
                if (stripos($indexContent, 'rel="icon"') !== false && (stripos($indexContent, 'image/svg+xml') !== false || stripos($indexContent, 'icon.svg') !== false)) $appData['pwa_tab_icon'] = true;
            }

            // Calculate Progressive Capabilities
            $backupDir = dirname(__DIR__) . '/data/backups/' . $appName;
            $appData['is_fullscreen'] = ($appData['pwa_meta'] && $appData['manifest_standalone']);
            $appData['is_high_res'] = ($appData['pwa_touch_icon'] && $appData['manifest_icons']);
            $appData['is_tab_icon'] = $appData['pwa_tab_icon'];
            $appData['is_backup_safe'] = file_exists($backupDir . '/index.php.bak');

            $apps[] = $appData;
        }
        
        // Sort alphabetically
        usort($apps, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $apps;
    }
}
?>