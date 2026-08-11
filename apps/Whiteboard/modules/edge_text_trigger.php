<?php
/**
 * Whiteboard Edge Text Trigger Module
 * Detects drag from the top edge of the screen to spawn a text editor.
 */
?>
<script>
(function() {
    const EDGE_THRESHOLD = 40; // Pixels from the top edge

    window.addEventListener('pointerdown', (e) => {
    // We only care about finger touch (block mouse and pen)
    if (e.pointerType === 'mouse' || e.pointerType === 'pen') return;if (e.clientY <= EDGE_THRESHOLD) {
            // Ignore if we hit UI elements or if the gallery is open
            if (e.target.closest('.tool-btn') || 
                e.target.closest('.menu-btn') || 
                e.target.closest('#zoom-indicator') || 
                e.target.closest('.home-btn') || 
                e.target.closest('#gallery-view')) {
                return;
            }

            // Ensure app.js variables are ready
            if (typeof isDraggingTextInsertion === 'undefined' || typeof spawnTextEditor === 'undefined' || typeof getCanvasCoords === 'undefined') return;

            // Intercept the event so app.js doesn't start a drawing stroke or pan
            e.stopPropagation(); 
            
            if (typeof isInteracting !== 'undefined') isInteracting = true;
            
            // Emulate the viewport targeting logic from app.js
            const vpIndex = viewports.findIndex(v => v.canvas === e.target || (v.canvas.parentElement && v.canvas.parentElement.contains(e.target)));
            if (vpIndex !== -1) {
                activeViewportIndex = vpIndex;
                pointerToViewport.set(e.pointerId, vpIndex);
            }
            const vp = getActiveViewport();
            try { vp.canvas.setPointerCapture(e.pointerId); } catch(err) {}
            pointers.set(e.pointerId, e);

            // If there's an active text editor, commit it first
            if (typeof activeTextEditor !== 'undefined' && activeTextEditor) {
                commitText();
            }

            // Enter text insertion mode
            isDraggingTextInsertion = true;
            
            // Calculate world coordinates for the spawn point
            const coords = getCanvasCoords(e, vp);
            const offsetY = 100;
            const worldOffsetY = offsetY / vp.transform.scale;
            
            // Spawn the ghost editor above the finger
            spawnTextEditor(coords.x, coords.y - worldOffsetY, null, true);
            
            if (window.navigator.vibrate) navigator.vibrate(10);
        }
    }, { capture: true }); // Use capture phase to intercept before app.js
})();
</script>