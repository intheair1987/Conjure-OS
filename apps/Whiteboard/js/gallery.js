/**
 * WHITEBOARD GALLERY MODULE
 * Handles canvas grid rendering, folder navigation, and bulk management.
 */

let wbCurrentFolderId = 0;
window.wbGallerySelectionMode = false;
window.wbSelectedCanvases = new Set();
window.wbSelectedFolders = new Set();

function wbToggleGallerySelectionMode() {
    window.wbGallerySelectionMode = !window.wbGallerySelectionMode;
    const grid = document.getElementById('gallery-grid');
    
    if (window.wbGallerySelectionMode) {
        grid.classList.add('gallery-selection-active');
    } else {
        grid.classList.remove('gallery-selection-active');
        // Clean up visual selection states from all cards
        grid.querySelectorAll('.selected').forEach(el => el.classList.remove('selected'));
        window.wbSelectedCanvases.clear();
        window.wbSelectedFolders.clear();
    }
    wbUpdateGallerySelectionBar();
}

function wbUpdateGallerySelectionBar() {
    const count = window.wbSelectedCanvases.size + window.wbSelectedFolders.size;

    // Auto-exit mode if nothing is selected
    if (window.wbGallerySelectionMode && count === 0) {
        wbToggleGallerySelectionMode();
        return;
    }

    let bar = document.getElementById('gallery-selection-bar');
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'gallery-selection-bar';
        bar.className = 'gallery-selection-bar';
        document.getElementById('gallery-view').appendChild(bar);
    }
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'gallery-selection-bar';
        bar.className = 'gallery-selection-bar';
        document.getElementById('gallery-view').appendChild(bar);
    }
    
    if (count > 0) {
        bar.classList.add('active');
        bar.innerHTML = `
            <div style="font-size:14px; font-weight:800; color:var(--text-primary);">${count} Selected</div>
            <div style="display:flex; gap:8px;">
                <button class="tool-btn" onclick="wbGallerySelectAll()" style="padding:8px 16px; font-size:12px;">Select All</button>
                <button class="tool-btn" onclick="wbGalleryBulkMove()" style="padding:8px 16px; font-size:12px;">Move</button>
                <button class="tool-btn" onclick="wbGalleryBulkDelete()" style="color:#ff3b30; border-color:rgba(255,59,48,0.2); padding:8px 16px; font-size:12px;">Delete</button>
                <button class="tool-btn" onclick="wbToggleGallerySelectionMode()" style="padding:8px 16px; font-size:12px; background:var(--bg-color);">Cancel</button>
            </div>
        `;
    } else {
        bar.classList.remove('active');
    }
}

async function wbGalleryBulkDelete() {
    const cCount = window.wbSelectedCanvases.size;
    const fCount = window.wbSelectedFolders.size;
    
    // Protection: Check if ID 1 is in the selection
    if (window.wbSelectedCanvases.has(1)) {
        window.wbui.alert("The 'Default Canvas' cannot be deleted.", "Protected Item", "🛡️");
        return;
    }

    if (!await window.wbui.confirm(`Delete ${cCount} canvases and ${fCount} folders permanently?`, "Bulk Delete", wbIcons.trash)) return;
    
    const fd = new FormData();
    fd.append('action', 'bulk_gallery_delete');
    fd.append('canvas_ids', JSON.stringify(Array.from(window.wbSelectedCanvases)));
    fd.append('folder_ids', JSON.stringify(Array.from(window.wbSelectedFolders)));
    
    const pill = document.getElementById('status-pill');
    pill.innerText = "Deleting...";
    pill.style.opacity = "1";

    // 1. Animate items away immediately
    const grid = document.getElementById('gallery-grid');
    const selectedCards = grid.querySelectorAll('.selected');
    selectedCards.forEach(card => card.classList.add('wb-removing'));

    // 2. Perform backend deletion
    await fetch('index.php', { method: 'POST', body: fd });
    
    // 3. Cleanup local cache
    for (const id of window.wbSelectedCanvases) {
        await deleteLocalDocument('canvas_' + id);
    }

    // 4. Finalize UI after animation
    setTimeout(() => {
        wbToggleGallerySelectionMode();
        wbRenderGallery(); // Refresh data silently
        pill.innerText = "Deleted";
    }, 300);
    setTimeout(() => pill.style.opacity = "0", 2000);
}

