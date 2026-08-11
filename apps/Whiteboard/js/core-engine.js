/**
 * WHITEBOARD CORE ENGINE
 * Handles rendering, transforms, and coordinate math.
 */
let viewports =[{
    id: 'pane-0',
    canvas: document.getElementById('main-canvas'),
    overlay: document.getElementById('overlay-canvas'),
    ctx: document.getElementById('main-canvas').getContext('2d'),
    octx: document.getElementById('overlay-canvas').getContext('2d'),
    transform: { x: 0, y: 0, scale: 1, rotation: 0 },
    gesture: { lastPinchDist: 0, lastPinchAngle: 0, lastMidpoint: { x: 0, y: 0 } }
}];
let activeViewportIndex = 0;
function getActiveViewport() { return viewports[activeViewportIndex]; }

window.wbGetViewportIndexAt = function(clientX, clientY) {
    // Reverse iteration to check floating viewports (top-most) first
    for (let i = viewports.length - 1; i >= 0; i--) {
        const rect = viewports[i].canvas.parentElement.getBoundingClientRect();
        if (clientX >= rect.left && clientX <= rect.right &&
            clientY >= rect.top && clientY <= rect.bottom) {
            return i;
        }
    }
    return -1;
};

let pointerToViewport = new Map(); // pointerId -> vpIndex
let ignoredPointers = new Set(); // IDs of large touches (palms) to ignore

let activePalms = new Map();
let pointerMeta = new Map(); // Tracks start positions and validation state

window.checkPalm = function(e) {
    if (e.pointerType !== 'touch') return false;

    const meta = pointerMeta.get(e.pointerId);
    const now = Date.now();
    
    // 1. MOTION VALIDATION: If a finger has moved > 30px, it is "Validated"
    // Validated fingers are immune to size-based rejection to allow "Fat Finger" zooming.
    if (meta && meta.validated) return false;

    // 2. CLUSTER ANALYSIS: A touch is only a "Palm" if it is large (>100px) 
    // AND has a "Satellite" (another touch) within 180px (knuckles/wrist).
    const isLarge = e.width > 100 || e.height > 100;
    let hasSatellite = false;
    
    for (let [id, ptr] of pointers.entries()) {
        if (id !== e.pointerId && ptr.pointerType === 'touch') {
            const d = Math.hypot(e.clientX - ptr.clientX, e.clientY - ptr.clientY);
            if (d < 180) hasSatellite = true;
        }
    }

    // A Confirmed Palm is large and part of a cluster, OR very large (>180px)
    const isConfirmedPalm = (isLarge && hasSatellite) || (e.width > 180);

    if (isConfirmedPalm) {
        activePalms.set(e.pointerId, { x: e.clientX, y: e.clientY, time: now });
    }

    // 3. PROXIMITY REJECTION: Block any non-validated touch within 300px of a confirmed palm.
    let isNearPalm = false;
    for (let [palmId, palm] of activePalms.entries()) {
        if (now - palm.time > 2000) { activePalms.delete(palmId); continue; }
        if (palmId !== e.pointerId) {
            const dist = Math.hypot(e.clientX - palm.x, e.clientY - palm.y);
            if (dist < 300) isNearPalm = true;
        }
    }

    if (isConfirmedPalm || isNearPalm) {
        ignoredPointers.add(e.pointerId);
        
        // Active Sweep: Kill any existing non-validated touches in the dead zone
        for (let [id, ptr] of pointers.entries()) {
            const pMeta = pointerMeta.get(id);
            if (id !== e.pointerId && ptr.pointerType === 'touch' && (!pMeta || !pMeta.validated)) {
                const dist = Math.hypot(ptr.clientX - e.clientX, ptr.clientY - e.clientY);
                if (dist < 300) {
                    ignoredPointers.add(id);
                    pointers.delete(id);
                    pointerToViewport.delete(id);
                    if (isDrawing) { isDrawing = false; currentStroke = null; }
                }
            }
        }

        if (pointers.has(e.pointerId)) {
            pointers.delete(e.pointerId);
            pointerToViewport.delete(e.pointerId);
            isPanning = false;
            isSelecting = false;
            viewports.forEach(v => v.gesture.lastPinchDist = 0);
            if (typeof render === 'function') render();
        }
        return true; 
    }
    return false;
};

let isDrawing = false;
let isSelecting = false;
let isMoving = false;
let isPanning = false;
let isResizing = false;
let isLayoutLocked = false;
let isDraggingObject = false;
window.isDraggingBlank = false;
window.activeGhostBlank = null;

window.wbToggleLayoutLock = function(locked) {
    isLayoutLocked = locked;
    document.body.classList.toggle('wb-layout-locked', locked);
    if (window.navigator.vibrate) navigator.vibrate(5);
};
let isResizingSelection = false;
let lpTimer = null;
let lpStartPos = { x: 0, y: 0 };
let activeHandle = null; // 'nw', 'ne', 'sw', 'se'
let dragOffset = { x: 0, y: 0 };
let lastTapTime = 0;
let lastTapPos = { x: 0, y: 0 };
let wbSelection = {
    ids: new Set(),
    bounds: null,
    initialStrokes: null 
};
let clipboard = null;

// --- ASSET VAULT UTILS ---
async function wbHash(str) {
    if (window.isSecureContext && crypto.subtle) {
        const msgUint8 = new TextEncoder().encode(str);
        const hashBuffer = await crypto.subtle.digest('SHA-1', msgUint8);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }
    // Fallback: Simple fast hash for non-secure contexts
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = ((hash << 5) - hash) + str.charCodeAt(i);
        hash |= 0;
    }
    return 'fallback-' + Math.abs(hash).toString(16);
}

async function ensureVaultAsset(hash) {
    const fd = new FormData();
    fd.append('action', 'get_asset');
    fd.append('hash', hash);
    try { await fetch('index.php', { method: 'POST', body: fd }); } catch(e) {}
}

async function getLocalAsset(hash) {
    return new Promise((resolve) => {
        if (!localDB) return resolve(null);
        const tx = localDB.transaction('assets', 'readonly');
        const store = tx.objectStore('assets');
        const req = store.get(hash);
        req.onsuccess = () => resolve(req.result ? req.result.data : null);
    });
}

async function hasLocalAsset(hash) {
    return new Promise((resolve) => {
        if (!localDB) return resolve(false);
        const tx = localDB.transaction('assets', 'readonly');
        const store = tx.objectStore('assets');
        const req = store.getKey(hash);
        req.onsuccess = () => resolve(req.result !== undefined);
        req.onerror = () => resolve(false);
    });
}

async function saveLocalAsset(hash, data) {
    const tx = localDB.transaction('assets', 'readwrite');
    tx.objectStore('assets').put({ id: hash, data: data });
}

async function ensureAssetSynced(hash, data) {
    // 1. Save to local IndexedDB
    await saveLocalAsset(hash, data);
    // 2. Upload to Server Vault
    const fd = new FormData();
    fd.append('action', 'upload_asset');
    fd.append('hash', hash);
    fd.append('data', data);
    await fetch('index.php', { method: 'POST', body: fd });
}
let lassoPoints = [];
let lastX = 0;
let lastY = 0;
let brushColor = '#000000';
let brushWidth = 4;
let toolConfigs = {
    draw: { color: '#000000', width: 4 },
    highlight: { color: '#ffff00', width: 20 }
};
let textFontSize = 24;
let isPenActive = false;
let toolbarMode = 'floating'; // 'floating' or 'docked'
let touchMode = 'draw'; // 'pan', 'draw', 'lasso'
let userSelectedTool = 'draw'; // Tracks the tool manually picked in the toolbar
let isDraggingTextInsertion = false;
let pendingTextHit = null;
let pendingTextCommit = false;
let eraserMode = 'point';
let paperType = 'plain';
let autoSaveEnabled = false; // Initialized by index.php
let penOnlyMode = false; // Initialized by index.php

let rotationEnabled = true; // Initialized by index.php

window.updatePenOnlyMode = function(enabled) {
    penOnlyMode = enabled;
    saveSettings({ pen_only_mode: enabled });
    if (enabled) {
        // If we were in the middle of a touch-draw, cancel it
        if (isDrawing && !isPenActive) {
            isDrawing = false;
            currentStroke = null;
            render();
        }
    }
    if (window.wb && window.wb.ui) window.wb.ui.update();
};

// TRANSFORM STATE (Zoom/Pan/Rotate)
let pointers = new Map();
let lastPinchDist = 0;
let lastPinchAngle = 0;
let lastMidpoint = { x: 0, y: 0 };

// UNDO/REDO STORAGE
let allStrokes =[];
let undoStack =[]; // Stores snapshots of previous states
let redoStack =[]; // Stores snapshots for redoing
let interactionSnapshot = null; // Captures state at the start of a gesture
window.wbDeletedStrokeIds = new Set(); // Tombstones for deleted strokes
let hasChangedDuringInteraction = false;
let isInteracting = false;
let renderRequested = false;

// The Master Render Loop (rAF)
function requestRender() {
    if (!renderRequested) {
        renderRequested = true;
        requestAnimationFrame(() => {
            renderRequested = false;
            render();
        });
    }
}

function wbPushUndo(customSnapshot = null) {
    const snapshot = customSnapshot || JSON.stringify(allStrokes);
    undoStack.push(snapshot);
    if (undoStack.length > 50) undoStack.shift(); // Limit history to 50 steps
    redoStack = []; // New actions clear the redo path
    updateUndoRedoButtons();
}

function updateUndoRedoButtons() {
    const uBtn = document.getElementById('undo-btn');
    const rBtn = document.getElementById('redo-btn');
    if (uBtn) uBtn.disabled = undoStack.length === 0;
    if (rBtn) rBtn.disabled = redoStack.length === 0;
}

let _wbMeasureCtx = null;
function wbCalculateStrokeBounds(s) {
    if (s.type === 'path') {
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        s.points.forEach(p => {
            minX = Math.min(minX, p.x); minY = Math.min(minY, p.y);
            maxX = Math.max(maxX, p.x); maxY = Math.max(maxY, p.y);
        });
        const pad = (s.width || 4) / 2;
        return { x: minX - pad, y: minY - pad, w: (maxX - minX) + (pad * 2), h: (maxY - minY) + (pad * 2) };
    } else if (s.type === 'text') {
        if (!_wbMeasureCtx) _wbMeasureCtx = document.createElement('canvas').getContext('2d');
        const fontSize = s.fontSize || 24;
        const fontStyle = (s.italic ? 'italic ' : '') + (s.bold ? 'bold ' : '');
        const fontFamily = s.fontFamily || 'sans-serif';
        _wbMeasureCtx.font = `${fontStyle}${fontSize}px ${fontFamily}`;

        const lines = (s.content || "").split('\n');
        let maxW = 0;
        lines.forEach(line => {
            const metrics = _wbMeasureCtx.measureText(line);
            maxW = Math.max(maxW, metrics.width);
        });

        const estH = lines.length * (fontSize * 1.2);
        let startX = s.x;
        if (s.align === 'center') startX = s.x - (maxW / 2);
        else if (s.align === 'right') startX = s.x - maxW;
        
        return { x: startX, y: s.y, w: maxW, h: estH };
    } else if (s.type === 'blank') {
        const fontSize = s.fontSize || 24;
        const labelRatio = s.labelRatio || window.grammarLabelRatio || 0.4;
        const labelFontSize = Math.max(8, fontSize * labelRatio);
        const labelHeight = s.label ? labelFontSize * 1.5 : 0;
        const lines = (s.content || "").split('\n');
        const contentHeight = (lines.length > 0 ? lines.length : 1) * (fontSize * 1.2);
        // x is start of line. y is the line itself.
        return { x: s.x, y: s.y - contentHeight, w: s.w, h: contentHeight + labelHeight };
    } else {
        // Image or Group
        return { x: s.x, y: s.y, w: s.w || 50, h: s.h || 50 };
    }
}

