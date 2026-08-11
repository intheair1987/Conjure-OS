<?php
// ==============================================================================
// PLUGIN: Project Planner
// DESCRIPTION: Deep Work Management.
// Manages AI-maintained project plans in CJOS_PATH_DATA/projects/.
// Supports YAML metadata and "Active Context" injection for AI exports.
// ==============================================================================

$planner_projects_dir = CJOS_PATH_DATA . '/projects';
$planner_config_file = CJOS_PATH_DATA . '/project-planner-config.json';
$planner_lib_dir = CJOS_PATH_APP . '/js/lib';

if (!is_dir($planner_projects_dir)) mkdir($planner_projects_dir, 0777, true);
if (!is_dir($planner_lib_dir)) mkdir($planner_lib_dir, 0777, true);

// --- 1. OFFLINE LIBRARY HANDLER ---
$marked_path = $planner_lib_dir . '/marked.min.js';
if (!file_exists($marked_path)) {
    $cdn_url = "https://cdn.jsdelivr.net/npm/marked/marked.min.js";
    $js_content = @file_get_contents($cdn_url);
    if ($js_content) file_put_contents($marked_path, $js_content);
}

// --- 2. BACKEND HANDLERS ---
if (!function_exists('pp_resolve_path')) {
    function pp_resolve_path($relPath, &$isKnowledge = false) {
        $relPath = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $relPath);
        if ($relPath === '_Knowledge' || strpos($relPath, '_Knowledge/') === 0) {
            $isKnowledge = true;
            if ($relPath === '_Knowledge') return CJOS_PATH_DATA . '/knowledge';
            return CJOS_PATH_DATA . '/knowledge/' . substr($relPath, 11);
        }
        $isKnowledge = false;
        return CJOS_PATH_DATA . '/projects/' . $relPath;
    }
}

if (isset($_POST['plugin_action'])) {
    
    // GET PROJECTS LIST
    if ($_POST['plugin_action'] === 'planner_get_projects') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $projects = [];
        $folders = [];
        
        $parseProjectFile = function($fullPath, $relPath) use (&$projects) {
            $content = file_get_contents($fullPath);
            $meta = [
                'title' => basename($fullPath, '.md'), 
                'status' => 'No Status', 
                'priority' => 'Normal', 
                'archived' => false,
                'pinned' => false,
                'updated' => date('Y-m-d', filemtime($fullPath))
            ];
            
            if (preg_match('/^---[\s\n]+([\s\S]+?)[\s\n]+---/', $content, $matches)) {
                $yaml_lines = explode("\n", trim($matches[1]));
                foreach ($yaml_lines as $line) {
                    $kv = explode(":", $line, 2);
                    if (count($kv) === 2) {
                        $key = strtolower(trim($kv[0]));
                        $val = trim($kv[1]);
                        if ($key === 'title') $meta['title'] = $val;
                        if ($key === 'status') $meta['status'] = $val;
                        if ($key === 'priority') $meta['priority'] = $val;
                        if ($key === 'archived') $meta['archived'] = (strtolower($val) === 'true');
                        if ($key === 'pinned') $meta['pinned'] = (strtolower($val) === 'true');
                        if ($key === 'lastupdated') $meta['updated'] = $val;
                    }
                }
                $body = trim(preg_replace('/^---[\s\S]+?---/', '', $content));
            } else {
                $body = trim($content);
            }
            
            $totalTasks = preg_match_all('/\[[ x]\]/i', $content, $matches);
            $doneTasks = preg_match_all('/\[x\]/i', $content, $matches);
            $percent = ($totalTasks > 0) ? round(($doneTasks / $totalTasks) * 100) : null;

            $projects[] = [
                'filename' => $relPath,
                'meta' => $meta,
                'percent' => $percent,
                'snippet' => mb_strimwidth($body, 0, 120, "...")
            ];
        };

        // Scan Projects
        $dirIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($planner_projects_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($dirIterator as $file) {
            $fullPath = $file->getRealPath();
            $relPath = str_replace('\\', '/', substr($fullPath, strlen($planner_projects_dir) + 1));

            if ($file->isDir()) {
                $folders[] = $relPath;
                continue;
            }

            if ($file->getExtension() !== 'md') continue;
            $parseProjectFile($fullPath, $relPath);
        }

        // Scan Knowledge
        $knowledge_dir = CJOS_PATH_DATA . '/knowledge';
        if (is_dir($knowledge_dir)) {
            $folders[] = '_Knowledge';
            $kIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($knowledge_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($kIterator as $file) {
                $fullPath = $file->getRealPath();
                $relPath = '_Knowledge/' . str_replace('\\', '/', substr($fullPath, strlen($knowledge_dir) + 1));

                if ($file->isDir()) {
                    $folders[] = $relPath;
                    continue;
                }

                if ($file->getExtension() !== 'md') continue;
                $parseProjectFile($fullPath, $relPath);
            }
        }

        $conf = file_exists($planner_config_file) ? json_decode(file_get_contents($planner_config_file), true) : ['active_project' => null];
        $activeFiles = $conf['active_projects'] ?? ($conf['active_project'] ? [$conf['active_project']] : []);

        // Sort: Blueprint > Pinned > Active > Date Updated
        usort($projects, function($a, $b) use ($activeFiles) {
            if ($a['filename'] === '_Blueprint.md') return -1;
            if ($b['filename'] === '_Blueprint.md') return 1;

            // Pinned Status
            if ($a['meta']['pinned'] && !$b['meta']['pinned']) return -1;
            if (!$a['meta']['pinned'] && $b['meta']['pinned']) return 1;

            // Active Status
            $aActive = in_array($a['filename'], $activeFiles);
            $bActive = in_array($b['filename'], $activeFiles);
            if ($aActive && !$bActive) return -1;
            if (!$aActive && $bActive) return 1;

            // Date Updated (Descending)
            return strcmp($b['meta']['updated'], $a['meta']['updated']);
        });
        
        echo json_encode(['status' => 'success', 'projects' => $projects, 'folders' => $folders, 'config' => $conf]);
        exit;
    }

    // CREATE FOLDER
    if ($_POST['plugin_action'] === 'planner_create_folder') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $path = pp_resolve_path($_POST['path']);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Folder already exists']);
        }
        exit;
    }

    // DELETE FOLDER
    if ($_POST['plugin_action'] === 'planner_delete_folder') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        if ($_POST['path'] === '_Knowledge') {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete Knowledge folder']);
            exit;
        }
        $relPath = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['path']);
        $fullPath = pp_resolve_path($relPath);

        if (is_dir($fullPath)) {
            // Check if empty (only . and ..)
            $files = array_diff(scandir($fullPath), array('.', '..'));
            if (empty($files)) {
                if (rmdir($fullPath)) {
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to remove directory']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Folder is not empty']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Folder not found']);
        }
        exit;
    }

    // MOVE ITEM (File or Folder)
    if ($_POST['plugin_action'] === 'planner_move_item') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        if ($_POST['old_rel_path'] === '_Knowledge' || $_POST['new_rel_path'] === '_Knowledge') {
            echo json_encode(['status' => 'error', 'message' => 'Cannot move Knowledge folder']);
            exit;
        }
        $oldPath = pp_resolve_path($_POST['old_rel_path']);
        $newPath = pp_resolve_path($_POST['new_rel_path']);
        
        if (file_exists($oldPath)) {
            $parent = dirname($newPath);
            if (!is_dir($parent)) mkdir($parent, 0777, true);
            
            if (rename($oldPath, $newPath)) {
                // Also move associated audit file if it's a project file
                if (substr($oldPath, -3) === '.md') {
                    $oldAudit = str_replace('.md', '.audit.json', $oldPath);
                    $newAudit = str_replace('.md', '.audit.json', $newPath);
                    if (file_exists($oldAudit)) {
                        rename($oldAudit, $newAudit);
                    }
                }
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Move failed']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Source not found']);
        }
        exit;
    }

    // SET ACTIVE PROJECT (MULTI)
    if ($_POST['plugin_action'] === 'planner_set_active') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $filenames = json_decode($_POST['filenames'], true) ?: [];
        $current = file_exists($planner_config_file) ? json_decode(file_get_contents($planner_config_file), true) : [];
        $current['active_projects'] = $filenames;
        file_put_contents($planner_config_file, json_encode($current, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // SAVE PLANNER UI CONFIG
    if ($_POST['plugin_action'] === 'planner_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $settings = json_decode($_POST['settings'] ?? '{}', true);
        if (!is_array($settings)) $settings = [];
        $current = file_exists($planner_config_file) ? json_decode(file_get_contents($planner_config_file), true) : [];
        if (!is_array($current)) $current = [];
        $merged = array_merge($current, $settings);
        file_put_contents($planner_config_file, json_encode($merged, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET PROJECT CONTENT (Security Proxy)
    if ($_POST['plugin_action'] === 'planner_get_content') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
        $path = pp_resolve_path($filename);
        if (file_exists($path)) {
            echo json_encode(['status' => 'success', 'content' => file_get_contents($path)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File not found']);
        }
        exit;
    }

    // DUPLICATE PROJECT
    if ($_POST['plugin_action'] === 'planner_duplicate_project') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
        $path = pp_resolve_path($filename);
        
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $dir = pathinfo($filename, PATHINFO_DIRNAME);
            $prefix = ($dir === '.') ? "" : $dir . '/';
            
            // Generate unique filename
            $newFilename = $prefix . $base . '_Copy.' . $ext;
            $counter = 1;
            while (file_exists(pp_resolve_path($newFilename))) {
                $newFilename = $prefix . $base . '_Copy_' . $counter . '.' . $ext;
                $counter++;
            }

            // Update Title in YAML if present
            if (preg_match('/^Title:\s*(.*)$/m', $content, $matches)) {
                $oldTitle = trim($matches[1]);
                $content = preg_replace('/^Title:\s*.*$/m', "Title: $oldTitle (Copy)", $content, 1);
            }

            file_put_contents(pp_resolve_path($newFilename), $content);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Source file not found']);
        }
        exit;
    }

    // TOGGLE PIN
    if ($_POST['plugin_action'] === 'planner_toggle_pin') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
        $path = pp_resolve_path($filename);
        
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $newState = ($_POST['state'] === 'true') ? 'true' : 'false';
            
            if (preg_match('/^---[\s\n]+([\s\S]+?)[\s\n]+---/', $content, $matches)) {
                $yaml = $matches[1];
                if (preg_match('/Pinned:\s*.*/i', $yaml)) {
                    $newYaml = preg_replace('/Pinned:\s*.*/i', "Pinned: $newState", $yaml);
                } else {
                    $newYaml = $yaml . "\nPinned: $newState";
                }
                $newContent = str_replace($yaml, $newYaml, $content);
            } else {
                $newContent = "---\nPinned: $newState\n---\n" . $content;
            }
            
            file_put_contents($path, $newContent);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File not found']);
        }
        exit;
    }

    // TOGGLE ARCHIVE
    if ($_POST['plugin_action'] === 'planner_toggle_archive') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
        $path = pp_resolve_path($filename);
        
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $newState = ($_POST['state'] === 'true') ? 'true' : 'false';
            
            if (preg_match('/^---[\s\n]+([\s\S]+?)[\s\n]+---/', $content, $matches)) {
                $yaml = $matches[1];
                if (preg_match('/Archived:\s*.*/i', $yaml)) {
                    $newYaml = preg_replace('/Archived:\s*.*/i', "Archived: $newState", $yaml);
                } else {
                    $newYaml = $yaml . "\nArchived: $newState";
                }
                $newContent = str_replace($yaml, $newYaml, $content);
            } else {
                $newContent = "---\nArchived: $newState\n---\n" . $content;
            }
            
            file_put_contents($path, $newContent);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File not found']);
        }
        exit;
    }

    // GET AUDIT DATA
    if ($_POST['plugin_action'] === 'planner_get_audit') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
        $auditPath = pp_resolve_path(str_replace('.md', '.audit.json', $filename));
        
        if (file_exists($auditPath)) {
            echo json_encode(['status' => 'success', 'data' => json_decode(file_get_contents($auditPath), true)]);
        } else {
            // Return success with null data; missing audit is not a server error
            echo json_encode(['status' => 'success', 'data' => null]);
        }
        exit;
    }

    // SAVE AUDIT DATA (Update checkboxes)
    if ($_POST['plugin_action'] === 'planner_save_audit') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
        $auditPath = pp_resolve_path(str_replace('.md', '.audit.json', $filename));
        file_put_contents($auditPath, $_POST['data']);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // DELETE AUDIT FILE
    if ($_POST['plugin_action'] === 'planner_delete_audit') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
        $auditPath = pp_resolve_path(str_replace('.md', '.audit.json', $filename));
        if (file_exists($auditPath)) {
            unlink($auditPath);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Audit file not found']);
        }
        exit;
    }

    // GET SYSTEM DEPENDENCY MAP (Merged System + AppMaker Maps)
    if ($_POST['plugin_action'] === 'planner_get_sys_map') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $sysMapPath = CJOS_PATH_DATA . '/knowledge/sys_map.json';
        $appsMapPath = CJOS_PATH_DATA . '/knowledge/sys_map_apps-private.json';

        $sysMap = file_exists($sysMapPath) ? json_decode(file_get_contents($sysMapPath), true) : [];
        $appsMap = file_exists($appsMapPath) ? json_decode(file_get_contents($appsMapPath), true) : [];

        if (!is_array($sysMap)) $sysMap = [];
        if (!is_array($appsMap)) $appsMap = [];

        $mergedMap = array_merge($sysMap, $appsMap);
        echo json_encode(['status' => 'success', 'map' => $mergedMap]);
        exit;
    }

    // GET SYSTEM FILE TREE (For Scope Management)
