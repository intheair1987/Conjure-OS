<?php
// apps/TravelPacker/modules/api.php

header('Content-Type: application/json');

if (!isset($_GET['action'])) {
    echo json_encode(["success" => false, "error" => "No action specified"]);
    exit;
}

$action = $_GET['action'];

try {
    switch ($action) {
        case 'edit_trip':
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $categories_raw = isset($_POST['categories']) ? $_POST['categories'] : '[]';
            $category_ids = json_decode($categories_raw, true);

            if (empty($name)) {
                echo json_encode(["success" => false, "error" => "Trip name cannot be empty"]);
                exit;
            }
            
            $stmt = $db->prepare("UPDATE trips SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
            
            // Rebuild category associations
            $db->prepare("DELETE FROM trip_categories WHERE trip_id = ?")->execute([$id]);
            
            if (is_array($category_ids)) {
                $stmt_link = $db->prepare("INSERT INTO trip_categories (trip_id, category_id) VALUES (?, ?)");
                foreach ($category_ids as $cat_id) {
                    $stmt_link->execute([$id, intval($cat_id)]);
                }
            }
            
            echo json_encode(["success" => true]);
            break;
            
        case 'delete_trip':
            $id = intval($_POST['id']);
            $db->prepare("DELETE FROM trips WHERE id = ?")->execute([$id]);
            $db->prepare("DELETE FROM trip_categories WHERE trip_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM trip_items WHERE trip_id = ?")->execute([$id]);
            
            $remaining = $db->query("SELECT id FROM trips LIMIT 1")->fetchColumn();
            if ($remaining) {
                $db->exec("UPDATE trips SET is_active = 1 WHERE id = $remaining");
            } else {
                $db->exec("INSERT INTO trips (name, date, is_active) VALUES ('My First Trip', date('now'), 1)");
                $new_trip_id = $db->lastInsertId();
                $all_cats = $db->query("SELECT id FROM categories")->fetchAll(PDO::FETCH_COLUMN);
                $stmt_link = $db->prepare("INSERT INTO trip_categories (trip_id, category_id) VALUES (?, ?)");
                foreach ($all_cats as $c_id) {
                    $stmt_link->execute([$new_trip_id, $c_id]);
                }
            }
            echo json_encode(["success" => true]);
            break;
            
        case 'delete_category':
            $id = intval($_POST['id']);
            // Explicitly clean up all associations and items related to this category to maintain DB integrity
            $db->prepare("DELETE FROM trip_categories WHERE category_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM trip_items WHERE item_id IN (SELECT id FROM items WHERE category_id = ?)")->execute([$id]);
            $db->prepare("DELETE FROM items WHERE category_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            echo json_encode(["success" => true]);
            break;
            
        case 'remove_category_from_trip':
            $id = intval($_POST['id']);
            $trip_id = $db->query("SELECT id FROM trips WHERE is_active = 1 LIMIT 1")->fetchColumn();
            $db->prepare("DELETE FROM trip_categories WHERE trip_id = ? AND category_id = ?")->execute([$trip_id, $id]);
            echo json_encode(["success" => true]);
            break;

        case 'add_category':
            $name = trim($_POST['name']);
            $icon = trim($_POST['icon']);
            $enable_current = isset($_POST['enable_current']) ? intval($_POST['enable_current']) : 0;
            
            if (empty($name)) {
                echo json_encode(["success" => false, "error" => "Category name cannot be empty"]);
                exit;
            }
            
            $pos = $db->query("SELECT MAX(position) FROM categories")->fetchColumn() + 1;
            $stmt = $db->prepare("INSERT INTO categories (name, position, icon) VALUES (?, ?, ?)");
            $stmt->execute([$name, $pos, $icon]);
            $new_cat_id = $db->lastInsertId();
            
            if ($enable_current) {
                $trip_id = $db->query("SELECT id FROM trips WHERE is_active = 1 LIMIT 1")->fetchColumn();
                if ($trip_id) {
                    $stmt_link = $db->prepare("INSERT INTO trip_categories (trip_id, category_id) VALUES (?, ?)");
                    $stmt_link->execute([$trip_id, $new_cat_id]);
                }
            }
            
            echo json_encode(["success" => true]);
            break;

        case 'toggle_star_trip':
            $id = intval($_POST['id']);
            
            // Toggle star status in database
            $current = $db->query("SELECT is_starred FROM trips WHERE id = $id")->fetchColumn();
            $new_state = 1 - $current;
            
            // Set all other trips to unpinned as only one trip can be the default
            if ($new_state == 1) {
                $db->exec("UPDATE trips SET is_starred = 0");
            }
            
            $stmt = $db->prepare("UPDATE trips SET is_starred = ? WHERE id = ?");
            $stmt->execute([$new_state, $id]);
            
            echo json_encode(["success" => true, "is_starred" => $new_state]);
            break;

        case 'duplicate_trip':
            $source_id = intval($_POST['id']);
            $name = trim($_POST['name']);
            if (empty($name)) {
                echo json_encode(["success" => false, "error" => "Trip name cannot be empty"]);
                exit;
            }
            
            $db->exec("UPDATE trips SET is_active = 0");
            $stmt = $db->prepare("INSERT INTO trips (name, date, is_active) VALUES (?, ?, 1)");
            $stmt->execute([$name, date('Y-m-d')]);
            $new_trip_id = $db->lastInsertId();
            
            // Duplicate category associations from selected trip
            $stmt_link = $db->prepare("INSERT INTO trip_categories (trip_id, category_id) SELECT ?, category_id FROM trip_categories WHERE trip_id = ?");
            $stmt_link->execute([$new_trip_id, $source_id]);
            
            // Duplicate all item configurations (both packed and hidden states) from selected trip
            $stmt_items = $db->prepare("INSERT INTO trip_items (trip_id, item_id, is_packed, is_hidden) SELECT ?, item_id, is_packed, is_hidden FROM trip_items WHERE trip_id = ?");
            $stmt_items->execute([$new_trip_id, $source_id]);
            
            echo json_encode(["success" => true]);
            break;

        case 'add_trip':
            $name = trim($_POST['name']);
            $categories_raw = isset($_POST['categories']) ? $_POST['categories'] : '[]';
            $category_ids = json_decode($categories_raw, true);
            
            if (empty($name)) {
                echo json_encode(["success" => false, "error" => "Trip name cannot be empty"]);
                exit;
            }
            
            $db->exec("UPDATE trips SET is_active = 0");
            $stmt = $db->prepare("INSERT INTO trips (name, date, is_active) VALUES (?, ?, 1)");
            $stmt->execute([$name, date('Y-m-d')]);
            $new_trip_id = $db->lastInsertId();
            
            // Link selected categories to this trip
            if (is_array($category_ids)) {
                $stmt_link = $db->prepare("INSERT INTO trip_categories (trip_id, category_id) VALUES (?, ?)");
                foreach ($category_ids as $cat_id) {
                    $stmt_link->execute([$new_trip_id, intval($cat_id)]);
                }
            }
            
            echo json_encode(["success" => true]);
            break;
            
        case 'edit_item':
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $category_id = intval($_POST['category_id']);
            $quantity = intval($_POST['quantity']);
            $note = trim($_POST['note']);
            $is_needed = intval($_POST['is_needed']);
            
            if (empty($name)) {
                echo json_encode(["success" => false, "error" => "Name cannot be empty"]);
                exit;
            }
            
            $stmt = $db->prepare("UPDATE items SET name = ?, category_id = ?, quantity = ?, is_needed = ?, note = ? WHERE id = ?");
            $stmt->execute([$name, $category_id, $quantity, $is_needed, $note, $id]);
            
            // Ensure the category is linked to the current trip
            $trip_id = $db->query("SELECT id FROM trips WHERE is_active = 1 LIMIT 1")->fetchColumn();
            $stmt_check_link = $db->prepare("SELECT 1 FROM trip_categories WHERE trip_id = ? AND category_id = ?");
            $stmt_check_link->execute([$trip_id, $category_id]);
            if (!$stmt_check_link->fetchColumn()) {
                $stmt_link = $db->prepare("INSERT INTO trip_categories (trip_id, category_id) VALUES (?, ?)");
                $stmt_link->execute([$trip_id, $category_id]);
            }
            
            // Re-fetch updated metrics excluding hidden items
            $total_count = $db->query("SELECT COUNT(*) FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = $trip_id WHERE tc.trip_id = $trip_id AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
            $packed_count = $db->query("SELECT COUNT(*) FROM trip_items ti JOIN items i ON ti.item_id = i.id JOIN trip_categories tc ON i.category_id = tc.category_id AND tc.trip_id = ti.trip_id WHERE ti.trip_id = $trip_id AND ti.is_packed = 1 AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
            $pct = ($total_count > 0) ? round(($packed_count / $total_count) * 100) : 0;
            
            // Gather updated critical unpacked items excluding hidden ones
            $stmt_priority = $db->prepare("SELECT i.* FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = ? WHERE tc.trip_id = ? AND i.is_needed = 1 AND COALESCE(ti.is_packed, 0) = 0 AND COALESCE(ti.is_hidden, 0) = 0 ORDER BY i.position");
            $stmt_priority->execute([$trip_id, $trip_id]);
            $priority_items = $stmt_priority->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true, 
                "item" => [
                    "id"          => $id,
                    "name"        => $name,
                    "category_id" => $category_id,
                    "quantity"    => $quantity,
                    "is_needed"   => $is_needed,
                    "note"        => $note
                ],
                "pct"            => $pct,
                "priority_items" => $priority_items
            ]);
            break;
            
        case 'toggle_needed':
            $id = intval($_POST['id']);
            $db->exec("UPDATE items SET is_needed = 1 - is_needed WHERE id = $id");
            
            $is_needed = $db->query("SELECT is_needed FROM items WHERE id = $id")->fetchColumn();
            $trip_id = $db->query("SELECT id FROM trips WHERE is_active = 1 LIMIT 1")->fetchColumn();
            
            $stmt_priority = $db->prepare("SELECT i.* FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = ? WHERE tc.trip_id = ? AND i.is_needed = 1 AND COALESCE(ti.is_packed, 0) = 0 ORDER BY i.position");
            $stmt_priority->execute([$trip_id, $trip_id]);
            $priority_items = $stmt_priority->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success"        => true,
                "is_needed"      => $is_needed,
                "priority_items" => $priority_items
            ]);
            break;
            
        case 'toggle_packed':
            $id = intval($_POST['id']);
            $val = intval($_POST['checked']);
            $trip_id = $db->query("SELECT id FROM trips WHERE is_active = 1 LIMIT 1")->fetchColumn();
            
            // Persist packed status in isolated trip_items join table, preserving is_hidden
            $stmt = $db->prepare("REPLACE INTO trip_items (trip_id, item_id, is_packed, is_hidden) VALUES (?, ?, ?, COALESCE((SELECT is_hidden FROM trip_items WHERE trip_id = ? AND item_id = ?), 0))");
            $stmt->execute([$trip_id, $id, $val, $trip_id, $id]);
            
            // Re-fetch stats excluding hidden items
            $total_count = $db->query("SELECT COUNT(*) FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = $trip_id WHERE tc.trip_id = $trip_id AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
            $packed_count = $db->query("SELECT COUNT(*) FROM trip_items ti JOIN items i ON ti.item_id = i.id JOIN trip_categories tc ON i.category_id = tc.category_id AND tc.trip_id = ti.trip_id WHERE ti.trip_id = $trip_id AND ti.is_packed = 1 AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
            $pct = ($total_count > 0) ? round(($packed_count / $total_count) * 100) : 0;
            
            // Gather updated critical unpacked needs
            $stmt_priority = $db->prepare("SELECT i.* FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = ? WHERE tc.trip_id = ? AND i.is_needed = 1 AND COALESCE(ti.is_packed, 0) = 0 AND COALESCE(ti.is_hidden, 0) = 0 ORDER BY i.position");
            $stmt_priority->execute([$trip_id, $trip_id]);
            $priority_items = $stmt_priority->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success"        => true, 
                "pct"            => $pct, 
                "packed_count"   => $packed_count, 
                "total_count"    => $total_count,
                "priority_items" => $priority_items
            ]);
            break;
            
        case 'toggle_hidden':
            $id = intval($_POST['id']);
            $val = intval($_POST['is_hidden']);
            $trip_id = $db->query("SELECT id FROM trips WHERE is_active = 1 LIMIT 1")->fetchColumn();
            
            // Update the hidden state of an item for this specific trip
            $stmt = $db->prepare("REPLACE INTO trip_items (trip_id, item_id, is_packed, is_hidden) VALUES (?, ?, COALESCE((SELECT is_packed FROM trip_items WHERE trip_id = ? AND item_id = ?), 0), ?)");
            $stmt->execute([$trip_id, $id, $trip_id, $id, $val]);
            
            // Re-fetch stats excluding hidden items
            $total_count = $db->query("SELECT COUNT(*) FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = $trip_id WHERE tc.trip_id = $trip_id AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
            $packed_count = $db->query("SELECT COUNT(*) FROM trip_items ti JOIN items i ON ti.item_id = i.id JOIN trip_categories tc ON i.category_id = tc.category_id AND tc.trip_id = ti.trip_id WHERE ti.trip_id = $trip_id AND ti.is_packed = 1 AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
            $pct = ($total_count > 0) ? round(($packed_count / $total_count) * 100) : 0;
            
            // Gather updated critical unpacked needs excluding hidden items
            $stmt_priority = $db->prepare("SELECT i.* FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = ? WHERE tc.trip_id = ? AND i.is_needed = 1 AND COALESCE(ti.is_packed, 0) = 0 AND COALESCE(ti.is_hidden, 0) = 0 ORDER BY i.position");
            $stmt_priority->execute([$trip_id, $trip_id]);
            $priority_items = $stmt_priority->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success"        => true,
                "pct"            => $pct,
                "priority_items" => $priority_items
            ]);
            break;
            
        case 'delete_item':
            $id = intval($_POST['id']);
            $db->prepare("DELETE FROM items WHERE id = ?")->execute([$id]);
            $db->prepare("DELETE FROM trip_items WHERE item_id = ?")->execute([$id]);
            
            $trip_id = $db->query("SELECT id FROM trips WHERE is_active = 1 LIMIT 1")->fetchColumn();
            
            $total_count = $db->query("SELECT COUNT(*) FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = $trip_id WHERE tc.trip_id = $trip_id AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
            $packed_count = $db->query("SELECT COUNT(*) FROM trip_items ti JOIN items i ON ti.item_id = i.id JOIN trip_categories tc ON i.category_id = tc.category_id AND tc.trip_id = ti.trip_id WHERE ti.trip_id = $trip_id AND ti.is_packed = 1 AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
            $pct = ($total_count > 0) ? round(($packed_count / $total_count) * 100) : 0;
            
            echo json_encode(["success" => true, "pct" => $pct]);
            break;
            
        case 'add_item':
            $trip_id = $db->query("SELECT id FROM trips WHERE is_active = 1 LIMIT 1")->fetchColumn();
            $name = trim($_POST['name']);
            $category_id = intval($_POST['category_id']);
            $quantity = intval($_POST['quantity']);
            $note = trim($_POST['note']);
            $is_needed = intval($_POST['is_needed']);
            
            if (empty($name)) {
                echo json_encode(["success" => false, "error" => "Name cannot be empty"]);
                exit;
            }
            
            // Insert item globally into categories pool
            $stmt = $db->prepare("INSERT INTO items (category_id, name, quantity, is_needed, note) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$category_id, $name, $quantity, $is_needed, $note]);
            $new_id = $db->lastInsertId();
            
            // Ensure the category is linked to the current trip
            $stmt_check_link = $db->prepare("SELECT 1 FROM trip_categories WHERE trip_id = ? AND category_id = ?");
            $stmt_check_link->execute([$trip_id, $category_id]);
            if (!$stmt_check_link->fetchColumn()) {
                $stmt_link = $db->prepare("INSERT INTO trip_categories (trip_id, category_id) VALUES (?, ?)");
                $stmt_link->execute([$trip_id, $category_id]);
            }
            
            // Re-fetch updated metrics excluding hidden items
            $total_count = $db->query("SELECT COUNT(*) FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = $trip_id WHERE tc.trip_id = $trip_id AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
            $packed_count = $db->query("SELECT COUNT(*) FROM trip_items ti JOIN items i ON ti.item_id = i.id JOIN trip_categories tc ON i.category_id = tc.category_id AND tc.trip_id = ti.trip_id WHERE ti.trip_id = $trip_id AND ti.is_packed = 1 AND COALESCE(ti.is_hidden, 0) = 0")->fetchColumn();
            $pct = ($total_count > 0) ? round(($packed_count / $total_count) * 100) : 0;
            
            // Gather updated critical unpacked items excluding hidden ones
            $stmt_priority = $db->prepare("SELECT i.* FROM items i JOIN trip_categories tc ON i.category_id = tc.category_id LEFT JOIN trip_items ti ON i.id = ti.item_id AND ti.trip_id = ? WHERE tc.trip_id = ? AND i.is_needed = 1 AND COALESCE(ti.is_packed, 0) = 0 AND COALESCE(ti.is_hidden, 0) = 0 ORDER BY i.position");
            $stmt_priority->execute([$trip_id, $trip_id]);
            $priority_items = $stmt_priority->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true, 
                "item" => [
                    "id"          => $new_id,
                    "name"        => $name,
                    "category_id" => $category_id,
                    "quantity"    => $quantity,
                    "is_needed"   => $is_needed,
                    "note"        => $note
                ],
                "pct"            => $pct,
                "priority_items" => $priority_items
            ]);
            break;
            
        case 'reset_trip':
            $trip_id = $db->query("SELECT id FROM trips WHERE is_active = 1 LIMIT 1")->fetchColumn();
            
            // Set all items back to unpacked status without removing their trip-isolated hiding states
            $stmt = $db->prepare("UPDATE trip_items SET is_packed = 0 WHERE trip_id = ?");
            $stmt->execute([$trip_id]);
            
            echo json_encode(["success" => true]);
            break;
            
        case 'change_trip':
            $target_id = intval($_POST['trip_id']);
            $db->exec("UPDATE trips SET is_active = 0");
            $stmt = $db->prepare("UPDATE trips SET is_active = 1 WHERE id = ?");
            $stmt->execute([$target_id]);
            
            echo json_encode(["success" => true]);
            break;
            
        case 'save_settings':
            $bg_color = trim($_POST['bg_color']);
            $card_bg = trim($_POST['card_bg']);
            $accent = trim($_POST['primary_accent']);
            
            $settings_file = __DIR__ . '/../data/settings.json';
            $settings = json_decode(file_get_contents($settings_file), true);
            
            $settings['bg-color'] = $bg_color;
            $settings['card-bg'] = $card_bg;
            $settings['primary-accent'] = $accent;
            
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
            echo json_encode(["success" => true]);
            break;
            
        case 'reset_settings':
            $settings_file = __DIR__ . '/../data/settings.json';
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
            echo json_encode(["success" => true]);
            break;
            
        default:
            echo json_encode(["success" => false, "error" => "Action not found"]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
exit;