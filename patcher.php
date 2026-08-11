<?php
// ==============================================================================
// EMERGENCY PATCHER MODE
// Standalone entry point to bypass index.php / app.php during fatal errors.
// ==============================================================================
require_once __DIR__ . '/app/paths.php';

ini_set('display_errors', '0');
ini_set('html_errors', '0');

// Mock plugin variables expected by FilePatchManager
$plugin_settings_map =[];
$plugin_js = "";

// Load Patcher Backend (intercepts POST requests automatically)
require_once CJOS_PATH_PLUGINS . '/FilePatchManager.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Emergency Patcher</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <style>
        :root {
            --bg-color: #f2f2f7;
            --card-bg: #ffffff;
            --text-primary: #1c1c1e;
            --text-secondary: #6e6e73;
            --text-title: #000000;
            --primary: #007AFF;
            --primary-text: #ffffff;
            --btn-bg: #e5e5ea;
            --btn-text: #1c1c1e;
            --border-color: rgba(0,0,0,0.1);
            --danger: #ff3b30;
            --success-bg: #34c759;
            --warn-bg: #ffcc00;
            --input-bg: #f5f5f7;
            --input-text: #1c1c1e;
            --ai-accent: #5856d6;
            --ai-accent-bg: rgba(88, 86, 214, 0.1);
        }
        * { box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", sans-serif; 
            background: var(--bg-color); 
            color: var(--text-primary); 
            margin: 0; 
            padding: 20px; 
            line-height: 1.4;
        }
        #cp-tray-anchor { 
            max-width: 600px; 
            margin: 0 auto; 
            background: var(--card-bg); 
            border-radius: 20px; 
            padding: 20px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.08); 
        }
        .text-btn { 
            cursor: pointer; 
            border: none; 
            transition: opacity 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .text-btn:active { opacity: 0.7; }
        
        /* Layout Fixes */
        .setting-item { margin-bottom: 20px; }
        .setting-label { font-weight: 700; font-size: 17px; color: var(--text-title); display: block; margin-bottom: 4px; }
        .setting-desc { font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; }
        
        textarea {
            width: 100% !important;
            border: 1px solid var(--border-color) !important;
            background: var(--input-bg) !important;
            border-radius: 12px !important;
            padding: 12px !important;
            font-family: "SF Mono", "Menlo", monospace !important;
            font-size: 13px !important;
            resize: vertical;
        }

        /* Mock UI Elements */
        #cp-header-row { display: flex; gap: 10px; margin-bottom: 20px; }
        .mock-accordion-trigger {
            flex: 1;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
        }
        
        /* Hide elements that don't make sense or lack dependencies in standalone mode */
        #cp-tray-actions, .cp-header-row { display: none !important; }
        .sui-accordion { display: none; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div style="max-width: 600px; margin: 0 auto 20px auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <a href="index.php" title="Return to Main App" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; color: var(--text-primary); text-decoration: none; font-size: 13px; font-weight: 700; box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: opacity 0.2s;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px; height:16px;"><polyline points="15 18 9 12 15 6"></polyline></svg>
                <span>Back to App</span>
            </a>
            <div style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">Bypass Mode</div>
        </div>
        <div style="text-align: center;">
            <h1 style="margin: 0 0 4px 0; color: var(--danger); font-size: 26px; font-weight: 800; letter-spacing: -0.5px;">Emergency Patcher</h1>
            <p style="margin: 0; font-size: 13px; color: var(--text-secondary); font-weight: 500;">System Bypass Active • Surgical Fixes Only</p>
        </div>
    </div>

    <div id="cp-header-row" style="max-width: 600px; margin: 0 auto 12px auto; display: flex; gap: 10px;">
        <div class="mock-accordion-trigger" onclick="suiToggle('cp-meta-section')">
            Capabilities
            <span style="font-size: 10px; opacity: 0.5;">▼</span>
        </div>
        <div style="width: 44px; height: 44px; background: var(--btn-bg); border-radius: 12px; display: flex; align-items: center; justify-content: center; opacity: 0.5;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px; height:18px; color: var(--primary);"><path d="M15 3h6v6"></path><path d="M10 14L21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path></svg>
        </div>
    </div>

    <?php echo $plugin_settings_map['FilePatchManager'] ?? ''; ?>

    <script>
        // Configure API Endpoint for standalone mode
        window.CP_API_ENDPOINT = 'patcher.php';
        
        // SUI Mock for Standalone Environment
        window.sui = {
            toast: (msg) => alert(msg),
            haptic: () => {},
            api: async (action, data) => {
                const fd = new FormData();
                fd.append("plugin_action", action);
                for (let k in data) fd.append(k, data[k]);
                const res = await fetch(window.CP_API_ENDPOINT, { method: "POST", body: fd });
                return await res.json();
            }
        };
        
        window.openConfirm = (title, msg, onConfirm, showCancel, confirmText, cancelText) => {
            if (confirm(title + "\n\n" + msg)) { 
                if (onConfirm) onConfirm(); 
            }
        };

        // Mock toggle for capabilities section (since SUI is mocked)
        window.suiToggle = function(id) {
            const el = document.getElementById(id);
            if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
        };

        // Fallback icon hydrator for standalone mode
        window.suiHydrateIcons = function(container) {
            const root = container || document;
            const els = root.querySelectorAll('[data-sui-icon]');
            els.forEach(el => {
                if (el.querySelector('svg')) return;
                const name = el.getAttribute('data-sui-icon');
                if (name === 'close' || name === 'x') {
                    el.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px; height:14px; display:block;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
                } else if (name === 'copy') {
                    el.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px; height:12px; display:block;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
                } else if (name === 'alert-triangle') {
                    el.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px; height:14px; display:block;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
                } else if (name === 'chevron') {
                    el.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px; height:14px; display:block;"><polyline points="6 9 12 15 18 9"></polyline></svg>';
                }
            });
        };

        document.addEventListener('DOMContentLoaded', () => {
            window.suiHydrateIcons();
        });
    </script>
    <script>
        <?php echo $plugin_js ?? ''; ?>
    </script>
</body>
</html>