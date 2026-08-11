<?php
// ==============================================================================
// PLUGIN: Plugin Organizer
// DESCRIPTION: Folders & Hidden Settings.
// Allows reordering visible plugins and organizing hidden plugins into folders.
// UPDATED: Automatically saves layout when clicking "Done".
// ==============================================================================

$po_config_file = CJOS_PATH_DATA . '/plugin-layout.json';

// --- BACKEND HANDLERS ---

if (isset($_POST['plugin_action'])) {
    
    // FETCH DORMANT PLUGIN SETTINGS
    if ($_POST['plugin_action'] === 'po_fetch_settings') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        global $plugin_settings_map;
        if (!is_array($plugin_settings_map)) $plugin_settings_map = [];
        
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['name'] ?? '');
        $path = CJOS_PATH_PLUGINS . "/" . $name . ".php";
        
        $html = "";
        $js = "";
        
        if (file_exists($path)) {
            $content = file_get_contents($path);
            
            // Extract strictly this plugin's NOWDOC $plugin_js block
            if (preg_match('/\$plugin_js\s*\.=\s*<<<[\'\"]?JS[\'\"]?\s*\n(.*?)\n\s*JS;/s', $content, $matches)) {
                $js = $matches[1];
            }
            
            if (!isset($plugin_settings_map[$name])) {
                include_once $path;
            }
            
            $html = $plugin_settings_map[$name] ?? '<div class="setting-item"><div class="setting-desc" style="opacity:0.6;">No configurable settings for this plugin.</div></div>';
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Plugin file not found.']);
            exit;
        }
        
        echo json_encode(['status' => 'success', 'html' => $html, 'js' => $js]);
        exit;
    }

    // SAVE LAYOUT
    if ($_POST['plugin_action'] === 'po_save_layout') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $layout = json_decode($_POST['layout'], true);
        
        // Ensure data dir exists
        $dir = dirname($po_config_file);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        file_put_contents($po_config_file, json_encode($layout, JSON_PRETTY_PRINT));
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET LAYOUT
    if ($_POST['plugin_action'] === 'po_delete_plugin') {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['name']);
        $path = CJOS_PATH_PLUGINS . "/" . $name . ".php";
        
        if (file_exists($path)) {
            @unlink($path);
            clearstatcache(true, $path);
        }
        
        // Final check: if it's gone (or was already gone), it's a win.
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        if (!file_exists($path)) {
            echo json_encode(['status' => 'success', 'message' => 'Plugin Deletion Success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File is locked or permission denied. Check your server file manager.']);
        }
        exit;
    }

    if ($_POST['plugin_action'] === 'po_get_layout') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $layout = ['visible' => [], 'hidden_folders' => [], 'hidden_root' => [], 'hidden_order' => [], 'show_visibility_btn' => true, 'show_status_toggles' => true];
        
        if (file_exists($po_config_file)) {
            $loaded = json_decode(file_get_contents($po_config_file), true);
            if (is_array($loaded)) {
                $layout = array_merge($layout, $loaded);
            }
        }
        
        echo json_encode(['status' => 'success', 'layout' => $layout]);
        exit;
    }

    if ($_POST['plugin_action'] === 'po_get_source') {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['name']);
        $path = CJOS_PATH_PLUGINS . "/" . $name . ".php";
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        if (file_exists($path)) {
            echo json_encode(['status' => 'success', 'source' => file_get_contents($path)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File not found.']);
        }
        exit;
    }

    if ($_POST['plugin_action'] === 'po_rename_plugin') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $old = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['old_name']);
        $new = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['new_name']);
        
        $oldPath = CJOS_PATH_PLUGINS . "/" . $old . ".php";
        $newPath = CJOS_PATH_PLUGINS . "/" . $new . ".php";

        if (!file_exists($oldPath)) {
            echo json_encode(['status' => 'error', 'message' => 'Source plugin not found.']);
            exit;
        }
        if (file_exists($newPath)) {
            echo json_encode(['status' => 'error', 'message' => 'A plugin with that name already exists.']);
            exit;
        }

        // 1. Physical Rename
        if (!rename($oldPath, $newPath)) {
            echo json_encode(['status' => 'error', 'message' => 'Filesystem rename failed.']);
            exit;
        }

        // 2. Update Layout Registry (plugin-layout.json)
        $layoutFile = CJOS_PATH_DATA . '/plugin-layout.json';
        if (file_exists($layoutFile)) {
            $layout = json_decode(file_get_contents($layoutFile), true);
            
            if (is_array($layout)) {
                $renameRecursive = function(&$folders) use ($old, $new, &$renameRecursive) {
                    if (!is_array($folders)) return;
                    foreach ($folders as &$f) {
                        if (isset($f['plugins']) && is_array($f['plugins'])) {
                            $f['plugins'] = array_map(fn($p) => $p === $old ? $new : $p, $f['plugins']);
                        }
                        if (isset($f['folders']) && is_array($f['folders'])) {
                            $renameRecursive($f['folders']);
                        }
                    }
                };

                if (isset($layout['visible']) && is_array($layout['visible'])) {
                    $layout['visible'] = array_map(fn($v) => $v === $old ? $new : $v, $layout['visible']);
                }
                if (isset($layout['hidden_root']) && is_array($layout['hidden_root'])) {
                    $layout['hidden_root'] = array_map(fn($v) => $v === $old ? $new : $v, $layout['hidden_root']);
                }
                if (isset($layout['hidden_order']) && is_array($layout['hidden_order'])) {
                    foreach ($layout['hidden_order'] as &$item) {
                        if ($item['type'] === 'plugin' && $item['id'] === $old) $item['id'] = $new;
                    }
                }
                if (isset($layout['hidden_folders']) && is_array($layout['hidden_folders'])) {
                    $renameRecursive($layout['hidden_folders']);
                }
                
                file_put_contents($layoutFile, json_encode($layout, JSON_PRETTY_PRINT));
            }
        }

        // 3. Update UI Config (ui-config.json)
        $uiFile = CJOS_PATH_DATA . '/ui-config.json';
        if (file_exists($uiFile)) {
            $ui = json_decode(file_get_contents($uiFile), true);
            if (is_array($ui)) {
                if (isset($ui['plugins_enabled']) && is_array($ui['plugins_enabled'])) {
                    if (isset($ui['plugins_enabled']["plugin_$old"])) {
                        $ui['plugins_enabled']["plugin_$new"] = $ui['plugins_enabled']["plugin_$old"];
                        unset($ui['plugins_enabled']["plugin_$old"]);
                    }
                }
                if (isset($ui['plugins_hidden']) && is_array($ui['plugins_hidden'])) {
                    if (isset($ui['plugins_hidden']["cjos_hide_$old"])) {
                        $ui['plugins_hidden']["cjos_hide_$new"] = $ui['plugins_hidden']["cjos_hide_$old"];
                        unset($ui['plugins_hidden']["cjos_hide_$old"]);
                    }
                }
                file_put_contents($uiFile, json_encode($ui, JSON_PRETTY_PRINT));
            }
        }

        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- SETTINGS UI ---
