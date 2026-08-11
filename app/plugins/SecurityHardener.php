<?php
// ==============================================================================
// PLUGIN: Security Hardener
// DESCRIPTION: System Firewall & HTTPS.
// Features: Independent Header Toggle, Firewall Toggle, Safe Uninstall.
// ==============================================================================

$sh_config_file = CJOS_PATH_DATA . '/security-config.json';

// 1. SESSION (Always required for CSRF generation)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}
if (empty($_SESSION['cjos_csrf_token'])) {
    $_SESSION['cjos_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['cjos_csrf_token'];

// 2. LOAD CONFIG
$sh_settings = ['headers_enabled' => true, 'force_https' => false, 'https_bypass_local' => true]; // Default
if (file_exists($sh_config_file)) {
    $loaded = json_decode(file_get_contents($sh_config_file), true);
    if(is_array($loaded)) $sh_settings = array_merge($sh_settings, $loaded);
}

// --- DATA BRIDGE ---
$sh_bridge_json = json_encode($sh_settings);
$plugin_js .= "\nwindow.__SH_BRIDGE__ = $sh_bridge_json;\n";

// 3. LAYER 1: SECURITY HEADERS (Conditional)
if ($sh_settings['headers_enabled']) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

// 3.1. LAYER 2: FORCE HTTPS (Enforcer)
if ($sh_settings['force_https']) {
    // 1. Check for Localhost Bypass (Prevents lockout during local dev)
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $is_local = (
        strpos($host, 'localhost') !== false || 
        strpos($host, '127.0.0.1') !== false || 
        strpos($host, '192.168.') !== false || 
        strpos($host, '10.') === 0 || 
        strpos($host, '172.') === 0
    );

    // 2. Check if not secure. Supports standard SSL and Cloudflare/Proxy headers.
    $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                 ($_SERVER['SERVER_PORT'] == 443) ||
                 (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');

    if (!$is_secure && !($sh_settings['https_bypass_local'] && $is_local)) {
        $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $redirect);
        exit;
    }
}

// 4. FRONTEND CSRF GENERATOR (Always run if plugin is enabled, to support Firewall)
// We only Block here if headers are enabled OR firewall logic requires it, 
// but the main blocking happens in the Firewall file itself.
// This block ensures index.php doesn't process actions if tokens mismatch.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p_action = $_POST['plugin_action'] ?? $_GET['plugin_action'] ?? '';
    $is_exempt = (
        $p_action === 'remote_upload' || 
        $p_action === 'live_sync_delta' ||
        isset($_POST['auth_action']) || 
        (isset($_POST['action']) && $_POST['action'] === 'upload_only') ||
        (isset($_GET['sys_action']) && $_GET['sys_action'] === 'checkpoint') || 
        $p_action === 'bk_prepare_export'
    );

    if (!$is_exempt) {
        $sent_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        // If firewall is active, this check is redundant but safe. 
        // If firewall is OFF, this check is still good practice for index.php interactions.
        if (!hash_equals($_SESSION['cjos_csrf_token'], $sent_token)) {
            if (isset($_POST['plugin_action']) || isset($_POST['action'])) {
                // Only block strictly if we are "hardening". 
                // To avoid breaking legacy, we mostly rely on the Firewall file for the hard block.
                // But we return error for AJAX consistency.
                if ($sh_settings['headers_enabled']) { // Use this flag as general "Hardening Active" proxy
                    header('HTTP/1.1 403 Forbidden');
                    header('Content-Type: application/json');
                    die(json_encode(['status' => 'error', 'message' => 'Security Token Mismatch. Refresh page.']));
                }
            }
        }
    }
}

// 5. BACKEND HANDLERS
if (isset($_POST['plugin_action'])) {
    
    // SAVE CONFIG
    if ($_POST['plugin_action'] === 'sh_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $sh_settings['headers_enabled'] = ($_POST['headers'] === 'true');
        $sh_settings['force_https'] = ($_POST['force_https'] === 'true');
        $sh_settings['https_bypass_local'] = ($_POST['https_bypass_local'] === 'true');
        file_put_contents($sh_config_file, json_encode($sh_settings));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // FIREWALL LOGIC
    $backend_path = CJOS_PATH_APP . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'backend.php';
    $firewall_path = CJOS_PATH_DATA . DIRECTORY_SEPARATOR . 'firewall-private.php';
    $patch_string = "include_once CJOS_PATH_DATA . DIRECTORY_SEPARATOR . 'firewall-private.php';";

    if ($_POST['plugin_action'] === 'sh_get_status') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $hasFile = file_exists($firewall_path);
        $content = file_exists($backend_path) ? file_get_contents($backend_path) : '';
        $isPatched = strpos($content, 'firewall-private.php') !== false;
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $is_local = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false || strpos($host, '192.168.') !== false || strpos($host, '10.') === 0);
        echo json_encode([
            'status' => 'success', 
            'firewall' => ($hasFile && $isPatched), 
            'headers' => $sh_settings['headers_enabled'], 
            'https' => $sh_settings['force_https'],
            'bypass_enabled' => $sh_settings['https_bypass_local'],
            'is_local' => $is_local
        ]);
        exit;
    }

    if ($_POST['plugin_action'] === 'sh_install_firewall') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $code = <<<'PHP'
<?php
// --- STATE-AWARE FIREWALL ---
if (session_status() === PHP_SESSION_NONE) { ini_set("session.cookie_httponly", 1); session_start(); }
header("X-Frame-Options: SAMEORIGIN");

// 1. Check if plugin is actually enabled in config
$sh_conf_path = CJOS_PATH_DATA . "/ui-config.json";
if (file_exists($sh_conf_path)) {
    $sh_conf = json_decode(file_get_contents($sh_conf_path), true);
    $sh_enabled = $sh_conf["plugins_enabled"]["plugin_SecurityHardener"] ?? "true";
    if ($sh_enabled === "false" || $sh_enabled === false) return; 
}

// 2. Firewall Logic
if (isset($_GET["action"]) && $_GET["action"] === "get_config") return;
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if(isset($_POST["action"]) && $_POST["action"] === "upload_only") return;
    $token = $_POST["csrf_token"] ?? $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
    $sess = $_SESSION["cjos_csrf_token"] ?? "";
    if (empty($sess) || !hash_equals($sess, $token)) {
        header("HTTP/1.1 403 Forbidden");
        die(json_encode(["status" => "error", "message" => "Firewall: Invalid Security Token"]));
    }
}
?>
PHP;
        file_put_contents($firewall_path, $code);
        $content = file_get_contents($backend_path);
        if (strpos($content, 'firewall-private.php') === false) {
            $content = preg_replace('/^<\?php\s*/', "<?php\n" . $patch_string . "\n", $content, 1);
            file_put_contents($backend_path, $content);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['plugin_action'] === 'sh_remove_firewall') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        if (file_exists($firewall_path)) unlink($firewall_path);
        $content = file_get_contents($backend_path);
        $content = str_replace($patch_string . "\n", "", $content);
        $content = str_replace($patch_string, "", $content);
        file_put_contents($backend_path, $content);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // SAFE UNINSTALL
    if ($_POST['plugin_action'] === 'sh_safe_uninstall') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        // 1. Remove Firewall
        if (file_exists($firewall_path)) unlink($firewall_path);
        $content = file_get_contents($backend_path);
        $content = str_replace($patch_string . "\n", "", $content);
        $content = str_replace($patch_string, "", $content);
        file_put_contents($backend_path, $content);

        // 2. Disable Plugin in UI Config
        $uiConfigPath = CJOS_PATH_DATA . '/ui-config.json';
        if (file_exists($uiConfigPath)) {
            $uiData = json_decode(file_get_contents($uiConfigPath), true);
            $uiData['plugins_enabled']['plugin_SecurityHardener'] = "false"; // Disable
            file_put_contents($uiConfigPath, json_encode($uiData, JSON_PRETTY_PRINT));
        }

        echo json_encode(['status' => 'success']);
        exit;
    }
}