function wbCreateId() {
    return Date.now().toString(36) + Math.random().toString(36).substr(2, 5);
}

function wbGetNextZIndex() {
    if (allStrokes.length === 0) return 0;
    return Math.max(...allStrokes.map(s => s.zIndex || 0)) + 1;
}
let currentStroke = null;
let straightenTimer = null;
let isStraightened = false;
let starredSizes = []; // Local cache for reactive UI
let wbImageCache = {};

// Helper to enforce a timeout on network requests within the app
const wbFetchWithTimeout = (url, options, timeout = 3000) => {
    // LAYER 4: Instant Skip if hardware reports offline
    if (navigator.onLine === false) {
        return Promise.reject(new Error("Offline (Instant Skip)"));
    }
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), timeout);
    return fetch(url, { ...options, signal: controller.signal }).finally(() => clearTimeout(id));
};

function wbGenerateMipmap(img) {
    const MAX_THUMB_SIZE = 1024;
    if (img.naturalWidth > MAX_THUMB_SIZE || img.naturalHeight > MAX_THUMB_SIZE) {
        const ratio = Math.min(MAX_THUMB_SIZE / img.naturalWidth, MAX_THUMB_SIZE / img.naturalHeight);
        const tCanvas = document.createElement('canvas');
        tCanvas.width = img.naturalWidth * ratio;
        tCanvas.height = img.naturalHeight * ratio;
        tCanvas.getContext('2d').drawImage(img, 0, 0, tCanvas.width, tCanvas.height);
        img.thumb = tCanvas;
    } else {
        img.thumb = img;
    }
}

function wbGetImage(src) {
    if (!src) return new Image();
    if (wbImageCache[src]) return wbImageCache[src];
    const img = new Image();
    img.onload = () => {
        wbGenerateMipmap(img);
        render(); // Trigger a single re-render once the asset is ready
    };
    img.src = src;
    wbImageCache[src] = img;
    return img;
}

let lastSyncedId = 0; // Initialized by index.php
let lastServerHash = "";

function wbUpdateSelectionUI(show) {
    const btn = document.getElementById('selection-commit-btn');
    const menu = document.getElementById('selection-menu');
    const isActive = show && wbSelection.ids.size > 0;

    if (btn) btn.classList.toggle('active', isActive);
    if (menu) {
        if (isActive) {
            menu.style.display = 'flex';
            // Force reflow for transition
            void menu.offsetWidth;
            menu.classList.add('active');
        } else {
            menu.classList.remove('active');
            // Hide after transition
            setTimeout(() => {
                if (!menu.classList.contains('active')) menu.style.display = 'none';
            }, 400);
        }
    }
}

function wbGetHash(str) {
    if (!str) return "00000000";
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        const char = str.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash |= 0; 
    }
    return (hash >>> 0).toString(16).toUpperCase().padStart(8, '0');
}

window.toggleSplitPopover = function() {
    const btn = document.getElementById('split-btn');
    const pop = document.getElementById('split-popover');
    const isVisible = pop.style.display === 'flex';
    
    if (!isVisible) {
        // Hide others
        document.getElementById('size-popover').style.display = 'none';
        document.getElementById('save-menu').style.display = 'none';
        document.getElementById('options-menu').style.display = 'none';
        
        // Show and Position
        pop.style.display = 'flex';
        const rect = btn.getBoundingClientRect();
        const popWidth = pop.offsetWidth || 180;
        const margin = 12;
        
        // Center relative to button
        let left = rect.left + (rect.width / 2) - (popWidth / 2);
        
        // Clamp to viewport
        left = Math.max(margin, Math.min(left, window.innerWidth - popWidth - margin));
        
        pop.style.left = left + 'px';
    } else {
        pop.style.display = 'none';
    }
};

window.toggleResizerDebug = function(show) {
    const resizer = document.querySelector('.resizer');
    if (resizer) resizer.classList.toggle('debug-hit', show);
};

window.updateResizerHitArea = function(val, persist = true) {
    const offset = -Math.abs(val);
    document.documentElement.style.setProperty('--resizer-hit-offset', offset + 'px');
    const display = document.getElementById('hit-area-val');
    if (display) display.innerText = val + 'px';
    const slider = document.getElementById('hit-area-slider');
    if (slider) slider.value = val;
    
    if (persist) saveSettings({ resizer_hit_padding: val });
};

