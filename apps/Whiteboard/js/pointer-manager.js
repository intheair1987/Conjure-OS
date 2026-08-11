/**
 * WHITEBOARD POINTER MANAGER
 * Handles all canvas touch, pen, and mouse interactions.
 */

document.getElementById('canvas-container').addEventListener('pointerdown', (e) => {
    if (typeof wbLogPointerEvent === 'function') wbLogPointerEvent(e, 'down');

    // Auto-purge stale pointers where buttons are no longer pressed or invalid
    for (const [id, ev] of pointers.entries()) {
        if (ev.buttons === 0 && id !== e.pointerId) {
            pointers.delete(id);
            pointerToViewport.delete(id);
            pointerMeta.delete(id);
            ignoredPointers.delete(id);
        }
    }
    if (pointers.size === 0) {
        pointerToViewport.clear();
        ignoredPointers.clear();
        isDrawing = false;
        isPanning = false;
        isSelecting = false;
        isDraggingObject = false;
        isResizingSelection = false;
        isDraggingTextInsertion = false;
        isInteracting = false;
    }

    // Initialize Metadata
    pointerMeta.set(e.pointerId, { startX: e.clientX, startY: e.clientY, validated: false });

    // 1. Cluster-based Palm Rejection
    if (typeof checkPalm === 'function' && checkPalm(e)) return;
    if (ignoredPointers.has(e.pointerId)) return;

    // WebKit/Safari Fix: Prevent native gesture recognizers from eating rapid light Pencil strokes.
    if (e.pointerType !== 'mouse' && !e.target.closest('#wb-text-editor') && !e.target.closest('.resizer')) {
        e.preventDefault();
    }
    if (isResizing) return;
    isInteracting = true;
    const vpIndex = viewports.findIndex(v => v.canvas === e.target || v.canvas.parentElement.contains(e.target));
    if (vpIndex === -1) return;

    // A gesture must never span independent viewports. If another pointer is
    // already active in a different pane, consume this pointer without routing
    // it into the existing gesture or changing the active camera.
    const activeViewportIds = new Set(
        Array.from(pointerToViewport.values()).filter(index => index !== undefined && index < viewports.length)
    );
    if (activeViewportIds.size > 0 && !activeViewportIds.has(vpIndex)) {
        ignoredPointers.add(e.pointerId);
        return;
    }

    activeViewportIndex = vpIndex;
    pointerToViewport.set(e.pointerId, vpIndex);
    const vp = viewports[vpIndex];
    try { vp.canvas.setPointerCapture(e.pointerId); } catch(err) {}
    pointers.set(e.pointerId, e);
    const rect = vp.canvas.getBoundingClientRect();

    // --- DOUBLE TAP DETECTION (Pan Mode -> Text Edit) ---
    const now = Date.now();
    const tapDist = Math.hypot(e.clientX - lastTapPos.x, e.clientY - lastTapPos.y);
    const isDoubleTap = (now - lastTapTime < 300 && tapDist < 30);
    lastTapTime = now;
    lastTapPos = { x: e.clientX, y: e.clientY };

    if (isDoubleTap && touchMode === 'pan' && e.pointerType !== 'pen') {
        const coords = getCanvasCoords(e);
        const hitIdx = findStrokeAt(coords.x, coords.y, 15, ['text', 'blank']);
        if (hitIdx !== -1) {
            if (window.navigator.vibrate) navigator.vibrate([10, 30]);
            spawnTextEditor(allStrokes[hitIdx].x, allStrokes[hitIdx].y, allStrokes[hitIdx]);
            // Prevent pan logic from starting
            isPanning = false;
            pointers.delete(e.pointerId);
            if (vp.canvas.releasePointerCapture) vp.canvas.releasePointerCapture(e.pointerId);
            return;
        }
    }

    // Get pointers belonging to THIS viewport
    const sameVpPointers = Array.from(pointers.entries())
        .filter(([id, ev]) => pointerToViewport.get(id) === activeViewportIndex)
        .map(([id, ev]) => ev);

    if (sameVpPointers.length > 1) {
    clearTimeout(lpTimer);
    lpTimer = null;
    isDrawing = false;
    isSelecting = false;
    isPanning = false;
    isDraggingObject = false;
    pendingTextHit = null;
    pendingTextCommit = false;
    currentStroke = null;
        
    if (activeTextEditor) {
        if (isDraggingTextInsertion) {
            isDraggingTextInsertion = false;
            vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height);
            // Convert ghost to real editor so user can zoom/pan to position it
            activeTextEditor.el.innerText = "";
            activeTextEditor.el.style.opacity = "1";
            finalizeTextEditor(activeTextEditor);
        }
        // Removed cancelText() - editor now persists during multi-touch
    }
    if (sameVpPointers.length === 2) {
        const p = sameVpPointers;
        vp.gesture.lastPinchDist = Math.hypot(p[0].clientX - p[1].clientX, p[0].clientY - p[1].clientY);
        vp.gesture.lastPinchAngle = Math.atan2(p[1].clientY - p[0].clientY, p[1].clientX - p[0].clientX);
        vp.gesture.lastMidpoint = {
            x: ((p[0].clientX + p[1].clientX) / 2) - rect.left,
            y: ((p[0].clientY + p[1].clientY) / 2) - rect.top
        };
    }
    return;
}

// --- LONG PRESS LASSO SETUP (Finger Only) ---
lpStartPos = { x: e.clientX, y: e.clientY };
clearTimeout(lpTimer);
if (e.pointerType === 'touch') {
    lpTimer = setTimeout(() => {
        // Recalculate active pointers
        const activeCount = Array.from(pointers.entries()).filter(([id, ev]) => (ev.buttons > 0 || id === e.pointerId)).length;
            
        if (!isSelecting && !isDraggingObject && !isResizingSelection && activeCount === 1) {
            if (window.navigator.vibrate) navigator.vibrate(15);
                
            if (activeTextEditor) cancelText();

            // Transition from potential Pan/Draw to Lasso
            isPanning = false; 
            isDrawing = false;
            currentStroke = null; 
            isSelecting = true;
                
            const coords = getCanvasCoords(e);
            lassoPoints = [coords];
            
            // Reset smoothing anchors to current position to prevent "rush back" jumps
            lastX = coords.x;
            lastY = coords.y;
                
            // UI Polish: Hide selection menu and update status
            document.getElementById('selection-menu').style.display = 'none';
            const pill = document.getElementById('status-pill');
            pill.innerText = "Lasso Mode";
            pill.style.opacity = "1";
            setTimeout(() => { if(pill.innerText === "Lasso Mode") pill.style.opacity = "0"; }, 1000);
                
            requestRender();
        }
    }, 500);
}

// Direct Manipulation Check: Are we touching a handle or a selected object?
// Stylus Isolation: Pens cannot move or resize text/grammar boxes.
const hasTextInSelection = Array.from(wbSelection.ids).some(id => {
    const s = allStrokes.find(st => st.id === id);
    return s && (s.type === 'text' || s.type === 'blank');
});

if (wbSelection.ids.size > 0 && wbSelection.bounds && !(e.pointerType === 'pen' && hasTextInSelection)) {
    const coords = getCanvasCoords(e);
    const b = wbSelection.bounds;
    const threshold = 20 / vp.transform.scale;

    // Check Handles first
    const handles = {
        nw: {x: b.x, y: b.y},
        ne: {x: b.x + b.w, y: b.y},
        sw: {x: b.x, y: b.y + b.h},
        se: {x: b.x + b.w, y: b.y + b.h}
    };

    for (let key in handles) {
        const h = handles[key];
        if (Math.hypot(coords.x - h.x, coords.y - h.y) < threshold) {
            isResizingSelection = true;
            activeHandle = key;
            wbSelection.initialStrokes = JSON.parse(JSON.stringify(allStrokes.filter(s => wbSelection.ids.has(s.id))));
            wbSelection.initialBounds = {...b};
            document.getElementById('selection-menu').style.display = 'none';
            return;
        }
    }

    const isInside = (coords.x >= b.x && coords.x <= b.x + b.w &&
                      coords.y >= b.y && coords.y <= b.y + b.h);
        
    if (isInside) {
        isDraggingObject = true;
        dragOffset = { x: coords.x - b.x, y: coords.y - b.y };
        // Hide UI during drag for better visibility
        document.getElementById('selection-menu').style.display = 'none';
        wbUpdateSelectionUI(false);
        return;
    }
}

// --- PEN ONLY MODE ROUTING ---
if (penOnlyMode && e.pointerType === 'touch') {
    // Strict Palm Rejection: If the pen was recently active or is hovering, ignore the finger
    if (isPenActive) return;

    isPanning = true;
    lastMidpoint = { x: e.clientX - rect.left, y: e.clientY - rect.top };

    // UI Polish: Hide selection menu so it doesn't float while we pan
    document.getElementById('selection-menu').style.display = 'none';

    // Feedback: If a drawing tool is selected, remind the user why they are panning
    if (touchMode !== 'pan') {
        const pill = document.getElementById('status-pill');
        pill.innerText = "Navigation Mode";
        pill.style.opacity = "1";
        setTimeout(() => { if(pill.innerText === "Navigation Mode") pill.style.opacity = "0"; }, 1000);
    }
    return;
}if (e.pointerType === 'pen') isPenActive = true;
    else if (e.pointerType === 'touch' && isPenActive) return;

    // Snapshot state before any potential changes occur
    interactionSnapshot = JSON.stringify(allStrokes);
    hasChangedDuringInteraction = false;

    // Initialize smoothing coordinates immediately to prevent "needle" jumps
    const initialCoords = getCanvasCoords(e);
    [lastX, lastY] = [initialCoords.x, initialCoords.y];



    // Text Tool Logic (Bypass if side button is held for Lasso OR if hardware eraser is used)
if (touchMode === 'text' && !(e.buttons & 2) && !(e.buttons & 32) && e.button !== 5) {
    // IMMEDIATE TILT GUARD
    if (e.pointerType === 'pen') {
        const tx = e.tiltX * (Math.PI / 180);
        const ty = e.tiltY * (Math.PI / 180);
        const tiltMag = Math.sqrt(Math.pow(Math.tan(tx), 2) + Math.pow(Math.tan(ty), 2));
        const altitude = Math.atan(1 / (tiltMag || 0.0001)) * (180 / Math.PI);
        if (altitude < (window.tiltTriggerThreshold || 30)) return; 
    }
    if (window.isTiltActive) return; 

    if (activeTextEditor) {
        if (e.target.closest('#wb-text-editor') || e.target.closest('.wb-text-handle')) return;
        
        // PERSISTENCE: Tapping the canvas while editing now triggers PANNING instead of committing.
        // This allows the user to adjust their view without losing their typing progress.
        isPanning = true;
        lastMidpoint = { x: e.clientX - rect.left, y: e.clientY - rect.top };
        return;
    }

const coords = getCanvasCoords(e);
const hitIdx = findStrokeAt(coords.x, coords.y, 15, ['text', 'blank']);
const hitStroke = hitIdx !== -1 ? allStrokes[hitIdx] : null;

if (hitStroke) {
    pendingTextHit = hitStroke;
    return;
}

isDraggingTextInsertion = true;
const isTouch = e.pointerType === 'touch' || e.pointerType === 'pen';
const offsetY = isTouch ? 100 : 0;

// Multi-Viewport Fix: Update active viewport based on ghost position
const targetVpIdx = window.wbGetViewportIndexAt(e.clientX, e.clientY - offsetY);
if (targetVpIdx !== -1 && targetVpIdx !== activeViewportIndex) {
    activeViewportIndex = targetVpIdx;
}

const activeVp = getActiveViewport();
const spawnCoords = getCanvasCoords(e, activeVp);
const worldOffsetY = offsetY / activeVp.transform.scale;
spawnTextEditor(spawnCoords.x, spawnCoords.y - worldOffsetY, null, true);
    if (activeTextEditor) activeTextEditor.horizontalVisibilityOnly = true;
return;
    }// Single-finger Pan Mode (or forced by Pen Only mode)
const isTouch = e.pointerType === 'touch';
const forcePan = (penOnlyMode && isTouch);

if (forcePan || (isTouch && touchMode === 'pan')) {
isPanning = true;
lastMidpoint = { x: e.clientX - rect.left, y: e.clientY - rect.top };
return;
}const coords = initialCoords; // Use the coords we calculated at the top
    
    // Determine if we are starting a lasso (Bypass if hardware eraser is used)
    const isLassoStart = ((e.buttons & 2) || (touchMode === 'lasso')) && !(e.buttons & 32) && e.button !== 5;
    
    if (isLassoStart) {
        isSelecting = true;
        lassoPoints = [coords];
        return;
    }

    isDrawing = true;
    lassoPoints = [coords];
    isStraightened = false;
    if (straightenTimer) clearTimeout(straightenTimer);
    
    const isEraser = (e.buttons & 32) || (e.button === 5) || (touchMode === 'erase');
    const isHighlighter = (touchMode === 'highlight');
    const pressure = e.pressure || 0.5;
    
    if (isEraser) {
        // Immediately check for a hit on touch-down (using tight 0.5px threshold)
        const hitIdx = findStrokeAt(coords.x, coords.y, 0.5);
        // Brush eraser targets ink paths, text, and blanks with high precision
        if (hitIdx !== -1 && (allStrokes[hitIdx].type === 'path' || allStrokes[hitIdx].type === 'text' || allStrokes[hitIdx].type === 'blank')) {
            // Use the new snapshot system
            wbPushUndo(interactionSnapshot);
            window.wbDeletedStrokeIds.add(allStrokes[hitIdx].id);
            allStrokes.splice(hitIdx, 1);
            render();
            if (autoSaveEnabled) saveDrawing();
            if (window.navigator.vibrate) navigator.vibrate(5);
        }
        return; // Don't create a currentStroke for erasers
    }

    currentStroke = {
        id: wbCreateId(),
        zIndex: wbGetNextZIndex(),
        type: 'path',
        color: brushColor,
        composite: isHighlighter ? 'multiply' : 'source-over',
        opacity: isHighlighter ? 0.4 : 1.0,
        isHighlighter: isHighlighter,
        width: isHighlighter ? (brushWidth * 5) : brushWidth,
        points: [{x: coords.x, y: coords.y, w: brushWidth * (0.4 + pressure)}]
    };
});



