<?php
// ==============================================================================
// PLUGIN: Server Ping
// DESCRIPTION: Live Connectivity Monitor.
// Pings the server periodically to ensure it hasn't crashed.
// Shows a prominent warning if the connection is lost.
// ==============================================================================

$sp_config_file = CJOS_PATH_DATA . '/server-ping-config.json';

// --- 1. BACKEND HANDLER ---
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'sp_ping') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'timestamp' => time()]);
    exit;
}

if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'sp_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults = ['enabled' => true, 'interval' => 15];
        $conf = file_exists($sp_config_file) ? json_decode(file_get_contents($sp_config_file), true) : $defaults;
        echo json_encode(['status' => 'success', 'config' => $conf]);
        exit;
    }
    if ($_POST['plugin_action'] === 'sp_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $settings = json_decode($_POST['settings'], true);
        file_put_contents($sp_config_file, json_encode($settings, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- 2. SETTINGS UI ---
$plugin_settings_map['ServerPing'] = <<<'HTML'
    <div data-sui-setting="Active Monitoring" data-sui-desc="Check server health in the background." data-sui-id="sp-enabled-toggle" data-sui-onchange="spSaveSettings()"></div>
    <div class="setting-item vertical">
        <label class="setting-label">Ping Interval</label>
        <div class="setting-desc">Seconds between checks. Lower is more responsive but uses more battery.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="sp-interval-slider" min="5" max="60" step="5" oninput="document.getElementById('sp-interval-val').innerText = this.value + 's'" onchange="spSaveSettings()" style="flex:1;">
            <span id="sp-interval-val" style="font-weight:700; color:var(--primary); min-width:40px;">15s</span>
        </div>
    </div>
    <div id="sp-save-status" style="text-align:right; font-size:11px; color:#8E8E93; padding:0 16px 8px; height:14px;"></div>
HTML;

// --- 3. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- SERVER PING ENGINE ---

let spConfig = { enabled: true, interval: 15 };
let spTimer = null;
let spIsOffline = false;

window.addEventListener("load", () => {
    // 1. Inject UI Components
    const banner = document.createElement("div");
    banner.id = "sp-offline-banner";
    banner.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; 
        background: #FF3B30; color: white; 
        text-align: center; padding: 10px; 
        font-size: 13px; font-weight: 800; 
        z-index: 10000; display: none;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        animation: spSlideDown 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        letter-spacing: 0.5px;
    `;
    banner.innerHTML = `
        <div style="display:flex; align-items:center; justify-content:center; gap:10px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:16px; height:16px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            SERVER CONNECTION LOST
            <button onclick="spRunCheck()" style="background:white; color:#FF3B30; border:none; padding:4px 10px; border-radius:6px; font-size:10px; font-weight:900; cursor:pointer; margin-left:10px;">RETRY</button>
        </div>
    `;
    document.body.appendChild(banner);

    const style = document.createElement("style");
    style.innerHTML = `@keyframes spSlideDown { from { transform: translateY(-100%); } to { transform: translateY(0); } }`;
    document.head.appendChild(style);

    // 2. Proof of Life: If this script is running, the server just served index.php.
    spIsOffline = false; 
    
    // 3. Load Settings
    spLoadConfig();

    // 4. Visibility Watcher (Save battery when tab is backgrounded)
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") spStartLoop();
        else spStopLoop();
    });
});

async function spLoadConfig() {
    try {
        const data = await window.sui.api("sp_get_config", {}, { 
            toast: false,
            errorToast: false 
        });
        if (data) {
            spConfig = data.config;
            const toggle = document.getElementById("sp-enabled-toggle");
            const slider = document.getElementById("sp-interval-slider");
            if(toggle) toggle.checked = spConfig.enabled;
            if(slider) {
                slider.value = spConfig.interval;
                document.getElementById("sp-interval-val").innerText = spConfig.interval + "s";
            }
            if(spConfig.enabled) spStartLoop();
        }
    } catch(e) {
        // Config load failure on startup shouldn't trigger the banner 
        // since the page itself just loaded successfully.
        console.warn("SP: Initial config fetch failed, using defaults.");
    }
}

window.spSaveSettings = async function() {
    spConfig.enabled = document.getElementById("sp-enabled-toggle").checked;
    spConfig.interval = parseInt(document.getElementById("sp-interval-slider").value);
    
    const status = document.getElementById("sp-save-status");
    if(status) status.innerText = "Saving...";
    
    try {
        await window.sui.api("sp_save_config", { settings: spConfig }, { toast: false });
        if(status) { status.innerText = "Saved"; setTimeout(()=>status.innerText="", 2000); }
    } catch(e) {}

    if (spConfig.enabled) spStartLoop();
    else spStopLoop();
};

function spStartLoop() {
    spStopLoop();
    if (!spConfig.enabled || document.visibilityState !== "visible") return;
    spRunCheck();
    spTimer = setInterval(spRunCheck, spConfig.interval * 1000);
}

function spStopLoop() {
    if (spTimer) clearInterval(spTimer);
}

window.spRunCheck = async function() {
    if (!navigator.onLine) {
        spSetOffline(true, "NO INTERNET CONNECTION");
        return;
    }

    try {
        // Use sui.api with abort signal capability via options
        const data = await window.sui.api("sp_ping", {}, { 
            toast: false, 
            errorToast: false 
        });
        
        if (data) spSetOffline(false);
        else spSetOffline(true);
    } catch(e) {
        spSetOffline(true);
    }
};

function spSetOffline(isOffline, customMsg = null) {
    const banner = document.getElementById("sp-offline-banner");
    if (!banner) return;

    if (isOffline) {
        if (!spIsOffline) {
            banner.style.display = "block";
            spIsOffline = true;
            window.sui.haptic('error');
        }
        if (customMsg) {
            const textNode = banner.querySelector("div");
            if (textNode) textNode.childNodes[1].textContent = " " + customMsg + " ";
        }
    } else {
        if (spIsOffline) {
            banner.style.display = "none";
            spIsOffline = false;
            if (window.sui && window.sui.toast) { window.sui.toast("Connection Restored", { plugin: "ServerPing", caller: "spRunCheck", metrics: { status: "online" } }); }}
    }
}
JS;