// ==========================================================================
// 1. SCROLL & HEADER LOGIC
// ==========================================================================

// --- STICKY HEADER ANTI-STACKING ---
// Prevents multiple transparent date headers from overlapping in Glass themes.
window.cjosUpdateHeaderVisibility = function() {
    const mainScroll = document.getElementById('main-scroll');
    if (!mainScroll) return;
    const headers = Array.from(mainScroll.querySelectorAll('.section-header'));
    if (headers.length === 0) return;

    // Detection Threshold: Approximate height of the top bar + margin
    const threshold = 140; 
    let lastStuckIdx = -1;

    // 1. Find the most recent header that has reached the sticky zone
    headers.forEach((h, i) => {
        if (h.getBoundingClientRect().top <= threshold) lastStuckIdx = i;
    });

    // 2. Hide all headers above the active one, show the active and future ones
    headers.forEach((h, i) => {
        const isObscured = i < lastStuckIdx;
        h.style.visibility = isObscured ? "hidden" : "visible";
        h.style.opacity = isObscured ? "0" : "1";
        h.style.transition = "opacity 0.2s ease, visibility 0.2s";
    });
};

// --- LAZY HYDRATION ENGINE ---
window._cjosIsHydrating = false;
window.cjosHydratePage = function(container) {
    if (!container || container.classList.contains('is-hydrated') || window._cjosIsHydrating) return;
    const id = container.getAttribute('data-lazy-id');
    const template = document.querySelector('#tpl-' + id);
    
    if (template) {
        window._cjosIsHydrating = true;
        try {
            // 1. Hydrate content
            container.appendChild(template.content.cloneNode(true));
            container.classList.add('is-hydrated');
            
            // 2. Cleanup template
            template.remove();
            
            // 3. Broadcast hydration event (Deferred to prevent stack/network conflicts)
            const pageId = container.getAttribute('data-page-id') || container.querySelector('.scroll-view')?.id || id;
            setTimeout(() => {
                window.dispatchEvent(new CustomEvent('cjos-hydrated', { 
                    detail: { id: pageId, container: container } 
                }));
            }, 0);
        } finally {
            window._cjosIsHydrating = false;
        }
    }
};

// --- FUNDAMENTAL AUTO-WAKE ENGINE (DOM Overrides) ---
window.cjosAutoWakeEnabled = localStorage.getItem('cjos_auto_wake_enabled') !== 'false';
(function() {
    const _nativeGetElementById = document.getElementById;
    const _nativeQuerySelector = document.querySelector;

    function wakeForElement(id, isSelector = false) {
        if (!window.cjosAutoWakeEnabled || window._cjosIsHydrating) return false;
        const templates = document.querySelectorAll('.lazy-page template');
        for (let tpl of templates) {
            try {
                const found = isSelector ? tpl.content.querySelector(id) : tpl.content.getElementById(id);
                if (found) {
                    const page = tpl.closest('.lazy-page');
                    if (page && !page.classList.contains('is-hydrated')) {
                        window.cjosHydratePage(page);
                        return true;
                    }
                }
            } catch(e) {}
        }
        return false;
    }

    document.getElementById = function(id) {
        if (typeof id !== 'string') return _nativeGetElementById.call(document, id);
        let el = _nativeGetElementById.call(document, id);
        if (!el && wakeForElement(id)) {
            el = _nativeGetElementById.call(document, id);
        }
        return el;
    };

    document.querySelector = function(selector) {
        if (typeof selector !== 'string') return _nativeQuerySelector.call(document, selector);
        let el = _nativeQuerySelector.call(document, selector);
        if (!el && selector.includes('#')) {
            const idMatch = selector.match(/#([a-zA-Z0-9_-]+)/);
            if (idMatch && wakeForElement(idMatch[1])) {
                el = _nativeQuerySelector.call(document, selector);
            }
        }
        return el;
    };
})();

const cjosHydrationObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            window.cjosHydratePage(entry.target);
            cjosHydrationObserver.unobserve(entry.target);
        }
    });
}, { 
    root: document.querySelector('.horizontal-viewport'), 
    threshold: 0.05,
    rootMargin: '0px 100px 0px 100px' // Pre-hydrate slightly before entry
});

