<?php
// ==============================================================================
// PLUGIN: Context Exporter
// DESCRIPTION: Tiered AI Context Generator.
// Provides efficient context downloads (Foundation, Project, Full).
// ==============================================================================

// --- 1. BACKEND HANDLER ---

// HELPER: Layout Config Loader
function ce_get_layout_config() {
    $file = CJOS_PATH_DATA . '/context-layout-config.json';
    if (file_exists($file)) return json_decode(file_get_contents($file), true);
    return ['categories' => ['foundation', 'project', 'manual']];
}

// HELPER: Foundation List Loader
function ce_get_foundation($root) {
    $conf = CJOS_PATH_DATA . '/foundation-config.json';
    $list = file_exists($conf) ? json_decode(file_get_contents($conf), true) : [];
    if (!is_array($list)) $list = [];
    
    $relData = str_replace($root . '/', '', CJOS_PATH_DATA);
    
    // Scan knowledge folder for all files
    $knowledge_dir = CJOS_PATH_DATA . '/knowledge';
    $knowledge_files = [];
    if (is_dir($knowledge_dir)) {
        $kIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($knowledge_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($kIt as $file) {
            if ($file->isFile()) {
                $knowledge_files[] = $relData . '/knowledge/' . str_replace('\\', '/', substr($file->getRealPath(), strlen($knowledge_dir) + 1));
            }
        }
    }

    // Prune missing knowledge files
    $list = array_filter($list, function($f) use ($root, $relData) {
        if (strpos($f, $relData . '/knowledge/') === 0) {
            return file_exists($root . '/' . $f);
        }
        return true;
    });
    $list = array_values($list);

    // Add missing knowledge files to list
    foreach ($knowledge_files as $kf) {
        if (!in_array($kf, $list)) {
            $list[] = $kf;
        }
    }

    // Ensure core files are in the list as fallback
    $inst = $relData . '/knowledge/system_instructions.md';
    $struc = $relData . '/knowledge/system_structure.md';
    $manual = $relData . '/knowledge/patcher_manual.md';

    if (!in_array($inst, $list)) array_unshift($list, $inst);
    if (!in_array($struc, $list)) array_splice($list, 1, 0, $struc);
    if (!in_array($manual, $list)) $list[] = $manual;

    return $list;
}

// HELPER: Automated System & App Dependency Map Generator
if (!function_exists('ce_generate_dependency_maps')) {
    function ce_generate_dependency_maps($root, $force = false) {
        $sysPath = CJOS_PATH_DATA . '/knowledge/sys_map.json';
        $appsPath = CJOS_PATH_DATA . '/knowledge/sys_map_apps-private.json';

        // Fast Cache Guard: Skip regeneration if maps already exist and force flag is false
        if (!$force && file_exists($sysPath) && file_exists($appsPath)) {
            return ['sys_count' => 0, 'apps_count' => 0];
        }

        $sysMap = [];
        $appsMap = [];

        $header = [
            "DESCRIPTION" => "This JSON represents the architectural dependency map of the system.",
            "HOW_TO_READ" => "KEY = File you want to modify. VALUE = Array of files that file depends on (providers).",
            "JIT_MANDATE" => "Before patching, lookup the target file in this map. If a dependency is missing from your context, you MUST request it.",
            "EXPORT_PROTOCOL" => "To retrieve a file, use #ACTION: export in a Protocol V8 block. No #FIND or #REPLACE needed.",
            "EXPORT_EXAMPLE" => "```text\n#PATCH_ID: [UNIQUE_ID]\n#FILE: [PATH_FROM_MAP]\n#ACTION: export\n#END\n```",
            "NO_HALLUCINATION" => "Never guess variable names or logic of missing files. Use the Export Protocol to see the truth."
        ];

        $sysMap["__AI_PROTOCOL__"] = $header;
        $appsMap["__AI_PROTOCOL__"] = $header;

        $dirExcludes = ['recordings', 'transcriptions', '.git', 'vendor', 'node_modules', 'backups', 'batch_backups', 'staged_patches', 'temp', '.apkstudio', 'data/projects'];

        if (is_dir($root)) {
            $dirIt = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
            $filterIt = new RecursiveCallbackFilterIterator($dirIt, function ($current, $key, $iterator) use ($dirExcludes, $root) {
                $rel = str_replace('\\', '/', substr($current->getRealPath(), strlen($root) + 1));
                if ($current->isDir()) {
                    $dirName = $current->getFilename();
                    if (in_array($dirName, $dirExcludes)) return false;
                    foreach ($dirExcludes as $ex) {
                        if (strpos($rel, $ex) === 0) return false;
                    }
                }
                return true;
            });

            $it = new RecursiveIteratorIterator($filterIt);
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                if ($file->getSize() > 200000) continue; // Skip files > 200 KB

                $filePath = $file->getRealPath();
                $relPath = str_replace('\\', '/', substr($filePath, strlen($root) + 1));

                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                if (!in_array($ext, ['php', 'js', 'java'])) continue; // Only scan executable code files

                if (ce_is_binary_file($filePath)) continue;

                $content = @file_get_contents($filePath);
                if (empty($content)) continue;

                $deps = [];

                if (preg_match_all('/(?:require_once|include_once|require|include)\s+[\'"]?([^\'";]+)[\'"]?/', $content, $matches)) {
                    foreach ($matches[1] as $match) {
                        $resolved = ce_resolve_path(basename($match), $root);
                        if ($resolved && $resolved !== $relPath && !in_array($resolved, $deps)) {
                            $deps[] = $resolved;
                        }
                    }
                }

                if (preg_match_all('/[\'"]([a-zA-Z0-9_\-\/]+\.(?:json|db))[\'"]/', $content, $matches)) {
                    foreach ($matches[1] as $match) {
                        $resolved = ce_resolve_path(basename($match), $root);
                        if ($resolved && $resolved !== $relPath && !in_array($resolved, $deps)) {
                            $deps[] = $resolved;
                        }
                    }
                }

                if (preg_match_all('/[\'"]([a-zA-Z0-9_]+\.php)[\'"]/', $content, $matches)) {
                    foreach ($matches[1] as $match) {
                        $resolved = ce_resolve_path($match, $root);
                        if ($resolved && $resolved !== $relPath && !in_array($resolved, $deps)) {
                            $deps[] = $resolved;
                        }
                    }
                }

                if (!empty($deps)) {
                    sort($deps);
                    if (strpos($relPath, 'apps/') === 0) {
                        $appsMap[$relPath] = $deps;
                    } else {
                        $sysMap[$relPath] = $deps;
                    }
                }
            }
        }

        if (is_dir(dirname($sysPath))) {
            file_put_contents($sysPath, json_encode($sysMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            file_put_contents($appsPath, json_encode($appsMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return ['sys_count' => count($sysMap) - 1, 'apps_count' => count($appsMap) - 1];
    }
}

// HELPER: Pre-flight Structure Scan
function ce_update_structure_md($root) {
    ce_generate_dependency_maps($root);
    $relData = str_replace($root . '/', '', CJOS_PATH_DATA);
    $strucFile = $root . '/' . $relData . '/knowledge/system_structure.md';
    
    $tree = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    
    $relApp = str_replace($root . '/', '', CJOS_PATH_APP);
    $treeExcludes = ['recordings', 'transcriptions', '.git', 'vendor', 'node_modules', 'backups', $relApp . '/backups'];

    foreach ($it as $file) {
        $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));
        
        // Exclude paths dynamically ignored by .contextignore or .noexport files
        if (ce_is_ignored_by_file($rel, $root)) continue;

        $isInsideExcluded = false;
        foreach ($treeExcludes as $ex) {
            if (strpos($rel, $ex) === 0) { $isInsideExcluded = true; break; }
        }
        if ($isInsideExcluded) continue;

        $parts = explode('/', $rel);
        $curr = &$tree;
        foreach ($parts as $part) {
            if (!isset($curr[$part])) $curr[$part] = [];
            $curr = &$curr[$part];
        }
        if (!$file->isDir()) {
            $curr['__path'] = $rel;
            $curr['__size'] = $file->getSize();
        }
    }

    $renderNode = function($node, $indent = "") use (&$renderNode) {
        $out = ""; $keys = array_filter(array_keys($node), function($k) { return $k !== '__path' && $k !== '__size'; }); sort($keys);
        foreach ($keys as $i => $key) {
            $isLast = ($i === count($keys) - 1); $connector = $isLast ? "└── " : "├── "; $childIndent = $isLast ? "    " : "│   ";
            if (isset($node[$key]['__path'])) {
                $size = isset($node[$key]['__size']) ? " [" . round($node[$key]['__size'] / 1024, 1) . " KB]" : "";
                $out .= $indent . $connector . $key . $size . "\n";
            } else {
                $out .= $indent . $connector . $key . "/\n" . $renderNode($node[$key], $indent . $childIndent);
            }
        }
        return $out;
    };

    $output = "################################################################################\n";
    $output .= "### SYSTEM STRUCTURE (Full Map)\n";
    $output .= "### GENERATED: " . date('Y-m-d H:i:s') . "\n";
    $output .= "################################################################################\n\n";
    $output .= "```txt\n" . $renderNode($tree) . "```\n";

    file_put_contents($strucFile, $output);
}

// HELPER: Content-based binary file detector (Null-byte inspection)
if (!function_exists('ce_is_binary_file')) {
    function ce_is_binary_file($filePath) {
        if (!$filePath) return false;
        
        // 1. Extension Deny-List Fast Path
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $disallowedExts = [
            'png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'bmp',
            'mp3', 'ogg', 'wav', 'flac', 'aac', 'm4a',
            'mp4', 'mkv', 'avi', 'mov', 'webm',
            'zip', 'tar', 'gz', 'bz2', '7z', 'rar',
            'db', 'db-shm', 'db-wal', 'sqlite', 'sqlite3',
            'exe', 'dll', 'so', 'dylib', 'a', 'o', 'obj', 'bin', 'apk', 'jar', 'class',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'ttf', 'otf', 'woff', 'woff2', 'eot', 'dat', 'keystore', 'jks', 'wasm'
        ];
        if (in_array($ext, $disallowedExts)) return true;

        // 2. Content Inspection (Null Byte Detection)
        if (file_exists($filePath) && is_file($filePath) && filesize($filePath) > 0) {
            $fp = @fopen($filePath, 'rb');
            if ($fp) {
                $block = fread($fp, 1024);
                fclose($fp);
                if (strpos($block, "\x00") !== false) {
                    return true; // Contains binary null bytes
                }
            }
        }
        return false;
    }
}

// HELPER: Folder-level auto-exclusion checker (.contextignore or .noexport)
function ce_is_ignored_by_file($path, $root) {
    static $cache = [];
    $path = str_replace('\\', '/', $path);
    $dir = is_dir($root . '/' . $path) ? $path : dirname($path);
    $dir = str_replace('\\', '/', $dir);
    if ($dir === '.' || $dir === '/' || $dir === '') return false;
    
    if (isset($cache[$dir])) {
        return $cache[$dir];
    }
    
    $baseName = basename($path);
    if (strpos($baseName, 'termux-main-') === 0 || strpos($baseName, 'Packages') === 0) {
        return $cache[$dir] = true;
    }

    // Check if current directory contains any ignore-file markers
    if (file_exists($root . '/' . $dir . '/.contextignore') || file_exists($root . '/' . $dir . '/.noexport')) {
        return $cache[$dir] = true;
    }
    
    // Check parent directory recursively
    $parent = dirname($dir);
    $parent = str_replace('\\', '/', $parent);
    if ($parent !== $dir) {
        if (ce_is_ignored_by_file($parent, $root)) {
            return $cache[$dir] = true;
        }
    }
    
    return $cache[$dir] = false;
}

// HELPER: Exclusions Loader
function ce_get_exclusions($type = 'foundation') {
    $filename = ($type === 'foundation') ? 'foundation-exclusions.json' : 'extra-exclusions-private.json';
    // Migration: If foundation-exclusions doesn't exist, try to load from old legacy files
    $file = CJOS_PATH_DATA . '/' . $filename;
    $legacy = CJOS_PATH_DATA . '/foundation-exclusions-private.json';
    $legacy2 = CJOS_PATH_DATA . '/context-exclusions-private.json';
    
    if (!file_exists($file) && $type === 'foundation') {
        if (file_exists($legacy)) return json_decode(file_get_contents($legacy), true) ?: [];
        if (file_exists($legacy2)) return json_decode(file_get_contents($legacy2), true) ?: [];
    }

    if (file_exists($file)) return json_decode(file_get_contents($file), true) ?: [];
    return [];
}

// HELPER: Global File Resolver
function ce_resolve_path($filename, $root) {
    static $resolvedCache = [];
    static $dirIndex = null;
    
    if (!$filename) return null;
    $filename = trim($filename);
    
    if (isset($resolvedCache[$filename])) {
        return $resolvedCache[$filename];
    }
    
    // 1. Direct match or common prefixes
    $relPlugins = str_replace($root . '/', '', CJOS_PATH_PLUGINS);
    $relApp = str_replace($root . '/', '', CJOS_PATH_APP);
    $relData = str_replace($root . '/', '', CJOS_PATH_DATA);
    
    $candidates = [
        $filename, 
        $relPlugins . '/' . $filename, 
        $relPlugins . '/' . $filename . '.php', 
        $relApp . '/css/' . $filename, 
        $relData . '/' . $filename
    ];
    foreach($candidates as $c) {
        if(file_exists($root.'/'.$c) && !is_dir($root.'/'.$c)) {
            return $resolvedCache[$filename] = $c;
        }
    }

    // 2. Build flat file index once per request if needed
    if ($dirIndex === null) {
        $dirIndex = [];
        
        // Index app/
        if (is_dir($root.'/app')) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $fName = $file->getFilename();
                    $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));
                    $dirIndex[$fName] = $rel;
                    
                    // Also index with .php extension stripped for matching flexibility
                    if (pathinfo($fName, PATHINFO_EXTENSION) === 'php') {
                        $baseName = pathinfo($fName, PATHINFO_FILENAME);
                        if (!isset($dirIndex[$baseName])) {
                            $dirIndex[$baseName] = $rel;
                        }
                    }
                }
            }
        }
        
        // Index apps/
        if (is_dir($root.'/apps')) {
            $itApps = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/apps', RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($itApps as $file) {
                if ($file->isFile()) {
                    $fName = $file->getFilename();
                    $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));
                    $dirIndex[$fName] = $rel;
                }
            }
        }
    }

    // Check flat index
    if (isset($dirIndex[$filename])) {
        return $resolvedCache[$filename] = $dirIndex[$filename];
    }
    
    // Fallback: check index with .php appended
    $withPhp = $filename . '.php';
    if (isset($dirIndex[$withPhp])) {
        return $resolvedCache[$filename] = $dirIndex[$withPhp];
    }

    return $resolvedCache[$filename] = null;
}

