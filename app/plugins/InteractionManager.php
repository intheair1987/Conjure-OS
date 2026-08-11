<?php
// ==============================================================================
// PLUGIN: Interaction Manager
// DESCRIPTION: Gesture Traffic Controller.
// Purpose: Central "Traffic Controller" for all card-based gestures.
// Features: Event Delegation, Gesture Recognition, Conflict Resolution.
// Diagnostics: Tracks active event listeners to measure optimization.
// ==============================================================================

$im_config_file = CJOS_PATH_DATA . '/interaction-manager-config.json';

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'im_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults = [
            'double_tap_speed' => 300,
            'long_press_duration' => 450,
            'long_press_threshold' => 20,
            'swipe_enabled' => true,
            'swipe_reset' => 40,
            'swipe_archive' => 100,
            'swipe_delete' => 160,
            'swipe_fast_delete' => 250,
            'vibration_intensity' => 10,
            'auditor_enabled' => true
        ];
        $config = file_exists($im_config_file) ? json_decode(file_get_contents($im_config_file), true) : $defaults;
        echo json_encode(['status' => 'success', 'config' => $config]);
        exit;
    }
    
    if ($_POST['plugin_action'] === 'im_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $settings = json_decode($_POST['settings'], true);
        // Ensure data dir
        $dir = dirname($im_config_file);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents($im_config_file, json_encode($settings, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- SETTINGS UI ---
$plugin_settings_map['InteractionManager'] = <<<'HTML'
    <!-- DIAGNOSTICS -->
    <div class="setting-item vertical" style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:16px; padding:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <label class="switch" style="width:34px; height:20px;">
                    <input type="checkbox" id="im-auditor-toggle" onchange="imUpdateUI(); imSaveSettings()">
                    <span class="slider" style="border-radius:20px;"></span>
                </label>
                <label class="setting-label" style="margin:0; font-weight:800; color:var(--primary);">Listener Audit</label>
            </div>
            <span id="im-monitor-count" style="font-family:monospace; font-size:16px; font-weight:700;">0</span>
        </div>
        <div class="setting-desc" style="margin-top:8px;">
            Active event listeners detected on cards. 
            <div style="font-size:10px; opacity:0.7; margin-top:4px;">
                Target: <span id="im-monitor-target">Calculating...</span>
            </div>
        </div>
        <div style="height:6px; width:100%; background:var(--btn-bg); border-radius:3px; margin-top:10px; overflow:hidden;">
            <div id="im-monitor-bar" style="height:100%; width:0%; background:var(--danger); transition:width 0.5s ease;"></div>
        </div>
    </div>

    <!-- TIMING CONFIG -->
    <div class="setting-item vertical">
        <label class="setting-label">Double Tap Speed</label>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="im-dt-slider" min="200" max="600" step="50" oninput="imUpdateUI()" onchange="imSaveSettings()" style="flex:1;">
            <span id="im-dt-val" style="font-weight:700; color:var(--text-primary); min-width:50px;">300ms</span>
        </div>
    </div>

    <div class="setting-item vertical">
    <label class="setting-label">Long Press Duration</label>
    <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
        <input type="range" id="im-lp-slider" min="300" max="1000" step="50" oninput="imUpdateUI()" onchange="imSaveSettings()" style="flex:1;">
        <span id="im-lp-val" style="font-weight:700; color:var(--text-primary); min-width:50px;">450ms</span>
    </div>
</div>

<div class="setting-item vertical">
    <label class="setting-label">Movement Tolerance</label>
    <div class="setting-desc">Max distance (pixels) finger can move during a long press.</div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
        <input type="range" id="im-threshold-slider" min="5" max="100" step="5" oninput="imUpdateUI()" onchange="imSaveSettings()" style="flex:1;">
        <span id="im-threshold-val" style="font-weight:700; color:var(--text-primary); min-width:50px;">20px</span>
    </div>
</div>
      

    <div style="border-top: 1px solid var(--border-color); padding-top: 8px;">
        <div data-sui-setting="Swipe Actions" data-sui-desc="Enable left-to-right swipe for quick actions." data-sui-id="im-swipe-toggle" data-sui-onchange="imUpdateUI(); imSaveSettings()"></div>
    </div>

<div id="im-swipe-sliders" style="display:none; flex-direction:column; gap:4px; padding: 0 16px 16px 16px;">
    <div class="setting-item vertical" style="padding:8px 0; border:none;">
        <div style="display:flex; justify-content:space-between;">
            <label class="setting-label" style="font-size:13px;">Reset Zone</label>
            <span id="im-val-sw-reset" style="font-size:12px; font-weight:700; color:var(--primary);">40px</span>
        </div>
        <input type="range" id="im-slider-sw-reset" min="20" max="100" step="5" oninput="imUpdateUI()" onchange="imSaveSettings()">
    </div>
    <div class="setting-item vertical" style="padding:8px 0; border:none;">
        <div style="display:flex; justify-content:space-between;">
            <label class="setting-label" style="font-size:13px;">Archive Zone</label>
            <span id="im-val-sw-archive" style="font-size:12px; font-weight:700; color:var(--primary);">100px</span>
        </div>
        <input type="range" id="im-slider-sw-archive" min="60" max="200" step="10" oninput="imUpdateUI()" onchange="imSaveSettings()">
    </div>
    <div class="setting-item vertical" style="padding:8px 0; border:none;">
        <div style="display:flex; justify-content:space-between;">
            <label class="setting-label" style="font-size:13px;">Delete Zone</label>
            <span id="im-val-sw-delete" style="font-size:12px; font-weight:700; color:var(--primary);">160px</span>
        </div>
        <input type="range" id="im-slider-sw-delete" min="120" max="300" step="10" oninput="imUpdateUI()" onchange="imSaveSettings()">
        </div>
        <div class="setting-item vertical" style="padding:8px 0; border:none;">
<div style="display:flex; justify-content:space-between;">
    <label class="setting-label" style="font-size:13px; color:var(--danger);">Fast Delete (No Prompt)</label>
    <span id="im-val-sw-fast-delete" style="font-size:12px; font-weight:700; color:var(--primary);">250px</span>
</div>
<input type="range" id="im-slider-sw-fast-delete" min="180" max="400" step="10" oninput="imUpdateUI()" onchange="imSaveSettings()">
        </div>
    </div>
      <div id="im-save-status" style="text-align:right; font-size:11px; color:#8E8E93; padding:0 16px 8px; height:14px;"></div>
HTML;
      

// --- CLIENT-SIDE LOGIC ---
$plugin_js .= <<<'JS'
// --- INTERACTION MANAGER JS ---

(function() {
    /* --- CENTRALIZED ANIMATIONS --- */
    const style = document.createElement('style');
    style.innerHTML = `
        .card.card-tapped {
            animation: imCardBounce 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
            transition: none !important;
            z-index: 80 !important;
            will-change: transform, opacity;
        }
        @keyframes imCardBounce {
            0% { transform: scale(1); }
            40% { transform: scale(0.94); background-color: rgba(0, 122, 255, 0.05); }
            100% { transform: scale(1); }
        }

        /* SWIPE UI */
        .swipe-tray {
            position: absolute;
            top: 0; left: 0; bottom: 0; right: 0;
            background: #E5E5EA;
            border-radius: inherit;
            display: flex;
            align-items: center;
            padding-left: 24px;
            z-index: 0;
            opacity: 0;
            transition: background 0.3s ease, opacity 0.2s ease;
        }
        .swipe-tray-icon {
            color: white;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0;
            position: absolute;
            left: 0;
            /* Vertical center baseline */
            top: 50%;
            /* Only transition opacity/color to keep movement 1:1 with finger */
            transition: opacity 0.3s ease, color 0.3s ease;
            pointer-events: none;
            white-space: nowrap;
            will-change: transform;
        }
        .swipe-tray-icon svg { width: 24px; height: 24px; stroke-width: 3; }
        
        /* STAGES */
        .swipe-stage-reset { background: #007AFF !important; opacity: 1; }
        .swipe-stage-archive { background: #5856D6 !important; opacity: 1; }
        .swipe-stage-delete { background: #FF3B30 !important; opacity: 1; }
        .swipe-stage-fast_delete { background: #8B0000 !important; opacity: 1; }
        
        /* Removed transform reset to allow dynamic centering via JS */
        .swipe-active-icon { opacity: 1 !important; }

        .card-content {
            position: relative;
            z-index: 2;
            background: var(--card-bg);
            will-change: transform;
        }
    `;
    document.head.appendChild(style);
    // --- 1. CONFIGURATION ---
    let imConfig = {
        double_tap_speed: 300,
        long_press_duration: 450,
        vibration_intensity: 10
    };

    let imMonitorTimer = null;
    let imObserver = null;

    function startImMonitor() {
        if (imMonitorTimer) return;
        if (!imConfig.auditor_enabled || document.visibilityState !== "visible") return;
        
        updateMonitorUI();
        imMonitorTimer = setInterval(() => {
            if (document.visibilityState !== "visible" || !imConfig.auditor_enabled) {
                stopImMonitor();
                return;
            }
            updateMonitorUI();
        }, 2000);
    }

    function stopImMonitor() {
        if (imMonitorTimer) {
            clearInterval(imMonitorTimer);
            imMonitorTimer = null;
        }
    }

    function initImObserver() {
        const target = document.getElementById('im-monitor-count');
        if (!target) return;

        if (imObserver) imObserver.disconnect();

        imObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    startImMonitor();
                } else {
                    stopImMonitor();
                }
            });
        }, { threshold: 0.1 });

        imObserver.observe(target);
    }

    // --- 2. LISTENER MONITOR (THE AUDITOR) ---
    // We hook into addEventListener to count how many plugins are attaching directly to cards.
    let imListenerCount = 0;
    const originalAddEventListener = EventTarget.prototype.addEventListener;
    
    window._imGlobalListeners = 0;
    EventTarget.prototype.addEventListener = function(type, listener, options) {
        const isGlobal = (this === document || this === window);
        const isTargeted = (this.classList && this.classList.contains('card')) || (this.id === 'entries-container');
        
        const isInteractionEvent = ['click', 'touchstart', 'touchend', 'mousedown', 'mouseup', 'contextmenu'].includes(type);

        if ((isGlobal || isTargeted) && isInteractionEvent) {
            if (listener.name !== 'imMasterHandler' && !type.includes('scroll')) {
                if (isGlobal) window._imGlobalListeners++;
                else imListenerCount++;

                if (window._imMonitorTimeout) clearTimeout(window._imMonitorTimeout);
                window._imMonitorTimeout = setTimeout(updateMonitorUI, 100);
            }
        }
        return originalAddEventListener.call(this, type, listener, options);
    };

    function updateMonitorUI() {
        if (!imConfig.auditor_enabled) return;
        const label = document.getElementById('im-monitor-count');
        const bar = document.getElementById('im-monitor-bar');
        const target = document.getElementById('im-monitor-target');
        
        const cards = document.querySelectorAll('.card');
        const cardCount = cards.length || 1;

        // 1. Scan for Inline Megaphones (onclick, onmousedown, ontouchstart)
        let inlineDebt = 0;
        cards.forEach(c => {
            if (c.onclick) inlineDebt++;
            if (c.onmousedown) inlineDebt++;
            if (c.ontouchstart) inlineDebt++;
            // Check internal player buttons which often have legacy biding
            c.querySelectorAll('button').forEach(btn => {
                if (btn.onclick) inlineDebt++;
            });
        });

        // 2. Background Shouts (Global listeners scaled by card count)
        // If a plugin listens to 'document', it's effectively watching every card.
        const backgroundShouts = window._imGlobalListeners || 0;

        const totalShouts = imListenerCount + inlineDebt + (backgroundShouts * cardCount);
        
        if (label) label.innerText = totalShouts;
        
        const ratio = totalShouts / cardCount;
        
        if (target) {
            if (ratio <= 1.2) target.innerHTML = "<span style='color:#34C759'>SILENT & OPTIMIZED</span>";
            else target.innerHTML = `Breakdown: ${imListenerCount} Card-Level | ${inlineDebt} Inline | ${backgroundShouts} Global`;
        }

        if (bar) {
            // Red zone if there are more than 3 "shouts" per card
            const pct = Math.min(100, (ratio / 4) * 100);
            bar.style.width = pct + '%';
            bar.style.background = pct < 30 ? '#34C759' : (pct < 70 ? '#FFD60A' : '#FF3B30');
        }
    }

    function imDecorateCard(card) {
        if (!card || card.querySelector('.swipe-tray')) return;
        const tray = document.createElement('div');
        tray.className = 'swipe-tray';
        tray.innerHTML = `
            <div class="swipe-tray-icon icon-reset"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg> Reset</div>
            <div class="swipe-tray-icon icon-archive"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg> Archive</div>
            <div class="swipe-tray-icon icon-delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> Delete</div>
            <div class="swipe-tray-icon icon-fast_delete" style="color:#FFBABA;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg> Instant Delete</div>
        `;
        card.prepend(tray);
    }

    // --- 3. THE SUBSCRIBER REGISTRY ---
    const subscribers = {
        onTap: [],
        onDoubleTap: [],
        onTripleTap: [],
        onLongPress: [],
        onSideTap: [],
        onSelectionChange: [],
        onInteractionReset: [],
        onSwipeAction: [],
        onDogEarTap: [],
        onPlayTap: [],
        onStopTap: [],
        onEditTap: []
    };

    window.InteractionManager = {
        setAnchor: function(id) { lastAnchorId = id; },
        subscribe: function(def) {
            if (!subscribers[def.event]) return;
            subscribers[def.event].push(def);
            // Sort by priority (Ascending: 1 runs first)
            subscribers[def.event].sort((a, b) => (a.priority || 50) - (b.priority || 50));
            console.log(`[IM] Subscribed: ${def.plugin} to ${def.event}`);
        },
        
        // Helper for plugins to trigger standard haptics
        haptic: (type) => window.sui.haptic(type)
    };

    // --- 4. THE GESTURE ENGINE ---
    let lastTapTime = 0;
    let tapCount = 0;
    let pressTimer = null;
    let startX = 0, startY = 0;
    let startTime = 0; // Tracked for flick detection
    let isMoved = false;
    let isSwiping = false;
    let currentSwipeStage = null;
    let currentCard = null;
    let multiTapTimer = null;
    let lastAnchorId = null;     
    let isBusy = false;
    let swipeRafPending = false;
    let swipeRafId = null;
    let latestDx = 0, latestDy = 0;

    function updateSwipeFrame() {
        swipeRafPending = false;
        if (!isSwiping || !currentCard) return;

        const content = currentCard.querySelector('.card-content');
        const tray = currentCard.querySelector('.swipe-tray');
        if (!content || !tray) return;

        const moveX = latestDx < 0 ? 0 : Math.pow(latestDx, 0.95);
        content.style.transform = "translateX(" + moveX + "px)";
        content.style.transition = 'none';
        tray.style.opacity = Math.min(1, moveX / 50);

        // DYNAMIC ICON POSITIONING
        const icons = tray.querySelectorAll('.swipe-tray-icon');
        const iconTargetX = (moveX / 2) - 50;

        icons.forEach(icon => {
            icon.style.transform = `translateY(-50%) translateX(${iconTargetX}px)`;
            if (moveX < 40) {
                icon.style.opacity = '0';
            }
        });

        let newStage = null;
        if (moveX > (imConfig.swipe_fast_delete || 250)) newStage = 'fast_delete';
        else if (moveX > (imConfig.swipe_delete || 160)) newStage = 'delete';
        else if (moveX > (imConfig.swipe_archive || 100)) newStage = 'archive';
        else if (moveX > (imConfig.swipe_reset || 40)) newStage = 'reset';

        if (newStage !== currentSwipeStage) {
            currentSwipeStage = newStage;
            window.sui.haptic('tap');
            tray.className = 'swipe-tray ' + (newStage ? 'swipe-stage-' + newStage : '');
            tray.querySelectorAll('.swipe-tray-icon').forEach(i => i.classList.remove('swipe-active-icon'));
            if (newStage) tray.querySelector('.icon-' + newStage).classList.add('swipe-active-icon');
        }
    }

    function imMasterHandler(e) {
        // A. DEAD ZONE CHECK
        if (e.target.closest('.read-more-btn, audio, textarea, input')) {
            return; // Let native browser handle controls
        }

        const card = e.target.closest('.card');
        if (!card) return;

        // B. GHOST TAP CHECK (Busy State)
        if (isBusy) return;

        // C. EVENT ROUTING
        if (e.type === 'touchstart' || e.type === 'mousedown') handleStart(e, card);
        else if (e.type === 'touchmove' || e.type === 'mousemove') handleMove(e);
        else if (e.type === 'touchend' || e.type === 'mouseup') handleEnd(e, card);
        else if (e.type === 'click') handleClick(e, card);
    }

    function handleStart(e, card) {
        // MULTI-TOUCH GUARD: Abort if more than one finger is detected
        if (e.touches && e.touches.length > 1) {
            if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
            isSwiping = false;
            isMoved = true;
            return;
        }
        currentCard = card;
        isMoved = false;
        isSwiping = false;
        currentSwipeStage = null;
        startX = e.touches ? e.touches[0].clientX : e.clientX;
        startY = e.touches ? e.touches[0].clientY : e.clientY;
        startTime = Date.now();

        // Long Press Timer
        pressTimer = setTimeout(() => {
            dispatch('onLongPress', card, { x: startX, y: startY });
            isMoved = true; // Prevent click after long press
        }, imConfig.long_press_duration);
    }

    function handleMove(e) {
        // MULTI-TOUCH GUARD: Abort if more than one finger is detected
        if (e.touches && e.touches.length > 1) {
            if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
            isSwiping = false;
            isMoved = true;
            return;
        }
    const cx = e.touches ? e.touches[0].clientX : e.clientX;
    const cy = e.touches ? e.touches[0].clientY : e.clientY;
    const dx = cx - startX;
    const dy = cy - startY;

    const threshold = imConfig.long_press_threshold || 20;
    if (pressTimer && (Math.abs(dx) > threshold || Math.abs(dy) > threshold)) {
        clearTimeout(pressTimer);
        pressTimer = null;
        isMoved = true;
    }
      

        if (!isSwiping && imConfig.swipe_enabled && dx > 15 && Math.abs(dx) > Math.abs(dy) * 1.5 && !document.body.classList.contains('select-mode')) {
            isSwiping = true;
            isMoved = true;
            
            // LOCK VIEWPORT: Prevent page swiping while interacting with a card
            const viewport = document.querySelector('.horizontal-viewport');
            if (viewport) viewport.style.overflowX = 'hidden';
            if (!currentCard.querySelector('.swipe-tray')) {
                imDecorateCard(currentCard);
            }
        }

        if (isSwiping) {
    latestDx = dx;
    latestDy = dy;
    if (!swipeRafPending) {
        swipeRafPending = true;
        swipeRafId = requestAnimationFrame(updateSwipeFrame);
    }
}
    }

    function handleEnd(e, card) {
if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
if (swipeRafPending) {
    cancelAnimationFrame(swipeRafId);
    swipeRafPending = false;
}
if (isSwiping) {const content = currentCard.querySelector('.card-content');
            const tray = currentCard.querySelector('.swipe-tray');
            
            // FLICK DETECTION
            const endX = e.changedTouches ? e.changedTouches[0].clientX : e.clientX;
            const totalDx = endX - startX;
            const duration = Date.now() - startTime;

            // If the swipe is fast (<250ms) and has sufficient distance (>40px),
            // force it to be a 'reset' action regardless of depth.
            if (duration < 250 && totalDx > 40) {
                currentSwipeStage = 'reset';
            }

            // UNLOCK VIEWPORT: Restore page swiping
            const viewport = document.querySelector('.horizontal-viewport');
            if (viewport) viewport.style.overflowX = 'auto';

            if (currentSwipeStage) {
                dispatch('onSwipeAction', currentCard, { action: currentSwipeStage });
            }
            content.style.transition = 'transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
            content.style.transform = 'translateX(0)';
            if (tray) tray.style.opacity = '0';
            isSwiping = false;
            currentSwipeStage = null;
        }
    }

    function handleClick(e, card) {
        if (isMoved) { 
            e.stopPropagation(); 
            e.preventDefault(); 
            return; 
        }

        // Selection Mode Context
        if (e.target.closest('.dog-ear-zone')) {
            dispatch('onDogEarTap', card, {});
            return;
        }

        if (e.target.closest('.btn-play')) {
            dispatch('onPlayTap', card, {});
            return;
        }

        if (e.target.closest('.btn-stop')) {
            dispatch('onStopTap', card, {});
            return;
        }

        if (e.target.closest('.manual-edit-btn')) {
            dispatch('onEditTap', card, {});
            return;
        }

        const isSelecting = document.body.classList.contains('select-mode');

        // --- STATE MATRIX LOGIC ---

        if (isSelecting) {
            // In Selection Mode: Single Tap = Toggle Checkbox
            // We handle the "Checkbox Click" logic manually to ensure we catch the event
            const checkbox = card.querySelector('.custom-checkbox');
            if (checkbox) {
                // Visual toggle
                checkbox.classList.toggle('checked');
                // Update history
                lastAnchorId = checkbox.getAttribute('data-id');
                // Fire Subscription
                dispatch('onSelectionChange', card, { isChecked: checkbox.classList.contains('checked') });
            }
            // Eat the double tap logic
            tapCount = 0;
            return;
        }

        // Default Stream Mode
        const now = Date.now();
        
        // Side Tap Detection (Outer 50px)
        const rect = card.getBoundingClientRect();
        const x = (e.detail && e.detail.x) || e.clientX; // Mouse vs simulated
        const isSide = (x - rect.left < 50) || (rect.right - x < 50);

        if (isSide) {
            dispatch('onSideTap', card, { side: (x - rect.left < 50) ? 'left' : 'right' });
            tapCount = 0;
            return;
        }

        // --- MULTI-TAP GESTURE ENGINE ---
        if (now - lastTapTime < imConfig.double_tap_speed) {
            tapCount++;
        } else {
            tapCount = 1;
        }
        lastTapTime = now;

        if (multiTapTimer) clearTimeout(multiTapTimer);

        if (tapCount === 3) {
            // TRIPLE TAP: Reset Interaction
            if (multiTapTimer) clearTimeout(multiTapTimer);
            // Kill the bounce animation from the previous two taps immediately
            card.classList.remove('card-tapped');
            dispatch('onInteractionReset', card, {});
            dispatch('onTripleTap', card, {});
            tapCount = 0;
            lastTapTime = 0;
            return; // Exit immediately to prevent the 'else' block from scheduling anything
        } else {
            // Visual Feedback (Bounce on every tap)
            card.classList.remove('card-tapped');
            void card.offsetWidth;
            card.classList.add('card-tapped');

            multiTapTimer = setTimeout(() => {
                if (tapCount === 1) {
                    dispatch('onTap', card, {});
                } else if (tapCount === 2) {
                    dispatch('onDoubleTap', card, {});
                }
                tapCount = 0;
                lastTapTime = 0;
            }, imConfig.double_tap_speed);
        }
    }

    // --- 5. DISPATCHER ---
    function dispatch(eventName, card, detail) {
        // Build Context
        const context = {
            isSelectMode: document.body.classList.contains('select-mode'),
            isSearchActive: (typeof isSearchOpen !== 'undefined' && isSearchOpen),
            isDrafting: (document.getElementById('draft-pad-card')?.style.transform === 'translateY(0px)'),
            lastAnchorId: lastAnchorId
        };

        // Get Entry Data
        const id = card.querySelector('.custom-checkbox')?.getAttribute('data-id');
        const entry = (typeof logs !== 'undefined') ? logs.find(l => l.id === id) : null;

        // Find Subscribers
        const list = subscribers[eventName];
        if (!list || list.length === 0) return;

        // Execute in Priority Order
        for (const sub of list) {
            // Check Condition
            if (sub.condition && !sub.condition(card, context)) continue;
            
            // Execute Handler
            try {
                const result = sub.handler({ card, entry, context, detail, vibrate: window.InteractionManager.haptic });
                // If handler returns false, stop propagation to lower priority plugins
                if (result === false) break;
            } catch(e) {
                console.error(`[IM] Error in ${sub.plugin}:`, e);
            }
        }
    }

    // --- 6. INIT & BINDING ---
    window.addEventListener('load', async () => {
        // Pre-register Card Decorator & decorate current cards for butter-smooth swiping
        if (window.registerCardPlugin) {
            window.registerCardPlugin(imDecorateCard, 80);
        }
        document.querySelectorAll('.card').forEach(imDecorateCard);

        // Load Config
        try {
            const data = await window.sui.api('im_get_config', {}, { toast: false });
            if (data) imConfig = data.config;
            
            // Sync UI
            const dtSlider = document.getElementById('im-dt-slider');
            const lpSlider = document.getElementById('im-lp-slider');
            const thSlider = document.getElementById('im-threshold-slider');
            if(dtSlider) dtSlider.value = imConfig.double_tap_speed;
            if(lpSlider) lpSlider.value = imConfig.long_press_duration;
            if(thSlider) thSlider.value = imConfig.long_press_threshold || 20;

            const swToggle = document.getElementById('im-swipe-toggle');
            if(swToggle) swToggle.checked = imConfig.swipe_enabled !== false;
            if(document.getElementById('im-slider-sw-reset')) document.getElementById('im-slider-sw-reset').value = imConfig.swipe_reset || 40;
            if(document.getElementById('im-slider-sw-archive')) document.getElementById('im-slider-sw-archive').value = imConfig.swipe_archive || 100;
            if(document.getElementById('im-slider-sw-delete')) document.getElementById('im-slider-sw-delete').value = imConfig.swipe_delete || 160;
            if(document.getElementById('im-slider-sw-fast-delete')) document.getElementById('im-slider-sw-fast-delete').value = imConfig.swipe_fast_delete || 250;
            const audToggle = document.getElementById('im-auditor-toggle');
            if(audToggle) audToggle.checked = imConfig.auditor_enabled !== false;
            imUpdateUI();
        } catch(e) {}

        // Attach Master Listener (Event Delegation)
        const container = document.getElementById('entries-container');
        if (container) {
            // Use capture to inspect before bubbling, or bubble?
            // Bubble is safer for 'closest' checks.
            container.addEventListener('touchstart', imMasterHandler, { passive: true });
            container.addEventListener('touchmove', imMasterHandler, { passive: true });
            container.addEventListener('touchend', imMasterHandler, { passive: true });
            container.addEventListener('mousedown', imMasterHandler);
            container.addEventListener('mouseup', imMasterHandler);
            container.addEventListener('click', imMasterHandler);
            // Prevent browser context menu on cards to allow clean long-press
            container.addEventListener('contextmenu', (e) => {
                if (e.target.closest('.card')) e.preventDefault();
            });
            console.log("[IM] Master Listener Attached");
        }

        // Periodic Monitor Update (Battery Friendly)
        initImObserver();

        document.addEventListener("visibilitychange", () => {
            if (document.visibilityState === "visible") {
                const target = document.getElementById('im-monitor-count');
                if (target && target.offsetWidth > 0 && imConfig.auditor_enabled) {
                    startImMonitor();
                }
            } else {
                stopImMonitor();
            }
        });
    });

    // UI Helpers
