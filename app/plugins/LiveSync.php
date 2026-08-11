<?php
// ==============================================================================
// PLUGIN: LiveSync
// DESCRIPTION: Auto-Fetch New Notes.
// 1. Auto-fetches new entries from server.
// 2. OVERRIDES the Recorder to provide "Skeleton" UI and "No-Refresh" uploads.
// ==============================================================================

// --- BACKEND HANDLER ---
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'live_sync_delta') {
    while (ob_get_level()) ob_end_clean(); 
    header('Content-Type: application/json');
    error_reporting(0);

    $client_ts = isset($_POST['last_timestamp']) ? (int)$_POST['last_timestamp'] : 0;
    $check_ids = isset($_POST['check_ids']) ? json_decode($_POST['check_ids'], true) : [];

    try {
        // Safety: Check if folder_map exists before joining to prevent SQL errors on fresh installs
        $hasFolders = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='folder_map'")->fetch();
        
        // Use positional parameters (?) exclusively to prevent PDO driver conflicts
        if ($hasFolders) {
            $sql = "SELECT l.*, fm.folder_id FROM logs l LEFT JOIN folder_map fm ON l.id = fm.log_id WHERE l.timestamp > ?";
        } else {
            $sql = "SELECT l.*, NULL as folder_id FROM logs l WHERE l.timestamp > ?";
        }
        $params = [$client_ts];

        if (!empty($check_ids)) {
            $placeholders = implode(',', array_fill(0, count($check_ids), '?'));
            $sql .= " OR l.id IN ($placeholders)";
            foreach($check_ids as $id) $params[] = $id;
        }

        $sql .= " ORDER BY l.timestamp DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $new_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Use partial output flags to ensure we return JSON even if transcription contains invalid characters
        echo json_encode([
            'status' => 'success', 
            'count' => count($new_entries), 
            'entries' => $new_entries
        ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage() ?: 'Database or Encoding Error']);
    }
    exit;
}

// --- SETTINGS UI ---
$plugin_settings_map['LiveSync'] = <<<'HTML'
    <div data-sui-setting="Live Sync" data-sui-desc="Auto-fetch entries & Instant Recording UI." data-sui-id="ls-toggle" data-sui-onchange="toggleLiveSync(this.checked)"></div>

    <div data-sui-setting="Sync Diagnostics" data-sui-desc="Enable detailed background logging." data-sui-id="ls-diag-toggle" data-sui-onchange="lsToggleDiagnostics(this.checked)"></div>

    <div id="ls-diag-container" style="display:none; margin: 0 16px 12px 16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); cursor:pointer;" onclick="suiToggle('ls-diag-acc')">
            <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Console Output</div>
            <div style="display:flex; align-items:center; gap:12px;">
                <button id="ls-fix-btn" onclick="event.stopPropagation(); lsFixStuckItems()" style="display:none; background:none; border:none; color:var(--danger); font-size:10px; font-weight:700; cursor:pointer;">FIX STUCK (0)</button>
                <span data-sui-icon="chevron" data-sui-arrow="ls-diag-acc" data-sui-size="14" style="transition:transform 0.35s; transform: rotate(-90deg);"></span>
            </div>
        </div>
        <div id="ls-diag-acc" class="sui-accordion">
            <div class="sui-accordion-inner" style="padding-top:8px;">
                <div id="ls-console" style="background:#000; color:#00FF41; font-family:monospace; font-size:10px; padding:12px; border-radius:10px; height:140px; overflow-y:auto; border: 1px solid #333; line-height:1.4; box-shadow: inset 0 2px 8px rgba(0,0,0,0.5);">
                    <div style="color:#8E8E93;">[SYSTEM] Console Initialized.</div>
                </div>
                <button onclick="document.getElementById('ls-console').innerHTML=''; lsLog('Console cleared.', 'info')" style="width:100%; margin-top:8px; background:var(--btn-bg); border:1px solid var(--border-color); color:var(--text-secondary); font-size:10px; font-weight:700; padding:8px; border-radius:8px; cursor:pointer;">CLEAR CONSOLE</button>
            </div>
        </div>
    </div>

    <!-- REGISTRY HANDSHAKES SECTION -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin: 12px 0 8px 0; cursor:pointer;" 
 onclick="suiToggle('ls-registry-sec', true); lsPopulateRegistry();">
<div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">System Handshakes</div>
<span data-sui-icon="chevron" data-sui-arrow="ls-registry-sec" data-sui-size="14" data-sui-stroke="2.5" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>
    <div class="sui-accordion" id="ls-registry-sec">
<div class="sui-accordion-inner" id="ls-registry-list" style="display:flex; flex-direction:column; gap:8px; padding-bottom:12px;">
    <div style="text-align:center; padding:10px; color:var(--text-secondary); font-size:12px;">Initializing registry scanner...</div>
</div>
    </div>
HTML;
      

