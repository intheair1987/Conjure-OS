if ('scrollRestoration' in history) {
    history.scrollRestoration = 'manual';
}
window.scrollTo(0, 0);

if (!history.state) {
    history.replaceState({ screen: 'home' }, '', '');
}

const findClosestAppItem = (clientX, clientY, isExtracting = false, currentTarget = null) => {
    let selector = '#main-app-grid .app-item:not(.is-placeholder), #dock-app-grid .app-item:not(.is-placeholder)';
    if (window.portalActiveFolderId && !isExtracting) {
        selector = '#folder-app-grid .app-item:not(.is-placeholder)';
    }
    const items = Array.from(document.querySelectorAll(selector));
    let closest = null;
    let minDist = Infinity;
    items.forEach(el => {
        const rect = el.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;
        let dist = Math.pow(clientX - cx, 2) + Math.pow(clientY - cy, 2);
        
        // Hysteresis: Give the current target a mathematical "stickiness" advantage
        // This creates a dead-zone in the corners, preventing diagonal jitter
        if (currentTarget && el === currentTarget) {
            dist -= 1500; 
        }

        if (dist < minDist) {
            minDist = dist;
            closest = el;
        }
    });
    return minDist < 22500 ? closest : null;
};

/* ==========================================================================
   1. SYNTH AUDIO FX ENGINE
   ========================================================================== */
const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
const configAudio = { enabled: localStorage.getItem('portal_audio') !== 'false' };

function unlockAudioContext() {
    if (audioCtx.state === 'suspended') {
        audioCtx.resume().catch(() => {});
    }

    const primeOscillator = audioCtx.createOscillator();
    const primeGain = audioCtx.createGain();
    primeGain.gain.setValueAtTime(0, audioCtx.currentTime);
    primeOscillator.connect(primeGain);
    primeGain.connect(audioCtx.destination);
    primeOscillator.start();
    primeOscillator.stop(audioCtx.currentTime + 0.01);
}

let defaultFrameEnabled = window.innerWidth > 600;
const storedFrame = localStorage.getItem('portal_frame');
if (storedFrame !== null) {
    defaultFrameEnabled = storedFrame === 'true';
}
const configFrame = { enabled: defaultFrameEnabled };
const configInApp = { enabled: localStorage.getItem('portal_inapp') !== 'false' };

if (!configFrame.enabled) document.body.classList.add('frame-disabled');

function playAudioTone(type) {
    if (!configAudio.enabled) return;
    if (audioCtx.state === 'suspended') {
        audioCtx.resume();
    }

    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.connect(gain);
    gain.connect(audioCtx.destination);

    if (type === 'tap') {
        osc.type = 'sine';
        osc.frequency.setValueAtTime(500, audioCtx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(1200, audioCtx.currentTime + 0.05);
        gain.gain.setValueAtTime(0.04, audioCtx.currentTime);
        gain.gain.linearRampToValueAtTime(0.001, audioCtx.currentTime + 0.05);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.05);
    } else if (type === 'longpress') {
        osc.type = 'triangle';
        osc.frequency.setValueAtTime(140, audioCtx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(380, audioCtx.currentTime + 0.18);
        gain.gain.setValueAtTime(0.12, audioCtx.currentTime);
        gain.gain.linearRampToValueAtTime(0.001, audioCtx.currentTime + 0.18);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.18);
    } else if (type === 'panel') {
        osc.type = 'sine';
        osc.frequency.setValueAtTime(650, audioCtx.currentTime);
        osc.frequency.setValueAtTime(850, audioCtx.currentTime + 0.03);
        gain.gain.setValueAtTime(0.02, audioCtx.currentTime);
        gain.gain.linearRampToValueAtTime(0.001, audioCtx.currentTime + 0.1);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.1);
    } else if (type === 'app-open') {
        osc.type = 'sine';
        osc.frequency.setValueAtTime(260, audioCtx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(680, audioCtx.currentTime + 0.25);
        gain.gain.setValueAtTime(0.06, audioCtx.currentTime);
        gain.gain.linearRampToValueAtTime(0.001, audioCtx.currentTime + 0.25);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.25);
    }
}

/* ==========================================================================
   2. APP INITIALIZATION & DOM EVENTS
   ========================================================================== */
let selectedAppContext = null;
window.isEditMode = false;
let currentDropTarget = null;
window.portalFolders = {};

window.isSearchActive = false;

window.portalToggleSearch = function() {
    const container = document.getElementById('portal-search-container');
    const isHidden = container.style.display === 'none';
    if (isHidden) {
        if (window.isEditMode) {
            window.portalToggleEditMode();
            setTimeout(() => {
                window.portalToggleSearch();
            }, 50);
            return;
        }
        container.style.display = 'block';
        document.getElementById('portal-search-input').focus();
        const trigger = document.getElementById('search-trigger');
        if (trigger) trigger.classList.add('active');
        window.isSearchActive = true;
        history.pushState({ screen: 'search' }, '', '');
    } else {
        history.back();
    }
};

window.portalClearSearch = function() {
    const input = document.getElementById('portal-search-input');
    input.value = '';
    window.portalPerformSearch('');
    document.getElementById('portal-search-clear').style.display = 'none';
};

window.portalPerformSearch = function(query) {
    query = query.toLowerCase().trim();
    const mainGrid = document.getElementById('main-app-grid');
    
    document.querySelectorAll('.search-result-app').forEach(el => el.remove());

    if (query === '') {
        document.querySelectorAll('#main-app-grid .app-item, #dock-app-grid .app-item').forEach(el => {
            el.style.display = '';
        });
        window.isSearchActive = false;
        return;
    }

    window.isSearchActive = true;

    document.querySelectorAll('.app-item[data-type="folder"]').forEach(el => {
        el.style.display = 'none';
    });

    document.querySelectorAll('.app-item[data-type="app"]').forEach(el => {
        const nameEl = el.querySelector('.app-name');
        if (nameEl && nameEl.textContent.toLowerCase().includes(query)) {
            el.style.display = '';
        } else {
            el.style.display = 'none';
        }
    });

    const appsInFolders = [];
    for (const folderId in window.portalFolders) {
        window.portalFolders[folderId].apps.forEach(appId => {
            if (!appsInFolders.includes(appId)) appsInFolders.push(appId);
        });
    }

    appsInFolders.forEach(appId => {
        const app = window.CJOS_APPS.find(a => a.id === appId);
        if (app && app.name.toLowerCase().includes(query)) {
            const el = createAppIconElement(app);
            el.classList.add('search-result-app');
            mainGrid.appendChild(el);
        }
    });
};

window.addEventListener('DOMContentLoaded', () => {
    const rawFolders = window.CJOS_FOLDERS;
    if (Array.isArray(rawFolders)) {
        window.portalFolders = {};
    } else {
        window.portalFolders = rawFolders || {};
    }

    const searchInput = document.getElementById('portal-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value;
            document.getElementById('portal-search-clear').style.display = query.length > 0 ? 'block' : 'none';
            window.portalPerformSearch(query);
        });
    }
});

window.portalSaveSettingsGlobal = function() {
    const order = Array.from(document.querySelectorAll('#main-app-grid .app-item:not(.empty-slot):not(.search-result-app)'))
        .map(el => el.getAttribute('data-id'));
        
    const dock = Array.from(document.querySelectorAll('#dock-app-grid .app-item:not(.empty-slot):not(.search-result-app)'))
        .map(el => el.getAttribute('data-id'));
        
    const dockLabels = document.getElementById('dock-labels-toggle').checked;
    const hideStatus = document.getElementById('hide-status-toggle').checked;
    const blurVal = document.getElementById('blur-slider').value;
    const disableWallpaper = document.getElementById('disable-wallpaper-toggle').checked;
    const showBackdrops = document.getElementById('icon-backdrops-toggle').checked;
            
const lineLength = document.getElementById('line-length-range') ? parseInt(document.getElementById('line-length-range').value) : 16;
const boxSize = document.getElementById('box-size-range') ? parseInt(document.getElementById('box-size-range').value) : 2;
                
const payload = {
    action: 'save_layout',
    order: order,
    dock: dock,
    folders: window.portalFolders,
    dock_labels: dockLabels,
    hide_status_on_launch: hideStatus,
    wallpaper_blur: parseInt(blurVal),
    disable_wallpaper: disableWallpaper,
    show_icon_backdrops: showBackdrops,
    line_length: lineLength,
    box_size: boxSize
};fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data && data.success) {
            console.log("[Portal] Layout saved successfully.");
        }
    })
    .catch(err => console.error("[Portal] Save layout error:", err));
};

