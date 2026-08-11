<?php
// ==============================================================================
// PLUGIN: Conjure Core
// DESCRIPTION: Transcription Engine. Orchestrates audio-to-text conversion via OpenAI or OpenRouter.
// Handles transcription API calls and orphaned file synchronization.
// ==============================================================================

// --- AUTO-SYNC ORPHANED FILES ---
if (isset($db) && isset($rec_dir)) {
    $actual_trans_dir = CJOS_PATH_STORAGE . '/text';
    $files = glob("$rec_dir/*.{mp4,webm,m4a,mp3,wav,aac,ogg}", GLOB_BRACE);
    foreach ($files as $file) {
        $id = pathinfo(basename($file), PATHINFO_FILENAME);
        $txt_path = "$actual_trans_dir/$id.txt";
        $stmt = $db->prepare("SELECT COUNT(*) FROM logs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if ($stmt->fetchColumn() == 0) {
            $transcription = file_exists($txt_path) ? file_get_contents($txt_path) : "(Pending Transcription...)";
            $file_time = filemtime($file);
            $date = date('Y-m-d H:i:s', $file_time);
            $relAudioPath = str_replace(CJOS_PATH_ROOT . '/', '', $file);
            $db->prepare("INSERT INTO logs (id, date_display, audio_path, transcription, timestamp) VALUES (?,?,?,?,?)")
               ->execute([$id, $date, $relAudioPath, $transcription, $file_time]);
        }
    }
}

