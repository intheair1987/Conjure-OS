<?php
// Whiteboard App - Standalone AppMaker Module
$db_path = __DIR__ . '/app.db';
$db = new PDO("sqlite:$db_path");

// --- API REGISTRY ---
$WB_API = [];
/**
 * Registers a server-side action for the Whiteboard API.
 * @param string $action The action name sent via POST
 * @param callable $callback Function receiving ($db)
 */
function wb_register_api($action, $callback) {
    global $WB_API;
    $WB_API[$action] = $callback;
}

$db->exec("CREATE TABLE IF NOT EXISTS drawings (id INTEGER PRIMARY KEY, canvas_id INTEGER DEFAULT 1, data TEXT, updated_at INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS stickers (id INTEGER PRIMARY KEY, data TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS presets (id INTEGER PRIMARY KEY, size REAL, type TEXT DEFAULT 'draw', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE(size, type))");
$db->exec("CREATE TABLE IF NOT EXISTS folders (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, parent_id INTEGER DEFAULT 0, created_at INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS canvases (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, folder_id INTEGER DEFAULT 0, thumbnail TEXT, created_at INTEGER, updated_at INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS assets (hash TEXT PRIMARY KEY, data TEXT, created_at INTEGER)");

// Add indexes for high-speed lookups
$db->exec("CREATE INDEX IF NOT EXISTS idx_folders_parent_id ON folders(parent_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_canvases_folder_id ON canvases(folder_id)");

// Workspace Migration
$cols = $db->query("PRAGMA table_info(canvases)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('workspaces', $cols)) {
    $db->exec("ALTER TABLE canvases ADD COLUMN workspaces TEXT");
}
if (!in_array('viewport', $cols)) {
    $db->exec("ALTER TABLE canvases ADD COLUMN viewport TEXT");
}

// Filesystem Asset Initialization
$assets_dir = __DIR__ . '/data/assets';
if (!is_dir($assets_dir)) mkdir($assets_dir, 0777, true);

/**
 * Scans the assets directory to find a file matching a specific hash.
 * Used for auto-relinking broken paths.
 */
function wb_find_file_by_hash($hash) {
    $assets_dir = __DIR__ . '/data/assets';
    if (!is_dir($assets_dir)) return null;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets_dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename()[0] !== '.') {
            if (sha1_file($file->getPathname()) === $hash) {
                $normalized_root = str_replace('\\', '/', $assets_dir);
                $path = str_replace('\\', '/', $file->getPathname());
                return ltrim(str_replace($normalized_root, '', $path), '/');
            }
        }
    }
    return null;
}



function wb_sync_filesystem_to_db($db) {
    $assets_dir = __DIR__ . '/data/assets';
    if (!is_dir($assets_dir)) return;

    $valid_paths =[];
    $normalized_assets_dir = str_replace('\\', '/', $assets_dir);

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets_dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename()[0] !== '.') {
            $path = str_replace('\\', '/', $file->getPathname());
            $rel_path = ltrim(str_replace($normalized_assets_dir, '', $path), '/');
            $valid_paths[$rel_path] = true;
            
            // Fast Check: Use mtime and size to avoid rehashing unchanged files
            $mtime = $file->getMTime();
            $size = $file->getSize();
            $fast_id = md5($rel_path . $mtime . $size);
            
            static $known_files = null;
            if ($known_files === null) {
                $known_files =[];
                $stmt = $db->query("SELECT hash, data FROM assets WHERE data LIKE '%\"fast_id\"%'");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $meta = json_decode($row['data'], true);
                    if (isset($meta['fast_id'])) $known_files[$meta['fast_id']] = $row['hash'];
                }
            }

            $hash = null;
            if (!isset($known_files[$fast_id])) {
                $hash = sha1_file($path);
                
                // PURGE GHOST HASHES
                $stmtGhost = $db->query("SELECT hash, data FROM assets WHERE data LIKE '%\"path\":\"" . $rel_path . "\"%'");
                while ($rowG = $stmtGhost->fetch(PDO::FETCH_ASSOC)) {
                    if ($rowG['hash'] !== $hash) {
                        $old_meta = json_decode($rowG['data'], true);
                        if (isset($old_meta['path']) && $old_meta['path'] === $rel_path) {
                            unset($old_meta['path']);
                            unset($old_meta['fast_id']);
                            $db->prepare("UPDATE assets SET data = ? WHERE hash = ?")->execute([json_encode($old_meta), $rowG['hash']]);
                        }
                    }
                }

                $stmt = $db->prepare("SELECT hash FROM assets WHERE hash = ?");
                $stmt->execute([$hash]);
                if (!$stmt->fetch()) {
                    $meta = json_encode(['path' => $rel_path, 'fast_id' => $fast_id]);
                    $stmt = $db->prepare("INSERT INTO assets (hash, data, created_at) VALUES (?, ?, ?)");
                    $stmt->execute([$hash, $meta, time()]);
                } else {
                    $meta = json_encode(['path' => $rel_path, 'fast_id' => $fast_id]);
                    $db->prepare("UPDATE assets SET data = ? WHERE hash = ?")->execute([$meta, $hash]);
                }
                $known_files[$fast_id] = $hash;
            } else {
                $hash = $known_files[$fast_id];
            }

            // Vault retired: No longer copying files to hash-named storage.
        }
    }

    // Cleanup stale paths from DB to prevent ghost files and thumbnail conflicts offline
    $stmt = $db->query("SELECT hash, data FROM assets WHERE data LIKE '%\"path\"%'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $meta = json_decode($row['data'], true);
        if (isset($meta['path']) && !isset($valid_paths[$meta['path']])) {
            unset($meta['path']);
            unset($meta['fast_id']); // Also clear fast_id since it's no longer valid
            $db->prepare("UPDATE assets SET data = ? WHERE hash = ?")->execute([json_encode($meta), $row['hash']]);
        }
    }
}

// Multi-Canvas Migration: Add canvas_id to drawings
$cols = $db->query("PRAGMA table_info(drawings)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('canvas_id', $cols)) {
    $db->exec("ALTER TABLE drawings ADD COLUMN canvas_id INTEGER DEFAULT 1");
    $db->exec("INSERT OR IGNORE INTO canvases (id, name, folder_id, created_at, updated_at) VALUES (1, 'Default Canvas', 0, " . time() . ", " . time() . ")");
}
$db->exec("CREATE INDEX IF NOT EXISTS idx_drawings_canvas_id ON drawings(canvas_id)");

// 2. Idempotent Migration: Add 'type' column only if it's missing
$cols = $db->query("PRAGMA table_info(presets)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('type', $cols)) {
    $db->exec("ALTER TABLE presets ADD COLUMN type TEXT DEFAULT 'draw'");
}
if (!in_array('name', $cols)) {
    $db->exec("ALTER TABLE presets ADD COLUMN name TEXT");
}
if (!in_array('data', $cols)) {
    $db->exec("ALTER TABLE presets ADD COLUMN data TEXT");
}
if (!in_array('is_hidden', $cols)) {
    $db->exec("ALTER TABLE presets ADD COLUMN is_hidden INTEGER DEFAULT 0");
}
$db->exec("CREATE INDEX IF NOT EXISTS idx_presets_type_hidden ON presets(type, is_hidden)");


// --- MODULE LOADER (Logic Only) ---
require_once __DIR__ . '/modules/backup.php';
require_once __DIR__ . '/modules/object_library.php';

// Legacy Handlers (to be moved to registry)
wb_handle_backup($db);