function bindUnifiedInteraction(el) {
    let longPressTimeout = null;
    let clickLock = false;
    let startPosition = { x: 0, y: 0 };
    let isDraggingPointer = false;
    let hasDragged = false;
    
    let editDragTimer = null;
    let isPickedUp = false;
    
    // Global drag state variables
    let activeDragClone = null;
    let activeDragPlaceholder = null;
    let dragOffsetX = 0;
    let dragOffsetY = 0;
    let folderIntentTimer = null;
    let activeFolderTarget = null;
    let currentDropTarget = null;

    const handleTouchMoveGlobal = (e) => {
        if (isDraggingPointer) {
            e.preventDefault();
        }
    };

    const handlePointerMoveGlobal = (e) => {
        if (!isDraggingPointer) return;
        
        // Edge Auto-Scrolling Logic
        const scrollArea = document.querySelector('.scrollable-screen-area');
        if (scrollArea) {
            const scrollRect = scrollArea.getBoundingClientRect();
            const edgeThreshold = 60;
            let scrollAmount = 0;
            
            if (e.clientY < scrollRect.top + edgeThreshold) {
                scrollAmount = -12;
            } else if (e.clientY > scrollRect.bottom - edgeThreshold) {
                scrollAmount = 12;
            }
            
            if (scrollAmount !== 0) {
                if (!window.autoScrollTimer) {
                    window.autoScrollTimer = setInterval(() => {
                        scrollArea.scrollTop += scrollAmount;
                    }, 16);
                }
            } else {
                if (window.autoScrollTimer) {
                    clearInterval(window.autoScrollTimer);
                    window.autoScrollTimer = null;
                }
            }
        }

        const diffX = e.clientX - startPosition.x;
        const diffY = e.clientY - startPosition.y;
        
        const dragDist = Math.sqrt(diffX * diffX + diffY * diffY);
        if (dragDist >= 15) hasDragged = true;
        
        if (!hasDragged) return;

        const x = e.clientX - dragOffsetX;
        const y = e.clientY - dragOffsetY;
        activeDragClone.style.transform = `translate(${x}px, ${y}px) scale(1.1)`;

        let isExtracting = false;
        if (window.portalActiveFolderId) {
            const panel = document.getElementById('portal-folder-panel');
            if (panel) {
                const panelRect = panel.getBoundingClientRect();
                if (e.clientX < panelRect.left || e.clientX > panelRect.right || e.clientY < panelRect.top || e.clientY > panelRect.bottom) {
                    isExtracting = true;
                }
            }
        }

        const dock = document.getElementById('dock-app-grid');
        const mainGrid = document.getElementById('main-app-grid');
        const folderGrid = document.getElementById('folder-app-grid');
        const containers = [mainGrid, dock, folderGrid];

        if (isExtracting) {
            if (activeDragPlaceholder.parentNode !== mainGrid) {
                window.applyFlip(containers, () => {
                    mainGrid.appendChild(activeDragPlaceholder);
                });
                if (window.navigator && window.navigator.vibrate) window.navigator.vibrate(10);
            }
            return;
        }

        const dropTarget = findClosestAppItem(e.clientX, e.clientY, isExtracting, currentDropTarget);
        if (!dropTarget || dropTarget === activeDragPlaceholder) return;
        currentDropTarget = dropTarget;

        const rect = dropTarget.getBoundingClientRect();
        const relX = e.clientX - rect.left;
        const relY = e.clientY - rect.top;
        const width = rect.width;
        const height = rect.height;

        const isFolderIntent = (relX > width * 0.25 && relX < width * 0.75 && relY > height * 0.25 && relY < height * 0.75);
        const fromType = el.getAttribute('data-type');
        const toType = dropTarget.getAttribute('data-type');
        const toIsEmptySlot = dropTarget.classList.contains('empty-slot');

        if (isFolderIntent && !toIsEmptySlot && fromType !== 'folder' && !window.portalActiveFolderId) {
            if (activeFolderTarget !== dropTarget) {
                if (activeFolderTarget) activeFolderTarget.classList.remove('folder-intent-target');
                activeFolderTarget = dropTarget;
                activeFolderTarget.classList.add('folder-intent-target');
                
                if (folderIntentTimer) clearTimeout(folderIntentTimer);
                folderIntentTimer = setTimeout(() => {
                    if (window.navigator && window.navigator.vibrate) window.navigator.vibrate(20);
                    activeFolderTarget.style.transform = 'scale(1.15)';
                    setTimeout(() => { activeFolderTarget.style.transform = ''; }, 150);
                }, 400);
            }
            return;
        } else {
            if (activeFolderTarget) {
                activeFolderTarget.classList.remove('folder-intent-target');
                activeFolderTarget = null;
                if (folderIntentTimer) clearTimeout(folderIntentTimer);
            }
        }

        let insertAction = (relX < width / 2) ? 'before' : 'after';
        let needsMove = false;
        
        if (insertAction === 'before' && dropTarget.previousSibling !== activeDragPlaceholder) {
            needsMove = true;
        } else if (insertAction === 'after' && dropTarget.nextSibling !== activeDragPlaceholder) {
            needsMove = true;
        }

        if (needsMove) {
            const moveIntentKey = `${dropTarget.getAttribute('data-id')}-${insertAction}`;
            if (window.pendingMoveIntentKey !== moveIntentKey) {
                window.pendingMoveIntentKey = moveIntentKey;
                if (window.domMoveTimer) clearTimeout(window.domMoveTimer);
                
                window.domMoveTimer = setTimeout(() => {
                    const isTargetInDock = dropTarget.parentNode === dock;
                    const isPlaceholderInDock = activeDragPlaceholder.parentNode === dock;

                    if (isTargetInDock && !isPlaceholderInDock) {
                        const dockRealItems = dock.querySelectorAll('.app-item:not(.empty-slot)').length;
                        if (dockRealItems >= 4) return;
                    }

                    window.applyFlip(containers, () => {
                        const oldParent = activeDragPlaceholder.parentNode;
                        const newParent = dropTarget.parentNode;

                        if (insertAction === 'before') {
                            newParent.insertBefore(activeDragPlaceholder, dropTarget);
                        } else {
                            newParent.insertBefore(activeDragPlaceholder, dropTarget.nextSibling);
                        }

                        if (oldParent === dock && newParent !== dock) {
                            const newSlot = document.createElement('div');
                            newSlot.className = 'app-item empty-slot dock-slot';
                            newSlot.innerHTML = '<div class="icon-container empty-icon"></div>';
                            dock.appendChild(newSlot);
                        } else if (oldParent !== dock && newParent === dock) {
                            const emptySlot = dock.querySelector('.empty-slot');
                            if (emptySlot) emptySlot.remove();
                        }
                    });
                    if (window.navigator && window.navigator.vibrate) window.navigator.vibrate(10);
                }, 120); // 120ms dwell time to confirm intent and break feedback loop
            }
        } else {
            // If the user moves the pointer back to a stable position, cancel pending moves
            if (window.domMoveTimer) {
                clearTimeout(window.domMoveTimer);
                window.domMoveTimer = null;
                window.pendingMoveIntentKey = null;
            }
        }
    };

    const handlePointerUpGlobal = (e) => {
        isDraggingPointer = false;
        window.isDraggingApp = false;
        
        if (window.autoScrollTimer) {
            clearInterval(window.autoScrollTimer);
            window.autoScrollTimer = null;
        }
        if (window.domMoveTimer) {
            clearTimeout(window.domMoveTimer);
            window.domMoveTimer = null;
            window.pendingMoveIntentKey = null;
        }
        
        window.removeEventListener('pointermove', handlePointerMoveGlobal);
        window.removeEventListener('pointerup', handlePointerUpGlobal);
        window.removeEventListener('pointercancel', handlePointerCancelGlobal);
        window.removeEventListener('touchmove', handleTouchMoveGlobal);

        if (folderIntentTimer) {
            clearTimeout(folderIntentTimer);
            folderIntentTimer = null;
        }

        if (!hasDragged) {
            if (activeDragClone) activeDragClone.remove();
            activeDragClone = null;
            if (activeDragPlaceholder) activeDragPlaceholder.classList.remove('is-placeholder');
            if (activeFolderTarget) activeFolderTarget.classList.remove('folder-intent-target');
            activeFolderTarget = null;
            
            if (el.getAttribute('data-type') === 'folder') {
                window.amOpenFolder(el.getAttribute('data-id'));
            }
            return;
        }

        if (activeFolderTarget) {
            const toId = activeFolderTarget.getAttribute('data-id');
            const fromId = el.getAttribute('data-id');
            
            if (activeDragClone) activeDragClone.remove();
            activeDragClone = null;
            
            el.classList.remove('is-placeholder');
            activeFolderTarget.classList.remove('folder-intent-target');
            
            window.portalHandleFolderDrop(fromId, toId, activeFolderTarget);
            activeFolderTarget = null;
            return;
        }

        if (activeDragClone && activeDragPlaceholder) {
            const rect = activeDragPlaceholder.getBoundingClientRect();
            activeDragClone.style.transition = 'transform 0.25s cubic-bezier(0.2, 0.8, 0.2, 1)';
            activeDragClone.style.transform = `translate(${rect.left}px, ${rect.top}px) scale(1)`;
            
            setTimeout(() => {
                if (activeDragClone) activeDragClone.remove();
                activeDragClone = null;
                if (activeDragPlaceholder) activeDragPlaceholder.classList.remove('is-placeholder');
                
                if (window.portalActiveFolderId && activeDragPlaceholder.parentNode === document.getElementById('main-app-grid')) {
                    const folderGrid = document.getElementById('folder-app-grid');
                    const newFolderApps = Array.from(folderGrid.querySelectorAll('.app-item:not(.empty-slot)')).map(e => e.getAttribute('data-id'));
                    window.portalFolders[window.portalActiveFolderId].apps = newFolderApps;
                    
                    if (newFolderApps.length === 0) {
                        delete window.portalFolders[window.portalActiveFolderId];
                        const folderEl = document.querySelector(`[data-id="${window.portalActiveFolderId}"]`);
                        if (folderEl) folderEl.remove();
                    } else {
                        portalUpdateFolderIcon(window.portalActiveFolderId);
                    }
                    window.amCloseFolder();
                }

                window.portalSaveSettingsGlobal();
                playAudioTone('tap');
                portalUpdateLabelClasses();
            }, 250);
        }
    };

    const handlePointerCancelGlobal = (e) => {
        isDraggingPointer = false;
        window.isDraggingApp = false;
        
        if (window.autoScrollTimer) {
            clearInterval(window.autoScrollTimer);
            window.autoScrollTimer = null;
        }
        if (window.domMoveTimer) {
            clearTimeout(window.domMoveTimer);
            window.domMoveTimer = null;
            window.pendingMoveIntentKey = null;
        }
        
        window.removeEventListener('pointermove', handlePointerMoveGlobal);
        window.removeEventListener('pointerup', handlePointerUpGlobal);
        window.removeEventListener('pointercancel', handlePointerCancelGlobal);
        window.removeEventListener('touchmove', handleTouchMoveGlobal);
        
        if (folderIntentTimer) {
            clearTimeout(folderIntentTimer);
            folderIntentTimer = null;
        }
        if (activeFolderTarget) {
            activeFolderTarget.classList.remove('folder-intent-target');
            activeFolderTarget = null;
        }

        if (activeDragClone) {
            activeDragClone.remove();
            activeDragClone = null;
        }
        if (activeDragPlaceholder) {
            activeDragPlaceholder.classList.remove('is-placeholder');
        }
    };

    el.addEventListener('pointerdown', (e) => {
        if (e.button === 2) return;

        unlockAudioContext();
        
        clickLock = false;
        hasDragged = false;
        isPickedUp = false;
        startPosition = { x: e.clientX, y: e.clientY };
        el.style.transform = 'scale(0.92)';

        if (window.isEditMode) {
            // Delay the pickup by 200ms to allow native vertical scrolling
            editDragTimer = setTimeout(() => {
                isPickedUp = true;
                isDraggingPointer = true;
                window.isDraggingApp = true;
                if (window.navigator && window.navigator.vibrate) window.navigator.vibrate(20);
                
                const rect = el.getBoundingClientRect();
                activeDragClone = el.cloneNode(true);
                activeDragClone.classList.add('dragging-clone');
                activeDragClone.style.position = 'fixed';
                activeDragClone.style.top = '0';
                activeDragClone.style.left = '0';
                activeDragClone.style.width = `${rect.width}px`;
                activeDragClone.style.height = `${rect.height}px`;
                activeDragClone.style.margin = '0';
                activeDragClone.style.transform = `translate(${rect.left}px, ${rect.top}px) scale(1.1)`;
                activeDragClone.style.animation = 'none';
                
                document.body.appendChild(activeDragClone);
                
                dragOffsetX = e.clientX - rect.left;
                dragOffsetY = e.clientY - rect.top;

                activeDragPlaceholder = el;
                activeDragPlaceholder.classList.add('is-placeholder');
                
                window.addEventListener('pointermove', handlePointerMoveGlobal, { passive: false });
                window.addEventListener('pointerup', handlePointerUpGlobal);
                window.addEventListener('pointercancel', handlePointerCancelGlobal);
                window.addEventListener('touchmove', handleTouchMoveGlobal, { passive: false });
            }, 200);
            return;
        }

        const appJson = el.getAttribute('data-app-json');
        if (appJson) {
            const app = JSON.parse(appJson);
            selectedAppContext = app;
            longPressTimeout = setTimeout(() => {
                clickLock = true;
                playAudioTone('longpress');
                showSystemContextMenu(e.clientX, e.clientY, app, e.pointerId);
            }, 400);
        }
    });

    el.addEventListener('pointermove', (e) => {
        const diffX = e.clientX - startPosition.x;
        const diffY = e.clientY - startPosition.y;

        if (window.isEditMode) {
            if (!isPickedUp) {
                // If finger moves more than 10px before the 200ms pickup, cancel pickup to allow scrolling
                if (Math.sqrt(diffX * diffX + diffY * diffY) > 10) {
                    if (editDragTimer) {
                        clearTimeout(editDragTimer);
                        editDragTimer = null;
                    }
                    el.style.transform = '';
                }
            }
            return;
        }

        const absX = Math.abs(diffX);
        const absY = Math.abs(diffY);

        if (absX > 8 || absY > 8) {
            clickLock = true;
            if (longPressTimeout) {
                clearTimeout(longPressTimeout);
                longPressTimeout = null;
                el.style.transform = '';
                el.classList.remove('holding-wiggle');
            }
        }
    });

    const handleUp = (e) => {
        el.style.transform = '';
        el.style.removeProperty('z-index');
        el.classList.remove('holding-wiggle');
        
        if (longPressTimeout) {
            clearTimeout(longPressTimeout);
            longPressTimeout = null;
        }

        if (window.isEditMode) {
            if (editDragTimer) {
                clearTimeout(editDragTimer);
                editDragTimer = null;
            }
            
            // If we tapped a folder in edit mode without picking it up, open it
            if (!isPickedUp && !hasDragged && el.getAttribute('data-type') === 'folder' && !window.isDraggingApp) {
                window.amOpenFolder(el.getAttribute('data-id'));
            }
            return;
        }

        if (!clickLock) {
            const appJson = el.getAttribute('data-app-json');
            if (appJson) {
                const app = JSON.parse(appJson);
                amLaunch(app.path, el, app.name, app.id);
            } else {
                const folderId = el.getAttribute('data-id');
                if (folderId && el.getAttribute('data-type') === 'folder') {
                    amOpenFolder(folderId);
                }
            }
        }
    };

    const handleCancel = (e) => {
        el.style.transform = '';
        el.style.removeProperty('z-index');
        el.classList.remove('holding-wiggle');
        
        if (longPressTimeout) {
            clearTimeout(longPressTimeout);
            longPressTimeout = null;
        }

        if (window.isEditMode) {
            if (editDragTimer) {
                clearTimeout(editDragTimer);
                editDragTimer = null;
            }
        }
    };

    el.addEventListener('pointerup', handleUp);
    el.addEventListener('pointercancel', handleCancel);
    el.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (window.isEditMode) return;
        const appJson = el.getAttribute('data-app-json');
        if (appJson) {
            const app = JSON.parse(appJson);
            if (longPressTimeout) {
                clearTimeout(longPressTimeout);
                longPressTimeout = null;
            }
            clickLock = true;
            playAudioTone('longpress');
            showSystemContextMenu(e.clientX, e.clientY, app, e.pointerId);
        }
    });
}