// 6. SETTINGS UI
$plugin_settings_map['SecurityHardener'] = <<<'HTML'
    <!-- LAYER 0: HTTPS -->
    <div data-sui-setting="Force HTTPS" data-sui-desc="Redirect all insecure traffic to SSL/TLS." data-sui-id="sh-https-toggle" data-sui-onchange="saveShConfig()"></div>
    
    <div id="sh-https-bypass-row" style="display:none;">
        <div data-sui-setting="Allow Local Bypass" data-sui-desc="Disable HTTPS enforcement on Localhost/LAN." data-sui-id="sh-https-bypass-toggle" data-sui-onchange="saveShConfig()"></div>
    </div>

    <div id="sh-https-status-row" style="display:none; padding: 0 16px 12px 16px; margin-top:-8px;">
        <div style="display:flex; align-items:center; gap:6px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">
            <span style="color:var(--text-secondary);">Detection:</span>
            <span id="sh-https-mode-label">Checking...</span>
        </div>
    </div>

    <div id="sh-https-safety-box" style="display:none; background:var(--warn-bg); border:1px solid var(--border-color); color:var(--warn-text); border-radius:12px; padding:12px; margin: 0 16px 16px 16px;">
        <div style="display:flex; gap:10px; align-items:start;">
            <div style="font-size:18px;">⚠️</div>
            <div style="font-size:11px; color:#856404; line-height:1.4;">
                <strong>HTTPS SAFETY INFO</strong><br>
                If you enable this and get a "Connection Refused" error, you can manually disable it by editing this file on your server:
                <code style="display:block; background:rgba(0,0,0,0.05); padding:4px; border-radius:4px; margin-top:4px; font-family:monospace;">data/security-config.json</code>
                Set <span style="font-family:monospace;">"force_https": false</span> to regain access.
            </div>
        </div>
    </div>

    <!-- LAYER 1: HEADERS -->
    <div data-sui-setting="Browser Shields" data-sui-desc="Security Headers (XSS, Clickjacking protection)." data-sui-id="sh-headers-toggle" data-sui-onchange="toggleShHeaders(this.checked)"></div>

    <!-- LAYER 2: FIREWALL -->
    <div class="setting-item vertical">
        <div class="setting-text-wrap">
            <label class="setting-label">Backend Firewall</label>
            <div class="setting-desc" id="sh-status-label">Checking status...</div>
        </div>
        <div style="margin-top:8px;">
            <div data-sui-switch="true" data-sui-id="sh-fw-toggle" data-sui-onchange="toggleShFirewall(this.checked)"></div>
        </div>
    </div>
    
    <div id="sh-warning-box" style="display:none; background:var(--btn-bg); border:1px solid var(--border-color); border-radius:12px; padding:12px; margin: 0 16px 16px 16px;">
        <div style="display:flex; gap:10px; align-items:start;">
            <div style="font-size:20px;">🛡️</div>
            <div style="font-size:12px; color:#004085; line-height:1.4;">
                <strong>FIREWALL ACTIVE</strong><br>
                The firewall is now state-aware. Toggling this plugin "OFF" will automatically deactivate the protection.
                <div style="margin-top:4px; opacity:0.8; font-style:italic;">To physically remove the code patches from your server files, use the "Safe Uninstall" button below.</div>
            </div>
        </div>
    </div>

    <!-- SAFE EXIT -->
    <div class="setting-item">
        <button onclick="shSafeUninstall()" class="text-btn" style="width:100%; color:var(--danger); border:1px solid var(--border-color); border-radius:12px; padding:12px; font-weight:600;">Safe Uninstall & Disable</button>
    </div>
