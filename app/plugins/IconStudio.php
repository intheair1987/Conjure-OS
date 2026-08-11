<?php
// ==============================================================================
// PLUGIN: Icon Studio
// DESCRIPTION: Manage, preview, and download Lucide icons from the CDN.
// ==============================================================================

if (isset($_POST['plugin_action'])) {
    
    // API: Download Icon from CDN
    if ($_POST['plugin_action'] === 'is_download') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $iconName = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['icon_name'] ?? ''));
        $destPath = $_POST['dest_path'] ?? ('app/data/icons/' . $iconName . '.svg');
        
        if (!$iconName) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid icon name']);
            exit;
        }

        $url = "https://unpkg.com/lucide-static@latest/icons/{$iconName}.svg";
        
        // Check for allow_url_fopen
        if (!ini_get('allow_url_fopen')) {
            echo json_encode(['status' => 'error', 'message' => 'Server restriction: allow_url_fopen is disabled. Please enable it in php.ini.']);
            exit;
        }

        // Fetch with context to suppress warnings on 404
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $http_response_header = []; // Initialize to prevent notices
        $svg = @file_get_contents($url, false, $context);
        
        $isOk = false;
        foreach ($http_response_header as $header) {
            if (strpos($header, 'HTTP/') === 0 && strpos($header, '200') !== false) {
                $isOk = true;
                break;
            }
        }

        if ($svg && $isOk) {
            $fullPath = CJOS_PATH_ROOT . '/' . ltrim($destPath, '/');
            $dir = dirname($fullPath);
            
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to create directory: ' . $dir]);
                exit;
            }
            
            if (strpos($svg, '<svg') !== false) {
                if (@file_put_contents($fullPath, $svg) === false) {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to write file: ' . $destPath]);
                } else {
                    echo json_encode(['status' => 'success', 'path' => $destPath]);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid SVG content received from CDN']);
            }
        } else {
            $msg = $svg ? "CDN returned an error (HTTP " . ($http_response_header[0] ?? 'Unknown') . ")" : "Could not connect to CDN or icon '{$iconName}' does not exist.";
            echo json_encode(['status' => 'error', 'message' => $msg]);
        }
        exit;
    }

    // API: Fetch CDN Index
    if ($_POST['plugin_action'] === 'is_fetch_cdn_index') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $url = "https://unpkg.com/lucide-static@latest/icons/?meta";
        $context = stream_context_create(['http' =>['ignore_errors' => true, 'timeout' => 15]]);
        $json = @file_get_contents($url, false, $context);
        
        if ($json) {
            $data = json_decode($json, true);
            if (isset($data['files'])) {
                $names = [];
                foreach ($data['files'] as $f) {
                    if (isset($f['path']) && strpos($f['path'], '.svg') !== false) {
                        $names[] = str_replace('.svg', '', basename($f['path']));
                    }
                }
                echo json_encode(['status' => 'success', 'icons' => $names]);
                exit;
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid meta format from CDN']);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'Could not connect to CDN index']);
        exit;
    }

    // API: List Local Icons
    if ($_POST['plugin_action'] === 'is_list_local') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $dir = CJOS_PATH_DATA . '/icons';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        $files = glob($dir . '/*.svg');
        $icons = [];
        foreach($files as $f) {
            $icons[] = [
                'name' => basename($f, '.svg'),
                'path' => 'app/data/icons/' . basename($f),
                'svg' => file_get_contents($f)
            ];
        }
        echo json_encode(['status' => 'success', 'icons' => $icons]);
        exit;
    }
    
    // API: Delete Local Icon
    if ($_POST['plugin_action'] === 'is_delete_local') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $path = $_POST['path'] ?? '';
        $fullPath = CJOS_PATH_ROOT . '/' . ltrim($path, '/');
        
        // Security check: only allow deleting from app/data/icons/
        if (strpos($fullPath, CJOS_PATH_DATA . '/icons/') === 0 && file_exists($fullPath)) {
            unlink($fullPath);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid file path']);
        }
        exit;
    }
}

// Inject UI Button into Settings
$plugin_settings_map['IconStudio'] = <<<'HTML'
<div class="setting-item vertical">
    <label class="setting-label">Icon Studio</label>
    <div class="setting-desc">Manage local SVG icons and download from Lucide CDN.</div>
    <button onclick="isOpenStudio()" class="text-btn" style="width:100%; margin-top:8px; background:var(--primary); color:var(--primary-text); border-radius:8px; padding:10px; font-weight:bold;">Open Icon Studio</button>
</div>
HTML;