window.applyFlip = function(containers, actionFn) {
    const allChildren = [];
    containers.forEach(c => {
        if (c) allChildren.push(...Array.from(c.children));
    });

    const firstRects = new Map();
    allChildren.forEach(c => {
        if (c.nodeType === 1) firstRects.set(c, c.getBoundingClientRect());
    });

    actionFn();

    const newChildren = [];
    containers.forEach(c => {
        if (c) newChildren.push(...Array.from(c.children));
    });

    const lastRects = new Map();
    newChildren.forEach(c => {
        if (c.nodeType === 1) lastRects.set(c, c.getBoundingClientRect());
    });

    newChildren.forEach(c => {
        const first = firstRects.get(c);
        const last = lastRects.get(c);
        if (!first || !last) return;
        const dx = first.left - last.left;
        const dy = first.top - last.top;
        if (dx !== 0 || dy !== 0) {
            c.style.transform = `translate(${dx}px, ${dy}px)`;
            c.style.transition = 'none';
        }
    });

    requestAnimationFrame(() => {
        newChildren.forEach(c => {
            if (firstRects.has(c)) {
                c.style.transform = '';
                c.style.transition = 'transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1)';
            }
        });
        setTimeout(() => {
            newChildren.forEach(c => {
                c.style.transition = '';
                c.style.transform = '';
            });
        }, 300);
    });
};



