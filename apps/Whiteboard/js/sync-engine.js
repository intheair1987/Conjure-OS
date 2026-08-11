/**
 * WHITEBOARD SYNC ENGINE
 * Handles IndexedDB local storage, Cloud Sync, and Dashboard telemetry.
 */

// --- LOCAL DATABASE (INDEXEDDB) ---
const DB_NAME = 'WhiteboardLocalDB';
const DB_VERSION = 3; // Upgraded for Metadata support
let localDB = null;

window.wbIsOfflineMode = false;
window.wbForceOffline = false;

window.wbSetOnlineState = function(isOnline) {
    // Safety: Cannot go online if forced offline
    if (isOnline && window.wbForceOffline) isOnline = false;

    window.wbIsOfflineMode = !isOnline;
    const goOnlineBtn = document.getElementById('wb-go-online-btn');
    const pill = document.getElementById('status-pill');
    
    if (goOnlineBtn) {
        // Only show "Go Online" if we are offline but NOT forced offline
        goOnlineBtn.style.display = (!isOnline && !window.wbForceOffline) ? 'flex' : 'none';
    }

    if (!isOnline) {
        if (pill) {
            pill.innerText = window.wbForceOffline ? "Mode: Offline" : "Server Unreachable";
            pill.style.background = window.wbForceOffline ? "var(--text-secondary)" : "#ff3b30";
            pill.style.opacity = "1";
            setTimeout(() => { pill.style.opacity = "0"; }, 3000);
        }
    } else {
        if (pill) {
            pill.innerText = "Back Online";
            pill.style.background = "var(--primary-accent)";
            pill.style.opacity = "1";
            setTimeout(() => { pill.style.opacity = "0"; }, 2000);
        }
        // Trigger a catch-up sync
        wbSyncAll(true);
    }
};

window.wbCheckConnection = async function(silent = false) {
    // MASTER GUARD: If manually forced offline, do not check connection or update state
    if (window.wbForceOffline) {
        if (!silent) {
            window.wbui.alert("Turn off 'Force Offline' in Settings to go back online.", "Forced Offline", "📶");
        }
        wbSetOnlineState(false);
        return false;
    }

    try {
        const fd = new FormData();
        fd.append('action', 'check_version');
        const res = await fetch('index.php?t=' + Date.now(), { method: 'POST', body: fd });
        if (res.ok) {
            wbSetOnlineState(true);
            return true;
        }
    } catch (e) {
        if (!silent) wbSetOnlineState(false);
    }
    return false;
};

function initLocalDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onerror = (e) => reject("IndexedDB error");
        request.onsuccess = (e) => {
            localDB = e.target.result;
            resolve(localDB);
        };
        request.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('documents')) {
                db.createObjectStore('documents', { keyPath: 'id' });
            }
            if (!db.objectStoreNames.contains('assets')) {
                db.createObjectStore('assets', { keyPath: 'id' });
            }
            // New store for Gallery/Vault structure
            if (!db.objectStoreNames.contains('metadata')) {
                db.createObjectStore('metadata', { keyPath: 'id' });
            }
        };
    });
}

async function saveMetadata(id, data) {
    return new Promise((resolve) => {
        if (!localDB) return resolve();
        const tx = localDB.transaction('metadata', 'readwrite');
        tx.objectStore('metadata').put({ id: id, data: data, updated_at: Date.now() });
        tx.oncomplete = () => resolve();
    });
}

async function getMetadata(id) {
    return new Promise((resolve) => {
        if (!localDB) return resolve(null);
        const tx = localDB.transaction('metadata', 'readonly');
        const req = tx.objectStore('metadata').get(id);
        req.onsuccess = () => resolve(req.result ? req.result.data : null);
    });
}

async function saveLocalDocument(id, dataStr, isDirty = true, syncedId = null, hash = null, thumbnail = null, viewport = null) {
    if (!localDB) return;
    
    try {
        // Fetch existing record to preserve metadata (like thumbnail or viewport) if not provided
        const existing = await getLocalDocument(id);
        
        return new Promise((resolve, reject) => {
            const tx = localDB.transaction('documents', 'readwrite');
            const store = tx.objectStore('documents');
            
            // Helper to pick the first non-undefined, non-null value
            const pick = (val, fallback) => (val !== null && val !== undefined) ? val : fallback;

            const sId = pick(syncedId, (existing ? existing.lastSyncedId : lastSyncedId));
            const h = pick(hash, (existing ? existing.lastServerHash : lastServerHash));

            const entry = { 
                id: id, 
                data: dataStr, 
                thumbnail: pick(thumbnail, (existing ? existing.thumbnail : null)),
                viewport: pick(viewport, (existing ? existing.viewport : null)),
                updated_at: Date.now(),
                dirty: isDirty,
                lastSyncedId: sId,
                lastServerHash: h
            };

            const req = store.put(entry);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
        });
    } catch (e) {
        console.error("IDB Save Failure:", e);
    }
}

function getLocalDocument(id) {
    return new Promise((resolve, reject) => {
        if (!localDB) return reject("No DB");
        const tx = localDB.transaction('documents', 'readonly');
        const store = tx.objectStore('documents');
        const request = store.get(id);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject();
    });
}

function deleteLocalDocument(id) {
    return new Promise((resolve, reject) => {
        if (!localDB) return reject("No DB");
        const tx = localDB.transaction('documents', 'readwrite');
        const store = tx.objectStore('documents');
        const request = store.delete(id);
        request.onsuccess = () => resolve();
        request.onerror = () => reject();
    });
}

window.wbIsUiBusy = function() {
    // Check for active staging or blocking overlays
    const overlays = [
        'pdf-options-overlay',
        'layout-picker-overlay',
        'export-progress-overlay',
        'asset-preview-overlay'
    ];
    
    const isOverlayOpen = overlays.some(id => {
        const el = document.getElementById(id);
        return el && el.style.display === 'flex';
    });

    // Busy if user is actively interacting with the canvas, dragging/resizing, selecting, or panning
    const isUserInteracting = (typeof isInteracting !== 'undefined' && isInteracting) ||
                              (typeof isDraggingObject !== 'undefined' && isDraggingObject) ||
                              (typeof isDraggingTextInsertion !== 'undefined' && isDraggingTextInsertion) ||
                              (typeof isResizingSelection !== 'undefined' && isResizingSelection) ||
                              (typeof isSelecting !== 'undefined' && isSelecting) ||
                              (typeof isPanning !== 'undefined' && isPanning);

    // Also busy if text editor is active, globally syncing, or a save is pending/debouncing
return isOverlayOpen || 
       isUserInteracting ||
       (typeof activeTextEditor !== 'undefined' && activeTextEditor !== null) ||
       isGlobalSyncing ||
       (typeof cloudSyncTimer !== 'undefined' && cloudSyncTimer !== null);
    };let isSyncing = false;
let cloudSyncTimer = null;
window._wbInitialLoadComplete = false;

