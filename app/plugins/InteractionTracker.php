<?php
// ==============================================================================
// PLUGIN: Interaction Tracker
// DESCRIPTION: New vs Processed Indicators.
// Highlights untouched entries with a "Draft" aesthetic (Dashed Border).
// UPDATED: Now automatically marks original cards as 'Interacted' when merging.
// ==============================================================================

// 1. DATABASE MIGRATION
try {
    $cols = $db->query("PRAGMA table_info(logs)")->fetchAll(PDO::FETCH_ASSOC);
    $hasCol = false;
    foreach ($cols as $c) { if ($c['name'] === 'has_interacted') $hasCol = true; }
    if (!$hasCol) {
        $db->exec("ALTER TABLE logs ADD COLUMN has_interacted INTEGER DEFAULT 0");
    }
} catch (Exception $e) {}

// 2. API HANDLER
$it_config_file = CJOS_PATH_DATA . '/interaction-tracker-config.json';

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'it_get_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $defaults = [
        'enabled' => true, 
        'dot_color' => '#FF3B30', 
        'mode' => 'dot',
        'dim_opacity' => 50,
        'dim_brightness' => 80,
        'dim_saturation' => 50
    ];
    $config = file_exists($it_config_file) ? json_decode(file_get_contents($it_config_file), true) : $defaults;
    if (!isset($config['mode'])) $config['mode'] = 'dot';
    if (!isset($config['dim_opacity'])) $config['dim_opacity'] = 50;
    if (!isset($config['dim_brightness'])) $config['dim_brightness'] = 80;
    if (!isset($config['dim_saturation'])) $config['dim_saturation'] = 50;
    echo json_encode(['status' => 'success', 'config' => $config]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'it_save_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $settings = json_decode($_POST['settings'], true);
    file_put_contents($it_config_file, json_encode($settings, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'it_mark_interacted') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $ids = json_decode($_POST['ids'], true);
    if (is_array($ids) && !empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE logs SET has_interacted = 1 WHERE id IN ($placeholders)");
        $stmt->execute($ids);
    }
    
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'it_unmark_interacted') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $id = $_POST['id'] ?? '';
    $ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : [];
    
    if (is_array($ids) && !empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE logs SET has_interacted = 0 WHERE id IN ($placeholders)");
        $stmt->execute($ids);
    } elseif ($id) {
        $stmt = $db->prepare("UPDATE logs SET has_interacted = 0 WHERE id = ?");
        $stmt->execute([$id]);
    }
    
    echo json_encode(['status' => 'success']);
    exit;
}

