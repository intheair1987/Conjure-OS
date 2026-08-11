<?php
// ==============================================================================
// PLUGIN: Read More
// DESCRIPTION: Auto-Truncate Long Text.
// Purpose: Automatically truncates long transcriptions and adds an expand button.
// Priority: 50 (Content Decoration)
// ==============================================================================

$plugin_settings_map['ReadMore'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Auto-Truncate</label>
            <span class="setting-desc">Automatically hide long text behind a "Read More" button.</span>
        </div>
        <div style="color:#34C759; font-weight:600; font-size:12px;">Active</div>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- READ MORE PLUGIN JS ---

(function() {
    // 1. INJECT STYLES
    const style = document.createElement('style');
    style.innerHTML = `
        .transcription.truncated {
            display: -webkit-box;
            -webkit-line-clamp: 8;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .read-more-wrapper {
            display: block;
            width: 100%;
            pointer-events: none;
            margin-top: 4px;
        }
        .read-more-btn {
            display: inline-block;
            color: var(--primary);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            pointer-events: auto;
            padding: 4px 12px 4px 0;
        }
    `;
    document.head.appendChild(style);

    // 2. CORE LOGIC
    window.setupReadMore = function(card) {
        const textDiv = card.querySelector('.transcription');
        if (!textDiv || card.querySelector('.read-more-wrapper')) return;

        // Create Button elements
        const wrapper = document.createElement('div');
        wrapper.className = 'read-more-wrapper';
        
        const btn = document.createElement('span');
        btn.className = 'read-more-btn';
        btn.textContent = 'Read more';
        btn.style.display = 'none';

        btn.onclick = (e) => {
            e.stopPropagation();
            if (textDiv.classList.contains('truncated')) {
                textDiv.classList.remove('truncated');
                btn.textContent = 'Show less';
            } else {
                textDiv.classList.add('truncated');
                btn.textContent = 'Read more';
            }
        };

        wrapper.appendChild(btn);
        textDiv.after(wrapper);

        // Check for overflow
        const checkOverflow = () => {
            // Force truncation class to measure correctly
            textDiv.classList.add('truncated');
            const isOverflowing = textDiv.scrollHeight > textDiv.clientHeight;
            btn.style.display = isOverflowing ? 'inline-block' : 'none';
        };

        // Initial check
        setTimeout(checkOverflow, 50);

        // Observe text changes (for AI transforms or edits)
        const observer = new MutationObserver(checkOverflow);
        observer.observe(textDiv, { childList: true, characterData: true, subtree: true });
    };

    // 3. GLOBAL HELPER (Maintains compatibility with other plugins)
    window.refreshReadMoreButtons = function() {
        document.querySelectorAll('.card').forEach(card => {
            const btn = card.querySelector('.read-more-btn');
            const textDiv = card.querySelector('.transcription');
            if (btn && textDiv) {
                const isOverflowing = textDiv.scrollHeight > textDiv.clientHeight;
                btn.style.display = isOverflowing ? 'inline-block' : 'none';
            }
        });
    };

    // 4. REGISTER PLUGIN
    if (window.registerCardPlugin) {
        window.registerCardPlugin(window.setupReadMore, 50);
    }
})();
JS;
?>