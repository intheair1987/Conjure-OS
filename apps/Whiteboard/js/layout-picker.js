/**
 * WHITEBOARD LAYOUT PICKER
 * Handles staging, layout configuration, and placement of imported assets.
 */

function showLayoutPicker() {
    document.getElementById('layout-count-label').innerText = wbStagingImages.length;
    document.getElementById('layout-picker-overlay').style.display = 'flex';
    renderLayoutPreview();
}

window.wbImportGap = 250;

window.updateImportSpacing = function(val) {
    window.wbImportGap = parseInt(val);
    const display = document.getElementById('import-spacing-val');
    if (display) display.innerText = val + 'px';
    renderLayoutPreview();
};

window.setImportLayout = function(mode) {
    wbImportLayoutMode = mode;
    document.getElementById('layout-v-btn').classList.toggle('active', mode === 'vertical');
    document.getElementById('layout-h-btn').classList.toggle('active', mode === 'horizontal');
    renderLayoutPreview();
};

function renderLayoutPreview() {
    const box = document.getElementById('layout-preview-box');
    box.innerHTML = '';
    box.style.flexDirection = wbImportLayoutMode === 'vertical' ? 'column' : 'row';
    
    // Sync the preview gap with the slider (scaled down for the small UI)
    box.style.gap = (window.wbImportGap / 10) + 'px';
    
    const count = Math.min(wbStagingImages.length, 5);
    const baseSize = 50; // The maximum dimension for a preview box

    for (let i = 0; i < count; i++) {
        const img = wbStagingImages[i];
        const item = document.createElement('div');
        item.className = 'layout-preview-item';
        
        // Calculate Aspect Ratio
        const ratio = img.w / img.h;
        
        if (ratio > 1) { 
            // Landscape: Width is the base, height is smaller
            item.style.width = baseSize + 'px';
            item.style.height = (baseSize / ratio) + 'px';
        } else {
            // Portrait: Height is the base, width is smaller
            item.style.height = baseSize + 'px';
            item.style.width = (baseSize * ratio) + 'px';
        }
        
        box.appendChild(item);
    }
    if (wbStagingImages.length > 5) {
        const plus = document.createElement('div');
        plus.innerText = '+';
        plus.style.fontSize = '18px';
        plus.style.fontWeight = '800';
        plus.style.color = 'var(--text-secondary)';
        box.appendChild(plus);
    }
}

window.cancelImportLayout = function() {
    document.getElementById('layout-picker-overlay').style.display = 'none';
    wbStagingImages = [];
};

window.commitImportLayout = async function() {
    const vp = getActiveViewport();
    const rect = vp.canvas.getBoundingClientRect();
    const gap = window.wbImportGap; 

    // 1. Target the World Origin (0,0) for Magic/Bulk imports
    // This ensures content is always "centered" on the coordinate system.
    const targetCenter = { x: 0, y: 0 };

    // 2. Calculate Total Dimensions
    let totalW = 0, totalH = 0;
    if (wbImportLayoutMode === 'vertical') {
        totalW = Math.max(...wbStagingImages.map(img => img.w));
        totalH = wbStagingImages.reduce((sum, img) => sum + img.h, 0) + (gap * (wbStagingImages.length - 1));
    } else {
        totalW = wbStagingImages.reduce((sum, img) => sum + img.w, 0) + (gap * (wbStagingImages.length - 1));
        totalH = Math.max(...wbStagingImages.map(img => img.h));
    }

    // 3. Native Scaling: Import documents at their true 1:1 physical resolution.
    // Industry Standard: Do not shrink the document to fit the screen; instead, adjust the camera to fit the document.
    const scale = 1.0;

    const finalTotalW = totalW * scale;
    const finalTotalH = totalH * scale;

    // 4. Place Images
    wbPushUndo();
    wbSelection.ids.clear();
    
    let currentX = targetCenter.x - finalTotalW / 2;
    let currentY = targetCenter.y - finalTotalH / 2;

    for (const img of wbStagingImages) {
        const imgW = img.w * scale;
        const imgH = img.h * scale;
        
        let posX = currentX;
        let posY = currentY;

        if (wbImportLayoutMode === 'vertical') {
            posX = targetCenter.x - imgW / 2;
        } else {
            posY = targetCenter.y - imgH / 2;
        }

        const newStroke = {
            id: wbCreateId(),
            zIndex: wbGetNextZIndex(),
            type: img.type || 'image',
            assetId: img.assetId,
            page: img.page,
            quality: img.quality,
            data: '', 
            x: posX, y: posY, w: imgW, h: imgH
        };

        allStrokes.push(newStroke);
        wbSelection.ids.add(newStroke.id);

        if (wbImportLayoutMode === 'vertical') currentY += imgH + (gap * scale);
        else currentX += imgW + (gap * scale);
    }

    // 5. Finalize UI
    document.getElementById('layout-picker-overlay').style.display = 'none';
    
    // Calculate bounding box for the whole group (Centered on World Origin 0,0)
    wbSelection.bounds = { 
        x: targetCenter.x - finalTotalW / 2, 
        y: targetCenter.y - finalTotalH / 2, 
        w: finalTotalW, 
        h: finalTotalH 
    };
    
    render();
    
    const menu = document.getElementById('selection-menu');
    menu.style.display = 'flex';
    
    wbUpdateSelectionUI(true);
    
    // Auto-Frame: Zoom and Pan to fit the new content perfectly
    // We use a slightly longer delay to ensure all DOM changes are settled
    setTimeout(() => {
        centerOnContent(true);
        wbUpdateHashUI();
        if (autoSaveEnabled) saveDrawing();
    }, 150);

    if (window.navigator.vibrate) navigator.vibrate(15);
    wbStagingImages = [];
};

async function placeStagedAssetOnCanvas(img) {
    const vp = getActiveViewport();
    const rect = vp.canvas.getBoundingClientRect();
    
    const worldCenter = getCanvasCoords({ 
        clientX: rect.left + rect.width/2, 
        clientY: rect.top + rect.height/2 
    }, vp);

    const MAX_WORLD_DIM = 4000;
    const initialScale = Math.min(1.0, MAX_WORLD_DIM / img.w, MAX_WORLD_DIM / img.h);
    const finalW = img.w * initialScale;
    const finalH = img.h * initialScale;

    const newStroke = {
        id: wbCreateId(),
        zIndex: wbGetNextZIndex(),
        type: img.type || 'image',
        assetId: img.assetId,
        page: img.page,
        quality: img.quality,
        data: '', 
        x: worldCenter.x - finalW / 2,
        y: worldCenter.y - finalH / 2,
        w: finalW,
        h: finalH
    };

    wbPushUndo();
    allStrokes.push(newStroke);
    wbSelection.ids.clear();
    wbSelection.ids.add(newStroke.id);
    wbSelection.bounds = { x: newStroke.x, y: newStroke.y, w: newStroke.w, h: newStroke.h };
    
    render();
    
    wbUpdateSelectionUI(true);
    
    setTimeout(() => {
        centerOnContent(true);
        if (autoSaveEnabled) saveDrawing();
    }, 50);
    
    if (window.navigator.vibrate) navigator.vibrate(10);
}