window.imUpdateUI = function() {
    const dt = document.getElementById('im-dt-slider').value;
    const lp = document.getElementById('im-lp-slider').value;
    const th = document.getElementById('im-threshold-slider').value;
    const aud = document.getElementById('im-auditor-toggle').checked;

    const swEn = document.getElementById('im-swipe-toggle').checked;
    const swRes = document.getElementById('im-slider-sw-reset').value;
const swArc = document.getElementById('im-slider-sw-archive').value;
const swDel = document.getElementById('im-slider-sw-delete').value;
const swFast = document.getElementById('im-slider-sw-fast-delete').value;
        
document.getElementById('im-dt-val').innerText = dt + 'ms';
      document.getElementById('im-lp-val').innerText = lp + 'ms';
    document.getElementById('im-threshold-val').innerText = th + 'px';

    document.getElementById('im-val-sw-reset').innerText = swRes + 'px';
document.getElementById('im-val-sw-archive').innerText = swArc + 'px';
document.getElementById('im-val-sw-delete').innerText = swDel + 'px';
document.getElementById('im-val-sw-fast-delete').innerText = swFast + 'px';
document.getElementById('im-swipe-sliders').style.display = swEn ? 'flex' : 'none';
        
imConfig.double_tap_speed = parseInt(dt);
imConfig.long_press_duration = parseInt(lp);
imConfig.long_press_threshold = parseInt(th);
imConfig.swipe_enabled = swEn;
imConfig.swipe_reset = parseInt(swRes);
imConfig.swipe_archive = parseInt(swArc);
imConfig.swipe_delete = parseInt(swDel);
imConfig.swipe_fast_delete = parseInt(swFast);
imConfig.auditor_enabled = aud;
      // UI Feedback
        const monitorSection = document.getElementById('im-monitor-bar')?.closest('.setting-item');
        if (monitorSection) monitorSection.style.opacity = aud ? '1' : '0.3';

        // Dynamic Auditor Lifecycle (Battery Friendly)
        if (aud) {
            initImObserver();
            const target = document.getElementById('im-monitor-count');
            if (target && target.offsetWidth > 0) {
                startImMonitor();
            }
        } else {
            if (imObserver) {
                imObserver.disconnect();
                imObserver = null;
            }
            stopImMonitor();
        }
    };

    window.imSaveSettings = async function() {
        await window.sui.api('im_save_config', { settings: imConfig }, { toast: false });
        const status = document.getElementById('im-save-status');
        if(status) {
            status.innerText = "Saved";
            setTimeout(() => status.innerText = "", 2000);
        }
    };

})();
JS;