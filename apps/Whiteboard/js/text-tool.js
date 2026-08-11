/**
 * WHITEBOARD TEXT TOOL MODULE
 * Handles floating text editors, formatting toolbars, and drag-to-insert logic.
 */

let activeTextEditor = null;

window.wbRecomputeBlankWidth = function(stroke, textContent) {
    if (stroke.type !== 'blank') return;
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const fontStyle = (stroke.italic ? 'italic ' : '') + (stroke.bold ? 'bold ' : '');
    ctx.font = `${fontStyle}${stroke.fontSize || 24}px ${stroke.fontFamily || 'sans-serif'}`;
    
    const lines = (textContent || '').split('\n');
    let maxTextW = 0;
    lines.forEach(l => { maxTextW = Math.max(maxTextW, ctx.measureText(l).width); });
    
    const textWorldW = maxTextW + 16; // Add comfortable padding
    const oldW = stroke.w;
    const emptyW = stroke.minW || 150;
    
    // Shrink-to-fit logic: 
    // If empty, use default/min width. If has text, use text width (even if smaller than default).
    const newW = (textContent.trim() === '') ? emptyW : textWorldW;
    const delta = newW - oldW;
    
    // ALWAYS delete cached bounds/raster because content changed (could be multi-line)
    delete stroke._b;
    delete stroke._cache;

    if (Math.abs(delta) > 0.5) {
        stroke.w = newW;
        const myBaseline = stroke.y;
        const midShift = delta / 2; // Shift for centered content
        
        if (typeof allStrokes !== 'undefined') {
            allStrokes.forEach(s => {
                if (s.id === stroke.id) return;
                
                // 1. Handle Path Strokes (Annotations & Drawings)
                if (s.type === 'path') {
                    const sB = s._b || wbCalculateStrokeBounds(s);
                    // Check if stroke is on the same vertical level (roughly)
                    if (Math.abs(sB.y + sB.h/2 - myBaseline) < 60) {
                        // A. Overlapping Annotation: Move by half delta to stay centered with text
                        const isOverlapping = (sB.x < stroke.x + oldW && sB.x + sB.w > stroke.x);
                        if (isOverlapping) {
                            s.points.forEach(p => p.x += midShift);
                            delete s._b; delete s._cache;
                        }
                        // B. Subsequent Drawing: Move by full delta to maintain flow
                        else if (sB.x >= stroke.x + oldW - 5) {
                            s.points.forEach(p => p.x += delta);
                            delete s._b; delete s._cache;
                        }
                    }
                    return;
                }

                // 2. Handle Object Flow (Text & Blanks)
                if (s.type === 'text' || s.type === 'blank') {
                    const sBaseline = s.type === 'blank' ? s.y : s.y + (s.fontSize || 24);
                    if (Math.abs(sBaseline - myBaseline) < 12) {
                        if (s.x >= stroke.x + oldW - 5) {
                            s.x += delta;
                            delete s._b; delete s._cache;
                        }
                    }
                }
            });
        }
    }
    if (typeof render === 'function') render();
};

function updateTextFormat(prop, val) {
    if (!activeTextEditor) return;
    const { el } = activeTextEditor;
    
    if (prop === 'bold') {
        const isBold = el.style.fontWeight === 'bold';
        el.style.fontWeight = isBold ? 'normal' : 'bold';
        document.getElementById('wb-t-bold').classList.toggle('active', !isBold);
    } else if (prop === 'italic') {
        const isItalic = el.style.fontStyle === 'italic';
        el.style.fontStyle = isItalic ? 'normal' : 'italic';
        document.getElementById('wb-t-italic').classList.toggle('active', !isItalic);
    } else if (prop === 'underline') {
        const isUnder = el.style.textDecoration === 'underline';
        el.style.textDecoration = isUnder ? 'none' : 'underline';
        document.getElementById('wb-t-underline').classList.toggle('active', !isUnder);
    } else if (prop === 'size') {
        const vp = getActiveViewport();
        activeTextEditor.fontSize = val;
        textFontSize = val; // Update global for new text
        el.style.fontSize = (val * vp.transform.scale) + 'px';
        document.getElementById('text-size-val').innerText = val;
        updateEditorPosition();
        saveSettings({ text_font_size: val }); // Persist to server

        // Update Star State
        const isStarred = (window.starredTextSizes || []).some(s => Math.abs(s - val) < 0.1);
        document.getElementById('text-star-btn')?.classList.toggle('active', isStarred);
        // Update chip active states
        document.querySelectorAll('.text-preset-chip').forEach(chip => {
            chip.classList.toggle('active', Math.abs(parseFloat(chip.innerText) - val) < 0.1);
        });
    } else if (prop === 'align') {
        activeTextEditor.align = val;
        el.style.textAlign = val;
        ['left', 'center', 'right'].forEach(a => {
            document.getElementById('wb-t-' + a).classList.toggle('active', a === val);
        });
        updateEditorPosition();
    }
}

function updateToolbarMode(mode) {
    toolbarMode = mode;
    saveSettings({ toolbar_mode: mode });
    updateToolbarPosition();
}

function updateToolbarPosition() {
    const bar = document.getElementById('text-toolbar');
    if (!bar || bar.style.display !== 'flex' || !activeTextEditor) return;
    
    // Tier 3 handles positioning automatically via flexbox
    bar.style.left = '';
    bar.style.top = '';
    bar.style.transform = '';
}

