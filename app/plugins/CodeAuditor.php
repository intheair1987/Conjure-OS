<?php
// ==============================================================================
// PLUGIN: Code Auditor
// DESCRIPTION: System-Wide Pattern Radar.
// Scans /app and /apps for specific code patterns to ensure 100% refactor coverage.
// This plugin is READ-ONLY and cannot modify files.
// ==============================================================================

$ca_config_file = CJOS_PATH_DATA . '/code-auditor-config.json';
$ca_defaults = ['expand' => true, 'exclude_apps' => false, 'exclude_disabled' => false, 'exclude_data' => false, 'scroll_delay' => 450, 'show_tool' => true];
$ca_config = file_exists($ca_config_file) ? json_decode(file_get_contents($ca_config_file), true) : $ca_defaults;

// --- DATA BRIDGE ---
$ca_bridge_json = json_encode([
    'config' => $ca_config
]);
$plugin_js .= "\nwindow.__CA_BRIDGE__ = $ca_bridge_json;\n";

// --- 1. BACKEND HANDLER ---
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ca_get_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $defaults = ['expand' => true, 'exclude_apps' => false, 'exclude_disabled' => false, 'exclude_data' => false, 'scroll_delay' => 450, 'show_tool' => true];
    $conf = file_exists($ca_config_file) ? json_decode(file_get_contents($ca_config_file), true) : $defaults;
    echo json_encode(['status' => 'success', 'config' => $conf]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ca_save_config') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $settings = json_decode($_POST['settings'], true);
    file_put_contents($ca_config_file, json_encode($settings, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success']);
    exit;
}

if ($_POST['plugin_action'] === 'ca_save_to_project') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
        
    $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
    $results = $_POST['results']; // Already JSON string
        
    $projectDir = CJOS_PATH_DATA . '/projects';
    $auditPath = $projectDir . '/' . str_replace('.md', '.audit.json', $filename);
        
    file_put_contents($auditPath, $results);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($_POST['plugin_action'] === 'ca_mark_item_done') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['project']);
    $auditId = $_POST['audit_id'];
    $targetIdx = (int)$_POST['match_index'];

    $auditPath = CJOS_PATH_DATA . '/projects/' . str_replace('.md', '.audit.json', $filename);

    if (file_exists($auditPath)) {
        $data = json_decode(file_get_contents($auditPath), true);
        $found = false;
        foreach ($data as &$section) {
            if ($section['id'] === $auditId) {
                foreach ($section['matches'] as &$m) {
                    if ((int)$m['index'] === $targetIdx) {
                        $m['done'] = true;
                        $found = true;
                        break 2;
                    }
                }
            }
        }
        if ($found) {
            file_put_contents($auditPath, json_encode($data, JSON_PRETTY_PRINT));
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Item not found in audit.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Audit file missing.']);
    }
    exit;
}

if ($_POST['plugin_action'] === 'ca_get_pattern') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['project']);
    $auditId = $_POST['audit_id'];
    $targetIdx = (int)($_POST['match_index'] ?? 1);

    $auditPath = CJOS_PATH_DATA . '/projects/' . str_replace('.md', '.audit.json', $filename);

    if (file_exists($auditPath)) {
        $data = json_decode(file_get_contents($auditPath), true);
        $section = null;
        foreach ($data as $s) {
            if ($s['id'] === $auditId) { $section = $s; break; }
        }
        if ($section) {
            $expectedMatch = null;
            foreach ($section['matches'] as $m) {
                if ((int)$m['index'] === $targetIdx) { $expectedMatch = $m; break; }
            }
            echo json_encode([
                'status' => 'success', 
                'pattern' => $section['pattern'],
                'file_filter' => $section['file_filter'] ?? null,
                'expected_file' => $expectedMatch ? $expectedMatch['file'] : null,
                'match_details' => $expectedMatch
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Audit section not found.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Audit file missing.']);
    }
    exit;
}

function ca_execute_audit_payload($rawInput, $fileFilter = '', $excludeApps = false, $excludeDisabled = false, $excludeData = false) {
    if (empty($rawInput)) {
        return ['status' => 'error', 'message' => 'No payload provided.'];
    }

    // 1. Parse Multi-Block Protocol
    $blocks = preg_split('/\n#END(?:[:\s][^\n]*)?/i', $rawInput);
    $audits = [];
    foreach ($blocks as $block) {
        if (!trim($block)) continue;
        $idMatch = []; preg_match('/#PATCH_ID:\s*([^\n]+)/', $block, $idMatch);
        $fileMatch = []; preg_match('/#FILE:\s*([^\n]+)/', $block, $fileMatch);
        $patternMatch = []; preg_match('/#PATTERN:\s*\n?([\s\S]*)$/', $block, $patternMatch);
        
        if (isset($patternMatch[1]) && trim($patternMatch[1]) !== '') {
            $audits[] = [
                'id' => isset($idMatch[1]) ? trim($idMatch[1]) : 'Untitled Audit',
                'file' => isset($fileMatch[1]) ? trim($fileMatch[1]) : null,
                'pattern' => trim($patternMatch[1]),
                'is_regex' => (strpos($block, '#REGEX: true') !== false)
            ];
        }
    }

    if (empty($audits)) {
        return ['status' => 'error', 'message' => 'No valid #PATTERN blocks found.'];
    }

    $root = CJOS_PATH_ROOT . '/';
$scanDirs = ['app'];
if (!$excludeApps) {
    $scanDirs[] = 'apps';
}

$enabledPlugins = [];
if ($excludeDisabled) {
    $uiConfPath = CJOS_PATH_DATA . '/ui-config.json';
    if (file_exists($uiConfPath)) {
        $uiConf = json_decode(file_get_contents($uiConfPath), true);
        $enabledPlugins = $uiConf['plugins_enabled'] ?? [];
    }
}

$relData = str_replace($root, '', CJOS_PATH_DATA);
$relStorage = str_replace($root, '', CJOS_PATH_STORAGE);
$relLib = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_APP . '/js/lib');
$excludes = [$relLib, $relStorage, 'backups', '.git', 'node_modules', 'vendor'];
if ($excludeData) {
    $excludes[] = $relData;
}$allowedExt = ['php', 'js', 'css', 'json', 'html', 'htaccess'];

    $results_by_id = [];
    
    try {
        // Prepare file list once for efficiency
        $file_list = [];

        if ($fileFilter !== '') {
            $fullPath = realpath($root . $fileFilter);
            if ($fullPath && strpos($fullPath, realpath($root)) === 0 && file_exists($fullPath)) {
                if (is_dir($fullPath)) {
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS));
                    foreach ($iterator as $file) {
                        if ($file->isDir()) continue;
                        $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
                        if (in_array($ext, $allowedExt)) $file_list[] = $file->getRealPath();
                    }
                } else {
                    $file_list = [$fullPath];
                }
            } else {
                return ['status' => 'error', 'message' => 'Specified path not found or out of scope.'];
            }
        } else {
            foreach ($scanDirs as $dir) {
                $dirPath = $root . $dir;
                if (!is_dir($dirPath)) continue;
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isDir()) continue;
                    $relPath = str_replace('\\', '/', substr($file->getRealPath(), strlen($root)));
                    
                    // 1. Check top-level directory exclusions
foreach ($excludes as $ex) { if (strpos($relPath, $ex) === 0) continue 2; }

// 2. Catch nested data folders (structural check)
if ($excludeData && in_array('data', explode('/', $relPath))) continue;

// 3. Skip disabled plugins if requested
$relPlugins = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_PLUGINS . '/');
if ($excludeDisabled && strpos($relPath, $relPlugins) === 0) {
    $pName = basename($relPath, '.php');
    $pKey = 'plugin_' . $pName;
    $isEnabled = isset($enabledPlugins[$pKey]) ? ($enabledPlugins[$pKey] === 'true' || $enabledPlugins[$pKey] === true || $enabledPlugins[$pKey] === '1') : true;
    if (!$isEnabled) continue;
}$ext = pathinfo($relPath, PATHINFO_EXTENSION);
                    if (in_array($ext, $allowedExt)) $file_list[] = $file->getRealPath();
                }
            }
        }

        // Run each audit
        foreach ($audits as $audit) {
            $pattern = $audit['pattern'];
            $isRegex = $audit['is_regex'];
            if ($isRegex && strlen($pattern) > 0 && $pattern[0] !== '/' && $pattern[0] !== '#') {
                $pattern = '/' . $pattern . '/i';
            }
            $matches = [];

            $current_files = $file_list;
            if (!empty($audit['file'])) {
                $specificPath = realpath($root . $audit['file']);
                if ($specificPath && strpos($specificPath, realpath($root)) === 0 && file_exists($specificPath)) {
                    if (is_dir($specificPath)) {
                        $current_files = [];
                        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($specificPath, RecursiveDirectoryIterator::SKIP_DOTS));
                        foreach ($iterator as $file) {
                            if ($file->isDir()) continue;
                            $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
                            if (in_array($ext, $allowedExt)) $current_files[] = $file->getRealPath();
                        }
                    } else {
                        $current_files = [$specificPath];
                    }
                }
            }

            foreach ($current_files as $fullPath) {
                $content = file_get_contents($fullPath);
                if (strpos($content, $pattern) === false && !$isRegex) continue;

                $relPath = str_replace('\\', '/', substr($fullPath, strlen($root)));
                $lines = explode("\n", $content);
                foreach ($lines as $index => $lineContent) {
                    $found = false;
                    if ($isRegex) { if (@preg_match($pattern, $lineContent)) $found = true; }
                    else { if (strpos($lineContent, $pattern) !== false) $found = true; }

                    if ($found) {
    $radius = 2; // Single Source of Truth for context radius
    $startLine = max(0, $index - $radius);
    $endLine = min(count($lines) - 1, $index + $radius);

    $contextLines = [];
    $contextTextLines = [];
    for ($i = $startLine; $i <= $endLine; $i++) {
        $lineTxt = trim($lines[$i]);
        $contextLines[] = [
            'line' => $i + 1,
            'text' => $lineTxt,
            'is_target' => ($i === $index)
        ];
        $contextTextLines[] = $lineTxt;
    }

    $matches[] = [
        'index' => count($matches) + 1,
        'file' => $relPath,
        'line' => $index + 1,
        'content' => trim($lineContent),
        'context_text' => implode("\n", $contextTextLines),
        'context_lines' => $contextLines,
        'context' => [
            'prev2' => isset($lines[$index - 2]) ? trim($lines[$index - 2]) : null,
            'prev' => isset($lines[$index - 1]) ? trim($lines[$index - 1]) : null,
            'next' => isset($lines[$index + 1]) ? trim($lines[$index + 1]) : null,
            'next2' => isset($lines[$index + 2]) ? trim($lines[$index + 2]) : null
        ]
    ];
}}
            }
            $results_by_id[] = [
                'id' => $audit['id'],
                'pattern' => $pattern,
                'file_filter' => !empty($audit['file']) ? $audit['file'] : $fileFilter,
                'matches' => $matches
            ];
        }
        return ['status' => 'success', 'results' => $results_by_id];
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function ca_format_audit_report($results) {
    if (!is_array($results) || empty($results)) {
        return "~~~\n### SYSTEM AUDIT BATCH REPORT\n\nNo audit results.\n~~~";
    }

    $report = "~~~\n### SYSTEM AUDIT BATCH REPORT\n\n";

    foreach ($results as $audit) {
        $matches = $audit['matches'] ?? [];
        $auditFiles = array_values(array_unique(array_map(function($m) { return $m['file']; }, $matches)));

        $report .= "AUDIT ID: " . ($audit['id'] ?? "Untitled") . "\n";
        $report .= "PATTERN: " . ($audit['pattern'] ?? "") . "\n";
        $report .= "TARGET: " . ($audit['file_filter'] ?? "System-wide") . "\n";
        $report .= "FILE COUNT: " . count($auditFiles) . "\n";
        $report .= "FILES: " . implode(", ", $auditFiles) . "\n";
        $report .= "INSTANCES: " . count($matches) . "\n\n";

        if (!empty($matches)) {
            foreach ($matches as $m) {
                $report .= "  " . ($m['index'] ?? 1) . ". FILE: " . $m['file'] . " (Line " . $m['line'] . ")\n";
                $report .= "--- CONTEXT ---\n";
                $report .= (!empty($m['context_text']) ? $m['context_text'] : $m['content']) . "\n";
                $report .= "---------------\n\n";
            }
        }
        $report .= str_repeat("-", 40) . "\n\n";
    }

    $report .= "~~~";
    return trim($report);
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ca_run_audit') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $res = ca_execute_audit_payload(
        $_POST['payload'] ?? '',
        trim($_POST['file_filter'] ?? ''),
        ($_POST['exclude_apps'] ?? 'false') === 'true',
        ($_POST['exclude_disabled'] ?? 'false') === 'true',
        ($_POST['exclude_data'] ?? 'false') === 'true'
    );
    echo json_encode($res);
    exit;
}

