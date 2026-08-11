<?php
// ==============================================================================
// PLUGIN: Onboarding (Conjure Guide)
// ==============================================================================
// 
// CRITICAL DEVELOPER INSTRUCTIONS:
// 1. SYSTEM-WIDE SCOPE: This plugin acts as an interactive bridge across almost 
//    all core plugins (SharedUI, FloatingRecorder, FloatingCommandBar, Patcher, etc.).
// 2. ZERO-GUESSING POLICY: Do NOT assume variable names, element IDs, or tool 
//    behaviors. If a file or plugin referenced in the curriculum (e.g., ThemePresets.php, 
//    AppMaker.php, etc.) is missing from your context, you MUST request it first 
//    using the AI Export Protocol.
// 3. MANDATE OF GENTLE GUIDANCE: The guide must NEVER click, swipe, or trigger 
//    actions on behalf of the user. It may only highlight targets, simulate visual 
//    gestures (3-pulse animations), and check state variables.
// ==============================================================================

$onboarding_file = CJOS_PATH_DATA . '/onboarding-private.json';

// --- API HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'onboarding_get_state') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults = [
            'enabled' => false,
            'mission' => 1,
            'step' => 0,
            'completed' => true
        ];
        $state = file_exists($onboarding_file) ? json_decode(file_get_contents($onboarding_file), true) : $defaults;
        echo json_encode(['status' => 'success', 'state' => $state]);
        exit;
    }
    if ($_POST['plugin_action'] === 'onboarding_save_state') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $state = json_decode($_POST['state'], true);
        file_put_contents($onboarding_file, json_encode($state, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- SETTINGS UI ---
$plugin_settings_map['Onboarding'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Conjure Guide</label>
        <div class="setting-desc">The interactive onboarding assistant.</div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:12px;">
            <button onclick="Guide.forceStart()" class="text-btn" style="background:var(--btn-bg); color:var(--primary); border-radius:10px; padding:10px; font-size:12px; font-weight:700; border:1px solid var(--border-color);">
                Restart Guide
            </button>
            <button onclick="Guide.toggleVisibility()" id="guide-toggle-btn" class="text-btn" style="background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:12px; font-weight:700; border:1px solid var(--border-color);">
                Hide Guide
            </button>
        </div>
    </div>
HTML;

// --- UI OVERLAYS ---
$plugin_overlays[] = <<<'HTML'
<style>
    :root {
        --guide-blob-size: 56px;
    }
    /* THE BLOB */
    #guide-blob {
        position: fixed;
        width: var(--guide-blob-size);
        height: var(--guide-blob-size);
        border-radius: 50%;
        background: var(--glass-bg);
        backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px);
        border: 1.5px solid var(--glass-border);
        box-shadow: var(--shadow-floating);
        z-index: 999998;
        display: flex; align-items: center; justify-content: center;
        cursor: grab;
        touch-action: none;
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s;
        opacity: 0; visibility: hidden;
    }
    #guide-blob.visible { opacity: 1; visibility: visible; }
    #guide-blob:active { cursor: grabbing; transform: scale(0.95); }
    
    .guide-progress-ring {
        position: absolute; 
        inset: 0;
        width: 100%;
        height: 100%;
        transform: rotate(-90deg);
        pointer-events: none;
        overflow: visible;
    }
    .guide-progress-ring circle {
        fill: none;
        /* Stroke width is relative to the 100-unit viewBox */
        stroke-width: 5; 
    }
    .guide-progress-ring .progress-track {
        stroke: var(--border-color);
        opacity: 0.3;
    }
    .guide-progress-ring .progress-value {
        stroke: var(--primary);
        stroke-linecap: round;
        /* Initial state for JS to take over */
        stroke-dasharray: 292; 
        stroke-dashoffset: 292;
        transition: stroke-dashoffset 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    }

    #guide-blob-content {
        font-size: 20px;
        user-select: none;
        pointer-events: none;
    }

    /* THE NON-BLOCKING FLOATING PANEL WRAPPER */
    #guide-panel-overlay {
        position: fixed; inset: 0;
        background: transparent;
        pointer-events: none; /* Let clicks pass through! */
        z-index: 999997;
        display: block;
        opacity: 0; visibility: hidden;
        transition: opacity 0.3s, visibility 0.3s;
    }
    #guide-panel-overlay.visible { opacity: 1; visibility: visible; }

    #guide-panel {
        position: fixed;
        width: 320px;
        height: auto;
        min-height: 180px;
        /* Responsive adaptive viewport sizing */
        max-width: calc(100vw - 24px);
        max-height: 48vh; /* Mobile: max 48% of screen height to leave half screen open */
        background: var(--glass-bg);
        backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
        border: 1px solid var(--glass-border);
        border-radius: 20px;
        box-shadow: var(--shadow-floating);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        touch-action: none;
        pointer-events: auto; /* Catch clicks on the window itself */
        transform: scale(0.9);
        transition: opacity 0.3s, transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), border-radius 0.3s;
    }

    @media (min-width: 768px) {
        #guide-panel {
            max-width: 380px;
            max-height: 58vh; /* Tablet: max 58% of screen height */
        }
    }

    @media (min-width: 1024px) {
        #guide-panel {
            max-width: 460px;
            max-height: 68vh; /* Desktop: max 68% of screen height */
        }
    }
    #guide-panel-overlay.visible #guide-panel { transform: scale(1); }

    .guide-drag-handle {
        background: rgba(255, 255, 255, 0.03);
        border-bottom: 1px solid var(--border-color);
        padding: 10px 14px;
        cursor: grab;
        display: flex;
        align-items: center;
        justify-content: space-between;
        user-select: none;
        flex-shrink: 0; /* Lock header size */
    }
    .guide-drag-handle:active {
        cursor: grabbing;
    }
    
    .guide-panel-body {
        padding: 18px 20px 14px 20px;
        display: flex;
        flex-direction: column;
        gap: 14px;
        overflow-y: auto;
        flex: 1;
    }

    .guide-mission-tag { font-size: 10px; font-weight: 900; text-transform: uppercase; color: var(--primary); letter-spacing: 1px; }
    .guide-title { font-size: 17px; font-weight: 800; color: var(--text-primary); }
    
    .guide-text { 
        font-size: 14px; 
        color: var(--text-secondary); 
        line-height: 1.5; 
    }
    
    /* Interactive Actions styling */
    .guide-bold-trigger {
        color: var(--primary) !important;
        font-weight: 800 !important;
        cursor: pointer !important;
        border-bottom: 2px solid color-mix(in srgb, var(--primary), transparent 30%);
        padding: 1px 4px 1px 2px;
        background: color-mix(in srgb, var(--primary), transparent 94%);
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
    }
    .guide-bold-trigger:hover {
        background: color-mix(in srgb, var(--primary), transparent 88%);
    }
    .guide-bold-trigger:active {
        opacity: 0.6;
    }
    .guide-target-svg-icon {
        width: 11px;
        height: 11px;
        flex-shrink: 0;
        stroke: var(--primary);
    }
    
    .guide-text strong {
        color: var(--text-primary);
        font-weight: 800;
    }
    
    .guide-footer { 
        display: flex; 
        gap: 12px; 
        border-top: 1px solid var(--border-color); 
        padding: 12px 20px;
        background: rgba(255, 255, 255, 0.01);
        flex-shrink: 0; /* Lock footer at bottom */
    }

    /* Resize Handle */
    .guide-resize-handle {
        position: absolute;
        bottom: 0;
        right: 0;
        width: 16px;
        height: 16px;
        cursor: se-resize;
        z-index: 10;
        background-image: linear-gradient(135deg, transparent 50%, var(--text-secondary) 50%, transparent 60%, var(--text-secondary) 60%, transparent 70%, var(--text-secondary) 70%);
        background-size: 100% 100%;
        opacity: 0.4;
    }

    /* PORTAL GLOW */
    #guide-glow-portal {
        position: fixed;
        pointer-events: none;
        z-index: 999998;
        border: 3px solid var(--primary);
        border-radius: 12px;
        box-shadow: 0 0 10px var(--primary), inset 0 0 5px var(--primary);
        opacity: 0;
        visibility: hidden;
        box-sizing: border-box; 
        transition: 
            left 0.5s cubic-bezier(0.16, 1, 0.3, 1), 
            top 0.5s cubic-bezier(0.16, 1, 0.3, 1), 
            width 0.5s cubic-bezier(0.16, 1, 0.3, 1), 
            height 0.5s cubic-bezier(0.16, 1, 0.3, 1), 
            transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), 
            opacity 0.4s ease, 
            visibility 0.4s, 
            border-radius 0.4s ease;
    }

    #guide-glow-portal.tracking-active {
        transition: opacity 0.2s ease, visibility 0.2s, border-radius 0.2s ease !important;
    }

    #guide-glow-portal.target-hidden {
        opacity: 0 !important;
        visibility: hidden !important;
    }

    @keyframes portal-pulse-elegant {
        0% { transform: scale(1); box-shadow: 0 0 10px var(--primary), inset 0 0 5px var(--primary); }
        50% { transform: scale(1.05); box-shadow: 0 0 25px var(--primary), inset 0 0 10px var(--primary); }
        100% { transform: scale(1); box-shadow: 0 0 10px var(--primary), inset 0 0 5px var(--primary); }
    }
    
    #guide-glow-portal.active {
        visibility: visible;
        opacity: 1;
        animation: portal-pulse-elegant 1.2s ease-in-out 3; /* 3 pulses */
    }

    #guide-glow-portal.persistent-active {
        visibility: visible;
        opacity: 1;
        animation: portal-pulse-elegant 2s ease-in-out infinite; /* Slower, infinite */
    }

    /* VIRTUAL TOUCH POINTER */
    #guide-touch-pointer {
        position: fixed;
        width: 44px; height: 44px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.3);
        border: 2px solid var(--primary);
        box-shadow: 0 0 15px var(--primary), inset 0 0 10px var(--primary);
        pointer-events: none;
        z-index: 10000002; /* Above Portal Glow */
        transform: translate(-50%, -50%) scale(0);
        opacity: 0;
        transition: opacity 0.2s, transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    #guide-touch-pointer.active {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
    }
    #guide-touch-pointer.moving {
        transition: none; /* Instant 1:1 tracking during drag */
    }
