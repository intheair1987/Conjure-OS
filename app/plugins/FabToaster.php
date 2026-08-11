<?php
// ==============================================================================
// PLUGIN: FabToaster
// DESCRIPTION: Button Status Notifications.
// 1. Morphs Record Button into notification pill (works during recording).
// 2. PATCHES the Microphone "Stuck" bug by forcing track release on stop.
// 3. Includes "Anti-Stuck" logic to ensure notifications always dismiss.
// UPDATED: Fixed "Stuck on Wake" bug by cancelling stale animation frames.
// ==============================================================================

$plugin_settings_map['FabToaster'] = <<<'HTML'
    <div data-sui-setting="Live Button Notifications" data-sui-desc="Show notifications on the button itself." data-sui-id="ft-toggle" data-sui-onchange="toggleFabToaster(this.checked)"></div>
    
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Notification History</label>
            <div class="setting-desc">View and debug recent system messages.</div>
        </div>
        <button onclick="ftOpenHistoryStudio()" class="text-btn" style="background:var(--btn-bg); padding:8px 16px; border-radius:10px; font-weight:700;">View Log</button>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- FAB TOASTER JS ---

const ftEnabled = localStorage.getItem("cjos_ft_enabled") !== "false"; 
let ftHistory = JSON.parse(sessionStorage.getItem('cjos_ft_history') || '[]');

window.ftOpenHistoryStudio = function() {
    const renderHistory = () => {
        if (ftHistory.length === 0) return window.suiEmptyState('🔔', 'No notifications recorded');
        
        return ftHistory.map((item, idx) => `
            <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:16px; padding:16px; margin-bottom:12px; display:flex; flex-direction:column; gap:10px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:4px;">${item.time}</div>
                        <div style="font-size:15px; font-weight:700; color:var(--text-primary); line-height:1.3;">${item.message}</div>
                    </div>
                    <div class="meta-badge sui-badge-default" style="font-size:9px;">${item.plugin || 'System'}</div>
                </div>
                
                ${item.metrics ? `
                    <div style="margin-top:4px;">
                        ${window.suiAccordion('ft-hist-' + idx, 'Debug Details', `
                            <div style="padding:12px; background:var(--input-bg); border-radius:10px; margin-top:8px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <span style="font-family:monospace; font-size:11px; color:var(--primary); font-weight:700;">${item.caller || 'anonymous'}()</span>
                                    <button onclick="ftCopyDebugInfo(${idx})" style="background:var(--card-bg); border:1px solid var(--border-color); padding:4px 8px; border-radius:6px; font-size:10px; font-weight:700; cursor:pointer;">Copy Debug Info</button>
                                </div>
                                <pre style="margin:0; font-family:monospace; font-size:11px; color:var(--text-primary); white-space:pre-wrap; word-break:break-all;">${JSON.stringify(JSON.parse(item.metrics), null, 2)}</pre>
                            </div>
                        `, false)}
                    </div>
                ` : ''}
            </div>
        `).reverse().join('');
    };

    window.sui.openStudio({
        id: 'ft-history',
        title: 'Notification History',
        content: `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <div style="font-size:12px; color:var(--text-secondary); font-weight:600;">Last ${ftHistory.length} events</div>
                <button onclick="ftClearHistory()" class="text-btn" style="color:var(--danger); font-size:12px; font-weight:700;">Clear All</button>
            </div>
            <div id="ft-history-list">${renderHistory()}</div>
        `,
        onSetup: (cont) => {
            window.suiHydrateIcons(cont);
        }
    });
};

window.ftClearHistory = function() {
    ftHistory = [];
    sessionStorage.removeItem('cjos_ft_history');
    const list = document.getElementById('ft-history-list');
    if (list) list.innerHTML = window.suiEmptyState('🔔', 'History Cleared');
};