async function saveDrawing(manual = false) {
    if (!manual && (!autoSaveEnabled || wbIsUiBusy())) return;

    const data = JSON.stringify(allStrokes);
    const currentHash = wbGetHash(data);
    const vp = getActiveViewport();
    const currentViewportStr = vp ? JSON.stringify(vp.transform) : null;

    // Skip sync if data and viewport haven't changed since last server update
    const dataChanged = currentHash !== lastServerHash;
    const viewportChanged = currentViewportStr !== window._lastServerViewportStr;

    if (!dataChanged && !viewportChanged) {
        return;
    }

    const pill = document.getElementById('status-pill');
    pill.style.opacity = "1";
    
    // 1. Save Locally (Instant Persistence)
    try {
        if (typeof saveLocalDocument === 'function') {
            await saveLocalDocument('canvas_' + window.currentCanvasId, data, true);
        }
        pill.innerText = "Local Saved";
    } catch(e) { console.error("Local save failed", e); }

    // 2. Debounce Cloud Sync
    if (cloudSyncTimer) clearTimeout(cloudSyncTimer);

    const performCloudSync = async () => {
        if (String(window.currentCanvasId).startsWith('local_')) {
            if (navigator.onLine !== false) wbSyncAll(true);
            return;
        }
        
        // Strict Mutex Lock: Prevent overlapping network requests
        if (isSyncing) {
            window._wbSyncQueued = true;
            return;
        }
        isSyncing = true;
        window._wbSyncQueued = false;

        const isHardwareOnline = navigator.onLine !== false;
        if (!isHardwareOnline || window.wbIsOfflineMode) {
            if (pill) {
                pill.innerText = window.wbForceOffline ? "Offline (Saved)" : "Offline (Local Only)";
                pill.style.opacity = "1";
            }
            // Still generate thumbnail for local gallery even if offline
            render();
            const vp = getActiveViewport();
            const thumbCanvas = document.createElement('canvas');
            thumbCanvas.width = parseInt(window._thumbRes || 200);
            thumbCanvas.height = thumbCanvas.width * (vp.canvas.height / vp.canvas.width);
            thumbCanvas.getContext('2d').drawImage(vp.canvas, 0, 0, thumbCanvas.width, thumbCanvas.height);
            await saveLocalDocument('canvas_' + window.currentCanvasId, JSON.stringify(allStrokes), true, lastSyncedId, lastServerHash, thumbCanvas.toDataURL('image/jpeg', 0.5));
            setTimeout(() => { if(pill && pill.innerText.includes("Offline")) pill.style.opacity = "0"; }, 2000);
            isSyncing = false;
            if (window._wbSyncQueued) {
                window._wbSyncQueued = false;
                setTimeout(() => saveDrawing(true), 100);
            }
            return;
        }

        isSyncing = true;
        pill.innerText = "Syncing to Cloud...";
        pill.style.opacity = "1";

        // Force immediate render so the screenshot isn't one frame behind
        render();

        const syncData = data; // Reuse pre-serialized JSON string
        const syncHash = currentHash;
        const vp = getActiveViewport();
        const thumbRes = parseInt(window._thumbRes || 200);
        const thumbQual = parseFloat(window._thumbQual || 0.5);
        
        const thumbCanvas = document.createElement('canvas');
        thumbCanvas.width = thumbRes;
        thumbCanvas.height = thumbRes * (vp.canvas.height / vp.canvas.width);
        const tctx = thumbCanvas.getContext('2d');
        tctx.fillStyle = '#FFFFFF';
        tctx.fillRect(0, 0, thumbCanvas.width, thumbCanvas.height);
        tctx.drawImage(vp.canvas, 0, 0, thumbCanvas.width, thumbCanvas.height);
        
        const thumbData = thumbCanvas.toDataURL('image/jpeg', thumbQual);

        // 1. Update Local Bunker immediately (with thumbnail)
        await saveLocalDocument('canvas_' + window.currentCanvasId, syncData, true, lastSyncedId, lastServerHash, thumbData);

        // 2. Patch Local Gallery Snapshot immediately so the gallery is updated even offline
        const snap = await getMetadata('full_snapshot');
        if (snap && snap.canvases) {
            const idx = snap.canvases.findIndex(c => c.id == window.currentCanvasId);
            if (idx !== -1) {
                snap.canvases[idx].thumbnail = thumbData;
                snap.canvases[idx].updated_at = Math.floor(Date.now() / 1000);
                await saveMetadata('full_snapshot', snap);
            }
        }

        const attemptSync = async (baseId) => {
            const fd = new FormData();
fd.append('action', 'save');
fd.append('data', syncData);
fd.append('base_id', baseId);
fd.append('canvas_id', window.currentCanvasId);
fd.append('thumbnail', thumbData);
fd.append('viewport', JSON.stringify(vp.transform));try {
                // Use a 10s timeout for the cloud sync to accommodate large thumbnails and slow connections
                const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 10000);
                const result = await res.json();

                if (result.status === 'success') {
    lastSyncedId = result.id;
    lastServerHash = syncHash;
    window._lastServerViewportStr = currentViewportStr;
    if (typeof saveLocalDocument === 'function') {
        await saveLocalDocument('canvas_' + window.currentCanvasId, syncData, false);
    }pill.innerText = "Cloud Synced";
                    setTimeout(() => { if(pill.innerText === "Cloud Synced") pill.style.opacity = "0"; }, 1500);
                    isSyncing = false;
                } 
                else if (result.status === 'conflict') {
                    pill.innerText = "Resolving Conflict...";
                    console.warn("Sync Conflict! Server is at " + result.server_id + ", we are at " + baseId);
                    
                    // RESOLUTION: Fetch the server's version and merge
                    const fetchFd = new FormData();
                    fetchFd.append('action', 'fetch_latest_data');
                    fetchFd.append('canvas_id', window.currentCanvasId);
                    const fetchRes = await wbFetchWithTimeout('index.php', { method: 'POST', body: fetchFd }, 3000);
                    const serverData = await fetchRes.json();
                    
                    if (serverData.status === 'success') {
                        let remoteStrokes = [];
                        try {
                            remoteStrokes = JSON.parse(serverData.data || '[]');
                        } catch(e) { console.error("Sync: Failed to parse server data", e); }
                        
                        // TIME TRAVEL DETECTION: If the server ID is lower than our last sync, 
                        // a restore likely happened. We should prioritize the server.
                        if (serverData.id < lastSyncedId) {
                            console.warn("Sync: Server has been rolled back (Time Travel). Overwriting local.");
                            allStrokes = remoteStrokes;
                            const pill = document.getElementById('status-pill');
                            if (pill) {
                                pill.innerText = "Cloud Restore Applied";
                                pill.style.opacity = "1";
                                setTimeout(() => pill.style.opacity = "0", 3000);
                            }
                        } else {
                            // Robust ID-Based Merge with Tombstones
                            const localMap = new Map(allStrokes.map(s => [s.id, s]));
                            const merged = [];
                            
                            // 1. Prioritize Local Strokes (Preserves local moves, edits, and new strokes)
                            allStrokes.forEach(s => merged.push(s));
                            
                            // 2. Add Remote Strokes that we don't have, UNLESS we explicitly deleted them offline
                            remoteStrokes.forEach(rs => {
                                const isDeletedLocally = window.wbDeletedStrokeIds && window.wbDeletedStrokeIds.has(rs.id);
                                if (!localMap.has(rs.id) && !isDeletedLocally) {
                                    merged.push(rs);
                                }
                            });
                            
                            allStrokes = merged.sort((a, b) => a.zIndex - b.zIndex);
                        }
                        
                        render();
                        await attemptSync(serverData.id);
                    }
                }
                
                // Clear tombstones on successful sync to prevent memory leaks
                if (window.wbDeletedStrokeIds) window.wbDeletedStrokeIds.clear();

            } catch (e) {
                console.warn("Sync: Cloud save failed or timed out.", e);
                const pill = document.getElementById('status-pill');
                if (pill) {
                    pill.innerText = "Sync Failed (Retrying later)";
                    setTimeout(() => { if(pill.innerText.includes("Failed")) pill.style.opacity = "0"; }, 2000);
                }
                // Only force offline mode if the browser explicitly reports no connection
                if (navigator.onLine === false) {
                    wbSetOnlineState(false);
                }
            } finally {
                isSyncing = false;
                // If a save was requested while we were syncing, trigger it now
                if (window._wbSyncQueued) {
                    setTimeout(() => saveDrawing(true), 100);
                }
            }
        };

        await attemptSync(lastSyncedId);
    };

    if (manual) {
        return performCloudSync();
    } else {
        cloudSyncTimer = setTimeout(performCloudSync, 2500); 
    }
}

let isGlobalSyncing = false;