$plugin_settings_map['PluginOrganizer'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-desc">
            Controls for <strong>Search</strong> and <strong>Edit Layout</strong> have been moved to the top of the <strong>Hidden Plugins</strong> section below.
        </div>
    </div>
    <div data-sui-setting="Show Folder Icons" data-sui-desc="Show the folder shortcut icon on individual plugin rows." data-sui-id="po-show-folder-toggle" data-sui-onchange="poUpdateFolderIcons(this.checked)"></div>
    <div data-sui-setting="Show Status Toggles" data-sui-desc="Show the Enable/Disable switches on plugin rows." data-sui-id="po-show-status-toggle" data-sui-onchange="poUpdateStatusToggleVisibility(this.checked)"></div>
    <div class="setting-item" id="po-save-container" style="display:none; justify-content:flex-end;">
        <button onclick="savePoLayout(false)" style="background:var(--primary); color:white; border:none; padding:8px 16px; border-radius:8px; font-weight:600; cursor:pointer;">Save Layout</button>
    </div>
HTML;

// --- JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- PLUGIN ORGANIZER JS ---

let poLayout = { visible: [], hidden_folders: [], hidden_root: [] };
let poIsEditing = false;

// Global Handler: Toggle Plugin Settings Tray (with On-Demand Dormant Accordion Hydration)
window.togglePluginTray = async function(trayId, arrowId) {
    const tray = document.getElementById(trayId);
    const arrow = document.getElementById(arrowId);
    if (!tray) return;

    const isDormant = tray.getAttribute('data-sui-dormant') === 'true';
    const isLoaded = tray.getAttribute('data-dormant-loaded') === 'true';

    if (isDormant && !isLoaded) {
        const pluginName = trayId.replace('tray-', '');
        if (arrow) arrow.style.opacity = '0.4';
        
        try {
            const res = await window.sui.api("po_fetch_settings", { name: pluginName }, { toast: false });
            if (res && res.status === 'success') {
                tray.innerHTML = res.html;
                tray.setAttribute('data-dormant-loaded', 'true');
                
                // 1. Inject & execute plugin's JavaScript logic on demand (with var sanitization)
                if (res.js && res.js.trim()) {
                    try {
                        // Sanitize top-level let/const to var to prevent browser redeclaration SyntaxErrors
                        const safeJs = res.js
                            .replace(/^(\s*)let\s+/gm, '$1var ')
                            .replace(/^(\s*)const\s+/gm, '$1var ');
                        
                        const oldScript = document.querySelector('.dormant-script-' + pluginName);
                        if (oldScript) oldScript.remove();
                        
                        const scriptEl = document.createElement('script');
                        scriptEl.className = 'dormant-script-' + pluginName;
                        scriptEl.textContent = safeJs;
                        document.body.appendChild(scriptEl);
                    } catch(err) {
                        console.error('Dormant script injection error:', pluginName, err);
                    }
                }
                
                // 2. Initialize SharedUI controls (switches, icons, setting rows)
                if (window.sui && window.sui.init) {
                    try { window.sui.init(tray); } catch(e) {}
                }
                
                // 3. Trigger plugin settings auto-load function if defined (e.g. ccLoadSettings)
                const candidateFns = [
                    'ccLoadSettings',
                    pluginName + 'LoadSettings',
                    pluginName.toLowerCase() + 'LoadSettings',
                    pluginName.charAt(0).toLowerCase() + pluginName.slice(1) + 'LoadSettings'
                ];
                
                setTimeout(() => {
                    for (const fnName of candidateFns) {
                        if (typeof window[fnName] === 'function') {
                            try { window[fnName](); } catch(err) { console.error('Settings auto-load error:', fnName, err); }
                            break;
                        }
                    }
                }, 50);
            }
        } catch(e) {
            console.error('Failed to fetch dormant settings:', e);
        } finally {
            if (arrow) arrow.style.opacity = '1';
        }
    }

    const isOpen = tray.classList.contains('open');
    if (isOpen) {
        tray.classList.remove('open');
        if (arrow) arrow.style.transform = 'rotate(-90deg)';
    } else {
        tray.classList.add('open');
        if (arrow) arrow.style.transform = 'rotate(0deg)';
    }
};

// 1. Init & Styles
window.addEventListener("load", () => {
    // Inject Styles
    const style = document.createElement("style");
    style.innerHTML = `
        .po-folder {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin: 0 16px 12px 16px;
            overflow: hidden;
            transition: all 0.2s;
            position: relative;
            z-index: 1;
        }
        /* Extra breathing room for the last top-level folder */
        #hidden-plugins-container > .po-folder:last-of-type {
            margin-bottom: 24px !important;
        }
        /* Nested Indentation */
        .po-folder .po-folder {
            margin: 8px 12px !important;
            border-left: 2px solid var(--primary);
            border-radius: 0 12px 12px 0;
        }
        /* Nuclear specificity override to defeat SharedUI's last-child reset */
        #settings-scroll-container .po-folder-content > .po-folder:last-child,
        .sui-studio-content .po-folder-content > .po-folder:last-child {
            margin-bottom: 8px !important;
        }
        .po-folder-header {
            padding: 12px 16px;
            background: var(--bg-color);
            border-bottom: none !important;
            font-size: 13px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            user-select: none;
        }
        .po-folder-header:active { background: var(--btn-bg); opacity: 0.7; }
        
        .po-folder-actions {
            display: none;
            gap: 4px;
            align-items: center;
        }
        body.po-editing .po-folder-actions { display: flex; }
        
        .po-folder-content {
            padding: 0;
            display: block; 
        }
        .po-folder-content .plugin-block {
            border-bottom: none !important;
        }
        .po-folder-content .plugin-block:last-child {
            border-bottom: none;
        }
        /* Hide the separator line for the last visible plugin in a container */
        .plugin-block.po-is-last:after {
            display: none !important;
        }
        /* Hide the bottom border of the tray if the plugin is the last visible one */
        .plugin-block:last-child .plugin-tray,
        .plugin-block.po-is-last .plugin-tray {
            border-bottom: none !important;
        }
        
        .po-controls {
            display: none;
            gap: 4px;
            margin-right: 8px;
        }
        body.po-editing .po-controls { display: flex; }
        
        .po-btn {
            width: 28px; height: 28px;
            border-radius: 6px;
            background: var(--btn-bg);
            color: var(--btn-text);
            border: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
        }
        .po-btn:active { background: #D1D1D6; }
        
        .po-drop-zone {
            display: none !important;
            padding: 10px;
            text-align: center;
            border: 2px dashed var(--border-color);
            border-radius: 10px;
            color: var(--text-secondary);
            font-size: 13px;
            margin: 10px 16px 0 16px;
            cursor: pointer;
        }
        body.po-editing .po-drop-zone { display: block !important; }
        .po-drop-zone:hover { border-color: var(--primary); color: var(--primary); }
        
        .plugin-block.is-disabled {
            opacity: 0.5;
            filter: grayscale(1);
            transition: opacity 0.3s ease, filter 0.3s ease;
        }

        .plugin-block.is-dormant {
            opacity: 0.98;
        }

        .plugin-tray, .plugin-tray * {
            pointer-events: auto !important;
        }

        .po-disabled-label {
            display: none;
            font-size: 10px;
            font-weight: 800;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
            margin-right: 4px;
        }
    `;
    document.head.appendChild(style);

    // Initial Load
    loadPoLayout();

    // DOM Observer
    const observer = new MutationObserver((mutations) => {
        // Ensure controls persist if DOM changes
        if(document.getElementById("hidden-plugins-container") && !document.getElementById("po-tools-header")) {
            injectPoTools();
        }

        if (!poIsEditing) return;
        mutations.forEach(m => {
            m.addedNodes.forEach(node => {
                if (node.nodeType === 1 && node.classList.contains("plugin-block")) {
                    injectPoControlsRow(node);
                }
            });
        });
    });
    
    const visCont = document.getElementById("visible-plugins-container");
    const hidCont = document.getElementById("hidden-plugins-container");
    const obsConfig = { childList: true };
    if(visCont) observer.observe(visCont, obsConfig);
    if(hidCont) observer.observe(hidCont, obsConfig);
});

// 2. Load & Apply
async function loadPoLayout() {
    try {
        const data = await window.sui.api("po_get_layout", {}, { toast: false });
        if (data && data.layout) {
    poLayout = data.layout;
    if (poLayout.show_visibility_btn === undefined) poLayout.show_visibility_btn = true;
    if (poLayout.show_status_toggles === undefined) poLayout.show_status_toggles = true;
    
    const folderToggle = document.getElementById("po-show-folder-toggle");
    if (folderToggle) folderToggle.checked = poLayout.show_visibility_btn;
    
    const statToggle = document.getElementById("po-show-status-toggle");
    if (statToggle) statToggle.checked = poLayout.show_status_toggles;

    poApplyFolderIconStyle();
    poApplyStatusToggleStyle();
    applyPoLayout();
}} catch(e) { console.error("PO Load Error", e); }
}

function applyPoLayout() {
    const visCont = document.getElementById("visible-plugins-container");
    const hidCont = document.getElementById("hidden-plugins-container");
    if(!visCont || !hidCont) return;

    // A. Reorder Visible
    if (poLayout.visible && poLayout.visible.length > 0) {
        poLayout.visible.forEach(name => {
            const row = document.getElementById("plg-row-" + name);
            if (row && row.parentNode === visCont) {
                visCont.appendChild(row); 
            }
        });
    }

    // B. Clean Old JS Folders
    document.querySelectorAll(".po-folder").forEach(el => {
        const content = el.querySelector(".po-folder-content");
        // Dump plugins back to hidden root before destroy
        while(content.children.length > 0) {
            hidCont.appendChild(content.children[0]);
        }
        el.remove();
    });

    // C. Reorder Hidden Root Plugins
    if (poLayout.hidden_root && poLayout.hidden_root.length > 0) {
        poLayout.hidden_root.forEach(name => {
            const row = document.getElementById("plg-row-" + name);
            if (row && (row.parentNode === hidCont)) {
                hidCont.appendChild(row);
            }
        });
    }

    // D. Create & Insert Hidden Items (Respecting Interleaved Order)
    const renderFolderTree = (folderData, container) => {
        const isOpen = (folderData.isOpen !== undefined) ? folderData.isOpen : true;
        const folderEl = createFolderDOM(folderData.id, folderData.name, isOpen);
        container.appendChild(folderEl);
        poAttachLongPress(folderEl);
        
        const content = folderEl.querySelector(".po-folder-content");
        
        // Render Sub-content (Recursive)
        if (folderData.folders) {
            folderData.folders.forEach(sub => renderFolderTree(sub, content));
        }
        if (folderData.plugins) {
            folderData.plugins.forEach(name => {
                const row = document.getElementById("plg-row-" + name);
                if (row) content.appendChild(row);
            });
        }
    };

    // E. INJECT TOOLS FIRST
    injectPoTools();

    // F. APPLY TOP-LEVEL ORDER
    const toolsHeader = document.getElementById("po-tools-header");
    
    // Ensure New Folder button is initialized before ordering
    injectNewFolderBtn();
    const newFolderBtn = document.getElementById("po-new-folder-btn");

    // G. ATTACH GLOBAL LISTENERS (View Mode Support)
    // Note: poAttachLongPress is already called for folders during tree rendering.
    // We only need to catch plugins in the hidden root or visible list here.
    document.querySelectorAll(".plugin-block").forEach(el => {
        poAttachLongPress(el);
        if (window.srWatch) window.srWatch(el, document.getElementById('settings-scroll-container'));
    });
    
    // Ensure all folders have scroll reveal
    document.querySelectorAll(".po-folder").forEach(el => {
        if (window.srWatch) window.srWatch(el, document.getElementById('settings-scroll-container'));
    });

    if (poLayout.hidden_order && poLayout.hidden_order.length > 0) {
        poLayout.hidden_order.forEach(item => {
            if (item.type === 'folder') {
                const data = poLayout.hidden_folders.find(f => f.id === item.id);
                if (data) renderFolderTree(data, hidCont);
            } else {
                const row = document.getElementById("plg-row-" + item.id);
                if (row) hidCont.appendChild(row);
            }
        });
    } else {
        // Fallback for legacy layouts: Folders then Plugins
        if (poLayout.hidden_folders) poLayout.hidden_folders.forEach(f => renderFolderTree(f, hidCont));
        if (poLayout.hidden_root) poLayout.hidden_root.forEach(name => {
            const row = document.getElementById("plg-row-" + name);
            if (row) hidCont.appendChild(row);
        });
    }

    // Always push the New Folder button to the very end
    if (newFolderBtn) hidCont.appendChild(newFolderBtn);

    if (typeof updateHiddenSectionUI === 'function') updateHiddenSectionUI();
}

// 3. Inject Controls (Search & Edit Toggle)
function injectPoTools() {
    const hidCont = document.getElementById("hidden-plugins-container");
    if (!hidCont) return;

    // Check if exists
    let tools = document.getElementById("po-tools-header");
    if (tools) tools.remove(); // Remove to re-add at top

    tools = document.createElement("div");
    tools.id = "po-tools-header";
    tools.style.cssText = "padding: 12px 16px 16px 16px; border-bottom: 1px solid var(--border-color); margin-bottom: 12px; display:flex; gap:10px; align-items:center;";

    // Search Input
    const searchWrapper = document.createElement("div");
    searchWrapper.style.cssText = "flex:1; position:relative;";
    
    const searchIcon = `<div style="position:absolute; left:10px; top:50%; transform:translateY(-50%); display:flex;">${window.suiIcon('search', 'var(--text-secondary)', 16, 2)}</div>`;
    
    const input = document.createElement("input");
    input.type = "text";
    input.placeholder = "Filter plugins...";
    input.style.cssText = "width:100%; padding: 8px 74px 8px 34px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--input-text); font-size: 14px; box-sizing: border-box;";
    
    const clearBtn = document.createElement("button");
    clearBtn.id = "po-search-clear-btn";
    clearBtn.innerHTML = "&times;";
    clearBtn.style.cssText = "position:absolute; right:38px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-secondary); font-size:24px; cursor:pointer; display:none; padding:0; width:34px; height:34px; line-height:34px; text-align:center; opacity:0.6;";
    
    const deepToggle = document.createElement("button");
deepToggle.id = "po-deep-search-btn";
deepToggle.title = "Search Plugin Settings";
deepToggle.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:14px; height:14px; stroke-width:2.5;"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>`;
deepToggle.style.cssText = "position:absolute; right:4px; top:50%; transform:translateY(-50%); background:none; border:none; color:#C7C7CC; cursor:pointer; padding:8px; transition:all 0.2s; display:flex; align-items:center; justify-content:center;";
          
let isDeepSearch = localStorage.getItem("cjos_po_deep_search") === "true";
const updateDeepUI = () => {
    deepToggle.style.color = isDeepSearch ? "var(--primary)" : "#C7C7CC";
    deepToggle.style.background = isDeepSearch ? "var(--btn-bg)" : "none";
    deepToggle.style.borderRadius = "8px";
    deepToggle.style.boxShadow = isDeepSearch ? "inset 0 1px 3px rgba(0,0,0,0.08)" : "none";
    input.placeholder = isDeepSearch ? "Search for settings text..." : "Search for plugins...";
};
updateDeepUI();

deepToggle.onclick = () => {
    isDeepSearch = !isDeepSearch;
    localStorage.setItem("cjos_po_deep_search", isDeepSearch);
    updateDeepUI();
    filterPoPlugins(input.value);
};

input.oninput = (e) => {
    const val = e.target.value;
    localStorage.setItem("cjos_po_search_query", val);
    filterPoPlugins(val);
    clearBtn.style.display = val.length > 0 ? "block" : "none";
};

clearBtn.onclick = () => {
    input.value = "";
    localStorage.removeItem("cjos_po_search_query");
    filterPoPlugins("");
    clearBtn.style.display = "none";input.focus();
        const hidCont = document.getElementById("hidden-plugins-container");
        if(hidCont) {
            hidCont.querySelectorAll(".po-folder").forEach(folder => {
                const content = folder.querySelector(".po-folder-content");
                const arrow = folder.querySelector(".po-folder-header svg");
                if(content) content.style.display = "none";
                if(arrow) arrow.style.transform = "rotate(-90deg)";
            });
        }
    };
    
    searchWrapper.innerHTML = searchIcon;
searchWrapper.appendChild(input);
searchWrapper.appendChild(deepToggle);
searchWrapper.appendChild(clearBtn);
      // Edit Toggle Button
    const editBtn = document.createElement("button");
    editBtn.id = "po-edit-btn-injected";
    editBtn.innerText = "Edit Layout";
    editBtn.style.cssText = "background: var(--btn-bg); color: var(--text-primary); border: none; padding: 8px 12px; border-radius: 10px; font-weight: 600; font-size: 13px; cursor: pointer; white-space: nowrap; transition: all 0.2s;";
    
    const importBtn = document.createElement("button");
    importBtn.id = "po-import-btn";
    importBtn.innerText = "Import";
    importBtn.style.cssText = "display:none; background: var(--btn-bg); color: var(--text-primary); border: none; padding: 8px 12px; border-radius: 10px; font-weight: 600; font-size: 13px; cursor: pointer; margin-right: 4px;";
    importBtn.onclick = poTriggerImport;

    const exportBtn = document.createElement("button");
    exportBtn.id = "po-export-btn";
    exportBtn.innerText = "Export";
    exportBtn.style.cssText = "display:none; background: var(--btn-bg); color: var(--text-primary); border: none; padding: 8px 12px; border-radius: 10px; font-weight: 600; font-size: 13px; cursor: pointer; margin-right: 4px;";
    exportBtn.onclick = poTriggerExport;

    const helpText = document.createElement("div");
    helpText.id = "po-edit-help";
    helpText.innerText = "* Long press on a folder or plugin for more options.";
    helpText.style.cssText = "display:none; font-size:10px; color:var(--text-secondary); text-align:center; margin-top:-8px; margin-bottom:12px; width:100%; opacity:0.8; font-style:italic;";

    editBtn.onclick = () => {
        const isEditing = document.body.classList.contains("po-editing");
        importBtn.style.display = isEditing ? "none" : "block";
        exportBtn.style.display = isEditing ? "none" : "block";
        helpText.style.display = isEditing ? "none" : "block";
        if (isEditing) {
            // --- FIX: AUTO-SAVE ON DONE ---
            savePoLayout(true); 
            filterPoPlugins(""); // Ensure all folders reappear
        }
        togglePoEditMode(!isEditing);
    };

    tools.appendChild(searchWrapper);
    tools.appendChild(importBtn);
    tools.appendChild(exportBtn);
    tools.appendChild(editBtn);
    hidCont.insertBefore(helpText, tools.nextSibling);

    // Bulk Actions Row (Expand/Collapse)
    const bulkRow = document.createElement("div");
    bulkRow.id = "po-bulk-actions-row";
    bulkRow.style.cssText = "display:flex; gap:8px; justify-content:center; margin-bottom:12px;";
    bulkRow.innerHTML = `
        <button onclick="poToggleAllFolders(true)" class="text-btn" style="font-size:10px; font-weight:800; color:var(--primary); text-transform:uppercase; letter-spacing:0.5px; padding:2px 10px; background:var(--btn-bg); border-radius:6px; border:1px solid rgba(0,0,0,0.03);">Expand All</button>
        <button onclick="poToggleAllFolders(false)" class="text-btn" style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; padding:2px 10px; background:var(--btn-bg); border-radius:6px; border:1px solid rgba(0,0,0,0.03);">Collapse All</button>
    `;

    hidCont.insertBefore(bulkRow, hidCont.firstChild);
    hidCont.insertBefore(tools, hidCont.firstChild);

    // Restore Saved Search
    const savedQuery = localStorage.getItem("cjos_po_search_query") || "";
    if (savedQuery) {
        input.value = savedQuery;
        clearBtn.style.display = "block";
        // Delay filter slightly to ensure DOM layout is ready
        setTimeout(() => filterPoPlugins(savedQuery), 50);
    }
}

// 4. Search & Filter Logic
function filterPoPlugins(query) {
    const term = query.toLowerCase().trim();
    const isSearching = term.length > 0;
    const hidCont = document.getElementById("hidden-plugins-container");
    if(!hidCont) return;

    const isDeep = localStorage.getItem("cjos_po_deep_search") === "true";
const processElement = (container) => {
    let totalVisible = 0;

    // 1. Handle Plugins
    const plugins = Array.from(container.children).filter(el => el.classList.contains("plugin-block"));
    plugins.forEach(el => {
        el.classList.remove("po-is-last");
        const name = el.querySelector(".setting-label").textContent.toLowerCase();const tray = el.querySelector(".plugin-tray");
        const trayText = (isDeep && tray) ? tray.textContent.toLowerCase() : "";
            
        if (!isSearching || name.includes(term) || trayText.includes(term)) {
            el.style.removeProperty("display");
            totalVisible++;
        } else {
            el.style.setProperty("display", "none", "important");
        }
    });
      

        // 2. Handle Folders (Recursive)
        const folders = Array.from(container.children).filter(el => el.classList.contains("po-folder"));
        folders.forEach(folder => {
            const content = folder.querySelector(".po-folder-content");
            const subVisible = processElement(content);
            
            // A folder is visible if we aren't searching, or if it has visible contents
            if (!isSearching || subVisible > 0) {
                folder.style.removeProperty("display");
                totalVisible += (subVisible || 1); // Ensure empty folders stay visible when not searching
                
                // Auto-expand on search match
                if (isSearching && subVisible > 0) {
                    content.style.display = "block";
                    const arrow = folder.querySelector(".po-folder-header svg");
                    if(arrow) arrow.style.transform = "rotate(0deg)";
                }
            } else {
                folder.style.setProperty("display", "none", "important");
            }
        });

        // 3. Tag Last Visible Plugin
        const visibleChildren = Array.from(container.children).filter(el => {
            return (el.classList.contains("plugin-block") || el.classList.contains("po-folder")) 
                   && el.style.display !== "none";
        });
        if (visibleChildren.length > 0) {
            const last = visibleChildren[visibleChildren.length - 1];
            if (last.classList.contains("plugin-block")) {
                last.classList.add("po-is-last");
            }
        }

        return totalVisible;
    };

    processElement(hidCont);

    // Also handle the visible container (not recursive)
    const visCont = document.getElementById("visible-plugins-container");
    if (visCont) {
        const visPlugins = Array.from(visCont.children).filter(el => el.classList.contains("plugin-block"));
        visPlugins.forEach(p => p.classList.remove("po-is-last"));
        const visible = visPlugins.filter(p => p.style.display !== "none");
        if (visible.length > 0) visible[visible.length - 1].classList.add("po-is-last");
    }
}

// 5. Edit Mode Logic
window.togglePoEditMode = function(enabled) {
    poIsEditing = enabled;
    document.body.classList.toggle("po-editing", enabled);
    const saveBtn = document.getElementById("po-save-container");
    if(saveBtn) saveBtn.style.display = enabled ? "flex" : "none";

    // Update Injected Button
    const btn = document.getElementById("po-edit-btn-injected");
    if(btn) {
        if(enabled) {
            btn.style.background = "var(--primary)";
            btn.style.color = "var(--primary-text)";
            btn.innerText = "Done";
        } else {
            btn.style.background = "var(--btn-bg)";
            btn.style.color = "var(--text-primary)";
            btn.innerText = "Edit Layout";
        }
    }

    // Toggle Search Input state (Disable search while editing to avoid conflict)
    const input = document.querySelector("#po-tools-header input");
    const clrBtn = document.getElementById("po-search-clear-btn");
    if(input && enabled) {
        input.value = "";
        filterPoPlugins(""); // Clear filter
        input.disabled = true;
        input.style.opacity = "0.5";
        if(clrBtn) clrBtn.style.display = "none";
    } else if (input) {
        input.disabled = false;
        input.style.opacity = "1";
    }

    if (enabled) {
        injectPoControls();
        injectNewFolderBtn();
    } else {
        removePoControls();
    }
};

function injectPoControls() {
    document.querySelectorAll(".plugin-block").forEach(row => {
        injectPoControlsRow(row);
    });
}

let poLongPressActive = false;
let poStartX = 0, poStartY = 0;
let poTitleTimer = null;
let poInteractionLock = false;

window.poAttachLongPress = function(el) {
    const header = el.classList.contains('po-folder') ? el.querySelector('.po-folder-header') : el.querySelector('.setting-item');
    if (!header || header.dataset.poHasListener) return;

    const folderId = el.getAttribute('data-folder-id');

    // Use Pointer Events to unify Touch and Mouse logic into a single stream
    header.addEventListener('pointerdown', (e) => poHandleTitleStart(e, el, folderId));
    header.addEventListener('pointermove', (e) => poHandleTitleMove(e));
    header.addEventListener('pointerup', (e) => poHandleTitleEnd(e));
    header.addEventListener('pointercancel', (e) => poHandleTitleEnd(e));
    header.addEventListener('pointerleave', (e) => poHandleTitleEnd(e));
    header.addEventListener('contextmenu', e => e.preventDefault());
    
    header.dataset.poHasListener = "true";
};

// Global Capture-Phase Click Interceptor
// This kills the "ghost click" that follows a long-press release, preventing the menu from closing.
window.addEventListener('click', (e) => {
    if (poInteractionLock) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
    }
}, true);