function showTextToolbar(screenX, screenY, stroke) {
    const bar = document.getElementById('text-toolbar');
    bar.style.display = 'flex';

    // Sync UI state
    document.getElementById('wb-t-bold').classList.toggle('active', stroke?.bold || false);
    document.getElementById('wb-t-italic').classList.toggle('active', stroke?.italic || false);
    document.getElementById('wb-t-underline').classList.toggle('active', stroke?.underline || false);
    
    const align = stroke?.align || 'left';
    ['left', 'center', 'right'].forEach(a => {
        document.getElementById('wb-t-' + a).classList.toggle('active', a === align);
    });

    const size = stroke?.fontSize || textFontSize;
    document.getElementById('text-size-slider').value = size;
    document.getElementById('text-size-val').innerText = Math.round(size);

    // Position and clamp
    setTimeout(updateToolbarPosition, 0); // Delay slightly to allow editor to render
    
    if (typeof refreshTextPresets === 'function') refreshTextPresets();
}

function ensureTextEditorVisible() {
    if (!activeTextEditor || activeTextEditor.visibilityAdjusting) return;

    // Coalesce repeated calls from input, render hooks, and viewport updates.
    if (activeTextEditor.visibilityFramePending) return;
    activeTextEditor.visibilityFramePending = true;

    requestAnimationFrame(() => {
        if (!activeTextEditor) return;
        activeTextEditor.visibilityFramePending = false;
        if (activeTextEditor.visibilityAdjusting) return;

        const editor = activeTextEditor;
        const vp = getActiveViewport();
        const viewportRect = vp.canvas.getBoundingClientRect();
        const editorRect = editor.el.getBoundingClientRect();
        const margin = Math.max(24, Math.min(48, editor.fontSize * 0.6));
        const edgeTolerance = 2;

        const overflowLeft = Math.max(0, viewportRect.left + margin - editorRect.left);
        const overflowRight = Math.max(0, editorRect.right - (viewportRect.right - margin));
        const overflowTop = Math.max(0, viewportRect.top + margin - editorRect.top);
        const overflowBottom = Math.max(0, editorRect.bottom - (viewportRect.bottom - margin));
        const horizontalOverflow = overflowLeft > edgeTolerance || overflowRight > edgeTolerance;
        const verticalOverflow = overflowTop > edgeTolerance || overflowBottom > edgeTolerance;

        // During any pointer-driven text insertion, the camera must not move
        // vertically. The pointer owns the object's position; visibility
        // correction may only protect the horizontal edges.
        const allowVerticalCorrection = !isDraggingTextInsertion && !editor.horizontalVisibilityOnly;

        if (horizontalOverflow || (allowVerticalCorrection && verticalOverflow)) {
            let panX = overflowLeft - overflowRight;
            let panY = allowVerticalCorrection ? overflowTop - overflowBottom : 0;

            editor.visibilityAdjusting = true;
            vp.transform.x += panX;
            vp.transform.y += panY;
            requestRender();

            requestAnimationFrame(() => {
                if (activeTextEditor === editor) editor.visibilityAdjusting = false;
            });
            return;
        }

        const availableWidth = Math.max(120, viewportRect.width - (margin * 2));
        const availableHeight = Math.max(80, viewportRect.height - (margin * 2));
        const needsZoom = editorRect.width > availableWidth || (allowVerticalCorrection && editorRect.height > availableHeight);

        if (needsZoom && vp.transform.scale > 0.45) {
            const widthRatio = availableWidth / Math.max(editorRect.width, 1);
            const heightRatio = allowVerticalCorrection
                ? availableHeight / Math.max(editorRect.height, 1)
                : 1;
            const ratio = Math.min(widthRatio, heightRatio);
            const nextScale = Math.max(0.45, Math.min(vp.transform.scale, vp.transform.scale * ratio));

            if (nextScale < vp.transform.scale - 0.01) {
                const anchorX = (editorRect.left + editorRect.right) / 2 - viewportRect.left;
                const anchorY = (editorRect.top + editorRect.bottom) / 2 - viewportRect.top;
                const worldAnchor = getCanvasCoords({
                    clientX: (editorRect.left + editorRect.right) / 2,
                    clientY: (editorRect.top + editorRect.bottom) / 2
                }, vp);

                editor.visibilityAdjusting = true;
                vp.transform.scale = nextScale;
                vp.transform.x = anchorX - worldAnchor.x * nextScale;
                if (allowVerticalCorrection) {
                    vp.transform.y = anchorY - worldAnchor.y * nextScale;
                }
                requestRender();

                requestAnimationFrame(() => {
                    if (activeTextEditor === editor) editor.visibilityAdjusting = false;
                });
            }
        }
    });
}

function updateEditorPosition() {
    if (!activeTextEditor) return { screenX: 0, screenY: 0 };
    const vp = getActiveViewport();
    
    // If editing a blank, always center based on the current stroke width
    if (activeTextEditor.isBlank && activeTextEditor.originalStroke) {
        activeTextEditor.worldX = activeTextEditor.originalStroke.x + (activeTextEditor.originalStroke.w / 2);
    }

    const { container, el, worldX, worldY, fontSize, align } = activeTextEditor;
    const rect = vp.canvas.getBoundingClientRect();
    const screenX = (worldX * vp.transform.scale) + vp.transform.x + rect.left;
    const screenY = (worldY * vp.transform.scale) + vp.transform.y + rect.top;
    
    const padX = 8;
    const padY = 4;
    
    let leftPos = screenX;
    let transformX = '0%';

    // Match Canvas textAlign behavior using CSS transforms
    if (align === 'center') {
        leftPos = screenX;
        transformX = '-50%';
    } else if (align === 'right') {
        leftPos = screenX + padX;
        transformX = '-100%';
    } else { // left
        leftPos = screenX - padX;
        transformX = '0%';
    }

    container.style.left = leftPos + 'px';
    container.style.top = (screenY - padY) + 'px';
    container.style.transform = `translateX(${transformX})`;

    // DYNAMIC SCALING: Update the font size to match the current viewport scale
    el.style.fontSize = (fontSize * vp.transform.scale) + 'px';
    
    // Update toolbar position in real-time (zoom/pan)
    updateToolbarPosition();
    if (!isDraggingTextInsertion) {
        ensureTextEditorVisible();
    }
    
    return { screenX, screenY };
}