</style>

<div id="guide-glow-portal"></div>
<div id="guide-touch-pointer"></div>

<div id="guide-blob" class="visible">
    <svg class="guide-progress-ring" viewBox="0 0 100 100">
        <circle cx="50" cy="50" r="46.5" class="progress-track"></circle>
        <circle cx="50" cy="50" r="46.5" class="progress-value"></circle>
    </svg>
    <div id="guide-blob-content" style="display: flex; align-items: center; justify-content: center;"></div>
</div>

<div id="guide-panel-overlay">
    <div id="guide-panel">
        <div class="guide-drag-handle" id="guide-drag-handle">
            <div id="guide-mission-label" class="guide-mission-tag">Mission 1.1</div>
            <div style="display: flex; gap: 8px;">
                <button id="guide-minimize-btn" onclick="Guide.shrink()" style="background:var(--btn-bg); border:none; width:26px; height:26px; border-radius:50%; color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <span data-sui-icon="minus" data-sui-size="12" data-sui-stroke="3"></span>
                </button>
            </div>
        </div>
        <div class="guide-panel-body">
            <div id="guide-title" class="guide-title">Welcome to Conjure</div>
            <div id="guide-content" class="guide-text">
                I am your guide. Let's start by exploring the <b>Navigation Bar</b> at the bottom.
            </div>
        </div>
        <div class="guide-footer">
            <button id="guide-prev-btn" onclick="Guide.prev()" class="text-btn" style="flex:1; background:var(--btn-bg); border:1px solid var(--border-color); border-radius:12px; font-weight:700; color:var(--text-secondary); display:none; padding:10px; font-size:13px; cursor:pointer;">Back</button>
            <button id="guide-next-btn" onclick="Guide.next()" class="btn-primary" style="flex:2; padding:10px; font-size:13px; cursor:pointer;">Next</button>
        </div>
        <div class="guide-resize-handle" id="guide-resize-handle"></div>
    </div>
</div>
HTML;

