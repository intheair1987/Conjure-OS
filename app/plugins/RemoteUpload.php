<?php
// ==============================================================================
// PLUGIN: Remote Upload
// DESCRIPTION: Remote Audio Ingestion. Handles audio files for AI transcription via external API calls.
// Upload, Transcribe, and Sort via CURL/HTTP.
// Uses global settings (API Key, Model, Prompt) from backend-config.json.
// ==============================================================================

// 1. ENSURE DATABASE TABLES EXIST
try {
    $db->exec("CREATE TABLE IF NOT EXISTS folders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        is_pinned INTEGER DEFAULT 0,
        created_at INTEGER,
        updated_at INTEGER
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS folder_map (
        log_id TEXT PRIMARY KEY,
        folder_id INTEGER,
        FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE CASCADE
    )");
} catch (Exception $e) {}

// --- HELPER: GET GLOBAL BACKEND SETTINGS ---
function get_ru_global_settings() {
    $data_dir = CJOS_PATH_DATA;
    $settings = [
        'provider' => 'openai',
        'api_key' => '', 
        'model' => 'whisper-1', 
        'prompt' => '',
        'or_api_key' => '',
        'or_model' => 'openai/whisper-large-v3',
        'or_prompt' => ''
    ];

    // Load Global Config (Public)
    $confFile = $data_dir . '/backend-config.json';
    if (file_exists($confFile)) {
        $c = json_decode(file_get_contents($confFile), true);
        if ($c) $settings = array_merge($settings, $c);
    }

    // Load Global Private (API Keys)
    $privFile = $data_dir . '/backend-private.json';
    if (file_exists($privFile)) {
        $p = json_decode(file_get_contents($privFile), true);
        if (isset($p['api_key'])) $settings['api_key'] = $p['api_key'];
        if (isset($p['or_api_key'])) $settings['or_api_key'] = $p['or_api_key'];
    }

    // Inherit from OpenRouterAI plugin if local transcription key is empty
    if (empty($settings['or_api_key'])) {
        $orPrivFile = $data_dir . '/openrouter-private.json';
        if (file_exists($orPrivFile)) {
            $orP = json_decode(file_get_contents($orPrivFile), true);
            if (!empty($orP['api_key'])) $settings['or_api_key'] = $orP['api_key'];
        }
    }
    
    return $settings;
}

// --- MAIN UPLOAD HANDLER ---

if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'remote_upload') {
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    while (ob_get_level()) @ob_end_clean();
    header('Content-Type: application/json');
    
    $response = [
        'status' => 'error',
        'message' => '',
        'transcription' => null
    ];

    try {
        // --- TIMEZONE CONFIGURATION ---
        $tz_file = CJOS_PATH_DATA . '/timezone.json';
        $timezone = 'UTC'; 

        if (file_exists($tz_file)) {
            $tz_data = json_decode(file_get_contents($tz_file), true);
            if (!empty($tz_data['mode']) && $tz_data['mode'] === 'Manual' && !empty($tz_data['manual_value'])) {
                $timezone = $tz_data['manual_value'];
            } elseif (!empty($tz_data['detected_value'])) {
                $timezone = $tz_data['detected_value'];
            }
        }
        try { date_default_timezone_set($timezone); } catch(Exception $e) {}

        // --- 1. LOAD CONFIGURATION ---
        $serverSettings = get_ru_global_settings();
        $provider = $serverSettings['provider'] ?? 'openai';

        if ($provider === 'openrouter') {
            $apiKey = $_SERVER['HTTP_X_OPENROUTER_KEY'] ?? $_POST['or_api_key'] ?? $serverSettings['or_api_key'] ?? '';
            $model  = $_POST['or_model'] ?? $serverSettings['or_model'] ?? '';
            $prompt = $_POST['or_prompt'] ?? $serverSettings['or_prompt'] ?? '';
            $endpoint = 'https://openrouter.ai/api/v1/audio/transcriptions';
        } else {
            $apiKey = $_SERVER['HTTP_X_OPENAI_KEY'] ?? $_POST['api_key'] ?? $serverSettings['api_key'] ?? '';
            $model  = $_POST['model'] ?? $serverSettings['model'] ?? '';
            $prompt = $_POST['prompt'] ?? $serverSettings['prompt'] ?? '';
            $endpoint = 'https://api.openai.com/v1/audio/transcriptions';
        }
        
        if (empty($apiKey)) {
            throw new Exception("Missing API Key for $provider. Please check Transcription Engine settings.");
        }

        $folderName = $_SERVER['HTTP_X_FOLDER'] ?? $_GET['folder'] ?? $_POST['folder'] ?? 'Unsorted';
        $folderName = trim($folderName);

        // --- TRANSCRIPTION MODE --- 
        // Default is ON. Set to 'off' to skip API call and return immediately.
        $transcribeMode = $_REQUEST['transcribe'] ?? $_SERVER['HTTP_X_TRANSCRIBE'] ?? 'on';
        $isRapidFire = (strtolower($transcribeMode) === 'off');

        // AI Monitoring Check: Only arm AI if we are actually transcribing in this request.
        // If transcribe=off, the AI will be triggered later when the text is actually generated.
        $ai_processed = 0;
        if (!$isRapidFire) {
            $ai_conf_file = CJOS_PATH_DATA . '/ai-assistant-config.json';
            if (file_exists($ai_conf_file)) {
                $ai_conf = json_decode(file_get_contents($ai_conf_file), true);
                if (!empty($ai_conf['monitoring_enabled']) && strtolower($folderName) !== 'archived') $ai_processed = 1;
            }
        }

        // --- 2. HANDLE UPLOAD ---
        $root_dir = CJOS_PATH_ROOT;
        $rec_dir = CJOS_PATH_STORAGE . '/audio';
        $trans_dir = CJOS_PATH_STORAGE . '/text';
        $rel_rec_path = 'recordings/audio';

        // --- DEMO MODE CHECK ---
        $demo_state_file = CJOS_PATH_DATA . '/demo-mode.json';
        $is_demo_mode = false;
        if (file_exists($demo_state_file)) {
            $dm_state = json_decode(file_get_contents($demo_state_file), true);
            if (!empty($dm_state['enabled'])) $is_demo_mode = true;
        }

        if ($is_demo_mode) {
            $demo_dir = CJOS_PATH_DATA . '/demo';
            if (!is_dir($demo_dir)) mkdir($demo_dir, 0777, true);
            $rec_dir = $demo_dir . '/audio';
            $trans_dir = $demo_dir . '/text';
            $rel_rec_path = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_DATA . '/demo/audio');
        }

        if (!is_dir($rec_dir)) mkdir($rec_dir, 0777, true);
        if (!is_dir($trans_dir)) mkdir($trans_dir, 0777, true);

        $timestamp = time();
        // Add microseconds and a random suffix to prevent collisions during rapid-fire uploads
        $id = date('Ymd_His', $timestamp) . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 4); 
        $targetPath = "";
        
        // Multipart Form
        if (!empty($_FILES['audio'])) {
            $errCode = $_FILES['audio']['error'] ?? UPLOAD_ERR_OK;
            if ($errCode !== UPLOAD_ERR_OK) {
                switch ($errCode) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        throw new Exception('Uploaded file exceeds server size limit (upload_max_filesize / post_max_size).');
                    case UPLOAD_ERR_PARTIAL:
                        throw new Exception('The uploaded file was only partially uploaded.');
                    case UPLOAD_ERR_NO_FILE:
                        throw new Exception('No file was uploaded.');
                    case UPLOAD_ERR_NO_TMP_DIR:
                        throw new Exception('Missing a temporary upload folder on server.');
                    case UPLOAD_ERR_CANT_WRITE:
                        throw new Exception('Failed to write file to disk.');
                    default:
                        throw new Exception('PHP upload error code: ' . $errCode);
                }
            }

            $originalFileName = $_FILES['audio']['name'];
            $ext = pathinfo($originalFileName, PATHINFO_EXTENSION) ?: 'webm';
            $targetPath = "$rec_dir/$id.$ext";
            if (!move_uploaded_file($_FILES['audio']['tmp_name'], $targetPath)) {
                if (!@copy($_FILES['audio']['tmp_name'], $targetPath)) {
                    throw new Exception('Failed to save multipart file to target path: ' . $targetPath);
                }
            }
        }
        // Raw Binary
        else {
            $rawInput = file_get_contents("php://input");
            if (strlen($rawInput) > 0) {
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                $ext = 'webm';
                if (strpos($contentType, 'mp4') !== false) $ext = 'mp4';
                if (strpos($contentType, 'm4a') !== false) $ext = 'm4a';
                if (strpos($contentType, 'wav') !== false) $ext = 'wav';
                if (strpos($contentType, 'mpeg') !== false) $ext = 'mp3';
                $targetPath = "$rec_dir/$id.$ext";
                file_put_contents($targetPath, $rawInput);
            } else {
                throw new Exception('No audio data received.');
            }
        }

        // --- 3. INITIAL DB LOGGING ---
        // We use "(Transcribing...)" instead of "(Pending Transcription...)" to prevent 
        // the client-side UI from trying to batch-transcribe this while the server is working.
        $date_display = date('Y-m-d H:i:s', $timestamp);
        $db->prepare("INSERT INTO logs (id, date_display, audio_path, transcription, timestamp, ai_processed) VALUES (:id, :date, :audio, :text, :ts, 0)")
           ->execute([
               ':id' => $id, 
               ':date' => $date_display, 
               ':audio' => "$rel_rec_path/" . basename($targetPath), 
               ':text' => "(Transcribing...)", 
               ':ts' => $timestamp
           ]);

        // Folder Sorting
        if (!empty($folderName) && $folderName !== 'Unsorted') {
            $stmt = $db->prepare("SELECT id FROM folders WHERE name = :name LIMIT 1");
            $stmt->execute([':name' => $folderName]);
            $folderId = $stmt->fetchColumn();
            if (!$folderId) {
                $db->prepare("INSERT INTO folders (name, created_at, updated_at) VALUES (:name, :ts, :ts)")
                   ->execute([':name' => $folderName, ':ts' => $timestamp]);
                $folderId = $db->lastInsertId();
            }
            $db->prepare("INSERT OR REPLACE INTO folder_map (log_id, folder_id) VALUES (?, ?)")
               ->execute([$id, $folderId]);
        }

        $response['id'] = $id;
        $response['server_time'] = $date_display;
        $response['status'] = 'success';

        // --- 4. TRANSCRIPTION STRATEGY ---
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $isRapidFire = (strtolower($transcribeMode) === 'off');
        
        if ($isRapidFire) {
            // Update DB to 'Pending' so the Web UI can pick it up for batch processing
            $db->prepare("UPDATE logs SET transcription = :text WHERE id = :id")
               ->execute([':text' => "(Pending Transcription...)", ':id' => $id]);

            $response['status'] = 'success';
            $response['transcription'] = "(Pending Transcription...)";
            $response['message'] = "Upload successful. Added to pending queue.";
            
            // Send response and TERMINATE immediately to release the client
            echo json_encode($response);
            exit;
        }

        // Proceed with Transcription logic (Default behavior)
        $shouldTranscribe = (strtolower($transcribeMode) !== 'never');
        if ($shouldTranscribe) {
            // GLOBAL LOCK CHECK
            $globalLock = CJOS_PATH_DATA . "/system-busy.lock";
            if (file_exists($globalLock) && (time() - filemtime($globalLock) < 120)) {
                // If busy, we fallback to 'Pending' mode automatically
                $transcribeMode = 'off';
                $shouldTranscribe = false;
            } else {
                file_put_contents($globalLock, getmypid());
                register_shutdown_function(function() use ($globalLock) { if(file_exists($globalLock)) unlink($globalLock); });
            }
        }

        if ($shouldTranscribe) {
            $ch = curl_init();
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $headers = [
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: http://' . $host,
                'X-Title: Conjure Remote Ingestion'
            ];

            if ($provider === 'openrouter') {
    // OpenRouter expects JSON with Base64 audio
    $audioData = base64_encode(file_get_contents($targetPath));
    $extension = pathinfo($targetPath, PATHINFO_EXTENSION);
            
    $payload = [
        'model' => $model,
        'input_audio' => [
            'data' => $audioData,
            'format' => $extension
        ]
    ];

    if (isset($serverSettings['or_temp']) && $serverSettings['or_temp'] !== '') $payload['temperature'] = (float)$serverSettings['or_temp'];
if (!empty($serverSettings['or_lang'])) $payload['language'] = $serverSettings['or_lang'];if ((strpos($model, 'openai/') !== false || strpos($model, 'whisper') !== false) && !empty($prompt)) {
        $payload['prompt'] = $prompt;
    }
            
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));} else {
                // OpenAI expects Multipart Form
                $fields = [
                    'file' => new CURLFile($targetPath),
                    'model' => $model
                ];
                if(!empty($prompt)) $fields['prompt'] = $prompt;

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

            $apiRes = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $errData = json_decode($apiRes, true);
                $detail = $errData['error']['message'] ?? $errData['message'] ?? ($curlErr ? "cURL Error: $curlErr" : $apiRes);
                $transcriptionText = "[" . ucfirst($provider) . " Error $httpCode]\nModel: $model\nDetail: $detail";
                $response['message'] = "Transcription failed ($httpCode). " . ($curlErr ? "cURL: $curlErr" : "See log for details.");
            } else {
                $json = json_decode($apiRes, true);
                $transcriptionText = $json['text'] ?? '[No text returned]';
            }
        }

        // Save Text
        $trans_dir = CJOS_PATH_STORAGE . '/text';
        if (!is_dir($trans_dir)) mkdir($trans_dir, 0777, true);
        file_put_contents("$trans_dir/$id.txt", $transcriptionText);

        // --- 5. UPDATE DB WITH RESULT ---
        $db->prepare("UPDATE logs SET transcription = :text, ai_processed = :ai WHERE id = :id")
           ->execute([':text' => $transcriptionText, ':ai' => $ai_processed, ':id' => $id]);

        $response['transcription'] = $transcriptionText;
        $response['text'] = $transcriptionText;

        // RELEASE LOCK: Allow the upcoming AI trigger to proceed
        if (isset($globalLock) && file_exists($globalLock)) unlink($globalLock);

        // Clean buffer and set headers for connection close
        $jsonResponse = json_encode($response);
        header('Connection: close');
        header('Content-Length: ' . strlen($jsonResponse));
        
        echo $jsonResponse;
        
        // Flush buffer to release client immediately
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_end_flush();
            @ob_flush();
            @flush();
        }

        // --- 6. TRIGGER AI PIPELINE (Non-Blocking Loopback) ---
        if ($ai_processed == 1) {
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

    } catch (Exception $e) {$response['message'] = $e->getMessage();
http_response_code(400); 
echo json_encode($response);
exit;
    }}

