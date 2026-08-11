<?php
// ==============================================================================
// PLUGIN: Sequential Copy
// DESCRIPTION: Copy in Selection Order and Manage Multi-Buckets.
// ==============================================================================

$seq_config_file = CJOS_PATH_DATA . '/seq-buckets.json';
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'seq_get_buckets') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $data = file_exists($seq_config_file) ? json_decode(file_get_contents($seq_config_file), true) : null;
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }
    if ($_POST['plugin_action'] === 'seq_save_buckets') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $payload = json_decode($_POST['payload'], true);
        file_put_contents($seq_config_file, json_encode($payload, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    $seq_stash_file = CJOS_PATH_DATA . '/seq-stash.json';
    if ($_POST['plugin_action'] === 'seq_stash_session') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $payload = json_decode($_POST['payload'], true);
        file_put_contents($seq_stash_file, json_encode($payload, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['plugin_action'] === 'seq_get_stash') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $data = file_exists($seq_stash_file) ? json_decode(file_get_contents($seq_stash_file), true) : null;
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }
    if ($_POST['plugin_action'] === 'seq_clear_stash') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        if (file_exists($seq_stash_file)) unlink($seq_stash_file);
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// NOTE: We inject CSS via JS to avoid polluting JSON API responses
$plugin_js .=  <<<'JS'
// --- 0. INJECT STYLES SECURELY ---
const seqStyle = document.createElement("style");
seqStyle.textContent = `
.seq-badge {
    position: absolute;
    top: -2px;
    left: -2px;
    width: 28px;
    height: 28px;
    background-color: var(--primary);
    color: white;
    font-size: 14px;
    font-weight: 600;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    transform: scale(0);
    transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    pointer-events: none;
}
.seq-badge.visible {
    transform: scale(1);
}
/* BUCKET SKEUOMORPHIC STYLES */
.seq-bucket-divider {
    width: 2px;
    height: 24px;
    background: var(--border-color, #ccc);
    margin: 0 12px;
    flex-shrink: 0;
    align-self: center;
    border-radius: 1px;
    opacity: 0.6;
}
.seq-bucket-btn {
    position: relative !important;
    -webkit-touch-callout: none !important;
    -webkit-user-select: none !important;
    user-select: none !important;
}
.seq-bucket-btn .bucket-count,
.seq-bucket-add-btn .seq-rollback-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: var(--primary, #FF3B30);
    color: white;
    font-size: 9px;
    font-weight: 800;
    padding: 1px 5px;
    border-radius: 10px;
    line-height: 1;
    box-shadow: 0 1px 3px rgba(0,0,0,0.4);
    border: 1px solid var(--border-color, #ccc);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    cursor: pointer;
    pointer-events: auto;
}
.seq-rollback-badge {
    width: 14px !important;
    height: 14px !important;
    padding: 0 !important;
    border-radius: inherit !important; /* Match theme-specific badge shapes dynamically */
    box-sizing: border-box !important;
}
.sb-scroll-container .seq-bucket-add-btn .seq-rollback-badge svg {
    width: 8px !important;
    height: 8px !important;
    stroke: #ffffff !important; /* Overrides any theme-specific button svg coloring rules */
    stroke-width: 2.2 !important; /* Elegant, balanced line weight */
    display: block !important;
}
.seq-bucket-add-btn {
    position: relative !important;
    overflow: visible !important;
}
`;
document.head.appendChild(seqStyle);

if (window.registerRefreshHook) {
    window.registerRefreshHook(() => {
        if (document.body.classList.contains("select-mode")) renderSequenceBadges();
    });
}

// --- SEQUENTIAL COPY & BUCKETS LOGIC ---
window.seqBuckets = { 'b1': { id: 'b1', name: 'Bucket A', sequence: [] } };
window.seqActiveBucketId = 'b1';
window.selectionSequence = []; // Alias to active sequence
window._seqIsCommitAction = false;
window.seqStashedSession = null;

window.seqLoadState = async function() {
    try {
        const res = await window.sui.api('seq_get_buckets', {}, {toast: false});
        if (res && res.data && res.data.buckets) {
            window.seqBuckets = res.data.buckets;
            window.seqActiveBucketId = res.data.activeBucketId || Object.keys(window.seqBuckets)[0];
            if (!window.seqBuckets[window.seqActiveBucketId]) {
                window.seqActiveBucketId = Object.keys(window.seqBuckets)[0];
            }
            window.selectionSequence = window.seqBuckets[window.seqActiveBucketId].sequence;
        }
        const stashRes = await window.sui.api('seq_get_stash', {}, {toast: false});
        if (stashRes && stashRes.data) {
            window.seqStashedSession = stashRes.data;
        }
    } catch(e) {}
    seqRenderBucketUI();
};

window.seqSaveState = async function() {
    const payload = { buckets: window.seqBuckets, activeBucketId: window.seqActiveBucketId };
    try {
        window.sui.api('seq_save_buckets', { payload: JSON.stringify(payload) }, {toast: false});
    } catch(e) {}
};

window.seqDiscardBucket = function(id) {
    if (!window.seqBuckets[id]) return;
    window.seqBuckets[id].sequence = [];
    if (id === window.seqActiveBucketId) {
        window.selectionSequence = [];
        document.querySelectorAll('.custom-checkbox.checked').forEach(cb => cb.classList.remove('checked'));
    }
    window.renderSequenceBadges();
    window.seqRenderBucketUI();
    window.seqSaveState();
    if (typeof window.updateSelectionCount === 'function') window.updateSelectionCount();
    if (window.sui && window.sui.toast) window.sui.toast(`${window.seqBuckets[id].name} Discarded`);
};

window.seqShowBucketPreview = function(id) {
    const bucket = window.seqBuckets[id];
    if (!bucket) return;

    // A. COLLAPSED RETRO ACCORDION DETAILS HEADER
    let html = `
    <div style="font-family: inherit; text-align: left; color: var(--text-primary);">
        <details style="background: var(--bg-color); border: 1.5px solid var(--border-color); border-radius: 12px; margin: 6px 0 16px 0; font-size: 12.5px; line-height: 1.45; box-shadow: inset 0 1px 3px rgba(0,0,0,0.05); overflow: hidden;">
            <summary style="padding: 12px 14px; font-weight: 800; color: var(--primary); cursor: pointer; user-select: none; outline: none; list-style-position: inside;">📋 WHAT IS A BUCKET?</summary>
            <div style="padding: 0 14px 12px 14px; color: var(--text-secondary); line-height: 1.5;">
                A temporary workspace to collect notes as you scroll. Swapping active buckets lets you organize separate groups in parallel. Actions (Copy, Delete, Folder Assign) execute strictly on the active bucket's sequence.
            </div>
        </details>
    `;

    // B. CHRONOLOGICAL FILTERING (Match active log stream ordering 1:1)
    const streamOrderedNotes = (typeof logs !== 'undefined') 
        ? logs.filter(l => bucket.sequence.includes(l.id)) 
        : [];

    const isActive = (id === window.seqActiveBucketId);

    if (streamOrderedNotes.length === 0) {
        html += `
            <div style="text-align: center; padding: 32px 16px; color: var(--text-secondary); font-size: 13px;">
                <div style="font-size: 24px; margin-bottom: 8px;">📭</div>
                This bucket is empty.<br>Start checking notes to build your sequence.
            </div>
        </div>`;
        if (isActive) {
            window.openConfirm(`${bucket.name} Preview`, html, null, false, "OK", null);
        } else {
            window.openConfirm(`${bucket.name} Preview`, html, () => {
                window.seqSwitchBucket(id);
            }, false, "Switch to Bucket", "OK");
        }
        return;
    }

    // C. SCROLLABLE ROW GENERATOR with True Click-Sequence Badges
    html += `<div style="max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 4px; box-sizing: border-box;">`;
    
    streamOrderedNotes.forEach((entry) => {
        // Safe sanitization
        const safeText = entry.transcription
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");

        // ACCURATE CLICK SEQUENCE LOOKUP: Map index in click-sequence order (1-based index)
        const sequenceOrderNumber = bucket.sequence.indexOf(entry.id) + 1;

        html += `
        <div style="display: flex; gap: 12px; align-items: flex-start; padding: 10px 12px; background: var(--card-bg); border: 1.5px solid var(--border-color); border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <!-- Displays selection order badge (e.g. 1, 3, 2...) -->
            <div style="background: var(--primary); color: white; border-radius: 50%; width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; flex-shrink: 0; box-shadow: 0 1.5px 3px rgba(0,0,0,0.15); margin-top: 1px; user-select: none;">
                ${sequenceOrderNumber}
            </div>
            <!-- Clipped note body -->
            <div style="font-size: 13px; line-height: 1.45; color: var(--text-primary); max-height: 56px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; font-family: inherit;">
                ${safeText}
            </div>
        </div>`;
    });

    html += `</div></div>`;

    // Dynamic Multi-Button Mapping based on current bucket active state
    if (isActive) {
        window.openConfirm(`${bucket.name} Notes`, html, () => {
            window.seqDiscardBucket(id);
        }, true, "Discard Bucket", "OK");
    } else {
        window.openConfirm(`${bucket.name} Notes`, html, () => {
            window.seqSwitchBucket(id);
        }, false, "Switch to Bucket", "OK", null, "Discard Bucket", () => {
            window.seqDiscardBucket(id);
        });
    }
};

window.seqRenderBucketUI = function() {
    const container = document.querySelector('.sb-scroll-container');
    if (!container) return;

    const existingBuckets = container.querySelectorAll('.seq-bucket-btn');
    const bucketIds = Object.keys(window.seqBuckets);
    
    // SMART IN-PLACE UPDATE: If the number of buckets hasn't changed, just update classes and counts.
    if (existingBuckets.length === bucketIds.length && container.querySelector('.seq-bucket-divider')) {
        window._abIsSortingDOM = true; // Suspend mutation observer reactions
        Object.values(window.seqBuckets).forEach(b => {
            const btn = document.getElementById(`seq-btn-${b.id}`);
            if (btn) {
                // Toggle Active State
                if (b.id === window.seqActiveBucketId) {
                    btn.classList.add('danger', 'active');
                    btn.classList.remove('secondary');
                } else {
                    btn.classList.remove('danger', 'active');
                    btn.classList.add('secondary');
                }
                
                // Update Badge Count
                let badge = btn.querySelector('.bucket-count');
                if (b.sequence.length > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'bucket-count';
                        btn.appendChild(badge);
                    }
                    badge.innerText = b.sequence.length;
                } else {
                    if (badge) badge.remove();
                }
            }
        });

        // UPDATE ROLLBACK BADGE IN-PLACE
        const existingAddBtn = container.querySelector('.seq-bucket-add-btn');
        if (existingAddBtn) {
            let rollbackBadge = existingAddBtn.querySelector('.seq-rollback-badge');
            if (window.seqStashedSession) {
                if (!rollbackBadge) {
                    rollbackBadge = document.createElement('div');
                    rollbackBadge.className = 'bucket-count seq-rollback-badge';
                    rollbackBadge.innerHTML = `
                        <svg viewBox="0 0 24 24" width="11" height="11" stroke="currentColor" stroke-width="3" fill="none" style="vertical-align: middle;">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
                            <path d="M3 3v5h5"></path>
                        </svg>
                    `;
                    rollbackBadge.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        window.seqTriggerRestorePrompt();
                    });
                    rollbackBadge.addEventListener('touchstart', (e) => {
                        e.stopPropagation();
                    }, { passive: true });
                    existingAddBtn.appendChild(rollbackBadge);
                }
            } else {
                if (rollbackBadge) rollbackBadge.remove();
            }
        }

        window._abIsSortingDOM = false;
        return; // Exit early! No layout shift!
    }

    // FULL REBUILD (Only happens on initialization or when a new bucket is added)
    const savedScroll = container.scrollLeft;
    const oldSnap = container.style.scrollSnapType;
    container.style.scrollSnapType = 'none'; // Disable snapping during rebuild to stop browser from fighting
    
    window._abIsSortingDOM = true;

    // 1. Clear previous instances
    container.querySelectorAll('.seq-bucket-btn, .seq-bucket-add-btn, .seq-bucket-divider').forEach(el => el.remove());

    const itemsToPrepend = [];

    // 2. Build bucket buttons (A, B, C...)
    Object.values(window.seqBuckets).forEach(b => {
        const btn = document.createElement('button');
        btn.className = `bar-action-btn seq-bucket-btn ${b.id === window.seqActiveBucketId ? 'danger active' : 'secondary'}`;
        btn.id = `seq-btn-${b.id}`;
        
        const shortName = b.name.replace('Bucket ', '');
        btn.innerHTML = `<span style="font-family: inherit; font-weight: 800; font-size: 14px;">${shortName}</span>`;

        if (b.sequence.length > 0) {
            const badge = document.createElement('span');
            badge.className = 'bucket-count';
            badge.innerText = b.sequence.length;
            btn.appendChild(badge);
        }

        // --- HIGH-RELIABILITY TACTILE LONG PRESS ENGINE ---
        let pressTimer = null;
        let startX = 0, startY = 0;
        let longPressTriggered = false;

        const onStart = (e) => {
            longPressTriggered = false;
            const coord = e.touches ? e.touches[0] : e;
            startX = coord.clientX;
            startY = coord.clientY;

            pressTimer = setTimeout(() => {
                longPressTriggered = true;
                window.sui.haptic('heavy');
                seqShowBucketPreview(b.id);
            }, 500); // 500ms long press threshold
        };

        const onMove = (e) => {
            if (!pressTimer) return;
            const coord = e.touches ? e.touches[0] : e;
            const dx = coord.clientX - startX;
            const dy = coord.clientY - startY;
            const distance = Math.sqrt(dx * dx + dy * dy);

            // If scrolled or dragged past 12px threshold, cancel the timer
            if (distance > 12) {
                clearTimeout(pressTimer);
                pressTimer = null;
            }
        };

        const onEnd = (e) => {
            clearTimeout(pressTimer);
            pressTimer = null;
            if (longPressTriggered) {
                e.preventDefault();
                e.stopPropagation();
            }
        };

        btn.addEventListener('mousedown', onStart);
        btn.addEventListener('touchstart', onStart, { passive: true });

        btn.addEventListener('mousemove', onMove);
        btn.addEventListener('touchmove', onMove, { passive: true });

        btn.addEventListener('mouseup', onEnd);
        btn.addEventListener('touchend', onEnd);
        btn.addEventListener('mouseleave', onEnd);

        // Block system text-selection / callout sheets during hold
        btn.addEventListener('contextmenu', (e) => e.preventDefault());

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (longPressTriggered) {
                longPressTriggered = false; // Reset
                return;
            }
            seqSwitchBucket(b.id);
        });

        itemsToPrepend.push(btn);
    });

    // 3. Build standard circular "+" button
    const addBtn = document.createElement('button');
    addBtn.className = 'bar-action-btn seq-bucket-add-btn secondary';
    
    let rollbackBadge = '';
    if (window.seqStashedSession) {
        rollbackBadge = `
        <div class="bucket-count seq-rollback-badge">
            <svg viewBox="0 0 24 24" width="11" height="11" stroke="currentColor" stroke-width="3" fill="none" style="vertical-align: middle;">
                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
                <path d="M3 3v5h5"></path>
            </svg>
        </div>`;
    }
    
    addBtn.innerHTML = `
        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2.5" fill="none" style="vertical-align: middle;">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
        ${rollbackBadge}
    `;

    // Abstract trigger to allow dual access via tap or hold
    window.seqTriggerRestorePrompt = function() {
        if (!window.seqStashedSession) return;
        window.openConfirm("Restore Session", "Discard current buckets and restore the previously saved session?", async () => {
            window.seqBuckets = window.seqStashedSession.buckets;
            window.seqActiveBucketId = window.seqStashedSession.activeBucketId;
            window.selectionSequence = window.seqBuckets[window.seqActiveBucketId].sequence;
            await window.seqSaveState();
            await window.sui.api('seq_clear_stash', {}, {toast: false});
            window.seqStashedSession = null;
            window.seqSwitchBucket(window.seqActiveBucketId); 
            window.sui.toast("Session Restored");
        }, true, "Restore", "Cancel");
    };

    // Bind Direct Tap to Badge (Stops propagation to parent)
    const badgeEl = addBtn.querySelector('.seq-rollback-badge');
    if (badgeEl) {
        badgeEl.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            window.seqTriggerRestorePrompt();
        });
        badgeEl.addEventListener('touchstart', (e) => {
            e.stopPropagation();
        }, { passive: true });
    }

    let addPressTimer = null;
    let addStartX = 0, addStartY = 0;
    let addLongPressTriggered = false;

    const onAddStart = (e) => {
        addLongPressTriggered = false;
        const coord = e.touches ? e.touches[0] : e;
        addStartX = coord.clientX;
        addStartY = coord.clientY;

        addPressTimer = setTimeout(() => {
            addLongPressTriggered = true;
            window.sui.haptic('heavy');
            window.seqTriggerRestorePrompt();
        }, 500);
    };

    const onAddMove = (e) => {
        if (!addPressTimer) return;
        const coord = e.touches ? e.touches[0] : e;
        if (Math.hypot(coord.clientX - addStartX, coord.clientY - addStartY) > 12) {
            clearTimeout(addPressTimer);
            addPressTimer = null;
        }
    };

    const onAddEnd = (e) => {
        clearTimeout(addPressTimer);
        addPressTimer = null;
        if (addLongPressTriggered) {
            e.preventDefault();
            e.stopPropagation();
        }
    };

    addBtn.addEventListener('mousedown', onAddStart);
    addBtn.addEventListener('touchstart', onAddStart, { passive: true });
    addBtn.addEventListener('mousemove', onAddMove);
    addBtn.addEventListener('touchmove', onAddMove, { passive: true });
    addBtn.addEventListener('mouseup', onAddEnd);
    addBtn.addEventListener('touchend', onAddEnd);
    addBtn.addEventListener('mouseleave', onAddEnd);
    addBtn.addEventListener('contextmenu', (e) => e.preventDefault());

    addBtn.onclick = (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (addLongPressTriggered) {
            addLongPressTriggered = false;
            return;
        }
        seqAddBucket();
    };

    itemsToPrepend.push(addBtn);

    // 4. Create division line
    const divider = document.createElement('div');
    divider.className = 'seq-bucket-divider';
    itemsToPrepend.push(divider);

    // 5. Prepend elements in reverse order to sit perfectly on the far left
    for (let i = itemsToPrepend.length - 1; i >= 0; i--) {
        container.insertBefore(itemsToPrepend[i], container.firstChild);
    }

    window._abIsSortingDOM = false;
    
    // Re-calculate snap points immediately before restoring snap type
    if (typeof window.refreshBarLayout === 'function') window.refreshBarLayout();
    
    container.scrollLeft = savedScroll;
    setTimeout(() => { container.style.scrollSnapType = oldSnap; }, 50);
};

