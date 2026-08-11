<?php
// ==============================================================================
// PLUGIN: Floating Recorder
// DESCRIPTION: Record Button & Page Swiping.
// Manages the FAB, Recording Logic, and Audio Sound Settings.
// ==============================================================================

// --- CONFIGURATION ---
$fr_config_file = CJOS_PATH_DATA . '/floating-recorder-config.json';
$fr_config_raw = file_exists($fr_config_file) ? json_decode(file_get_contents($fr_config_file), true) : [];
$fr_init_mode = $fr_config_raw['ui_mode'] ?? 'fab';
$fr_init_classes = [];
if ($fr_init_mode === 'bar') $fr_init_classes[] = 'fcb-mode';
if ($fr_config_raw['fcb_docked'] ?? false) $fr_init_classes[] = 'fcb-docked';
if ($fr_config_raw['fcb_omnibutton'] ?? false) $fr_init_classes[] = 'fcb-omni';
if ($fr_config_raw['fcb_hide_left'] ?? false) $fr_init_classes[] = 'fcb-hide-left';

$fr_class_js = count($fr_init_classes) ? "document.body.classList.add('".implode("','", $fr_init_classes)."');" : "";
$fr_hide_fab_css = ($fr_init_mode === 'bar' ? 'body.fcb-mode #fab-record { display: none !important; }' : '');

// Inject immediate overrides to prevent UI flash
$plugin_overlays[] = "<style>$fr_hide_fab_css</style><script>(function(){ $fr_class_js })();</script>";

if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'fr_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults =['travel_sensitivity' => 22, 'reverse_travel' => false, 'reverse_action_travel' => false, 'stop_at_dashboard' => false, 'menu_distance' => 100, 'back_x' => 45, 'back_y' => 32, 'back_gesture_dist' => 160, 'gap' => 16, 'debug' => false, 'ui_mode' => 'fab', 'fcb_docked' => false, 'fcb_omnibutton' => false, 'fcb_hide_left' => false, 'fcb_bottom_offset' => 24, 'fcb_dial_offset' => 100, 'fcb_single_tap_exit' => false, 'fcb_double_tap_exit' => false];$config = file_exists($fr_config_file) ? json_decode(file_get_contents($fr_config_file), true) : $defaults;
        echo json_encode(['status' => 'success', 'config' => $config]);
        exit;
    }
    if ($_POST['plugin_action'] === 'fr_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $settings = $_POST['settings'] ?? [];
        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?: [];
        }
        if (!is_array($settings)) {
            $settings = [];
        }
        file_put_contents($fr_config_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// 1. SETTINGS UI
$plugin_settings_map['FloatingRecorder'] = <<<'HTML'
<div id="fr-tray-anchor">
    <div id="fr-gui-root">
    <!-- 1. AUDIO & SOUNDS -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); cursor:pointer; margin-bottom:8px;" onclick="suiToggle('fr-acc-audio')">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Audio & Sounds</div>
        <span data-sui-icon="chevron" data-sui-arrow="fr-acc-audio" data-sui-size="14" style="transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>
    <div id="fr-acc-audio" class="sui-accordion" style="display:none;">
        <div class="sui-accordion-inner">
            <div class="setting-item vertical">
                <label class="setting-label">Start Sound URL</label>
                <input type="text" id="fr-sound-start" placeholder="Built-in or path/to/mp3" onchange="frSaveSettings()">
            </div>
            <div class="setting-item vertical">
                <label class="setting-label">Stop Sound URL</label>
                <input type="text" id="fr-sound-stop" placeholder="Built-in or path/to/mp3" onchange="frSaveSettings()">
            </div>
            <div style="padding:0 16px 12px; font-size:11px; color:var(--text-secondary); opacity:0.7;">Sound settings sync to backend-config.json</div>
        </div>
    </div>

    <!-- 1.5 UI MODE (FAB vs COMMAND BAR) -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); cursor:pointer; margin-bottom:8px;" onclick="suiToggle('fr-acc-uimode')">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Interface Mode</div>
        <span data-sui-icon="chevron" data-sui-arrow="fr-acc-uimode" data-sui-size="14" style="transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>
    <div id="fr-acc-uimode" class="sui-accordion" style="display:none;">
        <div class="sui-accordion-inner">
            <div class="setting-item vertical">
                <label class="setting-label">Layout Style</label>
                <div class="setting-desc">Choose between the minimal Floating Action Button (FAB) or the horizontal Command Bar.</div>
                <select id="fr-ui-mode" onchange="frUpdateUiMode(this.value)" style="width:100%; padding:10px; border-radius:10px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-primary); margin-top:8px;">
                    <option value="fab">Minimal FAB (Gesture Based)</option>
                    <option value="bar">Command Bar (Horizontal Strip)</option>
                </select>
            </div>
            <div data-sui-setting="Dock Command Bar" data-sui-desc="Pin the Command Bar to the absolute bottom edge of the screen." data-sui-id="fr-fcb-docked" data-sui-onchange="frToggleFcbDocked(this.checked)"></div>
            <div data-sui-setting="Omnibutton Mode" data-sui-desc="Combine Record and Back into a single gesture-based button (Command Bar only)." data-sui-id="fr-fcb-omnibutton" data-sui-onchange="frToggleOmnibutton(this.checked)"></div>
            <div data-sui-setting="Hide Recorder Buttons" data-sui-desc="Remove the left recorder section, showing only navigation and actions." data-sui-id="fr-fcb-hide-left" data-sui-onchange="frToggleHideLeft(this.checked)"></div>
            <div data-sui-setting="Single Tap Exit" data-sui-desc="Single tapping a page button will close all open overlays to show the page." data-sui-id="fr-fcb-single-tap-exit" data-sui-onchange="frToggleSingleTapExit(this.checked)"></div>
            <div data-sui-setting="Double Tap Exit" data-sui-desc="Double tapping a page button will close all open overlays to show the page." data-sui-id="fr-fcb-double-tap-exit" data-sui-onchange="frToggleDoubleTapExit(this.checked)"></div>
            <div class="setting-item vertical">
                <label class="setting-label">Floating Bar Offset</label>
                <div class="setting-desc">Vertical distance from the bottom edge (Floating mode only).</div>
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="range" id="fr-fcb-offset" min="0" max="200" step="2" oninput="frUpdateFcbOffset(this.value)" onchange="frSaveExtraConfig()" style="flex:1;">
                    <span id="fr-fcb-offset-val" style="font-weight:700; color:var(--primary); min-width:40px;">24px</span>
                </div>
            </div>
            <div class="setting-item vertical">
                <label class="setting-label">Fast Travel Dial Offset</label>
                <div class="setting-desc">Vertical distance between the dial and your finger.</div>
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="range" id="fr-fcb-dial-offset" min="40" max="300" step="5" oninput="frUpdateDialOffset(this.value)" onchange="frSaveExtraConfig()" style="flex:1;">
                    <span id="fr-fcb-dial-offset-val" style="font-weight:700; color:var(--primary); min-width:40px;">100px</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. PAGE NAVIGATION -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); cursor:pointer; margin-bottom:8px;" onclick="suiToggle('fr-acc-nav')">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Page Navigation</div>
        <span data-sui-icon="chevron" data-sui-arrow="fr-acc-nav" data-sui-size="14" style="transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>
    <div id="fr-acc-nav" class="sui-accordion" style="display:none;">
        <div class="sui-accordion-inner">
            <div class="setting-item vertical">
                <label class="setting-label">Fast Travel Sensitivity</label>
                <div class="setting-desc">Thumb distance required to sweep all pages. Lower is faster.</div>
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="range" id="fr-travel-sensitivity" min="5" max="100" step="1" oninput="frUpdateSensitivityLabel(this.value)" onchange="frSaveExtraConfig()" style="flex:1;">
                    <span id="fr-travel-sensitivity-val" style="font-weight:700; color:var(--primary); min-width:40px;">22%</span>
                </div>
            </div>
            <div data-sui-setting="Reverse Page Swipe" data-sui-desc="Flip the axis: Slide right to move to previous pages." data-sui-id="fr-reverse-travel" data-sui-onchange="frToggleReverse(this.checked)"></div>
            <div data-sui-setting="Reverse Action Swipe" data-sui-desc="Flip the axis for the Command Bar's action strip." data-sui-id="fr-reverse-action-travel" data-sui-onchange="frToggleActionReverse(this.checked)"></div>
            <div data-sui-setting="Dashboard Snap-Stop" data-sui-desc="Stop automatically at the Dashboard when swiping back." data-sui-id="fr-stop-dash" data-sui-onchange="frToggleStopDash(this.checked)"></div>
        </div>
    </div>

    <!-- 3. ACTION MENU -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); cursor:pointer; margin-bottom:8px;" onclick="suiToggle('fr-acc-menu')">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Quick Action Menu</div>
        <span data-sui-icon="chevron" data-sui-arrow="fr-acc-menu" data-sui-size="14" style="transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>
    <div id="fr-acc-menu" class="sui-accordion" style="display:none;">
        <div class="sui-accordion-inner">
            <div style="padding: 12px 16px 0 16px; font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing:1px; text-align:center;">Visual Layout (3x3 Grid)</div>
            <div id="fr-settings-grid" class="fr-grid-container"></div>

            <div style="padding: 0 16px 8px 16px; font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing:1px; text-align:center;">Unassigned Actions</div>
            <div id="fr-settings-bench" style="display:flex; flex-wrap:wrap; gap:8px; padding:0 16px 16px 16px; justify-content:center;"></div>
            
            <div class="setting-item vertical" style="border-top:1px solid var(--border-color); padding-top:12px;">
                <label class="setting-label">Tier Vertical Spread</label>
                <div class="setting-desc">Vertical gap between the three action tiers.</div>
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="range" id="fr-tier-spread" min="80" max="200" step="2" oninput="frUpdateSpreadUI(this.value)" onchange="frSaveExtraConfig()" style="flex:1;">
                    <span id="fr-tier-spread-val" style="font-weight:700; color:var(--primary); min-width:40px;">80px</span>
                </div>
            </div>
            <div class="setting-item vertical">
                <label class="setting-label">Menu Vertical Distance</label>
                <div class="setting-desc">Distance between the FAB and the action menu.</div>
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="range" id="fr-menu-dist" min="50" max="250" step="5" oninput="frUpdateMenuDist(this.value)" onchange="frSaveExtraConfig()" style="flex:1;">
                    <span id="fr-menu-dist-val" style="font-weight:700; color:var(--primary); min-width:40px;">100px</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. GLOBAL BACK UI -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); cursor:pointer; margin-bottom:16px;" onclick="suiToggle('fr-acc-back')">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Global Back & Safe Zone</div>
        <span data-sui-icon="chevron" data-sui-arrow="fr-acc-back" data-sui-size="14" style="transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>
    <div id="fr-acc-back" class="sui-accordion" style="display:none;">
        <div class="sui-accordion-inner">
            <div class="setting-item vertical">
                <label class="setting-label">Back Button X-Position</label>
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="range" id="fr-back-x" min="10" max="150" step="1" oninput="frUpdateBackX(this.value)" onchange="frSaveExtraConfig()" style="flex:1;">
                    <span id="fr-back-x-val" style="font-weight:700; color:var(--primary); min-width:40px;">45px</span>
                </div>
            </div>
            <div class="setting-item vertical">
                <label class="setting-label">Back Button Y-Position</label>
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="range" id="fr-back-y" min="10" max="150" step="1" oninput="frUpdateBackY(this.value)" onchange="frSaveExtraConfig()" style="flex:1;">
                    <span id="fr-back-y-val" style="font-weight:700; color:var(--primary); min-width:40px;">32px</span>
                </div>
            </div>
            <div class="setting-item vertical">
                <label class="setting-label">Back Mode Gesture Height</label>
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="range" id="fr-back-gesture-dist" min="80" max="350" step="5" oninput="frUpdateBackGestureDist(this.value)" onchange="frSaveExtraConfig()" style="flex:1;">
                    <span id="fr-back-gesture-dist-val" style="font-weight:700; color:var(--primary); min-width:40px;">160px</span>
                </div>
            </div>
            <div class="setting-item vertical">
                <label class="setting-label">Content Push Clearance</label>
                <div class="setting-desc">Additional breathing room to push page content above the recorder/bar.</div>
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="range" id="fr-gap" min="0" max="250" step="2" oninput="frUpdateGapUI(this.value)" onchange="frSaveExtraConfig()" style="flex:1;">
                    <span id="fr-gap-val" style="font-weight:700; color:var(--primary); min-width:40px;">16px</span>
                </div>
            </div>
            <div data-sui-setting="Visualize Safe Zone" data-sui-desc="Show red overlay for debugging." data-sui-id="fr-debug-toggle" data-sui-onchange="frToggleDebug(this.checked)"></div>
            <div class="setting-item vertical" style="border-top:1px solid var(--border-color); margin-top:8px; padding-top:16px;">
                <label class="setting-label">Magic Back URL</label>
                <div class="setting-desc">Copy this into your browser's "Back Gesture URL" setting to hijack the Android back button.</div>
                <div style="display:flex; gap:8px; margin-top:8px;">
                    <input type="text" id="fr-magic-back-url" readonly style="flex:1; font-family:monospace; font-size:12px;" onclick="this.select()">
                    <button onclick="navigator.clipboard.writeText(document.getElementById('fr-magic-back-url').value); window.sui.haptic('success');" class="text-btn" style="background:var(--btn-bg); padding:0 12px; border-radius:8px; font-size:11px; font-weight:700;">Copy</button>
                </div>
            </div>
        </div>
    </div>
    </div> <!-- /fr-gui-root -->