// --- BACKEND HANDLER ---
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'cc_transcribe') {
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    while (ob_get_level()) @ob_end_clean();
    header('Content-Type: application/json');

    $settings = cc_get_backend_settings();
    $provider = $settings['provider'] ?? 'openai';
    
    if ($provider === 'openrouter') {
        $apiKey = !empty($_POST['or_api_key']) ? $_POST['or_api_key'] : ($settings['or_api_key'] ?? '');
        $model  = !empty($_POST['or_model'])   ? $_POST['or_model']   : ($settings['or_model'] ?? '');
        $prompt = !empty($_POST['or_prompt']) ? $_POST['or_prompt'] : ($settings['or_prompt'] ?? '');
        $endpoint = 'https://openrouter.ai/api/v1/audio/transcriptions';
    } else {
        $apiKey = !empty($_POST['api_key']) ? $_POST['api_key'] : ($settings['api_key'] ?? '');
        $model  = !empty($_POST['model'])   ? $_POST['model']   : ($settings['model'] ?? '');
        $prompt = !empty($_POST['prompt'])  ? $_POST['prompt']  : ($settings['prompt'] ?? '');
        $endpoint = 'https://api.openai.com/v1/audio/transcriptions';
    }
    $id     = $_POST['id'] ?? '';

    if (empty($apiKey) || empty($id)) { echo json_encode(['status' => 'error', 'message' => 'Missing Data']); exit; }

    $globalLock = CJOS_PATH_DATA . "/system-busy.lock";
    if (file_exists($globalLock) && (time() - filemtime($globalLock) < 120)) {
        header('HTTP/1.1 503 Service Unavailable');
        echo json_encode(['status' => 'error', 'message' => 'System Busy: AI Pipeline in progress.']);
        exit;
    }
    file_put_contents($globalLock, getmypid());
    register_shutdown_function(function() use ($globalLock) { if(file_exists($globalLock)) unlink($globalLock); });

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $row = $db->prepare("SELECT audio_path FROM logs WHERE id = :id");
    $row->execute([':id' => $id]);
    $entry = $row->fetch(PDO::FETCH_ASSOC);
    
    $filePath = CJOS_PATH_ROOT . '/' . $entry['audio_path'];
    if (!$entry || !file_exists($filePath)) { echo json_encode(['status' => 'error', 'message' => 'File missing']); exit; }

    $ch = curl_init();
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'HTTP-Referer: http://' . $host,
        'X-Title: Conjure Transcription Engine'
    ];

    if ($provider === 'openrouter') {
        $audioData = base64_encode(file_get_contents($filePath));
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        $payload = [
            'model' => $model,
            'input_audio' => [
                'data' => $audioData,
                'format' => $extension
            ]
        ];

        if (isset($settings['or_temp']) && $settings['or_temp'] !== '') $payload['temperature'] = (float)$settings['or_temp'];
        if (!empty($settings['or_lang'])) $payload['language'] = $settings['or_lang'];

        // Only send prompt if model is OpenAI-based
        if ((strpos($model, 'openai/') !== false || strpos($model, 'whisper') !== false) && !empty($prompt)) {
            $payload['prompt'] = $prompt;
        }
        
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } else {
        // OpenAI expects Multipart Form
        $fields = ['file' => new CURLFile($filePath), 'model' => $model];
        if (!empty($prompt)) $fields['prompt'] = $prompt;
        
        // IMPORTANT: Do NOT set Content-Type manually for multipart; cURL needs to set the boundary.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    }

    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) { 
        if (file_exists($globalLock)) unlink($globalLock);
        $errData = json_decode($response, true);
        $detail = $errData['error']['message'] ?? $errData['message'] ?? ($curlErr ? "cURL Error: $curlErr" : $response);
        
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error', 
            'message' => ucfirst($provider) . " Error ($httpCode): " . $detail,
            'debug' => "Provider: $provider | Model: $model | Endpoint: $endpoint" . ($curlErr ? " | cURL: $curlErr" : "")
        ]); 
        exit; 
    }

    $json = json_decode($response, true);
    $text = $json['text'] ?? '';
    
    $actual_trans_dir = CJOS_PATH_STORAGE . '/text';
    if (!is_dir($actual_trans_dir)) mkdir($actual_trans_dir, 0777, true);
    
    $disk_save_path = $actual_trans_dir . '/' . $id . '.txt';
    file_put_contents($disk_save_path, $text);
    
    $db->prepare("UPDATE logs SET transcription = :text WHERE id = :id")->execute([':text' => $text, ':id' => $id]);

    echo json_encode(['status' => 'success', 'text' => $text]); 

    if (file_exists($globalLock)) unlink($globalLock);
    
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }

    $stmt = $db->prepare("SELECT ai_processed FROM logs WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if ($stmt->fetchColumn() == 1) {
        $basePath = str_replace('app/api/backend.php', 'index.php', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $isHttps ? "https" : "http";
        $url = $scheme . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $basePath;
            
        $triggerCh = curl_init($url);
        curl_setopt($triggerCh, CURLOPT_TIMEOUT, 1);
        curl_setopt($triggerCh, CURLOPT_NOSIGNAL, 1);
        curl_setopt($triggerCh, CURLOPT_POST, 1);
        curl_setopt($triggerCh, CURLOPT_POSTFIELDS, ['plugin_action' => 'ai_trigger_pipeline', 'log_id' => $id]);
        curl_setopt($triggerCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($triggerCh, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($triggerCh);
        curl_close($triggerCh);
    }
    exit;
}

// --- CONFIGURATION HANDLERS ---

function cc_get_backend_settings() {
    $data_dir = CJOS_PATH_DATA;
    $settings = [
        'provider' => 'openai',
        'api_key' => '', 
        'model' => 'whisper-1', 
        'prompt' => '', 
        'or_api_key' => '',
        'or_model' => 'openai/whisper-1',
        'or_prompt' => '',
        'or_temp' => 0.0,
        'or_lang' => '',
        'or_models_cached' => [],
        'sound_start' => '', 
        'sound_stop' => ''
    ];
    
    $confFile = $data_dir . '/backend-config.json';
    if (file_exists($confFile)) {
        $c = json_decode(file_get_contents($confFile), true);
        if ($c) $settings = array_merge($settings, $c);
    }
    $privFile = $data_dir . '/backend-private.json';
    if (file_exists($privFile)) {
        $p = json_decode(file_get_contents($privFile), true);
        if (isset($p['api_key'])) $settings['api_key'] = $p['api_key'];
        if (isset($p['or_api_key'])) $settings['or_api_key'] = $p['or_api_key'];
    }

    // Inherit from OpenRouterAI plugin if local key is empty
    if (empty($settings['or_api_key'])) {
        $orPrivFile = $data_dir . '/openrouter-private.json';
        if (file_exists($orPrivFile)) {
            $orP = json_decode(file_get_contents($orPrivFile), true);
            if (!empty($orP['api_key'])) $settings['or_api_key'] = $orP['api_key'];
        }
    }
    return $settings;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'cc_get_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'config' => cc_get_backend_settings()]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'cc_save_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $data_dir = CJOS_PATH_DATA;

    // Save Private Keys
    $privFile = $data_dir . '/backend-private.json';
    $privData = file_exists($privFile) ? json_decode(file_get_contents($privFile), true) : [];
    if (isset($_POST['api_key'])) $privData['api_key'] = $_POST['api_key'];
    if (isset($_POST['or_api_key'])) $privData['or_api_key'] = $_POST['or_api_key'];
    file_put_contents($privFile, json_encode($privData, JSON_PRETTY_PRINT));

    // Save Public Config
    $confFile = $data_dir . '/backend-config.json';
    $current_config = file_exists($confFile) ? json_decode(file_get_contents($confFile), true) : [];
    
    $fields = ['provider', 'model', 'prompt', 'or_model', 'or_prompt', 'or_temp', 'or_lang', 'sound_start', 'sound_stop'];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) $current_config[$f] = $_POST[$f];
    }

    file_put_contents($confFile, json_encode($current_config, JSON_PRETTY_PRINT));

    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'cc_refresh_or_models') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $settings = cc_get_backend_settings();
    $apiKey = $settings['or_api_key'];
    if (empty($apiKey)) {
        echo json_encode(['status' => 'error', 'message' => 'OpenRouter API Key required to fetch models.']);
        exit;
    }

    // Use the official transcription modality flag
    $ch = curl_init('https://openrouter.ai/api/v1/models?output_modalities=transcription');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey"]);
    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    if (isset($data['data']) && is_array($data['data'])) {
        // The server is now handling the filtering via the query parameter.
        // We just map and sort the results.
        $list = array_map(function($m) {
            return [
                'id' => $m['id'], 
                'name' => $m['name'] ?? $m['id'],
                'pricing' => $m['pricing'] ?? null
            ];
        }, $data['data']);

        // Sort alphabetically by name
        usort($list, fn($a, $b) => strcmp($a['name'], $b['name']));

        $confFile = CJOS_PATH_DATA . '/backend-config.json';
        $conf = file_exists($confFile) ? json_decode(file_get_contents($confFile), true) : [];
        $conf['or_models_cached'] = $list;
        file_put_contents($confFile, json_encode($conf, JSON_PRETTY_PRINT));

        echo json_encode(['status' => 'success', 'models' => $list]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch models from OpenRouter.']);
    }
    exit;
}

