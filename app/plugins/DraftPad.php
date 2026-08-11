<?php
// ==============================================================================
// PLUGIN: Draft Pad
// DESCRIPTION: Composition Staging Area.
// ==============================================================================

// Load configuration
$dp_config_file = CJOS_PATH_DATA . '/draftpad-config.json';
$dp_config = file_exists($dp_config_file) ? json_decode(file_get_contents($dp_config_file), true) : [];
$dp_show_shortcut = isset($dp_config['show_shortcut']) ? (bool)$dp_config['show_shortcut'] : false;

if ($dp_show_shortcut) {
    $plugin_buttons[] = [
        'btn-header-draftpad',
        '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zM16 18H8v-2h8v2zm0-4H8v-2h8v2zm-1-5V3.5L18.5 9H15z"/>',
        'toggleDraftPadManual()',
        'Draft Pad',
        'secondary'
    ];
}

// 1. BACKEND HANDLER: Save Draft to Logs
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'save_draft_pad') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    if (!isset($db)) {
        echo json_encode(['status' => 'error', 'message' => 'Database not initialized']);
        exit;
    }

    $text = $_POST['text'] ?? '';
    if (trim($text) === '') {
        echo json_encode(['status' => 'error', 'message' => 'Empty text']);
        exit;
    }

    $timestamp = time();
    $id = date('Ymd_His', $timestamp);
    $date_display = date('Y-m-d H:i:s', $timestamp);
    
    $stmt = $db->prepare("INSERT INTO logs (id, date_display, audio_path, transcription, timestamp) VALUES (:id, :date, :audio, :text, :ts)");
    $stmt->execute([
        ':id' => $id, 
        ':date' => $date_display, 
        ':audio' => 'text_only', 
        ':text' => $text, 
        ':ts' => $timestamp
    ]);

    // --- FOLDER ASSIGNMENT ---
    $folderId = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : 0;
    if ($folderId > 0) {
        try {
            $db->prepare("INSERT OR REPLACE INTO folder_map (log_id, folder_id) VALUES (?, ?)")
               ->execute([$id, $folderId]);
        } catch (Exception $e) {}
    }
    
    echo json_encode([
        'status' => 'success', 
        'entry' => [
            'id' => $id, 
            'date_display' => $date_display, 
            'audio_path' => 'text_only', 
            'transcription' => $text, 
            'timestamp' => $timestamp,
            'folder_id' => $folderId
        ]
    ]);
    exit;
}

// 1.1 BACKEND HANDLER: Save Draft Pad Config
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'save_draftpad_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $config_file = CJOS_PATH_DATA . '/draftpad-config.json';
    $config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
    
    $config['show_shortcut'] = (isset($_POST['show_shortcut']) && ($_POST['show_shortcut'] === 'true' || $_POST['show_shortcut'] === true));
    
    file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success', 'config' => $config]);
    exit;
}

// 1.5 SETTINGS UI
$shortcut_checked = $dp_show_shortcut ? 'checked' : '';
$plugin_settings_map['DraftPad'] = <<<HTML
    <div class="setting-item vertical" style="margin-bottom: 16px;">
        <label class="setting-label">Expansion Height</label>
        <div class="setting-desc">Adjust how much screen space the Draft Pad takes when open.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="dp-height-slider" min="200" max="850" step="10" value="450" 
                oninput="dpUpdateHeightUI(this.value)" 
                onchange="dpSaveHeight()"
                style="flex:1;">
            <span id="dp-height-val" style="font-weight:700; color:var(--primary); min-width:50px;">450px</span>
        </div>
    </div>
    
    <div class="setting-item" style="display:flex; justify-content:space-between; align-items:center; padding-top:12px; padding-bottom:12px; border-top:1px solid rgba(0,0,0,0.05);">
        <div>
            <div class="setting-label" style="font-weight:600; font-size:14px; color:var(--text-primary);">Header Shortcut</div>
            <div class="setting-desc" style="font-size:12px; color:var(--text-secondary); margin-top:2px;">Show Draft Pad button in the top header.</div>
        </div>
        <div class="setting-control">
            <style>
            .dp-switch input:checked + span {
                background-color: var(--primary) !important;
            }
            .dp-switch span::before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 2px;
                bottom: 2px;
                background-color: var(--card-bg);
                transition: .3s;
                border-radius: 50%;
                box-shadow: 0 1px 3px rgba(0,0,0,0.15);
            }
            .dp-switch input:checked + span::before {
                transform: translateX(20px);
            }
            </style>
            <label class="dp-switch" style="position: relative; display: inline-block; width: 44px; height: 24px;">
                <input type="checkbox" id="dp-shortcut-toggle" onchange="dpToggleShortcut(this.checked)" style="opacity: 0; width: 0; height: 0;" {$shortcut_checked}>
                <span style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--btn-bg); transition: .3s; border-radius: 24px; border: 1px solid var(--glass-border);"></span>
            </label>
        </div>
    </div>
