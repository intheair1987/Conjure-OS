window.App = {
    longPressTimer: null,
    isDragging: false,
    defaultDomain: 'conjure.com',
    pollInterval: null, // Track globally to prevent background memory leaks
    touchStartX: 0,
    touchStartY: 0,

    appendLog: function(msg, type = 'info') {
        const consoleBody = document.getElementById('telemetry-console');
        if (!consoleBody) return;
        const div = document.createElement('div');
        div.className = `log-line log-${type}`;
        
        const now = new Date();
        const timeStr = now.getHours().toString().padStart(2, '0') + ':' + 
                        now.getMinutes().toString().padStart(2, '0') + ':' + 
                        now.getSeconds().toString().padStart(2, '0');
                        
        div.innerText = `[${timeStr}] ${msg}`;
        consoleBody.appendChild(div);
        consoleBody.scrollTop = consoleBody.scrollHeight;
    },

    showAlert: function(title, message) {
        document.getElementById('orbit-alert-title').innerText = title;
        document.getElementById('orbit-alert-message').innerText = message;
        document.getElementById('orbit-alert-modal').classList.add('active');
    },

    showConfirm: function(title, message, confirmText, confirmColor, onConfirm, onCancel) {
        document.getElementById('orbit-confirm-title').innerText = title;
        document.getElementById('orbit-confirm-message').innerText = message;
        
        const okBtn = document.getElementById('orbit-confirm-ok-btn');
        okBtn.innerText = confirmText;
        okBtn.style.background = confirmColor;
        
        const modal = document.getElementById('orbit-confirm-modal');
        
        const cleanup = () => {
            modal.classList.remove('active');
            window._orbitConfirmCancel = null;
        };
        
        window._orbitConfirmCancel = () => {
            cleanup();
            if (onCancel) onCancel();
        };
        
        document.getElementById('orbit-confirm-cancel-btn').onclick = window._orbitConfirmCancel;
        
        okBtn.onclick = () => {
            cleanup();
            if (onConfirm) onConfirm();
        };
        
        modal.classList.add('active');
    },

    isSelectionMode: false,
    selectedInstances: new Set(),

    enterSelectionMode: function(e, initialId) {
        if (e) e.stopPropagation();
        
        const sheetWasActive = document.getElementById('action-sheet').classList.contains('active');
        this.closeOverlays(false, true); // skipHistory = true
        
        this.isSelectionMode = true;
        this.selectedInstances = new Set();
        this.toggleSelection(initialId);
        
        // Replace or push state to cleanly swap history without triggering popstate
        if (sheetWasActive && history.state && history.state.modal === 'action-sheet') {
            history.replaceState({ mode: 'selection' }, '', '');
        } else {
            history.pushState({ mode: 'selection' }, '', '');
        }
        
        document.getElementById('fab-batch-deploy').style.display = 'flex';
    },

    pushModalState: function(modalName) {
        setTimeout(() => {
            if (!history.state || history.state.modal !== modalName) {
                history.pushState({ modal: modalName }, '', '');
            }
        }, 50);
    },

    exitSelectionMode: function(isPopState = false, skipHistory = false) {
        this.isSelectionMode = false;
        this.selectedInstances.clear();
        document.querySelectorAll('.instance-card.selected').forEach(c => c.classList.remove('selected'));
        
        const batchFab = document.getElementById('fab-batch-deploy');
        if (batchFab) batchFab.style.display = 'none';
        
        // Revert history state if exited manually to prevent stack pollution
        if (!isPopState && !skipHistory) {
            if (history.state && history.state.mode === 'selection') {
                history.back();
            }
        }
    },

    toggleSelection: function(id) {
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        if (!card) return;
        
        if (this.selectedInstances.has(id)) {
            this.selectedInstances.delete(id);
            card.classList.remove('selected');
        } else {
            this.selectedInstances.add(id);
            card.classList.add('selected');
        }
        
        if (this.selectedInstances.size === 0) {
            this.exitSelectionMode();
        }
    },

    init: function() {
        // Override native alert to enforce Zero Native UI mandate globally
        window.alert = (msg) => {
            this.showAlert("Notice", msg);
        };

        this.bindGridEvents();
        this.bindInputSanitizers();
        this.bindFabEvents();
        
        // Listen to browser back gestures to exit selection mode and modals
        window.addEventListener('popstate', (e) => {
            if (this.isSelectionMode) {
                this.exitSelectionMode(true);
            } else if (document.getElementById('action-sheet') && document.getElementById('action-sheet').classList.contains('active')) {
                this.closeOverlays(true);
            } else if (document.getElementById('settings-modal') && document.getElementById('settings-modal').classList.contains('active')) {
                this.closeSettings(true);
            } else if (document.getElementById('snapshots-modal') && document.getElementById('snapshots-modal').classList.contains('active')) {
                this.closeSnapshotsModal(true);
            } else if (document.getElementById('deploy-modal') && document.getElementById('deploy-modal').classList.contains('active')) {
                this.closeDeployWizard(true);
            }
        });
        
        // Close custom select dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (this.isSelectionMode && !e.target.closest('.instance-card') && !e.target.closest('.orbit-fab-secondary') && !e.target.closest('.orbit-modal') && !e.target.closest('.action-sheet')) {
                this.exitSelectionMode();
            }
            if (!e.target.closest('.custom-select-wrapper')) {
                document.querySelectorAll('.custom-select-dropdown.active').forEach(d => d.classList.remove('active'));
            }
        });

        // Bind dynamic setup command regenerators
        const watchInputs = ['set-domain', 'set-vps-ip', 'set-receiver-secret'];
        watchInputs.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', () => this.updateManualProvisionUI());
            }
        });
    },

    hijackSelects: function(selectId) {
        const select = document.getElementById(selectId);
        if (!select) return;
        
        // Clean up previous wrapper if it exists (for dynamic re-renders)
        if (select.nextElementSibling && select.nextElementSibling.classList.contains('custom-select-wrapper')) {
            select.nextElementSibling.remove();
        }
        
        select.style.display = 'none'; // Hide native select
        
        const wrapper = document.createElement('div');
        wrapper.className = 'custom-select-wrapper';
        
        const display = document.createElement('div');
        display.className = 'custom-select-display';
        const activeText = select.options[select.selectedIndex]?.innerHTML || 'Select an option';
        display.innerHTML = `<span>${activeText}</span> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>`;
        
        const dropdown = document.createElement('div');
        dropdown.className = 'custom-select-dropdown';
        
        Array.from(select.options).forEach((opt, idx) => {
            if (opt.disabled) return;
            const optionDiv = document.createElement('div');
            optionDiv.className = 'custom-select-option';
            optionDiv.innerHTML = opt.innerHTML; // Preserves emoji icons
            
            optionDiv.onclick = () => {
                select.selectedIndex = idx;
                display.querySelector('span').innerHTML = opt.innerHTML;
                dropdown.classList.remove('active');
                // Trigger change event if needed
                select.dispatchEvent(new Event('change'));
            };
            dropdown.appendChild(optionDiv);
        });
        
        display.onclick = () => {
            // Close others first
            document.querySelectorAll('.custom-select-dropdown.active').forEach(d => {
                if (d !== dropdown) d.classList.remove('active');
            });
            dropdown.classList.toggle('active');
        };
        
        wrapper.appendChild(display);
        wrapper.appendChild(dropdown);
        select.parentNode.insertBefore(wrapper, select.nextSibling);
    },

    bindGridEvents: function() {
        const cards = document.querySelectorAll('.instance-card');
        
        cards.forEach(card => {
            const id = card.getAttribute('data-id');
            
            // Gold Standard: Block Android/iOS context menus from hijacking long press timers
            card.addEventListener('contextmenu', (e) => e.preventDefault());
            
            // Unified Pointer events for long press
            card.addEventListener('pointerdown', (e) => this.startPress(e, id, card));
            card.addEventListener('pointerup', (e) => this.endPress(e, id, card));
            card.addEventListener('pointermove', (e) => this.handleMove(e));
            card.addEventListener('pointercancel', () => this.cancelPress());
            card.addEventListener('pointerleave', () => this.cancelPress());
        });
    },

    bindInputSanitizers: function() {
        const prefixInputs = ['deploy-prefix', 'edit-instance-prefix'];
        prefixInputs.forEach(id => {
            const input = document.getElementById(id);
            if (!input) return;
            
            input.addEventListener('input', function() {
                // Convert spaces to hyphens on the fly
                let clean = this.value.replace(/\s+/g, '-');
                // Strip emojis, punctuation, and all special characters except alpha, numeric, underscore, hyphen
                clean = clean.replace(/[^a-zA-Z0-9_-]/g, '');
                // Standardize to lowercase for professional URL formats
                clean = clean.toLowerCase();
                
                if (this.value !== clean) {
                    this.value = clean;
                }
            });
        });

        // Bind change listener on template select to dynamically update suggestions
        const templateSelect = document.getElementById('deploy-template');
        if (templateSelect) {
            templateSelect.addEventListener('change', () => {
                this.fetchNameSuggestion(templateSelect.value);
                const container = document.getElementById('deploy-options-container');
                if (container) container.innerHTML = ''; // Force re-render of checkboxes to update templateName param
                this.toggleOverwriteOptions('deploy');
            });
        }

        // Bind real-time Chinese-safe & emoji-blocking sanitizer on the Backup Note input
        const noteInput = document.getElementById('backup-note-input');
        if (noteInput) {
            noteInput.addEventListener('input', function() {
                // Retain alphanumeric, spaces, underscores, hyphens, and CJK (Chinese) Unicode blocks
                const clean = this.value.replace(/[^a-zA-Z0-9_\s\-\u4e00-\u9fa5]/gu, '');
                if (this.value !== clean) {
                    this.value = clean;
                }
            });
        }
    },

    startPress: function(e, id, card) {
        this.isDragging = false;

        if (this.isSelectionMode) {
            return;
        }

        if (e.target.closest('.icon-btn-small')) return; // Ignore if clicking the dots
        
        const isUrlChip = !!e.target.closest('.card-url');
        if (isUrlChip) return; // URL chips don't need long-press actions anymore
        
        // Track starting coordinates
        this.touchStartX = e.clientX;
        this.touchStartY = e.clientY;
        
        this.longPressTimer = setTimeout(() => {
            this.isDragging = true; // Prevent tap event
            if (navigator.vibrate) navigator.vibrate(50);
            App.openActionSheet(id);
        }, 600);
    },

    handleMove: function(e) {
        if (!this.longPressTimer) return;
        
        const currentX = e.clientX;
        const currentY = e.clientY;
        
        // Calculate hypotenuse distance of micro-movement
        const dist = Math.sqrt(
            Math.pow(currentX - this.touchStartX, 2) + 
            Math.pow(currentY - this.touchStartY, 2)
        );
        
        // If movement exceeds the 10px slop threshold, assume scrolling and cancel
        if (dist > 10) {
            this.cancelPress();
        }
    },

    cancelPress: function() {
        this.isDragging = true;
        if (this.longPressTimer) clearTimeout(this.longPressTimer);
    },

    endPress: function(e, id, card) {
        if (this.longPressTimer) clearTimeout(this.longPressTimer);
        
        if (this.isSelectionMode) {
            this.toggleSelection(id);
            return;
        }

        if (e.target.closest('.icon-btn-small')) return;
        
        if (!this.isDragging) {
            if (e.target.closest('.card-url')) {
                const chip = e.target.closest('.card-url');
                if (chip.classList.contains('copied')) return; // Guard 1: Instantly exit if browser double-fires click events
                
                const span = chip.querySelector('span');
                const origText = span.innerText;
                
                // Guard 2: Read URL from immutable data attributes instead of mutable HTML text
                let urlText = card.getAttribute('data-subdomain');
                
                // Normalize and prefix
                urlText = urlText.replace('https://', '').replace('http://', '');
                const fullUrl = 'https://' + urlText;
                
                // Guard 3: Pass null to prevent innerHTML text replacement, keeping SVGs intact
                App.copyToClipboard(fullUrl, null);
                
                chip.classList.add('copied');
                span.innerText = "Copied! ✅";
                if (navigator.vibrate) navigator.vibrate(30);
                
                setTimeout(() => {
                    span.innerText = origText;
                    chip.classList.remove('copied');
                }, 1200);
            } else {
                App.launchInstance(id, card);
            }
        }
        this.isDragging = false;
    },

    launchInstance: function(id, card) {
        const name = card.getAttribute('data-name');
        let url = card.getAttribute('data-subdomain');
        this.openAppViewer(name, url);
    },

    openInNewTab: function(id) {
        this.closeOverlays();
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        if (!card) return;
        let url = card.getAttribute('data-subdomain');
        window.open(url, '_blank');
    },

    openIframeInNewTab: function() {
        const iframe = document.getElementById('app-viewer-iframe');
        if (iframe && iframe.src !== 'about:blank') {
            window.open(iframe.src, '_blank');
        }
    },

    openEditInstanceModal: function(id) {
        this.closeOverlays();
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        if (!card) return;
        
        const name = card.getAttribute('data-name');
        const subdomain = card.getAttribute('data-subdomain');
        const templateName = card.querySelector('.card-subtitle').innerText;
        const isHome = card.getAttribute('data-is-home') === '1';
        
        // Parse Route Prefix from subdomain
        let prefix = '';
        if (isHome) {
            prefix = '_home';
        } else if (subdomain && subdomain !== 'https://') {
            const host = subdomain.replace('https://', '');
            prefix = host.split('.')[0];
        }
        
        // Ensure defaultDomain is parsed if settings modal hasn't opened yet
        fetch('index.php?ajax=get_settings')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.defaultDomain = data.default_domain || 'conjure.com';
                    
                    document.getElementById('edit-instance-id').value = id;
                    document.getElementById('edit-instance-template').value = templateName;
                    document.getElementById('edit-instance-name').value = name;
                    document.getElementById('edit-is-home').checked = isHome;
                    
                    const prefixInput = document.getElementById('edit-instance-prefix');
                    prefixInput.value = prefix;
                    
                    if (isHome) {
                        prefixInput.disabled = true;
                        prefixInput.style.opacity = '0.6';
                    } else {
                        prefixInput.disabled = false;
                        prefixInput.style.opacity = '1';
                    }
                    
                    this.updateEditRoutePreview();
                    document.getElementById('edit-instance-modal').classList.add('active');
                }
            });
    },

    closeEditInstanceModal: function() {
        document.getElementById('edit-instance-modal').classList.remove('active');
    },

    openDirectoryModal: function(id) {
        this.closeOverlays();
        const list = document.getElementById('directory-app-list');
        list.innerHTML = `<div style="text-align:center; color: var(--text-secondary); padding: 10px;">Loading apps...</div>`;
        document.getElementById('directory-modal').classList.add('active');

        fetch('index.php?ajax=get_directory_config')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.instances.length === 0) {
                        list.innerHTML = `<div style="text-align:center; color: var(--text-secondary); padding: 10px; font-size: 13px;">No other active applications found.<br>Deploy some apps first!</div>`;
                        return;
                    }
                    
                    let html = '';
                    data.instances.forEach(inst => {
                        const checked = inst.enabled ? 'checked' : '';
                        html += `
                            <label style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: rgba(0,0,0,0.2); padding: 12px 16px; border-radius: 8px; cursor: pointer; transition: background 0.2s ease;">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <span style="font-size: 14px; font-weight: 600; color: var(--text-primary);">${inst.name}</span>
                                    <span style="font-size: 11px; color: var(--text-secondary); font-family: monospace;">${inst.subdomain}</span>
                                </div>
                                <input type="checkbox" class="directory-app-checkbox" value="${inst.id}" ${checked} style="width: 18px; height: 18px; accent-color: var(--primary-accent); cursor: pointer;">
                            </label>
                        `;
                    });
                    list.innerHTML = html;
                } else {
                    list.innerHTML = `<div style="text-align:center; color: var(--danger); padding: 10px;">Error loading apps.</div>`;
                }
            })
            .catch(err => {
                list.innerHTML = `<div style="text-align:center; color: var(--danger); padding: 10px;">Network Error</div>`;
            });
    },

    closeDirectoryModal: function() {
        document.getElementById('directory-modal').classList.remove('active');
    },

    saveDirectoryConfig: function() {
        const checkboxes = document.querySelectorAll('.directory-app-checkbox');
        const enabledIds = [];
        checkboxes.forEach(cb => {
            if (cb.checked) enabledIds.push(parseInt(cb.value));
        });

        const btn = document.getElementById('btn-save-directory');
        const origText = btn.innerText;
        btn.innerText = "Saving locally...";
        btn.disabled = true;

        fetch('index.php?ajax=save_directory_config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ enabled_ids: enabledIds })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                btn.innerText = "Syncing with cloud...";
                
                // Identify the LaunchSite instance ID to trigger automatic push-code sync
                const cards = document.querySelectorAll('.instance-card');
                let launchSiteId = null;
                cards.forEach(c => {
                    const template = c.querySelector('.card-subtitle').innerText;
                    if (template === 'LaunchSite') {
                        launchSiteId = c.getAttribute('data-id');
                    }
                });
                
                if (launchSiteId) {
                    btn.innerText = "Sync Initiated!";
                    setTimeout(() => {
                        this.closeDirectoryModal();
                        App.deployUpdate(launchSiteId);
                    }, 800);
                } else {
                    btn.innerText = "Saved locally! ✅";
                    setTimeout(() => {
                        this.closeDirectoryModal();
                        btn.innerText = origText;
                        btn.disabled = false;
                    }, 1000);
                }
            } else {
                alert("Save Failed: " + data.error);
                btn.innerText = origText;
                btn.disabled = false;
            }
        })
        .catch(err => {
            console.error(err);
            alert("Network Error");
            btn.innerText = origText;
            btn.disabled = false;
        });
    },

    updateEditRoutePreview: function() {
        const isHome = document.getElementById('edit-is-home').checked;
        const prefix = document.getElementById('edit-instance-prefix').value.trim() || 'app';
        const preview = document.getElementById('edit-instance-preview');
        
        if (isHome) {
            preview.innerHTML = `<span>🔗 https://${this.defaultDomain}</span>`;
        } else {
            preview.innerHTML = `<span>🔗 https://${prefix}.${this.defaultDomain}</span>`;
        }
    },

    toggleHomeAppDeploy: function() {
        const checkbox = document.getElementById('deploy-is-home');
        const prefixInput = document.getElementById('deploy-prefix');
        
        if (checkbox.checked) {
            prefixInput.disabled = true;
            prefixInput.value = '_home';
            prefixInput.style.opacity = '0.6';
        } else {
            prefixInput.disabled = false;
            prefixInput.value = '';
            prefixInput.style.opacity = '1';
        }
        this.updateRoutePreview();
    },

    getSelectiveOptionsHTML: function(prefix, templateName) {
        const isDbDefaultChecked = prefix === 'deploy' ? 'checked' : '';
        return `
            <div id="${prefix}-overwrite-options" style="display: flex; flex-direction: column; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.1); text-align: left;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: not-allowed; font-size: 13px; color: var(--text-secondary); opacity: 0.6;" title="Application code is always deployed.">
                    <input type="checkbox" checked disabled style="accent-color: var(--text-secondary); cursor: not-allowed; width: 14px; height: 14px;">
                    Include Application Code (Mandatory)
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: var(--text-secondary);">
                    <input type="checkbox" id="${prefix}-inc-db" ${isDbDefaultChecked} style="accent-color: var(--primary-accent); width: 14px; height: 14px;">
                    Include SQLite Databases & Data Folder
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: var(--text-secondary);">
                    <input type="checkbox" id="${prefix}-inc-private" style="accent-color: var(--danger); width: 14px; height: 14px;">
                    Include Secrets (*-private.json)
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: var(--text-secondary);">
                    <input type="checkbox" id="${prefix}-inc-ignored" onchange="App.toggleIgnorePreview('${prefix}', '${templateName}')" style="accent-color: var(--warning); width: 14px; height: 14px;">
                    Include Ignored Files (.orbitignore)
                </label>
                <div id="${prefix}-orbitignore-preview" style="display: none; margin-top: 4px; padding-left: 24px;"></div>
            </div>
        `;
    },

    toggleOverwriteOptions: function(prefix) {
        const isChecked = document.getElementById(`${prefix}-overwrite`).checked;
        const container = document.getElementById(`${prefix}-options-container`);
        const template = document.getElementById('deploy-template').value;
        
        if (container) {
            if (isChecked) {
                if (container.innerHTML.trim() === '') {
                    container.innerHTML = this.getSelectiveOptionsHTML(prefix, template);
                }
                container.style.display = 'block';
            } else {
                container.style.display = 'none';
            }
        }
    },

    toggleIgnorePreview: function(prefix, template) {
        const isIgnoredChecked = document.getElementById(`${prefix}-inc-ignored`).checked;
        const previewDiv = document.getElementById(`${prefix}-orbitignore-preview`);
        if (!previewDiv) return;
        
        if (isIgnoredChecked) {
            previewDiv.style.display = 'block';
            previewDiv.innerHTML = '<small style="color:var(--text-secondary);">Loading rules...</small>';
            
            fetch(`index.php?ajax=get_orbitignore&template=${encodeURIComponent(template)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        if (data.rules && data.rules.length > 0) {
                            previewDiv.innerHTML = `<div style="background:rgba(0,0,0,0.3); padding:8px; border-radius:8px; font-family:monospace; font-size:11px; color:var(--warning); max-height:120px; overflow-y:auto; overscroll-behavior:contain;">${data.rules.join('<br>')}</div>`;
                        } else {
                            previewDiv.innerHTML = '<small style="color:var(--text-secondary);">No .orbitignore file found for this template.</small>';
                        }
                    }
                })
                .catch(err => {
                    previewDiv.innerHTML = '<small style="color:var(--danger);">Failed to load rules.</small>';
                });
        } else {
            previewDiv.style.display = 'none';
        }
    },

    submitEditInstance: function() {
        const id = document.getElementById('edit-instance-id').value;
        const payload = {
            id: id,
            name: document.getElementById('edit-instance-name').value.trim(),
            route_prefix: document.getElementById('edit-instance-prefix').value.trim()
        };
        
        if (!payload.name || !payload.route_prefix) {
            alert("Please fill out all fields.");
            return;
        }

        const btn = document.getElementById('btn-save-instance');
        const origText = btn.innerText;
        btn.innerText = "Saving Changes...";
        btn.disabled = true;

        fetch('index.php?ajax=edit_instance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                btn.innerText = "Saved! Reloading...";
                setTimeout(() => {
                    this.closeEditInstanceModal();
                    location.reload();
                }, 1000);
            } else {
                alert("Edit Failed: " + data.error);
                btn.innerText = origText;
                btn.disabled = false;
            }
        })
        .catch(err => {
            console.error(err);
            alert("Network error.");
            btn.innerText = origText;
            btn.disabled = false;
        });
    },

    openActionSheet: function(id) {
        const overlay = document.getElementById('orbit-overlay');
        const sheet = document.getElementById('action-sheet');
        const content = document.getElementById('action-sheet-content');
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        const templateName = card ? card.querySelector('.card-subtitle').innerText : '';
        
        let directoryBtn = '';
        if (templateName === 'LaunchSite') {
            directoryBtn = `
            <div class="sheet-item" onclick="App.openDirectoryModal(${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                Configure Public Directory
            </div>
            `;
        }
        
        // Build Action Sheet HTML
        content.innerHTML = `
            <div class="sheet-item" onclick="App.enterSelectionMode(event, ${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                Select
            </div>
            <div class="sheet-item" onclick="App.openInNewTab(${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg>
                Open in New Tab
            </div>
            ${directoryBtn}
            <div class="sheet-item" onclick="App.deployUpdate(${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                Deploy Update (Push Code)
            </div>
            <div class="sheet-item danger" onclick="App.confirmHardSync(${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21h5v-5"/></svg>
                Hard Sync (Wipe & Deploy)
            </div>
            <div class="sheet-item" onclick="App.runDiagnostics(${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Run Diagnostics (Get Debug Info)
            </div>
            <div class="sheet-item" onclick="App.openBackupManager(${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                Manage Backups
            </div>
            <div class="sheet-item" onclick="App.toggleMaintenance(${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m14 7 3 3-3 3 3 3-3 3-3-3-3 3-3-3 3-3-3-3 3-3-3-3 3-3 3 3Z"/></svg>
                Toggle Maintenance
            </div>
            <div class="sheet-item" onclick="App.openAccessModal(${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                Manage Access (Zero Trust)
            </div>
            <div class="sheet-item" onclick="App.openEditInstanceModal(${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                Edit Instance
            </div>
            <div class="sheet-item danger" onclick="App.deleteInstance(${id})">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                Delete Instance
            </div>
        `;
        
        overlay.classList.add('active');
        sheet.classList.add('active');
        
        this.pushModalState('action-sheet');
    },

    closeOverlays: function(isPopState = false, skipHistory = false) {
        const overlay = document.getElementById('orbit-overlay');
        const sheet = document.getElementById('action-sheet');
        const wasActive = sheet.classList.contains('active');
        
        overlay.classList.remove('active');
        sheet.classList.remove('active');
        
        if (wasActive && !isPopState && !skipHistory) {
            if (history.state && history.state.modal === 'action-sheet') {
                history.back();
            }
        }
    },

    openSettings: function() {
        this.closeOverlays();
        fetch('index.php?ajax=get_settings')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('set-domain').value = data.default_domain || '';
                    document.getElementById('set-notice').value = data.admin_notice || '';
                    document.getElementById('set-vps-ip').value = data.vps_ip || '';
                    document.getElementById('set-cf-token').value = data.cf_token || '';
                    document.getElementById('set-do-token').value = data.do_token || '';
                    document.getElementById('set-cf-tunnel-mode').checked = data.cloudflare_tunnel_mode == 1;
                    window._cf_tunnel_token = data.cf_tunnel_token || '';
                    
                    // SECURE PERSISTENCE CHECK: Only auto-generate a secret if the database returned a completely blank value
                    if (!data.receiver_secret || data.receiver_secret.trim() === "") {
                        data.receiver_secret = this.generateRandomString(24);
                    }
                    document.getElementById('set-receiver-secret').value = data.receiver_secret;
                    
                    // Pre-populate our off-screen textareas with the compiled configs for secure iOS synchronous copying
                    document.getElementById('hidden-nginx').value = data.nginx_config || '';
                    document.getElementById('nginx-editor-area').value = data.nginx_config || '';
                    document.getElementById('hidden-receiver').value = data.receiver_code || '';
                    
                    // Reset accordions view on open
                    document.getElementById('settings-acc-body').style.display = 'none';
                    document.getElementById('settings-acc-arrow').style.transform = 'rotate(0deg)';
                    document.getElementById('setup-acc-body').style.display = 'none';
                    document.getElementById('setup-acc-arrow').style.transform = 'rotate(0deg)';
                    document.getElementById('autodeploy-acc-body').style.display = 'none';
                    document.getElementById('autodeploy-acc-arrow').style.transform = 'rotate(0deg)';
                    document.getElementById('nginx-acc-body').style.display = 'none';
                    document.getElementById('nginx-acc-arrow').style.transform = 'rotate(0deg)';

                    this.updateManualProvisionUI();
                    document.getElementById('settings-modal').classList.add('active');
                    this.pushModalState('settings');
                }
            })
            .catch(err => console.error(err));
    },

    generateRandomString: function(length) {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        for (let i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    },

    generateRandomSecret: function() {
        document.getElementById('set-receiver-secret').value = this.generateRandomString(24);
        this.updateManualProvisionUI();
    },

    toggleNginxAccordion: function() {
        const body = document.getElementById('nginx-acc-body');
        const arrow = document.getElementById('nginx-acc-arrow');
        if (body.style.display === 'none') {
            body.style.display = 'flex';
            arrow.style.transform = 'rotate(180deg)';
        } else {
            body.style.display = 'none';
            arrow.style.transform = 'rotate(0deg)';
        }
    },

    saveNginxEditor: function(btn) {
        const config = document.getElementById('nginx-editor-area').value;
        const origText = btn.innerText;
        btn.innerText = "Saving...";
        btn.disabled = true;

        fetch('index.php?ajax=save_nginx', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ config: config })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('hidden-nginx').value = config;
                btn.innerText = "Saved! ✅";
            } else {
                alert("Failed to save: " + data.error);
                btn.innerText = "Error ❌";
            }
            setTimeout(() => { btn.innerText = origText; btn.disabled = false; }, 2000);
        }).catch(err => {
            btn.innerText = "Network Error";
            setTimeout(() => { btn.innerText = origText; btn.disabled = false; }, 2000);
        });
    },

    pullNginxEditor: function(btn) {
        const origText = btn.innerText;
        btn.innerText = "Pulling...";
        btn.disabled = true;

        fetch('index.php?ajax=pull_nginx')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.config) {
                document.getElementById('nginx-editor-area').value = data.config;
                document.getElementById('hidden-nginx').value = data.config;
                btn.innerText = "Pulled! ✅";
            } else {
                alert("Failed to pull: " + (data.error || "Unknown error"));
                btn.innerText = "Error ❌";
            }
            setTimeout(() => { btn.innerText = origText; btn.disabled = false; }, 2000);
        }).catch(err => {
            btn.innerText = "Network Error";
            setTimeout(() => { btn.innerText = origText; btn.disabled = false; }, 2000);
        });
    },

    resetNginxEditor: function(btn) {
        if (!confirm("Are you sure you want to reset your local Nginx configuration to the default template? This will overwrite your local changes (but won't affect the VPS until you push).")) return;
        
        const origText = btn.innerText;
        btn.innerText = "Resetting...";
        btn.disabled = true;

        fetch('index.php?ajax=reset_nginx')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.config) {
                document.getElementById('nginx-editor-area').value = data.config;
                document.getElementById('hidden-nginx').value = data.config;
                btn.innerText = "Reset! ✅";
            } else {
                alert("Failed to reset: " + (data.error || "Unknown error"));
                btn.innerText = "Error ❌";
            }
            setTimeout(() => { btn.innerText = origText; btn.disabled = false; }, 2000);
        }).catch(err => {
            btn.innerText = "Network Error";
            setTimeout(() => { btn.innerText = origText; btn.disabled = false; }, 2000);
        });
    },

    toggleSettingsAccordion: function() {
        const body = document.getElementById('settings-acc-body');
        const arrow = document.getElementById('settings-acc-arrow');
        if (body.style.display === 'none') {
            body.style.display = 'flex';
            arrow.style.transform = 'rotate(180deg)';
        } else {
            body.style.display = 'none';
            arrow.style.transform = 'rotate(0deg)';
        }
    },

    toggleSetupAccordion: function() {
        const body = document.getElementById('setup-acc-body');
        const arrow = document.getElementById('setup-acc-arrow');
        if (body.style.display === 'none') {
            body.style.display = 'flex';
            arrow.style.transform = 'rotate(180deg)';
        } else {
            body.style.display = 'none';
            arrow.style.transform = 'rotate(0deg)';
        }
    },

    toggleAutoDeployAccordion: function() {
        const body = document.getElementById('autodeploy-acc-body');
        const arrow = document.getElementById('autodeploy-acc-arrow');
        if (body.style.display === 'none') {
            body.style.display = 'flex';
            arrow.style.transform = 'rotate(180deg)';
        } else {
            body.style.display = 'none';
            arrow.style.transform = 'rotate(0deg)';
        }
    },

    copyNginxConfig: function(btn) {
        const configText = document.getElementById('hidden-nginx').value;
        if (!configText || configText.trim() === "") {
            alert("Please save your Base Domain first to generate your Nginx configuration.");
            return;
        }
        this.copyToClipboard(configText, btn);
    },

    toggleManualProvisionAccordion: function() {
        const body = document.getElementById('manual-acc-body');
        const arrow = document.getElementById('manual-acc-arrow');
        if (body.style.display === 'none') {
            body.style.display = 'flex';
            arrow.style.transform = 'rotate(180deg)';
            this.updateManualProvisionUI();
        } else {
            body.style.display = 'none';
            arrow.style.transform = 'rotate(0deg)';
        }
    },

    provisionTunnel: function(btn) {
        const origText = btn.innerHTML;
        btn.innerHTML = "⏳ Provisioning Tunnel via API...";
        btn.disabled = true;

        fetch('index.php?ajax=provision_tunnel')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                btn.innerHTML = "✅ Tunnel Provisioned!";
                this.showAlert("Tunnel Provisioned", "Success! The tunnel has been created and configured in Cloudflare.\n\nNow, proceed to Step 2: Copy the Bootstrap command and run it on your VPS. It now automatically includes the tunnel installation!");
                // Refresh settings to pull the new token into the bootstrap script
                this.openSettings();
            } else {
                btn.innerHTML = origText;
                btn.disabled = false;
                this.showAlert("Provisioning Failed", data.error);
            }
        })
        .catch(err => {
            btn.innerHTML = origText;
            btn.disabled = false;
            this.showAlert("Network Error", err.message);
        });
    },

    handleTunnelModeToggle: function(checkbox) {
        checkbox.disabled = true;
        const label = checkbox.nextElementSibling.querySelector('label');
        const origText = label.innerText;
        label.innerText = origText + " (Updating Nginx Config...)";

        // Save settings first, then regenerate Nginx
        this.saveSettingsSilent().then(() => {
            fetch('index.php?ajax=reset_nginx')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.config) {
                    document.getElementById('nginx-editor-area').value = data.config;
                    document.getElementById('hidden-nginx').value = data.config;
                    label.innerText = origText + " ✅ Config Auto-Updated!";
                } else {
                    label.innerText = origText + " ❌ Update Failed";
                }
                setTimeout(() => { 
                    label.innerText = origText; 
                    checkbox.disabled = false; 
                }, 2500);
            })
            .catch(err => {
                label.innerText = origText + " ❌ Network Error";
                setTimeout(() => { 
                    label.innerText = origText; 
                    checkbox.disabled = false; 
                }, 2500);
            });
        });
    },

    updateManualProvisionUI: function() {
        const secret = document.getElementById('set-receiver-secret').value;
        const cmdPrivate = `echo '{"secret":"${secret}"}' | sudo tee /var/www/html/receiver-private.json`;
        
        const privateKeyEl = document.getElementById('cmd-private-key');
        if (privateKeyEl) privateKeyEl.value = cmdPrivate;
        
        const domain = document.getElementById('set-domain').value || 'conjure-os.com';
        const vpsIp = document.getElementById('set-vps-ip').value || '12.34.56.78';
        const nginxConfig = document.getElementById('nginx-editor-area').value;
        const receiverCode = document.getElementById('hidden-receiver').value;
        const tunnelToken = window._cf_tunnel_token || '';

        let tunnelInstallCmd = '';
        let tunnelStatusText = document.getElementById('tunnel-status-text');
        if (tunnelToken) {
            tunnelInstallCmd = `\n# 1.5 Install Cloudflare Tunnel\necho "🚇 Installing Cloudflare Tunnel..."\ncurl -L --output cloudflared.deb https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb\ndpkg -i cloudflared.deb\ncloudflared service install ${tunnelToken}\nrm cloudflared.deb\n`;
            if (tunnelStatusText) tunnelStatusText.innerText = "Orbit services and the Cloudflare Tunnel";
            const btnProv = document.getElementById('btn-provision-tunnel');
            if (btnProv) {
                btnProv.innerHTML = "✅ Tunnel Provisioned & Ready";
                btnProv.style.background = "var(--success)";
            }
        } else {
            if (tunnelStatusText) tunnelStatusText.innerText = "Orbit services";
            const btnProv = document.getElementById('btn-provision-tunnel');
            if (btnProv) {
                btnProv.innerHTML = "⚡ Create & Configure Tunnel";
                btnProv.style.background = "var(--primary-accent)";
            }
        }
        
        const bootstrapContent = `#!/bin/bash
set -e
echo "🚀 Bootstrapping Orbit Infrastructure Kernel on VPS..."

# 1. Install required packages
echo "📦 Installing packages..."
apt-get update && apt-get install -y nginx php8.3-fpm php8.3-sqlite3 php8.3-zip php8.3-mbstring php8.3-curl unzip curl sudo
${tunnelInstallCmd}
# 2. Configure directory structure and permissions
echo "📁 Setting up directory trees..."
mkdir -p /var/www/html/instances /var/www/html/backups /var/www/html/instances/orbit_kernel
chown -R www-data:www-data /var/www/html

# 3. Write Secret Key
echo "🔑 Provisioning secure access tokens..."
cat << 'EOF' > /var/www/html/receiver-private.json
{"secret":"${secret}"}
EOF
chown www-data:www-data /var/www/html/receiver-private.json
chmod 600 /var/www/html/receiver-private.json

# 4. Write Receiver Script
echo "📥 Writing receiver API gateway..."
cat << 'EOF' > /var/www/html/receiver.php
${receiverCode}
EOF
chown www-data:www-data /var/www/html/receiver.php
chmod 644 /var/www/html/receiver.php

# 5. Write Nginx Default Config
echo "⚙️ Configuring Nginx reverse routing..."
cat << 'EOF' > /etc/nginx/sites-available/default
${nginxConfig}
EOF

# 6. Set up Instant Auto-Updater (Sudoers)
echo "⚡ Setting up instant update permissions..."
(crontab -l 2>/dev/null | grep -v orbit_kernel) | crontab - || true
echo "www-data ALL=(ALL) NOPASSWD: /bin/bash /var/www/html/instances/orbit_kernel/apply.sh" > /etc/sudoers.d/orbit_kernel
chmod 440 /etc/sudoers.d/orbit_kernel

# 7. Write Emergency Break-Glass Script
echo "🛡️ Provisioning emergency recovery shell..."
cat << 'EOF' > /var/www/html/instances/orbit_kernel/emergency_restore.sh
#!/bin/bash
echo "🚨 Triggering Break-Glass Protocol..."
sed -i 's/listen 127.0.0.1:80/listen 80/g' /etc/nginx/sites-available/default
nginx -t && systemctl restart nginx
rm -f /var/www/html/instances/*/maintenance.flag
echo "✅ Web interface restored over public IP."
EOF
chmod +x /var/www/html/instances/orbit_kernel/emergency_restore.sh

# 8. Test and Restart Nginx & PHP-FPM
echo "🔄 Starting web routing servers..."
nginx -t
systemctl restart nginx
systemctl restart php8.3-fpm || systemctl restart php-fpm || true

echo "🪐 Orbit Setup Complete!"`;

        // CRITICAL: Strip Windows line endings (\r) before Base64 encoding. 
        // If \r leaks into the cron job string, the Linux cron daemon will silently reject the entire task.
        const cleanBootstrap = bootstrapContent.replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
        const safeBase64 = btoa(unescape(encodeURIComponent(cleanBootstrap)));
        const encodedCmd = `echo "${safeBase64}" | base64 -d > /tmp/orbit_bootstrap.sh && sudo bash /tmp/orbit_bootstrap.sh && rm /tmp/orbit_bootstrap.sh`;
        const bootstrapEl = document.getElementById('cmd-bootstrap');
        if (bootstrapEl) bootstrapEl.value = encodedCmd;
    },

    copyHelperText: function(elemId, btn) {
        const elem = document.getElementById(elemId);
        if (elem) {
            this.copyToClipboard(elem.value, btn);
        }
    },

    copyReceiverCode: function(btn) {
        const receiverText = document.getElementById('hidden-receiver').value;
        this.copyToClipboard(receiverText, btn);
    },

    copyToClipboard: function(text, btn) {
        // Modern secure synchronous copying
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text)
                .then(() => {
                    if (btn) this.showCopySuccess(btn);
                })
                .catch(() => this.fallbackCopyToClipboard(text, btn));
        } else {
            this.fallbackCopyToClipboard(text, btn);
        }
    },

    fallbackCopyToClipboard: function(text, btn) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        
        // Hide off-screen safely (no layout jumps)
        textArea.style.position = 'fixed';
        if (document.body.dir === 'rtl') {
            textArea.style.right = '-9999px';
        } else {
            textArea.style.left = '-9999px';
        }
        document.body.appendChild(textArea);
        
        // Focus and select
        textArea.focus();
        textArea.select();
        textArea.setSelectionRange(0, 99999);
        
        // iOS specific selection range
        const range = document.createRange();
        range.selectNodeContents(textArea);
        const selection = window.getSelection();
        if (selection) {
            selection.removeAllRanges();
            selection.addRange(range);
        }

        try {
            document.execCommand("copy");
            if (btn) this.showCopySuccess(btn);
        } catch (err) {
            console.error("Fallback copy execution failed", err);
            alert("Copy failed. Please manually select the text and copy.");
        }
        document.body.removeChild(textArea);
    },

    showCopySuccess: function(btn) {
        const origText = btn.innerHTML;
        btn.innerHTML = "✅ Copied!";
        setTimeout(() => btn.innerHTML = origText, 1500);
    },

    stageKernel: function(btn) {
        const isIconBtn = btn.classList.contains('icon-btn');
        const origText = isIconBtn ? '' : btn.innerText;
        
        btn.disabled = true;

        const consoleModal = document.getElementById('telemetry-modal');
        const consoleBody = document.getElementById('telemetry-console');
        const closeBtn = document.getElementById('btn-telemetry-close');
        
        // Set contextual title for Kernel updates
        const headerTitle = consoleModal.querySelector('.modal-header h2');
        if (headerTitle) headerTitle.innerText = "Deploying Kernel Updates";
        
        // Reset modal copy button behavior to standard logs
        const copyBtn = consoleModal.querySelector('.modal-header button');
        if (copyBtn) {
            copyBtn.onclick = (e) => {
                this.copyTelemetryReport(copyBtn);
            };
        }
        
        consoleBody.innerHTML = ''; // Clear previous
        this.appendLog('Packaging and transmitting Kernel payload...', 'status');
        closeBtn.disabled = true;
        consoleModal.classList.add('active');

        // Save settings first ONLY if the settings modal is actively open
        const isSettingsOpen = document.getElementById('settings-modal').classList.contains('active');
        const savePromise = isSettingsOpen ? this.saveSettingsSilent() : Promise.resolve();

        savePromise.then(() => {
            fetch('index.php?ajax=stage_kernel')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        this.appendLog(`Payload transmitted successfully to: ${data.target_url}`, 'success');
                        this.appendLog(`Executing apply script instantly in the background...`, 'info');
                        
                        let lastLogLength = 0;
                        let pollCount = 0;
                        
                        this.pollInterval = setInterval(() => {
                            pollCount++;
                            
                            if (pollCount > 15) { // 45 seconds max for instant script
                                clearInterval(this.pollInterval);
                                this.pollInterval = null;
                                this.appendLog(`[Timeout] Apply script did not respond in time.`, 'error');
                                this.appendLog(`To apply manually, SSH into your VPS and run:`, 'warn');
                                this.appendLog(`sudo bash /var/www/html/instances/orbit_kernel/apply.sh`, 'info');
                                closeBtn.disabled = false;
                                btn.disabled = false;
                                if (!isIconBtn) btn.innerText = origText;
                                return;
                            }
                            
                            fetch('index.php?ajax=poll_kernel_log&run_id=' + data.run_id)
                                .then(res => res.json())
                                .then(resData => {
                                    if (resData.success && resData.log) {
                                        if (resData.log.includes(`Run ID: ${data.run_id}`)) {
                                            if (resData.log.length > lastLogLength) {
                                                const newText = resData.log.substring(lastLogLength);
                                                lastLogLength = resData.log.length;
                                                
                                                const lines = newText.split('\n').filter(l => l.trim() !== "");
                                                lines.forEach(line => {
                                                    let type = 'info';
                                                    if (line.includes('[success]')) type = 'success';
                                                    if (line.includes('[error]')) type = 'error';
                                                    if (line.includes('[warn]')) type = 'warn';
                                                    if (line.includes('[status]')) type = 'status';
                                                    
                                                    const cleanLine = line.replace(/\[(info|success|error|warn|status)\]\s/, '');
                                                    this.appendLog(cleanLine, type);
                                                });
                                            }
                                            
                                            if (resData.log.includes('Kernel update complete!')) {
                                                clearInterval(this.pollInterval);
                                                this.pollInterval = null;
                                                closeBtn.disabled = false;
                                                btn.disabled = false;
                                                if (!isIconBtn) btn.innerText = origText;
                                                
                                                closeBtn.innerText = "Done";
                                                closeBtn.onclick = () => {
                                                    this.closeTelemetryModal();
                                                };
                                            }
                                        }
                                    }
                                })
                                .catch(err => { /* ignore until ready */ });
                        }, 3000);
                        
                    } else {
                        const targetInfo = data.target_url ? `\nTarget Endpoint: ${data.target_url}` : '';
                        this.appendLog(`Kernel Sync Failed: ${data.error || "Unknown error"}${targetInfo}`, 'error');
                        closeBtn.disabled = false;
                        btn.disabled = false;
                        if (!isIconBtn) btn.innerText = origText;
                    }
                })
                .catch(err => {
                    console.error(err);
                    this.appendLog(`Network Error: ${err.message}`, 'error');
                    closeBtn.disabled = false;
                    btn.disabled = false;
                    if (!isIconBtn) btn.innerText = origText;
                });
        });
    },

    saveSettingsSilent: function() {
        const payload = {
            default_domain: document.getElementById('set-domain').value,
            admin_notice: document.getElementById('set-notice').value,
            vps_ip: document.getElementById('set-vps-ip').value,
            receiver_secret: document.getElementById('set-receiver-secret').value,
            cf_token: document.getElementById('set-cf-token').value,
            do_token: document.getElementById('set-do-token').value,
            cloudflare_tunnel_mode: document.getElementById('set-cf-tunnel-mode').checked ? 1 : 0
        };

        return fetch('index.php?ajax=save_settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.nginx_config) {
                document.getElementById('nginx-editor-area').value = data.nginx_config;
                document.getElementById('hidden-nginx').value = data.nginx_config;
            }
            return data;
        });
    },



    closeSettings: function(isPopState = false, skipHistory = false) {
        const modal = document.getElementById('settings-modal');
        const wasActive = modal.classList.contains('active');
        modal.classList.remove('active');
        
        if (wasActive && !isPopState && !skipHistory) {
            if (history.state && history.state.modal === 'settings') {
                history.back();
            }
        }
    },

    saveSettings: function() {
        const payload = {
            default_domain: document.getElementById('set-domain').value,
            admin_notice: document.getElementById('set-notice').value,
            vps_ip: document.getElementById('set-vps-ip').value,
            receiver_secret: document.getElementById('set-receiver-secret').value,
            cf_token: document.getElementById('set-cf-token').value,
            do_token: document.getElementById('set-do-token').value,
            cloudflare_tunnel_mode: document.getElementById('set-cf-tunnel-mode').checked ? 1 : 0
        };

        const btn = document.getElementById('btn-settings-save');
        const origText = btn.innerText;
        btn.innerText = "Saving...";
        btn.disabled = true;

        fetch('index.php?ajax=save_settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                btn.innerText = "Saved! ✅";
                if (data.nginx_config) {
                    document.getElementById('nginx-editor-area').value = data.nginx_config;
                    document.getElementById('hidden-nginx').value = data.nginx_config;
                }
                setTimeout(() => {
                    btn.innerText = origText;
                    btn.disabled = false;
                }, 1500);
            }
        })
        .catch(err => {
            console.error(err);
            btn.innerText = "Error ❌";
            setTimeout(() => { btn.innerText = origText; btn.disabled = false; }, 2000);
        });
    },

    openDeployWizard: function() {
        this.closeOverlays();
        fetch('index.php?ajax=get_templates')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.defaultDomain = data.default_domain || 'conjure.com';
                    const select = document.getElementById('deploy-template');
                    
                    if (data.templates.length === 0) {
                        select.innerHTML = `<option disabled>No AppMaker templates found</option>`;
                    } else {
                        select.innerHTML = data.templates.map(t => `<option value="${t.folder}">${t.icon} ${t.name}</option>`).join('');
                    }
                    
                    // Hijack the native select
                    this.hijackSelects('deploy-template');
                    
                    document.getElementById('deploy-name').value = '';
                    document.getElementById('deploy-prefix').value = '';
                    
                    // Auto-suggest unique names and prefixes for the first template option
                    if (data.templates.length > 0) {
                        this.fetchNameSuggestion(data.templates[0].folder);
                    } else {
                        this.updateRoutePreview();
                    }
                    
                    document.getElementById('deploy-modal').classList.add('active');
                    this.pushModalState('deploy');
                }
            })
            .catch(err => console.error(err));
    },

    fetchNameSuggestion: function(template) {
        if (!template) return;
        
        const nameInput = document.getElementById('deploy-name');
        const prefixInput = document.getElementById('deploy-prefix');
        
        nameInput.placeholder = "Calculating unique suggested name...";
        prefixInput.placeholder = "Calculating unique suggested prefix...";
        
        fetch(`index.php?ajax=get_next_suggestion&template=${encodeURIComponent(template)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    nameInput.value = data.suggested_name;
                    prefixInput.value = data.suggested_prefix;
                    this.updateRoutePreview();
                }
            })
            .catch(err => console.error(err));
    },

    closeDeployWizard: function(isPopState = false, skipHistory = false) {
        const modal = document.getElementById('deploy-modal');
        const wasActive = modal.classList.contains('active');
        modal.classList.remove('active');
        
        if (wasActive && !isPopState && !skipHistory) {
            if (history.state && history.state.modal === 'deploy') {
                history.back();
            }
        }
    },

    updateRoutePreview: function() {
        const isHome = document.getElementById('deploy-is-home').checked;
        const prefix = document.getElementById('deploy-prefix').value.trim() || 'app';
        const preview = document.getElementById('deploy-preview');
        
        if (isHome) {
            preview.innerHTML = `<span>🔗 https://${this.defaultDomain}</span>`;
        } else {
            preview.innerHTML = `<span>🔗 https://${prefix}.${this.defaultDomain}</span>`;
        }
    },

    runDiagnostics: function(id) {
        this.closeOverlays();
        
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        const name = card ? card.getAttribute('data-name') : 'App';
        
        const consoleModal = document.getElementById('telemetry-modal');
        const consoleBody = document.getElementById('telemetry-console');
        const closeBtn = document.getElementById('btn-telemetry-close');
        
        // Re-label Telemetry Header dynamically for diagnostics context
        const headerTitle = consoleModal.querySelector('.modal-header h2');
        if (headerTitle) headerTitle.innerText = `Diagnostics: ${name}`;
        
        consoleBody.innerHTML = ''; // Clear previous logs
        closeBtn.disabled = true;
        consoleModal.classList.add('active');
        
        this.appendLog(`Initiating remote diagnostics wrapper for ${name}...`, 'status');
        this.appendLog(`Interrogating VPS environment & running index.php compile scan...`, 'info');
        
        fetch('index.php?ajax=run_diagnostics', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
        .then(res => res.json())
        .then(data => {
            closeBtn.disabled = false;
            
            if (data.success) {
                this.appendLog(`Diagnostic execution complete! Output stream below:`, 'success');
                
                // Print raw output lines neatly with adaptive color-coding
                const lines = data.output.split('\n');
                lines.forEach(line => {
                    if (line.trim() !== '') {
                        let logType = 'info';
                        if (line.toLowerCase().includes('success') || line.toLowerCase().includes('ok') || line.toLowerCase().includes('yes') || line.toLowerCase().includes('complete')) logType = 'success';
                        if (line.toLowerCase().includes('error') || line.toLowerCase().includes('fatal') || line.toLowerCase().includes('fail') || line.toLowerCase().includes('uncaught')) logType = 'error';
                        if (line.toLowerCase().includes('warning') || line.toLowerCase().includes('warn')) logType = 'warn';
                        
                        this.appendLog(line, logType);
                    }
                });
                
                // Construct formatted clipboard package wrapped in triple backticks for immediate forum/chat posting
                const now = new Date().toLocaleString();
                const reportText = `=== ORBIT REMOTE DIAGNOSTIC REPORT ===\nGenerated: ${now}\nApp Name: ${name}\nExit Code: ${data.exit_code}\n\n=== RAW EXECUTION OUTPUT ===\n${data.output.trim()}\n=== END REPORT ===`;
                const formattedReport = "```text\n" + reportText + "\n```";
                
                // Secure synchronous clipboard sync
                this.copyToClipboard(formattedReport, null);
                
                this.appendLog(`✅ Full debug report has been auto-copied to your clipboard!`, 'status');
                
                // Adjust header copy button to target this generated report
                const copyBtn = consoleModal.querySelector('.modal-header button');
                if (copyBtn) {
                    copyBtn.onclick = (e) => {
                        this.copyToClipboard(formattedReport, copyBtn);
                    };
                }
            } else {
                this.appendLog(`Diagnostics Failed: ${data.error}`, 'error');
            }
        })
        .catch(err => {
            closeBtn.disabled = false;
            this.appendLog(`Network Error: ${err.message}`, 'error');
        });
    },

    copyTelemetryReport: function(btn) {
        const consoleBody = document.getElementById('telemetry-console');
        const report = consoleBody.innerText;
        // Automatically wrap the raw log in markdown backticks for immediate chat posting
        const formattedReport = "```text\n" + report.trim() + "\n```";
        this.copyToClipboard(formattedReport, btn);
    },

    submitDeploy: function() {
        const isOverwrite = document.getElementById('deploy-overwrite').checked;
        const payload = {
            template: document.getElementById('deploy-template').value,
            instance_name: document.getElementById('deploy-name').value.trim(),
            route_prefix: document.getElementById('deploy-prefix').value.trim(),
            base_domain: this.defaultDomain,
            overwrite_data: isOverwrite ? 1 : 0,
            include_db: (isOverwrite && document.getElementById('deploy-inc-db')?.checked) ? 1 : 0,
            include_private: (isOverwrite && document.getElementById('deploy-inc-private')?.checked) ? 1 : 0,
            include_ignored: (isOverwrite && document.getElementById('deploy-inc-ignored')?.checked) ? 1 : 0,
            is_home: document.getElementById('deploy-is-home').checked ? 1 : 0
        };
        
        if (!payload.template || !payload.instance_name || !payload.route_prefix) {
            alert("Please fill out all fields.");
            return;
        }

        this.closeDeployWizard();
        this.startDeployPipeline(payload, "Deploying App");
    },

    deployUpdate: function(id, isHardSync = false) {
        this.closeOverlays();
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        if (!card) return;
        
        const name = card.getAttribute('data-name');
        const subdomain = card.getAttribute('data-subdomain');
        const templateName = card.querySelector('.card-subtitle').innerText;
        const isHome = card.getAttribute('data-is-home') === '1';
        
        let prefix = '';
        if (isHome) {
            prefix = '_home';
        } else if (subdomain && subdomain !== 'https://') {
            const host = subdomain.replace('https://', '');
            prefix = host.split('.')[0];
        }

        let includeDb = 0;
        let includePrivate = 0;
        let includeIgnored = 0;
        
        if (isHardSync) {
            includeDb = document.getElementById('hardsync-inc-db')?.checked ? 1 : 0;
            includePrivate = document.getElementById('hardsync-inc-private')?.checked ? 1 : 0;
            includeIgnored = document.getElementById('hardsync-inc-ignored')?.checked ? 1 : 0;
        }

        const payload = {
            id: id,
            template: templateName,
            instance_name: name,
            route_prefix: prefix,
            overwrite_data: isHardSync ? 1 : 0,
            include_db: includeDb,
            include_private: includePrivate,
            include_ignored: includeIgnored,
            wipe_remote: isHardSync ? 1 : 0,
            is_update: 1,
            is_home: isHome ? 1 : 0
        };

        const title = isHardSync ? "Hard Sync (Wipe & Deploy)" : "Deploying Update";
        this.startDeployPipeline(payload, title);
    },

    async startBatchDeployPipeline(payloads, titleText) {
        const consoleModal = document.getElementById('telemetry-modal');
        const consoleBody = document.getElementById('telemetry-console');
        const closeBtn = document.getElementById('btn-telemetry-close');
        
        const headerTitle = consoleModal.querySelector('.modal-header h2');
        if (headerTitle) headerTitle.innerText = titleText;
        
        const copyBtn = consoleModal.querySelector('.modal-header button');
        if (copyBtn) {
            copyBtn.onclick = (e) => { this.copyTelemetryReport(copyBtn); };
        }
        
        consoleBody.innerHTML = '';
        this.appendLog(`[System] Initiating batch deployment for ${payloads.length} apps...`, 'status');
        closeBtn.disabled = true;
        consoleModal.classList.add('active');

        let successCount = 0;
        let failCount = 0;

        for (let i = 0; i < payloads.length; i++) {
            const payload = payloads[i];
            let zip_path_to_clean = null;
            this.appendLog(`\n--- Deploying ${i+1}/${payloads.length}: ${payload.instance_name} ---`, 'status');
            
            try {
                // 0. Pre-flight estimate
                let estRes = await fetch('index.php?ajax=deploy_estimate', { method: 'POST', body: JSON.stringify(payload) });
                let estData = await estRes.json();
                
                if (!estData.success) throw new Error(estData.error);
                
                const fileCount = estData.file_count;
                if (fileCount === 0) {
                    this.appendLog(`[System] No changed files detected for ${payload.instance_name}. Skipped.`, 'success');
                    successCount++;
                    continue;
                }

                // 1. Build
                this.appendLog(`Step 1: Packaging ${payload.instance_name}...`, "info");
                let res = await fetch('index.php?ajax=deploy_build', { method: 'POST', body: JSON.stringify(payload) });
                let data = await res.json();
                
                if (!data.success) throw new Error(data.error);
                
                const { zip_path, total_chunks, upload_id, receiver_url } = data;
                zip_path_to_clean = zip_path;
                
                // 2. Upload Chunks
                this.appendLog(`Step 2: Uploading payload to ${receiver_url}...`, "info");
                for (let c = 0; c < total_chunks; c++) {
                    let chunkRes = await fetch('index.php?ajax=deploy_chunk', { 
                        method: 'POST', 
                        body: JSON.stringify({ ...payload, zip_path, chunk_index: c, total_chunks, upload_id, receiver_url }) 
                    });
                    let chunkData = await chunkRes.json();
                    if (!chunkData.success) throw new Error(chunkData.error);
                }
                
                // 3. Extract
                this.appendLog(`Step 3: Extracting and finalizing...`, "info");
                let extRes = await fetch('index.php?ajax=deploy_extract', { 
                    method: 'POST', 
                    body: JSON.stringify({ ...payload, upload_id, receiver_url, zip_path }) 
                });
                let extData = await extRes.json();
                if (!extData.success) throw new Error(extData.error);
                
                if (extData.messages) {
                    extData.messages.forEach(msg => this.appendLog(msg.text, msg.type));
                }
                
                this.appendLog(`✅ ${payload.instance_name} deployed successfully!`, "success");
                successCount++;
            } catch (err) {
                this.appendLog(`[Execution Error for ${payload.instance_name}] ${err.message}`, 'error');
                failCount++;
            } finally {
                if (zip_path_to_clean) {
                    fetch('index.php?ajax=deploy_cleanup', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ zip_path: zip_path_to_clean })
                    }).catch(e => console.error("Cleanup failed", e));
                }
            }
        }
        
        this.appendLog(`\n=== Batch Deployment Complete ===`, 'status');
        this.appendLog(`Successful: ${successCount} | Failed: ${failCount}`, successCount > 0 && failCount === 0 ? 'success' : (failCount > 0 ? 'warn' : 'info'));
        
        closeBtn.disabled = false;
        closeBtn.innerText = "Done (Reload Grid)";
        closeBtn.onclick = () => {
            this.closeTelemetryModal();
            location.reload();
        };
        
        // Clear selection mode
        this.exitSelectionMode();
    },

    deploySelectedUpdates: function() {
        if (this.selectedInstances.size === 0) return;
        
        const payloads = [];
        this.selectedInstances.forEach(id => {
            const card = document.querySelector(`.instance-card[data-id="${id}"]`);
            if (!card) return;
            
            const name = card.getAttribute('data-name');
            const subdomain = card.getAttribute('data-subdomain');
            const templateName = card.querySelector('.card-subtitle').innerText;
            const isHome = card.getAttribute('data-is-home') === '1';
            
            let prefix = '';
            if (isHome) {
                prefix = '_home';
            } else if (subdomain && subdomain !== 'https://') {
                const host = subdomain.replace('https://', '');
                prefix = host.split('.')[0];
            }

            payloads.push({
                id: id,
                template: templateName,
                instance_name: name,
                route_prefix: prefix,
                overwrite_data: 0,
                include_db: 0,
                include_private: 0,
                include_ignored: 0,
                wipe_remote: 0,
                is_update: 1,
                is_home: isHome ? 1 : 0
            });
        });
        
        this.showConfirm(
            "Batch Deploy Updates",
            `You are about to deploy updates for ${payloads.length} selected apps.\n\nProceed?`,
            "Deploy All",
            "var(--success)",
            () => {
                this.startBatchDeployPipeline(payloads, `Batch Deploy (${payloads.length} Apps)`);
            }
        );
    },

    bindFabEvents: function() {
        const fab = document.getElementById('fab-batch-deploy');
        if (!fab) return;

        let timer = null;
        let isLongPressActive = false;

        fab.addEventListener('pointerdown', (e) => {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            isLongPressActive = false;
            timer = setTimeout(() => {
                isLongPressActive = true;
                if (navigator.vibrate) navigator.vibrate(50);
                this.selectAllAndPromptDeploy();
            }, 600);
        });

        const cancel = () => {
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
        };

        fab.addEventListener('pointerup', (e) => {
            cancel();
            if (!isLongPressActive) {
                this.deploySelectedUpdates();
            }
            isLongPressActive = false;
        });

        fab.addEventListener('pointerleave', cancel);
        fab.addEventListener('pointercancel', cancel);
    },

    selectAllAndPromptDeploy: function() {
        const cards = document.querySelectorAll('.instance-card');
        this.selectedInstances.clear();
        
        cards.forEach(card => {
            const id = card.getAttribute('data-id');
            this.selectedInstances.add(id);
            card.classList.add('selected');
        });
        
        this.deploySelectedUpdates();
    },

    async startDeployPipeline(payload, titleText) {
        let zip_path_to_clean = null;
        const consoleModal = document.getElementById('telemetry-modal');
        const consoleBody = document.getElementById('telemetry-console');
        const closeBtn = document.getElementById('btn-telemetry-close');
        
        const headerTitle = consoleModal.querySelector('.modal-header h2');
        if (headerTitle) headerTitle.innerText = titleText;
        
        const copyBtn = consoleModal.querySelector('.modal-header button');
        if (copyBtn) {
            copyBtn.onclick = (e) => { this.copyTelemetryReport(copyBtn); };
        }
        
        consoleBody.innerHTML = '';
        this.appendLog('[System] Analyzing payload and calculating estimate...', 'status');
        closeBtn.disabled = true;
        consoleModal.classList.add('active');

        try {
            // 0. Pre-flight estimate
            let estRes = await fetch('index.php?ajax=deploy_estimate', { method: 'POST', body: JSON.stringify(payload) });
            let estData = await estRes.json();
            
            if (!estData.success) throw new Error(estData.error);
            
            const fileCount = estData.file_count;
            const estMb = estData.estimated_mb;
            const estBytes = estData.estimated_bytes;
            
            let sizeDisplay = estMb >= 1 ? `${estMb} MB` : `${Math.round(estBytes / 1024)} KB`;
            
            if (fileCount === 0) {
                this.appendLog('[System] No changed files detected. Deployment skipped.', 'success');
                closeBtn.disabled = false;
                closeBtn.innerText = "Done";
                closeBtn.onclick = () => this.closeTelemetryModal();
                return;
            }

            const userConfirmed = await new Promise((resolve) => {
                App.showConfirm(
                    "Deployment Estimate",
                    `Template: ${payload.template}\nTarget: ${payload.instance_name}\n\nFiles to upload: ${fileCount}\nEstimated payload size: ${sizeDisplay}\n\nDo you want to proceed with the deployment?`,
                    "Deploy",
                    "var(--primary-accent)",
                    () => resolve(true),
                    () => resolve(false)
                );
            });
            
            if (!userConfirmed) {
                this.appendLog('[System] Deployment aborted by user.', 'warn');
                closeBtn.disabled = false;
                closeBtn.innerText = "Close";
                closeBtn.onclick = () => this.closeTelemetryModal();
                return;
            }
            
            this.appendLog('[System] Estimate confirmed. Initiating chunked deployment pipeline...', 'status');

            // 1. Build
            this.appendLog("Step 1: Packaging app & configuring DNS...", "info");
            let res = await fetch('index.php?ajax=deploy_build', { method: 'POST', body: JSON.stringify(payload) });
            let data = await res.json();
            
            if (!data.success) throw new Error(data.error);
            
            const { zip_path, total_chunks, upload_id, receiver_url } = data;
            zip_path_to_clean = zip_path;
            const mb_size = Math.round((data.zip_size / 1048576) * 100) / 100;
            this.appendLog(`Package built successfully. Size: ${mb_size} MB`, "success");
            
            // 2. Upload Chunks
            this.appendLog(`Step 2: Uploading payload to ${receiver_url} in ${total_chunks} chunks...`, "info");
            for (let i = 0; i < total_chunks; i++) {
                let pct = Math.round(((i + 1) / total_chunks) * 100);
                this.appendLog(`Uploading chunk ${i+1}/${total_chunks} (${pct}%)...`, "status");
                
                let chunkRes = await fetch('index.php?ajax=deploy_chunk', { 
                    method: 'POST', 
                    body: JSON.stringify({ ...payload, zip_path, chunk_index: i, total_chunks, upload_id, receiver_url }) 
                });
                let chunkData = await chunkRes.json();
                if (!chunkData.success) throw new Error(chunkData.error);
            }
            this.appendLog(`Upload complete (100%).`, "success");
            
            // 3. Extract
            this.appendLog("Step 3: Extracting and finalizing on server...", "info");
            let extRes = await fetch('index.php?ajax=deploy_extract', { 
                method: 'POST', 
                body: JSON.stringify({ ...payload, upload_id, receiver_url, zip_path }) 
            });
            let extData = await extRes.json();
            if (!extData.success) throw new Error(extData.error);
            
            if (extData.messages) {
                extData.messages.forEach(msg => this.appendLog(msg.text, msg.type));
            }
            
            this.appendLog("Deployment sequence completed successfully! 🎉", "success");
            
            closeBtn.disabled = false;
            closeBtn.innerText = "Done (Reload Grid)";
            closeBtn.onclick = () => {
                this.closeTelemetryModal();
                location.reload();
            };
        } catch (err) {
            closeBtn.disabled = false;
            this.appendLog(`[Execution Error] ${err.message}`, 'error');
        } finally {
            if (zip_path_to_clean) {
                fetch('index.php?ajax=deploy_cleanup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ zip_path: zip_path_to_clean })
                }).catch(e => console.error("Cleanup failed", e));
            }
        }
    },

    closeTelemetryModal: function() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
        document.getElementById('telemetry-modal').classList.remove('active');
    },

    currentBackupInstanceId: null,

    openBackupManager: function(id) {
        App.closeOverlays();
        App.currentBackupInstanceId = id;
        
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        const name = card ? card.getAttribute('data-name') : 'Instance';
        document.getElementById('backup-instance-name').innerText = name;
        
        const noteInput = document.getElementById('backup-note-input');
        if (noteInput) noteInput.value = '';
        
        document.getElementById('backup-list').innerHTML = `<div style="text-align:center; padding: 20px; color: var(--text-secondary);">Loading backups...</div>`;
        
        const btn = document.getElementById('btn-create-backup');
        btn.onclick = () => App.createBackup(id);
        
        document.getElementById('backup-modal').classList.add('active');
        App.loadBackups(id);
    },

    closeBackupManager: function() {
        document.getElementById('backup-modal').classList.remove('active');
        document.getElementById('backup-confirm-overlay').style.display = 'none';
        App.currentBackupInstanceId = null;
    },

    loadBackups: function(id) {
        const list = document.getElementById('backup-list');
        fetch('index.php?ajax=manage_backups', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, sub_action: 'list_backups' })
        })
        .then(res => {
            if (!res.ok) {
                return res.text().then(text => { throw new Error(text || "HTTP " + res.status); });
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                if (!data.backups || data.backups.length === 0) {
                    list.innerHTML = `<div style="text-align:center; padding: 30px; color: var(--text-secondary);">No backups found on the server.</div>`;
                    return;
                }
                
                let html = '';
                data.backups.forEach(b => {
                    const size = App.formatBytes(b.size);
                    const date = new Date(b.time * 1000).toLocaleString();
                    const noteHtml = b.note ? `<div style="font-size: 12px; color: var(--warning); margin-top: 2px;">📝 ${b.note}</div>` : '';
                    
                    html += `
                        <div class="backup-item">
                            <div class="backup-info">
                                <span class="backup-filename">${b.file}</span>
                                ${noteHtml}
                                <span class="backup-meta">${date} &bull; ${size}</span>
                            </div>
                            <div class="backup-actions">
                                <button class="icon-btn-small" onclick="App.restoreBackup(${id}, '${b.file}')" title="Restore this backup" style="color: var(--success); background: rgba(16,185,129,0.1); padding: 8px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                </button>
                                <button class="icon-btn-small" onclick="App.deleteBackup(${id}, '${b.file}')" title="Delete backup" style="color: var(--danger); background: rgba(239,68,68,0.1); padding: 8px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                </button>
                            </div>
                        </div>
                    `;
                });
                list.innerHTML = html;
            } else {
                list.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--danger);">${data.error || 'Failed to load backups'}</div>`;
            }
        })
        .catch(err => {
            console.error("Fetch backups failure:", err);
            list.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--danger);">Error: ${err.message || 'Network error fetching backups'}</div>`;
        });
    },

    createBackup: function(id) {
        const btn = document.getElementById('btn-create-backup');
        const noteInput = document.getElementById('backup-note-input');
        const note = noteInput ? noteInput.value.trim() : '';
        
        const origText = btn.innerHTML;
        btn.innerHTML = `Creating...`;
        btn.disabled = true;
        
        fetch('index.php?ajax=manage_backups', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, sub_action: 'create_backup', note: note })
        })
        .then(res => {
            if (!res.ok) {
                return res.text().then(text => { throw new Error(text || "HTTP " + res.status); });
            }
            return res.json();
        })
        .then(data => {
            btn.innerHTML = origText;
            btn.disabled = false;
            if (data.success) {
                if (noteInput) noteInput.value = '';
                App.loadBackups(id);
            } else {
                App.showBackupConfirm("Backup Failed", data.error || "Unknown error", "OK", "var(--danger)", () => {});
            }
        })
        .catch(err => {
            console.error("Backup creation failure:", err);
            btn.innerHTML = origText;
            btn.disabled = false;
            App.showBackupConfirm("Network Error", err.message || "Failed to communicate with server.", "OK", "var(--danger)", () => {});
        });
    },

    showBackupConfirm: function(title, msg, btnText, btnColor, onConfirm) {
        document.getElementById('backup-confirm-title').innerText = title;
        document.getElementById('backup-confirm-title').style.color = btnColor;
        document.getElementById('backup-confirm-msg').innerHTML = msg; // Support styled HTML messages
        
        const btn = document.getElementById('backup-confirm-btn');
        btn.innerText = btnText;
        btn.style.background = btnColor;
        btn.onclick = () => {
            document.getElementById('backup-confirm-overlay').style.display = 'none';
            onConfirm();
        };
        
        document.getElementById('backup-confirm-overlay').style.display = 'flex';
    },

    restoreBackup: function(id, file) {
        const msgHtml = `
            <div style="text-align: left; line-height: 1.5; font-size: 13px; user-select: none;">
                Select how you want to restore this backup:<br><br>
                <strong>File:</strong> <span style="font-family: monospace; font-size: 11px; word-break: break-all; color: var(--text-primary);">${file}</span>
                
                <div style="margin-top: 16px; display: flex; flex-direction: column; gap: 8px;">
                    <label style="display: flex; align-items: flex-start; gap: 10px; background: rgba(0,0,0,0.25); padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); cursor: pointer;">
                        <input type="radio" name="restore_mode" value="full" checked style="margin-top: 2px; accent-color: var(--danger);">
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <span style="color:var(--danger); font-size:13px; font-weight:600;">🔴 Complete Rollback</span>
                            <small style="color:var(--text-secondary); font-size:11px; line-height:1.3;">Reverts BOTH code and databases to this backup state.</small>
                        </div>
                    </label>
                    <label style="display: flex; align-items: flex-start; gap: 10px; background: rgba(0,0,0,0.25); padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); cursor: pointer;">
                        <input type="radio" name="restore_mode" value="data_only" style="margin-top: 2px; accent-color: var(--warning);">
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <span style="color:var(--warning); font-size:13px; font-weight:600;">💾 Data Only (Preserve Code)</span>
                            <small style="color:var(--text-secondary); font-size:11px; line-height:1.3;">Reverts databases & settings only. Keeps your live code intact.</small>
                        </div>
                    </label>
                    <label style="display: flex; align-items: flex-start; gap: 10px; background: rgba(0,0,0,0.25); padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); cursor: pointer;">
                        <input type="radio" name="restore_mode" value="code_only" style="margin-top: 2px; accent-color: var(--success);">
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <span style="color:var(--success); font-size:13px; font-weight:600;">💻 Code Only (Preserve Data)</span>
                            <small style="color:var(--text-secondary); font-size:11px; line-height:1.3;">Reverts code only. Protects and preserves all live database records.</small>
                        </div>
                    </label>
                </div>
            </div>
        `;

        App.showBackupConfirm(
            "Restore Backup?",
            msgHtml,
            "Yes, Restore",
            "var(--primary-accent)",
            () => {
                const list = document.getElementById('backup-list');
                const radios = document.getElementsByName('restore_mode');
                let selectedMode = 'full';
                for (let i = 0; i < radios.length; i++) {
                    if (radios[i].checked) {
                        selectedMode = radios[i].value;
                        break;
                    }
                }

                list.innerHTML = `<div style="text-align:center; padding: 40px; color: var(--warning);">Restoring backup...<br><small>This may take a minute depending on the size.</small></div>`;
                
                fetch('index.php?ajax=manage_backups', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, sub_action: 'restore_backup', file: file, restore_mode: selectedMode })
                })
                .then(res => {
                    if (!res.ok) {
                        return res.text().then(text => { throw new Error(text || "HTTP " + res.status); });
                    }
                    return res.json();
                })
                .then(data => {
                    if (data.success) {
                        App.loadBackups(id);
                    } else {
                        list.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--danger);">Restore failed: ${data.error}</div>`;
                        setTimeout(() => App.loadBackups(id), 3000);
                    }
                })
                .catch(err => {
                    console.error("Restore failure:", err);
                    list.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--danger);">Error: ${err.message || 'Network error during restore.'}</div>`;
                    setTimeout(() => App.loadBackups(id), 3000);
                });
            }
        );
    },

    deleteBackup: function(id, file) {
        const msgHtml = `
            <div style="text-align: left; line-height: 1.5; font-size: 13px;">
                Are you sure you want to permanently delete this backup file from the server?<br><br>
                <strong>File:</strong> <span style="font-family: monospace; font-size: 11px; word-break: break-all; color: var(--text-primary);">${file}</span>
            </div>
        `;

        App.showBackupConfirm(
            "Delete Backup?",
            msgHtml,
            "Yes, Delete",
            "var(--danger)",
            () => {
                fetch('index.php?ajax=manage_backups', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, sub_action: 'delete_backup', file: file })
                })
                .then(res => {
                    if (!res.ok) {
                        return res.text().then(text => { throw new Error(text || "HTTP " + res.status); });
                    }
                    return res.json();
                })
                .then(data => {
                    if (data.success) {
                        App.loadBackups(id);
                    } else {
                        const list = document.getElementById('backup-list');
                        list.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--danger);">Delete failed: ${data.error}</div>`;
                        setTimeout(() => App.loadBackups(id), 3000);
                    }
                })
                .catch(err => {
                    console.error("Deletion failure:", err);
                    const list = document.getElementById('backup-list');
                    list.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--danger);">Error: ${err.message || 'Network error during deletion.'}</div>`;
                    setTimeout(() => App.loadBackups(id), 3000);
                });
            }
        );
    },

    accessEmails: [],
    accessGroups: [],

    openAccessModal: function(id) {
        this.closeOverlays();
        document.getElementById('access-instance-id').value = id;
        
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        const name = card ? card.getAttribute('data-name') : 'App';
        document.getElementById('access-new-group-name').value = `Orbit: ${name}`;
        
        const list = document.getElementById('access-email-list');
        list.innerHTML = `<div style="text-align:center; color: var(--text-secondary); padding: 10px;">Loading Cloudflare Groups...</div>`;
        document.getElementById('access-modal').classList.add('active');
        
        this.accessEmails = [];
        this.accessGroups = [];

        fetch(`index.php?ajax=get_access_groups&id=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.accessGroups = data.groups || [];
                    const select = document.getElementById('access-group-select');
                    
                    let html = `<option value="none">-- None (Public Access) --</option>`;
                    html += `<option value="">-- Create New Group --</option>`;
                    this.accessGroups.forEach(g => {
                        html += `<option value="${g.id}">${g.name}</option>`;
                    });
                    select.innerHTML = html;
                    
                    if (data.selected_group_id) {
                        select.value = data.selected_group_id;
                    } else {
                        select.value = "none";
                    }
                    
                    this.hijackSelects('access-group-select');
                    this.onAccessGroupChange();
                } else {
                    list.innerHTML = `<div style="text-align:center; color: var(--danger); padding: 10px;">Error: ${data.error}</div>`;
                }
            })
            .catch(err => {
                list.innerHTML = `<div style="text-align:center; color: var(--danger); padding: 10px;">Network Error</div>`;
            });
    },

    closeAccessModal: function() {
        document.getElementById('access-modal').classList.remove('active');
    },

    openSnapshotsModal: function() {
        this.closeOverlays();
        const list = document.getElementById('snapshots-list');
        list.innerHTML = `<div style="text-align:center; color: var(--text-secondary); padding: 20px;">Loading Snapshots...</div>`;
        document.getElementById('snapshot-droplet-ip').innerText = 'Loading...';
        document.getElementById('snapshots-modal').classList.add('active');
        
        this.pushModalState('snapshots');
        this.loadSnapshots();
    },

    closeSnapshotsModal: function(isPopState = false, skipHistory = false) {
        const modal = document.getElementById('snapshots-modal');
        const wasActive = modal.classList.contains('active');
        modal.classList.remove('active');
        
        if (wasActive && !isPopState && !skipHistory) {
            if (history.state && history.state.modal === 'snapshots') {
                history.back();
            }
        }
    },

    loadSnapshots: function() {
        const list = document.getElementById('snapshots-list');
        fetch('index.php?ajax=get_snapshots')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('snapshot-droplet-ip').innerText = `${data.ip} (${data.droplet_name})`;
                    if (!data.snapshots || data.snapshots.length === 0) {
                        list.innerHTML = `<div style="text-align:center; padding: 30px; color: var(--text-secondary);">No VM snapshots found.</div>`;
                        return;
                    }
                    
                    let html = '';
                    data.snapshots.forEach(s => {
                        const size = s.size_gigabytes ? s.size_gigabytes + ' GB' : 'Processing...';
                        const date = new Date(s.created_at).toLocaleString();
                        html += `
                            <div class="backup-item">
                                <div class="backup-info">
                                    <span class="backup-filename">${s.name}</span>
                                    <span class="backup-meta">${date} &bull; ${size}</span>
                                </div>
                                <div class="backup-actions">
                                    <button class="icon-btn-small" onclick="App.restoreSnapshot('${s.id}', '${s.name}')" title="Restore Droplet to this Snapshot" style="color: var(--success); background: rgba(16,185,129,0.1); padding: 8px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                    </button>
                                    <button class="icon-btn-small" onclick="App.deleteSnapshot('${s.id}', '${s.name}')" title="Delete snapshot" style="color: var(--danger); background: rgba(239,68,68,0.1); padding: 8px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                    </button>
                                </div>
                            </div>
                        `;
                    });
                    list.innerHTML = html;
                } else {
                    list.innerHTML = `<div style="text-align:center; color: var(--danger); padding: 20px;">${data.error}</div>`;
                    document.getElementById('snapshot-droplet-ip').innerText = 'Error';
                }
            })
            .catch(err => {
                list.innerHTML = `<div style="text-align:center; color: var(--danger); padding: 20px;">Network Error</div>`;
            });
    },

    createSnapshot: function() {
        const btn = document.getElementById('btn-create-snapshot');
        const origText = btn.innerHTML;
        btn.innerHTML = `Initiating...`;
        btn.disabled = true;
        
        fetch('index.php?ajax=create_snapshot')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    btn.innerHTML = `Started! ✅`;
                    setTimeout(() => {
                        btn.innerHTML = origText;
                        btn.disabled = false;
                        this.loadSnapshots();
                    }, 2000);
                } else {
                    alert("Snapshot Failed: " + data.error);
                    btn.innerHTML = origText;
                    btn.disabled = false;
                }
            })
            .catch(err => {
                alert("Network Error");
                btn.innerHTML = origText;
                btn.disabled = false;
            });
    },

    restoreSnapshot: function(id, name) {
        if (!confirm(`🚨 CRITICAL WARNING:\n\nAre you sure you want to restore your Droplet to the snapshot: ${name}?\n\nThis will completely overwrite all files, databases, and configuration settings on your VPS. The Droplet will temporarily shut down during the restoration process.`)) return;
        
        const list = document.getElementById('snapshots-list');
        list.innerHTML = `<div style="text-align:center; color: var(--warning); padding: 20px;">Initiating Droplet restoration...<br><small>Do NOT reload this page. The server will briefly disconnect.</small></div>`;
        
        fetch('index.php?ajax=restore_snapshot', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ snapshot_id: id })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert("Restoration Action Initiated Successfully! Your Droplet is now rebuilding from the snapshot in the background. It will automatically boot back up once complete (usually takes 1-3 minutes).");
                this.loadSnapshots();
            } else {
                alert("Restore Action Failed: " + data.error);
                this.loadSnapshots();
            }
        })
        .catch(err => {
            alert("Network Error");
            this.loadSnapshots();
        });
    },

    deleteSnapshot: function(id, name) {
        if (!confirm(`Are you sure you want to permanently delete the snapshot: ${name}?`)) return;
        
        const list = document.getElementById('snapshots-list');
        list.innerHTML = `<div style="text-align:center; color: var(--warning); padding: 20px;">Deleting snapshot...</div>`;
        
        fetch('index.php?ajax=delete_snapshot', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ snapshot_id: id })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                this.loadSnapshots();
            } else {
                alert("Delete Failed: " + data.error);
                this.loadSnapshots();
            }
        })
        .catch(err => {
            alert("Network Error");
            this.loadSnapshots();
        });
    },

    onAccessGroupChange: function() {
        const select = document.getElementById('access-group-select');
        const newGroupContainer = document.getElementById('access-new-group-container');
        const emailsSection = document.getElementById('access-emails-section');
        
        if (select.value === "none") {
            newGroupContainer.style.display = 'none';
            if (emailsSection) emailsSection.style.display = 'none';
            this.accessEmails = [];
        } else if (select.value === "") {
            newGroupContainer.style.display = 'flex';
            if (emailsSection) emailsSection.style.display = 'block';
            this.accessEmails = [];
        } else {
            newGroupContainer.style.display = 'none';
            if (emailsSection) emailsSection.style.display = 'block';
            const group = this.accessGroups.find(g => g.id === select.value);
            this.accessEmails = [];
            if (group && group.include) {
                group.include.forEach(inc => {
                    if (inc.email && inc.email.email) {
                        this.accessEmails.push(inc.email.email);
                    }
                });
            }
        }
        this.renderAccessEmails();
    },

    renderAccessEmails: function() {
        const list = document.getElementById('access-email-list');
        if (this.accessEmails.length === 0) {
            list.innerHTML = `<div style="text-align:center; color: var(--text-secondary); padding: 10px; font-size: 13px;">No emails in this group.<br>Add one above.</div>`;
            return;
        }
        
        let html = '';
        this.accessEmails.forEach((email, idx) => {
            html += `
                <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.2); padding: 8px 12px; border-radius: 8px; font-size: 13px;">
                    <span>${email}</span>
                    <button class="icon-btn-small" onclick="App.removeAccessEmail(${idx})" style="color: var(--danger);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>
            `;
        });
        list.innerHTML = html;
    },

    addAccessEmail: function() {
        const input = document.getElementById('access-new-email');
        const email = input.value.trim().toLowerCase();
        if (!email) return;
        
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert("Please enter a valid email address.");
            return;
        }
        
        if (!this.accessEmails.includes(email)) {
            this.accessEmails.push(email);
            this.renderAccessEmails();
        }
        input.value = '';
    },

    removeAccessEmail: function(idx) {
        this.accessEmails.splice(idx, 1);
        this.renderAccessEmails();
    },

    saveAccessGroup: function() {
        const id = document.getElementById('access-instance-id').value;
        const select = document.getElementById('access-group-select');
        const groupId = select.value;
        
        let groupName = "";
        if (groupId === "none") {
            // No name needed
        } else if (groupId === "") {
            groupName = document.getElementById('access-new-group-name').value.trim();
            if (!groupName) {
                alert("Please provide a name for the new Access Group.");
                return;
            }
        } else {
            const group = this.accessGroups.find(g => g.id === groupId);
            if (group) groupName = group.name;
        }
        
        const btn = document.getElementById('btn-save-access');
        const origText = btn.innerText;
        btn.innerText = "Saving...";
        btn.disabled = true;
        
        fetch('index.php?ajax=save_access_group', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: id,
                group_id: groupId,
                group_name: groupName,
                emails: this.accessEmails
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                btn.innerText = "Saved! ✅";
                setTimeout(() => {
                    this.closeAccessModal();
                    btn.innerText = origText;
                    btn.disabled = false;
                    location.reload();
                }, 1000);
            } else {
                alert("Save Failed: " + data.error);
                btn.innerText = origText;
                btn.disabled = false;
            }
        })
        .catch(err => {
            console.error(err);
            alert("Network Error");
            btn.innerText = origText;
            btn.disabled = false;
        });
    },

    formatBytes: function(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024, sizes = ['Bytes', 'KB', 'MB', 'GB'], i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    toggleMaintenance: function(id) {
        this.closeOverlays();
        
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        const statusRing = card ? card.querySelector('.status-ring') : null;
        const origClass = statusRing ? statusRing.className : '';
        
        if (statusRing) {
            // Apply neutral status ring state to indicate active communication
            statusRing.className = 'status-ring offline'; 
        }
        
        fetch('index.php?ajax=toggle_maintenance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (statusRing) {
                    statusRing.className = 'status-ring ' + data.status;
                }
                if (navigator.vibrate) navigator.vibrate(30);
            } else {
                alert("Toggle Failed: " + data.error);
                if (statusRing) statusRing.className = origClass;
            }
        })
        .catch(err => {
            console.error(err);
            alert("Network error toggling maintenance.");
            if (statusRing) statusRing.className = origClass;
        });
    },

    confirmHardSync: function(id) {
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        const name = card ? card.getAttribute('data-name') : 'Instance';
        const templateName = card ? card.querySelector('.card-subtitle').innerText : '';
        const content = document.getElementById('action-sheet-content');
        
        content.innerHTML = `
            <div style="padding: 16px 0; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); margin-bottom: 16px;">
                <h3 style="font-size: 16px; font-weight: 700; color: var(--danger);">⚠️ Wipe & Deploy Local?</h3>
                <span style="font-size: 13px; color: var(--text-secondary); margin-top: 6px; display: block; line-height: 1.4; user-select: none;">
                    Are you sure you want to hard-sync <strong>${name}</strong>?<br><br>
                    This will <strong style="color: var(--danger);">permanently delete</strong> the live server's database, files, and backups, then upload a fresh copy from your local machine.
                </span>
                <div style="margin-top: 16px; background: rgba(0,0,0,0.15); padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                    <div style="font-size: 13px; font-weight: bold; color: var(--text-primary); text-align: left; margin-bottom: 4px;">Selective Packaging</div>
                    ${this.getSelectiveOptionsHTML('hardsync', templateName)}
                </div>
            </div>
            <button class="btn btn-primary" id="btn-confirm-hardsync" onclick="App.deployUpdate(${id}, true)" style="background: var(--danger); width: 100%; margin-bottom: 12px; height: 48px; font-size: 15px;">Yes, Wipe & Deploy</button>
            <button class="btn btn-secondary" onclick="App.closeOverlays()" style="width: 100%; height: 48px; font-size: 15px;">Cancel</button>
        `;
    },

    deleteInstance: function(id) {
        this.confirmDeleteInstance(id);
    },

    confirmDeleteInstance: function(id) {
        const card = document.querySelector(`.instance-card[data-id="${id}"]`);
        const name = card ? card.getAttribute('data-name') : 'Instance';
        const content = document.getElementById('action-sheet-content');
        
        content.innerHTML = `
            <div style="padding: 16px 0; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); margin-bottom: 16px;">
                <h3 style="font-size: 16px; font-weight: 700; color: var(--danger);">⚠️ Permanently Delete App?</h3>
                <span style="font-size: 13px; color: var(--text-secondary); margin-top: 6px; display: block; line-height: 1.4; user-select: none;">
                    Are you sure you want to delete <strong>${name}</strong>?<br>
                    This will permanently remove all files, databases, backups, and Cloudflare DNS records.
                </span>
            </div>
            <button class="btn btn-primary" id="btn-confirm-delete" onclick="App.executeDeleteInstance(${id})" style="background: var(--danger); width: 100%; margin-bottom: 12px; height: 48px; font-size: 15px;">Yes, Delete Everything</button>
            <button class="btn btn-secondary" onclick="App.closeOverlays()" style="width: 100%; height: 48px; font-size: 15px;">Cancel</button>
        `;
    },

    executeDeleteInstance: function(id) {
        const btn = document.getElementById('btn-confirm-delete');
        if (btn) {
            btn.innerText = "Deleting... Please wait";
            btn.disabled = true;
            btn.style.opacity = "0.6";
        }
        
        fetch('index.php?ajax=delete_instance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (btn) btn.innerText = "Deleted! Reloading...";
                setTimeout(() => {
                    this.closeOverlays();
                    location.reload();
                }, 1000);
            } else {
                alert("Deletion failed: " + (data.error || "Unknown error"));
                if (btn) {
                    btn.innerText = "Yes, Delete Everything";
                    btn.disabled = false;
                    btn.style.opacity = "1";
                }
            }
        })
        .catch(err => {
            console.error(err);
            alert("Network connection error. Check server logs.");
            if (btn) {
                btn.innerText = "Yes, Delete Everything";
                btn.disabled = false;
                btn.style.opacity = "1";
            }
        });
    },

    openAppViewer: function(title, url) {
        const modal = document.getElementById('app-viewer-modal');
        const iframe = document.getElementById('app-viewer-iframe');
        const titleElem = document.getElementById('viewer-title');
        
        titleElem.innerText = title;
        iframe.src = url;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden'; // Lock background scrolling
    },

    closeAppViewer: function() {
        const modal = document.getElementById('app-viewer-modal');
        const iframe = document.getElementById('app-viewer-iframe');
        modal.classList.remove('active');
        iframe.src = 'about:blank'; // Prevent background media leak
        document.body.style.overflow = '';
    },

    reloadAppViewer: function() {
        const iframe = document.getElementById('app-viewer-iframe');
        iframe.contentWindow.location.reload();
    }
};

document.addEventListener('DOMContentLoaded', () => {
    App.init();
});