window.seqAddBucket = function() {
    const num = Object.keys(window.seqBuckets).length + 1;
    const newId = 'b' + Date.now();
    const letter = String.fromCharCode(64 + num); 
    window.seqBuckets[newId] = { id: newId, name: 'Bucket ' + letter, sequence: [] };
    seqSwitchBucket(newId);
};

window.seqSwitchBucket = function(id) {
    if (!window.seqBuckets[id]) return;
    window.seqActiveBucketId = id;
    window.selectionSequence = window.seqBuckets[id].sequence;
    
    // DOM SWAP: Clear current visually
    document.querySelectorAll('.custom-checkbox.checked').forEach(cb => cb.classList.remove('checked'));
    
    // DOM SWAP: Apply new visually
    window.selectionSequence.forEach(cardId => {
        const cb = document.querySelector(`.custom-checkbox[data-id="${cardId}"]`);
        if (cb) cb.classList.add('checked');
    });
    
    window.renderSequenceBadges();
    window.seqRenderBucketUI();
    window.seqSaveState();
    if (typeof window.updateSelectionCount === 'function') window.updateSelectionCount();
};

// 1. Listen for clicks on the container to track order
window.addEventListener('load', () => {
    seqLoadState(); // Load server state

    if (!window.InteractionManager) return;
    
    InteractionManager.subscribe({
        plugin: 'SequentialCopy',
        event: 'onSelectionChange',
        priority: 20,
        handler: ({ card }) => {
            const checkbox = card.querySelector('.custom-checkbox');
            if (!checkbox) return;
            const id = checkbox.getAttribute('data-id');

            // 20ms buffer to allow DOM/Animation to settle
            setTimeout(() => {
                if (checkbox.classList.contains('checked')) {
                    if (!window.selectionSequence.includes(id)) window.selectionSequence.push(id);
                } else {
                    window.selectionSequence = window.selectionSequence.filter(sid => sid !== id);
                }
                window.seqBuckets[window.seqActiveBucketId].sequence = window.selectionSequence;
                window.renderSequenceBadges();
                window.seqRenderBucketUI();
                window.seqSaveState();
            }, 20);
        }
    });
});

