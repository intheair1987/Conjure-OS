<?php
// ==============================================================================
// PLUGIN: Dynamic Header
// DESCRIPTION: Fluid Header Motion.
// 1. Decouples Page Titles from vertical scroll state (fixes the "jumping" bug).
// 2. Maps Page Title movement strictly to Horizontal Swipe progress.
// 3. Direct DOM manipulation for smooth 60fps animation.
// ==============================================================================

$plugin_settings_map['DynamicHeader'] = <<<'HTML'
    <!-- SECTION 1: HEADER & CONTROLS -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); cursor:pointer; margin-bottom:8px;" onclick="suiToggle('dh-acc-controls')">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Header & Controls</div>
        <span data-sui-icon="chevron" data-sui-arrow="dh-acc-controls" data-sui-size="14" style="transition:transform 0.35s; transform: rotate(0deg);"></span>
    </div>
    <div id="dh-acc-controls" class="sui-accordion open">
        <div class="sui-accordion-inner">
            <div class="setting-item vertical">
                <label class="setting-label">Header Behavior</label>
                <div class="setting-desc" style="color:var(--primary); font-weight:500;">✓ Dynamic & Responsive</div>
                <div class="setting-desc" style="margin-top:4px;">Header collapses on scroll, but expands smoothly when you swipe between pages.</div>
            </div>
            <div data-sui-setting="Persistent Settings Cog" data-sui-desc="Show the cog even when the header is contracted." data-sui-id="dh-toggle-cog-persistent" data-sui-onchange="dhSaveExtraSettings()"></div>
            <div class="setting-item" id="dh-cog-opacity-row">
                <div class="setting-text-wrap">
                    <label class="setting-label">Collapsed Cog Opacity</label>
                    <span class="setting-desc">Fades the cog when it is the only icon visible.</span>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <input type="range" id="dh-cog-opacity-slider" min="10" max="100" step="10" oninput="dhUpdateOpacityLabel(this.value)" onchange="dhSaveExtraSettings()" style="width:80px;">
                    <span id="dh-cog-opacity-val" style="font-size:12px; font-weight:700; color:var(--primary); min-width:35px;">50%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 2: STATUS DISPLAY -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); cursor:pointer; margin-bottom:16px;" onclick="suiToggle('dh-acc-status')">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Clock & Battery</div>
        <span data-sui-icon="chevron" data-sui-arrow="dh-acc-status" data-sui-size="14" style="transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>
    <div id="dh-acc-status" class="sui-accordion" style="display:none;">
        <div class="sui-accordion-inner">
            <div data-sui-setting="Battery Icon Mode" data-sui-desc="Show visual battery instead of percentage" data-sui-id="dh-battery-icon-mode" data-sui-onchange="dhHandleBatteryModeToggle(this.checked)"></div>
            <div data-sui-setting="Show Battery Number" data-sui-desc="Display percentage inside the icon" data-sui-id="dh-battery-show-number" data-sui-onchange="dhHandleBatteryNumberToggle(this.checked)"></div>
        </div>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- DYNAMIC HEADER JS (ISOLATED PHYSICS) ---

let dhRafId = null;
let dhCachedWidth = 0;
let dhIsSwiping = false;
let dhClockTimer = null;

// Cache Element References
let dhEls = {
    topBar: null,
    titleWrap: null,
    logo: null,
    actions: null,
    organizer: null,
    sectionHead: null,
    pageTitles: [] 
};

// Store original transforms
let dhTitleCache = new Map(); 