window.applyFlip = function(containers, actionFn) {
    const allChildren = [];
    containers.forEach(c => {
        if (c) allChildren.push(...Array.from(c.children));
    });

    const firstRects = new Map();
    allChildren.forEach(c => {
        if (c.nodeType === 1) firstRects.set(c, c.getBoundingClientRect());
    });

    actionFn();

    const newChildren = [];
    containers.forEach(c => {
        if (c) newChildren.push(...Array.from(c.children));
    });

    const lastRects = new Map();
    newChildren.forEach(c => {
        if (c.nodeType === 1) lastRects.set(c, c.getBoundingClientRect());
    });

    newChildren.forEach(c => {
        const first = firstRects.get(c);
        const last = lastRects.get(c);
        if (!first || !last) return;
        const dx = first.left - last.left;
        const dy = first.top - last.top;
        if (dx !== 0 || dy !== 0) {
            c.style.transform = `translate(${dx}px, ${dy}px)`;
            c.style.transition = 'none';
        }
    });

    requestAnimationFrame(() => {
        newChildren.forEach(c => {
            if (firstRects.has(c)) {
                c.style.transform = '';
                c.style.transition = 'transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1)';
            }
        });
        setTimeout(() => {
            newChildren.forEach(c => {
                c.style.transition = '';
                c.style.transform = '';
            });
        }, 300);
    });
};





let stagedFromId = null;
let stagedToId = null;
window.portalModalMode = 'create';
let activeRenameFolderId = null;
window.portalActiveFolderId = null;

function portalShowInputModal(fromId, toId, mode = 'create', currentName = '') {
    stagedFromId = fromId;
    stagedToId = toId;
    window.portalModalMode = mode;
    
    const modal = document.getElementById('portal-input-modal');
    const input = document.getElementById('portal-folder-name-input');
    const title = modal.querySelector('.modal-title');
    const confirmBtn = modal.querySelector('.confirm-btn');
    
    if (mode === 'rename') {
        title.textContent = "Rename Folder";
        input.value = currentName;
        confirmBtn.textContent = "Save";
        activeRenameFolderId = fromId;
    } else {
        title.textContent = "New Folder";
        input.value = '';
        confirmBtn.textContent = "Create";
    }
    
    modal.classList.add('active');
    setTimeout(() => input.focus(), 100);
    playAudioTone('panel');
    history.pushState({ screen: 'folder_input' }, '', '');
}

window.portalCloseInputModal = function() {
    const modal = document.getElementById('portal-input-modal');
    modal.classList.remove('active');
    stagedFromId = null;
    stagedToId = null;
}

window.portalCommitModalAction = function() {
    if (window.portalModalMode === 'rename') {
        portalCommitFolderRename();
    } else {
        portalCommitFolderCreation();
    }
};

function portalCommitFolderRename() {
    const input = document.getElementById('portal-folder-name-input');
    const folderName = input.value.trim();
    if (!folderName) {
        showToast("Please enter a folder name");
        return;
    }
    
    const folderId = activeRenameFolderId;
    if (folderId && window.portalFolders[folderId]) {
        window.portalFolders[folderId].name = folderName;
        
        document.getElementById('app-viewport-title').textContent = folderName;
        
        const gridFolderLabel = document.querySelector(`#main-app-grid [data-id="${folderId}"] .app-name`);
        if (gridFolderLabel) {
            gridFolderLabel.textContent = folderName;
        }
        
        const dockFolderLabel = document.querySelector(`#dock-app-grid [data-id="${folderId}"] .app-name`);
        if (dockFolderLabel) {
            dockFolderLabel.textContent = folderName;
        }
    }
    
    portalCloseInputModal();
    window.portalSaveSettingsGlobal();
    playAudioTone('app-open');
    history.back();
}

window.portalCommitFolderCreation = function() {
    const input = document.getElementById('portal-folder-name-input');
    const folderName = input.value.trim();
    if (!folderName) {
        showToast("Please enter a folder name");
        return;
    }

    const newFolderId = 'folder_' + Date.now();
    window.portalFolders[newFolderId] = {
        name: folderName,
        apps: [stagedToId, stagedFromId]
    };

    const fromEl = document.querySelector(`[data-id="${stagedFromId}"]`);
    const toEl = document.querySelector(`[data-id="${stagedToId}"]`);
    
    if (fromEl && toEl) {
        const folderEl = document.createElement('div');
        folderEl.className = 'app-item';
        folderEl.setAttribute('data-id', newFolderId);
        folderEl.setAttribute('data-type', 'folder');
        
        let miniIconsHtml = '';
        const stagedApps = [stagedToId, stagedFromId];
        stagedApps.forEach(appId => {
            const app = window.CJOS_APPS.find(a => a.id === appId);
            if (app) {
                const isSvg = app.icon.trim().startsWith('<svg');
                if (isSvg || app.svg) {
                    const inner = app.svg ? `<img src="${app.svg}" style="width:100%; height:100%; object-fit:contain;" alt="">` : app.icon;
                    miniIconsHtml += `<div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; overflow:hidden; transform:scale(1.05);">${inner}</div>`;
                } else {
                    miniIconsHtml += `<div style="background:${app.color || '#5856D6'}; width:100%; height:100%; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:10px; overflow:hidden;">${app.icon}</div>`;
                }
            }
        });
        
        folderEl.innerHTML = `
            <div class="icon-container" style="background: rgba(255,255,255,0.1); border: 1px solid var(--card-border); display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr; gap:4px; padding:6px;">
                ${miniIconsHtml}
            </div>
            <div class="app-name">${folderName}</div>
        `;
        
        toEl.before(folderEl);
        fromEl.remove();
        toEl.remove();
        
        bindUnifiedInteraction(folderEl);
    }

    portalCloseInputModal();
    window.portalSaveSettingsGlobal();
    playAudioTone('app-open');
    history.back();
}

function portalUpdateLabelClasses() {
    document.querySelectorAll('#main-app-grid .app-name').forEach(el => {
        el.classList.remove('dock-label');
    });
    document.querySelectorAll('#dock-app-grid .app-name').forEach(el => {
        el.classList.add('dock-label');
    });
}

window.portalHandleFolderDrop = function(fromId, toId, targetEl) {
    const toType = targetEl.getAttribute('data-type');
    const fromEl = document.querySelector(`[data-id="${fromId}"]`);
    
    if (toType === 'folder') {
        window.portalFolders[toId].apps.push(fromId);
        if (fromEl) fromEl.remove();
        portalUpdateFolderIcon(toId);
        window.portalSaveSettingsGlobal();
        playAudioTone('tap');
    } else {
        portalShowInputModal(fromId, toId, 'create');
    }
};

window.portalToggleEditMode = function() {
    if (!window.isEditMode) {
        if (window.isSearchActive) {
            window.portalToggleSearch();
            setTimeout(() => {
                window.portalToggleEditMode();
            }, 50);
            return;
        }
        window.isEditMode = true;
        const trigger = document.getElementById('edit-mode-trigger');
        if (trigger) trigger.classList.add('active');
        document.body.classList.add('edit-mode-active');
        playAudioTone('tap');
    } else {
        window.isEditMode = false;
        const trigger = document.getElementById('edit-mode-trigger');
        if (trigger) trigger.classList.remove('active');
        document.body.classList.remove('edit-mode-active');
        playAudioTone('tap');
    }
};

window.addEventListener('pageshow', (event) => {
    document.body.classList.remove('is-launching');
    document.querySelectorAll('.app-item').forEach(el => {
        el.classList.remove('active-target');
        el.style.removeProperty('--tx');
        el.style.removeProperty('--ty');
    });
    dismissSystemContextMenu();
    document.getElementById('app-viewport').classList.remove('open');
});

document.querySelectorAll('.app-item:not(.empty-slot)').forEach(el => {
    bindUnifiedInteraction(el);
});

function createAppIconElement(app) {
    const container = document.createElement('div');
    container.className = 'app-item';
    container.setAttribute('data-id', app.id);
    container.setAttribute('data-type', 'app');
    container.setAttribute('data-app-json', JSON.stringify(app));
    
    const iconWrap = document.createElement('div');
    iconWrap.className = 'icon-container';
    iconWrap.style.background = app.color || '#5856D6';
    
    if (app.svg) {
        iconWrap.innerHTML = `<img src="${app.svg}" style="width:100%; height:100%; object-fit:contain; display:block;" alt="">`;
    } else {
        iconWrap.innerHTML = app.icon;
    }
    
    container.appendChild(iconWrap);
    
    const label = document.createElement('span');
    label.className = 'app-name';
    label.textContent = app.name;
    container.appendChild(label);
    
    bindUnifiedInteraction(container);
    return container;
}

// Legacy bindInteractionEvents replaced by bindUnifiedInteraction

/* ==========================================================================
   3. FOLDER & LAUNCH LOGIC
   ========================================================================== */