// --- FRONTEND SETTINGS UI ---
$plugin_settings_map['ConjureCore'] = <<<'HTML'
    <style>
        .cc-auth-mask { -webkit-text-security: disc !important; text-security: disc !important; }
        .cc-field-group { display: flex; flex-direction: column; gap: 14px; padding: 16px; }
        .cc-field { display: flex; flex-direction: column; }
        .cc-field input, .cc-field textarea { box-sizing: border-box !important; }
        .cc-label { font-size: 11px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; margin-left: 2px; }
        .cc-separator { height: 1px; background: var(--border-color); margin: 4px 16px; opacity: 0.4; }
        
        /* Custom Range Slider Styling */
        .cc-range { -webkit-appearance: none; width: 100%; height: 6px; background: var(--btn-bg); border-radius: 5px; outline: none; margin: 10px 0; border: 1px solid var(--border-color); }
        .cc-range::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 22px; height: 22px; border-radius: 50%; background: var(--primary); cursor: pointer; border: 3px solid var(--card-bg); box-shadow: 0 2px 6px rgba(0,0,0,0.2); transition: transform 0.1s; }
        .cc-range::-webkit-slider-thumb:active { transform: scale(1.1); }

        .cc-model-display { font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 700; color: var(--primary); word-break: break-all; line-height: 1.3; }
        .cc-sub-card { padding: 14px; background: var(--bg-color); border-radius: 14px; border: 1px solid var(--border-color); box-shadow: inset 0 1px 3px rgba(0,0,0,0.02); }
    </style>

    <!-- PROVIDER SUMMARY -->
    <div style="margin: 16px 16px 16px 16px; background: var(--btn-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 12px; display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <div style="width: 8px; height: 8px; border-radius: 50%; background: var(--primary); box-shadow: 0 0 8px var(--primary);"></div>
            <div style="font-size: 11px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">2 Providers Registered</div>
        </div>
        <div id="cc-active-badge" style="font-size: 10px; font-weight: 900; background: var(--primary); color: var(--primary-text); padding: 2px 8px; border-radius: 6px; text-transform: uppercase;">OpenAI</div>
    </div>

    <div class="cc-separator"></div>

    <!-- ACTIVE PROVIDER SELECTOR -->
    <div style="padding: 12px 16px 16px 16px;">
        <label class="cc-label" style="margin-left: 4px;">Active Transcription Provider</label>
        <input type="hidden" id="cc-provider" value="openai">
        <button onclick="ccOpenProviderPicker()" class="text-btn" style="
            width: 100%; text-align: left; background: var(--input-bg); color: var(--input-text); 
            border: 1px solid var(--border-color); padding: 14px; border-radius: 12px; font-weight: 600;
            display: flex; justify-content: space-between; align-items: center; box-shadow: inset 0 1px 3px rgba(0,0,0,0.05);
        ">
            <span id="cc-provider-display">OpenAI (Whisper)</span>
            <span data-sui-icon="chevron" data-sui-size="14" data-sui-stroke="3" style="color:var(--text-secondary);"></span>
        </button>
    </div>

    <div class="cc-separator"></div>

    <!-- PROVIDER SETTINGS ACCORDIONS -->
    <div style="margin-top: 4px; padding-bottom: 8px;">
        <!-- OPENAI ACCORDION -->
        <div style="display:flex; justify-content:space-between; align-items:center; padding: 14px 24px; cursor:pointer;" onclick="suiToggle('cc-sec-openai', true)">
            <div style="font-size:11px; font-weight:800; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.8px;">OpenAI Settings</div>
            <span data-sui-icon="chevron" data-sui-arrow="cc-sec-openai" data-sui-size="14" data-sui-stroke="3" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(-90deg);"></span>
        </div>
        <div class="sui-accordion" id="cc-sec-openai">
            <div class="sui-accordion-inner">
                <div style="margin: 0 16px 16px 16px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden;">
                    <div class="cc-field-group">
                        <div class="cc-field">
                            <label class="cc-label">API Key</label>
                            <input type="text" id="cc-auth-v1" class="cc-auth-mask" autocomplete="off" data-lpignore="true" data-bitwarden-ignore="true" spellcheck="false" placeholder="sk-..." style="width:100%; padding:14px; border-radius:12px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text);">
                        </div>
                        <div class="cc-field">
                            <label class="cc-label">Model (Required)</label>
                            <input type="text" id="cc-model" placeholder="e.g. whisper-1" style="width:100%; padding:14px; border-radius:12px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text);">
                        </div>
                        <div class="cc-field">
                            <label class="cc-label">Transcription Prompt</label>
                            <textarea id="cc-prompt" placeholder="Hints for OpenAI..." style="width:100%; height:70px; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text); resize:none; font-size:13px; line-height:1.4;"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- OPENROUTER ACCORDION -->
        <div style="display:flex; justify-content:space-between; align-items:center; padding: 14px 24px; cursor:pointer;" onclick="suiToggle('cc-sec-openrouter', true)">
            <div style="font-size:11px; font-weight:800; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.8px;">OpenRouter Settings</div>
            <span data-sui-icon="chevron" data-sui-arrow="cc-sec-openrouter" data-sui-size="14" data-sui-stroke="3" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(-90deg);"></span>
        </div>
        <div class="sui-accordion" id="cc-sec-openrouter">
            <div class="sui-accordion-inner">
                <div style="margin: 0 16px 16px 16px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.04);">
                    <div class="cc-field-group">
                        <!-- API KEY -->
                        <div class="cc-field">
                            <label class="cc-label">API Key</label>
                            <input type="text" id="cc-auth-v2" class="cc-auth-mask" autocomplete="off" data-lpignore="true" data-bitwarden-ignore="true" spellcheck="false" placeholder="Inherited from OpenRouterAI plugin" style="width:100%; padding:14px; border-radius:12px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text); font-size:14px;">
                        </div>
                        
                        <!-- MODEL SELECTOR -->
                        <div class="cc-field">
                            <label class="cc-label">Transcription Model</label>
                            <div style="display:flex; gap:10px; align-items:stretch;">
                                <input type="hidden" id="cc-or-model" value="">
                                <button onclick="ccOpenOrModelPicker()" class="text-btn" style="flex:1; text-align:left; background:var(--input-bg); color:var(--input-text); border:1px solid var(--border-color); padding:14px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; min-height:54px;">
                                    <span id="cc-or-model-display" class="cc-model-display">Select Model...</span>
                                    <span data-sui-icon="chevron" data-sui-size="14" style="opacity:0.5; margin-left:10px; flex-shrink:0;"></span>
                                </button>
                                <button onclick="ccRefreshOrModels()" class="icon-btn" title="Refresh List" style="width:54px; border-radius:12px; background:var(--btn-bg); border:1px solid var(--border-color); color:var(--primary); display:flex; align-items:center; justify-content:center; transition:all 0.2s;">
                                    <span data-sui-icon="refresh-cw" data-sui-size="20" data-sui-stroke="2.5"></span>
                                </button>
                            </div>
                        </div>

                        <!-- SHARED SETTINGS -->
                        <div class="cc-sub-card">
                            <div style="font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 14px; letter-spacing: 1px; display:flex; align-items:center; gap:6px;">
                                <div style="width:4px; height:4px; border-radius:50%; background:var(--text-secondary);"></div>
                                Shared Settings
                            </div>
                            <div style="display:flex; flex-direction:column; gap:16px;">
                                <div class="cc-field">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                        <label class="cc-label" style="margin:0; text-transform:none; font-size:12px; color:var(--text-primary);">Temperature</label>
                                        <span id="cc-or-temp-val" style="font-family:monospace; font-weight:800; color:var(--primary); background:var(--btn-bg); padding:2px 6px; border-radius:6px; font-size:11px;">0.0</span>
                                    </div>
                                    <input type="range" id="cc-or-temp" class="cc-range" min="0" max="1" step="0.1" value="0" oninput="document.getElementById('cc-or-temp-val').innerText = parseFloat(this.value).toFixed(1)">
                                </div>
                                <div class="cc-field">
                                    <label class="cc-label" style="margin:0; text-transform:none; font-size:12px; color:var(--text-primary); margin-bottom:6px;">Language Hint</label>
                                    <input type="text" id="cc-or-lang" placeholder="e.g. en, zh, ja" style="width:100%; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text); font-size:13px; font-weight:600;">
                                </div>
                            </div>
                        </div>

                        <!-- MODEL SPECIFIC SETTINGS -->
                        <div id="cc-or-specific-sec" style="display:none;" class="cc-sub-card">
                            <div style="font-size: 10px; font-weight: 900; color: var(--ai-accent); text-transform: uppercase; margin-bottom: 12px; letter-spacing: 1px; display:flex; align-items:center; gap:6px;">
                                <div style="width:4px; height:4px; border-radius:50%; background:var(--ai-accent);"></div>
                                Model-Specific Settings
                            </div>
                            <div class="cc-field">
                                <label class="cc-label" style="margin:0; text-transform:none; font-size:12px; color:var(--text-primary); margin-bottom:8px;">Transcription Prompt (Context Hints)</label>
                                <textarea id="cc-or-prompt" placeholder="Hints for this specific model..." style="width:100%; height:80px; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text); resize:none; font-size:13px; line-height:1.5; font-family:inherit;"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="cc-separator"></div>

    <!-- ACTION AREA -->
    <div style="padding: 16px; display: flex; flex-direction: column; gap: 12px;">
        <button onclick="ccSaveSettings()" class="btn-primary" style="width: 100%; padding: 14px; font-weight: 700; border-radius: 12px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 12px rgba(0, 122, 255, 0.2);">
            <span data-sui-icon="save" data-sui-size="18" data-sui-stroke="3"></span>
            Save Transcription Settings
        </button>
        
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0 4px;">
            <span id="cc-status" style="font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">Synced with Server</span>
            <button onclick="ccLoadSettings()" class="text-btn" style="font-size: 11px; font-weight: 800; color: var(--primary); text-transform: uppercase;">Discard & Reload</button>
        </div>
    </div>
