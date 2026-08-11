<?php
// ==============================================================================
// PLUGIN: FileVault
// DESCRIPTION: Secure File Manager & Storage.
// Features: Folders, Multi-selection, Rename, Delete, Download, and Sorting.
// ==============================================================================

$fv_base_dir = CJOS_PATH_DATA . DIRECTORY_SEPARATOR . 'vault';
if (!is_dir($fv_base_dir)) mkdir($fv_base_dir, 0777, true);

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action']) || isset($_GET['plugin_action'])) {
    $action = $_POST['plugin_action'] ?? $_GET['plugin_action'];

    // 1. LIST FILES
    if ($action === 'fv_list') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $sub = $_POST['path'] ?? '';
        $target = realpath($fv_base_dir . DIRECTORY_SEPARATOR . $sub);
        
        if (!$target || strpos($target, realpath($fv_base_dir)) !== 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid path']);
            exit;
        }

        $items = [];
        $files = scandir($target);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $target . DIRECTORY_SEPARATOR . $f;
            $isDir = is_dir($full);
            $items[] = [
                'name' => $f,
                'path' => ($sub ? $sub . '/' : '') . $f,
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : filesize($full),
                'mtime' => filemtime($full),
                'ext' => $isDir ? 'dir' : pathinfo($f, PATHINFO_EXTENSION)
            ];
        }
        echo json_encode(['status' => 'success', 'items' => $items]);
        exit;
    }

    // 2. UPLOAD
    if ($action === 'fv_upload') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $sub = $_POST['path'] ?? '';
        $targetDir = realpath($fv_base_dir . DIRECTORY_SEPARATOR . $sub);
        
        if (!$targetDir || strpos($targetDir, realpath($fv_base_dir)) !== 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid upload path']);
            exit;
        }

        if (!empty($_FILES['files'])) {
            $count = count($_FILES['files']['name']);
            for ($i = 0; $i < $count; $i++) {
                $name = basename($_FILES['files']['name'][$i]);
                move_uploaded_file($_FILES['files']['tmp_name'][$i], $targetDir . DIRECTORY_SEPARATOR . $name);
            }
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No files received']);
        }
        exit;
    }

    // 3. DELETE (Single or Batch)
    if ($action === 'fv_delete') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $paths = json_decode($_POST['paths'], true);
        foreach ($paths as $p) {
            $full = realpath($fv_base_dir . DIRECTORY_SEPARATOR . $p);
            if ($full && strpos($full, realpath($fv_base_dir)) === 0) {
                if (is_dir($full)) {
                    // Simple recursive delete for folders
                    $it = new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS);
                    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                    foreach($files as $file) {
                        if ($file->isDir()) rmdir($file->getRealPath()); else unlink($file->getRealPath());
                    }
                    rmdir($full);
                } else {
                    unlink($full);
                }
            }
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    // 4. MOVE (Batch)
    if ($action === 'fv_move') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $paths = json_decode($_POST['paths'], true);
        $destSub = $_POST['dest'] ?? '';
        $destDir = realpath($fv_base_dir . DIRECTORY_SEPARATOR . $destSub);

        if (!$destDir || strpos($destDir, realpath($fv_base_dir)) !== 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid destination']);
            exit;
        }

        $success = 0;
        foreach ($paths as $p) {
            $old = realpath($fv_base_dir . DIRECTORY_SEPARATOR . $p);
            if ($old && strpos($old, realpath($fv_base_dir)) === 0) {
                $newName = basename($old);
                $newPath = $destDir . DIRECTORY_SEPARATOR . $newName;
                if (!file_exists($newPath)) {
                    if (rename($old, $newPath)) $success++;
                }
            }
        }
        echo json_encode(['status' => 'success', 'moved' => $success]);
        exit;
    }

    // 5. RENAME
    if ($action === 'fv_rename') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $old = realpath($fv_base_dir . DIRECTORY_SEPARATOR . $_POST['old_path']);
        $new = dirname($old) . DIRECTORY_SEPARATOR . basename($_POST['new_name']);
        if ($old && strpos($old, realpath($fv_base_dir)) === 0) {
            rename($old, $new);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid rename']);
        }
        exit;
    }

    // 5. CREATE FOLDER
    if ($action === 'fv_mkdir') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $parent = realpath($fv_base_dir . DIRECTORY_SEPARATOR . ($_POST['path'] ?? ''));
        $baseName = basename($_POST['name']);
        
        if ($parent && strpos($parent, realpath($fv_base_dir)) === 0) {
            $finalName = $baseName;
            $counter = 1;
            
            // Collision Check Loop
            while (file_exists($parent . DIRECTORY_SEPARATOR . $finalName)) {
                $finalName = $baseName . " ($counter)";
                $counter++;
            }
            
            if (mkdir($parent . DIRECTORY_SEPARATOR . $finalName, 0777, true)) {
                echo json_encode(['status' => 'success', 'name' => $finalName]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to create directory']);
            }
        }
        exit;
    }

    // 6. VIEW / SERVE (Images)
    if ($action === 'fv_serve') {
        while (ob_get_level()) ob_end_clean();
        $path = $_GET['path'] ?? '';
        $full = realpath($fv_base_dir . DIRECTORY_SEPARATOR . $path);
        if ($full && strpos($full, realpath($fv_base_dir)) === 0 && !is_dir($full)) {
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            $mimes = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 
                'png' => 'image/png', 'gif' => 'image/gif', 
                'webp' => 'image/webp', 'svg' => 'image/svg+xml',
                'pdf' => 'application/pdf',
                'mp4' => 'video/mp4', 'webm' => 'video/webm', 
                'mov' => 'video/quicktime', 'ogg' => 'video/ogg'
            ];
            $mime = $mimes[$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($full));
            readfile($full);
            exit;
        }
        exit;
    }

    // 7. DOWNLOAD
    if ($action === 'fv_download') {
        while (ob_get_level()) ob_end_clean();
        $path = $_GET['path'] ?? '';
        $full = realpath($fv_base_dir . DIRECTORY_SEPARATOR . $path);
        if ($full && strpos($full, realpath($fv_base_dir)) === 0 && !is_dir($full)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($full).'"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($full));
            readfile($full);
            exit;
        }
        die("Unauthorized or missing file.");
    }
}

