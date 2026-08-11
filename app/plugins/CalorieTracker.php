<?php
// ==============================================================================
// PLUGIN: Calorie Tracker
// Features: Dashboard Widget, Page, AI Logger, Combos.
// DEPENDENCY: FoodDatabase.php (for food table and management UI)
// UPDATED: Fixed Database List Loading & Logger Layout Padding.
// ==============================================================================

// 1. DATABASE SETUP (Standalone Logs & Combos - Lazy Initialization)
$cal_data_dir = CJOS_PATH_DATA . '/calorie-tracker';
$cal_db_path = $cal_data_dir . '/logs.db';
$fdb_path = CJOS_PATH_DATA . '/food-database/food.db';
$cal_db = null;

function cal_get_db() {
    global $cal_db, $cal_data_dir, $cal_db_path, $fdb_path;
    if ($cal_db !== null) return $cal_db;
    
    if (!is_dir($cal_data_dir)) mkdir($cal_data_dir, 0777, true);
    
    try {
        $cal_db = new PDO("sqlite:$cal_db_path");
        $cal_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cal_db->exec("PRAGMA journal_mode=WAL;");
        
        // ATTACH Food Database for joined lookups (Snapshot source)
        if (file_exists($fdb_path)) {
            $cal_db->exec("ATTACH DATABASE '$fdb_path' AS fdb");
        }

        $cal_db->exec("CREATE TABLE IF NOT EXISTS cal_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_ref TEXT,
            meal_type TEXT,
            food_name TEXT,
            calories INTEGER,
            protein REAL DEFAULT 0,
            fat REAL DEFAULT 0,
            sat_fat REAL DEFAULT 0,
            trans_fat REAL DEFAULT 0,
            carbs REAL DEFAULT 0,
            sugar REAL DEFAULT 0,
            sodium REAL DEFAULT 0,
            portion_name TEXT,
            log_timestamp INTEGER
        )");

        // Migration: Ensure existing logs table has all macro columns
        $logCols = $cal_db->query("PRAGMA table_info(cal_logs)")->fetchAll(PDO::FETCH_ASSOC);
        $existingLogCols = array_column($logCols, 'name');
        $missingLogCols = [
            'sat_fat' => 'REAL DEFAULT 0',
            'trans_fat' => 'REAL DEFAULT 0',
            'sugar' => 'REAL DEFAULT 0',
            'sodium' => 'REAL DEFAULT 0',
            'multiplier' => 'REAL DEFAULT 1',
            'mode' => "TEXT DEFAULT 'portion'",
            'ref_amount_g' => 'INTEGER DEFAULT 0',
            'ex_total_burn' => 'INTEGER DEFAULT 0',
            'ex_bmr' => 'INTEGER DEFAULT 0',
            'ex_steps' => 'INTEGER DEFAULT 0',
            'food_id' => 'INTEGER DEFAULT NULL'
        ];
        foreach($missingLogCols as $colName => $colDef) {
            if(!in_array($colName, $existingLogCols)) {
                $cal_db->exec("ALTER TABLE cal_logs ADD COLUMN $colName $colDef");
            }
        }

        $cal_db->exec("CREATE TABLE IF NOT EXISTS cal_combos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT
        )");

        $cal_db->exec("CREATE TABLE IF NOT EXISTS cal_combo_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            combo_id INTEGER,
            food_id INTEGER,
            multiplier REAL DEFAULT 1,
            mode TEXT DEFAULT 'portion'
        )");

        // Migration for combo items
        $ciCols = $cal_db->query("PRAGMA table_info(cal_combo_items)")->fetchAll(PDO::FETCH_ASSOC);
        $existingCiCols = array_column($ciCols, 'name');
        if(!in_array('id', $existingCiCols)) {
            // Handle SQLite limitation: cannot add primary key to existing table easily.
            // Since this is a utility table, we recreate it if ID is missing.
            $cal_db->exec("CREATE TABLE cal_combo_items_new (id INTEGER PRIMARY KEY AUTOINCREMENT, combo_id INTEGER, food_id INTEGER, multiplier REAL DEFAULT 1, mode TEXT DEFAULT 'portion')");
            $cal_db->exec("INSERT INTO cal_combo_items_new (combo_id, food_id, multiplier, mode) SELECT combo_id, food_id, multiplier, mode FROM cal_combo_items");
            $cal_db->exec("DROP TABLE cal_combo_items");
            $cal_db->exec("ALTER TABLE cal_combo_items_new RENAME TO cal_combo_items");
        }
        if(!in_array('multiplier', $existingCiCols)) $cal_db->exec("ALTER TABLE cal_combo_items ADD COLUMN multiplier REAL DEFAULT 1");
        if(!in_array('mode', $existingCiCols)) $cal_db->exec("ALTER TABLE cal_combo_items ADD COLUMN mode TEXT DEFAULT 'portion'");

    } catch (Exception $e) { 
        error_log("CalorieDB Error: " . $e->getMessage()); 
        $cal_db = false;
    }
    return $cal_db;
}

// --- DATA BRIDGE ---
$cFile = CJOS_PATH_DATA . '/calorie-config.json';
$cal_config = file_exists($cFile) ? json_decode(file_get_contents($cFile), true) : ['bmr' => 1800, 'goal' => 2200];
$cal_bridge_json = json_encode($cal_config);
$plugin_js .= "\nwindow.__CAL_BRIDGE__ = $cal_bridge_json;\n";

