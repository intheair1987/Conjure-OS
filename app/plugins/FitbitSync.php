<?php
// ==============================================================================
// PLUGIN: Fitbit Sync
// DESCRIPTION: Syncs daily activity and calories from Fitbit.
// ==============================================================================

$fb_data_dir = CJOS_PATH_DATA . '/fitbit';
if (!is_dir($fb_data_dir)) mkdir($fb_data_dir, 0777, true);

$fb_db_path = $fb_data_dir . '/fitbit.db';
$fb_secrets_path = $fb_data_dir . '/fitbit-private.json';

// 1. DATABASE SETUP (LAZY INITIALIZATION)
$fb_db = null;
function fb_get_db() {
    global $fb_db, $fb_db_path;
    if ($fb_db !== null) return $fb_db;
    try {
        $fb_db = new PDO("sqlite:$fb_db_path");
        $fb_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $fb_db->exec("CREATE TABLE IF NOT EXISTS sync_history (
            date_ref TEXT PRIMARY KEY,
            total_burn INTEGER,
            active_calories INTEGER,
            steps INTEGER,
            updated_at INTEGER,
            intraday_steps TEXT
        )");

        // Migration: Add intraday column if missing
        $cols = $fb_db->query("PRAGMA table_info(sync_history)")->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('intraday_steps', array_column($cols, 'name'))) {
            $fb_db->exec("ALTER TABLE sync_history ADD COLUMN intraday_steps TEXT");
        }
    } catch (Exception $e) { 
        error_log("Fitbit DB Error: " . $e->getMessage()); 
        $fb_db = false;
    }
    return $fb_db;
}

// 2. OAUTH CONFIG LOADER
function fb_get_config($path) {
    $defaults = [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'],
        'scope' => 'activity profile',
        'first_day_of_week' => 'Sunday',
        'sync_concurrency' => 3
    ];
    if (!file_exists($path)) return $defaults;
    $content = file_get_contents($path);
    $saved = json_decode($content, true);
    if (!is_array($saved)) $saved = [];
    return array_merge($defaults, $saved);
}