function spawnTextEditor(worldX, worldY, existingStroke = null, isGhost = false) {
    if (activeTextEditor) commitText();

    const isBlank = existingStroke && existingStroke.type === 'blank';

    if (existingStroke && !isBlank) {
        const idx = allStrokes.indexOf(existingStroke);
        if (idx !== -1) {
            window.wbDeletedStrokeIds.add(existingStroke.id);
            allStrokes.splice(idx, 1);
            render();
        }
    }

    let tempContent = "";
    if (isBlank) {
        if (typeof wbPushUndo === 'function') wbPushUndo();
        tempContent = existingStroke.content;
        existingStroke.content = ""; 
        if (typeof render === 'function') render();
    }

    // Create Container
    const container = document.createElement('div');
    container.id = 'wb-text-editor-container';

    const editor = document.createElement('div');
    editor.id = 'wb-text-editor';
    editor.contentEditable = true;
    editor.setAttribute('autocapitalize', 'none');
    editor.setAttribute('autocorrect', 'off');
    editor.spellcheck = false;
    editor.innerText = existingStroke ? (isBlank ? tempContent : existingStroke.content) : (isGhost ? "Text" : "");
    
    const vp = getActiveViewport();
    const fontSize = existingStroke ? existingStroke.fontSize : textFontSize;
    const color = existingStroke ? existingStroke.color : (toolConfigs.text?.color || brushColor);
    
    editor.style.fontSize = (fontSize * vp.transform.scale) + 'px';
    editor.style.color = color;
    editor.style.opacity = isGhost ? "0.5" : "1";
    if (existingStroke && existingStroke.bold) editor.style.fontWeight = 'bold';
    if (existingStroke && existingStroke.italic) editor.style.fontStyle = 'italic';
    if (existingStroke && existingStroke.underline) editor.style.textDecoration = 'underline';
    editor.style.textAlign = isBlank ? 'center' : (existingStroke ? (existingStroke.align || 'left') : 'left');
    
    const actualWorldY = isBlank ? existingStroke.y - (existingStroke.fontSize * 1.2) : worldY;
    const actualWorldX = isBlank ? existingStroke.x + (existingStroke.w / 2) : worldX;

    activeTextEditor = {
        container: container,
        el: editor,
        worldX: actualWorldX,
        worldY: actualWorldY,
        originalStroke: existingStroke,
        fontSize: fontSize,
        color: color,
        align: isBlank ? 'center' : (existingStroke ? (existingStroke.align || 'left') : 'left'),
        isBlank: isBlank,
        tempContent: tempContent,
        horizontalVisibilityOnly: false,
        visibilityFramePending: false,
        visibilityAdjusting: false
    };

    editor.oninput = () => {
        updateToolbarPosition();
        // Re-measure after each edit so the right edge and caret remain visible.
        requestAnimationFrame(() => {
            updateEditorPosition();
            ensureTextEditorVisible();
        });
        if (activeTextEditor.isBlank) {
            // Mobile Shortcut: Double comma ",," triggers jump to next blank
            if (editor.innerText.endsWith(',,')) {
                editor.innerText = editor.innerText.slice(0, -2);
                const current = activeTextEditor.originalStroke;
                window.wbRecomputeBlankWidth(current, editor.innerText);
                const neighbors = allStrokes
                    .filter(s => s.type === 'blank' && Math.abs(s.y - current.y) < 12)
                    .sort((a, b) => a.x - b.x);
                
                const idx = neighbors.findIndex(n => n.id === current.id);
                const next = neighbors[idx + 1];
                
                commitText();
                if (next) {
                    spawnTextEditor(next.x, next.y, next);
                }
                return;
            }
            window.wbRecomputeBlankWidth(activeTextEditor.originalStroke, editor.innerText);
        }
    };
    
    // Create Move Handle
    const handle = document.createElement('div');
    handle.className = 'wb-text-handle';
    handle.title = "Drag to move";
    
    let isDraggingHandle = false;
    let startX, startY, startWorldX, startWorldY;

    handle.onpointerdown = (e) => {
        if (e.pointerType === 'pen') return;
        e.stopPropagation();
        e.preventDefault();
        isDraggingHandle = true;
        isDraggingTextInsertion = true; // Trigger guide visibility
        
        startX = e.clientX;
        startY = e.clientY;
        startWorldX = activeTextEditor.worldX;
        startWorldY = activeTextEditor.worldY;
        
        handle.setPointerCapture(e.pointerId);
    };

    handle.onpointermove = (e) => {
        if (!isDraggingHandle) return;
        const vp = getActiveViewport();
        let newWorldX = startWorldX + (e.clientX - startX) / vp.transform.scale;
        let newWorldY = startWorldY + (e.clientY - startY) / vp.transform.scale;

        // --- MAGNETIC SNAPPING (Edge-to-Edge) ---
        const threshold = 12 / vp.transform.scale;
        let snapped = false;
        
        // For the editor handle, we use the bounds of the original stroke if editing,
        // or a default height for new text.
        const mH = activeTextEditor.originalStroke ? (activeTextEditor.originalStroke._b?.h || activeTextEditor.fontSize * 1.2) : (activeTextEditor.fontSize * 1.2);
        const mW = activeTextEditor.originalStroke ? (activeTextEditor.originalStroke._b?.w || 0) : 0;

        const draggedBaselineY = newWorldY + (activeTextEditor.fontSize || 24);

        for (const s of allStrokes) {
            if (activeTextEditor.originalStroke && s.id === activeTextEditor.originalStroke.id) continue;
            if (!['text', 'blank', 'image', 'pdf_page', 'docx_page'].includes(s.type)) continue;

            const b = s._b || wbCalculateStrokeBounds(s);
            
            // Snap X (Left-to-Left) - Right-to-Right only if we have a width
            if (Math.abs(newWorldX - b.x) < threshold) { newWorldX = b.x; snapped = true; }
            else if (mW > 0 && Math.abs((newWorldX + mW) - (b.x + b.w)) < threshold) { newWorldX = b.x + b.w - mW; snapped = true; }

            // Snap Y
            if (s.type === 'text' || s.type === 'blank') {
                let snapBaselineY = b.y;
                if (s.type === 'blank') snapBaselineY = s.y;
                else if (s.type === 'text') snapBaselineY = s.y + (s.fontSize || 24);

                if (Math.abs(draggedBaselineY - snapBaselineY) < threshold) { 
                    newWorldY = snapBaselineY - (activeTextEditor.fontSize || 24); 
                    snapped = true; 
                }
            } else {
                if (Math.abs(newWorldY - b.y) < threshold) { newWorldY = b.y; snapped = true; }
                else if (Math.abs((newWorldY + mH) - (b.y + b.h)) < threshold) { newWorldY = b.y + b.h - mH; snapped = true; }
            }
        }

        if (snapped && !handle._snapped) {
            if (window.navigator.vibrate) navigator.vibrate(5);
            handle._snapped = true;
        } else if (!snapped) {
            handle._snapped = false;
        }

        activeTextEditor.worldX = newWorldX;
        activeTextEditor.worldY = newWorldY;
        
        updateEditorPosition();
        updateTextInsertionGuides();
    };

    handle.onpointerup = (e) => {
        isDraggingHandle = false;
        isDraggingTextInsertion = false;
        const vp = getActiveViewport();
        vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height);
        handle.releasePointerCapture(e.pointerId);
    };

    container.appendChild(editor);
    container.appendChild(handle);
    document.body.appendChild(container);
    updateEditorPosition();

    if (!isGhost) finalizeTextEditor(activeTextEditor);

    // Commit on blur or Ctrl+Enter
    editor.onblur = (e) => {
        // Delay commit to check where focus went
        setTimeout(() => { 
            if (!activeTextEditor || activeTextEditor.el !== editor) return;
            
            // If focus moved to any UI component, don't commit
            const isUiFocus = document.activeElement.closest('#toolbar') || 
                             document.activeElement.closest('#text-toolbar') ||
                             document.activeElement.closest('#size-popover') ||
                             document.activeElement.closest('#options-menu') ||
                             document.activeElement.closest('#save-menu');

            if (isUiFocus) {
                editor.focus(); // Return focus to editor
                return;
            }
            
            commitText(); 
        }, 150);
    };

    editor.onkeydown = (e) => {
        if (e.key === 'Tab') {
            e.preventDefault();
            if (activeTextEditor.isBlank) {
                const current = activeTextEditor.originalStroke;
                // Find all blanks on the same baseline (within 12px tolerance)
                const neighbors = allStrokes
                    .filter(s => s.type === 'blank' && Math.abs(s.y - current.y) < 12)
                    .sort((a, b) => a.x - b.x);
                
                const idx = neighbors.findIndex(n => n.id === current.id);
                const next = e.shiftKey ? neighbors[idx - 1] : neighbors[idx + 1];
                
                if (next) {
                    commitText();
                    spawnTextEditor(next.x, next.y, next);
                    return;
                }
            }
            commitText();
        }
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            commitText();
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            commitText();
        }
    };
}

