<?php
// ==============================================================================
// PLUGIN: Scroll Reveal
// DESCRIPTION: Scroll Entrance Effects.
// Purpose: Adds a 'Falling into Place' animation as cards scroll into view.
// Benefit: Forces GPU re-rendering to prevent ghosting and improves aesthetics.
// ==============================================================================

$plugin_settings_map['ScrollReveal'] = <<<'HTML'
    <div data-sui-setting="Scroll Animations" data-sui-desc="Cards subtly slide and fade into place as you scroll." data-sui-id="sr-toggle" data-sui-onchange="toggleScrollReveal(this.checked)"></div>
HTML;

$plugin_js .= <<<'JS'
// --- SCROLL REVEAL JS (COOLING EDITION) ---

const srEnabled = localStorage.getItem("cjos_sr_enabled") !== "false"; 

window.toggleScrollReveal = function(val) {
    localStorage.setItem("cjos_sr_enabled", val);
    location.reload();
};

(function() {
    if (!srEnabled) return;

    const style = document.createElement('style');
    style.innerHTML = `
        /* Hidden state: slightly shifted and transparent */
        .card.sr-hidden, .sr-item.sr-hidden, .section-header.sr-hidden, #organizer-bar-wrapper.sr-hidden,
        .settings-group.sr-hidden, .plugin-block.sr-hidden, .po-folder.sr-hidden {
            opacity: 0 !important;
            transform: translate3d(0, 15px, 0) !important;
            pointer-events: none !important;
            transition: none !important;
        }

        /* Safety: Ensure action bar buttons are never blocked/hidden by reveal logic */
        .bar-action-btn.sr-hidden, .sb-scroll-container .sr-hidden {
            opacity: 1 !important;
            transform: none !important;
            pointer-events: auto !important;
        }

        /* Re-entry animation: forces GPU repaint */
        .card.sr-animating, .sr-item.sr-animating, .section-header.sr-animating, #organizer-bar-wrapper.sr-animating,
        .settings-group.sr-animating, .plugin-block.sr-animating, .po-folder.sr-animating {
            transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.4s ease;
            will-change: transform, opacity;
            transform: translate3d(0, 0, 0);
            opacity: 1; /* Removed !important to allow semantic overrides */
            pointer-events: auto;
        }

        /* Stable state: zero animation cost. 
           We keep translate3d and will-change to prevent 'white-out' bugs 
           on mobile browsers by keeping the GPU layer active. */
        .card.sr-finished, .sr-item.sr-finished, .section-header.sr-finished, #organizer-bar-wrapper.sr-finished,
        .settings-group.sr-finished, .plugin-block.sr-finished, .po-folder.sr-finished {
            opacity: 1; /* Removed !important: Inline styles (e.g. Inactive AI) now win naturally */
            transform: translate3d(0, 0, 0);
            will-change: transform, opacity;
            pointer-events: auto;
        }
    `;
    document.head.appendChild(style);

    // Map to store observers for different scroll roots (null = viewport)
    const observers = new Map();

    const createObserver = (rootEl = null) => {
        return new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const el = entry.target;
            if (entry.isIntersecting) {
                // EDIT GUARD: If we are reordering the dashboard, skip reveal to prevent glitching
                if (document.body.classList.contains('dash-edit-mode') && el.classList.contains('dash-widget')) {
                    el.classList.remove('sr-hidden');
                    el.classList.add('sr-finished');
                    return;
                }
                // ENTERING VIEW: Force paint with animation
                el.classList.remove('sr-hidden');
                el.classList.add('sr-animating');
                
                // Keep the 'finished' class to stabilize the element after 400ms
                setTimeout(() => {
                    if (el.classList.contains('sr-animating')) {
                        el.classList.remove('sr-animating');
                        el.classList.add('sr-finished');
                    }
                }, 400);
                } else {
                    // EXITING VIEW: Reset state so it re-paints on return
                    el.classList.add('sr-hidden');
                    el.classList.remove('sr-animating');
                    el.classList.remove('sr-finished');
                }
            });
        }, {
            root: rootEl,
            threshold: 0,
            rootMargin: rootEl ? '0px 20px 0px 20px' : '50px 0px 50px 0px'
        });
    };

    // Initialize default viewport observer
    observers.set('viewport', createObserver(null));

    window.srWatch = function(el, root = null) {
        if (!srEnabled || !el) return;
        
        // Ensure we have a valid root ID or fallback to viewport
        const obsKey = root ? (typeof root === 'string' ? root : (root.id || 'custom-root')) : 'viewport';
        
        if (!observers.has(obsKey)) {
            observers.set(obsKey, createObserver(root && typeof root !== 'string' ? root : null));
        }

        if (!el.classList.contains('sr-item')) {
            el.classList.add('sr-hidden');
            el.classList.add('sr-item');
            observers.get(obsKey).observe(el);
        }
    };

    window.srScan = function(container, root = null) {
        if (!srEnabled || !container) return;
        // Global Block Selectors
        const selectors = '.card, .pp-card, .dash-widget, .settings-group, .plugin-block, .po-folder, .todo-list-wrap, .ai-assistant-card,[id^="todo-list-wrap"], .section-header, #organizer-bar-wrapper';
        const targets = container.querySelectorAll(selectors);
        targets.forEach(t => window.srWatch(t, root));
    };

    // --- GLOBAL AUTO-DISCOVERY ---
    function initSrGlobalObserver() {
        if (!srEnabled) return;

        // 1. Initial Scan of the whole body
        window.srScan(document.body);

        // 2. Watch for dynamic injections (LiveSync, AI Hub, Stacks, etc.)
        const bodyObserver = new MutationObserver((mutations) => {
            mutations.forEach(m => {
                m.addedNodes.forEach(node => {
                    if (node.nodeType !== 1) return;
                    
                    // Check if the added node itself is a block
                    if (node.matches('.card, .pp-card, .dash-widget, .po-folder, .section-header, #organizer-bar-wrapper')) {
                        window.srWatch(node);
                    }
                    
                    // Also scan its children for nested blocks
                    window.srScan(node);
                });
            });
        });

        bodyObserver.observe(document.body, { childList: true, subtree: true });
    }

    // 3. INITIALIZE & HANDSHAKE
    window.addEventListener('load', () => {
        const toggle = document.getElementById('sr-toggle');
        if(toggle) toggle.checked = srEnabled;

        if (srEnabled) {
            initSrGlobalObserver();
            
            // Priority Handshake for Standard Cards
            if (window.registerCardPlugin) {
                window.registerCardPlugin((card) => {
                    window.srWatch(card);
                }, 90); 
            }
        }
    });
})();
JS;
?>