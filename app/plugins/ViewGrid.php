<?php
// ==============================================================================
// PLUGIN: View Grid
// Turns the main list into a 2-column masonry layout.
// ==============================================================================

$plugin_settings_map['ViewGrid'] = '
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Grid View</label>
            <span class="setting-desc">Display entries in a 2-column masonry layout.</span>
        </div>
        <label class="switch">
            <input type="checkbox" id="vg-toggle" onchange="toggleGridView(this.checked)">
            <span class="slider"></span>
        </label>
    </div>
';

$plugin_js .=  <<<'JS'

// --- VIEW GRID JS ---

window.toggleGridView = function(enabled) {
    localStorage.setItem("cjos_view_grid", enabled);
    applyGridView(enabled);
};

function applyGridView(enabled) {
    let style = document.getElementById("vg-style");
    if (!style) {
        style = document.createElement("style");
        style.id = "vg-style";
        document.head.appendChild(style);
    }

    if (enabled) {
        style.innerHTML = `
            #entries-container {
                column-count: 2;
                column-gap: 16px;
                padding-bottom: 40px;
            }
            .card {
                break-inside: avoid;
                margin-bottom: 16px;
                display: inline-block; /* Fix for column break */
                width: 100%;
            }
            .section-header {
                column-span: all;
                padding-top: 20px;
                padding-bottom: 12px;
                background: var(--bg-color); /* Ensure readability */
                /* Fix Sticky Header in columns */
                position: relative !important; 
                top: 0 !important;
            }
            
            /* Mobile Fallback */
            @media (max-width: 400px) {
                #entries-container { column-count: 1; }
            }
        `;
    } else {
        style.innerHTML = "";
    }
}

window.addEventListener("load", () => {
    const saved = localStorage.getItem("cjos_view_grid") === "true";
    const toggle = document.getElementById("vg-toggle");
    if(toggle) toggle.checked = saved;
    applyGridView(saved);
});
JS;
?>