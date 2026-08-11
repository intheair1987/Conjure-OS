<?php
// ==============================================================================
// PLUGIN: Deletion Progress
// DESCRIPTION: Batch Deletion Tracker.
// Purpose: Aesthetic batch deletion tracker using the global Progress Pill API.
// ==============================================================================

$plugin_settings_map['DeletionProgress'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Batch Deletion UI</label>
            <span class="setting-desc">Uses the Progress Pill to track multiple deletions.</span>
        </div>
        <div style="color:#34C759; font-weight:600; font-size:12px;">Active</div>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- DELETION PROGRESS JS ---

(function() {
    window.addEventListener('load', () => {
        // Hijack the delete button
        setTimeout(injectAestheticDelete, 1000);
    });

    function injectAestheticDelete() {
        const btnDelete = document.getElementById('action-delete');
        if (!btnDelete || !window.cjosProgressPill) return;

        const newBtn = btnDelete.cloneNode(true);
        btnDelete.parentNode.replaceChild(newBtn, btnDelete);

        newBtn.onclick = async () => {
            const items = typeof getSelectedItems === 'function' ? getSelectedItems() : [];
            if (items.length === 0) return;

            const confirmMsg = items.length === 1 
                ? "Delete this entry?" 
                : `Permanently delete ${items.length} entries?`;
            
            window.openConfirm("Delete Items", confirmMsg, async () => {
                if (typeof window.lsIsProcessing !== 'undefined') window.lsIsProcessing = true;
                const releaseScroll = (typeof window.soLockScroll === "function") 
                    ? window.soLockScroll(items.map(i => i.id)) 
                    : () => {};

            // 1. Show Pill
            window.cjosProgressPill.show(`Deleting 1 of ${items.length}`);

            let deletedCount = 0;
            const total = items.length;

            // 2. Optimistic DOM Removal & Local Log Splice (0ms visual latency)
            items.forEach(item => {
                const logIdx = typeof logs !== 'undefined' ? logs.findIndex(l => l.id === item.id) : -1;
                if (logIdx !== -1) logs.splice(logIdx, 1);

                const cb = document.querySelector(`.custom-checkbox[data-id="${item.id}"]`);
                if (cb) {
                    const card = cb.closest('.card');
                    if (card) {
                        card.style.transition = 'all 0.25s ease';
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.9)';
                        card.style.marginBottom = '-50px';
                        setTimeout(() => card.remove(), 250);
                    }
                }
                if (window.cjosHooks) window.cjosHooks.emit('onDelete', item.id);
            });

            // 3. Single Transactional Batch Delete Request
            window.cjosProgressPill.update("Deleting...", 50);
            try {
                const formData = new FormData();
                formData.append('action', 'delete_batch');
                formData.append('ids', JSON.stringify(items.map(i => i.id)));
                await fetch(window.CJOS_API_URL || 'index.php', { method: 'POST', body: formData });
            } catch (e) { console.error("Batch delete failed", e); }

            // 4. Finalize Progress Pill & UI
            window.cjosProgressPill.done("Deleted");
            
            if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();

            if (typeof cjosToggleSelectMode === 'function') cjosToggleSelectMode(false);
            if (typeof window.lsIsProcessing !== 'undefined') window.lsIsProcessing = false;
            setTimeout(releaseScroll, 1000);
        }, true);
    };
}
})();
JS;