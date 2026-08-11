<?php
// ==============================================================================
// PLUGIN: Manual Editor
// DESCRIPTION: Edit Note Text.
// ==============================================================================

// 1. DATABASE UPDATE
try {
    if (isset($db)) {
        $cols = $db->query("PRAGMA table_info(logs)")->fetchAll(PDO::FETCH_ASSOC);
        $hasCol = false;
        foreach ($cols as $c) { if ($c['name'] === 'is_manually_edited') $hasCol = true; }
        if (!$hasCol) {
            $db->exec("ALTER TABLE logs ADD COLUMN is_manually_edited INTEGER DEFAULT 0");
        }
    }
} catch (Exception $e) {}

// 2. BACKEND HANDLER
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'manual_edit_save') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $id = $_POST['id'] ?? '';
    $text = $_POST['text'] ?? '';
    
    if (empty($id)) { 
        echo json_encode(['status' => 'error', 'message' => 'Missing ID']); 
        exit; 
    }

    try {
        if (!isset($db)) throw new Exception("Database connection missing");

        $stmt = $db->prepare("UPDATE logs SET transcription = :text, is_manually_edited = 1 WHERE id = :id");
        $stmt->execute([':text' => $text, ':id' => $id]);
        
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    
    exit;
}

// 3. JAVASCRIPT LOGIC
$plugin_js .= <<<'JS'
// --- MANUAL EDITOR CSS ---
const meStyle = document.createElement("style");
meStyle.textContent = `
    .card.is-processing .manual-edit-btn { display: none !important; }
    .manual-edit-btn {
        position: absolute;
        bottom: 12px;
        right: 12px;
        color: #D1D1D6;
        background: transparent;
        border: none;
        padding: 8px;
        cursor: pointer;
        opacity: 0.6;
        transition: opacity 0.2s, color 0.2s;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 5;
    }
    .manual-edit-btn:hover {
        opacity: 1;
        color: var(--primary);
        background-color: rgba(0,0,0,0.03);
    }
    .manual-edit-textarea {
        width: 100%;
        min-height: 100px;
        font-family: inherit;
        font-size: 17px;
        line-height: 1.5;
        padding: 10px;
        border: 1px solid var(--primary);
        border-radius: 12px;
        background: var(--input-bg);
        color: var(--input-text);
        resize: vertical;
        box-sizing: border-box;
        outline: none;
        margin-bottom: 10px;
        /* Fix Android/iOS selection menu */
        user-select: text !important;
        -webkit-user-select: text !important;
    }
    .manual-edit-wrapper {
        user-select: text !important;
        -webkit-user-select: text !important;
    }
    .edit-controls {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }
    .edit-btn-action {
        padding: 6px 16px;
        border-radius: 16px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        border: none;
    }
    .edit-save { background: var(--primary); color: var(--primary-text); }
    .edit-cancel { background: var(--btn-bg); color: var(--btn-text); }
`;
document.head.appendChild(meStyle);

// --- REGISTER PLUGIN ---
// Priority 60: Content Decoration
if (window.registerCardPlugin) {
    window.registerCardPlugin(setupManualEditor, 60);
} else {
    // Fallback if LiveSync is disabled/missing
    window.addEventListener("load", () => document.querySelectorAll(".card").forEach(setupManualEditor));
}

function setupManualEditor(card) {
    if(card.querySelector(".manual-edit-btn")) return;
    
    const checkbox = card.querySelector(".custom-checkbox");
    if (!checkbox) return;
    
    const id = checkbox.getAttribute("data-id");
    const entry = logs.find(l => l.id === id);
    if (!entry) return;

    const content = card.querySelector(".card-content");
    content.style.position = "relative"; 
    
    const editBtn = document.createElement("button");
    editBtn.className = "manual-edit-btn";
    editBtn.innerHTML = window.suiIcon('edit', 'currentColor', 20, 2);
    editBtn.title = "Edit Text";
    
    // Click handled via InteractionManager delegation
    
    content.appendChild(editBtn);
}

// Phase 8: Badge Engine Registration
document.addEventListener('DOMContentLoaded', () => {
    if (window.sui && window.sui.registerBadge) {
        window.sui.registerBadge("manual-edited-badge", (entry) => {
            if (entry.is_manually_edited == 1) {
                return window.suiBadge("✎ Edited", "default");
            }
            return null;
        }, 60); // Priority 60: Decoration
    }
});