// 3. BACKEND HANDLERS
if (isset($_POST['plugin_action']) || isset($_GET['code']) || isset($_GET['plugin_action'])) {

    // Helper: Safe JSON Response
    if (!function_exists('fb_respond')) {
        function fb_respond($data) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode($data);
            exit;
        }
    }

    // Load configuration safely
    $fb_config = fb_get_config($fb_secrets_path);

    // --- OAUTH CALLBACK ---
    if (isset($_GET['code'])) {
        $code = $_GET['code'];
        $auth = base64_encode($fb_config['client_id'] . ":" . $fb_config['client_secret']);
        
        $ch = curl_init('https://api.fitbit.com/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $fb_config['client_id'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $fb_config['redirect_uri'],
            'code' => $code
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic $auth", "Content-Type: application/x-www-form-urlencoded"]);
        
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($res['access_token'])) {
            $res['created_at'] = time();
            
            // Fetch Profile for Display Name verification
            $pch = curl_init("https://api.fitbit.com/1/user/-/profile.json");
            curl_setopt($pch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($pch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $res['access_token']]);
            $profile = json_decode(curl_exec($pch), true);
            curl_close($pch);
            
            if (isset($profile['user']['displayName'])) {
                $res['user_display_name'] = $profile['user']['displayName'];
            }

            // Merge tokens with existing API credentials (client_id, secret, etc)
            $finalConfig = array_merge($fb_config, $res);
            file_put_contents($fb_secrets_path, json_encode($finalConfig));
            
            header("Location: " . $fb_config['redirect_uri']); // Return to app
            exit;
        } else {
            die("Fitbit Auth Failed: " . json_encode($res));
        }
    }

    // --- SAVE CONFIG ---
    if ($_POST['plugin_action'] === 'fb_save_config') {
        $conf = fb_get_config($fb_secrets_path);
        $conf['client_id'] = $_POST['client_id'];
        $conf['client_secret'] = $_POST['client_secret'];
        $conf['redirect_uri'] = $_POST['redirect_uri'];
        if (isset($_POST['first_day_of_week'])) $conf['first_day_of_week'] = $_POST['first_day_of_week'];
        file_put_contents($fb_secrets_path, json_encode($conf));
        fb_respond(['status' => 'success']);
    }

    if ($_POST['plugin_action'] === 'fb_update_setting') {
        $conf = fb_get_config($fb_secrets_path);
        $conf[$_POST['key']] = $_POST['val'];
        file_put_contents($fb_secrets_path, json_encode($conf));
        fb_respond(['status' => 'success']);
    }

    // --- GET STATUS ---
    if ($_POST['plugin_action'] === 'fb_get_status') {
        $has_creds = (!empty($fb_config['client_id']) && !empty($fb_config['client_secret']));
        $is_connected = (isset($fb_config['access_token']) && !empty($fb_config['access_token']));
        
        $user_name = "Not Logged In";
        if ($is_connected) {
            $user_name = $fb_config['user_display_name'] ?? "Connected User";
        }
        
        $last_sync = 0;
        $db_conn = fb_get_db();
        if ($db_conn instanceof PDO) {
            try {
                $stmt = $db_conn->query("SELECT MAX(updated_at) FROM sync_history");
                if ($stmt) $last_sync = $stmt->fetchColumn() ?: 0;
            } catch(Exception $e) {}
        }
        
        fb_respond([
            'status' => 'success', 
            'connected' => $is_connected, 
            'user_name' => $user_name, 
            'last_sync' => $last_sync,
            'has_creds' => $has_creds
        ]);
    }

    // --- GET FULL CONFIG (FOR SETUP UI) ---
    if ($_POST['plugin_action'] === 'fb_get_full_config') {
        $conf = fb_get_config($fb_secrets_path);
        fb_respond(['status' => 'success', 'config' => $conf]);
    }

    // --- DISCONNECT ---
    if ($_POST['plugin_action'] === 'fb_disconnect') {
        if (file_exists($fb_secrets_path)) unlink($fb_secrets_path);
        fb_respond(['status' => 'success']);
    }

    // --- VAULT REPAIR (Browser -> Server) ---
    if ($_POST['plugin_action'] === 'fb_vault_repair') {
        $browserVault = json_decode($_POST['vault'], true);
        if (!$browserVault || !isset($browserVault['access_token'])) fb_respond(['status' => 'error', 'msg' => 'Invalid Vault']);
        
        $current = fb_get_config($fb_secrets_path);
        $bTs = $browserVault['created_at'] ?? 0;
        $sTs = $current['created_at'] ?? 0;

        // Structural and Timestamp Check
        if ($bTs > $sTs && isset($browserVault['refresh_token'])) {
            $merged = array_merge($current, $browserVault);
            file_put_contents($fb_secrets_path, json_encode($merged));
            fb_respond(['status' => 'success', 'msg' => 'Server secrets repaired from browser vault.']);
        } else {
            fb_respond(['status' => 'ignored', 'msg' => 'Server is already newer or equal.']);
        }
    }

    // --- DEBUG: GET RAW RESPONSE ---
    if ($_POST['plugin_action'] === 'fb_get_debug') {
        $resp_path = $fb_data_dir . '/last-response-private.json';
        $raw_resp = file_exists($resp_path) ? file_get_contents($resp_path) : '{"message": "No response captured yet. Run a sync first."}';
        $conf = fb_get_config($fb_secrets_path);
        fb_respond([
            'status' => 'success', 
            'raw' => $raw_resp, 
            'internal' => json_encode($conf)
        ]);
    }

    // --- GET HISTORY (FOR WIDGET) ---
    if ($_POST['plugin_action'] === 'fb_get_history') {
        $mode = $_POST['mode']; // week or month
        $refDate = $_POST['date']; // YYYY-MM-DD
        $firstDay = $fb_config['first_day_of_week'] ?? 'Sunday';
        
        $ts = strtotime($refDate);
        $start = ''; $end = '';

        if ($mode === 'week') {
            $wIdx = (int)date('w', $ts); // 0 (Sun) to 6 (Sat)
            $offset = ($firstDay === 'Monday') ? ($wIdx === 0 ? 6 : $wIdx - 1) : $wIdx;
            $start = date('Y-m-d', strtotime("-$offset days", $ts));
            $end = date('Y-m-d', strtotime("+6 days", strtotime($start)));
        } else {
            $start = date('Y-m-01', $ts);
            $end = date('Y-m-t', $ts);
        }

        $db_conn = fb_get_db();
        if ($db_conn instanceof PDO) {
            $stmt = $db_conn->prepare("SELECT date_ref, steps FROM sync_history WHERE date_ref >= ? AND date_ref <= ? ORDER BY date_ref ASC");
            $stmt->execute([$start, $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = [];
        }
        
        fb_respond([
            'status' => 'success', 
            'history' => $rows, 
            'range' => ['start' => $start, 'end' => $end]
        ]);
    }

    // --- START AUTH ---
    if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'fb_start_auth') {
        if (empty($fb_config['client_id'])) die("Client ID missing. Configure in Settings.");
        
        $url = "https://www.fitbit.com/oauth2/authorize?" . http_build_query([
            'response_type' => 'code',
            'client_id' => $fb_config['client_id'],
            'redirect_uri' => $fb_config['redirect_uri'],
            'scope' => $fb_config['scope'],
            'prompt' => 'consent'
        ]);
        header("Location: $url");
        exit;
    }

    // --- HELPER: REFRESH TOKEN ---
    function fb_refresh_token($fb_secrets_path) {
        // Use a lock file to prevent multiple refreshes at once
        $lock_file = $fb_secrets_path . '.lock';
        $lock_handle = fopen($lock_file, 'w');
        flock($lock_handle, LOCK_EX); // Wait here until other processes are done

        $conf = fb_get_config($fb_secrets_path);
        
        // If the token was refreshed by another process while we were waiting for the lock,
        // check if the access token is now young again (less than 1 minute old).
        if (isset($conf['created_at']) && (time() - $conf['created_at'] < 60)) {
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            return $conf; // Return the tokens already refreshed by the other request
        }

        if (!isset($conf['refresh_token'])) {
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            return false;
        }

        $auth = base64_encode($conf['client_id'] . ":" . $conf['client_secret']);
        $ch = curl_init('https://api.fitbit.com/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $conf['refresh_token']]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic $auth", "Content-Type: application/x-www-form-urlencoded"]);
        
        $res = curl_exec($ch);
        $newTokens = json_decode($res, true);
        curl_close($ch);

        if (isset($newTokens['access_token'])) {
            $conf = array_merge($conf, $newTokens);
            $conf['created_at'] = time();
            file_put_contents($fb_secrets_path, json_encode($conf));
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            return $conf;
        }
        // Save the failure to the debug file so the user can see it in the UI
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
        $fb_data_dir = dirname($fb_secrets_path);
        file_put_contents($fb_data_dir . '/last-response-private.json', $res);

        // If the token is dead, wipe the session so the user can re-login
        $errData = json_decode($res, true);
        if (isset($errData['errors'])) {
            foreach ($errData['errors'] as $e) {
                if ($e['errorType'] === 'invalid_grant') {
                    unset($conf['access_token']);
                    unset($conf['refresh_token']);
                    file_put_contents($fb_secrets_path, json_encode($conf));
                }
            }
        }
        return false;
    }

    // --- MANUAL REFRESH ---
    if ($_POST['plugin_action'] === 'fb_manual_refresh') {
        if (fb_refresh_token($fb_secrets_path)) {
            fb_respond(['status' => 'success', 'message' => 'Tokens refreshed successfully.']);
        } else {
            fb_respond(['status' => 'error', 'message' => 'Refresh failed. Check your API credentials or re-login.']);
        }
    }

    // --- SYNC DATA ---
    if ($_POST['plugin_action'] === 'fb_sync') {
        if (!file_exists($fb_secrets_path)) fb_respond(['status' => 'error', 'message' => 'Not connected']);
        
        $tokens = json_decode(file_get_contents($fb_secrets_path), true);
        $date = $_POST['date'] ?? date('Y-m-d');
        $attempts = 0;
        $data = null;
        $refresh_status = 'not_attempted';
        $refresh_error = null;

        while ($attempts < 2) {
            $ch = curl_init("https://api.fitbit.com/1/user/-/activities/date/$date.json");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $tokens['access_token']]);
            $rawResponse = curl_exec($ch);
            curl_close($ch);

            file_put_contents($fb_data_dir . '/last-response-private.json', $rawResponse);
            $data = json_decode($rawResponse, true);

            // Check for Expired Token Error
            $isExpired = false;
            if (isset($data['errors'])) {
                foreach ($data['errors'] as $err) {
                    if ($err['errorType'] === 'expired_token') $isExpired = true;
                }
            }

            if ($isExpired && $attempts === 0) {
                $refresh_status = 'attempted';
                $newTokens = fb_refresh_token($fb_secrets_path);
                if ($newTokens) {
                    $tokens = $newTokens;
                    $attempts++;
                    $refresh_status = 'success';
                    continue; // Retry with new token
                } else {
                    $refresh_status = 'failed';
                    $refresh_error = json_decode(file_get_contents($fb_data_dir . '/last-response-private.json'), true);
                    break; // Refresh failed, stop trying
                }
            }
            break; 
        }

        if (isset($data['summary'])) {
            // --- NEW: Fetch Intraday Steps ---
            $intradayJson = null;
            $ich = curl_init("https://api.fitbit.com/1/user/-/activities/steps/date/$date/1d/15min.json");
            curl_setopt($ich, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ich, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $tokens['access_token']]);
            $iRes = curl_exec($ich);
            curl_close($ich);
            $iData = json_decode($iRes, true);
            if (isset($iData['activities-steps-intraday'])) {
                $intradayJson = json_encode($iData['activities-steps-intraday']['dataset']);
            }

            $s = $data['summary'];
            $totalBurn = (int)$s['caloriesOut'];
            $activeCals = (int)$s['activityCalories'];
            // Fitbit uses 'caloriesBMR' (all caps) in the JSON response
            $bmr = isset($s['caloriesBMR']) ? (int)$s['caloriesBMR'] : 0;
            $steps = (int)$s['steps'];

            // 1. Update Fitbit Local DB
            $db_conn = fb_get_db();
            if ($db_conn instanceof PDO) {
                $stmt = $db_conn->prepare("INSERT OR REPLACE INTO sync_history (date_ref, total_burn, active_calories, steps, updated_at, intraday_steps) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$date, $totalBurn, $activeCals, $steps, time(), $intradayJson]);
            }

            // 2. Bridge to Calorie Tracker Config (BMR Cache)
            $cFile = CJOS_PATH_DATA . '/calorie-config.json';
            if ($bmr > 0 && file_exists($cFile)) {
                $conf = json_decode(file_get_contents($cFile), true);
                $conf['fitbit_bmr'] = (int)$bmr; 
                file_put_contents($cFile, json_encode($conf));
            }

            // 3. Bridge to Calorie Tracker Logs
            // We log 'activeCalories' as an exercise entry.
            $cal_db_path = CJOS_PATH_DATA . '/calorie-tracker/logs.db';
            if (file_exists($cal_db_path)) {
                $cal_db = new PDO("sqlite:$cal_db_path");
                // Remove existing Fitbit sync for this day to avoid duplicates
                $cal_db->prepare("DELETE FROM cal_logs WHERE date_ref = ? AND meal_type = 'exercise' AND food_name = 'Fitbit Sync'")->execute([$date]);
                // Insert new sync entry (Always save to allow clicking Activity Summary even with 0 activity)
                $ins = $cal_db->prepare("INSERT INTO cal_logs (date_ref, meal_type, food_name, calories, ex_total_burn, ex_bmr, ex_steps, log_timestamp) VALUES (?, 'exercise', 'Fitbit Sync', ?, ?, ?, ?, ?)");
                $ins->execute([$date, -$activeCals, $totalBurn, $bmr, $steps, time()]);
            }

            fb_respond(['status' => 'success', 'active_calories' => $activeCals, 'steps' => $steps, 'intraday' => $intradayJson]);
        } else {
            $sessionLost = false;
            if (isset($refresh_error['errors'])) {
                foreach ($refresh_error['errors'] as $re) {
                    if ($re['errorType'] === 'invalid_grant') $sessionLost = true;
                }
            }
            fb_respond([
                'status' => 'error', 
                'message' => $sessionLost ? 'session_lost' : 'API Error', 
                'session_lost' => $sessionLost,
                'refresh_attempted' => $refresh_status,
                'refresh_details' => $refresh_error,
                'details' => $data
            ]);
        }
    }
}

