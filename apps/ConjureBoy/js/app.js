// apps/ConjureBoy/js/app.js

(function() {
    'use strict';

    // Global Compatibility Bindings for GameBoyCore.js
    window.GameBoyWindow = window;
    
    window.initNewCanvas = function() {
        // Stubbed safely to prevent ReferenceErrors during coordinate calculations
        if (window.Emulator && window.Emulator.canvas) {
            window.Emulator.canvas.width = 160;
            window.Emulator.canvas.height = 144;
        }
    };

    window.pause = function() {
        if (window.App && window.App.logTerminal) {
            window.App.logTerminal("[SYSTEM] Core execution paused by internal fault.", "warning");
        }
    };

    // Provide a modern Web Audio API polyfill to replace the legacy XAudioServer
    window.XAudioServer = class {
        constructor(channels, sampleRate, minBufferSize, maxBufferSize, underRunCallback, volume, failureCallback) {
            this.channels = channels;
            this.sampleRate = sampleRate; // The emulator's generated rate (~44150.56 Hz)
            this.volume = volume;
            this.speedAdjustment = 1.0;
            try {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                // Request lowest possible hardware latency
                this.audioCtx = new AudioCtx({ latencyHint: 'interactive' });
                this.gainNode = this.audioCtx.createGain();
                this.gainNode.gain.value = this.volume;
                this.gainNode.connect(this.audioCtx.destination);
                this.startTime = this.audioCtx.currentTime;
            } catch (e) {
                if (failureCallback) failureCallback();
            }
        }
        writeAudioNoCallback(samples) {
            if (!this.audioCtx) return;
            const length = samples.length / this.channels;
            const nativeRate = this.audioCtx.sampleRate;
            // Apply the user's manual speed tuning factor
            const effectiveSampleRate = this.sampleRate * this.speedAdjustment;
            const ratio = effectiveSampleRate / nativeRate;
            const inputFrames = samples.length / this.channels;
            const outputFrames = Math.floor(inputFrames / ratio);
            
            // Create a buffer perfectly matching the device's native hardware rate
            const audioBuffer = this.audioCtx.createBuffer(this.channels, outputFrames, nativeRate);
            
            // Manually resample the audio via Linear Interpolation
            for (let c = 0; c < this.channels; c++) {
                const channelData = audioBuffer.getChannelData(c);
                for (let i = 0; i < outputFrames; i++) {
                    const srcIndex = i * ratio;
                    const index1 = Math.floor(srcIndex);
                    const index2 = Math.min(index1 + 1, inputFrames - 1);
                    const fraction = srcIndex - index1;
                    
                    const sample1 = samples[index1 * this.channels + c];
                    const sample2 = samples[index2 * this.channels + c];
                    
                    channelData[i] = sample1 + (sample2 - sample1) * fraction;
                }
            }
            
            const source = this.audioCtx.createBufferSource();
            source.buffer = audioBuffer;
            source.connect(this.gainNode);
            
            const currentTime = this.audioCtx.currentTime;
            const trueDuration = outputFrames / nativeRate;
            
            // Handle underrun (audio starved)
            if (this.startTime < currentTime) {
                this.startTime = currentTime + 0.02; // 20ms safety padding
            }
            
            // Handle overrun (buffer bloat > 100ms)
            if (this.startTime > currentTime + 0.1) {
                // Drop this audio chunk to maintain sync without overlapping/popping
                return; 
            }
            
            source.start(this.startTime);
            this.startTime += trueDuration;
        }
        changeVolume(v) {
            this.volume = v;
            if (this.gainNode) this.gainNode.gain.value = v;
        }
        changeSpeed(s) {
            this.speedAdjustment = s;
        }
        remainingBuffer() {
            // Return a massive number to completely disable GameBoyCore's internal CPU speed-up hack.
            // We are strictly controlling the CPU via our 59.7 FPS requestAnimationFrame loop.
            return 9999999;
        }
    };

    // Application namespace
    window.App = {
        user: null,
        hapticsEnabled: true,
        volume: 0.5,
        isMuted: false,
        magnifierScale: 1.15,
        roms: [],
        activeRom: null,
        pendingQuickLoad: false,

        init: function() {
            this.bindDOMElements();
            this.initLocalModules();

            this.initAestheticInteractions();
            this.bindROMManager();
            this.bindStateSlots();
            this.bindAuthEvents();
            
            this.checkAuthState();
            
            this.Toast.show("Console Frame Assembled", "success");
        },

        bindAuthEvents: function() {
            var self = this;
            this.el.btnLogin.addEventListener('click', function() {
                self.authenticate('login');
            });
            this.el.btnRegister.addEventListener('click', function() {
                self.authenticate('register');
            });
            this.el.btnLogout.addEventListener('click', function() {
                fetch('index.php?action=logout')
                    .then(res => res.json())
                    .then(data => {
                        self.user = null;
                        self.updateAuthUI();
                        self.roms = [];
                        self.renderCartridgeGrid();
                        self.Toast.show("Logged out", "info");
                        if (window.Emulator) window.Emulator.stop();
                        self.ejectROM();
                    });
            });
        },

        authenticate: function(action) {
            var self = this;
            var username = this.el.authUsername.value.trim();
            var password = this.el.authPassword.value;
            if (!username || !password) {
                this.Toast.show("Username and password required", "error");
                return;
            }
            fetch('index.php?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: username, password: password })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    self.user = data.user;
                    if (data.settings) {
                        self.applySettings(data.settings);
                    }
                    self.el.authPassword.value = '';
                    self.updateAuthUI();
                    self.loadROMCatalog();
                    self.Toast.show("Welcome, " + self.user.username, "success");
                } else {
                    self.Toast.show(data.error, "error");
                }
            })
            .catch(err => self.Toast.show("Authentication failed", "error"));
        },

        checkAuthState: function() {
            var self = this;
            fetch('index.php?action=me')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        self.user = data.user;
                        if (data.settings) {
                            self.applySettings(data.settings);
                        }
                        self.updateAuthUI();
                        self.loadROMCatalog();
                    } else {
                        self.user = null;
                        self.updateAuthUI();
                        // Read initial state values from DOM elements as fallback
                        self.volume = parseFloat(self.el.volumeSlider.value);
                        self.hapticsEnabled = self.el.toggleHaptic.classList.contains('on');
                        self.magnifierScale = parseFloat(self.el.magnifierAcc.getAttribute('data-scale')) || 1.15;
                        self.syncEmulatorSettings();
                        self.checkAndInstallCore();
                        self.loadROMCatalog(); // Clear grid if needed
                    }
                })
                .catch(err => {
                    self.user = null;
                    self.updateAuthUI();
                });
        },

        updateAuthUI: function() {
            if (this.user) {
                this.el.authGuestView.style.display = 'none';
                this.el.authUserView.style.display = 'block';
                this.el.dropZone.style.display = 'flex';
                this.el.profileUsername.textContent = this.user.username;
                this.el.profileRole.textContent = this.user.role;
                this.el.profileRole.className = 'role-badge ' + this.user.role;
            } else {
                this.el.authGuestView.style.display = 'block';
                this.el.authUserView.style.display = 'none';
                this.el.dropZone.style.display = 'none'; // Hide upload zone for guests
            }
        },

        applySettings: function(settings) {
            this.volume = settings.sound_volume ?? 0.5;
            this.hapticsEnabled = settings.haptics_enabled ?? true;
            this.magnifierScale = settings.magnifier_scale ?? 1.15;
            
            this.el.volumeSlider.value = this.volume;
            if (this.hapticsEnabled) {
                this.el.toggleHaptic.classList.add('on');
            } else {
                this.el.toggleHaptic.classList.remove('on');
            }
            if (settings.lcd_grid) {
                this.el.toggleGrid.classList.add('on');
                this.el.screenOverlay.classList.add('active');
            } else {
                this.el.toggleGrid.classList.remove('on');
                this.el.screenOverlay.classList.remove('active');
            }
            
            this.updateMagnifierScale();

            // Apply visual theme to body wrapper
            var theme = settings.theme || 'classic-dmg';
            document.body.className = '';
            document.body.classList.add('theme-' + theme);
            
            var options = this.el.themeOptions.querySelectorAll('.custom-option');
            var self = this;
            options.forEach(function(opt) { 
                opt.classList.remove('selected'); 
                if (opt.getAttribute('data-value') === theme) {
                    opt.classList.add('selected');
                    self.el.themeTrigger.textContent = opt.textContent;
                }
            });

            this.syncEmulatorSettings();
            this.checkAndInstallCore();
        },

        bindDOMElements: function() {
            this.el = {
                container: document.getElementById('app-container'),
                consoleBody: document.querySelector('.console-body'),
                powerLed: document.getElementById('power-led'),
                screenOverlay: document.getElementById('lcd-overlay'),
                standbyScreen: document.getElementById('standby-screen'),
                loadedCartridge: document.getElementById('loaded-cartridge'),
                
                // Auth elements
                authGuestView: document.getElementById('auth-guest-view'),
                authUserView: document.getElementById('auth-user-view'),
                authUsername: document.getElementById('auth-username'),
                authPassword: document.getElementById('auth-password'),
                btnRegister: document.getElementById('btn-register'),
                btnLogin: document.getElementById('btn-login'),
                btnLogout: document.getElementById('btn-logout'),
                profileUsername: document.getElementById('profile-username'),
                profileRole: document.getElementById('profile-role'),
                profileQuota: document.getElementById('profile-quota'),

                // Sidebar Cartridge deck
                deck: document.getElementById('cartridge-deck'),
                dropZone: document.getElementById('drop-zone'),
                fileInput: document.getElementById('rom-file-input'),
                btnRefresh: document.getElementById('btn-refresh-library'),
                utilCard: document.getElementById('util-card'),
                btnEject: document.getElementById('btn-eject'),
                btnPauseResume: document.getElementById('btn-pause-resume'),
                btnReset: document.getElementById('btn-reset'),

                // Dialog overlays
                dialog: document.getElementById('dialog-overlay'),
                dialogTitle: document.getElementById('dialog-title'),
                dialogMsg: document.getElementById('dialog-message'),
                dialogInputContainer: document.getElementById('dialog-input-container'),
                dialogInput: document.getElementById('dialog-input'),
                dialogCancel: document.getElementById('dialog-cancel'),
                dialogOk: document.getElementById('dialog-ok'),
                toastContainer: document.getElementById('toast-container'),

                // Custom settings options
                mobileToggle: document.getElementById('btn-mobile-drawer'),
                auxBtn: document.getElementById('btn-aux'),
                fullscreenBtn: document.getElementById('btn-fscrn'),
                auxSpace: document.getElementById('aux-space'),

                // Magnifier Booster Accessory
                magnifierAcc: document.getElementById('magnifier-acc'),
                magnifierTab: document.getElementById('magnifier-tab'),
                magnifierLightBtn: document.getElementById('btn-magnifier-light'),
                btnQuickSave: document.getElementById('btn-quick-save'),
                btnQuickLoad: document.getElementById('btn-aux-load'),
                toggleRapidFire: document.getElementById('toggle-rapid-fire'),
                btnSpeedUp: document.getElementById('btn-speed-up'),
                btnSpeedDown: document.getElementById('btn-speed-down'),
                speedIndicator: document.getElementById('speed-indicator'),
                deckColumn: document.getElementById('deck-column'),
                themeTrigger: document.getElementById('theme-selector-trigger'),
                themeOptions: document.getElementById('theme-selector-options'),
                volumeSlider: document.getElementById('volume-slider'),
                toggleGrid: document.getElementById('toggle-grid'),
                toggleHaptic: document.getElementById('toggle-haptic'),
                btnZoomUp: document.getElementById('btn-zoom-up'),
                btnZoomDown: document.getElementById('btn-zoom-down'),
                zoomValueLabel: document.getElementById('zoom-value-label'),
                btnQuickLoad: document.getElementById('btn-aux-load'),
                toggleRapidFire: document.getElementById('toggle-rapid-fire'),
                btnSpeedUp: document.getElementById('btn-speed-up'),
                btnSpeedDown: document.getElementById('btn-speed-down'),
                speedIndicator: document.getElementById('speed-indicator'),
                deckColumn: document.getElementById('deck-column'),
                themeTrigger: document.getElementById('theme-selector-trigger'),
                themeOptions: document.getElementById('theme-selector-options'),
                volumeSlider: document.getElementById('volume-slider'),
                toggleGrid: document.getElementById('toggle-grid'),
                toggleHaptic: document.getElementById('toggle-haptic'),

                // Monospace Terminal UI
                terminalOutput: document.getElementById('terminal-output'),
                btnClearTerminal: document.getElementById('btn-clear-terminal'),
                btnCopyTerminal: document.getElementById('btn-copy-terminal'),

                // Downloader overlay
                bootstrapOverlay: document.getElementById('bootstrap-overlay'),
                bootstrapDownload: document.getElementById('bootstrap-download'),
                bootstrapCancel: document.getElementById('bootstrap-cancel'),
                bootstrapProgressWrap: document.getElementById('bootstrap-progress-wrap'),
                bootstrapProgress: document.getElementById('bootstrap-progress'),

                // Close deck button
                btnCloseDeck: document.getElementById('btn-close-deck'),

                // Manual booklet elements
                btnManual: document.getElementById('btn-manual'),
                manualOverlay: document.getElementById('manual-overlay'),
                manualClose: document.getElementById('manual-close')
            };
        },

        // Single Source of Truth UI widgets (Toasts & Dialog overlay)
        initLocalModules: function() {
            var self = this;

            // Global cout Interceptor for standard GameBoyCore logging
            window.cout = function(msg, category) {
                let type = 'info';
                if (category === 1) type = 'warning';
                if (category === 2) type = 'error';
                self.logTerminal(msg, type);
            };

            // Toast feedback
            this.Toast = {
                show: function(msg, type) {
                    var el = document.createElement('div');
                    el.className = 'custom-toast' + (type ? ' toast-' + type : '');
                    el.innerHTML = '<span>🎮</span> ' + msg;
                    self.el.toastContainer.appendChild(el);
                    
                    // Force render paint reflow
                    el.offsetHeight;
                    
                    el.classList.add('show');
                    setTimeout(function() {
                        el.classList.remove('show');
                        setTimeout(function() {
                            el.remove();
                        }, 300);
                    }, 3500);
                }
            };

            // Non-native confirmation/prompts dialogs
            this.Dialog = {
                activeCallback: null,
                activeCancelCallback: null,

                confirm: function(title, msg, onOk, onCancel) {
                    self.restoreDefaultDialogActions();
                    self.el.dialogTitle.textContent = title;
                    self.el.dialogMsg.textContent = msg;
                    self.el.dialogInputContainer.style.display = 'none';
                    self.el.dialogCancel.style.display = 'block';
                    self.el.dialog.classList.add('active');
                    this.activeCallback = onOk;
                    this.activeCancelCallback = onCancel || null;
                },

                prompt: function(title, msg, defaultVal, onOk, onRevert) {
                    self.restoreDefaultDialogActions();
                    self.el.dialogTitle.textContent = title;
                    self.el.dialogMsg.textContent = msg;
                    self.el.dialogInput.value = defaultVal || '';
                    self.el.dialogInputContainer.style.display = 'block';
                    self.el.dialogCancel.style.display = 'block';
                    
                    if (onRevert) {
                        const actionsBox = self.el.dialog.querySelector('.dialog-actions');
                        const btnRevert = document.createElement('button');
                        btnRevert.className = 'btn btn-secondary';
                        btnRevert.textContent = '↩ Revert';
                        btnRevert.onclick = function() {
                            onRevert();
                        };
                        actionsBox.insertBefore(btnRevert, self.el.dialogOk);
                    }
                    
                    self.el.dialog.classList.add('active');
                    self.el.dialogInput.focus();
                    this.activeCallback = function() {
                        onOk(self.el.dialogInput.value);
                    };
                },

                // Dynamic Action Sheet loader for slot operations
                showSlotMenu: function(slotNum, occupied) {
                    self.el.dialogTitle.textContent = `Save State - Slot ${slotNum}`;
                    self.el.dialogMsg.textContent = occupied 
                        ? "This slot contains a saved game state. Would you like to load this state, overwrite it with your current game, or delete it?"
                        : "This save state slot is empty. Would you like to save your current game state into this slot?";
                    
                    self.el.dialogInputContainer.style.display = 'none';
                    
                    const actionsBox = self.el.dialog.querySelector('.dialog-actions');
                    actionsBox.innerHTML = ''; // Purge defaults
                    actionsBox.classList.add('vertical');

                    if (occupied) {
                        const btnLoad = document.createElement('button');
                        btnLoad.className = 'btn btn-primary';
                        btnLoad.textContent = '📂 Load';
                        btnLoad.onclick = function() {
                            self.Dialog.close();
                            if (window.Emulator) window.Emulator.loadState(slotNum);
                        };
                        actionsBox.appendChild(btnLoad);

                        const btnOverwrite = document.createElement('button');
                        btnOverwrite.className = 'btn btn-secondary';
                        btnOverwrite.textContent = '💾 Overwrite';
                        btnOverwrite.onclick = function() {
                            self.Dialog.close();
                            if (window.Emulator) window.Emulator.saveState(slotNum);
                        };
                        actionsBox.appendChild(btnOverwrite);

                        const btnDelete = document.createElement('button');
                        btnDelete.className = 'btn btn-danger';
                        btnDelete.textContent = '🗑️ Delete';
                        btnDelete.onclick = function() {
                            self.Dialog.close();
                            self.Dialog.confirm(
                                "Purge Snapshot",
                                `Are you sure you want to delete the save state in Slot ${slotNum}?`,
                                function() {
                                    if (window.Emulator) window.Emulator.deleteState(slotNum);
                                }
                            );
                        };
                        actionsBox.appendChild(btnDelete);
                    } else {
                        const btnSave = document.createElement('button');
                        btnSave.className = 'btn btn-primary';
                        btnSave.textContent = '💾 Save State';
                        btnSave.onclick = function() {
                            self.Dialog.close();
                            if (window.Emulator) window.Emulator.saveState(slotNum);
                        };
                        actionsBox.appendChild(btnSave);
                    }

                    const btnCancel = document.createElement('button');
                    btnCancel.className = 'btn btn-secondary';
                    btnCancel.textContent = 'Cancel';
                    btnCancel.onclick = function() {
                        self.Dialog.close();
                    };
                    actionsBox.appendChild(btnCancel);

                    self.el.dialog.classList.add('active');
                },

                close: function() {
                    self.el.dialog.classList.remove('active');
                    this.activeCallback = null;
                    this.activeCancelCallback = null;
                }
            };

            // Bind default action clicks
            this.restoreDefaultDialogActions();

            // Clear & Copy Terminal events
            this.el.btnClearTerminal.addEventListener('click', function() {
                self.el.terminalOutput.innerHTML = '<div class="log-row log-status">[SYSTEM] Terminal cleared. Standing by...</div>';
            });

            this.el.btnCopyTerminal.addEventListener('click', function() {
                var text = '';
                var rows = self.el.terminalOutput.querySelectorAll('.log-row');
                rows.forEach(function(r) {
                    text += r.textContent + '\n';
                });
                
                var finalReport = "```text\n" + text + "```";
                
                var textarea = document.createElement('textarea');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                textarea.value = finalReport;
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    self.Toast.show("Telemetry report copied to clipboard", "success");
                } catch(e) {
                    self.Toast.show("Failed to copy log", "error");
                }
                document.body.removeChild(textarea);
            });

            // Bootstrap Core Downloader execution
            this.el.bootstrapDownload.addEventListener('click', function() {
                self.el.bootstrapDownload.disabled = true;
                self.el.bootstrapProgressWrap.style.display = 'block';
                self.el.bootstrapProgress.style.width = '40%';
                self.logTerminal("Downloading GameBoyCore.js runtime from edge CDN...", "status");
                
                fetch('index.php?action=bootstrap_core')
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        if (data.success) {
                            self.el.bootstrapProgress.style.width = '100%';
                            self.logTerminal("Bootstrapped successfully! Saved core locally to js/core/.", "success");
                            self.Toast.show("Emulator runtime core installed!", "success");
                            setTimeout(function() {
                                self.el.bootstrapOverlay.classList.remove('active');
                                window.location.reload();
                            }, 1200);
                        } else {
                            self.el.bootstrapDownload.disabled = false;
                            self.logTerminal("Bootstrap failed: " + data.error, "error");
                            self.Toast.show("Bootstrap failed", "error");
                        }
                    })
                    .catch(function(err) {
                        self.el.bootstrapDownload.disabled = false;
                        self.logTerminal("Server network/CA transmission error.", "error");
                        self.Toast.show("Connection failed", "error");
                    });
            });

            this.el.bootstrapCancel.addEventListener('click', function() {
                self.el.bootstrapOverlay.classList.remove('active');
                self.Toast.show("Core deployment canceled", "info");
            });

            // Manual Overlay trigger bindings
            if (this.el.btnManual) {
                this.el.btnManual.addEventListener('click', function(e) {
                    e.stopPropagation();
                    self.triggerHapticFeedback(15);
                    self.el.manualOverlay.classList.add('active');
                });
            }
            if (this.el.manualClose) {
                this.el.manualClose.addEventListener('click', function(e) {
                    e.stopPropagation();
                    self.triggerHapticFeedback(10);
                    self.el.manualOverlay.classList.remove('active');
                });
            }
        },

        restoreDefaultDialogActions: function() {
            const self = this;
            const actionsBox = this.el.dialog.querySelector('.dialog-actions');
            actionsBox.innerHTML = '';
            actionsBox.classList.remove('vertical');
            
            const btnCancel = document.createElement('button');
            btnCancel.id = 'dialog-cancel';
            btnCancel.className = 'btn btn-secondary';
            btnCancel.textContent = 'Cancel';
            btnCancel.onclick = function() {
                if (typeof self.Dialog.activeCancelCallback === 'function') {
                    self.Dialog.activeCancelCallback();
                }
                self.Dialog.close();
            };
            
            const btnOk = document.createElement('button');
            btnOk.id = 'dialog-ok';
            btnOk.className = 'btn btn-primary';
            btnOk.textContent = 'Proceed';
            btnOk.onclick = function() {
                if (typeof self.Dialog.activeCallback === 'function') {
                    self.Dialog.activeCallback();
                }
                self.Dialog.close();
            };

            actionsBox.appendChild(btnCancel);
            actionsBox.appendChild(btnOk);
            this.el.dialogCancel = btnCancel;
            this.el.dialogOk = btnOk;
        },

        openShelf: function() {
            var self = this;
            this.el.deckColumn.classList.add('open');
            this.el.mobileToggle.classList.add('pressed');
            
            // Push history state to intercept browser back gesture without altering URL hash
            history.pushState({ shelfOpen: true }, '');
            
            window.onpopstate = function(e) {
                if (self.el.deckColumn.classList.contains('open')) {
                    self.closeShelf(true);
                }
            };
        },

        closeShelf: function(fromPopstate) {
            if (!this.el.deckColumn.classList.contains('open')) return;

            this.el.deckColumn.classList.remove('open');
            this.el.mobileToggle.classList.remove('pressed');
            
            window.onpopstate = null;
            
            // If closed via manual tap instead of swipe gesture, pop the fake history item
            if (!fromPopstate) {
                history.back();
            }
        },

        quickResumePrompt: function() {
            var lastHash = localStorage.getItem('cjb_last_rom');
            if (!lastHash) {
                this.Toast.show("No recent game found", "error");
                return;
            }
            var targetRom = this.roms.find(r => r.rom_hash === lastHash);
            if (!targetRom) {
                this.Toast.show("Recent game cartridge missing", "error");
                return;
            }
            var self = this;
            this.Dialog.confirm(
                "Quick Resume",
                "Do you want to load '" + targetRom.display_name + "' and automatically resume from your Quick Save?",
                function() {
                    self.pendingQuickLoad = true;
                    self.insertCartridge(targetRom);
                }
            );
        },

        logTerminal: function(msg, type) {
            var row = document.createElement('div');
            row.className = 'log-row log-' + (type || 'info');
            
            var now = new Date();
            var timeStr = "[" + 
                String(now.getHours()).padStart(2, '0') + ":" + 
                String(now.getMinutes()).padStart(2, '0') + ":" + 
                String(now.getSeconds()).padStart(2, '0') + 
                "] ";
                
            row.textContent = timeStr + msg;
            this.el.terminalOutput.appendChild(row);
            this.el.terminalOutput.scrollTop = this.el.terminalOutput.scrollHeight;
            
            while (this.el.terminalOutput.childNodes.length > 500) {
                this.el.terminalOutput.removeChild(this.el.terminalOutput.firstChild);
            }
        },

        checkAndInstallCore: function() {
            var self = this;
            this.logTerminal("Scanning server-side runtime directory...", "status");
            
            // Check for both GB/GBC and GBA cores
            if (typeof window.GameBoyCore === 'function' && typeof window.GameBoyAdvance === 'function') {
                this.logTerminal("GBC & GBA core instructions verified.", "success");
            } else {
                this.logTerminal("One or more emulator cores missing from server workspace.", "warning");
                // Prompt user to fetch runtime automatically
                setTimeout(function() {
                    self.el.bootstrapOverlay.classList.add('active');
                }, 800);
            }
        },

        syncEmulatorSettings: function() {
            window.settings = [
                true,                                   // [0] Sound enabled
                false,                                  // [1] Use boot ROM
                false,                                  // [2] Force DMG mode
                this.volume,                            // [3] Volume
                true,                                   // [4] Colorize DMG games
                false,                                  // [5] Disable typed arrays
                1000 / 59.7275,                         // [6] Speed timing (Aligns CPU clocks to exactly 59.7 FPS)
                4,                                      // [7] Buffer containment (Frames)
                8,                                      // [8] Audio threshold (Frames)
                false,                                  // [9] Disable banks override
                true,                                   // [10] Persistent SRAM
                false,                                  // [11] Use DMG boot ROM
                false,                                  // [12] Scaling override
                false,                                  // [13] Smooth scaling
                [true, true, true, true]                // [14] Sound channels enabled
            ];
        },

        initAestheticInteractions: function() {
            var self = this;

            // 3D Glass shine reflections coordinate tracker mapped relative to frame
            window.addEventListener('mousemove', function(e) {
                var pctX = (e.clientX / window.innerWidth) * 100;
                var pctY = (e.clientY / window.innerHeight) * 100;
                document.documentElement.style.setProperty('--sheen-x', pctX + '%');
                document.documentElement.style.setProperty('--sheen-y', pctY + '%');
            });

            // Handle device tilts accelerometer support if available
            if (window.DeviceOrientationEvent) {
                window.addEventListener('deviceorientation', function(e) {
                    if (e.gamma !== null && e.beta !== null) {
                        var x = ((e.gamma + 90) / 180) * 100;
                        var y = ((e.beta + 180) / 360) * 100;
                        document.documentElement.style.setProperty('--sheen-x', x + '%');
                        document.documentElement.style.setProperty('--sheen-y', y + '%');
                    }
                }, true);
            }

            // Keyboard controller mappings pressed animations bindings
            var gbButtonsMap = {
                'ArrowUp': 'UP',
                'ArrowDown': 'DOWN',
                'ArrowLeft': 'LEFT',
                'ArrowRight': 'RIGHT',
                'z': 'A', 'Z': 'A',
                'x': 'B', 'X': 'B',
                'Enter': 'START',
                'Shift': 'SELECT',
                'q': 'L', 'Q': 'L',
                'e': 'R', 'E': 'R',
                'r': 'R', 'R': 'R'
            };

            window.addEventListener('keydown', function(e) {
                var btn = gbButtonsMap[e.key];
                if (btn) {
                    var el = self.getVirtualButtonDOM(btn);
                    if (el) el.classList.add('pressed');
                    self.triggerHapticFeedback();
                    if (window.Emulator && !e.repeat) window.Emulator.pressKey(btn);
                }
            });

            window.addEventListener('keyup', function(e) {
                var btn = gbButtonsMap[e.key];
                if (btn) {
                    var el = self.getVirtualButtonDOM(btn);
                    if (el) el.classList.remove('pressed');
                    if (window.Emulator) window.Emulator.releaseKey(btn);
                }
            });

            // Direct Touch/Click gamepad listeners
            var allControls = document.querySelectorAll('[data-btn]');
            allControls.forEach(function(btnEl) {
                var btnName = btnEl.getAttribute('data-btn');
                
                var handlePressStart = function(e) {
                    e.preventDefault();
                    btnEl.classList.add('pressed');
                    self.triggerHapticFeedback();
                    if (window.Emulator) window.Emulator.pressKey(btnName);
                };

                var handlePressEnd = function(e) {
                    e.preventDefault();
                    btnEl.classList.remove('pressed');
                    if (window.Emulator) window.Emulator.releaseKey(btnName);
                };

                btnEl.addEventListener('mousedown', handlePressStart);
                btnEl.addEventListener('mouseup', handlePressEnd);
                btnEl.addEventListener('mouseleave', handlePressEnd);

                // Only attach individual touch events to non-Dpad buttons (D-pad uses the sliding parent controller)
                if (!btnEl.classList.contains('dpad-btn')) {
                    btnEl.addEventListener('touchstart', handlePressStart, { passive: false });
                    btnEl.addEventListener('touchend', handlePressEnd, { passive: false });
                    btnEl.addEventListener('touchcancel', handlePressEnd, { passive: false });
                }
            });

            // Slide/swipe D-pad touch controller supporting continuous sliding direction changes & multi-touch
            var dpadCross = document.getElementById('dpad-cross');
            if (dpadCross) {
                var currentActiveBtn = null;
                var dpadTouchId = null;

                var handleDpadTouch = function(e) {
                    e.preventDefault();
                    
                    var touch = null;
                    
                    if (dpadTouchId === null) {
                        // Find a touch that just started inside dpadCross
                        var targetTouches = e.changedTouches || e.touches;
                        for (var i = 0; i < targetTouches.length; i++) {
                            var t = targetTouches[i];
                            var el = document.elementFromPoint(t.clientX, t.clientY);
                            if (el && el.closest('#dpad-cross')) {
                                touch = t;
                                dpadTouchId = t.identifier;
                                break;
                            }
                        }
                    } else {
                        // Find the ongoing touch matching the tracked dpadTouchId
                        for (var i = 0; i < e.touches.length; i++) {
                            if (e.touches[i].identifier === dpadTouchId) {
                                touch = e.touches[i];
                                break;
                            }
                        }
                    }
                    
                    if (!touch) {
                        // If the tracked D-pad touch is lost, release any active keys
                        if (currentActiveBtn) {
                            var prevEl = self.getVirtualButtonDOM(currentActiveBtn);
                            if (prevEl) prevEl.classList.remove('pressed');
                            if (window.Emulator) window.Emulator.releaseKey(currentActiveBtn);
                            currentActiveBtn = null;
                        }
                        
                        dpadTouchId = null;
                        return;
                    }

                    var element = document.elementFromPoint(touch.clientX, touch.clientY);
                    var targetBtn = null;

                    if (element) {
                        targetBtn = element.closest('.dpad-btn');
                    }

                    var targetBtnName = targetBtn ? targetBtn.getAttribute('data-btn') : null;

                    if (targetBtnName !== currentActiveBtn) {
                        // Release previous button
                        if (currentActiveBtn) {
                            var prevEl = self.getVirtualButtonDOM(currentActiveBtn);
                            if (prevEl) prevEl.classList.remove('pressed');
                            if (window.Emulator) window.Emulator.releaseKey(currentActiveBtn);
                        }

                        // Press new button
                        if (targetBtnName) {
                            var newEl = self.getVirtualButtonDOM(targetBtnName);
                            if (newEl) newEl.classList.add('pressed');
                            self.triggerHapticFeedback(12);
                            if (window.Emulator) window.Emulator.pressKey(targetBtnName);
                        }

                        currentActiveBtn = targetBtnName;
                    }
                };

                dpadCross.addEventListener('touchstart', handleDpadTouch, { passive: false });
                dpadCross.addEventListener('touchmove', handleDpadTouch, { passive: false });
                
                var handleDpadRelease = function(e) {
                    e.preventDefault();
                    if (dpadTouchId === null) return;

                    var dpadTouchEnded = false;
                    var changed = e.changedTouches || [];
                    for (var i = 0; i < changed.length; i++) {
                        if (changed[i].identifier === dpadTouchId) {
                            dpadTouchEnded = true;
                            break;
                        }
                    }

                    if (dpadTouchEnded) {
                        if (currentActiveBtn) {
                            var prevEl = self.getVirtualButtonDOM(currentActiveBtn);
                            if (prevEl) prevEl.classList.remove('pressed');
                            if (window.Emulator) window.Emulator.releaseKey(currentActiveBtn);
                            currentActiveBtn = null;
                        }
                        
                        dpadTouchId = null;
                    }
                };

                dpadCross.addEventListener('touchend', handleDpadRelease, { passive: false });
                dpadCross.addEventListener('touchcancel', handleDpadRelease, { passive: false });
            }

            // Smart D-pad center toggle for speed bypass (asterisk mode)
            var dpadCenter = document.querySelector('.dpad-center');
            if (dpadCenter) {
                var toggleCenterBypass = function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.triggerHapticFeedback(40);
                    if (window.Emulator && self.activeRom) {
                        window.Emulator.toggleSpeedBypass();
                    }
                };
                dpadCenter.addEventListener('mousedown', toggleCenterBypass);
                dpadCenter.addEventListener('touchstart', toggleCenterBypass, { passive: false });
            }

            // Mobile sliding drawer toggles (Triggers from the physical console button!)
            if (this.el.mobileToggle) {
                let shelfTimer = null;
                let isShelfLongPress = false;

                const startShelf = function(e) {
                    e.preventDefault();
                    isShelfLongPress = false;
                    shelfTimer = setTimeout(function() {
                        isShelfLongPress = true;
                        self.triggerHapticFeedback(50);
                        self.quickResumePrompt();
                    }, 500);
                };

                const endShelf = function(e) {
                    e.preventDefault();
                    if (shelfTimer) {
                        clearTimeout(shelfTimer);
                        shelfTimer = null;
                    }
                    if (!isShelfLongPress) {
                        self.triggerHapticFeedback(10);
                        if (self.el.deckColumn.classList.contains('open')) {
                            self.closeShelf();
                        } else {
                            self.openShelf();
                        }
                    }
                    isShelfLongPress = false;
                };

                this.el.mobileToggle.addEventListener('mousedown', startShelf);
                this.el.mobileToggle.addEventListener('mouseup', endShelf);
                this.el.mobileToggle.addEventListener('mouseleave', function() {
                    if (shelfTimer) {
                        clearTimeout(shelfTimer);
                        shelfTimer = null;
                    }
                });

                this.el.mobileToggle.addEventListener('touchstart', startShelf, { passive: false });
                this.el.mobileToggle.addEventListener('touchend', endShelf, { passive: false });
                this.el.mobileToggle.addEventListener('touchcancel', function() {
                    if (shelfTimer) {
                        clearTimeout(shelfTimer);
                        shelfTimer = null;
                    }
                }, { passive: true });
            }

            // Aux Button Toggle (Elongates console body to show empty plain space)
            if (this.el.auxBtn) {
                this.el.auxBtn.addEventListener('click', function() {
                    self.triggerHapticFeedback();
                    var isActive = self.el.consoleBody.classList.toggle('aux-active');
                    self.el.auxBtn.classList.toggle('pressed', isActive);
                    if (isActive) {
                        self.logTerminal("[SYSTEM] Auxiliary module deployed.", "status");
                    } else {
                        self.logTerminal("[SYSTEM] Auxiliary module retracted.", "status");
                    }
                });
            }

            // Fullscreen Button Toggler
            if (this.el.fullscreenBtn) {
                this.el.fullscreenBtn.addEventListener('click', function() {
                    self.triggerHapticFeedback();
                    self.toggleFullscreen();
                });
                this.bindFullscreenEvents();
            }

            // Magnifier Booster Drag-and-Snap Controller
            if (this.el.magnifierTab) {
                var self = this;
                var acc = this.el.magnifierAcc;
                var tab = this.el.magnifierTab;
                var isDragging = false;
                var startY = 0;
                var currentY = -276; // Starts retracted
                var isDeployed = false;
                var dragThreshold = 5;
                var startPointerY = 0;
                var startPointerX = 0;

                var isRotated = function() {
                    // Detect if the counter-rotation landscape hack is active in fullscreen
                    return document.body.classList.contains('native-fullscreen') && 
                           document.body.classList.contains('is-mobile') && 
                           (window.innerHeight < window.innerWidth);
                };

                var updatePosition = function(y) {
                    currentY = Math.max(-276, Math.min(58, y));
                    acc.classList.add('dragging');
                    acc.style.transform = 'translateY(' + currentY + 'px) scale(1)';
                };

                var snapPosition = function(deploy) {
                    isDeployed = deploy;
                    acc.classList.remove('dragging');
                    self.el.consoleBody.classList.remove('magnifier-dragging'); // Clean up dragging class
                    currentY = isDeployed ? 58 : -276;
                    
                    if (isDeployed) {
                        acc.style.transform = 'translateY(58px) scale(1.15)';
                        self.el.consoleBody.classList.add('magnifier-active');
                        self.logTerminal("[ACCESSORY] Magnifier attachment deployed.", "status");
                    } else {
                        acc.style.transform = 'translateY(-276px) scale(1)';
                        self.el.consoleBody.classList.remove('magnifier-active');
                        self.logTerminal("[ACCESSORY] Magnifier attachment folded back.", "status");
                    }
                    self.triggerHapticFeedback(25);
                };

                var onStart = function(e) {
                    var pointer = e.touches ? e.touches[0] : e;
                    isDragging = true;
                    startPointerY = pointer.clientY;
                    startPointerX = pointer.clientX;
                    startY = currentY;
                    
                    // Instantly drop to scale 1.0 on touch/tap to prevent layout desync
                    acc.classList.add('dragging');
                    self.el.consoleBody.classList.add('magnifier-dragging');
                    acc.style.transform = 'translateY(' + currentY + 'px) scale(1)';
                    
                    e.preventDefault();
                };

                var onMove = function(e) {
                    if (!isDragging) return;
                    var pointer = e.touches ? e.touches[0] : e;
                    
                    var delta = 0;
                    if (isRotated()) {
                        // In rotated landscape, physical horizontal dragging maps to console vertical dragging
                        delta = pointer.clientX - startPointerX;
                    } else {
                        delta = pointer.clientY - startPointerY;
                    }
                    
                    updatePosition(startY + delta);
                    e.preventDefault();
                };

                var onEnd = function(e) {
                    if (!isDragging) return;
                    isDragging = false;
                    
                    var totalDragDist = Math.abs(currentY - startY);
                    if (totalDragDist < dragThreshold) {
                        // Satisfying quick tap toggle
                        snapPosition(!isDeployed);
                    } else {
                        // Drag snap evaluation threshold
                        if (currentY > -109) {
                            snapPosition(true);
                        } else {
                            snapPosition(false);
                        }
                    }
                };

                tab.addEventListener('mousedown', onStart);
                window.addEventListener('mousemove', onMove);
                window.addEventListener('mouseup', onEnd);

                tab.addEventListener('touchstart', onStart, { passive: false });
                window.addEventListener('touchmove', onMove, { passive: false });
                window.addEventListener('touchend', onEnd);
                window.addEventListener('touchcancel', onEnd);
            }

            // Built-in Incandescent Screen Light Switch Toggler
            if (this.el.magnifierLightBtn) {
                var self = this;
                this.el.magnifierLightBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    self.triggerHapticFeedback(20);
                    var active = self.el.magnifierLightBtn.classList.toggle('on');
                    if (active) {
                        document.body.classList.add('light-on');
                        self.logTerminal("[ACCESSORY] Incandescent screen light turned on.", "status");
                    } else {
                        document.body.classList.remove('light-on');
                        self.logTerminal("[ACCESSORY] Incandescent screen light turned off.", "status");
                    }
                });
            }

            // Magnifier zoom controls
            if (this.el.btnZoomUp) {
                this.el.btnZoomUp.addEventListener('click', function(e) {
                    e.stopPropagation();
                    self.triggerHapticFeedback(15);
                    if (self.magnifierScale < 2.00) {
                        self.magnifierScale = parseFloat((self.magnifierScale + 0.05).toFixed(2));
                        self.updateMagnifierScale();
                    }
                });
            }

            if (this.el.btnZoomDown) {
                this.el.btnZoomDown.addEventListener('click', function(e) {
                    e.stopPropagation();
                    self.triggerHapticFeedback(15);
                    if (self.magnifierScale > 1.00) {
                        self.magnifierScale = parseFloat((self.magnifierScale - 0.05).toFixed(2));
                        self.updateMagnifierScale();
                    }
                });
            }

            // Quick Save Trigger (Confirmation on click, direct on long-press)
            if (this.el.btnQuickSave) {
                let savePressTimer = null;
                let isLongPress = false;

                const startSave = function(e) {
                    e.preventDefault();
                    isLongPress = false;
                    savePressTimer = setTimeout(function() {
                        isLongPress = true;
                        self.triggerHapticFeedback(50);
                        if (window.Emulator && self.activeRom) {
                            window.Emulator.saveState(99);
                        } else {
                            self.Toast.show("No active cartridge loaded", "error");
                        }
                    }, 500);
                };

                const endSave = function(e) {
                    e.preventDefault();
                    if (savePressTimer) {
                        clearTimeout(savePressTimer);
                        savePressTimer = null;
                    }
                    if (!isLongPress) {
                        // Short Tap -> Prompt Confirm
                        if (window.Emulator && self.activeRom) {
                            self.Dialog.confirm(
                                "Quick Save State",
                                "Do you want to write your current game state into the Quick Save slot?",
                                function() {
                                    window.Emulator.saveState(99);
                                }
                            );
                        } else {
                            self.Toast.show("No active cartridge loaded", "error");
                        }
                    }
                    isLongPress = false;
                };

                this.el.btnQuickSave.addEventListener('mousedown', startSave);
                this.el.btnQuickSave.addEventListener('mouseup', endSave);
                this.el.btnQuickSave.addEventListener('mouseleave', function() {
                    if (savePressTimer) {
                        clearTimeout(savePressTimer);
                        savePressTimer = null;
                    }
                });

                this.el.btnQuickSave.addEventListener('touchstart', startSave, { passive: false });
                this.el.btnQuickSave.addEventListener('touchend', endSave, { passive: false });
                this.el.btnQuickSave.addEventListener('touchcancel', function() {
                    if (savePressTimer) {
                        clearTimeout(savePressTimer);
                        savePressTimer = null;
                    }
                }, { passive: true });
            }

            // Quick Load Trigger (Confirmation on click, direct on long-press)
            if (this.el.btnQuickLoad) {
                let loadPressTimer = null;
                let isLongPress = false;

                const startLoad = function(e) {
                    e.preventDefault();
                    isLongPress = false;
                    loadPressTimer = setTimeout(function() {
                        isLongPress = true;
                        self.triggerHapticFeedback(50);
                        if (window.Emulator && self.activeRom) {
                            window.Emulator.loadState(99);
                        } else {
                            self.Toast.show("No active cartridge loaded", "error");
                        }
                    }, 500);
                };

                const endLoad = function(e) {
                    e.preventDefault();
                    if (loadPressTimer) {
                        clearTimeout(loadPressTimer);
                        loadPressTimer = null;
                    }
                    if (!isLongPress) {
                        // Short Tap -> Prompt Confirm
                        if (window.Emulator && self.activeRom) {
                            self.Dialog.confirm(
                                "Quick Load State",
                                "Do you want to restore your game state from the Quick Save slot?",
                                function() {
                                    window.Emulator.loadState(99);
                                }
                            );
                        } else {
                            self.Toast.show("No active cartridge loaded", "error");
                        }
                    }
                    isLongPress = false;
                };

                this.el.btnQuickLoad.addEventListener('mousedown', startLoad);
                this.el.btnQuickLoad.addEventListener('mouseup', endLoad);
                this.el.btnQuickLoad.addEventListener('mouseleave', function() {
                    if (loadPressTimer) {
                        clearTimeout(loadPressTimer);
                        loadPressTimer = null;
                    }
                });

                this.el.btnQuickLoad.addEventListener('touchstart', startLoad, { passive: false });
                this.el.btnQuickLoad.addEventListener('touchend', endLoad, { passive: false });
                this.el.btnQuickLoad.addEventListener('touchcancel', function() {
                    if (loadPressTimer) {
                        clearTimeout(loadPressTimer);
                        loadPressTimer = null;
                    }
                }, { passive: true });
            }

            // Rapid Fire Slider Switch Toggle
            if (this.el.toggleRapidFire) {
                this.el.toggleRapidFire.addEventListener('click', function() {
                    self.triggerHapticFeedback();
                    var active = self.el.toggleRapidFire.classList.toggle('on');
                    if (window.Emulator) {
                        window.Emulator.setRapidFire(active);
                    }
                    self.logTerminal("[SYSTEM] Rapid Turbo Fire " + (active ? "Enabled" : "Disabled") + ".", "status");
                });
            }

            // Speed Fast-Forward Up Click
            if (this.el.btnSpeedUp) {
                this.el.btnSpeedUp.addEventListener('click', function() {
                    if (window.Emulator && self.activeRom) {
                        var options = window.Emulator.speedOptions;
                        var idx = window.Emulator.speedIndex;
                        if (idx < options.length - 1) {
                            idx++;
                            window.Emulator.speedIndex = idx;
                            var multiplier = options[idx];
                            
                            if (window.Emulator.isSpeedBypassed) {
                                // Adjust background speed multiplier while in bypass mode
                                window.Emulator.savedSpeedMultiplier = multiplier;
                                
                                // Temporarily show newly adjusted target speed value for 1 second
                                self.el.speedIndicator.textContent = multiplier.toFixed(1) + 'x';
                                self.el.speedIndicator.classList.remove('bypassed');
                                
                                if (window.Emulator.bypassRestoreTimer) {
                                    clearTimeout(window.Emulator.bypassRestoreTimer);
                                }
                                window.Emulator.bypassRestoreTimer = setTimeout(() => {
                                    if (window.Emulator && window.Emulator.isSpeedBypassed && self.el.speedIndicator) {
                                        self.el.speedIndicator.textContent = '1.0x*';
                                        self.el.speedIndicator.classList.add('bypassed');
                                    }
                                }, 1000);
                            } else {
                                // Normal adjustment mode
                                window.Emulator.setSpeedMultiplier(multiplier);
                                self.el.speedIndicator.textContent = multiplier.toFixed(1) + 'x';
                            }
                            self.triggerHapticFeedback();
                        }
                    }
                });
            }

            // Speed Fast-Forward Down Click
            if (this.el.btnSpeedDown) {
                this.el.btnSpeedDown.addEventListener('click', function() {
                    if (window.Emulator && self.activeRom) {
                        var options = window.Emulator.speedOptions;
                        var idx = window.Emulator.speedIndex;
                        if (idx > 0) {
                            idx--;
                            window.Emulator.speedIndex = idx;
                            var multiplier = options[idx];
                            
                            if (window.Emulator.isSpeedBypassed) {
                                // Adjust background speed multiplier while in bypass mode
                                window.Emulator.savedSpeedMultiplier = multiplier;
                                
                                // Temporarily show newly adjusted target speed value for 1 second
                                self.el.speedIndicator.textContent = multiplier.toFixed(1) + 'x';
                                self.el.speedIndicator.classList.remove('bypassed');
                                
                                if (window.Emulator.bypassRestoreTimer) {
                                    clearTimeout(window.Emulator.bypassRestoreTimer);
                                }
                                window.Emulator.bypassRestoreTimer = setTimeout(() => {
                                    if (window.Emulator && window.Emulator.isSpeedBypassed && self.el.speedIndicator) {
                                        self.el.speedIndicator.textContent = '1.0x*';
                                        self.el.speedIndicator.classList.add('bypassed');
                                    }
                                }, 1000);
                            } else {
                                // Normal adjustment mode
                                window.Emulator.setSpeedMultiplier(multiplier);
                                self.el.speedIndicator.textContent = multiplier.toFixed(1) + 'x';
                            }
                            self.triggerHapticFeedback();
                        }
                    }
                });
            }

            // Long Press/Long Tap on Speed Indicator to toggle normal speed bypass
            if (this.el.speedIndicator) {
                let pressTimer = null;
                const startPress = function(e) {
                    e.preventDefault();
                    pressTimer = setTimeout(function() {
                        self.triggerHapticFeedback(50);
                        if (window.Emulator && self.activeRom) {
                            window.Emulator.toggleSpeedBypass();
                        }
                    }, 500);
                };
                const endPress = function(e) {
                    if (pressTimer) {
                        clearTimeout(pressTimer);
                        pressTimer = null;
                    }
                };
                this.el.speedIndicator.addEventListener('mousedown', startPress);
                this.el.speedIndicator.addEventListener('mouseup', endPress);
                this.el.speedIndicator.addEventListener('mouseleave', endPress);
                this.el.speedIndicator.addEventListener('touchstart', startPress, { passive: false });
                this.el.speedIndicator.addEventListener('touchend', endPress, { passive: false });
                this.el.speedIndicator.addEventListener('touchcancel', endPress, { passive: false });
            }

            // Close deck drawer button click
            if (this.el.btnCloseDeck) {
                this.el.btnCloseDeck.addEventListener('click', function() {
                    self.closeShelf();
                });
            }

            // Aesthetic themes selector triggers
            this.el.themeTrigger.addEventListener('click', function(e) {
                e.stopPropagation();
                self.el.themeOptions.classList.toggle('open');
            });

            document.addEventListener('click', function() {
                self.el.themeOptions.classList.remove('open');
            });

            var options = this.el.themeOptions.querySelectorAll('.custom-option');
            options.forEach(function(opt) {
                opt.addEventListener('click', function() {
                    var selectedTheme = opt.getAttribute('data-value');
                    
                    options.forEach(function(o) { o.classList.remove('selected'); });
                    opt.classList.add('selected');
                    self.el.themeTrigger.textContent = opt.textContent;

                    // Apply visual theme to body wrapper
                    document.body.className = '';
                    document.body.classList.add('theme-' + selectedTheme);

                    self.saveSettings();
                    self.Toast.show("Aesthetic presetted to: " + opt.textContent, "success");
                });
            });

            // Volume sliders
            this.el.volumeSlider.addEventListener('input', function() {
                self.volume = parseFloat(self.el.volumeSlider.value);
                
                // Automatically deactivate mute if volume is manually adjusted
                self.isMuted = false;
                var speakerSlits = document.querySelector('.speaker-slits');
                if (speakerSlits) {
                    speakerSlits.classList.remove('muted');
                }

                self.saveSettings();
                self.syncEmulatorSettings();
                if (window.Emulator) {
                    window.Emulator.setVolume(self.volume);
                }
            });

            // LCD Matrix Overlay switch
            this.el.toggleGrid.addEventListener('click', function() {
                var active = self.el.toggleGrid.classList.toggle('on');
                if (active) {
                    self.el.screenOverlay.classList.add('active');
                } else {
                    self.el.screenOverlay.classList.remove('active');
                }
                self.saveSettings();
            });

            // Haptics switch
            this.el.toggleHaptic.addEventListener('click', function() {
                self.hapticsEnabled = self.el.toggleHaptic.classList.toggle('on');
                self.saveSettings();
                if (self.hapticsEnabled) {
                    self.triggerHapticFeedback(50);
                }
            });

            // Speaker Grille Long Press to Mute
            var speakerSlits = document.querySelector('.speaker-slits');
            if (speakerSlits) {
                let muteTimer = null;
                let isLongPress = false;

                const startMutePress = function(e) {
                    e.preventDefault();
                    isLongPress = false;
                    muteTimer = setTimeout(function() {
                        isLongPress = true;
                        self.triggerHapticFeedback(50);
                        self.toggleMute();
                    }, 500); // 500ms hold threshold
                };

                const endMutePress = function(e) {
                    e.preventDefault();
                    if (muteTimer) {
                        clearTimeout(muteTimer);
                        muteTimer = null;
                    }
                    if (!isLongPress) {
                        self.triggerHapticFeedback(10);
                    }
                    isLongPress = false;
                };

                speakerSlits.addEventListener('mousedown', startMutePress);
                speakerSlits.addEventListener('mouseup', endMutePress);
                speakerSlits.addEventListener('mouseleave', endMutePress);

                speakerSlits.addEventListener('touchstart', startMutePress, { passive: false });
                speakerSlits.addEventListener('touchend', endMutePress, { passive: false });
                speakerSlits.addEventListener('touchcancel', endMutePress, { passive: false });
            }
        },

        bindStateSlots: function() {
            var self = this;
            const slotBtns = document.querySelectorAll('.slot-btn');
            slotBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const slotNum = parseInt(btn.getAttribute('data-slot'));
                    const isOccupied = btn.classList.contains('active');
                    
                    // Trigger dynamic bento state sheet modal
                    self.Dialog.showSlotMenu(slotNum, isOccupied);
                });
            });
        },

        getVirtualButtonDOM: function(btnName) {
            switch(btnName) {
                case 'UP': return document.getElementById('ctrl-up');
                case 'DOWN': return document.getElementById('ctrl-down');
                case 'LEFT': return document.getElementById('ctrl-left');
                case 'RIGHT': return document.getElementById('ctrl-right');
                case 'A': return document.getElementById('ctrl-a');
                case 'B': return document.getElementById('ctrl-b');
                case 'SELECT': return document.getElementById('ctrl-select');
                case 'START': return document.getElementById('ctrl-start');
            }
            return null;
        },

        triggerHapticFeedback: function(duration) {
            if (this.hapticsEnabled && window.navigator && window.navigator.vibrate) {
                window.navigator.vibrate(duration || 12);
            }
        },

        toggleMute: function() {
            this.isMuted = !this.isMuted;
            
            var speakerSlits = document.querySelector('.speaker-slits');
            if (speakerSlits) {
                speakerSlits.classList.toggle('muted', this.isMuted);
            }

            if (this.isMuted) {
                if (window.Emulator) {
                    window.Emulator.setVolume(0);
                }
                this.Toast.show("Console Muted", "info");
                this.logTerminal("[SYSTEM] Audio output muted via speaker grille long-press.", "warning");
            } else {
                if (window.Emulator) {
                    window.Emulator.setVolume(this.volume);
                }
                this.Toast.show("Console Unmuted", "success");
                this.logTerminal("[SYSTEM] Audio output restored to " + (this.volume * 100).toFixed(0) + "%.", "success");
            }
        },

        updateMagnifierScale: function() {
            this.el.zoomValueLabel.textContent = this.magnifierScale.toFixed(2) + 'x';
            document.documentElement.style.setProperty('--magnifier-scale', this.magnifierScale);
            this.saveSettings();
        },

        saveSettings: function() {
            if (!this.user) return; // Don't save settings for guests

            var activeThemeOpt = this.el.themeOptions.querySelector('.custom-option.selected');
            var payload = {
                theme: activeThemeOpt ? activeThemeOpt.getAttribute('data-value') : 'classic-dmg',
                lcd_grid: this.el.toggleGrid.classList.contains('on'),
                sound_volume: this.volume,
                haptics_enabled: this.hapticsEnabled,
                magnifier_scale: this.magnifierScale,
                keyboard_bindings: {
                    "ArrowUp": "UP",
                    "ArrowDown": "DOWN",
                    "ArrowLeft": "LEFT",
                    "ArrowRight": "RIGHT",
                    "KeyZ": "A",
                    "KeyX": "B",
                    "Enter": "START",
                    "ShiftLeft": "SELECT"
                },
                // Retain current system variables design tokens
                design_tokens: {
                    "--bg-color": getComputedStyle(document.documentElement).getPropertyValue('--bg-color').trim(),
                    "--card-bg": getComputedStyle(document.documentElement).getPropertyValue('--card-bg').trim(),
                    "--text-primary": getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim(),
                    "--text-secondary": getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim(),
                    "--primary-accent": getComputedStyle(document.documentElement).getPropertyValue('--primary-accent').trim(),
                    "--font-main": getComputedStyle(document.documentElement).getPropertyValue('--font-main').trim(),
                    "--radius-container": getComputedStyle(document.documentElement).getPropertyValue('--radius-container').trim()
                }
            };

            // Post configurations server-side
            fetch('index.php?action=save_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).catch(function(err) {
                console.error("Failed to sync settings:", err);
            });
        },

        // ROM file manipulation & library syncs
        bindROMManager: function() {
            var self = this;

            // Trigger browse explorer on upload card click, ignoring bubbled input clicks
            this.el.dropZone.addEventListener('click', function(e) {
                if (e.target === self.el.fileInput) return;
                self.el.fileInput.click();
            });

            // Stop click propagation to prevent infinite recursive loop blocks in the browser
            this.el.fileInput.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            this.el.fileInput.addEventListener('change', function() {
                if (self.el.fileInput.files.length > 0) {
                    self.uploadROMFile(self.el.fileInput.files[0]);
                }
            });

            // Standard dragover/drop handlers
            this.el.dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                self.el.dropZone.classList.add('dragover');
            });

            this.el.dropZone.addEventListener('dragleave', function() {
                self.el.dropZone.classList.remove('dragover');
            });

            this.el.dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                self.el.dropZone.classList.remove('dragover');
                if (e.dataTransfer.files.length > 0) {
                    self.uploadROMFile(e.dataTransfer.files[0]);
                }
            });

            this.el.btnRefresh.addEventListener('click', function() {
                self.loadROMCatalog();
            });

            this.el.btnEject.addEventListener('click', function() {
                self.ejectROM();
            });

            this.el.btnPauseResume.addEventListener('click', function() {
                if (window.Emulator && window.Emulator.gbInstance) {
                    var isPaused = window.Emulator.togglePause();
                    self.el.btnPauseResume.innerHTML = isPaused ? '▶️ Resume' : '⏸️ Pause';
                    self.el.powerLed.className = isPaused ? 'power-led led-paused' : 'power-led led-online';
                }
            });

            this.el.btnReset.addEventListener('click', function() {
                if (window.Emulator && self.activeRom) {
                    self.Dialog.confirm(
                        "Hard Reset",
                        "Are you sure you want to hard reset the console? Any unsaved progress will be lost.",
                        function() {
                            self.el.btnPauseResume.innerHTML = '⏸️ Pause';
                            self.insertCartridge(self.activeRom);
                        }
                    );
                }
            });
        },

        loadROMCatalog: function() {
            var self = this;
            fetch('index.php?action=list')
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        self.roms = data.roms;
                        self.renderCartridgeGrid();
                    } else {
                        self.Toast.show("Catalog query error: " + data.error, "error");
                    }
                })
                .catch(function(err) {
                    console.error("ROM fetch error:", err);
                });
        },

        uploadROMFile: function(file) {
            var self = this;
            this.el.fileInput.value = ''; // Reset input value cache to allow consecutive uploads of the same file
            var ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'gb' && ext !== 'gbc' && ext !== 'gba') {
                this.Toast.show("Unsupported format. Please upload GB, GBC, or GBA files.", "error");
                return;
            }

            var formData = new FormData();
            formData.append('rom', file);

            this.Toast.show("Cataloging Cartridge: " + file.name, "info");

            fetch('index.php?action=upload', {
                method: 'POST',
                body: formData
            })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        self.Toast.show("Cartridge stored: " + data.rom.display_name, "success");
                        self.loadROMCatalog();
                    } else {
                        self.Toast.show("Upload failure: " + data.error, "error");
                    }
                })
                .catch(function(err) {
                    self.Toast.show("System transmission failure", "error");
                });
        },

        renderCartridgeGrid: function() {
            var self = this;
            this.el.deck.innerHTML = '';

            if (this.user) {
                if (this.user.role === 'admin') {
                    this.el.profileQuota.textContent = 'Infinite Cartridges (Admin)';
                } else {
                    this.el.profileQuota.textContent = this.roms.length + ' / 10 Cartridges';
                }
            }

            var myRoms = [];
            var publicRoms = [];
            
            this.roms.forEach(function(rom) {
                if (rom.is_public == 1) {
                    publicRoms.push(rom);
                } else {
                    myRoms.push(rom);
                }
            });

            if (myRoms.length === 0 && publicRoms.length === 0) {
                this.el.deck.innerHTML = `
                    <div class="library-empty">
                        <p>No cartridges on shelf.</p>
                        <p class="sub">Upload files to populate the collection.</p>
                    </div>`;
                return;
            }

            const renderCard = function(rom) {
                var ext = rom.filename.split('.').pop();
                var card = document.createElement('div');
                card.className = 'cartridge-card';
                if (self.activeRom && self.activeRom.rom_hash === rom.rom_hash) {
                    card.classList.add('inserted');
                }

                var showOptions = false;
                if (self.user) {
                    if (self.user.role === 'admin') showOptions = true;
                    else if (rom.is_public == 0) showOptions = true;
                }

                var displayName = rom.custom_name || rom.system_name || rom.display_name;

                var optionsHtml = showOptions ? `
                    <div class="cartridge-actions">
                        <button class="cartridge-options-btn" data-hash="${rom.rom_hash}">⋮</button>
                    </div>` : '';

                var stickerClass = rom.has_cover == 1 ? 'cartridge-sticker has-cover' : 'cartridge-sticker';
                var stickerStyle = rom.has_cover == 1 ? `style="background-image: url('data/covers/${rom.rom_hash}.jpg?v=${Date.now()}');"` : '';

                card.innerHTML = optionsHtml + `
                    <div class="${stickerClass}" ${stickerStyle}>
                        <div class="sticker-title">${displayName}</div>
                        <div class="sticker-footer">
                            <span class="cartridge-logo">GB</span>
                            <div style="display: flex; gap: 4px;">
                                ${self.user && self.user.role === 'admin' && rom.linked_to_admin == 1 ? '<span class="rom-type-badge type-admin">ADMIN</span>' : ''}
                                <span class="rom-type-badge type-${ext}">${ext.toUpperCase()}</span>
                            </div>
                        </div>
                    </div>
                `;

                let pressTimer = null;
                let isLongPress = false;
                let startX = 0, startY = 0;

                const startPress = function(e) {
                    if (e.target.closest('.cartridge-options-btn')) return;
                    if (card.classList.contains('inserted')) return;
                    if (!showOptions) return;

                    const point = e.touches ? e.touches[0] : e;
                    startX = point.clientX;
                    startY = point.clientY;
                    isLongPress = false;

                    pressTimer = setTimeout(function() {
                        isLongPress = true;
                        self.triggerHapticFeedback(50);
                        self.showCartridgeMenu(rom);
                    }, 600);
                };

                const movePress = function(e) {
                    if (!pressTimer) return;
                    const point = e.touches ? e.touches[0] : e;
                    if (Math.abs(point.clientX - startX) > 10 || Math.abs(point.clientY - startY) > 10) {
                        clearTimeout(pressTimer);
                        pressTimer = null;
                    }
                };

                const endPress = function(e) {
                    if (pressTimer) {
                        clearTimeout(pressTimer);
                        pressTimer = null;
                    }
                };

                card.addEventListener('mousedown', startPress);
                card.addEventListener('mousemove', movePress);
                card.addEventListener('mouseup', endPress);
                card.addEventListener('mouseleave', endPress);

                card.addEventListener('touchstart', startPress, { passive: true });
                card.addEventListener('touchmove', movePress, { passive: true });
                card.addEventListener('touchend', endPress);
                card.addEventListener('touchcancel', endPress);

                card.addEventListener('click', function(e) {
                    if (e.target.closest('.cartridge-options-btn')) return;
                    if (card.classList.contains('inserted')) return;
                    if (isLongPress) {
                        isLongPress = false;
                        return;
                    }
                    self.insertCartridge(rom);
                });

                if (showOptions) {
                    var optBtn = card.querySelector('.cartridge-options-btn');
                    optBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        self.showCartridgeMenu(rom);
                    });
                }

                self.el.deck.appendChild(card);
            };

            myRoms.forEach(renderCard);

            if (publicRoms.length > 0) {
                var divider = document.createElement('div');
                divider.className = 'public-roms-header';
                divider.innerHTML = '<h4>🌐 Public Cartridges</h4>';
                self.el.deck.appendChild(divider);
                
                publicRoms.forEach(renderCard);
            }
        },

        showAdminRevertScopeMenu: function(hash) {
            var self = this;
            this.el.dialogTitle.textContent = "Revert Scope";
            this.el.dialogMsg.textContent = "Do you want to revert this name system-wide (removes global custom name) or just for your personal shelf?";
            this.el.dialogInputContainer.style.display = 'none';
            
            const actionsBox = this.el.dialog.querySelector('.dialog-actions');
            actionsBox.innerHTML = '';
            actionsBox.classList.add('vertical');

            const btnGlobal = document.createElement('button');
            btnGlobal.className = 'btn btn-primary';
            btnGlobal.textContent = '🌐 System-Wide';
            btnGlobal.onclick = function() { self.Dialog.close(); self.revertROMName(hash, 1); };
            
            const btnPersonal = document.createElement('button');
            btnPersonal.className = 'btn btn-secondary';
            btnPersonal.textContent = '👤 Just For Me';
            btnPersonal.onclick = function() { self.Dialog.close(); self.revertROMName(hash, 0); };

            const btnCancel = document.createElement('button');
            btnCancel.className = 'btn btn-secondary';
            btnCancel.textContent = 'Cancel';
            btnCancel.onclick = function() { self.Dialog.close(); };

            actionsBox.appendChild(btnGlobal);
            actionsBox.appendChild(btnPersonal);
            actionsBox.appendChild(btnCancel);
            
            this.el.dialog.classList.add('active');
        },

        showAdminRenameScopeMenu: function(hash, newName) {
            var self = this;
            this.el.dialogTitle.textContent = "Rename Scope";
            this.el.dialogMsg.textContent = "Do you want to update this name system-wide for all users, or just for your personal shelf?";
            this.el.dialogInputContainer.style.display = 'none';
            
            const actionsBox = this.el.dialog.querySelector('.dialog-actions');
            actionsBox.innerHTML = '';
            actionsBox.classList.add('vertical');

            const btnGlobal = document.createElement('button');
            btnGlobal.className = 'btn btn-primary';
            btnGlobal.textContent = '🌐 System-Wide';
            btnGlobal.onclick = function() { self.Dialog.close(); self.renameROM(hash, newName, 1); };
            
            const btnPersonal = document.createElement('button');
            btnPersonal.className = 'btn btn-secondary';
            btnPersonal.textContent = '👤 Just For Me';
            btnPersonal.onclick = function() { self.Dialog.close(); self.renameROM(hash, newName, 0); };

            const btnCancel = document.createElement('button');
            btnCancel.className = 'btn btn-secondary';
            btnCancel.textContent = 'Cancel';
            btnCancel.onclick = function() { self.Dialog.close(); };

            actionsBox.appendChild(btnGlobal);
            actionsBox.appendChild(btnPersonal);
            actionsBox.appendChild(btnCancel);
            
            this.el.dialog.classList.add('active');
        },

        showCartridgeMenu: function(rom) {
            var self = this;
            var displayName = rom.custom_name || rom.system_name || rom.display_name;
            this.el.dialogTitle.textContent = "Cartridge Options";
            this.el.dialogMsg.textContent = displayName;
            this.el.dialogInputContainer.style.display = 'none';
            
            const actionsBox = this.el.dialog.querySelector('.dialog-actions');
            actionsBox.innerHTML = ''; 
            actionsBox.classList.add('vertical');

            const btnCover = document.createElement('button');
            btnCover.className = 'btn btn-secondary';
            btnCover.textContent = '🖼️ Cover Art';
            btnCover.onclick = function() {
                self.Dialog.close();
                setTimeout(function() {
                    self.showCoverStudio(rom);
                }, 300);
            };
            actionsBox.appendChild(btnCover);

            const btnRename = document.createElement('button');
            btnRename.className = 'btn btn-secondary';
            btnRename.textContent = '✏️ Rename';
            btnRename.onclick = function() {
                self.Dialog.close();
                var hasCustom = rom.custom_name || (self.user && self.user.role === 'admin' && rom.system_name);
                setTimeout(function() {
                    self.Dialog.prompt(
                        "Rename Cartridge", 
                        "Enter a new name for this cartridge:", 
                        displayName, 
                        function(newName) {
                            if (newName && newName !== displayName) {
                                if (self.user && self.user.role === 'admin') {
                                    setTimeout(function() {
                                        self.showAdminRenameScopeMenu(rom.rom_hash, newName);
                                    }, 300);
                                } else {
                                    self.renameROM(rom.rom_hash, newName, 0);
                                }
                            }
                        },
                        hasCustom ? function() {
                            self.Dialog.close();
                            setTimeout(function() {
                                if (self.user && self.user.role === 'admin') {
                                    self.showAdminRevertScopeMenu(rom.rom_hash);
                                } else {
                                    self.Dialog.confirm(
                                        "Revert Name", 
                                        "Are you sure you want to revert this cartridge name back to the default?", 
                                        function() {
                                            self.revertROMName(rom.rom_hash, 0);
                                        }
                                    );
                                }
                            }, 300);
                        } : null
                    );
                }, 300);
            };
            actionsBox.appendChild(btnRename);

            if (this.user && this.user.role === 'admin') {
                const btnPublic = document.createElement('button');
                btnPublic.className = 'btn btn-secondary';
                if (rom.is_public == 1) {
                    btnPublic.textContent = '🔒 Revoke Public';
                    btnPublic.onclick = function() {
                        self.Dialog.close();
                        self.togglePublicROM(rom.rom_hash, 0);
                    };
                } else {
                    btnPublic.textContent = '🌐 Make Public';
                    btnPublic.onclick = function() {
                        self.Dialog.close();
                        self.togglePublicROM(rom.rom_hash, 1);
                    };
                }
                actionsBox.appendChild(btnPublic);

                if (rom.linked_to_admin == 0) {
                    const btnClaim = document.createElement('button');
                    btnClaim.className = 'btn btn-primary';
                    btnClaim.textContent = '👑 Claim Cartridge';
                    btnClaim.onclick = function() {
                        self.Dialog.close();
                        self.claimROM(rom.rom_hash);
                    };
                    actionsBox.appendChild(btnClaim);
                }

                const btnDelete = document.createElement('button');
                btnDelete.className = 'btn btn-danger';
                btnDelete.textContent = '🗑️ Destroy';
                btnDelete.onclick = function() {
                    self.Dialog.close();
                    self.Dialog.confirm(
                        "Destroy Cartridge Globally",
                        "Are you sure this file will be physically deleted and all users linked to it will be unlinked?",
                        function() {
                            self.deleteROM(rom.rom_hash);
                        }
                    );
                };
                actionsBox.appendChild(btnDelete);
            } else if (this.user) {
                const btnRemove = document.createElement('button');
                btnRemove.className = 'btn btn-danger';
                btnRemove.textContent = '🗑️ Remove';
                btnRemove.onclick = function() {
                    self.Dialog.close();
                    self.Dialog.confirm(
                        "Remove Cartridge",
                        "Are you sure you want to remove this cartridge from your shelf?",
                        function() {
                            self.deleteROM(rom.rom_hash);
                        }
                    );
                };
                actionsBox.appendChild(btnRemove);
            }

            const btnCancel = document.createElement('button');
            btnCancel.className = 'btn btn-secondary';
            btnCancel.textContent = 'Cancel';
            btnCancel.onclick = function() {
                self.Dialog.close();
            };
            actionsBox.appendChild(btnCancel);

            this.el.dialog.classList.add('active');
        },

        togglePublicROM: function(hash, state) {
            var self = this;
            var formData = new FormData();
            formData.append('hash', hash);
            formData.append('state', state);

            fetch('index.php?action=toggle_public', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    self.Toast.show(state ? "Cartridge made public" : "Cartridge is now private", "success");
                    self.loadROMCatalog();
                } else {
                    self.Toast.show("Error: " + data.error, "error");
                }
            })
            .catch(err => self.Toast.show("Network error", "error"));
        },

        renameROM: function(hash, newName, isGlobal) {
            var self = this;
            var formData = new FormData();
            formData.append('hash', hash);
            formData.append('new_name', newName);
            formData.append('global', isGlobal ? 1 : 0);

            fetch('index.php?action=rename', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    self.Toast.show("Cartridge renamed", "success");
                    self.loadROMCatalog();
                } else {
                    self.Toast.show("Rename error: " + data.error, "error");
                }
            })
            .catch(err => self.Toast.show("Network error", "error"));
        },

        showCoverStudio: function(rom) {
            var self = this;
            this.el.dialogTitle.textContent = "Cover Art Studio";
            this.el.dialogMsg.textContent = "Search the web for box art or title screens.";
            this.el.dialogInputContainer.style.display = 'none';
            
            const actionsBox = this.el.dialog.querySelector('.dialog-actions');
            actionsBox.innerHTML = ''; 
            actionsBox.classList.remove('vertical');

            // Build custom studio body
            const studioBody = document.createElement('div');
            studioBody.id = 'cover-studio-body';
            
            const searchRow = document.createElement('div');
            searchRow.className = 'cover-studio-search';
            
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'custom-text-input';
            var ext = rom.filename.split('.').pop().toUpperCase();
            searchInput.value = `${rom.display_name} ${ext} box art`;
            
            const searchBtn = document.createElement('button');
            searchBtn.className = 'btn btn-primary';
            searchBtn.textContent = 'Search';
            
            searchRow.appendChild(searchInput);
            searchRow.appendChild(searchBtn);
            studioBody.appendChild(searchRow);
            
            const gridContainer = document.createElement('div');
            gridContainer.className = 'cover-studio-grid';
            studioBody.appendChild(gridContainer);
            
            // Insert studio body before actions
            this.el.dialog.querySelector('.dialog-box').insertBefore(studioBody, actionsBox);

            const performSearch = function() {
                gridContainer.innerHTML = '<p style="grid-column: span 3; text-align: center; color: var(--text-secondary);">Searching...</p>';
                var formData = new FormData();
                formData.append('query', searchInput.value);
                
                fetch('index.php?action=search_covers', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    gridContainer.innerHTML = '';
                    if (data.success && data.images.length > 0) {
                        data.images.forEach(url => {
                            const img = document.createElement('img');
                            img.src = url;
                            img.className = 'cover-thumbnail';
                            img.loading = 'lazy';
                            img.onclick = function() {
                                gridContainer.innerHTML = '<p style="grid-column: span 3; text-align: center; color: var(--primary-accent);">Downloading & Applying Cover...</p>';
                                self.setCoverArt(rom.rom_hash, url);
                            };
                            gridContainer.appendChild(img);
                        });
                    } else {
                        gridContainer.innerHTML = '<p style="grid-column: span 3; text-align: center; color: var(--text-secondary);">No results found.</p>';
                    }
                })
                .catch(err => {
                    gridContainer.innerHTML = '<p style="grid-column: span 3; text-align: center; color: #e63946;">Search failed.</p>';
                });
            };

            searchBtn.onclick = performSearch;
            searchInput.onkeypress = function(e) { if (e.key === 'Enter') performSearch(); };

            if (rom.has_cover == 1) {
                const btnRemove = document.createElement('button');
                btnRemove.className = 'btn btn-danger';
                btnRemove.textContent = 'Remove Cover';
                btnRemove.onclick = function() {
                    self.removeCoverArt(rom.rom_hash);
                };
                actionsBox.appendChild(btnRemove);
            }

            const btnCancel = document.createElement('button');
            btnCancel.className = 'btn btn-secondary';
            btnCancel.textContent = 'Close';
            btnCancel.onclick = function() {
                studioBody.remove();
                self.Dialog.close();
            };
            actionsBox.appendChild(btnCancel);

            this.el.dialog.classList.add('active');
            
            // Auto-trigger search on open
            performSearch();
        },

        setCoverArt: function(hash, imageUrl) {
            var self = this;
            var formData = new FormData();
            formData.append('hash', hash);
            formData.append('image_url', imageUrl);

            fetch('index.php?action=set_cover', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                const studioBody = document.getElementById('cover-studio-body');
                if (studioBody) studioBody.remove();
                self.Dialog.close();
                
                if (data.success) {
                    self.Toast.show("Cover art applied!", "success");
                    self.loadROMCatalog();
                } else {
                    self.Toast.show("Failed to apply cover: " + data.error, "error");
                }
            })
            .catch(err => self.Toast.show("Network error", "error"));
        },

        removeCoverArt: function(hash) {
            var self = this;
            var formData = new FormData();
            formData.append('hash', hash);

            fetch('index.php?action=remove_cover', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                const studioBody = document.getElementById('cover-studio-body');
                if (studioBody) studioBody.remove();
                self.Dialog.close();
                
                if (data.success) {
                    self.Toast.show("Cover art removed", "info");
                    self.loadROMCatalog();
                } else {
                    self.Toast.show("Error removing cover", "error");
                }
            })
            .catch(err => self.Toast.show("Network error", "error"));
        },

        revertROMName: function(hash, isGlobal) {
            var self = this;
            var formData = new FormData();
            formData.append('hash', hash);
            formData.append('global', isGlobal ? 1 : 0);

            fetch('index.php?action=revert', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    self.Toast.show("Cartridge name reverted", "success");
                    self.loadROMCatalog();
                } else {
                    self.Toast.show("Revert error: " + data.error, "error");
                }
            })
            .catch(err => self.Toast.show("Network error", "error"));
        },

        claimROM: function(hash) {
            var self = this;
            var formData = new FormData();
            formData.append('hash', hash);

            fetch('index.php?action=claim', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    self.Toast.show("Cartridge claimed successfully", "success");
                    self.loadROMCatalog();
                } else {
                    self.Toast.show("Claim error: " + data.error, "error");
                }
            })
            .catch(err => self.Toast.show("Network error", "error"));
        },

        deleteROM: function(hash) {
            var self = this;
            var formData = new FormData();
            formData.append('hash', hash);

            fetch('index.php?action=delete', {
                method: 'POST',
                body: formData
            })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        self.Toast.show("Cartridge removed", "success");
                        if (self.activeRom && self.activeRom.rom_hash === hash) {
                            self.ejectROM();
                        }
                        self.loadROMCatalog();
                    } else {
                        self.Toast.show("Purge error: " + data.error, "error");
                    }
                })
                .catch(function(err) {
                    self.Toast.show("Database sync failure", "error");
                });
        },

        // Physical cartridge insertion animations
        insertCartridge: function(rom) {
            this.activeRom = rom;
            localStorage.setItem('cjb_last_rom', rom.rom_hash);
            this.el.standbyScreen.style.display = 'none';
            this.el.loadedCartridge.style.display = 'flex';
            this.el.powerLed.className = 'power-led led-online';
            this.el.utilCard.style.display = 'block';
            
            this.renderCartridgeGrid();
            this.Toast.show("Cartridge Inserted: " + rom.display_name, "success");

            // Route closure through unified state API to pop browser history
            this.closeShelf();

            // Initialize Emulator Engine & Audio Context synchronously to satisfy Apple Safari gesture requirements
            if (window.Emulator) {
                window.Emulator.stop();
            }

            // Route to correct engine core depending on filename extension
            var ext = rom.filename.split('.').pop().toLowerCase();
            if (ext === 'gba') {
                document.body.classList.add('gba-active');
                window.Emulator = new window.ConjureGbaEngine('emulator-screen');
            } else {
                document.body.classList.remove('gba-active');
                window.Emulator = new window.ConjureBoyEngine('emulator-screen');
                window.Emulator.initAudio();
            }

            // Fetch any existing battery SRAM (.sav) before boot (Modular lifecycle compliance)
            this.logTerminal("Fetching SQLite battery SRAM archives...", "status");
            fetch(`index.php?action=load_sram&hash=${rom.rom_hash}`)
                .then(res => res.json())
                .then(data => {
                    const sramData = (data.success && data.sram) ? data.sram : null;
                    if (sramData) {
                        this.logTerminal("SRAM battery save state discovered in database.", "status");
                    } else {
                        this.logTerminal("No legacy SRAM save found. Starting clean save file.", "info");
                    }
                    if (typeof window.Emulator.setLoadedSram === 'function') {
                        window.Emulator.setLoadedSram(sramData);
                    }
                    window.Emulator.loadROM(rom.rom_hash);
                })
                .catch(err => {
                    console.error("SRAM load fetch failed:", err);
                    window.Emulator.loadROM(rom.rom_hash);
                });
        },

        ejectROM: function() {
            this.activeRom = null;
            this.el.standbyScreen.style.display = 'flex';
            this.el.loadedCartridge.style.display = 'none';
            this.el.powerLed.className = 'power-led led-off';
            this.el.utilCard.style.display = 'none';
            if (this.el.btnPauseResume) this.el.btnPauseResume.innerHTML = '⏸️ Pause';
            
            if (this.el.speedIndicator) {
                this.el.speedIndicator.textContent = '1.0x';
            }

            this.renderCartridgeGrid();
            this.Toast.show("Cartridge Ejected", "info");
            
            if (window.Emulator) {
                window.Emulator.stop();
            }
        },

        toggleFullscreen: function() {
            var self = this;
            var doc = window.document;
            var docEl = doc.documentElement;

            var requestFullScreen = docEl.requestFullscreen || docEl.webkitRequestFullscreen || docEl.mozRequestFullScreen || docEl.msRequestFullscreen;
            var cancelFullScreen = doc.exitFullscreen || doc.webkitExitFullscreen || doc.mozCancelFullScreen || doc.msExitFullscreen;
            var isFullscreen = doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || doc.msFullscreenElement;
            var isMobile = /Mobi|Android|iPhone|iPod|iPad/i.test(navigator.userAgent);

            if (!isFullscreen) {
                if (requestFullScreen) {
                    requestFullScreen.call(docEl).then(function() {
                        if (isMobile && window.screen && window.screen.orientation && typeof window.screen.orientation.lock === 'function') {
                            window.screen.orientation.lock('portrait').catch(function(e) {
                                console.warn("Orientation lock rejected, falling back to CSS counter-rotation.", e);
                            });
                        }
                    }).catch(function(err) {
                        self.toggleCSSFullscreen(true);
                    });
                } else {
                    // Fallback directly for iOS Safari
                    self.toggleCSSFullscreen(true);
                }
            } else {
                if (cancelFullScreen) {
                    if (window.screen && window.screen.orientation && typeof window.screen.orientation.unlock === 'function') {
                        window.screen.orientation.unlock();
                    }
                    cancelFullScreen.call(doc);
                } else {
                    self.toggleCSSFullscreen(false);
                }
            }
        },

        toggleCSSFullscreen: function(active) {
            if (active) {
                document.body.classList.add('fullscreen-active');
                if (this.el.fullscreenBtn) this.el.fullscreenBtn.classList.add('pressed');
                this.logTerminal("[SYSTEM] Viewport entered fullscreen mode.", "status");
            } else {
                document.body.classList.remove('fullscreen-active');
                if (this.el.fullscreenBtn) this.el.fullscreenBtn.classList.remove('pressed');
                this.logTerminal("[SYSTEM] Viewport exited fullscreen mode.", "status");
                
                // Force viewport zoom reset on mobile after exiting fullscreen
                setTimeout(function() {
                    window.scrollTo(0, 0);
                    var meta = document.querySelector('meta[name="viewport"]');
                    if (meta) {
                        var originalContent = meta.content;
                        meta.content = 'width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover';
                        setTimeout(function() { meta.content = originalContent; }, 100);
                    }
                }, 50);
            }
        },

        bindFullscreenEvents: function() {
            var self = this;
            var isMobile = /Mobi|Android|iPhone|iPod|iPad/i.test(navigator.userAgent);
            if (isMobile) {
                document.body.classList.add('is-mobile');
            }

            const syncState = function() {
                var doc = window.document;
                var isFullscreen = doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || doc.msFullscreenElement;
                
                if (isFullscreen) {
                    document.body.classList.add('native-fullscreen');
                    self.toggleCSSFullscreen(true);
                } else {
                    document.body.classList.remove('native-fullscreen');
                    self.toggleCSSFullscreen(false);
                }
            };
            document.addEventListener('fullscreenchange', syncState);
            document.addEventListener('webkitfullscreenchange', syncState);
            document.addEventListener('mozfullscreenchange', syncState);
            document.addEventListener('MSFullscreenChange', syncState);
        }
    };

    // Upgraded Engine wrapper placed directly inside app.js for seamless isolation
    window.ConjureBoyEngine = class {
        constructor(canvasId) {
            this.canvas = document.getElementById(canvasId);
            
            // Set GBC 10:9 aspect ratio coordinate buffer bounds
            this.canvas.width = 160;
            this.canvas.height = 144;

            this.ctx = this.canvas.getContext('2d', { alpha: false });
            this.audioCtx = null;
            this.romData = null;
            this.animFrame = null;
            this.isRunning = false;
            this.gbInstance = null;
            this.volume = window.App ? window.App.volume : 0.5;
            this.speedAdjustment = window.App ? window.App.audioSpeed : 1.0;
            this.loadedSramBase64 = null;
            this.autoSaveInterval = null;

            // Auxiliary Module State Elements
this.rapidFireEnabled = false;
this.rapidIntervals = { 'A': null, 'B': null };
this.speedMultiplier = 1.0;
this.speedOptions = [1.0, 1.5, 2.0, 3.0, 4.0, 5.0, 6.0, 8.0, 10.0, 12.0, 15.0, 20.0];
this.speedIndex = 0;
this.isSpeedBypassed = false;
this.savedSpeedMultiplier = 1.0;

// Joypad mappings matching GameBoyCore hardware register indices
// Bits 0-3 are directions, Bits 4-7 are action buttons
this.JOYPAD_MAP = {
    'RIGHT': 0,
    'LEFT': 1,
    'UP': 2,
    'DOWN': 3,
    'A': 4,
    'B': 5,
    'SELECT': 6,
    'START': 7
};}

        async loadROM(hash) {
            try {
                this.activeHash = hash;
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal("Fetching ROM binary array...", "status");
                }

                const res = await fetch(`index.php?action=download&hash=${hash}`);
                if (!res.ok) throw new Error("ROM fetch stream interrupted.");
                
                const buffer = await res.arrayBuffer();
                this.romData = new Uint8Array(buffer);
                
                // Extract original game title from byte header (Offset 0x0134 to 0x0143)
                let title = "";
                for(let i = 0x0134; i <= 0x0143; i++) {
                    if(this.romData[i] === 0) break;
                    title += String.fromCharCode(this.romData[i]);
                }
                this.romTitle = title.trim() || "UNKNOWN GAME";
                
                // If the downloaded window.GameBoyCore is active on the window, launch it!
                if (window.GameBoyCore) {
                    this.startCoreEmulator();
                } else {
                    this.startBootSequence();
                }
            } catch (err) {
                console.error("[CPU] ROM Initialization Failure:", err);
                this.drawError("ROM CRITICAL FAILURE");
            }
        }

        setLoadedSram(base64Data) {
            this.loadedSramBase64 = base64Data;
        }

        togglePause() {
            if (!this.gbInstance) return false;
            
            if (this.isRunning) {
                this.isRunning = false;
                if (this.animFrame) cancelAnimationFrame(this.animFrame);
                if (window.App && window.App.logTerminal) window.App.logTerminal("[SYSTEM] Emulation paused.", "warning");
                if (this.gbInstance.audioHandle) this.gbInstance.audioHandle.changeVolume(0);
                return true;
            } else {
                this.isRunning = true;
                if (this.gbInstance.audioHandle) this.gbInstance.audioHandle.changeVolume(this.volume);
                this.restartLoop();
                if (window.App && window.App.logTerminal) window.App.logTerminal("[SYSTEM] Emulation resumed.", "success");
                return false;
            }
        }

        initAudio() {
            if (!this.audioCtx) {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                this.audioCtx = new AudioContext();
            }
            if (this.audioCtx.state === 'suspended') {
                this.audioCtx.resume();
            }
        }

        setVolume(v) {
            this.volume = v;
            if (window.settings) {
                window.settings[3] = v;
            }
            if (this.gbInstance) {
                if (this.gbInstance.audioHandle && typeof this.gbInstance.audioHandle.changeVolume === 'function') {
                    this.gbInstance.audioHandle.changeVolume(v);
                }
                if (typeof this.gbInstance.changeVolume === 'function') {
                    this.gbInstance.changeVolume();
                }
            }
        }

        playBootChime() {
            if (!this.audioCtx) return;
            const osc = this.audioCtx.createOscillator();
            const gain = this.audioCtx.createGain();
            
            osc.type = 'square';
            osc.connect(gain);
            gain.connect(this.audioCtx.destination);
            
            const now = this.audioCtx.currentTime;
            osc.frequency.setValueAtTime(987.77, now);
            osc.frequency.setValueAtTime(1318.51, now + 0.1);
            
            gain.gain.setValueAtTime(0, now);
            gain.gain.linearRampToValueAtTime(0.08 * this.volume, now + 0.05);
            gain.gain.setValueAtTime(0.08 * this.volume, now + 0.4);
            gain.gain.linearRampToValueAtTime(0, now + 0.6);
            
            osc.start(now);
            osc.stop(now + 0.6);
        }

        setRapidFire(active) {
            this.rapidFireEnabled = active;
            if (!active) {
                if (this.rapidIntervals['A']) { clearInterval(this.rapidIntervals['A']); this.rapidIntervals['A'] = null; }
                if (this.rapidIntervals['B']) { clearInterval(this.rapidIntervals['B']); this.rapidIntervals['B'] = null; }
            }
        }

        setSpeedMultiplier(multiplier) {
            this.speedMultiplier = multiplier;
            if (this.gbInstance && this.gbInstance.audioHandle) {
                this.gbInstance.audioHandle.changeSpeed(this.speedMultiplier);
            }
            if (window.App && window.App.logTerminal) {
                window.App.logTerminal(`[SYSTEM] Game Speed adjusted to ${this.speedMultiplier.toFixed(1)}x`, "status");
            }
        }

        setSpeedBypass(active) {
            if (!this.gbInstance) return;
            if (this.isSpeedBypassed === active) return;
            
            this.isSpeedBypassed = active;
            if (this.isSpeedBypassed) {
                this.savedSpeedMultiplier = this.speedMultiplier;
                this.setSpeedMultiplier(1.0);
                if (window.App && window.App.el.speedIndicator) {
                    window.App.el.speedIndicator.textContent = '1.0x*';
                    window.App.el.speedIndicator.classList.add('bypassed');
                }
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal("[SYSTEM] Speed multiplier temporarily bypassed (Running at 1.0x).", "warning");
                }
            } else {
                // Safely discard the active display timer
                if (this.bypassRestoreTimer) {
                    clearTimeout(this.bypassRestoreTimer);
                    this.bypassRestoreTimer = null;
                }
                const restoreMultiplier = this.savedSpeedMultiplier || 1.0;
                this.setSpeedMultiplier(restoreMultiplier);
                if (window.App && window.App.el.speedIndicator) {
                    window.App.el.speedIndicator.textContent = restoreMultiplier.toFixed(1) + 'x';
                    window.App.el.speedIndicator.classList.remove('bypassed');
                }
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal(`[SYSTEM] Speed multiplier restored to ${restoreMultiplier.toFixed(1)}x.`, "success");
                }
            }
        }

        toggleSpeedBypass() {
            this.setSpeedBypass(!this.isSpeedBypassed);
        }

        startBootSequence() {
            this.isRunning = true;
            let y = -40;
            let titleOpacity = 0;
            let chimePlayed = false;

            const loop = () => {
                if (!this.isRunning) return;

                this.ctx.fillStyle = '#8b956d';
                this.ctx.fillRect(0, 0, 160, 144);

                this.ctx.fillStyle = '#1a1c13';
                this.ctx.font = 'bold 14px sans-serif';
                this.ctx.textAlign = 'center';
                this.ctx.fillText("ConjureBoy", 80, y);

                if (y < 60) {
                    y += 1.5;
                } else {
                    if (!chimePlayed) {
                        this.playBootChime();
                        chimePlayed = true;
                    }
                    
                    if (titleOpacity < 1) {
                        titleOpacity += 0.02;
                    }
                    
                    this.ctx.globalAlpha = titleOpacity;
                    this.ctx.font = '10px monospace';
                    this.ctx.fillText(this.romTitle, 80, 90);
                    this.ctx.globalAlpha = 1.0;
                }

                this.animFrame = requestAnimationFrame(loop);
            };
            
            loop();
        }

        startCoreEmulator() {
            try {
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal("[CPU] Converting binary payload to Emulator ROM string...", "status");
                }
                
                // Convert Uint8Array to memory-safe binary string expected by GameBoyCore
                const len = this.romData.length;
                let binaryString = "";
                const chunk = 16384; // Reduced to prevent Safari "Maximum call stack size exceeded"
                for (let i = 0; i < len; i += chunk) {
                    const subArray = this.romData.subarray(i, i + chunk);
                    binaryString += String.fromCharCode.apply(null, subArray);
                }
                
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal("[CPU] Initializing GBC instruction registers...", "status");
                }
                
                // Sync settings array index mapping
                if (window.App) {
                    window.App.syncEmulatorSettings();
                }
                
                // Instantiate genuine core constructor GameBoyCore(canvas, ROM_string)
                this.gbInstance = new window.GameBoyCore(this.canvas, binaryString);

                // Inject our native SRAM loader handler hook
                if (this.loadedSramBase64) {
                    const sramRawStr = atob(this.loadedSramBase64);
                    const sramBytes = new Uint8Array(sramRawStr.length);
                    for (let i = 0; i < sramRawStr.length; i++) {
                        sramBytes[i] = sramRawStr.charCodeAt(i);
                    }
                    
                    // Bind SRAM loader hook expected by setupRAM() inside GameBoyCore
                    this.gbInstance.openMBC = () => {
                        if (window.App && window.App.logTerminal) {
                            window.App.logTerminal("[SYSTEM] Restoring SRAM battery records from SQLite...", "success");
                        }
                        return Array.from(sramBytes);
                    };
                }
                
                // Unlock the CPU before starting
                this.gbInstance.stopEmulator = 1;

                // Start the emulator cycle loop (executes boot/setupRAM)
                this.gbInstance.start();
                this.isRunning = true;
                
                // Establish high-performance, fixed-timestep frame pump loop
                let lastTime = performance.now();
                const frameTime = 1000 / 59.7275; // Native Game Boy framerate
                let timeAccumulator = 0;

                const gameLoop = (currentTime) => {
                    if (!this.isRunning) return;
                    
                    this.animFrame = requestAnimationFrame(gameLoop);
                    
                    const deltaTime = currentTime - lastTime;
                    lastTime = currentTime;
                    
                    // Scale elapsed delta time by speed fast-forward multiplier
                    timeAccumulator += deltaTime * this.speedMultiplier;
                    
                    // Prevent spiral of death if tab is backgrounded
                    const maxAccumulator = frameTime * 3 * this.speedMultiplier;
                    if (timeAccumulator > maxAccumulator) {
                        timeAccumulator = maxAccumulator;
                    }

                    while (timeAccumulator >= frameTime) {
                        timeAccumulator -= frameTime;
                        
                        // The core expects stopEmulator to have bit 0 set (1) when calling run().
                        if (this.gbInstance && (this.gbInstance.stopEmulator & 1) === 1) {
                            this.gbInstance.run();
                        }
                    }
                };
                
                this.animFrame = requestAnimationFrame(gameLoop);

                // Boot up slot dashboard previews
                this.updateSavesMetadata();

                // Start silent periodic auto-saves for SRAM data
                this.startSramAutoSaveWatchdog();
                
                this.setSpeedMultiplier(this.speedMultiplier);
                
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal("[CPU] Emulation execution loop started.", "success");
                }

                // Handle Quick Resume auto-load hook
                if (window.App && window.App.pendingQuickLoad) {
                    window.App.pendingQuickLoad = false;
                    setTimeout(() => {
                        this.loadState(99);
                    }, 100);
                }
            } catch (e) {
                console.error("[CPU] Core Init Exception:", e);
                this.drawError("CORE INSTRUCTION FAILURE");
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal(`[CPU Error] Init exception: ${e.message}`, "error");
                }
            }
        }

        // Silent periodic background watchdog timer checking SRAM writes every 10s
        startSramAutoSaveWatchdog() {
            this.lastSramHash = "";
            this.autoSaveInterval = setInterval(() => {
                this.checkAndSaveSRAM();
            }, 10000);
        }

        checkAndSaveSRAM(force = false) {
            if (!this.gbInstance || !this.gbInstance.MBCRam || this.gbInstance.MBCRam.length === 0) return;
            
            const sramArray = this.gbInstance.saveSRAMState();
            if (!sramArray || sramArray.length === 0) return;

            // Convert to base64
            const sramString = String.fromCharCode.apply(null, sramArray);
            const sramB64 = btoa(sramString);

            // Cancel write-back if nothing has mutated
            if (sramB64 === this.lastSramHash && !force) return;
            this.lastSramHash = sramB64;

            // Pulse battery LED amber to show silent SQLite commit
            const led = document.getElementById('power-led');
            if (led) {
                led.className = 'power-led led-saving';
                setTimeout(() => {
                    if (this.isRunning) led.className = 'power-led led-online';
                }, 400);
            }

            const formData = new FormData();
            formData.append('hash', this.activeHash);
            formData.append('sram', sramB64);

            fetch('index.php?action=save_sram', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && window.App && window.App.logTerminal) {
                    window.App.logTerminal("[SYSTEM] Auto-Save: SRAM database synchronized.", "success");
                }
            })
            .catch(err => console.error("SRAM backup write error:", err));
        }

        // Save State (Snapshots)
        saveState(slot) {
            if (!this.gbInstance || !this.isRunning) return;
            
            try {
                const stateArray = this.gbInstance.saveState();
                const stateJSON = JSON.stringify(stateArray);
                
                // Capture canvas viewport thumbnail
                const previewB64 = this.canvas.toDataURL("image/jpeg", 0.35);

                const formData = new FormData();
                formData.append('hash', this.activeHash);
                formData.append('slot', slot);
                formData.append('state', stateJSON);
                formData.append('preview', previewB64);

                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal(`[SYSTEM] Compiling save state snapshot for ${slot === 99 ? 'Quick Save' : 'Slot ' + slot}...`, "status");
                }

                fetch('index.php?action=save_state', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        window.App.Toast.show(slot === 99 ? "Quick Save Snapshot Saved" : `Slot ${slot} Snapshot Saved`, "success");
                        if (window.App && window.App.logTerminal) {
                            window.App.logTerminal(`[SYSTEM] Save state snapshot ${slot === 99 ? 'Quick Save' : slot} committed to SQLite database.`, "success");
                        }
                        this.updateSavesMetadata();
                    }
                })
                .catch(err => console.error("State save failure:", err));
            } catch (e) {
                console.error("Save state failed:", e);
            }
        }

        loadState(slot) {
            if (!this.gbInstance || !this.isRunning) return;

            if (window.App && window.App.logTerminal) {
                window.App.logTerminal(`[SYSTEM] Querying state registry for Slot ${slot}...`, "status");
            }

            fetch(`index.php?action=load_state&hash=${this.activeHash}&slot=${slot}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.state) {
                        const stateArray = JSON.parse(data.state);

                        // Pause emulator frame cycle during memory swap
                        this.stop();

                        // Write memory state registers back
                        this.gbInstance.returnFromState(stateArray);
                        this.isRunning = true;
                        this.gbInstance.stopEmulator = 1;

                        // Restart fixed timestep loop
                        this.restartLoop();

                        window.App.Toast.show(slot === 99 ? "Quick Save Snapshot Restored" : `Slot ${slot} Snapshot Restored`, "success");
                        if (window.App && window.App.logTerminal) {
                            window.App.logTerminal(`[SYSTEM] Core execution successfully restored to ${slot === 99 ? 'Quick Save' : 'Slot ' + slot} snapshot.`, "success");
                        }
                    } else {
                        if (slot === 99) {
                            window.App.Toast.show("No Quick Save snapshot exists yet", "error");
                        }
                    }
                })
                .catch(err => console.error("State restore lookup failure:", err));
        }

        deleteState(slot) {
            const formData = new FormData();
            formData.append('hash', this.activeHash);
            formData.append('slot', slot);

            fetch('index.php?action=delete_state', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.App.Toast.show(`Slot ${slot} Cleared`, "info");
                    this.updateSavesMetadata();
                }
            })
            .catch(err => console.error("State delete failed:", err));
        }

        updateSavesMetadata() {
            if (!this.activeHash) return;

            fetch(`index.php?action=get_states_meta&hash=${this.activeHash}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const statesMap = {};
                        data.states.forEach(s => {
                            statesMap[s.slot] = s;
                        });

                        const slotBtns = document.querySelectorAll('.slot-btn');
                        slotBtns.forEach(btn => {
                            const slot = parseInt(btn.getAttribute('data-slot'));
                            const state = statesMap[slot];
                            const dateBadge = btn.querySelector('.state-date');

                            if (state) {
                                btn.classList.add('active');
                                const date = new Date(state.updated_at);
                                const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                                dateBadge.textContent = timeStr;

                                // Set blurred background thumbnail
                                btn.style.backgroundImage = `linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url(${state.preview})`;
                                btn.style.backgroundSize = 'cover';
                                btn.style.backgroundPosition = 'center';
                            } else {
                                btn.classList.remove('active');
                                dateBadge.textContent = 'Empty';
                                btn.style.backgroundImage = 'none';
                            }
                        });
                    }
                })
                .catch(err => console.error("Failed to load saves meta:", err));
        }

        restartLoop() {
            let lastTime = performance.now();
            const frameTime = 1000 / 59.7275;
            let timeAccumulator = 0;

            const gameLoop = (currentTime) => {
                if (!this.isRunning) return;
                
                this.animFrame = requestAnimationFrame(gameLoop);
                
                const deltaTime = currentTime - lastTime;
                lastTime = currentTime;
                
                // Scale elapsed delta time by speed fast-forward multiplier
                timeAccumulator += deltaTime * this.speedMultiplier;
                
                // Prevent spiral of death if tab is backgrounded
                const maxAccumulator = frameTime * 3 * this.speedMultiplier;
                if (timeAccumulator > maxAccumulator) {
                    timeAccumulator = maxAccumulator;
                }

                while (timeAccumulator >= frameTime) {
                    timeAccumulator -= frameTime;
                    if (this.gbInstance && (this.gbInstance.stopEmulator & 1) === 1) {
                        this.gbInstance.run();
                    }
                }
            };
            this.animFrame = requestAnimationFrame(gameLoop);
        }

        drawError(msg) {
            this.ctx.fillStyle = '#8b956d';
            this.ctx.fillRect(0, 0, 160, 144);
            this.ctx.fillStyle = '#1a1c13';
            this.ctx.font = '10px monospace';
            this.ctx.textAlign = 'center';
            this.ctx.fillText(msg, 80, 72);
        }

        stop() {
            this.isRunning = false;
            if (this.animFrame) cancelAnimationFrame(this.animFrame);
            if (this.autoSaveInterval) clearInterval(this.autoSaveInterval);
            if (this.bypassRestoreTimer) clearTimeout(this.bypassRestoreTimer);
            
            // Force final SRAM backup before exit
            this.checkAndSaveSRAM(true);

            if (this.gbInstance && typeof this.gbInstance.stop === 'function') {
                this.gbInstance.stopEmulator = 3;
            }
            this.ctx.fillStyle = '#0c0c0e';
            this.ctx.fillRect(0, 0, 160, 144);
        }

        pressKey(key) {
            if (this.rapidFireEnabled && (key === 'A' || key === 'B')) {
                if (!this.rapidIntervals[key]) {
                    let state = false;
                    const joyIndex = this.JOYPAD_MAP[key];
                    this.rapidIntervals[key] = setInterval(() => {
                        state = !state;
                        if (this.gbInstance && typeof this.gbInstance.JoyPadEvent === 'function') {
                            this.gbInstance.JoyPadEvent(joyIndex, state);
                        }
                    }, 50); // fast rapid fire toggles alternately every 50ms for turbo speed
                }
                return;
            }

            const joyIndex = this.JOYPAD_MAP[key];
            if (this.gbInstance && typeof this.gbInstance.JoyPadEvent === 'function') {
                this.gbInstance.JoyPadEvent(joyIndex, true);
            } else {
                console.log(`[CPU Debug] Key Down: ${key} (mapped ID: ${joyIndex})`);
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal(`KEY_DOWN: ${key} (mapped id: ${joyIndex})`, "status");
                }
            }
        }

        releaseKey(key) {
            if (this.rapidIntervals[key]) {
                clearInterval(this.rapidIntervals[key]);
                this.rapidIntervals[key] = null;
                const joyIndex = this.JOYPAD_MAP[key];
                if (this.gbInstance && typeof this.gbInstance.JoyPadEvent === 'function') {
                    this.gbInstance.JoyPadEvent(joyIndex, false);
                }
                return;
            }

            const joyIndex = this.JOYPAD_MAP[key];
            if (this.gbInstance && typeof this.gbInstance.JoyPadEvent === 'function') {
                this.gbInstance.JoyPadEvent(joyIndex, false);
            } else {
                console.log(`[CPU Debug] Key Up: ${key} (mapped ID: ${joyIndex})`);
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal(`KEY_UP: ${key} (mapped id: ${joyIndex})`, "status");
                }
            }
        }
    };

    // Pure JS GBA Engine wrapper
    window.ConjureGbaEngine = class {
        constructor(canvasId) {
            this.canvas = document.getElementById(canvasId);
            
            // Set GBA 3:2 aspect ratio coordinate buffer bounds to prevent oversized cropping distortions
            this.canvas.width = 240;
            this.canvas.height = 160;

            this.gba = new window.GameBoyAdvance();
            this.gba.setCanvas(this.canvas);
            
            // Cache the 2D context with alpha disabled for hardware GPU optimization
            this.ctx = this.canvas.getContext('2d', { alpha: false });
            this.ctx.imageSmoothingEnabled = false;

            // Initialize frame dropping state
            this.framesDrawn = 0;
            this.frameSkip = 2; // Draw 1 frame, skip 1 frame (30 FPS) to drastically reduce mobile GPU/CPU load

            // Override GBA.js's offset-based draw loop with coordinate-safe scaling
            var self = this;
            this.gba.video.drawCallback = function() {
                self.framesDrawn++;
                if (self.framesDrawn % self.frameSkip !== 0) return; // Drop frame execution to save resources
                
                if (self.gba.indirectCanvas) {
                    self.ctx.drawImage(self.gba.indirectCanvas, 0, 0, self.canvas.width, self.canvas.height);
                }
            };

            // Disable GBA.js's native keyboard handlers to prevent input interception conflicts
            this.gba.keypad.keyboardHandler = function() {};

            this.activeHash = null;
            this.isRunning = false;
            this.volume = window.App ? window.App.volume : 0.5;
            this.speedMultiplier = 1.0;
            this.speedOptions = [1.0, 1.5, 2.0, 3.0, 4.0]; // GBA emulation has higher overhead, capped at 4.0x
            this.speedIndex = 0;
            this.isSpeedBypassed = false;
            this.savedSpeedMultiplier = 1.0;
            
            this.loadedSramBase64 = null;
            this.autoSaveInterval = null;
            this.lastSramHash = "";

            this.rapidFireEnabled = false;
            this.rapidIntervals = { 'A': null, 'B': null };
            
            // Map buttons directly to hardware GBA keypad register bits to bypass minifier mangling
            this.KEY_MAP = {
                'A': 0,
                'B': 1,
                'SELECT': 2,
                'START': 3,
                'RIGHT': 4,
                'LEFT': 5,
                'UP': 6,
                'DOWN': 7,
                'R': 8,
                'L': 9
            };
        }

        async loadROM(hash) {
            try {
                this.activeHash = hash;
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal("[GBA] Fetching open-source GBA Bios...", "status");
                }
                
                // Fetch the bios stub binary on the server
                const biosRes = await fetch('data/bios.bin');
                if (!biosRes.ok) throw new Error("GBA bios stub missing from server.");
                const biosBuffer = await biosRes.arrayBuffer();
                this.gba.setBios(biosBuffer);

                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal("[GBA] Fetching GBA ROM binary...", "status");
                }
                const res = await fetch(`index.php?action=download&hash=${hash}`);
                if (!res.ok) throw new Error("ROM fetch stream failed.");
                const romBuffer = await res.arrayBuffer();

                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal("[GBA] Initializing GBA instruction registers...", "status");
                }
                
                const parsed = this.gba.setRom(romBuffer);
                if (!parsed) {
                    console.error("[GBA] ROM Parse Error: setRom returned false.");
                    this.drawError("ROM PARSE ERROR");
                    return;
                }
                
                // Inject SRAM if available
                if (this.loadedSramBase64) {
                    try {
                        const sramRawStr = atob(this.loadedSramBase64);
                        const sramBytes = new Uint8Array(sramRawStr.length);
                        for (let i = 0; i < sramRawStr.length; i++) {
                            sramBytes[i] = sramRawStr.charCodeAt(i);
                        }
                        this.gba.setSavedata(sramBytes.buffer);
                        if (window.App && window.App.logTerminal) {
                            window.App.logTerminal("[GBA] Restored SRAM battery records from SQLite.", "success");
                        }
                    } catch (e) {
                        console.error("Failed to inject GBA SRAM:", e);
                    }
                }

                this.isRunning = true;
                this.startLoop();
                this.setVolume(this.volume);
                this.setSpeedMultiplier(this.speedMultiplier);
                
                this.updateSavesMetadata();
                this.startSramAutoSaveWatchdog();
                
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal("[GBA] GBA Emulation execution loop started.", "success");
                }

                // Handle Quick Resume auto-load hook
                if (window.App && window.App.pendingQuickLoad) {
                    window.App.pendingQuickLoad = false;
                    setTimeout(() => {
                        this.loadState(99);
                    }, 100);
                }

            } catch (err) {
                console.error("[GBA] Engine Initialization Failure:", err);
                this.drawError("GBA PARSE ERROR");
            }
        }

        setLoadedSram(base64Data) {
            this.loadedSramBase64 = base64Data;
        }

        startSramAutoSaveWatchdog() {
            this.lastSramHash = "";
            this.autoSaveInterval = setInterval(() => {
                this.checkAndSaveSRAM();
            }, 10000);
        }

        checkAndSaveSRAM(force = false) {
            if (!this.gba || !this.gba.mmu || !this.gba.mmu.save) return;
            if (!this.gba.mmu.saveNeedsFlush() && !force) return;
            
            try {
                const sramB64 = this.gba.encodeBase64(this.gba.mmu.save.view);
                if (!sramB64) return;
                
                if (sramB64 === this.lastSramHash && !force) return;
                this.lastSramHash = sramB64;
                
                this.gba.mmu.flushSave(); // Reset dirty flag

                const led = document.getElementById('power-led');
                if (led) {
                    led.className = 'power-led led-saving';
                    setTimeout(() => {
                        if (this.isRunning) led.className = 'power-led led-online';
                    }, 400);
                }

                const formData = new FormData();
                formData.append('hash', this.activeHash);
                formData.append('sram', sramB64);

                fetch('index.php?action=save_sram', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success && window.App && window.App.logTerminal) {
                        window.App.logTerminal("[GBA] Auto-Save: SRAM database synchronized.", "success");
                    }
                })
                .catch(err => console.error("SRAM backup write error:", err));
            } catch (e) {
                console.error("SRAM extraction failed:", e);
            }
        }

        saveState(slot) {
            if (!this.gba || !this.isRunning) return;
            
            try {
                this.gba.pause(); // Pause to prevent state mutation during save
                const frost = this.gba.freeze();
                const blob = Serializer.serialize(frost);
                
                const reader = new FileReader();
                reader.onload = (e) => {
                    const stateB64 = e.target.result; // data:application/octet-stream;base64,...
                    const previewB64 = this.canvas.toDataURL("image/jpeg", 0.35);

                    const formData = new FormData();
                    formData.append('hash', this.activeHash);
                    formData.append('slot', slot);
                    formData.append('state', stateB64);
                    formData.append('preview', previewB64);

                    if (window.App && window.App.logTerminal) {
                        window.App.logTerminal(`[GBA] Compiling save state snapshot for ${slot === 99 ? 'Quick Save' : 'Slot ' + slot}...`, "status");
                    }

                    fetch('index.php?action=save_state', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            window.App.Toast.show(slot === 99 ? "Quick Save Snapshot Saved" : `Slot ${slot} Snapshot Saved`, "success");
                            if (window.App && window.App.logTerminal) {
                                window.App.logTerminal(`[GBA] Save state snapshot ${slot === 99 ? 'Quick Save' : slot} committed to SQLite database.`, "success");
                            }
                            this.updateSavesMetadata();
                        }
                        this.isRunning = true;
                        this.startLoop();
                    })
                    .catch(err => {
                        console.error("State save failure:", err);
                        this.isRunning = true;
                        this.startLoop();
                    });
                };
                reader.readAsDataURL(blob);
            } catch (e) {
                console.error("Save state failed:", e);
                this.isRunning = true;
                this.startLoop();
            }
        }

        loadState(slot) {
            if (!this.gba || !this.isRunning) return;

            if (window.App && window.App.logTerminal) {
                window.App.logTerminal(`[GBA] Querying state registry for Slot ${slot}...`, "status");
            }

            fetch(`index.php?action=load_state&hash=${this.activeHash}&slot=${slot}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.state) {
                        this.gba.pause();
                        
                        fetch(data.state)
                            .then(res => res.blob())
                            .then(blob => {
                                Serializer.deserialize(blob, (frost) => {
                                    this.gba.defrost(frost);
                                    this.isRunning = true;
                                    this.startLoop();
                                    
                                    window.App.Toast.show(slot === 99 ? "Quick Save Snapshot Restored" : `Slot ${slot} Snapshot Restored`, "success");
                                    if (window.App && window.App.logTerminal) {
                                        window.App.logTerminal(`[GBA] Core execution successfully restored to ${slot === 99 ? 'Quick Save' : 'Slot ' + slot} snapshot.`, "success");
                                    }
                                });
                            })
                            .catch(err => {
                                console.error("Blob conversion failed:", err);
                                this.isRunning = true;
                                this.startLoop();
                            });
                    } else {
                        if (slot === 99) {
                            window.App.Toast.show("No Quick Save snapshot exists yet", "error");
                        }
                    }
                })
                .catch(err => console.error("State restore lookup failure:", err));
        }

        startLoop() {
            if (this.animFrame) cancelAnimationFrame(this.animFrame);
            this.gba.paused = false;
            if (this.gba.audio) this.gba.audio.pause(false);

            let lastTime = performance.now();
            const frameTime = 1000 / 59.7275;
            let timeAccumulator = 0;

            const gameLoop = (currentTime) => {
                if (!this.isRunning) return;
                
                this.animFrame = requestAnimationFrame(gameLoop);
                
                const deltaTime = currentTime - lastTime;
                lastTime = currentTime;
                
                timeAccumulator += deltaTime * this.speedMultiplier;
                
                const maxAccumulator = frameTime * 3 * this.speedMultiplier;
                if (timeAccumulator > maxAccumulator) {
                    timeAccumulator = maxAccumulator;
                }

                while (timeAccumulator >= frameTime) {
                    timeAccumulator -= frameTime;
                    if (this.gba) {
                        this.gba.advanceFrame();
                    }
                }
            };
            this.animFrame = requestAnimationFrame(gameLoop);
        }

        deleteState(slot) {
            const formData = new FormData();
            formData.append('hash', this.activeHash);
            formData.append('slot', slot);

            fetch('index.php?action=delete_state', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.App.Toast.show(`Slot ${slot} Cleared`, "info");
                    this.updateSavesMetadata();
                }
            })
            .catch(err => console.error("State delete failed:", err));
        }

        updateSavesMetadata() {
            if (!this.activeHash) return;

            fetch(`index.php?action=get_states_meta&hash=${this.activeHash}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const statesMap = {};
                        data.states.forEach(s => {
                            statesMap[s.slot] = s;
                        });

                        const slotBtns = document.querySelectorAll('.slot-btn');
                        slotBtns.forEach(btn => {
                            const slot = parseInt(btn.getAttribute('data-slot'));
                            const state = statesMap[slot];
                            const dateBadge = btn.querySelector('.state-date');

                            if (state) {
                                btn.classList.add('active');
                                const date = new Date(state.updated_at);
                                const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                                dateBadge.textContent = timeStr;

                                btn.style.backgroundImage = `linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url(${state.preview})`;
                                btn.style.backgroundSize = 'cover';
                                btn.style.backgroundPosition = 'center';
                            } else {
                                btn.classList.remove('active');
                                dateBadge.textContent = 'Empty';
                                btn.style.backgroundImage = 'none';
                            }
                        });
                    }
                })
                .catch(err => console.error("Failed to load saves meta:", err));
        }

        togglePause() {
            if (!this.gba) return false;
            if (this.isRunning) {
                this.gba.pause();
                this.isRunning = false;
                if (this.animFrame) cancelAnimationFrame(this.animFrame);
                return true;
            } else {
                this.isRunning = true;
                this.startLoop();
                return false;
            }
        }

        setVolume(v) {
            this.volume = v;
            if (this.gba && this.gba.audio) {
                this.gba.audio.masterVolume = v;
            }
        }

        pressKey(key) {
            if (this.rapidFireEnabled && (key === 'A' || key === 'B')) {
                if (!this.rapidIntervals[key]) {
                    let state = false;
                    const bit = this.KEY_MAP[key];
                    this.rapidIntervals[key] = setInterval(() => {
                        state = !state;
                        if (this.gba && this.gba.keypad) {
                            if (state) {
                                this.gba.keypad.currentDown &= ~(1 << bit);
                            } else {
                                this.gba.keypad.currentDown |= (1 << bit);
                            }
                        }
                    }, 50);
                }
                return;
            }

            const bit = this.KEY_MAP[key];
            if (bit !== undefined && this.gba && this.gba.keypad) {
                // GBA hardware registers are active-low (0 = pressed, 1 = released).
                // Use bitwise AND NOT to clear the specific bit.
                this.gba.keypad.currentDown &= ~(1 << bit);
            }
        }

        releaseKey(key) {
            if (this.rapidIntervals[key]) {
                clearInterval(this.rapidIntervals[key]);
                this.rapidIntervals[key] = null;
                const bit = this.KEY_MAP[key];
                if (this.gba && this.gba.keypad) {
                    this.gba.keypad.currentDown |= (1 << bit);
                }
                return;
            }

            const bit = this.KEY_MAP[key];
            if (bit !== undefined && this.gba && this.gba.keypad) {
                // Use bitwise OR to set the bit back to 1 (released).
                this.gba.keypad.currentDown |= (1 << bit);
            }
        }

        setSpeedMultiplier(multiplier) {
            this.speedMultiplier = multiplier;
            
            // Dynamically scale frame-skipping to maintain ~30FPS drawing and save CPU during fast-forward
            this.frameSkip = Math.max(2, Math.floor(2 * multiplier));

            // Adjust Web Audio API resample ratio to consume samples faster and prevent buffer echoing
            if (this.gba && this.gba.audio && this.gba.audio.context) {
                const baseRatio = this.gba.audio.sampleRate / this.gba.audio.context.sampleRate;
                this.gba.audio.resampleRatio = baseRatio * multiplier;
            }
            
            if (window.App && window.App.logTerminal) {
                window.App.logTerminal(`[GBA] Speed multiplier adjusted to ${this.speedMultiplier.toFixed(1)}x`, "status");
            }
        }

        setSpeedBypass(active) {
            if (!this.gba) return;
            if (this.isSpeedBypassed === active) return;
            
            this.isSpeedBypassed = active;
            if (this.isSpeedBypassed) {
                this.savedSpeedMultiplier = this.speedMultiplier;
                this.setSpeedMultiplier(1.0);
                if (window.App && window.App.el.speedIndicator) {
                    window.App.el.speedIndicator.textContent = '1.0x*';
                    window.App.el.speedIndicator.classList.add('bypassed');
                }
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal("[SYSTEM] Speed multiplier temporarily bypassed (Running at 1.0x).", "warning");
                }
            } else {
                if (this.bypassRestoreTimer) {
                    clearTimeout(this.bypassRestoreTimer);
                    this.bypassRestoreTimer = null;
                }
                const restoreMultiplier = this.savedSpeedMultiplier || 1.0;
                this.setSpeedMultiplier(restoreMultiplier);
                if (window.App && window.App.el.speedIndicator) {
                    window.App.el.speedIndicator.textContent = restoreMultiplier.toFixed(1) + 'x';
                    window.App.el.speedIndicator.classList.remove('bypassed');
                }
                if (window.App && window.App.logTerminal) {
                    window.App.logTerminal(`[SYSTEM] Speed multiplier restored to ${restoreMultiplier.toFixed(1)}x.`, "success");
                }
            }
        }

        toggleSpeedBypass() {
            this.setSpeedBypass(!this.isSpeedBypassed);
        }

        setRapidFire(active) {
            this.rapidFireEnabled = active;
            if (!active) {
                if (this.rapidIntervals['A']) { clearInterval(this.rapidIntervals['A']); this.rapidIntervals['A'] = null; }
                if (this.rapidIntervals['B']) { clearInterval(this.rapidIntervals['B']); this.rapidIntervals['B'] = null; }
            }
        }

        drawError(msg) {
            if (!this.ctx) this.ctx = this.canvas.getContext('2d', { alpha: false });
            this.ctx.fillStyle = '#1a1c13';
            this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
            this.ctx.fillStyle = '#8b956d';
            this.ctx.font = '12px monospace';
            this.ctx.textAlign = 'center';
            this.ctx.fillText(msg, this.canvas.width / 2, this.canvas.height / 2);
        }

        stop() {
            this.isRunning = false;
            if (this.animFrame) cancelAnimationFrame(this.animFrame);
            if (this.autoSaveInterval) clearInterval(this.autoSaveInterval);
            if (this.bypassRestoreTimer) clearTimeout(this.bypassRestoreTimer);
            
            this.checkAndSaveSRAM(true);

            if (this.gba) {
                this.gba.pause();
            }
            if (!this.ctx) this.ctx = this.canvas.getContext('2d', { alpha: false });
            this.ctx.fillStyle = '#0c0c0e';
            this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        window.App.init();
    });
})();