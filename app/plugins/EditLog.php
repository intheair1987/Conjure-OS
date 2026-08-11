<?php
// ==============================================================================
// PLUGIN: Edit Log
// DESCRIPTION: System Change History.
// Purpose: Tracks a history of AI-generated updates and manual changes.
// Storage: data/edit-log.json
// UPDATED: Settings UI now uses on-demand picker instead of embedded list.
// ==============================================================================

$el_db_file = CJOS_PATH_DATA . '/edit-log-file.db';
$el_db_file = CJOS_PATH_DATA . '/edit-log.db';
$el_state_file = CJOS_PATH_DATA . '/edit-log-state.json'; // Small manifest for sync-checks
$el_config_file = CJOS_PATH_DATA . '/edit-log-config.json';

// Immediate sweep on plugin load for orphan temp files
foreach (glob($el_state_file . '.tmp*') as $orphan) {
    @unlink($orphan);
}

// --- DATABASE INITIALIZATION & MIGRATION ---
function el_get_db() {
    global $el_db_file;
    $db = new PDO("sqlite:" . $el_db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("CREATE TABLE IF NOT EXISTS edit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date DATETIME,
        summary TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_summary ON edit_log(summary)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_date ON edit_log(date)");
    return $db;
}

// Ingest pending edit-log.json entries (from Patcher #ACTION: edit_log or manual JSON)
if (file_exists(CJOS_PATH_DATA . '/edit-log.json')) {
    $old_file = CJOS_PATH_DATA . '/edit-log.json';
    $content = trim(file_get_contents($old_file));
    if (!empty($content)) {
        $db = el_get_db();
        $data = json_decode($content, true);
        $stmt = $db->prepare("INSERT INTO edit_log (date, summary) VALUES (?, ?)");
        
        if (is_array($data)) {
            $db->beginTransaction();
            foreach ($data as $entry) {
                if (is_array($entry) && !empty($entry['summary'])) {
                    $stmt->execute([$entry['date'] ?? date('Y-m-d H:i:s'), $entry['summary']]);
                }
            }
            $db->commit();
        } else {
            // Handle plain text summary written by Patcher #ACTION: edit_log
            $stmt->execute([date('Y-m-d H:i:s'), $content]);
        }
    }
    @unlink($old_file);
    el_update_state_manifest();
}

// Helper: Update the small state file for sync-checks and previews
function el_update_state_manifest() {
    global $el_state_file;
    $db = el_get_db();
    // Increased to 2000 to support deep context exports and prevent "False Pending" flags
    $rows = $db->query("SELECT date, summary FROM edit_log ORDER BY id DESC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents($el_state_file, json_encode($rows));

    // Sweep any orphan temporary state files
    foreach (glob($el_state_file . '.tmp*') as $orphan) {
        @unlink($orphan);
    }
}

// Helper: Fast, cached lookup for latest checkpoint max timestamp
function el_get_checkpoint_max_ts() {
    $cp_dir = CJOS_PATH_APP . '/backups/checkpoints';
    $timestamp_vault_file = CJOS_PATH_DATA . '/edit-log-timestamps-private.json';
    $zip_files = glob($cp_dir . "/*.zip");
    if (empty($zip_files)) return '1970-01-01 00:00:00';

    $vault = file_exists($timestamp_vault_file) ? json_decode(file_get_contents($timestamp_vault_file), true) : [];
    if (!is_array($vault)) $vault = [];

    // Pre-extract timestamps for O(N) sorting
    $file_ts = [];
    foreach ($zip_files as $f) {
        preg_match('/(\d{8}_\d{6})/', $f, $m);
        $file_ts[$f] = $m[1] ?? '';
    }
    usort($zip_files, function($a, $b) use ($file_ts) {
        return strcmp($file_ts[$b], $file_ts[$a]);
    });

    $latest_zip_name = basename($zip_files[0]);
    if (isset($vault[$latest_zip_name])) {
        return $vault[$latest_zip_name];
    }

    $zip = new ZipArchive();
    $checkpoint_max_ts = '1970-01-01 00:00:00';
    if ($zip->open($zip_files[0]) === TRUE) {
        $relData = ltrim(str_replace(CJOS_PATH_ROOT, '', CJOS_PATH_DATA), DIRECTORY_SEPARATOR);
        $content = $zip->getFromName($relData . "/edit-log-state.json") ?: $zip->getFromName($relData . "/edit-log.json");
        
        if (!$content) {
            $diffJson = $zip->getFromName('sc_diff.json');
            if ($diffJson) {
                $diffMeta = json_decode($diffJson, true);
                $baseRef = $diffMeta['base_ref'] ?? null;
                if ($baseRef && file_exists($cp_dir . '/' . $baseRef)) {
                    $baseZip = new ZipArchive();
                    if ($baseZip->open($cp_dir . '/' . $baseRef) === TRUE) {
                        $content = $baseZip->getFromName($relData . "/edit-log-state.json") ?: $baseZip->getFromName($relData . "/edit-log.json");
                        $baseZip->close();
                    }
                }
            }
        }

        $logData = $content ? json_decode($content, true) : null;
        if (is_array($logData) && isset($logData[0]['date'])) {
            $checkpoint_max_ts = $logData[0]['date'];
        }
        $zip->close();

        $vault[$latest_zip_name] = $checkpoint_max_ts;
        file_put_contents($timestamp_vault_file, json_encode($vault));
    }
    return $checkpoint_max_ts;
}

// --- 1. BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    
    // GET CONFIG
    if ($_POST['plugin_action'] === 'el_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $conf = ['show_header_btn' => false, 'view_limit' => 50, 'export_limit' => 15];
        if (file_exists($el_config_file)) $conf = json_decode(file_get_contents($el_config_file), true);
        echo json_encode(['status' => 'success', 'config' => $conf]);
        exit;
    }

    // SAVE CONFIG
    if ($_POST['plugin_action'] === 'el_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $conf = [
            'show_header_btn' => ($_POST['show_header_btn'] === 'true'),
            'view_limit' => (int)$_POST['view_limit'],
            'export_limit' => (int)$_POST['export_limit']
        ];
        file_put_contents($el_config_file, json_encode($conf));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET LOG (SQLite Search Edition)
    if ($_POST['plugin_action'] === 'el_get_log') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $db = el_get_db();
        
        $query = trim($_POST['query'] ?? '');
        $limit = (int)($_POST['limit'] ?? 50);
        
        // Remove the 500 cap. If user wants "All" (501), we give them a high enough number.
        if ($limit >= 501) $limit = 10000; 

        if ($query) {
            // Search both summary AND date
            $stmt = $db->prepare("SELECT date, summary FROM edit_log WHERE summary LIKE ? OR date LIKE ? ORDER BY id DESC LIMIT ?");
            $stmt->execute(['%' . $query . '%', '%' . $query . '%', $limit]);
        } else {
            $stmt = $db->prepare("SELECT date, summary FROM edit_log ORDER BY id DESC LIMIT ?");
            $stmt->execute([$limit]);
        }
        $log = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $checkpoint_max_ts = el_get_checkpoint_max_ts();
        echo json_encode(['status' => 'success', 'log' => $log, 'checkpoint_max_ts' => $checkpoint_max_ts]);
        exit;
    }

    // CHECK PENDING STATUS (SQLite Source-of-Truth Edition)
    if ($_POST['plugin_action'] === 'el_check_status') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $checkpoint_max_ts = el_get_checkpoint_max_ts();
        $db = el_get_db();

        // The database is authoritative. Rebuild the manifest first so
        // older agent-written entries cannot leave the cache stale.
        el_update_state_manifest();

        $live = $db->query("SELECT MAX(date) AS max_date FROM edit_log")->fetch(PDO::FETCH_ASSOC);
        $live_max_ts = !empty($live['max_date']) ? $live['max_date'] : '1970-01-01 00:00:00';

        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM edit_log WHERE date > ?");
        $stmt->execute([$checkpoint_max_ts]);
        $pending_count = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        $has_pending = $pending_count > 0;

        echo json_encode([
            'status' => 'success',
            'has_pending' => $has_pending,
            'pending_count' => $pending_count,
            'live_max_ts' => $live_max_ts,
            'checkpoint_max_ts' => $checkpoint_max_ts
        ]);
        exit;
    }// CLEAR LOG
    if ($_POST['plugin_action'] === 'el_clear_log') {
        $db = el_get_db();
        $db->exec("DELETE FROM edit_log");
        el_update_state_manifest();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['plugin_action'] === 'el_remove_latest') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $db = el_get_db();
        $db->exec("DELETE FROM edit_log WHERE id = (SELECT MAX(id) FROM edit_log)");
        el_update_state_manifest();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['plugin_action'] === 'el_prune_log') {
        $db = el_get_db();
        $db->exec("DELETE FROM edit_log WHERE id NOT IN (SELECT id FROM edit_log ORDER BY id DESC LIMIT 20)");
        el_update_state_manifest();
        echo json_encode(['status' => 'success']);
        exit;
    }

    // MANUAL LOG ENTRY
    if ($_POST['plugin_action'] === 'el_repair_migration') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $old_file = CJOS_PATH_DATA . '/edit-log.json.bak';
        if (!file_exists($old_file)) {
            echo json_encode(['status' => 'error', 'message' => 'Backup JSON not found.']);
            exit;
        }
        $data = json_decode(file_get_contents($old_file), true);
        if (!is_array($data)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid backup data.']);
            exit;
        }
        $db = el_get_db();
        $db->exec("DELETE FROM edit_log"); // Clear current
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO edit_log (date, summary) VALUES (?, ?)");
        foreach ($data as $entry) {
            $stmt->execute([$entry['date'], $entry['summary']]);
        }
        $db->commit();
        el_update_state_manifest();
        echo json_encode(['status' => 'success', 'count' => count($data)]);
        exit;
    }

    if ($_POST['plugin_action'] === 'el_manual_log') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $summary = trim($_POST['summary'] ?? '');
        if (empty($summary)) {
            echo json_encode(['status' => 'error', 'message' => 'Summary cannot be empty.']);
            exit;
        }

        $db = el_get_db();
        $stmt = $db->prepare("INSERT INTO edit_log (date, summary) VALUES (?, ?)");
        $stmt->execute([date('Y-m-d H:i:s'), $summary]);
        
        el_update_state_manifest();
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- 2. SETTINGS UI ---
$plugin_settings_map['EditLog'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Update History</label>
        <div class="setting-desc">
            A chronological record of patches and features added to this instance.
        </div>
        
        <div data-sui-setting="Header Shortcut" data-sui-desc="Show a history clock icon in the settings header." data-sui-id="el-show-shortcut-toggle" data-sui-onchange="elSaveElConfig()"></div>

        <div class="setting-item vertical">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <label class="setting-label" style="margin:0;">History View Limit</label>
        <span id="el-view-limit-label" style="font-weight:700; color:var(--primary); font-size:14px;">50</span>
    </div>
    <div class="setting-desc">Control how many entries are shown in the history picker.</div>
    <input type="range" id="el-view-limit-slider" min="0" max="501" step="1" value="50" oninput="elUpdateLimitLabel(this.value)" onchange="elSaveElConfig()" style="margin-top:8px;">
</div>

<div class="setting-item vertical">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <label class="setting-label" style="margin:0;">Export Context Limit</label>
        <span id="el-export-limit-label" style="font-weight:700; color:var(--primary); font-size:14px;">15</span>
    </div>
    <div class="setting-desc">Number of log entries included in "Export Source Code" files.</div>
    <input type="range" id="el-export-limit-slider" min="0" max="2000" step="5" value="15" oninput="elUpdateExportLabel(this.value)" onchange="elSaveElConfig()" style="margin-top:8px;">
</div>
   

        <div style="display:flex; flex-direction:column; gap:10px; margin-top:12px;">
            <div style="display:flex; gap:10px;">
                <button onclick="elShowHistoryPicker()" class="text-btn" style="
                    flex: 1; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; 
                    padding:12px; font-weight:600; color:var(--text-primary);
                ">View Edit Log</button>
                
                <button onclick="elPruneLog()" class="text-btn" style="
                    flex: 1; background:var(--btn-bg); color:var(--btn-text); border:1px solid var(--border-color); 
                    border-radius:12px; padding:12px; font-weight:600;
                ">Prune (Keep 20)</button>
            </div>
            
            <div style="display:flex; gap:10px;">
                <button onclick="elRepairMigration()" class="text-btn" style="
                    flex: 1; background:var(--btn-bg); color:var(--primary); border:1px solid var(--border-color); 
                    border-radius:12px; padding:12px; font-weight:600;
                ">Repair History Database</button>

                <button onclick="clearEditLog()" class="text-btn" style="
                    flex: 1; background:#FFE5E5; color:var(--danger); border:none; 
                    border-radius:12px; padding:12px; font-weight:600;
                ">Clear All</button>
            </div>
        </div>

        <div style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border-color);">
            <label class="setting-label">Manual Log Entry</label>
            <div class="setting-desc">Document a manual change or update to the system.</div>
            <textarea id="el-manual-summary" placeholder="Describe your changes..." style="width:100%; height:80px; margin-top:8px; resize:none;"></textarea>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button onclick="elPreviewLog()" class="text-btn" style="flex:1; background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:12px; font-weight:600;">Preview</button>
                <button onclick="elManualLog()" class="text-btn" style="flex:1; background:var(--primary); color:var(--primary-text); border-radius:10px; padding:10px; font-size:12px; font-weight:700;">Log to System</button>
            </div>
        </div>
    </div>
