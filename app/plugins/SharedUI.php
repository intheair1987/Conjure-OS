<?php
// ==============================================================================
// PLUGIN: Shared UI
// DESCRIPTION: Reusable UI Components.
// ==============================================================================
$sui_config_file = CJOS_PATH_DATA . '/shared-ui-config.json';

// --- ICON WARM CACHE ---
$icon_cache = [];
$icon_dir = CJOS_PATH_DATA . '/icons';
if (is_dir($icon_dir)) {
    foreach (glob("$icon_dir/*.svg") as $file) {
        $name = basename($file, '.svg');
        $icon_cache[$name] = file_get_contents($file);
    }
}
$icon_cache_json = json_encode($icon_cache);
$plugin_js .= "\nwindow.__SUI_ICON_CACHE__ = $icon_cache_json;\n";

if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'sui_icon_proxy') {
    while (ob_get_level()) ob_end_clean();
    $name = preg_replace('/[^a-z0-9-]/', '', $_GET['name'] ?? '');
    if (!$name) exit;
    $dir = CJOS_PATH_DATA . '/icons';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $path = $dir . '/' . $name . '.svg';
    if (!file_exists($path)) {
        $ch = curl_init("https://unpkg.com/lucide-static@latest/icons/{$name}.svg");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $svg = curl_exec($ch);
        curl_close($ch);
        if ($svg && strpos($svg, '<svg') !== false) file_put_contents($path, $svg);
    }
    if (file_exists($path)) {
        header('Content-Type: image/svg+xml');
        echo file_get_contents($path);
    }
    exit;
}

if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'sui_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults = ['dim' => 60, 'blur' => 8, 'stroke' => 1.5];
        $saved = file_exists($sui_config_file) ? json_decode(file_get_contents($sui_config_file), true) : [];
        $conf = array_merge($defaults, $saved);
        echo json_encode(['status' => 'success', 'config' => $conf]);
        exit;
    }
    if ($_POST['plugin_action'] === 'sui_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $data = [
            'dim' => (int)$_POST['dim'], 
            'blur' => (int)$_POST['blur'],
            'stroke' => (float)$_POST['stroke']
        ];
        file_put_contents($sui_config_file, json_encode($data));
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// ==============================================================================
// SHARED UI COMPONENTS (Modal Pickers)
// ==============================================================================

// Fix 1: Use $plugin_overlays so it doesn't create a swipeable page
// Fix 2: Z-Index 6000 to sit above Settings (5000)
$plugin_settings_map['SharedUI'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Background Dimming</label>
        <div class="setting-desc">Control how dark the area behind menus appears.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <div data-sui-slider="true" data-sui-id="sui-dim-slider" data-sui-min="0" data-sui-max="100" data-sui-value="dim" data-sui-oninput="suiUpdateDimUI(this.value)" data-sui-onchange="suiSaveConfig()"></div>
            <span id="sui-dim-val" style="font-weight:700; color:var(--primary); min-width:40px;">60%</span>
        </div>
    </div>
    <div class="setting-item vertical">
        <label class="setting-label">Background Blur</label>
        <div class="setting-desc">Control the intensity of the glass frost effect.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <div data-sui-slider="true" data-sui-id="sui-blur-slider" data-sui-min="0" data-sui-max="25" data-sui-value="blur" data-sui-oninput="suiUpdateBlurUI(this.value)" data-sui-onchange="suiSaveConfig()"></div>
            <span id="sui-blur-val" style="font-weight:700; color:var(--primary); min-width:40px;">8px</span>
        </div>
    </div>
    <div class="setting-item vertical">
        <label class="setting-label">Icon Stroke Weight</label>
        <div class="setting-desc">Adjust the thickness of system-wide icons for elegance.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <div data-sui-slider="true" data-sui-id="sui-stroke-slider" data-sui-min="1" data-sui-max="3" data-sui-step="0.1" data-sui-value="stroke" data-sui-oninput="suiUpdateStrokeUI(this.value)" data-sui-onchange="suiSaveConfig()"></div>
            <span id="sui-stroke-val" style="font-weight:700; color:var(--primary); min-width:40px;">1.5</span>
        </div>
    </div>

    <div id="sui-save-status" style="text-align:right; font-size:11px; color:#8E8E93; padding:0 16px 8px; height:14px;"></div>

    <div class="setting-item vertical" style="border-top: 1px solid var(--border-color); margin-top: 8px; padding-top: 16px;">
        <label class="setting-label">Developer Reference</label>
        <div class="setting-desc">Reusable components available for other plugins. Tap to preview:</div>
        
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:8px;">
            <button onclick="suiPreviewPicker()" class="text-btn" style="background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:12px; font-weight:600; border:1px solid var(--border-color);">
                Pop Picker
            </button>
            <button onclick="suiPreviewInput()" class="text-btn" style="background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:12px; font-weight:600; border:1px solid var(--border-color);">
                Text Input
            </button>
            <button onclick="suiPreviewConfirm(false)" class="text-btn" style="background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:12px; font-weight:600; border:1px solid var(--border-color);">
                Confirm (Normal)
            </button>
            <button onclick="suiPreviewConfirm(true)" class="text-btn" style="background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:12px; font-weight:600; border:1px solid var(--border-color);">
                Confirm (Danger)
            </button>
            <button onclick="suiPreviewIcons()" class="text-btn" style="grid-column: span 2; background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:12px; font-weight:600; border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; gap:6px;">
                <span data-sui-icon="grid" data-sui-size="14"></span> Icon Library
            </button>
        </div>

        <label class="setting-desc" style="margin-top:12px; font-weight:700; display:block;">Haptic Pattern Lab (Mobile Only)</label>
        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:8px; margin-top:8px;">
            <button onclick="window.sui.haptic('light')" class="text-btn" style="background:var(--btn-bg); font-size:10px; padding:8px; border-radius:8px; border:1px solid var(--border-color);">Light</button>
            <button onclick="window.sui.haptic('medium')" class="text-btn" style="background:var(--btn-bg); font-size:10px; padding:8px; border-radius:8px; border:1px solid var(--border-color);">Medium</button>
            <button onclick="window.sui.haptic('heavy')" class="text-btn" style="background:var(--btn-bg); font-size:10px; padding:8px; border-radius:8px; border:1px solid var(--border-color);">Heavy</button>
            <button onclick="window.sui.haptic('success')" class="text-btn" style="background:var(--success-bg); color:var(--success-text); font-size:10px; padding:8px; border-radius:8px; border:1px solid var(--border-color);">Success</button>
            <button onclick="window.sui.haptic('notify')" class="text-btn" style="background:var(--selected-bg); color:var(--selected-text); font-size:10px; padding:8px; border-radius:8px; border:1px solid var(--border-color);">Notify</button>
            <button onclick="window.sui.haptic('error')" class="text-btn" style="background:var(--warn-bg); color:var(--danger); font-size:10px; padding:8px; border-radius:8px; border:1px solid var(--border-color);">Error</button>
        </div>

        <div style="margin-top:12px; padding:10px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Hardware Stress Test</span>
                <span id="sui-haptic-val" style="font-size:12px; font-weight:800; color:var(--primary);">50ms</span>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <input type="range" min="1" max="500" value="50" style="flex:1;" oninput="document.getElementById('sui-haptic-val').innerText = this.value + 'ms'" onchange="window.sui.haptic(parseInt(this.value))">
                <button onclick="window.sui.haptic(parseInt(document.querySelector('#sui-haptic-val').innerText))" style="background:var(--primary); color:white; border:none; border-radius:6px; padding:4px 8px; font-size:10px; font-weight:700;">TEST</button>
            </div>
        </div>

        <div style="margin-top:12px; background:rgba(0,0,0,0.03); padding:10px; border-radius:8px; font-family:monospace; font-size:10px; color:var(--text-secondary); line-height:1.4;">
            window.openPicker(title, options, currentVal, onSelect, searchable)<br><br>
            window.openInput(title, placeholder, defaultVal, callback)<br><br>
            window.openConfirm(title, message, onConfirm, isDanger)
        </div>
    </div>
HTML;

if(!isset($plugin_overlays)) $plugin_overlays = [];

$plugin_overlays[] = <<<'HTML'
<style>
    .shared-menu-overlay { opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s; }
    .shared-menu-overlay.visible { opacity: 1; visibility: visible; }
    .shared-menu-overlay.visible .shared-bottom-sheet { transform: translate3d(0, 0, 0); }
    .shared-bottom-sheet { 
        transform: translate3d(0, 100%, 0); 
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); 
        will-change: transform;
    }
    /* DEBUG: SAFE ZONE VISUALIZATION */
    body.sui-debug-active .shared-bottom-sheet::after,
    body.sui-debug-active .settings-sheet::after {
        content: 'DEBUG: SAFE ZONE';
        background: rgba(255, 59, 48, 0.15);
        border-top: 2px dashed rgba(255, 59, 48, 0.4);
        display: flex; align-items: center; justify-content: center;
        font-size: 10px; font-weight: 900; color: rgba(255, 59, 48, 0.6);
    }
    /* Standard Accordion Mechanics */
    .sui-accordion {
        display: grid; 
        grid-template-rows: 0fr; 
        transition: grid-template-rows 0.35s cubic-bezier(0.33, 1, 0.68, 1), opacity 0.35s ease; 
        /* Keep at 0.01 to ensure the browser keeps the element in the paint tree, preventing 'pop-in' */
        opacity: 0.01;
        will-change: grid-template-rows, opacity;
        /* Isolation prevents internal shifts from affecting the parent's backdrop-filter context */
        isolation: isolate;
        contain: content;
        transform: translate3d(0,0,0);
        backface-visibility: hidden;
    }
    .sui-accordion.open { grid-template-rows: 1fr; opacity: 1; }
    .sui-accordion-inner { overflow: hidden; }
    /* Ensure icon containers are centered */
    [data-sui-icon] {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        vertical-align: middle;
        line-height: 0;
    }
    /* Shared Primary Button Style */
    .btn-primary {
        background: var(--primary);
        color: var(--primary-text);
        border: none;
        padding: 14px;
        border-radius: 16px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 4px 12px rgba(0,122,255,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .btn-primary:active { transform: scale(0.98); opacity: 0.9; }
    .btn-primary.danger {
        background: var(--danger);
        box-shadow: 0 4px 12px rgba(255, 59, 48, 0.2);
        color: var(--primary-text);
    }
    /* REORDER GLOW ANIMATION */
    @keyframes sui-fs-reorder-glow {
        0% { background: var(--ai-accent-bg); box-shadow: 0 0 15px color-mix(in srgb, var(--ai-accent), transparent 50%); border-color: var(--ai-accent); transform: scale(1.02); }
        20% { background: var(--ai-accent-bg); box-shadow: 0 0 20px var(--ai-accent); border-color: var(--ai-accent); transform: scale(1.02); }
        100% { background: var(--btn-bg); box-shadow: none; border-color: var(--border-color); transform: scale(1); }
    }
    .ce-reorder-glow { 
        animation: sui-fs-reorder-glow 1.5s cubic-bezier(0.2, 0, 0.2, 1) forwards; 
        z-index: 10;
        position: relative;
    }
    /* GLOBAL ICON REACTIVITY */
    svg, [data-sui-icon] svg, .fcb-btn svg, .fab svg {
        stroke-width: var(--sui-icon-stroke, 1.5) !important;
    }
    /* DRAG AND DROP STYLES */
    .sui-fs-dragging {
        opacity: 0.5 !important;
        transform: scale(0.98);
        filter: grayscale(1);
        pointer-events: none;
    }
    .sui-fs-drag-ghost {
        position: fixed;
        pointer-events: none;
        z-index: 9999;
        opacity: 0.9;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        background: var(--card-bg);
        border: 2px solid var(--ai-accent);
        border-radius: 12px;
        transform: scale(1.05);
    }
    .sui-fs-drop-line {
        position: absolute;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--ai-accent);
        z-index: 20;
        pointer-events: none;
        border-radius: 2px;
        box-shadow: 0 0 10px var(--ai-accent);
        display: none; /* Hidden until first move */
    }
    /* Lock scrolling only when drag is active */
    .sui-fs-container-dragging {
        touch-action: none !important;
        user-select: none !important;
        -webkit-user-select: none !important;
    }
    .sui-fs-drop-line::before {
        content: '';
        position: absolute;
        left: -6px;
        top: -4px;
        width: 10px;
        height: 10px;
        background: var(--ai-accent);
        border-radius: 50%;
    }
</style>
<div id="shared-picker-overlay" class="shared-menu-overlay" style="z-index:9600;">
    <div id="shared-picker-sheet" class="shared-bottom-sheet">
        <!-- Header -->
        <div style="display:flex; justify-content:space-between; align-items:center; padding:20px 24px; background:var(--bg-color); border-bottom:1px solid var(--border-color);">
            <div style="display:flex; align-items:center; gap:10px; min-width:0; flex:1;">
                <div id="shared-picker-title" style="font-size:18px; font-weight:700; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Select Option</div>
                <div id="shared-picker-header-extra" style="display:flex; gap:6px; align-items:center;"></div>
            </div>
            <button onclick="closeSharedPicker()" class="sui-close-trigger" style="background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0;">
                <span data-sui-icon="close" data-sui-size="18" data-sui-stroke="2.5"></span>
            </button>
        </div>
        
        <!-- Search Bar (Optional) -->
        <div id="shared-picker-search-container" style="display:none; padding: 12px 24px; border-bottom: 1px solid rgba(0,0,0,0.05);">
            <div style="position:relative; display:flex; align-items:center; background:var(--input-bg); border:1px solid var(--border-color); border-radius:10px; padding:8px 12px;">
                <span data-sui-icon="search" data-sui-color="var(--text-secondary)" data-sui-size="16" data-sui-stroke="2.5" style="margin-right:8px; display:flex;"></span>
                <input type="text" id="shared-picker-search-input" placeholder="Search..." style="border:none; background:transparent; font-size:15px; width:100%; outline:none; padding:0; color:var(--input-text);">
            </div>
        </div>
        
        <!-- Options List -->
        <div id="shared-picker-list" style="overflow-y:auto; padding:16px 24px 0 24px; display:flex; flex-direction:column; gap:8px;">
            <!-- Items injected via JS -->
        </div>
    </div>
</div>

<div id="shared-input-overlay" class="shared-menu-overlay" style="z-index:9700; background:none; backdrop-filter:none;">
    <div id="shared-input-sheet" class="shared-bottom-sheet">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:20px 24px; background:var(--bg-color); border-bottom:1px solid var(--border-color); margin-bottom:20px;">
            <div id="shared-input-title" style="font-size:18px; font-weight:700; color:var(--text-primary);">Enter Value</div>
            <button onclick="closeInput()" style="background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:18px; height:18px; stroke-width:2.5;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="sui-sz-padded" style="padding: 0 24px 32px 24px;">
            <textarea id="shared-input-field" style="width:100%; padding:14px; border-radius:12px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text); font-size:17px; box-sizing:border-box; outline-color:var(--primary); margin-bottom:20px; min-height:50px; max-height:40vh; resize:none; display:block; line-height:1.5;"></textarea>
            <button id="shared-input-submit-btn" onclick="submitSharedInput()" class="btn-primary" style="width:100%; font-size:16px;">Save Changes</button>
        </div>
    </div>
</div>

<div id="shared-confirm-overlay" class="shared-menu-overlay" style="z-index:9800; background:none; backdrop-filter:none; display: flex; align-items: center; justify-content: center;">
    <div id="shared-confirm-sheet" style="width: 85%; max-width: 320px; background: var(--card-bg); border-radius: 28px; padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); transform: scale(0.9); opacity: 0; transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); pointer-events: auto; border: 1px solid var(--border-color); display: flex; flex-direction: column; max-height: 80vh;">
        <div id="shared-confirm-title" style="font-size: 19px; font-weight: 800; color: var(--text-primary); margin-bottom: 12px; text-align: center; flex-shrink: 0;">Confirm Action</div>
        <div id="shared-confirm-msg" style="font-size: 15px; color: var(--text-secondary); line-height: 1.5; margin-bottom: 24px; text-align: center; overflow-y: auto; flex: 1; -webkit-overflow-scrolling: touch;">Are you sure you want to proceed?</div>
        <div id="shared-confirm-btn-container" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <button onclick="closeConfirm()" class="text-btn" style="font-size: 15px; border-radius: 16px;">Cancel</button>
            <button id="shared-confirm-alt-btn" class="btn-primary" style="font-size: 15px; background: var(--btn-bg); color: var(--text-primary); border: 1px solid var(--border-color); box-shadow: none; display: none;">Alternative</button>
            <button id="shared-confirm-btn" class="btn-primary" style="font-size: 15px;">Confirm</button>
        </div>
    </div>
</div>
HTML;

// JS API
$plugin_js .= <<<'JS'
// --- SHARED CONFIRM LOGIC ---
const confirmOverlay = document.getElementById("shared-confirm-overlay");
const confirmSheet = document.getElementById("shared-confirm-sheet");
const confirmTitle = document.getElementById("shared-confirm-title");
const confirmMsg = document.getElementById("shared-confirm-msg");
const confirmBtn = document.getElementById("shared-confirm-btn");
let currentConfirmCallback = null;
let currentCancelCallback = null;

window.openConfirm = function(title, message, onConfirm, isDanger = false, confirmLabel = "Confirm", cancelLabel = "Cancel", onCancel = null, altLabel = null, onAlt = null) {
    if(!confirmOverlay) return;
    if (window.sui) {
        window.sui.registerOverlay('shared-confirm-overlay', window.closeConfirm);
        confirmOverlay.style.zIndex = window.sui.getNextZIndex().toString();
    }
    confirmTitle.innerText = title;
    confirmMsg.innerHTML = message;
    currentConfirmCallback = onConfirm;
    currentCancelCallback = onCancel;

    confirmBtn.innerText = confirmLabel;
    const cancelBtn = confirmOverlay.querySelector('button[onclick="closeConfirm()"]');
    const altBtn = document.getElementById("shared-confirm-alt-btn");
    const btnContainer = document.getElementById("shared-confirm-btn-container");

    if (altBtn && btnContainer) {
        if (altLabel) {
            altBtn.style.setProperty('display', 'flex', 'important');
            altBtn.innerText = altLabel;
            altBtn.onclick = async () => {
                try { if (onAlt) await onAlt(); } catch(e) { console.error("[SUI] Alt Callback Error:", e); } finally { closeConfirm(); }
            };
            btnContainer.style.setProperty('display', 'flex', 'important');
            btnContainer.style.setProperty('flex-direction', 'column', 'important');
            btnContainer.style.setProperty('gap', '8px', 'important');
            if (cancelBtn) {
                cancelBtn.style.setProperty('display', cancelLabel === null ? 'none' : 'block', 'important');
                if (cancelLabel) cancelBtn.innerText = cancelLabel;
            }
        } else {
            altBtn.style.setProperty('display', 'none', 'important');
            if (cancelBtn) {
                if (cancelLabel === null) {
                    cancelBtn.style.setProperty('display', 'none', 'important');
                    btnContainer.style.setProperty('display', 'grid', 'important');
                    btnContainer.style.setProperty('grid-template-columns', '1fr', 'important');
                    btnContainer.style.setProperty('gap', '12px', 'important');
                } else {
                    cancelBtn.style.setProperty('display', 'block', 'important');
                    btnContainer.style.setProperty('display', 'grid', 'important');
                    btnContainer.style.setProperty('grid-template-columns', '1fr 1fr', 'important');
                    btnContainer.style.setProperty('gap', '12px', 'important');
                    cancelBtn.innerText = cancelLabel;
                }
            }
        }
    }

    // Remove old state
    confirmBtn.classList.remove('danger');
    if (isDanger) confirmBtn.classList.add('danger');
    
    confirmBtn.onclick = async () => {
        try {
            if(currentConfirmCallback) await currentConfirmCallback();
        } catch(e) {
            console.error("[SUI] Confirm Callback Error:", e);
        } finally {
            closeConfirm();
        }
    };

    // Override the cancel button for this instance
    if (cancelBtn) {
        cancelBtn.onclick = () => {
            if(currentCancelCallback) currentCancelCallback();
            closeConfirm();
        };
    }

    confirmOverlay.style.visibility = "visible";
    confirmOverlay.style.opacity = "1";
    requestAnimationFrame(() => {
        confirmSheet.style.transform = "scale(1)";
        confirmSheet.style.opacity = "1";
    });
};

window.suiToggle = function(id, save = false) {
    const el = document.getElementById(id);
    if (!el) return;
    const arrow = document.querySelector(`[data-sui-arrow="${id}"]`);
    const isOpen = el.classList.contains('open');
    const newState = isOpen ? 'closed' : 'open';

    if (isOpen) {
        el.classList.remove('open');
        if (arrow) arrow.style.transform = 'rotate(-90deg)';
        setTimeout(() => {
            if (!el.classList.contains('open')) el.style.display = 'none';
        }, 350);
    } else {
        el.style.display = '';
        void el.offsetHeight;
        el.classList.add('open');
        if (arrow) arrow.style.transform = 'rotate(0deg)';
    }

    if (save && window.updateServerUiState) {
        updateServerUiState('sections', 'cjos_sec_' + id, newState);
    }
    localStorage.setItem('cjos_sec_' + id, newState);
    if (window.sui && window.sui.haptic) window.sui.haptic('light');
};

window.suiInit = function(id) {
    const el = document.getElementById(id);
    const arrow = document.querySelector(`[data-sui-arrow="${id}"]`);
    if (!el || !arrow) return;
    const saved = localStorage.getItem('cjos_sec_' + id);
    if (saved === 'closed') {
        el.classList.remove('open');
        el.style.display = 'none';
        arrow.style.transform = 'rotate(-90deg)';
    } else if (saved === 'open') {
        el.style.display = '';
        el.classList.add('open');
        arrow.style.transform = 'rotate(0deg)';
    }
};

window.suiAccordion = function(id, title, contentHtml, isOpen = false) {
    return `
        <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); cursor:pointer;" onclick="suiToggle('${id}')">
            <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">${title}</div>
            <span data-sui-icon="chevron" data-sui-arrow="${id}" data-sui-size="14" style="transition:transform 0.35s; transform: rotate(${isOpen ? '0' : '-90'}deg);"></span>
        </div>
        <div id="${id}" class="sui-accordion ${isOpen ? 'open' : ''}" style="${isOpen ? '' : 'display: none;'}">
            <div class="sui-accordion-inner">${contentHtml}</div>
        </div>
    `;
};

window.closeConfirm = function() {
    if (window.sui) window.sui.unregisterOverlay('shared-confirm-overlay');
    if (confirmSheet) {
        confirmSheet.style.transform = "scale(0.9)";
        confirmSheet.style.opacity = "0";
    }
    if (confirmOverlay) {
        confirmOverlay.style.opacity = "0";
        // Restore default cancel button behavior safely
        const btns = confirmOverlay.querySelectorAll('button');
        btns.forEach(btn => {
            if (btn.innerText === "Cancel" || btn.getAttribute('onclick')?.includes('closeConfirm')) {
                btn.onclick = () => closeConfirm();
            }
        });
    }

    // Programmatic Style Reset: Guarantee subsequent dialogs render using clean 2-column defaults
    const altBtn = document.getElementById("shared-confirm-alt-btn");
    const btnContainer = document.getElementById("shared-confirm-btn-container");
    if (altBtn) altBtn.style.setProperty('display', 'none', 'important');
    if (btnContainer) {
        btnContainer.style.setProperty('display', 'grid', 'important');
        btnContainer.style.setProperty('grid-template-columns', '1fr 1fr', 'important');
        btnContainer.style.setProperty('gap', '12px', 'important');
        btnContainer.style.removeProperty('flex-direction');
    }

    setTimeout(() => { 
        if (confirmOverlay) confirmOverlay.style.visibility = "hidden"; 
    }, 300);
};





// --- SHARED UI CONFIG LOGIC ---
let suiConfig = { dim: 60, blur: 8, gap: 16, stroke: 1.5 };

window.addEventListener("load", suiLoadConfig);

async function suiLoadConfig() {
    try {
        const data = await window.sui.api("sui_get_config", {}, { toast: false });
        if (data) {
            suiConfig = data.config;
            suiApplyConfig(true);
            
            const debugToggle = document.getElementById('sui-debug-toggle');
            if (debugToggle) debugToggle.checked = !!suiConfig.debug;
            document.body.classList.toggle('sui-debug-active', !!suiConfig.debug);
        }
    } catch(e) {}
}

function suiApplyConfig(updateSliders = false) {
    // Ensure values are numbers to prevent "undefined" or string concatenation issues
    const dim = parseFloat(suiConfig.dim) || 60;
    const blur = parseFloat(suiConfig.blur) || 8;
    const stroke = parseFloat(suiConfig.stroke) || 1.5;

    document.documentElement.style.setProperty('--overlay-dim', dim / 100);
    document.documentElement.style.setProperty('--overlay-blur', blur + 'px');
    document.documentElement.style.setProperty('--sui-icon-stroke', stroke);

    const dimLabel = document.getElementById("sui-dim-val");
    const blurLabel = document.getElementById("sui-blur-val");
    const strokeLabel = document.getElementById("sui-stroke-val");
    
    if(dimLabel) dimLabel.innerText = dim + "%";
    if(blurLabel) blurLabel.innerText = blur + "px";
    if(strokeLabel) strokeLabel.innerText = stroke.toFixed(1);

    if (updateSliders) {
        const dimSlider = document.getElementById("sui-dim-slider");
        const blurSlider = document.getElementById("sui-blur-slider");
        const strokeSlider = document.getElementById("sui-stroke-slider");
        
        if(dimSlider) { 
            dimSlider.value = dim; 
            dimSlider.style.setProperty('--range-pct', ((dim - 0) / (100 - 0)) * 100 + '%');
        }
        if(blurSlider) { 
            blurSlider.value = blur; 
            blurSlider.style.setProperty('--range-pct', ((blur - 0) / (25 - 0)) * 100 + '%');
        }
        if(strokeSlider) {
            strokeSlider.value = stroke;
            // Math: (current - min) / (max - min)
            const pct = ((stroke - 1) / (3 - 1)) * 100;
            strokeSlider.style.setProperty('--range-pct', pct + '%');
        }
    }
}

window.suiUpdateDimUI = function(val) {
    suiConfig.dim = val;
    suiApplyConfig();
};

window.suiUpdateBlurUI = function(val) {
    suiConfig.blur = val;
    suiApplyConfig();
};

window.suiUpdateStrokeUI = function(val) {
    suiConfig.stroke = val;
    suiApplyConfig();
};

window.suiSaveConfig = async function() {
    await window.sui.api("sui_save_config", { 
        dim: suiConfig.dim, 
        blur: suiConfig.blur,
        stroke: suiConfig.stroke
    });
};
JS;

$plugin_js .=  <<<'JS'
// --- CENTRALIZED OVERLAY DISMISSAL ---
// Automatically handles background taps for any plugin using the 'shared-menu-overlay' class.
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('shared-menu-overlay')) {
        // Use the centralized dismissal logic to ensure the JS stack stays in sync
        if (window.sui && typeof window.sui.dismissTopOverlay === 'function') {
            window.sui.dismissTopOverlay();
        } else {
            const closeBtn = e.target.querySelector('button[onclick*="close"], button[onclick*="Close"], button[onclick*="amClose"], button[onclick*="Hide"], .settings-close, .sui-studio-close');
            if (closeBtn) closeBtn.click();
        }
    }
});