// --- FRONTEND JS ---
$plugin_js .= <<<'JS'
// --- LIVE SYNC JS ---

// --- THE DATA PIPELINE ---
window.cjosPipeline = {
    processBatch: null, // Defined below
    broadcastUpdate: function(logId, data) {
        if (window.cjosUpdateRegistry) {
            window.cjosUpdateRegistry.forEach(fn => { try { fn(logId, data); } catch(e) { console.error("Update Hook Failed", e); } });
        }
    }
};

// Global Event Bus Implementation
window.cjosHooks = {
    _hooks: {
        onIngest: [],    // New entry created (id, entry)
        onTranscribe: [], // Transcription completed (id, text, entry)
        onDelete: [],    // Entry removed (id)
        onUpdate: []     // Entry data changed (id, entry)
    },
    register: function(hookName, fn) {
        if (this._hooks[hookName]) this._hooks[hookName].push(fn);
    },
    emit: function(hookName, ...args) {
        if (this._hooks[hookName]) {
            this._hooks[hookName].forEach(fn => {
                try { fn(...args); } catch(e) { console.error(`[Hook:${hookName}] Error:`, e); }
            });
        }
    }
};

window.cjosBroadcastUpdate = window.cjosPipeline.broadcastUpdate;

window.lsFixStuckItems = async function() {
    const btn = document.getElementById('ls-fix-btn');
    const ids = JSON.parse(btn.dataset.stuckIds || "[]");
    if (ids.length === 0) return;

    btn.innerText = "FIXING...";
    btn.disabled = true;

    try {
        // Use the existing AI Reset API to clear flags and restore text
        await window.sui.api("ai_reset_entries", { ids: ids }, { toast: "Stuck Flags Cleared" });
        
        // Local cleanup for orphaned items (those not on server)
        ids.forEach(id => {
            const log = logs.find(l => l.id === id);
            if (log) {
                if (log.original_text) log.transcription = log.original_text;
                log.ai_processed = 0;
            }
        });

        lsLog(`Successfully reset ${ids.length} items.`, "success");
        btn.style.display = 'none';
        if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
    } catch (e) {
        lsLog("Fix failed: " + e.message, "error");
        btn.disabled = false;
        btn.innerText = `RETRY FIX (${ids.length})`;
    }
};

let lsDiagEnabled = localStorage.getItem("cjos_ls_diag_enabled") === "true";

window.lsToggleDiagnostics = function(enabled) {
    lsDiagEnabled = enabled;
    localStorage.setItem("cjos_ls_diag_enabled", enabled);
    const cont = document.getElementById('ls-diag-container');
    if (cont) cont.style.display = enabled ? 'block' : 'none';
    if (enabled) lsLog("Diagnostics enabled.", "info");
};

window.lsLog = function(msg, type = 'info') {
    if (!lsDiagEnabled) return;
    const el = document.getElementById('ls-console');
    if (!el) return;
    const time = new Date().toLocaleTimeString([], {hour12:false, hour:'2-digit', minute:'2-digit', second:'2-digit'});
    const color = type === 'success' ? '#34C759' : (type === 'error' ? '#FF3B30' : (type === 'warn' ? '#FF9500' : '#00FF41'));
    
    const div = document.createElement('div');
    div.style.marginBottom = "2px";
    div.innerHTML = `<span style="color:#8E8E93;">[${time}]</span> <span style="color:${color}; font-weight:bold;">[${type.toUpperCase()}]</span> ${msg}`;
    el.appendChild(div);
    
    // Auto-prune to 50 lines to save memory
    while (el.children.length > 50) el.removeChild(el.firstChild);
    el.scrollTop = el.scrollHeight;
};

let lsEnabled = localStorage.getItem("cjos_ls_enabled") !== "false";
let lsInterval = null;
let highlightObserver = null;
window.lsIsProcessing = false; // Flag to prevent sync-clash during in-app recording

// Adaptive Polling Configuration
let lsCurrentInterval = 4000;
const LS_MIN_INTERVAL = 4000;
const LS_MAX_INTERVAL = 32000;
let lsIdleCycles = 0;

function stopLiveSync() {
    if (lsInterval) {
        clearTimeout(lsInterval);
        lsInterval = null;
    }
}

function startLiveSync() {
    stopLiveSync();
    if (lsEnabled && document.visibilityState === "visible") {
        lsInterval = setTimeout(executeLiveSyncCycle, lsCurrentInterval);
    }
}

async function executeLiveSyncCycle() {
    const hadNewData = await checkForNewEntries();
    
    if (hadNewData) {
        lsIdleCycles = 0;
        lsCurrentInterval = LS_MIN_INTERVAL;
    } else {
        lsIdleCycles++;
        if (lsIdleCycles >= 3) {
            // Gradually back off the polling frequency if no database changes are occurring
            lsCurrentInterval = Math.min(LS_MAX_INTERVAL, lsCurrentInterval * 2);
            lsLog(`Resting. Relaxing poll interval to ${lsCurrentInterval / 1000}s`, "info");
        }
    }
    
    if (lsEnabled && document.visibilityState === "visible") {
        lsInterval = setTimeout(executeLiveSyncCycle, lsCurrentInterval);
    }
}