const mainScroll = document.getElementById('main-scroll');
let lastScrollTop = 0;
window.lsIsProcessing = false; // System-wide lock for transcription/uploads
const scrollThreshold = 2; // Reduced for higher responsiveness

window.cjosInitHydration = function() {
    if (window._cjosHydrationStarted) return;
    window._cjosHydrationStarted = true;
    
    // Check user preference
    if (localStorage.getItem('cjos_lazy_disabled') === 'true') {
        document.querySelectorAll('.lazy-page').forEach(p => window.cjosHydratePage(p));
    } else {
        document.querySelectorAll('.lazy-page').forEach(p => cjosHydrationObserver.observe(p));
    }
};

window.handleLazyToggle = function(disable) {
    localStorage.setItem('cjos_lazy_disabled', disable);
    if (disable) {
        document.querySelectorAll('.lazy-page').forEach(p => window.cjosHydratePage(p));
    }
};

window.handleAutoWakeToggle = function(enabled) {
    localStorage.setItem('cjos_auto_wake_enabled', enabled);
    window.cjosAutoWakeEnabled = enabled;
};

window.addEventListener('load', () => {
    const savedPos = localStorage.getItem('cjos_scroll_pos');
    if (savedPos && mainScroll) mainScroll.scrollTop = parseInt(savedPos, 10);
    
    // If PageLayout is present, it will call cjosInitHydration() after reordering.
    // Otherwise, we auto-init after a short delay to be safe.
    setTimeout(() => {
        if (!window.plManualInit) window.cjosInitHydration();
    }, 100);

    // Initial silent load for settings
    setTimeout(loadSettings, 500); 
});

let isPhysicalInteraction = false;
let interactionTimer = null;

if(mainScroll) {
    // Detection for physical input with Momentum Support
    const startInteraction = () => { 
        isPhysicalInteraction = true; 
        clearTimeout(interactionTimer); 
    };
    const endInteraction = () => {
        // Keep interaction flag active for 800ms after touch ends to handle momentum/flicks
        clearTimeout(interactionTimer);
        interactionTimer = setTimeout(() => { isPhysicalInteraction = false; }, 800);
    };

    mainScroll.addEventListener('touchstart', startInteraction, { passive: true });
    mainScroll.addEventListener('mousedown', startInteraction, { passive: true });
    mainScroll.addEventListener('touchend', endInteraction, { passive: true });
    mainScroll.addEventListener('mouseup', endInteraction, { passive: true });

    // Wheel (Mouse) interaction
    mainScroll.addEventListener('wheel', () => {
        isPhysicalInteraction = true;
        clearTimeout(interactionTimer);
        interactionTimer = setTimeout(() => { isPhysicalInteraction = false; }, 200);
    }, { passive: true });

    let scrollPosSaveTimer = null;

    mainScroll.addEventListener('scroll', () => {
        if (!scrollPosSaveTimer) {
            scrollPosSaveTimer = setTimeout(() => {
                localStorage.setItem('cjos_scroll_pos', mainScroll.scrollTop);
                scrollPosSaveTimer = null;
            }, 250);
        }
        window.cjosUpdateHeaderVisibility();
        
        // Only react to scrolls while the user is interacting or during momentum phase
        if (!isPhysicalInteraction) return;

        // If we are still scrolling, keep the interaction window open
        if (interactionTimer) {
            clearTimeout(interactionTimer);
            interactionTimer = setTimeout(() => { isPhysicalInteraction = false; }, 800);
        }

        if (typeof isSelectMode !== 'undefined' && isSelectMode) { 
            document.body.classList.remove('header-collapsed'); 
            return; 
        }

        // Only allow header collapse if we are on the first page (scrollLeft ~ 0)
        const hViewport = document.querySelector('.horizontal-viewport');
        if (hViewport && hViewport.scrollLeft > 20) {
            document.body.classList.remove('header-collapsed');
            return;
        }

        let scrollTop = mainScroll.scrollTop;
        // Clamp at 0 to avoid elastic bounce issues on mobile
        if (scrollTop < 0) scrollTop = 0;
        
        const diff = scrollTop - lastScrollTop;
        if (Math.abs(diff) <= scrollThreshold) return;

        // LOGIC: Scroll Down (Content moves up) -> Collapse | Scroll Up (Content moves down) -> Expand
        if (diff > 0 && scrollTop > 40) {
            document.body.classList.add('header-collapsed');
        } else if (diff < -5 || scrollTop <= 5) {
            // We use a larger negative threshold (-5) to ensure expansion is deliberate
            document.body.classList.remove('header-collapsed');
            
            // Visual Safety: Restore hidden button states
            const actions = document.querySelector('.default-actions');
            if (actions) {
                actions.querySelectorAll('button:not(#settings-btn)').forEach(btn => {
                    btn.style.removeProperty('opacity');
                    btn.style.removeProperty('transform');
                    btn.style.removeProperty('visibility');
                });
            }
        }
        lastScrollTop = scrollTop;
    }, { passive: true });
}

