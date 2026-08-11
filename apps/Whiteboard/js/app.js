window.wbCopyAiInstructions = function(url, key) {
    const inst = "# Whiteboard Media API Integration\n\n" +
                 "**Endpoint:** `" + url + "`\n" +
                 "**API Key:** `" + key + "`\n\n" +
                 "## Authentication\n" +
                 "- **Method:** `POST`\n" +
                 "- **Header:** `X-API-KEY: " + key + "`\n\n" +
                 "## Supported Formats\n" +
                 "- **Images:** PNG, JPG, JPEG, WEBP, GIF, SVG\n" +
                 "- **Documents:** PDF, DOCX (Automatically converted to high-res canvas assets)\n\n" +
                 "## Payload Options\n" +
                 "1. **image**: Binary file upload (Images or Documents)\n" +
                 "2. **image_base64**: Full DataURL string (Images only)\n" +
                 "3. **folder**: (Optional) Target folder path. Supports subfolders (e.g., `Math/Geometry`).\n" +
                 "4. **filename**: (Optional) Custom filename (e.g., `diagram_01.png`).\n\n" +
                 "## Organization Rules\n" +
                 "- Use the `folder` parameter to group related assets.\n" +
                 "- Subfolders are created automatically using forward slashes.\n\n" +
                 "## cURL Example (Subfolder Upload)\n" +
                 "```bash\n" +
                 "curl -X POST " + url + " \\\n" +
                 "     -H \"X-API-KEY: " + key + "\" \\\n" +
                 "     -F \"image=@chart.jpg\" \\\n" +
                 "     -F \"folder=Reports/2026/March\" \\\n" +
                 "     -F \"filename=revenue_chart.jpg\"\n" +
                 "```";
    wbCopyText(inst);
};

window.wbCopyText = async function(text) {
    let success = false;

    // 1. Try Modern Clipboard API (Requires HTTPS/Localhost)
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            success = true;
        } catch (err) {
            console.warn("Clipboard API failed, falling back...", err);
        }
    }

    // 2. Hardened Textarea Fallback
    if (!success) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '0';
        ta.setAttribute('readonly', ''); // Prevent keyboard from popping up
        document.body.appendChild(ta);
        
        const selected = document.getSelection().rangeCount > 0 ? document.getSelection().getRangeAt(0) : false;
        
        ta.focus(); // Crucial for mobile browsers
        ta.select();
        ta.setSelectionRange(0, 999999); // Force selection on iOS
        
        try {
            success = document.execCommand('copy');
        } catch (err) {
            success = false;
        }
        
        document.body.removeChild(ta);
        if (selected) {
            document.getSelection().removeAllRanges();
            document.getSelection().addRange(selected);
        }
    }
    
    // Visual feedback
    const pill = document.getElementById('status-pill');
    if (pill) {
        pill.innerText = success ? "Copied to Clipboard!" : "Copy Failed";
        pill.style.opacity = "1";
        pill.style.background = success ? "var(--primary-accent)" : "#ff3b30";
        setTimeout(() => { pill.style.opacity = "0"; }, 2000);
    }
};









window.addEventListener('resize', resize);

function setTouchMode(mode, persist = true) {
    // If manually switching tools while something is selected, clear the selection first
    if (persist && wbSelection.ids.size > 0 && mode !== touchMode) {
        // We don't call handleSelectionAction('clear') here to avoid recursion, 
        // just perform the cleanup.
        wbSelection.ids.clear();
        wbSelection.bounds = null;
        wbUpdateSelectionUI(false);
    }

    // 1. Reset all interaction flags to prevent "stuck" states
    isDrawing = false;
    isSelecting = false;
    isDraggingTextInsertion = false;
    currentStroke = null;
    lassoPoints =[];
    selectionBounds = null; 
    viewports.forEach(vp => vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height));
    
    if (activeTextEditor && mode !== 'text') {
        commitText();
    }

    // 2. Update Mode and Track User Intent
    touchMode = mode;
    if (persist) userSelectedTool = mode;

    // 3. Load tool-specific config (Sync Pan with Draw)
    let configMode = mode;
    if (configMode === 'pan') configMode = 'draw';

    if (configMode === 'draw' || configMode === 'highlight' || configMode === 'text') {
        const config = toolConfigs[configMode];
        if (config) {
            setBrushColor(config.color, false);
            if (config.width) setBrushWidth(config.width, false);
        }
        refreshPresets();
    }

    if (window.wb && window.wb.ui) window.wb.ui.update();
    
    if (persist) saveSettings({ touch_mode: mode, tool_configs: toolConfigs });
}



// Update pointerdown to trigger text input

function setBrushColor(c, persist = true) { 
    brushColor = c; 
    let targetMode = touchMode === 'pan' ? 'draw' : touchMode;

    if (targetMode === 'draw' || targetMode === 'highlight' || targetMode === 'text') {
        if (!toolConfigs[targetMode]) toolConfigs[targetMode] = {};
        toolConfigs[targetMode].color = c;
    }
    if (persist) saveSettings({ brush_color: c, tool_configs: toolConfigs });

    // If a text editor is active, update its color immediately
    if (activeTextEditor) {
        activeTextEditor.el.style.color = c;
        activeTextEditor.color = c;
    }
    
    // Update UI selection
    document.querySelectorAll('.color-dot').forEach(dot => {
        dot.style.boxShadow = dot.dataset.color === c ? '0 0 0 2px var(--primary-accent)' : '0 0 0 1px #ccc';
        dot.style.transform = dot.dataset.color === c ? 'scale(1.1)' : 'scale(1)';
    });
}



function toggleSizePopover() {
    const pop = document.getElementById('size-popover');
    const isVisible = pop.style.display === 'flex';
    pop.style.display = isVisible ? 'none' : 'flex';
    if (!isVisible) {
        document.getElementById('save-menu').style.display = 'none';
        document.getElementById('options-menu').style.display = 'none';
        refreshPresets();
    }
}

window.toggleSliderExpand = function() {
    const pop = document.getElementById('size-popover');
    const btn = document.getElementById('expand-slider-btn');
    const isExpanded = pop.classList.toggle('expanded');
    
    if (isExpanded) {
        btn.innerHTML = `<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"></polyline><polyline points="20 10 14 10 14 4"></polyline><line x1="14" y1="10" x2="21" y2="3"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>`;
        btn.style.color = "var(--primary-accent)";
    } else {
        btn.innerHTML = `<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>`;
        btn.style.color = "var(--text-secondary)";
    }
    if (window.navigator && window.navigator.vibrate) navigator.vibrate(5);
};