function wbGallerySelectAll() {
    const grid = document.getElementById('gallery-grid');
    const cards = grid.querySelectorAll('.canvas-card, .folder-card');
    
    cards.forEach(card => {
        const id = parseInt(card.dataset.id);
        const type = card.dataset.type;
        
        if (type === 'canvas') window.wbSelectedCanvases.add(id);
        else window.wbSelectedFolders.add(id);
        
        card.classList.add('selected');
    });
    
    wbUpdateGallerySelectionBar();
}

function wbGallerySelectAll() {
    const grid = document.getElementById('gallery-grid');
    const cards = grid.querySelectorAll('.canvas-card, .folder-card');
    
    cards.forEach(card => {
        const id = parseInt(card.dataset.id);
        const type = card.dataset.type;
        if (type === 'canvas') window.wbSelectedCanvases.add(id);
        else window.wbSelectedFolders.add(id);
        card.classList.add('selected');
    });
    wbUpdateGallerySelectionBar();
}

async function wbGalleryBulkMove() {
    // Open the folder picker (reusing the existing asset picker logic but for canvases)
    const fd = new FormData();
    fd.append('action', 'get_all_folders');
    const res = await fetch('index.php', { method: 'POST', body: fd });
    const data = await res.json();
    
    let html = `<button class="wb-as-btn" onclick="wbExecuteBulkGalleryMove(0)">${wbIcons.home} Root (My Canvases)</button>`;
    data.folders.forEach(f => {
        // Don't allow moving into a folder that is currently selected for moving
        if (!window.wbSelectedFolders.has(f.id)) {
            html += `<button class="wb-as-btn" onclick="wbExecuteBulkGalleryMove(${f.id})">${wbIcons.folder} ${f.name}</button>`;
        }
    });
    
    const sheet = document.getElementById('wb-action-sheet');
    document.getElementById('wb-as-title').innerText = "Move Selected to...";
    document.getElementById('wb-as-options').innerHTML = html;
    sheet.style.display = 'flex';
    setTimeout(() => {
        sheet.classList.add('active');
        sheet.querySelector('.wb-action-sheet').classList.add('active');
    }, 50);
}

async function wbExecuteBulkGalleryMove(targetFid) {
    wbCloseActionSheet();
    const fd = new FormData();
    fd.append('action', 'bulk_gallery_move');
    fd.append('canvas_ids', JSON.stringify(Array.from(window.wbSelectedCanvases)));
    fd.append('folder_ids', JSON.stringify(Array.from(window.wbSelectedFolders)));
    fd.append('target_folder_id', targetFid);
    
    await fetch('index.php', { method: 'POST', body: fd });
    wbToggleGallerySelectionMode();
    wbRenderGallery(); // Refresh data after move
}

async function wbToggleGallery(show = null) {
    const el = document.getElementById('gallery-view');
    const isVisible = el.classList.contains('visible');
    const targetShow = (show !== null) ? show : !isVisible;

    if (targetShow) {
        const proceed = () => {
            el.style.display = 'flex';
            // Force reflow for transition
            void el.offsetWidth;
            el.classList.add('visible');
            wbRenderGallery(wbCurrentFolderId);
        };

        if (typeof saveDrawing === 'function') {
            const pill = document.getElementById('status-pill');
            if (pill) {
                pill.innerText = "Saving canvas...";
                pill.style.opacity = "1";
            }
            saveDrawing(true).then(proceed).catch(proceed);
        } else {
            proceed();
        }
    } else {
        el.classList.remove('visible');
        // Hide after transition
        setTimeout(() => {
            if (!el.classList.contains('visible')) el.style.display = 'none';
        }, 300);
    }
}

window.wbGallerySearchQuery = '';

window.wbHandleGallerySearch = function(val) {
    window.wbGallerySearchQuery = val.trim();
    const clearBtn = document.getElementById('gallery-search-clear');
    if (clearBtn) clearBtn.style.display = val ? 'flex' : 'none';
    wbRenderGallery(wbCurrentFolderId);
};

