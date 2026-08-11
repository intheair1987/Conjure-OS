/**
 * WHITEBOARD EXPORT ENGINE
 * Handles high-resolution canvas rendering and image generation.
 */

async function exportAsImage(withBackground = false) {
    if (allStrokes.length === 0) {
        alert("Canvas is empty.");
        return;
    }

    const overlay = document.getElementById('export-progress-overlay');
    const msg = document.getElementById('export-progress-msg');
    const bar = document.getElementById('export-progress-bar');
    const pct = document.getElementById('export-progress-pct');
    
    const updateProgress = (p, text) => {
        msg.innerText = text;
        bar.style.width = p + '%';
        pct.innerText = Math.round(p) + '%';
    };

    overlay.style.display = 'flex';
    updateProgress(5, "Calculating bounds...");

    const qualityScale = parseFloat(document.getElementById('export-q-slider')?.value || 2.0);
    
    // 1. Calculate Content Bounds
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    allStrokes.forEach(s => {
        const b = s._b || wbCalculateStrokeBounds(s);
        minX = Math.min(minX, b.x); minY = Math.min(minY, b.y);
        maxX = Math.max(maxX, b.x + b.w); maxY = Math.max(maxY, b.y + b.h);
    });

    const pad = 20;
    const worldW = (maxX - minX) + (pad * 2);
    const worldH = (maxY - minY) + (pad * 2);

    // 2. Load Assets
    const assetsNeeded = allStrokes.filter(s => s.type === 'image' && s.assetId);
    for (let i = 0; i < assetsNeeded.length; i++) {
        const s = assetsNeeded[i];
        const progress = 5 + ((i / assetsNeeded.length) * 40);
        updateProgress(progress, `Loading asset ${i+1}/${assetsNeeded.length}...`);
        
        const img = await fetchAsset(s.assetId);
        if (img && img.decode) {
            try { await img.decode(); } catch(e) { console.warn("Decode failed", e); }
        }
    }

    updateProgress(50, "Safety check & memory allocation...");
    await new Promise(r => setTimeout(r, 100));

    // --- SAFETY GOVERNOR ---
    const MAX_CANVAS_DIM = 16384; // Hardware limit for most browsers
    const MAX_CANVAS_AREA = 12000 * 12000; // ~144MP (Memory limit)
    
    let finalScale = qualityScale;
    let targetW = worldW * finalScale;
    let targetH = worldH * finalScale;

    // Clamp by Dimension
    if (targetW > MAX_CANVAS_DIM || targetH > MAX_CANVAS_DIM) {
        const ratio = Math.min(MAX_CANVAS_DIM / targetW, MAX_CANVAS_DIM / targetH);
        finalScale *= ratio;
        targetW = worldW * finalScale;
        targetH = worldH * finalScale;
    }

    // Clamp by Area (Total Pixels)
    if (targetW * targetH > MAX_CANVAS_AREA) {
        const ratio = Math.sqrt(MAX_CANVAS_AREA / (targetW * targetH));
        finalScale *= ratio;
        targetW = worldW * finalScale;
        targetH = worldH * finalScale;
    }

    if (finalScale < qualityScale) {
        updateProgress(52, `Downscaling to ${finalScale.toFixed(1)}x for stability...`);
        await new Promise(r => setTimeout(r, 800));
    }

    // 3. Create High-Res Offscreen Buffer
    let tempCanvas, tctx;
    try {
        tempCanvas = document.createElement('canvas');
        tempCanvas.width = Math.floor(targetW);
        tempCanvas.height = Math.floor(targetH);
        tctx = tempCanvas.getContext('2d', { alpha: !withBackground });
        if (!tctx) throw new Error("Context allocation failed");
    } catch (e) {
        overlay.style.display = 'none';
        alert("Memory Error: The drawing area is too large to export at this quality. Try a lower quality setting.");
        return;
    }

    if (withBackground) {
        tctx.fillStyle = '#FFFFFF';
        tctx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
    }

    // CRITICAL FIX: Use finalScale (clamped) instead of qualityScale (requested)
    // This ensures the drawing fits exactly within the allocated canvas dimensions.
    tctx.setTransform(finalScale, 0, 0, finalScale, 0, 0);
    tctx.translate(-minX + pad, -minY + pad);

    // 4. Render Strokes
    for (let i = 0; i < allStrokes.length; i++) {
        if (i % 100 === 0) {
            const progress = 50 + ((i / allStrokes.length) * 45);
            updateProgress(progress, `Rendering ${i+1}/${allStrokes.length}...`);
            await new Promise(r => setTimeout(r, 0)); 
        }
        drawStroke(tctx, allStrokes[i], finalScale, true); // forceVector = true for pristine export
    }

    updateProgress(95, "Finalizing image file...");
    await new Promise(r => setTimeout(r, 100));

    // 5. Download
    try {
        const finalData = tempCanvas.toDataURL('image/png');
        const link = document.createElement('a');
        const date = new Date().toISOString().slice(0, 10);
        const suffix = withBackground ? 'flat' : 'trans';
        const qLabel = qualityScale.toFixed(1) + 'x';
        link.download = `whiteboard-${date}-${suffix}-${qLabel}.png`;
        link.href = finalData;
        link.click();
        updateProgress(100, "Done!");
    } catch (e) {
        alert("Export failed: Image too large for browser memory. Try lowering quality.");
    }

    setTimeout(() => { overlay.style.display = 'none'; }, 800);
}