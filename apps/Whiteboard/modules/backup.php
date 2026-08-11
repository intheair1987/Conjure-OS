<?php
// Whiteboard Backup & Restore Module

function wb_handle_backup($db) {
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) return;

    // --- EXPORT DATA ---
    if ($_POST['action'] === 'export_data') {
        try {
    $data =[
        'folders' => $db->query("SELECT * FROM folders")->fetchAll(PDO::FETCH_ASSOC),
        'canvases' => $db->query("SELECT * FROM canvases")->fetchAll(PDO::FETCH_ASSOC),
        'drawings' => $db->query("SELECT * FROM drawings")->fetchAll(PDO::FETCH_ASSOC),
        'stickers' => $db->query("SELECT * FROM stickers")->fetchAll(PDO::FETCH_ASSOC),
        'presets' => $db->query("SELECT * FROM presets")->fetchAll(PDO::FETCH_ASSOC),
        'settings' => []
    ];$settings_path = dirname(__DIR__) . '/data/settings.json';
            if (file_exists($settings_path)) {
                $data['settings'] = json_decode(file_get_contents($settings_path), true);
            }

            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="whiteboard_backup_' . date('Y-m-d_His') . '.json"');
            echo json_encode($data);
            exit;
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    // --- IMPORT DATA ---
    if ($_POST['action'] === 'import_data') {
        $json = $_POST['data'] ?? '';
        $data = json_decode($json, true);
        
        if (!$data || !isset($data['drawings'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or incomplete backup file.']);
            exit;
        }

        try {
            $db->beginTransaction();
            
            // 1. Clear existing tables
            $db->exec("DELETE FROM folders");
            $db->exec("DELETE FROM canvases");
            $db->exec("DELETE FROM drawings");
            $db->exec("DELETE FROM stickers");
            $db->exec("DELETE FROM presets");

            // 1.5 Import Folders & Canvases
            if (!empty($data['folders'])) {
                $stmt = $db->prepare("INSERT INTO folders (id, name, parent_id, created_at) VALUES (?, ?, ?, ?)");
                foreach ($data['folders'] as $row) {
                    $stmt->execute([$row['id'], $row['name'], $row['parent_id'], $row['created_at']]);
                }
            }
            if (!empty($data['canvases'])) {
                $stmt = $db->prepare("INSERT INTO canvases (id, name, folder_id, thumbnail, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($data['canvases'] as $row) {
                    $stmt->execute([$row['id'], $row['name'], $row['folder_id'], $row['thumbnail'], $row['created_at'], $row['updated_at']]);
                }
            }

            // 2. Import Drawings
            if (!empty($data['drawings'])) {
                $stmt = $db->prepare("INSERT INTO drawings (id, data, updated_at) VALUES (?, ?, ?)");
                foreach ($data['drawings'] as $row) {
                    $stmt->execute([$row['id'], $row['data'], $row['updated_at']]);
                }
            }

            // 3. Import Stickers
            if (!empty($data['stickers'])) {
                $stmt = $db->prepare("INSERT INTO stickers (id, data, created_at) VALUES (?, ?, ?)");
                foreach ($data['stickers'] as $row) {
                    $stmt->execute([$row['id'], $row['data'], $row['created_at']]);
                }
            }

            // 4. Import Presets
            if (!empty($data['presets'])) {
                $stmt = $db->prepare("INSERT INTO presets (id, size, created_at) VALUES (?, ?, ?)");
                foreach ($data['presets'] as $row) {
                    $stmt->execute([$row['id'], $row['size'], $row['created_at']]);
                }
            }

            // 5. Import Settings
            if (isset($data['settings']) && !empty($data['settings'])) {
                $settings_path = dirname(__DIR__) . '/data/settings.json';
                if (!is_dir(dirname($settings_path))) mkdir(dirname($settings_path), 0777, true);
                file_put_contents($settings_path, json_encode($data['settings'], JSON_PRETTY_PRINT));
            }

            $db->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}
?>