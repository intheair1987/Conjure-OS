/**
 * WHITEBOARD MEDIA VAULT MODULE
 * Handles global asset management, PDF/Docx conversion, and Magic Canvas creation.
 */

window.wbCurrentVaultPath = '';
window.wbVaultSelectionMode = false;
window.wbVaultSelectedHashes = new Set();
window.wbVaultSelectedFiles = []; // Store full file objects for Magic Canvas
window.wbVaultSelectedFolders = new Set();
window.wbVaultLastData = null; // Cache for the current folder's file list
window.wbVaultLastLpTime = 0;

function wbStartVaultLongPress(e, assetOrFolder, el, type = 'asset') {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    if (window.wbVaultSelectionMode) return;

    el.classList.add('wb-pressing');
    const startPos = { x: e.clientX, y: e.clientY };
    let triggered = false;

    const timer = setTimeout(() => {
        triggered = true;
        if (window.navigator.vibrate) navigator.vibrate(15);

        window.wbVaultSelectionMode = true;
        if (type === 'folder') {
            window.wbVaultSelectedFolders.add(assetOrFolder);
        } else {
            window.wbVaultSelectedHashes.add(assetOrFolder.hash);
            window.wbVaultSelectedFiles.push(assetOrFolder);
        }
        
        wbUpdateVaultSelectionBar();
        wbOpenMediaVault(window.wbCurrentVaultPath, false);
    }, 600);

    const cleanup = () => {
        clearTimeout(timer);
        if (el) el.classList.remove('wb-pressing');
        // If the long-press triggered, record the time of release to suppress the ghost click
        if (triggered) window.wbVaultLastLpTime = Date.now();
        
        window.removeEventListener('pointerup', cleanup);
        window.removeEventListener('pointercancel', cleanup);
        window.removeEventListener('pointermove', move);
    };

    const move = (me) => {
        if (Math.hypot(me.clientX - startPos.x, me.clientY - startPos.y) > 10) cleanup();
    };

    window.addEventListener('pointerup', cleanup);
    window.addEventListener('pointercancel', cleanup);
    window.addEventListener('pointermove', move);
}

function wbToggleVaultSelectionMode() {
    window.wbVaultSelectionMode = !window.wbVaultSelectionMode;
    window.wbVaultSelectedHashes.clear();
    window.wbVaultSelectedFiles = [];
    window.wbVaultSelectedFolders.clear();
    window.wbVaultLastLpTime = 0;
    wbUpdateVaultSelectionBar();
    // Call with forceFetch = false to use the memory cache for instant transition
    wbOpenMediaVault(window.wbCurrentVaultPath, false);
}

function wbFormatBytes(bytes, decimals = 1) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

// Replaced by unified function below

let _pendingMagicFiles = [];

window.processMagicFolder = async function(path) {
    const pill = document.getElementById('status-pill');
    pill.innerText = "Scanning Folder...";
    pill.style.opacity = "1";
    
    try {
    const fd = new FormData();
    fd.append('action', 'list_library');
    fd.append('path', path);
    const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 8000);
    const data = await res.json();if (data.status === 'success' && data.files.length > 0) {
            _pendingMagicFiles = data.files;
            
            // Check if we have documents that need conversion
            const hasDocs = data.files.some(f => {
                const ext = f.name.toLowerCase().split('.').pop();
                return ext === 'pdf' || ext === 'docx';
            });

            if (hasDocs) {
                pill.style.opacity = "0";
                document.getElementById('pdf-options-overlay').style.display = 'flex';
            } else {
                // Only images, proceed to standard loading
                await executeMagicImport(4.0);
            }
        } else {
            pill.style.opacity = "0";
        }
    } catch (e) {
        console.warn("Offline: Reconstructing folder contents for Magic Canvas.");
        const snap = await getMetadata('full_snapshot');
        if (snap && snap.assets) {
            const targetPath = path ? path + '/' : '';
            const filesMap = new Map();
            snap.assets.forEach(a => {
                try {
                    const meta = (typeof a.data === 'string') ? JSON.parse(a.data) : a.data;
                    if (meta && meta.path && meta.path.startsWith(targetPath)) {
                        const remainder = meta.path.substring(targetPath.length);
                        if (!remainder.includes('/')) {
                            const existing = filesMap.get(remainder);
                            if (!existing || a.created_at > existing.mtime) {
                                filesMap.set(remainder, {
                                    name: remainder,
                                    hash: a.hash,
                                    size: 0,
                                    mtime: a.created_at || 0
                                });
                            }
                        }
                    }
                } catch(err) {}
            });
            const files = Array.from(filesMap.values()).sort((a, b) => a.name.localeCompare(b.name));
            if (files.length > 0) {
                _pendingMagicFiles = files;
                const hasDocs = files.some(f => {
                    const ext = f.name.toLowerCase().split('.').pop();
                    return ext === 'pdf' || ext === 'docx';
                });
                if (hasDocs) {
                    pill.style.opacity = "0";
                    document.getElementById('pdf-options-overlay').style.display = 'flex';
                } else {
                    await executeMagicImport(2.0);
                }
                return;
            }
        }
        pill.style.opacity = "0";
        window.wbui.alert("No valid content found in this folder.", "Magic Canvas", "⚠️");
    }
};