// 2. Function to draw the badges
window.renderSequenceBadges = function() {
    document.querySelectorAll(".seq-badge").forEach(el => el.remove());

    window.selectionSequence.forEach((id, index) => {
        const checkbox = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
        if (checkbox) {
            let badge = document.createElement("div");
            badge.className = "seq-badge visible";
            badge.innerText = (index + 1);
            checkbox.appendChild(badge);
        }
    });
};

// --- MANUAL CANCEL TRACKER ---
let isManualCancel = false;

document.addEventListener('click', (e) => {
    if (e.target.closest('#cancel-btn')) {
        isManualCancel = true;
        setTimeout(() => { isManualCancel = false; }, 100);
    }
}, true);

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        isManualCancel = true;
        setTimeout(() => { isManualCancel = false; }, 100);
    }
}, true);

window.addEventListener('popstate', () => {
    isManualCancel = true;
    setTimeout(() => { isManualCancel = false; }, 120); // 120ms window to cover history-pop ticks
});

// 3. Hook into ToggleSelectMode to clear sequence OR handle smart exits
window._seqForceExit = false;
const originalToggleSelect = window.cjosToggleSelectMode;
window.cjosToggleSelectMode = function(enable) {
    if (!enable) {
        if (window._seqForceExit) {
            window._seqForceExit = false; // Reset bypass flag on confirmed exit
        } else if (!isManualCancel) {
            const otherBuckets = Object.values(window.seqBuckets).filter(b => b.id !== window.seqActiveBucketId && b.sequence.length > 0);
            
            window.seqBuckets[window.seqActiveBucketId].sequence = [];
            window.selectionSequence = [];
            
            if (otherBuckets.length > 0) {
                seqSwitchBucket(otherBuckets[0].id);
                if (window.sui && window.sui.toast) {
                    window.sui.toast(`Active Bucket Done. Switched to ${otherBuckets[0].name}`);
                }
                return; // DO NOT EXIT SELECT MODE
            }
        } else {
            // Manual exit triggered (Done button or Escape key) while populated buckets exist
            const populatedBuckets = Object.values(window.seqBuckets).filter(b => b.sequence.length > 0);
            const totalSelectedCount = populatedBuckets.reduce((acc, b) => acc + b.sequence.length, 0);
            const isSingleBucketSingleNote = (populatedBuckets.length === 1 && totalSelectedCount === 1);

            if (populatedBuckets.length > 0 && !isSingleBucketSingleNote) {
                const listItems = populatedBuckets.map(b => 
                    `<li style="margin-bottom: 5px;"><strong>${b.name}</strong>: ${b.sequence.length} note${b.sequence.length > 1 ? 's' : ''}</li>`
                ).join('');

                const message = `
                <div style="font-family: inherit; line-height: 1.5; text-align: left; color: var(--text-primary); margin-top: 6px;">
                    You have unsaved selections inside your sequence buckets:
                    <ul style="margin: 12px 0; padding-left: 20px; color: var(--primary);">${listItems}</ul>
                    Are you sure you want to exit selection mode and discard these selections?
                </div>`;

                window.openConfirm("⚠️ Unsaved Buckets", message, () => {
                    window._seqForceExit = true;
                    window.cjosToggleSelectMode(false); // Force exit on user confirmation
                }, true, "Discard & Exit", "Cancel", null, "Save Session & Exit", async () => {
                    const payload = { buckets: window.seqBuckets, activeBucketId: window.seqActiveBucketId, timestamp: Date.now() };
                    await window.sui.api('seq_stash_session', { payload: JSON.stringify(payload) }, {toast: "Session Saved"});
                    window.seqStashedSession = payload;
                    window._seqForceExit = true;
                    window.cjosToggleSelectMode(false);
                });
                return; // Synchronously prevent selection mode close
            }
        }

        // True Exit
        window.seqBuckets = { 'b1': { id: 'b1', name: 'Bucket A', sequence: [] } };
        window.seqActiveBucketId = 'b1';
        window.selectionSequence = [];
        window.seqSaveState();
        window.seqRenderBucketUI();
        document.querySelectorAll(".seq-badge").forEach(el => el.remove());
    } else {
        window.seqRenderBucketUI();

        // DEFERRED INCEPTION SCAN
        setTimeout(() => {
            const checkedBoxes = document.querySelectorAll('.custom-checkbox.checked');
            checkedBoxes.forEach(cb => {
                const id = cb.getAttribute('data-id');
                if (id && !window.selectionSequence.includes(id)) {
                    window.selectionSequence.push(id);
                }
            });
            window.seqBuckets[window.seqActiveBucketId].sequence = window.selectionSequence;

            window.seqRenderBucketUI();
            window.renderSequenceBadges();
            window.seqSaveState();
            if (typeof window.updateSelectionCount === 'function') window.updateSelectionCount();
        }, 60);
        
        // FORCE STABLE LEFT-ALIGNMENT ON SELECT MODE INCEPTION
        setTimeout(() => {
            const wrapper = document.querySelector(".sb-scroll-container");
            if (wrapper) wrapper.scrollLeft = 0;
        }, 50);
        setTimeout(() => {
            const wrapper = document.querySelector(".sb-scroll-container");
            if (wrapper) wrapper.scrollLeft = 0;
        }, 360);
    }
    if (originalToggleSelect) originalToggleSelect(enable);
};