function updateRecordBtnState() {
    const fab = document.getElementById("fab-record");
    if(!fab) return;
    const activeEditors = document.querySelectorAll(".manual-edit-textarea");
    if (activeEditors.length > 0) {
        fab.style.opacity = "0";
        fab.style.pointerEvents = "none";
    } else {
        fab.style.opacity = "1";
        fab.style.pointerEvents = "auto";
    }
}

function enterEditMode(entry, container, editBtn) {
    const textDiv = container.querySelector(".transcription");
    const readMore = container.querySelector(".read-more-btn");
    
    textDiv.style.display = "none";
    if(readMore) readMore.style.display = "none";
    editBtn.style.display = "none";
    
    const wrapper = document.createElement("div");
    wrapper.className = "manual-edit-wrapper";
    wrapper.onclick = (e) => e.stopPropagation();
    
    const textarea = document.createElement("textarea");
    textarea.className = "manual-edit-textarea";
    textarea.setAttribute("inputmode", "text");
    textarea.style.touchAction = "auto"; // Ensure touch gestures for menu aren't blocked
    textarea.value = entry.transcription; 

    // Insulate from InteractionManager and global gestures
    // This prevents parent cards from "eating" the long-press or double-tap events
    ['touchstart', 'touchend', 'touchmove', 'pointerdown', 'pointerup', 'contextmenu', 'mousedown', 'mouseup', 'click', 'dblclick'].forEach(evt => {
        textarea.addEventListener(evt, (e) => {
            e.stopPropagation();
        }, { passive: true });
    });
    
    setTimeout(() => {
        textarea.style.height = "auto";
        textarea.style.height = textarea.scrollHeight + "px";
        textarea.focus();
    }, 10);
    
    const controls = document.createElement("div");
    controls.className = "edit-controls";
    
    const cancelBtn = document.createElement("button");
    cancelBtn.className = "edit-btn-action edit-cancel";
    cancelBtn.innerText = "Cancel";
    
    const saveBtn = document.createElement("button");
    saveBtn.className = "edit-btn-action edit-save";
    saveBtn.innerText = "Save";
    
    controls.appendChild(cancelBtn);
    controls.appendChild(saveBtn);
    wrapper.appendChild(textarea);
    wrapper.appendChild(controls);
    container.insertBefore(wrapper, textDiv.nextSibling);
    
    updateRecordBtnState();
    
    cancelBtn.onclick = (e) => {
        e.stopPropagation();
        cleanup();
    };
    
    saveBtn.onclick = async (e) => {
        e.stopPropagation();
        const newText = textarea.value;
        saveBtn.innerText = "Saving...";
        saveBtn.style.opacity = "0.7";
        
        try {
            const data = await window.sui.api("manual_edit_save", { id: entry.id, text: newText }, { toast: false });
            if (data) {
                entry.transcription = newText;
                entry.is_manually_edited = 1;
                textDiv.textContent = newText;
                // Refresh badges via Engine
                if (window.sui && window.sui.decorateCard) {
                    const card = container.closest('.card');
                    if (card) window.sui.decorateCard(card, entry);
                }
                if (window.cjosBroadcastUpdate) window.cjosBroadcastUpdate(entry.id, entry);
                cleanup();
            }
        } catch(err) {
            saveBtn.innerText = "Save";
            saveBtn.style.opacity = "1";
        }
    };
    
    function cleanup() {
        wrapper.remove();
        textDiv.style.display = ""; 
        
        setTimeout(() => {
            const isLong = textDiv.scrollHeight > textDiv.clientHeight || textDiv.textContent.length > 300;
            if(readMore) {
                readMore.style.display = isLong ? "inline-block" : "none";
                if(isLong) {
                    if(textDiv.classList.contains("truncated")) {
                        readMore.textContent = "Read more";
                    } else {
                        readMore.textContent = "Show less";
                    }
                }
            }
        }, 50);

        editBtn.style.display = "flex";
        updateRecordBtnState();
    }
}
JS;

$plugin_js .= <<<'JS'
// --- MANUAL EDITOR INTERACTION MANAGER ADAPTER ---
(function() {
    window.addEventListener('load', () => {
        if (!window.InteractionManager) return;

        InteractionManager.subscribe({
            plugin: 'ManualEditor',
            event: 'onEditTap',
            priority: 60,
            handler: ({ card, entry, vibrate }) => {
                const content = card.querySelector('.card-content');
                const editBtn = card.querySelector('.manual-edit-btn');
                if (content && editBtn && entry) {
                    enterEditMode(entry, content, editBtn);
                    vibrate('light');
                }
            }
        });
    });
})();
JS;
?>