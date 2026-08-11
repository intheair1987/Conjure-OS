/**
 * WHITEBOARD SELECTION & LIBRARY MANAGER
 * Handles lasso selection, moving, clipboard, and the sticker library.
 */

function finalizeSelection(clientX, clientY, pointerType = 'touch') {
    viewports.forEach(v => v.octx.clearRect(0, 0, v.overlay.width, v.overlay.height));
    const vp = getActiveViewport();
    wbSelection.ids.clear();

    const ignoredTypes = (pointerType === 'pen') ? ['text', 'blank'] : null;

    // 1. If lasso was just a tap, find the object under the pointer
    if (lassoPoints.length < 5) {
        const coords = getCanvasCoords({ clientX, clientY }, vp);
        const hitIdx = findStrokeAt(coords.x, coords.y, 15, null, ignoredTypes);
        if (hitIdx !== -1) wbSelection.ids.add(allStrokes[hitIdx].id);
    } else {
        // 2. Standard Lasso logic
        allStrokes.forEach(s => {
            if (ignoredTypes && ignoredTypes.includes(s.type)) return;
            let hit = false;
            if (s.type === 'path') hit = s.points.some(p => isPointInPoly(p, lassoPoints));
            else hit = isPointInPoly({x: s.x, y: s.y}, lassoPoints);
            if (hit) wbSelection.ids.add(s.id);
        });
    }

    if (wbSelection.ids.size === 0) {
        render(); 
        return;
    }

    // 3. Calculate bounding box
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    const selectedStrokes = allStrokes.filter(s => wbSelection.ids.has(s.id));
    
    selectedStrokes.forEach(s => {
        const b = wbCalculateStrokeBounds(s);
        minX = Math.min(minX, b.x); minY = Math.min(minY, b.y);
        maxX = Math.max(maxX, b.x + b.w); maxY = Math.max(maxY, b.y + b.h);
    });

    wbSelection.bounds = { x: minX, y: minY, w: maxX - minX, h: maxY - minY };

    const menu = document.getElementById('selection-menu');
const editBtn = document.getElementById('selection-edit-btn');
if (editBtn) {
    editBtn.style.display = (selectedStrokes.length === 1 && (selectedStrokes[0].type === 'text' || selectedStrokes[0].type === 'blank')) ? 'block' : 'none';
}
    
wbUpdateSelectionUI(true);render();

    // Switch to Pan temporarily for movement, without overwriting the user's base tool
    if (touchMode !== 'pan') {
        setTouchMode('pan', false);
    }
}

function handleSelectionAction(action) {
    const menu = document.getElementById('selection-menu');
    menu.style.display = 'none';

    if (action === 'edit') {
        const stroke = allStrokes.find(s => wbSelection.ids.has(s.id));
        if (stroke && (stroke.type === 'text' || stroke.type === 'blank')) {
            wbSelection.ids.clear();
            wbSelection.bounds = null;
            wbUpdateSelectionUI(false);
            spawnTextEditor(stroke.x, stroke.y, stroke);
        }
        return;
    }

    if (action === 'front' || action === 'back') {
        wbPushUndo();
        const selected = allStrokes.filter(s => wbSelection.ids.has(s.id)).sort((a, b) => a.zIndex - b.zIndex);
        const others = allStrokes.filter(s => !wbSelection.ids.has(s.id));
        
        if (action === 'front') {
            const maxZ = others.length > 0 ? Math.max(...others.map(o => o.zIndex)) : 0;
            selected.forEach((s, i) => s.zIndex = maxZ + 1 + i);
        } else {
            const minZ = others.length > 0 ? Math.min(...others.map(o => o.zIndex)) : 0;
            selected.forEach((s, i) => s.zIndex = minZ - selected.length + i);
        }
        
        allStrokes.sort((a, b) => a.zIndex - b.zIndex);
        render();
        if (autoSaveEnabled) saveDrawing();
        // Re-show menu since we didn't deselect
        menu.style.display = 'flex';
        return;
    }

    if (action === 'clear') {
        wbSelection.ids.clear();
        wbSelection.bounds = null;
        wbUpdateSelectionUI(false);
        const vp = getActiveViewport();
        vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height);
        setTouchMode(userSelectedTool);
        render();
        return;
    }

    const {x, y, w, h} = wbSelection.bounds;
    const strokes = allStrokes.filter(s => wbSelection.ids.has(s.id));

    if (action === 'copy' || action === 'cut' || action === 'move') {
        clipboard = {
            type: 'object_group',
            strokes: JSON.parse(JSON.stringify(strokes)),
            w: w, h: h,
            origX: x + w/2,
            origY: y + h/2
        };
        updatePasteButton();

        // Generate Raster Preview for Library
        const tempCanvas = document.createElement('canvas');
        const pad = 20;
        tempCanvas.width = (w + pad * 2) * 2;
        tempCanvas.height = (h + pad * 2) * 2;
        const tctx = tempCanvas.getContext('2d');
        tctx.scale(2, 2);
        tctx.translate(-x + pad, -y + pad);
        strokes.forEach(s => drawStroke(tctx, s, 2));
        saveStickerToLibrary(tempCanvas.toDataURL());
    }

    if (action === 'cut' || action === 'delete' || action === 'move') {
        wbPushUndo();
        wbSelection.ids.forEach(id => window.wbDeletedStrokeIds.add(id));
        allStrokes = allStrokes.filter(s => !wbSelection.ids.has(s.id));
        render();
        if (autoSaveEnabled) saveDrawing();
    }

    if (action === 'move') {
        isMoving = true;
        document.getElementById('move-controls').style.display = 'flex';
        document.body.classList.add('is-moving');
        updateMoveVisual();
    } else {
        // Auto-exit selection state and return to base tool for Copy, Cut, Delete
        wbSelection.ids.clear();
        wbSelection.bounds = null;
        wbUpdateSelectionUI(false);

        const vp = getActiveViewport();
        vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height);
        setTouchMode(userSelectedTool);
        render();
    }
}

