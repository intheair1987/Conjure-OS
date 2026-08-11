<?php
// ==============================================================================
// PLUGIN: Storage Optimizer
// DESCRIPTION: Audio Cleanup.
// 1. Delete audio files from selected entries (keep text).
// 2. Hide player controls for text-only entries.
// UPDATED: Fixed button alignment with ScrollableActionBar.
// ==============================================================================

// --- BACKEND HANDLER ---
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'optimize_delete_audio') {
    // Clean output buffer
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $ids = json_decode($_POST['ids'], true);
    $count = 0;
    $errors = [];

    if (!empty($ids) && is_array($ids)) {
        $db->beginTransaction();
        // Prepare DB update
        $stmtUpdate = $db->prepare("UPDATE logs SET audio_path = 'text_only' WHERE id = ?");
        
        foreach ($ids as $id) {
            // 1. Get current path
            $stmtGet = $db->prepare("SELECT audio_path FROM logs WHERE id = ?");
            $stmtGet->execute([$id]);
            $row = $stmtGet->fetch(PDO::FETCH_ASSOC);

            if ($row && $row['audio_path'] !== 'text_only') {
                $filePath = CJOS_PATH_ROOT . '/' . $row['audio_path'];
                
                // 2. Delete File
                if (file_exists($filePath)) {
                    if (unlink($filePath)) {
                        // 3. Update DB only if delete successful
                        $stmtUpdate->execute([$id]);
                        $count++;
                    } else {
                        $errors[] = "Could not delete file for ID: $id";
                    }
                } else {
                    // File already gone, update DB anyway
                    $stmtUpdate->execute([$id]);
                    $count++;
                }
            }
        }
        $db->commit();
    }

    echo json_encode(['status' => 'success', 'count' => $count, 'errors' => $errors]);
    exit;
}

// --- SETTINGS UI ---
$plugin_settings_map['StorageOptimizer'] = <<<'HTML'
    <div data-sui-setting="Hide Empty Players" data-sui-desc="Remove play buttons from text-only entries." data-sui-id="so-hide-players" data-sui-onchange="toggleSoHidePlayers(this.checked)"></div>
    <div class="setting-item">
        <div class="setting-desc">
            To delete audio files, enter Selection Mode (tap header/long press), select items, and look for the waveform icon with an "X" in the bottom bar.
        </div>
    </div>
HTML;

// --- JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- STORAGE OPTIMIZER JS ---

const soPrefs = {
    hidePlayers: localStorage.getItem("cjos_so_hide_players") !== "false" // Default true
};

window.toggleSoHidePlayers = function(val) {
    localStorage.setItem("cjos_so_hide_players", val);
    location.reload();
};

window.addEventListener("load", () => {
    // 1. Init Settings Toggle
    const toggle = document.getElementById("so-hide-players");
    if(toggle) toggle.checked = soPrefs.hidePlayers;

    // 2. Inject Action Button (With Scrollbar Support)
    // We wait a small amount to allow ScrollableActionBar to initialize its DOM
    setTimeout(() => {
        // Priority: Look for the scroll wrapper first, fallback to main bar
        const scrollCont = document.querySelector(".sb-scroll-container");
        const mainBar = document.querySelector(".selection-bottom-bar");
        const target = scrollCont || mainBar;

        if (target && !document.getElementById("action-opt-audio")) {
            const btn = document.createElement("button");
            btn.className = "bar-action-btn danger";
            btn.id = "action-opt-audio"; // ID added to prevent dupes
            btn.title = "Delete Audio (Keep Text)";
            // Icon: Waveform with X
            btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5L6 9H2v6h4l5 4V5z"></path><path d="M19.07 4.93L15.54 8.46a5 5 0 0 0 0 7.07l3.53 3.53"></path><line x1="16" y1="9" x2="22" y2="15"></line><line x1="22" y1="9" x2="16" y2="15"></line></svg>`;
            btn.onclick = performAudioCleanup;
            target.appendChild(btn);
        }
    }, 200);

    // 3. Run UI Cleanup
    if (soPrefs.hidePlayers) {
        scanAndHidePlayers();
        // Watch for LiveSync updates
        const container = document.getElementById("entries-container");
        if(container) {
            const obs = new MutationObserver(() => scanAndHidePlayers());
            obs.observe(container, { childList: true });
        }
    }
});