async function wbSyncAll(silent = false) {
    const intervalSecs = (window.wb.settings.sync_interval_mins || 1) * 60;
    window.syncCountdown = intervalSecs;
    const cdEl = document.getElementById('sync-countdown');
    if (cdEl) cdEl.innerText = window.syncCountdown;

    if (isGlobalSyncing || window.wbIsOfflineMode) return;
    isGlobalSyncing = true;
    
    const btn = document.getElementById('wb-sync-all-btn');
    const pill = document.getElementById('status-pill');
    let tooltip = document.getElementById('wb-sync-tooltip');

    if (!silent) {
        if (navigator.onLine === false) {
            pill.innerText = "Offline (Sync Skipped)";
            pill.style.opacity = "1";
            setTimeout(() => pill.style.opacity = "0", 2000);
            isGlobalSyncing = false;
            return;
        }
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'wb-sync-tooltip';
            tooltip.className = 'wb-sync-tooltip';
            document.body.appendChild(tooltip);
        }
        if (btn) {
            const rect = btn.getBoundingClientRect();
            tooltip.style.top = (rect.bottom + 10) + 'px';
            tooltip.style.left = (rect.right - 140) + 'px';
            tooltip.classList.add('active');
            btn.style.opacity = "0.5";
        }
        tooltip.innerText = "Fetching manifest...";
        pill.innerText = "Syncing All...";
        pill.style.opacity = "1";
    }

    try {
        // 0. Background Push: Find and upload all "dirty" canvases first
        const txPush = localDB.transaction('documents', 'readonly');
        const storePush = txPush.objectStore('documents');
        const allDocs = await new Promise(r => {
            const req = storePush.getAll();
            req.onsuccess = () => r(req.result);
        });

        let pushCount = 0;
        for (const doc of allDocs) {
            if (doc.dirty && doc.id.startsWith('canvas_')) {
                const cid = doc.id.replace('canvas_', '');
                
                if (cid.startsWith('local_')) {
                    if (!silent && tooltip) tooltip.innerText = `Registering offline canvas...`;
                    let cName = "Offline Canvas";
                    let cFolder = 0;
                    const snap = await getMetadata('full_snapshot');
                    if (snap && snap.canvases) {
                        const cMeta = snap.canvases.find(c => c.id === cid);
                        if (cMeta) { cName = cMeta.name; cFolder = cMeta.folder_id; }
                    }
                    
                    const fd = new FormData();
                    fd.append('action', 'create_canvas');
                    fd.append('name', cName);
                    fd.append('folder_id', cFolder);
                    try {
                        const res = await fetch('index.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.status === 'success') {
                            const newId = data.id;
                            await pushBackgroundUpdate(newId, doc);
                            await deleteLocalDocument(doc.id);
                            if (snap && snap.canvases) {
                                const idx = snap.canvases.findIndex(c => c.id === cid);
                                if (idx !== -1) {
                                    snap.canvases[idx].id = newId;
                                    await saveMetadata('full_snapshot', snap);
                                }
                            }
                            if (window.currentCanvasId === cid) {
                                window.currentCanvasId = newId;
                                localStorage.setItem('wb_last_canvas', newId);
                                window.history.replaceState(null, '', '?canvas=' + newId);
                            }
                            pushCount++;
                        }
                    } catch (e) {
                        console.error("Failed to register local canvas", e);
                    }
                } else {
                    if (!silent && tooltip) tooltip.innerText = `Uploading offline work: Canvas ${cid}`;
                    const success = await pushBackgroundUpdate(cid, doc);
                    if (success) pushCount++;
                }
            }
        }
        if (pushCount > 0) console.log(`Sync: Uploaded ${pushCount} canvases from background.`);

// 1. Fetch Lightweight Manifest FIRST
const fd = new FormData();
fd.append('action', 'get_sync_manifest');
const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 15000);
const data = await res.json();

if (data.status === 'success') {
    // --- SMART SNAPSHOT CHECK ---
    let needsSnapshot = false;
    const snap = await getMetadata('full_snapshot');
            
    if (!snap) {
        needsSnapshot = true;
    } else {
        const sys = data.sys_meta;
        const localAssets = snap.assets || [];
        const localFolders = snap.folders || [];
                
        if (sys.assets.c != localAssets.length || sys.assets.m > (snap._last_asset_m || 0) ||
            sys.folders.c != localFolders.length || sys.folders.m > (snap._last_folder_m || 0)) {
            needsSnapshot = true;
        } else {
            const localCMap = new Map((snap.canvases || []).map(c => [c.id, c.updated_at]));
            if (data.manifest.length !== localCMap.size) {
                needsSnapshot = true;
            } else {
                for (let m of data.manifest) {
                    if (localCMap.get(m.id) != m.updated_at) {
                        needsSnapshot = true;
                        break;
                    }
                }
            }
        }
    }

    if (needsSnapshot) {
        if (!silent && tooltip) tooltip.innerText = "Fetching structural updates...";
        const snapFd = new FormData();
        snapFd.append('action', 'get_full_snapshot');
        const snapRes = await wbFetchWithTimeout('index.php', { method: 'POST', body: snapFd }, 10000);
        const snapshot = await snapRes.json();
                
        if (snapshot.status === 'success') {
            snapshot._last_asset_m = data.sys_meta.assets.m;
            snapshot._last_folder_m = data.sys_meta.folders.m;
            await saveMetadata('full_snapshot', snapshot);
            console.log("Sync: Metadata Snapshot Updated.");

            const assets = snapshot.assets || [];
            const CHUNK_SIZE = 10;
            let downloadCount = 0;
                    
            for (let i = 0; i < assets.length; i += CHUNK_SIZE) {
                const chunk = assets.slice(i, i + CHUNK_SIZE);
                if (!silent && tooltip) tooltip.innerText = `Checking Assets ${i + 1}/${assets.length}...`;
                        
                await Promise.all(chunk.map(async (asset) => {
                    const exists = await hasLocalAsset(asset.hash);
                    if (!exists) {
                        await fetchAsset(asset.hash);
                        downloadCount++;
                    }
                }));
            }
            if (downloadCount > 0) console.log(`Sync: Cached ${downloadCount} new assets.`);
        }
    }

    // 2. Standard Drawing Sync
    let updateCount = 0;
    const total = data.manifest.length;
            
    for (let i = 0; i < total; i++) {const item = data.manifest[i];
                const cid = item.id;
                const serverId = parseInt(item.latest_drawing_id || 0);
                
                if (!silent && tooltip) tooltip.innerText = `Checking (${i+1}/${total}): ${item.name || 'Canvas ' + cid}`;

                const localDoc = await getLocalDocument('canvas_' + cid);
                const localId = localDoc ? (localDoc.lastSyncedId || 0) : 0;
                const isDirty = localDoc ? localDoc.dirty : false;

                if (serverId > localId && !isDirty) {
                    if (!silent && tooltip) tooltip.innerText = `Downloading: ${item.name || 'Canvas ' + cid}`;
                    await fetchBackgroundUpdate(cid);
                    updateCount++;
                }
            }
            
            // --- NEW: Local Cache Pruning ---
            // If the server says we only have 1 canvas, but local DB has 5, delete the 4 stale ones.
            const serverIds = new Set(data.manifest.map(m => 'canvas_' + m.id));
            const tx = localDB.transaction('documents', 'readwrite');
            const store = tx.objectStore('documents');
            const getAllKeysReq = store.getAllKeys();

            getAllKeysReq.onsuccess = () => {
                const localKeys = getAllKeysReq.result;
                localKeys.forEach(key => {
                    // Only prune keys that look like canvases and aren't in the server manifest
                    if (key.startsWith('canvas_') && !serverIds.has(key)) {
                        console.log("Pruning stale local canvas: " + key);
                        store.delete(key);
                    }
                });
            };

            // Success: Store timestamp
            localStorage.setItem('wb_last_sync_all', new Date().toISOString());
            
            if (!silent) {
                if (tooltip) tooltip.innerText = updateCount > 0 ? `Successfully updated ${updateCount} items` : "Everything is already synced";
                pill.innerText = updateCount > 0 ? `Updated ${updateCount} Canvases` : "All Up to Date";
            }
        }
    } catch (e) {
        console.error("Global Sync Failed", e);
        if (!silent && tooltip) tooltip.innerText = "Sync Error: Check connection";
        if (!silent) pill.innerText = "Sync Failed";
    }

    if (!silent) {
        setTimeout(() => { 
            pill.style.opacity = "0"; 
            if (tooltip) tooltip.classList.remove('active');
            if (btn) btn.style.opacity = "1"; 
        }, 3000);
    }
    
    isGlobalSyncing = false;
    if (document.getElementById('gallery-view').style.display === 'flex') wbRenderGallery();
    if (document.getElementById('sync-center-overlay')?.style.display === 'flex') wbOpenDashboard();
}

