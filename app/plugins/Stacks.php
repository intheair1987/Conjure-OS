<?php
// ==============================================================================
// PLUGIN: Stacks
// DESCRIPTION: Group Notes into Piles.
// Data: SQLite (stacks, stack_members) | Settings: JSON (data/stacks-config.json)
// ==============================================================================

$st_config_file = CJOS_PATH_DATA . '/stacks-config.json';

// --- 1. PRE-LOAD DATA (Zero-Latency Rendering) ---
$st_preloaded = null;
try {
    $st_settings = file_exists($st_config_file) ? json_decode(file_get_contents($st_config_file), true) : [];
    $st_rows = $db->query("SELECT * FROM stacks")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($st_rows as &$s) {
        $stmt = $db->prepare("SELECT log_id FROM stack_members WHERE stack_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$s['id']]);
        $s['ids'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $st_preloaded = array_merge(['stacks' => $st_rows], (is_array($st_settings) ? $st_settings : []));
} catch (Exception $e) {}

if ($st_preloaded) {
    $plugin_js .= <<<'JS'
window.preloadedStacks =
JS . json_encode($st_preloaded) . ";\n";
}

// --- 2. DATABASE SETUP ---
try {
    // Create Stacks Metadata Table
    $db->exec("CREATE TABLE IF NOT EXISTS stacks (
        id TEXT PRIMARY KEY, 
        name TEXT, 
        folder_id INTEGER
    )");

    // Create Stack Members Table (Card Links)
    $db->exec("CREATE TABLE IF NOT EXISTS stack_members (
        stack_id TEXT, 
        log_id TEXT, 
        sort_order INTEGER,
        FOREIGN KEY(stack_id) REFERENCES stacks(id) ON DELETE CASCADE
    )");

    // Cleanup legacy settings table if it exists (Optional, ensures clean state)
    // $db->exec("DROP TABLE IF EXISTS stack_settings"); 
} catch (Exception $e) {}

// --- 2. BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'stacks_get') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        // A. Load Settings from JSON
        $settings = [
            'spacing' => 8,
            'gap' => 12,
            'footer_margin' => 32,
            'theme' => 'glass',
            'layout_mode' => 'full',
            'grid_cols' => 2,
            'grid_count_opacity' => 60
        ];
        
        if (file_exists($st_config_file)) {
            $loaded = json_decode(file_get_contents($st_config_file), true);
            if (is_array($loaded)) {
                $settings = array_merge($settings, $loaded);
            }
        }
        
        // B. Load Data from SQLite
        $stacks = $db->query("SELECT * FROM stacks")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stacks as &$s) {
            $stmt = $db->prepare("SELECT log_id FROM stack_members WHERE stack_id = ? ORDER BY sort_order ASC");
            $stmt->execute([$s['id']]);
            $s['ids'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $data = array_merge(['stacks' => $stacks], $settings);

        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }

    if ($_POST['plugin_action'] === 'stacks_save') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $payload = json_decode($_POST['payload'], true);

        // A. Save Settings to JSON
        $settingsData = [
            'spacing' => $payload['spacing'] ?? 8,
            'gap' => $payload['gap'] ?? 12,
            'footer_margin' => $payload['footer_margin'] ?? 32,
            'theme' => $payload['theme'] ?? 'glass',
            'layout_mode' => $payload['layout_mode'] ?? 'full',
            'grid_cols' => $payload['grid_cols'] ?? 2,
            'grid_count_opacity' => $payload['grid_count_opacity'] ?? 60
        ];
        
        // Ensure directory exists
        $dir = dirname($st_config_file);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        file_put_contents($st_config_file, json_encode($settingsData, JSON_PRETTY_PRINT));

        // B. Save Stacks to SQLite
        $db->beginTransaction();
        try {
            // Clear current state
            $db->exec("DELETE FROM stacks");
            $db->exec("DELETE FROM stack_members");

            // Insert new state
            $stmtStack = $db->prepare("INSERT INTO stacks (id, name, folder_id) VALUES (?, ?, ?)");
            $stmtMember = $db->prepare("INSERT INTO stack_members (stack_id, log_id, sort_order) VALUES (?, ?, ?)");

            if (isset($payload['stacks']) && is_array($payload['stacks'])) {
                foreach ($payload['stacks'] as $s) {
                    $stmtStack->execute([$s['id'], $s['name'], $s['folder_id']]);
                    if (isset($s['ids']) && is_array($s['ids'])) {
                        foreach ($s['ids'] as $idx => $lid) {
                            $stmtMember->execute([$s['id'], $lid, $idx]);
                        }
                    }
                }
            }

            $db->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// --- 3. SETTINGS UI ---
$plugin_settings_map['Stacks'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Card Stacking</label>
            <span class="setting-desc">Group selected notes into a compact pile at the top of folders.</span>
        </div>
        <div style="color:var(--primary); font-weight:600; font-size:12px;">Active</div>
    </div>
    <div class="setting-item vertical">
        <label class="setting-label">Stack Depth (Pile)</label>
        <div class="setting-desc">Adjust how tightly the cards are packed inside the pile.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="stack-spacing-slider" min="2" max="20" step="1" oninput="updateStackSpacingUI(this.value)" onchange="saveStacksToServer()" style="flex:1;">
            <span id="stack-spacing-val" style="font-weight:700; color:var(--primary); min-width:40px;">8px</span>
        </div>
    </div>

    <div class="setting-item vertical">
        <label class="setting-label">Inter-Stack Gap</label>
        <div class="setting-desc">Distance between individual stacks/piles.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="stack-gap-slider" min="0" max="60" step="1" oninput="updateStackGapUI(this.value)" onchange="saveStacksToServer()" style="flex:1;">
            <span id="stack-gap-val" style="font-weight:700; color:var(--primary); min-width:40px;">12px</span>
        </div>
    </div>

    <div class="setting-item vertical">
        <label class="setting-label">Stack Block Footer Margin</label>
        <div class="setting-desc">Space between the bottom of the stacks and the date header below.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="stack-footer-slider" min="0" max="100" step="2" oninput="updateStackFooterUI(this.value)" onchange="saveStacksToServer()" style="flex:1;">
            <span id="stack-footer-val" style="font-weight:700; color:var(--primary); min-width:40px;">32px</span>
        </div>
    </div>
    <div class="setting-item vertical">
        <label class="setting-label">Stack Layout</label>
        <div class="setting-desc">Choose between full-width bars or a compact card grid.</div>
        <select id="stack-layout-select" onchange="updateStackLayoutUI(this.value); saveStacksToServer()" style="
            width: 100%; padding: 12px; border-radius: 12px; border: 1px solid var(--border-color);
            background: var(--btn-bg); color: var(--text-primary); font-size: 15px; margin-top: 8px; appearance: none;
        ">
            <option value="full">Full Width (Bars)</option>
            <option value="grid">Card Grid (Rectangles)</option>
        </select>
    </div>

    <div id="stack-grid-cols-row" class="setting-item vertical">
        <label class="setting-label">Grid Columns</label>
        <div class="setting-desc">Number of card piles per row.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="stack-cols-slider" min="1" max="4" step="1" oninput="updateStackColsUI(this.value)" onchange="saveStacksToServer()" style="flex:1;">
            <span id="stack-cols-val" style="font-weight:700; color:var(--primary); min-width:40px;">2</span>
        </div>
    </div>

    <div id="stack-count-opacity-row" class="setting-item vertical">
        <label class="setting-label">Grid Item Count Opacity</label>
        <div class="setting-desc">Visibility of the number at the bottom of grid cards.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="stack-count-opacity-slider" min="10" max="100" step="5" oninput="updateStackCountOpacityUI(this.value)" onchange="saveStacksToServer()" style="flex:1;">
            <span id="stack-count-opacity-val" style="font-weight:700; color:var(--primary); min-width:40px;">60%</span>
        </div>
    </div>


    <div class="setting-item" style="justify-content:center;">
        <button onclick="saveStacksToServer(false)" class="text-btn" style="
            background:var(--primary); color:white; padding:12px 24px; 
            border-radius:12px; font-weight:600; width:100%;
        ">Save Settings</button>
    </div>
    <div id="stacks-save-status" style="text-align:center; font-size:11px; color:#8E8E93; margin-bottom:16px; height:14px;"></div>

    <div class="setting-item">
        <button onclick="unstackAll()" class="text-btn" style="color:var(--danger); width:100%;">Dissolve All Stacks</button>
    </div>
HTML;

// --- 3. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- STACKS PLUGIN JS ---

let cjosStacks = [];
let cjosStackConfig = { spacing: 8, gap: 12, footer_margin: 32, theme: 'glass', layout_mode: 'full', grid_cols: 2, grid_count_opacity: 60 };
window.expandedStackId = null;
let activeStackEditing = false;
let _stacksBusy = false;

// --- 0. UI UPDATE API (Defined Early to avoid Hoisting/Race conditions) ---
window.updateStackSpacingUI = function(val, updateSlider = false) {
    cjosStackConfig.spacing = parseInt(val);
    document.documentElement.style.setProperty('--stack-spacing', val + 'px');
    const label = document.getElementById('stack-spacing-val');
    if(label) label.innerText = val + 'px';
    if(updateSlider && document.getElementById('stack-spacing-slider')) {
        document.getElementById('stack-spacing-slider').value = val;
    }
};

window.updateStackGapUI = function(val, updateSlider = false) {
    cjosStackConfig.gap = parseInt(val);
    document.documentElement.style.setProperty('--stack-gap', val + 'px');
    const label = document.getElementById('stack-gap-val');
    if(label) label.innerText = val + 'px';
    if(updateSlider && document.getElementById('stack-gap-slider')) {
        document.getElementById('stack-gap-slider').value = val;
    }
};

window.updateStackFooterUI = function(val, updateSlider = false) {
    cjosStackConfig.footer_margin = parseInt(val);
    document.documentElement.style.setProperty('--stack-footer-margin', val + 'px');
    const label = document.getElementById('stack-footer-val');
    if(label) label.innerText = val + 'px';
    if(updateSlider && document.getElementById('stack-footer-slider')) {
        document.getElementById('stack-footer-slider').value = val;
    }
};

window.updateStackThemeUI = function(themeName, updateSelect = false) {
    // Managed by ThemePresets
};

window.updateStackLayoutUI = function(mode, updateSelect = false) {
    cjosStackConfig.layout_mode = mode;
    const rowCols = document.getElementById('stack-grid-cols-row');
    const rowOpac = document.getElementById('stack-count-opacity-row');
    if(rowCols) rowCols.style.display = (mode === 'grid') ? 'block' : 'none';
    if(rowOpac) rowOpac.style.display = (mode === 'grid') ? 'block' : 'none';
    if(updateSelect && document.getElementById('stack-layout-select')) {
        document.getElementById('stack-layout-select').value = mode;
    }
    if (typeof renderStacksUI === 'function') renderStacksUI();
};

window.updateStackColsUI = function(val, updateSlider = false) {
    cjosStackConfig.grid_cols = parseInt(val);
    document.documentElement.style.setProperty('--stack-cols', val);
    const label = document.getElementById('stack-cols-val');
    if(label) label.innerText = val;
    if(updateSlider && document.getElementById('stack-cols-slider')) {
        document.getElementById('stack-cols-slider').value = val;
    }
    if (typeof renderStacksUI === 'function') renderStacksUI();
};

window.updateStackCountOpacityUI = function(val, updateSlider = false) {
    cjosStackConfig.grid_count_opacity = parseInt(val);
    document.documentElement.style.setProperty('--stack-count-opacity', val / 100);
    const label = document.getElementById('stack-count-opacity-val');
    if(label) label.innerText = val + '%';
    if(updateSlider && document.getElementById('stack-count-opacity-slider')) {
        document.getElementById('stack-count-opacity-slider').value = val;
    }
    if (typeof renderStacksUI === 'function') renderStacksUI();
};

(function() {
    // 1. INJECT STYLES
    const style = document.createElement('style');
    style.innerHTML = `
        .stack-pile-container {
            /* Vertical margin between piles */
            margin: 0 12px var(--stack-gap, 12px) 12px;
            transition: 
                transform 0.3s cubic-bezier(0.16, 1, 0.3, 1),
                opacity 0.4s cubic-bezier(0.16, 1, 0.3, 1),
                max-height 0.4s cubic-bezier(0.16, 1, 0.3, 1),
                margin-bottom 0.5s ease; /* Smooth out any dynamic spacing changes */
            perspective: 1000px;
            cursor: pointer;
            position: relative;
            height: 100px;
            display: flex;
            align-items: flex-end;
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            /* Ensure shadows aren't clipped by the container box */
            overflow: visible !important;
            z-index: 10;
        }
        .stack-pile-container:active { transform: scale(0.98); }

        /* STRICT HIDING: Overrides SmartOrganizer/LiveSync display resets */
        .card.is-stacked-hidden {
            display: none !important;
        }

        /* Ensure the main container doesn't clip these specific shadows */
        #entries-container { contain: none !important; }            .stack-visual-card {
                position: absolute;
                width: 100%;
                height: 85px;
                background: var(--card-bg);
                border-radius: 22px;
                border: 1px solid var(--border-color);
                display: flex;
                flex-direction: column;
                justify-content: center;
                padding: 0 20px;
                box-sizing: border-box;
                transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
                box-shadow: var(--shadow-card);
            }

        /* 3D Stack Offsets - Dynamic Spacing */
        .stack-visual-card:nth-child(1) { transform: translateY(calc(-2 * var(--stack-spacing, 8px))) scale(0.92); z-index: 1; opacity: 0.3; }
        .stack-visual-card:nth-child(2) { transform: translateY(calc(-1 * var(--stack-spacing, 8px))) scale(0.96); z-index: 2; opacity: 0.6; }
        .stack-visual-card:nth-child(3) { transform: translateY(0) scale(1); z-index: 3; box-shadow: 0 12px 30px rgba(0,0,0,0.06); }

        .stack-label-pill {
            background: var(--btn-bg);
            color: var(--text-secondary);
            font-size: 8px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            border: 1px solid var(--border-color);
            flex-shrink: 0;
        }

        .stack-title-text {
            font-family: "New York", "Georgia", serif;
            font-style: italic;
            font-weight: 700;
            font-size: 15px;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-shadow: 0px 1px 0px rgba(255,255,255,0.8);
            flex: 1;
            margin-right: 10px;
        }

        .stack-preview-line {
            font-size: 11px;
            color: var(--text-secondary);
            opacity: 0.7;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.4;
            max-width: 90%;
        }

        /* EXPANDED STATE - Theme Aware */
        .expanded-stack-wrapper {
            background: var(--bg-color);
            filter: brightness(0.98);
            border-radius: 32px;
            padding: 24px 16px;
            /* Vertical margin between stacks */
            margin: 0 8px var(--stack-gap, 12px) 8px;
            border: 1px solid rgba(0,0,0,0.05);
            box-shadow: var(--shadow-card);
            animation: stackExpandIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            
            /* SCROLL ANCHORING: Ensures room for Header + Organizer Bar */
            scroll-margin-top: 140px;

            transition: 
                opacity 0.4s ease,
                transform 0.5s cubic-bezier(0.16, 1, 0.3, 1),
                filter 0.4s ease,
                margin-bottom 0.5s ease,
                max-height 0.6s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }

        /* COLLAPSING STATE - The Outro Animation */
        .expanded-stack-wrapper.stack-collapsing {
            opacity: 0 !important;
            transform: translateY(-20px) scale(0.98) !important;
            filter: blur(10px) !important;
            /* We no longer collapse height to 0 here; the Layout Guard handles the shift */
            pointer-events: none !important;
        }

        /* EDIT MODE LOGIC */
        .btn-dissolve-stack {
            display: none !important; /* Hidden until edit mode */
            color: var(--danger) !important;
        }

        .expanded-stack-wrapper.is-editing .btn-dissolve-stack,
        .expanded-stack-wrapper.is-editing .btn-move-stack {
            display: flex !important;
        }

        .btn-move-stack {
            display: none !important;
        }

        .expanded-stack-wrapper.is-editing .btn-edit-stack {
            background: var(--primary) !important;
            color: var(--primary-text) !important;
        }

        .stack-card-remove-btn {
            display: none !important; /* Hidden until edit mode */
        }

        .expanded-stack-wrapper.is-editing .stack-card-remove-btn {
            display: flex !important;
        }

        /* HIDE OTHERS WHEN ONE IS EXPANDED */
        .stack-hidden-by-focus {
            opacity: 0 !important;
            pointer-events: none !important;
            max-height: 0 !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            overflow: hidden !important;
        }

        @keyframes stackExpandIn {
            from { opacity: 0; transform: translateY(-30px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .expanded-stack-wrapper.no-entrance-anim {
            animation: none !important;
        }

        .stack-header-bar {
            display: flex;
            flex-direction: column; /* Stack vertically to accommodate long titles */
            align-items: flex-start;
            gap: 16px;
            padding: 4px 12px 28px 12px;
        }

        .stack-title-heading {
            font-family: "New York", "Georgia", serif;
            font-style: italic;
            font-weight: 800;
            font-size: 22px;
            color: var(--text-title);
            letter-spacing: -0.5px;
            line-height: 1.2;
            width: 100%;
            padding-right: 75px; /* Space for corner buttons */
            box-sizing: border-box;
            cursor: pointer;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
            /* ENGRAVED LOOK: Highlight on the bottom, faint shadow on top */
            text-shadow: 
                0px 1px 0px rgba(255,255,255,0.8),  /* Bevel highlight */
                0px -0.5px 0px rgba(0,0,0,0.15);   /* Depth shadow */
        }

        .stack-title-heading:active {
            opacity: 0.6;
        }

        .stack-corner-actions {
            position: absolute;
            top: 22px;
            right: 18px;
            display: flex;
            gap: 8px;
            z-index: 10;
        }

        .stack-corner-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 1px solid var(--border-color);
            background: var(--btn-bg);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            padding: 0;
        }

        .stack-corner-btn:hover {
            background: var(--card-bg);
            color: var(--text-primary);
            transform: scale(1.05);
        }

        .stack-corner-btn:active {
            transform: scale(0.92);
            opacity: 0.7;
        }

        /* Focus/Selection fix: stop the button from staying dark after toggle */
        .stack-corner-btn:focus {
            outline: none !important;
            background: var(--btn-bg);
            color: var(--text-secondary);
        }

        .expanded-stack-wrapper.is-editing .btn-edit-stack:focus {
            background: var(--primary) !important;
            color: var(--primary-text) !important;
        }

        .stack-corner-btn svg {
            width: 14px;
            height: 14px;
            stroke-width: 3;
        }

        /* ANIMATIONS: Cinematic Lifecycle */
        @keyframes stackCardGhostlyVanish {
            /* Stage 1: Visual Dissolve & Slide Up */
            0% { 
                transform: scale(1); 
                opacity: 1; 
                filter: blur(0); 
                max-height: var(--start-height, 200px); 
                margin-bottom: 16px;
            }
            /* Continuous Collapse: Start shrinking immediately to guide the eye */
            100% { 
                transform: scale(0.9) translateY(-20px); 
                opacity: 0; 
                filter: blur(10px); 
                max-height: 0;
                margin-top: 0;
                margin-bottom: 0;
                padding-top: 0;
                padding-bottom: 0;
            }
        }
        .stack-card-implode {
            animation: stackCardGhostlyVanish 0.85s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
            pointer-events: none !important;
            z-index: 100 !important;
            overflow: hidden !important;
        }

        /* STAGE 1: The Wedge (Pure Layout Move) */
        @keyframes stackGapExpand {
            0% { max-height: 0; margin-bottom: 0; padding-top: 0; }
            100% { max-height: 120px; margin-bottom: var(--stack-gap, 12px); padding-top: 10px; }
        }
        .stack-expanding-gap {
            animation: stackGapExpand 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            overflow: hidden !important;
            will-change: max-height;
        }

        /* STAGE 2: The Materialization (Pure Visual Paint) */
        @keyframes stackPileFadeIn {
            0% { opacity: 0; transform: scale(0.95) translateY(10px); filter: blur(10px); }
            100% { opacity: 1; transform: scale(1) translateY(0); filter: blur(0); }
        }
        .stack-materializing .stack-visual-card {
            animation: stackPileFadeIn 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        /* Hide visuals during the physical expansion phase to keep 60fps */
        .stack-expanding-gap .stack-visual-card {
            opacity: 0 !important;
        }
        
        /* Re-enable high-quality effects only after the expansion finishes */
        .stack-pile-container.entrance-complete {
            backdrop-filter: blur(20px) saturate(160%) !important;
            -webkit-backdrop-filter: blur(20px) saturate(160%) !important;
            overflow: visible !important;
        }

        @keyframes stackCardMaterialize {
            0% { transform: translateY(30px) scale(0.95); opacity: 0; filter: blur(15px); }
            100% { transform: translateY(0) scale(1); opacity: 1; filter: blur(0); }
        }
        .stack-card-return {
            animation: stackCardMaterialize 1s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
            /* Overrides both ScrollReveal and the stacked-hidden class during transition */
            visibility: visible !important;
            display: block !important;
        }

        /* Ensure headers move smoothly when stacks appear/disappear */
        .section-header {
            transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.4s ease;
        }

        /* ANIMATIONS: Cinematic Lifecycle */
        @keyframes stackHeaderCinematicExit {
            /* Stage 1: Visual Fade (Cards below stay still) */
            0% { 
                opacity: 1; 
                transform: translateY(0) scale(1); 
                filter: blur(0); 
                max-height: var(--exit-height, 400px); 
                margin-bottom: var(--stack-gap, 12px);
                border-width: 1px;
            }
            60% { 
                opacity: 0; 
                transform: translateY(-20px) scale(0.98); 
                filter: blur(10px); 
                max-height: var(--exit-height, 400px); 
                margin-bottom: var(--stack-gap, 12px);
                border-width: 1px;
            }
            /* Stage 2: Physical Collapse (Cards below move up) */
            100% { 
                opacity: 0; 
                transform: translateY(-30px) scale(0.95); 
                filter: blur(20px); 
                max-height: 0; 
                min-height: 0;
                margin-top: 0;
                margin-bottom: 0; 
                padding-top: 0;
                padding-bottom: 0;
                border-width: 0; /* KILL THE SNAP */
            }
        }
        .stack-exit-cinematic {
            /* Slower duration to allow the eye to track the transition */
            animation: stackHeaderCinematicExit 1.1s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
            pointer-events: none !important;
            overflow: hidden !important;
            /* Force hardware acceleration for the height collapse */
            will-change: max-height, transform, opacity;
        }

        /* WRAPPER FOR ALL STACKS AT TOP */
        #stacks-section-wrapper {
            display: flex;
            flex-direction: column;
            margin-bottom: var(--stack-footer-margin, 32px);
            padding-top: 10px;
            transition: margin-bottom 1.1s cubic-bezier(0.16, 1, 0.3, 1), padding-top 1.1s cubic-bezier(0.16, 1, 0.3, 1);
        }

        /* GRID MODE OVERRIDES */
        #stacks-section-wrapper.mode-grid {
            display: grid;
            grid-template-columns: repeat(var(--stack-cols, 2), 1fr);
            gap: var(--stack-gap, 12px);
            padding: 10px 16px;
            align-items: start;
        }

        #stacks-section-wrapper.mode-grid .stack-pile-container {
            margin: 0 !important;
            height: 120px; /* Taller height for card-like appearance */
        }

        #stacks-section-wrapper.mode-grid .stack-visual-card {
            height: 100px;
            padding: 12px;
        }

        #stacks-section-wrapper.mode-grid .stack-title-text {
            font-size: 13px;
            width: 100%;
            margin-right: 0;
        }

        #stacks-section-wrapper.mode-grid .stack-info-row {
            display: block !important;
            margin-bottom: 2px !important;
        }

        #stacks-section-wrapper.mode-grid .stack-label-pill {
            position: absolute;
            bottom: 10px;
            right: 12px;
            margin: 0;
            font-size: 10px;
            font-weight: 700;
            padding: 0;
            background: none !important;
            border: none !important;
            box-shadow: none !important;
            color: var(--text-primary);
            opacity: var(--stack-count-opacity, 0.6);
        }

        #stacks-section-wrapper.mode-grid .stack-preview-line {
            display: block;
            white-space: nowrap; /* Match bar behavior: 1 line per card */
            text-overflow: ellipsis;
            overflow: hidden;
            font-size: 10px;
            margin-top: 1px;
            line-height: 1.3;
            opacity: 0.6;
        }

        #stacks-section-wrapper.mode-grid .stack-visual-card {
            height: 100px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start; /* Align to top to make room for text */
            padding-top: 14px;
        }

        #stacks-section-wrapper.mode-grid .expanded-stack-wrapper {
            grid-column: 1 / -1; /* Expanded stacks always take full width */
            margin: 0 !important;
        }

        #stacks-section-wrapper.section-vanishing {
            margin-bottom: 0 !important;
            padding-top: 0 !important;
        }
        /* 
           Legacy rule removed to prevent layout snap on exit. 
           Keeping margins consistent on all items ensures stability when siblings are removed.
        */
    `;
    document.head.appendChild(style);

    // 2. INIT & HANDSHAKES
    // Start loading immediately
    loadStacksFromServer();

    window.addEventListener('load', () => {
        // Inject Stack Button
        setTimeout(injectStackActionBtn, 400);
    });

    // REGISTER VIA HANDSHAKE (Structural Priority 15)
    if (window.registerCardPlugin) {
        window.registerCardPlugin((card) => {
            const id = card.querySelector('.custom-checkbox')?.getAttribute('data-id');
            const allStackedIds = cjosStacks.reduce((acc, s) => acc.concat(s.ids), []);
            
            if (id && allStackedIds.includes(id)) {
                // Use class-based hiding to survive style re-writes by other plugins
                if (!card.closest('.expanded-stack-wrapper')) {
                    card.classList.add('is-stacked-hidden');
                } else {
                    card.classList.remove('is-stacked-hidden');
                }
            }
        }, 15);
    }

    // DRAW PILES AFTER EVERY RENDER
    if (window.registerRefreshHook) {
        window.registerRefreshHook(renderStacksUI);
    }

    // --- THE TOP ENFORCER ---
    // Ensures the stacks section stays at index 0 even if other plugins prepend items.
    window.addEventListener('load', () => {
        const container = document.getElementById('entries-container');
        if (!container) return;

        const enforcer = new MutationObserver(() => {
            if (window._cjosIsRendering) return; // Wait for core renderer to finish
            const wrapper = document.getElementById('stacks-section-wrapper');
            if (wrapper && container.firstChild !== wrapper) {
                enforcer.disconnect();
                container.prepend(wrapper);
                enforcer.observe(container, { childList: true });
            }
        });

        enforcer.observe(container, { childList: true });
    });

    async function loadStacksFromServer() {
        if (window.preloadedStacks) {
            applyStacksData(window.preloadedStacks);
            return;
        }
        try {
            const json = await window.sui.api('stacks_get', {}, { toast: false });
            applyStacksData(json.data);
        } catch(e) {}
    }

    function applyStacksData(data) {
        cjosStacks = data.stacks || [];
        cjosStackConfig = {
            spacing: data.spacing ?? 8,
            gap: data.gap ?? 12,
            footer_margin: data.footer_margin ?? 32,
            theme: data.theme ?? 'glass',
            layout_mode: data.layout_mode ?? 'full',
            grid_cols: data.grid_cols ?? 2,
            grid_count_opacity: data.grid_count_opacity ?? 60
        };

        updateStackSpacingUI(cjosStackConfig.spacing, true);
        updateStackGapUI(cjosStackConfig.gap, true);
        updateStackFooterUI(cjosStackConfig.footer_margin, true);
        updateStackThemeUI(cjosStackConfig.theme, true);
        updateStackLayoutUI(cjosStackConfig.layout_mode, true);
        updateStackColsUI(cjosStackConfig.grid_cols, true);
        updateStackCountOpacityUI(cjosStackConfig.grid_count_opacity, true);
        
        if (typeof window.refreshFolderView === 'function') {
            window.refreshFolderView();
        } else if (window.renderStandardList && typeof logs !== 'undefined') {
            window.renderStandardList(logs);
        }
    }



    window.updateStackColsUI = function(val, updateSlider = false) {
        cjosStackConfig.grid_cols = parseInt(val);
        document.documentElement.style.setProperty('--stack-cols', val);
        const label = document.getElementById('stack-cols-val');
        if(label) label.innerText = val;
        if(updateSlider && document.getElementById('stack-cols-slider')) {
            document.getElementById('stack-cols-slider').value = val;
        }
        renderStacksUI();
    };

    window.updateStackCountOpacityUI = function(val, updateSlider = false) {
        cjosStackConfig.grid_count_opacity = parseInt(val);
        document.documentElement.style.setProperty('--stack-count-opacity', val / 100);
        const label = document.getElementById('stack-count-opacity-val');
        if(label) label.innerText = val + '%';
        if(updateSlider && document.getElementById('stack-count-opacity-slider')) {
            document.getElementById('stack-count-opacity-slider').value = val;
        }
    };

    window.saveStacksToServer = async function(silent = true) {
        const payload = {
            stacks: cjosStacks,
            spacing: cjosStackConfig.spacing,
            gap: cjosStackConfig.gap,
            footer_margin: cjosStackConfig.footer_margin,
            theme: cjosStackConfig.theme,
            layout_mode: cjosStackConfig.layout_mode,
            grid_cols: cjosStackConfig.grid_cols,
            grid_count_opacity: cjosStackConfig.grid_count_opacity
        };
        await window.sui.api('stacks_save', { payload: payload }, { toast: silent ? false : 'Settings Saved Successfully' });
    }

    function injectStackActionBtn() {
        const bar = document.querySelector('.sb-scroll-container') || document.querySelector('.selection-bottom-bar');
        if (bar && !document.getElementById('action-stack')) {
            const btn = document.createElement('button');
            btn.className = 'bar-action-btn';
            btn.id = 'action-stack';
            btn.title = 'Create Stack';
            btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="4" y="4" width="16" height="16" rx="2"/><line x1="4" y1="10" x2="20" y2="10"/><line x1="4" y1="14" x2="20" y2="14"/></svg>`;
            btn.onclick = initiateStackAction;
            bar.appendChild(btn);
        }
    }

    // 4. CORE ACTIONS

    async function initiateStackAction() {
        const items = typeof getSelectedItems === 'function' ? getSelectedItems() : [];
        if (items.length === 0) return;

        const activeFolderId = (typeof currentFolderId !== 'undefined') ? currentFolderId : 0;
        const relevantStacks = cjosStacks.filter(s => s.folder_id == activeFolderId);

        if (relevantStacks.length > 0 && window.openPicker) {
            const options = [
                { label: "+ Create New Stack", value: "new" }
            ];
            relevantStacks.forEach(s => {
                const allIn = items.every(item => s.ids.includes(item.id));
                const someIn = items.some(item => s.ids.includes(item.id));
                const label = allIn ? "Remove from: " : (someIn ? "Update: " : "Add to: ");
                options.push({ label: label + s.name, value: s.id });
            });

            window.openPicker("Stack Action", options, null, (val) => {
                if (val === "new") {
                    if (items.length < 2) { 
                        window.openConfirm("Stacking Error", "Please select at least 2 items to create a new stack.", null, true, "OK", null);
                        return; 
                    }
                    createStackFromSelection(items);
                } else {
                    addSelectionToExistingStack(val, items);
                }
            });
        } else {
            if (items.length < 2) { 
                window.openConfirm("Stacking Error", "Please select at least 2 items to create a stack.", null, true, "OK", null);
                return; 
            }
            createStackFromSelection(items);
        }
    }

    async function addSelectionToExistingStack(stackId, items) {
        const stack = cjosStacks.find(s => s.id === stackId);
        if (!stack) return;

        const allIn = items.every(item => stack.ids.includes(item.id));
        _stacksBusy = true;
        if (typeof cjosToggleSelectMode === 'function') cjosToggleSelectMode(false);
        await new Promise(r => setTimeout(r, 400));

        // 1. Implode cards
        items.forEach((item, index) => {
            const cb = document.querySelector(`.custom-checkbox[data-id="${item.id}"]`);
            if (cb) {
                const card = cb.closest('.card');
                card.style.setProperty('--start-height', card.offsetHeight + 'px');
                card.style.animationDelay = (index * 0.05) + 's';
                card.classList.add('stack-card-implode');
            }
        });

        await new Promise(r => setTimeout(r, 900));

        // 2. Update Data (Toggle Logic)
        items.forEach(item => {
            if (allIn) {
                stack.ids = stack.ids.filter(id => id !== item.id);
            } else {
                // EXCLUSIVE MEMBERSHIP: Remove from all other stacks first
                cjosStacks.forEach(otherStack => {
                    if (otherStack.id !== stackId) {
                        otherStack.ids = otherStack.ids.filter(id => id !== item.id);
                    }
                });
                if (!stack.ids.includes(item.id)) stack.ids.push(item.id);
            }
        });

        _stacksBusy = false;
        window._stacksInstantUpdate = true;

        if (allIn) {
            // REMOVING: Full refresh needed to bring cards back to stream
            if (typeof window.refreshFolderView === 'function') {
                window.refreshFolderView();
            } else if (window.renderStandardList) {
                window.renderStandardList(logs);
            }
        } else {
            // ADDING: Surgical refresh. Cards are already imploded/hidden.
            // Just update the stack pile visuals.
            renderStacksUI();
        }

        // 3. Materialize in main list if removed
        if (allIn) {
            setTimeout(() => {
                items.forEach((item, index) => {
                    const cb = document.querySelector(`.custom-checkbox[data-id="${item.id}"]`);
                    if (cb) {
                        const card = cb.closest('.card');
                        card.classList.remove('is-stacked-hidden');
                        card.style.display = 'block';
                        card.style.opacity = '0';
                        requestAnimationFrame(() => {
                            card.classList.add('stack-card-return');
                            setTimeout(() => {
                                card.classList.remove('stack-card-return');
                                card.style.opacity = '';
                            }, 1050);
                        });
                    }
                });
            }, 100);
        }

        window._stacksInstantUpdate = false;
        saveStacksToServer();
    }

    function createStackFromSelection(items) {
        window.openInput("Create Stack", "Enter stack name", "New Stack", async (name) => {
            if (!name) return;

            _stacksBusy = true;

        // --- PHASE 1: UI CLEANUP ---
        // Exit selection mode first so checkboxes slide away before we start the heavy lifting
        if (typeof cjosToggleSelectMode === 'function') cjosToggleSelectMode(false);
        
        // Wait for selection bar and side-drawers to clear (400ms matches CSS transition)
        await new Promise(r => setTimeout(r, 400));

        // --- PHASE 2: CINEMATIC VANISH ---
        const scrollEl = document.getElementById('main-scroll');
        let anchorId = null;
        let initialAnchorTop = 0;

        // 1. Footing Lock: Identify a stable card ID to anchor to
        if (scrollEl) {
            const allCards = Array.from(document.querySelectorAll('.card'));
            const stackedIds = items.map(i => i.id);
            const stableCard = allCards.find(c => {
                const id = c.querySelector('.custom-checkbox')?.getAttribute('data-id');
                return !stackedIds.includes(id) && c.getBoundingClientRect().top > 0;
            });

            if (stableCard) {
                anchorId = stableCard.querySelector('.custom-checkbox').getAttribute('data-id');
                initialAnchorTop = stableCard.getBoundingClientRect().top;
                
                // Start the Long-Term Compensation Loop (Covers Vanish + Expansion)
                const startTime = performance.now();
                const compensate = () => {
                    // Re-locate the anchor element by ID (survives re-renders)
                    const cb = document.querySelector(`.custom-checkbox[data-id="${anchorId}"]`);
                    const currentAnchor = cb ? cb.closest('.card') : null;

                    if (currentAnchor) {
                        const currentTop = currentAnchor.getBoundingClientRect().top;
                        const diff = currentTop - initialAnchorTop;
                        if (Math.abs(diff) > 0.5) {
                            scrollEl.scrollTop += diff;
                        }
                    }
                    
                    // Run for 2.5s to cover the entire creation sequence
                    if (performance.now() - startTime < 2500) requestAnimationFrame(compensate);
                };
                requestAnimationFrame(compensate);
            }
        }

        items.forEach((item, index) => {
            const cb = document.querySelector(`.custom-checkbox[data-id="${item.id}"]`);
            if (cb) {
                const card = cb.closest('.card');
                // Set the specific start height for the CSS animation to use
                card.style.setProperty('--start-height', card.offsetHeight + 'px');
                // Apply class immediately with animation-delay property for smoother GPU scheduling
                card.style.animationDelay = (index * 0.05) + 's';
                card.classList.add('stack-card-implode');
            }
        });

        // Wait for cards to vanish and list to slide up (850ms anim + buffer)
        await new Promise(r => setTimeout(r, 950));

        // --- PHASE 3: GEOMETRIC EXPANSION ---
        const itemIds = items.map(i => i.id);

        // EXCLUSIVE MEMBERSHIP: Remove these IDs from any existing stacks
        cjosStacks.forEach(s => {
            s.ids = s.ids.filter(id => !itemIds.includes(id));
        });

        const newStack = {
            id: 'stack_' + Date.now(),
            name: name,
            ids: itemIds,
            is_brand_new: true, 
            folder_id: (typeof currentFolderId !== 'undefined' && currentFolderId !== null) ? currentFolderId : 0
        };

        cjosStacks.push(newStack);
        _stacksBusy = false;

        // Render the new stack pile, which triggers its own 'slide down' expansion animation
        if (typeof window.refreshFolderView === 'function') {
            window.refreshFolderView();
        } else if (window.renderStandardList && typeof logs !== 'undefined') {
            window.renderStandardList(logs);
        }

        saveStacksToServer();
        });
    }

    window.unstackAll = function() {
        window.openConfirm("Dissolve All Stacks", "This will return all stacked notes to the main list. Continue?", async () => {
            cjosStacks = [];
            await saveStacksToServer();
            location.reload();
        }, true);
    };

    // 5. RENDERER

    function renderStacksUI() {
        const container = document.getElementById('entries-container');
        if (!container) return;

        _stacksBusy = true;

        // 1. Validate Folder & Existence Integrity
        validateStacksAgainstFolders();

        // --- NON-DESTRUCTIVE UPDATE ---
        const oldWrapper = document.getElementById('stacks-section-wrapper');

        // 2. Filter stacks for current folder context
        const activeFolderId = (typeof currentFolderId !== 'undefined') ? currentFolderId : null;
        const relevantStacks = cjosStacks.filter(s => s.folder_id == activeFolderId);

        if (relevantStacks.length > 0) {
            // 3. Create a master wrapper for the stacks section
            const stacksSection = document.createElement('div');
            stacksSection.id = 'stacks-section-wrapper';
            
            // Use persistent config instead of reading from DOM
            if(cjosStackConfig.layout_mode === 'grid') stacksSection.classList.add('mode-grid');

            // If every stack in this section is currently animating away, collapse the wrapper footprint too
            const isLastSectionExit = relevantStacks.every(s => s.is_annihilating);
            if (isLastSectionExit) {
                stacksSection.classList.add('section-vanishing');
            }

            relevantStacks.forEach(stack => {
                if (stack.ids.length === 0 && !stack.is_annihilating) return;

                // PERSISTENCE GUARD: If the element is already in the DOM and animating, don't recreate it
                const existing = document.getElementById(`stack-container-${stack.id}`);
                if (existing && stack.is_annihilating) {
                    stacksSection.appendChild(existing);
                    return;
                }

                if (expandedStackId === stack.id) {
                    renderExpandedStack(stack, stacksSection);
                } else {
                    const pile = renderCollapsedStack(stack, stacksSection);
                    // If another stack is expanded, hide this pile smoothly
                    if (expandedStackId && expandedStackId !== stack.id) {
                        pile.classList.add('stack-hidden-by-focus');
                    }
                }
            });

            if (oldWrapper) {
                oldWrapper.replaceWith(stacksSection);
            } else {
                container.prepend(stacksSection);
            }
        } else if (oldWrapper) {
            oldWrapper.remove();
        }

        // 4. Validate Folder Integrity (Remove moved items, Dissolve empty stacks)
        validateStacksAgainstFolders();

        // Aggressive Cleanup: Ensure stacked items are hidden in the main list
        enforceStackHiding();

        // --- RELEASE LAYOUT GUARD ---
        requestAnimationFrame(() => {
            container.style.minHeight = '';
        });

        _stacksBusy = false;
    }

    function validateStacksAgainstFolders() {
        if (typeof so_map === 'undefined' || typeof logs === 'undefined') return;
        
        let changed = false;
        const originalCount = cjosStacks.length;
        const stacksToPurge = [];

        cjosStacks = cjosStacks.filter(stack => {
            // SHIELD: If already animating, don't validate or remove it yet
            if (stack.is_annihilating) return true;

            const oldLength = stack.ids.length;
            const stackFid = (stack.folder_id === null || stack.folder_id === undefined) ? 0 : stack.folder_id;
            
            stack.ids = stack.ids.filter(id => {
                const exists = logs.some(l => l.id === id);
                if (!exists) return false;
                const cardFid = so_map[id] || 0;
                return cardFid == stackFid;
            });
            
            if (stack.ids.length !== oldLength) changed = true;

            if (stack.ids.length === 0) {
                stacksToPurge.push(stack.id);
                return true; 
            }
            return true;
        });

        // Handle Cinematic Exits for empty stacks
        stacksToPurge.forEach(sid => {
            const stack = cjosStacks.find(s => s.id === sid);
            if (!stack || stack.is_annihilating) return;

            // Target specific container ID
            const el = document.getElementById(`stack-container-${sid}`);
            if (el) {
                stack.is_annihilating = true;
                // Capture height for smooth collapse
                el.style.setProperty('--exit-height', el.offsetHeight + 'px');
                el.classList.add('stack-exit-cinematic');
                
                setTimeout(() => {
                    cjosStacks = cjosStacks.filter(s => s.id !== sid);
                    saveStacksToServer();
                    // Clear expanded state ONLY after visual removal
                    if (expandedStackId === sid) expandedStackId = null;
                    _stacksBusy = false;
                    renderStacksUI();
                }, 1150); // Matches the new 1.1s duration + buffer
            } else {
                cjosStacks = cjosStacks.filter(s => s.id !== sid);
            }
        });

        if (cjosStacks.length !== originalCount) changed = true;

        if (changed) {
            saveStacksToServer();
            if (expandedStackId && !cjosStacks.find(s => s.id === expandedStackId)) {
                expandedStackId = null;
            }
        }
    }

    function enforceStackHiding() {
        // Gather all IDs currently in stacks
        const allStackedIds = cjosStacks.reduce((acc, s) => acc.concat(s.ids), []);
        
        // Find all cards in the main list (excluding the stack wrapper itself)
        // We target the custom-checkbox to get the ID, then hide the parent card
        allStackedIds.forEach(id => {
            const checkboxes = document.querySelectorAll(`.custom-checkbox[data-id="${id}"]`);
            checkboxes.forEach(cb => {
                const card = cb.closest('.card');
                if (card) {
                    // If inside the expanded wrapper, ensure visible. If outside (stream), ensure hidden.
                    if (card.closest('.expanded-stack-wrapper')) {
                        card.classList.remove('is-stacked-hidden');
                    } else {
                        card.classList.add('is-stacked-hidden');
                    }
                }
            });
        });
    }



    function renderCollapsedStack(stack, container) {
        const pile = document.createElement('div');
        pile.id = `stack-container-${stack.id}`;
        pile.className = 'stack-pile-container';
        if (stack.is_annihilating) pile.classList.add('stack-exit-cinematic');
        
        // GHOST CLICK PROTECTION: Record birth time
        const birthTime = Date.now();
        
        // Fetch snippets from the top three cards
        const snippets = stack.ids.slice(0, 3).map(id => {
            const entry = logs.find(l => l.id === id);
            return entry ? entry.transcription.trim().split('\n')[0] : "";
        }).filter(s => s !== "");

        let previewHtml = snippets.map(s => `<div class="stack-preview-line">${s}</div>`).join('');

        if (stack.is_brand_new && !window._stacksInstantUpdate) {
            // Start Phase 1: Expand the gap (The Wedge)
            pile.classList.add('stack-expanding-gap');
            
            // Start Phase 2: Materialize visuals once gap is open
            setTimeout(() => {
                const el = document.getElementById(`stack-container-${stack.id}`);
                if (el) {
                    el.classList.remove('stack-expanding-gap');
                    el.classList.add('stack-materializing');
                    el.style.maxHeight = '200px';
                    el.style.marginBottom = ''; // Revert to CSS variable in class
                    el.style.paddingTop = '10px';
                }
            }, 550); // Just before the 0.6s expansion finishes

            // Finalize
            setTimeout(() => {
                delete stack.is_brand_new;
                const el = document.getElementById(`stack-container-${stack.id}`);
                if (el) {
                    el.classList.remove('stack-materializing');
                    el.classList.add('entrance-complete');
                }
            }, 1400);
        }

        pile.innerHTML = `
            <div class="stack-visual-card"></div>
            <div class="stack-visual-card"></div>
            <div class="stack-visual-card">
                <div class="stack-info-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <div class="stack-title-text">${stack.name}</div>
                    <div class="stack-label-pill">${stack.ids.length} Items</div>
                </div>
                <div class="stack-preview-area" style="display:flex; flex-direction:column;">${previewHtml}</div>
            </div>
        `;

        pile.onclick = (e) => {
            if (Date.now() - birthTime < 400) return;
            
            window.expandedStackId = stack.id;

            // Push History State for Back Override
            if (typeof aboEnabled !== "undefined" && aboEnabled) {
                history.pushState({ stack_open: true }, null, window.location.href);
            }

            renderStacksUI();
            
            // Find the newly rendered expanded wrapper and align it to the top
            const wrapper = container.querySelector('.expanded-stack-wrapper');
            if (wrapper) {
                wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };

        container.prepend(pile);
        if (window.srWatch && !stack.is_brand_new) window.srWatch(pile);
        return pile;
    }

    window.closeActiveStack = function(wrapperEl) {
        if (!wrapperEl && window.expandedStackId) {
            wrapperEl = document.getElementById(`stack-container-${window.expandedStackId}`);
        }
        if (!wrapperEl || wrapperEl.classList.contains('stack-collapsing')) return;
        
        // 1. Lock current height for smooth collapse
        wrapperEl.style.maxHeight = wrapperEl.offsetHeight + 'px';
        
        // 2. Trigger animation
        requestAnimationFrame(() => {
            wrapperEl.classList.add('stack-collapsing');
        });

        // 3. Swap DOM after animation completes
        setTimeout(() => {
            expandedStackId = null;
            renderStacksUI();
        }, 450); 
    }

    function renderExpandedStack(stack, container) {
        const wrapper = document.createElement('div');
        wrapper.id = `stack-container-${stack.id}`;
        let wrapperClass = 'expanded-stack-wrapper';
        if (window._stacksInstantUpdate) wrapperClass += ' no-entrance-anim';
        if (activeStackEditing) wrapperClass += ' is-editing';
        wrapper.className = wrapperClass;
        if (stack.is_annihilating) wrapper.classList.add('stack-exit-cinematic');

        // --- SIDE-TAP COLLAPSE ---
        wrapper.onclick = (e) => {
            // Ignore if selecting items or clicking interactive components
            if (document.body.classList.contains('select-mode')) return;
            if (e.target.closest('button') || e.target.closest('.player-capsule') || e.target.closest('audio') || e.target.closest('.custom-checkbox') || e.target.closest('.dog-ear-zone')) return;

            const rect = wrapper.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const zone = 50; // 50px interaction zone on both sides

            if (x < zone || x > (rect.width - zone)) {
                e.stopPropagation();
                closeActiveStack(wrapper);
            }
        };

        const header = document.createElement('div');
        header.className = 'stack-header-bar';
        header.innerHTML = `
            <div class="stack-title-heading">${stack.name}</div>
            <div class="stack-corner-actions">
                <button class="stack-corner-btn btn-dissolve-stack" title="Dissolve Stack">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
                <button class="stack-corner-btn btn-move-stack" title="Move Stack to Folder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
                </button>
                <button class="stack-corner-btn btn-edit-stack" title="Edit Stack Contents">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                </button>
                <button class="stack-corner-btn btn-collapse-stack" title="Collapse">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="18 15 12 9 6 15"></polyline></svg>
                </button>
            </div>
        `;

        const btnEdit = header.querySelector('.btn-edit-stack');
        btnEdit.onclick = (e) => {
            e.stopPropagation();
            activeStackEditing = !activeStackEditing;
            wrapper.classList.toggle('is-editing');
            if (navigator.vibrate) navigator.vibrate(10);
        };

        const btnMove = header.querySelector('.btn-move-stack');
        btnMove.onclick = (e) => {
            e.stopPropagation();
            moveStackToFolder(stack, wrapper);
        };

        const titleEl = header.querySelector('.stack-title-heading');
        let titlePressTimer;
        let wasLongPress = false;

        // --- TITLE INTERACTIONS ---
        titleEl.onmousedown = titleEl.ontouchstart = (e) => {
            wasLongPress = false;
            titlePressTimer = setTimeout(() => {
                wasLongPress = true;
                if(navigator.vibrate) navigator.vibrate(50);
                window.openInput("Rename Stack", "New Name", stack.name, (newName) => {
                    if (newName && newName.trim() !== "") {
                        stack.name = newName.trim();
                        saveStacksToServer();
                        renderStacksUI();
                    }
                });
            }, 600);
        };

        titleEl.onmouseup = titleEl.onmouseleave = titleEl.ontouchend = () => {
            clearTimeout(titlePressTimer);
        };

        titleEl.onclick = (e) => {
            e.stopPropagation();
            if (wasLongPress) return;
            closeActiveStack(wrapper);
        };

        header.querySelector('.btn-collapse-stack').onclick = (e) => {
            e.stopPropagation();
            closeActiveStack(wrapper);
        };

        header.querySelector('.btn-dissolve-stack').onclick = (e) => {
            e.stopPropagation();
            window.openConfirm("Dissolve Stack", "Return these notes to the main list?", async () => {
                _stacksBusy = true;
            const idsToReturn = [...stack.ids];
            
            // 1. Mark as annihilating to engage the Persistence Guard
            stack.is_annihilating = true;
            
            // 2. Clear IDs from the stack so they are picked up by the main list loop
            stack.ids = []; 
            
            // 3. Close selection mode if active to clear the UI
            if (typeof cjosToggleSelectMode === 'function') cjosToggleSelectMode(false);

            // 4. Trigger the cinematic exit on the wrapper
            const wrapperEl = document.getElementById(`stack-container-${stack.id}`);
            if (wrapperEl) {
                // Capture exact height to prevent layout jumps
                wrapperEl.style.setProperty('--exit-height', wrapperEl.offsetHeight + 'px');
                wrapperEl.classList.add('stack-exit-cinematic');
            }

            // 5. Re-render the main list immediately.
            // The Persistence Guard will keep the animating wrapper in place,
            // while the main loop will draw the returning cards below it.
            if (typeof window.refreshFolderView === 'function') {
                window._stacksInstantUpdate = true;
                window.refreshFolderView();
                
                // 6. Apply Materialize animation to the 'returning' cards in the main list
                idsToReturn.forEach((id, index) => {
                    const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
                    if (cb) {
                        const card = cb.closest('.card');
                        card.style.opacity = '0';
                        setTimeout(() => {
                            card.classList.add('stack-card-return');
                            setTimeout(() => {
                                card.classList.remove('stack-card-return');
                                card.style.opacity = '';
                            }, 1050);
                        }, 100 + (index * 50));
                    }
                });
                window._stacksInstantUpdate = false;
            }

            // 7. Final Data Purge after the 1.1s exit animation finishes
            setTimeout(async () => {
                cjosStacks = cjosStacks.filter(s => s.id !== stack.id);
                await saveStacksToServer();
                if (expandedStackId === stack.id) expandedStackId = null;
                _stacksBusy = false;
                // Final clean render to remove the guard-node
                if (typeof window.refreshFolderView === 'function') {
                    window.refreshFolderView();
                } else if (window.renderStandardList) {
                    window.renderStandardList(logs);
                }
            }, 1150);
            }, true);
        };

        wrapper.appendChild(header);

        // Find and sort the actual log entries for this stack
        const stackEntries = logs.filter(l => stack.ids.includes(l.id));
        // Sort them chronologically relative to each other (descending)
        stackEntries.sort((a,b) => b.timestamp - a.timestamp);

        stackEntries.forEach(entry => {
            if (window.createStandardCardDOM) {
                const card = window.createStandardCardDOM(entry);
                
                // --- INJECT REMOVE BUTTON ---
                const headerRow = card.querySelector('.header-row');
                if (headerRow) {
                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'stack-card-remove-btn';
                    removeBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4"><line x1="5" y1="12" x2="19" y2="12"></line></svg>`;
                    removeBtn.title = "Remove from Stack";
                    removeBtn.style.cssText = "background:rgba(0,0,0,0.05); border:none; width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#8E8E93; margin-left:8px; transition: all 0.2s;";
                    
                    removeBtn.onclick = (e) => {
                        e.stopPropagation();
                        removeSingleCardFromStack(stack.id, entry.id, card);
                    };
                    
                    const player = headerRow.querySelector('.player-capsule');
                    if (player) headerRow.insertBefore(removeBtn, player);
                    else headerRow.appendChild(removeBtn);
                }

                wrapper.appendChild(card);
                
                // Trigger Handshake for nested cards
                if (window.cjosPluginRegistry) {
                    window.cjosPluginRegistry.forEach(p => { try { p.fn(card, entry); } catch(e) { console.error("Stack card plugin failed", e); } });
                }
            }
        });

        container.prepend(wrapper);
        if (window.srWatch) window.srWatch(wrapper);
        
        // Instead of triggering a global refresh (which causes recursion),
        // we simply allow the existing refresh cycle to continue.
        // The cards inside are already decorated by the loop above.
    }

    async function moveStackToFolder(stack, wrapperEl) {
        if (typeof window.openFolderManager !== 'function') {
            window.openConfirm("Plugin Required", "SmartOrganizer plugin is required to move stacks.", null, false, "OK", null);
            return;
        }

        window.openFolderManager(true, "Move stack to folder...", async (targetFid) => {
            if (targetFid === null || typeof targetFid === 'undefined') return;
            
            _stacksBusy = true;
            const ids = [...stack.ids];

            // 1. Visual Feedback: Start the cinematic exit
            stack.is_annihilating = true;
            wrapperEl.style.setProperty('--exit-height', wrapperEl.offsetHeight + 'px');
            wrapperEl.classList.add('stack-exit-cinematic');

            // 2. Update Local State (Directly modifying SmartOrganizer globals)
            stack.folder_id = targetFid;
            ids.forEach(id => {
                if (targetFid == 0) delete so_map[id];
                else so_map[id] = targetFid;
            });

            // 3. Sync Server: Cards (SmartOrganizer Endpoint)
            await window.sui.api("folder_assign", { folder_id: targetFid, log_ids: ids }, { toast: false });

            // 4. Sync Server: Stack Metadata
            await saveStacksToServer();

            // 5. Cleanup UI
            setTimeout(() => {
                expandedStackId = null;
                activeStackEditing = false;
                stack.is_annihilating = false;
                _stacksBusy = false;
                if (typeof window.refreshFolderView === 'function') {
                    window.refreshFolderView();
                } else if (window.renderStandardList) {
                    window.renderStandardList(logs);
                }
                
                if (window.sui && window.sui.toast) {
    window.sui.toast("Stack Moved", { plugin: "Stacks", caller: "handleStackMove", metrics: { target_folder: targetFid, count: ids.length } });
}}, 1150);
        });
    }

    async function removeSingleCardFromStack(stackId, logId, cardEl) {
        const stack = cjosStacks.find(s => s.id === stackId);
        if (!stack) return;

        _stacksBusy = true;

        // 1. Vanish animation within the stack
        cardEl.style.setProperty('--start-height', cardEl.offsetHeight + 'px');
        cardEl.classList.add('stack-card-implode');

        // Wait for the implode to finish before touching data
        await new Promise(r => setTimeout(r, 850));

        // 2. Update Data and Persistence
        stack.ids = stack.ids.filter(id => id !== logId);
        saveStacksToServer();

        // 3. Re-render list with 'Instant' flag to prevent stack spasms
        window._stacksInstantUpdate = true;
        if (typeof window.refreshFolderView === 'function') {
            window.refreshFolderView();
        } else if (window.renderStandardList) {
            window.renderStandardList(logs);
        }

        if (true) {
            // 4. Materialize in main list (The Return)
            // Delay ensures the DOM is fully painted so the transition can trigger
            setTimeout(() => {
                const cb = document.querySelector(`.custom-checkbox[data-id="${logId}"]`);
                if (cb) {
                    const card = cb.closest('.card');
                    // Force display and remove any residual hidden classes
                    card.classList.remove('is-stacked-hidden');
                    card.style.display = 'block';
                    card.style.opacity = '0';
                    
                    requestAnimationFrame(() => {
                        card.classList.add('stack-card-return');
                        setTimeout(() => {
                            // CLEANUP: Remove the animation class and the inline opacity lock
                            card.classList.remove('stack-card-return');
                            card.style.opacity = '';
                        }, 1050);
                    });
                }
            }, 100);
        }

        window._stacksInstantUpdate = false;
        _stacksBusy = false;
    }

})();
JS;
?>