// ACTION: TOGGLE EXCLUSION
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_toggle_exclusion') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $type = $_POST['type'] ?? 'foundation';
    $pathInput = $_POST['path'];
    $paths = json_decode($pathInput, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($paths)) {
        $paths = [$pathInput];
    }
    $enabled = $_POST['enabled'] === 'true';
    
    $filename = ($type === 'foundation') ? 'foundation-exclusions.json' : 'extra-exclusions-private.json';
    $exFile = CJOS_PATH_DATA . '/' . $filename;
    $exclusions = ce_get_exclusions($type);
    
    foreach ($paths as $path) {
        if ($enabled) {
            $exclusions = array_values(array_filter($exclusions, function($e) use ($path) { return $e !== $path; }));
        } else {
            if (!in_array($path, $exclusions)) $exclusions[] = $path;
        }
    }
    
    file_put_contents($exFile, json_encode($exclusions, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success']);
    exit;
}

// ACTION: RESTORE EXCLUSIONS (Clean Overwrite)
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_restore_exclusions') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    if (isset($_POST['foundation_paths'])) {
        file_put_contents(CJOS_PATH_DATA . '/foundation-exclusions.json', json_encode(json_decode($_POST['foundation_paths'], true) ?: [], JSON_PRETTY_PRINT));
    }
    if (isset($_POST['extra_paths'])) {
        file_put_contents(CJOS_PATH_DATA . '/extra-exclusions-private.json', json_encode(json_decode($_POST['extra_paths'], true) ?: [], JSON_PRETTY_PRINT));
    }
    
    echo json_encode(['status' => 'success']);
    exit;
}

// ACTION: CONTEXT GROUPS
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_get_groups') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $file = CJOS_PATH_DATA . '/context-groups-private.json';
    $groups = file_exists($file) ? json_decode(file_get_contents($file), true) :[];
    
    $enrichedGroups =[];
    $root = CJOS_PATH_ROOT;
    foreach ($groups as $name => $files) {
        $enrichedFiles =[];
        foreach ($files as $f) {
            $full = $root . '/' . $f;
            $size = file_exists($full) ? round(filesize($full)/1024, 1) . ' KB' : '0 KB';
            $enrichedFiles[] = ['path' => $f, 'size' => $size];
        }
        $enrichedGroups[$name] = $enrichedFiles;
    }
    
    echo json_encode(['status' => 'success', 'groups' => $enrichedGroups]);
    exit;
}if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_save_group') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $file = CJOS_PATH_DATA . '/context-groups-private.json';
    $groups = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $name = $_POST['name'] ?? 'Untitled Group';
    $files = json_decode($_POST['files'], true) ?: [];
    $groups[$name] = $files;
    file_put_contents($file, json_encode($groups, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success']);
    exit;
}
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_rename_group') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $file = CJOS_PATH_DATA . '/context-groups-private.json';
    $groups = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $old = $_POST['old_name'];
    $new = $_POST['new_name'];
    if (isset($groups[$old])) {
        $groups[$new] = $groups[$old];
        unset($groups[$old]);
        file_put_contents($file, json_encode($groups, JSON_PRETTY_PRINT));
    }
    echo json_encode(['status' => 'success']);
    exit;
}
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_delete_group') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $file = CJOS_PATH_DATA . '/context-groups-private.json';
    $groups = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    unset($groups[$_POST['name']]);
    file_put_contents($file, json_encode($groups, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success']);
    exit;
}

// HELPER: Database Snapshot Logic
function ce_perform_snapshot_save($db) {
    $manual = [];
    $manualFile = CJOS_PATH_DATA . '/context-manual-extras-private.json';
    if (file_exists($manualFile)) $manual = json_decode(file_get_contents($manualFile), true) ?: [];
    
    $fExcl = ce_get_exclusions('foundation');
    $eExcl = ce_get_exclusions('extra');
    
    $data = json_encode([
        'manual' => $manual,
        'foundation_exclusions' => $fExcl,
        'extra_exclusions' => $eExcl
    ]);

    $db->exec("CREATE TABLE IF NOT EXISTS context_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, data TEXT)");
    
    // Deduplication: Don't save if the last entry is identical and within 10 seconds
    $last = $db->query("SELECT data, timestamp FROM context_snapshots ORDER BY timestamp DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $lastTime = strtotime($last['timestamp'] . ' UTC');
        if ($last['data'] === $data && (time() - $lastTime) < 10) {
            return; // Skip duplicate entry
        }
    }

    $stmt = $db->prepare("INSERT INTO context_snapshots (data) VALUES (?)");
    $stmt->execute([$data]);
    
    // Prune to keep only last 20 snapshots
    $db->exec("DELETE FROM context_snapshots WHERE id NOT IN (SELECT id FROM context_snapshots ORDER BY timestamp DESC LIMIT 20)");
}

// ACTION: SNAPSHOT MANAGEMENT
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_get_snapshots') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS context_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, data TEXT)");
    $rows = $db->query("SELECT id, timestamp, data FROM context_snapshots ORDER BY timestamp DESC")->fetchAll(PDO::FETCH_ASSOC);
    $list = array_map(function($r) {
        return [
            'id' => $r['id'],
            'timestamp' => $r['timestamp'],
            'data' => json_decode($r['data'], true)
        ];
    }, $rows);
    $foundation = ce_get_foundation(CJOS_PATH_ROOT);
    echo json_encode(['status' => 'success', 'snapshots' => $list, 'foundation' => $foundation]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_load_snapshot') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    global $db;
    $id = $_POST['id'] ?? null;
    if ($id) {
        $stmt = $db->prepare("SELECT data FROM context_snapshots WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        $stmt = $db->prepare("SELECT data FROM context_snapshots ORDER BY timestamp DESC LIMIT 1");
        $stmt->execute();
    }
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        echo json_encode(['status' => 'success', 'snapshot' => json_decode($res['data'], true)]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Snapshot not found.']);
    }
    exit;
}

// ACTION: GET/SAVE MANUAL EXTRAS
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_get_manual') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $file = CJOS_PATH_DATA . '/context-manual-extras-private.json';
    $manual = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    echo json_encode(['status' => 'success', 'manual' => $manual]);
    exit;
}
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_save_manual') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $file = CJOS_PATH_DATA . '/context-manual-extras-private.json';
    file_put_contents($file, $_POST['manual']);
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_get_apps') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $appsDir = CJOS_PATH_APPS;
    $apps = [];
    if (is_dir($appsDir)) {
        $dirs = array_filter(glob($appsDir . '/*'), 'is_dir');
        foreach ($dirs as $dir) {
            $manifestFile = $dir . '/manifest.json';
            if (file_exists($manifestFile)) {
                $manifest = json_decode(file_get_contents($manifestFile), true);
                $apps[] = [
                    'folder' => basename($dir),
                    'name' => $manifest['name'] ?? basename($dir),
                    'icon' => $manifest['icon'] ?? '📦',
                    'color' => $manifest['color'] ?? 'var(--primary)'
                ];
            }
        }
    }
    echo json_encode(['status' => 'success', 'apps' => $apps]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_get_apk_projects') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $projectsDir = CJOS_PATH_APPS . '/ApkStudio/data/projects';
    $projects = [];
    if (is_dir($projectsDir)) {
        $dirs = array_filter(glob($projectsDir . '/*'), 'is_dir');
        foreach ($dirs as $dir) {
            $projects[] = [
                'folder' => basename($dir),
                'name' => basename($dir),
                'icon' => '🔨',
                'color' => 'var(--primary-accent)'
            ];
        }
    }
    echo json_encode(['status' => 'success', 'projects' => $projects]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_get_apk_project_files') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $projectName = $_POST['project'];
    $root = CJOS_PATH_ROOT;
    $apkStudioPath = CJOS_PATH_APPS . '/ApkStudio';
    $files = [];
    
    if (is_dir($apkStudioPath)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apkStudioPath, RecursiveDirectoryIterator::SKIP_DOTS));
        
        foreach ($it as $file) {
            if ($file->isFile()) {
                $realPath = $file->getRealPath();
                if (!ce_is_binary_file($realPath)) {
                    $rel = str_replace('\\', '/', substr($realPath, strlen($root) + 1));
                    if (ce_is_ignored_by_file($rel, $root)) continue;
                    
                    // Exclude files under data/projects/ except the targeted project
                    $projectsPrefix = 'apps/ApkStudio/data/projects/';
                    if (strpos($rel, $projectsPrefix) === 0) {
                        $targetProjectPrefix = $projectsPrefix . $projectName . '/';
                        if (strpos($rel, $targetProjectPrefix) !== 0) {
                            continue; // Skip files of other projects
                        }
                    }
                    
                    $files[] = [
                        'path' => $rel,
                        'size' => round(filesize($file->getRealPath())/1024, 1) . ' KB'
                    ];
                }
            }
        }
    }
    echo json_encode(['status' => 'success', 'files' => $files]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_compile_custom_files') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $files = json_decode($_POST['files'] ?? '[]', true) ?: [];
    $root = CJOS_PATH_ROOT;
    $output = "################################################################################\n";
    $output .= "### CONJURE JIT CONTEXT: CUSTOM AUDIT EXPORT\n";
    $output .= "### GENERATED: " . date('Y-m-d H:i:s') . "\n";
    $output .= "### EXPORTED FILES (" . count($files) . "):\n";
    foreach ($files as $idx => $f) {
        $output .= "###   " . ($idx + 1) . ". " . $f . "\n";
    }
    $output .= "################################################################################\n\n";
    foreach ($files as $f) {
        $f = trim($f);
        $fullPath = $root . '/' . $f;
        if (is_readable($fullPath) && !is_dir($fullPath)) {
            $content = file_get_contents($fullPath);
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $langMap = [
                'php' => 'php', 'js' => 'javascript', 'css' => 'css', 'json' => 'json',
                'md' => 'markdown', 'txt' => 'text', 'sql' => 'sql', 'html' => 'html', 'htm' => 'html',
                'sh' => 'bash', 'bash' => 'bash', 'zsh' => 'bash', 'ksh' => 'bash', 'csh' => 'bash',
                'ps1' => 'powershell', 'bat' => 'batch', 'cmd' => 'batch',
                'py' => 'python', 'rb' => 'ruby', 'pl' => 'perl', 'lua' => 'lua',
                'yaml' => 'yaml', 'yml' => 'yaml', 'toml' => 'toml',
                'conf' => 'ini', 'ini' => 'ini', 'env' => 'ini',
                'java' => 'java', 'go' => 'go', 'kt' => 'kotlin', 'xml' => 'xml', 'tpl' => 'text', 'svg' => 'xml',
                'c' => 'c', 'cpp' => 'cpp', 'h' => 'c', 'hpp' => 'cpp',
                'gitignore' => 'ini', 'orbitignore' => 'ini', 'ignore' => 'ini'
            ];
            $lang = $langMap[$ext] ?? 'text';
            $output .= "================================================================================\nFILE START: $f\n================================================================================\n```$lang\n$content\n```\n\n";
        }
    }
    echo json_encode(['status' => 'success', 'context' => $output]);
    exit;
}

if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'ce_download_app_source') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/plain');
    $appFolder = $_GET['folder'];
    $mode = $_GET['mode'] ?? 'clean';
    header('Content-Disposition: attachment; filename="Source_' . $appFolder . '_' . ucfirst($mode) . '_' . date('md_Hi') . '.txt"');
    
    $root = CJOS_PATH_ROOT;
    $fullPath = CJOS_PATH_APPS . '/' . $appFolder;
    
    if (is_dir($fullPath)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $disallowedExts = ['png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'bmp', 'mp3', 'ogg', 'wav', 'flac', 'mp4', 'mkv', 'avi', 'mov', 'zip', 'tar', 'gz', '7z', 'rar', 'db', 'db-shm', 'db-wal', 'sqlite', 'exe', 'dll', 'so', 'dylib', 'a', 'o', 'bin', 'apk', 'jar', 'class', 'pdf', 'ttf', 'otf', 'woff', 'woff2', 'eot'];
        
    $tree = [];$filesToInclude = [];

        foreach ($it as $file) {
            $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($fullPath) + 1));
            $fullRel = 'apps/' . $appFolder . '/' . $rel;
            
            // Exclude folders/files dynamically ignored by .contextignore or .noexport
            if (ce_is_ignored_by_file($fullRel, $root)) continue;
            
            if ($file->isFile()) {
                $realPath = $file->getRealPath();
                if (ce_is_binary_file($realPath)) continue;
                if ($mode === 'clean' && strpos(basename($rel), '-private.json') !== false) continue;
                
                $filesToInclude[] = [
                    'fullRel' => $fullRel,
                    'realPath' => $file->getRealPath(),
                    'ext' => $ext
                ];
            }

            // Build Tree Structure
            $parts = explode('/', $rel);
            $curr = &$tree;
            foreach ($parts as $part) {
                if (!isset($curr[$part])) $curr[$part] = [];
                $curr = &$curr[$part];
            }
            if ($file->isFile()) $curr['__file'] = true;
        }

        // 1. Render Tree
        echo "################################################################################\n";
        echo "### APP SOURCE CONTEXT: " . strtoupper($appFolder) . " (" . strtoupper($mode) . ")\n";
        echo "### GENERATED: " . date('Y-m-d H:i:s') . "\n";
        echo "################################################################################\n\n";
        echo "APP STRUCTURE\n===========================\n```txt\n";
        $renderNode = function($node, $indent = "") use (&$renderNode) {
            $out = ""; $keys = array_keys($node); sort($keys);
            foreach ($keys as $i => $key) {
                if ($key === '__file') continue;
                $isLast = ($i === count($keys) - 1 || (count($keys) === 2 && isset($node['__file'])));
                $connector = $isLast ? "└── " : "├── ";
                $childIndent = $isLast ? "    " : "│   ";
                if (isset($node[$key]['__file'])) {
                    $out .= $indent . $connector . $key . "\n";
                } else {
                    $out .= $indent . $connector . $key . "/\n" . $renderNode($node[$key], $indent . $childIndent);
                }
            }
            return $out;
        };
        echo "apps/" . $appFolder . "/\n" . $renderNode($tree);
        echo "```\n\n";

        // 2. Render Files
        foreach ($filesToInclude as $f) {
            $content = file_get_contents($f['realPath']);
            $lang = ($f['ext'] === 'php') ? 'php' : (($f['ext'] === 'js') ? 'javascript' : (($f['ext'] === 'css') ? 'css' : (($f['ext'] === 'json') ? 'json' : 'txt')));
            echo "================================================================================\nFILE START: " . $f['fullRel'] . "\n================================================================================\n```$lang\n$content\n```\n\n";
        }
    }
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_get_app_source') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $appFolder = $_POST['folder'];
    $root = CJOS_PATH_ROOT;
    $fullPath = CJOS_PATH_APPS . '/' . $appFolder;
    $output = "";
    
    if (is_dir($fullPath)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS));
        
        foreach ($it as $file) {
            if ($file->isFile()) {
                $realPath = $file->getRealPath();
                if (!ce_is_binary_file($realPath)) {
                    $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));
                    if (ce_is_ignored_by_file($rel, $root)) continue;
                    $content = file_get_contents($file->getRealPath());
                    $lang = ($ext === 'php') ? 'php' : (($ext === 'js') ? 'javascript' : (($ext === 'css') ? 'css' : (($ext === 'json') ? 'json' : 'txt')));
                    $output .= "================================================================================\nFILE START: $rel\n================================================================================\n```$lang\n$content\n```\n\n";
                }
            }
        }
    }
    echo json_encode(['status' => 'success', 'source' => $output]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_get_app_files') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $appFolder = $_POST['folder'];
    $root = CJOS_PATH_ROOT;
    $fullPath = CJOS_PATH_APPS . '/' . $appFolder;
    $files = [];
    
    if (is_dir($fullPath)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS));
        
        foreach ($it as $file) {
            if ($file->isFile()) {
                $realPath = $file->getRealPath();
                if (!ce_is_binary_file($realPath)) {
                    $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));
                    if (ce_is_ignored_by_file($rel, $root)) continue;
                    $files[] =[
                        'path' => $rel,
                        'size' => round(filesize($file->getRealPath())/1024, 1) . ' KB'
                    ];
                }
            }
        }
    }
    echo json_encode(['status' => 'success', 'files' => $files]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_rebuild_dependency_maps') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $res = ce_generate_dependency_maps(CJOS_PATH_ROOT, true);
    echo json_encode(['status' => 'success', 'counts' => $res]);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_get_exclusions') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'exclusions' => ce_get_exclusions(CJOS_PATH_ROOT)]);
    exit;
}