async function executeMagicImport(quality) {
    const pill = document.getElementById('status-pill');
    pill.style.opacity = "1";
    
    // Clear the canvas to ensure magic import starts fresh
    allStrokes = [];
    render();
    
    wbStagingImages =[];

    let processed = 0;
    for (const file of _pendingMagicFiles) {
        processed++;
        const ext = file.name.toLowerCase().split('.').pop();
        const hash = file.hash;

        if (ext === 'pdf' || ext === 'docx') {
    pill.innerText = `Parsing ${file.name}...`;
    try {
        let sourceData = await getLocalAsset(hash);
        let arrayBuffer;
        if (sourceData) {
            const binary = atob(sourceData.split(',')[1]);
            const array = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) array[i] = binary.charCodeAt(i);
            arrayBuffer = array.buffer;
        } else {
    const fd = new FormData();
    fd.append('action', 'get_asset');
    fd.append('hash', hash);
    const assetRes = await fetch('index.php', { method: 'POST', body: fd });
    const assetData = await assetRes.json();
    if (!assetData.url) throw new Error("Asset not found");
                
    const response = await fetch(assetData.url);
    arrayBuffer = await response.arrayBuffer();
}const blob = new Blob([arrayBuffer]);
                    
        if (ext === 'pdf') await processPdfSource(blob, hash, quality);
        else await processDocxSource(blob, hash, quality);
    } catch (err) { console.error("Conversion failed for " + file.name, err); }
} else {pill.innerText = `Loading ${processed}/${_pendingMagicFiles.length}...`;
            await fetchAsset(hash);
            const img = wbImageCache[hash];
            if (img && img.naturalWidth) {
                wbStagingImages.push({
                    type: 'image',
                    assetId: hash,
                    data: '',
                    w: img.naturalWidth,
                    h: img.naturalHeight
                });
            }
        }
    }

    pill.style.opacity = "0";
    if (wbStagingImages.length > 0) {
        showLayoutPicker();
    } else {
        window.wbui.alert("No valid content found in this folder.", "Magic Canvas", "⚠️");
    }
    _pendingMagicFiles =[];
}

window.triggerImageImport = function() {
    document.getElementById('image-import-input').click();
};

let wbStagingImages = [];
let wbImportLayoutMode = 'vertical';

window.updatePdfQualityDisplay = function(val) {
    const label = document.getElementById('pdf-quality-label');
    const desc = document.getElementById('pdf-quality-desc');
    label.innerText = parseFloat(val).toFixed(1) + 'x';
    
    if (val <= 1) desc.innerText = "Draft (Fast)";
    else if (val <= 2) desc.innerText = "Standard HD";
    else if (val <= 3) desc.innerText = "High Res (Zoom)";
    else desc.innerText = "Ultra (Projection/4K)";
};

async function processDocxSource(file, hash, qualityScale = 2.0) {
    const arrayBuffer = await file.arrayBuffer();
    const pill = document.getElementById('status-pill');
    pill.innerText = "Parsing DOCX...";

    const container = document.createElement('div');
    container.style.cssText = "position:absolute; left:-9999px; top:0; width:816px; background:white;";
    document.body.appendChild(container);

    try {
        await docx.renderAsync(arrayBuffer, container, null, {
            inWrapper: false, ignoreWidth: false, ignoreHeight: false, debug: false
        });

        const sections = container.querySelectorAll('section.docx');
        const numPages = sections.length;
        if (numPages === 0) throw new Error("No pages rendered");

        for (let i = 0; i < numPages; i++) {
            const section = sections[i];
            const targetW = section.offsetWidth * qualityScale;
            const targetH = section.offsetHeight * qualityScale;

            wbStagingImages.push({
                type: 'docx_page',
                assetId: hash,
                page: i + 1,
                quality: qualityScale,
                w: targetW,
                h: targetH
            });
        }
    } catch (err) {
        console.error("DOCX Import Failed:", err);
        alert("Failed to process Word document.");
    } finally {
        document.body.removeChild(container);
    }
}

async function processPdfSource(file, hash, qualityScale = 2.0) {
    const arrayBuffer = await file.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
    const pill = document.getElementById('status-pill');

    for (let i = 1; i <= pdf.numPages; i++) {
        pill.innerText = `Parsing PDF Page ${i}/${pdf.numPages}...`;
        const page = await pdf.getPage(i);
        const viewport = page.getViewport({ scale: qualityScale }); 

        const maxDim = qualityScale * 1024;
        let targetW = viewport.width;
        let targetH = viewport.height;
        if (targetW > maxDim || targetH > maxDim) {
            const ratio = Math.min(maxDim / targetW, maxDim / targetH);
            targetW *= ratio; targetH *= ratio;
        }

        wbStagingImages.push({
            type: 'pdf_page',
            assetId: hash,
            page: i,
            quality: qualityScale,
            w: targetW,
            h: targetH
        });
    }
}

let _pendingUploadFiles = [];