window.setSplitMode = function(mode) {
    const container = document.getElementById('canvas-container');
    const pane0 = document.getElementById('pane-0');
    
    // --- BRANCH A: CREATE FLOATING VIEWPORT (Additive) ---
    if (mode === 'floating') {
        const floatId = 'pane-float-' + Date.now();
        const floatDiv = document.createElement('div');
        floatDiv.className = 'floating-viewport';

        const SNAP_THRESHOLD = 12;
        let lastSnapX = false, lastSnapY = false;

        const getOtherRects = () => {
            return Array.from(document.querySelectorAll('.floating-viewport'))
                .filter(el => el !== floatDiv)
                .map(el => ({
                    l: el.offsetLeft,
                    t: el.offsetTop,
                    r: el.offsetLeft + el.offsetWidth,
                    b: el.offsetTop + el.offsetHeight
                }));
        };

        const refreshSettleTimer = () => {
            if (floatDiv._settleTimer) clearTimeout(floatDiv._settleTimer);
            floatDiv.classList.remove('settled');
            floatDiv.classList.add('resize-mode');
            floatDiv._settleTimer = setTimeout(() => {
                floatDiv.classList.add('settled');
                floatDiv.classList.remove('resize-mode');
            }, 5000);
        };
        floatDiv.wbRefreshSettleTimer = refreshSettleTimer;
        
        // Stagger position based on existing count
        const count = document.querySelectorAll('.floating-viewport').length;
        floatDiv.style.top = (20 + (count * 30)) + 'px';
        floatDiv.style.right = (20 + (count * 30)) + 'px';
        
        // --- CLOSE BUTTON ---
        const closeBtn = document.createElement('button');
        closeBtn.className = 'floating-close-btn';
        closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="3" fill="none"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
        
        closeBtn.onpointerdown = (e) => {
            e.stopPropagation();
            // Instance-specific removal
            const idx = viewports.findIndex(v => v.id === floatId);
            if (idx !== -1) viewports.splice(idx, 1);
            floatDiv.remove();
            activeViewportIndex = 0;
            if (typeof resetPointerState === 'function') resetPointerState();
            render();
            if (window.navigator.vibrate) navigator.vibrate(10);
        };

        // --- DUPLICATE BUTTON ---
        const dupBtn = document.createElement('button');
        dupBtn.className = 'floating-duplicate-btn';
        dupBtn.title = "Duplicate Viewport";
        dupBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="3" fill="none"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
        
        dupBtn.onpointerdown = (e) => {
            e.stopPropagation();
            const srcW = floatDiv.offsetWidth;
            const srcH = floatDiv.offsetHeight;
            const srcL = floatDiv.offsetLeft;
            const srcT = floatDiv.offsetTop;
            const srcVp = viewports.find(v => v.id === floatId);
            
            setSplitMode('floating');
            const allFloats = document.querySelectorAll('.floating-viewport');
            const newDiv = allFloats[allFloats.length - 1];
            const newVp = viewports[viewports.length - 1];
            
            if (newDiv && newVp && srcVp) {
                newDiv.style.width = srcW + 'px';
                newDiv.style.height = srcH + 'px';
                newDiv.style.left = (srcL + 20) + 'px';
                newDiv.style.top = (srcT + 20) + 'px';
                newDiv.style.right = 'auto';
                newVp.transform = { ...srcVp.transform };
                resize();
            }
            if (window.navigator.vibrate) navigator.vibrate(10);
        };

        const pane = document.createElement('div');
        pane.className = 'canvas-pane';
        pane.id = floatId;
        
        const main = document.createElement('canvas');
        const over = document.createElement('canvas');
        pane.appendChild(main);
        pane.appendChild(over);

        const handle = document.createElement('div');
        handle.className = 'floating-handle';

        // --- RESIZE HANDLES ---
        ['nw', 'ne', 'sw', 'se'].forEach(dir => {
            const h = document.createElement('div');
            h.className = `viewport-resize-handle vrh-${dir}`;
            h.onpointerdown = (e) => {
                if (e.pointerType === 'pen') return;
                if (floatDiv._settleTimer) clearTimeout(floatDiv._settleTimer);
                e.stopPropagation();
                h.setPointerCapture(e.pointerId);
                const startX = e.clientX;
                const startY = e.clientY;
                const startW = floatDiv.offsetWidth;
                const startH = floatDiv.offsetHeight;
                const startL = floatDiv.offsetLeft;
                const startT = floatDiv.offsetTop;

                const onMove = (me) => {
                    let dx = me.clientX - startX;
                    let dy = me.clientY - startY;
                    const others = getOtherRects();
                    let snapped = false;

                    if (dir.includes('e')) {
                        let targetR = startL + startW + dx;
                        others.forEach(r => {
                            if (Math.abs(targetR - r.l) < SNAP_THRESHOLD) { targetR = r.l; snapped = true; }
                            else if (Math.abs(targetR - r.r) < SNAP_THRESHOLD) { targetR = r.r; snapped = true; }
                        });
                        floatDiv.style.width = Math.max(150, targetR - startL) + 'px';
                    }
                    if (dir.includes('w')) {
                        let targetL = startL + dx;
                        others.forEach(r => {
                            if (Math.abs(targetL - r.l) < SNAP_THRESHOLD) { targetL = r.l; snapped = true; }
                            else if (Math.abs(targetL - r.r) < SNAP_THRESHOLD) { targetL = r.r; snapped = true; }
                        });
                        const finalW = Math.max(150, (startL + startW) - targetL);
                        floatDiv.style.width = finalW + 'px';
                        floatDiv.style.left = ((startL + startW) - finalW) + 'px';
                    }
                    if (dir.includes('s')) {
                        let targetB = startT + startH + dy;
                        others.forEach(r => {
                            if (Math.abs(targetB - r.t) < SNAP_THRESHOLD) { targetB = r.t; snapped = true; }
                            else if (Math.abs(targetB - r.b) < SNAP_THRESHOLD) { targetB = r.b; snapped = true; }
                        });
                        floatDiv.style.height = Math.max(100, targetB - startT) + 'px';
                    }
                    if (dir.includes('n')) {
                        let targetT = startT + dy;
                        others.forEach(r => {
                            if (Math.abs(targetT - r.t) < SNAP_THRESHOLD) { targetT = r.t; snapped = true; }
                            else if (Math.abs(targetT - r.b) < SNAP_THRESHOLD) { targetT = r.b; snapped = true; }
                        });
                        const finalH = Math.max(100, (startT + startH) - targetT);
                        floatDiv.style.height = finalH + 'px';
                        floatDiv.style.top = ((startT + startH) - finalH) + 'px';
                    }

                    if (snapped && !lastSnapX) {
                        if (window.navigator.vibrate) navigator.vibrate(5);
                        lastSnapX = true;
                    } else if (!snapped) {
                        lastSnapX = false;
                    }

                    resize();
                };
                const onUp = () => {
                    refreshSettleTimer();
                    h.releasePointerCapture(e.pointerId);
                    window.removeEventListener('pointermove', onMove);
                    window.removeEventListener('pointerup', onUp);
                    if (typeof saveViewState === 'function') saveViewState();
                };
                window.addEventListener('pointermove', onMove);
                window.addEventListener('pointerup', onUp);
            };
            floatDiv.appendChild(h);
        });

        let handleLpTimer = null;
        let wakeTimer = null;

        handle.onpointerdown = (e) => {
            if (e.pointerType === 'pen') return;
            if (isLayoutLocked) return;
            e.stopPropagation();
            handle.setPointerCapture(e.pointerId);
            
            const startX = e.clientX - floatDiv.offsetLeft;
            const startY = e.clientY - floatDiv.offsetTop;
            const startPos = { x: e.clientX, y: e.clientY };

            // UNLOCK LOGIC: If settled, require 1s hold OR double-tap to wake up
            if (floatDiv.classList.contains('settled')) {
                const now = Date.now();
                const lastTap = floatDiv._lastHandleTap || 0;
                
                if (now - lastTap < 300) {
                    // SUCCESS: Double Tap detected
                    if (wakeTimer) clearTimeout(wakeTimer);
                    wakeTimer = null;
                    
                    // Wake up visually but don't start the 5s clock yet
                    floatDiv.classList.remove('settled');
                    floatDiv.classList.add('resize-mode');
                    
                    if (window.navigator.vibrate) navigator.vibrate([10, 30]);
                    floatDiv._lastHandleTap = 0;
                } else {
                    floatDiv._lastHandleTap = now;
                    if (wakeTimer) clearTimeout(wakeTimer);
                    wakeTimer = setTimeout(() => {
                        // Wake up visually but don't start the 5s clock yet
                        floatDiv.classList.remove('settled');
                        floatDiv.classList.add('resize-mode');
                        
                        if (window.navigator.vibrate) navigator.vibrate(30);
                        wakeTimer = null;
                    }, 1000);
                }
            } else {
                // Already awake: Suspend the settle clock while finger is down
                if (floatDiv._settleTimer) clearTimeout(floatDiv._settleTimer);
                
                // Start the 600ms manual resize toggle timer
                handleLpTimer = setTimeout(() => {
                    const isActive = floatDiv.classList.toggle('resize-mode');
                    if (window.navigator.vibrate) navigator.vibrate(20);
                    handleLpTimer = null;
                }, 600);
            }

            const onMove = (me) => {
                const dist = Math.hypot(me.clientX - startPos.x, me.clientY - startPos.y);
                
                if (floatDiv.classList.contains('settled')) {
                    if (dist > 10) {
                        if (wakeTimer) {
                            clearTimeout(wakeTimer);
                            wakeTimer = null;
                        }
                        floatDiv._lastHandleTap = 0;
                    }
                    return; 
                }

                if (handleLpTimer && dist > 10) {
                    clearTimeout(handleLpTimer);
                    handleLpTimer = null;
                }

                let newL = me.clientX - startX;
                let newT = me.clientY - startY;
                const w = floatDiv.offsetWidth;
                const h = floatDiv.offsetHeight;
                const others = getOtherRects();

                let snappedX = false, snappedY = false;

                others.forEach(r => {
                    // X Snapping (Left or Right edges)
                    if (Math.abs(newL - r.l) < SNAP_THRESHOLD) { newL = r.l; snappedX = true; }
                    else if (Math.abs(newL - r.r) < SNAP_THRESHOLD) { newL = r.r; snappedX = true; }
                    else if (Math.abs((newL + w) - r.l) < SNAP_THRESHOLD) { newL = r.l - w; snappedX = true; }
                    else if (Math.abs((newL + w) - r.r) < SNAP_THRESHOLD) { newL = r.r - w; snappedX = true; }

                    // Y Snapping (Top or Bottom edges)
                    if (Math.abs(newT - r.t) < SNAP_THRESHOLD) { newT = r.t; snappedY = true; }
                    else if (Math.abs(newT - r.b) < SNAP_THRESHOLD) { newT = r.b; snappedY = true; }
                    else if (Math.abs((newT + h) - r.t) < SNAP_THRESHOLD) { newT = r.t - h; snappedY = true; }
                    else if (Math.abs((newT + h) - r.b) < SNAP_THRESHOLD) { newT = r.b - h; snappedY = true; }
                });

                if ((snappedX && !lastSnapX) || (snappedY && !lastSnapY)) {
                    if (window.navigator.vibrate) navigator.vibrate(5);
                }
                lastSnapX = snappedX; lastSnapY = snappedY;

                floatDiv.style.left = newL + 'px';
                floatDiv.style.top = newT + 'px';
                floatDiv.style.right = 'auto';
            };
            const onUp = () => {
                if (wakeTimer) {
                    clearTimeout(wakeTimer);
                    wakeTimer = null;
                } else if (!floatDiv.classList.contains('settled')) {
                    // Start the 5s countdown only if we are currently in active mode
                    refreshSettleTimer();
                }
                clearTimeout(handleLpTimer);
                handleLpTimer = null;
                handle.releasePointerCapture(e.pointerId);
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                if (typeof saveViewState === 'function') saveViewState();
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
        };

        floatDiv.appendChild(closeBtn);
        floatDiv.appendChild(dupBtn);
        floatDiv.appendChild(pane);
        floatDiv.appendChild(handle);
        container.appendChild(floatDiv);

        refreshSettleTimer();

        viewports.push({
            id: floatId,
            canvas: main,
            overlay: over,
            ctx: main.getContext('2d'),
            octx: over.getContext('2d'),
            transform: { ...viewports[0].transform },
            gesture: { lastPinchDist: 0, lastPinchAngle: 0, lastMidpoint: { ...viewports[0].gesture.lastMidpoint } }
        });
        resize();
        document.getElementById('split-popover').style.display = 'none';
        return;
    }

    // --- BRANCH B: CHANGE BASE LAYOUT (None, Vertical, Horizontal) ---
    const oldPane = document.getElementById('pane-1');
    const oldResizer = container.querySelector('.resizer');
    const floatingVps = viewports.filter(v => v.id.startsWith('pane-float'));

    // 1. Clean up existing base split elements
    if (oldPane) oldPane.remove();
    if (oldResizer) oldResizer.remove();

    // 2. Reset container classes and pane0 styles
    container.classList.remove('split-horizontal');
    pane0.classList.remove('horizontal');
    
    // Reset flex to center (50/50) whenever switching base modes (None, Vert, Horiz).
    // This prevents a fixed pixel width from a vertical split "veering" a horizontal split.
    pane0.style.flex = "1 1 0%";

    // 3. Reconstruct viewports array (Keep main, add split if needed, keep all floating)
    const newViewports = [viewports[0]];

    if (mode !== 'none') {
        const isHorizontal = mode === 'horizontal';
        if (isHorizontal) {
            container.classList.add('split-horizontal');
            pane0.classList.add('horizontal');
        }

        const resizer = document.createElement('div');
        resizer.className = `resizer ${isHorizontal ? 'horizontal' : 'vertical'}`;
        
        resizer.onpointerdown = (e) => {
            if (e.pointerType === 'pen') return;
            if (isLayoutLocked) return;
            e.stopPropagation(); 
            isResizing = true;
            resizer.setPointerCapture(e.pointerId);
            resizer.classList.add('active');
            container.classList.add('is-resizing');
            const r = container.getBoundingClientRect();
            const p0r = pane0.getBoundingClientRect();
            const grabOffset = isHorizontal ? (e.clientY - p0r.bottom) : (e.clientX - p0r.right);
            
            const onMove = (me) => {
                const cr = container.getBoundingClientRect();
                if (isHorizontal) {
                    const newH = me.clientY - cr.top - grabOffset;
                    if ((newH / cr.height) * 100 >= 10 && (newH / cr.height) * 100 <= 90) pane0.style.flex = `0 0 ${newH}px`;
                } else {
                    const newW = me.clientX - cr.left - grabOffset;
                    if ((newW / cr.width) * 100 >= 10 && (newW / cr.width) * 100 <= 90) pane0.style.flex = `0 0 ${newW}px`;
                }
                resize(); 
            };
            const onUp = () => {
                isResizing = false;
                container.classList.remove('is-resizing');
                resizer.releasePointerCapture(e.pointerId);
                resizer.classList.remove('active');
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                if (typeof saveViewState === 'function') saveViewState();
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
        };

        const pane1 = document.createElement('div');
        pane1.className = 'canvas-pane' + (isHorizontal ? ' horizontal' : '');
        pane1.id = 'pane-1';
        const main1 = document.createElement('canvas');
        const over1 = document.createElement('canvas');
        pane1.appendChild(main1);
        pane1.appendChild(over1);
        
        container.appendChild(resizer);
        container.appendChild(pane1);
        
        newViewports.push({
            id: 'pane-1',
            canvas: main1,
            overlay: over1,
            ctx: main1.getContext('2d'),
            octx: over1.getContext('2d'),
            transform: { ...viewports[0].transform },
            gesture: { lastPinchDist: 0, lastPinchAngle: 0, lastMidpoint: { ...viewports[0].gesture.lastMidpoint } }
        });
    }

    // 4. Append floating viewports back to the array
    viewports = [...newViewports, ...floatingVps];
    activeViewportIndex = 0;
    if (typeof resetPointerState === 'function') resetPointerState();
    resize();
    document.getElementById('split-popover').style.display = 'none';
    if (window.navigator.vibrate) navigator.vibrate(5);
};

function wbUpdateHashUI() {
    const localData = JSON.stringify(allStrokes);
    const localHash = wbGetHash(localData);
    
    const localChip = document.getElementById('local-hash-chip');
    const serverChip = document.getElementById('server-hash-chip');
    
    const isMatch = (lastServerHash && localHash === lastServerHash);

    // SELF-HEALING: If hashes match, we are technically not dirty anymore.
    // This prevents the "Identical but Out of Sync" alert.
    if (isMatch) {
        getLocalDocument('canvas_' + window.currentCanvasId).then(doc => {
            if (doc && doc.dirty) {
                console.log("Sync: Hashes match. Clearing dirty flag.");
                saveLocalDocument('canvas_' + window.currentCanvasId, localData, false);
            }
        });
    }
    
    if (localChip) {
        localChip.innerText = localHash;
        localChip.classList.toggle('match', isMatch);
        localChip.classList.toggle('mismatch', lastServerHash && !isMatch);
    }
    
    if (serverChip) {
        serverChip.innerText = lastServerHash || 'NONE';
        serverChip.classList.toggle('match', isMatch);
        serverChip.classList.toggle('mismatch', lastServerHash && !isMatch);
    }

    const weightChip = document.getElementById('canvas-weight-chip');
    if (weightChip) {
        const bytes = localData.length;
        if (bytes < 1024) weightChip.innerText = bytes + ' B';
        else if (bytes < 1048576) weightChip.innerText = (bytes / 1024).toFixed(1) + ' KB';
        else weightChip.innerText = (bytes / 1048576).toFixed(2) + ' MB';
    }
}

window.wbCaptureViewportState = function() {
    const container = document.getElementById('canvas-container');
    const pane0 = document.getElementById('pane-0');
    if (!container || !pane0 || !viewports || viewports.length === 0) return null;

    const baseMode = container.classList.contains('split-horizontal') ? 'horizontal' : 
                      (document.getElementById('pane-1') ? 'vertical' : 'none');
    
    const state = {
        baseMode: baseMode,
        splitRatio: pane0.style.flex,
        mainTransform: viewports[0] ? { ...viewports[0].transform } : { x: 0, y: 0, scale: 1, rotation: 0 },
        splitTransform: viewports[1] && viewports[1].id === 'pane-1' ? { ...viewports[1].transform } : null,
        floating: []
    };

    document.querySelectorAll('.floating-viewport').forEach(el => {
        const pane = el.querySelector('.canvas-pane');
        const vp = pane ? viewports.find(v => v.id === pane.id) : null;
        if (vp) {
            state.floating.push({
                rect: {
                    top: el.style.top,
                    left: el.style.left,
                    width: el.style.width,
                    height: el.style.height
                },
                transform: { ...vp.transform }
            });
        }
    });

    return state;
};

window.wbApplyViewportState = function(layout) {
    if (!layout || !layout.baseMode) return false;

    // 1. Clear existing floating viewports from DOM
    document.querySelectorAll('.floating-viewport').forEach(el => el.remove());
    viewports = [viewports[0]];
    activeViewportIndex = 0;

    // 2. Set Base Split Mode (none, vertical, horizontal)
    setSplitMode(layout.baseMode);

    // 3. Set Split Ratio if split mode is active
    if (layout.splitRatio) {
        const pane0 = document.getElementById('pane-0');
        if (pane0) pane0.style.flex = layout.splitRatio;
    }

    // 4. Restore Main Viewport Transform
    if (layout.mainTransform && viewports[0]) {
        viewports[0].transform = { ...layout.mainTransform };
    }

    // 5. Restore Split Pane Transform (if pane-1 exists)
    if (layout.splitTransform && viewports[1] && viewports[1].id === 'pane-1') {
        viewports[1].transform = { ...layout.splitTransform };
    }

    // 6. Restore Floating Viewports
    if (Array.isArray(layout.floating) && layout.floating.length > 0) {
        layout.floating.forEach(f => {
            setSplitMode('floating');
            const allFloats = document.querySelectorAll('.floating-viewport');
            const el = allFloats[allFloats.length - 1];
            if (el && f.rect) {
                if (f.rect.top) el.style.top = f.rect.top;
                if (f.rect.left) el.style.left = f.rect.left;
                if (f.rect.width) el.style.width = f.rect.width;
                if (f.rect.height) el.style.height = f.rect.height;
                el.style.right = 'auto';

                const paneId = el.querySelector('.canvas-pane')?.id;
                const vp = paneId ? viewports.find(v => v.id === paneId) : null;
                if (vp && f.transform) {
                    vp.transform = { ...f.transform };
                }
            }
        });
    }

    resize();
    return true;
};

let _viewSaveTimer = null;
window.saveViewState = function() {
    if (!window.currentCanvasId) return;
    if (_viewSaveTimer) clearTimeout(_viewSaveTimer);
    // Debounce the save to prevent micro-stutters during rapid scrolling/panning
    _viewSaveTimer = setTimeout(() => {
        const layout = wbCaptureViewportState();
        if (layout) {
            const viewStr = JSON.stringify(layout);
            
            // Store with timestamp for local priority resolution against server state
            localStorage.setItem('wb_view_' + window.currentCanvasId, JSON.stringify({
                layout: layout,
                transform: layout.mainTransform,
                ts: Date.now()
            }));
            
            // Update local DB so background sync eventually pushes it to server
            if (typeof saveLocalDocument === 'function' && window._wbInitialLoadComplete) {
                saveLocalDocument('canvas_' + window.currentCanvasId, JSON.stringify(allStrokes), true, lastSyncedId, lastServerHash, null, viewStr);
            }
        }
    }, 300);
};

function restoreViewState() {
    const serverViewRaw = window._initialViewport;
    const localViewRaw = localStorage.getItem('wb_view_' + window.currentCanvasId);
    
    let targetView = null;
    let localView = null;
    let localTs = 0;

    try {
        if (localViewRaw) {
            const parsed = JSON.parse(localViewRaw);
            if (parsed.layout) {
                localView = parsed.transform || parsed.layout.mainTransform;
                localView._layout = parsed.layout;
                localTs = parsed.ts || 0;
            } else if (parsed.transform && parsed.ts) {
                localView = parsed.transform;
                localTs = parsed.ts;
            } else if (typeof parsed.x === 'number') {
                localView = parsed; // Legacy fallback
                localTs = 0;
            } else if (parsed.baseMode) {
                localView = parsed.mainTransform || parsed;
                localView._layout = parsed;
                localTs = 0;
            }
        }
    } catch(e) { console.warn("Local viewport parse failed", e); }

    let serverView = null;
    try {
        if (serverViewRaw) {
            const parsed = (typeof serverViewRaw === 'string') ? JSON.parse(serverViewRaw) : serverViewRaw;
            if (parsed && parsed.baseMode) {
                serverView = parsed.mainTransform || parsed;
                serverView._layout = parsed;
            } else if (parsed && parsed.transform) {
                serverView = parsed.transform;
                serverView._layout = parsed.layout || parsed;
            } else {
                serverView = parsed;
            }
        }
    } catch(e) { console.warn("Server viewport parse failed", e); }

    const serverTs = (window._canvasUpdatedAt || 0) * 1000;

    // Conflict Resolution: If local storage has a viewport saved AFTER the server's 
    // last canvas update, use it. This prevents a stale server state from 
    // overwriting a fresh local pan/zoom on reload.
    if (localView && localTs >= serverTs) {
        targetView = localView;
    } else if (serverView) {
        targetView = serverView;
    } else if (localView) {
        targetView = localView;
    }

    if (targetView) {
        if (targetView._layout) {
            wbApplyViewportState(targetView._layout);
        } else if (typeof targetView.x === 'number') {
            getActiveViewport().transform = targetView;
        }
        window._lastServerViewportStr = JSON.stringify(targetView._layout || targetView);
        render();
        return true;
    }
    return false;
}

function loadCanvasData(rawData) {
    // Always start by clearing the current state to prevent ghosting
    allStrokes =[];
    if (!rawData) { 
        if (!restoreViewState()) centerOnContent(); 
        return; 
    }
    
    if (rawData.startsWith('[') || rawData.startsWith('{')) {
        try {
            allStrokes = JSON.parse(rawData);
            
            // AUTO-PURGE: Strip any display-only properties to ensure a clean render state
            allStrokes.forEach(s => {
                delete s._cache;
                delete s._cachePad;
                delete s._b;
            });
            let maxZ = 0;
            allStrokes.forEach((s, i) => {
                if (!s.id) s.id = wbCreateId();
                if (typeof s.zIndex === 'undefined') s.zIndex = i;
                maxZ = Math.max(maxZ, s.zIndex);
            });
            allStrokes.sort((a, b) => a.zIndex - b.zIndex);
            if (!restoreViewState()) centerOnContent();
        } catch(e) { console.error("Vector parse failed", e); }
    } else {
        const img = new Image();
        img.onload = () => {
            allStrokes =[{ id: wbCreateId(), zIndex: 0, type: 'image', data: rawData, x: 0, y: 0, w: img.width, h: img.height }];
            if (!restoreViewState()) centerOnContent();
        };
        img.src = rawData;
    }
}

function resize() {
    const dpr = window.devicePixelRatio || 1;
    viewports.forEach(vp => {
        const rect = vp.canvas.parentElement.getBoundingClientRect();
        [vp.canvas, vp.overlay].forEach(c => {
            c.width = rect.width * dpr;
            c.height = rect.height * dpr;
        });

        vp.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        vp.ctx.lineCap = 'round';
        vp.ctx.lineJoin = 'round';

        vp.octx.setTransform(dpr, 0, 0, dpr, 0, 0);
        vp.octx.setLineDash([5, 5]);
        vp.octx.strokeStyle = '#007aff';
    });
    render();
}function centerOnContent(fit = false) {
    const vp = getActiveViewport();
    if (allStrokes.length === 0) {
        const rect = vp.canvas.getBoundingClientRect();
        // Center the origin (0,0) in the middle of the viewport
        vp.transform.x = rect.width / 2;
        vp.transform.y = rect.height / 2;
        vp.transform.scale = 1;
        vp.transform.rotation = 0;
        render(); return;
    }

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    allStrokes.forEach(s => {
        if (s.type === 'path' || s.type === 'mask') {
            s.points.forEach(p => {
                minX = Math.min(minX, p.x); minY = Math.min(minY, p.y);
                maxX = Math.max(maxX, p.x); maxY = Math.max(maxY, p.y);
            });
        } else if (s.type === 'image' || s.type === 'pdf_page' || s.type === 'docx_page') {
            minX = Math.min(minX, s.x); minY = Math.min(minY, s.y);
            maxX = Math.max(maxX, s.x + s.w); maxY = Math.max(maxY, s.y + s.h);
        } else if (s.type === 'text') {
            const lines = (s.content || "").split('\n');
            const fontSize = s.fontSize || 24;
            const longestLine = lines.reduce((max, line) => Math.max(max, line.length), 0);
            const estW = longestLine * (fontSize * 0.6); 
            const estH = lines.length * (fontSize * 1.2);
            
            let startX = s.x;
            if (s.align === 'center') startX = s.x - (estW / 2);
            if (s.align === 'right') startX = s.x - estW;

            minX = Math.min(minX, startX); minY = Math.min(minY, s.y);
            maxX = Math.max(maxX, startX + estW); maxY = Math.max(maxY, s.y + estH);
        } else if (s.type === 'vector_group') {
            minX = Math.min(minX, s.x - s.w/2); minY = Math.min(minY, s.y - s.h/2);
            maxX = Math.max(maxX, s.x + s.w/2); maxY = Math.max(maxY, s.y + s.h/2);
        }
    });

    const rect = vp.canvas.getBoundingClientRect();
    const contentW = maxX - minX;
    const contentH = maxY - minY;
    
    vp.transform.rotation = 0;

    if (fit && contentW > 0 && contentH > 0) {
        const padding = 60; // Increased padding for better visual breathing room
        const availableW = rect.width - (padding * 2);
        const availableH = rect.height - (padding * 2);
        // Calculate scale to fit, with a max cap of 1.0 to prevent over-zooming small icons
        vp.transform.scale = Math.min(availableW / contentW, availableH / contentH, 1.0);
    } else {
        vp.transform.scale = 1;
    }

    // Center the camera on the midpoint of the content
    const midX = minX + contentW / 2;
    const midY = minY + contentH / 2;
    
    vp.transform.x = (rect.width / 2) - (midX * vp.transform.scale);
    vp.transform.y = (rect.height / 2) - (midY * vp.transform.scale);
    
    render();
    if (typeof saveViewState === 'function') saveViewState();
    window._lastServerViewportStr = JSON.stringify(vp.transform);
}

// --- RENDER LOOP ---
function render() {
    window._cachesCreatedThisFrame = 0;
    const dpr = window.devicePixelRatio || 1;
    
    viewports.forEach((vp, index) => {
        try {
        // Clear both main and overlay contexts at the start of the frame
        vp.ctx.setTransform(1, 0, 0, 1, 0, 0);
        vp.ctx.clearRect(0, 0, vp.canvas.width, vp.canvas.height);
        vp.octx.setTransform(1, 0, 0, 1, 0, 0);
        vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height);
        
        vp.ctx.scale(dpr, dpr);
        vp.ctx.translate(vp.transform.x, vp.transform.y);
        vp.ctx.rotate(vp.transform.rotation);
        vp.ctx.scale(vp.transform.scale, vp.transform.scale);

        // Optimization: Skip paper pattern during heavy interaction if zoomed out
        if (!isInteracting || vp.transform.scale > 0.2) {
            drawPaperPattern(vp);
        }
        
        drawOriginMarker(vp);
        updateCoordChip(vp);

        // --- VIEWPORT CULLING ---
        // 1. Calculate the visible world area (Frustum)
        const rect = vp.canvas.getBoundingClientRect();
        const tl = getCanvasCoords({ clientX: rect.left, clientY: rect.top }, vp);
        const tr = getCanvasCoords({ clientX: rect.right, clientY: rect.top }, vp);
        const bl = getCanvasCoords({ clientX: rect.left, clientY: rect.bottom }, vp);
        const br = getCanvasCoords({ clientX: rect.right, clientY: rect.bottom }, vp);

        const vMinX = Math.min(tl.x, tr.x, bl.x, br.x);
        const vMaxX = Math.max(tl.x, tr.x, bl.x, br.x);
        const vMinY = Math.min(tl.y, tr.y, bl.y, br.y);
        const vMaxY = Math.max(tl.y, tr.y, bl.y, br.y);

        // 2. Draw selection hulls and strokes only if visible
        window._vectorBudget = 100; // Max raw vectors to draw per frame if cache isn't ready
        
        allStrokes.forEach(stroke => {
            if (!stroke._b) stroke._b = wbCalculateStrokeBounds(stroke);
            const b = stroke._b;

            const isVisible = (b.x + b.w >= vMinX && b.x <= vMaxX && 
                               b.y + b.h >= vMinY && b.y <= vMaxY);

            if (!isVisible) return;

            const screenW = b.w * vp.transform.scale;
            const screenH = b.h * vp.transform.scale;
            if (screenW < 2 && screenH < 2) return;

            if (wbSelection.ids.has(stroke.id)) {
                drawSelectionHull(vp.ctx, stroke);
            }
            drawStroke(vp.ctx, stroke, vp.transform.scale, false, vp);
        });

        // Draw Bounding Box and Handles if something is selected
        if (wbSelection.ids.size > 0 && wbSelection.bounds && index === activeViewportIndex) {
            drawTransformUI(vp.ctx, wbSelection.bounds);
        }
        
        // Broadcast viewport render event to plugins
        window.wb.emit('onRenderViewport', vp, index, activeViewportIndex);
        } catch (renderErr) {
            console.error("Viewport Render Crash:", renderErr);
        }

        // Draw current stroke preview if drawing
        if (isDrawing && currentStroke && index === activeViewportIndex) {
            vp.octx.save();
            vp.octx.setTransform(vp.transform.scale * dpr, 0, 0, vp.transform.scale * dpr, vp.transform.x * dpr, vp.transform.y * dpr);
            vp.octx.setLineDash([]); // Ensure stroke preview is solid
            vp.octx.lineCap = 'round';
            vp.octx.lineJoin = 'round';
            vp.octx.globalAlpha = currentStroke.opacity || 1.0;
            vp.octx.strokeStyle = currentStroke.color;
            if (currentStroke.points.length > 1) {
                vp.octx.lineWidth = currentStroke.width || currentStroke.points[0].w;
                vp.octx.beginPath();
                vp.octx.moveTo(currentStroke.points[0].x, currentStroke.points[0].y);
                for (let i = 1; i < currentStroke.points.length - 1; i++) {
                    const p1 = currentStroke.points[i];
                    const p2 = currentStroke.points[i + 1];
                    vp.octx.quadraticCurveTo(p1.x, p1.y, (p1.x + p2.x) / 2, (p1.y + p2.y) / 2);
                }
                vp.octx.lineTo(currentStroke.points[currentStroke.points.length - 1].x, currentStroke.points[currentStroke.points.length - 1].y);
                vp.octx.stroke();
            }
            vp.octx.restore();
        }
        
        // Draw current lasso if selecting
        if (isSelecting && lassoPoints.length > 1 && index === activeViewportIndex) {
            vp.octx.save();
            vp.octx.setTransform(vp.transform.scale * dpr, 0, 0, vp.transform.scale * dpr, vp.transform.x * dpr, vp.transform.y * dpr);
            vp.octx.setLineDash([5, 5]); // Ensure lasso is dashed
            vp.octx.strokeStyle = '#007aff';
            vp.octx.beginPath();
            lassoPoints.forEach((p, i) => {
                if (i === 0) vp.octx.moveTo(p.x, p.y);
                else vp.octx.lineTo(p.x, p.y);
            });
            vp.octx.stroke();
            vp.octx.restore();
        }

        // Draw POS preset ghost while a saved composition is being dragged.
        if (window.wbPosDragGhost && index === activeViewportIndex) {
            vp.octx.save();
            vp.octx.setTransform(
                vp.transform.scale * dpr,
                0,
                0,
                vp.transform.scale * dpr,
                vp.transform.x * dpr,
                vp.transform.y * dpr
            );
            vp.octx.globalAlpha = 0.45;
            window.wbPosDragGhost.forEach(item => {
                drawStroke(vp.octx, {
                    type: 'blank',
                    x: item.x,
                    y: item.y,
                    w: item.w,
                    fontSize: item.fontSize,
                    labelRatio: item.labelRatio,
                    label: item.component.kind === 'label' ? item.component.value : null,
                    content: '',
                    color: item.color
                }, vp.transform.scale, false, vp);
            });
            vp.octx.restore();
        }

        // Draw ghost blank if dragging
        if (typeof window.isDraggingBlank !== 'undefined' && window.isDraggingBlank && window.activeGhostBlank && index === activeViewportIndex) {
            vp.octx.save();
            vp.octx.setTransform(vp.transform.scale * dpr, 0, 0, vp.transform.scale * dpr, vp.transform.x * dpr, vp.transform.y * dpr);
            vp.octx.globalAlpha = 0.5;
            
            // Draw alignment guides (Horizontal and Vertical)
            vp.octx.strokeStyle = window.activeGhostBlank.color || '#000000';
            vp.octx.setLineDash([10, 10]);
            vp.octx.lineWidth = 1 / vp.transform.scale;
            
            const lineHalfW = 5000 / vp.transform.scale;
            const lineHalfH = 5000 / vp.transform.scale;
            const cx = window.activeGhostBlank.x + (window.activeGhostBlank.w / 2);
            const cy = window.activeGhostBlank.y;
            
            vp.octx.beginPath();
            vp.octx.moveTo(cx - lineHalfW, cy);
            vp.octx.lineTo(cx + lineHalfW, cy);
            vp.octx.stroke();
            
            vp.octx.beginPath();
            vp.octx.moveTo(cx, cy - lineHalfH);
            vp.octx.lineTo(cx, cy + lineHalfH);
            vp.octx.stroke();
            
            vp.octx.setLineDash([]);
            
            // Render the actual blank using the standard primitive
            drawStroke(vp.octx, window.activeGhostBlank, vp.transform.scale, false, vp);
            
            vp.octx.restore();
        }

        // Draw text insertion guides if dragging/inserting text
        if (typeof isDraggingTextInsertion !== 'undefined' && isDraggingTextInsertion && typeof activeTextEditor !== 'undefined' && activeTextEditor && index === activeViewportIndex) {
            const worldX = activeTextEditor.worldX;
            const worldY = activeTextEditor.worldY;
            const fontSize = activeTextEditor.fontSize;
            
            vp.octx.save();
            vp.octx.setTransform(vp.transform.scale * dpr, 0, 0, vp.transform.scale * dpr, vp.transform.x * dpr, vp.transform.y * dpr);
            vp.octx.strokeStyle = typeof brushColor !== 'undefined' ? brushColor : '#000000';
            vp.octx.globalAlpha = 0.4;
            vp.octx.lineWidth = 1 / vp.transform.scale;
            vp.octx.setLineDash([10, 10]);

            const lineHalfW = 5000 / vp.transform.scale;
            vp.octx.beginPath();
            vp.octx.moveTo(worldX - lineHalfW, worldY);
            vp.octx.lineTo(worldX + lineHalfW, worldY);
            vp.octx.stroke();

            const bottomY = worldY + fontSize;
            vp.octx.beginPath();
            vp.octx.moveTo(worldX - lineHalfW, bottomY);
            vp.octx.lineTo(worldX + lineHalfW, bottomY);
            vp.octx.stroke();

            const lineHalfH = 5000 / vp.transform.scale;
            vp.octx.beginPath();
            vp.octx.moveTo(worldX, worldY - lineHalfH);
            vp.octx.lineTo(worldX, worldY + lineHalfH);
            vp.octx.stroke();

            vp.octx.restore();
        }
    });

    // Broadcast end of render cycle to plugins (UI updates, etc.)
    window.wb.emit('onRenderEnd');
}

// --- DRAW PRIMITIVES ---
function drawTransformUI(ctx, b) {
    const handleSize = 10 / getActiveViewport().transform.scale;
    ctx.save();
    ctx.strokeStyle = 'rgba(0, 122, 255, 0.5)';
    ctx.lineWidth = 1 / getActiveViewport().transform.scale;
    ctx.setLineDash([5, 5]);
    ctx.strokeRect(b.x, b.y, b.w, b.h);
    
    ctx.setLineDash([]);
    ctx.fillStyle = 'white';
    ctx.strokeStyle = '#007aff';
    ctx.lineWidth = 2 / getActiveViewport().transform.scale;

    const corners = [
        {x: b.x, y: b.y}, // nw
        {x: b.x + b.w, y: b.y}, // ne
        {x: b.x, y: b.y + b.h}, // sw
        {x: b.x + b.w, y: b.y + b.h} // se
    ];

    corners.forEach(c => {
        ctx.beginPath();
        ctx.arc(c.x, c.y, handleSize / 2, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
    });
    ctx.restore();
}

function drawSelectionHull(targetCtx, stroke) {
    targetCtx.save();
    // 1. Setup High-Contrast Outline (Shadows are too expensive for battery)
    targetCtx.strokeStyle = 'rgba(0,0,0,0.2)';
    targetCtx.lineWidth = (stroke.width || 4) + 12;

    // 2. Setup Outline Style
    targetCtx.strokeStyle = 'white';
    targetCtx.lineCap = 'round';
    targetCtx.lineJoin = 'round';
    targetCtx.globalAlpha = 0.8;

    if (stroke.type === 'path') {
        targetCtx.lineWidth = (stroke.width || (stroke.points[0] ? stroke.points[0].w : 4)) + 8;
        targetCtx.beginPath();
        targetCtx.moveTo(stroke.points[0].x, stroke.points[0].y);
        if (stroke.points.length === 2) {
            targetCtx.lineTo(stroke.points[1].x, stroke.points[1].y);
        } else {
            for (let i = 1; i < stroke.points.length - 1; i++) {
                const p1 = stroke.points[i];
                const p2 = stroke.points[i + 1];
                targetCtx.quadraticCurveTo(p1.x, p1.y, (p1.x + p2.x) / 2, (p1.y + p2.y) / 2);
            }
            const last = stroke.points[stroke.points.length - 1];
            targetCtx.lineTo(last.x, last.y);
        }
        targetCtx.stroke();
    } else if (stroke.type === 'text') {
        const fontStyle = (stroke.italic ? 'italic ' : '') + (stroke.bold ? 'bold ' : '');
        const fontSize = stroke.fontSize || 24;
        targetCtx.font = `${fontStyle}${fontSize}px ${stroke.fontFamily || 'sans-serif'}`;
        targetCtx.textAlign = stroke.align || 'left';
        targetCtx.textBaseline = 'top';
        targetCtx.lineWidth = 8;
        const lines = (stroke.content || "").split('\n');
        lines.forEach((line, i) => {
            targetCtx.strokeText(line, stroke.x, stroke.y + (i * fontSize * 1.2));
        });
    } else if (stroke.type === 'blank') {
        const b = stroke._b || wbCalculateStrokeBounds(stroke);
        targetCtx.lineWidth = 4;
        targetCtx.strokeRect(b.x - 4, b.y - 4, b.w + 8, b.h + 8);
    } else {
        // Image or Group
        const sw = stroke.w || 50;
        const sh = stroke.h || 50;
        const sx = (stroke.type === 'vector_group') ? (stroke.x - sw/2) : stroke.x;
        const sy = (stroke.type === 'vector_group') ? (stroke.y - sh/2) : stroke.y;
        targetCtx.lineWidth = 4;
        targetCtx.strokeRect(sx - 4, sy - 4, sw + 8, sh + 8);
    }
    targetCtx.restore();
}

function wbIsStrokeInViewport(stroke, vp) {
    const b = stroke._b || wbCalculateStrokeBounds(stroke);
    const rect = vp.canvas.getBoundingClientRect();
    const tl = getCanvasCoords({ clientX: rect.left, clientY: rect.top }, vp);
    const br = getCanvasCoords({ clientX: rect.right, clientY: rect.bottom }, vp);
    
    const vMinX = Math.min(tl.x, br.x); const vMaxX = Math.max(tl.x, br.x);
    const vMinY = Math.min(tl.y, br.y); const vMaxY = Math.max(tl.y, br.y);

    return (b.x + b.w >= vMinX && b.x <= vMaxX && b.y + b.h >= vMinY && b.y <= vMaxY);
}

function wbGetStrokeCache(stroke, vp) {
    if (stroke._cache !== undefined) return stroke._cache;
    
    // Only cache paths that are actually visible to save massive amounts of RAM
    if (!wbIsStrokeInViewport(stroke, vp)) return null;

    if (stroke.type !== 'path' || stroke.points.length < 4) {
        stroke._cache = null;
        return null;
    }

    const b = stroke._b || wbCalculateStrokeBounds(stroke);
    if (b.w > 4000 || b.h > 4000 || b.w === 0 || b.h === 0) {
        stroke._cache = null;
        return null;
    }

    if (window._cachesCreatedThisFrame > 15) return null; 
    window._cachesCreatedThisFrame = (window._cachesCreatedThisFrame || 0) + 1;

    const pad = (stroke.width || (stroke.points[0] ? stroke.points[0].w : 10)) + 5;
    const canvas = document.createElement('canvas');
    
    // MEMORY GOVERNOR: Limit the maximum internal resolution to prevent browser crashes
    let dpr = window.cacheResolution || 3.0;
    const MAX_CANVAS_DIM = 2048; // Sane limit for hidden cache elements
    if ((b.w + pad * 2) * dpr > MAX_CANVAS_DIM || (b.h + pad * 2) * dpr > MAX_CANVAS_DIM) {
        dpr = Math.min(MAX_CANVAS_DIM / (b.w + pad * 2), MAX_CANVAS_DIM / (b.h + pad * 2));
    }

    canvas.width = Math.max(1, (b.w + pad * 2) * dpr);
    canvas.height = Math.max(1, (b.h + pad * 2) * dpr);
    
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        stroke._cache = null; // Browser refused allocation
        return null;
    }
    ctx.scale(dpr, dpr);
    ctx.translate(-(b.x - pad), -(b.y - pad));
    
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = stroke.color;
    ctx.lineWidth = stroke.width || (stroke.points[0] ? stroke.points[0].w : 4);
    
    ctx.beginPath();
    ctx.moveTo(stroke.points[0].x, stroke.points[0].y);
    for (let i = 1; i < stroke.points.length - 1; i++) {
        const p1 = stroke.points[i];
        const p2 = stroke.points[i + 1];
        ctx.quadraticCurveTo(p1.x, p1.y, (p1.x + p2.x) / 2, (p1.y + p2.y) / 2);
    }
    const last = stroke.points[stroke.points.length - 1];
    ctx.lineTo(last.x, last.y);
    ctx.stroke();
    
    stroke._cache = canvas;
    stroke._cachePad = pad;
    
    if (window._cachesCreatedThisFrame === 15) requestRender();
    
    return canvas;
}

function drawStroke(targetCtx, stroke, scale = 1, forceVector = false, vp = null) {
    if (stroke.type === 'path') {
        const threshold = (window.vectorThreshold || 1000) / 100;
        const useCache = (window.rasterCacheEnabled !== false) && scale <= threshold && !forceVector;
        
        if (useCache && vp) {
            const cache = wbGetStrokeCache(stroke, vp);
            if (cache) {
                targetCtx.save();
                const isMoving = isInteracting && (stroke.id !== currentStroke?.id);
                targetCtx.globalCompositeOperation = isMoving ? 'source-over' : (stroke.composite || 'source-over');
                targetCtx.globalAlpha = isMoving ? (stroke.opacity * 0.8) : (stroke.opacity || 1.0);
                
                const b = stroke._b;
                const pad = stroke._cachePad;
                targetCtx.drawImage(cache, b.x - pad, b.y - pad, b.w + pad * 2, b.h + pad * 2);
                targetCtx.restore();
                return;
            }
        }

        targetCtx.save();
        targetCtx.lineCap = 'round';
        targetCtx.lineJoin = 'round';
        
        // Interaction Fidelity Drop: Skip expensive blend modes during motion
        // BUDGET CHECK: If we are in raster mode but the cache isn't ready,
        // only draw the vector if we haven't hit our CPU limit for this frame.
        if (useCache && !forceVector) {
            if (window._vectorBudget <= 0) return; // Skip drawing to prevent freeze
            window._vectorBudget--;
        }

        const isMoving = isInteracting && (stroke.id !== currentStroke?.id);
        targetCtx.globalCompositeOperation = isMoving ? 'source-over' : (stroke.composite || 'source-over');
        targetCtx.globalAlpha = isMoving ? (stroke.opacity * 0.8) : (stroke.opacity || 1.0);
        targetCtx.strokeStyle = stroke.color;
        
        if (stroke.points.length < 2) { targetCtx.restore(); return; }

        // Vector LOD: Simplify paths when zoomed out
        // If scale is 0.1 (10%), we only draw every 4th point.
        let step = 1;
        if (scale < 0.15) step = 4;
        else if (scale < 0.4) step = 2;

        targetCtx.beginPath();
        targetCtx.moveTo(stroke.points[0].x, stroke.points[0].y);
        targetCtx.lineWidth = stroke.width || (stroke.points[0] ? stroke.points[0].w : brushWidth);
        
        if (stroke.points.length === 2) {
            targetCtx.lineTo(stroke.points[1].x, stroke.points[1].y);
        } else {
            for (let i = 1; i < stroke.points.length - 1; i += step) {
                const p1 = stroke.points[i];
                const p2 = stroke.points[Math.min(i + step, stroke.points.length - 1)];
                targetCtx.quadraticCurveTo(p1.x, p1.y, (p1.x + p2.x) / 2, (p1.y + p2.y) / 2);
            }
            const last = stroke.points[stroke.points.length - 1];
            targetCtx.lineTo(last.x, last.y);
        }
        targetCtx.stroke();
        targetCtx.restore();
    } else if (stroke.type === 'mask') {
        targetCtx.save();
        targetCtx.globalCompositeOperation = 'destination-out';
        targetCtx.fillStyle = 'black';
        targetCtx.beginPath();
        stroke.points.forEach((p, i) => {
            if (i === 0) targetCtx.moveTo(p.x, p.y);
            else targetCtx.lineTo(p.x, p.y);
        });
        targetCtx.closePath();
        targetCtx.fill();
        targetCtx.restore();
    } else if (stroke.type === 'vector_group') {
    targetCtx.save();
    // Align the lasso's original center with its new dropped position (Fixes Issue 2)
    targetCtx.translate(stroke.x - stroke.origX, stroke.y - stroke.origY);
            
    // Apply the lasso clipping mask
    targetCtx.beginPath();
    stroke.lasso.forEach((p, i) => {
        if (i === 0) targetCtx.moveTo(p.x, p.y);
        else targetCtx.lineTo(p.x, p.y);
    });
    targetCtx.closePath();
    targetCtx.clip();

    // Draw the contained strokes
    stroke.strokes.forEach(s => drawStroke(targetCtx, s, scale));
    targetCtx.restore();} else if (stroke.type === 'text') {
        targetCtx.save();
        targetCtx.globalCompositeOperation = stroke.composite || 'source-over';
        targetCtx.fillStyle = stroke.color || '#000000';
        
        const fontStyle = (stroke.italic ? 'italic ' : '') + (stroke.bold ? 'bold ' : '');
        const fontSize = stroke.fontSize || 24;
        const fontFamily = stroke.fontFamily || 'sans-serif';
        targetCtx.font = `${fontStyle}${fontSize}px ${fontFamily}`;
        targetCtx.textAlign = stroke.align || 'left';
        targetCtx.textBaseline = 'top';

        const lines = (stroke.content || "").split('\n');
        const lineHeight = fontSize * 1.2;

        lines.forEach((line, i) => {
            const ly = stroke.y + (i * lineHeight);
            targetCtx.fillText(line, stroke.x, ly);
            
            if (stroke.underline) {
                const metrics = targetCtx.measureText(line);
                const underlineY = ly + fontSize * 0.95;
                targetCtx.beginPath();
                targetCtx.lineWidth = Math.max(1, fontSize / 15);
                targetCtx.strokeStyle = stroke.color || '#000000';
                targetCtx.moveTo(stroke.x, underlineY);
                targetCtx.lineTo(stroke.x + metrics.width, underlineY);
                targetCtx.stroke();
            }
        });
        targetCtx.restore();
    } else if (stroke.type === 'blank') {
        targetCtx.save();
        const color = stroke.color || '#000000';
        const fontSize = stroke.fontSize || 24;
        const fontStyle = (stroke.italic ? 'italic ' : '') + (stroke.bold ? 'bold ' : '');
        const fontFamily = stroke.fontFamily || 'sans-serif';
        const labelRatio = stroke.labelRatio || window.grammarLabelRatio || 0.4;
        const labelFontSize = Math.max(8, fontSize * labelRatio);
        
        // 1. Draw Underline
        targetCtx.strokeStyle = color;
        targetCtx.lineWidth = Math.max(1, fontSize / 12);
        targetCtx.beginPath();
        targetCtx.moveTo(stroke.x, stroke.y);
        targetCtx.lineTo(stroke.x + stroke.w, stroke.y);
        targetCtx.stroke();

        // 2. Draw Content (Above line)
        if (stroke.content) {
            targetCtx.fillStyle = color;
            targetCtx.font = `${fontStyle}${fontSize}px ${fontFamily}`;
            targetCtx.textAlign = 'center';
            targetCtx.textBaseline = 'bottom';
            const lines = stroke.content.split('\n');
            const lineHeight = fontSize * 1.2;
            // Draw from bottom up so the last line sits exactly on the underline
            lines.reverse().forEach((line, i) => {
                targetCtx.fillText(line, stroke.x + stroke.w / 2, stroke.y - 2 - (i * lineHeight));
            });
        }

        // 3. Draw Label (Below line)
        if (stroke.label) {
            targetCtx.fillStyle = color;
            targetCtx.globalAlpha = 0.7;
            targetCtx.font = `bold ${labelFontSize}px ${fontFamily}`;
            targetCtx.textAlign = 'center';
            targetCtx.textBaseline = 'top';
            targetCtx.fillText(stroke.label, stroke.x + stroke.w / 2, stroke.y + (labelFontSize * 0.4));
        }
        targetCtx.restore();
    } else if (stroke.type === 'image' || stroke.type === 'pdf_page' || stroke.type === 'docx_page') {
    let src = stroke.data;
    if (stroke.assetId) {
        const cacheKey = (stroke.type === 'image') ? stroke.assetId : `${stroke.type}_${stroke.assetId}_p${stroke.page || 1}`;
        const cached = wbImageCache[cacheKey];
        if (cached && cached !== 'loading') {
            src = cached.src;
        } else {
            if (stroke.type === 'image') {
                fetchAsset(stroke.assetId);
            } else {
                wbRenderSessionAsset(stroke);
            }
            return; 
        }
    }
    const img = wbGetImage(src);
    if (img.complete && img.naturalWidth > 0) {
        // Adaptive Rendering: Use Mipmap if zoomed out past 75% (scale < 0.25)
        // Force full resolution if the item is selected for editing.
        const isSelected = wbSelection.ids.has(stroke.id);
        const useFullRes = isSelected || (scale >= 0.25) || !img.thumb;
        const drawSrc = useFullRes ? img : img.thumb;
        targetCtx.drawImage(drawSrc, stroke.x, stroke.y, stroke.w, stroke.h);
    }
}}

async function wbRenderSessionAsset(stroke) {
    const hash = stroke.assetId;
    const pageNum = stroke.page || 1;
    const quality = stroke.quality || 2.0;
    const cacheKey = `${stroke.type}_${hash}_p${pageNum}`;

    if (wbImageCache[cacheKey] === 'loading') return;
    wbImageCache[cacheKey] = 'loading';

    try {
        // 1. Get Source Binary
        let sourceData = await getLocalAsset(hash);
        if (!sourceData) {
            const fd = new FormData();
            fd.append('action', 'get_asset');
            fd.append('hash', hash);
            const assetRes = await fetch('index.php', { method: 'POST', body: fd });
            const assetData = await assetRes.json();
            if (!assetData.url) throw new Error("Asset not found");
            
            const res = await fetch(assetData.url);
            const blob = await res.blob();
            sourceData = await new Promise(r => {
                const reader = new FileReader();
                reader.onloadend = () => r(reader.result);
                reader.readAsDataURL(blob);
            });
            await saveLocalAsset(hash, sourceData);
        }

        // Convert DataURL back to ArrayBuffer for processing
        const binary = atob(sourceData.split(',')[1]);
        const array = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) array[i] = binary.charCodeAt(i);
        const buffer = array.buffer;

        let blobUrl = null;

        if (stroke.type === 'pdf_page') {
            const pdf = await pdfjsLib.getDocument({ data: buffer }).promise;
            const page = await pdf.getPage(pageNum);
            const viewport = page.getViewport({ scale: quality });
            const canvas = document.createElement('canvas');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            await page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
            blobUrl = await new Promise(r => canvas.toBlob(b => r(URL.createObjectURL(b)), 'image/jpeg', 0.9));
        } else if (stroke.type === 'docx_page') {
            const container = document.createElement('div');
            container.style.cssText = "position:absolute; left:-9999px; width:816px; background:white;";
            document.body.appendChild(container);
            await docx.renderAsync(buffer, container, null, { inWrapper: false });
            const sections = container.querySelectorAll('section.docx');
            const section = sections[pageNum - 1] || sections[0];
            const canvas = await html2canvas(section, { scale: quality, backgroundColor: '#ffffff' });
            blobUrl = await new Promise(r => canvas.toBlob(b => r(URL.createObjectURL(b)), 'image/jpeg', 0.8));
            document.body.removeChild(container);
        }

        if (blobUrl) {
            const img = new Image();
            img.onload = () => {
                wbGenerateMipmap(img);
                wbImageCache[cacheKey] = img;
                // Force multiple frames to ensure browser composite catch-up
                requestRender();
                setTimeout(requestRender, 50);
                setTimeout(requestRender, 200);
            };
            img.src = blobUrl;
        }
    } catch (e) {
        console.error("Session Render Failed:", e);
        wbImageCache[cacheKey] = null;
    }
}

