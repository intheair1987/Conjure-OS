<?php
// ==============================================================================
// PLUGIN: Setting Layout Fixer
// DESCRIPTION: Auto-Resize Settings.
// Automatically regulates the height of plugin settings to prevent clipping 
// or overlapping. Ensures that all settings content is fully visible.
// ==============================================================================

$plugin_js .= <<<'JS'
(function() {
    /**
     * Setting Layout Fixer Logic:
     * 1. Injects CSS to override the static 500px limit.
     * 2. Uses a ResizeObserver to detect content size changes (even nested ones).
     * 3. Dynamically calculates and applies the correct height for smooth transitions.
     */

    const fixerStyle = document.createElement('style');
    fixerStyle.innerHTML = `
        /* Overrule the static 500px limit from main CSS */
        .plugin-tray.open {
            /* We use a high variable to ensure it never clips */
            max-height: var(--dynamic-tray-height, 2000px) !important;
            transition: max-height 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease !important;
            overflow: visible !important; /* Let the main settings sheet handle scrolling */
        }

        /* Ensure the outer block expands to fit the tray */
        .plugin-block {
            display: flex !important;
            flex-direction: column !important;
            height: auto !important;
            position: relative;
        }

        /* Z-index rule removed to prevent repaint flashing on overlay trigger */
        
        .plugin-tray {
            position: relative;
            z-index: 1;
        }
    `;
    document.head.appendChild(fixerStyle);

    function refreshTrayHeight(tray) {
        if (!tray) return;
        
        let targetHeight = '0px';
        if (tray.classList.contains('open')) {
            // Calculate actual height needed
            const realHeight = tray.scrollHeight;
            targetHeight = (realHeight + 60) + 'px';
        }

        // GUARD: Only mutate CSS variable if the height value actually changed
        const currentHeight = tray.style.getPropertyValue('--dynamic-tray-height');
        if (currentHeight !== targetHeight) {
            tray.style.setProperty('--dynamic-tray-height', targetHeight);
        }
    }

    function initFixer() {
        const trays = document.querySelectorAll('.plugin-tray');
        
        trays.forEach(tray => {
            // 1. Monitor Class Changes (when user clicks the toggle arrow)
            const classObserver = new MutationObserver((mutations) => {
                mutations.forEach(m => {
                    if (m.attributeName === 'class') {
                        requestAnimationFrame(() => refreshTrayHeight(tray));
                    }
                });
            });
            classObserver.observe(tray, { attributes: true, attributeFilter: ['class'] });

            // 2. Monitor Content Changes (useful for Plugin Organizer folders)
            const resizeObserver = new ResizeObserver(() => {
                if (tray.classList.contains('open')) {
                    requestAnimationFrame(() => refreshTrayHeight(tray));
                }
            });
            resizeObserver.observe(tray);

            // Initial check
            refreshTrayHeight(tray);
        });
    }

    // Initialize on load
    window.addEventListener('load', () => {
        // Small delay to ensure all plugins have finished their initial DOM rendering
        setTimeout(initFixer, 600);
    });

    // Global interaction hook
    document.addEventListener('click', (e) => {
        // Re-calculate if the user interacts with elements that might change layout
        if (e.target.closest('.plugin-toggle-btn') || e.target.closest('.po-folder-header')) {
            setTimeout(() => {
                document.querySelectorAll('.plugin-tray.open').forEach(refreshTrayHeight);
            }, 150);
        }
    });

})();
JS;
?>