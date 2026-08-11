<?php
// ==============================================================================
// PLUGIN: Plugin Manager
// DESCRIPTION: Update and Create Plugins.
// Add, Update (Paste), and Restore plugins directly from the Settings UI.
// UPDATED: Now parses backup headers to show version descriptions in history.
// ==============================================================================

// --- BACKEND LOGIC ---

$pm_plugin_dir = CJOS_PATH_PLUGINS;
$pm_backup_dir = CJOS_PATH_APP . '/backups/plugins';
if (!is_dir($pm_backup_dir)) mkdir($pm_backup_dir, 0777, true);

// HELPER: Robust Response
function pm_send_json($data) {
    // 1. Aggressive Buffer Clean
    error_reporting(0);
    ini_set('display_errors', 0);
    while (ob_get_level()) ob_end_clean();
    
    // 2. Standard Output
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if (isset($_POST['plugin_action'])) {
    
    // ACTION: ADD
    if($_POST['plugin_action'] === 'pm_add') {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['name']); 
        $content = $_POST['content'];
        
        if(empty($name) || empty($content)) pm_send_json(['status'=>'error','message'=>'Empty data']);
        
        $path = $pm_plugin_dir . '/' . $name . '.php';
        if(file_exists($path)) pm_send_json(['status'=>'error','message'=>'Plugin already exists']);
        
        file_put_contents($path, $content);
        pm_send_json(['status'=>'success']);
    }

    // ACTION: LIST
    if($_POST['plugin_action'] === 'pm_list') {
        $files = glob($pm_plugin_dir . '/*.php');
        $list = [];
        foreach($files as $f) {
            $list[] = basename($f, '.php');
        }
        sort($list);
        pm_send_json(['status'=>'success', 'plugins'=>$list]);
    }

    // ACTION: UPDATE
    if($_POST['plugin_action'] === 'pm_update') {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['name']);
        $content = $_POST['content'];
        $file = $pm_plugin_dir . '/' . $name . '.php';
        
        if(!file_exists($file)) pm_send_json(['status'=>'error','message'=>'File not found']);

        // Backup
        $ts = date('Ymd_His');
        $backup_file = $pm_backup_dir . '/' . $name . '_' . $ts . '.php';
        copy($file, $backup_file);

        // Prune (Keep Max 20)
        $backups = glob($pm_backup_dir . '/' . $name . '_*.php');
        if (count($backups) > 20) {
            usort($backups, function($a, $b) { return filemtime($a) - filemtime($b); });
            $to_delete = array_slice($backups, 0, count($backups) - 20);
            foreach($to_delete as $f) unlink($f);
        }

        file_put_contents($file, $content);
        pm_send_json(['status'=>'success']);
    }

    // ACTION: HISTORY (UPDATED TO PARSE DESCRIPTIONS)
    if($_POST['plugin_action'] === 'pm_history') {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['name']);
        
        // Scan backups directory
        $files = glob($pm_backup_dir . '/' . $name . '_*.php');
        $history = [];
        
        foreach($files as $f) {
            $bn = basename($f);
            if(preg_match('/_(\d{8}_\d{6})\.php$/', $bn, $m)) {
                $raw_ts = $m[1]; // 20251215_084200
                $formatted = date('M j, H:i', strtotime(str_replace('_', ' ', $raw_ts)));
                
                // Extract Description from File Header
                $desc = "";
                $header_content = file_get_contents($f, false, null, 0, 1024); // Read top 1KB
                $lines = explode("\n", $header_content);
                $foundPluginLine = false;
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    // Skip separators and empty comments
                    if (strpos($line, '//') !== 0 || strpos($line, '==') !== false) continue;
                    
                    // Logic: Find "PLUGIN:", then grab the NEXT meaningful comment line
                    if (stripos($line, 'PLUGIN:') !== false) {
                        $foundPluginLine = true;
                        continue;
                    }
                    
                    if ($foundPluginLine) {
                        // Clean up "// "
                        $clean = trim(substr($line, 2));
                        if (!empty($clean)) {
                            $desc = mb_strimwidth($clean, 0, 50, "...");
                            break; // Stop after finding the first description line
                        }
                    }
                }

                // Append description to display if found
                $display_text = $formatted;
                if (!empty($desc)) {
                    $display_text .= " - <span style='opacity:0.6; font-weight:400;'>" . htmlspecialchars($desc) . "</span>";
                }

                $history[] = [
                    'file' => $bn,
                    'ts' => $raw_ts,
                    'display' => $display_text
                ];
            }
        }
        
        // Sort newest first
        usort($history, function($a, $b) { return strcmp($b['ts'], $a['ts']); });
        
        pm_send_json(['status'=>'success', 'history'=>$history]);
    }

    // ACTION: RESTORE
    if($_POST['plugin_action'] === 'pm_restore') {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['name']);
        $backup_file = basename($_POST['file']); 
        $source = $pm_backup_dir . '/' . $backup_file;
        $dest = $pm_plugin_dir . '/' . $name . '.php';
        
        if(file_exists($source)) {
            copy($source, $dest);
            pm_send_json(['status'=>'success']);
        } else {
            pm_send_json(['status'=>'error']);
        }
    }
}