window.handleImageUpload = async function(e) {
    const files = Array.from(e.target.files);
    if (files.length === 0) return;
    
    _pendingUploadFiles = files;
    const hasPdf = files.some(f => f.type === 'application/pdf' || f.name.toLowerCase().endsWith('.pdf'));

    if (hasPdf) {
        document.getElementById('pdf-options-overlay').style.display = 'flex';
    } else {
        confirmPdfOptions(); // Proceed immediately for images
    }
    e.target.value = '';
};

window.cancelPdfOptions = function() {
    document.getElementById('pdf-options-overlay').style.display = 'none';
    _pendingUploadFiles = [];
};

window.confirmPdfOptions = async function() {
    const quality = parseFloat(document.getElementById('pdf-quality-slider').value);
    document.getElementById('pdf-options-overlay').style.display = 'none';

    // If we are in the Magic Folder flow
    if (_pendingMagicFiles.length > 0) {
        await executeMagicImport(quality);
        return;
    }

    const pill = document.getElementById('status-pill');
    pill.innerText = `Processing...`;
    pill.style.opacity = "1";

    wbStagingImages =[];

    for (const file of _pendingUploadFiles) {
        const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
        const isDocx = file.name.toLowerCase().endsWith('.docx');

        const fileDataUrl = await new Promise(r => {
            const reader = new FileReader();
            reader.onload = () => r(reader.result);
            reader.readAsDataURL(file);
        });

        const hash = await wbHash(fileDataUrl);
        await ensureAssetSynced(hash, fileDataUrl);

        if (isPdf) {
            await processPdfSource(file, hash, quality);
        } else if (isDocx) {
            await processDocxSource(file, hash, quality);
        } else {
            await new Promise((resolve) => {
                const img = new Image();
                img.onload = () => {
                    const maxDim = 1200;
                    let targetW = img.width;
                    let targetH = img.height;
                    if (targetW > maxDim || targetH > maxDim) {
                        const ratio = Math.min(maxDim / targetW, maxDim / targetH);
                        targetW *= ratio; targetH *= ratio;
                    }
                    wbStagingImages.push({
                        type: 'image',
                        assetId: hash,
                        data: '',
                        w: targetW,
                        h: targetH
                    });
                    resolve();
                };
                img.src = fileDataUrl;
            });
        }
    }

    pill.style.opacity = "0";
    
    if (wbStagingImages.length === 1) {
        placeStagedAssetOnCanvas(wbStagingImages[0]);
    } else {
        showLayoutPicker();
    }
    
    const fileInput = document.getElementById('image-import-input');
    if (fileInput) fileInput.value = '';
};

window.wbCreateAssetFolder = async function() {
    if (navigator.onLine === false) {
        window.wbui.alert("You cannot create folders while offline.", "Offline Mode", "📶");
        return;
    }
    const currentLoc = window.wbCurrentVaultPath || 'Assets (Root)';
    const name = await window.wbui.input(`Creating new folder inside:\n${currentLoc}`, "New Folder", "Untitled_Folder", wbIcons.folder);
    if (!name) return;
    
    try {
        const fd = new FormData();
        fd.append('action', 'create_asset_folder');
        fd.append('path', window.wbCurrentVaultPath || '');
        fd.append('name', name.replace(/\s+/g, '_')); // Sanitize spaces
        
        await fetch('index.php', { method: 'POST', body: fd });
        window.wbVaultLastData = null; // Clear cache
        wbOpenMediaVault(window.wbCurrentVaultPath);
    } catch(e) {
        window.wbui.alert("Failed to connect to server.", "Connection Error", "📶");
    }
};