async function pushBackgroundUpdate(cid, localDoc) {
    if (!localDoc || !localDoc.data) return;

    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('data', localDoc.data);
    fd.append('base_id', localDoc.lastSyncedId || 0);
    fd.append('canvas_id', cid);
    if (localDoc.thumbnail) fd.append('thumbnail', localDoc.thumbnail);
    if (localDoc.viewport) fd.append('viewport', localDoc.viewport);

    try {
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const result = await res.json();

        if (result.status === 'success') {
            console.log(`Sync: Background push successful for Canvas ${cid}`);
            // Mark as clean in IndexedDB
            await saveLocalDocument('canvas_' + cid, localDoc.data, false, result.id, wbGetHash(localDoc.data), localDoc.thumbnail);
            return true;
        } else if (result.status === 'conflict') {
            console.warn(`Sync: Conflict on background push for Canvas ${cid}. Manual resolution required.`);
            // We leave it dirty so the user can resolve it when they eventually open this canvas
        }
    } catch (e) {
        console.error(`Sync: Background push failed for Canvas ${cid}`, e);
    }
    return false;
}

async function fetchBackgroundUpdate(cid) {
    const fd = new FormData();
    fd.append('action', 'fetch_latest_data');
    fd.append('canvas_id', cid);
    try {
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const result = await res.json();
        if (result.status === 'success') {
            const remoteHash = wbGetHash(result.data);
            // Save directly to IndexedDB with correct metadata from the response
            await saveLocalDocument('canvas_' + cid, result.data, false, result.id, remoteHash, null, result.viewport);
            
            // If this happens to be the canvas we are currently looking at, update the view
            if (cid == window.currentCanvasId && !isDrawing) {
                lastSyncedId = result.id;
                lastServerHash = remoteHash;
                allStrokes = JSON.parse(result.data);
                render();
            }
        }
    } catch(e) {}
}

function startDataPoller() {
    // 1. Active Canvas Poller (Fast: 5s)
    setInterval(async () => {
        if (document.hidden) return;
        if (!window._wbInitialLoadComplete || isDrawing || isMoving || isSelecting || isSyncing || isGlobalSyncing || window.wbIsOfflineMode || wbIsUiBusy()) return;
        if (String(window.currentCanvasId).startsWith('local_')) return;

        const fd = new FormData();
        fd.append('action', 'check_data_version');
        fd.append('canvas_id', window.currentCanvasId);
        try {
            const res = await fetch('index.php?t=' + Date.now(), { method: 'POST', body: fd });
            const data = await res.json();
            const localDoc = await getLocalDocument('canvas_' + window.currentCanvasId);
            if (data.id && data.id > lastSyncedId && (!localDoc || !localDoc.dirty)) {
                fetchRemoteUpdate();
            }
        } catch(e) {}
    }, 15000);

    // Initial Icon Hydration
    if (window.lucide) lucide.createIcons();

    // 2. Global Background Poller (15s tick for lower wakeup and radio cost)
    const initIntervalMins = window.wb.settings.sync_interval_mins || 1;
    window.syncCountdown = initIntervalMins * 60;
    let lastSyncTickAt = Date.now();
    let hiddenSince = document.hidden ? Date.now() : 0;

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            hiddenSince = Date.now();
            return;
        }

        if (!hiddenSince) return;

        const intervalSecs = (window.wb.settings.sync_interval_mins || 1) * 60;
        const hiddenSecs = Math.max(0, Math.round((Date.now() - hiddenSince) / 1000));
        window.syncCountdown = Math.max(0, window.syncCountdown - hiddenSecs);
        lastSyncTickAt = Date.now();
        hiddenSince = 0;

        if (
            window.syncCountdown <= 0 &&
            !window.wbIsOfflineMode &&
            !isGlobalSyncing &&
            !isSyncing &&
            !isDrawing &&
            !document.hidden
        ) {
            window.syncCountdown = intervalSecs;
            wbSyncAll(true);
        }
    });

    setInterval(async () => {
        const now = Date.now();
        const elapsedSecs = Math.max(1, Math.round((now - lastSyncTickAt) / 1000));
        lastSyncTickAt = now;
        if (document.hidden) return;

        const cdEl = document.getElementById('sync-countdown');
        const autoEnabled = window.wb.settings.auto_sync_enabled !== false;
        const intervalMins = window.wb.settings.sync_interval_mins || 1;
        const intervalSecs = intervalMins * 60;
        
        // OFFLINE OR DISABLED GUARD: Pause countdown and update UI
        if (window.wbIsOfflineMode || !autoEnabled) {
            if (cdEl) {
                cdEl.innerText = !autoEnabled ? 'MAN' : 'OFF';
                cdEl.style.background = 'var(--text-secondary)';
                cdEl.style.opacity = '0.5';
            }
            
            // Auto-recovery: If offline but NOT forced offline, ping the server every 10 seconds
            if (window.wbIsOfflineMode && !window.wbForceOffline) {
                if (typeof window._offlinePingCounter === 'undefined') window._offlinePingCounter = 0;
                window._offlinePingCounter++;
                if (window._offlinePingCounter >= 10) {
                    window._offlinePingCounter = 0;
                    wbCheckConnection(true); // Silent check
                }
            }
            return;
        }

        if (typeof window.syncCountdown === 'undefined') {
            window.syncCountdown = intervalSecs;
        }
        
        window.syncCountdown -= elapsedSecs;
        
        if (cdEl) {
            cdEl.style.opacity = '1';
            cdEl.innerText = window.syncCountdown > 0 ? window.syncCountdown : '!!';
            // Visual feedback when syncing
            cdEl.style.background = 'var(--primary-accent)';
        }

        if (window.syncCountdown <= 0) {
            window.syncCountdown = intervalSecs;
            if (isDrawing || isSyncing || isGlobalSyncing) return;
            
            // If Gallery is open, refresh the list to show new canvases/folders from other devices
            if (document.getElementById('gallery-view').style.display === 'flex') {
                wbRenderGallery();
            }
            
            // Silently check manifest and download updates for background canvases
            wbSyncAll(true);
        }
    }, 15000);
}

