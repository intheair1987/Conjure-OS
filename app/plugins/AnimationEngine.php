<?php
// ==============================================================================
// PLUGIN: Animation Engine
// DESCRIPTION: UI Performance & Smoothness.

$ae_config_file = CJOS_PATH_DATA . '/animation-engine-config.json';

// Load initial state for SSR-lite hydration
$ae_conf = ['forceGPU' => true, 'simplify' => false, 'reduceMotion' => false];
if (file_exists($ae_config_file)) {
    $loaded = json_decode(file_get_contents($ae_config_file), true);
    if (is_array($loaded)) $ae_conf = array_merge($ae_conf, $loaded);
}

if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'ae_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        file_put_contents($ae_config_file, $_POST['settings']);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['plugin_action'] === 'ae_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $data = file_exists($ae_config_file) ? json_decode(file_get_contents($ae_config_file), true) : ['forceGPU' => true, 'simplify' => false, 'reduceMotion' => false];
        echo json_encode(['status' => 'success', 'config' => $data]);
        exit;
    }
}

// --- DATA BRIDGE ---
$ae_bridge_json = json_encode($ae_conf);
$plugin_js .= "\nwindow.__AE_BRIDGE__ = $ae_bridge_json;\n";

// Purpose: The "Gold Standard" rendering controller.
// 1. Enforces GPU Compositing (Hardware Acceleration) for critical elements.
// 2. Disables expensive paints (Blurs, Box Shadows) during interaction.
// 3. Centralizes transition timing to ensure frame budget (16ms) is met.
// ==============================================================================

$plugin_settings_map['AnimationEngine'] = <<<'HTML'
    <div data-sui-setting="GPU Acceleration" data-sui-desc="Force hardware compositing for 60fps scrolling." data-sui-id="ae-gpu-mode" data-sui-onchange="toggleAeGpu(this.checked)"></div>
    <div data-sui-setting="Simplify Visuals" data-sui-desc="Disable Blur and reduce Shadows to save battery." data-sui-id="ae-simplify-mode" data-sui-onchange="toggleAeSimplify(this.checked)"></div>
    <div data-sui-setting="Reduce Motion" data-sui-desc="Replaces sliding/expanding animations with instant snaps." data-sui-id="ae-reduce-motion" data-sui-onchange="toggleAeMotion(this.checked)"></div>
HTML;

$plugin_js .= <<<'JS'
// --- ANIMATION ENGINE CORE ---

let aeState = { forceGPU: true, simplify: false, reduceMotion: false };

window.addEventListener("load", async () => {
    // 0. Fetch Server State (Use Bridge first for zero-flash)
    if (window.__AE_BRIDGE__) {
        aeState = window.__AE_BRIDGE__;
    }
    try {
        const data = await window.sui.api('ae_get_config', {}, { toast: false });
        if (data) aeState = data.config;
    } catch(e) {}

    // 2. Apply Engine Rules
    aeApplyRules();
    
    // 3. Sync all UI toggles
    if (typeof window.aeSyncUI === 'function') window.aeSyncUI();
});

async function aeSaveToServer() {
    await window.sui.api('ae_save_config', { settings: aeState }, { toast: false });
}

window.aeSyncUI = () => {
    const g1 = document.getElementById("ae-gpu-mode");
    const g2 = document.getElementById("tp-ae-gpu-mode");
    if (g1) g1.checked = aeState.forceGPU;
    if (g2) g2.checked = aeState.forceGPU;

    const s1 = document.getElementById("ae-simplify-mode");
    const s2 = document.getElementById("tp-ae-simplify-mode");
    if (s1) s1.checked = aeState.simplify;
    if (s2) s2.checked = aeState.simplify;

    const m1 = document.getElementById("ae-reduce-motion");
    const m2 = document.getElementById("tp-ae-reduce-motion");
    if (m1) m1.checked = aeState.reduceMotion;
    if (m2) m2.checked = aeState.reduceMotion;
};

window.toggleAeGpu = (val) => {
    aeState.forceGPU = val;
    aeApplyRules();
    aeSaveToServer();
    window.aeSyncUI();
};

window.toggleAeSimplify = (val) => {
    aeState.simplify = val;
    aeApplyRules();
    aeSaveToServer();
    window.aeSyncUI();
};

window.toggleAeMotion = (val) => {
    aeState.reduceMotion = val;
    aeApplyRules();
    aeSaveToServer();
    window.aeSyncUI();
};

function aeApplyRules() {
    let style = document.getElementById("ae-engine-css");
    if (!style) {
        style = document.createElement("style");
        style.id = "ae-engine-css";
        document.head.appendChild(style);
    }

    let css = "";

    // --- TIER 1: SIMPLIFY VISUALS (Disable expensive effects) ---
    if (aeState.simplify) {
        css += `
            /* 1. Kill Expensive Filters */
            .top-bar, .selection-bottom-bar, .card, .po-folder-header, .settings-sheet, .shared-menu-overlay, .shared-bottom-sheet {
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
            }
            
            /* 2. Simplified Transparency */
            .top-bar, .selection-bottom-bar {
                background-color: var(--header-bg, #F2F2F7) !important;
                border-bottom: 1px solid rgba(0,0,0,0.1);
            }

            /* 3. Optimize Box Shadows */
            .card:not(.merge-highlight):not(.ai-just-finished):not(.jump-highlight) {
                box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
            }
        `;
    }

    // --- TIER 2: GPU ACCELERATION (Force 60fps) ---
    if (aeState.forceGPU) {
        css += `
            /* Promote the entire scrolling container to a layer instead of each card individually.
               This is much more memory efficient. */
            .scroll-view, .horizontal-viewport, .sb-scroll-container, 
            #folder-manager-list, #folder-move-list, #shared-picker-list {
                transform: translate3d(0,0,0);
                backface-visibility: hidden;
                perspective: 1000px;
            }

            /* Only promote specific cards that are actually animating */
            .card-tapped, 
.ra-anim-out,
.todo-double-bounce,
.settings-overlay.visible .settings-sheet,
.shared-menu-overlay.visible .shared-bottom-sheet {
    will-change: transform, opacity;
    backface-visibility: hidden;
}
                  
            /* Optimize text rendering only when moving to save CPU */
            .is-swiping *, .card-tapped {
                -webkit-font-smoothing: antialiased;
                text-rendering: optimizeSpeed;
            }
        `;
    }

    // --- TIER 2: REDUCE MOTION (For Instant Snappiness) ---
    // This "compiles" animations down to 0s duration effectively.
    if (aeState.reduceMotion) {
        css += `
            *, *::before, *::after {
                animation-duration: 0.01s !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01s !important;
                scroll-behavior: auto !important;
            }
            
            /* Override Specific Plugins that use JS-driven heights */
            .plugin-tray.open { max-height: none !important; transition: none !important; }
            #organizer-bar-wrapper { transition: none !important; }
            .top-bar { transition: none !important; }
            .settings-sheet { transition: none !important; }
        `;
    }

    style.innerHTML = css;
}
JS;
?>