async function wbOpenMediaVault(path = '', forceFetch = true) {
    const overlay = document.getElementById('media-vault-overlay');
    const grid = document.getElementById('media-vault-grid');
    const isGallery = document.getElementById('gallery-view').style.display === 'flex';
    
    overlay.style.display = 'flex';
    
    // Only show "Scanning" and clear grid if we are actually fetching new data
    if (forceFetch || !window.wbVaultLastData || window.wbCurrentVaultPath !== path) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--text-secondary);">Scanning vault...</div>';
        window.wbVaultLastData = null; 
    }

    window.wbCurrentVaultPath = path;

    try {
        let data = window.wbVaultLastData;

        if (forceFetch || !data) {
            try {
                const fd = new FormData();
                fd.append('action', 'list_library');
                fd.append('path', path);
                const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 8000);
                data = await res.json();
            } catch (e) {
                console.warn("Offline: Reconstructing Vault from local cache.");
                const snap = await getMetadata('full_snapshot');
                if (!snap) throw new Error("No offline cache available.");
                
                const folders = new Set();
                const filesMap = new Map(); // Use Map to deduplicate by filename
                const targetPath = path ? path + '/' : '';
                
                (snap.assets ||[]).forEach(a => {
                    let p = '';
                    try {
                        // Support both stringified JSON and pre-parsed objects from the snapshot
                        const meta = (typeof a.data === 'string') ? JSON.parse(a.data) : a.data;
                        if (meta && meta.path) p = meta.path;
                        else return; 
                    } catch(err) { return; }
                    
                    if (p.startsWith(targetPath)) {
                        const remainder = p.substring(targetPath.length);
                        if (remainder.includes('/')) {
                            folders.add(remainder.split('/')[0]);
                        } else {
                            // Only keep the newest version of this filename
                            const existing = filesMap.get(remainder);
                            if (!existing || a.created_at > existing.mtime) {
                                filesMap.set(remainder, {
                                    name: remainder,
                                    hash: a.hash,
                                    size: 0,
                                    mtime: a.created_at || 0
                                });
                            }
                        }
                    }
                });
                
                const files = Array.from(filesMap.values());
                
                data = {
                    status: 'success',
                    folders: Array.from(folders).sort((a, b) => a.localeCompare(b)),
                    files: files.sort((a, b) => a.name.localeCompare(b.name)),
                    current_path: path
                };
            }
            window.wbVaultLastData = data; // Update cache
        }

        if (data.status === 'success') {
            const header = document.querySelector('#media-vault-overlay .sync-header');
            const selectionBar = document.getElementById('vault-selection-bar') || (() => {
                const bar = document.createElement('div');
                bar.id = 'vault-selection-bar';
                bar.className = 'vault-selection-bar';
                grid.parentElement.appendChild(bar);
                return bar;
            })();

            let breadcrumbs = `<span style="cursor:pointer;" onclick="wbOpenMediaVault('')">Assets</span>`;
            if (path) {
                const parts = path.split('/');
                let cur = '';
                parts.forEach(p => {
                    if(!p) return;
                    cur += (cur ? '/' : '') + p;
                    breadcrumbs += ` / <span style="cursor:pointer;" onclick="wbOpenMediaVault('${cur}')">${p}</span>`;
                });
            }
            
            const upPath = path.includes('/') ? path.substring(0, path.lastIndexOf('/')) : '';
            const magicBtn = path && data.files.length > 0 ? `<button class="tool-btn" onclick="wbCreateMagicCanvas('${path}')" style="padding:4px 12px; font-size:11px; background:var(--primary-accent); color:white; border:none; box-shadow:0 2px 8px rgba(88, 86, 214, 0.3); flex-shrink:0; white-space:nowrap;">✨ Magic Canvas</button>` : '';
            
            header.innerHTML = `
                <div class="vault-header-container">
                    <h3 style="margin:0; font-size:18px; display:flex; align-items:center; gap:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; width:100%;">
                        ${path ? `<span onclick="wbOpenMediaVault('${upPath}')" style="cursor:pointer; display:flex; align-items:center; opacity:0.7; flex-shrink:0;"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="3"><path d="M15 18l-6-6 6-6"/></svg></span>` : ''}
                        <div style="overflow:hidden; text-overflow:ellipsis; flex:1;">${breadcrumbs}</div>
                    </h3>
                    <div class="vault-actions">
                        <button class="tool-btn ${window.wbVaultSelectionMode ? 'active' : ''}" onclick="wbToggleVaultSelectionMode()" style="padding:4px 12px; font-size:11px;">
                            ${window.wbVaultSelectionMode ? 'Cancel' : 'Select'}
                        </button>
                        ${magicBtn}
                        <button class="tool-btn" onclick="wbCreateAssetFolder()" style="padding:4px 12px; font-size:11px;">+ Folder</button>
                        <button class="tool-btn" onclick="wbOpenMediaVault('${path}')" style="padding:4px 12px; font-size:11px;">↻ Scan</button>
                    </div>
                </div>
            `;

            grid.innerHTML = '';
            if (data.folders.length === 0 && data.files.length === 0) {
                grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--text-secondary);">Folder is empty.</div>';
                return;
            }

            data.folders.forEach(f => {
                const isSelected = window.wbVaultSelectedFolders.has(f);
                const card = document.createElement('div');
                card.className = 'folder-card' + (isSelected ? ' selecting' : '');
                card.style.aspectRatio = '1';
                card.innerHTML = `
                    <div class="vault-select-check">
                        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="4" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </div>
                    <div class="folder-card-thumb" style="height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; background:rgba(0,0,0,0.02);">
                        <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="1.5" fill="none"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                        <div style="font-size:11px; font-weight:700; margin-top:8px; width:90%; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${f}</div>
                    </div>
                `;
                
                card.onpointerdown = (e) => wbStartVaultLongPress(e, f, card, 'folder');

                card.onclick = () => {
                    if (Date.now() - (window.wbVaultLastLpTime || 0) < 400) return;
                    if (window.wbVaultSelectionMode) {
                        if (window.wbVaultSelectedFolders.has(f)) {
                            window.wbVaultSelectedFolders.delete(f);
                            card.classList.remove('selecting');
                        } else {
                            window.wbVaultSelectedFolders.add(f);
                            card.classList.add('selecting');
                        }
                        wbUpdateVaultSelectionBar();
                    } else {
                        wbOpenMediaVault((path ? path + '/' : '') + f);
                    }
                };
                grid.appendChild(card);
            });

            if (window.wbVaultSelectionMode) grid.classList.add('vault-selection-active');
            else grid.classList.remove('vault-selection-active');

            data.files.forEach(asset => {
                const ext = asset.name.split('.').pop().toLowerCase();
                const dateStr = new Date(asset.mtime * 1000).toLocaleDateString();
                const sizeStr = wbFormatBytes(asset.size);
                const isSelected = window.wbVaultSelectedHashes.has(asset.hash);
                
                const card = document.createElement('div');
                card.className = 'canvas-card vault-asset-card' + (isSelected ? ' selecting' : '');
                
                // Attach Long Press Listeners
                card.onpointerdown = (e) => wbStartVaultLongPress(e, asset, card);

                const actionBtn = (!isGallery && !window.wbVaultSelectionMode) ? `
                    <div class="canvas-card-actions" style="opacity:1; background:var(--primary-accent); color:white; width:auto; height:auto; padding:4px 8px; border-radius:8px; font-size:10px; font-weight:800; top:auto; bottom:8px; right:8px;">
                        USE
                    </div>` : '';

                card.innerHTML = `
                    <div class="vault-select-check">
                        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="4" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </div>
                    <div id="vault-thumb-${asset.hash}" class="canvas-card-thumb" style="background-size:contain; background-repeat:no-repeat; background-position:center;">
                        <div class="vault-placeholder-icon">
                            ${ext === 'pdf' ? '<svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M9 15h3a2 2 0 0 0 0-4H9v9"></path></svg>' : 
                              ext === 'docx' ? '<svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M9 12h6"></path><path d="M9 16h6"></path></svg>' :
                              '<svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>'}
                        </div>
                    </div>
                    <div class="vault-info">
                        <div class="vault-filename" title="${asset.name}">${asset.name}</div>
                        <div class="vault-meta-row">
                            <span class="vault-badge">${ext.toUpperCase()}</span>
                            <span id="vault-pages-${asset.hash}"></span>
                            <span>${sizeStr}</span>
                            <span>${dateStr}</span>
                        </div>
                    </div>

                    ${actionBtn}
                `;
                
                card.onclick = () => {
                    if (Date.now() - (window.wbVaultLastLpTime || 0) < 400) return;

                    if (window.wbVaultSelectionMode) {
                        if (window.wbVaultSelectedHashes.has(asset.hash)) {
                            window.wbVaultSelectedHashes.delete(asset.hash);
                            window.wbVaultSelectedFiles = window.wbVaultSelectedFiles.filter(f => f.hash !== asset.hash);
                            card.classList.remove('selecting');
                        } else {
                            window.wbVaultSelectedHashes.add(asset.hash);
                            window.wbVaultSelectedFiles.push(asset);
                            card.classList.add('selecting');
                        }
                        wbUpdateVaultSelectionBar();
                    } else {
                        wbOpenAssetPreview(asset, isGallery);
                    }
                };
                
                grid.appendChild(card);
                wbLoadVaultThumbnail(asset.hash, ext);
            });
        }
    } catch (e) {
        grid.innerHTML = '<div style="grid-column:1/-1; color:red; text-align:center;">Failed to load vault.</div>';
    }
}