if ($_POST['plugin_action'] === 'planner_get_sys_files') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
        
    $root = CJOS_PATH_ROOT;
    $query = trim($_POST['q'] ?? '');
    $relPath = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['path'] ?? '');
    $filters = isset($_POST['filters']) ? explode(',', $_POST['filters']) : [];
$isFuzzy = !empty($_POST['fuzzy']);
$isPathSearch = !empty($_POST['path_search']);
$targetDir = realpath($root . ($relPath ? '/' . $relPath : ''));

if (!$targetDir || strpos($targetDir, realpath($root)) !== 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid path']);
    exit;
}

$items = [];
$exclude =['/^\.git$/', '/^node_modules$/', '/^recordings$/', '/^backups$/', '/^data\/backups$/', '/^\.htaccess$/'];
        
$allowedExts =[];
if (!empty($filters)) {
    if (in_array('code', $filters)) $allowedExts = array_merge($allowedExts,['php', 'js', 'css', 'html', 'htm', 'sql', 'sh', 'bash', 'zsh', 'ksh', 'csh', 'ps1', 'bat', 'cmd', 'py', 'rb', 'pl', 'lua']);
    if (in_array('json', $filters)) $allowedExts = array_merge($allowedExts, ['json', 'yaml', 'yml', 'toml']);
    if (in_array('md', $filters)) $allowedExts = array_merge($allowedExts,['md', 'txt', 'csv', 'conf', 'ini', 'env']);
    if (in_array('binary', $filters)) $allowedExts = array_merge($allowedExts,['jpg', 'jpeg', 'png', 'gif', 'svg', 'pdf', 'db', 'sqlite', 'zip', 'mp3', 'mp4', 'webm', 'ogg']);
}

if ($query !== '') {
    // RECURSIVE SEARCH MODE
    $dirIt = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
            
    // Filter iterator to skip excluded directories entirely
    $filterIt = new RecursiveCallbackFilterIterator($dirIt, function ($current, $key, $iterator) use ($exclude, $root) {
        $fName = $current->getFilename();
        $fRel = ltrim(str_replace('\\', '/', substr($current->getRealPath(), strlen(realpath($root)))), '/');
        foreach ($exclude as $pattern) {
            if (preg_match($pattern, $fRel) || preg_match($pattern, $fName)) return false;
        }
        return true;
    });

    $it = new RecursiveIteratorIterator($filterIt);
            
    $fuzzyRegex = '';
    if ($isFuzzy) {
        // Order-Agnostic Fuzzy: Every word must exist somewhere in the string.
        // Uses positive lookaheads: (?=.*word1)(?=.*word2)
        $parts = array_filter(explode(' ', $query));
        $lookaheads = array_map(function($p) { return '(?=.*' . preg_quote($p, '/') . ')'; }, $parts);
        $fuzzyRegex = '/' . implode('', $lookaheads) . '/i';
    }

    foreach ($it as $file) {
        $fName = $file->getFilename();
        $fRel = ltrim(str_replace('\\', '/', substr($file->getRealPath(), strlen(realpath($root)))), '/');
                
        if (!empty($allowedExts) && !$file->isDir()) {if (!in_array(strtolower($file->getExtension()), $allowedExts)) continue;
        }

        // Determine what we are searching in
        $targetString = $isPathSearch ? $fRel : $fName;

        $matched = false;
        if ($isFuzzy) {
            $matched = preg_match($fuzzyRegex, $targetString);
        } else {
            $matched = (stripos($targetString, $query) !== false);
        }if ($matched) {
    $items[] =[
        'name' => $fName,
        'path' => $fRel,
        'is_dir' => $file->isDir(),
        'ext' => $file->getExtension(),
        'size' => $file->isDir() ? '' : round($file->getSize() / 1024, 1) . ' KB'
    ];
}
if (count($items) >= 150) { $truncated = true; break; } // Limit results for performance
            }
        } else {// DIRECTORY NAVIGATOR MODE
        $files = scandir($targetDir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            foreach ($exclude as $pattern) { if (preg_match($pattern, $f)) continue 2; }
                
            $full = $targetDir . '/' . $f;
            $isDir = is_dir($full);
                
            if (!empty($allowedExts) && !$isDir) {
                if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowedExts)) continue;
            }
                
            $rel = ltrim($relPath . '/' . $f, '/');
            $items[] =[
                'name' => $f,
                'path' => $rel,
                'is_dir' => $isDir,
                'ext' => pathinfo($f, PATHINFO_EXTENSION),
                'size' => $isDir ? '' : round(filesize($full) / 1024, 1) . ' KB'
            ];
        }
    }// Sort: Dirs first, then name
        usort($items, function($a, $b) {
            if ($a['is_dir'] && !$b['is_dir']) return -1;
            if (!$a['is_dir'] && $b['is_dir']) return 1;
            return strcasecmp($a['name'], $b['name']);
        });

        echo json_encode(['status' => 'success', 'items' => $items, 'truncated' => ($truncated ?? false)]);
        exit;
    }

    // SAVE PROJECT SCOPE
    if ($_POST['plugin_action'] === 'planner_save_scope') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
        $scopeJson = $_POST['scope']; // Array of strings
        $scopeArr = json_decode($scopeJson, true);
        $path = pp_resolve_path($filename);

        if (file_exists($path)) {
            $content = file_get_contents($path);
            $scopeStr = "[" . implode(", ", $scopeArr) . "]";
            
            if (preg_match('/^---[\s\n]+([\s\S]+?)[\s\n]+---/', $content, $matches)) {
                $yaml = $matches[1];
                if (preg_match('/Scope:\s*.*/i', $yaml)) {
                    $newYaml = preg_replace('/Scope:\s*.*/i', "Scope: $scopeStr", $yaml);
                } else {
                    $newYaml = rtrim($yaml) . "\nScope: $scopeStr";
                }
                $newContent = str_replace($yaml, $newYaml, $content);
            } else {
                $newContent = "---\nScope: $scopeStr\n---\n" . $content;
            }
            
            file_put_contents($path, $newContent);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File not found']);
        }
        exit;
    }

    // TOGGLE TASK CHECKBOX
    if ($_POST['plugin_action'] === 'planner_toggle_task') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
        $path = pp_resolve_path($filename);
        $taskIdx = (int)$_POST['task_index']; // 0-based index of the checkbox in the file
        $newState = ($_POST['state'] === 'true') ? 'x' : ' ';

        if (file_exists($path)) {
            $lines = explode("\n", file_get_contents($path));
            $currentCheckboxCount = 0;
            $updated = false;

            foreach ($lines as &$line) {
                // Match - [ ] or - [x] (case insensitive)
                if (preg_match('/^(\s*-\s*\[)([ xX])(\].*)$/', $line, $matches)) {
                    if ($currentCheckboxCount === $taskIdx) {
                        $line = $matches[1] . $newState . $matches[3];
                        $updated = true;
                        break;
                    }
                    $currentCheckboxCount++;
                }
            }

            if ($updated) {
                file_put_contents($path, implode("\n", $lines));
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Task index not found']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File not found']);
        }
        exit;
    }

    // DELETE PROJECT
    if ($_POST['plugin_action'] === 'planner_delete_project') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $filename = preg_replace('/[^a-zA-Z0-9_.\/-]/', '', $_POST['filename']);
        
        // Safety: Do not allow deleting the system blueprint via UI
        if ($filename === '_Blueprint.md') {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete system blueprint']);
            exit;
        }

        $path = pp_resolve_path($filename);
        if (file_exists($path)) {
            unlink($path);
            
            // Cleanup associated audit file
            $auditPath = pp_resolve_path(str_replace('.md', '.audit.json', $filename));
            if (file_exists($auditPath)) unlink($auditPath);
            
            // If this was the active project, clear the config
            $conf = file_exists($planner_config_file) ? json_decode(file_get_contents($planner_config_file), true) : [];
            $save = false;
            
            // Legacy single
            if (isset($conf['active_project']) && $conf['active_project'] === $filename) {
                $conf['active_project'] = null;
                $save = true;
            }
            // Multi array
            if (isset($conf['active_projects']) && is_array($conf['active_projects'])) {
                if (in_array($filename, $conf['active_projects'])) {
                    $conf['active_projects'] = array_values(array_filter($conf['active_projects'], function($f) use ($filename) { return $f !== $filename; }));
                    $save = true;
                }
            }

            if ($save) file_put_contents($planner_config_file, json_encode($conf, JSON_PRETTY_PRINT));
            
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File not found']);
        }
        exit;
    }

    // SAFE UNINSTALL
    if ($_POST['plugin_action'] === 'planner_safe_uninstall') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $uiConfigPath = CJOS_PATH_DATA . '/ui-config.json';
        if (file_exists($uiConfigPath)) {
            $uiData = json_decode(file_get_contents($uiConfigPath), true);
            $uiData['plugins_enabled']['plugin_ProjectPlanner'] = "false";
            file_put_contents($uiConfigPath, json_encode($uiData, JSON_PRETTY_PRINT));
        }
        if (file_exists($planner_config_file)) unlink($planner_config_file);
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// --- 3. PAGE VIEW ---
$pp_page = <<<'HTML'
<div class="scroll-view" id="project-planner-view">
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <div class="page-title" style="margin-bottom:0; padding-top:0;">Planner</div>
        <div style="display:flex; gap:8px;">
            <button id="pp-select-toggle" onclick="ppToggleSelectMode()" class="icon-btn" title="Select Projects" style="background:var(--btn-bg); width:34px; height:34px; border-radius:50%; color:var(--text-primary);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
            </button>
            <button onclick="ppPromptCreateFolder()" class="icon-btn" title="New Folder" style="background:var(--btn-bg); width:34px; height:34px; border-radius:50%; color:var(--text-primary);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
            </button>
            <button id="pp-view-toggle" onclick="ppToggleViewMode()" class="icon-btn" title="Toggle View" style="background:var(--btn-bg); width:34px; height:34px; border-radius:50%; color:var(--text-primary);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
            </button>
            <button id="pp-active-count-btn" onclick="ppOpenActiveProjectsView()" class="icon-btn" title="Active Projects" style="background:var(--btn-bg); width:34px; height:34px; border-radius:50%; color:var(--text-primary); display:none; align-items:center; justify-content:center;">
                <!-- Count injected via JS -->
            </button>
            <button onclick="ppFetchProjects()" class="icon-btn" style="background:var(--btn-bg); width:34px; height:34px; border-radius:50%; color:var(--text-primary);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6"></path><path d="M1 20v-6h6"></path><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
            </button>
        </div>
    </div>



    <!-- BREADCRUMBS -->
    <div id="pp-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin-bottom:20px; overflow-x:auto; white-space:nowrap; -ms-overflow-style:none; scrollbar-width:none; padding-bottom:4px;"></div>

    <div id="pp-project-grid" style="display:grid; grid-template-columns: 1fr; gap:16px;">
        <!-- Injected via JS -->
    </div>
