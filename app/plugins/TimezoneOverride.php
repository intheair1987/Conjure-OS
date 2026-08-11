<?php
// ==============================================================================
// PLUGIN: Timezone Override
// DESCRIPTION: Server Time Manager.
// Stores timezone settings in a physical file (timezone.json) and supports 
// IP-based auto-detection for headless server environments.
// ==============================================================================

$tz_config_file = CJOS_PATH_DATA . '/timezone.json';

// --- FUNCTIONS ---

function tz_get_config() {
    global $tz_config_file;
    if (file_exists($tz_config_file)) {
        return json_decode(file_get_contents($tz_config_file), true);
    }
    return ['mode' => 'Auto', 'manual_value' => 'UTC', 'detected_value' => 'UTC', 'last_check' => 0];
}

function tz_detect_from_ip() {
    // Queries ip-api.com to get the server's external location
    // This works perfectly on a phone/localhost server connected to WiFi/Data
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/json/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $json = curl_exec($ch);
    curl_close($ch);

    if ($json) {
        $data = json_decode($json, true);
        if (isset($data['timezone'])) {
            return $data['timezone'];
        }
    }
    return 'UTC'; // Fallback
}

// --- BACKEND HANDLER ---

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'get_timezone_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'config' => tz_get_config()]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'save_timezone_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $mode = $_POST['mode']; // 'Auto' or 'Manual'
    $manual = $_POST['manual_value'];
    
    $currentConfig = tz_get_config();
    $detected = $currentConfig['detected_value'];
    $lastCheck = $currentConfig['last_check'];

    // If Auto, perform a fresh check
    if ($mode === 'Auto') {
        $detected = tz_detect_from_ip();
        $lastCheck = time();
    }

    $newConfig = [
        'mode' => $mode,
        'manual_value' => $manual,
        'detected_value' => $detected,
        'last_check' => $lastCheck
    ];

    file_put_contents($tz_config_file, json_encode($newConfig));

    // Apply immediately to current request to verify
    $activeTz = ($mode === 'Manual') ? $manual : $detected;
    
    echo json_encode([
        'status' => 'success', 
        'config' => $newConfig,
        'active_now' => $activeTz,
        'current_time' => (new DateTime("now", new DateTimeZone($activeTz)))->format('Y-m-d H:i:s')
    ]);
    exit;
}

// --- FRONTEND UI ---