// 3. SETTINGS UI
$plugin_settings_map['RemoteUpload'] = <<<'HTML'
    <div class="setting-item" style="display:block; text-align:center; padding:20px; color:var(--text-secondary); font-size:13px; background:var(--btn-bg); border-radius:12px; border:1px solid var(--border-color);">
        <div style="margin-bottom:8px; font-weight:600; color:var(--text-primary);">Inherited Settings</div>
        This plugin now uses the <strong>API Key</strong>, <strong>Model</strong>, and <strong>Prompt</strong><br>
        defined in the main <a onclick="document.querySelector('[onclick*=\'sec-trans\']').click();" style="color:var(--primary); cursor:pointer; text-decoration:underline;">Transcription Settings</a>.
    </div>

    <div class="setting-item vertical" style="margin-top:16px; background:var(--card-bg); padding:16px; border-radius:12px; border:1px solid var(--border-color);">
        <div style="font-weight:600; font-size:14px; color:var(--text-primary); margin-bottom:4px;">Manual Audio Upload</div>
        <div class="setting-desc" style="margin-bottom:12px;">Upload an audio file directly with a target folder and optional transcription prompt or tag.</div>
        
        <div style="display:flex; flex-direction:column; gap:10px;">
            <div>
                <label class="setting-label" style="font-size:11px; margin-bottom:4px; display:block;">Target Folder / Stack</label>
                <input type="text" id="ru-manual-folder" placeholder="e.g., Inbox, Work, Personal" value="Inbox" style="width:100%; padding:8px 10px; font-size:12px; border-radius:8px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary);">
            </div>

            <div>
                <label class="setting-label" style="font-size:11px; margin-bottom:4px; display:block;">Transcription Flag (Mode)</label>
                <select id="ru-manual-transcribe" style="width:100%; padding:8px 10px; font-size:12px; border-radius:8px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary);">
                    <option value="on" selected>On — Transcribe Immediately</option>
                    <option value="off">Off — Rapid Upload (Pending Queue)</option>
                </select>
            </div>

            <input type="file" id="ru-manual-file-input" accept="audio/*" style="display:none;" onchange="window.handleRuManualFileSelected && window.handleRuManualFileSelected(this)">

            <div style="display:flex; gap:10px; align-items:center; margin-top:4px;">
                <label for="ru-manual-file-input" id="ru-manual-upload-btn" style="padding:10px 16px; background:var(--primary); color:var(--primary-text); border:none; border-radius:8px; font-weight:600; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; user-select:none; -webkit-user-select:none; -webkit-tap-highlight-color:transparent;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    <span>Choose File & Upload</span>
                </label>
                <span id="ru-manual-status" style="font-size:11px; color:var(--text-secondary); font-weight:500;"></span>
            </div>
        </div>
    </div>

    <div class="setting-item vertical" style="margin-top:16px;">
        <label class="setting-label">CURL Command</label>
        <div class="setting-desc">Tap below to copy. Use this to upload audio from Shortcuts or Terminal.</div>
        <div style="position:relative; margin-top:8px;">
            <textarea id="ru-curl-box" readonly style="
                width:100%; height:130px; font-family:monospace; font-size:11px; 
                padding:12px; border-radius:8px; border:1px solid var(--border-color); 
                background:var(--bg-color); resize:none; color:var(--text-primary); display:block;
                cursor:pointer; line-height:1.4; white-space:pre-wrap; overflow-x:hidden;
                -webkit-user-select: none; user-select: none;
            " onclick="copyRuCurl()"></textarea>
            
            <div style="
                position:absolute; top:8px; right:8px; 
                background:rgba(0,0,0,0.05); color:#8E8E93; 
                padding:4px 8px; border-radius:6px; 
                font-size:10px; font-weight:700; pointer-events:none;
            ">TAP TO COPY</div>
        </div>
    </div>
