<?php
// ==============================================================================
// PLUGIN: Backlog Auditor
// DESCRIPTION: Idea Reconciliation Tool.
// Cross-references Unsorted notes against the Edit Log to find implemented features.
// ==============================================================================

$ba_config_file = dirname(__DIR__) . '/data/backlog-auditor-config.json';

// --- 1. BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {

    if ($_POST['plugin_action'] === 'ba_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults = [
            'model' => 'cognitivecomputations/dolphin-mistral-24b-venice-edition:free',
            'depth' => 100,
            'chunk_size' => 10,
            'max_notes' => 50,
            'temp' => 0.2,
            'prompt' => "You are the 'Backlog Auditor'. I will provide 'Ideas' (unsorted notes) and a 'Change Log' of implemented features.\n\nYour task is to identify which Ideas have already been built. \n\nSTRICTNESS RULES:\n- DO NOT GUESS. If there is no clear evidence of implementation, do not create a match.\n- Look for matching keywords and identical logic.\n\nFor each match, provide a 'matching_score' (integer 0-100):\n- 100: Exact match. The feature described is explicitly implemented.\n- 75: Strong logical match. The implementation clearly covers the idea even if wording differs.\n- 50: Partial match. The log addresses the core of the idea but maybe not all details.\n- <50: Weak/Speculative. DO NOT RETURN matches below 50.\n\nReturn a JSON array: [{'note_id', 'log_index', 'matching_score', 'reasoning'}, ...]\nIMPORTANT: Return ONLY the JSON array."
        ];
        $conf = file_exists($ba_config_file) ? json_decode(file_get_contents($ba_config_file), true) : $defaults;
        echo json_encode(['status' => 'success', 'config' => $conf]);
        exit;
    }

    if ($_POST['plugin_action'] === 'ba_get_log_slice') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $depth = (int)$_POST['depth'];
        $logPath = dirname(__DIR__) . '/data/edit-log.json';
        $fullLog = file_exists($logPath) ? json_decode(file_get_contents($logPath), true) : [];
        $logSlice = array_reverse(array_slice($fullLog, -$depth));
        echo json_encode(['status' => 'success', 'slice' => $logSlice]);
        exit;
    }

    if ($_POST['plugin_action'] === 'ba_run_analysis') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $notes = json_decode($_POST['notes'], true);
        $depth = (int)$_POST['depth'];
        $model = $_POST['model'];
        $temp = (float)$_POST['temp'];
        $sysPrompt = $_POST['prompt'];

        // 1. Get Edit Log Slice
        $logPath = dirname(__DIR__) . '/data/edit-log.json';
        $fullLog = file_exists($logPath) ? json_decode(file_get_contents($logPath), true) : [];
        $logSlice = array_reverse(array_slice($fullLog, -$depth));
        
        $formattedLog = "";
        foreach ($logSlice as $idx => $entry) {
            $formattedLog .= "[Log #{$idx}] Date: {$entry['date']} | Summary: {$entry['summary']}\n";
        }

        // 2. Format Notes
        $formattedNotes = "";
        foreach ($notes as $n) {
            $formattedNotes .= "ID: {$n['id']} | Content: {$n['transcription']}\n---\n";
        }

        // 3. Call OpenRouter
        $or_priv = dirname(__DIR__) . '/data/openrouter-private.json';
        $apiKey = file_exists($or_priv) ? json_decode(file_get_contents($or_priv), true)['api_key'] ?? '' : '';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $sysPrompt . "\n\nCHANGE LOG:\n" . $formattedLog],
                ['role' => 'user', 'content' => "Audit these specific ideas:\n\n" . $formattedNotes]
            ],
            'temperature' => $temp
        ];

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $apiKey, "Content-Type: application/json"]);
        $res = curl_exec($ch); curl_close($ch);

        echo $res; // Return raw OR response to JS
        exit;
    }

    if ($_POST['plugin_action'] === 'ba_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $settings = json_decode($_POST['settings'], true);
        file_put_contents($ba_config_file, json_encode($settings, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- 2. PAGE VIEW (PAGE 7) ---
$ba_page_html = <<<'HTML'
<style>
    .ba-match-bridge {
        display: flex; flex-direction: column; align-items: center; gap: 4px;
        margin: -10px 0 -10px 0; position: relative; z-index: 5;
    }
    .ba-bridge-line { width: 2px; height: 20px; background: var(--primary); opacity: 0.3; }
    .ba-bridge-pill { 
        background: var(--primary); color: white; font-size: 9px; font-weight: 900; 
        padding: 2px 10px; border-radius: 10px; text-transform: uppercase; letter-spacing: 1px;
    }
    .ba-log-card {
        background: #E8F5E9; border: 1px solid #C8E6C9; border-radius: 16px; padding: 16px;
        color: #2E7D32; font-size: 14px; line-height: 1.4; box-shadow: 0 4px 12px rgba(0,0,0,0.03);
    }
    .ba-match-group {
        display: flex; flex-direction: column; gap: 0; margin-bottom: 40px;
        transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        background: var(--card-bg); padding: 12px; border-radius: 24px;
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-card);
    }
    .ba-score-header {
        font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;
        margin: 30px 0 0 0; padding: 12px 16px; display: flex; align-items: center; gap: 10px;
        background: var(--bg-color); border-radius: 12px 12px 0 0; cursor: pointer;
    }
    .ba-tier-container {
        border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 20px;
        overflow: hidden; background: var(--bg-color);
    }
    .ba-tier-content { padding: 12px; display: flex; flex-direction: column; gap: 12px; }
    .ba-tier-actions {
        display: flex; gap: 8px; padding: 10px 16px; background: rgba(0,0,0,0.02);
        border-top: 1px solid var(--border-color); justify-content: flex-end;
    }
    .ba-mini-btn {
        font-size: 10px; font-weight: 800; text-transform: uppercase; padding: 5px 10px;
        border-radius: 6px; border: 1px solid var(--border-color); background: var(--card-bg);
        color: var(--text-secondary); cursor: pointer;
    }
    .ba-mini-btn.primary { color: var(--primary); border-color: var(--primary); }
    .ba-score-dot { width: 8px; height: 8px; border-radius: 50%; }
    .ba-score-100 { color: #34C759; } .ba-score-100 .ba-score-dot { background: #34C759; box-shadow: 0 0 8px #34C759; }
    .ba-score-75 { color: #007AFF; } .ba-score-75 .ba-score-dot { background: #007AFF; }
    .ba-score-50 { color: #FF9500; } .ba-score-50 .ba-score-dot { background: #FF9500; }
    .ba-score-badge {
        font-size: 10px; font-weight: 900; padding: 2px 6px; border-radius: 5px;
        background: rgba(0,0,0,0.05); color: var(--text-secondary);
    }
    .ba-match-group.ba-removing {
        opacity: 0; transform: translateX(40px); pointer-events: none;
    }
    .ba-action-row {
        display: flex; gap: 10px; margin-top: 15px; padding: 0 4px;
    }
    .ba-btn {
        flex: 1; padding: 12px; border-radius: 12px; border: none; font-size: 13px; font-weight: 700; cursor: pointer;
        transition: transform 0.1s; display: flex; align-items: center; justify-content: center; gap: 6px;
    }
    .ba-btn:active { transform: scale(0.95); }
    .ba-btn-archive { background: var(--primary); color: white; box-shadow: 0 4px 12px rgba(0,122,255,0.2); }
    .ba-btn-dismiss { background: var(--btn-bg); color: var(--text-secondary); }
    body.theme-midnight .ba-log-card { background: #0E2E0E; border-color: #1B4D1B; color: #34C759; }
    body.theme-midnight .ba-match-group { background: rgba(255,255,255,0.03); }
</style>
<div class="scroll-view" id="backlog-auditor-view" style="padding:0 20px;">
    <div style="height: calc(var(--header-base-height) + var(--inner-padding-top) + 40px);"></div>
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; width:100%;">
        <div style="display:flex; align-items:baseline; gap:10px;">
            <div class="page-title" style="margin-bottom:0; padding-top:0;">Auditor</div>
            <div id="ba-scan-status" style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; opacity:0.6;">Ready</div>
        </div>
        <button onclick="baOpenSettings()" class="icon-btn" style="background:var(--btn-bg); width:34px; height:34px; border-radius:50%; color:var(--text-primary); margin-right:-4px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
        </button>
    </div>

    <!-- MAIN ACTION -->
    <div style="display:flex; gap:10px; margin-bottom:32px;">
        <button onclick="baStartAudit()" id="ba-main-btn" class="text-btn" style="
            flex:1; background:var(--primary); color:white; border-radius:16px; 
            padding:18px; font-weight:700; font-size:16px; box-shadow:0 8px 20px rgba(0,122,255,0.25);
        ">Start Backlog Audit</button>
        <button onclick="baClearSession()" id="ba-clear-btn" class="text-btn" style="
            display:none; background:var(--btn-bg); color:var(--text-secondary); border-radius:16px; 
            padding:18px; font-weight:700; font-size:16px;
        ">Clear</button>
    </div>

    <!-- RESULTS FEED -->
    <div id="ba-results-feed" style="display:flex; flex-direction:column; gap:20px; padding-bottom:160px;">

    <!-- BULK ACTIONS (STICKY) -->
    <div id="ba-bulk-container" style="
        display:none; position:fixed; bottom:0; left:0; right:0; 
        background:var(--header-bg); backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px);
        padding:15px 20px 35px 20px; border-top:1px solid var(--border-color);
        z-index:100; box-shadow:0 -10px 30px rgba(0,0,0,0.05);
        animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    ">
        <div style="display:flex; gap:10px;">
            <button onclick="baClearSession()" class="text-btn" style="
                flex:1; background:var(--btn-bg); color:var(--text-secondary); border-radius:12px; 
                padding:14px; font-weight:700; font-size:14px;
            ">Dismiss All</button>
            <button onclick="baArchiveAllMatches()" id="ba-bulk-btn" class="text-btn" style="
                flex:2; background:var(--primary); color:white; border-radius:12px; 
                padding:14px; font-weight:700; font-size:14px; box-shadow:0 4px 12px rgba(0,122,255,0.2);
            ">Archive All Matches</button>
        </div>
    </div>
        <div style="text-align:center; padding:40px; color:var(--text-secondary); opacity:0.6; font-size:14px;">
            Tap the button above to cross-reference Unsorted notes with implemented features.
        </div>
    </div>
</div>
HTML;

$plugin_pages[] = $ba_page_html;

$plugin_overlays[] = <<<'HTML'
<!-- AUDITOR SETTINGS OVERLAY -->
<div id="ba-settings-overlay" class="shared-menu-overlay" style="z-index:9500;">
    <div id="ba-settings-sheet" class="shared-bottom-sheet" style="height:85vh;">
        <div style="padding:20px 24px; background:var(--bg-color); border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
            <div style="font-size:18px; font-weight:800; color:var(--text-primary);">Auditor Settings</div>
            <button onclick="baCloseSettings()" style="background:var(--btn-bg); border:none; width:32px; height:32px; border-radius:50%; color:var(--text-primary); display:flex; align-items:center; justify-content:center; cursor:pointer;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:16px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        
        <div style="flex:1; overflow-y:auto; padding:24px;">
            <!-- MODEL PICKER -->
            <div class="setting-item vertical" style="padding:0; border:none; margin-bottom:24px;">
                <label class="setting-label">Analysis Brain</label>
                <div class="setting-desc" style="margin-bottom:8px;">Current: <span id="ba-model-display" style="font-weight:600; color:var(--primary);">...</span></div>
                <button onclick="baPickModel()" class="text-btn" style="width:100%; text-align:center; background:var(--input-bg); color:var(--input-text); border: 1px solid var(--border-color); padding:12px; border-radius:10px; font-weight:600;">Change Model</button>
            </div>

            <!-- CONTEXT DEPTH -->
            <div class="setting-item vertical" style="padding:0; border:none; margin-bottom:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label class="setting-label">Log Depth</label>
                    <span id="ba-depth-val" style="font-weight:700; color:var(--primary); font-size:14px;">100</span>
                </div>
                <div class="setting-desc">Number of recent Edit Log entries to scan.</div>
                <input type="range" id="ba-depth-slider" min="50" max="500" step="10" oninput="baUpdateUI()" onchange="baSaveConfig()" style="margin-top:8px;">
            </div>

            <!-- MAX NOTES -->
            <div class="setting-item vertical" style="padding:0; border:none; margin-bottom:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label class="setting-label">Max Notes to Audit</label>
                    <span id="ba-max-val" style="font-weight:700; color:var(--primary); font-size:14px;">50</span>
                </div>
                <div class="setting-desc">Limit how many unsorted notes are sent for analysis.</div>
                <input type="range" id="ba-max-slider" min="1" max="100" step="1" oninput="baUpdateUI()" onchange="baSaveConfig()" style="margin-top:8px;">
            </div>

            <!-- CHUNK SIZE -->
            <div class="setting-item vertical" style="padding:0; border:none; margin-bottom:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label class="setting-label">Batch Size (Chunks)</label>
                    <span id="ba-chunk-val" style="font-weight:700; color:var(--primary); font-size:14px;">10</span>
                </div>
                <div class="setting-desc">Number of notes to process per API call.</div>
                <input type="range" id="ba-chunk-slider" min="1" max="20" step="1" oninput="baUpdateUI()" onchange="baSaveConfig()" style="margin-top:8px;">
            </div>

            <!-- TEMPERATURE -->
            <div class="setting-item vertical" style="padding:0; border:none; margin-bottom:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label class="setting-label">Strictness (Temp)</label>
                    <span id="ba-temp-val" style="font-weight:700; color:var(--primary); font-size:14px;">0.2</span>
                </div>
                <div class="setting-desc">Lower values are more precise.</div>
                <input type="range" id="ba-temp-slider" min="0" max="2" step="0.1" oninput="baUpdateUI()" onchange="baSaveConfig()" style="margin-top:8px;">
            </div>

            <!-- PROMPT -->
            <div class="setting-item vertical" style="padding:0; border:none; margin-bottom:24px;">
                <label class="setting-label">System Prompt</label>
                <textarea id="ba-prompt-input" onchange="baSaveConfig()" style="height:150px; font-size:12px; font-family:monospace; margin-top:8px;"></textarea>
                <button onclick="baResetPrompt()" class="text-btn" style="width:100%; margin-top:12px; font-size:11px; color:var(--danger); opacity:0.7; font-weight:700;">Reset to Default Scoring Prompt</button>
            </div>
        </div>
    </div>
</div>
HTML;

// --- 3. JS LOGIC ---
$plugin_js .= <<<'JS'
// --- BACKLOG AUDITOR ENGINE ---

let baConfig = { model: '', depth: 100, temp: 0.3, prompt: '' };

window.addEventListener("load", () => {
    baLoadConfig();
    
    // Restore Session Results
    const saved = sessionStorage.getItem("ba_results");
    if (saved) {
        const matches = JSON.parse(saved);
        if (matches.length > 0) {
            baRenderMatches(matches);
            document.getElementById("ba-bulk-container").style.display = "block";
            document.getElementById("ba-main-btn").innerText = "Re-scan Backlog";
            document.getElementById("ba-clear-btn").style.display = "block";
        }
    }
    
    // Styles moved to static HTML block for persistence.
});

async function baLoadConfig() {
    try {
        const fd = new FormData();
        fd.append("plugin_action", "ba_get_config");
        const res = await fetch("index.php", { method: "POST", body: fd });
        const data = await res.json();
        if (data.status === "success") {
            baConfig = data.config;
            baUpdateUI(true);
        }
    } catch(e) {}
}

window.baResetPrompt = function() {
    const defaultPrompt = "You are the 'Backlog Auditor'. I will provide 'Ideas' (unsorted notes) and a 'Change Log' of implemented features.\n\nYour task is to identify which Ideas have already been built. \n\nSTRICTNESS RULES:\n- DO NOT GUESS. If there is no clear evidence of implementation, do not create a match.\n- Look for matching keywords and identical logic.\n\nFor each match, provide a 'matching_score' (integer 0-100):\n- 100: Exact match. The feature described is explicitly implemented.\n- 75: Strong logical match. The implementation covers the idea even if wording differs.\n- 50: Partial match. The log addresses the core of the idea but not all details.\n- <50: Weak/Speculative. DO NOT RETURN matches below 50.\n\nReturn a JSON array: [{'note_id', 'log_index', 'matching_score', 'reasoning'}, ...]\nIMPORTANT: Return ONLY the JSON array.";
    document.getElementById("ba-prompt-input").value = defaultPrompt;
    baConfig.prompt = defaultPrompt;
    baSaveConfig();
    const t = document.getElementById("toast");
    if(t) { t.innerText = "Prompt Reset"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
};

window.baOpenSettings = function() {
    const overlay = document.getElementById("ba-settings-overlay");
    const sheet = document.getElementById("ba-settings-sheet");
    overlay.style.visibility = "visible";
    overlay.style.opacity = "1";

    if (typeof aboEnabled !== "undefined" && aboEnabled) {
        history.pushState({ ba_settings_open: true }, null, window.location.href);
    }

    requestAnimationFrame(() => {
        sheet.style.transform = "translateY(0)";
    });
};

window.baCloseSettings = function() {
    const overlay = document.getElementById("ba-settings-overlay");
    const sheet = document.getElementById("ba-settings-sheet");
    sheet.style.transform = "translateY(100%)";
    overlay.style.opacity = "0";
    setTimeout(() => {
        overlay.style.visibility = "hidden";
    }, 300);
};

window.baUpdateUI = function(syncInputs = false) {
    const depthS = document.getElementById("ba-depth-slider");
    const chunkS = document.getElementById("ba-chunk-slider");
    const maxS = document.getElementById("ba-max-slider");
    const tempS = document.getElementById("ba-temp-slider");
    const promptI = document.getElementById("ba-prompt-input");
    const modelD = document.getElementById("ba-model-display");

    if (syncInputs) {
        if(depthS) depthS.value = baConfig.depth;
        if(chunkS) chunkS.value = baConfig.chunk_size;
        if(maxS) maxS.value = baConfig.max_notes || 50;
        if(tempS) tempS.value = baConfig.temp;
        if(promptI) promptI.value = baConfig.prompt;
    } else {
        if(depthS) baConfig.depth = parseInt(depthS.value);
        if(chunkS) baConfig.chunk_size = parseInt(chunkS.value);
        if(maxS) baConfig.max_notes = parseInt(maxS.value);
        if(tempS) baConfig.temp = parseFloat(tempS.value);
    }

    if(document.getElementById("ba-depth-val")) document.getElementById("ba-depth-val").innerText = baConfig.depth;
    if(document.getElementById("ba-chunk-val")) document.getElementById("ba-chunk-val").innerText = baConfig.chunk_size;
    if(document.getElementById("ba-max-val")) document.getElementById("ba-max-val").innerText = baConfig.max_notes || 50;
    if(document.getElementById("ba-temp-val")) document.getElementById("ba-temp-val").innerText = baConfig.temp;
    if(modelD) modelD.innerText = baConfig.model.split('/').pop();
};

window.baStartAudit = async function() {
    const btn = document.getElementById("ba-main-btn");
    const status = document.getElementById("ba-scan-status");
    const feed = document.getElementById("ba-results-feed");

    let unsorted = logs.filter(l => {
        const fid = (typeof so_map !== 'undefined') ? so_map[l.id] : 0;
        return fid == 0 || fid == null;
    });

    if (baConfig.max_notes) {
        unsorted = unsorted.slice(0, baConfig.max_notes);
    }

    if (unsorted.length === 0) {
        alert("Unsorted folder is empty. Nothing to audit!");
        return;
    }

    btn.disabled = true; btn.style.opacity = "0.5";
    feed.innerHTML = `<div style="text-align:center; padding:40px; color:var(--primary); font-weight:600;">Analyzing ${unsorted.length} notes against implementations...</div>`;

    if (window.cjosProgressPill) window.cjosProgressPill.show(`Auditing 1 of ${unsorted.length}`);

    let allMatches = [];
    const totalBatches = Math.ceil(unsorted.length / baConfig.chunk_size);

    for (let i = 0; i < totalBatches; i++) {
        const start = i * baConfig.chunk_size;
        const chunk = unsorted.slice(start, start + baConfig.chunk_size);
        
        const pct = Math.round(((i) / totalBatches) * 100);
        if (window.cjosProgressPill) window.cjosProgressPill.update(`Batch ${i+1} of ${totalBatches}`, pct);
        status.innerText = `Scanning Batch ${i+1}/${totalBatches}`;
        
        try {
            const fd = new FormData();
            fd.append("plugin_action", "ba_run_analysis");
            fd.append("notes", JSON.stringify(chunk));
            fd.append("depth", baConfig.depth);
            fd.append("model", baConfig.model);
            fd.append("temp", baConfig.temp);
            fd.append("prompt", baConfig.prompt);

            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();

            if (data.choices && data.choices[0].message.content) {
                const content = data.choices[0].message.content;
                // Attempt to extract JSON from markdown or raw text
                const jsonMatch = content.match(/\[[\s\S]*\]/);
                if (jsonMatch) {
                    const matches = JSON.parse(jsonMatch[0]);
                    allMatches = allMatches.concat(matches);
                } else {
                    console.error("AI returned unprocessable format:", content);
                    feed.insertAdjacentHTML('beforeend', `<div style="color:var(--danger); font-size:11px; padding:10px; border:1px solid #FFCDCD; border-radius:8px; margin-bottom:10px;">⚠️ Batch ${i+1} failed: AI returned non-JSON format.</div>`);
                }
            } else {
                throw new Error("API Error: " + (data.error?.message || "Unknown response"));
            }
        } catch(e) {
            console.error("Audit Batch Error", e);
            feed.insertAdjacentHTML('beforeend', `<div style="color:var(--danger); font-size:11px; padding:10px; border:1px solid #FFCDCD; border-radius:8px; margin-bottom:10px;">❌ Batch ${i+1} failed: ${e.message}</div>`);
        }
    }

    status.innerText = "Audit Complete";
    btn.disabled = false; btn.style.opacity = "1";
    btn.innerText = "Re-scan Backlog";
    document.getElementById("ba-clear-btn").style.display = "block";

    if (window.cjosProgressPill) window.cjosProgressPill.done("Audit Complete");

    if (allMatches.length === 0) {
        feed.innerHTML = `<div style="text-align:center; padding:40px; color:var(--text-secondary); opacity:0.6;">No implemented matches found for your current backlog.</div>`;
        document.getElementById("ba-bulk-container").style.display = "none";
        sessionStorage.removeItem("ba_results");
    } else {
        sessionStorage.setItem("ba_results", JSON.stringify(allMatches));
        baRenderMatches(allMatches);
        document.getElementById("ba-bulk-container").style.display = "block";
    }
};

window.baClearSession = function() {
    sessionStorage.removeItem("ba_results");
    document.getElementById("ba-results-feed").innerHTML = `<div style="text-align:center; padding:40px; color:var(--text-secondary); opacity:0.6; font-size:14px;">Results cleared.</div>`;
    document.getElementById("ba-bulk-container").style.display = "none";
    document.getElementById("ba-main-btn").innerText = "Start Backlog Audit";
    document.getElementById("ba-clear-btn").style.display = "none";
    document.getElementById("ba-scan-status").innerText = "Ready";
};

window.baRenderMatches = async function(matches) {
    const feed = document.getElementById("ba-results-feed");
    feed.innerHTML = "";
    
    const fd = new FormData();
    fd.append("plugin_action", "ba_get_log_slice");
    fd.append("depth", baConfig.depth);
    const res = await fetch("index.php", { method: "POST", body: fd });
    const logData = await res.json();
    const slice = logData.slice || [];

    // 1. Group by tiers & STRICT FILTERING
    const tiers = {
        100: { label: "Definite Matches", class: "ba-score-100", items: [] },
        75: { label: "Strong Matches", class: "ba-score-75", items: [] },
        50: { label: "Partial Matches", class: "ba-score-50", items: [] }
    };

    matches.forEach(m => {
        const score = parseInt(m.matching_score || m.score || 0);
        if (score < 50) return; // Defensive: Ignore low-confidence matches
        if (score >= 100) tiers[100].items.push(m);
        else if (score >= 75) tiers[75].items.push(m);
        else tiers[50].items.push(m);
    });

    // 2. Render Tiers
    [100, 75, 50].forEach(score => {
        const tier = tiers[score];
        if (tier.items.length === 0) return;

        const container = document.createElement("div");
        container.className = "ba-tier-container";
        container.id = `ba-tier-${score}`;

        const header = document.createElement("div");
        header.className = `ba-score-header ${tier.class}`;
        header.innerHTML = `
            <div class="ba-score-dot"></div>
            <span style="flex:1;">${tier.label} (${tier.items.length})</span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:14px; height:14px; stroke-width:3;"><polyline points="6 9 12 15 18 9"></polyline></svg>
        `;
        
        const content = document.createElement("div");
        content.className = "ba-tier-content";
        content.id = `ba-tier-content-${score}`;
        content.style.display = "none"; // Collapse by default

        header.onclick = () => {
            const isHidden = content.style.display === "none";
            content.style.display = isHidden ? "flex" : "none";
            header.querySelector('svg').style.transform = isHidden ? "rotate(0deg)" : "rotate(-90deg)";
        };
        // Set initial arrow state
        header.querySelector('svg').style.transform = "rotate(-90deg)";

        const tierActions = document.createElement("div");
        tierActions.className = "ba-tier-actions";
        tierActions.innerHTML = `
            <button onclick="baDismissTier(${score})" class="ba-mini-btn">Dismiss Tier</button>
            <button onclick="baArchiveTier(${score})" class="ba-mini-btn primary">Archive Tier</button>
        `;

        tier.items.forEach((m) => {
            const entry = logs.find(l => l.id === m.note_id);
            const logMatch = slice[m.log_index];
            if (!entry || !logMatch) return;

            const group = document.createElement("div");
            group.className = "ba-match-group";
            group.id = `ba-group-${m.note_id}`;
            group.setAttribute("data-log-id", entry.id);

            const card = window.createStandardCardDOM(entry);
            card.style.marginBottom = "0"; card.style.boxShadow = "none";
            card.style.border = "1px solid var(--border-color)";

            const bridge = document.createElement("div");
            bridge.className = "ba-match-bridge";
            bridge.innerHTML = `<div class="ba-bridge-line"></div><div class="ba-bridge-pill">Implemented By</div><div class="ba-bridge-line"></div>`;

            const logCard = document.createElement("div");
            logCard.className = "ba-log-card";
            const displayScore = m.matching_score || m.score || 0;
            logCard.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
                    <div style="font-size:10px; font-weight:800; opacity:0.6; text-transform:uppercase;">${logMatch.date}</div>
                    <div class="ba-score-badge">${displayScore}% Match</div>
                </div>
                <div style="font-weight:600;">${logMatch.summary}</div>
                <div style="font-size:12px; font-style:italic; margin-top:8px; opacity:0.8; border-top:1px solid rgba(0,0,0,0.05); padding-top:8px;">" ${m.reasoning} "</div>
            `;

            const actions = document.createElement("div");
            actions.className = "ba-action-row";
            actions.innerHTML = `
                <button onclick="baDismissMatch('${entry.id}')" class="ba-btn ba-btn-dismiss">Dismiss</button>
                <button onclick="baArchiveMatch('${entry.id}')" class="ba-btn ba-btn-archive">Archive Note</button>
            `;

            group.append(card, bridge, logCard, actions);
            content.appendChild(group);
            if (window.cjosPluginRegistry) window.cjosPluginRegistry.forEach(p => { try { p.fn(card); } catch(e) {} });
        });

        container.append(header, content, tierActions);
        feed.appendChild(container);
    });

    if (feed.innerHTML === "") {
        feed.innerHTML = `<div style="text-align:center; padding:40px; color:var(--text-secondary); opacity:0.6;">No qualified matches found (Score >= 50%).</div>`;
    }
};

window.baArchiveTier = async function(score) {
    const tier = document.getElementById(`ba-tier-content-${score}`);
    if (!tier) return;
    const groups = tier.querySelectorAll(".ba-match-group:not(.ba-removing)");
    if (groups.length === 0) return;
    const ids = Array.from(groups).map(g => g.getAttribute("data-log-id"));
    if (!confirm(`Archive all ${ids.length} entries in this tier?`)) return;
    baExecuteBulkArchive(ids, groups, score);
};

window.baDismissTier = function(score) {
    const tier = document.getElementById(`ba-tier-content-${score}`);
    const container = document.getElementById(`ba-tier-${score}`);
    if (!tier) return;
    const groups = tier.querySelectorAll(".ba-match-group:not(.ba-removing)");
    if (groups.length === 0) return;
    if (!confirm(`Dismiss all ${groups.length} matches in this tier?`)) return;

    const ids = Array.from(groups).map(g => g.getAttribute("data-log-id"));
    
    groups.forEach(g => g.classList.add("ba-removing"));
    setTimeout(() => {
        if (container) container.remove();
        baSyncSessionData(ids);
    }, 500);
};

async function baExecuteBulkArchive(ids, elements, tierScore = null) {
    if (window.cjosProgressPill) window.cjosProgressPill.show(`Archiving ${ids.length} Notes`);
    let archiveFid = 0;
    if (typeof so_folders !== 'undefined') {
        const f = so_folders.find(x => x.name.toLowerCase() === 'archived');
        if (f) archiveFid = f.id;
    }
    const fd = new FormData();
    fd.append("plugin_action", "folder_assign");
    fd.append("folder_id", archiveFid);
    fd.append("log_ids", JSON.stringify(ids));
    try {
        await fetch("index.php", { method: "POST", body: fd });
        ids.forEach(id => { if (typeof so_map !== 'undefined') so_map[id] = archiveFid; });
        if (window.cjosProgressPill) window.cjosProgressPill.done("Archived");
        
        // Sync Session Storage to prevent cards returning on refresh
        baSyncSessionData(ids);

        elements.forEach(g => { g.classList.add("ba-removing"); setTimeout(() => g.remove(), 500); });

        // Prune empty tier containers
        setTimeout(() => {
            if (tierScore) {
                const container = document.getElementById(`ba-tier-${tierScore}`);
                if (container) container.remove();
            } else {
                // If single archive, check if its parent tier is now empty
                document.querySelectorAll('.ba-tier-container').forEach(tier => {
                    if (tier.querySelectorAll('.ba-match-group').length === 0) tier.remove();
                });
            }
        }, 600);

        if (typeof window.renderFolderBadges === "function") window.renderFolderBadges();
        if (typeof window.refreshFolderView === "function") window.refreshFolderView();
    } catch(e) { alert("Archive failed."); }
}

window.baSyncSessionData = function(removedIds) {
    const saved = JSON.parse(sessionStorage.getItem("ba_results") || "[]");
    const filtered = saved.filter(m => !removedIds.includes(m.note_id));
    if (filtered.length === 0) {
        baClearSession();
    } else {
        sessionStorage.setItem("ba_results", JSON.stringify(filtered));
    }
};

window.baDismissMatch = function(logId) {
    const el = document.getElementById(`ba-group-${logId}`);
    if (el) {
        el.classList.add("ba-removing");
        const container = el.closest('.ba-tier-container');
        setTimeout(() => {
            el.remove();
            baSyncSessionData([logId]);
            // Remove container if last item in tier was dismissed
            if (container && container.querySelectorAll('.ba-match-group').length === 0) {
                container.remove();
            }
        }, 500);
    }
};

window.baArchiveAllMatches = async function() {
    const groups = document.querySelectorAll(".ba-match-group:not(.ba-removing)");
    if (groups.length === 0) return;
    const ids = Array.from(groups).map(g => g.getAttribute("data-log-id"));
    if (!confirm(`Archive all ${ids.length} matches across all tiers?`)) return;
    baExecuteBulkArchive(ids, groups);
    document.getElementById("ba-bulk-container").style.display = "none";
};

window.baArchiveMatch = async function(logId) {
    const el = document.getElementById(`ba-group-${logId}`);
    const btn = el.querySelector(".ba-btn-archive");
    btn.disabled = true;
    btn.innerText = "Archiving...";

    // Handshake: SmartOrganizer
    const fd = new FormData();
    fd.append("plugin_action", "folder_assign");
    
    // Find "Archived" Folder ID
    let archiveFid = 0;
    if (typeof so_folders !== 'undefined') {
        const f = so_folders.find(x => x.name.toLowerCase() === 'archived');
        if (f) archiveFid = f.id;
    }

    fd.append("folder_id", archiveFid);
    fd.append("log_ids", JSON.stringify([logId]));

    try {
        await fetch("index.php", { method: "POST", body: fd });
        
        // Update Local Data Map
        if (typeof so_map !== 'undefined') so_map[logId] = archiveFid;
        
        // Visual Feedback
        el.classList.add("ba-removing");
        
        // Update Session Storage so it doesn't come back on refresh
        const saved = JSON.parse(sessionStorage.getItem("ba_results") || "[]");
        const filtered = saved.filter(m => m.note_id !== logId);
        sessionStorage.setItem("ba_results", JSON.stringify(filtered));

        setTimeout(() => {
            el.remove();
            if (typeof window.renderFolderBadges === "function") window.renderFolderBadges();
            // CRITICAL SYNC: Re-run folder filter to hide the archived note from Unsorted
            if (typeof window.refreshFolderView === "function") window.refreshFolderView();
        }, 500);

        const t = document.getElementById("toast");
        if(t) { t.innerText = "Entry Archived"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
    } catch(e) {
        alert("Archive failed.");
        btn.disabled = false;
        btn.innerText = "Archive Note";
    }
};

window.baSaveConfig = async function() {
    const fd = new FormData();
    fd.append("plugin_action", "ba_save_config");
    fd.append("settings", JSON.stringify(baConfig));
    await fetch("index.php", { method: "POST", body: fd });
};

window.baTogglePrompt = function() {
    const cont = document.getElementById("ba-prompt-container");
    const arrow = document.getElementById("ba-prompt-arrow");
    const isHidden = cont.style.display === "none";
    cont.style.display = isHidden ? "block" : "none";
    arrow.style.transform = isHidden ? "rotate(0deg)" : "rotate(-90deg)";
};

window.baPickModel = function() {
    if (typeof window.openModelPicker === "function") {
        window.openModelPicker();
        const origSelect = window.selectModel;
        window.selectModel = (id) => {
            baConfig.model = id;
            baUpdateUI();
            baSaveConfig();
            window.selectModel = origSelect; 
            if (typeof window.closeAiManager === "function") window.closeAiManager();
        };
    }
};

// Redundant function removed.
JS;
?>