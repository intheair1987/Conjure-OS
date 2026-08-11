<?php
// ==============================================================================
// PLUGIN: Hydration Guard
// DESCRIPTION: Stability-aware Splash Screen.
// Purpose: Hides DOM jitter and layout shifts during initial plugin hydration.
// ==============================================================================

$plugin_settings_map['HydrationGuard'] = <<<'HTML'
<div class="setting-item vertical">
    <label class="setting-label">Hydration Guard</label>
    <div class="setting-desc">This plugin is active by default and manages the initial application splash screen. No manual configuration is required.</div>
</div>
HTML;

// Inject the Shield immediately at the top of the body
$plugin_overlays[] = <<<'HTML'
<style>
    /* ZERO-FLASH SYSTEM: Hide the app frame while the guard is active */
    #app-frame { 
        opacity: 0; 
        transition: opacity 0.8s ease-in-out; 
        pointer-events: none;
    }
    body.hg-dismissed #app-frame { 
        opacity: 1; 
        pointer-events: auto;
    }

    #hg-shield {
        position: fixed; inset: 0;
        background: var(--bg-color);
        z-index: 999999;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        transition: opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.8s;
        pointer-events: auto;
        overflow: hidden;
        cursor: pointer;
    }

    #hg-shield.dismissed { opacity: 0; visibility: hidden; pointer-events: none; }

    .hg-content {
        position: relative; z-index: 2;
        display: flex; flex-direction: column;
        align-items: center; gap: 40px;
        transform: translateY(-20px);
        /* Elegant Fade-In for content */
        animation: hg-content-in 1.2s cubic-bezier(0.2, 0, 0.2, 1) forwards;
    }
    @keyframes hg-content-in {
        from { opacity: 0; transform: translateY(0px); }
        to { opacity: 1; transform: translateY(-20px); }
    }

    .hg-logo-wrap {
        position: relative;
        width: 120px; height: 80px;
        display: flex; align-items: center; justify-content: center;
    }
    
    .hg-logo { 
        width: 64px; height: 64px; 
        color: var(--primary); 
        filter: drop-shadow(0 0 25px color-mix(in srgb, var(--primary), transparent 40%)); 
    }

    .hg-logo-bar {
        fill: currentColor;
        stroke: none;
    }

    /* Staggered Animations targeting height and y to preserve rounded corners */
    .bar-outer { animation: hg-wave-outer 1.4s infinite ease-in-out; }
    .bar-inner { animation: hg-wave-inner 1.4s infinite ease-in-out; }
    .bar-center { animation: hg-wave-center 1.4s infinite ease-in-out; }

    @keyframes hg-wave-outer {
        0%, 100% { height: 2px; y: 11px; opacity: 0.6; }
        50% { height: 10px; y: 7px; opacity: 1; }
    }
    @keyframes hg-wave-inner {
        0%, 100% { height: 8px; y: 8px; opacity: 0.7; }
        50% { height: 16px; y: 4px; opacity: 1; }
    }
    @keyframes hg-wave-center {
        0%, 100% { height: 18px; y: 3px; opacity: 0.8; }
        50% { height: 22px; y: 1px; opacity: 1; }
    }

    .hg-status { display: flex; flex-direction: column; align-items: center; gap: 18px; width: 100%; }
    .hg-text {
        font-size: 48px; font-weight: 200; text-transform: uppercase;
        letter-spacing: 22px; color: var(--text-primary);
        margin-left: 22px; /* Offset for letter-spacing centering */
        opacity: 0.95;
        filter: drop-shadow(0 0 10px color-mix(in srgb, var(--text-primary), transparent 90%));
    }
    .hg-tagline {
        font-size: 11px; font-weight: 500; text-transform: uppercase;
        letter-spacing: 6px; color: var(--text-secondary);
        opacity: 0.4;
        margin-top: 4px;
    }

    /* Progress Bar */
    .hg-progress-wrap {
        width: 160px; height: 3px;
        background: rgba(0,0,0,0.05);
        border-radius: 10px; overflow: hidden;
        position: relative;
    }
    #hg-progress-bar {
        position: absolute; left: 0; top: 0; height: 100%;
        width: 0%; background: var(--primary);
        transition: width 0.4s cubic-bezier(0.1, 0.5, 0.1, 1);
        box-shadow: 0 0 10px var(--primary);
    }

    .hg-hint {
        position: absolute; bottom: 40px;
        font-size: 9px; font-weight: 700; text-transform: uppercase;
        color: var(--text-secondary); opacity: 0.4;
        letter-spacing: 1px;
    }
