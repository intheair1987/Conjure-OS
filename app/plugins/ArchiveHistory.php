<?php
// ==============================================================================
// PLUGIN: Archive History
// DESCRIPTION: Sequence of Archived Notes.
// Tracks the sequence of entries moved to the Archived folder.
// Stores data in a standalone JSON file to avoid database contamination.
// ==============================================================================

$ah_data_file = CJOS_PATH_DATA . '/archive-history-private.json';
$ah_config_file = CJOS_PATH_DATA . '/archive-history-config.json';

// --- 1. BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    
    // GET CONFIG
    if ($_POST['plugin_action'] === 'ah_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $conf = ['show_bar_btn' => true, 'show_float_btn' => true];
        if (file_exists($ah_config_file)) $conf = json_decode(file_get_contents($ah_config_file), true);
        echo json_encode(['status' => 'success', 'config' => $conf]);
        exit;
    }

    // SAVE CONFIG
    if ($_POST['plugin_action'] === 'ah_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $conf = [
            'show_bar_btn' => ($_POST['show_bar_btn'] === 'true'),
            'show_float_btn' => ($_POST['show_float_btn'] === 'true')
        ];
        file_put_contents($ah_config_file, json_encode($conf));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // SAVE TO HISTORY
    if ($_POST['plugin_action'] === 'ah_save') {
        error_reporting(0); while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $newIds = json_decode($_POST['ids'], true);
        $history = file_exists($ah_data_file) ? json_decode(file_get_contents($ah_data_file), true) : [];
        
        $now = time();
        foreach ($newIds as $id) {
            // Find transcription snippet from POST (to avoid DB lookup)
            $snippet = $_POST['snippet_' . $id] ?? 'Unknown Entry';
            
            array_unshift($history, [
                'id' => $id,
                'archived_at' => $now,
                'text' => mb_strimwidth($snippet, 0, 100, "...")
            ]);
        }
        
        // Keep only last 50 entries
        $history = array_slice($history, 0, 50);
        
        if (!is_dir(dirname($ah_data_file))) mkdir(dirname($ah_data_file), 0777, true);
        file_put_contents($ah_data_file, json_encode($history, JSON_PRETTY_PRINT));
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET HISTORY
    if ($_POST['plugin_action'] === 'ah_get') {
        error_reporting(0); while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $history = file_exists($ah_data_file) ? json_decode(file_get_contents($ah_data_file), true) : [];
        echo json_encode(['status' => 'success', 'history' => $history]);
        exit;
    }

    // CLEAR HISTORY
    if ($_POST['plugin_action'] === 'ah_clear') {
        if (file_exists($ah_data_file)) unlink($ah_data_file);
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- 2. SETTINGS UI ---
$plugin_settings_map['ArchiveHistory'] = <<<'HTML'
    <div style="padding: 16px 16px 8px 16px; font-size: 13px; line-height: 1.4; color: var(--text-secondary); font-style: italic; opacity: 0.8;">
        Handy for when you forget what you just worked on. This tracks the sequence of notes moved to the archive regardless of their original creation date.
    </div>

    <div data-sui-setting="Show in Selection Bar" data-sui-desc="Clock icon in bottom bar during selection." data-sui-id="ah-bar-toggle" data-sui-onchange="ahSaveConfig()"></div>
    <div data-sui-setting="Show in Archive Folder" data-sui-desc="Floating button in the Archived view." data-sui-id="ah-float-toggle" data-sui-onchange="ahSaveConfig()"></div>

    <div class="setting-item vertical">
        <label class="setting-label">Archive Log</label>
        <div class="setting-desc">View the chronological history of archived notes.</div>
        <div style="display:flex; gap:10px; margin-top:4px;">
            <button onclick="ahOpenHistoryMenu()" class="text-btn" style="flex:1; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:12px; font-weight:600; color:var(--text-primary);">View History Log</button>
            <button onclick="ahClearHistory()" class="text-btn" style="flex:1; background:#FFE5E5; color:var(--danger); border-radius:12px; padding:12px; font-weight:600;">Clear Log</button>
        </div>
    </div>
HTML;

// --- 3. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- ARCHIVE HISTORY JS ---

let ahConfig = { show_bar_btn: true, show_float_btn: true };

window.addEventListener("load", async () => {
    await ahLoadConfig();
    // Inject History Button into the selection bottom bar
    setTimeout(ahInjectButton, 400);
});

async function ahLoadConfig() {
    try {
        const data = await window.sui.api("ah_get_config", {}, { toast: false });
        if (data) {
            ahConfig = data.config;
            if(document.getElementById("ah-bar-toggle")) document.getElementById("ah-bar-toggle").checked = ahConfig.show_bar_btn;
            if(document.getElementById("ah-float-toggle")) document.getElementById("ah-float-toggle").checked = ahConfig.show_float_btn;
        }
    } catch(e) {}
}

window.ahSaveConfig = async function() {
    ahConfig.show_bar_btn = document.getElementById("ah-bar-toggle").checked;
    ahConfig.show_float_btn = document.getElementById("ah-float-toggle").checked;
    
    await window.sui.api("ah_save_config", {
        show_bar_btn: ahConfig.show_bar_btn,
        show_float_btn: ahConfig.show_float_btn
    }, { toast: false });
    
    // Update UI immediately
    if (!ahConfig.show_bar_btn) {
        const btn = document.getElementById("action-archive-history");
        if (btn) btn.remove();
    } else {
        ahInjectButton();
    }
    ahUpdateFloatingButton();
};

function ahInjectButton() {
    if (!ahConfig.show_bar_btn) return;
    const bar = document.querySelector(".selection-bottom-bar");
    const scrollCont = document.querySelector(".sb-scroll-container");
    const target = scrollCont || bar;

    if (target && !document.getElementById("action-archive-history")) {
        const btn = document.createElement("button");
        btn.className = "bar-action-btn";
        btn.id = "action-archive-history";
        btn.title = "Archive History";
        
        // Icon: Clock / History
        btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>`;
        
        btn.onclick = ahOpenHistoryMenu;
        
        // Just append. ScrollableActionBar will handle the sorting.
        target.appendChild(btn);
    }
}

window.ahOpenHistoryMenu = async function() {
    try {
        const data = await window.sui.api("ah_get", {}, { toast: false });
        
        if (!data.history || data.history.length === 0) {
            window.openConfirm("History Empty", "No recent archive history found.", null, false, "OK", null);
            return;
        }

        const options = data.history.map(item => {
            const time = new Date(item.archived_at * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            return {
                label: `<div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
                            <div style="display:flex; flex-direction:column; gap:2px; text-align:left; flex:1; min-width:0;">
                                <div style="font-size:10px; font-weight:800; color:var(--primary); text-transform:uppercase; opacity:0.8;">Archived at ${time}</div>
                                <div style="font-size:14px; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${item.text}</div>
                            </div>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:14px; height:14px; color:var(--text-secondary); opacity:0.25; margin-left:12px; flex-shrink:0;"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </div>`,
                value: item.id
            };
        });

        if (window.openPicker) {
            window.openPicker("Recently Archived", options, null, (logId) => {
                // 1. Exit selection mode to view the card
if (typeof cjosToggleSelectMode === "function") cjosToggleSelectMode(false);// 2. Trigger the jump
                ahJumpToLog(logId);
            });
        }
    } catch(e) { window.openConfirm("Error", "Error loading history", null, false, "OK", null); }
};

