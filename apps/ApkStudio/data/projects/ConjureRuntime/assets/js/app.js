const Runtime = {
    statusTitle: null,
    statusMessage: null,
    statusBox: null,
    downloadInProgress: false,
    runtimeActive: false,
    tsActive: false,
    tsPoller: null,
    tsConsoleVisible: false,

    init() {
        this.statusTitle = document.getElementById('status-title');
        this.statusMessage = document.getElementById('status-message');
        this.statusBox = document.getElementById('status-box');

        if (window.Android && typeof window.Android.getRuntimeBundleInfo === 'function') {
            try {
                const rawBundleInfo = window.Android.getRuntimeBundleInfo();
                const bundleInfo = typeof rawBundleInfo === 'string'
                    ? JSON.parse(rawBundleInfo)
                    : rawBundleInfo;

                const runtimeMessage = document.getElementById('runtime-status-message');
                if (runtimeMessage && bundleInfo && bundleInfo.abi) {
                    runtimeMessage.textContent = `Runtime bundle target: ${bundleInfo.abi}. Required executables: ${bundleInfo.required.join(', ')}.`;
                }
            } catch (error) {
                console.error('Failed to restore runtime bundle information', error);
            }
        }

        if (window.Android && typeof window.Android.getCustomPorts === 'function') {
            try {
                const rawPorts = window.Android.getCustomPorts();
                const ports = typeof rawPorts === 'string' ? JSON.parse(rawPorts) : rawPorts;
                if (ports) {
                    const httpsInp = document.getElementById('inp-https-port');
                    const httpInp = document.getElementById('inp-http-port');
                    if (httpsInp) httpsInp.value = ports.https_port || 8000;
                    if (httpInp) httpInp.value = ports.http_port || 8001;
                }
            } catch (error) {
                console.error('Failed to restore custom ports', error);
            }
        }

        if (window.Android && typeof window.Android.getInstallStatus === 'function') {
            try {
                const rawStatus = window.Android.getInstallStatus();
                const status = typeof rawStatus === 'string' ? JSON.parse(rawStatus) : rawStatus;

                if (status && status.title && status.message) {
                    if (status.type === 'importing' || status.type === 'preparing') {
                        this.handleInstallProgress(status.title, status.message, status.type, 15);
                    } else {
                        this.showStatus(status.title, status.message, status.type || '');
                    }
                }
            } catch (error) {
                console.error('Failed to restore native installation status', error);
            }
        }

        const pkgDetails = document.getElementById('pkg-panel-details');
        const pkgBadge = document.getElementById('pkg-panel-badge');
        if (pkgDetails) {
            pkgDetails.addEventListener('toggle', () => {
                if (pkgBadge) {
                    pkgBadge.textContent = pkgDetails.open ? 'Tap to Collapse \u2191' : 'Tap to Expand \u2193';
                }
            });
        }

        this.restoreTailscaleApiKey();
        this.restoreAutoStartSettings();
        this.restoreOpenConjureOsByDefault();
        this.restoreInterceptBackButton();
        this.loadSystemPaths();
        setTimeout(() => this.restoreTailscaleApiKey(), 200);
        setTimeout(() => this.restoreTailscaleApiKey(), 600);
        this.checkStoragePermission();
        this.checkBatteryOptimization();
        this.startStatusPoller();
    },

    loadSystemPaths() {
        if (!window.Android || typeof window.Android.getSystemPaths !== 'function') return;
        try {
            const raw = window.Android.getSystemPaths();
            const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
            if (data) {
                this.renderSystemPathsUI(data);
            }
        } catch (e) {
            console.error('Failed to load system paths', e);
        }
    },

    renderSystemPathsUI(data) {
        const listContainer = document.getElementById('system-paths-list');
        const activePathLbl = document.getElementById('lbl-active-runtime-path');

        if (!data || !data.paths) return;

        if (activePathLbl) {
            activePathLbl.textContent = data.active_path || '/storage/emulated/0/Conjure OS/';
        }

        if (!listContainer) return;

        let html = '';
        data.paths.forEach(item => {
            const isActive = !!item.is_active;
            const isDefault = !!item.is_default;
            const displayPath = item.display_path || item.path;
            const label = item.label || (isDefault ? 'Default Conjure OS' : 'Custom Path');
            const safePath = item.path.replace(/\\/g, '\\\\').replace(/'/g, "\\'");

            html += `
                <div class="path-item-card" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; background: ${isActive ? 'rgba(124, 108, 255, 0.12)' : 'rgba(0,0,0,0.25)'}; border: 1px solid ${isActive ? 'var(--primary-accent)' : 'var(--border-color)'}; border-radius: 10px; transition: all 0.2s;">
                    <div style="display: flex; align-items: center; gap: 10px; flex: 1; overflow: hidden; margin-right: 8px;">
                        <label class="switch" style="flex-shrink: 0;" title="Toggle to activate this system path">
                            <input type="checkbox" ${isActive ? 'checked' : ''} onchange="Runtime.setActivePath('${safePath}')">
                            <span class="switch-slider"></span>
                        </label>
                        <div style="overflow: hidden;">
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <span style="font-size: 12px; font-weight: 700; color: ${isActive ? 'var(--primary-accent)' : 'var(--text-primary)'};">${label}</span>
                                ${isDefault ? '<span style="font-size: 9px; font-weight: 800; background: rgba(52, 211, 153, 0.2); color: #34d399; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">DEFAULT</span>' : '<span style="font-size: 9px; font-weight: 800; background: rgba(96, 165, 250, 0.2); color: #60a5fa; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">CUSTOM</span>'}
                                ${isActive ? '<span style="font-size: 9px; font-weight: 800; background: var(--primary-accent); color: #fff; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">ACTIVE</span>' : ''}
                            </div>
                            <code style="display: block; font-family: var(--font-mono); font-size: 11px; color: var(--text-secondary); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${displayPath}</code>
                        </div>
                    </div>
                    ${!isDefault ? `
                        <button class="secondary-action btn-sm btn-danger-action" type="button" onclick="Runtime.removePath('${safePath}')" title="Remove Path" style="padding: 4px 8px; font-size: 11px; flex-shrink: 0; width: auto;">✕</button>
                    ` : ''}
                </div>
            `;
        });

        listContainer.innerHTML = html;
    },

    setActivePath(path) {
        if (!window.Android || typeof window.Android.setActiveSystemPath !== 'function') return;
        window.Android.setActiveSystemPath(path);
        this.loadSystemPaths();
        if (this.runtimeActive) {
            this.showStatus('Active Path Changed', 'Restart runtime to serve PHP & Nginx from the newly selected folder.', '');
        }
    },

    removePath(path) {
        if (!window.Android || typeof window.Android.removeSystemPath !== 'function') return;
        if (confirm('Remove this folder path from your options list?')) {
            window.Android.removeSystemPath(path);
            this.loadSystemPaths();
        }
    },

    openFolderPicker() {
        if (window.Android && typeof window.Android.openFolderPicker === 'function') {
            window.Android.openFolderPicker();
        } else {
            this.showStatus('Native bridge unavailable', 'Folder picker requires app update.', 'error');
        }
    },

    statusPoller: null,

    startStatusPoller() {
        if (this.statusPoller) clearInterval(this.statusPoller);
        this.pollRuntimeStatus();
        this.pollTailscaleStatus();
        this.pollMdnsStatus();
        this.statusPoller = setInterval(() => {
            this.pollRuntimeStatus();
            this.pollTailscaleStatus();
            this.pollMdnsStatus();
        }, 1500);
    },

    pollMdnsStatus() {
        if (!window.Android || typeof window.Android.getMdnsStatus !== 'function') return;
        try {
            const raw = window.Android.getMdnsStatus();
            const mdns = typeof raw === 'string' ? JSON.parse(raw) : raw;
            if (mdns) {
                this.updateMdnsUI(mdns);
            }
        } catch (e) {
            console.error('Failed to poll mDNS status', e);
        }
    },

    updateMdnsUI(mdns) {
        const badge = document.getElementById('mdns-status-badge');
        const title = document.getElementById('mdns-status-title');
        const msg = document.getElementById('mdns-status-message');
        const count = document.getElementById('mdns-query-count');

        const status = (mdns.status || 'STOPPED').toUpperCase();

        if (badge) {
            if (status === 'RUNNING') {
                badge.className = 'status-badge running';
                badge.textContent = 'mDNS ACTIVE';
            } else if (status === 'STARTING') {
                badge.className = 'status-badge starting';
                badge.textContent = 'STARTING';
            } else if (status === 'NO_WIFI') {
                badge.className = 'status-badge starting';
                badge.textContent = 'NO WI-FI';
            } else if (status === 'ERROR') {
                badge.className = 'status-badge error';
                badge.textContent = 'ERROR';
            } else {
                badge.className = 'status-badge offline';
                badge.textContent = 'OFFLINE';
            }
        }

        if (title) {
            if (status === 'RUNNING') {
                title.textContent = `conjure.local (${mdns.active_ip || 'Active'})`;
            } else {
                title.textContent = 'conjure.local';
            }
        }

        if (msg) {
            msg.textContent = mdns.message || 'Start runtime to activate mDNS.';
        }

        if (count) {
            count.textContent = `${mdns.queries_handled || 0} queries`;
        }
    },

    pollRuntimeStatus() {
        if (!window.Android || typeof window.Android.getRuntimeStatus !== 'function') return;
        try {
            const raw = window.Android.getRuntimeStatus();
            const runtimeStatus = typeof raw === 'string' ? JSON.parse(raw) : raw;
            if (runtimeStatus) {
                this.updateRuntimeStatus(
                    runtimeStatus.status || 'STOPPED',
                    runtimeStatus.message || ''
                );
            }
        } catch (e) {
            console.error('Failed to poll runtime status', e);
        }
    },

    restartApp() {
        this.stopTailscalePoller();
        if (window.Android && typeof window.Android.restartApp === 'function') {
            window.Android.restartApp();
        } else if (window.Android && typeof window.Android.restartServices === 'function') {
            window.Android.restartServices();
            setTimeout(() => this.startStatusPoller(), 2000);
        }
    },

    restartServices() {
        this.stopTailscalePoller();
        if (window.Android && typeof window.Android.restartServices === 'function') {
            window.Android.restartServices();
            setTimeout(() => this.startStatusPoller(), 2000);
        }
    },

    restoreAutoStartSettings() {
        if (!window.Android || typeof window.Android.getAutoStartSettings !== 'function') return;
        try {
            const raw = window.Android.getAutoStartSettings();
            const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
            const chkLaunch = document.getElementById('chk-auto-launch');
            const chkBoot = document.getElementById('chk-auto-boot');
            const chkTailscale = document.getElementById('chk-auto-tailscale');
            if (chkLaunch) chkLaunch.checked = !!(data && data.auto_start_launch);
            if (chkBoot) chkBoot.checked = !!(data && data.auto_start_boot);
            if (chkTailscale) chkTailscale.checked = !!(data && data.auto_start_tailscale);
        } catch (e) {
            console.error('Failed to restore auto-start settings', e);
        }
    },

    saveAutoStartSettings() {
        if (!window.Android || typeof window.Android.setAutoStartSettings !== 'function') return;
        const chkLaunch = document.getElementById('chk-auto-launch');
        const chkBoot = document.getElementById('chk-auto-boot');
        const chkTailscale = document.getElementById('chk-auto-tailscale');
        const autoLaunch = chkLaunch ? chkLaunch.checked : false;
        const autoBoot = chkBoot ? chkBoot.checked : false;
        const autoTailscale = chkTailscale ? chkTailscale.checked : false;
        window.Android.setAutoStartSettings(autoLaunch, autoBoot, autoTailscale);
    },

    restoreOpenConjureOsByDefault() {
        if (!window.Android || typeof window.Android.getOpenConjureOsByDefault !== 'function') return;
        try {
            const enabled = window.Android.getOpenConjureOsByDefault();
            const chk = document.getElementById('chk-open-conjure-default');
            if (chk) chk.checked = !!enabled;
        } catch (e) {
            console.error('Failed to restore open Conjure OS by default setting', e);
        }
    },

    saveOpenConjureOsByDefault() {
        if (!window.Android || typeof window.Android.setOpenConjureOsByDefault !== 'function') return;
        const chk = document.getElementById('chk-open-conjure-default');
        const enabled = chk ? chk.checked : false;
        window.Android.setOpenConjureOsByDefault(enabled);
    },

    restoreInterceptBackButton() {
        if (!window.Android || typeof window.Android.getInterceptBackButton !== 'function') return;
        try {
            const enabled = window.Android.getInterceptBackButton();
            const chk = document.getElementById('chk-intercept-back');
            if (chk) chk.checked = !!enabled;
        } catch (e) {
            console.error('Failed to restore intercept back button setting', e);
        }
    },

    saveInterceptBackButton() {
        if (!window.Android || typeof window.Android.setInterceptBackButton !== 'function') return;
        const chk = document.getElementById('chk-intercept-back');
        const enabled = chk ? chk.checked : false;
        window.Android.setInterceptBackButton(enabled);
    },

    openBrowserSettings() {
        if (window.Android && typeof window.Android.openWrapperSettings === 'function') {
            window.Android.openWrapperSettings();
        } else {
            this.showStatus('Native bridge unavailable', 'Browser settings dialog requires app update.', 'error');
        }
    },

    openConjureOsWrapper() {
        if (window.Android && typeof window.Android.openConjureOsWrapper === 'function') {
            window.Android.openConjureOsWrapper();
        } else {
            this.openConjureOS();
        }
    },

    checkBatteryOptimization() {
        if (!window.Android || typeof window.Android.isBatteryOptimizationIgnored !== 'function') return;
        try {
            const isIgnored = window.Android.isBatteryOptimizationIgnored();
            const banner = document.getElementById('battery-opt-banner');
            if (banner) {
                banner.style.display = isIgnored ? 'none' : 'block';
            }
        } catch (e) {
            console.error('Failed to check battery optimization state', e);
        }
    },

    requestIgnoreBatteryOptimizations() {
        if (window.Android && typeof window.Android.requestIgnoreBatteryOptimizations === 'function') {
            window.Android.requestIgnoreBatteryOptimizations();
        }
    },

    checkStoragePermission() {
        if (!window.Android || typeof window.Android.hasStoragePermission !== 'function') return;
        try {
            const hasPerm = window.Android.hasStoragePermission();
            const banner = document.getElementById('storage-perm-banner');
            if (banner) {
                banner.style.display = hasPerm ? 'none' : 'block';
            }
        } catch (e) {
            console.error('Failed to check storage permission', e);
        }
    },

    requestStoragePermission() {
        if (window.Android && typeof window.Android.requestStoragePermission === 'function') {
            window.Android.requestStoragePermission();
        }
    },

    hasExistingPackage() {
        if (window.Android && typeof window.Android.hasExistingDefaultPackage === 'function') {
            return window.Android.hasExistingDefaultPackage();
        }
        if (!window.Android || typeof window.Android.getInstallStatus !== 'function') return false;
        try {
            const raw = window.Android.getInstallStatus();
            const status = typeof raw === 'string' ? JSON.parse(raw) : raw;
            return status && status.type === 'success';
        } catch (e) {
            return false;
        }
    },

    checkPackageFolderState(isSuccess) {
        const details = document.getElementById('pkg-panel-details');
        const badge = document.getElementById('pkg-panel-badge');
        if (!details) return;

        if (isSuccess) {
            details.removeAttribute('open');
            if (badge) badge.textContent = 'Tap to Expand \u2193';
        } else {
            details.setAttribute('open', 'open');
            if (badge) badge.textContent = 'Tap to Collapse \u2191';
        }
    },

    pendingOverwriteAction: null,

    openOverwriteModal(action) {
        this.pendingOverwriteAction = action;
        const modal = document.getElementById('overwrite-confirm-modal');
        const input = document.getElementById('inp-overwrite-path');
        const btn = document.getElementById('btn-confirm-overwrite');
        const status = document.getElementById('overwrite-path-status');

        if (input) input.value = '';
        if (btn) {
            btn.disabled = true;
            btn.style.opacity = '0.4';
            btn.style.cursor = 'not-allowed';
        }
        if (status) {
            status.textContent = 'Awaiting full path entry...';
            status.style.color = 'var(--text-secondary)';
        }

        if (modal) {
            modal.style.display = 'flex';
            setTimeout(() => { if (input) input.focus(); }, 150);
        }
    },

    closeOverwriteModal() {
        const modal = document.getElementById('overwrite-confirm-modal');
        const input = document.getElementById('inp-overwrite-path');
        if (modal) modal.style.display = 'none';
        if (input) input.value = '';
        this.pendingOverwriteAction = null;
    },

    isPathMatch(val) {
        if (!val) return false;
        const clean = val.trim().replace(/\\/g, '/');
        return clean === '/sdcard/Conjure OS/' || clean === '/storage/emulated/0/Conjure OS/';
    },

    isPartialPathMatch(val) {
        if (!val) return true;
        const clean = val.trim().replace(/\\/g, '/');
        const targets = [
            '/sdcard/Conjure OS/',
            '/storage/emulated/0/Conjure OS/'
        ];
        return targets.some(target => target.startsWith(clean));
    },

    onOverwritePathInput() {
        const input = document.getElementById('inp-overwrite-path');
        const btn = document.getElementById('btn-confirm-overwrite');
        const status = document.getElementById('overwrite-path-status');
        const val = input ? input.value : '';

        if (this.isPathMatch(val)) {
            if (btn) {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
            }
            if (status) {
                status.textContent = '✓ Path verified! Ready to delete & overwrite.';
                status.style.color = 'var(--success)';
            }
        } else if (!val || val.trim().length === 0) {
            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.4';
                btn.style.cursor = 'not-allowed';
            }
            if (status) {
                status.textContent = 'Awaiting full path entry...';
                status.style.color = 'var(--text-secondary)';
            }
        } else if (this.isPartialPathMatch(val)) {
            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.4';
                btn.style.cursor = 'not-allowed';
            }
            if (status) {
                status.textContent = 'Typing path... (must match target directory)';
                status.style.color = '#60a5fa';
            }
        } else {
            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.4';
                btn.style.cursor = 'not-allowed';
            }
            if (status) {
                status.textContent = '⚠️ Path does not match target directory.';
                status.style.color = '#f87171';
            }
        }
    },

    confirmOverwrite() {
        const input = document.getElementById('inp-overwrite-path');
        const val = input ? input.value : '';

        if (!this.isPathMatch(val)) return;

        const action = this.pendingOverwriteAction;
        this.closeOverwriteModal();

        if (action === 'select') {
            this.showStatus('Opening file picker', 'Choose the Conjure OS ZIP package from device storage.', '');
            window.Android.selectConjureZip();
        } else if (action === 'download') {
            this.executeDownloadPackage();
        }
    },

    selectPackage() {
        if (!window.Android || typeof window.Android.selectConjureZip !== 'function') {
            this.showStatus('Native bridge unavailable', 'This screen must run inside the Conjure Runtime APK.', 'error');
            return;
        }

        if (this.hasExistingPackage()) {
            this.openOverwriteModal('select');
            return;
        }

        this.showStatus('Opening file picker', 'Choose the Conjure OS ZIP package from device storage.', '');
        window.Android.selectConjureZip();
    },

    downloadPackage() {
        if (this.downloadInProgress) {
            return;
        }

        const input = document.getElementById('package-url');
        const url = input ? input.value.trim() : '';

        if (!/^https?:\/\/.+/i.test(url)) {
            this.showStatus('Invalid package URL', 'Enter a complete HTTP or HTTPS ZIP URL.', 'error');
            return;
        }

        if (!window.Android || typeof window.Android.downloadConjureZip !== 'function') {
            this.showStatus('Native bridge unavailable', 'This screen must run inside the Conjure Runtime APK.', 'error');
            return;
        }

        if (this.hasExistingPackage()) {
            this.openOverwriteModal('download');
            return;
        }

        this.executeDownloadPackage();
    },

    executeDownloadPackage() {
        const input = document.getElementById('package-url');
        const url = input ? input.value.trim() : '';

        this.downloadInProgress = true;
        const button = document.querySelector('.url-form .secondary-action');
        if (button) {
            button.disabled = true;
            button.textContent = 'Downloading...';
        }

        this.showStatus('Downloading package', 'The ZIP will be downloaded into private staging storage before validation.', '');
        window.Android.downloadConjureZip(url);
    },

    toggleRuntime() {
        if (this.runtimeActive) {
            this.stopRuntime();
        } else {
            this.startRuntime();
        }
    },

    updateRuntimeStatus(status, message) {
        const titleEl = document.getElementById('status-title');
        const messageEl = document.getElementById('status-message');
        const badge = document.getElementById('status-badge');
        const card = document.getElementById('status-card');
        const toggleBtn = document.getElementById('btn-toggle-runtime');

        const cleanStatus = (status || '').toUpperCase();
        const isRunning = (cleanStatus === 'RUNNING');
        const isStarting = (cleanStatus === 'STARTING');
        const isError = (cleanStatus === 'ERROR' || cleanStatus === 'BUNDLES_REQUIRED');

        this.runtimeActive = isRunning;

        if (badge) {
            if (isRunning) {
                badge.className = 'status-badge running';
                badge.textContent = 'ONLINE';
            } else if (isStarting) {
                badge.className = 'status-badge starting';
                badge.textContent = 'STARTING';
            } else if (isError) {
                badge.className = 'status-badge error';
                badge.textContent = 'ERROR';
            } else {
                badge.className = 'status-badge offline';
                badge.textContent = 'OFFLINE';
            }
        }

        if (card) {
            if (isRunning) card.className = 'status-card running';
            else if (isError) card.className = 'status-card error';
            else card.className = 'status-card';
        }

        if (titleEl) {
            if (isRunning) titleEl.textContent = 'Conjure OS Online';
            else if (isStarting) titleEl.textContent = 'Preparing Services...';
            else if (isError) titleEl.textContent = 'Runtime Error';
            else titleEl.textContent = 'Runtime Offline';
        }

        if (messageEl) {
            messageEl.textContent = message || (isRunning ? 'Local PHP & Nginx services active.' : 'Tap Start Runtime to launch local services.');
        }

        if (toggleBtn) {
            if (isRunning) {
                toggleBtn.textContent = 'Stop Runtime';
                toggleBtn.className = 'primary-action btn-danger-toggle';
                toggleBtn.disabled = false;
            } else if (isStarting) {
                toggleBtn.textContent = 'Starting...';
                toggleBtn.className = 'primary-action';
                toggleBtn.disabled = true;
            } else {
                toggleBtn.textContent = 'Start Runtime';
                toggleBtn.className = 'primary-action';
                toggleBtn.disabled = false;
            }
        }

        if (isRunning) {
            this.renderActiveLinks();
        } else {
            const box = document.getElementById('active-links-box');
            if (box) box.style.display = 'none';
        }
    },

    startRuntime() {
        this.updateRuntimeStatus('STARTING', 'Preparing PHP, Nginx, and SSL certificates...');
        if (window.Android && typeof window.Android.startRuntime === 'function') {
            window.Android.startRuntime();
        }
    },

    stopRuntime() {
        this.stopTailscalePoller();
        if (window.Android && typeof window.Android.stopRuntime === 'function') {
            window.Android.stopRuntime();
        }
    },

    copyLogs() {
        if (window.Android && typeof window.Android.copyLogs === 'function') {
            window.Android.copyLogs();
        }
    },

    clearLogs() {
        if (window.Android && typeof window.Android.clearLogs === 'function') {
            window.Android.clearLogs();
        }
    },

    downloadRootCa() {
        if (window.Android && typeof window.Android.downloadRootCa === 'function') {
            window.Android.downloadRootCa();
        } else {
            this.showStatus('Native bridge unavailable', 'Root CA download is only available inside the app.', 'error');
        }
    },

    installRootCa() {
        this.downloadRootCa();
        const modal = document.getElementById('ca-modal');
        if (modal) modal.style.display = 'flex';
    },

    closeCaModal() {
        const modal = document.getElementById('ca-modal');
        if (modal) modal.style.display = 'none';
    },

    proceedToSettings() {
        this.closeCaModal();
        if (window.Android && typeof window.Android.openMainSettings === 'function') {
            window.Android.openMainSettings();
        }
    },

    savePorts() {
        const httpsInp = document.getElementById('inp-https-port');
        const httpInp = document.getElementById('inp-http-port');
        const httpsPort = httpsInp ? parseInt(httpsInp.value) : 8000;
        const httpPort = httpInp ? parseInt(httpInp.value) : 8001;

        if (isNaN(httpsPort) || httpsPort < 1024 || httpsPort > 65535) {
            this.showStatus('Invalid HTTPS Port', 'Port must be between 1024 and 65535.', 'error');
            return;
        }
        if (isNaN(httpPort) || httpPort < 1024 || httpPort > 65535) {
            this.showStatus('Invalid HTTP Port', 'Port must be between 1024 and 65535.', 'error');
            return;
        }

        if (window.Android && typeof window.Android.setCustomPorts === 'function') {
            window.Android.setCustomPorts(httpsPort, httpPort);
        }
        this.renderActiveLinks();
        this.pollTailscaleStatus();
    },

    getActivePorts() {
        const httpsInp = document.getElementById('inp-https-port');
        const httpInp = document.getElementById('inp-http-port');
        const httpsPort = httpsInp && !isNaN(parseInt(httpsInp.value)) ? parseInt(httpsInp.value) : 8000;
        const httpPort = httpInp && !isNaN(parseInt(httpInp.value)) ? parseInt(httpInp.value) : 8001;
        return { httpsPort, httpPort };
    },

    renderActiveLinks() {
        if (!window.Android || typeof window.Android.getActiveNetworkIps !== 'function') return;
        try {
            const rawIps = window.Android.getActiveNetworkIps();
            const ips = typeof rawIps === 'string' ? JSON.parse(rawIps) : rawIps;
            const httpsInp = document.getElementById('inp-https-port');
            const httpInp = document.getElementById('inp-http-port');
            const httpsPort = httpsInp ? httpsInp.value : 8000;
            const httpPort = httpInp ? httpInp.value : 8001;

            const box = document.getElementById('active-links-box');
            const list = document.getElementById('active-links-list');

            if (box && list && ips && ips.length > 0) {
                let html = `
                    <div class="active-link-row" style="background: rgba(124, 108, 255, 0.12); border-radius: 6px; padding: 6px 8px; margin-bottom: 6px; border: 1px solid rgba(124, 108, 255, 0.25);">
                        <span style="color: var(--primary-accent); font-weight: bold;">conjure.local <small style="font-weight: normal; opacity: 0.8;">(Other Wi-Fi Devices)</small></span>
                        <span>
                            <a href="https://conjure.local:${httpsPort}/" target="_blank" style="color: var(--primary-accent); font-weight: bold;">HTTPS (${httpsPort})</a> | 
                            <a href="http://conjure.local:${httpPort}/" target="_blank">HTTP (${httpPort})</a>
                        </span>
                    </div>
                `;
                html += ips.map(ip => `
                    <div class="active-link-row">
                        <span>${ip}</span>
                        <span>
                            <a href="https://${ip}:${httpsPort}/" target="_blank">HTTPS (${httpsPort})</a> | 
                            <a href="http://${ip}:${httpPort}/" target="_blank">HTTP (${httpPort})</a>
                        </span>
                    </div>
                `).join('');
                list.innerHTML = html;
                box.style.display = 'block';
            }
        } catch (e) {
            console.error('Failed to render active links', e);
        }
    },

    openConjureOS() {
        if (window.Android && typeof window.Android.openConjureOS === 'function') {
            window.Android.openConjureOS();
        }
    },

    openExternalUrl(url) {
        if (window.Android && typeof window.Android.openExternalUrl === 'function') {
            window.Android.openExternalUrl(url);
        } else {
            window.open(url, '_blank');
        }
    },

    saveTailscaleApiKey() {
        const inp = document.getElementById('inp-ts-apikey');
        const key = inp ? inp.value.trim() : '';

        if (!key) {
            this.showStatus('Invalid API Key', 'Enter a valid Tailscale API key or Auth key.', 'error');
            return;
        }

        if (window.Android && typeof window.Android.saveTailscaleApiKey === 'function') {
            try {
                window.Android.saveTailscaleApiKey(key, "");
            } catch (e) {
                window.Android.saveTailscaleApiKey(key);
            }
            setTimeout(() => this.restoreTailscaleApiKey(), 300);
        }
    },

    toggleKeyVisibility() {
        const inp = document.getElementById('inp-ts-apikey');
        const btn = document.getElementById('btn-toggle-key-visibility');
        if (!inp || !btn) return;

        if (inp.type === 'password') {
            inp.type = 'text';
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.52 13.16 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>`;
            btn.style.color = 'var(--primary-accent)';
        } else {
            inp.type = 'password';
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>`;
            btn.style.color = 'var(--text-secondary)';
        }
    },

    restoreTailscaleApiKey() {
        if (!window.Android || typeof window.Android.getTailscaleApiKey !== 'function') return false;
        try {
            const raw = window.Android.getTailscaleApiKey();
            const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
            const statusEl = document.getElementById('ts-key-status');
            const inp = document.getElementById('inp-ts-apikey');

            if (data && data.api_key && data.api_key.trim() !== '') {
                if (inp && (!inp.value || inp.value !== data.api_key)) {
                    inp.value = data.api_key;
                }
                if (statusEl) {
                    const daysOld = data.timestamp ? Math.floor((Date.now() - data.timestamp) / (1000 * 60 * 60 * 24)) : 0;
                    if (daysOld > 75) {
                        statusEl.innerHTML = `<span style="color: #fbbf24;">⚠️ Key saved ${daysOld} days ago. Refresh before expiry.</span>`;
                    } else {
                        statusEl.innerHTML = `<span style="color: var(--success);">✓ Key active (Saved ${daysOld}d ago). Enables auto domain reclaiming.</span>`;
                    }
                }
                return true;
            }
        } catch (e) {
            console.error('Failed to restore Tailscale API key', e);
        }
        return false;
    },

    hasAutoOpenedAuth: false,

    toggleTailscale() {
        if (this.tsActive) {
            this.stopTailscale();
        } else {
            this.startTailscale();
        }
    },

    startTailscale() {
        if (!window.Android || typeof window.Android.startTailscale !== 'function') {
            this.showStatus('Native bridge unavailable', 'Tailscale feature requires app update.', 'error');
            return;
        }
        window.Android.startTailscale();
        this.startTailscalePoller();
    },

    stopTailscale() {
        if (window.Android && typeof window.Android.stopTailscale === 'function') {
            window.Android.stopTailscale();
        }
        this.stopTailscalePoller();
        this.updateTailscaleUI({ status: 'STOPPED', auth_url: '', ip: '', magic_dns: '' });
    },

    startTailscalePoller() {
        if (this.tsPoller) clearInterval(this.tsPoller);
        this.pollTailscaleStatus();
        this.tsPoller = setInterval(() => this.pollTailscaleStatus(), 1500);
    },

    stopTailscalePoller() {
        if (this.tsPoller) {
            clearInterval(this.tsPoller);
            this.tsPoller = null;
        }
    },

    pollTailscaleStatus() {
        if (!window.Android || typeof window.Android.getTailscaleStatus !== 'function') return;
        try {
            const raw = window.Android.getTailscaleStatus();
            const tsData = typeof raw === 'string' ? JSON.parse(raw) : raw;
            if (tsData) {
                this.updateTailscaleUI(tsData);
            }
        } catch (e) {
            console.error('Failed to poll Tailscale status', e);
        }
    },

    updateTailscaleUI(tsData) {
        const badge = document.getElementById('ts-status-badge');
        const card = document.getElementById('ts-status-card');
        const title = document.getElementById('ts-status-title');
        const message = document.getElementById('ts-status-message');
        const toggleBtn = document.getElementById('btn-toggle-tailscale');
        const logoutBtn = document.getElementById('btn-logout-tailscale');
        const authBox = document.getElementById('ts-auth-box');
        const linksBox = document.getElementById('ts-links-box');
        const ipVal = document.getElementById('ts-ip-val');
        const dnsVal = document.getElementById('ts-dns-val');

        const status = ((tsData && tsData.status) || 'STOPPED').toUpperCase();
        this.tsActive = (status === 'CONNECTED' || status === 'NEEDS_AUTH' || status === 'STARTING');

        const hasSavedKey = this.restoreTailscaleApiKey();
        const hasState = this.tsActive || hasSavedKey;

        if (logoutBtn) {
            logoutBtn.style.display = hasState ? 'inline-block' : 'none';
        }

        if (badge) {
            if (status === 'CONNECTED') {
                badge.className = 'status-badge running';
                badge.textContent = 'CONNECTED';
            } else if (status === 'NEEDS_AUTH') {
                badge.className = 'status-badge starting';
                badge.textContent = 'NEEDS AUTH';
            } else if (status === 'STARTING') {
                badge.className = 'status-badge starting';
                badge.textContent = 'STARTING';
            } else if (status === 'ERROR') {
                badge.className = 'status-badge error';
                badge.textContent = 'ERROR';
            } else {
                badge.className = 'status-badge offline';
                badge.textContent = 'DISCONNECTED';
            }
        }

        if (card) {
            if (status === 'CONNECTED') card.className = 'status-card running';
            else if (status === 'NEEDS_AUTH' || status === 'STARTING') card.className = 'status-card';
            else if (status === 'ERROR') card.className = 'status-card error';
            else card.className = 'status-card';
        }

        if (title) {
            if (status === 'CONNECTED') title.textContent = 'Tailscale Active';
            else if (status === 'NEEDS_AUTH') title.textContent = 'Action Required';
            else if (status === 'STARTING') title.textContent = 'Initializing Mesh...';
            else if (status === 'ERROR') title.textContent = 'Tailscale Error';
            else title.textContent = 'Tailscale Offline';
        }

        if (message) {
            if (status === 'CONNECTED') message.textContent = 'Node authenticated and connected to Tailnet.';
            else if (status === 'NEEDS_AUTH') message.textContent = 'Node created. Tap Login to Tailscale to authorize.';
            else if (status === 'STARTING') message.textContent = 'Starting tsnet background daemon...';
            else message.textContent = 'Tap Connect Tailscale to launch embedded mesh proxy.';
        }

        if (toggleBtn) {
            if (this.tsActive) {
                toggleBtn.textContent = 'Disconnect Tailscale';
                toggleBtn.className = 'primary-action btn-danger-toggle';
            } else {
                toggleBtn.textContent = 'Connect Tailscale';
                toggleBtn.className = 'primary-action';
            }
        }

        if (authBox) {
            const needsAuth = (status === 'NEEDS_AUTH' && tsData.auth_url);
            authBox.style.display = needsAuth ? 'block' : 'none';

            if (needsAuth && !this.hasAutoOpenedAuth) {
                this.hasAutoOpenedAuth = true;
                this.openTailscaleAuthUrl();
            }
        }

        if (status === 'CONNECTED' || status === 'STOPPED') {
            this.hasAutoOpenedAuth = false;
        }

        if (linksBox) {
            if (status === 'CONNECTED') {
                const dnsClean = tsData.magic_dns ? tsData.magic_dns.replace(/\.$/, '') : '';
                const ports = this.getActivePorts();
                const certReady = (tsData.cert_ready === true || tsData.cert_ready === 'true');
                
                if (dnsVal) {
                    if (certReady) {
                        dnsVal.innerHTML = dnsClean 
                            ? `<a href="https://${dnsClean}:${ports.httpsPort}/" target="_blank">HTTPS (${ports.httpsPort})</a> | <a href="http://${dnsClean}:${ports.httpPort}/" target="_blank">HTTP (${ports.httpPort})</a> (${dnsClean})`
                            : '-';
                    } else {
                        dnsVal.innerHTML = dnsClean
                            ? `<span style="color: #fbbf24; font-size: 11px; font-weight: 600;">🔒 Provisioning Let's Encrypt SSL (Wait ~10s)...</span>`
                            : '-';
                    }
                }
                linksBox.style.display = 'block';
            } else {
                linksBox.style.display = 'none';
            }
        }

        const collisionBanner = document.getElementById('ts-collision-banner');
        const collisionDomainVal = document.getElementById('ts-collision-domain');

        if (tsData && tsData.collision_flag) {
            if (collisionDomainVal && tsData.collision_domain) {
                collisionDomainVal.textContent = tsData.collision_domain;
            }
            if (collisionBanner) {
                collisionBanner.style.display = 'block';
            }
        } else {
            if (collisionBanner) {
                collisionBanner.style.display = 'none';
            }
        }

        if (this.tsConsoleVisible) {
            this.refreshTailscaleConsole();
        }
    },

    dismissCollisionBanner() {
        const collisionBanner = document.getElementById('ts-collision-banner');
        if (collisionBanner) {
            collisionBanner.style.display = 'none';
        }
        if (window.Android && typeof window.Android.dismissCollisionFlag === 'function') {
            window.Android.dismissCollisionFlag();
        }
    },

    openTailscaleAuthUrl() {
        if (window.Android && typeof window.Android.openTailscaleAuthUrl === 'function') {
            window.Android.openTailscaleAuthUrl();
        }
    },

    openTailscaleAdminDns() {
        this.openExternalUrl('https://login.tailscale.com/admin/dns');
    },

    toggleTailscaleConsole() {
        const wrapper = document.getElementById('ts-console-wrapper');
        if (!wrapper) return;
        this.tsConsoleVisible = (wrapper.style.display === 'none');
        wrapper.style.display = this.tsConsoleVisible ? 'block' : 'none';
        if (this.tsConsoleVisible) {
            this.refreshTailscaleConsole();
        }
    },

    refreshTailscaleConsole() {
        if (!window.Android || typeof window.Android.getTailscaleLog !== 'function') return;
        const out = document.getElementById('ts-console-output');
        if (out) {
            const logs = window.Android.getTailscaleLog();
            out.textContent = logs || 'No log output yet.';
            out.scrollTop = out.scrollHeight;
        }
    },

    copyTailscaleLog() {
        if (!window.Android || typeof window.Android.getTailscaleLog !== 'function') return;
        const logs = window.Android.getTailscaleLog();
        if (logs) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(logs).then(() => {
                    this.showStatus('Tailscale Log Copied', 'Tailscale debug log was copied to clipboard.', 'success');
                }).catch(() => {
                    if (window.Android.copyLogs) window.Android.copyLogs();
                });
            } else {
                if (window.Android.copyLogs) window.Android.copyLogs();
            }
        }
    },

    resetTailscaleNode() {
        if (!window.Android || typeof window.Android.resetTailscaleNode !== 'function') {
            this.showStatus('Native bridge unavailable', 'Tailscale node reset requires app update.', 'error');
            return;
        }

        if (confirm('Reset Tailscale node identity and credentials? You will need to re-authenticate on your next connection.')) {
            window.Android.resetTailscaleNode();
            this.pollTailscaleStatus();
        }
    },

    logoutTailscale() {
        if (!window.Android || typeof window.Android.logoutTailscale !== 'function') {
            this.showStatus('Native bridge unavailable', 'Tailscale logout requires app update.', 'error');
            return;
        }

        // 1. Stop status poller immediately
        this.stopTailscalePoller();
        this.tsActive = false;
        this.hasAutoOpenedAuth = false;

        // 2. Clear UI input fields
        const inp = document.getElementById('inp-ts-apikey');
        if (inp) inp.value = '';
        const tagsInp = document.getElementById('inp-ts-tags');
        if (tagsInp) tagsInp.value = '';
        const statusEl = document.getElementById('ts-key-status');
        if (statusEl) statusEl.innerHTML = '';

        // 3. Force Tailscale UI to Disconnected state
        this.updateTailscaleUI({ status: 'STOPPED', auth_url: '', ip: '', magic_dns: '' });

        // 4. Force logout button to physically disappear
        const logoutBtn = document.getElementById('btn-logout-tailscale');
        if (logoutBtn) {
            logoutBtn.style.display = 'none';
        }

        // 5. Open debug console so user sees live logout progress
        const consoleWrapper = document.getElementById('ts-console-wrapper');
        if (consoleWrapper) {
            consoleWrapper.style.display = 'block';
            this.tsConsoleVisible = true;
        }

        // 6. Trigger native Java remote cloud device deletion & local purge
        window.Android.logoutTailscale();

        // 7. Live refresh debug console during purge
        let pollCount = 0;
        const logoutPoller = setInterval(() => {
            this.refreshTailscaleConsole();
            pollCount++;
            if (pollCount > 10) clearInterval(logoutPoller);
        }, 500);
    },

    setDownloadComplete() {
        this.downloadInProgress = false;
        const button = document.querySelector('.url-form .secondary-action');
        if (button) {
            button.disabled = false;
            button.textContent = 'Download ZIP';
        }
    },

    handleInstallProgress(title, message, type, progress) {
        const modal = document.getElementById('import-progress-modal');
        const badge = document.getElementById('import-progress-badge');
        const titleEl = document.getElementById('import-progress-title');
        const msgEl = document.getElementById('import-progress-msg');
        const bar = document.getElementById('import-progress-bar');
        const actions = document.getElementById('import-modal-actions');

        const cleanType = (type || '').toLowerCase();

        if (cleanType === 'importing' || cleanType === 'preparing' || (progress > 0 && progress < 100)) {
            if (modal) {
                modal.style.display = 'flex';
                modal.style.zIndex = '2000';
            }
            if (badge) {
                badge.className = 'status-badge starting';
                badge.textContent = 'IMPORTING';
            }
            if (titleEl) titleEl.textContent = title;
            if (msgEl) msgEl.textContent = message;
            if (bar) {
                bar.style.width = `${Math.max(10, Math.min(100, progress))}%`;
                bar.style.background = 'var(--primary-accent)';
            }
            if (actions) actions.style.display = 'none';
        } else if (cleanType === 'success') {
            if (modal) {
                modal.style.display = 'flex';
                modal.style.zIndex = '2000';
            }
            if (badge) {
                badge.className = 'status-badge running';
                badge.textContent = 'SUCCESS';
            }
            if (titleEl) titleEl.textContent = title;
            if (msgEl) msgEl.textContent = message;
            if (bar) {
                bar.style.width = '100%';
                bar.style.background = 'var(--success)';
            }
            if (actions) actions.style.display = 'flex';
            this.checkPackageFolderState(true);
            this.setDownloadComplete();
        } else if (cleanType === 'error') {
            if (modal) {
                modal.style.display = 'flex';
                modal.style.zIndex = '2000';
            }
            if (badge) {
                badge.className = 'status-badge error';
                badge.textContent = 'ERROR';
            }
            if (titleEl) titleEl.textContent = title;
            if (msgEl) msgEl.textContent = message;
            if (bar) {
                bar.style.width = '100%';
                bar.style.background = 'var(--danger)';
            }
            if (actions) actions.style.display = 'flex';
            this.setDownloadComplete();
        } else {
            this.showStatus(title, message, type || '');
        }
    },

    closeImportModal() {
        const modal = document.getElementById('import-progress-modal');
        if (modal) modal.style.display = 'none';
    },

    showStatus(title, message, type) {
        const titleEl = document.getElementById('status-title');
        const messageEl = document.getElementById('status-message');
        const badge = document.getElementById('status-badge');
        const card = document.getElementById('status-card');

        if (titleEl) titleEl.textContent = title;
        if (messageEl) messageEl.textContent = message;

        const cleanType = (type || '').toLowerCase();
        if (cleanType === 'success') {
            this.checkPackageFolderState(true);
        }

        if (badge && card) {
            card.className = `status-card ${cleanType}`.trim();

            if (cleanType === 'success' || cleanType === 'running') {
                badge.className = 'status-badge running';
                badge.textContent = 'RUNNING';
            } else if (cleanType === 'error') {
                badge.className = 'status-badge error';
                badge.textContent = 'ERROR';
            } else {
                badge.className = 'status-badge stopped';
                badge.textContent = 'OFFLINE';
            }
        }
    }
};

window.updateInstallStatus = function(title, message, type, progress) {
    Runtime.handleInstallProgress(title, message, type, progress || 0);
};

window.updateRuntimeStatus = function(status, message) {
    Runtime.updateRuntimeStatus(status, message);
};

window.checkPermissionsLive = function() {
    Runtime.checkStoragePermission();
    Runtime.checkBatteryOptimization();
    Runtime.pollRuntimeStatus();
    Runtime.pollTailscaleStatus();
    Runtime.pollMdnsStatus();
    Runtime.loadSystemPaths();
};

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        window.checkPermissionsLive();
    }
});

window.addEventListener('focus', () => {
    window.checkPermissionsLive();
});

document.addEventListener('DOMContentLoaded', () => Runtime.init());