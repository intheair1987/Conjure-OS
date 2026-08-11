<?php
// ==============================================================================
// PLUGIN: LiveSync Phantom Filter
// DESCRIPTION: Silent Folder Alerts.
// Extends LiveSync logic to:
// 1. Hide "Phantom Cards" for specific folders (Archive).
// 2. Prevent Phantom UI if the user is already in the matching folder (Fixes Unsorted).
// 3. Updates the label to "New in [Folder]" for clarity.
// ==============================================================================

$ls_pf_config = CJOS_PATH_DATA . '/livesync-phantom-filter-config.json';

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'ls_pf_save_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $ignored = $_POST['ignored_folders'] ?? '';
        file_put_contents($ls_pf_config, json_encode(['ignored' => $ignored]));
        
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($_POST['plugin_action'] === 'ls_pf_get_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $data = ['ignored' => 'Archive']; // Default
        if (file_exists($ls_pf_config)) {
            $data = json_decode(file_get_contents($ls_pf_config), true);
        }
        echo json_encode(['status' => 'success', 'config' => $data]);
        exit;
    }
}

// --- SETTINGS UI ---
$plugin_settings_map['LiveSyncPhantomFilter'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Silent Folders</label>
        <div class="setting-desc">
            Comma-separated list of folder names. New entries arriving in these folders will NOT show "Phantom Cards" (Go to Folder buttons).
        </div>
        <input type="text" id="ls-pf-input" placeholder="e.g. Archive, Dictation" onchange="saveLsPfSettings()" style="margin-top:8px;">
        <div id="ls-pf-status" style="text-align:right; font-size:11px; color:#8E8E93; margin-top:8px; height:14px;"></div>
    </div>
HTML;

// --- JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- LIVESYNC PHANTOM FILTER JS ---

let lsPfIgnoredFolders = ["Archive"];

window.addEventListener("load", () => {
    loadLsPfSettings();
    
    // --- THE HIJACK ---
    // We wrap the original makeCardPhantom function from LiveSync.php
    setTimeout(() => {
        if (typeof window.makeCardPhantom === "function") {
            
            window.makeCardPhantom = function(card, targetFolderId) {
                // 1. Resolve folder name
                let folderName = "Unsorted";
                if (targetFolderId && typeof so_folders !== "undefined") {
                    const f = so_folders.find(x => x.id == targetFolderId);
                    if (f) folderName = f.name;
                }
                
                // 2. LOGIC FIX: Check if we are already in this view
                // currentFolderId can be null (All), 0 (Unsorted), or ID (Folder)
                // targetFolderId can be undefined/null (Unsorted) or ID (Folder)
                const activeViewId = (typeof currentFolderId !== "undefined") ? currentFolderId : null;
                
                // Normalize both to 0 if they represent Unsorted
                const normalizedActive = (activeViewId === null || activeViewId === 0) ? 0 : activeViewId;
                const normalizedTarget = (targetFolderId === null || targetFolderId === 0 || !targetFolderId) ? 0 : targetFolderId;

                if (normalizedActive === normalizedTarget) {
                    // We are in the correct view! 
                    // Do NOT apply phantom styling or show the "Go to Folder" button.
                    card.classList.remove("phantom-card");
                    console.log("LiveSync Filter: Skipping phantom UI (Already in " + folderName + ")");
                    return;
                }
                
                // 3. Check against "Silent Folders" (Archive)
                const isIgnored = lsPfIgnoredFolders.some(name => 
                    name.trim().toLowerCase() === folderName.trim().toLowerCase()
                );
                
                if (isIgnored) {
                    console.log("LiveSync Filter: Suppressing card for silent folder: " + folderName);
                    card.remove();
                    return;
                }
                
                // 4. Apply Custom Phantom UI
                card.classList.add("phantom-card");
                const content = card.querySelector(".card-content");
                const actions = document.createElement("div"); 
                actions.className = "phantom-actions";
                
                // Updated Label as requested
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
                        jumpToFolderAndHighlight(id, targetFolderId || 0);
                    }
                };

                // 2. MOVE HERE
                actions.querySelector(".btn-phantom-move").onclick = (e) => {
                    e.stopPropagation();
                    const id = card.querySelector(".custom-checkbox").getAttribute("data-id");
                    const currFid = (typeof currentFolderId !== "undefined" && currentFolderId !== null) ? currentFolderId : 0;
                    
                    window.sui.api("folder_assign", { folder_id: currFid, log_ids: [id] }, { toast: false }).then(() => {
                        if(typeof so_map !== "undefined") so_map[id] = currFid;
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
                    card.style.maxHeight = card.offsetHeight + "px";
                    requestAnimationFrame(() => {
                        card.classList.add("phantom-dismissing");
                    });
                    setTimeout(() => card.remove(), 550);
                };
                content.appendChild(actions);
            };
        }
    }, 1100); // Wait slightly longer than LiveSync's 1000ms init
});

async function loadLsPfSettings() {
    try {
        const data = await window.sui.api("ls_pf_get_config", {}, { toast: false });
        if (data) {
            const val = data.config.ignored || "";
            document.getElementById("ls-pf-input").value = val;
            lsPfIgnoredFolders = val.split(",").map(s => s.trim()).filter(s => s !== "");
        }
    } catch(e) {}
}

async function saveLsPfSettings() {
    const val = document.getElementById("ls-pf-input").value;
    const status = document.getElementById("ls-pf-status");
    if(status) status.innerText = "Saving...";
    
    lsPfIgnoredFolders = val.split(",").map(s => s.trim()).filter(s => s !== "");
    
    try {
        await window.sui.api("ls_pf_save_config", { ignored_folders: val }, { toast: false });
        if(status) {
            status.innerText = "Settings Saved";
            setTimeout(() => status.innerText = "", 2000);
        }
    } catch(e) {
        if(status) status.innerText = "Error saving";
    }
}
JS;