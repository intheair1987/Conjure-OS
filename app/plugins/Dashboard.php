<?php
// ==============================================================================
// PLUGIN: Dashboard
// DESCRIPTION: Stats & Tools Hub.
// UPDATED: Added event.stopPropagation() to sort buttons to prevent accidental 
// navigation when reordering.
// ==============================================================================

try {
    $count = $db->query("SELECT COUNT(*) FROM logs")->fetchColumn();
} catch (Exception $e) { $count = 0; }

$dash_layout_file = CJOS_PATH_DATA . '/dashboard-layout.json';

if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'dash_save_layout') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        file_put_contents($dash_layout_file, $_POST['layout']);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['plugin_action'] === 'dash_get_layout') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $data = file_exists($dash_layout_file) ? json_decode(file_get_contents($dash_layout_file), true) : [];
        echo json_encode(['status' => 'success', 'layout' => $data]);
        exit;
    }

    $dash_vis_file = CJOS_PATH_DATA . '/dashboard-visibility.json';

    if ($_POST['plugin_action'] === 'dash_save_visibility') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        file_put_contents($dash_vis_file, $_POST['visibility']);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['plugin_action'] === 'dash_get_visibility') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $data = file_exists($dash_vis_file) ? json_decode(file_get_contents($dash_vis_file), true) : new stdClass();
        echo json_encode(['status' => 'success', 'visibility' => $data]);
        exit;
    }

    $dash_tools_file = CJOS_PATH_DATA . '/dashboard-tools-config.json';
    if ($_POST['plugin_action'] === 'dash_save_tools_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        file_put_contents($dash_tools_file, $_POST['config']);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['plugin_action'] === 'dash_get_tools_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $data = file_exists($dash_tools_file) ? json_decode(file_get_contents($dash_tools_file), true) : ['tools_cols' => 2];
        echo json_encode(['status' => 'success', 'config' => $data]);
        exit;
    }

    if ($_POST['plugin_action'] === 'dash_get_page_order') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $path = CJOS_PATH_DATA . '/page-order.json';
        $order = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
        echo json_encode(['status' => 'success', 'order' => $order]);
        exit;
    }
}