window.poHandleTitleStart = function(e, target, isFolderId = null) {
    // Ignore clicks on interactive elements
    if (e.target.closest('button, .switch, input, .po-folder-actions')) return;
    
    // Stop propagation to prevent nested folders from both triggering
    e.stopPropagation();

    if (poTitleTimer) clearTimeout(poTitleTimer);
    poLongPressActive = false;
    poInteractionLock = false;

    poStartX = e.clientX;
    poStartY = e.clientY;

    poTitleTimer = setTimeout(() => {
        poTitleTimer = null;
        poLongPressActive = true;
        poInteractionLock = true; // Lock interactions to swallow the upcoming 'click' event
        
        if (window.sui && window.sui.haptic) window.sui.haptic('medium');
        
        if (isFolderId) openFolderContextMenu(isFolderId);
        else openPluginContextMenu(target);

        // Release the lock after the browser's click window has passed
        setTimeout(() => { poInteractionLock = false; }, 400);
    }, 600);
};

window.poHandleTitleMove = function(e) {
    if (!poTitleTimer) return;
    const dx = Math.abs(e.clientX - poStartX);
    const dy = Math.abs(e.clientY - poStartY);
    if (dx > 10 || dy > 10) {
        clearTimeout(poTitleTimer);
        poTitleTimer = null;
    }
};

