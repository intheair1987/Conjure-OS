// Global Catalog State Variables
window.projects = [];
window.currentLayout = localStorage.getItem('apkwrapper_layout') || 'grid';
window.currentCatalogTab = localStorage.getItem('apkwrapper_catalog_tab') || 'all';
window.currentSortMode = localStorage.getItem('apkwrapper_sort_mode') || 'time';
window.currentSortOrder = localStorage.getItem('apkwrapper_sort_order') || 'desc';
window.currentEditingId = null;
window.currentBase64Icon = "";

window.switchSortMode = function(mode) {
    window.currentSortMode = mode;
    localStorage.setItem('apkwrapper_sort_mode', mode);
    window.updateSortMenuUI();
    window.renderCatalog();
};

window.switchSortOrder = function(order) {
    window.currentSortOrder = order;
    localStorage.setItem('apkwrapper_sort_order', order);
    window.updateSortMenuUI();
    window.renderCatalog();
};

window.openSortMenu = function() {
    window.updateSortMenuUI();
    const overlay = document.getElementById('catalogSortMenuOverlay');
    if (overlay) overlay.style.display = 'flex';
};

window.closeSortMenu = function() {
    const overlay = document.getElementById('catalogSortMenuOverlay');
    if (overlay) overlay.style.display = 'none';
};

window.updateSortMenuUI = function() {
    ['time', 'alpha', 'installed'].forEach(mode => {
        const btn = document.getElementById('btnSortMode_' + mode);
        if (btn) {
            if (window.currentSortMode === mode) {
                btn.style.background = 'var(--primary-accent)';
                btn.style.color = '#ffffff';
                btn.style.borderColor = 'var(--primary-accent)';
            } else {
                btn.style.background = 'rgba(255, 255, 255, 0.05)';
                btn.style.color = 'var(--text-secondary)';
                btn.style.borderColor = 'var(--border-color)';
            }
        }
    });

    ['asc', 'desc'].forEach(order => {
        const btn = document.getElementById('btnSortOrder_' + order);
        if (btn) {
            if (window.currentSortOrder === order) btn.classList.add('active');
            else btn.classList.remove('active');
        }
    });
};

// Installation Status Query Helpers
window.checkIsAppInstalled = function(pkgName) {
    if (!pkgName) return false;
    if (window.Android && typeof window.Android.isAppInstalled === 'function') {
        try {
            return window.Android.isAppInstalled(pkgName.trim());
        } catch (e) {
            return false;
        }
    }
    return false;
};

window.getInstallationStatusBadge = function(pkgName) {
    const installed = window.checkIsAppInstalled(pkgName);
    if (installed) {
        return `<span style="font-size: 10px; padding: 1px 6px; background: rgba(16, 185, 129, 0.15); color: #34d399; border-radius: 4px; font-weight: 600; display: inline-flex; align-items: center; gap: 3px;"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>Installed</span>`;
    }
    return `<span style="font-size: 10px; padding: 1px 6px; background: rgba(148, 163, 184, 0.1); color: #94a3b8; border-radius: 4px; font-weight: 500;">Not Installed</span>`;
};

// Long-Press Gesture & Context Menu State
window.longPressTimer = null;
window.longPressStartPos = { x: 0, y: 0 };
window.activeLongPressAppId = null;

// Catalog Selection Mode & Floating Batch Action State
window.isCatalogSelectionMode = false;
window.selectedCatalogProjects = new Set();

window.handleCardPointerDown = function(e, appId) {
    if (window.isCatalogSelectionMode) return;
    
    window.activeLongPressAppId = appId;
    window.longPressStartPos = { x: e.clientX || 0, y: e.clientY || 0 };

    if (window.longPressTimer) clearTimeout(window.longPressTimer);

    window.longPressTimer = setTimeout(() => {
        if (window.Android && window.Android.vibrate) {
            try { window.Android.vibrate(50); } catch(err) {}
        } else if (navigator.vibrate) {
            try { navigator.vibrate(50); } catch(err) {}
        }
        window.openCatalogContextMenu(appId);
        window.longPressTimer = null;
    }, 400);
};

window.handleCardPointerMove = function(e) {
    if (!window.longPressTimer) return;
    const moveX = Math.abs((e.clientX || 0) - window.longPressStartPos.x);
    const moveY = Math.abs((e.clientY || 0) - window.longPressStartPos.y);
    if (moveX > 10 || moveY > 10) {
        clearTimeout(window.longPressTimer);
        window.longPressTimer = null;
    }
};

window.handleCardPointerUp = function() {
    if (window.longPressTimer) {
        clearTimeout(window.longPressTimer);
        window.longPressTimer = null;
    }
};

window.handleCardClick = function(e, appId) {
    if (window.isCatalogSelectionMode) {
        window.toggleCatalogCardSelection(appId);
    } else {
        window.editProjectFromCard(appId);
    }
};

window.openCatalogContextMenu = function(appId) {
    const proj = window.projects.find(p => p.id === appId);
    if (!proj) return;

    window.activeLongPressAppId = appId;

    // Purge any active native window text selections immediately
    if (window.getSelection) {
        try { window.getSelection().removeAllRanges(); } catch(e) {}
    }
    
    const overlay = document.getElementById('catalogContextMenuOverlay');
    const title = document.getElementById('ctxMenuTitle');
    const sub = document.getElementById('ctxMenuSub');
    const icon = document.getElementById('ctxMenuIcon');

    if (title) title.textContent = proj.appName || 'App';
    if (sub) sub.textContent = proj.pkgName || '';

    if (icon) {
        if (proj.base64Icon) {
            try {
                const decoded = atob(proj.base64Icon);
                icon.src = decoded.includes('<svg') ? `data:image/svg+xml;base64,${proj.base64Icon}` : `data:image/png;base64,${proj.base64Icon}`;
            } catch (e) {
                icon.src = `data:image/png;base64,${proj.base64Icon}`;
            }
        } else {
            icon.src = 'templates/package.svg';
        }
    }

    if (overlay) {
        // Temporarily disable pointer-events during long-press touch release phase to prevent text selection handles
        overlay.style.pointerEvents = 'none';
        overlay.style.display = 'flex';
        setTimeout(() => {
            overlay.style.pointerEvents = 'auto';
        }, 120);
    }
};

window.closeCatalogContextMenu = function() {
    const overlay = document.getElementById('catalogContextMenuOverlay');
    if (overlay) overlay.style.display = 'none';
};

window.uninstallProjectFromCard = function(appId) {
    const proj = window.projects.find(p => p.id === appId);
    if (!proj) return window.showToast("App not found in catalog.", "error");

    const pkgName = (proj.pkgName || '').trim();
    if (!pkgName) return window.showToast("No package name defined for this app.", "error");

    window.showToast(`Requesting uninstallation for ${proj.appName}...`, "info");
    window.updateTerminal(`Requesting uninstallation for ${proj.appName} (${pkgName})...`, 'running');

    // 1. Try Native Android Bridge
    if (window.Android && typeof window.Android.uninstallApp === 'function') {
        try {
            window.Android.uninstallApp(pkgName);
            return;
        } catch (e) {
            console.error("Native uninstallApp call failed", e);
        }
    }

    // 2. Intent URI fallback for WebViews / Browsers
    try {
        const intentUrl = `intent://package:${pkgName}#Intent;scheme=package;action=android.intent.action.DELETE;end;`;
        const a = document.createElement('a');
        a.href = intentUrl;
        a.target = '_self';
        document.body.appendChild(a);
        a.click();
        a.remove();
    } catch (e) {
        window.location.href = `market://details?id=${pkgName}`;
    }
};

window.triggerCtxMenuAction = function(action) {
    const appId = window.activeLongPressAppId;
    window.closeCatalogContextMenu();
    if (!appId) return;

    if (action === 'build') {
        window.compileProjectFromCard(appId);
    } else if (action === 'edit') {
        window.editProjectFromCard(appId);
    } else if (action === 'uninstall') {
        window.uninstallProjectFromCard(appId);
    } else if (action === 'delete') {
        window.deleteProjectFromCard(appId);
    } else if (action === 'select') {
        window.enterCatalogSelectionMode(appId);
    }
};

window.enterCatalogSelectionMode = function(initialAppId = null) {
    window.isCatalogSelectionMode = true;
    window.selectedCatalogProjects = new Set();
    if (initialAppId) {
        window.selectedCatalogProjects.add(initialAppId);
    }
    window.updateBatchFloatingBar();
    window.renderCatalog();
};

window.exitCatalogSelectionMode = function() {
    window.isCatalogSelectionMode = false;
    window.selectedCatalogProjects.clear();
    window.updateBatchFloatingBar();
    window.renderCatalog();
};

window.toggleCatalogCardSelection = function(appId) {
    if (window.selectedCatalogProjects.has(appId)) {
        window.selectedCatalogProjects.delete(appId);
    } else {
        window.selectedCatalogProjects.add(appId);
    }
    window.updateBatchFloatingBar();
    window.renderCatalog();
};

window.getCurrentlyDisplayedCatalogProjects = function() {
    const allProjs = window.projects || [];
    const currentTab = window.currentCatalogTab || 'all';

    let list = allProjs;
    if (currentTab === 'appmaker') list = allProjs.filter(p => window.getProjectSource(p) === 'appmaker');
    else if (currentTab === 'orbit') list = allProjs.filter(p => window.getProjectSource(p) === 'orbit');
    else if (currentTab === 'manual') list = allProjs.filter(p => window.getProjectSource(p) === 'manual');

    const searchQuery = (document.getElementById('inpCatalogSearch')?.value || '').toLowerCase().trim();
    if (searchQuery) {
        list = list.filter(p => {
            const nameMatch = (p.appName || '').toLowerCase().includes(searchQuery);
            const pkgMatch = (p.pkgName || '').toLowerCase().includes(searchQuery);
            const urlMatch = (p.targetUrl || '').toLowerCase().includes(searchQuery);
            const isInst = window.checkIsAppInstalled(p.pkgName);
            const statusMatch = (searchQuery === 'installed' && isInst) || (searchQuery === 'not installed' && !isInst);
            return nameMatch || pkgMatch || urlMatch || statusMatch;
        });
    }

    return list;
};

