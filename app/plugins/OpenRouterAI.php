<?php
// ==============================================================================
// PLUGIN: OpenRouter AI
// DESCRIPTION: AI Text Transformation.
// UPDATED: Added "Double Ripple" animation and auto-scroll upon completion.
// ==============================================================================

// 1. DATABASE UPDATE (Non-Destructive)
try {
    $cols = $db->query("PRAGMA table_info(logs)")->fetchAll(PDO::FETCH_ASSOC);
    $hasCol = false;
    foreach ($cols as $c) { if ($c['name'] === 'original_text') $hasCol = true; }
    if (!$hasCol) {
        $db->exec("ALTER TABLE logs ADD COLUMN original_text TEXT DEFAULT NULL");
    }
} catch (Exception $e) {}

// --- HELPER: GET CONFIG ---
function get_or_settings() {
    $data_dir = CJOS_PATH_DATA;
    $settings = [
        'api_key' => '',
        'model' => 'openai/gpt-3.5-turbo',
        'presets' => [] // Empty by default, JS handles defaults if missing
    ];

    // Public
    $confFile = $data_dir . '/openrouter-config.json';
    if (file_exists($confFile)) {
        $c = json_decode(file_get_contents($confFile), true);
        if ($c) {
            if (isset($c['model'])) $settings['model'] = $c['model'];
            if (isset($c['presets'])) $settings['presets'] = $c['presets'];
        }
    }

    // Private
    $privFile = $data_dir . '/openrouter-private.json';
    if (file_exists($privFile)) {
        $p = json_decode(file_get_contents($privFile), true);
        if ($p && isset($p['api_key'])) $settings['api_key'] = $p['api_key'];
    }
    
    return $settings;
}

// --- API ACTIONS ---

// GET CONFIG (Frontend Load)
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'or_get_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'config' => get_or_settings()]);
    exit;
}

// SAVE CONFIG (Frontend Save)
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'or_save_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $data_dir = CJOS_PATH_DATA;
    if (!is_dir($data_dir)) mkdir($data_dir, 0777, true);

    $apiKey = $_POST['api_key'] ?? '';
    $model = $_POST['model'] ?? 'openai/gpt-3.5-turbo';
    $presets = json_decode($_POST['presets'] ?? '[]', true);

    // Save Private
    file_put_contents($data_dir . '/openrouter-private.json', json_encode(['api_key' => $apiKey], JSON_PRETTY_PRINT));

    // Save Public
    $publicData = ['model' => $model, 'presets' => $presets];
    file_put_contents($data_dir . '/openrouter-config.json', json_encode($publicData, JSON_PRETTY_PRINT));

    echo json_encode(['status' => 'success']);
    exit;
}

