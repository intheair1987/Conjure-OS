/**
 * WHITEBOARD WORKSPACES MODULE
 * Handles viewport layout saving, restoring, and Split View interactions.
 */

// --- WORKSPACE ENGINE ---
window.wbWorkspaces = [];



window.wbOpenWorkspaceManager = async function() {
    const fd = new FormData();
    fd.append('action', 'get_workspaces');
    fd.append('canvas_id', window.currentCanvasId);
    const res = await fetch('index.php', { method: 'POST', body: fd });
    const data = await res.json();
    window.wbWorkspaces = JSON.parse(data.data || '[]');

    let html = `
        <div style="margin-bottom: 24px;">
            <button class="tool-btn" onclick="wbSaveCurrentWorkspace()" style="width:100%; background:var(--primary-accent); color:white; border:none; padding:14px; font-weight:800; border-radius:16px; display:flex; align-items:center; justify-content:center; gap:10px;">
                <i data-lucide="plus-circle" style="width:20px;"></i> Save Current Layout
            </button>
        </div>
        <div id="ws-list-container" style="max-height: 400px; overflow-y: auto; padding-right: 4px; margin-right: -4px;">
    `;

    if (window.wbWorkspaces.length === 0) {
        html += `
            <div style="text-align:center; padding:40px 20px; opacity:0.5;">
                <i data-lucide="bookmark" style="width:40px; height:40px; margin-bottom:12px; opacity:0.3;"></i>
                <div style="font-size:14px; font-weight:700;">No Saved Workspaces</div>
                <div style="font-size:11px; margin-top:4px;">Snapshots of your viewport layouts will appear here.</div>
            </div>`;
    } else {
        window.wbWorkspaces.forEach((ws, idx) => {
            const icon = ws.baseMode === 'horizontal' ? 'rows' : (ws.baseMode === 'vertical' ? 'columns' : 'square');
            const floatCount = ws.floating?.length || 0;
            const metaText = `${ws.baseMode}${floatCount > 0 ? ` + ${floatCount} Floating` : ''}`;

            html += `
                <div class="workspace-item">
                    <div class="workspace-icon"><i data-lucide="${icon}" style="width:20px;"></i></div>
                    <div class="workspace-info" onclick="wbLoadWorkspace(${idx})">
                        <span class="workspace-name">${ws.name}</span>
                        <span class="workspace-meta">${metaText}</span>
                    </div>
                    <div class="workspace-actions">
                        <div class="ws-btn-del" onclick="wbDeleteWorkspace(${idx})" title="Delete Workspace">
                            <i data-lucide="trash-2" style="width:18px;"></i>
                        </div>
                    </div>
                </div>
            `;
        });
    }
    html += `</div>`;

    const sheet = document.getElementById('wb-action-sheet');
    document.getElementById('wb-as-title').innerText = "Workspaces";
    document.getElementById('wb-as-options').innerHTML = html;
    
    // Position/Display logic
    sheet.style.display = 'flex';
    setTimeout(() => {
        sheet.classList.add('active');
        sheet.querySelector('.wb-action-sheet').classList.add('active');
        // Hydrate the newly injected icons
        if (window.lucide) lucide.createIcons();
    }, 50);
};

window.wbSaveCurrentWorkspace = async function() {
    const name = await window.wbui.input("Name this workspace", "Save Workspace", "Layout " + (window.wbWorkspaces.length + 1), "🔖");
    if (!name) return;

    const container = document.getElementById('canvas-container');
    const pane0 = document.getElementById('pane-0');
    
    const state = {
        name: name,
        timestamp: Date.now(),
        baseMode: container.classList.contains('split-horizontal') ? 'horizontal' : 
                  (document.getElementById('pane-1') ? 'vertical' : 'none'),
        splitRatio: pane0.style.flex,
        mainTransform: { ...viewports[0].transform },
        splitTransform: viewports[1] && viewports[1].id === 'pane-1' ? { ...viewports[1].transform } : null,
        floating: []
    };

    // Capture Floating Viewports
    document.querySelectorAll('.floating-viewport').forEach(el => {
        const pane = el.querySelector('.canvas-pane');
        const vp = viewports.find(v => v.id === pane.id);
        if (vp) {
            state.floating.push({
                rect: {
                    top: el.style.top,
                    left: el.style.left,
                    width: el.style.width,
                    height: el.style.height
                },
                transform: { ...vp.transform }
            });
        }
    });

    window.wbWorkspaces.push(state);
    await wbPersistWorkspaces();
    wbOpenWorkspaceManager(); // Refresh list
};

window.wbDeleteWorkspace = async function(idx) {
    if (!await wbui.confirm("Delete this workspace?", "Delete", wbIcons.trash)) return;
    window.wbWorkspaces.splice(idx, 1);
    await wbPersistWorkspaces();
    wbOpenWorkspaceManager();
};