window.toggleSelectAllCatalogProjects = function() {
    const displayed = window.getCurrentlyDisplayedCatalogProjects();
    if (!displayed || displayed.length === 0) return;

    const allSelected = displayed.every(p => window.selectedCatalogProjects.has(p.id));
    if (allSelected) {
        displayed.forEach(p => window.selectedCatalogProjects.delete(p.id));
    } else {
        displayed.forEach(p => window.selectedCatalogProjects.add(p.id));
    }

    window.updateBatchFloatingBar();
    window.renderCatalog();
};

window.updateBatchFloatingBar = function() {
    const bar = document.getElementById('catalogBatchFloatingBar');
    const txtCount = document.getElementById('txtBatchSelectionCount');
    const btnSelectAll = document.getElementById('btnSelectAllCatalog');
    const terminalCard = document.getElementById('terminalCard');

    if (!bar) return;

    if (window.isCatalogSelectionMode) {
        bar.style.display = 'flex';
        
        // Dynamically elevate batch bar so it sits cleanly above terminal card
        const isTerminalExpanded = terminalCard && terminalCard.classList.contains('expanded');
        bar.style.bottom = isTerminalExpanded ? '242px' : '50px';

        if (txtCount) {
            txtCount.textContent = `${window.selectedCatalogProjects.size} Selected`;
        }

        const displayed = window.getCurrentlyDisplayedCatalogProjects();
        const allSelected = displayed.length > 0 && displayed.every(p => window.selectedCatalogProjects.has(p.id));
        if (btnSelectAll) {
            btnSelectAll.textContent = allSelected ? "Select None" : "Select All";
        }
    } else {
        bar.style.display = 'none';
    }
};

window.runBatchSelectionBuild = function() {
    if (!window.selectedCatalogProjects || window.selectedCatalogProjects.size === 0) {
        return window.showToast("Select at least one app to build.", "error");
    }

    const selectedList = window.projects.filter(p => window.selectedCatalogProjects.has(p.id));
    window.showCustomConfirm(
        "Build Selected Apps",
        `Compile and install ${selectedList.length} selected web wrapper app(s) in sequence?`,
        () => {
            window.batchQueue = [...selectedList];
            window.batchTotalCount = selectedList.length;
            window.isBatchRunning = true;
            window.isWaitingForResume = false;

            window.exitCatalogSelectionMode();

            const banner = document.getElementById('batchProgressBanner');
            if (banner) banner.style.display = 'block';

            window.processNextInBatch();
        },
        "Start Build",
        "Cancel"
    );
};

window.runBatchSelectionDelete = function() {
    if (!window.selectedCatalogProjects || window.selectedCatalogProjects.size === 0) {
        return window.showToast("Select at least one app to delete.", "error");
    }

    const count = window.selectedCatalogProjects.size;
    window.showCustomConfirm(
        "Delete Selected Apps",
        `Are you sure you want to delete ${count} selected app(s) from your catalog?`,
        () => {
            const idsToDelete = Array.from(window.selectedCatalogProjects);
            idsToDelete.forEach(id => window.deleteProjectFromBridge(id));
            window.exitCatalogSelectionMode();
            window.showToast(`Deleted ${count} app(s)`, "info");
        },
        "Delete All",
        "Cancel",
        true
    );
};

// Batch Uninstall Queue State
window.batchUninstallQueue = [];

window.runBatchSelectionUninstall = function() {
    if (!window.selectedCatalogProjects || window.selectedCatalogProjects.size === 0) {
        return window.showToast("Select at least one app to uninstall.", "error");
    }

    if (!window.Android || !window.Android.uninstallApp) {
        return window.showToast("Native Android bridge required to uninstall apps.", "error");
    }

    const selectedList = window.projects.filter(p => window.selectedCatalogProjects.has(p.id));
    window.showCustomConfirm(
        "Uninstall Selected Apps",
        `Request system uninstallation for ${selectedList.length} selected app(s) in sequence?`,
        () => {
            window.batchUninstallQueue = selectedList.map(p => p.pkgName);
            window.exitCatalogSelectionMode();
            window.processNextInBatchUninstall();
        },
        "Start Uninstall",
        "Cancel"
    );
};

window.processNextInBatchUninstall = function() {
    if (!window.batchUninstallQueue || window.batchUninstallQueue.length === 0) {
        window.showToast("Batch uninstallation requests complete.", "success");
        return;
    }

    const nextPkg = window.batchUninstallQueue.shift();
    if (!nextPkg) return;

    window.showToast(`Uninstalling (${window.batchUninstallQueue.length + 1} remaining): ${nextPkg}`, "info");

    // 1. Try Native Android Bridge
    if (window.Android && typeof window.Android.uninstallApp === 'function') {
        try {
            window.Android.uninstallApp(nextPkg);
            return;
        } catch (e) {
            console.error("Native batch uninstall call failed", e);
        }
    }

    // 2. Intent URI fallback for WebViews / Browsers
    try {
        const intentUrl = `intent://package:${nextPkg}#Intent;scheme=package;action=android.intent.action.DELETE;end;`;
        const a = document.createElement('a');
        a.href = intentUrl;
        a.target = '_self';
        document.body.appendChild(a);
        a.click();
        a.remove();
    } catch (e) {
        window.location.href = `market://details?id=${nextPkg}`;
    }
};

window.getProjectSource = function(proj) {
    if (proj && proj.source) return proj.source;
    if (proj && proj.pkgName) {
        if (proj.pkgName.includes('.orbit.')) return 'orbit';
        if (proj.pkgName.includes('.appmaker.')) return 'appmaker';
    }
    return 'manual';
};

window.switchCatalogTab = function(tab) {
    window.currentCatalogTab = tab;
    localStorage.setItem('apkwrapper_catalog_tab', tab);

    ['All', 'AppMaker', 'Orbit', 'Manual'].forEach(tName => {
        const btn = document.getElementById('tabCatalog' + tName);
        if (btn) {
            if (tName.toLowerCase() === tab) btn.classList.add('active');
            else btn.classList.remove('active');
        }
    });

    window.renderCatalog();
};

// Batch Reinstall Queue State Variables
window.batchQueue = [];
window.batchTotalCount = 0;
window.isBatchRunning = false;
window.isWaitingForResume = false;

// Auto-Detect AppMaker Apps State
window.autoDetectedApps = [];
window.selectedAutoApps = new Set();
window.currentAutoDetectTab = 'appmaker';
window.autoDetectSortMode = localStorage.getItem('apkwrapper_auto_sort_mode') || 'alpha';
window.autoDetectSortOrder = localStorage.getItem('apkwrapper_auto_sort_order') || 'asc';

window.switchAutoDetectSortMode = function(mode) {
    window.autoDetectSortMode = mode;
    localStorage.setItem('apkwrapper_auto_sort_mode', mode);
    window.updateAutoDetectSortMenuUI();
    window.renderAutoDetectGrid();
};

window.switchAutoDetectSortOrder = function(order) {
    window.autoDetectSortOrder = order;
    localStorage.setItem('apkwrapper_auto_sort_order', order);
    window.updateAutoDetectSortMenuUI();
    window.renderAutoDetectGrid();
};

window.openAutoDetectSortMenu = function() {
    window.updateAutoDetectSortMenuUI();
    const overlay = document.getElementById('autoDetectSortMenuOverlay');
    if (overlay) overlay.style.display = 'flex';
};

window.closeAutoDetectSortMenu = function() {
    const overlay = document.getElementById('autoDetectSortMenuOverlay');
    if (overlay) overlay.style.display = 'none';
};

window.updateAutoDetectSortMenuUI = function() {
    ['alpha', 'catalog', 'installed'].forEach(mode => {
        const btn = document.getElementById('btnAutoSortMode_' + mode);
        if (btn) {
            if (window.autoDetectSortMode === mode) {
                btn.style.background = 'var(--primary-accent)';
                btn.style.color = '#ffffff';
                btn.style.borderColor = 'var(--primary-accent)';
            } else {
                btn.style.background = 'rgba(255, 255, 255, 0.05)';
                btn.style.color = 'var(--text-secondary)';
                btn.style.borderColor = 'var(--border-color)';
            }
        }
    });

    ['asc', 'desc'].forEach(order => {
        const btn = document.getElementById('btnAutoSortOrder_' + order);
        if (btn) {
            if (window.autoDetectSortOrder === order) btn.classList.add('active');
            else btn.classList.remove('active');
        }
    });
};

window.switchAutoDetectTab = function(tab) {
    window.currentAutoDetectTab = tab;
    const btnAppMaker = document.getElementById('tabAutoAppMaker');
    const btnOrbit = document.getElementById('tabAutoOrbit');
    if (btnAppMaker && btnOrbit) {
        if (tab === 'appmaker') {
            btnAppMaker.classList.add('active');
            btnOrbit.classList.remove('active');
        } else {
            btnOrbit.classList.add('active');
            btnAppMaker.classList.remove('active');
        }
    }
    window.renderAutoDetectGrid();
};

window.openAutoDetectDrawer = function() {
    window.selectedAutoApps = new Set();
    const overlay = document.getElementById('autoDetectOverlay');
    if (overlay) {
        overlay.classList.add('active');
        overlay.style.display = 'flex';
    }
    window.fetchAppMakerApps();
};

window.closeAutoDetectDrawer = function() {
    const overlay = document.getElementById('autoDetectOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.style.display = 'none';
    }
};

