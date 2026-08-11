<?php
// Defines the buttons array
$default_buttons = [
    // REFRESH
    ['refresh-btn', '<path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/>', 'location.reload()', 'Refresh', 'secondary'],
    
    // SETTINGS
    ['settings-btn', '<path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.488.488 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.58 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>', 'openSettings()', 'Settings', 'secondary'],
];

// PLUG-AND-PLAY MERGE
if(isset($plugin_buttons) && is_array($plugin_buttons)) {
    $default_buttons = array_merge($plugin_buttons, $default_buttons);
}

// Select is always last
$default_buttons[] = ['select-btn', '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>', 'cjosToggleSelectMode(true)', 'Select', 'secondary'];
?>
<div class="top-bar">
    <div class="title-container">
        <!-- Default Title -->
        <div class="title-wrapper">
            <svg class="app-logo" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 3V21M8 8V16M16 8V16M4 11V13M20 11V13" stroke-width="2.5" stroke-linecap="round"/></svg>
            <div class="bar-title">Conjure</div>
        </div>
        <!-- Selection Mode Title -->
        <div class="selection-title" id="selection-count">0 Selected</div>
    </div>
    
    <div class="actions-stack">
        <div id="header-clock"></div>
        <!-- Default Buttons -->
        <div class="action-group default-actions">
            <?php 
            $hideSelect = (isset($_COOKIE['cjos_hide_select_btn']) && $_COOKIE['cjos_hide_select_btn'] === 'true');
            foreach ($default_buttons as $btn): 
                $btnStyle = ($btn[0] === 'select-btn' && $hideSelect) ? 'style="display:none;"' : '';
            ?>
            <button class="icon-btn <?php echo $btn[4]; ?>" id="<?php echo $btn[0]; ?>" title="<?php echo $btn[3]; ?>" onclick="<?php echo $btn[2]; ?>" <?php echo $btnStyle; ?>>
                <svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><?php echo $btn[1]; ?></svg>
            </button>
            <?php endforeach; ?>
        </div>
        
        <!-- Selection Mode Done Button -->
        <div class="action-group selection-done-wrapper">
            <button id="cancel-btn" class="done-btn" onclick="cjosToggleSelectMode(false)">Done</button>
        </div>
    </div>
</div>

<style>
.title-wrapper {
    position: relative;
    z-index: 1050 !important;
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    -webkit-touch-callout: none;
    display: inline-flex !important;
    align-items: center;
    padding: 10px 16px;
    margin: -10px -16px;
}
.title-wrapper * {
    pointer-events: none !important;
}
</style>

<script>
// Global IPs definition for system-wide access
window.CJOS_IPS = {
    tailscale: <?php echo function_exists('am_get_tailscale_ip') ? json_encode(am_get_tailscale_ip()) : 'null'; ?>,
    tailscale_domain: <?php echo function_exists('am_get_tailscale_domain') ? json_encode(am_get_tailscale_domain()) : 'null'; ?>,
    lan: <?php echo function_exists('am_get_lan_ip') ? json_encode(am_get_lan_ip()) : 'null'; ?>
};

function cjosGetAlternativeAppUrls() {
    const portSuffix = window.location.port ? `:${window.location.port}` : '';
    const protocol = window.location.protocol;
    const baseDir = window.location.pathname.split('index.php')[0];
    const cleanPath = baseDir + "index.php"; // Main App entry point
    
    const urls = [];
    const ips = window.CJOS_IPS || {};
    
    urls.push({
        label: `Current (${window.location.host})`,
        url: window.location.origin + cleanPath
    });
    
    if (window.location.hostname !== 'localhost') {
        urls.push({
            label: `Localhost (localhost${portSuffix})`,
            url: `${protocol}//localhost${portSuffix}${cleanPath}`
        });
    }
    
    if (window.location.hostname !== '127.0.0.1') {
        urls.push({
            label: `127.0.0.1 (127.0.0.1${portSuffix})`,
            url: `${protocol}//127.0.0.1${portSuffix}${cleanPath}`
        });
    }
    
    if (ips && ips.tailscale_domain && window.location.hostname !== ips.tailscale_domain) {
        const hasPort = ips.tailscale_domain.indexOf(':') !== -1;
        const targetHost = hasPort ? ips.tailscale_domain : (ips.tailscale_domain + portSuffix);
        urls.push({
            label: `Tailscale HTTPS (${targetHost})`,
            url: `https://${targetHost}${cleanPath}`
        });
    }
    
    if (ips && ips.tailscale && window.location.hostname !== ips.tailscale) {
        urls.push({
            label: `Tailscale IP (${ips.tailscale}${portSuffix})`,
            url: `${protocol}//${ips.tailscale}${portSuffix}${cleanPath}`
        });
    }
    
    if (ips && ips.lan && window.location.hostname !== ips.lan) {
        urls.push({
            label: `Local LAN (${ips.lan}${portSuffix})`,
            url: `${protocol}//${ips.lan}${portSuffix}${cleanPath}`
        });
    }
    
    return urls;
}

