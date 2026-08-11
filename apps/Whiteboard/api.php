<?php
/**
 * Whiteboard Media API
 * Allows external apps to upload assets to the Whiteboard database.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$db_path = __DIR__ . '/app.db';
$secrets_path = __DIR__ . '/data/secrets-private.json';

// 1. Load/Initialize API Key
if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
if (!file_exists($secrets_path)) {
    $new_key = bin2hex(random_bytes(16));
    file_put_contents($secrets_path, json_encode(['api_key' => $new_key]));
}
$secrets = json_decode(file_get_contents($secrets_path), true);

// 2. Validate Request
$api_key = $_POST['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($api_key !== $secrets['api_key']) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API Key']);
    exit;
}

// 3. Process Image Data
$raw_data = '';
if (isset($_FILES['image'])) {
    $raw_data = 'data:' . $_FILES['image']['type'] . ';base64,' . base64_encode(file_get_contents($_FILES['image']['tmp_name']));
} elseif (isset($_POST['image_base64'])) {
    $raw_data = $_POST['image_base64']; // Expects full dataURL
}

if (empty($raw_data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No image data provided. Use "image" (file) or "image_base64" (string).']);
    exit;
}

// 4. Calculate Hash (Must match Whiteboard's SHA-1 logic)
// We hash the raw string data to match the JS implementation
$hash = sha1($raw_data);

// 5. Save to Filesystem & Database
try {
    // Sanitize and trim slashes to ensure clean path joining
    $folder_raw = $_POST['folder'] ?? 'API_Uploads';
    $folder = trim(preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $folder_raw), '/');
    
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['filename'] ?? ('asset_' . substr($hash, 0, 8) . '.jpg'));

    $assets_dir = __DIR__ . '/data/assets/' . $folder;
    if (!is_dir($assets_dir)) mkdir($assets_dir, 0777, true);

    $bin = $raw_data;
    $ext = '.png';
    if (preg_match('/^data:(.*?);base64,(.*)$/', $raw_data, $matches)) {
        $bin = base64_decode($matches[2]);
        if (strpos($matches[1], 'jpeg') !== false) $ext = '.jpg';
        else if (strpos($matches[1], 'pdf') !== false) $ext = '.pdf';
        else if (strpos($matches[1], 'officedocument') !== false) $ext = '.docx';
    }
    if (isset($_POST['filename'])) {
        $ext = '.' . pathinfo($_POST['filename'], PATHINFO_EXTENSION);
    }

    $human_path = $assets_dir . '/' . $filename;
    file_put_contents($human_path, $bin);

    $db = new PDO("sqlite:$db_path");
    clearstatcache(true, $human_path);
    
    $rel_path = $folder . '/' . $filename;
    
    // PURGE GHOST HASHES: Remove the path from any old hashes pointing to this exact file
    $stmt = $db->query("SELECT hash, data FROM assets WHERE data LIKE '%\"path\":\"" . $rel_path . "\"%'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['hash'] !== $hash) {
            $old_meta = json_decode($row['data'], true);
            if (isset($old_meta['path']) && $old_meta['path'] === $rel_path) {
                unset($old_meta['path']);
                unset($old_meta['fast_id']);
                $db->prepare("UPDATE assets SET data = ? WHERE hash = ?")->execute([json_encode($old_meta), $row['hash']]);
            }
        }
    }

    $fast_id = md5($rel_path . filemtime($human_path) . filesize($human_path));
    $meta = json_encode(['path' => $rel_path, 'fast_id' => $fast_id]);
    
    // Use REPLACE to ensure the latest metadata/fast_id is associated with this content hash
    $stmt = $db->prepare("INSERT OR REPLACE INTO assets (hash, data, created_at) VALUES (?, ?, ?)");
    $stmt->execute([$hash, $meta, time()]);
    
    echo json_encode([
        'status' => 'success',
        'hash' => $hash,
        'path' => 'data/assets/' . $folder . '/' . $filename,
        'message' => 'Asset integrated into Media Vault.'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}