window.getServerCandidateUrls = function() {
    const candidates = [];

    // 1. Native Android bridge active runtime JSON (from /sdcard/Conjure_Config/runtime_active.json SSOT)
    if (window.Android && window.Android.getRuntimeActiveJson) {
        try {
            const rawJson = window.Android.getRuntimeActiveJson();
            const activeJson = JSON.parse(rawJson || '{}');
            if (activeJson.https_port) {
                const url = `https://127.0.0.1:${activeJson.https_port}`;
                if (!candidates.includes(url)) candidates.push(url);
            }
            if (activeJson.base_url && !candidates.includes(activeJson.base_url)) {
                candidates.push(activeJson.base_url);
            }
            if (activeJson.custom_url && !candidates.includes(activeJson.custom_url)) {
                candidates.push(activeJson.custom_url);
            }
            if (activeJson.loopback_url && !candidates.includes(activeJson.loopback_url)) {
                candidates.push(activeJson.loopback_url);
            }
            if (activeJson.http_port) {
                const url = `http://127.0.0.1:${activeJson.http_port}`;
                if (!candidates.includes(url)) candidates.push(url);
            }
        } catch (e) {
            console.error("Failed to parse runtime_active.json via bridge", e);
        }
    }

    // 2. Previously verified active base URL from localStorage
    const savedUrl = localStorage.getItem('conjure_active_base_url');
    if (savedUrl && !candidates.includes(savedUrl)) {
        candidates.push(savedUrl);
    }

    // 3. If loaded over HTTP/HTTPS, current origin
    if (window.location.protocol.startsWith('http') && !candidates.includes(window.location.origin)) {
        candidates.push(window.location.origin);
    }

    // 4. Common loopback ports fallback array
    const defaultPorts = [8000, 8001, 8080, 8081, 8002, 8003];
    defaultPorts.forEach(port => {
        const httpUrl = `http://127.0.0.1:${port}`;
        if (!candidates.includes(httpUrl)) candidates.push(httpUrl);
    });

    candidates.push('http://conjure.local:8000');
    candidates.push('http://conjure.local:8001');
    candidates.push('http://localhost:8000');
    candidates.push('http://localhost:8001');

    return candidates;
};

window.fetchAppMakerApps = async function() {
    const statusCount = document.getElementById('autoDetectStatusCount');
    const grid = document.getElementById('autoDetectAppGrid');
    if (statusCount) statusCount.textContent = "Probing active server ports...";
    if (grid) grid.innerHTML = `<div style="text-align: center; color: var(--text-secondary); font-size: 13px; padding: 24px;">Discovering AppMaker apps...</div>`;

    const candidates = window.getServerCandidateUrls();
    let workingServerUrl = null;
    let responseData = null;
    let lastError = null;

    for (const baseUrl of candidates) {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 2000);

            const res = await fetch(`${baseUrl}/app/api/backend.php?action=scan_appmaker_apps`, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                signal: controller.signal
            });
            clearTimeout(timeoutId);

            if (res.ok) {
                const data = await res.json();
                if (data && data.status === 'success') {
                    workingServerUrl = baseUrl;
                    responseData = data;
                    localStorage.setItem('conjure_active_base_url', baseUrl);
                    break;
                }
            }
        } catch (e) {
            lastError = e ? (e.message || String(e)) : 'Network error';
        }
    }

    if (workingServerUrl && responseData && Array.isArray(responseData.apps)) {
        window.autoDetectedApps = responseData.apps;
        window.selectedAutoApps = new Set(); // Select none by default
        
        window.renderAutoDetectGrid();
        window.syncCatalogIconsWithScannedAssets();
    } else {
        const errDiag = lastError ? ` [${lastError}]` : '';
        if (grid) grid.innerHTML = `<div style="text-align: center; color: var(--text-secondary); font-size: 13px; padding: 24px;">Server offline or unreachable across probed ports (${candidates.slice(0, 3).join(', ')}...)${errDiag}. Ensure ConjureRuntime is running.</div>`;
        if (statusCount) statusCount.textContent = "Server offline / unreachable";
    }
};

window.toggleSelectAllAutoApps = function() {
    if (!window.autoDetectedApps || window.autoDetectedApps.length === 0) return;

    const searchQuery = (document.getElementById('inpAutoDetectSearch')?.value || '').toLowerCase().trim();
    let visibleApps = window.autoDetectedApps.filter(a => 
        window.currentAutoDetectTab === 'orbit' ? a.source === 'orbit' : a.source === 'appmaker'
    );

    if (searchQuery) {
        visibleApps = visibleApps.filter(app => 
            (app.appName || '').toLowerCase().includes(searchQuery) ||
            (app.pkgName || '').toLowerCase().includes(searchQuery) ||
            (app.subdomain || '').toLowerCase().includes(searchQuery) ||
            (app.folder || '').toLowerCase().includes(searchQuery) ||
            (app.template || '').toLowerCase().includes(searchQuery)
        );
    }

    const visiblePkgs = visibleApps.map(a => a.pkgName);
    const allVisibleSelected = visiblePkgs.length > 0 && visiblePkgs.every(pkg => window.selectedAutoApps.has(pkg));

    if (allVisibleSelected) {
        visiblePkgs.forEach(pkg => window.selectedAutoApps.delete(pkg));
    } else {
        visiblePkgs.forEach(pkg => window.selectedAutoApps.add(pkg));
    }
    window.renderAutoDetectGrid();
};

window.renderAutoDetectGrid = function() {
    const statusCount = document.getElementById('autoDetectStatusCount');
    const btnSelectAll = document.getElementById('btnSelectAllAuto');
    const grid = document.getElementById('autoDetectAppGrid');
    const badgeAppMaker = document.getElementById('badgeCountAppMaker');
    const badgeOrbit = document.getElementById('badgeCountOrbit');
    const btnImport = document.getElementById('btnImportSelectedAuto');

    if (!grid) return;

    const appMakerApps = (window.autoDetectedApps || []).filter(a => a.source === 'appmaker');
    const orbitApps = (window.autoDetectedApps || []).filter(a => a.source === 'orbit');

    if (badgeAppMaker) badgeAppMaker.textContent = appMakerApps.length;
    if (badgeOrbit) badgeOrbit.textContent = orbitApps.length;

    const currentTab = window.currentAutoDetectTab || 'appmaker';
    let visibleApps = currentTab === 'orbit' ? orbitApps : appMakerApps;

    const searchQuery = (document.getElementById('inpAutoDetectSearch')?.value || '').toLowerCase().trim();
    if (searchQuery) {
        visibleApps = visibleApps.filter(app => 
            (app.appName || '').toLowerCase().includes(searchQuery) ||
            (app.pkgName || '').toLowerCase().includes(searchQuery) ||
            (app.subdomain || '').toLowerCase().includes(searchQuery) ||
            (app.folder || '').toLowerCase().includes(searchQuery) ||
            (app.template || '').toLowerCase().includes(searchQuery)
        );
    }

    // Sort visibleApps based on autoDetectSortMode and autoDetectSortOrder
    visibleApps = [...visibleApps].sort((a, b) => {
        const isDesc = (window.autoDetectSortOrder === 'desc');
        if (window.autoDetectSortMode === 'catalog') {
            const inCatA = window.projects.some(p => p.pkgName === a.pkgName || (p.appName.toLowerCase() === a.appName.toLowerCase() && p.targetUrl === a.targetUrl)) ? 1 : 0;
            const inCatB = window.projects.some(p => p.pkgName === b.pkgName || (p.appName.toLowerCase() === b.appName.toLowerCase() && p.targetUrl === b.targetUrl)) ? 1 : 0;
            if (inCatA !== inCatB) {
                return isDesc ? (inCatA - inCatB) : (inCatB - inCatA);
            }
            return (a.appName || '').localeCompare(b.appName || '');
        } else if (window.autoDetectSortMode === 'installed') {
            const instA = window.checkIsAppInstalled(a.pkgName) ? 1 : 0;
            const instB = window.checkIsAppInstalled(b.pkgName) ? 1 : 0;
            if (instA !== instB) {
                return isDesc ? (instA - instB) : (instB - instA);
            }
            return (a.appName || '').localeCompare(b.appName || '');
        } else { // 'alpha'
            const cmp = (a.appName || '').toLowerCase().localeCompare((b.appName || '').toLowerCase());
            return isDesc ? -cmp : cmp;
        }
    });

    if (btnImport) {
        const selCount = window.selectedAutoApps ? window.selectedAutoApps.size : 0;
        btnImport.textContent = selCount > 0 ? `Import ${selCount} Selected Item(s)` : "Import Selected Items";
    }

    if (!visibleApps || visibleApps.length === 0) {
        const emptyLabel = searchQuery 
            ? `No ${currentTab === 'orbit' ? 'Orbit instances' : 'AppMaker apps'} found matching "${escapeHtml(searchQuery)}".`
            : (currentTab === 'orbit' ? 'No Orbit instances deployed or found.' : 'No local AppMaker apps found.');
        grid.innerHTML = `<div style="text-align: center; color: var(--text-secondary); font-size: 13px; padding: 24px;">${emptyLabel}</div>`;
        if (statusCount) statusCount.textContent = `0 ${currentTab === 'orbit' ? 'instances' : 'apps'} available`;
        if (btnSelectAll) btnSelectAll.textContent = "Select All";
        return;
    }

    const selectedInTab = visibleApps.filter(a => window.selectedAutoApps.has(a.pkgName)).length;
    const allTabSelected = selectedInTab === visibleApps.length && visibleApps.length > 0;
    if (btnSelectAll) btnSelectAll.textContent = allTabSelected ? "Deselect All" : "Select All";

    if (statusCount) {
        const queryLabel = searchQuery ? ` filtered` : '';
        statusCount.textContent = `${visibleApps.length}${queryLabel} ${currentTab === 'orbit' ? 'Orbit instance(s)' : 'AppMaker app(s)'} (${selectedInTab} selected)`;
    }

    let htmlOutput = '';

    if (window.autoDetectSortMode === 'catalog') {
        const inCat = [];
        const notInCat = [];
        visibleApps.forEach(app => {
            const inCatalog = window.projects.some(p => p.pkgName === app.pkgName || (p.appName.toLowerCase() === app.appName.toLowerCase() && p.targetUrl === app.targetUrl));
            if (inCatalog) inCat.push(app);
            else notInCat.push(app);
        });

        inCat.sort((a,b) => (a.appName || '').localeCompare(b.appName || ''));
        notInCat.sort((a,b) => (a.appName || '').localeCompare(b.appName || ''));

        const isDesc = (window.autoDetectSortOrder === 'desc');
        const sec1 = isDesc ? { title: 'Not in Catalog', items: notInCat, color: '#94a3b8' } : { title: 'In Catalog', items: inCat, color: '#818cf8' };
        const sec2 = isDesc ? { title: 'In Catalog', items: inCat, color: '#818cf8' } : { title: 'Not in Catalog', items: notInCat, color: '#94a3b8' };

        [sec1, sec2].forEach(sec => {
            if (sec.items.length > 0) {
                htmlOutput += `<div class="grid-section-header" style="border-left-color: ${sec.color};">${escapeHtml(sec.title)} (${sec.items.length})</div>`;
                htmlOutput += sec.items.map(app => window.renderAutoDetectCardHtml(app)).join('');
            }
        });
    } else if (window.autoDetectSortMode === 'installed') {
        const installed = [];
        const notInstalled = [];
        visibleApps.forEach(app => {
            if (window.checkIsAppInstalled(app.pkgName)) installed.push(app);
            else notInstalled.push(app);
        });

        installed.sort((a,b) => (a.appName || '').localeCompare(b.appName || ''));
        notInstalled.sort((a,b) => (a.appName || '').localeCompare(b.appName || ''));

        const isDesc = (window.autoDetectSortOrder === 'desc');
        const sec1 = isDesc ? { title: 'Not Installed', items: notInstalled, color: '#94a3b8' } : { title: 'Installed', items: installed, color: '#34d399' };
        const sec2 = isDesc ? { title: 'Installed', items: installed, color: '#34d399' } : { title: 'Not Installed', items: notInstalled, color: '#94a3b8' };

        [sec1, sec2].forEach(sec => {
            if (sec.items.length > 0) {
                htmlOutput += `<div class="grid-section-header" style="border-left-color: ${sec.color};">${escapeHtml(sec.title)} (${sec.items.length})</div>`;
                htmlOutput += sec.items.map(app => window.renderAutoDetectCardHtml(app)).join('');
            }
        });
    } else {
        visibleApps.sort((a, b) => {
            const isDesc = (window.autoDetectSortOrder === 'desc');
            const cmp = (a.appName || '').toLowerCase().localeCompare((b.appName || '').toLowerCase());
            return isDesc ? -cmp : cmp;
        });
        htmlOutput = visibleApps.map(app => window.renderAutoDetectCardHtml(app)).join('');
    }

    grid.innerHTML = htmlOutput;
};