async function fetchAsset(hash) {
    if (wbImageCache[hash] && wbImageCache[hash] !== 'loading') return wbImageCache[hash];
    
    if (wbImageCache[hash] === 'loading') {
        return new Promise(resolve => {
            const check = setInterval(() => {
                if (wbImageCache[hash] !== 'loading') {
                    clearInterval(check);
                    resolve(wbImageCache[hash]);
                }
            }, 50);
        });
    }

    wbImageCache[hash] = 'loading';

    return new Promise(async (resolve) => {
        let data = await getLocalAsset(hash);
        let url = null;

        if (!data) {
    const fd = new FormData();
    fd.append('action', 'get_asset');
    fd.append('hash', hash);
    try {
        const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 15000);
        const result = await res.json();data = result.data;
                url = result.url;
                if (data) await saveLocalAsset(hash, data);
            } catch(e) { console.error("Asset fetch failed", e); }
        }

        let finalSrc = url ? url : data;

        // If the server gave us a URL (physical file), we must fetch the actual bytes 
        // and convert to a DataURL so it can be stored in IndexedDB for offline use.
        if (url && !data) {
            try {
                const blobRes = await fetch(url);
                const blob = await blobRes.blob();
                finalSrc = await new Promise(r => {
                    const reader = new FileReader();
                    reader.onloadend = () => r(reader.result);
                    reader.readAsDataURL(blob);
                });
                // Save the converted binary to local storage
                await saveLocalAsset(hash, finalSrc);
            } catch (e) { console.error("Sync: Failed to localize physical asset", e); }
        }

        if (finalSrc) {
            const img = new Image();
            img.onload = () => {
                wbGenerateMipmap(img);
                wbImageCache[hash] = img;
                render();
                resolve(img);
            };
            img.onerror = () => {
                wbImageCache[hash] = null;
                resolve(null);
            };
            img.src = finalSrc;
        } else {
            wbImageCache[hash] = null;
            resolve(null);
        }
    });
}