// --- SETTINGS UI ---
$plugin_settings_map['Dashboard'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Reset Widget Order</label>
            <span class="setting-desc">Fixes layout issues if widgets get stuck or lost.</span>
        </div>
        <button onclick="resetDashboardLayout()" class="text-btn" style="color:var(--danger); font-weight:600;">Reset</button>
    </div>
HTML;

// --- DASHBOARD HTML ---
$dashboard_html = <<<'HTML'
<div class="scroll-view" id="dashboard-scroll-view">
    
    <!-- Title Row -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-top:10px;">
        <div class="page-title" style="margin-bottom:0; padding-top:0;">Dashboard</div>
    </div>
    
    <!-- Sortable Container -->
    <div id="dashboard-layout-root" style="display:flex; flex-direction:column; gap:20px; padding-bottom:100px;">
        <!-- All widgets (Core & Plugin) are now rendered dynamically via renderDashboard() -->
    </div>
</div>
HTML;

if(!isset($plugin_pages)) $plugin_pages = [];
$plugin_pages[] = $dashboard_html;

$plugin_js .= <<<'JS'
// --- DASHBOARD SORTING & EDIT JS ---

let isDashEditMode = false;
let dashSavedOrder = [];
let dashVisibility = {};
let dashConfig = { tools_cols: 2 };
let dashPageOrder = [];
let dashObserver = null;

// 1. STYLE INJECTION
const dashStyle = document.createElement("style");
dashStyle.innerHTML = `
    .dash-widget {
        transition: transform 0.2s, opacity 0.2s;
        position: relative;
    }
    
    .dash-edit-mode .dash-widget {
        transform: scale(0.98);
        border: 2px dashed #007AFF;
        border-radius: 24px;
        padding: 4px;
        background: rgba(0, 122, 255, 0.05);
    }

    .dash-sort-controls {
        position: absolute;
        right: -10px; top: 50%; transform: translateY(-50%);
        display: none;
        flex-direction: column;
        gap: 4px;
        z-index: 100; /* Ensure strictly on top */
    }
    .dash-edit-mode .dash-sort-controls { display: flex; }
    
    .dash-sort-btn {
        width: 36px; height: 36px; border-radius: 50%;
        background: var(--card-bg); border: 1px solid var(--border-color);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15); /* Stronger shadow */
        display: flex; align-items: center; justify-content: center;
        color: var(--primary); cursor: pointer;
        pointer-events: auto; /* Force clickability */
    }
    .dash-sort-btn:active { transform: scale(0.9); background: #F2F2F7; }
    
    .tool-card {
        background: var(--btn-bg);
        border-radius: 16px;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid var(--border-color);
    }
    .tool-card:active { 
        transform: scale(0.98);
        background-color: rgba(0, 0, 0, 0.04); 
    }
    .tool-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        margin-bottom: 6px;
    }
    .tool-icon svg { width: 20px; height: 20px; }
    .tool-title { font-size: 15px; font-weight: 600; color: var(--text-primary); }
    .tool-desc { font-size: 12px; color: var(--text-secondary); line-height: 1.3; }

    /* Ghost Mode: For tools with no page or hidden pages */
    .tool-card-ghost {
        border-style: dashed !important;
        background: var(--card-bg) !important;
        border-color: var(--border-color) !important; /* Use standard lighter border */
    }
    .tool-card-ghost .tool-icon {
        /* Full color icons to signify active functionality */
    }

    /* Compact Mode for High Column Counts */
    .dash-tools-compact .tool-card { 
        padding: 12px 6px; 
        gap: 6px; 
        align-items: center; 
        justify-content: center;
        container-type: inline-size; /* Enable container queries for title scaling */
    }
    .dash-tools-compact .tool-icon { 
        width: 32px; 
        height: 32px; 
        margin-bottom: 0; 
        flex-shrink: 0;
    }
    .dash-tools-compact .tool-icon svg { width: 18px; height: 18px; }
    .dash-tools-compact .tool-title { 
        /* Scale font size based on card width (cqw) to force single line */
        font-size: clamp(8px, 11cqw, 13px); 
        text-align: center; 
        white-space: nowrap;
        width: 100%;
        overflow: hidden;
        text-overflow: clip;
        line-height: 1.2;
    }
    .dash-tools-compact .tool-desc { display: none; }
`;
document.head.appendChild(dashStyle);

/**
 * Universal Dashboard Navigation
 * Scrolls the horizontal viewport to the page containing the target element.
 */
window.dashNavToPage = function(targetId) {
    // 1. Try to find the hydrated element OR the lazy wrapper
    const page = document.getElementById(targetId)?.closest('.page-view') || 
                 document.querySelector(`.lazy-page[data-page-id="${targetId}"]`);
    
    if (!page) return;

    const viewport = document.querySelector('.horizontal-viewport');
    if (viewport) {
        // Scroll to the wrapper. The IntersectionObserver will hydrate it as it enters.
        viewport.scrollTo({ left: page.offsetLeft, behavior: 'smooth' });
        if (window.sui && window.sui.haptic) window.sui.haptic('light');
    }
};

// 2. INIT
/**
 * Updates the dashboard statistics based on the live logs array.
 */
function dashRefreshStats() {
    const el = document.getElementById("dash-total-recordings");
    if (el && typeof logs !== 'undefined') {
        el.innerText = logs.length;
    }
}

// 2. INIT DATA ON LOAD (Global Availability)
window.addEventListener('load', async () => {
    try {
        const [layoutData, visData, toolsData, pageData] = await Promise.all([
            window.sui.api('dash_get_layout', {}, { toast: false }),
            window.sui.api('dash_get_visibility', {}, { toast: false }),
            window.sui.api('dash_get_tools_config', {}, { toast: false }),
            window.sui.api('dash_get_page_order', {}, { toast: false })
        ]);
        dashSavedOrder = layoutData.layout || [];
        dashVisibility = visData.visibility || {};
        dashConfig = toolsData.config || { tools_cols: 2 };
        dashPageOrder = pageData.order || [];
        
        // Apply visibility to DOM immediately
        applyDashboardVisibility();
        // Signal other plugins (like Command Bar) to sync
        if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
    } catch(e) { console.error("Dash Data Load Failed", e); }
});

async function dashInit() {
    // Register for live updates from LiveSync / Core
    if (window.registerRefreshHook) {
        window.registerRefreshHook(dashRefreshStats);
    }
    
    // Initial Render (Data already loaded via listener above)
    renderDashboard();
    applyDashboardVisibility();
}

// Listen for Lazy Hydration
window.addEventListener('cjos-hydrated', (e) => {
    if (e.detail.id === 'dashboard-scroll-view') {
        dashInit();
    }
});

async function saveDashVisibility() {
    await window.sui.api('dash_save_visibility', { visibility: JSON.stringify(dashVisibility) }, { toast: false });
    applyDashboardVisibility();
    if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
}

function applyDashboardVisibility() {
    // Apply to Widgets
    document.querySelectorAll('.dash-widget').forEach(w => {
        const id = w.getAttribute('data-id');
        if (!id) return;
        const isHidden = dashVisibility['widget_' + id] === false;
        w.style.display = isHidden ? 'none' : 'block';
    });

    // Apply to Pages (Lazy-Aware)
    if (typeof dashRegisteredTools !== 'undefined') {
        dashRegisteredTools.forEach(t => {
            if (t.linked_page) {
                // Find by ID (hydrated) OR by data-page-id (template)
                const page = document.querySelector(`.lazy-page[data-page-id="${t.linked_page}"]`);
                if (page) {
                    const isHidden = dashVisibility['page_' + t.linked_page] === false;
                    // If currently open in a portal, keep the original hidden regardless of settings
                    const inPortal = page.classList.contains('in-portal-active');
                    page.style.display = (isHidden || inPortal) ? 'none' : 'flex';
                }
            }
        });
    }
}

function dashOpenToolMenu(e, tool) {
    e.preventDefault();
    e.stopPropagation();
    window.sui.haptic('light');

    const options = [];
    
    if (tool.linked_page) {
        const isVisible = dashVisibility['page_' + tool.linked_page] !== false;
        options.push({ 
            label: isVisible ? "Hide Page" : "Show Page", 
            value: "toggle_page" 
        });
    }
    
    if (tool.linked_widget) {
        const isVisible = dashVisibility['widget_' + tool.linked_widget] !== false;
        options.push({ 
            label: isVisible ? "Hide Widget" : "Show Widget", 
            value: "toggle_widget" 
        });
    }

    if (options.length === 0) {
        // Fallback info if no toggleable components
        window.openConfirm(tool.name, "No toggleable components for this tool.", null, false, "OK", null);
        return;
    }

    if (tool.linked_page && dashVisibility['page_' + tool.linked_page] === false) {
        options.push({ label: "🚀 Open in Dynamic View", value: "open_dynamic" });
    }

    window.openPicker(tool.name + " Options", options, null, (val) => {
        if (val === "open_dynamic") {
            dashOpenDynamicView(tool);
        }
        if (val === "toggle_page") {
            const current = dashVisibility['page_' + tool.linked_page];
            dashVisibility['page_' + tool.linked_page] = (current === false) ? true : false;
            saveDashVisibility();
            renderToolsGrid(); // Live update grouping and style
        }
        if (val === "toggle_widget") {
            const current = dashVisibility['widget_' + tool.linked_widget];
            dashVisibility['widget_' + tool.linked_widget] = (current === false) ? true : false;
            saveDashVisibility();
        }
    });
}

function renderDashboard() {
    const root = document.getElementById("dashboard-layout-root");
    if(!root) return;
    root.innerHTML = "";

    // A. Define CORE widgets
    const coreWidgets = [
        {
            id: 'stats',
            title: 'Statistics',
            icon: `<svg viewBox="0 0 24 24" fill="currentColor" style="width:20px; height:20px;"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>`,
            icon_color: 'var(--primary)',
            html: `<div style="display:flex; justify-content:space-between; align-items:flex-end;">
                    <div>
                        <div style="font-size:13px; color:var(--text-secondary); font-weight:500; text-transform:uppercase; letter-spacing:0.5px;">Total Recordings</div>
                        <div id="dash-total-recordings" style="font-size:34px; font-weight:700; color:var(--text-primary); line-height:1.1; margin-top:4px;">${typeof logs !== 'undefined' ? logs.length : '--'}</div>
                    </div>
                </div>`
        },
        {
            id: 'tools',
            title: 'Tools',
            icon: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:20px; height:20px; stroke-width:2.5;"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>`,
            icon_color: 'var(--danger)',
            html: `<div id="dash-tools-grid" style="display:grid; grid-template-columns: repeat(var(--dash-tools-cols, 2), 1fr); gap:12px;"></div>`
        }
    ];

    // B. Combine with Plugin widgets
    const allWidgets = [...coreWidgets, ...(typeof dashRegisteredWidgets !== 'undefined' ? dashRegisteredWidgets : [])];

    // C. Sort
    const orderMap = {};
    dashSavedOrder.forEach((id, index) => orderMap[id] = index);
    allWidgets.sort((a, b) => {
        const aIdx = orderMap[a.id] !== undefined ? orderMap[a.id] : 999;
        const bIdx = orderMap[b.id] !== undefined ? orderMap[b.id] : 999;
        return aIdx - bIdx;
    });

    // D. Render
    allWidgets.forEach(w => {
        const widgetDiv = document.createElement("div");
        widgetDiv.className = "dash-widget";
        widgetDiv.setAttribute("data-id", w.id);
        widgetDiv.id = "dash-widget-" + w.id;
        
        const isTools = w.id === 'tools';
        if (isTools) {
            document.documentElement.style.setProperty('--dash-tools-cols', dashConfig.tools_cols || 2);
        }

        widgetDiv.innerHTML = `
            <div style="background:var(--card-bg); border-radius:22px; padding:24px; box-shadow:var(--shadow-card);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div style="width:32px; height:32px; border-radius:8px; background:var(--btn-bg); display:flex; align-items:center; justify-content:center; color:${w.icon_color || 'var(--primary)'};">
                            ${w.icon}
                        </div>
                        <h3 style="margin:0; font-size:17px; font-weight:600; color:var(--text-primary);">${w.title}</h3>
                    </div>
                    ${isTools ? `<button onclick="dashOpenToolsSettings(event)" class="icon-btn secondary" style="padding:4px; margin-right:-8px;">${window.suiIcon('sliders', 'var(--text-secondary)', 18)}</button>` : ''}
                </div>
                ${w.html}
                <div class="dash-sort-controls">
                    <button class="dash-sort-btn" onclick="moveDashWidget(event, this, -1)">▲</button>
                    <button class="dash-sort-btn" onclick="moveDashWidget(event, this, 1)">▼</button>
                </div>
            </div>
        `;
        root.appendChild(widgetDiv);
    });

    // E. Populate Tools Grid
    renderToolsGrid();
    
    // F. Notify plugins to hydrate their widgets
    if (window.renderDashboardPinnedLists) window.renderDashboardPinnedLists();
    if (typeof renderAllCalendars === 'function') renderAllCalendars();
    if (typeof renderCalDashboard === 'function') renderCalDashboard();
}

function renderToolsGrid() {
    const grid = document.getElementById("dash-tools-grid");
    if(!grid || typeof dashRegisteredTools === 'undefined') return;
    grid.innerHTML = "";

    const cols = parseInt(dashConfig.tools_cols || 2);
    grid.classList.toggle('dash-tools-compact', cols > 2);

    // 1. Categorize & Sort Tools
    const systemToolNames = ["Page Order", "Page Layout", "Edit Layout", "Refactor Lab"];
    const appTools = dashRegisteredTools.filter(t => !systemToolNames.includes(t.name));
    
    const getTier = (t) => {
        const hasPage = !!t.linked_page;
        const isVisible = hasPage && dashVisibility['page_' + t.linked_page] !== false;
        if (isVisible) return 0; // Tier 0: Solid (Visible Page)
        if (hasPage) return 1;   // Tier 1: Ghost (Hidden Page)
        return 2;                // Tier 2: Ghost (No Page)
    };

    // Sort app tools by Tier, then by Page Order
    appTools.sort((a, b) => {
        const tierA = getTier(a);
        const tierB = getTier(b);

        // Primary Sort: Tiers
        if (tierA !== tierB) return tierA - tierB;

        // Secondary Sort: Page Order
        if (dashPageOrder && dashPageOrder.length > 0) {
            const idxA = a.linked_page ? dashPageOrder.indexOf(a.linked_page) : -1;
            const idxB = b.linked_page ? dashPageOrder.indexOf(b.linked_page) : -1;
            
            if (idxA !== -1 && idxB !== -1) return idxA - idxB;
            if (idxA !== -1) return -1;
            if (idxB !== -1) return 1;
        }
        return 0;
    });

    const systemTools = dashRegisteredTools.filter(t => systemToolNames.includes(t.name));

    const createToolUI = (t, isApp = false) => {
        const div = document.createElement("div");
        div.className = "tool-card";

        if (isApp) {
            const noPage = !t.linked_page;
            const hiddenPage = t.linked_page && dashVisibility['page_' + t.linked_page] === false;
            if (noPage || hiddenPage) div.classList.add("tool-card-ghost");
        }

        const iconHtml = t.sui_icon ? window.suiIcon(t.sui_icon, t.icon_color || 'var(--primary)', 20, 2.5) : t.icon;
        div.innerHTML = `
            <div class="tool-icon" style="background:${t.color || 'var(--btn-bg)'}; color:${t.icon_color || 'var(--primary)'};">
                ${iconHtml}
            </div>
            <div class="tool-title">${t.name}</div>
            <div class="tool-desc">${t.desc || ''}</div>
        `;
        
        div.onclick = () => {
            const isPageHidden = t.linked_page && dashVisibility['page_' + t.linked_page] === false;
            const existingPortal = document.getElementById('dash-dynamic-portal');
            if (existingPortal && t.linked_page && existingPortal.querySelector('#' + t.linked_page)) {
                existingPortal.scrollIntoView({ behavior: 'smooth', inline: 'start' });
                window.sui.haptic('light');
                return;
            }
            if (isPageHidden) {
                window.sui.haptic('medium');
                window.openPicker(`${t.name} is Hidden`, [
                    { label: "🚀 Open in Dynamic View", value: "dynamic" },
                    { label: "👁️ Enable Page & Open", value: "enable" }
                ], null, (choice) => {
                    if (choice === 'dynamic') dashOpenDynamicView(t);
                    else if (choice === 'enable') {
                        dashVisibility['page_' + t.linked_page] = true;
                        applyDashboardVisibility();
                        saveDashVisibility();
                        setTimeout(() => { if (typeof t.action === 'string') new Function(t.action)(); }, 150);
                    }
                });
            } else if (typeof t.action === 'string') {
                new Function(t.action)();
            }
        };

        // Context Menu
        let lpTimer, startX, startY;
        div.oncontextmenu = (e) => dashOpenToolMenu(e, t);
        div.ontouchstart = (e) => {
            const pos = e.touches[0]; startX = pos.clientX; startY = pos.clientY;
            lpTimer = setTimeout(() => { dashOpenToolMenu(e, t); }, 600);
        };
        div.ontouchmove = (e) => {
            const pos = e.touches[0];
            if (Math.abs(pos.clientX - startX) > 10 || Math.abs(pos.clientY - startY) > 10) clearTimeout(lpTimer);
        };
        div.ontouchend = () => clearTimeout(lpTimer);
        return div;
    };

    // 2. Render Apps (with ghosting logic)
    appTools.forEach(t => grid.appendChild(createToolUI(t, true)));

    // 3. Render Separator
    const sep = document.createElement("div");
    sep.style.cssText = "grid-column: 1 / -1; margin-top: 12px; padding: 12px 4px 4px 4px; border-top: 1px solid var(--border-color);";
    sep.innerHTML = `<div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; opacity: 0.6;">System & Layout</div>`;
    grid.appendChild(sep);

    // 4. Render System Tools from Registry (no ghosting)
    systemTools.forEach(t => grid.appendChild(createToolUI(t, false)));

    // 5. Render Core "Edit Layout" Function
    window.suiHydrateIcons(grid);
    const edit = createToolUI({
        name: "Edit Layout",
        desc: "Rearrange widgets",
        sui_icon: "sliders",
        color: "rgba(142, 142, 147, 0.12)",
        icon_color: "var(--text-secondary)",
        action: "toggleDashboardEditMode()"
    }, false);
    edit.id = "tool-edit-layout";
    grid.appendChild(edit);
}

function ingestExternalWidgets() {
    const root = document.getElementById("dashboard-layout-root");
    if(!root) return;

    const todoWrapper = document.getElementById("todo-pinned-wrapper");
    const todoLabel = document.getElementById("todo-pinned-label");
    
    if (todoWrapper && todoWrapper.parentNode !== root) {
        let widget = document.getElementById("dash-widget-pinned-todo");
        if (!widget) {
            widget = document.createElement("div");
            widget.id = "dash-widget-pinned-todo";
            widget.className = "dash-widget";
            widget.setAttribute("data-id", "pinned-todo");
            
            if(todoLabel) widget.appendChild(todoLabel);
            widget.appendChild(todoWrapper);
            root.appendChild(widget);
        }
    }
    
    document.querySelectorAll(".dash-widget").forEach(w => {
        if(!w.querySelector(".dash-sort-controls")) {
            const ctrl = document.createElement("div");
            ctrl.className = "dash-sort-controls";
            // FIXED: Added event argument to propagate correctly
            ctrl.innerHTML = `
                <button class="dash-sort-btn" onclick="moveDashWidget(event, this, -1)">▲</button>
                <button class="dash-sort-btn" onclick="moveDashWidget(event, this, 1)">▼</button>
            `;
            w.appendChild(ctrl);
        }
    });
}

function sortDashboard() {
    const root = document.getElementById("dashboard-layout-root");
    if(!root || dashSavedOrder.length === 0) return; 
    
    // TEMPORARY DISCONNECT: Prevent the sort itself from triggering the observer
    if(dashObserver) dashObserver.disconnect();

    const widgets = Array.from(root.children);
    const orderMap = {};
    dashSavedOrder.forEach((id, index) => orderMap[id] = index);
    
    widgets.sort((a, b) => {
        const aId = a.getAttribute("data-id");
        const bId = b.getAttribute("data-id");
        const aIdx = orderMap[aId] !== undefined ? orderMap[aId] : 999;
        const bIdx = orderMap[bId] !== undefined ? orderMap[bId] : 999;
        return aIdx - bIdx;
    });
    
    widgets.forEach(w => root.appendChild(w));

    // RECONNECT
    if(dashObserver) dashObserver.observe(root, { childList: true });
}

async function saveDashboardOrder() {
    const root = document.getElementById("dashboard-layout-root");
    const ids = Array.from(root.children).map(w => w.getAttribute("data-id")).filter(id => id);
    dashSavedOrder = ids;
    
    await window.sui.api('dash_save_layout', { layout: ids }, { toast: false });
}

window.toggleDashboardEditMode = function() {
    isDashEditMode = !isDashEditMode;
    const root = document.getElementById("dashboard-layout-root");
    const editTool = document.getElementById("tool-edit-layout");
    
    if(isDashEditMode) {
        root.classList.add("dash-edit-mode");
        if(editTool) {
            // Use system variables and remove hardcoded overrides
            editTool.style.backgroundColor = "var(--btn-bg)";
            editTool.style.borderColor = "var(--primary)";
            editTool.querySelector(".tool-title").innerText = "Done Editing";
            editTool.querySelector(".tool-title").style.color = "var(--primary)";
        }
        ingestExternalWidgets();
    } else {
        root.classList.remove("dash-edit-mode");
        if(editTool) {
            editTool.style.backgroundColor = "";
            editTool.style.borderColor = "";
            editTool.querySelector(".tool-title").innerText = "Edit Layout";
            editTool.querySelector(".tool-title").style.color = "";
        }
    }
};

// FIXED: Added event argument and stopPropagation
/**
 * Dynamic View Portal
 * Teleports a hidden plugin page into a temporary view to the right of the Dashboard.
 */
window.dashOpenDynamicView = function(tool) {
    // 0. Find the Lazy Wrapper first (it always exists in the DOM)
    const parentPage = document.querySelector(`.lazy-page[data-page-id="${tool.linked_page}"]`);
    let targetScrollView = document.getElementById(tool.linked_page);

    // 1. If not hydrated, wake it up now so we can get the ID
    if (!targetScrollView && parentPage) {
        const template = parentPage.querySelector('template');
        if (template) {
            parentPage.appendChild(template.content.cloneNode(true));
            parentPage.classList.add('is-hydrated');
            template.remove();
            window.dispatchEvent(new CustomEvent('cjos-hydrated', { 
                detail: { id: tool.linked_page, container: parentPage } 
            }));
        }
        targetScrollView = document.getElementById(tool.linked_page);
    }

    if (!targetScrollView) return;

    // 2. PORTAL EVICTION: If a portal is already open, move its content back home first
    const existingPortal = document.getElementById('dash-dynamic-portal');
    if (existingPortal) {
        const oldContent = existingPortal.querySelector('.scroll-view');
        if (oldContent && oldContent._originalParent) {
            // Restore visibility state and move back
            oldContent.style.display = oldContent._originalDisplay || 'none';
            oldContent.style.paddingTop = '';
            oldContent._originalParent.appendChild(oldContent);
            
            // Remove the portal-active flag from the old parent
            const oldParentPage = oldContent.closest('.lazy-page');
            if (oldParentPage) oldParentPage.classList.remove('in-portal-active');
        }
        existingPortal.remove();
    }

    // 2. Create Portal Page
    const dashPage = document.getElementById('dashboard-scroll-view').closest('.page-view');
    const newPage = document.createElement('div');
    newPage.className = 'page-view dash-dynamic-portal';
    newPage.id = 'dash-dynamic-portal';
    
    // Dynamic Layout Bridge: Define the gap size here so CSS can read it
    newPage.style.setProperty('--dynamic-portal-spacer', 'calc(var(--header-base-height) + var(--inner-padding-top) + 15px)');

    const iconHtml = tool.sui_icon ? window.suiIcon(tool.sui_icon, tool.icon_color || 'var(--primary)', 20, 2.5) : tool.icon;

    newPage.innerHTML = `
        <div style="height:var(--dynamic-portal-spacer); flex-shrink:0; background:var(--header-bg);"></div>
        <div style="height:54px; background:var(--header-bg); backdrop-filter:blur(30px); -webkit-backdrop-filter:blur(30px); display:flex; align-items:center; justify-content:space-between; padding:0 20px; border-bottom:1px solid rgba(0,0,0,0.05); flex-shrink:0; z-index:10;">
            <div style="font-size:11px; font-weight:800; color:var(--text-primary); text-transform:uppercase; letter-spacing:2px; display:flex; align-items:center; gap:10px;">
                <span style="background:${tool.color}; padding:6px; border-radius:8px; display:flex; align-items:center; justify-content:center;">${iconHtml}</span> ${tool.name}
            </div>
            <button onclick="dashCloseDynamicView('${tool.linked_page}')" style="background:var(--primary); border:none; width:32px; height:32px; border-radius:50%; color:white; font-weight:800; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0; box-shadow:0 4px 12px rgba(0,122,255,0.3);">&times;</button>
        </div>
        <div class="portal-content-area" style="flex:1; overflow:hidden; display:flex; flex-direction:column; background:var(--bg-color);"></div>
    `;

    // 3. Teleport DOM
    const contentArea = newPage.querySelector('.portal-content-area');
    targetScrollView._originalParent = targetScrollView.parentNode;
    targetScrollView._originalDisplay = targetScrollView.style.display;
    
    // Force visibility for the portal - Restore original or default to block
    targetScrollView.style.display = (targetScrollView._originalDisplay === 'none') ? 'block' : targetScrollView._originalDisplay;
    targetScrollView.style.paddingTop = '20px'; // Adjust for portal header
    contentArea.appendChild(targetScrollView);

    // 4. Mark original as active in portal and refresh visibility
    parentPage.classList.add('in-portal-active');
    applyDashboardVisibility();

    // 5. Insert and Scroll
    dashPage.after(newPage);
    if (typeof window.showAboShield === "function") window.showAboShield();
    
    setTimeout(() => {
        newPage.scrollIntoView({ behavior: 'smooth', inline: 'start' });
    }, 50);

    // 5. History Push
    if (typeof aboEnabled !== "undefined" && aboEnabled) {
        history.pushState({ dash_portal_open: true }, null, window.location.href);
    }
};

window.dashCloseDynamicView = function(linkedPageId) {
    const portal = document.getElementById('dash-dynamic-portal');
    const targetScrollView = document.getElementById(linkedPageId);
    const dashPage = document.getElementById('dashboard-scroll-view').closest('.page-view');

    if (!portal || !targetScrollView) return;

    // 1. Scroll back
    dashPage.scrollIntoView({ behavior: 'smooth', inline: 'start' });

    // 2. Wait for animation, then restore DOM and destroy portal
    setTimeout(() => {
        const parentPage = document.querySelector(`.lazy-page[data-page-id="${linkedPageId}"]`);
        if (parentPage) {
            parentPage.classList.remove('in-portal-active');
        }
        applyDashboardVisibility();

        targetScrollView.style.display = targetScrollView._originalDisplay || 'none';
        targetScrollView.style.paddingTop = ''; // Reset padding
        if (targetScrollView._originalParent) {
            targetScrollView._originalParent.appendChild(targetScrollView);
        }
        portal.remove();
    }, 500);
};

window.moveDashWidget = function(e, btn, dir) {
    e.stopPropagation(); // Stops click from reaching the widget container
    
    const widget = btn.closest(".dash-widget");
    const root = widget.parentNode;
    
    if(dir === -1) { 
        if(widget.previousElementSibling) root.insertBefore(widget, widget.previousElementSibling);
    } else { 
        if(widget.nextElementSibling) root.insertBefore(widget.nextElementSibling, widget);
    }
    
    saveDashboardOrder();
    if(navigator.vibrate) navigator.vibrate(20);
};

window.resetDashboardLayout = function() {
    window.openConfirm("Reset Layout", "Reset Dashboard layout to default?", () => {
        localStorage.removeItem("cjos_dashboard_order"); // Using literal key for safety
        location.reload();
    });
};

window.dashOpenToolsSettings = function(e) {
    if (e) e.stopPropagation();
    window.sui.openStudio({
        id: 'dash-tools-settings',
        title: 'Tools Layout',
        content: `
            <div style="padding:10px 0;">
                ${window.suiSettingRow(
                    'Grid Columns', 
                    'Adjust how many tools fit in a single row.', 
                    `<div style="display:flex; align-items:center; gap:12px; min-width:120px;">
                        ${window.suiSlider('dash-cols-slider', 1, 4, 1, dashConfig.tools_cols || 2, 'dashUpdateToolsCols(this.value)')}
                        <span id="dash-cols-val" style="font-weight:700; color:var(--primary); min-width:20px;">${dashConfig.tools_cols || 2}</span>
                    </div>`,
                    true
                )}
            </div>
        `,
        onSetup: (content, overlay) => {
            // Make background transparent so user can see the dashboard live
            overlay.style.background = 'transparent';
            overlay.style.backdropFilter = 'none';
            overlay.style.webkitBackdropFilter = 'none';
            
            const sheet = overlay.querySelector('.shared-bottom-sheet');
            sheet.style.height = 'auto';
            sheet.style.maxHeight = '40vh';
            sheet.style.background = 'var(--header-bg)'; 
            sheet.style.backdropFilter = 'blur(20px)';
            sheet.style.webkitBackdropFilter = 'blur(20px)';
        }
    });
};

window.dashUpdateToolsCols = function(val) {
    dashConfig.tools_cols = val;
    document.getElementById('dash-cols-val').innerText = val;
    document.documentElement.style.setProperty('--dash-tools-cols', val);
    
    const grid = document.getElementById('dash-tools-grid');
    if (grid) grid.classList.toggle('dash-tools-compact', parseInt(val) > 2);

    // Throttled Save
    if (window._dashSaveTimer) clearTimeout(window._dashSaveTimer);
    window._dashSaveTimer = setTimeout(async () => {
        await window.sui.api('dash_save_tools_config', { config: JSON.stringify(dashConfig) }, { toast: false });
    }, 500);
};
JS;
?>