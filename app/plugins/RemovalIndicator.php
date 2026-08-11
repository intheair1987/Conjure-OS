<?php
// ==============================================================================
// PLUGIN: Removal Indicator
// Purpose: Instead of cards disappearing when moved, they turn into faded 
// placeholders indicating where they went. Tapping them cleans up the UI.
// ==============================================================================

$ri_config_file = dirname(__DIR__) . '/data/removal-indicator-config.json';

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'ri_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $settings = json_decode($_POST['settings'], true);
        file_put_contents($ri_config_file, json_encode($settings));
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['plugin_action'] === 'ri_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults = ['move' => true, 'delete' => true, 'opacity' => 8, 'blur' => 2];
        $config = file_exists($ri_config_file) ? json_decode(file_get_contents($ri_config_file), true) : $defaults;
        if (!isset($config['opacity'])) $config['opacity'] = 8;
        if (!isset($config['blur'])) $config['blur'] = 2;
        echo json_encode(['status' => 'success', 'config' => $config]);
        exit;
    }
}

$plugin_settings_map['RemovalIndicator'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Placeholder for Moved Cards</label>
            <span class="setting-desc">Show ghost card when moving to folders.</span>
        </div>
        <label class="switch">
            <input type="checkbox" id="ri-toggle-move" onchange="riSaveSettings()">
            <span class="slider"></span>
        </label>
    </div>
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Placeholder for Deleted Cards</label>
            <span class="setting-desc">Show ghost card when deleting entries.</span>
        </div>
        <label class="switch">
            <input type="checkbox" id="ri-toggle-delete" onchange="riSaveSettings()">
            <span class="slider"></span>
        </label>
    </div>
    <div class="setting-item vertical">
        <label class="setting-label">Ghost Content Opacity</label>
        <div class="setting-desc">Control how much of the original card is visible behind the placeholder.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="ri-opacity-slider" min="0" max="100" step="1" oninput="riUpdateOpacityLabel(this.value)" onchange="riSaveSettings()" style="flex:1;">
            <span id="ri-opacity-val" style="font-weight:700; color:var(--primary); min-width:40px;">8%</span>
        </div>
    </div>
    <div class="setting-item vertical">
        <label class="setting-label">Ghost Content Blur</label>
        <div class="setting-desc">Control the blur intensity of the background content.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="ri-blur-slider" min="0" max="20" step="1" oninput="riUpdateBlurLabel(this.value)" onchange="riSaveSettings()" style="flex:1;">
            <span id="ri-blur-val" style="font-weight:700; color:var(--primary); min-width:40px;">2px</span>
        </div>
    </div>
    <div id="ri-save-status" style="text-align:right; font-size:11px; color:#8E8E93; padding:0 16px 8px; height:14px;"></div>

    <div class="setting-item vertical" style="border-top: 1px solid var(--border-color); padding-top: 16px;">
        <label class="setting-label">Live Preview</label>
        <div class="ri-preview-box">
            <div class="ri-preview-label">Ghost Content Preview</div>
            <div class="ri-preview-ghost" style="font-family:serif; font-style:italic; font-size:18px; color:var(--text-primary);">
                Sample Ghost Text
            </div>
            <div class="moved-indicator-overlay" style="width:100%; height:100%; border-radius:0; border:none;">
                <div class="moved-indicator-text" style="margin-bottom:0; font-size:12px;">Placeholder Overlay</div>
            </div>
        </div>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- REMOVAL INDICATOR JS ---

(function() {
    let riSettings = { move: true, delete: true, opacity: 8, blur: 2 };

    // 0. IMMEDIATE INITIALIZATION (Prevents NaN and ensures defaults work before load)
    const initVars = () => {
        const savedOpacity = localStorage.getItem('cjos_ri_cache_opacity') || 8;
        const savedBlur = localStorage.getItem('cjos_ri_cache_blur') || 2;
        document.documentElement.style.setProperty('--ri-ghost-opacity', (savedOpacity / 100));
        document.documentElement.style.setProperty('--ri-ghost-blur', savedBlur + 'px');
    };
    initVars();

    window.addEventListener("load", () => {
        riLoadSettings();
        riHookRenderer();
    });

    async function riLoadSettings() {
        try {
            const fd = new FormData();
            fd.append("plugin_action", "ri_get_config");
            const res = await fetch("index.php", { method: "POST", body: fd });
            const data = await res.json();
            if (data.status === "success") {
                riSettings = data.config;
                if(document.getElementById("ri-toggle-move")) document.getElementById("ri-toggle-move").checked = riSettings.move;
                if(document.getElementById("ri-toggle-delete")) document.getElementById("ri-toggle-delete").checked = riSettings.delete;
                
                const opVal = riSettings.opacity !== undefined ? riSettings.opacity : 8;
                const blVal = riSettings.blur !== undefined ? riSettings.blur : 2;

                if(document.getElementById("ri-opacity-slider")) {
                    document.getElementById("ri-opacity-slider").value = opVal;
                    riUpdateOpacityLabel(opVal);
                }
                if(document.getElementById("ri-blur-slider")) {
                    document.getElementById("ri-blur-slider").value = blVal;
                    riUpdateBlurLabel(blVal);
                }
            }
        } catch(e) {}
    }

    window.riUpdateOpacityLabel = function(val) {
        const num = parseFloat(val) || 8;
        const label = document.getElementById("ri-opacity-val");
        if(label) label.innerText = Math.round(num) + "%";
        document.documentElement.style.setProperty('--ri-ghost-opacity', (num / 100));
        localStorage.setItem('cjos_ri_cache_opacity', num);
    };

    window.riUpdateBlurLabel = function(val) {
        const num = parseFloat(val) || 0;
        const label = document.getElementById("ri-blur-val");
        if(label) label.innerText = num + "px";
        document.documentElement.style.setProperty('--ri-ghost-blur', num + "px");
        localStorage.setItem('cjos_ri_cache_blur', num);
    };

    window.riSaveSettings = async function() {
        const status = document.getElementById("ri-save-status");
        if(status) status.innerText = "Saving...";
        
        riSettings.move = document.getElementById("ri-toggle-move").checked;
        riSettings.delete = document.getElementById("ri-toggle-delete").checked;
        riSettings.opacity = parseInt(document.getElementById("ri-opacity-slider").value);
        riSettings.blur = parseInt(document.getElementById("ri-blur-slider").value);

        const fd = new FormData();
        fd.append("plugin_action", "ri_save_config");
        fd.append("settings", JSON.stringify(riSettings));
        
        await fetch("index.php", { method: "POST", body: fd });
        if(status) {
            status.innerText = "Saved";
            setTimeout(() => status.innerText = "", 2000);
        }
    };

    /**
     * RENDERER HOOK
     * Captures placeholders before the container is cleared and restores them after.
     */
    function riHookRenderer() {
        if (typeof window.renderStandardList !== "function") return;

        const originalRender = window.renderStandardList;
        window.renderStandardList = function(logsData) {
            const container = document.getElementById("entries-container");
            if (!container) return originalRender(logsData);

            // 1. Capture existing placeholders and their context
            const placeholders = Array.from(container.querySelectorAll(".is-moved-placeholder")).map(p => ({
                node: p,
                timestamp: parseInt(p.getAttribute("data-ts")) || 0,
                isPinned: p.classList.contains("is-dogeared")
            }));

            // 2. Run the original renderer (which clears the container)
            originalRender(logsData);

            // 3. Re-inject placeholders with section awareness
            placeholders.forEach(ph => {
                const pinnedHeader = document.getElementById("plugin-dogear-header");
                const children = Array.from(container.children);
                let inserted = false;

                if (ph.isPinned && pinnedHeader) {
                    // A. PLACE IN PINNED SECTION: Find the first card after the Pinned header
                    for (let i = children.indexOf(pinnedHeader) + 1; i < children.length; i++) {
                        const node = children[i];
                        if (node.classList.contains("section-header")) break; // End of pinned section
                        if (node.classList.contains("card")) {
                            const cardId = node.querySelector(".custom-checkbox")?.getAttribute("data-id");
                            const entry = logs.find(l => l.id === cardId);
                            if (entry && entry.timestamp < ph.timestamp) {
                                container.insertBefore(ph.node, node);
                                inserted = true;
                                break;
                            }
                        }
                    }
                    if (!inserted) pinnedHeader.after(ph.node);
                } else {
                    // B. PLACE IN CHRONO STREAM: Find the first normal section header
                    let startIdx = 0;
                    const firstNormalHeader = children.find(c => c.classList.contains("section-header") && c.id !== "plugin-dogear-header");
                    if (firstNormalHeader) startIdx = children.indexOf(firstNormalHeader);

                    for (let i = startIdx; i < children.length; i++) {
                        const node = children[i];
                        if (node.classList.contains("card") && !node.classList.contains("is-dogeared")) {
                            const cardId = node.querySelector(".custom-checkbox")?.getAttribute("data-id");
                            const entry = logs.find(l => l.id === cardId);
                            if (entry && entry.timestamp < ph.timestamp) {
                                container.insertBefore(ph.node, node);
                                inserted = true;
                                break;
                            }
                        }
                    }
                    if (!inserted) container.appendChild(ph.node);
                }
            });
        };
    }

    // 1. INJECT STYLES
    const style = document.createElement('style');
    style.innerHTML = `
        .card.is-moved-placeholder {
            display: block !important; 
            pointer-events: none !important;
            transition: all 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            border: none !important;
            background: transparent !important;
            box-shadow: none !important;
            /* Lower than section-header (90) to ensure it scrolls underneath */
            z-index: 1 !important;
        }

        /* Kill the InteractionTracker blue dashed/dotted outline for placeholders */
        .card.is-moved-placeholder::before, 
        .card.is-moved-placeholder::after {
            display: none !important;
            content: none !important;
        }

        /* Show a ghost-like glimpse of the original content */
        .card.is-moved-placeholder > *:not(.moved-indicator-overlay), 
        .ri-preview-ghost {
            opacity: var(--ri-ghost-opacity, 0.08) !important;
            visibility: visible !important;
            /* Added brightness/contrast boost to ensure visibility in dark themes */
            filter: grayscale(0.5) brightness(1.2) contrast(1.2) blur(var(--ri-ghost-blur, 2px)) !important;
            pointer-events: none !important;
            transition: opacity 0.2s ease, filter 0.2s ease;
        }

        .ri-preview-box {
            margin: 12px 0;
            padding: 16px;
            background: var(--bg-color);
            border-radius: 14px;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ri-preview-label {
            position: absolute;
            top: 6px;
            left: 10px;
            font-size: 9px;
            font-weight: 800;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .moved-indicator-overlay {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            /* Lowered z-index to stay beneath sticky headers (90) */
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 96%;
            height: 90%;
            background: var(--glass-bg);
            /* Link backdrop blur to the dynamic variable to allow 0px setting */
            backdrop-filter: blur(var(--ri-ghost-blur, 10px)); 
            -webkit-backdrop-filter: blur(var(--ri-ghost-blur, 10px));
            border-radius: 22px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            pointer-events: auto !important;
            cursor: pointer;
            text-align: center;
            animation: placeholderFadeIn 0.8s cubic-bezier(0.2, 0, 0.2, 1);
        }

        @keyframes placeholderFadeIn {
            from { opacity: 0; transform: translate(-50%, -48%) scale(0.98); }
            to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
        }

        .moved-indicator-text {
            font-family: "New York", serif;
            font-style: italic;
            font-size: 15px;
            font-weight: 400;
            color: var(--text-secondary);
            margin-bottom: 14px;
            letter-spacing: 0.2px;
        }

        /* Light red hue for deleted entries */
        .moved-indicator-text.is-delete {
            color: var(--danger);
            opacity: 0.9;
        }

        .moved-indicator-subtext {
            font-size: 8.5px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1.8px;
            padding: 6px 16px;
            border-radius: 100px;
            background: var(--btn-bg);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        /* --- THEME ADAPTATIONS --- */
        body.theme-midnight .moved-indicator-overlay {
            background: rgba(0, 0, 0, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }
        body.theme-matrix .moved-indicator-overlay {
            background: #000;
            border: 1px solid var(--primary);
            backdrop-filter: none;
        }
        body.theme-matrix .moved-indicator-text {
            color: var(--primary);
            text-shadow: 0 0 5px var(--primary);
        }
        body.theme-cyber .moved-indicator-overlay {
            background: rgba(5, 5, 5, 0.8);
            border: 1px solid var(--text-secondary);
            box-shadow: 0 0 20px rgba(0, 240, 255, 0.2);
        }
        body.theme-glass .moved-indicator-overlay {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .moved-indicator-overlay:hover .moved-indicator-subtext {
            background: rgba(0, 0, 0, 0.06);
            color: #8E8E93;
        }

        .moved-indicator-overlay:active .moved-indicator-subtext {
            transform: scale(0.97);
            background: rgba(0, 0, 0, 0.1);
        }



        /* The Magic Poof Out - Collapses height so cards below slide up */
        /* High specificity (body .card) used to override ScrollReveal and other plugins */
        body .card.moved-poof-out {
            opacity: 0 !important;
            transform: translate3d(-40px, 0, 0) scale(0.85) !important;
            filter: blur(12px) !important;
            max-height: 0 !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            pointer-events: none !important;
            overflow: hidden !important;
            will-change: transform, opacity, max-height;
            /* Transition !important is required to beat ScrollReveal's !important locks */
            transition: 
                opacity 0.4s ease,
                filter 0.4s ease,
                transform 0.4s cubic-bezier(0.16, 1, 0.3, 1),
                max-height 0.5s cubic-bezier(0.16, 1, 0.3, 1),
                margin 0.5s cubic-bezier(0.16, 1, 0.3, 1),
                padding 0.5s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }

        /* PROTECTION SHIELD: Prevents app.js or DeletionProgress from hiding the card */
        .card.ri-protected {
            display: block !important;
            opacity: 1 !important;
            transform: none !important;
            margin-bottom: 16px !important;
            max-height: none !important;
            pointer-events: auto !important;
        }
    `;
    document.head.appendChild(style);

    // 2. FETCH INTERCEPTOR
    const originalFetch = window.fetch;
    window.fetch = function(url, options) {
        let actionType = null; // 'move' or 'delete'
        let targetFid = null;
        let affectedIds = [];

        // A. Detect Folder Move or Batch Delete (POST)
        if (options && options.body instanceof FormData) {
            const action = options.body.get('plugin_action') || options.body.get('action');
            if (action === 'delete_batch' && riSettings.delete) {
                actionType = 'delete';
                try {
                    affectedIds = JSON.parse(options.body.get('ids') || '[]');
                } catch(e) { affectedIds = []; }
            } else if (action === 'folder_assign' && riSettings.move) {
                const tFid = options.body.get('folder_id');
                const currFid = (typeof currentFolderId !== "undefined") ? currentFolderId : null;
                const isStayingInView = (currFid === null) || (parseInt(tFid) == parseInt(currFid));

                if (!isStayingInView) {
                    actionType = 'move';
                    targetFid = tFid;
                    affectedIds = JSON.parse(options.body.get('log_ids') || '[]');
                }
            }
        }

        // B. Detect Deletion (GET)
        if (!actionType && typeof url === 'string' && url.includes('action=delete') && riSettings.delete) {
            const params = new URLSearchParams(url.split('?')[1]);
            const id = params.get('id');
            if (id) {
                actionType = 'delete';
                affectedIds = [id];
            }
        }

        // If no relevant action or disabled, proceed as normal
        if (!actionType || affectedIds.length === 0) return originalFetch.apply(this, arguments);

        // Capture cards BEFORE they are processed
        const cardsToMark = [];
        affectedIds.forEach(id => {
            const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
            if (cb) {
                const card = cb.closest('.card');
                if (card) {
                    cardsToMark.push(card);
                    // Shield the card immediately so app.js cannot hide/remove it
                    card.classList.add('ri-protected');
                    if (!card._origRemove) {
                        card._origRemove = card.remove;
                        card.remove = function() {
                            if (this.classList.contains('ri-protected') || this.classList.contains('is-moved-placeholder')) return;
                            this._origRemove();
                        };
                    }
                }
            }
        });

        return originalFetch.apply(this, arguments).then(async (response) => {
            if (response.ok) {
                let mainText = "Entry Deleted";
                
                if (actionType === 'move') {
                    let folderName = "Unsorted";
                    if (targetFid != 0 && typeof so_folders !== "undefined") {
                        const folder = so_folders.find(f => f.id == targetFid);
                        if (folder) folderName = folder.name;
                    }
                    mainText = `Moved to ${folderName}`;
                }

                cardsToMark.forEach(card => {
                    if (!card || card.classList.contains('is-moved-placeholder')) return;
                    
                    const id = card.querySelector(".custom-checkbox")?.getAttribute("data-id");
                    const entry = logs.find(l => l.id === id);
                    if (entry) card.setAttribute("data-ts", entry.timestamp);

                    card.classList.add('is-moved-placeholder');
                    const overlay = document.createElement('div');
                    overlay.className = 'moved-indicator-overlay';
                    const textClass = (actionType === 'delete') ? 'moved-indicator-text is-delete' : 'moved-indicator-text';
                    overlay.innerHTML = `
                        <div class="${textClass}">${mainText}</div>
                        <div class="moved-indicator-subtext">Tap to remove all placeholders</div>
                    `;
                    
                    overlay.onclick = (e) => {
                        e.stopPropagation();
                        const allPlaceholders = Array.from(document.querySelectorAll('.is-moved-placeholder'));
                        const ids = allPlaceholders.map(p => p.querySelector(".custom-checkbox")?.getAttribute("data-id")).filter(id => id);
                        const releaseScroll = (typeof window.soLockScroll === "function") ? window.soLockScroll(ids) : () => {};
                        
                        // Trigger animation for ALL placeholders
                        allPlaceholders.forEach(targetCard => {
                            targetCard.style.maxHeight = targetCard.offsetHeight + 'px';
                            requestAnimationFrame(() => {
                                targetCard.classList.add('moved-poof-out');
                                const ghost = targetCard.querySelector('> *:not(.moved-indicator-overlay)');
                                if (ghost) ghost.style.opacity = '0';
                            });

                            setTimeout(() => {
                                targetCard.classList.remove('ri-protected', 'is-moved-placeholder');
                                if (targetCard._origRemove) targetCard._origRemove();
                                else targetCard.remove();
                                
                                // Final cleanup: Notify other plugins (like Stacks) to re-evaluate
                                if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
                            }, 550);
                        });

                        setTimeout(() => {
                            releaseScroll();
                            if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
                        }, 600);

                                


                                // We don't need complex math; simply tracking the difference in height
                                // of the 'above' block and adjusting scroll keeps the view locked.
                                // Note: Browser handles the 'pull up', we handle the 'push down'.
                                

                            




                    };
                    card.appendChild(overlay);
                });
            }
            return response;
        });
    };
})();
JS;
?>