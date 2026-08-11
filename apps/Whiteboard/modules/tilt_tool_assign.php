<?php
/**
 * Whiteboard Tilt: Direct Tool Assignment
 * Maps pen direction (Azimuth) to specific tools/colors.
 */
?>
<div id="wb-tilt-assign-ui" style="
    position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
    pointer-events: none; z-index: 8500; display: none; text-align: center;
">
    <div id="wb-assign-label" style="
        background: var(--primary-accent); color: white; padding: 6px 14px;
        border-radius: 10px; font-weight: 800; font-size: 11px; text-transform: uppercase;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15); letter-spacing: 1px;
        backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.2);
        opacity: 0.9;
    ">TOOL NAME</div>
</div>

<script>
(function() {
    const ui = document.getElementById('wb-tilt-assign-ui');
    const label = document.getElementById('wb-assign-label');
    let lastTool = null;
    let originalState = null;
    let isDirectMode = false;

    window.wbTiltToolActivate = function(e, direct) {
        isDirectMode = direct;
        if (isDirectMode) {
            // Save global state before overriding
            originalState = { 
                mode: typeof touchMode !== 'undefined' ? touchMode : 'draw', 
                color: typeof brushColor !== 'undefined' ? brushColor : '#000000' 
            };

            // LASSO CLEANUP: If entering from Lasso, wipe the dotted lines immediately
            if (originalState.mode === 'lasso') {
                lassoPoints = [];
                if (typeof viewports !== 'undefined') {
                    viewports.forEach(v => v.octx.clearRect(0, 0, v.overlay.width, v.overlay.height));
                }
            }
        }
        ui.style.display = 'block';
        wbTiltToolUpdate(e);
    };

    window.wbTiltToolUpdate = function(e) {
        if (ui.style.display === 'none') return;

        // Calculate Azimuth (Heading)
        const tx = e.tiltX * (Math.PI / 180);
        const ty = e.tiltY * (Math.PI / 180);
        let azimuth = Math.atan2(Math.sin(ty), Math.sin(tx)) * (180 / Math.PI);
        if (azimuth < 0) azimuth += 360;

        // Map Azimuth to Tools
        // 0/360 = Right, 90 = Down, 180 = Left, 270 = Up
        let tool = { name: "Pen", color: "#000000", mode: "draw", textColor: "white" };

        if (azimuth > 225 && azimuth <= 315) {
            tool = { name: "Eraser", color: null, mode: "erase", textColor: "white" };
        } else if (azimuth > 45 && azimuth <= 135) {
            tool = { name: "Highlighter", color: "#ffff00", mode: "highlight", textColor: "#000" };
        } else if (azimuth > 135 && azimuth <= 225) {
            tool = { name: "Black Pen", color: "#000000", mode: "draw", textColor: "white" };
        } else {
            tool = { name: "Red Pen", color: "#ff3b30", mode: "draw", textColor: "white" };
        }

        label.innerText = tool.name + (isDirectMode ? " (Direct)" : "");
        label.style.background = tool.color || "#555";
        label.style.color = tool.textColor;
        
        if (lastTool !== tool.name) {
            if (window.navigator.vibrate) navigator.vibrate(5);
            
            if (isDirectMode) {
                // Temporarily override global variables
                if (typeof touchMode !== 'undefined') touchMode = tool.mode;
                if (tool.color && typeof brushColor !== 'undefined') brushColor = tool.color;

                // STATE HANDOFF: If we were in Lasso mode, we need to flip the internal app flags
                if (originalState.mode === 'lasso' && isSelecting) {
                    isSelecting = false;
                    isDrawing = true;
                    lassoPoints = [];
                }
                
                // If we were in Text mode, ensure isDrawing is true so path logic executes
                if (originalState.mode === 'text') {
                    isDrawing = true;
                    // DISMISS TEXT EDITOR: If a box is open (empty or active), kill it immediately
                    if (typeof activeTextEditor !== 'undefined' && activeTextEditor) {
                        cancelText();
                    }

                    // INITIALIZE STROKE: Since app.js skipped this due to the guard, we do it here
                    // so the very first move event has a valid stroke to draw into.
                    const coords = getCanvasCoords(e);
                    const isHighlighter = (tool.mode === 'highlight');
                    currentStroke = {
                        id: wbCreateId(),
                        zIndex: wbGetNextZIndex(),
                        type: 'path',
                        color: tool.color || brushColor,
                        composite: isHighlighter ? 'multiply' : 'source-over',
                        opacity: isHighlighter ? 0.4 : 1.0,
                        isHighlighter: isHighlighter,
                        width: isHighlighter ? (brushWidth * 5) : brushWidth,
                        points: [{x: coords.x, y: coords.y, w: brushWidth}]
                    };
                }
                
                if (tool.mode === 'erase') {
                    // Stop drawing the current line so we can start erasing
                    if (typeof currentStroke !== 'undefined') currentStroke = null;
                    if (typeof render === 'function') render();
                } else {
                    // If we were just erasing and tilted to a pen tool, start a new stroke
                    if (isDrawing && !currentStroke) {
                        const coords = getCanvasCoords(e);
                        const isHighlighter = (tool.mode === 'highlight');
                        currentStroke = {
                            id: wbCreateId(),
                            zIndex: wbGetNextZIndex(),
                            type: 'path',
                            color: tool.color || brushColor,
                            composite: isHighlighter ? 'multiply' : 'source-over',
                            opacity: isHighlighter ? 0.4 : 1.0,
                            isHighlighter: isHighlighter,
                            width: isHighlighter ? (brushWidth * 5) : brushWidth,
                            points: [{x: coords.x, y: coords.y, w: brushWidth}]
                        };
                    }
                    // Mutate the active stroke in real-time so it changes color/style immediately
                    else if (currentStroke) {
                        currentStroke.color = tool.color || brushColor;
                        currentStroke.composite = tool.mode === 'highlight' ? 'multiply' : 'source-over';
                        currentStroke.opacity = tool.mode === 'highlight' ? 0.4 : 1.0;
                        currentStroke.isHighlighter = tool.mode === 'highlight';
                        currentStroke.width = currentStroke.isHighlighter ? (brushWidth * 5) : brushWidth;
                    }
                    if (typeof requestRender === 'function') requestRender();
                }
            } else {
                // Switch mode permanently
                if (tool.mode === 'erase') {
                    if (typeof setTouchMode === 'function') setTouchMode('erase', false);
                } else {
                    if (typeof setTouchMode === 'function') setTouchMode(tool.mode, false);
                    if (typeof setBrushColor === 'function') setBrushColor(tool.color, false);
                }
            }
            lastTool = tool.name;
        }
    };

    window.wbTiltToolDeactivate = function() {
        ui.style.display = 'none';
        lastTool = null;
        
        if (isDirectMode && originalState) {
            // Restore original state globally
            if (typeof touchMode !== 'undefined') touchMode = originalState.mode;
            if (typeof brushColor !== 'undefined') brushColor = originalState.color;

            // STATE RESTORE: Return to Lasso behavior if that was the base tool
            if (originalState.mode === 'lasso') {
                // Only resume selection if the pen is still touching (prevents hover-draw bug)
                const penStillDown = typeof isInteracting !== 'undefined' && isInteracting;
                isSelecting = penStillDown;
                isDrawing = false;
                if (typeof currentStroke !== 'undefined') currentStroke = null;
                
                // Clear points and overlay to ensure no ghosting
                lassoPoints = [];
                if (typeof viewports !== 'undefined') {
                    viewports.forEach(v => v.octx.clearRect(0, 0, v.overlay.width, v.overlay.height));
                }
            }
            
            // If we were erasing and tilted back up, start a new stroke for the original tool
            if (isDrawing && !currentStroke && originalState.mode !== 'erase') {
                // Note: We can't easily get the last event here, so we wait for the next pointermove
                // app.js will naturally handle this because isDrawing is true and touchMode is restored.
            }
            
            // Mutate the active stroke back to the original tool
            if (typeof currentStroke !== 'undefined' && currentStroke) {
                currentStroke.color = originalState.color;
                currentStroke.composite = originalState.mode === 'highlight' ? 'multiply' : (originalState.mode === 'erase' ? 'destination-out' : 'source-over');
                currentStroke.opacity = originalState.mode === 'highlight' ? 0.4 : 1.0;
                currentStroke.isHighlighter = originalState.mode === 'highlight';
                if (typeof requestRender === 'function') requestRender();
            }
            originalState = null;
        }
    };

    window.addEventListener('pointermove', (e) => {
        if (window.tiltTriggerMode === 'assign_switch' || window.tiltTriggerMode === 'assign_direct') {
            wbTiltToolUpdate(e);
        }
    }, { passive: true });
})();
</script>