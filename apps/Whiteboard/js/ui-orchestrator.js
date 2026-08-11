/**
 * WHITEBOARD UI ORCHESTRATOR
 * Manages the modular Tiered Toolbar System.
 */
window.wb.ui = {
    registry: new Map(),
    layoutMap: ['nav', 'tools', 'media', 'system'],
    sections: { nav: [], tools: [], media: [], system:[] },
    pinnedTiers: new Set(),
    dockedTiers: new Set(),
    expandedSections: new Set(),
    
    registerAction: function(id, config) {
        this.registry.set(id, config);
        if (config.section && this.sections[config.section]) {
            // Only add if not already present (prevents duplicates and respects loaded settings)
            if (!this.sections[config.section].includes(id)) {
                this.sections[config.section].push(id);
            }
        }
    },
    
    render: function() {
        const stack = document.getElementById('wb-ui-stack');
        if (!stack) return;
        
        // Clear stack safely (don't destroy tier3 elements if they are inside)
        if (this.tier3Els) {
            Object.values(this.tier3Els).forEach(el => {
                if (el && el.parentNode) el.parentNode.removeChild(el);
            });
        }
        stack.innerHTML = '';
        
        // --- TIER 1: MAIN DOCK ---
        const tier1 = document.createElement('div');
        tier1.className = 'wb-tier1-dock';
        tier1.style.cssText = 'display:flex; justify-content:flex-start; align-items:center; gap:12px; background:var(--card-bg); padding:0 16px; height: var(--toolbar-height); border-top:1px solid rgba(0,0,0,0.1); width: 100%; box-sizing: border-box; overflow-x: auto; overflow-y: hidden; scrollbar-width: none; -webkit-overflow-scrolling: touch;';
        
        // Center alignment on larger screens (handled via CSS in style.css normally, but inline for safety)
        if (window.innerWidth >= 800) {
            tier1.style.justifyContent = 'space-around';
            tier1.style.padding = '0 40px';
        }
        
        let hasAppended = false;
        this.layoutMap.forEach((sec, sIdx) => {
            let secEl = null;
            
            if (this.sections[sec]) {
                const acts = this.sections[sec];
                if (acts && acts.length > 0) {
                    secEl = document.createElement('div');
                    secEl.className = 'tool-section';
                    
                    acts.forEach(id => {
                        const config = this.registry.get(id);
                        if (!config) return;
                        const btn = document.createElement('button');
                        btn.className = 'tool-btn';
                        if (config.id) btn.id = config.id;
                        if (config.title) btn.title = config.title;
                        btn.innerHTML = config.icon;
                        if (config.color) btn.style.color = config.color;
                        if (config.cssText) btn.style.cssText += config.cssText;
                        
                        btn.onclick = (e) => {
                            if (config.onClick) config.onClick(e);
                            this.update();
                        };
                        secEl.appendChild(btn);
                        if (config.onRender) setTimeout(() => config.onRender(btn), 0);
                    });
                }
            } else if (this.dockedTiers && this.dockedTiers.has(sec)) {
                secEl = (sec === 'settings') ? this.createSettingsBar() : this.createGrammarBar();
                if (secEl) secEl.classList.add('wb-tier2-docked');
            }
            
            if (secEl) {
                if (hasAppended) {
                    const sep = document.createElement('div');
                    sep.style.cssText = 'width:1px; background:rgba(0,0,0,0.1); height:24px; margin:0 4px; flex-shrink:0;';
                    sep.className = `wb-dock-sep-${sec}`;
                    tier1.appendChild(sep);
                }
                tier1.appendChild(secEl);
                hasAppended = true;
            }
        });
        
        stack.appendChild(tier1);
        
        // --- TIER 2: CONTEXTUAL DOCKS ---
        this.renderTier2(stack);
        
        // --- TIER 3: SITUATIONAL DOCK ---
        const tier3 = document.createElement('div');
        tier3.id = 'wb-tier3-dock';
        tier3.style.cssText = 'display:flex; flex-direction:column; align-items:center; gap:8px; pointer-events:none; width:100%;';
        
        if (this.tier3Els) {
            if (this.tier3Els.move) tier3.appendChild(this.tier3Els.move);
            if (this.tier3Els.text) tier3.appendChild(this.tier3Els.text);
            if (this.tier3Els.selection) tier3.appendChild(this.tier3Els.selection);
            if (this.tier3Els.selectionCommit) tier3.appendChild(this.tier3Els.selectionCommit);
        }
        
        stack.appendChild(tier3);
        
        this.update();
    },
    
    renderTier2: function(stack) {
        const mode = typeof touchMode !== 'undefined' ? touchMode : 'draw';
        
        const bars = [
            { id: 'settings', active: ['draw', 'highlight', 'text'].includes(mode) },
            { id: 'grammar', active: (mode === 'text') }
        ];

        bars.forEach(b => {
            const isDocked = this.dockedTiers && this.dockedTiers.has(b.id);
            if (isDocked) return; // Handled by Tier 1 render loop

            const isPinned = this.pinnedTiers.has(b.id);
            const shouldShow = b.active || isPinned;

            if (shouldShow) {
                const newBar = (b.id === 'settings') ? this.createSettingsBar() : this.createGrammarBar();
                const tier3 = document.getElementById('wb-tier3-dock');
                if (tier3) stack.insertBefore(newBar, tier3);
                else stack.appendChild(newBar);
            }
        });
    },

    createBarContainer: function(id) {
        const bar = document.createElement('div');
        bar.className = `wb-tier2-dock wb-bar-${id}`;
        bar.style.cssText = 'display:flex; flex-wrap:wrap; justify-content:center; align-items:center; gap:12px; background:var(--card-bg); padding:8px 16px; border-radius:16px; border:1px solid rgba(0,0,0,0.1); box-shadow:0 8px 24px rgba(0,0,0,0.15); margin: 0 auto; pointer-events:auto; width: max-content; max-width: 95%; position:relative; transition: all 0.2s;';
        
        const isPinned = this.pinnedTiers.has(id);
        const isDocked = this.dockedTiers && this.dockedTiers.has(id);
        
        const pinBtn = document.createElement('button');
        pinBtn.className = 'wb-tier-pin' + (isPinned || isDocked ? ' active' : '');
        pinBtn.title = isDocked ? 'Docked (Tap to undock)' : (isPinned ? 'Pinned (Hold to dock)' : 'Pin toolbar (Hold to dock)');
        
        if (isDocked) {
            pinBtn.innerHTML = `<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19h16"/><path d="M12 4v11"/><path d="M8 11l4 4 4-4"/></svg>`;
        } else {
            pinBtn.innerHTML = `<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="${isPinned ? 'currentColor' : 'none'}" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A.5.5 0 0 0 6 13.9V15a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-1.1a.5.5 0 0 0-.11-.3l-1.78-.9a2 2 0 0 1-1.11-1.79V4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2Z"/></svg>`;
        }

        let lpTimer;
        let hasLongPressed = false;

        pinBtn.onpointerdown = (e) => {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            hasLongPressed = false;
            lpTimer = setTimeout(() => {
                hasLongPressed = true;
                if (isDocked) {
                    this.dockedTiers.delete(id);
                    this.pinnedTiers.add(id);
                    this.layoutMap = this.layoutMap.filter(s => s !== id);
                } else {
                    this.dockedTiers.add(id);
                    this.pinnedTiers.delete(id);
                    if (!this.layoutMap.includes(id)) this.layoutMap.push(id);
                }
                if (typeof saveSettings === 'function') {
                    saveSettings({ docked_tiers: Array.from(this.dockedTiers), pinned_tiers: Array.from(this.pinnedTiers), toolbar_layout: this.layoutMap });
                }
                this.render();
                if (window.navigator.vibrate) navigator.vibrate([10, 20]);
            }, 500);
        };

        pinBtn.onpointerup = (e) => {
            clearTimeout(lpTimer);
            if (!hasLongPressed) {
                if (isDocked) {
                    this.dockedTiers.delete(id);
                    this.layoutMap = this.layoutMap.filter(s => s !== id);
                } else {
                    if (isPinned) this.pinnedTiers.delete(id);
                    else this.pinnedTiers.add(id);
                }
                if (typeof saveSettings === 'function') {
                    saveSettings({ docked_tiers: Array.from(this.dockedTiers), pinned_tiers: Array.from(this.pinnedTiers), toolbar_layout: this.layoutMap });
                }
                this.render();
                if (window.navigator.vibrate) navigator.vibrate(5);
            }
        };

        pinBtn.onpointercancel = pinBtn.onpointerleave = () => {
            clearTimeout(lpTimer);
        };

        pinBtn.oncontextmenu = (e) => e.preventDefault();

        bar.appendChild(pinBtn);
        return bar;
    },

    createSettingsBar: function() {
        const bar = this.createBarContainer('settings');

        // Size Button
        const sizeBtn = document.createElement('button');
        sizeBtn.className = 'tool-btn';
        sizeBtn.id = 'size-btn';
        sizeBtn.title = 'Adjust Size';
        sizeBtn.style.cssText = 'display:flex; align-items:center; gap:10px; min-width:60px; justify-content:center; padding: 6px 12px;';
        sizeBtn.onclick = () => { if(typeof isDraggingSize !== 'undefined' && !isDraggingSize) toggleSizePopover(); };
        sizeBtn.innerHTML = `<div id="btn-size-dot" style="width:4px; height:4px; background:var(--text-primary); border-radius:50%;"></div><span id="size-btn-val" style="font-size:12px; font-variant-numeric: tabular-nums;">4</span>`;
        bar.appendChild(sizeBtn);
        
        // Separator
        const sep = document.createElement('div');
        sep.style.cssText = 'width:1px; background:rgba(0,0,0,0.1); height:20px; margin:0 4px; flex-shrink:0;';
        bar.appendChild(sep);
        
        // Colors (Appended directly to bar for better wrapping)
        const colors =['#ffff00', '#000000', '#ff3b30', '#34c759', '#007aff'];
        colors.forEach(c => {
            const dot = document.createElement('div');
            dot.className = 'color-dot';
            dot.dataset.color = c;
            dot.style.cssText = `width:24px; height:24px; border-radius:50%; background:${c}; border:2px solid white; box-shadow:0 0 0 1px #ccc; cursor:pointer; transition:transform 0.1s; flex-shrink:0;`;
            dot.onpointerdown = e => e.preventDefault();
            dot.onclick = () => { if (typeof setBrushColor === 'function') setBrushColor(c); window.wb.ui.update(); };
            bar.appendChild(dot);
        });
        
        setTimeout(() => {
            if (typeof initSizeSwipe === 'function') initSizeSwipe();
            if (typeof setBrushWidth === 'function' && typeof brushWidth !== 'undefined') setBrushWidth(brushWidth, false);
            if (typeof setBrushColor === 'function' && typeof brushColor !== 'undefined') setBrushColor(brushColor, false);
        }, 0);
        
        return bar;
    },

    createGrammarBar: function() {
        const bar = this.createBarContainer('grammar');

        const ratioBtn = document.createElement('button');
        ratioBtn.className = 'tool-btn';
        ratioBtn.id = 'grammar-ratio-btn';
        ratioBtn.title = 'Label Size Ratio';
        ratioBtn.style.cssText = 'display:flex; align-items:center; gap:4px; min-width:56px; justify-content:center; padding: 6px 10px; font-variant-numeric: tabular-nums; font-weight: 800; font-size: 13px;';
        ratioBtn.innerHTML = `<span style="font-size:9px; opacity:0.6; padding-top:2px;">%</span><span id="grammar-ratio-btn-val">40</span>`;
        ratioBtn.onclick = () => { if(typeof isDraggingGrammarRatio !== 'undefined' && !isDraggingGrammarRatio) toggleGrammarRatioPopover(); };
        bar.appendChild(ratioBtn);

        const sep = document.createElement('div');
        sep.style.cssText = 'width:1px; background:rgba(0,0,0,0.1); height:20px; margin:0 4px; flex-shrink:0;';
        bar.appendChild(sep);

        const templates = [
            { label: null, display: '' },
            { label: 'S', display: 'S' },
            { label: 'V', display: 'V' },
            { label: 'n.', display: 'n.' },
            { label: 'adj.', display: 'adj.' },
            { label: 'V-ing', display: 'V-ing' },
            { label: 'V-pp', display: 'V-pp' },
            { label: 'V-ed', display: 'V-ed' },
            { label: 'V-r', display: 'V-r' }
        ].filter(template => {
            const hidden = window.wb?.settings?.pos_hidden_elements;
            const value = template.label || '';
            return !Array.isArray(hidden) || !hidden.includes(value);
        });
        templates.forEach(t => {
            const btn = document.createElement('button');
            btn.className = 'tool-btn wb-blank-template';
            btn.title = t.label ? `Insert ${t.label} blank` : 'Insert empty blank';
            btn.innerHTML = `<div class="wb-blank-preview-label">${t.display}</div><div class="wb-blank-preview-line"></div>`;
            
            // --- DRAG AND DROP LOGIC ---
            btn.addEventListener('pointerdown', (e) => {
                if (e.pointerType === 'pen') return;
                if (e.pointerType === 'mouse' && e.button !== 0) return;
                btn.setPointerCapture(e.pointerId);
                btn._dragStart = { x: e.clientX, y: e.clientY };
                btn._hasDragged = false;
                
                btn._lpTimer = setTimeout(() => {
                    if (!btn._hasDragged) {
                        btn._dragStart = null; // Prevent drag after long press drop
                        if (window.navigator.vibrate) navigator.vibrate(15);
                        
                        const vp = typeof getActiveViewport !== 'undefined' ? getActiveViewport() : null;
                        if (vp && typeof getCanvasCoords !== 'undefined') {
                            const rect = vp.canvas.getBoundingClientRect();
                            const center = getCanvasCoords({ clientX: rect.left + rect.width/2, clientY: rect.top + rect.height/2 }, vp);
                            
                            const newBlank = {
                                id: typeof wbCreateId !== 'undefined' ? wbCreateId() : Date.now().toString(),
                                zIndex: typeof wbGetNextZIndex !== 'undefined' ? wbGetNextZIndex() : 0,
                                type: 'blank',
                                x: center.x - 75,
                                y: center.y,
                                w: 150,
                                minW: 150,
                                label: t.label,
                                content: '',
                                fontSize: typeof textFontSize !== 'undefined' ? textFontSize : 24,
                                labelRatio: typeof grammarLabelRatio !== 'undefined' ? grammarLabelRatio : 0.4,
                                color: (typeof toolConfigs !== 'undefined' && toolConfigs.text?.color) ? toolConfigs.text.color : (typeof brushColor !== 'undefined' ? brushColor : '#000000')
                            };
                            if (typeof wbPushUndo === 'function') wbPushUndo();
                            if (typeof allStrokes !== 'undefined') {
                                allStrokes.push(newBlank);
                                allStrokes.sort((a, b) => a.zIndex - b.zIndex);
                            }
                            if (typeof render === 'function') render();
                            if (typeof autoSaveEnabled !== 'undefined' && autoSaveEnabled) {
                                if (typeof saveDrawing === 'function') saveDrawing();
                            }
                        }
                    }
                }, 500);

                e.stopPropagation();
            });

            btn.addEventListener('pointermove', (e) => {
                if (!btn._dragStart) return;
                const dist = Math.hypot(e.clientX - btn._dragStart.x, e.clientY - btn._dragStart.y);
                
                if (!window.isDraggingBlank && dist > 15) {
                    btn._hasDragged = true;
                    clearTimeout(btn._lpTimer);
                    window.isDraggingBlank = true;
                    if (window.navigator.vibrate) navigator.vibrate(10);
                    
                    window.activeGhostBlank = {
                        type: 'blank',
                        label: t.label,
                        content: '',
                        w: 150, // Default physical width
                        minW: 150,
                        fontSize: typeof textFontSize !== 'undefined' ? textFontSize : 24,
                        labelRatio: typeof grammarLabelRatio !== 'undefined' ? grammarLabelRatio : 0.4,
                        color: (typeof toolConfigs !== 'undefined' && toolConfigs.text?.color) ? toolConfigs.text.color : (typeof brushColor !== 'undefined' ? brushColor : '#000000')
                    };
                }
                
                if (window.isDraggingBlank && window.activeGhostBlank) {
    const isTouch = e.pointerType === 'touch' || e.pointerType === 'pen';
    const offsetY = isTouch ? 100 : 0;

    // Multi-Viewport Fix: Update active viewport based on ghost position
    const targetVpIdx = window.wbGetViewportIndexAt(e.clientX, e.clientY - offsetY);
    if (targetVpIdx !== -1 && targetVpIdx !== activeViewportIndex) {
        activeViewportIndex = targetVpIdx;
    }

    const vp = typeof getActiveViewport !== 'undefined' ? getActiveViewport() : null;
    if (!vp) return;

    const coords = typeof getCanvasCoords !== 'undefined' ? getCanvasCoords(e, vp) : {x:0, y:0};
    const worldOffsetY = offsetY / vp.transform.scale;let targetX = coords.x - (window.activeGhostBlank.w / 2);
                    let targetY = coords.y - worldOffsetY;
                    
                    // Magnetic Snapping
                    const threshold = 12 / vp.transform.scale;
                    let snapped = false;
                    
                    if (typeof allStrokes !== 'undefined') {
                        for (const s of allStrokes) {
                            if (!['text', 'blank', 'image', 'pdf_page'].includes(s.type)) continue;
                            const b = s._b || (typeof wbCalculateStrokeBounds !== 'undefined' ? wbCalculateStrokeBounds(s) : null);
                            if (!b) continue;
                            
                            // Snap X
                            if (Math.abs(targetX - b.x) < threshold) { targetX = b.x; snapped = true; }
                            else if (Math.abs((targetX + window.activeGhostBlank.w) - (b.x + b.w)) < threshold) { targetX = b.x + b.w - window.activeGhostBlank.w; snapped = true; }
                            
                            // Snap Y (Baseline Alignment)
                            let snapY = b.y;
                            if (s.type === 'blank') snapY = s.y; // Match baseline to baseline
                            else if (s.type === 'text') snapY = s.y + (s.fontSize || 24); // Approximate baseline

                            if (Math.abs(targetY - snapY) < threshold) { targetY = snapY; snapped = true; }
                        }
                    }
                    
                    if (snapped && !btn._snapped) {
                        if (window.navigator.vibrate) navigator.vibrate(5);
                        btn._snapped = true;
                    } else if (!snapped) {
                        btn._snapped = false;
                    }
                    
                    window.activeGhostBlank.x = targetX;
                    window.activeGhostBlank.y = targetY;
                    
                    if (typeof requestRender === 'function') requestRender();
                }
            });

            btn.addEventListener('pointerup', (e) => {
                clearTimeout(btn._lpTimer);
                if (!btn._dragStart) return;
                btn.releasePointerCapture(e.pointerId);
                btn._dragStart = null;
                
                if (window.isDraggingBlank && window.activeGhostBlank) {
    const isTouch = e.pointerType === 'touch' || e.pointerType === 'pen';
    const offsetY = isTouch ? 100 : 0;
    const targetVpIdx = window.wbGetViewportIndexAt(e.clientX, e.clientY - offsetY);
    if (targetVpIdx !== -1) activeViewportIndex = targetVpIdx;

    // Commit to Canvas
    window.activeGhostBlank.id = typeof wbCreateId !== 'undefined' ? wbCreateId() : Date.now().toString();
    window.activeGhostBlank.zIndex = typeof wbGetNextZIndex !== 'undefined' ? wbGetNextZIndex() : 0;
                
    if (typeof wbPushUndo === 'function') wbPushUndo();
    if (typeof allStrokes !== 'undefined') {
        allStrokes.push(window.activeGhostBlank);
        allStrokes.sort((a, b) => a.zIndex - b.zIndex);
    }
                
    window.isDraggingBlank = false;
    window.activeGhostBlank = null;
                
    if (typeof viewports !== 'undefined') {
        viewports.forEach(v => v.octx.clearRect(0, 0, v.overlay.width, v.overlay.height));
    }if (typeof render === 'function') render();
                    if (typeof autoSaveEnabled !== 'undefined' && autoSaveEnabled) {
                        if (typeof saveDrawing === 'function') saveDrawing();
                    }
                } else {
                    // It was just a tap. Spawn it centered.
                    const vp = typeof getActiveViewport !== 'undefined' ? getActiveViewport() : null;
                    if (vp && typeof getCanvasCoords !== 'undefined') {
                        const rect = vp.canvas.getBoundingClientRect();
                        const center = getCanvasCoords({ clientX: rect.left + rect.width/2, clientY: rect.top + rect.height/2 }, vp);
                        
                        const newBlank = {
                            id: typeof wbCreateId !== 'undefined' ? wbCreateId() : Date.now().toString(),
                            zIndex: typeof wbGetNextZIndex !== 'undefined' ? wbGetNextZIndex() : 0,
                            type: 'blank',
                            x: center.x - 75,
                            y: center.y,
                            w: 150,
                            label: t.label,
                            content: '',
                            fontSize: typeof textFontSize !== 'undefined' ? textFontSize : 24,
                            labelRatio: typeof grammarLabelRatio !== 'undefined' ? grammarLabelRatio : 0.4,
                            color: (typeof toolConfigs !== 'undefined' && toolConfigs.text?.color) ? toolConfigs.text.color : (typeof brushColor !== 'undefined' ? brushColor : '#000000')
                        };
                        if (typeof wbPushUndo === 'function') wbPushUndo();
                        if (typeof allStrokes !== 'undefined') {
                            allStrokes.push(newBlank);
                            allStrokes.sort((a, b) => a.zIndex - b.zIndex);
                        }
                        if (typeof render === 'function') render();
                        if (typeof autoSaveEnabled !== 'undefined' && autoSaveEnabled) {
                            if (typeof saveDrawing === 'function') saveDrawing();
                        }
                    }
                }
            });

            btn.addEventListener('pointercancel', (e) => {
                clearTimeout(btn._lpTimer);
                if (!btn._dragStart) return;
                btn.releasePointerCapture(e.pointerId);
                btn._dragStart = null;
                if (window.isDraggingBlank) {
                    window.isDraggingBlank = false;
                    window.activeGhostBlank = null;
                    const vp = typeof getActiveViewport !== 'undefined' ? getActiveViewport() : null;
                    if (vp) vp.octx.clearRect(0, 0, vp.overlay.width, vp.overlay.height);
                    if (typeof requestRender === 'function') requestRender();
                }
            });

            bar.appendChild(btn);
        });

        const posActions = document.createElement('div');
        posActions.className = 'wb-pos-preset-actions';

        const managePresetBtn = document.createElement('button');
        managePresetBtn.className = 'tool-btn wb-pos-manage-btn';
        managePresetBtn.title = 'POS Manager';
        managePresetBtn.innerText = '⚙';
        managePresetBtn.onclick = () => wbOpenPosPresetManager();
        posActions.appendChild(managePresetBtn);

        const posPresetContainer = document.createElement('div');
        posPresetContainer.id = 'wb-pos-presets-container';
        posPresetContainer.className = 'wb-pos-presets-container';
        posActions.appendChild(posPresetContainer);
        bar.appendChild(posActions);

        setTimeout(() => {
            if (typeof initGrammarRatioSwipe === 'function') initGrammarRatioSwipe();
            if (typeof updateGrammarLabelRatio === 'function') updateGrammarLabelRatio(window.grammarLabelRatio, false);
            if (typeof refreshPosPresets === 'function') refreshPosPresets();
        }, 0);

        return bar;
    },
    
    update: function() {
        // Update active states
        this.registry.forEach((config, id) => {
            if (config.id) {
                const el = document.getElementById(config.id);
                if (el) {
                    if (config.isActive) el.classList.toggle('active', config.isActive());
                    if (config.isDisabled) el.disabled = config.isDisabled();
                }
            }
        });

        // Toggle Pen Only active style on Pan button
        const panEl = document.getElementById('tm-pan-btn');
        if (panEl) {
            panEl.classList.toggle('wb-pen-only-active', typeof penOnlyMode !== 'undefined' && penOnlyMode);
        }
        
        // Update Tier 2 visibility
        const stack = document.getElementById('wb-ui-stack');
        if (!stack) return;
        const tier1 = stack.querySelector('.wb-tier1-dock');
        const tier3 = document.getElementById('wb-tier3-dock');
        const mode = typeof touchMode !== 'undefined' ? touchMode : 'draw';

        const bars = [
            { id: 'settings', active: ['draw', 'highlight', 'text'].includes(mode) },
            { id: 'grammar', active: (mode === 'text') }
        ];

        bars.forEach(b => {
            const isDocked = this.dockedTiers && this.dockedTiers.has(b.id);
            if (isDocked) return; // Docked bars are permanently in Tier 1

            const isPinned = this.pinnedTiers.has(b.id);
            const shouldShow = b.active || isPinned;
            
            const oldBar = stack.querySelector(`.wb-bar-${b.id}:not(.wb-tier2-docked)`);
            
            if (shouldShow && !oldBar) {
                const newBar = (b.id === 'settings') ? this.createSettingsBar() : this.createGrammarBar();
                if (tier3) stack.insertBefore(newBar, tier3);
                else stack.appendChild(newBar);
            } else if (!shouldShow && oldBar) {
                oldBar.remove();
            }
        });
    },
    
    init: function() {
        // Load layout from settings if available
        if (window.wb.settings) {
            if (window.wb.settings.toolbar_layout) this.layoutMap = window.wb.settings.toolbar_layout;
            if (window.wb.settings.toolbar_sections) this.sections = window.wb.settings.toolbar_sections;
            if (Array.isArray(window.wb.settings.pinned_tiers)) {
                this.pinnedTiers = new Set(window.wb.settings.pinned_tiers);
            }
            if (Array.isArray(window.wb.settings.docked_tiers)) {
                this.dockedTiers = new Set(window.wb.settings.docked_tiers);
                // Auto-migrate: Ensure docked tiers are in the layout map
                this.dockedTiers.forEach(id => {
                    if (!this.layoutMap.includes(id)) this.layoutMap.push(id);
                });
            }
        }
        
        // Capture Tier 3 elements before they are manipulated
        this.tier3Els = {
            move: document.getElementById('move-controls'),
            text: document.getElementById('text-toolbar'),
            selection: document.getElementById('selection-menu')
        };
        
        this.setupActions();
        this.render();
        window.wb.on('onRenderEnd', () => this.update());
    },
    
    saveLayout: function() {
        if (typeof saveSettings === 'function') {
            saveSettings({ 
                toolbar_layout: this.layoutMap,
                toolbar_sections: this.sections,
                docked_tiers: Array.from(this.dockedTiers),
                pinned_tiers: Array.from(this.pinnedTiers)
            });
        }
    },

    openLayoutManager: function(highlightId = null) {
        // Capture current state to prevent reflow jumps
        const oldList = document.getElementById('wb-layout-list');
        const scrollPos = oldList ? oldList.scrollTop : 0;
        const oldHeight = oldList ? oldList.offsetHeight : 0;

        let html = `
            <div style="margin-bottom: 20px; font-size: 12px; color: var(--text-secondary); text-align: center; line-height: 1.4;">
                Reorder sections (headers) or individual buttons.
            </div>
            <div id="wb-layout-list" style="display: flex; flex-direction: column; gap: 12px; max-height: 50vh; overflow-y: auto; padding: 10px 20px; margin: 0 -10px; scroll-behavior: smooth; min-height: ${oldHeight}px;">
        `;

        this.layoutMap.forEach((section, sIdx) => {
            const isFirstS = sIdx === 0;
            const isLastS = sIdx === this.layoutMap.length - 1;
            const isExpanded = this.expandedSections.has(section);
            
            const isDockedTier = section === 'settings' || section === 'grammar';
            const sectionName = isDockedTier ? `${section} (Docked)` : section;
            const chevronOpacity = isDockedTier ? '0' : '1';
            const pointerEvents = isDockedTier ? 'none' : 'auto';
            
            // Section Header
            const sGlow = (highlightId === 'section-' + section) ? 'wb-item-glow' : '';
            html += `
                <div class="${sGlow}" style="display: flex; align-items: center; gap: 10px; padding: 6px 10px; background: var(--bg-color); border-radius: 12px; border: 1px solid rgba(0,0,0,0.05); cursor: pointer;" onclick="if(!${isDockedTier}) window.wb.ui.toggleSectionCollapse('${section}')">
                    <i data-lucide="chevron-right" style="width: 14px; color: var(--text-secondary); transition: transform 0.2s; transform: rotate(${isExpanded ? '90deg' : '0deg'}); opacity: ${chevronOpacity}; pointer-events: ${pointerEvents};"></i>
                    <div style="flex: 1; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; color: var(--text-primary);">${sectionName}</div>
                    <div style="display: flex; gap: 2px;" onclick="event.stopPropagation()">
                        <button class="tool-btn" onclick="window.wb.ui.moveSection(${sIdx}, -1)" ${isFirstS ? 'disabled' : ''} style="padding: 4px; min-width: auto; background: none; border: none; opacity: ${isFirstS ? 0.2 : 0.6};">
                            <i data-lucide="chevron-up" style="width: 14px;"></i>
                        </button>
                        <button class="tool-btn" onclick="window.wb.ui.moveSection(${sIdx}, 1)" ${isLastS ? 'disabled' : ''} style="padding: 4px; min-width: auto; background: none; border: none; opacity: ${isLastS ? 0.2 : 0.6};">
                            <i data-lucide="chevron-down" style="width: 14px;"></i>
                        </button>
                    </div>
                </div>
            `;

            if (isExpanded && !isDockedTier) {
                // Buttons in Section
                const acts = this.sections[section] || [];
                acts.forEach((id, aIdx) => {
                const config = this.registry.get(id);
                if (!config) return;
                const isFirstA = aIdx === 0;
                const isLastA = aIdx === acts.length - 1;
                
                const aGlow = (highlightId === 'action-' + id) ? 'wb-item-glow' : '';
                html += `
                    <div class="workspace-item ${aGlow}" style="padding: 6px 10px; margin-bottom: 0; margin-left: 12px; border-left: 2px solid var(--primary-accent); border-radius: 0 12px 12px 0; background: transparent;">
                        <div class="workspace-info">
                            <span class="workspace-name" style="font-size: 13px;">${config.title || id}</span>
                        </div>
                        <div style="display: flex; gap: 4px;">
                            <button class="tool-btn" onclick="window.wb.ui.moveAction('${section}', ${aIdx}, -1)" ${isFirstA ? 'disabled' : ''} style="padding: 6px; min-width: auto; background: none; border: none; opacity: ${isFirstA ? 0.1 : 1};">
                                <i data-lucide="chevron-up" style="width: 16px;"></i>
                            </button>
                            <button class="tool-btn" onclick="window.wb.ui.moveAction('${section}', ${aIdx}, 1)" ${isLastA ? 'disabled' : ''} style="padding: 6px; min-width: auto; background: none; border: none; opacity: ${isLastA ? 0.1 : 1};">
                                <i data-lucide="chevron-down" style="width: 16px;"></i>
                            </button>
                        </div>
                    </div>
                `;
                });
            }
        });

        html += `</div>
            <button class="tool-btn" onclick="wbCloseActionSheet()" style="width: 100%; margin-top: 20px; background: var(--primary-accent); color: white; border: none; padding: 12px; font-weight: 800; border-radius: 14px;">Done</button>
        `;

        const sheet = document.getElementById('wb-action-sheet');
        const isAlreadyOpen = sheet.classList.contains('active');
        
        document.getElementById('wb-as-title').innerText = "Customize Toolbar";
        document.getElementById('wb-as-options').innerHTML = html;
        
        // Restore scroll IMMEDIATELY to prevent visual jump
        const newList = document.getElementById('wb-layout-list');
        if (newList) newList.scrollTop = scrollPos;

        sheet.style.display = 'flex';
        
        const finalize = () => {
            sheet.classList.add('active');
            sheet.querySelector('.wb-action-sheet').classList.add('active');
            if (newList) newList.style.minHeight = ''; // Release height lock
            if (window.lucide) lucide.createIcons();
        };

        if (isAlreadyOpen) finalize();
        else setTimeout(finalize, 50);
    },

    moveSection: function(index, direction) {
        const newIndex = index + direction;
        if (newIndex < 0 || newIndex >= this.layoutMap.length) return;

        const sectionId = this.layoutMap[index];
        const temp = this.layoutMap[index];
        this.layoutMap[index] = this.layoutMap[newIndex];
        this.layoutMap[newIndex] = temp;

        this.render();
        this.saveLayout();
        this.openLayoutManager('section-' + sectionId); 
        if (window.navigator.vibrate) navigator.vibrate(10);
    },

    toggleSectionCollapse: function(section) {
        if (this.expandedSections.has(section)) {
            this.expandedSections.delete(section);
        } else {
            this.expandedSections.add(section);
        }
        this.openLayoutManager();
        if (window.navigator.vibrate) navigator.vibrate(5);
    },

    moveAction: function(section, index, direction) {
        const acts = this.sections[section];
        if (!acts) return;
        
        const newIndex = index + direction;
        if (newIndex < 0 || newIndex >= acts.length) return;

        const actionId = acts[index];
        const temp = acts[index];
        acts[index] = acts[newIndex];
        acts[newIndex] = temp;

        this.render();
        this.saveLayout();
        this.openLayoutManager('action-' + actionId);
        if (window.navigator.vibrate) navigator.vibrate(10);
    },
    
    setupActions: function() {
        this.registerAction('gallery', {
            id: 'gallery-btn', section: 'nav', title: 'Back to Gallery',
            cssText: 'background:var(--bg-color); color:var(--primary-accent); border:1px solid rgba(0,0,0,0.05); width:44px; height:44px; padding:0;',
            icon: '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2.5" fill="none"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>',
            onClick: () => { if(typeof wbToggleGallery === 'function') wbToggleGallery(); }
        });

        this.registerAction('pan', {
            id: 'tm-pan-btn', section: 'tools', title: 'Finger Pan',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5" fill="none"><path d="M18 11V6a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v5"></path><path d="M14 10V4a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v10"></path><path d="M10 10.5V6a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v8"></path><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"></path></svg>',
            onClick: () => { if(typeof setTouchMode === 'function') setTouchMode('pan'); },
            isActive: () => typeof touchMode !== 'undefined' && touchMode === 'pan',
            onRender: (btn) => {
                let timer = null;
                let longPressed = false;
                btn.addEventListener('pointerdown', (e) => {
                    if (e.pointerType === 'mouse' && e.button !== 0) return;
                    longPressed = false;
                    timer = setTimeout(() => {
                        longPressed = true;
                        if (typeof window.updatePenOnlyMode === 'function') {
                            const newMode = !penOnlyMode;
                            window.updatePenOnlyMode(newMode);
                            const checkbox = document.getElementById('pen-only-toggle');
                            if (checkbox) checkbox.checked = newMode;
                            const pill = document.getElementById('status-pill');
                            if (pill) {
                                pill.innerText = newMode ? "Pen Only: ON" : "Pen Only: OFF";
                                pill.style.background = newMode ? "var(--primary-accent)" : "var(--text-secondary)";
                                pill.style.opacity = "1";
                                setTimeout(() => { if (pill.innerText.includes("Pen Only")) pill.style.opacity = "0"; }, 1500);
                            }
                            if (window.navigator.vibrate) window.navigator.vibrate(20);
                        }
                    }, 600);
                });
                btn.addEventListener('pointerup', () => { if (timer) { clearTimeout(timer); timer = null; } });
                btn.addEventListener('pointerleave', () => { if (timer) { clearTimeout(timer); timer = null; } });
                btn.addEventListener('click', (e) => {
                    if (longPressed) {
                        e.preventDefault();
                        e.stopPropagation();
                        longPressed = false;
                    }
                }, { capture: true });
            }
        });

        this.registerAction('lasso', {
            id: 'tm-lasso-btn', section: 'tools', title: 'Finger Lasso',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5" fill="none"><path d="M7 22c-1.1 0-2-.9-2-2 0-.3.1-.6.2-.8l1.1-3.4C4.2 14.7 3 12.5 3 10c0-4.4 3.6-8 8-8s8 3.6 8 8-3.6 8-8 8c-1.1 0-2.1-.2-3.1-.6l-1.1 3.4c-.2.7-.9 1.2-1.8 1.2z"></path><path d="M11 14a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path></svg>',
            onClick: () => { if(typeof setTouchMode === 'function') setTouchMode('lasso'); },
            isActive: () => typeof touchMode !== 'undefined' && touchMode === 'lasso'
        });

        this.registerAction('erase', {
            id: 'tm-erase-btn', section: 'tools', title: 'Finger Erase',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.9-9.9c1-1 2.5-1 3.4 0l4.3 4.3c1 1 1 2.5 0 3.4l-9.9 9.9c-1 1-2.5 1-3.4 0z"></path><path d="m22 21H7"></path><path d="m5 11 9 9"></path></svg>',
            onClick: () => { if(typeof setTouchMode === 'function') setTouchMode('erase'); },
            isActive: () => typeof touchMode !== 'undefined' && touchMode === 'erase'
        });

        this.registerAction('draw', {
            id: 'tm-draw-btn', section: 'tools', title: 'Finger Draw',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5" fill="none"><path d="M12 19l7-7 3 3-7 7-3-3z"></path><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path><path d="M2 2l5 5"></path><path d="M11 11l1 1"></path></svg>',
            onClick: () => { if(typeof setTouchMode === 'function') setTouchMode('draw'); },
            isActive: () => typeof touchMode !== 'undefined' && touchMode === 'draw'
        });

        this.registerAction('highlight', {
            id: 'tm-highlight-btn', section: 'tools', title: 'Highlighter',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11-6 6v3h9l3-3"></path><path d="m22 12-4.6 4.6a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8L14 4"></path></svg>',
            onClick: () => { if(typeof setTouchMode === 'function') setTouchMode('highlight'); },
            isActive: () => typeof touchMode !== 'undefined' && touchMode === 'highlight'
        });

        this.registerAction('text', {
            id: 'tm-text-btn', section: 'tools', title: 'Text Tool',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"></polyline><line x1="9" y1="20" x2="15" y2="20"></line><line x1="12" y1="4" x2="12" y2="20"></line></svg>',
            onClick: () => { if(typeof setTouchMode === 'function') setTouchMode('text'); },
            isActive: () => typeof touchMode !== 'undefined' && touchMode === 'text',
            onRender: () => { if (typeof initTextToolDrag === 'function') initTextToolDrag(); }
        });

        this.registerAction('image', {
            id: 'image-import-btn', section: 'media', title: 'Import Image',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>',
            onClick: () => { if(typeof triggerImageImport === 'function') triggerImageImport(); }
        });

        this.registerAction('paste', {
            id: 'paste-btn', section: 'media', title: 'Paste from Clipboard',
            cssText: 'display:none; background:var(--primary-accent); color:white; border:none; font-size:12px; padding:8px 12px;',
            icon: 'Paste',
            onClick: () => { if(typeof paste === 'function') paste(); }
        });

        this.registerAction('save', {
            id: 'save-btn', section: 'system', title: 'Save & Export',
            cssText: 'padding:8px;',
            icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:20px; height:20px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>',
            onClick: () => { if(typeof toggleSaveMenu === 'function') toggleSaveMenu(); }
        });

        this.registerAction('undo', {
            id: 'undo-btn', section: 'system', title: 'Undo',
            cssText: 'padding:8px;',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5" fill="none"><path d="M3 7v6h6"></path><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"></path></svg>',
            onClick: () => { if(typeof undo === 'function') undo(); },
            isDisabled: () => typeof undoStack !== 'undefined' && undoStack.length === 0
        });

        this.registerAction('redo', {
            id: 'redo-btn', section: 'system', title: 'Redo',
            cssText: 'padding:8px;',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5" fill="none"><path d="M21 7v6h-6"></path><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"></path></svg>',
            onClick: () => { if(typeof redo === 'function') redo(); },
            isDisabled: () => typeof redoStack !== 'undefined' && redoStack.length === 0
        });

        this.registerAction('clear', {
            section: 'system', title: 'Clear Canvas', color: '#ff3b30',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>',
            onClick: () => { if(typeof clearCanvas === 'function') clearCanvas(); }
        });

        this.registerAction('split', {
            id: 'split-btn', section: 'system', title: 'Split View',
            cssText: 'width:44px; height:44px; padding:0; touch-action:none;',
            icon: '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2.5" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="12" y1="3" x2="12" y2="21"></line></svg>',
            onClick: () => { if(typeof toggleSplitPopover === 'function') toggleSplitPopover(); },
            onRender: () => { if (typeof initSplitDrag === 'function') initSplitDrag(); }
        });

        this.registerAction('options', {
            id: 'options-btn', section: 'system', title: 'Options',
            cssText: 'width:44px; height:44px; padding:0;',
            icon: '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2.5" fill="none"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>',
            onClick: () => { if(typeof toggleOptionsMenu === 'function') toggleOptionsMenu(); }
        });

        this.registerAction('touch-lab', {
            id: 'wb-touch-lab-btn', section: 'system', title: 'Touch Lab',
            color: '#ff3b30', cssText: 'border-color:rgba(255,59,48,0.2); display:none;',
            icon: '<i data-lucide="fingerprint" style="width:20px;"></i>',
            onClick: () => { if(typeof wbOpenTouchLab === 'function') wbOpenTouchLab(); },
            onRender: (el) => {
                if (typeof updateTouchLabVisibility === 'function') {
                    const isVisible = document.getElementById('touch-lab-toggle')?.checked;
                    el.style.display = isVisible ? 'flex' : 'none';
                }
                if (window.lucide) lucide.createIcons({ root: el });
            }
        });
    }
};

document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => window.wb.ui.init(), 100);
});