HTML;

// --- 3. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- EDIT LOG JS ---

// Inject Status Check Animation Styles and Badge Styles
const elStatusStyle = document.createElement('style');
elStatusStyle.innerHTML = `
    @keyframes el-status-checking {
        0% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.4; transform: scale(0.9); }
        100% { opacity: 1; transform: scale(1); }
    }
    .el-checking { 
        animation: el-status-checking 0.8s infinite ease-in-out !important; 
        filter: grayscale(1) !important;
    }
    .el-badge {
        position: absolute;
        top: -4px;
        right: -4px;
        background-color: #FF3B30;
        color: #FFFFFF;
        font-size: 8px;
        font-weight: 900;
        border-radius: 50%;
        min-width: 14px;
        height: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 2px;
        box-sizing: border-box;
        border: 1.5px solid var(--card-bg);
        pointer-events: none;
        line-height: 1;
        z-index: 10;
        box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }
    /* Ensure targeted action clock buttons and settings cog position relative-absolute correctly */
    #el-header-history-btn, #cp-studio-history-btn, #fr-zone-checkpoint, #settings-btn {
        position: relative !important;
    }
`;
document.head.appendChild(elStatusStyle);

let elState = { show_header_btn: false, view_limit: 50, export_limit: 15 };
let _elLastCheck = 0;
let _elLastPendingState = null;
let _elLastPendingCount = 0;

