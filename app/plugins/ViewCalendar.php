<?php
// ==============================================================================
// PLUGIN: View Calendar
// DESCRIPTION: Visual Date Timeline.
// Adds a monthly calendar view with inline entry list.
// Features: Robust Two-way State Sync (Delete & Edit) with Main List.
// UPDATED: Injects a Shortcut into the Dashboard Tools area.
// ==============================================================================

// 1. Capture Today's Date (Respects TimezoneOverride set in index.php)
$cal_server_today = date('Y-m-d');
$cal_conf_file = CJOS_PATH_DATA . '/calendar-plugin-config.json';

// --- DATA BRIDGE ---
$cal_defaults = ['show_page' => true, 'show_widget' => true];
$cal_conf = file_exists($cal_conf_file) ? json_decode(file_get_contents($cal_conf_file), true) : $cal_defaults;
$cal_bridge_json = json_encode([
    'today' => $cal_server_today,
    'settings' => $cal_conf
]);
$plugin_js .= "\nwindow.__CAL_BRIDGE__ = $cal_bridge_json;\n";

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'cal_get_plugin_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults = ['show_page' => true, 'show_widget' => true];
        $conf = file_exists($cal_conf_file) ? json_decode(file_get_contents($cal_conf_file), true) : $defaults;
        echo json_encode(['status' => 'success', 'config' => $conf]);
        exit;
    }
    if ($_POST['plugin_action'] === 'cal_save_plugin_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $settings = json_decode($_POST['settings'], true);
        file_put_contents($cal_conf_file, json_encode($settings, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// Load Config for PHP logic
// --- SETTINGS UI ---
$plugin_settings_map['ViewCalendar'] = <<<'HTML'
    <div style="padding:16px; font-size:13px; color:var(--text-secondary); font-style:italic;">
        Tip: You can show/hide the Calendar Page and Widget by long-pressing the "Calendar" icon in the Dashboard Tools section.
    </div>
HTML;

$calendar_html = <<<'HTML'
<div class="scroll-view" id="calendar-view">
    
    <!-- Title Row -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-top:10px;">
        <div class="page-title" style="margin-bottom:0; padding-top:0;">Calendar</div>
    </div>
    
    <!-- Calendar Card -->
    <div style="background:var(--card-bg); border-radius:22px; padding:20px; box-shadow:var(--shadow-card); margin-bottom:24px;">
        <div id="cal-controls" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <button onclick="calChangeMonth(-1)" style="background:var(--btn-bg); border:none; width:36px; height:36px; border-radius:50%; font-size:16px; cursor:pointer; color:var(--text-primary); display:flex; align-items:center; justify-content:center;">&lt;</button>
            <div id="cal-month-label" style="font-size:17px; font-weight:700; color:var(--text-primary); font-family:system-ui, -apple-system, sans-serif;">Month</div>
            <button onclick="calChangeMonth(1)" style="background:var(--btn-bg); border:none; width:36px; height:36px; border-radius:50%; font-size:16px; cursor:pointer; color:var(--text-primary); display:flex; align-items:center; justify-content:center;">&gt;</button>
        </div>

        <div id="cal-grid" style="
            display:grid; 
            grid-template-columns: repeat(7, 1fr); 
            gap: 8px; 
            text-align: center;
            font-family: -apple-system, sans-serif;
        ">
            <!-- Weekdays -->
            <div style="color:var(--text-secondary); font-size:12px; font-weight:600; padding-bottom:8px;">S</div>
            <div style="color:var(--text-secondary); font-size:12px; font-weight:600; padding-bottom:8px;">M</div>
            <div style="color:var(--text-secondary); font-size:12px; font-weight:600; padding-bottom:8px;">T</div>
            <div style="color:var(--text-secondary); font-size:12px; font-weight:600; padding-bottom:8px;">W</div>
            <div style="color:var(--text-secondary); font-size:12px; font-weight:600; padding-bottom:8px;">T</div>
            <div style="color:var(--text-secondary); font-size:12px; font-weight:600; padding-bottom:8px;">F</div>
            <div style="color:var(--text-secondary); font-size:12px; font-weight:600; padding-bottom:8px;">S</div>
            <!-- Days injected via JS -->
        </div>
    </div>
    
    <!-- Selected Date Header -->
    <div id="cal-list-header" class="section-header" style="display:none; margin-bottom:12px;">Selected Date</div>

    <!-- Inline Entry List -->
    <div id="cal-entry-list" style="padding-bottom:100px; min-height:200px;">
        <div style="text-align:center; color:#8E8E93; font-size:14px; margin-top:40px;">Select a date to view entries.</div>
    </div>
</div>
HTML;

if(!isset($plugin_pages)) $plugin_pages = [];
// Always include the HTML so the DOM element exists for immediate JS toggling
$plugin_pages[] = $calendar_html;

$plugin_tools[] = [
    'name' => 'Calendar',
    'desc' => 'View by date',
    'sui_icon' => 'calendar',
    'color' => 'rgba(255, 59, 48, 0.1)',
    'icon_color' => 'var(--danger)',
    'action' => "dashNavToPage('calendar-view')",
    'linked_page' => 'calendar-view',
    'linked_widget' => 'calendar-grid'
];

$plugin_widgets[] = [
    'id' => 'calendar-grid',
    'title' => 'Monthly Grid',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:20px; height:20px; stroke-width:2.5;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
    'icon_color' => 'var(--primary)',
    'html' => '
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div id="cal-widget-month-label" style="font-size:14px; font-weight:700; color:var(--text-primary);">Month</div>
            <div style="display:flex; gap:6px;">
                <button onclick="calChangeMonth(-1); renderAllCalendars();" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; font-size:12px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-primary);">&lt;</button>
                <button onclick="calChangeMonth(1); renderAllCalendars();" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; font-size:12px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-primary);">&gt;</button>
            </div>
        </div>
        <div id="cal-widget-grid" style="display:grid; grid-template-columns: repeat(7, 1fr); gap: 6px; text-align: center;">
            <div style="color:var(--text-secondary); font-size:10px; font-weight:600; padding-bottom:4px;">S</div>
            <div style="color:var(--text-secondary); font-size:10px; font-weight:600; padding-bottom:4px;">M</div>
            <div style="color:var(--text-secondary); font-size:10px; font-weight:600; padding-bottom:4px;">T</div>
            <div style="color:var(--text-secondary); font-size:10px; font-weight:600; padding-bottom:4px;">W</div>
            <div style="color:var(--text-secondary); font-size:10px; font-weight:600; padding-bottom:4px;">T</div>
            <div style="color:var(--text-secondary); font-size:10px; font-weight:600; padding-bottom:4px;">F</div>
            <div style="color:var(--text-secondary); font-size:10px; font-weight:600; padding-bottom:4px;">S</div>
        </div>'
];

$plugin_js .= <<<'JS'
// --- VIEW CALENDAR JS ---

const calBridge = window.__CAL_BRIDGE__ || { today: new Date().toISOString().split('T')[0], settings: { show_page: true, show_widget: true } };
let calPlugSettings = calBridge.settings;
const calServerToday = calBridge.today;
let calCurrentDate = new Date();
let calSelectedDateKey = null; 
let calLogMap = {}; 

// Flag to prevent the Sync Observer from deleting items during view changes
window._isCalRendering = false;

// Initialize View Date based on Server Date
(function() {
    const parts = calServerToday.split("-");
    calCurrentDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
})();

// 1. Init Data
function initCalendarMap() {
    calLogMap = {};
    if(typeof logs !== "undefined") {
        logs.forEach(l => {
            const dateStr = l.date_display.split(" ")[0]; // YYYY-MM-DD
            if(!calLogMap[dateStr]) calLogMap[dateStr] = 0;
            calLogMap[dateStr]++;
        });
    }
}

// 2. Render Grid (Unified)
function renderCalendar(targetPrefix = "cal") {
    const grid = document.getElementById(targetPrefix + "-grid");
    const label = document.getElementById(targetPrefix + "-month-label");
    if(!grid || !label) return;

    // Preserve Weekdays (first 7 children)
    while(grid.children.length > 7) {
        grid.removeChild(grid.lastChild);
    }

    const year = calCurrentDate.getFullYear();
    const month = calCurrentDate.getMonth();
    
    label.innerText = calCurrentDate.toLocaleDateString("en-US", { month: "long", year: "numeric" });

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    // Blanks
    for(let i=0; i<firstDay; i++) {
        grid.appendChild(document.createElement("div"));
    }

    const todayStr = calServerToday;

    // Days
    for(let d=1; d<=daysInMonth; d++) {
        const dateKey = `${year}-${String(month+1).padStart(2,"0")}-${String(d).padStart(2,"0")}`;
        const count = calLogMap[dateKey] || 0;
        const hasEntry = count > 0;
        const isToday = (dateKey === todayStr);
        const isSelected = (dateKey === calSelectedDateKey);

        const cell = document.createElement("div");
        
        let css = "aspect-ratio: 1; display:flex; flex-direction:column; align-items:center; justify-content:center; border-radius:10px; cursor:pointer; position:relative; transition:all 0.2s;";
        let bg = "transparent";
        let color = "var(--text-primary)";
        let border = "2px solid transparent";

        if (isSelected) {
            bg = "var(--primary)";
            color = "#FFFFFF";
            if(isToday) border = "2px solid rgba(255,255,255,0.3)"; 
        } else if (isToday) {
            // Thick outline for Today (Server Time)
            border = "2px solid var(--primary)";
            color = "var(--primary)";
        } else if (hasEntry) {
            bg = "rgba(0, 122, 255, 0.05)";
        }

        cell.style.cssText = css;
        cell.style.backgroundColor = bg;
        cell.style.color = color;
        cell.style.border = border;
        
        const num = document.createElement("span");
        num.innerText = d;
        num.style.fontSize = "15px";
        num.style.fontWeight = (isToday || isSelected) ? "700" : "500";
        cell.appendChild(num);

        // --- Entry Count Number ---
        if(hasEntry) {
            const countColor = isSelected ? "#FFFFFF" : "var(--primary)";
            const countOpacity = isSelected ? "1" : "0.8";
            
            const countBadge = document.createElement("div");
            countBadge.innerText = count;
            countBadge.style.cssText = `font-size:10px; color:${countColor}; font-weight:700; margin-top:0px; opacity:${countOpacity}; line-height:10px;`;
            cell.appendChild(countBadge);
        }

        cell.onclick = () => {
            if (targetPrefix === "cal") {
                selectCalendarDate(dateKey);
            } else {
                // Widget Behavior: Simply highlight or jump to page
                haptic(5);
            }
        };
        grid.appendChild(cell);
    }
}

// Legacy settings logic removed. Visibility now handled by Dashboard Centralized State.

// injectCalendarWidget logic moved to PHP $plugin_widgets bus.

window.renderAllCalendars = function() {
    initCalendarMap();
    renderCalendar("cal");
    renderCalendar("cal-widget");
};

// 3. Select & Render List
window.selectCalendarDate = function(dateKey) {
    if (calSelectedDateKey === dateKey) {
        calSelectedDateKey = null;
    } else {
        calSelectedDateKey = dateKey;
    }
    renderCalendar();
    renderDayEntries(calSelectedDateKey);
};
      

window.renderDayEntries = function(dateKey) {
    const list = document.getElementById("cal-entry-list");
    const header = document.getElementById("cal-list-header");
    if(!list) return;
    
    window._isCalRendering = true;
    list.innerHTML = "";

    if (!dateKey) {
        if(header) header.style.display = "none";
        list.innerHTML = `<div style="text-align:center; color:#8E8E93; font-size:14px; margin-top:40px;">Select a date to view entries.</div>`;
        updateCalendarStats();
        window._isCalRendering = false;
        return;
    }
    
    const dateObj = new Date(dateKey);
      
    const friendly = dateObj.toLocaleDateString("en-US", { weekday: "long", month: "long", day: "numeric" });
    
    if(header) {
        header.style.display = "block";
        header.innerText = friendly;
    }

    const dayLogs = logs.filter(l => l.date_display.startsWith(dateKey));
    
    if (dayLogs.length === 0) {
        list.innerHTML = `<div style="text-align:center; color:#8E8E93; padding:40px;">No recordings for this date.</div>`;
    } else {
        dayLogs.forEach(entry => {
            let card;
            if (typeof window.createStandardCardDOM === "function") {
                card = window.createStandardCardDOM(entry);
            } else {
                card = document.createElement("div"); 
                card.className = "card";
                card.innerHTML = `<div class="card-content" style="padding:20px;">${entry.transcription}</div>`;
            }
            list.appendChild(card);
            
            if(window.cjosPluginRegistry) {
                window.cjosPluginRegistry.forEach(p => { try { p.fn(card, entry); } catch(e) {} });
            }
        });
    }
    
    setTimeout(() => { window._isCalRendering = false; }, 50);
    if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
};

window.calChangeMonth = function(dir) {
    calCurrentDate.setMonth(calCurrentDate.getMonth() + dir);
    renderCalendar();
};

window.updateCalendarStats = function() {
    const list = document.getElementById("cal-entry-list");
    if (!list || !list.firstElementChild || typeof logs === "undefined" || logs.length === 0) return;
    
    // Only run if we are in the default state
    if (list.children.length === 1 && list.innerText.includes("Select a date")) {
        const count = logs.length;
        // logs is sorted DESC (newest first), so last is oldest
        const oldest = logs[logs.length - 1];
        if (!oldest) return;
        
        // Parse date (YYYY-MM-DD)
        const parts = oldest.date_display.split(" ")[0].split("-");
        const dateObj = new Date(parts[0], parts[1] - 1, parts[2]);
        const dateStr = dateObj.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
        
        list.firstElementChild.innerHTML = `
            <div style="margin-bottom:6px; font-weight:500;">Select a date to view entries</div>
            <div style="font-size:11px; opacity:0.6; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">${count} Entries Since ${dateStr}</div>
        `;
    }
};

// --- DATA-DRIVEN SYNC ENGINE ---
(function() {
    window.addEventListener("load", () => {
        if (window.registerUpdateHook) {
            // Subscribe to the global update bus
            window.registerUpdateHook((logId, data) => {
                // If a change happens anywhere, refresh the calendar maps
                initCalendarMap();
                renderAllCalendars();
                
                // If the user is currently viewing the date belonging to this log, re-render the list
                if (calSelectedDateKey) {
                    const entryDate = (data && data.date_display) ? data.date_display.split(" ")[0] : null;
                    // If we don't have the date in the payload, we check the logs array
                    const finalDate = entryDate || logs.find(l => l.id === logId)?.date_display.split(" ")[0];
                    
                    if (finalDate === calSelectedDateKey) {
                        renderDayEntries(calSelectedDateKey);
                    }
                }
            });
        }
    });
})();

async function calendarInit() {
    // Set initial page visibility
    const pageView = document.getElementById("calendar-view")?.closest(".page-view");
    if (pageView) {
        pageView.style.display = calPlugSettings.show_page ? "flex" : "none";
    }

    initCalendarMap();
    renderCalendar("cal");
    if (typeof injectCalendarWidget === 'function') injectCalendarWidget();
    updateCalendarStats();
}

// Listen for Lazy Hydration
window.addEventListener('cjos-hydrated', (e) => {
    if (e.detail.id === 'calendar-view') {
        calendarInit();
    }
});

if (window.registerRefreshHook) {
    window.registerRefreshHook(renderAllCalendars);
}
JS;
?>