<?php
// ==============================================================================
// PLUGIN: Food Database
// Sub-plugin for Calorie Tracker. Manages the food registry and Calculator.
// UPDATED: Fixed Empty List View & Add Panel Layout.
// ==============================================================================

// 1. DATABASE SETUP (Standalone Food Database - Lazy Initialization)
$fdb_dir = CJOS_PATH_DATA . '/food-database';
$fdb_path = $fdb_dir . '/food.db';
$fdb = null;

function fdb_get_db() {
    global $fdb, $fdb_dir, $fdb_path;
    if ($fdb !== null) return $fdb;
    
    if (!is_dir($fdb_dir)) mkdir($fdb_dir, 0777, true);
    
    try {
        $fdb = new PDO("sqlite:$fdb_path");
        $fdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $fdb->exec("PRAGMA journal_mode=WAL;");

        $fdb->exec("CREATE TABLE IF NOT EXISTS cal_foods (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE,
            calories INTEGER,
            protein REAL DEFAULT 0,
            fat REAL DEFAULT 0,
            sat_fat REAL DEFAULT 0,
            trans_fat REAL DEFAULT 0,
            carbs REAL DEFAULT 0,
            sugar REAL DEFAULT 0,
            sodium REAL DEFAULT 0,
            total_weight_g INTEGER DEFAULT 0,
            ref_amount_g INTEGER DEFAULT 100,
            ref_calories INTEGER DEFAULT 0,
            portion_name TEXT
        )");

        // Ensure all new columns exist (for updates)
        $cols = $fdb->query("PRAGMA table_info(cal_foods)")->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_column($cols, 'name');
        $new_cols = ['sat_fat', 'trans_fat', 'sugar', 'sodium', 'portion_name', 'updated_at'];
        foreach($new_cols as $nc) {
            if(!in_array($nc, $existing)) {
                $type = ($nc === 'updated_at') ? 'INTEGER DEFAULT 0' : 'REAL DEFAULT 0';
                $fdb->exec("ALTER TABLE cal_foods ADD COLUMN $nc $type");
            }
        }
    } catch (Exception $e) {
        error_log("FoodDB Init Error: " . $e->getMessage());
        $fdb = false;
    }
    return $fdb;
}