async function wbPersistWorkspaces() {
    const fd = new FormData();
    fd.append('action', 'save_workspaces');
    fd.append('canvas_id', window.currentCanvasId);
    fd.append('data', JSON.stringify(window.wbWorkspaces));
    await fetch('index.php', { method: 'POST', body: fd });
}

window.wbLoadWorkspace = async function(idx) {
    const ws = window.wbWorkspaces[idx];
    if (!ws) return;

    wbCloseActionSheet();

    // --- CLEANUP: Clear current layout before loading workspace ---
    // 1. Remove all floating viewport elements from the DOM
    document.querySelectorAll('.floating-viewport').forEach(el => el.remove());
    // 2. Reset the tracking array to only contain the primary viewport
    viewports = [viewports[0]];
    activeViewportIndex = 0;

    const pill = document.getElementById('status-pill');
    pill.innerText = "Restoring Workspace...";
    pill.style.opacity = "1";

    // 1. Reset to Base Mode
    if (typeof resetPointerState === 'function') resetPointerState();
    setSplitMode(ws.baseMode);
    
    // 2. Apply Split Ratio
    if (ws.splitRatio) {
        document.getElementById('pane-0').style.flex = ws.splitRatio;
    }

    // 3. Apply Main Transforms
    viewports[0].transform = { ...ws.mainTransform };
    if (ws.splitTransform && viewports[1]) {
        viewports[1].transform = { ...ws.splitTransform };
    }

    // 4. Restore Floating Viewports
    ws.floating.forEach(f => {
        setSplitMode('floating');
        const allFloats = document.querySelectorAll('.floating-viewport');
        const el = allFloats[allFloats.length - 1];
        
        // Apply saved dimensions/position
        el.style.top = f.rect.top;
        el.style.left = f.rect.left;
        el.style.width = f.rect.width;
        el.style.height = f.rect.height;
        el.style.right = 'auto';

        // Apply saved transform to the newly created viewport object
        const paneId = el.querySelector('.canvas-pane').id;
        const vp = viewports.find(v => v.id === paneId);
        if (vp) vp.transform = { ...f.transform };
    });

    resize();
    setTimeout(() => { pill.style.opacity = "0"; }, 1000);
    if (window.navigator.vibrate) navigator.vibrate(15);
};

function initSplitDrag() {
    const btn = document.getElementById('split-btn');
    if (!btn) return;

    let isDraggingSplit = false;
    let splitDragStart = null;
    let activeFloatingPane = null;

    btn.addEventListener('contextmenu', e => e.preventDefault());

    btn.addEventListener('pointerdown', (e) => {
        if (e.pointerType === 'pen') return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        if (typeof isLayoutLocked !== 'undefined' && isLayoutLocked) {
            splitDragStart = { x: e.clientX, y: e.clientY, locked: true };
            return;
        }
        splitDragStart = { x: e.clientX, y: e.clientY };
        isDraggingSplit = false;
        activeFloatingPane = null;
        btn.setPointerCapture(e.pointerId);
    });

    btn.addEventListener('pointermove', (e) => {
        if (!splitDragStart || splitDragStart.locked) return;
        const dist = Math.hypot(e.clientX - splitDragStart.x, e.clientY - splitDragStart.y);
        
        if (!isDraggingSplit && dist > 15) {
            isDraggingSplit = true;
            setSplitMode('floating');
            const floats = document.querySelectorAll('.floating-viewport');
            activeFloatingPane = floats[floats.length - 1];
            if (activeFloatingPane._settleTimer) clearTimeout(activeFloatingPane._settleTimer);
            document.getElementById('split-popover').style.display = 'none';
            if (window.navigator && navigator.vibrate) navigator.vibrate(10);
        }

        if (isDraggingSplit && activeFloatingPane) {
            const w = activeFloatingPane.offsetWidth || 350;
            const h = activeFloatingPane.offsetHeight || 250;
            activeFloatingPane.style.left = (e.clientX - w/2) + 'px';
            activeFloatingPane.style.top = (e.clientY - h/2) + 'px';
            activeFloatingPane.style.right = 'auto';
        }
    });

    btn.addEventListener('pointerup', (e) => {
        if (!splitDragStart) return;
        if (!splitDragStart.locked) btn.releasePointerCapture(e.pointerId);
        
        if (!isDraggingSplit) {
            toggleSplitPopover();
        } else if (activeFloatingPane && activeFloatingPane.wbRefreshSettleTimer) {
            activeFloatingPane.wbRefreshSettleTimer();
        }
        
        splitDragStart = null;
        isDraggingSplit = false;
        activeFloatingPane = null;
    });
}