// 2. BACKEND HANDLERS
if (isset($_POST['plugin_action'])) {

    function cal_tracker_send($data) {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    // --- SAVE SETTINGS ---
    if ($_POST['plugin_action'] === 'cal_save_piggy') {
        $cFile = CJOS_PATH_DATA . '/calorie-config.json';
        $conf = file_exists($cFile) ? json_decode(file_get_contents($cFile), true) : [];
        $conf['piggy_bank'] = [
            'enabled' => ($_POST['enabled'] === 'true'),
            'kg_goal' => (float)$_POST['kg_goal'],
            'start_date' => $_POST['start_date'],
            'end_date' => $_POST['end_date'] ?: null
        ];
        file_put_contents($cFile, json_encode($conf));
        cal_tracker_send(['status' => 'success']);
    }

    if ($_POST['plugin_action'] === 'cal_get_piggy_stats') {
        $db_conn = cal_get_db();
        if (!$db_conn) cal_tracker_send(['status' => 'error', 'message' => 'DB failed']);
        $start = $_POST['start_date'];
        $stmt = $db_conn->prepare("
            SELECT date_ref, 
                   SUM(CASE WHEN meal_type != 'exercise' THEN calories ELSE 0 END) as eaten,
                   SUM(CASE WHEN meal_type == 'exercise' AND food_name != 'Fitbit Sync' THEN ABS(calories) ELSE 0 END) as burned,
                   MAX(ex_bmr) as fb_bmr,
                   MAX(ex_total_burn) as fb_total_burn
            FROM cal_logs 
            WHERE date_ref >= ? 
            GROUP BY date_ref
        ");
        $stmt->execute([$start]);
        cal_tracker_send(['status' => 'success', 'stats' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($_POST['plugin_action'] === 'cal_save_settings') {
        $settings = [
            'manual_bmr' => (int)$_POST['manual_bmr'], 
            'goal' => (int)$_POST['goal'],
            'protein_goal' => (int)$_POST['protein_goal'],
            'use_fitbit_bmr' => ($_POST['use_fitbit_bmr'] === 'true'),
            'hide_main_log_btn' => ($_POST['hide_main_log_btn'] === 'true'),
            'first_day_of_week' => (int)$_POST['first_day_of_week'],
            'recent_limit' => (int)$_POST['recent_limit']
        ];
        // Merge with existing to preserve fitbit_bmr cache
        $cFile = CJOS_PATH_DATA . '/calorie-config.json';
        $existing = file_exists($cFile) ? json_decode(file_get_contents($cFile), true) : [];
        $final = array_merge($existing, $settings);
        
        file_put_contents($cFile, json_encode($final));
        cal_tracker_send(['status' => 'success']);
    }

    if ($_POST['plugin_action'] === 'cal_save_trend_prefs') {
        $cFile = CJOS_PATH_DATA . '/calorie-config.json';
        $conf = file_exists($cFile) ? json_decode(file_get_contents($cFile), true) : [];
        $conf['trend_show_c_in'] = ($_POST['show_c_in'] === 'true');
        $conf['trend_show_c_out'] = ($_POST['show_c_out'] === 'true');
        $conf['trend_show_prot'] = ($_POST['show_prot'] === 'true');
        file_put_contents($cFile, json_encode($conf));
        cal_tracker_send(['status' => 'success']);
    }

    // --- GET DATA ---
    if ($_POST['plugin_action'] === 'cal_get_data') {
        $db_conn = cal_get_db();
        if (!$db_conn) cal_tracker_send(['status' => 'error', 'message' => 'DB failed']);
        $date = $_POST['date'] ?? date('Y-m-d');
        
        $stmt = $db_conn->prepare("SELECT * FROM cal_logs WHERE date_ref = ? ORDER BY log_timestamp ASC");
        $stmt->execute([$date]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Joined query using ATTACHED food_db
        $combosRaw = $db_conn->query("SELECT c.id as combo_id, c.name as combo_name, f.id as food_id, f.name as food_name, f.calories,
                                            ci.id as link_id, ci.multiplier, ci.mode,
                                            f.protein, f.fat, f.sat_fat, f.trans_fat, f.carbs, f.sugar, f.sodium, f.portion_name, f.ref_amount_g, f.ref_calories
                               FROM cal_combos c 
                               LEFT JOIN cal_combo_items ci ON c.id = ci.combo_id 
                               LEFT JOIN fdb.cal_foods f ON ci.food_id = f.id
                               ORDER BY c.name ASC")->fetchAll(PDO::FETCH_ASSOC);
        
        $combos = [];
        foreach($combosRaw as $r) {
            if(!isset($combos[$r['combo_id']])) $combos[$r['combo_id']] = ['id' => $r['combo_id'], 'name' => $r['combo_name'], 'items' => []];
            if($r['food_id']) {
                $combos[$r['combo_id']]['items'][] = [
                    'id' => $r['food_id'], 'link_id' => $r['link_id'], 'name' => $r['food_name'], 'calories' => $r['calories'],
                    'multiplier' => $r['multiplier'], 'mode' => $r['mode'],
                    'protein' => $r['protein'], 'fat' => $r['fat'], 'sat_fat' => $r['sat_fat'], 'trans_fat' => $r['trans_fat'],
                    'carbs' => $r['carbs'], 'sugar' => $r['sugar'], 'sodium' => $r['sodium'], 'portion_name' => $r['portion_name'],
                    'ref_amount_g' => $r['ref_amount_g'],
                    'ref_calories' => $r['ref_calories']
                ];
            }
        }

        $config = ['bmr' => 1800, 'goal' => 2200];
        $cFile = CJOS_PATH_DATA . '/calorie-config.json';
        if(file_exists($cFile)) $config = json_decode(file_get_contents($cFile), true);

        // Pre-fetch Recent Usage for the Logger
        $limit = 10;
        if(!empty($config['recent_limit'])) $limit = (int)$config['recent_limit'];
        $recentStmt = $db_conn->prepare("
            SELECT l.food_name as name, l.calories, l.protein, l.fat, l.carbs, l.portion_name, l.multiplier, f.ref_amount_g, MAX(l.log_timestamp) as last_used 
            FROM cal_logs l 
            LEFT JOIN fdb.cal_foods f ON l.food_name = f.name 
            WHERE l.meal_type != 'exercise' 
            GROUP BY l.food_name 
            ORDER BY MAX(l.log_timestamp) DESC 
            LIMIT ?
        ");
        $recentStmt->execute([$limit]);
        $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        cal_tracker_send(['status' => 'success', 'logs' => $logs, 'combos' => array_values($combos), 'config' => $config, 'recent' => $recent]);
    }

    // --- LOGGING (Snapshot Logic: Full Macros are saved per entry) ---
    if ($_POST['plugin_action'] === 'cal_log_manual') {
        try {
            $db_conn = cal_get_db();
            if (!$db_conn) cal_tracker_send(['status' => 'error', 'message' => 'DB failed']);
            $date = $_POST['date'];
            $meal = $_POST['meal'];
            $name = $_POST['name'];
            $cals = (int)$_POST['calories'];
            $p = (float)($_POST['protein'] ?? 0);
            $f = (float)($_POST['fat'] ?? 0);
            $sf = (float)($_POST['sat_fat'] ?? 0);
            $tf = (float)($_POST['trans_fat'] ?? 0);
            $c = (float)($_POST['carbs'] ?? 0);
            $s = (float)($_POST['sugar'] ?? 0);
            $na = (float)($_POST['sodium'] ?? 0);
            $m = (float)($_POST['multiplier'] ?? 1);
            $md = $_POST['mode'] ?? 'portion';
            $ref = (int)($_POST['ref_amount_g'] ?? 0);
            $pn = ($_POST['portion_name'] === 'null' || !$_POST['portion_name']) ? null : $_POST['portion_name'];
            $fid = (!empty($_POST['food_id']) && $_POST['food_id'] !== 'null') ? (int)$_POST['food_id'] : null;
            $ts = time(); 
            
            $stmt = $db_conn->prepare("INSERT INTO cal_logs (date_ref, meal_type, food_name, calories, protein, fat, sat_fat, trans_fat, carbs, sugar, sodium, portion_name, multiplier, mode, ref_amount_g, log_timestamp, food_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$date, $meal, $name, $cals, $p, $f, $sf, $tf, $c, $s, $na, $pn, $m, $md, $ref, $ts, $fid]);
            cal_tracker_send(['status' => 'success']);
        } catch (Exception $e) {
            cal_tracker_send(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    if ($_POST['plugin_action'] === 'cal_get_recent_usage') {
        $db_conn = cal_get_db();
        if (!$db_conn) cal_tracker_send(['status' => 'error', 'message' => 'DB failed']);
        $limit = 10;
        $cFile = CJOS_PATH_DATA . '/calorie-config.json';
        if(file_exists($cFile)) {
            $conf = json_decode(file_get_contents($cFile), true);
            if(!empty($conf['recent_limit'])) $limit = (int)$conf['recent_limit'];
        }
        // Join with food database to retrieve reference weight for recent items
        $stmt = $db_conn->prepare("
            SELECT l.food_name as name, l.calories, l.protein, l.fat, l.carbs, l.portion_name, l.multiplier, f.ref_amount_g, MAX(l.log_timestamp) as last_used 
            FROM cal_logs l 
            LEFT JOIN fdb.cal_foods f ON l.food_name = f.name 
            WHERE l.meal_type != 'exercise' 
            GROUP BY l.food_name 
            ORDER BY MAX(l.log_timestamp) DESC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        cal_tracker_send(['status' => 'success', 'recent' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($_POST['plugin_action'] === 'cal_delete_entry') {
        $db_conn = cal_get_db();
        if (!$db_conn) cal_tracker_send(['status' => 'error', 'message' => 'DB failed']);
        $db_conn->prepare("DELETE FROM cal_logs WHERE id = ?")->execute([$_POST['id']]);
        cal_tracker_send(['status' => 'success']);
    }

    if ($_POST['plugin_action'] === 'cal_update_log') {
        try {
            $db_conn = cal_get_db();
            if (!$db_conn) cal_tracker_send(['status' => 'error', 'message' => 'DB failed']);
            $stmt = $db_conn->prepare("UPDATE cal_logs SET 
                calories = ?, protein = ?, fat = ?, sat_fat = ?, trans_fat = ?, 
                carbs = ?, sugar = ?, sodium = ?, multiplier = ?, mode = ? 
                WHERE id = ?");
            $stmt->execute([
                (int)$_POST['calories'], (float)$_POST['protein'], (float)$_POST['fat'], 
                (float)$_POST['sat_fat'], (float)$_POST['trans_fat'], (float)$_POST['carbs'], 
                (float)$_POST['sugar'], (float)$_POST['sodium'], (float)$_POST['multiplier'], 
                $_POST['mode'], $_POST['id']
            ]);
            cal_tracker_send(['status' => 'success']);
        } catch (Exception $e) { cal_tracker_send(['status' => 'error', 'message' => $e->getMessage()]); }
    }

    // --- COMBOS ---
    if ($_POST['plugin_action'] === 'cal_manage_combos') {
        $db_conn = cal_get_db();
        if (!$db_conn) cal_tracker_send(['status' => 'error', 'message' => 'DB failed']);
        $mode = $_POST['mode'];
        if ($mode === 'create') {
            $db_conn->prepare("INSERT INTO cal_combos (name) VALUES (?)")->execute([$_POST['name']]);
            cal_tracker_send(['status' => 'success', 'id' => $db_conn->lastInsertId()]);
        }
        if ($mode === 'delete') $db_conn->prepare("DELETE FROM cal_combos WHERE id = ?")->execute([$_POST['id']]);
        if ($mode === 'add_item') {
             $m = (float)($_POST['multiplier'] ?? 1);
             $md = $_POST['mode'] ?? 'portion';
             $db_conn->prepare("INSERT INTO cal_combo_items (combo_id, food_id, multiplier, mode) VALUES (?, ?, ?, ?)")->execute([$_POST['combo_id'], $_POST['food_id'], $m, $md]);
        }
        if ($mode === 'bulk_add_items') {
            $items = json_decode($_POST['items'], true);
            $cid = $_POST['combo_id'];
            if ($items && is_array($items)) {
                $stmt = $db_conn->prepare("INSERT INTO cal_combo_items (combo_id, food_id, multiplier, mode) VALUES (?, ?, ?, ?)");
                foreach ($items as $i) {
                    $stmt->execute([$cid, $i['food_id'], $i['multiplier'], $i['mode']]);
                }
            }
            cal_tracker_send(['status' => 'success']);
        }
        if ($mode === 'update_item') {
             $m = (float)($_POST['multiplier'] ?? 1);
             $md = $_POST['mode'] ?? 'portion';
             $db_conn->prepare("UPDATE cal_combo_items SET multiplier = ?, mode = ? WHERE id = ?")->execute([$m, $md, $_POST['id']]);
        }
        if ($mode === 'remove_item') $db_conn->prepare("DELETE FROM cal_combo_items WHERE id = ?")->execute([$_POST['id']]);
        cal_tracker_send(['status' => 'success']);
    }

    // --- AI PROCESSOR ---
    if ($_POST['plugin_action'] === 'cal_get_month_stats') {
        $db_conn = cal_get_db();
        if (!$db_conn) cal_tracker_send(['status' => 'error', 'message' => 'DB failed']);
        $month = $_POST['month']; // YYYY-MM
        $stmt = $db_conn->prepare("
            SELECT date_ref, 
                   SUM(CASE WHEN meal_type != 'exercise' THEN calories ELSE 0 END) as eaten,
                   SUM(CASE WHEN meal_type == 'exercise' AND food_name != 'Fitbit Sync' THEN ABS(calories) ELSE 0 END) as burned,
                   SUM(protein) as protein,
                   MAX(ex_bmr) as fb_bmr,
                   MAX(ex_total_burn) as fb_total_burn
            FROM cal_logs 
            WHERE date_ref LIKE ? 
            GROUP BY date_ref
        ");
        $stmt->execute([$month . '%']);
        cal_tracker_send(['status' => 'success', 'stats' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }


}

// 3. PAGE HTML
$cal_page = <<<'HTML'
<div class="scroll-view" id="calorie-view" style="background:var(--bg-color);">
    <div>
        <div class="page-title" style="margin-bottom:10px;">Nutrition</div>
    </div>

    <!-- Date Nav -->
    <div id="cal-date-nav-bar" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; background:var(--card-bg); border:1px solid var(--border-color); padding:4px; border-radius:18px; touch-action: pan-y; position: relative;">
        <button onclick="calShiftDate(-1)" class="icon-btn" style="width:50px; height:50px; flex-shrink:0;">&lt;</button>
        <div id="cal-page-date-wrap" onclick="calOpenWidgetsStudio()" style="flex:1; text-align:center; cursor:pointer; padding:8px 0; user-select:none; -webkit-user-select:none; position: relative;">
            <div id="cal-jump-today-pill" onclick="event.stopPropagation(); calJumpToToday();" style="display:none; position:absolute; top:-12px; left:50%; transform:translateX(-50%); background:var(--primary); color:white; font-size:9px; font-weight:900; padding:2px 8px; border-radius:10px; text-transform:uppercase; letter-spacing:0.5px; z-index:10; box-shadow: 0 2px 6px rgba(0,122,255,0.3); white-space: nowrap;">Go to Today</div>
            <div id="cal-page-date" style="font-weight:700; font-size:17px; color:var(--text-primary);">Today</div>
            <div style="font-size:9px; font-weight:800; color:var(--primary); text-transform:uppercase; letter-spacing:0.5px; opacity:0.7;">Tap for Calendar</div>
        </div>
        <button onclick="calShiftDate(1)" class="icon-btn" style="width:50px; height:50px; flex-shrink:0;">&gt;</button>
    </div>

    <!-- Stats Card -->
    <div id="cal-stats-card" style="background:var(--card-bg); border-radius:22px; padding:24px; box-shadow:var(--shadow-card); margin-bottom:24px; border:1px solid var(--border-color);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
            <div>
                <span style="color:var(--text-secondary); font-size:11px; font-weight:700; text-transform:uppercase; display:block; margin-bottom:2px;">Net Balance</span>
                <span id="cal-page-net" style="font-size:28px; font-weight:800; color:var(--text-primary); letter-spacing:-1px;">0</span>
            </div>
            <div style="display:flex; gap:8px;">
                <div id="cal-piggy-pill" onclick="calOpenPiggyStudio()" style="display:none; text-align:right; background:var(--bg-color); padding:8px 12px; border-radius:14px; border:1px solid var(--border-color); cursor:pointer; min-width:80px; display:flex; flex-direction:column; justify-content:center;">
                    <!-- Content injected via JS -->
                </div>
                <div id="cal-protein-pill" style="text-align:right; background:var(--bg-color); padding:8px 12px; border-radius:14px; border:1px solid var(--border-color); min-width:80px; display:flex; flex-direction:column; justify-content:center;">
                    <!-- Content injected via JS -->
                </div>
            </div>
        </div>
        <div style="display:flex; gap:4px; height:10px; border-radius:5px; overflow:hidden; background:var(--bg-color); margin-bottom:16px;">
            <div id="cal-bar-food" style="width:0%; background:var(--primary);"></div>
            <div id="cal-bar-ex" style="width:0%; background:#34C759;"></div>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:13px; color:var(--text-secondary);">
            <span>Eaten: <b id="cal-page-in" style="color:var(--text-primary)">0</b></span>
            <span>Burned: <b id="cal-page-out" style="color:var(--text-primary)">0</b></span>
        </div>
    </div>

    <!-- Meals List -->
    <div id="cal-meals-container"></div>

    <!-- Bottom Actions Row -->
    <div style="display:flex; gap:12px;">
        <button id="cal-main-log-btn" onclick="calOpenLogger()" style="flex:1; padding:16px; background:var(--primary); color:var(--primary-text); border:none; border-radius:18px; font-weight:600; font-size:16px; box-shadow:0 4px 15px rgba(0,122,255,0.3); display:flex; align-items:center; justify-content:center; gap:8px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:20px; stroke-width:2.5;"><path d="M12 5v14M5 12h14"></path></svg> Log Food / Exercise
        </button>
        <button onclick="calOpenMenu()" style="width:54px; height:54px; background:var(--card-bg); color:var(--text-primary); border:1px solid var(--border-color); border-radius:18px; box-shadow:var(--shadow-card); display:flex; align-items:center; justify-content:center; cursor:pointer;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:24px; height:24px; stroke-width:2.5;"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>
        </button>
    </div>
</div>
HTML;

$plugin_tools[] = [
    'name' => 'Nutrition',
    'desc' => 'Track calories',
    'sui_icon' => 'flame',
    'color' => 'rgba(255, 149, 0, 0.1)',
    'icon_color' => '#FF9500',
    'action' => "dashNavToPage('calorie-view')",
    'linked_page' => 'calorie-view',
    'linked_widget' => 'calories'
];

if(!isset($plugin_pages)) $plugin_pages = [];
$plugin_pages[] = $cal_page;

$plugin_widgets[] = [
    'id' => 'calories',
    'title' => 'Nutrition Stats',
    'icon' => '📊',
    'icon_color' => '#FF9500',
    'html' => <<<'HTML'
        <div id="cal-dash-sync-pill" onclick="event.stopPropagation(); if(typeof fbSync === 'function') fbSync();" style="position:absolute; top:18px; right:16px; font-size:9px; font-weight:900; color:var(--primary); background:var(--btn-bg); padding:6px 12px; border-radius:8px; cursor:pointer; transition: opacity 0.2s; z-index:10; border:none; display:flex; align-items:center; justify-content:center;">Sync Fitbit</div>

        <!-- HERO: Net Balance -->
        <div style="margin-top:12px; margin-bottom:16px; background:var(--btn-bg); padding:12px; border-radius:16px; border:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span id="cal-dash-emoji" style="font-size:22px;">🔥</span>
                <div style="display:flex; flex-direction:column;">
                    <span style="font-size:9px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Net Balance</span>
                    <span id="cal-dash-val" style="font-weight:900; font-size:24px; letter-spacing:-1px; line-height:1; color:var(--text-primary);">0</span>
                </div>
            </div>
            <div style="text-align:right; line-height:1.3;">
                <div style="font-size:8px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">BMR: <span id="cal-dash-bmr" style="color:var(--text-primary);">0</span></div>
                <div style="font-size:8px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">ACT: <span id="cal-dash-active" style="color:#34C759;">0</span></div>
            </div>
        </div>

        <!-- TRACKS STACK -->
        <div style="display:flex; flex-direction:column; gap:10px;">
            <!-- Track: Eaten Goal -->
            <div style="background:rgba(0,0,0,0.02); border-radius:12px; padding:8px 10px; border:1px solid var(--border-color);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <span style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">🍴 Daily Intake</span>
                    <span id="cal-dash-eaten-sub" style="font-size:10px; font-weight:800; color:var(--text-primary);">0 / 0</span>
                </div>
                <div style="height:6px; background:var(--btn-bg); border-radius:3px; overflow:hidden;">
                    <div id="cal-dash-bar" style="height:100%; width:0%; background:var(--primary); transition: width 0.5s cubic-bezier(0.16, 1, 0.3, 1);"></div>
                </div>
            </div>

            <!-- Track: Protein -->
            <div style="background:rgba(0,0,0,0.02); border-radius:12px; padding:8px 10px; border:1px solid var(--border-color);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <span style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">💪 Protein</span>
                    <span id="cal-dash-protein" style="font-size:10px; font-weight:800; color:#007AFF;">0 / 0g</span>
                </div>
                <div style="height:6px; background:var(--btn-bg); border-radius:3px; overflow:hidden;">
                    <div id="cal-dash-prot-bar" style="height:100%; width:0%; background:#007AFF; transition: width 0.5s;"></div>
                </div>
            </div>

            <!-- Track: Piggy Bank -->
            <div id="cal-dash-piggy-row" style="display:none; background:rgba(0,0,0,0.02); border-radius:12px; padding:8px 10px; border:1px solid var(--border-color);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <span style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">🐷 Piggy Bank</span>
                    <span id="cal-dash-piggy" style="font-size:10px; font-weight:800; color:#FF9500;">0 / 0</span>
                </div>
                <div style="height:6px; background:var(--btn-bg); border-radius:3px; overflow:hidden;">
                    <div id="cal-dash-piggy-bar" style="height:100%; width:0%; background:#FF9500; transition: width 0.5s;"></div>
                </div>
            </div>
        </div>
HTML
];

// 4. OVERLAYS (Migrated to SharedUI)
if(!isset($plugin_overlays)) $plugin_overlays = [];

$plugin_settings_map['CalorieTracker'] = '';

// 6. JS LOGIC
$plugin_js .= <<<'JS'
// --- CALORIE TRACKER JS ---

let calData = { 
    logs: [], 
    foods: [], 
    combos: [], 
    recent: [], 
    config: window.__CAL_BRIDGE__ || { bmr:1800, goal:2200 } 
};
let calViewDate = new Date();

function calInit() {
    if (typeof window.fdbFetchAll === 'function') {
        window.fdbFetchAll();
    }
    fetchCalData();

    // --- DATE BAR SWIPE LOGIC ---
    const dateBar = document.getElementById('cal-date-nav-bar');
    if (dateBar) {
        let startX = 0;
        dateBar.addEventListener('touchstart', (e) => { startX = e.touches[0].clientX; }, {passive: true});
        dateBar.addEventListener('touchend', (e) => {
            const endX = e.changedTouches[0].clientX;
            const diff = startX - endX;
            if (Math.abs(diff) > 50) {
                calShiftDate(diff > 0 ? 1 : -1);
            }
        }, {passive: true});
    }

    if (window.registerUpdateHook) {
        window.registerUpdateHook(() => {
            if (typeof fdbFetchAll === 'function') fdbFetchAll();
            fetchCalData();
        });
    }
    injectCalorieBottomButton();
}

// Listen for Lazy Hydration
window.addEventListener('cjos-hydrated', (e) => {
    if (e.detail.id === 'calorie-view') {
        calInit();
    }
});

async function fetchCalData() {
    const dateStr = calViewDate.toLocaleDateString('sv');
    const fd = new FormData();
    fd.append('plugin_action', 'cal_get_data'); fd.append('date', dateStr);
    try {
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();
        if(data.status === 'success') {
            // Merge instead of overwrite to preserve foods list from standalone DB
            calData.logs = data.logs || [];
            calData.combos = data.combos || [];
            calData.recent = data.recent || [];
            calData.config = data.config || calData.config;
            
            updateCalPage();
            renderCalDashboard();
            if (typeof window._calRefreshLoggerUI === 'function') window._calRefreshLoggerUI();
        }
    } catch(e) { console.error(e); }
}

// --- WIDGET ---
function renderCalDashboard() {
    const widget = document.getElementById('dash-widget-calories');
    if (!widget) return;
    
    const config = calData.config;
    let consumed = 0, burned = 0, protein = 0;
    
    // Calculate Burn Baseline
    const fbSyncEntry = calData.logs.find(l => l.food_name === 'Fitbit Sync');
    const manualBmr = parseInt(config.manual_bmr || config.bmr || 1800);

    if (!config.use_fitbit_bmr) {
        burned = manualBmr;
    } else if (fbSyncEntry) {
        burned = parseInt(fbSyncEntry.ex_total_burn);
    } else {
        burned = parseInt(config.fitbit_bmr) || manualBmr;
    }

    calData.logs.forEach(l => {
        if (l.meal_type === 'exercise') {
            if (l.food_name !== 'Fitbit Sync') burned += Math.abs(parseInt(l.calories));
        } else {
            consumed += parseInt(l.calories);
            protein += parseFloat(l.protein || 0);
        }
    });

    const bmrVal = (config.use_fitbit_bmr && fbSyncEntry) ? parseInt(fbSyncEntry.ex_bmr) : (parseInt(config.fitbit_bmr) || manualBmr);
    const activeVal = burned - bmrVal;
    const balance = consumed - burned;

    const dashVal = document.getElementById('cal-dash-val');
    const dashBar = document.getElementById('cal-dash-bar');
    const dashProt = document.getElementById('cal-dash-protein');
    const dashProtBar = document.getElementById('cal-dash-prot-bar');
    
    if (dashVal) { 
        dashVal.innerText = (balance > 0 ? "+" : "") + balance; 
        dashVal.style.color = balance > 0 ? "var(--danger)" : "#34C759"; 
    }

    const dashEmoji = document.getElementById('cal-dash-emoji');
    if (dashEmoji) dashEmoji.innerText = balance > 0 ? "😖" : "🔥";
    
    if (document.getElementById('cal-dash-bmr')) document.getElementById('cal-dash-bmr').innerText = bmrVal;
    if (document.getElementById('cal-dash-active')) document.getElementById('cal-dash-active').innerText = activeVal;
    
    const eatenSub = document.getElementById('cal-dash-eaten-sub');
    if (eatenSub) eatenSub.innerText = `${consumed} / ${burned}`;

    if (dashBar) { 
        let pct = Math.min(100, (consumed / burned) * 100); 
        dashBar.style.width = pct + "%"; 
        dashBar.style.background = balance > 0 ? "var(--danger)" : "var(--primary)"; 
    }
    if (dashProt) {
        const protGoal = parseInt(config.protein_goal || 150);
        dashProt.innerHTML = `${Math.round(protein)}<small style="opacity:0.5; margin:0 2px;">/</small>${protGoal}g`;
        if (dashProtBar) dashProtBar.style.width = Math.min(100, (protein / protGoal) * 100) + "%";
    }

    // Piggy Bank Widget Logic
    const piggyRow = document.getElementById('cal-dash-piggy-row');
    const piggyVal = document.getElementById('cal-dash-piggy');
    const piggyBar = document.getElementById('cal-dash-piggy-bar');
    if (config.piggy_bank && config.piggy_bank.enabled && piggyRow) {
        piggyRow.style.display = 'block';
        window.sui.api('cal_get_piggy_stats', { start_date: config.piggy_bank.start_date }, { toast: false })
            .then(data => {
                if (data.status === 'success') {
                    let totalSaved = 0;
                    data.stats.forEach(s => {
                        let dayBurn = 0;
                        if (!config.use_fitbit_bmr) dayBurn = manualBmr + parseInt(s.burned);
                        else if (s.fb_total_burn) dayBurn = parseInt(s.fb_total_burn) + parseInt(s.burned);
                        else dayBurn = (parseInt(s.fb_bmr) || manualBmr) + parseInt(s.burned);
                        totalSaved += (dayBurn - parseInt(s.eaten));
                    });
                    const goal = Math.round(config.piggy_bank.kg_goal * 7700);
                    piggyVal.innerHTML = `${Math.round(totalSaved).toLocaleString()}<small style="opacity:0.5; margin:0 2px;">/</small>${goal.toLocaleString()}`;
                    if (piggyBar) piggyBar.style.width = Math.min(100, (totalSaved / goal) * 100) + "%";
                }
            });
    } else if (piggyRow) {
        piggyRow.style.display = 'none';
    }
}

// --- NAV SYSTEM ---
function calOpenMenu() {
    const iconStyle = 'width:18px; height:18px; stroke-width:1.8; margin-right:12px; vertical-align:middle; opacity:0.8;';
    const options = [
        { label: "Library & Combos", type: "header" },
        { label: `<div style="display:flex; align-items:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="${iconStyle}"><path d="M2 13c0 4 3 7 8 7s8-3 8-7H2Z"/><path d="M18 10h4v10h-4z"/><path d="M18 14h4"/><path d="M7 13v-2M10 13v-3M13 13v-2"/></svg> Food Combos</div>`, value: "combos" },
        { label: `<div style="display:flex; align-items:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="${iconStyle}"><path d="M12 21c-2.5 0-5.5-1.5-6.5-4C4.5 14.5 4 12 4 10c0-3.5 3-5 6-5 1 0 2 .5 3 1.5 1-1 2-1.5 3-1.5 3 0 6 1.5 6 5 0 2-.5 4.5-1.5 7-1 2.5-4 4-6.5 4Z"/><path d="M12 5V2c2 0 3-1 3-1"/></svg> Food Database</div>`, value: "db" },
        { label: `<div style="display:flex; align-items:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="${iconStyle}"><path d="M19 5c-1.5 0-2.8 1.4-3 2-3.5-1.5-11-.3-11 5 0 1.8 0 3 2 4.5V20h4v-2h2v2h4v-3.5c2-1.5 2-2.7 2-4.5 0-5.3-7.5-6.5-11-5"/><path d="M7 11c.7 0 1.3.6 1.3 1.3 0 .7-.6 1.3-1.3 1.3-.7 0-1.3-.6-1.3-1.3 0-.7.6-1.3 1.3-1.3Z"/></svg> Piggy Bank Mode</div>`, value: "piggy" },
        { label: "Configuration", type: "header" },
        { label: `<div style="display:flex; align-items:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="${iconStyle}"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg> Settings (BMR & Goals)</div>`, value: "settings" }
    ];
    window.openPicker("Nutrition Options", options, null, (val) => {
        if (val === 'db') calOpenDbStudio();
        if (val === 'combos') calOpenCombosStudio();
        if (val === 'piggy') calOpenPiggyStudio();
        if (val === 'settings') calOpenSettingsStudio();
    });
}

function calOpenPiggyStudio() {
    const p = calData.config.piggy_bank || { enabled: false, kg_goal: 5, start_date: new Date().toLocaleDateString('sv') };
    window.sui.openStudio({
        id: 'cal-piggy',
        title: 'Piggy Bank Mode',
        content: `
            <div style="display:flex; flex-direction:column; gap:20px;">
                <div style="background:var(--warn-bg); border:1px solid var(--border-color); padding:16px; border-radius:16px; display:flex; gap:12px; align-items:start;">
                    <div style="font-size:24px;">🐷</div>
                    <div style="font-size:12px; color:var(--warn-text); line-height:1.4;">
                        <strong>SAVE CALORIES FOR A GOAL</strong><br>
                        Enter how many kilograms you want to lose. The system will convert this to a calorie deficit goal (7,700 kcal per kg) and track your progress daily.
                    </div>
                </div>

                ${window.suiSettingRow('Enable Piggy Bank', 'Show your accumulated savings on the dashboard.', `
                    ${window.suiSwitch('cal-piggy-enabled', p.enabled)}
                `)}

                ${window.suiSettingRow('Weight Loss Goal', 'How many KG do you want to lose?', `
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="number" id="cal-piggy-kg" step="0.1" value="${p.kg_goal}" style="width:80px; text-align:right; font-weight:700;">
                        <span style="font-size:12px; color:var(--text-secondary);">kg</span>
                    </div>
                `, true)}

                <div style="background:var(--bg-color); padding:12px; border-radius:12px; border:1px solid var(--border-color); text-align:center;">
                    <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:4px;">Total Calorie Goal</div>
                    <div id="cal-piggy-total-label" style="font-size:18px; font-weight:800; color:var(--primary);">0 cal</div>
                </div>

                ${window.suiSettingRow('Start Date', 'When did you start saving?', `
                    <div style="display:flex; align-items:center; gap:8px; background:var(--input-bg); padding:4px 12px; border-radius:10px; border:1px solid var(--border-color);">
                        <input type="date" id="cal-piggy-start" value="${p.start_date}" style="border:none; background:transparent; font-size:14px; font-weight:600; color:var(--primary); outline:none; padding:4px 0; width:130px;">
                    </div>
                `)}

                <button id="cal-piggy-save-btn" class="btn-primary" style="margin-top:10px;">Save Piggy Bank Goal</button>
            </div>
        `,
        onSetup: (content, overlay) => {
            const kgInput = overlay.querySelector('#cal-piggy-kg');
            const totalLabel = overlay.querySelector('#cal-piggy-total-label');
            const updateLabel = () => {
                const kg = parseFloat(kgInput.value) || 0;
                totalLabel.innerText = Math.round(kg * 7700).toLocaleString() + ' cal';
            };
            kgInput.oninput = updateLabel;
            updateLabel();

            overlay.querySelector('#cal-piggy-save-btn').onclick = async () => {
                const enabled = overlay.querySelector('#cal-piggy-enabled').checked;
                const kg = kgInput.value;
                const start = overlay.querySelector('#cal-piggy-start').value;
                await window.sui.api('cal_save_piggy', { enabled: enabled, kg_goal: kg, start_date: start });
                await fetchCalData();
                window.sui.closeStudio('cal-piggy');
            };
        }
    });
}

function calOpenSettingsStudio() {
    const conf = calData.config;
    const useFitbit = conf.use_fitbit_bmr || false;
    const manualBmr = conf.manual_bmr || conf.bmr || 1800; // Fallback to legacy bmr key
    const fitbitBmr = conf.fitbit_bmr || 0;

    window.sui.openStudio({
        id: 'cal-settings',
        title: 'Nutrition Settings',
        content: `
            <div style="display:flex; flex-direction:column; gap:20px;">
                <!-- Manual Section -->
                <div id="cal-sec-manual" style="background:var(--card-bg); border-radius:16px; padding:16px; border:1px solid var(--border-color); transition: opacity 0.3s; opacity: ${useFitbit ? '0.5' : '1'};">
                    <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:12px; letter-spacing:0.5px;">Manual Baseline</div>
                    ${window.suiSettingRow('Manual BMR', 'Set your baseline calories manually.', `
                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="number" id="cal-set-manual-bmr" value="${manualBmr}" ${useFitbit ? 'disabled' : ''} style="width:80px; text-align:right; font-weight:700;">
                            <span style="font-size:12px; color:var(--text-secondary);">cal</span>
                        </div>
                    `)}
                </div>

                <!-- Fitbit Section -->
                <div id="cal-sec-fitbit" style="background:var(--card-bg); border-radius:16px; padding:16px; border:1px solid var(--border-color); transition: opacity 0.3s; opacity: ${!useFitbit ? '0.5' : '1'};">
                    <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:12px; letter-spacing:0.5px;">Fitbit Integration</div>
                    ${window.suiSettingRow('Use Fitbit BMR', 'Sync BMR automatically from your tracker.', `
                        ${window.suiSwitch('cal-set-use-fitbit', useFitbit, 'calUpdateBmrFieldState()')}
                    `)}
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; padding-top:12px; border-top:1px solid var(--border-color);">
                        <span style="font-size:13px; color:var(--text-primary);">Tracker BMR</span>
                        <span style="font-weight:700; color:var(--primary);">${fitbitBmr || '--'} <small style="font-size:10px; opacity:0.6;">cal</small></span>
                    </div>
                </div>
                ${window.suiSettingRow('Daily Goal', 'Your net calorie target for weight management.', `
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="number" id="cal-set-goal" value="${calData.config.goal}" style="width:80px; text-align:right; font-weight:700;">
                        <span style="font-size:12px; color:var(--text-secondary);">cal/day</span>
                    </div>
                `, true)}
                ${window.suiSettingRow('Protein Goal', 'Your daily target for protein intake.', `
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="number" id="cal-set-protein-goal" value="${calData.config.protein_goal || 150}" style="width:80px; text-align:right; font-weight:700;">
                        <span style="font-size:12px; color:var(--text-secondary);">g/day</span>
                    </div>
                `, true)}
                ${window.suiSettingRow('Hide Main Button', 'Hide the large "Log Food" button at the bottom.', `
                    ${window.suiSwitch('cal-set-hide-btn', calData.config.hide_main_log_btn || false)}
                `)}
                ${window.suiSettingRow('First Day of Week', 'Set the starting day for the Insights calendar.', `
                    <div id="cal-set-first-day-trigger" style="display:flex; align-items:center; gap:8px; background:var(--input-bg); padding:8px 12px; border-radius:10px; border:1px solid var(--border-color); cursor:pointer;">
                        <span id="cal-set-first-day-label" style="font-size:14px; font-weight:600; color:var(--primary);">
                            ${parseInt(calData.config.first_day_of_week || 0) === 1 ? 'Monday' : 'Sunday'}
                        </span>
                        <span data-sui-icon="chevron" data-sui-size="12" data-sui-stroke="3"></span>
                        <input type="hidden" id="cal-set-first-day" value="${calData.config.first_day_of_week || 0}">
                    </div>
                `)}
                ${window.suiSettingRow('Recently Used Limit', 'Number of items to show in the prioritized library section.', `
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="number" id="cal-set-recent-limit" value="${calData.config.recent_limit || 10}" style="width:60px; text-align:right; font-weight:700;">
                        <span style="font-size:12px; color:var(--text-secondary);">items</span>
                    </div>
                `)}
                <button id="cal-save-btn" class="btn-primary" style="margin-top:12px;">Save Configuration</button>
            </div>
        `,
        onSetup: (content, overlay) => {
            overlay.querySelector('#cal-save-btn').onclick = () => calSaveSettings();
            
            const trigger = overlay.querySelector('#cal-set-first-day-trigger');
            if (trigger) {
                trigger.onclick = () => {
                    const options = [
                        { label: 'Sunday', value: '0' },
                        { label: 'Monday', value: '1' }
                    ];
                    const current = overlay.querySelector('#cal-set-first-day').value;
                    window.openPicker("First Day of Week", options, current, (val) => {
                        overlay.querySelector('#cal-set-first-day').value = val;
                        overlay.querySelector('#cal-set-first-day-label').innerText = val === '1' ? 'Monday' : 'Sunday';
                    });
                };
            }
            window.suiHydrateIcons(overlay);
        }
    });
}

function calOpenCombosStudio() {
    window.sui.openStudio({
        id: 'cal-combos',
        title: 'Food Combos',
        content: `
            <div style="display:flex; gap:10px; margin-bottom:20px;">
                <input type="text" id="cal-combo-new-name" placeholder="Combo Name" style="flex:1;">
                <button id="cal-create-combo-btn" class="btn-primary" style="padding:10px 16px;">Create</button>
            </div>
            <div id="cal-studio-combo-list"></div>
        `,
        onSetup: (content, overlay) => {
            const renderList = () => {
                const list = overlay.querySelector('#cal-studio-combo-list');
                list.innerHTML = '';
                calData.combos.forEach(c => {
                    const div = document.createElement('div');
                    div.style.cssText = "background:var(--card-bg); border-radius:16px; margin-bottom:12px; padding:16px; border:1px solid var(--border-color);";
                    
                    const itemsHtml = c.items.map((i, idx) => {
                        const m = parseFloat(i.multiplier) || 1;
                        const mode = i.mode || 'portion';
                        const ref = i.ref_amount_g || 0;
                        const adjCals = Math.round(i.calories * m);
                        const displayVal = (mode === 'portion') ? Number(m.toFixed(2)) : Math.round(m * ref);
                        const unitLabel = (mode === 'portion') ? 'portion' : 'g/ml';

                        return `
                        <div class="cal-combo-item-row" style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom: ${idx === c.items.length - 1 ? 'none' : '1px solid var(--border-color)'};">
                            <div style="min-width:0; flex:1; padding-right:10px;">
                                <div style="font-size:13px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${i.name}</div>
                                <div class="cal-combo-adj-label" style="font-size:11px; color:var(--text-secondary);">${adjCals} cal <small>(${i.calories} x ${Number(m.toFixed(2))})</small></div>
                            </div>
                            <div style="display:flex; align-items:center; gap:6px;">
                                <button class="cal-combo-undo" onclick="calResetComboItemPortion(${c.id}, ${i.link_id})" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; color:var(--primary); display:${m !== 1 ? 'flex' : 'none'}; align-items:center; justify-content:center; cursor:pointer; padding:0; flex-shrink:0;">
                                    <span data-sui-icon="undo" data-sui-size="12" data-sui-stroke="3"></span>
                                </button>
                                <div style="position:relative; width:65px; height:34px;">
                                    <input type="number" step="any" inputmode="decimal" value="${displayVal}" 
                                           onfocus="this.select()"
                                           oninput="calUpdateComboItemPortion(${c.id}, ${i.link_id}, this)" 
                                           style="width:100%; padding:2px 4px 10px 4px; font-size:13px; text-align:center; border-radius:8px; height:100%; font-weight:700; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text);">
                                    ${ref > 0 ? `<div onclick="calToggleComboItemMode(${c.id}, ${i.link_id})" style="position:absolute; bottom:2px; left:0; right:0; text-align:center; font-size:7px; font-weight:900; color:var(--primary); cursor:pointer; opacity:0.8;">${unitLabel}</div>` : ''}
                                </div>
                                <button onclick="calComboRemoveItem(${i.link_id})" style="background:none; border:none; color:var(--danger); padding:4px;"><span data-sui-icon="trash" data-sui-size="14"></span></button>
                            </div>
                        </div>`;
                    }).join('');

                    div.innerHTML = `
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                            <span style="font-weight:700; font-size:16px;">${c.name}</span>
                            <button onclick="calComboDelete(${c.id})" style="background:none; border:none; color:var(--danger); font-size:12px; font-weight:700;">Delete Combo</button>
                        </div>
                        <div style="margin-bottom:16px;">${itemsHtml || '<div style="color:var(--text-secondary); font-style:italic; font-size:12px; padding:10px 0;">No items</div>'}</div>
                        
                        <div style="background:var(--bg-color); padding:12px; border-radius:12px; border:1px solid var(--border-color);">
                            <div style="position:relative;">
                                <input type="text" id="cal-combo-search-${c.id}" placeholder="Search foods to add..." 
                                       oninput="calComboSearchFood(${c.id}, this.value)"
                                       onfocus="calComboSearchFood(${c.id}, this.value)"
                                       style="width:100%; font-size:13px; padding-right:30px;">
                                <div id="cal-combo-results-${c.id}" style="display:none; position:absolute; top:100%; left:0; right:0; background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; margin-top:4px; max-height:200px; overflow-y:auto; z-index:100; box-shadow:var(--shadow-floating);"></div>
                            </div>
                        </div>
                    `;
                    list.appendChild(div);
                });
                window.suiHydrateIcons(list);
            };
            
            overlay.querySelector('#cal-create-combo-btn').onclick = async () => {
                const n = overlay.querySelector('#cal-combo-new-name').value;
                if(!n) return;
                await window.sui.api('cal_manage_combos', { mode: 'create', name: n });
                overlay.querySelector('#cal-combo-new-name').value = '';
                await fetchCalData();
                renderList();
            };

            window.calComboSearchFood = (cid, term) => {
                const results = overlay.querySelector(`#cal-combo-results-${cid}`);
                if (!term || term.length < 1) {
                    results.style.display = 'none';
                    return;
                }
                const matches = calData.foods.filter(f => f.name.toLowerCase().includes(term.toLowerCase())).slice(0, 10);
                if (matches.length === 0) {
                    results.style.display = 'none';
                    return;
                }

                results.innerHTML = matches.map(f => `
                    <div onclick="calComboAddFood(${cid}, ${f.id})" style="padding:12px; border-bottom:1px solid var(--border-color); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                        <div style="font-size:13px; font-weight:600; color:var(--text-primary);">${f.name}</div>
                        <div style="font-size:11px; color:var(--text-secondary);">${f.calories} cal</div>
                    </div>
                `).join('');
                results.style.display = 'block';
            };

            window.calComboAddFood = async (cid, fid) => {
                await window.sui.api('cal_manage_combos', { mode: 'add_item', combo_id: cid, food_id: fid });
                await fetchCalData();
                renderList();
                window.sui.haptic('medium');
            };

            // Close results on outside click
            overlay.addEventListener('click', (e) => {
                if (!e.target.closest('[id^="cal-combo-search-"]')) {
                    overlay.querySelectorAll('[id^="cal-combo-results-"]').forEach(r => r.style.display = 'none');
                }
            });

            window.calUpdateComboItemPortion = async (cid, linkId, input) => {
                const combo = calData.combos.find(c => c.id === cid);
                const item = combo.items.find(i => i.link_id === linkId);
                const val = parseFloat(input.value) || 0;
                
                if (item.mode === 'weight' && item.ref_amount_g > 0) item.multiplier = val / item.ref_amount_g;
                else item.multiplier = val;

                // Live UI update for label
                const row = input.closest('.cal-combo-item-row');
                const label = row.querySelector('.cal-combo-adj-label');
                const undo = row.querySelector('.cal-combo-undo');
                if (label) label.innerHTML = `${Math.round(item.calories * item.multiplier)} cal <small>(${item.calories} x ${Number(item.multiplier.toFixed(2))})</small>`;
                if (undo) undo.style.display = (item.multiplier === 1) ? 'none' : 'flex';

                // Persist
                await window.sui.api('cal_manage_combos', { mode: 'update_item', id: linkId, multiplier: item.multiplier, mode: item.mode }, { toast: false });
            };

            window.calToggleComboItemMode = async (cid, linkId) => {
                const combo = calData.combos.find(c => c.id === cid);
                const item = combo.items.find(i => i.link_id === linkId);
                item.mode = (item.mode === 'weight') ? 'portion' : 'weight';
                await window.sui.api('cal_manage_combos', { mode: 'update_item', id: linkId, multiplier: item.multiplier, mode: item.mode }, { toast: false });
                await fetchCalData();
                renderList();
                window.sui.haptic('light');
            };

            window.calResetComboItemPortion = async (cid, linkId) => {
                await window.sui.api('cal_manage_combos', { mode: 'update_item', id: linkId, multiplier: 1, mode: 'portion' }, { toast: false });
                await fetchCalData();
                renderList();
                window.sui.haptic('light');
            };

            renderList();
        }
    });
}

// --- LOGGING ---
function calOpenLogger(initialMeal = 'breakfast') {
    window.sui.openStudio({
        id: 'cal-logger',
        title: 'Log Food',
        content: `
            <div id="cal-staged-area" style="display:none; margin-bottom:20px; background:var(--card-bg); border-radius:18px; border:1px solid var(--border-color); overflow:hidden;">
                <div style="padding:12px 16px; background:var(--btn-bg); display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Staged Items</span>
                    <span id="cal-staged-count" style="background:var(--primary); color:white; font-size:10px; font-weight:800; padding:2px 8px; border-radius:10px;">0</span>
                </div>
                <div id="cal-staged-list" style="padding:8px 16px;"></div>
                <div style="padding:12px 16px; border-top:1px solid var(--border-color);">
                    <button id="cal-commit-staged-btn" class="btn-primary" style="width:100%; font-size:14px; padding:10px;">Save All to Log</button>
                </div>
            </div>

            <!-- SHARED MEAL TYPE -->
            <div id="cal-log-meal-trigger" style="margin-bottom:20px; width:100%; padding:14px; border:1px solid var(--border-color); border-radius:14px; background:var(--card-bg); color:var(--text-primary); font-size:16px; font-weight:700; display:flex; justify-content:space-between; align-items:center; cursor:pointer; box-sizing:border-box; box-shadow:var(--shadow-card);">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:12px; color:var(--text-secondary); text-transform:uppercase; font-weight:800;">Logging For</span>
                    <span id="cal-log-meal-label">Breakfast</span>
                </div>
                <span data-sui-icon="chevron" data-sui-size="14" data-sui-stroke="3"></span>
            </div>

            <!-- SECTION 1: QUICK ADD -->
            <div style="margin-bottom:24px;">
                ${window.suiAccordion('cal-logger-quick-add', 'Quick Add (Manual)', `
                    <div style="background:var(--card-bg); border-radius:18px; border:1px solid var(--border-color); padding:16px; margin-top:8px; box-shadow:var(--shadow-card);">
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <input type="text" id="cal-log-name" placeholder="Food Name" style="width:100%;">
                            <div style="position:relative; display:flex; align-items:center;">
                                <span id="cal-log-cals-prefix" style="display:none; position:absolute; left:12px; font-weight:800; color:#34C759; font-size:18px; pointer-events:none;">-</span>
                                <input type="number" id="cal-log-cals" placeholder="Calories" style="width:100%; padding-left:12px; transition:padding-left 0.2s;">
                            </div>
                            <button id="cal-submit-log-btn" class="btn-primary" style="width:100%; font-size:14px; padding:12px;">Add to Staged List</button>
                        </div>
                    </div>
                `, initialMeal === 'exercise')}
            </div>

            <!-- SECTION 2: COMBOS -->
            <div id="cal-combos-accordion-wrap" style="margin-bottom:24px;"></div>

            <!-- SECTION 3: FOOD DATABASE (FILTERABLE LIST) -->
            <div id="cal-logger-library-section" style="background:var(--card-bg); border-radius:18px; border:1px solid var(--border-color); padding:16px; min-height:300px;">
                <div style="position:sticky; top:-24px; background:var(--card-bg); z-index:10; margin:-16px -16px 12px -16px; padding:24px 16px 12px 16px; border-bottom:1px solid var(--border-color); border-top-left-radius: 18px; border-top-right-radius: 18px;">
                    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:12px; letter-spacing:0.5px;">Food Library</div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <div style="position:relative; flex:1; display:flex; align-items:center;">
                            <input type="text" id="cal-db-filter" placeholder="Search library..." style="width:100%; padding-right:40px;">
                            <button id="cal-db-filter-clear" style="position:absolute; right:8px; background:var(--btn-bg); border:none; width:24px; height:24px; border-radius:50%; color:var(--text-secondary); display:none; align-items:center; justify-content:center; cursor:pointer; padding:0;">
                                <span data-sui-icon="close" data-sui-size="12" data-sui-stroke="3"></span>
                            </button>
                        </div>
                        <button id="cal-db-quick-add" style="display:flex; background:var(--primary); color:white; border:none; width:36px; height:36px; border-radius:10px; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0;">
                            <span data-sui-icon="plus" data-sui-size="18" data-sui-stroke="3"></span>
                        </button>
                    </div>
                </div>
                <div id="cal-db-list-container" style="display:flex; flex-direction:column;"></div>
            </div>
        `,
        onSetup: (content, overlay) => {
    // Persist staged items globally
    window._calStagedLogs = window._calStagedLogs || [];
    const stagedLogs = window._calStagedLogs;

    const stagedArea = overlay.querySelector('#cal-staged-area');
    const stagedList = overlay.querySelector('#cal-staged-list');
    const stagedCount = overlay.querySelector('#cal-staged-count');
    const commitBtn = overlay.querySelector('#cal-commit-staged-btn');

    const renderStaged = () => {
    const titleEl = overlay.querySelector('.sui-studio-title');
    if (titleEl) titleEl.innerText = stagedLogs.length > 0 ? `Log Food (${stagedLogs.length})` : 'Log Food';

    if (stagedLogs.length === 0) {
        stagedArea.style.display = 'none';
        return;
    }
    stagedArea.style.display = 'block';
    stagedCount.innerText = stagedLogs.length;
    stagedList.innerHTML = stagedLogs.map((item, idx) => {
    const m = (item.multiplier !== undefined) ? item.multiplier : 1;
    const mode = item.mode || 'portion';
    const ref = item.ref_amount_g || 0;
    const adjCals = Math.round(item.calories * m);
                    
    // Value to show in input (round multiplier if in portion mode)
    const portionsPerPkg = (item.total_weight_g && item.ref_amount_g) ? (item.total_weight_g / item.ref_amount_g) : 0;
    let displayVal, unitLabel;

    if (mode === 'weight') {
        displayVal = Math.round(m * ref);
        unitLabel = 'g/ml';
    } else if (mode === 'package' && portionsPerPkg > 0) {
        displayVal = Number((m / portionsPerPkg).toFixed(2));
        unitLabel = 'pkg';
    } else {
        displayVal = Number(m.toFixed(3));
        unitLabel = 'portion';
    }
    const pLabel = item.portion_name || 'portions';
    const pkgInfo = item.pkg_calories ? ` • ${item.pkg_calories} cal pkg (${Number(portionsPerPkg.toFixed(1))} ${pLabel})` : '';
    const ratioStr = item.ref_amount_g > 0 ? `${item.calories} / ${item.ref_amount_g}g` : `${item.calories}`;

    return `
    <div class="cal-staged-item" style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom: ${idx === stagedLogs.length - 1 ? 'none' : '1px solid var(--border-color)'};">
        <div style="min-width:0; flex:1; padding-right:10px;">
            <div style="font-size:13px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${item.name}</div>
            <div class="cal-staged-adj-label" style="font-size:11px; color:var(--text-secondary);">${adjCals} cal <small>(${ratioStr} x ${Number(m.toFixed(3))})${pkgInfo}</small></div>
        </div><div style="display:flex; align-items:center; gap:6px;">
    <button class="cal-staged-undo" onclick="calResetStagedPortion(${idx})" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; color:var(--primary); display:${m !== 1 ? 'flex' : 'none'}; align-items:center; justify-content:center; cursor:pointer; padding:0; flex-shrink:0;">
        <span data-sui-icon="undo" data-sui-size="12" data-sui-stroke="3"></span>
    </button>
    <div style="position:relative; width:65px; height:34px;"><input type="number" step="any" inputmode="decimal" value="${displayVal}" 
                   onfocus="this.select()"
                   oninput="calUpdateStagedPortion(${idx}, this)" 
                   style="width:100%; padding:2px 4px 10px 4px; font-size:13px; text-align:center; border-radius:8px; height:100%; font-weight:700; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text);">
            ${ref > 0 ? `
            <div onclick="calToggleStagedMode(${idx}, this)" style="position:absolute; bottom:2px; left:0; right:0; text-align:center; font-size:7px; font-weight:900; color:var(--primary); cursor:pointer; opacity:0.8;">${unitLabel}</div>
            ` : ''}
        </div>
        <button onclick="calRemoveStaged(${idx})" style="background:none; border:none; color:var(--danger); padding:4px;"><span data-sui-icon="trash" data-sui-size="14"></span></button>
    </div>
</div>`}).join('');window.suiHydrateIcons(stagedList);
};

window.calUpdateStagedPortion = (idx, input) => {
    const val = input.value;
    const num = parseFloat(val) || 0;
    const item = stagedLogs[idx];
    const mode = item.mode || 'portion';

    const portionsPerPkg = (item.total_weight_g && item.ref_amount_g) ? (item.total_weight_g / item.ref_amount_g) : 0;
    if (mode === 'weight' && item.ref_amount_g > 0) {
        item.multiplier = num / item.ref_amount_g;
    } else if (mode === 'package' && portionsPerPkg > 0) {
        item.multiplier = num * portionsPerPkg;
    } else {
        item.multiplier = num;
    }
                
    const row = input.closest('.cal-staged-item');
                
    // Live Undo Button Detection
    const undoBtn = row ? row.querySelector('.cal-staged-undo') : null;
    if (undoBtn) {
        undoBtn.style.display = (item.multiplier === 1) ? 'none' : 'flex';
    }

    const label = row ? row.querySelector('.cal-staged-adj-label') : null;
if (label) {
    const base = item.calories;
    const adj = Math.round(base * item.multiplier);
    const displayMultiplier = Number(item.multiplier.toFixed(3));
    const portionsPerPkg = (item.total_weight_g && item.ref_amount_g) ? (item.total_weight_g / item.ref_amount_g) : 0;
    const pLabel = item.portion_name || 'portions';
    const pkgInfo = item.pkg_calories ? ` • ${item.pkg_calories} cal pkg (${Number(portionsPerPkg.toFixed(1))} ${pLabel})` : '';
    const ratioStr = item.ref_amount_g > 0 ? `${base} / ${item.ref_amount_g}g` : `${base}`;
    label.innerHTML = `${adj} cal <small>(${ratioStr} x ${displayMultiplier})${pkgInfo}</small>`;
}};window.calToggleStagedMode = (idx, labelEl) => {
    const item = stagedLogs[idx];
    const hasPkg = (item.total_weight_g && item.ref_amount_g);
    
    if (item.mode === 'portion') {
        item.mode = 'weight';
    } else if (item.mode === 'weight') {
        item.mode = hasPkg ? 'package' : 'portion';
    } else {
        item.mode = 'portion';
    }
    renderStaged();
    window.sui.haptic('light');
};

window.calResetStagedPortion = (idx) => {
    stagedLogs[idx].multiplier = 1;
    renderStaged();
    window.sui.haptic('light');
};window.calRemoveStaged = (idx) => {stagedLogs.splice(idx, 1);
    renderStaged();
    renderFoodLibrary();
    window.sui.haptic('light');
};commitBtn.onclick = async () => {
    commitBtn.disabled = true;
    commitBtn.innerText = "Saving...";
    const dateStr = calViewDate.toLocaleDateString('sv');
                
    for (const item of stagedLogs) {
        const m = item.multiplier || 1;
        const payload = {
            date: dateStr,
            meal: item.meal,
            name: item.name,
            calories: Math.round(item.calories * m),
            protein: (item.protein * m).toFixed(1),
            fat: (item.fat * m).toFixed(1),
            sat_fat: (item.sat_fat * m).toFixed(1),
            trans_fat: (item.trans_fat * m).toFixed(1),
            carbs: (item.carbs * m).toFixed(1),
            sugar: (item.sugar * m).toFixed(1),
            sodium: (item.sodium * m).toFixed(1),
            portion_name: item.portion_name,
            multiplier: m,
            mode: item.mode || 'portion',
            ref_amount_g: item.ref_amount_g || 0,
            food_id: item.food_id || null
                    };await window.sui.api('cal_log_manual', payload, { toast: false });
    }
    // Clear global persistence
    window._calStagedLogs = [];
    await fetchCalData();
    if (window.cjosBroadcastUpdate) window.cjosBroadcastUpdate();
    window.sui.closeStudio('cal-logger');
};// SHARED MEAL TYPE LOGIC
    let selectedMeal = initialMeal;
    const mealTrigger = overlay.querySelector('#cal-log-meal-trigger');
    const mealLabel = overlay.querySelector('#cal-log-meal-label');
    const nameInput = overlay.querySelector('#cal-log-name');
    nameInput.onfocus = () => nameInput.select();
    const calsInput = overlay.querySelector('#cal-log-cals');
    calsInput.onfocus = () => calsInput.select();
    const prefix = overlay.querySelector('#cal-log-cals-prefix');
    const mealOptions = [
        { label: 'Breakfast', value: 'breakfast' },
        { label: 'Lunch', value: 'lunch' },
        { label: 'Dinner', value: 'dinner' },
        { label: 'Snack', value: 'snack' },
        { label: 'Exercise', value: 'exercise' }
    ];

    const updateMealUI = (val) => {
    selectedMeal = val;
    mealLabel.innerText = mealOptions.find(o => o.value === val).label;
                
    const libSection = overlay.querySelector('#cal-logger-library-section');
    const comboSection = overlay.querySelector('#cal-combos-accordion-wrap');
    const quickAddAcc = overlay.querySelector('#cal-logger-quick-add');
    const isEx = (val === 'exercise');

    if (isEx) {
        nameInput.placeholder = "Exercise Name";
        if (prefix) prefix.style.display = 'block';
        if (calsInput) calsInput.style.paddingLeft = '24px';
        if (libSection) libSection.style.display = 'none';
        if (comboSection) comboSection.style.display = 'none';
        
        // Force open Quick Add for Exercise
        if (quickAddAcc && !quickAddAcc.classList.contains('open')) {
            window.suiToggle('cal-logger-quick-add');
        }
    } else {
        nameInput.placeholder = "Food Name";
        if (prefix) prefix.style.display = 'none';
        if (calsInput) calsInput.style.paddingLeft = '12px';
        if (libSection) libSection.style.display = 'block';
        if (comboSection) comboSection.style.display = 'block';
    }
};updateMealUI(initialMeal);

    mealTrigger.onclick = () => {
        window.openPicker("Select Meal Type", mealOptions, selectedMeal, (val) => {
            updateMealUI(val);
        });
    };

    // MANUAL ADD LOGIC
overlay.querySelector('#cal-submit-log-btn').onclick = () => {
    const n = nameInput.value ? nameInput.value.trim() : "";
    const c = calsInput.value;
    if(!n || !c) return;
    stagedLogs.push({
    meal: selectedMeal, name: n, calories: c, multiplier: 1,
    protein: 0, fat: 0, sat_fat: 0, trans_fat: 0, carbs: 0, sugar: 0, sodium: 0, portion_name: null
});nameInput.value = ''; calsInput.value = '';
    renderStaged();
    renderFoodLibrary();
    window.sui.haptic('medium');
};// FOOD LIBRARY LOGIC
let recentItems = [];
const dbFilter = overlay.querySelector('#cal-db-filter');
const dbList = overlay.querySelector('#cal-db-list-container');
            
const getItemHtml = (f, isRecent = false) => {
    const isStaged = stagedLogs.some(s => s.name === f.name);
    const isManual = isRecent && !calData.foods.some(lib => lib.name === f.name);
    const icon = isStaged ? 'check' : (isRecent ? 'undo' : 'plus');
    const bg = isStaged ? 'background:var(--selected-bg); border-radius:12px; padding-left:10px; padding-right:10px; border-color:var(--primary);' : '';
                
    const manualBadge = isManual ? `<span style="font-size:8px; font-weight:900; background:var(--btn-bg); color:var(--text-secondary); padding:2px 5px; border-radius:4px; text-transform:uppercase; border:1px solid var(--border-color); flex-shrink:0;">Manual</span>` : '';

const portionLabel = (f.multiplier && parseFloat(f.multiplier) !== 1) ? `<span style="color:var(--primary); font-weight:700;">(x${Number(parseFloat(f.multiplier).toFixed(3))})</span> ` : '';

return `
    <div class="fdb-item" style="display:flex; justify-content:space-between; align-items:center; padding:12px 0; border-bottom:1px solid var(--border-color); cursor:pointer; margin: 2px 0; transition: all 0.2s; ${bg}" 
         onclick="calQuickLogFood(${JSON.stringify(f).replace(/"/g, '&quot;')})"
         oncontextmenu="event.preventDefault(); calShowLibraryDetails(${JSON.stringify(f).replace(/"/g, '&quot;')})">
        <div style="flex:1; min-width:0;">
            <div style="font-size:14px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:${isStaged ? 'var(--primary)' : 'var(--text-primary)'};">${f.name}</div>
            <div style="font-size:12px; color:var(--text-secondary);">${isRecent ? `${f.calories} cal ${portionLabel}• ${f.portion_name || 'serving'}` : `${f.ref_calories || 0} cal / ${f.portion_name || 'serving'} • ${f.calories} cal pkg`}</div>
        </div><div style="display:flex; align-items:center; gap:8px;">
            ${manualBadge}
            <span data-sui-icon="${icon}" data-sui-size="14" data-sui-color="${isStaged ? 'var(--primary)' : 'var(--primary)'}"></span>
        </div>
    </div>
`;};const renderFoodLibrary = () => {
    const term = dbFilter.value.toLowerCase();
    let html = "";

    if (term === "") {
        // Map last_used timestamps for quick lookup
        const lastUsedMap = {};
        (recentItems || []).forEach(r => lastUsedMap[r.name] = parseInt(r.last_used || 0));

        // 1. Recently Updated or Added
        // Item is here IF: (updated_at > last_used) OR (never used)
        const modified = [...calData.foods]
            .filter(f => {
                const up = parseInt(f.updated_at || 0);
                const used = lastUsedMap[f.name] || 0;
                return up > 0 && up > used;
            })
            .sort((a, b) => b.updated_at - a.updated_at)
            .slice(0, 5);
        const modifiedNames = modified.map(m => m.name);

        if (modified.length > 0) {
            html += `<div style="font-size:9px; font-weight:900; color:var(--ai-accent); text-transform:uppercase; letter-spacing:1px; margin:10px 0 5px 0; opacity:0.8;">Recently Updated or Added</div>`;
            html += modified.map(f => getItemHtml(f)).join('');
        }

        // 2. Recently Used
        // Item is here IF: (last_used >= updated_at) OR (Manual entry not in library)
        const used = (recentItems || []).filter(r => {
            const libItem = calData.foods.find(f => f.name === r.name);
            const up = libItem ? parseInt(libItem.updated_at || 0) : 0;
            const usedTs = parseInt(r.last_used || 0);
            return usedTs >= up;
        });
        const usedNames = used.map(u => u.name);

        if (used.length > 0) {
            html += `<div style="font-size:9px; font-weight:900; color:var(--primary); text-transform:uppercase; letter-spacing:1px; margin:25px 0 5px 0; opacity:0.8;">Recently Used</div>`;
            html += used.map(f => getItemHtml(f, true)).join('');
        }

        // 3. All Library Items - Exclude items already shown above
        const others = calData.foods.filter(f => !modifiedNames.includes(f.name) && !usedNames.includes(f.name));
        if (others.length > 0) {
            html += `<div style="font-size:9px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin:25px 0 5px 0; opacity:0.6;">All Library Items</div>`;
            html += others.map(f => getItemHtml(f)).join('');
        }
    } else {// Show Search Results
        const visible = calData.foods.filter(f => f.name.toLowerCase().includes(term));
        if (visible.length === 0) {
            html = window.suiEmptyState('🔍', 'No matches in library');
        } else {
            html = visible.map(f => getItemHtml(f)).join('');
        }
    }

    dbList.innerHTML = html;
    window.suiHydrateIcons(dbList);
};

const dbClearBtn = overlay.querySelector('#cal-db-filter-clear');
const quickAddBtn = overlay.querySelector('#cal-db-quick-add');

quickAddBtn.onclick = () => {
    calOpenFoodEditorStudio(null, dbFilter.value);
};

dbFilter.oninput = () => {
    dbClearBtn.style.display = dbFilter.value.length > 0 ? 'flex' : 'none';
    renderFoodLibrary();
};
dbClearBtn.onclick = () => {
    dbFilter.value = '';
    dbClearBtn.style.display = 'none';
    renderFoodLibrary();
    dbFilter.focus();
};

window.calShowLibraryDetails = (f) => {
    // Try to find the full object in the library (esp if f is a partial 'Recent' object)
    const fullFood = calData.foods.find(lib => lib.name === f.name) || f;
    if (typeof calShowFoodDetails === 'function') {
        calShowFoodDetails(fullFood);
        window.sui.haptic('medium');
    }
};

window.calQuickLogFood = (f) => {
    const existingIdx = stagedLogs.findIndex(s => s.name === f.name);
    if (existingIdx > -1) {
        // Toggle Off: Remove from stage
        stagedLogs.splice(existingIdx, 1);
        window.sui.haptic('light');
    } else {
        // Toggle On: Add to stage
        const isRecent = f.hasOwnProperty('multiplier');
        let baseCals, initialMultiplier, ratio;

        if (isRecent) {
            // Recent Item: Use snapshot from log
            initialMultiplier = parseFloat(f.multiplier) || 1;
            baseCals = f.calories / initialMultiplier;
            ratio = 1 / initialMultiplier;
        } else {
            // Library Item: Base is 1 portion
            baseCals = f.ref_calories || 0;
            initialMultiplier = 1;
            // Scale macros from package total to portion reference
            ratio = (f.calories > 0) ? (f.ref_calories / f.calories) : 0;
        }
                    
        stagedLogs.push({
            meal: selectedMeal, 
            food_id: f.id,
            name: f.name, 
            calories: baseCals, 
            multiplier: initialMultiplier,
            ref_amount_g: f.ref_amount_g || 0,
            mode: 'portion',
            protein: (f.protein || 0) * ratio, 
            fat: (f.fat || 0) * ratio, 
            sat_fat: (f.sat_fat || 0) * ratio, 
            trans_fat: (f.trans_fat || 0) * ratio,
            carbs: (f.carbs || 0) * ratio, 
            sugar: (f.sugar || 0) * ratio, 
            sodium: (f.sodium || 0) * ratio, 
            portion_name: f.portion_name,
            pkg_calories: isRecent ? null : f.calories,
            total_weight_g: isRecent ? null : f.total_weight_g
        });
        window.sui.haptic('medium');
    }
    renderStaged();
    renderFoodLibrary();
};// RECENT & COMBOS LOGIC
const renderRecentAndCombos = () => {
    renderCombos(); 
    recentItems = calData.recent || [];
    renderFoodLibrary(); 
};

window._calRefreshLoggerUI = () => {
    renderRecentAndCombos();
};

const renderCombos = () => {
    const wrap = overlay.querySelector('#cal-combos-accordion-wrap');
    if (!wrap) return;

    // Capture existing state before refresh
    const openComboIds = Array.from(wrap.querySelectorAll('.cal-nested-combo.open')).map(el => el.dataset.id);
    const mainAcc = wrap.querySelector('#cal-logger-combos');
    const isMainOpen = mainAcc ? mainAcc.classList.contains('open') : false;

    let combosHtml = `
        <style>
            .cal-nested-combo { background:var(--bg-color); border-radius:12px; margin-bottom:8px; border:1px solid var(--border-color); overflow:hidden; transition: all 0.2s; }
            .cal-nested-combo.open { border-color: var(--primary); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
            .cal-nested-combo-header { display:flex; justify-content:space-between; align-items:center; padding:12px; cursor:pointer; }
            .cal-nested-combo-body { max-height:0; overflow:hidden; transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1); background: rgba(0,0,0,0.02); }
            .cal-nested-combo.open .cal-nested-combo-body { max-height: 500px; overflow-y: auto; }
            .cal-nested-combo.open [data-sui-icon="chevron"] { transform: rotate(180deg); }
        </style>
    `;

    if (calData.combos && calData.combos.length > 0) {
        combosHtml += calData.combos.map(c => {
            const itemsPreview = c.items.slice(0, 5).map(i => {
                const m = parseFloat(i.multiplier) || 1;
                const adjCals = Math.round(i.calories * m);
                const label = i.mode === 'weight' ? (Math.round(m * i.ref_amount_g) + 'g') : ('x' + Number(m.toFixed(2)));
                return `<div style="font-size:11px; color:var(--text-secondary); padding:2px 0;">• ${i.name} <b style="color:var(--primary); font-size:10px;">${label}</b> <span style="opacity:0.6;">(${adjCals} cal)</span></div>`;
            }).join('');
            const isOpen = openComboIds.includes(c.id.toString());
            return `
            <div class="cal-nested-combo ${isOpen ? 'open' : ''}" data-id="${c.id}">
                <div class="cal-nested-combo-header" onclick="this.parentElement.classList.toggle('open'); window.sui.haptic('light');"><div style="display:flex; flex-direction:column;">
                        <span style="font-weight:700; font-size:13px;">${c.name}</span>
                        <span style="font-size:10px; color:var(--text-secondary); opacity:0.8;">${c.items.length} items</span>
                    </div>
                    <span data-sui-icon="chevron" data-sui-size="12" data-sui-stroke="3" style="transition:transform 0.3s; color:var(--text-secondary);"></span>
                </div>
                <div class="cal-nested-combo-body">
                    <div style="padding:0 12px 12px 12px;">
                        <div style="padding:8px 0; border-top:1px solid rgba(0,0,0,0.05);">${itemsPreview}${c.items.length > 5 ? '<div style="font-size:10px; opacity:0.5; font-style:italic;">...and more</div>' : ''}</div>
                        <div style="display:flex; gap:8px; margin-top:8px;">
                            <button onclick="calLogCombo(${c.id})" class="btn-primary" style="flex:2; padding:8px; font-size:11px; border-radius:8px; box-shadow:none;">Add to Staged</button>
                            <button onclick="calOpenCombosStudio()" style="flex:1; background:var(--btn-bg); color:var(--text-primary); border:none; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer;">Edit Combo</button>
                        </div>
                    </div>
                </div>
            </div>
        `}).join('');
    } else {
        combosHtml += `<div style="padding:16px; text-align:center; color:var(--text-secondary); font-size:12px; font-style:italic; opacity:0.7;">No combos created.<br>Add them via Options > Food Combos.</div>`;
    }

    wrap.innerHTML = window.suiAccordion('cal-logger-combos', 'Combos', `
        <div style="background:var(--card-bg); border-radius:18px; border:1px solid var(--border-color); padding:16px; margin-top:8px; box-shadow:var(--shadow-card);">
            ${combosHtml}
        </div>
    `, isMainOpen);
    window.suiHydrateIcons(wrap);
};window.calLogCombo = async (cid) => {
    const combo = calData.combos.find(c => c.id === cid);
    if (!combo) return;
    combo.items.forEach(i => {
        // Skip if already in staged list
        if (stagedLogs.some(s => s.name === i.name)) return;

        const m = parseFloat(i.multiplier) || 1;
        const ratio = (i.calories > 0) ? (i.ref_calories / i.calories) : 0;
        
        stagedLogs.push({
            meal: selectedMeal, 
            name: i.name, 
            calories: i.ref_calories, // Use portion base
            multiplier: m,
            mode: i.mode || 'portion',
            ref_amount_g: i.ref_amount_g || 0,
            protein: (i.protein || 0) * ratio, 
            fat: (i.fat || 0) * ratio,
            sat_fat: (i.sat_fat || 0) * ratio, 
            trans_fat: (i.trans_fat || 0) * ratio,
            carbs: (i.carbs || 0) * ratio, 
            sugar: (i.sugar || 0) * ratio,
            sodium: (i.sodium || 0) * ratio, 
            portion_name: i.portion_name,
            pkg_calories: i.calories,
            total_weight_g: i.total_weight_g
        });
    });
    renderStaged();
    renderFoodLibrary();
    window.sui.haptic('notify');
};renderRecentAndCombos();
renderFoodLibrary();
// Refresh staged view immediately to show persisted items
renderStaged();
// Global hydration to catch accordion arrows and shared components
window.suiHydrateIcons(overlay);
}});}
async function calSubmitLog() {
    const n = document.getElementById('cal-log-name').value;
    const c = document.getElementById('cal-log-cals').value;
    const m = document.getElementById('cal-log-meal').value;
    if(!n || !c) return alert("Missing info");
    
    const fd = new FormData();
    fd.append('plugin_action', 'cal_log_manual'); 
    fd.append('date', calViewDate.toLocaleDateString('sv')); 
    fd.append('meal', m); fd.append('name', n); fd.append('calories', c);
    
    await fetch('index.php', {method:'POST', body:fd});
    calCloseLogger();
    fetchCalData();
    document.getElementById('cal-log-name').value = ''; document.getElementById('cal-log-cals').value = '';
    const list = document.getElementById('cal-log-suggestions');
    if(list) list.style.display = 'none';
}

// --- CUSTOM AUTOCOMPLETE ---
window.calSearchLogFood = function(term) {
    const list = document.getElementById('cal-log-suggestions');
    if (!list) return;
    
    if (term.length < 1) {
        list.style.display = 'none';
        return;
    }

    const matches = calData.foods.filter(f => f.name.toLowerCase().includes(term.toLowerCase()));
    
    if (matches.length === 0) {
        list.style.display = 'none';
        return;
    }

    list.innerHTML = '';
    matches.forEach(f => {
        const item = document.createElement('div');
        item.style.cssText = "padding:12px; border-bottom:1px solid var(--border-color); cursor:pointer; display:flex; justify-content:space-between; color:var(--text-primary);";
        item.innerHTML = `<span style="font-weight:600;">${f.name}</span><span style="color:var(--text-secondary);">${f.calories} cal</span>`;
        item.onmousedown = (e) => {
            e.preventDefault(); // Prevent blur
            document.getElementById('cal-log-name').value = f.name;
            document.getElementById('cal-log-cals').value = f.calories;
            list.style.display = 'none';
        };
        list.appendChild(item);
    });
    list.style.display = 'block';
};
document.getElementById('cal-log-name')?.addEventListener('blur', () => {
    setTimeout(() => {
        const list = document.getElementById('cal-log-suggestions');
        if(list) list.style.display = 'none';
    }, 200);
});

// --- STANDARD UTILS ---
function updateDatalist() {
    const dl = document.getElementById('cal-food-datalist');
    if(!dl) return;
    dl.innerHTML = '';
    calData.foods.forEach(f => {
        const opt = document.createElement('option');
        opt.value = f.name; opt.setAttribute('data-cals', f.calories);
        dl.appendChild(opt);
    });
}
function calUpdateBmrFieldState() {
    const useFitbit = document.getElementById('cal-set-use-fitbit').checked;
    const secManual = document.getElementById('cal-sec-manual');
    const secFitbit = document.getElementById('cal-sec-fitbit');
    const inputManual = document.getElementById('cal-set-manual-bmr');

    if (secManual && secFitbit && inputManual) {
        secManual.style.opacity = useFitbit ? '0.5' : '1';
        secFitbit.style.opacity = useFitbit ? '1' : '0.5';
        inputManual.disabled = useFitbit;
    }
}

async function calSaveSettings() {
    const mb = document.getElementById('cal-set-manual-bmr').value;
    const g = document.getElementById('cal-set-goal').value;
    const pg = document.getElementById('cal-set-protein-goal').value;
    const uf = document.getElementById('cal-set-use-fitbit').checked;
    const hb = document.getElementById('cal-set-hide-btn').checked;
    const fd = document.getElementById('cal-set-first-day').value;
    const rl = document.getElementById('cal-set-recent-limit').value;
    await window.sui.api('cal_save_settings', { manual_bmr: mb, goal: g, protein_goal: pg, use_fitbit_bmr: uf, hide_main_log_btn: hb, first_day_of_week: fd, recent_limit: rl });
    fetchCalData();
    window.sui.closeStudio('cal-settings');
}

function updateCalPage() {
    const isToday = calViewDate.toDateString() === new Date().toDateString();
    document.getElementById('cal-page-date').innerText = isToday ? 'Today' : calViewDate.toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric' });
    
    const jumpPill = document.getElementById('cal-jump-today-pill');
    if (jumpPill) jumpPill.style.display = isToday ? 'none' : 'block';

    const cont = document.getElementById('cal-meals-container'); cont.innerHTML = '';
    let dayConsumed = 0, dayExercise = 0, dayProtein = 0;
    const config = calData.config;

    ['breakfast', 'lunch', 'dinner', 'snack', 'exercise'].forEach(type => {
        const items = calData.logs.filter(l => l.meal_type === type);
        const secDiv = document.createElement('div'); secDiv.style.cssText = "background:var(--card-bg); border-radius:16px; padding:16px; margin-bottom:12px; border:1px solid var(--border-color);";
        let mealTotal = 0;

        let headerActions = `<button onclick="calOpenLogger('${type}')" style="background:var(--btn-bg); border:none; width:22px; height:22px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;"><span data-sui-icon="plus" data-sui-size="12" data-sui-stroke="4"></span></button>`;
        
        if (type !== 'exercise' && items.length > 0) {
            headerActions = `<button onclick="calSaveMealAsCombo('${type}')" style="background:var(--btn-bg); border:none; width:22px; height:22px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;"><span data-sui-icon="star" data-sui-size="12" data-sui-stroke="3"></span></button>` + headerActions;
        }

        if (type === 'exercise') {
            headerActions = `
                <span onclick="event.stopPropagation(); if(typeof fbSync === 'function') fbSync();" style="font-size:10px; font-weight:800; color:var(--primary); background:color-mix(in srgb, var(--primary), transparent 90%); padding:4px 10px; border-radius:10px; cursor:pointer; text-transform:none; letter-spacing:0; transition: opacity 0.2s; margin-right:4px;">Sync Fitbit</span>
                ${headerActions}
            `;
        }
        
        let html = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <span style="font-size:13px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">${type}</span>
                <div style="display:flex; align-items:center; gap:4px;">${headerActions}</div>
            </div>
        `;
        if(items.length === 0) html += `<div style="font-size:13px; color:var(--text-secondary); font-style:italic; opacity:0.5;">No entries</div>`;
        else {
            items.forEach(i => {
                const c = parseInt(i.calories);
                
                // Special Case: Fitbit Sync total should show "Extra Effort" (ACT)
                if (i.food_name === 'Fitbit Sync') {
                    const extraEffort = Math.max(0, parseInt(i.ex_total_burn || 0) - parseInt(i.ex_bmr || 0));
                    mealTotal += extraEffort;
                } else {
                    mealTotal += Math.abs(c);
                }

                dayProtein += parseFloat(i.protein || 0);
                if(type === 'exercise') dayExercise += Math.abs(c); else dayConsumed += c;
                
                const displayVal = (type === 'exercise') ? "-" + Math.abs(c) : c;
                let rightSide = `<span style="font-weight:600; color:${type==='exercise'?'#34C759':'var(--text-primary)'}">${displayVal}</span>`;
                
                if (i.food_name === 'Fitbit Sync') {
                    const extraEffort = Math.max(0, parseInt(i.ex_total_burn || 0) - parseInt(i.ex_bmr || 0));
                    rightSide = `<div style="text-align:right; font-size:10px; font-weight:700; color:#34C759; line-height:1.2;"><div>BMR: -${parseInt(i.ex_bmr||0)}</div><div>ACT: -${extraEffort}</div></div>`;
                }

                html += `<div onclick="calShowLogDetails(${JSON.stringify(i).replace(/"/g, '&quot;')})" style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border-color); color:var(--text-primary); cursor:pointer;"><div><div style="font-size:15px; font-weight:500;">${i.food_name}</div></div><div style="display:flex; gap:8px; align-items:center;">${rightSide}<button onclick="event.stopPropagation(); calDeleteEntry(${i.id})" style="background:rgba(255,59,48,0.1); color:var(--danger); border:none; width:20px; height:20px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px;">&times;</button></div></div>`;
            });
            const totalLabel = (type === 'exercise') ? 'Total Burned' : 'Meal Total';
            html += `<div style="text-align:right; margin-top:12px; font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">${totalLabel}: <span style="color:var(--text-primary); font-size:13px; margin-left:4px;">${mealTotal} cal</span></div>`;
        }secDiv.innerHTML = html; cont.appendChild(secDiv);
        if (window.srWatch) window.srWatch(secDiv);
    });

    // --- SMART BURN CALCULATION ---
    let totalBurn = 0;
    const fbSync = calData.logs.find(l => l.food_name === 'Fitbit Sync');
    const manualBmr = parseInt(config.manual_bmr || config.bmr || 1800);

    if (!config.use_fitbit_bmr) {
        // Mode A: Manual BMR + All Exercise
        totalBurn = manualBmr + dayExercise;
    } else if (fbSync) {
        // Mode B: Fitbit Total Burn + Manual Exercises (not from Fitbit)
        const otherExercise = calData.logs
            .filter(l => l.meal_type === 'exercise' && l.food_name !== 'Fitbit Sync')
            .reduce((acc, l) => acc + Math.abs(parseInt(l.calories)), 0);
        totalBurn = parseInt(fbSync.ex_total_burn) + otherExercise;
    } else {
        // Mode C: Fitbit Sync Enabled but not yet performed for today
        // Use the cached Fitbit BMR from config if available, otherwise manual
        const fbBmr = parseInt(config.fitbit_bmr) || manualBmr;
        totalBurn = fbBmr + dayExercise;
    }

    const net = dayConsumed - totalBurn;
    const mainBtn = document.getElementById('cal-main-log-btn');
    if (mainBtn) mainBtn.style.display = config.hide_main_log_btn ? 'none' : 'flex';

    // Register Static Components for Scroll Reveal (Mirrors Meals Logic)
    if (window.srWatch) {
        const dateBar = document.getElementById('cal-date-nav-bar');
        const statsCard = document.getElementById('cal-stats-card');
        const actionRow = mainBtn ? mainBtn.parentElement : null;
        if (dateBar) window.srWatch(dateBar);
        if (statsCard) window.srWatch(statsCard);
        if (actionRow) window.srWatch(actionRow);
    }

    document.getElementById('cal-page-in').innerText = dayConsumed; document.getElementById('cal-page-out').innerText = totalBurn;
    
    const protGoal = parseInt(config.protein_goal || 150);
    const protPill = document.getElementById('cal-protein-pill');
    if (protPill) {
        protPill.innerHTML = `
            <span style="color:var(--text-secondary); font-size:9px; font-weight:800; text-transform:uppercase; display:block; margin-bottom:2px;">Protein</span>
            <span style="font-size:17px; font-weight:800; color:var(--primary); line-height:1;">${Math.round(dayProtein)}</span>
            <span style="font-size:9px; font-weight:700; color:var(--text-secondary); opacity:0.7;">/ ${protGoal}g</span>
        `;
    }
    
    document.getElementById('cal-page-net').innerText = (net > 0 ? "+" : "") + net; document.getElementById('cal-page-net').style.color = net > 0 ? "var(--danger)" : "#34C759";
    document.getElementById('cal-bar-food').style.width = Math.min(100, (dayConsumed/totalBurn)*100) + "%";

    // --- PIGGY BANK CALCULATION ---
    const piggyPill = document.getElementById('cal-piggy-pill');
    if (config.piggy_bank && config.piggy_bank.enabled) {
        piggyPill.style.display = 'flex';
        window.sui.api('cal_get_piggy_stats', { start_date: config.piggy_bank.start_date }, { toast: false })
            .then(data => {
                if (data.status === 'success') {
                    let totalSaved = 0;
                    const manualBmr = parseInt(config.manual_bmr || config.bmr || 1800);
                    data.stats.forEach(s => {
                        let dayBurn = 0;
                        if (!config.use_fitbit_bmr) {
                            dayBurn = manualBmr + parseInt(s.burned);
                        } else if (s.fb_total_burn) {
                            dayBurn = parseInt(s.fb_total_burn) + parseInt(s.burned);
                        } else {
                            dayBurn = (parseInt(s.fb_bmr) || manualBmr) + parseInt(s.burned);
                        }
                        totalSaved += (dayBurn - parseInt(s.eaten));
                    });
                    const goal = Math.round(config.piggy_bank.kg_goal * 7700);
                    piggyPill.innerHTML = `
                        <span style="color:var(--text-secondary); font-size:9px; font-weight:800; text-transform:uppercase; display:block; margin-bottom:2px;">Piggy Bank</span>
                        <span style="font-size:17px; font-weight:800; color:var(--primary); line-height:1;">${Math.round(totalSaved).toLocaleString()}</span>
                        <span style="font-size:9px; font-weight:700; color:var(--text-secondary); opacity:0.7;">/ ${goal.toLocaleString()}</span>
                    `;
                }
            });
    } else {
        piggyPill.style.display = 'none';
    }
    window.suiHydrateIcons(cont);

    // --- DETAIL VIEW AUTO-REFRESH ---
    if (window._calViewingFitbitDetail) {
        const newFbLog = calData.logs.find(l => l.food_name === 'Fitbit Sync');
        if (newFbLog) {
            calShowLogDetails(newFbLog);
        } else {
            // Use Mock Log for unsynced days to maintain navigation/layout consistency
            const mockLog = {
                food_name: 'Fitbit Sync',
                calories: 0,
                ex_total_burn: 0,
                ex_bmr: calData.config.fitbit_bmr || calData.config.manual_bmr || 0,
                ex_steps: 0,
                log_timestamp: calViewDate.getTime() / 1000,
                not_synced: true
            };
            calShowLogDetails(mockLog);
        }
    }
}
window.calShowLogDetails = function(log) {
    const dateLabel = calViewDate.toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric' });
    const isToday = new Date().toDateString() === calViewDate.toDateString();
    
    // --- FITBIT SYNC HANDLER (STUDIO MIGRATION) ---
    if (log.food_name === 'Fitbit Sync') {
        const activeCals = Math.abs(log.calories);
        const totalBurn = parseInt(log.ex_total_burn || 0);
        const bmr24h = parseInt(log.ex_bmr || 0);
        const extraEffort = Math.max(0, totalBurn - bmr24h);
        const bmrOverlap = Math.max(0, activeCals - extraEffort);
        const statusText = log.not_synced ? ' <span style="color:var(--danger); opacity:0.6; font-size:9px; font-weight:800; padding:1px 4px; border:1px solid var(--danger); border-radius:4px; vertical-align:middle; margin-left:4px;">NOT SYNCED</span>' : '';

        const entryOffset = (window._calSlideDir || 0) * 40;
        window.sui.openStudio({
            id: 'cal-fb-detail',
            title: `Fitbit: ${dateLabel}`,
            onClose: () => { window._calViewingFitbitDetail = false; },
            content: `
                <div id="cal-fb-slide-wrapper" style="display:flex; flex-direction:column; gap:20px; opacity: 0; transform: translateX(${entryOffset}px); transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1); opacity: ${log.not_synced ? '0.6' : '1'};">
                    <div style="background:var(--card-bg); border-radius:18px; padding:20px; border:1px solid var(--border-color); box-shadow:var(--shadow-card);">
                        <div style="display:flex; justify-content:space-between; font-weight:800; font-size:18px; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:8px;">
                            <span>Total Burned</span>
                            <b>${totalBurn} <small style="font-size:10px; opacity:0.6;">cal</small></b>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:4px 0; font-size:14px;"><span>Daily BMR (Baseline)</span><b>${bmr24h} cal</b></div>
                        <div style="display:flex; justify-content:space-between; padding:4px 0; color:var(--primary); font-weight:700; font-size:14px;"><span>Extra Effort (Active)</span><b>+ ${extraEffort} cal</b></div>
                    </div>

                    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:-10px;">Calculation Details</div>
                    <div style="background:var(--bg-color); border-radius:14px; padding:16px; border:1px solid var(--border-color); font-size:13px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Reported Active Calories</span><span>${activeCals} cal</span></div>
                        <div style="display:flex; justify-content:space-between; font-style:italic; color:var(--text-secondary);"><span>↳ BMR Overlap</span><span>- ${bmrOverlap} cal</span></div>
                        <div style="margin-top:12px; font-size:10px; line-height:1.4; color:var(--text-secondary); border-top:1px dashed var(--border-color); padding-top:12px;">
                            Fitbit includes base BMR inside its "Active" metric. We subtract that overlap to show true extra calories burned.
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; background:var(--card-bg); padding:16px; border-radius:14px; border:1px solid var(--border-color);">
                        <span style="font-size:14px; font-weight:700;">Total Steps Today</span>
                        <b style="font-size:16px; color:var(--primary);">${(log.ex_steps || 0).toLocaleString()}</b>
                    </div>

                    ${log.not_synced ? `<button onclick="fbSync()" class="btn-primary">Sync Fitbit Data</button>` : `<button onclick="fbSync()" class="btn-primary" style="background:var(--btn-bg); color:var(--primary); box-shadow:none; border:1px solid var(--border-color);">Re-sync Fitbit Data</button>`}
                </div>
            `,
            onSetup: (content, overlay) => {
                window._calViewingFitbitDetail = true;
                const wrapper = overlay.querySelector('#cal-fb-slide-wrapper');
                requestAnimationFrame(() => {
                    if (wrapper) {
                        wrapper.style.transform = 'translateX(0)';
                        wrapper.style.opacity = '1';
                    }
                });
                window._calSlideDir = 0;

                const title = overlay.querySelector('.sui-studio-title');
                if (title && !isToday) {
                    title.innerHTML += ` <button onclick="calJumpToToday()" style="background:var(--btn-bg); border:none; padding:4px 10px; border-radius:12px; font-size:10px; font-weight:800; color:var(--primary); margin-left:10px; text-transform:uppercase; cursor:pointer;">Go to Today</button>`;
                }

                // Swipe to Navigate Dates
                let startX = 0;
                overlay.ontouchstart = (e) => { startX = e.touches[0].clientX; };
                overlay.ontouchend = (e) => {
                    const diff = e.changedTouches[0].clientX - startX;
                    if (Math.abs(diff) > 70) {
                        calShiftDate(diff > 0 ? -1 : 1);
                        if (window.sui && window.sui.haptic) window.sui.haptic('light');
                    }
                };
            }
        });
        return;
    }

    // --- FOOD LOG HANDLER (STUDIO WITH PORTION EDITOR) ---
    const multiplier = parseFloat(log.multiplier) || 1;
    const base = {
        calories: log.calories / multiplier,
        protein: log.protein / multiplier,
        fat: log.fat / multiplier,
        sat_fat: log.sat_fat / multiplier,
        trans_fat: log.trans_fat / multiplier,
        carbs: log.carbs / multiplier,
        sugar: log.sugar / multiplier,
        sodium: log.sodium / multiplier
    };

    window.sui.openStudio({
        id: 'cal-log-detail',
        title: log.food_name,
        content: `
            <div style="display:flex; flex-direction:column; gap:20px;">
                <!-- Portion Editor (Staged Area Style) -->
                <div id="cal-detail-editor" class="cal-staged-item" style="background:var(--card-bg); border-radius:18px; padding:16px; border:1px solid var(--border-color); box-shadow:var(--shadow-card); display:flex; justify-content:space-between; align-items:center;">
                    <div style="flex:1; padding-right:10px;">
                        <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:4px;">Portion Editor</div>
                        <div id="cal-detail-adj-label" style="font-size:15px; font-weight:800; color:var(--primary);">${log.calories} cal <small style="color:var(--text-secondary); font-weight:400;">(${Math.round(base.calories)} x ${Number(multiplier.toFixed(2))})</small></div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <button id="cal-detail-undo" style="background:var(--btn-bg); border:none; width:32px; height:32px; border-radius:50%; color:var(--primary); display:${multiplier !== 1 ? 'flex' : 'none'}; align-items:center; justify-content:center; cursor:pointer;">
                            <span data-sui-icon="undo" data-sui-size="14" data-sui-stroke="3"></span>
                        </button>
                        <div style="position:relative; width:80px; height:40px;">
                            <input type="number" id="cal-detail-input" step="any" inputmode="decimal" value="${log.mode === 'weight' ? Math.round(multiplier * log.ref_amount_g) : Number(multiplier.toFixed(2))}" 
                                   onfocus="this.select()"
                                   style="width:100%; padding:4px 4px 12px 4px; font-size:16px; text-align:center; border-radius:10px; height:100%; font-weight:800; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text);">
                            ${log.ref_amount_g > 0 ? `<div id="cal-detail-unit-toggle" style="position:absolute; bottom:3px; left:0; right:0; text-align:center; font-size:8px; font-weight:900; color:var(--primary); cursor:pointer; text-transform:lowercase; opacity:0.8;">${log.mode || 'portion'}</div>` : ''}
                        </div>
                    </div>
                </div>

                <!-- Macros List -->
                <div style="background:var(--bg-color); border-radius:16px; padding:16px; border:1px solid var(--border-color);">
                    <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:12px; letter-spacing:0.5px; border-bottom:1px solid var(--border-color); padding-bottom:6px;">Nutritional Snapshot</div>
                    <div id="cal-detail-macros" style="display:flex; flex-direction:column; gap:10px; font-size:14px;">
                        <!-- Injected via updateUI -->
                    </div>
                </div>

                <div id="cal-detail-sync-row" style="display:none; flex-direction:column; gap:8px; margin-bottom:10px;">
                    <button id="cal-detail-open-db" class="text-btn" style="width:100%; background:var(--ai-accent-bg); color:var(--ai-accent); border-radius:12px; padding:10px; font-size:12px; font-weight:700; border:1px solid rgba(88, 86, 214, 0.2); display:flex; align-items:center; justify-content:center; gap:8px;">
                        <span data-sui-icon="edit" data-sui-size="14"></span> Open in Database
                    </button>
                    <button id="cal-detail-sync-db" class="text-btn" style="width:100%; background:var(--success-bg); color:var(--success-text); border-radius:12px; padding:10px; font-size:12px; font-weight:700; border:1px solid rgba(30, 70, 32, 0.1); display:none; align-items:center; justify-content:center; gap:8px;">
                        <span data-sui-icon="undo" data-sui-size="14"></span> Sync Macros from Library
                    </button>
                </div>

                <div style="display:flex; gap:10px;">
                    <button onclick="calDeleteEntry(${log.id}).then(() => window.sui.closeStudio('cal-log-detail'))" class="btn-primary" style="flex:1; background:var(--btn-bg); color:var(--danger); box-shadow:none; border:1px solid var(--border-color);">Delete Log</button>
                    <button id="cal-detail-save" class="btn-primary" style="flex:2;">Save Changes</button>
                </div>
                
                <div style="text-align:center; font-size:11px; color:var(--text-secondary); opacity:0.6;">
                    Recorded at ${new Date(log.log_timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})} on ${dateLabel}
                </div>
            </div>
        `,
        onSetup: (content, overlay) => {
            let currentM = multiplier;
            let currentMode = log.mode || 'portion';
            const input = overlay.querySelector('#cal-detail-input');
            const unitToggle = overlay.querySelector('#cal-detail-unit-toggle');
            const undo = overlay.querySelector('#cal-detail-undo');
            const saveBtn = overlay.querySelector('#cal-detail-save');
            const macroBox = overlay.querySelector('#cal-detail-macros');
            const adjLabel = overlay.querySelector('#cal-detail-adj-label');

            // --- LIBRARY LINKING & LIVE SYNC ---
            const refreshLinking = () => {
                const linkedFood = calData.foods.find(f => f.id == log.food_id) || calData.foods.find(f => f.name === log.food_name);
                if (!linkedFood) return;

                const syncRow = overlay.querySelector('#cal-detail-sync-row');
                const openDbBtn = overlay.querySelector('#cal-detail-open-db');
                const syncDbBtn = overlay.querySelector('#cal-detail-sync-db');
                syncRow.style.display = 'flex';
                
                openDbBtn.onclick = () => {
                    if (typeof calOpenFoodEditorStudio === 'function') {
                        calOpenFoodEditorStudio(linkedFood);
                    }
                };

                // Check if data differs (ignore small rounding)
                const ratio = (linkedFood.calories > 0) ? (linkedFood.ref_calories / linkedFood.calories) : 0;
                const libPortionCals = Math.round(linkedFood.ref_calories);
                const logPortionCals = Math.round(base.calories);
                
                if (libPortionCals !== logPortionCals) {
                    syncDbBtn.style.display = 'flex';
                    syncDbBtn.onclick = () => {
                        window.openConfirm("Sync from Library", "Update this log entry with the latest macros from the database?", () => {
                            base.calories = linkedFood.ref_calories;
                            base.protein = linkedFood.protein * ratio;
                            base.fat = linkedFood.fat * ratio;
                            base.sat_fat = linkedFood.sat_fat * ratio;
                            base.trans_fat = linkedFood.trans_fat * ratio;
                            base.carbs = linkedFood.carbs * ratio;
                            base.sugar = linkedFood.sugar * ratio;
                            base.sodium = linkedFood.sodium * ratio;
                            updateUI();
                            syncDbBtn.style.display = 'none';
                            window.sui.haptic('success');
                        });
                    };
                } else {
                    syncDbBtn.style.display = 'none';
                }
            };

            window._calRefreshLogDetail = refreshLinking;
            refreshLinking();

            const updateUI = () => {
                const fmt = (v) => {
                    const num = parseFloat(v) || 0;
                    return Number.isInteger(num) ? num : num.toFixed(1);
                };
                const m = currentM;
                const cals = Math.round(base.calories * m);
                adjLabel.innerHTML = `${cals} cal <small style="color:var(--text-secondary); font-weight:400;">(${Math.round(base.calories)} x ${Number(m.toFixed(2))})</small>`;
                undo.style.display = (m === 1) ? 'none' : 'flex';

                macroBox.innerHTML = `
                    <div style="display:flex; justify-content:space-between;"><span>Protein</span><b>${fmt(base.protein * m)}g</b></div>
                    <div style="display:flex; justify-content:space-between;"><span>Fat</span><b>${fmt(base.fat * m)}g</b></div>
                    <div style="display:flex; justify-content:space-between; padding-left:15px; opacity:0.7; font-size:12px;"><span>- Saturated Fat</span><b>${fmt(base.sat_fat * m)}g</b></div>
                    <div style="display:flex; justify-content:space-between; padding-left:15px; opacity:0.7; font-size:12px;"><span>- Trans Fat</span><b>${fmt(base.trans_fat * m)}g</b></div>
                    <div style="display:flex; justify-content:space-between;"><span>Carbs</span><b>${fmt(base.carbs * m)}g</b></div>
                    <div style="display:flex; justify-content:space-between; padding-left:15px; opacity:0.7; font-size:12px;"><span>- Sugar</span><b>${fmt(base.sugar * m)}g</b></div>
                    <div style="display:flex; justify-content:space-between;"><span>Sodium</span><b>${fmt(base.sodium * m)}mg</b></div>
                `;
            };

            input.oninput = () => {
                const val = parseFloat(input.value) || 0;
                if (currentMode === 'weight' && log.ref_amount_g > 0) currentM = val / log.ref_amount_g;
                else currentM = val;
                updateUI();
            };

            if (unitToggle) {
                unitToggle.onclick = () => {
                    currentMode = (currentMode === 'weight') ? 'portion' : 'weight';
                    unitToggle.innerText = currentMode;
                    input.value = (currentMode === 'weight') ? Math.round(currentM * log.ref_amount_g) : Number(currentM.toFixed(2));
                    window.sui.haptic('light');
                };
            }

            undo.onclick = () => {
                currentM = 1;
                currentMode = 'portion';
                if (unitToggle) unitToggle.innerText = 'portion';
                input.value = 1;
                updateUI();
                window.sui.haptic('light');
            };

            saveBtn.onclick = async () => {
                saveBtn.disabled = true;
                saveBtn.innerText = "Saving...";
                const m = currentM;
                await window.sui.api('cal_update_log', {
                    id: log.id,
                    multiplier: m,
                    mode: currentMode,
                    calories: Math.round(base.calories * m),
                    protein: base.protein * m,
                    fat: base.fat * m,
                    sat_fat: base.sat_fat * m,
                    trans_fat: base.trans_fat * m,
                    carbs: base.carbs * m,
                    sugar: base.sugar * m,
                    sodium: base.sodium * m
                });
                if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
                if (window.cjosBroadcastUpdate) window.cjosBroadcastUpdate();
                window.sui.closeStudio('cal-log-detail');
            };

            updateUI();
            window.suiHydrateIcons(overlay);
        }
    });
};

// Ensure flag is cleared when picker closes
document.addEventListener('click', (e) => {
    if (e.target.id === 'shared-picker-overlay' || e.target.closest('[onclick*="closeSharedPicker"]')) {
        window._calViewingFitbitDetail = false;
    }
});

window.calJumpToToday = function() {
    const today = new Date();
    if (today.toDateString() === calViewDate.toDateString()) return;
    
    window._calIsAnimating = true;
    window._calSlideDir = (today > calViewDate) ? 1 : -1;
    
    const list = document.getElementById('shared-picker-list');
    if (list) {
        list.style.transition = "all 0.2s ease-in";
        list.style.transform = `translateX(${window._calSlideDir > 0 ? '-50px' : '50px'})`;
        list.style.opacity = "0";
    }
    
    setTimeout(() => {
        calViewDate = today;
        fetchCalData();
    }, 200);
};

window.calOpenWidgetsStudio = function() {
    window.sui.openStudio({
        id: 'cal-widgets',
        title: 'Nutrition Insights',
        content: `
            <style>
                .cal-day-highlight { 
                    border: 2px dotted var(--primary) !important; 
                    box-sizing: border-box;
                }
            </style>
            <div id="cal-widget-calendar-container" style="background:var(--card-bg); border-radius:18px; border:1px solid var(--border-color); padding:16px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <button id="cal-widget-prev-mo" class="icon-btn" style="background:var(--btn-bg);">&lt;</button>
                    <div id="cal-widget-mo-title" style="font-weight:800; font-size:16px;">Month Year</div>
                    <button id="cal-widget-next-mo" class="icon-btn" style="background:var(--btn-bg);">&gt;</button>
                </div>
                <div id="cal-widget-header" style="display:grid; grid-template-columns: repeat(7, 1fr); gap:4px; text-align:center; margin-bottom:8px;"></div>
                <div id="cal-widget-grid" style="display:grid; grid-template-columns: repeat(7, 1fr); gap:4px;"></div>
            </div>

            <!-- Trend Chart Widget -->
            <div id="cal-trend-container" style="background:var(--card-bg); border-radius:18px; border:1px solid var(--border-color); padding:20px; margin-bottom:20px; position:relative; touch-action:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Monthly Balance Trend</div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div id="cal-trend-val-hint" style="font-size:12px; font-weight:800; color:var(--text-primary); opacity:0; transition: opacity 0.2s;">...</div>
                        <button onclick="calToggleTrendSettings()" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                            <span data-sui-icon="sliders" data-sui-size="14" data-sui-stroke="2.5"></span>
                        </button>
                    </div>
                </div>

                <!-- Floating Settings Overlay -->
                <div id="cal-trend-settings" style="display:none; position:absolute; top:52px; right:20px; z-index:100; background:var(--card-bg); border:1px solid var(--border-color); border-radius:14px; box-shadow:var(--shadow-floating); width:140px; padding:12px; animation: suiPop 0.2s ease-out;">
                    <div style="font-size:9px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; margin-bottom:10px; letter-spacing:0.5px;">Visibility</div>
                    <label style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; cursor:pointer;">
                        <span style="font-size:12px; font-weight:600;">C In</span>
                        <input type="checkbox" id="cal-vis-c-in" checked onchange="calUpdateTrendVisibility()" style="width:16px; height:16px; accent-color:var(--primary);">
                    </label>
                    <label style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; cursor:pointer;">
                        <span style="font-size:12px; font-weight:600;">C Out</span>
                        <input type="checkbox" id="cal-vis-c-out" checked onchange="calUpdateTrendVisibility()" style="width:16px; height:16px; accent-color:#34C759;">
                    </label>
                    <label style="display:flex; align-items:center; justify-content:space-between; cursor:pointer;">
                        <span style="font-size:12px; font-weight:600;">Protein</span>
                        <input type="checkbox" id="cal-vis-prot" checked onchange="calUpdateTrendVisibility()" style="width:16px; height:16px; accent-color:#007AFF;">
                    </label>
                </div>
                <div id="cal-trend-svg-wrap" style="height:120px; width:100%; position:relative; cursor:crosshair;">
                    <svg id="cal-trend-svg" style="width:100%; height:100%; overflow:visible; pointer-events:none;"></svg>
                    <!-- Interaction Layer -->
                    <div id="cal-trend-interaction" style="position:absolute; inset:0; z-index:10;"></div>
                    <!-- Tooltip Line -->
                    <div id="cal-trend-line" style="position:absolute; top:0; bottom:0; width:1px; background:var(--primary); opacity:0; pointer-events:none; z-index:5;"></div>
                    <!-- Tooltip Box -->
                    <div id="cal-trend-tooltip" style="position:absolute; top:-10px; transform:translate(-50%, -100%); background:var(--text-primary); color:var(--card-bg); padding:4px 8px; border-radius:6px; font-size:10px; font-weight:800; white-space:nowrap; opacity:0; pointer-events:none; z-index:20; box-shadow:var(--shadow-floating);"></div>
                </div>
                <div id="cal-trend-labels" style="display:flex; justify-content:space-between; margin-top:12px; font-size:9px; font-weight:800; color:var(--text-secondary); opacity:0.5;">
                    <!-- Labels injected via JS -->
                </div>
            </div>
        `,
        onSetup: (content, overlay) => {
            let cur = new Date(calViewDate);
            const render = async () => {
                const year = cur.getFullYear();
                const month = cur.getMonth();
                const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
                const firstDaySetting = parseInt(calData.config.first_day_of_week || 0);
                
                overlay.querySelector('#cal-widget-mo-title').innerText = cur.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                
                const showCIn = calData.config.trend_show_c_in !== false;
                const showCOut = calData.config.trend_show_c_out !== false;
                const showProt = calData.config.trend_show_prot !== false;
                overlay.querySelector('#cal-vis-c-in').checked = showCIn;
                overlay.querySelector('#cal-vis-c-out').checked = showCOut;
                overlay.querySelector('#cal-vis-prot').checked = showProt;

                // Render Headers
                const days = firstDaySetting === 1 ? ['M','T','W','T','F','S','S'] : ['S','M','T','W','T','F','S'];
                overlay.querySelector('#cal-widget-header').innerHTML = days.map(d => `<div style="font-size:10px; font-weight:900; color:var(--text-secondary);">${d}</div>`).join('');

                const res = await window.sui.api('cal_get_month_stats', { month: monthStr }, { toast: false });
                const statsMap = {};
                if(res.status === 'success') res.stats.forEach(s => statsMap[s.date_ref] = s);

                const grid = overlay.querySelector('#cal-widget-grid');
                grid.innerHTML = '';
                
                const firstDay = new Date(year, month, 1).getDay();
                const daysInMo = new Date(year, month + 1, 0).getDate();

                // Calculate Offset
                let offset = firstDay;
                if (firstDaySetting === 1) { // Monday
                    offset = (firstDay === 0) ? 6 : firstDay - 1;
                }

                for(let i=0; i<offset; i++) grid.appendChild(document.createElement('div'));

                // --- TREND CHART LOGIC ---
                const trendSvg = overlay.querySelector('#cal-trend-svg');
                trendSvg.innerHTML = '';
                const dailyStats = [];
                let maxAbs = 500; 
                const proteinGoal = parseInt(calData.config.protein_goal || 150);
                let maxProtDev = 20; // Default vertical scale for protein (deviation from goal)

                const manualBmr = parseInt(calData.config.manual_bmr || calData.config.bmr || 1800);
                const useFitbit = calData.config.use_fitbit_bmr;

                for(let d=1; d<=daysInMo; d++) {
                    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                    const s = statsMap[dateStr];
                    if (s) {
                        let dayBurn = 0;
                        if (!useFitbit) {
                            dayBurn = manualBmr + parseInt(s.burned);
                        } else if (s.fb_total_burn) {
                            dayBurn = parseInt(s.fb_total_burn) + parseInt(s.burned);
                        } else {
                            dayBurn = (parseInt(s.fb_bmr) || parseInt(calData.config.fitbit_bmr) || manualBmr) + parseInt(s.burned);
                        }
                        const net = parseInt(s.eaten) - dayBurn;
                        const prot = Math.round(parseFloat(s.protein || 0));
                        dailyStats.push({ d, net, prot });
                        if (Math.abs(net) > maxAbs) maxAbs = Math.abs(net);
                        const pDev = Math.abs(prot - proteinGoal);
                        if (pDev > maxProtDev) maxProtDev = pDev;
                    }
                }

                if (dailyStats.length > 0) {
                    const width = trendSvg.clientWidth || 300;
                    const height = 120;
                    const padding = 10;
                    const chartH = height - (padding * 2);
                    const zeroY = height / 2;
                    
                    // Draw Baseline
                    trendSvg.innerHTML += `<line x1="0" y1="${zeroY}" x2="${width}" y2="${zeroY}" stroke="var(--border-color)" stroke-width="1" stroke-dasharray="4 4" />`;
                    trendSvg.innerHTML += `<text x="${width}" y="${zeroY - 5}" text-anchor="end" fill="var(--text-secondary)" style="font-size:8px; font-weight:800; opacity:0.5; text-transform:uppercase; letter-spacing:0.5px;">Net Zero / ${proteinGoal}g Goal</text>`;

                    let cInPath = "";
                    let cOutPath = "";
                    let protPath = "";

                    const cInGroup = document.createElementNS("http://www.w3.org/2000/svg", "g");
                    cInGroup.setAttribute("class", "cal-trend-group-c-in");
                    const cOutGroup = document.createElementNS("http://www.w3.org/2000/svg", "g");
                    cOutGroup.setAttribute("class", "cal-trend-group-c-out");
                    const protGroup = document.createElementNS("http://www.w3.org/2000/svg", "g");
                    protGroup.setAttribute("class", "cal-trend-group-prot");

                    dailyStats.forEach((pt, i) => {
                        const x = ((pt.d - 1) / (daysInMo - 1)) * width;
                        const yNet = zeroY - (pt.net / maxAbs) * (chartH / 2);
                        const yCOut = zeroY - ((-pt.net) / maxAbs) * (chartH / 2);
                        const yProt = zeroY - ((pt.prot - proteinGoal) / maxProtDev) * (chartH / 2);
                        
                        if (i === 0) {
                            cInPath = `M ${x} ${yNet}`;
                            cOutPath = `M ${x} ${yCOut}`;
                            protPath = `M ${x} ${yProt}`;
                        } else {
                            cInPath += ` L ${x} ${yNet}`;
                            cOutPath += ` L ${x} ${yCOut}`;
                            protPath += ` L ${x} ${yProt}`;
                        }

                        // Draw C In Dot
                        const cInColor = pt.net > 0 ? 'var(--danger)' : '#34C759';
                        cInGroup.innerHTML += `<circle cx="${x}" cy="${yNet}" r="3" fill="${cInColor}" stroke="var(--card-bg)" stroke-width="1" />`;

                        // Draw C Out Dot (Symmetric Color Logic)
                        const cOutColor = pt.net < 0 ? '#34C759' : 'var(--danger)';
                        cOutGroup.innerHTML += `<circle cx="${x}" cy="${yCOut}" r="3" fill="${cOutColor}" stroke="var(--card-bg)" stroke-width="1" />`;
                        
                        // Draw Protein Dot (Blue)
                        protGroup.innerHTML += `<circle cx="${x}" cy="${yProt}" r="2.5" fill="#007AFF" stroke="var(--card-bg)" stroke-width="1" />`;
                    });

                    // Draw C In Line
                    const cInLine = document.createElementNS("http://www.w3.org/2000/svg", "path");
                    cInLine.setAttribute("d", cInPath);
                    cInLine.setAttribute("fill", "none");
                    cInLine.setAttribute("stroke", "var(--primary)");
                    cInLine.setAttribute("stroke-width", "2");
                    cInLine.setAttribute("stroke-linejoin", "round");
                    cInLine.setAttribute("style", "opacity:0.2;");
                    cInGroup.prepend(cInLine);

                    // Draw C Out Line (Symmetric)
                    const cOutLine = document.createElementNS("http://www.w3.org/2000/svg", "path");
                    cOutLine.setAttribute("d", cOutPath);
                    cOutLine.setAttribute("fill", "none");
                    cOutLine.setAttribute("stroke", "#34C759");
                    cOutLine.setAttribute("stroke-width", "2");
                    cOutLine.setAttribute("stroke-linejoin", "round");
                    cOutLine.setAttribute("style", "opacity:0.2;");
                    cOutGroup.prepend(cOutLine);

                    // Draw Protein Line (Blue)
                    const protLine = document.createElementNS("http://www.w3.org/2000/svg", "path");
                    protLine.setAttribute("d", protPath);
                    protLine.setAttribute("fill", "none");
                    protLine.setAttribute("stroke", "#007AFF");
                    protLine.setAttribute("stroke-width", "2");
                    protLine.setAttribute("stroke-linejoin", "round");
                    protLine.setAttribute("style", "opacity:0.3;");
                    protGroup.prepend(protLine);

                    cInGroup.style.display = showCIn ? 'block' : 'none';
                    cOutGroup.style.display = showCOut ? 'block' : 'none';
                    protGroup.style.display = showProt ? 'block' : 'none';
                    trendSvg.appendChild(cInGroup);
                    trendSvg.appendChild(cOutGroup);
                    trendSvg.appendChild(protGroup);

                    // --- INTERACTION HANDLERS ---
                    const inter = overlay.querySelector('#cal-trend-interaction');
                    const tLine = overlay.querySelector('#cal-trend-line');
                    const tTip = overlay.querySelector('#cal-trend-tooltip');
                    const tHint = overlay.querySelector('#cal-trend-val-hint');

                    const handleMove = (e) => {
                        const rect = inter.getBoundingClientRect();
                        const x = (e.clientX || e.touches[0].clientX) - rect.left;
                        const dayIdx = Math.round((x / rect.width) * (daysInMo - 1));
                        const d = dayIdx + 1;
                        const pt = dailyStats.find(p => p.d === d);

                        if (pt) {
                            const screenX = (dayIdx / (daysInMo - 1)) * rect.width;
                            const yNet = zeroY - (pt.net / maxAbs) * (chartH / 2);
                            const yCOut = zeroY - ((-pt.net) / maxAbs) * (chartH / 2);
                            const yProt = zeroY - ((pt.prot - proteinGoal) / maxProtDev) * (chartH / 2);
                            
                            tLine.style.opacity = "1";
                            tLine.style.left = screenX + "px";
                            
                            tTip.style.opacity = "1";
                            tTip.style.left = screenX + "px";
                            tTip.style.top = (showCIn ? yNet : (showCOut ? yCOut : yProt)) - 15 + "px";
                            
                            let tipHtml = "";
                            if (showCIn) tipHtml += `<div style="color:${pt.net > 0 ? '#FF3B30' : '#34C759'};">In: ${pt.net > 0 ? '+' : ''}${pt.net} cal</div>`;
                            if (showCOut) tipHtml += `<div style="color:${pt.net < 0 ? '#34C759' : '#FF3B30'};">Out: ${-pt.net > 0 ? '+' : ''}${-pt.net} cal</div>`;
                            if (showProt) tipHtml += `<div style="color:#007AFF;">${pt.prot}g Protein</div>`;
                            tTip.innerHTML = tipHtml || "No Data";

                            tHint.style.opacity = "1";
                            tHint.innerText = `${cur.toLocaleDateString('en-US', {month:'short'})} ${d}`;
                            tHint.style.color = 'var(--text-primary)';

                            // Highlight Calendar Cell
                            grid.querySelectorAll('[data-day]').forEach(c => c.classList.remove('cal-day-highlight'));
                            const targetCell = grid.querySelector(`[data-day="${d}"]`);
                            if (targetCell) targetCell.classList.add('cal-day-highlight');
                        }
                    };

                    const handleEnd = () => {
                        tLine.style.opacity = "0";
                        tTip.style.opacity = "0";
                        tHint.style.opacity = "0";
                        grid.querySelectorAll('[data-day]').forEach(c => c.classList.remove('cal-day-highlight'));
                    };

                    inter.onpointermove = handleMove;
                    inter.onpointerleave = handleEnd;
                    inter.ontouchstart = (e) => { handleMove(e); e.preventDefault(); };
                    inter.ontouchmove = handleMove;
                    inter.ontouchend = handleEnd;

                    // --- VISIBILITY & SETTINGS ---
                    window.calToggleTrendSettings = () => {
                        const menu = overlay.querySelector('#cal-trend-settings');
                        menu.style.display = (menu.style.display === 'none') ? 'block' : 'none';
                        window.sui.haptic('light');
                    };

                    window.calUpdateTrendVisibility = async () => {
                        const showCIn = overlay.querySelector('#cal-vis-c-in').checked;
                        const showCOut = overlay.querySelector('#cal-vis-c-out').checked;
                        const showProt = overlay.querySelector('#cal-vis-prot').checked;
                        overlay.querySelector('.cal-trend-group-c-in').style.display = showCIn ? 'block' : 'none';
                        overlay.querySelector('.cal-trend-group-c-out').style.display = showCOut ? 'block' : 'none';
                        overlay.querySelector('.cal-trend-group-prot').style.display = showProt ? 'block' : 'none';
                        
                        // Persist to server
                        await window.sui.api('cal_save_trend_prefs', { show_c_in: showCIn, show_c_out: showCOut, show_prot: showProt }, { toast: false });
                        calData.config.trend_show_c_in = showCIn;
                        calData.config.trend_show_c_out = showCOut;
                        calData.config.trend_show_prot = showProt;
                        
                        window.sui.haptic('medium');
                    };

                    // Close menu if tapping outside
                    overlay.addEventListener('click', (e) => {
                        const menu = overlay.querySelector('#cal-trend-settings');
                        if (menu && menu.style.display === 'block' && !e.target.closest('#cal-trend-settings') && !e.target.closest('button[onclick*="calToggleTrendSettings"]')) {
                            menu.style.display = 'none';
                        }
                    });

                    // --- RENDER LABELS ---
                    const labelWrap = overlay.querySelector('#cal-trend-labels');
                    labelWrap.innerHTML = '';
                    [1, 8, 15, 22, daysInMo].forEach(day => {
                        const span = document.createElement('span');
                        span.innerText = day;
                        span.style.width = "20px";
                        span.style.textAlign = "center";
                        labelWrap.appendChild(span);
                    });

                } else {
                    trendSvg.innerHTML = `<text x="50%" y="50%" text-anchor="middle" fill="var(--text-secondary)" style="font-size:12px; opacity:0.5;">No data for this period</text>`;
                }

                for(let d=1; d<=daysInMo; d++) {
                    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                    const dayStats = statsMap[dateStr];
                    const cell = document.createElement('div');
                    
                    let balanceHtml = '';
                    if (dayStats) {
                        const eaten = parseInt(dayStats.eaten);
                        let dayBurn = 0;
                        if (!useFitbit) {
                            dayBurn = manualBmr + parseInt(dayStats.burned);
                        } else if (dayStats.fb_total_burn) {
                            dayBurn = parseInt(dayStats.fb_total_burn) + parseInt(dayStats.burned);
                        } else {
                            dayBurn = (parseInt(dayStats.fb_bmr) || parseInt(calData.config.fitbit_bmr) || manualBmr) + parseInt(dayStats.burned);
                        }
                        const net = eaten - dayBurn;
                        const color = net > 0 ? 'var(--danger)' : '#34C759';
                        const protein = Math.round(parseFloat(dayStats.protein || 0));
                        balanceHtml = `
                            <div style="font-size:8px; font-weight:800; color:${color};">${net > 0 ? '+' : ''}${net}</div>
                            <div style="font-size:7px; font-weight:700; color:var(--primary); opacity:0.8;">${protein}p</div>
                        `;
                    }

                    const isToday = new Date().toLocaleDateString('sv') === dateStr;
                    const isSelected = calViewDate.toLocaleDateString('sv') === dateStr;

                    // Selection uses a 15% tint of the primary theme color
                    const cellBg = isSelected ? 'color-mix(in srgb, var(--primary), transparent 85%)' : 'var(--btn-bg)';
                    const cellBorder = isSelected ? '2px solid var(--primary)' : (isToday ? '1px solid var(--primary)' : 'none');
                    const dateColor = isSelected ? 'var(--primary)' : 'var(--text-primary)';

                    cell.style.cssText = `aspect-ratio:1; display:flex; flex-direction:column; align-items:center; justify-content:center; border-radius:10px; cursor:pointer; background:${cellBg}; border:${cellBorder}; transition: transform 0.1s; box-sizing: border-box;`;
                    cell.dataset.day = d;
                    cell.innerHTML = `<div style="font-size:12px; font-weight:700; color:${dateColor};">${d}</div>${balanceHtml}`;
                    
                    cell.onclick = () => {
                        calViewDate = new Date(year, month, d);
                        fetchCalData();
                        window.sui.closeStudio('cal-widgets');
                    };
                    grid.appendChild(cell);
                }
            };

            overlay.querySelector('#cal-widget-prev-mo').onclick = () => { cur.setMonth(cur.getMonth() - 1); render(); };
            overlay.querySelector('#cal-widget-next-mo').onclick = () => { cur.setMonth(cur.getMonth() + 1); render(); };
            render();
            window.suiHydrateIcons(overlay);
        }
    });
};

window.calSaveMealAsCombo = function(type) {
    const items = calData.logs.filter(l => l.meal_type === type);
    
    // Normalize lookups to prevent matching failures due to case or whitespace
    const foods = calData.foods || [];
    const validItems = items.map(l => {
        const logName = (l.food_name || "").trim().toLowerCase();
        const f = foods.find(food => (food.name || "").trim().toLowerCase() === logName);
        if (f) {
            return {
                food_id: f.id,
                multiplier: parseFloat(l.multiplier) || 1,
                mode: l.mode || 'portion'
            };
        }
        return null;
    }).filter(i => i !== null);

    if (validItems.length === 0) {
        return window.openConfirm("Save Combo", "Only items from the Food Library can be saved into a Combo. No library items found in this section.", null, false, "OK", null);
    }

    // Generate suggested name based on meal type and existing combos
    const baseName = type.charAt(0).toUpperCase() + type.slice(1);
    let suggestion = baseName;
    const existingNames = (calData.combos || []).map(c => c.name);
    
    if (existingNames.includes(baseName)) {
        let counter = 1;
        while (existingNames.includes(`${baseName} ${counter}`)) {
            counter++;
        }
        suggestion = `${baseName} ${counter}`;
    }

    window.openInput("Save as Combo", "Enter combo name", suggestion, async (name) => {
        if (!name) return;
        try {
            const res = await window.sui.api('cal_manage_combos', { mode: 'create', name: name });
            if (res && res.id) {
                // Use the new bulk_add API for atomicity and to include multipliers/modes
                await window.sui.api('cal_manage_combos', { 
                    mode: 'bulk_add_items', 
                    combo_id: res.id, 
                    items: validItems 
                }, { toast: false });
                
                await fetchCalData();
                window.openConfirm("Combo Saved", `"${name}" has been added to your Combos.`, null, false, "Great", null);
            }
        } catch(e) { console.error(e); }
    });
};

window.calShiftDate = function(d) { 
    const next = new Date(calViewDate);
    next.setDate(next.getDate() + d);
    const today = new Date();
    today.setHours(23, 59, 59, 999);
    if (next > today) {
        if (window.sui && window.sui.haptic) window.sui.haptic('error');
        return; 
    }

    if (window.sui && window.sui.haptic) window.sui.haptic('light');
    
    window._calSlideDir = d; // 1 = Next, -1 = Prev
    if (window._calViewingFitbitDetail) {
        const wrapper = document.getElementById('cal-fb-slide-wrapper');
        if (wrapper) {
            wrapper.style.transform = `translateX(${d > 0 ? '-40px' : '40px'})`;
            wrapper.style.opacity = '0';
        }
    }

    setTimeout(() => {
        calViewDate = next;
        fetchCalData();
    }, window._calViewingFitbitDetail ? 150 : 0);
}
window.calDeleteEntry = async function(id) {
    window.openConfirm("Delete Entry", "Remove this item from your log?", async () => {
        await window.sui.api('cal_delete_entry', { id: id });
        if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
        if (window.cjosBroadcastUpdate) window.cjosBroadcastUpdate();
    }, true);
}
window.calComboDelete = async function(id) {
    window.openConfirm("Delete Combo", "Are you sure you want to delete this food combo?", async () => {
        await window.sui.api('cal_manage_combos', { mode: 'delete', id: id });
        await fetchCalData();
        calOpenCombosStudio();
    }, true);
}
window.calComboRemoveItem = async function(linkId) {
    await window.sui.api('cal_manage_combos', { mode: 'remove_item', id: linkId });
    if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
    calOpenCombosStudio();
}

if (window.registerRefreshHook) {
    window.registerRefreshHook(fetchCalData);
}
JS;
?>