HTML;

// 4. JS LOGIC
$plugin_js .=  <<<'JS'
// --- REMOTE UPLOAD JS ---

window.handleRuManualFileSelected = function(input) {
    if (!input || !input.files || !input.files[0]) return;
    const file = input.files[0];
    
    const folderInput = document.getElementById("ru-manual-folder");
    const transcribeSelect = document.getElementById("ru-manual-transcribe");
    const folderVal = (folderInput ? folderInput.value : "Inbox").trim() || "Inbox";
    const transcribeVal = transcribeSelect ? transcribeSelect.value : "on";
    const statusEl = document.getElementById("ru-manual-status");
    const uploadBtn = document.getElementById("ru-manual-upload-btn");
    
    if (statusEl) {
        statusEl.style.color = "var(--text-secondary)";
        statusEl.textContent = "Uploading & processing...";
    }
    if (uploadBtn) {
        uploadBtn.style.pointerEvents = "none";
        uploadBtn.style.opacity = "0.6";
    }

    const formData = new FormData();
    formData.append("audio", file);
    formData.append("folder", folderVal);
    formData.append("transcribe", transcribeVal);

    const protocol = window.location.protocol;
    const host = window.location.host;
    const path = window.location.pathname;
    const uploadUrl = `${protocol}//${host}${path}?plugin_action=remote_upload`;

    fetch(uploadUrl, {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === "success") {
            if (statusEl) {
                statusEl.style.color = "var(--success-bg, #22c55e)";
                statusEl.textContent = "Upload successful!";
            }
            if (window.sui && window.sui.toast) {
                window.sui.toast("Audio uploaded & transcribed!", { plugin: "RemoteUpload", caller: "handleRuManualFileSelected" });
            }
            if (typeof window.fetchLogs === "function") {
                window.fetchLogs();
            } else if (typeof window.loadNotes === "function") {
                window.loadNotes();
            }
        } else {
            throw new Error(data.message || "Upload failed");
        }
    })
    .catch(err => {
        if (statusEl) {
            statusEl.style.color = "var(--danger, #ef4444)";
            statusEl.textContent = `Error: ${err.message}`;
        }
        if (window.sui && window.sui.toast) {
            window.sui.toast(`Upload Error: ${err.message}`, { plugin: "RemoteUpload", caller: "handleRuManualFileSelected" });
        }
    })
    .finally(() => {
        if (uploadBtn) {
            uploadBtn.style.pointerEvents = "auto";
            uploadBtn.style.opacity = "1";
        }
        input.value = "";
    });
};