// --- EMERGENCY RECOVERY TRIGGER ---
(function() {
    // Inline script ensures functionality even if app.js crashes
    const titleWrap = document.querySelector('.title-wrapper');
    if (!titleWrap) return;
    
    let recTimer = null;
    const start = () => {
        recTimer = setTimeout(() => {
            const shieldUrl = window.location.href.split(/[?#]/)[0].replace("index.php", "").replace(/\/$/, "") + "/index.php?shield=1";

            if (typeof window.openPicker === "function") {
                if (window.navigator.vibrate) navigator.vibrate(15);
                
                const options = [
                    { label: "🛡️ Go to System Shield", value: "go_shield" },
                    { label: "📋 Copy Shield URL", value: "copy_shield_direct" },
                    { label: "🔗 Copy App URL...", value: "copy_app_multi" },
                    { label: "📦 Go to Bunker", value: "go_bunker" }
                ];
                
                window.openPicker("System Shield Options", options, null, (val) => {
                    if (val === "go_shield") {
                        window.location.href = "index.php?shield=1";
                    } else if (val === "copy_shield_direct") {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(shieldUrl).then(() => {
                                if (window.sui && window.sui.toast) {
                                    window.sui.toast("Shield URL Copied");
                                } else {
                                    alert("Shield URL Copied!");
                                }
                            });
                        }
                    } else if (val === "copy_app_multi") {
                        const urls = cjosGetAlternativeAppUrls();
                        const copyOptions = urls.map(opt => ({
                            label: opt.label,
                            value: opt.url
                        }));
                        
                        setTimeout(() => {
                            window.openPicker("Copy App URL", copyOptions, null, (selectedUrl) => {
                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                    navigator.clipboard.writeText(selectedUrl).then(() => {
                                        if (window.sui && window.sui.toast) {
                                            window.sui.toast("App URL Copied");
                                        } else {
                                            alert("App URL Copied!");
                                        }
                                    });
                                }
                            });
                        }, 100);
                    } else if (val === "go_bunker") {
                        if (typeof window.scShowBunkerMenu === "function") {
                            window.scShowBunkerMenu();
                        } else {
                            window.location.href = "recovery.php";
                        }
                    }
                });
            } else {
                try { 
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(shieldUrl); 
                    }
                } catch(e) {}

                const msg = "Go to the Shield?\n\n(Shield URL copied to clipboard)";
                if (confirm("🛡️ SYSTEM SHIELD:\n\n" + msg)) {
                    window.location.href = "index.php?shield=1";
                }
            }
        }, 1500);
    };
    const stop = () => { if(recTimer) clearTimeout(recTimer); };

    titleWrap.addEventListener('mousedown', start);
    titleWrap.addEventListener('touchstart', start, {passive: true});
    titleWrap.addEventListener('mouseup', stop);
    titleWrap.addEventListener('mouseleave', stop);
    titleWrap.addEventListener('touchend', stop);
})();
</script>

<!-- BOTTOM BAR FOR ACTIONS -->
<div class="selection-bottom-bar">
    <button class="bar-action-btn" id="action-copy">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
    </button>
    <button class="bar-action-btn" id="action-reprocess">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M23 4v6h-6"></path><path d="M1 20v-6h6"></path><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
    </button>
    <button class="bar-action-btn danger" id="action-delete">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
    </button>
</div>