// --- JAVASCRIPT ---
$plugin_js .= <<<'JS'
window.Guide = {
    state: { enabled: true, mission: 1, step: 0, completed: false },
    isDragging: false,
    dragStart: { x: 0, y: 0 },
    blobPos: { x: 85, y: 80 }, // Percentages
    panelPos: { left: 100, top: 100, width: 320, height: 260 },
    glowTimeout: null,
    gestureState: { active: false, btn: null, startX: 0, startY: 0, timeouts: [], intervals: [], rafs: [] },

    async init() {
        const res = await window.sui.api('onboarding_get_state', {}, { toast: false });
        if (res && res.state) {
            this.state = res.state;
        }

        // Auto-start logic disabled in favor of Demo Mode card-based onboarding
        if (typeof logs !== 'undefined' && logs.length === 0 && !this.state.completed) {
            this.state.enabled = false;
        }

        // Battery-Friendly Visibility Observer
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.activeGlowTarget) {
                if (!this.isTracking) {
                    this.trackGlow();
                }
            }
        });

        this.loadPosition();
        this.render();
        this.setupDraggable();
        this.setupPanelEvents();
        this.adaptHeight(); // Measure and apply dynamic height constraints initially
        this.updateVisibility();
    },

    loadPosition() {
        const saved = localStorage.getItem('cjos_guide_pos');
        if (saved) this.blobPos = JSON.parse(saved);
        this.applyPosition();
    },

    applyPosition() {
        const blob = document.getElementById('guide-blob');
        if (!blob) return;
        blob.style.left = `calc(${this.blobPos.x}% - (var(--guide-blob-size) / 2))`;
        blob.style.top = `calc(${this.blobPos.y}% - (var(--guide-blob-size) / 2))`;
    },

    adaptHeight() {
        const panel = document.getElementById('guide-panel');
        const dragHandle = document.getElementById('guide-drag-handle');
        const body = document.querySelector('.guide-panel-body');
        const footer = document.querySelector('.guide-footer');
        if (!panel || !dragHandle || !body || !footer) return;

        // Reset height constraints so the panel can measure itself
        panel.style.height = 'auto';

        const headerH = dragHandle.offsetHeight || 38;
        const bodyH = body.scrollHeight || 100;
        const footerH = footer.offsetHeight || 54;
        
        // 6px safety buffer to prevent single-line scrollbar jitter
        const minHeightNeeded = headerH + bodyH + footerH + 6; 

        // Check if the user has manually resized the window
        const isResized = localStorage.getItem('cjos_guide_is_resized') === 'true';
        let savedRect = null;
        try {
            const saved = localStorage.getItem('cjos_guide_panel_rect');
            if (saved) savedRect = JSON.parse(saved);
        } catch(e) {}

        // Set static safe min-height limit
        panel.style.minHeight = '180px';

        if (isResized && savedRect && savedRect.height) {
            panel.style.height = savedRect.height + 'px';
        } else {
            // Default: Auto-fit to the content perfectly, let browser CSS max-height clamp it on small screens
            panel.style.height = minHeightNeeded + 'px';
        }
    },

    setupDraggable() {
        const blob = document.getElementById('guide-blob');
        if (!blob) return;

        const onMove = (e) => {
            const x = e.clientX;
            const y = e.clientY;

            if (!this.isDragging) {
                const dist = Math.sqrt(Math.pow(x - this.dragStart.x, 2) + Math.pow(y - this.dragStart.y, 2));
                if (dist > 8) {
                    this.isDragging = true;
                    blob.style.transition = 'none'; // Disable transitions during active drag
                }
                return;
            }

            this.blobPos.x = (x / window.innerWidth) * 100;
            this.blobPos.y = (y / window.innerHeight) * 100;
            
            // Constrain
            this.blobPos.x = Math.max(5, Math.min(95, this.blobPos.x));
            this.blobPos.y = Math.max(5, Math.min(95, this.blobPos.y));
            
            this.applyPosition();
        };

        const onEnd = (e) => {
            blob.releasePointerCapture(e.pointerId);
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onEnd);

            if (this.isDragging) {
                blob.style.transition = ''; // Restore transitions
                localStorage.setItem('cjos_guide_pos', JSON.stringify(this.blobPos));
                setTimeout(() => { this.isDragging = false; }, 50);
            }
        };

        blob.onpointerdown = (e) => {
            if (e.button !== 0) return;
            
            this.isDragging = false;
            this.dragStart = { x: e.clientX, y: e.clientY };
            
            blob.setPointerCapture(e.pointerId);
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onEnd);
        };

        blob.onclick = (e) => {
            if (this.isDragging) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            this.expand();
        };
    },

    setupPanelEvents() {
        const panel = document.getElementById('guide-panel');
        const dragHandle = document.getElementById('guide-drag-handle');
        const resizeHandle = document.getElementById('guide-resize-handle');
        if (!panel) return;

        // Load position/size
        const saved = localStorage.getItem('cjos_guide_panel_rect');
        if (saved) {
            this.panelPos = JSON.parse(saved);
        } else {
            this.panelPos.left = (window.innerWidth - 320) / 2;
            this.panelPos.top = (window.innerHeight - 260) / 2;
        }
        
        // Ensure bounds
        this.panelPos.left = Math.max(10, Math.min(window.innerWidth - 100, this.panelPos.left));
        this.panelPos.top = Math.max(10, Math.min(window.innerHeight - 100, this.panelPos.top));
        this.panelPos.width = Math.max(260, Math.min(600, this.panelPos.width));
        this.panelPos.height = Math.max(200, Math.min(600, this.panelPos.height));

        const applyRect = () => {
            panel.style.left = this.panelPos.left + 'px';
            panel.style.top = this.panelPos.top + 'px';
            panel.style.width = this.panelPos.width + 'px';
            panel.style.height = this.panelPos.height + 'px';
        };
        applyRect();

        // Dragging
        let isDragging = false;
        let dragOffset = { x: 0, y: 0 };

        dragHandle.onpointerdown = (e) => {
            if (e.button !== 0) return;
            isDragging = true;
            dragOffset.x = e.clientX - this.panelPos.left;
            dragOffset.y = e.clientY - this.panelPos.top;
            dragHandle.setPointerCapture(e.pointerId);
            dragHandle.style.cursor = 'grabbing';
            e.preventDefault();
        };

        dragHandle.onpointermove = (e) => {
            if (!isDragging) return;
            this.panelPos.left = e.clientX - dragOffset.x;
            this.panelPos.top = e.clientY - dragOffset.y;
            
            // Constrain
            this.panelPos.left = Math.max(10, Math.min(window.innerWidth - this.panelPos.width - 10, this.panelPos.left));
            this.panelPos.top = Math.max(10, Math.min(window.innerHeight - this.panelPos.height - 10, this.panelPos.top));
            
            applyRect();
        };

        const endDrag = (e) => {
            if (isDragging) {
                isDragging = false;
                dragHandle.releasePointerCapture(e.pointerId);
                dragHandle.style.cursor = 'grab';
                localStorage.setItem('cjos_guide_panel_rect', JSON.stringify(this.panelPos));
            }
        };
        dragHandle.onpointerup = endDrag;
        dragHandle.onpointercancel = endDrag;

        // Resizing
        let isResizing = false;
        let resizeStart = { w: 0, h: 0, x: 0, y: 0 };

        resizeHandle.onpointerdown = (e) => {
            if (e.button !== 0) return;
            isResizing = true;
            resizeStart.w = this.panelPos.width;
            resizeStart.h = this.panelPos.height;
            resizeStart.x = e.clientX;
            resizeStart.y = e.clientY;
            resizeHandle.setPointerCapture(e.pointerId);
            e.preventDefault();
            e.stopPropagation();
        };

        resizeHandle.onpointermove = (e) => {
            if (!isResizing) return;
            const dw = e.clientX - resizeStart.x;
            const dh = e.clientY - resizeStart.y;
            
            // Read the dynamically computed minHeight limit instead of a static value
            const minH = parseInt(panel.style.minHeight) || 200;
            this.panelPos.width = Math.max(260, Math.min(600, resizeStart.w + dw));
            this.panelPos.height = Math.max(minH, Math.min(600, resizeStart.h + dh));
            
            applyRect();
        };

        const endResize = (e) => {
            if (isResizing) {
                isResizing = false;
                resizeHandle.releasePointerCapture(e.pointerId);
                localStorage.setItem('cjos_guide_is_resized', 'true'); // Flag manual user resize
                localStorage.setItem('cjos_guide_panel_rect', JSON.stringify(this.panelPos));
            }
        };
        resizeHandle.onpointerup = endResize;
        resizeHandle.onpointercancel = endResize;

        // Double-click the drag header to clear custom dimensions and return to auto-fitting
        dragHandle.ondblclick = () => {
            localStorage.removeItem('cjos_guide_is_resized');
            localStorage.removeItem('cjos_guide_panel_rect');
            if (window.sui && window.sui.haptic) window.sui.haptic('medium');
            
            // Reset position coordinate closure variables
            this.panelPos.width = 320;
            this.panelPos.height = 260;
            this.panelPos.left = (window.innerWidth - 320) / 2;
            this.panelPos.top = (window.innerHeight - 260) / 2;
            
            panel.style.width = this.panelPos.width + 'px';
            panel.style.left = this.panelPos.left + 'px';
            
            this.adaptHeight();
            if (window.sui && window.sui.toast) window.sui.toast("Returned to Auto-Fit Height");
        };
    },

    expand() {
        window.sui.haptic('light');
        document.getElementById('guide-panel-overlay').classList.add('visible');
        this.updateVisibility();
    },

    shrink() {
        this.cleanupGestureDemo();
        document.getElementById('guide-panel-overlay').classList.remove('visible');
        this.updateVisibility();
    },

    makeWay(el) {
        const overlay = document.getElementById('guide-panel-overlay');
        if (!overlay || !overlay.classList.contains('visible')) return;

        const panel = document.getElementById('guide-panel');
        if (!panel || !el) return;

        // Safety Guard: If the element is a child of the panel, never trigger shifting
        if (panel.contains(el)) return;

        const pRect = panel.getBoundingClientRect();
        const eRect = el.getBoundingClientRect();
        const screenH = window.innerHeight;

        let newTop = this.panelPos.top;

        // Always push the guide panel to the opposite vertical half of the screen 
        // to guarantee maximum visibility of the highlighted element and its surrounding modal/context.
        if (eRect.top + (eRect.height / 2) > screenH / 2) {
            newTop = 24; // Slide to the top half
        } else {
            // Slide to the absolute bottom of the screen ("bottom of the bottom"), 
            // overlapping the command bar to completely clear the middle workspace.
            const bottomOffset = 12; 
            newTop = Math.max(24, screenH - pRect.height - bottomOffset);
        }

        // --- PHYSICAL COLLISION/OBSTRUCTION GUARD ---
        // If the proposed panel position still overlaps the target element,
        // automatically minimize the guide to the floating blob to allow unobstructed view.
        const pHeight = pRect.height || 260;
        const pWidth = pRect.width || 320;
        const padding = 16;
        
        const proposedPanelTop = newTop;
        const proposedPanelBottom = newTop + pHeight;
        const proposedPanelLeft = this.panelPos.left;
        const proposedPanelRight = this.panelPos.left + pWidth;

        const stillOverlaps = !(
            proposedPanelRight + padding < eRect.left ||
            proposedPanelLeft - padding > eRect.right ||
            proposedPanelBottom + padding < eRect.top ||
            proposedPanelTop - padding > eRect.bottom
        );

        if (stillOverlaps) {
            this.shrink();
            if (window.sui && window.sui.toast) {
                window.sui.toast("Guide minimized to reveal highlighted target");
            }
            return;
        }

        if (newTop !== this.panelPos.top) {
            // Slide vertically using cubic-bezier easing
            panel.style.transition = 'top 0.4s cubic-bezier(0.2, 0, 0.2, 1), transform 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
            
            this.panelPos.top = newTop;
            panel.style.top = newTop + 'px';

            localStorage.setItem('cjos_guide_panel_rect', JSON.stringify(this.panelPos));

            setTimeout(() => {
                panel.style.transition = 'opacity 0.3s, transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), border-radius 0.3s';
            }, 450);
        }
    },

    render() {
        const circle = document.querySelector('.guide-progress-ring .progress-value');
        if (!circle) return;
        
        const circumference = 2 * Math.PI * 46.5;
        circle.style.strokeDasharray = `${circumference} ${circumference}`;

        const totalMissions = 6; 
        const progress = (this.state.mission - 1) / totalMissions;
        const offset = circumference - (circumference * progress);
        circle.style.strokeDashoffset = offset;
        
        const contentEl = document.getElementById('guide-blob-content');
        if (contentEl) {
            contentEl.innerHTML = window.suiIcon(this.getMissionIcon(), 'var(--primary)', 24);
        }

        if (this.state.mission === 2 || this.state.mission === 3 || this.state.mission === 6) {
            if (!document.body.classList.contains('fcb-mode')) {
                if (typeof window.frUpdateUiMode === 'function') window.frUpdateUiMode('bar');
            }
            const fcb = document.getElementById('fcb-container');
            if (fcb) {
                if (this.state.mission === 2 && this.state.step === 0) {
                    fcb.classList.remove('show-actions');
                } else if (!fcb.classList.contains('show-actions')) {
                    fcb.classList.add('show-actions');
                }
            }
        } else {
            const fcb = document.getElementById('fcb-container');
            if (fcb && fcb.classList.contains('show-actions')) {
                fcb.classList.remove('show-actions');
            }
        }
        
        const prevBtn = document.getElementById('guide-prev-btn');
        const nextBtn = document.getElementById('guide-next-btn');
        
        if (prevBtn) prevBtn.style.display = (this.state.mission > 1 || this.state.step > 0) ? 'block' : 'none';
        
        if (nextBtn) {
            const isLastMission = this.state.mission === 6;
            const isLastStep = this.isLastStep();
            nextBtn.innerText = (isLastMission && isLastStep) ? 'Finish' : 'Next';
        }

        this.updatePanelContent();
    },

    isLastStep() {
        const curriculum = this.getCurriculum();
        const steps = curriculum[this.state.mission]?.steps || [];
        return this.state.step >= steps.length - 1;
    },

    async prev() {
        this.cleanupGestureDemo();
        if (this.state.step > 0) {
            this.state.step--;
        } else if (this.state.mission > 1) {
            this.state.mission--;
            const curriculum = this.getCurriculum();
            this.state.step = (curriculum[this.state.mission]?.steps.length || 1) - 1;
        } else {
            return;
        }
        
        window.sui.haptic('light');
        this.render();
        await this.saveState();
    },

    async next() {
        this.cleanupGestureDemo();
        if (!this.isLastStep()) {
            this.state.step++;
            window.sui.haptic('light');
        } else if (this.state.mission < 6) {
            this.state.mission++;
            this.state.step = 0;
            window.sui.haptic('success');
        } else {
            this.state.completed = true;
            this.state.enabled = false;
            window.sui.haptic('success');
        }
        
        this.render();
        await this.saveState();
        this.updateVisibility();
    },

    async saveState() {
        await window.sui.api('onboarding_save_state', { state: this.state }, { toast: false });
    },

    getMissionIcon() {
        const icons = ['compass', 'layout', 'brain', 'mic', 'mouse-pointer-2', 'hammer'];
        return icons[this.state.mission - 1] || 'shield-check';
    },

    getCurriculum() {
        const isBar = document.body.classList.contains('fcb-mode');
        
        const barSteps = [
            { title: "The Command Bar", text: "The button on the far left is the <b>Omni Button</b>. It acts as a back button and a recorder trigger. Tap to exit a page or scroll to top. Double tap to quickly get to the logs.", target: "record" },
            { title: "Recording Gestures", text: "To record, press and hold the button, <b>Slide Up</b>, and release. When you are finished, do the same to stop." },
            { title: "Utility Reveal", text: "<b>Swipe Right</b> on the button to show or hide the hidden utilities:<br><br>a) <b>Switch Toggle</b>: Minimizes the Command Bar into a minimalistic gesture-based record button. Swipe down on the record button to switch back to the Command Bar.<br><br>b) <b>Recorder Settings</b>: Opens the configuration options." }
        ];

        const fabSteps = [
            { title: "The Record Button", text: "You are currently using the floating <b>Record Button</b>. Press and hold it to reveal the tiered <b>Quick Action Menu</b>.", target: "record" },
            { title: "Tiered Actions", text: "<b>Swipe Up</b> and release on an icon to trigger it. The further you swipe, the more <b>Tiered Actions</b> are revealed." },
            { title: "Quick Trigger", text: "A fast <b>Swipe Up</b> without holding triggers the first action immediately. To switch back, <b>Swipe Down</b> on the button." }
        ];

        return {
            1: {
                title: "Navigation & Gestures",
                steps: [
                    { title: "Welcome to Conjure", text: "I am your guide. Conjure is a <i>Sovereign OS</i> designed to give you total control over your tools. Let's start with the basics." },
                    { title: "Minimizing the Guide", text: "At any point during the onboarding, you may minimize this window into a <b>blob</b> to get it out of your way. Let's try it: tap the <b>minimize button</b> in the top right to see it in action.", target: "minimize_guide" },
                    { title: "The Viewport", text: "The main screen is a horizontal slider. Try <b>Swiping Left</b> or right to switch between your Log Stream and the Dashboard." },
                    ...(isBar ? barSteps : fabSteps),
                    { title: "System Settings", text: "Everything in Conjure is a plugin. You can enable or disable features in the <b>Settings Tray</b> at the top right.", target: "header_settings" }
                ]
            },
            2: {
                title: "The Command Bar",
                steps: [
                    { title: "Action Shortcuts", text: "In the Command Bar, below the page shortcuts are action/tool shortcuts. <b>Swipe up</b> to see them now.<br><br>You can customize them in the <b>recorder settings</b>.", target: "navigation_bar" },
                    { title: "Fast Travel", text: "Press and hold on the <b>Command Bar</b> to unlock the gestures:<br><br>a) <b>Page Fast Travel</b>: Slide left and right to sweep between active pages.<br><br>b) <b>Quick Tool Selection</b>: Slide up to activate tools or trigger actions.", target: "navigation_bar" },
                    { title: "The Patcher", text: "The Patcher is your AI code editor. It applies surgical changes to the system source code.", target: "patcher" },
                    { title: "Context Exporter", text: "The Context Exporter packages your system files and project plans into a single text file for the AI to read.", target: "context_extras" },
                    { title: "Quick Save", text: "Quick Save creates an instant snapshot of your current system state, allowing you to undo mistakes safely.<br><br><strong>Long-press</strong> to give your checkpoint a name for later reference.", target: "save_snapshot" },
                    { title: "Setup", text: "The Setup button opens the settings tray, where you can configure plugins and UI preferences.", target: "settings" },
                    { title: "File Vault", text: "The File Vault is your secure storage for uploading, downloading, and managing media assets and documents.", target: "vault" },
                    { title: "Theme Mode", text: "The Theme toggle instantly switches the system between Light and Dark mode.", target: "theme" },
                    { title: "Draft Pad", text: "The Draft Pad gives you a persistent scratchpad to compose notes before saving them to the log stream.", target: "draft" }
                ]
            },
            3: {
                title: "The Brain (API)",
                steps: [
                    { title: "The AI Brain", text: "Conjure uses AI for three things: the <i>Assistant</i>, <i>Transcription</i> (dictation), and <i>AI Chat</i>. To power these, you can connect your own API keys." },
                    { title: "API Setup", text: "To add your keys, open <b>settings</b>, tap <b>Show Hidden plugins</b> at the bottom, and go to <b>AI & Transcription</b> → <b>Engines</b> → <b>OpenRouter AI</b> and <b>Conjure Core</b> (or simply search for the plugins in the <b>search bar</b>).", target: "settings" },
                    { title: "The Patching Engine", text: "While Conjure handles your code, the logic for system updates is currently generated in <i>Google AI Studio</i>. It is the current primary engine for creating new features and fixing bugs." },
                    { title: "The Development Loop", text: "The workflow is simple: Use the <b>Context Exporter</b> to grab your code, upload it to <i>Google AI Studio</i>, and ask for a change. You'll get a patch to paste back here later. We will go over the whole loop later in the onboarding.", target: "context_extras" }
                ]
            },
            4: {
                title: "The Voice",
                steps: [
                    { title: "Recording a Note", text: "Let's record your first note. Use the <b>Record Button</b> (or slide up on the Command Bar) to dictate a short message, like 'Hello Conjure'.", target: "record" },
                    { title: "Processing", text: "Once you stop recording, Conjure will transcribe your voice using the API you configured (or save the audio if no API is set)." }
                ]
            },
            5: {
                title: "The Handshake",
                steps: [
                    { title: "Interactions", text: "Conjure relies on gestures. <strong>Double-tap</strong> any note to copy its text. <strong>Triple-tap</strong> to unmark it as new." },
                    { title: "Long Press", text: "<strong>Long-press</strong> a note to enter <strong>Selection Mode</strong>. From here, you can select multiple notes and apply bulk actions." },
                    { title: "Merging Notes", text: "In Selection Mode, tap the <b>Merge</b> icon <svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='3' stroke-linecap='round' stroke-linejoin='round' style='width:14px; height:14px; vertical-align:middle; display:inline-block; margin:0 2px;'><path d='M6 5 L12 11 L18 5'></path><path d='M12 11 L12 20'></path></svg> (which looks like a <b>Y</b>) on the bottom action bar to combine multiple notes into a single cohesive entry.", target: "merge" },
                    { title: "Exiting & Exploration", text: "To finish, press the <b>back button</b> to exit Selection Mode. Feel free to explore the other buttons in the action bar! Remember: sometimes <strong>long-pressing</strong> on a screen element reveals hidden powers.", target: "back" }
                ]
            },
            6: {
                title: "The Loop (Patching)",
                steps: [
                    { title: "The Development Cycle", text: "The true power of Conjure is self-modification. The loop is simple: Export, Ask, Patch." },
                    { title: "1. Quick Context Export", text: "Open the <b>Patcher</b> to begin. First, create a safety checkpoint by tapping the <b>floppy disk</b> icon in the header. This ensures you can revert if anything goes wrong!<br><br>Next, tap the <b>Export Button</b> in the Patcher header to download the <i>foundation files</i> context, as we'll be using this context later in the guide! You can also <strong>long-press</strong> this button to reveal additional export options.", target: "patcher" },
                    { title: "2. Context Preparation", text: "Open the <b>Context Exporter</b> to prepare and manage manual context in several ways. Feel free to explore these advanced features to understand how they work, though we won't need to use them right now. Below is a quick introduction to the key options available:<br><br>There are two main methods of exporting context:<br><br>• <b>foundation</b>: Generates a lightweight snapshot of system core files and guidelines.<br>• <b>manual</b>: Combines the foundation context with any custom files you select.<br><br>a) <b>per project</b>: Automatically loads the file scope from your active project plan.<br>b) <b>per app</b>: Quickly selects all files associated with a standalone micro-app (like AtlasTrack, EduNexus, etc.).<br>c) <b>per manually saved groups</b>: Saves and reloads custom file lists for repeatable tasks.<br>d) <b>history</b>: Re-opens and restores any of your last 20 saved context states." },
                    { title: "3. Ask", text: "Upload the context to Google AI Studio or your favorite LLM, and ask it to make a specific change.<br><br><div style='display:flex; gap:8px; margin-top:10px;'><button onclick='window.open(\"https://aistudio.google.com/prompts/new_chat\", \"_blank\")' class='text-btn' style='flex:1; background:var(--primary); color:var(--primary-text); border:none; border-radius:10px; padding:10px; font-size:12px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px;'><svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' style='width:13px; height:13px;'><path d=\"M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6\"></path><polyline points=\"15 3 21 3 21 9\"></polyline><line x1=\"10\" y1=\"14\" x2=\"21\" y2=\"3\"></line></svg> Open AI Studio</button><button onclick=\"window.sui.copyText('https://aistudio.google.com/prompts/new_chat').then(() => window.sui.toast('URL Copied!'))\" class='text-btn' style='flex:1; background:var(--btn-bg); color:var(--text-primary); border:1px solid var(--border-color); border-radius:10px; padding:10px; font-size:12px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px;'><svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' style='width:13px; height:13px;'><rect x=\"9\" y=\"9\" width=\"13\" height=\"13\" rx=\"2\" ry=\"2\"></rect><path d=\"M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1\"></path></svg> Copy URL</button></div>" },
                    { title: "4. Patch", text: "The AI will give you a Protocol V10 Patch block. Copy it, open the <b>Patcher</b>, paste it, and commit the change.", target: "patcher" },
                    { title: "Graduation", text: "You are now a Conjure Architect. Welcome to Sovereign Software." }
                ]
            }
        };
    },

    updatePanelContent() {
        const body = document.querySelector('.guide-panel-body');
        if (body) body.scrollTop = 0; // Reset vertical scroll to top on every step transition

        const curriculum = this.getCurriculum();
        const m = curriculum[this.state.mission] || { title: "Keep Going!", steps: [{ text: "Follow the bold prompts." }] };
        const s = m.steps[this.state.step] || m.steps[0];
        
        document.getElementById('guide-mission-label').innerText = `Mission ${this.state.mission}.${this.state.step + 1}`;
        document.getElementById('guide-title').innerText = s.title || m.title;
        
        // Bold-to-Glow Parser: Append target/magnifier icon to actionable elements
        const content = s.text.replace(/<b>(.*?)<\/b>/g, (match, p1) => {
            return `<b onclick="Guide.glow('${p1}')" class="guide-bold-trigger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="guide-target-svg-icon"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>${p1}</b>`;
        });
        document.getElementById('guide-content').innerHTML = content;

        if (s.target) {
            this.persistentGlow(s.target);
        } else {
            this.clearPersistentGlow();
        }

        // Trigger the adaptive height check whenever text content shifts
        this.adaptHeight();

        // Auto-trigger the view peeking once when landing on Step 1.3 (index 2)
        if (this.state.mission === 1 && this.state.step === 2) {
            if (this._lastAutoTriggeredStep !== '1.3') {
                this._lastAutoTriggeredStep = '1.3';
                this.executeWhenReady(() => {
                    setTimeout(() => {
                        // Check that the user is still on this step before auto-running
                        if (this.state.mission === 1 && this.state.step === 2) {
                            this.peekViewport();
                        }
                    }, 300); // Snappy 300ms delay after loader fully fades out / step settles
                });
            }
        } else if (this.state.mission === 1 && this.state.step === 4) {
            // Auto-trigger the gesture demonstration once when landing on Step 1.5 (index 4)
            if (this._lastAutoTriggeredStep !== '1.5') {
                this._lastAutoTriggeredStep = '1.5';
                this.executeWhenReady(() => {
                    setTimeout(() => {
                        // Check that the user is still on this step before auto-running
                        if (this.state.mission === 1 && this.state.step === 4) {
                            this.showGestureDemo();
                        }
                    }, 300); // Snappy 300ms delay after loader fully fades out / step settles
                });
            }
        } else if (this.state.mission === 1 && this.state.step === 5) {
            // Auto-trigger the swipe right demonstration once when landing on Step 1.6 (index 5)
            if (this._lastAutoTriggeredStep !== '1.6') {
                this._lastAutoTriggeredStep = '1.6';
                this.executeWhenReady(() => {
                    setTimeout(() => {
                        // Check that the user is still on this step and in fcb-mode before auto-running
                        if (this.state.mission === 1 && this.state.step === 5 && document.body.classList.contains('fcb-mode')) {
                            this.showSwipeDemo();
                        }
                    }, 300); // Snappy 300ms delay after loader fully fades out / step settles
                });
            }
        } else if (this.state.mission === 2 && this.state.step === 0) {
            // Auto-trigger the swipe up actions toggle demonstration once when landing on Step 2.1 (index 0)
            if (this._lastAutoTriggeredStep !== '2.1') {
                this._lastAutoTriggeredStep = '2.1';
                this.executeWhenReady(() => {
                    setTimeout(() => {
                        // Check that the user is still on this step before auto-running
                        if (this.state.mission === 2 && this.state.step === 0) {
                            this.showToggleActionsDemo();
                        }
                    }, 300); // Snappy 300ms delay after loader fully fades out / step settles
                });
            }
        } else {
            this._lastAutoTriggeredStep = null;
        }
    },

    activeGlowTarget: null,
    activeGlowTargetId: null, 
    isPersistentGlow: false,
    isTracking: false,
    trackingTransitionTimeout: null,

    resolveTarget(id) {
        if (id === 'record') {
            const isBar = document.body.classList.contains('fcb-mode');
            const isOmni = document.body.classList.contains('fcb-omni');
            return isBar ? (isOmni ? document.getElementById('fcb-btn-omni') : document.getElementById('fcb-btn-record')) : document.getElementById('fab-record');
        }
        if (id === 'back') {
    const isBar = document.body.classList.contains('fcb-mode');
    const isOmni = document.body.classList.contains('fcb-omni');
    if (isBar) return isOmni ? document.getElementById('fcb-btn-omni') : document.getElementById('fcb-btn-back');
    return document.getElementById('fab-record');
}
if (id === 'merge') return document.getElementById('action-merge');
if (id === 'minimize_guide') return document.getElementById('guide-minimize-btn');
if (id === 'navigation_bar') return document.getElementById('fcb-container');
if (id === 'export_button') return document.getElementById('cp-studio-export-btn') || document.getElementById('cp-tray-export-btn');
if (id === 'header_settings') {
    return document.getElementById('settings-btn') || 
           document.querySelector('.btn-header-action[onclick*="openSettings"]') || 
           document.querySelector('.settings-trigger') ||
           Array.from(document.querySelectorAll('button')).find(b => b.onclick && b.onclick.toString().includes('openSettings'));
}
return document.querySelector(`[data-action-id="${id}"]`);
        },setGlowTarget(elOrId, isPersistent) {
    const portal = document.getElementById('guide-glow-portal');
    if (!portal) return;

    const el = (typeof elOrId === 'string') ? this.resolveTarget(elOrId) : elOrId;
    const id = (typeof elOrId === 'string') ? elOrId : null;

    if (this.activeGlowTarget !== el || this.activeGlowTargetId !== id) {
        portal.classList.remove('tracking-active');
        clearTimeout(this.trackingTransitionTimeout);
        this.trackingTransitionTimeout = setTimeout(() => {
            portal.classList.add('tracking-active'); 
        }, 500); 
    }

    this.activeGlowTarget = el;
    this.activeGlowTargetId = id;
    this.isPersistentGlow = isPersistent;

    // Centralized Obstacle Avoidance: Ensure panel shifts out of the highlighted target's way
    if (el && id !== 'minimize_guide') {
        this.makeWay(el);
    }

    portal.classList.remove('persistent-active', 'active', 'target-hidden');
    void portal.offsetWidth; 
    portal.classList.add(isPersistent ? 'persistent-active' : 'active');

    if (!this.isTracking) {
        this.trackGlow();
    }
},

    clearGlowTarget() {
        this.activeGlowTarget = null;
        this.activeGlowTargetId = null;
        const portal = document.getElementById('guide-glow-portal');
        if (portal) {
            portal.classList.remove('persistent-active', 'active', 'target-hidden', 'tracking-active');
        }
    },

    trackGlow() {
        if (document.hidden) {
            this.isTracking = false;
            return; // Battery-Friendly: Halt recursive rAF when tab is backgrounded
        }

        if (!this.activeGlowTarget && !this.activeGlowTargetId) {
            this.isTracking = false;
            return;
        }
        this.isTracking = true;

        const portal = document.getElementById('guide-glow-portal');
        let el = this.activeGlowTarget;
        
        if (this.activeGlowTargetId) {
            el = this.resolveTarget(this.activeGlowTargetId);
        }

        if (portal && el && document.body.contains(el)) {
            const rect = el.getBoundingClientRect();
            const padding = 8;
            
            const style = window.getComputedStyle(el);
            const isVisible = rect.width > 0 && rect.height > 0 && style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
            const isOffScreen = rect.right < 0 || rect.left > window.innerWidth || rect.bottom < 0 || rect.top > window.innerHeight;

            let isClipped = false;
            const strip = el.closest('.fcb-strip');
            if (strip) {
                const stripRect = strip.getBoundingClientRect();
                const fcb = document.getElementById('fcb-container');
                
                if (rect.right < stripRect.left || rect.left > stripRect.right || 
                    rect.bottom < stripRect.top || rect.top > stripRect.bottom) {
                    isClipped = true;
                }

                if (fcb) {
                    const isActionsStrip = strip.id === 'fcb-strip-actions';
                    const showingActions = fcb.classList.contains('show-actions');
                    if (isActionsStrip !== showingActions) isClipped = true;
                }
            }

            if (isVisible && !isOffScreen && !isClipped) {
                portal.classList.remove('target-hidden');
                portal.style.width = (rect.width + (padding * 2)) + 'px';
                portal.style.height = (rect.height + (padding * 2)) + 'px';
                portal.style.left = (rect.left - padding) + 'px';
                portal.style.top = (rect.top - padding) + 'px';

                // Dynamically match and scale the target's border-radius for organic highlight wrapping
                const radiusStr = style.borderRadius;
                if (radiusStr.includes('%')) {
                    portal.style.borderRadius = radiusStr;
                } else {
                    const targetRadius = parseFloat(radiusStr);
                    if (!isNaN(targetRadius) && targetRadius > 0) {
                        portal.style.borderRadius = (targetRadius + padding) + 'px';
                    } else if (targetRadius === 0) {
                        portal.style.borderRadius = '0px';
                    } else {
                        portal.style.borderRadius = '14px'; // Default fallback
                    }
                }
            } else {
                portal.classList.add('target-hidden');
            }
        } else {
            if (portal) portal.classList.add('target-hidden');
        }

        requestAnimationFrame(() => this.trackGlow());
    },

    persistentGlow(targetId) {
        clearTimeout(this.glowTimeout);
        clearTimeout(this.scrollTimeout);

        const el = this.resolveTarget(targetId);
        
        if (el && targetId !== 'record' && targetId !== 'merge') {
            el.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            this.scrollTimeout = setTimeout(() => {
                this.setGlowTarget(targetId, true);
            }, 300);
        } else {
            this.setGlowTarget(targetId, true);
        }
    },

    clearPersistentGlow() {
        if (this.isPersistentGlow) this.clearGlowTarget();
    },

    glow(targetName) {
        window.sui.haptic('medium');
        clearTimeout(this.glowTimeout);
        
        const name = targetName.toLowerCase();

        // --- DIRECT ACTION TRIGGER ---
        if (name.includes('context exporter') || name.includes('context explorer') || name.includes('per project') || name.includes('per app') || name.includes('manually saved groups') || name === 'history') { 
            const studio = document.getElementById('sui-studio-ce-manual-extras');
            const isStudioOpen = studio && (studio.classList.contains('visible') || studio.style.visibility === 'visible');
            if (!isStudioOpen && window.ceOpenManualExtrasStudio) {
                ceOpenManualExtrasStudio();
            }
        }
        else if (name.includes('foundation files') || name.includes('context sources') || name.includes('appmaker apps') || name.includes('context groups') || name.includes('project files')) { 
            const studio = document.getElementById('sui-studio-ce-manual-extras');
            const isStudioOpen = studio && (studio.classList.contains('visible') || studio.style.visibility === 'visible');
            if (!isStudioOpen && window.ceOpenManualExtrasStudio) {
                ceOpenManualExtrasStudio();
            }
        }
        else if (name.includes('patcher')) { if (window.cpOpenStudio) cpOpenStudio(); }
        else if (name.includes('back button')) { if (window.frHandleBackAction) frHandleBackAction(); }
        else if (name.includes('file vault') || name.includes('vault')) { if (window.fvOpenStudio) fvOpenStudio(); }
        else if (name.includes('draft pad')) { 
            if (window.setDraftPadState) { 
                localStorage.setItem("cjos_draft_pad_open", "true"); 
                setDraftPadState(true); 
            } 
        }
        else if (name.includes('quick save')) { if (window.elTriggerCheckpointAction) elTriggerCheckpointAction('save'); }
        else if (name.includes('theme')) {
            if (typeof tpToggleMode === 'function') {
                const isDark = (typeof tpState !== 'undefined' && tpState.mode === 'dark');
                tpToggleMode(!isDark);
            }
        }

        // Special Case: Viewport Peek
        if (name.includes('swiping')) { setTimeout(() => this.peekViewport(), 500); return; }
        if (name.includes('slide up')) { setTimeout(() => this.showGestureDemo(), 500); return; }
        if (name.includes('swipe right')) { setTimeout(() => this.showSwipeDemo(), 500); return; }
        if (name.includes('minimize button') || name.includes('minimize') || name.includes('blob')) {
            setTimeout(() => this.showMinimizeDemo(), 500);
            return;
        }
        if (name.includes('swipe up')) {
            setTimeout(() => this.showToggleActionsDemo(), 500);
            return;
        }
        if (name.includes('page fast travel') || name.includes('fast travel')) {
            setTimeout(() => this.showPageFastTravelDemo(), 500);
            return;
        }
        if (name.includes('quick tool selection') || name.includes('tool selection')) {
            setTimeout(() => this.showQuickToolSelectionDemo(), 500);
            return;
        }

        setTimeout(() => {
            let el = null;
            let expansionDelay = 0;

            // Auto-open parent accordions if project files are requested
            if (name.includes('project files') || name.includes('per project') || name === 'project') {
                const acc = document.getElementById('sui-fs-sources-acc-ce-manual-extras');
                if (acc && !acc.classList.contains('open')) {
                    suiToggle('sui-fs-sources-acc-ce-manual-extras');
                    expansionDelay = 400; // Delay to allow accordion slide-open animation
                }
                const projAcc = document.getElementById('ce-fs-proj-acc-ce-manual-extras');
                if (projAcc && !projAcc.classList.contains('open')) {
                    suiToggle('ce-fs-proj-acc-ce-manual-extras');
                    expansionDelay = 400; // Delay to allow accordion slide-open animation
                }
            }

            if (name.includes('command bar')) el = document.getElementById('fcb-container');
            if (name.includes('quick action menu') || name.includes('tiered actions')) el = document.getElementById('fr-action-menu');
            
            if (name.includes('context exporter') || name.includes('context explorer')) el = this.resolveTarget('context_extras');
            else if (name.includes('patcher')) el = this.resolveTarget('patcher');
            else if (name.includes('export button') || name === 'export') el = document.getElementById('cp-studio-export-btn') || document.getElementById('cp-tray-export-btn');
            else if (name.includes('floppy disk') || name === 'save' || name.includes('save button')) el = document.getElementById('cp-studio-save-btn') || document.getElementById('cp-tray-save-btn');
            
            else if (name.includes('foundation files')) el = document.getElementById('ce-fs-found-acc-ce-manual-extras');
            else if (name === 'foundation') el = document.getElementById('sui-fs-act-ce-manual-extras-0');
            else if (name === 'manual') el = document.getElementById('sui-fs-act-ce-manual-extras-1');
            else if (name.includes('context sources')) el = document.getElementById('sui-fs-sources-acc-ce-manual-extras');
            else if (name.includes('search') && !name.includes('search bar')) el = document.querySelector('#sui-studio-ce-manual-extras .sui-fs-search-input');
            else if (name.includes('appmaker apps') || name.includes('per app') || name === 'app') el = document.getElementById('sui-fs-act-ce-manual-extras-2');
            else if (name.includes('context groups') || name.includes('manually saved groups') || name === 'groups') el = document.getElementById('sui-fs-act-ce-manual-extras-3');
            else if (name.includes('project files') || name.includes('per project') || name === 'project') el = document.getElementById('ce-fs-proj-acc-ce-manual-extras');
            else if (name === 'history') el = document.getElementById('sui-fs-history-ce-manual-extras');
            else if (name.includes('file vault') || name.includes('vault')) el = this.resolveTarget('vault');
            else if (name.includes('quick save')) el = this.resolveTarget('save_snapshot');
            else if (name.includes('draft pad')) el = this.resolveTarget('draft');
            else if (name.includes('theme')) el = this.resolveTarget('theme');
            
            else if (name === 'settings' || name === 'setup') {
                el = document.body.classList.contains('fcb-mode')
                    ? document.querySelector('[data-action-id="settings"]')
                    : (document.getElementById('settings-btn') || document.querySelector('.btn-header-action[onclick*="openSettings"]'));
            }
            else if (name === 'settings tray' || name === 'settings-btn' || name === 'settings btn') {
                el = document.getElementById('settings-btn') || 
                     document.querySelector('.btn-header-action[onclick*="openSettings"]') || 
                     document.querySelector('.settings-trigger') ||
                     Array.from(document.querySelectorAll('button')).find(b => b.onclick && b.onclick.toString().includes('openSettings'));
            }
            else if (name.includes('hidden plugins')) {
                el = document.getElementById('hidden-section-divider');
            }
            else if (name.includes('search bar')) {
                el = document.querySelector('#po-tools-header input') || document.querySelector('#hidden-plugins-container input[type="text"]');
            }
            else if (name.includes('ai & transcription') || name.includes('ai and transcription')) {
                const spans = Array.from(document.querySelectorAll('.po-folder-header span'));
                const matchedSpan = spans.find(span => {
                    const txt = span.textContent.trim().toLowerCase();
                    return txt.includes('ai & transcription') || txt.includes('ai and transcription');
                });
                el = matchedSpan ? matchedSpan.closest('.po-folder') : null;
            }
            else if (name.includes('engines')) {
                const spans = Array.from(document.querySelectorAll('.po-folder-header span'));
                const matchedSpan = spans.find(span => span.textContent.trim().toLowerCase() === 'engines');
                el = matchedSpan ? matchedSpan.closest('.po-folder') : null;
            }
            else if (name.includes('openrouter ai') || name.includes('openrouter')) {
                el = document.getElementById('plg-row-OpenRouterAI');
            }
            else if (name.includes('conjure core') || name.includes('conjurecore')) {
                el = document.getElementById('plg-row-ConjureCore');
            }
            
            if (name.includes('switch toggle')) el = document.getElementById('fcb-btn-switch');
            if (name.includes('recorder settings')) el = document.getElementById('fcb-btn-settings');
            if (name.includes('merge') || name === 'y') el = document.getElementById('action-merge');

            if (el && (el.id === 'fcb-btn-switch' || el.id === 'fcb-btn-settings')) {
                const left = document.getElementById('fcb-left');
                if (left && !left.classList.contains('show-hidden')) {
                    left.classList.add('show-hidden');
                    window.sui.haptic('light');
                    expansionDelay = 500;
                }
            }

            setTimeout(() => {
                if (name.includes('record button') || name.includes('omni button')) {
                    const isBar = document.body.classList.contains('fcb-mode');
                    const isOmni = document.body.classList.contains('fcb-omni');
                    el = isBar ? (isOmni ? document.getElementById('fcb-btn-omni') : document.getElementById('fcb-btn-record')) : document.getElementById('fab-record');
                }
                
                if (name.includes('settings tray')) {
                    el = document.querySelector('.btn-header-action[onclick*="openSettings"]') || 
                         document.querySelector('.settings-trigger') ||
                         Array.from(document.querySelectorAll('button')).find(b => b.onclick && b.onclick.toString().includes('openSettings'));
                }

                if (name.includes('navigation bar')) {
                    el = document.getElementById('fcb-container') || document.querySelector('.action-bar');
                }

                if (!el) {
                    el = Array.from(document.querySelectorAll('button, span, div, label, .nav-item'))
                        .find(node => node.textContent.trim().toLowerCase() === name);
                }

                if (el) {
                    // 1. Smoothly scroll the element into view first
                    if (typeof el.scrollIntoView === 'function') {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
                    }
                    
                    // 2. Wait for the scroll animation to complete (350ms) before measuring and moving
                    setTimeout(() => {
                        // 3. Assess the post-scroll resting position of the element and shift the panel to the opposite half if obstructed
                        this.makeWay(el);
                        
                        // 4. Position and pulse the Portal Glow highlight
                        this.setGlowTarget(el, false);
                        this.glowTimeout = setTimeout(() => {
                            if (!this.isPersistentGlow) this.clearGlowTarget();
                        }, 3600);
                    }, 350);
                } else if (name.includes('merge') || name === 'y') {
                    window.sui.toast("Enter Selection Mode (long-press a note) to see the Merge tool.");
                } else {
                    window.sui.toast(`Target "${targetName}" not found.`);
                }
            }, expansionDelay);
        }, 500);
    },

    peekViewport() {
        this.cleanupGestureDemo();
        const viewport = document.querySelector('.horizontal-viewport');
        if (!viewport) return;

        this.gestureState.active = true;
        this.gestureState.btn = viewport;
        
        const startX = window.innerWidth / 2 + 50;
        const startY = window.innerHeight / 2;
        
        this.gestureState.startX = startX;
        this.gestureState.startY = startY;

        const addTimeout = (fn, delay) => {
            const t = setTimeout(fn, delay);
            this.gestureState.timeouts.push(t);
            return t;
        };

        this.showTouchPointer(startX, startY);
        viewport.style.scrollSnapType = 'none';

        const duration = 1200; // 1.2s per peek
        let peekCount = 0;
        
        const runPeek = () => {
            if (!this.gestureState.active) return;
            const startTime = performance.now();
            window.sui.haptic('light');
            
            const animate = (time) => {
                if (!this.gestureState.active) return;
                let elapsed = time - startTime;
                if (elapsed > duration) elapsed = duration;
                
                // Sine wave: 0 -> 1 -> 0
                const progress = (elapsed / duration) * Math.PI;
                const floatIndex = Math.sin(progress);
                
                this.moveTouchPointer(startX - (floatIndex * 120), startY);
                viewport.scrollLeft = floatIndex * 100;
                
                if (elapsed < duration) {
                    this.gestureState.rafs.push(requestAnimationFrame(animate));
                } else {
                    peekCount++;
                    if (peekCount < 3) {
                        addTimeout(runPeek, 400);
                    } else {
                        this.hideTouchPointer();
                        viewport.style.scrollSnapType = 'x mandatory';
                        this.gestureState.active = false;
                    }
                }
            };
            this.gestureState.rafs.push(requestAnimationFrame(animate));
        };
        
        addTimeout(runPeek, 600);
    },

    showSwipeDemo() {
    this.cleanupGestureDemo();
    const leftSection = document.getElementById('fcb-left');
    if (!leftSection) return;

    this.gestureState.active = true;
    this.gestureState.btn = leftSection;
            
    const rect = leftSection.getBoundingClientRect();
    const startX = rect.left + 25;
    const startY = rect.top + rect.height / 2;
            
    this.gestureState.startX = startX;
    this.gestureState.startY = startY;

    const addTimeout = (fn, delay) => {
        const t = setTimeout(fn, delay);
        this.gestureState.timeouts.push(t);
        return t;
    };

    this.showTouchPointer(startX, startY);

    const duration = 800; // 0.8s per swipe
    let swipeCount = 0;
            
    const runSwipe = () => {
        if (!this.gestureState.active) return;
        const startTime = performance.now();
        let toggled = false;
                
        // Alternate direction: swipe right (+1), then swipe left (-1)
        const direction = (swipeCount % 2 === 0) ? 1 : -1;
                
        const animate = (time) => {
            if (!this.gestureState.active) return;
            let elapsed = time - startTime;
            if (elapsed > duration) elapsed = duration;
                    
            // Sine wave: 0 -> 1 -> 0
            const progress = (elapsed / duration) * Math.PI;
            const floatIndex = Math.sin(progress);
                    
            this.moveTouchPointer(startX + (floatIndex * 60 * direction), startY);
                    
            // Toggle when we reach the peak of the swipe
            if (floatIndex > 0.8 && !toggled) {
                toggled = true;
                leftSection.classList.toggle('show-hidden');
                window.sui.haptic('light');
            }
                    
            if (elapsed < duration) {
                this.gestureState.rafs.push(requestAnimationFrame(animate));
            } else {
                swipeCount++;
                if (swipeCount < 6) { // 6 swipes = 3 reveals, 3 hides
                    addTimeout(runSwipe, 400);
                } else {
                    this.hideTouchPointer();
                    leftSection.classList.remove('show-hidden');
                    this.gestureState.active = false;
                }
            }
        };
        this.gestureState.rafs.push(requestAnimationFrame(animate));
    };
            
    addTimeout(runSwipe, 600);
},showGestureDemo() {// If already running, clean up first
    this.cleanupGestureDemo();

    const btn = this.resolveTarget('record');
    const backZone = document.getElementById('fr-back-zone-rec');
    if (!btn || !backZone) return;

    const rect = btn.getBoundingClientRect();
    const startX = rect.left + rect.width / 2;
    const startY = rect.top + rect.height / 2;

    this.gestureState.active = true;
    this.gestureState.btn = btn;
    this.gestureState.startX = startX;
    this.gestureState.startY = startY;

    // Helper to push timeouts/intervals for global tracking
    const addTimeout = (fn, delay) => {
        const t = setTimeout(fn, delay);
        this.gestureState.timeouts.push(t);
        return t;
    };
    const addInterval = (fn, delay) => {
        const i = setInterval(fn, delay);
        this.gestureState.intervals.push(i);
        return i;
    };

    // 1. Highlight the button itself and simulate pointerdown
window.sui.haptic('light');
this.setGlowTarget(btn, false);
this.showTouchPointer(startX, startY);

const downEvent = new PointerEvent('pointerdown', {
    pointerId: 1, bubbles: true, cancelable: true, pointerType: 'touch',
    clientX: startX, clientY: startY, button: 0
});
btn.dispatchEvent(downEvent);

// 2. Wait 1 second (press & hold)
addTimeout(() => {
    this.setGlowTarget(backZone, false);

    const settings = typeof window.frGetSettings === 'function' ? window.frGetSettings() : {};
    const dist = settings.back_gesture_dist || 160;
    const endY = startY - dist;

    // 3. Smooth slide up (approx. 250ms over 15 steps)
    const steps = 15;
    const stepY = (startY - endY) / steps;
    let currentY = startY;
    let currentStep = 0;

    const slideUpInterval = addInterval(() => {
        currentY -= stepY;
        this.moveTouchPointer(startX, currentY);
        const moveEvent = new PointerEvent('pointermove', {
            pointerId: 1, bubbles: true, cancelable: true, pointerType: 'touch',
            clientX: startX, clientY: currentY
        });
        window.dispatchEvent(moveEvent);

        currentStep++;
        if (currentStep >= steps) {
            clearInterval(slideUpInterval);
            this.gestureState.intervals = this.gestureState.intervals.filter(i => i !== slideUpInterval);
                        
            // 4. Slide-up complete. Keep highlighted at the record zone for 2 seconds.
            addTimeout(() => {
                // 5. Smooth slide back down to disengage safely without triggering a recording
                let currentDownStep = 0;
                let currentDownY = endY;

                const slideDownInterval = addInterval(() => {
                    currentDownY += stepY;
                    this.moveTouchPointer(startX, currentDownY);
                    const moveEvent = new PointerEvent('pointermove', {
                        pointerId: 1, bubbles: true, cancelable: true, pointerType: 'touch',
                        clientX: startX, clientY: currentDownY
                    });
                    window.dispatchEvent(moveEvent);

                    currentDownStep++;
                    if (currentDownStep >= steps) {
                        clearInterval(slideDownInterval);
                        this.gestureState.intervals = this.gestureState.intervals.filter(i => i !== slideDownInterval);

                        // 6. Dispatch pointerup at the starting coordinates (safe release outside active zone)
                        const upEvent = new PointerEvent('pointerup', {
                            pointerId: 1, bubbles: true, cancelable: true, pointerType: 'touch',
                            clientX: startX, clientY: startY
                        });
                        btn.dispatchEvent(upEvent);

                        this.clearGlowTarget();
                        this.hideTouchPointer();
                        this.gestureState.active = false;
                    }
                }, 16);
            }, 2000);
        }
    }, 16);
}, 1000);},