// Snap back to fast polling the instant the user interacts with the app
function resetLiveSyncInterval() {
    if (lsCurrentInterval > LS_MIN_INTERVAL) {
        lsLog(`User activity detected. Resetting poll interval to ${LS_MIN_INTERVAL / 1000}s`, "info");
        lsCurrentInterval = LS_MIN_INTERVAL;
        lsIdleCycles = 0;
        startLiveSync();
    }
}

window.addEventListener("scroll", resetLiveSyncInterval, { passive: true });
window.addEventListener("touchstart", resetLiveSyncInterval, { passive: true });
window.addEventListener("click", resetLiveSyncInterval, { passive: true });

// Registry init moved to app.js





window.lsPopulateRegistry = function() {
    const cont = document.getElementById('ls-registry-list');
    if (!cont) return;

    const points = [
        { name: "Card Decoration", api: "registerCardPlugin(fn, priority)", count: (window.cjosPluginRegistry || []).length },
        { name: "Post-Render Hooks", api: "registerRefreshHook(fn)", count: (window.cjosRefreshRegistry || []).length },
        { name: "Data Update Bus", api: "registerUpdateHook(fn)", count: (window.cjosUpdateRegistry || []).length }
    ];

    // Detect Interaction Manager Subscriptions
    if (window.InteractionManager && window.InteractionManager._debugSubscribers) {
        // This assumes InteractionManager provides a way to count, otherwise we list the API
    }
    points.push({ name: "Gesture Engine", api: "InteractionManager.subscribe()", count: "Active" });

    cont.innerHTML = points.map(p => `
        <div style="background:var(--bg-color); border:1px solid var(--border-color); border-radius:10px; padding:10px; display:flex; justify-content:space-between; align-items:center;">
            <div style="flex:1;">
                <div style="font-size:13px; font-weight:700; color:var(--text-primary);">${p.name}</div>
                <div style="font-family:monospace; font-size:10px; color:var(--primary); margin-top:2px; opacity:0.8;">${p.api}</div>
            </div>
            <div style="background:var(--card-bg); padding:4px 8px; border-radius:6px; font-family:monospace; font-size:12px; font-weight:800; border:1px solid var(--border-color);">
                ${p.count}
            </div>
        </div>
    `).join('');
};

window.addEventListener("load", () => {
    // 1. Styles
      
    const lsStyle = document.createElement("style");
    lsStyle.innerHTML = `
        .card.is-processing { cursor: wait; pointer-events: none; opacity: 0.8; } 
        .card.is-processing .phantom-actions { display: none !important; }
        /* HIDE EDIT BUTTON ON PHANTOM CARDS TO PREVENT OVERLAP */
        .card.phantom-card .manual-edit-btn { display: none !important; } 
        .card.phantom-card { border: 2px dashed var(--border-heavy) !important; opacity: 0.95; }
        /* Overlay to modify background appearance without hijacking card-bg */
        .card.phantom-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: var(--bg-color); opacity: 0.45; pointer-events: none; z-index: 0; }
        .phantom-actions { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-color); display: flex; flex-direction: column; align-items: flex-start; gap: 8px; position: relative; z-index: 1; }
        .phantom-info-badge { font-size: 11px; font-weight: 700; color: var(--text-secondary); background-color: var(--card-bg); border: 1px dashed var(--border-color); padding: 6px 12px; border-radius: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .phantom-btn-row { display: flex; gap: 8px; width: 100%; position: relative; z-index: 2; }
        .btn-phantom-jump { background: var(--primary); color: var(--primary-text); border: none; border-radius: 14px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; flex: 1; }
        .btn-phantom-move { background: var(--btn-bg); color: var(--btn-text); border: 1px solid var(--border-color); border-radius: 14px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; flex: 1; }
        .btn-phantom-dismiss { background: var(--btn-bg); color: var(--text-secondary); border: 1px solid var(--border-color); border-radius: 14px; width: 36px; font-size: 18px; font-weight: 400; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        @keyframes highlightPulse { 
            0% { background-color: rgba(0, 122, 255, 0.15); } 100% { background-color: var(--card-bg); } 
        }
        .card.new-entry-highlight { animation: highlightPulse 2.5s ease-out forwards; }
        .card.phantom-dismissing {
            opacity: 0 !important;
            transform: translateX(-20px) scale(0.95) !important;
            max-height: 0 !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            overflow: hidden !important;
            pointer-events: none !important;
            transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
    `;
    document.head.appendChild(lsStyle);

    // 2. Setup Observers
    highlightObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add("new-entry-highlight");
                observer.unobserve(entry.target); 
            }
        });
    }, { threshold: 0.3 });

    const toggle = document.getElementById("ls-toggle");
    if(toggle) toggle.checked = lsEnabled;

    const diagToggle = document.getElementById("ls-diag-toggle");
    if(diagToggle) diagToggle.checked = lsDiagEnabled;
    const diagCont = document.getElementById('ls-diag-container');
    if(diagCont) diagCont.style.display = lsDiagEnabled ? 'block' : 'none';
    
    // 3. Init Logic
    if (lsEnabled) {
        startLiveSync();
        overrideRecorder(); 
        // Check for pending items on initial load
        setTimeout(checkAndPromptPending, 2000);
    }

    // 4. Visibility Watcher (Battery Friendly)
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") {
            startLiveSync();
        } else {
            stopLiveSync();
        }
    });
});

