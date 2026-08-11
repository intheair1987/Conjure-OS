<?php
// ==============================================================================
// PLUGIN: Timestamp Fixer
// DESCRIPTION: Repair Entry Dates.
if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'repair_timestamps') {
    header('Content-Type: application/json');
    $rows = $db->query("SELECT id FROM logs")->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    $stmt = $db->prepare("UPDATE logs SET date_display = :date, timestamp = :ts WHERE id = :id");
    foreach ($rows as $row) {
        $id = $row['id'];
        if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})$/', $id, $m)) {
            $dateStr = "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
            $ts = strtotime($dateStr);
            $stmt->execute([':date' => $dateStr, ':ts' => $ts, ':id' => $id]);
            $count++;
        }
    }
    echo json_encode(['status' => 'success', 'count' => $count]);
    exit;
}

// FRONTEND UI (Wrapper Removed)
$plugin_settings_map['TimestampFixer'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Repair Database Dates</label>
        <div class="setting-desc">
            Reset dates based on original filenames (YYYYMMDD_HHMMSS).
        </div>
        <button onclick="runTimestampRepair(this)" class="text-btn" style="
            background-color: var(--btn-bg); 
            color: var(--text-primary);
            width: 100%; 
            border-radius: 12px; 
            padding: 12px; 
            font-weight: 600;
            margin-top: 8px;
            border: 1px solid rgba(0,0,0,0.1);
        ">Fix All Dates</button>
    </div>
HTML;

// JS
$plugin_js .=  <<<'JS'
function runTimestampRepair(btn) {
    const originalText = btn.innerText;
    btn.innerText = "Repairing...";
    btn.style.opacity = "0.7";
    btn.style.pointerEvents = "none";
    fetch("?plugin_action=repair_timestamps").then(res => res.json()).then(data => {
        btn.innerText = "Fixed " + data.count + " Entries!";
        btn.style.backgroundColor = "var(--primary)";
        btn.style.color = "white";
        setTimeout(() => { location.reload(); }, 1000);
    }).catch(err => { window.openConfirm("Repair Error", "Error: " + err, null, true, "OK", null); btn.innerText = originalText; btn.style.pointerEvents = "auto"; });
}
JS;
?>