function injectPoControlsRow(row) {
    const old = row.querySelector(".po-controls");
    if(old) old.remove();

    const controls = document.createElement("div");
    controls.className = "po-controls";
    
    const btnUp = document.createElement("button");
    btnUp.className = "po-btn";
    btnUp.innerHTML = "↑";
    btnUp.onclick = (e) => { e.stopPropagation(); movePlugin(row, -1); };
    
    const btnDown = document.createElement("button");
    btnDown.className = "po-btn";
    btnDown.innerHTML = "↓";
    btnDown.onclick = (e) => { e.stopPropagation(); movePlugin(row, 1); };
    
    controls.appendChild(btnUp);
    controls.appendChild(btnDown);

    const item = row.querySelector(".setting-item");
    if (item) {
        item.insertBefore(controls, item.firstChild);
        // Listener is now handled globally via poAttachLongPress
    }
}

window.openPluginContextMenu = function(row) {
    if (typeof window.openPicker !== "function") return;
    
    const pluginName = row.id.replace("plg-row-", "");
    const displayName = row.querySelector(".setting-label").innerText;
    const isHidden = row.closest("#hidden-plugins-container") !== null;
    
    const checkbox = row.querySelector('input[type="checkbox"]');
    const isEnabled = checkbox ? checkbox.checked : true;

    const isExcluded = (typeof bkExcludedPlugins !== 'undefined') && bkExcludedPlugins.includes(pluginName);
    const currentState = row.getAttribute("data-plugin-state") || (isEnabled ? "active" : "disabled");

    const options = [
        { label: "File Information", type: "header" },
        { label: `<div style="display:flex; justify-content:space-between; align-items:center; font-family:monospace; font-size:11px; color:var(--text-secondary);"><span>${pluginName}.php</span><span style="opacity:0.5;">PHP File</span></div>`, type: "info" },
        { label: "✏️ Rename Plugin File", value: "rename_file" },

        { label: "Status & Mode", type: "header" },
        { label: "🟢 Set Active (Full UI)", value: "state_active" },
        { label: "🌙 Set Dormant (API Only)", value: "state_dormant" },
        { label: "🔴 Disable Plugin", value: "state_disabled" },
        { label: "📂 Assign Folder", value: "assign" },
        
        { label: "Development & Backup", type: "header" },
        { label: "📋 Copy Source Code", value: "copy_source" },
        { label: "🛠️ Get Source from Patcher", value: "get_source_patcher" },
        { label: isExcluded ? "✅ Include in Source Export" : "🚫 Exclude from Source Export", value: "toggle_export" },
        
        { label: "Danger Zone", type: "header" },
        { label: "<span style='color:var(--danger)'>🗑️ Delete Plugin</span>", value: "delete" }
    ];

    window.openPicker(displayName, options, null, async (val) => {
        if (val === "state_active") togglePluginState(pluginName, "active");
        if (val === "state_dormant") togglePluginState(pluginName, "dormant");
        if (val === "state_disabled") togglePluginState(pluginName, "disabled");
        if (val === "toggle_state") togglePluginState(pluginName, isEnabled ? "disabled" : "active");
        if (val === "copy_source") {
            try {
                const res = await window.sui.api("po_get_source", { name: pluginName }, { toast: "Source Captured" });
                if (res.status === 'success') {
                    const wrapped = "```php\n" + res.source.trim() + "\n```";
                    await navigator.clipboard.writeText(wrapped);
                    if (window.sui && window.sui.haptic) window.sui.haptic('success');
                }
            } catch(e) { console.error("Copy failed", e); }
        }
        if (val === "get_source_patcher") {
            const exportBlock = `#PATCH_ID: export_${pluginName}\n#FILE: app/plugins/${pluginName}.php\n#ACTION: export\n#END`;
            const cpInp = document.getElementById('cp-input');
            if (cpInp && typeof window.cpOpenStudio === 'function') {
                cpInp.value = exportBlock;
                window.cpOpenStudio();
                if (typeof window.cpVerifyBatch === 'function') window.cpVerifyBatch();
            } else {
                window.openConfirm("Patcher Error", "File Patch Manager plugin is not enabled or loaded.", null, false, "OK", null);
            }
        }
        if (val === "rename_file") poRenamePluginFile(pluginName);
        if (val === "assign") openFolderPicker(row);
        if (val === "toggle_visibility") {
            if (typeof togglePluginVisibility === "function") {
                togglePluginVisibility(pluginName, !isHidden);
            } else {
                window.openConfirm("Error", "Visibility controller not found.", null, false, "OK", null);
            }
        }
        if (val === "toggle_export") {
            if (typeof bkToggleExclusionStatus === "function") bkToggleExclusionStatus(pluginName);
        }
        if (val === "delete") poDeletePlugin(pluginName, row);
    });
};