// 3. SETTINGS UI
$plugin_settings_map['InteractionTracker'] = <<<'HTML'
    <div data-sui-setting="Enable Tracking" data-sui-desc="Visually differentiate new and processed notes." data-sui-id="it-toggle" data-sui-onchange="itUpdateSetting('enabled', this.checked)"></div>

    <div class="setting-item vertical">
        <label class="setting-label">Display Style</label>
        <div class="setting-desc">Choose how to identify interacted entries.</div>
        <div style="display:flex; background:#E5E5EA; border-radius:10px; padding:2px; margin-top:8px;">
            <button onclick="itUpdateSetting('mode', 'dot')" id="it-btn-mode-dot" style="flex:1; border:none; background:transparent; border-radius:8px; padding:8px; cursor:pointer; font-size:12px; color:#8E8E93; transition:all 0.2s;">Dot (New)</button>
            <button onclick="itUpdateSetting('mode', 'dim')" id="it-btn-mode-dim" style="flex:1; border:none; background:transparent; border-radius:8px; padding:8px; cursor:pointer; font-size:12px; color:#8E8E93; transition:all 0.2s;">Dim (Old)</button>
            <button onclick="itUpdateSetting('mode', 'both')" id="it-btn-mode-both" style="flex:1; border:none; background:transparent; border-radius:8px; padding:8px; cursor:pointer; font-size:12px; color:#8E8E93; transition:all 0.2s;">Hybrid</button>
        </div>
    </div>

    <div id="it-color-setting" class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Indicator Color</label>
            <span class="setting-desc">Color of the untouched notification dot.</span>
        </div>
        <div class="color-input-group">
            <input type="color" id="it-dot-color-picker" onchange="itUpdateSetting('dot_color', this.value)" style="width:40px; height:40px; border-radius:8px; border:none; background:none; cursor:pointer;">
        </div>
    </div>

    <div id="it-dim-settings" style="display:none; flex-direction:column; gap:4px; padding: 0 16px 16px 16px; border-bottom: 1px solid #F2F2F7;">
        <div class="setting-item vertical" style="padding:8px 0; border:none;">
            <div style="display:flex; justify-content:space-between;">
                <label class="setting-label" style="font-size:13px;">Interacted Opacity</label>
                <span id="it-val-opacity" style="font-size:12px; font-weight:700; color:var(--primary);">50%</span>
            </div>
            <input type="range" id="it-slider-opacity" min="10" max="100" step="5" oninput="itUpdateSetting('dim_opacity', this.value)">
        </div>
        
        <div class="setting-item vertical" style="padding:8px 0; border:none;">
            <div style="display:flex; justify-content:space-between;">
                <label class="setting-label" style="font-size:13px;">Interacted Brightness</label>
                <span id="it-val-brightness" style="font-size:12px; font-weight:700; color:var(--primary);">80%</span>
            </div>
            <input type="range" id="it-slider-brightness" min="20" max="100" step="5" oninput="itUpdateSetting('dim_brightness', this.value)">
        </div>

        <div class="setting-item vertical" style="padding:8px 0; border:none;">
            <div style="display:flex; justify-content:space-between;">
                <label class="setting-label" style="font-size:13px;">Interacted Saturation</label>
                <span id="it-val-saturation" style="font-size:12px; font-weight:700; color:var(--primary);">50%</span>
            </div>
            <input type="range" id="it-slider-saturation" min="0" max="100" step="5" oninput="itUpdateSetting('dim_saturation', this.value)">
        </div>
    </div>

    <div class="setting-item">
        <button onclick="markAllInteracted()" class="text-btn" style="width:100%; color:var(--primary); font-size:14px;">Mark All Visible as Done</button>
    </div>
    <div id="it-save-status" style="text-align:right; font-size:11px; color:#8E8E93; padding:0 16px 8px; height:14px;"></div>
HTML;

// 4. JS LOGIC
$plugin_js .= <<<'JS'
// --- INTERACTION TRACKER JS ---

let itSettings = { 
    enabled: true, 
    dot_color: '#FF3B30', 
    mode: 'dot',
    dim_opacity: 50,
    dim_brightness: 80,
    dim_saturation: 50
};

window.itUpdateSetting = function(key, val) {
itSettings[key] = val;
itApplySettings();
itSaveSettings();
};

const haptic = (type) => window.sui.haptic(type);

async function itSaveSettings() {
    try {
        await window.sui.api('it_save_config', { settings: itSettings }, { toast: "Tracker Settings Saved" });
    } catch(e) {}
}