window.wbClearGallerySearch = function() {
    const input = document.getElementById('gallery-search-input');
    if (input) input.value = '';
    wbHandleGallerySearch('');
    // Clear pill active states
    document.querySelectorAll('.shortcut-pill').forEach(p => p.classList.remove('active'));
};

window.wbSetSearchShortcut = function(name) {
    const input = document.getElementById('gallery-search-input');
    if (!input) return;

    if (input.value === name) {
        wbClearGallerySearch();
    } else {
        input.value = name;
        wbHandleGallerySearch(name);
    }

    // Update active state on pills
    document.querySelectorAll('.shortcut-pill').forEach(p => {
        p.classList.toggle('active', p.innerText === name && input.value === name);
    });
};

async function wbInitSearchShortcuts() {
    const container = document.getElementById('gallery-search-shortcuts');
    if (!container || container.children.length > 0) return;

    let folders = [];
    try {
        const fd = new FormData();
        fd.append('action', 'list_library');
        fd.append('path', 'LessonPlanner');
        const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 5000);
        const data = await res.json();
        if (data.status === 'success') folders = data.folders;
    } catch (e) {
        // Offline Fallback: Extract folder names from metadata snapshot
        const snap = await getMetadata('full_snapshot');
        if (snap && snap.assets) {
            const set = new Set();
            snap.assets.forEach(a => {
                const meta = (typeof a.data === 'string') ? JSON.parse(a.data) : a.data;
                if (meta && meta.path && meta.path.startsWith('LessonPlanner/')) {
                    const parts = meta.path.split('/');
                    if (parts.length > 1 && parts[1]) set.add(parts[1]);
                }
            });
            folders = Array.from(set).sort();
        }
    }

    if (folders.length > 0) {
        container.innerHTML = folders.map(f => `<div class="shortcut-pill" onclick="wbSetSearchShortcut('${f}')">${f}</div>`).join('');
    }
}

