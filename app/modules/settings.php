<div class="settings-overlay shared-menu-overlay" id="settings-overlay">
    <div class="settings-sheet shared-bottom-sheet">
        <div class="settings-header">
            <div class="settings-title">Settings</div>
            <div id="settings-header-actions" style="display:flex; align-items:center; gap:12px; margin-left:auto; margin-right:12px;"></div>
            <button class="settings-close" id="settings-close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div id="settings-scroll-container">

        <?php
        function get_sec_state($id) {
            global $ui_config;
            if (isset($ui_config['sections']["cjos_sec_$id"])) {
                return $ui_config['sections']["cjos_sec_$id"];
            }
            return 'open';
        }
        $s3 = get_sec_state('sec-appear');
        ?>

        <!-- SECTION: APPEARANCE -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin: 0 24px 8px 32px; cursor:pointer;" 
             onclick="suiToggle('sec-appear', true)">
            <div style="font-size:13px; font-weight:600; text-transform:uppercase; color:var(--text-secondary);">Appearance</div>
            <span data-sui-icon="chevron" data-sui-arrow="sec-appear" data-sui-size="16" data-sui-stroke="2.5" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(<?php echo $s3=='closed' ? '-90' : '0'; ?>deg);"></span>
        </div>
        
        <div class="sui-accordion <?php echo $s3=='open' ? 'open' : ''; ?>" id="sec-appear">
            <div class="sui-accordion-inner">
                <div class="settings-group">
                    <div class="setting-item vertical"><label class="setting-label">Outer Spacing</label><input type="range" id="input-outer-margin" min="0" max="60" value="0"></div>
                    <div class="setting-item vertical"><label class="setting-label">Inner Padding</label><input type="range" id="input-inner-padding" min="0" max="60" value="0"></div>
                    <div data-sui-setting="Top Edge Style" data-sui-desc="Fade Mode vs Rounded" data-sui-id="input-fade-toggle" data-sui-onchange="handleFadeToggle(this.checked)"></div>
                    <div class="setting-item vertical" id="radius-setting"><label class="setting-label">Corner Rounding</label><input type="range" id="input-radius" min="0" max="40" value="0"></div>
                    <?php $sw = $ui_config['appearance']['sidebar_width'] ?? 27; ?>
                    <div class="setting-item vertical">
                        <label class="setting-label">Sidebar Width (<?php echo $sw; ?>vw)</label>
                        <input type="range" id="input-sidebar-width" min="15" max="50" step="0.5" value="<?php echo $sw; ?>" 
                               oninput="this.previousElementSibling.innerText = 'Sidebar Width (' + this.value + 'vw)'; document.documentElement.style.setProperty('--sidebar-width', this.value + 'vw')" 
                               onchange="updateServerUiState('appearance', 'sidebar_width', this.value); localStorage.removeItem('cjos_sidebar_width');">
                    </div>
                    <div data-sui-setting="Disable Hibernation" data-sui-desc="Keep all pages active in memory (High Usage)." data-sui-id="input-lazy-toggle" data-sui-onchange="handleLazyToggle(this.checked)"></div>
                    <div data-sui-setting="Auto Wake" data-sui-desc="Automatically hydrate pages when elements are requested." data-sui-id="input-wake-toggle" data-sui-onchange="handleAutoWakeToggle(this.checked)"></div>
                    <?php $ao = ($ui_config['appearance']['auto_open_patcher'] ?? 'false') === 'true'; ?>
                    <div data-sui-setting="Auto-Open Patcher" data-sui-desc="Expand Surgical Patcher tray on load." data-sui-id="input-patcher-auto-open" data-sui-checked="<?php echo $ao ? 'true' : 'false'; ?>" data-sui-onchange="updateServerUiState('appearance', 'auto_open_patcher', this.checked)"></div>
                </div>
            </div>
        </div>

        <!-- PLUGINS LOGIC -->
        <?php 
        $ui_hidden = $ui_config['plugins_hidden'] ?? [];
        if (!empty($all_found_plugins)) {
            $visible_plugins_html = '';
            $hidden_plugins_html = '';
            $hidden_count = 0;

            foreach ($all_found_plugins as $name) {
                $key = "plugin_" . $name;
                $p_state = $plugin_states[$name] ?? 'active';
                $is_enabled = ($p_state !== 'disabled');
                $is_dormant = ($p_state === 'dormant');
                
                $hide_key = "cjos_hide_" . $name;
                $is_hidden = isset($ui_hidden[$hide_key]) ? ($ui_hidden[$hide_key] === 'true' || $ui_hidden[$hide_key] === true) : false;
                $has_settings = $is_enabled && (isset($plugin_settings_map[$name]) || $is_dormant);
                $tray_id = "tray-" . $name;
                $arrow_id = "arrow-" . $name;
                $display_name = preg_replace('/(?<!\ )[A-Z]/', ' $0', $name);
                $row_id = "plg-row-" . $name;

                ob_start(); 
                ?>
                <div class="plugin-block <?php echo !$is_enabled ? 'is-disabled' : ($is_dormant ? 'is-dormant' : ''); ?>" id="<?php echo $row_id; ?>" data-plugin-state="<?php echo $p_state; ?>">
                    <div class="setting-item">
                        <div style="display:flex; align-items:center; gap:8px; flex:1;">
                            <button class="plugin-toggle-btn folder-assign-btn" 
                                    onclick="if(typeof openFolderPicker === 'function') openFolderPicker(this.closest('.plugin-block'))"
                                    style="margin-left:-8px; margin-right:4px; color:#C7C7CC;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:18px; height:18px; stroke-width:2.5;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                            </button>
                            <div style="display:flex; flex-direction:column;">
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <label class="setting-label" style="margin:0; font-size:15px;"><?php echo $display_name; ?></label>
                                </div>
                                <?php if(isset($plugin_descriptions[$name])): ?>
                                    <div class="plugin-description"><?php echo htmlspecialchars($plugin_descriptions[$name]); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if ($has_settings): ?>
                            <button id="<?php echo $arrow_id; ?>" class="plugin-toggle-btn" 
                                    <?php if ($is_dormant): ?>data-sui-dormant="true" data-plugin-name="<?php echo $name; ?>"<?php endif; ?>
                                    onclick="togglePluginTray('<?php echo $tray_id; ?>', '<?php echo $arrow_id; ?>')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:20px; height:20px; stroke-width:2.5;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </button>
                            <?php endif; ?>
                        </div>
                        <span class="po-disabled-label"><?php echo $is_dormant ? 'Dormant' : 'Disabled'; ?></span>
                        <div data-sui-switch="true" data-sui-checked="<?php echo $is_enabled ? 'true' : 'false'; ?>" data-sui-onchange="togglePluginState('<?php echo $name; ?>', this.checked ? 'active' : 'disabled')"></div>
                    </div>
                    <?php if ($has_settings): ?>
                    <div id="<?php echo $tray_id; ?>" class="plugin-tray" <?php if ($is_dormant): ?>data-sui-dormant="true" data-dormant-loaded="false"<?php endif; ?>>
                        <?php echo $plugin_settings_map[$name] ?? ''; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php 
                $row_html = ob_get_clean();
                if ($is_hidden) { $hidden_plugins_html .= $row_html; $hidden_count++; } else { $visible_plugins_html .= $row_html; }
            }
            ?>

            <div class="group-title">Plugins</div>
            <div class="settings-group">
                <div id="visible-plugins-container"><?php echo $visible_plugins_html; ?></div>
                <div id="hidden-section-divider" class="setting-item" onclick="toggleHiddenSection()" 
                     style="justify-content:center; cursor:pointer; background:var(--btn-bg); border-top:1px solid var(--border-color); display: <?php echo $hidden_count > 0 ? 'flex' : 'none'; ?>;">
                    <div style="font-size:13px; font-weight:600; color:var(--text-secondary); display:flex; align-items:center; gap:6px;">
                        <span id="hidden-label">Show <?php echo $hidden_count; ?> Hidden Plugins</span>
                        <svg id="hidden-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:14px; height:14px; stroke-width:2.5; transition:transform 0.35s;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </div>
                </div>
                <div id="hidden-plugins-wrapper" class="sui-accordion" style="transform: translateZ(0); backface-visibility: hidden;">
                    <div id="hidden-plugins-container" class="sui-accordion-inner" style="background:var(--btn-bg); box-shadow:inset 0 4px 10px rgba(0,0,0,0.05);">
                        <?php echo $hidden_plugins_html; ?>
                    </div>
                </div>
            </div>
        <?php } ?>
        
        <script>
            // In-place UI Updater for Plugin Rows (Batch Mode)
            window.updatePluginRowUI = function(name, stateKey) {
                const row = document.getElementById('plg-row-' + name);
                if (!row) return;

                const isEnabled = (stateKey !== 'disabled');
                const isDormant = (stateKey === 'dormant');

                // Update dataset & state classes
                row.setAttribute('data-plugin-state', stateKey);
                row.classList.toggle('is-disabled', !isEnabled);
                row.classList.toggle('is-dormant', isDormant);

                // Remove legacy dormant badge if present
                const legacyBadge = row.querySelector('.po-dormant-badge');
                if (legacyBadge) legacyBadge.remove();

                // Update Disabled/Dormant Label Text
                const statusLabel = row.querySelector('.po-disabled-label');
                if (statusLabel) {
                    statusLabel.textContent = isDormant ? 'Dormant' : 'Disabled';
                }

                // Update Switch State
                const switchEl = row.querySelector('[data-sui-switch]');
                if (switchEl) {
                    switchEl.setAttribute('data-sui-checked', isEnabled ? 'true' : 'false');
                    const input = switchEl.querySelector('input[type="checkbox"]');
                    if (input) input.checked = isEnabled;
                }

                // Toggle Settings Arrow Button
                const arrowBtn = document.getElementById('arrow-' + name);
                const tray = document.getElementById('tray-' + name);
                if (arrowBtn) {
                    if (isDormant) {
                        arrowBtn.setAttribute('data-sui-dormant', 'true');
                        arrowBtn.setAttribute('data-plugin-name', name);
                        arrowBtn.style.display = '';
                    } else {
                        arrowBtn.removeAttribute('data-sui-dormant');
                        arrowBtn.removeAttribute('data-plugin-name');
                        arrowBtn.style.display = isEnabled ? '' : 'none';
                    }
                }
                if (tray && isDormant) {
                    tray.setAttribute('data-sui-dormant', 'true');
                }
            };

            // Dynamic "Apply & Reload" Header Button
            window.showSettingsReloadPrompt = function() {
                let btn = document.getElementById('btn-settings-reload');
                if (!btn) {
                    const actionsContainer = document.getElementById('settings-header-actions');
                    if (actionsContainer) {
                        btn = document.createElement('button');
                        btn.id = 'btn-settings-reload';
                        btn.style.cssText = 'background:var(--primary); color:var(--primary-text, #fff); border:none; padding:6px 12px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; box-shadow:0 2px 8px rgba(0,0,0,0.15); transition:all 0.2s;';
                        btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:14px; height:14px; stroke-width:2.5;"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg> Apply & Reload`;
                        btn.onclick = () => location.reload();
                        actionsContainer.prepend(btn);
                    }
                }
                if (btn) btn.style.display = 'flex';
            };

            function updateHiddenSectionUI() {
                const hiddenWrapper = document.getElementById("hidden-plugins-wrapper");
                const divider = document.getElementById("hidden-section-divider");
                const label = document.getElementById("hidden-label");
                
                if (!hiddenWrapper || !divider || !label) return;

                // Stable Count: Count all plugin blocks inside the hidden wrapper.
                // This is immune to folder nesting or search filtering.
                const count = hiddenWrapper.querySelectorAll(".plugin-block").length;
                
                if (count === 0) {
                    divider.style.display = "none";
                    hiddenWrapper.classList.remove("open");
                } else {
                    divider.style.display = "flex";
                    const prefix = hiddenWrapper.classList.contains('open') ? "Hide " : "Show ";
                    label.textContent = prefix + count + " Hidden Plugins";
                }
            }

            function toggleHiddenSection() {
                const wrap = document.getElementById('hidden-plugins-wrapper');
                const arrow = document.getElementById('hidden-arrow');
                if (!wrap || !arrow) return;

                const isOpen = wrap.classList.contains('open');
                if (!isOpen) {
                    wrap.classList.add('open');
                    arrow.style.transform = 'rotate(0deg)';
                } else {
                    wrap.classList.remove('open');
                    arrow.style.transform = 'rotate(-90deg)';
                }
                
                updateHiddenSectionUI();
                if (window.sui && window.sui.haptic) window.sui.haptic('light');
            }

            // Auto-Open Patcher Logic (Desktop Only)
            window.addEventListener('load', () => {
                const autoOpen = <?php echo ($ui_config['appearance']['auto_open_patcher'] ?? 'false') === 'true' ? 'true' : 'false'; ?>;
                const isDesktop = window.innerWidth >= 1024;
                
                if (autoOpen && isDesktop) {
                    setTimeout(() => {
                        if (typeof togglePluginTray === 'function') {
                            const tray = document.getElementById('tray-FilePatchManager');
                            if (tray && !tray.classList.contains('open')) {
                                togglePluginTray('tray-FilePatchManager', 'arrow-FilePatchManager');
                            }
                        }
                    }, 800);
                }
            });
        </script>
        </div>
    </div>
</div>