window.poUpdateFolderIcons = function(enabled) {
    poLayout.show_visibility_btn = enabled;
    poApplyFolderIconStyle();
    savePoLayout(true);
};

function poApplyFolderIconStyle() {
    let style = document.getElementById("po-folder-icon-style");
    if (!style) {
        style = document.createElement("style");
        style.id = "po-folder-icon-style";
        document.head.appendChild(style);
    }
    style.innerHTML = (poLayout.show_visibility_btn === false) ? `.folder-assign-btn { display: none !important; }` : "";
}

window.poUpdateStatusToggleVisibility = function(enabled) {
    poLayout.show_status_toggles = enabled;
    poApplyStatusToggleStyle();
    savePoLayout(true);
};

function poApplyStatusToggleStyle() {
    let style = document.getElementById("po-status-toggle-style");
    if (!style) {
        style = document.createElement("style");
        style.id = "po-status-toggle-style";
        document.head.appendChild(style);
    }
    // Target only the top-level switches in plugin rows, not those inside trays
    if (poLayout.show_status_toggles === false) {
        style.innerHTML = `
            .plugin-block > .setting-item > .switch { display: none !important; }
            .plugin-block.is-disabled > .setting-item > .po-disabled-label,
            .plugin-block.is-dormant > .setting-item > .po-disabled-label { display: inline-block !important; }
        `;
    } else {
        style.innerHTML = "";
    }
}