function wbPopulateGalleryGrid(data) {
    const grid = document.getElementById('gallery-grid');
    const headerTitle = document.getElementById('gallery-title');
    const fragment = document.createDocumentFragment();

    // Update Title
    if (data.current_folder) {
        headerTitle.innerHTML = `<span style="cursor:pointer; opacity:0.6;" onclick="wbRenderGallery(${data.current_folder.parent_id})">My Canvases</span> <span style="opacity:0.4; margin:0 8px;">/</span> <span>${data.current_folder.name}</span>`;
    } else {
        headerTitle.innerHTML = `My Canvases`;
    }

    data.folders.forEach(f => {
        const card = document.createElement('div');
        card.classList.add('wb-content-fade');
        card.dataset.id = f.id;
        card.dataset.type = 'folder';
        const isSelected = window.wbSelectedFolders.has(f.id);
        card.className = 'folder-card' + (isSelected ? ' selected' : '');
        const escName = f.name.replace(/'/g, "\\'");
        card.innerHTML = `
            <div class="gallery-select-check"><svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="4" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg></div>
            <div class="folder-card-thumb"><svg viewBox="0 0 24 24" width="48" height="48" stroke="currentColor" stroke-width="1.2" fill="none"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg></div>
            <div class="folder-card-info"><div class="folder-card-name">${f.name}</div><div style="font-size:10px; color:var(--text-secondary); font-weight:600; text-transform:uppercase; margin-top:4px;">Folder</div></div>
            <div class="canvas-card-actions" onclick="wbOpenActionSheet(event, 'folder', ${f.id}, '${escName}')"><svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="3" fill="none"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg></div>
        `;
        card.onpointerdown = (e) => wbStartLongPress(e, 'folder', f.id, escName);
        card.onpointerup = wbCancelLongPress;
        card.onpointermove = wbHandlePointerMove;
        card.onpointercancel = wbCancelLongPress;
        card.onclick = () => {
            if (window.wbGallerySelectionMode) {
                const wasSelected = window.wbSelectedFolders.has(f.id);
                if (wasSelected) window.wbSelectedFolders.delete(f.id); else window.wbSelectedFolders.add(f.id);
                card.classList.toggle('selected', !wasSelected);
                wbUpdateGallerySelectionBar();
            } else { wbRenderGallery(f.id); }
        };
        fragment.appendChild(card);
    });

    data.canvases.forEach(c => {
        const card = document.createElement('div');
        card.classList.add('wb-content-fade');
        card.dataset.id = c.id;
        card.dataset.type = 'canvas';
        const isSelected = window.wbSelectedCanvases.has(c.id);
        card.className = 'canvas-card' + (isSelected ? ' selected' : '');
        const escName = c.name.replace(/'/g, "\\'");
        if (c.id == window.currentCanvasId) card.style.borderColor = 'var(--primary-accent)';
        const date = new Date(c.updated_at * 1000).toLocaleDateString();
        card.innerHTML = `
            <div class="gallery-select-check"><svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="4" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg></div>
            <div class="canvas-card-thumb" style="background-image: url('${c.thumbnail || ''}')"></div>
            <div class="canvas-card-info"><div class="canvas-card-name">${c.name}</div><div class="canvas-card-date">${date}</div></div>
            <div class="canvas-card-actions" onclick="wbOpenActionSheet(event, 'canvas', ${c.id}, '${escName}')"><svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="3" fill="none"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg></div>
        `;
        card.onpointerdown = (e) => wbStartLongPress(e, 'canvas', c.id, escName);
        card.onpointerup = wbCancelLongPress;
        card.onpointermove = wbHandlePointerMove;
        card.onpointercancel = wbCancelLongPress;
        card.onclick = () => {
            if (window.wbGallerySelectionMode) {
                const wasSelected = window.wbSelectedCanvases.has(c.id);
                if (wasSelected) window.wbSelectedCanvases.delete(c.id); else window.wbSelectedCanvases.add(c.id);
                card.classList.toggle('selected', !wasSelected);
                wbUpdateGallerySelectionBar();
            } else {
                if (c.id == window.currentCanvasId) wbToggleGallery(false);
                else window.location.href = '?canvas=' + c.id;
            }
        };
        fragment.appendChild(card);
    });

    grid.innerHTML = '';
    grid.appendChild(fragment);

    if (window.wbGallerySelectionMode) grid.classList.add('gallery-selection-active');
    else grid.classList.remove('gallery-selection-active');

    if (window.lucide) lucide.createIcons();

    setTimeout(() => {
        const container = document.querySelector('.gallery-breadcrumb-container');
        if (container) container.scrollTo({ left: container.scrollWidth, behavior: 'smooth' });
    }, 50);
}

async function wbRenderGallery(folderId = null) {
    const isNewFolder = folderId !== null && folderId !== wbCurrentFolderId;
    if (folderId !== null) wbCurrentFolderId = folderId;
    
    // Initialize shortcuts once
    wbInitSearchShortcuts();

    const grid = document.getElementById('gallery-grid');
    
    // --- 1. LOAD FROM LOCAL CACHE FIRST ---
    const snap = await getMetadata('full_snapshot');
    const fid = wbCurrentFolderId || 0;
    let localData = null;

    if (snap) {
        localData = {
            status: 'success',
            folders: (snap.folders || []).filter(f => f.parent_id == fid),
            canvases: (snap.canvases || [])
                .filter(c => c.folder_id == fid)
                .sort((a, b) => b.updated_at - a.updated_at),
            current_folder: fid > 0 ? (snap.folders || []).find(f => f.id == fid) : null
        };

        // Apply Search Query if active
        if (window.wbGallerySearchQuery) {
            const q = window.wbGallerySearchQuery.toLowerCase();
            localData.folders = localData.folders.filter(f => f.name.toLowerCase().includes(q));
            localData.canvases = localData.canvases.filter(c => c.name.toLowerCase().includes(q));
        }

        wbPopulateGalleryGrid(localData);
    } else if (grid.children.length === 0 || isNewFolder) {
        // Show skeletons only if no local cache at all
        let skeletons = '';
        for(let i=0; i<8; i++) {
            skeletons += `<div class="wb-skeleton-card">
                <div class="wb-skeleton-thumb"></div>
                <div class="wb-skeleton-info">
                    <div class="wb-skeleton-line" style="width:70%"></div>
                    <div class="wb-skeleton-line" style="width:40%"></div>
                </div>
            </div>`;
        }
        grid.innerHTML = skeletons;
    }

    // --- 2. FETCH FROM SERVER IN BACKGROUND ---
    const fd = new FormData();
    fd.append('action', 'get_gallery');
    fd.append('folder_id', wbCurrentFolderId);
    
    try {
        if (navigator.onLine !== false) {
            const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 4000);
            const serverData = await res.json();
            
            if (serverData.status === 'success') {
                // Apply Search Query
                if (window.wbGallerySearchQuery) {
                    const q = window.wbGallerySearchQuery.toLowerCase();
                    serverData.folders = serverData.folders.filter(f => f.name.toLowerCase().includes(q));
                    serverData.canvases = serverData.canvases.filter(c => c.name.toLowerCase().includes(q));
                }

                const localSignature = localData ? JSON.stringify(localData.canvases.map(c => c.id + '-' + c.updated_at)) : '';
                const serverSignature = JSON.stringify(serverData.canvases.map(c => c.id + '-' + c.updated_at));

                if (localSignature !== serverSignature || isNewFolder) {
                    wbPopulateGalleryGrid(serverData);
                }
            }
        }
    } catch (e) {
        // Keep any local gallery content visible and usable when the server is
        // unavailable. Only show the error state when no cached data exists.
        if (!localData && grid.children.length === 0) {
            grid.innerHTML = '<div style="grid-column:1/-1; color:var(--text-secondary); text-align:center; padding:40px;">Offline: no cached canvases available.</div>';
        }
    }
}let wbLpTimer = null;
let wbLpPos = { x: 0, y: 0 };

function wbStartLongPress(e, type, id, name) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    if (window.wbGallerySelectionMode) return; // Already in selection mode

    const target = e.currentTarget;
    wbLpPos = { x: e.clientX, y: e.clientY };
    target.classList.add('wb-pressing');
    
    wbLpTimer = setTimeout(() => {
        target.classList.remove('wb-pressing');
        if (window.navigator.vibrate) navigator.vibrate(15);
        
        // 1. Set State
        window.wbGallerySelectionMode = true;
        if (type === 'canvas') window.wbSelectedCanvases.add(id);
        else window.wbSelectedFolders.add(id);
        
        // 2. Direct DOM Update (Zero Flicker)
        const grid = document.getElementById('gallery-grid');
        grid.classList.add('gallery-selection-active');
        target.classList.add('selected');
        
        // 3. UI Refresh
        wbUpdateGallerySelectionBar();
        wbLpTimer = null;
    }, 600);
}