// --- SHARED ICON SYSTEM ---
window.suiIcon = function(name, color = 'currentColor', size = 16, stroke = null) {
    // We now use CSS to control stroke-width globally. 
    // We only set it here as a fallback or for specific overrides.
    const finalStroke = stroke || 'inherit';
    
    // 1. Check Warm Cache (Previously fetched icons now on server disk)
    if (window.__SUI_ICON_CACHE__ && window.__SUI_ICON_CACHE__[name]) {
        let svg = window.__SUI_ICON_CACHE__[name];
        // Apply dynamic attributes to the cached string
        svg = svg.replace(/width="[0-9]+"/, `width="${size}"`);
        svg = svg.replace(/height="[0-9]+"/, `height="${size}"`);
        svg = svg.replace(/stroke="[^"]+"/, `stroke="${color}"`);
        svg = svg.replace(/stroke-width="[0-9.]+"/, `stroke-width="${finalStroke}"`);
        // Ensure it doesn't have internal fixed styles that override our attributes
        svg = svg.replace('style="', 'style="display:block;');
        if (!svg.includes('style=')) svg = svg.replace('<svg', '<svg style="display:block;"');
        return svg;
    }

    const remoteIcons = ['hammer', 'file-text', 'image', 'music', 'video', 'file-code', 'palette', 'file', 'list'];
    const icons = {
        search: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="display:block; width:${size}px; height:${size}px; stroke-width:${finalStroke}; flex-shrink:0; pointer-events:none;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>`,
        close: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${finalStroke}; flex-shrink:0; pointer-events:none;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>`,
        chevron: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${finalStroke}; flex-shrink:0; pointer-events:none;"><polyline points="6 9 12 15 18 9"></polyline></svg>`,
        trash: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>`,
        plus: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>`,
        edit: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>`,
        check: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><polyline points="20 6 9 17 4 12"></polyline></svg>`,
        star: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>`,
        undo: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>`,
        'rotate-ccw': `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>`,
        save: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>`,
        folder: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>`,
        calendar: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>`,
        shield: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>`,
        grid: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>`,

        activity: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>`,
        flame: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path></svg>`,
        layers: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 12 12 17 22 12"></polyline><polyline points="2 17 12 22 22 17"></polyline></svg>`,
        layout: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line><line x1="15" y1="3" x2="15" y2="21"></line></svg>`,
        sliders: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="12"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>`,
        info: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>`,
        clock: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>`,
        sun: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>`,
        moon: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>`,
        export: `<svg viewBox="0 0 256 256" fill="${color}" style="width:${size}px; height:${size}px; flex-shrink:0; pointer-events:none;"><path d="M136,88H120V35.3103L91.65625,63.64648A7.99983,7.99983,0,1,1,80.34375,52.332l42-41.98926a8.00063,8.00063,0,0,1,11.3125,0l42,41.98926a7.99983,7.99983,0,1,1-11.3125,11.31445L136,35.3103Zm64,0H136v40a8,8,0,0,1-16,0V88H56a16.01833,16.01833,0,0,0-16,16V208a16.01833,16.01833,0,0,0,16,16H200a16.01833,16.01833,0,0,0,16-16V104A16.01833,16.01833,0,0,0,200,88Z"/></svg>`,
        copy: `<svg viewBox="0 0 24 24" fill="${color}" style="width:${size}px; height:${size}px; flex-shrink:0; pointer-events:none;"><path d="M8,7 L8,8 L6.5,8 C5.67157288,8 5,8.67157288 5,9.5 L5,18.5 C5,19.3284271 5.67157288,20 6.5,20 L13.5,20 C14.3284271,20 15,19.3284271 15,18.5 L15,17 L16,17 L16,18.5 C16,19.8807119 14.8807119,21 13.5,21 L6.5,21 C5.11928813,21 4,19.8807119 4,18.5 L4,9.5 C4,8.11928813 5.11928813,7 6.5,7 L8,7 Z M16,4 L10.5,4 C9.67157288,4 9,4.67157288 9,5.5 L9,14.5 C9,15.3284271 9.67157288,16 10.5,16 L17.5,16 C18.3284271,16 19,15.3284271 19,14.5 L19,7 L16.5,7 C16.2238576,7 16,6.77614237 16,6.5 L16,4 Z M20,6.52797748 L20,14.5 C20,15.8807119 18.8807119,17 17.5,17 L10.5,17 C9.11928813,17 8,15.8807119 8,14.5 L8,5.5 C8,4.11928813 9.11928813,3 10.5,3 L16.4720225,3 C16.6047688,2.99158053 16.7429463,3.03583949 16.8535534,3.14644661 L19.8535534,6.14644661 C19.9641605,6.25705373 20.0084195,6.39523125 20,6.52797748 Z M17,6 L18.2928932,6 L17,4.70710678 L17,6 Z M11.5,13 C11.2238576,13 11,12.7761424 11,12.5 C11,12.2238576 11.2238576,12 11.5,12 L13.5,12 C13.7761424,12 14,12.2238576 14,12.5 C14,12.7761424 13.7761424,13 13.5,13 L11.5,13 Z M11.5,11 C11.2238576,11 11,10.7761424 11,10.5 C11,10.2238576 11.2238576,10 11.5,10 L16.5,10 C16.7761424,10 17,10.2238576 17,10.5 C17,10.7761424 16.7761424,11 16.5,11 L11.5,11 Z M11.5,9 C11.2238576,9 11,8.77614237 11,8.5 C11,8.22385763 11.2238576,8 11.5,8 L16.5,8 C16.7761424,8 17,8.22385763 17,8.5 C17,8.77614237 16.7761424,9 16.5,9 L11.5,9 Z"/></svg>`,
        download: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>`,
        'shield-check': `<svg viewBox="0 0 1024 1024" fill="${color}" style="width:${size}px; height:${size}px; flex-shrink:0; pointer-events:none;"><path d="M512 64L128 192v384c0 212.1 171.9 384 384 384s384-171.9 384-384V192L512 64zm312 512c0 172.3-139.7 312-312 312S200 748.3 200 576V246l312-110 312 110v330z"/><path d="M378.4 475.1a35.91 35.91 0 0 0-50.9 0 35.91 35.91 0 0 0 0 50.9l129.4 129.4 2.1 2.1a33.98 33.98 0 0 0 48.1 0L730.6 434a33.98 33.98 0 0 0 0-48.1l-2.8-2.8a33.98 33.98 0 0 0-48.1 0L483 579.7 378.4 475.1z"/></svg>`,
        package: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="m7.5 4.27 9 5.15"></path><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>`,
        box: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; flex-shrink:0; pointer-events:none;"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>`,
        gauge: `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" style="width:${size}px; height:${size}px; stroke-width:${stroke}; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; pointer-events:none;"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></svg>`
    };
    if (name === '__KEYS__') return [...Object.keys(icons), ...remoteIcons];
    if (icons[name]) return icons[name];
    return `<span data-sui-icon-name="${name}" data-sui-icon-color="${color}" data-sui-icon-size="${size}" data-sui-icon-stroke="${stroke}"></span>`;
};