// --- FRONTEND: SETTINGS UI ---
$plugin_settings_map['PluginManager'] = <<<'HTML'
    <style>
        .pm-accordion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 12px 16px 12px 16px;
            padding: 14px 18px;
            background: var(--btn-bg);
            border-radius: 14px;
            cursor: pointer;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
            transition: transform 0.1s, filter 0.2s;
            border: 1px solid var(--border-color);
        }
        .pm-accordion-header:active {
            filter: brightness(0.92);
            transform: scale(0.98);
        }
        .pm-accordion-header span {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--text-secondary);
            letter-spacing: 0.8px;
        }
    </style>

    <!-- ADD PLUGIN SECTION -->
    <div class="pm-accordion-header" onclick="suiToggle('sec-pm-add', true)">
        <span>Add New Plugin</span>
        <span data-sui-icon="chevron" data-sui-arrow="sec-pm-add" data-sui-size="18" data-sui-stroke="3" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>
    
    <div class="sui-accordion" id="sec-pm-add">
        <div class="sui-accordion-inner">
            <div class="settings-group">
                <div class="setting-item vertical">
                    <label class="setting-label">Paste from Clipboard</label>
                    <div class="setting-desc">Creates a new .php file in /plugins.</div>
                    <button onclick="pmAddFromClipboard()" class="text-btn" style="width:100%; background:var(--primary); color:var(--primary-text); border-radius:12px; padding:12px; font-weight:600; margin-top:8px;">Paste & Create</button>
                </div>
            </div>
        </div>
    </div>
    <script>initSectionState('sec-pm-add', 'arr-pm-add');</script>

    <!-- EDIT PLUGIN SECTION -->
    <div class="pm-accordion-header" onclick="suiToggle('sec-pm-edit', true)">
        <span>Edit & Restore</span>
        <span data-sui-icon="chevron" data-sui-arrow="sec-pm-edit" data-sui-size="18" data-sui-stroke="3" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>
    
    <div class="sui-accordion" id="sec-pm-edit">
        <div class="sui-accordion-inner">
            <div class="settings-group">
                
                <!-- SELECTOR -->
                <div class="setting-item vertical">
                    <label class="setting-label">Select Plugin</label>
                    <div class="setting-desc" style="margin-bottom:8px;">Selected: <span id="pm-selected-display" style="font-weight:600; color:var(--primary);">None</span></div>
                    <button onclick="pmOpenSelector()" class="text-btn" style="
                        width:100%; text-align:center; background:var(--input-bg); color:var(--input-text); border: 1px solid var(--border-color);
                        padding:12px; border-radius:10px; font-weight:600; border:1px solid var(--border-color);
                    ">Choose Plugin...</button>
                </div>

                <!-- ACTIONS (Hidden until selected) -->
                <div id="pm-actions-area" style="display:none;">
                    
                    <!-- PASTE OVERRIDE -->
                    <div class="setting-item vertical">
                        <label class="setting-label">Update Code</label>
                        <div class="setting-desc">Overwrites file with clipboard content. Auto-backs up first.</div>
                        <button onclick="pmUpdateFromClipboard()" class="text-btn" style="width:100%; background:var(--success-bg); color:var(--success-text); border:1px solid var(--border-color); border-radius:12px; padding:12px; font-weight:600; margin-top:8px;">Paste & Replace</button>
                    </div>

                    <!-- HISTORY (UPDATED TO USE BUTTON) -->
                    <div class="setting-item vertical">
                        <label class="setting-label">Backups & Restore</label>
                        <div class="setting-desc">Select a previous version to restore.</div>
                        <button onclick="pmOpenHistoryPicker()" class="text-btn" style="width:100%; background:var(--btn-bg); color:var(--btn-text); border-radius:12px; padding:12px; font-weight:600; margin-top:8px; border:1px solid var(--border-color);">View Backups...</button>
                    </div>

                </div>
            </div>
        </div>
    </div>
    <script>suiInit('sec-pm-edit');</script>
