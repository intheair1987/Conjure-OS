<?php
// Workout History Logic & UI Components

function atlas_get_history($limit = 3) {
    global $db;
    $stmt = $db->prepare("
        SELECT w.*, 
        (SELECT GROUP_CONCAT(e.name, ', ') 
         FROM workout_sets ws 
         JOIN exercises e ON ws.exercise_id = e.id 
         WHERE ws.workout_id = w.id 
         GROUP BY ws.workout_id) as exercise_summary
        FROM workouts w 
        ORDER BY w.start_time DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function atlas_get_all_history() {
    global $db;
    $stmt = $db->query("
        SELECT w.*, 
        (SELECT GROUP_CONCAT(e.name, ', ') 
         FROM workout_sets ws 
         JOIN exercises e ON ws.exercise_id = e.id 
         WHERE ws.workout_id = w.id 
         GROUP BY ws.workout_id) as exercise_summary
        FROM workouts w 
        ORDER BY w.start_time DESC 
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function atlas_render_history_item($w) {
    $date = date('M d, Y', strtotime($w['start_time']));
    $duration_sec = strtotime($w['end_time']) - strtotime($w['start_time']);
    $m = floor($duration_sec / 60);
    $s = $duration_sec % 60;
    $duration_text = sprintf('%dm %ds', $m, $s);
    
    return '
    <div class="card workout-history-card" onclick="AtlasTrack.viewWorkoutDetail('.$w['id'].')">
        <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:8px;">
            <div>
                <h3 style="margin:0; font-size:16px;">'.htmlspecialchars($w['name'] ?: 'Workout').'</h3>
                <div style="font-size:12px; color:var(--text-secondary); margin-top:2px;">'.$date.'</div>
            </div>
            <i data-lucide="more-horizontal" style="color:var(--text-secondary); width:18px; cursor:pointer;" onclick="AtlasTrack.showHistoryMenu(event, '.$w['id'].')"></i>
        </div>
        <div style="display:flex; gap:16px; margin-bottom:12px;">
            <div style="font-size:13px;"><i data-lucide="clock" style="width:12px; vertical-align:middle; margin-right:4px; color:var(--primary-accent);"></i>'.$duration_text.'</div>
            <div style="font-size:13px;"><i data-lucide="weight" style="width:12px; vertical-align:middle; margin-right:4px; color:var(--primary-accent);"></i>'.$w['total_volume'].' kg</div>
        </div>
        <p style="color:var(--text-secondary); font-size:13px; margin:0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            '.($w['exercise_summary'] ?: 'No exercises logged').'
        </p>
    </div>';
}