<?php
require_once dirname(__DIR__) . '/paths.php';

// 1. AUTHENTICATION CHECK (Mirroring recovery.php logic)
function is_authed() {
    // SECURITY BYPASS: Enabled for development/multi-device testing.
    return true;
}

if (!is_authed()) {
    die("ACCESS DENIED: Please log in to the main app first.");
}

// Release session locks immediately to prevent blocking concurrent AJAX progress requests
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// 1.1 AJAX PROGRESS TELEMETRY ENDPOINT
if (isset($_GET['ajax']) && $_GET['ajax'] === 'progress') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $track_file = $_GET['track_file'] ?? '';
    if (!$track_file || strpos($track_file, '..') !== false) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file']);
        exit;
    }
    
    $backupDir = CJOS_PATH_APP . '/backups/checkpoints';
    $fullPath = $backupDir . '/' . basename($track_file);
    
    $size = 0;
    $found_file = '';
    
    clearstatcache();
    if (file_exists($fullPath)) {
        $size = filesize($fullPath);
        $found_file = basename($fullPath);
    } else {
        // Fallback: look for temporary or part files matching the timestamp
        $timestamp = $_GET['timestamp'] ?? '';
        if ($timestamp) {
            $files = glob($backupDir . "/*{$timestamp}*");
            foreach ($files as $f) {
                if (file_exists($f)) {
                    $s = filesize($f);
                    if ($s > $size) {
                        $size = $s;
                        $found_file = basename($f);
                    }
                }
            }
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'size' => $size,
        'size_formatted' => round($size / (1024 * 1024), 2) . ' MB',
        'file' => $found_file
    ]);
    exit;
}

// 2. INITIALIZE STREAMING
set_time_limit(0);
ini_set('memory_limit', '1024M');

// Disable server-side compression and buffering
header('Content-Encoding: none');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');

// Clear all existing buffers
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

$action = $_GET['action'] ?? 'create';
$name = $_GET['name'] ?? 'Manual';
$file = $_GET['file'] ?? ''; // For restore

// --- SYSTEM TELEMETRY LOGIC ---
function get_dir_size($path) {
    $size = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) $size += $f->getSize();
    } catch (Exception $e) {}
    return $size;
}

$backupDir = CJOS_PATH_APP . '/backups/checkpoints';

$timestamp = date('Ymd_His');
$forceMajor = ($_GET['force_major'] ?? '0') === '1';
$type = $forceMajor ? 'major' : 'auto';