</div> <!-- /fr-tray-anchor -->
HTML;

// 2. OVERLAYS (The FAB)
$plugin_overlays[] = <<<'HTML'
<style>
    .fr-action-overlay {
        position: fixed; bottom: calc(var(--fab-size) + var(--fr-menu-dist, 100px) + var(--fab-bottom-offset, 0px));
        left: 50%; transform: translateX(-50%) translateY(20px);
        width: 260px; height: 240px; z-index: 12000;
        opacity: 0; pointer-events: none; visibility: hidden;
        /* Removed opacity transition to prevent backdrop-filter pop-in lag */
        transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275), visibility 0.3s;
    }
    .fr-action-overlay.visible { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
    
    .fr-action-zone {
        position: absolute;
        width: 64px; height: 64px; border-radius: 50%;
        background: var(--glass-bg); 
        backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
        border: 1px solid var(--glass-border);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        box-shadow: var(--shadow-floating); color: var(--text-secondary);
        /* Prime the GPU for the blur effect */
        will-change: backdrop-filter, transform;
        transform: translateZ(0);
        backface-visibility: hidden;
        transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275), 
                    opacity 0.2s ease, 
                    box-shadow 0.2s ease, 
                    color 0.2s ease;
    }
    .fr-action-zone svg { width: 24px; height: 24px; margin-bottom: 2px; }
    .fr-action-zone span { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
    .fr-action-zone.active {
        transform: scale(1.25); 
        background: color-mix(in srgb, var(--primary), transparent 40%) !important; 
        color: var(--primary-text) !important;
        border-color: rgba(255,255,255,0.5) !important; 
        box-shadow: 0 8px 30px color-mix(in srgb, var(--primary), transparent 60%) !important;
    }
    /* Preserve centering for middle column during magnification */
    .fr-action-zone.active.fr-col-center {
        transform: translateX(-50%) scale(1.25);
    }
    /* Specific Red Tint for Secure Action on Hover/Active gesture (Only if pending) */
    #fr-zone-checkpoint.el-pending.active,
    #fr-zone-save_snapshot.el-pending.active {
        background: var(--danger) !important;
        color: white !important;
        border-color: rgba(255,255,255,0.4) !important;
        box-shadow: 0 8px 30px rgba(255, 59, 48, 0.5) !important;
    }
    /* Positional Classes */
    .fr-col-left { left: 0; }
    .fr-col-center { left: 50%; transform: translateX(-50%); }
    .fr-col-right { right: 0; }

    .fr-tier-1 { bottom: 0; }
    .fr-tier-2 { 
        bottom: var(--fr-tier-spread, 80px); transform: scale(0.8); opacity: 0; pointer-events: none;
        transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .fr-tier-2.fr-col-center { transform: translateX(-50%) scale(0.8); }
    .fr-tier-3 { 
        bottom: calc(var(--fr-tier-spread, 80px) * 2); transform: scale(0.8); opacity: 0; pointer-events: none;
        transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .fr-tier-3.fr-col-center { transform: translateX(-50%) scale(0.8); }

    .fr-tier-2.unlocked, .fr-tier-3.unlocked { opacity: 1; pointer-events: auto; }
    .fr-tier-2.unlocked { transform: scale(1); }
    .fr-tier-2.fr-col-center.unlocked { transform: translateX(-50%) scale(1); }
    .fr-tier-3.unlocked { transform: scale(1); }
    .fr-tier-3.fr-col-center.unlocked { transform: translateX(-50%) scale(1); }

    .fr-action-tooltip {
        position: absolute; 
        /* Tier 1: 126px, Tier 2: 46px, Tier 3: -34px (Relative to bottom-aligned container) */
        top: var(--tooltip-y, 126px); 
        left: 50%;
        transform: translateX(-50%) translateY(10px);
        background: var(--glass-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
        border: 1px solid var(--glass-border); color: var(--text-primary);
        padding: 8px 18px; border-radius: 24px; font-size: 13px; font-weight: 800;
        white-space: nowrap; opacity: 0;
        transition: all 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        box-shadow: var(--shadow-floating); pointer-events: none; z-index: 3100;
    }
    .fr-action-tooltip.visible { opacity: 1; transform: translateX(-50%) translateY(0); }
    .fab { 
        z-index: 11000 !important; 
        background: var(--glass-bg);
        backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px);
        border: 1px solid var(--glass-border);
        color: var(--primary);
        box-shadow: var(--shadow-floating);
        will-change: transform, left, width;
        /* All movement and sizing properties MUST use identical timing to prevent "jumping" */
        transition: 
            bottom 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275),
            left 0.4s cubic-bezier(0.2, 0, 0.2, 1),
            transform 0.4s cubic-bezier(0.2, 0, 0.2, 1),
            width 0.4s cubic-bezier(0.2, 0, 0.2, 1),
            height 0.4s cubic-bezier(0.2, 0, 0.2, 1),
            max-width 0.4s cubic-bezier(0.2, 0, 0.2, 1),
            min-width 0.4s cubic-bezier(0.2, 0, 0.2, 1),
            background-color 0.3s, border-color 0.3s, color 0.3s;
    }
    /* Subtle primary tint when not recording to identify action */
    /* Applied to both standard and active notification states in back-mode */
    .fab:not(.recording):not(.back-mode),
    .fab.back-mode.ft-active {
        background: color-mix(in srgb, var(--primary), var(--glass-bg) 85%) !important;
    }

    /* BACK MODE: Minimize to corner when overlays are open */
    .fab.back-mode {
        z-index: 20000 !important; 
        left: var(--fr-back-x, 45px) !important;
        bottom: var(--fr-back-y, 32px) !important;
    }
    .fab.back-mode:not(.ft-active) {
        width: 48px !important;
        height: 48px !important;
        min-width: 48px !important;
        /* Themed Glassy Back Button */
        background: color-mix(in srgb, var(--primary), var(--glass-bg) 70%) !important;
        color: white !important;
        border-color: rgba(255,255,255,0.2) !important;
        box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
        /* Ensure transform resets to 0 smoothly during transition from centered pill */
        transform: translateX(0) scale(1) !important;
    }
    .fab.back-mode.ft-active {
        height: 48px !important;
    }
    .fab.back-mode:active { transform: scale(0.9) !important; background: var(--btn-bg) !important; }

    /* APP MODE: Minimize to a small dot in the lower left */
    .fab.am-app-mode {
        left: 24px !important;
        bottom: 24px !important;
        width: 10px !important;
        height: 10px !important;
        min-width: 10px !important;
        padding: 0 !important;
        transform: none !important;
        background: var(--primary) !important;
        border: none !important;
        box-shadow: 0 0 8px rgba(0,0,0,0.2) !important;
        z-index: 20000 !important;
    }
    .fab.am-app-mode svg { display: none !important; }

    /* SELECTION MODE OFFSET: Lift above bottom bar */
    body.select-mode:not(.sui-overlay-open) .fab.back-mode {
        bottom: calc(var(--fr-back-y, 32px) + 100px) !important;
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
    }
    body.select-mode:not(.sui-overlay-open) .fab.back-mode:not(.ft-active) {
        transform: scale(1) !important;
    }
    


    /* --- SHARED SAFE ZONE SYSTEM --- */
    :root {
        /* UI Height: The physical space the floating elements occupy */
        --fr-ui-h: calc(var(--fr-back-y, 32px) + var(--fab-size, 68px));
        /* Safe Zone Height: UI Height + User-defined content clearance */
        --fr-sz-h: calc(var(--fr-ui-h) + var(--fr-safe-zone-gap, 16px));
    }

    /* Command Bar Mode Override */
    body.fcb-mode {
        --fr-ui-h: calc(var(--fcb-bottom-offset, 24px) + 60px);
    }

    /* Docked Command Bar Override */
    body.fcb-mode.fcb-docked {
        --fr-ui-h: calc(60px + env(safe-area-inset-bottom, 0px));
    }

    .shared-bottom-sheet { padding-bottom: 0 !important; }
    
    /* Inner Content Padding */
    .sui-studio-content, .sui-sz-padded, #settings-scroll-container {
        padding-bottom: var(--fr-sz-h) !important;
    }
    /* Firefox Flex Fix: Use a pseudo-element spacer for scrollable containers */
    #shared-picker-list::after,
    .scroll-view::after {
        content: '';
        display: block;
        height: var(--fr-sz-h);
        flex-shrink: 0;
    }
    /* Neutralize last-child margins recursively to ensure content touches the safe zone border */
    .sui-studio-content *:last-child, 
    #shared-picker-list *:last-child, 
    .sui-sz-padded *:last-child,
    #settings-scroll-container *:last-child,
    .po-folder-content *:last-child,
    .settings-group *:last-child,
    #settings-scroll-container > *:last-child {
        margin-bottom: 0 !important;
    }
    /* Specifically target the gap between groups if they are at the bottom */
    #settings-scroll-container .settings-group:last-of-type {
        margin-bottom: 0 !important;
    }
    /* Feathered Overlay */
    .shared-bottom-sheet::after, .settings-sheet::after {
        content: '';
        position: absolute;
        bottom: 0; left: 0; right: 0;
        height: var(--fr-sz-h);
        background: linear-gradient(to top, var(--bg-color) 20%, transparent);
        pointer-events: none;
        z-index: 100;
        transition: background 0.3s, border 0.3s;
    }
    /* DEBUG: SAFE ZONE VISUALIZATION */
    body.fr-debug-active .shared-bottom-sheet::after,
    body.fr-debug-active .settings-sheet::after {
        content: 'DEBUG: SAFE ZONE';
        background: rgba(255, 59, 48, 0.15);
        border-top: 2px dashed rgba(255, 59, 48, 0.4);
        display: flex; align-items: center; justify-content: center;
        font-size: 10px; font-weight: 900; color: rgba(255, 59, 48, 0.6);
    }
    .fab.back-mode svg { width: 22px !important; height: 22px !important; stroke-width: 3 !important; }
    .fab.back-mode #icon-mic, .fab.back-mode #icon-stop { display: none !important; }
    .fab.back-mode #icon-back { display: block !important; }

    /* FAB GLOW EFFECT */
    .fab.hint-active {
        animation: fr-fab-glow 1.5s infinite alternate;
        z-index: 3001;
    }

    /* --- SETTINGS GRID UI --- */
    .fr-grid-container {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        padding: 16px;
        background: rgba(0,0,0,0.03);
        border-radius: 20px;
        margin: 12px 16px;
        border: 1px solid var(--border-color);
    }
    .fr-grid-slot {
        aspect-ratio: 1;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        position: relative;
        transition: all 0.2s;
        box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    }
    .fr-grid-slot.is-empty { opacity: 0.4; border-style: dashed; background: transparent; }
    .fr-grid-slot:active { transform: scale(0.95); background: var(--btn-bg); }
    
    .fr-grid-label { 
        font-size: 8px; font-weight: 900; text-transform: uppercase; 
        margin-top: 6px; color: var(--text-secondary); 
        letter-spacing: 0.5px; max-width: 90%; overflow: hidden; text-overflow: ellipsis;
    }
    
    .fr-grid-nav {
        position: absolute; inset: 0;
        display: grid;
        grid-template-areas: ". up ." "left . right" ". down .";
        grid-template-columns: 1fr 1fr 1fr;
        grid-template-rows: 1fr 1fr 1fr;
        pointer-events: none;
    }
    .fr-grid-nav button {
        pointer-events: auto; border: none; background: none; 
        color: var(--primary); display: flex; align-items: center; 
        justify-content: center; opacity: 0.2; transition: opacity 0.2s;
        cursor: pointer; padding: 0;
    }
    .fr-grid-nav button:not(:disabled):hover { opacity: 0.8; }
    .fr-grid-nav button:disabled { color: var(--text-secondary); opacity: 0.05; cursor: default; }
    
    .nav-up { grid-area: up; }
    .nav-down { grid-area: down; }
    .nav-left { grid-area: left; }
    .nav-right { grid-area: right; }
    @keyframes fr-fab-glow {
        from { box-shadow: 0 0 10px var(--primary), 0 0 20px rgba(0, 122, 255, 0.2); }
        to { box-shadow: 0 0 25px var(--primary), 0 0 50px rgba(0, 122, 255, 0.5); transform: translateX(-50%) scale(1.1); }
    }