function wbHandlePointerMove(e) {
    if (!wbLpTimer) return;
    const dist = Math.hypot(e.clientX - wbLpPos.x, e.clientY - wbLpPos.y);
    // Allow 10px of movement (jitter) before canceling the long-press
    if (dist > 10) {
        wbCancelLongPress(e);
    }
}

function wbCancelLongPress(e) {
    clearTimeout(wbLpTimer);
    wbLpTimer = null;
    e.currentTarget.classList.remove('wb-pressing');
}

async function wbCreateCanvas() {
    const name = await window.wbui.input("Give your new canvas a name", "New Canvas", "Untitled Drawing", wbIcons.palette);
    if (!name) return;
    
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
            if (!snap.canvases) snap.canvases =[];
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
    window.location.href = '?canvas=' + realId;
}

async function wbCreateFolder() {
    if (navigator.onLine === false) {
        window.wbui.alert("You cannot create folders while offline.", "Offline Mode", "📶");
        return;
    }
    const name = await window.wbui.input("Enter a name for your new folder", "New Folder", "Untitled Folder", wbIcons.folder);
    if (!name) return;
    
    try {
        const fd = new FormData();
        fd.append('action', 'create_folder');
        fd.append('name', name);
        fd.append('parent_id', wbCurrentFolderId);
        await fetch('index.php', { method: 'POST', body: fd });
        wbRenderGallery();
    } catch(e) {
        window.wbui.alert("Failed to connect to server.", "Connection Error", "📶");
    }
}

