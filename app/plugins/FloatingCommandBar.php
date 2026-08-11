<?php
// ==============================================================================
// PLUGIN: Floating Command Bar
// DESCRIPTION: UI Skin for the Floating Recorder.
// Provides a horizontal, swipeable command strip as an alternative to the FAB.
// ==============================================================================

$fr_config_file = CJOS_PATH_DATA . '/floating-recorder-config.json';
$fr_config_raw = file_exists($fr_config_file) ? json_decode(file_get_contents($fr_config_file), true) : [];
$fr_init_mode = $fr_config_raw['ui_mode'] ?? 'fab';
$fcb_init_show = ($fr_init_mode === 'bar' ? 'body.fcb-mode #fcb-container { opacity: 1 !important; visibility: visible !important; pointer-events: auto !important; }' : '');
$fcb_snapshot = $fr_config_raw['fcb_nav_snapshot'] ?? '';
$plugin_overlays[] = "<style>$fcb_init_show</style><script>window._fcbNavSnapshot = " . json_encode($fcb_snapshot) . "; window._fcbLoadLock = true; setTimeout(() => { window._fcbLoadLock = false; console.log('[FCB] Load Lock Released'); }, 10000);</script>";

$fcb_base_html = <<<'HTML'
<style>
    /* HIDE OLD FAB WHEN FCB IS ACTIVE */
    body.fcb-mode #fab-record, 
    body.fcb-mode #fr-action-menu {
        display: none !important;
    }

    #fcb-container {
        position: fixed;
        left: 50%;
        transform: translateX(-50%);
        height: 60px;
        background: var(--glass-bg);
        backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px);
        border: 1px solid var(--glass-border);
        box-shadow: var(--shadow-floating);
        display: flex;
        align-items: center;
        z-index: 20000; /* Match elevated FAB z-index */
        touch-action: pan-x;
        
        /* Hidden by default, shown via body.fcb-mode */
        opacity: 0; visibility: hidden; pointer-events: none;
        transition: 
            bottom 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275),
            opacity 0.4s ease, 
            visibility 0.4s,
            width 0.4s cubic-bezier(0.2, 0, 0.2, 1),
            transform 0.4s cubic-bezier(0.2, 0, 0.2, 1);
    }

    /* Always visible in fcb-mode, regardless of overlays */
    body.fcb-mode #fcb-container {
        opacity: 1; visibility: visible; pointer-events: auto;
    }

    /* Lift above selection bar when active, but drop down if a menu is open */
    body.fcb-mode.select-mode:not(.sui-overlay-open) #fcb-container {
        bottom: calc(var(--fab-bottom-offset, 0px) + 110px) !important;
    }

    /* FLOATING vs DOCKED STATES */
    #fcb-container {
        bottom: calc(var(--fab-bottom-offset, 0px) + var(--fcb-bottom-offset, 24px));
        width: 92%;
        max-width: 420px;
        border-radius: 30px;
        padding: 0 8px;
    }
    
    body.fcb-docked #fcb-container {
        bottom: 0;
        width: 100%;
        max-width: none;
        border-radius: 0;
        border-bottom: none;
        border-left: none;
        border-right: none;
        padding: 0 12px;
        padding-bottom: env(safe-area-inset-bottom, 0px);
        height: calc(60px + env(safe-area-inset-bottom, 0px));
    }

    /* INTERNAL LAYOUT */
    #fcb-left {
        display: flex;
        align-items: center;
        gap: 6px;
        padding-right: 2px;
        border-right: 1px solid var(--border-color);
        height: 48px;
        flex-shrink: 0;
        transition: width 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        overflow: hidden;
    }

    #fcb-hidden-btns {
        display: flex;
        align-items: center;
        gap: 6px;
        width: 0;
        opacity: 0;
        transform: translateX(-20px);
        transition: width 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), 
                    opacity 0.3s ease, 
                    transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        pointer-events: none;
    }

    #fcb-left.show-hidden #fcb-hidden-btns {
        width: 94px; /* 2 buttons * 44px + gap */
        opacity: 1;
        transform: translateX(0);
        pointer-events: auto;
    }

    .fcb-btn {
        width: 44px; height: 44px;
        border-radius: 50%;
        border: none; background: transparent;
        color: var(--text-secondary);
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
    }
    .fcb-btn:active { background: rgba(0,0,0,0.05); transform: scale(0.9); }

    /* RECORD BUTTON DYNAMICS */
    #fcb-btn-record {
        background: color-mix(in srgb, var(--primary), transparent 85%);
        color: var(--primary);
    }
    body.is-recording #fcb-btn-record {
        background: var(--danger) !important;
        color: white !important;
        animation: fcb-pulse 1.5s infinite;
    }

    /* OMNIBUTTON DYNAMICS */
    body.fcb-omni #fcb-btn-record,
    body.fcb-omni #fcb-btn-back {
        display: none !important;
    }
    #fcb-btn-omni {
        display: none;
        background: color-mix(in srgb, var(--primary), transparent 90%);
        color: var(--text-secondary);
        touch-action: none;
    }
    body.fcb-omni #fcb-btn-omni {
        display: flex;
    }
    body.fcb-omni.is-recording #fcb-btn-omni {
        background: var(--danger) !important;
        color: white !important;
        animation: fcb-pulse 1.5s infinite;
    }
    body.is-recording #fcb-icon-mic { display: none; }
    body.is-recording #fcb-icon-stop { display: block !important; }

    @keyframes fcb-pulse {
        0% { box-shadow: 0 0 0 0 rgba(255, 59, 48, 0.4); }
        70% { box-shadow: 0 0 0 15px rgba(255, 59, 48, 0); }
        100% { box-shadow: 0 0 0 0 rgba(255, 59, 48, 0); }
    }

    /* --- DESKTOP UNIFIED LAYOUT --- */
    @media (min-width: 1024px) {
        #fcb-container {
            max-width: 1400px !important;
            width: fit-content !important;
            min-width: 600px;
            padding: 0 16px !important;
            border-radius: 30px !important;
        }

        /* Always show utility buttons on desktop */
        #fcb-left {
            width: auto !important;
            padding-right: 16px;
        }
        #fcb-hidden-btns {
            width: auto !important;
            opacity: 1 !important;
            transform: none !important;
            pointer-events: auto !important;
            margin-left: 8px;
        }

        #fcb-right {
            display: flex !important;
            flex-direction: row !important;
            align-items: center;
            gap: 0;
            overflow: visible !important;
        }

        /* Force both strips to be visible and side-by-side */
        .fcb-strip {
            position: relative !important;
            width: auto !important;
            height: 100% !important;
            opacity: 1 !important;
            transform: none !important;
            pointer-events: auto !important;
            overflow: visible !important;
            display: flex !important;
        }

        /* Visual Divider between Pages and Actions */
        #fcb-strip-pages::after {
            content: "";
            width: 1px;
            height: 24px;
            background: var(--border-color);
            margin: 0 12px;
            align-self: center;
            flex-shrink: 0;
            opacity: 0.6;
        }

        /* Prevent the 'show-actions' class from hiding pages on desktop */
        #fcb-container.show-actions #fcb-strip-pages {
            transform: none !important;
            opacity: 1 !important;
            pointer-events: auto !important;
        }
        
        .fcb-strip-item {
            min-width: 64px;
        }
    }

    /* HIDE LEFT SECTION MODE */
    body.fcb-hide-left #fcb-left {
        display: none !important;
    }
    body.fcb-hide-left #fcb-right {
        margin-left: 0 !important;
    }

    /* RIGHT SIDE (STRIPS) */
    #fcb-right {
        flex: 1;
        height: 100%;
        position: relative;
        overflow: hidden;
        margin-left: 6px;
        /* Force GPU layer for the container of the strips */
        transform: translateZ(0);
    }

    .fcb-strip {
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        display: flex;
        align-items: center;
        gap: 4px;
        padding: 0 8px;
        box-sizing: border-box;
        overflow-x: auto;
        overflow-y: hidden;
        scroll-behavior: smooth;
        -webkit-overflow-scrolling: touch;
        /* Hide scrollbar */
        scrollbar-width: none;
        touch-action: pan-x;
        
        /* Promote strips to GPU layers immediately on load */
        will-change: transform, opacity;
        transform: translateZ(0);
        
        transition: transform 0.22s cubic-bezier(0.2, 0, 0.2, 1), opacity 0.2s;
    }

    /* INSTANT FEEDBACK: Disable transitions during Fast Travel to prevent layer freezing */
    #fcb-container.fast-travel-active,
    #fcb-container.fast-travel-active .fcb-strip,
    #fcb-container.fast-travel-active .fcb-strip-item {
        transition: none !important;
    }
    .fcb-strip::-webkit-scrollbar { display: none; }

    /* STRIP ANIMATION STATES */
    #fcb-strip-pages { transform: translateY(0); opacity: 1; }
    #fcb-strip-actions { transform: translateY(100%); opacity: 0; }

    #fcb-container.show-actions #fcb-strip-pages { transform: translateY(-100%); opacity: 0; }
    #fcb-container.show-actions #fcb-strip-actions { transform: translateY(0); opacity: 1; }

    /* STRIP BUTTONS */
    .fcb-strip-item {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        min-width: 56px; height: 50px;
        border-radius: 12px;
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.2s;
        flex-shrink: 0;
    }
    .fcb-strip-item:active { background: rgba(0,0,0,0.05); transform: scale(0.95) !important; }
    .fcb-strip-item svg { width: 22px; height: 22px; margin-bottom: 2px; }
    .fcb-strip-item span { font-size: 9px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; }
    
    .fcb-strip-item {
        position: relative;
        transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275), color 0.2s;
    }

    .fcb-strip-item.active {
        color: var(--primary);
        transform: scale(1.05) !important;
        background: color-mix(in srgb, var(--primary), transparent 92%);
        box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--primary), transparent 85%);
    }

    /* Active Indicator Dot */
    .fcb-strip-item.active::after {
        content: "";
        position: absolute;
        bottom: 2px;
        left: 50%;
        transform: translateX(-50%);
        width: 16px;
        height: 3px;
        border-radius: 2.5px;
        background: var(--primary);
        box-shadow: 0 0 8px var(--primary);
    }

    /* FAST TRAVEL VISUAL FEEDBACK */
    #fcb-container.fast-travel-active {
        transform: translateX(-50%) scale(1.05) !important;
        border-color: var(--primary) !important;
        box-shadow: 0 0 20px color-mix(in srgb, var(--primary), transparent 60%), var(--shadow-floating) !important;
    }
    #fcb-container.fast-travel-active .fcb-strip {
        /* Prevent buttons from scrolling/reacting during fast travel */
        pointer-events: none !important;
        overflow-x: hidden !important;
    }
    #fcb-container.fast-travel-active .fcb-strip-item {
        opacity: 0.3;
        transition: opacity 0.2s ease;
    }
    #fcb-container.fast-travel-active .fcb-strip-item.active {
        opacity: 1 !important;
    }
    
    /* TOAST OVERRIDE STYLES (For FabToaster Integration) */
    #fcb-container.ft-active #fcb-left,
    #fcb-container.ft-active #fcb-right {
        opacity: 0; pointer-events: none;
    }
    /* NOTIFICATION TICKER (ABOVE BAR) */
    #fcb-notif-layer {
        position: fixed;
        /* Positioned relative to screen bottom to sit above the bar */
        bottom: calc(var(--fab-bottom-offset, 0px) + var(--fcb-bottom-offset, 24px) + 68px);
        left: 50%;
        transform: translateX(-50%) translateY(10px);
        width: auto;
        min-width: 120px;
        max-width: 90vw;
        height: 32px;
        background: var(--glass-bg);
        /* Standardized blur to 15px and added GPU hints to force rendering */
        backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px);
        will-change: backdrop-filter, transform;
        transform: translateX(-50%) translateY(10px) translateZ(0);
        
        border: 1px solid var(--glass-border);
        border-radius: 16px;
        display: flex;
        align-items: center;
        padding: 0 16px;
        box-shadow: var(--shadow-floating);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        z-index: 20001; /* Stay above the container */
        overflow: hidden;
    }

    /* Adjust position for Docked mode */
    body.fcb-docked #fcb-notif-layer {
        bottom: calc(68px + env(safe-area-inset-bottom, 0px));
    }

    #fcb-notif-layer.ft-active {
        opacity: 1;
        visibility: visible;
        transform: translateX(-50%) translateY(0);
    }

    #fcb-notif-layer .ft-text-inner {
        font-size: 12px;
        font-weight: 700;
        color: var(--primary);
        white-space: nowrap;
    }

    /* Pulse effect for the ticker */
    #fcb-notif-layer.ft-active::after {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: inherit;
        border: 1px solid var(--primary);
        opacity: 0;
        animation: fcb-notif-pulse 2s infinite;
    }

    @keyframes fcb-notif-pulse {
        0% { transform: scale(1); opacity: 0.5; }
        100% { transform: scale(1.1, 1.3); opacity: 0; }
    }

    /* FAST TRAVEL DIAL OVERLAY */
    #fcb-dial-overlay {
        position: fixed;
        top: 0; left: 50%;
        width: 300px; height: 74px;
        background: var(--glass-bg);
        backdrop-filter: blur(25px); -webkit-backdrop-filter: blur(25px);
        border: 1px solid var(--glass-border);
        border-radius: 37px;
        z-index: 22000;
        display: flex; align-items: center;
        
        /* WARMUP: Keep visible in layout but transparent. 
           Removing 'visibility: hidden' prevents the GPU from "discarding" the layer. */
        opacity: 0; pointer-events: none;
        
        /* Kill transitions entirely for the dial to ensure 1:1 finger tracking */
        transition: none !important;
        
        /* Fade out edges */
        mask-image: linear-gradient(to right, transparent 0%, black 25%, black 75%, transparent 100%);
        -webkit-mask-image: linear-gradient(to right, transparent 0%, black 25%, black 75%, transparent 100%);
        
        /* Force GPU promotion */
        will-change: transform;
        transform: translate3d(-50%, 0, 0);
        
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    }
    #fcb-dial-overlay.active {
        opacity: 1; visibility: visible;
    }
    #fcb-dial-track {
        display: flex; align-items: center;
        /* Smooth micro-adjustments for sliding */
        transition: transform 0.1s linear; 
    }
    .fcb-dial-item {
        width: 70px; height: 70px;
        flex-shrink: 0;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        color: var(--text-secondary);
        /* Faded state for non-active items */
        opacity: 0.35;
        transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), color 0.2s, opacity 0.2s;
    }
    .fcb-dial-item.active {
        color: var(--primary);
        transform: scale(1.2);
        opacity: 1;
    }
    .fcb-dial-item svg { width: 26px; height: 26px; margin-bottom: 4px; stroke-width: 2.2; }
    .fcb-dial-item span { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
</style>

<div id="fcb-dial-overlay">
    <div id="fcb-dial-track"></div>
</div>

<div id="fcb-notif-layer"></div>
<div id="fcb-container">
    <div id="fcb-left">
        <button id="fcb-btn-record" class="fcb-btn" onclick="if(typeof frToggleRecording === 'function') frToggleRecording()">
            <svg id="fcb-icon-mic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
            <svg id="fcb-icon-stop" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="display:none;"><rect x="6" y="6" width="12" height="12" rx="2"></rect></svg>
        </button>
        <button id="fcb-btn-back" class="fcb-btn" 
                onpointerdown="fcbBackStart(event)" 
                onpointerup="fcbBackEnd(event)" 
                onpointerleave="fcbBackEnd(event)" 
                onclick="fcbHandleBack()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="var(--sui-icon-stroke, 1.5)"><polyline points="15 18 9 12 15 6"></polyline></svg>
        </button>

        <button id="fcb-btn-omni" class="fcb-btn"
                onpointerdown="fcbOmniStart(event)"
                onclick="fcbOmniClick()">
            <div id="fcb-omni-icon-wrap" style="display:flex; align-items:center; justify-content:center; gap:2px; width:100%; height:100%;">
                <!-- Back Icon (Lucide Chevron-Left) -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="calc(var(--sui-icon-stroke, 1.5) + 0.5)" style="width:13px; height:13px; flex-shrink:0; margin-left:-2px;"><polyline points="15 18 9 12 15 6"></polyline></svg>
                
                <!-- Longer Slash (Custom Path for height) -->
                <svg viewBox="0 0 10 24" fill="none" stroke="currentColor" stroke-width="calc(var(--sui-icon-stroke, 1.5) - 0.3)" style="width:7px; height:24px; flex-shrink:0; opacity:0.3; margin:0 1px;"><line x1="8" y1="2" x2="2" y2="22"></line></svg>
                
                <!-- Mic Icon (Lucide Mic) -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="var(--sui-icon-stroke, 1.5)" style="width:13px; height:13px; flex-shrink:0;"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line></svg>
            </div>
            <svg id="fcb-omni-icon-stop" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="display:none; width:18px; height:18px;"><rect x="6" y="6" width="12" height="12" rx="2"></rect></svg>
        </button>
        
        <!-- HIDDEN BUTTONS (Swipe to reveal) -->
        <div id="fcb-hidden-btns">
            <button id="fcb-btn-switch" class="fcb-btn" onclick="frUpdateUiMode('fab')" title="Switch to FAB">
                <span data-sui-icon="minimize-2" data-sui-size="18"></span>
            </button>
            <button id="fcb-btn-settings" class="fcb-btn" onclick="frOpenStudio()" title="Recorder Settings">
                <span data-sui-icon="sliders" data-sui-size="18"></span>
            </button>
        </div>
    </div>
    <div id="fcb-right">
        <div id="fcb-strip-pages" class="fcb-strip">{{SNAPSHOT}}</div>
        <div id="fcb-strip-actions" class="fcb-strip"></div>
    </div>
</div>
<script>
(function(){
    const strip = document.getElementById('fcb-strip-pages');
    if (strip && strip.children.length > 0) {
        const h = () => {
            if (window.suiHydrateIcons) window.suiHydrateIcons(strip);
            else setTimeout(h, 50);
        };
        h();
    }
})();
</script>
HTML;
$plugin_overlays[] = str_replace('{{SNAPSHOT}}', $fcb_snapshot, $fcb_base_html);

$plugin_js .= <<<'JS'
// --- FLOATING COMMAND BAR JS ---

let fcbItemStartX = 0;
let fcbItemStartY = 0;
let fcbItemActiveId = null;

window.fcbItemStart = function(e, id) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    fcbItemStartX = e.clientX;
    fcbItemStartY = e.clientY;
    fcbItemActiveId = id;
};

