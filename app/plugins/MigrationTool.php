<?php
// ==============================================================================
// PLUGIN: Migration Tool
// DESCRIPTION: Browser Settings Backup.
// Updated: Added "Wipe Local State" button for restoration testing.
// ==============================================================================

// --- SHARED CONFIGURATION ---
function get_migration_exclusions() {
    return [
        'dirs' => [
            'Recordings', 'recordings', 'backups',
            '.git', '.idea', 'vscode', 'vendor', 'node_modules'
        ],
        'exact_files' => [
    'conjure.db', 'conjure.db-shm', 'conjure.db-wal', 'conjure.db-journal', 
    'access_log', 'access.log', '.DS_Store', 'error_log', 
    'client_state_backup.zip', 'firewall.php', 'sys_map.json', 'edit-log.json'
]];
}

// --- BACKEND HANDLERS ---

// 1. DISTRIBUTABLE ZIP EXPORT (Source Code Only)
if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'migration_download_dist') {
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0); ini_set('memory_limit', '512M');
    $rootPath = CJOS_PATH_ROOT;
    $filename = 'Conjure_Dist_' . date('Ymd_Hi') . '.zip';
    $zipPath = sys_get_temp_dir() . '/' . $filename;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) die("Error creating zip");

    $excludes = get_migration_exclusions();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($rootPath) + 1);
            $fileName = $file->getFilename();
            $parts = explode(DIRECTORY_SEPARATOR, $relativePath); 
            
            // Exclusions
            $relBackups = str_replace($rootPath . '/', '', CJOS_PATH_APP . '/backups');
            $relData = str_replace($rootPath . '/', '', CJOS_PATH_DATA);
            if (in_array($parts[0], $excludes['dirs'])) continue; 
            if (strpos($relativePath, $relBackups) === 0) continue;
            if (strpos($relativePath, $relData . '/projects/') === 0 && strpos($fileName, '_Blueprint') !== 0) continue;
            if (in_array($fileName, $excludes['exact_files'])) continue;
            if (strpos($fileName, '-private.') !== false) continue;
            if (strpos($fileName, 'Project_Code_') !== false) continue;
            if (pathinfo($filePath, PATHINFO_EXTENSION) === 'db') continue;
            if (pathinfo($filePath, PATHINFO_EXTENSION) === 'zip') continue;

            $zip->addFile($filePath, $relativePath);
        }
    }
    $zip->close();
    if (file_exists($zipPath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath); unlink($zipPath); exit;
    }
}

// 2. CODE EXPORT (TXT)
if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'migration_export_code') {
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0); ini_set('memory_limit', '512M');
    $rootPath = CJOS_PATH_ROOT; 
    $filename = 'Project_Code_' . date('md_Hi') . '.txt';
    $allowed_extensions = ['php', 'js', 'css', 'html', 'json', 'sql', 'htaccess', 'txt', 'md'];
    $excludes = get_migration_exclusions();
    $file_list = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    
    // Load AppMaker Exclusions
    $am_conf_file = CJOS_PATH_DATA . '/app-maker-config.json';
    $am_excluded = [];
    if (file_exists($am_conf_file)) {
        $am_data = json_decode(file_get_contents($am_conf_file), true);
        $am_excluded = $am_data['excluded_from_export'] ?? [];
    }

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $relativePath = substr($path, strlen($rootPath) + 1);
        $fileName = $file->getFilename();
        
        $parts = explode('/', str_replace('\\', '/', $relativePath));
        if (in_array($parts[0], $excludes['dirs'])) continue; 
        $relData = str_replace($rootPath . '/', '', CJOS_PATH_DATA);
        if (strpos($relativePath, $relData . '/projects/') === 0 && strpos($fileName, '_Blueprint') !== 0) continue;

        // AppMaker Context Filter
        if ($parts[0] === 'apps' && isset($parts[1])) {
            if (in_array($parts[1], $am_excluded)) continue;
        }
        $relBackups = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_APP . '/backups');
