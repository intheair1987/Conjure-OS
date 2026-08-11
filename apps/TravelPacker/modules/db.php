<?php
// apps/TravelPacker/modules/db.php

$db_file = __DIR__ . '/../app.db';

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Automated schema evolution/migration if old schema is present
    $table_check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='trip_categories'")->fetchColumn();
    if (!$table_check) {
        $db->exec("DROP TABLE IF EXISTS items");
        $db->exec("DROP TABLE IF EXISTS categories");
        $db->exec("DROP TABLE IF EXISTS trips");
    }

    // Create normalized tables
    $db->exec("CREATE TABLE IF NOT EXISTS trips (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        date TEXT,
        is_active INTEGER DEFAULT 0,
        is_starred INTEGER DEFAULT 0
    )");

    // Safe schema evolution migration: append column if missing from legacy tables
    $columns = $db->query("PRAGMA table_info(trips)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_starred', $columns)) {
        $db->exec("ALTER TABLE trips ADD COLUMN is_starred INTEGER DEFAULT 0");
    }
    
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        position INTEGER DEFAULT 0,
        icon TEXT
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        name TEXT NOT NULL,
        quantity INTEGER DEFAULT 1,
        is_needed INTEGER DEFAULT 0,
        note TEXT,
        position INTEGER DEFAULT 0,
        FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS trip_categories (
        trip_id INTEGER,
        category_id INTEGER,
        PRIMARY KEY (trip_id, category_id),
        FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
        FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS trip_items (
        trip_id INTEGER,
        item_id INTEGER,
        is_packed INTEGER DEFAULT 0,
        is_hidden INTEGER DEFAULT 0,
        PRIMARY KEY (trip_id, item_id),
        FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE,
        FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE
    )");

    // Safe schema migration: append column if missing
    $columns_ti = $db->query("PRAGMA table_info(trip_items)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_hidden', $columns_ti)) {
        $db->exec("ALTER TABLE trip_items ADD COLUMN is_hidden INTEGER DEFAULT 0");
    }
    
    // Seed initial data if database is empty
    $item_count = $db->query("SELECT COUNT(*) FROM items")->fetchColumn();
    if ($item_count == 0) {
        // Create initial default trip
        $db->exec("INSERT INTO trips (name, date, is_active) VALUES ('My First Trip', '2026-06-21', 1)");
        $trip_id = $db->lastInsertId();
        
        // Embedded CSV packing list
        $csv_data = <<<'CSV'
Name,Quantity,Needed,Order in list,Note,Group,Group position,Order in group,Crossed out
Shirt,2,false,1,,Clothing,1,1,false
Pants,2,false,2,,Clothing,1,2,false
Shorts,1,false,3,,Clothing,1,3,false
Hat,1,false,4,,Clothing,1,4,false
Socks,4,false,5,,Clothing,1,5,false
Shoes,1,false,6,,Clothing,1,6,false
Slippers,1,false,7,,Clothing,1,7,false
Underwear,4,false,8,,Clothing,1,8,false
Sleeping shirt,1,false,9,,Clothing,1,9,false
Sleeping shorts,1,false,10,,Clothing,1,10,false
Sleeping long pants,1,false,11,,Clothing,1,11,false
Jacket,1,false,82,,Clothing,1,12,false
Belt,1,false,83,,Clothing,1,13,false
----------------------------------------,1,false,100,,Clothing,1,14,true
Travel hangers,1,false,109,,Clothing,1,15,true
Perfume,1,false,76,,Clothing,1,16,true
Necklace,1,false,75,,Clothing,1,17,true
Gym shirt,3,false,12,,Workout,2,1,false
Gym shorts,3,false,13,,Workout,2,2,false
Gym shoes,1,false,14,,Workout,2,3,false
Gym socks,4,false,86,,Workout,2,4,false
Headband,1,false,15,,Workout,2,5,false
Creatine,1,false,22,,Workout,2,6,false
眼鏡防滑繩,1,false,94,,Workout,2,7,false
慢跑腰帶包,1,false,97,,Workout,2,8,false
防曬乳,1,false,96,,Workout,2,9,false
----------------------------------------,1,false,101,,Workout,2,10,true
Pushup handles,1,false,18,,Workout,2,11,false
Towel,1,false,16,,Workout,2,12,false
Elastic bands,1,false,17,,Workout,2,13,true
Water bottle,1,false,19,,Workout,2,14,false
Blender bottle,1,false,20,,Workout,2,15,false
Protein supplement,1,false,21,,Workout,2,16,false
Phone,1,false,23,,Electronics,3,1,false
Phone charging cable,1,false,24,,Electronics,3,2,false
Phone charging brick,1,false,25,,Electronics,3,3,false
Powerbank,1,true,26,,Electronics,3,4,false
Beats Fit Pro,1,false,27,,Electronics,3,5,false
USB-C cable,1,true,30,,Electronics,3,6,false
Pixel Watch,1,true,31,,Electronics,3,7,false
Pixel watch charger,1,true,32,,Electronics,3,8,false
隨身短 type-c,1,true,95,,Electronics,3,9,false
多插座延長線,1,true,98,,Electronics,3,10,false
----------------------------------------,1,false,99,,Electronics,3,11,true
MacBook,1,false,34,,Electronics,3,12,true
Kindle,1,false,28,,Electronics,3,13,true
iPad,1,true,29,,Electronics,3,14,false
Seiko mechanical watch,1,false,33,,Electronics,3,15,true
MacBook charging brick,1,false,35,,Electronics,3,16,false
Folding keyboard,1,false,36,,Electronics,3,17,true
Logitech MX Master mouse,1,false,37,,Electronics,3,18,true
Xiaomi Karaoke Microphone,1,false,38,,Electronics,3,19,true
SIM ejector tool,1,false,84,,Electronics,3,20,true
Bose Revolve speaker,1,false,85,,Electronics,3,21,true
Allergy medication,1,false,39,,Medications,4,1,false
Allergy eye drops,1,false,41,,Medications,4,2,true
----------------------------------------,1,false,102,,Medications,4,3,true
Motion sickness medication,1,false,40,,Medications,4,4,true
Other medications,4,false,42,,Medications,4,5,true
Toothbrush,1,false,43,,Toiletry,5,1,false
Toothpaste,1,false,44,,Toiletry,5,2,true
Folding cup,1,false,45,,Toiletry,5,3,false
Shampoo,1,false,46,,Toiletry,5,4,true
Shower gel,1,false,47,,Toiletry,5,5,true
Face wash,1,false,48,,Toiletry,5,6,true
CeraVe lotion,1,false,49,,Toiletry,5,7,false
Travel hairdryer,1,false,51,,Toiletry,5,8,false
Towel,2,false,68,,Toiletry,5,9,false
Earplugs,1,false,69,,Toiletry,5,10,false
Sleeping eye mask,1,false,70,,Toiletry,5,11,false
Bag for dirty clothes,1,true,74,,Toiletry,5,12,false
Lip balm,1,false,77,,Toiletry,5,13,true
Tissues,1,false,79,,Toiletry,5,14,true
Floss / toothpicks,1,false,87,,Toiletry,5,15,false
刮鬍刀,1,false,93,,Toiletry,5,16,false
Breath freshener,1,false,108,,Toiletry,5,17,false
Nose hair trimmer,1,false,110,,Toiletry,5,18,false
----------------------------------------,1,false,103,,Toiletry,5,19,true
Comb,1,false,50,,Toiletry,5,20,true
Sunscreen for body,1,false,52,,Outdoors,6,1,false
Sunscreen for face,1,false,91,,Outdoors,6,2,false
Sunglasses,1,false,53,,Outdoors,6,3,false
Light jacket,1,false,54,,Outdoors,6,4,true
Hat,1,false,55,,Outdoors,6,5,true
Super small picnic blanket/matt,1,false,80,,Outdoors,6,6,false
----------------------------------------,1,false,104,,Outdoors,6,7,true
Flashlight,1,false,81,,Outdoors,6,8,true
ID card,1,false,57,,Documentation,7,1,false
Health insurance card,1,false,58,,Documentation,7,2,false
Passport,1,false,61,,Documentation,7,3,true
Driver's license,1,false,72,,Documentation,7,4,false
Credit card,1,false,73,,Documentation,7,5,false
Pen,1,false,78,,Documentation,7,6,true
----------------------------------------,1,false,105,,Documentation,7,7,true
Boarding pass,1,false,71,,Documentation,7,8,true
Airline itineraries,1,false,62,,Documentation,7,9,true
Travel insurance,1,false,59,,To Do,8,1,false
Orion pet stay,1,false,60,,To Do,8,2,true
Download offline maps,1,false,64,,To Do,8,3,false
Japan metro apps,1,false,65,,To Do,8,4,false
Suica,1,false,66,,To Do,8,5,true
Local SIM card / e-sim,1,false,56,,To Do,8,6,false
Local currency,1,false,67,,To Do,8,7,false
Clean trash,1,true,90,,To Do,8,8,false
Hotel booking,1,false,63,,To Do,8,9,false
----------------------------------------,1,false,106,,To Do,8,10,false
煙灰罐,1,false,92,,Others,9,1,true
----------------------------------------,1,false,107,,Others,9,2,false
Umbrella,1,false,88,,Others,9,3,false
小側背包,1,false,89,,Others,9,4,false
CSV;
            
            $lines = explode("\n", trim($csv_data));
            
            $icon_map = [
                'Clothing'      => 'shirt',
                'Workout'       => 'dumbbell',
                'Electronics'   => 'laptop',
                'Medications'   => 'pill',
                'Toiletry'      => 'droplet',
                'Outdoors'      => 'compass',
                'Documentation' => 'file-text',
                'To Do'         => 'check-square',
                'Others'        => 'package'
            ];
            
            $stmt_category = $db->prepare("INSERT OR IGNORE INTO categories (name, position, icon) VALUES (:name, :position, :icon)");
            $stmt_item = $db->prepare("INSERT INTO items (category_id, name, quantity, is_needed, note, position) VALUES (:category_id, :name, :quantity, :is_needed, :note, :position)");
            $stmt_trip_item = $db->prepare("INSERT INTO trip_items (trip_id, item_id, is_packed) VALUES (?, ?, ?)");

            for ($i = 1; $i < count($lines); $i++) {
                $row = str_getcsv($lines[$i]);
                if (count($row) < 9) continue;
                
                $name = trim($row[0]);
                if (strpos($name, '---') !== false || empty($name)) continue;
                
                $quantity = intval($row[1]);
                $is_needed = (trim($row[2]) === 'true') ? 1 : 0;
                $group_name = trim($row[5]);
                $group_pos = intval($row[6]);
                $order_in_group = intval($row[7]);
                $is_packed = (trim($row[8]) === 'true') ? 1 : 0;
                
                $icon = isset($icon_map[$group_name]) ? $icon_map[$group_name] : 'package';
                $stmt_category->execute([
                    ':name'     => $group_name,
                    ':position' => $group_pos,
                    ':icon'     => $icon
                ]);
                
                $stmt_get_cat = $db->prepare("SELECT id FROM categories WHERE name = ?");
                $stmt_get_cat->execute([$group_name]);
                $cat_id = $stmt_get_cat->fetchColumn();
                
                $stmt_item->execute([
                    ':category_id' => $cat_id,
                    ':name'        => $name,
                    ':quantity'    => $quantity,
                    ':is_needed'   => $is_needed,
                    ':note'        => '',
                    ':position'    => $order_in_group
                ]);
                $new_item_id = $db->lastInsertId();

                if ($is_packed) {
                    $stmt_trip_item->execute([$trip_id, $new_item_id, 1]);
                }
            }

            // Link all categories to the first default trip
            $all_cats = $db->query("SELECT id FROM categories")->fetchAll(PDO::FETCH_COLUMN);
            $stmt_link = $db->prepare("INSERT INTO trip_categories (trip_id, category_id) VALUES (?, ?)");
            foreach ($all_cats as $c_id) {
                $stmt_link->execute([$trip_id, $c_id]);
            }
        }
    } catch (PDOException $e) {
        die("Database Connection / Seeding Failed: " . $e->getMessage());
    }