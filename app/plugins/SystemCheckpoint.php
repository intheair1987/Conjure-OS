<?php
// ==============================================================================
// PLUGIN: System Checkpoint
// DESCRIPTION: Fail-Safe State Snapshots.
// Features: 
// 1. Standalone 'recovery.php' (Bunker) with Create, Restore, and Delete.
// 2. PRG Pattern: Refreshing the bunker no longer re-triggers actions.
// 3. Smart Defaults: Uses timestamps if labels are left blank.
// 4. Aesthetic: Emergency Terminal UI with long-name wrapping and Crimson accents.
// 5. Zero-Dependency: Works even if the main app is completely destroyed.
// ==============================================================================

$sc_backup_dir = CJOS_PATH_APP . '/backups/checkpoints';
$sc_config_file = CJOS_PATH_DATA . '/checkpoint-config.json';
if (!is_dir($sc_backup_dir)) mkdir($sc_backup_dir, 0777, true);

// --- CORE SHARED LOGIC ---
require_once CJOS_PATH_APP . '/api/sc_logic_create.php';
require_once CJOS_PATH_APP . '/api/sc_logic_restore.php';
require_once CJOS_PATH_APP . '/api/sc_logic_bunker.php';

// --- DATA BRIDGE ---
$sc_conf_data = ['show_shield' => false, 'show_undo' => false];
if (file_exists($sc_config_file)) {
    $loaded = json_decode(file_get_contents($sc_config_file), true);
    if (isset($loaded['show_shortcut'])) {
        $sc_conf_data['show_shield'] = $loaded['show_shortcut'];
        $sc_conf_data['show_undo'] = $loaded['show_shortcut'];
    }
    if (isset($loaded['show_shield'])) $sc_conf_data['show_shield'] = $loaded['show_shield'];
    if (isset($loaded['show_undo'])) $sc_conf_data['show_undo'] = $loaded['show_undo'];
}
$sc_files = glob($sc_backup_dir . "/*.zip");
$sc_conf_data['total_count'] = count($sc_files);
$sc_bridge_json = json_encode(['config' => $sc_conf_data]);
$plugin_js .= "\nwindow.__SC_BRIDGE__ = $sc_bridge_json;\n";



