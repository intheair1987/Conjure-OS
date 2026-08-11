<?php
require_once dirname(__DIR__) . '/paths.php';
include_once CJOS_PATH_DATA . '/firewall-private.php';
include_once CJOS_PATH_DATA . '/firewall.php';
// ==============================================================================
// 0. TIMEZONE HANDLER
// ==============================================================================
$timezone = 'UTC'; 
$tz_file = CJOS_PATH_DATA . '/timezone.json';
if (file_exists($tz_file)) {
    $tz_data = json_decode(file_get_contents($tz_file), true);
    if (!empty($tz_data['mode']) && $tz_data['mode'] === 'Manual' && !empty($tz_data['manual_value'])) {
        $timezone = $tz_data['manual_value'];
    } elseif (!empty($tz_data['detected_value'])) {
        $timezone = $tz_data['detected_value'];
    }
}
if (isset($_COOKIE['cjos_manual_timezone']) && !empty($_COOKIE['cjos_manual_timezone']) && $_COOKIE['cjos_manual_timezone'] !== 'Auto') {
    $timezone = $_COOKIE['cjos_manual_timezone'];
}
try { date_default_timezone_set($timezone); } catch(Exception $e) { date_default_timezone_set('UTC'); }

// ==============================================================================
// SERVER LOGIC
// ==============================================================================
set_time_limit(0); 
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');

$base_dir = CJOS_PATH_APP;
$data_dir = CJOS_PATH_DATA;
$root_dir = CJOS_PATH_ROOT;
$rec_dir = CJOS_PATH_STORAGE . '/audio';
$trans_dir = CJOS_PATH_STORAGE . '/text';
$db_file = CJOS_PATH_ROOT . '/conjure.db';
$rel_rec_path = str_replace(CJOS_PATH_ROOT . '/', '', $rec_dir);
$rel_trans_path = str_replace(CJOS_PATH_ROOT . '/', '', $trans_dir);

// --- DEMO MODE INTERCEPT (FILE BASED) ---
$demo_state_file = $data_dir . '/demo-mode-private.json';
$is_demo_mode = false;
if (file_exists($demo_state_file)) {
    $dm_state = json_decode(file_get_contents($demo_state_file), true);
    if (isset($dm_state['enabled'])) $is_demo_mode = (bool)$dm_state['enabled'];
} else {
    // Fresh installation default: enable Demo Mode automatically and persist state
    $is_demo_mode = true;
    @file_put_contents($demo_state_file, json_encode(['enabled' => true]));
}

if ($is_demo_mode) {
    $demo_dir = $data_dir . '/demo';
    if (!is_dir($demo_dir)) mkdir($demo_dir, 0777, true);
    $db_file = $demo_dir . '/demo.db';
    $rec_dir = $demo_dir . '/audio';
    $trans_dir = $demo_dir . '/text';
    $rel_rec_path = str_replace(CJOS_PATH_ROOT . '/', '', $rec_dir);
    $rel_trans_path = str_replace(CJOS_PATH_ROOT . '/', '', $trans_dir);
}

if (!is_dir($rec_dir)) mkdir($rec_dir, 0777, true);
if (!is_dir($trans_dir)) mkdir($trans_dir, 0777, true);
if (!is_dir($data_dir)) mkdir($data_dir, 0777, true);

