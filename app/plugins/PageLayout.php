<?php
// ==============================================================================
// PLUGIN: Page Layout
// DESCRIPTION: Reorder App Pages.
// UPDATED: Fixed "Ghost Text" bug. Footer helper text now fades out properly.
// ==============================================================================

$pl_config_file = CJOS_PATH_DATA . '/page-order.json';

// --- BACKEND HANDLERS ---

if (isset($_POST['plugin_action'])) {
    
    // SAVE ORDER
    if ($_POST['plugin_action'] === 'pl_save_order') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $order = json_decode($_POST['order'], true);
        
        $dir = dirname($pl_config_file);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        file_put_contents($pl_config_file, json_encode($order, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET ORDER
    if ($_POST['plugin_action'] === 'pl_get_order') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $order = [];
        if (file_exists($pl_config_file)) {
            $order = json_decode(file_get_contents($pl_config_file), true);
        }
        echo json_encode(['status' => 'success', 'order' => $order]);
        exit;
    }
}

// --- REGISTRY ---
$plugin_tools[] = [
    'name' => 'Page Order',
    'desc' => 'Reorder views',
    'sui_icon' => 'layout',
    'color' => 'rgba(0, 122, 255, 0.1)',
    'icon_color' => 'var(--primary)',
    'action' => "plOpenOverview()"
];

// --- SETTINGS UI ---
$plugin_settings_map['PageLayout'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Page Organization</label>
        <div class="setting-desc">Reorder pages using a visual task manager view.</div>
        <button onclick="plOpenOverview()" class="text-btn" style="
            width: 100%; background: var(--primary); color: white; 
            border-radius: 12px; padding: 14px; font-weight: 600; 
            margin-top: 12px; box-shadow: 0 4px 122, 255, 0.3);
        ">
            Open Page Switcher
        </button>
    </div>
HTML;

// --- JS LOGIC ---
$plugin_js .= <<<'JS'
// --- PAGE LAYOUT (3D ANIMATED) JS ---

let plSavedOrder = [];

// 1. INIT & STYLES
window.addEventListener("load", async () => {
    const style = document.createElement("style");
    style.innerHTML = `
        #pl-overview-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            /* Use system variables for consistent look */
            background: rgba(40, 40, 45, 0.9) !important; 
            backdrop-filter: blur(20px) !important; 
            -webkit-backdrop-filter: blur(20px) !important;
            z-index: 12000; /* Sit above standard overlays */
            display: flex; flex-direction: column;
            pointer-events: none;
            opacity: 0; visibility: hidden;
            transition: opacity 0.4s ease, visibility 0.4s;
        }
        #pl-overview-overlay.visible { 
            pointer-events: auto;
            opacity: 1; visibility: visible;
        }
        
        /* THE TRACK (3D Stage) */
        #pl-card-track {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 30px; /* Wider gap for 3D feel */
            padding: 0 60px; /* Push first card to center */
            overflow-x: auto;
            overflow-y: hidden;
            scroll-behavior: auto; /* CRITICAL: Prevent animated jumps during init */
            -webkit-overflow-scrolling: touch;
            
            /* 3D Perspective Context */
            perspective: 1000px;
            perspective-origin: center center;
        }
        
        /* CARD WRAPPER (Animation Target) */
        .pl-card-wrapper {
            position: relative;
            width: 260px; height: 500px;
            flex-shrink: 0;
            display: flex; flex-direction: column; align-items: center;
            transform-style: preserve-3d;
            
            /* INITIAL STATE: Pushed back and down */
            opacity: 0;
            transform: scale(0.85) translateY(60px) rotateY(10deg);
            
            /* The Magic Transition: Cubic Bezier for "Heavy" feel */
            transition: 
                transform 0.6s cubic-bezier(0.19, 1, 0.22, 1), 
                opacity 0.5s ease;
            
            /* Touch handling */
            touch-action: pan-x;
            user-select: none;
            -webkit-user-select: none;
        }
        
        /* ACTIVE STATE: Normal */
        .pl-card-wrapper.in-view {
            opacity: 1;
            transform: scale(1) translateY(0) rotateY(0deg);
        }

        /* ZOOM EXIT EFFECT */
        .pl-card-wrapper.pl-zooming-out {
            z-index: 1000 !important;
            transition: transform 0.5s cubic-bezier(0.19, 1, 0.22, 1), opacity 0.3s ease !important;
        }

        /* HERO SHRINK EFFECT: The active page starts large and shrinks */
        .pl-card-wrapper.pl-hero-card {
            transform: scale(1.4) translateY(0) rotateY(0deg);
            opacity: 0;
            z-index: 10;
        }
        .pl-card-wrapper.pl-hero-card.in-view {
            transform: scale(1) translateY(0) rotateY(0deg);
            opacity: 1;
        }

        .pl-card-wrapper.pl-long-pressing {
            transform: scale(1.05) translateY(-10px) !important;
            transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
        }
        
        .pl-card-preview {
            width: 100%; height: 100%;
            background: var(--bg-color);
            border-radius: 24px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 20px 50px rgba(0,0,0,0.4);
            pointer-events: none;
            transform-origin: center center;
            
            /* ANTI-FLICKER & CLIPPING FIXES */
            isolation: isolate; /* Force a new stacking context for clean clipping */
            backface-visibility: hidden;
            -webkit-backface-visibility: hidden;
            transform: translateZ(0); /* Force GPU layer */
            contain: paint; /* Optimization: Don't paint outside the rounded rect */
        }

        /* Prevent sharp corner leakage during scale */
        .pl-card-preview > div {
            border-radius: inherit;
        }
        
        /* Ensure clones don't snap or flex-shrink inside the mock frame */
        .pl-card-preview .page-view {
            scroll-snap-align: none !important;
            flex: none !important;
            width: 100% !important;
            height: 100% !important;
        }
        
        .pl-card-label {
            margin-top: 20px;
            color: white; font-weight: 600; font-size: 16px; letter-spacing: 0.5px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            display: flex; align-items: center; gap: 6px;
            opacity: 0; transform: translateY(10px);
            transition: all 0.4s ease 0.2s; /* Delayed label entry */
        }
        .pl-card-wrapper.in-view .pl-card-label { opacity: 1; transform: translateY(0); }
        
        /* DRAGGING */
        .pl-card-wrapper.is-dragging { 
            transition: none !important;
            opacity: 0.9; 
            transform: scale(1.08) translateY(-10px) !important; 
            z-index: 100;
        }
        /* Apply shadow only to the card, not the label below it */
        .pl-card-wrapper.is-dragging .pl-card-preview {
            box-shadow: 0 30px 80px rgba(0,0,0,0.6);
        }
        
        /* UI HEADER & FOOTER (The Fix) */
        #pl-ui-header, #pl-ui-footer {
            padding: 20px 40px; 
            opacity: 0; 
            transition: all 0.4s ease;
        }
        #pl-ui-header { display: flex; justify-content: space-between; align-items: center; transform: translateY(-20px); }
        #pl-ui-footer { text-align:center; color:rgba(255,255,255,0.4); font-size:13px; transform: translateY(20px); }
        
        /* VISIBLE STATE */
        #pl-overview-overlay.visible #pl-ui-header { transform: translateY(0); opacity: 1; }
        #pl-overview-overlay.visible #pl-ui-footer { transform: translateY(0); opacity: 1; }

        /* FORCE VISIBILITY for Scroll Reveal items inside clones */
        #pl-card-track .sr-hidden, 
        #pl-card-track .sr-animating {
            opacity: 1 !important;
            transform: none !important;
            visibility: visible !important;
        }

        .pl-locked-badge {
            font-size: 10px; background: rgba(255,255,255,0.2); padding: 2px 6px; border-radius: 4px; text-transform: uppercase;
        }

        /* LAZY PLACEHOLDER UI */
        .pl-lazy-placeholder {
            position: absolute; inset: 0;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: var(--bg-color);
            color: var(--text-secondary);
            z-index: 5;
        }
        .pl-lazy-icon { font-size: 44px; margin-bottom: 12px; filter: grayscale(1); opacity: 0.4; }
        .pl-lazy-text { font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.5; }
        .pl-lazy-sub { font-size: 9px; font-weight: 700; margin-top: 6px; opacity: 0.3; text-transform: uppercase; }
    `;
    document.head.appendChild(style);

    // Signal app.js that we will handle hydration timing
    window.plManualInit = true;
    await plLoadAndApplyOrder();
    
    // Now that the DOM is reordered, start the observer
    if (typeof window.cjosInitHydration === 'function') {
        window.cjosInitHydration();
    }
});

// 3. LOGIC & DATA
async function plLoadAndApplyOrder() {
    try {
        const data = await window.sui.api("pl_get_order", {}, { toast: false });
        if (Array.isArray(data.order)) {
            plSavedOrder = data.order;
            plApplyDomOrder();
        }
    } catch(e) {}
}

function plApplyDomOrder() {
    if (plSavedOrder.length === 0) return;
    const viewport = document.querySelector(".horizontal-viewport");
    // CRITICAL: Exclude Dynamic Portals from the permanent page list
    const pages = Array.from(document.querySelectorAll(".horizontal-viewport > .page-view:not(.dash-dynamic-portal)"));
    if (pages.length < 2) return;

    const pageMap = new Map();
    const unlabeledPages = [];
    const homePage = pages[0]; 

    for (let i = 1; i < pages.length; i++) {
        const p = pages[i];
        const id = plGetPageId(p);
        if (id) pageMap.set(id, p);
        else unlabeledPages.push(p);
    }

    viewport.appendChild(homePage);
    plSavedOrder.forEach(id => { if (pageMap.has(id)) { viewport.appendChild(pageMap.get(id)); pageMap.delete(id); } });
    pageMap.forEach(p => viewport.appendChild(p));
    unlabeledPages.forEach(p => viewport.appendChild(p));

    // Signal Command Bar to re-scan the new DOM order
    if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
}

function plGetPageId(pageEl) {
    // 1. Check hydrated DOM
    const scroller = pageEl.querySelector(".scroll-view");
    if (scroller && scroller.id) return scroller.id;
    
    // 2. Check Lazy Template (Regex peek for performance)
    const template = pageEl.querySelector('template');
    if (template) {
        const html = template.innerHTML;
        // Target the ID of the div with class "scroll-view"
        const idMatch = html.match(/class=["']scroll-view["']\s+id=["']([^"']+)["']/);
        if (idMatch) return idMatch[1];
        
        // Fallback to any ID if scroll-view match fails
        const fallbackMatch = html.match(/id=["']([^"']+)["']/);
        if (fallbackMatch) return fallbackMatch[1];
    }
    
    // 3. Fallback to Title-based ID
    const name = plGetPageName(pageEl);
    return "page_" + name.replace(/\s+/g, "").toLowerCase();
}

function plGetPageName(pageEl) {
    // 1. Check hydrated DOM
    const title = pageEl.querySelector(".page-title");
    if (title) return title.innerText;

    // 2. Check Lazy Template (Regex peek for performance)
    const template = pageEl.querySelector('template');
    if (template) {
        const html = template.innerHTML;
        // Find the first page-title class and capture its inner text
        const titleMatch = html.match(/class=["']page-title["'][^>]*>([^<]+)</);
        if (titleMatch) return titleMatch[1].trim();
    }
    return "Page";
}

// 4. ANIMATED OPEN
window.plOpenOverview = function() {
    let overlay = document.getElementById("pl-overview-overlay");
    if (!overlay) {
        overlay = document.createElement("div");
        overlay.id = "pl-overview-overlay";
        overlay.className = "shared-menu-overlay";
        overlay.innerHTML = `
            <div id="pl-ui-header">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:32px; height:32px; border-radius:8px; background:rgba(255,255,255,0.1); display:flex; align-items:center; justify-content:center; color:white;">
                        <span data-sui-icon="layout" data-sui-size="18"></span>
                    </div>
                    <h2 style="color:white; margin:0; font-size:22px; font-weight:800; letter-spacing:-0.5px;">Page Layout</h2>
                </div>
                <button onclick="plSaveAndClose()" class="sui-close-trigger" style="background:white; color:black; border:none; padding:8px 20px; border-radius:20px; font-weight:700; cursor:pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">Done</button>
            </div>
            <div id="pl-card-track"></div>
            <div id="pl-ui-footer">&middot; drag cards to reorder &middot;<br>&middot; tap to jump &middot;</div>
        `;
        document.body.appendChild(overlay);
        window.suiHydrateIcons(overlay);
    }
    
    const track = document.getElementById("pl-card-track");
    track.innerHTML = "";
    overlay.style.display = 'flex';
    
    const viewport = document.querySelector('.horizontal-viewport');
    const activeIdx = Math.round(viewport.scrollLeft / viewport.clientWidth);
    const pages = Array.from(document.querySelectorAll(".horizontal-viewport > .page-view:not(.dash-dynamic-portal)"));
    const activePage = pages[activeIdx];
    const activeId = activePage ? (plGetPageId(activePage) || "main-scroll") : null;
    window._plOriginalId = activeId; // Store for "Back" button return
    const mainHeader = document.querySelector(".top-bar");
    
    pages.forEach((page, index) => {
        if (page.offsetParent === null) return;
        const isLocked = (index === 0);
        const name = (index === 0) ? "Stream" : plGetPageName(page);
        const id = plGetPageId(page) || ("temp_" + index);
        
        const pageClone = page.cloneNode(true);
        pageClone.style.overflow = "hidden";
        pageClone.querySelectorAll("script, video, audio").forEach(e => e.remove());
        
        // NEUTER CLONE: Strip all IDs and inline 'on*' event handlers to prevent bleed-through
        pageClone.querySelectorAll("*").forEach(el => {
            el.removeAttribute('id');
            Array.from(el.attributes).forEach(attr => {
                if (attr.name.startsWith('on')) el.removeAttribute(attr.name);
            });
        });
        
        const headerClone = mainHeader ? mainHeader.cloneNode(true) : null;
        if (headerClone) {
            const clock = headerClone.querySelector('#header-clock');
            if (clock) clock.remove();
            headerClone.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));
            headerClone.style.position = "absolute";
            headerClone.style.top = "0";
            headerClone.style.left = "0";
            headerClone.style.width = "100%";
            headerClone.style.boxSizing = "border-box";
            headerClone.style.zIndex = "10";
        }

        const wrap = document.createElement("div");
        wrap.className = `pl-card-wrapper ${isLocked ? "locked" : ""}`;
        wrap.setAttribute("data-page-id", id);
        wrap.draggable = !isLocked;
        
        const preview = document.createElement("div");
        preview.className = "pl-card-preview";
        
        const winW = window.innerWidth;
        const winH = window.innerHeight;
        const scale = 260 / winW;

        const mockApp = document.createElement("div");
        mockApp.style.width = winW + "px";
        mockApp.style.height = winH + "px";
        mockApp.style.position = "relative";
        mockApp.style.transform = `scale(${scale})`;
        mockApp.style.transformOrigin = "top left";
        mockApp.style.background = "var(--bg-color)";

        if (headerClone) mockApp.appendChild(headerClone);
        
        if (page.classList.contains('lazy-page') && !page.classList.contains('is-hydrated')) {
            const placeholder = document.createElement('div');
            placeholder.className = 'pl-lazy-placeholder';
            let icon = "💤";
            placeholder.innerHTML = `
                <div class="pl-lazy-icon">${icon}</div>
                <div class="pl-lazy-text">Hibernating</div>
                <div class="pl-lazy-sub">Tap to Wake</div>
            `;
            mockApp.appendChild(placeholder);
        } else {
            mockApp.appendChild(pageClone);
        }

        preview.appendChild(mockApp);
        const labelDiv = document.createElement("div");
        labelDiv.className = "pl-card-label";
        labelDiv.innerHTML = isLocked ? `${name} <span class="pl-locked-badge">Home</span>` : name;
            
        wrap.appendChild(preview);
        wrap.appendChild(labelDiv);

        wrap.onclick = (e) => {
            if (wrap.classList.contains('is-dragging')) return;
            window.plSaveAndClose(id);
        };
        
        if (!isLocked) {
            wrap.addEventListener("dragstart", plHandleDragStart);
            wrap.addEventListener("dragover", plHandleDragOver);
            wrap.addEventListener("drop", plHandleDrop);
            wrap.addEventListener("dragend", plHandleDragEnd);
            wrap.addEventListener("touchstart", plHandleTouchStart, {passive:false});
            wrap.addEventListener("touchmove", plHandleTouchMove, {passive:false});
            wrap.addEventListener("touchend", plHandleTouchEnd);
        }
        
        track.appendChild(wrap);
        if (index === 0) wrap.setAttribute("data-page-id", "main-scroll");

        // Mark the Hero card (the one the user is currently on)
        if (activeId && id === activeId) {
            wrap.classList.add('pl-hero-card');
            // Calculate scale needed to fill screen (winW / cardW)
            const startScale = window.innerWidth / 260;
            wrap.style.transform = `scale(${startScale}) translateY(0) rotateY(0deg)`;
            wrap.style.zIndex = "100";
            wrap.style.opacity = "1"; // Force opaque immediately to prevent background flicker
        }
    });

    // --- INSTANT CENTERING (No Animation) ---
    if (activeId) {
        const cardsArr = Array.from(track.querySelectorAll('.pl-card-wrapper'));
        const heroIdx = cardsArr.findIndex(c => c.getAttribute('data-page-id') === activeId);
        if (heroIdx !== -1) {
            const cardWidth = 260;
            const gap = 30;
            const padding = 60;
            // Snap the track to center the hero card immediately
            const targetScroll = padding + (heroIdx * (cardWidth + gap)) - (window.innerWidth / 2) + (cardWidth / 2);
            track.scrollLeft = targetScroll;
        }
    }
    
    // --- ANIMATION SEQUENCE ---
    overlay.style.visibility = "visible";
    overlay.classList.add("visible");

    if (window.sui) {
        window.sui.registerOverlay("pl-overview", () => window.plSaveAndClose());
    }
    
    const cards = track.querySelectorAll('.pl-card-wrapper');
    cards.forEach((card, i) => {
        const isHero = card.classList.contains('pl-hero-card');
        // Hero shrinks immediately, others stagger in from background
        const delay = isHero ? 0 : (100 + (i * 30)); 
        setTimeout(() => {
            if (isHero) card.style.transform = ""; // Revert to CSS scale(1)
            card.classList.add('in-view');
        }, delay);
    });
};

// 5. ANIMATED CLOSE
window.plSaveAndClose = async function(targetPageId = null) {
    const overlay = document.getElementById("pl-overview-overlay");
    const track = document.getElementById("pl-card-track");
    if (!overlay) return;

    // 1. Resolve Target (Tapped card OR original entry page)
    const finalId = targetPageId || window._plOriginalId;
    const targetCard = track.querySelector(`.pl-card-wrapper[data-page-id="${finalId}"]`);

    if (window.sui && window.sui.haptic) window.sui.haptic('light');

    // 2. Cinematic Centering: If using "Back", scroll to the original card first
    if (!targetPageId && targetCard) {
        const cardWidth = 260;
        const gap = 30;
        const padding = 60;
        const cardsArr = Array.from(track.querySelectorAll('.pl-card-wrapper'));
        const idx = cardsArr.indexOf(targetCard);
        const targetScroll = padding + (idx * (cardWidth + gap)) - (window.innerWidth / 2) + (cardWidth / 2);
        
        // Organic Dynamic Scroll
        const startScroll = track.scrollLeft;
        const distance = targetScroll - startScroll;

        if (Math.abs(distance) > 8) {
            await new Promise(resolve => {
                // Reduced duration floor from 350ms to 200ms for snappier short travel
                const duration = Math.min(700, Math.max(200, Math.abs(distance) * 0.4));
                const startTime = performance.now();
                
                const animate = (time) => {
                    const elapsed = time - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    
                    // Ease Out Cubic: Slightly less 'sticky' than Quartic
                    const ease = 1 - Math.pow(1 - progress, 3);
                    track.scrollLeft = startScroll + (distance * ease);
                    
                    // PERCEPTUAL RESOLVE: If we are 98% done, the card looks stopped. 
                    // Resolve now to eliminate the 'heavy tail' delay.
                    if (progress < 0.98) {
                        requestAnimationFrame(animate);
                    } else {
                        track.scrollLeft = targetScroll; // Snap final pixel
                        resolve();
                    }
                };
                requestAnimationFrame(animate);
            });
            // The 60ms pause is now the ONLY remaining delay
            await new Promise(r => setTimeout(r, 60));
        }
    }

    // 3. Trigger Zoom Animation
    if (targetCard) {
        const zoomScale = window.innerWidth / 260;
        targetCard.classList.add('pl-zooming-out');
        targetCard.style.transform = `scale(${zoomScale})`;
    }
    
    // Fade the background overlay simultaneously with the zoom
    overlay.classList.remove("visible");

    // 4. Background Sync
    plApplyDomOrder();
    
    setTimeout(() => {
        overlay.style.visibility = "hidden";
        overlay.style.display = 'none';
        if (track) track.innerHTML = ""; 

        if (finalId) {
            const viewport = document.querySelector(".horizontal-viewport");
            const pages = Array.from(viewport.querySelectorAll(".page-view:not(.dash-dynamic-portal)"));
            const targetIdx = pages.findIndex(p => plGetPageId(p) === finalId);
            if (targetIdx !== -1) {
                viewport.scrollLeft = targetIdx * viewport.clientWidth;
            }
        }
    }, 450);
};

// 6. DRAG & DROP LOGIC
let plDraggedItem = null;
let plLongPressTimer = null;
let plTouchStartX = 0;
let plTouchStartY = 0;

function plHandleDragStart(e) { 
    plDraggedItem = this; 
    this.classList.add("is-dragging"); 
    e.dataTransfer.effectAllowed = "move"; 
}
function plHandleDragOver(e) { if (e.preventDefault) e.preventDefault(); return false; }
function plHandleDrop(e) { 
    e.stopPropagation(); 
    if (plDraggedItem && plDraggedItem !== this) { 
        const t=document.getElementById("pl-card-track"); 
        const c=Array.from(t.children); 
        if (c.indexOf(this)>0) plAnimateSwap(plDraggedItem, this); 
    } 
    return false; 
}
function plHandleDragEnd() { this.classList.remove("is-dragging"); plDraggedItem = null; }

function plHandleTouchStart(e) {
    const t = e.touches[0];
    plTouchStartX = t.clientX;
    plTouchStartY = t.clientY;
    
    plLongPressTimer = setTimeout(() => {
        this.classList.add("is-dragging");
        this.classList.add("pl-long-pressing");
        plDraggedItem = this;
        if (window.sui && window.sui.haptic) window.sui.haptic('medium');
    }, 500);
}

function plHandleTouchMove(e) {
    if (!plDraggedItem) {
        const t = e.touches[0];
        const moveX = Math.abs(t.clientX - plTouchStartX);
        const moveY = Math.abs(t.clientY - plTouchStartY);
        if (moveX > 10 || moveY > 10) {
            clearTimeout(plLongPressTimer);
        }
    } else {
        // Prevent scrolling while dragging
        e.preventDefault();
    }
}

function plHandleTouchEnd(e) {
    clearTimeout(plLongPressTimer);
    this.classList.remove("pl-long-pressing");
    
    if (plDraggedItem) {
        this.classList.remove("is-dragging");
        const t = e.changedTouches[0];
        const target = document.elementFromPoint(t.clientX, t.clientY);
        const w = target ? target.closest(".pl-card-wrapper") : null;
        if (w && w !== this && !w.classList.contains("locked")) {
            plAnimateSwap(this, w);
            if (window.sui && window.sui.haptic) window.sui.haptic('success');
        }
        plDraggedItem = null;
    }
}

async function plAnimateSwap(n1, n2) {
    const p = n1.parentNode; const c = Array.from(p.children);
    const m = new Map(); c.forEach(x => m.set(x, x.getBoundingClientRect().left));
    const i1 = c.indexOf(n1); const i2 = c.indexOf(n2);
    if (i1 < i2) p.insertBefore(n1, n2.nextSibling); else p.insertBefore(n1, n2);
    
    const nc = Array.from(p.children);
    nc.forEach(x => {
        const d = m.get(x) - x.getBoundingClientRect().left;
        if(d!==0) { x.style.transition='none'; x.style.transform=`translateX(${d}px) scale(1)`; }
    });
    void p.offsetHeight;
    nc.forEach(x => { x.style.transition='transform 0.4s cubic-bezier(0.2, 0, 0, 1)'; x.style.transform=''; });

    // SAVE IMMEDIATELY ON SWAP
    const newOrder = [];
    nc.forEach((card, idx) => {
        if (idx === 0) return; // Skip locked Home card
        const id = card.getAttribute("data-page-id");
        if (id && !id.startsWith("temp_")) newOrder.push(id);
    });
    plSavedOrder = newOrder;
    window.sui.api("pl_save_order", { order: newOrder }, { toast: false });
}
JS;
?>