// --- 2. CORE LOGIC (For In-App Use) ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'sc_prune') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $keep = (int)($_POST['keep'] ?? 20);
        $files = glob($sc_backup_dir . "/*.zip");
        
        // Load meta to protect starred items
        $metaFile = $sc_backup_dir . "/meta.json";
        $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];

        $majors = [];
        $starred_diffs = [];
        $unstarred_diffs = [];

        // Classify files safely into their respective buckets
        foreach ($files as $f) {
            $bn = basename($f);
            if (strpos($bn, 'DIFF_') === 0) {
                if (isset($meta[$bn])) {
                    $starred_diffs[] = $f;
                } else {
                    $unstarred_diffs[] = $f;
                }
            } else {
                $majors[] = $f; // Legacy or major backups
            }
        }

        // Sort unstarred differentials newest first using filename timestamps
        usort($unstarred_diffs, function($a, $b) {
            preg_match('/(\d{8}_\d{6})/', $a, $mA);
            preg_match('/(\d{8}_\d{6})/', $b, $mB);
            if (isset($mA[1]) && isset($mB[1])) {
                return strcmp($mB[1], $mA[1]);
            }
            return filemtime($b) - filemtime($a);
        });

        // Slice the unstarred differentials based on the keep limit
        $kept_unstarred_diffs = array_slice($unstarred_diffs, 0, $keep);
        $pruned_unstarred_diffs = array_slice($unstarred_diffs, $keep);

        // Combine all kept differentials (starred + those within limit)
        $kept_diffs = array_merge($kept_unstarred_diffs, $starred_diffs);
        $connected_majors = [];
        $oldest_kept_unstarred_timestamp = null;

        // Find the oldest timeframe based ONLY on unstarred kept diffs
        if (!empty($kept_unstarred_diffs)) {
            // Since $kept_unstarred_diffs is sorted newest first, the last element is the oldest
            $oldest_unstarred = end($kept_unstarred_diffs);
            if (preg_match('/(\d{8}_\d{6})/', basename($oldest_unstarred), $m)) {
                $oldest_kept_unstarred_timestamp = $m[1];
            }
        }

        // Resolve which major baselines must be protected
        foreach ($kept_diffs as $diffPath) {
            $zip = new ZipArchive();
            if ($zip->open($diffPath) === TRUE) {
                $diffJson = $zip->getFromName('sc_diff.json');
                if ($diffJson) {
                    $diffMeta = json_decode($diffJson, true);
                    if (isset($diffMeta['base_ref'])) {
                        $connected_majors[basename($diffMeta['base_ref'])] = true;
                    }
                }
                $zip->close();
            }
        }

        $deletedCount = 0;

        // 1. Delete unstarred differentials that exceeded the limit
        foreach ($pruned_unstarred_diffs as $f) {
            if (@unlink($f)) {
                $deletedCount++;
            }
        }

        // 2. Delete unstarred major backups that have no remaining dependencies AND fall outside the timeframe
        foreach ($majors as $f) {
            $bn = basename($f);
            
            if (isset($meta[$bn])) {
                continue; // Protected because it is starred
            }
            if (isset($connected_majors[$bn])) {
                continue; // Protected because a kept differential depends on it
            }
            
            // Protect if it falls within the active timeframe (newer than the oldest kept UNSTARRED differential)
            if ($oldest_kept_unstarred_timestamp !== null && preg_match('/(\d{8}_\d{6})/', $bn, $m)) {
                $major_ts = $m[1];
                if (strcmp($major_ts, $oldest_kept_unstarred_timestamp) >= 0) {
                    continue; // Protected: It is an intermediate major snapshot within the rolling timeline
                }
            }

            if (@unlink($f)) {
                $deletedCount++;
            }
        }

        echo json_encode(['status' => 'success', 'deleted' => $deletedCount]);
        exit;
    }

    if ($_POST['plugin_action'] === 'sc_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $conf = ['show_shield' => false, 'show_undo' => false];
        if (file_exists($sc_config_file)) {
            $loaded = json_decode(file_get_contents($sc_config_file), true);
            // Migration: Map old 'show_shortcut' to both new keys if present
            if (isset($loaded['show_shortcut'])) {
                $conf['show_shield'] = $loaded['show_shortcut'];
                $conf['show_undo'] = $loaded['show_shortcut'];
            }
            if (isset($loaded['show_shield'])) $conf['show_shield'] = $loaded['show_shield'];
            if (isset($loaded['show_undo'])) $conf['show_undo'] = $loaded['show_undo'];
        }
        $files = glob($sc_backup_dir . "/*.zip");
        $conf['total_count'] = count($files);
        echo json_encode(['status' => 'success', 'config' => $conf]);
        exit;
    }

    if ($_POST['plugin_action'] === 'sc_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $conf = [
            'show_shield' => ($_POST['show_shield'] === 'true'),
            'show_undo' => ($_POST['show_undo'] === 'true')
        ];
        file_put_contents($sc_config_file, json_encode($conf));
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- ACTION: RESTORE LATEST ---
// --- ACTION: GET LOG FROM LATEST ZIP ---
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'sc_get_latest_log') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $files = glob($sc_backup_dir . "/*.zip");
    if (empty($files)) {
        echo json_encode(['status' => 'success', 'log' => []]);
        exit;
    }

    usort($files, function($a, $b) {
        preg_match('/(\d{8}_\d{6})/', $a, $mA);
        preg_match('/(\d{8}_\d{6})/', $b, $mB);
        return strcmp($mB[1] ?? '', $mA[1] ?? '');
    });
    $latestZip = $files[0];
    $logData = [];

    $zip = new ZipArchive();
    if ($zip->open($latestZip) === TRUE) {
        $relData = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_DATA);
        // Try state manifest first (fast), fallback to legacy json
        $content = $zip->getFromName($relData . "/edit-log-state.json") ?: $zip->getFromName($relData . "/edit-log.json");
        
        // --- CHAINED LOOKUP FOR DIFFERENTIALS ---
        if (!$content) {
            $diffJson = $zip->getFromName('sc_diff.json');
            if ($diffJson) {
                $diffMeta = json_decode($diffJson, true);
                $baseRef = $diffMeta['base_ref'] ?? null;
                if ($baseRef && file_exists($sc_backup_dir . '/' . $baseRef)) {
                    $baseZip = new ZipArchive();
                    if ($baseZip->open($sc_backup_dir . '/' . $baseRef) === TRUE) {
                        $content = $baseZip->getFromName($relData . "/edit-log-state.json") ?: $baseZip->getFromName($relData . "/edit-log.json");
                        $baseZip->close();
                    }
                }
            }
        }

        if ($content) $logData = json_decode($content, true);
        $zip->close();
    }

    // Manifest is already newest-first
    echo json_encode(['status' => 'success', 'log' => $logData ?: []]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'sc_restore_latest') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $files = glob($sc_backup_dir . "/*.zip");
    if (empty($files)) {
        echo json_encode(['status' => 'error', 'message' => 'No checkpoints found.']);
        exit;
    }

    usort($files, function($a, $b) {
        preg_match('/(\d{8}_\d{6})/', $a, $mA);
        preg_match('/(\d{8}_\d{6})/', $b, $mB);
        return strcmp($mB[1] ?? '', $mA[1] ?? '');
    });
    $latestZip = $files[0];
    $protected = sc_get_protected_apps();
    $result = sc_perform_restore($latestZip, null, $protected);

    if ($result) {
        echo json_encode([
            'status' => 'success', 
            'client_state' => is_array($result) ? $result : null,
            'label' => str_replace('.zip', '', substr(basename($latestZip), 15))
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Restore failed.']);
    }
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'sc_generate_bunker') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    sc_generate_bunker();
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'sc_create') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
        
    set_time_limit(0);ini_set('memory_limit', '1024M');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $rawName = $_POST['name'] ?? "";
    $name = str_replace(' ', '', ucwords(preg_replace('/[^a-zA-Z0-9]/', ' ', $rawName))) ?: "Manual";
    $timestamp = date('Ymd_His');
    $zipPath = $sc_backup_dir . "/{$timestamp}_{$name}.zip";
    
    $type = (isset($_POST['force_major']) && $_POST['force_major'] === '1') ? 'major' : 'auto';
    $clientState = $_POST['client_state'] ?? null;
    $success = sc_perform_create($zipPath, $clientState, null, $type);
    
    if ($success) {
    echo json_encode(['status' => 'success']);
} else {echo json_encode(['status' => 'error', 'message' => 'Failed to create checkpoint.']);
    }
    exit;
}