window.renderAutoDetectCardHtml = function(app) {
    const isChecked = window.selectedAutoApps.has(app.pkgName);
    const inCatalog = window.projects.some(p => p.pkgName === app.pkgName || (p.appName.toLowerCase() === app.appName.toLowerCase() && p.targetUrl === app.targetUrl));
    const iconSrc = app.base64Icon ? `data:image/svg+xml;base64,${app.base64Icon}` : 'templates/package.svg';

    const isOrbit = app.source === 'orbit';
    const sourceBadge = isOrbit 
        ? `<span style="font-size: 10px; padding: 1px 6px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; border-radius: 4px; font-weight: 600;">🪐 Orbit: ${escapeHtml(app.subdomain || '')}</span>`
        : `<span style="font-size: 10px; padding: 1px 6px; background: rgba(16, 185, 129, 0.15); color: #10b981; border-radius: 4px; font-weight: 500;">⚡ Local App</span>`;

    const installBadge = window.getInstallationStatusBadge(app.pkgName);

    return `
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; background: rgba(255,255,255,0.02); border: 1px solid ${isChecked ? 'rgba(16, 185, 129, 0.4)' : 'var(--border-color)'}; border-radius: 10px; cursor: pointer;" onclick="toggleAutoAppSelect('${app.pkgName}')">
            <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                <input type="checkbox" ${isChecked ? 'checked' : ''} onclick="event.stopPropagation(); toggleAutoAppSelect('${app.pkgName}')" style="width: 18px; height: 18px; accent-color: #10b981; cursor: pointer;">
                <img src="${iconSrc}" style="width: 36px; height: 36px; border-radius: 8px; object-fit: contain; background: rgba(0,0,0,0.2); padding: 4px; border: 1px solid var(--border-color);" onerror="this.src='templates/package.svg'">
                <div style="min-width: 0;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                        ${escapeHtml(app.appName)}
                        ${sourceBadge}
                        ${installBadge}
                        ${inCatalog ? '<span style="font-size: 10px; padding: 1px 6px; background: rgba(99, 102, 241, 0.15); color: #818cf8; border-radius: 4px; font-weight: 500;">In Catalog</span>' : ''}
                    </div>
                    <div style="font-size: 11px; color: var(--text-secondary); font-family: var(--font-mono); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        ${escapeHtml(app.pkgName)}
                    </div>
                </div>
            </div>
        </div>
    `;
};

window.toggleAutoAppSelect = function(pkgName) {
    if (window.selectedAutoApps.has(pkgName)) {
        window.selectedAutoApps.delete(pkgName);
    } else {
        window.selectedAutoApps.add(pkgName);
    }
    window.renderAutoDetectGrid();
};