HTML;

// --- FRONTEND JS ---
$plugin_js .=  <<<'JS'
// --- CONJURE CORE JS ---
window.ccOpenProviderPicker = function() {
    const options = [
        { label: "OpenAI (Whisper)", value: "openai" },
        { label: "OpenRouter (Multi-Model)", value: "openrouter" }
    ];
    const current = document.getElementById("cc-provider").value;
    window.openPicker("Transcription Provider", options, current, (val) => {
        document.getElementById("cc-provider").value = val;
        const label = options.find(o => o.value === val).label;
        document.getElementById("cc-provider-display").innerText = label;
        ccSaveSettings();
    });
};

let ccOrModelsCached = [];

window.ccRefreshOrModels = async function() {
    try {
        const res = await window.sui.api("cc_refresh_or_models", {}, { toast: "Fetching models..." });
        if (res && res.status === 'success') {
            ccOrModelsCached = res.models;
            window.sui.toast(`${res.models.length} STT Models Found`);
        }
    } catch(e) { console.error(e); }
};

window.ccOpenOrModelPicker = function() {
    if (ccOrModelsCached.length === 0) {
        window.openConfirm("Model List Empty", "Would you like to fetch the transcription model list from OpenRouter now?", () => ccRefreshOrModels());
        return;
    }
    
    const options = ccOrModelsCached.map(m => {
        let priceTag = "";
        if (m.pricing) {
            const p = parseFloat(m.pricing.prompt);
            if (p === 0) {
                priceTag = `<span style="color:var(--success-bg); font-weight:800; font-size:9px; background:rgba(52, 199, 89, 0.1); padding:2px 6px; border-radius:4px; margin-left:8px;">FREE</span>`;
            } else {
                const id = m.id.toLowerCase();
                // Detect if token-based (GPT-4o) or duration-based (Whisper/Chirp)
                const isToken = id.includes('transcribe') || id.includes('gpt');
                const displayPrice = isToken ? `$${(p * 1000000).toFixed(2)}/M` : `$${(p * 60).toFixed(3)}/m`;
                priceTag = `<span style="opacity:0.5; font-size:10px; font-weight:700; margin-left:8px; font-family:monospace;">${displayPrice}</span>`;
            }
        }

        return { 
            label: `<div style="display:flex; justify-content:space-between; align-items:center; width:100%;"><span style="font-weight:600;">${m.name}</span>${priceTag}</div>`, 
            value: m.id 
        };
    });

    const current = document.getElementById("cc-or-model").value;
    window.openPicker("OpenRouter STT Models", options, current, (val) => {
        document.getElementById("cc-or-model").value = val;
        document.getElementById("cc-or-model-display").innerText = val;
        ccUpdateOrSpecificVisibility(val);
    });
};

