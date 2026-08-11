<?php
// Exercise Analytics & PR Logic

function atlas_get_exercise_stats($ex_id) {
    global $db;
    
    // 1. Get PRs
    $stats = [
        'heaviest_weight' => 0,
        'best_1rm' => 0,
        'best_set_volume' => 0,
        'best_session_volume' => 0
    ];

    // Heaviest Weight & Best Set Volume
    $stmt = $db->prepare("SELECT MAX(weight) as max_w, MAX(weight * reps) as max_v FROM workout_sets WHERE exercise_id = ?");
    $stmt->execute([$ex_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['heaviest_weight'] = $row['max_w'] ?: 0;
    $stats['best_set_volume'] = $row['max_v'] ?: 0;

    // Best 1RM (Brzycki Formula: weight / (1.0278 - (0.0278 * reps)))
    $stmt = $db->prepare("SELECT weight, reps FROM workout_sets WHERE exercise_id = ?");
    $stmt->execute([$ex_id]);
    $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sets as $s) {
        if ($s['reps'] > 0) {
            $oneRM = $s['weight'] / (1.0278 - (0.0278 * $s['reps']));
            if ($oneRM > $stats['best_1rm']) $stats['best_1rm'] = round($oneRM, 2);
        }
    }

    // 2. Get Chart Data (Last 30 unique days of activity)
    // Grouping by date() ensures only one point per day (the heaviest lift)
    $stmt = $db->prepare("
        SELECT date(w.start_time) as date, MAX(ws.weight) as max_weight, SUM(ws.weight * ws.reps) as session_volume
        FROM workout_sets ws
        JOIN workouts w ON ws.workout_id = w.id
        WHERE ws.exercise_id = ?
        GROUP BY date(w.start_time)
        ORDER BY date(w.start_time) ASC
        LIMIT 30
    ");
    $stmt->execute([$ex_id]);
    $stats['history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Get Full Detailed History (All sets grouped by workout)
    $stmt = $db->prepare("
        SELECT w.id as workout_id, w.name as workout_name, date(w.start_time) as date, ws.weight, ws.reps, ws.set_index
        FROM workout_sets ws
        JOIN workouts w ON ws.workout_id = w.id
        WHERE ws.exercise_id = ?
        ORDER BY w.start_time DESC, ws.set_index ASC
    ");
    $stmt->execute([$ex_id]);
    $stats['full_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $stats;
}