// Register Tool
$plugin_tools[] = [
    'id' => 'fv-launcher',
    'name' => 'File Vault',
    'desc' => 'Secure file storage',
    'sui_icon' => 'folder',
    'action' => 'fvOpenStudio()'
];

// --- JS LOGIC ---
$plugin_js .= <<<'JS'
let fvCurrentPath = "";
let fvSelection = new Set();
let fvViewMode = localStorage.getItem('fv_view_mode') || 'grid';
let fvSortMode = localStorage.getItem('fv_sort_mode') || 'name'; // 'name' or 'date'
let fvMoveSource = null; 
let fvLongPressTimer = null;
let fvLastItems = []; // Cache for collision checks

/**
 * Generates a unique name by checking against cached items
 */
function fvGetUniqueName(baseName) {
    let name = baseName;
    let counter = 1;
    const existing = new Set(fvLastItems.map(i => i.name));
    while (existing.has(name)) {
        name = `${baseName} (${counter})`;
        counter++;
    }
    return name;
}

window.fvOpenStudio = function() {
    window.sui.openStudio({
        id: 'file-vault',
        title: 'File Vault',
        content: `
            <div id="fv-root" style="display:flex; flex-direction:column; height:100%;">
                <!-- Action Bar -->
                <div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap;">
                    <button onclick="fvTriggerUpload()" class="btn-primary" style="flex:1; padding:10px; font-size:12px;">
                        <span data-sui-icon="plus" data-sui-size="14" style="margin-right:6px;"></span> Upload
                    </button>
                    <button onclick="fvNewFolder()" class="text-btn" style="flex:1; background:var(--btn-bg); border-radius:12px; font-size:12px; font-weight:700;">
                        New Folder
                    </button>
                    <button id="fv-sort-btn" onclick="fvToggleSort()" class="text-btn" style="flex:1; background:var(--btn-bg); border-radius:12px; font-size:12px; font-weight:700;">
                        Sort: ${fvSortMode === 'name' ? 'A-Z' : 'Newest'}
                    </button>
                    <button onclick="fvToggleView()" class="icon-btn" style="background:var(--btn-bg); width:40px; height:40px;" title="Toggle View Mode">
                        <span id="fv-view-icon" data-sui-icon="${fvViewMode === 'grid' ? 'list' : 'grid'}" data-sui-size="20"></span>
                    </button>
                </div>

                <!-- Breadcrumbs -->
                <div id="fv-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin-bottom:12px; font-size:12px; color:var(--text-secondary); overflow-x:auto; white-space:nowrap; padding-bottom:4px;"></div>

                <!-- Selection Bar -->
                <div id="fv-selection-bar" style="display:none; justify-content:space-between; align-items:center; background:var(--ai-accent-bg); padding:10px 16px; border-radius:12px; margin-bottom:12px; border:1px solid rgba(88, 86, 214, 0.2);">
                    <span style="font-size:12px; font-weight:800; color:var(--ai-accent);"><span id="fv-sel-count">0</span> Selected</span>
                    <div style="display:flex; gap:12px;">
                        <button onclick="fvInitiateMove()" class="text-btn" style="color:var(--primary); font-size:12px; font-weight:800;">Move</button>
                        <button onclick="fvBulkDelete()" class="text-btn" style="color:var(--danger); font-size:12px; font-weight:800;">Delete</button>
                        <button onclick="fvClearSelection()" class="text-btn" style="color:var(--text-secondary); font-size:12px; font-weight:800;">Cancel</button>
                    </div>
                </div>

                <!-- Move Mode Bar -->
                <div id="fv-move-bar" style="display:none; justify-content:space-between; align-items:center; background:var(--warn-bg); padding:10px 16px; border-radius:12px; margin-bottom:12px; border:1px solid rgba(133, 100, 4, 0.2);">
                    <span style="font-size:12px; font-weight:800; color:var(--warn-text);">Moving <span id="fv-move-count">0</span> items...</span>
                    <div style="display:flex; gap:12px;">
                        <button onclick="fvExecuteMove()" class="text-btn" style="color:var(--primary); font-size:12px; font-weight:800;">Move Here</button>
                        <button onclick="fvCancelMove()" class="text-btn" style="color:var(--text-secondary); font-size:12px; font-weight:800;">Cancel</button>
                    </div>
                </div>

                <!-- Explorer -->
                <div id="fv-explorer" style="flex:1; overflow-y:auto; min-height:300px;"></div>
                
                <input type="file" id="fv-file-input" multiple style="display:none;" onchange="fvHandleUpload(this)">
            </div>
        `,
        onSetup: () => {
            fvRefresh();
        }
    });
};

