<?php
// ==============================================================================
// PLUGIN: Location History
// DESCRIPTION: Scroll & Filter Bookmarks.
// Saves and restores the current folder filter and vertical scroll position.
// ==============================================================================

$lh_data_file = CJOS_PATH_DATA . '/location-history.json';

// --- 1. BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    
    // SAVE LOCATION
    if ($_POST['plugin_action'] === 'lh_save') {
        error_reporting(0); 
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $newLoc = json_decode($_POST['location'], true);
        $history = file_exists($lh_data_file) ? json_decode(file_get_contents($lh_data_file), true) : [];
        if (!is_array($history)) $history = [];
        
        array_unshift($history, $newLoc);
        $history = array_slice($history, 0, 20); // Keep last 20 entries
        
        if (!is_dir(dirname($lh_data_file))) mkdir(dirname($lh_data_file), 0777, true);
        file_put_contents($lh_data_file, json_encode($history, JSON_PRETTY_PRINT));
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET HISTORY
    if ($_POST['plugin_action'] === 'lh_get') {
        error_reporting(0); 
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $history = file_exists($lh_data_file) ? json_decode(file_get_contents($lh_data_file), true) : [];
        echo json_encode(['status' => 'success', 'history' => $history]);
        exit;
    }

    // DELETE SINGLE
    if ($_POST['plugin_action'] === 'lh_delete_single') {
        error_reporting(0); while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $idx = (int)$_POST['index'];
        $history = file_exists($lh_data_file) ? json_decode(file_get_contents($lh_data_file), true) : [];
        if (isset($history[$idx])) {
            array_splice($history, $idx, 1);
            file_put_contents($lh_data_file, json_encode($history, JSON_PRETTY_PRINT));
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    // CLEAR HISTORY
    if ($_POST['plugin_action'] === 'lh_clear') {
        if (file_exists($lh_data_file)) unlink($lh_data_file);
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- 2. HEADER BUTTON ---
// Icon: Map Pin
$plugin_buttons[] = ['lh-btn', '<path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"/><circle cx="12" cy="10" r="3"/>', 'lhOpenMenu()', 'Location History', 'secondary'];

// --- 3. SETTINGS UI ---
$plugin_settings_map['LocationHistory'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Location History</label>
        <div class="setting-desc">Saves your folder filter and scroll position to the server.</div>
        <button onclick="lhClearHistory()" class="text-btn" style="color:var(--danger); margin-top:8px; font-weight:600;">Clear All Saved Locations</button>
    </div>
HTML;

// --- 4. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- LOCATION HISTORY JS ---

window.lhOpenMenu = async function() {
    try {
        const data = await window.sui.api("lh_get", {}, { toast: false });
        
        const options = [
            { 
                label: `<div style="display:flex; align-items:center; gap:12px; color:var(--primary);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:20px; height:20px;"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span style="font-weight:700;">Save Current Spot</span>
                        </div>`, 
                value: "action_save" 
            }
        ];

        if (data.history && data.history.length > 0) {
            options.push({ label: "Recent Locations", type: "header" });
            data.history.forEach((loc, idx) => {
                options.push({
                    label: `<div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
                                <div style="display:flex; flex-direction:column; gap:2px; text-align:left;">
                                    <div style="font-weight:600; font-size:15px; color:var(--text-primary);">${loc.name}</div>
                                    <div style="font-size:12px; color:var(--text-secondary); opacity:0.8; display:flex; align-items:center; gap:4px;">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px; height:12px;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                        ${loc.folder_name}
                                    </div>
                                </div>
                                <button onclick="event.stopPropagation(); lhDeleteSingle(${idx})" style="background:none; border:none; color:var(--text-secondary); padding:8px; cursor:pointer; opacity:0.4; margin-right:-10px;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px; height:18px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </div>`,
                    value: idx
                });
            });
        }

        if (window.openPicker) {
            window.openPicker("Location History", options, null, (val) => {
                if (val === "action_save") lhSaveCurrent();
                else if (typeof val === "number") lhApplyLocation(data.history[val]);
            });
        } else {
            window.openConfirm("Plugin Required", "SharedUI plugin is required for this menu.", null, false, "OK", null);
        }
    } catch(e) { window.openConfirm("Error", "Error loading history", null, false, "OK", null); }
};

async function lhSaveCurrent() {
    const defaultName = "Spot at " + new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    window.openInput("Save Location", "Name this location:", defaultName, async (name) => {
        if (name === null) return;

        const scrollEl = document.getElementById("main-scroll");
    const folderId = (typeof currentFolderId !== "undefined") ? currentFolderId : null;
    
    // Resolve folder name for display
    let folderName = "All Notes";
    if (folderId === 0) folderName = "Unsorted";
    else if (folderId && typeof so_folders !== "undefined") {
        const f = so_folders.find(x => x.id == folderId);
        if (f) folderName = f.name;
    }

    const loc = {
        name: name || defaultName,
        folder_id: folderId,
        folder_name: folderName,
        scroll_top: scrollEl ? scrollEl.scrollTop : 0,
        timestamp: Date.now()
    };

        try {
            await window.sui.api("lh_save", { location: loc }, { toast: "Location Saved" });
        } catch(e) { window.openConfirm("Error", "Save failed", null, false, "OK", null); }
    });
}

function lhApplyLocation(loc) {
    // Sync mainFilterState if restoring a 'Main' view (All, Unsorted, or Archived)
    if (typeof mainFilterState !== 'undefined' && typeof so_folders !== 'undefined') {
        const archiveFolder = so_folders.find(f => f.name.toLowerCase() === "archived");
        const archiveId = archiveFolder ? parseInt(archiveFolder.id) : -99;

        // If the restored folder matches one of the toggle identities, update the toggle state
        if (loc.folder_id === null || loc.folder_id === 0 || loc.folder_id === archiveId) {
            mainFilterState = loc.folder_id;
            localStorage.setItem("cjos_folder_main_state", mainFilterState);
        }
    }

    // 1. Switch Folder first
    if (typeof setFolderFilter === "function") {
        setFolderFilter(loc.folder_id);
    }

    // 2. Wait for DOM to render (Crucial for scroll accuracy)
    // We use a delay to allow SmartOrganizer and VirtualScrolling to finish rendering the list
    setTimeout(() => {
        const scrollEl = document.getElementById("main-scroll");
        if (scrollEl) {
            scrollEl.scrollTo({
                top: loc.scroll_top,
                behavior: "smooth"
            });
            
            // Visual Feedback: Flash the scroll area slightly
            scrollEl.style.transition = "background-color 0.5s";
            scrollEl.style.backgroundColor = "rgba(0, 122, 255, 0.05)";
            setTimeout(() => { scrollEl.style.backgroundColor = ""; }, 600);
        }
    }, 400);
}

window.lhDeleteSingle = async function(idx) {
    window.openConfirm("Delete Location", "Delete this location?", async () => {
        await window.sui.api("lh_delete_single", { index: idx }, { toast: false });
        if (typeof closeSharedPicker === "function") closeSharedPicker();
        setTimeout(lhOpenMenu, 300);
    }, true);
};

window.lhClearHistory = async function() {
    window.openConfirm("Clear History", "Delete all saved locations?", async () => {
        await window.sui.api("lh_clear", {}, { toast: "History Cleared" });
    }, true);
};

// --- AUTO-RESUME ENGINE ---
let lhSaveTimer = null;

// Throttled Scroll Tracker
function lhInitAutoTracker() {
    const scrollEl = document.getElementById("main-scroll");
    if (!scrollEl) return;

    scrollEl.addEventListener('scroll', () => {
        if (lhSaveTimer || document.hidden) return;
        lhSaveTimer = setTimeout(() => {
            const state = {
                folder_id: (typeof currentFolderId !== "undefined") ? currentFolderId : null,
                scroll_top: scrollEl.scrollTop,
                timestamp: Date.now()
            };
            localStorage.setItem('cjos_lh_auto_resume', JSON.stringify(state));
            lhSaveTimer = null;
        }, 500); // Save every 500ms during active scrolling
    }, { passive: true });
}

// Automatic Restoration on Load
async function lhAutoRestore() {
    const saved = localStorage.getItem('cjos_lh_auto_resume');
    if (!saved) return;

    try {
        const state = JSON.parse(saved);
        // Only restore if the data is fresh (within last 2 hours) or we are in a dev session
        const isFresh = (Date.now() - state.timestamp) < (1000 * 60 * 60 * 2);
        
        if (isFresh) {
            console.log("[LH] Auto-resuming last position...");
            // Reuse existing apply logic
            lhApplyLocation(state);
        }
    } catch(e) { console.warn("[LH] Auto-resume failed", e); }
}

window.addEventListener('load', () => {
    lhInitAutoTracker();
    // Delay restoration slightly to allow LiveSync and VirtualScrolling to initialize
    setTimeout(lhAutoRestore, 600);
});
JS;
?>