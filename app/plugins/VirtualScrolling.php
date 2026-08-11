<?php
// ==============================================================================
// PLUGIN: Virtual Scrolling
// DESCRIPTION: Performance for Large Lists.
// Replaces the standard list rendering with a batched "Load on Scroll" system.
// Improves performance significantly for large databases.
// ==============================================================================

$vs_config_file = CJOS_PATH_DATA . '/virtual-scrolling-config.json';

// --- 1. BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'vs_save_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $settings = json_decode($_POST['settings'], true);
        file_put_contents($vs_config_file, json_encode($settings, JSON_PRETTY_PRINT));
        
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- 2. LOAD CONFIG ---
$vs_enabled = true;
$vs_batch_size = 50;

if (file_exists($vs_config_file)) {
    $vs_data = json_decode(file_get_contents($vs_config_file), true);
    $vs_enabled = $vs_data['enabled'] ?? true;
    $vs_batch_size = $vs_data['batchSize'] ?? 50;
}

// --- DATA BRIDGE ---
$vs_bridge_json = json_encode(['enabled' => (bool)$vs_enabled, 'batchSize' => (int)$vs_batch_size]);
$plugin_js .= "\nwindow.__VS_BRIDGE__ = $vs_bridge_json;\n";

// --- 3. SETTINGS UI ---
$plugin_settings_map['VirtualScrolling'] = <<<'HTML'
    <div data-sui-setting="Enable Virtual Scrolling" data-sui-desc="Only render visible items. Recommended for 100+ notes." data-sui-id="vs-enabled-toggle" data-sui-onchange="saveVsSettings()"></div>

    <div class="setting-item vertical">
        <label class="setting-label">Batch Size</label>
        <div class="setting-desc">Number of items to load per scroll segment.</div>
        <div style="display:flex; align-items:center; gap:15px; margin-top:8px;">
            <input type="range" id="vs-batch-slider" min="10" max="200" step="10" oninput="document.getElementById('vs-batch-val').innerText = this.value" onchange="saveVsSettings()">
            <span id="vs-batch-val" style="font-weight:700; color:var(--primary); min-width:30px;">--</span>
        </div>
    </div>
HTML;

// --- 4. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
let vsSettings = window.__VS_BRIDGE__ || { enabled: true, batchSize: 50 };

// --- VIRTUAL SCROLLING JS ---
JS;

$plugin_js .= <<<'JS'


let vsFullData = [];
let vsCurrentIndex = 0;
let vsObserver = null;
let vsIsRendering = false;

// --- IMMEDIATE OVERRIDE ---
// We override the renderer immediately to catch the very first render call from ViewStandard
if (vsSettings.enabled) {
    overrideStandardRenderer();
}

window.addEventListener("load", () => {
    // Sync UI state only
    const toggle = document.getElementById("vs-enabled-toggle");
    const slider = document.getElementById("vs-batch-slider");
    const label = document.getElementById("vs-batch-val");
    
    if(toggle) toggle.checked = vsSettings.enabled;
    if(slider) slider.value = vsSettings.batchSize;
    if(label) label.innerText = vsSettings.batchSize;
});

function overrideStandardRenderer() {
    // We hijack the global render function defined in ViewStandard.php
    const originalRender = window.renderStandardList;
    
    window.renderStandardList = function(logsData) {
        if (!vsSettings.enabled) {
            return originalRender(logsData);
        }

        const container = document.getElementById("entries-container");
        if (!container || !logsData) return;

        // If list is empty, clear and stop
        if (logsData.length === 0) {
            vsFullData = [];
            vsCurrentIndex = 0;
            if (vsObserver) {
                vsObserver.disconnect();
                vsObserver = null;
            }
            container.innerHTML = window.suiEmptyState('📂', 'No entries found in this view');
            const sentinel = document.getElementById("vs-sentinel");
            if (sentinel) {
                sentinel.innerHTML = '';
                sentinel.remove();
            }
            return;
        }

        // Reset State
        let processedData = [...logsData];
        if (window.cjosVsDataProcessors) {
            window.cjosVsDataProcessors.forEach(fn => {
                try { processedData = fn(processedData); } catch(e) { console.error("VS Processor Error", e); }
            });
        }
        vsFullData = processedData;
        vsCurrentIndex = 0;
        container.innerHTML = "";
        
        if (vsObserver) vsObserver.disconnect();

        // Initial Render
        renderNextVsBatch();
        
        // Setup Sentinel for Infinite Scroll
        setupVsSentinel();
    };
}

