<?php
// ==============================================================================
// PLUGIN: Demo Mode
// DESCRIPTION: Contained Sandbox Environment.
// Swaps the primary database for a demo-specific one in CJOS_PATH_DATA/demo/.
// ==============================================================================

if (isset($_POST['plugin_action'])) {
    
    // TOGGLE STATUS (SERVER SIDE)
    if ($_POST['plugin_action'] === 'dm_set_status') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $enabled = $_POST['enabled'] === 'true';
        $file = CJOS_PATH_DATA . '/demo-mode-private.json';
        file_put_contents($file, json_encode(['enabled' => $enabled]));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // SEED DATABASE
    if ($_POST['plugin_action'] === 'dm_seed_data') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        // Only seed if the demo database is empty
        $count = $db->query("SELECT COUNT(*) FROM logs")->fetchColumn();
        if ($count == 0) {
            $now = time();
            
            // 1. Create Folders
            $db->exec("INSERT INTO folders (name, is_pinned, created_at, updated_at) VALUES ('Onboarding', 1, $now, $now)");
            $onboardingFolderId = $db->lastInsertId();

            $db->exec("INSERT INTO folders (name, is_pinned, created_at, updated_at) VALUES ('Demo Memos', 1, $now - 10, $now - 10)");
            $demoFolderId = $db->lastInsertId();

            // 2. Unsorted Root Stream Notes
            $unsorted_notes = [
                [
                    'id' => date('Ymd_His', $now),
                    'ts' => $now,
                    'text' => "👋 Welcome to Conjure OS!\n\nYou are currently exploring in Demo Sandbox Mode.\n\n💡 Quick Tip: Notes with long text automatically truncate to keep your stream clean. You can tap the left or right edges of any note card (Side-Tap) or tap the \"Read More\" button to expand and collapse the full text!\n\nTo get started with the full guide, scroll the folder bar above and tap on the \"Onboarding\" folder. Feel free to explore freely or test out features anytime!"
                ],
                [
                    'id' => date('Ymd_His', $now - 10),
                    'ts' => $now - 10,
                    'text' => "☕ Catching Up with Sarah & Marcus\n\nMet Sarah and Marcus for coffee at the corner café today! We're planning a weekend hiking trip up the mountain next Saturday if the weather stays clear. Need to group text everyone on Thursday to coordinate ride shares and snacks."
                ],
                [
                    'id' => date('Ymd_His', $now - 20),
                    'ts' => $now - 20,
                    'text' => "💼 Work Reflection: Design Review Prep\n\nKey points for tomorrow's team sync:\n1. Walk through the new client presentation slides.\n2. Get feedback on the project timeline adjustments.\n3. Keep the meeting focused and wrap up under 30 minutes so everyone has time to focus on deep work."
                ],
                [
                    'id' => date('Ymd_His', $now - 30),
                    'ts' => $now - 30,
                    'text' => "🌱 Morning Routine & Wellness Goal\n\nStarted reading 15 pages of a book before checking my phone in the morning—it really sets a calm mood for the day. Also aiming for 8,000 steps daily this week!"
                ],
                [
                    'id' => date('Ymd_His', $now - 40),
                    'ts' => $now - 40,
                    'text' => "🛒 Weekend Groceries & Errands\n\n• Fresh sourdough bread & avocados\n• Oat milk & Ethiopian coffee beans\n• Pick up dry cleaning on 5th Street\n• Birthday card for Dad's party on Sunday"
                ]
            ];

            // 3. Onboarding Folder Notes (Step-by-step)
            $onboarding_notes = [
                [
                    'id' => date('Ymd_His', $now - 100),
                    'ts' => $now - 100,
                    'text' => "🚀 Step 1: Getting Started & Exiting Demo Mode\n\nWelcome! You can freely explore Conjure OS first or follow these step-by-step guide notes.\n\n• Mark as Read: Double-tap on any note card to mark it as read or interacted with.\n• How to Exit Demo Mode: When you're done exploring and ready to use Conjure with your real data:\n  1. Tap the Settings icon in the top-right header.\n  2. Tap \"Show Hidden Plugins\" at the bottom of the settings overlay.\n  3. Type \"demo\" in the plugin search bar.\n  4. Toggle off the Demo Mode switch to enter your clean personal workspace!"
                ],
                [
                    'id' => date('Ymd_His', $now - 200),
                    'ts' => $now - 200,
                    'text' => "📱 Step 2: Viewports & Navigation\n\nConjure OS is a horizontal multi-page workspace.\n\n• Horizontal Swipe: Swipe left or right anywhere on the main screen to switch between your Log Stream and the Dashboard.\n• Folder Bar: Scroll the top folder bar horizontally to filter notes by folder or return to Unsorted.\n• Command Bar / Record FAB: Use the bottom floating record control to dictate audio notes or trigger quick actions."
                ],
                [
                    'id' => date('Ymd_His', $now - 300),
                    'ts' => $now - 300,
                    'text' => "🖐️ Step 3: Gestures & Multi-Selection\n\nConjure relies on fluid, touch-first gestures:\n\n• Double-Tap: Double-tap any note to copy its text directly to your clipboard.\n• Long-Press: Long-press a note to enter Multi-Selection Mode. From here, you can select multiple notes to copy, organize, or delete.\n• Merge Notes: In Selection Mode, tap the Merge icon (Y-shaped icon) on the bottom action bar to combine several notes into a single cohesive entry."
                ],
                [
                    'id' => date('Ymd_His', $now - 400),
                    'ts' => $now - 400,
                    'text' => "🧠 Step 4: AI Brain & API Keys\n\nConjure uses AI for voice dictation, smart suggestions, and chat assistance.\n\nTo connect your API keys:\n1. Open Settings (top-right header).\n2. Expand Hidden Plugins and search for \"OpenRouter\" or \"Conjure Core\".\n3. Enter your API keys to enable live transcription and autonomous AI features."
                ],
                [
                    'id' => date('Ymd_His', $now - 500),
                    'ts' => $now - 500,
                    'text' => "⚡ Step 5: The Self-Modification Loop\n\nConjure is a sovereign OS capable of modifying its own code!\n\n1. Quick Save: Long-press the Floppy Disk icon in the Patcher or Settings header to save a system checkpoint.\n2. Export Context: Open the Context Exporter or tap Export in the Patcher to grab system source code.\n3. Ask AI: Send the code context to Google AI Studio or your favorite LLM and ask for a feature or fix.\n4. Patch: Paste the returned Protocol V10 patch block into the Surgical Patcher and click \"Analyze & Stage\" -> \"Commit All\" to apply changes live!"
                ]
            ];

            // 4. General Demo Memos
            $demo_memos = [
                [
                    'id' => date('Ymd_His', $now - 1000),
                    'ts' => $now - 1000,
                    'text' => "Voice Memo: Review the architecture documentation for the new API Bridge refactor."
                ],
                [
                    'id' => date('Ymd_His', $now - 2000),
                    'ts' => $now - 2000,
                    'text' => "Meeting Notes: Discussed the implementation of the new 'Stacks' feature. The UI should feel liquid and tactile."
                ]
            ];

            $stmt = $db->prepare("INSERT INTO logs (id, date_display, audio_path, transcription, timestamp) VALUES (?, ?, 'text_only', ?, ?)");
            $stmtMap = $db->prepare("INSERT INTO folder_map (log_id, folder_id) VALUES (?, ?)");

            // Insert Unsorted Notes (No folder mapping)
            foreach ($unsorted_notes as $uNote) {
                $stmt->execute([$uNote['id'], date('Y-m-d H:i:s', $uNote['ts']), $uNote['text'], $uNote['ts']]);
            }

            // Insert Onboarding Notes & Map to Onboarding Folder
            foreach ($onboarding_notes as $note) {
                $stmt->execute([$note['id'], date('Y-m-d H:i:s', $note['ts']), $note['text'], $note['ts']]);
                $stmtMap->execute([$note['id'], $onboardingFolderId]);
            }

            // Insert Demo Memos & Map to Demo Memos Folder
            foreach ($demo_memos as $memo) {
                $stmt->execute([$memo['id'], date('Y-m-d H:i:s', $memo['ts']), $memo['text'], $memo['ts']]);
                $stmtMap->execute([$memo['id'], $demoFolderId]);
            }

            // 5. Create Demo To-Do List
            $db->exec("INSERT INTO todo_lists (name, is_starred, created_at) VALUES ('Getting Started', 1, $now)");
            $listId = $db->lastInsertId();
            
            $items = ["Open Onboarding Folder", "Try Double-Tap to Mark Read", "Try Selection Mode", "Explore Settings Overlay"];
            $stmtTodo = $db->prepare("INSERT INTO todo_items (list_id, log_id, task_text, is_done) VALUES (?, ?, ?, ?)");
            foreach ($items as $idx => $task) {
                $stmtTodo->execute([$listId, $onboarding_notes[0]['id'], $task, ($idx == 0 ? 1 : 0)]);
            }
        }
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // RESET SANDBOX
    if ($_POST['plugin_action'] === 'dm_reset_sandbox') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        // Access globals set by app.php
        global $is_demo_mode, $rec_dir, $trans_dir, $db;

        if (!$is_demo_mode) {
             echo json_encode(['status' => 'error', 'message' => 'Not in Demo Mode']);
             exit;
        }

        // 1. Truncate DB Tables
        $tables = ['logs', 'folders', 'folder_map', 'todo_lists', 'todo_items', 'stacks', 'stack_members', 'ai_suggestions', 'ai_audit_log'];
        foreach ($tables as $t) {
            try { $db->exec("DELETE FROM $t"); } catch(Exception $e) {}
        }
        
        // 2. Clear Files
        // Use glob to find files in the demo directories
        $files = array_merge(
            glob($rec_dir . '/*'), 
            glob($trans_dir . '/*')
        );
        foreach ($files as $file) {
            if (is_file($file)) @unlink($file);
        }

        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- SETTINGS UI ---
// Check global variable set by app.php
$dm_active = isset($is_demo_mode) ? $is_demo_mode : false;

// --- DATA BRIDGE ---
$dm_bridge_json = json_encode(['active' => $dm_active]);
$plugin_js .= "\nwindow.__DM_BRIDGE__ = $dm_bridge_json;\n";

$plugin_settings_map['DemoMode'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Sandbox Mode</label>
        <div class="setting-desc">
            Toggle <strong>Demo Mode</strong> to hide your real notes and data. 
            All changes made in this mode are stored in a separate database file.
        </div>
        
        <div style="margin-top:12px; padding:16px; border-radius:14px; background:var(--btn-bg); border:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:10px; height:10px; border-radius:50%; background: #34C759; box-shadow: 0 0 8px #34C759; opacity: 0.4;" id="dm-status-dot"></div>
                <span style="font-weight:700; font-size:14px; color:var(--text-primary);">Demo Sandbox</span>
            </div>
            <label class="switch">
                <input type="checkbox" id="dm-toggle-input" onchange="dmToggle(this.checked)">
                <span class="slider"></span>
            </label>
        </div>

        <div id="dm-reset-container" style="margin-top:12px; display:none;">
            <button onclick="dmReset()" class="text-btn" style="width:100%; color:var(--danger); border:1px solid var(--border-color); border-radius:12px; padding:12px; font-weight:600; background:rgba(255, 59, 48, 0.05);">
                Reset Sandbox Data
            </button>
            <div style="text-align:center; font-size:10px; color:var(--text-secondary); margin-top:6px; opacity:0.7;">
                Wipes all demo data and restores defaults.
            </div>
        </div>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- DEMO MODE JS ---

window.addEventListener("load", () => {
    // Read state from Data Bridge
    const toggle = document.getElementById("dm-toggle-input");
    const dot = document.getElementById("dm-status-dot");
    
    const isActive = window.__DM_BRIDGE__?.active || false;
    
    if (toggle) toggle.checked = isActive;
    if (dot) dot.style.opacity = isActive ? "1" : "0.2";

    if (isActive) {
        // Inject visual indicator into header
        const title = document.querySelector(".bar-title");
        if (title) {
            title.style.position = "relative";
            title.style.display = "inline-block";
            // right:-10px shifts the label past the end of the text
            title.innerHTML = 'Conjure<span style="position:absolute; top:3px; right:-10px; font-size:7px; background:var(--primary); color:white; padding:1px 3px; border-radius:3px; font-family:sans-serif; font-style:normal; letter-spacing:0.5px; font-weight:900; line-height:1; white-space:nowrap; box-shadow: 1px 1px 3px rgba(0,0,0,0.15); pointer-events:none; z-index:1000;">DEMO</span>';
        }
        // Show reset button
        const resetCont = document.getElementById("dm-reset-container");
        if (resetCont) resetCont.style.display = "block";
    }
});

window.dmReset = async function() {
    window.openConfirm("Reset Sandbox", "Are you sure? This will wipe all data in the Sandbox and restore the default examples.", async () => {
        if (window.sui && window.sui.toast) {
            window.sui.toast("Resetting Sandbox...", { plugin: "DemoMode", caller: "dmReset" });
        }
        try {
            // Clear all folder filter state keys to prevent stale filter persistence
            localStorage.removeItem('cjos_so_fid');
            localStorage.removeItem('cjos_folder_main_state');
            localStorage.removeItem('cjos_folder_toggle_memory');
            localStorage.removeItem('cjos_so_current_folder');
            localStorage.removeItem('cjos_so_search_query');
            localStorage.removeItem('cjos_so_filter_state');
            localStorage.removeItem('cjos_lh_last_folder');

            // 1. Wipe
            await window.sui.api("dm_reset_sandbox", {}, { toast: false });
            // 2. Re-Seed
            await window.sui.api("dm_seed_data", {}, { toast: false });
            
            location.reload();
        } catch(e) {
            window.openConfirm("Reset Failed", "Reset failed: " + e.message, null, false, "OK", null);
        }
    }, true);
};

window.dmToggle = async function(enabled) {
    // --- RACE CONDITION PREVENTER ---
    // Immediately lock LiveSync and kill the interval to prevent "Entries Synced" ghost toasts
    window.lsIsProcessing = true; 
    if (window.lsInterval) clearInterval(window.lsInterval);

    if (window.sui && window.sui.toast) {
        window.sui.toast(enabled ? "Entering Sandbox..." : "Exiting Sandbox...", { plugin: "DemoMode", caller: "dmToggle", metrics: { enabled: enabled } });
    }

    // 1. Set Server State
    await window.sui.api("dm_set_status", { enabled: enabled }, { toast: false });

    // 1.5 Clear UI Filters (Prevent stale folder IDs or searches from hiding logs in the new mode)
    localStorage.removeItem('cjos_so_fid');
    localStorage.removeItem('cjos_folder_main_state');
    localStorage.removeItem('cjos_folder_toggle_memory');
    localStorage.removeItem('cjos_so_current_folder');
    localStorage.removeItem('cjos_so_search_query');
    localStorage.removeItem('cjos_so_filter_state');
    localStorage.removeItem('cjos_lh_last_folder');

    // 2. Trigger Seeding if enabling
    if (enabled) {
        try {
            await window.sui.api("dm_seed_data", {}, { toast: false });
        } catch(e) {}
    }

    // 3. Reload to switch DB context
    location.reload();
};
JS;
?>