window.addEventListener("load", () => {
    elLoadConfig();
    setTimeout(elRefreshStatus, 1500);
    
    // 1. Visibility Sentry: Check when you switch back to the tab
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') elRefreshStatus();
    });

    // 2. Focus Sentry: Check when the window gains focus
    window.addEventListener('focus', elRefreshStatus);
});

// Global hook to refresh when checkpoints are created
window.elRefreshStatus = async function() {
    const targets = [
        document.getElementById('el-header-history-btn'),
        document.getElementById('cp-studio-history-btn'),
        document.getElementById('fr-zone-checkpoint'),
        document.getElementById('settings-btn')
    ].filter(b => b !== null);

    if (targets.length === 0) return;

    const applyUI = (hasPending, count) => {
        requestAnimationFrame(() => {
            targets.forEach(btn => {
                const isFrZone = btn.id === 'fr-zone-checkpoint';
                const isSettings = btn.id === 'settings-btn';
                
                btn.classList.toggle('el-pending', hasPending);
                if (isSettings) btn.classList.toggle('sh-pending-dot', false); // Superseded by numbered badge

                if (hasPending) {
                    btn.style.color = "#FF3B30";
                    btn.style.background = isFrZone ? "rgba(255, 59, 48, 0.2)" : "rgba(255, 59, 48, 0.1)";
                    btn.style.borderColor = "#FF3B30";
                    btn.style.borderStyle = "solid";
                    btn.style.borderWidth = "1px";
                    btn.title = isSettings ? `Settings (${count} Unsecured Changes)` : `Pending Changes (${count} Unsecured)`;
                    
                    let badge = btn.querySelector('.el-badge');
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'el-badge';
                        btn.appendChild(badge);
                    }
                    badge.innerText = count;
                } else {
                    if (isSettings) {
                        btn.style.color = "";
                        btn.style.background = "";
                        btn.style.borderColor = "";
                        btn.style.borderStyle = "";
                        btn.style.borderWidth = "";
                        btn.title = "Settings";
                    } else {
                        btn.style.color = isFrZone ? "var(--text-secondary)" : "var(--primary)";
                        btn.style.background = isFrZone ? "var(--glass-bg)" : "var(--btn-bg)";
                        btn.style.borderColor = isFrZone ? "var(--glass-border)" : "transparent";
                        btn.style.borderWidth = isFrZone ? "1px" : "0px";
                        btn.title = "System Secured";
                    }
                    
                    const badge = btn.querySelector('.el-badge');
                    if (badge) badge.remove();
                }
            });
        });
    };

    // Apply cached state immediately if under cooldown
    const now = Date.now();
    if (now - _elLastCheck < 1500) {
        if (_elLastPendingState !== null) {
            applyUI(_elLastPendingState, _elLastPendingCount);
        }
        return;
    }
    _elLastCheck = now;

    // Start checking animation simultaneously on all targets
    targets.forEach(btn => btn.classList.add('el-checking'));

    try {
        const data = await window.sui.api('el_check_status', {}, { toast: false });
        if (data) {
            _elLastPendingState = !!data.has_pending;
            _elLastPendingCount = parseInt(data.pending_count) || 0;
            applyUI(_elLastPendingState, _elLastPendingCount);
        }
    } catch(e) {
    } finally {
        // Stop checking animation simultaneously on all targets
        targets.forEach(btn => btn.classList.remove('el-checking'));
    }
};