async function checkSyncStatus() {
    const pill = document.getElementById('status-pill');
    pill.innerText = "Checking...";
    pill.style.opacity = "1";

    try {
        const fd = new FormData();
        fd.append('action', 'fetch_latest_data');
        fd.append('canvas_id', window.currentCanvasId);
        // Use cache-buster to ensure we get live DB state, not a cached response
        const res = await fetch('index.php?t=' + Date.now(), { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data.status === 'success' && data.data !== undefined) {
            lastServerHash = wbGetHash(data.data);
            wbUpdateHashUI();
        }

        const localDoc = await getLocalDocument('canvas_' + window.currentCanvasId);
        const isDirty = localDoc ? localDoc.dirty : false;
        
        let report = "";
        let isOut = false;

        if (isDirty) {
            report = "You have local changes that haven't been uploaded yet.";
            isOut = true;
        } else if (data.id > lastSyncedId) {
            report = `The server has a newer version (v${data.id}) than your current view (v${lastSyncedId}).`;
            isOut = true;
        } else if (data.id < lastSyncedId) {
            report = `Your local version (v${lastSyncedId}) is ahead of the server (v${data.id}).`;
            isOut = true;
        } else {
            report = `Your local cache matches the cloud (Version ${lastSyncedId}).`;
        }

        pill.innerText = isOut ? "Out of Sync" : "Synced";
        
        const modalTitle = isOut ? "Sync Conflict" : "Cloud Sync";
        const modalIcon = isOut ? wbIcons.alert : wbIcons.home;
        const modalMsg = report + "\n\nWould you like to force a pull from the cloud? This will overwrite your local cache with the server version.";

        if (await wbui.confirm(modalMsg, modalTitle, modalIcon)) {
            fetchRemoteUpdate(true);
        }
        
        setTimeout(() => { pill.style.opacity = "0"; }, 2000);

    } catch (e) {
        pill.innerText = "Offline";
        alert("Status: UNREACHABLE\nReason: The server could not be reached. You are currently working in Offline Mode.");
        setTimeout(() => { pill.style.opacity = "0"; }, 2000);
    }
}

async function fetchRemoteUpdate(force = false, shouldReload = true) {
    if (!window._wbInitialLoadComplete && !force) return;
    
    // BUSY GUARD: Never trigger a reload while the user is in a staging or export flow
    if (wbIsUiBusy() && !force) {
        console.log("Sync: Remote update deferred. UI is busy.");
        return;
    }

    const localDoc = await getLocalDocument('canvas_' + window.currentCanvasId);
    
    // DIRTY GUARD: Never overwrite local work that hasn't been synced yet.
    // The only exception is if 'force' is true AND we are not in the middle of an interaction.
    if (localDoc && localDoc.dirty) {
        if (!force) {
            console.log("Sync: Remote update blocked. Local canvas is dirty.");
            return;
        }
        // If forced (e.g. via Sync Check), we still check if we should really overwrite
        console.warn("Sync: System requested forced update, but local is dirty. Aborting to prevent data loss.");
        return;
    }

    const fd = new FormData();
    fd.append('action', 'fetch_latest_data');
    fd.append('canvas_id', window.currentCanvasId);
    try {
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const result = await res.json();
        if (result.status === 'success') {
            lastSyncedId = result.id;
            lastServerHash = wbGetHash(result.data);
            
            if (typeof saveLocalDocument === 'function') {
                await saveLocalDocument('canvas_' + window.currentCanvasId, result.data, false, lastSyncedId, lastServerHash, null, result.viewport);
            }
            
            if (shouldReload) {
                const pill = document.getElementById('status-pill');
                if (pill) {
                    pill.innerText = "Sync Complete. Reloading...";
                    pill.style.opacity = "1";
                }
                setTimeout(() => { location.reload(); }, 500);
            } else {
    // IN-MEMORY SWAP: Update state without reloading to break the loop
    console.log("Sync: Swapping data in memory (No-Reload mode)");
    allStrokes = JSON.parse(result.data || '[]');
    // Update the baked-in ID so the stale check doesn't trigger again
    window._initialCanvasId = window.currentCanvasId;
                    
    if (typeof wbPurgeRenderCache === 'function') wbPurgeRenderCache();render();
                
                const pill = document.getElementById('status-pill');
                if (pill) {
                    pill.innerText = "Canvas Updated";
                    pill.style.opacity = "1";
                    setTimeout(() => { pill.style.opacity = "0"; }, 2000);
                }
            }
        }
    } catch(e) { console.error("Remote sync failed", e); }
}

setInterval(async () => {
    if (isDrawing || document.hidden || !window._wbInitialLoadComplete) return;
    try {
        const localDoc = await getLocalDocument('canvas_' + window.currentCanvasId);
        if (localDoc && localDoc.dirty) saveDrawing();
    } catch (e) {}
}, 30000);

async function wbOpenDashboard() {
    let overlay = document.getElementById('sync-center-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'sync-center-overlay';
        overlay.className = 'sync-center-overlay';
        overlay.onclick = (e) => { if(e.target === overlay) overlay.style.display = 'none'; };
        document.body.appendChild(overlay);
    }
    
    overlay.style.display = 'flex';
    overlay.innerHTML = `
        <div class="sync-card">
            <div class="sync-header">
                <h3 style="margin:0; font-size:18px;">System Dashboard</h3>
                <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Whiteboard Health & Statistics</div>
            </div>
            <div class="sync-body" id="sync-health-list">
                <div style="text-align:center; padding:40px; color:var(--text-secondary);">Gathering intelligence...</div>
            </div>
            <div style="padding:16px; border-top:1px solid rgba(0,0,0,0.05); display:flex; gap:10px;">
                <button class="tool-btn" onclick="document.getElementById('sync-center-overlay').style.display='none'" style="flex:1; background:var(--bg-color);">Close Dashboard</button>
            </div>
        </div>
    `;

    try {
        const fd = new FormData();
        fd.append('action', 'get_sync_health');
        // Increased timeout to 20s to allow for deep recursive filesystem scans on slow storage
        const res = await wbFetchWithTimeout('index.php', { method: 'POST', body: fd }, 20000);
        const server = await res.json();

        const tx = localDB.transaction('documents', 'readonly');
        const store = tx.objectStore('documents');
        const request = store.getAll();
        
        request.onsuccess = async () => {
            const localDocs = request.result;
            await renderDashboardReport(server, localDocs);
        };
    } catch (e) {
        document.getElementById('sync-health-list').innerHTML = `<div style="color:red; text-align:center; padding:20px;">Connection Timeout: The server took too long to scan the filesystem.</div>`;
    }
}

async function renderDashboardReport(server, localDocs) {
    window._lastDashboardData = { server, localDocs }; // Cache for debug report
    const list = document.getElementById('sync-health-list');
    list.innerHTML = '';

    const formatSize = (bytes) => {
        if (bytes === 0) return '0 KB';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };

    const addSectionHeader = (title) => {
        const h = document.createElement('div');
        h.style.cssText = 'font-size:10px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin:16px 0 8px 4px;';
        h.innerText = title;
        list.appendChild(h);
    };

    // --- 1. DATA CALCULATION ---
    const cloudCount = server.canvases.length;
    const localCount = localDocs.length;
    const cloudSize = server.stats.db_size || 0;
    // 1. Calculate Vector Size (Documents)
    const localVectorSize = localDocs.reduce((acc, doc) => acc + (doc.data ? doc.data.length : 0), 0);
    
    // 2. Calculate Asset Store Size & Breakdown (Images in IndexedDB)
    const localAssetBreakdown = { pdf: 0, docx: 0, img: 0 };
    let localAssetStoreSize = 0;

    if (localDB) {
        // Map hashes to extensions from snapshot for categorization
        const snap = await getMetadata('full_snapshot');
        const extMap = {};
        if (snap && snap.assets) {
            snap.assets.forEach(a => {
                try {
                    const meta = JSON.parse(a.data);
                    extMap[a.hash] = meta.path.split('.').pop().toLowerCase();
                } catch(e) {}
            });
        }

        localAssetStoreSize = await new Promise(r => {
            const tx = localDB.transaction('assets', 'readonly');
            const store = tx.objectStore('assets');
            const request = store.openCursor();
            request.onsuccess = (e) => {
                const cursor = e.target.result;
                if (cursor) {
                    const sz = cursor.value.data ? cursor.value.data.length : 0;
                    localAssetStoreSize += sz;
                    const ext = extMap[cursor.key] || 'img';
                    if (ext === 'pdf') localAssetBreakdown.pdf += sz;
                    else if (ext === 'docx') localAssetBreakdown.docx += sz;
                    else localAssetBreakdown.img += sz;
                    cursor.continue();
                } else { r(localAssetStoreSize); }
            };
            request.onerror = () => r(0);
        });
    }

    let localTotalUsage = 0, localIdbUsage = 0, localCacheUsage = 0;
    if (navigator.storage && navigator.storage.estimate) {
        const est = await navigator.storage.estimate();
        localTotalUsage = est.usage;
        if (est.usageDetails) {
            localIdbUsage = est.usageDetails.indexedDB || 0;
            localCacheUsage = est.usageDetails.caches || 0;
        }
    }

    // --- NEW: Cache API Deep Breakdown ---
    const cacheBreakdown = { core: 0, libs: 0, manifest: 0, media: 0, other: 0 };
    if ('caches' in window) {
        const keys = await caches.keys();
        for (const key of keys) {
            const cache = await caches.open(key);
            const requests = await cache.keys();
            for (const req of requests) {
                try {
                    const res = await cache.match(req);
                    if (res) {
                        const blob = await res.blob();
                        const size = blob.size;
                        const url = req.url.toLowerCase();
                        const isCDN = url.includes('unpkg.com') || url.includes('cdnjs.cloudflare.com') || url.includes('cdn.jsdelivr.net');
                        
                        if (url.includes('/data/vault/') || url.includes('/data/assets/')) {
                            cacheBreakdown.media += size;
                        } else if (isCDN) {
                            cacheBreakdown.libs += size;
                        } else if (url.includes('.js') || url.includes('.css') || url.includes('.php')) {
                            cacheBreakdown.core += size;
                        } else if (url.includes('manifest.json') || url.includes('.svg') || url.includes('.png') || url.includes('.ico')) {
                            cacheBreakdown.manifest += size;
                        } else {
                            cacheBreakdown.other += size;
                        }
                    }
                } catch(e) { console.warn("Cache scan error", e); }
            }
        }
    }

    const snap = await getMetadata('full_snapshot');
    const localStickerCount = snap ? (snap.stickers || []).length : 0;
    const localPresetCount = (starredSizes || []).length + (window.starredTextSizes || []).length;
    const cloudAssetSize = server.stats.total_size - cloudSize;
    
    // Check for dirty/out-of-date items
    let outOfDate = 0;
    let dirtyCount = 0;
    server.canvases.forEach(sc => {
        const local = localDocs.find(ld => ld.id === 'canvas_' + sc.id);
        const serverVersion = server.versions[sc.id] || 0;
        if (!local) {
            // Canvas exists on server but not in this browser
            outOfDate++;
        } else {
            if (local.dirty) dirtyCount++;
            else if (serverVersion > (local.lastSyncedId || 0)) outOfDate++;
        }
    });

    // --- 2. UNIFIED COMPARISON TABLE ---
    addSectionHeader('System Overview');
    const table = document.createElement('table');
    table.className = 'wb-dashboard-table';
    
    const getStatusIcon = (isOk, isWarn = false) => {
        if (isOk && !isWarn) return '<span style="color:#34c759;">✓</span>';
        if (isWarn) return '<span style="color:#ff9500;">!</span>';
        return '<span style="color:#ff3b30;">✗</span>';
    };

    // Calculate logical totals for the headers so they actually add up
    const cloudMediaVaultTotal = server.stats.total_size - server.stats.db_size;
    const localMediaVaultTotal = localAssetStoreSize;
    const localIdbDataTotal = localVectorSize + localAssetStoreSize;
    const logicalCacheTotal = cacheBreakdown.core + cacheBreakdown.libs + cacheBreakdown.manifest + cacheBreakdown.media + cacheBreakdown.other + (localCacheUsage - (cacheBreakdown.core + cacheBreakdown.libs + cacheBreakdown.manifest + cacheBreakdown.media + cacheBreakdown.other));
    const logicalGrandTotal = localIdbDataTotal + localCacheUsage;

    table.innerHTML = `
        <thead>
            <tr>
                <th>Category</th>
                <th>Cloud (Server)</th>
                <th>Local (Bunker)</th>
                <th class="wb-status-cell">Stat</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Canvases</td>
                <td class="wb-table-val">${cloudCount}</td>
                <td class="wb-table-val">${localCount}</td>
                <td class="wb-status-cell">${getStatusIcon(cloudCount === localCount)}</td>
            </tr>
            <tr>
                <td><b>User Data (IDB)</b></td>
                <td class="wb-table-val">${formatSize(server.stats.total_size)}</td>
                <td class="wb-table-val">${formatSize(localIdbDataTotal)}</td>
                <td class="wb-status-cell">${getStatusIcon(dirtyCount === 0 && outOfDate === 0, dirtyCount > 0 || outOfDate > 0)}</td>
            </tr>
            <tr class="wb-sub-row">
                <td>↳ Strokes (Database)</td>
                <td class="wb-table-val">${formatSize(server.stats.db_size)}</td>
                <td class="wb-table-val">${formatSize(localVectorSize)}</td>
                <td></td>
            </tr>
            <tr class="wb-sub-row">
                <td>↳ Media Vault (Assets)</td>
                <td class="wb-table-val">${formatSize(cloudMediaVaultTotal)}</td>
                <td class="wb-table-val">${formatSize(localMediaVaultTotal)}</td>
                <td></td>
            </tr>
            <tr class="wb-sub-row" style="opacity: 0.5;">
                <td style="padding-left: 40px !important;">↳ PDF Documents</td>
                <td class="wb-table-val">${formatSize(server.stats.asset_breakdown.pdf)}</td>
                <td class="wb-table-val">${formatSize(localAssetBreakdown.pdf)}</td>
                <td></td>
            </tr>
            <tr class="wb-sub-row" style="opacity: 0.5;">
                <td style="padding-left: 40px !important;">↳ Word (DOCX)</td>
                <td class="wb-table-val">${formatSize(server.stats.asset_breakdown.docx)}</td>
                <td class="wb-table-val">${formatSize(localAssetBreakdown.docx)}</td>
                <td></td>
            </tr>
            <tr class="wb-sub-row" style="opacity: 0.5;">
                <td style="padding-left: 40px !important;">↳ Images / Other</td>
                <td class="wb-table-val">${formatSize(server.stats.asset_breakdown.img + server.stats.asset_breakdown.other)}</td>
                <td class="wb-table-val">${formatSize(localAssetBreakdown.img)}</td>
                <td></td>
            </tr>
            <tr>
                <td>App Cache (SW)</td>
                <td class="wb-table-val">--</td>
                <td class="wb-table-val">${formatSize(localCacheUsage)}</td>
                <td class="wb-status-cell">${getStatusIcon(cacheBreakdown.media === 0, cacheBreakdown.media > 0)}</td>
            </tr>
            <tr class="wb-sub-row">
                <td>↳ Core Assets (Code)</td>
                <td class="wb-table-val">--</td>
                <td class="wb-table-val">${formatSize(cacheBreakdown.core)}</td>
                <td></td>
            </tr>
            <tr class="wb-sub-row">
                <td>↳ External Libs (CDN)</td>
                <td class="wb-table-val">--</td>
                <td class="wb-table-val">${formatSize(cacheBreakdown.libs)}</td>
                <td></td>
            </tr>
            <tr class="wb-sub-row">
                <td>↳ Icons & Manifest</td>
                <td class="wb-table-val">--</td>
                <td class="wb-table-val">${formatSize(cacheBreakdown.manifest)}</td>
                <td></td>
            </tr>
            <tr class="wb-sub-row" style="${cacheBreakdown.media > 0 ? 'color: var(--danger); font-weight: 800;' : ''}">
                <td>↳ Media Leak (Redundant)</td>
                <td class="wb-table-val">--</td>
                <td class="wb-table-val">${formatSize(cacheBreakdown.media)}</td>
                <td>${cacheBreakdown.media > 0 ? '⚠️' : ''}</td>
            </tr>
            <tr class="wb-sub-row" style="opacity: 0.4;">
                <td>↳ Browser Overhead</td>
                <td class="wb-table-val">--</td>
                <td class="wb-table-val">${formatSize(Math.max(0, localCacheUsage - (cacheBreakdown.core + cacheBreakdown.libs + cacheBreakdown.manifest + cacheBreakdown.media + cacheBreakdown.other)))}</td>
                <td></td>
            </tr>
            <tr>
                <td><b>Total Data Volume</b></td>
                <td class="wb-table-val">${formatSize(server.stats.total_size)}</td>
                <td class="wb-table-val">${formatSize(logicalGrandTotal)}</td>
                <td class="wb-status-cell" title="Logical sum of all files">📊</td>
            </tr>
            <tr style="background: rgba(52, 199, 89, 0.05); border-top: 1px solid rgba(52, 199, 89, 0.1);">
                <td style="color: #28a745; font-weight: 800;">Actual Disk Usage</td>
                <td class="wb-table-val">${formatSize(server.stats.total_size)}</td>
                <td class="wb-table-val" style="color: #28a745; font-weight: 800;">
                    ${formatSize(localTotalUsage)}
                    <span style="font-size: 8px; background: #28a745; color: white; padding: 1px 4px; border-radius: 4px; margin-left: 4px; vertical-align: middle;">
                        -${Math.round((1 - (localTotalUsage / logicalGrandTotal)) * 100)}%
                    </span>
                </td>
                <td class="wb-status-cell" title="Hardware impact after compression">💾</td>
            </tr>
            <tr>
                <td>Library</td>
                <td class="wb-table-val">${server.stats.stickers} Stk / ${server.stats.presets} Pre</td>
                <td class="wb-table-val">${localStickerCount} Stk / ${localPresetCount} Pre</td>
                <td class="wb-status-cell">${getStatusIcon(localStickerCount === server.stats.stickers)}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="padding:0; border-top:1px solid rgba(0,0,0,0.05);">
                    <div style="display:flex; width:100%;">
                        <button class="tool-btn" onclick="wbSyncAll()" style="flex:1; background:var(--bg-color); border:none; border-radius:0; padding:16px; font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; gap:10px; color:var(--primary-accent); border-right:1px solid rgba(0,0,0,0.05);">
                            <i data-lucide="refresh-cw" style="width:14px;"></i> Sync All
                        </button>
                        <button class="tool-btn" onclick="wbGenerateDebugReport()" style="flex:1; background:var(--bg-color); border:none; border-radius:0; padding:16px; font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; gap:10px; color:var(--text-secondary);">
                            <i data-lucide="terminal" style="width:14px;"></i> Debug Report
                        </button>
                    </div>
                </td>
            </tr>
        </tfoot>
    `;
    list.appendChild(table);

    // --- 3. GALLERY PERFORMANCE (SLIDER) ---
    addSectionHeader('Gallery Performance');
    const perfBox = document.createElement('div');
    perfBox.className = 'db-control-group';
    const currentRes = window._thumbRes || 200;
    const resLabel = currentRes >= 600 ? 'Ultra HD' : (currentRes >= 400 ? 'High' : 'Standard');

    perfBox.innerHTML = `
        <div class="db-slider-header">
            <span style="font-size:13px; font-weight:700;">Thumbnail Resolution</span>
            <span id="db-res-val" style="font-size:11px; font-weight:800; color:var(--primary-accent);">${currentRes}px (${resLabel})</span>
        </div>
        <input type="range" id="db-res-slider" min="100" max="800" step="50" value="${currentRes}" style="width:100%; margin:0;">
    `;
    list.appendChild(perfBox);

    // Slider Event
    setTimeout(() => {
        const slider = document.getElementById('db-res-slider');
        if (slider) {
            slider.oninput = (e) => {
                const val = e.target.value;
                const label = val >= 600 ? 'Ultra HD' : (val >= 400 ? 'High' : 'Standard');
                document.getElementById('db-res-val').innerText = `${val}px (${label})`;
            };
            slider.onchange = (e) => {
                const val = parseInt(e.target.value);
                window._thumbRes = val;
                window._thumbQual = val >= 400 ? 0.8 : 0.5;
                saveSettings({ thumb_res: window._thumbRes, thumb_quality: window._thumbQual });
            };
        }
    }, 10);

    // --- 4. DEVELOPER API ---
    addSectionHeader('Developer API');
    const apiKey = server.api_key || 'Unknown';
    const apiUrl = window.location.href.split('?')[0].replace('index.php', 'api.php');
    const apiBox = document.createElement('div');
    apiBox.style.cssText = 'margin-bottom: 12px; padding: 12px; background: rgba(0,122,255,0.05); border: 1px solid rgba(0,122,255,0.1); border-radius: 16px;';
    apiBox.innerHTML = `
        <div style="font-size:11px; font-weight:700; color:var(--primary-accent); margin-bottom:8px; text-transform:uppercase;">External Asset Integration</div>
        <div style="font-size:10px; color:var(--text-secondary); margin-bottom:4px;">ENDPOINT</div>
        <div class="code-block" style="margin-bottom:12px; font-size:10px; user-select:all;">${apiUrl}</div>
        <div style="display:flex; gap:8px; align-items:center; margin-bottom:16px;">
            <div class="code-block" style="flex:1; margin:0; font-size:10px; color:var(--text-primary); font-weight:800;">${apiKey}</div>
            <button class="tool-btn" onclick="wbCopyText('${apiKey}')" style="padding:4px 10px; font-size:10px; background:var(--card-bg);">Copy Key</button>
        </div>
        <button class="tool-btn" onclick="wbCopyAiInstructions('${apiUrl}', '${apiKey}')" style="width:100%; background:var(--primary-accent); color:white; border:none; font-size:11px; font-weight:800; padding:10px;">
            Copy AI Integration Prompt
        </button>
    `;
    list.appendChild(apiBox);

    // --- 5. MAINTENANCE & AUTOMATION ---
    addSectionHeader('Maintenance & Automation');
    const maintBox = document.createElement('div');
    maintBox.className = 'db-control-group';
    
    const syncInt = window.wb.settings.sync_interval_mins || 1;
    const autoSync = window.wb.settings.auto_sync_enabled !== false;

    maintBox.innerHTML = `
        <div style="margin-bottom: 20px;">
            <div class="db-slider-header">
                <span style="font-size:13px; font-weight:700;">Auto-Sync Interval</span>
                <span id="db-sync-val" style="font-size:11px; font-weight:800; color:var(--primary-accent);">${syncInt}m</span>
            </div>
            <input type="range" id="db-sync-slider" min="1" max="60" step="1" value="${syncInt}" style="width:100%; margin:0;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px;">
                <span style="font-size:12px; font-weight:600;">Enable Background Sync</span>
                <label class="switch" style="width:36px; height:20px;">
                    <input type="checkbox" id="db-auto-sync-toggle" ${autoSync ? 'checked' : ''}>
                    <span class="slider" style="border-radius:20px;"></span>
                </label>
            </div>
        </div>

        <div style="height:1px; background:rgba(0,0,0,0.05); margin:16px 0;"></div>

        <div>
            <div class="db-slider-header">
                <span style="font-size:13px; font-weight:700;">Cleanup Old Canvases</span>
                <span id="db-cleanup-val" style="font-size:11px; font-weight:800; color:#ff3b30;">1 Week</span>
            </div>
            <input type="range" id="db-cleanup-slider" min="1" max="6" step="1" value="1" style="width:100%; margin:0;">
            <button class="tool-btn" onclick="wbCleanupOldCanvases()" style="width:100%; background:#ff3b30; color:white; border:none; font-size:12px; margin-top:12px;">
                Delete Old Canvases Now
            </button>
            <button class="tool-btn" onclick="wbCleanupOldAssets()" style="width:100%; background:var(--bg-color); color:#ff3b30; border:1px solid rgba(255,59,48,0.2); font-size:12px; margin-top:8px;">
                Delete Old Assets Now
            </button>
        </div>
    `;
    list.appendChild(maintBox);

    // Event Listeners for new controls
    setTimeout(() => {
        const syncSlider = document.getElementById('db-sync-slider');
        if (syncSlider) {
            syncSlider.oninput = (e) => { document.getElementById('db-sync-val').innerText = e.target.value + 'm'; };
            syncSlider.onchange = (e) => {
                const val = parseInt(e.target.value);
                window.syncCountdown = val * 60;
                saveSettings({ sync_interval_mins: val });
            };
        }
        const syncToggle = document.getElementById('db-auto-sync-toggle');
        if (syncToggle) {
            syncToggle.onchange = (e) => { saveSettings({ auto_sync_enabled: e.target.checked }); };
        }
        const cleanupSlider = document.getElementById('db-cleanup-slider');
        const cleanupMap = { 1: 7, 2: 14, 3: 21, 4: 28, 5: 60, 6: 90 };
        const cleanupLabels = { 
            1: '1 Week', 
            2: '2 Weeks', 
            3: '3 Weeks', 
            4: '4 Weeks', 
            5: '2 Months', 
            6: '3 Months' 
        };
        if (cleanupSlider) {
            cleanupSlider.oninput = (e) => { document.getElementById('db-cleanup-val').innerText = cleanupLabels[e.target.value]; };
        }

        window.wbCleanupOldCanvases = async () => {
            const days = cleanupMap[document.getElementById('db-cleanup-slider').value];
            const label = cleanupLabels[document.getElementById('db-cleanup-slider').value];
            if (!await wbui.confirm(`Permanently delete all canvases created more than ${label} ago?\n\nThis will NOT delete your Default Canvas (ID 1).`, "Auto-Cleanup", wbIcons.trash)) return;
            
            const pill = document.getElementById('status-pill');
            pill.innerText = "Cleaning up...";
            pill.style.opacity = "1";

            const fd = new FormData();
            fd.append('action', 'cleanup_old_canvases');
            fd.append('days', days);
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                // 1. Wipe the local metadata cache so the gallery fetches fresh data
                await saveMetadata('full_snapshot', null);
                
                // 2. Refresh the Gallery UI if it's open
                if (document.getElementById('gallery-view').style.display === 'flex') {
                    wbRenderGallery();
                }
                
                pill.innerText = "Cleanup Complete";
                wbOpenDashboard(); // Refresh stats
            }
            setTimeout(() => pill.style.opacity = "0", 2000);
        };

        window.wbCleanupOldAssets = async () => {
            const days = cleanupMap[document.getElementById('db-cleanup-slider').value];
            const label = cleanupLabels[document.getElementById('db-cleanup-slider').value];
            const msg = `Permanently delete all assets (images/PDFs) uploaded more than ${label} ago?\n\n⚠️ WARNING: This will break any existing canvases that are still using these assets.`;
            
            if (!await wbui.confirm(msg, "Cleanup Assets", wbIcons.trash)) return;
            
            const pill = document.getElementById('status-pill');
            pill.innerText = "Purging Assets...";
            pill.style.opacity = "1";

            const fd = new FormData();
            fd.append('action', 'cleanup_old_assets');
            fd.append('days', days);
            
            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    // Clear local asset cache to reflect deletions
                    if (localDB) {
                        const tx = localDB.transaction('assets', 'readwrite');
                        tx.objectStore('assets').clear();
                    }
                    wbImageCache = {};
                    
                    pill.innerText = "Assets Purged";
                    wbOpenDashboard(); // Refresh stats
                }
            } catch(e) {
                pill.innerText = "Cleanup Failed";
            }
            setTimeout(() => pill.style.opacity = "0", 2000);
        };
    }, 10);

    // --- 6. PRUNE TRIGGER ---
    const pruneBox = document.createElement('div');
    pruneBox.style.cssText = 'margin-top: 16px; padding: 12px; background: rgba(255,59,48,0.05); border: 1px solid rgba(255,59,48,0.1); border-radius: 16px;';
    pruneBox.innerHTML = `
        <div style="font-size:12px; font-weight:700; color:#ff3b30; margin-bottom:4px;">Database Optimization</div>
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:10px; line-height:1.4;">Pruning deletes previous versions, keeping only the current state.</div>
        <button class="tool-btn" onclick="wbPruneHistory()" style="width:100%; background:#ff3b30; color:white; border:none; font-size:12px;">Prune & Optimize Server DB</button>
    `;
    list.appendChild(pruneBox);
    
    if(window.lucide) lucide.createIcons();
}

