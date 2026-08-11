<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Wrapper Settings</title>
    <style>
        :root {
            --bg-color: #08080d;
            --card-bg: #12121a;
            --text-primary: #f5f5f7;
            --text-secondary: #a1a1aa;
            --primary-accent: #7c6cff;
            --primary-accent-strong: #5f52e8;
            --success: #34d399;
            --danger: #f87171;
            --border-color: rgba(255, 255, 255, 0.1);
            --font-main: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --font-mono: ui-monospace, SFMono-Regular, Menlo, monospace;
            --radius-container: 16px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg-color);
            color: var(--text-primary);
            font-family: var(--font-main);
            min-height: 100vh;
            padding: 24px 16px 80px 16px;
            -webkit-font-smoothing: antialiased;
        }

        .shell {
            max-width: 520px;
            margin: 0 auto;
        }

        .header {
            margin-bottom: 24px;
        }

        .eyebrow {
            color: var(--primary-accent);
            font-family: var(--font-mono);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        h1 {
            font-size: 26px;
            font-weight: 800;
            margin: 4px 0 6px 0;
            letter-spacing: -0.02em;
        }

        p.desc {
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.5;
        }

        .section-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-container);
            padding: 18px 20px;
            margin-bottom: 16px;
        }

        .section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-secondary);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .theme-options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .theme-card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 8px;
            text-align: center;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.02);
            transition: all 0.2s ease;
            user-select: none;
        }

        .theme-card.active {
            border-color: var(--primary-accent);
            background: rgba(124, 108, 255, 0.15);
            color: #ffffff;
        }

        .theme-card span {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-top: 4px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group:last-child { margin-bottom: 0; }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-color);
            color: var(--text-primary);
            font-family: var(--font-mono);
            font-size: 13px;
            outline: none;
        }

        .form-input:focus {
            border-color: var(--primary-accent);
        }

        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .toggle-row:last-child { border-bottom: none; }

        .toggle-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .toggle-sub {
            font-size: 11px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 42px;
            height: 24px;
            flex-shrink: 0;
        }

        .switch input { opacity: 0; width: 0; height: 0; }

        .switch-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(255, 255, 255, 0.12);
            transition: 0.25s ease;
            border-radius: 24px;
            border: 1px solid var(--border-color);
        }

        .switch-slider::before {
            content: "";
            position: absolute;
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: var(--text-secondary);
            transition: 0.25s ease;
            border-radius: 50%;
        }

        input:checked + .switch-slider {
            background-color: var(--primary-accent);
            border-color: var(--primary-accent);
        }

        input:checked + .switch-slider::before {
            transform: translateX(18px);
            background-color: #ffffff;
        }

        .btn-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }

        .action-btn {
            padding: 10px 8px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.03);
            color: var(--text-primary);
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }

        .action-btn:active {
            border-color: var(--primary-accent);
            color: var(--primary-accent);
        }

        .btn-purge-master {
            width: 100%;
            padding: 14px;
            border: 1px solid rgba(248, 113, 113, 0.3);
            border-radius: 12px;
            background: rgba(248, 113, 113, 0.1);
            color: var(--danger);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-purge-master:active {
            background: rgba(248, 113, 113, 0.25);
        }

        .footer-bar {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: rgba(18, 18, 26, 0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-top: 1px solid var(--border-color);
            padding: 12px 16px;
            display: flex;
            gap: 10px;
            z-index: 100;
        }

        .btn-footer-primary {
            flex: 2;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: var(--primary-accent);
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-footer-secondary {
            flex: 1;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: transparent;
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .toast {
            position: fixed;
            top: 16px; left: 50%;
            transform: translateX(-50%) translateY(-60px);
            background: rgba(18, 18, 26, 0.95);
            border: 1px solid var(--success);
            color: var(--success);
            padding: 10px 18px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            opacity: 0;
            pointer-events: none;
            z-index: 1000;
        }

        .toast.active {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
    </style>
</head>
<body>
    <div id="toast" class="toast">Action Complete</div>

    <div class="shell">
        <header class="header">
            <span class="eyebrow">WRAPPER SETTINGS</span>
            <h1>Browser Settings</h1>
            <p class="desc">Configure theme mode, connection target, and browser cache clearing.</p>
        </header>

        <!-- Section 1: Appearance -->
        <div class="section-card">
            <div class="section-title">🎨 Appearance & Display Mode</div>
            <div class="theme-options" style="margin-bottom: 14px;">
                <div class="theme-card active" data-theme="system" onclick="selectTheme('system')">
                    <div style="font-size: 18px;">📱</div>
                    <span>System</span>
                </div>
                <div class="theme-card" data-theme="light" onclick="selectTheme('light')">
                    <div style="font-size: 18px;">☀️</div>
                    <span>Light</span>
                </div>
                <div class="theme-card" data-theme="dark" onclick="selectTheme('dark')">
                    <div style="font-size: 18px;">🌙</div>
                    <span>Dark</span>
                </div>
            </div>
            <div class="form-group">
                <label for="sel-statusbar-mode">Status Bar Style</label>
                <select id="sel-statusbar-mode" class="form-input" style="background: var(--bg-color); color: var(--text-primary); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 12px; outline: none; width: 100%;">
                    <option value="fullscreen">Full Screen (Hide Status Bar)</option>
                    <option value="transparent">Transparent Overlay (Content Under Icons)</option>
                    <option value="solid">Standard Solid Status Bar</option>
                </select>
            </div>
        </div>

        <!-- Section 2: Target URL -->
        <div class="section-card">
            <div class="section-title">🌐 Target Connection URL</div>
            <div class="form-group">
                <label for="inp-url">Web Application Address</label>
                <input type="url" id="inp-url" class="form-input" placeholder="https://127.0.0.1:8000/" autocomplete="off" autocapitalize="none" spellcheck="false">
            </div>
        </div>

        <!-- Section: External Link & Tab Management -->
        <div class="section-card">
            <div class="section-title">🔗 External Link & Tab Behavior</div>
            
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="sel-link-mode">External & _blank Link Action</label>
                <select id="sel-link-mode" class="form-input" style="background: var(--bg-color); color: var(--text-primary); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 12px; outline: none; width: 100%;">
                    <option value="prompt">Ask Every Time (Overlay vs External)</option>
                    <option value="overlay">Always Open in Overlay Tab</option>
                    <option value="external">Always Open in External Browser</option>
                </select>
            </div>

            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Enable Multi-Tab Mode</div>
                    <div class="toggle-sub">Shows floating tab counter badge at bottom-right when multiple tabs open</div>
                </div>
                <label class="switch">
                    <input type="checkbox" id="chk-multitab">
                    <span class="switch-slider"></span>
                </label>
            </div>

            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Enforce Pinch-to-Zoom</div>
                    <div class="toggle-sub">Override web app viewport limits to allow zooming on all pages</div>
                </div>
                <label class="switch">
                    <input type="checkbox" id="chk-forcezoom">
                    <span class="switch-slider"></span>
                </label>
            </div>

            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Enable Shake to Refresh</div>
                    <div class="toggle-sub">Shake device to display 3-second quick page reload prompt</div>
                </div>
                <label class="switch">
                    <input type="checkbox" id="chk-shake">
                    <span class="switch-slider"></span>
                </label>
            </div>
        </div>

        <!-- Section 3: Session Resume -->
        <div class="section-card">
            <div class="section-title">🔄 Session Resume Controls</div>
            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Resume Last Visited Page</div>
                    <div class="toggle-sub">Reopen exact URL where you left off</div>
                </div>
                <label class="switch">
                    <input type="checkbox" id="chk-resume" onchange="updateResumeSubState()">
                    <span class="switch-slider"></span>
                </label>
            </div>
            <div class="toggle-row" id="row-confirm">
                <div>
                    <div class="toggle-label">Confirm Before Resuming</div>
                    <div class="toggle-sub">Ask confirmation dialog on app launch</div>
                </div>
                <label class="switch">
                    <input type="checkbox" id="chk-confirm">
                    <span class="switch-slider"></span>
                </label>
            </div>
        </div>

        <!-- Section 4: Data & Cache Management -->
        <div class="section-card">
            <div class="section-title">🧹 Browser Cache & Storage</div>
            <p class="desc" style="margin-bottom: 12px; font-size: 12px;">Purge temporary files, offline database storage, or session cookies if experiencing stuck layouts or outdated code.</p>
            
            <div class="btn-grid">
                <button type="button" class="action-btn" onclick="clearCacheOnly()">Clear Cache</button>
                <button type="button" class="action-btn" onclick="clearWebStorageOnly()">Clear Storage</button>
                <button type="button" class="action-btn" onclick="clearCookiesOnly()">Clear Cookies</button>
            </div>

            <button type="button" class="btn-purge-master" onclick="confirmPurgeAll()">
                <span>🔥</span> Purge All Site Data & Cache
            </button>
        </div>
    </div>

    <!-- Fixed Footer Bar -->
    <div class="footer-bar">
        <button type="button" class="btn-footer-secondary" onclick="cancelSettings()">Cancel</button>
        <button type="button" class="btn-footer-primary" onclick="saveSettings()">Save & Launch App</button>
    </div>

    <script>
        let selectedTheme = 'system';

        function showToast(msg) {
            const toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.classList.add('active');
            setTimeout(() => toast.classList.remove('active'), 2500);
        }

        function selectTheme(theme) {
            selectedTheme = theme;
            document.querySelectorAll('.theme-card').forEach(card => {
                card.classList.toggle('active', card.getAttribute('data-theme') === theme);
            });
            if (window.Android && window.Android.vibrate) {
                window.Android.vibrate(15);
            }
        }

        function updateResumeSubState() {
            const chkResume = document.getElementById('chk-resume');
            const chkConfirm = document.getElementById('chk-confirm');
            const rowConfirm = document.getElementById('row-confirm');
            if (chkResume && rowConfirm && chkConfirm) {
                const isChecked = chkResume.checked;
                chkConfirm.disabled = !isChecked;
                rowConfirm.style.opacity = isChecked ? '1' : '0.4';
            }
        }

        function loadSettings() {
            if (!window.Android) return;

            let rawJson = "";
            if (typeof window.Android.getWrapperSettingsJson === 'function') {
                rawJson = window.Android.getWrapperSettingsJson();
            } else if (typeof window.Android.getWrapperSettings === 'function') {
                rawJson = window.Android.getWrapperSettings();
            }

            if (!rawJson) return;

            try {
                const data = typeof rawJson === 'string' ? JSON.parse(rawJson) : rawJson;
                if (data) {
                    if (data.theme) selectTheme(data.theme);
                    if (data.custom_url) document.getElementById('inp-url').value = data.custom_url;
                    if (data.resume_last_url !== undefined) document.getElementById('chk-resume').checked = !!data.resume_last_url;
                    if (data.confirm_resume !== undefined) document.getElementById('chk-confirm').checked = !!data.confirm_resume;
                    if (data.link_mode) document.getElementById('sel-link-mode').value = data.link_mode;
                    if (data.status_bar_mode) document.getElementById('sel-statusbar-mode').value = data.status_bar_mode;
                    if (data.multi_tab_mode !== undefined) document.getElementById('chk-multitab').checked = !!data.multi_tab_mode;
                    if (data.force_zoom !== undefined) document.getElementById('chk-forcezoom').checked = !!data.force_zoom;
                    if (data.shake_to_refresh !== undefined) document.getElementById('chk-shake').checked = !!data.shake_to_refresh;
                    updateResumeSubState();
                }
            } catch (e) {
                console.error("Failed to parse settings JSON", e);
            }
        }

        function clearCacheOnly() {
            if (window.Android && typeof window.Android.clearCacheOnly === 'function') {
                window.Android.clearCacheOnly();
                showToast("HTTP Cache Cleared");
            }
        }

        function clearWebStorageOnly() {
            if (window.Android && typeof window.Android.clearWebStorageOnly === 'function') {
                window.Android.clearWebStorageOnly();
                showToast("LocalStorage & WebSQL Cleared");
            }
        }

        function clearCookiesOnly() {
            if (window.Android && typeof window.Android.clearCookiesOnly === 'function') {
                window.Android.clearCookiesOnly();
                showToast("Session Cookies Cleared");
            }
        }

        function confirmPurgeAll() {
            if (confirm("Purge ALL site data, local storage, session cookies, and browser cache?")) {
                if (window.Android && typeof window.Android.clearAllSiteData === 'function') {
                    window.Android.clearAllSiteData();
                    showToast("✨ All Site Data & Cache Cleared!");
                }
            }
        }

        function cancelSettings() {
            if (window.Android) {
                if (typeof window.Android.closeChildOverlay === 'function') {
                    window.Android.closeChildOverlay();
                } else if (typeof window.Android.openConjureOsWrapper === 'function') {
                    window.Android.openConjureOsWrapper();
                } else if (typeof window.Android.loadTargetUrl === 'function') {
                    const urlInp = document.getElementById('inp-url');
                    const targetUrl = urlInp ? urlInp.value.trim() : '';
                    window.Android.loadTargetUrl(targetUrl);
                }
            }
        }

        function saveSettings() {
            const btn = document.querySelector('.btn-footer-primary');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Launching...';
            }
            const urlInp = document.getElementById('inp-url');
            const targetUrl = urlInp ? urlInp.value.trim() : '';
            const chkResume = document.getElementById('chk-resume').checked;
            const chkConfirm = document.getElementById('chk-confirm').checked;
            const linkMode = document.getElementById('sel-link-mode').value;
            const statusBarMode = document.getElementById('sel-statusbar-mode').value;
            const chkMultiTab = document.getElementById('chk-multitab').checked;
            const chkForceZoom = document.getElementById('chk-forcezoom').checked;
            const chkShake = document.getElementById('chk-shake').checked;

            if (window.Android && typeof window.Android.saveWrapperSettings === 'function') {
                window.Android.saveWrapperSettings(selectedTheme, targetUrl, chkResume, chkConfirm, linkMode, statusBarMode, chkMultiTab, chkForceZoom, chkShake);
            } else {
                cancelSettings();
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadSettings();
        });
    </script>
</body>
</html>