</style>
<button class="fab" id="fab-record">
    <svg id="icon-mic" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
    <svg id="icon-stop" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="display:none;"><rect x="6" y="6" width="12" height="12" rx="1"></rect></svg>
    <svg id="icon-back" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="display:none;"><polyline points="15 18 9 12 15 6"></polyline></svg>
</button>

<div class="fr-action-overlay" id="fr-action-menu">
    <div id="fr-menu-tooltip" class="fr-action-tooltip">Action</div>
    <!-- Zones dynamically generated by frInitActions() -->
</div>

<!-- BACK MODE RECORDER UI -->
<style>
    .fr-back-ui {
        position: fixed;
        /* Positioned dynamically via JS */
        left: 0; bottom: 0; 
        width: 60px; height: var(--fr-back-gesture-dist, 160px);
        z-index: 21000;
        pointer-events: none;
        opacity: 0; visibility: hidden;
        transition: opacity 0.2s;
        display: flex; flex-direction: column; align-items: flex-start; justify-content: flex-end;
    }
    .fr-back-ui.visible { opacity: 1; visibility: visible; }

    .fr-back-dotted-line {
        width: 2px; flex: 1;
        background-image: linear-gradient(to bottom, var(--text-secondary) 33%, rgba(255,255,255,0) 0%);
        background-position: bottom;
        background-size: 2px 6px;
        background-repeat: repeat-y;
        opacity: 0.5; margin-bottom: 4px;
        margin-left: 27px; /* Center under the 56px circle */
    }

    #fr-back-zone-rec {
        width: 56px; height: 56px; border-radius: 50%;
        background: var(--card-bg); border: 1px solid var(--border-color);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        box-shadow: var(--shadow-floating); color: var(--danger);
        transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    #fr-back-zone-rec svg { width: 24px; height: 24px; fill: currentColor; stroke: none; }
    #fr-back-zone-rec.active {
        transform: scale(1.3); /* Scaled up more to peek from under finger */
        background: var(--danger); color: white;
        border-color: rgba(255,255,255,0.4);
        /* Large Primary Glow + Pulse Animation */
        box-shadow: 0 0 0 0 rgba(255, 59, 48, 0.6), 0 12px 40px rgba(255, 59, 48, 0.5);
        animation: fr-back-rec-pulse 1.5s infinite;
    }

    @keyframes fr-back-rec-pulse {
        0% { box-shadow: 0 0 0 0 rgba(255, 59, 48, 0.7), 0 12px 40px rgba(255, 59, 48, 0.5); }
        70% { box-shadow: 0 0 0 25px rgba(255, 59, 48, 0), 0 12px 40px rgba(255, 59, 48, 0.5); }
        100% { box-shadow: 0 0 0 0 rgba(255, 59, 48, 0), 0 12px 40px rgba(255, 59, 48, 0.5); }
    }
    
    .fr-back-tooltip {
        position: absolute; top: -40px; left: 0;
        background: var(--glass-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
        border: 1px solid var(--glass-border); color: var(--text-primary);
        padding: 6px 12px; border-radius: 16px; font-size: 11px; font-weight: 800;
        white-space: nowrap; box-shadow: var(--shadow-floating);
    }
</style>
<div id="fr-back-recorder-ui" class="fr-back-ui">
    <div id="fr-back-tooltip" class="fr-back-tooltip">Slide up to Record</div>
    <div id="fr-back-zone-rec">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="6"></circle></svg>
    </div>
    <div class="fr-back-dotted-line"></div>
</div>

<div id="processing-overlay"><div class="spinner"></div><div class="processing-text" id="proc-text">Processing...</div></div>
HTML;

// 3. JAVASCRIPT LOGIC
$plugin_js .= <<<'JS'
// --- FLOATING RECORDER JS ---

let frExtraSettings = { travel_sensitivity: 22, reverse_travel: false, stop_at_dashboard: false };

// --- EXPORTS FOR COMMAND BAR ---
window.frGetSettings = () => frExtraSettings;
window.frGetRegistry = () => frActionRegistry;

// --- ACTION REGISTRY ---
const frActionRegistry =[
    { id: 'folders',  label: 'Folders', icon: 'folder',   action: () => typeof openFolderManager === "function" && openFolderManager() },
    { id: 'dash',     label: 'Dash',    icon: 'gauge',    action: () => typeof dashNavToPage === "function" && dashNavToPage('dashboard-scroll-view') },
    { id: 'draft',    label: 'Draft',   icon: 'edit',     action: () => { localStorage.setItem("cjos_draft_pad_open", "true"); if (typeof setDraftPadState === "function") setDraftPadState(true); } },
    { id: 'patcher',  label: 'Patcher', icon: 'hammer',   action: () => typeof cpOpenStudio === "function" && cpOpenStudio() },
    { id: 'layout',   label: 'Layout',  icon: 'layout',   action: () => typeof plOpenOverview === "function" && plOpenOverview() },
    { id: 'settings', label: 'Setup',   icon: 'sliders',  action: () => typeof openSettings === "function" && openSettings() },
    { id: 'history',  label: 'History', icon: 'clock',    action: () => typeof elShowHistoryPicker === "function" && elShowHistoryPicker() },
    { id: 'context_extras', label: 'Context', icon: 'box',    action: () => typeof ceOpenManualExtrasStudio === "function" && ceOpenManualExtrasStudio() },
    { id: 'ctx_studio', label: 'Export', icon: 'package', action: () => typeof ceOpenStudio === "function" && ceOpenStudio() },
    { id: 'vault',    label: 'Vault',   icon: 'folder',  action: () => typeof fvOpenStudio === "function" && fvOpenStudio() },
    { id: 'checkpoint', label: 'Secure',  icon: 'shield', action: () => typeof scCreateCheckpoint === "function" ? scCreateCheckpoint() : window.sui.toast("Checkpoint plugin disabled") },
    { id: 'save_snapshot', label: 'Save', icon: 'save',   action: () => typeof elTriggerCheckpointAction === "function" ? elTriggerCheckpointAction('save') : window.sui.toast("Save unavailable") },
    { id: 'theme',    label: 'Mode',    icon: 'moon',     action: () => {
        const isDark = (typeof tpState !== 'undefined' && tpState.mode === 'dark');
        if (typeof tpToggleMode === 'function') tpToggleMode(!isDark);
    }},
    { id: 'empty',    label: 'Empty',   icon: 'plus',     action: null }
];

function frInitActions() {
    const menu = document.getElementById('fr-action-menu');
    const settingsGrid = document.getElementById('fr-settings-grid');
    const settingsBench = document.getElementById('fr-settings-bench');
    if (!menu) return;

    // Initialize flat array (9 slots for 3 tiers)
    if (!Array.isArray(frExtraSettings.tier_order) || frExtraSettings.tier_order.length < 9) {
        const old = Array.isArray(frExtraSettings.tier_order) ? frExtraSettings.tier_order : ['folders', 'dash', 'draft', 'patcher', 'layout', 'settings'];
        frExtraSettings.tier_order = [...old];
        if (!frExtraSettings.tier_order.includes('history')) frExtraSettings.tier_order.push('history');
        if (!frExtraSettings.tier_order.includes('context_extras')) frExtraSettings.tier_order.push('context_extras');
        if (!frExtraSettings.tier_order.includes('vault')) frExtraSettings.tier_order.push('vault');
        if (!frExtraSettings.tier_order.includes('checkpoint')) frExtraSettings.tier_order.push('checkpoint');
        while(frExtraSettings.tier_order.length < 9) frExtraSettings.tier_order.push('empty');
    }

    const fullList = frExtraSettings.tier_order;

    // Clear existing
    menu.querySelectorAll('.fr-action-zone').forEach(z => z.remove());
    if (settingsGrid) settingsGrid.innerHTML = "";
    if (settingsBench) settingsBench.innerHTML = "";

    const renderMenuZone = (id, tier, colIndex) => {
        if (id === 'empty') return;
        let act = frActionRegistry.find(a => a.id === id);
        if (!act) return;

        // Dynamic Theme Icon/Label
        if (id === 'theme' && typeof tpState !== 'undefined') {
            const isDark = tpState.mode === 'dark';
            act = { ...act, icon: isDark ? 'sun' : 'moon', label: isDark ? 'Light' : 'Dark' };
        }
        const cols = ['left', 'center', 'right'];
        const col = cols[colIndex];
        const zone = document.createElement('div');
        zone.className = `fr-action-zone fr-tier-${tier} fr-col-${col}`;
        zone.id = `fr-zone-${act.id}`;
        zone.dataset.col = col;
        zone.innerHTML = `
            <span data-sui-icon="${act.icon}" data-sui-size="24" data-sui-stroke="2.5"></span>
            <span>${act.label}</span>
        `;
        menu.appendChild(zone);
    };

    const renderGridSlot = (idx) => {
        const id = fullList[idx];
        const act = frActionRegistry.find(a => a.id === id);
        if (!act || !settingsGrid) return;
        
        const slot = document.createElement('div');
        slot.className = `fr-grid-slot ${id === 'empty' ? 'is-empty' : ''}`;
        
        // Tap to open picker
        slot.onclick = () => frPickActionForSlot(idx);
        
        // Long-press to quickly unassign
        let lpTimer;
        slot.onpointerdown = (e) => {
            if (id === 'empty') return;
            lpTimer = setTimeout(() => {
                window.sui.haptic('medium');
                frExtraSettings.tier_order[idx] = 'empty';
                frInitActions();
                frSaveExtraConfig();
                window.sui.toast("Moved to Bench");
                lpTimer = null;
            }, 600);
        };
        slot.onpointerup = () => clearTimeout(lpTimer);
        slot.onpointerleave = () => clearTimeout(lpTimer);
        
        slot.innerHTML = `
            <span data-sui-icon="${act.icon}" data-sui-size="20" data-sui-color="${id === 'empty' ? 'var(--text-secondary)' : 'var(--primary)'}"></span>
            <div class="fr-grid-label">${act.label}</div>
            <div class="fr-grid-nav">
                <button class="nav-up" onclick="event.stopPropagation(); frMoveButton(${idx}, 'up')" ${idx >= 6 ? 'disabled' : ''}><span data-sui-icon="chevron" data-sui-size="10" style="transform:rotate(180deg)"></span></button>
                <button class="nav-down" onclick="event.stopPropagation(); frMoveButton(${idx}, 'down')" ${idx <= 2 ? 'disabled' : ''}><span data-sui-icon="chevron" data-sui-size="10"></span></button>
                <button class="nav-left" onclick="event.stopPropagation(); frMoveButton(${idx}, 'left')" ${idx % 3 === 0 ? 'disabled' : ''}><span data-sui-icon="chevron" data-sui-size="10" style="transform:rotate(90deg)"></span></button>
                <button class="nav-right" onclick="event.stopPropagation(); frMoveButton(${idx}, 'right')" ${idx % 3 === 2 ? 'disabled' : ''}><span data-sui-icon="chevron" data-sui-size="10" style="transform:rotate(-90deg)"></span></button>
            </div>
        `;
        settingsGrid.appendChild(slot);
    };

    // Render Logic: 
    // Gesture Menu renders T1 at bottom.
    // Settings Grid: Tier 3 (top row), Tier 2 (middle), Tier 1 (bottom)
    const gridOrder = [6, 7, 8, 3, 4, 5, 0, 1, 2];
    gridOrder.forEach(idx => renderGridSlot(idx));

    // Render Bench (Unassigned items)
    const assignedIds = new Set(fullList);
    const unassigned = frActionRegistry.filter(a => a.id !== 'empty' && !assignedIds.has(a.id));
    
    if (unassigned.length === 0 && settingsBench) {
        settingsBench.innerHTML = `<div style="font-size:10px; color:var(--text-secondary); opacity:0.5; font-style:italic; padding:8px;">All actions are currently assigned.</div>`;
    }

    unassigned.forEach(act => {
        if (!settingsBench) return;
        const pill = document.createElement('div');
        pill.className = 'meta-badge sui-badge-default';
        pill.style.cssText = 'cursor:pointer; padding:8px 12px; font-size:11px; border-radius:10px; display:flex; align-items:center; gap:6px;';
        pill.innerHTML = `<span data-sui-icon="${act.icon}" data-sui-size="14"></span> ${act.label}`;
        pill.onclick = () => {
            // Find first empty slot or ask to swap
            const firstEmpty = fullList.indexOf('empty');
            if (firstEmpty !== -1) {
                fullList[firstEmpty] = act.id;
                frInitActions();
                frSaveExtraConfig();
                window.sui.haptic('success');
            } else {
                window.openConfirm("Menu Full", "All 9 slots are filled. Tap a slot in the grid to swap or clear it.", null, false, "OK", null);
            }
        };
        settingsBench.appendChild(pill);
    });

    // Render actual menu zones
    fullList.slice(0, 3).forEach((id, i) => renderMenuZone(id, 1, i));
    fullList.slice(3, 6).forEach((id, i) => renderMenuZone(id, 2, i));
    fullList.slice(6, 9).forEach((id, i) => renderMenuZone(id, 3, i));

    if (window.suiHydrateIcons) {
        window.suiHydrateIcons(menu);
        if (settingsGrid) window.suiHydrateIcons(settingsGrid);
        if (settingsBench) window.suiHydrateIcons(settingsBench);
    }

    // Ensure visual status (red glow) is applied to new buttons immediately
    if (typeof window.elRefreshStatus === "function") {
        window.elRefreshStatus();
    }

    // LIVE UPDATE: Refresh the Command Bar if it exists
    if (typeof window.fcbRender === "function") {
        window.fcbRender();
    }
}

window.frPickActionForSlot = function(idx) {
    const currentId = frExtraSettings.tier_order[idx];
    const options = [
        { label: "Management", type: "header" },
        { label: "❌ Unassign (Move to Bench)", value: "empty" },
        { label: "Available Actions", type: "header" }
    ];

    // Add all registry items (except empty which is handled above)
    frActionRegistry.forEach(a => {
        if (a.id === 'empty') return;
        options.push({ label: a.label, value: a.id });
    });

    window.openPicker("Slot Assignment", options, currentId, (newId) => {
        // If the newId is already in another slot, swap them
        const existingIdx = frExtraSettings.tier_order.indexOf(newId);
        if (newId !== 'empty' && existingIdx !== -1 && existingIdx !== idx) {
            frExtraSettings.tier_order[existingIdx] = currentId;
        }
        
        frExtraSettings.tier_order[idx] = newId;
        frInitActions();
        frSaveExtraConfig();
        window.sui.haptic('medium');
    }, true);
};

window.frMoveButton = function(idx, direction) {
    const list = frExtraSettings.tier_order;
    let targetIdx = -1;
    
    if (direction === 'up' && idx < 6) targetIdx = idx + 3;
    if (direction === 'down' && idx > 2) targetIdx = idx - 3;
    if (direction === 'left' && idx % 3 !== 0) targetIdx = idx - 1;
    if (direction === 'right' && idx % 3 !== 2) targetIdx = idx + 1;

    if (targetIdx !== -1) {
        const temp = list[idx];
        list[idx] = list[targetIdx];
        list[targetIdx] = temp;
        frInitActions();
        frSaveExtraConfig();
        window.sui.haptic('medium');
    }
};



// --- MAGIC BACK GESTURE INTERCEPTOR ---
window.frHandleBackAction = function() {
    if (window.sui && window.sui.haptic) window.sui.haptic('medium');
    
    // 1. Priority: Scroll open Studios/Overlays to top before dismissing
    const activeStudio = document.querySelector('.shared-menu-overlay.visible .sui-studio-content, .shared-menu-overlay.visible #shared-picker-list');
    if (activeStudio && activeStudio.scrollTop > 10) {
        activeStudio.scrollTo({ top: 0, behavior: 'smooth' });
        return true;
    }

    // 2. Priority: Dismiss overlays/studios/pickers
    if (window.sui && typeof window.sui.dismissTopOverlay === 'function') {
        if (window.sui.dismissTopOverlay()) return true;
    }

    // 2.5 Priority: Close Draft Pad
    const draftPad = document.getElementById("draft-pad-card");
    if (draftPad && draftPad.style.transform === "translateY(0px)") {
        if (typeof setDraftPadState === "function") {
            setDraftPadState(false);
            localStorage.setItem('cjos_draft_pad_open', 'false');
            return true;
        }
    }
    
    // 3. Secondary: Cancel multi-selection mode
    if (document.body.classList.contains('select-mode')) {
        if (typeof cjosToggleSelectMode === 'function') cjosToggleSelectMode(false);
        return true;
    }

    // 4. Tertiary: Close standard settings overlay
    const settings = document.querySelector('.settings-overlay.visible');
    if (settings) {
        const closeBtn = settings.querySelector('.settings-close');
        if (closeBtn) closeBtn.click();
        return true;
    }

    // 5. Quaternary: Scroll to top of current page (Lazy & Portal Aware)
    const viewport = document.querySelector('.horizontal-viewport');
    if (viewport) {
        const pageWidth = viewport.clientWidth;
        const scrollLeft = viewport.scrollLeft;
        // Find all page views, including portals
        const pages = Array.from(viewport.querySelectorAll('.page-view'));
        // Find the one that is currently most visible in the viewport
        const activePage = pages.find(p => {
            const offset = p.offsetLeft;
            return Math.abs(offset - scrollLeft) < 10;
        });

        if (activePage) {
            // Find the scrollable element. In portals, it might be nested.
            // We look for the first element that is actually scrolled.
            const scrollables = activePage.querySelectorAll('.scroll-view, [style*="overflow-y: auto"], [style*="overflow-y:auto"]');
            for (let s of scrollables) {
                if (s.scrollTop > 10) {
                    s.scrollTo({ top: 0, behavior: 'smooth' });
                    return true;
                }
            }
        }
    }
    return false;
};

const frProcessBack = () => {
    if (window.location.hash === '#back') {
        // Clear hash immediately so the next gesture can trigger it
        history.replaceState(null, null, window.location.pathname);
        if (typeof frHandleBackAction === 'function') frHandleBackAction();
    }
};
window.addEventListener('hashchange', frProcessBack, false);
window.addEventListener('popstate', frProcessBack, false);
if (window.location.hash === '#back') frProcessBack();

window.frToggleStopDash = function(val) {
    frExtraSettings.stop_at_dashboard = val;
    frSaveExtraConfig();
};

// A. SETTINGS LOGIC
window.addEventListener("load", async () => {
    // Initialize Magic Back URL display
    const mbUrl = document.getElementById('fr-magic-back-url');
    if (mbUrl) mbUrl.value = window.location.origin + window.location.pathname + '#back';

    // Load extra config
    try {
        const data = await window.sui.api("fr_get_config", {}, { toast: false });
        if(data && data.config) {
            // Merge config instead of overwriting to preserve defaults set by plugins
            frExtraSettings = { ...frExtraSettings, ...data.config };
            
            // Initialize Sliders with Sync Helper
            frUpdateSensitivityLabel(frExtraSettings.travel_sensitivity ?? 22);
            frUpdateMenuDist(frExtraSettings.menu_distance ?? 100);
            frUpdateSpreadUI(frExtraSettings.tier_spread ?? 80);
            frUpdateBackX(frExtraSettings.back_x ?? 45);
            frUpdateBackY(frExtraSettings.back_y ?? 32);
            frUpdateBackGestureDist(frExtraSettings.back_gesture_dist ?? 160);
            frUpdateGapUI(frExtraSettings.gap ?? 16);

            // Initialize Toggles (Hydrated by SharedUI)
            const revToggle = document.getElementById("fr-reverse-travel");
            if(revToggle) revToggle.checked = !!frExtraSettings.reverse_travel;

            const actRevToggle = document.getElementById("fr-reverse-action-travel");
            if(actRevToggle) actRevToggle.checked = !!frExtraSettings.reverse_action_travel;
            
            const stopToggle = document.getElementById("fr-stop-dash");
            if(stopToggle) stopToggle.checked = !!frExtraSettings.stop_at_dashboard;
            
            const debugToggle = document.getElementById('fr-debug-toggle');
            if(debugToggle) debugToggle.checked = !!frExtraSettings.debug;
            document.body.classList.toggle('fr-debug-active', !!frExtraSettings.debug);

            // UI Mode Settings
            const modeSelect = document.getElementById('fr-ui-mode');
            if(modeSelect) modeSelect.value = frExtraSettings.ui_mode || 'fab';
            const dockToggle = document.getElementById('fr-fcb-docked');
            if(dockToggle) dockToggle.checked = !!frExtraSettings.fcb_docked;

            const omniToggle = document.getElementById('fr-fcb-omnibutton');
            if(omniToggle) omniToggle.checked = !!frExtraSettings.fcb_omnibutton;

            const hideLeftToggle = document.getElementById('fr-fcb-hide-left');
            if(hideLeftToggle) hideLeftToggle.checked = !!frExtraSettings.fcb_hide_left;

            frUpdateFcbOffset(frExtraSettings.fcb_bottom_offset ?? 24);
            frUpdateDialOffset(frExtraSettings.fcb_dial_offset ?? 100);
            
            document.body.classList.toggle('fcb-mode', frExtraSettings.ui_mode === 'bar');
            document.body.classList.toggle('fcb-docked', !!frExtraSettings.fcb_docked);
            document.body.classList.toggle('fcb-omni', !!frExtraSettings.fcb_omnibutton);
            document.body.classList.toggle('fcb-hide-left', !!frExtraSettings.fcb_hide_left);

            const singleToggle = document.getElementById('fr-fcb-single-tap-exit');
            if(singleToggle) singleToggle.checked = !!frExtraSettings.fcb_single_tap_exit;

            const doubleToggle = document.getElementById('fr-fcb-double-tap-exit');
            if(doubleToggle) doubleToggle.checked = !!frExtraSettings.fcb_double_tap_exit;
        }
    } catch(e) {}

window.frUpdateUiMode = function(val) {
    frExtraSettings.ui_mode = val;
    document.body.classList.toggle('fcb-mode', val === 'bar');
    
    // Synchronize the select menu in settings
    const modeSelect = document.getElementById('fr-ui-mode');
    if (modeSelect) modeSelect.value = val;
    
    frSaveExtraConfig();
};

window.frToggleFcbDocked = function(val) {
    frExtraSettings.fcb_docked = !!val;
    document.body.classList.toggle('fcb-docked', !!val);
    frSaveExtraConfig();
};

window.frToggleOmnibutton = function(val) {
    frExtraSettings.fcb_omnibutton = !!val;
    document.body.classList.toggle('fcb-omni', !!val);
    frSaveExtraConfig();
};

window.frToggleHideLeft = function(val) {
    frExtraSettings.fcb_hide_left = !!val;
    document.body.classList.toggle('fcb-hide-left', !!val);
    frSaveExtraConfig();
};

window.frToggleSingleTapExit = function(val) {
    frExtraSettings.fcb_single_tap_exit = !!val;
    frSaveExtraConfig();
};

window.frToggleDoubleTapExit = function(val) {
    frExtraSettings.fcb_double_tap_exit = !!val;
    frSaveExtraConfig();
};

    // Ensure actions are initialized AFTER config is loaded and merged
    frInitActions();

    // Load config from ConjureCore
    try {
        const data = await window.sui.api("cc_get_config", {}, { toast: false });
        if(data && data.config) {
            if(document.getElementById("fr-sound-start")) document.getElementById("fr-sound-start").value = data.config.sound_start || "";
            if(document.getElementById("fr-sound-stop")) document.getElementById("fr-sound-stop").value = data.config.sound_stop || "";
            
            // Sync to LocalStorage
            localStorage.setItem("cjos_sound_start", data.config.sound_start || "");
            localStorage.setItem("cjos_sound_stop", data.config.sound_stop || "");
        }
    } catch(e) {}
});

window.frSaveSettings = async function() {
    const start = document.getElementById("fr-sound-start").value;
    const stop = document.getElementById("fr-sound-stop").value;
    const status = document.getElementById("fr-status");
    
    if(status) status.innerText = "Saving...";

    try {
        // Sync with ConjureCore
        await window.sui.api("cc_save_config", { sound_start: start, sound_stop: stop }, { toast: false });
        localStorage.setItem("cjos_sound_start", start);
        localStorage.setItem("cjos_sound_stop", stop);
        if(status) status.innerText = "Saved";
    } catch(e) {
        if(status) status.innerText = "Error saving";
    }
};

// Helper to sync slider value, label, track fill, and global state
function frSyncSlider(id, val, suffix = "px") {
    const slider = document.getElementById(id);
    const label = document.getElementById(id + "-val");
    if (!slider) return;
    
    // 1. Update Slider Value
    slider.value = val;
    
    // 2. Update Visual Track Fill (--range-pct)
    const min = slider.min || 0;
    const max = slider.max || 100;
    const pct = ((val - min) / (max - min)) * 100;
    slider.style.setProperty('--range-pct', pct + '%');
    
    // 3. Update Label
    if (label) label.innerText = val + suffix;
}

window.frUpdateSensitivityLabel = function(val) {
    frSyncSlider("fr-travel-sensitivity", val, "%");
    frExtraSettings.travel_sensitivity = parseInt(val);
};

window.frToggleReverse = function(val) {
    frExtraSettings.reverse_travel = !!val;
    frSaveExtraConfig();
};

window.frToggleActionReverse = function(val) {
    frExtraSettings.reverse_action_travel = !!val;
    frSaveExtraConfig();
};

window.frUpdateMenuDist = function(val) {
    frSyncSlider("fr-menu-dist", val, "px");
    frExtraSettings.menu_distance = parseInt(val);
    document.documentElement.style.setProperty('--fr-menu-dist', val + 'px');
};

window.frUpdateSpreadUI = function(val) {
    frSyncSlider("fr-tier-spread", val, "px");
    frExtraSettings.tier_spread = parseInt(val);
    document.documentElement.style.setProperty('--fr-tier-spread', val + 'px');
};

window.frUpdateBackX = function(val) {
    frSyncSlider("fr-back-x", val, "px");
    frExtraSettings.back_x = parseInt(val);
    document.documentElement.style.setProperty('--fr-back-x', val + 'px');
};

window.frUpdateBackY = function(val) {
    frSyncSlider("fr-back-y", val, "px");
    frExtraSettings.back_y = parseInt(val);
    document.documentElement.style.setProperty('--fr-back-y', val + 'px');
};

window.frUpdateBackGestureDist = function(val) {
    frSyncSlider("fr-back-gesture-dist", val, "px");
    frExtraSettings.back_gesture_dist = parseInt(val);
    document.documentElement.style.setProperty('--fr-back-gesture-dist', val + 'px');
};

window.frUpdateGapUI = function(val) {
    frSyncSlider("fr-gap", val, "px");
    frExtraSettings.gap = parseInt(val);
    document.documentElement.style.setProperty('--fr-safe-zone-gap', val + 'px');
};

window.frToggleDebug = function(enabled) {
    frExtraSettings.debug = enabled;
    document.body.classList.toggle('fr-debug-active', enabled);
    frSaveExtraConfig();
};

window.frUpdateFcbOffset = function(val) {
    frSyncSlider("fr-fcb-offset", val, "px");
    frExtraSettings.fcb_bottom_offset = parseInt(val);
    document.documentElement.style.setProperty('--fcb-bottom-offset', val + 'px');
};

window.frUpdateDialOffset = function(val) {
    frSyncSlider("fr-fcb-dial-offset", val, "px");
    frExtraSettings.fcb_dial_offset = parseInt(val);
};

window.frOpenStudio = function() {
    const root = document.getElementById('fr-gui-root');
    const anchor = document.getElementById('fr-tray-anchor');
    if(!root || !anchor) return;

    window.sui.openStudio({
        id: 'fr-settings-studio',
        title: 'Recorder Settings',
        content: '', // Empty because we move the live DOM
        onSetup: (contentBox) => {
            // Move the LIVE DOM from the tray to the Studio
            contentBox.appendChild(root);
            
            // Ensure padding for the safe zone
            root.style.padding = "20px 16px";
            
            // Re-sync visual state of sliders (track fill)
            frUpdateSensitivityLabel(frExtraSettings.travel_sensitivity ?? 22);
            frUpdateMenuDist(frExtraSettings.menu_distance ?? 100);
            frUpdateSpreadUI(frExtraSettings.tier_spread ?? 80);
            frUpdateBackX(frExtraSettings.back_x ?? 45);
            frUpdateBackY(frExtraSettings.back_y ?? 32);
            frUpdateBackGestureDist(frExtraSettings.back_gesture_dist ?? 160);
            frUpdateGapUI(frExtraSettings.gap ?? 16);
            frUpdateFcbOffset(frExtraSettings.fcb_bottom_offset ?? 24);
        },
        onClose: () => {
            // Move the LIVE DOM back to its original home in the settings tray
            anchor.appendChild(root);
            root.style.padding = "0";
        }
    });
};

window.frSaveExtraConfig = async function() {
    await window.sui.api("fr_save_config", { settings: frExtraSettings }, { toast: false });
};

// B. RECORDER LOGIC
function playTone(freq, type) {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.frequency.value = freq; osc.type = type;
    osc.connect(gain); gain.connect(ctx.destination);
    osc.start(); gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + 0.5);
    osc.stop(ctx.currentTime + 0.5);
    setTimeout(() => { try { ctx.close(); } catch(e) {} }, 600);
}

