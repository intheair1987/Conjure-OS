<?php
// ==============================================================================
// PLUGIN: Patch History
// DESCRIPTION: Persistently stores raw patch text from successful commits.
// ==============================================================================

$ph_history_file = CJOS_PATH_DATA . '/patch-history-private.json';

// --- BACKEND API ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'ph_save') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $raw = $_POST['raw'] ?? '';
        if (empty($raw)) {
            echo json_encode(['status' => 'error', 'message' => 'No content to save.']);
            exit;
        }

        $history = file_exists($ph_history_file) ? json_decode(file_get_contents($ph_history_file), true) : [];
        if (!is_array($history)) $history = [];

        // Duplicate Check: Discard if exact raw text already exists
        foreach ($history as $item) {
            if (isset($item['raw']) && $item['raw'] === $raw) {
                echo json_encode(['status' => 'success', 'message' => 'Duplicate ignored.']);
                exit;
            }
        }

        // Extract Edit Log Summary if present
        $summary = '';
        if (preg_match('/#ACTION:\s*edit_log[\s\S]*?#REPLACE:\s*([\s\S]*?)\n#END/i', $raw, $matches)) {
            $summary = trim($matches[1]);
        }

        array_unshift($history, [
            'id' => uniqid(),
            'timestamp' => date('Y-m-d H:i:s'),
            'raw' => $raw,
            'summary' => $summary,
            'note' => ''
        ]);

        // Keep last 100 entries
        if (count($history) > 100) $history = array_slice($history, 0, 100);

        file_put_contents($ph_history_file, json_encode($history, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['plugin_action'] === 'ph_list') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $history = file_exists($ph_history_file) ? json_decode(file_get_contents($ph_history_file), true) : [];
        
        $latest_log_date = '';
        $state_file = CJOS_PATH_DATA . '/edit-log-state.json';
        if (file_exists($state_file)) {
            $state = json_decode(file_get_contents($state_file), true);
            if (!empty($state) && is_array($state)) {
                $latest_log_date = $state[0]['date'] ?? '';
            }
        }

        echo json_encode([
            'status' => 'success', 
            'history' => $history, 
            'latest_log_date' => $latest_log_date
        ]);
        exit;
    }

    if ($_POST['plugin_action'] === 'ph_delete') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $id = $_POST['id'] ?? '';
        $history = file_exists($ph_history_file) ? json_decode(file_get_contents($ph_history_file), true) : [];
        $history = array_values(array_filter($history, fn($item) => $item['id'] !== $id));
        file_put_contents($ph_history_file, json_encode($history, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['plugin_action'] === 'ph_update_note') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $id = $_POST['id'] ?? '';
        $note = $_POST['note'] ?? '';
        $history = file_exists($ph_history_file) ? json_decode(file_get_contents($ph_history_file), true) : [];
        foreach ($history as &$item) {
            if ($item['id'] === $id) {
                $item['note'] = $note;
                break;
            }
        }
        file_put_contents($ph_history_file, json_encode($history, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['plugin_action'] === 'ph_clear_all') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        file_put_contents($ph_history_file, json_encode([], JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- FRONTEND JS ---
$plugin_js .= <<<'JS'
window.phSavePatch = async function(rawText) {
    if (!rawText || !rawText.trim()) return;
    try {
        await window.sui.api('ph_save', { raw: rawText }, { toast: false });
        console.log("[PH] Patch saved to history.");
    } catch (e) {
        console.error("[PH] Failed to save patch history:", e);
    }
};

window.phOpenStudio = function() {
    window.sui.openStudio({
        id: 'ph-studio',
        title: 'Patch History',
        content: '<div id="ph-list-container" style="padding:16px; display:flex; flex-direction:column; gap:12px;">Loading history...</div>',
        onSetup: async (contentBox, overlay) => {
            const actions = overlay.querySelector('.sui-studio-actions');
            if (actions) {
                actions.innerHTML = `
                    <button onclick="phClearAll()" style="background:rgba(255,59,48,0.1); border:none; width:32px; height:32px; border-radius:50%; color:var(--danger); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                        <span data-sui-icon="trash" data-sui-size="18"></span>
                    </button>
                `;
                window.suiHydrateIcons(actions);
            }
            window.phRefreshList();
        }
    });
};

window.phViewContent = async function(id) {
    try {
        const data = await window.sui.api('ph_list', {}, { toast: false });
        const item = data.history.find(i => i.id === id);
        if (item) {
            window.openConfirm("Patch Content", `
                <div style="font-family:monospace; font-size:11px; background:var(--bg-color); color:var(--text-primary); padding:12px; border-radius:10px; border:1px solid var(--border-color); white-space:pre-wrap; max-height:400px; overflow-y:auto; text-align:left;">${escapeHtml(item.raw)}</div>
            `, null, false, "Close", null);
        }
    } catch (e) {
        window.sui.toast("Failed to load content");
    }
};

window.phRefreshList = async function() {
    const container = document.getElementById('ph-list-container');
    if (!container) return;

    try {
        const data = await window.sui.api('ph_list', {}, { toast: false });
        if (data.status === 'success') {
            if (data.history.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:40px; opacity:0.5;">No history found.</div>';
                return;
            }

            const latestLogDate = data.latest_log_date || '';

            container.innerHTML = data.history.map(item => {
                // Convert timestamps to Unix time (ms) for accurate comparison with a buffer
                // We replace the space with 'T' to ensure cross-browser Date parsing compatibility
                const patchTime = new Date(item.timestamp.replace(' ', 'T')).getTime();
                const logTime = latestLogDate ? new Date(latestLogDate.replace(' ', 'T')).getTime() : 0;
                
                // Logic: Dim if the patch is older than the log, OR if it happened within 5 seconds of the log
                // (Accounting for the delay between server-side commit and client-side history save)
                const isOld = logTime > 0 && patchTime <= (logTime + 5000);
                const dimStyle = isOld ? 'opacity: 0.5; filter: grayscale(0.5);' : '';
                
                const firstLine = item.raw.trim().split('\n')[0].substring(0, 60);
                const displayNote = item.note ? `<div style="font-size:11px; color:var(--primary); margin-top:4px; font-weight:600;">📝 ${item.note}</div>` : '';
                const displaySummary = item.summary ? `<div style="font-size:12px; color:var(--text-primary); background:var(--ai-accent-bg); border-left:3px solid var(--ai-accent); padding:8px; border-radius:6px; margin-top:4px; margin-bottom:4px; line-height:1.4;">${escapeHtml(item.summary)}</div>` : '';
                
                return `
                    <div class="ph-item-card" style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:12px; display:flex; flex-direction:column; gap:8px; transition: all 0.3s; ${dimStyle}">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                            <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">${item.timestamp}</div>
                            <div style="display:flex; gap:6px;">
                                <button onclick="phViewContent('${item.id}')" style="background:none; border:none; color:var(--primary); cursor:pointer; padding:2px;"><span data-sui-icon="eye" data-sui-size="14"></span></button>
                                <button onclick="phUpdateNote('${item.id}', '${item.note.replace(/'/g, "\\'")}')" style="background:none; border:none; color:var(--text-secondary); cursor:pointer; padding:2px;"><span data-sui-icon="edit-3" data-sui-size="14"></span></button>
                                <button onclick="phDeleteEntry('${item.id}')" style="background:none; border:none; color:var(--danger); cursor:pointer; padding:2px;"><span data-sui-icon="trash-2" data-sui-size="14"></span></button>
                            </div>
                        </div>
                        <div style="font-family:monospace; font-size:11px; background:rgba(0,0,0,0.03); padding:8px; border-radius:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; border:1px solid rgba(0,0,0,0.02);">
                            ${escapeHtml(firstLine)}...
                        </div>
                        ${displaySummary}
                        ${displayNote}
                        <button onclick="phRestoreEntry('${item.id}')" class="text-btn" style="width:100%; background:var(--primary); color:var(--primary-text); border-radius:8px; padding:8px; font-size:11px; font-weight:700; margin-top:4px;">Restore to Patcher</button>
                    </div>
                `;
            }).join('');
            if (window.suiHydrateIcons) window.suiHydrateIcons(container);
        }
    } catch (e) {
        container.innerHTML = '<div style="color:var(--danger);">Failed to load history.</div>';
    }
};

window.phRestoreEntry = async function(id) {
    try {
        const data = await window.sui.api('ph_list', {}, { toast: false });
        const item = data.history.find(i => i.id === id);
        if (item) {
            const patchInput = document.getElementById('cp-input');
            if (patchInput) {
                // 1. Restore the text
                patchInput.value = item.raw;

                // 1.5 Update Patcher UI (Clear Button visibility)
                if (typeof window.cpUpdateClearBtn === 'function') {
                    window.cpUpdateClearBtn();
                }
                
                // 2. Close the History Studio using the explicit SUI API
                if (window.sui && window.sui.closeStudio) {
                    window.sui.closeStudio('ph-studio');
                }

                // 3. Trigger the scan
                if (typeof cpVerifyBatch === 'function') cpVerifyBatch();

                window.sui.toast("Patch Restored");
            } else {
                window.sui.toast("Patcher not found");
            }
        }
    } catch (e) {
        window.sui.toast("Restore failed");
    }
};

window.phUpdateNote = function(id, currentNote) {
    window.openConfirm("Entry Note", `<input type="text" id="ph-note-input" value="${currentNote}" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text);" placeholder="Enter note...">`, async () => {
        const note = document.getElementById('ph-note-input').value;
        await window.sui.api('ph_update_note', { id, note });
        window.phRefreshList();
    }, true, "Save Note", "Cancel");
};

window.phDeleteEntry = function(id) {
    window.openConfirm("Delete Entry", "Are you sure you want to remove this from history?", async () => {
        await window.sui.api('ph_delete', { id });
        window.phRefreshList();
    });
};

window.phClearAll = function() {
    window.openConfirm("Clear All History", "Are you sure you want to permanently delete all patch history? This action cannot be undone.", async () => {
        await window.sui.api('ph_clear_all');
        window.phRefreshList();
    }, true);
};
JS;
?>