showTouchPointer(x, y) {
    const tp = document.getElementById('guide-touch-pointer');
    if (!tp) return;
    tp.style.left = x + 'px';
    tp.style.top = y + 'px';
    tp.classList.remove('moving');
    tp.classList.add('active');
    setTimeout(() => tp.classList.add('moving'), 250);
},

moveTouchPointer(x, y) {
    const tp = document.getElementById('guide-touch-pointer');
    if (!tp) return;
    tp.style.left = x + 'px';
    tp.style.top = y + 'px';
},

hideTouchPointer() {
    const tp = document.getElementById('guide-touch-pointer');
    if (!tp) return;
    tp.classList.remove('active', 'moving');
},

cleanupGestureDemo() {
    this.hideTouchPointer();
    // 1. Clear all active timers
    this.gestureState.timeouts.forEach(clearTimeout);
    this.gestureState.intervals.forEach(clearInterval);
    if (this.gestureState.rafs) this.gestureState.rafs.forEach(cancelAnimationFrame);
    this.gestureState.timeouts = [];
    this.gestureState.intervals = [];
    this.gestureState.rafs = [];

    const btn = this.gestureState.btn;
    const wasActive = this.gestureState.active;

    // 2. If gesture was active, perform a safe, instant, non-recording release
    if (wasActive && btn) {
        const startX = this.gestureState.startX;
        const startY = this.gestureState.startY;

        // First move the pointer back to the bottom (safe) coordinates instantly
        const moveEvent = new PointerEvent('pointermove', {
            pointerId: 1, bubbles: true, cancelable: true, pointerType: 'touch',
            clientX: startX, clientY: startY
        });
        window.dispatchEvent(moveEvent);

        // Now dispatch pointerup safely outside the record trigger zone
        const upEvent = new PointerEvent('pointerup', {
            pointerId: 1, bubbles: true, cancelable: true, pointerType: 'touch',
            clientX: startX, clientY: startY
        });
        btn.dispatchEvent(upEvent);

        this.clearGlowTarget();
    }

    // Close the revealed utility area on the Command Bar
    const leftSection = document.getElementById('fcb-left');
    if (leftSection) {
        leftSection.classList.remove('show-hidden');
    }

    // Close the Actions panel on the Command Bar
    const fcb = document.getElementById('fcb-container');
    if (fcb) {
        if (this.state.mission === 2 && this.state.step === 0) {
            fcb.classList.remove('show-actions');
        }
        fcb.classList.remove('fast-travel-active');
    }

    const dial = document.getElementById('fcb-dial-overlay');
    if (dial) dial.classList.remove('active');

    const actionsStrip = document.getElementById('fcb-strip-actions');
    if (actionsStrip) {
        actionsStrip.querySelectorAll('.fcb-strip-item.active').forEach(el => el.classList.remove('active'));
    }

    const viewport = document.querySelector('.horizontal-viewport');
    if (viewport && wasActive && (btn === fcb || btn === viewport)) {
        viewport.style.scrollSnapType = 'x mandatory';
        viewport.scrollTo({ left: 0, behavior: 'auto' });
    }

    this.gestureState.active = false;
    this.gestureState.btn = null;
},executeWhenReady(callback) {const check = () => {
        // Execute once the Hydration Guard is dismissed (or not active in DOM)
        const isDismissed = document.body.classList.contains('hg-dismissed') || !document.getElementById('hg-shield');
        if (isDismissed) {
            callback();
        } else {
            setTimeout(check, 100);
        }
    };
    check();
},