// --- STARTUP PROMPT LOGIC ---
async function checkAndPromptPending() {
    if (window.lsIsProcessing || typeof window.processTranscriptionBatch !== "function") return;
    
    const allPending = logs.filter(l => l.transcription === "(Pending Transcription...)");
    if (allPending.length > 0 && typeof window.openPicker === "function") {
        const choice = await new Promise((resolve) => {
            window.openPicker("Pending Items Detected", [
                { label: `Transcribe All Pending (${allPending.length})`, value: "yes" },
                { label: "Ignore for now", value: "no" }
            ], null, (val) => resolve(val));
        });

        if (choice === "yes") {
            window.processTranscriptionBatch(allPending);
        }
    }
}

window.toggleLiveSync = function(enabled) {
    lsEnabled = enabled;
    localStorage.setItem("cjos_ls_enabled", enabled);
    if(enabled) { 
        if (document.visibilityState === "visible") {
            startLiveSync();
        }
        overrideRecorder(); 
        window.openConfirm("Live Sync Active", "Live Sync & Instant UI Active.", () => {
            location.reload();
        }, false, "Reload", null);
    } 
    else { stopLiveSync(); }
};

// --- A. SYNC LOGIC ---
async function checkForNewEntries() {
    if (typeof isRecording !== "undefined" && isRecording) return false;
    if (window.lsIsProcessing) return false; 
    if (document.querySelector(".manual-edit-textarea")) return false; 

    let maxTs = 0;
    const pendingIds = [];
    const now = Math.floor(Date.now() / 1000);

    if (typeof logs !== "undefined" && Array.isArray(logs)) {
        logs.forEach(l => { 
            const t = parseInt(l.timestamp); 
            if (t > maxTs && t <= (now + 300)) maxTs = t; 
            if (l.transcription === "(Pending Transcription...)" || l.transcription === "(Transcribing...)" || l.ai_processed == 1) {
                pendingIds.push(l.id);
            }
        });
    }

    if (pendingIds.length > 0) {
        lsLog(`Polling (Tracking ${pendingIds.length} pending items)...`, "info");
    }

    try {
        const data = await window.sui.api("live_sync_delta", { 
            last_timestamp: maxTs,
            check_ids: JSON.stringify(pendingIds)
        }, { toast: false, plugin: 'LiveSync' });
        
        if (data.status === "success" && data.entries.length > 0) {
            let addedCount = 0;
            let updatedCount = 0;
            let stuckIds = [];
            
            const now = Math.floor(Date.now() / 1000);
            data.entries.forEach(entry => {
                const existing = logs.find(l => l.id === entry.id);
                if (!existing) addedCount++;
                else if (existing.transcription !== entry.transcription || existing.ai_processed !== entry.ai_processed) {
                    updatedCount++;
                } else {
                    // Only flag as "Stuck" if it's been pending for more than 60 seconds
                    const age = now - parseInt(entry.timestamp);
                    if (age > 60) stuckIds.push(entry.id);
                }
            });

            // Identify the "Missing" item (tracked by client but not returned by server)
            const returnedIds = data.entries.map(e => e.id);
            const missingIds = pendingIds.filter(id => !returnedIds.includes(id));

            if (addedCount > 0 || updatedCount > 0) {
                lsLog(`Sync: ${addedCount} new, ${updatedCount} updated.`, "success");
                
                // Trigger a toast for updates (e.g. Transcription Received)
                if (updatedCount > 0 && window.sui && window.sui.toast) {
                    window.sui.toast(`${updatedCount} Note(s) Updated`, { plugin: 'LiveSync', caller: 'checkForNewEntries' });
                }
            } 
            
            if (stuckIds.length > 0 || missingIds.length > 0) {
                const totalStuck = stuckIds.length + missingIds.length;
                lsLog(`Detected ${totalStuck} stuck items.`, "warn");
                if (missingIds.length > 0) lsLog(`Orphaned (Local only): ${missingIds.join(', ')}`, "error");
                
                const fixBtn = document.getElementById('ls-fix-btn');
                if (fixBtn) {
                    fixBtn.style.display = 'block';
                    fixBtn.innerText = `FIX STUCK (${totalStuck})`;
                    fixBtn.dataset.stuckIds = JSON.stringify([...stuckIds, ...missingIds]);
                }
            }
        } else if (data.status === "success") {
            lsLog("Check complete: No new data.", "info");
        }

        if (data.status === "success" && data.entries.length > 0) {
    data.entries.sort((a,b) => a.timestamp - b.timestamp);
    let addedCount = 0;
    let updatedCount = 0;
    let activeFolderId = (typeof currentFolderId !== "undefined") ? currentFolderId : null;data.entries.forEach(entry => {
                const existing = logs.find(l => l.id === entry.id);
                if (!existing) {
                    logs.unshift(entry);
                    if (typeof so_map !== "undefined" && entry.folder_id) so_map[entry.id] = entry.folder_id;

                    const card = injectEntryCard(entry); 
                    if (card) {
                        if (activeFolderId !== null && entry.folder_id != activeFolderId) {
                            makeCardPhantom(card, entry.folder_id);
                        } else {
                            if (highlightObserver) highlightObserver.observe(card);
                        }
                    }

                    if (window.cjosHooks) {
                        window.cjosHooks.emit('onIngest', entry.id, entry);
                        if (entry.transcription && entry.transcription !== "(Pending Transcription...)" && entry.transcription !== "(Transcribing...)") {
                            window.cjosHooks.emit('onTranscribe', entry.id, entry.transcription, entry);
                        }
                    }
                    addedCount++;
                } else if (existing.transcription !== entry.transcription || existing.ai_processed !== entry.ai_processed) {
                    // UPDATE EXISTING ENTRY (Text or AI State)
                    const oldText = existing.transcription;
                    
                    existing.transcription = entry.transcription;
                    existing.ai_processed = entry.ai_processed;
                    existing.ai_assistant_id = entry.ai_assistant_id;
                    
                    const cb = document.querySelector(`.custom-checkbox[data-id="${entry.id}"]`);
                    const card = cb ? cb.closest('.card') : null;
                    if (card) {
                        // Update Text if changed
                        if (oldText !== entry.transcription) {
                            const t = card.querySelector('.transcription');
                            if (t) {
                                t.innerHTML = entry.transcription;
                                if (window.refreshReadMoreButtons) window.refreshReadMoreButtons();
                            }
                        }
                        // Always re-decorate if either changed (Handles AI Badge appearance)
if (window.sui && window.sui.decorateCard) window.sui.decorateCard(card, existing);
                    
// Trigger AI structural cleanup (removes processing dots and text)
if (window.aiDecorateCard) window.aiDecorateCard(card, existing);
                }

                if (window.cjosHooks && (oldText === "(Transcribing...)" || oldText === "(Pending Transcription...)") && entry.transcription.indexOf('(') !== 0) {window.cjosHooks.emit('onTranscribe', entry.id, entry.transcription, existing);
                    }
                    updatedCount++;
                }
            });

            if (addedCount > 0 || updatedCount > 0) {
                // Update plugins via centralized bus
                if (typeof runMasterFilter === "function") runMasterFilter();
                if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
                
                if (addedCount > 0 && window.sui && window.sui.toast) {
                    window.sui.toast(addedCount + " New Entry Synced", { 
                        plugin: "LiveSync", 
                        caller: "checkForNewEntries", 
                        metrics: { added: addedCount, last_ts: maxTs, received_ids: data.entries.map(e => e.id) } 
                    });
                }

                // --- AUTO-PROMPT FOR TRANSCRIPTION (GLOBAL SYSTEM CHECK) ---
                // Check the entire logs array for any pending items (old or new)
                const allPending = logs.filter(l => l.transcription === "(Pending Transcription...)");
                
                if (allPending.length > 0 && !window.lsIsProcessing && typeof window.openPicker === "function") {
                    setTimeout(async () => {
                        const choice = await new Promise((resolve) => {
                            window.openPicker("Pending Items Detected", [
                                { label: `Transcribe All Pending (${allPending.length})`, value: "yes" },
                                { label: "Ignore for now", value: "no" }
                            ], null, (val) => resolve(val));
                        });

                        if (choice === "yes") {
                            window.processTranscriptionBatch(allPending);
                        }
                    }, 1000); // Slight delay after toast
                }
                return true; // Return state to inform backoff engine that data changed
            }
        }
    } catch(e) { 
        console.error(e); 
        lsLog("Sync Error: " + e.message, "error");
    }
    return false; // No changes occurred
}