// ACTION: PRE-FLIGHT PRIVACY CHECK
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_preflight') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $tier = $_POST['tier'] ?? 'foundation';
    $root = CJOS_PATH_ROOT;
    $files = ce_get_context_file_list($tier, $root);
    $risks = ce_check_privacy_risk($files, $root);
    echo json_encode([
        'status' => 'success',
        'files' => $files,
        'risks' => $risks,
        'risk_count' => count($risks)
    ]);
    exit;
}

// ACTION: GET STATS FOR UI
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_reorder_category') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $config = ce_get_layout_config();
    $cat = $_POST['category'];
    $dir = $_POST['direction']; // 'up' or 'down'
    $idx = array_search($cat, $config['categories']);
    if ($idx !== false) {
        $newIdx = ($dir === 'up') ? $idx - 1 : $idx + 1;
        if ($newIdx >= 0 && $newIdx < count($config['categories'])) {
            $tmp = $config['categories'][$newIdx];
            $config['categories'][$newIdx] = $config['categories'][$idx];
            $config['categories'][$idx] = $tmp;
            file_put_contents(CJOS_PATH_DATA . '/context-layout-config.json', json_encode($config, JSON_PRETTY_PRINT));
        }
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_reorder_foundation') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $root = CJOS_PATH_ROOT;
    $list = ce_get_foundation($root);
    $file = $_POST['file'];
    $dir = $_POST['direction'] ?? null;
    $targetIdx = isset($_POST['target_index']) ? (int)$_POST['target_index'] : null;
    
    $idx = array_search($file, $list);
    if ($idx !== false) {
        if ($targetIdx !== null) {
            // Absolute Move (Drag & Drop)
            array_splice($list, $idx, 1);
            array_splice($list, $targetIdx, 0, $file);
        } else {
            // Relative Move (Buttons)
            $newIdx = ($dir === 'up') ? $idx - 1 : $idx + 1;
            if ($newIdx >= 0 && $newIdx < count($list)) {
                $tmp = $list[$newIdx];
                $list[$newIdx] = $list[$idx];
                $list[$idx] = $tmp;
            }
        }
        file_put_contents(CJOS_PATH_DATA . '/foundation-config.json', json_encode($list, JSON_PRETTY_PRINT));
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ce_get_stats') {
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
$root = CJOS_PATH_ROOT;
$relData = str_replace($root . '/', '', CJOS_PATH_DATA);
$fExcl = ce_get_exclusions('foundation');
$eExcl = ce_get_exclusions('extra');

// Ensure map exists for accurate stats
if (!file_exists($root . '/' . $relData . '/knowledge/sys_map.json')) {
    // Handshake: If KB map is missing, we assume synthesis is needed.
    // We don't trigger a scan here to avoid blocking the UI; 
    // the user should run Knowledge Builder.
}
    
$foundationFiles = ce_get_foundation($root);
    
    $fSize = 0;
    $fList = [];

    // Inject Patcher Manual into Foundation UI list
    $patcherManualRel = str_replace(CJOS_PATH_ROOT . '/', '', CJOS_PATH_DATA) . '/knowledge/patcher_manual.md';
    if (!in_array($patcherManualRel, $foundationFiles)) {
        $foundationFiles[] = $patcherManualRel;
    }

    foreach($foundationFiles as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) === 'db') continue;
        $full = $root.'/'.$f;
        
        // Special Case: Edit Log (Database-backed virtual file)
        if ($f === $relData . '/edit-log.json') {
            $limit = 15; // Default export limit
            $el_conf_file = CJOS_PATH_DATA . '/edit-log-config.json';
            if (file_exists($el_conf_file)) {
                $el_conf = json_decode(file_get_contents($el_conf_file), true);
                if (isset($el_conf['export_limit'])) $limit = (int)$el_conf['export_limit'];
            }
            
            $s = 0;
            $el_db_file = CJOS_PATH_DATA . '/edit-log.db';
            if (file_exists($el_db_file)) {
                try {
                    $el_db = new PDO("sqlite:" . $el_db_file);
                    $rows = $el_db->query("SELECT date, summary FROM edit_log ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
                    $s = strlen(json_encode($rows, JSON_PRETTY_PRINT));
                } catch(Exception $e) { $s = 0; }
            }
            $fSize += $s;
            $fList[] = ['path' => $f, 'size' => round($s/1024, 1).' KB'];
            continue;
        }

        if(file_exists($full)) {
            $s = filesize($full);
            $fSize += $s;
            $fList[] = ['path' => $f, 'size' => round($s/1024, 1).' KB'];
        }
    }

    $pSize = $fSize;
    $projectList = [];
    $manualList = [];
    $extraPaths = []; // Track uniqueness

    // 1. Manual Extras
    $manualFile = $root . '/' . $relData . '/context-manual-extras-private.json';
    if (file_exists($manualFile)) {
        $manual = json_decode(file_get_contents($manualFile), true);
        foreach ($manual as $f) {
            if (in_array($f, $foundationFiles) || in_array($f, $extraPaths)) continue;
            $full = $root . '/' . $f;
            if (file_exists($full)) {
                $s = filesize($full);
                $pSize += $s;
                $manualList[] = ['path' => $f, 'size' => round($s/1024, 1).' KB'];
                $extraPaths[] = $f;
            }
        }
    }

    // 2. Project Extras & Detailed Grouping (Optimized: Only process active projects)
    $projectGroups = [];
    $pp_projects_dir = $root . '/' . $relData . '/projects';
    $pp_config_file = $root . '/' . $relData . '/project-planner-config.json';
    
    if (is_dir($pp_projects_dir)) {
        $pp_conf = file_exists($pp_config_file) ? json_decode(file_get_contents($pp_config_file), true) : [];
        $active_projects = $pp_conf['active_projects'] ?? ($pp_conf['active_project'] ? [$pp_conf['active_project']] : []);
        
        foreach ($active_projects as $activePath) {
            $fullPath = $pp_projects_dir . '/' . $activePath;
            if (!file_exists($fullPath) || is_dir($fullPath)) continue;
            
            $relMd = str_replace('\\', '/', substr(realpath($fullPath), strlen($root) + 1));
            $content = file_get_contents($fullPath);
            
            $title = basename($activePath, '.md');
            if (preg_match('/Title:\s*(.*)$/m', $content, $m)) $title = trim($m[1]);

            $groupFiles = [];
            $groupFiles[] = ['path' => $relMd, 'size' => round(filesize($fullPath)/1024, 1) . ' KB'];
            
            if (preg_match('/Scope:\s*\[(.*?)\]/is', $content, $m)) {
                $scoped = explode(',', $m[1]);
                foreach ($scoped as $f) {
                    $resolved = ce_resolve_path($f, $root);
                    if ($resolved && pathinfo($resolved, PATHINFO_EXTENSION) !== 'db') {
                        $groupFiles[] = ['path' => $resolved, 'size' => round(filesize($root.'/'.$resolved)/1024, 1) . ' KB'];
                    }
                }
            }

            $projectGroups[] = [
                'filename' => $activePath,
                'title' => $title,
                'isActive' => true,
                'files' => $groupFiles
            ];

            foreach ($groupFiles as $gf) {
                if (!in_array($gf['path'], $extraPaths) && !in_array($gf['path'], $foundationFiles)) {
                    $pSize += (float)$gf['size'] * 1024;
                    $projectList[] = $gf;
                    $extraPaths[] = $gf['path'];
                }
            }
        }
    }

    echo json_encode([
        'status' => 'success', 
        'stats' => [
            'foundation_kb' => round($fSize / 1024, 1),
            'project_kb' => round($pSize / 1024, 1),
            'f_list' => $fList,
            'p_list' => $projectList,
            'm_list' => $manualList,
            'project_groups' => $projectGroups,
            'foundation_exclusions' => $fExcl,
            'extra_exclusions' => $eExcl,
            'layout_categories' => ce_get_layout_config()['categories']
        ]
    ]);
    exit;
}

// HELPER: Context File List Builder
function ce_get_context_file_list($tier, $root) {
    $fExcl = ce_get_exclusions('foundation');
    $eExcl = ce_get_exclusions('extra');
    $relData = str_replace($root . '/', '', CJOS_PATH_DATA);
    $fileList = [];

    // --- TIER 1: FOUNDATION (Always included) ---
    $fFiles = ce_get_foundation($root);
    foreach ($fFiles as $f) {
        if (!in_array($f, $fExcl) && !in_array($f, $fileList)) $fileList[] = $f;
    }

    // --- TIER 3: CAPSULE (Latest Session Capsule) ---
if ($tier === 'capsule') {
    $temp_dir = $root . '/' . $relData . '/projects/temp';
    if (is_dir($temp_dir)) {
        $capsules = glob($temp_dir . '/Session_Capsule_*.md');
        if (!empty($capsules)) {
            rsort($capsules);
            $latest = $capsules[0];
            $relLatest = str_replace('\\', '/', substr($latest, strlen($root) + 1));
            if (!in_array($relLatest, $fileList)) {
                $fileList[] = $relLatest;
            }

            // Extract the project scope from the latest session capsule file
            $content = file_get_contents($latest);
            if (preg_match('/Scope:\s*\[(.*?)\]/i', $content, $m)) {
                $scoped = explode(',', $m[1]);
                foreach ($scoped as $f) {
                    $f = trim($f);
                    if (empty($f)) continue;
                    $resolved = ce_resolve_path($f, $root);
                    if ($resolved && pathinfo($resolved, PATHINFO_EXTENSION) !== 'db') {
                        // Always include these scoped files, bypassing active exclusions to guarantee export
                        if (!in_array($resolved, $fileList)) {
                            $fileList[] = $resolved;
                        }
                    }
                }
            }
        }
    }
}

// --- TIER 2: PROJECT (Only included if tier is 'project') ---
if ($tier === 'project') {// 1. Project Planner Scope
        $pp_config_file = $root . '/' . $relData . '/project-planner-config.json';
        if (file_exists($pp_config_file)) {
            $pp_conf = json_decode(file_get_contents($pp_config_file), true);
            $active_projects = $pp_conf['active_projects'] ?? ($pp_conf['active_project'] ? [$pp_conf['active_project']] : []);
            
            foreach ($active_projects as $filename) {
                $plan_path = $root . '/' . $relData . '/projects/' . $filename;
                if (file_exists($plan_path)) {
                    $relPlan = $relData . '/projects/' . $filename;
                    if (!in_array($relPlan, $eExcl) && !in_array($relPlan, $fileList)) $fileList[] = $relPlan;
                    
                    $audit_file = str_replace('.md', '.audit.json', $filename);
                    $relAudit = $relData . '/projects/' . $audit_file;
                    if (file_exists($root . '/' . $relAudit)) {
                        if (!in_array($relAudit, $eExcl) && !in_array($relAudit, $fileList)) $fileList[] = $relAudit;
                    }

                    $content = file_get_contents($plan_path);
                    if (preg_match('/Scope:\s*\[(.*?)\]/i', $content, $m)) {
                        $scoped = explode(',', $m[1]);
                        foreach ($scoped as $f) {
                            $resolved = ce_resolve_path($f, $root);
                            if ($resolved && pathinfo($resolved, PATHINFO_EXTENSION) !== 'db') {
                                if (!in_array($resolved, $eExcl) && !in_array($resolved, $fileList)) $fileList[] = $resolved;
                            }
                        }
                    }
                }
            }
        }

        // 2. Manual Extras
        $manualFile = $root . '/' . $relData . '/context-manual-extras-private.json';
        if (file_exists($manualFile)) {
            $manual = json_decode(file_get_contents($manualFile), true);
            if (is_array($manual)) {
                foreach ($manual as $f) {
                    if (!in_array($f, $eExcl) && !in_array($f, $fileList)) $fileList[] = $f;
                }
            }
        }
    }

    // Apply dynamic folder-level ignore filters (.contextignore or .noexport)
    $filteredList = [];
    foreach ($fileList as $f) {
        if (!ce_is_ignored_by_file($f, $root)) {
            $filteredList[] = $f;
        }
    }
    return $filteredList;
}