const fab = document.getElementById("fab-record");
const iconMic = document.getElementById("icon-mic");
const iconStop = document.getElementById("icon-stop");
const processingOverlay = document.getElementById("processing-overlay");
const procText = document.getElementById("proc-text");

let isRecording = false;
let mediaRecorder = null;
let audioChunks = [];

function playSound(type, onComplete) {
    let src = type === "start" ? localStorage.getItem("cjos_sound_start") : localStorage.getItem("cjos_sound_stop");
    if(src && src.length > 5) { 
        let audio = new Audio(src);
        if(onComplete) {
            audio.onended = onComplete;
            setTimeout(() => { if(!audio.ended) onComplete(); }, 3000); 
        }
        audio.play().catch(() => { if(onComplete) onComplete(); });
    } else { 
        if(type === "start") playTone(600, "sine"); 
        else playTone(400, "triangle");
        if(onComplete) setTimeout(onComplete, 500); 
    }
}

if(fab) {
    let fabTimer = null;
    let backGestureTimer = null;
    let isLongPressTriggered = false;
    let isSliderActive = false;
    let isMenuMode = false;
    let isBackMenuMode = false;
    let activeZone = null;
    let lpHintTimer = null;
    let colHoverTimers = { left: null, center: null, right: null };
    let colUnlocked = { left: false, center: false, right: false };
    let preventClick = false;
    let startX = 0;
    let startY = 0;
    let lastRawDeltaY = 0;
    let lastMenuX = 0;
    let startPage = 0;
    let targetScrollX = 0;
    let lastHapticPage = -1;
    let reachedLogStream = false;
    let dashGateLocked = true;
    let dashLockTime = 0;
    const viewport = document.querySelector('.horizontal-viewport');

    let lerpRafId = null;
    const lerpLoop = () => {
        if (!isSliderActive || !viewport || document.hidden) {
            lerpRafId = null;
            return;
        }
        const current = viewport.scrollLeft;
        const diff = targetScrollX - current;
        if (Math.abs(diff) > 0.5) {
            viewport.scrollLeft += diff * 0.15;
            lerpRafId = requestAnimationFrame(lerpLoop);
        } else {
            lerpRafId = null;
        }
    };

    const frResetMenuState = () => {
        Object.keys(colHoverTimers).forEach(c => {
            clearTimeout(colHoverTimers[c]);
            colHoverTimers[c] = null;
            colUnlocked[c] = 0; // Depth: 0 (None), 1 (T2), 2 (T3)
            document.querySelectorAll(`.fr-action-zone.fr-col-${c}`).forEach(z => z.classList.remove('unlocked'));
        });
        activeZone = null;
        const tooltip = document.getElementById('fr-menu-tooltip');
        if (tooltip) {
            tooltip.classList.remove('visible');
            tooltip.style.setProperty('--tooltip-y', '126px');
        }
        document.querySelectorAll('.fr-action-zone').forEach(z => z.classList.remove('active'));
    };

    const startFabPress = (e) => {
    // NOTE: In Back Mode, allow press even if recording (to allow stop)
    if (isRecording && !fab.classList.contains('back-mode')) return;
    
    frResetMenuState();
    isSliderActive = false;
    isMenuMode = false;
    isBackMenuMode = false;
    startX = e.touches ? e.touches[0].clientX : e.clientX;
    startY = e.touches ? e.touches[0].clientY : e.clientY;

    // BACK MODE: Show gesture UI after a short delay to ignore quick taps
    if (fab.classList.contains('back-mode')) {
        isBackMenuMode = true;
        backGestureTimer = setTimeout(() => {
            const backUi = document.getElementById('fr-back-recorder-ui');
            const backTooltip = document.getElementById('fr-back-tooltip');
            if (backUi && backTooltip) {
                const rect = fab.getBoundingClientRect();
                const topY = window.innerHeight - rect.top;
                // Align left edge of UI container with left edge of button
                backUi.style.left = rect.left + 'px';
                backUi.style.bottom = (topY + 10) + 'px';
                backUi.classList.add('visible');
                backTooltip.innerText = isRecording ? "Slide up to Stop" : "Slide up to Record";
            }
            backGestureTimer = null;
        }, 180);
    }
    if (!fab.classList.contains('back-mode')) {
        lpHintTimer = setTimeout(() => {
            if (!isSliderActive && !isMenuMode) {
                isMenuMode = true;
                preventClick = true;
                document.getElementById('fr-action-menu').classList.add('visible');
                fab.classList.add('hint-active');
                window.sui.haptic('medium');
            }
        }, 600);
    }
            
    if (viewport) {const pageWidth = viewport.clientWidth;
            startPage = Math.round(viewport.scrollLeft / pageWidth);
            targetScrollX = viewport.scrollLeft;
            viewport.style.scrollSnapType = 'none';
        }
    };

    const frUpdateActiveZone = (newZoneId) => {
        if (newZoneId === activeZone) return;
        activeZone = newZoneId;
        window.sui.haptic('tap');
        const tooltip = document.getElementById('fr-menu-tooltip');
        
        const action = frActionRegistry.find(a => a.id === newZoneId);
        if (action) {
            tooltip.innerText = action.label;
            tooltip.classList.add('visible');
        } else {
            tooltip.classList.remove('visible');
        }
        
        frActionRegistry.forEach(act => {
            const el = document.getElementById(`fr-zone-${act.id}`);
            if (el) el.classList.toggle('active', activeZone === act.id);
        });
    };

    const handleFabMove = (e) => {
        if ((isRecording && !fab.classList.contains('back-mode')) || !startX) return;
        const currentX = e.touches ? e.touches[0].clientX : e.clientX;
        const currentY = e.touches ? e.touches[0].clientY : e.clientY;
        const rawDeltaX = currentX - startX;
        const rawDeltaY = currentY - startY;
        lastRawDeltaY = rawDeltaY;

        // --- GLOBAL BREAKOUT ---
        if (!isSliderActive && !isMenuMode && !isBackMenuMode && (Math.abs(rawDeltaX) > 10 || Math.abs(rawDeltaY) > 10)) {
            clearTimeout(lpHintTimer);
            fab.classList.remove('hint-active');
        }

        // --- BACK MODE GESTURE LOGIC ---
        if (fab.classList.contains('back-mode')) {
            if (isBackMenuMode) {
                preventClick = true;

                // Breakout: If sliding up significantly, trigger UI immediately
                if (rawDeltaY < -15 && backGestureTimer) {
                    clearTimeout(backGestureTimer);
                    // Manually trigger the UI visibility logic
                    const backUi = document.getElementById('fr-back-recorder-ui');
                    if (backUi) {
                        const rect = fab.getBoundingClientRect();
                        backUi.style.left = (rect.left + rect.width / 2) + 'px';
                        backUi.style.bottom = (window.innerHeight - rect.top + 10) + 'px';
                        backUi.classList.add('visible');
                    }
                    backGestureTimer = null;
                }
                    
        // Dynamic Threshold Logic: 
        // Trigger when finger reaches within 40px of the visual button center
        const dist = frExtraSettings.back_gesture_dist || 160;
        const inZone = rawDeltaY < -(dist - 40);

        if (inZone && activeZone !== 'back_record') {activeZone = 'back_record';
                    document.getElementById('fr-back-zone-rec').classList.add('active');
                    window.sui.haptic('medium');
                } else if (!inZone && activeZone === 'back_record') {
                    activeZone = null;
                    document.getElementById('fr-back-zone-rec').classList.remove('active');
                }
                return; // Stop standard logic
            }
            return; // Stop standard logic if in back mode but not yet in gesture
        }

        // --- STANDARD LOGIC ---
        // Horizontal Fast Travel Breakout
        if (!isSliderActive && Math.abs(rawDeltaX) > 30 && Math.abs(rawDeltaX) > (Math.abs(rawDeltaY) * 1.8)) {
            isSliderActive = true;
            preventClick = true;
            if (isMenuMode) {
                isMenuMode = false;
                document.getElementById('fr-action-menu').classList.remove('visible');
                frResetMenuState();
                fab.classList.remove('hint-active');
            }
            requestAnimationFrame(lerpLoop);
        }

        // Immediate Swipe-Up Trigger
        if (!isSliderActive && !isMenuMode && rawDeltaY < -50) {
            isMenuMode = true;
            clearTimeout(lpHintTimer);
            document.getElementById('fr-action-menu').classList.add('visible');
            fab.classList.add('hint-active');
            window.sui.haptic('medium');
        }

        // Immediate Swipe-Down Trigger (Switch to Command Bar)
        if (!isSliderActive && !isMenuMode && !isBackMenuMode && rawDeltaY > 50) {
            preventClick = true;
            clearTimeout(lpHintTimer);
            if (typeof window.frUpdateUiMode === 'function') {
                window.frUpdateUiMode('bar');
                window.sui.haptic('medium');
            }
            startX = 0; startY = 0; // Terminate gesture
            return;
        }

        if (isMenuMode) {
            preventClick = true;
            const menuX = currentX - (window.innerWidth / 2);
            lastMenuX = menuX;
            let newZone = null;
            
            const col = (menuX < -70) ? 'left' : (menuX > 70 ? 'right' : 'center');
            const tooltip = document.getElementById('fr-menu-tooltip');
            
            if (rawDeltaY < -80) {
                const tier1El = document.querySelector(`.fr-action-zone.fr-tier-1.fr-col-${col}`);
                const tier2El = document.querySelector(`.fr-action-zone.fr-tier-2.fr-col-${col}`);
                const tier3El = document.querySelector(`.fr-action-zone.fr-tier-3.fr-col-${col}`);
                
                const spread = frExtraSettings.tier_spread || 80;
                const t2Threshold = -(80 + spread - 10); // 10px buffer
                const t3Threshold = -(80 + (spread * 2) - 10);

                if (colUnlocked[col] >= 2 && rawDeltaY < t3Threshold && tier3El) {
                    newZone = tier3El.id.replace('fr-zone-', '');
                    tooltip.style.setProperty('--tooltip-y', (126 - (spread * 2)) + 'px');
                } else if (colUnlocked[col] >= 1 && rawDeltaY < t2Threshold && tier2El) {
                    newZone = tier2El.id.replace('fr-zone-', '');
                    tooltip.style.setProperty('--tooltip-y', (126 - spread) + 'px');
                    
                    // Start T3 Unlock chain if hovering over T2
                    if (tier3El && colUnlocked[col] < 2 && !colHoverTimers[col]) {
                        colHoverTimers[col] = setTimeout(() => {
                            colUnlocked[col] = 2;
                            tier3El.classList.add('unlocked');
                            colHoverTimers[col] = null; 
                            window.sui.haptic('medium');
                            if (lastRawDeltaY < t3Threshold) frUpdateActiveZone(tier3El.id.replace('fr-zone-', ''));
                        }, 400);
                    }
                } else if (tier1El) {
                    newZone = tier1El.id.replace('fr-zone-', '');
                    tooltip.style.setProperty('--tooltip-y', '126px');
                    
                    // Start T2 Unlock chain if hovering over T1
                    if (tier2El && colUnlocked[col] < 1 && !colHoverTimers[col]) {
                        colHoverTimers[col] = setTimeout(() => {
                            colUnlocked[col] = 1;
                            tier2El.classList.add('unlocked');
                            colHoverTimers[col] = null; 
                            window.sui.haptic('medium');
                            if (lastRawDeltaY < t2Threshold) frUpdateActiveZone(tier2El.id.replace('fr-zone-', ''));
                        }, 400);
                    }
                }
                
                // Reset chains for other columns or if moved back down
                ['left', 'center', 'right'].forEach(c => {
                    if (c !== col || rawDeltaY > -60) {
                        clearTimeout(colHoverTimers[c]); colHoverTimers[c] = null;
                        if (c !== col) {
                            colUnlocked[c] = 0;
                            document.querySelectorAll(`.fr-action-zone.fr-col-${c}`).forEach(z => {
                                if (!z.classList.contains('fr-tier-1')) z.classList.remove('unlocked');
                            });
                        }
                    }
                });
            }

            frUpdateActiveZone(newZone);
            return;
        }

        if (isSliderActive) {
            const pageWidth = viewport.clientWidth;
            const maxPages = Math.round(viewport.scrollWidth / pageWidth) - 1;
            const multiplier = frExtraSettings.travel_sensitivity / 100;
            const pixelsPerPage = Math.max(25, (window.innerWidth * multiplier) / (maxPages || 1));
            const direction = frExtraSettings.reverse_travel ? -1 : 1;
                
            const idealPage = startPage + ((currentX - startX) * direction / pixelsPerPage);
            if (idealPage < 0) startX = currentX + (startPage * pixelsPerPage * direction);
            else if (idealPage > maxPages) startX = currentX - ((maxPages - startPage) * pixelsPerPage * direction);

            const finalDeltaX = currentX - startX;
            let targetPage = Math.max(0, Math.min(maxPages, Math.round(startPage + (finalDeltaX * direction / pixelsPerPage))));
                
            if (frExtraSettings.stop_at_dashboard) {
                if (targetPage === 0) reachedLogStream = true;
                if (reachedLogStream && targetPage > 1 && dashGateLocked) {
                    targetPage = 1;
                    if (lastHapticPage === 1) {
                        const now = Date.now();
                        if (dashLockTime === 0) dashLockTime = now;
                        if (now - dashLockTime > 450) {
                            dashGateLocked = false;
                            window.sui.haptic('medium');
                        } else {
                            startX = currentX - ((1 - startPage) * pixelsPerPage * direction);
                        }
                    }
                } else if (targetPage !== 1) dashLockTime = 0;
            }

            targetScrollX = targetPage * pageWidth;
            if (!lerpRafId) {
                lerpRafId = requestAnimationFrame(lerpLoop);
            }
            if (targetPage !== lastHapticPage) {
                window.sui.haptic('light');
                lastHapticPage = targetPage;
            }
        }
    };

    const endFabPress = (e) => {
        clearTimeout(lpHintTimer);
        clearTimeout(backGestureTimer);
        fab.classList.remove('hint-active');
            
        const wasSlider = isSliderActive;
        const wasLongPress = isLongPressTriggered;
        const wasMenu = isMenuMode;
        const wasBackMenu = isBackMenuMode;
        const finalZone = activeZone;

        // Handle Back Mode Recording Gesture
        if (wasBackMenu) {
            document.getElementById('fr-back-recorder-ui').classList.remove('visible');
            document.getElementById('fr-back-zone-rec').classList.remove('active');
            if (finalZone === 'back_record') {
                window.sui.haptic('medium');
                window.frToggleRecording(); // Trigger logic
            }
            isBackMenuMode = false;
            activeZone = null;
        }

        // Handle Standard Menu Actions
        if (wasMenu) {
            document.getElementById('fr-action-menu').classList.remove('visible');
            
            const action = frActionRegistry.find(a => a.id === finalZone);
            if (action && action.action) {
                action.action();
                window.sui.haptic('medium');
            }

            isMenuMode = false;
            frResetMenuState();
            document.getElementById('fr-menu-tooltip').style.setProperty('--tooltip-y', '46px');
        }

        isSliderActive = false;
        startX = 0;
        startY = 0;
        lastRawDeltaY = 0;
        lastMenuX = 0;
        lastHapticPage = -1;
        reachedLogStream = false;
        dashGateLocked = true;
        dashLockTime = 0;

        if (viewport) {
            viewport.style.scrollSnapType = 'x mandatory';
            if (wasSlider) {
                viewport.scrollTo({ left: targetScrollX, behavior: 'smooth' });
            }
        }

        if (wasLongPress && !wasSlider && !wasBackMenu) {
            preventClick = true;
            localStorage.setItem("cjos_draft_pad_open", "true");
            if (typeof cjosToggleSelectMode === "function") cjosToggleSelectMode(true);
            if (typeof setDraftPadState === "function") {
                setTimeout(() => setDraftPadState(true), 100);
            }
        }

        isLongPressTriggered = false;
        fab.classList.remove("morph-folder");
        setTimeout(() => { preventClick = false; }, 50);
    };

    fab.addEventListener("mousedown", startFabPress);
    fab.addEventListener("touchstart", startFabPress, {passive: true});
    window.addEventListener("mousemove", handleFabMove);
    window.addEventListener("touchmove", handleFabMove, {passive: true});
    window.addEventListener("mouseup", endFabPress);
    window.addEventListener("touchend", endFabPress);

    // --- OVERLAY STACK & STATE SYNCHRONIZATION ---
    window.frSyncOverlayState = () => {
        if (document.hidden) return; // Battery-Friendly: Skip if tab is backgrounded
        
        const overlays = document.querySelectorAll('.shared-menu-overlay, .settings-overlay, .sui-studio-overlay');
        const hasOverlay = Array.from(overlays).some(el => {
            return el.classList.contains('visible') || el.style.visibility === 'visible';
        });
        const isInBackMode = hasOverlay || document.body.classList.contains('select-mode');
        
        // Sync body class for CSS positioning logic
        document.body.classList.toggle('sui-overlay-open', hasOverlay);
            
        if (fab.classList.contains('back-mode') !== isInBackMode) {
            fab.classList.toggle('back-mode', isInBackMode);
            const backIcon = document.getElementById('icon-back');
            if (backIcon) backIcon.style.display = isInBackMode ? 'block' : 'none';
                
            if (isInBackMode) {
                iconMic.style.display = 'none';
                iconStop.style.display = 'none';
            } else if (!isRecording) {
                iconMic.style.display = 'block';
                iconStop.style.display = 'none';
            }
        }
    };

    window.frSyncAppPageMode = () => {
        if (document.hidden) return;
        const dynamicApp = document.getElementById('am-dynamic-app-page');
        let isViewingApp = false;
        if (dynamicApp && viewport) {
            const scrollLeft = viewport.scrollLeft;
            const appOffset = dynamicApp.offsetLeft;
            isViewingApp = Math.abs(scrollLeft - appOffset) < 10;
        }
        fab.classList.toggle('am-app-mode', isViewingApp && !window._frAppModeOverride);
    };

    // Event-Driven MutationObserver for Overlays and Selection Mode
    if (typeof MutationObserver !== "undefined") {
        const frOverlayObserver = new MutationObserver((mutations) => {
            frSyncOverlayState();
        });
        frOverlayObserver.observe(document.body, { 
            attributes: true, 
            attributeFilter: ['class', 'style'], 
            subtree: true 
        });
    }

    // Event-Driven Scroll Listener for App Page Detection
    if (viewport) {
        viewport.addEventListener('scroll', () => {
            frSyncAppPageMode();
        }, { passive: true });
    }

    // Global Hooks Integration
    if (typeof registerRefreshHook === "function") registerRefreshHook(frSyncOverlayState);
    if (typeof registerUpdateHook === "function") registerUpdateHook(frSyncOverlayState);

    // Establish initial baseline state on launch with zero background polling loops
    setTimeout(() => {
        frSyncOverlayState();
        frSyncAppPageMode();
    }, 500);
        
    // --- RECORDING TOGGLE LOGIC (Extracted) ---
    window.frToggleRecording = async () => {
        if (window.Guide && window.Guide.gestureState && window.Guide.gestureState.active) {
            return;
        }
        if (!isRecording) {
            try {
                const constraints = { audio: { echoCancellation: false, autoGainControl: false, noiseSuppression: false } };
                
                // Secure Context & Legacy Shim (Firefox/Tailscale Fix)
                const getMic = (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) 
                    ? navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices) 
                    : (navigator.mozGetUserMedia || navigator.webkitGetUserMedia || navigator.msGetUserMedia || navigator.getUserMedia);

                if (!getMic) {
                    window.openConfirm("Microphone Blocked", 
                        "Microphone access is blocked because this connection is not secure (HTTPS).<br><br>" +
                        "<strong>How to fix:</strong><br>" +
                        "1. Use <strong>Chrome/Edge</strong> with the 'Insecure Origins' flag.<br>" +
                        "2. Use an <strong>SSH Tunnel</strong> (localhost:8000).<br>" +
                        "3. Enable <strong>Tailscale HTTPS</strong>.", 
                        null, false, "OK", null);
                    return;
                }

                const stream = await getMic(constraints);
                let options = { mimeType: "audio/webm" };
                if (!MediaRecorder.isTypeSupported("audio/webm")) options = { mimeType: "audio/mp4" }; 
                    
                mediaRecorder = new MediaRecorder(stream, options);
                audioChunks = [];
                    
                mediaRecorder.ondataavailable = event => { if (event.data.size > 0) audioChunks.push(event.data); };
                mediaRecorder.onstop = window.cjosUpload;
                    
                playSound("start");
                mediaRecorder.start(); 

                isRecording = true;
                document.body.classList.add("is-recording");
                fab.classList.add("recording");
                iconMic.style.display = "none"; iconStop.style.display = "block";
            } catch (err) { 
                isRecording = false;
                document.body.classList.remove("is-recording");
                fab.classList.remove("recording");
                iconMic.style.display = "block"; 
                iconStop.style.display = "none";
                window.openConfirm("Microphone Error", "Mic Error: " + err, null, true, "OK", null); 
            }
        } else {
            isRecording = false;
            document.body.classList.remove("is-recording");
            fab.classList.remove("recording");
            iconMic.style.display = "block"; iconStop.style.display = "none";
            fab.style.pointerEvents = "none";

            playSound("stop", () => {
                if(mediaRecorder && mediaRecorder.state !== "inactive") {
                    if (mediaRecorder.stream) mediaRecorder.stream.getTracks().forEach(track => track.stop());
                    mediaRecorder.stop();
                }
                fab.style.pointerEvents = "auto";
            });
        }
    };

    fab.onclick = async () => {
        if (preventClick) {
            preventClick = false;
            return;
        }

        // APP MODE EXPANSION: If minimized, expand for 3 seconds instead of acting
        if (fab.classList.contains('am-app-mode')) {
            window._frAppModeOverride = true;
            fab.classList.remove('am-app-mode'); // Immediate visual feedback
            if (window.sui && window.sui.haptic) window.sui.haptic('light');
            
            if (window._frAppModeTimer) clearTimeout(window._frAppModeTimer);
            window._frAppModeTimer = setTimeout(() => {
                window._frAppModeOverride = false;
            }, 3000);
            return;
        }

        // GLOBAL BACK LOGIC
        if (fab.classList.contains('back-mode')) {
            window.frHandleBackAction();
            return;
        }

        window.frToggleRecording();
    };
}// C. UPLOAD SEQUENCE
// Defines the global handler used by the recorder. 
// Uses "core_transcribe" via index.php (Plugin System).
if (typeof window.cjosUpload === "undefined") {
window.cjosUpload = async function() {
        const apiKey = localStorage.getItem("cjos_api_key") || "";
        
        await new Promise(r => setTimeout(r, 100));
        if(audioChunks.length === 0) { window.openConfirm("Recording Error", "Recording was empty.", null, false, "OK", null); return; }
        
        const audioBlob = new Blob(audioChunks, { type: "audio/webm" });
        
        // 1. UPLOAD ONLY (Core Backend)
        if(processingOverlay) processingOverlay.classList.add("visible");
        if(procText) procText.textContent = "Saving...";
        
        let uploadedId = null;
        
        try {
            const formData = new FormData();
            formData.append("action", "upload_only");
            formData.append("audio", audioBlob, "recording.webm");
            
            const response = await fetch("index.php", { method: "POST", body: formData });
            const result = await response.json();
            
            if (result.status === "success") {
                uploadedId = result.id;
            } else {
                throw new Error(result.message || "Unknown server error");
            }
        } catch (e) {
            if(processingOverlay) processingOverlay.classList.remove("visible");
            window.openConfirm("Storage Error", "Could not save audio. " + e, null, true, "OK", null);
            return;
        }

        // 2. TRANSCRIBE (Plugin via index.php)
        if(procText) procText.textContent = "Transcribing...";
        
        try {
            const result = await window.sui.api("cc_transcribe", {
                id: uploadedId,
                api_key: apiKey,
                model: localStorage.getItem("cjos_model") || "whisper-1",
                prompt: localStorage.getItem("cjos_prompt") || ""
            }, { toast: false });
            
            if(processingOverlay) processingOverlay.classList.remove("visible");
            location.reload();
        } catch (e) {
            if(processingOverlay) processingOverlay.classList.remove("visible");
            location.reload();
        }
    };
}
JS;
?>