// --- B. DOM HELPERS ---
function makeCardPhantom(card, targetFolderId) {
    card.classList.add("phantom-card");
    let folderName = "Other Folder";
    if (targetFolderId && typeof so_folders !== "undefined") {
        const f = so_folders.find(x => x.id == targetFolderId);
        if(f) folderName = f.name;
    }
    const content = card.querySelector(".card-content");
    const actions = document.createElement("div"); actions.className = "phantom-actions";
    
    actions.innerHTML = `
        <div class="phantom-info-badge">New in ${folderName}</div>
        <div class="phantom-btn-row">
            <button class="btn-phantom-jump">Go to Card</button>
            <button class="btn-phantom-move">Move Here</button>
            <button class="btn-phantom-dismiss">&times;</button>
        </div>
    `;
    
    // 1. JUMP
    actions.querySelector(".btn-phantom-jump").onclick = (e) => {
        e.stopPropagation(); 
        if (typeof jumpToFolderAndHighlight === "function") {
            const id = card.querySelector(".custom-checkbox").getAttribute("data-id");
            jumpToFolderAndHighlight(id, targetFolderId);
        }
    };

    // 2. MOVE HERE
    actions.querySelector(".btn-phantom-move").onclick = (e) => {
        e.stopPropagation();
        const id = card.querySelector(".custom-checkbox").getAttribute("data-id");
        const targetFid = (typeof currentFolderId !== "undefined" && currentFolderId !== null) ? currentFolderId : 0;
        
        window.sui.api("folder_assign", { folder_id: targetFid, log_ids: [id] }, { toast: false }).then(() => {
            if(typeof so_map !== "undefined") so_map[id] = targetFid;
            card.classList.remove("phantom-card");
            actions.remove();
            
            // Visual Feedback: Ripple effect
            card.classList.add("ai-just-finished");
            setTimeout(() => card.classList.remove("ai-just-finished"), 2000);
            
            if(window.renderFolderBadges) window.renderFolderBadges();
        });
    };

    // 3. DISMISS
    actions.querySelector(".btn-phantom-dismiss").onclick = (e) => {
        e.stopPropagation();
        // Set explicit max-height to current height to allow smooth CSS transition to 0
        card.style.maxHeight = card.offsetHeight + "px";
        requestAnimationFrame(() => {
            card.classList.add("phantom-dismissing");
        });
        setTimeout(() => card.remove(), 550);
    };

    content.appendChild(actions);
}

