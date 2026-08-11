<?php
// ==============================================================================
// PLUGIN: View Standard
// The default Card Renderer. Handles the main list, audio players, and dates.
// ==============================================================================

$plugin_settings_map['ViewStandard'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-desc">
            This plugin controls the main list view. Disabling it will result in a blank page unless another View plugin is active.
        </div>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- VIEW STANDARD JS ---

// 1. GLOBAL AUDIO STATE
window.currentAudio = null;
window.activeCapsule = null;

window.setCapsulePlaying = function(capsule) { 
    if (window.activeCapsule && window.activeCapsule !== capsule) {
        window.activeCapsule.classList.remove("is-playing"); 
    }
    capsule.classList.add("is-playing"); 
    window.activeCapsule = capsule; 
};

window.setCapsuleStopped = function(capsule) { 
    capsule.classList.remove("is-playing"); 
    if (window.activeCapsule === capsule) window.activeCapsule = null; 
};

// 2. DATE UTILITIES


window.formatAMPM = function(timeStr) {
    if (!timeStr) return "";
    let [h, m] = timeStr.split(":"); 
    h = parseInt(h); 
    const ampm = h >= 12 ? "PM" : "AM"; 
    h = h % 12; h = h ? h : 12; 
    return `${h}:${m} ${ampm}`;
};

window.getRelativeDateLabel = function(dateStr) {
    if (!dateStr) return "Unknown Date";
    const [y, m, d] = dateStr.split("-").map(Number);
    const entryDate = new Date(y, m - 1, d);
    const today = new Date(); today.setHours(0,0,0,0);
    const diffTime = today.getTime() - entryDate.getTime();
    const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
    if (diffDays === 0) return "Today"; 
    if (diffDays === 1) return "Yesterday"; 
    if (diffDays < 7 && diffDays > 0) return `${diffDays} Days Ago`;
    return entryDate.toLocaleDateString("en-US", { weekday: "long", month: "short", day: "numeric" });
};

// 3. CARD FACTORY (Standardized DOM)
window.createStandardCardDOM = function(entry) {
    const card = document.createElement("div"); 
    card.className = "card";
    
    // Checkbox Area
    const checkboxWrapper = document.createElement("div"); 
    checkboxWrapper.className = "checkbox-wrapper";
    const customCheckbox = document.createElement("div"); 
    customCheckbox.className = "custom-checkbox"; 
    customCheckbox.setAttribute("data-id", entry.id); 
    checkboxWrapper.appendChild(customCheckbox);
    
    // Content Area
    const cardContent = document.createElement("div"); 
    cardContent.className = "card-content";
    
    // Header (Time + Player)
    const headerRow = document.createElement("div"); 
    headerRow.className = "header-row";
    const timeSpan = document.createElement("div"); 
    timeSpan.className = "time-badge"; 
    
    const [datePart, timePart] = entry.date_display.split(" ");
    timeSpan.textContent = window.formatAMPM(timePart);
    
    // Player Controls
    const controlsDiv = document.createElement("div"); 
    controlsDiv.className = "player-capsule";
    const stopBtn = document.createElement("button"); 
    stopBtn.className = "player-btn btn-stop"; 
    stopBtn.innerHTML = `<svg viewBox="0 0 24 24"><rect x="7" y="7" width="10" height="10" rx="2"></rect></svg>`;
    const divider = document.createElement("div"); 
    divider.className = "player-divider";
    const playBtn = document.createElement("button"); 
    playBtn.className = "player-btn btn-play"; 
    playBtn.innerHTML = `<svg viewBox="0 0 24 24"><polygon points="6 4 19 12 6 20 6 4"></polygon></svg>`;
    
    const audioEl = document.createElement("audio"); 
    if(entry.audio_path === "text_only") {
        controlsDiv.style.display = "none";
    } else {
        audioEl.src = entry.audio_path; 
        audioEl.preload = "none"; 
        audioEl.style.display = "none"; 
    }
    audioEl.onended = () => { 
        window.setCapsuleStopped(controlsDiv); 
        if (window.currentAudio === audioEl) window.currentAudio = null; 
    };

    controlsDiv.appendChild(stopBtn); 
    controlsDiv.appendChild(divider); 
    controlsDiv.appendChild(playBtn);
    headerRow.appendChild(timeSpan); 
    headerRow.appendChild(controlsDiv);
    
    // Text Transcription
    const textDiv = document.createElement("div"); 
    textDiv.className = "transcription truncated"; 
    textDiv.textContent = entry.transcription;
    
    cardContent.appendChild(headerRow); 
    cardContent.appendChild(textDiv); 
    cardContent.appendChild(audioEl);
    
    card.appendChild(checkboxWrapper); 
    card.appendChild(cardContent);
    
    // Interaction logic migrated to InteractionManager.php
    


    return card;
};

// 4. MAIN RENDER LOOP
window.renderStandardList = function(logsData) {
    window._cjosIsRendering = true;
    const container = document.getElementById("entries-container");
    if (!container || !logsData) return;
    
    const scroller = document.getElementById("main-scroll");
    if(scroller && !window._cjosSkipScrollReset) scroller.scrollTop = 0;
    
    const fragment = document.createDocumentFragment();
    
    // PRESERVATION: If Stacks exist, move them into the fragment first 
    // so they are swapped back in at the exact same moment as the cards.
    const stacks = document.getElementById('stacks-section-wrapper');
    if (stacks) fragment.appendChild(stacks);

    let lastDate = null;
    
    // Create DOM
    logsData.forEach(entry => {
        const [datePart, timePart] = entry.date_display.split(" ");
        if (datePart !== lastDate) { 
            const header = document.createElement("div"); 
            header.className = "section-header"; 
            header.textContent = window.getRelativeDateLabel(datePart); 
            fragment.appendChild(header); 
            if (window.srWatch) window.srWatch(header);
            lastDate = datePart; 
        }
        
        const card = window.createStandardCardDOM(entry);
        fragment.appendChild(card);
        
        // Single-Card Hooks (WordCounter, ManualEditor, etc.)
        if(window.cjosPluginRegistry) {
            window.cjosPluginRegistry.forEach(p => { try { p.fn(card, entry); } catch(e) {} });
        }
    });

    container.replaceChildren(fragment);
    window._cjosIsRendering = false;
    
    // --- BATCH POST-PROCESS HANDSHAKE ---
    if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
};

// 5. LIST MAINTENANCE
window.cjosPruneEmptyHeaders = function() {
    const container = document.getElementById('entries-container');
    if (!container) return;
    
    // Scan all section headers (including the DogEar pinned header)
    const headers = container.querySelectorAll('.section-header');
    headers.forEach(header => {
        let hasVisibleContent = false;
        let sibling = header.nextElementSibling;
        
        // Look ahead until the next header or the end of the list
        while (sibling && !sibling.classList.contains('section-header') && sibling.id !== 'vs-sentinel') {
            // If we find a card that is NOT hidden by folders, stacks, or removal animations
            if (sibling.classList.contains('card') && 
                sibling.style.display !== 'none' && 
                !sibling.classList.contains('is-stacked-hidden') &&
                !sibling.classList.contains('ra-anim-out')) {
                hasVisibleContent = true;
                break;
            }
            sibling = sibling.nextElementSibling;
        }
        
        // If no visible cards were found before the next header, remove this one
        if (!hasVisibleContent) {
            header.remove();
        }
    });
};

// Register as a core refresh hook so it runs in the centralized pipeline
if (window.registerRefreshHook) {
    window.registerRefreshHook(window.cjosPruneEmptyHeaders);
}

// 6. AUTO-RUN
// Render call removed. SmartOrganizer now handles the initial view-aware render.
JS;

$plugin_js .= <<<'JS'
// --- VIEW STANDARD INTERACTION MANAGER ADAPTER ---
(function() {
    window.addEventListener('load', () => {
        if (!window.InteractionManager) return;

        // 1. Handle Play Action
        InteractionManager.subscribe({
            plugin: 'ViewStandard',
            event: 'onPlayTap',
            priority: 50,
            handler: ({ card, vibrate }) => {
                const audioEl = card.querySelector('audio');
                const controls = card.querySelector('.player-capsule');
                if (!audioEl || !controls) return;

                if (window.currentAudio && window.currentAudio !== audioEl) {
                    window.currentAudio.pause();
                    window.currentAudio.currentTime = 0;
                }
                window.setCapsulePlaying(controls);
                window.currentAudio = audioEl;
                audioEl.play();
                vibrate('light');
            }
        });

        // 2. Handle Stop Action
        InteractionManager.subscribe({
            plugin: 'ViewStandard',
            event: 'onStopTap',
            priority: 50,
            handler: ({ card, vibrate }) => {
                const audioEl = card.querySelector('audio');
                const controls = card.querySelector('.player-capsule');
                if (!audioEl || !controls) return;

                window.setCapsuleStopped(controls);
                audioEl.pause();
                audioEl.currentTime = 0;
                if (window.currentAudio === audioEl) window.currentAudio = null;
                vibrate('light');
            }
        });
    });
})();
JS;
?>