// --- ACTION: SCAN APPMAKER APPS ---
if ((isset($_GET['action']) && $_GET['action'] === 'scan_appmaker_apps') || (isset($_POST['action']) && $_POST['action'] === 'scan_appmaker_apps')) {
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    while (ob_get_level()) @ob_end_clean();
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: *');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    $apps_dir = CJOS_PATH_APPS;
    $apps_list = [];

    if (is_dir($apps_dir)) {
        // Default to secure HTTPS context for WebWrappers
        $protocol = "https";
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'off') {
            $protocol = "http";
        }
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
        if (strpos($host, ':') === false) {
            $port = $_SERVER['SERVER_PORT'] ?? $_SERVER['HTTP_X_FORWARDED_PORT'] ?? null;
            if ($port && (($protocol === 'https' && $port != 443) || ($protocol === 'http' && $port != 80))) {
                $host .= ':' . $port;
            }
        }
        $base_url = $protocol . "://" . $host;

        $folders = glob($apps_dir . '/*', GLOB_ONLYDIR);
        foreach ($folders as $folder_path) {
            $folder_name = basename($folder_path);
            if (strpos($folder_name, '.') === 0) continue;

            $manifest_file = $folder_path . '/manifest.json';
            $index_file = $folder_path . '/index.php';
            $html_index = $folder_path . '/index.html';

            if (!file_exists($manifest_file) && !file_exists($index_file) && !file_exists($html_index)) {
                continue;
            }

            $manifest_data = file_exists($manifest_file) ? json_decode(file_get_contents($manifest_file), true) : [];
            $app_name = $manifest_data['name'] ?? $folder_name;
            $app_color = $manifest_data['color'] ?? '#6366f1';

            $base64_icon = "";
            $svg_file = $folder_path . '/icon.svg';
            $png_file = $folder_path . '/icon.png';

            if (file_exists($svg_file)) {
                $base64_icon = base64_encode(file_get_contents($svg_file));
            } elseif (file_exists($png_file)) {
                $base64_icon = base64_encode(file_get_contents($png_file));
            }

            $sanitized_pkg = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $folder_name));
            $entry_point = file_exists($index_file) ? 'index.php' : 'index.html';

            $apps_list[] = [
                'source' => 'appmaker',
                'folder' => $folder_name,
                'appName' => $app_name,
                'color' => $app_color,
                'base64Icon' => $base64_icon,
                'targetUrl' => $base_url . '/apps/' . $folder_name . '/' . $entry_point,
                'pkgName' => 'com.wrapper.appmaker.' . $sanitized_pkg
            ];
        }

        // --- SCAN DEPLOYED ORBIT INSTANCES ---
        $orbit_db_file = $apps_dir . '/orbit/app.db';
        if (file_exists($orbit_db_file)) {
            try {
                $orbit_db = new PDO("sqlite:$orbit_db_file");
                $orbit_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $orbit_db->query("SELECT id, name, template_name, subdomain, is_home FROM instances ORDER BY name ASC");
                $instances = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($instances as $inst) {
                    $inst_id = $inst['id'];
                    $inst_name = $inst['name'];
                    $template = $inst['template_name'] ?? '';
                    $subdomain = trim($inst['subdomain'] ?? '');

                    if (empty($subdomain)) continue;

                    $target_url = $subdomain;
                    if (!preg_match('~^https?://~i', $target_url)) {
                        $target_url = 'https://' . $target_url;
                    }

                    $base64_icon = "";
                    $app_color = "#38bdf8";
                    if (!empty($template)) {
                        $template_dir = $apps_dir . '/' . $template;
                        $template_manifest = $template_dir . '/manifest.json';
                        if (file_exists($template_manifest)) {
                            $m_data = json_decode(file_get_contents($template_manifest), true);
                            if (!empty($m_data['color'])) $app_color = $m_data['color'];
                        }

                        $svg_file = $template_dir . '/icon.svg';
                        $png_file = $template_dir . '/icon.png';
                        if (file_exists($svg_file)) {
                            $base64_icon = base64_encode(file_get_contents($svg_file));
                        } elseif (file_exists($png_file)) {
                            $base64_icon = base64_encode(file_get_contents($png_file));
                        }
                    }

                    // Extract clean subdomain slug (e.g. "atlastrack.domain.com" -> "atlastrack")
                    $parsed_host = parse_url($target_url, PHP_URL_HOST) ?: $subdomain;
                    $host_parts = explode('.', $parsed_host);
                    $sub_slug = (count($host_parts) > 1) ? $host_parts[0] : $parsed_host;
                    $sanitized_sub = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $sub_slug));

                    if (empty($sanitized_sub) || $sanitized_sub === 'https' || $sanitized_sub === 'http') {
                        $sanitized_sub = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $inst_name));
                    }

                    $pkg_name = 'com.wrapper.orbit.' . $sanitized_sub;

                    $apps_list[] = [
                        'source' => 'orbit',
                        'id' => $inst_id,
                        'folder' => 'orbit_' . $sanitized_sub,
                        'template' => $template,
                        'appName' => $inst_name,
                        'subdomain' => $subdomain,
                        'color' => $app_color,
                        'base64Icon' => $base64_icon,
                        'targetUrl' => $target_url,
                        'pkgName' => $pkg_name
                    ];
                }
            } catch (Throwable $e) {}
        }
    }

    echo json_encode(['status' => 'success', 'apps' => $apps_list]);
    exit;
}

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // SQLite Concurrency & I/O Optimization
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=10000;");

    $db->exec("CREATE TABLE IF NOT EXISTS logs (
        id TEXT PRIMARY KEY, date_display TEXT, audio_path TEXT, transcription TEXT, timestamp INTEGER
    )");

    // Performance Indexes for Instant Deletions, Sorting, and Joins (13,000+ Notes Scale)
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON logs(timestamp DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_sugg_log ON ai_suggestions(log_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_audit_log ON ai_audit_log(log_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_folder_map_log ON folder_map(log_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_stack_members_log ON stack_members(log_id)");
    } catch (Throwable $e) {}
} catch (PDOException $e) { die("DB Error"); }

// --- ACTION: UI STATE SAVE ---
if (isset($_POST['action']) && $_POST['action'] === 'ui_save_state') {
    header('Content-Type: application/json');
    $uiFile = $data_dir . '/ui-config.json';
    $data = file_exists($uiFile) ? json_decode(file_get_contents($uiFile), true) : [];
    
    $cat = $_POST['category'] ?? ''; 
    $key = $_POST['key'] ?? '';
    $val = $_POST['val'] ?? '';
    
    if ($cat && $key) {
        if (!isset($data[$cat])) $data[$cat] = [];
        $data[$cat][$key] = $val;
        file_put_contents($uiFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    echo json_encode(['status' => 'success']);
    exit;
}



// --- ACTION: BATCH DELETE LOGS ---
if ((isset($_GET['action']) && $_GET['action'] === 'delete_batch') || (isset($_POST['action']) && $_POST['action'] === 'delete_batch')) {
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    while (ob_get_level()) @ob_end_clean();
    header('Content-Type: application/json');
    $rawIds = $_POST['ids'] ?? $_GET['ids'] ?? '[]';
    $ids = is_string($rawIds) ? json_decode($rawIds, true) : $rawIds;
    if (!is_array($ids)) $ids = [$rawIds];
    $ids = array_values(array_filter(array_map('trim', $ids)));

    if (empty($ids)) {
        echo json_encode(['status' => 'error', 'message' => 'No IDs provided']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // 1. Unlink recording & text files via direct path checks (0ms inode lookups, NO glob directory scans)
    $extensions = ['webm', 'm4a', 'mp4', 'wav', 'mp3', 'aac', 'ogg'];
    try {
        $stmt = $db->prepare("SELECT id, audio_path FROM logs WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (!empty($row['audio_path'])) {
                $fullAudio = $root_dir . '/' . ltrim($row['audio_path'], '/');
                if (file_exists($fullAudio)) @unlink($fullAudio);
            }
            $logId = $row['id'];
            foreach ($extensions as $ext) {
                $f = "$rec_dir/$logId.$ext";
                if (file_exists($f)) @unlink($f);
            }
            $txtFile = "$trans_dir/$logId.txt";
            if (file_exists($txtFile)) @unlink($txtFile);
        }
    } catch (Exception $e) {}

    // 2. Single Transactional Commit for Instant Sub-Millisecond Database Deletion
    try {
        $db->beginTransaction();

        $stmtLogs = $db->prepare("DELETE FROM logs WHERE id IN ($placeholders)");
        $stmtLogs->execute($ids);

        $stmtSugg = $db->prepare("DELETE FROM ai_suggestions WHERE log_id IN ($placeholders)");
        $stmtSugg->execute($ids);

        $stmtAudit = $db->prepare("DELETE FROM ai_audit_log WHERE log_id IN ($placeholders)");
        $stmtAudit->execute($ids);

        try {
            $stmtFm = $db->prepare("DELETE FROM folder_map WHERE log_id IN ($placeholders)");
            $stmtFm->execute($ids);
        } catch (Exception $e) {}

        try {
            $stmtSm = $db->prepare("DELETE FROM stack_members WHERE log_id IN ($placeholders)");
            $stmtSm->execute($ids);
        } catch (Exception $e) {}

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }

    echo json_encode(['status' => 'success', 'deleted_count' => count($ids)]);
    exit;
}

// --- ACTION: DELETE SINGLE LOG ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // 1. Delete exact audio path recorded in DB
    try {
        $stmt = $db->prepare("SELECT audio_path FROM logs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $logRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($logRow && !empty($logRow['audio_path'])) {
            $fullAudio = $root_dir . '/' . ltrim($logRow['audio_path'], '/');
            if (file_exists($fullAudio)) @unlink($fullAudio);
        }
    } catch(Exception $e) {}

    // 2. Direct path checks (0ms inode lookups, NO glob directory scans)
    $extensions = ['webm', 'm4a', 'mp4', 'wav', 'mp3', 'aac', 'ogg'];
    foreach ($extensions as $ext) {
        $f = "$rec_dir/$id.$ext";
        if (file_exists($f)) @unlink($f);
    }
    $txtFile = "$trans_dir/$id.txt";
    if (file_exists($txtFile)) @unlink($txtFile);

    $db->prepare("DELETE FROM logs WHERE id = :id")->execute([':id' => $id]);
    
    // Cleanup AI records
    $db->prepare("DELETE FROM ai_suggestions WHERE log_id = :id")->execute([':id' => $id]);
    $db->prepare("DELETE FROM ai_audit_log WHERE log_id = :id")->execute([':id' => $id]);

    // Cleanup Plugin Mappings
    try { $db->prepare("DELETE FROM folder_map WHERE log_id = :id")->execute([':id' => $id]); } catch(Exception $e) {}
    try { $db->prepare("DELETE FROM stack_members WHERE log_id = :id")->execute([':id' => $id]); } catch(Exception $e) {}
    header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
}

// --- ACTION: UPLOAD ONLY ---
if (isset($_POST['action']) && $_POST['action'] === 'upload_only') {
    header('Content-Type: application/json');
    if (empty($_FILES['audio'])) { echo json_encode(['status' => 'error', 'message' => 'No audio file']); exit; }

    $timestamp = time();
    $id = date('Ymd_His', $timestamp); 
    $ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'webm';
    $filename = $id . '.' . $ext;
    
    if (!move_uploaded_file($_FILES['audio']['tmp_name'], "$rec_dir/$filename")) { 
        echo json_encode(['status' => 'error', 'message' => 'Save failed']); exit; 
    }

    $date_display = date('Y-m-d H:i:s', $timestamp);
    $db->prepare("INSERT INTO logs (id, date_display, audio_path, transcription, timestamp) VALUES (:id, :date, :audio, :text, :ts)")
       ->execute([':id' => $id, ':date' => $date_display, ':audio' => "$rel_rec_path/$filename", ':text' => "(Pending Transcription...)", ':ts' => $timestamp]);

    echo json_encode(['status' => 'success', 'id' => $id]); exit;
}
?>