window.fcbItemEnd = function(e, id, isPage) {
    if (fcbItemActiveId !== id) return;
    const dx = e.clientX - fcbItemStartX;
    const dy = e.clientY - fcbItemStartY;
    
    // Tap triggers if drift distance is negligible (under 8px)
    if (Math.sqrt(dx*dx + dy*dy) < 8) {
        if (isPage) {
            window.fcbHandlePageTap(id);
        } else {
            window.fcbExecuteAction(id);
        }
    }
    fcbItemActiveId = null;
};

window.addEventListener('load', () => {
    // Register as a system refresh hook so we update when order/visibility changes
    if (window.registerRefreshHook) {
        window.registerRefreshHook(window.fcbRender);
    }
    // Initial render
    setTimeout(window.fcbRender, 600);
});

window.fcbRender = function() {
    const pagesStrip = document.getElementById('fcb-strip-pages');
    const actionsStrip = document.getElementById('fcb-strip-actions');
    const container = document.getElementById('fcb-container');
    if (!pagesStrip || !actionsStrip || !container) return;

    // 1. POPULATE PAGES STRIP
    // Only respect the lock if we actually have a snapshot to show.
    if (window._fcbLoadLock && window._fcbNavSnapshot) {
        // If snapshot is already rendered by PHP, we just need to ensure highlight is correct.
        if (pagesStrip.children.length === 0) {
            pagesStrip.innerHTML = window._fcbNavSnapshot;
            if (window.suiHydrateIcons) window.suiHydrateIcons(pagesStrip);
        }
        
        // Always trigger highlight to set the correct active item
        const viewport = document.querySelector('.horizontal-viewport');
        if (viewport) viewport.dispatchEvent(new Event('scroll'));
    } else {
        // Lock released: Scan actual DOM order
        const pagesInDom = Array.from(document.querySelectorAll(".horizontal-viewport > .page-view:not(.dash-dynamic-portal)"));
        let pageItemsHtml = '';

        pagesInDom.forEach((page, idx) => {
            let pageId = "";
            let pageName = "Page";
            let icon = "layout";

            if (idx === 0) {
                pageId = "main-scroll";
                pageName = "Stream";
                icon = "list";
            } else {
                const scroller = page.querySelector(".scroll-view");
                pageId = scroller ? scroller.id : page.dataset.pageId;
            }

            if (!pageId) return;
            if (page.style.display === 'none') return;
            if (typeof dashVisibility !== 'undefined' && dashVisibility['page_' + pageId] === false) return;

            if (typeof dashRegisteredTools !== 'undefined') {
                const tool = dashRegisteredTools.find(t => t.linked_page === pageId);
                if (tool) {
                    pageName = tool.name.split(' ')[0].substring(0, 8);
                    icon = tool.sui_icon || "layout";
                } else if (pageId === 'dashboard-scroll-view') {
                    pageName = "Dash";
                    icon = "gauge";
                }
            }

            const iconHtml = (pageId === 'main-scroll') ? '' : `<span data-sui-icon="${icon}" data-sui-size="22" data-sui-stroke="2"></span>`;
            pageItemsHtml += `
                <div class="fcb-strip-item" data-page-id="${pageId}" onpointerdown="fcbItemStart(event, '${pageId}')" onpointerup="fcbItemEnd(event, '${pageId}', true)">
                    ${iconHtml}
                    <span>${pageName}</span>
                </div>
            `;
        });

        // Update only if changed to prevent layout thrashing
        if (pageItemsHtml && pageItemsHtml !== pagesStrip.innerHTML) {
            pagesStrip.innerHTML = pageItemsHtml;
            if (window.suiHydrateIcons) window.suiHydrateIcons(pagesStrip);
            
            // Save Snapshot to Server (Persistent across LocalStorage clears)
            if (typeof window.frGetSettings === 'function' && typeof window.frSaveExtraConfig === 'function') {
                const settings = window.frGetSettings();
                settings.fcb_nav_snapshot = pageItemsHtml;
                window.frSaveExtraConfig();
            }
        }
    }

    // 2. POPULATE ACTIONS STRIP
    let actionItemsHtml = '';
    if (typeof window.frGetSettings === 'function' && typeof window.frGetRegistry === 'function') {
        const settings = window.frGetSettings();
        const registry = window.frGetRegistry();
        const order = settings.tier_order || [];
        
        order.forEach(id => {
            if (id === 'empty') return;
            let act = registry.find(a => a.id === id);
            if (!act) return;

            let icon = act.icon;
            let label = act.label;
            if (id === 'theme' && typeof tpState !== 'undefined') {
                const isDark = tpState.mode === 'dark';
                icon = isDark ? 'sun' : 'moon';
                label = isDark ? 'Light' : 'Dark';
            }

            actionItemsHtml += `
                <div class="fcb-strip-item" data-action-id="${act.id}" onpointerdown="fcbItemStart(event, '${act.id}')" onpointerup="fcbItemEnd(event, '${act.id}', false)">
                    <span data-sui-icon="${icon}" data-sui-size="22" data-sui-stroke="2"></span>
                    <span>${label}</span>
                </div>
            `;
        });
    }
    actionsStrip.innerHTML = actionItemsHtml;

    if (window.suiHydrateIcons) {
        window.suiHydrateIcons(pagesStrip);
        window.suiHydrateIcons(actionsStrip);
    }

    // 2.5 SWIPE TO REVEAL HIDDEN BUTTONS (LEFT SECTION)
    const leftSection = document.getElementById('fcb-left');
    
    if (!window._fcbTouchListenersAttached) {
        window._fcbTouchListenersAttached = true;
        
        let leftStartX = 0;
        
        leftSection.addEventListener('touchstart', (e) => {
        leftStartX = e.touches[0].clientX;
        e.stopPropagation(); // Prevent triggering page strip gestures
    }, {passive: true});

    leftSection.addEventListener('touchend', (e) => {
        const deltaX = e.changedTouches[0].clientX - leftStartX;
        if (Math.abs(deltaX) > 30) {
            // Horizontal Swipe (Either direction) -> Toggle Hidden Buttons
            leftSection.classList.toggle('show-hidden');
            window.sui.haptic('light');
        }
    }, {passive: true});

    // 3. GESTURE LOGIC (Swipe Up for Actions + Long-Press Fast Travel)
    let startX = 0;
    let startY = 0;
    let lastTouchX = 0;
    let lastTouchY = 0;
    let revertTimer = null;
    
    // Fast Travel State
    let fcbIsFastTravel = false;
    let fcbFastTravelMode = 0; // 0: Pages, 1: Actions
    let fcbLongPressTimer = null;
    let fcbStartPage = 0;
    let fcbTargetScrollX = 0;
    let fcbLastHapticPage = -1;
    let fcbPageIdCache = [];
    let fcbActionIdCache = [];
    let fcbTargetActionId = null;
    let fcbActionStartX = 0; // New anchor for decoupled action selection
    let fcbActionLockoutTime = 0; // Horizontal selection lockout timestamp

    let fcbLerpRafId = null;
    const fcbLerpLoop = () => {
        // PERSISTENT LOOP: Stays alive while Fast Travel gesture is active
        if (!fcbIsFastTravel || document.hidden) {
            fcbLerpRafId = null;
            return;
        }
        
        const viewport = document.querySelector('.horizontal-viewport');
        if (viewport) {
            const current = viewport.scrollLeft;
            const diff = fcbTargetScrollX - current;
            // Smoothly slide pages to target snap point
            if (Math.abs(diff) > 0.1) {
                viewport.scrollLeft += diff * 0.15;
            }
        }

        // AGGRESSIVE VISUAL ENFORCEMENT
        // Ensure the container class matches the logical mode every frame
        const container = document.getElementById('fcb-container');
        if (container) {
            const hasClass = container.classList.contains('show-actions');
            if (fcbFastTravelMode === 1 && !hasClass) container.classList.add('show-actions');
            if (fcbFastTravelMode === 0 && hasClass) container.classList.remove('show-actions');
        }

        fcbLerpRafId = requestAnimationFrame(fcbLerpLoop);
    };

    container.addEventListener('touchstart', (e) => {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        lastTouchX = startX;
        lastTouchY = startY;
        clearTimeout(revertTimer);
        
        // Start Long Press Timer for Fast Travel
        fcbIsFastTravel = false;
        window._fcbIsFastTravel = false;
        clearTimeout(fcbLongPressTimer);
        fcbLongPressTimer = setTimeout(() => {
            const viewport = document.querySelector('.horizontal-viewport');
            if (viewport) {
                fcbIsFastTravel = true;
                window._fcbIsFastTravel = true;
                container.classList.add('fast-travel-active');
                window.sui.haptic('medium');
                
                const pageWidth = viewport.clientWidth;
                fcbStartPage = Math.round(viewport.scrollLeft / pageWidth);
                fcbTargetScrollX = viewport.scrollLeft;

                // Cache ONLY VISIBLE Page IDs to match the Command Bar strip
                const allPages = Array.from(document.querySelectorAll(".horizontal-viewport > .page-view:not(.dash-dynamic-portal)"));
                fcbPageIdCache = allPages.filter(p => {
                    if (p.style.display === 'none') return false;
                    const scroller = p.querySelector(".scroll-view");
                    const pId = scroller ? scroller.id : p.dataset.pageId;
                    if (typeof dashVisibility !== 'undefined' && dashVisibility['page_' + pId] === false) return false;
                    return true;
                }).map((p, idx) => {
                    if (allPages.indexOf(p) === 0) return "main-scroll";
                    const scroller = p.querySelector(".scroll-view");
                    return scroller ? scroller.id : p.dataset.pageId;
                });

                // Cache Action IDs
                const actionItems = document.querySelectorAll('#fcb-strip-actions .fcb-strip-item');
                fcbActionIdCache = Array.from(actionItems).map(item => {
                    return item.dataset.actionId || null;
                }).filter(id => id !== null);
                
                // Determine initial mode based on whether they have already slid up
                const initialDeltaY = lastTouchY - startY;
                if (initialDeltaY < -40) {
                    fcbFastTravelMode = 1; // Actions Mode
                    fcbActionLockoutTime = Date.now();
                    fcbActionStartX = lastTouchX;
                    container.classList.add('show-actions');
                    const actionsStrip = document.getElementById('fcb-strip-actions');
                    if (actionsStrip) actionsStrip.scrollLeft = 0;
                    
                    fcbTargetActionId = fcbActionIdCache[0];
                    if (fcbTargetActionId) fcbHighlightAction(fcbTargetActionId);
                } else {
                    fcbFastTravelMode = 0; // Pages Mode
                    fcbTargetActionId = null;
                }
                
                // Disable snapping immediately
                viewport.style.scrollSnapType = 'none';
                
                // Start the animation loop ONCE
                requestAnimationFrame(fcbLerpLoop);

                // --- SHOW DIAL OVERLAY ---
                if (typeof window.fcbPopulateDial === 'function') {
                    window.fcbPopulateDial(fcbFastTravelMode);
                    const dial = document.getElementById('fcb-dial-overlay');
                    if (dial) dial.classList.add('active');
                    
                    const activeIndex = (fcbFastTravelMode === 1) ? 0 : fcbStartPage;
                    const maxIndex = (fcbFastTravelMode === 1) ? (fcbActionIdCache.length - 1) : (fcbPageIdCache.length - 1);
                    window.fcbUpdateDial(lastTouchY, activeIndex, maxIndex);
                }
            }
        }, 500);
    }, {passive: true});

    container.addEventListener('touchmove', (e) => {
        const currentX = e.touches[0].clientX;
        const currentY = e.touches[0].clientY;
        const deltaY = currentY - startY;
        const deltaX = currentX - startX;

        lastTouchX = currentX;
        lastTouchY = currentY;

        // Check if movement is primarily vertical and moving upwards
        const isSlidingUp = (deltaY < -15) && (Math.abs(deltaY) > Math.abs(deltaX));
        
        // Cancel only if not sliding up, or if moving down, or sliding horizontally too far
        const shouldCancel = !isSlidingUp && (Math.abs(deltaX) > 15 || deltaY > 15);

        if (!fcbIsFastTravel && shouldCancel) {
            clearTimeout(fcbLongPressTimer);
        }

        if (fcbIsFastTravel) {
            if (e.cancelable) e.preventDefault();

            // --- MODE SWITCHING (Vertical Axis) ---
            if (deltaY < -40 && fcbFastTravelMode === 0) {
                fcbFastTravelMode = 1;
                fcbActionLockoutTime = Date.now(); 
                fcbActionStartX = currentX; 
                
                container.classList.add('show-actions');
                const actionsStrip = document.getElementById('fcb-strip-actions');
                if (actionsStrip) actionsStrip.scrollLeft = 0;
                window.sui.haptic('medium');

                // --- SNAP PAGES ON DROP ---
                const viewport = document.querySelector('.horizontal-viewport');
                if (viewport) {
                    const pageWidth = viewport.clientWidth;
                    const nearestPage = Math.round(viewport.scrollLeft / pageWidth);
                    fcbTargetScrollX = nearestPage * pageWidth;
                    
                    // RE-ANCHOR IMMEDIATELY: Lock the page position
                    fcbStartPage = nearestPage;
                    startX = currentX; // This makes deltaX exactly 0 for the rest of this frame

                    const targetId = fcbPageIdCache[nearestPage];
                    if (targetId) fcbHighlightPage(targetId);
                    fcbLastHapticPage = nearestPage;
                }
                
                fcbTargetActionId = fcbActionIdCache[0];
                if (fcbTargetActionId) fcbHighlightAction(fcbTargetActionId);

                // UPDATE DIAL
                if (typeof window.fcbPopulateDial === 'function') {
                    window.fcbPopulateDial(1);
                    // Force immediate update to Index 0 for the new Action mode
                    window.fcbUpdateDial(currentY, 0, fcbActionIdCache.length - 1);
                }

            } else if (deltaY > -20 && fcbFastTravelMode === 1) {
                fcbFastTravelMode = 0;
                
                // --- RE-ANCHOR PAGE SWIPING ---
                const viewport = document.querySelector('.horizontal-viewport');
                if (viewport) {
                    const pageWidth = viewport.clientWidth;
                    fcbStartPage = Math.round(viewport.scrollLeft / pageWidth);
                    startX = currentX; 
                }

                window.sui.haptic('medium');

                // UPDATE DIAL
                if (typeof window.fcbPopulateDial === 'function') {
                    window.fcbPopulateDial(0);
                    // Force immediate update to current page for the new Page mode
                    window.fcbUpdateDial(currentY, fcbStartPage, fcbPageIdCache.length - 1);
                }
            }

            // Recalculate deltaX AFTER potential mode-switch re-anchoring
            const deltaX = currentX - startX;

            // AGGRESSIVE VISUAL SYNC
            if (fcbFastTravelMode === 1) {
                if (!container.classList.contains('show-actions')) container.classList.add('show-actions');
                clearTimeout(revertTimer); 
            }

            if (fcbFastTravelMode === 0) {
                // --- PAGE SLIDING MODE ---
                const viewport = document.querySelector('.horizontal-viewport');
                if (!viewport) return;
                const settings = typeof window.frGetSettings === 'function' ? window.frGetSettings() : { travel_sensitivity: 22 };
                const pageWidth = viewport.clientWidth;
                const maxPages = fcbPageIdCache.length - 1;
                // Standard base of 150px scaled by the sensitivity value (50 is the center scale factor)
                const pixelsPerPage = Math.max(10, 150 * (settings.travel_sensitivity / 50));
                const direction = settings.reverse_travel ? -1 : 1;

                let idealPage = fcbStartPage + (deltaX * direction / pixelsPerPage);
                
                // --- ANCHOR SHIFTING (Immediate Direction Reversal) ---
                // If the user swipes past the boundaries, we move the starting 'startX' 
                // so that the current finger position is always pinned to the edge.
                if (idealPage < 0) {
                    startX = currentX + (fcbStartPage * pixelsPerPage * direction);
                    idealPage = 0;
                } else if (idealPage > maxPages) {
                    startX = currentX - ((maxPages - fcbStartPage) * pixelsPerPage * direction);
                    idealPage = maxPages;
                }

                const constrainedPage = Math.max(0, Math.min(maxPages, idealPage));
                fcbTargetScrollX = constrainedPage * pageWidth;

                const hapticPage = Math.round(constrainedPage);
                if (hapticPage !== fcbLastHapticPage) {
                    window.sui.haptic('light');
                    fcbLastHapticPage = hapticPage;
                    const targetId = fcbPageIdCache[hapticPage];
                    if (targetId) fcbHighlightPage(targetId);
                }

                // UPDATE DIAL
                if (typeof window.fcbUpdateDial === 'function') {
                    window.fcbUpdateDial(currentY, constrainedPage, maxPages);
                }
            } else {
                // --- ACTION SELECTION MODE (Fast Travel Physics) ---
                
                // Horizontal Lockout: Ignore swipes for 500ms after flip to stabilize selection
                if (Date.now() - fcbActionLockoutTime < 500) {
                    fcbActionStartX = currentX; // Keep anchor pinned to finger while locked
                    
                    // SAFETY MECHANISM: If we are in lockout, we MUST be seeing the actions.
                    if (!container.classList.contains('show-actions')) container.classList.add('show-actions');
                    
                    // UPDATE DIAL (Vertical tracking only during lockout)
                    if (typeof window.fcbUpdateDial === 'function') {
                        window.fcbUpdateDial(currentY, 0, fcbActionIdCache.length - 1);
                    }
                    return;
                }

                const maxActions = fcbActionIdCache.length - 1;
                // Sensitivity: Use 40% of screen width to traverse the whole list
                const pixelsPerAction = Math.max(15, (window.innerWidth * 0.4) / (maxActions || 1));
                
                let actionDeltaX = currentX - fcbActionStartX;
                const settings = typeof window.frGetSettings === 'function' ? window.frGetSettings() : { reverse_action_travel: false };
                const actionDirection = settings.reverse_action_travel ? 1 : -1;
                
                // Calculate ideal index
                let idealIndex = (actionDeltaX * actionDirection) / pixelsPerAction;

                // --- ANCHOR SHIFTING (Prevents getting stuck at screen edges) ---
                // We adjust the starting anchor (fcbActionStartX) so that the current 
                // finger position always maps to a valid index between 0 and maxActions.
                if (idealIndex < 0) {
                    fcbActionStartX = currentX; 
                    idealIndex = 0;
                } else if (idealIndex > maxActions) {
                    // Shift anchor based on direction to pin the index at the maximum
                    fcbActionStartX = currentX - (maxActions * pixelsPerAction * actionDirection);
                    idealIndex = maxActions;
                }

                const actionIdx = Math.round(idealIndex);
                const targetActionId = fcbActionIdCache[actionIdx];
                
                if (targetActionId && targetActionId !== fcbTargetActionId) {
                    fcbTargetActionId = targetActionId;
                    window.sui.haptic('light');
                    fcbHighlightAction(targetActionId);
                }

                // UPDATE DIAL
                if (typeof window.fcbUpdateDial === 'function') {
                    window.fcbUpdateDial(currentY, idealIndex, maxActions);
                }
            }
        }
    }, {passive: true});

    const fcbTouchEnd = (e) => {
        clearTimeout(fcbLongPressTimer);
        
        const endX = (e && e.changedTouches && e.changedTouches.length > 0) ? e.changedTouches[0].clientX : startX;
        const endY = (e && e.changedTouches && e.changedTouches.length > 0) ? e.changedTouches[0].clientY : startY;
        
        const deltaX = endX - startX;
        const deltaY = endY - startY;

        if (fcbIsFastTravel) {
            const viewport = document.querySelector('.horizontal-viewport');
            const wasInActions = (fcbFastTravelMode === 1);
            const actionToRun = fcbTargetActionId;

            fcbIsFastTravel = false;
            window._fcbIsFastTravel = false;
            container.classList.remove('fast-travel-active');
            container.classList.remove('show-actions');
            
            // Clear transient action highlights
            const actionsStrip = document.getElementById('fcb-strip-actions');
            if (actionsStrip) {
                actionsStrip.querySelectorAll('.fcb-strip-item.active').forEach(el => el.classList.remove('active'));
            }

            // HIDE DIAL
            const dial = document.getElementById('fcb-dial-overlay');
            if (dial) dial.classList.remove('active');
            
            // Only execute action if this was a normal touchend, not a fallback cancellation
            const isCancel = !e || e.type === 'touchcancel' || e.type === 'pointerdown';
            
            if (wasInActions && actionToRun && !isCancel) {
                window.fcbExecuteAction(actionToRun);
            } else if (viewport) {
                viewport.style.scrollSnapType = 'x mandatory';
                viewport.scrollTo({ left: fcbTargetScrollX, behavior: 'smooth' });
            }
            return;
        }

        const absX = Math.abs(deltaX);
        const absY = Math.abs(deltaY);
        const isPrimarilyVertical = absY > (absX * 2);

        if (isPrimarilyVertical && deltaY < -40) {
            // Swipe Up -> Toggle Loop between Pages and Actions
            const isShowing = container.classList.toggle('show-actions');
            if (isShowing) {
                const actionsStrip = document.getElementById('fcb-strip-actions');
                if (actionsStrip) actionsStrip.scrollLeft = 0;
            }
            window.sui.haptic('light');
        } else if (isPrimarilyVertical && deltaY > 40) {
            // Swipe Down -> Show Pages
            if (container.classList.contains('show-actions')) {
                container.classList.remove('show-actions');
                window.sui.haptic('light');
            }
        }
    };

    container.addEventListener('touchend', fcbTouchEnd);
    container.addEventListener('touchcancel', fcbTouchEnd);
    window.addEventListener('pointerdown', (e) => {
        if (fcbIsFastTravel && !container.contains(e.target)) fcbTouchEnd(e);
    }, {passive: true});
    } // End of _fcbTouchListenersAttached guard

    // 4. SCROLL LISTENER TO HIGHLIGHT ACTIVE PAGE (SPATIAL DETECTION)
    const viewport = document.querySelector('.horizontal-viewport');
    if (viewport && !window._fcbScrollListenerAttached) {
        window._fcbScrollListenerAttached = true;
        let scrollTimeout;
        viewport.addEventListener('scroll', () => {
            if (window._fcbIsFastTravel) return; // Ignore scroll events during active Fast Travel sliding
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                const viewWidth = viewport.clientWidth;
                if (viewWidth === 0) return;

                // Find the center-point of the current viewport
                const scrollCenter = viewport.scrollLeft + (viewWidth / 2);
                
                // Get all physical pages (ignoring portals)
                const pages = Array.from(document.querySelectorAll(".horizontal-viewport > .page-view:not(.dash-dynamic-portal)"));
                
                // Find which page physically contains the center-point
                const activePage = pages.find(p => {
                    const left = p.offsetLeft;
                    const right = left + p.clientWidth;
                    return scrollCenter >= left && scrollCenter <= right;
                });
                
                if (activePage) {
                    const scroller = activePage.querySelector(".scroll-view");
                    const pageId = scroller ? scroller.id : activePage.dataset.pageId;
                    // Fallback for the very first page (Stream)
                    const finalId = pageId || (pages.indexOf(activePage) === 0 ? "main-scroll" : null);
                    if (finalId) fcbHighlightPage(finalId);
                }
            }, 16); // Reduced from 50ms to 1 frame for snappiness
        }, {passive: true});
    }

    // Initial Highlight Execution
    setTimeout(() => {
        if (viewport) viewport.dispatchEvent(new Event('scroll'));
    }, 100);
}