if (strpos($relativePath, $relBackups) === 0) continue;
        if (in_array($fileName, $excludes['exact_files'])) continue;
        if (strpos($fileName, '-private') !== false) continue; 
        if (strpos($fileName, 'Project_Code_') !== false) continue;

        if ($parts[0] === 'app' && $parts[1] === 'plugins' && count($parts) === 3) {
    if (!isset($enabled_map)) {
        $ui_config_file = CJOS_PATH_DATA . '/ui-config.json';
        $ui_config = file_exists($ui_config_file) ? json_decode(file_get_contents($ui_config_file), true) : [];
        $enabled_map = $ui_config['plugins_enabled'] ?? [];
    }
    $pName = basename($fileName, '.php');
    $pKey = 'plugin_' . $pName;
    if (isset($enabled_map[$pKey]) && ($enabled_map[$pKey] === 'false' || $enabled_map[$pKey] === false || $enabled_map[$pKey] === '0')) continue;
                
    $bk_conf_path = CJOS_PATH_DATA . '/backup-config.json';
    $bk_conf_data = file_exists($bk_conf_path) ? json_decode(file_get_contents($bk_conf_path), true) : [];
    $ex_list = $bk_conf_data['excluded_plugins'] ?? [];
    if (in_array($pName, $ex_list)) continue;
}if ($file->isFile() && in_array(pathinfo($path, PATHINFO_EXTENSION), $allowed_extensions)) $file_list[] = $relativePath;
    }
    sort($file_list);

    $output = "PROJECT STRUCTURE\n=================\n";
    foreach($file_list as $f) $output .= $f . "\n";
    $output .= "\n";

    if (function_exists('get_ai_sys_instructions')) {
        $output .= get_ai_sys_instructions();
    }

    // --- PROJECT PLANNER INJECTION (MULTI-SUPPORT) ---
    $pp_config_file = CJOS_PATH_DATA . '/project-planner-config.json';
    if (file_exists($pp_config_file)) {
        $pp_conf = json_decode(file_get_contents($pp_config_file), true);
        $active_list = [];
        if (!empty($pp_conf['active_projects'])) $active_list = $pp_conf['active_projects'];
        elseif (!empty($pp_conf['active_project'])) $active_list = [$pp_conf['active_project']];
        
        foreach ($active_list as $filename) {
            $plan_path = CJOS_PATH_DATA . '/projects/' . $filename;
            if (file_exists($plan_path)) {
                $plan_content = file_get_contents($plan_path);
                $output .= "################################################################################\n";
                $output .= "### ACTIVE PROJECT MASTER PLAN\n";
                $output .= "### File: " . $filename . "\n";
                $output .= "################################################################################\n";
                $output .= "PROJECT MANAGEMENT PROTOCOL:\n";
                $output .= "1. CONDITIONAL MAINTENANCE: Only update this plan if your current task directly relates to the project described.\n";
                $output .= "2. SCOPE VERIFICATION: Check the 'Scope' or 'Relevant Files' section below. If the files you are patching are not listed, do not modify this plan.\n";
                $output .= "3. BLUEPRINT PROTECTION: Files starting with '_' (like _Blueprint.md) are templates for reference only. NEVER update them unless specifically asked to change the structure of the planning system itself.\n";
                $output .= "4. YAML INTEGRITY: If updating, ensure the Status and LastUpdated fields are updated to reflect reality.\n";
                $output .= "--------------------------------------------------------------------------------\n\n";
                $output .= $plan_content . "\n\n";

                // Check for linked Audit Checklist
                $auditPath = CJOS_PATH_DATA . '/projects/' . str_replace('.md', '.audit.json', $filename);
                if (file_exists($auditPath)) {
                    $output .= "\nLINKED REFACTOR CHECKLIST (JSON):\n";
                    $output .= "File: " . str_replace('.md', '.audit.json', $filename) . "\n";
                    $output .= "```json\n" . file_get_contents($auditPath) . "\n```\n";
                }

                $output .= "################################################################################\n\n";
            }
        }
    }

    foreach ($file_list as $f) {
        $fullPath = $rootPath . '/' . $f;
        if(is_readable($fullPath)) {
            $content = file_get_contents($fullPath);


   

            $output .= "================================================================================\n";
            $output .= "FILE START: " . $f . "\n";
            $output .= "================================================================================\n";
            $output .= "```" . pathinfo($f, PATHINFO_EXTENSION) . "\n" . $content . "\n```\n\n"; 
        }
    }
    
    $filename = 'Project_Code_' . date('md_Hi') . '.txt';
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo $output; exit;
}