</div>
HTML;

$plugin_pages[] = $pp_page;

$plugin_tools[] = [
    'name' => 'Planner',
    'desc' => 'Deep work plans',
    'sui_icon' => 'folder',
    'color' => 'rgba(0, 122, 255, 0.1)',
    'icon_color' => 'var(--primary)',
    'action' => "dashNavToPage('project-planner-view')",
    'linked_page' => 'project-planner-view'
];

$plugin_overlays[] = <<<'HTML'
<style>
    #pp-batch-bar {
        position: fixed; 
        /* Anchor to the physical top of the Command Bar/FAB with a small 10px gap */
        bottom: calc(var(--fr-ui-h, 20px) + 10px); 
        left: 50%; 
        transform: translateX(-50%) translateY(20px);
        background: var(--glass-bg); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px);
        border: 1px solid var(--glass-border); border-radius: 24px; padding: 10px 16px;
        display: flex; gap: 12px; align-items: center; 
        z-index: 30000; /* Ensure it is above the FCB and all other overlays */
        opacity: 0; visibility: hidden; pointer-events: none;
        transition: 
            transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275),
            opacity 0.3s ease,
            visibility 0.3s;
        box-shadow: var(--shadow-floating);
    }
    #pp-batch-bar.visible { 
        transform: translateX(-50%) translateY(0); 
        opacity: 1; visibility: visible; pointer-events: auto; 
    }
    .pp-batch-btn {
        width: 42px; height: 42px; border-radius: 50%; border: none; background: var(--btn-bg);
        color: var(--text-primary); display: flex; align-items: center; justify-content: center;
        cursor: pointer; transition: all 0.2s;
    }
    .pp-batch-btn:active { transform: scale(0.9); background: rgba(0,0,0,0.05); }
    .pp-batch-btn.danger { color: var(--danger); }
    
    .pp-card-select-indicator {
        position: absolute; top: 12px; right: 12px; width: 24px; height: 24px;
        border-radius: 50%; border: 2px solid var(--border-color); background: var(--card-bg);
        display: none; align-items: center; justify-content: center; z-index: 5;
        transition: all 0.2s;
    }
    body.pp-select-mode .pp-card-select-indicator { display: flex; }
    .pp-card.is-selected { border-color: var(--primary); background: color-mix(in srgb, var(--primary), transparent 95%); }
    .pp-card.is-selected .pp-card-select-indicator { background: var(--primary); border-color: var(--primary); color: white; }
</style>

<div id="pp-batch-bar">
    <div id="pp-batch-count" style="font-size:11px; font-weight:900; color:var(--primary); margin: 0 8px; text-transform:uppercase; letter-spacing:0.5px;">0 Selected</div>
    <button onclick="ppBatchArchive(true)" class="pp-batch-btn" title="Archive"><span data-sui-icon="archive" data-sui-size="20"></span></button>
    <button onclick="ppBatchMove()" class="pp-batch-btn" title="Move"><span data-sui-icon="folder" data-sui-size="20"></span></button>
    <button onclick="ppBatchSetActive(true)" class="pp-batch-btn" title="AI Context"><span data-sui-icon="activity" data-sui-size="20"></span></button>
    <button onclick="ppBatchDelete()" class="pp-batch-btn danger" title="Delete"><span data-sui-icon="trash" data-sui-size="20"></span></button>
    <div style="width:1px; height:24px; background:var(--border-color); margin: 0 4px;"></div>
    <button onclick="ppToggleSelectMode(false)" class="pp-batch-btn" title="Cancel"><span data-sui-icon="close" data-sui-size="20"></span></button>
</div>
HTML;

