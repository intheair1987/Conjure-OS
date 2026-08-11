<?php
// ==============================================================================
// PLUGIN: Merge Entries
// DESCRIPTION: Combine Multiple Notes.
// Merges selected entries (text ONLY) and offers a non-blocking delete confirm.
// Updated: Supports Folder Persistence (SmartOrganizer)
// ==============================================================================

// --- DATABASE MIGRATION ---
try {
    $cols = $db->query("PRAGMA table_info(logs)")->fetchAll(PDO::FETCH_ASSOC);
    $hasCol = false;
    foreach ($cols as $c) { if ($c['name'] === 'is_merged') $hasCol = true; }
    if (!$hasCol) {
        $db->exec("ALTER TABLE logs ADD COLUMN is_merged INTEGER DEFAULT 0");
    }
} catch (Exception $e) {}

// --- BACKEND HANDLERS ---

if (isset($_POST['plugin_action'])) {
    
    // 1. MERGE ACTION
    if ($_POST['plugin_action'] === 'merge_entries') {
        // Clean Output
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $logIds = json_decode($_POST['log_ids'], true);
        $targetFolderId = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : 0; // Capture Folder ID

        if (empty($logIds) || !is_array($logIds)) {
            echo json_encode(['status' => 'error', 'message' => 'No items selected']); exit;
        }

        // Fetch Data
        $placeholders = implode(',', array_fill(0, count($logIds), '?'));
        $stmt = $db->prepare("SELECT * FROM logs WHERE id IN ($placeholders)");
        $stmt->execute($logIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Sort rows based on selection order
        $orderedRows = [];
        foreach ($logIds as $id) {
            foreach ($rows as $row) {
                if ($row['id'] === $id) {
                    $orderedRows[] = $row;
                    break;
                }
            }
        }
        
        if (empty($orderedRows)) { echo json_encode(['status' => 'error', 'message' => 'DB Read Error']); exit; }

        // Determine New Metadata
        $lastEntry = end($orderedRows);
        $newTimestamp = $lastEntry['timestamp'] + 2;

        // --- COLLISION AVOIDANCE LOOP ---
        // If an entry with this timestamp ID already exists, keep adding 2 seconds until unique.
        while (true) {
            $candidateId = date('Ymd_His', $newTimestamp);
            $check = $db->prepare("SELECT COUNT(*) FROM logs WHERE id = ?");
            $check->execute([$candidateId]);
            if ($check->fetchColumn() == 0) {
                $newId = $candidateId;
                break;
            }
            $newTimestamp += 2;
        }
        $newDateDisplay = date('Y-m-d H:i:s', $newTimestamp);
        
        // Merge Text
        $mergedText = "";
        foreach ($orderedRows as $row) {
            $mergedText .= $row['transcription'] . "\n\n";
        }
        $mergedText = trim($mergedText);

        // Text Only - No Audio File
        $dbAudioPath = "text_only"; 

        // Insert New Entry
        $ins = $db->prepare("INSERT INTO logs (id, date_display, audio_path, transcription, timestamp, is_merged) VALUES (?, ?, ?, ?, ?, 1)");
        $ins->execute([$newId, $newDateDisplay, $dbAudioPath, $mergedText, $newTimestamp]);

        // --- FOLDER PERSISTENCE LOGIC ---
        // If we are in a specific folder, map the new entry to it.
        if ($targetFolderId > 0) {
            try {
                // Check if SmartOrganizer table exists to prevent errors if plugin is disabled
                $chk = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='folder_map'");
                if ($chk->fetch()) {
                    $db->prepare("INSERT OR REPLACE INTO folder_map (log_id, folder_id) VALUES (?, ?)")
                       ->execute([$newId, $targetFolderId]);
                }
            } catch (Exception $e) {
                // Ignore folder error, proceed with merge
            }
        }
        // --------------------------------

        // Save Text File (Optional backup)
        $trans_dir = CJOS_PATH_STORAGE . '/text';
        if (!is_dir($trans_dir)) mkdir($trans_dir, 0777, true);
        file_put_contents("$trans_dir/$newId.txt", $mergedText);

        echo json_encode([
            'status' => 'success', 
            'new_id' => $newId,
            'entry' => [
                'id' => $newId,
                'date_display' => $newDateDisplay,
                'audio_path' => $dbAudioPath,
                'transcription' => $mergedText,
                'timestamp' => $newTimestamp,
                'folder_id' => $targetFolderId,
                'is_merged' => 1
            ]
        ]);
        exit;
    }

    // 2. DELETE ORIGINALS ACTION
    if ($_POST['plugin_action'] === 'delete_merged_originals') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $ids = json_decode($_POST['ids'], true);
        if (!empty($ids)) {
            $rec_dir = CJOS_PATH_STORAGE . '/audio';
            $trans_dir = CJOS_PATH_STORAGE . '/text';
            
            $stmt = $db->prepare("DELETE FROM logs WHERE id = ?");
            
            // Also clean up folder map if it exists
            $hasFolderMap = false;
            try {
                $chk = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='folder_map'");
                if ($chk->fetch()) $hasFolderMap = true;
            } catch(Exception $e) {}
            
            $stmtFolder = $hasFolderMap ? $db->prepare("DELETE FROM folder_map WHERE log_id = ?") : null;

            foreach ($ids as $id) {
                // Delete files
                foreach(['webm','mp4','m4a','wav'] as $x) {
                    $f = "$rec_dir/$id.$x";
                    if(file_exists($f)) unlink($f);
                }
                $t = "$trans_dir/$id.txt";
                if(file_exists($t)) unlink($t);
                
                // DB Delete
                $stmt->execute([$id]);
                if ($stmtFolder) $stmtFolder->execute([$id]);
                $db->prepare("DELETE FROM ai_suggestions WHERE log_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM ai_audit_log WHERE log_id = ?")->execute([$id]);
            }
        }
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- FRONTEND: SETTINGS & JS ---

$plugin_settings_map['MergeEntries'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Merge Feature Guide</label>
            <span class="setting-desc">The Merge Entries plugin is active. Enter multi-selection mode and select 2 or more notes to reveal the merge action button (<svg style="width:12px; height:12px; display:inline-block; vertical-align:middle;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 5 L12 11 L18 5"></path><path d="M12 11 L12 20"></path></svg>) in the bottom selection bar. Tapping it combines their transcriptions into a new text-only note. To disable this feature entirely, turn off the master "Merge Entries" switch in the plugin list.</span>
        </div>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- MERGE ENTRIES PLUGIN ---

// 1. INJECT CSS FOR HIGHLIGHT & DELEGATED BANNER Spacing
const mergeStyle = document.createElement("style");
mergeStyle.innerHTML = `
    @keyframes mergePulse {
        0% { box-shadow: 0 0 0 0 rgba(0, 122, 255, 0.6); transform: scale(1); }
        50% { box-shadow: 0 0 0 20px rgba(0, 122, 255, 0); transform: scale(1.02); }
        100% { box-shadow: 0 0 0 0 rgba(0, 122, 255, 0); transform: scale(1); }
    }
    @keyframes slideUp { 
        from { transform: translateY(100%); opacity: 0; } 
        to { transform: translateY(0); opacity: 1; } 
    }
    .merge-highlight {
        animation: mergePulse 1.5s infinite cubic-bezier(0.4, 0, 0.2, 1);
        outline: 2px solid var(--primary) !important;
        outline-offset: -2px;
        z-index: 200 !important;
    }
    #merge-delete-banner {
        position: fixed;
        bottom: calc(var(--fab-bottom-offset, 20px) + var(--fab-size, 56px) + 20px);
        left: 20px;
        right: 20px;
        background: var(--card-bg);
        border-radius: 16px;
        box-shadow: var(--shadow-floating, 0 10px 40px rgba(0,0,0,0.2));
        padding: 16px;
        z-index: 3000;
        display: flex;
        flex-direction: column;
        gap: 12px;
        animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        transition: bottom 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    
    /* Lift banner when Selection Mode is active (keeps it above the multi-bucket selection bar) */
    body.select-mode #merge-delete-banner {
        bottom: 120px;
    }
    
    /* Lift banner higher when Floating Command Bar (FCB) is also active and elevated above the Selection Bar */
    body.fcb-mode.select-mode #merge-delete-banner {
        bottom: 190px !important;
    }
`;
document.head.appendChild(mergeStyle);

window.addEventListener("load", () => {
    // 1. Inject Merge Button
    const bottomBar = document.querySelector(".selection-bottom-bar");
    
    if (bottomBar) {
        const mergeBtn = document.createElement("button");
        mergeBtn.className = "bar-action-btn";
        mergeBtn.id = "action-merge";
        
        mergeBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 5 L12 11 L18 5"></path>
            <path d="M12 11 L12 20"></path>
        </svg>`;
        
        mergeBtn.onclick = handleMergeAction;
        bottomBar.appendChild(mergeBtn);
    }

    // Phase 8: Badge Engine Registration
    if (window.sui && window.sui.registerBadge) {
        window.sui.registerBadge("merged-badge", (entry) => {
            if (entry && entry.is_merged == 1) {
                return window.suiBadge("⑂ Merged", "default");
            }
            return null;
        }, 35); // Priority 35: Post-Structural
    }

    // 3. Check for Pending Confirmation (And Highlight)
    checkPendingMergeDeletion();
});

// --- ACTION LOGIC ---
async function handleMergeAction() {
    let orderedIds = [];
    if (typeof selectionSequence !== "undefined" && selectionSequence.length > 0) {
        orderedIds = [...selectionSequence];
    } else {
        orderedIds = getSelectedItems().map(i => i.id);
    }

    if (orderedIds.length < 2) {
        window.openConfirm("Merge Error", "Select at least 2 entries to merge.", null, false, "OK", null);
        return;
    }

    const overlay = document.getElementById("processing-overlay");
    const procText = document.getElementById("proc-text");
    if(overlay) { overlay.classList.add("visible"); procText.textContent = "Merging..."; }

    try {
        const data = await window.sui.api("merge_entries", {
            log_ids: orderedIds,
            folder_id: (typeof currentFolderId !== "undefined" && currentFolderId !== null) ? currentFolderId : (localStorage.getItem("cjos_so_fid") || 0)
        }, { toast: false });

        if (data.status === "success") {
            const entry = data.entry;
            const lastSelectedId = orderedIds[orderedIds.length - 1];
            const lastCard = document.querySelector(`.custom-checkbox[data-id="${lastSelectedId}"]`)?.closest(".card");

            // 1. Update Local Data (Chronological Splice)
            if (typeof logs !== "undefined") {
                const insertIdx = logs.findIndex(l => parseInt(l.timestamp) < entry.timestamp);
                if (insertIdx === -1) logs.push(entry);
                else logs.splice(insertIdx, 0, entry);
            }
            if (typeof so_map !== "undefined" && entry.folder_id) so_map[entry.id] = entry.folder_id;

            // 2. Persist state for recovery
            localStorage.setItem("cjos_pending_merge_delete", JSON.stringify(orderedIds));
            localStorage.setItem("cjos_new_merge_id", data.new_id);

            // 3. Inject UI without reload
            if (typeof createStandardCardDOM === "function") {
                const card = createStandardCardDOM(entry);
                
                // Chronological DOM Placement: After the last selected item
                if (lastCard) {
                    lastCard.after(card);
                } else {
                    const container = document.getElementById("entries-container");
                    if (container) container.prepend(card);
                }

                // Run Plugin Hooks (WordCounter, ManualEditor, etc.)
                if(window.cjosPluginRegistry) {
                    window.cjosPluginRegistry.forEach(plugin => { try { plugin.fn(card); } catch(e) {} });
                }

                // Visuals
                if (overlay) overlay.classList.remove("visible");
                
                // EXIT SELECT MODE
if (typeof cjosToggleSelectMode === "function") cjosToggleSelectMode(false);// Robust Scroll & Highlight: Delay ensures layout is stable after overlay removal
                setTimeout(() => {
                    card.classList.add("merge-highlight");
                    card.scrollIntoView({ behavior: "smooth", block: "center" });
                }, 300);

                // Trigger the confirmation banner
                if (window.cjosHooks) window.cjosHooks.emit('onUpdate', data.new_id, data.entry);
                checkPendingMergeDeletion();
            } else {
                location.reload();
            }
        } else {
            window.openConfirm("Merge Failed", data.message, null, true, "OK", null);
            if(overlay) overlay.classList.remove("visible");
        }
    } catch (e) {
        window.openConfirm("Network Error", "Network Error: " + e, null, true, "OK", null);
        if(overlay) overlay.classList.remove("visible");
    }
}

// --- CONFIRMATION UI & HIGHLIGHT ---
function checkPendingMergeDeletion() {
    const rawIds = localStorage.getItem("cjos_pending_merge_delete");
    const newId = localStorage.getItem("cjos_new_merge_id");

    // 1. Handle Highlight & Scroll (Only on fresh page load/recovery)
    if (newId && !document.querySelector(".merge-highlight")) {
        // Wait for DOM
        setTimeout(() => {
            const newCheckbox = document.querySelector(`.custom-checkbox[data-id="${newId}"]`);
            if (newCheckbox) {
                const card = newCheckbox.closest(".card");
                if (card) {
                    card.classList.add("merge-highlight");
                    card.scrollIntoView({ behavior: "smooth", block: "center" });
                }
            }
        }, 500);
    }

    // 2. Show Popup if pending delete exists
    if (!rawIds) return;
    const ids = JSON.parse(rawIds);
    if (!ids || ids.length === 0) return;
    
    // Prevent duplicate banners
    if (document.getElementById("merge-delete-banner")) return;

    // Create Floating Banner
    const banner = document.createElement("div");
    banner.id = "merge-delete-banner";

    const title = document.createElement("div");
    title.innerHTML = `<strong>Merge Successful!</strong><br><span style="font-size:13px; color: var(--text-secondary);">The new entry is highlighted above. Do you want to delete the original ${ids.length} entries?</span>`;
    
    const btnRow = document.createElement("div");
    btnRow.style.cssText = "display:flex; gap:10px;";

    const removeHighlight = () => {
        const hCard = document.querySelector(".merge-highlight");
        if(hCard) hCard.classList.remove("merge-highlight");
        localStorage.removeItem("cjos_new_merge_id");
    };

    const btnKeep = document.createElement("button");
    btnKeep.innerText = "Keep Originals";
    btnKeep.style.cssText = "flex:1; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--btn-bg, #E5E5EA); font-weight:600; cursor:pointer; color:var(--btn-text, #1C1C1E);";
    btnKeep.onclick = () => {
        localStorage.removeItem("cjos_pending_merge_delete");
        removeHighlight();
        banner.remove();
    };

    const btnDelete = document.createElement("button");
    btnDelete.innerText = "Delete Originals";
    btnDelete.style.cssText = "flex:1; padding:12px; border-radius:10px; border:none; background:var(--danger, #FF3B30); font-weight:600; cursor:pointer; color:#fff;";
    btnDelete.onclick = async () => {
        const releaseScroll = (typeof window.soLockScroll === "function") ? window.soLockScroll(ids) : () => {};
        btnDelete.innerText = "Deleting...";
        btnDelete.style.opacity = "0.7";
        
        await window.sui.api("delete_merged_originals", { ids: ids }, { toast: false });
        
        // Update local data array (Data Integrity)
        ids.forEach(id => {
            const logIdx = logs.findIndex(l => l.id === id);
            if (logIdx !== -1) logs.splice(logIdx, 1);
        });

        // Remove from DOM immediately
        ids.forEach(id => {
            const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
            if(cb) {
                const card = cb.closest(".card");
                if(card) {
                    card.style.transition = "all 0.3s";
                    card.style.opacity = "0";
                    card.style.height = "0";
                    card.style.margin = "0";
                    setTimeout(() => card.remove(), 300);
                }
            }
        });
        
        localStorage.removeItem("cjos_pending_merge_delete");
        removeHighlight();
        banner.remove();
        if (window.cjosHooks) ids.forEach(id => window.cjosHooks.emit('onDelete', id));
        if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
        setTimeout(releaseScroll, 1000);
    };

    btnRow.appendChild(btnKeep);
    btnRow.appendChild(btnDelete);
    banner.appendChild(title);
    banner.appendChild(btnRow);
    
    document.body.appendChild(banner);
}
JS;
?>