HTML;

// 2. HTML STRUCTURE
$plugin_overlays[] = <<<'HTML'
<div id="draft-pad-card" style="
    position: fixed;
    bottom: calc(var(--fr-sz-h) + 10px);
    left: 12px;
    right: 12px;
    height: 450px;
    background-color: var(--card-bg);
    opacity: 0.98;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: 24px;
    box-shadow: var(--shadow-floating);
    z-index: 1500;
    display: flex;
    flex-direction: column;
    transform: translateY(150%); /* Hidden further down to clear the gap */
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    border: 1px solid var(--glass-border);
">
    <!-- Resizable Header -->
    <div id="draft-pad-header" style="
        padding: 14px 16px 10px 16px; 
        border-bottom: 1px solid rgba(0,0,0,0.05); 
        display:flex; justify-content:space-between; align-items:center;
        cursor: row-resize;
        touch-action: none;
        user-select: none;
        position: relative;
    ">
        <div style="position: absolute; top: 6px; left: 50%; transform: translateX(-50%); width: 32px; height: 4px; background: var(--text-secondary); border-radius: 10px; opacity: 0.2;"></div>
        <div style="display:flex; align-items:center; gap:12px;">
            <button onclick="setDraftPadState(false); localStorage.setItem('cjos_draft_pad_open', 'false');" style="background:var(--btn-bg); border:none; width:24px; height:24px; border-radius:50%; color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:16px; font-weight:800;">&times;</button>
            <span style="color: var(--text-secondary); font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">Drafting Area</span>
        </div>

        <div style="display:flex; gap:16px; font-size: 14px; font-weight: 500;">
            <span id="draft-status" style="opacity:0; transition:opacity 0.3s; font-size:11px; color:#8E8E93; align-self:center; margin-right:4px;">Saved</span>
            <span id="btn-draft-save" onclick="saveDraftPad()" style="cursor:pointer; color:var(--primary);">Save</span>
            <span id="btn-draft-copy" onclick="copyDraftPad()" style="cursor:pointer; color:var(--primary);">Copy</span>
            <span onclick="clearDraftPad()" style="cursor:pointer; color:var(--danger);">Clear</span>
        </div>
    </div>

    <textarea id="draft-pad-input" placeholder="Paste notes here to assemble..." style="
        flex: 1;
        width: 100%;
        border: none;
        background: transparent;
        padding: 16px;
        padding-bottom: 20px;
        font-family: var(--app-font);
        font-size: 16px;
        line-height: 1.5;
        color: var(--text-primary);
        resize: none;
        outline: none;
        box-sizing: border-box;
    "></textarea>
</div>
HTML;

// 3. JAVASCRIPT LOGIC
$plugin_js .= <<<'JS'
// --- DRAFT PAD LOGIC ---
const draftPad = document.getElementById("draft-pad-card");
const draftHeader = document.getElementById("draft-pad-header");
const draftInput = document.getElementById("draft-pad-input");
const draftStatus = document.getElementById("draft-status");
let draftSaveTimer = null;