HTML;

// 7. CLIENT JS (Interceptor & Logic)
$plugin_js .= <<<'JS'
// --- SECURITY HARDENER JS ---
const cjosCsrfToken = '
JS . $csrf_token . <<<'JS'
';

// Interceptors
const originalFetch = window.fetch;
window.fetch = async (url, options) => {
    if (options && options.method && options.method.toUpperCase() === 'POST') {
        if (options.body instanceof FormData) {
            options.body.append('csrf_token', cjosCsrfToken);
        }
    }
    return originalFetch(url, options);
};
const originalXhrSend = XMLHttpRequest.prototype.send;
XMLHttpRequest.prototype.send = function(data) {
    if (this._method && this._method.toUpperCase() === 'POST' && data instanceof FormData) {
        data.append('csrf_token', cjosCsrfToken);
    }
    return originalXhrSend.apply(this, arguments);
};

window.addEventListener('load', () => refreshShStatus());

async function refreshShStatus() {
    const label = document.getElementById('sh-status-label');
    
    // Initial Hydration from Data Bridge
    if (window.__SH_BRIDGE__) {
        const b = window.__SH_BRIDGE__;
        const hdToggle = document.getElementById('sh-headers-toggle');
        const httpsToggle = document.getElementById('sh-https-toggle');
        const bypassToggle = document.getElementById('sh-https-bypass-toggle');
        if (hdToggle) hdToggle.checked = b.headers_enabled;
        if (httpsToggle) httpsToggle.checked = b.force_https;
        if (bypassToggle) bypassToggle.checked = b.https_bypass_local;
    }
    const fwToggle = document.getElementById('sh-fw-toggle');
    const hdToggle = document.getElementById('sh-headers-toggle');
    const httpsToggle = document.getElementById('sh-https-toggle');
    const bypassToggle = document.getElementById('sh-https-bypass-toggle');
    const warn = document.getElementById('sh-warning-box');
    
    if(!fwToggle) return;

    try {
        const data = await window.sui.api('sh_get_status', {}, { toast: false });
        if (data) {
            // Update Headers Toggle
            hdToggle.checked = data.headers;
            if(httpsToggle) httpsToggle.checked = data.https;
            if(bypassToggle) bypassToggle.checked = data.bypass_enabled;

            // Update HTTPS Mode UI
            const bypassRow = document.getElementById('sh-https-bypass-row');
            const statusRow = document.getElementById('sh-https-status-row');
            const modeLabel = document.getElementById('sh-https-mode-label');
            const safetyBox = document.getElementById('sh-https-safety-box');

            if (data.https) {
                bypassRow.style.display = 'flex';
                statusRow.style.display = 'block';
                safetyBox.style.display = 'block';
                if (data.is_local && data.bypass_enabled) {
                    modeLabel.innerText = 'Bypass (Local/LAN)';
                    modeLabel.style.color = '#FF9500';
                } else {
                    modeLabel.innerText = 'Enforcing HTTPS';
                    modeLabel.style.color = 'var(--primary)';
                }
            } else {
                bypassRow.style.display = 'none';
                statusRow.style.display = 'none';
                safetyBox.style.display = 'none';
            }
            
            // Update Firewall Toggle
            fwToggle.checked = data.firewall;
            fwToggle.disabled = false;
            
            if (data.firewall) {
                label.innerText = 'Active (Patched)';
                label.style.color = '#34C759';
                warn.style.display = 'block';
            } else {
                label.innerText = 'Inactive';
                label.style.color = '#FF9500';
                warn.style.display = 'none';
            }
        }
    } catch(e) { label.innerText = 'Status check failed'; }
}