async function toggleStarCurrent() {
    const btn = document.getElementById('star-btn');
    const isStarred = btn.classList.contains('active');
    const type = (touchMode === 'highlight') ? 'highlight' : 'draw';
    
    if (isStarred) {
        const fd = new FormData();
        fd.append('action', 'get_presets');
        fd.append('type', type);
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();
        const existing = data.presets.find(p => {
            const snappedPSize = p.size < 1 ? parseFloat(p.size.toFixed(1)) : Math.round(p.size);
            return Math.abs(snappedPSize - brushWidth) < 0.1;
        });
        if (existing) await deletePreset(null, existing.id);
    } else {
        const fd = new FormData();
        fd.append('action', 'save_preset');
        fd.append('size', brushWidth);
        fd.append('type', type);
        await fetch('index.php', { method: 'POST', body: fd });
        refreshPresets();
    }
}

async function refreshPresets() {
    const container = document.getElementById('presets-container');
    const type = (touchMode === 'highlight') ? 'highlight' : 'draw';
    const fd = new FormData();
    fd.append('action', 'get_presets');
    fd.append('type', type);
    const res = await fetch('index.php', { method: 'POST', body: fd });
    const data = await res.json();
    
    container.innerHTML = '';
    starredSizes = data.presets.map(p => p.size < 1 ? parseFloat(p.size.toFixed(1)) : Math.round(p.size)); // Update local cache
    let isCurrentStarred = false;

    if (data.presets.length === 0) {
        container.innerHTML = `
            <div class="presets-empty-box">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" style="opacity:0.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                <span>Star a stroke size<br>for quick use later</span>
            </div>
        `;
    } else {
        data.presets.forEach(p => {
            const snappedPSize = p.size < 1 ? parseFloat(p.size.toFixed(1)) : Math.round(p.size);
            const isActive = Math.abs(snappedPSize - brushWidth) < 0.1;
            if (isActive) isCurrentStarred = true;
            
            const chip = document.createElement('div');
            chip.className = 'preset-chip' + (isActive ? ' active' : '');
            chip.dataset.size = snappedPSize;
            chip.innerText = snappedPSize < 1 ? snappedPSize.toFixed(1) : Math.round(snappedPSize);
            chip.onclick = () => setBrushWidth(snappedPSize);
            container.appendChild(chip);
        });
    }

    document.getElementById('star-btn').classList.toggle('active', isCurrentStarred);
}

async function deletePreset(e, id) {
    if (e) e.stopPropagation();
    const fd = new FormData();
    fd.append('action', 'delete_preset');
    fd.append('id', id);
    await fetch('index.php', { method: 'POST', body: fd });
    refreshPresets();
}

function setBrushWidth(w, persist = true) {
    let parsed = parseFloat(w);
    brushWidth = parsed < 1 ? parseFloat(parsed.toFixed(1)) : Math.round(parsed);
    let targetMode = touchMode === 'pan' ? 'draw' : touchMode;

    if (targetMode === 'draw' || targetMode === 'highlight') {
        if (!toolConfigs[targetMode]) toolConfigs[targetMode] = {};
        toolConfigs[targetMode].width = brushWidth;
    }
    const sizeValDisp = document.getElementById('size-val-display');
    if (sizeValDisp) sizeValDisp.innerText = brushWidth < 1 ? brushWidth.toFixed(1) : brushWidth;
    
    const sizeBtnVal = document.getElementById('size-btn-val');
    if (sizeBtnVal) sizeBtnVal.innerText = brushWidth < 1 ? brushWidth.toFixed(1) : brushWidth;
    
    // Sync slider element
    const slider = document.getElementById('size-slider');
    if (slider && slider.value !== brushWidth.toString()) slider.value = brushWidth;

    // Update preview dot
    const previewDot = document.getElementById('size-dot-preview');
    if (previewDot) {
        previewDot.style.width = brushWidth + 'px';
        previewDot.style.height = brushWidth + 'px';
    }
    
    // Update toolbar dot (capped for UI)
    const btnDot = document.getElementById('btn-size-dot');
    if (btnDot) {
        const displaySize = Math.min(Math.max(brushWidth, 2), 24);
        btnDot.style.width = displaySize + 'px';
        btnDot.style.height = displaySize + 'px';
    }

    // Update active state on chips
    document.querySelectorAll('.preset-chip').forEach(chip => {
        const chipSize = parseFloat(chip.dataset.size);
        chip.classList.toggle('active', Math.abs(chipSize - brushWidth) < 0.1);
    });

    // Reactive Star State
    const isStarred = starredSizes.some(s => Math.abs(s - brushWidth) < 0.1);
    const starBtn = document.getElementById('star-btn');
    if (starBtn) starBtn.classList.toggle('active', isStarred);

    if (persist) saveSettings({ brush_width: brushWidth, tool_configs: toolConfigs });
}

let isDraggingSize = false;
let isLongPressing = false;
let longPressTimer = null;
let dragStartX = 0;
let dragStartY = 0;
let lastTriggerY = 0;
let dragStartWidth = 0;
let swipeAxis = null; // 'x' or 'y'





