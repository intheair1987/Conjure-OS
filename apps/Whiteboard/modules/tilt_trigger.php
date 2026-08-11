<?php
/**
 * Whiteboard Heavy Tilt Trigger Module
 * Detects when the Surface Pen is tilted below a specific altitude threshold
 * while touching the screen, activating an interaction overlay.
 */
?>
<div id="wb-tilt-overlay" style="
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(88, 86, 214, 0.15); backdrop-filter: blur(4px);
    z-index: 8000; display: none; align-items: center; justify-content: center;
    pointer-events: auto; transition: opacity 0.2s; opacity: 0;
">
    <div style="
        background: var(--card-bg); padding: 30px 40px; border-radius: 24px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3); border: 2px solid var(--primary-accent);
        text-align: center; transform: translateY(20px); transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    " id="wb-tilt-modal">
        <div style="width: 64px; height: 64px; background: rgba(88, 86, 214, 0.1); color: var(--primary-accent); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto;">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M12 19l7-7 3 3-7 7-3-3z"></path><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path><path d="M2 2l5 5"></path></svg>
        </div>
        <h2 style="margin:0 0 8px 0; color: var(--text-primary); font-size: 22px;">Heavy Tilt Detected</h2>
        <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 20px;">
            Altitude: <span id="wb-tilt-val" style="font-weight: 800; color: var(--primary-accent);">0</span>&deg; 
            (Threshold: <span id="wb-tilt-thresh-val">30</span>&deg;)
        </div>
        <div style="padding: 12px; background: var(--bg-color); border-radius: 12px; font-size: 12px; font-weight: 700; color: var(--text-primary);">
            Ready for alternative interaction.
        </div>
    </div>
</div>

<script>
(function() {
    window.tiltTriggerThreshold = 30; 
    window.isTiltActive = false;
    let activePenId = null;
    let confidenceCounter = 0;
    const CONFIDENCE_REQUIRED = 2; // Number of consecutive frames required to trigger
    
    const overlay = document.getElementById('wb-tilt-overlay');
    const modal = document.getElementById('wb-tilt-modal');
    const valDisp = document.getElementById('wb-tilt-val');
    const threshDisp = document.getElementById('wb-tilt-thresh-val');

    function checkTilt(e) {
        // 1. Strict Palm Rejection: Ignore everything that isn't the pen
        if (e.pointerType !== 'pen') return;
        
        // 2. Track the specific Pen ID to prevent multi-input confusion
        activePenId = e.pointerId;

        // 3. Tip-Down Check with Jitter Protection
        // If the pen is in range but not touching, or if it's the eraser/barrel, kill the trigger
        if (!(e.buttons & 1) || (e.buttons & 32)) {
            confidenceCounter = 0;
            if (isTiltActive) deactivate();
            return;
        }

        // 4. Calculate Altitude
        const tx = e.tiltX * (Math.PI / 180);
        const ty = e.tiltY * (Math.PI / 180);
        const tiltMag = Math.sqrt(Math.pow(Math.tan(tx), 2) + Math.pow(Math.tan(ty), 2));
        const altitude = Math.atan(1 / (tiltMag || 0.0001)) * (180 / Math.PI);

        // 5. Threshold Logic with Confidence Buffer
        if (altitude < window.tiltTriggerThreshold) {
            if (!isTiltActive) {
                confidenceCounter++;
                if (confidenceCounter >= CONFIDENCE_REQUIRED) {
                    activate(altitude);
                }
            } else {
                valDisp.innerText = Math.round(altitude);
            }
        } else {
            confidenceCounter = 0;
            if (isTiltActive) deactivate();
        }
    }

    function activate(alt, e) {
        isTiltActive = true;
        confidenceCounter = 0;

        const mode = window.tiltTriggerMode || 'modal';

        // Cancel the current drawing stroke UNLESS we are in Direct mode
        if (mode !== 'assign_direct') {
            if (typeof isDrawing !== 'undefined' && isDrawing) {
                isDrawing = false;
                currentStroke = null;
                if (typeof render === 'function') render();
            }
        }

        if (mode === 'modal') {
            valDisp.innerText = Math.round(alt);
            threshDisp.innerText = window.tiltTriggerThreshold;
            overlay.style.display = 'flex';
            void overlay.offsetWidth;
            overlay.style.opacity = '1';
            modal.style.transform = 'translateY(0)';
        } else if (mode === 'assign_switch') {
            if (typeof wbTiltToolActivate === 'function') wbTiltToolActivate(e, false);
        } else if (mode === 'assign_direct') {
            if (typeof wbTiltToolActivate === 'function') wbTiltToolActivate(e, true);
        } else if (mode === 'palette') {
            if (typeof wbTiltPaletteActivate === 'function') wbTiltPaletteActivate(e);
        }
        
        if (window.navigator.vibrate) navigator.vibrate(20);
    }

    function deactivate() {
        if (!isTiltActive) return;
        isTiltActive = false;
        
        const mode = window.tiltTriggerMode || 'modal';
        if (mode === 'modal') {
            overlay.style.opacity = '0';
            modal.style.transform = 'translateY(20px)';
            setTimeout(() => { if (!isTiltActive) overlay.style.display = 'none'; }, 200);
        } else if (mode === 'assign_switch' || mode === 'assign_direct') {
            if (typeof wbTiltToolDeactivate === 'function') wbTiltToolDeactivate();
        } else if (mode === 'palette') {
            if (typeof wbTiltPaletteDeactivate === 'function') wbTiltPaletteDeactivate();
        }
    }

    // Attach to window to catch everything globally
    window.addEventListener('pointerdown', checkTilt, { passive: true });
    window.addEventListener('pointermove', checkTilt, { passive: true });
    window.addEventListener('pointerup', () => { if (isTiltActive) deactivate(); }, { passive: true });
})();
</script>