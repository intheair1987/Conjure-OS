<?php
// ==============================================================================
// PLUGIN: MacroDroid Bridge
// DESCRIPTION: Android Actuator System.
// ==============================================================================

$mb_config_file = CJOS_PATH_DATA . '/macrodroid-config.json';
$mb_private_file = CJOS_PATH_DATA . '/macrodroid-private.json';

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {

    if ($_POST['plugin_action'] === 'mb_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $defaults = [
            'local_port' => '8080',
            'local_path' => 'aibridge',
            'routing_mode' => 'localhost',
            'macro_json' => '',
            'no_cors' => false,
            'fast_abort' => true
        ];
        $conf = file_exists($mb_config_file) ? json_decode(file_get_contents($mb_config_file), true) : $defaults;
        $conf = array_merge($defaults, $conf);
        
        $priv_defaults = ['webhook_url' => ''];
        $priv = file_exists($mb_private_file) ? json_decode(file_get_contents($mb_private_file), true) : $priv_defaults;
        
        // Dynamic Verb Extraction
        $verbs = mb_parse_macro_verbs($conf['macro_json'] ?? '');
        
        echo json_encode(['status' => 'success', 'config' => $conf, 'private' => $priv, 'verbs' => $verbs]);
        exit;
    }

    if ($_POST['plugin_action'] === 'mb_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $conf = [
            'local_port' => $_POST['local_port'],
            'local_path' => $_POST['local_path'],
            'routing_mode' => $_POST['routing_mode'],
            'macro_json' => $_POST['macro_json'],
            'no_cors' => ($_POST['no_cors'] === 'true'),
            'fast_abort' => ($_POST['fast_abort'] === 'true')
        ];
        $priv = [
            'webhook_url' => $_POST['webhook_url']
        ];
        
        file_put_contents($mb_config_file, json_encode($conf, JSON_PRETTY_PRINT));
        file_put_contents($mb_private_file, json_encode($priv, JSON_PRETTY_PRINT));
        
        // Return new verbs immediately so the UI can sync without a refresh
        $verbs = mb_parse_macro_verbs($conf['macro_json']);
        
        echo json_encode(['status' => 'success', 'verbs' => $verbs]);
        exit;
    }
}

/**
 * Parses MacroDroid JSON to find 'trigger' values in If blocks.
 */
function mb_parse_macro_verbs($jsonStr) {
    $data = json_decode($jsonStr, true);
    if (!$data || !isset($data['macro']['m_actionList'])) return [];

    $verbs = [];
    $currentVerb = null;
    
    foreach ($data['macro']['m_actionList'] as $action) {
        $type = $action['m_classType'] ?? '';
        
        if ($type === 'IfConditionAction') {
            foreach (($action['m_constraintList'] ?? []) as $constraint) {
                if (($constraint['m_classType'] ?? '') === 'MacroDroidVariableConstraint') {
                    $varName = $constraint['m_variable']['m_name'] ?? '';
                    if ($varName === 'trigger') {
                        $verbName = $constraint['m_stringValue'] ?? '';
                        if ($verbName) {
                            $currentVerb = $verbName;
                            $verbs[$currentVerb] = [
                                'id' => $verbName,
                                'label' => strtoupper(str_replace('_', ' ', $verbName)),
                                'has_response' => false,
                                'params' => []
                            ];
                        }
                    }
                }
            }
        } elseif ($currentVerb) {
            if ($type === 'HttpServerResponseAction') {
                $verbs[$currentVerb]['has_response'] = true;
            }
            
            // Deep Scan for dictionary keys: values[key_name]
            $actionJson = json_encode($action);
            if (preg_match_all('/values\[([^\]]+)\]/', $actionJson, $matches)) {
                foreach ($matches[1] as $key) {
                    if (!in_array($key, $verbs[$currentVerb]['params'])) {
                        $verbs[$currentVerb]['params'][] = $key;
                    }
                }
            }

            if ($type === 'EndIfAction') {
                $currentVerb = null;
            }
        }
    }
    return array_values($verbs);
}