window.saveShConfig = async function() {
    const headers = document.getElementById('sh-headers-toggle').checked;
    const https = document.getElementById('sh-https-toggle').checked;
    const bypass = document.getElementById('sh-https-bypass-toggle').checked;
    
    await window.sui.api('sh_save_config', { 
        headers: headers, 
        force_https: https, 
        https_bypass_local: bypass 
    }, { toast: false });
};

// Legacy binding for compatibility
window.toggleShHeaders = window.saveShConfig;

window.toggleShFirewall = async function(enabled) {
    const toggle = document.getElementById('sh-fw-toggle');
    toggle.disabled = true; 
    const action = enabled ? 'sh_install_firewall' : 'sh_remove_firewall';
    try {
        await window.sui.api(action, {}, { toast: false });
        refreshShStatus();
    } catch(e) { refreshShStatus(); }
};

window.shSafeUninstall = async function() {
    window.openConfirm("Safe Uninstall", 'This will REMOVE the firewall, DISABLE security headers, and DISABLE the SecurityHardener plugin.\n\nAre you sure?', async () => {
        try {
            await window.sui.api('sh_safe_uninstall', {}, { toast: false });
            window.openConfirm("Security Hardened", 'Uninstalled successfully. App will reload.', () => {
                location.reload();
            }, false, "OK", null);
        } catch(e) {}
    }, true);
};
JS;
?>