function itApplySettings() {
    document.documentElement.style.setProperty('--it-dot-color', itSettings.dot_color);
    
    const hex = itSettings.dot_color.replace('#', '');
    const r = parseInt(hex.substring(0, 2), 16);
    const g = parseInt(hex.substring(2, 4), 16);
    const b = parseInt(hex.substring(4, 6), 16);
    document.documentElement.style.setProperty('--it-dot-glow', `rgba(${r}, ${g}, ${b}, 0.6)`);

    const toggle = document.getElementById('it-toggle');
    if(toggle) toggle.checked = itSettings.enabled;
    
    const picker = document.getElementById('it-dot-color-picker');
    if(picker) picker.value = itSettings.dot_color;

    // Apply Mode Classes
    document.body.classList.toggle('it-disabled', !itSettings.enabled);
    document.body.classList.remove('it-mode-dot', 'it-mode-dim', 'it-mode-both');
    if(itSettings.enabled) document.body.classList.add('it-mode-' + (itSettings.mode || 'dot'));

    // Update Mode Buttons
    ['dot', 'dim', 'both'].forEach(m => {
        const btn = document.getElementById('it-btn-mode-' + m);
        if(btn) {
            const isActive = itSettings.mode === m;
            btn.style.background = isActive ? 'white' : 'transparent';
            btn.style.color = isActive ? 'var(--text-primary)' : '#8E8E93';
            btn.style.boxShadow = isActive ? '0 1px 3px rgba(0,0,0,0.1)' : 'none';
            btn.style.fontWeight = isActive ? '700' : '400';
        }
    });

    // Show/Hide color picker based on mode
    const colorRow = document.getElementById('it-color-setting');
    if(colorRow) colorRow.style.display = (itSettings.mode === 'dim' && itSettings.enabled) ? 'none' : 'flex';

    // Update Sliders & Labels
    const dimSettings = document.getElementById('it-dim-settings');
    if(dimSettings) dimSettings.style.display = (itSettings.mode !== 'dot' && itSettings.enabled) ? 'flex' : 'none';

    if(itSettings.enabled) {
        document.documentElement.style.setProperty('--it-dim-opacity', itSettings.dim_opacity / 100);
        document.documentElement.style.setProperty('--it-dim-brightness', itSettings.dim_brightness / 100);
        document.documentElement.style.setProperty('--it-dim-saturation', itSettings.dim_saturation / 100);

        if(document.getElementById('it-slider-opacity')) document.getElementById('it-slider-opacity').value = itSettings.dim_opacity;
        if(document.getElementById('it-slider-brightness')) document.getElementById('it-slider-brightness').value = itSettings.dim_brightness;
        if(document.getElementById('it-slider-saturation')) document.getElementById('it-slider-saturation').value = itSettings.dim_saturation;

        if(document.getElementById('it-val-opacity')) document.getElementById('it-val-opacity').innerText = itSettings.dim_opacity + '%';
        if(document.getElementById('it-val-brightness')) document.getElementById('it-val-brightness').innerText = itSettings.dim_brightness + '%';
        if(document.getElementById('it-val-saturation')) document.getElementById('it-val-saturation').innerText = itSettings.dim_saturation + '%';
    }
}

document.addEventListener("DOMContentLoaded", () => {
    if (window.sui && window.sui.registerBadge) {
        // Force the label into the registry immediately
        window.loKnownLabels = window.loKnownLabels || {};
        window.loKnownLabels["it-interacted-badge"] = { label: "✅ Processed", color: "var(--btn-bg)" };

        window.sui.registerBadge("it-interacted-badge", (entry, card) => {
            if (entry && entry.has_interacted == 1) {
                if (!card) return { label: "✅ Processed" };
                return null;
            }
            return null;
        }, 30);
    }
});

