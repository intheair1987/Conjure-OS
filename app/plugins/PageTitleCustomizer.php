<?php
// ==============================================================================
// PLUGIN: Page Title Customizer
// DESCRIPTION: Header Title Styles.
// ==============================================================================

$pt_config_file = CJOS_PATH_DATA . '/page-title-config.json';

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    
    // SAVE CONFIG
    if ($_POST['plugin_action'] === 'pt_save_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $payload = json_decode($_POST['settings'], true);
        
        // Ensure data dir exists
        $dir = dirname($pt_config_file);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        file_put_contents($pt_config_file, json_encode($payload, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET CONFIG
    if ($_POST['plugin_action'] === 'pt_get_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $config = ['selectedStyle' => 'default', 'align' => 'left'];
        if (file_exists($pt_config_file)) {
            $loaded = json_decode(file_get_contents($pt_config_file), true);
            if(is_array($loaded)) $config = array_merge($config, $loaded);
        }
        
        echo json_encode(['status' => 'success', 'config' => $config]);
        exit;
    }
}

// --- SETTINGS UI ---
$plugin_settings_map['PageTitleCustomizer'] = <<<'HTML'
    <!-- STYLE SELECTOR -->
    <div class="setting-item vertical">
        <label class="setting-label">Page Title Style</label>
        
        <div class="style-selector-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;">
            
            <!-- 1. HEM LABEL -->
            <label class="style-option" style="cursor:pointer; border:1px solid var(--border-color); border-radius:8px; padding:10px; display:flex; flex-direction:column; background:var(--card-bg); height:100%; box-sizing:border-box;">
                <div style="display:flex; align-items:center; margin-bottom:6px;">
                    <input type="radio" name="pt_style_select" value="label" style="transform:scale(1.1); margin-right:8px;">
                    <strong style="font-size:13px; color:var(--text-primary);">Hem Label</strong>
                </div>
                <span style="font-size:11px; color:var(--text-secondary); line-height:1.3;">Red tag sticking to the header seam.</span>
            </label>

            <!-- 2. EDITORIAL BAR -->
            <label class="style-option" style="cursor:pointer; border:1px solid var(--border-color); border-radius:8px; padding:10px; display:flex; flex-direction:column; background:var(--card-bg); height:100%; box-sizing:border-box;">
                <div style="display:flex; align-items:center; margin-bottom:6px;">
                    <input type="radio" name="pt_style_select" value="modern" style="transform:scale(1.1); margin-right:8px;">
                    <strong style="font-size:13px; color:var(--text-primary);">Editorial</strong>
                </div>
                <span style="font-size:11px; color:var(--text-secondary); line-height:1.3;">Clean text with accent line.</span>
            </label>

            <!-- 3. SOFT CAPSULE -->
            <label class="style-option" style="cursor:pointer; border:1px solid var(--border-color); border-radius:8px; padding:10px; display:flex; flex-direction:column; background:var(--card-bg); height:100%; box-sizing:border-box;">
                <div style="display:flex; align-items:center; margin-bottom:6px;">
                    <input type="radio" name="pt_style_select" value="capsule" style="transform:scale(1.1); margin-right:8px;">
                    <strong style="font-size:13px; color:var(--text-primary);">Capsule</strong>
                </div>
                <span style="font-size:11px; color:var(--text-secondary); line-height:1.3;">Rounded gray pill shape.</span>
            </label>

            <!-- 4. BRUTALIST POP -->
            <label class="style-option" style="cursor:pointer; border:1px solid var(--border-color); border-radius:8px; padding:10px; display:flex; flex-direction:column; background:var(--card-bg); height:100%; box-sizing:border-box;">
                <div style="display:flex; align-items:center; margin-bottom:6px;">
                    <input type="radio" name="pt_style_select" value="brutalist" style="transform:scale(1.1); margin-right:8px;">
                    <strong style="font-size:13px; color:var(--text-primary);">Brutalist</strong>
                </div>
                <span style="font-size:11px; color:var(--text-secondary); line-height:1.3;">Hard borders and solid shadow.</span>
            </label>

            <!-- 5. NEON GRADIENT -->
            <label class="style-option" style="cursor:pointer; border:1px solid var(--border-color); border-radius:8px; padding:10px; display:flex; flex-direction:column; background:var(--card-bg); height:100%; box-sizing:border-box;">
                <div style="display:flex; align-items:center; margin-bottom:6px;">
                    <input type="radio" name="pt_style_select" value="gradient" style="transform:scale(1.1); margin-right:8px;">
                    <strong style="font-size:13px; color:var(--text-primary);">Neon</strong>
                </div>
                <span style="font-size:11px; color:var(--text-secondary); line-height:1.3;">Vibrant text color gradient.</span>
            </label>

            <!-- 6. HIGHLIGHTER -->
            <label class="style-option" style="cursor:pointer; border:1px solid var(--border-color); border-radius:8px; padding:10px; display:flex; flex-direction:column; background:var(--card-bg); height:100%; box-sizing:border-box;">
                <div style="display:flex; align-items:center; margin-bottom:6px;">
                    <input type="radio" name="pt_style_select" value="marker" style="transform:scale(1.1); margin-right:8px;">
                    <strong style="font-size:13px; color:var(--text-primary);">Marker</strong>
                </div>
                <span style="font-size:11px; color:var(--text-secondary); line-height:1.3;">Yellow highlight background.</span>
            </label>

            <!-- 7. TECH TERMINAL -->
            <label class="style-option" style="cursor:pointer; border:1px solid var(--border-color); border-radius:8px; padding:10px; display:flex; flex-direction:column; background:var(--card-bg); height:100%; box-sizing:border-box;">
                <div style="display:flex; align-items:center; margin-bottom:6px;">
                    <input type="radio" name="pt_style_select" value="terminal" style="transform:scale(1.1); margin-right:8px;">
                    <strong style="font-size:13px; color:var(--text-primary);">Terminal</strong>
                </div>
                <span style="font-size:11px; color:var(--text-secondary); line-height:1.3;">Dark box with code font.</span>
            </label>

             <!-- 8. DEFAULT -->
            <label class="style-option" style="cursor:pointer; border:1px solid var(--border-color); border-radius:8px; padding:10px; display:flex; flex-direction:column; background:var(--card-bg); height:100%; box-sizing:border-box;">
                <div style="display:flex; align-items:center; margin-bottom:6px;">
                    <input type="radio" name="pt_style_select" value="default" style="transform:scale(1.1); margin-right:8px;">
                    <strong style="font-size:13px; color:var(--text-primary);">Default</strong>
                </div>
                <span style="font-size:11px; color:var(--text-secondary); line-height:1.3;">Original theme style.</span>
            </label>

        </div>
    </div>

    <!-- ALIGNMENT CONTROLS -->
    <div class="setting-item vertical" style="margin-top:20px;">
        <label class="setting-label">Alignment</label>
        <div style="display:flex; background:var(--btn-bg); border-radius:10px; padding:2px; margin-top:8px;">
            <button onclick="setPtAlign('left')" id="pt-align-left" style="flex:1; border:none; background:white; border-radius:8px; padding:8px; cursor:pointer; font-size:14px; color:var(--text-primary); box-shadow:0 1px 3px rgba(0,0,0,0.1); transition:all 0.2s;">Left</button>
            <button onclick="setPtAlign('center')" id="pt-align-center" style="flex:1; border:none; background:transparent; border-radius:8px; padding:8px; cursor:pointer; font-size:14px; color:var(--text-secondary); transition:all 0.2s;">Center</button>
            <button onclick="setPtAlign('right')" id="pt-align-right" style="flex:1; border:none; background:transparent; border-radius:8px; padding:8px; cursor:pointer; font-size:14px; color:var(--text-secondary); transition:all 0.2s;">Right</button>
        </div>
    </div>
    <div style="padding:10px; text-align:right; font-size:12px; color:#8E8E93;" id="pt-save-status">Settings saved to data/</div>
HTML;

// --- JS LOGIC ---
$plugin_js .= <<<'JS'
// --- HELPER: AUTO MARGINS ---
const getMarginCSS = (align) => {
    if(align === "left") return "margin-left: 0 !important; margin-right: auto !important;";
    if(align === "center") return "margin-left: auto !important; margin-right: auto !important;";
    if(align === "right") return "margin-left: auto !important; margin-right: 0 !important;";
    return "";
};

const getBlockAlignCSS = (align) => {
    return `width: 100% !important; display: block !important; text-align: ${align} !important;`;
};

const ptStyleGenerators = {
    // 1. HEM LABEL
    label: (align) => {
        let posCSS = "";
        if(align === "left") {
            posCSS = "left: 0 !important; margin-left: -20px !important; border-radius: 0 4px 4px 0 !important; right: auto !important; transform: none !important;";
        } else if (align === "center") {
            posCSS = "left: 50% !important; transform: translateX(-50%) !important; border-radius: 0 0 4px 4px !important; right: auto !important; margin-left: 0 !important;";
        } else if (align === "right") {
            posCSS = "right: 0 !important; margin-right: -20px !important; border-radius: 4px 0 0 4px !important; left: auto !important; transform: none !important;";
        }
        return `
            .page-title {
                position: absolute !important;
                top: calc(var(--header-base-height) + 12px) !important;
                ${posCSS}
                display: inline-block !important;
                width: fit-content !important;
                background-color: #D32F2F !important;
                color: #ffffff !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
                font-size: 11px !important;
                font-weight: 800 !important;
                text-transform: uppercase !important;
                letter-spacing: 1.5px !important;
                padding: 6px 16px !important;
                box-shadow: 2px 2px 5px rgba(0,0,0,0.15) !important;
                z-index: 100 !important;
                line-height: 1.4 !important;
                margin-top: 0 !important; margin-bottom: 0 !important;
                transition: top 0.4s cubic-bezier(0.16, 1, 0.3, 1), transform 0.2s !important;
            }
            body.header-collapsed .page-title {
                top: calc(var(--header-collapsed-height) + 12px) !important;
            }
        `;
    },
    // 2. EDITORIAL BAR
    modern: (align) => {
        let borderCSS = "";
        if(align === "left")   borderCSS = "border-left: 4px solid #1C1C1E !important; padding-left: 16px !important;";
        if(align === "center") borderCSS = "border-bottom: 4px solid #1C1C1E !important; padding-bottom: 8px !important; display:inline-block !important; width:auto !important; margin-left:auto !important; margin-right:auto !important;";
        if(align === "right")  borderCSS = "border-right: 4px solid #1C1C1E !important; padding-right: 16px !important;";
        
        if(align === "center") {
             return `
                .page-title {
                    display: table !important; 
                    ${getMarginCSS("center")}
                    font-family: "Georgia", serif !important;
                    font-size: 28px !important;
                    font-weight: 400 !important;
                    color: #1a1a1a !important;
                    background: transparent !important;
                    text-transform: capitalize !important;
                    ${borderCSS}
                }
            `;
        } else {
             return `
                .page-title {
                    ${getBlockAlignCSS(align)}
                    font-family: "Georgia", serif !important;
                    font-size: 28px !important;
                    font-weight: 400 !important;
                    color: #1a1a1a !important;
                    background: transparent !important;
                    text-transform: capitalize !important;
                    margin: 0 !important;
                    ${borderCSS}
                }
            `;
        }
    },
    // 3. CAPSULE
    capsule: (align) => {
        return `
            .page-title {
                display: table !important;
                ${getMarginCSS(align)}
                background-color: #F2F2F7 !important;
                color: #636366 !important;
                padding: 8px 24px !important;
                border-radius: 50px !important;
                font-family: -apple-system, sans-serif !important;
                font-size: 14px !important;
                font-weight: 600 !important;
                border: 1px solid #E5E5EA !important;
                width: fit-content !important;
            }
        `;
    },
    // 4. BRUTALIST
    brutalist: (align) => {
        return `
             .page-title {
                display: table !important;
                ${getMarginCSS(align)}
                background-color: #FFFFFF !important;
                color: #000000 !important;
                padding: 10px 20px !important;
                border: 2px solid #000000 !important;
                box-shadow: 4px 4px 0px #000000 !important;
                font-family: "Courier New", Courier, monospace !important;
                font-weight: 700 !important;
                font-size: 20px !important;
                text-transform: uppercase !important;
                width: fit-content !important;
                margin-bottom: 10px !important;
            }
        `;
    },
    // 5. NEON
    gradient: (align) => {
        return `
            .page-title {
                display: table !important;
                ${getMarginCSS(align)}
                background: linear-gradient(to right, #6a11cb 0%, #2575fc 100%) !important;
                -webkit-background-clip: text !important;
                -webkit-text-fill-color: transparent !important;
                font-family: -apple-system, sans-serif !important;
                font-weight: 800 !important;
                font-size: 32px !important;
                width: fit-content !important;
            }
        `;
    },
    // 6. MARKER
    marker: (align) => {
        return `
            .page-title {
                display: table !important;
                ${getMarginCSS(align)}
                background-color: #fff133 !important;
                color: #000 !important;
                padding: 4px 10px !important;
                font-family: "Georgia", serif !important;
                font-style: italic !important;
                font-weight: 600 !important;
                font-size: 28px !important;
                width: fit-content !important;
            }
        `;
    },
    // 7. TERMINAL
    terminal: (align) => {
        return `
            .page-title {
                display: table !important;
                ${getMarginCSS(align)}
                background-color: #1e1e1e !important;
                color: #00ff41 !important;
                padding: 12px 18px !important;
                font-family: "Courier New", monospace !important;
                font-size: 16px !important;
                border-radius: 6px !important;
                border: 1px solid #333 !important;
                width: fit-content !important;
            }
            .page-title::before { content: "> "; opacity: 0.6; }
        `;
    },
    // 8. DEFAULT
    default: (align) => `
        .page-title { ${getBlockAlignCSS(align)} }
    ` 
};

let ptState = { selectedStyle: "default", align: "left" };

// Init
window.addEventListener("load", () => {
    fetchPtSettings();
});

async function fetchPtSettings() {
    try {
        const data = await window.sui.api("pt_get_config", {}, { toast: false });
        if (data) {
            ptState = data.config;
            applyPtUI();
            applyPtStyles();
        } else {
            // Fallback to local if server fails (Migration check)
            const local = localStorage.getItem("cjos_pt_settings_v3");
            if (local) {
                ptState = JSON.parse(local);
                savePtSettings(); // Migrate to server
            }
            applyPtUI();
            applyPtStyles();
        }
    } catch(e) { 
        console.error("PT Init Error", e);
        applyPtUI();
        applyPtStyles();
    }
}

function applyPtUI() {
    // 1. Radios
    const radios = document.getElementsByName("pt_style_select");
    if(radios.length > 0) {
        for(const r of radios) {
            r.checked = (r.value === ptState.selectedStyle);
            // Bind listener if not already
            r.onchange = (e) => {
                if(e.target.checked) {
                    ptState.selectedStyle = e.target.value;
                    savePtSettings();
                    updateUIHighlight(radios);
                }
            };
        }
        updateUIHighlight(radios);
    }
    // 2. Alignment Buttons
    updatePtAlignUI();
}

function updateUIHighlight(radios) {
    for(const r of radios) {
        const container = r.parentElement;
        if(r.checked) {
            container.style.borderColor = "var(--primary)";
            container.style.backgroundColor = "var(--selected-bg)";
            container.style.boxShadow = "0 2px 5px rgba(0,0,0,0.05)";
        } else {
            container.style.borderColor = "var(--border-color)";
            container.style.backgroundColor = "var(--input-bg)";
            container.style.boxShadow = "none";
        }
    }
}

window.setPtAlign = function(align) {
    ptState.align = align;
    updatePtAlignUI();
    savePtSettings();
}

function updatePtAlignUI() {
    ["left", "center", "right"].forEach(dir => {
        const btn = document.getElementById("pt-align-" + dir);
        if(btn) {
            if(dir === ptState.align) {
                btn.style.background = "var(--card-bg)";
                btn.style.color = "var(--text-primary)";
                btn.style.boxShadow = "var(--shadow-card)";
            } else {
                btn.style.background = "transparent";
                btn.style.color = "var(--text-secondary)";
                btn.style.boxShadow = "none";
            }
        }
    });
}

function applyPtStyles() {
    let styleTag = document.getElementById("pt-custom-style");
    if (!styleTag) {
        styleTag = document.createElement("style");
        styleTag.id = "pt-custom-style";
        document.head.appendChild(styleTag);
    }
    const generator = ptStyleGenerators[ptState.selectedStyle] || ptStyleGenerators.default;
    styleTag.innerHTML = generator(ptState.align);
    document.body.setAttribute('data-pt-align', ptState.align);
}

async function savePtSettings() {
    // Update local cache immediately
    applyPtStyles();
    await window.sui.api("pt_save_config", { settings: ptState }, { toast: "Title Styles Saved" });
}
JS;
?>