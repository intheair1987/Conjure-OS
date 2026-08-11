<?php
// ==============================================================================
// 0. TIMEZONE & CONFIG LOADER
// ==============================================================================
require_once __DIR__ . '/paths.php';
$base_dir = CJOS_PATH_APP;
$tz_file = CJOS_PATH_DATA . '/timezone.json';
$timezone = 'UTC'; 

// Load Timezone
if (file_exists($tz_file)) {
    $tz_data = json_decode(file_get_contents($tz_file), true);
    if (!empty($tz_data['detected_value'])) $timezone = $tz_data['detected_value'];
    if (!empty($tz_data['mode']) && $tz_data['mode'] === 'Manual' && !empty($tz_data['manual_value'])) $timezone = $tz_data['manual_value'];
}
if (isset($_COOKIE['cjos_manual_timezone']) && $_COOKIE['cjos_manual_timezone'] !== 'Auto') $timezone = $_COOKIE['cjos_manual_timezone'];
try { date_default_timezone_set($timezone); } catch(Exception $e) { date_default_timezone_set('UTC'); }

// LOAD UI CONFIG (Layouts, Plugins)
$ui_config_file = CJOS_PATH_DATA . '/ui-config.json';
$ui_config = file_exists($ui_config_file) ? json_decode(file_get_contents($ui_config_file), true) : [];

// LOAD THEME CONFIG (Foundation for Zero-Flash SSR)
$tp_config_file = CJOS_PATH_DATA . '/theme-presets-config.json';
$tp_config = file_exists($tp_config_file) ? json_decode(file_get_contents($tp_config_file), true) : ['light_theme'=>'default','dark_theme'=>'midnight','mode'=>'light'];
$active_theme_key = ($tp_config['mode'] === 'dark') ? ($tp_config['dark_theme'] ?? 'midnight') : ($tp_config['light_theme'] ?? 'default');

// ==============================================================================
// SERVER LOGIC
// ==============================================================================
set_time_limit(0); 
ini_set('memory_limit', '512M');
$root_dir = CJOS_PATH_ROOT;
$rec_dir = CJOS_PATH_STORAGE . '/audio';
$trans_dir = CJOS_PATH_STORAGE . '/text';
$db_file = $root_dir . '/conjure.db';
$rel_rec_path = str_replace(CJOS_PATH_ROOT . '/', '', $rec_dir);
$rel_trans_path = str_replace(CJOS_PATH_ROOT . '/', '', $trans_dir);

// --- DEMO MODE INTERCEPT (FILE BASED) ---
$demo_state_file = CJOS_PATH_DATA . '/demo-mode-private.json';
$is_demo_mode = false;
if (file_exists($demo_state_file)) {
    $dm_state = json_decode(file_get_contents($demo_state_file), true);
    if (isset($dm_state['enabled'])) $is_demo_mode = (bool)$dm_state['enabled'];
} else {
    // Fresh installation default: enable Demo Mode automatically and persist state
    $is_demo_mode = true;
    @file_put_contents($demo_state_file, json_encode(['enabled' => true]));
}

if ($is_demo_mode) {
    $demo_dir = CJOS_PATH_DATA . '/demo';
    if (!is_dir($demo_dir)) mkdir($demo_dir, 0777, true);
    $db_file = $demo_dir . '/demo.db';
    $rec_dir = $demo_dir . '/audio';
    $trans_dir = $demo_dir . '/text';
    $rel_rec_path = str_replace(CJOS_PATH_ROOT . '/', '', $rec_dir);
    $rel_trans_path = str_replace(CJOS_PATH_ROOT . '/', '', $trans_dir);
}

