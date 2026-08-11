<?php
// ==============================================================================
// PLUGIN: Smart Organizer
// DESCRIPTION: Folders & Search.
// ARCHITECTURE: Data Bridge (Zero-Escaping)
// ==============================================================================

$so_config_file = CJOS_PATH_DATA . '/smart-organizer-config.json';
$so_config = ['show_btn' => true];
if (file_exists($so_config_file)) {
    $loaded = json_decode(file_get_contents($so_config_file), true);
    if(is_array($loaded)) $so_config = array_merge($so_config, $loaded);
}

// ------------------------------------------------------------------------------
// 1. DATABASE SETUP & HELPER
// ------------------------------------------------------------------------------
try {
    // Maintenance: Clean up orphaned mappings
    $db->exec("DELETE FROM folder_map WHERE log_id NOT IN (SELECT id FROM logs)");

    $db->exec("CREATE TABLE IF NOT EXISTS folders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        is_pinned INTEGER DEFAULT 0,
        created_at INTEGER,
        updated_at INTEGER
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS folder_map (
        log_id TEXT PRIMARY KEY,
        folder_id INTEGER,
        FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS smart_folders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        query TEXT,
        created_at INTEGER
    )");
} catch (Exception $e) {}

/**
 * Cleanly sends JSON and exits to prevent HTML contamination.
 */
if (!function_exists('so_send_json')) {
    function so_send_json($data) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

// ------------------------------------------------------------------------------
// 2. API HANDLERS
// ------------------------------------------------------------------------------
if (isset($_POST['plugin_action'])) {

    if ($_POST['plugin_action'] === 'so_save_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $settings = json_decode($_POST['settings'], true);
        if (!is_dir(CJOS_PATH_DATA)) mkdir(CJOS_PATH_DATA, 0777, true);
        file_put_contents($so_config_file, json_encode($settings, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($_POST['plugin_action'] === 'so_get_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $config = ['show_btn' => true];
        if (file_exists($so_config_file)) {
            $loaded = json_decode(file_get_contents($so_config_file), true);
            if(is_array($loaded)) $config = array_merge($config, $loaded);
        }
        echo json_encode(['status' => 'success', 'config' => $config]);
        exit;
    }
    
    if ($_POST['plugin_action'] === 'folder_create') {
        $name = trim($_POST['name']);
        if($name) {
            $now = time();
            $stmt = $db->prepare("INSERT INTO folders (name, created_at, updated_at) VALUES (?, ?, ?)");
            $stmt->execute([$name, $now, $now]);
            so_send_json(['status' => 'success', 'id' => $db->lastInsertId(), 'name' => $name, 'updated_at' => $now]);
        }
        so_send_json(['status' => 'error', 'message' => 'Invalid name']);
    }

    if ($_POST['plugin_action'] === 'folder_rename') {
        $id = $_POST['id'];
        $name = trim($_POST['name']);
        if($name) {
            $db->prepare("UPDATE folders SET name = ?, updated_at = ? WHERE id = ?")->execute([$name, time(), $id]);
            so_send_json(['status' => 'success']);
        }
        so_send_json(['status' => 'error']);
    }

    if ($_POST['plugin_action'] === 'folder_delete') {
        $id = $_POST['id'];
        $db->prepare("DELETE FROM folders WHERE id = ?")->execute([$id]);
        $db->prepare("DELETE FROM folder_map WHERE folder_id = ?")->execute([$id]);
        so_send_json(['status' => 'success']);
    }

    if ($_POST['plugin_action'] === 'folder_toggle_pin') {
        $id = $_POST['id'];
        $val = $_POST['state']; 
        $db->prepare("UPDATE folders SET is_pinned = ? WHERE id = ?")->execute([$val, $id]);
        so_send_json(['status' => 'success']);
    }

    if ($_POST['plugin_action'] === 'folder_assign') {
        $folderId = $_POST['folder_id'];
        $logIds = json_decode($_POST['log_ids'], true);
        $db->beginTransaction();
        $del = $db->prepare("DELETE FROM folder_map WHERE log_id = ?");
        $ins = $db->prepare("INSERT INTO folder_map (log_id, folder_id) VALUES (?, ?)");
        $updateTs = $db->prepare("UPDATE folders SET updated_at = ? WHERE id = ?");
        $now = time();
        foreach ($logIds as $lid) {
            $del->execute([$lid]);
            if ($folderId && $folderId != 0) {
                $ins->execute([$lid, $folderId]);
                $updateTs->execute([$now, $folderId]);
            }
        }
        $db->commit();
        so_send_json(['status' => 'success']);
    }

    if ($_POST['plugin_action'] === 'smart_search_save') {
        $query = trim($_POST['query'] ?? '');
        if($query) {
            $stmt = $db->prepare("INSERT INTO smart_folders (query, created_at) VALUES (?, ?)");
            $stmt->execute([$query, time()]);
            so_send_json(['status' => 'success', 'id' => $db->lastInsertId(), 'query' => $query]);
        }
        so_send_json(['status' => 'error']);
    }

    if ($_POST['plugin_action'] === 'smart_search_delete') {
        $id = $_POST['id'];
        $db->prepare("DELETE FROM smart_folders WHERE id = ?")->execute([$id]);
        so_send_json(['status' => 'success']);
    }
}

// ------------------------------------------------------------------------------
// 3. DATA PREPARATION (The Bridge)
// ------------------------------------------------------------------------------
$folders = $db->query("SELECT * FROM folders ORDER BY is_pinned DESC, updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$maps = $db->query("SELECT * FROM folder_map")->fetchAll(PDO::FETCH_ASSOC);
$jsMap = [];
foreach($maps as $m) $jsMap[$m['log_id']] = $m['folder_id'];
$smart_folders = $db->query("SELECT * FROM smart_folders ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$bridgeData = [
    'folders' => $folders,
    'map' => $jsMap,
    'smart' => $smart_folders
];

// ------------------------------------------------------------------------------
// 4. UI COMPONENTS
// ------------------------------------------------------------------------------

$showBtn = $so_config['show_btn'] ?? true;
if ($showBtn === true || $showBtn === 'true' || $showBtn === 1 || $showBtn === '1') {
    $plugin_buttons[] = ['organizer-btn', '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>', 'toggleOrganizerBar()', 'Organizer', 'secondary'];
}

// SETTINGS UI
$plugin_settings_map['SmartOrganizer'] = <<<'HTML'
    <div data-sui-setting="Show Header Button" data-sui-desc="Add a folder icon to the top action bar to toggle the organizer." data-sui-id="plug-so-btn-toggle" data-sui-onchange="toggleSmartOrganizerSetting('show_btn', this.checked)"></div>
    <div id="so-save-status" style="text-align:right; font-size:11px; color:#8E8E93; padding:0 16px 8px;"></div>
HTML;

if(!isset($plugin_overlays)) $plugin_overlays = [];

// DATA BRIDGE SCRIPT (Hidden JSON)
$plugin_overlays[] = '<script id="so-data-bridge" type="application/json">' . json_encode($bridgeData) . '</script>';

// CONFIG BRIDGE
$so_bridge_json = json_encode($so_config);
$plugin_js .= "\nwindow.__SO_BRIDGE__ = $so_bridge_json;\n";

// ORGANIZER BAR
$plugin_overlays[] = <<<'HTML'
<div id="organizer-bar-wrapper" style="display:none;">
    <div style="display:flex; align-items:center; height:52px; padding: 10px 0 0 16px; gap:8px; box-sizing: border-box; border-bottom:1px solid var(--border-color);">
        <button onclick="openFolderManager()" title="Manage Folders" style="width:28px; height:28px; flex-shrink:0; border-radius:6px; background: var(--btn-bg); border:none; color:var(--text-primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
            <span data-sui-icon="folder" data-sui-size="16"></span>
        </button>
        <button id="btn-toggle-search-ui" onclick="toggleSearchUI()" title="Search" style="width:28px; height:28px; flex-shrink:0; border-radius:6px; background: var(--btn-bg); border:none; color:var(--text-primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
            <span data-sui-icon="search" data-sui-size="16" data-sui-stroke="2.5"></span>
        </button>
        <button id="folder-bar-move-btn" onclick="openMoveModal()" title="Move Selected" style="display:none; width:28px; height:28px; flex-shrink:0; border-radius:6px; background: var(--btn-bg); border:none; color:var(--text-primary); align-items:center; justify-content:center; cursor:pointer;">
            <span data-sui-icon="folder" data-sui-size="18"></span>
        </button>
        <div style="width:1px; height:20px; background:var(--border-color); margin:0 4px;"></div>
        <div id="organizer-chips-scroll" style="flex:1; display:flex; gap:6px; overflow-x:auto; align-items:center; height:100%; padding-right: 20px; -ms-overflow-style: none; scrollbar-width: none;"></div>
    </div>
    <div id="organizer-search-row" style="height: 0; overflow: hidden; transition: height 0.3s cubic-bezier(0.16, 1, 0.3, 1); display:flex; align-items:center; padding: 4px 16px 0 16px; background:var(--bg-color); box-sizing: border-box;">
        <style>
            #smart-search-input::placeholder { font-size: 12px; opacity: 0.5; font-weight: 400; }
        </style>
        <div style="flex:1; display:flex; align-items:center; background:var(--input-bg); border-radius:10px; padding:3px 8px 3px 12px; border:1px solid var(--border-color); height:32px;">
            <span data-sui-icon="search" data-sui-color="var(--text-secondary)" data-sui-size="14" style="margin-right:8px; display:flex;"></span>
            <input type="text" id="smart-search-input" placeholder="Search..." style="border:none; background:transparent; font-size:13px; width:100%; outline:none; padding:0 4px; color:var(--input-text);">
            <button id="btn-clear-search" style="background:none; border:none; color:var(--text-secondary); display:none; cursor:pointer; padding:4px;"><svg viewBox="0 0 24 24" fill="currentColor" style="width:14px; height:14px; stroke:none;"><path d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/></svg></button>
            <button id="btn-delete-smart-search" style="background:none; border:none; color:var(--danger); display:none; cursor:pointer; padding:4px; margin-left:4px; border-left:1px solid var(--border-color); padding-left:8px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:14px; height:14px; stroke-width:2;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
        </div>
        <button id="btn-save-search" style="margin-left:10px; font-size:13px; font-weight:600; color:var(--primary); background:none; border:none; cursor:pointer; white-space:nowrap; display:none;">Save</button>
    </div>
    <div id="so-label-filter-tray" style="display:none; overflow-x:auto; padding: 8px 16px; gap:8px; border-top: 1px solid var(--border-color); background:var(--bg-color); -ms-overflow-style:none; scrollbar-width:none; align-items:center;"></div>
</div>
HTML;



// ------------------------------------------------------------------------------
// 5. JAVASCRIPT LOGIC
// ------------------------------------------------------------------------------
$plugin_js .= <<<'JS'

// --- SMART ORGANIZER JS (DATA BRIDGE) ---

// 1. Initialize State from Bridge
const soBridge = JSON.parse(document.getElementById('so-data-bridge').textContent);
let so_folders = soBridge.folders;
let so_map = soBridge.map;
let so_smart = soBridge.smart;

let soState = {
    show_btn: true
};

async function fetchSoSettings() {
    if (window.__SO_BRIDGE__) {
        soState = window.__SO_BRIDGE__;
    }
    try {
        const data = await window.sui.api("so_get_config", {}, { toast: false });
        if (data && data.config) {
            soState = data.config;
            localStorage.setItem("cjos_so_show_btn", soState.show_btn);
        }
        
        const btnToggle = document.getElementById("plug-so-btn-toggle");
        if (btnToggle) btnToggle.checked = soState.show_btn;
    } catch(e) { console.error("SO Config Error", e); }
}

async function saveSoSettings() {
    const status = document.getElementById("so-save-status");
    if(status) status.innerText = "Saving...";
    localStorage.setItem("cjos_so_show_btn", soState.show_btn);
    try {
        await window.sui.api("so_save_config", { settings: soState }, { toast: false });
        if(status) {
            status.innerText = "Saved to data/";
            setTimeout(() => status.innerText = "", 2000);
        }
    } catch(e) {
        if(status) status.innerText = "Error saving";
    }
}

window.toggleSmartOrganizerSetting = async function(key, val) {
    soState[key] = val;
    await saveSoSettings();
    if(key === "show_btn") location.reload();
};

let currentFolderId = null; 
let currentSmartId = null;  
let activeLabelFilter = null;
var showBroadLabels = false;
let searchQuery = "";       
let isBarOpen = false;
let isSearchOpen = false;
let isFolderManagerEditing = false;
let isMoveMode = false;
let soOnPickCallback = null;
let folderSortMode = localStorage.getItem("cjos_so_sort_mode") || 'updated';

let mainFilterState = localStorage.getItem("cjos_folder_main_state");
if (mainFilterState === "null" || mainFilterState === null) {
    mainFilterState = null;
} else {
    mainFilterState = parseInt(mainFilterState);
}

let toggleMemory = localStorage.getItem("cjos_folder_toggle_memory");
toggleMemory = (toggleMemory === null) ? 0 : parseInt(toggleMemory);

const BAR_H = 52; 
const SEARCH_H = 48; 
const TRAY_H = 44;

window.addEventListener("load", () => {
    fetchSoSettings();
    if (window.sui && window.sui.registerBadge) {
        window.sui.registerBadge("folder-badge", (entry, card) => {
            const folderId = so_map[entry.id];
            if (!folderId) return null;
            const folder = so_folders.find(f => f.id == folderId);
            if (!folder) return null;
            return window.suiBadge("📂 " + folder.name, "folder");
        }, 20);
    }

    const style = document.createElement("style");
    style.innerHTML = `
        #organizer-bar-wrapper {
            position: absolute;
            top: calc(var(--header-base-height) + var(--inner-padding-top) + 1px);
            left: 0; right: 0; height: 0;
            overflow: hidden; background: var(--bg-color); z-index: 900;
            transition: top 0.4s cubic-bezier(0.16, 1, 0.3, 1), height 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            -webkit-mask-image: linear-gradient(to bottom, transparent 0px, black 12px, black 100%);
        }
        body.header-collapsed #organizer-bar-wrapper { top: calc(var(--header-collapsed-height) + var(--inner-padding-top) + 1px); }
        .scroll-view { transition: padding-top 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        .section-header { padding-top: 24px !important; padding-bottom: 8px !important; }
        body.organizer-open .section-header { padding-top: 10px !important; }
        #organizer-btn { position: relative; overflow: visible !important; }
        .org-filter-dot {
            position: absolute; top: 4px; right: 6px; width: 8px; height: 8px;
            background-color: #FF3B30; border-radius: 50%;
            border: 2px solid var(--header-bg, #F2F2F7); display: none; z-index: 10; pointer-events: none;
        }
        .org-filter-dot.visible { display: block; }
        .org-chip { 
            padding: 5px 12px; border-radius: 14px; font-size: 13px; font-weight: 500; 
            white-space: nowrap; cursor: pointer; transition: all 0.2s; 
            border: 1px solid var(--border-color); background-color: var(--input-bg); color: var(--input-text);
            display: inline-flex; flex-direction: column; align-items: center; justify-content: center;
        }
        .org-chip::after {
            display: block; content: attr(data-text); font-weight: 600; height: 0; overflow: hidden; visibility: hidden;
        }
        .org-chip.pinned { border-color: var(--primary); background-color: var(--card-bg); }
        .org-chip.folder-active,
        .org-chip.smart-active { 
            background-color: var(--primary) !important; 
            color: var(--primary-text) !important; 
            font-weight: 700; 
            border: none !important;
            filter: invert(0.12) brightness(1.1) saturate(1.2);
            box-shadow: 0 0 8px color-mix(in srgb, var(--primary), transparent 60%) !important;
        }
        .org-chip.smart-chip { color: var(--ai-accent); background: var(--ai-accent-bg); border-color: var(--border-color); }
        .card.search-hidden { display: none !important; }
        mark { background: #FFE600; color: black; border-radius: 2px; padding: 0 2px; }
        .org-filter-sep { 
            width: 1px; height: 20px; background: var(--border-color); 
            margin: 0 4px; flex-shrink: 0; display: inline-block;
        }
    `;
    document.head.appendChild(style);

    const bar = document.getElementById("organizer-bar-wrapper");
    const firstPage = document.querySelector(".horizontal-viewport .page-view");
    if(bar && firstPage) { 
        bar.style.display = "block"; 
        firstPage.insertBefore(bar, firstPage.firstChild); 
    }

    const savedF = localStorage.getItem("cjos_so_fid");
    currentFolderId = (savedF === "null" || savedF === null) ? null : parseInt(savedF);
    
    if (currentFolderId !== null && currentFolderId !== 0) {
        const isValid = so_folders.some(f => f.id == currentFolderId);
        if (!isValid) {
            currentFolderId = 0;
            localStorage.setItem("cjos_so_fid", 0);
        }
    }

    const soOpenSetting = localStorage.getItem("cjos_so_open");
    if (soOpenSetting === "true" || soOpenSetting === null) {
        isBarOpen = true;
        document.body.classList.add("organizer-open");
        updateBarGeometry();
        if (typeof updateToggleBtnColor === "function") {
            updateToggleBtnColor();
        }
    }

    renderOrganizerBar();
    renderFolderBadges();
    refreshFolderView(); 
    setTimeout(scrollToActiveChip, 600);
    injectSelectionToggle();
    injectArchiveButton();

    const sInput = document.getElementById("smart-search-input");
    const clrBtn = document.getElementById("btn-clear-search");
    const saveBtn = document.getElementById("btn-save-search");
    const delBtn = document.getElementById("btn-delete-smart-search");

    sInput.addEventListener("input", (e) => {
        searchQuery = e.target.value;
        clrBtn.style.display = searchQuery ? "block" : "none";
        saveBtn.style.display = (searchQuery && !currentSmartId) ? "block" : "none";
        if (delBtn) delBtn.style.display = "none";
        refreshFolderView();
    });

    clrBtn.onclick = () => {
        sInput.value = ""; searchQuery = "";
        clrBtn.style.display = "none"; saveBtn.style.display = "none";
        if (delBtn) delBtn.style.display = "none";
        if(currentSmartId) { currentSmartId = null; renderOrganizerBar(); }
        refreshFolderView();
    };

    if (delBtn) {
        delBtn.onclick = () => {
            if (!currentSmartId) return;
            window.openConfirm("Delete Search", "Delete this saved search?", () => deleteSmartSearch(currentSmartId), true);
        };
    }

    if (window.registerCardPlugin) {
        window.registerCardPlugin(highlightSearchTerms, 55); 
    }

    if (window.registerRefreshHook) {
        window.registerRefreshHook(() => {
            renderFolderBadges();
            renderOrganizerBar();
        });
    }

    saveBtn.onclick = saveSmartSearch;
    if (window.registerRefreshHook) window.registerRefreshHook(renderOrganizerBar);
});

function scrollToActiveChip() {
    const cont = document.getElementById("organizer-chips-scroll");
    if (!cont) return;
    const active = cont.querySelector(".folder-active, .smart-active");
    if (active) {
        const contRect = cont.getBoundingClientRect();
        const activeRect = active.getBoundingClientRect();
        const centerDiff = (activeRect.left + activeRect.width / 2) - (contRect.left + contRect.width / 2);
        cont.scrollBy({ left: centerDiff, behavior: 'smooth' });
    }
}

window.refreshFolderView = function() {
    const term = searchQuery.toLowerCase().trim();
    const useSearch = term.length > 0;
    
    const filtered = logs.filter(l => {
        const fid = so_map[l.id];
        let fMatch = false;
        if (currentFolderId === null) fMatch = true;
        else if (currentFolderId === 0) fMatch = (fid == 0 || fid == null);
        else fMatch = (fid == currentFolderId);
        
        if (!fMatch) return false;
        if (activeLabelFilter && !logHasLabel(l, activeLabelFilter)) return false;
        if (useSearch) return (l.transcription || "").toLowerCase().includes(term);
        return true;
    });

    if (typeof renderStandardList === "function") {
        renderStandardList(filtered);
    }
    updateFilterIndicator();
};

function updateFilterIndicator() {
    const btn = document.getElementById("organizer-btn");
    if (!btn) return;
    let dot = btn.querySelector(".org-filter-dot");
    if (!dot) {
        dot = document.createElement("div");
        dot.className = "org-filter-dot";
        btn.appendChild(dot);
    }
    const isFiltered = (currentFolderId !== null) || (searchQuery && searchQuery.trim() !== "") || (activeLabelFilter !== null);
    dot.classList.toggle("visible", isFiltered);
}

function logHasLabel(l, labelId) {
    if (!window.sui || !window.sui.badges) return false;
    const targetIds = [labelId];
    if (labelId === "ai-badge") targetIds.push("ai-asst-badge");
    for (let i = 0; i < window.sui.badges.length; i++) {
        const p = window.sui.badges[i];
        if (targetIds.includes(p.id)) {
            try { if (p.render(l, null)) return true; } catch(e) {}
        }
    }
    return false;
}

window.highlightSearchTerms = function(card) {
    const term = searchQuery.trim();
    if (!term) return;
    const textDiv = card.querySelector(".transcription");
    if (textDiv && textDiv.textContent.toLowerCase().includes(term.toLowerCase())) {
        const regex = new RegExp("(" + term.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + ")", "gi");
        textDiv.innerHTML = textDiv.textContent.replace(regex, "<mark>$1</mark>");
    }
};

window.jumpToFolderAndHighlight = function(logId, folderId) {
    setFolderFilter(folderId);
    setTimeout(() => {
        const checkbox = document.querySelector(`.custom-checkbox[data-id="${logId}"]`);
        if (checkbox) {
            const card = checkbox.closest(".card");
            card.classList.remove("phantom-card");
            const actions = card.querySelector(".phantom-actions");
            if (actions) actions.remove();
            card.scrollIntoView({ behavior: "smooth", block: "center" });
            card.classList.add("jump-highlight");
            setTimeout(() => card.classList.remove("jump-highlight"), 1000);
        }
    }, 150);
};

window.applyFolderFilter = function(fid) { setFolderFilter(fid); };

window.renderOrganizerBar = function() {
    const cont = document.getElementById("organizer-chips-scroll");
    if(!cont) return;
    cont.innerHTML = "";

    const archiveFolder = so_folders.find(f => f.name.toLowerCase() === "archived");
    const archiveId = archiveFolder ? parseInt(archiveFolder.id) : -99;

    const getCount = (fid) => {
        if (fid === null) return logs.length;
        if (fid === 0) return logs.filter(l => !so_map[l.id] || so_map[l.id] == 0).length;
        return logs.filter(l => so_map[l.id] == fid).length;
    };

    const toggleChip = document.createElement("div");
    let label = "Unsorted";
    if (currentFolderId === null) label = "All";
    else if (currentFolderId === archiveId) label = "Archived";
    else if (currentFolderId !== 0) label = (toggleMemory === archiveId) ? "Archived" : "Unsorted";

    const isActive = (currentFolderId === null || currentFolderId === 0 || currentFolderId === archiveId);
    toggleChip.className = `org-chip ${isActive ? "folder-active" : ""}`;
    let displayLabel = label + (isActive ? ` (${getCount(currentFolderId)})` : "");
    toggleChip.innerText = displayLabel;
    toggleChip.setAttribute("data-text", displayLabel);
    
    let pressTimer;
    toggleChip.onclick = () => {
        if (pressTimer === null) return; // Prevent tap trigger after long press
        if (currentFolderId === null) setFolderFilter(toggleMemory);
        else if (currentFolderId === 0 || currentFolderId === archiveId) {
            const next = (currentFolderId === 0) ? archiveId : 0;
            toggleMemory = next;
            localStorage.setItem("cjos_folder_toggle_memory", next);
            setFolderFilter(next);
        } else {
            // Returning from a specific folder: always return directly to Unsorted (0)
            toggleMemory = 0;
            localStorage.setItem("cjos_folder_toggle_memory", 0);
            setFolderFilter(0);
        }
    };

    toggleChip.onmousedown = toggleChip.ontouchstart = () => {
        pressTimer = setTimeout(() => {
            pressTimer = null;
            setFolderFilter(currentFolderId === null ? toggleMemory : null);
            if (navigator.vibrate) navigator.vibrate(50);
        }, 600);
    };

    toggleChip.onmouseup = toggleChip.onmouseleave = toggleChip.ontouchend = () => {
        if (pressTimer) clearTimeout(pressTimer);
    };

    cont.appendChild(toggleChip);
    const sep = document.createElement("div");
    sep.style.cssText = "width:1px; height:16px; background:var(--border-color); margin:0;";
    cont.appendChild(sep);

    so_smart.forEach(sf => {
        const chip = document.createElement("div");
        chip.className = `org-chip smart-chip ${currentSmartId == sf.id ? "smart-active" : ""}`;
        chip.innerText = "🔍 " + sf.query;
        chip.onclick = () => activateSmartFolder(sf);
        cont.appendChild(chip);
    });

    const sorted = [...so_folders].sort((a, b) => {
        if (a.is_pinned !== b.is_pinned) return b.is_pinned - a.is_pinned;
        const tA = parseInt(a[folderSortMode === 'created' ? 'created_at' : 'updated_at']) || 0;
        const tB = parseInt(b[folderSortMode === 'created' ? 'created_at' : 'updated_at']) || 0;
        return tB - tA;
    });

    sorted.forEach(f => {
        if (f.name.toLowerCase() === "archived") return;
        const chip = document.createElement("div");
        const isThisActive = currentFolderId == f.id;
        chip.className = `org-chip ${f.is_pinned ? "pinned" : ""} ${isThisActive ? "folder-active" : ""}`;
        let fText = (f.is_pinned ? "📌 " : "📂 ") + f.name + (isThisActive ? ` (${getCount(f.id)})` : "");
        chip.innerText = fText;
        chip.onclick = () => { if (timer === null) return; setFolderFilter(f.id); };
        
        let timer = null;
        let startX, startY;

        const start = (e) => {
            const t = e.touches ? e.touches[0] : e;
            startX = t.clientX; startY = t.clientY;
            timer = setTimeout(() => {
                timer = null; // Mark as long-pressed
                if (window.sui && window.sui.haptic) window.sui.haptic('medium');
                openFolderActionPicker(f.id, f.name, f.is_pinned);
            }, 700);
        };

        const move = (e) => {
            if (!timer) return;
            const t = e.touches ? e.touches[0] : e;
            if (Math.abs(t.clientX - startX) > 10 || Math.abs(t.clientY - startY) > 10) {
                clearTimeout(timer); timer = -1; // Cancelled
            }
        };

        const end = () => { if (timer && timer !== -1) clearTimeout(timer); };

        chip.onmousedown = chip.ontouchstart = start;
        chip.onmousemove = chip.ontouchmove = move;
        chip.onmouseup = chip.onmouseleave = chip.ontouchend = end;

        cont.appendChild(chip);
    });
};

function renderFolderBadges() {
    if (window.sui && window.sui.decorateCard) {
        document.querySelectorAll(".card").forEach(card => {
             const id = card.querySelector(".custom-checkbox")?.getAttribute("data-id");
             const entry = logs.find(l => l.id === id);
             if (entry) window.sui.decorateCard(card, entry);
        });
    }
}

function renderLabelFilterTray() {
    const tray = document.getElementById("so-label-filter-tray");
    if (!tray) return;
    
    const labels = window.loKnownLabels ? Object.keys(window.loKnownLabels) : [];
    const order = (window.loSavedOrder && window.loSavedOrder.length > 0) ? [...window.loSavedOrder] : labels;

    if (order.length === 0 || !isSearchOpen) {
        tray.style.display = "none";
        return;
    }

    tray.innerHTML = "";
    const broadIds = ["wc-badge", "folder-badge"];
    
    // Ensure Processed filter is always available
    if (window.loKnownLabels && window.loKnownLabels["it-interacted-badge"] && order.indexOf("it-interacted-badge") === -1) {
        order.push("it-interacted-badge");
    }

    const essentials = order.filter(id => broadIds.indexOf(id) === -1);
    const broads = order.filter(id => broadIds.indexOf(id) !== -1);

    // 1. Render Essentials
    essentials.forEach(className => {
        const info = window.loKnownLabels[className] || { label: className };
        const chip = document.createElement("div");
        chip.className = "org-chip " + (activeLabelFilter === className ? "smart-active" : "");
        chip.style.fontSize = "11px"; chip.style.padding = "4px 10px";
        chip.innerText = info.label;
        chip.onclick = () => toggleLabelFilter(className);
        tray.appendChild(chip);
    });

    // 2. Handle Broad Section (Word Count, Folder Name)
    const activeIsBroad = broadIds.indexOf(activeLabelFilter) !== -1;

    if (showBroadLabels) {
        const sep = document.createElement("div"); sep.className = "org-filter-sep"; tray.appendChild(sep);
        broads.forEach(className => {
            const info = window.loKnownLabels[className] || { label: className };
            const chip = document.createElement("div");
            const isActive = (activeLabelFilter === className);
            chip.className = "org-chip " + (isActive ? "smart-active" : "");
            chip.style.fontSize = "11px"; chip.style.padding = "4px 10px";
            if (!isActive) chip.style.opacity = "0.7";
            chip.innerText = info.label;
            chip.onclick = () => toggleLabelFilter(className);
            tray.appendChild(chip);
        });
    } else if (activeIsBroad) {
        const info = window.loKnownLabels[activeLabelFilter] || { label: activeLabelFilter };
        const chip = document.createElement("div");
        chip.className = "org-chip smart-active";
        chip.style.fontSize = "11px"; chip.style.padding = "4px 10px";
        chip.innerText = info.label;
        chip.onclick = () => toggleLabelFilter(activeLabelFilter);
        tray.appendChild(chip);
    }

    const toggleChip = document.createElement("div");
    toggleChip.className = "org-chip"; 
    toggleChip.style.cssText = "font-size:10px; background:transparent; border:none; opacity:0.5; padding:4px 8px;";
    toggleChip.innerText = showBroadLabels ? "« Less" : "+ More";
    toggleChip.onclick = () => { 
        showBroadLabels = !showBroadLabels; 
        renderLabelFilterTray(); 
        updateBarGeometry(); 
    };
    tray.appendChild(toggleChip);
    tray.style.display = "flex";
}

function toggleLabelFilter(className) {
    activeLabelFilter = (activeLabelFilter === className) ? null : className;
    renderLabelFilterTray();
    refreshFolderView();
    if (window.sui && window.sui.haptic) window.sui.haptic("light");
}

function toggleOrganizerBar() {
    isBarOpen = !isBarOpen;
    localStorage.setItem("cjos_so_open", isBarOpen);
    document.body.classList.toggle("organizer-open", isBarOpen);
    if(!isBarOpen && isSearchOpen) toggleSearchUI();
    updateBarGeometry();
    if (typeof updateToggleBtnColor === "function") {
        updateToggleBtnColor();
    }
}

function toggleSearchUI() {
    isSearchOpen = !isSearchOpen;
    if (!isSearchOpen) {
        activeLabelFilter = null; searchQuery = "";
        const inp = document.getElementById("smart-search-input");
        if (inp) inp.value = "";
        document.getElementById("btn-clear-search").style.display = "none";
        refreshFolderView();
    }
    renderLabelFilterTray();
    updateBarGeometry();
    const btn = document.getElementById("btn-toggle-search-ui");
    const row = document.getElementById("organizer-search-row");
    if(isSearchOpen) {
        btn.style.background = "var(--primary)"; btn.style.color = "var(--primary-text)"; row.style.padding = "4px 16px 0 16px";
        setTimeout(() => document.getElementById("smart-search-input").focus(), 100);
    } else {
        btn.style.background = "var(--btn-bg)"; btn.style.color = "var(--text-primary)"; row.style.padding = "0 16px";
    }
}

function updateBarGeometry() {
    const bar = document.getElementById("organizer-bar-wrapper");
    const searchRow = document.getElementById("organizer-search-row");
    const tray = document.getElementById("so-label-filter-tray");
    const sv = document.querySelector(".scroll-view");
    let totalH = 0;
    if (isBarOpen) {
        const hasTray = isSearchOpen && tray && tray.style.display !== "none";
        totalH = BAR_H + (isSearchOpen ? SEARCH_H : 0) + (hasTray ? TRAY_H : 0);
        bar.style.height = totalH + "px";
        searchRow.style.height = isSearchOpen ? SEARCH_H + "px" : "0px";
    } else bar.style.height = "0";
    if(sv) {
        const base = 64; 
        const inner = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--inner-padding-top")) || 0;
        sv.style.paddingTop = (base + inner + totalH) + "px";
    }
}

function setFolderFilter(id) {
    currentFolderId = id;
    localStorage.setItem("cjos_so_fid", id);
    mainFilterState = id;
    localStorage.setItem("cjos_folder_main_state", id);
    if (currentSmartId) currentSmartId = null;
    renderOrganizerBar();
    refreshFolderView();
    if (window.updateArchiveButtonState) updateArchiveButtonState();
    setTimeout(scrollToActiveChip, 100);
}

function activateSmartFolder(sf) {
    const delBtn = document.getElementById("btn-delete-smart-search");
    if(currentSmartId === sf.id) {
        currentSmartId = null; searchQuery = ""; document.getElementById("smart-search-input").value = "";
        document.getElementById("btn-clear-search").style.display = "none";
        if (delBtn) delBtn.style.display = "none";
    } else {
        currentSmartId = sf.id; searchQuery = sf.query;
        if(!isBarOpen) toggleOrganizerBar();
        if(!isSearchOpen) toggleSearchUI();
        document.getElementById("smart-search-input").value = sf.query;
        document.getElementById("btn-clear-search").style.display = "block";
        if (delBtn) delBtn.style.display = "block";
    }
    renderOrganizerBar();
    refreshFolderView();
    setTimeout(scrollToActiveChip, 100);
}

async function saveSmartSearch() {
    if(!searchQuery) return;
    const data = await window.sui.api("smart_search_save", { query: searchQuery }, { toast: "Search Query Saved" });
    so_smart.unshift({ id: data.id, query: data.query, created_at: Date.now()/1000 }); 
    activateSmartFolder(so_smart[0]);
}

async function deleteSmartSearch(id) {
    try {
        await window.sui.api("smart_search_delete", { id: id }, { toast: "Search Deleted" });
        so_smart = so_smart.filter(s => s.id != id);
        if(currentSmartId == id) { 
            currentSmartId = null; 
            searchQuery = ""; 
            const inp = document.getElementById("smart-search-input");
            if (inp) inp.value = "";
            const delBtn = document.getElementById("btn-delete-smart-search");
            if (delBtn) delBtn.style.display = "none";
            const clrBtn = document.getElementById("btn-clear-search");
            if (clrBtn) clrBtn.style.display = "none";
        }
        renderOrganizerBar();
        refreshFolderView();
    } catch(e) { console.error("Delete search failed", e); }
}

function openFolderManager(isMove = false, customTitle = null, onPick = null) {
    soOnPickCallback = onPick;
    isMoveMode = isMove; 
    isFolderManagerEditing = false; // Always reset edit mode

    const title = isMove ? (customTitle || "Add to folder...") : "Folders";

    window.sui.openStudio({
        id: 'folder-manager',
        title: title,
        content: `
            <div style="margin-bottom:16px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:6px; display:flex; gap:8px; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                <input type="text" id="new-folder-input" placeholder="New Folder Name..." style="flex:1; padding:8px 12px; border-radius:8px; border:none; background:transparent; font-size:15px; color:var(--text-primary); outline:none;">
                <button onclick="createNewFolder()" style="background:var(--primary); color:var(--primary-text); border:none; padding:0 16px; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px; transition:opacity 0.2s;">Add</button>
            </div>

            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; padding:0 4px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Sort:</span>
                    <button id="btn-sort-updated" onclick="setFolderSort('updated')" style="background:none; border:none; font-size:11px; font-weight:700; color:var(--primary); cursor:pointer; padding:2px; transition:opacity 0.2s;">Recent</button>
                    <button id="btn-sort-created" onclick="setFolderSort('created')" style="background:none; border:none; font-size:11px; font-weight:400; color:var(--text-secondary); cursor:pointer; padding:2px; transition:opacity 0.2s;">Created</button>
                </div>
                <button id="btn-toggle-folder-edit" onclick="toggleFolderManagerEdit()" style="background:var(--btn-bg); border:1px solid var(--border-color); font-size:11px; font-weight:600; color:var(--text-primary); cursor:pointer; padding:4px 10px; border-radius:6px; transition:background 0.2s;">Edit</button>
            </div>

            <div id="folder-manager-list" style="display:flex; flex-direction:column; gap:8px; padding-bottom:20px;"></div>
        `,
        onSetup: () => {
            renderFolderList();
            
            // Sync Sort Button Visuals
            const btnU = document.getElementById('btn-sort-updated');
            const btnC = document.getElementById('btn-sort-created');
            if(btnU && btnC) {
                const isUpd = folderSortMode === 'updated';
                btnU.style.color = isUpd ? 'var(--primary)' : 'var(--text-secondary)';
                btnU.style.fontWeight = isUpd ? '700' : '400';
                btnU.style.opacity = isUpd ? '1' : '0.6';
                
                btnC.style.color = !isUpd ? 'var(--primary)' : 'var(--text-secondary)';
                btnC.style.fontWeight = !isUpd ? '700' : '400';
                btnC.style.opacity = !isUpd ? '1' : '0.6';
            }
        },
        onClose: () => {
            isMoveMode = false;
            soOnPickCallback = null;
        }
    });
}

function closeFolderManager() { 
    window.sui.closeStudio('folder-manager'); 
}

function openMoveModal() {
    const sel = document.querySelectorAll(".custom-checkbox.checked"); 
    if(sel.length === 0) return;
    openFolderManager(true);
}

const _origToggle = window.cjosToggleSelectMode;
window.cjosToggleSelectMode = function(enable) {
    if(_origToggle) _origToggle(enable);
    const mb = document.getElementById("folder-bar-move-btn");
    if(mb) mb.style.display = enable ? "flex" : "none";
};

window.toggleFolderManagerEdit = function() {
    isFolderManagerEditing = !isFolderManagerEditing;
    const btn = document.getElementById("btn-toggle-folder-edit");
    if (btn) btn.innerText = isFolderManagerEditing ? "Done" : "Edit";
    renderFolderList();
};

window.setFolderSort = function(mode) {
    folderSortMode = mode;
    localStorage.setItem("cjos_so_sort_mode", mode);
    renderFolderList();
    renderOrganizerBar();
    const btnU = document.getElementById('btn-sort-updated');
    const btnC = document.getElementById('btn-sort-created');
    if(btnU) { btnU.style.opacity = mode === 'updated' ? '1' : '0.5'; btnU.style.fontWeight = mode === 'updated' ? '700' : '400'; }
    if(btnC) { btnC.style.opacity = mode === 'created' ? '1' : '0.5'; btnC.style.fontWeight = mode === 'created' ? '700' : '400'; }
};

function renderFolderList() {
    const list = document.getElementById("folder-manager-list");
    if (!list) return;
    list.innerHTML = "";
    const archiveFolder = so_folders.find(f => f.name.toLowerCase() === "archived");
    const archiveId = archiveFolder ? parseInt(archiveFolder.id) : -99;
    const systemViews = [{ id: null, name: "All Notes", icon: "🌐" }];
    if (archiveFolder) systemViews.push({ id: archiveId, name: "Archived", icon: "📦" });
    systemViews.push({ id: 0, name: "Unsorted", icon: "📥" });

    systemViews.forEach(v => {
        if (isMoveMode && v.id === null) return;
        const row = document.createElement("div");
        const isActive = (v.id === currentFolderId);
        row.style.cssText = `background:${isActive ? "var(--selected-bg)" : "var(--bg-color)"}; color:${isActive ? "var(--selected-text)" : "var(--text-primary)"}; padding:16px; border-radius:14px; display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; border:1px solid ${isActive ? "var(--primary)" : "var(--border-color)"}; cursor:pointer; transition: transform 0.1s;`;
        
        row.onmousedown = () => row.style.transform = "scale(0.98)";
        row.onmouseup = row.onmouseleave = () => row.style.transform = "scale(1)";

        row.onclick = () => {
            if (soOnPickCallback) soOnPickCallback(v.id || 0);
            else if (isMoveMode) assignToFolder(v.id || 0);
            else {
                setFolderFilter(v.id);
                const vp = document.querySelector(".horizontal-viewport");
                if(vp && vp.scrollLeft > 50) vp.scrollTo({ left: 0, behavior: "smooth" });
            }
            closeFolderManager();
        };
        row.innerHTML = `
            <div style="font-weight:700; display:flex; align-items:center; gap:10px;"><span style="font-size:18px;">${v.icon}</span> ${v.name}</div>
            <div style="font-size:10px; font-weight:800; text-transform:uppercase; opacity:0.5;">System</div>
        `;
        list.appendChild(row);
    });

    const sorted = [...so_folders].sort((a, b) => {
        if (a.is_pinned !== b.is_pinned) return b.is_pinned - a.is_pinned;
        const tA = parseInt(a[folderSortMode === 'created' ? 'created_at' : 'updated_at']) || 0;
        const tB = parseInt(b[folderSortMode === 'created' ? 'created_at' : 'updated_at']) || 0;
        return tB - tA;
    });

    sorted.forEach(f => {
        if (f.name.toLowerCase() === "archived") return;
        const row = document.createElement("div");
        const isActive = (f.id == currentFolderId);
        row.style.cssText = `background:${isActive ? "var(--selected-bg)" : "var(--card-bg)"}; padding:16px; border-radius:14px; display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; border:1px solid ${isActive ? "var(--primary)" : "var(--border-color)"}; cursor:pointer;`;
        
        row.onclick = () => {
            if (isFolderManagerEditing) return;
            if (soOnPickCallback) soOnPickCallback(f.id);
            else if (isMoveMode) assignToFolder(f.id);
            else setFolderFilter(f.id);
            closeFolderManager();
        };

        const left = document.createElement("div");
        const dateTs = parseInt(f[folderSortMode === 'created' ? 'created_at' : 'updated_at']) || 0;
        const dateStr = dateTs > 0 ? new Date(dateTs * 1000).toLocaleDateString() : 'N/A';
        
        left.innerHTML = `
            <div style="font-weight:600;">${(f.is_pinned ? "📌 " : "") + f.name}</div>
            <div style="font-size:11px; color:var(--text-secondary); margin-top:2px;">
                ${folderSortMode === 'created' ? 'Created' : 'Updated'}: ${dateStr}
            </div>
        `;
        row.appendChild(left);

        if (isFolderManagerEditing) {
            const acts = document.createElement("div");
            acts.style.display = "flex"; acts.style.gap = "8px";
            
            const btnRen = document.createElement("button");
            btnRen.innerText = "Rename";
            btnRen.style.cssText = "background:var(--btn-bg); color:var(--text-primary); border:none; padding:6px 10px; border-radius:8px; font-size:11px; font-weight:700;";
            btnRen.onclick = (e) => { e.stopPropagation(); renameFolderInteract(f.id, f.name); };

            const btnPin = document.createElement("button");
            btnPin.innerText = f.is_pinned ? "Unpin" : "Pin";
            btnPin.style.cssText = "background:var(--btn-bg); color:var(--text-primary); border:none; padding:6px 10px; border-radius:8px; font-size:11px; font-weight:700;";
            btnPin.onclick = (e) => { e.stopPropagation(); togglePin(f.id, f.is_pinned); };

            const btnDel = document.createElement("button");
            btnDel.innerText = "Delete";
            btnDel.style.cssText = "background:rgba(255,59,48,0.1); color:var(--danger); border:none; padding:6px 10px; border-radius:8px; font-size:11px; font-weight:700;";
            btnDel.onclick = (e) => { e.stopPropagation(); deleteFolder(f.id); };
            
            acts.append(btnRen, btnPin, btnDel);
            row.appendChild(acts);
        }
        list.appendChild(row);
    });
}

window.renameFolderInteract = function(id, oldName) {
    if (typeof window.openInput !== "function") return;
    window.openInput("Rename Folder", "Folder name...", oldName, (newName) => {
        if (newName && newName !== oldName) submitRename(id, newName);
    });
};

async function submitRename(id, newName) {
    await window.sui.api("folder_rename", { id: id, name: newName.trim() }, { toast: "Folder Renamed" });
    const f = so_folders.find(x => x.id == id);
    if (f) f.name = newName.trim();
    renderOrganizerBar(); renderFolderBadges(); renderFolderList();
}

async function togglePin(id, state) {
    const newState = state ? 0 : 1;
    await window.sui.api("folder_toggle_pin", { id: id, state: newState }, { toast: false });
    const f = so_folders.find(x => x.id == id); 
    if(f) f.is_pinned = newState;
    renderFolderList(); renderOrganizerBar();
}

window.openFolderActionPicker = function(id, name, isPinned) {
    if (typeof window.openPicker !== "function") return;
    const options = [
        { label: (isPinned ? "📍 Unpin Folder" : "📌 Pin Folder"), value: "pin" },
        { label: "✎ Rename Folder", value: "rename" },
        { label: "🗑️ Delete Folder", value: "delete" }
    ];
    window.openPicker(name, options, null, (val) => {
        if (val === "pin") togglePin(id, isPinned);
        else if (val === "rename") window.renameFolderInteract(id, name);
        else if (val === "delete") deleteFolder(id);
    });
};

async function createNewFolder() {
    const inp = document.getElementById("new-folder-input");
    const name = inp.value.trim();
    if(!name) return;
    try {
        const data = await window.sui.api("folder_create", { name: name }, { toast: "Folder Created" });
        if (data.status === 'success') {
            so_folders.push({ id: data.id, name: data.name, is_pinned: 0, updated_at: data.updated_at }); 
            inp.value = ""; 
            renderFolderList(); 
            renderOrganizerBar();
        }
    } catch(e) { console.error("Create folder failed", e); }
}

async function deleteFolder(id) {
    window.openConfirm("Delete Folder", "Delete this folder and unassign all notes?", async () => {
        await window.sui.api("folder_delete", { id: id }, { toast: "Folder Deleted" });
        so_folders = so_folders.filter(f => f.id != id);
        for (let lid in so_map) { if (so_map[lid] == id) delete so_map[lid]; }
        if (currentFolderId == id) setFolderFilter(null);
        renderFolderList(); renderOrganizerBar(); renderFolderBadges();
    }, true);
}

async function assignToFolder(fid) {
    closeFolderManager();
    const sel = document.querySelectorAll(".custom-checkbox.checked"); 
    const ids = Array.from(sel).map(el => el.getAttribute("data-id"));
    const targetFid = parseInt(fid);
    
    const releaseScroll = (typeof window.soLockScroll === "function") ? window.soLockScroll(ids) : () => {};
    
    await window.sui.api("folder_assign", { folder_id: targetFid, log_ids: ids }, { toast: false });
    
    const now = Math.floor(Date.now() / 1000);
    const folder = so_folders.find(f => f.id == targetFid);
    if (folder) {
        folder.updated_at = now;
        renderOrganizerBar();
    }

    ids.forEach(id => {
        if(targetFid === 0) delete so_map[id]; else so_map[id] = targetFid;
        const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
        const card = cb?.closest(".card");
        if (card && currentFolderId !== null) card.remove();
        
        // Emit update so Interaction Tracker and others can react
        if (window.cjosHooks) window.cjosHooks.emit('onUpdate', id, logs.find(l => l.id === id), { action: 'folder_assign' });
    });

    if (window.cjosRefreshPlugins) window.cjosRefreshPlugins(); 
    
    if(window.cjosToggleSelectMode) window.cjosToggleSelectMode(false);
    setTimeout(releaseScroll, 1000);
}

function injectSelectionToggle() {
    const wrap = document.querySelector(".selection-done-wrapper");
    if(wrap && !document.getElementById("sel-org-btn")) {
        const btn = document.createElement("button"); btn.className = "icon-btn"; btn.id = "sel-org-btn";
        btn.style.cssText = "background:#E5E5EA; width:36px; height:36px; border-radius:50%; color:var(--text-title); opacity:0.85; margin-right:12px; display:flex; align-items:center; justify-content:center; border:none;";
        btn.innerHTML = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>`;
        btn.onclick = (e) => { e.stopPropagation(); toggleOrganizerBar(); };
        wrap.insertBefore(btn, wrap.firstChild);
    }
}

function injectArchiveButton() {
    const bar = document.querySelector(".selection-bottom-bar");
    if (bar && !document.getElementById("action-archive")) {
        const btn = document.createElement("button");
        btn.className = "bar-action-btn";
        btn.id = "action-archive";
        
        let lpTimer = null;
        let wasLongPress = false;
        let startX = 0, startY = 0;
        
        const startLp = (e) => {
            const touch = e.touches ? e.touches[0] : e;
            startX = touch.clientX; startY = touch.clientY;
            wasLongPress = false;
            lpTimer = setTimeout(() => {
                wasLongPress = true;
                if (navigator.vibrate) navigator.vibrate(60);
                if (window._actionArchiveLp) window._actionArchiveLp();
            }, 600);
        };

        const moveLp = (e) => {
            if (!lpTimer) return;
            const touch = e.touches ? e.touches[0] : e;
            if (Math.abs(touch.clientX - startX) > 10 || Math.abs(touch.clientY - startY) > 10) {
                clearTimeout(lpTimer); lpTimer = null;
            }
        };
        
        const endLp = () => { clearTimeout(lpTimer); lpTimer = null; };

        btn.addEventListener("mousedown", startLp);
        btn.addEventListener("touchstart", startLp, {passive: true});
        btn.addEventListener("mousemove", moveLp);
        btn.addEventListener("touchmove", moveLp, {passive: true});
        btn.addEventListener("mouseup", endLp);
        btn.addEventListener("mouseleave", endLp);
        btn.addEventListener("touchend", endLp);

        btn.onclick = (e) => {
            e.stopPropagation();
            if (!wasLongPress) openFolderManager(true);
        };
        
        bar.insertBefore(btn, bar.firstChild);
    }
    updateArchiveButtonState();
}

window.updateArchiveButtonState = function() {
    const btn = document.getElementById("action-archive");
    if (!btn) return;
    const archiveFolder = so_folders.find(f => f.name.toLowerCase() === "archived");
    const archiveId = archiveFolder ? parseInt(archiveFolder.id) : -99;
    
    btn.title = "Move to Folder (Hold to Archive)";
    btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>`;
    
    window._actionArchiveLp = (currentFolderId == archiveId) ? handleQuickUnarchive : handleQuickArchive;
};

async function handleQuickArchive() {
    const sel = document.querySelectorAll(".custom-checkbox.checked");
    const ids = Array.from(sel).map(el => el.getAttribute("data-id"));
    if (ids.length === 0) return;

    let archiveFolder = so_folders.find(f => f.name.toLowerCase() === 'archived');
    let folderId;

    if (!archiveFolder) {
        const data = await window.sui.api("folder_create", { name: "Archived" }, { toast: false });
        archiveFolder = { id: data.id, name: data.name, is_pinned: 0 };
        so_folders.push(archiveFolder);
        folderId = data.id;
    } else folderId = archiveFolder.id;

    const releaseScroll = (typeof window.soLockScroll === "function") ? window.soLockScroll(ids) : () => {};
    await window.sui.api("folder_assign", { folder_id: folderId, log_ids: ids }, { toast: false });
    
    ids.forEach(id => {
        so_map[id] = folderId;
        const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
        const card = cb?.closest(".card");
        if (card && currentFolderId !== null) card.remove();
    });

    if (typeof window.logArchiveHistory === "function") window.logArchiveHistory(ids);
    if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
    if (window.cjosToggleSelectMode) window.cjosToggleSelectMode(false);
    window.sui.toast(`Archived ${ids.length} items`);
    setTimeout(releaseScroll, 1000);
}

async function handleQuickUnarchive() {
    const sel = document.querySelectorAll(".custom-checkbox.checked");
    const ids = Array.from(sel).map(el => el.getAttribute("data-id"));
    if (ids.length === 0) return;

    const releaseScroll = (typeof window.soLockScroll === "function") ? window.soLockScroll(ids) : () => {};
    await window.sui.api("folder_assign", { folder_id: 0, log_ids: ids }, { toast: false });
    ids.forEach(id => {
        const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
        if (cb) {
            const card = cb.closest(".card");
            if (card) {
                card.classList.remove("ra-anim-out");
                card.style.maxHeight = ""; card.style.opacity = ""; card.style.transform = ""; card.style.margin = ""; card.style.padding = "";
            }
        }
        delete so_map[id];
    });
    if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
    if (window.cjosToggleSelectMode) window.cjosToggleSelectMode(false);
    window.sui.toast(`Unarchived ${ids.length} items`);
    setTimeout(releaseScroll, 1000);
}

// --- PIPELINE HOOKS ---
if (window.cjosHooks) {
    window.cjosHooks.register('onDelete', (id) => {
        delete so_map[id];
    });
}

if (window.registerRefreshHook) {
    window.registerRefreshHook(renderFolderBadges);
    window.registerRefreshHook(() => {
        const container = document.getElementById("entries-container");
        if (container) {
            // Count cards that are active (not moved placeholders, not collapsed in stacks, not animating out)
            const visibleCards = container.querySelectorAll(".card:not(.is-moved-placeholder):not(.is-stacked-hidden):not(.ra-anim-out)");
            const hasStacks = !!document.querySelector(".stack-visual-card, .stack-visual-wrapper");
            
            // If the folder is truly empty, delegate empty-state rendering to the Single Source of Truth
            if (visibleCards.length === 0 && !hasStacks && !container.querySelector(".sui-empty-state")) {
                if (typeof window.renderStandardList === "function") {
                    window.renderStandardList([]);
                }
            } else if ((visibleCards.length > 0 || hasStacks) && container.querySelector(".sui-empty-state")) {
                // Safety cleanup: remove empty state when notes or stacks are present
                container.querySelectorAll(".sui-empty-state").forEach(el => el.remove());
            }
        }
    });
}

window.soLockScroll = function(excludedIds) {
    const ids = excludedIds || [];
    const container = document.getElementById("main-scroll");
    if (!container) return () => {};

    const allCards = Array.from(document.querySelectorAll(".card"));
    const anchorCard = allCards.find(c => {
        const id = c.querySelector(".custom-checkbox")?.getAttribute("data-id");
        return id && !ids.includes(id) && c.getBoundingClientRect().top >= 0;
    });

    if (!anchorCard) return () => {};

    const anchorId = anchorCard.querySelector(".custom-checkbox").getAttribute("data-id");
    const targetTop = anchorCard.getBoundingClientRect().top;
    let active = true;

    const sync = () => {
        if (!active) return;
        const cb = document.querySelector(`.custom-checkbox[data-id="${anchorId}"]`);
        const current = cb ? cb.closest(".card") : null;
        
        if (current && document.body.contains(current)) {
            const currentTop = current.getBoundingClientRect().top;
            const delta = currentTop - targetTop;
            if (Math.abs(delta) > 0.1) {
                container.scrollTop += delta;
            }
        }
        requestAnimationFrame(sync);
    };
    requestAnimationFrame(sync);

    return () => { active = false; };
};

(function() {
    window.addEventListener('load', () => {
        if (window.InteractionManager) {
            InteractionManager.subscribe({
                plugin: 'SmartOrganizer',
                event: 'onSwipeAction',
                priority: 50,
                handler: ({ card, entry, detail }) => {
                    const id = entry.id;
                    if (detail.action === 'archive') {
                        window.openConfirm("Archive Entry", "Move this entry to the archive?", () => {
                            const cb = card.querySelector('.custom-checkbox');
                            if (cb) {
                                cb.classList.add('checked');
                                window.handleQuickArchive();
                            }
                        });
                    } else if (detail.action === 'delete' || detail.action === 'fast_delete') {
                        const performDelete = () => {
                            if (typeof window.lsIsProcessing !== 'undefined') window.lsIsProcessing = true;
                            const releaseScroll = (typeof window.soLockScroll === "function") ? window.soLockScroll([id]) : () => {};
                            fetch(window.CJOS_API_URL + "?action=delete&id=" + id)
                                .then(res => res.json())
                                .then(data => {
                                    if (data.status === 'success') {
                                        const logIdx = logs.findIndex(l => l.id === id);
                                        if (logIdx !== -1) logs.splice(logIdx, 1);
                                        
                                        // Surgical DOM Removal
                                        card.remove();
                                        if (window.cjosHooks) window.cjosHooks.emit('onDelete', id);
                                        if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
                                        if (typeof window.lsIsProcessing !== 'undefined') window.lsIsProcessing = false;
                                        releaseScroll();
                                    }
                                }).catch(() => { if (typeof window.lsIsProcessing !== 'undefined') window.lsIsProcessing = false; });
                        };

                        if (detail.action === 'fast_delete') {
                            performDelete();
                            if (window.sui && window.sui.haptic) window.sui.haptic('heavy');
                        } else {
                            window.openConfirm("Delete Entry", "Permanently delete this entry?", performDelete, true);
                        }
                    }
                }
            });
        }
    });
})();

JS;
?>