window.fcbPopulateDial = function(mode) {
    const track = document.getElementById('fcb-dial-track');
    if (!track) return;
    track.innerHTML = '';
    
    const sourceId = mode === 0 ? 'fcb-strip-pages' : 'fcb-strip-actions';
    const items = document.querySelectorAll(`#${sourceId} .fcb-strip-item`);
    
    items.forEach(item => {
        const div = document.createElement('div');
        div.className = 'fcb-dial-item';
        div.innerHTML = item.innerHTML;
        track.appendChild(div);
    });
};

window.fcbUpdateDial = function(currentY, floatIndex, maxIndex) {
    const overlay = document.getElementById('fcb-dial-overlay');
    const track = document.getElementById('fcb-dial-track');
    if (!overlay || !track) return;

    // Follow finger vertically using user setting
    const settings = typeof window.frGetSettings === 'function' ? window.frGetSettings() : { fcb_dial_offset: 100 };
    const offset = settings.fcb_dial_offset || 100;
    overlay.style.transform = `translate(-50%, calc(${currentY}px - ${offset}px)) translateZ(0)`;

    // Clamp index to prevent scrolling into emptiness
    const clamped = Math.max(0, Math.min(maxIndex, floatIndex));
    
    // Translate track to center the active item
    // Dial Width: 300px -> Center: 150px
    // Item Width: 70px -> Center Offset: 35px
    // Starting X to align index 0 to center: 150 - 35 = 115px
    const tx = 115 - (clamped * 70);
    track.style.transform = `translateX(${tx}px)`;

    // Highlight active item
    const activeIdx = Math.round(clamped);
    const items = track.querySelectorAll('.fcb-dial-item');
    items.forEach((item, idx) => {
        if (idx === activeIdx) item.classList.add('active');
        else item.classList.remove('active');
    });
};

