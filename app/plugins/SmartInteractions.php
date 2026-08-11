<?php
// ==============================================================================
// PLUGIN: Smart Interactions
// DESCRIPTION: Intelligent Gestures.
// ==============================================================================

$si_config_file = CJOS_PATH_DATA . '/smart-interactions-config.json';

// --- LOAD CONFIG (Data Bridge) ---
$si_runtime_config = [
    'sideTap' => true,
    'longPress' => true,
    'hideBtn' => false,
    'autoExit' => false
];
if (file_exists($si_config_file)) {
    $si_loaded = json_decode(file_get_contents($si_config_file), true);
    if (is_array($si_loaded)) $si_runtime_config = array_merge($si_runtime_config, $si_loaded);
}
$si_bridge_json = json_encode($si_runtime_config);
$plugin_js .= "\nwindow.__SI_BRIDGE__ = $si_bridge_json;\n";

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'si_save_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $settings = json_decode($_POST['settings'], true);
        
        if (!is_dir(CJOS_PATH_DATA)) mkdir(CJOS_PATH_DATA, 0777, true);
        
        file_put_contents($si_config_file, json_encode($settings, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($_POST['plugin_action'] === 'si_get_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        // Defaults
        $config = [
            'sideTap' => true,
            'longPress' => true,
            'hideBtn' => false,
            'autoExit' => false
        ];
        
        if (file_exists($si_config_file)) {
            $loaded = json_decode(file_get_contents($si_config_file), true);
            if(is_array($loaded)) $config = array_merge($config, $loaded);
        }
        echo json_encode(['status' => 'success', 'config' => $config]);
        exit;
    }
}

// SETTINGS UI
$plugin_settings_map['SmartInteractions'] = <<<'HTML'
    <div data-sui-setting="Side-Tap Expand" data-sui-desc="Tap left/right edges of a card to toggle 'Read More'." data-sui-id="si-side-tap" data-sui-onchange="toggleSiSetting('sideTap', this.checked)"></div>
    <div data-sui-setting="Long Press Select" data-sui-desc="Hold to select. Range-fills visible items." data-sui-id="si-long-press" data-sui-onchange="toggleSiSetting('longPress', this.checked)"></div>
    <div data-sui-setting="Hide Header Button" data-sui-desc="Remove the check-circle icon from the top bar." data-sui-id="si-hide-btn" data-sui-onchange="toggleSiSetting('hideBtn', this.checked)"></div>
    <div data-sui-setting="Auto-Exit Selection" data-sui-desc="Exit mode when the last item is unselected." data-sui-id="si-auto-exit" data-sui-onchange="toggleSiSetting('autoExit', this.checked)"></div>
    <div id="si-save-status" style="text-align:right; font-size:11px; color:#8E8E93; padding:0 16px 8px;"></div>
HTML;

// JAVASCRIPT LOGIC
$plugin_js .=  <<<'JS'
// --- SMART INTERACTIONS LOGIC ---

let siPrefs = {
    sideTap: true,
    longPress: true,
    hideBtn: false,
    autoExit: false
};

window.addEventListener("load", () => {
    fetchSiSettings();

    // --- MIGRATION: SIDE TAP SUBSCRIBER ---
    if (window.InteractionManager) {
        InteractionManager.subscribe({
            plugin: 'SmartInteractions',
            event: 'onSideTap',
            priority: 50,
            condition: () => siPrefs.sideTap,
            handler: ({ card }) => {
                const cardContent = card.querySelector(".card-content");
                if (!cardContent) return;

                const btn = cardContent.querySelector(".read-more-btn");
                // Only trigger if "Read More" is actually visible
                if (btn && btn.style.display !== "none") {
                    const textDiv = cardContent.querySelector(".transcription");
                    
                    if (textDiv.classList.contains("truncated")) {
                        // EXPAND
                        textDiv.classList.remove("truncated");
                        btn.textContent = "Show less";
                    } else {
                        // COLLAPSE
                        textDiv.classList.add("truncated");
                        btn.textContent = "Read more";
                        
                        // Scroll Logic: If header is above view, scroll it down
                        const cardTop = card.offsetTop;
                        const scrollContainer = document.getElementById("main-scroll");
                        if (scrollContainer && scrollContainer.scrollTop > cardTop) {
                            scrollContainer.scrollTo({ top: cardTop - 80, behavior: "smooth" });
                        }
                    }
                }
            }
        });

        // B. LONG PRESS (Selection Mode / Range Fill)
        InteractionManager.subscribe({
            plugin: 'SmartInteractions',
            event: 'onLongPress',
            priority: 50,
            condition: () => siPrefs.longPress,
            handler: ({ card, context, vibrate }) => {
                vibrate('heavy');
                
                if (!context.isSelectMode) {
                    // 1. Enter Selection Mode
cjosToggleSelectMode(true);// 2. Select this card
                    const cb = card.querySelector('.custom-checkbox');
                    if (cb) {
                        cb.classList.add('checked');
                        // Update Anchor History
                        window.InteractionManager.setAnchor(cb.getAttribute('data-id'));
                        
                        // Sync with SequentialCopy (Legacy)
                        if (typeof selectionSequence !== 'undefined') {
                            if (!selectionSequence.includes(cb.getAttribute('data-id'))) {
                                selectionSequence.push(cb.getAttribute('data-id'));
                            }
                            if (typeof renderSequenceBadges === 'function') renderSequenceBadges();
                        }
                        if (window.updateSelectionCount) window.updateSelectionCount();
                    }
                } else {
                    // 3. Range Fill Logic (Exclude visually hidden stacked cards)
                    const allVisible = Array.from(document.querySelectorAll('.card')).filter(c => 
                        c.style.display !== 'none' && 
                        !c.classList.contains('search-hidden') && 
                        !c.classList.contains('is-stacked-hidden')
                    );
                    
                    const targetIdx = allVisible.indexOf(card);
                    let anchorIdx = -1;

                    // Priority 1: Use the Last Interacted Card as Anchor
                    if (context.lastAnchorId) {
                        anchorIdx = allVisible.findIndex(c => c.querySelector('.custom-checkbox')?.getAttribute('data-id') === context.lastAnchorId);
                    }

                    // Priority 2: Fallback to Nearest if anchor is missing or filtered out
                    if (anchorIdx === -1) {
                        allVisible.forEach((c, i) => {
                            if (c !== card && c.querySelector('.custom-checkbox.checked')) {
                                if (anchorIdx === -1 || Math.abs(i - targetIdx) < Math.abs(anchorIdx - targetIdx)) {
                                    anchorIdx = i;
                                }
                            }
                        });
                    }

                    if (anchorIdx !== -1) {
                        // Directional Range Fill (Preserves Sequential Order)
                        const step = targetIdx > anchorIdx ? 1 : -1;
                        let currentIdx = anchorIdx;
                        
                        while (true) {
                            if (currentIdx < 0 || currentIdx >= allVisible.length) break;
                            
                            const cb = allVisible[currentIdx].querySelector('.custom-checkbox');
                            if (cb && !cb.classList.contains('checked')) {
                                cb.classList.add('checked');
                                // Sync with SequentialCopy
                                if (typeof selectionSequence !== 'undefined') {
                                    const id = cb.getAttribute('data-id');
                                    if (!selectionSequence.includes(id)) selectionSequence.push(id);
                                }
                            }
                            
                            if (currentIdx === targetIdx) break;
                            currentIdx += step;
                        }
                        
                        if (typeof renderSequenceBadges === 'function') renderSequenceBadges();
                        if (window.updateSelectionCount) window.updateSelectionCount();

                        // Sync range-fill selections with the active bucket state and update the action bar counters
                        if (typeof window.seqBuckets !== 'undefined' && typeof window.seqActiveBucketId !== 'undefined') {
                            window.seqBuckets[window.seqActiveBucketId].sequence = window.selectionSequence;
                        }
                        if (typeof window.seqRenderBucketUI === 'function') window.seqRenderBucketUI();
                        if (typeof window.seqSaveState === 'function') window.seqSaveState();
                    }
                }
            }
        });

        // C. AUTO-EXIT LOGIC
        InteractionManager.subscribe({
            plugin: 'SmartInteractions',
            event: 'onSelectionChange',
            priority: 90,
            condition: () => siPrefs.autoExit,
            handler: ({ context }) => {
                // Logic: Exit if count is 0 and we aren't drafting
                if (context.isDrafting) return;
                
                const count = document.querySelectorAll('.custom-checkbox.checked').length;
                if (count === 0) {
                    setTimeout(() => cjosToggleSelectMode(false), 50);
                }
            }
        });
    }
});

async function fetchSiSettings() {
    if (window.__SI_BRIDGE__) {
        siPrefs = window.__SI_BRIDGE__;
    }
    try {
        const data = await window.sui.api("si_get_config", {}, { toast: false });
        if (data) {
            siPrefs = data.config;
        } else if (!window.__SI_BRIDGE__) {
            // Local Migration
            const st = localStorage.getItem("cjos_si_side_tap");
            const lp = localStorage.getItem("cjos_si_long_press");
            const hb = localStorage.getItem("cjos_si_hide_btn");
            const ae = localStorage.getItem("cjos_si_auto_exit");
            
            if (st !== null) siPrefs.sideTap = (st !== "false");
            if (lp !== null) siPrefs.longPress = (lp !== "false");
            if (hb !== null) siPrefs.hideBtn = (hb === "true");
            if (ae !== null) siPrefs.autoExit = (ae === "true");
            
            saveSiSettings(); // Sync
        }
        
        // Apply UI
        const inputSide = document.getElementById("si-side-tap");
        const inputLong = document.getElementById("si-long-press");
        const inputHide = document.getElementById("si-hide-btn");
        const inputAuto = document.getElementById("si-auto-exit");
        
        if(inputSide) inputSide.checked = siPrefs.sideTap;
        if(inputLong) inputLong.checked = siPrefs.longPress;
        if(inputHide) inputHide.checked = siPrefs.hideBtn;
        if(inputAuto) inputAuto.checked = siPrefs.autoExit;

        applyHideBtnState();
        
    } catch(e) { console.error("SI Config Error", e); }
}

window.toggleSiSetting = function(key, val) {
    siPrefs[key] = val;
    saveSiSettings();
    if (key === "hideBtn") applyHideBtnState();
};

async function saveSiSettings() {
    await window.sui.api("si_save_config", { settings: siPrefs }, { toast: "Interaction Settings Saved" });
}

function applyHideBtnState() {
    const btn = document.getElementById("select-btn");
    if(btn) {
        btn.style.display = siPrefs.hideBtn ? "none" : "flex";
    }
    // Sync to cookie for PHP to read on next refresh (prevents flash)
    document.cookie = "cjos_hide_select_btn=" + siPrefs.hideBtn + "; path=/; max-age=31536000";
}
      

// 4. CSS INJECTION FOR STABILITY
const siStyle = document.createElement("style");
siStyle.innerHTML = `
    .card {
        -webkit-touch-callout: none !important;
        -webkit-user-select: none !important;
        user-select: none !important;
        touch-action: pan-x pan-y !important; 
    }
`;
document.head.appendChild(siStyle);

// Legacy logic removed - Migrated to InteractionManager subscriptions

// 8. SIDE TAP LOGIC REMOVED (Migrated to InteractionManager Subscription)
JS;
?>