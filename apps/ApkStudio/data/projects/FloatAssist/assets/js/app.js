document.addEventListener('DOMContentLoaded', () => {
    // 1. Initialize Values from Android SharedPreferences via WebBridge
    const isRunning = window.Android ? window.Android.getServiceStatus() : false;
    document.getElementById('toggleHelper').checked = isRunning;
    updateServiceUIStatus(isRunning);

    const savedSize = window.Android ? window.Android.getSettingInt("ball_size", 56) : 56;
    document.getElementById('sliderSize').value = savedSize;
    document.getElementById('valSize').innerText = savedSize + "px";

    const savedGlow = window.Android ? window.Android.getSettingInt("ball_glow", 10) : 10;
    document.getElementById('sliderGlow').value = savedGlow;
    document.getElementById('valGlow').innerText = (savedGlow / 10.0).toFixed(1) + "x";

    const savedColor = window.Android ? window.Android.getSettingString("ball_color", "#6366f1") : "#6366f1";
    const savedRgb = window.Android ? window.Android.getSettingString("ball_color_rgb", "99, 102, 241") : "99, 102, 241";
    const savedName = window.Android ? window.Android.getSettingString("ball_color_name", "Indigo Flare") : "Indigo Flare";
    applySpectrum(savedColor, savedRgb, savedName);

    // Base URL Settings Engine (Auto-Detect vs. Manual Exclusive Toggles)
    const isAutoDetect = window.Android ? (window.Android.getSettingInt("auto_detect_urls", 1) === 1) : true;
    const isManual = window.Android ? (window.Android.getSettingInt("manual_urls_enabled", 0) === 1) : false;

    const savedManualHttps = window.Android ? window.Android.getSettingString("manual_https_url", "https://127.0.0.1:8000") : "https://127.0.0.1:8000";
    const savedManualHttp = window.Android ? window.Android.getSettingString("manual_http_url", "http://127.0.0.1:8001") : "http://127.0.0.1:8001";

    const chkAuto = document.getElementById('toggleAutoDetectUrls');
    const chkManual = document.getElementById('toggleManualUrls');
    const inpHttps = document.getElementById('inpManualHttpsUrl');
    const inpHttp = document.getElementById('inpManualHttpUrl');
    const boxManualInputs = document.getElementById('boxManualUrlsInputs');

    if (inpHttps) inpHttps.value = savedManualHttps;
    if (inpHttp) inpHttp.value = savedManualHttp;

    function applyUrlModeUI(autoState, manualState) {
        if (chkAuto) chkAuto.checked = autoState;
        if (chkManual) chkManual.checked = manualState;

        if (boxManualInputs) {
            boxManualInputs.style.opacity = manualState ? '1' : '0.4';
            boxManualInputs.style.pointerEvents = manualState ? 'auto' : 'none';
        }

        if (window.Android) {
            window.Android.saveSettingInt("auto_detect_urls", autoState ? 1 : 0);
            window.Android.saveSettingInt("manual_urls_enabled", manualState ? 1 : 0);
        }
        pollAutoDetectedManifest();
    }

    applyUrlModeUI(isAutoDetect, isManual);

    if (chkAuto) {
        chkAuto.addEventListener('change', (e) => {
            const newState = e.target.checked;
            applyUrlModeUI(newState, !newState);
        });
    }

    if (chkManual) {
        chkManual.addEventListener('change', (e) => {
            const newState = e.target.checked;
            applyUrlModeUI(!newState, newState);
        });
    }

    if (inpHttps) {
        inpHttps.addEventListener('change', (e) => {
            const val = e.target.value.trim() || "https://127.0.0.1:8000";
            if (window.Android) window.Android.saveSettingString("manual_https_url", val);
        });
    }

    if (inpHttp) {
        inpHttp.addEventListener('change', (e) => {
            const val = e.target.value.trim() || "http://127.0.0.1:8001";
            if (window.Android) window.Android.saveSettingString("manual_http_url", val);
        });
    }

    function pollAutoDetectedManifest() {
        const box = document.getElementById('boxAutoDetectedUrls');
        if (!box) return;

        let manifest = null;
        if (window.Android && typeof window.Android.getRuntimeActiveJson === 'function') {
            try {
                const raw = window.Android.getRuntimeActiveJson();
                if (raw && raw !== '{}') manifest = JSON.parse(raw);
            } catch(e) {}
        }

        if (manifest && manifest.status === 'RUNNING') {
            box.innerHTML = `<span style="color:#34d399;">✓ ACTIVE:</span> ${manifest.https_url || ''} <span style="color:rgba(255,255,255,0.4);">\|</span> ${manifest.http_url || ''}`;
            box.style.borderColor = 'rgba(52, 211, 153, 0.4)';
        } else {
            box.innerHTML = `<span style="color:#f59e0b;">⚠️ OFFLINE:</span> Runtime manifest not found at /sdcard/Conjure_Config/runtime_active.json`;
            box.style.borderColor = 'rgba(245, 158, 11, 0.3)';
        }
    }

    pollAutoDetectedManifest();
    setInterval(pollAutoDetectedManifest, 3000);

    const savedDismissBack = window.Android ? (window.Android.getSettingInt("dismiss_pod_with_back", 1) === 1) : true;
    const chkDismissBack = document.getElementById('toggleDismissBack');
    if (chkDismissBack) {
        chkDismissBack.checked = savedDismissBack;
        chkDismissBack.addEventListener('change', (e) => {
            if (window.Android) window.Android.saveSettingInt("dismiss_pod_with_back", e.target.checked ? 1 : 0);
        });
    }

    if (window.updatePermissionUI) window.updatePermissionUI();

    // 2. Bind Event Listeners
    document.getElementById('toggleHelper').addEventListener('change', (e) => {
        if (window.Android) window.Android.toggleService(e.target.checked);
        updateServiceUIStatus(e.target.checked);
    });

    document.getElementById('sliderSize').addEventListener('input', (e) => {
        const val = e.target.value;
        document.getElementById('valSize').innerText = val + "px";
        if (window.Android) window.Android.saveSettingInt("ball_size", parseInt(val));
    });

    document.getElementById('sliderGlow').addEventListener('input', (e) => {
        const val = e.target.value;
        document.getElementById('valGlow').innerText = (val / 10.0).toFixed(1) + "x";
        if (window.Android) window.Android.saveSettingInt("ball_glow", parseInt(val));
    });

    document.querySelectorAll('.color-dot').forEach(dot => {
        dot.addEventListener('click', () => {
            const hex = dot.getAttribute('data-color');
            const rgb = dot.getAttribute('data-rgb');
            const name = dot.getAttribute('data-name');
            applySpectrum(hex, rgb, name);
            
            if (window.Android) {
                window.Android.saveSettingString("ball_color", hex);
                window.Android.saveSettingString("ball_color_rgb", rgb);
                window.Android.saveSettingString("ball_color_name", name);
            }
        });
    });

    document.getElementById('btnPermission').addEventListener('click', () => {
        if (window.Android && !window.Android.getPermissionStatus()) {
            window.Android.requestPermission();
        }
    });

    const btnCopyCmd = document.getElementById('btnCopyTermuxCmd');
    if (btnCopyCmd) {
        btnCopyCmd.addEventListener('click', () => {
            const cmdText = 'echo "allow-external-apps = true" >> ~/.termux/termux.properties && termux-reload-settings';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(cmdText);
            }
            const orig = btnCopyCmd.textContent;
            btnCopyCmd.textContent = 'Copied!';
            setTimeout(() => btnCopyCmd.textContent = orig, 1500);
        });
    }
});

