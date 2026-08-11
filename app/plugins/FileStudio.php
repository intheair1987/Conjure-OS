<?php
// ==============================================================================
// PLUGIN: File Studio
// DESCRIPTION: System File Editor & JSON Explorer.
// Provides a centralized API for viewing and editing files via Studios.
// ==============================================================================

if (isset($_POST['plugin_action'])) {
    $root = CJOS_PATH_ROOT . '/';

    // 1. GET FILE CONTENT
    if ($_POST['plugin_action'] === 'fs_get_file') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $relPath = $_POST['path'] ?? '';
        $fullPath = realpath($root . $relPath);

        if (!$fullPath || strpos($fullPath, realpath($root)) !== 0 || !file_exists($fullPath)) {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied or File Not Found.']);
            exit;
        }

        echo json_encode([
            'status' => 'success', 
            'content' => file_get_contents($fullPath),
            'mtime' => filemtime($fullPath)
        ]);
        exit;
    }

    // 2. SAVE FILE CONTENT
    if ($_POST['plugin_action'] === 'fs_save_file') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $relPath = $_POST['path'] ?? '';
        $content = $_POST['content'] ?? '';
        $fullPath = realpath($root . $relPath);

        if (!$fullPath || strpos($fullPath, realpath($root)) !== 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Path.']);
            exit;
        }

        if (file_put_contents($fullPath, $content) !== false) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Write Failed. Check permissions.']);
        }
        exit;
    }
}