if ($type === 'auto') {
    $majors = glob($backupDir . "/MAJOR_*.zip");
    $predictedType = empty($majors) ? 'major' : 'diff';
} else {
    $predictedType = $type;
}
$predictedPrefix = ($predictedType === 'major') ? 'MAJOR_' : 'DIFF_';
$cleanLabel = str_replace(' ', '', ucwords(preg_replace('/[^a-zA-Z0-9]/', ' ', $name))) ?: "Manual";
$predictedFilename = $predictedPrefix . $timestamp . "_" . $cleanLabel . ".zip";
?>
<!DOCTYPE html>
<html>
<head>
    <title>System Worker: <?php echo strtoupper($action); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root {
            --bg: #0d1117;
            --term-bg: #161b22;
            --text: #c9d1d9;
            --accent: #34c759;
            --info: #58a6ff;
            --warn: #d29922;
            --error: #f85149;
            --border: #30363d;
        }
        body { 
            background: var(--bg); 
            color: var(--text); 
            font-family: 'SF Mono', 'Fira Code', 'JetBrains Mono', monospace; 
            margin: 0; 
            padding: 10px; 
            line-height: 1.5; 
            font-size: 13px;
            display: flex;
            flex-direction: column;
            min-height: 100dvh;
            box-sizing: border-box;
        }
        
        #terminal-window {
            max-width: 900px;
            width: 100%;
            margin: auto;
            background: var(--term-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
        }

        #terminal-header {
            background: #21262d;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1px solid var(--border);
        }
        .dot { width: 10px; height: 10px; border-radius: 50%; }
        .dot.red { background: #ff5f56; }
        .dot.yellow { background: #ffbd2e; }
        .dot.green { background: #27c93f; }
        .term-title { flex: 1; text-align: center; font-size: 11px; font-weight: 700; color: #8b949e; text-transform: uppercase; letter-spacing: 1px; }

        #terminal-body {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0; /* Critical for flex scrolling */
        }

        #terminal-status-block {
            flex-shrink: 0;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 20px;
        }

        #terminal-scroller {
            flex: 1;
            overflow-y: auto;
            padding-right: 10px;
            /* Custom scrollbar for aesthetics */
            scrollbar-width: thin;
            scrollbar-color: var(--border) transparent;
        }
        #terminal-scroller::-webkit-scrollbar { width: 4px; }
        #terminal-scroller::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }

        .line { margin-bottom: 6px; word-break: break-all; opacity: 0; animation: fadeIn 0.2s forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        
        .line.info { color: var(--info); }
        .line.warn { color: var(--warn); }
        .line.task { color: #8b949e; font-size: 11px; }
        .line.error { color: var(--error); font-weight: bold; }
        .line.success { color: var(--accent); font-weight: bold; }
        .timestamp { color: #484f58; margin-right: 10px; font-size: 11px; }
        
        /* Progress System */
        #progress-wrap { margin: 10px 0 0 0; }
        #progress-container { 
            background: #0d1117; 
            border: 1px solid var(--border); 
            height: 12px; 
            border-radius: 6px; 
            overflow: hidden; 
            position: relative; 
            margin-bottom: 8px;
        }
        #progress-bar { 
            height: 100%; 
            background: linear-gradient(90deg, #238636, #34c759); 
            width: 0%; 
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            box-shadow: 0 0 15px rgba(52, 199, 89, 0.3);
        }
        #progress-meta { display: flex; justify-content: space-between; font-size: 11px; font-weight: 700; color: #8b949e; }

        .cursor { display: inline-block; width: 8px; height: 15px; background: var(--accent); animation: blink 1s infinite; vertical-align: middle; margin-left: 4px; }
        @keyframes blink { 0%, 49% { opacity: 1; } 50%, 100% { opacity: 0; } }
        
        #footer { 
            margin-top: 0; 
            padding: 20px; 
            background: #161b22;
            border-top: 1px solid var(--border); 
            display: none; 
            text-align: center;
            animation: slideUp 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }

        .btn { 
            background: var(--accent); 
            color: #000; 
            border: none; 
            padding: 12px 24px; 
            border-radius: 8px; 
            cursor: pointer; 
            text-decoration: none; 
            font-weight: 800; 
            font-size: 13px;
            display: inline-block;
            transition: transform 0.2s, filter 0.2s;
        }
        .btn:active { transform: scale(0.95); }
        .btn:hover { filter: brightness(1.1); }

        @media (max-width: 600px) {
            body { padding: 0; }
            #terminal-window { border-radius: 0; border: none; height: 100dvh; }
            #terminal-body { padding: 15px; }
        }
    </style>
</head>
<body>

<div id="terminal-window">
    <div id="terminal-header">
        <div class="dot red"></div>
        <div class="dot yellow"></div>
        <div class="dot green"></div>
        <div class="term-title">System Worker: <?php echo strtoupper($action); ?></div>
    </div>

    <div id="terminal-body">
        <!-- FIXED STATUS BLOCK -->
        <div id="terminal-status-block">
            <div class="line info">>>> CONJURE KERNEL v1.0.4</div>
            <div class="line info">>>> STATUS: INITIALIZING <?php echo strtoupper($action); ?> PROTOCOL...</div>
            
            <div style="margin: 20px 0; padding: 18px 20px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); box-shadow: inset 0 0 20px rgba(0,0,0,0.2);">
                <div class="line info" style="font-size: 10px; font-weight: 800; letter-spacing: 1.5px; margin-bottom: 14px; opacity: 0.9;">[ SYSTEM TELEMETRY ]</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px 32px;">
                    <div style="font-size: 11px; color: #8b949e; font-weight: 700;">ROOT SIZE: <span id="stat-root-size" style="color:var(--text); margin-left:4px;">...</span></div>
                    <div style="font-size: 11px; color: #8b949e; font-weight: 700;">DISK FREE: <span id="stat-disk-free" style="color:var(--text); margin-left:4px;">...</span></div>
                    <div style="font-size: 11px; color: #8b949e; font-weight: 700;">MAJOR REF: <span id="stat-major-ref" style="color:var(--text); margin-left:4px;">...</span></div>
                    <div style="font-size: 11px; color: #8b949e; font-weight: 700;">DIFF CHAIN: <span id="stat-diff-chain" style="color:var(--text); margin-left:4px;">...</span></div>
                </div>
            </div>

            <div id="progress-wrap">
                <div id="progress-container">
                    <div id="progress-bar"></div>
                </div>
                <div id="progress-meta">
                    <span id="progress-task">Preparing...</span>
                    <span id="progress-text">0%</span>
                </div>
            </div>
        </div>

        <!-- SCROLLABLE LOG AREA -->
        <div id="terminal-scroller">
            <div id="output"></div>
            <div id="active-line" style="margin-top:10px; color:var(--accent); font-weight:bold;">
                <span style="margin-right:8px;">$</span><span id="current-task"></span><span class="cursor"></span>
            </div>
        </div>
    </div>

    <div id="footer">
        <?php if (isset($_GET['is_iframe'])): ?>
            <button onclick="window.parent.scCloseWorkerPortal(<?php echo $action === 'restore' ? 'true' : 'false'; ?>)" class="btn">CLOSE WORKER & RETURN</button>
        <?php else: ?>
            <a href="../index.php" class="btn">RETURN TO MAINFRAME</a>
        <?php endif; ?>
    </div>
</div>



<script>
    // Predicted backup file coordinates
    const TRACK_FILENAME = <?php echo json_encode($predictedFilename); ?>;
    const TRACK_TIMESTAMP = <?php echo json_encode($timestamp); ?>;
    const IS_CREATE_ACTION = <?php echo json_encode($action === 'create'); ?>;

    // Buffer Buster: Force browser to render the UI immediately
    // by sending 4KB of whitespace padding.
    /* <?php echo str_repeat(' ', 4096); ?> */

    const output = document.getElementById('output');
    const task = document.getElementById('current-task');

    // Ping Sentinel
    fetch('http://localhost:8001/sentinel.php').catch(e => {});
    function scrollToBottom() {
        const scroller = document.getElementById('terminal-scroller');
        if (scroller) scroller.scrollTop = scroller.scrollHeight;
    }

    function log(msg, type = '') {
        const div = document.createElement('div');
        div.className = 'line ' + type;
        const time = new Date().toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
        div.innerHTML = `<span class="timestamp">[${time}]</span><span>${msg}</span>`;
        output.appendChild(div);
        
        // Auto-scroll after browser paint
        requestAnimationFrame(() => scrollToBottom());
    }
    function setTask(msg) { 
        task.innerText = msg; 
        log(msg, 'task');
    }

    let pollInterval = null;
    let lastSize = 0;
    let stallCount = 0;

    let currentPct = 0;

    function startFileProgressPolling() {
        if (!IS_CREATE_ACTION || pollInterval) return;
        log("Activating compression telemetry tracking...", "info");
        
        pollInterval = setInterval(async () => {
            if (isFinished || currentPct === 100) {
                if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
                return;
            }
            try {
                const res = await fetch(`checkpoint_worker.php?ajax=progress&track_file=${encodeURIComponent(TRACK_FILENAME)}&timestamp=${TRACK_TIMESTAMP}`);
                const data = await res.json();
                if (isFinished || currentPct === 100) return;
                if (data.status === 'success' && data.size > 0) {
                    const sizeMb = (data.size / (1024 * 1024)).toFixed(2);
                    const isFinalZip = data.file === TRACK_FILENAME;
                    
                    if (isFinalZip) {
                        if (data.size === lastSize) {
                            stallCount++;
                            if (stallCount >= 4) { // Stable for 4 seconds
                                log(`Telemetry detected stable archive write: ${sizeMb} MB complete.`, "success");
                                log(`FILE: ${data.file}`, "task");
                                finish();
                                return;
                            }
                        } else {
                            lastSize = data.size;
                            stallCount = 0;
                        }
                    } else {
                        lastSize = data.size;
                        stallCount = 0;
                    }
                    
                    updateProgress(95, `COMPRESSING (${sizeMb} MB / ~${targetMb} MB est.)...`);
                    task.innerText = `Compressing and writing archive: ${sizeMb} MB / ~${targetMb} MB est.`;
                }
            } catch (e) {
                console.error("Progress polling error:", e);
            }
        }, 1000);
    }

    let targetMb = '???';
    function updateProgress(pct, label = '') {
        if (isFinished && pct < 100) return;
        if (currentPct === 100 && pct < 100) return;
        currentPct = pct;

        const bar = document.getElementById('progress-bar');
        const text = document.getElementById('progress-text');
        const taskLabel = document.getElementById('progress-task');
        bar.style.width = pct + '%';
        text.innerText = Math.round(pct) + '%';
        
        if (pct === 95 && label.includes('COMPRESSING_EST:')) {
            targetMb = label.split(':')[1];
            label = `COMPRESSING (0.00 MB / ~${targetMb} MB est.)...`;
            startFileProgressPolling();
        }
        
        if (label) taskLabel.innerText = label;

        if (pct === 100) {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }
    }

    let isFinished = false;
    function finish() {
        if (isFinished) return;
        isFinished = true;
        currentPct = 100;
        
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }

        document.getElementById('active-line').style.display = 'none';
        document.getElementById('footer').style.display = 'block';
        updateProgress(100, 'COMPLETE');
        
        // Final scroll to ensure archive size/filename are visible after layout shift
        setTimeout(scrollToBottom, 50);
    }

    function updateStat(id, val) {
        const el = document.getElementById(id);
        if (el) el.innerText = val;
    }
</script>

<?php
// --- EARLY FLUSH: Send the UI shell to the browser immediately ---
flush();

// --- TELEMETRY CALCULATION (Post-Flush) ---
$majors = glob($backupDir . "/MAJOR_*.zip");
$latestMajorSize = "0 MB";
$diffCount = 0;

if (!empty($majors)) {
    rsort($majors);
    $latestMajor = $majors[0];
    $latestMajorSize = round(filesize($latestMajor) / (1024 * 1024), 2) . " MB";
    $mTime = filemtime($latestMajor);
    $allDiffs = glob($backupDir . "/DIFF_*.zip");
    foreach($allDiffs as $d) { if (filemtime($d) > $mTime) $diffCount++; }
}

$freeSpace = round(disk_free_space(CJOS_PATH_ROOT) / (1024 * 1024 * 1024), 2) . " GB";
echo "<script>updateStat('stat-disk-free', '$freeSpace'); updateStat('stat-major-ref', '$latestMajorSize'); updateStat('stat-diff-chain', '$diffCount files');</script>";
flush();

$totalAppSize = round(get_dir_size(CJOS_PATH_ROOT) / (1024 * 1024), 2) . " MB";
echo "<script>updateStat('stat-root-size', '$totalAppSize');</script>";
flush();

function js_log($msg, $type = '') {
    echo "<script>log(" . json_encode($msg) . ", '$type');</script>";
    flush();
}
function js_task($msg) {
    echo "<script>setTask(" . json_encode($msg) . ");</script>";
    flush();
}
function js_progress($pct, $label = '') {
    echo "<script>updateProgress($pct, " . json_encode($label) . ");</script>";
    flush();
}

// Load Shared Logic
require_once __DIR__ . '/sc_logic_create.php';
require_once __DIR__ . '/sc_logic_restore.php';

$backupDir = CJOS_PATH_APP . '/backups/checkpoints';

// Progress Bridge: Maps core callback to worker JS functions
$workerCallback = function($type, $msg, $extra = null) {
    if ($type === 'log') js_log($msg, $extra ?: '');
    if ($type === 'task') js_task($msg);
    if ($type === 'progress') js_progress($msg, $extra ?: '');
};

if ($action === 'create') {
    // Reuse pre-generated timestamp to guarantee filename consistency
    $zipPath = $backupDir . "/{$timestamp}_{$name}.zip";
    
    $forceMajor = ($_GET['force_major'] ?? '0') === '1';
    $type = $forceMajor ? 'major' : 'auto';
    
    $clientState = $_COOKIE['cjos_client_state_bridge'] ?? null;
    $success = sc_perform_create($zipPath, $clientState, $workerCallback, $type);
    
    if ($success) {
        // Find the actual file (it might have a MAJOR_ or DIFF_ prefix added by the logic)
        $matches = glob($backupDir . "/*{$timestamp}*.zip");
        if (!empty($matches)) {
            $actualFile = $matches[0];
            $size = filesize($actualFile);
            $mb = round($size / (1024 * 1024), 2);
            js_log("FINAL ARCHIVE SIZE: $mb MB", "success");
            js_log("FILE: " . basename($actualFile), "task");
        }
    } else {
        js_log("ERROR: Checkpoint creation failed.", "error");
    }

} elseif ($action === 'restore') {
    if (!$file) {
        $files = glob($backupDir . "/*.zip");
        usort($files, function($a, $b) {
            preg_match('/(\d{8}_\d{6})/', $a, $mA);
            preg_match('/(\d{8}_\d{6})/', $b, $mB);
            return strcmp($mB[1] ?? '', $mA[1] ?? '');
        });
        $file = !empty($files) ? basename($files[0]) : '';
    }
    $zipPath = $backupDir . "/" . $file;

    if (!file_exists($zipPath)) {
        js_log("ERROR: Checkpoint file not found: $file", "error");
    } else {
        $protected = sc_get_protected_apps();
        $skipFolders =[];
            
        if (!empty($protected) && !isset($_POST['protection_confirmed'])) {
            js_log("SHIELDED APPS DETECTED", "warn");
            $ui = "<div style='background:rgba(210, 153, 34, 0.1); border:1px solid var(--warn); padding:15px; border-radius:8px; margin-top:10px; margin-bottom:15px;'>";
            $ui .= "<div style='color:var(--warn); font-weight:bold; margin-bottom:10px;'>PROTECTED APPS DETECTED</div>";
            $ui .= "<div style='margin-bottom:15px; color:#8b949e;'>Select apps to OVERWRITE (revert to snapshot). Unchecked apps will be SKIPPED and keep their current live data.</div>";
            $ui .= "<form method='POST' action='?action=restore&file=" . urlencode($file) . (isset($_GET['is_iframe']) ? "&is_iframe=1" : "") . "'>";
            $ui .= "<input type='hidden' name='protection_confirmed' value='1'>";
            foreach ($protected as $p) {
                $ui .= "<label style='display:flex; align-items:center; gap:8px; margin-bottom:10px; cursor:pointer; color:var(--text);'><input type='checkbox' name='overwrites[]' value='" . htmlspecialchars($p) . "' style='width:16px; height:16px; accent-color:var(--warn);'> Overwrite <strong>$p</strong></label>";
            }
            $ui .= "<button type='submit' class='btn' style='margin-top:15px; width:100%; background:var(--warn); color:#000;'>Confirm & Restore</button>";
            $ui .= "</form></div>";
            echo "<script>document.getElementById('output').innerHTML += " . json_encode($ui) . "; scrollToBottom();</script>";
            echo "<script>document.getElementById('active-line').style.display = 'none';</script>";
            exit;
        } else {
            if (isset($_POST['protection_confirmed'])) {
                $overwrites = $_POST['overwrites'] ??[];
                foreach ($protected as $p) {
                    if (!in_array($p, $overwrites)) $skipFolders[] = $p;
                }
                if (!empty($skipFolders)) js_log("Skipping protected folders: " . implode(', ', $skipFolders), "info");
            }
        }

        js_log("Restoring from: $file");
        $clientState = sc_perform_restore($zipPath, $workerCallback, $skipFolders);if ($clientState) {
            if (is_array($clientState)) {
                echo "<script>localStorage.setItem('cjos_restore_state', " . json_encode(json_encode($clientState)) . ");</script>";
            }
            js_log("The application will need to reload to apply browser state.", "warn");
        } else {
            js_log("ERROR: Restore operation failed.", "error");
        }
    }
}

echo "<script>finish();</script>";
?>
</body>
</html>