window.fvRefresh = async function() {
    const explorer = document.getElementById('fv-explorer');
    if (!explorer) return;
    explorer.innerHTML = `<div style="padding:40px; text-align:center;">${window.suiSpinner(30)}</div>`;
    
    fvUpdateBreadcrumbs();

    try {
        const data = await window.sui.api('fv_list', { path: fvCurrentPath }, { toast: false });
        if (data && data.items) {
            fvLastItems = data.items;
            renderFvItems(data.items);
        }
    } catch(e) { explorer.innerHTML = `<div style="padding:20px; color:var(--danger);">Failed to load vault.</div>`; }
};

window.fvToggleSort = function() {
    fvSortMode = (fvSortMode === 'name' ? 'date' : 'name');
    localStorage.setItem('fv_sort_mode', fvSortMode);
    const btn = document.getElementById('fv-sort-btn');
    if (btn) btn.innerText = `Sort: ${fvSortMode === 'name' ? 'A-Z' : 'Newest'}`;
    fvRefresh();
};

function renderFvItems(items) {
    const explorer = document.getElementById('fv-explorer');
    if (items.length === 0) {
        explorer.innerHTML = window.suiEmptyState('📁', 'Vault is empty');
        return;
    }

    // 1. Core Sorting Logic
    items.sort((a, b) => {
        // Always keep folders at the top
        if (a.is_dir !== b.is_dir) return b.is_dir - a.is_dir;
        
        if (fvSortMode === 'date') {
            // Newest first
            return b.mtime - a.mtime;
        } else {
            // Alphanumeric A-Z
            return a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
        }
    });

    const container = document.createElement('div');
    if (fvViewMode === 'grid') {
        container.style.display = 'grid';
        container.style.gridTemplateColumns = 'repeat(auto-fill, minmax(100px, 1fr))';
        container.style.gap = '12px';
    } else {
        container.style.display = 'flex';
        container.style.flexDirection = 'column';
        container.style.gap = '8px';
    }

    items.forEach(item => {
        const el = document.createElement('div');
        const isSelected = fvSelection.has(item.path);
        
        const iconName = item.is_dir ? 'folder' : fvGetIcon(item.ext);
        const iconColor = item.is_dir ? 'var(--primary)' : 'var(--text-secondary)';

        if (fvViewMode === 'grid') {
            el.style.cssText = `
                display:flex; flex-direction:column; align-items:center; gap:8px; padding:16px 8px; 
                background:var(--card-bg); border:1px solid ${isSelected ? 'var(--primary)' : 'var(--border-color)'}; 
                border-radius:16px; cursor:pointer; position:relative; transition: transform 0.1s;
                box-shadow: ${isSelected ? '0 4px 12px rgba(0,122,255,0.15)' : 'none'};
            `;
            el.innerHTML = `
                <div style="height:40px; display:flex; align-items:center; justify-content:center;">
                    <span data-sui-icon="${iconName}" data-sui-size="32" data-sui-color="${iconColor}" data-sui-stroke="1.5"></span>
                </div>
                <div style="font-size:11px; font-weight:700; text-align:center; word-break:break-all; color:var(--text-primary); max-width:100%; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">${item.name}</div>
                ${isSelected ? `<div style="position:absolute; top:6px; right:6px; background:var(--primary); border-radius:50%; width:16px; height:16px; display:flex; align-items:center; justify-content:center;"><span data-sui-icon="check" data-sui-size="10" data-sui-color="white" data-sui-stroke="4"></span></div>` : ''}
            `;
        } else {
            el.style.cssText = `
                display:flex; align-items:center; gap:12px; padding:12px 16px; 
                background:var(--card-bg); border:1px solid ${isSelected ? 'var(--primary)' : 'var(--border-color)'}; 
                border-radius:12px; cursor:pointer;
            `;
            el.innerHTML = `
                <span data-sui-icon="${iconName}" data-sui-size="20" data-sui-color="${iconColor}" data-sui-stroke="2"></span>
                <div style="flex:1; min-width:0;">
                    <div style="font-size:14px; font-weight:700; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis;">${item.name}</div>
                    <div style="font-size:10px; color:var(--text-secondary);">${item.is_dir ? 'Folder' : fvFormatSize(item.size)}</div>
                </div>
                ${isSelected ? `<span data-sui-icon="check" data-sui-size="16" data-sui-color="var(--primary)" data-sui-stroke="3"></span>` : ''}
            `;
        }

        el.onclick = () => {
            if (fvSelection.size > 0) {
                fvToggleSelect(item.path);
            } else {
                if (item.is_dir) {
                    fvCurrentPath = item.path;
                    fvRefresh();
                } else {
                    const ext = item.ext.toLowerCase();
                    const isImg = ['jpg','jpeg','png','gif','webp','svg'].includes(ext);
                    const isText = ['txt','md','json','php','js','css','html','log','xml','yaml'].includes(ext);
                    const isPdf = ext === 'pdf';
                    const isVid = ['mp4','webm','mov','ogg'].includes(ext);
                    
                    if (isImg) fvViewImage(item);
                    else if (isText) fvViewText(item);
                    else if (isPdf) fvViewPdf(item);
                    else if (isVid) fvViewVideo(item);
                    else fvOpenFileMenu(item);
                }
            }
        };

        // Long Press / Selection Logic
        el.onpointerdown = (e) => {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            fvLongPressTimer = setTimeout(() => {
                fvLongPressTimer = null;
                window.sui.haptic('medium');
                fvToggleSelect(item.path);
            }, 600);
        };
        el.onpointerup = () => { clearTimeout(fvLongPressTimer); fvLongPressTimer = null; };
        el.onpointerleave = () => { clearTimeout(fvLongPressTimer); fvLongPressTimer = null; };
        el.oncontextmenu = (e) => e.preventDefault();

        container.appendChild(el);
    });

    explorer.innerHTML = "";
    explorer.appendChild(container);
    window.suiHydrateIcons(explorer);
}