// 4. UI COMPONENTS
$plugin_widgets[] = [
    'id' => 'fitbit_steps',
    'title' => 'Activity Trend',
    'icon' => '👣',
    'icon_color' => '#00B9C4',
    'html' => '
        <div id="fb-steps-widget-content" style="position:relative; margin-top:12px;">
            <!-- Corner Navigation -->
            <div style="position:absolute; top:-36px; right:0px; display:flex; align-items:center; gap:6px; z-index:10;">
                <div id="fb-view-chip" onclick="event.stopPropagation(); fbCycleView()" style="background:var(--btn-bg); color:var(--text-secondary); font-size:9px; font-weight:900; padding:6px 10px; border-radius:12px; text-transform:uppercase; letter-spacing:0.8px; cursor:pointer; margin-right:4px;">Day</div>
                <button onclick="event.stopPropagation(); fbShiftWidgetDate(-1)" style="background:var(--btn-bg); border:none; width:26px; height:26px; border-radius:8px; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <span data-sui-icon="chevron" data-sui-size="10" data-sui-stroke="4" style="transform:rotate(90deg); display:block;"></span>
                </button>
                <button onclick="event.stopPropagation(); fbShiftWidgetDate(1)" style="background:var(--btn-bg); border:none; width:26px; height:26px; border-radius:8px; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <span data-sui-icon="chevron" data-sui-size="10" data-sui-stroke="4" style="transform:rotate(-90deg); display:block;"></span>
                </button>
            </div>

            <div style="margin-top:0; position:relative;">
                <div id="fb-steps-chart-wrap" style="height:100px; width:100%; position:relative; touch-action:none;">
                    <svg id="fb-steps-svg" style="width:100%; height:100%; overflow:visible;"></svg>
                    <div id="fb-steps-hint" style="position:absolute; top:-5px; transform:translate(-50%, -100%); background:var(--text-primary); color:var(--card-bg); padding:4px 8px; border-radius:6px; font-size:10px; font-weight:800; opacity:0; pointer-events:none; z-index:20; white-space:nowrap;"></div>
                    <div id="fb-steps-line" style="position:absolute; top:0; bottom:0; width:1px; background:var(--primary); opacity:0; pointer-events:none; z-index:10;"></div>
                </div>
                <div id="fb-steps-labels" style="display:flex; justify-content:space-between; margin-top:8px; font-size:9px; font-weight:800; color:var(--text-secondary); opacity:0.6; text-transform:uppercase;">
                    <span>12am</span><span>6am</span><span>12pm</span><span>6pm</span><span>12am</span>
                </div>
            </div>
            <div id="fb-steps-summary" style="margin-top:16px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border-color); padding-top:12px;">
                <div>
                    <span id="fb-steps-total-label" style="font-size:9px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; display:block;">Total Steps</span>
                    <span id="fb-steps-total" style="font-size:18px; font-weight:900; color:var(--text-primary);">0</span>
                </div>
                <div style="text-align:right;">
                    <span id="fb-steps-peak-label" style="font-size:9px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; display:block;">Peak Activity</span>
                    <span id="fb-steps-peak" style="font-size:14px; font-weight:800; color:#00B9C4;">--</span>
                    <div id="fb-steps-date-label" onclick="fbResetWidgetDate()" style="font-size:9px; color:var(--primary); font-weight:700; cursor:pointer; margin-top:2px;">Today</div>
                </div>
            </div>'
];

$plugin_tools[] = [
    'name' => 'Fitbit',
    'desc' => 'Sync activity',
    'sui_icon' => 'activity',
    'color' => 'rgba(0, 185, 196, 0.1)',
    'icon_color' => '#00B9C4',
    'action' => "fbOpenStatus()"
];