async function elLoadConfig() {
    try {
        const data = await window.sui.api('el_get_config', {}, { toast: false });
        if (data) {
            elState = data.config;
            const toggle = document.getElementById('el-show-shortcut-toggle');
            if (toggle) toggle.checked = elState.show_header_btn;
            
            const slider = document.getElementById('el-view-limit-slider');
            if (slider) {
                slider.value = elState.view_limit;
                elUpdateLimitLabel(elState.view_limit);
            }

            const exSlider = document.getElementById('el-export-limit-slider');
            if (exSlider) {
                exSlider.value = elState.export_limit;
                elUpdateExportLabel(elState.export_limit);
            }
            
            elRenderHeaderBtn(elState.show_header_btn);
        }
    } catch(e) {}
}

window.elUpdateExportLabel = function(val) {
    const label = document.getElementById('el-export-limit-label');
    if (!label) return;
    label.innerText = val == 0 ? "None" : val;
};

window.elUpdateLimitLabel = function(val) {
    const label = document.getElementById('el-view-limit-label');
    if (!label) return;
    if (val == 0) label.innerText = "None";
    else if (val == 501) label.innerText = "All";
    else label.innerText = val;
};

window.elRemoveLatestPrompt = function() {
    window.openConfirm(
        "Remove Log Entry",
        "Are you sure you want to remove the <strong>most recent</strong> log entry? This action cannot be undone.",
        async () => {
            try {
                const res = await window.sui.api('el_remove_latest', {}, { toast: "Entry Removed" });
                if (res.status === 'success') {
                    closeSharedPicker();
                    setTimeout(elShowHistoryPicker, 300); // Re-open to show updated list
                }
            } catch(e) {}
        },
        true,
        "Remove Entry",
        "Cancel"
    );
};