window.svgBase64ToPngBase64 = function(svgBase64, bgColor = '#ffffff', fgColor = '#1e1b4b') {
    return new Promise((resolve) => {
        if (!svgBase64) return resolve("");
        try {
            const decoded = atob(svgBase64);
            // If already a valid binary PNG, return as-is
            if (decoded.startsWith('\x89PNG') || svgBase64.startsWith('iVBORw0KGgo')) {
                return resolve(svgBase64);
            }

            let svgContent = decoded;

            // If the SVG content is a complete SVG element (standard AppMaker icon.svg)
            if (svgContent.includes('<svg')) {
                // Ensure the SVG fills 512x512 full bleed without inner scaling or artificial margins
                if (!svgContent.includes('viewBox')) {
                    svgContent = svgContent.replace('<svg', '<svg viewBox="0 0 512 512"');
                }
                if (svgContent.includes('width=')) {
                    svgContent = svgContent.replace(/width="[^"]+"/, 'width="512"');
                } else {
                    svgContent = svgContent.replace('<svg', '<svg width="512"');
                }
                if (svgContent.includes('height=')) {
                    svgContent = svgContent.replace(/height="[^"]+"/, 'height="512"');
                } else {
                    svgContent = svgContent.replace('<svg', '<svg height="512"');
                }
            } else {
                // If it's a raw inner path/vector string without <svg> root tag
                svgContent = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
                    <rect width="512" height="512" fill="${bgColor || '#ffffff'}" rx="112" />
                    <g transform="translate(96, 96) scale(13.33)" stroke="${fgColor}" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        ${svgContent}
                    </g>
                </svg>`;
            }

            const dataUri = 'data:image/svg+xml;charset=utf-8;base64,' + btoa(unescape(encodeURIComponent(svgContent)));
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                canvas.width = 512;
                canvas.height = 512;
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, 512, 512);
                ctx.drawImage(img, 0, 0, 512, 512);
                const pngDataUrl = canvas.toDataURL('image/png');
                resolve(pngDataUrl.split(',')[1]);
            };
            img.onerror = (e) => {
                console.error("SVG to PNG conversion error", e);
                resolve("");
            };
            img.src = dataUri;
        } catch (e) {
            console.error("SVG to PNG conversion error", e);
            resolve("");
        }
    });
};

window.importSelectedAutoApps = async function() {
    if (window.selectedAutoApps.size === 0) {
        return window.showToast("Select at least one item to import.", "error");
    }

    const btnImport = document.getElementById('btnImportSelectedAuto');
    if (btnImport) {
        btnImport.disabled = true;
        btnImport.textContent = "Converting Icons & Importing...";
    }

    let count = 0;
    for (const app of window.autoDetectedApps) {
        if (window.selectedAutoApps.has(app.pkgName)) {
            const id = 'proj_' + app.pkgName.replace(/[^a-zA-Z0-9_]/g, '_');
            let pngBase64Icon = "";
            if (app.base64Icon) {
                pngBase64Icon = await window.svgBase64ToPngBase64(app.base64Icon, '#ffffff', app.color || '#1e1b4b');
            }

            const projObj = {
                id: id,
                appName: app.appName,
                targetUrl: app.targetUrl,
                pkgName: app.pkgName,
                base64Icon: pngBase64Icon,
                source: app.source || (app.pkgName.includes('.orbit.') ? 'orbit' : (app.pkgName.includes('.appmaker.') ? 'appmaker' : 'manual')),
                updatedAt: new Date().toISOString()
            };
            window.saveProjectToBridge(projObj);
            count++;
        }
    }

    if (btnImport) {
        btnImport.disabled = false;
        btnImport.textContent = "Import Selected Apps";
    }

    window.renderCatalog();
    window.closeAutoDetectDrawer();
    window.showToast(`Imported ${count} item(s) with PNG icons!`, "success");
};

window.syncCatalogIconsWithScannedAssets = async function() {
    if (!window.projects || window.projects.length === 0 || !window.autoDetectedApps || window.autoDetectedApps.length === 0) return;
    
    let updatedCount = 0;
    for (const proj of window.projects) {
        const match = window.autoDetectedApps.find(a => 
            a.pkgName === proj.pkgName || 
            (a.appName.toLowerCase() === proj.appName.toLowerCase() && (a.folder === proj.id.replace('proj_', '') || a.targetUrl === proj.targetUrl))
        );

        if (match && match.base64Icon) {
            const freshPng = await window.svgBase64ToPngBase64(match.base64Icon, '#ffffff', match.color || '#1e1b4b');
            if (freshPng && freshPng !== proj.base64Icon) {
                proj.base64Icon = freshPng;
                proj.updatedAt = new Date().toISOString();
                window.saveProjectToBridge(proj);
                updatedCount++;
            }
        }
    }

    if (updatedCount > 0) {
        window.renderCatalog();
        window.showToast(`Auto-synced ${updatedCount} app icon(s) from server!`, "success");
    }
};

window.copyCompilerOutput = function() {
    const terminalOutput = document.getElementById('terminalOutput');
    if (!terminalOutput) return;
    const text = terminalOutput.textContent || terminalOutput.innerText || '';
    if (!text || text === 'Awaiting instructions...') {
        return window.showToast("No compiler output to copy.", "info");
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            window.showToast("Compiler log copied to clipboard!", "success");
        }).catch(() => {
            window.fallbackCopyText(text);
        });
    } else {
        window.fallbackCopyText(text);
    }
};

window.fallbackCopyText = function(text) {
    try {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        textArea.style.top = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        document.execCommand('copy');
        textArea.remove();
        window.showToast("Compiler log copied to clipboard!", "success");
    } catch (e) {
        window.showToast("Failed to copy log.", "error");
    }
};

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// Custom Toast Notification Engine
window.showToast = function(msg, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = msg;
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
};

// Custom Modal Dialog Engine (Replaces native browser confirm/alert)
window.showCustomConfirm = function(title, message, onConfirm, okText = 'OK', cancelText = 'Cancel', isDanger = false) {
    const overlay = document.getElementById('modalDialogOverlay');
    const titleEl = document.getElementById('modalDialogTitle');
    const msgEl = document.getElementById('modalDialogMessage');
    const actionsEl = document.getElementById('modalDialogActions');
    if (!overlay) return;

    if (titleEl) titleEl.textContent = title;
    if (msgEl) msgEl.textContent = message;

    if (actionsEl) {
        actionsEl.innerHTML = '';
        if (cancelText) {
            const btnCancel = document.createElement('button');
            btnCancel.className = 'btn-card-action';
            btnCancel.textContent = cancelText;
            btnCancel.onclick = () => {
                overlay.style.display = 'none';
            };
            actionsEl.appendChild(btnCancel);
        }

        const btnOk = document.createElement('button');
        btnOk.className = isDanger ? 'btn-card-action btn-card-delete' : 'btn-card-action btn-card-compile';
        btnOk.textContent = okText;
        btnOk.onclick = () => {
            overlay.style.display = 'none';
            if (onConfirm) onConfirm();
        };
        actionsEl.appendChild(btnOk);
    }

    overlay.style.display = 'flex';
};

window.showCustomAlert = function(title, message) {
    window.showCustomConfirm(title, message, null, 'OK', null);
};

// Generic Color Preset Chip Delegation Handler
document.addEventListener('click', (e) => {
    const chip = e.target.closest('.color-chip');
    if (chip) {
        const presetsContainer = chip.closest('.color-presets');
        if (presetsContainer) {
            const targetId = presetsContainer.getAttribute('data-target');
            const targetInput = document.getElementById(targetId);
            if (targetInput) {
                const color = chip.getAttribute('data-color');
                targetInput.value = color;
                targetInput.dispatchEvent(new Event('input', { bubbles: true }));
                targetInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }
});

// Native Bridge Storage Helpers
window.loadProjectsFromBridge = function() {
    let loaded = [];
    if (window.Android && window.Android.loadProjects) {
        try {
            const raw = window.Android.loadProjects();
            loaded = JSON.parse(raw || '[]');
        } catch (e) {
            console.error("Failed to parse native projects JSON", e);
        }
    }
    
    if (!loaded || loaded.length === 0) {
        try {
            loaded = JSON.parse(localStorage.getItem('apkwrapper_projects') || '[]');
        } catch (e) { loaded = []; }
    }

    window.projects = loaded;
    window.renderCatalog();
};

window.saveProjectToBridge = function(projObj) {
    const idx = window.projects.findIndex(p => p.id === projObj.id);
    if (idx >= 0) window.projects[idx] = projObj;
    else window.projects.push(projObj);

    try {
        localStorage.setItem('apkwrapper_projects', JSON.stringify(window.projects));
    } catch (e) {}

    if (window.Android && window.Android.saveProject) {
        window.Android.saveProject(projObj.id, JSON.stringify(projObj));
    }
};

window.deleteProjectFromBridge = function(id) {
    window.projects = window.projects.filter(p => p.id !== id);
    try {
        localStorage.setItem('apkwrapper_projects', JSON.stringify(window.projects));
    } catch (e) {}

    if (window.Android && window.Android.deleteProject) {
        window.Android.deleteProject(id);
    }
};

// Render Catalog Items
window.renderCatalog = function() {
    const container = document.getElementById('catalogContainer');
    const emptyState = document.getElementById('emptyCatalogState');

    if (!container) return;

    if (window.currentLayout === 'grid') {
        container.className = 'catalog-grid';
        const gridIcon = document.getElementById('iconLayoutGrid');
        const listIcon = document.getElementById('iconLayoutList');
        if (gridIcon) gridIcon.style.display = 'block';
        if (listIcon) listIcon.style.display = 'none';
    } else {
        container.className = 'catalog-list';
        const gridIcon = document.getElementById('iconLayoutGrid');
        const listIcon = document.getElementById('iconLayoutList');
        if (gridIcon) gridIcon.style.display = 'none';
        if (listIcon) listIcon.style.display = 'block';
    }

    const allProjs = window.projects || [];
    const appMakerProjs = allProjs.filter(p => window.getProjectSource(p) === 'appmaker');
    const orbitProjs = allProjs.filter(p => window.getProjectSource(p) === 'orbit');
    const manualProjs = allProjs.filter(p => window.getProjectSource(p) === 'manual');

    const bAll = document.getElementById('badgeCatalogAll');
    const bAppMaker = document.getElementById('badgeCatalogAppMaker');
    const bOrbit = document.getElementById('badgeCatalogOrbit');
    const bManual = document.getElementById('badgeCatalogManual');

    if (bAll) bAll.textContent = allProjs.length;
    if (bAppMaker) bAppMaker.textContent = appMakerProjs.length;
    if (bOrbit) bOrbit.textContent = orbitProjs.length;
    if (bManual) bManual.textContent = manualProjs.length;

    const currentTab = window.currentCatalogTab || 'all';
    let displayProjects = allProjs;
    if (currentTab === 'appmaker') displayProjects = appMakerProjs;
    else if (currentTab === 'orbit') displayProjects = orbitProjs;
    else if (currentTab === 'manual') displayProjects = manualProjs;

    const searchQuery = (document.getElementById('inpCatalogSearch')?.value || '').toLowerCase().trim();
    if (searchQuery) {
        displayProjects = displayProjects.filter(p => 
            (p.appName || '').toLowerCase().includes(searchQuery) ||
            (p.pkgName || '').toLowerCase().includes(searchQuery) ||
            (p.targetUrl || '').toLowerCase().includes(searchQuery)
        );
    }

    if (!allProjs || allProjs.length === 0) {
        container.innerHTML = '';
        if (emptyState) emptyState.style.display = 'block';
        return;
    }

    if (emptyState) emptyState.style.display = 'none';

    if (displayProjects.length === 0) {
        const tabLabel = currentTab === 'all' ? '' : ` in ${currentTab}`;
        const searchLabel = searchQuery ? ` matching "${escapeHtml(searchQuery)}"` : '';
        container.innerHTML = `<div style="grid-column: 1 / -1; text-align: center; color: var(--text-secondary); font-size: 13px; padding: 32px;">No catalog wrappers found${tabLabel}${searchLabel}.</div>`;
        return;
    }

    if (window.currentSortMode === 'installed') {
        const installed = [];
        const notInstalled = [];
        displayProjects.forEach(p => {
            if (window.checkIsAppInstalled(p.pkgName)) installed.push(p);
            else notInstalled.push(p);
        });

        installed.sort((a,b) => (a.appName || '').localeCompare(b.appName || ''));
        notInstalled.sort((a,b) => (a.appName || '').localeCompare(b.appName || ''));

        const isDesc = (window.currentSortOrder === 'desc');
        const sec1 = isDesc ? { title: 'Not Installed', items: notInstalled, color: '#94a3b8' } : { title: 'Installed', items: installed, color: '#34d399' };
        const sec2 = isDesc ? { title: 'Installed', items: installed, color: '#34d399' } : { title: 'Not Installed', items: notInstalled, color: '#94a3b8' };

        let htmlOut = '';
        [sec1, sec2].forEach(sec => {
            if (sec.items.length > 0) {
                htmlOut += `<div class="grid-section-header" style="grid-column: 1 / -1; border-left-color: ${sec.color};">${escapeHtml(sec.title)} (${sec.items.length})</div>`;
                htmlOut += sec.items.map(p => window.renderCatalogCardHtml(p)).join('');
            }
        });
        container.innerHTML = htmlOut;
        return;
    }

    // Sort displayProjects for alpha or time
    displayProjects = [...displayProjects].sort((a, b) => {
        if (window.currentSortMode === 'alpha') {
            const nameA = (a.appName || '').toLowerCase();
            const nameB = (b.appName || '').toLowerCase();
            const cmp = nameA.localeCompare(nameB);
            return window.currentSortOrder === 'desc' ? -cmp : cmp;
        } else { // 'time'
            const tA = new Date(a.updatedAt || 0).getTime() || 0;
            const tB = new Date(b.updatedAt || 0).getTime() || 0;
            return window.currentSortOrder === 'desc' ? (tB - tA) : (tA - tB);
        }
    });

    container.innerHTML = displayProjects.map(p => window.renderCatalogCardHtml(p)).join('');
};

window.renderCatalogCardHtml = function(p) {
    let iconSrc = 'templates/package.svg';
    if (p.base64Icon) {
        try {
            const decoded = atob(p.base64Icon);
            if (decoded.includes('<svg')) {
                iconSrc = `data:image/svg+xml;base64,${p.base64Icon}`;
            } else {
                iconSrc = `data:image/png;base64,${p.base64Icon}`;
            }
        } catch (e) {
            iconSrc = `data:image/png;base64,${p.base64Icon}`;
        }
    }

    const src = window.getProjectSource(p);
    let srcBadge = `<span style="font-size: 10px; padding: 1px 6px; background: rgba(168, 85, 247, 0.15); color: #c084fc; border-radius: 4px; font-weight: 500;">✏️ Manual</span>`;
    if (src === 'appmaker') {
        srcBadge = `<span style="font-size: 10px; padding: 1px 6px; background: rgba(16, 185, 129, 0.15); color: #10b981; border-radius: 4px; font-weight: 500;">⚡ AppMaker</span>`;
    } else if (src === 'orbit') {
        srcBadge = `<span style="font-size: 10px; padding: 1px 6px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; border-radius: 4px; font-weight: 600;">🪐 Orbit</span>`;
    }

    const installBadge = window.getInstallationStatusBadge(p.pkgName);
    const isSelected = window.isCatalogSelectionMode && window.selectedCatalogProjects.has(p.id);
    const checkIcon = isSelected ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>' : '';

    return `
    <div class="app-card ${isSelected ? 'selected-card' : ''}" data-id="${p.id}"
         onpointerdown="handleCardPointerDown(event, '${p.id}')"
         onpointermove="handleCardPointerMove(event)"
         onpointerup="handleCardPointerUp()"
         onpointercancel="handleCardPointerUp()"
         oncontextmenu="event.preventDefault(); openCatalogContextMenu('${p.id}');"
         onclick="handleCardClick(event, '${p.id}')">
                
        ${window.isCatalogSelectionMode ? `<div class="card-selection-check">${checkIcon}</div>` : ''}

        <div class="app-card-meta">
            <img src="${iconSrc}" class="app-card-icon" onerror="this.src='templates/package.svg'">
            <div class="app-card-info">
                <div class="app-card-title" style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                    ${escapeHtml(p.appName)}
                    ${srcBadge}
                    ${installBadge}
                </div>
                <div class="app-card-sub">${escapeHtml(p.pkgName)}</div>
            </div>
        </div>
    </div>
`;
};// Batch Reinstall Queue Functions
window.startBatchReinstall = function() {
    if (!window.projects || window.projects.length === 0) {
        return window.showToast("No saved apps in catalog to reinstall.", "error");
    }

    window.showCustomConfirm(
        "Reinstall All Apps",
        `Compile and reinstall all ${window.projects.length} saved web wrapper apps in sequence?`,
        () => {
            window.batchQueue = [...window.projects];
            window.batchTotalCount = window.projects.length;
            window.isBatchRunning = true;
            window.isWaitingForResume = false;

            const banner = document.getElementById('batchProgressBanner');
            if (banner) banner.style.display = 'block';

            window.processNextInBatch();
        },
        "Start Reinstall",
        "Cancel"
    );
};

window.processNextInBatch = function() {
    const banner = document.getElementById('batchProgressBanner');
    const statusText = document.getElementById('batchStatusText');
    const progressBar = document.getElementById('batchProgressBar');

    if (!window.batchQueue || window.batchQueue.length === 0) {
        window.isBatchRunning = false;
        window.isWaitingForResume = false;
        if (banner) banner.style.display = 'none';
        if (progressBar) progressBar.style.width = '100%';
        window.showToast("All apps in batch queue processed!", "success");
        return;
    }

    const completedCount = window.batchTotalCount - window.batchQueue.length + 1;
    const pct = Math.round(((completedCount - 1) / window.batchTotalCount) * 100);

    if (progressBar) progressBar.style.width = `${pct}%`;
    
    const nextApp = window.batchQueue.shift();
    if (statusText) {
        statusText.textContent = `Installing ${completedCount} of ${window.batchTotalCount}: ${nextApp.appName}`;
    }

    window.isWaitingForResume = true;
    window.compileProjectFromCard(nextApp.id);
};

window.cancelBatchReinstall = function() {
    window.batchQueue = [];
    window.isBatchRunning = false;
    window.isWaitingForResume = false;
    
    const banner = document.getElementById('batchProgressBanner');
    if (banner) banner.style.display = 'none';
    
    window.showToast("Batch installation cancelled.", "info");
};

window.onAppResume = function() {
    window.renderCatalog();
    if (document.getElementById('autoDetectOverlay')?.style.display === 'flex') {
        window.renderAutoDetectGrid();
    }

    if (window.isBatchRunning && window.isWaitingForResume) {
        window.isWaitingForResume = false;
        setTimeout(() => {
            window.processNextInBatch();
        }, 600);
    } else if (window.batchUninstallQueue && window.batchUninstallQueue.length > 0) {
        setTimeout(() => {
            window.processNextInBatchUninstall();
        }, 600);
    }
};

// Global Window Action Handlers
window.openConfiguratorDrawer = function() {
    window.currentEditingId = null;
    const inpId = document.getElementById('inpProjectId');
    const inpName = document.getElementById('inpAppName');
    const inpUrl = document.getElementById('inpTargetUrl');
    const inpPkg = document.getElementById('inpPkgName');
    const title = document.getElementById('configuratorTitle');
    const overlay = document.getElementById('configuratorOverlay');

    if (inpId) inpId.value = '';
    if (inpName) inpName.value = '';
    if (inpUrl) inpUrl.value = '';
    if (inpPkg) inpPkg.value = '';
    if (title) title.textContent = 'New WebWrapper';

    window.currentBase64Icon = "";

    const iconPrev = document.getElementById('iconPreview');
    const iconContainer = document.getElementById('iconPreviewContainer');
    const iconPlaceholder = document.getElementById('iconPlaceholder');

    if (iconPrev) iconPrev.src = '';
    if (iconContainer) iconContainer.style.display = 'none';
    if (iconPlaceholder) iconPlaceholder.style.display = 'block';

    if (overlay) {
        overlay.classList.add('active');
        overlay.style.display = 'flex';
    }
};

window.closeConfiguratorDrawer = function() {
    const overlay = document.getElementById('configuratorOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.style.display = 'none';
    }
};

window.saveAppFromConfigurator = function() {
    const inpAppName = document.getElementById('inpAppName');
    const inpTargetUrl = document.getElementById('inpTargetUrl');
    const inpPkgName = document.getElementById('inpPkgName');

    const appName = inpAppName ? inpAppName.value.trim() : '';
    const targetUrl = inpTargetUrl ? inpTargetUrl.value.trim() : '';
    const pkgName = inpPkgName ? inpPkgName.value.trim() : '';

    if (!appName) return window.showToast("Please enter an Application Name.", "error");
    if (!targetUrl || !targetUrl.startsWith('http')) return window.showToast("Please enter a valid Target URL (http/https).", "error");

    const id = window.currentEditingId || ('proj_' + Date.now());
    const existing = window.currentEditingId ? window.projects.find(p => p.id === window.currentEditingId) : null;
    const projObj = {
        id: id,
        appName: appName,
        targetUrl: targetUrl,
        pkgName: pkgName,
        base64Icon: window.currentBase64Icon || "",
        source: existing ? (existing.source || window.getProjectSource(existing)) : 'manual',
        updatedAt: new Date().toISOString()
    };

    window.saveProjectToBridge(projObj);
    window.renderCatalog();
    window.closeConfiguratorDrawer();
    window.showToast(`${appName} saved to catalog`, "success");
};

window.compileAppFromConfigurator = function() {
    const inpAppName = document.getElementById('inpAppName');
    const inpTargetUrl = document.getElementById('inpTargetUrl');
    const inpPkgName = document.getElementById('inpPkgName');

    const appName = inpAppName ? inpAppName.value.trim() : '';
    const targetUrl = inpTargetUrl ? inpTargetUrl.value.trim() : '';
    const pkgName = inpPkgName ? inpPkgName.value.trim() : '';

    if (!appName) return window.showToast("Please enter an Application Name.", "error");
    if (!targetUrl || !targetUrl.startsWith('http')) return window.showToast("Please enter a valid Target URL (http/https).", "error");

    const id = window.currentEditingId || ('proj_' + Date.now());
    const existing = window.currentEditingId ? window.projects.find(p => p.id === window.currentEditingId) : null;
    const projObj = {
        id: id,
        appName: appName,
        targetUrl: targetUrl,
        pkgName: pkgName,
        base64Icon: window.currentBase64Icon || "",
        source: existing ? (existing.source || window.getProjectSource(existing)) : 'manual',
        updatedAt: new Date().toISOString()
    };

    window.saveProjectToBridge(projObj);
    window.renderCatalog();
    window.closeConfiguratorDrawer();

    const terminalOutput = document.getElementById('terminalOutput');
    if (terminalOutput) {
        terminalOutput.textContent = `Initializing compilation pipeline for ${appName}...\n`;
    }

    if (window.Android && window.Android.startCompilation) {
        window.Android.startCompilation(appName, pkgName, targetUrl, window.currentBase64Icon || "");
    } else {
        window.updateTerminal("Error: Android WebBridge not found. Are you running inside the native app?", "error");
    }
};

window.toggleCatalogLayout = function() {
    window.currentLayout = (window.currentLayout === 'grid') ? 'list' : 'grid';
    localStorage.setItem('apkwrapper_layout', window.currentLayout);
    window.renderCatalog();
};

window.compileProjectFromCard = function(id) {
    const proj = window.projects.find(p => p.id === id);
    if (!proj) return;
    
    const terminalOutput = document.getElementById('terminalOutput');
    if (terminalOutput) {
        terminalOutput.textContent = `Initializing build pipeline for ${proj.appName}...\n`;
    }
    
    if (window.Android && window.Android.startCompilation) {
        window.Android.startCompilation(proj.appName, proj.pkgName, proj.targetUrl, proj.base64Icon || "");
    } else {
        window.updateTerminal("Error: Android WebBridge not found. Are you running inside the native app?", "error");
    }
};

window.editProjectFromCard = function(id) {
    const proj = window.projects.find(p => p.id === id);
    if (!proj) return;

    window.currentEditingId = proj.id;
    const inpId = document.getElementById('inpProjectId');
    const inpName = document.getElementById('inpAppName');
    const inpUrl = document.getElementById('inpTargetUrl');
    const inpPkg = document.getElementById('inpPkgName');
    const title = document.getElementById('configuratorTitle');

    if (inpId) inpId.value = proj.id;
    if (inpName) inpName.value = proj.appName || '';
    if (inpUrl) inpUrl.value = proj.targetUrl || '';
    if (inpPkg) inpPkg.value = proj.pkgName || '';
    if (title) title.textContent = `Edit ${proj.appName}`;

    const iconPrev = document.getElementById('iconPreview');
    const iconContainer = document.getElementById('iconPreviewContainer');
    const iconPlaceholder = document.getElementById('iconPlaceholder');

    if (proj.base64Icon) {
        window.currentBase64Icon = proj.base64Icon;
        if (iconPrev) iconPrev.src = `data:image/png;base64,${proj.base64Icon}`;
        if (iconContainer) iconContainer.style.display = 'flex';
        if (iconPlaceholder) iconPlaceholder.style.display = 'none';
    } else {
        window.currentBase64Icon = "";
        if (iconPrev) iconPrev.src = '';
        if (iconContainer) iconContainer.style.display = 'none';
        if (iconPlaceholder) iconPlaceholder.style.display = 'block';
    }

    const overlay = document.getElementById('configuratorOverlay');
    if (overlay) {
        overlay.classList.add('active');
        overlay.style.display = 'flex';
    }
};

window.deleteProjectFromCard = function(id) {
    const proj = window.projects.find(p => p.id === id);
    if (!proj) return;
    
    window.showCustomConfirm(
        "Delete WebWrapper",
        `Are you sure you want to delete ${proj.appName} from your catalog?`,
        () => {
            window.deleteProjectFromBridge(id);
            window.renderCatalog();
            window.showToast(`${proj.appName} deleted`, "info");
        },
        "Delete",
        "Cancel",
        true
    );
};

window.exportCatalogBackup = function() {
    const jsonPayload = JSON.stringify(window.projects, null, 2);
    if (window.Android && window.Android.exportCatalogToDownloads) {
        const res = window.Android.exportCatalogToDownloads(jsonPayload);
        window.showToast(res, "success");
    } else {
        const blob = new Blob([jsonPayload], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'ApkWrapper_Catalog_Backup.json';
        a.click();
        URL.revokeObjectURL(url);
        window.showToast("Catalog backup downloaded", "success");
    }
};

window.triggerCatalogImport = function() {
    const inp = document.getElementById('inpImportCatalog');
    if (inp) inp.click();
};

document.addEventListener('selectionstart', (e) => {
    if (window.longPressTimer || window.activeLongPressAppId || document.getElementById('catalogContextMenuOverlay')?.style.display === 'flex') {
        e.preventDefault();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const inpAppName = document.getElementById('inpAppName');
    const inpPkgName = document.getElementById('inpPkgName');
    const inpIcon = document.getElementById('inpIcon');
    const iconPreviewContainer = document.getElementById('iconPreviewContainer');
    const iconPreview = document.getElementById('iconPreview');
    const iconPlaceholder = document.getElementById('iconPlaceholder');
    const terminalOutput = document.getElementById('terminalOutput');

    let uploadedSvgContent = null;
    let uploadedSvgSize = 100;
    let uploadedSvgRaw = false;
    let lastCompositeSvg = "";

    const uploadSvgControls = document.getElementById('upload-svg-controls');
    const chkRawSvgUpload = document.getElementById('chkRawSvgUpload');
    const svgCustomizerFields = document.getElementById('svg-customizer-fields');
    const inpBgColorUpload = document.getElementById('inpBgColorUpload');
    const txtBgColorUpload = document.getElementById('txtBgColorUpload');
    const inpFgColorUpload = document.getElementById('inpFgColorUpload');
    const txtFgColorUpload = document.getElementById('txtFgColorUpload');
    const inpSizeUpload = document.getElementById('inpSizeUpload');
    const txtSizeUpload = document.getElementById('txtSizeUpload');

    let lucideIcons = [];
    let activeLucideIcon = null;
    let lucideTagsData = {};
    const tabUpload = document.getElementById('tabUpload');
    const tabGenerate = document.getElementById('tabGenerate');
    const paneUpload = document.getElementById('paneUpload');
    const paneGenerate = document.getElementById('paneGenerate');
    const inpBgColor = document.getElementById('inpBgColor');
    const inpFgColor = document.getElementById('inpFgColor');
    const inpIconSearch = document.getElementById('inpIconSearch');
    const iconGrid = document.getElementById('iconGrid');
    const genPreview = document.getElementById('genPreview');
    const genPlaceholder = document.getElementById('genPlaceholder');

    if (inpAppName) {
        inpAppName.addEventListener('input', () => {
            const name = inpAppName.value;
            if (!name) {
                if (inpPkgName) inpPkgName.value = "";
                return;
            }
            const sanitized = name.toLowerCase().replace(/[^a-z0-9]/g, '');
            if (inpPkgName) {
                if (sanitized) inpPkgName.value = "com.wrapper." + sanitized;
                else inpPkgName.value = "";
            }
        });
    }

    if (tabUpload && tabGenerate) {
        tabUpload.addEventListener('click', () => {
            tabUpload.classList.add('active');
            tabGenerate.classList.remove('active');
            if (paneUpload) paneUpload.style.display = 'block';
            if (paneGenerate) paneGenerate.style.display = 'none';
            
            if (iconPreview && iconPreview.src && iconPreview.src.startsWith('data:')) {
                window.currentBase64Icon = iconPreview.src.split(',')[1];
            } else {
                window.currentBase64Icon = "";
            }
        });

        tabGenerate.addEventListener('click', () => {
            tabGenerate.classList.add('active');
            tabUpload.classList.remove('active');
            if (paneGenerate) paneGenerate.style.display = 'block';
            if (paneUpload) paneUpload.style.display = 'none';
            
            if (lucideIcons.length === 0) fetchLucideIndex();
            else updateGeneratedIcon();
        });
    }

    async function fetchLucideIndex() {
        try {
            const res = await fetch('https://unpkg.com/lucide-static@latest/tags.json');
            lucideTagsData = await res.json();
            lucideIcons = Object.keys(lucideTagsData);
            renderIconGrid(lucideIcons.slice(0, 40));
            if (lucideIcons.length > 0 && !activeLucideIcon) {
                activeLucideIcon = 'globe';
                updateGeneratedIcon();
            }
        } catch (e) {
            console.error("Failed to fetch Lucide index", e);
            lucideIcons = ['globe', 'home', 'settings', 'user', 'star', 'heart', 'zap', 'compass', 'box', 'layers', 'skull'];
            lucideTagsData = {};
            lucideIcons.forEach(i => lucideTagsData[i] = [i]);
            renderIconGrid(lucideIcons);
            activeLucideIcon = 'globe';
            updateGeneratedIcon();
        }
    }

    function renderIconGrid(icons) {
        if (!iconGrid) return;
        iconGrid.innerHTML = '';
        icons.forEach(name => {
            const btn = document.createElement('button');
            btn.className = `icon-btn ${name === activeLucideIcon ? 'active' : ''}`;
            btn.innerHTML = `<img src="https://unpkg.com/lucide-static@latest/icons/${name}.svg" style="width:24px; height:24px; filter: invert(1);" onerror="this.src='https://unpkg.com/lucide-static@latest/icons/help-circle.svg'">`;
            btn.onclick = () => {
                activeLucideIcon = name;
                document.querySelectorAll('.icon-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                updateGeneratedIcon();
            };
            iconGrid.appendChild(btn);
        });
    }

    if (inpIconSearch) {
        inpIconSearch.addEventListener('input', () => {
            const query = inpIconSearch.value.toLowerCase().trim();
            if (!query) {
                renderIconGrid(lucideIcons.slice(0, 40));
                return;
            }
            
            const filtered = lucideIcons.filter(name => {
                if (name.includes(query)) return true;
                const tags = lucideTagsData[name] || [];
                return tags.some(tag => tag.toLowerCase().includes(query));
            });
            
            renderIconGrid(filtered.slice(0, 40));
        });
    }

    if (inpBgColor) {
        inpBgColor.addEventListener('input', () => {
            const txt = document.getElementById('txtBgColor');
            if (txt) txt.textContent = inpBgColor.value;
            updateGeneratedIcon();
        });
    }
    if (inpFgColor) {
        inpFgColor.addEventListener('input', () => {
            const txt = document.getElementById('txtFgColor');
            if (txt) txt.textContent = inpFgColor.value;
            updateGeneratedIcon();
        });
    }

    async function updateGeneratedIcon() {
        if (!activeLucideIcon) return;
        try {
            const res = await fetch(`https://unpkg.com/lucide-static@latest/icons/${activeLucideIcon}.svg`);
            const svgText = await res.text();
            const innerMatch = svgText.match(/<svg[^>]*>([\s\S]*?)<\/svg>/i);
            const inner = innerMatch ? innerMatch[1] : '';
            
            const bgColor = inpBgColor ? inpBgColor.value : '#12101c';
            const fgColor = inpFgColor ? inpFgColor.value : '#ffffff';

            const compositeSvg = `
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
                    <rect width="512" height="512" fill="${bgColor}" rx="112" />
                    <g transform="translate(128, 128) scale(10.66)" stroke="${fgColor}" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        ${inner}
                    </g>
                </svg>
            `;

            const dataUri = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(compositeSvg)));
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                canvas.width = 512;
                canvas.height = 512;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);
                const dataUrl = canvas.toDataURL('image/png');
                
                if (genPreview) {
                    genPreview.src = dataUrl;
                    genPreview.style.display = 'block';
                }
                if (genPlaceholder) genPlaceholder.style.display = 'none';
                
                if (tabGenerate && tabGenerate.classList.contains('active')) {
                    window.currentBase64Icon = dataUrl.split(',')[1];
                }
            };
            img.onerror = (e) => {
                console.error("Failed to render generated SVG icon", e);
            };
            img.src = dataUri;
        } catch (e) {
            console.error(e);
        }
    }

    const iconZone = document.getElementById('iconZone');
    if (iconZone && inpIcon) {
        iconZone.addEventListener('click', () => {
            inpIcon.click();
        });
    }

    if (inpIcon) {
        inpIcon.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;

            const isSvg = file.name.toLowerCase().endsWith('.svg') || file.type === 'image/svg+xml';

            if (isSvg) {
                if (uploadSvgControls) uploadSvgControls.style.display = 'block';
                const reader = new FileReader();
                reader.onload = (evt) => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(evt.target.result, 'image/svg+xml');
                    const svgEl = doc.querySelector('svg');
                    if (svgEl) {
                        uploadedSvgContent = {
                            rawText: evt.target.result,
                            inner: svgEl.innerHTML,
                            viewBox: svgEl.getAttribute('viewBox') || "0 0 24 24",
                            stroke: svgEl.getAttribute('stroke') || 'none',
                            fill: svgEl.getAttribute('fill') || 'none'
                        };
                        renderUploadedSvg();
                    } else {
                        window.showToast("Failed to parse SVG content.", "error");
                    }
                };
                reader.readAsText(file);
            } else {
                if (uploadSvgControls) uploadSvgControls.style.display = 'none';
                uploadedSvgContent = null;

                const reader = new FileReader();
                reader.onload = (evt) => {
                    const img = new Image();
                    img.onload = () => {
                        const canvas = document.createElement('canvas');
                        const ctx = canvas.getContext('2d');
                        canvas.width = 512;
                        canvas.height = 512;

                        const scale = Math.max(canvas.width / img.width, canvas.height / img.height);
                        const x = (canvas.width / 2) - (img.width / 2) * scale;
                        const y = (canvas.height / 2) - (img.height / 2) * scale;

                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        ctx.drawImage(img, x, y, img.width * scale, img.height * scale);

                        const dataUrl = canvas.toDataURL('image/png');
                        
                        if (iconPreview) iconPreview.src = dataUrl;
                        if (iconPreviewContainer) iconPreviewContainer.style.display = 'flex';
                        if (iconPlaceholder) iconPlaceholder.style.display = 'none';
                        
                        window.currentBase64Icon = dataUrl.split(',')[1];
                    };
                    img.src = evt.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }

    function renderUploadedSvg() {
        if (!uploadedSvgContent) return;
        try {
            let compositeSvg = "";
            const config = {
                raw: uploadedSvgRaw,
                bgColor: inpBgColorUpload ? inpBgColorUpload.value : '#6366f1',
                fgColor: inpFgColorUpload ? inpFgColorUpload.value : '#ffffff',
                size: uploadedSvgSize,
                viewBox: uploadedSvgContent.viewBox,
                stroke: uploadedSvgContent.stroke,
                fill: uploadedSvgContent.fill,
                inner: uploadedSvgContent.inner,
                rawText: uploadedSvgContent.rawText
            };

            if (uploadedSvgRaw) {
                compositeSvg = uploadedSvgContent.rawText;
                compositeSvg = compositeSvg.replace(/data-pwa-studio-config="[^"]+"/, '');
                if (compositeSvg.includes('width=')) {
                    compositeSvg = compositeSvg.replace(/width="[^"]+"/, 'width="512"');
                } else {
                    compositeSvg = compositeSvg.replace('<svg', '<svg width="512"');
                }
                if (compositeSvg.includes('height=')) {
                    compositeSvg = compositeSvg.replace(/height="[^"]+"/, 'height="512"');
                } else {
                    compositeSvg = compositeSvg.replace('<svg', '<svg height="512"');
                }
            } else {
                const parts = uploadedSvgContent.viewBox.split(' ');
                const w = parseFloat(parts[2]) || 24;
                const h = parseFloat(parts[3]) || 24;
                
                const sizeMultiplier = uploadedSvgSize / 100;
                const scale = Math.min(256 / w, 256 / h) * sizeMultiplier;
                const dx = (512 - w * scale) / 2;
                const dy = (512 - h * scale) / 2;
                
                let strokeColor = (uploadedSvgContent.stroke === 'none') ? 'none' : config.fgColor;
                let fillColor = uploadedSvgContent.fill;
                if (fillColor !== 'none' && fillColor !== '') {
                    fillColor = config.fgColor;
                }
                if (strokeColor === 'none' && fillColor === 'none') {
                    strokeColor = config.fgColor;
                }

                compositeSvg = `
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
                        <rect width="512" height="512" fill="${config.bgColor}" rx="112" />
                        <g transform="translate(${dx}, ${dy}) scale(${scale})" stroke="${strokeColor}" fill="${fillColor}" stroke-linecap="round" stroke-linejoin="round">
                            ${uploadedSvgContent.inner}
                        </g>
                    </svg>
                `;
            }
            
            lastCompositeSvg = compositeSvg;
            const dataUri = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(compositeSvg)));
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                canvas.width = 512;
                canvas.height = 512;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);
                const dataUrl = canvas.toDataURL('image/png');
                
                if (iconPreview) iconPreview.src = dataUrl;
                if (iconPreviewContainer) iconPreviewContainer.style.display = 'flex';
                if (iconPlaceholder) iconPlaceholder.style.display = 'none';
                
                window.currentBase64Icon = dataUrl.split(',')[1];
            };
            img.onerror = (e) => {
                console.error("Failed to render uploaded SVG icon", e);
            };
            img.src = dataUri;
        } catch (e) {
            console.error(e);
        }
    }

    if (inpBgColorUpload) {
        inpBgColorUpload.addEventListener('input', () => {
            if (txtBgColorUpload) txtBgColorUpload.textContent = inpBgColorUpload.value;
            renderUploadedSvg();
        });
    }

    if (inpFgColorUpload) {
        inpFgColorUpload.addEventListener('input', () => {
            if (txtFgColorUpload) txtFgColorUpload.textContent = inpFgColorUpload.value;
            renderUploadedSvg();
        });
    }

    if (inpSizeUpload) {
        inpSizeUpload.addEventListener('input', (e) => {
            uploadedSvgSize = parseInt(e.target.value);
            if (txtSizeUpload) txtSizeUpload.textContent = `${uploadedSvgSize}%`;
            renderUploadedSvg();
        });
    }

    if (chkRawSvgUpload) {
        chkRawSvgUpload.addEventListener('change', (e) => {
            uploadedSvgRaw = e.target.checked;
            if (svgCustomizerFields) svgCustomizerFields.style.display = uploadedSvgRaw ? 'none' : 'block';
            renderUploadedSvg();
        });
    }

    const inpImportCatalog = document.getElementById('inpImportCatalog');
    if (inpImportCatalog) {
        inpImportCatalog.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = (evt) => {
                try {
                    const importedArr = JSON.parse(evt.target.result);
                    if (Array.isArray(importedArr)) {
                        importedArr.forEach(proj => {
                            if (proj.id && proj.appName && proj.pkgName) {
                                window.saveProjectToBridge(proj);
                            }
                        });
                        window.renderCatalog();
                        window.showToast(`Successfully imported ${importedArr.length} wrapper app(s)!`, "success");
                    } else {
                        window.showToast("Invalid catalog backup file format.", "error");
                    }
                } catch (err) {
                    window.showToast("Error parsing backup JSON: " + err.message, "error");
                }
            };
            reader.readAsText(file);
        });
    }

    window.expandTerminal = function() {
        const card = document.getElementById('terminalCard');
        const chevron = document.getElementById('iconTerminalChevron');
        if (card) {
            card.classList.remove('collapsed');
            card.classList.add('expanded');
        }
        if (chevron) {
            chevron.style.transform = 'rotate(180deg)';
        }
        if (window.isCatalogSelectionMode) {
            window.updateBatchFloatingBar();
        }
    };

    window.collapseTerminal = function() {
        const card = document.getElementById('terminalCard');
        const chevron = document.getElementById('iconTerminalChevron');
        if (card) {
            card.classList.remove('expanded');
            card.classList.add('collapsed');
        }
        if (chevron) {
            chevron.style.transform = 'rotate(0deg)';
        }
        if (window.isCatalogSelectionMode) {
            window.updateBatchFloatingBar();
        }
    };

    window.toggleTerminal = function() {
        const card = document.getElementById('terminalCard');
        if (card && card.classList.contains('expanded')) {
            window.collapseTerminal();
        } else {
            window.expandTerminal();
        }
    };

    window.updateTerminal = function(logLine, status = 'running') {
        const terminalOutput = document.getElementById('terminalOutput');
        const statusPill = document.getElementById('terminalStatusPill');

        if (terminalOutput) {
            if (terminalOutput.textContent === 'Awaiting instructions...') {
                terminalOutput.textContent = '';
            }
            terminalOutput.textContent += logLine + "\n";
            terminalOutput.scrollTop = terminalOutput.scrollHeight;
        }

        if (statusPill) {
            statusPill.className = `terminal-pill ${status}`;
            if (status === 'running') {
                statusPill.textContent = 'Compiling...';
                window.expandTerminal();
            } else if (status === 'done' || status === 'success') {
                statusPill.textContent = 'Done';
            } else if (status === 'error') {
                statusPill.textContent = 'Failed';
                window.expandTerminal();
            } else {
                statusPill.textContent = 'Idle';
            }
        } else if (status === 'running') {
            window.expandTerminal();
        }
    };

    // INITIALIZATION TRIGGERS WITH RETRY POLLER
    window.loadProjectsFromBridge();
    setTimeout(() => window.loadProjectsFromBridge(), 300);
    setTimeout(() => window.loadProjectsFromBridge(), 1000);
});