$plugin_settings_map['FitbitSync'] = <<<'HTML'
    <div class="setting-item vertical" id="fb-tray-container">
        <div style="text-align:center; padding:20px;">
            <div class="spinner"></div>
            <div style="font-size:11px; color:var(--text-secondary); margin-top:8px;">Checking Connection...</div>
        </div>
    </div>
    <script>setTimeout(() => fbHydrateTray(), 100);</script>
HTML;

$plugin_js .= <<<'JS'
document.addEventListener("DOMContentLoaded", () => {
    fbVaultSync();
});

async function fbVaultSync() {
    const VAULT_KEY = 'cjos_fitbit_token_vault';
    try {
        const res = await window.sui.api('fb_get_full_config', {}, { toast: false });
        if (!res || !res.config) return;
        
        const server = res.config;
        const browserRaw = localStorage.getItem(VAULT_KEY);
        const browser = browserRaw ? JSON.parse(browserRaw) : null;

        const sTs = server.created_at || 0;
        const bTs = browser ? (browser.created_at || 0) : 0;

        if (server.access_token && sTs > bTs) {
            // Server has newer tokens (Standard update)
            const vaultData = {
                access_token: server.access_token,
                refresh_token: server.refresh_token,
                created_at: server.created_at,
                user_display_name: server.user_display_name
            };
            localStorage.setItem(VAULT_KEY, JSON.stringify(vaultData));
        } 
        else if (browser && bTs > sTs) {
            // Browser has newer tokens (Rollback detected!)
            console.warn("[Fitbit] Rollback detected. Repairing server secrets from browser vault...");
            await window.sui.api('fb_vault_repair', { vault: browser }, { toast: "Restoring Fitbit Session..." });
        }
    } catch(e) { console.error("[Fitbit] Vault Sync Error", e); }
}