// ==========================================================================
// 2. SETTINGS & SHARED UI HELPERS
// ==========================================================================
const settingsOverlay = document.getElementById('settings-overlay');
const settingsClose = document.getElementById('settings-close');
const root = document.documentElement;

function openSettings() { 
    settingsOverlay.classList.add('visible');
    if (window.sui) window.sui.registerOverlay('settings', () => settingsOverlay.classList.remove('visible'));
    loadSettings();
    // Refresh Edit Log status icon whenever settings are opened
    if (typeof window.elRefreshStatus === 'function') window.elRefreshStatus();
    
    // Trigger Scroll Reveal for settings items
    if (window.srScan) {
        setTimeout(() => {
            const sc = document.getElementById('settings-scroll-container');
            window.srScan(sc, sc);
        }, 350); // Wait for slide-up animation
    }
}

if(settingsClose) settingsClose.onclick = () => settingsOverlay.classList.remove('visible');
if(settingsOverlay) settingsOverlay.onclick = (e) => { if(e.target === settingsOverlay) settingsOverlay.classList.remove('visible'); };

// --- SHARED UI HELPERS ---
window.renderPicker = function(containerId, options, currentVal, onSelect) {
    const container = document.getElementById(containerId);
    if(!container) return;
    container.innerHTML = '';
    options.forEach(opt => {
        const item = document.createElement('div');
        item.className = `picker-item ${opt.value === currentVal ? 'selected' : ''}`;
        item.innerHTML = `<span>${opt.label}</span><svg class="picker-check" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
        item.onclick = () => {
            container.querySelectorAll('.picker-item').forEach(el => el.classList.remove('selected'));
            item.classList.add('selected');
            onSelect(opt.value);
        };
        container.appendChild(item);
    });
};

// --- SETTINGS LOGIC (SERVER SYNC) ---

const syncStatus = document.getElementById('server-sync-status');

// Local UI Inputs
const inputOuterMargin = document.getElementById('input-outer-margin');
const inputInnerPadding = document.getElementById('input-inner-padding');
const inputRadius = document.getElementById('input-radius');
const radiusSettingDiv = document.getElementById('radius-setting');
const appFrame = document.getElementById('app-frame');

window.togglePluginTray = function(trayId, arrowId) {
    const tray = document.getElementById(trayId);
    const arrow = document.getElementById(arrowId);
    if(tray) tray.classList.toggle('open');
    if(arrow) arrow.classList.toggle('rotated');
};

// A. LOAD SETTINGS
async function loadSettings() {
    loadLocalVisuals(); // Local Visuals (Immediate)

    // Server Config Fetch (Via ConjureCore Plugin)
    if(syncStatus) syncStatus.textContent = "Checking server...";
    try {
        const data = await window.sui.api('cc_get_config', {}, { toast: false });
        
        if (data) {
            const c = data.config;
            // Sync to local storage for plugins
            localStorage.setItem('cjos_api_key', c.api_key || '');
            localStorage.setItem('cjos_model', c.model || 'whisper-1');
            localStorage.setItem('cjos_prompt', c.prompt || '');
            localStorage.setItem('cjos_sound_start', c.sound_start || '');
            localStorage.setItem('cjos_sound_stop', c.sound_stop || '');
            
            if(syncStatus) {
                syncStatus.textContent = "Loaded from backend-config.json";
                syncStatus.style.color = "#34C759"; 
            }
        }
    } catch(e) {
        if(syncStatus) {
            syncStatus.textContent = "Load failed (using local)";
            syncStatus.style.color = "#FF3B30";
        }
    }
}

function loadLocalVisuals() {
    const savedOuter = localStorage.getItem('cjos_outer_margin') || '0';
    const savedInner = localStorage.getItem('cjos_inner_padding') || '0';
    const savedRadius = localStorage.getItem('cjos_radius') || '0';
    const savedColor = localStorage.getItem('cjos_sys_color') || 'theme-default';
    const savedFadeColor = localStorage.getItem('cjos_fade_color') || 'theme-default';
    const savedFade = localStorage.getItem('cjos_fade_edge') === 'true';

    root.style.setProperty('--outer-margin-top', savedOuter + 'px');
    root.style.setProperty('--inner-padding-top', savedInner + 'px');
    root.style.setProperty('--app-corner-radius', savedRadius + 'px');
    
    if (savedColor === 'theme-default') root.style.removeProperty('--system-bar-bg');
    else root.style.setProperty('--system-bar-bg', savedColor);

    // Sync Meta Tag for Mobile Status Bar
    const meta = document.getElementById('meta-theme-color');
    if (meta) {
        let metaCol = savedColor;
        if (savedColor === 'theme-default' || savedColor === 'transparent') {
            const themeBg = getComputedStyle(root).getPropertyValue('--bg-color').trim();
            metaCol = (themeBg === 'transparent' || !themeBg) ? '#000000' : themeBg;
        }
        meta.setAttribute('content', metaCol);
    }

    if (savedFadeColor === 'theme-default') root.style.removeProperty('--bottom-fade-bg');
    else root.style.setProperty('--bottom-fade-bg', savedFadeColor);

    if(inputOuterMargin) inputOuterMargin.value = savedOuter;
    if(inputInnerPadding) inputInnerPadding.value = savedInner;
    if(inputRadius) inputRadius.value = savedRadius;
    
    const fadeToggle = document.getElementById('input-fade-toggle');
    if(fadeToggle) fadeToggle.checked = savedFade;

    const lazyToggle = document.getElementById('input-lazy-toggle');
    if(lazyToggle) lazyToggle.checked = (localStorage.getItem('cjos_lazy_disabled') === 'true');
    
    const wakeToggle = document.getElementById('input-wake-toggle');
    if(wakeToggle) wakeToggle.checked = (localStorage.getItem('cjos_auto_wake_enabled') !== 'false');


    
    const inputSysColor = document.getElementById('input-sys-color');
    const inputSysText = document.getElementById('input-sys-text');
    if(inputSysColor) inputSysColor.value = savedColor;
    if(inputSysText) inputSysText.value = savedColor;
    
    toggleFadeMode(savedFade);
}



// Visual Logic
window.handleFadeToggle = function(isFade) {
    localStorage.setItem('cjos_fade_edge', isFade);
    toggleFadeMode(isFade);
};



function toggleFadeMode(isFade) {
    const frame = document.getElementById('app-frame');
    const radiusDiv = document.getElementById('radius-setting');
    if(!frame) return;
    if(isFade) { 
        frame.classList.add('fade-mode'); 
        if(radiusDiv) { radiusDiv.style.opacity = '0.3'; radiusDiv.style.pointerEvents = 'none'; } 
    } else { 
        frame.classList.remove('fade-mode'); 
        if(radiusDiv) { radiusDiv.style.opacity = '1'; radiusDiv.style.pointerEvents = 'auto'; } 
    }
}

if(inputOuterMargin) inputOuterMargin.addEventListener('input', (e) => { root.style.setProperty('--outer-margin-top', e.target.value + 'px'); localStorage.setItem('cjos_outer_margin', e.target.value); });
if(inputInnerPadding) inputInnerPadding.addEventListener('input', (e) => { root.style.setProperty('--inner-padding-top', e.target.value + 'px'); localStorage.setItem('cjos_inner_padding', e.target.value); });
if(inputRadius) inputRadius.addEventListener('input', (e) => { root.style.setProperty('--app-corner-radius', e.target.value + 'px'); localStorage.setItem('cjos_radius', e.target.value); });

// --- DESKTOP SIDEBAR RESIZER ---
(function() {
    const resizer = document.getElementById('sidebar-resizer');
    if (!resizer) return;

    let isDragging = false;
    const savedWidth = localStorage.getItem('cjos_sidebar_width');
    if (savedWidth) document.documentElement.style.setProperty('--sidebar-width', savedWidth);

    const initDrag = (e) => {
        if (window.innerWidth < 1024) return;
        isDragging = true;
        resizer.classList.add('dragging');
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        window.addEventListener('mousemove', doDrag);
        window.addEventListener('mouseup', stopDrag);
    };

    const doDrag = (e) => {
        if (!isDragging) return;
        // Clamp width between 280px and 50% of screen
        let newWidth = Math.max(280, Math.min(e.clientX, window.innerWidth * 0.5));
        const widthStr = newWidth + 'px';
        document.documentElement.style.setProperty('--sidebar-width', widthStr);
        localStorage.setItem('cjos_sidebar_width', widthStr);
    };

    const stopDrag = () => {
        isDragging = false;
        resizer.classList.remove('dragging');
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
        window.removeEventListener('mousemove', doDrag);
        window.removeEventListener('mouseup', stopDrag);
    };

    resizer.addEventListener('mousedown', initDrag);
})();

// ==========================================================================
// 3. UTILS & ACTIONS
// ==========================================================================

// --- CORE REGISTRIES ---
window.cjosPluginRegistry = window.cjosPluginRegistry || [];
window.registerCardPlugin = function(setupFunction, priority = 50) {
    window.cjosPluginRegistry.push({ fn: setupFunction, priority: priority });
    window.cjosPluginRegistry.sort((a, b) => a.priority - b.priority);
    if (document.readyState === "complete") {
        document.querySelectorAll(".card").forEach(setupFunction);
    }
};

window.cjosRefreshRegistry = window.cjosRefreshRegistry || [];
window.registerRefreshHook = function(fn) { window.cjosRefreshRegistry.push(fn); };
window.cjosRefreshPlugins = function() {
    window.cjosRefreshRegistry.forEach(fn => { try { fn(); } catch(e) { console.error("Refresh Hook Failed", e); } });
    setTimeout(window.cjosUpdateHeaderVisibility, 50); // Small delay to allow DOM to settle
};

window.cjosUpdateRegistry = window.cjosUpdateRegistry || [];
window.registerUpdateHook = function(fn) { window.cjosUpdateRegistry.push(fn); };

function copyToClipboard(text, cardElement) {
    const t = document.createElement("textarea"); 
    t.value = text; 
    document.body.appendChild(t); 
    t.select(); 
    document.execCommand("copy"); 
    document.body.removeChild(t);
    
    if (window.sui && window.sui.toast) {
        window.sui.toast("Copied to Clipboard", {
            plugin: "System",
            caller: "copyToClipboard",
            metrics: { length: text.length, preview: text.substring(0, 200) + (text.length > 200 ? "..." : "") }
        });
    }
}

function getSelectedItems() {
    const checked = document.querySelectorAll('.custom-checkbox.checked');
    if (!checked.length || !Array.isArray(logs)) return [];
    
    // Fast O(1) Map Lookup
    const logMap = new Map(logs.map(l => [l.id, l]));
    let items = []; 
    checked.forEach(box => { 
        const id = box.getAttribute('data-id'); 
        const itemData = logMap.get(id); 
        if (itemData) items.push(itemData); 
    }); 
    return items;
}

let isSelectMode = false;
const selectionCountLabel = document.getElementById('selection-count');

window.updateSelectionCount = function() {
    const count = document.querySelectorAll('.custom-checkbox.checked').length;
    if(selectionCountLabel) selectionCountLabel.innerText = count + " Selected";
}

window.cjosToggleSelectMode = function(enable) {
    isSelectMode = enable;
    window.dispatchEvent(new CustomEvent('cjos-select-mode', { detail: { enabled: enable } }));
    if (enable) { 
        document.body.classList.add('select-mode'); 
        document.body.classList.remove('header-collapsed'); 
        updateSelectionCount(); 
    } else { 
        document.body.classList.remove('select-mode'); 
        document.querySelectorAll('.custom-checkbox').forEach(cb => cb.classList.remove('checked')); 
    }
}

const btnDelete = document.getElementById('action-delete');
const btnCopy = document.getElementById('action-copy');
const btnReprocess = document.getElementById('action-reprocess');

if(btnDelete) btnDelete.onclick = async () => { 
    const items = getSelectedItems(); 
    if(items.length) { 
        const ids = items.map(item => item.id);

        // 1. Optimistically animate and remove cards immediately (0ms visual latency)
        items.forEach(item => {
            const logIdx = logs.findIndex(l => l.id === item.id);
            if (logIdx !== -1) logs.splice(logIdx, 1);

            const checkbox = document.querySelector(`.custom-checkbox[data-id="${item.id}"]`);
            if (checkbox) {
                const card = checkbox.closest('.card');
                if (card) {
                    card.style.transition = 'all 0.25s ease'; 
                    card.style.opacity = '0'; 
                    card.style.transform = 'scale(0.9)'; 
                    card.style.marginBottom = '-50px'; 
                    setTimeout(() => card.remove(), 250);
                }
            }

            if (window.cjosHooks) window.cjosHooks.emit('onDelete', item.id);
        });

        if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
        cjosToggleSelectMode(false);

        // 2. Fire SINGLE transactional batch delete request to backend
        try {
            const formData = new FormData();
            formData.append('action', 'delete_batch');
            formData.append('ids', JSON.stringify(ids));
            await fetch(window.CJOS_API_URL, { method: 'POST', body: formData });
        } catch(e) { console.error('Batch delete sync error:', e); }
    }
};

if(btnCopy) btnCopy.onclick = () => { 
    const items = getSelectedItems(); 
    if(items.length) { 
        const texts = items.map(i => i.transcription); 
        copyToClipboard(texts.join("\n\n"), null); 
        if (window.markIdsAsInteracted) window.markIdsAsInteracted(items.map(i => i.id));
        cjosToggleSelectMode(false); 
    }
};

if(btnReprocess) btnReprocess.onclick = async () => { 
    let items = getSelectedItems(); 
    if(!items.length) return;
    window.processTranscriptionBatch(items);
};

// The transcription pipeline is now managed by the LiveSync plugin.
window.processTranscriptionBatch = (items) => window.cjosPipeline?.processBatch?.(items);