// 3. CLIENT STATE: SAVE ZIP TO SERVER (Used by both Export buttons)
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'mig_client_save_zip') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $json = $_POST['client_json'] ?? '{}';
    $targetPath = CJOS_PATH_DATA . '/client_state_backup.zip';
    
    if (!class_exists('ZipArchive')) {
        echo json_encode(['status' => 'error', 'message' => 'ZipArchive PHP extension missing']);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $zip->addFromString('state.json', $json);
        $zip->close();
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Could not create zip in data/ folder. Check permissions.']);
    }
    exit;
}

// 4. CLIENT STATE: DOWNLOAD PERSISTENT ZIP
if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'mig_client_download_persistent') {
    $targetPath = CJOS_PATH_DATA . '/client_state_backup.zip';
    $downloadName = 'Client_State_' . date('Ymd_His') . '.zip';

    if (file_exists($targetPath)) {
        // Aggressive buffer cleaning
        while (ob_get_level()) ob_end_clean();
        header_remove(); 
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($targetPath));
        
        readfile($targetPath);
        exit;
    } else {
        while (ob_get_level()) ob_end_clean();
        header("HTTP/1.0 404 Not Found");
        die("Backup file not found on server.");
    }
}

// 5. CLIENT STATE: RESTORE (UPLOAD)
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'mig_client_import_upload') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    if (empty($_FILES['file'])) { echo json_encode(['status' => 'error', 'message' => 'No file uploaded']); exit; }
    
    $zip = new ZipArchive();
    if ($zip->open($_FILES['file']['tmp_name']) === TRUE) {
        $json = $zip->getFromName('state.json');
        $zip->close();
        if ($json) {
            echo json_encode(['status' => 'success', 'data' => json_decode($json, true)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'state.json not found inside zip']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid zip file']);
    }
    exit;
}

// 6. CLIENT STATE: RESTORE (SERVER)
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'mig_client_import_server') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $targetPath = CJOS_PATH_DATA . '/client_state_backup.zip';
    
    if (!file_exists($targetPath)) { echo json_encode(['status' => 'error', 'message' => 'No backup found (data/client_state_backup.zip)']); exit; }
    
    $zip = new ZipArchive();
    if ($zip->open($targetPath) === TRUE) {
        $json = $zip->getFromName('state.json');
        $zip->close();
        if ($json) {
            echo json_encode(['status' => 'success', 'data' => json_decode($json, true)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'state.json not found inside zip']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Corrupt backup file']);
    }
    exit;
}

// --- FRONTEND UI ---
$plugin_settings_map['MigrationTool'] = <<<'HTML'
<div class="setting-item vertical">
    <label class="setting-label">Export App Source</label>
    <div class="setting-desc">Clean exports (excludes private keys and logs).</div>
    <div style="display:flex; gap:10px; margin-top:8px;">
        <button onclick="downloadDistZip(this)" class="text-btn" style="flex:1; background:var(--primary); color:white; border-radius:12px; padding:12px; font-weight:600;">ZIP Archive</button>
        <button onclick="downloadDistCode(this)" class="text-btn" style="flex:1; background:#E5E5EA; color:var(--text-primary); border-radius:12px; padding:12px; font-weight:600;">Code (.txt)</button>
    </div>
</div>

<div class="setting-item vertical">
    <label class="setting-label">Client State & Settings</label>
    <div class="setting-desc">Backup LocalStorage and Cookies (Settings, UI Prefs, PIN Token).</div>
    
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:12px;">
        <!-- EXPORT BUTTONS -->
        <button onclick="migExportClientDownload(this)" class="text-btn" style="background:var(--input-bg); border:1px solid var(--border-color); color:var(--input-text); border-radius:12px; padding:12px; font-size:13px; font-weight:600;">
            <span style="display:block; font-size:16px; margin-bottom:4px;">⬇️</span> Export to File
        </button>
        <button onclick="migExportClientServer(this)" class="text-btn" style="background:var(--input-bg); border:1px solid var(--border-color); color:var(--input-text); border-radius:12px; padding:12px; font-size:13px; font-weight:600;">
            <span style="display:block; font-size:16px; margin-bottom:4px;">☁️</span> Export to Server
        </button>

        <!-- RESTORE BUTTONS -->
        <button onclick="document.getElementById('mig-upload-input').click()" class="text-btn" style="background:var(--input-bg); border:1px solid var(--border-color); color:var(--input-text); border-radius:12px; padding:12px; font-size:13px; font-weight:600;">
            <span style="display:block; font-size:16px; margin-bottom:4px;">📂</span> Restore from File
        </button>
        <button onclick="migRestoreClientServer(this)" class="text-btn" style="background:var(--input-bg); border:1px solid var(--border-color); color:var(--input-text); border-radius:12px; padding:12px; font-size:13px; font-weight:600;">
            <span style="display:block; font-size:16px; margin-bottom:4px;">🔄</span> Restore from Server
        </button>
    </div>
    
    <!-- WIPE BUTTON -->
    <button onclick="migWipeClientState()" class="text-btn" style="width:100%; margin-top:10px; background:var(--danger); border:none; color:var(--primary-text); border-radius:12px; padding:12px; font-size:13px; font-weight:600;">
        ⚠️ Wipe Local State (Testing)
    </button>
    
    <!-- Hidden Upload Input -->
    <input type="file" id="mig-upload-input" accept=".zip" style="display:none;" onchange="migRestoreClientUpload(this)">
    <div id="mig-status-msg" style="text-align:center; font-size:12px; color:#8E8E93; margin-top:10px; height:16px;"></div>
</div>
HTML;

// --- JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- MIGRATION TOOL JS ---

// A. Source Code Export
function downloadDistZip(b){ const t=b.innerHTML; b.innerHTML="Zipping..."; const i=document.createElement("iframe"); i.style.display="none"; i.src="?plugin_action=migration_download_dist"; document.body.appendChild(i); setTimeout(()=>{b.innerHTML=t;},5000); }
function downloadDistCode(b){ const t=b.innerHTML; b.innerHTML="Generating..."; const i=document.createElement("iframe"); i.style.display="none"; i.src="?plugin_action=migration_export_code"; document.body.appendChild(i); setTimeout(()=>{b.innerHTML=t;},5000); }

// --- CLIENT STATE LOGIC ---

function migGetClientState() {
    return JSON.stringify({
        localStorage: JSON.stringify(localStorage),
        cookies: document.cookie
    });
}

function migApplyClientState(data) {
    if (!data) return false;
    try {
        // 1. Restore LocalStorage
        if (data.localStorage) {
            const ls = JSON.parse(data.localStorage);
            localStorage.clear();
            for (const key in ls) {
                localStorage.setItem(key, ls[key]);
            }
        }
        // 2. Restore Cookies
        if (data.cookies) {
            const cookies = data.cookies.split(';');
            cookies.forEach(c => {
                const parts = c.split('=');
                if(parts.length < 2) return;
                const name = parts[0].trim();
                const val = parts.slice(1).join('=').trim();
                if (name) {
                    document.cookie = `${name}=${val}; path=/; max-age=31536000`;
                }
            });
        }
        return true;
    } catch(e) {
        console.error(e);
        return false;
    }
}

function migWipeClientState() {
    window.openConfirm("Wipe Local State", "DANGER: This will delete all LocalStorage settings and Cookies from this browser.\n\nUse this only to test restoration.\n\nAre you sure?", () => {
        // Clear LocalStorage
        localStorage.clear();
        
        // Clear Cookies
        document.cookie.split(";").forEach(function(c) { 
            document.cookie = c.replace(/^ +/, "").replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/"); 
        });
        
        window.openConfirm("Wipe Complete", "Browser state wiped. App will reload.", () => {
            location.reload();
        }, false, "OK", null);
    }, true);
}

function migSetStatus(msg, isErr=false) {
    const el = document.getElementById('mig-status-msg');
    if(el) {
        el.innerText = msg;
        el.style.color = isErr ? '#FF3B30' : '#34C759';
        setTimeout(() => el.innerText = '', 4000);
    }
}

// 1. Export & Download (Simpler: Save to server -> Redirect to Stream)
async function migExportClientDownload(btn) {
    const orig = btn.innerHTML;
    btn.innerText = "Processing...";
    
    // Step 1: Save JSON to data/client_state_backup.zip
    try {
        const data = await window.sui.api('mig_client_save_zip', { client_json: migGetClientState() }, { toast: false });
        
        if (data) {
            btn.innerText = "Downloading...";
            migSetStatus("Download started");
            
            // Step 2: Trigger direct download of the persistent file
            // Using window.location.href ensures browser handles it as a navigation/download
            const baseUrl = window.location.href.split('?')[0]; 
            // Add random param to prevent caching of the ZIP
            window.location.href = baseUrl + "?plugin_action=mig_client_download_persistent&nocache=" + Date.now();
            
            setTimeout(() => { btn.innerHTML = orig; }, 3000);
        } else {
            migSetStatus("Error: " + data.message, true);
            btn.innerHTML = orig;
        }
    } catch(e) {
        migSetStatus("Connection error", true);
        btn.innerHTML = orig;
    }
}

// 2. Export to Server (Only Save)
async function migExportClientServer(btn) {
    const orig = btn.innerHTML;
    btn.innerText = "Saving...";
    
    try {
        const d = await window.sui.api('mig_client_save_zip', { client_json: migGetClientState() }, { toast: false });
        if(d) migSetStatus("Saved to server data folder.");
    } catch(e) { migSetStatus("Connection error", true); }
    btn.innerHTML = orig;
}

// 3. Restore from Upload
async function migRestoreClientUpload(input) {
    if (input.files.length === 0) return;
    const file = input.files[0];
    const fd = new FormData();
    fd.append('plugin_action', 'mig_client_import_upload');
    fd.append('file', file);
    
    try {
        migSetStatus("Restoring...");
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const d = await res.json();
        if(d.status === 'success') {
            if(migApplyClientState(d.data)) {
                window.openConfirm("Restore Success", "State Restored! App will reload.", () => {
                    location.reload();
                }, false, "OK", null);
            } else {
                migSetStatus("Error parsing state data", true);
            }
        } else {
            migSetStatus(d.message, true);
        }
    } catch(e) { migSetStatus("Upload failed", true); }
    input.value = ''; // Reset
}

// 4. Restore from Server
async function migRestoreClientServer(btn) {
    window.openConfirm("Restore from Server", "Overwrite current browser settings with version from server?", async () => {
        const orig = btn.innerHTML;
    btn.innerText = "Loading...";
    
        try {
            const d = await window.sui.api('mig_client_import_server', {}, { toast: false });
            if(d) {
                if(migApplyClientState(d.data)) {
                    window.openConfirm("Restore Success", "Restored from server! App will reload.", () => {
                        location.reload();
                    }, false, "OK", null);
                } else {
                    migSetStatus("Error applying state", true);
                }
            } else {
                migSetStatus(d.message, true);
            }
        } catch(e) { migSetStatus("Connection error", true); }
        btn.innerHTML = orig;
    });
}
JS;
?>