function ccUpdateOrSpecificVisibility(modelId) {
    const sec = document.getElementById("cc-or-specific-sec");
    if (!sec) return;
    // Show prompt only for OpenAI/Whisper models
    const isPromptSupported = modelId.includes('openai/') || modelId.includes('whisper');
    sec.style.display = isPromptSupported ? "block" : "none";
}

window.ccLoadSettings = async function() {
    try {
        const data = await window.sui.api("cc_get_config", {}, { toast: false });
        if(data && data.config) {
            const c = data.config;
            
            // Smarter helper that handles both Inputs and Display Spans
            const setVal = (id, val) => { 
                const el = document.getElementById(id); 
                if(!el) return;
                if(el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
                    el.value = val || "";
                } else {
                    el.innerText = val || "";
                }
            };
            
            setVal("cc-provider", c.provider);
            setVal("cc-auth-v1", c.api_key);
            setVal("cc-model", c.model);
            setVal("cc-auth-v2", c.or_api_key);
            setVal("cc-or-model", c.or_model);
            setVal("cc-or-model-display", c.or_model || "Select Model...");
            setVal("cc-prompt", c.prompt);
            setVal("cc-or-prompt", c.or_prompt);
            setVal("cc-or-temp", c.or_temp || 0.0);
            setVal("cc-or-lang", c.or_lang);
            
            const tempVal = document.getElementById('cc-or-temp-val');
            if (tempVal) tempVal.innerText = c.or_temp || "0.0";

            ccOrModelsCached = c.or_models_cached || [];
            ccUpdateOrSpecificVisibility(c.or_model || "");

            const label = c.provider === 'openai' ? 'OpenAI (Whisper)' : 'OpenRouter (Multi-Model)';
            const disp = document.getElementById("cc-provider-display");
            if (disp) disp.innerText = label;

            const badge = document.getElementById("cc-active-badge");
            if (badge) badge.innerText = c.provider === 'openai' ? 'OpenAI' : 'OpenRouter';

            localStorage.setItem("cjos_cc_provider", c.provider || "openai");
            localStorage.setItem("cjos_api_key", c.api_key || "");
            localStorage.setItem("cjos_model", c.model || "whisper-1");
            
            const status = document.getElementById("cc-status");
            if(status) status.innerText = "Synced";
        }
    } catch(e) { 
        const status = document.getElementById("cc-status");
        if(status) status.innerText = "Error"; 
    }
};