window.addEventListener('pointermove', (e) => {
    if (typeof wbLogPointerEvent === 'function') wbLogPointerEvent(e, 'move');
    
    // Update Validation State
    const meta = pointerMeta.get(e.pointerId);
    if (meta && !meta.validated) {
        const dist = Math.hypot(e.clientX - meta.startX, e.clientY - meta.startY);
        if (dist > 30) meta.validated = true; // Touch is moving, likely a finger
    }

    // Cluster-based Palm Rejection
    if (typeof checkPalm === 'function' && checkPalm(e)) return;
    if (ignoredPointers.has(e.pointerId)) return;

    // Movement Threshold: If finger moves > 10px, cancel the long-press lasso trigger
    if (lpTimer && Math.hypot(e.clientX - lpStartPos.x, e.clientY - lpStartPos.y) > 10) {
        clearTimeout(lpTimer);
        lpTimer = null;
    }

    const vpIndex = pointerToViewport.get(e.pointerId);
    if (vpIndex === undefined || !viewports[vpIndex]) return;
    activeViewportIndex = vpIndex;
    const vp = viewports[vpIndex];
    const rect = vp.canvas.getBoundingClientRect();
    pointers.set(e.pointerId, e);

    // Get pointers belonging to THIS viewport
    const sameVpPointers = Array.from(pointers.entries())
        .filter(([id, ev]) => pointerToViewport.get(id) === activeViewportIndex)
        .map(([id, ev]) => ev);

    if (sameVpPointers.length > 1) {
        if (sameVpPointers.length === 2 && e.pointerType === 'touch') {
            const p = sameVpPointers;
            
            const mx = ((p[0].clientX + p[1].clientX) / 2) - rect.left;
            const my = ((p[0].clientY + p[1].clientY) / 2) - rect.top;

            const cp = getCanvasCoords({ clientX: vp.gesture.lastMidpoint.x + rect.left, clientY: vp.gesture.lastMidpoint.y + rect.top }, vp);

            const dist = Math.hypot(p[0].clientX - p[1].clientX, p[0].clientY - p[1].clientY);
            const angle = Math.atan2(p[1].clientY - p[0].clientY, p[1].clientX - p[0].clientX);
            
            if (vp.gesture.lastPinchDist > 0) {
                const zoomFactor = dist / vp.gesture.lastPinchDist;
                vp.transform.scale = Math.min(Math.max(vp.transform.scale * zoomFactor, 0.01), 100);
                
                if (rotationEnabled) {
                    vp.transform.rotation += (angle - vp.gesture.lastPinchAngle);
                }
            }

            const cos = Math.cos(vp.transform.rotation);
            const sin = Math.sin(vp.transform.rotation);
            const rx = (cp.x * vp.transform.scale) * cos - (cp.y * vp.transform.scale) * sin;
            const ry = (cp.x * vp.transform.scale) * sin + (cp.y * vp.transform.scale) * cos;
            
            vp.transform.x = mx - rx;
            vp.transform.y = my - ry;
            
            vp.gesture.lastPinchDist = dist;
            vp.gesture.lastPinchAngle = angle;
            vp.gesture.lastMidpoint = { x: mx, y: my };
            requestRender();
        }
        return;
    }

    if (isDraggingTextInsertion) {
        const isTouch = e.pointerType === 'touch' || e.pointerType === 'pen';
        const offsetY = isTouch ? 100 : 0;

        // Multi-Viewport Fix: Update active viewport based on ghost position
        const targetVpIdx = window.wbGetViewportIndexAt(e.clientX, e.clientY - offsetY);
        if (targetVpIdx !== -1 && targetVpIdx !== activeViewportIndex) {
            activeViewportIndex = targetVpIdx;
        }

        const vp = getActiveViewport(); // Shadows outer vp reference
        const coords = getCanvasCoords(e, vp);
        const worldOffsetY = offsetY / vp.transform.scale;
        
        if (activeTextEditor) {
            let targetX = coords.x;
            let targetY = coords.y - worldOffsetY;

            // --- MAGNETIC SNAPPING (Edge-to-Edge) ---
            const threshold = 12 / vp.transform.scale;
            let snapped = false;
            const mH = activeTextEditor.fontSize * 1.2;

            for (const s of allStrokes) {
                if (!['text', 'image', 'pdf_page', 'docx_page'].includes(s.type)) continue;
                const b = s._b || wbCalculateStrokeBounds(s);
                
                // Snap X (Left-to-Left)
                if (Math.abs(targetX - b.x) < threshold) { targetX = b.x; snapped = true; }

                // Snap Y (Top-to-Top, Bottom-to-Bottom)
                if (Math.abs(targetY - b.y) < threshold) { targetY = b.y; snapped = true; }
                else if (Math.abs((targetY + mH) - (b.y + b.h)) < threshold) { targetY = b.y + b.h - mH; snapped = true; }
            }

            if (snapped && !window._wbLastInsertSnap) {
                if (window.navigator.vibrate) navigator.vibrate(5);
                window._wbLastInsertSnap = true;
            } else if (!snapped) {
                window._wbLastInsertSnap = false;
            }

            activeTextEditor.worldX = targetX;
            activeTextEditor.worldY = targetY;
            updateEditorPosition();
            updateTextInsertionGuides();
        }
        return;
    }

    // Priority 0: Scaling
    if (isResizingSelection) {
        const coords = getCanvasCoords(e);
        const b = wbSelection.initialBounds;
        
        // Custom Horizontal Resize for Grammar Blanks
        const isSingleBlank = wbSelection.ids.size === 1 && allStrokes.find(s => s.id === Array.from(wbSelection.ids)[0])?.type === 'blank';
        if (isSingleBlank) {
            const blankStroke = allStrokes.find(s => s.id === Array.from(wbSelection.ids)[0]);
            const initial = wbSelection.initialStrokes[0];
            
            let newX = initial.x;
            let newW = initial.w;

            if (activeHandle.includes('w')) {
                newX = coords.x;
                newW = (initial.x + initial.w) - coords.x;
            } else {
                newW = coords.x - initial.x;
            }
            
            if (newW < 20) {
                if (activeHandle.includes('w')) newX = (initial.x + initial.w) - 20;
                newW = 20;
            }
            
            blankStroke.x = newX;
            blankStroke.w = newW;
            blankStroke.minW = newW;
            delete blankStroke._b;
            delete blankStroke._cache;
            
            const nb = wbCalculateStrokeBounds(blankStroke);
            wbSelection.bounds = { x: nb.x, y: nb.y, w: nb.w, h: nb.h };
            requestRender();
            return;
        }

        let origin = {};
        let scaleX = 1, scaleY = 1;

        if (activeHandle === 'se') { origin = {x: b.x, y: b.y}; }
        else if (activeHandle === 'sw') { origin = {x: b.x + b.w, y: b.y}; }
        else if (activeHandle === 'ne') { origin = {x: b.x, y: b.y + b.h}; }
        else if (activeHandle === 'nw') { origin = {x: b.x + b.w, y: b.y + b.h}; }

        scaleX = (coords.x - origin.x) / (activeHandle.includes('w') ? -b.w : b.w);
        scaleY = (coords.y - origin.y) / (activeHandle.includes('n') ? -b.h : b.h);

        // Aspect Ratio Lock (Uniform Scaling)
        const scale = Math.max(0.1, (Math.abs(scaleX) + Math.abs(scaleY)) / 2) * (scaleX < 0 ? -1 : 1);

        allStrokes.forEach(s => {
            if (wbSelection.ids.has(s.id)) {
                const initial = wbSelection.initialStrokes.find(is => is.id === s.id);
                if (s.type === 'path') {
                    s.points = initial.points.map(p => ({
                        x: origin.x + (p.x - origin.x) * scale,
                        y: origin.y + (p.y - origin.y) * scale,
                        w: p.w * Math.abs(scale)
                    }));
                } else if (s.type === 'text') {
                    s.x = origin.x + (initial.x - origin.x) * scale;
                    s.y = origin.y + (initial.y - origin.y) * scale;
                    s.fontSize = initial.fontSize * Math.abs(scale);
                } else if (s.type === 'image') {
                    s.x = origin.x + (initial.x - origin.x) * scale;
                    s.y = origin.y + (initial.y - origin.y) * scale;
                    s.w = initial.w * Math.abs(scale);
                    s.h = initial.h * Math.abs(scale);
                } else if (s.type === 'blank') {
                    s.x = origin.x + (initial.x - origin.x) * scale;
                    s.y = origin.y + (initial.y - origin.y) * scale;
                    s.w = initial.w * Math.abs(scale);
                }
                delete s._b; // Force recalculation of cached bounds
                delete s._cache; // Force recreation of raster cache
            }
        });

        // Update current bounds for rendering
        wbSelection.bounds = {
            x: origin.x + (activeHandle.includes('w') ? (coords.x - origin.x) : 0),
            y: origin.y + (activeHandle.includes('n') ? (coords.y - origin.y) : 0),
            w: b.w * Math.abs(scale),
            h: b.h * Math.abs(scale)
        };
        
        requestRender();
        return;
    }

    // Priority 1: Direct Manipulation Drag
    if (isDraggingObject) {
        const coords = getCanvasCoords(e);
        let targetX = coords.x - dragOffset.x;
        let targetY = coords.y - dragOffset.y;

        // --- MAGNETIC SNAPPING (Edge-to-Edge) ---
        const threshold = 12 / vp.transform.scale;
        let snapped = false;
        const mW = wbSelection.bounds.w;
        const mH = wbSelection.bounds.h;

        const selectedStrokes = allStrokes.filter(s => wbSelection.ids.has(s.id));
const isSingleBaselineObj = selectedStrokes.length === 1 && (selectedStrokes[0].type === 'blank' || selectedStrokes[0].type === 'text');
const draggedBaselineOffset = isSingleBaselineObj ? 
    (selectedStrokes[0].type === 'blank' ? selectedStrokes[0].y - wbSelection.bounds.y : (selectedStrokes[0].y + (selectedStrokes[0].fontSize || 24)) - wbSelection.bounds.y) 
    : 0;
                
let targetBaselineY = targetY + draggedBaselineOffset;

for (const s of allStrokes) {
    if (wbSelection.ids.has(s.id)) continue;
    if (!['text', 'blank', 'image', 'pdf_page', 'docx_page'].includes(s.type)) continue;

    const b = s._b || wbCalculateStrokeBounds(s);
                
    // Snap X (Left-to-Left, Right-to-Right)
    if (Math.abs(targetX - b.x) < threshold) { targetX = b.x; snapped = true; }
    else if (Math.abs((targetX + mW) - (b.x + b.w)) < threshold) { targetX = b.x + b.w - mW; snapped = true; }

    // Snap Y
    if (isSingleBaselineObj && (s.type === 'blank' || s.type === 'text')) {
        // Baseline to Baseline snapping
        let snapBaselineY = b.y;
        if (s.type === 'blank') snapBaselineY = s.y;
        else if (s.type === 'text') snapBaselineY = s.y + (s.fontSize || 24);

        if (Math.abs(targetBaselineY - snapBaselineY) < threshold) { 
            targetY = snapBaselineY - draggedBaselineOffset; 
            targetBaselineY = snapBaselineY; // Update for subsequent checks in loop
            snapped = true; 
        }
    } else {
        // Standard Bounding Box Snapping (Top-to-Top, Bottom-to-Bottom)
        if (Math.abs(targetY - b.y) < threshold) { targetY = b.y; snapped = true; }
        else if (Math.abs((targetY + mH) - (b.y + b.h)) < threshold) { targetY = b.y + b.h - mH; snapped = true; }
    }
}if (snapped && !window._wbLastSnap) {
            if (window.navigator.vibrate) navigator.vibrate(5);
            window._wbLastSnap = true;
        } else if (!snapped) {
            window._wbLastSnap = false;
        }

        const dx = targetX - wbSelection.bounds.x;
        const dy = targetY - wbSelection.bounds.y;
        
        wbSelection.bounds.x += dx;
        wbSelection.bounds.y += dy;

        // Apply offset to actual strokes in real-time for 1:1 feel
        allStrokes.forEach(s => {
    if (wbSelection.ids.has(s.id)) {
        if (s.type === 'path') {
            s.points.forEach(p => { p.x += dx; p.y += dy; });
        } else {
            s.x += dx; s.y += dy;
        }
        delete s._b; // Force recalculation of bounds on next render
        delete s._cache; // Force recreation of raster cache
    }
});requestRender();
        return;
    }

// Priority 2: Single-finger Pan Logic
// Resiliency: If isPanning is true (set by tool or Pen Only mode), perform the movement
if (isPanning && sameVpPointers.length === 1 && e.pointerType === 'touch') {
    const mx = e.clientX - rect.left;
        const my = e.clientY - rect.top;
        vp.transform.x += (mx - lastMidpoint.x);
        vp.transform.y += (my - lastMidpoint.y);
        lastMidpoint = { x: mx, y: my };
        requestRender();
        return;
    }
    if (!isDrawing && !isSelecting) return;
    if (isPenActive && e.pointerType === 'touch') return;

    const rawCoords = getCanvasCoords(e);
    
    // Apply Input Smoothing (Weighted Average)
    const smoothing = 0.4; // 0 = raw, 1 = maximum lag/smoothness
    const coords = {
        x: lastX * smoothing + rawCoords.x * (1 - smoothing),
        y: lastY * smoothing + rawCoords.y * (1 - smoothing)
    };

    const isLassoActive = (isSelecting || (e.buttons & 2) || (touchMode === 'lasso')) && !(e.buttons & 32) && e.button !== 5;
    const isEraser = (e.buttons & 32) || (e.button === 5) || (touchMode === 'erase');

    if (isLassoActive) {
        isSelecting = true;
        lassoPoints.push(coords);
        requestRender();
    } else if (isEraser) {
        // Interpolation Logic: Check multiple points between last and current position
        const dist = Math.hypot(coords.x - lastX, coords.y - lastY);
        const stepSize = 4; // Check every 4 pixels (safe now that we check segments)
        const steps = Math.max(1, Math.ceil(dist / stepSize));

        for (let i = 0; i <= steps; i++) {
            const interX = lastX + (coords.x - lastX) * (i / steps);
            const interY = lastY + (coords.y - lastY) * (i / steps);
            
            const hitIdx = findStrokeAt(interX, interY, 2.0);
            if (hitIdx !== -1 && (allStrokes[hitIdx].type === 'path' || allStrokes[hitIdx].type === 'text' || allStrokes[hitIdx].type === 'blank')) {
                window.wbDeletedStrokeIds.add(allStrokes[hitIdx].id);
                allStrokes.splice(hitIdx, 1);
                hasChangedDuringInteraction = true;
                requestRender();
                if (window.navigator.vibrate) navigator.vibrate(5);
            }
        }
    } else {
        const pressure = e.pressure || 0.5;
        const width = currentStroke.isHighlighter ? currentStroke.width : (currentStroke.composite === 'destination-out' ? 30 : (brushWidth * (0.4 + pressure)));
        
        if (isStraightened) {
            // AXIS LOCK: Force the line to be perfectly horizontal or vertical
            const start = currentStroke.points[0];
            const dx = Math.abs(coords.x - start.x);
            const dy = Math.abs(coords.y - start.y);

            if (dx > dy) {
                // Horizontal Snap
                currentStroke.points = [start, {x: coords.x, y: start.y, w: width}];
            } else {
                // Vertical Snap
                currentStroke.points = [start, {x: start.x, y: coords.y, w: width}];
            }
            requestRender();
        } else {
            const lastP = currentStroke.points[currentStroke.points.length - 1];
            const dist = Math.hypot(coords.x - lastP.x, coords.y - lastP.y);
            
            // Hold Detection: If movement is minimal, don't clear the timer
            if (dist > 2.0 / vp.transform.scale) {
                clearTimeout(straightenTimer);
                straightenTimer = setTimeout(() => {
                    if (!isDrawing || !currentStroke || currentStroke.points.length < 10) return;
                    
                    // Calculate Straightness Coefficient (Displacement / Path Length)
                    const pts = currentStroke.points;
                    const start = pts[0];
                    const end = pts[pts.length - 1];
                    const displacement = Math.hypot(end.x - start.x, end.y - start.y);
                    
                    let pathLength = 0;
                    for (let i = 1; i < pts.length; i++) {
                        pathLength += Math.hypot(pts[i].x - pts[i-1].x, pts[i].y - pts[i-1].y);
                    }

                    // If the line is > 80% straight, snap it
                    if (displacement / pathLength > 0.8) {
                        isStraightened = true;
                        
                        // Initial Axis Determination
                        const dx = Math.abs(end.x - start.x);
                        const dy = Math.abs(end.y - start.y);
                        if (dx > dy) {
                            currentStroke.points = [start, {x: end.x, y: start.y, w: end.w}];
                        } else {
                            currentStroke.points = [start, {x: start.x, y: end.y, w: end.w}];
                        }

                        if (window.navigator.vibrate) navigator.vibrate(15);
                        requestRender();
                    }
                }, 600);
            }

            if (dist > 1.5 / vp.transform.scale) {
                currentStroke.points.push({x: coords.x, y: coords.y, w: width});
                requestRender();
            }
        }
    }
    [lastX, lastY] = [coords.x, coords.y];
});

