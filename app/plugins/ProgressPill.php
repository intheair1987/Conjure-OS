<?php
// ==============================================================================
// PLUGIN: Progress Pill
// DESCRIPTION: Global Operation Status.
// Purpose: Provides a global, aesthetic floating status pill for batch 
// operations (Deletion, Optimization, etc.) with configurable position.
// API: window.cjosProgressPill.show(text), .update(text, percent), .done(text)
// ==============================================================================

$pp_config_file = CJOS_PATH_DATA . '/progress-pill-config.json';

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'pp_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $settings = json_decode($_POST['settings'], true);
        file_put_contents($pp_config_file, json_encode($settings));
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['plugin_action'] === 'pp_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults = ['top_pos' => 50];
        $config = file_exists($pp_config_file) ? json_decode(file_get_contents($pp_config_file), true) : $defaults;
        echo json_encode(['status' => 'success', 'config' => $config]);
        exit;
    }
}

$plugin_settings_map['ProgressPill'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Vertical Position</label>
        <div class="setting-desc">Adjust where the status pill appears on your screen.</div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
            <input type="range" id="pp-pos-slider" min="5" max="95" step="1" oninput="ppUpdatePos(this.value)" onchange="ppSaveSettings()" style="flex:1;">
            <span id="pp-pos-val" style="font-weight:700; color:var(--primary); min-width:40px;">50%</span>
        </div>
    </div>
    <div id="pp-save-status" style="text-align:right; font-size:11px; color:#8E8E93; padding:0 16px 8px; height:14px;"></div>
HTML;

$plugin_js .= <<<'JS'
// --- PROGRESS PILL GLOBAL UI ---

(function() {
    let ppSettings = { top_pos: 50 };

    const style = document.createElement('style');
    style.innerHTML = `
        #cjos-progress-pill {
            position: fixed;
            top: var(--pp-top-pos, 50%);
            left: 50%;
            transform: translate(-50%, -50%) scale(0.85);
            background: var(--glass-bg, var(--card-bg));
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 12px 20px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            color: var(--text-primary);
            box-shadow: var(--shadow-floating, 0 12px 40px rgba(0,0,0,0.12));
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: 
                opacity 0.4s cubic-bezier(0.2, 0, 0, 1), 
                transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1), 
                visibility 0.4s, 
                top 0.3s ease;
            pointer-events: none;
            border: 1px solid var(--glass-border, var(--border-color));
            min-width: 200px;
            max-width: 85vw;
        }

        #cjos-progress-pill.visible {
            opacity: 1;
            visibility: visible;
            transform: translate(-50%, -50%) scale(1);
        }

        .cjos-pp-spinner {
            width: 18px;
            height: 18px;
            border: 2.5px solid color-mix(in srgb, var(--primary), transparent 85%);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: cjos-pp-spin 0.8s cubic-bezier(0.4, 0, 0.2, 1) infinite;
            flex-shrink: 0;
        }

        @keyframes cjos-pp-spin { to { transform: rotate(360deg); } }

        .cjos-pp-text {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: -0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-variant-numeric: tabular-nums;
        }

        .cjos-pp-track {
            position: absolute;
            bottom: 6px;
            left: 20px;
            right: 20px;
            height: 3px;
            background: color-mix(in srgb, var(--text-primary), transparent 92%);
            border-radius: 2px;
            overflow: hidden;
        }

        #cjos-pp-fill {
            width: 0%;
            height: 100%;
            background: var(--primary);
            transition: width 0.4s cubic-bezier(0.2, 0, 0, 1);
            border-radius: 2px;
        }

        .cjos-pp-success-icon {
            display: none;
            width: 18px;
            height: 18px;
            color: #34C759;
            flex-shrink: 0;
            stroke-width: 3;
        }

        #cjos-progress-pill.done-state .cjos-pp-spinner { display: none; }
        #cjos-progress-pill.done-state .cjos-pp-success-icon { display: block; }
        #cjos-progress-pill.done-state #cjos-pp-fill { background: #34C759; }
    `;
    document.head.appendChild(style);

    window.addEventListener('load', () => {
        const pill = document.createElement('div');
        pill.id = 'cjos-progress-pill';
        pill.innerHTML = `
            <div class="cjos-pp-spinner"></div>
            <svg class="cjos-pp-success-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <div class="cjos-pp-text" id="cjos-pp-status-text">Processing...</div>
            <div class="cjos-pp-track">
                <div id="cjos-pp-fill"></div>
            </div>
        `;
        document.body.appendChild(pill);
        ppLoadSettings();
    });

    async function ppLoadSettings() {
        try {
            const data = await window.sui.api("pp_get_config", {}, { toast: false });
            if (data && data.config) {
                // Merge config to preserve defaults if keys are missing
                ppSettings = { ...ppSettings, ...data.config };
                const slider = document.getElementById("pp-pos-slider");
                if(slider) {
                    slider.value = ppSettings.top_pos;
                    ppUpdatePos(ppSettings.top_pos, true);
                }
            }
        } catch(e) {}
    }

    window.ppUpdatePos = function(val, silent = false) {
        const finalVal = val || ppSettings.top_pos || 50;
        const label = document.getElementById("pp-pos-val");
        if (label) label.innerText = finalVal + "%";
        document.documentElement.style.setProperty('--pp-top-pos', finalVal + '%');
        
        if (!silent) {
            const pill = document.getElementById('cjos-progress-pill');
            if(pill) {
                pill.classList.add('visible');
                if(window._ppPreviewTimer) clearTimeout(window._ppPreviewTimer);
                window._ppPreviewTimer = setTimeout(() => pill.classList.remove('visible'), 1500);
            }
        }
    };

    window.ppSaveSettings = async function() {
        const status = document.getElementById("pp-save-status");
        if(status) status.innerText = "Saving...";
        
        ppSettings.top_pos = parseInt(document.getElementById("pp-pos-slider").value);

        try {
            await window.sui.api("pp_save_config", { settings: ppSettings }, { toast: false });
            if(status) {
                status.innerText = "Saved";
                setTimeout(() => status.innerText = "", 2000);
            }
        } catch(e) { if(status) status.innerText = "Error"; }
    };

    // GLOBAL API
    window.cjosProgressPill = {
        show: function(text) {
            const pill = document.getElementById('cjos-progress-pill');
            const statusText = document.getElementById('cjos-pp-status-text');
            const fill = document.getElementById('cjos-pp-fill');
            if (statusText) statusText.textContent = text;
            if (fill) fill.style.width = '0%';
            if (pill) {
                pill.classList.remove('done-state');
                pill.classList.add('visible');
            }
        },
        update: function(text, percent) {
            const statusText = document.getElementById('cjos-pp-status-text');
            const fill = document.getElementById('cjos-pp-fill');
            if (statusText) statusText.textContent = text;
            if (fill) fill.style.width = percent + '%';
        },
        done: function(text, delay = 1500) {
            const statusText = document.getElementById('cjos-pp-status-text');
            const fill = document.getElementById('cjos-pp-fill');
            const pill = document.getElementById('cjos-progress-pill');
            if (statusText) statusText.textContent = text || "Done";
            if (fill) fill.style.width = '100%';
            if (pill) pill.classList.add('done-state');
            
            setTimeout(() => {
                if (pill) pill.classList.remove('visible');
            }, delay);
        },
        hide: function() {
            const pill = document.getElementById('cjos-progress-pill');
            if (pill) pill.classList.remove('visible');
        }
    };
})();
JS;