function fvGetIcon(ext) {
    const map = {
        'pdf': 'file-text', 'doc': 'file-text', 'docx': 'file-text', 'txt': 'file-text', 'md': 'file-text',
        'jpg': 'image', 'jpeg': 'image', 'png': 'image', 'gif': 'image', 'webp': 'image', 'svg': 'image',
        'mp3': 'music', 'wav': 'music', 'm4a': 'music',
        'mp4': 'video', 'mov': 'video', 'webm': 'video',
        'zip': 'package', 'rar': 'package', '7z': 'package',
        'json': 'file-code', 'php': 'file-code', 'js': 'file-code', 'css': 'palette'
    };
    return map[ext.toLowerCase()] || 'file';
}

function fvFormatSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

window.fvUpdateBreadcrumbs = function() {
    const bc = document.getElementById('fv-breadcrumbs');
    if (!bc) return;
    let html = `<span onclick="fvCurrentPath=''; fvRefresh()" style="cursor:pointer; font-weight:800; color:var(--primary);">Vault</span>`;
    if (fvCurrentPath) {
        const parts = fvCurrentPath.split('/');
        let current = "";
        parts.forEach((p, i) => {
            current += (i === 0 ? '' : '/') + p;
            html += ` <span style="opacity:0.4;">/</span> <span onclick="fvCurrentPath='${current}'; fvRefresh()" style="cursor:pointer;">${p}</span>`;
        });
    }
    bc.innerHTML = html;
};