// Inject JS Logic
$plugin_js .= <<<'JS'
window.isOpenStudio = function() {
    window.sui.openStudio({
        id: 'is-studio',
        title: 'Icon Studio (Lucide)',
        content: `
            <div style="padding: 16px;">
                <div style="display:flex; gap:8px; margin-bottom:12px;">
                    <input type="text" id="is-search" placeholder="Search icons..." oninput="isFilterCdn()" style="flex:1; padding:10px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text);">
                    <button onclick="isSearchCDN()" style="padding:10px 16px; background:var(--primary); color:var(--primary-text); border:none; border-radius:8px; font-weight:bold; cursor:pointer;">Preview</button>
                </div>
                
                <div id="is-cdn-result" style="margin-bottom:16px; display:none; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:16px; text-align:center; position:relative;">
                    <button onclick="document.getElementById('is-cdn-result').style.display='none'" style="position:absolute; top:8px; right:8px; background:none; border:none; color:var(--text-secondary); cursor:pointer; font-size:16px;">&times;</button>
                    <div id="is-cdn-preview" style="margin-bottom:12px; color:var(--text-primary);"></div>
                    <div id="is-cdn-name" style="font-weight:bold; margin-bottom:12px; font-family:monospace;"></div>
                    <div style="display:flex; gap:8px; justify-content:center;">
                        <button onclick="isDownloadCDN()" style="padding:8px 12px; background:var(--btn-bg); color:var(--text-primary); border:none; border-radius:6px; cursor:pointer; font-size:12px; font-weight:bold;">Save to Library</button>
                        <button onclick="isCopyName()" style="padding:8px 12px; background:var(--btn-bg); color:var(--text-primary); border:none; border-radius:6px; cursor:pointer; font-size:12px; font-weight:bold;">Copy Name</button>
                    </div>
                </div>

                <div style="display:flex; gap:8px; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
                    <button onclick="isSwitchTab('local')" id="is-tab-local" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:6px; font-weight:bold; cursor:pointer;">Local Library</button>
                    <button onclick="isSwitchTab('cdn')" id="is-tab-cdn" style="background:var(--btn-bg); color:var(--text-primary); border:none; padding:6px 12px; border-radius:6px; font-weight:bold; cursor:pointer;">Browse CDN</button>
                </div>

                <div id="is-view-local">
                    <div id="is-local-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(80px, 1fr)); gap:12px;"></div>
                </div>

                <div id="is-view-cdn" style="display:none;">
                    <div id="is-cdn-grid" style="display:flex; flex-wrap:wrap; gap:6px; align-content: flex-start;"></div>
                </div>
            </div>
        `,
        onSetup: () => {
            isLoadLocal();
            const searchInput = document.getElementById('is-search');
            if (searchInput) {
                searchInput.addEventListener('keypress', function (e) {
                    if (e.key === 'Enter') isSearchCDN();
                });
            }
        }
    });
};

window.isCdnList = [];

window.isSwitchTab = function(tab) {
    document.getElementById('is-view-local').style.display = tab === 'local' ? 'block' : 'none';
    document.getElementById('is-view-cdn').style.display = tab === 'cdn' ? 'block' : 'none';
    
    document.getElementById('is-tab-local').style.background = tab === 'local' ? 'var(--primary)' : 'var(--btn-bg)';
    document.getElementById('is-tab-local').style.color = tab === 'local' ? 'var(--primary-text)' : 'var(--text-primary)';
    
    document.getElementById('is-tab-cdn').style.background = tab === 'cdn' ? 'var(--primary)' : 'var(--btn-bg)';
    document.getElementById('is-tab-cdn').style.color = tab === 'cdn' ? 'var(--primary-text)' : 'var(--text-primary)';
    
    if (tab === 'cdn' && window.isCdnList.length === 0) {
        isLoadCdnList();
    } else if (tab === 'cdn') {
        isFilterCdn();
    }
};

window.isLoadCdnList = async function() {
    const grid = document.getElementById('is-cdn-grid');
    grid.innerHTML = '<div style="width:100%; text-align:center; padding:20px; color:var(--text-secondary);">Fetching CDN index (1400+ icons)...</div>';
    try {
        const data = await window.sui.api('is_fetch_cdn_index', {}, { toast: false });
        if (data.status === 'success' && data.icons) {
            window.isCdnList = data.icons;
            isFilterCdn();
        } else {
            grid.innerHTML = `<div style="width:100%; text-align:center; padding:20px; color:var(--danger);">Error: ${data.message}</div>`;
        }
    } catch(e) {
        grid.innerHTML = '<div style="width:100%; text-align:center; padding:20px; color:var(--danger);">Network error while fetching index.</div>';
    }
};

window.isFilterCdn = function() {
    if (document.getElementById('is-view-cdn').style.display === 'none') return;
    const q = document.getElementById('is-search').value.toLowerCase().trim();
    const list = q ? window.isCdnList.filter(n => n.includes(q)) : window.isCdnList;
    
    const grid = document.getElementById('is-cdn-grid');
    if (list.length === 0) {
        grid.innerHTML = '<div style="width:100%; text-align:center; padding:20px; color:var(--text-secondary);">No icons match search.</div>';
        return;
    }
    
    const limit = 300; 
    const renderList = list.slice(0, limit);
    
    let html = renderList.map(name => `<div onclick="isSelectCdnIcon('${name}')" style="background:var(--btn-bg); color:var(--text-primary); padding:6px 10px; border-radius:6px; font-size:11px; font-family:monospace; cursor:pointer; border:1px solid var(--border-color); transition: background 0.1s;">${name}</div>`).join('');
    
    if (list.length > limit) {
        html += `<div style="padding:6px 10px; font-size:11px; color:var(--text-secondary); font-style:italic;">+ ${list.length - limit} more...</div>`;
    }
    
    grid.innerHTML = html;
};