// --- SETTINGS UI ---
$plugin_settings_map['MacroDroidBridge'] = <<<'HTML'
    <div style="padding: 16px 16px 12px 16px;">
        <button onclick="mbOpenStudio()" class="btn-primary" style="width:100%; gap:10px; background:var(--ai-accent); box-shadow: 0 4px 12px rgba(88, 86, 214, 0.2);">
            <span data-sui-icon="activity" data-sui-size="18"></span> Open Actuator Studio
        </button>
    </div>
HTML;

$plugin_js .= <<<'JS'
// --- MACRODROID BRIDGE JS ---

window.addEventListener('load', mbLoadConfig);

async function mbLoadConfig() {
    try {
        const data = await window.sui.api('mb_get_config', {}, { toast: false });
        if (data) {
            window._mbConfig = data.config || {};
            window._mbPrivate = data.private || {};
            window._mbVerbs = data.verbs || [];
            
            // If Studio is open, refresh its inputs
            const studio = document.getElementById('sui-studio-macrodroid');
            if (studio) mbPopulateStudioFields();
        }
    } catch(e) {}
}

function mbPopulateStudioFields() {
    const c = window._mbConfig;
    const p = window._mbPrivate;
    
    const elMode = document.getElementById('mb-routing-mode');
    const elPort = document.getElementById('mb-local-port');
    const elPath = document.getElementById('mb-local-path');
    const elJson = document.getElementById('mb-macro-json');
    const elWebh = document.getElementById('mb-webhook-url');
    
    if (elMode) elMode.value = c.routing_mode || 'localhost';
    if (elPort) elPort.value = c.local_port || '8080';
    if (elPath) elPath.value = c.local_path || 'aibridge';
    if (elJson) elJson.value = c.macro_json || '';
    if (elWebh) elWebh.value = p.webhook_url || '';
    
    const elNoCors = document.getElementById('mb-no-cors-toggle');
    const elFastAbort = document.getElementById('mb-fast-abort-toggle');
    if (elNoCors) elNoCors.checked = c.no_cors === true;
    if (elFastAbort) elFastAbort.checked = c.fast_abort !== false;
    
    mbRenderVerbList();
}

window.mbSaveConfig = async function() {
    const payload = {
        routing_mode: document.getElementById('mb-routing-mode').value,
        local_port: document.getElementById('mb-local-port').value,
        local_path: document.getElementById('mb-local-path').value,
        macro_json: document.getElementById('mb-macro-json').value,
        webhook_url: document.getElementById('mb-webhook-url').value,
        no_cors: document.getElementById('mb-no-cors-toggle').checked,
        fast_abort: document.getElementById('mb-fast-abort-toggle').checked
    };
    
    const data = await window.sui.api('mb_save_config', payload, { toast: "Bridge Config Saved" });
    
    if (data && data.verbs) {
        // 1. Update local verb registry immediately
        window._mbVerbs = data.verbs;
        
        // 2. Reset active selection to prevent "stale" verb logic
        window._mbActiveVerb = null;
        document.getElementById('mb-param-box').style.display = 'none';
        
        // 3. Re-render the list
        mbRenderVerbList();
        mbLog("Macro parsed. Verbs updated.");
    }
};

window.mbClearMacroJson = function() {
    const el = document.getElementById('mb-macro-json');
    if (el) {
        el.value = "";
        el.focus();
    }
};

