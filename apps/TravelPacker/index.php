<?php
// apps/TravelPacker/index.php

define('TP_ROOT', __DIR__);
define('TP_DATA', TP_ROOT . '/data');
define('TP_MODULES', TP_ROOT . '/modules');

if (!file_exists(TP_DATA)) {
    mkdir(TP_DATA, 0755, true);
}

// Generate centralized theme configuration file
$settings_file = TP_DATA . '/settings.json';
if (!file_exists($settings_file)) {
    $default_settings = [
        "bg-color"         => "#0f172a",
        "card-bg"          => "#1e293b",
        "text-primary"     => "#f8fafc",
        "text-secondary"   => "#94a3b8",
        "primary-accent"   => "#6366f1",
        "font-main"        => "system-ui, sans-serif",
        "radius-container" => "16px"
    ];
    file_put_contents($settings_file, json_encode($default_settings, JSON_PRETTY_PRINT));
}

// Load database connection
require_once TP_MODULES . '/db.php';

// Orchestrator routing checkpoint (Rule 6.11): route active AJAX operations direct to api.php
if (isset($_GET['action'])) {
    require_once TP_MODULES . '/api.php';
    exit;
}

require_once TP_MODULES . '/layout.php';

$settings = json_decode(file_get_contents($settings_file), true);

$view = $_GET['view'] ?? null;
$active_cat_id = $_GET['active_cat'] ?? 'all';

// Routing logic: Default to Starred Trip if exists, otherwise load Home Dashboard
if ($view === null) {
    $starred_trip = $db->query("SELECT * FROM trips WHERE is_starred = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($starred_trip) {
        $view = 'trip';
        $trip = $starred_trip;
        $db->exec("UPDATE trips SET is_active = 0");
        $db->exec("UPDATE trips SET is_active = 1 WHERE id = " . $starred_trip['id']);
    } else {
        $view = 'home';
    }
}