function wbOpenActionSheet(e, type, id, name) {
    e.stopPropagation();
    const sheet = document.getElementById('wb-action-sheet');
    const title = document.getElementById('wb-as-title');
    const opts = document.getElementById('wb-as-options');
    
    title.innerText = name;
    opts.innerHTML = '';

    if (type === 'canvas') {
        opts.innerHTML += `<button class="wb-as-btn" onclick="wbAction('rename_canvas', ${id}, '${name}')">${wbIcons.edit} Rename</button>`;
        opts.innerHTML += `<button class="wb-as-btn" onclick="wbAction('duplicate_canvas', ${id})">${wbIcons.copy} Duplicate</button>`;
        opts.innerHTML += `<button class="wb-as-btn" onclick="wbMoveCanvasFlow(${id})">${wbIcons.folder} Move to Folder</button>`;
        opts.innerHTML += `<button class="wb-as-btn danger" onclick="wbAction('delete_canvas', ${id})">${wbIcons.trash} Delete</button>`;
    } else {
        opts.innerHTML += `<button class="wb-as-btn" onclick="wbAction('rename_folder', ${id}, '${name}')">${wbIcons.edit} Rename</button>`;
        opts.innerHTML += `<button class="wb-as-btn danger" onclick="wbAction('delete_folder', ${id})">${wbIcons.trash} Delete Folder</button>`;
    }

    sheet.style.display = 'flex';
    // Force a reflow to ensure the browser registers the display change
    void sheet.offsetWidth; 
    
    setTimeout(() => {
        sheet.classList.add('active');
        sheet.querySelector('.wb-action-sheet').classList.add('active');
    }, 50);
}

function wbCloseActionSheet() {
    const sheet = document.getElementById('wb-action-sheet');
    sheet.classList.remove('active');
    sheet.querySelector('.wb-action-sheet').classList.remove('active');
    setTimeout(() => sheet.style.display = 'none', 400);
}

async function wbAction(action, id, currentName = '') {
    wbCloseActionSheet();
    let payload = new FormData();
    payload.append('action', action);
    payload.append('id', id);

    if (action === 'rename_canvas' || action === 'rename_folder') {
        const newName = await window.wbui.input("Enter the new name", "Rename", currentName, wbIcons.edit);
        if (!newName) return;
        payload.append('name', newName);
    } else if (action === 'delete_canvas') {
        if (id == 1) { alert("Cannot delete default canvas."); return; }
        if (!await window.wbui.confirm("Delete permanently?", "Delete Canvas", wbIcons.trash)) return;
    } else if (action === 'delete_folder') {
        if (!await window.wbui.confirm("Delete folder? (Contents will be moved to the parent folder)", "Delete Folder", wbIcons.alert)) return;
    }

    if (action === 'delete_canvas' || action === 'delete_folder') {
        const card = document.querySelector(`[data-id="${id}"][data-type="${action === 'delete_canvas' ? 'canvas' : 'folder'}"]`);
        if (card) card.classList.add('wb-removing');
    }

    await fetch('index.php', { method: 'POST', body: payload });
    
    if (action === 'delete_canvas') {
        await deleteLocalDocument('canvas_' + id);
        if (id == window.currentCanvasId) {
            window.location.href = 'index.php';
            return;
        }
    }

    // Wait for animation before silent refresh
    setTimeout(() => {
        wbRenderGallery();
    }, 300);
}

async function wbMoveCanvasFlow(cid) {
    const fd = new FormData();
    fd.append('action', 'get_all_folders');
    const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 3000);
    const data = await res.json();
    
    let html = `<button class="wb-as-btn" onclick="wbExecuteMove(${cid}, 0)">${wbIcons.home} Root (My Canvases)</button>`;
    data.folders.forEach(f => {
        html += `<button class="wb-as-btn" onclick="wbExecuteMove(${cid}, ${f.id})">${wbIcons.folder} ${f.name}</button>`;
    });
    
    document.getElementById('wb-as-title').innerText = "Move to...";
    document.getElementById('wb-as-options').innerHTML = html;
}

async function wbExecuteMove(cid, fid) {
    const fd = new FormData();
    fd.append('action', 'move_canvas');
    fd.append('id', cid);
    fd.append('folder_id', fid);
    await fetch('index.php', { method: 'POST', body: fd });
    wbCloseActionSheet();
    wbRenderGallery();
}