// 2. BACKEND HANDLERS
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'cal_manage_food') {
    error_reporting(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $db_conn = fdb_get_db();
    if (!$db_conn) {
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }

    $mode = $_POST['mode'];
    
    if ($mode === 'add') {
        $now = time();
        $check = $db_conn->prepare("SELECT id FROM cal_foods WHERE name = ?");
        $check->execute([$_POST['name']]);
        if ($check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Food name already exists']);
            exit;
        }

        $pn = trim($_POST['portion_name'] ?? '');
        if ($pn === '' || strtolower($pn) === 'null') $pn = null;

        $stmt = $db_conn->prepare("INSERT INTO cal_foods (name, calories, protein, fat, sat_fat, trans_fat, carbs, sugar, sodium, total_weight_g, ref_amount_g, ref_calories, portion_name, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['name'], 
            $_POST['calories'],
            $_POST['protein'] ?? 0,
            $_POST['fat'] ?? 0,
            $_POST['sat_fat'] ?? 0,
            $_POST['trans_fat'] ?? 0,
            $_POST['carbs'] ?? 0,
            $_POST['sugar'] ?? 0,
            $_POST['sodium'] ?? 0,
            $_POST['total_weight'] ?? $_POST['total_weight_g'] ?? 0,
            $_POST['ref_amount'] ?? $_POST['ref_amount_g'] ?? 100,
            $_POST['ref_calories'] ?? 0,
            $pn,
            $now
        ]);
        echo json_encode(['status' => 'success']);
    }

    if ($mode === 'update') {
        $pn = trim($_POST['portion_name'] ?? '');
        if ($pn === '' || strtolower($pn) === 'null') $pn = null;
        $now = time();

        $stmt = $db_conn->prepare("UPDATE cal_foods SET name=?, calories=?, protein=?, fat=?, sat_fat=?, trans_fat=?, carbs=?, sugar=?, sodium=?, total_weight_g=?, ref_amount_g=?, ref_calories=?, portion_name=?, updated_at=? WHERE id=?");
        $stmt->execute([
            $_POST['name'], $_POST['calories'], $_POST['protein'], $_POST['fat'], $_POST['sat_fat'], $_POST['trans_fat'], 
            $_POST['carbs'], $_POST['sugar'], $_POST['sodium'], 
            $_POST['total_weight'] ?? $_POST['total_weight_g'] ?? 0, 
            $_POST['ref_amount'] ?? $_POST['ref_amount_g'] ?? 100, 
            $_POST['ref_calories'] ?? 0, $pn, $now, $_POST['id']
        ]);
        echo json_encode(['status' => 'success']);
    }

    if ($mode === 'import_batch') {
        $items = json_decode($_POST['payload'], true);
        if (!$items) { echo json_encode(['status' => 'error', 'message' => 'Invalid data']); exit; }
        
        $count = 0;
        $stmt = $db_conn->prepare("INSERT OR IGNORE INTO cal_foods (name, calories, protein, fat, sat_fat, trans_fat, carbs, sugar, sodium, total_weight_g, ref_amount_g, ref_calories, portion_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($items as $f) {
            $pn = trim($f['portion_name'] ?? '');
            if ($pn === '' || strtolower($pn) === 'null') $pn = null;

            $stmt->execute([
                $f['name'], $f['calories'], $f['protein'], $f['fat'], $f['sat_fat'], $f['trans_fat'], $f['carbs'], $f['sugar'], $f['sodium'],
                $f['total_weight'], $f['ref_amount'], $f['ref_calories'], $pn
            ]);
            if ($stmt->rowCount() > 0) $count++;
        }
        echo json_encode(['status' => 'success', 'imported' => $count]);
    }
    
    if ($mode === 'delete') {
        $db_conn->prepare("DELETE FROM cal_foods WHERE id = ?")->execute([$_POST['id']]);
        echo json_encode(['status' => 'success']);
    }
    
    if ($mode === 'get_all') {
        $foods = $db_conn->query("SELECT * FROM cal_foods ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'foods' => $foods]);
    }
    
    if ($mode === 'get_recent') {
        $foods = $db_conn->query("SELECT * FROM cal_foods ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'foods' => $foods]);
    }
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'cal_batch_portions') {
    error_reporting(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $db_conn = fdb_get_db();
    if (!$db_conn) {
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }

    $mode = $_POST['mode'];
    if ($mode === 'get_unique') {
        $stmt = $db_conn->query("SELECT 
            portion_name, 
            HEX(portion_name) as hex_id,
            COUNT(*) as count 
            FROM cal_foods 
            GROUP BY portion_name 
            ORDER BY count DESC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($mode === 'update') {
        $hex = $_POST['hex_id'] ?? '';
        $new = trim($_POST['new_name'] ?? '');
        if ($new === '' || strtolower($new) === 'null') $new = null;

        if ($hex === 'NULL' || $hex === '') {
            $stmt = $db_conn->prepare("UPDATE cal_foods SET portion_name = ? WHERE portion_name IS NULL");
            $stmt->execute([$new]);
        } else {
            $stmt = $db_conn->prepare("UPDATE cal_foods SET portion_name = ? WHERE HEX(portion_name) = ?");
            $stmt->execute([$new, $hex]);
        }
        echo json_encode(['status' => 'success', 'updated' => $stmt->rowCount()]);
    }
    exit;
}

// 3. SETTINGS UI (Placeholder to register plugin)
$plugin_settings_map['FoodDatabase'] = '
    <div class="setting-item">
        <div class="setting-desc">
            Manages the underlying food database for the Calorie Tracker.
        </div>
    </div>
';

// 4. OVERLAYS (Migrated to SharedUI)
if(!isset($plugin_overlays)) $plugin_overlays = [];

// 5. JS LOGIC
$plugin_js .= <<<'JS'
// --- FOOD DATABASE JS ---

let fdbItems = [];
window.fdbFetchAll = fdbFetchAll;

// window.fdbFetchAll is lazily called during the view's hydration sequence to prevent background resource drain.

window.calShowFoodDetails = function(f) {
    const fmt = (v) => {
        const num = parseFloat(v) || 0;
        if (num === 0) return '0';
        return Number.isInteger(num) ? num : num.toFixed(1);
    };

    // Calculation Ratios
    const totalW = parseFloat(f.total_weight_g) || 0;
    const refW = parseFloat(f.ref_amount_g) || 0;
    
    const pkgTo100 = totalW > 0 ? (100 / totalW) : 0;
    const pkgToServing = (totalW > 0 && refW > 0) ? (refW / totalW) : 0;

    const getRow = (label, val, unit = 'g', isSub = false, hasBorder = false) => {
        const pkgVal = parseFloat(val) || 0;
        const srvVal = pkgVal * pkgToServing;
        const hVal = pkgVal * pkgTo100;
        const borderStyle = hasBorder ? 'border-top: 1px solid var(--border-color); margin-top: 4px; padding-top: 10px;' : '';

        return `
            <div style="display:grid; grid-template-columns: 2.2fr 1fr 1fr 1fr; gap:4px; align-items:center; padding:6px 0; border-bottom:1px solid rgba(0,0,0,0.03); ${isSub ? 'opacity:0.7; font-size:11px; padding-left:10px;' : 'font-size:12px;'} ${borderStyle}">
                <div style="font-weight:${isSub ? '500' : '700'}; color:var(--text-secondary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${label}</div>
                <div style="text-align:right; font-weight:600; color:var(--text-primary);">${fmt(srvVal)}${isSub ? '' : `<small style="font-size:8px; font-weight:400; opacity:0.6; margin-left:1px;">${unit}</small>`}</div>
                <div style="text-align:right; font-weight:500; color:var(--text-secondary);">${fmt(hVal)}</div>
                <div style="text-align:right; font-weight:500; color:var(--text-secondary);">${fmt(pkgVal)}</div>
            </div>
        `;
    };

    const options = [
        { label: "Nutrition Facts", type: "header" },
        { label: `
            <div style="display:flex; flex-direction:column; color:var(--text-primary);">
                <!-- Header Row -->
                <div style="display:grid; grid-template-columns: 2.2fr 1fr 1fr 1fr; gap:4px; padding-bottom:8px; border-bottom:2px solid var(--border-color); margin-bottom:4px;">
                    <div style="font-size:9px; font-weight:900; color:var(--text-secondary); text-transform:uppercase;">Metric</div>
                    <div style="font-size:9px; font-weight:900; color:var(--primary); text-transform:uppercase; text-align:right;">Srv</div>
                    <div style="font-size:9px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; text-align:right;">100g</div>
                    <div style="font-size:9px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; text-align:right;">Pkg</div>
                </div>
                ${getRow('Calories', f.calories, '', false)}
                ${getRow('Protein', f.protein, 'g', false, true)}
                ${getRow('Fat', f.fat, 'g', false)}
                ${getRow('Saturated', f.sat_fat, 'g', true)}
                ${getRow('Trans', f.trans_fat, 'g', true)}
                ${getRow('Carbs', f.carbs, 'g', false, true)}
                ${getRow('Sugar', f.sugar, 'g', true)}
                ${getRow('Sodium', f.sodium, 'mg', false, true)}
            </div>
        `, type: "info" }
    ];

    if (f.total_weight_g > 0 || f.ref_amount_g > 0) {
        options.push({ label: "Package Metrics", type: "header" });
        let metrics = [];
        
        // 1. Total Package
        if (f.total_weight_g > 0) metrics.push(`Total Package: <b>${f.total_weight_g}g/mL</b>`);
        
        // 2. Servings per Package
        let portions = 0;
        if (f.total_weight_g > 0 && f.ref_amount_g > 0) {
            portions = f.total_weight_g / f.ref_amount_g;
            const pName = f.portion_name ? ' ' + f.portion_name : '';
            metrics.push(`Servings per Package: <b>${fmt(portions)}${pName}</b>`);
        }

        // 3. Per Serving (Hide if redundant)
        if (f.ref_amount_g > 0 && portions !== 1) {
            metrics.push(`Per Serving: <b>${f.ref_amount_g}g/mL</b> (${f.ref_calories} cal)`);
        }
        
        let weightHtml = `<div style="font-size:13px; color:var(--text-secondary); line-height:1.4;">${metrics.join('<br>')}</div>`;
        options.push({ label: weightHtml, type: "info", noBorder: true });
    }

    options.push({ label: "Actions", type: "header" });
    options.push({ label: `<div style="display:flex; align-items:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:18px; height:18px; stroke-width:1.8; margin-right:12px; opacity:0.8;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg> Edit Food Definition</div>`, value: "edit" });

    window.openPicker(f.name, options, null, (val) => {
        if (val === 'edit') calOpenFoodEditorStudio(f);
    });
};

window.calOpenFoodEditorStudio = function(food = null, prefillName = '') {
    const isEdit = !!food && typeof food === 'object';
    const preName = isEdit ? food.name : (typeof food === 'string' ? food : prefillName);
    const m = isEdit ? (food.total_weight_g / food.ref_amount_g) : 1;
    const activeMode = (isEdit && food.ref_amount_g !== 100) ? 'portion' : (isEdit ? '100g' : 'portion');

    window.sui.openStudio({
        id: 'cal-food-editor',
        title: isEdit ? `Edit: ${food.name}` : 'Add New Food',
        content: `
            <div style="padding:16px; background:var(--bg-color); border-radius:18px; border:1px solid var(--border-color); box-shadow:var(--shadow-card);">
                <input type="text" id="cal-db-name" placeholder="Package / Food Name" value="${preName}" style="width:100%; margin-bottom:16px; font-weight:700;">
                
                <div style="display:flex; background:var(--btn-bg); padding:4px; border-radius:10px; margin-bottom:16px;">
                    <button id="fdb-tab-portion" style="flex:1; border:none; padding:8px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; background:${activeMode === 'portion' ? 'var(--primary)' : 'transparent'}; color:${activeMode === 'portion' ? 'white' : 'var(--text-secondary)'};">By Portion</button>
                    <button id="fdb-tab-100g" style="flex:1; border:none; padding:8px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; background:${activeMode === '100g' ? 'var(--primary)' : 'transparent'}; color:${activeMode === '100g' ? 'white' : 'var(--text-secondary)'};">Per 100g/mL</button>
                </div>

                <div id="fdb-form-portion" style="display:${activeMode === 'portion' ? 'grid' : 'none'}; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:16px;">
                    <div style="grid-column: span 2;">
                        <label style="font-size:10px; color:var(--text-secondary); font-weight:800; display:block; margin-bottom:4px;">PORTION NAME (OPTIONAL)</label>
                        <input type="text" id="fdb-portion-name" autocapitalize="none" placeholder="e.g. bag, scoop, piece" value="${isEdit ? (food.portion_name || '') : ''}" style="width:100%;">
                    </div>
                    <div>
                        <label style="font-size:10px; color:var(--text-secondary); font-weight:800; display:block; margin-bottom:4px;">G/ML PER PORTION</label>
                        <input type="number" id="fdb-portion-size" placeholder="e.g. 30" value="${isEdit ? food.ref_amount_g : ''}" style="width:100%;">
                    </div>
                    <div>
                        <label style="font-size:10px; color:var(--text-secondary); font-weight:800; display:block; margin-bottom:4px;">PORTIONS / PKG</label>
                        <input type="number" id="fdb-portion-count" placeholder="e.g. 10" value="${isEdit ? m : ''}" style="width:100%;">
                    </div>
                </div>

                <div id="fdb-form-100g" style="display:${activeMode === '100g' ? 'block' : 'none'}; margin-bottom:16px;">
                    <label style="font-size:10px; color:var(--text-secondary); font-weight:800; display:block; margin-bottom:4px;">TOTAL PACKAGE G/ML</label>
                    <input type="number" id="fdb-total-weight" placeholder="e.g. 500" value="${isEdit ? food.total_weight_g : ''}" style="width:100%;">
                </div>

                <div style="font-size:10px; font-weight:800; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; border-bottom:1px solid var(--border-color); padding-bottom:4px;">Nutrition Facts <span id="fdb-nutrition-ref-label">(${activeMode === 'portion' ? 'per portion' : 'per 100g/mL'})</span></div>
                
                <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:20px;">
                    ${['calories', 'protein', 'fat', 'sat-fat', 'trans-fat', 'carbs', 'sugar', 'sodium'].map(key => {
                        const label = key.charAt(0).toUpperCase() + key.slice(1).replace('-', ' ');
                        const dbKey = key.replace('-', '_');
                        const val = isEdit ? (m > 0 ? food[dbKey] / m : food[dbKey]) : '';
                        return `
                        <div style="display:flex; align-items:center; gap:12px; ${key.includes('-') ? 'padding-left:15px; opacity:0.8;' : ''}">
                            <span style="flex:1; font-size:${key.includes('-') ? '12px' : '13px'}; font-weight:600; color:${key.includes('-') ? 'var(--text-secondary)' : 'var(--text-primary)'};">${label}</span>
                            <input type="number" id="val-${key}" value="${isEdit ? (Number.isInteger(val) ? val : val.toFixed(1)) : ''}" style="width:80px; text-align:right;">
                        </div>`;
                    }).join('')}
                </div>

                <button id="cal-db-save-btn" class="btn-primary" style="width:100%; display:flex; justify-content:space-between; align-items:center;">
                    <span>${isEdit ? 'Update Database Entry' : 'Save Full Package'}</span>
                    <span id="cal-calc-total" style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:6px; font-size:12px;">0 cal</span>
                </button>
                ${isEdit ? `
                <button id="cal-db-delete-btn" class="btn-primary" style="width:100%; background:var(--btn-bg); color:var(--danger); box-shadow:none; border:1px solid var(--border-color); margin-top:10px; font-size:14px;">
                    Delete Food Definition
                </button>` : ''}
            </div>
        `,
        onSetup: (content, overlay) => {
            let mode = activeMode;
            const tabPortion = overlay.querySelector('#fdb-tab-portion');
            const tab100g = overlay.querySelector('#fdb-tab-100g');
            const formPortion = overlay.querySelector('#fdb-form-portion');
            const form100g = overlay.querySelector('#fdb-form-100g');
            const refLabel = overlay.querySelector('#fdb-nutrition-ref-label');
            const saveBtn = overlay.querySelector('#cal-db-save-btn');

            // Stable baseline tracking to prevent rounding drift during typing
            let focusBaselineSize = 0;
            let focusBaselineNutr = {};

            const runCalc = () => {
                const cals = parseFloat(overlay.querySelector('#val-calories').value) || 0;
                
                // If in 100g mode and portion size is empty, pre-fill to 100 for sync
                if (mode === '100g' && !overlay.querySelector('#fdb-portion-size').value) {
                    overlay.querySelector('#fdb-portion-size').value = 100;
                    currentBaselineSize = 100;
                }

                let multiplier = 1;
                if (mode === 'portion') multiplier = parseFloat(overlay.querySelector('#fdb-portion-count').value) || 1;
                else multiplier = (parseFloat(overlay.querySelector('#fdb-total-weight').value) || 0) / 100;
                const totalCals = Math.round(cals * multiplier);
                overlay.querySelector('#cal-calc-total').innerText = totalCals + " cal";
                saveBtn.dataset.finalCals = totalCals;
                saveBtn.dataset.multiplier = multiplier;
            };

            const portionSizeInp = overlay.querySelector('#fdb-portion-size');
            
            // Capture a "Stable Baseline" when the user clicks into the field
            portionSizeInp.addEventListener('focus', () => {
                focusBaselineSize = parseFloat(portionSizeInp.value) || 0;
                focusBaselineNutr = {};
                ['calories', 'protein', 'fat', 'sat-fat', 'trans-fat', 'carbs', 'sugar', 'sodium'].forEach(k => {
                    const el = overlay.querySelector('#val-' + k);
                    focusBaselineNutr[k] = el ? (parseFloat(el.value) || 0) : 0;
                });
            });

            portionSizeInp.addEventListener('input', () => {
                const newSize = parseFloat(portionSizeInp.value) || 0;
                
                // Auto-calculate portions per package if total weight is known
                const totalWeight = parseFloat(overlay.querySelector('#fdb-total-weight').value) || 0;
                if (totalWeight > 0 && newSize > 0) {
                    overlay.querySelector('#fdb-portion-count').value = Number((totalWeight / newSize).toFixed(2));
                }

                // Only scale if we have a valid starting point and are in the portion tab
                if (mode === 'portion' && focusBaselineSize > 0) {
                    if (newSize > 0) {
                        // Always scale from the original focus values to prevent compounding rounding errors
                        const ratio = newSize / focusBaselineSize;
                        ['calories', 'protein', 'fat', 'sat-fat', 'trans-fat', 'carbs', 'sugar', 'sodium'].forEach(k => {
                            const input = overlay.querySelector('#val-' + k);
                            if (input && focusBaselineNutr[k] !== undefined) {
                                const newVal = focusBaselineNutr[k] * ratio;
                                input.value = (k === 'calories' || k === 'sodium') ? Math.round(newVal) : Number(newVal.toFixed(1));
                            }
                        });
                    }
                }
            });

            const switchTab = (newMode) => {
                if (mode === newMode) return;
                const oldMode = mode;
                mode = newMode;

                // 1. Capture current weights
                let ps = parseFloat(overlay.querySelector('#fdb-portion-size').value) || 0;
                const pc = parseFloat(overlay.querySelector('#fdb-portion-count').value) || 0;
                const tw = parseFloat(overlay.querySelector('#fdb-total-weight').value) || 0;

                // 2. Sync Total Weight / Count across tabs
                if (oldMode === 'portion') {
                    overlay.querySelector('#fdb-total-weight').value = Math.round(ps * pc);
                } else {
                    if (ps === 0) {
                        ps = 100;
                        overlay.querySelector('#fdb-portion-size').value = 100;
                    }
                    overlay.querySelector('#fdb-portion-count').value = Number((tw / ps).toFixed(2));
                }

                // 3. Convert Nutrition Values with high-precision reverse math
                // This ensures that 100g -> 35g -> 100g returns exactly to the original numbers
                if (ps > 0) {
                    const factor = (mode === '100g') ? (100 / ps) : (ps / 100);
                    ['calories', 'protein', 'fat', 'sat-fat', 'trans-fat', 'carbs', 'sugar', 'sodium'].forEach(key => {
                        const el = overlay.querySelector('#val-' + key);
                        if (el && el.value !== '') {
                            const currentVal = parseFloat(el.value) || 0;
                            const converted = currentVal * factor;
                            el.value = (key === 'calories' || key === 'sodium') ? Math.round(converted) : Number(converted.toFixed(1));
                        }
                    });
                }

                // 4. Update UI Styles
                tabPortion.style.background = mode === 'portion' ? 'var(--primary)' : 'transparent';
                tabPortion.style.color = mode === 'portion' ? 'white' : 'var(--text-secondary)';
                tab100g.style.background = mode === '100g' ? 'var(--primary)' : 'transparent';
                tab100g.style.color = mode === '100g' ? 'white' : 'var(--text-secondary)';
                formPortion.style.display = mode === 'portion' ? 'grid' : 'none';
                form100g.style.display = mode === '100g' ? 'block' : 'none';
                refLabel.innerText = mode === 'portion' ? '(per portion)' : '(per 100g/mL)';
                
                runCalc();
            };

            tabPortion.onclick = () => switchTab('portion');
            tab100g.onclick = () => switchTab('100g');

            if (isEdit) {
                const delBtn = overlay.querySelector('#cal-db-delete-btn');
                if (delBtn) {
                    delBtn.onclick = () => {
                        calDbDelete(food.id, () => {
                            window.sui.closeStudio('cal-food-editor');
                            if (typeof window._calRefreshDbList === 'function') window._calRefreshDbList();
                        });
                    };
                }
            }

            content.querySelectorAll('input').forEach(inp => {
                inp.oninput = runCalc;
                inp.onfocus = () => inp.select();
            });
            runCalc();

            saveBtn.onclick = async () => {
                const n = overlay.querySelector('#cal-db-name').value;
                if(!n) return;

                const mult = parseFloat(saveBtn.dataset.multiplier) || 1;
                const getVal = (id) => (parseFloat(overlay.querySelector(id).value) || 0) * mult;
                
                // Determine intended identity (Portion vs 100g)
                const ps = parseFloat(overlay.querySelector('#fdb-portion-size').value) || 0;
                const pn = overlay.querySelector('#fdb-portion-name').value.trim() || null;
                const isPortionRef = (ps > 0);
                
                // Total Weight is absolute
                const totalW = mode === 'portion' ? (ps * (parseFloat(overlay.querySelector('#fdb-portion-count').value) || 0)) : (parseFloat(overlay.querySelector('#fdb-total-weight').value) || 0);

                // Reference Calories must match the reference amount (Portion Size or 100)
                const inputCals = parseFloat(overlay.querySelector('#val-calories').value) || 0;
                const refAmount = isPortionRef ? ps : 100;
                const refCals = (mode === '100g' && isPortionRef) ? Math.round(inputCals * ps / 100) : 
                                (mode === 'portion' && !isPortionRef) ? Math.round(inputCals * 100 / (ps || 1)) : Math.round(inputCals);

                await window.sui.api('cal_manage_food', {
                    mode: isEdit ? 'update' : 'add', id: isEdit ? food.id : null,
                    name: n, calories: saveBtn.dataset.finalCals,
                    protein: getVal('#val-protein'), fat: getVal('#val-fat'), sat_fat: getVal('#val-sat-fat'), trans_fat: getVal('#val-trans-fat'),
                    carbs: getVal('#val-carbs'), sugar: getVal('#val-sugar'), sodium: getVal('#val-sodium'),
                    total_weight: totalW,
                    ref_amount: refAmount,
                    ref_calories: refCals,
                    portion_name: isPortionRef ? pn : null
                });
                await fdbFetchAll();
                if (window.cjosBroadcastUpdate) window.cjosBroadcastUpdate();
                window.sui.closeStudio('cal-food-editor');
            };
        }
    });
};

async function fdbFetchAll() {
    try {
        const data = await window.sui.api('cal_manage_food', { mode: 'get_all' }, { toast: false });
        if(data.status === 'success') {
            fdbItems = data.foods;
            if (typeof calData !== 'undefined') {
                calData.foods = fdbItems;
                if (typeof updateDatalist === 'function') updateDatalist();
            }
            // Trigger UI Refreshes for open studios
            if (typeof window._calRefreshDbList === 'function') window._calRefreshDbList();
            if (typeof window._calRefreshLoggerUI === 'function') window._calRefreshLoggerUI();
            if (typeof window._calRefreshLogDetail === 'function') window._calRefreshLogDetail();
        }
    } catch(e) {}
}

window.calOpenDbStudio = function() {
    window.sui.openStudio({
        id: 'cal-db',
        title: 'Food Database',
        content: `
            <div id="fdb-utilities-wrap" style="margin-bottom:20px;">
                ${window.suiAccordion('fdb-utilities-acc', 'Utilities', '<div id="fdb-utilities-content" style="display:flex; flex-direction:column; gap:10px; padding:16px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:14px; margin-top:8px;"></div>')}
            </div>
            <div style="display:flex; gap:10px; margin-bottom:20px;">
                <input type="text" id="cal-db-search" placeholder="Search saved foods..." style="flex:1;">
                <button id="cal-db-add-trigger" class="btn-primary" style="padding:10px 16px; font-size:13px;">+ New</button>
            </div>
            <div id="cal-studio-db-list" style="display:flex; flex-direction:column;"></div>
        `,
        onSetup: (content, overlay) => {
            const search = overlay.querySelector('#cal-db-search');
            search.onfocus = () => search.select();
            const list = overlay.querySelector('#cal-studio-db-list');
            const utilContent = overlay.querySelector('#fdb-utilities-content');
            
            window._calRefreshDbList = () => renderList();

            overlay.querySelector('#cal-db-add-trigger').onclick = () => calOpenFoodEditorStudio();

            // --- UTILITY: IMPORT WAISTLINE ---
            const importBtn = document.createElement('button');
            importBtn.className = 'text-btn';
            importBtn.style.cssText = "width:100%; border:1px dashed var(--border-color); color:var(--text-secondary); font-size:12px; font-weight:700; border-radius:10px; padding:12px;";
            importBtn.innerText = "IMPORT WAISTLINE JSON";
            importBtn.onclick = () => {
                const input = document.createElement('input');
                input.type = 'file'; input.accept = '.json';
                input.onchange = (e) => {
                    const file = e.target.files[0];
                    const reader = new FileReader();
                    reader.onload = async (ev) => {
                        try {
                            const data = JSON.parse(ev.target.result);
                            const foods = data.foodList || [];
                            const recipes = data.recipes || [];
                            const all = [...foods, ...recipes];
                            window.openConfirm("Import Data", `Found ${foods.length} foods and ${recipes.length} recipes. Import to database? (Duplicates will be ignored)`, async () => {
                                const payload = all.map(f => {
                                    const n = f.nutrition || {};
                                    return {
                                        name: (f.brand ? f.brand + " - " : "") + f.name,
                                        calories: n.calories || 0,
                                        protein: n.proteins || 0,
                                        fat: n.fat || 0,
                                        sat_fat: n['saturated-fat'] || 0,
                                        trans_fat: n['trans-fat'] || 0,
                                        carbs: n.carbohydrates || 0,
                                        sugar: n.sugars || 0,
                                        sodium: n.sodium || 0,
                                        total_weight: f.portion || 0,
                                        ref_amount: f.portion || 0,
                                        ref_calories: n.calories || 0,
                                        portion_name: f.unit || null
                                    };
                                });
                                await window.sui.api('cal_manage_food', { mode: 'import_batch', payload: JSON.stringify(payload) });
                                await fdbFetchAll();
                                if (window.cjosBroadcastUpdate) window.cjosBroadcastUpdate();
                            });
                        } catch(err) { alert("Invalid JSON file"); }
                    };
                    reader.readAsText(file);
                };
                input.click();
            };
            utilContent.appendChild(importBtn);

            // --- UTILITY: BATCH RENAME ---
            const batchBtn = document.createElement('button');
            batchBtn.className = 'text-btn';
            batchBtn.style.cssText = "width:100%; border:1px dashed var(--border-color); color:var(--text-secondary); font-size:12px; font-weight:700; border-radius:10px; padding:12px;";
            batchBtn.innerText = "BATCH RENAME PORTIONS";
            batchBtn.onclick = () => calOpenPortionBatchStudio();
            utilContent.appendChild(batchBtn);

            const renderList = () => {
                const term = search.value.toLowerCase();
                list.innerHTML = '';
                const visible = term === '' ? fdbItems : fdbItems.filter(f => {
                    if (term === '[empty]') return !f.portion_name || f.portion_name.trim() === '';
                    return f.name.toLowerCase().includes(term) || 
                           (f.portion_name && f.portion_name.toLowerCase().includes(term));
                });
                
                if (visible.length === 0) {
                    list.innerHTML = window.suiEmptyState('🔍', 'No foods found');
                    return;
                }

                visible.forEach(f => {
                    const div = document.createElement('div');
                    div.style.cssText = "display:flex; justify-content:space-between; padding:14px 0; border-bottom:1px solid var(--border-color); align-items:center; color:var(--text-primary); cursor:pointer;";
                    div.innerHTML = `
                        <div style="flex:1;">
                            <div style="font-weight:700;">${f.name}</div>
                            <div style="font-size:12px; color:var(--text-secondary);">${f.ref_calories || 0} cal / ${f.portion_name || 'serving'} • ${f.calories} cal total</div>
                        </div>
                        <button class="cal-db-del-btn" style="background:rgba(255,59,48,0.1); border:none; padding:6px 12px; border-radius:8px; color:var(--danger); font-size:11px; font-weight:800; cursor:pointer;" data-id="${f.id}">Delete</button>
                    `;
                    div.onclick = () => calShowFoodDetails(f);
                    div.querySelector('.cal-db-del-btn').onclick = (e) => { e.stopPropagation(); calDbDelete(f.id, renderList); };
                    list.appendChild(div);
                });
            };

            search.oninput = renderList;
            window.suiHydrateIcons(overlay);
            renderList();
        }
    });
};

window.calOpenPortionBatchStudio = function() {
    window.sui.openStudio({
        id: 'cal-portion-batch',
        title: 'Batch Rename Portions',
        content: `
            <div style="display:flex; flex-direction:column; gap:16px;">
                <div style="font-size:12px; color:var(--text-secondary); line-height:1.4; background:var(--btn-bg); padding:12px; border-radius:12px;">
                    This tool finds all unique portion names in your database and lets you rename them across all foods at once.
                </div>
                <div style="position:relative; display:flex; align-items:center;">
                    <input type="text" id="cal-pb-search" placeholder="Search portion names..." style="width:100%; padding-left:35px;">
                    <span data-sui-icon="search" data-sui-size="14" data-sui-color="var(--text-secondary)" style="position:absolute; left:12px; pointer-events:none;"></span>
                </div>
                <div id="cal-pb-list" style="display:flex; flex-direction:column; gap:2px;"></div>
            </div>
        `,
        onSetup: (content, overlay) => {
            const search = overlay.querySelector('#cal-pb-search');
            const list = overlay.querySelector('#cal-pb-list');
            let portions = [];

            const fetchAndRender = async () => {
                const res = await window.sui.api('cal_batch_portions', { mode: 'get_unique' }, { toast: false });
                if (res.status === 'success') {
                    portions = res.data;
                    renderList();
                }
            };

            const renderList = () => {
                const term = search.value.toLowerCase();
                list.innerHTML = '';
                const visible = portions.filter(p => (p.portion_name || '').toLowerCase().includes(term));

                if (visible.length === 0) {
                    list.innerHTML = window.suiEmptyState('🏷️', 'No portion names found');
                    return;
                }

                visible.forEach(p => {
                    const name = p.portion_name;
                    const hex = p.hex_id || 'NULL';
                    const isGhost = !name && hex !== 'NULL' && hex !== '';
                    const display = isGhost ? `Ghost Entry (Hex: ${hex})` : (name || '(No Name Assigned)');
                    
                    const div = document.createElement('div');
                    div.style.cssText = "display:flex; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--border-color); align-items:center; color:var(--text-primary); cursor:pointer; background:var(--card-bg); border-radius:12px; margin-bottom:4px; transition: background 0.2s;";
                    if (isGhost) div.style.border = "1px dashed var(--danger)";

                    div.innerHTML = `
                        <div style="flex:1;">
                            <div style="font-weight:700; color:${isGhost ? 'var(--danger)' : (name ? 'var(--text-primary)' : 'var(--text-secondary)')};">${display}</div>
                            <div style="font-size:11px; color:var(--primary); font-weight:800; text-transform:uppercase; margin-top:2px;">Used in ${p.count} foods</div>
                        </div>
                        <div style="display:flex; gap:12px; align-items:center;">
                            <button class="cal-pb-view-btn" style="background:var(--btn-bg); border:none; padding:8px; border-radius:8px; color:var(--primary); cursor:pointer; display:flex;" title="View Items">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px; height:16px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                            <span data-sui-icon="edit" data-sui-size="16" data-sui-color="var(--primary)"></span>
                        </div>
                    `;

                    div.querySelector('.cal-pb-view-btn').onclick = (e) => {
                        e.stopPropagation();
                        const parentSearch = document.querySelector('#cal-db-search');
                        if (parentSearch) {
                            // If name is null/empty, we set a special placeholder that our expanded search logic can catch
                            parentSearch.value = name || "[EMPTY]";
                            parentSearch.dispatchEvent(new Event('input'));
                            window.sui.closeStudio('cal-portion-batch');
                        }
                    };

                    div.onclick = () => {
                        window.openInput("Rename Portion", `Rename all "${display}" to:`, name || "", async (newVal) => {
                            if (newVal === name) return;
                            const updateRes = await window.sui.api('cal_batch_portions', { 
                                mode: 'update', 
                                hex_id: hex, 
                                new_name: newVal 
                            });
                            if (updateRes.status === 'success') {
                                fetchAndRender();
                                fdbFetchAll(); // Refresh main DB cache
                            }
                        });
                    };
                    list.appendChild(div);
                });
                window.suiHydrateIcons(list);
            };

            search.oninput = renderList;
            window.suiHydrateIcons(overlay);
            fetchAndRender();
        }
    });
};

window.calDbDelete = async function(id, callback) {
    window.openConfirm("Delete Food", "Remove this item permanently from the database?", async () => {
        await window.sui.api('cal_manage_food', { mode: 'delete', id: id });
        await fdbFetchAll();
        if (window.cjosBroadcastUpdate) window.cjosBroadcastUpdate();
        if (callback) callback();
    }, true);
};
JS;
?>