// --- COORDINATE MATH & HISTORY ---
function getCanvasCoords(e, vp = getActiveViewport()) {
    const rect = vp.canvas.getBoundingClientRect();
    const clientX = (typeof e.clientX !== 'undefined') ? e.clientX : (e.touches ? e.touches[0].clientX : 0);
    const clientY = (typeof e.clientY !== 'undefined') ? e.clientY : (e.touches ? e.touches[0].clientY : 0);
    const x = clientX - rect.left;
    const y = clientY - rect.top;

    // 1. Remove Pan
    const dx = x - vp.transform.x;
    const dy = y - vp.transform.y;
    
    // 2. Apply Inverse Rotation
    const cos = Math.cos(-vp.transform.rotation);
    const sin = Math.sin(-vp.transform.rotation);
    const rx = dx * cos - dy * sin;
    const ry = dx * sin + dy * cos;
    
    // 3. Remove Zoom
    return {
        x: rx / vp.transform.scale,
        y: ry / vp.transform.scale
    };
}



function undo() {
    if (undoStack.length === 0) return;
    
    // Save current state to redo before going back
    redoStack.push(JSON.stringify(allStrokes));
    
    // Restore previous state
    allStrokes = JSON.parse(undoStack.pop());
    
    render();
    updateUndoRedoButtons();
    if (autoSaveEnabled) saveDrawing();
}