function updateTextInsertionGuides() {
    if (typeof requestRender === 'function') {
        requestRender();
    }
}

function finalizeTextEditor(state) {
    const { el, originalStroke } = state;
    const pos = updateEditorPosition();
    showTextToolbar(pos.screenX, pos.screenY, originalStroke);
    
    // WebKit/iPad Fix: Focus must be synchronous within the user-event loop 
    // to trigger the virtual keyboard. setTimeout breaks this chain.
    el.focus();
    const range = document.createRange();
    const sel = window.getSelection();
    range.selectNodeContents(el);
    range.collapse(false);
    sel.removeAllRanges();
    sel.addRange(range);
}

function commitText() {
    if (!activeTextEditor) return;
    const { el, worldX, worldY, originalStroke, fontSize, color, isBlank } = activeTextEditor;
    const content = el.innerText.trim();

    if (isBlank) {
        originalStroke.content = content;
        delete originalStroke._b;
        delete originalStroke._cache;
        if (typeof render === 'function') render();
        if (typeof saveDrawing === 'function') saveDrawing();
    } else if (content) {
        const newStroke = {
            id: wbCreateId(),
            zIndex: wbGetNextZIndex(),
            type: 'text',
            content: content,
            x: worldX,
            y: worldY,
            fontSize: fontSize,
            color: color,
            bold: el.style.fontWeight === 'bold',
            italic: el.style.fontStyle === 'italic',
            underline: el.style.textDecoration === 'underline',
            align: activeTextEditor.align || 'left',
            composite: 'source-over'
        };

        // originalStroke was already spliced out in spawnTextEditor
        allStrokes.push(newStroke); 
        allStrokes.sort((a, b) => a.zIndex - b.zIndex);
        
        render();
        saveDrawing();
    } else if (originalStroke) {
        // If text was cleared, it remains removed. Just save the state.
        render();
        saveDrawing();
    }

    activeTextEditor.container.remove();
    document.getElementById('text-toolbar').style.display = 'none';
    activeTextEditor = null;
}