$plugin_settings_map['TimezoneOverride'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Server Timezone</label>
        <div class="setting-desc">Controls timestamps for Remote Uploads & Server Scripts.</div>
        
        <!-- Mode Switch -->
        <div style="display:flex; background:var(--btn-bg); border-radius:10px; padding:2px; margin-top:8px; margin-bottom:12px;">
            <button onclick="tzSetMode('Auto')" id="tz-btn-auto" style="flex:1; border:none; background:white; border-radius:8px; padding:8px; cursor:pointer; font-size:14px; font-weight:600; box-shadow:0 1px 3px rgba(0,0,0,0.1);">Auto (ISP)</button>
            <button onclick="tzSetMode('Manual')" id="tz-btn-manual" style="flex:1; border:none; background:transparent; border-radius:8px; padding:8px; cursor:pointer; font-size:14px; color:#8E8E93;">Manual</button>
        </div>

        <!-- Manual Picker -->
        <div id="tz-manual-container" style="display:none; margin-bottom:12px;">
            <button id="tz-trigger" class="text-btn" style="width:100%; text-align:left; background:var(--input-bg); color:var(--input-text); padding:12px; border-radius:10px; border:1px solid var(--border-color); display:flex; justify-content:space-between;">
                <span id="tz-display">Select Timezone...</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:16px; opacity:0.5;"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
        </div>

        <!-- Status Readout -->
        <div style="background:var(--btn-bg); border-radius:8px; padding:10px; font-size:12px; color:var(--text-secondary);">
            <strong>Active Timezone:</strong> <span id="tz-status-active">...</span><br>
            <strong>Server Time:</strong> <span id="tz-status-time">...</span>
        </div>
        <div style="margin-top:8px; text-align:right;">
             <button onclick="tzRefresh()" style="background:var(--primary); color:white; border:none; padding:6px 12px; border-radius:6px; font-size:12px;">Save & Update</button>
        </div>
    </div>
HTML;

$plugin_js .=  <<<'JS'
// --- TIMEZONE MANAGER JS ---

let tzState = { mode: "Auto", manual: "UTC" };
const tzOptions = [
    { value: "UTC", label: "UTC" },
    { value: "Asia/Taipei", label: "Asia/Taipei" },
    { value: "Asia/Tokyo", label: "Asia/Tokyo" },
    { value: "America/New_York", label: "New York (EST)" },
    { value: "America/Los_Angeles", label: "Los Angeles (PST)" },
    { value: "Europe/London", label: "London (GMT)" },
    { value: "Europe/Berlin", label: "Berlin (CET)" },
    { value: "Australia/Sydney", label: "Sydney (AEDT)" }
];

window.addEventListener("load", () => {
    tzLoadSettings();
    // Initial fetch of status is handled by "saving" current state to trigger a read
    // Or we could rely on defaults. Let's trigger a refresh on load to sync UI.
    // However, since we don't have a GET endpoint, we rely on the user tapping Save or Defaults.
    
    // UI Init
    const btnManual = document.getElementById("tz-trigger");
    if(btnManual) {
        btnManual.onclick = () => {
            window.openPicker("Select Timezone", tzOptions, tzState.manual, (val) => {
                tzState.manual = val;
                document.getElementById("tz-display").innerText = val;
            });
        };
    }
});

window.tzLoadSettings = async function() {
    try {
        const data = await window.sui.api("get_timezone_config", {}, { toast: false });
        if (data) {
            const c = data.config;
            tzState.mode = c.mode || "Auto";
            tzState.manual = c.manual_value || "UTC";
            tzSetMode(tzState.mode);
            if(document.getElementById("tz-display")) document.getElementById("tz-display").innerText = tzState.manual;
            if(document.getElementById("tz-status-active")) document.getElementById("tz-status-active").innerText = (c.mode==="Manual"?c.manual_value:c.detected_value) + (c.mode==="Auto"?" (Detected)":" (Manual)");
            if(document.getElementById("tz-status-time") && c.last_check) document.getElementById("tz-status-time").innerText = "Synced";
        }
    } catch(e) {}
};

window.tzSetMode = function(mode) {
    tzState.mode = mode;
    const btnAuto = document.getElementById("tz-btn-auto");
    const btnMan = document.getElementById("tz-btn-manual");
    const manCont = document.getElementById("tz-manual-container");

    if(mode === "Auto") {
        btnAuto.style.background = "var(--input-bg)"; btnAuto.style.boxShadow = "var(--shadow-card)"; btnAuto.style.color = "var(--input-text)";
        btnMan.style.background = "transparent"; btnMan.style.boxShadow = "none"; btnMan.style.color = "var(--text-secondary)";
        manCont.style.display = "none";
    } else {
        btnMan.style.background = "var(--input-bg)"; btnMan.style.boxShadow = "var(--shadow-card)"; btnMan.style.color = "var(--input-text)";
        btnAuto.style.background = "transparent"; btnAuto.style.boxShadow = "none"; btnAuto.style.color = "var(--text-secondary)";
        manCont.style.display = "block";
    }
};

window.tzRefresh = async function() {
    const btn = document.querySelector("#tz-status-time").parentElement.nextElementSibling.querySelector("button");
    const oldText = btn.innerText;
    btn.innerText = "Updating...";
    
    try {
        const data = await window.sui.api("save_timezone_config", { 
            mode: tzState.mode, 
            manual_value: tzState.manual 
        }, { toast: false });
        
        if(data) {
            document.getElementById("tz-status-active").innerText = data.active_now + (data.config.mode === "Auto" ? " (Detected)" : " (Manual)");
            document.getElementById("tz-status-time").innerText = data.current_time;
            
            // Sync internal state
            tzState.manual = data.config.manual_value;
            document.getElementById("tz-display").innerText = tzState.manual;
            tzSetMode(data.config.mode); // Visually reset buttons
        }
    } catch(e) {
        window.openConfirm("Error", "Error updating timezone: " + e, null, false, "OK", null);
    }
    btn.innerText = oldText;
};
JS;
?>