// Handle Actions
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. Route to Registered Modules
    if (isset($WB_API[$action])) {
        $WB_API[$action]($db);
        exit;
    }

    // 2. Legacy Dispatcher (Migration in progress)
    if ($action === 'save_pos_preset') {
        $name = trim($_POST['name'] ?? '');
        $rawData = $_POST['data'] ?? '';
        $components = json_decode($rawData, true);

        if ($name === '' || !is_array($components) || count($components) === 0 || count($components) > 24) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid POS preset']);
            exit;
        }

        $safeComponents = [];
        foreach ($components as $component) {
            if (!is_array($component)) continue;
            $kind = $component['kind'] ?? '';
            if (!in_array($kind, ['label', 'blank'], true)) continue;

            $value = trim((string)($component['value'] ?? ''));
            if ($kind === 'label' && ($value === '' || mb_strlen($value) > 24)) continue;
            if ($kind === 'blank') $value = '';

            $safeComponents[] = [
                'kind' => $kind,
                'value' => $value
            ];
        }

        if (count($safeComponents) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Preset has no valid components']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO presets (size, type, name, data, is_hidden) VALUES (?, 'pos', ?, ?, 0)");
        $stmt->execute([0, $name, json_encode($safeComponents, JSON_UNESCAPED_UNICODE)]);
        echo json_encode(['status' => 'success', 'id' => (int)$db->lastInsertId()]);
        exit;
    }
    if ($action === 'get_pos_presets') {
        $includeHidden = !empty($_POST['include_hidden']);
        $sql = "SELECT id, name, data, is_hidden, created_at FROM presets WHERE type = 'pos'";
        if (!$includeHidden) $sql .= " AND is_hidden = 0";
        $sql .= " ORDER BY created_at ASC, id ASC";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $decoded = json_decode($row['data'] ?? '[]', true);
            $row['components'] = is_array($decoded) ? $decoded : [];
            unset($row['data']);
        }
        echo json_encode(['status' => 'success', 'presets' => $rows]);
        exit;
    }
    if ($action === 'rename_pos_preset') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));

        if ($id <= 0 || $name === '' || mb_strlen($name) > 80) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid POS preset name']);
            exit;
        }

        $stmt = $db->prepare("UPDATE presets SET name = ? WHERE id = ? AND type = 'pos'");
        $stmt->execute([$name, $id]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($action === 'delete_pos_preset') {
        $stmt = $db->prepare("DELETE FROM presets WHERE id = ? AND type = 'pos'");
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($action === 'toggle_pos_preset') {
        $stmt = $db->prepare("UPDATE presets SET is_hidden = CASE WHEN is_hidden = 1 THEN 0 ELSE 1 END WHERE id = ? AND type = 'pos'");
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($action === 'save_preset') {
        $type = $_POST['type'] ?? 'draw';
        // Use OR IGNORE to handle the UNIQUE(size, type) constraint gracefully
        $stmt = $db->prepare("INSERT OR IGNORE INTO presets (size, type) VALUES (?, ?)");
        $stmt->execute([$_POST['size'], $type]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'get_presets') {
        $type = $_POST['type'] ?? 'draw';
        $stmt = $db->prepare("SELECT * FROM presets WHERE type = ? ORDER BY size ASC");
        $stmt->execute([$type]);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'presets' => $list]);
        exit;
    }
    if ($_POST['action'] === 'delete_preset') {
        $stmt = $db->prepare("DELETE FROM presets WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'save_sticker') {
        $stmt = $db->prepare("INSERT INTO stickers (data) VALUES (?)");
        $stmt->execute([$_POST['data']]);
        echo json_encode(['status' => 'success', 'id' => $db->lastInsertId()]);
        exit;
    }
    if ($_POST['action'] === 'get_stickers') {
        $list = $db->query("SELECT * FROM stickers ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'stickers' => $list]);
        exit;
    }
    if ($_POST['action'] === 'delete_sticker') {
        $stmt = $db->prepare("DELETE FROM stickers WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'clear_stickers') {
        $db->exec("DELETE FROM stickers");
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'get_gallery') {
        $fid = (int)($_POST['folder_id'] ?? 0);
        $folders = $db->prepare("SELECT * FROM folders WHERE parent_id = ? ORDER BY name ASC");
        $folders->execute([$fid]);
        $canvases = $db->prepare("SELECT id, name, thumbnail, updated_at FROM canvases WHERE folder_id = ? ORDER BY updated_at DESC");
        $canvases->execute([$fid]);

        $currentFolder = null;
        if ($fid > 0) {
            $cf = $db->prepare("SELECT id, name, parent_id FROM folders WHERE id = ?");
            $cf->execute([$fid]);
            $currentFolder = $cf->fetch(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'status' => 'success',
            'folders' => $folders->fetchAll(PDO::FETCH_ASSOC),
            'canvases' => $canvases->fetchAll(PDO::FETCH_ASSOC),
            'current_folder' => $currentFolder
        ]);
        exit;
    }
    if ($_POST['action'] === 'get_all_folders') {
        $folders = $db->query("SELECT id, name FROM folders ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'folders' => $folders]);
        exit;
    }

    if ($_POST['action'] === 'get_sync_manifest') {
        // Returns a list of all canvases and their latest drawing ID for comparison
        $stmt = $db->query("
            SELECT c.id, c.updated_at, 
                   (SELECT id FROM drawings WHERE canvas_id = c.id ORDER BY id DESC LIMIT 1) as latest_drawing_id 
            FROM canvases c
        ");
        $manifest = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sys_meta = [
            'assets' => $db->query("SELECT COUNT(*) as c, MAX(created_at) as m FROM assets")->fetch(PDO::FETCH_ASSOC),
            'folders' => $db->query("SELECT COUNT(*) as c, MAX(created_at) as m FROM folders")->fetch(PDO::FETCH_ASSOC)
        ];
        
        echo json_encode(['status' => 'success', 'manifest' => $manifest, 'sys_meta' => $sys_meta]);
        exit;
    }
    if ($_POST['action'] === 'get_full_snapshot') {
        // Fetch assets and strip legacy Base64 data to keep snapshot light
        $assets = $db->query("SELECT hash, data, created_at FROM assets")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($assets as &$a) {
            if (strpos($a['data'], 'data:') === 0) {
                $a['data'] = '{"legacy":true}'; // Strip Base64
            }
        }

        echo json_encode([
            'status' => 'success',
            'folders' => $db->query("SELECT * FROM folders ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC),
            'canvases' => $db->query("SELECT * FROM canvases ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC),
            'stickers' => $db->query("SELECT * FROM stickers ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC),
            'presets' => $db->query("SELECT * FROM presets")->fetchAll(PDO::FETCH_ASSOC),
            'assets' => $assets
        ]);
        exit;
    }
    if ($_POST['action'] === 'get_sync_health') {
        $folders = $db->query("SELECT id, name, parent_id FROM folders")->fetchAll(PDO::FETCH_ASSOC);
        $canvases = $db->query("SELECT id, name, folder_id, updated_at FROM canvases")->fetchAll(PDO::FETCH_ASSOC);
        // Get latest drawing ID for every canvas to verify data versions
        $versions = $db->query("SELECT canvas_id, MAX(id) as v FROM drawings GROUP BY canvas_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Additional Stats
        $stickerCount = $db->query("SELECT COUNT(*) FROM stickers")->fetchColumn();
        $presetCount = $db->query("SELECT COUNT(*) FROM presets")->fetchColumn();
        
        // Calculate DB Size
        $db_size = file_exists($db_path) ? filesize($db_path) : 0;

        // Calculate Total Data Folder Size & Breakdown
        $total_size = 0;
        $asset_breakdown = ['pdf' => 0, 'docx' => 0, 'img' => 0, 'other' => 0];
        
        // 1. Map hashes to extensions from the DB
        $hash_map = [];
        $stmt = $db->query("SELECT hash, data FROM assets");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $meta = json_decode($row['data'], true);
            if (isset($meta['path'])) {
                $hash_map[$row['hash']] = strtolower(pathinfo($meta['path'], PATHINFO_EXTENSION));
            }
        }

        $data_path = __DIR__ . '/data';
        if (is_dir($data_path)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data_path, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $sz = $f->getSize();
                $total_size += $sz;
                $path = $f->getPathname();
                
                // Categorize by scanning the Vault (actual data) and Assets (links)
                if (strpos($path, DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR) !== false || 
                    strpos($path, DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR) !== false) {
                    
                    $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
                    // If it's a hash-named file in the vault, look up its real extension
                    if (isset($hash_map[$f->getFilename()])) $ext = $hash_map[$f->getFilename()];

                    if ($ext === 'pdf') $asset_breakdown['pdf'] += $sz;
                    else if ($ext === 'docx') $asset_breakdown['docx'] += $sz;
                    else if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) $asset_breakdown['img'] += $sz;
                    else $asset_breakdown['other'] += $sz;
                }
            }
        }

        // Calculate internal DB content size (Strokes)
        $stroke_size = $db->query("SELECT SUM(LENGTH(data)) FROM drawings")->fetchColumn() ?: 0;
        
        $secrets_path = __DIR__ . '/data/secrets-private.json';
        if (!file_exists($secrets_path)) {
            if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
            $api_key = bin2hex(random_bytes(16));
            file_put_contents($secrets_path, json_encode(['api_key' => $api_key]));
        } else {
            $secrets = json_decode(file_get_contents($secrets_path), true);
            $api_key = $secrets['api_key'] ?? 'KeyMissing';
        }

        echo json_encode([
            'status' => 'success',
            'folders' => $folders,
            'canvases' => $canvases,
            'versions' => $versions,
            'api_key' => $api_key,
            'stats' => [
                'stickers' => (int)$stickerCount,
                'presets' => (int)$presetCount,
                'db_size' => $db_size,
                'total_size' => $total_size,
                'stroke_size' => (int)$stroke_size,
                'asset_breakdown' => $asset_breakdown
            ]
        ]);
        exit;
    }
    if ($_POST['action'] === 'create_folder') {
        $name = $_POST['name'] ?? 'New Folder';
        $fid = (int)($_POST['parent_id'] ?? 0);
        $db->prepare("INSERT INTO folders (name, parent_id, created_at) VALUES (?, ?, ?)")->execute([$name, $fid, time()]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'rename_folder') {
        $db->prepare("UPDATE folders SET name = ? WHERE id = ?")->execute([$_POST['name'], $_POST['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'delete_folder') {
        $fid = (int)$_POST['id'];
        $f = $db->prepare("SELECT parent_id FROM folders WHERE id = ?");
        $f->execute([$fid]);
        $parent = $f->fetch(PDO::FETCH_ASSOC);
        $pid = $parent ? $parent['parent_id'] : 0;
        
        $db->prepare("UPDATE canvases SET folder_id = ? WHERE folder_id = ?")->execute([$pid, $fid]);
        $db->prepare("UPDATE folders SET parent_id = ? WHERE parent_id = ?")->execute([$pid, $fid]);
        $db->prepare("DELETE FROM folders WHERE id = ?")->execute([$fid]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'duplicate_canvas') {
        $cid = (int)$_POST['id'];
        $stmt = $db->prepare("SELECT * FROM canvases WHERE id = ?");
        $stmt->execute([$cid]);
        $src = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($src) {
            $db->prepare("INSERT INTO canvases (name, folder_id, thumbnail, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")
               ->execute([$src['name'] . ' (Copy)', $src['folder_id'], $src['thumbnail'], time(), time()]);
            $newCid = $db->lastInsertId();
            
            $stmtD = $db->prepare("SELECT data FROM drawings WHERE canvas_id = ? ORDER BY id DESC LIMIT 1");
            $stmtD->execute([$cid]);
            $srcD = $stmtD->fetch(PDO::FETCH_ASSOC);
            if ($srcD) {
                $db->prepare("INSERT INTO drawings (canvas_id, data, updated_at) VALUES (?, ?, ?)")
                   ->execute([$newCid, $srcD['data'], time()]);
            }
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }
    if ($_POST['action'] === 'move_canvas') {
        $db->prepare("UPDATE canvases SET folder_id = ?, updated_at = ? WHERE id = ?")->execute([$_POST['folder_id'], time(), $_POST['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'create_canvas') {
        $name = $_POST['name'] ?? 'Untitled Canvas';
        $fid = (int)($_POST['folder_id'] ?? 0);
        $stmt = $db->prepare("INSERT INTO canvases (name, folder_id, created_at, updated_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $fid, time(), time()]);
        echo json_encode(['status' => 'success', 'id' => $db->lastInsertId()]);
        exit;
    }
    if ($_POST['action'] === 'rename_canvas') {
        $stmt = $db->prepare("UPDATE canvases SET name = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([$_POST['name'], time(), $_POST['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'delete_canvas') {
        $db->prepare("DELETE FROM canvases WHERE id = ?")->execute([$_POST['id']]);
        $db->prepare("DELETE FROM drawings WHERE canvas_id = ?")->execute([$_POST['id']]);
        
        // Shrink DB file
        $db->exec("VACUUM");
        
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'bulk_gallery_delete') {
        $cids = json_decode($_POST['canvas_ids'], true) ?: [];
        $fids = json_decode($_POST['folder_ids'], true) ?: [];
        
        $db->beginTransaction();
        // Delete Canvases
        foreach ($cids as $id) {
            if ($id == 1) continue; // Safety
            $db->prepare("DELETE FROM canvases WHERE id = ?")->execute([$id]);
            $db->prepare("DELETE FROM drawings WHERE canvas_id = ?")->execute([$id]);
        }
        // Delete Folders (Move contents to parent)
        foreach ($fids as $fid) {
            $f = $db->prepare("SELECT parent_id FROM folders WHERE id = ?");
            $f->execute([$fid]);
            $parent = $f->fetch(PDO::FETCH_ASSOC);
            $pid = $parent ? $parent['parent_id'] : 0;
            $db->prepare("UPDATE canvases SET folder_id = ? WHERE folder_id = ?")->execute([$pid, $fid]);
            $db->prepare("UPDATE folders SET parent_id = ? WHERE parent_id = ?")->execute([$pid, $fid]);
            $db->prepare("DELETE FROM folders WHERE id = ?")->execute([$fid]);
        }
        $db->commit();
        
        $db->exec("VACUUM");
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'cleanup_old_assets') {
        $days = (int)$_POST['days'];
        if ($days <= 0) exit;
        
        $limit = time() - ($days * 86400);
        
        // 1. Find assets older than threshold
        $stmt = $db->prepare("SELECT hash, data FROM assets WHERE created_at < ?");
        $stmt->execute([$limit]);
        $to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($to_delete as $asset) {
            // 2. Delete physical file
            $meta = json_decode($asset['data'], true);
            if (isset($meta['path'])) {
                $full_path = __DIR__ . '/data/assets/' . $meta['path'];
                if (file_exists($full_path)) {
                    @unlink($full_path);
                }
            }
            // 3. Delete DB record
            $db->prepare("DELETE FROM assets WHERE hash = ?")->execute([$asset['hash']]);
        }

        // 4. Prune empty folders (Bottom-up traversal)
        $assets_root = __DIR__ . '/data/assets';
        if (is_dir($assets_root)) {
            $dirs = [];
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($assets_root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                if ($file->isDir()) {
                    $dirs[] = $file->getRealPath();
                }
            }
            $it = null; // Release directory locks (Crucial for Windows)

            // Sort by depth descending (longest paths first)
            usort($dirs, function($a, $b) {
                return strlen($b) - strlen($a);
            });

            foreach ($dirs as $dir) {
                $items = @scandir($dir);
                if ($items !== false) {
                    // Ignore standard hidden OS files to ensure "empty" folders can actually be deleted
                    $contents = array_diff($items, ['.', '..', '.DS_Store', 'Thumbs.db']);
                    if (empty($contents)) {
                        @unlink($dir . '/.DS_Store');
                        @unlink($dir . '/Thumbs.db');
                        @rmdir($dir);
                    }
                }
            }
        }
        
        $db->exec("VACUUM");
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'bulk_gallery_move') {
        $cids = json_decode($_POST['canvas_ids'], true) ?: [];
        $fids = json_decode($_POST['folder_ids'], true) ?: [];
        $target = (int)$_POST['target_folder_id'];
        
        $db->beginTransaction();
        foreach ($cids as $id) {
            $db->prepare("UPDATE canvases SET folder_id = ?, updated_at = ? WHERE id = ?")->execute([$target, time(), $id]);
        }
        foreach ($fids as $id) {
            $db->prepare("UPDATE folders SET parent_id = ? WHERE id = ?")->execute([$target, $id]);
        }
        $db->commit();
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'check_version') {
        echo json_encode(['status' => 'success', 'hash' => get_asset_hash(['index.php', 'css/style.css', 'js/app.js', 'manifest.json'])]);
        exit;
    }
    if ($_POST['action'] === 'check_data_version') {
        $cid = isset($_POST['canvas_id']) ? (int)$_POST['canvas_id'] : 1;
        $stmt = $db->prepare("SELECT id FROM drawings WHERE canvas_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'id' => $row ? (int)$row['id'] : 0]);
        exit;
    }
    if ($_POST['action'] === 'fetch_latest_data') {
        $cid = isset($_POST['canvas_id']) ? (int)$_POST['canvas_id'] : 1;
        $stmt = $db->prepare("SELECT id, data FROM drawings WHERE canvas_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $data = ($row && !empty($row['data'])) ? $row['data'] : '[]';
        
        $stmtV = $db->prepare("SELECT viewport FROM canvases WHERE id = ?");
        $stmtV->execute([$cid]);
        $vRow = $stmtV->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success', 
            'id' => $row ? (int)$row['id'] : 0, 
            'data' => $data,
            'viewport' => $vRow ? $vRow['viewport'] : null
        ]);
        exit;
    }
    if ($_POST['action'] === 'prune_history') {
        // 1. Delete all but the latest drawing for every canvas
        $db->exec("DELETE FROM drawings WHERE id NOT IN (SELECT MAX(id) FROM drawings GROUP BY canvas_id)");
        
        // 2. Reclaim disk space
        $db->exec("VACUUM");
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'cleanup_old_canvases') {
        $days = (int)$_POST['days'];
        if ($days <= 0) exit;
        
        $limit = time() - ($days * 86400);
        
        // Delete canvases older than threshold, protecting ID 1
        $stmt = $db->prepare("DELETE FROM canvases WHERE created_at < ? AND id != 1");
        $stmt->execute([$limit]);
        
        // Cleanup orphaned drawings
        $db->exec("DELETE FROM drawings WHERE canvas_id NOT IN (SELECT id FROM canvases)");
        
        $db->exec("VACUUM");
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'upload_asset') {
        $hash = $_POST['hash'];
        $b64 = $_POST['data'];
        
        $assets_dir = __DIR__ . '/data/assets/Uploads';
        if (!is_dir($assets_dir)) mkdir($assets_dir, 0777, true);
        
        $bin = $b64;
        $ext = '.png';
        if (preg_match('/^data:(.*?);base64,(.*)$/', $b64, $matches)) {
            $bin = base64_decode($matches[2]);
            if (strpos($matches[1], 'jpeg') !== false) $ext = '.jpg';
        }
        
        $human_path = $assets_dir . '/asset_' . substr($hash, 0, 8) . $ext;
        if (!file_exists($human_path)) file_put_contents($human_path, $bin);
        
        $fast_id = md5('Uploads/asset_' . substr($hash, 0, 8) . $ext . filemtime($human_path) . filesize($human_path));
        $meta = json_encode(['path' => 'Uploads/asset_' . substr($hash, 0, 8) . $ext, 'fast_id' => $fast_id]);
        
        $stmt = $db->prepare("INSERT OR IGNORE INTO assets (hash, data, created_at) VALUES (?, ?, ?)");
        $stmt->execute([$hash, $meta, time()]);
        
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'get_asset') {
        $hash = $_POST['hash'];
        $stmt = $db->prepare("SELECT data FROM assets WHERE hash = ?");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
    if (strpos($row['data'], 'data:') === 0) {
        echo json_encode(['status' => 'success', 'data' => $row['data'], 'url' => null]);
        exit;
    }
    $meta = json_decode($row['data'], true);
    $rel_path = $meta['path'] ?? null;$full_path = $rel_path ? __DIR__ . '/data/assets/' . $rel_path : null;

            // 1. Check if file exists at recorded path
            if (!$full_path || !file_exists($full_path)) {
                // 2. AUTO-RELINK: Scan assets folder for matching hash
                $new_rel_path = wb_find_file_by_hash($hash);
                if ($new_rel_path) {
                    $rel_path = $new_rel_path;
                    $meta['path'] = $rel_path;
                    $meta['fast_id'] = md5($rel_path . filemtime(__DIR__ . '/data/assets/' . $rel_path) . filesize(__DIR__ . '/data/assets/' . $rel_path));
                    $db->prepare("UPDATE assets SET data = ? WHERE hash = ?")->execute([json_encode($meta), $hash]);
                } else {
                    // File truly missing
                    echo json_encode(['status' => 'success', 'data' => null, 'url' => null]);
                    exit;
                }
            }
            echo json_encode(['status' => 'success', 'data' => null, 'url' => 'data/assets/' . $rel_path . '?v=' . $hash]);
        } else {
            echo json_encode(['status' => 'success', 'data' => null, 'url' => null]);
        }
        exit;
    }
    if ($_POST['action'] === 'get_asset_usage') {
        $hash = $_POST['hash'];
        // Search drawings for the assetId pattern in the JSON data
        $pattern = '%"assetId":"' . $hash . '"%';
        $stmt = $db->prepare("
            SELECT DISTINCT c.name 
            FROM canvases c 
            JOIN drawings d ON c.id = d.canvas_id 
            WHERE d.data LIKE ?
        ");
        $stmt->execute([$pattern]);
        $canvases = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['status' => 'success', 'canvases' => $canvases]);
        exit;
    }
    if ($_POST['action'] === 'get_assets_list') {
        $stmt = $db->query("SELECT hash, created_at FROM assets ORDER BY created_at DESC");
        echo json_encode(['status' => 'success', 'assets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    if ($_POST['action'] === 'list_library') {
        $path = preg_replace('/[^a-zA-Z0-9_\-\/ ]/', '', $_POST['path'] ?? '');
        $target_dir = rtrim(__DIR__ . '/data/assets/' . $path, '/');
        
        if (!is_dir($target_dir)) {
            echo json_encode(['status' => 'error', 'message' => 'Directory not found']);
            exit;
        }
        
        $folders = [];
        $files =[];
        
        // Fetch all assets to build a path->hash map
        $stmt = $db->query("SELECT hash, data FROM assets WHERE data LIKE '%\"path\"%'");
        $path_to_hash =[];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $meta = json_decode($row['data'], true);
            if (isset($meta['path'])) {
                $path_to_hash[$meta['path']] = $row['hash'];
            }
        }

        $items = scandir($target_dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full_path = $target_dir . '/' . $item;
            $rel_path = ltrim($path . '/' . $item, '/');
            
            if (is_dir($full_path)) {
                $folders[] = $item;
            } else {
                $hash = $path_to_hash[$rel_path] ?? sha1_file($full_path);
                $files[] =[
                    'name' => $item,
                    'hash' => $hash,
                    'size' => filesize($full_path),
                    'mtime' => filemtime($full_path)
                ];
            }
        }
        
        echo json_encode(['status' => 'success', 'folders' => $folders, 'files' => $files, 'current_path' => $path]);
        exit;
    }
    if ($_POST['action'] === 'create_asset_folder') {
        $path = preg_replace('/[^a-zA-Z0-9_\-\/ ]/', '', $_POST['path'] ?? '');
        $name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $_POST['name'] ?? 'New_Folder');
        $target_dir = rtrim(__DIR__ . '/data/assets/' . $path, '/');
        if (!is_dir($target_dir . '/' . $name)) {
            mkdir($target_dir . '/' . $name, 0777, true);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'move_asset') {
        $hash = $_POST['hash'];
        $target_folder = preg_replace('/[^a-zA-Z0-9_\-\/ ]/', '', $_POST['target_folder'] ?? '');
        
        $stmt = $db->prepare("SELECT data FROM assets WHERE hash = ?");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $meta = json_decode($row['data'], true);
            $old_rel_path = $meta['path'];
            $filename = basename($old_rel_path);
            $new_rel_path = ltrim($target_folder . '/' . $filename, '/');
            
            $old_full = __DIR__ . '/data/assets/' . $old_rel_path;
            $new_dir = __DIR__ . '/data/assets/' . $target_folder;
            $new_full = $new_dir . '/' . $filename;
            
            if (file_exists($old_full)) {
                if (!is_dir($new_dir)) mkdir($new_dir, 0777, true);
                if (rename($old_full, $new_full)) {
                    $meta['path'] = $new_rel_path;
                    $meta['fast_id'] = md5($new_rel_path . filemtime($new_full) . filesize($new_full));
                    $db->prepare("UPDATE assets SET data = ? WHERE hash = ?")->execute([json_encode($meta), $hash]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Rename failed']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Source file missing']);
            }
        }
        exit;
    }
    if ($_POST['action'] === 'delete_asset_folder') {
        $path = preg_replace('/[^a-zA-Z0-9_\-\/ ]/', '', $_POST['path'] ?? '');
        $target_dir = rtrim(__DIR__ . '/data/assets/' . $path, '/');
        
        if (is_dir($target_dir) && !empty($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($target_dir);

            $stmt = $db->query("SELECT hash, data FROM assets WHERE data LIKE '%\"path\"%'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $meta = json_decode($row['data'], true);
                if (isset($meta['path']) && strpos($meta['path'], $path . '/') === 0) {
                    $db->prepare("DELETE FROM assets WHERE hash = ?")->execute([$row['hash']]);
                }
            }
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid path']);
        }
        exit;
    }
    if ($_POST['action'] === 'move_asset_folder') {
        $path = preg_replace('/[^a-zA-Z0-9_\-\/ ]/', '', $_POST['path'] ?? '');
        $target_folder = preg_replace('/[^a-zA-Z0-9_\-\/ ]/', '', $_POST['target_folder'] ?? '');
        
        $src_dir = rtrim(__DIR__ . '/data/assets/' . $path, '/');
        $folder_name = basename($path);
        $dest_dir = rtrim(__DIR__ . '/data/assets/' . $target_folder, '/') . '/' . $folder_name;
        
        if (strpos($dest_dir, $src_dir . '/') === 0 || $src_dir === $dest_dir) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot move folder into itself']);
            exit;
        }

        if (is_dir($src_dir) && !empty($path)) {
            if (!is_dir(dirname($dest_dir))) mkdir(dirname($dest_dir), 0777, true);
            if (rename($src_dir, $dest_dir)) {
                $stmt = $db->query("SELECT hash, data FROM assets WHERE data LIKE '%\"path\"%'");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $meta = json_decode($row['data'], true);
                    if (isset($meta['path']) && strpos($meta['path'], $path . '/') === 0) {
                        $new_rel = $target_folder . '/' . $folder_name . substr($meta['path'], strlen($path));
                        $new_rel = ltrim($new_rel, '/');
                        $meta['path'] = $new_rel;
                        $full_new = __DIR__ . '/data/assets/' . $new_rel;
                        if (file_exists($full_new)) {
                            $meta['fast_id'] = md5($new_rel . filemtime($full_new) . filesize($full_new));
                        }
                        $db->prepare("UPDATE assets SET data = ? WHERE hash = ?")->execute([json_encode($meta), $row['hash']]);
                    }
                }
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Rename failed']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Source folder missing']);
        }
        exit;
    }
    if ($_POST['action'] === 'delete_asset') {
        $hash = $_POST['hash'];
        $stmt = $db->prepare("SELECT data FROM assets WHERE hash = ?");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && strpos($row['data'], 'data:') !== 0) {
            $meta = json_decode($row['data'], true);
            if (isset($meta['path'])) {
                @unlink(__DIR__ . '/data/assets/' . $meta['path']);
            }
        }
        
        $stmt = $db->prepare("DELETE FROM assets WHERE hash = ?");
        $stmt->execute([$hash]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'save_workspaces') {
        $cid = (int)$_POST['canvas_id'];
        $stmt = $db->prepare("UPDATE canvases SET workspaces = ? WHERE id = ?");
        $stmt->execute([$_POST['data'], $cid]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['action'] === 'get_workspaces') {
        $cid = (int)$_POST['canvas_id'];
        $stmt = $db->prepare("SELECT workspaces FROM canvases WHERE id = ?");
        $stmt->execute([$cid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $row['workspaces'] ?? '[]']);
        exit;
    }
    if ($_POST['action'] === 'save_settings') {
        $settings_path = __DIR__ . '/data/settings.json';
        if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
        
        $raw = file_exists($settings_path) ? file_get_contents($settings_path) : '';
        $current = json_decode($raw, true);
        if (!is_array($current)) $current = []; // Handle corruption/null
        
        $incoming = json_decode($_POST['settings'], true);
        if (is_array($incoming)) {
            $new_settings = array_merge($current, $incoming);
            file_put_contents($settings_path, json_encode($new_settings, JSON_PRETTY_PRINT));
        }
        
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// Handle Save Request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $cid = isset($_POST['canvas_id']) ? $_POST['canvas_id'] : 1;
    if (is_numeric($cid)) {
        $cid = (int)$cid;
        $checkCanvas = $db->prepare("SELECT id FROM canvases WHERE id = ?");
        $checkCanvas->execute([$cid]);
        if (!$checkCanvas->fetch()) {
            $db->prepare("INSERT INTO canvases (id, name, folder_id, created_at, updated_at) VALUES (?, 'Recovered Canvas', 0, ?, ?)")->execute([$cid, time(), time()]);
        }
    }
    // Conflict Detection: Check if the base_id matches the current latest ID
    $stmt = $db->prepare("SELECT id FROM drawings WHERE canvas_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cid]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_id = $latest ? (int)$latest['id'] : 0;
    $base_id = isset($_POST['base_id']) ? (int)$_POST['base_id'] : 0;

    // If base_id is provided and doesn't match, and it's not the very first save
    if ($base_id > 0 && $base_id !== $current_id) {
        echo json_encode([
            'status' => 'conflict', 
            'message' => 'Version mismatch', 
            'server_id' => $current_id
        ]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO drawings (canvas_id, data, updated_at) VALUES (?, ?, ?)");
    $stmt->execute([$cid, $_POST['data'], time()]);
    $new_id = $db->lastInsertId();

    // AUTO-PRUNE: Delete older versions of THIS canvas to prevent image-based bloat.
    // We keep the ID incrementing for sync, but keep only the latest row for storage.
    $db->prepare("DELETE FROM drawings WHERE canvas_id = ? AND id < ?")->execute([$cid, $new_id]);
    
    $params = [time()];
$sql = "UPDATE canvases SET updated_at = ?";
if (isset($_POST['thumbnail'])) { $sql .= ", thumbnail = ?"; $params[] = $_POST['thumbnail']; }
if (isset($_POST['viewport'])) { $sql .= ", viewport = ?"; $params[] = $_POST['viewport']; }
$sql .= " WHERE id = ?";
$params[] = $cid;
$db->prepare($sql)->execute($params);

echo json_encode([
    'status' => 'success', 
    'id' => (int)$new_id, 
    'updated_at' => time()
]);
exit;
    }// Load Settings
$settings_path = __DIR__ . '/data/settings.json';
$settings = file_exists($settings_path) ? json_decode(file_get_contents($settings_path), true) : [];
$primary_accent = $settings['primary_accent'] ?? '#5856D6';

// Fetch Latest Drawing (Using Unix Timestamp for global sync)
// Priority: URL Param > Server-side User Setting > Default (1)
$current_canvas_id = isset($_GET['canvas']) ? $_GET['canvas'] : ($settings['last_canvas_id'] ?? 1);

// Ensure the requested canvas exists, otherwise fallback to 1
if (strpos((string)$current_canvas_id, 'local_') !== 0) {
    $current_canvas_id = (int)$current_canvas_id;
    $checkCanvas = $db->prepare("SELECT id FROM canvases WHERE id = ?");
    $checkCanvas->execute([$current_canvas_id]);
    if (!$checkCanvas->fetch()) {
        $current_canvas_id = 1;
        $db->exec("INSERT OR IGNORE INTO canvases (id, name, folder_id, created_at, updated_at) VALUES (1, 'Default Canvas', 0, " . time() . ", " . time() . ")");
    }
}

$stmt = $db->prepare("SELECT id, data, updated_at FROM drawings WHERE canvas_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$current_canvas_id]);
$latest = $stmt->fetch(PDO::FETCH_ASSOC);
$initial_data = $latest ? $latest['data'] : '';
$initial_id = $latest ? (int)$latest['id'] : 0;
$initial_time = $latest ? (int)$latest['updated_at'] : 0;

// Fetch Canvas Viewport Metadata
$stmtV = $db->prepare("SELECT viewport, updated_at FROM canvases WHERE id = ?");
$stmtV->execute([$current_canvas_id]);
$vRow = $stmtV->fetch(PDO::FETCH_ASSOC);
$canvas_updated_at = $vRow ? (int)$vRow['updated_at'] : 0;

// Fingerprinting Logic
function get_asset_hash($files) {
    $mtime = 0;
    foreach ($files as $f) {
        if (file_exists(__DIR__ . '/' . $f)) {
            $mtime = max($mtime, filemtime(__DIR__ . '/' . $f));
        }
    }
    return substr(md5($mtime), 0, 8);
}
$build_hash = get_asset_hash(['index.php', 'css/style.css', 'js/app.js', 'manifest.json']);
$disable_fp = $settings['disable_fingerprinting'] ?? false;
$v_suffix = $disable_fp ? '' : '?v=' . $build_hash;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Whiteboard</title>
    <meta name="description" content="A highly personalized infinite canvas whiteboard custom-built with Conjure OS. Designed for specialized workflows and general use, featuring unlimited resizable floating viewports, innovative top-edge text box drag-insertion with automatic zoom scaling, stylus pen tilt-activated tools (eraser, highlighter, and colors), automatic Magic Canvas folder imports with customized quality and spacing, and direct document integration with the Lesson Planner API.">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="<?php echo $primary_accent; ?>">
    <link rel="icon" href="icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="icon.svg">
    <link rel="stylesheet" href="css/style.css<?php echo $v_suffix; ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://unpkg.com/pdf-lib/dist/pdf-lib.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jszip/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/docx-preview/dist/docx-preview.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        // Initialize PDF.js worker
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    </script>
    <style>
        :root { --primary-accent: <?php echo $primary_accent; ?>; }
    </style>
    <script>
        /**
         * WHITEBOARD PLUGIN ENGINE (Core Bootstrapper)
         */
        window.wb = {
            state: {},
            hooks: {},
            on: function(hook, fn) {
                if (!this.hooks[hook]) this.hooks[hook] = [];
                this.hooks[hook].push(fn);
            },
            emit: function(hook, ...args) {
                if (this.hooks[hook]) {
                    this.hooks[hook].forEach(fn => {
                        try { fn(...args); } catch(e) { console.error(`Hook [${hook}] failed:`, e); }
                    });
                }
            }
        };

        window.wbIcons = {
            palette: `<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.92 0 1.5-.58 1.5-1.5 0-.43-.17-.83-.44-1.1-.27-.27-.44-.67-.44-1.1 0-.92.58-1.5 1.5-1.5H16c3.31 0 6-2.69 6-6 0-4.97-4.48-9-10-9z"/></svg>`,
            folder: `<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>`,
            edit: `<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
            copy: `<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>`,
            trash: `<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>`,
            alert: `<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
            home: `<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`,
            help: `<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`
        };
    </script>
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="Whiteboard">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
</head>
<body>

<div id="gallery-view" style="display:none;">
    <div class="gallery-header">
        <div class="gallery-breadcrumb-container">
            <h2 id="gallery-title">My Canvases</h2>
        </div>

        <div class="gallery-search-wrapper">
            <div class="gallery-search-container">
                <i data-lucide="search" style="width:16px; height:16px; color:var(--text-secondary); flex-shrink:0;"></i>
                <input type="text" id="gallery-search-input" class="gallery-search-input" placeholder="Search canvases..." oninput="wbHandleGallerySearch(this.value)" spellcheck="false" autocomplete="off">
                <button id="gallery-search-clear" onclick="wbClearGallerySearch()" style="display:none; background:none; border:none; padding:4px; color:var(--text-secondary); cursor:pointer; align-items:center; justify-content:center;">
                    <i data-lucide="x" style="width:14px; height:14px; stroke-width:3;"></i>
                </button>
            </div>
            <div id="gallery-search-shortcuts" class="gallery-shortcuts"></div>
        </div>
        <div class="gallery-actions">
            <!-- Media Vault -->
            <button class="gallery-tool-btn" onclick="wbOpenMediaVault()" title="Media Vault">
                <i data-lucide="archive" style="width:20px; height:20px;"></i>
            </button>
            
            <!-- System Dashboard (Health/Stats) -->
            <button class="gallery-tool-btn" onclick="wbOpenDashboard()" title="System Dashboard">
                <i data-lucide="activity" style="width:20px; height:20px;"></i>
            </button>
            
            <!-- Cloud Sync -->
            <button id="wb-sync-all-btn" class="gallery-tool-btn" onclick="wbSyncAll()" title="Sync All for Offline">
                <i data-lucide="refresh-cw" style="width:20px; height:20px;"></i>
                <span id="sync-countdown" class="sync-badge">60</span>
            </button>
            
            <!-- New Folder -->
            <button class="gallery-tool-btn" onclick="wbCreateFolder()" title="New Folder">
                <i data-lucide="folder-plus" style="width:20px; height:20px;"></i>
            </button>
            
            <!-- Primary Action -->
            <button class="tool-btn" onclick="wbCreateCanvas()" style="background:var(--primary-accent); color:white; border:none; padding:0 18px; height:42px; border-radius:12px; font-weight:800; font-size:13px; letter-spacing:0.5px;">+ New Canvas</button>
        </div>
    </div>
    <div id="gallery-grid" class="gallery-grid">
        <!-- Canvases injected here -->
    </div>
    
</div>

<!-- Shared Action Sheet Overlay (Global) -->
<div id="wb-action-sheet" class="wb-action-sheet-overlay" onclick="if(event.target===this) wbCloseActionSheet()">
    <div class="wb-action-sheet" id="wb-action-sheet-content">
        <div id="wb-as-title" style="font-size:12px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:16px; text-align:center;">Manage</div>
        <div id="wb-as-options"></div>
        <button class="wb-as-btn" onclick="wbCloseActionSheet()" style="justify-content:center; margin-top:8px; background:var(--bg-color);">Cancel</button>
    </div>
</div>

<div id="canvas-container">
    <div class="canvas-pane" id="pane-0">
        <canvas id="main-canvas"></canvas>
        <canvas id="overlay-canvas"></canvas>
    </div>
</div>

<div class="world-nav-container">
    <div id="zoom-indicator" title="Tap: Reset Zoom | Hold: Go Home">100%</div>
    <div id="coord-chip">0, 0</div>
</div>
<div id="status-pill">Saving...</div>

<div id="grammar-ratio-popover" style="display:none; position:fixed; bottom:85px; left:50%; transform:translateX(-50%); background:var(--card-bg); padding:20px; border-radius:24px; box-shadow:0 12px 40px rgba(0,0,0,0.2); flex-direction:column; align-items:center; gap:12px; z-index:600; min-width:260px; border:1px solid rgba(0,0,0,0.05);">
    <div style="width: 100%; display: flex; justify-content: space-between; align-items: center;">
        <div style="font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px;">Label Size Ratio</div>
    </div>
    <div style="width: 100%; display: flex; align-items: center; gap: 12px;">
        <input type="range" id="grammar-ratio-slider" min="0.2" max="1.0" step="0.05" value="0.4" oninput="updateGrammarLabelRatio(this.value)" style="width:100%; margin:0; cursor:pointer;">
        <span id="grammar-ratio-val-display" style="font-size: 14px; font-weight: 800; min-width: 45px; text-align: right;">40%</span>
    </div>
</div>

<div id="size-popover">
    <div style="width: 100%; display: flex; justify-content: space-between; align-items: center;">
        <div style="font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px;">Stroke Presets</div>
        <button id="star-btn" onclick="toggleStarCurrent()" title="Star current size">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
        </button>
    </div>
    
    <div id="presets-container" style="width: 100%; display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px; min-height: 30px;">
        <!-- Presets injected here -->
    </div>

    <div style="width: 100%; height: 1px; background: rgba(0,0,0,0.05); margin: 4px 0;"></div>

    <div class="size-preview-container">
        <div id="size-dot-preview" style="width: 4px; height: 4px;"></div>
    </div>
    
    <div style="width: 100%; display: flex; align-items: center; gap: 12px;">
        <button id="expand-slider-btn" onclick="toggleSliderExpand()" title="Expand Slider" style="background: none; border: none; padding: 4px; cursor: pointer; color: var(--text-secondary); display: flex; align-items: center; justify-content: center; transition: color 0.2s;">
            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>
        </button>
        <div class="wb-slider-container">
            <input type="range" id="size-slider" min="0.2" max="50" step="0.1" value="4" oninput="setBrushWidth(this.value)">
            <div class="wb-slider-ruler">
                <div class="wb-ruler-tick"><span>0</span></div>
                <div class="wb-ruler-tick"><span>10</span></div>
                <div class="wb-ruler-tick"><span>20</span></div>
                <div class="wb-ruler-tick"><span>30</span></div>
                <div class="wb-ruler-tick"><span>40</span></div>
                <div class="wb-ruler-tick"><span>50</span></div>
            </div>
        </div>
        <span id="size-val-display" style="font-size: 14px; font-weight: 800; min-width: 25px; text-align: right;">4</span>
    </div>
</div>

<div id="library-drawer">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3 style="margin:0; font-size:16px;">Sticker Library</h3>
        <div style="display:flex; gap:8px;">
            <button class="tool-btn" onclick="clearLibrary()" style="padding:4px 8px; font-size:11px; color:#ff3b30; border-color:rgba(255,59,48,0.2);">Clear All</button>
            <button class="tool-btn" onclick="toggleLibrary()" style="padding:4px 8px; font-size:11px;">Close</button>
        </div>
    </div>
    <div id="library-list" class="library-grid">
        <!-- Stickers injected here -->
    </div>
</div>

<div id="save-menu">
    <div style="padding: 6px 14px 2px 14px; font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; opacity: 0.6;">Save & Export</div>
    
    <button class="wb-menu-item" onclick="saveDrawing(true); toggleSaveMenu();">
        <i data-lucide="save" style="width:18px;"></i> Save to Cloud
    </button>
    
    <button class="wb-menu-item" onclick="exportAsImage(false); toggleSaveMenu();">
        <i data-lucide="image" style="width:18px;"></i> PNG (Transparent)
    </button>
    
    <button class="wb-menu-item" onclick="exportAsImage(true); toggleSaveMenu();">
        <i data-lucide="file-image" style="width:18px;"></i> PNG (White BG)
    </button>

    <div class="wb-menu-sep"></div>
    <div style="padding: 2px 14px; font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; opacity: 0.6;">Quality</div>

    <div class="wb-menu-info-card">
        <div class="wb-info-row" style="margin-bottom: 6px;">
            <span class="wb-info-label">Export Resolution</span>
            <span id="export-q-val" style="font-size: 10px; font-weight: 900; color: var(--primary-accent);">2.0x</span>
        </div>
        <input type="range" id="export-q-slider" min="1" max="5" step="0.5" value="2" 
               style="width: 100%; margin: 0; cursor: pointer;"
               oninput="document.getElementById('export-q-val').innerText = parseFloat(this.value).toFixed(1) + 'x'">
    </div>

    <div class="wb-menu-sep"></div>
    <div style="padding: 2px 14px; font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; opacity: 0.6;">System</div>

    <button class="wb-menu-item" onclick="exportDatabase(); toggleSaveMenu();">
        <i data-lucide="download-cloud" style="width:18px;"></i> Backup (.json)
    </button>
    
    <button class="wb-menu-item" onclick="triggerRestore(); toggleSaveMenu();">
        <i data-lucide="upload-cloud" style="width:18px;"></i> Restore
    </button>

    <button class="wb-menu-item" onclick="toggleSaveMenu()" style="margin-top: 4px; justify-content: center; color: var(--text-secondary); font-size: 12px;">
        Close
    </button>
</div>

<!-- Hidden Restore Input -->
<input type="file" id="restore-input" style="display:none" accept=".json" onchange="importDatabase(this)">

<!-- Hidden Image Import Input -->
<input type="file" id="image-import-input" style="display:none" accept="image/*,.pdf,.docx" multiple onchange="handleImageUpload(event)">

<!-- Export Progress Overlay -->
<div id="export-progress-overlay" class="sync-center-overlay" style="display:none; z-index: 3000;">
    <div class="sync-card" style="max-width: 300px; padding: 24px; text-align: center;">
        <div id="export-progress-icon" style="margin-bottom: 16px; color: var(--primary-accent);">
            <svg viewBox="0 0 24 24" width="40" height="40" stroke="currentColor" stroke-width="2" fill="none" class="wb-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
        </div>
        <div id="export-progress-title" style="font-weight: 900; font-size: 16px; margin-bottom: 4px;">Exporting</div>
        <div id="export-progress-msg" style="font-size: 12px; color: var(--text-secondary); margin-bottom: 20px;">Preparing canvas...</div>
        <div style="width: 100%; height: 6px; background: rgba(0,0,0,0.05); border-radius: 3px; overflow: hidden; position: relative;">
            <div id="export-progress-bar" style="position: absolute; top: 0; left: 0; height: 100%; width: 0%; background: var(--primary-accent); transition: width 0.3s ease;"></div>
        </div>
        <div id="export-progress-pct" style="font-size: 10px; font-weight: 900; margin-top: 8px; color: var(--primary-accent);">0%</div>
    </div>
</div>

<style>
    @keyframes wb-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .wb-spin { animation: wb-spin 1s linear infinite; }
</style>

<!-- PDF Options Overlay -->
<div id="pdf-options-overlay" class="sync-center-overlay" style="display:none; z-index: 2100;">
    <div class="sync-card" style="max-width: 340px;">
        <div class="sync-header">
            <h3 style="margin:0; font-size:18px;">Document Import Quality</h3>
            <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Higher quality allows for deeper zooming</div>
        </div>
        <div class="sync-body" style="padding: 24px;">
            <div style="text-align:center; margin-bottom: 20px;">
                <div id="pdf-quality-label" style="font-size: 28px; font-weight: 900; color: var(--primary-accent);">4.0x</div>
                <div id="pdf-quality-desc" style="font-size: 11px; font-weight: 700; text-transform: uppercase; opacity: 0.6;">Ultra (Projection/4K)</div>
            </div>
            <input type="range" id="pdf-quality-slider" min="1" max="4" step="0.5" value="4" 
                   style="width:100%; margin: 0;"
                   oninput="updatePdfQualityDisplay(this.value)">
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 30px;">
                <button class="tool-btn" onclick="cancelPdfOptions()" style="background:var(--bg-color);">Cancel</button>
                <button class="tool-btn" onclick="confirmPdfOptions()" style="background:var(--primary-accent); color:white; border:none;">Start Import</button>
            </div>
        </div>
    </div>
</div>

<!-- Layout Picker Overlay -->
<div id="layout-picker-overlay" style="display:none;">
    <div class="layout-picker-card">
        <div style="margin-bottom: 20px;">
            <h3 style="margin:0; font-size:18px;">Import Layout</h3>
            <div style="font-size:12px; color:var(--text-secondary);">Arrange <span id="layout-count-label">0</span> pages</div>
        </div>

        <div id="layout-preview-box">
            <!-- Visual boxes representing pages will be injected here -->
        </div>

        <div style="margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px;">Page Spacing</span>
                <span id="import-spacing-val" style="font-size: 11px; font-weight: 800; color: var(--primary-accent);">250px</span>
            </div>
            <input type="range" id="import-spacing-slider" min="0" max="500" step="10" value="250" 
                   oninput="updateImportSpacing(this.value)" 
                   style="width: 100%; cursor: pointer; margin: 0;">
        </div>

        <div style="display:flex; gap:10px; margin-bottom:20px;">
            <button class="tool-btn layout-toggle-btn active" id="layout-v-btn" onclick="setImportLayout('vertical')" style="flex:1;">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none" style="margin-bottom:4px; display:block; margin-inline:auto;"><rect x="7" y="2" width="10" height="20" rx="1"></rect><line x1="7" y1="9" x2="17" y2="9"></line><line x1="7" y1="15" x2="17" y2="15"></line></svg>
                Vertical
            </button>
            <button class="tool-btn layout-toggle-btn" id="layout-h-btn" onclick="setImportLayout('horizontal')" style="flex:1;">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none" style="margin-bottom:4px; display:block; margin-inline:auto;"><rect x="2" y="7" width="20" height="10" rx="1"></rect><line x1="9" y1="7" x2="9" y2="17"></line><line x1="15" y1="7" x2="15" y2="17"></line></svg>
                Horizontal
            </button>
        </div>

        <div style="display:flex; gap:10px;">
            <button class="tool-btn" onclick="cancelImportLayout()" style="flex:1; background:var(--bg-color);">Cancel</button>
            <button class="tool-btn" onclick="commitImportLayout()" style="flex:1; background:var(--primary-accent); color:white; border:none;">Import Pages</button>
        </div>
    </div>
</div>

<div id="split-popover">
    <div style="padding: 6px 14px 2px 14px; font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; opacity: 0.6;">View Mode</div>
    
    <button class="wb-menu-item" onclick="setSplitMode('none')">
        <i data-lucide="square" style="width:18px;"></i> Single View
    </button>
    <button class="wb-menu-item" onclick="setSplitMode('vertical')">
        <i data-lucide="columns" style="width:18px;"></i> Vertical Split
    </button>
    <button class="wb-menu-item" onclick="setSplitMode('horizontal')">
        <i data-lucide="rows" style="width:18px;"></i> Horizontal Split
    </button>
    <button class="wb-menu-item" onclick="setSplitMode('floating')">
        <i data-lucide="copy" style="width:18px;"></i> Floating View
    </button>

    <div class="wb-menu-sep"></div>
    <button class="wb-menu-item" onclick="wbOpenWorkspaceManager(); toggleSplitPopover();" style="color:var(--primary-accent);">
        <i data-lucide="bookmark" style="width:18px;"></i> Workspaces
    </button>

    <div class="wb-menu-sep"></div>
    <div style="padding: 2px 14px; font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; opacity: 0.6;">Layout Settings</div>

    <div class="wb-menu-info-card" style="gap: 12px;">
        <div class="wb-info-row">
            <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:700;">
                <i data-lucide="lock" style="width:14px; opacity:0.6;"></i> Lock
            </div>
            <label class="switch" style="width:36px; height:20px;">
                <input type="checkbox" id="wb-lock-layout-toggle" onchange="wbToggleLayoutLock(this.checked)">
                <span class="slider" style="border-radius:20px;"></span>
            </label>
        </div>

        <div style="display: flex; flex-direction: column; gap: 6px;">
            <div class="wb-info-row">
                <span class="wb-info-label">Grab Sensitivity</span>
                <span id="hit-area-val" style="font-size: 10px; font-weight: 900; color: var(--primary-accent);">15px</span>
            </div>
            <input type="range" id="hit-area-slider" min="0" max="40" step="1" value="15" 
                   onpointerdown="window.toggleResizerDebug(true)"
                   onpointerup="window.toggleResizerDebug(false)"
                   onpointerleave="window.toggleResizerDebug(false)"
                   oninput="updateResizerHitArea(this.value)" 
                   style="width: 100%; cursor: pointer; margin: 0;">
        </div>
    </div>

    <button class="wb-menu-item" onclick="toggleSplitPopover()" style="margin-top: 4px; justify-content: center; color: var(--text-secondary); font-size: 12px;">
        Close
    </button>
</div>

<div id="options-menu">
    <div style="padding: 6px 14px 2px 14px; font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; opacity: 0.6;">Tools</div>
    
    <button class="wb-menu-item" onclick="toggleSettings(); toggleOptionsMenu();">
        <i data-lucide="settings" style="width:18px;"></i> Settings
    </button>
    <button class="wb-menu-item" onclick="window.wb.ui.openLayoutManager(); toggleOptionsMenu();">
        <i data-lucide="layout-template" style="width:18px;"></i> Customize Toolbar
    </button>
    <button class="wb-menu-item" onclick="wbOpenMediaVault(); toggleOptionsMenu();">
        <i data-lucide="archive" style="width:18px;"></i> Media Vault
    </button>
    <button class="wb-menu-item" onclick="toggleLibrary(); toggleOptionsMenu();">
        <i data-lucide="layers" style="width:18px;"></i> Sticker Library
    </button>
    <button class="wb-menu-item" onclick="wbOpenDashboard(); toggleOptionsMenu();">
        <i data-lucide="activity" style="width:18px;"></i> System Dashboard
    </button>

    <div class="wb-menu-sep"></div>
    <div style="padding: 2px 14px; font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; opacity: 0.6;">System</div>

    <button id="wb-go-online-btn" class="wb-menu-item" onclick="wbCheckConnection(); toggleOptionsMenu();" style="display:none; color:#34c759;">
        <i data-lucide="zap" style="width:18px;"></i> Go Online
    </button>
    <button class="wb-menu-item" onclick="checkSyncStatus(); toggleOptionsMenu();">
        <i data-lucide="refresh-cw" style="width:18px;"></i> Sync Check
    </button>

    <div class="wb-menu-info-card">
        <div class="wb-info-row">
            <span class="wb-info-label">Cloud</span>
            <span id="server-hash-chip" class="hash-chip">...</span>
        </div>
        <div class="wb-info-row">
            <span class="wb-info-label">Local</span>
            <span id="local-hash-chip" class="hash-chip">...</span>
        </div>
        <div class="wb-info-row">
            <span class="wb-info-label">Weight</span>
            <span id="canvas-weight-chip" class="hash-chip">0 KB</span>
        </div>
        <div class="wb-info-row" style="margin-top:4px; padding-top:8px; border-top:1px solid rgba(0,0,0,0.05);">
            <span class="wb-info-label">Build</span>
            <span style="font-size: 9px; font-family: monospace; color: var(--primary-accent); font-weight: 800;"><?php echo $build_hash; ?></span>
        </div>
    </div>

    <button class="wb-menu-item" onclick="toggleOptionsMenu()" style="margin-top: 4px; justify-content: center; color: var(--text-secondary); font-size: 12px;">
        Close
    </button>
</div>

<div id="asset-preview-overlay" class="sync-center-overlay" style="z-index: 4500;" onclick="if(event.target === this) wbCloseAssetPreview()">
    <div class="sync-card" style="max-width: 600px; width: 90%; height: auto; max-height: 90vh; overflow: hidden;">
        <div class="sync-header" style="display:flex; justify-content:space-between; align-items:center; padding: 12px 20px; gap: 15px;">
            <h3 id="preview-title" style="margin:0; font-size:16px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1; min-width: 0;">File Preview</h3>
            <button class="tool-btn" onclick="wbCloseAssetPreview()" style="padding:10px; margin:-10px; border:none; background:none; color:var(--text-secondary); flex-shrink:0; position:relative; z-index:10; cursor:pointer;" title="Close Preview">
                <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" stroke-width="3" fill="none" style="display:block;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="sync-body" style="padding:0; background:rgba(0,0,0,0.03); display:flex; align-items:center; justify-content:center; min-height:300px; overflow:hidden;">
            <div id="preview-content-area" style="width:100%; height:100%; display:flex; align-items:center; justify-content:center;">
                <!-- Preview content (img or canvas) injected here -->
            </div>
        </div>
        <div id="preview-usage-info" style="padding: 12px 24px; font-size: 11px; background: var(--bg-color); border-top: 1px solid rgba(0,0,0,0.05); max-height: 80px; overflow-y: auto; display: none;"></div>
        <div id="preview-footer" style="padding:16px 24px; border-top:1px solid rgba(0,0,0,0.05); display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
            <!-- Buttons injected here -->
        </div>
    </div>
</div>

<div id="folder-picker-overlay" class="sync-center-overlay" style="z-index: 5000;" onclick="if(event.target === this) wbCloseFolderPicker()">
    <div class="sync-card" style="max-width: 400px; width: 90%;">
        <div class="sync-header">
            <h3 style="margin:0; font-size:18px;">Move to...</h3>
            <div id="picker-breadcrumb" style="font-size:11px; color:var(--primary-accent); font-weight:700; margin-top:4px;">Assets</div>
        </div>
        <div class="sync-body" id="picker-list" style="max-height: 300px; overflow-y: auto; padding: 8px;">
            <!-- Folders injected here -->
        </div>
        <div style="padding:16px; border-top:1px solid rgba(0,0,0,0.05); display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
            <button class="tool-btn" onclick="wbCloseFolderPicker()" style="background:var(--bg-color);">Cancel</button>
            <button class="tool-btn" id="picker-move-btn" style="background:var(--primary-accent); color:white; border:none;">Move Here</button>
        </div>
    </div>
</div>

<div id="media-vault-overlay" class="sync-center-overlay" onclick="if(event.target === this) this.style.display='none'">
    <div class="sync-card" style="max-width: 800px; width: 95%;">
        <div class="sync-header">
            <h3 style="margin:0; font-size:18px;">Media Vault</h3>
            <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Global Asset Library (Deduplicated)</div>
        </div>
        <div class="sync-body">
            <div id="media-vault-grid" class="gallery-grid" style="grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; padding-bottom: 20px;">
                <!-- Assets injected here -->
            </div>
        </div>
        <div style="padding:16px; border-top:1px solid rgba(0,0,0,0.05); display:flex; gap:10px;">
            <button class="tool-btn" onclick="document.getElementById('media-vault-overlay').style.display='none'" style="flex:1; background:var(--bg-color);">Close</button>
        </div>
    </div>
</div>

<div id="settings-overlay" onclick="if(event.target === this) toggleSettings()">
    <div class="settings-card">
        <div class="settings-nav-wrapper" id="settings-nav-wrapper">
            
            <!-- PAGE 0: MAIN MENU -->
            <div class="settings-page" id="settings-page-main">
                <h3 style="margin: 0 0 20px 0; font-size: 18px;">Settings</h3>
                
                <div class="settings-item-link" onclick="wbOpenSettingsPage(1)">
                    <div class="settings-link-info">
                        <span class="settings-link-title">General</span>
                        <span class="settings-link-desc">Sync, Rotation, Pen Mode</span>
                    </div>
                    <i data-lucide="chevron-right" style="width:18px; color:var(--text-secondary);"></i>
                </div>

                <div class="settings-item-link" onclick="wbOpenSettingsPage(2)">
                    <div class="settings-link-info">
                        <span class="settings-link-title">Canvas & Appearance</span>
                        <span class="settings-link-desc">Themes, Paper, Toolbars</span>
                    </div>
                    <i data-lucide="chevron-right" style="width:18px; color:var(--text-secondary);"></i>
                </div>

                <div class="settings-item-link" onclick="wbOpenSettingsPage(3)">
                    <div class="settings-link-info">
                        <span class="settings-link-title">Performance</span>
                        <span class="settings-link-desc">Raster Cache, Resolution</span>
                    </div>
                    <i data-lucide="chevron-right" style="width:18px; color:var(--text-secondary);"></i>
                </div>

                <div class="settings-item-link" onclick="wbOpenSettingsPage(4)">
                    <div class="settings-link-info">
                        <span class="settings-link-title">Input & Advanced</span>
                        <span class="settings-link-desc">Tilt Trigger, Telemetry, Reset</span>
                    </div>
                    <i data-lucide="chevron-right" style="width:18px; color:var(--text-secondary);"></i>
                </div>

                <div class="settings-item-link" onclick="wbOpenPosPresetManager()">
                    <div class="settings-link-info">
                        <span class="settings-link-title">POS Manager</span>
                        <span class="settings-link-desc">Manage POS elements and compositions</span>
                    </div>
                    <i data-lucide="chevron-right" style="width:18px; color:var(--text-secondary);"></i>
                </div>

                <button class="tool-btn" onclick="toggleSettings()" style="width: 100%; margin-top: 24px; background: var(--primary-accent); color: white; border: none;">Done</button>
            </div>

            <!-- PAGE 1: GENERAL -->
            <div class="settings-page">
                <div class="settings-header">
                    <button class="settings-back-btn" onclick="wbOpenSettingsPage(0)"><i data-lucide="chevron-left"></i></button>
                    <h3 style="margin:0; font-size:18px;">General</h3>
                </div>

                <span class="settings-section-label">Persistence</span>
                <div class="settings-group">
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-title">Automatic Saving</span>
                            <span class="setting-desc">Save after every stroke</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="auto-save-toggle" onchange="updateAutoSave(this.checked)">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-title">Auto-Refresh</span>
                            <span class="setting-desc">Refresh automatically after 5s</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="auto-update-toggle" onchange="updateAutoUpdate(this.checked)">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>

                <span class="settings-section-label">Interaction</span>
                <div class="settings-group">
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-title">Canvas Rotation</span>
                            <span class="setting-desc">Allow two-finger rotation</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="rotation-toggle" onchange="updateRotationEnabled(this.checked)">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-title">Pen Only Mode</span>
                            <span class="setting-desc">Fingers only pan/zoom, Pen draws</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="pen-only-toggle" onchange="updatePenOnlyMode(this.checked)">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-title">Force Offline Mode</span>
                            <span class="setting-desc">Disable all cloud sync for testing or battery</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="force-offline-toggle" onchange="updateForceOffline(this.checked)">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- PAGE 2: CANVAS & APPEARANCE -->
            <div class="settings-page">
                <div class="settings-header">
                    <button class="settings-back-btn" onclick="wbOpenSettingsPage(0)"><i data-lucide="chevron-left"></i></button>
                    <h3 style="margin:0; font-size:18px;">Appearance</h3>
                </div>

                <span class="settings-section-label">App Theme</span>
                <div class="settings-selection-grid">
                    <div class="settings-selection-item theme-item" data-theme="light" onclick="setTheme('light')">
                        <div class="dot" style="background:#ffffff; border-color:#ccc;"></div>
                        <span style="font-size:10px; font-weight:700;">Light</span>
                    </div>
                    <div class="settings-selection-item theme-item" data-theme="blueprint" onclick="setTheme('blueprint')">
                        <div class="dot" style="background:#1e293b; border-color:#38bdf8;"></div>
                        <span style="font-size:10px; font-weight:700;">Blueprint</span>
                    </div>
                    <div class="settings-selection-item theme-item" data-theme="sepia" onclick="setTheme('sepia')">
                        <div class="dot" style="background:#fdf6e3; border-color:#b58900;"></div>
                        <span style="font-size:10px; font-weight:700;">Sepia</span>
                    </div>
                    <div class="settings-selection-item theme-item" data-theme="dark" onclick="setTheme('dark')">
                        <div class="dot" style="background:#1e1e1e; border-color:#bb86fc;"></div>
                        <span style="font-size:10px; font-weight:700;">Dark</span>
                    </div>
                </div>

                <span class="settings-section-label">Paper Type</span>
                <div class="settings-selection-grid">
                    <div class="settings-selection-item paper-btn" data-paper="plain" onclick="setPaper('plain')">
                        <i data-lucide="square" style="width:18px;"></i>
                        <span style="font-size:10px; font-weight:700;">Plain</span>
                    </div>
                    <div class="settings-selection-item paper-btn" data-paper="grid" onclick="setPaper('grid')">
                        <i data-lucide="grid" style="width:18px;"></i>
                        <span style="font-size:10px; font-weight:700;">Grid</span>
                    </div>
                    <div class="settings-selection-item paper-btn" data-paper="ruled" onclick="setPaper('ruled')">
                        <i data-lucide="align-justify" style="width:18px;"></i>
                        <span style="font-size:10px; font-weight:700;">Ruled</span>
                    </div>
                    <div class="settings-selection-item paper-btn" data-paper="dots" onclick="setPaper('dots')">
                        <i data-lucide="grip-vertical" style="width:18px;"></i>
                        <span style="font-size:10px; font-weight:700;">Dots</span>
                    </div>
                </div>

                <span class="settings-section-label">Interface</span>
                <div class="settings-group">
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-title">Contextual Toolbars</span>
                            <span class="setting-desc">Positioning for text/edit tools</span>
                        </div>
                        <select id="toolbar-mode-select" onchange="updateToolbarMode(this.value)" style="background:var(--bg-color); color:var(--text-primary); border:1px solid rgba(0,0,0,0.1); padding:6px; border-radius:8px; font-size:12px; font-weight:600; outline:none;">
                            <option value="floating">Floating</option>
                            <option value="docked">Docked</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- PAGE 3: PERFORMANCE -->
            <div class="settings-page">
                <div class="settings-header">
                    <button class="settings-back-btn" onclick="wbOpenSettingsPage(0)"><i data-lucide="chevron-left"></i></button>
                    <h3 style="margin:0; font-size:18px;">Performance</h3>
                </div>

                <div class="setting-row">
                    <div>
                        <div style="font-weight: 700; font-size: 14px;">Raster Cache</div>
                        <div style="font-size: 11px; color: var(--text-secondary);">Massive speed boost / Low battery</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="raster-cache-toggle" onchange="updateRasterEnabled(this.checked)">
                        <span class="slider"></span>
                    </label>
                </div>

                <div style="margin-top: 12px;">
                    <div style="display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 6px;">
                        <span style="font-weight: 700;">Vector Handoff</span>
                        <span id="vector-thresh-display" style="font-weight: 900; color: var(--primary-accent);">1000%</span>
                    </div>
                    <input type="range" id="vector-thresh-slider" min="100" max="5000" step="100" value="1000" style="width: 100%; margin: 0;" oninput="updateVectorThreshold(this.value)">
                </div>

                <div style="margin-top: 12px;">
                    <div style="display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 6px;">
                        <span style="font-weight: 700;">Cache Resolution</span>
                        <span id="cache-res-display" style="font-weight: 900; color: var(--primary-accent);">3.0x</span>
                    </div>
                    <input type="range" id="cache-res-slider" min="1" max="10" step="0.5" value="3" style="width: 100%; margin: 0;" oninput="updateCacheResolution(this.value)">
                    <div id="cache-mem-warning" style="font-size: 9px; color: #ff3b30; margin-top: 4px; font-weight: 700; display: none;">⚠️ HIGH MEMORY USAGE</div>
                </div>

                <button class="tool-btn" onclick="wbPurgeRenderCache()" style="width: 100%; margin-top: 24px; font-size: 12px; background: rgba(255,59,48,0.1); color: #ff3b30; border-color: rgba(255,59,48,0.2);">
                    Purge Render Cache (Free RAM)
                </button>
            </div>

            <!-- PAGE 4: INPUT & ADVANCED -->
            <div class="settings-page">
                <div class="settings-header">
                    <button class="settings-back-btn" onclick="wbOpenSettingsPage(0)"><i data-lucide="chevron-left"></i></button>
                    <h3 style="margin:0; font-size:18px;">Advanced</h3>
                </div>

                <div style="margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid rgba(0,0,0,0.05);">
                    <div style="font-weight: 700; font-size: 14px; margin-bottom: 8px;">Heavy Tilt Trigger</div>
                    <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--text-secondary); margin-bottom: 8px;">
                        <span>Trigger overlay when pen is tilted low</span>
                        <span id="tilt-threshold-display" style="font-weight: 900; color: var(--primary-accent);">30&deg;</span>
                    </div>
                    <input type="range" id="tilt-threshold-slider" min="10" max="60" step="1" value="30" style="width: 100%; margin: 0; cursor: pointer;" oninput="updateTiltThreshold(this.value)">
                    
                    <div style="margin-top: 12px;">
                        <div style="font-size: 10px; font-weight: 900; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;">Trigger Mode</div>
                        <select id="tilt-mode-select" onchange="updateTiltMode(this.value)" style="width: 100%; background: var(--bg-color); color: var(--text-primary); border: 1px solid rgba(0,0,0,0.1); padding: 10px; border-radius: 12px; font-size: 13px; font-weight: 600; outline: none;">
                            <option value="modal">Status Modal (Info Only)</option>
                            <option value="assign_switch">Quick Tool Assignment (Switch)</option>
                            <option value="assign_direct">Quick Tool Assignment (Direct)</option>
                            <option value="palette">Palette Overlay (Visual)</option>
                        </select>
                    </div>
                </div>

                <div class="setting-row">
                    <div>
                        <div style="font-weight: 700; font-size: 14px;">Pen Telemetry</div>
                        <div style="font-size: 11px; color: var(--text-secondary);">Show real-time pointer data</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="telemetry-toggle" onchange="updateTelemetryEnabled(this.checked)">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-row">
                    <div>
                        <div style="font-weight: 700; font-size: 14px;">Horizontal Size Swipe</div>
                        <div style="font-size: 11px; color: var(--text-secondary);">Swipe left/right on size button to adjust</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="size-swipe-x-toggle" onchange="updateSizeSwipeX(this.checked)">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-row">
                    <div>
                        <div style="font-weight: 700; font-size: 14px;">Touch Lab Button</div>
                        <div style="font-size: 11px; color: var(--text-secondary);">Show diagnostic fingerprint icon in toolbar</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="touch-lab-toggle" onchange="updateTouchLabVisibility(this.checked)">
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-row">
                    <div>
                        <div style="font-weight: 700; font-size: 14px;">Disable Fingerprinting</div>
                        <div style="font-size: 11px; color: var(--text-secondary);">Remove ?v= cache busters from assets</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="fingerprint-toggle" onchange="updateFingerprinting(this.checked)">
                        <span class="slider"></span>
                    </label>
                </div>

                <div style="margin-top: 20px;">
                    <span class="settings-section-label">Maintenance & Recovery</span>
                    <div class="settings-group" style="padding: 12px; display: flex; flex-direction: column; gap: 10px;">
                        
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <button class="tool-btn" onclick="toggleSettings(); fetchRemoteUpdate(true);" style="width: 100%; justify-content: flex-start; gap: 10px; background: var(--bg-color);">
                                <i data-lucide="refresh-cw" style="width:16px; color: var(--primary-accent);"></i>
                                <div style="text-align: left;">
                                    <div style="font-size: 13px; font-weight: 700;">Force Pull Current</div>
                                    <div style="font-size: 10px; color: var(--text-secondary);">Overwrite this canvas from cloud</div>
                                </div>
                            </button>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <button class="tool-btn" onclick="toggleSettings(); wbSyncAll();" style="width: 100%; justify-content: flex-start; gap: 10px; background: var(--bg-color);">
                                <i data-lucide="database" style="width:16px; color: var(--primary-accent);"></i>
                                <div style="text-align: left;">
                                    <div style="font-size: 13px; font-weight: 700;">Sync Entire Library</div>
                                    <div style="font-size: 10px; color: var(--text-secondary);">Refresh all canvases for offline use</div>
                                </div>
                            </button>
                        </div>

                        <div style="height: 1px; background: rgba(0,0,0,0.05); margin: 4px 0;"></div>

                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <button class="tool-btn" onclick="hardResetApp()" style="width: 100%; justify-content: flex-start; gap: 10px; color: #ff3b30; border-color: rgba(255,59,48,0.2); background: rgba(255,59,48,0.05);">
                                <i data-lucide="zap" style="width:16px;"></i>
                                <div style="text-align: left;">
                                    <div style="font-size: 13px; font-weight: 700;">Hard Reset App</div>
                                    <div style="font-size: 10px; opacity: 0.7;">Clear code cache & service worker</div>
                                </div>
                            </button>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <button class="tool-btn" onclick="wbAnnihilateAllData()" style="width: 100%; justify-content: flex-start; gap: 10px; color: #ffffff; border: none; background: #ff3b30; box-shadow: 0 4px 12px rgba(255,59,48,0.3);">
                                <span style="font-size: 16px;">☢️</span>
                                <div style="text-align: left;">
                                    <div style="font-size: 13px; font-weight: 900;">Factory Reset (Annihilate)</div>
                                    <div style="font-size: 10px; opacity: 0.9;">Wipe EVERYTHING (Canvases, Assets, Settings)</div>
                                </div>
                            </button>
                        </div>

                    </div>
                </div>
            </div>

        </div> <!-- /settings-nav-wrapper -->
    </div>
</div>

<div id="selection-menu">
    <button id="selection-edit-btn" class="wb-selection-item primary" onclick="handleSelectionAction('edit')" style="display:none;" title="Edit Text">
        <i data-lucide="type" style="width:20px;"></i>
    </button>
    
    <button class="wb-selection-item" onclick="handleSelectionAction('front')" title="Bring to Front">
        <div class="wb-icon-stack">
            <i data-lucide="layers-2" style="width:18px; opacity:0.7;"></i>
            <div class="wb-icon-badge">
                <i data-lucide="chevron-up" style="width:10px; stroke-width:4;"></i>
            </div>
        </div>
    </button>
    
    <button class="wb-selection-item" onclick="handleSelectionAction('back')" title="Send to Back">
        <div class="wb-icon-stack">
            <i data-lucide="layers-2" style="width:18px; opacity:0.7;"></i>
            <div class="wb-icon-badge" style="background: var(--text-secondary);">
                <i data-lucide="chevron-down" style="width:10px; stroke-width:4;"></i>
            </div>
        </div>
    </button>

    <div class="wb-selection-sep"></div>

    <button class="wb-selection-item" onclick="handleSelectionAction('cut')" title="Cut">
        <i data-lucide="scissors" style="width:18px;"></i>
    </button>
    
    <button class="wb-selection-item" onclick="handleSelectionAction('copy')" title="Copy to Library">
        <i data-lucide="copy" style="width:18px;"></i>
    </button>
    
    <button class="wb-selection-item danger" onclick="handleSelectionAction('delete')" title="Delete Selection">
        <i data-lucide="trash-2" style="width:18px;"></i>
    </button>

    <div class="wb-selection-sep"></div>

    <button class="wb-selection-item primary" onclick="handleSelectionAction('clear')" title="Done" style="width: auto; padding: 0 14px; gap: 8px; font-weight: 800; font-size: 11px; border-radius: 14px; background: var(--primary-accent); color: white; margin-left: 4px;">
        <i data-lucide="check" style="width:18px; stroke-width: 3;"></i>
        DONE
    </button>
</div>

<div id="text-toolbar">
    <div class="tool-section">
        <button class="tool-btn" id="wb-t-bold" onpointerdown="event.preventDefault()" onclick="updateTextFormat('bold')" title="Bold"><b>B</b></button>
        <button class="tool-btn" id="wb-t-italic" onpointerdown="event.preventDefault()" onclick="updateTextFormat('italic')" title="Italic"><i>I</i></button>
        <button class="tool-btn" id="wb-t-underline" onpointerdown="event.preventDefault()" onclick="updateTextFormat('underline')" title="Underline"><span style="text-decoration:underline">U</span></button>
    </div>
    <div class="wb-t-sep"></div>
    <div class="tool-section">
        <button class="tool-btn" id="wb-t-left" onpointerdown="event.preventDefault()" onclick="updateTextFormat('align', 'left')" title="Align Left">
            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none"><line x1="17" y1="10" x2="3" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="17" y1="18" x2="3" y2="18"></line></svg>
        </button>
        <button class="tool-btn" id="wb-t-center" onpointerdown="event.preventDefault()" onclick="updateTextFormat('align', 'center')" title="Align Center">
            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none"><line x1="18" y1="10" x2="6" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="18" y1="18" x2="6" y2="18"></line></svg>
        </button>
        <button class="tool-btn" id="wb-t-right" onpointerdown="event.preventDefault()" onclick="updateTextFormat('align', 'right')" title="Align Right">
            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none"><line x1="21" y1="10" x2="7" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="21" y1="18" x2="7" y2="18"></line></svg>
        </button>
    </div>
    <div class="wb-t-sep"></div>
    <div class="tool-section wb-t-size-row">
        <input type="range" id="text-size-slider" min="12" max="120" step="2" onpointerdown="event.stopPropagation()" oninput="updateTextFormat('size', this.value)" style="width:80px">
        <span id="text-size-val" style="font-size:11px; font-weight:800; min-width:24px">24</span>
        <button id="text-star-btn" class="tool-btn" onpointerdown="event.preventDefault()" onclick="toggleStarText()" title="Star current text size" style="padding: 4px; min-width: auto; background: none; border: none; box-shadow: none;">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
        </button>
    </div>
    <div class="wb-t-sep" id="text-preset-sep" style="display: none;"></div>
    <div class="tool-section" id="text-presets-container" style="display: none; max-width: 140px; overflow-x: auto; scrollbar-width: none; gap: 4px; padding-left: 4px; padding-right: 4px;">
        <!-- Text Presets injected here -->
    </div>
    <div class="wb-t-sep" id="text-done-sep"></div>
    <button class="tool-btn" id="wb-t-done" onpointerdown="event.preventDefault()" onclick="commitText()" title="Done" style="background:var(--primary-accent); color:white; border:none; padding:0 12px; font-weight:800; height:32px; border-radius:10px; margin-left:2px;">
        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="3" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg>
        <span style="margin-left:4px; font-size:11px;">DONE</span>
    </button>
</div>

<div id="move-controls">
    <button class="move-btn cancel" onclick="cancelMove()" title="Cancel (Discard)">
        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="3" fill="none"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
    </button>
    <div style="width:1px; background:rgba(255,255,255,0.2); height:24px; margin:0 4px;"></div>
    <div style="font-size:10px; font-weight:900; color:white; text-transform:uppercase; letter-spacing:1px; padding:0 8px; opacity:0.8;">Moving</div>
    <div style="width:1px; background:rgba(255,255,255,0.2); height:24px; margin:0 4px;"></div>
    <button class="move-btn commit" onclick="commitMove()" title="Commit (Place)">
        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="3" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg>
    </button>
</div>

<div id="wb-ui-stack"></div>



<!-- Touch Lab Manager Overlay -->
<div id="touch-lab-overlay" class="sync-center-overlay" style="z-index: 9000;" onclick="if(event.target === this) this.style.display='none'">
    <div class="sync-card" style="max-width: 500px; width: 90%;">
        <div class="sync-header">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div style="text-align:left;">
                    <h3 style="margin:0; font-size:18px;">Touch Lab Diagnostics</h3>
                    <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;">Capture and analyze palm rejection data</div>
                </div>
                <button id="wb-copy-all-logs-btn" class="tool-btn" onclick="wbCopyAllTouchLogs()" style="display:none; padding:6px 12px; font-size:10px; background:var(--primary-accent); color:white; border:none; font-weight:800;">
                    COPY ALL
                </button>
            </div>
        </div>
        <div class="sync-body" id="touch-lab-list" style="max-height: 400px; overflow-y: auto; padding: 16px;">
            <!-- Logs injected here -->
        </div>
        <div style="padding:16px; border-top:1px solid rgba(0,0,0,0.05); display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
            <button class="tool-btn" onclick="document.getElementById('touch-lab-overlay').style.display='none'" style="background:var(--bg-color);">Close</button>
            <button class="tool-btn" id="wb-start-rec-btn" onclick="wbToggleTouchRecording()" style="background:#ff3b30; color:white; border:none; font-weight:800;">● Start Recording</button>
        </div>
    </div>
</div>

<!-- Active Recording Indicator -->
<div id="wb-rec-indicator" style="display:none; position:fixed; top:20px; left:50%; transform:translateX(-50%); background:rgba(255,59,48,0.9); color:white; padding:8px 16px; border-radius:20px; font-size:11px; font-weight:900; z-index:9500; align-items:center; gap:8px; pointer-events:none; box-shadow: 0 4px 12px rgba(255,59,48,0.3);">
    <div style="width:8px; height:8px; background:white; border-radius:50%; animation: wb-pulse 1s infinite;"></div>
    RECORDING TOUCH DATA...
</div>

<style>
    @keyframes wb-pulse { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }
    .touch-log-item { background: var(--bg-color); border-radius: 12px; padding: 12px; margin-bottom: 8px; border: 1px solid rgba(0,0,0,0.05); }
</style>

<script>
    window.wb.settings = <?php echo json_encode($settings); ?>;
    window._initialData = <?php echo json_encode($initial_data); ?>;
    window._initialId = <?php echo $initial_id; ?>;
    window._initialCanvasId = <?php echo $current_canvas_id; ?>;
    window._initialViewport = <?php echo json_encode($vRow ? $vRow['viewport'] : null); ?>;
    window._canvasUpdatedAt = <?php echo $canvas_updated_at; ?>;
    window.currentCanvasId = <?php echo $current_canvas_id; ?>;
</script><?php require_once __DIR__ . '/modules/ui.php'; ?>
<?php require_once __DIR__ . '/modules/telemetry.php'; ?>
<?php require_once __DIR__ . '/modules/tilt_trigger.php'; ?>
<?php require_once __DIR__ . '/modules/tilt_tool_assign.php'; ?>
<?php require_once __DIR__ . '/modules/tilt_palette_overlay.php'; ?>
<?php require_once __DIR__ . '/modules/edge_text_trigger.php'; ?>
<script src="js/core-engine.js<?php echo $v_suffix; ?>"></script>
<script src="js/pointer-manager.js<?php echo $v_suffix; ?>"></script>
<script src="js/sync-engine.js<?php echo $v_suffix; ?>"></script>
<script src="js/gallery.js<?php echo $v_suffix; ?>"></script>
<script src="js/media-vault.js<?php echo $v_suffix; ?>"></script>
<script src="js/text-tool.js<?php echo $v_suffix; ?>"></script>
<script src="js/workspaces.js<?php echo $v_suffix; ?>"></script>
<script src="js/touch-lab.js<?php echo $v_suffix; ?>"></script>
<script src="js/selection-manager.js<?php echo $v_suffix; ?>"></script>
<script src="js/layout-picker.js<?php echo $v_suffix; ?>"></script>
<script src="js/export-engine.js<?php echo $v_suffix; ?>"></script>
<script src="js/ui-orchestrator.js<?php echo $v_suffix; ?>"></script>
<script src="js/app.js<?php echo $v_suffix; ?>"></script><script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').catch(err => console.log('SW registration failed:', err));
        });
    }
    autoSaveEnabled = <?php echo ($settings['auto_save'] ?? true) ? 'true' : 'false'; ?>;
    rotationEnabled = <?php echo ($settings['rotation_enabled'] ?? true) ? 'true' : 'false'; ?>;
    penOnlyMode = <?php echo ($settings['pen_only_mode'] ?? false) ? 'true' : 'false'; ?>;
    
    // Initialize Theme, Paper and Size on load
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const magicFolder = urlParams.get('magic_folder');
        if (magicFolder) {
            // Clean the URL so a refresh doesn't re-trigger it
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?canvas=' + window.currentCanvasId;
            window.history.replaceState({path: cleanUrl}, '', cleanUrl);
            
            // Wait slightly for the canvas to fully initialize its dimensions
            setTimeout(() => {
                if (typeof window.processMagicFolder === 'function') {
                    window.processMagicFolder(magicFolder);
                }
            }, 800);
        }

        setTheme('<?php echo $settings['theme'] ?? 'light'; ?>', false);
        setPaper('<?php echo $settings['paper'] ?? 'plain'; ?>', false);
        
        // Load tool-specific configurations (color/width) from settings
        toolConfigs = <?php 
            $tc = $settings['tool_configs'] ?? [];
            if (!isset($tc['draw'])) $tc['draw'] = ['color' => '#000000', 'width' => 4];
            if (!isset($tc['highlight'])) $tc['highlight'] = ['color' => '#ffff00', 'width' => 20];
            if (!isset($tc['text'])) $tc['text'] = ['color' => '#000000'];
            echo json_encode($tc); 
        ?>;
        
        textFontSize = <?php echo $settings['text_font_size'] ?? 24; ?>;

        // Initialize the last used tool mode (this automatically sets the correct color and width)
        setTouchMode('<?php echo $settings['touch_mode'] ?? 'draw'; ?>', false);

        // Sync Toggles
        if (document.getElementById('auto-save-toggle')) document.getElementById('auto-save-toggle').checked = autoSaveEnabled;
        if (document.getElementById('auto-update-toggle')) document.getElementById('auto-update-toggle').checked = <?php echo ($settings['auto_update'] ?? true) ? 'true' : 'false'; ?>;
        if (document.getElementById('rotation-toggle')) document.getElementById('rotation-toggle').checked = <?php echo ($settings['rotation_enabled'] ?? true) ? 'true' : 'false'; ?>;
        if (document.getElementById('pen-only-toggle')) document.getElementById('pen-only-toggle').checked = penOnlyMode;
        
        const tiltThresh = <?php echo $settings['tilt_trigger_threshold'] ?? 30; ?>;
        window.tiltTriggerThreshold = tiltThresh;
        if (document.getElementById('tilt-threshold-slider')) document.getElementById('tilt-threshold-slider').value = tiltThresh;
        if (document.getElementById('tilt-threshold-display')) document.getElementById('tilt-threshold-display').innerText = tiltThresh + '°';
        
        window.updateTiltThreshold = function(val) {
            window.tiltTriggerThreshold = parseInt(val);
            document.getElementById('tilt-threshold-display').innerText = val + '°';
            saveSettings({ tilt_trigger_threshold: window.tiltTriggerThreshold });
        };

        const tiltMode = '<?php echo $settings['tilt_trigger_mode'] ?? 'modal'; ?>';
        window.tiltTriggerMode = tiltMode;
        if (document.getElementById('tilt-mode-select')) document.getElementById('tilt-mode-select').value = tiltMode;
        window.updateTiltMode = function(val) {
            window.tiltTriggerMode = val;
            saveSettings({ tilt_trigger_mode: val });
        };

        const teleEnabled = <?php echo ($settings['telemetry_enabled'] ?? false) ? 'true' : 'false'; ?>;
        if (document.getElementById('telemetry-toggle')) document.getElementById('telemetry-toggle').checked = teleEnabled;
        window.updateTelemetryEnabled = function(val) {
            if (typeof wbToggleTelemetry === 'function') wbToggleTelemetry(val);
            saveSettings({ telemetry_enabled: val });
        };
        if (teleEnabled) wbToggleTelemetry(true);

        window.wbSizeSwipeXEnabled = <?php echo ($settings['size_swipe_x'] ?? true) ? 'true' : 'false'; ?>;
        if (document.getElementById('size-swipe-x-toggle')) document.getElementById('size-swipe-x-toggle').checked = window.wbSizeSwipeXEnabled;
        window.updateSizeSwipeX = function(val) {
            window.wbSizeSwipeXEnabled = val;
            saveSettings({ size_swipe_x: val });
        };

        const touchLabVisible = <?php echo ($settings['show_touch_lab'] ?? true) ? 'true' : 'false'; ?>;
        if (document.getElementById('touch-lab-toggle')) document.getElementById('touch-lab-toggle').checked = touchLabVisible;
        window.updateTouchLabVisibility = function(val) {
            const btn = document.getElementById('wb-touch-lab-btn');
            if (btn) btn.style.display = val ? 'flex' : 'none';
            saveSettings({ show_touch_lab: val });
        };
        updateTouchLabVisibility(touchLabVisible);

        const fpDisabled = <?php echo ($settings['disable_fingerprinting'] ?? false) ? 'true' : 'false'; ?>;
        if (document.getElementById('fingerprint-toggle')) document.getElementById('fingerprint-toggle').checked = fpDisabled;
        window.updateFingerprinting = function(val) {
            saveSettings({ disable_fingerprinting: val });
            const pill = document.getElementById('status-pill');
            pill.innerText = "Reloading to apply...";
            pill.style.opacity = "1";
            setTimeout(() => location.reload(), 800);
        };

        const savedMode = '<?php echo $settings['toolbar_mode'] ?? 'floating'; ?>';
        if (document.getElementById('toolbar-mode-select')) document.getElementById('toolbar-mode-select').value = savedMode;
        toolbarMode = savedMode;

        const savedHitPadding = <?php echo $settings['resizer_hit_padding'] ?? 15; ?>;
        updateResizerHitArea(savedHitPadding, false);

        // Offline State Initialization
        window.grammarLabelRatio = <?php echo $settings['grammar_label_ratio'] ?? 0.4; ?>;
        window.wbForceOffline = <?php echo ($settings['force_offline'] ?? false) ? 'true' : 'false'; ?>;
        if (document.getElementById('force-offline-toggle')) document.getElementById('force-offline-toggle').checked = window.wbForceOffline;
        
        window.updateForceOffline = function(val) {
            window.wbForceOffline = val;
            wbSetOnlineState(!val);
            saveSettings({ force_offline: val });
            
            // Immediate UI Update for the countdown badge
            const cdEl = document.getElementById('sync-countdown');
            if (val && cdEl) {
                cdEl.innerText = 'OFF';
                cdEl.style.background = 'var(--text-secondary)';
                cdEl.style.opacity = '0.5';
            }
        };

        if (window.wbForceOffline) {
            wbSetOnlineState(false);
        } else {
            // Check connection on load
            wbCheckConnection(true);
        }

        // Performance Settings
        window.rasterCacheEnabled = <?php echo ($settings['raster_cache_enabled'] ?? true) ? 'true' : 'false'; ?>;
        window.vectorThreshold = parseInt("<?php echo $settings['vector_handoff_threshold'] ?? 1000; ?>") || 1000;
        window.cacheResolution = parseFloat("<?php echo $settings['cache_resolution'] ?? 3.0; ?>") || 3.0;

        if (document.getElementById('raster-cache-toggle')) document.getElementById('raster-cache-toggle').checked = window.rasterCacheEnabled;
        if (document.getElementById('vector-thresh-slider')) {
            document.getElementById('vector-thresh-slider').value = window.vectorThreshold;
            document.getElementById('vector-thresh-display').innerText = window.vectorThreshold + '%';
        }
        if (document.getElementById('cache-res-slider')) {
            document.getElementById('cache-res-slider').value = window.cacheResolution;
            document.getElementById('cache-res-display').innerText = parseFloat(window.cacheResolution).toFixed(1) + 'x';
            if (window.cacheResolution > 5) document.getElementById('cache-mem-warning').style.display = 'block';
        }

        window._thumbRes = <?php echo $settings['thumb_res'] ?? 200; ?>;
        window._thumbQual = <?php echo $settings['thumb_quality'] ?? 0.5; ?>;

        window._initialHash = '<?php echo $build_hash; ?>';
        window._rotationEnabled = <?php echo ($settings['rotation_enabled'] ?? true) ? 'true' : 'false'; ?>;
        window._autoUpdateEnabled = <?php echo ($settings['auto_update'] ?? true) ? 'true' : 'false'; ?>;

        lastSyncedId = window._initialId || 0;
        startUpdatePoller();
        startDataPoller();
        refreshPresets();
        initSizeSwipe();
        if (typeof initSplitDrag === 'function') initSplitDrag();
        if (typeof initTextToolDrag === 'function') initTextToolDrag();
    });
</script>



</body>
</html>