window.fvToggleView = function() {
    fvViewMode = (fvViewMode === 'grid' ? 'list' : 'grid');
    localStorage.setItem('fv_view_mode', fvViewMode);
    const icon = document.getElementById('fv-view-icon');
    if (icon) {
        // Show the icon of the mode we are NOT in (the action icon)
        icon.setAttribute('data-sui-icon', fvViewMode === 'grid' ? 'list' : 'grid');
        window.suiHydrateIcons(icon.parentElement);
    }
    fvRefresh();
};

window.fvToggleSelect = function(path) {
    if (fvSelection.has(path)) fvSelection.delete(path);
    else fvSelection.add(path);
    
    const bar = document.getElementById('fv-selection-bar');
    const count = document.getElementById('fv-sel-count');
    if (fvSelection.size > 0) {
        bar.style.display = 'flex';
        count.innerText = fvSelection.size;
    } else {
        bar.style.display = 'none';
    }
    renderFvItems([...document.querySelectorAll('#fv-explorer [data-item-path]')]); // Just a refresh trigger
    fvRefresh(); 
};

window.fvClearSelection = function() {
    fvSelection.clear();
    document.getElementById('fv-selection-bar').style.display = 'none';
    fvRefresh();
};

window.fvTriggerUpload = function() {
    document.getElementById('fv-file-input').click();
};

window.fvHandleUpload = async function(input) {
    if (!input.files.length) return;
    const fd = new FormData();
    fd.append('plugin_action', 'fv_upload');
    fd.append('path', fvCurrentPath);
    for (let f of input.files) fd.append('files[]', f);

    try {
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
            window.sui.toast("Upload Complete");
            fvRefresh();
        }
    } catch(e) { window.sui.toast("Upload failed"); }
    input.value = "";
};

window.fvNewFolder = function() {
    const suggestedName = fvGetUniqueName("Untitled");
    window.openInput("New Folder", "Folder Name", suggestedName, async (name) => {
        if (!name) return;
        await window.sui.api('fv_mkdir', { path: fvCurrentPath, name: name });
        fvRefresh();
    });
};

window.fvBulkDelete = function() {
    const count = fvSelection.size;
    window.openConfirm("Delete Items", `Are you sure you want to delete ${count} items? This cannot be undone.`, async () => {
        await window.sui.api('fv_delete', { paths: Array.from(fvSelection) });
        fvClearSelection();
    }, true);
};