window.ccSaveSettings = async function() {
    const getVal = (id) => document.getElementById(id)?.value || "";
    const status = document.getElementById("cc-status");
    if (status) status.innerText = "Saving...";
    
    const payload = {
        provider: getVal("cc-provider"),
        api_key: getVal("cc-auth-v1"),
        model: getVal("cc-model"),
        or_api_key: getVal("cc-auth-v2"),
        or_model: getVal("cc-or-model"),
        or_prompt: getVal("cc-or-prompt"),
        or_temp: getVal("cc-or-temp"),
        or_lang: getVal("cc-or-lang"),
        prompt: getVal("cc-prompt"),
        sound_start: localStorage.getItem("cjos_sound_start") || "",
        sound_stop: localStorage.getItem("cjos_sound_stop") || ""
    };

    try {
        const res = await window.sui.api("cc_save_config", payload, { toast: "Transcription Settings Saved" });
        if (res && res.status === 'success') {
            if (window.sui && window.sui.haptic) window.sui.haptic('success');
            if (status) status.innerText = "Synced with Server";
            
            const badge = document.getElementById("cc-active-badge");
            if (badge) badge.innerText = payload.provider === 'openai' ? 'OpenAI' : 'OpenRouter';
            
            localStorage.setItem("cjos_cc_provider", payload.provider);
            localStorage.setItem("cjos_api_key", payload.api_key);
            localStorage.setItem("cjos_model", payload.model);
        }
    } catch(e) {
        if (status) status.innerText = "Save Failed";
    }
};

window.addEventListener("load", () => {
    setTimeout(ccLoadSettings, 500);
});
JS;
?>