function cancelText() {
    if (!activeTextEditor) return;
    
    // Restore the original stroke if we cancelled the edit
    if (activeTextEditor.originalStroke) {
        if (activeTextEditor.isBlank) {
            activeTextEditor.originalStroke.content = activeTextEditor.tempContent;
            if (typeof window.wbRecomputeBlankWidth === 'function') {
                window.wbRecomputeBlankWidth(activeTextEditor.originalStroke, activeTextEditor.tempContent);
            }
        } else {
            allStrokes.push(activeTextEditor.originalStroke);
        }
        if (typeof render === 'function') render();
    }
    
    activeTextEditor.container.remove();
    document.getElementById('text-toolbar').style.display = 'none';
    activeTextEditor = null;
}

window.starredTextSizes = [];

async function toggleStarText() {
    const btn = document.getElementById('text-star-btn');
    const isStarred = btn.classList.contains('active');
    const currentSize = parseFloat(textFontSize);
    
    if (isStarred) {
        const fd = new FormData();
        fd.append('action', 'get_presets');
        fd.append('type', 'text');
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();
        const existing = data.presets.find(p => Math.abs(p.size - currentSize) < 0.1);
        if (existing) {
            const dfd = new FormData();
            dfd.append('action', 'delete_preset');
            dfd.append('id', existing.id);
            await fetch('index.php', { method: 'POST', body: dfd });
        }
    } else {
        const fd = new FormData();
        fd.append('action', 'save_preset');
        fd.append('size', currentSize);
        fd.append('type', 'text');
        await fetch('index.php', { method: 'POST', body: fd });
    }
    refreshTextPresets();
}

async function refreshTextPresets() {
    const container = document.getElementById('text-presets-container');
    const sep = document.getElementById('text-preset-sep');
    if (!container) return;

    const fd = new FormData();
    fd.append('action', 'get_presets');
    fd.append('type', 'text');
    const res = await fetch('index.php', { method: 'POST', body: fd });
    const data = await res.json();
    
    window.starredTextSizes = data.presets.map(p => p.size);
    
    if (data.presets.length === 0) {
        container.style.display = 'none';
        sep.classList.remove('active');
        sep.style.display = 'none';
    } else {
        container.style.display = 'flex';
        sep.classList.add('active');
        sep.style.display = ''; // Let CSS handle visibility based on orientation
        container.innerHTML = '';
        data.presets.forEach(p => {
            const isActive = Math.abs(p.size - textFontSize) < 0.1;
            const chip = document.createElement('div');
            chip.className = 'text-preset-chip' + (isActive ? ' active' : '');
            chip.innerText = Math.round(p.size);
            chip.onpointerdown = (e) => e.preventDefault();
            chip.onclick = () => {
                document.getElementById('text-size-slider').value = p.size;
                updateTextFormat('size', p.size);
            };
            container.appendChild(chip);
        });
    }
    
    const isCurrentStarred = window.starredTextSizes.some(s => Math.abs(s - textFontSize) < 0.1);
    document.getElementById('text-star-btn')?.classList.toggle('active', isCurrentStarred);
}

let wbPosBuilder = [];
let wbPosDragState = null;

const WB_POS_ELEMENTS = [
    { value: '', label: 'Empty blank' },
    { value: 'S', label: 'S' },
    { value: 'V', label: 'V' },
    { value: 'n.', label: 'n.' },
    { value: 'adj.', label: 'adj.' },
    { value: 'V-ing', label: 'V-ing' },
    { value: 'V-pp', label: 'V-pp' },
    { value: 'V-ed', label: 'V-ed' },
    { value: 'V-r', label: 'V-r' }
];

function wbPosHiddenElements() {
    const hidden = window.wb?.settings?.pos_hidden_elements;
    return Array.isArray(hidden) ? hidden : [];
}

function wbPosIsElementVisible(value) {
    return !wbPosHiddenElements().includes(value);
}

async function wbPosToggleElement(value) {
    const hidden = new Set(wbPosHiddenElements());
    if (hidden.has(value)) hidden.delete(value);
    else hidden.add(value);

    const next = Array.from(hidden);
    await saveSettings({ pos_hidden_elements: next });
    if (window.wb?.settings) window.wb.settings.pos_hidden_elements = next;
    if (window.wb?.ui) window.wb.ui.render();
    wbOpenPosPresetManager();
}

function wbPosGetGeometry(components, center, fontSize = (typeof textFontSize !== 'undefined' ? textFontSize : 24)) {
    const gap = Math.max(18, fontSize * 0.75);
    const itemWidth = Math.max(110, fontSize * 4.5);
    const totalWidth = (components.length * itemWidth) + ((components.length - 1) * gap);
    let x = center.x - (totalWidth / 2);

    return components.map(component => {
        const item = {
            component: component,
            x: x,
            y: center.y,
            w: itemWidth,
            fontSize: fontSize,
            labelRatio: window.grammarLabelRatio || 0.4,
            color: (toolConfigs.text?.color || brushColor || '#000000')
        };
        x += itemWidth + gap;
        return item;
    });
}