function renderNextVsBatch() {
    if (vsIsRendering) return;
    
    // --- FACTORY GUARD ---
    // If the card factory isn't ready yet, wait 50ms and try again.
    if (!window.createStandardCardDOM) {
        setTimeout(renderNextVsBatch, 50);
        return;
    }

    vsIsRendering = true;
    const container = document.getElementById("entries-container");
    const end = Math.min(vsCurrentIndex + vsSettings.batchSize, vsFullData.length);
    
    let lastDate = null;
    // Find the last date header in the container to avoid duplicates
    const existingHeaders = container.querySelectorAll(".section-header");
    if (existingHeaders.length > 0) {
        lastDate = existingHeaders[existingHeaders.length - 1].getAttribute("data-date-raw");
    }

    const fragment = document.createDocumentFragment();

    for (let i = vsCurrentIndex; i < end; i++) {
        const entry = vsFullData[i];
        const [datePart, timePart] = entry.date_display.split(" ");

        // Date Header Logic
        if (datePart !== lastDate) {
            const header = document.createElement("div");
            header.className = "section-header";
            header.textContent = window.getRelativeDateLabel ? window.getRelativeDateLabel(datePart) : datePart;
            header.setAttribute("data-date-raw", datePart);
            fragment.appendChild(header);
            lastDate = datePart;
        }

        // Card Creation
        if (window.createStandardCardDOM) {
            const card = window.createStandardCardDOM(entry);
            fragment.appendChild(card);
            
            // Run Plugin Hooks (WordCounter, etc)
            if (window.cjosPluginRegistry) {
                window.cjosPluginRegistry.forEach(p => { try { p.fn(card, entry); } catch(e) {} });
            }
        }
    }

    container.appendChild(fragment);
    vsCurrentIndex = end;
    vsIsRendering = false;

    // Post-render handshake
    if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
}

function setupVsSentinel() {
    const container = document.getElementById("entries-container");
    
    // Create or find sentinel
    let sentinel = document.getElementById("vs-sentinel");
    if (!sentinel) {
        sentinel = document.createElement("div");
        sentinel.id = "vs-sentinel";
        sentinel.style.height = "50px";
        sentinel.style.margin = "20px 0";
        sentinel.style.display = "flex";
        sentinel.style.alignItems = "center";
        sentinel.style.justifyContent = "center";
    }
    
    container.after(sentinel);

    vsObserver = new IntersectionObserver((entries) => {
        // Only trigger if we are intersecting AND not already at the end
        if (entries[0].isIntersecting && vsCurrentIndex < vsFullData.length && !vsIsRendering) {
            if (vsCurrentIndex < vsFullData.length) {
                sentinel.innerHTML = window.suiSpinner ? window.suiSpinner(20) : '...';
                requestAnimationFrame(() => {
                    setTimeout(() => {
                        renderNextVsBatch();
                        if (vsCurrentIndex >= vsFullData.length) {
                            sentinel.innerHTML = '<div style="color:#C7C7CC; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin: 20px 0;">End of Stream</div>';
                        }
                    }, 50);
                });
            } else {
                sentinel.innerHTML = '<div style="color:#C7C7CC; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin: 20px 0;">End of Stream</div>';
            }
        }
    }, { 
        rootMargin: "200px", // Reduced margin to prevent over-aggressive loading
        threshold: 0.1
    });

    vsObserver.observe(sentinel);
}

window.saveVsSettings = async function() {
    const enabled = document.getElementById("vs-enabled-toggle").checked;
    const batchSize = parseInt(document.getElementById("vs-batch-slider").value);
    
    // 1. Check if we just toggled the engine state
    const engineToggled = (vsSettings.enabled !== enabled);
    
    // 2. Update local state immediately for responsiveness
    vsSettings.enabled = enabled;
    vsSettings.batchSize = batchSize;

    // 3. Save to server
    try {
        // CRITICAL: We must await the API call. If we reload immediately, 
        // the browser cancels the request before the server can save the file.
        await window.sui.api("vs_save_config", { settings: { enabled, batchSize } }, { toast: false });
        
        if (engineToggled) {
            // Structural change (On/Off) still requires a reload for stability
            location.reload();
        } else if (enabled) {
            // Batch size changed: Trigger a live re-render of the current view
            if (typeof window.refreshFolderView === "function") {
                window.refreshFolderView();
            }
            
            const status = document.getElementById("vs-batch-val");
            if (status) {
                status.style.color = "#34C759";
                setTimeout(() => status.style.color = "var(--primary)", 500);
            }
        }
    } catch(e) { console.error("VS Save Error", e); }
};
JS;