function wbUpdateVaultSelectionBar() {
    const bar = document.getElementById('vault-selection-bar');
    if (!bar) return;
    const count = window.wbVaultSelectedHashes.size + window.wbVaultSelectedFolders.size;
    
    if (count > 0) {
        const hasPdfs = window.wbVaultSelectedFiles.some(f => f.name.toLowerCase().endsWith('.pdf'));
        const hasFolders = window.wbVaultSelectedFolders.size > 0;
        bar.classList.add('active');
        bar.innerHTML = `
            <div style="font-size:13px; font-weight:800;">${count} Selected</div>
            <div style="display:flex; gap:8px;">
                ${hasPdfs && !hasFolders ? `<button class="tool-btn" onclick="wbVaultCombinePdfs()" style="background:#34c759; color:white; border:none; padding:6px 12px; font-size:11px;">Combine PDFs</button>` : ''}
                ${!hasFolders ? `<button class="tool-btn" onclick="wbVaultMagicFromSelection()" style="background:var(--primary-accent); color:white; border:none; padding:6px 12px; font-size:11px;">✨ Magic Canvas</button>` : ''}
                <button class="tool-btn" onclick="wbVaultBulkMove()" style="padding:6px 12px; font-size:11px;">Move</button>
                <button class="tool-btn" onclick="wbVaultBulkDelete()" style="color:#ff3b30; border-color:rgba(255,59,48,0.2); padding:6px 12px; font-size:11px;">Delete</button>
            </div>
        `;
    } else {
        bar.classList.remove('active');
    }
}