// 1. INIT
window.addEventListener("load", () => {
    // Inject Toggle Button
    const wrapper = document.querySelector(".selection-done-wrapper");
    const doneBtn = document.getElementById("cancel-btn");
    
    if (wrapper && doneBtn) {
        const memoBtn = document.createElement("button");
        memoBtn.className = "icon-btn";
        memoBtn.id = "btn-toggle-draft";
        memoBtn.style.cssText = "background: #E5E5EA; width: 36px; height: 36px; border-radius: 50%; color: var(--text-title); opacity: 0.85; margin-right: 12px; display: flex; align-items: center; justify-content: center; padding: 0; border:none;";
        memoBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zM16 18H8v-2h8v2zm0-4H8v-2h8v2zm-1-5V3.5L18.5 9H15z"/></svg>`;
        memoBtn.onclick = (e) => {
            e.stopPropagation();
            toggleDraftPadManual();
        };
        wrapper.insertBefore(memoBtn, doneBtn);
    }
    
    // Restore Content & Height
    const savedText = localStorage.getItem("cjos_draft_pad_content");
    const savedHeight = localStorage.getItem("cjos_draft_pad_height") || 450;
    if(savedText) draftInput.value = savedText;
    if(savedHeight) {
        draftPad.style.height = savedHeight + "px";
        // Hydrate Slider if Settings is open
        const slider = document.getElementById("dp-height-slider");
        const label = document.getElementById("dp-height-val");
        if(slider) slider.value = savedHeight;
        if(label) label.innerText = savedHeight + "px";
    }

    window.dpUpdateHeightUI = function(val) {
        draftPad.style.transition = "none"; // Instant feedback
        draftPad.style.height = val + "px";
        const label = document.getElementById("dp-height-val");
        if(label) label.innerText = val + "px";
    };

    window.dpSaveHeight = function() {
        draftPad.style.transition = "transform 0.4s cubic-bezier(0.16, 1, 0.3, 1)";
        localStorage.setItem("cjos_draft_pad_height", draftPad.offsetHeight);
    };

    // Override Delete Logic
    overrideDeleteAction();
});

// --- CORE TOGGLE LOGIC ---

// 2. TOGGLE HANDLERS
window.toggleDraftPadManual = function() {
    const isVisible = draftPad.style.transform === "translateY(0px)";
    if (isVisible) {
        setDraftPadState(false);
        // User explicitly closed it, remember that preference
        localStorage.setItem("cjos_draft_pad_open", "false");
    } else {
        setDraftPadState(true);
        // User explicitly opened it, remember that preference
        localStorage.setItem("cjos_draft_pad_open", "true");
    }
};

window.setDraftPadState = function(open) {
    const btn = document.getElementById("btn-toggle-draft");
    const headerBtn = document.getElementById("btn-header-draftpad");
    
    if (open) {
        // OPEN
        draftPad.style.transform = "translateY(0px)";
        if(btn) {
            btn.style.color = "var(--primary)";
            btn.style.opacity = "1";
        }
        if(headerBtn) {
            headerBtn.style.color = "var(--primary)";
            headerBtn.style.opacity = "1";
        }
        setTimeout(() => draftInput.focus(), 100);
        
        // DISABLE AUTO-EXIT in SmartInteractions
        if (typeof siPrefs !== "undefined") {
            // Save original if not saved yet
            if (window._siAutoExitSaved === undefined) window._siAutoExitSaved = siPrefs.autoExit;
            siPrefs.autoExit = false;
        }
        
    } else {
        // CLOSE
        draftPad.style.transform = "translateY(150%)";
        if(btn) {
            btn.style.color = "var(--text-title)";
            btn.style.opacity = "0.85";
        }
        if(headerBtn) {
            headerBtn.style.color = "";
            headerBtn.style.opacity = "";
        }
        
        // RESTORE AUTO-EXIT in SmartInteractions
        if (typeof siPrefs !== "undefined" && window._siAutoExitSaved !== undefined) {
            siPrefs.autoExit = window._siAutoExitSaved;
        }
    }
}

