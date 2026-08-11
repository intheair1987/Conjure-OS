<?php
// ==============================================================================
// PLUGIN: Word Counter
// DESCRIPTION: Text Statistics.
// ==============================================================================

$wc_config_file = CJOS_PATH_DATA . '/word-counter-config.json';

// --- LOAD CONFIG ---
$wc_config = ['show_btn' => true, 'show_count' => false];
if (file_exists($wc_config_file)) {
    $loaded = json_decode(file_get_contents($wc_config_file), true);
    if(is_array($loaded)) $wc_config = array_merge($wc_config, $loaded);
}

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'wcnt_save_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $settings = json_decode($_POST['settings'], true);
        
        if (!is_dir(CJOS_PATH_DATA)) mkdir(CJOS_PATH_DATA, 0777, true);
        
        file_put_contents($wc_config_file, json_encode($settings, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($_POST['plugin_action'] === 'wcnt_get_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $config = ['show_btn' => true, 'show_count' => false];
        if (file_exists($wc_config_file)) {
            $loaded = json_decode(file_get_contents($wc_config_file), true);
            if(is_array($loaded)) $config = array_merge($config, $loaded);
        }
        echo json_encode(['status' => 'success', 'config' => $config]);
        exit;
    }
}

// --- PHP: HEADER BUTTON ---
$showBtn = $wc_config['show_btn'] ?? true;
if ($showBtn === true || $showBtn === 'true' || $showBtn === 1 || $showBtn === '1') {
    $plugin_buttons[] = ['stats-btn', '<path d="M10 20h4V4h-4v16zm-6 0h4v-8H4v8zM16 9v11h4V9h-4z"/>', 'showWordStats()', 'Stats', 'secondary'];
}

// --- SETTINGS UI ---
$plugin_settings_map['WordCounter'] = <<<'HTML'
    <div data-sui-setting="Show Header Button" data-sui-desc="Add a stats icon to the top action bar." data-sui-id="plug-wc-btn-toggle" data-sui-onchange="toggleWordCounterSetting('show_btn', this.checked)"></div>
    <div data-sui-setting="Show Count on Cards" data-sui-desc="Display word counts as metadata badges." data-sui-id="plug-show-count" data-sui-onchange="toggleWordCounterSetting('show_count', this.checked)"></div>
    <div id="wc-save-status" style="text-align:right; font-size:11px; color:#8E8E93; padding:0 16px 8px;"></div>
HTML;

// --- DATA BRIDGE ---
$wc_bridge_json = json_encode($wc_config);
$plugin_js .= "\nwindow.__WC_BRIDGE__ = $wc_bridge_json;\n";

// --- JS LOGIC ---
$plugin_js .= <<<'JS'
// Helper: Mixed-language word count (CJK character count + Western word count)
function wcGetWordCount(text) {
    if (!text || text.trim().length === 0) return 0;
    // 1. Count CJK characters (Chinese, Japanese, Korean)
    const cjk = text.match(/[\u4e00-\u9fa5\u3040-\u30ff\uac00-\ud7af]/g) || [];
    // 2. Remove CJK characters and count remaining Western words via whitespace
    const westernText = text.replace(/[\u4e00-\u9fa5\u3040-\u30ff\uac00-\ud7af]/g, ' ');
    const westernWords = westernText.trim().split(/\s+/).filter(w => w.length > 0);
    return cjk.length + westernWords.length;
}

let wcState = {
    show_btn: localStorage.getItem("cjos_wc_show_btn") !== "false", // Default true
    show_count: localStorage.getItem("cjos_wc_show_count") === "true" // Default false
};

window.addEventListener("load", () => {
    fetchWcSettings();
});

async function fetchWcSettings() {
    if (window.__WC_BRIDGE__) {
        wcState = window.__WC_BRIDGE__;
    }
    try {
        const data = await window.sui.api("wcnt_get_config", {}, { toast: false });
        
        if (data) {
            wcState = data.config;
            // Update LocalStorage cache with latest server values
            localStorage.setItem("cjos_wc_show_btn", wcState.show_btn);
            localStorage.setItem("cjos_wc_show_count", wcState.show_count);
        }
        
        // Update UI
        const btnToggle = document.getElementById("plug-wc-btn-toggle");
        if(btnToggle) btnToggle.checked = wcState.show_btn;
        
        const countToggle = document.getElementById("plug-show-count");
        if(countToggle) countToggle.checked = wcState.show_count;
        
        // Trigger re-decoration of all cards via SUI Engine
        if (window.sui && window.sui.decorateCard) {
            document.querySelectorAll(".card").forEach(card => {
                const id = card.querySelector(".custom-checkbox")?.getAttribute("data-id");
                const entry = logs.find(l => l.id === id);
                if(entry) window.sui.decorateCard(card, entry);
            });
        }
        
    } catch(e) { console.error("WC Config Error", e); }
}

window.toggleWordCounterSetting = async function(key, val) {
    wcState[key] = val;
    await saveWcSettings();
    if(key === "show_btn") location.reload(); 
    if(key === "show_count") {
        // Trigger re-decoration of all cards via SUI Engine
        if (window.sui && window.sui.decorateCard) {
            document.querySelectorAll(".card").forEach(card => {
                const id = card.querySelector(".custom-checkbox")?.getAttribute("data-id");
                const entry = logs.find(l => l.id === id);
                if(entry) window.sui.decorateCard(card, entry);
            });
        }
    }
};

// Phase 8: Badge Engine Registration
if (window.sui && window.sui.registerBadge) {
    // Add pointer cursor for the word counter badge
    const style = document.createElement('style');
    style.textContent = '.sui-badge-wc-interactive { cursor: pointer; transition: opacity 0.2s; } .sui-badge-wc-interactive:active { opacity: 0.6; }';
    document.head.appendChild(style);

    window.sui.registerBadge("wc-badge", (entry) => {
        if (!wcState.show_count) return null;
        const count = wcGetWordCount(entry.transcription);
        
        const badge = window.suiBadge(count + " words", "default", "sui-badge-wc-interactive");
        badge.onclick = (e) => {
            e.stopPropagation(); // Prevent card tap triggers
            showWordStats();
        };
        return badge;
    }, 60); // Priority 60
}

async function saveWcSettings() {
    const status = document.getElementById("wc-save-status");
    if(status) status.innerText = "Saving...";

    localStorage.setItem("cjos_wc_show_btn", wcState.show_btn);
    localStorage.setItem("cjos_wc_show_count", wcState.show_count);
    
    try {
        await window.sui.api("wcnt_save_config", { settings: wcState }, { toast: false });
        if(status) {
            status.innerText = "Saved to data/";
            setTimeout(() => status.innerText = "", 2000);
        }
    } catch(e) {
        if(status) status.innerText = "Error saving";
    }
}

function showWordStats() {
    let totalWords = 0;
    let maxWords = 0;
    let count = logs.length;
    
    logs.forEach(l => {
        const words = wcGetWordCount(l.transcription);
        totalWords += words;
        if (words > maxWords) maxWords = words;
    });

    const avg = count > 0 ? Math.round(totalWords / count) : 0;

    const options = [
        { label: "Collection Stats", type: "header" },
        { label: `<div style="display:flex; justify-content:space-between; align-items:center;"><span>Total Vocabulary</span><span style="font-weight:800; color:var(--primary);">${totalWords} words</span></div>`, type: "info" },
        { label: "Performance", type: "header" },
        { label: `<div style="display:flex; justify-content:space-between; align-items:center;"><span>Average Length</span><span style="font-weight:800; color:var(--primary);">${avg} words</span></div>`, type: "info" },
        { label: `<div style="display:flex; justify-content:space-between; align-items:center;"><span>Peak Note Length</span><span style="font-weight:800; color:var(--primary);">${maxWords} words</span></div>`, type: "info" }
    ];

    window.openPicker("Word Statistics", options, null, null);
}
JS;
?>