// --- 3. SETTINGS UI ---
$plugin_settings_map['SystemCheckpoint'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Emergency Recovery</label>
        <div class="setting-desc">
            Standalone <strong>recovery.php</strong> console for saving or reverting system state.
        </div>
        
        <label class="setting-desc" style="margin-top:12px; font-size:11px; font-weight:700; color:#FF3B30; text-transform:uppercase;">Recovery URL (Bookmark this)</label>
        <div style="position:relative; margin-top:4px; margin-bottom:12px;">
            <input type="text" id="sc-bunker-url" readonly style="
                width:100%; padding:12px; padding-right:40px; border-radius:10px; 
                border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text); 
                font-family:monospace; font-size:11px; cursor:pointer; outline:none;
            " onclick="scCopyBunkerUrl()" onfocus="this.blur()">
            <div style="position:absolute; right:12px; top:50%; transform:translateY(-50%); pointer-events:none; color:#FF3B30;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:16px; height:16px; stroke-width:2.5;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            </div>
        </div>

        <!-- 1. OPEN BUNKER -->
        <div style="display:flex; gap:8px; margin-bottom:8px;">
            <button onclick="scShowBunkerMenu(event)" class="text-btn" style="flex:1; display:flex; align-items:center; justify-content:center; background:var(--danger); color:var(--primary-text); border:none; border-radius:12px; padding:12px; font-weight:600; box-sizing:border-box; cursor:pointer;">
                Open Bunker
            </button>
            <button onclick="scGenerateBunker(event)" class="text-btn" style="flex:1; display:flex; align-items:center; justify-content:center; background:var(--btn-bg); color:var(--text-primary); border-radius:12px; padding:12px; font-weight:600; border:1px solid var(--border-color);">
                Rebuild Bunker
            </button>
        </div>

        <!-- 2. CREATE CHECKPOINT -->
        <button onclick="scCreateCheckpoint()" id="sc-btn-save" class="text-btn" style="width:100%; display:flex; align-items:center; justify-content:center; background:var(--primary); color:var(--primary-text); border-radius:12px; padding:12px; font-weight:600; border:none; margin-bottom:4px;">
            Create Checkpoint
        </button>
        <div id="sc-ts-hint" style="font-size:11px; color:var(--text-secondary); opacity:0.6; text-align:center; margin-bottom:12px;"></div>

        <!-- PRUNE CHECKPOINTS -->
        <div style="border-top:1px solid var(--border-color); padding-top: 16px; margin-top: 16px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <label class="setting-label" style="margin:0;">Prune Snapshots <span id="sc-total-count" style="font-weight:400; opacity:0.5; font-size:11px; margin-left:4px;"></span></label>
                <span id="sc-prune-val" style="font-weight:700; color:var(--primary); font-size:14px;">20</span>
            </div>
            <div class="setting-desc">Keep only the latest snapshots. (Starred items are never pruned)</div>
            <input type="range" id="sc-prune-slider" min="20" max="100" step="1" value="20" oninput="document.getElementById('sc-prune-val').innerText = this.value" style="margin-top:8px;">
            <button onclick="scPruneCheckpoints()" id="sc-btn-prune" class="text-btn" style="width:100%; background:var(--btn-bg); color:var(--text-primary); border:1px solid var(--border-color); border-radius:10px; padding:10px; font-weight:600; margin-top:10px;">Prune Old Checkpoints</button>
        </div>

        <!-- 3. HEADER SHORTCUTS -->
        <div class="setting-item" style="border-top:1px solid var(--border-color); padding-top: 16px; margin-top: 16px;">
            <div class="setting-text-wrap">
                <label class="setting-label">Header Shortcuts</label>
                <span class="setting-desc">Quick actions in the settings title bar.</span>
            </div>
            <div style="display:flex; flex-direction:column; gap:12px; align-items:flex-end;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Shield (Save)</span>
                    <div data-sui-switch="true" data-sui-id="sc-show-shield-toggle" data-sui-onchange="scSaveSettings()"></div>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Undo (Restore)</span>
                    <div data-sui-switch="true" data-sui-id="sc-show-undo-toggle" data-sui-onchange="scSaveSettings()"></div>
                </div>
            </div>
        </div>
    </div>