window.poRenamePluginFile = function(oldName) {
    window.openInput("Rename Plugin File", "New filename (no extension)", oldName, async (newName) => {
        if (!newName || newName === oldName) return;
        
        const sanitized = newName.replace(/[^a-zA-Z0-9_]/g, '');
        if (sanitized !== newName) {
            window.sui.toast("Invalid characters removed", { color: "var(--danger)" });
        }

        window.openConfirm("Confirm Rename", `This will rename the physical file to <strong>${sanitized}.php</strong> and update all registries. The page will reload.`, async () => {
            try {
                const res = await window.sui.api("po_rename_plugin", { old_name: oldName, new_name: sanitized }, { toast: "Renaming..." });
                
                // Even if the response is slightly malformed, if we got here, 
                // the rename likely happened. We'll check for success explicitly.
                if (res && res.status === 'success') {
                    window.sui.toast("Rename Successful! Reloading...", { color: "var(--success-bg)" });
                    if (window.sui && window.sui.haptic) window.sui.haptic('success');
                    
                    // Small delay ensures the toast is visible and filesystem settles
                    setTimeout(() => {
                        location.reload();
                    }, 800);
                } else {
                    const msg = res ? res.message : "Unknown error during rename.";
                    window.openConfirm("Rename Error", msg, null, false, "OK", null);
                }
            } catch(e) { 
                console.error("Rename Error:", e);
                window.sui.toast("Request failed. Please refresh manually.", { color: "var(--danger)" });
            }
        });
    });
};

window.poDeletePlugin = function(name, row) {
    if (typeof window.openConfirm !== "function") {
        window.openConfirm("Plugin Required", "SharedUI plugin is required for this action.", null, false, "OK", null);
        return;
    }

    // Step 1: Initial Confirmation
    window.openConfirm(
        "Delete Plugin",
        `Permanently remove "${name}.php" from your server?`,
        () => {
            // Step 2: Final Warning (Double Confirmation)
            setTimeout(() => {
                window.openConfirm(
    "Final Warning",
    `Are you REALLY sure? This will physically delete the file and cannot be undone.`,
    async () => {
        // 1. OPTIMISTIC FEEDBACK: Grey out immediately
        row.style.transition = "filter 0.3s ease, opacity 0.3s ease, background-color 0.3s ease";
        row.style.filter = "grayscale(1) brightness(0.8)";
        row.style.opacity = "0.3";
        row.style.pointerEvents = "none";
        row.style.backgroundColor = "rgba(0,0,0,0.05)";

        try {
            await window.sui.api("po_delete_plugin", { name: name }, { toast: false });
        } catch(e) { console.error("PO Delete Request Failed:", e); }

        // 2. DELAYED EXIT: Slide out regardless of response quality
        setTimeout(() => {
            row.style.transition = "all 0.5s cubic-bezier(0.16, 1, 0.3, 1)";
            row.style.opacity = "0";
            row.style.transform = "translateX(-40px)";
            setTimeout(() => {
                row.remove();
                savePoLayout(true);
            }, 500);
        }, 600);
    },
    true // isDanger
);}, 400); // Small delay between modals for visual clarity
        },
        true // isDanger
    );
};

function removePoControls() {
    const btn = document.getElementById("po-new-folder-btn");
    if(btn) btn.remove();
}

function injectNewFolderBtn() {
    const hidCont = document.getElementById("hidden-plugins-container");
    if(!hidCont || document.getElementById("po-new-folder-btn")) return;

    const btn = document.createElement("div");
    btn.id = "po-new-folder-btn";
    btn.className = "po-drop-zone";
    btn.innerText = "+ Create New Folder";
    // Sync visibility with current editing state immediately
    if (document.body.classList.contains("po-editing")) {
        btn.style.setProperty("display", "block", "important");
    }
    btn.onclick = createNewFolderInteract;
    hidCont.appendChild(btn);
}

// 6. ANIMATION & DOM OPS

function animateDomSwap(el1, el2, swapCallback) {
    const el1Start = el1.getBoundingClientRect().top;
    const el2Start = el2.getBoundingClientRect().top;

    swapCallback();

    const el1End = el1.getBoundingClientRect().top;
    const el2End = el2.getBoundingClientRect().top;
    const el1Delta = el1Start - el1End;
    const el2Delta = el2Start - el2End;

    requestAnimationFrame(() => {
        el1.style.transition = 'none'; el2.style.transition = 'none';
        el1.style.transform = `translateY(${el1Delta}px)`;
        el2.style.transform = `translateY(${el2Delta}px)`;
        el1.style.zIndex = 10; el2.style.zIndex = 10;
        void el1.offsetHeight;
        
        const trans = 'transform 0.3s cubic-bezier(0.2, 0, 0.2, 1)';
        el1.style.transition = trans; el2.style.transition = trans;
        el1.style.transform = ''; el2.style.transform = '';
        setTimeout(() => {
            el1.style.transition = ''; el1.style.zIndex = '';
            el2.style.transition = ''; el2.style.zIndex = '';
        }, 300);
    });
}

function movePlugin(row, dir) {
    const sibling = dir === -1 ? row.previousElementSibling : row.nextElementSibling;
    if (!sibling) return;

    animateDomSwap(row, sibling, () => {
        if (dir === -1) {
            row.parentNode.insertBefore(row, sibling);
        } else {
            row.parentNode.insertBefore(row, sibling.nextElementSibling);
        }
    });
}