// Global delegation listener for file input change events as fallback
document.addEventListener("change", (e) => {
    if (e.target && e.target.id === "ru-manual-file-input") {
        if (window.handleRuManualFileSelected) window.handleRuManualFileSelected(e.target);
    }
});

function updateRuExample() {
    const box = document.getElementById("ru-curl-box");
    if(!box) return;

    const protocol = window.location.protocol;
    const host = window.location.host;
    const path = window.location.pathname;
    const fullUrl = protocol + "//" + host + path;

    // We no longer show X-OpenAI-Key in the example as it uses the server default
    const cmd = `curl -X POST -F "audio=@/path/to/recording.m4a" -H "X-Folder: Inbox" "${fullUrl}?plugin_action=remote_upload&transcribe=off"`;
    
    box.value = cmd;
}

window.copyRuCurl = function() {
    const box = document.getElementById("ru-curl-box");
    if(!box) return;
    const val = box.value;
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(val).then(() => triggerCopyFeedback(box));
    } else {
        // Fallback
        const temp = document.createElement("textarea");
        temp.value = val;
        temp.style.position = "fixed";
        temp.style.left = "-9999px";
        document.body.appendChild(temp);
        temp.select();
        document.execCommand("copy");
        document.body.removeChild(temp);
        triggerCopyFeedback(box);
    }
};

function triggerCopyFeedback(el) {
    el.style.background = "#E0F8E0"; 
    el.style.transition = "background 0.2s";
    setTimeout(() => { el.style.background = "#F9F9F9"; }, 250);
    if (window.sui && window.sui.toast) {
        window.sui.toast("Copied to Clipboard", { plugin: "RemoteUpload", caller: "copyRuCurl" });
    }
}

// Initial Load
window.addEventListener("load", () => {
    updateRuExample();
});
JS;
?>