showMinimizeDemo() {
    const minBtn = document.getElementById('guide-minimize-btn');
    const blob = document.getElementById('guide-blob');
    const panelOverlay = document.getElementById('guide-panel-overlay');
    if (!minBtn || !blob || !panelOverlay) return;

    // 1. Highlight the minimize button
    window.sui.haptic('light');
    this.setGlowTarget(minBtn, false);

    // 2. Simulate minimization after delay
    setTimeout(() => {
        this.clearGlowTarget();
        window.sui.haptic('medium');
        this.shrink();

        // 3. Highlight the blob (representing minimized guide)
        setTimeout(() => {
            this.setGlowTarget(blob, false);

            // 4. Simulate a click on the blob to restore
            setTimeout(() => {
                this.clearGlowTarget();
                window.sui.haptic('success');
                this.expand();
            }, 1500);
        }, 600);
    }, 1500);
},

showPageFastTravelDemo() {
    this.cleanupGestureDemo();
    const container = document.getElementById('fcb-container');
    const dial = document.getElementById('fcb-dial-overlay');
    if (!container || !dial) return;

    window.sui.haptic('light');
    this.setGlowTarget(container, false);

    this.gestureState.active = true;
    this.gestureState.btn = container;

    const addTimeout = (fn, delay) => {
        const t = setTimeout(fn, delay);
        this.gestureState.timeouts.push(t);
        return t;
    };
    const addInterval = (fn, delay) => {
        const i = setInterval(fn, delay);
        this.gestureState.intervals.push(i);
        return i;
    };

    const rect = container.getBoundingClientRect();
const startX = rect.left + rect.width / 2;
const startY = rect.top + rect.height / 2;

addTimeout(() => {
    // 1. Press and Hold
    container.classList.add('fast-travel-active');
    window.sui.haptic('medium');
    this.showTouchPointer(startX, startY);

    const pages = document.querySelectorAll(".horizontal-viewport > .page-view:not(.dash-dynamic-portal)");
    const maxIndex = Math.max(1, pages.length - 1);

    if (typeof window.fcbPopulateDial === 'function') {
        window.fcbPopulateDial(0);
        dial.classList.add('active');
        window.fcbUpdateDial(startY, 0, maxIndex);
    }

    addTimeout(() => {
        // 2. Slide left and right with perfectly smooth rAF and Sine easing
        const viewport = document.querySelector('.horizontal-viewport');
        const pageWidth = viewport ? viewport.clientWidth : 0;

        if (viewport) viewport.style.scrollSnapType = 'none';

        const duration = 2800; // 2.8 seconds
        const startTime = performance.now();

        const animate = (time) => {
            if (!this.gestureState.active) return;
                        
            let elapsed = time - startTime;
            if (elapsed > duration) elapsed = duration;
                        
            // Sine wave easing: 0 -> 1 -> 0 over the duration
            const progress = (elapsed / duration) * Math.PI;
            const floatIndex = Math.sin(progress) * Math.min(2.1, maxIndex);

            this.moveTouchPointer(startX - (floatIndex * 80), startY);

            if (typeof window.fcbUpdateDial === 'function') {
                window.fcbUpdateDial(startY, floatIndex, maxIndex);
            }

            if (viewport && pageWidth) {
                viewport.scrollLeft = floatIndex * pageWidth;
            }

            if (elapsed < duration) {
                this.gestureState.rafs.push(requestAnimationFrame(animate));
            } else {
                addTimeout(() => {
                    // 3. Release
                    container.classList.remove('fast-travel-active');
                    dial.classList.remove('active');
                    this.clearGlowTarget();
                    this.hideTouchPointer();
                    this.gestureState.active = false;
                                
                    if (viewport) {
                        viewport.style.scrollSnapType = 'x mandatory';
                        viewport.scrollTo({ left: 0, behavior: 'smooth' });
                    }
                }, 400);
            }
        };
        this.gestureState.rafs.push(requestAnimationFrame(animate));
    }, 1000); // 1-second pause to let user spot the sticky dial
}, 800);},showQuickToolSelectionDemo() {
    this.cleanupGestureDemo();
    const container = document.getElementById('fcb-container');
    const dial = document.getElementById('fcb-dial-overlay');
    if (!container || !dial) return;

    window.sui.haptic('light');
    this.setGlowTarget(container, false);

    this.gestureState.active = true;
    this.gestureState.btn = container;

    const addTimeout = (fn, delay) => {
        const t = setTimeout(fn, delay);
        this.gestureState.timeouts.push(t);
        return t;
    };

    const rect = container.getBoundingClientRect();
const startX = rect.left + rect.width / 2;
const startY = rect.top + rect.height / 2;

addTimeout(() => {
    // 1. Press and Hold (Pages Mode initially)
    container.classList.add('fast-travel-active');
    window.sui.haptic('medium');
    this.showTouchPointer(startX, startY);

    const pages = document.querySelectorAll(".horizontal-viewport > .page-view:not(.dash-dynamic-portal)");
    const maxPageIndex = Math.max(1, pages.length - 1);
                
    const actions = document.querySelectorAll('#fcb-strip-actions .fcb-strip-item');
    const maxActionIndex = Math.max(1, actions.length - 1);

    if (typeof window.fcbPopulateDial === 'function') {
        window.fcbPopulateDial(0);
        dial.classList.add('active');
        window.fcbUpdateDial(startY, 0, maxPageIndex);
    }

    addTimeout(() => {
        // 2. Slide Up to reveal actions
const slideUpDuration = 400;
const slideUpStart = performance.now();
let switched = false;

const animateSlideUp = (time) => {
    if (!this.gestureState.active) return;
                        
    let elapsed = time - slideUpStart;
    if (elapsed > slideUpDuration) elapsed = slideUpDuration;
                        
    // Ease out cubic
    const t = elapsed / slideUpDuration;
    const ease = 1 - Math.pow(1 - t, 3);
    const currentY = startY - (110 * ease); // Increased slide height by 50px

    this.moveTouchPointer(startX, currentY);

    if (currentY <= startY - 40 && !switched) {
        switched = true;
        container.classList.add('show-actions');
        window.sui.haptic('medium');
        if (typeof window.fcbPopulateDial === 'function') {
            window.fcbPopulateDial(1);
        }
    }

    if (typeof window.fcbUpdateDial === 'function') {
        window.fcbUpdateDial(currentY, 0, switched ? maxActionIndex : maxPageIndex);
    }

    if (elapsed < slideUpDuration) {
        this.gestureState.rafs.push(requestAnimationFrame(animateSlideUp));
    } else {
        addTimeout(() => {
            // 3. Slide left and right to select tools smoothly with rAF
            const duration = 2800; // 2.8 seconds
            const startTime = performance.now();

            const animateScrub = (time) => {
                if (!this.gestureState.active) return;
                                    
                let elapsedScrub = time - startTime;
                if (elapsedScrub > duration) elapsedScrub = duration;
                                    
                // Sine wave easing: 0 -> 1 -> 0 over the duration
                const progress = (elapsedScrub / duration) * Math.PI;
                const floatIndex = Math.sin(progress) * Math.min(3.1, maxActionIndex);

                // Maintain the new 110px peak height during the horizontal scrub
                this.moveTouchPointer(startX - (floatIndex * 60), startY - 110);

                if (typeof window.fcbUpdateDial === 'function') {
                    window.fcbUpdateDial(startY - 110, floatIndex, maxActionIndex);
                }const actionsStrip = document.getElementById('fcb-strip-actions');
                        if (actionsStrip) {
                            const items = actionsStrip.querySelectorAll('.fcb-strip-item');
                            const activeIdx = Math.round(floatIndex);
                            items.forEach((item, idx) => {
                                if (idx === activeIdx) item.classList.add('active');
                                else item.classList.remove('active');
                            });
                        }

                        if (elapsedScrub < duration) {
                            this.gestureState.rafs.push(requestAnimationFrame(animateScrub));
                        } else {
                            addTimeout(() => {
                                // 4. Release
                                container.classList.remove('fast-travel-active');
                                container.classList.remove('show-actions');
                                dial.classList.remove('active');
                                this.clearGlowTarget();
                                this.hideTouchPointer();
                                this.gestureState.active = false;
                                            
                                const actionsStrip = document.getElementById('fcb-strip-actions');
                                if (actionsStrip) {
                                    actionsStrip.querySelectorAll('.fcb-strip-item.active').forEach(el => el.classList.remove('active'));
                                }
                            }, 400);
                        }
                    };
                    this.gestureState.rafs.push(requestAnimationFrame(animateScrub));
                }, 1000); // 1-second pause after slide up
            }
        };
        this.gestureState.rafs.push(requestAnimationFrame(animateSlideUp));
    }, 1000); // 1-second pause after press and hold
}, 800);},showToggleActionsDemo() {
    this.cleanupGestureDemo();

    const fcb = document.getElementById('fcb-container');if (!fcb) return;

    let count = 0;
    const interval = setInterval(() => {
        const isShowing = fcb.classList.toggle('show-actions');
        if (isShowing) {
            const actionsStrip = document.getElementById('fcb-strip-actions');
            if (actionsStrip) actionsStrip.scrollLeft = 0;
            window.sui.haptic('light');
        } else {
            window.sui.haptic('light');
        }

        count++;
        if (count >= 6) { // 6 toggles = 3 full up/down cycles
            clearInterval(interval);
            this.gestureState.intervals = this.gestureState.intervals.filter(i => i !== interval);
            fcb.classList.remove('show-actions');
        }
    }, 800);

    this.gestureState.intervals.push(interval);
},

updateVisibility() {const blob = document.getElementById('guide-blob');
        const btn = document.getElementById('guide-toggle-btn');
        const overlay = document.getElementById('guide-panel-overlay');
        if (!blob) return;
        
        const isPanelVisible = overlay && overlay.classList.contains('visible');
        const isVisible = this.state.enabled && !this.state.completed && !isPanelVisible;
        blob.classList.toggle('visible', isVisible);
        if (btn) btn.innerText = (this.state.enabled && !this.state.completed) ? "Hide Guide" : "Show Guide";
    },

    toggleVisibility() {
        this.state.enabled = !this.state.enabled;
        this.updateVisibility();
        window.sui.api('onboarding_save_state', { state: this.state }, { toast: false });
    },

    forceStart() {
        localStorage.removeItem('cjos_guide_is_resized');
        localStorage.removeItem('cjos_guide_panel_rect');
        this.state = { enabled: true, mission: 1, step: 0, completed: false };
        this.render();
        this.updateVisibility();
        this.expand();
        window.sui.api('onboarding_save_state', { state: this.state }, { toast: "Guide Reset" });
    }
};

document.addEventListener('DOMContentLoaded', () => Guide.init());
JS;
?>