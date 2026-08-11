<?php
// ==============================================================================
// PLUGIN: Authentication
// DESCRIPTION: PIN Protection.
// FIXED: Lockout status now overrides valid cookies.
// ==============================================================================

// --- CONFIGURATION ---
$auth_config_file = CJOS_PATH_DATA . '/authentication-private.json';
$auth_old_file = CJOS_PATH_DATA . '/authentication-config.json'; // Migration
$auth_cookie_name = 'cjos_auth_token';

// --- MIGRATION: RENAME OLD CONFIG IF EXISTS ---
if (file_exists($auth_old_file) && !file_exists($auth_config_file)) {
    rename($auth_old_file, $auth_config_file);
}

// --- 1. HELPERS ---

function auth_get_config() {
    global $auth_config_file;
    if (file_exists($auth_config_file)) {
        return json_decode(file_get_contents($auth_config_file), true);
    }
    return ['attempts' => 0, 'duration' => 86400];
}

function auth_get_status() {
    global $auth_config_file;
    // 1. No file = Setup
    if (!file_exists($auth_config_file)) return 'setup';
    
    $config = auth_get_config();
    // 2. Too many attempts = Locked
    if (($config['attempts'] ?? 0) >= 3) return 'locked';
    
    // 3. Otherwise = Login
    return 'login';
}

function auth_is_verified() {
    global $auth_config_file, $auth_cookie_name;
    
    if (!file_exists($auth_config_file)) return false;
    
    $config = auth_get_config();
    
    // [SECURITY FIX] PRIORITY 1: CHECK LOCKOUT
    // If the system is locked, deny access IMMEDIATELY, 
    // even if the user has a valid cookie.
    if (($config['attempts'] ?? 0) >= 3) {
        return false; 
    }
    
    // PRIORITY 2: CHECK COOKIE
    if (!isset($_COOKIE[$auth_cookie_name])) return false;
    
    // Parse timestamped token: "timestamp.hash"
    $parts = explode('.', $_COOKIE[$auth_cookie_name]);
    if (count($parts) !== 2) return false;
    
    list($issuedAt, $receivedHash) = $parts;
    $expectedHash = md5($issuedAt . $config['hash'] . $config['created_at']);
    
    // Validate Integrity
    if ($receivedHash !== $expectedHash) return false;
    
    // Enforce Expiry (Server-Side)
    $duration = isset($config['duration']) ? (int)$config['duration'] : 86400;
    if ($duration !== 2147483647 && (time() - (int)$issuedAt) > $duration) {
        return false; 
    }
    
    return true;
}

// --- 2. BACKEND HANDLERS (AJAX) ---

