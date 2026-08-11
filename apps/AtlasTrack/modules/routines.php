<?php
// Routine Management Logic & UI Components

function atlas_get_routines() {
    global $db;
    $stmt = $db->query("
        SELECT r.*, 
        (SELECT GROUP_CONCAT(e.name, ', ') 
         FROM routine_exercises re 
         JOIN exercises e ON re.exercise_id = e.id 
         WHERE re.routine_id = r.id 
         ORDER BY re.position ASC) as exercise_summary
        FROM routines r 
        ORDER BY r.name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function atlas_render_routine_item($r) {
    return '
    <div class="card routine-card">
        <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:8px;">
            <h3 style="margin:0; font-size:16px;">'.htmlspecialchars($r['name']).'</h3>
            <div style="display:flex; gap:12px;">
                <i data-lucide="more-horizontal" style="color:var(--text-secondary); width:18px; cursor:pointer;" onclick="AtlasTrack.showRoutineMenu(event, '.$r['id'].', \''.addslashes($r['name']).'\')"></i>
            </div>
        </div>
        <p style="color:var(--text-secondary); font-size:13px; margin:0 0 16px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            '.($r['exercise_summary'] ?: 'No exercises added').'
        </p>
        <button class="btn-primary" onclick="AtlasTrack.startWorkoutFromRoutine('.$r['id'].', \''.addslashes($r['name']).'\')">Start Routine</button>
    </div>';
}