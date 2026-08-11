<?php
// ==============================================================================
// PLUGIN: Select All
// DESCRIPTION: Bulk Selection Toggle.
// Adds a button to the selection bar to toggle all visible entries.
// Fully compatible with Sequential Copy and Smart Organizer.
// ==============================================================================

$plugin_settings_map['SelectAll'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Select All Button</label>
            <span class="setting-desc">Adds a toggle button to the bottom bar to select or deselect all visible items at once.</span>
        </div>
        <div style="color:var(--primary); font-weight:600; font-size:12px;">Active</div>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- SELECT ALL PLUGIN JS ---

(function() {
    window.addEventListener("load", () => {
        // 1. Inject Button into Bottom Bar
        // We use a timeout to ensure ScrollableActionBar has initialized
        setTimeout(injectSelectAllBtn, 300);
    });

    function injectSelectAllBtn() {
        const bar = document.querySelector(".selection-bottom-bar");
        const scrollCont = document.querySelector(".sb-scroll-container");
        const target = scrollCont || bar;

        if (target && !document.getElementById("action-select-all")) {
            const btn = document.createElement("button");
            btn.className = "bar-action-btn";
            btn.id = "action-select-all";
            btn.title = "Select / Deselect All";
            
            // Icon: Double Checkmark
            btn.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="7 13 10 16 16 8"></polyline>
                    <polyline points="12 16 15 19 21 11"></polyline>
                    <path d="M2 11l3 3 3-3"></path>
                </svg>`;
            
            btn.onclick = toggleSelectAll;
            target.appendChild(btn);
        }
    }

    window.toggleSelectAll = function() {
        // 1. Identify currently visible cards (respecting SmartOrganizer filters)
        const visibleCards = Array.from(document.querySelectorAll(".card")).filter(card => {
            return card.style.display !== "none" && !card.classList.contains("search-hidden");
        });

        if (visibleCards.length === 0) return;

        // 2. Check if we are selecting or deselecting
        // If any visible item is unchecked, we "Select All". 
        // If everything visible is already checked, we "Deselect All".
        const allChecked = visibleCards.every(card => {
            const cb = card.querySelector(".custom-checkbox");
            return cb && cb.classList.contains("checked");
        });

        const shouldCheck = !allChecked;

        // 3. Update DOM and Data
        visibleCards.forEach(card => {
            const cb = card.querySelector(".custom-checkbox");
            const id = cb.getAttribute("data-id");

            if (shouldCheck) {
                if (!cb.classList.contains("checked")) {
                    cb.classList.add("checked");
                    // Sync with SequentialCopy
                    if (typeof selectionSequence !== "undefined" && !selectionSequence.includes(id)) {
                        selectionSequence.push(id);
                    }
                }
            } else {
                if (cb.classList.contains("checked")) {
                    cb.classList.remove("checked");
                    // Sync with SequentialCopy
                    if (typeof selectionSequence !== "undefined") {
                        selectionSequence = selectionSequence.filter(sid => sid !== id);
                    }
                }
            }
        });

        // 4. Update UI Components
        if (typeof updateSelectionCount === "function") updateSelectionCount();
        if (typeof renderSequenceBadges === "function") renderSequenceBadges();

        // 5. Haptic Feedback
        window.sui.haptic(shouldCheck ? 'medium' : 'success');
    };
})();
JS;
?>