window.addEventListener("load", () => {
    // Load Cog Preferences
    const persistent = localStorage.getItem("cjos_dh_cog_persistent") !== "false"; // Default true
    const opacity = localStorage.getItem("cjos_dh_cog_opacity") || "50";
    
    const pToggle = document.getElementById("dh-toggle-cog-persistent");
    const oSlider = document.getElementById("dh-cog-opacity-slider");
    const oLabel = document.getElementById("dh-cog-opacity-val");
    
    if(pToggle) pToggle.checked = persistent;
    if(oSlider) oSlider.value = opacity;
    if(oLabel) oLabel.innerText = opacity + "%";
    
    dhApplyCogStyles(persistent, opacity);

    // 1. CLEANUP
    if (localStorage.getItem("cjos_dh_mode")) localStorage.removeItem("cjos_dh_mode");
    
    // 2. INIT REFERENCES
    const viewport = document.querySelector(".horizontal-viewport");
    if (!viewport) return;

    dhEls.topBar = document.querySelector(".top-bar");
    dhEls.titleWrap = document.querySelector(".title-wrapper");
    dhEls.logo = document.querySelector(".app-logo");
    dhEls.actions = document.querySelector(".default-actions");
    dhEls.clock = document.getElementById("header-clock");
    dhEls.organizer = document.getElementById("organizer-bar-wrapper");
    dhEls.sectionHead = document.querySelector(".section-header");
    
    // TARGETING: We only want to animate the titles on the "other" pages (Dashboard, ToDo)
    // We skip the first one (Entry Stream) because its behavior is handled by standard scroll
    const allTitles = document.querySelectorAll(".page-view .page-title");
    dhEls.pageTitles = Array.from(allTitles).slice(1); 

    // 3. CACHE DIMENSIONS
    dhCachedWidth = viewport.clientWidth;
    window.addEventListener("resize", () => { dhCachedWidth = viewport.clientWidth; });

    // 4. SCROLL LISTENER
    viewport.addEventListener("scroll", () => {
        if (dhRafId) return;
        dhRafId = window.requestAnimationFrame(() => {
            updateHeaderPhysics(viewport);
            dhRafId = null;
        });
    }, { passive: true });

    window.dhUpdateOpacityLabel = (val) => {
        document.getElementById("dh-cog-opacity-val").innerText = val + "%";
    };

    window.dhSaveExtraSettings = () => {
        const p = document.getElementById("dh-toggle-cog-persistent").checked;
        const o = document.getElementById("dh-cog-opacity-slider").value;
        localStorage.setItem("cjos_dh_cog_persistent", p);
        localStorage.setItem("cjos_dh_cog_opacity", o);
        dhApplyCogStyles(p, o);
    };

    window.dhHandleBatteryModeToggle = (isIcon) => {
        localStorage.setItem('cjos_battery_icon_mode', isIcon);
        if (typeof dhUpdateClock === 'function') dhUpdateClock();
    };

    window.dhHandleBatteryNumberToggle = (show) => {
        localStorage.setItem('cjos_battery_show_number', show);
        if (typeof dhUpdateClock === 'function') dhUpdateClock();
    };

    // --- CLOCK & BATTERY LOOP ---
    let battery = null;
    let isFetchingBattery = false;
    window.dhUpdateClock = async () => {
        const el = document.getElementById('header-clock');
        if (!el) return;

        if (!battery && navigator.getBattery && !isFetchingBattery) {
            isFetchingBattery = true;
            try {
                battery = await navigator.getBattery();
                const refresh = () => dhUpdateClock();
                battery.onlevelchange = refresh;
                battery.onchargingchange = refresh;
            } catch(e) {}
            isFetchingBattery = false;
        }

        const now = new Date();
        const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const day = days[now.getDay()];
        const month = months[now.getMonth()];
        const date = now.getDate();
        const hours = String(now.getHours()).padStart(2, '0');
        const mins = String(now.getMinutes()).padStart(2, '0');

        let batHtml = '';
        if (battery) {
            const level = Math.round(battery.level * 100);
            const charging = battery.charging ? '<span style="color:var(--primary); margin-right:1px; font-size:10px; vertical-align:middle;">⚡</span>' : '';
            const isIconMode = localStorage.getItem('cjos_battery_icon_mode') === 'true';
            const showNumber = localStorage.getItem('cjos_battery_show_number') !== 'false'; // Default true

            if (isIconMode) {
                const stateClass = battery.charging ? 'charging' : (level <= 20 ? 'low' : '');
                const numHtml = showNumber ? `<span class="battery-text">${level}</span>` : '';
                batHtml = ` • ${charging}<span class="battery-icon"><span class="battery-fill ${stateClass}" style="width:${level}%"></span>${numHtml}</span>`;
            } else {
                batHtml = ` • ${charging}${level}%`;
            }
        }
        el.innerHTML = `${day}, ${month} ${date} • ${hours}<span class="clock-sep">:</span>${mins}${batHtml}`;
    };

    // Initialize
    const batToggle = document.getElementById("dh-battery-icon-mode");
    if(batToggle) batToggle.checked = localStorage.getItem('cjos_battery_icon_mode') === 'true';
    
    const numToggle = document.getElementById("dh-battery-show-number");
    if(numToggle) numToggle.checked = localStorage.getItem('cjos_battery_show_number') !== 'false';

    window.dhStartClock = () => {
        dhStopClock();
        if (document.visibilityState !== "visible") return;
        dhUpdateClock();
        // Since we only display hours:minutes, a 10-second interval is highly precise and reduces CPU cycles by 90%
        dhClockTimer = setInterval(dhUpdateClock, 10000);
    };

    window.dhStopClock = () => {
        if (dhClockTimer) {
            clearInterval(dhClockTimer);
            dhClockTimer = null;
        }
    };

    dhStartClock();

    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") {
            dhStartClock();
        } else {
            dhStopClock();
        }
    });

    function dhApplyCogStyles(persistent, opacity) {
        let style = document.getElementById("dh-cog-logic-css");
        if (!style) {
            style = document.createElement("style");
            style.id = "dh-cog-logic-css";
            document.head.appendChild(style);
        }

        if (!persistent) {
            style.innerHTML = `
                body.header-collapsed #settings-btn,
                body.select-mode #settings-btn {
                    opacity: 0 !important;
                    visibility: hidden !important;
                    pointer-events: none !important;
                }
            `;
        } else {
            style.innerHTML = `
                body.header-collapsed #settings-btn {
                    opacity: ${opacity / 100} !important;
                    visibility: visible !important;
                    pointer-events: auto !important;
                    transform: scale(0.9) !important;
                }
                /* Selection mode always overrides persistence */
                body.select-mode #settings-btn {
                    opacity: 0 !important;
                    visibility: hidden !important;
                    pointer-events: none !important;
                }
                #settings-btn {
                    transition: opacity 0.3s ease, transform 0.3s ease !important;
                }
            `;
        }
        
        const opacityRow = document.getElementById("dh-cog-opacity-row");
        if(opacityRow) opacityRow.style.opacity = persistent ? "1" : "0.3";
    }

    // 5. INJECT BASE CSS & OVERRIDES
    const style = document.createElement("style");
    style.innerHTML = `
        #header-clock {
            position: absolute; top: -10px; right: 0;
            font-size: 8.5px; font-weight: 800; color: var(--text-secondary);
            text-transform: uppercase; letter-spacing: 0.8px; line-height: 1;
            opacity: 0.7; font-family: system-ui, -apple-system, sans-serif;
            white-space: nowrap; pointer-events: none; z-index: 1010;
            animation: clock-to-expanded 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        body.header-collapsed #header-clock {
            animation: clock-to-collapsed 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes clock-to-collapsed {
            0% { opacity: 0.7; top: -10px; right: 0; font-size: 8.5px; }
            25% { opacity: 0; top: -10px; right: 0; }
            75% { opacity: 0; top: 11px; right: 42px; }
            100% { opacity: 1; top: 11px; right: 42px; font-size: 9px; }
        }
        @keyframes clock-to-expanded {
            0% { opacity: 1; top: 11px; right: 42px; font-size: 9px; }
            25% { opacity: 0; top: 11px; right: 42px; }
            75% { opacity: 0; top: -10px; right: 0; }
            100% { opacity: 0.7; top: -10px; right: 0; font-size: 8.5px; }
        }
        @keyframes clock-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        .clock-sep { animation: clock-blink 2s infinite; font-weight: 700; }

        .battery-icon {
            display: inline-block; width: 22px; height: 11px; border: 1px solid currentColor;
            border-radius: 2px; position: relative; margin-left: 5px; vertical-align: middle;
            top: -1px; /* Visual alignment nudge */
            overflow: hidden; padding: 1px; box-sizing: border-box; opacity: 0.9;
        }
        .battery-icon::after {
            /* The battery tip */
            content: ''; position: absolute; right: -3.5px; top: 2.5px; width: 2.5px; height: 4px;
            background: currentColor; border-radius: 0 1px 1px 0;
        }
        .battery-fill {
            height: 100%; background: currentColor; border-radius: 0.5px; display: block;
            transition: width 0.3s ease, background-color 0.3s;
        }
        .battery-fill.low { background-color: var(--danger); }
        .battery-fill.charging { background-color: var(--primary); }
        
        .battery-text {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 7px; font-weight: 900; line-height: 1;
            color: #FFFFFF;
            /* 4-way text shadow creates a sharp outline for maximum contrast */
            text-shadow: 0.6px 0.6px 0 #000, -0.6px -0.6px 0 #000, 0.6px -0.6px 0 #000, -0.6px 0.6px 0 #000;
            pointer-events: none; z-index: 2;
            letter-spacing: -0.2px;
        }

        /* 1. Disable Transitions during swipe for sync */
        body.is-swiping .top-bar,
        body.is-swiping .title-wrapper,
        body.is-swiping .app-logo,
        body.is-swiping .default-actions,
        body.is-swiping #organizer-bar-wrapper,
        body.is-swiping .section-header,
        body.is-swiping #header-clock,
        body.is-swiping .page-view .page-title {
            transition: none !important;
            animation: none !important;
        }

        /* 2. SWIPE OVERRIDE: Force buttons to be visible and transitional-free during swipe */
        body.is-swiping .default-actions button:not(#settings-btn) {
            visibility: visible !important;
            pointer-events: auto !important;
            transition: none !important;
        }

        /* 3. ISOLATION FIX: Prevent Page Titles on secondary pages from reacting to vertical scroll */
        body.header-collapsed .page-view:not(:first-child) .page-title {
            top: calc(var(--header-base-height) + 12px) !important; /* Force Expanded Position */
            margin-top: 0 !important;
            transition: none !important; /* Stop CSS transitions from firing on class toggle */
        }
    `;
    document.head.appendChild(style);
});