if (isset($_POST['auth_action'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $action = $_POST['auth_action'];
    $inputPin = $_POST['pin'] ?? '';

    // A. SETUP
    if ($action === 'setup') {
        if (file_exists($auth_config_file)) {
            echo json_encode(['status' => 'error', 'message' => 'Config already exists']);
            exit;
        }
        if (strlen($inputPin) !== 4 || !is_numeric($inputPin)) {
            echo json_encode(['status' => 'error', 'message' => 'PIN must be 4 digits']);
            exit;
        }

        $hash = password_hash($inputPin, PASSWORD_DEFAULT);
        $created = time();
        $data = [
            'hash' => $hash,
            'created_at' => $created,
            'attempts' => 0,
            'duration' => 86400
        ];
        
        if (!is_dir(CJOS_PATH_DATA)) mkdir(CJOS_PATH_DATA, 0777, true);
        file_put_contents($auth_config_file, json_encode($data));

        $now = time();
        $token = $now . '.' . md5($now . $hash . $created);
        setcookie($auth_cookie_name, $token, time() + 86400, "/");
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // B. LOGIN
    if ($action === 'login') {
        if (!file_exists($auth_config_file)) {
            echo json_encode(['status' => 'error', 'message' => 'Not set up']); 
            exit;
        }

        $config = auth_get_config();
        
        // Block if already locked
        if (($config['attempts'] ?? 0) >= 3) {
            echo json_encode(['status' => 'error', 'state' => 'locked', 'message' => 'Locked out.']);
            exit;
        }

        if (password_verify($inputPin, $config['hash'])) {
            // Success
            $config['attempts'] = 0;
            file_put_contents($auth_config_file, json_encode($config));
            
            $now = time();
            $token = $now . '.' . md5($now . $config['hash'] . $config['created_at']);
            $duration = isset($config['duration']) ? (int)$config['duration'] : 86400;
            
            setcookie($auth_cookie_name, $token, time() + $duration, "/");
            echo json_encode(['status' => 'success']);
        } else {
            // Fail
            $config['attempts'] = ($config['attempts'] ?? 0) + 1;
            file_put_contents($auth_config_file, json_encode($config));
            
            // [SECURITY FIX] Force clear cookie on failure
            setcookie($auth_cookie_name, '', time() - 3600, "/");

            $attemptsLeft = 3 - $config['attempts'];
            if ($attemptsLeft <= 0) {
                echo json_encode(['status' => 'error', 'state' => 'locked', 'message' => 'Locked out.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => "Wrong PIN. $attemptsLeft attempts left."]);
            }
        }
        exit;
    }

    // C. SAVE SETTINGS
    if ($action === 'save_settings') {
        $config = auth_get_config();
        $duration = (int)$_POST['duration'];
        $config['duration'] = $duration;
        
        file_put_contents($auth_config_file, json_encode($config));
        
        // Refresh cookie
        $now = time();
        $token = $now . '.' . md5($now . $config['hash'] . $config['created_at']);
        setcookie($auth_cookie_name, $token, time() + $duration, "/");
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // D. GET SETTINGS
    if ($action === 'get_settings') {
        $config = auth_get_config();
        echo json_encode(['status' => 'success', 'duration' => $config['duration'] ?? 86400]);
        exit;
    }

    // E. RESET / DELETE PASSWORD
    if ($action === 'reset_auth') {
        if (file_exists($auth_config_file)) {
            unlink($auth_config_file);
        }
        setcookie($auth_cookie_name, '', time() - 3600, "/"); // Clear cookie
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- 3. GATEKEEPER UI ---

$is_api_bypass = false;
// Allow specific internal actions if needed, but keeping it strict is better.
// SecurityHardener (Firewall) handles the API token check separately.
if (isset($_GET['plugin_action']) && in_array($_GET['plugin_action'], ['remote_upload'])) {
    $is_api_bypass = true;
}

if (!$is_api_bypass && !auth_is_verified()) {
    $status = auth_get_status(); 
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>WhisperLog Security</title>
        <style>
            :root { 
                --bg: #F2F2F7; 
                --card: #FFFFFF; 
                --text: #1C1C1E; 
                --subtext: #8E8E93;
                --primary: #007AFF; /* Hardcoded for Auth to prevent theme-clash */
                --danger: #FF3B30; 
                --success: #34C759;
                --key-bg: rgba(0,0,0,0.04);
                --key-active: rgba(0,0,0,0.1);
                --primary-text: #FFFFFF;
            }
            @media (prefers-color-scheme: dark) { 
                :root { 
                    --bg: #000000; 
                    --card: #1C1C1E; 
                    --text: #FFFFFF; 
                    --subtext: #98989D;
                    --primary: #0A84FF; /* Vibrant Blue for Dark Mode */
                    --key-bg: rgba(255,255,255,0.1);
                    --key-active: rgba(255,255,255,0.2);
                    --primary-text: #FFFFFF;
                } 
            }
            body { background-color: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; overflow: hidden; user-select: none; -webkit-tap-highlight-color: transparent; }
            .container { position: relative; width: 100%; max-width: 360px; padding: 20px; box-sizing: border-box; }
            .slide { display: none; flex-direction: column; align-items: center; text-align: center; animation: fadeIn 0.4s ease; }
            .slide.active { display: flex; }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes shake { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-10px); } 40%, 80% { transform: translateX(10px); } }
            
            .logo-icon { width: 64px; height: 64px; background: var(--primary); border-radius: 18px; margin-bottom: 24px; color: white; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 20px rgba(0, 122, 255, 0.3); }
            .title { font-size: 28px; font-weight: 700; margin-bottom: 12px; line-height: 1.2; letter-spacing: -0.5px; }
            .subtitle { font-size: 16px; color: var(--subtext); line-height: 1.5; margin-bottom: 40px; max-width: 280px; }
            .feature-list { text-align: left; width: 100%; margin-bottom: 40px; }
            .feature { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; font-size: 15px; font-weight: 500; color: var(--text); }
            .feat-icon { width: 32px; height: 32px; border-radius: 8px; background: var(--key-bg); display: flex; align-items: center; justify-content: center; color: var(--primary); }
            .btn-primary { width: 100%; padding: 16px; background: var(--primary); color: white; border: none; border-radius: 16px; font-size: 17px; font-weight: 600; cursor: pointer; transition: transform 0.1s; }
            .btn-primary:active { transform: scale(0.98); opacity: 0.9; }
            .pin-display { display: flex; justify-content: center; gap: 20px; margin-bottom: 40px; margin-top: 20px; }
            .pin-dot { width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--text); transition: all 0.2s; opacity: 0.2; }
            .pin-dot.filled { background: var(--text); opacity: 1; transform: scale(1.1); }
            .keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; width: 100%; max-width: 280px; }
            .key { aspect-ratio: 1; border-radius: 50%; background: var(--key-bg); font-size: 26px; font-weight: 400; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; color: var(--text); }
            .key:active { background: var(--key-active); }
            .key.empty { background: transparent; cursor: default; }
            .key.clear { font-size: 20px; font-weight: 600; }
            #auth-msg { height: 20px; font-size: 14px; color: var(--danger); font-weight: 600; margin-bottom: 20px; opacity: 0; transition: opacity 0.3s; }
            #auth-msg.visible { opacity: 1; }
            .shaking { animation: shake 0.4s ease-in-out; }
            .locked-icon { color: var(--danger); width: 64px; height: 64px; margin-bottom: 20px; }
            .code-box { background: var(--key-bg); padding: 4px 8px; border-radius: 6px; font-family: monospace; font-size: 13px; display: inline-block; color: var(--text); border: 1px solid rgba(0,0,0,0.1); margin: 0 2px; }
            .info-box { background: rgba(0,0,0,0.04); border-radius: 12px; padding: 12px; font-size: 13px; color: var(--text); text-align: left; margin-bottom: 30px; line-height: 1.4; border: 1px solid rgba(0,0,0,0.05); }
        </style>
    </head>
    <body>
        <div class="container">
            <!-- 1. WELCOME -->
            <?php if ($status === 'setup'): ?>
            <div id="slide-welcome" class="slide active">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="32" height="32"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
                </div>
                <div class="title">Welcome to<br>WhisperLog</div>
                <div class="subtitle">Your private, self-hosted AI voice journal. Secure, fast, and completely yours.</div>
                <div class="feature-list">
                    <div class="feature"><div class="feat-icon">🎙️</div> Capture thoughts instantly</div>
                    <div class="feature"><div class="feat-icon">✨</div> Transcribe with OpenAI Whisper</div>
                    <div class="feature"><div class="feat-icon">🔒</div> Local storage & Privacy first</div>
                </div>
                <button class="btn-primary" onclick="goToPinSetup()">Get Started</button>
            </div>
            <?php endif; ?>

            <!-- 2. PIN ENTRY -->
            <div id="slide-pin" class="slide <?php echo ($status === 'login') ? 'active' : ''; ?>">
                <div style="margin-bottom:10px; font-weight:600; font-size:14px; text-transform:uppercase; color:var(--subtext); letter-spacing:1px;" id="pin-label">
                    <?php echo ($status === 'setup') ? 'Create PIN' : 'Enter PIN'; ?>
                </div>
                <div class="title" id="pin-title" style="font-size:24px; margin-bottom:0;">
                    <?php echo ($status === 'setup') ? 'Secure your notes' : 'Welcome Back'; ?>
                </div>
                <div class="pin-display" id="dots"><div class="pin-dot"></div><div class="pin-dot"></div><div class="pin-dot"></div><div class="pin-dot"></div></div>
                <div id="auth-msg">Incorrect PIN</div>
                <div class="keypad">
                    <div class="key" onclick="press(1)">1</div><div class="key" onclick="press(2)">2</div><div class="key" onclick="press(3)">3</div>
                    <div class="key" onclick="press(4)">4</div><div class="key" onclick="press(5)">5</div><div class="key" onclick="press(6)">6</div>
                    <div class="key" onclick="press(7)">7</div><div class="key" onclick="press(8)">8</div><div class="key" onclick="press(9)">9</div>
                    <div class="key empty"></div><div class="key" onclick="press(0)">0</div><div class="key clear" onclick="backspace()">⌫</div>
                </div>
            </div>

            <!-- 3. SUCCESS -->
            <div id="slide-success" class="slide">
                <div class="logo-icon" style="background:var(--success); box-shadow:0 10px 20px rgba(52, 199, 89, 0.3);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="32" height="32"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </div>
                <div class="title">All Set!</div>
                <div class="subtitle" style="margin-bottom:20px;">Your password has been set.</div>
                <?php $relData = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_DATA); ?>
                <div class="info-box">
                    <strong>⚠️ Important:</strong><br>
                    If you get locked out (3 failed attempts), you can regain access by deleting this file via your server's file manager:<br>
                    <div class="code-box" style="margin-left:0; margin-top:6px;"><?php echo $relData; ?>/authentication-private.json</div>
                </div>
                <button class="btn-primary" onclick="location.reload()">Open WhisperLog</button>
            </div>

            <!-- 4. LOCKED OUT -->
            <?php if ($status === 'locked'): ?>
            <div id="slide-locked" class="slide active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="locked-icon"><path d="M19 11H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2zm-7-2a3 3 0 0 1 3 3v2H9v-2a3 3 0 0 1 3-3z"></path></svg>
                <div class="title" style="color:var(--danger)">System Locked</div>
                <?php $relData = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_DATA); ?>
                <div class="subtitle" style="color:var(--text); max-width:320px;">
                    Too many failed attempts. For security, access is blocked.<br><br>
                    To reset, access your server files and delete:
                    <div class="code-box"><?php echo $relData; ?>/authentication-private.json</div>
                </div>
                <button class="btn-primary" style="background:#E5E5EA; color:var(--text);" onclick="location.reload()">Reload</button>
            </div>
            <?php endif; ?>
        </div>

        <script>
            let currentPin = "", tempSetupPin = "", setupStep = 1;
            const mode = "<?php echo $status; ?>";
            function goToPinSetup() { switchSlide('slide-welcome', 'slide-pin'); }
            function switchSlide(from, to) { document.getElementById(from).classList.remove('active'); setTimeout(() => document.getElementById(to).classList.add('active'), 50); }
            function press(n) { if(currentPin.length<4) { currentPin+=n; updateDots(); if(navigator.vibrate) navigator.vibrate(10); if(currentPin.length===4) handlePinComplete(); }}
            function backspace() { if(currentPin.length>0) { currentPin=currentPin.slice(0,-1); updateDots(); }}
            function updateDots() { document.querySelectorAll('.pin-dot').forEach((d,i) => d.classList.toggle('filled', i<currentPin.length)); }
            function resetPinUI() { currentPin=""; updateDots(); }
            function showError(m) { const e=document.getElementById('auth-msg'); e.innerText=m; e.classList.add('visible'); document.getElementById('slide-pin').classList.add('shaking'); if(navigator.vibrate)navigator.vibrate([50,50,50]); setTimeout(()=>{document.getElementById('slide-pin').classList.remove('shaking'); e.classList.remove('visible');}, 1500); }
            async function handlePinComplete() {
                if(mode==='setup') {
                    if(setupStep===1) { tempSetupPin=currentPin; document.getElementById('slide-pin').style.opacity='0'; setTimeout(()=>{resetPinUI(); document.getElementById('pin-label').innerText="Confirm PIN"; document.getElementById('pin-title').innerText="Type it again"; document.getElementById('slide-pin').style.opacity='1'; setupStep=2;}, 200); }
                    else { if(currentPin===tempSetupPin) submitToServer(currentPin,'setup'); else { showError("PINs do not match"); resetPinUI(); setTimeout(()=>{setupStep=1; tempSetupPin=""; document.getElementById('pin-label').innerText="Create PIN"; document.getElementById('pin-title').innerText="Secure your notes";}, 500); }}
                } else submitToServer(currentPin,'login');
            }
            async function submitToServer(pin, action) {
                try {
                    const fd = new FormData();
                    fd.append('auth_action', action);
                    fd.append('pin', pin);
                    
                    const res = await fetch('index.php', { method: 'POST', body: fd });
                    const d = await res.json();
                    
                    if(d.status === 'success') {
                        if(action === 'setup') switchSlide('slide-pin', 'slide-success');
                        else location.reload();
                    } else {
                        showError(d.message || "Invalid PIN");
                        resetPinUI();
                        if(d.state === 'locked') setTimeout(() => location.reload(), 1500);
                    }
                } catch(e) {
                    showError("Connection Error");
                    resetPinUI();
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// --- 4. APP SETTINGS UI ---
$plugin_settings_map['Authentication'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Session Duration</label>
        <div class="setting-desc">How long you stay logged in before needing the PIN again.</div>
        <select id="auth-duration-select" onchange="updateAuthDuration(this.value)" style="
            width: 100%; padding: 12px; border-radius: 12px; border: 1px solid var(--border-color);
            background: var(--btn-bg); color: var(--text-primary); font-size: 15px; appearance: none; margin-top:8px;
        ">
            <option value="1800">30 Minutes</option>
            <option value="3600">1 Hour</option>
            <option value="21600">6 Hours</option>
            <option value="86400">24 Hours</option>
            <option value="2147483647">Forever</option>
        </select>
    </div>

    <div class="setting-item vertical">
<label class="setting-label">Recovery Info</label>
<div class="setting-desc" style="background:#FFFBE6; color:#B7791F; padding:10px; border-radius:8px; border:1px solid #F5E8B0; margin-top:8px; line-height:1.4;">
    <strong>Locked out?</strong><br>
    If you forget your PIN, delete this file via your server's file manager:<br>
    <?php $relData = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_DATA); ?>
    <code style="background:rgba(0,0,0,0.05); padding:2px 4px; border-radius:4px; display:inline-block; margin-top:4px;"><?php echo $relData; ?>/authentication-private.json</code>
</div>
        <div style="display:flex; gap:10px; margin-top:12px;">
            <button onclick="authLogout()" class="text-btn" style="flex:1; color:var(--text-primary); border:1px solid #E5E5EA; padding:12px; border-radius:12px; font-weight:600;">Log Out</button>
            <button onclick="authRemovePin()" class="text-btn" style="flex:1; color:white; background:var(--danger); border:none; padding:12px; border-radius:12px; font-weight:600; box-shadow:0 4px 10px rgba(255,59,48,0.2);">Remove PIN</button>
        </div>
    </div>
HTML;

$plugin_js .= <<<'JS'
window.addEventListener("load", async () => {
    try {
        const data = await window.sui.api("get_settings", { auth_action: "get_settings" }, { toast: false });
        if(data) {
            const el = document.getElementById("auth-duration-select");
            if(el) el.value = data.duration;
        }
    } catch(e) {}
});

window.updateAuthDuration = async function(val) {
    await window.sui.api("save_settings", { auth_action: "save_settings", duration: val }, { toast: "Session Updated" });
};

window.authLogout = function() {
    window.openConfirm("Authentication", "Log out now?", () => {
        // Clear cookie for root path to ensure global logout
        document.cookie = "cjos_auth_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
        location.reload();
    });
};

window.authRemovePin = async function() {
    window.openConfirm("Remove PIN", "DANGER: This will remove the PIN protection and make the app accessible to anyone on your network.\n\nAre you sure you want to disable security?", async () => {
        await window.sui.api("reset_auth", { auth_action: "reset_auth" }, { toast: false });
        window.openConfirm("Security Disabled", "PIN protection removed. App will reload.", () => {
            location.reload();
        }, false, "OK", null);
    }, true);
};
JS;
?>