function createNewFolderInteract() {
    window.openInput("New Folder", "Folder Name", "", (name) => {
        if (!name) return;
        const id = "f_" + Date.now();
        
        const hidCont = document.getElementById("hidden-plugins-container");
        const folderEl = createFolderDOM(id, name, true);
        
        // Insert after the toolbar (which is firstChild)
        const header = document.getElementById("po-tools-header");
        if (header && header.nextSibling) {
            hidCont.insertBefore(folderEl, header.nextSibling);
        } else {
            hidCont.appendChild(folderEl);
        }
        savePoLayout(true);
    });
}

window.createNewSubFolder = function(parentId) {
    window.openInput("New Sub-folder", "Sub-folder Name", "", (name) => {
        if (!name) return;
        const id = "f_" + Date.now();
        
        const parentContent = document.getElementById(`content-${parentId}`);
        const parentArrow = document.getElementById(`arrow-${parentId}`);
        
        if (parentContent) {
            const folderEl = createFolderDOM(id, name, true);
            parentContent.appendChild(folderEl);
            
            // Auto-expand parent
            parentContent.style.display = "block";
            if (parentArrow) parentArrow.style.transform = "rotate(0deg)";
            
            savePoLayout(true);
        }
    });
};

function createFolderDOM(id, name, isOpen) {
    const div = document.createElement("div");
    div.className = "po-folder";
    div.setAttribute("data-folder-id", id);
    
    const displayStyle = isOpen ? "block" : "none";
    const arrowRotation = isOpen ? "0deg" : "-90deg";
    
    div.innerHTML = `
        <div class="po-folder-header" 
             onclick="togglePoFolder('${id}')" 
             style="background:var(--bg-color);">
            <div style="display:flex; align-items:center; gap:8px; flex:1;">
                <svg id="arrow-${id}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px; height:16px; transition:transform 0.2s; transform: rotate(${arrowRotation})"><polyline points="6 9 12 15 18 9"></polyline></svg>
                <span style="font-weight:600; color:var(--text-primary); cursor:default; user-select:none;">${name}</span>
            </div>
            <!-- CONTROLS -->
            <div class="po-folder-actions" onclick="event.stopPropagation()">
                <button onclick="moveFolder('${id}', -1)" class="po-btn" title="Move Up">↑</button>
                <button onclick="moveFolder('${id}', 1)" class="po-btn" title="Move Down">↓</button>
            </div>
        </div>
        <div id="content-${id}" class="po-folder-content" style="display: ${displayStyle};"></div>
    `;
    return div;
}

window.openFolderContextMenu = function(id) {
    if (typeof window.openPicker !== "function") return;
    
    const el = document.querySelector(`.po-folder[data-folder-id="${id}"]`);
    const name = el.querySelector(".po-folder-header span").innerText;

    const options = [
        { label: "✎ Rename Folder", value: "rename" },
        { label: "➕ Add Sub-folder", value: "add_sub" },
        { label: "📂 Move to Folder", value: "move" },
        { label: "<span style='color:var(--danger)'>🗑️ Delete Folder</span>", value: "delete" }
    ];

    window.openPicker(name, options, null, (val) => {
        if (val === "rename") poRenameFolder(id);
        if (val === "add_sub") createNewSubFolder(id);
        if (val === "move") openFolderPicker(el);
        if (val === "delete") poDeleteFolder(id);
    });
};

window.poHandleTitleEnd = function() {
    if (poTitleTimer) {
        clearTimeout(poTitleTimer);
        poTitleTimer = null;
    }
};

window.moveFolder = function(id, dir) {
    const folderEl = document.querySelector(`.po-folder[data-folder-id="${id}"]`);
    if(!folderEl) return;
    
    const sibling = dir === -1 ? folderEl.previousElementSibling : folderEl.nextElementSibling;
    // Agnostic check: Allow moving past folders OR plugins, but not the tools header
    if (!sibling || sibling.id === "po-tools-header" || sibling.id === "po-new-folder-btn") return;

    animateDomSwap(folderEl, sibling, () => {
        if (dir === -1) folderEl.parentNode.insertBefore(folderEl, sibling);
        else folderEl.parentNode.insertBefore(folderEl, sibling.nextElementSibling);
        savePoLayout(true);
    });
}

window.togglePoFolder = function(id) {
    if (poLongPressActive) {
        poLongPressActive = false;
        return;
    }
    const content = document.getElementById(`content-${id}`);
    const arrow = document.getElementById(`arrow-${id}`);
    if(!content || !arrow) return;
    
    if (content.style.display === "none") {
        content.style.display = "block";
        arrow.style.transform = "rotate(0deg)";
    } else {
        content.style.display = "none";
        arrow.style.transform = "rotate(-90deg)";
    }
    savePoLayout(true);
}

window.poRenameFolder = function(id) {
    const el = document.querySelector(`.po-folder[data-folder-id="${id}"]`);
    if(!el) return;
    const span = el.querySelector(".po-folder-header span");
    window.openInput("Rename Folder", "New Name", span.innerText, (newName) => {
        if(newName && newName.trim() !== "") {
            span.innerText = newName.trim();
            savePoLayout(true);
        }
    });
}

window.poDeleteFolder = function(id) {
    window.openConfirm("Delete Folder", "Delete folder? Plugins will be moved to the main hidden list.", () => {
        const el = document.querySelector(`.po-folder[data-folder-id="${id}"]`);
        if(!el) return;
        
        const content = el.querySelector(".po-folder-content");
        const hidCont = document.getElementById("hidden-plugins-container");
        
        while(content.children.length > 0) {
            hidCont.insertBefore(content.children[0], el);
        }
        el.remove();
        savePoLayout(true);
    });
}

function openFolderPicker(targetEl) {
    const isFolder = targetEl.classList.contains("po-folder");
    const folderId = isFolder ? targetEl.getAttribute("data-folder-id") : null;
    const hidCont = document.getElementById("hidden-plugins-container");
    
    const getFoldersRecursive = (container, depth = 0) => {
        const list = [];
        Array.from(container.children).forEach(child => {
            if (child.classList.contains("po-folder")) {
                const id = child.getAttribute("data-folder-id");
                
                // Cycle Prevention: Don't show self or descendants as targets
                if (isFolder && (id === folderId || targetEl.contains(child))) return;

                const name = child.querySelector(".po-folder-header span").innerText;
                const prefix = depth > 0 ? "— ".repeat(depth) : "";
                
                list.push({ id, name: prefix + name });
                
                const content = child.querySelector(".po-folder-content");
                if (content) {
                    list.push(...getFoldersRecursive(content, depth + 1));
                }
            }
        });
        return list;
    };
    
    const folders = getFoldersRecursive(hidCont);
    const options = folders.map(f => ({ label: (f.name.includes("—") ? "" : "📂 ") + f.name, value: f.id }));
    options.unshift({ label: "⬇️ Hidden Plugins (Root)", value: "root" });
    if (!isFolder) options.unshift({ label: "⭐ Visible (Main Settings)", value: "visible" });
    
    if (window.openPicker) {
        window.openPicker(isFolder ? "Move Folder" : "Move Plugin", options, null, async (val) => {
            const pluginName = !isFolder ? targetEl.id.replace("plg-row-", "") : null;

            if (val === "visible") {
                const visCont = document.getElementById("visible-plugins-container");
                if (visCont) {
                    visCont.appendChild(targetEl);
                    if (pluginName) await updateServerUiState('plugins_hidden', 'cjos_hide_' + pluginName, false);
                }
            } else if (val === "root") {
                const hidCont = document.getElementById("hidden-plugins-container");
                const btn = document.getElementById("po-new-folder-btn"); 
                hidCont.insertBefore(targetEl, btn);
                if (pluginName) await updateServerUiState('plugins_hidden', 'cjos_hide_' + pluginName, true);
            } else {
                const target = document.querySelector(`.po-folder[data-folder-id="${val}"] .po-folder-content`);
                if (target) {
                    target.appendChild(targetEl);
                    if (pluginName) await updateServerUiState('plugins_hidden', 'cjos_hide_' + pluginName, true);
                    const content = document.getElementById(`content-${val}`);
                    const arrow = document.getElementById(`arrow-${val}`);
                    if(content && content.style.display === "none") {
                        content.style.display = "block";
                        arrow.style.transform = "rotate(0deg)";
                    }
                }
            }
            savePoLayout(true);
            if (typeof updateHiddenSectionUI === 'function') updateHiddenSectionUI();
        });
    } else {
        window.openConfirm("Plugin Required", "SharedUI Plugin required for picker.", null, false, "OK", null);
    }
}