function initSizeSwipe() {
    const btn = document.getElementById('size-btn');
    if (!btn) return;

    btn.addEventListener('contextmenu', e => e.preventDefault());

    btn.addEventListener('pointerdown', (e) => {
        dragStartX = e.clientX;
        dragStartY = e.clientY;
        lastTriggerY = e.clientY;
        dragStartWidth = brushWidth;
        isDraggingSize = false;
        isLongPressing = false;
        swipeAxis = null;
        btn.setPointerCapture(e.pointerId);

        // Start Long Press Timer (Peek)
        longPressTimer = setTimeout(() => {
            isLongPressing = true;
            const pop = document.getElementById('size-popover');
            if (pop.style.display !== 'flex') {
                pop.style.display = 'flex';
                refreshPresets();
            }
            if (window.navigator.vibrate) navigator.vibrate(10);
        }, 500);
    });

    btn.addEventListener('pointermove', (e) => {
        if (e.buttons !== 1) return;
        
        const deltaX = e.clientX - dragStartX;
        const deltaY = e.clientY - dragStartY;

        // Cancel long press if moving significantly
        if (Math.hypot(deltaX, deltaY) > 10) {
            clearTimeout(longPressTimer);
        }

        // Determine axis if not set
        if (!swipeAxis) {
            if (Math.abs(deltaY) > 10) swipeAxis = 'y';
            else if (Math.abs(deltaX) > 10 && window.wbSizeSwipeXEnabled) swipeAxis = 'x';
        }

        if (swipeAxis === 'y') {
            isDraggingSize = true;
            
            // Show popover for visual feedback
            const pop = document.getElementById('size-popover');
            if (pop.style.display !== 'flex') pop.style.display = 'flex';

            const threshold = 40; // Pixels per preset jump
            const moveDist = e.clientY - lastTriggerY;

            if (Math.abs(moveDist) > threshold) {
                if (starredSizes.length > 0) {
                    // Find current or closest index
                    let currentIdx = starredSizes.findIndex(s => Math.abs(s - brushWidth) < 0.1);
                    if (currentIdx === -1) {
                        currentIdx = starredSizes.reduce((prev, curr, idx) => 
                            Math.abs(curr - brushWidth) < Math.abs(starredSizes[prev] - brushWidth) ? idx : prev, 0);
                    }

                    // Swipe Up (negative deltaY) -> Larger/Next Preset
                    if (moveDist < 0) currentIdx = (currentIdx + 1) % starredSizes.length;
                    // Swipe Down (positive deltaY) -> Smaller/Prev Preset
                    else currentIdx = (currentIdx - 1 + starredSizes.length) % starredSizes.length;
                    
                    setBrushWidth(starredSizes[currentIdx]);
                    if (window.vibrate) navigator.vibrate(5);
                }
                lastTriggerY = e.clientY;
            }
        } else if (swipeAxis === 'x') {
            isDraggingSize = true;
            const pop = document.getElementById('size-popover');
            if (pop.style.display !== 'flex') pop.style.display = 'flex';

            const sensitivity = 4; 
            const newVal = Math.min(Math.max(dragStartWidth + (deltaX / sensitivity), 0.2), 50);
            setBrushWidth(newVal);
        }
    });

    btn.addEventListener('pointerup', (e) => {
        btn.releasePointerCapture(e.pointerId);
        clearTimeout(longPressTimer);

        // If we were dragging or long-pressing, prevent the click event 
        // and hide the popover.
        if (isDraggingSize || isLongPressing) {
            e.preventDefault();
            e.stopPropagation();
            
            // Hide popover
            document.getElementById('size-popover').style.display = 'none';
        }
    });
}












window.addEventListener('contextmenu', (e) => {
    const isInput = e.target.tagName === 'INPUT' || 
                    e.target.tagName === 'TEXTAREA' || 
                    e.target.isContentEditable;
    if (!isInput) e.preventDefault();
});

let isDraggingGrammarRatio = false;
let isLongPressingGrammarRatio = false;
let grLongPressTimer = null;
let grDragStartY = 0;
let grDragStartVal = 0;

function initGrammarRatioSwipe() {
    const btn = document.getElementById('grammar-ratio-btn');
    if (!btn) return;

    btn.addEventListener('contextmenu', e => e.preventDefault());

    btn.addEventListener('pointerdown', (e) => {
        grDragStartY = e.clientY;
        grDragStartVal = window.grammarLabelRatio;
        isDraggingGrammarRatio = false;
        isLongPressingGrammarRatio = false;
        btn.setPointerCapture(e.pointerId);

        grLongPressTimer = setTimeout(() => {
            isLongPressingGrammarRatio = true;
            const pop = document.getElementById('grammar-ratio-popover');
            if (pop.style.display !== 'flex') {
                pop.style.display = 'flex';
            }
            if (window.navigator.vibrate) navigator.vibrate(10);
        }, 500);
    });

    btn.addEventListener('pointermove', (e) => {
        if (e.buttons !== 1) return;
        
        const deltaY = e.clientY - grDragStartY;

        if (Math.abs(deltaY) > 10) {
            clearTimeout(grLongPressTimer);
            isDraggingGrammarRatio = true;
            
            const pop = document.getElementById('grammar-ratio-popover');
            if (pop.style.display !== 'flex') pop.style.display = 'flex';

            const sensitivity = 200; 
            let newVal = grDragStartVal - (deltaY / sensitivity);
            newVal = Math.max(0.2, Math.min(1.0, newVal));
            newVal = Math.round(newVal * 20) / 20; // snap to 0.05
            
            updateGrammarLabelRatio(newVal);
        }
    });

    btn.addEventListener('pointerup', (e) => {
        btn.releasePointerCapture(e.pointerId);
        clearTimeout(grLongPressTimer);

        if (isDraggingGrammarRatio || isLongPressingGrammarRatio) {
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('grammar-ratio-popover').style.display = 'none';
        }
    });
}

window.toggleGrammarRatioPopover = function() {
    const pop = document.getElementById('grammar-ratio-popover');
    pop.style.display = pop.style.display === 'flex' ? 'none' : 'flex';
};

window.updateGrammarLabelRatio = function(val, persist = true) {
    window.grammarLabelRatio = parseFloat(val);
    const pct = Math.round(window.grammarLabelRatio * 100);
    
    const btnVal = document.getElementById('grammar-ratio-btn-val');
    if (btnVal) btnVal.innerText = pct;
    
    const disp = document.getElementById('grammar-ratio-val-display');
    if (disp) disp.innerText = pct + '%';
    
    const slider = document.getElementById('grammar-ratio-slider');
    if (slider && slider.value !== window.grammarLabelRatio.toString()) {
        slider.value = window.grammarLabelRatio;
    }

    if (typeof activeTextEditor !== 'undefined' && activeTextEditor && activeTextEditor.isBlank) {
        activeTextEditor.originalStroke.labelRatio = window.grammarLabelRatio;
        delete activeTextEditor.originalStroke._b;
        delete activeTextEditor.originalStroke._cache;
        if (typeof requestRender === 'function') requestRender();
    }

    if (persist) saveSettings({ grammar_label_ratio: window.grammarLabelRatio });
};