function updateHeaderPhysics(viewport) {
    const x = viewport.scrollLeft;
    
    if (dhCachedWidth === 0) dhCachedWidth = viewport.clientWidth || window.innerWidth;
    
    // Swipe Mode Detection
    const isCollapsed = document.body.classList.contains("header-collapsed");
    const isMoving = x > 2; 

    if (isMoving && isCollapsed) {
        if (!dhIsSwiping) {
            document.body.classList.add("is-swiping");
            dhIsSwiping = true;
            
            // Capture Base Transforms
            dhTitleCache.clear();
            dhEls.pageTitles.forEach(pt => {
                const computed = window.getComputedStyle(pt).transform;
                dhTitleCache.set(pt, computed === "none" ? "" : computed);
            });
        }
    } else {
        if (dhIsSwiping) {
            document.body.classList.remove("is-swiping");
            dhIsSwiping = false;
            clearInlineStyles();
        }
        return; 
    }

    // Progress (0.0 = Page 1/Collapsed, 1.0 = Page 2/Expanded)
    let p = x / dhCachedWidth;
    if (p < 0) p = 0;
    if (p > 1) p = 1;

    // If we have swiped significantly toward page 2, ensure the system state
    // reflects an expanded header so buttons become interactive and visible.
    if (p > 0.9) {
        document.body.classList.remove("header-collapsed");
    }

    // --- PHYSICS CALCS ---
    const baseH = 64; 
    const collH = 44; 
    const diff = baseH - collH; // 20px
    const innerPad = 0; 

    // 1. Header Height (44 -> 64)
    const currentH = collH + (diff * p);
    if(dhEls.topBar) dhEls.topBar.style.setProperty("height", (currentH + innerPad) + "px", "important");

    // 2. Title Scale
    const currentScale = 0.7 + (0.3 * p);
    if(dhEls.titleWrap) dhEls.titleWrap.style.setProperty("transform", `scale(${currentScale})`, "important");

    // 2.5 Clock Physics (Fade Out then Fade In)
    if (dhEls.clock) {
        const clockTop = 11 - (21 * p);    // 11px (collapsed) -> -10px (expanded)
        const clockRight = 42 * (1 - p);   // 42px (collapsed) -> 0px (expanded)
        
        // 3-Stage Opacity Curve:
        // Dead zone adjusted for snappier 0.6s feel
        let clockOpac = 0;
        if (p < 0.2) {
            clockOpac = 1 - (p / 0.2);
        } else if (p > 0.8) {
            clockOpac = ((p - 0.8) / 0.2) * 0.7;
        }
        
        dhEls.clock.style.setProperty("top", clockTop + "px", "important");
        dhEls.clock.style.setProperty("right", clockRight + "px", "important");
        dhEls.clock.style.setProperty("opacity", clockOpac, "important");
    }

    // 3. Actions (Opacity & Slide) - Target only non-settings buttons
    if(dhEls.actions) {
        dhEls.actions.style.setProperty("visibility", "visible", "important");
        dhEls.actions.style.setProperty("pointer-events", "auto", "important");
        
        // We no longer set opacity on the parent container (dhEls.actions)
        // Instead, we target the individual buttons that should fade during the swipe
        const otherBtns = dhEls.actions.querySelectorAll("button:not(#settings-btn)");
        otherBtns.forEach(btn => {
            btn.style.setProperty("opacity", p, "important");
            btn.style.setProperty("transform", `translateY(${-10 * (1 - p)}px)`, "important");
        });

        // The Settings Cog stays at 1.0 opacity to act as a persistent anchor
        const cog = document.getElementById("settings-btn");
        if (cog) cog.style.setProperty("opacity", "1", "important");
    }

    // 4. Organizer Bar
    if(dhEls.organizer) dhEls.organizer.style.setProperty("top", (currentH + innerPad + 1) + "px", "important");

    // 5. Section Header
    if(dhEls.sectionHead) dhEls.sectionHead.style.setProperty("top", (-20 + (20 * p)) + "px", "important");

    // 6. Page Titles (Sync Position & Scale)
    // Logic: 
    // At p=0 (Start of Swipe), the header is SMALL (44px).
    // The CSS Override forces the title to be at the LARGE position (76px or equivalent).
    // So visually, the title is too low.
    // We must TRANSLATE UP (-20px) to match the small header.
    // As p -> 1, we translate to 0px (matching the now-large header).
    
    const ptTranslateY = -diff * (1 - p); // -20px -> 0px
    const ptScale = 0.9 + (0.1 * p); // 0.9 -> 1.0
    const ptOpac = 0.5 + (0.5 * p); // 0.5 -> 1.0
    
    dhEls.pageTitles.forEach(pt => {
        const baseTransform = dhTitleCache.get(pt) || "";
        const newTransform = `${baseTransform} translateY(${ptTranslateY}px) scale(${ptScale})`;
        
        pt.style.setProperty("transform", newTransform, "important");
        pt.style.setProperty("opacity", ptOpac, "important");
        pt.style.setProperty("transform-origin", "left center", "important");
    });
}