window.addEventListener("load", async () => {
    // Load from server
    try {
        const data = await window.sui.api('it_get_config', {}, { toast: false });
        itSettings = data.config;
    } catch(e) {}

    itApplySettings();

    if (!itSettings.enabled) return;

    // 1. INJECT CSS
    const style = document.createElement("style");
    style.innerHTML = `
        /* PENDING / UNTOUCHED STATE */
        body.it-mode-dot .card.state-untouched:not(.phantom-card):not(.is-moved-placeholder) .time-badge::after,
        body.it-mode-both .card.state-untouched:not(.phantom-card):not(.is-moved-placeholder) .time-badge::after {
            content: "";
            display: inline-block;
            width: 6px;
            height: 6px;
            background-color: var(--it-dot-color, #FF3B30);
            border-radius: 50%;
            margin-left: 8px;
            vertical-align: middle;
            box-shadow: 0 0 6px var(--it-dot-glow, rgba(255, 59, 48, 0.6));
            transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        /* DIMMING INTERACTED STATE (Receding Physics) */
body.it-mode-dim .card.state-interacted .card-content,
body.it-mode-both .card.state-interacted .card-content {
    opacity: var(--it-dim-opacity, 0.5) !important;
    /* Grayscale + Contrast makes it look de-emphasized but still legible */
    filter: brightness(var(--it-dim-brightness, 0.8)) grayscale(var(--it-dim-saturation, 0.8)) contrast(0.9) !important;
    transition: opacity 0.6s cubic-bezier(0.23, 1, 0.32, 1), 
                filter 0.6s cubic-bezier(0.23, 1, 0.32, 1), 
                transform 0.45s cubic-bezier(0.16, 1, 0.3, 1);
}

/* Only apply receding scale when NOT in selection mode to allow checkboxes to show */
body.it-mode-dim:not(.select-mode) .card.state-interacted .card-content,
body.it-mode-both:not(.select-mode) .card.state-interacted .card-content {
    transform: scale(0.985) translate3d(0,0,0);
}
    
/* Lift dimming on active/playing cards */
body.it-mode-dim .card.state-interacted:has(.is-playing) .card-content,
body.it-mode-both .card.state-interacted:has(.is-playing) .card-content {
    opacity: 1 !important;
    filter: none !important;
    transform: scale(1) translate3d(0,0,0);
}

/* --- ANIMATION STATES --- */
@keyframes it-dot-explode {
    0% { transform: scale(0); opacity: 0; filter: blur(2px); }
    70% { transform: scale(1.2); opacity: 1; filter: blur(0); }
    100% { transform: scale(1); opacity: 1; box-shadow: 0 0 8px var(--it-dot-glow); }
}
.it-anim-ripple .time-badge::after {
    animation: it-dot-explode 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards !important;
}

@keyframes it-dot-implode {
    0% { transform: scale(1); opacity: 1; }
    30% { transform: scale(1.2); opacity: 1; }
    100% { transform: scale(0); opacity: 0; filter: blur(2px); }
}
.it-anim-poof .time-badge::after {
    animation: it-dot-implode 0.4s cubic-bezier(0.6, -0.28, 0.735, 0.045) forwards !important;
}/* Support for disabling the plugin visually */
        body.it-disabled .time-badge::after {
            display: none !important;
        }
    `;
    document.head.appendChild(style);



    // 3. HOOK: Copy to Clipboard
    if (typeof window.copyToClipboard === "function") {
        const originalCopy = window.copyToClipboard;
        window.copyToClipboard = function(text, cardElement) {
            originalCopy(text, cardElement);
            if (cardElement) markAsInteracted([cardElement]);
        };
    }

    // 4. HOOK: Pipeline Interface
if (window.cjosHooks) {
    // Mark as interacted when data is updated (Polished, Merged, etc)
    window.cjosHooks.register('onUpdate', (id, entry, details) => {
        // Ignore organizational folder assignments so they do not falsely trigger interaction flags
        if (details && details.action === 'folder_assign') return;

        if (entry && entry.is_merged) {
            // For merges, we find the originals via the pending delete key if available
            const originals = JSON.parse(localStorage.getItem("cjos_pending_merge_delete") || "[]");
            if (originals.length > 0) markIdsAsInteracted(originals);
        } else {
            markIdAsInteracted(id);
        }
    });
}// 5. REGISTER VIA HANDSHAKE
    if (window.registerCardPlugin) {
        window.registerCardPlugin(processSingleCard, 40); // Priority 40: State/Content
    }

    if (window.sui && window.sui.registerBadge) {
        // Ensure the label is known to the Organizer even if no cards are currently rendered
        if (window.loKnownLabels) {
            window.loKnownLabels["it-interacted-badge"] = { label: "✅ Processed", color: "var(--btn-bg)" };
        }

        window.sui.registerBadge("it-interacted-badge", (entry, card) => {
            if (entry && entry.has_interacted == 1) {
                // If card is null, we are in "Filter Discovery" mode (Smart Organizer)
                // If card exists, we check if the theme/user actually wants a visible badge on the card
                if (!card) return { label: "✅ Processed" };
                
                // We return null for the actual card render to avoid UI clutter, 
                // since the "dimming" effect already handles the visual state.
                return null;
            }
            return null;
        }, 30); // Priority 30: State metadata
    }
});

