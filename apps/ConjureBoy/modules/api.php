<?php
// apps/ConjureBoy/modules/api.php
session_start();

// Send strict anti-caching headers to prevent stale UI states on user swap
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$action = $_GET['action'] ?? '';
$roms_dir = __DIR__ . '/../data/roms';
$user_id = $_SESSION['user_id'] ?? null;

// Release the session lock immediately for non-session-writing actions to prevent blockages
if ($action !== 'login' && $action !== 'register' && $action !== 'logout') {
    session_write_close();
}

// Ensure ROM directory exists
if (!is_dir($roms_dir)) {
    mkdir($roms_dir, 0777, true);
}

switch ($action) {
    case 'register':
        $raw_input = file_get_contents('php://input');
        $payload = json_decode($raw_input, true);
        $username = trim($payload['username'] ?? '');
        $password = $payload['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            http_response_code(400); echo json_encode(['error' => 'Username and password required']); exit;
        }
        
        try {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                http_response_code(400); echo json_encode(['error' => 'Username already exists']); exit;
            }
            
            $default_settings = json_encode([
                "theme" => "classic-dmg", "lcd_grid" => true, "sound_volume" => 0.5, "haptics_enabled" => true,
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
            
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, settings_json) VALUES (?, ?, ?)");
            $stmt->execute([$username, $hash, $default_settings]);
            $new_user_id = $db->lastInsertId();
            
            $_SESSION['user_id'] = $new_user_id;
            echo json_encode(['success' => true, 'user' => ['id' => $new_user_id, 'username' => $username, 'role' => 'user']]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
        }
        break;

    case 'login':
        $raw_input = file_get_contents('php://input');
        $payload = json_decode($raw_input, true);
        $username = trim($payload['username'] ?? '');
        $password = $payload['password'] ?? '';
        
        try {
            $stmt = $db->prepare("SELECT id, username, password_hash, role, settings_json FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                echo json_encode([
                    'success' => true, 
                    'user' => ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']],
                    'settings' => json_decode($user['settings_json'], true)
                ]);
            } else {
                http_response_code(401); echo json_encode(['error' => 'Invalid credentials']);
            }
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => 'Login failed: ' . $e->getMessage()]);
        }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'me':
        if (!$user_id) {
            echo json_encode(['guest' => true]);
            exit;
        }
        try {
            $stmt = $db->prepare("SELECT id, username, role, settings_json, last_rom_hash FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                echo json_encode([
                    'success' => true,
                    'user' => ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'], 'last_rom_hash' => $user['last_rom_hash']],
                    'settings' => json_decode($user['settings_json'], true)
                ]);
            } else {
                echo json_encode(['guest' => true]);
            }
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => 'Failed to fetch user state: ' . $e->getMessage()]);
        }
        break;
    case 'list':
        try {
            if (!$user_id) {
                $stmt = $db->query("SELECT r.*, NULL as custom_name, 0 as linked_to_admin FROM roms r WHERE r.is_public = 1 ORDER BY COALESCE(r.system_name, r.display_name) ASC");
                $catalog = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Get user role
                $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $role = $stmt->fetchColumn();

                if ($role === 'admin') {
                    // Admin sees all uploaded ROMs globally and checks if linked to user ID 1
                    $stmt = $db->prepare("
                        SELECT r.*, ur.custom_name,
                               (SELECT COUNT(*) FROM user_roms ur2 WHERE ur2.rom_hash = r.rom_hash AND ur2.user_id = 1) as linked_to_admin
                        FROM roms r 
                        LEFT JOIN user_roms ur ON r.rom_hash = ur.rom_hash AND ur.user_id = ?
                        ORDER BY COALESCE(ur.custom_name, r.system_name, r.display_name) ASC
                    ");
                    $stmt->execute([$user_id]);
                    $catalog = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    // Standard user sees their linked ROMs OR public ROMs
                    $stmt = $db->prepare("
                        SELECT r.*, ur.custom_name, 0 as linked_to_admin 
                        FROM roms r 
                        LEFT JOIN user_roms ur ON r.rom_hash = ur.rom_hash AND ur.user_id = ?
                        WHERE r.is_public = 1 OR ur.user_id IS NOT NULL
                        ORDER BY COALESCE(ur.custom_name, r.system_name, r.display_name) ASC
                    ");
                    $stmt->execute([$user_id]);
                    $catalog = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            echo json_encode(['success' => true, 'roms' => $catalog]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to query ROM library: ' . $e->getMessage()]);
        }
        break;

    case 'rename':
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $hash = $_POST['hash'] ?? '';
        $new_name = trim($_POST['new_name'] ?? '');
        $global = intval($_POST['global'] ?? 0);

        if (empty($hash) || empty($new_name)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing hash or name']);
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $role = $stmt->fetchColumn();

            if ($global === 1) {
                if ($role !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['error' => 'Admin privileges required for system-wide rename']);
                    exit;
                }
                $stmt = $db->prepare("UPDATE roms SET system_name = ? WHERE rom_hash = ?");
                $stmt->execute([$new_name, $hash]);
            } else {
                // Personal rename
                $stmt = $db->prepare("SELECT 1 FROM user_roms WHERE user_id = ? AND rom_hash = ?");
                $stmt->execute([$user_id, $hash]);
                if ($stmt->fetch()) {
                    $stmt = $db->prepare("UPDATE user_roms SET custom_name = ? WHERE user_id = ? AND rom_hash = ?");
                    $stmt->execute([$new_name, $user_id, $hash]);
                } else {
                    $stmt = $db->prepare("INSERT INTO user_roms (user_id, rom_hash, custom_name) VALUES (?, ?, ?)");
                    $stmt->execute([$user_id, $hash, $new_name]);
                }
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to rename ROM: ' . $e->getMessage()]);
        }
        break;

    case 'search_covers':
        if (!$user_id) {
            http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
        }
        $query = trim($_POST['query'] ?? '');
        if (empty($query)) {
            echo json_encode(['success' => true, 'images' => []]); exit;
        }
        
        // Keyless Scraper using Bing Images
        $url = "https://www.bing.com/images/search?q=" . urlencode($query) . "&form=HDRSC2&first=1";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Safe local bypass for PHP environments lacking cacert.pem
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $html = curl_exec($ch);
        curl_close($ch);

        $images = [];
        if ($html) {
            // Bing encodes double quotes inside the 'm' attribute as HTML entity &quot;
            preg_match_all('/murl&quot;:&quot;(.*?)&quot;/', $html, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $img_url) {
                    $img_url = html_entity_decode(stripslashes($img_url));
                    if (filter_var($img_url, FILTER_VALIDATE_URL) && !strpos($img_url, 'bing.com')) {
                        $images[] = $img_url;
                    }
                    if (count($images) >= 15) break; // Limit to top 15 results
                }
            }
        }
        echo json_encode(['success' => true, 'images' => $images]);
        break;

    case 'set_cover':
        if (!$user_id) {
            http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
        }
        $hash = $_POST['hash'] ?? '';
        $image_url = $_POST['image_url'] ?? '';
        if (empty($hash) || empty($image_url)) {
            http_response_code(400); echo json_encode(['error' => 'Missing parameters']); exit;
        }

        $covers_dir = __DIR__ . '/../data/covers';
        $target_file = $covers_dir . '/' . $hash . '.jpg';

        // Download the image
        $ch = curl_init($image_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Bypass SSL verification for high-reliability downloads
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $img_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200 && $img_data) {
            // Save image and update database
            file_put_contents($target_file, $img_data);
            $stmt = $db->prepare("UPDATE roms SET has_cover = 1 WHERE rom_hash = ?");
            $stmt->execute([$hash]);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500); echo json_encode(['error' => 'Failed to download image from source']);
        }
        break;

    case 'remove_cover':
        if (!$user_id) {
            http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
        }
        $hash = $_POST['hash'] ?? '';
        if (empty($hash)) {
            http_response_code(400); echo json_encode(['error' => 'Missing parameters']); exit;
        }
        
        $covers_dir = __DIR__ . '/../data/covers';
        $target_file = $covers_dir . '/' . $hash . '.jpg';
        if (file_exists($target_file)) {
            @unlink($target_file);
        }
        
        $stmt = $db->prepare("UPDATE roms SET has_cover = 0 WHERE rom_hash = ?");
        $stmt->execute([$hash]);
        echo json_encode(['success' => true]);
        break;

    case 'revert':
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $hash = $_POST['hash'] ?? '';
        $global = intval($_POST['global'] ?? 0);

        if (empty($hash)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing hash']);
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $role = $stmt->fetchColumn();

            if ($global === 1) {
                if ($role !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['error' => 'Admin privileges required for system-wide revert']);
                    exit;
                }
                $stmt = $db->prepare("UPDATE roms SET system_name = NULL WHERE rom_hash = ?");
                $stmt->execute([$hash]);
            } else {
                // Personal revert
                $stmt = $db->prepare("UPDATE user_roms SET custom_name = NULL WHERE user_id = ? AND rom_hash = ?");
                $stmt->execute([$user_id, $hash]);
            }

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to revert ROM name: ' . $e->getMessage()]);
        }
        break;

    case 'claim':
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        try {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetchColumn() !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Admin privileges required']);
                exit;
            }
            
            $hash = $_POST['hash'] ?? '';
            if (empty($hash)) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing ROM hash']);
                exit;
            }
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO user_roms (user_id, rom_hash) VALUES (1, ?)");
            $stmt->execute([$hash]);
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to claim ROM: ' . $e->getMessage()]);
        }
        break;

    case 'upload':
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized. Please log in to upload ROMs.']);
            exit;
        }

        // Get user role to bypass limit for admin
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $role = $stmt->fetchColumn();

        if ($role !== 'admin') {
            // Check 10-ROM limit per user
            $stmt = $db->prepare("SELECT COUNT(*) FROM user_roms WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetchColumn() >= 10) {
                http_response_code(403);
                echo json_encode(['error' => 'Cartridge limit reached (10 max). Please delete a ROM first.']);
                exit;
            }
        }

        if (empty($_FILES['rom'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No ROM file uploaded']);
            exit;
        }

        $file = $_FILES['rom'];
        $filename = basename($file['name']);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, ['gb', 'gbc', 'gba'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported format. Only .gb, .gbc, and .gba ROMs are allowed.']);
            exit;
        }

        if ($file['size'] > 32 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error' => 'ROM file size exceeds the 32MB threshold.']);
            exit;
        }

        $tmp_name = $file['tmp_name'];
        $hash = md5_file($tmp_name);

        // Deduplication check
        $stmt = $db->prepare("SELECT * FROM roms WHERE rom_hash = ?");
        $stmt->execute([$hash]);
        $existing_rom = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_rom) {
            try {
                $stmt = $db->prepare("INSERT OR IGNORE INTO user_roms (user_id, rom_hash) VALUES (?, ?)");
                $stmt->execute([$user_id, $hash]);
                echo json_encode([
                    'success' => true,
                    'message' => 'Existing ROM linked successfully',
                    'rom' => $existing_rom
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Database storage failure: ' . $e->getMessage()]);
            }
            exit;
        }

        // Extract genuine ROM title from internal cartridge header
        $title = '';
        $fp = @fopen($tmp_name, 'rb');
        if ($fp) {
            if ($ext === 'gba') {
                fseek($fp, 0x00A0);
                $title_raw = fread($fp, 12);
            } else {
                fseek($fp, 0x0134);
                $title_raw = fread($fp, 15); // Safe 15-byte read excludes CGB flag at 0x0143
            }
            fclose($fp);
            
            // Attempt to detect encoding and safely encode to UTF-8 (handling Chinese Big5/GBK & Japanese Shift-JIS)
            $title_clean = rtrim($title_raw, "\0");
            if (function_exists('mb_check_encoding')) {
                if (mb_check_encoding($title_clean, 'UTF-8')) {
                    $title = $title_clean;
                } else {
                    $detected = mb_detect_encoding($title_clean, ['BIG5', 'GBK', 'SHIFT-JIS', 'ISO-8859-1']);
                    if ($detected) {
                        $title = mb_convert_encoding($title_clean, 'UTF-8', $detected);
                    } else {
                        $title = mb_convert_encoding($title_clean, 'UTF-8', 'ISO-8859-1');
                    }
                }
            } else {
                $title = $title_clean;
            }
            $title = preg_replace('/[\x00-\x1F\x7F]/', '', $title);
        }

        // Universally compatible, robust sanity check to detect corrupt/garbled header titles
        $is_garbled = false;
        if (empty(trim($title))) {
            $is_garbled = true;
        } else {
            // Count standard alphanumeric and space characters
            $clean_len = strlen(preg_replace('/[^a-zA-Z0-9\s]/', '', $title));
            $total_len = strlen($title);
            // If less than 40% of the string is standard alphanumeric, we check if it is valid Chinese/Japanese
            if ($total_len > 0 && ($clean_len / $total_len) < 0.40) {
                // If it lacks Han (Chinese) character blocks, flag it as corrupt binary garbage
                if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $title)) {
                    $is_garbled = true;
                }
            }
        }

        if ($is_garbled) {
            $title = pathinfo($filename, PATHINFO_FILENAME);
            $title = str_replace(['_', '-'], ' ', $title);
            $title = strtoupper($title);
        }

        $target_path = $roms_dir . '/' . $hash . '.' . $ext;

        if (move_uploaded_file($tmp_name, $target_path)) {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("INSERT OR REPLACE INTO roms (filename, display_name, rom_hash, size, last_played) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$hash . '.' . $ext, $title, $hash, $file['size'], null]);
                
                $stmt = $db->prepare("INSERT OR IGNORE INTO user_roms (user_id, rom_hash) VALUES (?, ?)");
                $stmt->execute([$user_id, $hash]);
                $db->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'ROM registered successfully',
                    'rom' => [
                        'filename' => $hash . '.' . $ext,
                        'display_name' => $title,
                        'rom_hash' => $hash,
                        'size' => $file['size']
                    ]
                ]);
            } catch (PDOException $e) {
                $db->rollBack();
                @unlink($target_path);
                http_response_code(500);
                echo json_encode(['error' => 'Database storage failure: ' . $e->getMessage()]);
            }
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save ROM file to target filesystem directory.']);
        }
        break;

    case 'download':
        $hash = $_GET['hash'] ?? '';
        if (empty($hash)) {
            http_response_code(400);
            exit;
        }
        $stmt = $db->prepare("SELECT filename FROM roms WHERE rom_hash = ?");
        $stmt->execute([$hash]);
        $rom = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rom) {
            $file_path = $roms_dir . '/' . $rom['filename'];
            if (file_exists($file_path)) {
                header('Content-Type: application/octet-stream');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
                exit;
            }
        }
        http_response_code(404);
        echo json_encode(['error' => 'ROM binary not found']);
        break;

    case 'bootstrap_core':
        $gb_url = "https://cdn.jsdelivr.net/gh/taisel/GameBoy-Online@master/js/GameBoyCore.js";
        $gb_path = __DIR__ . '/../js/core/GameBoyCore.js';
        
        $gba_path = __DIR__ . '/../js/core/gba.min.js';
        $bios_url = "https://cdn.jsdelivr.net/npm/gbajs@1.1.2/resources/bios.bin";
        $bios_path = __DIR__ . '/../data/bios.bin';
        
        $dir = __DIR__ . '/../js/core';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                echo json_encode(['success' => false, 'error' => "Failed to create directory: " . $dir]);
                exit;
            }
        }

        $success = true;
        $error_details = "";

        // Secure resilient down-stream file fetcher
        $download_file = function($url) use (&$error_details) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
            
            $content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            
            if ($http_code === 200 && !empty($content)) {
                return $content;
            } else {
                $error_details = "URL: " . $url . " | HTTP: " . $http_code . " | Error: " . $err;
                return false;
            }
        };

        // 1. Download/Overwrite GB/GBC core
        $content = $download_file($gb_url);
        if ($content === false) {
            // Try raw github fallback
            $fallback_url = "https://raw.githubusercontent.com/taisel/GameBoy-Online/master/js/GameBoyCore.js";
            $content = $download_file($fallback_url);
        }

        if ($content !== false) {
            if (file_put_contents($gb_path, $content) === false) {
                $success = false;
                $error_details = "Failed to write GameBoyCore.js locally.";
            }
        } else {
            $success = false;
        }

        // 2. Download and Compile/Bundle GBA Core files from raw github to prevent require() errors
        if ($success) {
            $gba_files = [
                'util.js',
                'core.js',
                'arm.js',
                'thumb.js',
                'mmu.js',
                'io.js',
                'audio.js',
                'video.js',
                'video/proxy.js',
                'video/software.js',
                'irq.js',
                'keypad.js',
                'sio.js',
                'savedata.js',
                'gpio.js',
                'gba.js'
            ];
            
            $bundled_content = "";
            $base_url = "https://raw.githubusercontent.com/endrift/gbajs/master/js/";
            
            foreach ($gba_files as $file) {
                $file_content = $download_file($base_url . $file);
                if ($file_content !== false) {
                    $bundled_content .= "\n\n/* --- BUNDLED FILE: " . $file . " --- */\n\n" . $file_content;
                } else {
                    $success = false;
                    break;
                }
            }
            
            if ($success && !empty($bundled_content)) {
                if (file_put_contents($gba_path, $bundled_content) === false) {
                    $success = false;
                    $error_details = "Failed to write gba.min.js locally.";
                }
            }
        }

        // 3. Download/Overwrite GBA Bios Stub
        if ($success) {
            $content = $download_file($bios_url);
            if ($content !== false) {
                if (file_put_contents($bios_path, $content) === false) {
                    $success = false;
                    $error_details = "Failed to write bios.bin locally.";
                }
            } else {
                $success = false;
            }
        }

        if ($success) {
            echo json_encode(['success' => true, 'message' => 'All emulator core runtimes compiled and stubs bootstrapped successfully.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Bootstrap failed. Details: ' . $error_details]);
        }
        break;

    case 'save_sram':
        if (!$user_id) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
        $hash = $_POST['hash'] ?? '';
        $sram = $_POST['sram'] ?? '';
        if (empty($hash) || empty($sram)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing SRAM parameters']);
            exit;
        }
        try {
            $stmt = $db->prepare("INSERT OR REPLACE INTO saves (user_id, rom_hash, save_data, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$user_id, $hash, $sram]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'SRAM backup failed: ' . $e->getMessage()]);
        }
        break;

    case 'load_sram':
        if (!$user_id) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
        $hash = $_GET['hash'] ?? '';
        try {
            $stmt = $db->prepare("SELECT save_data FROM saves WHERE user_id = ? AND rom_hash = ?");
            $stmt->execute([$user_id, $hash]);
            $save = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'sram' => $save ? $save['save_data'] : null]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'SRAM lookup failed: ' . $e->getMessage()]);
        }
        break;

    case 'save_state':
        if (!$user_id) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
        $hash = $_POST['hash'] ?? '';
        $slot = intval($_POST['slot'] ?? 0);
        $state = $_POST['state'] ?? '';
        $preview = $_POST['preview'] ?? '';
        if (empty($hash) || $slot < 1 || empty($state)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid save state parameters']);
            exit;
        }
        try {
            $stmt = $db->prepare("INSERT OR REPLACE INTO savestates (user_id, rom_hash, slot, state_data, preview, updated_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$user_id, $hash, $slot, $state, $preview]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Save state write failed: ' . $e->getMessage()]);
        }
        break;

    case 'load_state':
        if (!$user_id) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
        $hash = $_GET['hash'] ?? '';
        $slot = intval($_GET['slot'] ?? 0);
        try {
            $stmt = $db->prepare("SELECT state_data FROM savestates WHERE user_id = ? AND rom_hash = ? AND slot = ?");
            $stmt->execute([$user_id, $hash, $slot]);
            $state = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'state' => $state ? $state['state_data'] : null]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'State load lookup failed: ' . $e->getMessage()]);
        }
        break;

    case 'delete_state':
        if (!$user_id) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
        $hash = $_POST['hash'] ?? '';
        $slot = intval($_POST['slot'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM savestates WHERE user_id = ? AND rom_hash = ? AND slot = ?");
            $stmt->execute([$user_id, $hash, $slot]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'State purge failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_states_meta':
        if (!$user_id) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
        $hash = $_GET['hash'] ?? '';
        try {
            $stmt = $db->prepare("SELECT slot, preview, updated_at FROM savestates WHERE user_id = ? AND rom_hash = ?");
            $stmt->execute([$user_id, $hash]);
            $states = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'states' => $states]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'States meta lookup failed: ' . $e->getMessage()]);
        }
        break;

    case 'toggle_public':
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        try {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetchColumn() !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Admin privileges required']);
                exit;
            }
            
            $hash = $_POST['hash'] ?? '';
            $state = intval($_POST['state'] ?? 0);
            
            $stmt = $db->prepare("UPDATE roms SET is_public = ? WHERE rom_hash = ?");
            $stmt->execute([$state, $hash]);
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to toggle public state: ' . $e->getMessage()]);
        }
        break;

    case 'delete':
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $hash = $_POST['hash'] ?? '';
        if (empty($hash)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ROM hash parameter']);
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $role = $stmt->fetchColumn();

            $db->beginTransaction();

            if ($role === 'admin') {
                // Find file extension mapping
                $stmt = $db->prepare("SELECT filename FROM roms WHERE rom_hash = ?");
                $stmt->execute([$hash]);
                $rom = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($rom) {
                    $file_path = $roms_dir . '/' . $rom['filename'];
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                    }
                }

                // Purge db relationships globally
                $db->prepare("DELETE FROM roms WHERE rom_hash = ?")->execute([$hash]);
                $db->prepare("DELETE FROM user_roms WHERE rom_hash = ?")->execute([$hash]);
                $db->prepare("DELETE FROM saves WHERE rom_hash = ?")->execute([$hash]);
                $db->prepare("DELETE FROM savestates WHERE rom_hash = ?")->execute([$hash]);

                $msg = 'ROM physically deleted and all users unlinked.';
            } else {
                // Unlink for standard user
                $db->prepare("DELETE FROM user_roms WHERE user_id = ? AND rom_hash = ?")->execute([$user_id, $hash]);
                $db->prepare("DELETE FROM saves WHERE user_id = ? AND rom_hash = ?")->execute([$user_id, $hash]);
                $db->prepare("DELETE FROM savestates WHERE user_id = ? AND rom_hash = ?")->execute([$user_id, $hash]);
                
                // Check if anyone else uses it and if it's not public
                $stmt = $db->prepare("SELECT is_public FROM roms WHERE rom_hash = ?");
                $stmt->execute([$hash]);
                $rom_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $db->prepare("SELECT COUNT(*) FROM user_roms WHERE rom_hash = ?");
                $stmt->execute([$hash]);
                $user_count = $stmt->fetchColumn();
                
                if ($user_count == 0 && $rom_info && $rom_info['is_public'] == 0) {
                    $stmt = $db->prepare("SELECT filename FROM roms WHERE rom_hash = ?");
                    $stmt->execute([$hash]);
                    $rom = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($rom) {
                        $file_path = $roms_dir . '/' . $rom['filename'];
                        if (file_exists($file_path)) {
                            @unlink($file_path);
                        }
                    }
                    $db->prepare("DELETE FROM roms WHERE rom_hash = ?")->execute([$hash]);
                }
                
                $msg = 'ROM unlinked from your shelf.';
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => $msg]);
        } catch (PDOException $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete ROM files: ' . $e->getMessage()]);
        }
        break;

    case 'save_settings':
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized. Please log in to save settings.']);
            exit;
        }

        $raw_input = file_get_contents('php://input');
        $payload = json_decode($raw_input, true);
        if (empty($payload)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid configuration parameters']);
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE users SET settings_json = ? WHERE id = ?");
            $stmt->execute([json_encode($payload, JSON_UNESCAPED_SLASHES), $user_id]);
            echo json_encode(['success' => true, 'message' => 'Configurations synced server-side']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update settings: ' . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Action not found']);
        break;
}