window.addEventListener('pointerup', (e) => {
    if (typeof wbLogPointerEvent === 'function') wbLogPointerEvent(e, 'up');
    if (typeof activePalms !== 'undefined') activePalms.delete(e.pointerId);
    pointerMeta.delete(e.pointerId);
    if (ignoredPointers.has(e.pointerId)) {
        ignoredPointers.delete(e.pointerId);
        return;
    }
    clearTimeout(lpTimer);
    lpTimer = null;

    const assignedVpIndex = pointerToViewport.get(e.pointerId);
    const vp = assignedVpIndex !== undefined && viewports[assignedVpIndex]
        ? viewports[assignedVpIndex]
        : getActiveViewport();
    if (assignedVpIndex !== undefined) activeViewportIndex = assignedVpIndex;

    try { vp.canvas.releasePointerCapture(e.pointerId); } catch(err) {}
    pointers.delete(e.pointerId);
    pointerToViewport.delete(e.pointerId);
    
    const sameVpPointers = Array.from(pointers.entries())
        .filter(([id, ev]) => pointerToViewport.get(id) === activeViewportIndex);
    if (sameVpPointers.length < 2) vp.gesture.lastPinchDist = 0;
    
    isPanning = false;
    
    if (isResizingSelection || isDraggingObject) {
        isResizingSelection = false;
        isDraggingObject = false;
        activeHandle = null;
        
        wbUpdateSelectionUI(true);
        if (autoSaveEnabled) saveDrawing();
        return;
    }

    // Removed pendingTextCommit handler to ensure persistence

    if (pendingTextHit) {
        const hit = pendingTextHit;
        pendingTextHit = null;
        spawnTextEditor(hit.x, hit.y, hit);
        return;
    }

    if (isMoving) { 
        return; 
    }
    if (isDraggingTextInsertion) {
        isDraggingTextInsertion = false;
        viewports.forEach(v => v.octx.clearRect(0, 0, v.overlay.width, v.overlay.height));
        if (activeTextEditor) {
            activeTextEditor.el.innerText = "";
            activeTextEditor.el.style.opacity = "1";
            finalizeTextEditor(activeTextEditor);
        }
        return;
    }
    if (isDrawing && currentStroke && !isSelecting) {
        viewports.forEach(v => v.octx.clearRect(0, 0, v.overlay.width, v.overlay.height));
        
        // Commit the state BEFORE the stroke was added to history
        wbPushUndo(interactionSnapshot);
        
        allStrokes.push(currentStroke);
currentStroke = null;
render(); 
wbUpdateHashUI(); // Trigger only on change
if (autoSaveEnabled) saveDrawing();} else if (hasChangedDuringInteraction) {
        // Commit the state BEFORE the Move/Scale/Erasure started
        wbPushUndo(interactionSnapshot);
        if (autoSaveEnabled) saveDrawing();
    }
    isDrawing = false;
    isInteracting = false;
    if (e.pointerType === 'pen') isPenActive = false;
    
    // Clear "Navigation Mode" pill on release
    const pill = document.getElementById('status-pill');
    if (pill.innerText === "Navigation Mode") pill.style.opacity = "0";

    if (isSelecting) { finalizeSelection(e.clientX, e.clientY, e.pointerType); isSelecting = false; }
    requestRender(); // Final high-fidelity render, including the paper pattern
    render();
    if (typeof saveViewState === 'function') saveViewState();
});