HTML;

$plugin_js .= <<<'JS'
window.addEventListener("load", () => {
    const input = document.getElementById("sc-bunker-url");
    if(input) {
        const base = window.location.href.split("/index.php")[0].split("?")[0];
        input.value = base + "/recovery.php";
    }
    const hint = document.getElementById("sc-ts-hint");
    if(hint) {
        const updateHint = () => {
            // Battery Friendly: Only run formatting and DOM writes when the tab is active and the settings element is visible
            if (document.visibilityState !== "visible" || hint.offsetParent === null) return;
            const now = new Date();
            const ts = now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, '0') + "-" + String(now.getDate()).padStart(2, '0') + " " + String(now.getHours()).padStart(2, '0') + ":" + String(now.getMinutes()).padStart(2, '0') + ":" + String(now.getSeconds()).padStart(2, '0');
            hint.innerText = "Default label: " + ts;
        };
        updateHint(); setInterval(updateHint, 1000);
    }
});

window.scCopyBunkerUrl = function() {
    const input = document.getElementById("sc-bunker-url");
    if(!input) return;
    navigator.clipboard.writeText(input.value);
    if (window.sui && window.sui.toast) { window.sui.toast("URL Copied", { plugin: "SystemCheckpoint", caller: "scCopyBunkerUrl" }); }};

window.scShowBunkerMenu = function(e) {
    if (e) e.preventDefault();
    const options = [
        { 
            label: `<div style="display:flex; align-items:center; gap:10px;">
                        <span style="color:var(--primary);">${window.suiIcon('maximize-2', 'currentColor', 18)}</span>
                        <div style="text-align:left;">
                            <div style="font-weight:700; font-size:14px; color:var(--text-primary);">Open in Overlay</div>
                            <div style="font-size:11px; color:var(--text-secondary);">Stay inside the Conjure app</div>
                        </div>
                    </div>`, 
            value: "overlay" 
        },
        { 
            label: `<div style="display:flex; align-items:center; gap:10px;">
                        <span style="color:var(--danger);">${window.suiIcon('external-link', 'currentColor', 18)}</span>
                        <div style="text-align:left;">
                            <div style="font-weight:700; font-size:14px; color:var(--text-primary);">Open Directly</div>
                            <div style="font-size:11px; color:var(--text-secondary);">Navigate away to the standalone bunker</div>
                        </div>
                    </div>`, 
            value: "direct" 
        }
    ];
    if (window.openPicker) {
        window.openPicker("Recovery Bunker", options, null, (val) => {
            if (val === "overlay") {
                window.scOpenBunkerOverlay();
            } else if (val === "direct") {
                window.location.href = 'recovery.php';
            }
        });
    } else {
        window.location.href = 'recovery.php';
    }
};

