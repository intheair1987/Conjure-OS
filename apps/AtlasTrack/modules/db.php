<?php
// Database Connection & Schema Initialization
$db_file = __DIR__ . '/../app.db';
$db = new PDO("sqlite:$db_file");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Initialize Schema
$db->exec("CREATE TABLE IF NOT EXISTS exercises (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category TEXT,
    muscles_primary TEXT,
    muscles_secondary TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS routines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS routine_exercises (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    routine_id INTEGER,
    exercise_id INTEGER,
    position INTEGER,
    FOREIGN KEY(routine_id) REFERENCES routines(id),
    FOREIGN KEY(exercise_id) REFERENCES exercises(id)
)");

$db->exec("CREATE TABLE IF NOT EXISTS workouts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    start_time DATETIME,
    end_time DATETIME,
    total_volume REAL,
    total_sets INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Migration: Add name column if missing
$cols = $db->query("PRAGMA table_info(workouts)")->fetchAll(PDO::FETCH_ASSOC);
$hasName = false;
foreach($cols as $c) if($c['name'] === 'name') $hasName = true;
if(!$hasName) $db->exec("ALTER TABLE workouts ADD COLUMN name TEXT");

// Migration: Fix 0-based set indices from legacy HEVY imports
// We check if any 0s exist, and if so, increment every set index for those specific workouts
$hasZeros = $db->query("SELECT COUNT(*) FROM workout_sets WHERE set_index = 0")->fetchColumn();
if ($hasZeros > 0) {
    $db->exec("UPDATE workout_sets SET set_index = set_index + 1 WHERE workout_id IN (SELECT DISTINCT workout_id FROM workout_sets WHERE set_index = 0)");
}

$db->exec("CREATE TABLE IF NOT EXISTS workout_sets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workout_id INTEGER,
    exercise_id INTEGER,
    weight REAL,
    raw_weight REAL,
    reps INTEGER,
    set_index INTEGER,
    FOREIGN KEY(workout_id) REFERENCES workouts(id)
)");

// Migration for raw_weight
$cols = $db->query("PRAGMA table_info(workout_sets)")->fetchAll(PDO::FETCH_ASSOC);
$hasRaw = false; foreach($cols as $c) if($c['name'] === 'raw_weight') $hasRaw = true;
if(!$hasRaw) $db->exec("ALTER TABLE workout_sets ADD COLUMN raw_weight REAL");

// Migration for drop sets flag on sets
$cols = $db->query("PRAGMA table_info(workout_sets)")->fetchAll(PDO::FETCH_ASSOC);
$hasIsDrop = false; foreach($cols as $c) if($c['name'] === 'is_drop') $hasIsDrop = true;
if(!$hasIsDrop) $db->exec("ALTER TABLE workout_sets ADD COLUMN is_drop INTEGER DEFAULT 0");

$db->exec("CREATE TABLE IF NOT EXISTS workout_exercise_notes (
    workout_id INTEGER,
    exercise_id INTEGER,
    notes TEXT,
    base_weight_active INTEGER DEFAULT 0,
    base_weight_value REAL DEFAULT 20,
    is_single_side INTEGER DEFAULT 0,
    PRIMARY KEY(workout_id, exercise_id)
)");

// Migration for calculator settings
$cols = $db->query("PRAGMA table_info(workout_exercise_notes)")->fetchAll(PDO::FETCH_ASSOC);
$hasBase = false; foreach($cols as $c) if($c['name'] === 'base_weight_active') $hasBase = true;
if(!$hasBase) {
    $db->exec("ALTER TABLE workout_exercise_notes ADD COLUMN base_weight_active INTEGER DEFAULT 0");
    $db->exec("ALTER TABLE workout_exercise_notes ADD COLUMN base_weight_value REAL DEFAULT 20");
    $db->exec("ALTER TABLE workout_exercise_notes ADD COLUMN is_single_side INTEGER DEFAULT 0");
}

// Migration for assisted mode
$cols = $db->query("PRAGMA table_info(workout_exercise_notes)")->fetchAll(PDO::FETCH_ASSOC);
$hasAssisted = false; foreach($cols as $c) if($c['name'] === 'is_assisted') $hasAssisted = true;
if(!$hasAssisted) $db->exec("ALTER TABLE workout_exercise_notes ADD COLUMN is_assisted INTEGER DEFAULT 0");

// Migration for drop sets toggle state
$cols = $db->query("PRAGMA table_info(workout_exercise_notes)")->fetchAll(PDO::FETCH_ASSOC);
$hasDropActive = false; foreach($cols as $c) if($c['name'] === 'is_drop_active') $hasDropActive = true;
if(!$hasDropActive) $db->exec("ALTER TABLE workout_exercise_notes ADD COLUMN is_drop_active INTEGER DEFAULT 0");

$db->exec("CREATE TABLE IF NOT EXISTS active_workout_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    state_json TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    val TEXT
)");

// DB Seeder
$ex_count = $db->query("SELECT COUNT(*) FROM exercises")->fetchColumn();
if ($ex_count == 0) {
    $seed = [
        ['Bench Press (Barbell)', 'Barbell', 'Chest', 'Triceps, Shoulders'],
        ['Incline Bench Press (Barbell)', 'Barbell', 'Chest', 'Triceps, Shoulders'],
        ['Squat (Barbell)', 'Barbell', 'Legs', 'Glutes, Core'],
        ['Deadlift (Barbell)', 'Barbell', 'Back', 'Hamstrings, Glutes'],
        ['Overhead Press (Dumbbell)', 'Dumbbell', 'Shoulders', 'Triceps'],
        ['Pull Up', 'Bodyweight', 'Back', 'Biceps']
    ];
    $stmt = $db->prepare("INSERT INTO exercises (name, category, muscles_primary, muscles_secondary) VALUES (?, ?, ?, ?)");
    foreach($seed as $s) $stmt->execute($s);
}