// 3. AUTO-RESTORE STATE ON SELECT MODE
const _origToggleSelect_Draft = window.cjosToggleSelectMode;
window.cjosToggleSelectMode = function(enable) {
    if (_origToggleSelect_Draft) _origToggleSelect_Draft(enable);
    
    if (enable) {
        const shouldOpen = localStorage.getItem("cjos_draft_pad_open") === "true";
        if (shouldOpen) setTimeout(() => setDraftPadState(true), 50);
    }
    // Removed automatic closing on disable to unbundle Pad from Selection Mode
};

// 4. AUTO-SAVE
draftInput.addEventListener("input", () => {
    localStorage.setItem("cjos_draft_pad_content", draftInput.value);
    draftStatus.innerText = "Saved";
    draftStatus.style.opacity = "1";
    if(draftSaveTimer) clearTimeout(draftSaveTimer);
    draftSaveTimer = setTimeout(() => {
        draftStatus.style.opacity = "0";
    }, 1000);
});

// 5. COPY
window.copyDraftPad = function() {
    const text = draftInput.value;
    if(!text) return;
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById("btn-draft-copy");
        const originalText = btn.innerText;
        btn.innerText = "Copied";
        btn.style.fontWeight = "700";
        setTimeout(() => { btn.innerText = originalText; btn.style.fontWeight = "inherit"; }, 1500);
    });
};

// 6. SAVE AS ENTRY
window.saveDraftPad = async function() {
    const text = draftInput.value;
    if(!text.trim()) return;

    const btn = document.getElementById("btn-draft-save");
    const originalText = btn.innerText;
    btn.innerText = "Saving...";
    
    // 1. Close Pad Visually immediately
    setDraftPadState(false);
    
    // FIX: Update persistence so it doesn't auto-open next time
    localStorage.setItem("cjos_draft_pad_open", "false"); 

    // 2. Wait for animation (400ms matches CSS transition)
    await new Promise(r => setTimeout(r, 400));

    // 3. Exit Selection Mode (if active)
    if(document.body.classList.contains("select-mode")) {
        cjosToggleSelectMode(false);
    }

    // 4. Send Request
    const folderId = (typeof currentFolderId !== "undefined") ? currentFolderId : localStorage.getItem("cjos_so_fid");
    const apiData = { text: text };
    if (folderId && folderId !== "null" && folderId !== 0) {
        apiData.folder_id = folderId;
    }

    try {
        const data = await window.sui.api("save_draft_pad", apiData, { toast: false });
        if (data) {
            const entry = data.entry;

            // 1. Update local data arrays
            if (typeof logs !== "undefined") logs.unshift(entry);
            if (typeof so_map !== "undefined" && entry.folder_id) so_map[entry.id] = entry.folder_id;

            // 2. Inject into UI using LiveSync mechanism
            if (typeof injectEntryCard === "function") {
                const card = injectEntryCard(entry);
                if (card) {
                    card.scrollIntoView({ behavior: "smooth", block: "center" });
                    card.classList.add("new-entry-highlight");
                }
            }

            // 3. Refresh plugin UI components
            if (typeof renderFolderBadges === "function") renderFolderBadges();
            if (typeof renderLogLabels === "function") renderLogLabels();
            if (typeof runMasterFilter === "function") runMasterFilter();

            // 4. Reset Pad state
            draftInput.value = "";
            localStorage.setItem("cjos_draft_pad_content", "");
            btn.innerText = originalText;

        } else {
            window.openConfirm("Error", "Error saving: " + data.message, null, false, "OK", null);
            btn.innerText = originalText;
        }
    } catch(e) {
        console.error(e);
        window.openConfirm("Connection Error", "Connection failed. Check server.", null, false, "OK", null);
        btn.innerText = originalText;
    }
};