window.scOpenBunkerOverlay = function() {
    const existing = document.getElementById('sc-bunker-portal');
    if (existing) existing.remove();

    const portal = document.createElement('div');
    portal.id = 'sc-bunker-portal';
    portal.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: #050505; z-index: 2147483647; display: flex; flex-direction: column;
        animation: scPortalFade 0.25s ease-out;
    `;
    
    const style = document.createElement('style');
    style.innerHTML = `@keyframes scPortalFade { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }`;
    portal.appendChild(style);

    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = window.suiIcon('x', 'currentColor', 24, 2.5);
    closeBtn.style.cssText = `
        position: absolute; top: 16px; right: 16px; z-index: 2;
        background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2);
        border-radius: 50%; width: 40px; height: 40px;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
        transition: background 0.2s; box-shadow: 0 4px 12px rgba(0,0,0,0.5);
    `;
    closeBtn.onmouseover = () => closeBtn.style.background = 'rgba(255,255,255,0.2)';
    closeBtn.onmouseout = () => closeBtn.style.background = 'rgba(255,255,255,0.1)';
    closeBtn.onclick = () => {
        portal.style.opacity = '0';
        portal.style.transform = 'translateY(20px)';
        portal.style.transition = 'all 0.25s ease-in';
        setTimeout(() => portal.remove(), 250);
        // Refresh checkpoint counts in the UI if the user deleted/created snapshots while in the bunker
        if (typeof window.scLoadConfig === 'function') window.scLoadConfig();
    };
    portal.appendChild(closeBtn);

    const iframe = document.createElement('iframe');
    iframe.src = 'recovery.php';
    iframe.style.cssText = `flex: 1; border: none; width: 100%; height: 100%; background: #050505;`;
    portal.appendChild(iframe);

    document.body.appendChild(portal);
};

window.scGenerateBunker = async function(e) {
    const btn = e.currentTarget;
    const origText = btn.innerText;
    btn.innerText = "Rebuilding...";
    btn.disabled = true;
    try {
        await window.sui.api('sc_generate_bunker', {}, { toast: "Bunker Rebuilt Successfully" });
    } catch(err) {
        window.openConfirm("Error", "Failed to rebuild bunker.", null, false, "OK", null);
    }
    btn.innerText = origText;
    btn.disabled = false;
};