// --- FIXED INJECTION LOGIC ---
function injectEntryCard(entry, isSkeleton = false) {
    const container = document.getElementById("entries-container");
    if(!container) return null;

    // Clean up empty state if present before layout calculations
    const emptyState = container.querySelector(".sui-empty-state");
    if (emptyState) emptyState.remove();

    // 1. Create Card
    let card;
    if (typeof window.createStandardCardDOM === "function") {
        card = window.createStandardCardDOM(entry);
    } else {
        card = document.createElement("div"); card.className = "card";
        card.innerHTML = `<div class="card-content" style="padding:20px;">${entry.transcription}</div>`;
    }

    if(isSkeleton) {
        card.classList.add("is-processing");
        const t = card.querySelector(".transcription");
        if(t && window.suiSkeleton) t.innerHTML = window.suiSkeleton(3);
    }

    // 2. Determine Date Label (e.g. "Today")
    const [datePart, timePart] = entry.date_display.split(" ");
    let dateLabel = "Today"; 
    if(typeof window.getRelativeDateLabel === "function") dateLabel = window.getRelativeDateLabel(datePart);

    // 3. Find Proper Insertion Point (Skip Pinned Items)
    const children = Array.from(container.children);
    let targetHeader = null;
    let firstUnpinnedNode = null;

    for (let i = 0; i < children.length; i++) {
        const node = children[i];
        
        // Skip Pinned Items (DogEar Plugin)
        if (node.id === "plugin-dogear-header" || 
            node.id === "stacks-section-wrapper" || 
            node.textContent.includes("📌") || 
            node.classList.contains("is-dogeared")) {
            continue;
        }

        // Keep reference to the top of the unpinned list
        if (!firstUnpinnedNode) firstUnpinnedNode = node;

        // Check if "Today" header already exists
        if (node.classList.contains("section-header") && node.textContent.trim() === dateLabel) {
            targetHeader = node;
            break; 
        }
    }

    if (targetHeader) {
        // Header Exists: Insert immediately after it
        if (targetHeader.nextSibling) {
            container.insertBefore(card, targetHeader.nextSibling);
        } else {
            container.appendChild(card);
        }
        targetHeader.style.display = ""; // Ensure visible
    } else {
        // Header Missing: Create it
        const newHeader = document.createElement("div"); 
        newHeader.className = "section-header"; 
        newHeader.textContent = dateLabel; 
        
        if (firstUnpinnedNode) {
            // Insert Header + Card before the first unpinned item (e.g. "Yesterday")
            container.insertBefore(newHeader, firstUnpinnedNode);
            container.insertBefore(card, firstUnpinnedNode);
        } else {
            // List empty (or only pinned)
            container.appendChild(newHeader);
            container.appendChild(card);
        }
    }

    // --- THE FORMAL HANDSHAKE ---
    // We execute plugin hooks in a specific order to ensure dependencies are met
    if(window.cjosPluginRegistry) {
        // Priority 1: Structural/Data (Folders, To-Do)
        // Priority 2: Content (ManualEditor, WordCounter)
        // Priority 3: Visuals (DogEar, Animations)
        window.cjosPluginRegistry.sort((a, b) => (a.priority || 50) - (b.priority || 50));
        window.cjosPluginRegistry.forEach(plugin => {
            try { plugin.fn(card, entry); } catch(e) { console.error("Plugin Hook Failed", e); }
        });
    }
    return card;
}