function toggleSaveMenu() {
    const menu = document.getElementById('save-menu');
    menu.style.display = (menu.style.display === 'flex') ? 'none' : 'flex';
    if (menu.style.display === 'flex') document.getElementById('options-menu').style.display = 'none';
}

function toggleOptionsMenu() {
    const menu = document.getElementById('options-menu');
    menu.style.display = (menu.style.display === 'flex') ? 'none' : 'flex';
    if (menu.style.display === 'flex') document.getElementById('save-menu').style.display = 'none';
}

function toggleSettings() {
    const overlay = document.getElementById('settings-overlay');
    const isVisible = overlay.style.display === 'flex';
    overlay.style.display = isVisible ? 'none' : 'flex';
    if (!isVisible) {
        document.getElementById('auto-save-toggle').checked = autoSaveEnabled;
        // Reset to main page whenever opened
        wbOpenSettingsPage(0);
    }
}

window.wbOpenSettingsPage = function(pageIndex) {
    const wrapper = document.getElementById('settings-nav-wrapper');
    if (wrapper) {
        wrapper.style.transform = `translateX(-${pageIndex * 100}%)`;
    }
    if (window.navigator.vibrate) navigator.vibrate(5);
};

async function updateAutoSave(enabled) {
    autoSaveEnabled = enabled;
    saveSettings({ auto_save: enabled });
}

async function updateAutoUpdate(enabled) {
    window._autoUpdateEnabled = enabled;
    saveSettings({ auto_update: enabled });
}

window.updateRasterEnabled = function(enabled) {
    window.rasterCacheEnabled = enabled;
    saveSettings({ raster_cache_enabled: enabled });
    render();
};

window.updateVectorThreshold = function(val) {
    window.vectorThreshold = parseInt(val);
    document.getElementById('vector-thresh-display').innerText = val + '%';
    saveSettings({ vector_handoff_threshold: window.vectorThreshold });
    render();
};

window.updateCacheResolution = function(val) {
    const newVal = parseFloat(val);
    if (newVal === window.cacheResolution) return;
    
    window.cacheResolution = newVal;
    document.getElementById('cache-res-display').innerText = newVal.toFixed(1) + 'x';
    document.getElementById('cache-mem-warning').style.display = newVal > 5 ? 'block' : 'none';
    
    // CRITICAL: Invalidate all existing caches so they regenerate at the new resolution
    allStrokes.forEach(s => {
        if (s._cache) delete s._cache;
    });
    
    saveSettings({ cache_resolution: window.cacheResolution });
    render();
};

window.wbPurgeRenderCache = function() {
    allStrokes.forEach(s => {
        if (s._cache) delete s._cache;
    });
    const pill = document.getElementById('status-pill');
    pill.innerText = "RAM Released";
    pill.style.opacity = "1";
    setTimeout(() => pill.style.opacity = "0", 1500);
    render();
};

async function updateRotationEnabled(enabled) {
    rotationEnabled = enabled;
    saveSettings({ rotation_enabled: enabled });
    
    // If disabling, optionally reset rotation to 0 for a clean view
    viewports.forEach(vp => {
        if (!enabled && vp.transform.rotation !== 0) {
            vp.transform.rotation = 0;
        }
    });
    render();
}

function startUpdatePoller() {
    // Check every 30 seconds
    setInterval(async () => {
        if (document.hidden || navigator.onLine === false) return;
        const fd = new FormData();
        fd.append('action', 'check_version');
        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.hash && data.hash !== window._initialHash) {
                showUpdateModal();
            }
        } catch(e) {}
    }, 30000);
}