window.elTriggerCheckpointAction = function(mode = 'save') {
    closeSharedPicker();
    if (mode === 'restore') {
        window.openConfirm("Restore Checkpoint", "🚨 RESTORE SYSTEM CHECKPOINT?\n\nThis will revert the system to the last saved state.\nAny 'Pending' changes listed above will be lost.", () => {
            if (typeof scOpenWorker === 'function') {
                scOpenWorker('restore');
            } else {
                window.sui.api("sc_restore_latest", {}, { toast: "Restoring..." }).then(d => {
                    if (d.client_state && typeof migApplyClientState === "function") migApplyClientState(d.client_state);
                    location.reload();
                });
            }
        }, true);
        return;
    }
    // Mode: Direct Save
    if (typeof performScCreate === 'function') {
        performScCreate("Quick Checkpoint");
    } else {
        window.sui.api("sc_create", { name: "QuickSave" }, { toast: "Securing System..." }).then(() => {
            if (typeof elRefreshStatus === "function") elRefreshStatus();
        });
    }
};

window.elPreviewLog = async function() {
    const summary = document.getElementById('el-manual-summary').value.trim();
    if (!summary) { window.sui.toast("Please enter a summary first"); return; }
    
    try {
        const data = await window.sui.api('el_get_log', {}, { toast: false });
        const lastEntry = (data && data.log && data.log.length > 0) ? data.log[0] : null;
        
        const now = new Date().toISOString().replace('T', ' ').split('.')[0];
        const newEntry = { date: now, summary: summary };

        let html = '<div style="text-align:left; font-family:monospace; font-size:11px; background:var(--input-bg); padding:12px; border-radius:8px; white-space:pre-wrap; color:var(--text-secondary);">';
        if (lastEntry) {
            html += `// Previous Entry...\n${JSON.stringify(lastEntry, null, 2)}\n\n`;
        }
        html += `<span style="color:var(--primary); font-weight:bold;">// Appending New Entry...\n${JSON.stringify(newEntry, null, 2)}</span>`;
        html += '</div>';

        window.openConfirm(
            "Log Preview",
            html,
            () => { elManualLog(); },
            false,
            "Confirm Append",
            "Edit"
        );
    } catch(e) {
        window.sui.toast("Preview failed: " + e.message);
    }
};