// --- LOGIC FUNCTIONS ---

function applyInteractionStates() {
    document.querySelectorAll(".card").forEach(processSingleCard);
}

function processSingleCard(card) {
    const checkbox = card.querySelector(".custom-checkbox");
    if(!checkbox) return;
    
    const id = checkbox.getAttribute("data-id");
    const entry = (typeof logs !== "undefined") ? logs.find(l => l.id === id) : null;
    
    if (!entry) return;

    // CHECK 1: Explicit DB Flag
    if (entry.has_interacted == 1) {
        setCardInteracted(card);
        return;
    }



    // Default: Untouched
    card.classList.add("state-untouched");
    card.classList.remove("state-interacted");
}

function setCardInteracted(card, animate = false) {
    if (animate && card.classList.contains('state-untouched')) {
        card.classList.add('it-anim-poof');
        setTimeout(() => {
            card.classList.remove("state-untouched", "it-anim-poof");
            card.classList.add("state-interacted");
        }, 400);
    } else {
        card.classList.remove("state-untouched");
        card.classList.add("state-interacted");
    }
}

// --- ACTION HANDLERS ---

window.markIdAsInteracted = function(id) {
    window.markIdsAsInteracted([id]);
};

window.markIdsAsInteracted = function(ids) {
    if (!ids || ids.length === 0) return;
    ids.forEach(id => {
        if (typeof logs !== "undefined") {
            const entry = logs.find(l => l.id === id);
            if (entry) entry.has_interacted = 1;
        }
        const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
        if (cb) setCardInteracted(cb.closest(".card"), ids.length === 1);
    });
    window.sui.api("it_mark_interacted", { ids: ids }, { toast: false });
};

function markAsInteracted(cards) {
    if(!cards || cards.length === 0) return;
    
    const idsToUpdate = [];
    
    cards.forEach(card => {
        // Visual Update (Animate if it's a single card interaction like Copy)
        setCardInteracted(card, cards.length === 1);
        
        // Data Update
        const cb = card.querySelector(".custom-checkbox");
        if(cb) {
            const id = cb.getAttribute("data-id");
            idsToUpdate.push(id);
            
            if(typeof logs !== "undefined") {
                const entry = logs.find(l => l.id === id);
                if(entry) entry.has_interacted = 1;
            }
        }
    });

    // Server Update
    if(idsToUpdate.length > 0) {
        window.sui.api("it_mark_interacted", { ids: idsToUpdate }, { toast: false });
    }
}

window.markAllInteracted = function() {
    window.openConfirm("Mark All Read", "Mark all visible cards as interacted?", () => {
        const visibleCards = Array.from(document.querySelectorAll(".card")).filter(c => c.style.display !== "none");
        markAsInteracted(visibleCards);
    });
};

window.unmarkAsInteracted = function(card) {
    const cb = card.querySelector(".custom-checkbox");
    if(!cb) return;
    const id = cb.getAttribute("data-id");
    
    // DELAY: Card stays "interacted" (dimmed) for a moment after trigger
    setTimeout(() => {
        card.classList.remove("state-interacted");
        card.classList.add("state-untouched", "it-anim-ripple");
        
        // Cleanup animation class after it finishes
        setTimeout(() => {
            card.classList.remove("it-anim-ripple");
        }, 600);
    }, 450);
    
    if(typeof logs !== "undefined") {
        const entry = logs.find(l => l.id === id);
        if(entry) entry.has_interacted = 0;
    }
    
    window.sui.api("it_unmark_interacted", { id: id }, { toast: false });

    if (window.sui && window.sui.toast) {
        window.sui.toast("Interaction Reset", { plugin: "InteractionTracker", caller: "unmarkAsInteracted", metrics: { id: id } });
    }
};
JS;