if (!is_dir($rec_dir)) mkdir($rec_dir, 0777, true);
if (!is_dir($trans_dir)) mkdir($trans_dir, 0777, true);

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // SQLite Concurrency & I/O Optimization
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=10000;"); // Wait up to 10s for locks

    $db->exec("CREATE TABLE IF NOT EXISTS logs (
        id TEXT PRIMARY KEY, date_display TEXT, audio_path TEXT, transcription TEXT, timestamp INTEGER
    )");

    // AI Assistant Tables
    $db->exec("CREATE TABLE IF NOT EXISTS ai_assistants (
        id TEXT PRIMARY KEY,
        name TEXT,
        role_desc TEXT,
        prompt TEXT,
        model_override TEXT,
        temperature REAL,
        commit_mode TEXT,
        workflow_json TEXT,
        is_active INTEGER DEFAULT 1,
        max_turns INTEGER DEFAULT 2
    )");

    // Add max_turns column if missing
    $colsAsst = $db->query("PRAGMA table_info(ai_assistants)")->fetchAll(PDO::FETCH_ASSOC);
    $hasMaxTurns = false;
    foreach ($colsAsst as $c) { if ($c['name'] === 'max_turns') $hasMaxTurns = true; }
    if (!$hasMaxTurns) {
        $db->exec("ALTER TABLE ai_assistants ADD COLUMN max_turns INTEGER DEFAULT 2");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS ai_suggestions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        log_id TEXT,
        assistant_id TEXT,
        actions_json TEXT,
        reasoning TEXT,
        created_at INTEGER
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS ai_audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        log_id TEXT,
        event_type TEXT,
        assistant_id TEXT,
        message TEXT,
        details TEXT,
        timestamp INTEGER
    )");

    // Performance Indexes for Instant Deletions, Sorting, and Joins (13,000+ Notes Scale)
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON logs(timestamp DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_sugg_log ON ai_suggestions(log_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_audit_log ON ai_audit_log(log_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_folder_map_log ON folder_map(log_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_stack_members_log ON stack_members(log_id)");
    } catch (Throwable $e) {}

    // Add ai_processed column to logs if missing
    $cols = $db->query("PRAGMA table_info(logs)")->fetchAll(PDO::FETCH_ASSOC);
    $hasAiCol = false;
    foreach ($cols as $c) { if ($c['name'] === 'ai_processed') $hasAiCol = true; }
    if (!$hasAiCol) {
        $db->exec("ALTER TABLE logs ADD COLUMN ai_processed INTEGER DEFAULT 0");
    }

    // Add ai_assistant_id column if missing
    $hasAsstId = false;
    foreach ($cols as $c) { if ($c['name'] === 'ai_assistant_id') $hasAsstId = true; }
    if (!$hasAsstId) {
        $db->exec("ALTER TABLE logs ADD COLUMN ai_assistant_id TEXT DEFAULT NULL");
    }

    $hasOriginalText = false;
    foreach ($cols as $c) { if ($c['name'] === 'original_text') $hasOriginalText = true; }
    if (!$hasOriginalText) {
        $db->exec("ALTER TABLE logs ADD COLUMN original_text TEXT DEFAULT NULL");
    }

    $hasNickname = false;
    $colsAsst = $db->query("PRAGMA table_info(ai_assistants)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($colsAsst as $c) { if ($c['name'] === 'nickname') $hasNickname = true; }
    if (!$hasNickname) {
        $db->exec("ALTER TABLE ai_assistants ADD COLUMN nickname TEXT DEFAULT NULL");
    }

    $hasTriggers = false;
    foreach ($colsAsst as $c) { if ($c['name'] === 'trigger_phrases') $hasTriggers = true; }
    if (!$hasTriggers) {
        $db->exec("ALTER TABLE ai_assistants ADD COLUMN trigger_phrases TEXT DEFAULT NULL");
    }

    // Add reasoning column to ai_suggestions if missing
    try {
        $hasReasoning = false;
        $colsSugg = $db->query("PRAGMA table_info(ai_suggestions)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colsSugg as $c) { if ($c['name'] === 'reasoning') $hasReasoning = true; }
        if (!$hasReasoning) {
            $db->exec("ALTER TABLE ai_suggestions ADD COLUMN reasoning TEXT DEFAULT NULL");
        }
        
        $hasHistory = false;
        $colsSugg = $db->query("PRAGMA table_info(ai_suggestions)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colsSugg as $c) { if ($c['name'] === 'correction_history') $hasHistory = true; }
        if (!$hasHistory) {
            $db->exec("ALTER TABLE ai_suggestions ADD COLUMN correction_history TEXT DEFAULT NULL");
        }

        $hasDiscoveryCtx = false;
        $colsSugg = $db->query("PRAGMA table_info(ai_suggestions)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colsSugg as $c) { if ($c['name'] === 'discovery_context') $hasDiscoveryCtx = true; }
        if (!$hasDiscoveryCtx) {
            $db->exec("ALTER TABLE ai_suggestions ADD COLUMN discovery_context TEXT DEFAULT NULL");
        }

        $hasTurnHistory = false;
        $colsSugg = $db->query("PRAGMA table_info(ai_suggestions)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colsSugg as $c) { if ($c['name'] === 'turn_history') $hasTurnHistory = true; }
        if (!$hasTurnHistory) {
            $db->exec("ALTER TABLE ai_suggestions ADD COLUMN turn_history TEXT DEFAULT NULL");
        }
    } catch (Throwable $e) {}

} catch (Throwable $e) { die("DB Error: " . $e->getMessage()); }

// --- PLUG-AND-PLAY SYSTEM (SERVER SYNC & SMART 3-STATE ROUTER) ---
$plugin_buttons = [];
$plugin_tools = [];
$plugin_widgets = [];
$plugin_settings_map = [];
$plugin_js = "";
$all_found_plugins = [];
$plugin_pages = [];    
$plugin_overlays = []; 
$plugin_descriptions = [];
$plugin_states = [];

$enabled_map = $ui_config['plugins_enabled'] ?? [];

// Detect API Request vs Standard UI Render
$is_api_request = isset($_REQUEST['plugin_action']) || isset($_REQUEST['action']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

foreach (glob(CJOS_PATH_PLUGINS . "/*.php") as $filename) {
    $name = basename($filename, '.php');
    
    // Extract Description from Header
    $header = file_get_contents($filename, false, null, 0, 512);
    if (preg_match('/\/\/ DESCRIPTION:\s*(.*)/', $header, $matches)) {
        $plugin_descriptions[$name] = trim($matches[1]);
    }
    $all_found_plugins[] = $name;
    
    // Determine 3-State Lifecycle (active, dormant, disabled)
    $key = "plugin_" . $name;
    $plugin_state = 'active';
    
    if (isset($enabled_map[$key])) {
        $val = $enabled_map[$key];
        if ($val === 'dormant' || $val === 'headless') {
            $plugin_state = 'dormant';
        } elseif ($val === 'disabled' || $val === 'false' || $val === false || $val === '0') {
            $plugin_state = 'disabled';
        } else {
            $plugin_state = 'active';
        }
    } elseif (isset($_COOKIE[$key])) {
        $val = $_COOKIE[$key];
        if ($val === 'dormant' || $val === 'headless') {
            $plugin_state = 'dormant';
        } elseif ($val === 'disabled' || $val === 'false' || $val === false || $val === '0') {
            $plugin_state = 'disabled';
        }
    }
    
    $plugin_states[$name] = $plugin_state;

    // State 1: Disabled -> Skip completely
    if ($plugin_state === 'disabled') {
        continue;
    }
    
    // State 2: Dormant -> Include ONLY for API requests; omit UI assets during page load
    if ($plugin_state === 'dormant') {
        if ($is_api_request) {
            include_once $filename;
        }
        continue;
    }
    
    // State 3: Active -> Full execution (UI + Cards + Settings + API)
    include_once $filename;
}

// --- STANDARD API HANDLERS (Proxy to backend for simplicity if called directly) ---
if (isset($_GET['action']) || isset($_POST['action'])) {
    include CJOS_PATH_APP . '/api/backend.php';
    exit;
}

// Ingestion logic moved to plugins/ConjureCore.php
$logs_data = $db->query("SELECT * FROM logs ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$logs_json = json_encode($logs_data);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" id="meta-theme-color" content="#FFFFFF">
    <title>Conjure</title>
    <?php $rel_app = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_APP); ?>
    <link rel="stylesheet" href="<?php echo $rel_app; ?>/css/style.css?v=<?php echo filemtime($base_dir . '/css/style.css'); ?>">
    <script>
        // Server-Side Theme State (Handshake for Plugins)
        window.__THEME_STATE__ = <?php echo json_encode($tp_config); ?>;
    </script>
    <style id="tp-ssr-vars">
        <?php
        // SSR Theme Bridge: Variable Injection
        if (isset($tp_themes_registry) && isset($tp_themes_registry[$active_theme_key])) {
            $theme = $tp_themes_registry[$active_theme_key];
            
            echo ":root { ";
            // 1. Theme Variables
            if (isset($theme['vars'])) {
                foreach ($theme['vars'] as $prop => $val) { echo "$prop: $val; "; }
            }
            
            // 2. Manual Overrides (Cookies)
            $m_sys = $_COOKIE['cjos_sys_color'] ?? '';
            if ($m_sys && $m_sys !== 'theme-default') echo "--system-bar-bg: $m_sys; ";
            $m_fade = $_COOKIE['cjos_fade_color'] ?? '';
            if ($m_fade && $m_fade !== 'theme-default') echo "--bottom-fade-bg: $m_fade; ";
            
            $sidebar_w = $ui_config['appearance']['sidebar_width'] ?? '27';
            echo "--sidebar-width: {$sidebar_w}vw; ";

            echo " } ";
            
            // 3. Extra Styles (Gradients, Fonts, etc.)
            if (isset($theme['extra'])) echo $theme['extra'];
        }
        ?>
    </style>
</head>
<body>

    <div class="app-frame" id="app-frame">
        <?php include 'modules/header.php'; ?>
        
        <div class="horizontal-viewport">
            <!-- PAGE 1: Main Log List -->
            <div class="page-view">
                <div class="scroll-view" id="main-scroll">
                    <div id="entries-container"></div>
                </div>
                <div class="bottom-fade"></div>
            </div>

            <!-- DYNAMIC PLUGIN PAGES -->
            <?php 
            if(isset($plugin_pages) && !empty($plugin_pages)) {
    foreach($plugin_pages as $pIdx => $page_html) {
        // Extract the scroll-view ID from the HTML string to use as a locator
        preg_match('/id=["\']([^"\']+-view)["\']/', $page_html, $idMatch);
        $viewId = $idMatch[1] ?? "page-$pIdx";
                    
        echo '<div class="page-view lazy-page" data-lazy-id="page-' . $pIdx . '" data-page-id="' . $viewId . '">';
        echo '<template id="tpl-page-' . $pIdx . '">' . $page_html . '</template>';
        echo '</div>';
    }
}?>
        </div>

        <div id="toast">Copied</div>
    </div>

    <div class="sidebar-resizer" id="sidebar-resizer"></div>

    <?php include 'modules/settings.php'; ?>
    
    <!-- PLUGIN OVERLAYS -->
    <?php 
    if(isset($plugin_overlays) && !empty($plugin_overlays)) {
        foreach($plugin_overlays as $overlay_html) {
            echo $overlay_html;
        }
    }
    ?>

    <script>
        window.CJOS_APP_REL = '<?php echo $rel_app; ?>';
        window.CJOS_API_URL = window.CJOS_APP_REL + '/api/backend.php';
        window.CJOS_ASSET_PATH = window.CJOS_APP_REL + '/js/lib';
        const logs = <?php echo $logs_json; ?>;
        const dashRegisteredTools = <?php echo json_encode($plugin_tools); ?>;
        const dashRegisteredWidgets = <?php echo json_encode($plugin_widgets); ?>;
    </script>
    <script src="<?php echo $rel_app; ?>/js/app.js?v=<?php echo filemtime($base_dir . '/js/app.js'); ?>"></script>
    
    <script>
        <?php echo $plugin_js; ?>
        
        // GLOBAL HELPER: Update UI Config on Server
        async function updateServerUiState(category, key, val) {
            try {
                await window.sui.api('ui_save_state', 
                    { action: 'ui_save_state', category: category, key: key, val: val }, 
                    { toast: false }
                );
            } catch(e) { console.error('UI Sync Error', e); }
        }

        // Updated 3-State Plugin Toggle (Active, Dormant, Disabled) - Non-blocking Batch Updating
        async function togglePluginState(name, newState) {
            let stateKey = newState;
            if (typeof newState === 'boolean') {
                stateKey = newState ? 'active' : 'disabled';
            }
            
            // 1. Persist state change to server
            await updateServerUiState('plugins_enabled', 'plugin_' + name, stateKey);
            
            // 2. Update UI DOM element in place
            if (typeof window.updatePluginRowUI === 'function') {
                window.updatePluginRowUI(name, stateKey);
            }
            
            // 3. Show non-blocking toast feedback
            const labels = { 'active': 'Active', 'dormant': 'Dormant (API)', 'disabled': 'Disabled' };
            const displayName = name.replace(/([A-Z])/g, ' $1').trim();
            const targetLabel = labels[stateKey] || stateKey;
            
            if (window.sui && window.sui.toast) {
                window.sui.toast(`${displayName}: ${targetLabel}`, { 
                    color: stateKey === 'disabled' ? 'var(--danger)' : (stateKey === 'dormant' ? 'var(--warn-bg)' : 'var(--success-bg)') 
                });
            }
            if (window.sui && window.sui.haptic) {
                window.sui.haptic('light');
            }
            
            // 4. Reveal "Apply & Reload" button in settings header
            if (typeof window.showSettingsReloadPrompt === 'function') {
                window.showSettingsReloadPrompt();
            }
        }
    </script>
    

</body>
</html>