window.elManualLog = async function() {
    const summaryInput = document.getElementById('el-manual-summary');
    const summary = summaryInput.value.trim();
    if (!summary) { window.sui.toast("Summary cannot be empty"); return; }

    try {
        await window.sui.api('el_manual_log', { summary: summary }, { toast: "Entry Added to History" });
        summaryInput.value = "";
        elRefreshStatus(); // Trigger red-alert if needed
    } catch(e) {}
};

window.elSaveElConfig = async function() {
    const show = document.getElementById('el-show-shortcut-toggle').checked;
    const limit = parseInt(document.getElementById('el-view-limit-slider').value);
    const exLimit = parseInt(document.getElementById('el-export-limit-slider').value);
    
    elState.show_header_btn = show;
    elState.view_limit = limit;
    elState.export_limit = exLimit;

    await window.sui.api('el_save_config', { 
        show_header_btn: show, 
        view_limit: limit, 
        export_limit: exLimit 
    }, { toast: false });
    
    elRenderHeaderBtn(show);
};

function elRenderHeaderBtn(show) {
    const container = document.getElementById('settings-header-actions');
    if (!container) return;
    
    let btn = document.getElementById('el-header-history-btn');
    if (!show) {
        if (btn) btn.remove();
        return;
    }
    
    if (btn) return;

    btn = document.createElement('button');
    btn.id = 'el-header-history-btn';
    btn.title = 'View Edit History';
    btn.style.cssText = 'background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;';
    btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px; height:16px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>`;
    
    // Interaction Logic: Double-tap (Copy URL), Long-press (Jump to Bunker), Single-tap (View Log)
    let elPressTimer = null;
    let elIgnoreClick = false;
    let elLastClick = 0;

    const startPress = () => {
        elIgnoreClick = false;
        elPressTimer = setTimeout(() => {
            elIgnoreClick = true;
            const base = window.location.href.split("?")[0].replace("index.php", "").replace(/\/$/, "");
            const url = base + "/recovery.php";
            window.open(url, "_blank"); 
            if(navigator.vibrate) navigator.vibrate(60);
        }, 600);
    };

    const cancelPress = () => { clearTimeout(elPressTimer); };

    btn.addEventListener("mousedown", startPress);
    btn.addEventListener("touchstart", startPress, {passive: true});
    btn.addEventListener("mouseup", cancelPress);
    btn.addEventListener("mouseleave", cancelPress);
    btn.addEventListener("touchend", cancelPress);

    btn.onclick = (e) => {
        e.stopPropagation();
        if(elIgnoreClick) return;

        const now = Date.now();
        if (now - elLastClick < 300) {
            // Double Tap detected: Confirm before Copy URL
            if (btn._clickTimeout) clearTimeout(btn._clickTimeout);
            const base = window.location.href.split("?")[0].replace("index.php", "").replace(/\/$/, "");
            const url = base + "/recovery.php";

            const performCopy = () => {
                if(navigator.clipboard) {
                    navigator.clipboard.writeText(url).then(() => {
                        const t = document.getElementById("toast");
                        if(t) { t.innerText="Bunker URL Copied"; t.classList.add("show"); setTimeout(()=>t.classList.remove("show"), 2000); }
                    });
                }
            };

            if (typeof window.openConfirm === "function") {
                window.openConfirm("Copy Recovery URL?", "Would you like to copy the direct link to the Recovery Bunker to your clipboard?", performCopy);
            } else {
                performCopy();
            }

            elLastClick = 0;
            return;
        }
        elLastClick = now;

        // Single Tap: Open Picker (Delayed to allow double-tap check)
        btn._clickTimeout = setTimeout(() => {
            elShowHistoryPicker();
        }, 300);
    };
    
    // Prepend to ensure it stays to the left of Undo/Shield
    container.prepend(btn);
}

async function elShowHistoryPicker(searchQuery = '') {
    try {
        // 1. Fetch Current System Log (Filtered by search)
        const limit = elState.view_limit == 501 ? 20000 : elState.view_limit;
        const data1 = await window.sui.api('el_get_log', { query: searchQuery, limit: limit }, { toast: false });
            
        if (data1.status === 'success') {
            const currentLog = data1.log;
            const checkpoint_max_ts = data1.checkpoint_max_ts || '1970-01-01 00:00:00';
                
            // Convert Y-m-d H:i:s to parseable ISO strings (replacing space with T)
            const checkpointTime = new Date(checkpoint_max_ts.replace(' ', 'T')).getTime();

            const options = [];

            const quickActionsHtml = `
                <button onclick="window.open('recovery.php', '_blank')" title="Recovery Bunker" style="background:var(--btn-bg); color:var(--text-primary); border:none; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <span data-sui-icon="shield" data-sui-size="16"></span>
                </button>
                <button onclick="elTriggerCheckpointAction()" title="Create Checkpoint" style="background:var(--btn-bg); color:var(--text-primary); border:none; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <span data-sui-icon="check" data-sui-size="16"></span>
                </button>
                <button onclick="elRemoveLatestPrompt()" title="Remove Latest Entry" style="background:var(--btn-bg); color:var(--danger); border:none; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <span data-sui-icon="trash" data-sui-size="16"></span>
                </button>
            `;
            let hasNewItems = false;
            let hasSecuredHeader = false;

            // Apply User-Defined Limit
            const limit = elState.view_limit;
            const slicedLog = (limit == 501) ? currentLog : currentLog.slice(0, limit);

            slicedLog.forEach((entry, idx) => {
                const entryTime = new Date(entry.date.replace(' ', 'T')).getTime();
                const isPending = entryTime > checkpointTime;if (isPending && !hasNewItems) {
                    options.push({ label: "Pending Checkpoint", type: "header" });
                    hasNewItems = true;
                }
                
                if (!isPending && !hasSecuredHeader) {
                    options.push({ label: "Secured in Checkpoint", type: "header" });
                    
                    // Inject Restore Button at the boundary (ONLY if there are pending changes)
                    if (hasNewItems) {
                        options.push({
                            label: `<div style="background:rgba(255, 59, 48, 0.1); color:var(--danger); padding:12px; border-radius:12px; text-align:center; font-weight:700; margin-bottom:0; border:1px solid var(--danger);">
                                        <div style="display:flex; align-items:center; justify-content:center; gap:8px;">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px; height:16px;"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
                                            Restore to this state
                                        </div>
                                    </div>`,
                            value: "trigger_restore",
                            noStyle: true
                        });
                    }

                    hasSecuredHeader = true;
                }

                const badge = isPending ? `<span style="background:var(--primary); color:var(--primary-text); font-size:8px; padding:2px 5px; border-radius:4px; margin-left:8px; vertical-align:middle;">NEW</span>` : '';

                options.push({
                    label: `<div style="display:flex; flex-direction:column; gap:1px; border-left: ${isPending ? '3px solid var(--primary)' : '3px solid var(--border-color)'}; padding-left: 10px;">
                                <div style="font-size:9px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.3px;">${entry.date} ${badge}</div>
                                <div style="font-size:13px; color:var(--text-primary); line-height:1.4;">${escapeHtml(entry.summary)}</div>
                            </div>`,
                    type: "info",
                    noBorder: true
                });
            });

            // --- PENDING ACTION BUTTON ---
            if (hasNewItems) {
                options.unshift({
                    label: `<div style="background:var(--ai-accent-bg); color:var(--text-primary); border:1px solid var(--ai-accent); padding:14px; border-radius:16px; text-align:center; font-weight:700; margin-bottom:0; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                                <div style="display:flex; align-items:center; justify-content:center; gap:10px;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:18px; height:18px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                    Secure These Changes
                                </div>
                            </div>`,
                    value: "trigger_checkpoint",
                    noStyle: true
                });
            }

            // --- SYSTEM SECURED MESSAGE ---
            if (!hasNewItems && currentLog.length > 0) {
                options.unshift({
                    label: `<div style="background:var(--success-bg); border:1px solid var(--success-text); color:var(--success-text); padding:16px; border-radius:18px; font-size:13px; line-height:1.5; margin-bottom:0; text-align:center; box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
                                <div style="margin-bottom:8px; display:flex; justify-content:center;"><span data-sui-icon="shield-check" data-sui-size="32" data-sui-color="var(--success-text)"></span></div>
                                <strong style="display:block; font-size:16px; font-weight:800; margin-bottom:4px; letter-spacing:-0.3px;">System Fully Secured</strong>
                                All recorded progress is saved in your latest checkpoint. 
                                <div style="font-size:10px; margin-top:8px; opacity:0.7; font-style:italic; border-top:1px solid rgba(46, 125, 50, 0.1); padding-top:8px;">
                                    (Note: This assumes no manual file edits were made outside of the AI/Edit Log system.)
                                </div>
                            </div>`,
                    type: "info",
                    noBorder: true
                });
            }

            // --- SEARCH BAR (TOP-MOST) ---
            options.unshift({
                label: `<div style="padding: 5px 0; position: relative; cursor: default; user-select: text !important; -webkit-user-select: text !important;" 
                             onclick="event.stopPropagation();" 
                             onmousedown="event.stopPropagation();" 
                             onmouseup="event.stopPropagation();">
                            <input type="text" id="el-search-input" placeholder="Search history..." value="${searchQuery}" 
                                   style="width: 100%; padding: 12px 40px 12px 14px; border-radius: 12px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-primary); font-size: 16px; outline: none; box-sizing: border-box; position: relative; z-index: 10; pointer-events: auto !important; cursor: text;"
                                   oninput="elDebouncedSearch(this.value)"
                                   onfocus="this.setSelectionRange(this.value.length, this.value.length);">
                            ${searchQuery ? `
                                <div onclick="elDebouncedSearch(''); event.stopPropagation();" 
                                     onmousedown="event.stopPropagation();" 
                                     onmouseup="event.stopPropagation();"
                                     style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); cursor: pointer; background: var(--btn-bg); border-radius: 50%; z-index: 20; pointer-events: all !important; box-shadow: 0 2px 6px rgba(0,0,0,0.1);">
                                    <span data-sui-icon="close" data-sui-size="14" data-sui-stroke="3"></span>
                                </div>
                            ` : ''}
                        </div>`,
                type: "info",
                noBorder: true
            });

            if (options.length === 0) {
                options.push({ label: "No history recorded yet.", type: "info" });
            }

            if (window.openPicker) {
                // Set searchable (5th param) to FALSE because we use our own server-side search input
                window.openPicker("System Edit Log", options, null, (val) => {
                    if (val === "trigger_checkpoint") {
                        if (typeof scCreateCheckpoint === "function") {
                            setTimeout(scCreateCheckpoint, 300);
                        } else { window.openConfirm("Plugin Missing", "SystemCheckpoint plugin is disabled.", null, false, "OK", null); }
                    } else if (val === "trigger_restore") {
                        window.openConfirm("Restore Checkpoint", "🚨 RESTORE SYSTEM CHECKPOINT?\n\nThis will revert the system to the last saved state.\nAny 'Pending' changes listed above will be lost.", () => {
                            if (typeof scOpenWorker === 'function') {
                                scOpenWorker('restore');
                            } else {
                                window.sui.api("sc_restore_latest", {}, { toast: "Restoring..." }).then(d => {
                                    if (d.client_state && typeof migApplyClientState === "function") migApplyClientState(d.client_state);
                                    location.reload();
                                });
                            }
                        }, true);
                    }
                }, false, quickActionsHtml);
            }
        }
    } catch(e) { window.openConfirm("Load Error", "Failed to load log.", null, false, "OK", null); }
}

window.elPruneLog = async function() {
    window.openConfirm("Prune Log", "Keep only the last 20 entries? This reduces file size significantly.", async () => {
        try {
            await window.sui.api("el_prune_log");
            location.reload();
        } catch(e) {}
    });
};

window.clearEditLog = async function() {
    window.openConfirm("Clear History", "Permanently delete all edit history?", async () => {
        try {
            await window.sui.api("el_clear_log", {}, { toast: "History Cleared" });
            if (typeof elRefreshStatus === 'function') elRefreshStatus();
        } catch(e) {}
    }, true);
};

let _elSearchTimeout = null;
window.elDebouncedSearch = function(val) {
    clearTimeout(_elSearchTimeout);
    _elSearchTimeout = setTimeout(() => {
        elShowHistoryPicker(val);
        // Focus the input after re-render
        setTimeout(() => {
            const inp = document.getElementById('el-search-input');
            if (inp) {
                inp.focus();
                // Move cursor to end
                const len = inp.value.length;
                inp.setSelectionRange(len, len);
            }
        }, 100);
    }, 400);
};

window.elRepairMigration = function() {
    window.openConfirm("Repair Database", "This will wipe your current history database and re-import everything from your original 'edit-log.json.bak' file. Proceed?", async () => {
        try {
            const res = await window.sui.api('el_repair_migration', {}, { toast: "Repairing..." });
            if (res.status === 'success') {
                window.openConfirm("Repair Complete", `Successfully re-imported ${res.count} entries.`, () => location.reload(), false, "Reload App", null);
            } else {
                window.openConfirm("Repair Failed", res.message, null, false, "OK", null);
            }
        } catch(e) { window.sui.toast("Repair failed: " + e.message); }
    }, true);
};

function escapeHtml(text) {
    if (!text) return "";
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\"/g, "&quot;").replace(/'/g, "&#039;");
}
JS;
?>