// GLOBAL HOOK: To be called by SmartOrganizer
window.logArchiveHistory = async function(ids) {
    const data = { ids: ids };
    // Attach snippets for the history list
    ids.forEach(id => {
        const entry = logs.find(l => l.id === id);
        if (entry) {
            data["snippet_" + id] = entry.transcription;
        }
    });

    try {
        await window.sui.api("ah_save", data, { toast: false });
    } catch(e) { console.error("Archive history log failed", e); }
};

function ahJumpToLog(logId) {
    // 1. Switch to Stream Page
    const viewport = document.querySelector(".horizontal-viewport");
    if(viewport) viewport.scrollTo({ left: 0, behavior: "smooth" });

    // 2. Resolve and Switch Folder
    // We look up the folder ID from the SmartOrganizer map
    const folderId = (typeof so_map !== "undefined") ? so_map[logId] : null;

    // Sync main toggle chip state if jumping to the Archived folder
    if (typeof mainFilterState !== 'undefined' && typeof so_folders !== 'undefined') {
        const archiveFolder = so_folders.find(f => f.name.toLowerCase() === "archived");
        const archiveId = archiveFolder ? parseInt(archiveFolder.id) : -99;
        if (folderId == archiveId) {
            mainFilterState = archiveId;
            localStorage.setItem("cjos_folder_main_state", mainFilterState);
        }
    }

    if (folderId !== null && typeof setFolderFilter === "function") {
        setFolderFilter(folderId);
    } else if (typeof setFolderFilter === "function") {
        // Fallback: Try to find the Archived folder explicitly if the map lookup fails
        const archiveFolder = (typeof so_folders !== "undefined") ? so_folders.find(f => f.name.toLowerCase() === "archived") : null;
        if (archiveFolder) setFolderFilter(archiveFolder.id);
    }

    // 3. Wait for DOM to render folder contents, then scroll
    setTimeout(() => {
        const cb = document.querySelector(`.custom-checkbox[data-id="${logId}"]`);
        if (cb) {
            const card = cb.closest(".card");
            card.scrollIntoView({ behavior: "smooth", block: "center" });
            
            // Visual Feedback (Uses Core ripple class)
            card.classList.add("jump-highlight");
            // Keep class for 4 seconds to allow animation to complete
            setTimeout(() => card.classList.remove("jump-highlight"), 4000);
        }
    }, 450);
}