window.fcbHighlightAction = function(actionId) {
    const strip = document.getElementById('fcb-strip-actions');
    if (!strip) return;
    
    // O(1) Lookup via attribute selector
    const target = strip.querySelector(`[data-action-id="${actionId}"]`);

    if (target && !target.classList.contains('active')) {
        strip.querySelectorAll('.fcb-strip-item.active').forEach(item => item.classList.remove('active'));
        target.classList.add('active');
        
        // Use requestAnimationFrame to ensure the strip is visible before scrolling
        requestAnimationFrame(() => {
            target.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        });
    }
};



let lastBackTap = 0;
let backLpTimer = null;
let isBackLpActive = false;

window.fcbBackStart = function(e) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    isBackLpActive = false;
    backLpTimer = setTimeout(() => {
        isBackLpActive = true;
        window.sui.haptic('medium');
        if (typeof plOpenOverview === 'function') {
            plOpenOverview();
        } else {
            window.sui.toast("Page Layout plugin not found");
        }
    }, 600);
};

window.fcbBackEnd = function(e) {
    clearTimeout(backLpTimer);
};

window.fcbHandleBack = function() {
    // If a long press was just triggered, ignore the subsequent click event
    if (isBackLpActive) {
        isBackLpActive = false;
        return;
    }

    const now = Date.now();
    const delay = now - lastBackTap;

    if (delay < 300 && delay > 0) {
        // --- DOUBLE TAP: GO HOME ---
        window.sui.haptic('medium');
        
        // 1. Close all overlays/studios/pickers sequentially
        if (typeof frHandleBackAction === 'function') {
            let safety = 0;
            // Keep calling back until it returns false (nothing left to close)
            while (frHandleBackAction() && safety < 10) { safety++; }
        }

        // 2. Exit Selection Mode
        if (typeof cjosToggleSelectMode === 'function') {
            cjosToggleSelectMode(false);
        }

        // 3. Scroll to Stream (Home)
        if (typeof dashNavToPage === 'function') {
            dashNavToPage('main-scroll');
            fcbHighlightPage('main-scroll');
        }

        lastBackTap = 0; // Reset
    } else {
        // --- SINGLE TAP: STANDARD BACK ---
        if (typeof frHandleBackAction === 'function') {
            frHandleBackAction();
        }
        lastBackTap = now;
    }
};