function clearInlineStyles() {
    if(dhEls.topBar) dhEls.topBar.style.removeProperty("height");
    if(dhEls.titleWrap) dhEls.titleWrap.style.removeProperty("transform");
    if(dhEls.clock) {
        dhEls.clock.style.removeProperty("top");
        dhEls.clock.style.removeProperty("right");
        dhEls.clock.style.removeProperty("opacity");
    }
    if(dhEls.actions) {
        dhEls.actions.style.removeProperty("visibility");
        dhEls.actions.style.removeProperty("pointer-events");
        dhEls.actions.style.removeProperty("opacity");
        dhEls.actions.style.removeProperty("transform");
        // CRITICAL FIX: Explicitly clear the inline styles of child buttons
        dhEls.actions.querySelectorAll("button").forEach(btn => {
            btn.style.removeProperty("opacity");
            btn.style.removeProperty("transform");
        });
    }
    if(dhEls.organizer) dhEls.organizer.style.removeProperty("top");
    if(dhEls.sectionHead) dhEls.sectionHead.style.removeProperty("top");
    
    dhEls.pageTitles.forEach(pt => {
        pt.style.removeProperty("transform");
        pt.style.removeProperty("opacity");
        pt.style.removeProperty("transform-origin");
    });
}
JS;
?>