function applySpectrum(hex, rgb, name) {
    document.querySelectorAll('.color-dot').forEach(d => d.classList.remove('active'));
    const dot = document.querySelector(`.color-dot[data-color="${hex}"]`);
    if (dot) dot.classList.add('active');
    
    // Dynamically shift CSS root variables to match the selected theme
    document.documentElement.style.setProperty('--color-primary', hex);
    document.documentElement.style.setProperty('--color-primary-rgb', rgb);
    document.documentElement.style.setProperty('--glow-color', `rgba(${rgb}, 0.45)`);
    document.documentElement.style.setProperty('--glow-color-soft', `rgba(${rgb}, 0.15)`);
    
    // Update Telemetry labels
    const telSpec = document.getElementById('telSpectral');
    const badge = document.querySelector('.dash-badge');
    if (telSpec) {
        telSpec.innerText = name;
        telSpec.style.color = hex;
    }
    if (badge) {
        badge.style.color = hex;
    }
}

function updateServiceUIStatus(isRunning) {
    const txt = document.getElementById('txtServiceStatus');
    if (isRunning) {
        txt.innerText = "🟢 Status: Running";
        txt.style.color = "#10b981";
    } else {
        txt.innerText = "🔴 Status: Stopped";
        txt.style.color = "#ef4444";
    }
}

// 3. Callback functions exposed to Java (WebBridge)
window.updatePermissionUI = function() {
    const hasPerm = window.Android ? window.Android.getPermissionStatus() : true;
    const btn = document.getElementById('btnPermission');
    if (hasPerm) {
        btn.classList.add('granted');
        document.getElementById('lblPermission').innerText = "Granted";
    } else {
        btn.classList.remove('granted');
        document.getElementById('lblPermission').innerText = "Request";
    }
};

window.updateServiceUI = function() {
    const isRunning = window.Android ? window.Android.getServiceStatus() : false;
    document.getElementById('toggleHelper').checked = isRunning;
    updateServiceUIStatus(isRunning);
};

window.updateTelemetry = function(velocity, deformation, coords, constraint) {
    const elVel = document.getElementById('telVelocity');
    const elDef = document.getElementById('telDeformation');
    const elCoo = document.getElementById('telCoords');
    const elCon = document.getElementById('telConstraint');
    
    if (elVel) elVel.innerText = velocity;
    if (elDef) elDef.innerText = deformation;
    if (elCoo) elCoo.innerText = coords;
    if (elCon) elCon.innerText = constraint;
};