// --- 4. SETTINGS UI ---
$plugin_settings_map['ProjectPlanner'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Project Management</label>
        <div class="setting-desc">Maintenance of AI-driven project plans. All plans are stored in <code>data/projects/</code>.</div>
    </div>
    <div class="setting-item">
        <button onclick="ppSafeUninstall()" class="text-btn" style="width:100%; color:var(--danger); border:1px solid var(--border-color); border-radius:12px; padding:12px; font-weight:600;">Safe Uninstall & Disable</button>
    </div>
HTML;

// --- 5. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- PROJECT PLANNER ENGINE ---

window.ppProjects = [];
let ppFolders = [];
let ppActiveFilenames = [];
let ppCurrentRawMd = "";
let ppCurrentPath = ""; // Root
let ppSelectMode = false;
let ppSelectedFiles = new Set();
let ppArchivedOpen = false;
let ppUiConfig = { 
    show_label: true, 
    label_pos: 'tl', 
    label_style: 'ribbon',
    view_mode: 'list'
};

window.ppToggleSelectMode = function(force) {
    ppSelectMode = (force !== undefined) ? force : !ppSelectMode;
    document.body.classList.toggle('pp-select-mode', ppSelectMode);
    
    const toggleBtn = document.getElementById('pp-select-toggle');
    if (toggleBtn) toggleBtn.style.color = ppSelectMode ? 'var(--primary)' : 'var(--text-primary)';
    
    if (!ppSelectMode) {
        ppSelectedFiles.clear();
        ppUpdateBatchBar();
    }
    ppRenderGrid();
};

window.ppToggleFileSelection = function(filename) {
    if (ppSelectedFiles.has(filename)) ppSelectedFiles.delete(filename);
    else ppSelectedFiles.add(filename);
    ppUpdateBatchBar();
    ppRenderGrid();
};

function ppUpdateBatchBar() {
    const bar = document.getElementById('pp-batch-bar');
    const countLabel = document.getElementById('pp-batch-count');
    if (!bar || !countLabel) return;
    
    const count = ppSelectedFiles.size;
    countLabel.innerText = `${count} Selected`;
    
    // Only show if we are in select mode AND have items selected
    const shouldShow = ppSelectMode && count > 0;
    bar.classList.toggle('visible', shouldShow);
}

window.ppBatchArchive = async function(state) {
    const files = Array.from(ppSelectedFiles);
    window.sui.toast(`Archiving ${files.length} plans...`);
    for (const f of files) {
        await window.sui.api('planner_toggle_archive', { filename: f, state: state }, { toast: false });
    }
    ppToggleSelectMode(false);
    ppFetchProjects();
};

window.ppBatchDelete = function() {
    const files = Array.from(ppSelectedFiles);
    window.openConfirm("Delete Selected?", `Permanently delete ${files.length} projects?`, async () => {
        for (const f of files) {
            if (f === '_Blueprint.md') continue;
            await window.sui.api('planner_delete_project', { filename: f }, { toast: false });
        }
        ppToggleSelectMode(false);
        ppFetchProjects();
    }, true);
};

window.ppBatchSetActive = async function(state) {
    const files = Array.from(ppSelectedFiles).filter(f => !f.startsWith('_Knowledge/'));
    if (files.length === 0) {
        window.sui.toast("No valid projects selected");
        ppToggleSelectMode(false);
        return;
    }
    if (state) {
        files.forEach(f => { if (!ppActiveFilenames.includes(f)) ppActiveFilenames.push(f); });
    } else {
        ppActiveFilenames = ppActiveFilenames.filter(f => !files.includes(f));
    }
    await window.sui.api('planner_set_active', { filenames: ppActiveFilenames }, { toast: "AI Context Updated" });
    ppToggleSelectMode(false);
    ppFetchProjects();
};

window.ppBatchMove = function() {
    const files = Array.from(ppSelectedFiles);
    // Reuse existing move picker logic but adapt for multiple
    const dirs = new Set([""]); 
    ppFolders.forEach(f => dirs.add(f));
    const options = Array.from(dirs).sort().map(d => ({ label: d === "" ? "ROOT" : "📂 " + d, value: d }));
    
    window.openPicker(`Move ${files.length} items to...`, options, null, async (targetDir) => {
        for (const oldRelPath of files) {
            const name = oldRelPath.split('/').pop();
            const newRelPath = (targetDir ? targetDir + '/' : "") + name;
            if (newRelPath === oldRelPath) continue;
            await window.sui.api('planner_move_item', { old_rel_path: oldRelPath, new_rel_path: newRelPath }, { toast: false });
            ppActiveFilenames = ppActiveFilenames.map(f => f === oldRelPath ? newRelPath : f);
        }
        await window.sui.api('planner_set_active', { filenames: ppActiveFilenames }, { toast: false });
        ppToggleSelectMode(false);
        ppFetchProjects();
    });
};



window.ppToggleViewMode = async function() {
    ppUiConfig.view_mode = (ppUiConfig.view_mode === 'list' ? 'grid' : 'list');
    ppUpdateViewToggleIcon();
    ppRenderGrid();
    try {
        const payload = JSON.stringify({ view_mode: ppUiConfig.view_mode });
        const data = await window.sui.api('planner_save_config', { settings: payload }, { toast: false });
    } catch (e) {
        console.error("Failed to save view mode", e);
    }
};

function ppUpdateViewToggleIcon() {
    const btn = document.getElementById('pp-view-toggle');
    if (!btn) return;
    const isList = ppUiConfig.view_mode === 'list';
    btn.innerHTML = isList 
        ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>`
        : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>`;
}

function ppInit() {
    ppFetchProjects();
    
    // Load marked.js lazily if not already present
    if (typeof marked === "undefined") {
        const script = document.createElement("script");
        script.src = window.CJOS_ASSET_PATH + "/marked.min.js";
        document.head.appendChild(script);
    }
}

// Listen for Lazy Hydration
window.addEventListener('cjos-hydrated', (e) => {
    if (e.detail.id === 'project-planner-view') {
        ppInit();
    }
});

window.addEventListener("load", () => {
    // Inject Markdown CSS
    const style = document.createElement("style");
    style.innerHTML = `
        #pp-markdown-body { font-size: 16px; line-height: 1.6; }
        #pp-markdown-body h1 { 
            font-size: 28px; 
            margin-top: 0; margin-bottom: 24px; color: var(--text-primary); 
            border-bottom: 2px solid var(--primary); padding-bottom: 12px;
            display: inline-block;
        }
        
        /* THE ACCORDION STYLING */
        #pp-markdown-body details { 
            background: var(--card-bg); border: 1px solid var(--border-color); 
            border-radius: 20px; margin-bottom: 16px; overflow: hidden; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            transition: all 0.3s ease;
        }
        #pp-markdown-body details[open] { border-color: var(--primary); box-shadow: 0 15px 40px rgba(0,0,0,0.06); }
        
        #pp-markdown-body summary { 
            padding: 18px 45px 18px 20px; font-size: 15px; font-weight: 800; cursor: pointer; 
            list-style: none; display: block; position: relative;
            background: var(--bg-color); color: var(--text-primary);
            text-transform: uppercase; letter-spacing: 0.5px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        #pp-markdown-body summary::-webkit-details-marker { display: none; }
        #pp-markdown-body summary::after { 
            content: ""; width: 20px; height: 20px;
            position: absolute; right: 18px; top: 50%; margin-top: -10px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' stroke='%23007AFF' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-size: contain; background-repeat: no-repeat;
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            opacity: 0.6;
        }
        #pp-markdown-body details[open] summary::after { transform: rotate(180deg); opacity: 1; }
        #pp-markdown-body .details-content { padding: 20px; border-top: 1px solid var(--border-color); background: var(--card-bg); }

        /* HIGH-FIDELITY TASK ROWS */
        #pp-markdown-body ul { padding-left: 0; list-style-type: none; margin: 12px 0; }
        
        #pp-markdown-body li:has(input[type="checkbox"]) { 
            margin-bottom: 10px; 
            position: relative; 
            padding: 16px 16px 16px 52px; 
            background: var(--bg-color); 
            border-radius: 14px;
            border: 1px solid var(--border-color);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Task List Checkboxes (Absolute) */
        #pp-markdown-body li input[type="checkbox"] { 
            appearance: none; -webkit-appearance: none;
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            width: 22px; height: 22px; 
            border: 2px solid var(--primary); border-radius: 50%; 
            background: white; margin: 0; pointer-events: none;
            display: flex; align-items: center; justify-content: center;
        }

        /* Generic Checkboxes (Flex/Flow) */
        #pp-markdown-body input[type="checkbox"] {
            appearance: none; -webkit-appearance: none;
            width: 22px; height: 22px; 
            border: 2px solid var(--primary); border-radius: 50%; 
            background: white; margin: 0;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            cursor: pointer;
        }

        #pp-markdown-body input[type="checkbox"]:checked {
            background: #34C759; border-color: #34C759;
            box-shadow: 0 4px 10px rgba(52, 199, 89, 0.3);
        }

        #pp-markdown-body input[type="checkbox"]:checked::after {
            content: ""; width: 5px; height: 9px;
            border: solid white; border-width: 0 2.5px 2.5px 0;
            transform: rotate(45deg) translate(-1px, -1px);
        }

        #pp-markdown-body li:has(input:checked) { 
            color: var(--text-secondary); opacity: 0.6; 
            text-decoration: line-through; background: rgba(0,0,0,0.02);
            border-style: dashed;
        }

        #pp-markdown-body li:has(input:not(:checked)) {
            color: var(--text-primary); font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }

        /* STATUS BADGES */
        .pp-status-badge { 
            display: inline-block; padding: 3px 10px; border-radius: 8px; 
            font-size: 10px; font-weight: 900; text-transform: uppercase; 
            margin-right: 6px; letter-spacing: 0.8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .status-done { background: #E0F8E0; color: #1E4620; }
        .status-todo { background: #EBF5FF; color: #007AFF; }
        .status-wait { background: #FFFBE6; color: #856404; }
        .status-crit { background: #FFE5E5; color: #FF3B30; }

        /* MOBILE TABLE SAFETY */
        .pp-table-wrapper { width: 100%; overflow-x: auto; margin: 16px 0; border-radius: 12px; border: 1px solid var(--border-color); }
        #pp-markdown-body table { width: 100%; border-collapse: collapse; background: var(--card-bg); color: var(--text-primary); font-size: 14px; }
        #pp-markdown-body th { background: var(--btn-bg); padding: 12px; text-align: left; font-weight: 700; color: var(--text-primary); white-space: nowrap; }
        #pp-markdown-body td { padding: 12px; border-top: 1px solid var(--border-color); color: var(--text-primary); min-width: 180px; vertical-align: top; }
        #pp-markdown-body td:first-child { min-width: 90px; white-space: nowrap; }

        .pp-code-container { position: relative; margin: 16px 0; border-radius: 12px; overflow: hidden; }
        #pp-markdown-body pre { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 12px; overflow-x: auto; margin: 0 !important; }
        .pp-code-copy-btn {
            position: absolute; top: 10px; right: 10px;
            background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2);
            color: rgba(255, 255, 255, 0.8); border-radius: 6px; padding: 5px 10px;
            font-size: 10px; font-weight: 800; cursor: pointer; z-index: 10;
            transition: all 0.2s; backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px);
        }
        .pp-code-copy-btn:active { transform: scale(0.9); background: rgba(255, 255, 255, 0.2); }
        #pp-markdown-body blockquote { border-left: 4px solid var(--primary); margin: 16px 0; padding: 8px 20px; background: var(--ai-accent-bg); border-radius: 0 12px 12px 0; font-style: italic; }
        
        .pp-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 22px; padding: 20px; box-shadow: var(--shadow-card); transition: transform 0.1s; cursor: pointer; position: relative; overflow: hidden; }
        .pp-card:active { transform: scale(0.98); }
        .pp-card.active-context { border: 2px solid var(--primary); }
        
        /* LONG PRESS VISUAL FEEDBACK */
        .pp-lp-active { 
            transform: scale(0.94) !important; 
            opacity: 0.6; 
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.2s !important; 
            background: var(--btn-bg) !important;
        }
        







        
        .pp-archive-section {
            margin-top: 20px;
            border-top: 1px solid var(--border-color);
            padding-top: 20px;
        }
        .pp-archive-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 4px; cursor: pointer; user-select: none;
        }
        .pp-archive-title {
            font-size: 11px; font-weight: 800; color: var(--text-secondary);
            text-transform: uppercase; letter-spacing: 1px;
        }
        .pp-archive-arrow {
            width: 16px; height: 16px; color: var(--text-secondary);
            transition: transform 0.3s ease; transform: rotate(-90deg);
        }
        details[open] .pp-archive-arrow { transform: rotate(0deg); }
        .pp-archive-grid {
            display: grid; grid-template-columns: 1fr; gap: 16px; padding: 12px 0;
        }
        .pp-card.is-archived { opacity: 0.6; filter: grayscale(0.5); }

        /* PROGRESS UI */
        .pp-progress-container { margin-top: 16px; width: 100%; }
        .pp-progress-meta { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 6px; }
        .pp-progress-label { font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
        .pp-progress-val { font-size: 14px; font-weight: 800; color: var(--primary); font-family: monospace; }
        .pp-progress-track { width: 100%; height: 6px; background: var(--btn-bg); border-radius: 10px; overflow: hidden; }
        .pp-progress-fill { height: 100%; background: var(--primary); border-radius: 10px; transition: width 0.8s cubic-bezier(0.16, 1, 0.3, 1); }

        /* SMOOTH REVEAL FOR ASYNC BUTTONS */
        #pp-audit-action-zone {
            transition: all 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            opacity: 0;
            transform: translateY(-10px);
            max-height: 0;
            overflow: hidden;
            pointer-events: none;
        }
        #pp-audit-action-zone.reveal {
            opacity: 1;
            transform: translateY(0);
            max-height: 80px;
            margin-top: 12px;
            padding-top: 10px;
            pointer-events: auto;
        }
    `;
    document.head.appendChild(style);

});

async function ppFetchProjects() {
    try {
        const data = await window.sui.api('planner_get_projects', {}, { toast: false });
        if (data) {
            ppProjects = data.projects;
            ppFolders = data.folders || [];
            // Handle migration from single string to array
            const conf = data.config || {};
            if (conf.active_projects) {
                ppActiveFilenames = conf.active_projects.filter(f => !f.startsWith('_Knowledge/'));
            } else if (conf.active_project) {
                ppActiveFilenames = conf.active_project.startsWith('_Knowledge/') ? [] : [conf.active_project];
            } else {
                ppActiveFilenames = [];
            }
                    
if (conf.view_mode) {
    ppUiConfig.view_mode = conf.view_mode;
}
                    
ppUpdateViewToggleIcon();
ppRenderGrid();}
    } catch(e) {}
}

function ppRenderBreadcrumbs() {
    const cont = document.getElementById('pp-breadcrumbs');
    if (!cont) return;
    cont.innerHTML = "";
    
    const parts = ppCurrentPath ? ppCurrentPath.split('/') : [];
    
    const createCrumb = (label, path, isLast) => {
        const btn = document.createElement('button');
        btn.className = 'text-btn';
        btn.style.cssText = `font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:${isLast ? 'var(--text-primary)' : 'var(--primary)'}; background:none; border:none; padding:0; cursor:${isLast ? 'default' : 'pointer'};`;
        btn.innerText = label;
        if (!isLast) btn.onclick = () => { ppCurrentPath = path; ppRenderGrid(); };
        cont.appendChild(btn);
        if (!isLast) {
            const sep = document.createElement('span');
            sep.innerText = " / ";
            sep.style.cssText = "font-size:11px; color:var(--text-secondary); opacity:0.5;";
            cont.appendChild(sep);
        }
    };

    createCrumb("ROOT", "", parts.length === 0);
    let cumulative = "";
    parts.forEach((p, i) => {
        cumulative += (cumulative ? "/" : "") + p;
        createCrumb(p, cumulative, i === parts.length - 1);
    });
}

function ppRenderGrid() {
    const grid = document.getElementById('pp-project-grid');
    if (!grid) return;
    grid.innerHTML = "";
    ppRenderBreadcrumbs();
    ppUpdateActiveCount();

    const isCompact = ppUiConfig.view_mode === 'list';
    grid.style.gap = isCompact ? '8px' : '16px';

    // Register with SharedUI for Android Back gesture support
    if (window.sui) {
        if (ppCurrentPath !== "") {
            window.sui.registerOverlay('pp-folder', window.ppHandleBack);
        } else {
            window.sui.unregisterOverlay('pp-folder');
        }
    }

    // 1. Filter items for current path
    const currentItems = ppProjects.filter(p => {
        const parts = p.filename.split('/');
        const dir = parts.length > 1 ? parts.slice(0, -1).join('/') : "";
        return dir === ppCurrentPath;
    });

    // 2. Identify direct subfolders in current path
    const subfolders = ppFolders.filter(f => {
        const parts = f.split('/');
        const parentDir = parts.length > 1 ? parts.slice(0, -1).join('/') : "";
        return parentDir === ppCurrentPath;
    }).map(f => f.split('/').pop());

    // 3. Render Folders
    subfolders.sort((a, b) => {
        if (a === '_Knowledge') return -1;
        if (b === '_Knowledge') return 1;
        return a.localeCompare(b);
    }).forEach(folderName => {
        const card = document.createElement('div');
        card.className = 'pp-card';
        card.style.background = 'var(--btn-bg)';
        
        if (isCompact) {
            card.style.padding = "10px 16px";
            card.innerHTML = `
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:32px; height:32px; border-radius:8px; background:var(--card-bg); display:flex; align-items:center; justify-content:center; color:var(--primary);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                    </div>
                    <div style="font-weight:800; font-size:15px; color:var(--text-primary);">${folderName}</div>
                </div>
            `;
        } else {
            card.innerHTML = `
                <div style="display:flex; align-items:center; gap:15px; padding:10px 0;">
                    <div style="width:44px; height:44px; border-radius:12px; background:var(--card-bg); display:flex; align-items:center; justify-content:center; color:var(--primary);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:24px;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                    </div>
                    <div style="font-weight:800; font-size:17px; color:var(--text-primary);">${folderName}</div>
                </div>
            `;
        }
        
        // Context Menu for Folders
        let lpTimer, startX, startY;
        card.onmousedown = card.ontouchstart = (e) => {
            const pos = e.touches ? e.touches[0] : e;
            startX = pos.clientX; startY = pos.clientY;
            lpTimer = setTimeout(() => {
                if (navigator.vibrate) navigator.vibrate(60);
                card.dataset.isLp = "true";
                ppOpenFolderMenu(folderName);
            }, 600);
        };
        card.onmousemove = card.ontouchmove = (e) => {
            if (!lpTimer) return;
            const pos = e.touches ? e.touches[0] : e;
            if (Math.abs(pos.clientX - startX) > 10 || Math.abs(pos.clientY - startY) > 10) clearTimeout(lpTimer);
        };
        card.onmouseup = card.onmouseleave = card.ontouchend = () => { clearTimeout(lpTimer); setTimeout(() => delete card.dataset.isLp, 100); };

        card.onclick = () => {
            if (card.dataset.isLp) return;
            ppCurrentPath = (ppCurrentPath ? ppCurrentPath + '/' : "") + folderName;
            ppPushState();
            ppRenderGrid();
        };
        grid.appendChild(card);
    });

    if (currentItems.length === 0 && subfolders.size === 0) {
        grid.innerHTML += `<div style="text-align:center; padding:60px 20px; color:var(--text-secondary); opacity:0.6;">Folder is empty.</div>`;
        return;
    }

    const activeList = currentItems.filter(p => !p.meta.archived);
    const archivedList = currentItems.filter(p => p.meta.archived);

    activeList.forEach(p => ppRenderProjectCard(p, grid));
    if (archivedList.length > 0) {
        const archSection = document.createElement('div');
        archSection.className = 'pp-archive-section';
        archSection.innerHTML = `
            <details ${ppArchivedOpen ? 'open' : ''}>
                <summary class="pp-archive-header">
                    <span class="pp-archive-title">Archived Plans (${archivedList.length})</span>
                    <svg class="pp-archive-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </summary>
                <div class="pp-archive-grid" id="pp-archived-grid"></div>
            </details>
        `;
        grid.appendChild(archSection);
        
        const det = archSection.querySelector('details');
        det.ontoggle = () => { ppArchivedOpen = det.open; };

        const archGrid = archSection.querySelector('#pp-archived-grid');
        archivedList.forEach(p => {
    ppRenderProjectCard(p, archGrid);
    const card = archGrid.lastElementChild;
    card.classList.add('is-archived');
});}
}

window.ppOpenFolderMenu = function(folderName) {
    if (typeof window.openPicker !== "function") return;
    const relPath = (ppCurrentPath ? ppCurrentPath + '/' : "") + folderName;
    
    if (relPath === '_Knowledge') {
        window.sui.toast("Knowledge folder is protected");
        return;
    }
    
    const options = [
        { label: "✏️ Rename Folder", value: "rename" },
        { label: "📂 Move Folder", value: "move" },
        { label: "<span style='color:var(--danger)'>🗑️ Delete Folder (Must be empty)</span>", value: "delete" }
    ];

    window.openPicker(folderName, options, null, (val) => {
        if (val === "rename") ppPromptRenameFolder(folderName);
        if (val === "move") ppOpenMovePicker(relPath, true);
        if (val === "delete") {
            // Check if empty
            const hasContents = ppProjects.some(p => p.filename.startsWith(relPath + '/'));
            if (hasContents) {
                window.openConfirm("Folder Error", "Cannot delete folder: It still contains projects.", null, false, "OK", null);
            } else {
                window.openConfirm("Delete Folder?", `Are you sure you want to delete "${folderName}"?`, async () => {
                    const data = await window.sui.api('planner_delete_folder', { path: relPath }, { toast: "Folder Deleted" });
                    if (data && data.status === 'success') {
                        ppFetchProjects();
                    }
                }, true);
            }
        }
    });
};

window.ppPromptRenameFolder = function(oldName) {
    window.openInput("Rename Folder", "Enter new name:", oldName, async (newName) => {
        if (!newName || newName === oldName) return;
        const oldRelPath = (ppCurrentPath ? ppCurrentPath + '/' : "") + oldName;
        const newRelPath = (ppCurrentPath ? ppCurrentPath + '/' : "") + newName;
        
        const data = await window.sui.api('planner_move_item', { 
            old_rel_path: oldRelPath, 
            new_rel_path: newRelPath 
        }, { toast: "Folder Renamed" });
        
        if (data) {
            // Update Active Contexts for all files inside this folder
            const oldPrefix = oldRelPath + '/';
            const newPrefix = newRelPath + '/';
            let changed = false;
            ppActiveFilenames = ppActiveFilenames.map(f => {
                if (f.startsWith(oldPrefix)) {
                    changed = true;
                    return newPrefix + f.substring(oldPrefix.length);
                }
                return f;
            });
            if (changed) {
                await window.sui.api('planner_set_active', { filenames: ppActiveFilenames }, { toast: false });
            }
            ppFetchProjects();
        }
    });
};

window.ppOpenMovePicker = function(oldRelPath, isFolder = false) {
    if (oldRelPath === '_Knowledge') {
        window.sui.toast("Knowledge folder cannot be moved");
        return;
    }

    // 1. Start with Root
    const dirs = new Set([""]); 
    // 2. Add all explicitly created folders
    ppFolders.forEach(f => dirs.add(f));

    const options = Array.from(dirs).sort().map(d => ({ label: d === "" ? "ROOT" : "📂 " + d, value: d }));
    
    window.openPicker("Move to...", options, null, async (targetDir) => {
        const name = oldRelPath.split('/').pop();
        const newRelPath = (targetDir ? targetDir + '/' : "") + name;
        
        if (newRelPath === oldRelPath) return;

        const data = await window.sui.api('planner_move_item', { 
            old_rel_path: oldRelPath, 
            new_rel_path: newRelPath 
        }, { toast: false });
        
        if (data) {
            // Update Active Contexts if moved
            if (!isFolder) {
                ppActiveFilenames = ppActiveFilenames.map(f => f === oldRelPath ? newRelPath : f);
                await window.sui.api('planner_set_active', { filenames: ppActiveFilenames }, { toast: false });
            }
            ppFetchProjects();
            if (typeof window.ceRefreshStats === 'function') window.ceRefreshStats();
        }
    });
};

window.ppPromptCreateFolder = function() {
    window.openInput("New Folder", "Enter Folder Name:", "", (name) => {
        if (!name) return;
        const relPath = (ppCurrentPath ? ppCurrentPath + '/' : "") + name;
        window.sui.api('planner_create_folder', { path: relPath }, { toast: false }).then(() => ppFetchProjects());
    });
};

window.ppOpenProjectMenu = function(project) {
    if (typeof window.openPicker !== "function") return;

    const isActive = ppActiveFilenames.includes(project.filename);
    const isBlueprint = project.filename === '_Blueprint.md';
    const isKnowledge = project.filename.startsWith('_Knowledge/');

    const isArchived = project.meta.archived;
    const isPinned = project.meta.pinned;
    const options = [
        { label: "📖 Open Plan", value: "open" },
        { label: (isPinned ? "📍 Unpin Project" : "📌 Pin Project"), value: "toggle_pin" },
        { label: "🎯 Manage Project Scope", value: "scope" },
        { label: "📋 Copy MD as Context", value: "copy_context" },
        { label: "📂 Move to Folder", value: "move" },
        { label: "👯 Duplicate Project", value: "duplicate" },
        { label: (isArchived ? "📤 Unarchive Plan" : "📥 Archive Plan"), value: "toggle_archive" }
    ];

    if (!isKnowledge) {
        options.push({ label: "Legacy Features", type: "header" });
        options.push({ label: (isActive ? "📍 Clear Active Context" : "🎯 Set as Active Context"), value: "toggle_active" });
    }

    if (!isBlueprint) {
        options.push({ label: "<span style='color:var(--danger)'>🗑️ Delete Project</span>", value: "delete" });
    }

    window.openPicker(project.meta.title, options, null, (val) => {
        if (val === "open") ppOpenPreview(project);
        if (val === "toggle_pin") ppTogglePin(project.filename, !isPinned);
        if (val === "scope") ppOpenScopeStudio(project);
        if (val === "copy_context") ppCopyFileSource('app/data/projects/' + project.filename);
        if (val === "move") ppOpenMovePicker(project.filename, false);
        if (val === "duplicate") ppDuplicateProject(project);
        if (val === "toggle_active") ppToggleActive(project.filename, !isActive);
        if (val === "toggle_archive") ppToggleArchive(project.filename, !isArchived);
        if (val === "delete") ppDeleteProject(project);
    });
};

window.ppDuplicateProject = async function(project) {
    try {
        const data = await window.sui.api('planner_duplicate_project', { filename: project.filename }, { toast: "Project Duplicated" });
        if (data) {
            const t = document.getElementById("toast");
            if(t) { t.innerText = "Project Duplicated"; t.classList.add("show"); setTimeout(()=>t.classList.remove("show"), 2000); }
            ppFetchProjects();
        } else {
            window.openConfirm("Error", "Duplication failed: " + data.message, null, false, "OK", null);
        }
    } catch(e) { window.openConfirm("Error", "Network error", null, false, "OK", null); }
};

window.ppDeleteProject = async function(project) {
    if (typeof window.openConfirm === "function") {
        window.openConfirm(
            "Delete Project?",
            `Are you sure you want to permanently delete "${project.meta.title}"? This cannot be undone.`,
            () => ppExecuteDelete(project.filename),
            true
        );
    }
};

let ppLocalScope = new Set();
let ppScopeNavPath = "";
let ppSysMap = {};

function ppRenderScopeSuggestions() {
    const cont = document.getElementById('pp-scope-suggested-list');
    if (!cont || !ppSysMap) return;
    
    const acc = document.getElementById('pp-scope-suggestions-accordion');
    const header = acc ? acc.previousElementSibling : null;
    const titleEl = header ? header.querySelector('div') : null;

    const suggestions = new Set();
    ppLocalScope.forEach(file => {
        const deps = ppSysMap[file] || [];
        deps.forEach(dep => {
            if (!ppLocalScope.has(dep)) suggestions.add(dep);
        });
    });

    cont.innerHTML = "";
    if (titleEl) titleEl.innerText = `Suggested Dependencies ${suggestions.size > 0 ? '(' + suggestions.size + ')' : ''}`;

    if (suggestions.size === 0) {
        cont.innerHTML = `<span style="font-size:12px; color:var(--text-secondary); opacity:0.6;">No suggestions available.</span>`;
        if (acc && acc.classList.contains('open')) window.suiToggle('pp-scope-suggestions-accordion');
        return;
    }

    Array.from(suggestions).sort().forEach(f => {
        const badge = document.createElement('div');
        badge.className = 'pp-status-badge';
        badge.style.cssText = "background:var(--btn-bg); color:var(--primary); border:1px solid var(--primary); cursor:pointer; padding:4px 10px; font-family:monospace; text-transform:none; letter-spacing:0;";
        badge.innerHTML = `+ ${f.split('/').pop()} <span style="font-size:9px; opacity:0.6; margin-left:4px;">(dep)</span>`;
        badge.onclick = () => {
            ppLocalScope.add(f);
            ppRenderScopeActive();
            const q = document.getElementById('pp-scope-search')?.value || "";
            ppRenderScopeNavigator(q);
            if (window.sui && window.sui.haptic) window.sui.haptic('light');
        };
        cont.appendChild(badge);
    });
}

window.ppOpenScopeStudio = async function(project) {
    const data = await window.sui.api('planner_get_content', { filename: project.filename }, { toast: false });
    const currentScope = [];
    if (data && data.content) {
        const yamlMatch = data.content.match(/Scope:\s*\[(.*?)\]/i);
        if (yamlMatch && yamlMatch[1].trim()) {
            yamlMatch[1].split(',').forEach(f => currentScope.push(f.trim()));
        }
    }

    window.sui.openFileStudio({
        id: 'pp-scope',
        title: 'Project Scope',
        selection: currentScope,
        onSave: async (files) => {
            ppLocalScope = new Set(files);
            await ppSaveScope(project);
        }
    });
};

function ppRenderScopeActive() {
    const cont = document.getElementById('pp-scope-active-list');
    if (!cont) return;
    cont.innerHTML = "";
    if (ppLocalScope.size === 0) {
        cont.innerHTML = `<span style="font-size:12px; color:var(--text-secondary); opacity:0.6;">No files in scope.</span>`;
        return;
    }
    Array.from(ppLocalScope).sort().forEach(f => {
        const badge = document.createElement('div');
        badge.className = 'pp-status-badge';
        badge.style.cssText = "background:var(--primary); color:white; border:none; cursor:pointer; padding:4px 10px; font-family:monospace; text-transform:none; letter-spacing:0; user-select:none; -webkit-user-select:none;";
        badge.innerHTML = `${f} <span style="margin-left:6px; opacity:0.7;">×</span>`;
        badge.onpointerdown = (e) => ppStartLp(e, f);
        badge.onpointerup = badge.onpointerleave = () => ppEndLp();
        badge.onclick = () => {
            ppLocalScope.delete(f);
            ppRenderScopeActive();
            const q = document.getElementById('pp-scope-search')?.value || "";
            ppRenderScopeNavigator(q);
        };
        cont.appendChild(badge);
    });
    ppRenderScopeSuggestions();
}

async function ppRenderScopeNavigator(query = "") {
    const cont = document.getElementById('pp-scope-items');
    const pathLabel = document.getElementById('pp-scope-path');
    const navLabel = document.getElementById('pp-scope-nav-label');
    if (!cont) return;

    cont.innerHTML = `<div style="padding:40px; text-align:center;">${window.suiSpinner(30)}</div>`;
    
    if (query) {
        pathLabel.innerText = "Searching...";
        navLabel.innerText = "Search Results";
    } else {
        pathLabel.innerText = "/" + ppScopeNavPath;
        navLabel.innerText = "System Navigator";
    }

    try {
        const data = await window.sui.api('planner_get_sys_files', { path: ppScopeNavPath, q: query }, { toast: false });
        if (data && data.items) {
            cont.innerHTML = "";
            
            // Back Button
            if (ppScopeNavPath !== "") {
                const back = document.createElement('div');
                back.style.cssText = "padding:14px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:10px; cursor:pointer; background:rgba(0,0,0,0.02);";
                back.innerHTML = `<span data-sui-icon="chevron" data-sui-size="14" style="transform:rotate(90deg)"></span> <span style="font-size:14px; font-weight:700;">.. (Back)</span>`;
                back.onclick = () => {
                    const parts = ppScopeNavPath.split('/');
                    parts.pop();
                    ppScopeNavPath = parts.join('/');
                    ppRenderScopeNavigator();
                };
                cont.appendChild(back);
            }

            data.items.forEach(item => {
                const row = document.createElement('div');
                row.style.cssText = "padding:14px 20px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; cursor:pointer;";
                
                const isSelected = ppLocalScope.has(item.path);
                const icon = item.is_dir ? 'folder' : 'activity';
                
                let labelHtml = `<span style="font-size:14px; font-weight:${item.is_dir ? '800' : '500'}; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${item.name}</span>`;
                
                if (query && !item.is_dir) {
                    const dirPath = item.path.split('/').slice(0, -1).join('/');
                    labelHtml = `
                        <div style="display:flex; flex-direction:column; min-width:0;">
                            <span style="font-size:14px; font-weight:800; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${item.name}</span>
                            <span style="font-size:10px; color:var(--text-secondary); opacity:0.7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${dirPath || 'Root'}</span>
                        </div>
                    `;
                }

                row.innerHTML = `
                    <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:0; user-select:none; -webkit-user-select:none;">
                        <span data-sui-icon="${icon}" data-sui-size="${query ? 20 : 16}" data-sui-color="${item.is_dir ? 'var(--primary)' : 'var(--text-secondary)'}"></span>
                        ${labelHtml}
                    </div>
                    ${!item.is_dir ? `<div class="custom-checkbox ${isSelected ? 'checked' : ''}" style="width:20px; height:20px; flex-shrink:0;"></div>` : '<span data-sui-icon="chevron" data-sui-size="14" style="transform:rotate(-90deg); opacity:0.3;"></span>'}
                `;

                if (!item.is_dir) {
                    row.onpointerdown = (e) => ppStartLp(e, item.path);
                    row.onpointerup = row.onpointerleave = () => ppEndLp();
                }

                row.onclick = () => {
                    if (item.is_dir) {
                        ppScopeNavPath = item.path;
                        ppRenderScopeNavigator();
                    } else {
                        if (isSelected) ppLocalScope.delete(item.path);
                        else ppLocalScope.add(item.path);
                        ppRenderScopeActive();
                        const q = document.getElementById('pp-scope-search')?.value || "";
                        ppRenderScopeNavigator(q);
                        if (window.sui && window.sui.haptic) window.sui.haptic('light');
                    }
                };
                cont.appendChild(row);
            });
            window.suiHydrateIcons(cont);
        }
    } catch(e) { cont.innerHTML = `<div style="padding:20px; color:var(--danger); font-size:12px;">Failed to load directory.</div>`; }
}

let ppInitialScopeStr = "";

function ppHasScopeChanges() {
    const current = Array.from(ppLocalScope).sort().join(',');
    return current !== ppInitialScopeStr;
}

async function ppSaveScope(project) {
    await window.sui.api('planner_save_scope', { 
        filename: project.filename, 
        scope: Array.from(ppLocalScope) 
    }, { toast: "Scope Updated" });
    ppInitialScopeStr = Array.from(ppLocalScope).sort().join(',');
    ppFetchProjects();
    if (typeof window.ceRefreshStats === 'function') window.ceRefreshStats();
    
    // Refresh the preview if it is currently open
    if (document.getElementById('pp-markdown-body')) {
        ppFetchAndRender(project);
    }
}

async function ppExecuteDelete(filename) {
    const data = await window.sui.api('planner_delete_project', { filename: filename }, { toast: "Project Deleted" });
    if (data) {
        const t = document.getElementById("toast");
        if(t) { t.innerText = "Project Deleted"; t.classList.add("show"); setTimeout(()=>t.classList.remove("show"), 2000); }
        ppFetchProjects(); // Refresh grid
        if (typeof window.ceRefreshStats === 'function') window.ceRefreshStats();
    } else {
        window.openConfirm("Error", "Error: " + data.message, null, false, "OK", null);
    }
}

window.ppTogglePin = async function(filename, state) {
    const data = await window.sui.api('planner_toggle_pin', { 
        filename: filename, 
        state: state 
    }, { toast: state ? "Project Pinned" : "Project Unpinned" });
    if (data) {
        ppFetchProjects();
    }
};

window.ppRenderProjectCard = function(p, target) {
    const isActive = ppActiveFilenames.includes(p.filename);
    const isSelected = ppSelectedFiles.has(p.filename);
    const isBlueprint = p.filename === '_Blueprint.md';
    const isKnowledge = p.filename.startsWith('_Knowledge/');
    const isCompact = ppUiConfig.view_mode === 'list';
    const card = document.createElement('div');
    card.className = `pp-card ${isActive ? 'active-context' : ''} ${isSelected ? 'is-selected' : ''}`;
    
    const selectIndicator = `<div class="pp-card-select-indicator">${isSelected ? '<span data-sui-icon="check" data-sui-size="14" data-sui-stroke="4"></span>' : ''}</div>`;

    if (isCompact) {
        card.style.padding = "12px 16px";
        card.innerHTML = `
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:800; font-size:15px; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:6px;">
                        ${p.meta.pinned ? '<span style="font-size:12px;">📌</span>' : ''}
                        ${p.meta.title}
                    </div>
                    ${p.percent !== null ? `
                        <div class="pp-progress-track" style="height:4px; margin-top:4px; background:rgba(0,0,0,0.05);">
                            <div class="pp-progress-fill" style="width: ${p.percent}%"></div>
                        </div>
                    ` : ''}
                </div>
<div style="flex-shrink:0;">
    ${isKnowledge ? '' : window.suiSwitch('pp-act-' + p.filename.replace(/[^a-z0-9]/gi, '_'), isActive, `ppToggleActive('${p.filename}', this.checked)`, 'onclick="event.stopPropagation()"')}
</div>
        </div>`;
    } else {
        // Priority Color
        const prioCol = p.meta.priority.toLowerCase() === 'high' ? 'var(--danger)' : 'var(--primary)';

        card.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                <div style="flex:1;">
                    <div style="font-weight:800; font-size:18px; color:var(--text-primary); margin-bottom:4px; display:flex; align-items:center; gap:8px;">
                        ${p.meta.pinned ? '<span style="font-size:14px;">📌</span>' : ''}
                        ${p.meta.title}
                    </div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        ${isBlueprint ? '<span style="font-size:9px; font-weight:800; color:var(--primary-text); background:var(--primary); padding:2px 8px; border-radius:50px; text-transform:uppercase;">SYSTEM TEMPLATE</span>' : ''}
                        <span style="font-size:9px; font-weight:800; color:var(--primary-text); background:var(--text-secondary); padding:2px 8px; border-radius:50px; text-transform:uppercase;">${p.meta.status}</span>
                        <span style="font-size:9px; font-weight:800; color:${prioCol}; border:1px solid ${prioCol}; padding:1px 7px; border-radius:50px; text-transform:uppercase;">${p.meta.priority}</span>
                    </div>
                </div>
                <div style="text-align:right;">
    ${isKnowledge ? '' : window.suiSwitch('pp-act-' + p.filename.replace(/[^a-z0-9]/gi, '_'), isActive, `ppToggleActive('${p.filename}', this.checked)`, 'onclick="event.stopPropagation()"')}
    ${isKnowledge ? '' : '<div style="font-size:9px; color:var(--text-secondary); margin-top:6px; font-weight:700;">AI CONTEXT</div>'}
</div>
        </div>${selectIndicator}
            <div style="font-size:13px; color:var(--text-secondary); line-height:1.4; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;">${p.snippet}</div>
            
            ${p.percent !== null ? `
                <div class="pp-progress-container">
                    <div class="pp-progress-meta">
                        <span class="pp-progress-label">Project Completion</span>
                        <span class="pp-progress-val">${p.percent}%</span>
                    </div>
                    <div class="pp-progress-track">
                        <div class="pp-progress-fill" style="width: ${p.percent}%"></div>
                    </div>
                </div>
            ` : ''}

            <div style="margin-top:12px; font-size:10px; color:var(--text-secondary); opacity:0.5; font-weight:600; text-transform:uppercase;">Updated: ${p.meta.updated}</div>
        `;
    }
    
    // --- INTERACTION ENGINE ---
    let lpTimer, startX, startY;
    let isLongPress = false;

    card.oncontextmenu = (e) => { e.preventDefault(); e.stopPropagation(); };
    card.onmousedown = card.ontouchstart = (e) => {
        if (e.type === 'touchstart') e.stopPropagation(); 
        const pos = e.touches ? e.touches[0] : e;
        startX = pos.clientX; startY = pos.clientY;
        isLongPress = false;
        lpTimer = setTimeout(() => {
            isLongPress = true;
            if (navigator.vibrate) navigator.vibrate(60);
            ppOpenProjectMenu(p);
        }, 600);
    };

    card.onmousemove = card.ontouchmove = (e) => {
        if (!lpTimer) return;
        const pos = e.touches ? e.touches[0] : e;
        if (Math.abs(pos.clientX - startX) > 10 || Math.abs(pos.clientY - startY) > 10) clearTimeout(lpTimer);
    };

    card.onmouseup = card.onmouseleave = card.ontouchend = (e) => {
        clearTimeout(lpTimer);
    };

    card.onclick = (e) => {
        if (ppSelectMode) {
            ppToggleFileSelection(p.filename);
            return;
        }
        if (!isLongPress) ppOpenPreview(p);
    };

    target.appendChild(card);
};

window.ppUpdateActiveCount = function() {
    const btn = document.getElementById('pp-active-count-btn');
    if (!btn) return;
    const count = ppActiveFilenames.length;
    btn.innerHTML = `<span style="font-size:13px; font-weight:800;">${count}</span>`;
    btn.style.display = count > 0 ? 'flex' : 'none';
};

window.ppOpenActiveProjectsView = function() {
    const activeProjects = ppProjects.filter(p => ppActiveFilenames.includes(p.filename));
    const isCompact = ppUiConfig.view_mode === 'list';
    
    window.sui.openStudio({
        id: 'pp-active-list',
        title: 'Active Contexts',
        content: `<div id="pp-active-grid" style="display:grid; grid-template-columns: 1fr; gap:${isCompact ? '8px' : '16px'}; padding:16px;"></div>`,
        onSetup: (contentBox) => {
            const grid = contentBox.querySelector('#pp-active-grid');
            activeProjects.forEach(p => ppRenderProjectCard(p, grid));
        }
    });
};

window.ppToggleActive = async function(filename, enabled) {
    if (enabled) {
        if (!ppActiveFilenames.includes(filename)) ppActiveFilenames.push(filename);
    } else {
        ppActiveFilenames = ppActiveFilenames.filter(f => f !== filename);
    }
    
    await window.sui.api('planner_set_active', { filenames: ppActiveFilenames }, { toast: false });
    
    // Fetch fresh sorted data from server instead of just rendering locally
    ppFetchProjects();
    
    if (typeof window.ceRefreshStats === 'function') window.ceRefreshStats();
};

window.ppToggleArchive = async function(filename, state) {
    const data = await window.sui.api('planner_toggle_archive', { 
        filename: filename, 
        state: state 
    }, { toast: state ? "Plan Archived" : "Plan Restored" });
    if (data) {
        const t = document.getElementById("toast");
        if(t) { t.innerText = state ? "Plan Archived" : "Plan Restored"; t.classList.add("show"); setTimeout(()=>t.classList.remove("show"), 2000); }
        ppFetchProjects();
    }
};

window.ppPushState = function() {
    if (typeof aboEnabled !== "undefined" && aboEnabled) {
        history.pushState({ pp_folder_depth: true }, null, window.location.href);
    }
};

window.ppHandleBack = function() {
    if (ppCurrentPath === "") return false; // Let browser handle it
    const parts = ppCurrentPath.split('/');
    parts.pop();
    ppCurrentPath = parts.join('/');
    ppRenderGrid();
    return true; // Action taken
};

window.ppOpenPreview = function(project) {
    window._ppLastRequest = Date.now();
    const requestId = window._ppLastRequest;

    window.sui.openStudio({
        id: 'pp-preview',
        title: project.meta.title,
        content: `<div id="pp-markdown-body" style="font-family:system-ui, -apple-system, sans-serif; line-height:1.6; color:var(--text-primary);"><div style='text-align:center; padding:40px;'>Loading content...</div></div>`,
        onSetup: (contentBox, overlay) => {
            if (typeof aboEnabled !== "undefined" && aboEnabled) {
                history.pushState({ pp_preview_open: true }, null, window.location.href);
            }

            // Inject Refresh Button into Studio Header (with delay to prevent overwrite)
            setTimeout(() => {
                const actions = overlay.querySelector('.sui-studio-actions');
                if (actions && !actions.querySelector('#pp-refresh-btn')) {
                    const refreshBtn = document.createElement('button');
                    refreshBtn.id = 'pp-refresh-btn';
                    refreshBtn.className = 'icon-btn';
                    refreshBtn.title = "Refresh Plan";
                    refreshBtn.style.cssText = 'background:var(--btn-bg); border:none; width:32px; height:32px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer; transition: transform 0.2s; margin-right: 8px;';
                    
                    // Hardcoded SVG to guarantee visibility regardless of registry state
                    refreshBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:18px; height:18px;"><path d="M23 4v6h-6"></path><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>`;
                    
                    refreshBtn.onclick = (e) => {
                        e.stopPropagation();
                        refreshBtn.style.transform = 'rotate(180deg)';
                        setTimeout(() => refreshBtn.style.transform = 'rotate(0deg)', 300);
                        if (window.sui && window.sui.haptic) window.sui.haptic('light');
                        ppFetchAndRender(project, true);
                    };
                    actions.prepend(refreshBtn);
                }
            }, 100);

            ppFetchAndRender(project);
        }
    });
};