function wbPosPost(action, fields = {}) {
    const fd = new FormData();
    fd.append('action', action);
    Object.keys(fields).forEach(key => fd.append(key, fields[key]));
    return fetch('index.php', { method: 'POST', body: fd }).then(res => res.json());
}

function wbPosComponentLabel(component) {
    return component.kind === 'blank' ? '＿＿＿' : component.value;
}

function wbRenderPosBuilder() {
    const options = document.getElementById('wb-as-options');
    if (!options) return;

    const sequence = wbPosBuilder.length
        ? wbPosBuilder.map(wbPosComponentLabel).join('  →  ')
        : 'No components selected yet';

    options.innerHTML = `
        <div style="padding:10px 4px 14px; color:var(--text-secondary); font-size:11px; line-height:1.5;">
            Add components in order. Each component becomes an aligned grammar blank.
        </div>
        <div style="padding:12px; margin-bottom:10px; background:var(--bg-color); border-radius:14px; font-size:14px; font-weight:800; min-height:20px; overflow-x:auto; white-space:nowrap;">
            ${sequence}
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px;">
            ${['S', 'V', 'n.', 'adj.', 'V-ing', 'V-pp', 'V-ed', 'V-r'].map(value => `
                <button class="wb-as-btn" style="width:auto; margin:0; padding:9px 12px; font-size:12px;" onclick="wbPosBuilder.push({kind:'label',value:'${value}'}); wbRenderPosBuilder();">${value}</button>
            `).join('')}
            <button class="wb-as-btn" style="width:auto; margin:0; padding:9px 12px; font-size:12px;" onclick="wbPosBuilder.push({kind:'blank',value:''}); wbRenderPosBuilder();">Empty</button>
        </div>
        <div style="display:flex; gap:8px;">
            <button class="wb-as-btn" style="margin:0; text-align:center; justify-content:center;" onclick="wbPosBuilder=[]; wbRenderPosBuilder();">Clear</button>
            <button class="wb-as-btn" style="margin:0; background:var(--primary-accent); color:white; text-align:center; justify-content:center;" onclick="wbSavePosBuilder()">Save Preset</button>
        </div>
    `;
}

window.wbOpenPosPresetBuilder = function() {
    wbPosBuilder = [];
    const sheet = document.getElementById('wb-action-sheet');
    document.getElementById('wb-as-title').innerText = 'Create POS Preset';
    sheet.style.display = 'flex';
    wbRenderPosBuilder();
    requestAnimationFrame(() => {
        sheet.classList.add('active');
        sheet.querySelector('.wb-action-sheet').classList.add('active');
    });
};

window.wbSavePosBuilder = async function() {
    if (!wbPosBuilder.length) {
        await wbui.alert('Add at least one POS component first.', 'POS Preset', '✏️');
        return;
    }

    const name = await wbui.input('Give this composition a name', 'Save POS Preset', 'Subject + Verb', '🧩');
    if (!name) return;

    const result = await wbPosPost('save_pos_preset', {
        name: name,
        data: JSON.stringify(wbPosBuilder)
    });

    if (result.status !== 'success') {
        await wbui.alert(result.message || 'Unable to save this preset.', 'POS Preset', '⚠️');
        return;
    }

    wbCloseActionSheet();
    refreshPosPresets();
    await wbui.alert('The POS composition is ready to use from the grammar toolbar.', 'Preset Saved', '✅');
};

window.wbPlacePosPreset = function(components, centerOverride = null) {
    if (!Array.isArray(components) || !components.length) return;

    const vp = getActiveViewport();
    const rect = vp.canvas.getBoundingClientRect();
    const center = centerOverride || getCanvasCoords({
        clientX: rect.left + rect.width / 2,
        clientY: rect.top + rect.height / 2
    }, vp);

    const geometry = wbPosGetGeometry(components, center);

    wbPushUndo();
    geometry.forEach(item => {
        const blank = {
            id: wbCreateId(),
            zIndex: wbGetNextZIndex(),
            type: 'blank',
            x: item.x,
            y: item.y,
            w: item.w,
            minW: item.w,
            label: item.component.kind === 'label' ? item.component.value : null,
            content: '',
            fontSize: item.fontSize,
            labelRatio: item.labelRatio,
            color: item.color
        };
        allStrokes.push(blank);
    });

    allStrokes.sort((a, b) => a.zIndex - b.zIndex);
    window.wbPosDragGhost = null;
    render();
    if (autoSaveEnabled) saveDrawing();
    if (navigator.vibrate) navigator.vibrate(15);
};

function wbPosBeginDrag(e, components, chip) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;

    wbPosDragState = {
        pointerId: e.pointerId,
        components: components,
        chip: chip,
        startX: e.clientX,
        startY: e.clientY,
        viewportIndex: -1,
        dragging: false
    };

    chip.setPointerCapture(e.pointerId);
}

function wbPosMoveDrag(e) {
    if (!wbPosDragState || e.pointerId !== wbPosDragState.pointerId) return;

    const state = wbPosDragState;
    const distance = Math.hypot(e.clientX - state.startX, e.clientY - state.startY);

    if (!state.dragging && distance < 12) return;

    if (!state.dragging) {
        state.dragging = true;
        state.viewportIndex = window.wbGetViewportIndexAt(e.clientX, e.clientY);
        if (state.viewportIndex === -1) return;
        if (navigator.vibrate) navigator.vibrate(10);
    }

    const vp = viewports[state.viewportIndex];
    if (!vp) return;

    const center = getCanvasCoords(e, vp);
    window.wbPosDragGhost = wbPosGetGeometry(state.components, center);
    activeViewportIndex = state.viewportIndex;
    requestRender();
}