window.mbOpenStudio = function() {
    const c = window._mbConfig || {};
    const content = `
        <div style="display:flex; flex-direction:column; gap:20px;">
            
            <!-- CONFIGURATION SECTION -->
            ${window.suiAccordion('mb-config-acc', 'Connection Configuration', `
                <div style="padding:16px; display:flex; flex-direction:column; gap:16px;">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                        <div class="setting-item vertical" style="padding:0; border:none;">
                            <label class="setting-label" style="font-size:12px;">Routing Mode</label>
                            <select id="mb-routing-mode" onchange="mbSaveConfig()" style="margin-top:4px; height:40px;">
                                <option value="localhost">Localhost</option>
                                <option value="webhook">Cloud Webhook</option>
                            </select>
                        </div>
                        <div class="setting-item vertical" style="padding:0; border:none;">
                            <label class="setting-label" style="font-size:12px;">Local Port</label>
                            <input type="text" id="mb-local-port" placeholder="8080" onchange="mbSaveConfig()" style="margin-top:4px; height:40px;">
                        </div>
                    </div>

                    <div class="setting-item vertical" style="padding:0; border:none;">
                        <label class="setting-label" style="font-size:12px;">Endpoint Path</label>
                        <input type="text" id="mb-local-path" placeholder="aibridge" onchange="mbSaveConfig()" style="margin-top:4px; height:40px; font-family:monospace;">
                    </div>

                    <div class="setting-item vertical" style="padding:0; border:none;">
                        <label class="setting-label" style="font-size:12px;">Cloud Webhook URL (Private)</label>
                        <input type="text" id="mb-webhook-url" placeholder="https://trigger.macrodroid.com/..." onchange="mbSaveConfig()" style="margin-top:4px; height:40px; font-family:monospace; font-size:11px;">
                    </div>

                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; background:var(--bg-color); padding:10px 14px; border-radius:12px; border:1px solid var(--border-color);">
                            <div style="display:flex; flex-direction:column;">
                                <span style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Force No-CORS</span>
                                <span style="font-size:9px; opacity:0.5;">Bypass browser security blocks</span>
                            </div>
                            ${window.suiSwitch('mb-no-cors-toggle', c.no_cors, 'mbSaveConfig()')}
                        </div>
                        <div style="display:flex; align-items:center; justify-content:space-between; background:var(--bg-color); padding:10px 14px; border-radius:12px; border:1px solid var(--border-color);">
                            <div style="display:flex; flex-direction:column;">
                                <span style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Fast-Abort</span>
                                <span style="font-size:9px; opacity:0.5;">Close connection after 500ms</span>
                            </div>
                            ${window.suiSwitch('mb-fast-abort-toggle', c.fast_abort, 'mbSaveConfig()')}
                        </div>
                    </div>

                    <div class="setting-item vertical" style="padding:0; border:none;">
    <label class="setting-label" style="font-size:12px;">MacroDroid Macro JSON</label>
    <div style="position:relative; margin-top:8px;">
        <div style="position:absolute; top:8px; right:8px; z-index:10; display:flex; gap:6px;">
            <input type="file" id="mb-file-input" accept=".macro,.json" style="display:none;" onchange="mbHandleFile(this)">
            <button onclick="document.getElementById('mb-file-input').click()" style="background:var(--btn-bg); border:1px solid var(--border-color); color:var(--primary); font-size:9px; font-weight:900; padding:4px 8px; border-radius:6px; cursor:pointer; text-transform:uppercase; backdrop-filter:blur(4px);">Import .macro</button>
            <button onclick="mbClearMacroJson()" style="background:rgba(0,0,0,0.05); border:1px solid var(--border-color); color:var(--text-secondary); font-size:9px; font-weight:900; padding:4px 8px; border-radius:6px; cursor:pointer; text-transform:uppercase; backdrop-filter:blur(4px);">Clear</button>
        </div>
        <textarea id="mb-macro-json" onfocus="this.select()" placeholder='{"macro": ...}' style="height:120px; font-family:monospace; font-size:10px; background:var(--input-bg); display:block;"></textarea>
    </div>
    <button onclick="mbSaveConfig()" class="text-btn" style="margin-top:8px; width:100%; background:var(--btn-bg); border:1px solid var(--border-color); border-radius:10px; padding:10px; font-weight:700; font-size:12px; color:var(--primary); display:flex; align-items:center; justify-content:center; gap:6px;"><span data-sui-icon="check" data-sui-size="14"></span> Parse & Save Macro
                        </button>
                    </div>
                </div>
            `, false)}

            <!-- TEST BENCH -->
            <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:20px; padding:20px; box-shadow:var(--shadow-card);">
                <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:16px;">Actuator Test Bench</div>
                
                <div id="mb-verb-list" style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:20px;">
                    <!-- Injected Verbs -->
                </div>

                <div id="mb-param-box" style="display:none; border-top:1px solid var(--border-color); padding-top:16px; animation: mbFadeIn 0.3s;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <div id="mb-active-verb-label" style="font-size:12px; font-weight:800; color:var(--primary);"></div>
                        <div id="mb-param-needed-hint" style="font-size:10px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; opacity:0.5;">Required Parameters</div>
                    </div>
                    
                    <div id="mb-dynamic-params-list" style="display:flex; flex-direction:column; gap:8px; margin-bottom:16px;">
                        <!-- Dynamic Inputs Injected Here -->
                    </div>

                    <div style="display:flex; gap:10px;">
                        <button onclick="mbRunTest()" id="mb-btn-run" class="btn-primary" style="flex:1; height:48px; gap:8px;">
                            <span data-sui-icon="activity" data-sui-size="18"></span> Run Test
                        </button>
                        <button onclick="mbRunExternal()" class="text-btn" style="width:48px; height:48px; border-radius:12px; background:var(--btn-bg); color:var(--primary); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                            <span data-sui-icon="layout" data-sui-size="20"></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- RESPONSE VIEWER -->
            <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:20px; padding:20px; box-shadow:var(--shadow-card);">
                <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">Live Response</div>
                <div style="position:relative;">
                    <button onclick="mbCopyResponse()" style="position:absolute; top:8px; right:8px; z-index:10; background:var(--btn-bg); border:1px solid var(--border-color); color:var(--primary); font-size:9px; font-weight:900; padding:4px 8px; border-radius:6px; cursor:pointer; text-transform:uppercase; backdrop-filter:blur(4px);">Copy</button>
                    <pre id="mb-response-box" style="background:var(--bg-color); padding:15px; border-radius:12px; font-family:monospace; font-size:11px; min-height:60px; max-height:250px; margin:0; border:1px solid var(--border-color); white-space:pre-wrap; overflow:auto; -webkit-overflow-scrolling:touch; user-select: text; -webkit-user-select: text;">Waiting for command...</pre>
                </div>
            </div>

            <!-- CONSOLE -->
            <div style="margin-bottom:100px;">
                <div style="display:flex; justify-content:space-between; align-items:center; cursor:pointer; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); background:var(--card-bg);" onclick="suiToggle('mb-console-acc')">
                    <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Bridge Console</div>
                    <span data-sui-icon="chevron" data-sui-arrow="mb-console-acc" data-sui-size="14" style="transition:transform 0.3s; transform:rotate(-90deg);"></span>
                </div>
                <div id="mb-console-acc" class="sui-accordion">
                    <div class="sui-accordion-inner" style="padding:12px 0; position:relative;">
                        <div style="position:absolute; top:20px; right:8px; z-index:10; display:flex; gap:6px;">
                            <button onclick="mbClearConsole()" style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.2); color:#8E8E93; font-size:9px; font-weight:900; padding:4px 8px; border-radius:6px; cursor:pointer; text-transform:uppercase; backdrop-filter:blur(4px);">Clear</button>
                            <button onclick="mbCopyConsole()" style="background:rgba(0,255,65,0.1); border:1px solid rgba(0,255,65,0.3); color:#00FF41; font-size:9px; font-weight:900; padding:4px 8px; border-radius:6px; cursor:pointer; text-transform:uppercase; backdrop-filter:blur(4px);">Copy</button>
                        </div>
                        <div id="mb-console-log" style="background:#000; color:#00FF41; padding:15px; border-radius:12px; font-family:monospace; font-size:11px; height:180px; overflow-y:auto; border:1px solid #333; user-select: text; -webkit-user-select: text;"></div>
                    </div>
                </div>
            </div>
        </div>
        <style>
            @keyframes mbFadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
            .mb-verb-btn { background:var(--btn-bg); border:1px solid var(--border-color); padding:12px; border-radius:12px; font-size:11px; font-weight:700; color:var(--text-primary); cursor:pointer; text-align:center; transition: all 0.2s; }
            .mb-verb-btn.active { background:var(--primary); color:white; border-color:var(--primary); }
        </style>
    `;

    window.sui.openStudio({
        id: 'macrodroid',
        title: 'MacroDroid Actuator Studio',
        content: content,
        onSetup: (container) => {
            mbPopulateStudioFields();
            if (window.suiHydrateIcons) window.suiHydrateIcons(container);
            if (window.suiHydrateSettings) window.suiHydrateSettings(container);
            if (window.suiInit) window.suiInit('mb-config-acc');
            mbLog("Studio Initialized.");
        }
    });
};