function portalUpdateWallpaperPauseState() {
    const appViewport = document.getElementById('app-viewport');
    const appLaunchViewport = document.getElementById('app-launch-viewport');
    const isCovered = (appViewport && appViewport.classList.contains('open')) ||
                       (appLaunchViewport && appLaunchViewport.classList.contains('open'));
    document.body.classList.toggle('wallpaper-paused', document.hidden || isCovered);
}
document.addEventListener('visibilitychange', portalUpdateWallpaperPauseState);

function portalCheckStatusVisibility(isOpenAction = false) {
    const toggle = document.getElementById('hide-status-toggle');
    const shouldHide = toggle && toggle.checked;
    
    if (shouldHide && isOpenAction) {
        document.body.classList.add('viewport-active-hide-status');
    } else {
        document.body.classList.remove('viewport-active-hide-status');
    }
}

function portalUpdateFolderIcon(folderId) {
    const folderEl = document.querySelector(`[data-id="${folderId}"]`);
    if (!folderEl) return;
    const folder = window.portalFolders[folderId];
    if (!folder) return;
    
    let miniIconsHtml = '';
    const count = Math.min(4, folder.apps.length);
    for (let i = 0; i < count; i++) {
        const app = window.CJOS_APPS.find(a => a.id === folder.apps[i]);
        if (app) {
            const isSvg = app.icon.trim().startsWith('<svg');
            if (isSvg || app.svg) {
                const inner = app.svg ? `<img src="${app.svg}" style="width:100%; height:100%; object-fit:contain;" alt="">` : app.icon;
                miniIconsHtml += `<div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; overflow:hidden; transform:scale(1.05);">${inner}</div>`;
            } else {
                miniIconsHtml += `<div style="background:${app.color || '#5856D6'}; width:100%; height:100%; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:10px; overflow:hidden;">${app.icon}</div>`;
            }
        }
    }
    const iconContainer = folderEl.querySelector('.icon-container');
    if (iconContainer) iconContainer.innerHTML = miniIconsHtml;
}

window.amOpenFolder = function(folderId) {
    playAudioTone('app-open');
    window.portalActiveFolderId = folderId;
    const folder = window.portalFolders[folderId] || window.CJOS_FOLDERS[folderId];
    if (!folder) return;
    
    const input = document.getElementById('portal-folder-name');
    input.value = folder.name;
    input.readOnly = !window.isEditMode;
    
    const grid = document.getElementById('folder-app-grid');
    grid.innerHTML = '';
    
    folder.apps.forEach(appId => {
        const app = window.CJOS_APPS.find(a => a.id === appId);
        if (app) {
            const el = createAppIconElement(app);
            grid.appendChild(el);
        }
    });
    
    const overlay = document.getElementById('portal-folder-overlay');
    overlay.classList.add('active');
    history.pushState({ screen: 'folder' }, '', '');
};

window.amCloseFolder = function() {
    const overlay = document.getElementById('portal-folder-overlay');
    if (overlay && overlay.classList.contains('active')) {
        overlay.classList.remove('active');
        window.portalActiveFolderId = null;
        setTimeout(() => {
            document.getElementById('folder-app-grid').innerHTML = '';
        }, 300);
    }
};

// Sync live folder name changes
document.getElementById('portal-folder-name').addEventListener('change', (e) => {
    if (window.portalActiveFolderId && window.portalFolders[window.portalActiveFolderId]) {
        const newName = e.target.value.trim() || 'Folder';
        window.portalFolders[window.portalActiveFolderId].name = newName;
        
        const folderEl = document.querySelector(`[data-id="${window.portalActiveFolderId}"] .app-name`);
        if (folderEl) folderEl.textContent = newName;
        
        window.portalSaveSettingsGlobal();
    }
});

document.getElementById('app-back-btn').addEventListener('click', triggerBackNavigation);
document.getElementById('app-launch-back-btn').addEventListener('click', triggerBackNavigation);

document.getElementById('home-indicator-bar').addEventListener('click', () => {
    if (document.getElementById('control-panel').classList.contains('open') ||
        document.getElementById('app-viewport').classList.contains('open') ||
        document.getElementById('app-launch-viewport').classList.contains('open')) {
        history.back();
    }
});

window.addEventListener('popstate', (e) => {
    const stateScreen = (e.state && e.state.screen) ? e.state.screen : 'home';
    const controlPanel = document.getElementById('control-panel');
    const searchContainer = document.getElementById('portal-search-container');
    const panelBackdrop = document.getElementById('panel-backdrop');
    const appViewport = document.getElementById('app-viewport');
    const appLaunchViewport = document.getElementById('app-launch-viewport');
    const contextMenu = document.getElementById('custom-context-menu');
    const contextBackdrop = document.getElementById('context-backdrop');
    const folderOverlay = document.getElementById('portal-folder-overlay');
    const folderInputModal = document.getElementById('portal-input-modal');

    let closedSomething = false;

    if (stateScreen !== 'search' && searchContainer && searchContainer.style.display !== 'none') {
        searchContainer.style.display = 'none';
        window.portalClearSearch();
        const trigger = document.getElementById('search-trigger');
        if (trigger) trigger.classList.remove('active');
        closedSomething = true;
    }

    if (stateScreen !== 'settings' && controlPanel && controlPanel.classList.contains('open')) {
        controlPanel.classList.remove('open');
        if (panelBackdrop) panelBackdrop.classList.remove('active');
        closedSomething = true;
    }

    if (stateScreen !== 'folder' && appViewport && appViewport.classList.contains('open')) {
        appViewport.classList.remove('open');
        portalCheckStatusVisibility(false);
        closedSomething = true;
    }

    if (stateScreen !== 'folder' && stateScreen !== 'app' && folderOverlay && folderOverlay.classList.contains('active')) {
        window.amCloseFolder();
        closedSomething = true;
    }

    if (stateScreen !== 'app' && appLaunchViewport && appLaunchViewport.classList.contains('open')) {
        appLaunchViewport.classList.remove('open');
        portalCheckStatusVisibility(false);
        setTimeout(() => { 
            const body = document.getElementById('app-launch-body');
            if (body) body.innerHTML = ''; 
        }, 400);
        closedSomething = true;
    }

    if (stateScreen !== 'context' && contextMenu && contextMenu.classList.contains('active')) {
        contextMenu.classList.remove('active');
        if (contextBackdrop) contextBackdrop.classList.remove('active');
        closedSomething = true;
    }

    if (stateScreen !== 'folder_input' && folderInputModal && folderInputModal.classList.contains('active')) {
        folderInputModal.classList.remove('active');
        stagedFromId = null;
        stagedToId = null;
        closedSomething = true;
    }

    if (closedSomething) {
        playAudioTone('tap');
    }

    portalUpdateWallpaperPauseState();
});

function amLaunch(path, el = null, appName = 'App', appId = null) {
    if (document.body.classList.contains('is-launching')) return;
    
    playAudioTone('app-open');

    // 1. If "Prefer Native App" (configInApp.enabled) is ON and appId is available
    if (configInApp.enabled && appId) {
        const cleanId = (appId || '').toLowerCase().trim();
        const pkgName = 'com.wrapper.appmaker.' + cleanId;
        const componentName = pkgName + '/.MainActivity';
        let isNativeInstalled = false;

        if (window.Android && typeof window.Android.isAppInstalled === 'function') {
            isNativeInstalled = window.Android.isAppInstalled(pkgName);
        }

        if (isNativeInstalled) {
            if (window.Android && typeof window.Android.launchApp === 'function') {
                const launched = window.Android.launchApp(pkgName);
                if (launched) {
                    amShowToast("Launching native " + appName + "...");
                    dismissSystemContextMenu();
                    return;
                }
            }
            if (window.Service && typeof window.Service.executeSmartCommand === 'function') {
                const res = window.Service.executeSmartCommand('am start -n ' + componentName);
                if (res && !res.includes('ERROR')) {
                    amShowToast("Launching native " + appName + "...");
                    dismissSystemContextMenu();
                    return;
                }
            }
        } else if (window.Service && typeof window.Service.executeSmartCommand === 'function') {
            const res = window.Service.executeSmartCommand('am start -n ' + componentName);
            if (res && !res.includes('ERROR')) {
                amShowToast("Launching native " + appName + "...");
                dismissSystemContextMenu();
                return;
            }
        }
    }

    // 2. Fallback or when Prefer Native App is OFF: Open inside Portal in-app viewport iframe
    portalCheckStatusVisibility(true);
    const titleEl = document.getElementById('app-launch-title');
    if (titleEl) titleEl.textContent = appName;
    const body = document.getElementById('app-launch-body');
    if (body) {
        body.innerHTML = `<iframe id="app-iframe" src="${path}" style="width:100%; height:100%; border:none; background:var(--screen-bg);">`;
    }
    const launchViewport = document.getElementById('app-launch-viewport');
    if (launchViewport) launchViewport.classList.add('open');
    portalUpdateWallpaperPauseState();
    dismissSystemContextMenu();
    history.pushState({ screen: 'app' }, '', '');
}