function updatePasteButton() {
    const btn = document.getElementById('paste-btn');
    if (btn) btn.style.display = clipboard ? 'block' : 'none';
}

function paste() {
    if (!clipboard) return;
    const vp = getActiveViewport();
    isMoving = true;
    document.getElementById('move-controls').style.display = 'flex';
    if (touchMode !== 'pan') {
        touchModeBeforeMove = touchMode;
        setTouchMode('pan');
    }
    document.body.classList.add('is-moving');
    
    // Center the paste in World Space relative to the active viewport
    const rect = vp.canvas.getBoundingClientRect();
    const worldCenter = getCanvasCoords({ 
        clientX: rect.left + rect.width/2, 
        clientY: rect.top + rect.height/2 
    }, vp);
    
    selectionBounds = {
        x: worldCenter.x - clipboard.w/2,
        y: worldCenter.y - clipboard.h/2,
        w: clipboard.w,
        h: clipboard.h,
        strokes: clipboard.strokes // CRITICAL: Link the strokes to the selection
    };
    
    // Force immediate visual update
    render();
    updateMoveVisual();
}

function updateMoveVisual() {
    if (!clipboard) return;
    viewports.forEach(v => v.octx.clearRect(0, 0, v.overlay.width, v.overlay.height));
    const vp = getActiveViewport();
    
    vp.octx.save();
    const dpr = window.devicePixelRatio || 1;
    vp.octx.setTransform(vp.transform.scale * dpr, 0, 0, vp.transform.scale * dpr, vp.transform.x * dpr, vp.transform.y * dpr);
    
    vp.octx.globalAlpha = 0.6;
    
    // Calculate current offset from original position
    const dx = (selectionBounds.x + selectionBounds.w/2) - clipboard.origX;
    const dy = (selectionBounds.y + selectionBounds.h/2) - clipboard.origY;

    vp.octx.save();
    vp.octx.translate(dx, dy);
    clipboard.strokes.forEach(s => {
        drawSelectionHull(vp.octx, s);
        drawStroke(vp.octx, s, vp.transform.scale);
    });
    vp.octx.restore();
    
    vp.octx.strokeStyle = '#007aff';
    vp.octx.setLineDash([5, 5]);
    vp.octx.strokeRect(selectionBounds.x - 2, selectionBounds.y - 2, selectionBounds.w + 4, selectionBounds.h + 4);
    vp.octx.restore();
}

window.commitMove = function() {
    if (!clipboard || !selectionBounds) return;
    
    const dx = (selectionBounds.x + selectionBounds.w/2) - clipboard.origX;
    const dy = (selectionBounds.y + selectionBounds.h/2) - clipboard.origY;

    // Apply offset to all strokes in the group and add to main list
    clipboard.strokes.forEach(s => {
        const newStroke = JSON.parse(JSON.stringify(s));
        newStroke.id = wbCreateId();
        newStroke.zIndex = wbGetNextZIndex();
        if (newStroke.type === 'path') {
            newStroke.points.forEach(p => { p.x += dx; p.y += dy; });
        } else {
            newStroke.x += dx; newStroke.y += dy;
        }
        allStrokes.push(newStroke);
    });

    redoStack = []; // Clear redo history on new commit
    render();
    exitMoveMode();
    saveDrawing();
};