function mbLog(msg) {
    const el = document.getElementById('mb-console-log');
    if (!el) return;
    const time = new Date().toLocaleTimeString();
    el.innerHTML += `<div><span style="opacity:0.5;">[${time}]</span> ${msg}</div>`;
    el.scrollTop = el.scrollHeight;
}

window.mbCopyConsole = function() {
    const log = document.getElementById('mb-console-log');
    if (!log) return;
    const text = log.innerText;
    navigator.clipboard.writeText(text).then(() => {
        window.sui.toast("Console Log Copied");
    });
};

window.mbClearConsole = function() {
    const log = document.getElementById('mb-console-log');
    if (log) log.innerHTML = "";
    mbLog("Console Cleared.");
};

window.mbCopyResponse = function() {
    const box = document.getElementById('mb-response-box');
    if (!box) return;
    const text = box.innerText;
    if (text === "Waiting for command..." || text.startsWith("Executing")) return;
    
    navigator.clipboard.writeText(text).then(() => {
        window.sui.toast("Response Copied");
    });
};

function mbRenderVerbList() {
    const cont = document.getElementById('mb-verb-list');
    if (!cont) return;
    
    if (!window._mbVerbs || window._mbVerbs.length === 0) {
        cont.innerHTML = `<div style="grid-column: span 2; text-align:center; padding:20px; opacity:0.5; font-size:12px;">No verbs detected. Paste Macro JSON above.</div>`;
        return;
    }

    cont.innerHTML = window._mbVerbs.map(v => `
        <button class="mb-verb-btn" onclick="mbSelectVerb('${v.id}', this)">${v.label}</button>
    `).join('');
}