// 4. Override Action Buttons to Intercept Commit Text Formats
window.addEventListener("load", () => {
    // A. Override Copy Button
    const btnCopySeq = document.getElementById("action-copy");
    if (btnCopySeq) {
        const newBtn = btnCopySeq.cloneNode(true);
        btnCopySeq.parentNode.replaceChild(newBtn, btnCopySeq);
        
        newBtn.onclick = () => {
            const draftPad = document.getElementById("draft-pad-card");
            const isDrafting = draftPad && draftPad.style.transform === "translateY(0px)";

            if (window.selectionSequence.length > 0) {
                let textResult = [];
                window.selectionSequence.forEach(id => {
                    const itemData = (typeof logs !== 'undefined') ? logs.find(l => l.id === id) : null;
                    if (itemData) textResult.push(itemData.transcription);
                });
                copyToClipboard(textResult.join("\n\n"), null);
                if (window.markIdsAsInteracted) window.markIdsAsInteracted(window.selectionSequence);
                if (!isDrafting) cjosToggleSelectMode(false);
            } else {
                const items = getSelectedItems(); 
                if(items.length) { 
                    const texts = items.map(i => i.transcription); 
                    copyToClipboard(texts.join("\n\n"), null); 
                    if (window.markIdsAsInteracted) window.markIdsAsInteracted(items.map(i => i.id));
                    if (!isDrafting) cjosToggleSelectMode(false);
                }
            }
        };
    }
    
    // B. Patch ToDoList to Respect Sequence
    if(typeof todoData !== "undefined") {
        window.initiateAddToList = function() {
            if (window.selectionSequence.length > 0) {
                selectedForTodo = [...window.selectionSequence];
            } else {
                selectedForTodo = getSelectedItems().map(i => i.id);
            }

            let commonListId = null;
            if (selectedForTodo.length > 0) {
                for (const list of todoData) {
                    const listLogIds = list.items.map(i => i.log_id);
                    const allPresent = selectedForTodo.every(id => listLogIds.includes(id));
                    if (allPresent) {
                        commonListId = list.id;
                        break; 
                    }
                }
            }
            
            const options = todoData.map(l => ({ 
                value: l.id, 
                label: l.name + (l.is_starred == 1 ? " ★" : "") 
            }));
            options.unshift({ value: "create_new", label: "+ Create New List" });
            
            window.openPicker("Add to List", options, commonListId, async (val) => {
                if (val === "create_new") {
                    window.openInput("Create New List", "List Name", "", async (name) => {
                        if (name) {
                            await handleTodoListAction("add", "new", name);
                        }
                    });
                } else if (val === commonListId) {
                    await handleTodoListAction("remove", val);
                } else {
                    await handleTodoListAction("add", val);
                }
            });
        };
    }

    // C. Intercept Magic Back navigation from the gesture stack
    if (typeof window.frHandleBackAction === "function") {
        const originalFrBack = window.frHandleBackAction;
        window.frHandleBackAction = function(...args) {
            isManualCancel = true;
            const result = originalFrBack(...args);
            setTimeout(() => { isManualCancel = false; }, 120);
            return result;
        };
    }
});