HTML;

// --- FRONTEND: JS LOGIC ---
$plugin_js .= <<<'JS'
// --- PLUGIN MANAGER JS ---
let pmSelectedPlugin = null;

// Init: Restore last selection from persistence
window.addEventListener("load", () => {
    const last = localStorage.getItem("cjos_pm_last_plugin");
    if(last) {
        pmSelectPlugin(last);
        const sec = document.getElementById("sec-pm-edit");
        const arr = document.getElementById("arr-pm-edit");
        if(sec && arr && !sec.classList.contains("open")) {
            sec.classList.add("open");
            arr.style.transform = "rotate(0deg)";
        }
    }
});

// Helper: Extract JSON from polluted response
function pmParseResponse(text) {
    const match = text.match(/\|\|\|JSON_START\|\|\|([\s\S]*?)\|\|\|JSON_END\|\|\|/);
    if (match && match[1]) {
        return JSON.parse(match[1]);
    }
    throw new Error("Invalid response. Raw: " + text.substring(0, 100));
}

// Helper: Manual Paste Fallback UI
function pmShowManualUI(title, callback) {
    const id = "pm-manual-overlay";
    let overlay = document.getElementById(id);
    if (overlay) overlay.remove();
    
    overlay = document.createElement("div");
    overlay.id = id;
    overlay.style.cssText = "position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(5px);";
    
    overlay.innerHTML = `
        <div style="background:white; width:90%; max-width:500px; height:80vh; border-radius:16px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
            <div style="padding:16px; background:#F2F2F7; border-bottom:1px solid #E5E5EA; display:flex; justify-content:space-between; align-items:center;">
                <div style="font-weight:700; font-size:16px;">${title}</div>
                <button id="pm-manual-close" style="background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
            </div>
            <div style="flex:1; padding:16px; display:flex; flex-direction:column; box-sizing:border-box;">
                <div style="font-size:13px; color:#666; margin-bottom:8px;">Browser blocked auto-paste. Please paste code below manually:</div>
                <textarea id="pm-manual-text" style="flex:1; width:100%; border:1px solid #CCC; border-radius:8px; padding:10px; font-family:monospace; font-size:12px; resize:none; box-sizing:border-box; outline-color:var(--primary);"></textarea>
            </div>
            <div style="padding:16px; border-top:1px solid #E5E5EA; background:white;">
                <button id="pm-manual-save" style="width:100%; background:var(--primary); color:white; border:none; padding:12px; border-radius:10px; font-weight:600;">Save Code</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(overlay);
    document.getElementById("pm-manual-close").onclick = () => overlay.remove();
    document.getElementById("pm-manual-save").onclick = () => {
        const content = document.getElementById("pm-manual-text").value;
        if(!content.trim()) { window.openConfirm("Input Required", "Please paste code first.", null, false, "OK", null); return; }
        overlay.remove();
        callback(content);
    };
    setTimeout(() => document.getElementById("pm-manual-text").focus(), 100);
}

// 1. ADD NEW
async function pmAddFromClipboard() {
    window.openInput("Add New Plugin", "Plugin Name (e.g. MyNewPlugin)", "", async (name) => {
        if (!name) return;
        try {
        if (!navigator.clipboard) throw new Error("No API");
        const text = await navigator.clipboard.readText();
        if (!text || text.trim().length === 0) throw new Error("Empty");
        pmSubmitAdd(name, text);
        } catch (e) {
            pmShowManualUI("Paste New Plugin Code", (content) => {
                pmSubmitAdd(name, content);
            });
        }
    });
}

async function pmSubmitAdd(name, content) {
    try {
        const data = await window.sui.api("pm_add", { name, content }, { toast: false });
        if (data) {
            window.openConfirm("Plugin Created", "Plugin created! Refresh to load it?", () => {
                location.reload();
            });
        } else {
            window.openConfirm("Plugin Error", "Error: " + data.message, null, false, "OK", null);
        }
    } catch(e) {
        window.openConfirm("Server Error", "Server Error: " + e, null, false, "OK", null);
    }
}

// 2. OPEN SELECTOR
async function pmOpenSelector() {
    if (typeof window.openPicker !== "function") {
        window.openConfirm("System Error", "Please enable the 'SharedUI' plugin.", null, false, "OK", null);
        return;
    }

    try {
        const data = await window.sui.api("pm_list", {}, { toast: false });
        if (data) {
            const options = data.plugins.map(p => ({ label: p, value: p }));
            window.openPicker("Select Plugin", options, pmSelectedPlugin, (val) => {
                pmSelectPlugin(val);
            });
        } else {
            window.openConfirm("API Error", "Failed to load list.", null, false, "OK", null);
        }
    } catch(e) {
        window.openConfirm("API Error", "API Error: " + e, null, false, "OK", null);
    }
}

function pmSelectPlugin(name) {
    pmSelectedPlugin = name;
    localStorage.setItem("cjos_pm_last_plugin", name);
    document.getElementById("pm-selected-display").innerText = name;
    document.getElementById("pm-actions-area").style.display = "block";
}

// 3. UPDATE
async function pmUpdateFromClipboard() {
    if(!pmSelectedPlugin) return;
    window.openConfirm("Update Plugin", "Overwrite " + pmSelectedPlugin + ".php? A backup will be created.", async () => {
        try {
        if (!navigator.clipboard) throw new Error("No API");
        const text = await navigator.clipboard.readText();
        if (!text || text.trim().length === 0) throw new Error("Empty");
        pmSubmitUpdate(pmSelectedPlugin, text);
        } catch (e) {
            pmShowManualUI("Paste Update for " + pmSelectedPlugin, (content) => {
                pmSubmitUpdate(pmSelectedPlugin, content);
            });
        }
    });
}

async function pmSubmitUpdate(name, content) {
    try {
        const data = await window.sui.api("pm_update", { name, content }, { toast: false });
        if(data) {
            window.openConfirm("Update Success", "Updated successfully. Refresh app?", () => {
                location.reload();
            });
        } else {
            window.openConfirm("Update Error", "Error: " + data.message, null, false, "OK", null);
        }
    } catch(e) {
        window.openConfirm("Server Error", "Server Error: " + e, null, false, "OK", null);
    }
}

// 4. OPEN HISTORY PICKER
async function pmOpenHistoryPicker() {
    if(!pmSelectedPlugin) return;
    if (typeof window.openPicker !== "function") {
        window.openConfirm("System Error", "Please enable the 'SharedUI' plugin.", null, false, "OK", null);
        return;
    }

    try {
        const data = await window.sui.api("pm_history", { name: pmSelectedPlugin }, { toast: false });
        if(data && data.history.length > 0) {
            const options = data.history.map(h => ({
                label: h.display, // Now contains HTML description
                value: h.file
            }));
            
            window.openPicker('Restore ' + pmSelectedPlugin, options, null, (val) => {
                pmRestore(pmSelectedPlugin, val);
            });
        } else {
            window.openConfirm("History Empty", "No backups found.", null, false, "OK", null);
        }
    } catch(e) {
        window.openConfirm("History Error", "Error loading history: " + e, null, false, "OK", null);
    }
}

// 5. RESTORE
async function pmRestore(name, file) {
    window.openConfirm("Restore Version", "Restore this version?", async () => {
        try {
            const data = await window.sui.api("pm_restore", { name, file }, { toast: false });
            if(data) {
                window.openConfirm("Restore Success", "Restored. Refresh app?", () => {
                    location.reload();
                });
            } else {
                window.openConfirm("Restore Error", "Restore failed.", null, false, "OK", null);
            }
        } catch (e) {
            window.openConfirm("Server Error", "Server Error: " + e, null, false, "OK", null);
        }
    });
}
JS;
?>