function scanAndHidePlayers() {
    const cards = document.querySelectorAll(".card");
    cards.forEach(card => {
        const checkbox = card.querySelector(".custom-checkbox");
        if(!checkbox) return;
        
        const id = checkbox.getAttribute("data-id");
        const entry = logs.find(l => l.id === id);
        
        const isTextOnly = !entry.audio_path || entry.audio_path === "text_only" || entry.audio_path === "";
        
        if (isTextOnly) {
            const player = card.querySelector(".player-capsule");
            if (player) {
                player.style.display = "none";
            }
        }
    });
}

async function performAudioCleanup() {
    let items = getSelectedItems();
    if (items.length === 0) return;

    let audioItemsToProcess = items.filter(i => i.audio_path && i.audio_path !== "text_only");

    // --- FOLDER CONTEXT SCANNER ---
    const folderId = (typeof currentFolderId !== "undefined") ? currentFolderId : null;
    let folderName = "this view";
    if (folderId === 0) folderName = "Unsorted";
    else if (folderId && typeof so_folders !== "undefined") {
        const f = so_folders.find(x => x.id == folderId);
        if (f) folderName = f.name;
    }

    // Find all items currently visible in this folder/view that have audio
    const allInViewWithAudio = logs.filter(l => {
        const inFolder = (folderId === null) || (so_map[l.id] == folderId) || (folderId === 0 && (!so_map[l.id] || so_map[l.id] == 0));
        const hasAudio = l.audio_path && l.audio_path !== "text_only";
        return inFolder && hasAudio;
    });

    // If there are more audio files in this folder than currently selected, offer bulk cleanup
    if (allInViewWithAudio.length > audioItemsToProcess.length && typeof window.openPicker === "function") {
        const choice = await new Promise((resolve) => {
            window.openPicker(`Bulk Audio Cleanup`, [
                { label: `Clean Selected Only (${audioItemsToProcess.length})`, value: "selected" },
                { label: `Clean All in ${folderName} (${allInViewWithAudio.length})`, value: "all" },
                { label: "Cancel", value: "cancel" }
            ], null, (val) => resolve(val));
        });

        if (choice === "cancel" || !choice) return;
        if (choice === "all") {
            audioItemsToProcess = allInViewWithAudio;
        }
    } else {
        if (audioItemsToProcess.length === 0) {
            window.openConfirm("Selection Error", "No audio files found in selection.", null, false, "OK", null);
            return;
        }
        window.openConfirm("Delete Audio", `Delete audio files for ${audioItemsToProcess.length} entries?`, () => {
            performAudioCleanupExecution(audioItemsToProcess);
        }, true);
        return;
    }

    performAudioCleanupExecution(audioItemsToProcess);
}

async function performAudioCleanupExecution(itemsToClean) {
    if (typeof cjosToggleSelectMode === "function") cjosToggleSelectMode(false);
    
    const total = itemsToClean.length;
    const chunkSize = 25;
    let optimizedCount = 0;
    
    if (window.cjosProgressPill) window.cjosProgressPill.show(`Cleaning ${total} files...`);

    // Process in Chunks
    for (let i = 0; i < itemsToClean.length; i += chunkSize) {
        const chunk = itemsToClean.slice(i, i + chunkSize);
        const chunkIds = chunk.map(item => item.id);
        
        if (window.cjosProgressPill) {
            const pct = Math.round((i / total) * 100);
            window.cjosProgressPill.update(`Cleaning batch...`, pct);
        }

        try {
            const data = await window.sui.api("optimize_delete_audio", { ids: chunkIds }, { toast: false });
            if (data && data.status === 'success') {
                // Update local data and UI for the entire chunk
                chunkIds.forEach(id => {
                    const entry = logs.find(l => l.id === id);
                    if (entry) entry.audio_path = "text_only";
                    
                    const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
                    if (cb) {
                        const player = cb.closest(".card")?.querySelector(".player-capsule");
                        if (player) player.style.display = "none";
                    }
                });
                optimizedCount += data.count;
            }
        } catch (e) { console.error("Batch optimization failed", e); }
    }

    if (window.cjosProgressPill) window.cjosProgressPill.done(`Optimized ${optimizedCount} files`);
    
    // Refresh UI state
    if (typeof scanAndHidePlayers === "function") scanAndHidePlayers();
}
JS;
?>