window.fcbExecuteAction = function(id) {
    if (typeof window.frGetRegistry === 'function') {
        const registry = window.frGetRegistry();
        const act = registry.find(a => a.id === id);
        if (act && act.action) {
            act.action();
            window.sui.haptic('medium');
            
            // Revert bar back to pages after an action
            setTimeout(() => {
                const container = document.getElementById('fcb-container');
                if (container) container.classList.remove('show-actions');
                
                // Ensure action highlights are cleared and scroll is reset for next time
                const actionsStrip = document.getElementById('fcb-strip-actions');
                if (actionsStrip) {
                    actionsStrip.scrollLeft = 0;
                    actionsStrip.querySelectorAll('.fcb-strip-item.active').forEach(el => el.classList.remove('active'));
                }
            }, 300);
        }
    }
};

let omniStartX = 0;
let omniStartY = 0;
let omniGestureTimer = null;
let omniIsGesture = false;
let omniActiveZone = null;
let omniPreventClick = false;

window.fcbOmniStart = function(e) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;

    omniStartX = e.touches ? e.touches[0].clientX : e.clientX;
    omniStartY = e.touches ? e.touches[0].clientY : e.clientY;
    omniIsGesture = false;
    omniActiveZone = null;
    omniPreventClick = false;

    // Hold Timer (Deliberate hold required)
    omniGestureTimer = setTimeout(() => {
        if (!omniIsGesture) fcbOmniActivateUI();
    }, 450);

    // Listen for move immediately to detect "Breakout" (sliding up before timer)
    window.addEventListener('pointermove', fcbOmniMove);
    // Listen for release globally so the finger can leave the button area
    window.addEventListener('pointerup', fcbOmniEnd, { once: true });
    window.addEventListener('pointercancel', fcbOmniEnd, { once: true });
};