window.fvInitiateMove = function() {
    fvMoveSource = Array.from(fvSelection);
    fvSelection.clear();
    document.getElementById('fv-selection-bar').style.display = 'none';
    document.getElementById('fv-move-bar').style.display = 'flex';
    document.getElementById('fv-move-count').innerText = fvMoveSource.length;
    fvRefresh();
};

window.fvCancelMove = function() {
    fvMoveSource = null;
    document.getElementById('fv-move-bar').style.display = 'none';
    fvRefresh();
};

window.fvExecuteMove = async function() {
    if (!fvMoveSource) return;
    
    // Check if moving into itself
    if (fvMoveSource.some(p => p === fvCurrentPath)) {
        window.sui.toast("Cannot move a folder into itself");
        return;
    }

    const res = await window.sui.api('fv_move', { 
        paths: JSON.stringify(fvMoveSource), 
        dest: fvCurrentPath 
    });
    
    if (res && res.status === 'success') {
        window.sui.toast(`Moved ${res.moved} items`);
        fvCancelMove();
    }
};

window.fvViewImage = function(item) {
    const url = `index.php?plugin_action=fv_serve&path=${encodeURIComponent(item.path)}`;
    window.sui.openStudio({
        id: 'fv-viewer',
        title: item.name,
        content: `
            <div style="display:flex; flex-direction:column; gap:16px;">
                <div style="display:flex; align-items:center; justify-content:center; background:#000; border-radius:16px; overflow:hidden; min-height:200px; box-shadow:inset 0 0 40px rgba(0,0,0,0.5);">
                    <img src="${url}" style="max-width:100%; max-height:70vh; object-fit:contain; display:block;">
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <button onclick="window.location.href='index.php?plugin_action=fv_download&path=${encodeURIComponent(item.path)}'" class="text-btn" style="background:var(--btn-bg); border-radius:12px; padding:12px; font-size:13px; font-weight:700;">Download</button>
                    <button onclick="window.sui.closeStudio('fv-viewer'); fvOpenFileMenu(${JSON.stringify(item).replace(/"/g, '&quot;')})" class="text-btn" style="background:var(--btn-bg); border-radius:12px; padding:12px; font-size:13px; font-weight:700;">More Options...</button>
                </div>
            </div>
        `
    });
};

window.fvViewText = function(item) {
    // FileStudio expects paths relative to CJOS_PATH_ROOT
    const rootRelPath = 'app/data/vault/' + item.path;
    if (typeof window.fsOpen === 'function') {
        // Create a footer button that opens the Vault context menu
        // We use a helper function to avoid complex escaping in the string
        const footerHtml = `
            <button onclick="fvOpenOptionsFromStudio('${item.path}')" class="text-btn" style="flex:1; min-width:120px; background:var(--btn-bg); border-radius:12px; padding:12px; font-size:12px; font-weight:700;">More Options...</button>
        `;
        window.fsOpen(rootRelPath, { footer: footerHtml });
    } else {
        window.openConfirm("Plugin Missing", "The File Studio plugin is required to view text files.", null, false, "OK", null);
    }
};

/**
 * Helper to bridge Studio views back to Vault actions
 */
window.fvOpenOptionsFromStudio = async function(path) {
    // 1. Close the current viewer studio (could be code, md, or json)
    window.sui.closeStudio('fs-code');
    window.sui.closeStudio('fs-markdown');
    window.sui.closeStudio('fs-json');
    
    // 2. We need the full item object. Since we only have the path, 
    // we'll fetch the list again or just simulate the object if possible.
    // For Rename/Delete/Download, fvOpenFileMenu only needs .name, .path, and .ext
    const name = path.split('/').pop();
    const ext = name.split('.').pop();
    const item = { name, path, ext };
    
    // 3. Open the menu
    setTimeout(() => fvOpenFileMenu(item), 350);
};

