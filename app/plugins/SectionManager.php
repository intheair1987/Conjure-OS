<?php
// ==============================================================================
// PLUGIN: Section Manager
// DESCRIPTION: Collapsible Note Groups.
// Transforms [SECTION_START] and [SECTION_END] marker notes into interactive 
// UI headers. Requires the pair to exist for the section to activate.
// ==============================================================================

// --- SETTINGS UI ---
$plugin_settings_map['SectionManager'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">AI Sectioning</label>
            <span class="setting-desc">Enables the pairing logic that turns marker notes into collapsible headers.</span>
        </div>
        <div style="color:#34C759; font-weight:600; font-size:12px;">Active</div>
    </div>
    <div class="setting-item">
        <button onclick="smResetStates()" class="text-btn" style="width:100%; color:var(--primary);">Expand All Sections</button>
    </div>
HTML;

// --- JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- SECTION MANAGER ENGINE ---

window.smSectionMap = []; // Stores {startId, endId, title, idsInside}
window.smCollapsedStates = JSON.parse(localStorage.getItem('cjos_sm_collapsed') || '{}');

window.addEventListener("load", () => {
    // 1. Inject Styles
    const style = document.createElement("style");
    style.innerHTML = `
        .section-marker-header {
            background: var(--primary) !important;
            color: var(--primary-text) !important;
            border-radius: 14px !important;
            margin-bottom: 8px !important;
            cursor: pointer;
            border: none !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1) !important;
        }
        .section-marker-header .card-content {
            background: transparent !important;
            padding: 14px 20px !important;
            flex-direction: row !important;
            align-items: center !important;
            justify-content: space-between !important;
        }
        /* High-specificity selector to beat theme overrides */
        .card.section-marker-header .transcription {
            font-weight: 800;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--primary-text);
            opacity: 1;
            visibility: visible;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            flex: 1;
            margin: 0;
            padding: 0;
            line-height: 1.2;
            -webkit-line-clamp: unset;
        }
        .section-marker-header .time-badge, 
        .section-marker-header .player-capsule,
        .section-marker-header .manual-edit-btn,
        .section-marker-header .card-meta-row,
        .section-marker-header .read-more-wrapper {
            display: none !important;
        }
        .section-toggle-icon {
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            font-size: 14px;
            font-weight: 900;
            color: var(--primary-text) !important;
            opacity: 0.8;
            margin-left: 12px;
            flex-shrink: 0;
        }
        .section-collapsed .section-toggle-icon { transform: rotate(-90deg); }
        
        /* The End Marker is always hidden if paired */
        .card.section-marker-end-hidden { display: none !important; }
        .card.is-section-hidden { display: none !important; }

        /* SECTION MEMBER STYLING */
        .card.is-section-member {
            border-left: 4px solid var(--primary) !important;
            border-top-left-radius: 4px !important;
            border-bottom-left-radius: 4px !important;
            margin-left: 10px !important;
            width: calc(100% - 10px) !important;
            transition: all 0.3s ease;
        }
        /* Subtle indentation for member content */
        .card.is-section-member .card-content {
            padding-left: 15px !important;
        }
    `;
    document.head.appendChild(style);

    // 2. Register Handshaking
    if (window.registerCardPlugin) {
        window.registerCardPlugin(smDecorateCard, 12); 
    }

    if (window.registerRefreshHook) {
        window.registerRefreshHook(smProcessPairing);
    }
});

// --- CORE LOGIC: PAIRING PASS ---
window.smProcessPairing = function() {
    if (typeof logs === 'undefined') return;
    
    window.smSectionMap = [];
    const startRegex = /^\[SECTION_START:\s*(.*?)\]/i;
    const endRegex = /^\[SECTION_END\]/i;

    // logs is DESC (Newest at index 0). 
    // Chronologically: [END] (Newer) comes BEFORE [START] (Older) in the array.
    for (let i = 0; i < logs.length; i++) {
        const log = logs[i];
        const text = (log.transcription || "").trim();
        
        // 1. Look for the END marker (the boundary closest to the top/now)
        if (text.match(endRegex)) {
            let idsInside = [];
            let foundStart = false;
            let startId = null;
            let startTitle = "Untitled Section";
            let startIdx = -1;

            // 2. Look "Down" (Older notes) for the START marker
            for (let j = i + 1; j < logs.length; j++) {
                const subLog = logs[j];
                const subText = (subLog.transcription || "").trim();
                const startMatch = subText.match(startRegex);
                
                if (startMatch) {
                    foundStart = true;
                    startId = subLog.id;
                    startTitle = startMatch[1] || "Untitled Section";
                    startIdx = j;
                    break; 
                }
                // Collect everything in between
                idsInside.push(subLog.id);
            }

            if (foundStart) {
                window.smSectionMap.push({
                    startId: startId,
                    endId: log.id,
                    title: startTitle,
                    idsInside: idsInside
                });
                i = startIdx; // Jump index to the Start marker to continue scanning
            }
        }
    }
    smApplyVisibility();
};

function smApplyVisibility() {
    // 1. Reset all section-specific visibility first
    document.querySelectorAll('.is-section-hidden, .section-marker-end-hidden, .is-section-member').forEach(el => {
        el.classList.remove('is-section-hidden', 'section-marker-end-hidden', 'is-section-member');
    });

    const isSearching = (typeof searchQuery !== 'undefined' && searchQuery !== "");
    
    window.smSectionMap.forEach(section => {
        // Default to COLLAPSED (true) if state is undefined. Keyed by endId now.
        const isCollapsed = (window.smCollapsedStates[section.endId] !== false) && !isSearching;
        
        section.idsInside.forEach(id => {
            const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
            if (cb) {
                const card = cb.closest('.card');
                if (isCollapsed) {
                    card.classList.add('is-section-hidden');
                } else {
                    card.classList.add('is-section-member');
                }
            }
        });

        // Hide the Start Marker (the older boundary)
        const startCb = document.querySelector(`.custom-checkbox[data-id="${section.startId}"]`);
        if (startCb && !isSearching) {
            startCb.closest('.card').classList.add('section-marker-end-hidden');
        }
    });
}

function smDecorateCard(card) {
    const id = card.querySelector(".custom-checkbox")?.getAttribute("data-id");
    if (!id) return;
    
    if (window.smSectionMap.length === 0) smProcessPairing();

    // The End Marker is now the interactive Header
    const section = window.smSectionMap.find(s => s.endId === id);
    const isStartMarker = window.smSectionMap.find(s => s.startId === id);
    const isInside = window.smSectionMap.find(s => s.idsInside.includes(id));

    const isSearching = (typeof searchQuery !== 'undefined' && searchQuery !== "");
    
    // Immediate Visibility Handling
    if (!isSearching) {
        if (isStartMarker) {
            card.classList.add('section-marker-end-hidden');
        } else if (isInside) {
            const parent = window.smSectionMap.find(s => s.idsInside.includes(id));
            const isCollapsed = (window.smCollapsedStates[parent.endId] !== false);
            if (isCollapsed) card.classList.add('is-section-hidden');
            else card.classList.add('is-section-member');
        }
    }
    
    if (section) {
        card.classList.add('section-marker-header');
        // Default to COLLAPSED (true)
        const isCollapsed = (window.smCollapsedStates[id] !== false);
        if (isCollapsed) card.classList.add('section-collapsed');
        else card.classList.remove('section-collapsed');

        const content = card.querySelector('.card-content');
        const textDiv = card.querySelector('.transcription');
        
        // Ensure title is applied and survives scroller re-renders
        if (textDiv) {
            textDiv.innerText = section.title || "Untitled Section";
            textDiv.classList.remove('truncated'); // Headers don't need truncation
        }
        
        // Add Toggle Icon if not present
        if (!card.querySelector('.section-toggle-icon')) {
            const icon = document.createElement('div');
            icon.className = 'section-toggle-icon';
            icon.innerHTML = '▼';
            content.appendChild(icon);
        }

        card.onclick = (e) => {
            e.stopPropagation();
            smToggleSection(id);
        };
    }
}

window.smToggleSection = function(endId) {
    const cb = document.querySelector(`.custom-checkbox[data-id="${endId}"]`);
    const card = cb ? cb.closest('.card') : null;
    if (!card) return;

    // 1. CAPTURE ANCHOR POSITION (Relative to viewport)
    const initialTop = card.getBoundingClientRect().top;

    // 2. TOGGLE STATE
    window.smCollapsedStates[endId] = !window.smCollapsedStates[endId];
    localStorage.setItem('cjos_sm_collapsed', JSON.stringify(window.smCollapsedStates));
    
    if (navigator.vibrate) navigator.vibrate(10);

    // 3. REFRESH VIEW (Triggers DOM Mutation)
    if (typeof window.refreshFolderView === 'function') {
        window.refreshFolderView();
    }

    // 4. APPLY SCROLL ANCHORING
    // We wait for the next animation frame to ensure the DOM has shifted
    requestAnimationFrame(() => {
        const newCb = document.querySelector(`.custom-checkbox[data-id="${startId}"]`);
        const newCard = newCb ? newCb.closest('.card') : null;
        if (newCard) {
            const newTop = newCard.getBoundingClientRect().top;
            const diff = newTop - initialTop;
            const scrollCont = document.getElementById("main-scroll");
            
            // Adjust scroll position to cancel out the layout shift
            if (scrollCont && Math.abs(diff) > 0.5) {
                scrollCont.scrollTop += diff;
            }
        }
    });
};

window.smResetStates = function() {
    window.smCollapsedStates = {};
    localStorage.removeItem('cjos_sm_collapsed');
    if (typeof window.refreshFolderView === 'function') window.refreshFolderView();
};
JS;
?>