window.ahClearHistory = async function() {
    window.openConfirm("Clear History", "Clear your recent archive history?", async () => {
        await window.sui.api("ah_clear", {}, { toast: "History Cleared" });
    }, true);
};

// --- FLOATING HISTORY BUTTON LOGIC ---

// runMasterFilter hijack removed. ahUpdateFloatingButton is managed by interval and refresh hooks.

function ahUpdateFloatingButton() {
    let btn = document.getElementById("ah-floating-history-btn");
    
    if (!ahConfig.show_float_btn || typeof so_folders === "undefined" || typeof currentFolderId === "undefined") {
        if (btn) btn.style.display = "none";
        return;
    }

    const archiveFolder = so_folders.find(f => f.name.toLowerCase() === "archived");
    const isArchivedFolder = archiveFolder && currentFolderId == archiveFolder.id;

    if (!isArchivedFolder) {
        if (btn) btn.style.display = "none";
        return;
    }

    if (!btn) {
        btn = document.createElement("button");
        btn.id = "ah-floating-history-btn";
        btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> History`;
        
        btn.style.cssText = `
            position: absolute;
            right: 18px;
            background: var(--bg-color);
            color: #8E8E93;
            border: 1px dotted #C7C7CC;
            padding: 4px 10px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            z-index: 1100; /* Above Top Bar (1000) and Sticky Headers (90) */
            transition: opacity 0.3s, transform 0.2s, top 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        `;
        
        btn.querySelector("svg").style.width = "12px";
        btn.querySelector("svg").style.height = "12px";
        btn.onclick = (e) => { e.stopPropagation(); ahOpenHistoryMenu(); };

        const firstPage = document.querySelector(".page-view");
        if (firstPage) firstPage.appendChild(btn);
    }

    btn.style.display = "flex";
    
    const isCollapsed = document.body.classList.contains("header-collapsed");
    const isOrgOpen = document.body.classList.contains("organizer-open");
    const searchActive = (typeof isSearchOpen !== "undefined" && isSearchOpen);
    
    const baseH = isCollapsed ? 44 : 64;
    const orgH = isOrgOpen ? (searchActive ? 100 : 52) : 0;
    
    // Calculate precise top position to sit right at the seam of the header/organizer
    btn.style.top = `calc(${baseH}px + var(--inner-padding-top) + ${orgH}px + 6px)`;
}

// Dynamic Layout Observer (Battery-Friendly Event-Driven Repositioning)
if (typeof MutationObserver !== "undefined") {
    const ahObserver = new MutationObserver((mutations) => {
        if (document.hidden) return;
        for (const m of mutations) {
            if (m.attributeName === 'class') {
                ahUpdateFloatingButton();
                break;
            }
        }
    });
    ahObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
}

// Global UI Hook Integration
if (typeof registerRefreshHook === "function") registerRefreshHook(ahUpdateFloatingButton);
if (typeof registerUpdateHook === "function") registerUpdateHook(ahUpdateFloatingButton);

// Extremely passive, battery-friendly safety interval
setInterval(() => {
    if (document.hidden) return;
    if (typeof so_folders === "undefined" || typeof currentFolderId === "undefined") return;
    const archiveFolder = so_folders.find(f => f.name.toLowerCase() === "archived");
    if (!archiveFolder || currentFolderId != archiveFolder.id) return;
    ahUpdateFloatingButton();
}, 4000);
JS;