window.ftCopyDebugInfo = function(idx) {
    const item = ftHistory[idx];
    if (!item) return;

    if (window.sui && window.sui.haptic) window.sui.haptic('light');

    let report = `[${item.time}] ${item.message}\n`;
    report += `Plugin: ${item.plugin || 'System'}\n`;
    report += `Caller: ${item.caller || 'anonymous'}()\n`;
    
    if (item.metrics) {
        try {
            const parsed = JSON.parse(item.metrics);
            report += `Metrics: ${JSON.stringify(parsed, null, 2)}`;
        } catch(e) {
            report += `Metrics (Raw): ${item.metrics}`;
        }
    }

    copyToClipboard("```\n" + report + "\n```");
};

window.toggleFabToaster = function(val) {
    localStorage.setItem("cjos_ft_enabled", val);
    location.reload();
};

window.addEventListener("load", () => {
    
    // --- 1. CRITICAL: MICROPHONE RELEASE PATCH ---
    if (typeof window.cjosUpload === "function") {
const originalUpload = window.cjosUpload;
window.cjosUpload = async function() {if (typeof mediaRecorder !== "undefined" && mediaRecorder && mediaRecorder.stream) {
                try {
                    mediaRecorder.stream.getTracks().forEach(track => track.stop());
                } catch(e) { console.error("Mic release error", e); }
            }
            return originalUpload.apply(this, arguments);
        };
    }

    // --- 2. SETUP UI ---
    const input = document.getElementById("ft-toggle");
    if(input) input.checked = ftEnabled;

    // --- 2.5 INJECT SETTINGS HEADER BUTTON ---
    const headerActions = document.getElementById('settings-header-actions');
    if (headerActions && !document.getElementById('ft-header-history-btn')) {
        const btn = document.createElement('button');
        btn.id = 'ft-header-history-btn';
        // Use inline styles instead of .settings-close to avoid triggering the 
        // SharedUI background-click dismissal logic which scans for that class.
        btn.style.cssText = 'background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; position:relative;';
        btn.title = 'Notification History';
        btn.innerHTML = `<span data-sui-icon="activity" data-sui-size="18"></span>`;
        btn.onclick = (e) => {
            e.stopPropagation();
            ftOpenHistoryStudio();
        };
        headerActions.appendChild(btn);
        if (window.suiHydrateIcons) window.suiHydrateIcons(headerActions);
    }

    if (!ftEnabled) return;

    // --- 3. ANIMATION CSS ---
    const style = document.createElement("style");
    style.innerHTML = `
        /* BASE TRANSITION */
        .fab {
            width: var(--fab-size, 68px); 
            min-width: var(--fab-size, 68px);
            max-width: var(--fab-size, 68px);
            transition: 
                bottom 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275),
                left 0.5s cubic-bezier(0.19, 1, 0.22, 1),
                width 0.4s ease,
                height 0.4s ease,
                max-width 0.4s ease,
                min-width 0.4s ease,
                border-radius 0.4s ease,
                background-color 0.4s ease,
                border-color 0.4s ease,
                color 0.4s ease,
                box-shadow 0.4s ease,
                transform 0.4s ease;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            overflow: hidden !important;
            z-index: 3100 !important;
        }

        /* ACTIVE PILL STATE */
        body .fab.ft-active,
        #fcb-notif-layer.ft-active {
            left: 50% !important; 
            min-width: 220px !important;
            max-width: 380px !important;
            border-radius: 20px !important;
            padding-left: 20px !important;  
            padding-right: 24px !important; 
            justify-content: flex-start !important;
            transform: translateX(-50%) scale(1.02) !important;
            animation: none !important;
            /* Inherit background-color from .fab or .fab.recording */
            box-shadow: 
                0 15px 40px rgba(0,0,0,0.2),
                0 0 0 1px rgba(255,255,255,0.1) inset !important;
        }

        /* BACK-MODE / SELECT-MODE INTEGRATION:
           Force absolute centering even in back-mode to ensure notifications are readable. 
           We use maximum specificity to override FloatingRecorder's corner-docking and animations. */
        html body .fab.ft-active,
        html body .fab.ft-active.back-mode,
        html body.select-mode .fab.ft-active,
        html body.select-mode .fab.ft-active.back-mode {
            left: 50% !important;
            right: auto !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
            transform: translateX(-50%) scale(1.02) !important;
            animation: none !important;
            transform-origin: center center !important;
        }

        /* RECORDING OVERRIDE */
        .fab.recording.ft-active {
            animation: none !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
        }

        /* TEXT LABEL */
        #ft-label {
            max-width: 0;
            opacity: 0;
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary) !important;
            transform: translateX(10px);
            margin-left: 0;
            transition: 
                opacity 0.3s ease,
                transform 0.4s cubic-bezier(0.2, 0, 0, 1),
                max-width 0.4s cubic-bezier(0.2, 0, 0, 1),
                margin-left 0.3s ease;
            /* Allow touches to pass through to the FAB for gestures */
            pointer-events: none; 
            overflow: hidden; /* Viewport for Marquee */
            display: flex;
            align-items: center;
        }
        .fab.ft-active #ft-label,
        #fcb-container.ft-active #ft-label,
        #fcb-notif-layer.ft-active #ft-label {
            max-width: calc(100vw - 80px); /* Expand to screen width minus icon/padding */
            opacity: 1;
            margin-left: 14px;
            transform: translateX(0);
        }
        
        /* Command Bar Specific Label Alignment */
        #fcb-notif-layer.ft-active #ft-label {
            margin-left: 0;
            width: 100%;
            justify-content: center;
            padding: 0 10px;
            box-sizing: border-box;
        }
        
        #fcb-notif-layer.ft-active .ft-text-inner {
            font-size: 14px;
            font-weight: 800;
            color: var(--primary);
            text-shadow: 0 1px 4px rgba(0,0,0,0.1);
        }

        /* Back Mode override handled by higher specificity body .fab.ft-active */
        .ft-text-inner {
            display: inline-block;
            white-space: nowrap;
            will-change: transform;
        }
        @keyframes ft-marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(var(--ft-scroll-dist)); }
        }

        /* FLOWING LIGHT (SHIMMER) */
        .fab::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(105deg, transparent 30%, rgba(255,255,255,0.05) 45%, rgba(255,255,255,0.2) 50%, rgba(255,255,255,0.05) 55%, transparent 70%);
            background-size: 250% 100%;
            background-position: 100% 0;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }
        .fab.ft-active::before {
            opacity: 1;
            animation: ft-flow-shine 2.5s infinite cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes ft-flow-shine { 0% { background-position: 100% 0; } 100% { background-position: -150% 0; } }

        .fab svg {
            flex-shrink: 0;
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s;
        }

        /* PERMANENTLY HIDE ORIGINAL TOAST WHEN PLUGIN ACTIVE */
        #toast { display: none !important; }
    `;
    document.head.appendChild(style);

    // --- 4. DOM & OBSERVER ---
    const fab = document.getElementById("fab-record");
    const originalToast = document.getElementById("toast");
    if (!fab || !originalToast) return;

    const label = document.createElement("span");
    label.id = "ft-label";
    fab.appendChild(label); // Default append, moved dynamically later

    let safetyTimer = null;
    let showRafId = null; // FIX: Track the Animation Frame

    // Helper: Close the toaster and clear ALL associated timers
    const closeToaster = () => {
        if(showRafId) { cancelAnimationFrame(showRafId); showRafId = null; }
        if(window._ftAutoDismissTimer) { clearTimeout(window._ftAutoDismissTimer); window._ftAutoDismissTimer = null; }
        if(safetyTimer) { clearTimeout(safetyTimer); safetyTimer = null; }
        
        fab.classList.remove("ft-active");
        const fcb = document.getElementById("fcb-container");
        if (fcb) fcb.classList.remove("ft-active");
        const fcbNotif = document.getElementById("fcb-notif-layer");
        if (fcbNotif) fcbNotif.classList.remove("ft-active");
    };

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.attributeName === "class") {
                const isToastVisible = originalToast.classList.contains("show");
                
                if (isToastVisible) {
                    // 1. Reset all state for a fresh notification
                    closeToaster(); 

                    // DYNAMIC TARGET RESOLUTION (Command Bar vs FAB)
                    const isFcbMode = document.body.classList.contains('fcb-mode');
                    const targetFab = isFcbMode ? document.getElementById('fcb-notif-layer') : fab;
                    
                    if (targetFab && label.parentNode !== targetFab) {
                        targetFab.appendChild(label);
                    }
                    
                    const text = originalToast.textContent || "Notice";
                    label.innerHTML = `<span class="ft-text-inner">${text}</span>`;

                    // 1.5 Record to History
                    const now = new Date();
                    const timeStr = now.getHours().toString().padStart(2, '0') + ":" + now.getMinutes().toString().padStart(2, '0') + ":" + now.getSeconds().toString().padStart(2, '0');
                    
                    const entry = {
                        time: timeStr,
                        message: text,
                        plugin: originalToast.dataset.plugin || null,
                        caller: originalToast.dataset.caller || null,
                        metrics: originalToast.dataset.metrics || null
                    };
                    
                    ftHistory.push(entry);
                    if (ftHistory.length > 50) ftHistory.shift(); // Cap at 50
                    sessionStorage.setItem('cjos_ft_history', JSON.stringify(ftHistory));

                    // Clear data attributes to prevent ghosting on next toast
                    delete originalToast.dataset.plugin;
                    delete originalToast.dataset.caller;
                    delete originalToast.dataset.metrics;
                    
                    // 2. Expansion & Marquee Logic
                    safetyTimer = setTimeout(() => {
                        if (!targetFab.classList.contains("ft-active")) return;
                        const inner = label.querySelector('.ft-text-inner');
                        if (!inner) return;
                        
                        const overflow = inner.offsetWidth - label.offsetWidth;
                        if (overflow > 0) {
                            const duration = Math.max(3, overflow / 50);
                            inner.style.setProperty('--ft-scroll-dist', `-${overflow + 20}px`);
                            inner.style.animation = `ft-marquee ${duration}s linear 0.8s forwards`;
                            
                            // Schedule auto-dismiss: Delay (0.8s) + Duration + 3s buffer
                            const totalMs = (0.8 + duration + 3) * 1000;
                            window._ftAutoDismissTimer = setTimeout(() => {
                                if (targetFab.classList.contains("ft-active")) closeToaster();
                            }, totalMs);
                        } else {
                            // Standard 4s dismiss for short text
                            window._ftAutoDismissTimer = setTimeout(() => {
                                if (targetFab.classList.contains("ft-active")) closeToaster();
                            }, 4000);
                        }
                    }, 700); 
                    
                    // 3. Visual Activation
                    showRafId = requestAnimationFrame(() => {
                        targetFab.classList.add("ft-active");
                        showRafId = null; 
                    });
                }
                // We no longer closeToaster() when isToastVisible is false. 
                // We let our own timer or the user handle the dismissal.
            }
        });
    });

    observer.observe(originalToast, { attributes: true });

    // Initial Check (Fix for Load-Time Toasts)
    if (originalToast.classList.contains("show")) {
        const text = originalToast.textContent || "Notice";
        label.innerHTML = `<span class="ft-text-inner">${text}</span>`;
        fab.classList.add("ft-active");
    }

    // FIX: Catch-all for "Screen On" synchronization
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") {
            // If the original toast is already gone, ensure we are closed
            if (!originalToast.classList.contains("show")) {
                closeToaster();
            }
        }
    });

    // Intercept clicks on the FAB when a notification is active.
    // This dismisses the message without triggering a new recording.
    // We use capture:true to catch the event before FloatingRecorder's onclick.
    fab.addEventListener("click", (e) => {
        if (fab.classList.contains("ft-active")) {
            e.stopImmediatePropagation();
            closeToaster();
        }
    }, true);
});
JS;
?>