function wbPosEndDrag(e) {
    if (!wbPosDragState || e.pointerId !== wbPosDragState.pointerId) return;

    const state = wbPosDragState;
    const shouldPlace = state.dragging && state.viewportIndex !== -1;
    const vp = shouldPlace ? viewports[state.viewportIndex] : null;
    const center = vp ? getCanvasCoords(e, vp) : null;

    try {
        state.chip.releasePointerCapture(e.pointerId);
    } catch (error) {}

    window.wbPosDragGhost = null;
    wbPosDragState = null;

    if (shouldPlace && center) {
        wbPlacePosPreset(state.components, center);
    } else {
        requestRender();
    }
}

window.refreshPosPresets = async function() {
    const container = document.getElementById('wb-pos-presets-container');
    if (!container) return;

    try {
        const result = await wbPosPost('get_pos_presets');
        const presets = result.presets || [];
        container.innerHTML = '';

        presets.forEach(preset => {
            const button = document.createElement('button');
            button.className = 'wb-pos-preset-chip';
            button.title = `Drag or tap to place ${preset.name}`;
            button.innerText = preset.name;
            button.style.touchAction = 'none';

            let moved = false;
            button.onpointerdown = (event) => {
                moved = false;
                wbPosBeginDrag(event, preset.components, button);
            };
            button.onpointermove = (event) => {
                const before = wbPosDragState?.dragging;
                wbPosMoveDrag(event);
                moved = moved || Boolean(before) || Boolean(wbPosDragState?.dragging);
            };
            button.onpointerup = (event) => {
                const wasDragging = moved || Boolean(wbPosDragState?.dragging);
                wbPosEndDrag(event);
                if (!wasDragging) wbPlacePosPreset(preset.components);
            };
            button.onpointercancel = (event) => {
                window.wbPosDragGhost = null;
                wbPosDragState = null;
                requestRender();
            };

            container.appendChild(button);
        });

        if (!presets.length) {
            container.innerHTML = '<span class="wb-pos-empty">No POS presets yet</span>';
        }
    } catch (error) {
        container.innerHTML = '<span class="wb-pos-empty">POS presets unavailable</span>';
    }
};

window.wbOpenPosPresetManager = async function() {
    const sheet = document.getElementById('wb-action-sheet');
    document.getElementById('wb-as-title').innerText = 'POS Manager';
    document.getElementById('wb-as-options').innerHTML = '<div style="padding:20px; text-align:center; color:var(--text-secondary);">Loading POS manager...</div>';
    sheet.style.display = 'flex';
    requestAnimationFrame(() => {
        sheet.classList.add('active');
        sheet.querySelector('.wb-action-sheet').classList.add('active');
    });

    try {
        const result = await wbPosPost('get_pos_presets', { include_hidden: '1' });
        const presets = result.presets || [];
        const options = document.getElementById('wb-as-options');

        const elementManager = `
            <div style="margin:16px 0 8px; font-size:10px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">
                Built-in POS elements
            </div>
            <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:14px;">
                ${WB_POS_ELEMENTS.map(element => {
                    const visible = wbPosIsElementVisible(element.value);
                    const display = element.value || '＿＿＿';
                    return `
                        <div style="display:flex; align-items:center; gap:8px; padding:9px 10px; background:var(--bg-color); border-radius:10px;">
                            <div style="flex:1; font-size:12px; font-weight:800;">${display}</div>
                            <span style="font-size:10px; color:var(--text-secondary);">${element.label}</span>
                            <button class="tool-btn" onclick="wbPosToggleElement('${element.value}')">${visible ? 'Hide' : 'Show'}</button>
                        </div>
                    `;
                }).join('')}
            </div>
        `;

        options.innerHTML = `
            ${elementManager}
            <button class="wb-as-btn" onclick="wbOpenPosPresetBuilder()">＋ Create New Preset</button>
            ${presets.length ? presets.map(preset => `
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                    <div style="flex:1; min-width:0; padding:12px; background:var(--bg-color); border-radius:12px;">
                        <div style="font-weight:800; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${preset.name}</div>
                        <div style="font-size:10px; color:var(--text-secondary); margin-top:4px;">
                            ${preset.components.map(wbPosComponentLabel).join(' · ')}
                            ${Number(preset.is_hidden) ? ' · Hidden' : ''}
                        </div>
                    </div>
                    <button class="tool-btn" title="Rename" onclick="wbRenamePosPreset(${preset.id}, '${String(preset.name).replace(/'/g, "\\'")}')">Rename</button>
                    <button class="tool-btn" title="${Number(preset.is_hidden) ? 'Show' : 'Hide'}" onclick="wbTogglePosPreset(${preset.id})">${Number(preset.is_hidden) ? 'Show' : 'Hide'}</button>
                    <button class="tool-btn" title="Delete" onclick="wbDeletePosPreset(${preset.id})">×</button>
                </div>
            `).join('') : '<div style="padding:20px; text-align:center; color:var(--text-secondary);">No POS presets saved yet.</div>'}
        `;
    } catch (error) {
        document.getElementById('wb-as-options').innerHTML = '<div style="padding:20px; color:var(--text-secondary);">Unable to load POS presets.</div>';
    }
};

window.wbRenamePosPreset = async function(id, currentName) {
    const name = await wbui.input('Enter a new name for this POS preset', 'Rename POS Preset', currentName, '✏️');
    if (!name || name === currentName) return;

    const result = await wbPosPost('rename_pos_preset', {
        id: id,
        name: name
    });

    if (result.status !== 'success') {
        await wbui.alert(result.message || 'Unable to rename this POS preset.', 'POS Manager', '⚠️');
        return;
    }

    await refreshPosPresets();
    wbOpenPosPresetManager();
};