window.scOpenWorker = function(action, params = {}) {
    // 1. Build URL
    const url = new URL('app/api/checkpoint_worker.php', window.location.href);
    url.searchParams.set('action', action);
    url.searchParams.set('is_iframe', '1');
    for (const key in params) url.searchParams.set(key, params[key]);

    // 2. Create Portal Overlay
    const portal = document.createElement('div');
    portal.id = 'sc-worker-portal';
    portal.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: #0d1117; z-index: 2147483647; display: flex; flex-direction: column;
        animation: scPortalFade 0.3s ease-out;
    `;
    
    // Add internal style for animation
    const style = document.createElement('style');
    style.innerHTML = `@keyframes scPortalFade { from { opacity: 0; transform: scale(1.05); } to { opacity: 1; transform: scale(1); } }`;
    portal.appendChild(style);

    // 3. Create Iframe
    const iframe = document.createElement('iframe');
    iframe.src = url.toString();
    iframe.style.cssText = `flex: 1; border: none; width: 100%; height: 100%;`;
    portal.appendChild(iframe);

    // 4. Add Close Logic (Global access for the iframe to call)
    window.scCloseWorkerPortal = (shouldReload = false) => {
        portal.style.opacity = '0';
        portal.style.transition = 'opacity 0.3s';
        setTimeout(() => {
            portal.remove();
            if (shouldReload) {
                location.reload();
            } else if (typeof window.elRefreshStatus === 'function') {
                // Force a refresh of the pending status
                window.elRefreshStatus();
            }
        }, 300);
    };

    document.body.appendChild(portal);
};

window.scSaveSettings = async function() {
    const showShield = document.getElementById('sc-show-shield-toggle').checked;
    const showUndo = document.getElementById('sc-show-undo-toggle').checked;
    
    await window.sui.api('sc_save_config', { 
        show_shield: showShield, 
        show_undo: showUndo 
    }, { toast: false });
    scRenderHeaderBtn(showShield, showUndo);
};

async function scLoadConfig() {
    // Priority 1: Bridge
    if (window.__SC_BRIDGE__) {
        scApplyConfig(window.__SC_BRIDGE__.config);
    }

    // Priority 2: Fresh Fetch
    try {
        const data = await window.sui.api('sc_get_config', {}, { toast: false });
        if (data && data.config) {
            scApplyConfig(data.config);
        }
    } catch(e) {}
}

function scApplyConfig(config) {
    const shieldToggle = document.getElementById('sc-show-shield-toggle');
    const undoToggle = document.getElementById('sc-show-undo-toggle');
    if (shieldToggle) shieldToggle.checked = !!config.show_shield;
    if (undoToggle) undoToggle.checked = !!config.show_undo;
    scRenderHeaderBtn(!!config.show_shield, !!config.show_undo);

    const countEl = document.getElementById('sc-total-count');
    if(countEl && config.total_count !== undefined) {
        countEl.innerText = `(${config.total_count} current)`;
    }
}

function scRenderHeaderBtn(showShield, showUndo) {
    const container = document.getElementById('settings-header-actions');
    if (!container) return;
    
    // Clear existing to ensure order and clean state
    const oldUndo = document.getElementById('sc-header-undo-btn');
    const oldSnap = document.getElementById('sc-header-snapshot-btn');
    if (oldUndo) oldUndo.remove();
    if (oldSnap) oldSnap.remove();

    // 1. UNDO BUTTON (RESTORE LATEST)
    const undoBtn = document.createElement('button');
    undoBtn.id = 'sc-header-undo-btn';
    undoBtn.title = 'Undo: Restore Latest Checkpoint';
    undoBtn.style.cssText = 'background:#E5E5EA; border:none; width:30px; height:30px; border-radius:50%; color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;';
    undoBtn.innerHTML = window.suiIcon('undo', 'currentColor', 16, 2.5);
    
    undoBtn.onclick = async (e) => {
        e.stopPropagation();
        
        // 1. Fetch the Edit Log from INSIDE the latest checkpoint
        let history = [];
        try {
            const data = await window.sui.api('sc_get_latest_log', {}, { toast: false });
            history = data.log || [];
        } catch(err) { console.error("Could not fetch log"); }

        // 2. Prepare Picker Options
        const options = [
            { 
                label: `<div style="color:var(--danger); font-weight:800; text-align:center;">🚨 RESTORE LATEST CHECKPOINT</div>
                        <div style="font-size:11px; color:#8E8E93; font-weight:400; text-align:center; margin-top:4px;">This will revert all code and settings to the last snapshot.</div>`, 
                value: "confirm_undo" 
            }
        ];

        if (history.length > 0) {
            options.push({ label: "Restoring to this state:", type: "header" });
            history.slice(0, 15).forEach(entry => {
                const timeOnly = entry.date.split(' ')[1].substring(0, 5);
                options.push({
                    label: `<div style="display:flex; flex-direction:column; gap:1px;">
                                <div style="font-size:9px; font-weight:800; color:#8E8E93; text-transform:uppercase; letter-spacing:0.3px;">${timeOnly} — UPDATE</div>
                                <div style="font-size:13px; color:var(--text-primary); line-height:1.4;">${entry.summary}</div>
                            </div>`,
                    type: "info"
                });
            });
        } else {
            options.push({ label: "No recent edit history found.", type: "header" });
        }

        // 3. Open Picker as custom confirmation UI
        if (window.openPicker) {
            window.openPicker("Undo System Changes?", options, null, async (val) => {
                if (val === "confirm_undo") {
                    if (typeof scOpenWorker === 'function') {
                        scOpenWorker('restore');
                    } else {
                        undoBtn.style.opacity = '0.5';
                        undoBtn.style.pointerEvents = 'none';
                        try {
                            const data = await window.sui.api('sc_restore_latest', {}, { toast: "Restoring..." });
                            if (data.client_state && typeof migApplyClientState === 'function') migApplyClientState(data.client_state);
                            location.reload();
                        } catch(err) {
                            undoBtn.style.opacity = '1';
                            undoBtn.style.pointerEvents = 'auto';
                        }
                    }
                }
            });
        }
    };

    // 2. SNAPSHOT BUTTON (THE SHIELD)
    const snapBtn = document.createElement('button');
    snapBtn.id = 'sc-header-snapshot-btn';
    snapBtn.title = 'Create System Checkpoint';
    snapBtn.style.cssText = 'background:#E5E5EA; border:none; width:30px; height:30px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;';
    snapBtn.innerHTML = window.suiIcon('shield', 'currentColor', 16, 2.5);
    
    snapBtn.onclick = async (e) => {
        e.stopPropagation();
        snapBtn.style.opacity = '0.5';
        snapBtn.style.pointerEvents = 'none';
        await scCreateCheckpoint();
        snapBtn.style.opacity = '1';
        snapBtn.style.pointerEvents = 'auto';
        snapBtn.style.background = '#34C759';
        snapBtn.style.color = 'white';
        setTimeout(() => {
            snapBtn.style.background = '#E5E5EA';
            snapBtn.style.color = 'var(--primary)';
        }, 2000);
    };
    
    if (showUndo) container.appendChild(undoBtn);
    if (showShield) container.appendChild(snapBtn);
}

window.addEventListener('load', scLoadConfig);

window.scTriggerUndoFromStudio = function() {
    window.openConfirm(
        "Restore Latest Checkpoint?",
        "This will revert all code and settings to the last snapshot. Any pending changes in the list below will be lost.",
        () => {
            window.scOpenWorker('restore');
        },
        true,
        "Restore Now"
    );
};

window.scCreateCheckpoint = async function(autoFocus = false) {
    const now = new Date();
    const defaultTs = now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, '0') + "-" + String(now.getDate()).padStart(2, '0') + "_" + String(now.getHours()).padStart(2, '0') + String(now.getMinutes()).padStart(2, '0');
    
    // 1. Fetch Data for Pending Analysis
    let currentLog = [];
    let securedLog = [];
    try {
        const [d1, d2] = await Promise.all([
            window.sui.api('el_get_log', {}, { toast: false }),
            window.sui.api('sc_get_latest_log', {}, { toast: false })
        ]);
        currentLog = d1.log || [];
        securedLog = d2.log || [];
    } catch(e) { console.error("Could not fetch log history"); }

    const securedFingerprints = new Set(securedLog.map(e => e.date + '|' + e.summary));
    
    // Logic Fix: An entry is pending ONLY if it is newer than the latest match in the checkpoint.
    // We iterate until we find the first item that exists in the checkpoint, then stop.
    const pending = [];
    for (const entry of currentLog) {
        if (securedFingerprints.has(entry.date + '|' + entry.summary)) {
            break; // Found the "Secured" boundary
        }
        pending.push(entry);
    }

    // 2. Open Dedicated Studio
    window.sui.openStudio({
        id: 'sc-create-studio',
        title: 'Secure Checkpoint',
        content: `
            <!-- UTILITY BUTTONS -->
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; margin-bottom:20px;">
                <button onclick="scShowBunkerMenu(event)" class="text-btn" style="background:var(--btn-bg); color:var(--text-primary); border-radius:12px; padding:12px; display:flex; align-items:center; justify-content:center; gap:8px; font-size:12px; font-weight:700; border:1px solid var(--border-color);">
                    <span data-sui-icon="shield" data-sui-size="16"></span> Bunker
                </button>
                <button id="cp-studio-history-btn" onclick="if(typeof elShowHistoryPicker === 'function') elShowHistoryPicker()" class="text-btn" style="background:var(--btn-bg); color:var(--text-primary); border-radius:12px; padding:12px; display:flex; align-items:center; justify-content:center; gap:8px; font-size:12px; font-weight:700; border:1px solid var(--border-color);">
                    <span data-sui-icon="clock" data-sui-size="16"></span> History
                </button>
                <button onclick="scTriggerUndoFromStudio()" class="text-btn" style="background:rgba(255, 59, 48, 0.1); color:var(--danger); border-radius:12px; padding:12px; display:flex; align-items:center; justify-content:center; gap:8px; font-size:12px; font-weight:700; border:1px solid rgba(255, 59, 48, 0.2);">
                    <span data-sui-icon="undo" data-sui-size="16"></span> Undo
                </button>
            </div>

            <div style="height: 1px; background: var(--border-color); margin-bottom: 20px;"></div>

            <div style="margin-bottom:20px;">
    <label class="setting-label">Snapshot Label</label>
    <div class="setting-desc">Give this checkpoint a name or leave blank for timestamp.</div>
    <input type="text" id="sc-studio-label-input" placeholder="${defaultTs}" style="margin-top:8px; font-weight:600;">
    <label style="display:flex; align-items:center; gap:8px; margin-top:12px; font-size:11px; color:var(--text-secondary); cursor:pointer;">
        <input type="checkbox" id="sc-studio-force-major" style="width:auto; margin:0;">
        Force Major Snapshot (Full Backup)
    </label>
</div>

<div style="margin-bottom:24px;"><div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">Changes to be Secured</div>
                    <span class="meta-badge sui-badge-ai" style="font-size:10px;">${pending.length} Pending</span>
                </div>
                
                <div id="sc-studio-pending-list" style="max-height:250px; overflow-y:auto; background:var(--input-bg); border:1px solid var(--border-color); border-radius:14px; padding:4px;">
                    ${pending.length === 0 ? 
                        window.suiEmptyState('<span data-sui-icon="shield-check" data-sui-size="32" data-sui-color="var(--primary)"></span>', 'System is already secured') : 
                        pending.map(e => `
                            <div style="padding:12px; border-bottom:1px solid rgba(0,0,0,0.03); display:flex; flex-direction:column; gap:2px;">
                                <div style="font-size:9px; font-weight:800; color:var(--primary); text-transform:uppercase;">${e.date.split(' ')[1]} — UPDATE</div>
                                <div style="font-size:13px; color:var(--text-primary); line-height:1.4;">${e.summary}</div>
                            </div>
                        `).join('')
                    }
                </div>
            </div>

            <button id="sc-studio-commit-btn" class="btn-primary" style="width:100%; gap:8px;">
                <span data-sui-icon="shield-check" data-sui-size="18"></span>
                Secure Current State
            </button>
        `,
        onSetup: (content) => {
            const input = content.querySelector('#sc-studio-label-input');
            const btn = content.querySelector('#sc-studio-commit-btn');
            
            btn.onclick = () => {
    const label = input.value.trim() || defaultTs;
    const forceMajor = content.querySelector('#sc-studio-force-major').checked;
    performScCreate(label, forceMajor);
    window.sui.closeStudio('sc-create-studio');
};// Enter key support
            input.onkeydown = (e) => { if(e.key === 'Enter') btn.click(); };

            if (autoFocus) {
                setTimeout(() => {
                    input.focus();
                    input.select();
                }, 400); // Delay to allow studio slide-up animation to complete
            }
        }
    });
};

async function performScCreate(rawName, forceMajor = false) {
const name = rawName.replace(/[^a-zA-Z0-9\s]/g, ' ')
           .split(/\s+/)
           .filter(w => w.length > 0)
           .map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
           .join('') || 'Manual';

// Store client state in a temporary cookie so the worker can grab it
const state = { localStorage: JSON.stringify(localStorage), cookies: document.cookie };
document.cookie = "cjos_client_state_bridge=" + encodeURIComponent(JSON.stringify(state)) + "; path=/; max-age=60";

// Open worker in new tab via Form (Mobile Friendly)
window.scOpenWorker('create', { name: name, force_major: forceMajor ? '1' : '0' });
}window.scPruneCheckpoints = function() {
    const keepCount = document.getElementById('sc-prune-slider').value;
    const msg = `This will permanently delete all but the latest ${keepCount} checkpoints. Starred items will be protected.`;
    
    if (window.openConfirm) {
        window.openConfirm("Prune Checkpoints?", msg, () => performScPrune(keepCount), true);
    }
};

async function performScPrune(keepCount) {
    const btn = document.getElementById('sc-btn-prune');
    const origText = btn.innerText;
    btn.innerText = "Pruning...";
    btn.disabled = true;

    try {
        const data = await window.sui.api('sc_prune', { keep: keepCount }, { toast: false });
        if (data.status === 'success') {
            const t = document.getElementById("toast");
            if(t) { 
                t.innerText = `Pruned ${data.deleted} items`; 
                t.classList.add("show"); 
                setTimeout(()=>t.classList.remove("show"), 2000); 
            }
            // Refresh count display
            scLoadConfig();
        }
    } catch(e) { window.openConfirm("Error", "Pruning failed.", null, false, "OK", null); }

    btn.innerText = origText;
    btn.disabled = false;
};
JS;