async function wbVaultCombinePdfs() {
    const selected = window.wbVaultSelectedFiles.filter(f => f.name.toLowerCase().endsWith('.pdf'));
    if (selected.length < 2) {
        alert("Please select at least 2 PDF files to combine.");
        return;
    }

    const defaultName = "Combined_" + new Date().toISOString().slice(0,10) + ".pdf";
    const fileName = await window.wbui.input("Enter name for merged PDF", "Combine PDFs", defaultName, "📄");
    if (!fileName) return;

    const overlay = document.getElementById('export-progress-overlay');
    const msg = document.getElementById('export-progress-msg');
    const bar = document.getElementById('export-progress-bar');
    const pct = document.getElementById('export-progress-pct');
    
    const updateProgress = (p, text) => {
        msg.innerText = text;
        bar.style.width = p + '%';
        pct.innerText = Math.round(p) + '%';
    };

    overlay.style.display = 'flex';
    updateProgress(5, "Initializing PDF engine...");

    try {
        const mergedPdf = await PDFLib.PDFDocument.create();
        
        for (let i = 0; i < selected.length; i++) {
            const asset = selected[i];
            const progress = 10 + ((i / selected.length) * 80);
            updateProgress(progress, `Merging: ${asset.name}...`);

            // Fetch the raw PDF bytes from the vault (or local DB)
            let sourceData = await getLocalAsset(asset.hash);
            let arrayBuffer;
            if (sourceData) {
                const binary = atob(sourceData.split(',')[1]);
                const array = new Uint8Array(binary.length);
                for (let j = 0; j < binary.length; j++) array[j] = binary.charCodeAt(j);
                arrayBuffer = array.buffer;
            } else {
                const fd = new FormData();
                fd.append('action', 'get_asset');
                fd.append('hash', asset.hash);
                const assetRes = await fetch('index.php', { method: 'POST', body: fd });
                const assetData = await assetRes.json();
                if (!assetData.url) throw new Error("Asset not found");
                
                const response = await fetch(assetData.url);
                arrayBuffer = await response.arrayBuffer();
            }
            
            // Load the PDF and copy all pages
            const donorPdf = await PDFLib.PDFDocument.load(arrayBuffer);
            const pages = await mergedPdf.copyPages(donorPdf, donorPdf.getPageIndices());
            pages.forEach(page => mergedPdf.addPage(page));
        }

        updateProgress(90, "Generating final file...");
        const pdfBytes = await mergedPdf.save();
        
        // Trigger Download
        const blob = new Blob([pdfBytes], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = fileName.endsWith('.pdf') ? fileName : fileName + '.pdf';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);

        updateProgress(100, "Done!");
        setTimeout(() => { overlay.style.display = 'none'; }, 1000);
        wbToggleVaultSelectionMode(); // Exit selection mode
    } catch (e) {
        console.error(e);
        overlay.style.display = 'none';
        alert("Error combining PDFs: " + e.message);
    }
}

async function wbVaultBulkDelete() {
    const fileCount = window.wbVaultSelectedHashes.size;
    const folderCount = window.wbVaultSelectedFolders.size;
    if (!await wbui.confirm(`Delete ${fileCount} assets and ${folderCount} folders permanently?\n\nWARNING: If any of these are actively used in canvases, their links will break.`, "Bulk Delete", wbIcons.trash)) return;
    
    const hashes = Array.from(window.wbVaultSelectedHashes);
    for (const hash of hashes) {
        const fd = new FormData();
        fd.append('action', 'delete_asset');
        fd.append('hash', hash);
        await fetch('index.php', { method: 'POST', body: fd });
        await deleteLocalAsset(hash);
        delete wbImageCache[hash];
    }

    const folders = Array.from(window.wbVaultSelectedFolders);
    for (const folder of folders) {
        const fd = new FormData();
        fd.append('action', 'delete_asset_folder');
        fd.append('path', (window.wbCurrentVaultPath ? window.wbCurrentVaultPath + '/' : '') + folder);
        await fetch('index.php', { method: 'POST', body: fd });
    }
    
    window.wbVaultLastData = null;
    wbToggleVaultSelectionMode();
}

window.wbPickerPath = '';

async function wbOpenFolderPicker(startPath = '') {
    const overlay = document.getElementById('folder-picker-overlay');
    overlay.style.display = 'flex';
    wbNavigatePicker(startPath);
}

function wbCloseFolderPicker() {
    document.getElementById('folder-picker-overlay').style.display = 'none';
}