// --- C. RECORDER OVERRIDE ---
function overrideRecorder() {
    if (typeof window.cjosUpload === "function") {
window.cjosUpload = async function() {
            window.lsIsProcessing = true; // Lock sync
            const apiKey = localStorage.getItem("cjos_api_key") || "";
            
            // 1. Release Mic
            if (typeof mediaRecorder !== "undefined" && mediaRecorder && mediaRecorder.stream) {
                mediaRecorder.stream.getTracks().forEach(track => track.stop());
            }

            await new Promise(r => setTimeout(r, 100));
            if(audioChunks.length === 0) { window.openConfirm("Recording Error", "Recording was empty.", null, false, "OK", null); return; }
            
            // 2. Prepare Local Blob
            const audioBlob = new Blob(audioChunks, { type: "audio/webm" });
            const localAudioUrl = URL.createObjectURL(audioBlob);
            
            const tempId = "temp_" + Date.now();
            const now = new Date();
            const dateDisp = now.getFullYear() + "-" + String(now.getMonth()+1).padStart(2,0) + "-" + String(now.getDate()).padStart(2,0) + " " + String(now.getHours()).padStart(2,0) + ":" + String(now.getMinutes()).padStart(2,0) + ":" + String(now.getSeconds()).padStart(2,0);

            // 3. Inject Skeleton Card
            const isMonitoring = (typeof aiConfig !== 'undefined' && aiConfig.monitoring_enabled) || (localStorage.getItem('cjos_ai_monitoring') === 'true');
            const mockEntry = { 
                id: tempId, 
                date_display: dateDisp, 
                audio_path: localAudioUrl, 
                transcription: "", 
                timestamp: Math.floor(Date.now()/1000),
                ai_processed: isMonitoring ? 1 : 0
            };
            logs.unshift(mockEntry); 
            if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
            const cardElement = injectEntryCard(mockEntry, true);
            
            // PHANTOM LOGIC: 
            // Since new recordings are now always Unsorted (0), if the user is currently 
            // viewing a specific folder, we must mark this new card as a Phantom.
            if (typeof currentFolderId !== "undefined" && currentFolderId !== null && currentFolderId !== 0) {
                if (typeof makeCardPhantom === "function" && cardElement) {
                    makeCardPhantom(cardElement, 0); // 0 = Unsorted
                }
            }

            // 4. Determine Upload Strategy
            try {
                const formData = new FormData();
                formData.append("action", "upload_only");
                formData.append("audio", audioBlob, "recording.webm");
                formData.append("_tempId", tempId);
                
                // 4a. Upload File (Core Backend)
                const upRes = await fetch(window.CJOS_API_URL, { method: "POST", body: formData });
                const upData = await upRes.json();
                
                if (upData.status !== "success") throw new Error(upData.message);
                const realId = upData.id;

                // --- EARLY ID SYNC ---
                // Update DOM ID immediately so hooks can find the card
                if (cardElement) {
                    const cb = cardElement.querySelector(".custom-checkbox");
                    if (cb) cb.setAttribute("data-id", realId);
                }

                const logIndex = logs.findIndex(l => l.id === tempId);
                if(logIndex !== -1) logs[logIndex].id = realId;
                
                if (window.cjosHooks) window.cjosHooks.emit('onIngest', realId, logs[logIndex]);

                // 4b. Transcribe (Plugin Endpoint - ConjureCore)
                const transData = await window.sui.api("cc_transcribe", {
                    id: realId,
                    api_key: apiKey,
                    model: localStorage.getItem("cjos_model") || "whisper-1",
                    prompt: localStorage.getItem("cjos_prompt") || ""
                }, { toast: false, errorToast: false });
                
                // 5. Update UI (Success)
                if(logIndex !== -1) {
                    logs[logIndex].transcription = transData.text || "";
                }
                
                /* 
                if (typeof so_map !== "undefined" && so_map[tempId]) {
                    so_map[realId] = so_map[tempId];
                    delete so_map[tempId];
                    
                    if(so_map[realId]) {
                        window.sui.api("folder_assign", { 
                            folder_id: so_map[realId], 
                            log_ids: [realId] 
                        }, { toast: false });
                    }
                }
                */

                if(cardElement) {
                    // Update ID attribute
                    const cb = cardElement.querySelector(".custom-checkbox");
                    if(cb) cb.setAttribute("data-id", realId);
                    
                    // Update Text
                    cardElement.classList.remove("is-processing");
                    const textDiv = cardElement.querySelector(".transcription");
                    textDiv.innerHTML = transData.text || "[No Text]";
                    textDiv.classList.add("truncated");
                    
                    setTimeout(() => { 
                        const btn = cardElement.querySelector(".read-more-btn"); 
                        if(textDiv.scrollHeight > textDiv.clientHeight) btn.style.display = "inline-block"; 
                    }, 50);
                }

                // Final Hook: All state (Logs, DOM ID, DOM Text) is now ready
                if (window.cjosHooks && logIndex !== -1) {
                    window.cjosHooks.emit('onTranscribe', realId, transData.text, logs[logIndex]);
                }

                // Show Toast
                if (window.sui && window.sui.toast) {
    window.sui.toast("Transcription Done", { 
        plugin: "LiveSync", 
        caller: "cjosUpload", 
        metrics: { id: realId, text_len: transData.text.length } 
    });
}window.lsIsProcessing = false; // Unlock sync

            } catch (e) {
                console.error(e);
                if(cardElement) {
                    cardElement.classList.remove("is-processing");
                    cardElement.querySelector(".transcription").textContent = "Error: " + e.message;
                    cardElement.style.opacity = "0.5";
                }
                window.openConfirm("Upload Error", "Upload failed: " + e.message, null, true, "OK", null);
            }
            window.lsIsProcessing = false; // Ensure lock is released on error
        };
    }
}