window.wbTogglePosPreset = async function(id) {
    await wbPosPost('toggle_pos_preset', { id: id });
    refreshPosPresets();
    wbOpenPosPresetManager();
};

window.wbDeletePosPreset = async function(id) {
    if (!await wbui.confirm('Delete this POS preset permanently?', 'Delete POS Preset', '🗑️')) return;
    await wbPosPost('delete_pos_preset', { id: id });
    refreshPosPresets();
    wbOpenPosPresetManager();
};

function initTextToolDrag() {
    const btn = document.getElementById('tm-text-btn');
    if (!btn) return;

    let dragStart = null;

    btn.addEventListener('pointerdown', (e) => {
        if (e.pointerType === 'pen') return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        dragStart = { x: e.clientX, y: e.clientY };
        btn.setPointerCapture(e.pointerId);
    });

    btn.addEventListener('pointermove', (e) => {
        if (!dragStart) return;
        const dist = Math.hypot(e.clientX - dragStart.x, e.clientY - dragStart.y);
        
        if (!isDraggingTextInsertion && dist > 15) {
            isDraggingTextInsertion = true;
            if (activeTextEditor) commitText();
            
            const isTouch = e.pointerType === 'touch' || e.pointerType === 'pen';
            const offsetY = isTouch ? 100 : 0;
            
            // Update viewport based on ghost position before spawning
            const targetVpIdx = window.wbGetViewportIndexAt(e.clientX, e.clientY - offsetY);
            if (targetVpIdx !== -1 && targetVpIdx !== activeViewportIndex) {
                activeViewportIndex = targetVpIdx;
            }

            const vp = getActiveViewport();
            const coords = getCanvasCoords(e, vp);
            const worldOffsetY = offsetY / vp.transform.scale;
            
            spawnTextEditor(coords.x, coords.y - worldOffsetY, null, true);
            if (activeTextEditor) activeTextEditor.horizontalVisibilityOnly = true;
            if (window.navigator.vibrate) navigator.vibrate(10);
        }

        if (isDraggingTextInsertion && activeTextEditor) {
            const isTouch = e.pointerType === 'touch' || e.pointerType === 'pen';
            const offsetY = isTouch ? 100 : 0;

            // Multi-Viewport Fix: Update active viewport based on ghost position
            const targetVpIdx = window.wbGetViewportIndexAt(e.clientX, e.clientY - offsetY);
            if (targetVpIdx !== -1 && targetVpIdx !== activeViewportIndex) {
                activeViewportIndex = targetVpIdx;
            }

            const vp = getActiveViewport();
            const coords = getCanvasCoords(e, vp);
            const worldOffsetY = offsetY / vp.transform.scale;

            let targetX = coords.x;
            let targetY = coords.y - worldOffsetY;

            // --- MAGNETIC SNAPPING (Edge-to-Edge) ---
            const threshold = 12 / vp.transform.scale;
            let snapped = false;
            const mH = activeTextEditor.fontSize * 1.2;

            const draggedBaselineY = targetY + (activeTextEditor.fontSize || 24);

            for (const s of allStrokes) {
                if (!['text', 'blank', 'image', 'pdf_page', 'docx_page'].includes(s.type)) continue;
                const b = s._b || wbCalculateStrokeBounds(s);
                
                // Snap X
                if (Math.abs(targetX - b.x) < threshold) { targetX = b.x; snapped = true; }

                // Snap Y
                if (s.type === 'text' || s.type === 'blank') {
                    let snapBaselineY = b.y;
                    if (s.type === 'blank') snapBaselineY = s.y;
                    else if (s.type === 'text') snapBaselineY = s.y + (s.fontSize || 24);

                    if (Math.abs(draggedBaselineY - snapBaselineY) < threshold) { 
                        targetY = snapBaselineY - (activeTextEditor.fontSize || 24); 
                        snapped = true; 
                    }
                } else {
                    if (Math.abs(targetY - b.y) < threshold) { targetY = b.y; snapped = true; }
                    else if (Math.abs((targetY + mH) - (b.y + b.h)) < threshold) { targetY = b.y + b.h - mH; snapped = true; }
                }
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
    });

    btn.addEventListener('pointerup', (e) => {
        if (!dragStart) return;
        btn.releasePointerCapture(e.pointerId);
        
        if (!isDraggingTextInsertion) {
            setTouchMode('text');
        } else {
            const isTouch = e.pointerType === 'touch' || e.pointerType === 'pen';
            const offsetY = isTouch ? 100 : 0;
            const targetVpIdx = window.wbGetViewportIndexAt(e.clientX, e.clientY - offsetY);
            if (targetVpIdx !== -1) activeViewportIndex = targetVpIdx;

            isDraggingTextInsertion = false;
            const vp = getActiveViewport();
            viewports.forEach(v => v.octx.clearRect(0, 0, v.overlay.width, v.overlay.height));
            if (activeTextEditor) {
                activeTextEditor.horizontalVisibilityOnly = false;
                activeTextEditor.el.innerText = "";
                activeTextEditor.el.style.opacity = "1";
                finalizeTextEditor(activeTextEditor);
            }
        }
        dragStart = null;
    });
}

// --- PLUGIN HOOKS ---
window.wb.on('onRenderViewport', (vp, index, activeIndex) => {
    if (typeof activeTextEditor !== 'undefined' && activeTextEditor && index === activeIndex) {
        updateEditorPosition();
    }
});