async function wbNavigatePicker(path) {
    window.wbPickerPath = path;
    const list = document.getElementById('picker-list');
    const bread = document.getElementById('picker-breadcrumb');
    const moveBtn = document.getElementById('picker-move-btn');
    
    bread.innerText = 'Assets' + (path ? ' / ' + path.replace(/\//g, ' / ') : '');
    list.innerHTML = '<div style="text-align:center; padding:20px; opacity:0.5;">Loading...</div>';

    const fd = new FormData();
    fd.append('action', 'list_library');
    fd.append('path', path);
    const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 3000);
    const data = await res.json();

    list.innerHTML = '';
    
    // 1. Back button if not at root
    if (path) {
        const up = path.includes('/') ? path.substring(0, path.lastIndexOf('/')) : '';
        const back = document.createElement('div');
        back.className = 'picker-item';
        back.innerHTML = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="3"><path d="M15 18l-6-6 6-6"/></svg> <b>Back</b>`;
        back.onclick = () => wbNavigatePicker(up);
        list.appendChild(back);
    }

    // 2. Folder list
    data.folders.forEach(f => {
        const item = document.createElement('div');
        item.className = 'picker-item';
        item.innerHTML = `${wbIcons.folder} <span>${f}</span>`;
        item.onclick = () => wbNavigatePicker((path ? path + '/' : '') + f);
        list.appendChild(item);
    });

    if (data.folders.length === 0 && !path) {
        list.innerHTML = '<div style="text-align:center; padding:20px; opacity:0.5;">No subfolders found.</div>';
    }

    // 3. Move button logic
    moveBtn.onclick = () => wbExecuteBulkMove(path);
    // Disable move button if we are already in the current folder
    moveBtn.disabled = (path === window.wbCurrentVaultPath);
    moveBtn.style.opacity = moveBtn.disabled ? "0.3" : "1";
}

async function wbExecuteBulkMove(targetPath) {
    const hashes = Array.from(window.wbVaultSelectedHashes);
    const folders = Array.from(window.wbVaultSelectedFolders);
    wbCloseFolderPicker();
    
    const pill = document.getElementById('status-pill');
    pill.innerText = `Moving ${hashes.length + folders.length} items...`;
    pill.style.opacity = "1";

    for (const hash of hashes) {
        const fd = new FormData();
        fd.append('action', 'move_asset');
        fd.append('hash', hash);
        fd.append('target_folder', targetPath);
        await fetch('index.php', { method: 'POST', body: fd });
    }

    for (const folder of folders) {
        const fd = new FormData();
        fd.append('action', 'move_asset_folder');
        fd.append('path', (window.wbCurrentVaultPath ? window.wbCurrentVaultPath + '/' : '') + folder);
        fd.append('target_folder', targetPath);
        await fetch('index.php', { method: 'POST', body: fd });
    }

    pill.innerText = "Moved Successfully";
    window.wbVaultLastData = null; // Clear cache to force refresh
    wbToggleVaultSelectionMode(); // Exit selection mode and refresh
    setTimeout(() => pill.style.opacity = "0", 2000);
}

async function wbVaultBulkMove() {
    wbOpenFolderPicker(window.wbCurrentVaultPath);
}

async function wbVaultMagicFromSelection() {
    let suggestedName = "Selected Assets";
    if (window.wbCurrentVaultPath) {
        const parts = window.wbCurrentVaultPath.split('/').filter(p => p);
        const current = parts.pop();
        const parent = parts.pop();
        suggestedName = parent ? `${parent} - ${current}` : current;
    }

    const name = await window.wbui.input("Name your new magic canvas", "Magic Canvas", suggestedName, "✨");
    if (!name) return;
    
    document.getElementById('media-vault-overlay').style.display = 'none';
    const pill = document.getElementById('status-pill');
    pill.innerText = "Creating...";
    pill.style.opacity = "1";
    
    let realId = null;
    if (navigator.onLine !== false) {
        try {
            const fd = new FormData();
            fd.append('action', 'create_canvas');
            fd.append('name', name);
            fd.append('folder_id', typeof wbCurrentFolderId !== 'undefined' ? wbCurrentFolderId : 0);
            
            const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 3000);
            const data = await res.json();
            if (data.status === 'success') realId = data.id;
        } catch (e) {
            console.warn("Server unreachable, falling back to offline creation.");
        }
    }

    if (!realId) {
        realId = 'local_' + Date.now();
        const snap = await getMetadata('full_snapshot');
        if (snap) {
            if (!snap.canvases) snap.canvases = [];
            snap.canvases.unshift({
                id: realId,
                name: name,
                folder_id: typeof wbCurrentFolderId !== 'undefined' ? wbCurrentFolderId : 0,
                thumbnail: '',
                created_at: Math.floor(Date.now()/1000),
                updated_at: Math.floor(Date.now()/1000)
            });
            await saveMetadata('full_snapshot', snap);
        }
        await saveLocalDocument('canvas_' + realId, '[]', true, 0, '');
    }

    // Persist selection to sessionStorage because the redirect will wipe JS memory
    sessionStorage.setItem('wb_pending_magic', JSON.stringify(window.wbVaultSelectedFiles));
    
    // Redirect and trigger the import
    window.location.href = '?canvas=' + realId + '&magic_trigger=selection';
}

// Update DOMContentLoaded to handle the new magic_trigger
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const magicTrigger = urlParams.get('magic_trigger');
    if (magicTrigger === 'selection') {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?canvas=' + window.currentCanvasId;
        window.history.replaceState({path: cleanUrl}, '', cleanUrl);
        
        // Restore selection from sessionStorage
        const stored = sessionStorage.getItem('wb_pending_magic');
        if (stored) {
            _pendingMagicFiles = JSON.parse(stored);
            sessionStorage.removeItem('wb_pending_magic'); // Clean up immediately
        }

        setTimeout(async () => {
            if (_pendingMagicFiles.length === 0) {
                console.error("Magic Selection: No files found in sessionStorage.");
                return;
            }
            // Check for documents in the selection
            const hasDocs = _pendingMagicFiles.some(f => {
                const ext = f.name.toLowerCase().split('.').pop();
                return ext === 'pdf' || ext === 'docx';
            });

            if (hasDocs) {
                document.getElementById('pdf-options-overlay').style.display = 'flex';
            } else {
                await executeMagicImport(4.0);
            }
        }, 800);
    }
});

async function wbOpenAssetPreview(asset, isGalleryMode) {
    const overlay = document.getElementById('asset-preview-overlay');
    const title = document.getElementById('preview-title');
    const content = document.getElementById('preview-content-area');
    const footer = document.getElementById('preview-footer');
    const ext = asset.name.split('.').pop().toLowerCase();

    title.innerText = asset.name;
    content.innerHTML = '<div class="preview-placeholder">Loading...</div>';
    overlay.style.display = 'flex';

    // 1. Load Preview Content
    if (ext === 'pdf') {
        try {
            let sourceData = await getLocalAsset(asset.hash);
            let arrayBuffer;
            if (sourceData) {
                const binary = atob(sourceData.split(',')[1]);
                const array = new Uint8Array(binary.length);
                for (let i = 0; i < binary.length; i++) array[i] = binary.charCodeAt(i);
                arrayBuffer = array.buffer;
            } else {
                const fd = new FormData();
                fd.append('action', 'get_asset');
                fd.append('hash', asset.hash);
                const assetRes = await fetch('index.php', { method: 'POST', body: fd });
                const assetData = await assetRes.json();
                if (!assetData.url) throw new Error("Asset not found");
                
                const response = await fetch(assetData.url);
                arrayBuffer = await response.arrayBuffer();
            }
            const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
            const page = await pdf.getPage(1);
            const viewport = page.getViewport({ scale: 0.8 });
            const canvas = document.createElement('canvas');
            canvas.className = 'preview-img';
            canvas.height = viewport.height;
            canvas.width = viewport.width;
            await page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
            content.innerHTML = '';
            content.appendChild(canvas);
        } catch (e) { content.innerHTML = '<div class="preview-placeholder">PDF Preview Unavailable</div>'; }
    } else if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) {
        await fetchAsset(asset.hash);
        const img = wbImageCache[asset.hash];
        if (img) content.innerHTML = `<img src="${img.src}" class="preview-img">`;
    } else {
        content.innerHTML = `<div class="preview-placeholder">${wbIcons.palette}<br>${ext.toUpperCase()} File</div>`;
    }

    // 2. Load Usage Info
    const usageContainer = document.getElementById('preview-usage-info');
    if (usageContainer) {
        usageContainer.style.display = 'block';
        usageContainer.innerHTML = '<div style="opacity:0.5;">Checking usage...</div>';
        
        const fd = new FormData();
        fd.append('action', 'get_asset_usage');
        fd.append('hash', asset.hash);
        fetch('index.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    if (data.canvases.length > 0) {
                        usageContainer.innerHTML = `<div style="color: var(--primary-accent); font-weight: 700; margin-bottom: 4px;">🔗 Linked to ${data.canvases.length} Canvas(es)</div><div style="opacity:0.8; line-height: 1.4;">${data.canvases.join(', ')}</div>`;
                    } else {
                        usageContainer.innerHTML = `<div style="opacity:0.5; font-weight: 600;">Not currently used in any canvases.</div>`;
                    }
                }
            }).catch(() => {
                usageContainer.innerHTML = `<div style="color:var(--danger); opacity:0.8;">Usage check failed.</div>`;
            });
    }

    // 3. Setup Footer Buttons
    const useLabel = isGalleryMode ? "Open as Canvas" : "Place on Canvas";
    const useAction = isGalleryMode ? `wbCreateMagicCanvas('${(window.wbCurrentVaultPath ? window.wbCurrentVaultPath + '/' : '') + asset.name}', true, '${asset.hash}')` : `wbInsertAssetFromVault('${asset.hash}')`;

    footer.innerHTML = `
        <button class="tool-btn" onclick="wbDownloadAsset('${asset.hash}', '${asset.name}')" style="background:var(--bg-color);">
            Download
        </button>
        <button class="tool-btn" onclick="${useAction}; wbCloseAssetPreview();" style="background:var(--primary-accent); color:white; border:none;">
            ${useLabel}
        </button>
        <button class="tool-btn" onclick="wbDeleteAssetFromPreview('${asset.hash}')" style="grid-column: span 2; color:#ff3b30; border-color:rgba(255,59,48,0.1); margin-top:10px; font-size:11px;">
            Delete Asset Permanently
        </button>
    `;
}

function wbCloseAssetPreview() {
    document.getElementById('asset-preview-overlay').style.display = 'none';
}

async function wbDownloadAsset(hash, filename) {
    const pill = document.getElementById('status-pill');
    pill.innerText = "Preparing Download...";
    pill.style.opacity = "1";

    let data = await getLocalAsset(hash);
    
    if (!data) {
        // Fallback to server if online
        const fd = new FormData();
        fd.append('action', 'get_asset');
        fd.append('hash', hash);
        const assetRes = await fetch('index.php', { method: 'POST', body: fd });
        const assetData = await assetRes.json();
        
        if (assetData.url) {
            const link = document.createElement('a');
            link.href = assetData.url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } else {
            alert("File not found on server.");
        }
    } else {
        // OFFLINE DOWNLOAD: Convert DataURL to Blob
        const parts = data.split(',');
        const mime = parts[0].match(/:(.*?);/)[1];
        const bstr = atob(parts[1]);
        let n = bstr.length;
        const u8arr = new Uint8Array(n);
        while(n--) u8arr[n] = bstr.charCodeAt(n);
        
        const blob = new Blob([u8arr], {type:mime});
        const url = window.URL.createObjectURL(blob);
        
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
    }
    
    pill.style.opacity = "0";
}