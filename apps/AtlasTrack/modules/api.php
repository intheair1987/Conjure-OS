<?php
// AJAX API Endpoints
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_exercises') {
        $stmt = $db->query("SELECT * FROM exercises ORDER BY name ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['ajax'] === 'get_workout_full_detail') {
        $id = (int)($_GET['id'] ?? 0);
        $workout = $db->query("SELECT * FROM workouts WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $sets = $db->query("SELECT ws.*, e.name as ex_name FROM workout_sets ws JOIN exercises e ON ws.exercise_id = e.id WHERE ws.workout_id = $id ORDER BY ws.id ASC")->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch full metadata including calculator settings
        $meta = $db->query("SELECT * FROM workout_exercise_notes WHERE workout_id = $id")->fetchAll(PDO::FETCH_ASSOC);
        $metaMap = [];
        foreach($meta as $m) {
            $metaMap[$m['exercise_id']] = [
                'note' => $m['notes'],
                'base_active' => (bool)$m['base_weight_active'],
                'base_val' => $m['base_weight_value'],
                'single_side' => (bool)$m['is_single_side'],
                'is_assisted' => (bool)$m['is_assisted'],
                'is_drop_active' => (bool)$m['is_drop_active']
            ];
        }
        
        echo json_encode(['workout' => $workout, 'sets' => $sets, 'meta' => $metaMap]);
        exit;
    }

    if ($_GET['ajax'] === 'save_workout') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) { echo json_encode(['status' => 'error']); exit; }

        $db->beginTransaction();
        try {
            if (!empty($data['id'])) {
                $workoutId = $data['id'];
                $stmt = $db->prepare("UPDATE workouts SET name = ?, total_volume = ?, total_sets = ? WHERE id = ?");
                $stmt->execute([$data['name'] ?? 'Workout', $data['totalVolume'], $data['totalSets'], $workoutId]);
                $db->exec("DELETE FROM workout_sets WHERE workout_id = $workoutId");
                $db->exec("DELETE FROM workout_exercise_notes WHERE workout_id = $workoutId");
            } else {
                $stmt = $db->prepare("INSERT INTO workouts (name, start_time, end_time, total_volume, total_sets) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['name'] ?? 'Workout',
                    date('Y-m-d H:i:s', $data['startTime'] / 1000),
                    date('Y-m-d H:i:s', $data['endTime'] / 1000),
                    $data['totalVolume'],
                    $data['totalSets']
                ]);
                $workoutId = $db->lastInsertId();
            }

            $setStmt = $db->prepare("INSERT INTO workout_sets (workout_id, exercise_id, weight, raw_weight, reps, set_index, is_drop) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($data['sets'] as $s) {
                $setStmt->execute([$workoutId, $s['exId'], $s['weight'], $s['raw_weight'] ?? $s['weight'], $s['reps'], $s['index'], $s['isDrop'] ? 1 : 0]);
            }

            // Save per-exercise notes + Calculator State
            $noteStmt = $db->prepare("INSERT INTO workout_exercise_notes (workout_id, exercise_id, notes, base_weight_active, base_weight_value, is_single_side, is_assisted, is_drop_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($data['exerciseMeta'] as $exId => $meta) {
                $noteStmt->execute([
                    $workoutId, 
                    $exId, 
                    $meta['note'] ?? '', 
                    $meta['base_active'] ? 1 : 0, 
                    $meta['base_val'] ?? 20, 
                    $meta['single_side'] ? 1 : 0,
                    $meta['is_assisted'] ? 1 : 0,
                    $meta['is_drop_active'] ? 1 : 0
                ]);
            }

            $db->commit();
            echo json_encode(['status' => 'success', 'id' => $workoutId]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'get_calendar_data') {
        $year = $_GET['year'] ?? date('Y');
        $month = str_pad($_GET['month'] ?? date('m'), 2, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("
            SELECT id, date(start_time) as d, name 
            FROM workouts 
            WHERE strftime('%Y', start_time) = ? 
            AND strftime('%m', start_time) = ?
            ORDER BY start_time ASC
        ");
        $stmt->execute([$year, $month]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $map = [];
        foreach ($rows as $r) {
            if (!isset($map[$r['d']])) $map[$r['d']] = [];
            $map[$r['d']][] = [
                'id' => $r['id'],
                'name' => $r['name'] ?: 'Workout'
            ];
        }
        echo json_encode($map);
        exit;
    }

    if ($_GET['ajax'] === 'get_history') {
        require_once __DIR__ . '/history.php';
        $recent = atlas_get_history(3);
        $all = atlas_get_all_history();
        
        $recent_html = '';
        foreach ($recent as $w) $recent_html .= atlas_render_history_item($w);
        
        $all_html = '';
        foreach ($all as $w) $all_html .= atlas_render_history_item($w);
        
        echo json_encode([
            'status' => 'success', 
            'recent_html' => $recent_html, 
            'all_html' => $all_html
        ]);
        exit;
    }

    if ($_GET['ajax'] === 'get_routines') {
        require_once __DIR__ . '/routines.php';
        $routines = atlas_get_routines();
        $html = '';
        foreach ($routines as $r) {
            $html .= atlas_render_routine_item($r);
        }
        echo json_encode(['status' => 'success', 'html' => $html, 'data' => $routines]);
        exit;
    }

    if ($_GET['ajax'] === 'save_routine') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['name'])) { echo json_encode(['status' => 'error', 'message' => 'Invalid data']); exit; }

        $db->beginTransaction();
        try {
            if (!empty($data['id'])) {
                $routineId = $data['id'];
                // Update name
                $stmt = $db->prepare("UPDATE routines SET name = ? WHERE id = ?");
                $stmt->execute([$data['name'], $routineId]);
                // Clear old exercises
                $stmt = $db->prepare("DELETE FROM routine_exercises WHERE routine_id = ?");
                $stmt->execute([$routineId]);
            } else {
                $stmt = $db->prepare("INSERT INTO routines (name) VALUES (?)");
                $stmt->execute([$data['name']]);
                $routineId = $db->lastInsertId();
            }

            $reStmt = $db->prepare("INSERT INTO routine_exercises (routine_id, exercise_id, position) VALUES (?, ?, ?)");
            foreach ($data['exercises'] as $idx => $exId) {
                $reStmt->execute([$routineId, $exId, $idx]);
            }

            $db->commit();
            echo json_encode(['status' => 'success', 'id' => $routineId]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'get_routine_detail') {
        $id = $_GET['id'] ?? 0;
        $stmt = $db->prepare("
            SELECT e.* 
            FROM routine_exercises re 
            JOIN exercises e ON re.exercise_id = e.id 
            WHERE re.routine_id = ? 
            ORDER BY re.position ASC
        ");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['ajax'] === 'get_workout_detail') {
        $id = $_GET['id'] ?? 0;
        $stmt = $db->prepare("
            SELECT ws.*, e.name as ex_name 
            FROM workout_sets ws 
            JOIN exercises e ON ws.exercise_id = e.id 
            WHERE ws.workout_id = ? 
            ORDER BY ws.id ASC
        ");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['ajax'] === 'get_exercise_analytics') {
        require_once __DIR__ . '/analytics.php';
        $id = $_GET['id'] ?? 0;
        echo json_encode(atlas_get_exercise_stats($id));
        exit;
    }

    if ($_GET['ajax'] === 'get_settings') {
        $stmt = $db->query("SELECT * FROM settings");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Default values if DB is empty
        $defaults = [
            'body_weight' => 75,
            'gender' => 'male',
            'units' => 'kg',
            'rest_timer_default' => 120
        ];
        
        echo json_encode(array_merge($defaults, $rows));
        exit;
    }

    if ($_GET['ajax'] === 'save_settings') {
        $data = json_decode(file_get_contents('php://input'), true);
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO settings (key, val) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET val = excluded.val");
            foreach ($data as $k => $v) {
                $stmt->execute([$k, (string)$v]);
            }
            $db->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'reset_history') {
        $db->exec("DELETE FROM workout_sets");
        $db->exec("DELETE FROM workout_exercise_notes");
        $db->exec("DELETE FROM workouts");
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['ajax'] === 'reset_routines') {
        $db->exec("DELETE FROM routine_exercises");
        $db->exec("DELETE FROM routines");
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['ajax'] === 'reset_app') {
        $db->exec("DELETE FROM workout_sets");
        $db->exec("DELETE FROM workout_exercise_notes");
        $db->exec("DELETE FROM workouts");
        $db->exec("DELETE FROM routine_exercises");
        $db->exec("DELETE FROM routines");
        $db->exec("DELETE FROM active_workout_state");
        $db->exec("DELETE FROM settings");
        $db->exec("DELETE FROM exercises");
        // Re-seed will happen on next load via db.php
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['ajax'] === 'get_explore_routines') {
        echo json_encode([
            ['id' => 'full_body', 'name' => 'Full Body Starter', 'description' => 'Bench, Squat, Pull Ups. Great for beginners.'],
            ['id' => 'upper_body', 'name' => 'Upper Body Power', 'description' => 'Focus on Chest, Back, and Shoulders.'],
            ['id' => 'lower_body', 'name' => 'Leg Day Alpha', 'description' => 'Squats and Deadlifts focus.']
        ]);
        exit;
    }

    if ($_GET['ajax'] === 'get_routine_detail_by_key') {
        $key = $_GET['key'] ?? '';
        $routines = [
            'full_body' => [1, 3, 6],
            'upper_body' => [1, 5, 6],
            'lower_body' => [3, 4]
        ];
        $ids = $routines[$key] ?? [];
        if (empty($ids)) { echo json_encode([]); exit; }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM exercises WHERE id IN ($placeholders) ORDER BY name ASC");
        $stmt->execute($ids);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['ajax'] === 'import_routine') {
        $id = $_GET['id'] ?? '';
        $routines = [
            'full_body' => ['name' => 'Full Body Starter', 'ex' => [1, 3, 6]], // Bench, Squat, Pull Up
            'upper_body' => ['name' => 'Upper Body Power', 'ex' => [1, 5, 6]], // Bench, OHP, Pull Up
            'lower_body' => ['name' => 'Leg Day Alpha', 'ex' => [3, 4]]      // Squat, Deadlift
        ];

        if (!isset($routines[$id])) { echo json_encode(['status' => 'error']); exit; }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO routines (name) VALUES (?)");
            $stmt->execute([$routines[$id]['name']]);
            $routineId = $db->lastInsertId();

            $reStmt = $db->prepare("INSERT INTO routine_exercises (routine_id, exercise_id, position) VALUES (?, ?, ?)");
            foreach ($routines[$id]['ex'] as $idx => $exId) {
                $reStmt->execute([$routineId, $exId, $idx]);
            }
            $db->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['status' => 'error']);
        }
        exit;
    }

    if ($_GET['ajax'] === 'rename_routine') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['name'])) { echo json_encode(['status' => 'error']); exit; }
        
        $stmt = $db->prepare("UPDATE routines SET name = ? WHERE id = ?");
        $stmt->execute([$data['name'], $data['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['ajax'] === 'delete_routine') {
        $id = $_GET['id'] ?? 0;
        $db->beginTransaction();
        try {
            // Delete exercises links first
            $stmt = $db->prepare("DELETE FROM routine_exercises WHERE routine_id = ?");
            $stmt->execute([$id]);
            // Delete routine
            $stmt = $db->prepare("DELETE FROM routines WHERE id = ?");
            $stmt->execute([$id]);
            $db->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['status' => 'error']);
        }
        exit;
    }

    if ($_GET['ajax'] === 'delete_workout') {
        $id = $_GET['id'] ?? 0;
        $db->beginTransaction();
        try {
            // Delete associated sets first
            $stmt = $db->prepare("DELETE FROM workout_sets WHERE workout_id = ?");
            $stmt->execute([$id]);
            // Delete the workout record
            $stmt = $db->prepare("DELETE FROM workouts WHERE id = ?");
            $stmt->execute([$id]);
            $db->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'get_last_exercise_data') {
        $ex_id = $_GET['id'] ?? 0;

        // 1. Find the ID of the last completed workout containing this exercise
        $idStmt = $db->prepare("
            SELECT w.id, w.start_time
            FROM workouts w
            JOIN workout_sets ws ON w.id = ws.workout_id
            WHERE ws.exercise_id = ? AND w.end_time IS NOT NULL
            ORDER BY w.start_time DESC LIMIT 1
        ");
        $idStmt->execute([$ex_id]);
        $lastWk = $idStmt->fetch(PDO::FETCH_ASSOC);
        $lastWkId = $lastWk ? $lastWk['id'] : 0;
        $lastDate = $lastWk ? $lastWk['start_time'] : null;

        // 2. Get sets from THAT specific workout
        $sets = [];
        if ($lastWkId) {
            $stmt = $db->prepare("SELECT weight, raw_weight, reps, set_index, is_drop FROM workout_sets WHERE workout_id = ? AND exercise_id = ? ORDER BY set_index ASC");
            $stmt->execute([$lastWkId, $ex_id]);
            $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $prStmt = $db->prepare("
            SELECT ws.weight, ws.reps, w.start_time as created_at
            FROM workout_sets ws
            JOIN workouts w ON ws.workout_id = w.id
            WHERE ws.exercise_id = ? AND w.end_time IS NOT NULL
            ORDER BY ws.weight DESC, ws.reps DESC, w.start_time DESC
            LIMIT 1
        ");
        $prStmt->execute([$ex_id]);
        $prRow = $prStmt->fetch(PDO::FETCH_ASSOC);

        // 3. Get settings from THAT same specific workout
        $noteRow = null;
        if ($lastWkId) {
            $noteStmt = $db->prepare("SELECT * FROM workout_exercise_notes WHERE workout_id = ? AND exercise_id = ?");
            $noteStmt->execute([$lastWkId, $ex_id]);
            $noteRow = $noteStmt->fetch(PDO::FETCH_ASSOC);
        }

        // 4. Robust Note Retrieval: Find the most recent non-empty note for this exercise
        $lastNote = '';
        $noteQuery = $db->prepare("
            SELECT n.notes 
            FROM workout_exercise_notes n
            JOIN workouts w ON n.workout_id = w.id
            WHERE n.exercise_id = ? AND n.notes != '' AND n.notes != 'Add notes here...'
            ORDER BY w.start_time DESC LIMIT 1
        ");
        $noteQuery->execute([$ex_id]);
        $resNote = $noteQuery->fetch(PDO::FETCH_ASSOC);
        if ($resNote) $lastNote = $resNote['notes'];
        
        echo json_encode([
            'sets' => $sets,
            'lastDate' => $lastDate,
            'pr' => $prRow,
            'lastNote' => $lastNote,
            'base_active' => $noteRow ? (bool)$noteRow['base_weight_active'] : false,
            'base_val' => $noteRow ? $noteRow['base_weight_value'] : 20,
            'single_side' => $noteRow ? (bool)$noteRow['is_single_side'] : false,
            'is_assisted' => $noteRow ? (bool)$noteRow['is_assisted'] : false,
            'is_drop_active' => $noteRow ? (bool)$noteRow['is_drop_active'] : false
        ]);
        exit;
    }

    if ($_GET['ajax'] === 'save_active_state') {
        $data = file_get_contents('php://input');
        $stmt = $db->prepare("INSERT INTO active_workout_state (id, state_json, updated_at) VALUES (1, ?, CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET state_json = excluded.state_json, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$data]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['ajax'] === 'get_active_state') {
        $stmt = $db->query("SELECT state_json FROM active_workout_state WHERE id = 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo $row ? $row['state_json'] : json_encode(null);
        exit;
    }

    if ($_GET['ajax'] === 'clear_active_state') {
        $db->exec("DELETE FROM active_workout_state WHERE id = 1");
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['ajax'] === 'import_hevy') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'File upload failed.']);
            exit;
        }
        
        require_once __DIR__ . '/import.php';
        try {
            $count = atlas_import_hevy_csv($_FILES['csv_file']['tmp_name']);
            echo json_encode(['status' => 'success', 'count' => $count]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'save_exercise') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty(trim($data['name']))) { echo json_encode(['status' => 'error', 'message' => 'Name is required']); exit; }

        try {
            $stmt = $db->prepare("INSERT INTO exercises (name, category, muscles_primary, muscles_secondary) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                trim($data['name']),
                $data['category'] ?? 'Other',
                trim($data['muscles_primary'] ?? ''),
                trim($data['muscles_secondary'] ?? '')
            ]);
            echo json_encode(['status' => 'success', 'id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}