document.getElementById('canvas-container').addEventListener('wheel', (e) => {
    const vpIndex = viewports.findIndex(v => v.canvas === e.target || v.canvas.parentElement.contains(e.target));
    if (vpIndex !== -1) activeViewportIndex = vpIndex;
    const vp = getActiveViewport();
    e.preventDefault();
    const zoomSpeed = 0.001;
    const delta = -e.deltaY;
    const newScale = Math.min(Math.max(vp.transform.scale + delta * zoomSpeed, 0.01), 100);
    const midX = e.offsetX;
    const midY = e.offsetY;
    vp.transform.x -= (midX - vp.transform.x) * (newScale / vp.transform.scale - 1);
    vp.transform.y -= (midY - vp.transform.y) * (newScale / vp.transform.scale - 1);
    vp.transform.scale = newScale;
    render();
    if (typeof saveViewState === 'function') saveViewState();
}, { passive: false });

window.addEventListener('pointercancel', (e) => {
    if (typeof activePalms !== 'undefined') activePalms.delete(e.pointerId);
    ignoredPointers.delete(e.pointerId);
    const assignedVpIndex = pointerToViewport.get(e.pointerId);
    const vp = assignedVpIndex !== undefined && viewports[assignedVpIndex]
        ? viewports[assignedVpIndex]
        : getActiveViewport();
    if (assignedVpIndex !== undefined) activeViewportIndex = assignedVpIndex;
    pointers.delete(e.pointerId);
    pointerToViewport.delete(e.pointerId);
    if (pointers.size < 2) vp.gesture.lastPinchDist = 0;
    isPanning = false;
    isDraggingObject = false;
    isDrawing = false;
    isSelecting = false;
    if (e.pointerType === 'pen') isPenActive = false;
    pendingTextHit = null;
    pendingTextCommit = false;
    if (isDraggingTextInsertion) {
        isDraggingTextInsertion = false;
        vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height);
        if (activeTextEditor) cancelText();
    }
});