// 7. CLEAR
window.clearDraftPad = function() {
    window.openConfirm("Clear Draft", "Clear draft?", () => {
        draftInput.value = "";
        localStorage.setItem("cjos_draft_pad_content", "");
    }, true);
};

// 8. RESIZE LOGIC
let isDraggingDraft = false;
let startY_Draft = 0;
let startHeight_Draft = 0;

function startDragDraft(e) {
    isDraggingDraft = true;
    startY_Draft = e.touches ? e.touches[0].clientY : e.clientY;
    startHeight_Draft = draftPad.offsetHeight;
    draftPad.style.transition = "none";
    document.addEventListener("mousemove", onDragDraft);
    document.addEventListener("touchmove", onDragDraft, {passive: false});
    document.addEventListener("mouseup", stopDragDraft);
    document.addEventListener("touchend", stopDragDraft);
}
function onDragDraft(e) {
    if (!isDraggingDraft) return;
    e.preventDefault();
    const clientY = e.touches ? e.touches[0].clientY : e.clientY;
    const deltaY = startY_Draft - clientY;
    let newHeight = startHeight_Draft + deltaY;
    if (newHeight < 150) newHeight = 150;
    if (newHeight > window.innerHeight - 80) newHeight = window.innerHeight - 80;
    draftPad.style.height = newHeight + "px";
}
function stopDragDraft() {
    if (!isDraggingDraft) return;
    isDraggingDraft = false;
    draftPad.style.transition = "transform 0.4s cubic-bezier(0.16, 1, 0.3, 1)";
    localStorage.setItem("cjos_draft_pad_height", draftPad.offsetHeight);
    document.removeEventListener("mousemove", onDragDraft);
    document.removeEventListener("touchmove", onDragDraft);
    document.removeEventListener("mouseup", stopDragDraft);
    document.removeEventListener("touchend", stopDragDraft);
}
draftHeader.addEventListener("mousedown", startDragDraft);
draftHeader.addEventListener("touchstart", startDragDraft, {passive: false});

// 9. SMART DELETE (NON-CLOSING)
function overrideDeleteAction() {
    const btnDelete = document.getElementById("action-delete");
    if(btnDelete) {
        const newBtn = btnDelete.cloneNode(true);
        btnDelete.parentNode.replaceChild(newBtn, btnDelete);

        newBtn.onclick = async () => {
            const items = getSelectedItems();
            if(items.length) {
                window.openConfirm("Delete Items", "Delete " + items.length + " items?", async () => {
                    for (const item of items) {
                    try {
                        const response = await fetch(`${window.CJOS_API_URL}?action=delete&id=${item.id}`);
                        const data = await response.json();
                        if (data.status === "success") {
                            const checkbox = document.querySelector(`.custom-checkbox[data-id="${item.id}"]`);
                            if (checkbox) {
                                const card = checkbox.closest(".card");
                                if (card) {
                                    card.style.transition = "all 0.3s ease";
                                    card.style.opacity = "0";
                                    card.style.transform = "scale(0.9)";
                                    card.style.marginBottom = "-50px";
                                    setTimeout(() => card.remove(), 300);
                                }
                            }
                        }
                    } catch(e) { console.error(e); }
                }

                // If Pad is Open, do NOT exit selection mode
                // Since updateSelectionCount will show 0, and we disabled Auto-Exit,
                // the user stays in selection mode until they hit Done.
                const isDrafting = draftPad.style.transform === "translateY(0px)";
                if (!isDrafting) {
    cjosToggleSelectMode(false);
} else {setTimeout(() => {
                        if(window.updateSelectionCount) window.updateSelectionCount();
                        }, 350);
                    }
                }, true);
            }
        };
    }
}

// 10. TOGGLE HEADER SHORTCUT
window.dpToggleShortcut = async function(checked) {
    try {
        const response = await window.sui.api("save_draftpad_config", { show_shortcut: checked }, { toast: true });
        if (response && response.status === "success") {
            location.reload();
        }
    } catch (e) {
        console.error("Failed to save draftpad configuration", e);
    }
};
JS;
?>