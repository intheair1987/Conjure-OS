<?php
// ==============================================================================
// PLUGIN: Double Tap To Copy
// DESCRIPTION: Double-Tap Shortcuts.
// Purpose: Adds a mandatory bounce animation to all card taps and handles
// double-tap to copy transcription and triple-tap to reset interaction.
// Priority: 80 (Visual/Interaction)
// ==============================================================================

$plugin_settings_map['DoubleTapToCopy'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Interaction Feedback</label>
            <span class="setting-desc">Enables the tap-bounce effect and double-tap to copy transcription.</span>
        </div>
        <div style="color:#34C759; font-weight:600; font-size:12px;">Active</div>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- DOUBLE TAP TO COPY (SUBSCRIBER EDITION) ---

(function() {
    window.addEventListener('load', () => {
        if (!window.InteractionManager) return;

        // 1. Handle Copy Action
        InteractionManager.subscribe({
            plugin: 'DoubleTapToCopy',
            event: 'onDoubleTap',
            priority: 50,
            handler: ({ card, entry, vibrate }) => {
                if (!entry) return;
                if (window.copyToClipboard) {
                    window.copyToClipboard(entry.transcription, card);
                    vibrate('light');
                }
            }
        });

        // 2. Handle Interaction Revert (Triple-Tap Reset)
        InteractionManager.subscribe({
            plugin: 'DoubleTapToCopy',
            event: 'onInteractionReset',
            priority: 50,
            handler: ({ card, vibrate }) => {
                if (window.unmarkAsInteracted) {
                    window.unmarkAsInteracted(card);
                    vibrate('medium');
                }
            }
        });
    });
})();
JS;
?>