$plugin_js .= <<<'JS'
(function() {
    window.addEventListener('load', () => {
        if (window.InteractionManager) {
            InteractionManager.subscribe({
                plugin: 'InteractionTracker',
                event: 'onSwipeAction',
                priority: 50,
                handler: ({ card, detail }) => {
                    if (detail.action === 'reset') {
                        unmarkAsInteracted(card);
                    }
                }
            });
        }
    });
})();

function itInjectBarButton() {
    const bar = document.querySelector(".selection-bottom-bar");
    if (bar && !document.getElementById("action-reset-interaction")) {
        const btn = document.createElement("button");
        btn.className = "bar-action-btn";
        btn.id = "action-reset-interaction";
        btn.title = "Reset Interaction State";
        btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><polyline points="3 3 3 8 7 8"></polyline><circle cx="12" cy="12" r="1" fill="currentColor"></circle></svg>`;
        
        btn.onclick = (e) => {
            e.stopPropagation();
            itPromptResetState();
        };
        
        bar.appendChild(btn);
    }
}

window.addEventListener('cjos-select-mode', (e) => {
    if (e.detail.enabled) {
        itInjectBarButton();
    }
});

async function itPromptResetState() {
    const selected = getSelectedItems();
    if (selected.length === 0) return;

    let activeFolderLabel = "All Notes";
    const folderId = (typeof currentFolderId !== "undefined") ? currentFolderId : null;
    if (folderId === 0) {
        activeFolderLabel = "Unsorted";
    } else if (folderId !== null && typeof so_folders !== "undefined") {
        const f = so_folders.find(x => x.id == folderId);
        if (f) activeFolderLabel = f.name;
    }

    const options = [
        { label: `Reset Selected Only (${selected.length})`, value: "selected" },
        { label: `Reset All in Folder: ${activeFolderLabel}`, value: "folder" },
        { label: "Cancel", value: "cancel" }
    ];

    window.openPicker("Reset Interaction State", options, null, (val) => {
        if (val === "selected") {
            const ids = selected.map(item => item.id);
            itBatchReset(ids);
        } else if (val === "folder") {
            const folderLogs = logs.filter(l => {
                const fid = (typeof so_map !== "undefined") ? so_map[l.id] : null;
                if (folderId === null) return true;
                if (folderId === 0) return (!fid || fid == 0);
                return (fid == folderId);
            });
            const ids = folderLogs.map(l => l.id);
            itBatchReset(ids);
        }
    });
}

async function itBatchReset(ids) {
    if (!ids || ids.length === 0) return;
    
    try {
        await window.sui.api("it_unmark_interacted", { ids: JSON.stringify(ids) }, { toast: false });

        ids.forEach(id => {
            if (typeof logs !== "undefined") {
                const entry = logs.find(l => l.id === id);
                if (entry) entry.has_interacted = 0;
            }

            const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
            const card = cb ? cb.closest(".card") : null;
            if (card) {
                card.classList.remove("state-interacted");
                card.classList.add("state-untouched", "it-anim-ripple");
                setTimeout(() => {
                    card.classList.remove("it-anim-ripple");
                }, 600);
            }
        });

        if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
        if (window.cjosToggleSelectMode) window.cjosToggleSelectMode(false);

        if (window.sui && window.sui.toast) {
            window.sui.toast(`Reset ${ids.length} item(s)`);
        }
    } catch (e) {
        console.error("Batch reset failed", e);
    }
}
JS;
?>