/* ==========================================================================
   4. CONTEXT MENU & SHARING
   ========================================================================== */
window.lastContextTriggerX = 0;
window.lastContextTriggerY = 0;
window.portalContextMenuSuppressPointerId = null;
window.portalContextMenuSuppressClickUntil = 0;

const suppressPortalPointerEnd = (e) => {
    if (window.portalContextMenuSuppressPointerId === null) return;
    if (e.pointerId !== window.portalContextMenuSuppressPointerId) return;

    e.preventDefault();
    e.stopImmediatePropagation();
    window.portalContextMenuSuppressPointerId = null;
    window.portalContextMenuSuppressClickUntil = performance.now() + 500;
};

document.addEventListener('pointerup', suppressPortalPointerEnd, true);
document.addEventListener('pointercancel', suppressPortalPointerEnd, true);

document.addEventListener('click', (e) => {
    if (performance.now() > window.portalContextMenuSuppressClickUntil) return;

    const menu = document.getElementById('custom-context-menu');
    if (!menu || !menu.contains(e.target)) return;

    const dx = e.clientX - window.lastContextTriggerX;
    const dy = e.clientY - window.lastContextTriggerY;
    if (Math.sqrt(dx * dx + dy * dy) > 24) return;

    e.preventDefault();
    e.stopImmediatePropagation();
    window.portalContextMenuSuppressClickUntil = 0;
}, true);

function portalClampContextMenu(x, y) {
    const menu = document.getElementById('custom-context-menu');
    if (!menu) return;
    
    const origDisplay = menu.style.display;
    const origVisibility = menu.style.visibility;
    
    menu.style.display = 'block';
    menu.style.visibility = 'hidden';
    
    const menuWidth = menu.offsetWidth || 180;
    const menuHeight = menu.offsetHeight || 160;
    
    menu.style.display = origDisplay;
    menu.style.visibility = origVisibility;
    
    const screenRect = document.getElementById('phone-screen').getBoundingClientRect();
    let computedX = x - screenRect.left;
    let computedY = y - screenRect.top;
    
    const margin = 12;
    
    if (computedX + menuWidth > screenRect.width - margin) {
        computedX = x - screenRect.left - menuWidth;
    }
    if (computedY + menuHeight > screenRect.height - margin) {
        computedY = y - screenRect.top - menuHeight;
    }
    
    if (computedX < margin) computedX = margin;
    if (computedY < margin) computedY = margin;
    if (computedX + menuWidth > screenRect.width - margin) {
        computedX = screenRect.width - menuWidth - margin;
    }
    if (computedY + menuHeight > screenRect.height - margin) {
        computedY = screenRect.height - menuHeight - margin;
    }
    
    menu.style.left = `${computedX}px`;
    menu.style.top = `${computedY}px`;
}

function showSystemContextMenu(x, y, app, pointerId = null) {
    window.lastContextTriggerX = x;
    window.lastContextTriggerY = y;
    window.portalContextMenuSuppressPointerId = pointerId;

    const menu = document.getElementById('custom-context-menu');
    const backdrop = document.getElementById('context-backdrop');
    
    menu.innerHTML = `
        <div class="context-item" onclick="amLaunch('${app.path}', null, '${app.name.replace(/'/g, "\\'")}', '${app.id.replace(/'/g, "\\'")}')">
            <span class="context-icon" style="-webkit-mask-image: url('icons/sparkles.svg'); mask-image: url('icons/sparkles.svg');"></span>
            <span>Open in Portal</span>
        </div>
        <div class="context-item" onclick="amLaunchNativeApp('${app.id.replace(/'/g, "\\'")}')">
            <span class="context-icon" style="-webkit-mask-image: url('icons/external-link.svg'); mask-image: url('icons/external-link.svg');"></span>
            <span>Open App</span>
        </div>
        <div class="context-item" onclick="window.open('${app.path}', '_blank')">
            <span class="context-icon" style="-webkit-mask-image: url('icons/external-link.svg'); mask-image: url('icons/external-link.svg');"></span>
            <span>Open in New Tab</span>
        </div>
        <div class="context-item" onclick="amShowCopyOptions('${app.path}', '${app.name.replace(/'/g, "\\'")}')">
            <span class="context-icon" style="-webkit-mask-image: url('icons/link.svg'); mask-image: url('icons/link.svg');"></span>
            <span>Copy URL</span>
        </div>
        <div class="context-item" style="color: #ef4444;" onclick="amDeleteApp('${app.id}')">
            <span class="context-icon" style="-webkit-mask-image: url('icons/trash-2.svg'); mask-image: url('icons/trash-2.svg');"></span>
            <span>Delete App</span>
        </div>
    `;
    
    portalClampContextMenu(x, y);
    
    if (backdrop) backdrop.classList.add('active');
    menu.classList.add('active');
    history.pushState({ screen: 'context' }, '', '');
}

function dismissSystemContextMenu() {
    document.getElementById('custom-context-menu').classList.remove('active');
    const backdrop = document.getElementById('context-backdrop');
    if (backdrop) backdrop.classList.remove('active');
}