function fcbOmniActivateUI() {
    if (omniIsGesture) return;
    omniIsGesture = true;
    
    // Vibrate on activation
    if (window.sui && window.sui.haptic) window.sui.haptic('medium');

    const backUi = document.getElementById('fr-back-recorder-ui');
    const backTooltip = document.getElementById('fr-back-tooltip');
    if (backUi && backTooltip) {
        const rect = document.getElementById('fcb-btn-omni').getBoundingClientRect();
        const topY = window.innerHeight - rect.top;
        // Align left edge
        backUi.style.left = rect.left + 'px';
        backUi.style.bottom = (topY + 10) + 'px';
        backUi.classList.add('visible');
        backTooltip.innerText = document.body.classList.contains('is-recording') ? "Slide up to Stop" : "Slide up to Record";

        // Viewport Guard: Ensure tooltip doesn't go off-screen
        requestAnimationFrame(() => {
            const tw = backTooltip.offsetWidth;
            const screenW = window.innerWidth;
            const buffer = 10; // Safe margin from edge
            let shift = 0;
            
            // Calculate if the left or right edge of the tooltip is out of bounds
            const leftEdge = centerX - (tw / 2);
            const rightEdge = centerX + (tw / 2);

            if (leftEdge < buffer) {
                shift = buffer - leftEdge;
            } else if (rightEdge > screenW - buffer) {
                shift = (screenW - buffer) - rightEdge;
            }
            
            // Apply corrective shift to the centered tooltip
            backTooltip.style.transform = `translateX(calc(-50% + ${shift}px))`;
        });
    }
}