// HELPER: Privacy Risk Scanner (Foundation Files Exempted)
function ce_check_privacy_risk($fileList, $root = null) {
    if (!$root) $root = CJOS_PATH_ROOT;
    $foundationFiles = ce_get_foundation($root);
    $fExcl = ce_get_exclusions('foundation');
    
    // Active Foundation files are intentionally chosen by system/user
    $activeFoundation = array_filter($foundationFiles, function($f) use ($fExcl) {
        return !in_array($f, $fExcl);
    });

    $risks = [];
    foreach ($fileList as $f) {
        // Exception: Approved Foundation files do not trigger privacy alerts
        if (in_array($f, $activeFoundation)) continue;

        if (strpos($f, '-private.') !== false) $risks[] = $f;
    }
    return array_values($risks);
}

// HELPER: Code Skeletonizer Engine
if (!function_exists('ce_skeletonize_code_braces')) {
    function ce_skeletonize_code_braces($content, $ext) {
        $len = strlen($content);
        $out = '';
        $i = 0;
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $collapseDepth = -1;
        $recentCode = '';

        while ($i < $len) {
            $ch = $content[$i];
            $next = ($i + 1 < $len) ? $content[$i + 1] : '';

            if ($inString) {
                if ($collapseDepth === -1) $out .= $ch;
                if ($ch === '\\') {
                    if ($collapseDepth === -1 && $next !== '') $out .= $next;
                    $i += 2;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                $i++;
                continue;
            }

            if ($inComment === 'line') {
                if ($collapseDepth === -1) $out .= $ch;
                if ($ch === "\n") {
                    $inComment = false;
                    $recentCode = '';
                }
                $i++;
                continue;
            }
            if ($inComment === 'block') {
                if ($collapseDepth === -1) $out .= $ch;
                if ($ch === '*' && $next === '/') {
                    if ($collapseDepth === -1) $out .= '/';
                    $inComment = false;
                    $i += 2;
                    continue;
                }
                $i++;
                continue;
            }

            if ($ch === '/' && $next === '/') {
                $inComment = 'line';
                if ($collapseDepth === -1) $out .= '//';
                $i += 2;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inComment = 'block';
                if ($collapseDepth === -1) $out .= '/*';
                $i += 2;
                continue;
            }
            if ($ext === 'sh' || $ext === 'bash') {
                if ($ch === '#') {
                    $inComment = 'line';
                    if ($collapseDepth === -1) $out .= '#';
                    $i++;
                    continue;
                }
            }

            if ($ch === '"' || $ch === "'" || $ch === '`') {
                $inString = true;
                $stringChar = $ch;
                if ($collapseDepth === -1) $out .= $ch;
                $i++;
                continue;
            }

            if ($collapseDepth !== -1) {
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth < $collapseDepth) {
                        $collapseDepth = -1;
                        $out .= " /* [Impl hidden] */ }";
                        $recentCode = '';
                    }
                }
                $i++;
                continue;
            }

            if ($ch === '{') {
                $depth++;
                $isContainer = preg_match('/\b(class|interface|trait|enum|namespace|struct|switch)\b/i', $recentCode);
                if (!$isContainer) {
                    $collapseDepth = $depth;
                    $out .= " {";
                    $recentCode = '';
                    $i++;
                    continue;
                } else {
                    $out .= '{';
                    $recentCode = '';
                    $i++;
                    continue;
                }
            } elseif ($ch === '}') {
                if ($depth > 0) $depth--;
                $out .= '}';
                $recentCode = '';
                $i++;
                continue;
            }

            if ($ch === "\n" || $ch === ';') {
                $recentCode = '';
            } else {
                $recentCode .= $ch;
                if (strlen($recentCode) > 200) {
    $recentCode = mb_strlen($recentCode, 'UTF-8') > 150 ? mb_substr($recentCode, -150, null, 'UTF-8') : $recentCode;
}}

            $out .= $ch;
            $i++;
        }

        return $out;
    }
}

if (!function_exists('ce_skeletonize_json')) {
    function ce_skeletonize_json($content) {
        $data = json_decode($content, true);
        if ($data === null) return $content;

        $prune = function($item, $depth = 0) use (&$prune) {
            if (!is_array($item)) {
                if (is_string($item) && mb_strlen($item, 'UTF-8') > 100) {
                    return mb_substr($item, 0, 97, 'UTF-8') . '...';
                }
                return $item;
            }
            if (count($item) > 10 && $depth > 0) {
                $sample = array_slice($item, 0, 3, true);
                $sample['__summary'] = "... " . (count($item) - 3) . " items hidden ...";
                return $sample;
            }
            $res = [];
            foreach ($item as $k => $v) {
                $res[$k] = $prune($v, $depth + 1);
            }
            return $res;
        };

        return json_encode($prune($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('ce_skeletonize_css')) {
    function ce_skeletonize_css($content) {
        $lines = explode("\n", $content);
        $out = [];
        $inRoot = false;
        $inRule = false;
        $ruleBuffer = [];
        $ruleHeader = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (strpos($trimmed, ':root') !== false) {
                $inRoot = true;
                $out[] = $line;
                continue;
            }
            if ($inRoot) {
                $out[] = $line;
                if (strpos($trimmed, '}') !== false) $inRoot = false;
                continue;
            }

            if (strpos($trimmed, '{') !== false) {
                $inRule = true;
                $ruleHeader = $line;
                $ruleBuffer = [];
                continue;
            }

            if ($inRule) {
                if (strpos($trimmed, '}') !== false) {
                    $inRule = false;
                    if (count($ruleBuffer) > 4) {
                        $out[] = $ruleHeader;
                        $out[] = "    " . trim($ruleBuffer[0]);
                        $out[] = "    /* ... " . (count($ruleBuffer) - 1) . " rules hidden ... */";
                        $out[] = "}";
                    } else {
                        $out[] = $ruleHeader;
                        foreach ($ruleBuffer as $rb) $out[] = $rb;
                        $out[] = $line;
                    }
                } else {
                    if ($trimmed !== '') $ruleBuffer[] = $line;
                }
                continue;
            }

            if (strpos($trimmed, '@import') === 0 || strpos($trimmed, '@font-face') === 0 || strpos($trimmed, '/*') === 0) {
                $out[] = $line;
            }
        }

        return !empty($out) ? implode("\n", $out) : $content;
    }
}

if (!function_exists('ce_skeletonize_html')) {
    function ce_skeletonize_html($content) {
        $lines = explode("\n", $content);
        $out = [];
        $inScript = false;
        $inStyle = false;
        $scriptBuffer = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/<script\b/i', $trimmed)) {
                $inScript = true;
                $out[] = $line;
                $scriptBuffer = [];
                continue;
            }
            if ($inScript) {
                if (preg_match('/<\/script>/i', $trimmed)) {
                    $inScript = false;
                    $scriptCode = implode("\n", $scriptBuffer);
                    $skelScript = ce_skeletonize_code_braces($scriptCode, 'js');
                    $out[] = $skelScript;
                    $out[] = $line;
                } else {
                    $scriptBuffer[] = $line;
                }
                continue;
            }

            if (preg_match('/<style\b/i', $trimmed)) {
                $inStyle = true;
                $out[] = $line;
                continue;
            }
            if ($inStyle) {
                if (preg_match('/<\/style>/i', $trimmed)) {
                    $inStyle = false;
                    $out[] = $line;
                }
                continue;
            }

            if (preg_match('/<([a-z0-9]+)\b[^>]*\b(id|class|name|type|onclick|onchange|data-[a-z0-9-]+)=/i', $trimmed) ||
                preg_match('/<\/?(header|footer|nav|section|form|input|button|select|option|table|thead|tbody|tr|th|td|canvas|svg|iframe|textarea|modal|h[1-6])\b/i', $trimmed)) {
                $out[] = $line;
            } elseif (strlen($trimmed) > 0 && substr($trimmed, 0, 1) === '<') {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }
}

if (!function_exists('ce_skeletonize_md')) {
    function ce_skeletonize_md($content) {
        $lines = explode("\n", $content);
        $out = [];
        $inCodeBlock = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (strpos($trimmed, '```') === 0) {
                $inCodeBlock = !$inCodeBlock;
                $out[] = $line;
                if ($inCodeBlock) $out[] = "/* [Code block content hidden] */";
                continue;
            }
            if ($inCodeBlock) continue;

            if (strpos($trimmed, '#') === 0 || strpos($trimmed, '-') === 0 || strpos($trimmed, '*') === 0 || strpos($trimmed, '---') === 0 || empty($trimmed)) {
                $out[] = $line;
            } else {
                if (mb_strlen($trimmed, 'UTF-8') > 80) {
                    $out[] = mb_substr($trimmed, 0, 77, 'UTF-8') . '...';
                } else {
                    $out[] = $line;
                }
            }
        }

        return implode("\n", $out);
    }
}

if (!function_exists('ce_skeletonize_content')) {
    function ce_skeletonize_content($content, $ext = 'text') {
        if (empty(trim($content))) return $content;
        $ext = strtolower($ext);

        // Exempt Markdown files completely so system instructions, file trees, and rules remain full
        if ($ext === 'md' || $ext === 'markdown') {
            return $content;
        }

        $res = $content;
        if (in_array($ext, ['php', 'js', 'javascript', 'java', 'go', 'kt', 'kotlin', 'c', 'cpp', 'sh', 'bash'])) {
            $res = ce_skeletonize_code_braces($content, $ext);
        } elseif (in_array($ext, ['json'])) {
            $res = ce_skeletonize_json($content);
        } elseif (in_array($ext, ['css'])) {
            $res = ce_skeletonize_css($content);
        } elseif (in_array($ext, ['html', 'htm', 'xml', 'tpl', 'svg'])) {
            $res = ce_skeletonize_html($content);
        } else {
            // Fallback: Condense large unknown or extensionless text files (>30 KB)
            if (mb_strlen($content, 'UTF-8') > 30000) {
                $lines = explode("\n", $content);
                if (count($lines) > 30) {
                    $head = array_slice($lines, 0, 20);
                    $hiddenCount = count($lines) - 20;
                    $res = implode("\n", $head) . "\n/* ... {$hiddenCount} lines hidden (Unknown / Extensionless Text File) ... */";
                }
            }
        }

        if (function_exists('mb_convert_encoding')) {
            $res = mb_convert_encoding($res, 'UTF-8', 'UTF-8');
        }
        return $res;
    }
}

// HELPER: Context Generator (Reusable by other plugins like AiChat)
function ce_generate_context_text($tier, $root, $isSkeleton = false) {
    if (strpos($tier, '_skeleton') !== false) {
        $isSkeleton = true;
        $tier = str_replace('_skeleton', '', $tier);
    }
    $fileList = ce_get_context_file_list($tier, $root);
    
    // Log latest export manifest to a separate JSON
    file_put_contents(CJOS_PATH_DATA . '/last-context-export.json', json_encode([
        'date' => date('Y-m-d H:i:s'),
        'tier' => $tier,
        'files' => $fileList
    ], JSON_PRETTY_PRINT));

    $output = "################################################################################\n";
    $output .= "### CONJURE JIT CONTEXT: " . strtoupper($tier) . "\n";
    $output .= "### GENERATED: " . date('Y-m-d H:i:s') . "\n";
    $output .= "### EXPORTED FILES (" . count($fileList) . "):\n";
    foreach ($fileList as $idx => $f) {
        $output .= "###   " . ($idx + 1) . ". " . $f . "\n";
    }
    $output .= "################################################################################\n\n";

    foreach ($fileList as $f) {
        $relData = str_replace($root . '/', '', CJOS_PATH_DATA);
        $fullPath = $root . '/' . $f;
        
        // Special Case: Edit Log (Database-backed virtual file)
        if ($f === $relData . '/edit-log.json') {
            $limit = 15; // Default export limit
            $el_conf_file = CJOS_PATH_DATA . '/edit-log-config.json';
            if (file_exists($el_conf_file)) {
                $el_conf = json_decode(file_get_contents($el_conf_file), true);
                if (isset($el_conf['export_limit'])) $limit = (int)$el_conf['export_limit'];
            }
            
            $el_db_file = CJOS_PATH_DATA . '/edit-log.db';
            $content = "[]";
            if (file_exists($el_db_file)) {
                try {
                    $el_db = new PDO("sqlite:" . $el_db_file);
                    $rows = $el_db->query("SELECT date, summary FROM edit_log ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
                    $content = json_encode($rows, JSON_PRETTY_PRINT);
                } catch(Exception $e) { $content = "[]"; }
            }
            $output .= "================================================================================\nFILE START: $f\n================================================================================\n```json\n$content\n```\n\n";
            continue;
        }

        if (is_readable($fullPath) && !is_dir($fullPath)) {
            if (ce_is_binary_file($fullPath)) continue;
            
            $content = file_get_contents($fullPath);
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            
            if ($isSkeleton) {
                $content = ce_skeletonize_content($content, $ext);
            }
            
            $langMap = [
                'php' => 'php', 'js' => 'javascript', 'css' => 'css', 'json' => 'json',
                'md' => 'markdown', 'txt' => 'text', 'sql' => 'sql', 'html' => 'html', 'htm' => 'html',
                'sh' => 'bash', 'bash' => 'bash', 'zsh' => 'bash', 'ksh' => 'bash', 'csh' => 'bash',
                'ps1' => 'powershell', 'bat' => 'batch', 'cmd' => 'batch',
                'py' => 'python', 'rb' => 'ruby', 'pl' => 'perl', 'lua' => 'lua',
                'yaml' => 'yaml', 'yml' => 'yaml', 'toml' => 'toml',
                'conf' => 'ini', 'ini' => 'ini', 'env' => 'ini',
                'java' => 'java', 'go' => 'go', 'kt' => 'kotlin', 'xml' => 'xml', 'tpl' => 'text', 'svg' => 'xml',
                'c' => 'c', 'cpp' => 'cpp', 'h' => 'c', 'hpp' => 'cpp',
                'gitignore' => 'ini', 'orbitignore' => 'ini', 'ignore' => 'ini'
            ];
            $lang = $langMap[$ext] ?? 'text';
            
            $output .= "================================================================================\nFILE START: $f\n================================================================================\n```$lang\n$content\n```\n\n";
        }
    }
    if (function_exists('mb_convert_encoding')) {
        $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
    }
    return $output;
}

if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'ce_download_custom') {
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0); ini_set('memory_limit', '512M');
    $files = json_decode($_GET['files'] ?? '[]', true) ?: [];
    $root = CJOS_PATH_ROOT;
    $output = "################################################################################\n";
    $output .= "### CONJURE JIT CONTEXT: AUDITED FILES EXPORT\n";
    $output .= "### GENERATED: " . date('Y-m-d H:i:s') . "\n";
    $output .= "### EXPORTED FILES (" . count($files) . "):\n";
    foreach ($files as $idx => $f) {
        $output .= "###   " . ($idx + 1) . ". " . $f . "\n";
    }
    $output .= "################################################################################\n\n";
    foreach ($files as $f) {
        $f = trim($f);
        $fullPath = $root . '/' . $f;
        if (is_readable($fullPath) && !is_dir($fullPath)) {
            $content = file_get_contents($fullPath);
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $langMap = [
                'php' => 'php', 'js' => 'javascript', 'css' => 'css', 'json' => 'json',
                'md' => 'markdown', 'txt' => 'text', 'sql' => 'sql', 'html' => 'html', 'htm' => 'html',
                'sh' => 'bash', 'bash' => 'bash', 'zsh' => 'bash', 'ksh' => 'bash', 'csh' => 'bash',
                'ps1' => 'powershell', 'bat' => 'batch', 'cmd' => 'batch',
                'py' => 'python', 'rb' => 'ruby', 'pl' => 'perl', 'lua' => 'lua',
                'yaml' => 'yaml', 'yml' => 'yaml', 'toml' => 'toml',
                'conf' => 'ini', 'ini' => 'ini', 'env' => 'ini',
                'java' => 'java', 'go' => 'go', 'kt' => 'kotlin', 'xml' => 'xml', 'tpl' => 'text', 'svg' => 'xml',
                'c' => 'c', 'cpp' => 'cpp', 'h' => 'c', 'hpp' => 'cpp',
                'gitignore' => 'ini', 'orbitignore' => 'ini', 'ignore' => 'ini'
            ];
            $lang = $langMap[$ext] ?? 'text';
            $output .= "================================================================================\nFILE START: $f\n================================================================================\n```$lang\n$content\n```\n\n";
        }
    }
    $dlName = "Audit_Context_" . date('md_Hi') . ".txt";
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $dlName . '"');
    echo $output;
    exit;
}

