<?php
// ==============================================================================
// PLUGIN: Database Manager
// DESCRIPTION: Fix & Sync Database.
// Purpose: Rebuilds the database from physical files and repairs the filesystem.
// ==============================================================================

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'db_manager_rebuild') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $audio_dir = CJOS_PATH_STORAGE . '/audio';
    $text_dir = CJOS_PATH_STORAGE . '/text';
    
    $stats = ['created' => 0, 'updated' => 0, 'files_restored' => 0];

    try {
        // 1. PHASE 1: DB -> DISK (Restore missing .txt files from DB content)
        $all_logs = $db->query("SELECT id, transcription FROM logs")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all_logs as $log) {
            $txt_file = $text_dir . '/' . $log['id'] . '.txt';
$isPlaceholder = ($log['transcription'] === '(Pending Transcription...)' || $log['transcription'] === '(Transcribing...)');
if (!file_exists($txt_file) && !empty($log['transcription']) && !$isPlaceholder) {
    file_put_contents($txt_file, $log['transcription']);$stats['files_restored']++;
            }
        }

        // 2. PHASE 2: DISK -> DB (Sync DB to match physical files)
        $text_files = glob($text_dir . '/*.txt');
        foreach ($text_files as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            $content = file_get_contents($file);
            
            // Determine Audio Path
            $audio_path = 'text_only';
            foreach (['webm', 'mp4', 'm4a', 'wav', 'mp3'] as $ext) {
                if (file_exists($audio_dir . '/' . $id . '.' . $ext)) {
                    $audio_path = 'recordings/audio/' . $id . '.' . $ext;
                    break;
                }
            }

            // Check if exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM logs WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() == 0) {
                // Create missing entry
                $timestamp = strtotime(str_replace('_', ' ', $id)) ?: filemtime($file);
                $date_display = date('Y-m-d H:i:s', $timestamp);
                $db->prepare("INSERT INTO logs (id, date_display, audio_path, transcription, timestamp) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$id, $date_display, $audio_path, $content, $timestamp]);
                $stats['created']++;
            } else {
                // Update existing to match disk truth
                $db->prepare("UPDATE logs SET transcription = ?, audio_path = ? WHERE id = ?")
                   ->execute([$content, $audio_path, $id]);
                $stats['updated']++;
            }
        }

        // 3. PHASE 3: AUDIO -> DB (Identify audio files with no TXT/DB entry)
        $audio_files = glob($audio_dir . '/*.{webm,mp4,m4a,wav,mp3}', GLOB_BRACE);
        foreach ($audio_files as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            $txt_file = $text_dir . '/' . $id . '.txt';
            
            // Check if this audio is already represented in the DB
            $stmt = $db->prepare("SELECT COUNT(*) FROM logs WHERE id = ?");
            $stmt->execute([$id]);
            $existsInDb = ($stmt->fetchColumn() > 0);

            // If it's not in the DB and has no TXT, it's a "Lost Recording"
            if (!$existsInDb && !file_exists($txt_file)) {
                $timestamp = strtotime(str_replace('_', ' ', $id)) ?: filemtime($file);
                $date_display = date('Y-m-d H:i:s', $timestamp);
                $rel_audio_path = 'recordings/audio/' . basename($file);
                
                $db->prepare("INSERT INTO logs (id, date_display, audio_path, transcription, timestamp) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$id, $date_display, $rel_audio_path, "(Pending Transcription...)", $timestamp]);
                $stats['created']++;
            }
        }

        echo json_encode(['status' => 'success', 'stats' => $stats]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'fix_db_paths') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    try {
        // 1. Update Audio Paths (Case Sensitivity)
        $stmtAudio = $db->prepare("UPDATE logs SET audio_path = REPLACE(audio_path, 'Recordings/audio/', 'recordings/audio/') WHERE audio_path LIKE 'Recordings/audio/%'");
        $stmtAudio->execute();
        $audioCount = $stmtAudio->rowCount();

        // 2. Update Missing 'audio/' segment (Fixes recent broken recordings)
        $stmtMissing = $db->prepare("UPDATE logs SET audio_path = REPLACE(audio_path, 'recordings/', 'recordings/audio/') WHERE audio_path LIKE 'recordings/%' AND audio_path NOT LIKE 'recordings/audio/%'");
        $stmtMissing->execute();
        $audioCount += $stmtMissing->rowCount();

        // 2. Update Text Only Markers (Just in case specific logic relies on it, though 'text_only' usually stays same)
        // No change needed for 'text_only' string.

        echo json_encode(['status' => 'success', 'updated' => $audioCount]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

$plugin_settings_map['DatabaseManager'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Sync & Rebuild Database</label>
        <div class="setting-desc">Matches the database to your physical folders. It scans for <strong>orphaned audio</strong> (files with no text) and restores missing .txt files from the DB to ensure your filesystem is the "Source of Truth".</div>
        <button onclick="runDbRebuild()" id="btn-db-rebuild" class="text-btn" style="background:var(--primary); color:white; width:100%; border-radius:12px; padding:12px; font-weight:600; margin-top:8px; box-shadow: 0 4px 12px rgba(0,122,255,0.2);">Sync with Filesystem</button>
    </div>

    <div class="setting-item vertical" style="border-top: 1px solid var(--border-color); padding-top:16px;">
        <label class="setting-label">Path Repair</label>
        <div class="setting-desc">Fixes case-sensitivity and missing folder segments in database paths.</div>
        <button onclick="runDbPathFix()" id="btn-db-fix" class="text-btn" style="background:var(--btn-bg); color:var(--text-primary); width:100%; border-radius:12px; padding:12px; font-weight:600; margin-top:8px; border: 1px solid var(--border-color);">Run Path Migration</button>
    </div>
HTML;

$plugin_js .= <<<'JS'
window.runDbRebuild = async function() {
    window.openConfirm("Database Sync", "This will synchronize your database with the recordings folder.\n\n1. Missing .txt files will be restored from the DB.\n2. DB entries will be updated to match physical files.\n\nProceed?", async () => {
        const btn = document.getElementById("btn-db-rebuild");
    const oldText = btn.innerText;
    btn.innerText = "Synchronizing...";
    btn.disabled = true;
    
        try {
            const data = await window.sui.api("db_manager_rebuild", {}, { toast: false });
            if(data) {
                window.openConfirm("Sync Complete", `Sync Complete!\n- Files Restored: ${data.stats.files_restored}\n- Entries Created: ${data.stats.created}\n- Entries Updated: ${data.stats.updated}`, () => {
                    location.reload();
                }, false, "Reload", null);
            } else {
                window.openConfirm("Error", "Error: " + data.message, null, false, "OK", null);
                btn.innerText = oldText;
                btn.disabled = false;
            }
        } catch(e) {
            window.openConfirm("Connection Error", "Connection Error", null, false, "OK", null);
            btn.innerText = oldText;
            btn.disabled = false;
        }
    });
};

window.runDbPathFix = async function() {
    window.openConfirm("Path Migration", "Update database paths now?", async () => {
        const btn = document.getElementById("btn-db-fix");
        btn.innerText = "Migrating...";
        
        try {
            const data = await window.sui.api("fix_db_paths", {}, { toast: false });
            if(data) {
                window.openConfirm("Migration Complete", `Migration Complete! Updated ${data.updated} entries.`, null, false, "OK", null);
                btn.innerText = "Done";
                btn.disabled = true;
                btn.style.background = "#34C759";
            } else {
                window.openConfirm("Error", "Error: " + data.message, null, false, "OK", null);
                btn.innerText = "Retry";
            }
        } catch(e) {
            window.openConfirm("Connection Error", "Connection Error", null, false, "OK", null);
            btn.innerText = "Retry";
        }
    });
};
JS;