window.cjosPipeline.processBatch = async function(items) {
    if (window.lsIsProcessing) return;
    window.lsIsProcessing = true;

    // Sort items chronologically (oldest first)
    items.sort((a, b) => parseInt(a.timestamp) - parseInt(b.timestamp));

    // --- PENDING SCANNER ---
    const allPending = logs.filter(l => l.transcription === "(Pending Transcription...)");
    const selectedPending = items.filter(l => l.transcription === "(Pending Transcription...)");

    if (allPending.length > selectedPending.length && typeof window.openPicker === "function") {
        const choice = await new Promise((resolve) => {
            window.openPicker("Pending Items Detected", [
                { label: `Process Selected Only (${items.length})`, value: "selected" },
                { label: `Process All Pending (${allPending.length})`, value: "all" },
                { label: "Cancel", value: "cancel" }
            ], null, (val) => resolve(val));
        });
        if (choice === "cancel") { window.lsIsProcessing = false; return; }
        if (choice === "all") items = allPending;
    }

    const apiKey = localStorage.getItem('cjos_api_key') || "";
    
    const selectedIds = items.map(i => i.id);
    if (window.cjosToggleSelectMode) window.cjosToggleSelectMode(false);

    const cardMap = new Map();
    selectedIds.forEach(id => {
        const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
        if (cb) {
            const card = cb.closest('.card');
            const textDiv = card.querySelector('.transcription');
            if (card && textDiv) {
                cardMap.set(id, { card, textDiv });
                card.classList.add('is-processing');
                textDiv.innerHTML = window.suiSkeleton ? window.suiSkeleton(3) : '...';
            }
        }
    });
    
    for (const id of selectedIds) {
        const entry = cardMap.get(id);
        if (!entry) continue;
        entry.card.scrollIntoView({ behavior: 'smooth', block: 'center' });

        try {
            const data = await window.sui.api('cc_transcribe', {
                id: id, api_key: apiKey,
                model: localStorage.getItem("cjos_model") || "whisper-1",
                prompt: localStorage.getItem("cjos_prompt") || ""
            }, { toast: false });

            if (data) {
                const logIdx = logs.findIndex(l => l.id === id);
                if (logIdx !== -1) logs[logIdx].transcription = data.text;
                window.cjosHooks.emit('onTranscribe', id, data.text, logs[logIdx]);

                entry.textDiv.textContent = data.text;
                entry.card.classList.remove('is-processing');
                entry.card.classList.add('ai-just-finished');
                
                if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
                setTimeout(() => entry.card.classList.remove('ai-just-finished'), 2000);
            }
        } catch(e) { 
            entry.card.classList.remove('is-processing');
            entry.textDiv.textContent = "Error reprocessing.";
        }
    }

    if (window.sui && window.sui.toast) {
        window.sui.toast(items.length + " Items Reprocessed", { plugin: "LiveSync", caller: "processBatch" });
    }
    window.lsIsProcessing = false;
};

// Legacy bridge for app.js
window.processTranscriptionBatch = window.cjosPipeline.processBatch;
JS;
?>