window.fcbOmniMove = function(e) {
    const currentX = e.touches ? e.touches[0].clientX : e.clientX;
    const currentY = e.touches ? e.touches[0].clientY : e.clientY;
    const deltaX = currentX - omniStartX;
    const deltaY = currentY - omniStartY;

    // CANCEL: If the finger moves more than 8px before activation, it's a slide, not a hold.
    if (!omniIsGesture && (Math.abs(deltaX) > 8 || Math.abs(deltaY) > 8)) {
        clearTimeout(omniGestureTimer);
        window.removeEventListener('pointermove', fcbOmniMove);
        return;
    }

    if (!omniIsGesture) return;

    const settings = typeof window.frGetSettings === 'function' ? window.frGetSettings() : { back_gesture_dist: 160 };
    const dist = settings.back_gesture_dist || 160;
    const inZone = deltaY < -(dist - 40);

    if (inZone && omniActiveZone !== 'back_record') {
        omniActiveZone = 'back_record';
        document.getElementById('fr-back-zone-rec').classList.add('active');
        window.sui.haptic('medium');
    } else if (!inZone && omniActiveZone === 'back_record') {
        omniActiveZone = null;
        document.getElementById('fr-back-zone-rec').classList.remove('active');
    }
    if (Math.abs(deltaY) > 15) omniPreventClick = true;
};