if (isset($_GET['plugin_action']) && $_GET['plugin_action'] === 'ce_download') {
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0); ini_set('memory_limit', '512M');

    // Regenerate Protocol Manual to ensure AI has latest definitions
    if (function_exists('cp_generate_manual_internal')) {
        cp_generate_manual_internal();
    }
    
    global $db;
    ce_perform_snapshot_save($db);
    
    $root = CJOS_PATH_ROOT;
    $tier = $_GET['tier'] ?? 'foundation';
    $isSkeleton = (isset($_GET['skeleton']) && $_GET['skeleton'] == '1') || (strpos($tier, '_skeleton') !== false);

    // --- PRIVACY SHIELD: Direct Access Protection ---
    $cleanTier = str_replace('_skeleton', '', $tier);
    $files = ce_get_context_file_list($cleanTier, $root);
    $risks = ce_check_privacy_risk($files, $root);
    if (!empty($risks) && !isset($_GET['confirm_privacy'])) {
        $msg = "PRIVACY ALERT: Direct context export attempt blocked. Sensitive files detected: " . implode(', ', $risks);
        // Log to EditLog for visibility
        if (function_exists('el_add_log_entry')) {
            el_add_log_entry("SECURITY: Blocked unconfirmed export containing " . count($risks) . " private files.");
        }
        header('HTTP/1.1 403 Forbidden');
        echo $msg;
        exit;
    }
    
    // Pre-flight: Update the structure MD
    ce_update_structure_md($root);
    
    $output = ce_generate_context_text($cleanTier, $root, $isSkeleton);

    $skelTag = $isSkeleton ? "_Skeleton" : "";
    $dlName = "Context_" . date('md_Hi') . "_" . ucfirst($cleanTier) . $skelTag . ".txt";
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $dlName . '"');
    echo $output;
    exit;
}

// --- 2. SETTINGS UI ---
$plugin_settings_map['ContextExporter'] = <<<'HTML'
    <style>
        #ce-manifest-wrap, #ce-kb-monitor-wrap { transition: margin-bottom 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
        #ce-manifest-wrap.is-expanded, #ce-kb-monitor-wrap.is-expanded { margin-bottom: 10px; }
    </style>
<div id="ce-tray-anchor">
    <div id="ce-gui-root">
    <div class="setting-item vertical" style="padding-bottom: 0 !important;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <label class="setting-label" style="margin:0;">Context Size Monitor</label>
            <button onclick="ceOpenStudio()" style="width:36px; height:32px; border-radius:10px; border:1px solid var(--border-color); background:var(--btn-bg); color:var(--primary); cursor:pointer; display:flex; align-items:center; justify-content:center;">
                <span data-sui-icon="layout" data-sui-size="16" data-sui-stroke="2.5"></span>
            </button>
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:8px;">
            <div style="background:var(--btn-bg); padding:12px; border-radius:12px; text-align:center;">
                <div style="font-size:9px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Foundation</div>
                <div id="ce-stat-fsize" style="font-size:16px; font-weight:900; color:var(--text-primary);">0 KB</div>
            </div>
            <div style="background:var(--btn-bg); padding:12px; border-radius:12px; text-align:center;">
                <div style="font-size:9px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Project (+<span id="ce-stat-pcount">0</span> files)</div>
                <div id="ce-stat-psize" style="font-size:16px; font-weight:900; color:var(--ai-accent);">0 KB</div>
            </div>
        </div>
        <div style="margin-top:10px; text-align:center;">
            <span id="ce-token-est" style="font-size:10px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Est. Tokens: ~0</span>
        </div>

        <!-- FILE MANIFEST ACCORDION -->
        <div id="ce-manifest-wrap" style="margin-top:10px;">
            <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:rgba(0,0,0,0.03); border-radius:10px; cursor:pointer;" onclick="suiToggle('ce-manifest-sec'); this.parentElement.classList.toggle('is-expanded');">
                <span style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">View Context Manifest</span>
                <span data-sui-icon="chevron" data-sui-arrow="ce-manifest-sec" data-sui-size="12" style="transition:transform 0.3s; transform:rotate(-90deg);"></span>
            </div>
            <div id="ce-manifest-sec" class="sui-accordion">
                <div class="sui-accordion-inner" style="padding:12px 4px 0 4px;">
                    <div style="font-size:10px; font-weight:800; color:var(--ai-accent); margin-bottom:6px; text-transform:uppercase;">Context Category Order</div>
                    <div id="ce-layout-order" style="display:flex; flex-direction:column; gap:6px; margin-bottom:16px;"></div>

                    <div style="font-size:10px; font-weight:800; color:var(--primary); margin-bottom:6px; text-transform:uppercase;">Foundation Core (File Order)</div>
                    <div id="ce-list-foundation" style="font-family:monospace; font-size:10px; color:var(--text-secondary); line-height:1.4; margin-bottom:12px; background:var(--card-bg); padding:8px; border-radius:8px; border:1px solid var(--border-color);"></div>
                    
                    <div style="font-size:10px; font-weight:800; color:var(--ai-accent); margin-bottom:6px; text-transform:uppercase;">Project Extras (Plan Scope)</div>
                    <div id="ce-list-project" style="font-family:monospace; font-size:10px; color:var(--text-secondary); line-height:1.4; margin-bottom:12px; background:var(--card-bg); padding:8px; border-radius:8px; border:1px solid var(--border-color);"></div>
                    
                    <div style="font-size:10px; font-weight:800; color:#5856D6; margin-bottom:6px; text-transform:uppercase;">Manual Extras</div>
                    <div id="ce-list-manual" style="font-family:monospace; font-size:10px; color:var(--text-secondary); line-height:1.4; background:var(--card-bg); padding:8px; border-radius:8px; border:1px solid var(--border-color);"></div>
                    
                    <button onclick="ceOpenManualExtrasStudio()" class="text-btn" style="width:100%; margin-top:10px; background:var(--btn-bg); color:var(--text-primary); border-radius:8px; padding:8px; font-size:10px; font-weight:800; text-transform:uppercase; border:1px solid var(--border-color);">
                        Manage Manual Extras
                    </button>
                </div>
            </div>
        </div>


    </div>

    <div class="setting-item vertical">
        <label class="setting-label">JIT Context Downloads</label>
        <div class="setting-desc">Tailored context files for AI conversations. Triggers a fresh system scan on every click.</div>
        
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:12px;">
            <button onclick="ceDownload('foundation')" class="text-btn" style="background:var(--primary); color:var(--primary-text); border-radius:12px; padding:12px; font-weight:700; box-shadow: 0 4px 12px color-mix(in srgb, var(--primary), transparent 80%);">
                Export Foundation
            </button>
            <button onclick="ceDownload('foundation_skeleton')" class="text-btn" style="background:var(--btn-bg); color:var(--primary); border:1px solid var(--primary); border-radius:12px; padding:12px; font-weight:700;">
                Export Foundation (Skeleton)
            </button>
            <button onclick="ceDownload('project')" class="text-btn" style="background:var(--ai-accent); color:var(--primary-text); border-radius:12px; padding:12px; font-weight:700; box-shadow: 0 4px 12px color-mix(in srgb, var(--ai-accent), transparent 80%);">
                Export Project
            </button>
            <button onclick="ceDownload('project_skeleton')" class="text-btn" style="background:var(--btn-bg); color:var(--ai-accent); border:1px solid var(--ai-accent); border-radius:12px; padding:12px; font-weight:700;">
                Export Project (Skeleton)
            </button>
        </div>
        <button onclick="bkDownloadCode(this)" class="text-btn" style="width:100%; margin-top:10px; background:var(--btn-bg); color:var(--text-primary); border:1px solid var(--border-color); border-radius:12px; padding:12px; font-weight:600;">
            Full System Export (Legacy)
        </button>
        <button onclick="ceRebuildDependencyMaps()" class="text-btn" style="width:100%; margin-top:8px; background:var(--btn-bg); color:var(--text-secondary); border:1px dashed var(--border-color); border-radius:12px; padding:10px; font-size:11px; font-weight:700;">
            🔄 Rebuild Dependency Maps
        </button>
    </div>
    </div> <!-- /ce-gui-root -->
</div> <!-- /ce-tray-anchor -->
HTML;

$plugin_js .= <<<'JS'
// --- CONTEXT EXPORTER JS ---

window.addEventListener("load", () => window.ceRefreshStats());

window.ceOpenStudio = function() {
    const root = document.getElementById('ce-gui-root');
    const anchor = document.getElementById('ce-tray-anchor');
    if(!root || !anchor) return;

    window.sui.openStudio({
        id: 'ce-studio',
        title: 'Context Exporter',
        content: '', 
        onSetup: (contentBox) => {
            contentBox.appendChild(root);
            contentBox.scrollTop = 0;
            root.style.paddingBottom = "40px";
            if (window.suiHydrateIcons) window.suiHydrateIcons(root);
        },
        onClose: () => {
            anchor.appendChild(root);
            root.style.paddingBottom = "0";
        }
    });
};