function amGetAlternativeUrls(appPath) {
    const runtime = window.CJOS_RUNTIME || {};
    const isRuntime = !!runtime.is_runtime || (typeof window.RuntimeBridge !== 'undefined');

    const baseDir = window.location.pathname.split('index.php')[0];
    const rawPath = baseDir + appPath;
    
    const parts = rawPath.split('/');
    const cleanParts = [];
    for (let p of parts) {
        if (p === '..') cleanParts.pop();
        else if (p !== '.' && p !== '') cleanParts.push(p);
    }
    const resolvedPath = '/' + cleanParts.join('/');
    
    const urls = [];

    if (isRuntime) {
        const httpPortStr = (runtime.http_port && runtime.http_port !== 80) ? `:${runtime.http_port}` : '';
        const httpsPortStr = (runtime.https_port && runtime.https_port !== 443) ? `:${runtime.https_port}` : '';
        const mdnsHost = runtime.mdns_host || 'conjure.local';

        urls.push({
            label: `mDNS HTTP (${mdnsHost}${httpPortStr})`,
            url: `http://${mdnsHost}${httpPortStr}${resolvedPath}`
        });

        urls.push({
            label: `mDNS HTTPS (${mdnsHost}${httpsPortStr})`,
            url: `https://${mdnsHost}${httpsPortStr}${resolvedPath}`
        });

        const tsDomain = runtime.tailscale_domain || (window.CJOS_IPS && window.CJOS_IPS.tailscale_domain);
        if (tsDomain) {
            let cleanDomain = tsDomain.replace(/^https?:\/\//, '');
            if (!cleanDomain.includes(':')) {
                if (runtime.https_port && runtime.https_port !== 443) {
                    cleanDomain += `:${runtime.https_port}`;
                } else if (window.location.port && window.location.port !== '80' && window.location.port !== '443') {
                    cleanDomain += `:${window.location.port}`;
                }
            }
            urls.push({
                label: `Tailscale (${cleanDomain})`,
                url: `https://${cleanDomain}${resolvedPath}`
            });
        }
    } else {
        urls.push({
            label: `Current (${window.location.host})`,
            url: window.location.origin + resolvedPath
        });
    }
    
    return urls;
}

function amShowCopyOptions(appPath, appName) {
    const urls = amGetAlternativeUrls(appPath);
    const menu = document.getElementById('custom-context-menu');
    let html = `<div style="padding: 10px 14px; font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase;">Copy URL</div>`;
    urls.forEach(opt => {
        const escapedUrl = opt.url.replace(/'/g, "\\'");
        html += `
            <div class="context-item" onclick="amCopy('${escapedUrl}')">
                <span class="context-icon" style="-webkit-mask-image: url('icons/copy.svg'); mask-image: url('icons/copy.svg');"></span>
                <span>${opt.label}</span>
            </div>`;
    });
    html += `
        <div class="context-item" onclick="dismissSystemContextMenu()">
            <span class="context-icon" style="-webkit-mask-image: url('icons/x.svg'); mask-image: url('icons/x.svg');"></span>
            <span>Cancel</span>
        </div>`;
    menu.innerHTML = html;
    
    // Instantly re-evaluate bounds for the newly expanded height list
    portalClampContextMenu(window.lastContextTriggerX, window.lastContextTriggerY);
}



function amCopy(text) {
    navigator.clipboard.writeText(text).then(() => {
        dismissSystemContextMenu();
        const t = document.getElementById('toast');
        t.innerText = "URL Copied";
        t.classList.add('active');
        setTimeout(() => t.classList.remove('active'), 2000);
    });
}

function amLaunchNativeApp(appId) {
    dismissSystemContextMenu();
    const cleanId = (appId || '').toLowerCase().trim();
    const pkgName = 'com.wrapper.appmaker.' + cleanId;
    const componentName = pkgName + '/.MainActivity';
    
    // 1. FloatAssist / ServiceBridge execution
    if (window.Service && typeof window.Service.executeSmartCommand === 'function') {
        const res = window.Service.executeSmartCommand('am start -n ' + componentName);
        if (res && !res.includes('ERROR')) {
            amShowToast("Launching native " + appId + "...");
            return;
        }
    }

    // 2. Native Android WebBridge JNI execution
    if (window.Android && typeof window.Android.isAppInstalled === 'function') {
        const isInstalled = window.Android.isAppInstalled(pkgName);
        if (isInstalled && typeof window.Android.launchApp === 'function') {
            const launched = window.Android.launchApp(pkgName);
            if (launched) {
                amShowToast("Launching native " + appId + "...");
                return;
            }
        }
        amShowToast("Native app not installed for " + appId);
        return;
    }

    if (window.Android && typeof window.Android.launchApp === 'function') {
        const launched = window.Android.launchApp(pkgName);
        if (launched) {
            amShowToast("Launching native " + appId + "...");
            return;
        }
        amShowToast("Native app not installed for " + appId);
        return;
    }

    amShowToast("Native app not installed for " + appId);
}

function amShowToast(msg) {
    const t = document.getElementById('toast');
    if (t) {
        t.innerText = msg;
        t.classList.add('active');
        setTimeout(() => t.classList.remove('active'), 2500);
    }
}

function amDeleteApp(id) {
    if (!confirm(`Permanently delete "${id}"?`)) return;
    alert("Deletion must be performed from the main system settings.");
    dismissSystemContextMenu();
}

/* ==========================================================================
   5. SETTINGS, CLOCK & GREETING
   ========================================================================== */
function updateClockTime() {
    const now = new Date();
    let hours = now.getHours();
    let minutes = now.getMinutes();
    minutes = minutes < 10 ? '0' + minutes : minutes;
    document.getElementById('status-time').textContent = `${hours}:${minutes}`;
}

function updateDynamicGreeting() {
    const currentHour = new Date().getHours();
    let greeting = 'Good Evening';
    if (currentHour < 12) greeting = 'Good Morning';
    else if (currentHour < 17) greeting = 'Good Afternoon';
    document.getElementById('dash-greeting').textContent = greeting;
}

setInterval(updateClockTime, 10000);
setInterval(updateDynamicGreeting, 60000);
updateClockTime();
updateDynamicGreeting();

// --- Live Telemetry Status Bar Controllers ---
function updateStatusBarBattery() {
    if (typeof navigator.getBattery === 'function') {
        navigator.getBattery().then(battery => {
            const level = Math.round(battery.level * 100);
            const isCharging = battery.charging;
            
            const batteryLevelFill = document.getElementById('status-battery-fill');
            if (batteryLevelFill) {
                batteryLevelFill.style.width = `${level}%`;
                if (isCharging) {
                    batteryLevelFill.style.backgroundColor = '#10b981'; // Green charging
                } else if (level <= 20) {
                    batteryLevelFill.style.backgroundColor = '#ef4444'; // Red low
                } else if (level <= 50) {
                    batteryLevelFill.style.backgroundColor = '#f59e0b'; // Yellow medium
                } else {
                    batteryLevelFill.style.backgroundColor = 'currentColor'; // Standard theme color
                }
            }
            
            // Real-time Event listeners
            battery.onlevelchange = () => updateStatusBarBattery();
            battery.onchargingchange = () => updateStatusBarBattery();
        });
    }
}

function updateStatusBarConnection() {
    const isOnline = navigator.onLine;
    const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    
    const wifiIcon = document.getElementById('status-wifi-icon');
    
    if (!isOnline) {
        if (wifiIcon) wifiIcon.style.opacity = '0.2';
        setSignalBars(0);
        return;
    }
    
    if (conn) {
        const type = conn.type;
        const effectiveType = conn.effectiveType;
        
        if (type === 'wifi') {
            if (wifiIcon) wifiIcon.style.opacity = '1';
            setSignalBars(3);
        } else if (type === 'cellular') {
            if (wifiIcon) wifiIcon.style.opacity = '0.2';
            
            if (effectiveType === '4g') setSignalBars(4);
            else if (effectiveType === '3g') setSignalBars(3);
            else setSignalBars(2);
        } else {
            if (wifiIcon) wifiIcon.style.opacity = '1';
            setSignalBars(4);
        }
    } else {
        if (wifiIcon) wifiIcon.style.opacity = '1';
        setSignalBars(4);
    }
}

function setSignalBars(activeCount) {
    for (let i = 1; i <= 4; i++) {
        const bar = document.getElementById(`sig-bar-${i}`);
        if (bar) {
            bar.style.opacity = (i <= activeCount) ? '1' : '0.25';
        }
    }
}

// Initialize live status bar monitoring
updateStatusBarBattery();
updateStatusBarConnection();

window.addEventListener('online', updateStatusBarConnection);
window.addEventListener('offline', updateStatusBarConnection);
if (navigator.connection) {
    navigator.connection.addEventListener('change', updateStatusBarConnection);
}

function triggerBackNavigation() {
    history.back();
}

document.getElementById('settings-trigger').addEventListener('click', () => {
    playAudioTone('panel');
    document.getElementById('control-panel').classList.add('open');
    document.getElementById('panel-backdrop').classList.add('active');
    history.pushState({ screen: 'settings' }, '', '');
});

document.getElementById('panel-close').addEventListener('click', triggerBackNavigation);
document.getElementById('panel-backdrop').addEventListener('pointerdown', triggerBackNavigation);
document.getElementById('context-backdrop').addEventListener('pointerdown', triggerBackNavigation);
document.getElementById('portal-folder-overlay').addEventListener('pointerdown', (e) => {
    if (e.target.id === 'portal-folder-overlay') {
        history.back();
    }
});

document.getElementById('audio-toggle').checked = configAudio.enabled;
document.getElementById('audio-toggle').addEventListener('change', (e) => {
    configAudio.enabled = e.target.checked;
    localStorage.setItem('portal_audio', e.target.checked);
    playAudioTone('tap');
});

const savedTheme = localStorage.getItem('portal_theme') || 'theme-midnight';
document.body.classList.remove('theme-midnight', 'theme-paper');
document.body.classList.add(savedTheme);
if (!configFrame.enabled) document.body.classList.add('frame-disabled');
document.getElementById('theme-toggle').checked = (savedTheme === 'theme-paper');

document.getElementById('theme-toggle').addEventListener('change', (e) => {
    playAudioTone('panel');
    const theme = e.target.checked ? 'theme-paper' : 'theme-midnight';
    document.body.classList.remove('theme-midnight', 'theme-paper');
    document.body.classList.add(theme);
    if (!configFrame.enabled) document.body.classList.add('frame-disabled');
    localStorage.setItem('portal_theme', theme);
});

const renameBtn = document.getElementById('folder-rename-btn');
if (renameBtn) {
    renameBtn.addEventListener('click', () => {
        if (window.portalActiveFolderId && window.portalFolders[window.portalActiveFolderId]) {
            portalShowInputModal(window.portalActiveFolderId, null, 'rename', window.portalFolders[window.portalActiveFolderId].name);
        }
    });
}

const savedDockLabels = window.CJOS_AM_CONFIG && window.CJOS_AM_CONFIG.dock_labels === true;
const dockLabelsToggle = document.getElementById('dock-labels-toggle');
if (dockLabelsToggle) {
    dockLabelsToggle.checked = savedDockLabels;
    document.body.classList.toggle('dock-labels-enabled', savedDockLabels);
    dockLabelsToggle.addEventListener('change', (e) => {
        playAudioTone('tap');
        document.body.classList.toggle('dock-labels-enabled', e.target.checked);
        window.portalSaveSettingsGlobal();
    });
}

const savedHideStatus = window.CJOS_AM_CONFIG && window.CJOS_AM_CONFIG.hide_status_on_launch === true;
const hideStatusToggle = document.getElementById('hide-status-toggle');
if (hideStatusToggle) {
    hideStatusToggle.checked = savedHideStatus;
    hideStatusToggle.addEventListener('change', (e) => {
        playAudioTone('tap');
        window.portalSaveSettingsGlobal();
    });
}

const savedBlur = window.CJOS_AM_CONFIG && window.CJOS_AM_CONFIG.wallpaper_blur !== undefined ? window.CJOS_AM_CONFIG.wallpaper_blur : 40;
const blurSlider = document.getElementById('blur-slider');
const rangeValBlur = document.getElementById('range-val-blur');
if (blurSlider) {
    blurSlider.value = savedBlur;
    if (rangeValBlur) rangeValBlur.textContent = `${savedBlur}px`;
    document.body.style.setProperty('--wallpaper-blur', `${savedBlur}px`);
    
    blurSlider.addEventListener('input', (e) => {
        const val = e.target.value;
        if (rangeValBlur) rangeValBlur.textContent = `${val}px`;
        document.body.style.setProperty('--wallpaper-blur', `${val}px`);
    });
    
    blurSlider.addEventListener('change', () => {
        window.portalSaveSettingsGlobal();
    });
}

function portalUpdateWallpaperSliderState() {
    const toggle = document.getElementById('disable-wallpaper-toggle');
    const slider = document.getElementById('blur-slider');
    const sliderControl = slider ? slider.closest('.setting-control') : null;
    if (toggle && slider && sliderControl) {
        if (toggle.checked) {
            slider.setAttribute('disabled', 'true');
            sliderControl.style.opacity = '0.4';
            sliderControl.style.pointerEvents = 'none';
        } else {
            slider.removeAttribute('disabled');
            sliderControl.style.opacity = '1';
            sliderControl.style.pointerEvents = 'auto';
        }
    }
}

const savedDisableWallpaper = window.CJOS_AM_CONFIG && window.CJOS_AM_CONFIG.disable_wallpaper === true;
const disableWallpaperToggle = document.getElementById('disable-wallpaper-toggle');
if (disableWallpaperToggle) {
    disableWallpaperToggle.checked = savedDisableWallpaper;
    document.body.classList.toggle('wallpaper-disabled', savedDisableWallpaper);
    portalUpdateWallpaperSliderState();
    
    disableWallpaperToggle.addEventListener('change', (e) => {
        playAudioTone('tap');
        document.body.classList.toggle('wallpaper-disabled', e.target.checked);
        portalUpdateWallpaperSliderState();
        window.portalSaveSettingsGlobal();
    });
}

const savedIconBackdrops = window.CJOS_AM_CONFIG && window.CJOS_AM_CONFIG.show_icon_backdrops !== false;
const iconBackdropsToggle = document.getElementById('icon-backdrops-toggle');
if (iconBackdropsToggle) {
    iconBackdropsToggle.checked = savedIconBackdrops;
    document.body.classList.toggle('hide-icon-backdrops', !savedIconBackdrops);
    
    iconBackdropsToggle.addEventListener('change', (e) => {
        playAudioTone('tap');
        document.body.classList.toggle('hide-icon-backdrops', !e.target.checked);
        window.portalSaveSettingsGlobal();
    });
}

document.getElementById('frame-toggle').checked = configFrame.enabled;
document.getElementById('frame-toggle').addEventListener('change', (e) => {
    configFrame.enabled = e.target.checked;
    localStorage.setItem('portal_frame', e.target.checked);
    playAudioTone('tap');
    if (e.target.checked) {
        document.body.classList.remove('frame-disabled');
    } else {
        document.body.classList.add('frame-disabled');
    }
});

document.getElementById('inapp-toggle').checked = configInApp.enabled;
document.getElementById('inapp-toggle').addEventListener('change', (e) => {
    configInApp.enabled = e.target.checked;
    localStorage.setItem('portal_inapp', e.target.checked);
    playAudioTone('tap');
});

// Legacy checkbox blur-toggle replaced by continuous blur-slider intensity settings

const savedCols = localStorage.getItem('portal_cols') || '4';
const gridEl = document.getElementById('main-app-grid');
if (savedCols === '3') {
    document.getElementById('cols-btn-3').classList.add('active');
    document.getElementById('cols-btn-4').classList.remove('active');
    gridEl.className = 'app-grid grid-cols-3';
}

document.getElementById('cols-btn-3').addEventListener('click', () => {
    playAudioTone('tap');
    document.getElementById('cols-btn-3').classList.add('active');
    document.getElementById('cols-btn-4').classList.remove('active');
    gridEl.className = 'app-grid grid-cols-3';
    localStorage.setItem('portal_cols', '3');
});

document.getElementById('cols-btn-4').addEventListener('click', () => {
    playAudioTone('tap');
    document.getElementById('cols-btn-4').classList.add('active');
    document.getElementById('cols-btn-3').classList.remove('active');
    gridEl.className = 'app-grid grid-cols-4';
    localStorage.setItem('portal_cols', '4');
});

const savedIconSize = localStorage.getItem('portal_icon_size') || '54';
document.documentElement.style.setProperty('--icon-size', `${savedIconSize}px`);
document.getElementById('icon-size-range').value = savedIconSize;
document.getElementById('range-val-icon').textContent = `${savedIconSize}px`;

document.getElementById('icon-size-range').addEventListener('input', (e) => {
    const val = e.target.value;
    document.getElementById('range-val-icon').textContent = `${val}px`;
    document.documentElement.style.setProperty('--icon-size', `${val}px`);
    localStorage.setItem('portal_icon_size', val);
});

// Initialize dynamic Edit Mode indicator sliders
const savedLineLength = window.CJOS_AM_CONFIG && window.CJOS_AM_CONFIG.line_length !== undefined ? window.CJOS_AM_CONFIG.line_length : 16;
const savedBoxSize = window.CJOS_AM_CONFIG && window.CJOS_AM_CONFIG.box_size !== undefined ? window.CJOS_AM_CONFIG.box_size : 2;

window.portalLineLength = savedLineLength;
document.body.style.setProperty('--box-size', `${savedBoxSize}px`);

const lineLengthRange = document.getElementById('line-length-range');
const rangeValLine = document.getElementById('range-val-line');
if (lineLengthRange) {
    lineLengthRange.value = savedLineLength;
    if (rangeValLine) rangeValLine.textContent = `${savedLineLength}px`;
    
    lineLengthRange.addEventListener('input', (e) => {
        const val = e.target.value;
        if (rangeValLine) rangeValLine.textContent = `${val}px`;
        window.portalLineLength = parseInt(val);
    });
    lineLengthRange.addEventListener('change', () => {
        window.portalSaveSettingsGlobal();
    });
}

const boxSizeRange = document.getElementById('box-size-range');
const rangeValBox = document.getElementById('range-val-box');
if (boxSizeRange) {
    boxSizeRange.value = savedBoxSize;
    if (rangeValBox) rangeValBox.textContent = `${savedBoxSize}px`;
    
    boxSizeRange.addEventListener('input', (e) => {
        const val = e.target.value;
        if (rangeValBox) rangeValBox.textContent = `${val}px`;
        document.body.style.setProperty('--box-size', `${val}px`);
    });
    boxSizeRange.addEventListener('change', () => {
        window.portalSaveSettingsGlobal();
    });
}

// Strict Axis-Locked Slider Gesture Controller
document.querySelectorAll('.panel-body input[type="range"]').forEach(slider => {
    let startX = 0;
    let startY = 0;
    let isTracking = false;
    let axisLocked = null; // 'x', 'y', or null

    slider.addEventListener('pointerdown', (e) => {
        const rect = slider.getBoundingClientRect();
        const min = parseFloat(slider.min) || 0;
        const max = parseFloat(slider.max) || 100;
        const pct = (parseFloat(slider.value) - min) / (max - min);
        const thumbX = rect.left + pct * rect.width;
        
        // Touch target buffer: 30px (rejects track tapping)
        if (Math.abs(e.clientX - thumbX) > 30) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }

        isTracking = true;
        axisLocked = null;
        startX = e.clientX;
        startY = e.clientY;
        
        try {
            slider.setPointerCapture(e.pointerId);
        } catch(err) {}
    });

    slider.addEventListener('pointermove', (e) => {
        if (!isTracking) return;

        const diffX = e.clientX - startX;
        const diffY = e.clientY - startY;
        const absX = Math.abs(diffX);
        const absY = Math.abs(diffY);

        if (axisLocked === null) {
            if (absY > absX && absY > 5) {
                // Vertical Gesture: Release pointer capture and let native scrolling take over
                axisLocked = 'y';
                isTracking = false;
                try {
                    slider.releasePointerCapture(e.pointerId);
                } catch(err) {}
                return;
            } else if (absX > absY && absX > 5) {
                axisLocked = 'x';
            }
        }

        if (axisLocked === 'x') {
            const rect = slider.getBoundingClientRect();
            const width = rect.width;
            const min = parseFloat(slider.min) || 0;
            const max = parseFloat(slider.max) || 100;
            const range = max - min;
            
            const pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / width));
            const newVal = min + pct * range;
            
            slider.value = newVal;
            
            // Trigger standard input/change events to fire existing listeners
            slider.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });

    const endTracking = (e) => {
        if (!isTracking) return;
        isTracking = false;
        axisLocked = null;
        try {
            slider.releasePointerCapture(e.pointerId);
        } catch(err) {}
        
        // Trigger final change event to save config
        slider.dispatchEvent(new Event('change', { bubbles: true }));
    };

    slider.addEventListener('pointerup', endTracking);
    slider.addEventListener('pointercancel', endTracking);
});