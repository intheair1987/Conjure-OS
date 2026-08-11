<?php
// apps/ConjureBoy/modules/db.php

$db_file = __DIR__ . '/../app.db';
$db_exists = file_exists($db_file);

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA busy_timeout = 5000"); // Smooth out concurrent write contentions
    
    // Create ROMs metadata table
$db->exec("CREATE TABLE IF NOT EXISTS roms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT UNIQUE,
    display_name TEXT,
    rom_hash TEXT UNIQUE,
    size INTEGER,
    play_time INTEGER DEFAULT 0,
    last_played TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$columns = $db->query("PRAGMA table_info(roms)")->fetchAll(PDO::FETCH_ASSOC);
$hasIsPublic = false;
foreach ($columns as $col) {
    if ($col['name'] === 'is_public') $hasIsPublic = true;
}
if (!$hasIsPublic) {
    $db->exec("ALTER TABLE roms ADD COLUMN is_public INTEGER DEFAULT 0");
}

$hasSystemName = false;
foreach ($columns as $col) {
    if ($col['name'] === 'system_name') $hasSystemName = true;
}
if (!$hasSystemName) {
    $db->exec("ALTER TABLE roms ADD COLUMN system_name TEXT DEFAULT NULL");
}

$hasCover = false;
foreach ($columns as $col) {
    if ($col['name'] === 'has_cover') $hasCover = true;
}
if (!$hasCover) {
    $db->exec("ALTER TABLE roms ADD COLUMN has_cover INTEGER DEFAULT 0");
}

// Create Users table
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    password_hash TEXT,
    role TEXT DEFAULT 'user',
    settings_json TEXT,
    last_rom_hash TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Ensure a default admin user exists to inherit legacy data
$stmt = $db->query("SELECT id FROM users WHERE id = 1");
if (!$stmt->fetch()) {
    $default_settings = json_encode([
        "theme" => "classic-dmg",
        "lcd_grid" => true,
        "sound_volume" => 0.5,
        "haptics_enabled" => true,
        "magnifier_scale" => 1.15,
        "keyboard_bindings" => [
            "ArrowUp" => "UP", "ArrowDown" => "DOWN", "ArrowLeft" => "LEFT", "ArrowRight" => "RIGHT",
            "KeyZ" => "A", "KeyX" => "B", "Enter" => "START", "ShiftLeft" => "SELECT"
        ],
        "design_tokens" => [
            "--bg-color" => "#131416", "--card-bg" => "#1e2022", "--text-primary" => "#f8f9fa",
            "--text-secondary" => "#a6abb1", "--primary-accent" => "#8b956d",
            "--font-main" => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
            "--radius-container" => "20px"
        ]
    ]);
    $admin_pass = password_hash('admin', PASSWORD_DEFAULT);
$insert_stmt = $db->prepare("INSERT INTO users (id, username, password_hash, role, settings_json) VALUES (1, 'admin', ?, 'admin', ?)");
$insert_stmt->execute([$admin_pass, $default_settings]);
        }// Create User-ROMs linker table
$db->exec("CREATE TABLE IF NOT EXISTS user_roms (
    user_id INTEGER,
    rom_hash TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(user_id, rom_hash)
)");

$columns = $db->query("PRAGMA table_info(user_roms)")->fetchAll(PDO::FETCH_ASSOC);
$hasCustomName = false;
foreach ($columns as $col) {
    if ($col['name'] === 'custom_name') $hasCustomName = true;
}
if (!$hasCustomName) {
    $db->exec("ALTER TABLE user_roms ADD COLUMN custom_name TEXT DEFAULT NULL");
}

// Check for migration needed on saves/savestates
$columns = $db->query("PRAGMA table_info(saves)")->fetchAll(PDO::FETCH_ASSOC);
$tableExists = count($columns) > 0;
$hasUserId = false;
foreach ($columns as $col) {
    if ($col['name'] === 'user_id') $hasUserId = true;
}

if ($tableExists && !$hasUserId) {
    $db->beginTransaction();
    try {
        // 1. Migrate saves
        $db->exec("CREATE TABLE saves_new (
            user_id INTEGER,
            rom_hash TEXT,
            save_data BLOB,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(user_id, rom_hash)
        )");
        $db->exec("INSERT INTO saves_new (user_id, rom_hash, save_data, updated_at) SELECT 1, rom_hash, save_data, updated_at FROM saves");
        $db->exec("DROP TABLE saves");
        $db->exec("ALTER TABLE saves_new RENAME TO saves");

        // 2. Migrate savestates
        $db->exec("CREATE TABLE savestates_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            rom_hash TEXT,
            slot INTEGER,
            state_data BLOB,
            preview TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, rom_hash, slot)
        )");
        $db->exec("INSERT INTO savestates_new (id, user_id, rom_hash, slot, state_data, preview, updated_at) SELECT id, 1, rom_hash, slot, state_data, preview, updated_at FROM savestates");
        $db->exec("DROP TABLE savestates");
        $db->exec("ALTER TABLE savestates_new RENAME TO savestates");

        // 3. Link existing ROMs to the admin user
        $db->exec("INSERT OR IGNORE INTO user_roms (user_id, rom_hash) SELECT 1, rom_hash FROM roms");

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
} else {
    // Standard table creations if migration already ran or new install
    $db->exec("CREATE TABLE IF NOT EXISTS saves (
        user_id INTEGER,
        rom_hash TEXT,
        save_data BLOB,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(user_id, rom_hash)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS savestates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        rom_hash TEXT,
        slot INTEGER,
        state_data BLOB,
        preview TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, rom_hash, slot)
    )");

    // Run a non-destructive database self-repair routine to re-parse and fix existing corrupted display names
    $repair_flag = __DIR__ . '/../data/.self_repair_done';
    if (!file_exists($repair_flag)) {
        try {
            $stmt = $db->query("SELECT id, filename, display_name FROM roms");
            $all_roms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $roms_dir = __DIR__ . '/../data/roms';
            
            foreach ($all_roms as $r) {
                $file_path = $roms_dir . '/' . $r['filename'];
                if (file_exists($file_path)) {
                    $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
                    $fp = @fopen($file_path, 'rb');
                    if ($fp) {
                        if ($ext === 'gba') {
                            fseek($fp, 0x00A0);
                            $title_raw = fread($fp, 12);
                        } else {
                            fseek($fp, 0x0134);
                            $title_raw = fread($fp, 15); // Safe 15-byte read excludes CGB flag at 0x0143
                        }
                        fclose($fp);
                        
                        $title_clean = rtrim($title_raw, "\0");
                        if (function_exists('mb_check_encoding')) {
                            if (!mb_check_encoding($title_clean, 'UTF-8')) {
                                $detected = mb_detect_encoding($title_clean, ['BIG5', 'GBK', 'SHIFT-JIS', 'ISO-8859-1']);
                                if ($detected) {
                                    $title_clean = mb_convert_encoding($title_clean, 'UTF-8', $detected);
                                } else {
                                    $title_clean = mb_convert_encoding($title_clean, 'UTF-8', 'ISO-8859-1');
                                }
                            }
                        }
                        $title_clean = preg_replace('/[\x00-\x1F\x7F]/', '', $title_clean);
                        
                        // Sane fallback if title is garbled
                        $is_garbled = false;
                        if (empty(trim($title_clean))) {
                            $is_garbled = true;
                        } else {
                            $clean_len = strlen(preg_replace('/[^a-zA-Z0-9\s]/', '', $title_clean));
                            $total_len = strlen($title_clean);
                            if ($total_len > 0 && ($clean_len / $total_len) < 0.40) {
                                if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $title_clean)) {
                                    $is_garbled = true;
                                }
                            }
                        }
                        
                        if ($is_garbled) {
                            $title_clean = pathinfo($r['filename'], PATHINFO_FILENAME);
                            $title_clean = str_replace(['_', '-'], ' ', $title_clean);
                            $title_clean = strtoupper($title_clean);
                        } else {
                            $title_clean = trim($title_clean);
                        }
                        
                        if ($title_clean !== $r['display_name']) {
                            $up_stmt = $db->prepare("UPDATE roms SET display_name = ? WHERE id = ?");
                            $up_stmt->execute([$title_clean, $r['id']]);
                        }
                    }
                }
            }
            if (!is_dir(dirname($repair_flag))) {
                @mkdir(dirname($repair_flag), 0777, true);
            }
            @file_put_contents($repair_flag, 'completed');
        } catch (Exception $e) {
            // Fail silently to prevent site lockouts if files are busy
        }
    }
}} catch (PDOException $e) {
    die("Database initialization failed: " . $e->getMessage());
}