window.mbSelectVerb = function(id, btn) {
    document.querySelectorAll('.mb-verb-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    const verbData = window._mbVerbs.find(v => v.id === id);
    window._mbActiveVerb = verbData;
    
    document.getElementById('mb-param-box').style.display = 'block';
    document.getElementById('mb-active-verb-label').innerText = id.toUpperCase();
    
    const hint = document.getElementById('mb-param-needed-hint');
    const list = document.getElementById('mb-dynamic-params-list');
    list.innerHTML = "";

    if (verbData.params && verbData.params.length > 0) {
        hint.innerText = `${verbData.params.length} Parameters Detected`;
        hint.style.color = "var(--primary)";
        
        verbData.params.forEach(p => {
            const row = document.createElement('div');
            row.style.display = 'flex';
            row.style.flexDirection = 'column';
            row.style.gap = '4px';
            
            let defaultVal = "";
            if (p === 'pkg_name') defaultVal = "com.android.settings";

            row.innerHTML = `
                <label style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-left:4px;">${p.replace('_', ' ')}</label>
                <input type="text" class="mb-dynamic-input" data-key="${p}" value="${defaultVal}" placeholder="Enter ${p}..." style="height:44px;">
            `;
            list.appendChild(row);
        });
    } else {
        hint.innerText = "No Params Needed";
        hint.style.color = "var(--text-secondary)";
        list.innerHTML = `<div style="font-size:12px; opacity:0.4; text-align:center; padding:10px; border:1px dashed var(--border-color); border-radius:10px;">This verb has no dictionary dependencies.</div>`;
    }
};



window.mbGetTestUrl = function() {
    const verb = window._mbActiveVerb;
    if (!verb) return "";
    
    // Aggregate dynamic inputs from the new UI
    let queryParams = "";
    document.querySelectorAll('.mb-dynamic-input').forEach(inp => {
        const key = inp.getAttribute('data-key');
        const val = inp.value.trim();
        if (val) {
            queryParams += `&values(${key})=${encodeURIComponent(val)}`;
        }
    });
    
    const mode = (window._mbConfig && window._mbConfig.routing_mode) ? window._mbConfig.routing_mode : 'localhost';
    const port = (window._mbConfig && window._mbConfig.local_port) ? window._mbConfig.local_port : '8080';
    const path = (window._mbConfig && window._mbConfig.local_path) ? window._mbConfig.local_path : 'aibridge';
    const webhook = (window._mbPrivate && window._mbPrivate.webhook_url) ? window._mbPrivate.webhook_url : '';
    
    return (mode === 'localhost') 
        ? `http://localhost:${port}/${path}?trigger=${verb.id}${queryParams}`
        : `${webhook}?trigger=${verb.id}${queryParams}`;
};

window.mbRunExternal = function() {
    const url = mbGetTestUrl();
    if (!url) return;
    mbLog(`Opening External Test: ${url}`);
    window.open(url, '_blank');
};

window.mbRunTest = async function() {
    const verb = window._mbActiveVerb;
    if (!verb) return;

    const resBox = document.getElementById('mb-response-box');
    const runBtn = document.getElementById('mb-btn-run');
    
    const configNoCors = (window._mbConfig && window._mbConfig.no_cors === true);
    const configFastAbort = (window._mbConfig && window._mbConfig.fast_abort !== false);
    
    const useNoCors = configNoCors || !verb.has_response;
    const useFastAbort = useNoCors && configFastAbort;
    
    runBtn.disabled = true;
    resBox.innerText = "Executing...";
    
    const url = mbGetTestUrl();
    mbLog(`Triggering ${verb.id}...`);
    mbLog(`URL: ${url}`);
    
    const controller = new AbortController();
    const timeoutId = useFastAbort ? setTimeout(() => controller.abort(), 500) : null;

    try {
        const start = performance.now();
        const response = await fetch(url, { 
            mode: useNoCors ? 'no-cors' : 'cors',
            cache: 'no-cache',
            signal: controller.signal
        });
        const duration = (performance.now() - start).toFixed(0);
        
        // Opaque responses (no-cors) always have status 0 and ok: false.
        // We only throw if it's a CORS request that failed.
        if (!useNoCors && !response.ok) {
            throw new Error(`HTTP Error ${response.status}`);
        }
        
        if (useNoCors) {
            mbLog(`Command Sent (Fire & Forget) in ${duration}ms`);
            resBox.innerText = "SUCCESS: Command sent to phone.\n\nNote: This verb does not provide a response in the macro, so the browser cannot verify success, but the signal was dispatched.";
            return;
        }

        if (useNoCors) {
            mbLog(`Command Sent (Fire & Forget) in ${duration}ms`);
            mbLog(`Note: Response body is hidden in No-CORS mode.`);
            resBox.innerText = "SUCCESS: Command sent to phone.\n\nNote: This verb is currently in 'Fire & Forget' mode. To see data here, ensure the macro has a Response action and re-parse the JSON.";
            return;
        }

        const text = await response.text();
        mbLog(`Success in ${duration}ms`);
        mbLog(`Response Body: ${text || "(Empty)"}`);
        
        try {
            const json = JSON.parse(text);
            resBox.innerText = JSON.stringify(json, null, 2);
        } catch(e) {
            resBox.innerText = text || "OK (Empty Response)";
        }
        
    } catch(e) {
        if (e.name === 'AbortError' && useNoCors) {
            mbLog(`Signal Dispatched (Fast-Abort)`);
            resBox.innerText = "SUCCESS: Signal sent to phone.\n\nNote: Connection was closed early to prevent delay.";
        } else {
            mbLog(`Error: ${e.message}`);
            resBox.innerText = "Error: " + e.message;
        }
    } finally {
        if (timeoutId) clearTimeout(timeoutId);
        runBtn.disabled = false;
    }
};

window.mbHandleFile = function(input) {
    const file = input.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = function(e) {
        const content = e.target.result;
        try {
            // Basic validation to ensure it's at least JSON
            JSON.parse(content);
            document.getElementById('mb-macro-json').value = content;
            mbSaveConfig();
            mbLog(`File imported: ${file.name}`);
        } catch(err) {
            window.sui.toast("Invalid File: Not a valid JSON/Macro");
            mbLog("Error: Imported file is not valid JSON.");
        }
    };
    reader.readAsText(file);
    // Reset input so the same file can be picked again if modified
    input.value = '';
};
JS;