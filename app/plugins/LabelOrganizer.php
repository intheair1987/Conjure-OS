<?php
// ==============================================================================
// PLUGIN: Label Organizer
// DESCRIPTION: Badge Display Order.
// Automatically detects meta-badges in card footers and allows reordering.
// Saves configuration to data/label-organizer-config.json.
// ==============================================================================

$lo_config_file = CJOS_PATH_DATA . '/label-organizer-config.json';

// --- 1. BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'lo_save_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $order = json_decode($_POST['order'], true);
        file_put_contents($lo_config_file, json_encode(['order' => $order], JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($_POST['plugin_action'] === 'lo_get_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $data = ['order' => []];
        if (file_exists($lo_config_file)) {
            $data = json_decode(file_get_contents($lo_config_file), true);
        }
        echo json_encode(['status' => 'success', 'config' => $data]);
        exit;
    }
}

// --- 2. SETTINGS UI ---
$plugin_settings_map['LabelOrganizer'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Label Display Order</label>
        <div class="setting-desc">Drag labels to change their horizontal position on cards.</div>
        
        <div id="lo-list-container" style="margin-top:12px; display:flex; flex-direction:column; gap:8px;">
            <div style="text-align:center; padding:20px; color:#8E8E93; font-size:13px;">Scanning for active labels...</div>
        </div>

        <button onclick="loScanAndRefresh()" class="text-btn" style="width:100%; margin-top:12px; background:var(--btn-bg); border:1px solid var(--border-color); color:var(--text-primary); border-radius:10px; padding:10px; font-weight:600;">
            🔄 Refresh Detected Labels
        </button>
        
        <div id="lo-save-status" style="text-align:right; font-size:11px; color:#8E8E93; margin-top:8px; height:14px;"></div>
    </div>
HTML;

// --- 3. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- LABEL ORGANIZER JS ---

// Initialize from LocalStorage for instant, jump-free rendering
window.loSavedOrder = JSON.parse(localStorage.getItem("cjos_lo_order") || "[]");

// Apply cached styles immediately before page load
if (loSavedOrder.length > 0) {
    loApplyStyles();
}
window.loKnownLabels = {
    "folder-badge": { label: "📂 Folder Name", color: "var(--btn-bg)" },
    "todo-label-badge": { label: "✅ To-Do List", color: "var(--warn-bg)" },
    "ai-badge": { label: "✨ AI", color: "var(--ai-accent-bg)" },
    "ai-asst-badge": { label: "🤖 Robots", color: "var(--ai-accent-bg)" },
    "wc-badge": { label: "📝 Word Count", color: "var(--btn-bg)" },
    "manual-edited-badge": { label: "✎ Edited", color: "var(--btn-bg)" },
    "merged-badge": { label: "⑂ Merged", color: "var(--btn-bg)" }
};

window.addEventListener("load", () => {
    loLoadAndApply();
});

async function loLoadAndApply() {
    try {
        const data = await window.sui.api("lo_get_config", {}, { toast: false });
        if (data) {
            const newOrder = data.config.order || [];
            
            // Only re-apply if the server order differs from our local cache
            if (JSON.stringify(newOrder) !== JSON.stringify(loSavedOrder)) {
                loSavedOrder = newOrder;
                localStorage.setItem("cjos_lo_order", JSON.stringify(loSavedOrder));
                loApplyStyles();
            }
            loRenderSettings();
        }
    } catch(e) { console.error("LO Load Error", e); }
}

function loApplyStyles() {
    let styleTag = document.getElementById("lo-dynamic-style");
    if (!styleTag) {
        styleTag = document.createElement("style");
        styleTag.id = "lo-dynamic-style";
        document.head.appendChild(styleTag);
    }

    let css = ".card-meta-row { display: flex !important; }\n";
    loSavedOrder.forEach((className, index) => {
        css += `.${className} { order: ${index} !important; }\n`;
    });
    styleTag.innerHTML = css;
}

window.loScanAndRefresh = function() {
    // Scan DOM for any meta-badges to find classes
    const badges = document.querySelectorAll(".meta-badge");
    badges.forEach(b => {
        // Find the specific class (the one that isn't 'meta-badge')
        const specificClass = Array.from(b.classList).find(c => c !== "meta-badge" && c.endsWith("-badge"));
        if (specificClass && !loSavedOrder.includes(specificClass)) {
            loSavedOrder.push(specificClass);
        }
    });
    loRenderSettings();
    loSaveConfig();
};

function loRenderSettings() {
    const cont = document.getElementById("lo-list-container");
    if (!cont) return;
    cont.innerHTML = "";

    if (loSavedOrder.length === 0) {
        cont.innerHTML = `<div style="text-align:center; padding:20px; color:#8E8E93; font-size:13px;">No labels detected yet. Try refreshing.</div>`;
        return;
    }

    loSavedOrder.forEach((className, idx) => {
        const info = loKnownLabels[className] || { label: className, color: "var(--input-bg)" };
        const item = document.createElement("div");
        item.style.cssText = `background:var(--card-bg); color:var(--text-primary); border:1px solid var(--border-color); border-radius:12px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 2px 4px rgba(0,0,0,0.02);`;
        
        item.innerHTML = `
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:24px; height:24px; background:${info.color}; border:1px solid rgba(0,0,0,0.05); border-radius:6px;"></div>
                <span style="font-weight:600; font-size:14px;">${info.label}</span>
            </div>
            <div style="display:flex; gap:4px;">
                <button onclick="loMove(${idx}, -1)" class="po-btn" style="width:32px; height:32px;">↑</button>
                <button onclick="loMove(${idx}, 1)" class="po-btn" style="width:32px; height:32px;">↓</button>
            </div>
        `;
        cont.appendChild(item);
    });
}

window.loMove = function(idx, dir) {
    const target = idx + dir;
    if (target < 0 || target >= loSavedOrder.length) return;
    const temp = loSavedOrder[idx];
    loSavedOrder[idx] = loSavedOrder[target];
    loSavedOrder[target] = temp;
    
    loRenderSettings();
    loApplyStyles();
    loSaveConfig();
};

async function loSaveConfig() {
    const status = document.getElementById("lo-save-status");
    if(status) status.innerText = "Saving...";
    
    // Update local cache immediately
    localStorage.setItem("cjos_lo_order", JSON.stringify(loSavedOrder));

    try {
        await window.sui.api("lo_save_config", { order: loSavedOrder }, { toast: false });
        if(status) {
            status.innerText = "Order Saved";
            setTimeout(() => status.innerText = "", 2000);
        }
    } catch(e) { if(status) status.innerText = "Error"; }
}
JS;
?>