window.ppFetchAndRender = async function(project, isManualRefresh = false) {
    const body = document.getElementById("pp-markdown-body");
    const requestId = window._ppLastRequest;

    if (isManualRefresh && body) {
        body.style.opacity = '0.5';
        body.style.pointerEvents = 'none';
    }

    try {
        const data = await window.sui.api('planner_get_content', { filename: project.filename }, { toast: false });
        if (body) {
            body.style.opacity = '1';
            body.style.pointerEvents = 'auto';
        }
        if (data) {
            if (requestId !== window._ppLastRequest) return; // Abort: newer request exists
            ppCurrentRawMd = data.content;
            
            // 1. Extract Scope Metadata
            let scopeHtml = "";
            const yamlMatch = data.content.match(/---[\s\n]+([\s\S]+?)[\s\n]+---/);
            if (yamlMatch) {
                const yaml = yamlMatch[1];
                const scopeMatch = yaml.match(/Scope:\s*\[(.*?)\]/i);
                if (scopeMatch && scopeMatch[1].trim()) {
                    const files = scopeMatch[1].split(',').map(f => f.trim()).filter(f => f);
                    scopeHtml = `<div style="margin-bottom: 24px; background: rgba(0,0,0,0.02); padding: 12px; border-radius: 12px; border: 1px dashed var(--border-color);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px;">Project Scope</span>
                            <button id="pp-manage-scope-btn" class="text-btn" style="font-size: 10px; font-weight: 800; text-transform: uppercase; padding: 0; background: none; border: none; color: var(--primary); cursor: pointer;">Manage Scope</button>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 0;">
                            ${files.map(f => `<span class="pp-status-badge" style="background: var(--card-bg); color: var(--text-primary); border: 1px solid var(--border-color); font-family: monospace; font-size: 11px; text-transform: none; letter-spacing: 0;">${f}</span>`).join('')}
                        </div>
                        <div id="pp-audit-action-zone" style="border-top: 1px solid rgba(0,0,0,0.05);"></div>
                    </div>`;
                }
            }

            const cleanContent = data.content.replace(/---[\s\S]+?---/, '').trim();
            
            const tryRender = (attempts = 0) => {
                if (requestId !== window._ppLastRequest) return; // Abort
                if (typeof marked !== "undefined") {
                    let html = marked.parse(cleanContent);
    // Prepend Scope UI to the rendered HTML
    html = scopeHtml + html;
            
    // 1. Wrap Tables
    html = html.replace(/<table/g, '<div class="pp-table-wrapper"><table');
    html = html.replace(/<\/table>/g, '</table></div>');

    // 2. Keyword Badges
    const badgeMap = {
        'DONE': 'status-done', 'FIXED': 'status-done', 'COMPLETED': 'status-done',
        'TODO': 'status-todo', 'PENDING': 'status-todo', 'STAGED': 'status-todo',
        'WAITING': 'status-wait', 'HOLD': 'status-wait',
        'CRITICAL': 'status-crit', 'BUG': 'status-crit', 'ERROR': 'status-crit'
    };
    Object.entries(badgeMap).forEach(([key, cls]) => {
        const regex = new RegExp(`\\[${key}\\]`, 'g');
        html = html.replace(regex, `<span class="pp-status-badge ${cls}">${key}</span>`);
    });

    // 3. Accordion Logic (H2 Sections)
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = html;
    const finalFrag = document.createDocumentFragment();
            
    let currentDetails = null;
    let currentContent = null;

    Array.from(tempDiv.childNodes).forEach(node => {
        if (node.nodeName === 'H2') {
    // Start new section
    currentDetails = document.createElement('details');
    currentDetails.open = false; // Default collapsed for clean mobile view
    const summary = document.createElement('summary');summary.innerText = node.innerText;
            currentDetails.appendChild(summary);
                    
            currentContent = document.createElement('div');
            currentContent.className = 'details-content';
            currentDetails.appendChild(currentContent);
                    
            finalFrag.appendChild(currentDetails);
        } else if (currentContent) {
            currentContent.appendChild(node);
        } else {
            finalFrag.appendChild(node);
        }
    });

    body.innerHTML = "";
    body.appendChild(finalFrag);

    // Bind Checkboxes for Manual Sync
    body.querySelectorAll('input[type="checkbox"]').forEach((cb, idx) => {
        cb.removeAttribute('disabled'); // Ensure they are clickable
        cb.style.pointerEvents = 'auto';
        cb.onclick = async (e) => {
            // Prevent marked.js or other listeners from interfering
            e.stopPropagation();
            const isChecked = cb.checked;
            try {
                await window.sui.api('planner_toggle_task', {
                    filename: project.filename,
                    task_index: idx,
                    state: isChecked
                }, { toast: false });
                if (window.sui && window.sui.haptic) window.sui.haptic('light');
                
                // Update local snippet/progress if visible in grid
                ppFetchProjects(); 
            } catch (err) {
                cb.checked = !isChecked; // Revert on failure
                console.error("Failed to sync checkbox", err);
            }
        };
    });

    // Bind Manage Scope button
    const manageBtn = body.querySelector('#pp-manage-scope-btn');
    if (manageBtn) {
        manageBtn.onclick = (e) => {
            e.stopPropagation();
            ppOpenScopeStudio(project);
        };
    }

    // --- INJECT COPY BUTTONS ---
    body.querySelectorAll('pre').forEach(pre => {
        const container = document.createElement('div');
        container.className = 'pp-code-container';
        pre.parentNode.insertBefore(container, pre);
        container.appendChild(pre);

        const btn = document.createElement('button');
        btn.className = 'pp-code-copy-btn';
        btn.innerText = 'COPY';
        btn.onclick = (e) => {
            e.stopPropagation();
            const text = pre.innerText;
            navigator.clipboard.writeText(text).then(() => {
                btn.innerText = 'COPIED';
                if (window.sui && window.sui.haptic) window.sui.haptic('success');
                setTimeout(() => btn.innerText = 'COPY', 2000);
            });
        };
        container.appendChild(btn);
    });
                } else if (attempts < 20) {
                    // Library not ready yet, retry in 100ms
                    setTimeout(() => tryRender(attempts + 1), 100);
                } else {
                    body.innerText = cleanContent;
                }
            };
            tryRender();

// --- STABILIZED AUDIT INJECTION ---
// We perform the fetch AFTER the body content is definitely in the DOM
window.sui.api("planner_get_audit", { filename: project.filename }, { toast: false, errorToast: false })
    .then(d => {
        if (d && d.data) {
            // Use a short delay to ensure the browser has finished layout/paint
            setTimeout(() => {
                const zone = document.getElementById("pp-audit-action-zone");
                if (zone) {
                    zone.classList.add("reveal");
                    zone.innerHTML = "";
                    const btn = document.createElement("button");
                    btn.id = "pp-audit-trigger";
                    btn.className = "pp-status-badge status-todo";
                    btn.style.cssText = "cursor:pointer; padding:8px 16px; border:none; width:100%; display:block; font-size:11px;";
                    btn.innerHTML = "📋 OPEN REFACTOR CHECKLIST";
                    btn.onclick = (e) => { e.stopPropagation(); ppOpenAuditView(project.filename, d.data); };
                    zone.appendChild(btn);
                }
            }, 50);
        }
    });} else {
            body.innerText = "Error: " + data.message;
        }
    } catch(e) { body.innerText = "Error connecting to server."; }
};