window.isSelectCdnIcon = function(name) {
    document.getElementById('is-search').value = name;
    isSearchCDN();
    const searchBox = document.getElementById('is-search');
    if (searchBox) searchBox.scrollIntoView({behavior: 'smooth', block: 'center'});
};

window.isCurrentSearch = '';

window.isSearchCDN = async function() {
    const inputStr = document.getElementById('is-search').value.trim().toLowerCase();
    const input = inputStr.replace(/[^a-z0-9\-]/g, '');
    if (!input) return;
    
    window.isCurrentSearch = input;
    const resDiv = document.getElementById('is-cdn-result');
    const preview = document.getElementById('is-cdn-preview');
    const nameDiv = document.getElementById('is-cdn-name');
    
    resDiv.style.display = 'block';
    preview.innerHTML = '<span class="sui-spin" style="display:inline-block; width:24px; height:24px; border:2px solid var(--primary); border-top-color:transparent; border-radius:50%;"></span>';
    nameDiv.innerText = `Fetching '${input}'...`;

    try {
        const url = `https://unpkg.com/lucide-static@latest/icons/${input}.svg`;
        const response = await fetch(url);
        if (response.ok) {
            const svg = await response.text();
            preview.innerHTML = svg;
            const svgEl = preview.querySelector('svg');
            if(svgEl) {
                svgEl.style.width = '48px';
                svgEl.style.height = '48px';
                svgEl.style.stroke = 'currentColor';
                svgEl.style.strokeWidth = '2';
            }
            nameDiv.innerText = input;
        } else {
            preview.innerHTML = '❌';
            nameDiv.innerText = `Icon '${input}' not found.`;
        }
    } catch (e) {
        preview.innerHTML = '⚠️';
        nameDiv.innerText = 'Network error.';
    }
};

window.isDownloadCDN = async function() {
    if (!window.isCurrentSearch) return;
    
    try {
        const data = await window.sui.api('is_download', { icon_name: window.isCurrentSearch }, { toast: 'Downloading...' });
        if (data.status === 'success') {
            window.sui.toast('Icon saved to library!');
            isLoadLocal();
        } else {
            window.sui.toast(data.message || 'Download failed');
        }
    } catch(e) {
        window.sui.toast('API Error');
    }
};

window.isCopyName = function() {
    if (!window.isCurrentSearch) return;
    navigator.clipboard.writeText(window.isCurrentSearch);
    window.sui.toast('Name copied!');
};

window.isLoadLocal = async function() {
    const grid = document.getElementById('is-local-grid');
    if (!grid) return;
    
    try {
        const data = await window.sui.api('is_list_local', {}, { toast: false });
        if (data.status === 'success') {
            grid.innerHTML = '';
            if (data.icons.length === 0) {
                grid.innerHTML = '<div style="grid-column: 1 / -1; color:var(--text-secondary); font-style:italic; text-align:center; padding: 20px;">No local icons found in app/data/icons/</div>';
                return;
            }
            
            data.icons.forEach(icon => {
                const card = document.createElement('div');
                card.style.cssText = 'background:var(--btn-bg); border:1px solid var(--border-color); border-radius:12px; padding:12px; text-align:center; cursor:pointer; transition:transform 0.1s;';
                card.innerHTML = `
                    <div style="color:var(--text-primary); margin-bottom:8px; display:flex; justify-content:center;">${icon.svg}</div>
                    <div style="font-size:10px; font-family:monospace; color:var(--text-secondary); word-break:break-all;">${icon.name}</div>
                `;
                
                const svgEl = card.querySelector('svg');
                if(svgEl) {
                    svgEl.style.width = '24px';
                    svgEl.style.height = '24px';
                    svgEl.style.stroke = 'currentColor';
                    svgEl.style.strokeWidth = '2';
                }

                card.onclick = () => {
                    window.openPicker(`Icon: ${icon.name}`, [
                        { label: '📋 Copy Name', value: 'copy_name' },
                        { label: '🔗 Copy Path', value: 'copy_path' },
                        { label: '🗑️ Delete', value: 'delete' }
                    ], null, async (val) => {
                        if (val === 'copy_name') {
                            navigator.clipboard.writeText(icon.name);
                            window.sui.toast('Name copied!');
                        } else if (val === 'copy_path') {
                            navigator.clipboard.writeText(icon.path);
                            window.sui.toast('Path copied!');
                        } else if (val === 'delete') {
                            window.openConfirm('Delete Icon', `Delete ${icon.name}.svg?`, async () => {
                                const res = await window.sui.api('is_delete_local', { path: icon.path }, { toast: 'Deleting...' });
                                if(res.status === 'success') {
                                    window.sui.toast('Deleted');
                                    isLoadLocal();
                                } else {
                                    window.sui.toast(res.message || 'Error deleting');
                                }
                            });
                        }
                    });
                };
                
                grid.appendChild(card);
            });
        }
    } catch (e) {
        console.error(e);
    }
};
JS;