window.fvViewPdf = async function(item) {
    const url = `index.php?plugin_action=fv_serve&path=${encodeURIComponent(item.path)}`;
    
    // 1. Detection Heuristic
    const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
    const hasNativeSupport = navigator.pdfViewerEnabled || (typeof window.chrome !== 'undefined');
    const useNative = hasNativeSupport && !isMobile;

    let studioContent = "";
    let setupFn = null;

    if (useNative) {
        // --- PATH A: NATIVE IFRAME (Desktop) ---
        studioContent = `
            <div style="display:flex; flex-direction:column; height:85vh;">
                <div style="flex:1; background:var(--bg-color); border-radius:12px; overflow:hidden; border:1px solid var(--border-color);">
                    <iframe src="${url}#toolbar=1" style="width:100%; height:100%; border:none;" allow="fullscreen"></iframe>
                </div>
                <div style="margin-top:16px; display:flex; flex-wrap:wrap; gap:12px; padding-bottom:10px;">
                    <button onclick="window.location.href='index.php?plugin_action=fv_download&path=${encodeURIComponent(item.path)}'" class="text-btn" style="flex:1; min-width:120px; background:var(--btn-bg); border-radius:12px; padding:14px; font-weight:700;">Download PDF</button>
                    <button onclick="window.sui.closeStudio('fv-pdf-viewer'); fvOpenFileMenu(${JSON.stringify(item).replace(/"/g, '&quot;')})" class="text-btn" style="flex:1; min-width:120px; background:var(--btn-bg); border-radius:12px; padding:14px; font-weight:700;">More Options...</button>
                </div>
            </div>
        `;
    } else {
        // --- PATH B: PDF.JS CANVAS (Mobile/Fallback) ---
        if (typeof pdfjsLib === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
            document.head.appendChild(script);
            await new Promise(r => script.onload = r);
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }

        studioContent = `
            <div style="display:flex; flex-direction:column; max-height:85vh;">
                <div id="fv-pdf-container" style="background:#525659; border-radius:12px; overflow-y:auto; border:1px solid var(--border-color); display:flex; flex-direction:column; align-items:center; gap:12px; padding:12px; -webkit-overflow-scrolling: touch; min-height:200px;">
                    <div id="fv-pdf-loading" style="padding:60px 40px; color:white; text-align:center;">
                        ${window.suiSpinner(30)}
                        <div style="margin-top:10px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px;">Loading with PDF.js...</div>
                    </div>
                </div>
                <div style="margin-top:16px; display:flex; flex-wrap:wrap; gap:12px; padding-bottom:10px;">
                    <button onclick="window.location.href='index.php?plugin_action=fv_download&path=${encodeURIComponent(item.path)}'" class="text-btn" style="flex:1; min-width:120px; background:var(--btn-bg); border-radius:12px; padding:14px; font-weight:700;">Download PDF</button>
                    <button onclick="window.sui.closeStudio('fv-pdf-viewer'); fvOpenFileMenu(${JSON.stringify(item).replace(/"/g, '&quot;')})" class="text-btn" style="flex:1; min-width:120px; background:var(--btn-bg); border-radius:12px; padding:14px; font-weight:700;">More Options...</button>
                </div>
            </div>
        `;

        setupFn = async (container) => {
            const viewer = container.querySelector('#fv-pdf-container');
            const loader = container.querySelector('#fv-pdf-loading');
            try {
                const loadingTask = pdfjsLib.getDocument(url);
                const pdf = await loadingTask.promise;
                loader.remove();
                const dpr = window.devicePixelRatio || 1;
                for (let i = 1; i <= pdf.numPages; i++) {
                    const page = await pdf.getPage(i);
                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');
                    
                    // 1. Calculate the scale required to fit the container width
                    const unscaledViewport = page.getViewport({ scale: 1 });
                    const containerWidth = viewer.clientWidth - 24;
                    const fitScale = containerWidth / unscaledViewport.width;
                    
                    // 2. Create a viewport multiplied by Device Pixel Ratio for sharpness
                    // Corrected method name to getViewport for PDF.js v3.x
                    const viewport = page.getViewport({ scale: fitScale * dpr });

                    // 3. Set internal canvas resolution (High-Res)
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    
                    // 4. Set CSS display size (Standard-Res)
                    canvas.style.width = (viewport.width / dpr) + "px";
                    canvas.style.height = (viewport.height / dpr) + "px";

                    canvas.style.boxShadow = "0 4px 15px rgba(0,0,0,0.3)";
                    canvas.style.borderRadius = "4px";
                    canvas.style.display = "block";
                    
                    viewer.appendChild(canvas);

                    // 5. Render with the high-res viewport
                    await page.render({ 
                        canvasContext: context, 
                        viewport: viewport 
                    }).promise;
                }
            } catch (e) {
                viewer.innerHTML = `<div style="padding:40px; color:#ff6b6b; text-align:center; font-weight:700;">Failed to render PDF.<br><span style="font-size:10px; opacity:0.7;">${e.message}</span></div>`;
            }
        };
    }

    window.sui.openStudio({
        id: 'fv-pdf-viewer',
        title: item.name + (useNative ? '' : ' (Mobile View)'),
        content: studioContent,
        onSetup: setupFn
    });
};