window.ppOpenAuditView = function(filename, auditData) {
    const body = document.getElementById("pp-markdown-body");
    const originalContent = body.innerHTML;

    let auditProtocol = "";
    auditData.forEach(section => {
        auditProtocol += `#PATCH_ID: ${section.id}\n#ACTION: audit\n${section.file_filter ? '#FILE: ' + section.file_filter + '\n' : ''}#PATTERN:\n${section.pattern}\n#END\n\n`;
    });
    auditProtocol = auditProtocol.trim();


    
    const renderChecklist = () => {
        let total = 0; let done = 0;
        auditData.forEach(section => {
            section.matches.forEach(m => {
                total++;
                if (m.done) done++;
            });
        });
        const pct = total > 0 ? Math.round((done / total) * 100) : 0;

        const esc = (t) => t ? t.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;") : "";

        let html = `
            <div style="margin-bottom:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <button onclick="ppRestoreMarkdown()" class="text-btn" style="color:var(--primary); font-weight:700; display:flex; align-items:center; gap:6px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:16px;"><polyline points="15 18 9 12 15 6"></polyline></svg> Back to Plan
                    </button>
                    <button onclick="ppDeleteAudit('${filename}')" class="text-btn" style="color:var(--danger); font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px;">
                        Remove Checklist
                    </button>
                </div>

                <div class="pp-code-container" style="margin-bottom:15px;">
                    <pre style="font-size:10px; max-height:160px; overflow-y:auto; opacity:0.8; cursor:text;">${esc(auditProtocol)}</pre>
                    <div style="position:absolute; top:10px; right:10px; display:flex; gap:6px;">
                        <button onclick="ppCopyAuditProtocol()" class="pp-code-copy-btn" style="position:static; background:var(--btn-bg); color:var(--text-primary); border:1px solid var(--border-color);">COPY</button>
                        <button onclick="ppRunAudit()" class="pp-code-copy-btn" style="position:static; background:var(--primary); color:white; border:none; font-weight:900;">RUN AUDIT</button>
                    </div>
                </div>

                <div style="margin-bottom:15px; border-bottom:1px solid rgba(0,0,0,0.05); padding-bottom:12px; display:flex; gap:10px;">
                    <button onclick="ppCopyAuditJson('${filename}')" class="text-btn" style="flex:1; background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; border:1px solid var(--border-color);">
                        📋 Copy JSON
                    </button>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:8px;">
                    <h2 style="margin:0; font-size:22px; font-weight:900;">Refactor Checklist</h2>
                    <span style="font-family:monospace; font-weight:800; color:var(--primary);">${done}/${total} FIXED</span>
                </div>
                <div class="pp-progress-track"><div class="pp-progress-fill" style="width:${pct}%"></div></div>
            </div>
        `;

        auditData.forEach((section, sIdx) => {
            html += `
                <details open style="background:var(--bg-color); border:1px solid var(--border-color); border-radius:16px; margin-bottom:12px;">
                    <summary style="padding:14px 20px; font-size:13px; font-weight:800; background:rgba(0,0,0,0.02);">${section.id}</summary>
                    <div style="padding:10px 15px;">
            `;
            section.matches.forEach((m, mIdx) => {
                const checkId = `audit-${sIdx}-${mIdx}`;
                
                html += `
                    <div style="display:flex; align-items:flex-start; gap:12px; padding:12px; background:var(--card-bg); border-radius:12px; margin-bottom:8px; border:1px solid var(--border-color); ${m.done ? 'opacity:0.6' : ''}">
                        <input type="checkbox" id="${checkId}" ${m.done ? 'checked' : ''} 
                               style="width:20px; height:20px; margin-top:2px; accent-color:var(--primary);"
                               onchange="ppToggleAuditItem('${filename}', ${sIdx}, ${mIdx}, this.checked)">
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">${m.file} (Line ${m.line})</div>
                            <div style="font-family:monospace; font-size:10px; background:var(--input-bg); padding:10px; border-radius:8px; margin-top:6px; overflow-x:auto; line-height:1.4; border:1px solid var(--border-color);">
                                ${m.context && m.context.prev ? `<div style="opacity:0.35; white-space:pre;">${esc(m.context.prev)}</div>` : ''}
                                <div style="white-space:pre; color:var(--text-primary); font-weight:700; background:rgba(0,122,255,0.05);">${esc(m.content)}</div>
                                ${m.context && m.context.next ? `<div style="opacity:0.35; white-space:pre;">${esc(m.context.next)}</div>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            html += `</div></details>`;
        });
        body.innerHTML = html;
    };

    window.ppRestoreMarkdown = () => { body.innerHTML = originalContent; };

    window.ppCopyAuditJson = (fname) => {
        const wrapped = "```json\n" + JSON.stringify(auditData, null, 2) + "\n```";
        navigator.clipboard.writeText(wrapped).then(() => {
            const t = document.getElementById("toast");
            if(t) { t.innerText = "JSON Context Copied"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
            if (window.sui && window.sui.haptic) window.sui.haptic('success');
        });
    };

    window.ppCopyAuditProtocol = () => {
        const wrapped = "```text\n" + auditProtocol + "\n```";
        navigator.clipboard.writeText(wrapped).then(() => {
            const t = document.getElementById("toast");
            if(t) { t.innerText = "Audit Block Copied"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
            if (window.sui && window.sui.haptic) window.sui.haptic('success');
        });
    };

    window.ppRunAudit = () => {
        if (typeof caOpenStudio !== 'function') {
            window.openConfirm("Auditor Missing", "The Code Auditor plugin is required to run audits directly.", null, false, "OK", null);
            return;
        }
        caOpenStudio();
        setTimeout(() => {
            const inp = document.getElementById('ca-input');
            if (inp) {
                inp.value = auditProtocol;
                const runBtn = document.getElementById('ca-btn-run');
                if (runBtn) {
                    runBtn.classList.add('ca-highlight-pulse');
                    setTimeout(() => runBtn.classList.remove('ca-highlight-pulse'), 1600);
                }
            }
        }, 500);
    };
    
    window.ppDeleteAudit = (fname) => {
        const performDelete = async () => {
            const data = await window.sui.api("planner_delete_audit", { filename: fname }, { toast: "Checklist Removed" });
            if (data) {
                // 1. Return to the markdown view first
                ppRestoreMarkdown();
                
                // 2. Explicitly remove the trigger button and hide the zone from the restored DOM
                const trigger = document.getElementById("pp-audit-trigger");
                if (trigger) trigger.remove();
                const zone = document.getElementById("pp-audit-action-zone");
                if (zone) zone.style.display = "none";
                
                // 3. Feedback
                const t = document.getElementById("toast");
                if(t) { t.innerText = "Checklist Removed"; t.classList.remove("show"); void t.offsetWidth; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
            }
        };

        if (window.openConfirm) {
            window.openConfirm("Remove Checklist?", "This will permanently delete the linked audit report for this project. The project plan itself will not be affected.", performDelete, true);
        }
    };

    window.ppToggleAuditItem = async (fname, sIdx, mIdx, isDone) => {
        auditData[sIdx].matches[mIdx].done = isDone;
        await window.sui.api("planner_save_audit", { 
            filename: fname, 
            data: auditData 
        }, { toast: false });
        renderChecklist(); // Refresh UI
        if (window.sui && window.sui.haptic) window.sui.haptic(isDone ? 'success' : 'light');
    };

    renderChecklist();
};

let ppLpTimer = null;
window.ppStartLp = (e, path) => {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    const el = e.currentTarget;
    el.classList.add('pp-lp-active');
    
    ppLpTimer = setTimeout(() => {
        el.classList.remove('pp-lp-active');
        window.sui.haptic('medium');
        
        const ext = path.split('.').pop().toLowerCase();
        const isSupported = window.fsSupportedExtensions && window.fsSupportedExtensions.includes(ext);

        const options = [
            { label: "📋 Copy Source Code", value: "copy" }
        ];

        if (isSupported && typeof window.fsOpen === 'function') {
            options.unshift({ label: "📂 Open in File Studio", value: "studio" });
        }

        window.openPicker(`File: ${path.split('/').pop()}`, options, null, (val) => {
            if (val === "studio") window.fsOpen(path);
            if (val === "copy") ppCopyFileSource(path);
        });
    }, 600);
};

window.ppEndLp = () => {
    clearTimeout(ppLpTimer);
    document.querySelectorAll('.pp-lp-active').forEach(el => el.classList.remove('pp-lp-active'));
};

async function ppCopyFileSource(fileName) {
    try {
        const data = await window.sui.api("cp_preview", {
            patch_count: 1,
            p_0_file: fileName,
            p_0_action: "export",
            p_0_find: "",
            p_0_replace: "",
            p_0_match: 1
        }, { toast: "Fetching source..." });

        if (data && data.results && data.results[0].export_block) {
            const block = data.results[0].export_block;
            navigator.clipboard.writeText(block);
            
            const t = document.getElementById("toast");
            if (t) {
                t.innerText = "Source Copied to Clipboard";
                t.classList.add("show");
                setTimeout(() => t.classList.remove("show"), 2000);
            }
        }
    } catch(e) { console.error("Export failed", e); }
}

window.ppClosePreview = function() {
    window.sui.closeStudio('pp-preview');
};



window.ppSafeUninstall = function() {
    window.openConfirm("Safe Uninstall", "This will disable the Planner plugin and stop project plans from being injected into AI exports. Your .md files will remain safe in data/projects/.\n\nProceed?", async () => {
        await window.sui.api('planner_safe_uninstall', {}, { toast: false });
        location.reload();
    });
};
JS;