// GET USAGE / BILLING
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'or_get_usage') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $settings = get_or_settings();
    $apiKey = $settings['api_key'];

    if (empty($apiKey)) {
        echo json_encode(['status' => 'error', 'message' => 'No API Key']); exit;
    }

    $ch = curl_init('https://openrouter.ai/api/v1/credits');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json"
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) {
        echo json_encode(['status' => 'error', 'message' => 'API Error ' . $http]); exit;
    }

    $data = json_decode($res, true);
    if (isset($data['data'])) {
        // Calculate remaining balance like Knowledge Builder
        $remaining = $data['data']['total_credits'] - $data['data']['total_usage'];
        echo json_encode([
            'status' => 'success', 
            'data' => [
                'usage' => $remaining,
                'label' => 'Remaining Credits'
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid API Response']);
    }
    exit;
}

// TRANSFORM TEXT (The Main Function)
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'openrouter_transform') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    // 1. Load Server Settings
    $serverSettings = get_or_settings();
    
    // 2. Priority: Server File > POST Data
    $apiKey = !empty($serverSettings['api_key']) ? $serverSettings['api_key'] : ($_POST['api_key'] ?? '');
    $model  = !empty($serverSettings['model'])   ? $serverSettings['model']   : ($_POST['model'] ?? 'openai/gpt-3.5-turbo');
    
    $temp = floatval($_POST['temperature'] ?? 0.7);
    $sysPrompt = $_POST['system_prompt'] ?? 'You are a helpful assistant.';
    $ids = json_decode($_POST['ids'], true);

    if (empty($apiKey)) {
        echo json_encode(['status' => 'error', 'message' => 'CRITICAL: No API Key found on server or client. Please re-save OpenRouter settings.']); exit;
    }
    if (empty($ids)) {
        echo json_encode(['status' => 'error', 'message' => 'No notes selected for transformation.']); exit;
    }

    $updated = [];
    $errors = [];
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $referer = "http://$host";

    foreach ($ids as $id) {
        $stmt = $db->prepare("SELECT transcription, original_text FROM logs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $currentText = $row['transcription'];
            // Cache original if not already cached
            if (empty($row['original_text'])) {
                $db->prepare("UPDATE logs SET original_text = :orig WHERE id = :id")
                   ->execute([':orig' => $currentText, ':id' => $id]);
            }

            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $sysPrompt],
                    ['role' => 'user', 'content' => $currentText]
                ],
                'temperature' => $temp
            ];

            $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $apiKey",
                "HTTP-Referer: $referer", 
                "X-Title: Conjure",
                "Content-Type: application/json"
            ]);
            
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) { $errors[] = "CURL: $curlError"; continue; }

            $json = json_decode($response, true);
            if (isset($json['error'])) {
                $errors[] = "API: " . ($json['error']['message'] ?? 'Unknown');
                break;
            }
            
            if (isset($json['choices'][0]['message']['content'])) {
                $newText = $json['choices'][0]['message']['content'];
                $db->prepare("UPDATE logs SET transcription = :text WHERE id = :id")
                   ->execute([':text' => $newText, ':id' => $id]);
                $updated[] = ['id' => $id, 'text' => $newText];
            }
        }
    }

    if (!empty($errors) && empty($updated)) {
        echo json_encode(['status' => 'error', 'message' => implode('; ', $errors)]);
    } else {
        echo json_encode(['status' => 'success', 'updated' => $updated, 'errors' => $errors]);
    }
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'openrouter_revert') {
    $id = $_POST['id'];
    $stmt = $db->prepare("SELECT original_text FROM logs WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['original_text'])) {
        $db->prepare("UPDATE logs SET transcription = :orig, original_text = NULL WHERE id = :id")
           ->execute([':orig' => $row['original_text'], ':id' => $id]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// 3. SETTINGS UI
$plugin_settings_map['OpenRouterAI'] = <<<'HTML'
    <div class="setting-item" style="background:var(--btn-bg); border-bottom:1px solid var(--border-color);">
        <div class="setting-text-wrap">
            <label class="setting-label" style="font-size:14px;">Server Sync</label>
            <span id="or-sync-status" class="setting-desc" style="font-size:11px;">openrouter-config.json</span>
        </div>
        <div style="display:flex; gap:8px;">
            <button onclick="loadOrSettings()" class="icon-btn" style="background:white; width:32px; height:32px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1);" title="Load">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            </button>
            <button onclick="saveOrSettings()" class="icon-btn" style="background:var(--primary); color:white; width:32px; height:32px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1);" title="Save">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
            </button>
        </div>
    </div>

    <div class="setting-item vertical">
        <label class="setting-label">OpenRouter API Key</label>
        <input type="text" id="or-auth-v1" class="input-secret-key" autocomplete="off" data-bwignore="true" data-1p-ignore="true" data-lpignore="true" spellcheck="false" placeholder="sk-or-...">
    </div>
    <div class="setting-item vertical">
        <label class="setting-label">AI Model</label>
        <div class="setting-desc" style="margin-bottom:8px;">Selected: <span id="or-current-model-display" style="font-weight:600; color:var(--primary);">Default</span></div>
        <button onclick="openModelPicker()" class="text-btn" style="
            width:100%; text-align:center; background:var(--input-bg); color:var(--input-text); border: 1px solid var(--border-color);
            padding:12px; border-radius:10px; font-weight:600; border:1px solid var(--border-color);
        ">Select Model from OpenRouter</button>
    </div>
    <div class="setting-item">
        <span class="setting-desc">Presets are now synced via Server Config. Manage them in the selection menu.</span>
    </div>
HTML;

// 4. MANAGER OVERLAYS
// OpenRouter managers now use the SharedUI Studio Factory

// 5. JAVASCRIPT LOGIC
$plugin_js .= <<<'JS'
// --- OPENROUTER AI JS ---

const defaultPresets = [
    { name: "✨ Format Lists", prompt: "Format the text into a clean list. Use bullets or numbers where appropriate.", temp: 0.5 },
    { name: "👔 Make Formal", prompt: "Rewrite to be professional, concise, and formal.", temp: 0.7 },
    { name: "🦄 Casual Tone", prompt: "Rewrite to be casual, friendly, and easy to read.", temp: 0.9 },
    { name: "📝 Summarize", prompt: "Summarize into a single paragraph.", temp: 0.5 }
];

let isReordering = false;
let isEditingPreset = false;
let draggedIdx = null;

function getAiPresets() {
    const saved = localStorage.getItem("cjos_or_presets");
    return saved ? JSON.parse(saved) : defaultPresets;
}

window.addEventListener("load", () => {
    // 1. INJECT STYLES: Skeleton + Ripple
    const skelStyleId = "or-skel-style";
    if (!document.getElementById(skelStyleId)) {
        const s = document.createElement("style");
        s.id = skelStyleId;
        s.innerHTML = `
            .card.is-processing { cursor: wait; pointer-events: none; opacity: 0.8; }
            
            /* DOUBLE RIPPLE ANIMATION */
            @keyframes ai-ripple-pulse {
                0% { box-shadow: 0 0 0 0 rgba(0, 122, 255, 0.5); }
                30% { box-shadow: 0 0 0 15px rgba(0, 122, 255, 0); } /* First Ripple End */
                31% { box-shadow: 0 0 0 0 rgba(0, 122, 255, 0); } /* Pause */
                50% { box-shadow: 0 0 0 0 rgba(0, 122, 255, 0.5); } /* Second Ripple Start */
                80% { box-shadow: 0 0 0 15px rgba(0, 122, 255, 0); } /* Second Ripple End */
                100% { box-shadow: none; }
            }
            .card.ai-just-finished {
                animation: ai-ripple-pulse 1.5s ease-out forwards;
                z-index: 100;
            }

            /* REVERT ANIMATIONS (The Magic is Gone) */
            @keyframes magic-poof {
                0% { opacity: 1; transform: scale(1); filter: blur(0); }
                100% { opacity: 0; transform: scale(0.92); filter: blur(12px); }
            }
            @keyframes magic-return {
                0% { opacity: 0; transform: scale(1.05); }
                100% { opacity: 1; transform: scale(1); }
            }
            .ai-reverting { animation: magic-poof 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards; }
            .ai-restored { animation: magic-return 0.4s ease-out forwards; }
        `;
        document.head.appendChild(s);
    }

    // 2. CHECK FOR RECENT TRANSFORMATION (Animation Trigger)
    const justIds = JSON.parse(localStorage.getItem("cjos_ai_just_transformed") || "[]");
    if (justIds.length > 0) {
        localStorage.removeItem("cjos_ai_just_transformed");
        setTimeout(() => {
            let scrolled = false;
            justIds.forEach(id => {
                const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
                if (cb) {
                    const card = cb.closest(".card");
                    if (card) {
                        card.classList.add("ai-just-finished");
                        setTimeout(() => card.classList.remove("ai-just-finished"), 1600);
                        
                        if (!scrolled) {
                            card.scrollIntoView({ behavior: "smooth", block: "center" });
                            scrolled = true;
                        }
                    }
                }
            });
        }, 500); // Small delay to ensure DOM is ready
    }

    // Inject Button
    const wrapper = document.querySelector(".selection-done-wrapper");
    const refNode = document.getElementById("btn-toggle-draft") || document.getElementById("cancel-btn");

    if (wrapper && refNode) {
        const aiBtn = document.createElement("button");
        aiBtn.className = "icon-btn";
        aiBtn.innerHTML = "<svg viewBox='0 0 24 24' fill='white' stroke='none'><path d='M12 1L14.5 6.5L20 9L14.5 11.5L12 17L9.5 11.5L4 9L9.5 6.5L12 1Z M6 18L7 20.5L9.5 21.5L7 22.5L6 25L5 22.5L2.5 21.5L5 20.5L6 18Z M20 18L21 20.5L23.5 21.5L21 22.5L20 25L19 22.5L16.5 21.5L19 20.5L20 18Z'></path></svg>";
        aiBtn.style.cssText = "background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); width: 36px; height: 36px; border-radius: 50%; margin-right: 12px; display: flex; align-items: center; justify-content: center; padding: 8px; border: 1px solid rgba(255,255,255,0.3); box-shadow: 0 4px 15px rgba(118, 75, 162, 0.4);";
        aiBtn.onclick = (e) => { e.stopPropagation(); openAiMenu(); };
        wrapper.insertBefore(aiBtn, refNode);
    }
    
    // Auto Load on Open
    setTimeout(loadOrSettings, 1000);
    
    // Bind API Key Change
    const k = document.getElementById("or-api-key");
    if(k) k.addEventListener("change", saveOrSettings);

    // Phase 8: Badge Engine Registration
if (window.sui && window.sui.registerBadge) {
    window.sui.registerBadge("ai-badge", (entry, card) => {
        if (card.hasAttribute("data-ai-prohibited")) return null;
        if (entry && entry.original_text) {
            const badge = window.suiBadge("✨ AI Transformation", "ai-alt");
            badge.style.cursor = "pointer";
            badge.setAttribute('data-sui-id', 'or-ai-trigger');
            
            badge.onclick = (e) => { 
                e.stopPropagation(); 
                
                const actions = [];
                
                // 1. Toggle View
                const viewLabel = entry._show_raw ? "Show AI Version" : "Show Original Transcript";
                const viewBtn = window.suiBadge(viewLabel, "default");
                viewBtn.onclick = (ev) => { 
                    ev.stopPropagation(); 
                    const content = card.querySelector(".card-content");
                    toggleAiView(entry.id, content, badge); 
                };
                actions.push(viewBtn);

                // 2. Revert
                const revertBtn = window.suiBadge("↺ Revert Permanently", "danger");
                revertBtn.onclick = (ev) => { 
                    ev.stopPropagation(); 
                    window.openConfirm("Permanently Revert", "This will restore the raw transcript and delete the AI version forever. Proceed?", () => revertAiText(entry.id), true); 
                };
                actions.push(revertBtn);

                window.sui.toggleActions(card, actions, badge);
            };
            return badge;
        }
        return null;
    }, 45); // Priority 45: Content Badges
    }
});

// --- SERVER SYNC FUNCTIONS ---

window.loadOrSettings = async function() {
    const status = document.getElementById("or-sync-status");
    if(status) status.innerText = "Checking server...";
    
    try {
        const data = await window.sui.api("or_get_config", {}, { toast: false });
        
        if (data) {
            const c = data.config;
            
            // 1. Update UI & LocalStorage: API Key
            const apiKey = c.api_key || "";
            const input = document.getElementById("or-auth-v1");
            if(input) input.value = apiKey;
            localStorage.setItem("cjos_or_api_key", apiKey);
            
            // 2. Update UI & LocalStorage: Model
            const model = c.model || "openai/gpt-3.5-turbo";
            const disp = document.getElementById("or-current-model-display");
            if(disp) disp.innerText = model;
            localStorage.setItem("cjos_or_model", model);
            
            // 3. Update Presets
            if (c.presets && Array.isArray(c.presets) && c.presets.length > 0) {
                localStorage.setItem("cjos_or_presets", JSON.stringify(c.presets));
            }
            
            if(status) {
                status.innerText = "Synced with Server";
                status.style.color = "#34C759";
            }
        }
    } catch(e) {
        if(status) {
            status.innerText = "Load Failed";
            status.style.color = "#FF3B30";
        }
        // Fallback: Ensure UI shows local storage
        const lsKey = localStorage.getItem("cjos_or_api_key");
        const lsModel = localStorage.getItem("cjos_or_model");
        if(lsKey && document.getElementById("or-api-key")) document.getElementById("or-api-key").value = lsKey;
        if(lsModel && document.getElementById("or-current-model-display")) document.getElementById("or-current-model-display").innerText = lsModel;
    }
};

window.saveOrSettings = async function() {
    const status = document.getElementById("or-sync-status");
    if(status) status.innerText = "Saving...";
    
    // Gather Data
    const apiKey = document.getElementById("or-auth-v1").value.trim();
    const model = localStorage.getItem("cjos_or_model") || "openai/gpt-3.5-turbo";
    const presets = localStorage.getItem("cjos_or_presets") || "[]"; // Send as string, PHP decodes
    
    // Optimistic Local Update
    localStorage.setItem("cjos_or_api_key", apiKey);
    
    try {
        const data = await window.sui.api("or_save_config", { 
            api_key: apiKey, 
            model: model, 
            presets: presets 
        }, { toast: false });
        
        if(data) {
            if(status) {
                status.innerText = "Saved";
                status.style.color = "#34C759";
                setTimeout(() => { status.innerText = "Synced"; status.style.color = "#8E8E93"; }, 2000);
            }
        } else {
            throw new Error("Save Error");
        }
    } catch(e) {
        if(status) {
            status.innerText = "Save Failed";
            status.style.color = "#FF3B30";
        }
    }
};

// --- MENU & PRESET LOGIC ---

function openAiMenu() {
    window._aiMenuContext = "menu"; // Set Context
    const presets = getAiPresets();
    const options = presets.map((p, idx) => ({ label: p.name, value: idx }));
    options.push({ label: "⚙️ Manage Presets...", value: "manage" });
    
    window.openPicker("AI Transform", options, null, (val) => {
        if (val === "manage") openPresetManager();
        else runAiTransform(presets[val]);
    });
}

let aiContent, aiTitle; // Re-assigned by factory on open

window.closeAiManager = function() {
    if(isEditingPreset) {
        openPresetManager(); 
    } else {
        window.sui.closeStudio('or-manager', null, null, () => {
            if(window._aiMenuContext === "menu") openAiMenu();
        });
    }
};

window.openPresetManager = function() {
    window.sui.openStudio({
        id: 'or-manager',
        title: 'Manage Presets',
        onSetup: (container, overlay) => {
            aiTitle = overlay.querySelector('.sui-studio-title');
            aiContent = container;
            isEditingPreset = false;
            isReordering = false; 
            renderManagerList();
        }
    });
};

window.toggleReorder = function() {
    isReordering = !isReordering;
    renderManagerList();
};

function renderManagerList() {
    const presets = getAiPresets();
    let html = `<div style='display:flex; gap:10px; margin-bottom:12px;'><button onclick='editPreset(-1)' style='flex:1; background:var(--primary); color:white; border:none; padding:14px; border-radius:12px; font-weight:600; font-size:15px; box-shadow:0 4px 10px rgba(0,0,0,0.1);'>+ New</button>`;
    
    const btnBg = isReordering ? "#34C759" : "#E5E5EA";
    const btnCol = isReordering ? "white" : "var(--text-primary)";
    html += `<button onclick='toggleReorder()' style='background:${btnBg}; color:${btnCol}; border:none; padding:14px; border-radius:12px; font-weight:600;'>⇄ Reorder</button></div>`;
    
    html += `<div style='display:flex; flex-direction:column; gap:10px;'>`;
    presets.forEach((p, idx) => {
        if(isReordering) {
            html += `<div style='background:white; border-radius:14px; padding:16px; display:flex; justify-content:space-between; align-items:center; border:2px dashed #E5E5EA;'>`;
            html += `<div><div style='font-weight:600; font-size:16px;'>☰ ${p.name}</div></div>`;
            html += `<div style='display:flex; gap:8px;'>`;
            let upVis = (idx === 0) ? "visibility:hidden;" : "";
            html += `<button onclick='movePreset(${idx}, -1)' style='padding:8px 12px; border-radius:8px; background:#F2F2F7; border:none; ${upVis}'>↑</button>`;
            let downVis = (idx === presets.length - 1) ? "visibility:hidden;" : "";
            html += `<button onclick='movePreset(${idx}, 1)' style='padding:8px 12px; border-radius:8px; background:#F2F2F7; border:none; ${downVis}'>↓</button>`;
            html += `</div></div>`;
        } else {
            html += `<div style='background:white; border-radius:14px; padding:16px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 2px 5px rgba(0,0,0,0.02);'>`;
            html += `<div><div style='font-weight:600; font-size:16px; color:var(--text-primary);'>${p.name}</div><div style='font-size:13px; color:var(--text-secondary); margin-top:2px;'>Temp: ${p.temp}</div></div>`;
            html += `<div style='display:flex; gap:8px;'><button onclick='editPreset(${idx})' style='background:#E5E5EA; border:none; padding:8px 12px; border-radius:8px; font-size:13px; font-weight:600; color:var(--text-primary);'>Edit</button><button onclick='deletePresetIdx(${idx})' style='background:#FFE5E5; border:none; padding:8px 12px; border-radius:8px; font-size:13px; font-weight:600; color:red;'>Del</button></div></div>`;
        }
    });
    html += "</div>";
    aiContent.innerHTML = html;
}

window.movePreset = function(idx, dir) {
    const presets = getAiPresets();
    const target = idx + dir;
    if(target < 0 || target >= presets.length) return;
    const item = presets.splice(idx, 1)[0];
    presets.splice(target, 0, item);
    localStorage.setItem("cjos_or_presets", JSON.stringify(presets));
    saveOrSettings(); // Trigger Server Sync
    renderManagerList();
};

window.editPreset = function(idx) {
    isEditingPreset = true;
    const presets = getAiPresets();
    const p = idx === -1 ? { name: "", prompt: "", temp: 0.7 } : presets[idx];
    aiTitle.innerText = idx === -1 ? "New Preset" : "Edit Preset";
    let html = `<div style='display:flex; flex-direction:column; gap:16px;'>`;
    html += `<div><label style='display:block; font-size:13px; font-weight:600; color:var(--text-secondary); margin-bottom:6px;'>NAME</label><input type='text' id='ep-name' value='${p.name}' style='width:100%; padding:12px; border-radius:10px; border:1px solid #E5E5EA; background:white; font-size:16px; box-sizing:border-box;'></div>`;
    html += `<div><label style='display:block; font-size:13px; font-weight:600; color:var(--text-secondary); margin-bottom:6px;'>PROMPT</label><textarea id='ep-prompt' style='width:100%; height:120px; padding:12px; border-radius:10px; border:1px solid #E5E5EA; background:white; font-size:15px; box-sizing:border-box; font-family:sans-serif; resize:none;'>${p.prompt}</textarea></div>`;
    html += `<div><label style='display:block; font-size:13px; font-weight:600; color:var(--text-secondary); margin-bottom:6px;'>TEMP: <span id='temp-v'>${p.temp}</span></label><input type='range' id='ep-temp' min='0' max='2' step='0.1' value='${p.temp}' oninput='document.getElementById("temp-v").innerText = this.value'></div>`;
    html += `<div style='display:flex; gap:12px; margin-top:10px;'><button onclick='openPresetManager()' style='flex:1; padding:14px; border:none; background:#E5E5EA; color:var(--text-primary); border-radius:12px; font-weight:600;'>Cancel</button><button onclick='savePreset(${idx})' style='flex:1; padding:14px; border:none; background:var(--primary); color:white; border-radius:12px; font-weight:600;'>Save</button></div></div>`;
    aiContent.innerHTML = html;
};

window.savePreset = function(idx) {
    const name = document.getElementById("ep-name").value;
    const prompt = document.getElementById("ep-prompt").value;
    const temp = parseFloat(document.getElementById("ep-temp").value);
    if(!name || !prompt) { window.openConfirm("Preset Manager", "Missing fields", null, false, "OK", null); return; }
    const presets = getAiPresets();
    const obj = { name, prompt, temp };
    if(idx === -1) presets.push(obj); else presets[idx] = obj;
    localStorage.setItem("cjos_or_presets", JSON.stringify(presets));
    saveOrSettings(); // Trigger Server Sync
    openPresetManager();
};

window.deletePresetIdx = function(idx) {
    window.openConfirm("Delete Preset", "Delete this preset?", () => {
        const presets = getAiPresets();
        presets.splice(idx, 1);
        localStorage.setItem("cjos_or_presets", JSON.stringify(presets));
        saveOrSettings(); // Trigger Server Sync
        renderManagerList();
    }, true);
};

window.openModelPicker = async function() {
    window._aiMenuContext = "settings"; 
    
    window.sui.openStudio({
        id: 'or-manager',
        title: 'Select AI Model',
        content: `<div style='display:flex; flex-direction:column; height:100%;'><input type='text' id='model-search-input' placeholder='Search models (or type "free")' onkeyup='filterModels(this.value)' style='flex-shrink:0; width:100%; padding:12px; border-radius:12px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text); font-size:16px; margin-bottom:12px; box-sizing:border-box;'><div id='model-list-container' style='flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:8px; padding-bottom:20px;'><div style='text-align:center; padding:40px; color:#8E8E93;'>Fetching models...</div></div></div>`,
        onSetup: (container, overlay) => {
            aiTitle = overlay.querySelector('.sui-studio-title');
            aiContent = container;
        }
    });

    try {
        const res = await fetch("https://openrouter.ai/api/v1/models");
        const json = await res.json();
        const models = json.data.sort((a,b) => a.id.localeCompare(b.id));
        renderModelList(models, "");
    } catch(e) {
        const c = document.getElementById("model-list-container");
        if(c) c.innerHTML = `<div style='text-align:center; color:red;'>Failed to load models.</div>`;
    }
};

function renderModelList(allModels, filter) {
    const container = document.getElementById("model-list-container");
    if(!container) return;
    const favs = JSON.parse(localStorage.getItem("cjos_or_favs") || "[]");
    const term = filter.toLowerCase();
    const list = allModels.filter(m => {
        if(term === "free") return (m.pricing && m.pricing.prompt === "0" && m.pricing.completion === "0");
        return m.id.toLowerCase().includes(term) || m.name.toLowerCase().includes(term);
    }).sort((a, b) => {
        const aFav = favs.includes(a.id);
        const bFav = favs.includes(b.id);
        if (aFav && !bFav) return -1;
        if (!aFav && bFav) return 1;
        return 0;
    });
    let html = "";
    list.forEach(m => {
        const isFav = favs.includes(m.id);
        const isFree = (m.pricing && m.pricing.prompt === "0" && m.pricing.completion === "0");
        const fill = isFav ? "#FFCC00" : "none";
        const stroke = isFav ? "#FFCC00" : "#C7C7CC";
        const border = isFav ? "var(--primary)" : "transparent";
        const freeHtml = isFree ? "<span style='color:green; font-weight:bold'>FREE</span> • " : "";
        html += `<div style='background:var(--card-bg); border-radius:12px; padding:14px; border:1px solid ${border === "transparent" ? "var(--border-color)" : border}; box-shadow:0 2px 5px rgba(0,0,0,0.02); display:flex; align-items:center; gap:12px;'>`;
        html += `<button onclick='toggleFav("${m.id}")' style='background:none; border:none; padding:4px; cursor:pointer;'><svg viewBox='0 0 24 24' fill='${fill}' stroke='${stroke}' stroke-width='2' style='width:24px; height:24px;'><path d='M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z'></path></svg></button>`;
        html += `<div onclick='selectModel("${m.id}")' style='flex:1; cursor:pointer;'><div style='font-weight:600; color:var(--text-primary); font-size:15px;'>${m.name}</div><div style='font-size:12px; color:var(--text-secondary);'>${freeHtml}${m.id}</div></div></div>`;
    });
    container.innerHTML = html;
    window._cachedModels = allModels;
}

window.filterModels = function(val) {
    if(window._cachedModels) renderModelList(window._cachedModels, val);
};

window.toggleFav = function(id) {
    let favs = JSON.parse(localStorage.getItem("cjos_or_favs") || "[]");
    if(favs.includes(id)) favs = favs.filter(x => x !== id); else favs.push(id);
    localStorage.setItem("cjos_or_favs", JSON.stringify(favs));
    const input = document.getElementById("model-search-input");
    renderModelList(window._cachedModels, input ? input.value : "");
};

window.selectModel = function(id) {
    localStorage.setItem("cjos_or_model", id);
    const disp = document.getElementById("or-current-model-display");
    if(disp) disp.innerText = id;
    saveOrSettings(); // Trigger Server Sync
    closeAiManager();
};

async function runAiTransform(preset) {
    const apiKey = localStorage.getItem("cjos_or_api_key");
    const selectedItems = getSelectedItems();
    const ids = selectedItems.map(i => i.id);

    if (ids.length === 0) return;
    
    if (!apiKey || apiKey.trim() === "") {
        window.openConfirm("AI Error", "Missing API Key. Please go to Settings > AI > OpenRouter and enter your key, then click the Save (floppy disk) icon.", null, false, "OK", null);
        return;
    }
    
    // Visually exit selection mode
    if (typeof cjosToggleSelectMode === "function") cjosToggleSelectMode(false);

    // Apply Skeleton State
    const cardMap = new Map(); 
    let firstCardToScroll = null;

    ids.forEach(id => {
        const checkbox = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
        if(checkbox) {
            const card = checkbox.closest(".card");
            const textDiv = card.querySelector(".transcription");
            if(card && textDiv) {
                const orig = textDiv.innerHTML;
                cardMap.set(id, {card, textDiv, orig});
                
                textDiv.innerHTML = `<span class="skel-line"></span><span class="skel-line"></span><span class="skel-line short"></span>`;
                card.classList.add("is-processing");
                
                if (!firstCardToScroll) firstCardToScroll = card;
            }
        }
    });
    
    // Auto-Scroll Logic: Move the first processed card to center WHILE processing
    if (firstCardToScroll) {
        setTimeout(() => {
            firstCardToScroll.scrollIntoView({ behavior: "smooth", block: "center" });
        }, 150);
    }
    
    const apiData = {
        ids: ids,
        system_prompt: preset.prompt,
        temperature: preset.temp,
        model: localStorage.getItem("cjos_or_model") || "openai/gpt-3.5-turbo"
    };
    if (apiKey) apiData.api_key = apiKey;
    
    try {
        const data = await window.sui.api("openrouter_transform", apiData, { toast: false });
        if (data) {
            // 1. Update Data & UI Live
            data.updated.forEach(item => {
                // Update Local Data
                const log = logs.find(l => l.id === item.id);
                if (log) {
                    // Cache original if this is the first edit
                    if (!log.original_text) log.original_text = log.transcription;
                    log.transcription = item.text;
                }

                // Update DOM
const obj = cardMap.get(item.id);
if (obj) {
    const { card, textDiv } = obj;
    card.classList.remove("is-processing");
                        
    // Update text (using textContent to prevent HTML injection, preserving formatting via CSS)
    textDiv.textContent = item.text;
    if (window.cjosBroadcastUpdate) window.cjosBroadcastUpdate(item.id, log);// Trigger Animation
                    card.classList.add("ai-just-finished");
                    setTimeout(() => card.classList.remove("ai-just-finished"), 2000);
                }
            });

            // 2. Refresh Badges & Overflow Buttons
            if (typeof window.renderAiBadges === "function") window.renderAiBadges();
            if (typeof window.refreshReadMoreButtons === "function") window.refreshReadMoreButtons();

            // 3. Feedback
            if (window.sui && window.sui.toast) {
    window.sui.toast("Transformation Complete", { 
        plugin: "OpenRouterAI", 
        caller: "runAiTransform", 
        metrics: { model: apiData.model, prompt_len: apiData.prompt.length, result_len: data.text.length } 
    });
}} else {
            window.openConfirm("Transformation Error", data.message, null, false, "OK", null);
            // Revert on error
            cardMap.forEach(obj => {
                obj.textDiv.innerHTML = obj.orig;
                obj.card.classList.remove("is-processing");
            });
        }
    } catch (e) {
        window.openConfirm("Network Error", "Error: " + e, null, false, "OK", null);
        // Revert on error(e) {
        window.openConfirm("Network Error", "Error: " + e, null, false, "OK", null);
        // Revert on error
        cardMap.forEach(obj => {
            obj.textDiv.innerHTML = obj.orig;
            obj.card.classList.remove("is-processing");
        });
    }
}

function renderAiBadges() {
    if (window.sui && window.sui.decorateCard) {
        document.querySelectorAll(".card").forEach(card => {
             const id = card.querySelector(".custom-checkbox")?.getAttribute("data-id");
             const entry = logs.find(l => l.id === id);
             if (entry) window.sui.decorateCard(card, entry);
        });
    }
}

function toggleAiView(id, cardContent, badge) {
    const log = logs.find(l => l.id === id);
    const textDiv = cardContent.querySelector(".transcription");
    const isShowingOriginal = log._show_raw === true;

    if (!isShowingOriginal) {
        if(!log._ai_text_cache) log._ai_text_cache = log.transcription;
        log.transcription = log.original_text; 
        textDiv.textContent = log.original_text;
        log._show_raw = true;
    } else {
        if(log._ai_text_cache) log.transcription = log._ai_text_cache;
        textDiv.textContent = log.transcription;
        log._show_raw = false;
    }
    
    // Update the action row labels immediately by re-triggering the click logic
    if (badge && badge.classList.contains('sui-badge-active')) {
        badge.click(); // Close
        badge.click(); // Re-open with new labels
    }
}

async function revertAiText(id) {
    try {
        const data = await window.sui.api("openrouter_revert", { id: id }, { toast: false });
        
        if (data.status === "success") {
            // 1. Update Local Data
            const log = logs.find(l => l.id === id);
            if (log && log.original_text) {
                log.transcription = log.original_text;
                log.original_text = null;
            }

            // 2. Update DOM with Animation
            const checkbox = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
            if (checkbox) {
                const card = checkbox.closest(".card");
                const textDiv = card.querySelector(".transcription");
                
                if (textDiv) {
                    // Phase 1: The Poof
                    textDiv.classList.add("ai-reverting");
                    
                    setTimeout(() => {
                        // Phase 2: The Swap
                        textDiv.textContent = log ? log.transcription : "";
                        if (window.cjosBroadcastUpdate) window.cjosBroadcastUpdate(id, log);
                        textDiv.classList.remove("ai-reverting");
                        textDiv.classList.add("ai-restored");
                        
                        // Phase 3: Cleanup
                        setTimeout(() => textDiv.classList.remove("ai-restored"), 400);
                    }, 400);
                }
                
                // Close Action Row
                const container = window.getActionContainer(card.querySelector('.card-content'));
                if (container) container.classList.remove('open');

                if (window.sui && window.sui.decorateCard) window.sui.decorateCard(card, log);
                
                if (typeof window.refreshReadMoreButtons === "function") window.refreshReadMoreButtons();
                
                // Feedback
                if (window.sui && window.sui.toast) {
    window.sui.toast("Reverted to Original", { plugin: "OpenRouterAI", caller: "revertAiText", metrics: { id: id } });
}}
        }
    } catch(e) { console.error(e); }
}
JS;
?>