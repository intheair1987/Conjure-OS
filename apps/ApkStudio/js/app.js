const App = {
    async init() {
        this.bindEvents();
        await this.loadProjects();
        
        // Restore active workspace states from URL deep-links upon page reload
        const urlParams = new URLSearchParams(window.location.search);
        const projectId = urlParams.get('project');
        const file = urlParams.get('file');
        
        if (projectId) {
            const projectEl = document.querySelector(`.project-item[data-id="${projectId}"]`);
            if (projectEl) {
                await this.selectProject(parseInt(projectId));
                if (file) {
                    await this.loadFileContent(file);
                }
            }
        }

        // Start checking for local background compilation services
        this.startDaemonPoller();
        this.initResizeObserver();
    },

    initResizeObserver() {
        const container = document.getElementById('preview-content');
        if (container && window.ResizeObserver) {
            const ro = new ResizeObserver(() => {
                this.updatePhoneScale();
            });
            ro.observe(container);
        }
    },

    updatePhoneScale() {
        const container = document.getElementById('preview-content');
        const frame = document.getElementById('phone-frame');
        const scaler = document.getElementById('phone-frame-scaler');
        if (!container || !frame || !scaler) return;

        const availW = container.clientWidth - 24;
        const availH = container.clientHeight - 24;

        if (availW <= 0 || availH <= 0) return;

        const frameW = 384;
        const frameH = 784;

        const scale = Math.min(1.0, Math.min(availW / frameW, availH / frameH));

        frame.style.transform = `scale(${scale})`;
        scaler.style.width = `${frameW * scale}px`;
        scaler.style.height = `${frameH * scale}px`;
    },

    // Icon Generator Variables
    lucideIcons: [],
    lucideTagsData: {},
    activeLucideIcon: null,
    currentBase64Icon: "",
    uploadedSvgContent: null,
    uploadedSvgSize: 100,
    uploadedSvgRaw: false,
    activeProjectSvgSource: null,
    lastCompositeSvg: "",

    async openAppIconModal() {
        if (!this.currentProjectId) return;
        
        // Reset states
        this.currentBase64Icon = "";
        this.uploadedSvgContent = null;
        this.uploadedSvgSize = 100;
        this.uploadedSvgRaw = false;
        this.activeProjectSvgSource = null;
        this.lastCompositeSvg = "";
        
        document.getElementById('upload-svg-controls').style.display = 'none';
        document.getElementById('chkRawSvgUpload').checked = false;
        document.getElementById('svg-customizer-fields').style.display = 'block';
        document.getElementById('inpSizeUpload').value = 100;
        document.getElementById('txtSizeUpload').textContent = '100%';
        
        document.getElementById('inpIconStudio').value = '';
        document.getElementById('iconPreviewStudio').src = '';
        document.getElementById('iconPreviewContainerStudio').style.display = 'none';
        document.getElementById('iconPlaceholderStudio').style.display = 'block';
        
        document.getElementById('genPreviewStudio').src = '';
        document.getElementById('genPreviewStudio').style.display = 'none';
        document.getElementById('genPlaceholderStudio').style.display = 'block';
        
        // Query server to check if an active launcher icon is compiled
        try {
            const res = await fetch(`index.php?ajax=check_project_icon&id=${this.currentProjectId}`);
            const data = await res.json();
            const banner = document.getElementById('active-icon-banner');
            if (data.success && data.has_icon) {
                document.getElementById('active-icon-preview').src = data.icon_url;
                document.getElementById('active-icon-filename').textContent = data.icon_path;
                this.activeProjectSvgSource = data.icon_svg_source || null;
                banner.style.display = 'flex';
            } else {
                banner.style.display = 'none';
            }
        } catch (e) {
            console.error('Error checking project icon', e);
            document.getElementById('active-icon-banner').style.display = 'none';
        }
        
        this.switchIconTab('upload');
        this.openModal('modal-upload-icon');
    },

    switchIconTab(tab) {
        const btnUpload = document.getElementById('btn-icon-tab-upload');
        const btnGenerate = document.getElementById('btn-icon-tab-generate');
        const paneUpload = document.getElementById('pane-icon-upload');
        const paneGenerate = document.getElementById('pane-icon-generate');
        
        if (tab === 'upload') {
            btnUpload.classList.add('active');
            btnGenerate.classList.remove('active');
            paneUpload.style.display = 'block';
            paneGenerate.style.display = 'none';
            
            if (document.getElementById('iconPreviewStudio').src && document.getElementById('iconPreviewStudio').src.startsWith('data:')) {
                this.currentBase64Icon = document.getElementById('iconPreviewStudio').src.split(',')[1];
            } else {
                this.currentBase64Icon = "";
            }
        } else {
            btnGenerate.classList.add('active');
            btnUpload.classList.remove('active');
            paneGenerate.style.display = 'block';
            paneUpload.style.display = 'none';
            
            if (this.lucideIcons.length === 0) {
                this.fetchLucideIndex();
            } else {
                this.updateGeneratedIcon();
            }
        }
    },

    async fetchLucideIndex() {
        try {
            const res = await fetch('https://unpkg.com/lucide-static@latest/tags.json');
            this.lucideTagsData = await res.json();
            this.lucideIcons = Object.keys(this.lucideTagsData);
            this.renderIconGrid(this.lucideIcons.slice(0, 40));
            if (this.lucideIcons.length > 0 && !this.activeLucideIcon) {
                this.activeLucideIcon = 'globe';
                this.updateGeneratedIcon();
            }
        } catch (e) {
            console.error("Failed to fetch Lucide index", e);
            this.lucideIcons = ['globe', 'home', 'settings', 'user', 'star', 'heart', 'zap', 'compass', 'box', 'layers', 'skull'];
            this.lucideTagsData = {};
            this.lucideIcons.forEach(i => this.lucideTagsData[i] = [i]);
            this.renderIconGrid(this.lucideIcons);
            this.activeLucideIcon = 'globe';
            this.updateGeneratedIcon();
        }
    },

    renderIconGrid(icons) {
        const grid = document.getElementById('iconGridStudio');
        grid.innerHTML = '';
        icons.forEach(name => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `icon-btn ${name === this.activeLucideIcon ? 'active' : ''}`;
            btn.innerHTML = `<img src="https://unpkg.com/lucide-static@latest/icons/${name}.svg" style="width:24px; height:24px; filter: invert(1);" onerror="this.src='https://unpkg.com/lucide-static@latest/icons/help-circle.svg'">`;
            btn.onclick = () => {
                this.activeLucideIcon = name;
                document.querySelectorAll('#iconGridStudio .icon-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                this.updateGeneratedIcon();
            };
            grid.appendChild(btn);
        });
    },

    async updateGeneratedIcon() {
        if (!this.activeLucideIcon) return;
        try {
            const res = await fetch(`https://unpkg.com/lucide-static@latest/icons/${this.activeLucideIcon}.svg`);
            const svgText = await res.text();
            const innerMatch = svgText.match(/<svg[^>]*>([\s\S]*?)<\/svg>/i);
            const inner = innerMatch ? innerMatch[1] : '';
            
            const bgColor = document.getElementById('inpBgColorStudio').value || '#12101c';
            const fgColor = document.getElementById('inpFgColorStudio').value || '#ffffff';

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
                
                const genPreview = document.getElementById('genPreviewStudio');
                const genPlaceholder = document.getElementById('genPlaceholderStudio');
                
                if (genPreview) {
                    genPreview.src = dataUrl;
                    genPreview.style.display = 'block';
                }
                if (genPlaceholder) genPlaceholder.style.display = 'none';
                
                if (document.getElementById('btn-icon-tab-generate').classList.contains('active')) {
                    this.currentBase64Icon = dataUrl.split(',')[1];
                }
            };
            img.onerror = (e) => {
                console.error("Failed to render generated SVG icon", e);
            };
            img.src = dataUri;
        } catch (e) {
            console.error(e);
        }
    },

    editActiveIcon() {
        if (!this.activeProjectSvgSource) return;
        
        try {
            const parser = new DOMParser();
            const doc = parser.parseFromString(this.activeProjectSvgSource, 'image/svg+xml');
            const svgEl = doc.querySelector('svg');
            if (!svgEl) return;
            
            const configBase64 = svgEl.getAttribute('data-pwa-studio-config');
            if (!configBase64) return;
            
            const configJson = decodeURIComponent(escape(atob(configBase64)));
            const config = JSON.parse(configJson);
            
            // Re-hydrate variables
            this.uploadedSvgRaw = config.raw || false;
            this.uploadedSvgSize = config.size || 100;
            this.uploadedSvgContent = {
                rawText: config.rawText,
                inner: config.inner,
                viewBox: config.viewBox || "0 0 24 24",
                stroke: config.stroke || 'none',
                fill: config.fill || 'none'
            };
            
            // Re-hydrate UI Elements
            document.getElementById('chkRawSvgUpload').checked = this.uploadedSvgRaw;
            document.getElementById('inpBgColorUpload').value = config.bgColor || '#6366f1';
            document.getElementById('txtBgColorUpload').textContent = config.bgColor || '#6366f1';
            document.getElementById('inpFgColorUpload').value = config.fgColor || '#ffffff';
            document.getElementById('txtFgColorUpload').textContent = config.fgColor || '#ffffff';
            document.getElementById('inpSizeUpload').value = this.uploadedSvgSize;
            document.getElementById('txtSizeUpload').textContent = `${this.uploadedSvgSize}%`;
            
            document.getElementById('upload-svg-controls').style.display = 'block';
            document.getElementById('svg-customizer-fields').style.display = this.uploadedSvgRaw ? 'none' : 'block';
            
            // Draw live preview directly in customizer
            this.renderUploadedSvg();
            
            this.showToast("Active app icon loaded successfully!");
        } catch (e) {
            console.error("Failed to parse active icon config", e);
        }
    },

    renderUploadedSvg() {
        if (!this.uploadedSvgContent) return;
        try {
            let compositeSvg = "";
            const config = {
                raw: this.uploadedSvgRaw,
                bgColor: document.getElementById('inpBgColorUpload').value,
                fgColor: document.getElementById('inpFgColorUpload').value,
                size: this.uploadedSvgSize,
                viewBox: this.uploadedSvgContent.viewBox,
                stroke: this.uploadedSvgContent.stroke,
                fill: this.uploadedSvgContent.fill,
                inner: this.uploadedSvgContent.inner,
                rawText: this.uploadedSvgContent.rawText
            };
            
            // Base64-encode the JSON configuration to bypass XML attribute syntax restrictions
            const configJson = JSON.stringify(config);
            const configBase64 = btoa(unescape(encodeURIComponent(configJson)));

            if (this.uploadedSvgRaw) {
                compositeSvg = this.uploadedSvgContent.rawText;
                // Strip existing metadata attribute to prevent duplicate compilation warnings
                compositeSvg = compositeSvg.replace(/data-pwa-studio-config="[^"]+"/, '');
                
                // Force standard dimensions and inject dynamic config attribute
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
                compositeSvg = compositeSvg.replace('<svg', `<svg data-pwa-studio-config="${configBase64}"`);
            } else {
                const parts = this.uploadedSvgContent.viewBox.split(' ');
                const w = parseFloat(parts[2]) || 24;
                const h = parseFloat(parts[3]) || 24;
                
                // Process dynamic slider scale multiplier
                const sizeMultiplier = this.uploadedSvgSize / 100;
                const scale = Math.min(256 / w, 256 / h) * sizeMultiplier;
                const dx = (512 - w * scale) / 2;
                const dy = (512 - h * scale) / 2;
                
                // Smart check to override original stroke & fill colors cleanly
                let strokeColor = (this.uploadedSvgContent.stroke === 'none') ? 'none' : config.fgColor;
                let fillColor = this.uploadedSvgContent.fill;
                if (fillColor !== 'none' && fillColor !== '') {
                    fillColor = config.fgColor;
                }
                if (strokeColor === 'none' && fillColor === 'none') {
                    strokeColor = config.fgColor;
                }

                compositeSvg = `
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" data-pwa-studio-config="${configBase64}">
                        <rect width="512" height="512" fill="${config.bgColor}" rx="112" />
                        <g transform="translate(${dx}, ${dy}) scale(${scale})" stroke="${strokeColor}" fill="${fillColor}" stroke-linecap="round" stroke-linejoin="round">
                            ${this.uploadedSvgContent.inner}
                        </g>
                    </svg>
                `;
            }
            
            this.lastCompositeSvg = compositeSvg;
            const dataUri = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(compositeSvg)));
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                canvas.width = 512;
                canvas.height = 512;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);
                const dataUrl = canvas.toDataURL('image/png');
                
                document.getElementById('iconPreviewStudio').src = dataUrl;
                document.getElementById('iconPreviewContainerStudio').style.display = 'flex';
                document.getElementById('iconPlaceholderStudio').style.display = 'none';
                
                if (document.getElementById('btn-icon-tab-upload').classList.contains('active')) {
                    this.currentBase64Icon = dataUrl.split(',')[1];
                }
            };
            img.onerror = (e) => {
                console.error("Failed to render uploaded SVG icon", e);
            };
            img.src = dataUri;
        } catch (e) {
            console.error(e);
        }
    },

    async submitUploadIcon() {
        if (!this.currentProjectId || !this.currentBase64Icon) {
            return this.showToast("Please select or generate an icon first", "error");
        }

        const btn = document.getElementById('btn-submit-icon');
        const origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Saving...';

        const fd = new FormData();
        fd.append('ajax', 'save_project_icon');
        fd.append('id', this.currentProjectId);
        fd.append('icon_base64', this.currentBase64Icon);
        fd.append('icon_svg_source', this.lastCompositeSvg || '');

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.showToast('Icon updated successfully!');
                this.closeModal('modal-upload-icon');
                await this.loadProjects(); // Live Reload Sidebar Icons!
                this.selectProject(this.currentProjectId); // Refresh file tree
            } else {
                this.showToast(data.error || 'Failed to save icon', 'error');
            }
        } catch (e) {
            console.error(e);
            this.showToast('Error saving icon', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = origText;
        }
    },

    deleteProjectIcon() {
        if (!this.currentProjectId) return;
        this.showConfirm(
            "Delete App Icon?",
            "Are you sure you want to delete this custom launcher icon? The project will revert back to the default Android system icon.",
            async () => {
                const fd = new FormData();
                fd.append('ajax', 'delete_project_icon');
                fd.append('id', this.currentProjectId);

                try {
    const res = await fetch('index.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        this.showToast("App icon deleted successfully.");
        this.openAppIconModal(); // Refresh layout
        await this.loadProjects(); // Live Reload Sidebar Icons!
        this.selectProject(this.currentProjectId); // Refresh file tree
    } else {this.showToast(data.error || "Failed to delete icon", "error");
                    }
                } catch (e) {
                    console.error('Error deleting icon', e);
                }
            },
            "Delete",
            true
        );
    },

    bindEvents() {
        // Generic Contemporary Color Preset delegation handler
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
                        
                        // Force live update trigger
                        targetInput.dispatchEvent(new Event('input', { bubbles: true }));
                        targetInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            }
        });

        // Proxy click inside the studio icon upload zone to the hidden input
        document.getElementById('iconZoneStudio').addEventListener('click', () => {
            document.getElementById('inpIconStudio').click();
        });

        // Studio Icon file change listener (512x512 PNG Canvas Resizer + SVG Vector Builder)
        document.getElementById('inpIconStudio').addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;

            if (!file.type || !file.type.startsWith('image/')) {
                this.showToast("Please select a valid image file (.png, .jpg, .webp, .svg)", "error");
                e.target.value = '';
                return;
            }

            const isSvg = file.name.toLowerCase().endsWith('.svg') || file.type === 'image/svg+xml';

            if (isSvg) {
                document.getElementById('upload-svg-controls').style.display = 'block';
                const reader = new FileReader();
                reader.onload = (evt) => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(evt.target.result, 'image/svg+xml');
                    const svgEl = doc.querySelector('svg');
                    if (svgEl) {
                        this.uploadedSvgContent = {
                            rawText: evt.target.result,
                            inner: svgEl.innerHTML,
                            viewBox: svgEl.getAttribute('viewBox') || "0 0 24 24",
                            stroke: svgEl.getAttribute('stroke') || 'none',
                            fill: svgEl.getAttribute('fill') || 'none'
                        };
                        this.renderUploadedSvg();
                    } else {
                        this.showToast("Failed to parse SVG content.", "error");
                    }
                };
                reader.readAsText(file);
            } else {
                document.getElementById('upload-svg-controls').style.display = 'none';
                this.uploadedSvgContent = null;

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
                        
                        document.getElementById('iconPreviewStudio').src = dataUrl;
                        document.getElementById('iconPreviewContainerStudio').style.display = 'flex';
                        document.getElementById('iconPlaceholderStudio').style.display = 'none';
                        
                        if (document.getElementById('btn-icon-tab-upload').classList.contains('active')) {
                            this.currentBase64Icon = dataUrl.split(',')[1];
                        }
                    };
                    img.src = evt.target.result;
                };
                reader.readAsDataURL(file);
            }
        });

        // Studio live icon search input filter listener
        document.getElementById('inpIconSearchStudio').addEventListener('input', () => {
            const query = document.getElementById('inpIconSearchStudio').value.toLowerCase().trim();
            if (!query) {
                this.renderIconGrid(this.lucideIcons.slice(0, 40));
                return;
            }
            
            const filtered = this.lucideIcons.filter(name => {
                if (name.includes(query)) return true;
                const tags = this.lucideTagsData[name] || [];
                return tags.some(tag => tag.toLowerCase().includes(query));
            });
            
            this.renderIconGrid(filtered.slice(0, 40));
        });

        // Studio color picker live generators
        document.getElementById('inpBgColorStudio').addEventListener('input', () => {
            const hex = document.getElementById('inpBgColorStudio').value;
            const txt = document.getElementById('txtBgColorStudio');
            if (txt) txt.textContent = hex;
            this.updateGeneratedIcon();
        });
        document.getElementById('inpFgColorStudio').addEventListener('input', () => {
            const hex = document.getElementById('inpFgColorStudio').value;
            const txt = document.getElementById('txtFgColorStudio');
            if (txt) txt.textContent = hex;
            this.updateGeneratedIcon();
        });

        // Studio uploaded SVG background color live preview
        document.getElementById('inpBgColorUpload').addEventListener('input', () => {
            const hex = document.getElementById('inpBgColorUpload').value;
            document.getElementById('txtBgColorUpload').textContent = hex;
            this.renderUploadedSvg();
        });

        // Studio uploaded SVG icon color live preview
        document.getElementById('inpFgColorUpload').addEventListener('input', () => {
            const hex = document.getElementById('inpFgColorUpload').value;
            document.getElementById('txtFgColorUpload').textContent = hex;
            this.renderUploadedSvg();
        });

        // Studio uploaded SVG scale customizer slider
        document.getElementById('inpSizeUpload').addEventListener('input', (e) => {
            this.uploadedSvgSize = parseInt(e.target.value);
            document.getElementById('txtSizeUpload').textContent = `${this.uploadedSvgSize}%`;
            this.renderUploadedSvg();
        });

        // Studio uploaded SVG raw bypass toggle
        document.getElementById('chkRawSvgUpload').addEventListener('change', (e) => {
            this.uploadedSvgRaw = e.target.checked;
            document.getElementById('svg-customizer-fields').style.display = this.uploadedSvgRaw ? 'none' : 'block';
            this.renderUploadedSvg();
        });

        document.getElementById('btn-new-project').addEventListener('click', () => {
            this.openModal('modal-new-project');
        });

        // Package Name strict validation: lower-case letters, numbers, underscores, and dots only
        document.getElementById('inp-proj-pkg').addEventListener('input', (e) => {
            let val = e.target.value.toLowerCase();
            val = val.replace(/[^a-z0-9_.]/g, ''); 
            e.target.value = val;
        });

        document.getElementById('inp-edit-proj-pkg').addEventListener('input', (e) => {
            let val = e.target.value.toLowerCase();
            val = val.replace(/[^a-z0-9_.]/g, ''); 
            e.target.value = val;
        });

        // Target URL strict validation: lowercase letters, numbers, and standard URL punctuation only
        document.getElementById('inp-proj-url').addEventListener('input', (e) => {
            let val = e.target.value.toLowerCase();
            val = val.replace(/[^a-z0-9:\/\.\?=\&-_]/g, '');
            e.target.value = val;
        });

        // Key Alias validation: lowercase letters, numbers, underscores, and hyphens only (no spaces or capital letters)
        ['inp-key-alias', 'inp-gen-alias'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', (e) => {
                    let val = e.target.value.toLowerCase();
                    val = val.replace(/[^a-z0-9_-]/g, '');
                    e.target.value = val;
                });
            }
        });

        // Password fields: block spaces to ensure reliable shell arguments
        ['inp-key-storepass', 'inp-key-pass', 'inp-gen-storepass', 'inp-gen-pass'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', (e) => {
                    e.target.value = e.target.value.replace(/\s/g, '');
                });
            }
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            const select = document.getElementById('custom-proj-type');
            if (select && !select.contains(e.target)) {
                select.classList.remove('active');
            }
        });
    },

    openModal(id) {
        document.getElementById(id).classList.add('active');
    },

    closeModal(id) {
        document.getElementById(id).classList.remove('active');
        const select = document.getElementById('custom-proj-type');
        if (select) select.classList.remove('active');
    },

    toggleCustomSelect() {
        document.getElementById('custom-proj-type').classList.toggle('active');
    },

    selectCustomOption(val, text) {
        document.getElementById('inp-proj-type').value = val;
        document.getElementById('selected-type-text').textContent = text;
        document.getElementById('custom-proj-type').classList.remove('active');
        
        const urlGroup = document.getElementById('form-group-wrapper-url');
        urlGroup.style.display = val === 'wrapper' ? 'block' : 'none';
        
        document.querySelectorAll('.select-option').forEach(el => {
            el.classList.toggle('active', el.getAttribute('data-value') === val);
        });
    },

    showToast(message, type = 'success') {
        let toast = document.getElementById('apkstudio-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'apkstudio-toast';
            toast.className = 'toast-notification';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.className = `toast-notification ${type} active`;
        
        if (this.toastTimeout) clearTimeout(this.toastTimeout);
        this.toastTimeout = setTimeout(() => {
            toast.classList.remove('active');
        }, 3000);
    },

    showConfirm(title, message, onConfirm, okText = 'Confirm', isDanger = false) {
        document.getElementById('generic-confirm-title').textContent = title;
        document.getElementById('generic-confirm-message').textContent = message;
        
        const okBtn = document.getElementById('btn-generic-confirm-ok');
        okBtn.textContent = okText;
        if (isDanger) {
            okBtn.style.background = 'var(--danger)';
            okBtn.style.color = 'white';
        } else {
            okBtn.style.background = 'var(--primary-accent)';
            okBtn.style.color = 'white';
        }

        okBtn.onclick = () => {
            this.closeModal('modal-generic-confirm');
            if (onConfirm) onConfirm();
        };

        this.openModal('modal-generic-confirm');
    },

    async submitNewProject() {
        const name = document.getElementById('inp-proj-name').value;
        const pkg = document.getElementById('inp-proj-pkg').value;
        const type = document.getElementById('inp-proj-type').value;
        const url = document.getElementById('inp-proj-url').value;
        
        if(!name || !pkg) return this.showToast("Please fill in both fields", "error");
        if(type === 'wrapper' && !url) return this.showToast("Please specify the target URL", "error");

        const fd = new FormData();
        fd.append('ajax', 'create_project');
        fd.append('name', name);
        fd.append('package_name', pkg);
        fd.append('project_type', type);
        fd.append('wrapper_url', url);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if(data.success) {
                this.closeModal('modal-new-project');
                document.getElementById('inp-proj-name').value = '';
                document.getElementById('inp-proj-pkg').value = '';
                document.getElementById('inp-proj-url').value = '';
                this.selectCustomOption('standard', 'Standard Android App (Java/XML)');
                this.loadProjects();
                this.showToast("Project created successfully!");
            } else {
                this.showToast(data.error, "error");
            }
        } catch(e) { console.error(e); }
    },

    openEditProjectModal() {
        if (!this.currentProjectId || !this.projects) return;
        const proj = this.projects.find(p => p.id === this.currentProjectId);
        if (!proj) return;
        
        document.getElementById('inp-edit-proj-name').value = proj.name;
        document.getElementById('inp-edit-proj-pkg').value = proj.package_name;
        this.openModal('modal-edit-project');
    },

    async submitEditProject() {
        const id = this.currentProjectId;
        const name = document.getElementById('inp-edit-proj-name').value.trim();
        const pkg = document.getElementById('inp-edit-proj-pkg').value.trim();

        if (!name || !pkg) return this.showToast("Please fill in all fields", "error");

        const btn = document.querySelector('#modal-edit-project .btn-primary');
        const origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Saving...';

        const fd = new FormData();
        fd.append('ajax', 'edit_project');
        fd.append('id', id);
        fd.append('name', name);
        fd.append('package_name', pkg);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.closeModal('modal-edit-project');
                this.showToast("Project settings updated successfully!");
                await this.loadProjects();
                
                // Keep the sidebar highlight synchronized and reload files
                const url = new URL(window.location);
                url.searchParams.set('project', id);
                url.searchParams.delete('file');
                window.history.replaceState({}, '', url);

                await this.selectProject(id);
            } else {
                this.showToast(data.error || "Failed to update project", "error");
            }
        } catch (e) {
            console.error(e);
            this.showToast("Error updating project settings", "error");
        } finally {
            btn.disabled = false;
            btn.textContent = origText;
        }
    },

    deleteProject(id, name) {
        document.getElementById('delete-project-name').textContent = name;
        
        const confirmBtn = document.getElementById('btn-confirm-delete');
        confirmBtn.onclick = async () => {
            const fd = new FormData();
            fd.append('ajax', 'delete_project');
            fd.append('id', id);
            
            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const data = await res.json();
                if(data.success) {
                    this.closeModal('modal-delete-confirm');
                    this.loadProjects();
                    // Self-healing: if the active project was deleted, return workspace to home screen
                    if (this.currentProjectId === id) {
                        this.currentProjectId = null;
                        document.getElementById('project-workspace').classList.remove('active');
                        document.getElementById('empty-state').style.display = 'flex';
                        
                        // Clear deep-link params
                        const url = new URL(window.location);
                        url.searchParams.delete('project');
                        url.searchParams.delete('file');
                        window.history.replaceState({}, '', url);
                    }
                }
            } catch(e) { console.error(e); }
        };

        this.openModal('modal-delete-confirm');
    },

    async loadProjects() {
        try {
            const res = await fetch('index.php?ajax=get_projects');
            const data = await res.json();
            
            if (data.success) {
                this.projects = data.projects;
                this.renderProjects(data.projects);
            }
        } catch (e) {
            console.error('Failed to load projects', e);
        }
    },

    renderProjects(projects) {
        const list = document.getElementById('project-list');
        if (projects.length === 0) {
            list.innerHTML = '<div style="padding: 12px; color: var(--text-secondary); text-align: center; font-size: 13px;">No projects found</div>';
            return;
        }

        list.innerHTML = projects.map(p => {
            const isActive = p.id === this.currentProjectId;
            const iconHtml = p.icon_url 
                ? `<img src="${p.icon_url}" class="project-item-icon" style="width: 24px; height: 24px; border-radius: 6px; object-fit: contain; background: #000; padding: 2px; border: 1px solid var(--border-color); flex-shrink: 0;">` 
                : `<div class="project-item-icon-fallback" style="width: 24px; height: 24px; border-radius: 6px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; color: var(--text-secondary); border: 1px solid var(--border-color); flex-shrink: 0;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg></div>`;

            return `
                <div class="project-item${isActive ? ' active' : ''}" data-id="${p.id}" onclick="App.selectProject(${p.id})">
                    <div class="project-item-inner">
                        <div class="project-item-meta">
                            ${iconHtml}
                            <span>${p.name}</span>
                        </div>
                        <div class="project-item-actions">
                            <button class="btn-action btn-key" onclick="event.stopPropagation(); App.openUploadKeyModal(${p.id}, '${p.name.replace(/'/g, "\\'")}')" title="Upload Signing Key">
                                <img src="key.svg" class="btn-key-img" alt="Key">
                            </button>
                            <button class="btn-action btn-delete" onclick="event.stopPropagation(); App.deleteProject(${p.id}, '${p.name.replace(/'/g, "\\'")}')" title="Delete Project">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    },

    async openUploadKeyModal(projectId, projectName) {
        document.getElementById('key-project-id').value = projectId;
        
        // Reset all inputs in modal-upload-key
        document.getElementById('inp-key-file').value = '';
        document.getElementById('inp-key-storepass').value = '';
        document.getElementById('inp-key-alias').value = '';
        document.getElementById('inp-key-pass').value = '';
        document.getElementById('inp-gen-storepass').value = '';
        document.getElementById('inp-gen-alias').value = '';
        document.getElementById('inp-gen-pass').value = '';

        // Reset Active Display Values
        document.getElementById('active-key-storepass-display').value = '';
        document.getElementById('active-key-alias-display').value = '';
        document.getElementById('active-key-pass-display').value = '';
        
        // Close passwords
        document.getElementById('active-key-storepass-display').type = 'password';
        document.getElementById('active-key-pass-display').type = 'password';
        document.querySelectorAll('.signing-tab-pane input[type="password"] + button').forEach(btn => btn.textContent = 'Show');

        const activeTabBtn = document.getElementById('tab-btn-active');
        const generateTabBtn = document.getElementById('tab-btn-generate');
        const lockedMsg = document.getElementById('generate-locked-msg');
        const generateFields = document.getElementById('generate-form-fields');

        activeTabBtn.style.display = 'none';
        generateTabBtn.removeAttribute('disabled');
        generateTabBtn.style.opacity = '1';
        lockedMsg.style.display = 'none';
        generateFields.style.display = 'block';

        let initialMode = 'debug';

        try {
            const res = await fetch(`index.php?ajax=check_keystore&id=${projectId}`);
            const data = await res.json();
            if (data.success) {
                if (data.has_key) {
                    activeTabBtn.style.display = 'block';
                    
                    // Lock generate option if key already exists
                    generateTabBtn.style.opacity = '0.5';
                    lockedMsg.style.display = 'block';
                    generateFields.style.display = 'none';

                    // Populate Active tab displays
                    document.getElementById('active-key-storepass-display').value = data.storepass || '';
                    document.getElementById('active-key-alias-display').value = data.alias || '';
                    document.getElementById('active-key-pass-display').value = data.keypass || '';

                    // Default to custom if we have custom key and signing mode is custom
                    initialMode = data.mode === 'custom' ? 'active' : 'debug';
                } else {
                    initialMode = 'debug';
                }
            }
        } catch (e) {
            console.error('Error checking keystore', e);
        }

        this.switchSigningTab(initialMode);
        this.openModal('modal-upload-key');
    },

    switchSigningTab(tab) {
        // Enforce lock check on generate tab if active key exists
        if (tab === 'generate') {
            const activeTabBtn = document.getElementById('tab-btn-active');
            if (activeTabBtn && activeTabBtn.style.display === 'block') {
                this.showToast("Please delete your active custom key under the 'Custom Key' tab first.", "error");
                return;
            }
        }

        document.getElementById('inp-signing-mode').value = (tab === 'active') ? 'custom' : tab;

        // Visual Tab Highlight
        document.querySelectorAll('#signing-tab-menu .tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });

        // Map tab selection to button elements
        let targetBtn = null;
        if (tab === 'debug') targetBtn = document.querySelector('#signing-tab-menu .tab-btn:nth-child(1)');
        if (tab === 'upload') targetBtn = document.querySelector('#signing-tab-menu .tab-btn:nth-child(2)');
        if (tab === 'generate') targetBtn = document.getElementById('tab-btn-generate');
        if (tab === 'active') targetBtn = document.getElementById('tab-btn-active');
        if (targetBtn) targetBtn.classList.add('active');

        // Pane visibility
        document.querySelectorAll('.signing-tab-pane').forEach(pane => pane.style.display = 'none');
        if (tab === 'debug') document.getElementById('pane-signing-debug').style.display = 'block';
        if (tab === 'upload') document.getElementById('pane-signing-upload').style.display = 'block';
        if (tab === 'generate') document.getElementById('pane-signing-generate').style.display = 'block';
        if (tab === 'active') document.getElementById('pane-signing-active').style.display = 'block';

        // Adjust Form Field requirements dynamically (SSOT Form State)
        const fileInput = document.getElementById('inp-key-file');
        const uploadStorepass = document.getElementById('inp-key-storepass');
        const uploadAlias = document.getElementById('inp-key-alias');
        const genStorepass = document.getElementById('inp-gen-storepass');
        const genAlias = document.getElementById('inp-gen-alias');

        fileInput.removeAttribute('required');
        uploadStorepass.removeAttribute('required');
        uploadAlias.removeAttribute('required');
        genStorepass.removeAttribute('required');
        genAlias.removeAttribute('required');

        if (tab === 'upload') {
            fileInput.setAttribute('required', 'required');
            uploadStorepass.setAttribute('required', 'required');
            uploadAlias.setAttribute('required', 'required');
        } else if (tab === 'generate') {
            genStorepass.setAttribute('required', 'required');
            genAlias.setAttribute('required', 'required');
        }
    },

    togglePasswordVisibility(id) {
        const inp = document.getElementById(id);
        const btn = inp.nextElementSibling;
        if (inp.type === 'password') {
            inp.type = 'text';
            btn.textContent = 'Hide';
        } else {
            inp.type = 'password';
            btn.textContent = 'Show';
        }
    },

    async deleteKeystore() {
        const projectId = document.getElementById('key-project-id').value;
        this.showConfirm(
            "Delete Signing Key?",
            "Are you sure you want to delete this custom signing key? The project will revert back to the default debug signature key.",
            async () => {
                const fd = new FormData();
                fd.append('ajax', 'delete_keystore');
                fd.append('id', projectId);

                try {
                    const res = await fetch('index.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast("Custom signing key deleted successfully.");
                        this.openUploadKeyModal(projectId);
                    } else {
                        this.showToast(data.error || "Failed to delete signing key", "error");
                    }
                } catch (e) {
                    console.error('Error deleting keystore', e);
                }
            },
            "Delete",
            true
        );
    },

    async submitUploadKey() {
        const projectId = document.getElementById('key-project-id').value;
        const mode = document.getElementById('inp-signing-mode').value;

        const fd = new FormData();
        fd.append('ajax', 'upload_keystore');
        fd.append('id', projectId);
        fd.append('mode', mode);

        if (mode === 'debug') {
            // No additional parameters needed, server toggles and preserves values
        } else if (mode === 'upload') {
            const fileInput = document.getElementById('inp-key-file');
            const storepass = document.getElementById('inp-key-storepass').value;
            const alias = document.getElementById('inp-key-alias').value;
            const keypass = document.getElementById('inp-key-pass').value;

            fd.append('generate', '0');
            fd.append('storepass', storepass);
            fd.append('alias', alias);
            fd.append('keypass', keypass || storepass);
            if (fileInput.files.length > 0) {
                fd.append('keystore_file', fileInput.files[0]);
            }
        } else if (mode === 'generate') {
            const storepass = document.getElementById('inp-gen-storepass').value;
            const alias = document.getElementById('inp-gen-alias').value;
            const keypass = document.getElementById('inp-gen-pass').value;

            fd.append('generate', '1');
            fd.append('storepass', storepass);
            fd.append('alias', alias);
            fd.append('keypass', keypass || storepass);
        } else if (mode === 'custom') {
            // Already active custom key selected without modifications, we can keep it as is
            fd.append('generate', '0');
        }

        const submitBtn = document.getElementById('btn-submit-key');
        const originalText = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = (mode === 'generate') ? 'Generating...' : 'Saving...';

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.showToast("Signing key configuration saved successfully!");
                this.closeModal('modal-upload-key');
            } else {
                this.showToast(data.error || "Failed to configure signing key", "error");
            }
        } catch(e) {
            console.error(e);
            this.showToast("Error configuring signing key", "error");
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    },

    closeActiveProject() {
        this.currentProjectId = null;
        this.currentProjectName = null;
        
        const container = document.querySelector('.app-container');
        if (container) container.classList.remove('has-active-project');

        // Remove sidebar selection highlighting
        document.querySelectorAll('.project-item').forEach(el => el.classList.remove('active'));
        
        // Hide workspace and restore empty home state
        document.getElementById('project-workspace').classList.remove('active');
        document.getElementById('empty-state').style.display = 'flex';
        
        // Reset code editor pane
        document.getElementById('code-content').textContent = '';
        document.getElementById('code-header-path').textContent = 'Select a file';
        document.getElementById('btn-copy-path').style.display = 'none';

        // Clear deep-link search parameters
        const url = new URL(window.location);
        url.searchParams.delete('project');
        url.searchParams.delete('file');
        window.history.replaceState({}, '', url);
    },

    async selectProject(id) {
        document.querySelectorAll('.project-item').forEach(el => el.classList.remove('active'));
        
        const projectItem = document.querySelector(`.project-item[data-id="${id}"]`);
        if (projectItem) {
            projectItem.classList.add('active');
            // Update workspace header title dynamically
            const projectName = projectItem.querySelector('span').textContent;
            document.getElementById('active-project-title').textContent = projectName;
            this.currentProjectName = projectName;
        } else {
            this.currentProjectName = null;
        }
        
        this.currentProjectId = id;
        const container = document.querySelector('.app-container');
        if (container) container.classList.add('has-active-project');

        document.getElementById('empty-state').style.display = 'none';
        document.getElementById('project-workspace').classList.add('active');
        document.getElementById('code-content').textContent = '';
        document.getElementById('code-header-path').textContent = 'Loading tree...';
        document.getElementById('btn-copy-path').style.display = 'none';

        // Deep-link: update URL params to reference active project, and clear stale file references
        const url = new URL(window.location);
        url.searchParams.set('project', id);
        url.searchParams.delete('file');
        window.history.replaceState({}, '', url);

        const fd = new FormData();
        fd.append('ajax', 'get_file_tree');
        fd.append('id', id);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if(data.success) {
                this.currentBasePath = data.base_path;
                document.getElementById('code-header-path').textContent = 'Select a file';
                this.renderFileTree(data.tree, document.getElementById('tree-container'));
            } else {
                document.getElementById('code-header-path').textContent = 'Error loading tree';
            }
        } catch(e) { console.error(e); }
    },
    
    showBuildCommand() {
        if (!this.currentProjectId) return;
        const url = window.location.origin + window.location.pathname + '?ajax=download_zip&id=' + this.currentProjectId;
        const cmd = `rm -rf ~/apk_build && mkdir -p ~/apk_build && cd ~/apk_build && curl -fsSLk "${url}" -o project.zip && unzip -oq project.zip && chmod +x build.sh && AUTO_EXIT=1 bash ./build.sh`;
        document.getElementById('inp-build-cmd').value = cmd;
        this.openModal('modal-build-cmd');
    },

    copyBuildCommand() {
        const cmd = document.getElementById('inp-build-cmd').value;
        navigator.clipboard.writeText(cmd);
        const btn = document.querySelector('#modal-build-cmd .btn-primary');
        const origText = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = origText, 1500);
    },

    checkDaemonInterval: null,
    activeDaemonPort: 8089,

    startDaemonPoller() {
        if (this.checkDaemonInterval) clearInterval(this.checkDaemonInterval);

        const candidatePorts = [8089, 8090, 8091, 8092];

        const checkStatus = async () => {
            if (document.hidden) return;
            for (const port of candidatePorts) {
                try {
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 1000);
                    const res = await fetch(`http://localhost:${port}/status`, { signal: controller.signal });
                    clearTimeout(timeoutId);

                    if (res.ok) {
                        const data = await res.json();
                        if (data.service === 'buildkernel' && data.online) {
                            this.activeDaemonPort = port;
                            this.setDaemonOnline(true);
                            return;
                        }
                    }
                } catch(e) {}
            }
            this.setDaemonOnline(false);
        };

        checkStatus();
        this.checkDaemonInterval = setInterval(checkStatus, 4000);
    },

    setDaemonOnline(isOnline) {
        const daemonBtn = document.getElementById('btn-daemon-build');
        if (!daemonBtn) return;
        daemonBtn.style.display = isOnline ? 'flex' : 'none';
    },

    copyConsoleLogs() {
        const consoleOut = document.getElementById('build-console-output');
        if (!consoleOut) return;
        const text = consoleOut.textContent || "";
        const formatted = "```text\n" + text.trim() + "\n```";
        
        navigator.clipboard.writeText(formatted).then(() => {
            const copyBtn = document.getElementById('btn-copy-console');
            if (copyBtn) {
                const origText = copyBtn.textContent;
                copyBtn.textContent = 'Copied!';
                setTimeout(() => copyBtn.textContent = origText, 1500);
            }
        }).catch(err => {
            console.error('Failed to copy logs: ', err);
        });
    },

    openBuildConsole() {
        const consoleOut = document.getElementById('build-console-output');
        consoleOut.textContent = '';
        const closeBtn = document.getElementById('btn-close-console');
        closeBtn.disabled = true;
        closeBtn.textContent = 'Compiling...';
        this.openModal('modal-build-console');
    },

    logToConsole(msg) {
        const consoleOut = document.getElementById('build-console-output');
        consoleOut.textContent += msg + '\n';
        consoleOut.scrollTop = consoleOut.scrollHeight;

        // Verify resolution indicators to unlock console exit interface
        if (msg.includes("[BUILD_RESULT]")) {
            const closeBtn = document.getElementById('btn-close-console');
            closeBtn.disabled = false;
            closeBtn.textContent = 'Close';
        }
    },

    async triggerDaemonBuild() {
        if (!this.currentProjectId) return;

        this.openBuildConsole();
        this.logToConsole("Initializing local compiler daemon pipeline...");

        try {
            const projectItem = document.querySelector(`.project-item[data-id="${this.currentProjectId}"]`);
            const projectName = projectItem.querySelector('span').textContent;

            this.logToConsole("Requesting project packaging ZIP from host...");
            const zipRes = await fetch(`index.php?ajax=download_zip&id=${this.currentProjectId}`);
            if (!zipRes.ok) {
                throw new Error("PHP backend failed to pack sources.");
            }
            const zipBlob = await zipRes.blob();

            const daemonPort = this.activeDaemonPort || 8089;
            this.logToConsole(`Forwarding packed Blob to local compiler daemon on port ${daemonPort}...`);
            const compileUrl = `http://localhost:${daemonPort}/compile?name=${encodeURIComponent(projectName)}`;
            
            const response = await fetch(compileUrl, {
                method: 'POST',
                body: zipBlob
            });

            if (!response.ok) {
                throw new Error("Daemon compiler refused transaction: " + response.statusText);
            }

            // Bind streaming stream reader to paint terminal lines as they compile
            const reader = response.body.getReader();
            const decoder = new TextDecoder("UTF-8");
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop(); // Hold incomplete trailing lines

                for (const line of lines) {
                    if (line.trim()) {
                        this.logToConsole(line);
                    }
                }
            }
            if (buffer.trim()) {
                this.logToConsole(buffer);
            }

        } catch (e) {
            this.logToConsole("❌ Critical Error: " + e.message);
        } finally {
            const closeBtn = document.getElementById('btn-close-console');
            closeBtn.disabled = false;
            if (closeBtn.textContent === 'Compiling...') {
                closeBtn.textContent = 'Close';
            }
        }
    },

    renderFileTree(nodes, container) {
        container.innerHTML = '';
        nodes.forEach(node => {
            const el = document.createElement('div');
            const header = document.createElement('div');
            header.className = `tree-item ${node.is_dir ? 'tree-folder' : 'tree-file'}`;
            
            const iconSvg = node.is_dir 
                ? `<svg class="tree-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>`
                : `<svg class="tree-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>`;
                
            header.innerHTML = `${iconSvg} <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${node.name}</span>`;
            el.appendChild(header);
            
            if (node.is_dir) {
                const childrenContainer = document.createElement('div');
                childrenContainer.className = 'tree-children';
                // Folders open by default for small trees
                this.renderFileTree(node.children, childrenContainer);
                el.appendChild(childrenContainer);
                
                header.addEventListener('click', (e) => {
                    e.stopPropagation();
                    childrenContainer.style.display = childrenContainer.style.display === 'none' ? 'block' : 'none';
                });
            } else {
                header.setAttribute('data-path', node.path); // Set programmatic path signature
                header.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.loadFileContent(node.path);
                });
            }
            
            container.appendChild(el);
        });
    },

    async loadFileContent(relPath) {
        document.getElementById('code-header-path').textContent = 'Loading...';
        document.getElementById('code-content').textContent = '';
        
        // Toggle Live Preview Tab visibility
        const ext = relPath.toLowerCase().split('.').pop();
        if (['html', 'htm', 'xml', 'svg'].includes(ext)) {
            document.getElementById('view-tabs').style.display = 'flex';
        } else {
            document.getElementById('view-tabs').style.display = 'none';
            this.switchViewTab('code');
        }

        // Deep-link: update URL params to point to the active file
        const url = new URL(window.location);
        url.searchParams.set('file', relPath);
        window.history.replaceState({}, '', url);

        // Highlight: visually isolate the active file header in the tree view (SSOT)
        document.querySelectorAll('.tree-item').forEach(i => i.style.background = '');
        const activeHeader = document.querySelector(`.tree-item[data-path="${relPath}"]`);
        if (activeHeader) {
            activeHeader.style.background = 'var(--bg-color)';
        }

        const fd = new FormData();
        fd.append('ajax', 'get_file_content');
        fd.append('id', this.currentProjectId);
        fd.append('path', relPath);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if(data.success) {
                const fullPath = `${this.currentBasePath}/${relPath}`;
                document.getElementById('code-header-path').textContent = fullPath;
                document.getElementById('code-content').textContent = data.content;
                
                const copyBtn = document.getElementById('btn-copy-path');
                copyBtn.style.display = 'flex';
                copyBtn.onclick = () => {
                    navigator.clipboard.writeText(fullPath);
                    // Brief flash effect
                    copyBtn.style.color = 'var(--primary-accent)';
                    setTimeout(() => copyBtn.style.color = '', 1000);
                };

                // Auto-refresh preview if tab is active
                if (document.querySelector('.view-tabs .tab-btn[data-tab="preview"]').classList.contains('active')) {
                    this.renderFilePreview();
                }
            } else {
                document.getElementById('code-header-path').textContent = 'Error loading file';
            }
        } catch(e) { console.error(e); }
    },

    switchViewTab(tab) {
        document.querySelectorAll('.view-tabs .tab-btn').forEach(b => b.classList.remove('active'));
        const activeBtn = document.querySelector(`.view-tabs .tab-btn[data-tab="${tab}"]`);
        if (activeBtn) activeBtn.classList.add('active');
        
        if (tab === 'code') {
            document.getElementById('code-content').style.display = 'block';
            document.getElementById('preview-content').style.display = 'none';
        } else {
            document.getElementById('code-content').style.display = 'none';
            document.getElementById('preview-content').style.display = 'flex';
            this.renderFilePreview();
            this.updatePhoneScale();
        }
    },

    async renderFilePreview() {
        const fullPath = document.getElementById('code-header-path').textContent || '';
        const ext = fullPath.toLowerCase().split('.').pop();
        const content = document.getElementById('code-content').textContent || '';
        const screen = document.getElementById('preview-phone-screen');
        if (!screen) return;
        screen.innerHTML = '';

        if (ext === 'html' || ext === 'htm') {
            await this.renderHtmlPreview(content, fullPath, screen);
        } else if (ext === 'svg') {
            this.renderSvgPreview(content, screen);
        } else if (ext === 'xml') {
            this.renderXmlPreview();
        } else {
            screen.innerHTML = '<div style="color:var(--text-secondary); padding:20px; text-align:center;">Preview not supported for this file type.</div>';
        }
        this.updatePhoneScale();
    },

    async renderHtmlPreview(rawHtml, fullPath, screen) {
        screen.style.display = 'block';
        screen.style.width = '100%';
        screen.style.height = '100%';
        screen.style.padding = '0';
        screen.style.margin = '0';
        screen.style.overflow = 'hidden';

        const iframe = document.createElement('iframe');
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        iframe.style.borderRadius = '24px';
        iframe.style.background = '#08080d';
        iframe.style.margin = '0';
        iframe.style.padding = '0';
        iframe.style.boxSizing = 'border-box';

        let processedHtml = rawHtml;

        let relDir = "";
        if (this.currentBasePath && fullPath.startsWith(this.currentBasePath)) {
            const projectRelPath = fullPath.substring(this.currentBasePath.length).replace(/^\/+/, '');
            const parts = projectRelPath.split('/');
            parts.pop();
            relDir = parts.join('/');
        }

        const linkRegex = /<link\s+[^>]*href=["']([^"']+\.css)["'][^>]*>/gi;
        let match;
        const cssTasks = [];

        while ((match = linkRegex.exec(rawHtml)) !== null) {
            const href = match[1];
            if (!href.startsWith('http://') && !href.startsWith('https://') && !href.startsWith('//')) {
                const targetPath = relDir ? `${relDir}/${href}`.replace(/\/+/g, '/') : href;
                cssTasks.push({ tag: match[0], href: href, path: targetPath });
            }
        }

        for (const task of cssTasks) {
            try {
                const fd = new FormData();
                fd.append('ajax', 'get_file_content');
                fd.append('id', this.currentProjectId);
                fd.append('path', task.path);
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success && data.content) {
                    processedHtml = processedHtml.replace(task.tag, `<style>\n${data.content}\n</style>`);
                }
            } catch (e) {
                console.error("Failed to inline CSS for preview", task.path, e);
            }
        }

        screen.appendChild(iframe);
        iframe.srcdoc = processedHtml;
    },

    renderSvgPreview(svgContent, screen) {
        screen.style.display = 'flex';
        screen.style.alignItems = 'center';
        screen.style.justifyContent = 'center';
        screen.style.padding = '16px';
        screen.style.width = '100%';
        screen.style.height = '100%';
        screen.style.boxSizing = 'border-box';
        screen.style.overflow = 'hidden';

        const wrapper = document.createElement('div');
        wrapper.style.width = '100%';
        wrapper.style.height = '100%';
        wrapper.style.display = 'flex';
        wrapper.style.alignItems = 'center';
        wrapper.style.justifyContent = 'center';
        wrapper.style.overflow = 'hidden';
        wrapper.innerHTML = svgContent;

        const svgEl = wrapper.querySelector('svg');
        if (svgEl) {
            svgEl.removeAttribute('width');
            svgEl.removeAttribute('height');
            svgEl.style.width = '100%';
            svgEl.style.height = '100%';
            svgEl.style.maxWidth = '100%';
            svgEl.style.maxHeight = '100%';
            svgEl.style.objectFit = 'contain';
        }

        screen.appendChild(wrapper);
    },

    renderXmlPreview() {
        const xmlString = document.getElementById('code-content').textContent;
        const screen = document.getElementById('preview-phone-screen');
        screen.innerHTML = '';
        
        try {
            const parser = new DOMParser();
            const xmlDoc = parser.parseFromString(xmlString, "text/xml");
            const root = xmlDoc.documentElement;
            
            if (root.nodeName === "parsererror") {
                screen.innerHTML = "<div style='color:#ff453a; padding:20px; font-family:monospace;'>XML Parse Error</div>";
                return;
            }

            const htmlNode = this.convertXmlNode(root);
            if (htmlNode) {
                if (!root.getAttribute('android:layout_width')) htmlNode.style.width = '100%';
                if (!root.getAttribute('android:layout_height')) htmlNode.style.height = '100%';
                htmlNode.style.fontFamily = 'system-ui, -apple-system, sans-serif';
                screen.appendChild(htmlNode);
            }
        } catch (e) {
            screen.innerHTML = `<div style='color:#ff453a; padding:20px;'>Error: ${e.message}</div>`;
        }
    },

    convertXmlNode(node) {
        if (node.nodeType !== 1) return null; // Element nodes only
        
        const el = document.createElement('div');
        const tag = node.nodeName;
        
        // Base CSS flexbox mapping for Android Views
        el.style.boxSizing = 'border-box';
        el.style.display = 'flex';
        el.style.flexDirection = 'column'; 
        el.style.position = 'relative';
        el.style.flexShrink = '0';
        
        // Extract all attributes and allow tools: namespace to override android: namespace for previews
        const activeAttrs = {};
        for (let i = 0; i < node.attributes.length; i++) {
            activeAttrs[node.attributes[i].name] = node.attributes[i].value;
        }
        for (let i = 0; i < node.attributes.length; i++) {
            if (node.attributes[i].name.startsWith('tools:')) {
                const androidName = node.attributes[i].name.replace('tools:', 'android:');
                activeAttrs[androidName] = node.attributes[i].value;
                activeAttrs[node.attributes[i].name] = node.attributes[i].value;
            }
        }

        const orient = activeAttrs['android:orientation'] || 'horizontal';
        const isCol = orient === 'vertical';

        // Tag-specific defaults mapping
        if (tag === 'LinearLayout') {
            el.style.flexDirection = isCol ? 'column' : 'row';
        } else if (tag === 'ScrollView') {
            el.style.overflowY = 'auto';
            el.style.overflowX = 'hidden';
            if (activeAttrs['android:fillViewport'] === 'true') {
                el.style.flex = '1';
            }
        } else if (tag === 'TextView') {
            el.style.display = 'flex';
            el.innerText = activeAttrs['android:text'] || '';
            el.style.color = '#ffffff'; // Default fallback
        } else if (tag === 'Button') {
            el.style.display = 'flex';
            el.style.alignItems = 'center';
            el.style.justifyContent = 'center';
            el.style.backgroundColor = '#2c2c2e';
            el.style.color = '#ffffff';
            el.style.padding = '12px 16px';
            el.style.borderRadius = '6px';
            el.style.fontWeight = '500';
            el.innerText = activeAttrs['android:text'] || '';
            if (activeAttrs['android:textAllCaps'] !== 'false') {
                el.style.textTransform = 'uppercase';
            }
        } else if (tag === 'Switch') {
            el.style.width = '44px';
            el.style.height = '24px';
            el.style.backgroundColor = '#2c2c2e';
            el.style.borderRadius = '12px';
            el.style.border = '1px solid #3a3a3c';
        } else if (tag === 'SeekBar') {
            el.style.height = '4px';
            el.style.backgroundColor = '#3a3a3c';
            el.style.margin = '10px 0';
            el.style.borderRadius = '2px';
        } else if (tag === 'ImageView') {
            el.style.display = 'flex';
            el.style.alignItems = 'center';
            el.style.justifyContent = 'center';
            const src = activeAttrs['android:src'] || '';
            if (src === '@null' || src === 'null' || src === '') {
                el.innerHTML = '';
            } else {
                let svg = '<svg width="100%" height="100%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="12" cy="12" r="3"/></svg>';
                if (src.includes('compass')) svg = '<svg width="100%" height="100%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>';
                if (src.includes('close') || src.includes('cancel')) svg = '<svg width="100%" height="100%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
                el.innerHTML = svg;
            }
            el.style.color = '#ffffff';
        } else if (tag === 'Space') {
            el.style.background = 'transparent';
        } else if (tag === 'View') {
            // Standard empty view
        }
        
        let hasWeight = false;
        
        // Map Android Attributes to CSS
        for (const name in activeAttrs) {
            const val = activeAttrs[name];
            const pxVal = val.replace('dp', 'px').replace('sp', 'px');
            
            if (name === 'android:layout_width') {
                if (val === 'match_parent' || val === 'fill_parent') el.style.width = '100%';
                else if (val === 'wrap_content') el.style.width = 'max-content';
                else el.style.width = pxVal;
            } else if (name === 'android:layout_height') {
                if (val === 'match_parent' || val === 'fill_parent') el.style.height = '100%';
                else if (val === 'wrap_content') el.style.height = 'max-content';
                else el.style.height = pxVal;
            } else if (name === 'android:layout_weight') {
                el.style.flexGrow = val;
                hasWeight = true;
            } else if (name === 'android:background') {
                el.style.background = this.parseAndroidColor(val);
            } else if (name === 'android:textColor') {
                el.style.color = this.parseAndroidColor(val);
            } else if (name === 'android:textSize') {
                el.style.fontSize = pxVal;
            } else if (name === 'android:textStyle') {
                if (val.includes('bold')) el.style.fontWeight = 'bold';
                if (val.includes('italic')) el.style.fontStyle = 'italic';
            } else if (name.startsWith('android:padding')) {
                if (name === 'android:padding') el.style.padding = pxVal;
                if (name === 'android:paddingLeft' || name === 'android:paddingStart') el.style.paddingLeft = pxVal;
                if (name === 'android:paddingRight' || name === 'android:paddingEnd') el.style.paddingRight = pxVal;
                if (name === 'android:paddingTop') el.style.paddingTop = pxVal;
                if (name === 'android:paddingBottom') el.style.paddingBottom = pxVal;
            } else if (name.startsWith('android:layout_margin') && name !== 'android:layout_marginBottom' && name !== 'android:layout_marginTop' && name !== 'android:layout_marginLeft' && name !== 'android:layout_marginRight') {
                if (name === 'android:layout_margin') el.style.margin = pxVal;
            } else if (name === 'android:layout_marginLeft' || name === 'android:layout_marginStart') {
                el.style.marginLeft = pxVal;
            } else if (name === 'android:layout_marginRight' || name === 'android:layout_marginEnd') {
                el.style.marginRight = pxVal;
            } else if (name === 'android:layout_marginTop') {
                el.style.marginTop = pxVal;
            } else if (name === 'android:layout_marginBottom') {
                el.style.marginBottom = pxVal;
            } else if (name === 'android:gravity') {
                const gravities = val.split('|');
                gravities.forEach(g => {
                    if (g === 'center') {
                        el.style.alignItems = 'center';
                        el.style.justifyContent = 'center';
                    } else if (g === 'center_vertical') {
                        if (isCol) el.style.justifyContent = 'center';
                        else el.style.alignItems = 'center';
                    } else if (g === 'center_horizontal') {
                        if (isCol) el.style.alignItems = 'center';
                        else el.style.justifyContent = 'center';
                    } else if (g === 'bottom') {
                        if (isCol) el.style.justifyContent = 'flex-end';
                        else el.style.alignItems = 'flex-end';
                    } else if (g === 'right' || g === 'end') {
                        if (isCol) el.style.alignItems = 'flex-end';
                        else el.style.justifyContent = 'flex-end';
                    }
                });
            } else if (name === 'android:layout_gravity') {
                if (val === 'center') {
                    el.style.margin = 'auto';
                } else if (val.includes('center_horizontal')) {
                    el.style.alignSelf = 'center';
                } else if (val.includes('center_vertical')) {
                    el.style.marginTop = 'auto';
                    el.style.marginBottom = 'auto';
                } else if (val.includes('right') || val.includes('end')) {
                    el.style.alignSelf = 'flex-end';
                }
            } else if (name === 'android:tint') {
                el.style.color = this.parseAndroidColor(val);
            } else if (name === 'android:cornerRadius' || name === 'tools:cornerRadius') {
                el.style.borderRadius = pxVal;
            } else if (name === 'tools:boxShadow') {
                el.style.boxShadow = val;
            }
        }
        
        // Resolve Flex-Basis 0 for weighted elements
        if (hasWeight) {
            if (el.style.width === '0px' || el.style.width === '0dp') el.style.flexBasis = '0px';
            if (el.style.height === '0px' || el.style.height === '0dp') el.style.flexBasis = '0px';
        }
        
        // Process Children Recursively
        for (let i = 0; i < node.children.length; i++) {
            const childHtml = this.convertXmlNode(node.children[i]);
            if (childHtml) el.appendChild(childHtml);
        }
        
        return el;
    },

    parseAndroidColor(val) {
        if (val.startsWith('#')) {
            if (val.length === 9) { // #AARRGGBB
                const a = parseInt(val.substring(1, 3), 16) / 255;
                const r = parseInt(val.substring(3, 5), 16);
                const g = parseInt(val.substring(5, 7), 16);
                const b = parseInt(val.substring(7, 9), 16);
                return `rgba(${r}, ${g}, ${b}, ${a})`;
            }
            return val;
        }
        return val;
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());