window.fvViewVideo = function(item) {
    const url = `index.php?plugin_action=fv_serve&path=${encodeURIComponent(item.path)}`;
    window.sui.openStudio({
        id: 'fv-video-viewer',
        title: item.name,
        onClose: () => {
            // Explicitly kill video playback to prevent background audio
            const vid = document.querySelector('#sui-studio-fv-video-viewer video');
            if (vid) {
                vid.pause();
                vid.src = "";
                vid.load();
                vid.remove();
            }
        },
        content: `
            <div style="display:flex; flex-direction:column; gap:16px;">
                <div style="background:#000; border-radius:16px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.3); line-height:0;">
                    <video src="${url}" controls playsinline style="width:100%; max-height:70vh; display:block;"></video>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; padding-bottom:10px;">
                    <button onclick="window.location.href='index.php?plugin_action=fv_download&path=${encodeURIComponent(item.path)}'" class="text-btn" style="background:var(--btn-bg); border-radius:12px; padding:14px; font-weight:700;">Download Video</button>
                    <button onclick="window.sui.closeStudio('fv-video-viewer'); fvOpenFileMenu(${JSON.stringify(item).replace(/"/g, '&quot;')})" class="text-btn" style="background:var(--btn-bg); border-radius:12px; padding:14px; font-weight:700;">More Options...</button>
                </div>
            </div>
        `
    });
};

window.fvOpenFileMenu = function(item) {
    const ext = item.ext.toLowerCase();
    const isImg = ['jpg','jpeg','png','gif','webp','svg'].includes(ext);
    const isText = ['txt','md','json','php','js','css','html','log','xml','yaml'].includes(ext);
    const isPdf = ext === 'pdf';
    const isVid = ['mp4','webm','mov','ogg'].includes(ext);
    
    const options = [];
    if (isImg) options.push({ label: "👁️ View Image", value: "view" });
    if (isText) options.push({ label: "📝 View Text/Code", value: "view_text" });
    if (isPdf) options.push({ label: "📕 View PDF", value: "view_pdf" });
    if (isVid) options.push({ label: "🎬 Play Video", value: "view_video" });
    
    options.push({ label: "Download", value: "download" });
    options.push({ label: "Rename", value: "rename" });
    options.push({ label: "Delete", value: "delete" });
    window.openPicker(item.name, options, null, (val) => {
        if (val === 'view_text') {
            fvViewText(item);
        }
        if (val === 'view_pdf') {
            fvViewPdf(item);
        }
        if (val === 'view_video') {
            fvViewVideo(item);
        }
        if (val === 'download') {
            window.location.href = `index.php?plugin_action=fv_download&path=${encodeURIComponent(item.path)}`;
        }
        if (val === 'rename') {
            window.openInput("Rename", "New Name", item.name, async (newName) => {
                if (!newName || newName === item.name) return;
                await window.sui.api('fv_rename', { old_path: item.path, new_name: newName });
                fvRefresh();
            });
        }
        if (val === 'delete') {
            window.openConfirm("Delete File", `Delete ${item.name}?`, async () => {
                await window.sui.api('fv_delete', { paths: [item.path] });
                fvRefresh();
            }, true);
        }
    });
};
JS;
?>