// 7. Saving
window.poToggleAllFolders = function(expand) {
    const hidCont = document.getElementById("hidden-plugins-container");
    if (!hidCont) return;
    const folders = hidCont.querySelectorAll(".po-folder");
    folders.forEach(f => {
        const id = f.getAttribute("data-folder-id");
        const content = document.getElementById(`content-${id}`);
        const arrow = document.getElementById(`arrow-${id}`);
        if (content && arrow) {
            content.style.display = expand ? "block" : "none";
            arrow.style.transform = expand ? "rotate(0deg)" : "rotate(-90deg)";
        }
    });
    savePoLayout(true);
};

window.poTriggerImport = function() {
    window.openInput("Import Layout", "Paste the Layout JSON provided by AI:", "", (raw) => {
        if (!raw) return;
        try {
            const data = JSON.parse(raw);
            if (!data.hidden_folders && !data.visible) throw new Error("Invalid Format");
            poExecuteImport(data);
        } catch(e) { window.openConfirm("Import Error", "Import Failed: " + e.message, null, false, "OK", null); }
    }, true);
};

function poExecuteImport(data) {
    const hidCont = document.getElementById("hidden-plugins-container");
    const visCont = document.getElementById("visible-plugins-container");
    if (!hidCont || !visCont) return;

    // 1. Detach ALL plugins to a temporary fragment to prevent loss
    const allPlugins = document.querySelectorAll(".plugin-block");
    const pluginMap = {};
    allPlugins.forEach(p => {
        const name = p.id.replace("plg-row-", "");
        pluginMap[name] = p;
        hidCont.appendChild(p); // Move all to root initially
    });

    // 2. Clear all existing folders
    document.querySelectorAll(".po-folder").forEach(f => f.remove());

    // 3. Handle Visibility Settings
    if (data.show_visibility_btn !== undefined) {
        poLayout.show_visibility_btn = data.show_visibility_btn;
        const toggle = document.getElementById("po-show-visibility-toggle");
        if (toggle) toggle.checked = poLayout.show_visibility_btn;
        poApplyVisibilityIconStyle();
    }
    if (data.show_status_toggles !== undefined) {
        poLayout.show_status_toggles = data.show_status_toggles;
        const toggle = document.getElementById("po-show-status-toggle");
        if (toggle) toggle.checked = poLayout.show_status_toggles;
        poApplyStatusToggleStyle();
    }

    // 4. Handle Visible Plugins
    if (data.visible) {
        data.visible.forEach(pName => {
            if (pluginMap[pName]) {
                visCont.appendChild(pluginMap[pName]);
                delete pluginMap[pName];
            }
        });
    }

    // 5. Recursive Builder for Hidden Folders
    const buildNode = (node, container) => {
        const folderId = node.id || ("f_" + Math.random().toString(36).substr(2, 9));
        const folderEl = createFolderDOM(folderId, node.name, node.isOpen !== false);
        container.appendChild(folderEl);
        const content = folderEl.querySelector(".po-folder-content");

        if (node.folders) {
            node.folders.forEach(sub => buildNode(sub, content));
        }

        if (node.plugins) {
            node.plugins.forEach(pName => {
                if (pluginMap[pName]) {
                    content.appendChild(pluginMap[pName]);
                    delete pluginMap[pName];
                }
            });
        }
    };

    // 6. Run Folder Import
    const header = document.getElementById("po-tools-header");
    if (data.hidden_folders) {
        data.hidden_folders.forEach(rootFolder => {
            buildNode(rootFolder, hidCont);
            const newFolder = hidCont.lastElementChild;
            if (header && header.nextSibling) hidCont.insertBefore(newFolder, header.nextSibling);
        });
    }

    // 7. Finalize
    savePoLayout(true);
    window.openConfirm("Import Success", "Layout Imported Successfully. Unassigned plugins remain in root.", null, false, "OK", null);
}

window.poGetLayoutObject = function() {
    const newLayout = { 
        visible: [], 
        hidden_folders: [], 
        hidden_root: [], 
        hidden_order: [], 
        show_visibility_btn: poLayout.show_visibility_btn,
        show_status_toggles: poLayout.show_status_toggles
    };
    
    const visCont = document.getElementById("visible-plugins-container");
    if(visCont) {
        visCont.querySelectorAll(".plugin-block").forEach(el => {
            newLayout.visible.push(el.id.replace("plg-row-", ""));
        });
    }
    
    const hidCont = document.getElementById("hidden-plugins-container");
    if(!hidCont) return newLayout;

    const captureFolder = (folderEl) => {
        const id = folderEl.getAttribute("data-folder-id");
        const name = folderEl.querySelector(".po-folder-header span").innerText;
        const content = folderEl.querySelector(".po-folder-content");
        const isOpen = content && content.style.display !== "none";
        
        const plugins = [];
        const subFolders = [];
        
        Array.from(content.children).forEach(child => {
            if (child.classList.contains("plugin-block")) {
                plugins.push(child.id.replace("plg-row-", ""));
            } else if (child.classList.contains("po-folder")) {
                subFolders.push(captureFolder(child));
            }
        });
        
        return { id, name, plugins, folders: subFolders, isOpen };
    };
    
    Array.from(hidCont.children).forEach(child => {
        if (child.classList.contains("po-folder")) {
            const folderData = captureFolder(child);
            newLayout.hidden_folders.push(folderData);
            newLayout.hidden_order.push({ type: 'folder', id: folderData.id });
        } else if (child.classList.contains("plugin-block")) {
            const name = child.id.replace("plg-row-", "");
            newLayout.hidden_root.push(name);
            newLayout.hidden_order.push({ type: 'plugin', id: name });
        }
    });

    return newLayout;
};

window.poTriggerExport = async function() {
    const layout = window.poGetLayoutObject();
    const json = JSON.stringify(layout, null, 2);
    const wrapped = "```json\n" + json + "\n```";
    
    try {
        await navigator.clipboard.writeText(wrapped);
        window.sui.toast("Layout Copied to Clipboard");
        if (window.sui && window.sui.haptic) window.sui.haptic('success');
    } catch(e) {
        window.openConfirm("Export Error", "Failed to copy to clipboard.", null, false, "OK", null);
    }
};

window.savePoLayout = async function(isSilent = false) {
    const newLayout = window.poGetLayoutObject();

    try {
        const data = await window.sui.api("po_save_layout", { layout: newLayout }, { toast: !isSilent });
        poLayout = newLayout;
        
        if (!isSilent) {
            window.openConfirm("Success", "Layout saved.", () => {
                togglePoEditMode(false);
            }, false, "OK", null);
        }
    } catch(e) {
        if (!isSilent) window.openConfirm("Error", "Error saving layout", null, false, "OK", null);
    }
};
JS;
?>