window.fcbOmniEnd = function(e) {
    clearTimeout(omniGestureTimer);
    window.removeEventListener('pointermove', fcbOmniMove);
    
    const backUi = document.getElementById('fr-back-recorder-ui');
    const backTooltip = document.getElementById('fr-back-tooltip');
    if (backUi) {
        backUi.classList.remove('visible');
        document.getElementById('fr-back-zone-rec').classList.remove('active');
        if (backTooltip) backTooltip.style.transform = ''; // Reset shift
    }

    if (e && e.type !== 'pointercancel' && omniActiveZone === 'back_record') {
        if (typeof window.frToggleRecording === 'function') {
            window.frToggleRecording();
        }
        omniPreventClick = true;
    } else if (omniIsGesture) {
        // If the user held long enough to see the UI but let go without sliding to the target,
        // we must prevent the click so it doesn't accidentally trigger a "Back" action.
        omniPreventClick = true;
    }

    omniIsGesture = false;
    omniActiveZone = null;
};window.fcbOmniClick = function() {
    if (omniPreventClick) return;
    fcbHandleBack();
};

let lastPageTap = 0;
window.fcbHandlePageTap = function(pageId) {
    const now = Date.now();
    const settings = typeof window.frGetSettings === 'function' ? window.frGetSettings() : {};
    const isDouble = (now - lastPageTap < 300);

    // If single tap exit is on, OR if it's a double tap and double tap exit is on
    const shouldExit = (settings.fcb_single_tap_exit) || (isDouble && settings.fcb_double_tap_exit);

    if (shouldExit) {
        let safety = 0;
        // Close all overlays/studios/pickers sequentially
        while (window.frHandleBackAction && window.frHandleBackAction() && safety < 10) { safety++; }
        
        // Exit Selection Mode
        if (typeof cjosToggleSelectMode === 'function') {
            cjosToggleSelectMode(false);
        }
    }

    if (typeof dashNavToPage === 'function') {
        dashNavToPage(pageId);
    }
    
    lastPageTap = now;
};

window.fcbHighlightPage = function(pageId) {
    const strip = document.getElementById('fcb-strip-pages');
    if (!strip) return;
    
    // Remove active class from all
    strip.querySelectorAll('.fcb-strip-item').forEach(item => item.classList.remove('active'));
    
    // Find the one that corresponds to this ID
    const target = strip.querySelector(`[data-page-id="${pageId}"]`);
    
    if (target) {
        target.classList.add('active');
        // Scroll the strip so the active item is visible
        const stripRect = strip.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        
        if (targetRect.left < stripRect.left || targetRect.right > stripRect.right) {
            target.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        }
    }
};
JS;
?>