// Hook into ScrollableActionBar to keep bucket controls at the absolute far-left and stabilize scroll position
window.addEventListener('load', () => {
    const originalApplyOrder = window.applyActionBarOrder;
    if (originalApplyOrder) {
        window.applyActionBarOrder = function() {
            const wrapper = document.querySelector(".sb-scroll-container");
            const savedScrollLeft = wrapper ? wrapper.scrollLeft : 0;
            
            originalApplyOrder();
            
            if (wrapper) {
                const divider = wrapper.querySelector('.seq-bucket-divider');
                const addBtn = wrapper.querySelector('.seq-bucket-add-btn');
                const buckets = Array.from(wrapper.querySelectorAll('.seq-bucket-btn'));
                
                if (divider) wrapper.prepend(divider);
                if (addBtn) wrapper.prepend(addBtn);
                buckets.reverse().forEach(b => wrapper.prepend(b));
                
                // Keep scroll completely anchored across re-orders
                wrapper.scrollLeft = savedScrollLeft;
            }
        };
    }

    // Decorate layout builder to suppress reflow layout drift
    const originalRefresh = window.refreshBarLayout;
    if (originalRefresh) {
        window.refreshBarLayout = function() {
            const wrapper = document.querySelector(".sb-scroll-container");
            const savedScrollLeft = wrapper ? wrapper.scrollLeft : 0;
            
            originalRefresh();
            
            if (wrapper) {
                wrapper.scrollLeft = savedScrollLeft;
            }
        };
    }
});
JS;
?>