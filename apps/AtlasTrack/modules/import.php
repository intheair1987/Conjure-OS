<?php
// HEVY CSV Import Module

function atlas_import_hevy_csv($tmp_file) {
    global $db;
    
    $file = fopen($tmp_file, 'r');
    if (!$file) throw new Exception("Could not read uploaded file.");
    
    $header = fgetcsv($file);
    if (!$header) throw new Exception("Empty CSV file.");

    // Map column names to indexes to handle variations in column order
    $colMap =[];
    foreach ($header as $i => $col) {
        // Clean BOM and quotes
        $colName = trim($col, "\xEF\xBB\xBF\"' ");
        $colMap[$colName] = $i;
    }
    
    $required =['start_time', 'end_time', 'exercise_title', 'weight_kg', 'reps'];
    foreach ($required as $req) {
        if (!isset($colMap[$req])) throw new Exception("Invalid CSV format. Missing column: " . $req);
    }
    
    $workouts =[];
    while (($row = fgetcsv($file)) !== false) {
        if (count($row) < count($header)) continue;
        
        $start_time = $row[$colMap['start_time']];
        $end_time = $row[$colMap['end_time']];
        $title = isset($colMap['title']) ? $row[$colMap['title']] : 'Hevy Workout';
        $ex_title = $row[$colMap['exercise_title']];
        $notes = isset($colMap['exercise_notes']) ? $row[$colMap['exercise_notes']] : '';
        $weight = (float)$row[$colMap['weight_kg']];
        $reps = (int)$row[$colMap['reps']];
        $set_idx = isset($colMap['set_index']) ? (int)$row[$colMap['set_index']] : 0;
        
        $wk_key = $start_time; // Group by start time
        
        if (!isset($workouts[$wk_key])) {
            $workouts[$wk_key] =[
                'name' => $title,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'exercises' => []
            ];
        }
        
        if (!isset($workouts[$wk_key]['exercises'][$ex_title])) {
            $workouts[$wk_key]['exercises'][$ex_title] =[
                'notes' => $notes,
                'sets' => []
            ];
        } else if (empty($workouts[$wk_key]['exercises'][$ex_title]['notes']) && !empty($notes)) {
            // Capture notes if they appear on subsequent rows
            $workouts[$wk_key]['exercises'][$ex_title]['notes'] = $notes;
        }
        
        $workouts[$wk_key]['exercises'][$ex_title]['sets'][] =[
            'weight' => $weight,
            'reps' => $reps,
            'index' => $set_idx
        ];
    }
    fclose($file);
    
    // Cache existing exercises to prevent duplicates
    $exCache =[];
    $stmt = $db->query("SELECT id, name FROM exercises");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $exCache[strtolower(trim($r['name']))] = $r['id'];
    }
    
    $db->beginTransaction();
    try {
        $insertEx = $db->prepare("INSERT INTO exercises (name, category, muscles_primary, muscles_secondary) VALUES (?, 'Other', '', '')");
        $insertWk = $db->prepare("INSERT INTO workouts (name, start_time, end_time, total_volume, total_sets) VALUES (?, ?, ?, ?, ?)");
        $insertSet = $db->prepare("INSERT INTO workout_sets (workout_id, exercise_id, weight, reps, set_index) VALUES (?, ?, ?, ?, ?)");
        $insertNote = $db->prepare("INSERT INTO workout_exercise_notes (workout_id, exercise_id, notes) VALUES (?, ?, ?)");
        
        foreach ($workouts as $wk) {
            // Convert dates from "28 Apr 2026, 21:06" to "Y-m-d H:i:s"
            $dtStart = DateTime::createFromFormat('j M Y, H:i', $wk['start_time']);
            $dtEnd = DateTime::createFromFormat('j M Y, H:i', $wk['end_time']);
            
            $startStr = $dtStart ? $dtStart->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
            $endStr = $dtEnd ? $dtEnd->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
            
            $total_volume = 0;
            $total_sets = 0;
            
            // Pre-calculate volume
            foreach ($wk['exercises'] as $ex) {
                foreach ($ex['sets'] as $s) {
                    $total_volume += ($s['weight'] * $s['reps']);
                    $total_sets++;
                }
            }
            
            $insertWk->execute([$wk['name'], $startStr, $endStr, $total_volume, $total_sets]);
            $wkId = $db->lastInsertId();
            
            foreach ($wk['exercises'] as $exName => $exData) {
                $normName = strtolower(trim($exName));
                if (!isset($exCache[$normName])) {
                    $insertEx->execute([trim($exName)]);
                    $exCache[$normName] = $db->lastInsertId();
                }
                $exId = $exCache[$normName];
                
                if (!empty($exData['notes'])) {
                    $insertNote->execute([$wkId, $exId, trim($exData['notes'])]);
                }
                
                $setCounter = 1;
                foreach ($exData['sets'] as $s) {
                    // Ignore the CSV index and enforce AtlasTrack 1-based sequential indexing
                    $insertSet->execute([$wkId, $exId, $s['weight'], $s['reps'], $setCounter++]);
                }
            }
        }
        $db->commit();
        return count($workouts);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}