window.cancelMove = function() {
    exitMoveMode();
};

function toggleLibrary() {
    const drawer = document.getElementById('library-drawer');
    drawer.classList.toggle('open');
    if (drawer.classList.contains('open')) refreshLibrary();
}

async function saveStickerToLibrary(dataUrl) {
    const payload = {
        raster: dataUrl,
        vector: (clipboard && clipboard.type === 'vector_group') ? clipboard : null
    };
    const fd = new FormData();
    fd.append('action', 'save_sticker');
    fd.append('data', JSON.stringify(payload));
    
    try {
        await fetch('index.php', { method: 'POST', body: fd });
        // Live Update: Refresh the list if the drawer is currently open
        if (document.getElementById('library-drawer').classList.contains('open')) {
            refreshLibrary();
        }
    } catch(e) {
        console.error("Sticker save failed", e);
    }
}

async function refreshLibrary() {
    const list = document.getElementById('library-list');
    list.innerHTML = '<div style="grid-column:span 2; font-size:12px; color:var(--text-secondary); text-align:center;">Loading...</div>';
    
    let data;
    try {
        const fd = new FormData();
        fd.append('action', 'get_stickers');
        const res = await fetch('index.php', { method: 'POST', body: fd });
        data = await res.json();
    } catch (e) {
        console.warn("Offline: Loading Stickers from local cache.");
        const snap = await getMetadata('full_snapshot');
        data = { status: 'success', stickers: snap ? (snap.stickers || []) :[] };
    }
    
    list.innerHTML = '';
    window._stickerCache = {}; // Global cache for the current session
    data.stickers.forEach(s => {
        let sticker;
        try { sticker = JSON.parse(s.data); } catch(e) { sticker = { raster: s.data }; }
        window._stickerCache[s.id] = s.data;
        
        const card = document.createElement('div');
        card.className = 'sticker-card';
        card.innerHTML = `
            <div class="sticker-delete" onclick="deleteSticker(event, ${s.id})">×</div>
            <img src="${sticker.raster}" onclick="pasteSticker(window._stickerCache[${s.id}])">
        `;
        list.appendChild(card);
    });
}async function deleteSticker(e, id) {
    e.stopPropagation();
    if (!await wbui.confirm("Delete this sticker?", "Delete Sticker", wbIcons.trash)) return;
    const fd = new FormData();
    fd.append('action', 'delete_sticker');
    fd.append('id', id);
    await fetch('index.php', { method: 'POST', body: fd });
    refreshLibrary();
}

async function clearLibrary() {
    if (!await wbui.confirm("Permanently delete all stickers in your library? This cannot be undone.", "Clear Library", wbIcons.alert)) return;
    const fd = new FormData();
    fd.append('action', 'clear_stickers');
    await fetch('index.php', { method: 'POST', body: fd });
    refreshLibrary();
}

function pasteSticker(stickerDataRaw) {
    let sticker;
    try {
        sticker = JSON.parse(stickerDataRaw);
    } catch(e) {
        // Legacy fallback for plain dataUrl
        sticker = { raster: stickerDataRaw, vector: null };
    }

    const finalizePaste = () => {
        const btn = document.getElementById('paste-btn');
        if (btn) btn.style.display = clipboard ? 'block' : 'none';
        toggleLibrary();
        paste();
    };

    if (sticker.vector) {
        clipboard = sticker.vector;
        finalizePaste();
    } else {
        const img = new Image();
        img.onload = () => {
            const tempCanvas = document.createElement('canvas');
            tempCanvas.width = img.width;
            tempCanvas.height = img.height;
            tempCanvas.getContext('2d').drawImage(img, 0, 0);
            clipboard = tempCanvas;
            finalizePaste();
        };
        img.src = sticker.raster;
    }
}



function exitMoveMode() {
    const vp = getActiveViewport();
    vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height);
    isMoving = false;
    isDraggingObject = false;
    document.getElementById('move-controls').style.display = 'none';
    document.body.classList.remove('is-moving');
    setTouchMode(userSelectedTool);
    clipboard = null;
    updatePasteButton();
}