function redo() {
    if (redoStack.length === 0) return;
    
    // Save current state to undo before going forward
    undoStack.push(JSON.stringify(allStrokes));
    
    // Restore state from redo stack
    allStrokes = JSON.parse(redoStack.pop());
    
    render();
    updateUndoRedoButtons();
    if (autoSaveEnabled) saveDrawing();
}

async function clearCanvas() {
    if (await wbui.confirm("Clear whiteboard?", "Clear Canvas", wbIcons.trash)) {
        allStrokes = [];
        redoStack = [];
        render();
        saveDrawing();
    }
}

window.resetZoom = function() {
    const vp = getActiveViewport();
    // If already at 100%, perform "Zoom to Fit"
    const isAt100 = Math.abs(vp.transform.scale - 1.0) < 0.01;
    centerOnContent(isAt100);
    if (window.navigator.vibrate) navigator.vibrate(5);
};

window.wbGoHome = function() {
    const vp = getActiveViewport();
    const rect = vp.canvas.getBoundingClientRect();
    
    // Smoothly return to 0,0 at 100% zoom
    vp.transform.x = rect.width / 2;
    vp.transform.y = rect.height / 2;
    vp.transform.scale = 1;
    vp.transform.rotation = 0;
    
    render();
    if (typeof saveViewState === 'function') saveViewState();
    if (window.navigator.vibrate) navigator.vibrate(25);
    
    const pill = document.getElementById('status-pill');
    if (pill) {
        pill.innerText = "Returned to Origin";
        pill.style.opacity = "1";
        setTimeout(() => pill.style.opacity = "0", 1500);
    }
};