window.ceRefreshStats = async function() {
    try {
        const data = await window.sui.api('ce_get_stats', {}, { toast: false });
        
        if (data) {
            const s = data.stats;
            const exclusions = s.exclusions || [];
            
            // Calculate Active Sizes
            let activeFSize = 0;
            s.f_list.forEach(f => { if(!exclusions.includes(f.path)) activeFSize += parseFloat(f.size) * 1024; });
            
            let activePSize = activeFSize;
            s.p_list.forEach(f => { if(!exclusions.includes(f.path)) activePSize += parseFloat(f.size) * 1024; });
            s.m_list.forEach(f => { if(!exclusions.includes(f.path)) activePSize += parseFloat(f.size) * 1024; });

            document.getElementById('ce-stat-fsize').innerText = (activeFSize / 1024).toFixed(1) + ' KB';
            document.getElementById('ce-stat-psize').innerText = (activePSize / 1024).toFixed(1) + ' KB';
            
            const extraCount = s.p_list.filter(p => !exclusions.includes(p.path)).length + s.m_list.filter(m => !exclusions.includes(m.path)).length;
            document.getElementById('ce-stat-pcount').innerText = extraCount;
            
            const totalTokens = Math.round((activePSize / 1024) * 250);
            document.getElementById('ce-token-est').innerText = `Est. Project Tokens: ~${totalTokens.toLocaleString()}`;

            // Alphabetical Sort (Projects and Manual only, Foundation has custom order)
            const sortFn = (a, b) => a.path.localeCompare(b.path);
            s.p_list.sort(sortFn);
            s.m_list.sort(sortFn);

            // Populate Lists with Toggles
            const renderItem = (f) => {
                const isExcluded = exclusions.includes(f.path);
                return `
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed rgba(0,0,0,0.05); padding-bottom:5px; margin-bottom:5px; opacity:${isExcluded ? 0.4 : 1};">
                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-right:8px; font-size:10px;" title="${f.path}">• ${f.path}</span>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:8px; font-weight:700; opacity:0.5; flex-shrink:0;">${f.size}</span>
                        <input type="checkbox" ${!isExcluded ? 'checked' : ''} onchange="ceToggleFile('${f.path}', this.checked)" style="width:14px; height:14px; cursor:pointer;">
                    </div>
                </div>`;
            };

            const fList = document.getElementById('ce-list-foundation');
            const pList = document.getElementById('ce-list-project');
            const mList = document.getElementById('ce-list-manual');
            
            // Render Foundation with Reorder Buttons
            if(fList) fList.innerHTML = s.f_list.map((f, i) => {
                const isExcluded = exclusions.includes(f.path);
                return `
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed rgba(0,0,0,0.05); padding-bottom:5px; margin-bottom:5px; opacity:${isExcluded ? 0.4 : 1};">
                    <div style="display:flex; align-items:center; gap:6px; min-width:0; flex:1;">
                        <div style="display:flex; flex-direction:column; gap:2px;">
                            <button onclick="ceReorderFoundation('${f.path}', 'up')" style="background:none; border:none; color:var(--text-secondary); cursor:pointer; padding:0; display:flex;"><span data-sui-icon="chevron" data-sui-size="8" style="transform:rotate(180deg);"></span></button>
                            <button onclick="ceReorderFoundation('${f.path}', 'down')" style="background:none; border:none; color:var(--text-secondary); cursor:pointer; padding:0; display:flex;"><span data-sui-icon="chevron" data-sui-size="8"></span></button>
                        </div>
                        <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:10px;" title="${f.path}">${f.path}</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:8px; font-weight:700; opacity:0.5; flex-shrink:0;">${f.size}</span>
                        <input type="checkbox" ${!isExcluded ? 'checked' : ''} onchange="ceToggleFile('${f.path}', this.checked)" style="width:14px; height:14px; cursor:pointer;">
                    </div>
                </div>`;
            }).join('');

            // Render Category Order
            const layoutOrder = document.getElementById('ce-layout-order');
            if (layoutOrder && s.project_groups) {
                const categories = s.layout_categories || ['foundation', 'project', 'manual'];
                layoutOrder.innerHTML = categories.map((cat, i) => `
                    <div style="display:flex; justify-content:space-between; align-items:center; background:var(--btn-bg); padding:8px 12px; border-radius:10px; border:1px solid var(--border-color);">
                        <span style="font-size:11px; font-weight:800; color:var(--text-primary); text-transform:uppercase;">${cat}</span>
                        <div style="display:flex; gap:8px;">
                            <button onclick="ceReorderCategory('${cat}', 'up')" style="background:var(--card-bg); border:1px solid var(--border-color); width:24px; height:24px; border-radius:6px; color:var(--text-secondary); cursor:pointer; display:flex; align-items:center; justify-content:center;"><span data-sui-icon="chevron" data-sui-size="10" style="transform:rotate(180deg);"></span></button>
                            <button onclick="ceReorderCategory('${cat}', 'down')" style="background:var(--card-bg); border:1px solid var(--border-color); width:24px; height:24px; border-radius:6px; color:var(--text-secondary); cursor:pointer; display:flex; align-items:center; justify-content:center;"><span data-sui-icon="chevron" data-sui-size="10"></span></button>
                        </div>
                    </div>
                `).join('');
            }
            if(pList) pList.innerHTML = s.p_list.length > 0 ? s.p_list.map(renderItem).join('') : '<div style="opacity:0.5; font-size:9px;">No active project scope</div>';
            if(mList) mList.innerHTML = s.m_list.length > 0 ? s.m_list.map(renderItem).join('') : '<div style="opacity:0.5; font-size:9px;">No manual extras</div>';
            return s;
        }
    } catch(e) {}
}

window.ceDownload = async function(tier) {
    const showToast = (msg, color = "var(--success-bg)") => {
        const t = document.getElementById("toast");
        if (t) {
            t.innerText = msg;
            t.style.background = color;
            t.classList.add("show");
            setTimeout(() => t.classList.remove("show"), 3000);
        }
    };

    showToast("Scanning for sensitive data...");

    try {
        const pre = await window.sui.api('ce_preflight', { tier: tier }, { toast: false });
        if (pre && pre.risk_count > 0) {
            // Trigger Security Notification
            showToast(`PRIVACY ALERT: ${pre.risk_count} private files detected!`, "var(--danger)");
            
            const riskList = pre.risks.map(f => `• ${f}`).join('\n');
            window.openConfirm(
                "Privacy Shield Warning", 
                `The following sensitive files are included in this export:\n\n${riskList}\n\nAre you sure you want to proceed?`,
                () => ceExecuteDownload(tier, true),
                true, "Proceed Anyway", "Cancel Export"
            );
            return;
        }
    } catch(e) { console.error("Pre-flight failed", e); }

    ceExecuteDownload(tier);
};

window.ceExecuteDownload = function(tier, confirmed = false) {
    const params = {
        plugin_action: 'ce_download',
        tier: tier,
        t: Date.now()
    };
    if (confirmed) params.confirm_privacy = '1';

    const queryString = Object.keys(params)
        .map(key => encodeURIComponent(key) + '=' + encodeURIComponent(params[key]))
        .join('&');
    
    window.location.href = 'index.php?' + queryString;
};

window.ceRebuildDependencyMaps = async function() {
    try {
        const res = await window.sui.api('ce_rebuild_dependency_maps', {}, { toast: "Rebuilding dependency maps..." });
        if (res && res.counts) {
            window.sui.toast(`Maps rebuilt! Core: ${res.counts.sys_count} files, Apps: ${res.counts.apps_count} files`);
            if (typeof window.ceRefreshStats === 'function') {
                window.ceRefreshStats();
            }
        }
    } catch(e) {
        console.error("Rebuild maps failed", e);
    }
};

window.ceOpenManualExtrasStudio = function() {
    // Initial empty state to allow instant opening
    const current = [];
    window.ceCurrentManualRef = current; // Expose for restore synchronization
    const foundationExclusions = new Set();
    const extraExclusions = new Set();
    const fileSizes = {}; 
    const categories = [];
    let fList = [];
    let pList = [];
    let mList = [];
    let projectGroups = [];
    let fsApi = null; // Capture instance for cross-handler access
    let foundationBytes = 0;
    let projectBytes = 0;
    let foundationSkelBytes = 0;
    let projectSkelBytes = 0;

    const getFileEstSkeletonKb = (path, sizeKb) => {
        if (!path) return 0;
        const ext = path.split('.').pop().toLowerCase();
        const kb = parseFloat(sizeKb) || 0;
        if (ext === 'md' || ext === 'markdown') return kb; // Markdown files exempt (100%)
        return kb * 0.25; // Code / Data files condensed (~25%)
    };

    const rebuildSizeMap = (stats, api = null) => {const targetApi = api || fsApi;
        if (!stats) return;
        
        // Mutate existing arrays to preserve references held by SharedUI
        fList.length = 0; if (stats.f_list) fList.push(...stats.f_list);
        pList.length = 0; if (stats.p_list) pList.push(...stats.p_list);
        mList.length = 0; if (stats.m_list) mList.push(...stats.m_list);
        projectGroups.length = 0; if (stats.project_groups) projectGroups.push(...stats.project_groups);

        [...fList, ...pList, ...mList].forEach(f => {
            fileSizes[f.path] = f.size;
        });

        if (targetApi) {
            targetApi.renderActive();
        }
    };

    const updateLabels = (currentSelection, fExcl, eExcl) => {
        if (fList.length === 0) return; // Wait for data
        foundationBytes = 0;
        projectBytes = 0;
        foundationSkelBytes = 0;
        projectSkelBytes = 0;

        // 1. Foundation Size (f_list minus foundation exclusions)
        fList.forEach(f => {
            if (!fExcl.has(f.path)) {
                const kb = parseFloat(fileSizes[f.path]) || 0;
                foundationBytes += kb;
                foundationSkelBytes += getFileEstSkeletonKb(f.path, kb);
            }
        });

        // 2. Project Size (Foundation + Project Planner Scoped Files + Manual Selection)
        projectBytes = foundationBytes;
        projectSkelBytes = foundationSkelBytes;
        
        // Add Project Planner Files (p_list)
        pList.forEach(f => {
            if (!eExcl.has(f.path)) {
                const kb = parseFloat(fileSizes[f.path]) || 0;
                projectBytes += kb;
                projectSkelBytes += getFileEstSkeletonKb(f.path, kb);
            }
        });

        // Add Manual Selection (currentSelection)
        currentSelection.forEach(path => {
            const inFoundation = fList.some(f => f.path === path);
            const inProject = pList.some(f => f.path === path);
            if (!inFoundation && !inProject && !eExcl.has(path)) {
                const kb = parseFloat(fileSizes[path]) || 0;
                projectBytes += kb;
                projectSkelBytes += getFileEstSkeletonKb(path, kb);
            }
        });

        const fBtn = document.querySelector('#sui-fs-act-ce-manual-extras-0 .sui-fs-btn-label');
        const pBtn = document.querySelector('#sui-fs-act-ce-manual-extras-1 .sui-fs-btn-label');
        
        if (fBtn) fBtn.innerText = `Foundation (${foundationBytes.toFixed(1)} KB)`;
        if (pBtn) pBtn.innerText = `+Manual (${projectBytes.toFixed(1)} KB)`;
    };

    window.sui.openFileStudio({
        id: 'ce-manual-extras',
        title: 'Manual Context Extras',
        selection: current,
        foundationExclusions: foundationExclusions,
        extraExclusions: extraExclusions,
        categories: categories,
        foundation: fList,
        projects: projectGroups,
        fileSizes: fileSizes,
        autoSave: true,
        onSelectionChange: (sel, excl) => updateLabels(sel, foundationExclusions, extraExclusions),
        onCategoryReorder: async (cat, direction, refreshUI) => {
            await window.sui.api('ce_reorder_category', { category: cat, direction: direction }, { toast: false });
            const statsRes = await window.ceRefreshStats();
            if (statsRes && statsRes.layout_categories) {
                categories.length = 0;
                categories.push(...statsRes.layout_categories);
                refreshUI();
            }
        },
        onReorder: async (path, direction, refreshUI, targetIndex = null) => {
            const params = { file: path };
            if (targetIndex !== null) params.target_index = targetIndex;
            else params.direction = direction;

            await window.sui.api('ce_reorder_foundation', params, { toast: false });
            const statsRes = await window.sui.api('ce_get_stats', {}, { toast: false });
            if (statsRes) {
                rebuildSizeMap(statsRes.stats);
                refreshUI();
                window.ceRefreshStats(); // Sync the background tray UI
            }
        },
        rebuildSizeMap: (stats) => rebuildSizeMap(stats),
        updateLabels: () => updateLabels(current, exclusions),
        onHistory: (fsApi) => window.ceOpenSnapshotHistoryStudio(fsApi),
        onRefresh: async (fsApi) => {
            const apps = new Set();
            fsApi.selection.forEach(f => {
                if (f.startsWith('apps/')) {
                    const parts = f.split('/');
                    if (parts.length > 1) apps.add(parts[1]);
                }
            });
            if (apps.size === 0) {
                window.sui.toast("No apps in selection to refresh");
                return;
            }
            for (const app of apps) {
                const res = await window.sui.api('ce_get_app_files', { folder: app }, { toast: `Refreshing ${app}...` });
                if (res && res.files) {
                    res.files.forEach(f => {
                        fsApi.selection.add(f.path);
                        if (fsApi.fileSizes) fsApi.fileSizes[f.path] = f.size;
                    });
                }
            }
            fsApi.renderActive();
            fsApi.renderNavigator();
            fsApi.triggerSave();
        },
        headerActions: [
            { 
                label: 'Foundation', 
                icon: 'export', 
                onclick: () => {
                    if (typeof window.openPicker === 'function') {
                        const fullKb = foundationBytes.toFixed(1);
                        const skelKb = foundationSkelBytes.toFixed(1);
                        const options = [
                            { label: `📤 Export Foundation (Full — ${fullKb} KB)`, value: "foundation" },
                            { label: `⚡ Export Foundation (Skeleton — ~${skelKb} KB)`, value: "foundation_skeleton" },
                            { label: `🔄 Rebuild Dependency Maps`, value: "rebuild_map" }
                        ];
                        window.openPicker("Foundation Export Mode", options, null, (val) => {
                            if (val === "rebuild_map") {
                                window.ceRebuildDependencyMaps();
                            } else if (val) {
                                window.ceDownload(val);
                            }
                        });
                    } else {
                        window.ceDownload('foundation');
                    }
                }
            },
            { 
                label: '+Manual', 
                icon: 'export', 
                color: 'var(--ai-accent)', 
                onclick: () => {
                    if (typeof window.openPicker === 'function') {
                        const fullKb = projectBytes.toFixed(1);
                        const skelKb = projectSkelBytes.toFixed(1);
                        const options = [
                            { label: `🎯 Export +Manual (Full — ${fullKb} KB)`, value: "project" },
                            { label: `⚡ Export +Manual (Skeleton — ~${skelKb} KB)`, value: "project_skeleton" },
                            { label: `🔄 Rebuild Dependency Maps`, value: "rebuild_map" }
                        ];
                        window.openPicker("+Manual Export Mode", options, null, (val) => {
                            if (val === "rebuild_map") {
                                window.ceRebuildDependencyMaps();
                            } else if (val) {
                                window.ceDownload(val);
                            }
                        });
                    } else {
                        window.ceDownload('project');
                    }
                }
            },
            { label: 'Apps', icon: 'grid', onclick: (fsApi) => window.ceOpenAppsStudio(fsApi) },
            { label: 'Groups', icon: 'layers', onclick: (fsApi) => window.ceOpenGroupsStudio(fsApi) }
        ],
        onToggle: async (path, enabled, refreshUI, type = 'foundation') => {
            // Optimistic UI Update
            const targetSet = (type === 'foundation') ? foundationExclusions : extraExclusions;
            if (enabled) targetSet.delete(path); else targetSet.add(path);
            updateLabels(current, foundationExclusions, extraExclusions);
            refreshUI();
            
            // Background Sync
            ceToggleFile(path, enabled, true, type).then(() => {
                window.ceRefreshStats(); 
            });
        },
        onToggleBatch: async (paths, enabled, refreshUI, type = 'extra') => {
            // Optimistic UI Update
            const targetSet = (type === 'foundation') ? foundationExclusions : extraExclusions;
            paths.forEach(p => { if (enabled) targetSet.delete(p); else targetSet.add(p); });
            updateLabels(current, foundationExclusions, extraExclusions);
            refreshUI();

            // Background Sync
            ceToggleFile(JSON.stringify(paths), enabled, true, type).then(() => {
                window.ceRefreshStats().then(newStats => {
                    if (newStats) rebuildSizeMap(newStats);
                });
            });
        },
        onSave: async (files) => {
            await window.sui.api('ce_save_manual', { manual: JSON.stringify(files) }, { toast: false });
            current.length = 0; files.forEach(f => current.push(f));
            const newStats = await window.ceRefreshStats();
            if (newStats) rebuildSizeMap(newStats, fsApi);
            updateLabels(new Set(current), foundationExclusions, extraExclusions);
        },
        onSetup: (content, overlay, apiInstance) => {
            fsApi = apiInstance; // Capture for other handlers
            // ASYNC DATA FETCH: Populate the studio after it has opened
            (async () => {
                try {
                    const [manualRes, exclRes, statsRes] = await Promise.all([
                        window.sui.api('ce_get_manual', {}, { toast: false }),
                        window.sui.api('ce_get_exclusions', {}, { toast: false }),
                        window.sui.api('ce_get_stats', {}, { toast: false })
                    ]);

                    if (manualRes) {
                        fsApi.selection.clear();
                        manualRes.manual.forEach(f => {
                            current.push(f);
                            fsApi.selection.add(f);
                        });
                    }
                    if (statsRes && statsRes.stats.layout_categories) {
                        categories.length = 0;
                        categories.push(...statsRes.stats.layout_categories);
                    }
                    if (exclRes) {
                        // This is a legacy call, we now get exclusions from statsRes
                    }
                    if (statsRes) {
                        rebuildSizeMap(statsRes.stats, fsApi);
                        
                        foundationExclusions.clear();
                        (statsRes.stats.foundation_exclusions || []).forEach(e => foundationExclusions.add(e));
                        
                        extraExclusions.clear();
                        (statsRes.stats.extra_exclusions || []).forEach(e => extraExclusions.add(e));
                        
                        if (fsApi.foundationExclusions) {
                            fsApi.foundationExclusions.clear();
                            foundationExclusions.forEach(e => fsApi.foundationExclusions.add(e));
                        }
                        if (fsApi.extraExclusions) {
                            fsApi.extraExclusions.clear();
                            extraExclusions.forEach(e => fsApi.extraExclusions.add(e));
                        }
                    }

                    // Explicitly refresh the UI now that data is ready
                    fsApi.renderActive();
                    fsApi.renderNavigator();
                    updateLabels(new Set(current), foundationExclusions, extraExclusions);
                } catch(e) { console.error("Async load failed", e); }
            })();
        }
    });
};