async function fbHydrateTray() {
    const container = document.getElementById('fb-tray-container');
    if (!container) return;
    
    try {
        // Create a timeout promise
        const timeout = new Promise((_, reject) => setTimeout(() => reject(new Error('Request timed out')), 5000));
        
        // Race the API call against the timeout
        const data = await Promise.race([
            window.sui.api('fb_get_status', {}, { toast: false }),
            timeout
        ]);

        if (data && data.connected) {
            const lastSync = data.last_sync ? new Date(data.last_sync * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : "Never";
            container.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div class="setting-text-wrap">
                        <label class="setting-label">Fitbit Connected</label>
                        <div class="setting-desc">Linked to: <b>${data.user_name}</b></div>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button onclick="fbOpenSetupStudio()" class="icon-btn" style="background:var(--btn-bg); border-radius:10px; color:var(--text-secondary);">
                            <span data-sui-icon="sliders" data-sui-size="16"></span>
                        </button>
                        <button onclick="fbDisconnect()" class="icon-btn danger" style="background:rgba(255,59,48,0.1); border-radius:10px;">
                            <span data-sui-icon="trash" data-sui-size="16"></span>
                        </button>
                    </div>
                </div>
                <div style="background:var(--btn-bg); padding:12px; border-radius:14px; font-size:11px; color:var(--text-primary); display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                    <div><span style="color:var(--text-secondary); font-size:9px; text-transform:uppercase; font-weight:800;">Last Sync</span><br><b>${lastSync}</b></div>
                    <div style="text-align:right;"><span style="color:var(--text-secondary); font-size:9px; text-transform:uppercase; font-weight:800;">Status</span><br><b style="color:var(--primary);">Active</b></div>
                </div>
            `;
            window.suiHydrateIcons(container);
        } else {
            const hasCreds = data ? data.has_creds : false;
            container.innerHTML = `
                <label class="setting-label">Fitbit Integration</label>
                <div class="setting-desc">Connect your account to sync daily burned calories and BMR.</div>
                <div style="display:flex; gap:10px; margin-top:12px;">
                    <button onclick="fbOpenSetupStudio()" class="btn-primary" style="background:var(--btn-bg); color:var(--text-primary); flex:1; box-shadow:none;">API Setup</button>
                    <button id="fb-tray-connect-btn" onclick="fbConnect()" class="btn-primary" style="background:#00B9C4; flex:2; ${!hasCreds ? 'opacity:0.5; pointer-events:none;' : ''}">Login to Fitbit</button>
                </div>
            `;
        }
    } catch(e) { container.innerHTML = '<div style="color:var(--danger); font-size:12px;">Failed to load Fitbit status.</div>'; }
}

async function fbOpenSetupStudio() {
    const res = await window.sui.api('fb_get_debug', {}, { toast: false }); // Using debug to get current config indirectly or add new action
    // Note: We need the config, let's just fetch status which now includes has_creds
    // For simplicity, let's just open with current placeholder logic
    
    window.sui.openStudio({
        id: 'fb-setup',
        title: 'Fitbit API Setup',
        content: `
            <div style="display:flex; flex-direction:column; gap:20px;">
                <div style="font-size:12px; color:var(--text-secondary); line-height:1.4; background:var(--warn-bg); padding:12px; border-radius:12px; border:1px solid #F5E8B0;">
                    <strong>Instructions:</strong> Create a "Personal" application at <a href="https://dev.fitbit.com/apps/new" target="_blank" style="color:var(--primary);">dev.fitbit.com</a>. Set the Redirect URL to match the one below.
                </div>

                ${window.suiSettingRow('Client ID', 'Your Fitbit App Client ID.', '<input type="text" id="fb-conf-id" style="width:150px;">', true)}
                ${window.suiSettingRow('Client Secret', 'Your Fitbit App Client Secret.', '<input type="password" id="fb-conf-secret" style="width:150px;">', true)}
                ${window.suiSettingRow('Redirect URI', 'Must match Fitbit Dev portal exactly.', '<input type="text" id="fb-conf-uri" style="width:100%;">', true)}

                <button id="fb-conf-save-btn" class="btn-primary" style="margin-top:10px;">Save API Credentials</button>
            </div>
        `,
        onSetup: async (content, overlay) => {
            const res = await window.sui.api('fb_get_full_config', {}, { toast: false });
            const c = res.config || {};
            
            if (res && res.config) {
                overlay.querySelector('#fb-conf-id').value = c.client_id || '';
                overlay.querySelector('#fb-conf-secret').value = c.client_secret || '';
                overlay.querySelector('#fb-conf-uri').value = c.redirect_uri || (window.location.origin + window.location.pathname.replace('index.php', ''));
            }
            
            overlay.querySelector('#fb-conf-save-btn').onclick = async () => {
                await window.sui.api('fb_save_config', {
                    client_id: overlay.querySelector('#fb-conf-id').value,
                    client_secret: overlay.querySelector('#fb-conf-secret').value,
                    redirect_uri: overlay.querySelector('#fb-conf-uri').value
                });
                fbHydrateTray();
                window.sui.closeStudio('fb-setup');
            };
        }
    });
}

async function fbConnect() {
    const data = await window.sui.api('fb_get_status', {}, { toast: false });
    if (!data.has_creds) {
        window.openConfirm("Setup Required", "Please configure your Fitbit API credentials first.", () => fbOpenSetupStudio());
        return;
    }

    // We fetch the redirect URL from the server to ensure consistency
    const res = await fetch('index.php', {
        method: 'POST',
        body: new URLSearchParams({ 'plugin_action': 'fb_get_status' }) // Simplified: server handles URL generation
    });
    // For the direct redirect, we'll just trigger the server to handle the redirect or return the URL
    // Actually, let's just build it here since we know the structure:
    const clientId = document.getElementById('fb-conf-id')?.value || ''; // Fallback to prompt if missing
    
    // Better: Add a dedicated redirect action to PHP to keep secrets on server
    window.location.href = 'index.php?plugin_action=fb_start_auth';
}

async function fbDisconnect() {
    window.openConfirm("Disconnect Fitbit", "Are you sure you want to unlink your account?", async () => {
        await window.sui.api('fb_disconnect');
        fbHydrateTray();
    }, true);
}

window.fbUpdateSetting = async function(key, val) {
    await window.sui.api('fb_update_setting', { key: key, val: val });
    renderStepsWidget(); // Refresh chart in case alignment changed
};

async function fbOpenStatus() {
    const data = await window.sui.api('fb_get_status', {}, { toast: false });
    if (!data.connected) {
        window.openConfirm("Fitbit", "Your account is not connected. Connect now?", () => { fbConnect(); });
        return;
    }

    const res = await window.sui.api('fb_get_full_config', {}, { toast: false });
    const c = res.config || {};
    const lastSync = data.last_sync ? new Date(data.last_sync * 1000).toLocaleString() : "Never";

    window.sui.openStudio({
        id: 'fb-status',
        title: 'Fitbit Account',
        content: `
            <div style="display:flex; flex-direction:column; gap:24px;">
                <!-- Account Info -->
                <div style="background:var(--btn-bg); padding:16px; border-radius:16px; display:flex; align-items:center; gap:12px;">
                    <div style="width:40px; height:40px; background:#00B9C4; border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; font-weight:800; font-size:18px;">
                        ${data.user_name.charAt(0)}
                    </div>
                    <div>
                        <div style="font-size:16px; font-weight:700; color:var(--text-primary);">${data.user_name}</div>
                        <div style="font-size:11px; color:var(--text-secondary);">Last Sync: ${lastSync}</div>
                    </div>
                </div>

                <!-- Settings Section -->
                <div>
                    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px;">Display Settings</div>
                    ${window.suiSettingRow('First Day of Week', 'Aligns the Week view chart.', `
                        <div id="fb-status-first-day-btn" onclick="fbPickFirstDay()" style="background:var(--input-bg); color:var(--input-text); padding:8px 12px; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; border:1px solid var(--border-color); min-width:80px; text-align:center;">
                            ${c.first_day_of_week || 'Sunday'}
                        </div>
                    `)}
                    ${window.suiSettingRow('Activity Widget', 'Show or hide the dashboard trend.', `
                        ${window.suiSwitch('fb-status-widget-toggle', localStorage.getItem('cjos_fb_steps_hidden') !== 'true', 'fbToggleStepsWidget()')}
                    `)}
                </div>

                <!-- Sync Actions -->
                <div>
                    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px;">Sync Actions</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <button onclick="fbSync()" class="btn-primary" style="background:var(--btn-bg); color:var(--text-primary); box-shadow:none; font-size:13px; padding:12px;">Sync Recent</button>
                        <button onclick="fbOpenRangeStudio()" class="btn-primary" style="background:var(--btn-bg); color:var(--text-primary); box-shadow:none; font-size:13px; padding:12px;">Custom Range</button>
                    </div>
                </div>

                <!-- Advanced -->
                <div style="border-top:1px solid var(--border-color); padding-top:20px; display:flex; flex-direction:column; gap:10px;">
                    <button onclick="fbForceRefresh()" class="text-btn" style="text-align:left; font-size:14px; padding:8px 0; color:var(--primary);">🔄 Force Token Refresh</button>
                    <button onclick="fbShowDebug()" class="text-btn" style="text-align:left; font-size:14px; padding:8px 0; color:var(--primary);">🔍 Developer Debug Info</button>
                    <button onclick="fbDisconnect()" class="text-btn" style="text-align:left; font-size:14px; padding:8px 0; color:var(--danger);">🔌 Disconnect Account</button>
                </div>
            </div>
        `,
        onSetup: (content, overlay) => {
            window.fbPickFirstDay = () => {
                const options = [
                    { label: 'Sunday', value: 'Sunday' },
                    { label: 'Monday', value: 'Monday' }
                ];
                window.openPicker('First Day of Week', options, c.first_day_of_week || 'Sunday', (val) => {
                    fbUpdateSetting('first_day_of_week', val);
                    const btn = document.getElementById('fb-status-first-day-btn');
                    if (btn) btn.innerText = val;
                    c.first_day_of_week = val; // Update local ref for subsequent picker opens
                });
            };

            window.fbForceRefresh = async () => {
                await window.sui.api('fb_manual_refresh', {}, { toast: "Tokens Refreshed" });
            };
            window.fbShowDebug = async () => {
                const res = await window.sui.api('fb_get_debug', {}, { toast: false });
                const prettyRaw = JSON.stringify(JSON.parse(res.raw), null, 2);
                const prettyInternal = JSON.stringify(JSON.parse(res.internal), null, 2);
                
                window.sui.openStudio({
                    id: 'fb-debug',
                    title: 'Fitbit API Debug',
                    content: `
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <div>
                                <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:800; letter-spacing:0.5px; margin-bottom:8px;">Internal Token State</div>
                                <pre id="fb-internal-pre" style="background:#1e1e1e; color:#7ecfe9; padding:16px; border-radius:14px; font-size:11px; line-height:1.5; overflow:auto; white-space:pre-wrap; font-family:monospace; border:1px solid #333; margin:0;">${prettyInternal.replace(/</g, '&lt;')}</pre>
                                <button onclick="window.fbCopySpecificDebug('fb-internal-pre', this)" class="text-btn" style="font-size:11px; font-weight:700; color:var(--primary); margin-top:8px;">Copy Token State</button>
                            </div>
                            <div>
                                <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:800; letter-spacing:0.5px; margin-bottom:8px;">Last API Response</div>
                                <pre id="fb-raw-pre" style="background:#1e1e1e; color:#d4d4d4; padding:16px; border-radius:14px; font-size:11px; line-height:1.5; overflow:auto; white-space:pre-wrap; font-family:monospace; border:1px solid #333; margin:0;">${prettyRaw.replace(/</g, '&lt;')}</pre>
                                <button onclick="window.fbCopySpecificDebug('fb-raw-pre', this)" class="text-btn" style="font-size:11px; font-weight:700; color:var(--primary); margin-top:8px;">Copy API Response</button>
                            </div>
                        </div>
                    `,
                    onSetup: (c, o) => {
                        window.fbCopySpecificDebug = async function(id, btn) {
                            const pre = document.getElementById(id);
                            if (!pre) return;
                            const text = "```json\n" + pre.innerText.trim() + "\n```";
                            try {
                                await navigator.clipboard.writeText(text);
                                const oldText = btn.innerText;
                                btn.innerText = "✓ Copied!";
                                btn.style.color = "#34C759";
                                if (window.sui && window.sui.haptic) window.sui.haptic('success');
                                setTimeout(() => { btn.innerText = oldText; btn.style.color = ""; }, 2000);
                            } catch (err) { console.error(err); }
                        };
                    }
                });
            };
        }
    });
}

// Consolidated fbConnect logic is handled by the primary fbConnect() function.

async function fbSyncRange(dates) {
    const btn = document.getElementById('dash-tool-Fitbit');
    if (btn) btn.style.opacity = '0.5';
    
    // Fetch latest concurrency setting
    const configRes = await window.sui.api('fb_get_full_config', {}, { toast: false });
    const concurrency = parseInt(configRes?.config?.sync_concurrency || 3);
    
    let success = 0;
    let finished = 0;
    const total = dates.length;
    const queue = [...dates];
    
    if (window.cjosProgressPill) window.cjosProgressPill.show(`Syncing Fitbit (${concurrency} threads)...`);

    const worker = async () => {
        while (queue.length > 0) {
            const dateStr = queue.shift();
            try {
                const res = await window.sui.api('fb_sync', { date: dateStr }, { toast: false, errorToast: false });
                if (res && res.intraday) {
                    localStorage.setItem('cjos_fb_intraday_' + dateStr, res.intraday);
                }
                success++;
            } catch(e) { console.error(`[Fitbit] Failed ${dateStr}:`, e); }
            finished++;
            if (window.cjosProgressPill) {
                window.cjosProgressPill.update(`Syncing... (${finished}/${total})`, Math.round((finished / total) * 100));
            }
        }
    };

    // Start workers
    const workers = [];
    for (let i = 0; i < Math.min(concurrency, total); i++) {
        workers.push(worker());
    }
    await Promise.all(workers);
    
    if(btn) btn.style.opacity = '1';
    if (window.cjosProgressPill) window.cjosProgressPill.done(`Synced ${success}/${total} Days`);
    
    if(typeof fetchCalData === 'function') fetchCalData();
    fbHydrateTray();
    renderStepsWidget();
}

function fbSyncPeriod(days) {
    const dates = [];
    for(let i=0; i<days; i++) {
        const d = new Date();
        d.setDate(d.getDate() - i);
        dates.push(d.toLocaleDateString('sv'));
    }
    fbSyncRange(dates);
}

async function fbOpenRangeStudio() {
    const today = new Date().toLocaleDateString('sv');
    const lastWeek = new Date(Date.now() - 7 * 86400000).toLocaleDateString('sv');
    
    const res = await window.sui.api('fb_get_full_config', {}, { toast: false });
    const c = res.config || {};

    window.sui.openStudio({
        id: 'fb-range-picker',
        title: 'Sync Custom Range',
        content: `
            <div style="display:flex; flex-direction:column; gap:20px;">
                <div style="font-size:12px; color:var(--text-secondary); line-height:1.4;">
                    Select the start and end dates to retrieve historical activity data from Fitbit.
                </div>
                
                ${window.suiSettingRow('Start Date', '', `<input type="date" id="fb-range-start" value="${lastWeek}" style="width:160px;">`, true)}
                ${window.suiSettingRow('End Date', '', `<input type="date" id="fb-range-end" value="${today}" style="width:160px;">`, true)}

                <div style="border-top:1px solid var(--border-color); padding-top:15px;">
                    ${window.suiSettingRow('Sync Concurrency', 'Days to sync in parallel (1-10).', `
                        <div style="display:flex; align-items:center; gap:10px;">
                            ${window.suiSlider('fb-range-concurrency', 1, 10, 1, c.sync_concurrency || 3, "document.getElementById('fb-range-concurrency-val').innerText = this.value; fbUpdateSetting('sync_concurrency', this.value)")}
                            <span id="fb-range-concurrency-val" style="font-size:12px; font-weight:800; color:var(--primary); min-width:20px;">${c.sync_concurrency || 3}</span>
                        </div>
                    `, true)}
                </div>

                <button id="fb-range-run-btn" class="btn-primary" style="margin-top:10px;">Start Synchronization</button>
            </div>
        `,
        onSetup: (content, overlay) => {
            overlay.querySelector('#fb-range-run-btn').onclick = () => {
                const startStr = overlay.querySelector('#fb-range-start').value;
                const endStr = overlay.querySelector('#fb-range-end').value;
                
                if (!startStr || !endStr) return;
                
                let start = new Date(startStr);
                let end = new Date(endStr);
                if (start > end) [start, end] = [end, start];

                const dates = [];
                let curr = new Date(start);
                while (curr <= end) {
                    dates.push(curr.toLocaleDateString('sv'));
                    curr.setDate(curr.getDate() + 1);
                }

                window.sui.closeStudio('fb-range-picker');
                fbSyncRange(dates);
            };
        }
    });
}

window.fbCopyDebugJson = async function(e) {
    if (e) e.stopPropagation();
    const pre = document.getElementById('fb-raw-pre');
    if (!pre) return;
    const text = "```json\n" + pre.innerText.trim() + "\n```";

    try {
        // Try native clipboard first
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            // Fallback for non-HTTPS (localhost)
            const textArea = document.createElement("textarea");
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
        }
        
        // Visual Feedback
        const btn = e.target;
        const oldText = btn.innerText;
        btn.innerText = "✓ Copied!";
        btn.style.background = "#34C759";
        if (window.sui && window.sui.haptic) window.sui.haptic('success');
        
        setTimeout(() => {
            btn.innerText = oldText;
            btn.style.background = "";
        }, 2000);
    } catch (err) {
        console.error('Copy failed', err);
        if (window.openConfirm) window.openConfirm("Copy Failed", "Please select and copy the text manually.", null, false, "OK", null);
    }
}

window.fbHandleError = function(e) {
    console.error("[Fitbit] Sync Error:", e);
    if (window.cjosProgressPill) window.cjosProgressPill.hide();
    if (typeof fbHydrateTray === 'function') fbHydrateTray();

    const isSessionLost = e.message && e.message.toLowerCase().includes('session_lost');
    
    if (isSessionLost) {
        window.openConfirm(
            "Fitbit Session Expired", 
            "Your connection to Fitbit has been revoked or has expired. Reconnect to resume syncing your activity.", 
            () => { fbConnect(); },
            false,
            "Reconnect",
            "Later"
        );
    } else {
        // Industry Standard: Verify Network vs API failure
        const isOffline = !navigator.onLine;
        const title = isOffline ? "No Internet Connection" : "Fitbit Sync Failed";
        const msg = isOffline 
            ? "Your device appears to be offline. Please check your network and try again." 
            : ((e.message && e.message !== 'API Error') ? e.message : "The server could not complete the sync request.");

        window.openConfirm(
            title, 
            msg, 
            () => { fbSync(); }, // Retry action
            false,
            "Retry",
            "Cancel"
        );
    }
};

async function fbSync() {
    const btn = document.getElementById('dash-tool-Fitbit');
    if (btn) btn.style.opacity = '0.5';
    if (window.cjosProgressPill) window.cjosProgressPill.show("Syncing Fitbit...");

    try {
        const isCalToday = (typeof calViewDate !== 'undefined' && calViewDate instanceof Date) 
            ? calViewDate.toDateString() === new Date().toDateString() 
            : true;
        
        const dates = isCalToday ? [
            new Date(Date.now() - 86400000).toLocaleDateString('sv'), // Yesterday
            new Date().toLocaleDateString('sv') // Today
        ] : [calViewDate.toLocaleDateString('sv')];

        for (let i = 0; i < dates.length; i++) {
            if (window.cjosProgressPill) window.cjosProgressPill.update(`Syncing ${dates[i]}...`, Math.round(((i+1)/dates.length)*100));
const res = await window.sui.api('fb_sync', { date: dates[i] }, { toast: false, errorToast: false });
if (res && res.intraday) {
    localStorage.setItem('cjos_fb_intraday_' + dates[i], res.intraday);
}}
        
        if (window.cjosProgressPill) window.cjosProgressPill.done("Fitbit Synced");
        if (typeof fetchCalData === 'function') fetchCalData();
    } catch(e) {
        window.fbHandleError(e);
    }
    if (btn) btn.style.opacity = '1';
}

// --- STEPS WIDGET RENDERER ---
let fbWidgetDate = new Date();
let fbWidgetView = localStorage.getItem('cjos_fb_widget_view') || 'day'; // day, week, month

window.fbCycleView = function() {
    const views = ['day', 'week', 'month'];
    let idx = views.indexOf(fbWidgetView);
    fbWidgetView = views[(idx + 1) % views.length];
    localStorage.setItem('cjos_fb_widget_view', fbWidgetView);
    
    const chip = document.getElementById('fb-view-chip');
    if (chip) chip.innerText = fbWidgetView;
    
    window.sui.haptic('medium');
    renderStepsWidget();
};

window.fbToggleStepsWidget = function() {
    const isHidden = localStorage.getItem('cjos_fb_steps_hidden') === 'true';
    localStorage.setItem('cjos_fb_steps_hidden', !isHidden);
    const widget = document.getElementById('dash-widget-fitbit_steps');
    if (widget) widget.style.display = isHidden ? 'block' : 'none';
    const t = document.getElementById("toast");
    if(t) { t.innerText = isHidden ? "Widget Restored" : "Widget Hidden"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
};

window.fbResetWidgetDate = function() {
    fbWidgetDate = new Date();
    const label = document.getElementById('fb-steps-date-label');
    if (label) label.innerText = "Today";
    renderStepsWidget();
    window.sui.haptic('medium');
};

window.fbShiftWidgetDate = function(delta) {
    const today = new Date();
    
    if (fbWidgetView === 'day') {
        fbWidgetDate.setDate(fbWidgetDate.getDate() + delta);
    } else if (fbWidgetView === 'week') {
        fbWidgetDate.setDate(fbWidgetDate.getDate() + (delta * 7));
    } else if (fbWidgetView === 'month') {
        // Set to 1st of the month before shifting to avoid day-overflow (e.g., Jan 31 -> Feb 28)
        fbWidgetDate.setDate(1);
        fbWidgetDate.setMonth(fbWidgetDate.getMonth() + delta);
    }

    if (fbWidgetDate > today) fbWidgetDate = new Date(today);
    
    // Update Label logic is handled inside renderStepsWidget, 
    // but we trigger a haptic and re-render here.
    renderStepsWidget();
    window.sui.haptic('light');
};

async function renderStepsWidget() {
    const widget = document.getElementById('dash-widget-fitbit_steps');
    if (!widget) return;
    
    window.suiHydrateIcons(widget);
    const svg = document.getElementById('fb-steps-svg');
    const chip = document.getElementById('fb-view-chip');
    if (!svg || !chip) return;

    chip.innerText = fbWidgetView;
    if (localStorage.getItem('cjos_fb_steps_hidden') === 'true') { widget.style.display = 'none'; }

    try {
        const date = fbWidgetDate.toLocaleDateString('sv');
        const width = svg.clientWidth || 300;
        const height = 100;
        let dataset = [];

        // 1. DATA FETCHING
        if (fbWidgetView === 'day') {
            const iDataRaw = localStorage.getItem('cjos_fb_intraday_' + date);
            if (!iDataRaw) {
                svg.innerHTML = `<text x="50%" y="50%" text-anchor="middle" fill="var(--text-secondary)" style="font-size:10px; opacity:0.5;">Sync required for 24h view</text>`;
                return;
            }
            dataset = JSON.parse(iDataRaw).map(d => ({ label: d.time.substring(0,5), value: d.value }));
            document.getElementById('fb-steps-labels').innerHTML = `<span>12am</span><span>6am</span><span>12pm</span><span>6pm</span><span>12am</span>`;
        } else {
            const res = await window.sui.api('fb_get_history', { date: date, mode: fbWidgetView }, { toast: false });
            if (!res || !res.range) return;
            
            // Map sparse DB results to a continuous range
            const start = new Date(res.range.start);
            const end = new Date(res.range.end);
            const map = {};
            res.history.forEach(h => map[h.date_ref] = h.steps);

            dataset = [];
            let curr = new Date(start);
            while (curr <= end) {
                const dStr = curr.toLocaleDateString('sv');
                dataset.push({ label: dStr, value: map[dStr] || 0 });
                curr.setDate(curr.getDate() + 1);
            }

            if (fbWidgetView === 'week') {
                const dayLabels = dataset.map(d => {
                    const date = new Date(d.label + 'T00:00:00');
                    return `<span>${date.toLocaleDateString('en-US', { weekday: 'short' })}</span>`;
                }).join('');
                document.getElementById('fb-steps-labels').innerHTML = dayLabels;
            } else {
                const startLabel = res.range.start.split('-').slice(1).join('/');
                const endLabel = res.range.end.split('-').slice(1).join('/');
                document.getElementById('fb-steps-labels').innerHTML = `<span>${startLabel}</span><span></span><span></span><span></span><span>${endLabel}</span>`;
            }
        }

        // 2. RENDERING
        const maxVal = Math.max(...dataset.map(d => d.value), 50);
        let peakVal = 0; let peakLabel = "--"; let totalSteps = 0;

        if (fbWidgetView === 'day') {
            // Line Chart
            let pathData = "";
            dataset.forEach((pt, i) => {
                const x = (i / (dataset.length - 1)) * width;
                const y = height - (pt.value / maxVal) * height;
                pathData += (i === 0 ? "M" : "L") + ` ${x} ${y}`;
                if (pt.value > peakVal) { peakVal = pt.value; peakLabel = pt.label; }
                totalSteps += pt.value;
            });
            svg.innerHTML = `
                <defs><linearGradient id="stepGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#00B9C4" stop-opacity="0.3" /><stop offset="100%" stop-color="#00B9C4" stop-opacity="0" /></linearGradient></defs>
                <path d="${pathData} L ${width} ${height} L 0 ${height} Z" fill="url(#stepGrad)" />
                <path d="${pathData}" fill="none" stroke="#00B9C4" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
            `;
        } else {
            // Bar Chart
            const barW = (width / dataset.length) * 0.8;
            const gap = (width / dataset.length) * 0.2;
            let bars = "";
            dataset.forEach((pt, i) => {
                const x = i * (barW + gap);
                const h = (pt.value / maxVal) * height;
                const y = height - h;
                const isSelected = pt.label === date;
                bars += `<rect class="fb-bar" data-idx="${i}" x="${x}" y="${y}" width="${barW}" height="${h}" fill="#00B9C4" rx="2" style="transition: opacity 0.1s, stroke 0.1s; opacity: ${isSelected ? '1' : '0.4'}; stroke: ${isSelected ? 'var(--primary)' : 'none'}; stroke-width: 1.5; stroke-dasharray: 2,2;" />`;
                if (pt.value > peakVal) { peakVal = pt.value; peakLabel = pt.label; }
                totalSteps += pt.value;
            });
            svg.innerHTML = bars;
        }

        // 3. SUMMARY UPDATES
        document.getElementById('fb-steps-total-label').innerText = fbWidgetView === 'day' ? 'Total Steps' : (fbWidgetView === 'week' ? '7-Day Total' : '30-Day Total');
        document.getElementById('fb-steps-total').innerText = totalSteps.toLocaleString();
        document.getElementById('fb-steps-peak-label').innerText = fbWidgetView === 'day' ? 'Peak Activity' : 'Best Day';
        document.getElementById('fb-steps-peak').innerText = peakVal > 0 ? (fbWidgetView === 'day' ? `${peakVal} @ ${peakLabel}` : `${peakVal.toLocaleString()}`) : '--';
        
        const today = new Date().toDateString();
        const isToday = fbWidgetDate.toDateString() === today;
        document.getElementById('fb-steps-date-label').innerText = isToday ? "Today" : fbWidgetDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });

        // 4. INTERACTION (Battery Friendly & Layout Thrash Resistant)
        const wrap = document.getElementById('fb-steps-chart-wrap');
        const hint = document.getElementById('fb-steps-hint');
        const line = document.getElementById('fb-steps-line');

        let cachedRect = null;

        const updateCachedRect = () => {
            if (wrap) cachedRect = wrap.getBoundingClientRect();
        };

        const handleMove = (e) => {
            if (!cachedRect) {
                cachedRect = wrap.getBoundingClientRect();
            }
            const xPos = (e.clientX || (e.touches && e.touches[0].clientX)) - cachedRect.left;
            const idx = Math.max(0, Math.min(dataset.length - 1, Math.round((xPos / cachedRect.width) * (dataset.length - 1))));
            const pt = dataset[idx];
            if (pt) {
                const screenX = (idx / (dataset.length - 1)) * cachedRect.width;
                line.style.opacity = fbWidgetView === 'day' ? "1" : "0";
                line.style.left = screenX + "px";
                hint.style.opacity = "1";
                hint.style.left = screenX + "px";
                hint.innerText = fbWidgetView === 'day' ? `${pt.label}: ${pt.value} steps` : `${pt.label.split('-').slice(1).join('/')}: ${pt.value.toLocaleString()}`;

                // Bar Highlighting
                if (fbWidgetView !== 'day') {
                    svg.querySelectorAll('.fb-bar').forEach((bar, bIdx) => {
                        const isHovered = bIdx === idx;
                        const isSelected = dataset[bIdx].label === date;
                        bar.style.opacity = (isHovered || isSelected) ? '1' : '0.4';
                        bar.style.stroke = isHovered ? 'var(--primary)' : (isSelected ? 'var(--primary)' : 'none');
                        bar.style.strokeWidth = isHovered ? '2' : '1.5';
                    });
                }
            }
        };

        // Cache coordinates once at gesture initiation to prevent layout thrashing
        wrap.onpointerenter = updateCachedRect;
        wrap.ontouchstart = updateCachedRect;

        wrap.onpointermove = handleMove;
        wrap.ontouchmove = (e) => { handleMove(e); e.preventDefault(); };
        wrap.onpointerleave = () => { 
            cachedRect = null; // Clear cache
            line.style.opacity = "0"; 
            hint.style.opacity = "0"; 
            if (fbWidgetView !== 'day') {
                svg.querySelectorAll('.fb-bar').forEach((bar, bIdx) => {
                    const isSelected = dataset[bIdx].label === date;
                    bar.style.opacity = isSelected ? '1' : '0.4';
                    bar.style.stroke = isSelected ? 'var(--primary)' : 'none';
                    bar.style.strokeWidth = '1.5';
                });
            }
        };
        wrap.ontouchend = () => {
            cachedRect = null; // Clear cache
        };

    } catch(e) { console.error(e); }
}

// Update the storage logic in fbSync to cache intraday for the widget
const originalFbSync = fbSync;
window.fbSync = async function() {
    await originalFbSync();
    // After sync, the backend has stored intraday. 
    // We would need to fetch it. For now, let's trigger a refresh hook.
    setTimeout(renderStepsWidget, 500);
};

// Hook into widget load
document.addEventListener('DOMContentLoaded', () => {
    // Poll for the SVG existence because widgets load dynamically
    let attempts = 0;
    const poll = setInterval(() => {
        attempts++;
        if (document.getElementById('fb-steps-svg')) {
            renderStepsWidget();
            clearInterval(poll);
        } else if (attempts > 15) {
            // Safety timeout: Halt polling after 15 attempts to prevent background battery drain
            clearInterval(poll);
        }
    }, 1000);
});
JS;
?>