async function wbGenerateDebugReport() {
    const pill = document.getElementById('status-pill');
    pill.innerText = "Generating Report...";
    pill.style.opacity = "1";

    const dashData = window._lastDashboardData || {};
    const server = dashData.server || null;

    const formatSize = (bytes) => {
        if (!bytes || bytes === 0) return '0 KB';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };

    let report = "=== WHITEBOARD DEBUG REPORT ===\n";
    report += `Generated: ${new Date().toISOString()}\n`;
    report += `URL: ${window.location.href}\n`;
    report += `Online: ${navigator.onLine}\n`;
    report += `UserAgent: ${navigator.userAgent}\n\n`;

    // 1. Storage Metrics
    report += "--- STORAGE METRICS ---\n";
    const lastSync = localStorage.getItem('wb_last_sync_all');
    report += `Last Global Sync: ${lastSync ? new Date(lastSync).toLocaleString() : 'NEVER'}\n`;
    report += `Active Canvas ID: ${window.currentCanvasId}\n`;

    // Calculate Dirty Count for Report
    let dirtyCount = 0;
    if (dashData.localDocs) {
        dirtyCount = dashData.localDocs.filter(d => d.dirty).length;
    }
    report += `Unsaved (Dirty) Canvases: ${dirtyCount}\n`;

    if (server && server.stats) {
        report += `Cloud DB Size: ${formatSize(server.stats.db_size)}\n`;
        report += ` > Strokes (SQL): ${formatSize(server.stats.stroke_size)}\n`;
        report += `Cloud Total Folder: ${formatSize(server.stats.total_size)}\n`;
        const ab = server.stats.asset_breakdown || {};
        report += ` > PDFs: ${formatSize(ab.pdf)}\n`;
        report += ` > DOCX: ${formatSize(ab.docx)}\n`;
        report += ` > Images: ${formatSize(ab.img)}\n`;
        report += `Cloud Canvas Count: ${server.canvases ? server.canvases.length : '?'}\n`;
    }
    if (navigator.storage && navigator.storage.estimate) {
        const est = await navigator.storage.estimate();
        report += `Total Origin Usage: ${formatSize(est.usage)}\n`;
        
        // Breakdown if available
        if (est.usageDetails) {
            if (est.usageDetails.indexedDB) report += ` > IndexedDB (Bunker): ${formatSize(est.usageDetails.indexedDB)}\n`;
            if (est.usageDetails.caches) report += ` > Cache API (Assets): ${formatSize(est.usageDetails.caches)}\n`;
            if (est.usageDetails.serviceWorkerRegistrations) report += ` > SW Overhead: ${formatSize(est.usageDetails.serviceWorkerRegistrations)}\n`;
        }
        
        report += `Local Quota: ${formatSize(est.quota)}\n`;
    }

    // 2. Service Worker Audit
    report += "\n--- SERVICE WORKER ---\n";
    if ('serviceWorker' in navigator) {
        const reg = await navigator.serviceWorker.getRegistration();
        report += `Registered: ${!!reg}\n`;
        if (reg) {
            report += `Status: ${reg.active ? 'ACTIVE' : (reg.installing ? 'INSTALLING' : 'WAITING')}\n`;
            report += `Scope: ${reg.scope}\n`;
            report += `Controller: ${!!navigator.serviceWorker.controller}\n`;
        }
    } else {
        report += "SW Support: NO (Check HTTPS/Localhost)\n";
    }

    // 3. Cache API Audit
    report += "\n--- CACHE STORAGE ---\n";
    if ('caches' in window) {
        const keys = await caches.keys();
        report += `Caches Found: ${keys.join(', ') || 'NONE'}\n`;
        for (const key of keys) {
            const cache = await caches.open(key);
            const requests = await cache.keys();
            report += `> ${key}: ${requests.length} items\n`;
        }
    }

    // 4. IndexedDB Audit
    report += "\n--- INDEXEDDB (BUNKER DATA) ---\n";
    if (localDB) {
        report += `DB Version: ${localDB.version}\n`;
        const stores = Array.from(localDB.objectStoreNames);
        for (const storeName of stores) {
            const tx = localDB.transaction(storeName, 'readonly');
            const store = tx.objectStore(storeName);
            
            if (storeName === 'documents') {
                const keys = await new Promise(r => {
                    const req = store.getAllKeys();
                    req.onsuccess = () => r(req.result);
                });
                report += `> Store [documents]: ${keys.length} entries\n`;
                report += `  - Cached IDs: ${keys.map(k => k.replace('canvas_','')).join(', ')}\n`;
            } else if (storeName === 'metadata') {
                const snap = await getMetadata('full_snapshot');
                report += `> Store [metadata]: ${snap ? '1' : '0'} entries\n`;
                if (snap) {
                    const snapIds = (snap.canvases || []).map(c => c.id);
                    report += `  - Snapshot Canvas IDs: ${snapIds.join(', ')}\n`;
                    report += `  - Snapshot Assets: ${snap.assets?.length || 0}\n`;
                }
            } else {
                const count = await new Promise(r => {
                    const req = store.count();
                    req.onsuccess = () => r(req.result);
                });
                report += `> Store [${storeName}]: ${count} entries\n`;
            }
        }
    } else {
        report += "IndexedDB: NOT INITIALIZED\n";
    }

    report += "\n=== END REPORT ===";

    // Wrap in triple backticks for easy pasting
    wbCopyText("```\n" + report + "\n```");
    pill.innerText = "Report Copied!";
    setTimeout(() => pill.style.opacity = "0", 2000);
}

async function wbPruneHistory() {
    if (!await wbui.confirm("This will permanently delete the version history for all canvases. You will only keep the latest version. Proceed?", "Prune History", wbIcons.alert)) return;
    
    const pill = document.getElementById('status-pill');
    pill.innerText = "Pruning...";
    pill.style.opacity = "1";

    try {
        const fd = new FormData();
        fd.append('action', 'prune_history');
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data.status === 'success') {
            pill.innerText = "Optimized";
            // Refresh Dashboard to show new size
            wbOpenDashboard();
        }
    } catch(e) {
        pill.innerText = "Error";
    }
    setTimeout(() => pill.style.opacity = "0", 2000);
}