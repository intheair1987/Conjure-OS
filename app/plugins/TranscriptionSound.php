<?php
// ==============================================================================
// PLUGIN: Transcription Sound
// DESCRIPTION: Completion Audio Chime.
// Plays a sound effect when transcription finishes.
// 1. Intercepts UI Toast notifications.
// 2. Saves configuration to CJOS_PATH_DATA/transcription-sound.json
// ==============================================================================

$ts_config_file = CJOS_PATH_DATA . '/transcription-sound.json';

// --- BACKEND HANDLERS ---

if (isset($_POST['plugin_action'])) {
    
    // SAVE CONFIG
    if ($_POST['plugin_action'] === 'ts_save_config') {
        // Clean buffer
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $path = $_POST['sound_path'] ?? '';
        $data = ['sound_path' => trim($path)];
        
        // Ensure data dir exists (just in case)
        $dir = dirname($ts_config_file);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        file_put_contents($ts_config_file, json_encode($data, JSON_PRETTY_PRINT));
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET CONFIG
    if ($_POST['plugin_action'] === 'ts_get_config') {
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $config = ['sound_path' => ''];
        if (file_exists($ts_config_file)) {
            $loaded = json_decode(file_get_contents($ts_config_file), true);
            if (isset($loaded['sound_path'])) $config['sound_path'] = $loaded['sound_path'];
        }
        
        echo json_encode(['status' => 'success', 'config' => $config]);
        exit;
    }
}

// --- SETTINGS UI ---
$plugin_settings_map['TranscriptionSound'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Completion Sound</label>
        <div class="setting-desc">Path to audio file (e.g., sounds/done.mp3). Leave empty for default chime. Saved to server.</div>
        <div style="display:flex; gap:8px; margin-top:8px;">
            <input type="text" id="ts-sound-path" placeholder="Default: Electronic Chime" style="flex:1;">
            <button onclick="playTxCompleteSound(true)" style="
                background: var(--btn-bg); color: var(--text-primary); border: 1px solid var(--border-color); 
                padding: 0 16px; border-radius: 8px; font-weight: 600; cursor: pointer;
            ">Test</button>
        </div>
        <div id="ts-save-status" style="font-size:11px; color:#8E8E93; margin-top:6px; height:14px;"></div>
    </div>
HTML;

// --- JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- TRANSCRIPTION SOUND JS ---

let tsCurrentPath = ""; // Global cache

// 1. Init & Load
window.addEventListener("load", () => {
    // Load from Server
    loadTsConfig();

    // Bind Input Save
    const input = document.getElementById("ts-sound-path");
    if(input) {
        input.addEventListener("change", (e) => {
            saveTsConfig(e.target.value);
        });
    }
    
    // Start the Observer
    initToastObserver();
});

// 2. Server Sync Functions
async function loadTsConfig() {
    try {
        const data = await window.sui.api("ts_get_config", {}, { toast: false });
        if (data) {
            tsCurrentPath = data.config.sound_path;
            const input = document.getElementById("ts-sound-path");
            if (input) input.value = tsCurrentPath;
        }
    } catch(e) { console.error("TS Load Error", e); }
}

async function saveTsConfig(val) {
    const status = document.getElementById("ts-save-status");
    if(status) status.innerText = "Saving...";
    
    try {
        const data = await window.sui.api("ts_save_config", { sound_path: val }, { toast: "Sound Path Saved" });
        if (data) {
            tsCurrentPath = val;
            if(status) status.innerText = "";
        }
    } catch(e) {
        if(status) status.innerText = "Save failed.";
    }
}

// 3. The Sound Logic
window.playTxCompleteSound = function(force = false) {
    // Use cached path from server
    if (tsCurrentPath && tsCurrentPath.trim() !== "") {
        // A. Custom File
        const audio = new Audio(tsCurrentPath);
        audio.play().catch(e => {
            console.error("Audio Play Error:", e);
            if(force) window.openConfirm("Playback Error", "Could not play file: " + e.message, null, true, "OK", null);
        });
    } else {
        // B. Default Generated Chime (Web Audio API)
        playSuccessChime();
    }
};

// Helper: Nice "Success" Chime
function playSuccessChime() {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    
    const playNote = (freq, startTime, duration) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        
        osc.type = "sine";
        osc.frequency.value = freq;
        
        osc.connect(gain);
        gain.connect(ctx.destination);
        
        osc.start(startTime);
        
        // Envelope: Attack -> Decay
        gain.gain.setValueAtTime(0, startTime);
        gain.gain.linearRampToValueAtTime(0.3, startTime + 0.05);
        gain.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
        
        osc.stop(startTime + duration);
    };

    const now = ctx.currentTime;
    // Play a Major Third interval (C5 -> E5) for a happy "Ding-Dong"
    playNote(523.25, now, 0.4);       // C5
    playNote(659.25, now + 0.1, 0.6); // E5
}

// 4. The Interceptor (Observer)
function initToastObserver() {
    const toast = document.getElementById("toast");
    if (!toast) return;

    let lastPlayed = 0;

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.attributeName === "class") {
                // Check if Toast is being shown
                const isVisible = toast.classList.contains("show");
                if (isVisible) {
                    const text = (toast.textContent || "").toLowerCase();
                    
                    // Define trigger phrases used by LiveSync / Recorder
                    const triggers = [
                        "transcription done",
                        "transcription complete", 
                        "entry synced",
                        "upload complete",
                        "reprocessed"
                    ];

                    // Check if text matches valid triggers
                    const isMatch = triggers.some(t => text.includes(t));

                    // Debounce (prevent double playing within 2 seconds)
                    const now = Date.now();
                    if (isMatch && (now - lastPlayed > 2000)) {
                        lastPlayed = now;
                        playTxCompleteSound();
                    }
                }
            }
        });
    });

    observer.observe(toast, { attributes: true, childList: true, characterData: true     });
}
JS;
?>