window.ceOpenSnapshotHistoryStudio = async function(fsApi) {
    const refresh = async (contentBox) => {
        contentBox.innerHTML = `<div style="padding:40px; text-align:center;">${window.suiSpinner(30)}</div>`;
        const data = await window.sui.api('ce_get_snapshots', {}, { toast: false });
        const snapshots = data.snapshots || [];

        if (snapshots.length === 0) {
            contentBox.innerHTML = window.suiEmptyState('🕒', 'No context snapshots found in database.');
            return;
        }

        let html = `<div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:16px;">Export History (Last 20)</div>`;
        const foundationList = data.foundation || [];

        snapshots.forEach(s => {
            const date = new Date(s.timestamp + ' UTC').toLocaleString();
            const manualCount = s.data.manual?.length || 0;
            const exclCount = s.data.exclusions?.length || 0;
            const sid = `ce-snap-${s.id}`;

            // --- CATEGORIZE MANUAL FILES ---
            const manualGroups = { 'Foundation Core': [], 'System / Plugins': [] };
            (s.data.manual || []).forEach(f => {
                if (foundationList.includes(f)) {
                    manualGroups['Foundation Core'].push(f);
                } else if (f.startsWith('apps/')) {
                    const appName = f.split('/')[1];
                    if (!manualGroups['App: ' + appName]) manualGroups['App: ' + appName] = [];
                    manualGroups['App: ' + appName].push(f);
                } else {
                    manualGroups['System / Plugins'].push(f);
                }
            });

            let manualHtml = `<div style="font-weight:800; color:var(--primary); margin-bottom:6px; text-transform:uppercase; border-bottom:1px solid rgba(0,0,0,0.05); padding-bottom:2px;">Manual Files (${manualCount})</div>`;
            Object.keys(manualGroups).sort().forEach(group => {
                if (manualGroups[group].length === 0) return;
                manualHtml += `<div style="font-weight:700; color:var(--text-primary); margin:6px 0 2px 0; font-size:9px; opacity:0.8;">[${group}]</div>`;
                manualHtml += manualGroups[group].map(f => `<div style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; padding-left:8px;">• ${f.split('/').pop()} <span style="opacity:0.4; font-size:8px;">(${f})</span></div>`).join('');
            });

            const previewHtml = `
                <div style="padding:12px; display:flex; flex-direction:column; gap:12px;">
                    <div style="background:rgba(0,0,0,0.02); padding:10px; border-radius:10px; font-family:monospace; font-size:10px; color:var(--text-secondary); max-height:250px; overflow-y:auto; border:1px solid var(--border-color);">
                        ${manualHtml}
                    </div>
                    <button class="ce-snap-restore btn-primary" data-id="${s.id}" style="width:100%; padding:12px; font-size:13px;">
                        Restore This Snapshot
                    </button>
                </div>
            `;

            html += `
                <div class="card" style="margin-bottom:12px; border:1px solid var(--border-color);">
                    <div class="card-content" style="padding:0;">
                        <div onclick="suiToggle('${sid}')" style="cursor:pointer; padding:16px; display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <div style="font-weight:800; font-size:14px; color:var(--text-primary);">${date}</div>
                                <div style="font-size:11px; color:var(--text-secondary); font-weight:600;">${manualCount} Files · ${exclCount} Exclusions</div>
                            </div>
                            <span data-sui-icon="chevron" data-sui-arrow="${sid}" data-sui-size="14" style="transition:transform 0.3s; transform:rotate(-90deg);"></span>
                        </div>
                        <div id="${sid}" class="sui-accordion" style="display: none;">
                            <div class="sui-accordion-inner">${previewHtml}</div>
                        </div>
                    </div>
                </div>
            `;
        });

        contentBox.innerHTML = html;
        window.suiHydrateIcons(contentBox);

        contentBox.querySelectorAll('.ce-snap-restore').forEach(btn => {
            btn.onclick = () => {
                const id = btn.dataset.id;
                window.openConfirm("Restore Snapshot", "This will overwrite your current file selection and exclusion states. Proceed?", async () => {
                    await ceRestoreSnapshot(id, fsApi);
                    window.sui.closeStudio('ce-snapshot-history');
                });
            };
        });
    };

    window.sui.openStudio({
        id: 'ce-snapshot-history',
        title: 'Context History',
        onSetup: (contentBox) => refresh(contentBox)
    });
};

async function ceRestoreSnapshot(id, fsApi) {
    const res = await window.sui.api('ce_load_snapshot', { id: id }, { toast: "Restoring snapshot..." });
    if (res && res.snapshot) {
        const s = res.snapshot;
        
        // 1. Clean Overwrite Exclusions on Server
        await window.sui.api('ce_restore_exclusions', { 
            foundation_paths: JSON.stringify(s.foundation_exclusions || s.exclusions || []),
            extra_paths: JSON.stringify(s.extra_exclusions || [])
        }, { toast: false });
        
        // 2. Overwrite Manual Extras on Server
        await window.sui.api('ce_save_manual', { manual: JSON.stringify(s.manual || []) }, { toast: false });
        
        // 3. Sync Local State (Selection Badges)
        fsApi.selection.clear();
        (s.manual || []).forEach(f => fsApi.selection.add(f));

        // 4. Sync Local State (Exclusions Sets)
        if (fsApi.foundationExclusions) {
            fsApi.foundationExclusions.clear();
            (s.foundation_exclusions || s.exclusions || []).forEach(e => fsApi.foundationExclusions.add(e));
        }
        if (fsApi.extraExclusions) {
            fsApi.extraExclusions.clear();
            (s.extra_exclusions || []).forEach(e => fsApi.extraExclusions.add(e));
        }

        // 5. Sync Local State (Internal 'current' array for labels)
        // We reach into the parent scope if we're in the same file context
        if (typeof ceCurrentManualRef !== 'undefined') {
            ceCurrentManualRef.length = 0;
            (s.manual || []).forEach(f => ceCurrentManualRef.push(f));
        }
        
        // 6. Refresh UI and Stats
        const newStats = await window.ceRefreshStats();
        if (fsApi.rebuildSizeMap) fsApi.rebuildSizeMap(newStats);
        if (fsApi.updateLabels) fsApi.updateLabels();
        
        // Force re-render of badges and navigator
        fsApi.renderActive();
        fsApi.renderNavigator();
        
        window.sui.toast("Snapshot Restored");
    }
}