// Initialize Zoom Pill Dual-Action (Tap vs Hold)
(function() {
    const el = document.getElementById('zoom-indicator');
    if (!el) return;
    let timer = null;
    let isLp = false;

    el.onpointerdown = (e) => {
        if (e.button !== 0) return;
        isLp = false;
        el.setPointerCapture(e.pointerId);
        timer = setTimeout(() => {
            isLp = true;
            wbGoHome();
            timer = null;
        }, 600);
    };

    el.onpointerup = (e) => {
        el.releasePointerCapture(e.pointerId);
        if (timer) {
            clearTimeout(timer);
            timer = null;
            resetZoom();
        }
    };

    el.onpointercancel = el.onpointerleave = () => {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    };
})();

function drawOriginMarker(vp) {
    const ctx = vp.ctx;
    const size = 20 / vp.transform.scale;
    
    ctx.save();
    ctx.strokeStyle = cachedPatternColor;
    ctx.globalAlpha = 0.5;
    ctx.lineWidth = 2 / vp.transform.scale;
    
    // Draw a subtle '+' at 0,0
    ctx.beginPath();
    ctx.moveTo(-size, 0); ctx.lineTo(size, 0);
    ctx.moveTo(0, -size); ctx.lineTo(0, size);
    ctx.stroke();
    
    // Draw a small circle
    ctx.beginPath();
    ctx.arc(0, 0, 4 / vp.transform.scale, 0, Math.PI * 2);
    ctx.stroke();
    ctx.restore();
}