let updateCountdownInterval = null;
function showUpdateModal() {
    // Prevent multiple modals
    if (document.getElementById('update-modal-overlay')) return;

    const overlay = document.createElement('div');
    overlay.id = 'update-modal-overlay';
    overlay.style.display = 'flex';
    
    const isAuto = window._autoUpdateEnabled;
    let countdown = 5;

    overlay.innerHTML = `
        <div class="update-card">
            <div class="update-icon">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="3"><path d="M21 2v6h-6"></path><path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path><path d="M3 22v-6h6"></path><path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path></svg>
            </div>
            <h3 style="margin:0 0 8px 0; font-size:18px;">Update Available</h3>
            <p style="margin:0; font-size:13px; color:var(--text-secondary); line-height:1.4;">
                A new version of Whiteboard is ready. ${isAuto ? 'Refreshing in <span id="update-timer-text">5</span>s...' : 'Would you like to refresh now?'}
            </p>
            ${isAuto ? '<div class="update-timer-bar"><div id="update-progress-fill"></div></div>' : '<div style="height:20px;"></div>'}
            <div style="display:flex; gap:10px;">
                <button class="tool-btn" onclick="cancelUpdate()" style="flex:1; background:var(--bg-color);">Cancel</button>
                <button class="tool-btn" onclick="location.reload()" style="flex:1; background:var(--primary-accent); color:white; border:none;">Refresh</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    if (isAuto) {
        const fill = document.getElementById('update-progress-fill');
        const text = document.getElementById('update-timer-text');
        
        updateCountdownInterval = setInterval(() => {
            countdown--;
            if (text) text.innerText = countdown;
            if (fill) fill.style.width = (countdown / 5 * 100) + '%';
            
            if (countdown <= 0) {
                clearInterval(updateCountdownInterval);
                location.reload();
            }
        }, 1000);
    }
}

window.cancelUpdate = function() {
    const overlay = document.getElementById('update-modal-overlay');
    if (overlay) overlay.remove();
    if (updateCountdownInterval) clearInterval(updateCountdownInterval);
    // Stop checking for updates until next manual refresh to avoid pestering
    window._initialHash = "USER_CANCELLED"; 
};

window.hardResetApp = async function() {
    if (!await wbui.confirm("This will clear the app cache and unregister the Service Worker. Use this if the app is behaving strangely or not updating. Your drawings are safe in the database.", "Hard Reset", wbIcons.alert)) return;
    
    const pill = document.getElementById('status-pill');
    pill.innerText = "Clearing Code...";
    pill.style.opacity = "1";

    if ('serviceWorker' in navigator) {
        const registrations = await navigator.serviceWorker.getRegistrations();
        for (let registration of registrations) await registration.unregister();
    }

    if ('caches' in window) {
        const keys = await caches.keys();
        for (let key of keys) await caches.delete(key);
    }

    pill.innerText = "Reloading...";
    window.location.href = window.location.pathname + '?cb=' + Date.now();
};

window.wbAnnihilateAllData = async function() {
    const msg = "WARNING: This is the NUCLEAR OPTION.\n\nThis will permanently delete ALL local canvases, cached images, and settings from this browser. This cannot be undone.\n\nAre you absolutely sure?";
    if (!await wbui.confirm(msg, "Annihilate Everything", "☢️")) return;

    const pill = document.getElementById('status-pill');
    pill.innerText = "ANNIHILATING...";
    pill.style.opacity = "1";
    pill.style.background = "#ff3b30";

    // 1. Close and Delete IndexedDB
    if (localDB) {
        localDB.close();
        await new Promise((resolve) => {
            const req = indexedDB.deleteDatabase(DB_NAME);
            req.onsuccess = () => { console.log("IDB Deleted"); resolve(); };
            req.onerror = () => { console.error("IDB Delete Failed"); resolve(); };
            req.onblocked = () => { console.warn("IDB Delete Blocked"); resolve(); };
        });
    }

    // 2. Clear LocalStorage
    localStorage.clear();

    // 3. Clear Cookies
    document.cookie.split(";").forEach(function(c) {
        document.cookie = c.replace(/^ +/, "").replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/");
    });

    // 4. Clear Cache API & Service Workers (Reuse Hard Reset logic)
    if ('serviceWorker' in navigator) {
        const registrations = await navigator.serviceWorker.getRegistrations();
        for (let registration of registrations) await registration.unregister();
    }
    if ('caches' in window) {
        const keys = await caches.keys();
        for (let key of keys) await caches.delete(key);
    }

    pill.innerText = "WIPE COMPLETE";
    // 5. Redirect to base URL to prevent any state carry-over
    setTimeout(() => {
        window.location.href = window.location.pathname;
    }, 1000);
};

function setTheme(themeId, persist = true) {
    const themes = ['light', 'blueprint', 'sepia', 'dark'];
    themes.forEach(t => document.body.classList.remove('theme-' + t));
    document.body.classList.add('theme-' + themeId);
    
    // Update UI active states
    document.querySelectorAll('.theme-dot').forEach(dot => {
        dot.style.boxShadow = dot.dataset.theme === themeId ? '0 0 0 2px var(--primary-accent)' : 'none';
    });

    if (persist) saveSettings({ theme: themeId });
    updatePatternColorCache();
    _patternCache = {};
    render();
}

function setPaper(paperId, persist = true) {
    paperType = paperId;
    const papers = ['plain', 'grid', 'ruled', 'dots'];
    const container = document.getElementById('canvas-container');
    papers.forEach(p => container.classList.remove('paper-' + p));
    container.classList.add('paper-' + paperId);

    // Update UI active states
    document.querySelectorAll('.paper-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.paper === paperId);
    });

    if (persist) saveSettings({ paper: paperId });
    _patternCache = {};
    render();
}

let cachedPatternColor = 'rgba(0,0,0,0.05)';
function updatePatternColorCache() {
    cachedPatternColor = getComputedStyle(document.body).getPropertyValue('--pattern-color') || 'rgba(0,0,0,0.05)';
}

let _patternCache = {};
function drawPaperPattern(vp) {
    if (paperType === 'plain') return;
    const ctx = vp.ctx;
    const scale = vp.transform.scale;
    
    // Determine LOD level for caching
    let lod = 1;
    if (scale < 0.15) lod = 8;
    else if (scale < 0.4) lod = 4;
    else if (scale < 0.7) lod = 2;

    const cacheKey = `${paperType}-${lod}-${cachedPatternColor}`;
    
    if (!_patternCache[cacheKey]) {
        const pCanvas = document.createElement('canvas');
        const pCtx = pCanvas.getContext('2d');
        const stepX = (paperType === 'dots' ? 30 : 40) * lod;
        const stepY = (paperType === 'ruled' ? 32 : (paperType === 'dots' ? 30 : 40)) * lod;
        
        pCanvas.width = stepX;
        pCanvas.height = stepY;
        pCtx.strokeStyle = cachedPatternColor;
        pCtx.fillStyle = cachedPatternColor;
        
        // VISIBILITY FIX: Increase line weight as LOD increases (zoomed out)
        // This compensates for the browser's downscaling.
        const weight = lod === 1 ? 1 : (lod * 0.8);
        pCtx.lineWidth = weight;

        if (paperType === 'grid' || paperType === 'ruled') {
            pCtx.beginPath();
            // Offset by half-width for crispest possible lines at LOD 1
            const offset = (weight % 2 === 0) ? 0 : 0.5;
            if (paperType === 'grid') { pCtx.moveTo(offset, 0); pCtx.lineTo(offset, stepY); }
            pCtx.moveTo(0, offset); pCtx.lineTo(stepX, offset);
            pCtx.stroke();
        } else if (paperType === 'dots') {
            pCtx.beginPath();
            // Increase dot radius for visibility at high zoom-out
            const radius = lod === 1 ? 1.5 : (lod * 1.2);
            pCtx.arc(stepX/2, stepY/2, radius, 0, Math.PI * 2);
            pCtx.fill();
        }
        _patternCache[cacheKey] = ctx.createPattern(pCanvas, 'repeat');
    }

    ctx.save();
    // Use fixed world coordinates for the pattern so it stays pinned to the grid
    ctx.fillStyle = _patternCache[cacheKey];
    
    // VIEWPORT-AWARE FILL: Calculate the visible bounds in world space
    const rect = vp.canvas.getBoundingClientRect();
    const tl = getCanvasCoords({ clientX: rect.left, clientY: rect.top }, vp);
    const tr = getCanvasCoords({ clientX: rect.right, clientY: rect.top }, vp);
    const bl = getCanvasCoords({ clientX: rect.left, clientY: rect.bottom }, vp);
    const br = getCanvasCoords({ clientX: rect.right, clientY: rect.bottom }, vp);

    const minX = Math.min(tl.x, tr.x, bl.x, br.x);
    const maxX = Math.max(tl.x, tr.x, bl.x, br.x);
    const minY = Math.min(tl.y, tr.y, bl.y, br.y);
    const maxY = Math.max(tl.y, tr.y, bl.y, br.y);

    // Fill exactly what the user sees, plus a tiny 1px overlap to prevent edge gaps
    ctx.fillRect(minX - 1, minY - 1, (maxX - minX) + 2, (maxY - minY) + 2);
    ctx.restore();
}

let saveSettingsTimeout = null;
let pendingSettings = {};

async function saveSettings(newSettings) {
    // 1. Update In-Memory State immediately for UI responsiveness
    if (window.wb && window.wb.settings) {
        Object.assign(window.wb.settings, newSettings);
    }

    // 2. Update Local Metadata Cache (Offline Persistence)
    if (typeof getMetadata === 'function' && typeof saveMetadata === 'function') {
        getMetadata('full_snapshot').then(snap => {
            if (snap) {
                if (!snap.settings) snap.settings = {};
                Object.assign(snap.settings, newSettings);
                saveMetadata('full_snapshot', snap);
            }
        });
    }

    // 3. Queue Cloud Sync
    Object.assign(pendingSettings, newSettings);
    
    if (saveSettingsTimeout) clearTimeout(saveSettingsTimeout);
    
    saveSettingsTimeout = setTimeout(async () => {
        const toSave = { ...pendingSettings };
        const fd = new FormData();
        fd.append('action', 'save_settings');
        fd.append('settings', JSON.stringify(toSave));
        
        try {
            await fetch('index.php', { method: 'POST', body: fd });
            // Only remove keys that haven't been updated since we started the save
            for (let key in toSave) {
                if (pendingSettings[key] === toSave[key]) {
                    delete pendingSettings[key];
                }
            }
        } catch(e) { console.error("Settings save failed", e); }
    }, 500); // 500ms debounce
}

function exportDatabase() {
    const fd = new FormData();
    fd.append('action', 'export_data');
    
    fetch('index.php', { method: 'POST', body: fd })
        .then(res => res.blob())
        .then(blob => {
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `whiteboard_backup_${new Date().toISOString().slice(0,19).replace(/[:T]/g, '_')}.json`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(url);
            
            const pill = document.getElementById('status-pill');
            pill.innerText = "Backup Ready";
            pill.style.opacity = "1";
            setTimeout(() => pill.style.opacity = "0", 2000);
        })
        .catch(err => alert("Export failed: " + err));
}

async function triggerRestore() {
    if (await wbui.confirm("WARNING: Restoring will overwrite ALL current drawings, stickers, and settings. Continue?", "System Restore", wbIcons.alert)) {
        document.getElementById('restore-input').click();
    }
}

async function importDatabase(input) {
    const file = input.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = async (e) => {
        const data = e.target.result;
        const fd = new FormData();
        fd.append('action', 'import_data');
        fd.append('data', data);

        const pill = document.getElementById('status-pill');
        pill.innerText = "Restoring...";
        pill.style.opacity = "1";

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const result = await res.json();
            if (result.status === 'success') {
                alert("Restore successful. The app will now reload.");
                location.reload();
            } else {
                alert("Restore failed: " + result.message);
                pill.style.opacity = "0";
            }
        } catch (err) {
            alert("Network error during restore.");
            pill.style.opacity = "0";
        }
    };
    reader.readAsText(file);
    input.value = ''; // Reset input
}

















async function wbDeleteAssetFromPreview(hash) {
    wbCloseAssetPreview();
    // Re-use existing logic
    await wbDeleteAsset({ stopPropagation: () => {} }, hash);
}

// Unified Magic Canvas Creator
window.wbCreateMagicCanvas = async function(path, isSingle = false, singleHash = null) {
    const parts = path.split('/').filter(p => p);
    const current = parts.pop() || 'Magic Canvas';
    const parent = parts.pop();
    const suggestedName = parent ? `${parent} - ${current}` : current;

    const name = await window.wbui.input("Name your new canvas", "Magic Canvas", suggestedName, "✨");
    if (!name) return;
    
    document.getElementById('media-vault-overlay').style.display = 'none';
    const pill = document.getElementById('status-pill');
    pill.innerText = "Creating...";
    pill.style.opacity = "1";
    
    let realId = null;
    if (navigator.onLine !== false) {
        try {
    const fd = new FormData();
    fd.append('action', 'create_canvas');
    fd.append('name', name);
    fd.append('folder_id', typeof wbCurrentFolderId !== 'undefined' ? wbCurrentFolderId : 0);
                    
    const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 3000);
    const data = await res.json();if (data.status === 'success') realId = data.id;
        } catch (e) {
            console.warn("Server unreachable, falling back to offline creation.");
        }
    }

    if (!realId) {
        realId = 'local_' + Date.now();
        const snap = await getMetadata('full_snapshot');
        if (snap) {
            if (!snap.canvases) snap.canvases =[];
            snap.canvases.unshift({
                id: realId,
                name: name,
                folder_id: typeof wbCurrentFolderId !== 'undefined' ? wbCurrentFolderId : 0,
                thumbnail: '',
                created_at: Math.floor(Date.now()/1000),
                updated_at: Math.floor(Date.now()/1000)
            });
            await saveMetadata('full_snapshot', snap);
        }
        await saveLocalDocument('canvas_' + realId, '[]', true, 0, '');
    }

    if (isSingle && singleHash) {
    try {
        const listFd = new FormData();
        listFd.append('action', 'list_library');
        listFd.append('path', path.includes('/') ? path.substring(0, path.lastIndexOf('/')) : '');
        const listRes = await wbFetchWithTimeout('index.php', { method: 'POST', body: listFd }, 3000);
        const listData = await listRes.json();const fileObj = listData.files.find(f => f.hash === singleHash);
            if (fileObj) sessionStorage.setItem('wb_pending_magic', JSON.stringify([fileObj]));
        } catch(e) {
            // Offline fallback for single file
            const snap = await getMetadata('full_snapshot');
            if (snap && snap.assets) {
                const asset = snap.assets.find(a => a.hash === singleHash);
                if (asset) {
                    sessionStorage.setItem('wb_pending_magic', JSON.stringify([{
                        hash: singleHash,
                        name: path.split('/').pop()
                    }]));
                }
            }
        }
        window.location.href = '?canvas=' + realId + '&magic_trigger=selection';
    } else {
        window.location.href = '?canvas=' + realId + '&magic_folder=' + encodeURIComponent(path);
    }
};

async function wbDeleteAsset(e, hash) {
    if (e && e.stopPropagation) e.stopPropagation();
    
    const pill = document.getElementById('status-pill');
    if (pill) {
        pill.innerText = "Checking usage...";
        pill.style.opacity = "1";
    }

    let usage = [];
    try {
        const fd = new FormData();
        fd.append('action', 'get_asset_usage');
        fd.append('hash', hash);
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') usage = data.canvases;
    } catch (err) {
        console.warn("Could not check usage", err);
    }

    if (pill) pill.style.opacity = "0";

    let msg = "Delete this asset permanently from the global vault?";
    if (usage.length > 0) {
        const list = usage.slice(0, 5).map(c => "• " + c).join("\n");
        const extra = usage.length > 5 ? `\n...and ${usage.length - 5} more.` : "";
        msg = `⚠️ WARNING: This asset is actively used in ${usage.length} canvas(es):\n\n${list}${extra}\n\nDeleting it will show a broken link in those canvases. Delete anyway?`;
    }

    if (!await wbui.confirm(msg, "Delete Asset", wbIcons.trash)) return;

    const fd = new FormData();
    fd.append('action', 'delete_asset');
    fd.append('hash', hash);
    
    try {
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
            // 1. Remove from local cache
            await deleteLocalAsset(hash);
            delete wbImageCache[hash];
            // 2. Refresh UI
            if (typeof window.wbCurrentVaultPath !== 'undefined') {
                wbOpenMediaVault(window.wbCurrentVaultPath, false);
            } else {
                wbOpenMediaVault();
            }
        }
    } catch(e) { alert("Delete failed"); }
}

async function deleteLocalAsset(hash) {
    return new Promise((resolve) => {
        if (!localDB) return resolve();
        const tx = localDB.transaction('assets', 'readwrite');
        const store = tx.objectStore('assets');
        const req = store.delete(hash);
        req.onsuccess = () => resolve();
    });
}

async function wbLoadVaultThumbnail(hash, ext) {
    const thumb = document.getElementById(`vault-thumb-${hash}`);
    const pageLabel = document.getElementById(`vault-pages-${hash}`);

    if (ext === 'pdf') {
        try {
            let sourceData = await getLocalAsset(hash);
            let arrayBuffer;
            if (sourceData) {
                const binary = atob(sourceData.split(',')[1]);
                const array = new Uint8Array(binary.length);
                for (let i = 0; i < binary.length; i++) array[i] = binary.charCodeAt(i);
                arrayBuffer = array.buffer;
            } else {
    const fd = new FormData();
    fd.append('action', 'get_asset');
    fd.append('hash', hash);
    const assetRes = await fetch('index.php', { method: 'POST', body: fd });
    const assetData = await assetRes.json();
    if (!assetData.url) throw new Error("Asset not found");
                        
    const response = await fetch(assetData.url);
    arrayBuffer = await response.arrayBuffer();
}const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
            
            if (pageLabel) pageLabel.innerText = `• ${pdf.numPages} Pages`;

            // Generate Thumbnail from first page
            const page = await pdf.getPage(1);
            const viewport = page.getViewport({ scale: 0.3 });
            const canvas = document.createElement('canvas');
            canvas.height = viewport.height;
            canvas.width = viewport.width;
            await page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
            
            if (thumb) {
                thumb.style.backgroundImage = `url('${canvas.toDataURL('image/jpeg', 0.7)}')`;
                thumb.innerHTML = '';
            }
        } catch (e) { console.warn("PDF thumb failed", e); }
        return;
    }

    await fetchAsset(hash);
    const img = wbImageCache[hash];
    if (img && thumb && img !== 'loading') {
        thumb.style.backgroundImage = `url('${img.src}')`;
        thumb.innerHTML = ''; 
    }
}

async function wbInsertAssetFromVault(hash) {
    const overlay = document.getElementById('media-vault-overlay');
    overlay.style.display = 'none';

    const pill = document.getElementById('status-pill');
    pill.innerText = "Retrieving Asset...";
    pill.style.opacity = "1";

    await fetchAsset(hash);
    const img = wbImageCache[hash];
    
    if (img && img !== 'loading') {
        // Use the existing placement logic but skip hashing/uploading
        const vp = getActiveViewport();
        const rect = vp.canvas.getBoundingClientRect();
        const worldCenter = getCanvasCoords({ 
            clientX: rect.left + rect.width/2, 
            clientY: rect.top + rect.height/2 
        }, vp);

        const viewWidthInWorld = rect.width / vp.transform.scale;
        const initialScale = (viewWidthInWorld * 0.6) / img.naturalWidth;
        const finalW = img.naturalWidth * initialScale;
        const finalH = img.naturalHeight * initialScale;

        const newImage = {
            id: wbCreateId(),
            zIndex: wbGetNextZIndex(),
            type: 'image',
            assetId: hash,
            data: '', 
            x: worldCenter.x - finalW / 2,
            y: worldCenter.y - finalH / 2,
            w: finalW,
            h: finalH
        };

        wbPushUndo();
        allStrokes.push(newImage);
        wbSelection.ids.clear();
        wbSelection.ids.add(newImage.id);
        wbSelection.bounds = { x: newImage.x, y: newImage.y, w: newImage.w, h: newImage.h };
        
        render();
        wbUpdateSelectionUI(true);
        saveDrawing();
        if (window.navigator.vibrate) navigator.vibrate(10);
    }
    
    pill.style.opacity = "0";
}



// Global click-outside listener for popups
document.addEventListener('pointerdown', (e) => {
    const popups = [
        { id: 'save-menu', btn: 'save-btn' },
        { id: 'options-menu', btn: 'options-btn' },
        { id: 'selection-menu', btn: null },
        { id: 'size-popover', btn: 'size-btn' },
        { id: 'split-popover', btn: 'split-btn' },
        { id: 'grammar-ratio-popover', btn: 'grammar-ratio-btn' }
    ];

    popups.forEach(p => {
        const el = document.getElementById(p.id);
        if (!el || el.style.display !== 'flex') return;

        // Check if click is inside the popup or on its trigger button
        const isInside = el.contains(e.target);
        const isOnBtn = p.btn ? document.getElementById(p.btn)?.contains(e.target) : false;

        if (!isInside && !isOnBtn) {
            // We no longer auto-clear selection-menu on outside tap to allow panning.
            // Selection is now cleared via 'Done' button, 'Cancel' menu item, or tool switch.
            if (p.id !== 'selection-menu') {
                el.style.display = 'none';
            }
        }
    });
});



// --- SERVICE WORKER DIAGNOSTIC ---
if (!('serviceWorker' in navigator)) {
    console.error("SW: Service Workers are NOT supported by this browser or this connection (Requires HTTPS or localhost).");
} else {
    navigator.serviceWorker.getRegistration().then(reg => {
        if (reg) console.log("SW: Service Worker is currently:", reg.active ? "ACTIVE" : "PENDING/WAITING");
        else console.warn("SW: No Service Worker registration found. Try 'Hard Reset App'.");
    });
}

// --- PLUGIN HOOKS ---
window.wb.on('onRenderEnd', () => {
    const uBtn = document.getElementById('undo-btn');
    const rBtn = document.getElementById('redo-btn');
    const zInd = document.getElementById('zoom-indicator');
    
    if (uBtn) uBtn.disabled = allStrokes.length === 0;
    if (rBtn) rBtn.disabled = redoStack.length === 0;
    if (zInd) zInd.innerText = Math.round(getActiveViewport().transform.scale * 100) + '%';
});

// --- GLOBAL ROUTING OVERRIDE (IMMEDIATE) ---
(function() {
    const urlParams = new URLSearchParams(window.location.search);
    let urlCid = urlParams.get('canvas');
    const currentId = String(window.currentCanvasId || '1');

    // 1. URL Synchronization (Zero-Reload)
    if (urlCid) {
        // The URL is the source of truth. If the SW served a cached HTML, 
        // the injected PHP variable might be stale.
        window.currentCanvasId = urlCid;
    } else {
        // No ID in URL, update URL to reflect state without stripping other params
        urlParams.set('canvas', currentId);
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?' + urlParams.toString();
        window.history.replaceState({path: cleanUrl}, '', cleanUrl);
        urlCid = currentId;
    }

    // 2. Persistence Synchronization
    // Ensure local storage and server settings match the current session
    if (urlCid !== localStorage.getItem('wb_last_canvas')) {
        localStorage.setItem('wb_last_canvas', urlCid);
        if (typeof saveSettings === 'function') {
            saveSettings({ last_canvas_id: urlCid });
        }
    }
})();

// Initialize App
initLocalDB().then(async () => {
    // 1. Establish dimensions first so centering logic has valid rects
    resize();
    
    // 1.5 Offline Settings Recovery: Prefer local metadata over stale cached PHP injection
    try {
        const snap = await getMetadata('full_snapshot');
        if (snap && snap.settings && window.wb) {
            console.log("Sync: Restoring settings from local Bunker.");
            window.wb.settings = Object.assign({}, window.wb.settings, snap.settings);
        }
    } catch(e) { console.warn("Sync: Local settings recovery failed", e); }

    try {
        const localDoc = await getLocalDocument('canvas_' + window.currentCanvasId);
        
        // OFFLINE SANITY CHECK:
        const isInitialDataValid = (window._initialCanvasId == window.currentCanvasId);
        const serverData = isInitialDataValid ? (window._initialData || '') : '';
        
        // DECISION ENGINE:
        if (!isInitialDataValid || (localDoc && localDoc.dirty)) {
            // 1. Stale Cache or Offline Work: Priority is Local Storage
            console.log("Sync: Loading from IndexedDB (ID: " + window.currentCanvasId + ")");
            lastSyncedId = localDoc ? (localDoc.lastSyncedId || 0) : 0;
            lastServerHash = localDoc ? (localDoc.lastServerHash || "") : "";
            loadCanvasData(localDoc ? localDoc.data : '[]');
            
            if (localDoc && localDoc.dirty) {
                // Priority 1: We have unsaved work. Push it immediately.
                console.log("Sync: Local work is dirty. Triggering immediate upload...");
                await saveDrawing(true); 
            } else if (!isInitialDataValid && navigator.onLine) {
                // Priority 2: No local work, but the PHP data is for the wrong canvas. Pull correct data.
                console.log("Sync: Initial data was stale. Fetching correct canvas from cloud...");
                fetchRemoteUpdate(true, false); 
            }
        } 
        else if (serverData) {
            // 2. Local is CLEAN and matches the PHP-injected ID.
            console.log("Sync: Loading server-injected data.");
            loadCanvasData(serverData);
            
            // CRITICAL: Calculate hash AFTER loading to account for any plugin normalization
            const finalData = JSON.stringify(allStrokes);
            lastSyncedId = window._initialId || 0;
            lastServerHash = wbGetHash(finalData);
            
            await saveLocalDocument('canvas_' + window.currentCanvasId, finalData, false, lastSyncedId, lastServerHash);
        }
        else {
            // 3. Server is EMPTY: This is a brand new canvas.
            // We ignore localDoc here unless it was dirty, because if it's not dirty 
            // and the server is empty, the localDoc is just a "ghost" from a deleted canvas.
            console.log("Sync: Canvas is new/empty.");
            lastSyncedId = 0;
            lastServerHash = "";
            loadCanvasData(''); // Destructively clear the canvas
        }

        // BROWSER SETTLE DELAY: Wait 500ms for the browser to release old session RAM
        // before we start the heavy engine. This fixes the "Empty Canvas on Load" bug.
        setTimeout(() => {
            console.log("Memory: Triggering Auto-Purge for fresh session.");
            if (typeof wbPurgeRenderCache === 'function') wbPurgeRenderCache();
            window._wbInitialLoadComplete = true;
            console.log("Sync: Initialization Shield Lifted.");
        }, 500);

    } catch(e) {
        console.error("Sync: Init Error", e);
        if (window._initialData) loadCanvasData(window._initialData);
    }
}).catch(e => {
    console.error("Local DB Init Failed", e);
    // Final fallback: Load server data if DB is totally broken
    if (window._initialData) loadCanvasData(window._initialData);
    resize();
});