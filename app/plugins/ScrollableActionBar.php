<?php
// ==============================================================================
// PLUGIN: Scrollable Action Bar
// DESCRIPTION: Scrollable Bottom Bar.
// 1. Makes the bottom bar scrollable.
// 2. Adds a "Sort" handle to rearrange buttons (Drag & Drop).
// 3. Saves order to CJOS_PATH_DATA/action-bar-order.json.
// ==============================================================================

$ab_config_file = CJOS_PATH_DATA . '/action-bar-order.json';

// --- BACKEND HANDLERS ---

if (isset($_POST['plugin_action'])) {
    
    // SAVE ORDER
    if ($_POST['plugin_action'] === 'ab_save_order') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $order = json_decode($_POST['order'], true);
        
        // Ensure data dir exists
        $dir = dirname($ab_config_file);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        file_put_contents($ab_config_file, json_encode($order, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET ORDER
    if ($_POST['plugin_action'] === 'ab_get_order') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $order = [];
        if (file_exists($ab_config_file)) {
            $order = json_decode(file_get_contents($ab_config_file), true);
        }
        echo json_encode(['status' => 'success', 'order' => $order]);
        exit;
    }
    
    // RESET ORDER
    if ($_POST['plugin_action'] === 'ab_reset_order') {
        if (file_exists($ab_config_file)) unlink($ab_config_file);
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- SETTINGS UI ---

$plugin_settings_map['ScrollableActionBar'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-desc">
            The bottom action bar is scrollable. A <strong>"Sort"</strong> handle appears above the bar when you select items. Drag and drop icons to rearrange.
        </div>
    </div>
    <div class="setting-item">
        <button onclick="resetActionBarOrder()" class="text-btn" style="color:var(--danger); width:100%;">Reset Button Order</button>
    </div>
HTML;

// --- JAVASCRIPT LOGIC ---

$plugin_js .= <<<'JS'
// --- SCROLLABLE ACTION BAR JS ---

let abSavedOrder = [];
let abDragItem = null;

window.addEventListener("load", async () => {
    // 1. Init Scroll View
    initScrollableBar();

    // Listen for selection mode to fix layout timing
    window.addEventListener('cjos-select-mode', (e) => {
        if (e.detail.enabled) {
            setTimeout(refreshBarLayout, 50);
            setTimeout(refreshBarLayout, 350); // After slide animation
        }
    });
    
    // 2. Fetch Order from Server
    await loadActionBarOrder();
    
    // 3. Watch for new buttons (e.g. injected by other plugins later)
    const bar = document.querySelector(".selection-bottom-bar");
    if (bar) {
        const observer = new MutationObserver(() => {
            if (!window._abIsSortingDOM) {
                setTimeout(() => {
                    initScrollableBar(); 
                    applyActionBarOrder(); 
                    refreshBarLayout();
                }, 100);
            }
        });
        observer.observe(bar, { childList: true, subtree: true });
    }
});

// --- SERVER COMM ---

async function loadActionBarOrder() {
    try {
        const data = await window.sui.api("ab_get_order", {}, { toast: false });
        if (Array.isArray(data.order)) {
            abSavedOrder = data.order;
            applyActionBarOrder();
        }
    } catch(e) {}
}

async function saveActionBarOrder(newOrder) {
    abSavedOrder = newOrder;
    try {
        await window.sui.api("ab_save_order", { order: newOrder }, { toast: false });
    } catch(e) {}
}

window.resetActionBarOrder = async function() {
    window.openConfirm("Reset Order", "Reset action bar layout to default?", async () => {
        try {
            await window.sui.api("ab_reset_order");
            location.reload();
        } catch(e) {}
    });
};

// --- CORE UI ---

function initScrollableBar() {
    const bar = document.querySelector(".selection-bottom-bar");
    if (!bar) return;

    // A. Wrapper
    let wrapper = bar.querySelector(".sb-scroll-container");
    if (!wrapper) {
        wrapper = document.createElement("div");
        wrapper.className = "sb-scroll-container";
        bar.appendChild(wrapper);
        
        // Shadows
        const sl = document.createElement("div"); sl.className = "sb-shadow-left"; bar.appendChild(sl);
        const sr = document.createElement("div"); sr.className = "sb-shadow-right"; bar.appendChild(sr);
        
        wrapper.addEventListener("scroll", updateScrollShadows, { passive: true });
    }

    // INGESTION: Move any stray buttons added to the parent bar into the scroll wrapper
    Array.from(bar.children).forEach(child => {
        // CRITICAL: Ensure pagination dots and other fixed UI elements aren't moved into the scroll container
        const isManaged = child === wrapper || 
                          child.classList.contains("sb-rearrange-handle") || 
                          child.classList.contains("sb-shadow-left") || 
                          child.classList.contains("sb-shadow-right") ||
                          child.classList.contains("sb-pagination");
        if (!isManaged) {
            wrapper.appendChild(child);
        }
    });

    // B. Rearrange Handle (Visibility controlled by CSS)
    if (!document.getElementById("sb-sort-handle")) {
        const handle = document.createElement("div");
        handle.id = "sb-sort-handle";
        handle.className = "sb-rearrange-handle";
        handle.innerHTML = `<span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Sort</span>`;
        handle.onclick = openActionBarSorter;
        bar.appendChild(handle);
    }

    // B2. Pagination Dots
    if (!document.getElementById("sb-pagination")) {
        const pag = document.createElement("div");
        pag.id = "sb-pagination";
        pag.className = "sb-pagination";
        bar.appendChild(pag);
    }
    
    // C. Styles
    if (!document.getElementById("sb-styles")) {
        const style = document.createElement("style");
        style.id = "sb-styles";
        style.innerHTML = `
            .selection-bottom-bar {
                padding: 0 !important; display: block !important; overflow: visible !important; 
                z-index: 20000 !important;
            }
            .sb-scroll-container {
                display: flex; align-items: center; justify-content: flex-start;
                gap: 12px; overflow-x: auto; padding: 16px 24px 32px 24px;
                width: 100%; box-sizing: border-box;
                -webkit-overflow-scrolling: touch; scrollbar-width: none;
                /* GPU Promotion Fix */
                transform: translateZ(0);
                will-change: scroll-position;
                /* PAGINATION SNAPPING */
                scroll-snap-type: x mandatory;
                scroll-padding: 0 24px;
            }
            .sb-scroll-container::-webkit-scrollbar { display: none; }
            .sb-scroll-container .bar-action-btn { 
                flex-shrink: 0; 
                width: var(--sb-btn-width, auto);
                scroll-snap-align: none;
                transition: width 0.2s ease;
            }
            .sb-scroll-container .bar-action-btn.sb-snap-point {
                scroll-snap-align: start;
                scroll-snap-stop: always;
            }
            .sb-spacer {
                flex-shrink: 0;
                width: var(--sb-btn-width);
                visibility: hidden;
                pointer-events: none;
            }
            
            .sb-shadow-left, .sb-shadow-right {
                position: absolute; top: 0; bottom: 0; width: 30px; pointer-events: none; z-index: 10;
                transition: opacity 0.3s; opacity: 0;
                display: flex; align-items: center;
                /* Offset for asymmetrical bar padding (16px top, 32px bottom) */
                padding-bottom: 16px;
                box-sizing: border-box;
            }
            .sb-shadow-left { left: 0; background: linear-gradient(to right, var(--header-bg) 40%, transparent); justify-content: flex-start; padding-left: 8px; }
            .sb-shadow-right { right: 0; background: linear-gradient(to left, var(--header-bg) 40%, transparent); justify-content: flex-end; padding-right: 8px; }
            .sb-shadow-left.visible, .sb-shadow-right.visible { opacity: 1; }

            /* PEEK INDICATORS */
            .sb-shadow-right.visible::after {
                content: "›"; color: var(--primary); font-size: 20px; font-weight: 900;
                animation: sb-peek-right 1.5s infinite; opacity: 0.6;
            }
            .sb-shadow-left.visible::after {
                content: "‹"; color: var(--primary); font-size: 20px; font-weight: 900;
                animation: sb-peek-left 1.5s infinite; opacity: 0.6;
            }
            @keyframes sb-peek-right { 0%, 100% { transform: translateX(0); } 50% { transform: translateX(3px); } }
            @keyframes sb-peek-left { 0%, 100% { transform: translateX(0); } 50% { transform: translateX(-3px); } }

            /* PAGINATION DOTS */
            .sb-pagination {
                position: absolute; 
                bottom: 12px; left: 50%; 
                transform: translateX(-50%);
                display: flex; justify-content: center; gap: 6px;
                pointer-events: none; transition: opacity 0.3s;
                z-index: 100; /* Ensure dots are above all other bar elements */
            }
            .sb-dot {
                width: 5px; height: 5px; border-radius: 50%;
                background: var(--text-secondary); opacity: 0.3;
                transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            }
            .sb-dot.active { opacity: 1; background: var(--primary); transform: scale(1.3); }

            /* REARRANGE HANDLE - Hidden by default */
            .sb-rearrange-handle {
                position: absolute; top: -24px; left: 50%; transform: translateX(-50%);
                background: var(--header-bg);
                backdrop-filter: blur(30px); -webkit-backdrop-filter: blur(30px);
                padding: 4px 16px;
                border-top-left-radius: 12px; border-top-right-radius: 12px;
                border: 1px solid rgba(0,0,0,0.05); border-bottom: none;
                cursor: pointer; box-shadow: 0 -4px 10px rgba(0,0,0,0.05);
                color: var(--primary); z-index: 5;
                transition: transform 0.2s, opacity 0.3s, visibility 0.3s;
                opacity: 0; visibility: hidden; pointer-events: none;
            }
            .sb-rearrange-handle:active { transform: translateX(-50%) translateY(2px); }

            /* ONLY SHOW WHEN SELECT MODE IS ACTIVE */
            body.select-mode .sb-rearrange-handle {
                opacity: 1; visibility: visible; pointer-events: auto;
            }

            /* SORTER OVERLAY */
            #ab-sorter-overlay {
                position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0, 0, 0, var(--overlay-dim, 0.6));
                backdrop-filter: blur(var(--overlay-blur, 8px)); -webkit-backdrop-filter: blur(var(--overlay-blur, 8px));
                z-index: 9500; display: flex; flex-direction: column; align-items: center; justify-content: center;
                opacity: 0; visibility: hidden; transition: opacity 0.3s;
            }
            #ab-sorter-overlay.visible { opacity: 1; visibility: visible; }
            #ab-sorter-track {
                display: flex; flex-wrap: wrap; justify-content: center;
                gap: 20px; max-width: 90%; padding: 20px;
            }
            .ab-sort-card {
                width: 80px; height: 80px; background: var(--card-bg); border-radius: 18px;
                display: flex; align-items: center; justify-content: center;
                box-shadow: var(--shadow-card);
                border: 1px solid var(--border-color);
                transition: transform 0.2s, opacity 0.2s; cursor: grab; position: relative;
            }
            .ab-sort-card.is-dragging { opacity: 0.5; transform: scale(1.1); z-index: 100; cursor: grabbing; border-color: var(--primary); }
            .ab-sort-card svg { width: 32px; height: 32px; stroke-width: 2.2; color: var(--text-primary); }
            .ab-sort-card.danger svg { color: var(--danger); }
        `;
        document.head.appendChild(style);
    }
    refreshBarLayout();
}

function refreshBarLayout() {
    const wrapper = document.querySelector(".sb-scroll-container");
    const pag = document.getElementById("sb-pagination");
    if (!wrapper) return;

    // Filter out spacers for the calculation
    const btns = Array.from(wrapper.children).filter(c => !c.classList.contains('sb-spacer'));
    if (btns.length === 0) return;

    // 1. DYNAMIC LAYOUT CALCULATION
    const containerWidth = wrapper.offsetWidth;
    if (containerWidth < 50) return; // Bar is hidden or too small to calculate

    const availableWidth = containerWidth - 48; // 24px padding each side
    const minBtnWidth = 56;
    const gap = 12;

    let n = Math.floor((availableWidth + gap) / (minBtnWidth + gap));
    if (n < 1) n = 1;

    const exactWidth = (availableWidth - (n - 1) * gap) / n;
    wrapper.style.setProperty('--sb-btn-width', exactWidth + 'px');

    btns.forEach((btn, i) => {
        btn.classList.toggle('sb-snap-point', i % n === 0);
    });

    // 2. PHANTOM PADDING (Structural change)
    const remainder = btns.length % n;
    const spacersNeeded = remainder === 0 ? 0 : n - remainder;
    const currentSpacers = wrapper.querySelectorAll('.sb-spacer');
    
    if (currentSpacers.length !== spacersNeeded) {
        currentSpacers.forEach(s => s.remove());
        for (let i = 0; i < spacersNeeded; i++) {
            const spacer = document.createElement('div');
            spacer.className = 'sb-spacer';
            wrapper.appendChild(spacer);
        }
    }

    // 3. PAGINATION DOTS (Creation only)
    if (pag) {
        const pageCount = Math.ceil(btns.length / n);
        if (pageCount <= 1) {
            pag.style.opacity = "0";
            pag.innerHTML = "";
        } else {
            pag.style.opacity = "1";
            // Guard: Only rebuild if the number of pages changed
            if (pag.children.length !== pageCount) {
                pag.innerHTML = "";
                for (let i = 0; i < pageCount; i++) {
                    const dot = document.createElement("div");
                    dot.className = "sb-dot";
                    pag.appendChild(dot);
                }
            }
        }
    }
    
    // Cache N for the scroll handler
    wrapper.dataset.sectionSize = n;
    updateScrollShadows();
}

function updateScrollShadows() {
    const wrapper = document.querySelector(".sb-scroll-container");
    const sl = document.querySelector(".sb-shadow-left");
    const sr = document.querySelector(".sb-shadow-right");
    const pag = document.getElementById("sb-pagination");
    if (!wrapper || !sl || !sr) return;

    const x = wrapper.scrollLeft;
    const containerWidth = wrapper.offsetWidth;
    if (containerWidth <= 0) return;

    const scrollWidth = wrapper.scrollWidth;
    const max = scrollWidth - containerWidth;

    // Shadows & Peeks (Class toggles only - no layout shift)
    if (x > 5) sl.classList.add("visible"); else sl.classList.remove("visible");
    if (x < max - 5) sr.classList.add("visible"); else sr.classList.remove("visible");

    // Pagination (Active state only)
    if (pag && pag.children.length > 0) {
        const currentPage = Math.round(x / containerWidth);
        // Guard: Only update DOM if the page index actually changed
        if (wrapper._lastActivePage !== currentPage) {
            wrapper._lastActivePage = currentPage;
            Array.from(pag.children).forEach((dot, i) => {
                dot.classList.toggle("active", i === currentPage);
            });
        }
    }
}

// Recalculate on resize
window.addEventListener('resize', refreshBarLayout);

// Ensure layout recalculates on screen rotation/resize
window.addEventListener('resize', updateScrollShadows);

// --- ORDER LOGIC ---

function getButtonId(btn) {
    if (btn.id) return btn.id;
    const svg = btn.querySelector("svg");
    if (svg) {
        let hash = 0; const str = svg.innerHTML;
        for (let i = 0; i < str.length; i++) { hash = ((hash << 5) - hash) + str.charCodeAt(i); hash |= 0; }
        return "gen_btn_" + Math.abs(hash);
    }
    return "unknown_" + Math.random().toString(36).substr(2, 5);
}

function applyActionBarOrder() {
    window._abIsSortingDOM = true;
    const wrapper = document.querySelector(".sb-scroll-container");
    if (!wrapper || abSavedOrder.length === 0) { window._abIsSortingDOM = false; return; }

    const children = Array.from(wrapper.children);
    const btnMap = new Map();
    children.forEach(btn => {
        const id = getButtonId(btn);
        if (!btn.id) btn.id = id; 
        btnMap.set(id, btn);
    });

    abSavedOrder.forEach(id => {
        if (btnMap.has(id)) { 
            const btn = btnMap.get(id);
            wrapper.appendChild(btn); 
            btnMap.delete(id); 
        }
    });
    btnMap.forEach(btn => {
        wrapper.appendChild(btn);
    });
    
    setTimeout(() => { 
        window._abIsSortingDOM = false; 
        refreshBarLayout();
    }, 200);
}

// --- SORTER UI ---

window.openActionBarSorter = function() {
    let overlay = document.getElementById("ab-sorter-overlay");
    if (!overlay) {
        overlay = document.createElement("div");
        overlay.id = "ab-sorter-overlay";
        overlay.className = "shared-menu-overlay";
        overlay.innerHTML = `
            <div style="color:white; font-size:20px; font-weight:800; margin-bottom:30px; text-shadow: 0 2px 10px rgba(0,0,0,0.3);">Rearrange Buttons</div>
            <div id="ab-sorter-track"></div>
            <button onclick="saveAndCloseSorter()" class="btn-primary" style="margin-top:40px; min-width: 160px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">Done</button>
            <div style="margin-top:20px; color:white; opacity:0.6; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px;">Drag to reorder</div>
        `;
        document.body.appendChild(overlay);
    }

    if (window.sui) {
        window.sui.registerOverlay('ab-sorter', window.saveAndCloseSorter);
    }

    const track = document.getElementById("ab-sorter-track");
    track.innerHTML = "";
    
    const wrapper = document.querySelector(".sb-scroll-container");
    const buttons = Array.from(wrapper.children);

    buttons.forEach(btn => {
        const id = getButtonId(btn);
        if(!btn.id) btn.id = id;

        const card = document.createElement("div");
        card.className = "ab-sort-card";
        if (btn.classList.contains("danger") || btn.querySelector("svg")?.style.color === "rgb(255, 59, 48)") card.classList.add("danger");
        card.setAttribute("data-origin-id", id);
        card.innerHTML = btn.innerHTML;
        
        card.draggable = true;
        card.addEventListener("dragstart", abHandleDragStart);
        card.addEventListener("dragover", abHandleDragOver);
        card.addEventListener("drop", abHandleDrop);
        card.addEventListener("dragend", abHandleDragEnd);
        card.addEventListener("touchstart", abHandleTouchStart, {passive:false});
        card.addEventListener("touchmove", abHandleTouchMove, {passive:false});
        card.addEventListener("touchend", abHandleTouchEnd);

        track.appendChild(card);
    });

    overlay.classList.add("visible");
};

function abHandleDragStart(e) { abDragItem = this; this.classList.add("is-dragging"); if(e.dataTransfer) e.dataTransfer.effectAllowed = "move"; }
function abHandleDragOver(e) { e.preventDefault(); return false; }
function abHandleDrop(e) { e.stopPropagation(); if (abDragItem && abDragItem !== this) abAnimateSwap(abDragItem, this); return false; }
function abHandleDragEnd() { this.classList.remove("is-dragging"); abDragItem = null; }
function abHandleTouchStart(e) { abDragItem = this; this.classList.add("is-dragging"); }
function abHandleTouchMove(e) { e.preventDefault(); }
function abHandleTouchEnd(e) {
    this.classList.remove("is-dragging");
    const touch = e.changedTouches[0];
    const target = document.elementFromPoint(touch.clientX, touch.clientY);
    const card = target ? target.closest(".ab-sort-card") : null;
    if (card && card !== this) abAnimateSwap(this, card);
}

function abAnimateSwap(n1, n2) {
    const parent = n1.parentNode;
    const children = Array.from(parent.children);
    const posMap = new Map();
    children.forEach(c => posMap.set(c, c.getBoundingClientRect()));

    const idx1 = children.indexOf(n1);
    const idx2 = children.indexOf(n2);
    if (idx1 < idx2) parent.insertBefore(n1, n2.nextSibling); else parent.insertBefore(n1, n2);

    const newChildren = Array.from(parent.children);
    newChildren.forEach(c => {
        const oldPos = posMap.get(c);
        const newPos = c.getBoundingClientRect();
        const dx = oldPos.left - newPos.left;
        const dy = oldPos.top - newPos.top;
        if (dx !== 0 || dy !== 0) {
            c.style.transition = "none";
            c.style.transform = `translate(${dx}px, ${dy}px)`;
            void c.offsetHeight; 
            c.style.transition = "transform 0.3s cubic-bezier(0.2, 0, 0, 1)";
            c.style.transform = "";
        }
    });
}

window.saveAndCloseSorter = function() {
    if (window.sui) window.sui.unregisterOverlay('ab-sorter');
    const overlay = document.getElementById("ab-sorter-overlay");
    overlay.classList.remove("visible");
    
    const track = document.getElementById("ab-sorter-track");
    const newOrder = [];
    Array.from(track.children).forEach(card => {
        const id = card.getAttribute("data-origin-id");
        if(id) newOrder.push(id);
    });
    
    saveActionBarOrder(newOrder);
    applyActionBarOrder();
    
    const bar = document.querySelector(".selection-bottom-bar");
    if(bar) { bar.style.opacity = "0.5"; setTimeout(() => bar.style.opacity = "1", 300); }
};
JS;
?>