// --- 2. SETTINGS UI ---
$plugin_settings_map['CodeAuditor'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Code Auditor</label>
        <div class="setting-desc">Scan the codebase for patterns to track refactor progress.</div>
        <div data-sui-setting="Show Dashboard Tool" data-sui-desc="Display the Auditor launcher on the Dashboard." data-sui-id="ca-show-tool" data-sui-onchange="caSaveConfig(true)"></div>
        <button onclick="caOpenStudio()" class="text-btn" style="width:100%; background:var(--ai-accent); color:var(--primary-text); border-radius:14px; padding:14px; font-weight:700; margin-top:10px; border:none; box-shadow: 0 4px 12px color-mix(in srgb, var(--ai-accent), transparent 80%);">
            Open Audit Studio
        </button>
    </div>
HTML;

if (($ca_config['show_tool'] ?? true) !== false) {
    $plugin_tools[] = [
        'name' => 'Auditor',
        'desc' => 'Scan codebase',
        'sui_icon' => 'search',
        'color' => 'var(--ai-accent-bg)',
        'icon_color' => 'var(--ai-accent)',
        'action' => "caOpenStudio()"
    ];
}

// --- 3. JS LOGIC ---
$plugin_js .= <<<'JS'
// --- CODE AUDITOR ENGINE ---

const caBridge = window.__CA_BRIDGE__ || { config: { expand: true, exclude_apps: false, exclude_disabled: false, exclude_data: false, scroll_delay: 450, show_tool: true } };
let caSettings = caBridge.config;

window.caCopyPath = function(path) {
    if (!path) return;
    const finalize = () => {
        const t = document.getElementById("toast");
        if(t) { 
            t.innerText = "Copied: " + path; 
            t.classList.add("show"); 
            setTimeout(() => t.classList.remove("show"), 2000); 
        }
        if (window.sui && window.sui.haptic) window.sui.haptic('light');
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(path).then(finalize).catch(() => {
            const ta = document.createElement("textarea");
            ta.value = path; ta.style.position = "fixed"; ta.style.left = "-9999px";
            document.body.appendChild(ta); ta.select();
            document.execCommand("copy");
            document.body.removeChild(ta);
            finalize();
        });
    } else {
        const ta = document.createElement("textarea");
        ta.value = path; ta.style.position = "fixed"; ta.style.left = "-9999px";
        document.body.appendChild(ta); ta.select();
        document.execCommand("copy");
        document.body.removeChild(ta);
        finalize();
    }
};

// Inject Styles once
(function() {
    const style = document.createElement("style");
    style.innerHTML = `
        @keyframes ca-pulse-glow {
            0% { box-shadow: 0 0 0 0 rgba(0, 122, 255, 0.5); transform: scale(1); }
            50% { box-shadow: 0 0 0 20px rgba(0, 122, 255, 0); transform: scale(1.02); }
            100% { box-shadow: 0 0 0 0 rgba(0, 122, 255, 0); transform: scale(1); }
        }
        .ca-highlight-pulse {
            animation: ca-pulse-glow 0.8s ease-out 2;
            z-index: 100;
            position: relative;
        }
        .ca-result-card { scroll-margin-top: 20px; }
        .ca-summary-card {
            background: var(--primary); color: var(--primary-text);
            padding: 22px; border-radius: 22px; margin-bottom: 24px;
            box-shadow: 0 12px 35px rgba(0, 122, 255, 0.25);
            display: flex; flex-direction: column; gap: 18px;
            position: relative; z-index: 10; transform: translateZ(0); 
        }
        .ca-audit-section {
            margin-bottom: 12px; background: var(--card-bg);
            border: 1px solid var(--border-color); border-radius: 16px;
            overflow: hidden; transition: border-color 0.2s;
        }
        .ca-audit-section[open] { border-color: var(--primary); box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .ca-audit-summary {
            padding: 16px 20px; cursor: pointer; list-style: none;
            display: flex; justify-content: space-between; align-items: center;
            font-weight: 700; font-size: 14px; color: var(--text-primary);
        }
        .ca-audit-summary::-webkit-details-marker { display: none; }
        .ca-audit-summary:active { background: var(--btn-bg); }
    `;
    document.head.appendChild(style);
})();

window.caOpenStudio = function() {
    const content = `
        <div id="ca-studio-root">
            <!-- AUDIT PREFERENCES -->
            <details style="margin-bottom:16px; border-radius:12px; border:1px solid var(--border-color); overflow:hidden; background:var(--bg-color);">
                <summary style="padding:12px 16px; cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">
                    Audit Preferences
                    <span data-sui-icon="chevron" data-sui-size="12"></span>
                </summary>
                <div style="padding:4px 16px 12px 16px; display:flex; flex-direction:column; gap:4px;">
                    <div data-sui-setting="Expand Matches" data-sui-desc="Auto-expand result sections after scanning." data-sui-id="ca-expand-default" data-sui-onchange="caSaveConfig()"></div>
                    <div data-sui-setting="Exclude Apps Folder" data-sui-desc="Skip scanning standalone apps in /apps directory." data-sui-id="ca-exclude-apps" data-sui-onchange="caSaveConfig()"></div>
                    <div data-sui-setting="Exclude Disabled Plugins" data-sui-desc="Skip auditing plugins that are turned off in settings." data-sui-id="ca-exclude-disabled" data-sui-onchange="caSaveConfig()"></div>
                    <div data-sui-setting="Exclude Data Folders" data-sui-desc="Skip persistent data folders (app/data and apps/*/data)." data-sui-id="ca-exclude-data" data-sui-onchange="caSaveConfig()"></div>
                    
                    <div class="setting-item vertical" style="padding:8px 0; border:none;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <label class="setting-label" style="font-size:13px; margin:0;">Scroll Delay</label>
                            <span id="ca-delay-val" style="font-size:12px; font-weight:700; color:var(--primary);">450ms</span>
                        </div>
                        <div class="setting-desc" style="font-size:10px; margin-bottom:8px;">Wait time before aligning expanded sections to top.</div>
                        <input type="range" id="ca-delay-slider" min="0" max="1000" step="50" oninput="document.getElementById('ca-delay-val').innerText = this.value + 'ms'" onchange="caSaveConfig()">
                    </div>
                </div>
            </details>

            <!-- QUICK SEARCH -->
            <div style="margin-bottom:16px;">
                ${window.suiAccordion('ca-quick-search-sec', 'Quick Search', `
                    <div style="padding:8px 0;">
                        <input type="text" id="ca-quick-input" placeholder="Enter pattern to find..." style="width:100%; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text); font-size:14px; outline:none;">
                    </div>
                `, false)}
            </div>

            <div class="setting-item vertical" style="padding:0; border:none; margin-bottom:20px;">
                <label class="setting-label">Audit Radar</label>
                <div class="setting-desc">Paste an Audit Protocol block to scan the entire codebase for patterns.</div>
                
                <div style="margin-top:10px; margin-bottom:10px;">
                    <input type="text" id="ca-file-filter" placeholder="Target File or Folder (e.g. app/plugins)" style="width:100%; padding:10px; border-radius:10px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text); font-size:13px; font-family:monospace; outline:none;">
                </div>

                <textarea id="ca-input" placeholder="#ACTION: audit&#10;#PATTERN:&#10;your code here...&#10;#END" style="
                    width:100%; height:120px; padding:12px; border-radius:12px; 
                    border:1px solid var(--border-color); font-family:monospace; font-size:12px; 
                    background:var(--input-bg); color:var(--input-text); outline:none; margin-top:10px; resize:none;
                "></textarea>

                <button onclick="caPerformAudit()" id="ca-btn-run" class="text-btn" style="
                    width:100%; background:var(--primary); color:var(--primary-text);
                    border-radius:12px; padding:14px; font-weight:700; margin-top:12px;
                    box-shadow: 0 4px 12px rgba(0,122,255,0.2);
                ">Scan System</button>
            </div>

            <div id="ca-results-area" style="display:none; margin-top:20px;">
                <div id="ca-summary" style="margin-bottom:12px;"></div>
                <div id="ca-list" style="display:flex; flex-direction:column; gap:12px; padding-bottom:120px;"></div>
            </div>
        </div>
    `;

    window.sui.openStudio({
        id: 'ca-studio',
        title: 'Code Auditor',
        content: content,
        onSetup: (container) => {
            caLoadConfig();
            const caInput = container.querySelector("#ca-input");
            if (caInput) {
                caInput.addEventListener("focus", function() {
                    this.select();
                });
            }
            window.suiHydrateIcons(container);
            window.suiHydrateSettings(container);
        }
    });
};

async function caLoadConfig() {
    // Priority 1: Bridge
    if (window.__CA_BRIDGE__) {
        caSettings = window.__CA_BRIDGE__.config;
    }

    // Priority 2: Refresh from Server
    try {
        const data = await window.sui.api("ca_get_config", {}, { toast: false });
        if (data) {
            caSettings = data.config;
        }
    } catch(e) {}

    // Hydrate UI
    const exp = document.getElementById("ca-expand-default");
const excApps = document.getElementById("ca-exclude-apps");
const excDis = document.getElementById("ca-exclude-disabled");
const excDat = document.getElementById("ca-exclude-data");
const dly = document.getElementById("ca-delay-slider");
const dlyVal = document.getElementById("ca-delay-val");
const tool = document.getElementById("ca-show-tool");

if (exp) exp.checked = caSettings.expand;
if (excApps) excApps.checked = (caSettings.exclude_apps === true);
if (excDis) excDis.checked = (caSettings.exclude_disabled === true);
if (excDat) excDat.checked = (caSettings.exclude_data === true);
if (dly) dly.value = caSettings.scroll_delay || 450;if (dlyVal) dlyVal.innerText = (caSettings.scroll_delay || 450) + "ms";
    if (tool) tool.checked = (caSettings.show_tool !== false);
}

window.addEventListener("load", () => {
    if (window.__CA_BRIDGE__) {
        const tool = document.getElementById("ca-show-tool");
        if (tool) tool.checked = (window.__CA_BRIDGE__.config.show_tool !== false);
    }
});

window.caSaveConfig = async function(forceReload = false) {
    const exp = document.getElementById("ca-expand-default");
    const excApps = document.getElementById("ca-exclude-apps");
    const excDis = document.getElementById("ca-exclude-disabled");
    const excDat = document.getElementById("ca-exclude-data");
    const tool = document.getElementById("ca-show-tool");
    const dly = document.getElementById("ca-delay-slider");

    if (exp) caSettings.expand = exp.checked;
    if (excApps) caSettings.exclude_apps = excApps.checked;
    if (excDis) caSettings.exclude_disabled = excDis.checked;
    if (excDat) caSettings.exclude_data = excDat.checked;
    if (tool) caSettings.show_tool = tool.checked;
    if (dly) caSettings.scroll_delay = parseInt(dly.value);
    
    await window.sui.api("ca_save_config", { settings: caSettings }, { toast: false });
    if (forceReload) location.reload();
};

window.caScrollToMatch = function(el, fileName) {
    const details = el.closest('details');
    if (!details) return;
    
    // Ensure the section is expanded
    details.open = true;
    
    // Find the first card with this file name in THIS section
    const target = details.querySelector(`[data-ca-file="${fileName}"]`);
    if (target) {
        // Change alignment to start (top)
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        
        // Delay animation until arrival (approx 500ms for smooth scroll)
        setTimeout(() => {
            target.classList.remove('ca-highlight-pulse');
            void target.offsetWidth; // Force reflow
            target.classList.add('ca-highlight-pulse');
            setTimeout(() => target.classList.remove('ca-highlight-pulse'), 1600);
            if (navigator.vibrate) navigator.vibrate(15);
        }, 500);
    }
};

window.caPerformAudit = async function() {
    let raw = document.getElementById("ca-input").value.trim();
    const quickInput = document.getElementById("ca-quick-input");
    const quickValue = quickInput ? quickInput.value.trim() : "";

    // Fallback: If main radar is empty, use the Quick Search value
    if (!raw && quickValue) {
        raw = `#PATCH_ID: Quick Search\n#PATTERN:\n${quickValue}\n#END`;
    }

    if (!raw) return;

    const btn = document.getElementById("ca-btn-run");
    const originalText = btn.innerText;
    btn.innerText = "Scanning System...";
    btn.disabled = true;

    try {
        const data = await window.sui.api("ca_run_audit", { 
            payload: raw, 
            file_filter: document.getElementById('ca-file-filter').value.trim(),
            exclude_apps: document.getElementById('ca-exclude-apps') ? document.getElementById('ca-exclude-apps').checked : false,
            exclude_disabled: document.getElementById('ca-exclude-disabled') ? document.getElementById('ca-exclude-disabled').checked : false,
            exclude_data: document.getElementById('ca-exclude-data') ? document.getElementById('ca-exclude-data').checked : false
        }, { toast: false });
        
        if (data.status === "success") {
            caRenderResults(data.results);
        } else {
            window.openConfirm("Audit Failed", data.message, null, false, "OK", null);
        }
    } catch(e) {
        window.openConfirm("Error", "Server communication error.", null, false, "OK", null);
    } finally {
        btn.innerText = originalText;
        btn.disabled = false;
    }
};

function caRenderResults(results) {
    const area = document.getElementById("ca-results-area");
    const summary = document.getElementById("ca-summary");
    const list = document.getElementById("ca-list");
    
    area.style.display = "block";
    list.innerHTML = "";
    window._lastAuditResults = results;

    let totalMatches = 0;
    const allUniqueFiles = new Set();
    results.forEach(r => {
        totalMatches += r.matches.length;
        r.matches.forEach(m => allUniqueFiles.add(m.file));
    });

    summary.innerHTML = `
        <div class="ca-summary-card">
            <div style="display:flex; flex-direction:column; gap:4px;">
                <div style="font-size:10px; font-weight:900; opacity:0.7; text-transform:uppercase; letter-spacing:1.5px;">System Audit Results</div>
                <div style="font-size:26px; font-weight:900; letter-spacing:-0.5px; line-height:1;">${totalMatches} Matches</div>
                <div style="font-size:14px; font-weight:500; opacity:0.9;">In ${allUniqueFiles.size} files across ${results.length} patterns</div>
            </div>
            <div style="display:flex; flex-direction:column; gap:8px; width:100%;">
                <div style="display:flex; gap:10px; width:100%;">
                    <button onclick="caCopyReport()" style="flex:1; background:var(--primary-text); color:var(--primary); border:none; border-radius:12px; padding:14px; font-size:13px; font-weight:800; cursor:pointer; box-shadow:0 4px 10px rgba(0,0,0,0.1);">
                        Copy Report
                    </button>
                    <button onclick="caSaveToProject()" style="flex:1; background:var(--primary-text); color:var(--primary); border:none; border-radius:12px; padding:14px; font-size:13px; font-weight:800; cursor:pointer; box-shadow:0 4px 10px rgba(0,0,0,0.1);">
                        Save to Project
                    </button>
                </div>
                <div style="display:flex; gap:10px; width:100%;">
                    <button onclick="caCopyFilesContext()" style="flex:1; background:var(--card-bg); color:var(--primary); border:1px solid var(--border-color); border-radius:12px; padding:14px; font-size:13px; font-weight:800; cursor:pointer; box-shadow:0 4px 10px rgba(0,0,0,0.05);">
                        Copy Context
                    </button>
                    <button onclick="caDownloadFilesContext()" style="flex:1; background:var(--card-bg); color:var(--primary); border:1px solid var(--border-color); border-radius:12px; padding:14px; font-size:13px; font-weight:800; cursor:pointer; box-shadow:0 4px 10px rgba(0,0,0,0.05);">
                        Download Context
                    </button>
                </div>
            </div>
        </div>
    `;

    const shouldExpand = document.getElementById('ca-expand-default').checked;

    results.forEach((audit, aIdx) => {
        const auditFiles = [...new Set(audit.matches.map(m => m.file))];
        const details = document.createElement("details");
        details.className = "ca-audit-section";
        details.open = shouldExpand && audit.matches.length > 0;
        
        const countColor = audit.matches.length > 0 ? "var(--primary)" : "var(--text-secondary)";
        const summaryEl = document.createElement("summary");
        summaryEl.className = "ca-audit-summary";
        summaryEl.innerHTML = `
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:10px; height:10px; border-radius:50%; background:${countColor}; box-shadow: 0 0 8px ${countColor};"></div>
<div>
    <div style="font-size:14px; color:var(--text-primary);">${escapeHtml(audit.id)}</div>
    <div style="font-size:10px; font-weight:500; color:var(--text-secondary); opacity:0.7; margin-top:1px;">
        Target: <span style="color:var(--primary); font-weight:700;">${escapeHtml(audit.file_filter || 'System-wide')}</span> | 
        Pattern: ${escapeHtml(audit.pattern.substring(0,20))}${audit.pattern.length > 20 ? '...' : ''}
    </div>
</div></div>
            <div style="font-family:monospace; font-size:11px; background:var(--btn-bg); padding:4px 10px; border-radius:8px; color:var(--text-primary); border:1px solid var(--border-color);">
                ${audit.matches.length}
            </div>
        `;

        const content = document.createElement("div");
        content.style.cssText = "padding:0 16px 16px 16px; display:flex; flex-direction:column; gap:10px;";
        
        // File Manifest for this patch
        if (auditFiles.length > 0) {
            const manifest = document.createElement("div");
            manifest.style.cssText = "margin-top:10px; padding:12px; background:var(--btn-bg); border-radius:12px; font-size:11px; border:1px solid var(--border-color);";
            manifest.innerHTML = `
                <div style="font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Files in this Audit (${auditFiles.length}):</div>
                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                    ${auditFiles.map(f => `<span onclick="caCopyPath('${f}'); caScrollToMatch(this, '${f}')" style="cursor:pointer; background:var(--card-bg); padding:3px 8px; border-radius:6px; border:1px solid var(--border-color); color:var(--text-primary); font-family:monospace; transition:transform 0.1s;" onmousedown="this.style.transform='scale(0.95)'" onmouseup="this.style.transform='scale(1)'">${f}</span>`).join('')}
                </div>
            `;
            content.appendChild(manifest);
        }

        if (audit.matches.length === 0) {
            content.innerHTML = `<div style="text-align:center; padding:20px; color:var(--text-secondary); opacity:0.6; font-size:12px;">No matches for pattern.</div>`;
        } else {
            audit.matches.forEach((m, mIdx) => {
    const card = document.createElement("div");
    card.className = "ca-result-card";
    card.setAttribute("data-ca-file", m.file);
    const escapedPattern = escapeHtml(audit.pattern).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(`(${escapedPattern})`, 'gi');

    let linesHtml = "";
    if (m.context_lines && Array.isArray(m.context_lines)) {
        linesHtml = m.context_lines.map(cl => {
            const safe = escapeHtml(cl.text);
            if (cl.is_target) {
                const highlighted = safe.replace(regex, '<mark style="background:var(--primary); color:var(--primary-text); border-radius:2px; padding:0 2px;">$1</mark>');
                return `<div style="white-space:pre; color:var(--text-primary); font-weight:600;">${highlighted}</div>`;
            } else {
                return `<div style="opacity:0.35; white-space:pre;">${safe}</div>`;
            }
        }).join('');
    } else {
        const safeContent = escapeHtml(m.content);
        const highlighted = safeContent.replace(regex, '<mark style="background:var(--primary); color:var(--primary-text); border-radius:2px; padding:0 2px;">$1</mark>');
        linesHtml = `<div style="white-space:pre; color:var(--text-primary); font-weight:600;">${highlighted}</div>`;
    }

    card.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; border-bottom:1px solid var(--border-color); padding-bottom:6px; margin-top:10px;">
            <div onclick="caCopyPath('${m.file}')" style="cursor:pointer; font-weight:700; font-size:11px; color:var(--text-primary); word-break:break-all; flex:1;">
                <span style="opacity:0.5; margin-right:4px;">${m.index}.</span> ${m.file}
            </div>
            <div style="font-family:monospace; font-size:9px; background:var(--btn-bg); padding:2px 6px; border-radius:4px; color:var(--text-secondary); margin-left:10px;">
                Line ${m.line}
            </div>
        </div>
        <div style="background:var(--input-bg); color:var(--input-text); padding:10px; border-radius:10px; font-family:monospace; font-size:10px; overflow-x:auto; line-height:1.4; border:1px solid var(--border-color);">
            ${linesHtml}
        </div>
    `;
    content.appendChild(card);
});}
        
        details.appendChild(summaryEl);
        details.appendChild(content);

        // Auto-scroll to top when expanded
        details.addEventListener('toggle', () => {
            if (details.open) {
                // Configurable delay allows ScrollReveal animations to stabilize
                setTimeout(() => {
                    details.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, caSettings.scroll_delay || 450);
            }
        });

        list.appendChild(details);
        if (window.srWatch) window.srWatch(details);
    });

    // Ensure layout is calculated before scrolling to provide the "pull up" effect consistently
    requestAnimationFrame(() => {
        setTimeout(() => {
            const summaryCard = document.querySelector('.ca-summary-card');
            if (summaryCard) {
                summaryCard.scrollIntoView({ behavior: "smooth", block: "start" });
            } else {
                area.scrollIntoView({ behavior: "smooth", block: "start" });
            }
        }, 50);
    });
}

window.caSaveToProject = function() {
    if (!window._lastAuditResults) return;
    if (typeof window.openPicker !== "function") {
        window.openConfirm("Plugin Required", "SharedUI required.", null, false, "OK", null);
        return;
    }

    // Fetch projects from the Planner plugin's global state if available
    // or run a quick fetch
    window.sui.api("planner_get_projects", {}, { toast: false })
        .then(data => {
            if (data) {
                const options = data.projects
                    .filter(p => !p.meta.archived)
                    .map(p => ({ label: "📂 " + p.meta.title, value: p.filename }));
                window.openPicker("Link Audit to Project", options, null, async (filename) => {
                    // Standardize Schema: Ensure every match has an explicit 'done' state
                    const standardizedResults = window._lastAuditResults.map(section => ({
                        ...section,
                        matches: section.matches.map(m => ({
                            ...m,
                            done: false
                        }))
                    }));

                    const d = await window.sui.api("ca_save_to_project", { 
                        filename: filename, 
                        results: standardizedResults 
                    }, { toast: false });
                    
                    if (d) {
                        // 1. Show Feedback
                        const t = document.getElementById("toast");
                        if(t) { t.innerText = "Linked! Opening Project..."; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }

                        // 2. Find the project object in global memory
                        if (window.ppProjects) {
                            const project = window.ppProjects.find(p => p.filename === filename);
                            if (project) {
                                // 3. Close Settings
                                const settings = document.getElementById("settings-overlay");
                                if (settings) settings.classList.remove("visible");

                                // 4. Navigate to Planner Page
                                const view = document.getElementById("project-planner-view");
                                const viewport = document.querySelector(".horizontal-viewport");
                                if (view && viewport) {
                                    const page = view.closest(".page-view");
                                    viewport.scrollTo({ left: page.offsetLeft, behavior: "smooth" });

                                    // 5. Open Preview (Delay to allow scroll and overlay fade)
                                    setTimeout(() => {
                                        if (window.ppOpenPreview) window.ppOpenPreview(project);
                                    }, 600);
                                }
                            }
                        }
                    }
                });
            }
        });
};

window.caCopyReport = function() {
    const results = window._lastAuditResults || window._lastAuditMatches;
    if (!results || results.length === 0) {
        console.error("CodeAuditor: No results found to copy.");
        return;
    }

    let report = "### SYSTEM AUDIT BATCH REPORT\n\n";
    results.forEach(audit => {
        const matches = audit.matches || [];
        report += `AUDIT ID: ${audit.id}\n`;
        report += `PATTERN: ${audit.pattern}\n`;
        report += `INSTANCES: ${matches.length}\n`;
        
        if (matches.length > 0) {
            matches.forEach((m, idx) => {
                report += `  ${idx + 1}. FILE: ${m.file} (Line ${m.line})\n`;
                report += `     CONTEXT: ${m.content.trim()}\n`;
            });
        }
        report += "\n" + "-".repeat(40) + "\n\n";
    });

    const finalizeCopy = () => {
        const t = document.getElementById("toast");
        if(t) { t.innerText = "Full Report Copied"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(report).then(finalizeCopy).catch(err => {
            console.warn("Clipboard API failed, using fallback.");
            caCopyFallback(report, finalizeCopy);
        });
    } else {
        caCopyFallback(report, finalizeCopy);
    }
};

function caCopyFallback(text, callback) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-9999px";
    textArea.style.top = "0";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        document.execCommand('copy');
        if (callback) callback();
    } catch (err) {
        console.error('Fallback copy failed', err);
        window.openConfirm("Copy Failed", "Please check console.", null, false, "OK", null);
    }
    document.body.removeChild(textArea);
}

window.caCopyReport = function() {
    const results = window._lastAuditResults;
    if (!results || !Array.isArray(results)) return;

    let report = "```\n### SYSTEM AUDIT BATCH REPORT\n\n";
    results.forEach(audit => {
        const auditFiles = [...new Set(audit.matches.map(m => m.file))];
        report += "AUDIT ID: " + (audit.id || "Untitled") + "\n";
        report += "PATTERN: " + (audit.pattern || "") + "\n";
        report += "TARGET: " + (audit.file_filter || "System-wide") + "\n";
        report += "FILE COUNT: " + auditFiles.length + "\n";
        report += "FILES: " + auditFiles.join(", ") + "\n";
        report += "INSTANCES: " + (audit.matches ? audit.matches.length : 0) + "\n";
        
        if (audit.matches && audit.matches.length > 0) {
            audit.matches.forEach((m, idx) => {
                report += `  ${m.index}. FILE: ${m.file} (Line ${m.line})\n`;
                report += "--- CONTEXT ---\n";
                report += (m.context_text || m.content) + "\n";
                report += "---------------\n\n";
            });
        }
        report += "\n" + "-".repeat(40) + "\n\n";
    });
    report += "```";

    // Robust Fallback Copy Method
    const ta = document.createElement("textarea");
    ta.value = report;
    ta.style.position = "fixed"; ta.style.left = "-9999px"; 
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand("copy");
        const t = document.getElementById("toast");
        if(t) { t.innerText = "Full Report Copied"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
    } catch(err) {
        console.error("Copy failed", err);
        window.openConfirm("Access Denied", "Clipboard access denied by browser.", null, false, "OK", null);
    }
    document.body.removeChild(ta);
};

const legacy_caCopyReport = function() {
    report += `Pattern: ${document.getElementById('ca-input').value.split('\n#PATTERN:')[1]?.split('\n#END')[0]?.trim() || "Unknown"}\n`;
    report += `Total Instances: ${matches.length}\n\n`;

    matches.forEach((m, idx) => {
        report += `${idx + 1}. FILE: ${m.file} (Line ${m.line})\n`;
        report += `   CONTEXT: ${m.content.trim()}\n\n`;
    });

    navigator.clipboard.writeText(report).then(() => {
        const t = document.getElementById("toast");
        if(t) { t.innerText = "Audit Report Copied"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
    });
};

function caGetUniqueInvolvedFiles() {
    const files = new Set();
    if (window._lastAuditResults && Array.isArray(window._lastAuditResults)) {
        window._lastAuditResults.forEach(r => {
            if (r.matches && Array.isArray(r.matches)) {
                r.matches.forEach(m => {
                    if (m.file) files.add(m.file);
                });
            }
        });
    }
    return Array.from(files);
}

window.caCopyFilesContext = async function() {
    const files = caGetUniqueInvolvedFiles();
    if (files.length === 0) {
        const t = document.getElementById("toast");
        if(t) { t.innerText = "No files found to copy"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
        return;
    }
    
    const showToast = (msg) => {
        const t = document.getElementById("toast");
        if (t) {
            t.innerText = msg;
            t.classList.add("show");
            setTimeout(() => t.classList.remove("show"), 2000);
        }
    };
    showToast("Compiling context...");

    try {
        const res = await window.sui.api('ce_compile_custom_files', { files: JSON.stringify(files) }, { toast: false });
        if (res && res.status === 'success') {
            caCopyFallback(res.context, () => {
                showToast(`Copied context for ${files.length} files`);
            });
        } else {
            window.openConfirm("Copy Failed", "Failed to compile custom files on server.", null, false, "OK", null);
        }
    } catch(e) {
        console.error("Compilation failed", e);
        window.openConfirm("Error", "Failed to communicate with server.", null, false, "OK", null);
    }
};

window.caDownloadFilesContext = function() {
    const files = caGetUniqueInvolvedFiles();
    if (files.length === 0) {
        const t = document.getElementById("toast");
        if(t) { t.innerText = "No files to download"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
        return;
    }
    
    const params = {
        plugin_action: 'ce_download_custom',
        files: JSON.stringify(files),
        t: Date.now()
    };

    const queryString = Object.keys(params)
        .map(key => encodeURIComponent(key) + '=' + encodeURIComponent(params[key]))
        .join('&');
    
    window.location.href = 'index.php?' + queryString;
};

window.caMarkMatchDone = async function(linkStr) {
    // Expected format: Project.md | AUDIT_ID | 1
    const parts = linkStr.split('|').map(p => p.trim());
    if (parts.length < 3) return;

    const [project, auditId, matchIdx] = parts;
    console.log(`[Auditor] Handshake: Marking ${project} -> ${auditId} [#${matchIdx}] as DONE`);

    try {
        const data = await window.sui.api('ca_mark_item_done', { 
            project: project, 
            audit_id: auditId, 
            match_index: matchIdx 
        }, { toast: false });
        if (data.status === 'success') {
            // If Project Planner is open, refresh the checklist UI
            if (typeof renderChecklist === 'function') {
                // We'd need to re-fetch the data or update the local auditData object.
                // For now, the backend is updated, and the user will see it on next open.
            }
        }
    } catch(e) { console.error("[Auditor] Handshake Failed", e); }
};

window.caGetPattern = async function(linkStr) {
    const parts = linkStr.split('|').map(p => p.trim());
    if (parts.length < 3) return null;
    const [project, auditId, matchIdx] = parts;

    try {
        const data = await window.sui.api('ca_get_pattern', { 
            project: project, 
            audit_id: auditId, 
            match_index: matchIdx 
        }, { toast: false });
        return data || null;
    } catch(e) { return null; }
};

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
JS;