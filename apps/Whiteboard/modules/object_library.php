<?php
/**
 * Whiteboard Object Library Module
 * Handles filesystem discovery of JSON object templates.
 */

function wb_scan_object_library($dir) {
    $result = [];
    if (!is_dir($dir)) return $result;

    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $children = wb_scan_object_library($path);
            if (!empty($children)) {
                $result[] = [
                    'type' => 'folder',
                    'name' => $item,
                    'items' => $children
                ];
            }
        } else if (pathinfo($item, PATHINFO_EXTENSION) === 'json') {
            $content = json_decode(file_get_contents($path), true);
            if ($content) {
                $result[] = [
                    'type' => 'object',
                    'name' => $content['name'] ?? pathinfo($item, PATHINFO_FILENAME),
                    'file' => $item,
                    'data' => $content
                ];
            }
        }
    }
    
    // Sort: Folders first, then Objects alphabetically
    usort($result, function($a, $b) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'folder' ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });

    return $result;
}

function wb_handle_object_library_request() {
    $lib_dir = __DIR__ . '/../data/object-library';
    if (!is_dir($lib_dir)) {
        mkdir($lib_dir, 0777, true);
    }

    $library = wb_scan_object_library($lib_dir);
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'library' => $library
    ]);
    exit;
}

// Register with the Orchestrator
wb_register_api('get_object_library', function($db) {
    wb_handle_object_library_request();
});
?>