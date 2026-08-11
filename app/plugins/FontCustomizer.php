<?php
// ==============================================================================
// PLUGIN: Font Customizer
// DESCRIPTION: App Typography.
// Allows users to select and preview Google Fonts for the entire application.
// Saves configuration to data/font-config.json.
// FIXED: Expanded CSS selectors to override hardcoded styles in To-Do and Widgets.
// ==============================================================================

$font_config_file = CJOS_PATH_DATA . '/font-config.json';

// --- 1. LOAD SAVED FONT ---
$selected_font = "Default";
if (file_exists($font_config_file)) {
    $font_data = json_decode(file_get_contents($font_config_file), true);
    if (!empty($font_data['font_family'])) {
        $selected_font = $font_data['font_family'];
    }
}

// --- DATA BRIDGE ---
$fc_bridge_json = json_encode(['selected_font' => $selected_font]);
$plugin_js .= "\nwindow.__FC_BRIDGE__ = $fc_bridge_json;\n";

// --- 2. BACKEND HANDLERS ---
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'font_save_config') {
    error_reporting(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $font = $_POST['font_family'] ?? 'Default';
    $data = ['font_family' => $font];
    
    $dir = dirname($font_config_file);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    
    file_put_contents($font_config_file, json_encode($data, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success']);
    exit;
}

// --- 3. SETTINGS UI ---
$plugin_settings_map['FontCustomizer'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">System Font</label>
        <div class="setting-desc">Change the typography of the entire application.</div>
        
        <div style="display:flex; gap:10px; margin-top:8px;">
            <button onclick="openFontPicker()" class="text-btn" style="
                flex:1; background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; 
                padding:12px; font-weight:600; color:var(--input-text); text-align:left;
                display:flex; justify-content:space-between; align-items:center;
            ">
                <span id="fc-current-font-label">Loading...</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:16px; opacity:0.5; stroke-width:2.5;"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            
            <button onclick="saveFontSelection('Default')" class="icon-btn" style="background:var(--btn-bg); width:44px; height:44px; border-radius:12px; color:var(--text-primary);" title="Reset to Default">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
            </button>
        </div>
    </div>
HTML;

// --- 4. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
(function() {
    const fcBridge = window.__FC_BRIDGE__ || { selected_font: 'Default' };
    const activeFont = fcBridge.selected_font;

    // --- 1. IMMEDIATE INJECTION ---
    window.addEventListener('DOMContentLoaded', () => {
        const label = document.getElementById('fc-current-font-label');
        if (label) label.innerText = activeFont;
    });
    if (activeFont !== "Default") {
        const fontUrl = activeFont.replace(/ /g, '+');
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=' + fontUrl + ':wght@400;500;700&display=swap';
        document.head.appendChild(link);

        const style = document.createElement('style');
        style.id = "fc-active-style";
        style.textContent = `
            :root { --app-font: "${activeFont}", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            body, button, input, textarea, select, .bar-title, .page-title, .transcription, .section-header, 
            .todo-text, .todo-list-wrap div, #todo-pinned-wrapper div, #pp-markdown-body, #pp-markdown-body *,
            #aichat-sidebar *, #aichat-container *, .aichat-bubble *, #aichat-scroll-view *, 
            .shared-menu-overlay *, .shared-bottom-sheet *, .picker-item *:not(.fc-pvr), .aichat-floating-controls * { 
                font-family: var(--app-font) !important; 
            }
            .page-title { font-style: normal; }
            svg, .app-logo, [data-sui-icon] svg, .icon-btn svg, .bar-action-btn svg, .player-btn svg { 
                font-family: initial !important; 
            }
            .aichat-bubble pre, .aichat-bubble code, code, pre { font-family: 'Courier New', monospace !important; }
        `;
        document.head.appendChild(style);
    }

    // --- 2. PICKER LOGIC ---
    const fcPopularFonts = [
        "Inter", "Roboto", "Open Sans", "Lato", "Montserrat", 
        "Playfair Display", "Merriweather", "Lora", "PT Serif",
        "Oswald", "Raleway", "Ubuntu", "Poppins", "Nunito",
        "Quicksand", "Work Sans", "Fira Code", "JetBrains Mono",
        "Space Grotesk", "Lexend", "Fraunces", "Outfit", "Cabin", "Kanit"
    ];

    window.openFontPicker = function() {
        const options = [
            { label: "System Default", value: "Default" },
            { label: "Google Fonts", type: "header" }
        ];

        fcPopularFonts.sort().forEach(font => {
            if (!document.querySelector('link[href*="' + font.replace(/ /g, '+') + '"]')) {
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://fonts.googleapis.com/css2?family=' + font.replace(/ /g, '+') + '&display=swap';
                document.head.appendChild(link);
            }

            options.push({
                label: `
                    <div class="fc-pvr" style="font-family: '${font}', sans-serif !important;">
                        <div class="fc-pvr" style="font-size: 17px; font-weight: 600; font-family: inherit !important;">${font}</div>
                        <div class="fc-pvr" style="font-size: 11px; opacity: 0.6; margin-top: 2px; font-family: inherit !important;">The quick brown fox jumps over the lazy dog.</div>
                    </div>
                `,
                value: font
            });
        });

        window.openPicker("Select System Font", options, activeFont, (val) => {
            saveFontSelection(val);
        }, true);
    };

    window.closeFontPicker = () => window.closeSharedPicker();

    window.saveFontSelection = async function(family) {
        const label = document.getElementById('fc-current-font-label');
        if(label) label.innerText = family;
        
        try {
            await window.sui.api('font_save_config', { font_family: family }, { toast: false });
            window.closeSharedPicker();
            location.reload();
        } catch(e) {}
    };
})();
JS;
?>