window.ceOpenGroupsStudio = async function(fsApi) {
    const refresh = async (contentBox) => {
        const data = await window.sui.api('ce_get_groups', {}, { toast: false });
        const groups = data.groups || {};
        
        let html = `
            <div style="margin-bottom:20px;">
                <button id="ce-group-save-new" class="btn-primary" style="width:100%; gap:8px;">
                    <span data-sui-icon="plus" data-sui-size="16" data-sui-stroke="3"></span>
                    Save Current Selection as Group
                </button>
            </div>
            <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">Saved Groups</div>
        `;

        if (Object.keys(groups).length === 0) {
            html += window.suiEmptyState('📂', 'No saved groups yet');
        } else {
            Object.keys(groups).sort().forEach(name => {
                const files = groups[name];
                const safeId = 'ce-grp-' + name.replace(/[^a-z0-9]/gi, '-');
                html += `
                    <div class="card" style="margin-bottom:8px; border:1px solid var(--border-color); cursor:pointer;" 
                         onpointerdown="ceStartGroupLongPress(event, '${name.replace(/'/g, "\\'")}')" 
                         onpointerup="ceEndGroupLongPress()" 
                         onpointerleave="ceEndGroupLongPress()" 
                         oncontextmenu="event.preventDefault()">
                        <div class="card-content" style="padding:12px 16px;">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <div onclick="suiToggle('${safeId}')" style="flex:1; min-width:0; display:flex; align-items:center; gap:8px;">
                                    <div style="min-width:0;">
                                        <div style="font-weight:800; font-size:14px; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${name}</div>
                                        <div style="font-size:10px; color:var(--text-secondary); font-weight:600;">${files.length} Files</div>
                                    </div>
                                    <span data-sui-icon="chevron" data-sui-arrow="${safeId}" data-sui-size="10" style="transition:transform 0.3s; transform:rotate(-90deg); opacity:0.5;"></span>
                                </div>
                                <div style="display:flex; gap:4px; flex-shrink:0;">
                                    <button class="ce-group-load" data-name="${name}" title="Load Group" 
                                            onpointerdown="event.stopPropagation()"
                                            style="background:var(--primary); border:none; width:32px; height:32px; border-radius:8px; color:var(--primary-text); cursor:pointer; display:flex; align-items:center; justify-content:center;">
                                        <span data-sui-icon="check" data-sui-size="16" data-sui-stroke="3"></span>
                                    </button>
                                </div>
                            </div>
                            <div id="${safeId}" class="sui-accordion" style="display: none;">
                                <div class="sui-accordion-inner" style="padding-top:10px;">
                                    <div style="background:rgba(0,0,0,0.02); padding:10px; border-radius:10px; font-family:monospace; font-size:10px; color:var(--text-secondary); max-height:120px; overflow-y:auto; border:1px solid var(--border-color);">
                                        ${files.map(f => f.path).join('<br>')}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            // --- LONG PRESS LOGIC ---
            let groupLongPressTimer = null;
            window.ceStartGroupLongPress = (e, name) => {
                if (e.pointerType === 'mouse' && e.button !== 0) return;
                groupLongPressTimer = setTimeout(() => {
                    window.sui.haptic('medium');
                    const options = [
                        { 
                            label: `<div style="display:flex; align-items:center; gap:12px;">${window.suiIcon('undo', 'var(--primary)', 18)} Overwrite with Selection</div>`, 
                            value: "update" 
                        },
                        { 
                            label: `<div style="display:flex; align-items:center; gap:12px;">${window.suiIcon('edit', 'var(--text-secondary)', 18)} Rename Group</div>`, 
                            value: "rename" 
                        },
                        { 
                            label: `<div style="display:flex; align-items:center; gap:12px;">${window.suiIcon('trash', 'var(--danger)', 18)} Delete Group</div>`, 
                            value: "delete" 
                        }
                    ];
                    window.openPicker(`Group: ${name}`, options, null, (val) => {
                        if (val === "update") {
                            window.openConfirm("Update Group", `Overwrite "${name}" with your current selection (${fsApi.selection.size} files)?`, async () => {
                                await window.sui.api('ce_save_group', { name: name, files: Array.from(fsApi.selection) });
                                refresh(contentBox);
                            });
                        }
                        if (val === "rename") {
                            window.openInput("Rename Group", "New name...", name, async (newName) => {
                                if (!newName || newName === name) return;
                                await window.sui.api('ce_rename_group', { old_name: name, new_name: newName });
                                refresh(contentBox);
                            });
                        }
                        if (val === "delete") {
                            window.openConfirm("Delete Group", `Delete "${name}"?`, async () => {
                                await window.sui.api('ce_delete_group', { name: name });
                                refresh(contentBox);
                            }, true);
                        }
                    });
                }, 600);
            };
            window.ceEndGroupLongPress = () => clearTimeout(groupLongPressTimer);
        }
        
        contentBox.innerHTML = html;
        window.suiHydrateIcons(contentBox);

        // Bind Actions
        contentBox.querySelector('#ce-group-save-new').onclick = () => {
            window.openInput("Group Name", "e.g. Auth System", "", async (name) => {
                if (!name) return;
                await window.sui.api('ce_save_group', { name: name, files: Array.from(fsApi.selection) });
                refresh(contentBox);
            });
        };

        contentBox.querySelectorAll('.ce-group-load').forEach(btn => {
            btn.onclick = () => {
                const name = btn.dataset.name;
                const options = [
                    { 
                        label: `<div style="display:flex; align-items:center; gap:12px;">${window.suiIcon('plus', 'var(--primary)', 18)} Add to Selection</div>`, 
                        value: "add" 
                    },
                    { 
                        label: `<div style="display:flex; align-items:center; gap:12px;">${window.suiIcon('rotate-ccw', 'var(--ai-accent)', 18)} Replace Selection</div>`, 
                        value: "replace" 
                    }
                ];

                window.openPicker(`Load Group: ${name}`, options, null, (mode) => {
                    // Close immediately for snappy UI response
                    window.sui.closeStudio('ce-groups');

                    if (mode === 'replace') fsApi.selection.clear();
                    groups[name].forEach(f => {
                        fsApi.selection.add(f.path);
                        if (fsApi.fileSizes) fsApi.fileSizes[f.path] = f.size;
                    });
                    
                    fsApi.renderActive();
                    fsApi.renderNavigator();
                    fsApi.triggerSave();
                    
                    window.sui.toast(`${mode === 'replace' ? 'Replaced with' : 'Added'} ${groups[name].length} files`);
                });
            };
        });

        contentBox.querySelectorAll('.ce-group-update').forEach(btn => {
            btn.onclick = () => {
                const name = btn.dataset.name;
                window.openConfirm("Update Group", `Overwrite "${name}" with your current selection (${fsApi.selection.size} files)?`, async () => {
                    await window.sui.api('ce_save_group', { name: name, files: Array.from(fsApi.selection) });
                    refresh(contentBox);
                });
            };
        });

        contentBox.querySelectorAll('.ce-group-rename').forEach(btn => {
            btn.onclick = () => {
                const name = btn.dataset.name;
                window.openInput("Rename Group", "New name...", name, async (newName) => {
                    if (!newName || newName === name) return;
                    await window.sui.api('ce_rename_group', { old_name: name, new_name: newName });
                    refresh(contentBox);
                });
            };
        });

        contentBox.querySelectorAll('.ce-group-delete').forEach(btn => {
            btn.onclick = () => {
                const name = btn.dataset.name;
                window.openConfirm("Delete Group", `Delete "${name}"?`, async () => {
                    await window.sui.api('ce_delete_group', { name: name });
                    refresh(contentBox);
                }, true);
            };
        });
    };

    window.sui.openStudio({
        id: 'ce-groups',
        title: 'Context Groups',
        onSetup: (contentBox) => refresh(contentBox)
    });
};

window.ceOpenAppsStudio = async function(fsApi) {
    const refresh = async (contentBox) => {
        contentBox.innerHTML = `
            <div style="display: flex; flex-direction: column; height: 100%;">
                <div class="tab-menu" id="ce-apps-tab-menu" style="display: flex; gap: 4px; background: var(--bg-color); padding: 4px; border-radius: 8px; margin-bottom: 16px; border: 1px solid var(--border-color); flex-shrink:0;">
                    <button type="button" class="tab-btn" id="ce-tab-appmaker" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 600; transition: all 0.2s;">App Maker Apps</button>
                    <button type="button" class="tab-btn" id="ce-tab-apkstudio" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 600; transition: all 0.2s;">APK Studio Projects</button>
                </div>
                <div id="ce-panel-appmaker" style="display: none;"></div>
                <div id="ce-panel-apkstudio" style="display: none;"></div>
            </div>
        `;
        
        const panelAppMaker = contentBox.querySelector('#ce-panel-appmaker');
        const panelApkStudio = contentBox.querySelector('#ce-panel-apkstudio');
        const btnAppMaker = contentBox.querySelector('#ce-tab-appmaker');
        const btnApkStudio = contentBox.querySelector('#ce-tab-apkstudio');
        
        const switchTab = (activeTab) => {
            if (activeTab === 'appmaker') {
                btnAppMaker.style.background = 'var(--primary)';
                btnAppMaker.style.color = 'var(--primary-text)';
                btnApkStudio.style.background = 'none';
                btnApkStudio.style.color = 'var(--text-secondary)';
                panelAppMaker.style.display = 'block';
                panelApkStudio.style.display = 'none';
                localStorage.setItem('ce_apps_active_tab', 'appmaker');
            } else {
                btnApkStudio.style.background = 'var(--primary)';
                btnApkStudio.style.color = 'var(--primary-text)';
                btnAppMaker.style.background = 'none';
                btnAppMaker.style.color = 'var(--text-secondary)';
                panelAppMaker.style.display = 'none';
                panelApkStudio.style.display = 'block';
                localStorage.setItem('ce_apps_active_tab', 'apkstudio');
            }
        };
        
        btnAppMaker.onclick = () => switchTab('appmaker');
        btnApkStudio.onclick = () => switchTab('apkstudio');
        
        // Fetch App Maker Apps
        panelAppMaker.innerHTML = `<div style="text-align:center; padding:20px;">${window.suiSpinner(24)}</div>`;
        const appsData = await window.sui.api('ce_get_apps', {}, { toast: false });
        const apps = appsData.apps || [];
        
        let appsHtml = `
            <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">Standalone Apps</div>
        `;
        if (apps.length === 0) {
            appsHtml += window.suiEmptyState('📦', 'No standalone apps found in /apps/');
        } else {
            appsHtml += `<div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">`;
            apps.forEach(app => {
                appsHtml += `
                    <div class="card" style="border:1px solid var(--border-color); margin:0;">
                        <div class="card-content" style="padding:16px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:8px;">
                            <div style="font-size:32px; margin-bottom:4px;">${app.icon}</div>
                            <div style="font-weight:800; font-size:14px; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; width:100%;">${app.name}</div>
                            <div style="font-size:10px; color:var(--text-secondary); font-family:monospace; opacity:0.7;">/apps/${app.folder}</div>
                            
                            <div style="display:flex; gap:6px; width:100%; margin-top:8px;">
                                <button class="ce-app-load btn-primary" data-folder="${app.folder}" data-name="${app.name}" style="flex:1; padding:8px; font-size:11px; background:var(--btn-bg); color:var(--primary); border:1px solid var(--border-color); box-shadow:none;">
                                    Add
                                </button>
                                <button class="ce-app-replace btn-primary" data-folder="${app.folder}" data-name="${app.name}" style="flex:1; padding:8px; font-size:11px; background:var(--ai-accent-bg); color:var(--ai-accent); border:1px solid rgba(88, 86, 214, 0.2); box-shadow:none;">
                                    Replace
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            appsHtml += `</div>`;
        }
        panelAppMaker.innerHTML = appsHtml;
        
        // Fetch APK Studio Projects
        panelApkStudio.innerHTML = `<div style="text-align:center; padding:20px;">${window.suiSpinner(24)}</div>`;
        const projData = await window.sui.api('ce_get_apk_projects', {}, { toast: false });
        const projects = projData.projects || [];
        
        let projHtml = `
            <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">APK Studio Projects</div>
        `;
        if (projects.length === 0) {
            projHtml += window.suiEmptyState('🔨', 'No projects found in ApkStudio/data/projects/');
        } else {
            projHtml += `<div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">`;
            projects.forEach(p => {
                projHtml += `
                    <div class="card" style="border:1px solid var(--border-color); margin:0;">
                        <div class="card-content" style="padding:16px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:8px;">
                            <div style="font-size:32px; margin-bottom:4px;">${p.icon}</div>
                            <div style="font-weight:800; font-size:14px; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; width:100%;">${p.name}</div>
                            <div style="font-size:10px; color:var(--text-secondary); font-family:monospace; opacity:0.7;">ApkStudio / ${p.folder}</div>
                            
                            <div style="display:flex; gap:6px; width:100%; margin-top:8px;">
                                <button class="ce-apk-load btn-primary" data-project="${p.name}" style="flex:1; padding:8px; font-size:11px; background:var(--btn-bg); color:var(--primary); border:1px solid var(--border-color); box-shadow:none;">
                                    Add
                                </button>
                                <button class="ce-apk-replace btn-primary" data-project="${p.name}" style="flex:1; padding:8px; font-size:11px; background:var(--ai-accent-bg); color:var(--ai-accent); border:1px solid rgba(88, 86, 214, 0.2); box-shadow:none;">
                                    Replace
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            projHtml += `</div>`;
        }
        panelApkStudio.innerHTML = projHtml;
        
        window.suiHydrateIcons(contentBox);
        
        const activeTab = localStorage.getItem('ce_apps_active_tab') || 'appmaker';
        switchTab(activeTab);

        // Bind App Maker Apps Handlers
        const handleLoadApp = async (folder, name, replace) => {
            const res = await window.sui.api('ce_get_app_files', { folder: folder }, { toast: `Scanning ${name}...` });
            if (res && res.files) {
                if (replace) fsApi.selection.clear();
                res.files.forEach(f => {
                    fsApi.selection.add(f.path);
                    if (fsApi.fileSizes) fsApi.fileSizes[f.path] = f.size;
                });
                
                fsApi.renderActive();
                fsApi.renderNavigator();
                fsApi.triggerSave();
                window.sui.toast(`${replace ? 'Replaced with' : 'Added'} ${res.files.length} files from ${name}`);
            }
        };

        panelAppMaker.querySelectorAll('.ce-app-load').forEach(btn => {
            btn.onclick = () => handleLoadApp(btn.dataset.folder, btn.dataset.name, false);
        });

        panelAppMaker.querySelectorAll('.ce-app-replace').forEach(btn => {
            btn.onclick = () => handleLoadApp(btn.dataset.folder, btn.dataset.name, true);
        });

        // Bind APK Studio Projects Handlers
        const handleLoadApk = async (project, replace) => {
            const res = await window.sui.api('ce_get_apk_project_files', { project: project }, { toast: `Scanning project ${project}...` });
            if (res && res.files) {
                if (replace) fsApi.selection.clear();
                res.files.forEach(f => {
                    fsApi.selection.add(f.path);
                    if (fsApi.fileSizes) fsApi.fileSizes[f.path] = f.size;
                });
                
                fsApi.renderActive();
                fsApi.renderNavigator();
                fsApi.triggerSave();
                window.sui.toast(`${replace ? 'Replaced with' : 'Added'} ${res.files.length} files from ${project} & ApkStudio`);
            }
        };

        panelApkStudio.querySelectorAll('.ce-apk-load').forEach(btn => {
            btn.onclick = () => handleLoadApk(btn.dataset.project, false);
        });

        panelApkStudio.querySelectorAll('.ce-apk-replace').forEach(btn => {
            btn.onclick = () => handleLoadApk(btn.dataset.project, true);
        });
    };

    window.sui.openStudio({
        id: 'ce-apps',
        title: 'Standalone Apps & Projects',
        onSetup: (contentBox) => refresh(contentBox)
    });
};

window.ceReorderCategory = async function(cat, dir) {
    await window.sui.api('ce_reorder_category', { category: cat, direction: dir }, { toast: false });
    window.ceRefreshStats();
};

window.ceReorderFoundation = async function(file, dir) {
    await window.sui.api('ce_reorder_foundation', { file: file, direction: dir }, { toast: false });
    window.ceRefreshStats();
};

async function ceToggleFile(path, enabled, skipRefresh = false, type = 'foundation') {
    try {
        await window.sui.api('ce_toggle_exclusion', { path: path, enabled: enabled, type: type }, { toast: false });
        if (!skipRefresh) {
            const stats = await window.ceRefreshStats();
            return stats;
        }
    } catch(e) { console.error("Toggle failed", e); }
}
JS;
?>