window.suiHydrateIcons = function(container = document) {
    container.querySelectorAll('[data-sui-icon]').forEach(el => {
        const name = el.getAttribute('data-sui-icon');
        const color = el.getAttribute('data-sui-color') || 'currentColor';
        const size = el.getAttribute('data-sui-size') || 16;
        const stroke = el.getAttribute('data-sui-stroke') || 2;
        el.innerHTML = window.suiIcon(name, color, size, stroke);
    });

    container.querySelectorAll('[data-sui-icon-name]').forEach(async el => {
        const name = el.getAttribute('data-sui-icon-name');
        const color = el.getAttribute('data-sui-icon-color');
        const size = el.getAttribute('data-sui-icon-size');
        const stroke = el.getAttribute('data-sui-icon-stroke');
        
        try {
            const res = await fetch(`index.php?plugin_action=sui_icon_proxy&name=${name}`);
            const svgText = await res.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(svgText, 'image/svg+xml');
            const svg = doc.querySelector('svg');
            if (svg) {
                svg.setAttribute('width', size);
                svg.setAttribute('height', size);
                svg.setAttribute('stroke', color);
                svg.setAttribute('stroke-width', stroke);
                svg.style.display = 'block';
                el.replaceWith(svg);
            }
        } catch(e) { console.error("Icon load failed", e); }
    });
};

window.suiSwitch = function(id, checked = false, onchange = '', extraAttr = '') {
    const isDisabled = extraAttr.includes('disabled');
    return `<label class="switch" ${extraAttr}><input type="checkbox" id="${id}" ${checked ? 'checked' : ''} ${isDisabled ? 'disabled' : ''} onchange="${onchange}"><span class="slider"></span></label>`;
};

window.suiSlider = function(id, min, max, step, val, oninput, onchange = '', color = 'var(--primary)') {
    const pct = ((val - min) / (max - min)) * 100;
    const style = `--range-fill: ${color}; --range-pct: ${pct}%; cursor: pointer;`;
    return `<input type="range" id="${id}" min="${min}" max="${max}" step="${step}" value="${val}" 
        oninput="this.style.setProperty('--range-pct', ((this.value - ${min}) / (${max} - ${min})) * 100 + '%'); ${oninput}" 
        onchange="${onchange}" 
        style="${style}">`;
};

window.suiSettingRow = function(title, desc, actionHtml, vertical = false) {
    return `<div class="setting-item ${vertical ? 'vertical' : ''}"><div class="setting-text-wrap"><label class="setting-label">${title}</label><span class="setting-desc">${desc}</span></div>${actionHtml}</div>`;
};

window.suiSpinner = function(size = 30) {
    return `<div class="spinner" style="width:${size}px; height:${size}px; border-width:${Math.max(2, size/10)}px; border-radius:50%; margin:0; flex-shrink:0; display:block; box-sizing:border-box;"></div>`;
};

window.suiSkeleton = function(lines = 3) {
    let html = '<div style="padding: 4px 0;">';
    for(let i=0; i<lines; i++) {
        const isShort = (i === lines - 1 && lines > 1);
        html += `<span class="skel-line ${isShort ? 'short' : ''}"></span>`;
    }
    html += '</div>';
    return html;
};

window.suiEmptyState = function(icon, message) {
    return `<div class="sui-empty-state" style="text-align:center; padding:40px 20px; color:var(--text-secondary); opacity:0.6;">
        <div style="font-size:32px; margin-bottom:12px;">${icon}</div>
        <div style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:1px;">${message}</div>
    </div>`;
};

window.suiBadge = function(text, type = 'default', extraClass = '') {
    const b = document.createElement("span");
    b.className = `meta-badge sui-badge-${type} ${extraClass}`;
    b.innerHTML = text;
    return b;
};