let coordHideTimer = null;
let lastCoordChipValue = '';
let coordChipWasVisible = false;

function updateCoordChip(vp) {
    const chip = document.getElementById('coord-chip');
    if (!chip) return;
    
    const rect = vp.canvas.getBoundingClientRect();
    const worldCenter = getCanvasCoords({ 
        clientX: rect.left + rect.width/2, 
        clientY: rect.top + rect.height/2 
    }, vp);
    
    const x = Math.round(worldCenter.x);
    const y = Math.round(worldCenter.y);
    const nextValue = `${x}, ${y}`;

    // Rendering can occur many times per second. Avoid touching the DOM
    // unless the displayed coordinate actually changed.
    if (nextValue === lastCoordChipValue && coordChipWasVisible) return;

    if (nextValue !== lastCoordChipValue) {
        chip.innerText = nextValue;
        lastCoordChipValue = nextValue;
    }

    // Show on activity
    chip.classList.add('visible');
    coordChipWasVisible = true;

    if (coordHideTimer) clearTimeout(coordHideTimer);
    coordHideTimer = setTimeout(() => {
        chip.classList.remove('visible');
        coordChipWasVisible = false;
        coordHideTimer = null;
    }, 2000); // Stay visible for 2 seconds after last movement
}

function getCharVerticalEnvelope(char, fontSize) {
    const c = char.toLowerCase();
    let minY = 0.1;
    let maxY = 0.85;

    if (/[acemnorsuvwxz]/.test(c)) {
        // Lowercase flat letters
        minY = 0.35;
        maxY = 0.8;
    } else if (/[gjpqy]/.test(c)) {
        // Descenders
        minY = 0.35;
        maxY = 1.0;
    } else if (/[bdfhklt]/.test(c) || char !== c || /[0-9]/.test(char)) {
        // Ascenders, capitals, and digits
        minY = 0.1;
        maxY = 0.8;
    } else if (char === ',' || char === '.') {
        minY = 0.7;
        maxY = 0.95;
    } else if (char === '-' || char === '~') {
        minY = 0.45;
        maxY = 0.55;
    }
    
    return { minY, maxY };
}

function preciseTextHitTest(x, y, stroke, threshold) {
    const lines = (stroke.content || "").split('\n');
    const fontSize = stroke.fontSize || 24;
    const lineHeight = fontSize * 1.2;
    const fontStyle = (stroke.italic ? 'italic ' : '') + (stroke.bold ? 'bold ' : '');
    const fontFamily = stroke.fontFamily || 'sans-serif';
    
    if (!window._wbMeasureCtx) {
        window._wbMeasureCtx = document.createElement('canvas').getContext('2d');
    }
    window._wbMeasureCtx.font = `${fontStyle}${fontSize}px ${fontFamily}`;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const ly = stroke.y + (i * lineHeight);
        
        // Fast line-level vertical check first
        if (y >= ly - threshold - 5 && y <= ly + fontSize + threshold + 5) {
            const lineW = window._wbMeasureCtx.measureText(line).width;
            let startX = stroke.x;
            if (stroke.align === 'center') startX = stroke.x - (lineW / 2);
            else if (stroke.align === 'right') startX = stroke.x - lineW;

            if (x >= startX - threshold && x <= startX + lineW + threshold) {
                let currentX = startX;
                for (let char of line) {
                    const charW = window._wbMeasureCtx.measureText(char).width;
                    if (char.trim() !== "") {
                        // Fetch tight typographic envelope for this specific character
                        const env = getCharVerticalEnvelope(char, fontSize);
                        const cMinY = ly + fontSize * env.minY;
                        const cMaxY = ly + fontSize * env.maxY;
                        
                        // Shave off side bearings (glyphs occupy middle 80% of advance width)
                        const cMinX = currentX + charW * 0.1;
                        const cMaxX = currentX + charW * 0.9;

                        const hitX = (x >= cMinX - threshold && x <= cMaxX + threshold);
                        const hitY = (y >= cMinY - threshold && y <= cMaxY + threshold);

                        if (hitX && hitY) {
                            return true;
                        }
                    }
                    currentX += charW;
                }
            }
        }
    }
    return false;
}

function preciseBlankHitTest(x, y, stroke, threshold) {
    // 1. Underline check (4px buffer for the visible line thickness)
    const distToLineY = Math.abs(y - stroke.y);
    const isOverLineX = (x >= stroke.x - threshold && x <= stroke.x + stroke.w + threshold);
    if (distToLineY <= threshold + 4 && isOverLineX) {
        return true;
    }

    const fontSize = stroke.fontSize || 24;
    const fontFamily = stroke.fontFamily || 'sans-serif';

    // 2. Text Content check
    if (stroke.content) {
        const fontStyle = (stroke.italic ? 'italic ' : '') + (stroke.bold ? 'bold ' : '');
        if (!window._wbMeasureCtx) {
            window._wbMeasureCtx = document.createElement('canvas').getContext('2d');
        }
        window._wbMeasureCtx.font = `${fontStyle}${fontSize}px ${fontFamily}`;

        const lines = stroke.content.split('\n');
        const lineHeight = fontSize * 1.2;
        for (let i = 0; i < lines.length; i++) {
            const line = lines[lines.length - 1 - i];
            const ly = stroke.y - 2 - ((i + 1) * lineHeight);
            
            if (y >= ly - threshold - 5 && y <= ly + fontSize + threshold + 5) {
                const lineW = window._wbMeasureCtx.measureText(line).width;
                const startX = stroke.x + (stroke.w / 2) - (lineW / 2);
                let currentX = startX;
                for (let char of line) {
                    const charW = window._wbMeasureCtx.measureText(char).width;
                    if (char.trim() !== "") {
                        const env = getCharVerticalEnvelope(char, fontSize);
                        const cMinY = ly + fontSize * env.minY;
                        const cMaxY = ly + fontSize * env.maxY;
                        const cMinX = currentX + charW * 0.1;
                        const cMaxX = currentX + charW * 0.9;

                        if (x >= cMinX - threshold && x <= cMaxX + threshold &&
                            y >= cMinY - threshold && y <= cMaxY + threshold) {
                            return true;
                        }
                    }
                    currentX += charW;
                }
            }
        }
    }

    // 3. Label check
    if (stroke.label) {
        const labelRatio = stroke.labelRatio || window.grammarLabelRatio || 0.4;
        const labelFontSize = Math.max(8, fontSize * labelRatio);
        if (!window._wbMeasureCtx) {
            window._wbMeasureCtx = document.createElement('canvas').getContext('2d');
        }
        window._wbMeasureCtx.font = `bold ${labelFontSize}px ${fontFamily}`;

        const ly = stroke.y + (labelFontSize * 0.4);
        if (y >= ly - threshold - 5 && y <= ly + labelFontSize + threshold + 5) {
            const lineW = window._wbMeasureCtx.measureText(stroke.label).width;
            const startX = stroke.x + (stroke.w / 2) - (lineW / 2);
            let currentX = stroke.label ? stroke.x + (stroke.w / 2) - (lineW / 2) : stroke.x;
            for (let char of stroke.label) {
                const charW = window._wbMeasureCtx.measureText(char).width;
                if (char.trim() !== "") {
                    const env = getCharVerticalEnvelope(char, labelFontSize);
                    const cMinY = ly + labelFontSize * env.minY;
                    const cMaxY = ly + labelFontSize * env.maxY;
                    const cMinX = currentX + charW * 0.1;
                    const cMaxX = currentX + charW * 0.9;

                    if (x >= cMinX - threshold && x <= cMaxX + threshold &&
                        y >= cMinY - threshold && y <= cMaxY + threshold) {
                        return true;
                    }
                }
                currentX += charW;
            }
        }
    }

    return false;
}

// --- HIT TESTING ---
function findStrokeAt(x, y, threshold = 15, allowedTypes = null, ignoredTypes = null) {
    for (let i = allStrokes.length - 1; i >= 0; i--) {
        const stroke = allStrokes[i];
        if (allowedTypes && !allowedTypes.includes(stroke.type)) continue;
        if (ignoredTypes && ignoredTypes.includes(stroke.type)) continue;

        // Optimization: Skip strokes if the point is nowhere near the bounding box
        if (stroke._b) {
            const b = stroke._b;
            const p = threshold + 10;
            if (x < b.x - p || x > b.x + b.w + p || y < b.y - p || y > b.y + b.h + p) continue;
        }
        
        if (stroke.type === 'text' || stroke.type === 'blank') {
            const b = stroke._b || wbCalculateStrokeBounds(stroke);
            if (x >= b.x - threshold && x <= b.x + b.w + threshold && 
                y >= b.y - threshold && y <= b.y + b.h + threshold) {
                
                // Eraser precision gate (only trigger if touching actual characters/lines)
                if (threshold < 5) {
                    if (stroke.type === 'text') {
                        if (!preciseTextHitTest(x, y, stroke, threshold)) continue;
                    } else if (stroke.type === 'blank') {
                        if (!preciseBlankHitTest(x, y, stroke, threshold)) continue;
                    }
                }
                return i;
            }
            continue;
        }
        
        if (stroke.type === 'image') {
            if (x >= stroke.x && x <= stroke.x + stroke.w && y >= stroke.y && y <= stroke.y + stroke.h) return i;
            continue;
        }
        
        if (stroke.type === 'vector_group') {
            const sw = stroke.w || 0;
            const sh = stroke.h || 0;
            const sx = stroke.x - sw/2;
            const sy = stroke.y - sh/2;
            if (x >= sx && x <= sx + sw && y >= sy && y <= sy + sh) return i;
            continue;
        }



        if (stroke.type !== 'path') continue;
        
        // Check distance to every segment in the path
        for (let j = 0; j < stroke.points.length - 1; j++) {
            const A = stroke.points[j];
            const B = stroke.points[j + 1];
            
            // Distance from Point (x,y) to Line Segment (A-B)
            const dx = B.x - A.x;
            const dy = B.y - A.y;
            const l2 = dx * dx + dy * dy;
            if (l2 === 0) continue; 
            
            let t = ((x - A.x) * dx + (y - A.y) * dy) / l2;
            t = Math.max(0, Math.min(1, t));
            
            const closestX = A.x + t * dx;
            const closestY = A.y + t * dy;
            const dist = Math.hypot(x - closestX, y - closestY);
            
            if (dist < threshold + (A.w / 2)) return i;
        }
        
        // Fallback for single-point strokes
        if (stroke.points.length === 1) {
            const p = stroke.points[0];
            if (Math.hypot(x - p.x, y - p.y) < threshold + (p.w / 2)) return i;
        }
    }
    return -1;
}

function isPointInPoly(p, poly) {
    let isInside = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        if (((poly[i].y > p.y) !== (poly[j].y > p.y)) &&
            (p.x < (poly[j].x - poly[i].x) * (p.y - poly[i].y) / (poly[j].y - poly[i].y) + poly[i].x)) {
            isInside = !isInside;
        }
    }
    return isInside;
}