// Gather all trips
$all_trips = $db->query("SELECT * FROM trips ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Gather categories
$categories = $db->query("SELECT * FROM categories ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dashboard Data
$trips_dashboard_data = [];
if ($view === 'home') {
    foreach ($all_trips as $t) {
    // Exclude hidden items from dashboard calculations to maintain 1-to-1 sync with active checklist metrics
    $total = $db->query("SELECT COUNT(*) FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = " . $t['id'] . " WHERE tc.trip_id = " . $t['id'] . " AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
    $packed = $db->query("SELECT COUNT(*) FROM trip_items ti JOIN items i ON ti.item_id = i.id JOIN trip_categories tc ON i.category_id = tc.category_id AND tc.trip_id = ti.trip_id WHERE ti.trip_id = " . $t['id'] . " AND ti.is_packed = 1 AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
    $pct = ($total > 0) ? round(($packed / $total) * 100) : 0;// Fetch up to 5 distinct active category icons for the preview
        $stmt_icons = $db->query("SELECT DISTINCT c.icon FROM trip_categories tc JOIN categories c ON tc.category_id = c.id WHERE tc.trip_id = " . $t['id'] . " LIMIT 5");
        $icons = $stmt_icons->fetchAll(PDO::FETCH_COLUMN);
        
        $stmt_active_cats = $db->query("SELECT category_id FROM trip_categories WHERE trip_id = " . $t['id']);
        $active_cats = $stmt_active_cats->fetchAll(PDO::FETCH_COLUMN);

        $trips_dashboard_data[] = [
            'id' => $t['id'],
            'name' => $t['name'],
            'date' => $t['date'],
            'is_starred' => $t['is_starred'],
            'total_items' => $total,
            'packed_items' => $packed,
            'pct' => $pct,
            'icons' => $icons,
            'active_cats' => $active_cats
        ];
    }
} else {
    // Gather active trip if not pre-assigned by the starring checker
    if (!isset($trip)) {
        $trip = $db->query("SELECT * FROM trips WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    if (!$trip) {
        $db->exec("INSERT INTO trips (name, date, is_active) VALUES ('My First Trip', '2026-06-21', 1)");
        $trip = $db->query("SELECT * FROM trips WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        
        // Link all categories to default trip
        $all_cats = $db->query("SELECT id FROM categories")->fetchAll(PDO::FETCH_COLUMN);
        $stmt_link = $db->prepare("INSERT INTO trip_categories (trip_id, category_id) VALUES (?, ?)");
        foreach ($all_cats as $c_id) {
            $stmt_link->execute([$trip['id'], $c_id]);
        }
        
        $all_trips = $db->query("SELECT * FROM trips ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch active categories for the trip
    $stmt_active_cats = $db->query("SELECT category_id FROM trip_categories WHERE trip_id = " . $trip['id']);
    $trip['active_cats'] = $stmt_active_cats->fetchAll(PDO::FETCH_COLUMN);

    // Fetch stats metrics excluding hidden items
    $total_count = $db->query("SELECT COUNT(*) FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = " . $trip['id'] . " WHERE tc.trip_id = " . $trip['id'] . " AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
    $packed_count = $db->query("SELECT COUNT(*) FROM trip_items ti JOIN items i ON ti.item_id = i.id JOIN trip_categories tc ON i.category_id = tc.category_id AND tc.trip_id = ti.trip_id WHERE ti.trip_id = " . $trip['id'] . " AND ti.is_packed = 1 AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
    $pct = ($total_count > 0) ? round(($packed_count / $total_count) * 100) : 0;

    // Gather priority unpacked needs excluding hidden items
    $stmt_priority = $db->prepare("SELECT i.* FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = ? WHERE tc.trip_id = ? AND i.is_needed = 1 AND COALESCE(ti.is_packed, 0) = 0 AND COALESCE(ti.is_hidden, 0) = 0 ORDER BY i.position");
    $stmt_priority->execute([$trip['id'], $trip['id']]);
    $priority_items = $stmt_priority->fetchAll(PDO::FETCH_ASSOC);

    // Only render categories linked to this trip
    $active_trip_categories = $db->prepare("SELECT c.* FROM categories c JOIN trip_categories tc ON c.id = tc.category_id WHERE tc.trip_id = ? ORDER BY c.position");
    $active_trip_categories->execute([$trip['id']]);
    $active_trip_categories = $active_trip_categories->fetchAll(PDO::FETCH_ASSOC);

    // Gather all items grouped by active categories with isolated check states and hidden states
    $grouped_items = [];
    foreach ($active_trip_categories as $cat) {
        $stmt_cat_items = $db->prepare("SELECT i.*, COALESCE(ti.is_packed, 0) as is_packed, COALESCE(ti.is_hidden, 0) as is_hidden FROM items i LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = ? WHERE i.category_id = ? ORDER BY i.position");
        $stmt_cat_items->execute([$trip['id'], $cat['id']]);
        $cat_items = $stmt_cat_items->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($cat_items)) {
            $grouped_items[] = [
                'category' => $cat,
                'items'    => $cat_items
            ];
        }
    }
}

// Calculate static asset hash (md5 fingerprinting for caching overrides)
function get_asset_hash($files) {
    $combined = '';
    foreach ($files as $f) {
        $path = __DIR__ . '/' . $f;
        if (file_exists($path)) {
            $combined .= md5_file($path);
        }
    }
    return $combined ? substr(md5($combined), 0, 8) : 'dev';
}
$v = get_asset_hash(['css/style.css', 'js/app.js']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>TravelPacker - Elegant List Manager</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo $v; ?>">
    <style>
        :root {
            --bg-color: <?php echo htmlspecialchars($settings['bg-color']); ?>;
            --card-bg: <?php echo htmlspecialchars($settings['card-bg']); ?>;
            --text-primary: <?php echo htmlspecialchars($settings['text-primary']); ?>;
            --text-secondary: <?php echo htmlspecialchars($settings['text-secondary']); ?>;
            --primary-accent: <?php echo htmlspecialchars($settings['primary-accent']); ?>;
            --font-main: <?php echo htmlspecialchars($settings['font-main']); ?>;
            --radius-container: <?php echo htmlspecialchars($settings['radius-container']); ?>;
        }
        <?php if ($view === 'trip' && $active_cat_id !== 'all'): ?>
            /* Prevent dynamic tab flickering on reload */
            .category-group[data-cat-id]:not([data-cat-id="<?php echo intval($active_cat_id); ?>"]) {
                display: none;
            }
        <?php endif; ?>
    </style>
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="TravelPacker">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
</head>
<body>

    <!-- SVG Icon Definitions for Dashboard Previews -->
    <svg style="display: none;">
        <symbol id="icon-package" viewBox="0 0 24 24"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></symbol>
        <symbol id="icon-shirt" viewBox="0 0 24 24"><path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></symbol>
        <symbol id="icon-dumbbell" viewBox="0 0 24 24"><path d="M14.4 14.4 9.6 9.6"/><path d="M18.657 21.485a2 2 0 1 1-2.829-2.828l-1.767 1.768a2 2 0 1 1-2.829-2.829l6.364-6.364a2 2 0 1 1 2.829 2.829l-1.768 1.767a2 2 0 1 1 2.828 2.829z"/><path d="m21.5 21.5-1.4-1.4"/><path d="M3.9 3.9 2.5 2.5"/><path d="M6.404 2.757a2 2 0 1 0-2.829 2.828l1.768-1.767a2 2 0 1 0 2.829 2.828L1.808 13.01a2 2 0 1 0-2.829-2.829l1.767-1.768a2 2 0 1 0 2.829-2.828z"/></symbol>
        <symbol id="icon-laptop" viewBox="0 0 24 24"><path d="M20 16V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v9m16 0H4m16 0 1.28 2.55a1 1 0 0 1-.9 1.45H3.62a1 1 0 0 1-.9-1.45L4 16"/></symbol>
        <symbol id="icon-pill" viewBox="0 0 24 24"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></symbol>
        <symbol id="icon-droplet" viewBox="0 0 24 24"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></symbol>
        <symbol id="icon-compass" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></symbol>
        <symbol id="icon-file-text" viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></symbol>
        <symbol id="icon-check-square" viewBox="0 0 24 24"><path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></symbol>
        <!-- Additional custom icons from category selection -->
        <symbol id="icon-camera" viewBox="0 0 24 24"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></symbol>
        <symbol id="icon-map" viewBox="0 0 24 24"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" x2="9" y1="3" y2="18"/><line x1="15" x2="15" y1="6" y2="21"/></symbol>
        <symbol id="icon-music" viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></symbol>
        <symbol id="icon-coffee" viewBox="0 0 24 24"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/></symbol>
        <symbol id="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></symbol>
        <symbol id="icon-moon" viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></symbol>
        <symbol id="icon-briefcase" viewBox="0 0 24 24"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></symbol>
        <symbol id="icon-shopping-bag" viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></symbol>
        <symbol id="icon-heart" viewBox="0 0 24 24"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></symbol>
    </svg>

    <?php tp_render_ambient_bg(); ?>

    <?php if ($view === 'home'): ?>
        <?php tp_render_dashboard($trips_dashboard_data); ?>
    <?php else: ?>
        <?php tp_render_header($trip, $all_trips, $pct); ?>

        <div class="app-container">
            <?php 
            tp_render_priority_banner($priority_items);
            tp_render_category_pills($active_trip_categories, $active_cat_id);
            tp_render_board($grouped_items);
            tp_render_celebration_banner();
            ?>
        </div>
    <?php endif; ?>

    <!-- Active dynamic build version reference token chip -->
    <div style="position: fixed; bottom: 10px; left: 10px; font-size: 9px; opacity: 0.4; pointer-events: none; z-index: 1000; background: rgba(0,0,0,0.4); padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.05);">
        Build: <?php echo $v; ?>
    </div>

    <?php 
    tp_render_fab($view);
    tp_render_add_modal($categories);
    tp_render_settings_modal($settings);
    tp_render_trip_modal($categories);
    tp_render_category_modal();
    tp_render_context_menu();
    tp_render_trip_context_menu();
    tp_render_trip_duplicate_modal();
    tp_render_priority_context_menu();
    tp_render_category_pill_context_menu();
    tp_render_reset_confirm_modal();
    tp_render_category_delete_modal();
    tp_render_item_delete_modal();
    tp_render_trip_delete_modal();
    ?>

    <script src="js/app.js?v=<?php echo $v; ?>"></script>
    <?php if ($view === 'trip'): ?>
        <script>
            // Automatically center and synchronize the category view and default compact filters on load
            tpFilterCategory('<?php echo htmlspecialchars($active_cat_id); ?>', true);
        </script>
    <?php endif; ?>
</body>
</html>