window.sui = {
    _maxZIndex: 10000,
    getNextZIndex: function() {
        return ++this._maxZIndex;
    },
    api: async function(action, data = {}, options = {}) {
        const defaults = {
            method: 'POST',
            toast: true,      
            errorToast: true, 
            endpoint: 'index.php',
            plugin: null,
            caller: null,
            metrics: null
        };
        const opt = { ...defaults, ...options };

        // Auto-detect caller if not provided
        if (!opt.caller) {
            const stack = new Error().stack.split('\n');
            const callerLine = stack[2] || ''; 
            const callerMatch = callerLine.match(/at\s+(.*)\s+\(/) || callerLine.match(/at\s+(.*)$/);
            opt.caller = callerMatch ? callerMatch[1].split('.').pop() : 'api';
        }
        
        const fd = new FormData();
        fd.append('plugin_action', action);
        
        for (const k in data) {
            const val = data[k];
            fd.append(k, (typeof val === 'object' && val !== null) ? JSON.stringify(val) : val);
        }
        
        try {
            const res = await fetch(opt.endpoint, { method: opt.method, body: fd });
            const contentType = res.headers.get("content-type");
            
            if (!contentType || !contentType.includes("application/json")) {
                const text = await res.text();
                const isShield = text.includes("shield-box");
                throw new Error(isShield ? "System Shield Intercepted: A backend crash occurred." : "Server returned non-JSON response.");
            }

            const json = await res.json();
            
            if (json.status === 'success' || json.status === 'ghosted' || json.status === 'backgrounded') {
                if (opt.toast) {
                    window.sui.toast((typeof opt.toast === 'string') ? opt.toast : "Changes Saved", {
                        plugin: opt.plugin,
                        caller: opt.caller,
                        metrics: opt.metrics || data
                    });
                }
                return json;
            } else {
                throw new Error(json.message || `Server error during "${action}"`);
            }
        } catch (e) {
            console.error(`[SUI-API] Action "${action}" failed:`, e);
            if (opt.errorToast) {
                let msg = e.message;
                if (msg === "Failed to fetch") msg += " (Check HTTPS/Firewall)";
                
                window.sui.toast("Error: " + msg, {
                    plugin: opt.plugin || 'System',
                    caller: opt.caller,
                    metrics: { error: e.message, action: action, data: data }
                });
            }
            throw e; 
        }
    },

    toast: function(msg, meta = {}) {
        const t = document.getElementById("toast");
        if (!t) return;

        // 1. Set Content
        t.innerText = msg;

        // 2. Attach Metadata for FabToaster History
        if (meta.plugin) t.dataset.plugin = meta.plugin;
        else delete t.dataset.plugin;

        if (meta.caller) t.dataset.caller = meta.caller;
        else delete t.dataset.caller;

        if (meta.metrics) {
            t.dataset.metrics = (typeof meta.metrics === 'object') ? JSON.stringify(meta.metrics) : meta.metrics;
        } else {
            delete t.dataset.metrics;
        }

        // 3. Trigger Visibility (FabToaster's MutationObserver picks this up)
        t.classList.remove("show");
        void t.offsetWidth; 
        t.classList.add("show");

        // 4. Legacy Auto-Hide (FabToaster usually handles this, but we keep it for safety)
        if (window._suiToastTimer) clearTimeout(window._suiToastTimer);
        window._suiToastTimer = setTimeout(() => t.classList.remove("show"), 3000);
    },

    badges: [],
    registerBadge: function(id, renderFn, priority = 50) {
        this.badges.push({ id, render: renderFn, priority });
        this.badges.sort((a, b) => a.priority - b.priority);
    },
    decorateCard: function(card, entry) {
        const content = card.querySelector('.card-content');
        if (!content) return;
        const row = window.getMetaContainer(content);
        if (!row) return;

        this.badges.forEach(provider => {
            try {
                // De-duplication: Remove existing badges from this provider
                row.querySelectorAll(`.${provider.id}`).forEach(el => el.remove());

                const result = provider.render(entry, card);
                
                if (result) {
                    const badges = Array.isArray(result) ? result : [result];
                    badges.forEach(badge => {
                        if (badge instanceof HTMLElement) {
                            badge.classList.add(provider.id);
                            badge.setAttribute('data-sui-managed', 'true');
                            row.appendChild(badge);
                        }
                    });
                }
            } catch(e) { console.error(`[SUI] Badge Provider "${provider.id}" failed:`, e); }
        });
        if (typeof window.loApplyStyles === 'function') window.loApplyStyles();
    },
    // --- STUDIO FACTORY ---
    _studioTimers: {},
    openStudio: function(config) {
        // Cancel any pending close-animations for this ID
        if (this._studioTimers[config.id]) {
            clearTimeout(this._studioTimers[config.id]);
            delete this._studioTimers[config.id];
        }

        // Config: { id, title, content, onSave, onClose, onSetup, hasChanges }
        let overlay = document.getElementById(`sui-studio-${config.id}`);
        
        if (overlay) {
            delete overlay.dataset.isClosing;
        }

        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = `sui-studio-${config.id}`;
            overlay.className = 'shared-menu-overlay';
            
            overlay.innerHTML = `
                <div class="shared-bottom-sheet" style="max-height:92vh; display:flex; flex-direction:column;">
                    <div style="padding:20px 24px; background:var(--sui-header-bg, var(--bg-color)); border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
                        <div class="sui-studio-title" style="font-size:18px; font-weight:800; color:var(--text-primary);">${config.title || 'Studio'}</div>
                        <div style="display:flex; gap:12px; align-items:center;">
                            <div class="sui-studio-actions"></div>
                            <button class="sui-studio-close" style="background:var(--btn-bg); border:none; width:32px; height:32px; border-radius:50%; color:var(--text-primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:16px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        </div>
                    </div>
                    <div class="sui-studio-content" style="flex:1; overflow-y:auto; padding:24px 24px 8px 24px;"></div>
                </div>
            `;
            document.body.appendChild(overlay);
        }

        // Refresh Title and Close Binding on every open (Fixes stale headers/callbacks on reuse)
        const titleEl = overlay.querySelector('.sui-studio-title');
        if (titleEl && config.title) titleEl.innerText = config.title;

        const closeBtn = overlay.querySelector('.sui-studio-close');
        if (closeBtn) closeBtn.onclick = () => window.sui.closeStudio(config.id, config.hasChanges, config.onSave, config.onClose);

        const contentBox = overlay.querySelector('.sui-studio-content');
        if (config.content) {
            contentBox.innerHTML = config.content;
            window.suiHydrateIcons(contentBox);
            window.suiHydrateSettings(contentBox);
        }

        if (window.sui) {
            // Use Global Elevation to ensure the most recently requested studio is always on top
            overlay.style.zIndex = this.getNextZIndex().toString();

            window.sui.registerOverlay(`studio-${config.id}`, () => {
                window.sui.closeStudio(config.id, config.hasChanges, config.onSave, config.onClose);
            });
        }
        
        // Setup Callback (for binding inputs)
        if (config.onSetup) config.onSetup(contentBox, overlay);

        // Just-in-time status refresh for Edit Log
        if (typeof window.elRefreshStatus === 'function') window.elRefreshStatus();

        // Show
        overlay.style.visibility = 'visible';
        const sheet = overlay.querySelector('.shared-bottom-sheet');
        
        // Force reset to bottom before animating
        sheet.style.transform = 'translateY(100%)';
        
        // Ensure initial state is registered by browser before animating
        requestAnimationFrame(() => {
            overlay.style.opacity = '1';
            sheet.style.transform = 'translateY(0) translateZ(0)';
        });
    },

    closeStudio: function(id, checkChangesFn, saveFn, closeCallback) {
        const overlay = document.getElementById(`sui-studio-${id}`);
        if (!overlay || overlay.dataset.isClosing === 'true') return;

        const performClose = () => {
            overlay.dataset.isClosing = 'true';
            this.unregisterOverlay(`studio-${id}`);
            const sheet = overlay.querySelector('.shared-bottom-sheet');
            sheet.style.transform = 'translateY(100%)';
            overlay.style.opacity = '0';
            this._studioTimers[id] = setTimeout(() => {
                overlay.style.visibility = 'hidden';
                delete overlay.dataset.isClosing;
                delete this._studioTimers[id];
                if (closeCallback) closeCallback();
            }, 300);
        };

        if (checkChangesFn && checkChangesFn()) {
            window.openConfirm(
                "Unsaved Changes",
                "Save your changes before closing?",
                () => { if (saveFn) saveFn(); performClose(); }, // Confirm = Save & Close
                false,
                "Save & Close",
                "Discard",
                () => performClose() // Cancel = Discard
            );
        } else {
            performClose();
        }
    },

    _overlayStack: [],
    registerOverlay: function(id, closeFn) {
        // Prevent duplicates
        this._overlayStack = this._overlayStack.filter(o => o.id !== id);
        this._overlayStack.push({ id, close: closeFn });
    },
    unregisterOverlay: function(id) {
        this._overlayStack = this._overlayStack.filter(o => o.id !== id);
    },
    dismissTopOverlay: function() {
        if (this._overlayStack.length > 0) {
            const top = this._overlayStack.pop();
            if (typeof top.close === 'function') {
                top.close();
                return true;
            }
        }
        return false;
    },
    // --- FILE STUDIO COMPONENT ---
    // Standardized file navigator for Scope/Context management
    openFileStudio: function(config) {
        // config: { id, title, selection (Set), onSave, suggestions (Array/Fn), root (Optional), fileSizes (Object) }
        if (!config.exclusions && config.extraExclusions) {
            config.exclusions = config.extraExclusions;
        }
        const localSelection = new Set(config.selection || []);
        let highlightPath = null;
        let initialSnapshot = null;
        let saveTimeout;
        const notifyChange = () => {
            if (config.onSelectionChange) config.onSelectionChange(localSelection, config.foundationExclusions, config.extraExclusions);
        };

        const triggerSave = () => {
            notifyChange();
            if (!config.autoSave || !config.onSave) return;
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(() => config.onSave(Array.from(localSelection)), 400);
        };

        const updateNavigatorRow = (path) => {
            // 1. Update the bottom navigator list
            const itemsCont = document.getElementById(`sui-fs-items-${config.id}`);
            if (itemsCont) {
                const row = itemsCont.querySelector(`[data-path="${path}"]`);
                if (row) {
                    const isSelected = localSelection.has(path);
                    // Navigator uses extra exclusions for dimming
                    const isExcluded = config.extraExclusions && config.extraExclusions.has(path);
                    const cb = row.querySelector('.custom-checkbox');
                    if (cb) cb.classList.toggle('checked', isSelected);
                    row.style.opacity = isExcluded ? "0.5" : "1";
                }
            }

            // 2. Update the Foundation accordion list (Live Sync)
            const foundCont = document.getElementById(`ce-fs-foundation-${config.id}`);
            if (foundCont) {
                const row = foundCont.querySelector(`[data-path="${path}"]`);
                if (row) {
                    const isExcluded = config.foundationExclusions && config.foundationExclusions.has(path);
                    const cb = row.querySelector('.ce-found-toggle');
                    if (cb) cb.checked = !isExcluded;
                    row.style.opacity = isExcluded ? "0.5" : "1";
                }
            }
        };
        
        const checkChanges = () => {
            if (config.autoSave) return false;
            const overlay = document.getElementById(`sui-studio-${config.id}`);
            return window.sui.hasChanges(overlay, initialSnapshot);
        };

        const performSave = () => {
            if (config.onSave) config.onSave(Array.from(localSelection));
            initialSnapshot = JSON.stringify(Array.from(localSelection).sort());
        };

        let navPath = "";
        let sysMap = {};
        let activeFilters = new Set();
        let isFuzzy = localStorage.getItem('cjos_fs_fuzzy') === 'true';
        let isPathSearch = false;
        let fsReqId = 0;

        const renderProjects = () => {
            const cont = document.getElementById(`ce-fs-projects-${config.id}`);
            const wrap = document.getElementById(`ce-fs-projects-wrap-${config.id}`);
            if (!cont || !config.projects) return;
            
            // Capture currently open sub-accordions to preserve state during re-render
            const openIds = new Set(Array.from(cont.querySelectorAll('.sui-accordion.open')).map(el => el.id));
            
            const activeProjects = config.projects.filter(p => p.isActive);
            wrap.style.display = activeProjects.length > 0 ? 'block' : 'none';
            
            cont.innerHTML = activeProjects.map((proj, pIdx) => {
                const safeName = proj.filename.replace(/[^a-z0-9]/gi, '-');
                const projId = `ce-fs-proj-sub-${config.id}-${safeName}`;
                const isOpen = openIds.has(projId);

                const activeFiles = proj.files.filter(f => !config.extraExclusions.has(f.path));
                const totalFiles = proj.files.length;
                const isProjectExcluded = activeFiles.length === 0;
                
                const headerHtml = `
                    <div style="display:flex; justify-content:space-between; align-items:center; flex:1; min-width:0; gap:10px;">
                        <div style="display:flex; align-items:center; gap:8px; min-width:0; flex:1;">
                            <div style="font-size:12px; font-weight:800; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${proj.title}</div>
                            <span style="font-size:9px; font-weight:700; color:var(--text-secondary); opacity:0.6; flex-shrink:0;">${activeFiles.length}/${totalFiles}</span>
                        </div>
                        <div onclick="event.stopPropagation()" style="display:flex; align-items:center; flex-shrink:0;">
                            ${window.suiSwitch(`ce-proj-active-${config.id}-${pIdx}`, !isProjectExcluded, `window.sui.fsProjToggle('${config.id}', '${proj.filename}', this.checked)`)}
                        </div>
                    </div>
                `;

                const contentHtml = proj.files.map(f => {
                    const isExcluded = config.extraExclusions.has(f.path);
                    return `
                        <div data-path="${f.path}" style="display:flex; justify-content:space-between; align-items:center; padding:8px 10px; background:rgba(0,0,0,0.02); border-radius:8px; opacity:${isExcluded ? 0.5 : 1}; border: 1px solid var(--border-color);">
                            <div style="display:flex; flex-direction:column; min-width:0; flex:1;">
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <span style="font-size:11px; font-family:monospace; font-weight:700; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${f.path.split('/').pop()}</span>
                                    <span style="font-size:8px; font-weight:800; color:var(--text-secondary); opacity:0.5; background:var(--btn-bg); padding:1px 4px; border-radius:4px;">${f.size}</span>
                                </div>
                                <span style="font-size:9px; color:var(--text-secondary); opacity:0.7; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${f.path}</span>
                            </div>
                            <input type="checkbox" ${!isExcluded ? 'checked' : ''} class="ce-proj-file-toggle" data-path="${f.path}" style="width:16px; height:16px; cursor:pointer; margin-left:10px;">
                        </div>
                    `;
                }).join('');

                return `
                    <div style="background:var(--btn-bg); border-radius:12px; border:1px solid var(--border-color); overflow:hidden; opacity:${proj.isActive ? 1 : 0.6};">
                        <div onclick="suiToggle('${projId}')" style="padding:12px; cursor:pointer; display:flex; align-items:center; gap:8px; background:rgba(0,0,0,0.02);">
                            <span data-sui-icon="chevron" data-sui-arrow="${projId}" data-sui-size="10" style="transition:transform 0.3s; transform:rotate(${isOpen ? '0' : '-90'}deg);"></span>
                            ${headerHtml}
                        </div>
                        <div id="${projId}" class="sui-accordion ${isOpen ? 'open' : ''}" style="${isOpen ? '' : 'display:none;'}">
                            <div class="sui-accordion-inner" style="padding:0 10px 10px 10px; display:flex; flex-direction:column; gap:4px;">
                                ${contentHtml || '<div style="font-size:10px; opacity:0.5; text-align:center; padding:10px;">No files in scope</div>'}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            window.suiHydrateIcons(cont);
            cont.querySelectorAll('.ce-proj-file-toggle').forEach(cb => {
                cb.onchange = async () => {
                    if (config.onToggle) await config.onToggle(cb.dataset.path, cb.checked, () => { renderProjects(); renderActive(document.getElementById(`sui-fs-active-${config.id}`)); }, 'extra');
                };
            });
        };

        window.sui.fsProjToggle = async (studioId, filename, enabled) => {
            const proj = config.projects.find(p => p.filename === filename);
            if (!proj || !config.onToggleBatch) return;
            
            const paths = proj.files.map(f => f.path);
            await config.onToggleBatch(paths, enabled, () => {
                renderProjects();
                renderActive(document.getElementById(`sui-fs-active-${config.id}`));
            });
        };

        window.sui.fsReorderCategory = async (studioId, cat, direction) => {
            if (config.onCategoryReorder) {
                highlightPath = cat; // Reuse highlight logic for categories
                await config.onCategoryReorder(cat, direction, () => {
                    renderCategories();
                    setTimeout(() => { highlightPath = null; }, 1500);
                });
            }
        };

        const renderCategories = () => {
            const cont = document.getElementById(`ce-fs-categories-${config.id}`);
            if (!cont || !config.categories) return;
            cont.innerHTML = config.categories.map(cat => {
                const isHighlight = (cat === highlightPath);
                return `
                    <div class="${isHighlight ? 'ce-reorder-glow' : ''}" style="display:flex; justify-content:space-between; align-items:center; background:var(--btn-bg); padding:8px 12px; border-radius:10px; border:1px solid var(--border-color); margin-bottom:4px;">
                        <span style="font-size:11px; font-weight:800; color:var(--text-primary); text-transform:uppercase;">${cat}</span>
                        <div style="display:flex; gap:8px;">
                            <button onclick="window.sui.fsReorderCategory('${config.id}', '${cat}', 'up')" style="background:var(--card-bg); border:1px solid var(--border-color); width:24px; height:24px; border-radius:6px; color:var(--text-secondary); cursor:pointer; display:flex; align-items:center; justify-content:center;"><span data-sui-icon="chevron" data-sui-size="10" style="transform:rotate(180deg);"></span></button>
                            <button onclick="window.sui.fsReorderCategory('${config.id}', '${cat}', 'down')" style="background:var(--card-bg); border:1px solid var(--border-color); width:24px; height:24px; border-radius:6px; color:var(--text-secondary); cursor:pointer; display:flex; align-items:center; justify-content:center;"><span data-sui-icon="chevron" data-sui-size="10"></span></button>
                        </div>
                    </div>
                `;
            }).join('');
            window.suiHydrateIcons(cont);
        };

        window.sui.fsReorder = async (studioId, path, direction) => {
            if (config.onReorder) {
                // 1. Immediate "Light Up" before move
                const cont = document.getElementById(`ce-fs-foundation-${config.id}`);
                const row = cont?.querySelector(`[data-path="${path}"]`);
                if (row) row.classList.add('ce-reorder-glow');

                // 2. Set state to maintain glow after re-render
                highlightPath = path;

                await config.onReorder(path, direction, () => {
                    renderFoundation();
                    // 3. Clear the highlight state after the animation has had time to run
                    setTimeout(() => { highlightPath = null; }, 1500);
                });
            }
        };

        const renderFoundation = () => {
            const cont = document.getElementById(`ce-fs-foundation-${config.id}`);
            if (!cont || !config.foundation) return;
            cont.innerHTML = config.foundation.map((f, idx) => {
                const isExcluded = config.foundationExclusions.has(f.path);
                const reorderButtons = config.onReorder ? `
                    <div class="sui-fs-reorder-handle" style="display:flex; flex-direction:column; gap:2px; margin-right:10px; width:16px; cursor:grab;">
                        <button onclick="window.sui.fsReorder('${config.id}', '${f.path}', 'up')" style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:4px; color:var(--text-secondary); cursor:pointer; padding:2px; display:flex; align-items:center; justify-content:center; width:16px; height:14px;"><span data-sui-icon="chevron" data-sui-size="8" style="transform:rotate(180deg);"></span></button>
                        <button onclick="window.sui.fsReorder('${config.id}', '${f.path}', 'down')" style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:4px; color:var(--text-secondary); cursor:pointer; padding:2px; display:flex; align-items:center; justify-content:center; width:16px; height:14px;"><span data-sui-icon="chevron" data-sui-size="8"></span></button>
                    </div>
                ` : '';
                const isHighlight = (f.path === highlightPath);
                return `
                    <div data-path="${f.path}" data-index="${idx}" class="sui-fs-foundation-row ${isHighlight ? 'ce-reorder-glow' : ''}" 
                         style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; background:var(--btn-bg); border-radius:10px; opacity:${isExcluded ? 0.5 : 1}; border: 1px solid var(--border-color); position:relative;">
                        <div style="display:flex; align-items:center; min-width:0; flex:1;">
                            ${reorderButtons}
                            <div style="display:flex; flex-direction:column; min-width:0; flex:1;">
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <span style="font-size:12px; font-family:monospace; font-weight:700; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${f.path.split('/').pop()}</span>
                                    <span style="font-size:9px; font-weight:800; color:var(--text-secondary); opacity:0.5; background:rgba(0,0,0,0.03); padding:1px 4px; border-radius:4px;">${f.size}</span>
                                </div>
                                <span style="font-size:9px; color:var(--text-secondary); opacity:0.7; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${f.path}</span>
                            </div>
                        </div>
                        <input type="checkbox" ${!isExcluded ? 'checked' : ''} class="ce-found-toggle" data-path="${f.path}" style="width:18px; height:18px; cursor:pointer; margin-left:12px;">
                    </div>
                `;
            }).join('');

            cont.querySelectorAll('.ce-found-toggle').forEach(cb => {
                cb.onchange = async () => {
                    const path = cb.dataset.path;
                    const enabled = cb.checked;
                    if (config.onToggle) {
                        await config.onToggle(path, enabled, () => {
                            renderActive(document.getElementById(`sui-fs-active-${config.id}`));
                            if (typeof renderNavigator === 'function') renderNavigator();
                        }, 'foundation');
                    }
                };
            });

            // --- DRAG AND DROP ORCHESTRATION ---
            let dragTimer = null;
            let ghost = null;
            let dropLine = null;
            let draggedItem = null;
            let targetIndex = -1;
            let startX = 0;
            let startY = 0;

            // NUCLEAR SCROLL BLOCK: This prevents the browser from swiping/scrolling 
            // the page while we are trying to move an item.
            const preventScroll = (e) => {
                if (ghost) {
                    if (e.cancelable) e.preventDefault();
                }
            };

            const updateDropLinePos = (clientY) => {
                let closestDist = Infinity;
                let closestRow = null;
                let isAfter = false;
                const currentRows = Array.from(cont.querySelectorAll('.sui-fs-foundation-row'));

                currentRows.forEach((r) => {
                    if (r === draggedItem) return;
                    const rect = r.getBoundingClientRect();
                    const mid = rect.top + (rect.height / 2);
                    const dist = Math.abs(clientY - mid);
                    if (dist < closestDist) {
                        closestDist = dist;
                        closestRow = r;
                        isAfter = clientY > mid;
                    }
                });

                if (closestRow && dropLine) {
                    const rect = closestRow.getBoundingClientRect();
                    const contRect = cont.getBoundingClientRect();
                    const y = isAfter ? (rect.bottom - contRect.top) : (rect.top - contRect.top);
                    dropLine.style.top = (y - 1) + 'px';
                    dropLine.style.display = 'block'; // Reveal now that position is valid
                    
                    const idx = parseInt(closestRow.dataset.index);
                    targetIndex = isAfter ? idx + 1 : idx;
                }
            };

            cont.onpointerdown = (e) => {
                const row = e.target.closest('.sui-fs-foundation-row');
                if (!row || e.target.closest('input') || e.target.closest('button')) return;

                startX = e.clientX;
                startY = e.clientY;

                dragTimer = setTimeout(() => {
                    window.sui.haptic('medium');
                    draggedItem = row;
                    draggedItem.classList.add('sui-fs-dragging');
                    cont.classList.add('sui-fs-container-dragging');

                    // Activate the scroll block
                    window.addEventListener('touchmove', preventScroll, { passive: false });
                    
                    // Create Ghost
                    ghost = row.cloneNode(true);
                    ghost.className = 'sui-fs-drag-ghost';
                    ghost.style.width = row.offsetWidth + 'px';
                    ghost.style.left = e.clientX - (row.offsetWidth / 2) + 'px';
                    ghost.style.top = e.clientY - 20 + 'px';
                    document.body.appendChild(ghost);

                    // Create Drop Line
                    dropLine = document.createElement('div');
                    dropLine.className = 'sui-fs-drop-line';
                    cont.appendChild(dropLine);

                    // INITIAL POSITION CALCULATION (Prevents flash at top)
                    updateDropLinePos(e.clientY);
                    
                    cont.setPointerCapture(e.pointerId);
                }, 1000); // 1.0s Long-Press Threshold
            };

            cont.onpointermove = (e) => {
                if (!ghost) {
                    if (dragTimer) {
                        const dist = Math.sqrt(Math.pow(e.clientX - startX, 2) + Math.pow(e.clientY - startY, 2));
                        if (dist > 10) {
                            clearTimeout(dragTimer);
                            dragTimer = null;
                        }
                    }
                    return;
                }

                e.preventDefault();
                ghost.style.left = e.clientX - (ghost.offsetWidth / 2) + 'px';
                ghost.style.top = e.clientY - 20 + 'px';

                updateDropLinePos(e.clientY);
            };

            cont.onpointerup = async (e) => {
                clearTimeout(dragTimer);
                dragTimer = null;
                
                if (!ghost) return;

                const path = draggedItem.dataset.path;
                const oldIdx = parseInt(draggedItem.dataset.index);
                const currentRows = Array.from(cont.querySelectorAll('.sui-fs-foundation-row'));
                
                // Cleanup UI
                ghost.remove(); ghost = null;
                dropLine.remove(); dropLine = null;
                draggedItem.classList.remove('sui-fs-dragging');
                cont.classList.remove('sui-fs-container-dragging');
                
                // Release the scroll block
                window.removeEventListener('touchmove', preventScroll);
                
                cont.releasePointerCapture(e.pointerId);

                // Adjust target index if moving forward (since the item is removed first in splice)
                let finalIdx = targetIndex;
                if (finalIdx > oldIdx) finalIdx--;
                if (finalIdx < 0) finalIdx = 0;
                if (finalIdx >= currentRows.length) finalIdx = currentRows.length - 1;

                if (finalIdx !== oldIdx && config.onReorder) {
                    highlightPath = path;
                    await config.onReorder(path, null, () => {
                        renderFoundation();
                        setTimeout(() => { highlightPath = null; }, 1500);
                    }, finalIdx);
                }
                draggedItem = null;
            };

            // Ensure we cancel if the pointer leaves the container before triggering
            cont.onpointercancel = () => {
                clearTimeout(dragTimer);
                dragTimer = null;
            };

            window.suiHydrateIcons(cont);
        };

        const renderActive = (container) => {
    renderCategories();
    renderFoundation();
    renderProjects();
    renderSuggestions();
    const clearBtn = document.getElementById(`sui-fs-clear-${config.id}`);
    const countBadge = document.getElementById(`sui-fs-count-${config.id}`);
const allBtn = document.getElementById(`sui-fs-all-toggle-${config.id}`);
const jsonBtn = document.getElementById(`sui-fs-json-toggle-${config.id}`);
const downloadSmallBtn = document.getElementById(`sui-fs-download-small-${config.id}`);
const copySmallBtn = document.getElementById(`sui-fs-copy-small-${config.id}`);
const refreshBtn = document.getElementById(`sui-fs-refresh-${config.id}`);
            
if (refreshBtn) {
    refreshBtn.style.display = (config.onRefresh && localSelection.size > 0) ? 'flex' : 'none';
}

if (downloadSmallBtn) {
    downloadSmallBtn.style.display = localSelection.size > 0 ? 'flex' : 'none';
}

if (copySmallBtn) {
    copySmallBtn.style.display = localSelection.size > 0 ? 'flex' : 'none';
}
            
if (allBtn) {if (localSelection.size === 0) {
        allBtn.style.display = 'none';
    } else {
        allBtn.style.display = 'flex';
        const allOn = Array.from(localSelection).every(f => !config.extraExclusions || !config.extraExclusions.has(f));
        allBtn.style.opacity = allOn ? "1" : "0.4";
    }
}

if (jsonBtn) {
    const jsonFiles = Array.from(localSelection).filter(f => f.toLowerCase().endsWith('.json'));
    if (jsonFiles.length === 0) {
        jsonBtn.style.display = 'none';
    } else {
        jsonBtn.style.display = 'flex';
        const allOn = jsonFiles.every(f => !config.extraExclusions || !config.extraExclusions.has(f));
        jsonBtn.style.opacity = allOn ? "1" : "0.4";
    }
}if (countBadge) {
                countBadge.innerText = localSelection.size;
                countBadge.style.display = localSelection.size > 0 ? 'inline-block' : 'none';
            }
            
            if (clearBtn) { 
                clearBtn.style.opacity = (localSelection.size > 0) ? "1" : "0"; 
                clearBtn.style.pointerEvents = (localSelection.size > 0) ? "auto" : "none"; 
            }

            container.innerHTML = "";
            if (localSelection.size === 0) {
                container.insertAdjacentHTML('beforeend', `<span style="font-size:12px; color:var(--text-secondary); opacity:0.6;">No files selected.</span>`);
                return;
            }
            Array.from(localSelection).sort().forEach(f => {
                const badge = document.createElement('div');
                badge.className = 'meta-badge sui-badge-ai';
                const isExcluded = config.extraExclusions && config.extraExclusions.has(f);
                badge.style.cssText = `display:flex; align-items:center; gap:8px; padding:4px 10px; font-family:monospace; text-transform:none; letter-spacing:0; user-select:none; -webkit-user-select:none; border-radius:8px; max-width:100%; box-sizing:border-box; ${isExcluded ? 'opacity:0.4; filter:grayscale(1);' : ''}`;
                
                const size = (config.fileSizes && config.fileSizes[f]) ? `<span style="font-size:8px; font-weight:800; opacity:0.5; margin-left:6px; background:rgba(0,0,0,0.05); padding:1px 4px; border-radius:4px;">${config.fileSizes[f]}</span>` : '';
                badge.innerHTML = `
                    <span class="sui-fs-toggle-target" style="cursor:pointer; word-break:break-all; flex:1; display:flex; align-items:center;">${f}${size}</span>
                    <span class="sui-fs-remove-target" style="cursor:pointer; font-weight:900; opacity:0.6; padding:0 4px; border-left:1px solid rgba(0,0,0,0.1); margin-left:2px; flex-shrink:0;">×</span>
                `;

                badge.querySelector('.sui-fs-toggle-target').onclick = async (e) => {
                    e.stopPropagation();
                    if (config.onToggle) {
                        await config.onToggle(f, isExcluded, () => {
                            if (isExcluded) config.extraExclusions.delete(f); else config.extraExclusions.add(f);
                            renderActive(container);
                            updateNavigatorRow(f);
                        }, 'extra');
                    }
                };

                badge.querySelector('.sui-fs-remove-target').onclick = async (e) => {
                    e.stopPropagation();
                    
                    // Logic: If removing a foundation file from active selection, disable it in core
                    if (config.foundation && config.foundation.some(found => found.path === f)) {
                        if (config.onToggle) {
                            // enabled: false adds the file to the exclusions list
                            await config.onToggle(f, false, () => {});
                        }
                    }

                    localSelection.delete(f);
                    renderActive(container);
                    updateNavigatorRow(f);
                    triggerSave();
                };
                window.sui.bindFileLongPress(badge, f);
                container.appendChild(badge);
            });
            renderSuggestions();
        };

        const renderSuggestions = () => {
            const cont = document.getElementById(`sui-fs-suggested-${config.id}`);
            if (!cont) return;

            const accId = `sui-fs-sugg-acc-${config.id}`;
            const accEl = document.getElementById(accId);
            const header = accEl ? accEl.previousElementSibling : null;

            const suggestions = new Set();
            
            // Helper to check if a file is truly active (selected AND not excluded)
            const isActive = (f) => localSelection.has(f) && (!config.exclusions || !config.exclusions.has(f));

            localSelection.forEach(file => {
                if (!isActive(file)) return;
                (sysMap[file] || []).forEach(dep => { 
                    if (!localSelection.has(dep)) suggestions.add(dep); 
                });
            });

            // --- DYNAMIC INACTIVE STATE ---
            if (header) {
                const hasMap = Object.keys(sysMap).length > 0;
                
                if (!hasMap) {
                    // Loading State: Neutral/Waiting
                    header.style.opacity = "0.7";
                    header.style.filter = "none";
                    header.style.pointerEvents = "auto";
                } else {
                    const isEmpty = suggestions.size === 0;
                    header.style.opacity = isEmpty ? "0.4" : "1";
                    header.style.filter = isEmpty ? "grayscale(1)" : "none";
                    header.style.pointerEvents = isEmpty ? "none" : "auto";
                    
                    // Only auto-close if it was actively open and the user just cleared the last suggestion
                    if (isEmpty && accEl.classList.contains('open') && accEl.style.display !== 'none') {
                        window.suiToggle(accId);
                    }
                }
                header.style.transition = "all 0.3s ease";
            }

            cont.innerHTML = "";
            if (suggestions.size === 0) {
                cont.innerHTML = `<span style="font-size:12px; color:var(--text-secondary); opacity:0.6;">No suggestions.</span>`;
                return;
            }
            Array.from(suggestions).sort().forEach(f => {
                const b = document.createElement('div');
                b.className = 'meta-badge sui-badge-default';
                b.style.cssText = "cursor:pointer; padding:4px 10px; font-family:monospace; text-transform:none; border-radius:8px;";
                b.innerHTML = `+ ${f.split('/').pop()}`;
                b.onclick = () => { localSelection.add(f); renderActive(document.getElementById(`sui-fs-active-${config.id}`)); updateNavigatorRow(f); triggerSave(); };
                cont.appendChild(b);
            });
        };

        const renderNavigator = async (query = null) => {
            const cont = document.getElementById(`sui-fs-items-${config.id}`);
            const pathLabel = document.getElementById(`sui-fs-path-${config.id}`);
            if (!cont) return;

            const searchInp = document.querySelector(`#sui-studio-${config.id} .sui-fs-search-input`);
            const activeQuery = (query !== null) ? query : (searchInp ? searchInp.value.trim() : "");

            const myReqId = ++fsReqId;
            cont.innerHTML = `<div style="padding:40px; text-align:center;">${window.suiSpinner(30)}</div>`;
            pathLabel.innerText = activeQuery ? "Searching..." : "/" + navPath;

            try {
                const payload = { path: navPath, q: activeQuery };
                if (activeFilters.size > 0) payload.filters = Array.from(activeFilters).join(',');
                if (isFuzzy) payload.fuzzy = 1;
                if (isPathSearch) payload.path_search = 1;
                const data = await window.sui.api('planner_get_sys_files', payload, { toast: false });
                
                if (myReqId !== fsReqId) return;

                if (data && data.items) {
                    if (activeQuery) {
                        const count = data.items.length;
                        pathLabel.innerText = `Search Results (${count}${data.truncated ? '+' : ''})`;
                    } else {
                        pathLabel.innerText = "/" + navPath;
                    }

                    if (data.items.length === 0) {
                        cont.innerHTML = `<div style="padding:60px 20px; text-align:center; color:var(--text-secondary); opacity:0.6;">
                            <div style="font-size:24px; margin-bottom:8px;">🔍</div>
                            <div style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:1px;">No files found</div>
                        </div>`;
                        return;
                    }

                    let html = "";
                    if (navPath !== "" && !activeQuery) {
                        html += `
                            <div class="sui-fs-back-btn" style="padding:14px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:10px; cursor:pointer; background:rgba(0,0,0,0.02);">
                                <span data-sui-icon="chevron" data-sui-size="14" style="transform:rotate(90deg)"></span> 
                                <span style="font-size:14px; font-weight:700;">.. (Back)</span>
                            </div>
                        `;
                    }

                    data.items.forEach(item => {
                        const isExcluded = config.extraExclusions && config.extraExclusions.has(item.path);
                        const isSelected = localSelection.has(item.path);
                        const sizeStr = (!item.is_dir && (item.size || (config.fileSizes && config.fileSizes[item.path]))) 
                            ? `<span style="font-size:9px; font-weight:800; color:var(--text-secondary); opacity:0.5; background:var(--btn-bg); padding:1px 4px; border-radius:4px;">${item.size || config.fileSizes[item.path]}</span>` 
                            : '';
                        
                        html += `
                            <div class="sui-fs-item-row" data-path="${item.path}" data-is-dir="${item.is_dir ? '1' : '0'}" style="padding:14px 20px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; cursor:pointer; opacity: ${isExcluded ? '0.5' : '1'};">
                                <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:0;">
                                    <span data-sui-icon="${item.is_dir ? 'folder' : 'activity'}" data-sui-size="16" data-sui-color="${item.is_dir ? 'var(--primary)' : 'var(--text-secondary)'}"></span>
                                    <div style="display:flex; flex-direction:column; min-width:0;">
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <span style="font-size:14px; font-weight:${item.is_dir ? '800' : '500'}; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis;">${item.name}</span>
                                            ${sizeStr}
                                        </div>
                                        ${activeQuery ? `<span style="font-size:10px; color:var(--text-secondary); opacity:0.7; overflow:hidden; text-overflow:ellipsis;">${item.path}</span>` : ''}
                                    </div>
                                </div>
                                ${!item.is_dir ? `<div class="custom-checkbox ${isSelected ? 'checked' : ''}" style="width:20px; height:20px;"></div>` : '<span data-sui-icon="chevron" data-sui-size="14" style="transform:rotate(-90deg); opacity:0.3;"></span>'}
                            </div>
                        `;
                    });

                    cont.innerHTML = html;
                    window.suiHydrateIcons(cont);

                    const backBtn = cont.querySelector('.sui-fs-back-btn');
                    if (backBtn) {
                        backBtn.onclick = () => { const p = navPath.split('/'); p.pop(); navPath = p.join('/'); renderNavigator(""); };
                    }

                    cont.querySelectorAll('.sui-fs-item-row').forEach(row => {
                        const path = row.dataset.path;
                        const isDir = row.dataset.isDir === '1';
                        row.onclick = () => {
                            if (isDir) { navPath = path; renderNavigator(""); }
                            else {
                                const currentlySelected = localSelection.has(path);
                                if (currentlySelected) localSelection.delete(path);
                                else localSelection.add(path);
                                row.querySelector('.custom-checkbox').classList.toggle('checked', !currentlySelected);
                                renderActive(document.getElementById(`sui-fs-active-${config.id}`));
                                triggerSave();
                                if (window.sui && window.sui.haptic) window.sui.haptic('light');
                            }
                        };
                        if (!isDir) window.sui.bindFileLongPress(row, path);
                    });
                }
            } catch(e) { 
                if (myReqId === fsReqId) {
                    cont.innerHTML = `<div style="padding:20px; color:var(--danger); font-size:12px;">Failed to load.</div>`; 
                }
            }
        };

        this.openStudio({
            id: config.id,
            title: 'File Studio: ' + (config.title || 'Selection'),
            hasChanges: checkChanges,
            onSave: performSave,
            content: `
                <div style="display:block; width:100%;">
                    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px;">
                        ${(config.headerActions || []).map((act, i) => `
                            <button id="sui-fs-act-${config.id}-${i}" class="text-btn" style="flex:1; min-width:120px; font-size:10px; font-weight:800; color:${act.color || 'var(--primary)'}; text-transform:uppercase; padding:8px 12px; background:var(--btn-bg); border-radius:10px; border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; gap:6px;">
                                <span data-sui-icon="${act.icon || 'activity'}" data-sui-size="14"></span> 
                                <span class="sui-fs-btn-label">${act.label}</span>
                            </button>
                        `).join('')}
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); cursor:pointer; margin-bottom:16px;" onclick="suiToggle('sui-fs-active-acc-${config.id}')">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Active Selection</div>
                            <span id="sui-fs-count-${config.id}" class="meta-badge sui-badge-ai" style="font-size:9px; padding:1px 6px; display:none;">0</span>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <button id="sui-fs-history-${config.id}" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; color:var(--text-secondary); display:none; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;" onclick="event.stopPropagation()">
                                <span data-sui-icon="clock" data-sui-size="14" data-sui-stroke="3"></span>
                            </button>
                            <button id="sui-fs-refresh-${config.id}" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; color:var(--primary); display:none; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;" onclick="event.stopPropagation()">
                                <span data-sui-icon="rotate-ccw" data-sui-size="14" data-sui-stroke="3"></span>
                            </button>
                            <span data-sui-icon="chevron" data-sui-arrow="sui-fs-active-acc-${config.id}" data-sui-size="14" style="transition:transform 0.35s; transform: rotate(0deg);"></span>
                        </div>
                    </div>

                    <div id="sui-fs-active-acc-${config.id}" class="sui-accordion open" style="margin-bottom:16px;">
                        <div class="sui-accordion-inner">
                            <div id="sui-fs-active-box-${config.id}" style="margin-bottom:0; min-height:60px; border:1px dashed var(--border-color); padding:12px; border-radius:12px; background:rgba(0,0,0,0.02); display:flex; flex-direction:column; gap:10px;">
                                <!-- Actions Row (Top Right) -->
                                <div style="display:flex; justify-content:flex-end; gap:6px; align-items:center; min-height:24px;">
                                    <button id="sui-fs-all-toggle-${config.id}" style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:6px; padding:4px 8px; font-size:9px; font-weight:800; color:var(--text-primary); cursor:pointer; transition:opacity 0.2s; display:none; align-items:center; justify-content:center;" onclick="event.stopPropagation()">ALL</button>
                                    <button id="sui-fs-json-toggle-${config.id}" style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:6px; padding:4px 8px; font-size:9px; font-weight:800; color:var(--text-primary); cursor:pointer; transition:opacity 0.2s; display:none; align-items:center; justify-content:center;" onclick="event.stopPropagation()">JSON</button>
                                    <button id="sui-fs-download-small-${config.id}" style="background:var(--btn-bg); border:none; width:24px; height:24px; border-radius:50%; color:var(--primary); display:none; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;" onclick="event.stopPropagation()">
                                        <span data-sui-icon="download" data-sui-size="12" data-sui-stroke="3"></span>
                                    </button>
                                    <button id="sui-fs-copy-small-${config.id}" style="background:var(--btn-bg); border:none; width:24px; height:24px; border-radius:50%; color:var(--primary); display:none; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;" onclick="event.stopPropagation()">
                                        <span data-sui-icon="copy" data-sui-size="12" data-sui-stroke="3"></span>
                                    </button>
                                    <button id="sui-fs-clear-${config.id}" style="background:rgba(255,59,48,0.1); border:none; width:24px; height:24px; border-radius:50%; color:var(--danger); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s; opacity:0; pointer-events:none;" onclick="event.stopPropagation()">
                                        <span data-sui-icon="trash" data-sui-size="12" data-sui-stroke="3"></span>
                                    </button>
                                </div>
                                <!-- Badge Container -->
                                <div id="sui-fs-active-${config.id}" data-sui-capture="fs-selection" style="display:flex; flex-wrap:wrap; gap:8px;">
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- CONTEXT SOURCES (MAJOR ACCORDION) -->
                    <div style="margin-bottom:16px;">
                        ${window.suiAccordion(`sui-fs-sources-acc-${config.id}`, 'Context Sources', `
                            <div style="display:flex; flex-direction:column; gap:12px; padding:12px 4px 4px 4px;">
                                <!-- Suggested Dependencies -->
                                <div>
                                    ${window.suiAccordion(`sui-fs-sugg-acc-${config.id}`, 'Suggested Dependencies', `<div id="sui-fs-suggested-${config.id}" style="display:flex; flex-wrap:wrap; gap:8px; padding:12px;">${window.suiSpinner(20)}</div>`, false)}
                                </div>

                                <!-- Context Hierarchy -->
                                ${config.onCategoryReorder ? `
                                <div>
                                    ${window.suiAccordion(`ce-fs-hier-acc-${config.id}`, 'Context Hierarchy (Priority)', `<div id="ce-fs-categories-${config.id}" style="display:flex; flex-direction:column; padding:12px;"></div>`, false)}
                                </div>` : ''}
                                
                                <!-- Foundation Files -->
                                ${config.foundation ? `
                                <div>
                                    ${window.suiAccordion(`ce-fs-found-acc-${config.id}`, 'Foundation Files (Core)', `<div id="ce-fs-foundation-${config.id}" style="display:flex; flex-direction:column; gap:4px; padding:12px;"></div>`, false)}
                                </div>` : ''}
                                
                                <!-- Project Files -->
                                <div id="ce-fs-projects-wrap-${config.id}" style="display:none;">
                                    ${window.suiAccordion(`ce-fs-proj-acc-${config.id}`, 'Project Files (Active Scope)', `<div id="ce-fs-projects-${config.id}" style="display:flex; flex-direction:column; gap:8px; padding:12px;"></div>`, false)}
                                </div>


                            </div>
                        `, false)}
                    </div>
                    <div style="position:relative; margin-bottom:8px;">
                        <input type="text" class="sui-fs-search-input" placeholder="Search files..." autocapitalize="none" autocorrect="off" spellcheck="false" style="width:100%; padding:12px 75px 12px 40px; border-radius:12px; border:1px solid var(--border-color); background:var(--input-bg); font-size:14px; outline:none;">
                        <div style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-secondary);"><span data-sui-icon="search" data-sui-size="16"></span></div>
                        <div style="position:absolute; right:6px; top:50%; transform:translateY(-50%); display:flex; gap:4px; align-items:center;">
                            <button class="sui-fs-search-clear" style="width:28px; height:28px; border-radius:50%; border:none; background:var(--btn-bg); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s; opacity:0; pointer-events:none;">
                                <span data-sui-icon="close" data-sui-size="14" data-sui-stroke="3"></span>
                            </button>
                            <button class="sui-fs-search-execute" style="width:32px; height:32px; border-radius:10px; border:none; background:var(--primary); color:var(--primary-text); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;">
                                <span data-sui-icon="chevron" data-sui-size="16" data-sui-stroke="3" style="transform:rotate(-90deg);"></span>
                            </button>
                        </div>
                    </div>
                    <div class="sui-fs-filters" style="display:flex; flex-wrap:nowrap; gap:6px; margin-bottom:16px; overflow-x:auto; padding-bottom:8px; scrollbar-width:none; -ms-overflow-style:none; -webkit-overflow-scrolling:touch;">
                        <button class="sui-fs-filter-btn text-btn" data-filter="code" style="padding:6px 12px; font-size:10px; font-weight:800; border-radius:8px; border:1px solid var(--border-color); background:var(--btn-bg); color:var(--text-secondary); cursor:pointer; flex-shrink:0; transition:all 0.2s;">CODE/SCRIPTS</button>
                        <button class="sui-fs-filter-btn text-btn" data-filter="json" style="padding:6px 12px; font-size:10px; font-weight:800; border-radius:8px; border:1px solid var(--border-color); background:var(--btn-bg); color:var(--text-secondary); cursor:pointer; flex-shrink:0; transition:all 0.2s;">DATA/JSON</button>
                        <button class="sui-fs-filter-btn text-btn" data-filter="md" style="padding:6px 12px; font-size:10px; font-weight:800; border-radius:8px; border:1px solid var(--border-color); background:var(--btn-bg); color:var(--text-secondary); cursor:pointer; flex-shrink:0; transition:all 0.2s;">DOCS/CFG</button>
                        <button class="sui-fs-filter-btn text-btn" data-filter="binary" style="padding:6px 12px; font-size:10px; font-weight:800; border-radius:8px; border:1px solid var(--border-color); background:var(--btn-bg); color:var(--text-secondary); cursor:pointer; flex-shrink:0; transition:all 0.2s;">BINARY</button>
                        <div style="width:1px; background:var(--border-color); margin:0 2px; flex-shrink:0;"></div>
                        <button class="sui-fs-path-btn text-btn" style="padding:6px 12px; font-size:10px; font-weight:800; border-radius:8px; border:1px solid var(--border-color); background:var(--btn-bg); color:var(--text-secondary); cursor:pointer; flex-shrink:0; transition:all 0.2s;">PATH</button>
                        <button class="sui-fs-fuzzy-btn text-btn" style="padding:6px 12px; font-size:10px; font-weight:800; border-radius:8px; border:1px solid var(--border-color); background:var(--btn-bg); color:var(--text-secondary); cursor:pointer; flex-shrink:0; transition:all 0.2s;">FUZZY</button>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">System Navigator</div>
                        <div id="sui-fs-path-${config.id}" style="font-family:monospace; font-size:10px; color:var(--primary);">/</div>
                    </div>
                    <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:16px; overflow:hidden; margin-bottom:24px;">
                        <div id="sui-fs-items-${config.id}" style="height:320px; overflow-y:auto;"></div>
                    </div>
                    ${config.autoSave ? '' : `<button id="sui-fs-save-${config.id}" class="btn-primary" style="width:100%;">Save Selection</button>`}
                </div>
            `,
            onSetup: async (content, overlay) => {
                const activeBox = content.querySelector(`#sui-fs-active-${config.id}`);


                window.suiInit(`sui-fs-active-acc-${config.id}`);
                window.suiInit(`sui-fs-sources-acc-${config.id}`);

                const allBtn = document.getElementById(`sui-fs-all-toggle-${config.id}`);
const jsonBtn = document.getElementById(`sui-fs-json-toggle-${config.id}`);
const refreshBtn = document.getElementById(`sui-fs-refresh-${config.id}`);
const historyBtn = document.getElementById(`sui-fs-history-${config.id}`);

if (historyBtn) {
    historyBtn.style.display = config.onHistory ? 'flex' : 'none';
    historyBtn.onclick = (e) => {
        e.stopPropagation();
        config.onHistory(fsApi);
    };
}

if (allBtn) {
    allBtn.onclick = async (e) => {
        e.stopPropagation();
        const files = Array.from(localSelection);
        const allOn = files.every(f => !config.exclusions || !config.exclusions.has(f));
        const targetState = !allOn;
        const targets = files.filter(f => {
            const isExcluded = config.exclusions && config.exclusions.has(f);
            return targetState ? isExcluded : !isExcluded;
        });

        if (targets.length === 0) return;
        
        allBtn.style.opacity = "0.5";
        allBtn.style.pointerEvents = "none";
        
        if (config.onToggleBatch) {
            await config.onToggleBatch(targets, targetState, () => {
                targets.forEach(f => { 
                    if (targetState) config.exclusions.delete(f); else config.exclusions.add(f); 
                    updateNavigatorRow(f);
                });
                renderActive(activeBox);
            });
        } else {
            for (const f of targets) {
                await config.onToggle(f, targetState, () => {});
            }
            targets.forEach(f => { 
                if (targetState) config.exclusions.delete(f); else config.exclusions.add(f); 
                updateNavigatorRow(f);
            });
            renderActive(activeBox);
        }
        
        allBtn.style.pointerEvents = "auto";
    };
}

if (refreshBtn && config.onRefresh) {refreshBtn.onclick = (e) => {
                        e.stopPropagation();
                        if (window.sui && window.sui.haptic) window.sui.haptic('medium');
                        config.onRefresh(fsApi);
                    };
                }

                if (jsonBtn) {
    jsonBtn.onclick = async (e) => {
        e.stopPropagation();
        const jsonFiles = Array.from(localSelection).filter(f => f.toLowerCase().endsWith('.json'));
        const allOn = jsonFiles.every(f => !config.exclusions || !config.exclusions.has(f));
        const targetState = !allOn;
        const targets = jsonFiles.filter(f => {
            const isExcluded = config.exclusions && config.exclusions.has(f);
            return targetState ? isExcluded : !isExcluded;
        });

        if (targets.length === 0) return;
        
        jsonBtn.style.opacity = "0.5";
        jsonBtn.style.pointerEvents = "none";
        
        if (config.onToggleBatch) {
            await config.onToggleBatch(targets, targetState, () => {
                targets.forEach(f => { 
                    if (targetState) config.exclusions.delete(f); else config.exclusions.add(f); 
                    updateNavigatorRow(f);
                });
                renderActive(activeBox);
            });
        } else {
            for (const f of targets) {
                await config.onToggle(f, targetState, () => {});
            }
            targets.forEach(f => { 
                if (targetState) config.exclusions.delete(f); else config.exclusions.add(f); 
                updateNavigatorRow(f);
            });
            renderActive(activeBox);
        }
        
        jsonBtn.style.pointerEvents = "auto";
    };
}const clearBtn = document.getElementById(`sui-fs-clear-${config.id}`);
                if (clearBtn) {
                    clearBtn.onclick = (e) => {
                        e.stopPropagation();
                        window.openConfirm("Clear Selection", "Remove all files from selection?", async () => {
                            localSelection.clear();
                            renderActive(activeBox);
                            renderNavigator();
                            
                            // Immediate Save (Bypass throttle to ensure persistence)
                            if (config.onSave) {
                                await config.onSave([]);
                            }
                            
                            notifyChange();
                            if (window.sui && window.sui.haptic) window.sui.haptic('success');
                        }, true);
                    };
                }

                // Render the selection immediately
                renderActive(activeBox);
                
                window.sui.fsLoadDeps = async (id) => {
                    const mapData = await window.sui.api('planner_get_sys_map', {}, { toast: false });
                    if (mapData) {
                        sysMap = mapData.map;
                        renderSuggestions();
                    }
                };
                
                // Await the navigator render so checkboxes/items are present before snapshot
                await renderNavigator();

                // Background load dependency map to determine "Suggested" state immediately
                window.sui.fsLoadDeps(config.id);
                
                // Capture initial state from the content container
                initialSnapshot = window.sui.takeSnapshot(content);
                
                let st;
                const fsSearchInp = content.querySelector(`.sui-fs-search-input`);
                const fsSearchClear = content.querySelector(`.sui-fs-search-clear`);
                
                const updateFilterUI = () => {
                    content.querySelectorAll('.sui-fs-filter-btn').forEach(btn => {
                        const f = btn.dataset.filter;
                        if (activeFilters.has(f)) {
                            btn.style.background = 'var(--primary)';
                            btn.style.color = 'var(--primary-text)';
                            btn.style.borderColor = 'var(--primary)';
                        } else {
                            btn.style.background = 'var(--btn-bg)';
                            btn.style.color = 'var(--text-secondary)';
                            btn.style.borderColor = 'var(--border-color)';
                        }
                    });
                    const pathBtn = content.querySelector('.sui-fs-path-btn');
                    if (pathBtn) {
                        if (isPathSearch) {
                            pathBtn.style.background = 'var(--primary)';
                            pathBtn.style.color = 'var(--primary-text)';
                            pathBtn.style.borderColor = 'var(--primary)';
                        } else {
                            pathBtn.style.background = 'var(--btn-bg)';
                            pathBtn.style.color = 'var(--text-secondary)';
                            pathBtn.style.borderColor = 'var(--border-color)';
                        }
                    }
                    const fuzzyBtn = content.querySelector('.sui-fs-fuzzy-btn');
                    if (fuzzyBtn) {
                        if (isFuzzy) {
                            fuzzyBtn.style.background = 'var(--ai-accent)';
                            fuzzyBtn.style.color = 'var(--primary-text)';
                            fuzzyBtn.style.borderColor = 'var(--ai-accent)';
                        } else {
                            fuzzyBtn.style.background = 'var(--btn-bg)';
                            fuzzyBtn.style.color = 'var(--text-secondary)';
                            fuzzyBtn.style.borderColor = 'var(--border-color)';
                        }
                    }
                };

                // Initial UI Sync: Apply highlighting based on persistent state
                updateFilterUI();

                content.querySelectorAll('.sui-fs-filter-btn').forEach(btn => {
                    btn.onclick = () => {
                        const f = btn.dataset.filter;
                        if (activeFilters.has(f)) activeFilters.delete(f);
                        else activeFilters.add(f);
                        updateFilterUI();
                        renderNavigator(fsSearchInp.value.trim());
                        if (window.sui && window.sui.haptic) window.sui.haptic('light');
                    };
                });

                const pathBtn = content.querySelector('.sui-fs-path-btn');
                if (pathBtn) {
                    pathBtn.onclick = () => {
                        isPathSearch = !isPathSearch;
                        updateFilterUI();
                        renderNavigator(fsSearchInp.value.trim());
                        if (window.sui && window.sui.haptic) window.sui.haptic('light');
                    };
                }

                const fuzzyBtn = content.querySelector('.sui-fs-fuzzy-btn');
                if (fuzzyBtn) {
                    fuzzyBtn.onclick = () => {
                        isFuzzy = !isFuzzy;
                        localStorage.setItem('cjos_fs_fuzzy', isFuzzy);
                        updateFilterUI();
                        renderNavigator(fsSearchInp.value.trim());
                        if (window.sui && window.sui.haptic) window.sui.haptic('light');
                    };
                }

                const updateFsClearBtn = () => {
                    if (!fsSearchClear) return;
                    const hasVal = fsSearchInp.value.trim().length > 0;
                    fsSearchClear.style.opacity = hasVal ? "1" : "0";
                    fsSearchClear.style.pointerEvents = hasVal ? "auto" : "none";
                };

                fsSearchInp.oninput = (e) => {
                    updateFsClearBtn();
                    clearTimeout(st); st = setTimeout(() => renderNavigator(e.target.value.trim()), 300);
                };

                fsSearchInp.onkeydown = (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        clearTimeout(st);
                        renderNavigator(fsSearchInp.value.trim());
                    }
                };

                const fsSearchExecute = content.querySelector('.sui-fs-search-execute');
                if (fsSearchExecute) {
                    fsSearchExecute.onclick = () => {
                        clearTimeout(st);
                        renderNavigator(fsSearchInp.value.trim());
                        if (window.sui && window.sui.haptic) window.sui.haptic('medium');
                    };
                }

                fsSearchClear.onclick = () => {
                    fsSearchInp.value = "";
                    updateFsClearBtn();
                    renderNavigator();
                    fsSearchInp.focus();
                };
                const saveBtn = content.querySelector(`#sui-fs-save-${config.id}`);
                if (saveBtn) {
                    saveBtn.onclick = () => {
                        performSave();
                        window.sui.closeStudio(config.id);
                    };
                }

                // Bind Custom Header Actions
                const fsApi = { 
                    selection: localSelection,
                    exclusions: config.exclusions, // Expose exclusions Set
                    fileSizes: config.fileSizes,
                    renderActive: () => renderActive(activeBox), 
                    renderNavigator, 
                    triggerSave,
                    notifyChange,
                    rebuildSizeMap: config.rebuildSizeMap,
                    updateLabels: config.updateLabels
                };
                
                // Allow external plugin to perform additional setup (e.g. async data loading)
                if (config.onSetup) config.onSetup(content, overlay, fsApi);

                (config.headerActions || []).forEach((act, i) => {
                    const btn = content.querySelector(`#sui-fs-act-${config.id}-${i}`);
                    if(btn) btn.onclick = () => act.onclick(fsApi);
                });

                const downloadBtn = content.querySelector(`#sui-fs-download-small-${config.id}`);
                if (downloadBtn) {
                    downloadBtn.onclick = async () => {
                        const activeFiles = Array.from(localSelection).filter(f => !config.exclusions || !config.exclusions.has(f));
                        if (activeFiles.length === 0) {
                            window.sui.toast("No active files to download");
                            return;
                        }

                        const originalHtml = downloadBtn.innerHTML;
                        downloadBtn.innerHTML = window.suiSpinner(12);
                        downloadBtn.disabled = true;

                        try {
                            const fd = { patch_count: activeFiles.length };
                            activeFiles.forEach((f, i) => {
                                fd[`p_${i}_file`] = f;
                                fd[`p_${i}_action`] = 'export';
                                fd[`p_${i}_find`] = '';
                                fd[`p_${i}_replace`] = '';
                            });

                            const data = await window.sui.api('cp_preview', fd, { toast: "Preparing download..." });
                            if (data && data.results) {
                                const fullSource = data.results.map(r => r.export_block || "").join("\n");
                                const wrapped = "~~~\n" + fullSource.trim() + "\n\n~~~";
                                
                                const filename = "Context_Selection_" + Date.now() + ".txt";
                                if (typeof cpDownloadText === 'function') {
                                    cpDownloadText(filename, wrapped);
                                } else {
                                    const blob = new Blob([wrapped], { type: 'text/plain;charset=utf-8' });
                                    const url = URL.createObjectURL(blob);
                                    const a = document.createElement('a');
                                    a.style.display = 'none';
                                    a.href = url;
                                    a.download = filename;
                                    document.body.appendChild(a);
                                    a.click();
                                    setTimeout(() => { document.body.removeChild(a); window.URL.revokeObjectURL(url); }, 100);
                                }
                                window.sui.toast(`${activeFiles.length} Files Downloaded`);
                            }
                        } catch(e) { window.sui.toast("Download failed: " + e.message); }
                        
                        downloadBtn.innerHTML = originalHtml;
                        downloadBtn.disabled = false;
                        window.suiHydrateIcons(downloadBtn);
                    };
                }

                content.querySelector(`#sui-fs-copy-small-${config.id}`).onclick = async () => {
                    const activeFiles = Array.from(localSelection).filter(f => !config.exclusions || !config.exclusions.has(f));
                    if (activeFiles.length === 0) {
                        window.sui.toast("No active files to copy");
                        return;
                    }

                    const btn = content.querySelector(`#sui-fs-copy-small-${config.id}`);
                    const originalHtml = btn.innerHTML;
                    btn.innerHTML = window.suiSpinner(12);
                    btn.disabled = true;

                    try {
                        const fd = { patch_count: activeFiles.length };
                        activeFiles.forEach((f, i) => {
                            fd[`p_${i}_file`] = f;
                            fd[`p_${i}_action`] = 'export';
                            fd[`p_${i}_find`] = '';
                            fd[`p_${i}_replace`] = '';
                        });

                        const data = await window.sui.api('cp_preview', fd, { toast: false });
                        if (data && data.results) {
                            const fullSource = data.results.map(r => r.export_block || "").join("\n");
                            const wrapped = "~~~\n" + fullSource.trim() + "\n\n~~~";
                            await navigator.clipboard.writeText(wrapped);
                            window.sui.toast(`${activeFiles.length} Files Copied`);
                        }
                    } catch(e) { window.sui.toast("Copy failed: " + e.message); }
                    
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                    window.suiHydrateIcons(btn);
                };
            }
        });
    },

    bindFileLongPress: function(el, path) {
        let timer = null;
        const start = (e) => {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            timer = setTimeout(() => {
                timer = null;
                if (window.sui && window.sui.haptic) window.sui.haptic('medium');
                this.handleFileLongPress(path);
            }, 600);
        };
        const clear = () => { if(timer) { clearTimeout(timer); timer = null; } };
        el.addEventListener('pointerdown', start);
        el.addEventListener('pointerup', clear);
        el.addEventListener('pointerleave', clear);
        el.addEventListener('contextmenu', e => e.preventDefault());
    },

    handleFileLongPress: function(path) {
        const ext = path.split('.').pop().toLowerCase();
        const isSupported = window.fsSupportedExtensions && window.fsSupportedExtensions.includes(ext);

        const options = [
            { label: "📋 Copy Source Code", value: "copy" },
            { label: "📥 Download Context TXT", value: "download" },
            { label: "🛠️ Get Source from Patcher", value: "patcher" }
        ];

        if (isSupported && typeof window.fsOpen === 'function') {
            options.unshift({ label: "📂 Open in File Studio", value: "studio" });
        }

        window.openPicker(path.split('/').pop(), options, null, (val) => {
            if (val === 'studio') {
                window.fsOpen(path);
            }
            if (val === 'download') {
                window.sui.api("cp_preview", {
                    patch_count: 1,
                    p_0_file: path,
                    p_0_action: "export",
                    p_0_find: "",
                    p_0_replace: "",
                    p_0_match: 1
                }, { toast: "Preparing download..." }).then(data => {
                    if (data && data.results && data.results[0].export_block) {
                        const block = data.results[0].export_block;
                        const wrapped = "~~~\n" + block.trim() + "\n\n~~~";
                        const filename = "Context_" + path.split('/').pop() + ".txt";
                        if (typeof cpDownloadText === 'function') {
                            cpDownloadText(filename, wrapped);
                        } else {
                            const blob = new Blob([wrapped], { type: 'text/plain;charset=utf-8' });
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.style.display = 'none';
                            a.href = url;
                            a.download = filename;
                            document.body.appendChild(a);
                            a.click();
                            setTimeout(() => { document.body.removeChild(a); window.URL.revokeObjectURL(url); }, 100);
                        }
                    }
                }).catch(e => window.sui.toast("Download failed: " + e.message));
            }
            if (val === 'copy') {
                if (typeof cpCopyFileSource === 'function') {
                    cpCopyFileSource(path);
                } else {
                    window.openConfirm("Feature Unavailable", "File Patch Manager plugin is required to fetch source code.", null, false, "OK", null);
                }
            }
            if (val === 'patcher') {
                const exportBlock = `#PATCH_ID: export_${Date.now()}\n#FILE: ${path}\n#ACTION: export\n#END`;
                const cpInp = document.getElementById('cp-input');
                if (cpInp && typeof window.cpOpenStudio === 'function') {
                    cpInp.value = exportBlock;
                    window.cpOpenStudio();
                    if (typeof window.cpVerifyBatch === 'function') window.cpVerifyBatch();
                } else {
                    window.openConfirm("Feature Unavailable", "File Patch Manager plugin is required for this action.", null, false, "OK", null);
                }
            }
        });
    },

    takeSnapshot: function(container) {
        if (!container) return null;
        const data = {};
        // 1. Standard Form Elements
        container.querySelectorAll('input, textarea, select').forEach(el => {
            const key = el.id || el.name;
            if (!key || el.type === 'button' || el.type === 'submit') return;
            
            if (el.type === 'checkbox' || el.type === 'radio') {
                data[key] = el.checked;
            } else if (el.type === 'number' || el.type === 'range') {
                const num = parseFloat(el.value);
                data[key] = isNaN(num) ? 0 : parseFloat(num.toFixed(4));
            } else {
                data[key] = el.value.trim();
            }
        });
        // 2. Custom Captures (for labels or hidden state)
        container.querySelectorAll('[data-sui-capture]').forEach(el => {
            const key = el.getAttribute('data-sui-capture');
            data[key] = el.innerText.trim();
        });
        return JSON.stringify(data);
    },

    hasChanges: function(container, initialSnapshot) {
        if (!initialSnapshot) return false;
        return this.takeSnapshot(container) !== initialSnapshot;
    },

    toggleActions: function(card, actionElements, sourceBadge) {
        const content = card.querySelector('.card-content');
        const container = window.getActionContainer(content);
        if (!container) return;

        const sourceId = sourceBadge.getAttribute('data-sui-id') || sourceBadge.innerText;
        
        // Toggle Logic: If clicking the same badge that is already active, close it
        if (container.classList.contains('open') && container.dataset.sourceId === sourceId) {
            container.classList.remove('open');
            sourceBadge.classList.remove('sui-badge-active');
            return;
        }

        // Clear and populate
        container.innerHTML = "";
        actionElements.forEach(el => {
            if (el instanceof HTMLElement) {
                // Ensure action badges are smaller/distinct
                el.style.fontSize = '10px';
                el.style.padding = '2px 6px';
                container.appendChild(el);
            }
        });
        
        // Mark source
        container.dataset.sourceId = sourceId;
        
        // Highlight source badge
        card.querySelectorAll('.meta-badge').forEach(b => b.classList.remove('sui-badge-active'));
        sourceBadge.classList.add('sui-badge-active');

        container.classList.add('open');
        if (this.haptic) this.haptic('light');
    },

    haptic: function(type = 'light') {
        if (!navigator.vibrate) return;
        const patterns = {
            'light': 30,
            'tap': 30,
            'medium': 65,
            'heavy': 120,
            'success': [30, 50, 30],
            'warning': [60, 60, 60],
            'error': [150, 50, 150],
            'notify': [40, 80]
        };
        const p = patterns[type] || type;
        try { navigator.vibrate(p); } catch(e) {}
    },

    copyText: function(text) {
        return new Promise((resolve, reject) => {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            ta.style.top = '0';
            ta.setAttribute('readonly', ''); // Prevent keyboard on some mobile devices
            document.body.appendChild(ta);
            
            const selected = document.getSelection().rangeCount > 0 ? document.getSelection().getRangeAt(0) : false;
            
            ta.select();
            ta.setSelectionRange(0, 99999); // For mobile
            
            try {
                const success = document.execCommand('copy');
                if (success) resolve(); else reject();
            } catch (err) {
                reject(err);
            }
            
            document.body.removeChild(ta);
            if (selected) {
                document.getSelection().removeAllRanges();
                document.getSelection().addRange(selected);
            }
        });
    }
};

// --- GLOBAL CLIPBOARD HIJACK ---
(function() {
    const hijack = (text) => window.sui.copyText(text);
    if (!navigator.clipboard) {
        navigator.clipboard = { writeText: hijack };
    } else {
        navigator.clipboard.writeText = hijack;
    }
})();

window.suiHydrateSettings = function(container = document) {
    // 0. Sliders
    container.querySelectorAll('[data-sui-slider]').forEach(el => {
        const id = el.getAttribute('data-sui-id');
        const min = el.getAttribute('data-sui-min') || 0;
        const max = el.getAttribute('data-sui-max') || 100;
        const step = el.getAttribute('data-sui-step') || 1;
        const valKey = el.getAttribute('data-sui-value'); 
        const val = (typeof suiConfig !== 'undefined' && suiConfig[valKey]) ? suiConfig[valKey] : min;
        const oninput = el.getAttribute('data-sui-oninput') || '';
        const onchange = el.getAttribute('data-sui-onchange') || '';
        
        el.outerHTML = window.suiSlider(id, min, max, step, val, oninput, onchange);
    });

    // 1. Full Setting Rows
    container.querySelectorAll('[data-sui-setting]').forEach(el => {
        const title = el.getAttribute('data-sui-setting');
        const desc = el.getAttribute('data-sui-desc') || '';
        const id = el.getAttribute('data-sui-id');
        const onchange = el.getAttribute('data-sui-onchange') || '';
        const vertical = el.getAttribute('data-sui-vertical') === 'true';
        const isChecked = el.getAttribute('data-sui-checked') === 'true';

        const actionHtml = window.suiSwitch(id, isChecked, onchange);
        el.outerHTML = window.suiSettingRow(title, desc, actionHtml, vertical);
    });

    // 2. Standalone Switches (for lists like Plugin Manager)
    container.querySelectorAll('[data-sui-switch]').forEach(el => {
        const id = el.getAttribute('data-sui-id');
        const onchange = el.getAttribute('data-sui-onchange') || '';
        const isChecked = el.getAttribute('data-sui-checked') === 'true';
        const isDisabled = el.getAttribute('data-sui-disabled') === 'true';
        el.outerHTML = window.suiSwitch(id, isChecked, onchange, isDisabled ? 'disabled' : '');
    });
};

// Hydrate as soon as DOM is ready, before window.load events fire in plugins
document.addEventListener('DOMContentLoaded', () => {
    window.suiHydrateIcons();
    window.suiHydrateSettings();

    // --- SOURCE-LEVEL BACK GESTURE HOOK ---
    // Automatically hooks any element using the 'shared-menu-overlay' class to the gesture stack.
    // This ensures that custom plugin menus (App Maker, Smart Organizer) support Android Back out-of-the-box.
    const suiBackObserver = new MutationObserver((mutations) => {
        mutations.forEach(m => {
            if (m.type === 'attributes' && (m.attributeName === 'class' || m.attributeName === 'style')) {
                const el = m.target;
                if (!el.classList.contains('shared-menu-overlay')) return;
                
                // Detection: Check for .visible class OR inline style visibility
                const isVisible = el.classList.contains('visible') || el.style.visibility === 'visible';
                const id = el.id || 'anon-overlay';
                
                if (isVisible) {
                    window.sui.registerOverlay(id, () => {
                        // Dismissal Heuristic: Find a close button or force-remove visibility
                        // We prioritize explicit close classes and 'starts-with' onclick matches.
                        // We use querySelectorAll to find the BEST match rather than just the FIRST match.
                        const candidates = Array.from(el.querySelectorAll('.sui-close-trigger, .sui-studio-close, .settings-close, button[onclick^="close"], button[onclick^="window.close"]'));
                        const closeBtn = candidates.find(c => c.classList.contains('sui-close-trigger') || c.classList.contains('sui-studio-close')) || candidates[0];
                        
                        if (closeBtn) {
                            closeBtn.click();
                        } else {
                            el.classList.remove('visible');
                            el.style.visibility = 'hidden';
                            el.style.opacity = '0';
                        }
                    });
                } else {
                    window.sui.unregisterOverlay(id);
                }
            }
        });
    });

    // 1. Hook existing overlays
    document.querySelectorAll('.shared-menu-overlay').forEach(el => {
        suiBackObserver.observe(el, { attributes: true, attributeFilter: ['class', 'style'] });
    });

    // 2. Watch for new overlays (Dynamic plugins or injected apps)
    const suiDomObserver = new MutationObserver((mutations) => {
        mutations.forEach(m => {
            m.addedNodes.forEach(node => {
                if (node.nodeType === 1) {
                    const targets = node.classList.contains('shared-menu-overlay') ? [node] : node.querySelectorAll('.shared-menu-overlay');
                    targets.forEach(t => suiBackObserver.observe(t, { attributes: true, attributeFilter: ['class', 'style'] }));
                }
            });
        });
    });
    suiDomObserver.observe(document.body, { childList: true, subtree: true });
    
    // --- METADATA REFRESH HOOKS ---
    // Automatically re-run decoration when transcription finishes or text is updated
    const refreshBadges = (id, entry) => {
        const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
        const card = cb ? cb.closest('.card') : null;
        if (card && entry) window.sui.decorateCard(card, entry);
    };

    if (window.cjosHooks) {
        window.cjosHooks.register('onTranscribe', (id, text, entry) => refreshBadges(id, entry));
    }

    if (window.registerUpdateHook) {
        window.registerUpdateHook((id, entry) => refreshBadges(id, entry));
    }

    // Register the Badge Engine as a core card plugin
    if (window.registerCardPlugin) {
        window.registerCardPlugin((card, entry) => {
            // Safety: If entry is missing (e.g., from a partial render), try to find it
            if (!entry) {
                const id = card.querySelector('.custom-checkbox')?.getAttribute('data-id');
                entry = (typeof logs !== 'undefined') ? logs.find(l => l.id === id) : null;
            }
            if (entry) window.sui.decorateCard(card, entry);
        }, 40); // Priority 40: Content/Metadata tier
    }
});

// --- SHARED PICKER LOGIC ---
const pickerOverlay = document.getElementById("shared-picker-overlay");
const pickerSheet = document.getElementById("shared-picker-sheet");
const pickerTitle = document.getElementById("shared-picker-title");
const pickerList = document.getElementById("shared-picker-list");
let pickerHideTimer = null;
let _suiPickerGen = 0;
      
window.openPicker = function(title, options, currentVal, onSelect, searchable = false, extraHtml = null) {
    if(!pickerOverlay) return;
    _suiPickerGen++;
    if (window.sui) {
        window.sui.registerOverlay('sui-picker', closeSharedPicker);
        pickerOverlay.style.zIndex = window.sui.getNextZIndex().toString();
    }
          
    // CRITICAL FIX: Cancel pending close to prevent race conditions
    if(pickerHideTimer) { clearTimeout(pickerHideTimer); pickerHideTimer = null; }
      // 1. Set Title & Extra
    pickerTitle.innerText = title;
    const headerExtra = document.getElementById("shared-picker-header-extra");
    if (headerExtra) {
        headerExtra.innerHTML = extraHtml || "";
        window.suiHydrateIcons(headerExtra);
    }

    // 2. Handle Search UI
    const searchCont = document.getElementById("shared-picker-search-container");
    const searchInp = document.getElementById("shared-picker-search-input");
    if (searchCont && searchInp) {
        searchCont.style.display = searchable ? "block" : "none";
        searchInp.value = "";
        searchInp.oninput = () => {
            const q = searchInp.value.toLowerCase().trim();
            Array.from(pickerList.children).forEach(child => {
                if (child.style.display === "none" && q === "") { /* stay hidden if it was a custom hide */ }
                const text = child.innerText.toLowerCase();
                // Headers are hidden during search to keep results clean
                const isHeader = child.style.fontSize === "10px"; 
                if (q === "") {
                    child.style.display = "";
                } else {
                    child.style.display = (!isHeader && text.includes(q)) ? "" : "none";
                }
            });
        };
    }
    
    // 3. Render Options
    pickerList.innerHTML = "";
    options.forEach(opt => {
        if (opt.type === "header") {
            const hr = document.createElement("div");
            const isFirst = pickerList.children.length === 0;
            const marginTop = isFirst ? "0" : "8px";
            const borderTop = isFirst ? "none" : "1px solid var(--border-color)";
            const paddingTop = isFirst ? "0" : "12px";
            
            hr.style.cssText = `margin: ${marginTop} 0 8px 0; padding-top: ${paddingTop}; border-top: ${borderTop}; font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1.2px; text-align: center; pointer-events: none; opacity: 0.8;`;
            hr.innerHTML = opt.label;
            pickerList.appendChild(hr);
            return;
        }
        if (opt.type === "info") {
            const info = document.createElement("div");
            // Non-button styling: subtle left border, no shadow, transparent/dimmed background
            const borderStyle = opt.noBorder ? "" : "border-left: 3px solid #E5E5EA;";
            info.style.cssText = `padding: 10px 16px; ${borderStyle} margin-left: 4px; margin-bottom: 4px; pointer-events: none;`;
            info.innerHTML = opt.label;
            pickerList.appendChild(info);
            return;
        }
        const item = document.createElement("div");
        const isSelected = opt.value === currentVal;
        
        if (opt.noStyle) {
            item.style.cssText = "background: transparent; border: none; box-shadow: none; padding: 0; cursor: pointer; display: block;";
        } else {
            item.style.cssText = `
                background: var(--card-bg); padding: 16px; border-radius: 14px; 
                display: flex; justify-content: space-between; align-items: center;
                cursor: pointer; transition: background 0.2s;
                border: ${isSelected ? "1px solid var(--primary)" : "1px solid var(--border-color)"};
                box-shadow: 0 2px 5px rgba(0,0,0,0.02);
            `;
        }
        
        item.innerHTML = `
            <div style="flex:1; font-size:16px; font-weight:${isSelected ? "600" : "400"}; color:var(--text-primary); min-width:0;">${opt.label}</div>
            ${isSelected ? `<span data-sui-icon="check" data-sui-color="var(--primary)" data-sui-size="20" data-sui-stroke="2.5"></span>` : ""}
        `;
        
        item.onclick = () => {
            const localGen = _suiPickerGen;
            if(onSelect) onSelect(opt.value);
            if(localGen === _suiPickerGen) closeSharedPicker();
        };
        pickerList.appendChild(item);
        
        // Hook into Scroll Reveal
        if (typeof window.srWatch === "function") window.srWatch(item);
    });
    
    // Hydrate icons within the list (including checkmarks and custom labels)
    window.suiHydrateIcons(pickerList);

    // 3. Show
    pickerOverlay.style.visibility = "visible";
    // Use rAF to ensure transition plays correctly even if we interrupted a close
    requestAnimationFrame(() => {
        pickerOverlay.style.opacity = "1";
        pickerSheet.style.transform = "translateY(0) translateZ(0)";
    });
};

window.closeSharedPicker = function() {
    if (window.sui) window.sui.unregisterOverlay('sui-picker');
    pickerSheet.style.transform = "translateY(100%) translateZ(0)";
    pickerOverlay.style.opacity = "0";
    if(pickerHideTimer) clearTimeout(pickerHideTimer);
    pickerHideTimer = setTimeout(() => { 
        pickerOverlay.style.visibility = "hidden"; 
        pickerHideTimer = null;
    }, 300);
};



// --- SHARED INPUT DIALOG ---
const inputOverlay = document.getElementById("shared-input-overlay");
const inputSheet = document.getElementById("shared-input-sheet");
const inputTitle = document.getElementById("shared-input-title");
const inputField = document.getElementById("shared-input-field");
let currentInputCallback = null;
window._suiInputMultiline = false;

window.openInput = function(title, placeholder, defaultVal, callback, multiline = false, options = {}) {
    if(!inputOverlay) return;
    if (window.sui) {
        window.sui.registerOverlay('shared-input-overlay', window.closeInput);
        inputOverlay.style.zIndex = window.sui.getNextZIndex().toString();
    }
    inputTitle.innerText = title;
    inputField.placeholder = placeholder;
    inputField.value = defaultVal || "";
    currentInputCallback = callback;
    window._suiInputMultiline = multiline;

    // Reset height and handle auto-expand
    inputField.style.height = multiline ? '120px' : '50px';
    inputField.oninput = () => {
        if (window._suiInputMultiline) {
            inputField.style.height = 'auto';
            inputField.style.height = inputField.scrollHeight + 'px';
        }
    };
    
    inputOverlay.style.visibility = "visible";
    inputOverlay.style.opacity = "1";
    inputSheet.style.transform = "translateY(0)";
    
    setTimeout(() => {
        if (!options.noFocus) {
            inputField.focus();
            inputField.select();
        }
        if (multiline) inputField.dispatchEvent(new Event('input'));
    }, 300);
};

window.getMetaContainer = function(cardContent) {
    if (!cardContent) return null;
    let row = cardContent.querySelector('.card-meta-row');
    if (!row) {
        row = document.createElement('div');
        row.className = 'card-meta-row';
        cardContent.appendChild(row);
    }
    return row;
};

window.getActionContainer = function(cardContent) {
    if (!cardContent) return null;
    let row = cardContent.querySelector('.card-action-row');
    if (!row) {
        row = document.createElement('div');
        row.className = 'card-action-row';
        const meta = window.getMetaContainer(cardContent);
        if (meta) meta.after(row);
    }
    return row;
};

window.closeInput = function() {
    if (window.sui) window.sui.unregisterOverlay('shared-input-overlay');
    inputSheet.style.transform = "translateY(100%)";
    inputOverlay.style.opacity = "0";
    setTimeout(() => { inputOverlay.style.visibility = "hidden"; }, 300);
};

window.submitSharedInput = function() {
    const val = inputField.value.trim();
    if(currentInputCallback) currentInputCallback(val);
    closeInput();
};

// Handle Enter Key
inputField.addEventListener("keydown", (e) => {
    if(e.key === "Enter" && !window._suiInputMultiline) {
        e.preventDefault();
        submitSharedInput();
    }
});

// --- PREVIEW HELPERS ---
window.suiPreviewPicker = function() {
    const options = [
        { label: "Option A (Simple)", value: "a" },
        { label: "Option B (Active)", value: "b" },
        { label: "Group Header", type: "header" },
        { label: "Option C (Searchable)", value: "c" },
        { label: "Informational Entry", type: "info" }
    ];
    window.openPicker("Shared Picker Demo", options, "b", (val) => {
        const t = document.getElementById("toast");
        if(t) { t.innerText = "Selected: " + val; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
    }, true);
};

window.suiPreviewInput = function() {
    window.openInput("Shared Input Demo", "Type something...", "Initial Value", (val) => {
        const t = document.getElementById("toast");
        if(t) { t.innerText = "Saved: " + val; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
    });
};

window.suiPreviewConfirm = function(isDanger) {
    const title = isDanger ? "Demo Warning" : "Demo Notice";
    const msg = isDanger 
        ? "This is a demonstration of the danger style. No real data will be harmed during this test."
        : "This is a demonstration of the standard primary style for non-destructive actions.";

    window.openConfirm(title, msg, () => {
        const t = document.getElementById("toast");
        if(t) { t.innerText = "Demo Confirmed"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
    }, isDanger);
};

window.suiPreviewIcons = function() {
    const keys = window.suiIcon('__KEYS__');
    if (!keys || !Array.isArray(keys)) { window.sui.toast('Icon list unavailable'); return; }
    keys.sort();
    
    let html = `<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap:12px; padding-bottom:40px;">`;
    
    keys.forEach(k => {
        html += `
            <div onclick="navigator.clipboard.writeText('${k}'); window.sui.toast('Copied: ${k}')" 
                 style="display:flex; flex-direction:column; align-items:center; gap:8px; padding:12px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; cursor:pointer; transition:transform 0.2s;">
                ${window.suiIcon(k, 'var(--primary)', 24)}
                <div style="font-size:10px; font-family:monospace; color:var(--text-secondary); text-align:center; word-break:break-all;">${k}</div>
            </div>
        `;
    });
    html += `</div>`;

    window.sui.openStudio({
        id: 'icon-lib',
        title: 'Icon Library',
        content: html
    });
};
JS;
?>