// --- SETTINGS UI ---
$plugin_settings_map['FileStudio'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Available Viewers</label>
        <div class="setting-desc">The following specialized interfaces are available for system files:</div>
        
        <div style="display:grid; grid-template-columns: 1fr; gap:8px; margin-top:10px;">
            <!-- Code Editor: Primary Blue -->
            <div style="background:var(--card-bg); padding:12px; border-radius:12px; display:flex; align-items:center; gap:12px; border:1px solid var(--border-color);">
                <div style="width:32px; height:32px; border-radius:8px; background:var(--primary); color:var(--primary-text); display:flex; align-items:center; justify-content:center;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"></path></svg>
                </div>
                <div>
                    <div style="font-size:13px; font-weight:700; color:var(--text-primary);">Code Editor</div>
                    <div style="font-size:10px; color:var(--text-secondary);">PHP, JS, CSS, TXT</div>
                </div>
            </div>
            
            <!-- JSON Explorer: AI Purple -->
            <div style="background:var(--card-bg); padding:12px; border-radius:12px; display:flex; align-items:center; gap:12px; border:1px solid var(--border-color);">
                <div style="width:32px; height:32px; border-radius:8px; background:var(--ai-accent-bg); color:var(--ai-accent); display:flex; align-items:center; justify-content:center; border:1px solid rgba(88, 86, 214, 0.2);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
                </div>
                <div>
                    <div style="font-size:13px; font-weight:700; color:var(--text-primary);">JSON Explorer</div>
                    <div style="font-size:10px; color:var(--text-secondary);">Interactive Accordion View</div>
                </div>
            </div>

            <!-- Markdown Viewer: Semantic Yellow/Orange -->
            <div style="background:var(--card-bg); padding:12px; border-radius:12px; display:flex; align-items:center; gap:12px; border:1px solid var(--border-color);">
                <div style="width:32px; height:32px; border-radius:8px; background:var(--warn-bg); color:var(--warn-text); display:flex; align-items:center; justify-content:center; border:1px solid rgba(133, 100, 4, 0.2);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                </div>
                <div>
                    <div style="font-size:13px; font-weight:700; color:var(--text-primary);">Markdown Viewer</div>
                    <div style="font-size:10px; color:var(--text-secondary);">Formatted Rich Text</div>
                </div>
            </div>
        </div>
    </div>
HTML;

// --- JS ENGINE ---
$plugin_js .= <<<'JS'
window.fsState = {
    currentPath: null,
    initialContent: null,
    extraFooter: null
};

// Unified declaration of supported file types for System Navigator integration
window.fsSupportedExtensions = ['php', 'js', 'css', 'txt', 'json', 'md', 'html', 'htm', 'sql', 'sh', 'bash', 'ps1', 'py', 'env', 'ini', 'conf', 'yaml', 'yml', 'toml'];

/**
 * Main Entry Point for other plugins.
 * Automatically chooses the best viewer based on extension.
 */
window.fsOpen = function(path, options = {}) {
    fsState.extraFooter = options.footer || null;
    const ext = path.split('.').pop().toLowerCase();
    if (ext === 'json') {
        window.fsOpenJson(path);
    } else if (ext === 'md') {
        window.fsOpenMarkdown(path);
    } else {
        window.fsOpenCode(path);
    }
};

// --- MARKDOWN VIEWER ---
window.fsOpenMarkdown = async function(path) {
    try {
        const data = await window.sui.api("fs_get_file", { path: path }, { toast: "Rendering Markdown..." });
        if (data && data.status === 'success') {
            
            const content = `
                <style>
                    #fs-md-body { font-size: 15px; line-height: 1.6; color: var(--text-primary); }
                    #fs-md-body h1, #fs-md-body h2, #fs-md-body h3 { color: var(--text-title); margin-top: 24px; }
                    #fs-md-body code { background: rgba(0,0,0,0.05); padding: 2px 5px; border-radius: 4px; font-family: monospace; font-size: 0.9em; }
                    #fs-md-body pre { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 12px; overflow-x: auto; margin: 16px 0; }
                    #fs-md-body blockquote { border-left: 4px solid var(--primary); background: var(--bg-color); margin: 16px 0; padding: 8px 16px; font-style: italic; border-radius: 0 8px 8px 0; }
                    #fs-md-body table { width: 100%; border-collapse: collapse; margin: 16px 0; border: 1px solid var(--border-color); }
                    #fs-md-body th, #fs-md-body td { border: 1px solid var(--border-color); padding: 10px; text-align: left; }
                    #fs-md-body th { background: var(--bg-color); }
                    #fs-md-body ul { padding-left: 20px; }
                    #fs-md-body li { margin-bottom: 4px; }
                    #fs-md-body input[type="checkbox"] { margin-right: 8px; pointer-events: none; }
                </style>
                <div style="font-family:monospace; font-size:10px; color:var(--primary); margin-bottom:16px; word-break:break-all;">${path}</div>
                <div id="fs-md-body">Loading...</div>
                <div style="margin-top:24px; padding-top:16px; border-top:1px solid var(--border-color); display:flex; flex-wrap:wrap; gap:12px;">
                    <button onclick="window.fsOpenCode('${path}')" class="text-btn" style="flex:1; min-width:120px; background:var(--btn-bg); border-radius:12px; padding:12px; font-size:12px; font-weight:700;">Edit Source</button>
                    ${fsState.extraFooter || ''}
                    <button onclick="window.sui.closeStudio('fs-markdown')" class="text-btn" style="flex:1; min-width:120px; background:var(--primary); color:white; border-radius:12px; padding:12px; font-size:12px; font-weight:700;">Done</button>
                </div>
            `;

            window.sui.openStudio({
                id: 'fs-markdown',
                title: 'Markdown Viewer',
                content: content,
                onSetup: (container) => {
                    const body = document.getElementById("fs-md-body");
                    // Ensure marked is available (it should be as ProjectPlanner loads it)
                    if (typeof marked !== "undefined") {
                        body.innerHTML = marked.parse(data.content.replace(/---[\s\S]+?---/, '').trim());
                    } else {
                        body.innerText = data.content;
                    }
                }
            });
        }
    } catch(e) { console.error("Markdown render error", e); }
};

// --- CODE EDITOR ---
window.fsOpenCode = async function(path) {
    try {
        const data = await window.sui.api("fs_get_file", { path: path }, { toast: "Loading File..." });
        if (data && data.status === 'success') {
            fsState.currentPath = path;
            // Normalize initial content to Unix line endings for reliable comparison
            fsState.initialContent = data.content.replace(/\r\n/g, '\n');

            const content = `
                <div style="display:flex; flex-direction:column; min-height:70vh;">
                    <div style="font-family:monospace; font-size:10px; color:var(--primary); margin-bottom:8px; word-break:break-all;">${path}</div>
                    <textarea id="fs-code-area" style="flex:1; width:100%; min-height:50vh; background:var(--input-bg); color:var(--input-text); border:1px solid var(--border-color); border-radius:12px; padding:15px; font-family:monospace; font-size:13px; line-height:1.5; outline:none; resize:none;"></textarea>
                    <div style="margin-top:20px; display:flex; flex-wrap:wrap; gap:12px; padding-bottom:10px;">
                        <button onclick="window.sui.closeStudio('fs-code')" class="text-btn" style="flex:1; min-width:100px; background:var(--btn-bg); border-radius:12px; padding:14px; font-weight:700;">Discard</button>
                        ${fsState.extraFooter || ''}
                        <button id="fs-save-btn" class="text-btn" style="flex:2; min-width:150px; background:var(--primary); color:white; border-radius:12px; padding:14px; font-weight:700;">Save Changes</button>
                    </div>
                </div>
            `;

            window.sui.openStudio({
                id: 'fs-code',
                title: 'Code Editor',
                content: content,
                hasChanges: () => {
                    const current = document.getElementById("fs-code-area")?.value || "";
                    // Compare using normalized Unix line endings
                    return current.replace(/\r\n/g, '\n') !== fsState.initialContent;
                },
                onSave: fsSaveCode,
                onSetup: (container) => {
                    const area = document.getElementById("fs-code-area");
                    area.value = data.content;
                    document.getElementById("fs-save-btn").onclick = fsSaveCode;
                    
                    // Tab-key support
                    area.addEventListener('keydown', (e) => {
                        if (e.key === 'Tab') {
                            e.preventDefault();
                            const start = area.selectionStart;
                            const end = area.selectionEnd;
                            area.value = area.value.substring(0, start) + "    " + area.value.substring(end);
                            area.selectionStart = area.selectionEnd = start + 4;
                        }
                    });
                }
            });
        }
    } catch(e) {}
};

async function fsSaveCode() {
    const content = document.getElementById("fs-code-area").value;
    const res = await window.sui.api("fs_save_file", { path: fsState.currentPath, content: content }, { toast: "File Saved" });
    if (res && res.status === 'success') {
        fsState.initialContent = content;
        window.sui.closeStudio('fs-code');
    }
}

// --- JSON EXPLORER ---
window.fsOpenJson = async function(pathOrData, titleOverride = null) {
    let json = null;
    let displayPath = pathOrData;

    if (typeof pathOrData === 'object' && pathOrData !== null) {
        // Handle In-Memory Object
        json = pathOrData;
        displayPath = titleOverride || "In-Memory JSON";
    } else {
        // Handle File Path
        try {
            const data = await window.sui.api("fs_get_file", { path: pathOrData }, { toast: "Parsing JSON..." });
            if (data && data.status === 'success') {
                json = JSON.parse(data.content);
            }
        } catch(e) { 
            window.openConfirm("JSON Error", "Failed to parse JSON file structure.", null, false, "OK", null); 
            return;
        }
    }

    if (!json) return;
    
    const content = `
        <div style="font-family:monospace; font-size:10px; color:var(--primary); margin-bottom:16px; word-break:break-all;">${displayPath}</div>
        <div id="fs-json-tree"></div>
        <div style="margin-top:24px; padding-top:16px; border-top:1px solid var(--border-color); display:flex; flex-wrap:wrap; gap:12px;">
            ${typeof pathOrData === 'string' ? `<button onclick="window.fsOpenCode('${pathOrData}')" class="text-btn" style="flex:1; min-width:120px; background:var(--btn-bg); border-radius:12px; padding:12px; font-size:12px; font-weight:700;">Edit Source</button>` : ''}
            ${fsState.extraFooter || ''}
            <button onclick="window.sui.closeStudio('fs-json')" class="text-btn" style="flex:1; min-width:120px; background:var(--primary); color:white; border-radius:12px; padding:12px; font-size:12px; font-weight:700;">Done</button>
        </div>
    `;

    window.sui.openStudio({
        id: 'fs-json',
        title: titleOverride || 'JSON Explorer',
        content: content,
        onSetup: (container) => {
            const tree = document.getElementById("fs-json-tree");
            tree.appendChild(fsRenderNode("ROOT", json, true));
        }
    });
};

function fsRenderNode(key, val, isRoot = false) {
    const cont = document.createElement("div");
    cont.style.marginBottom = "4px";

    if (typeof val === 'object' && val !== null) {
        // Object or Array (Accordion)
        const isArr = Array.isArray(val);
        const count = isArr ? val.length : Object.keys(val).length;
        const id = "fs-node-" + Math.random().toString(36).substr(2, 9);
        
        const title = isRoot ? "{ ROOT }" : `<span style="color:var(--text-secondary); font-weight:400;">${key}:</span> <span style="font-weight:800;">${isArr ? '[' : '{'} ${count} items ${isArr ? ']' : '}'}</span>`;
        
        const inner = document.createElement("div");
        inner.style.padding = "8px 0 8px 12px";
        inner.style.borderLeft = "1px dashed var(--border-color)";
        inner.style.marginLeft = "8px";
        
        for (let k in val) {
            inner.appendChild(fsRenderNode(k, val[k]));
        }

        cont.innerHTML = window.suiAccordion(id, title, inner.outerHTML, isRoot);
        window.suiHydrateIcons(cont);
    } else {
        // Primitive Value
        let valDisplay = val;
        let valColor = "#34C759"; // String green
        if (typeof val === 'number') valColor = "#007AFF";
        if (typeof val === 'boolean') valColor = "#FF9500";
        if (val === null) valColor = "#8E8E93";

        cont.style.padding = "4px 8px";
        cont.style.fontSize = "13px";
        cont.innerHTML = `
            <span style="color:var(--text-secondary); font-family:monospace;">${key}:</span>
            <span style="color:${valColor}; font-weight:600; font-family:monospace; word-break:break-all;">${JSON.stringify(val)}</span>
        `;
    }
    return cont;
}
JS;
?>