document.getElementById('canvas-container').addEventListener('pointerleave', (e) => {
    // Pointer capture keeps an active stroke or gesture alive even when the
    // contact temporarily crosses the canvas boundary. Do not cancel it here.
    const assignedVpIndex = pointerToViewport.get(e.pointerId);
    const vp = assignedVpIndex !== undefined && viewports[assignedVpIndex]
        ? viewports[assignedVpIndex]
        : null;

    if (vp && vp.canvas.hasPointerCapture && vp.canvas.hasPointerCapture(e.pointerId)) {
        return;
    }

    if (typeof activePalms !== 'undefined') activePalms.delete(e.pointerId);
    if (assignedVpIndex !== undefined) activeViewportIndex = assignedVpIndex;
    if (vp) vp.gesture.lastPinchDist = 0;
    pointers.delete(e.pointerId);
    pointerToViewport.delete(e.pointerId);
    isDrawing = false;
    isPenActive = false;

    if (isDraggingTextInsertion && vp) {
        isDraggingTextInsertion = false;
        vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height);
        requestRender();
    }
});

// Safari Apple Pencil Bug Fix: Force disable all native gesture delays
document.getElementById('canvas-container').addEventListener('touchstart', (e) => {
    if (!e.target.closest('#wb-text-editor') && !e.target.closest('.resizer')) {
        e.preventDefault();
    }
}, { passive: false });

window.resetPointerState = function() {
    pointers.clear();
    pointerToViewport.clear();
    pointerMeta.clear();
    ignoredPointers.clear();
    if (typeof activePalms !== 'undefined') activePalms.clear();
    isDrawing = false;
    isPanning = false;
    isSelecting = false;
    isMoving = false;
    isDraggingObject = false;
    isResizingSelection = false;
    isDraggingTextInsertion = false;
    isInteracting = false;
    if (typeof isPenActive !== 'undefined') isPenActive = false;
    if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; }
    if (straightenTimer) { clearTimeout(straightenTimer); straightenTimer = null; }
    if (typeof viewports !== 'undefined' && Array.isArray(viewports)) {
        viewports.forEach(vp => {
            if (vp.gesture) vp.gesture.lastPinchDist = 0;
            if (vp.octx && vp.overlay) vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height);
        });
    }
};

window.addEventListener('blur', () => { if (typeof resetPointerState === 'function') resetPointerState(); });
document.addEventListener('visibilitychange', () => { if (document.hidden && typeof resetPointerState === 'function') resetPointerState(); });