</style>

<div id="hg-shield" onclick="window._hgDismiss()">
    <div class="hg-content">
        <div class="hg-logo-wrap">
            <svg class="hg-logo" viewBox="0 0 24 24">
                <!-- Outer Left -->
                <rect class="hg-logo-bar bar-outer" x="2.75" width="2.5" rx="1.25" style="animation-delay: 0.0s;" />
                <!-- Inner Left -->
                <rect class="hg-logo-bar bar-inner" x="6.75" width="2.5" rx="1.25" style="animation-delay: 0.15s;" />
                <!-- Center -->
                <rect class="hg-logo-bar bar-center" x="10.75" width="2.5" rx="1.25" style="animation-delay: 0.3s;" />
                <!-- Inner Right -->
                <rect class="hg-logo-bar bar-inner" x="14.75" width="2.5" rx="1.25" style="animation-delay: 0.15s;" />
                <!-- Outer Right -->
                <rect class="hg-logo-bar bar-outer" x="18.75" width="2.5" rx="1.25" style="animation-delay: 0.0s;" />
            </svg>
        </div>
        <div class="hg-status">
            <div class="hg-text">Conjure</div>
            <div class="hg-progress-wrap"><div id="hg-progress-bar"></div></div>
            <div class="hg-tagline">Conjure Ideas Into Existence</div>
        </div>
    </div>
    <div class="hg-hint">Tap to Skip</div>
</div>

<script>
(function() {
    const shield = document.getElementById('hg-shield');
    const bar = document.getElementById('hg-progress-bar');
    const startTime = Date.now();
    
    // Config
    const minDisplayTime = 1200;
    const stabilityThreshold = 700;
    const maxSafetyTime = 7000;
    
    // Predictive Logic
    let mutationCount = 0;
    const lastTarget = parseInt(localStorage.getItem('cjos_hg_target') || '150');
    
    let lastMutationTime = Date.now();
    let checkInterval = null;

    window._hgDismiss = () => {
        if (checkInterval) clearInterval(checkInterval);
        localStorage.setItem('cjos_hg_target', mutationCount.toString());

        // 1. Force-settle initial viewport ScrollReveal elements behind the shield
        document.querySelectorAll('.sr-hidden, .sr-animating').forEach(el => {
            const rect = el.getBoundingClientRect();
            if (rect.top < window.innerHeight + 200) {
                el.classList.remove('sr-hidden', 'sr-animating');
                el.classList.add('sr-finished');
            }
        });

        // 2. Double rAF handshake guarantees browser paint pipeline is completely idle and stable
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                document.body.classList.add('hg-dismissed');
                shield.classList.add('dismissed');
                setTimeout(() => shield.remove(), 1000);
            });
        });
    };

    const observer = new MutationObserver((mutations) => {
        lastMutationTime = Date.now();
        mutationCount += mutations.length;
        
        // Update Progress Bar
        const pct = Math.min(99, Math.round((mutationCount / lastTarget) * 100));
        if (bar) bar.style.width = pct + '%';
    });

    observer.observe(document.body, { childList: true, subtree: true, attributes: true });

    checkInterval = setInterval(() => {
        const now = Date.now();
        const timeSinceStart = now - startTime;
        const timeSinceLastMutation = now - lastMutationTime;

        if (timeSinceStart >= minDisplayTime && timeSinceLastMutation >= stabilityThreshold) {
            if (bar) bar.style.width = '100%';
            observer.disconnect();
            window._hgDismiss();
        }

        if (timeSinceStart >= maxSafetyTime) {
            observer.disconnect();
            window._hgDismiss();
        }
    }, 100);
})();
</script>
HTML;
?>