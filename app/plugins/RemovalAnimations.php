<?php
// [FILE: plugins/RemovalAnimations.php]
// ==============================================================================
// PLUGIN: Removal Animations (High Performance Edition)
// Purpose: Adds buttery smooth "Slide & Collapse" transitions.
// Optimizations: GPU acceleration, Stacking Context isolation, and Reflow reduction.
// ==============================================================================

$plugin_settings_map['RemovalAnimations'] = '
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Smooth Removal</label>
            <span class="setting-desc">Optimized GPU-accelerated animations for deleting or moving cards.</span>
        </div>
        <div style="color:#34C759; font-weight:600; font-size:12px;">Optimized & Active</div>
    </div>
';

$plugin_js .= <<<'JS'
// --- REMOVAL ANIMATIONS JS ---

(function() {
    // 1. INJECT OPTIMIZED ANIMATION CSS
    const style = document.createElement('style');
    style.innerHTML = `
        /* The "Removing" State - Optimized for 60fps */
        .card.ra-anim-out {
            opacity: 0 !important;
            
            /* Use translate3d to force GPU layer */
            transform: translate3d(-30px, 0, 0) scale(0.95) !important;
            
            /* Collapse height */
            max-height: 0 !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            
            pointer-events: none !important;
            overflow: hidden !important;
            z-index: 0 !important;

            /* Hardware acceleration hints */
            will-change: transform, opacity, max-height;
            backface-visibility: hidden;
            
            /* Snappy cubic-bezier */
            transition: 
                opacity 0.2s ease-out,
                transform 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                margin 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                padding 0.35s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }

        /* Fade content faster than the container collapses to prevent "ghost text" jank */
        .card.ra-anim-out .card-content {
            opacity: 0 !important;
            transition: opacity 0.15s ease-out !important;
        }
        
        /* Fix for Grid View column recalculation jank */
        #entries-container.vg-active .card.ra-anim-out {
            width: 0 !important;
            margin-right: 0 !important;
            transform: translate3d(0, 20px, 0) scale(0.8) !important;
        }
    `;
    document.head.appendChild(style);

    // 2. FETCH INTERCEPTOR (The Orchestrator)
    const originalFetch = window.fetch;

    window.fetch = function(url, options) {
        let isRemovalAction = false;
        let affectedIds = [];

        // Detect Folder Move / Merge / Batch Delete (POST)
        if (options && options.body instanceof FormData) {
            const action = options.body.get('plugin_action') || options.body.get('action');
            const removalTriggers = ['folder_assign', 'delete_merged_originals', 'delete_batch'];
            if (removalTriggers.includes(action)) {
                isRemovalAction = true;
                try {
                    const rawIds = options.body.get('ids') || options.body.get('log_ids') || '[]';
                    affectedIds = JSON.parse(rawIds);
                } catch(e) { affectedIds = []; }
            }
        }

        // Detect Deletion (GET)
        if (typeof url === 'string' && (url.includes('action=delete') || url.includes('plugin_action=folder_delete'))) {
            isRemovalAction = true;
            const params = new URLSearchParams(url.split('?')[1] || '');
            const id = params.get('id');
            if (id) affectedIds.push(id);
        }

        if (!isRemovalAction) return originalFetch.apply(this, arguments);

        // Prepare target cards for animation
        const cardsToAnimate = [];
        if (affectedIds.length > 0) {
            affectedIds.forEach(id => {
                const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
                const card = cb ? cb.closest('.card') : null;
                if (card && !cardsToAnimate.includes(card)) cardsToAnimate.push(card);
            });
        } else {
            document.querySelectorAll('.custom-checkbox.checked').forEach(cb => {
                const card = cb.closest('.card');
                if (card && !cardsToAnimate.includes(card)) cardsToAnimate.push(card);
            });
        }

        return originalFetch.apply(this, arguments).then(async (response) => {
            if (response.ok && cardsToAnimate.length > 0) {
                
                // PHASE 1: Lock current heights to prevent "jumping"
                cardsToAnimate.forEach(card => {
                    const h = card.offsetHeight;
                    card.style.maxHeight = h + 'px';
                });

                // PHASE 2: Trigger animation and Scroll Anchoring
                const scrollContainer = document.getElementById('main-scroll');
                
                // Find the "Anchor": The first TRULY visible card that is NOT being removed
                // We must ignore cards that are already hidden (display:none) or already animating out
                const anchorCard = Array.from(document.querySelectorAll('.card'))
                    .find(c => {
                        const isTarget = cardsToAnimate.includes(c);
                        const isAnimating = c.classList.contains('ra-anim-out');
                        const isHidden = c.style.display === 'none';
                        const rect = c.getBoundingClientRect();
                        return !isTarget && !isAnimating && !isHidden && rect.bottom > 0;
                    });

                let initialAnchorTop = 0;
                if (anchorCard) {
                    initialAnchorTop = anchorCard.getBoundingClientRect().top;
                }

                requestAnimationFrame(() => {
                    cardsToAnimate.forEach(card => {
                        card.classList.add('ra-anim-out');
                    });

                    // Start Scroll Compensation Loop
                    if (anchorCard && scrollContainer) {
                        const startTime = performance.now();
                        const compensate = (now) => {
                            const elapsed = now - startTime;
                            const currentAnchorTop = anchorCard.getBoundingClientRect().top;
                            const diff = currentAnchorTop - initialAnchorTop;

                            // If the anchor moved, adjust scroll to counter-act it
                            if (Math.abs(diff) > 0.1) {
                                scrollContainer.scrollTop += diff;
                            }

                            if (elapsed < 400) { // Run slightly longer than the 350ms CSS transition
                                requestAnimationFrame(compensate);
                            }
                        };
                        requestAnimationFrame(compensate);
                    }
                });

                // PHASE 3: Wait for CSS transition to finish before allowing DOM cleanup
                // 350ms matches our longest transition duration
                await new Promise(resolve => setTimeout(resolve, 360));
            }
            return response;
        });
    };

    // 3. SEARCH FILTER HOOK
    // Makes the "disappearing" of cards during search just as smooth
    window.addEventListener('load', () => {
        if (typeof window.runMasterFilter === 'function') {
            const originalFilter = window.runMasterFilter;
            
            window.runMasterFilter = function() {
                // If we are currently animating a deletion, delay the filter refresh
                // to prevent the list from "snapping" while cards are sliding out.
                const animating = document.querySelectorAll('.ra-anim-out').length > 0;
                if (animating) {
                    setTimeout(() => originalFilter.apply(this, arguments), 400);
                } else {
                    originalFilter.apply(this, arguments);
                }
            };
        }
    });

})();
JS;