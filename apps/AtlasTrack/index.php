<?php
// AtlasTrack - Workout Intelligence System
require_once __DIR__ . '/modules/db.php';
require_once __DIR__ . '/modules/api.php';
require_once __DIR__ . '/modules/history.php';
require_once __DIR__ . '/modules/calendar.php';

// --- DB SEEDER ---
$ex_count = $db->query("SELECT COUNT(*) FROM exercises")->fetchColumn();
if ($ex_count == 0) {
    $seed = [['Bench Press (Barbell)', 'Barbell', 'Chest', 'Triceps, Shoulders'],['Incline Bench Press (Barbell)', 'Barbell', 'Chest', 'Triceps, Shoulders'],['Squat (Barbell)', 'Barbell', 'Legs', 'Glutes, Core'],['Deadlift (Barbell)', 'Barbell', 'Back', 'Hamstrings, Glutes'],['Overhead Press (Dumbbell)', 'Dumbbell', 'Shoulders', 'Triceps'],
        ['Pull Up', 'Bodyweight', 'Back', 'Biceps']
    ];
    $stmt = $db->prepare("INSERT INTO exercises (name, category, muscles_primary, muscles_secondary) VALUES (?, ?, ?, ?)");
    foreach($seed as $s) $stmt->execute($s);
}

function get_asset_hash($files) {
    $combined = '';
    foreach ($files as $f) {
        $path = __DIR__ . '/' . $f;
        if (file_exists($path)) $combined .= md5_file($path);
    }
    return $combined ? substr(md5($combined), 0, 8) : 'dev';
}

$v = get_asset_hash(['css/style.css', 'js/app.js']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>AtlasTrack</title>
    <meta name="description" content="A comprehensive workout intelligence system custom-built with Conjure OS. Combine elite routine logging, exercise history analytics, and custom structured notes with innovative gym-friendly features—including a custom calculator numpad with quick bulk-fill, and a real-time server-side state engine that automatically resumes your active workout sessions when brought back into focus.">
    <link rel="stylesheet" href="css/style.css?v=<?php echo $v; ?>">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#000000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="icon.svg">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js').catch(err => console.log('SW registration failed:', err));
            });
        }
    </script>
    <!-- Local Cached Libraries -->
    <script src="lib/chart.min.js"></script>
    <script src="lib/lucide.min.js"></script>
    <script src="js/app.js?v=<?php echo $v; ?>"></script>
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="AtlasTrack">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
</head>
<body>

<header class="header" id="main-header">
    <div style="display:flex; align-items:center; gap:12px;">
        <div id="header-back" style="display:none; cursor:pointer;" onclick="AtlasTrack.switchTab('library')">
            <i data-lucide="arrow-left"></i>
        </div>
        <h1 id="header-title" style="font-size: 20px; margin: 0; font-weight: 800;">Workout</h1>
    </div>
    <div id="header-actions" style="display:flex; align-items:center; gap:12px;">
        <!-- Dynamic buttons injected here -->
    </div>
</header>

<div id="view-workout">
    <div class="app-container">
        <button id="btn-main-action" class="btn-primary" onclick="AtlasTrack.startEmptyWorkout()" style="background:var(--card-bg); border:1px solid var(--border-color); color:var(--text-primary); margin-bottom:24px; display:flex; align-items:center; justify-content:center; gap:8px;">
            <i data-lucide="plus" style="width:18px;"></i> Start Empty Workout
        </button>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="font-size:18px; margin:0;">Routines</h2>
            <i data-lucide="folder-plus" style="color:var(--primary-accent); width:20px;"></i>
        </div>

        <div style="display:flex; gap:10px; margin-bottom:20px;">
            <button class="btn-secondary" onclick="AtlasTrack.createNewRoutine()"><i data-lucide="clipboard-list" style="width:16px; vertical-align:middle; margin-right:4px;"></i> New Routine</button>
            <button class="btn-secondary" onclick="AtlasTrack.openExplore()"><i data-lucide="search" style="width:16px; vertical-align:middle; margin-right:4px;"></i> Explore</button>
        </div>

        <div id="routines-list">
            <div style="text-align:center; padding:20px; color:var(--text-secondary); font-size:14px;">Loading routines...</div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin: 24px 0 16px 0;">
            <h2 style="font-size:18px; margin:0;">Recent History</h2>
            <span style="color:var(--primary-accent); font-size:13px; font-weight:600; cursor:pointer;" onclick="AtlasTrack.switchTab('history')">Show All</span>
        </div>

        <div id="recent-history-list">
            <!-- Recent history cards injected here -->
            <div style="text-align:center; padding:40px; color:var(--text-secondary); font-size:14px;">
                <i data-lucide="calendar" style="width:32px; height:32px; margin-bottom:12px; opacity:0.5;"></i>
                <div>No workouts logged yet.</div>
            </div>
        </div>
    </div>

</div>

<div id="view-history" style="display:none;">
    <div class="app-container">
        <?php atlas_render_calendar_shell(); ?>
        <h2 style="font-size:18px; margin: 24px 0 16px 0;">All Workouts</h2>
        <div id="full-history-list">
            <!-- Full history cards injected here -->
        </div>
    </div>
</div><div id="view-library" style="display:none;">
    <div class="app-container">
        <div id="library-list">
        <!-- Populated via JS -->
    </div>
    </div>
</div>

<div id="view-explore" style="display:none;">
    <div class="app-container">
        <div style="margin-bottom:20px; color:var(--text-secondary); font-size:14px;">
            Discover routines designed by pros to help you reach your goals.
        </div>
        <div id="explore-list">
            <!-- Populated via JS -->
        </div>
    </div>
</div>

<div id="view-exercise-detail" style="display:none;">
    <div class="app-container">
        <div class="card" style="padding:0; overflow:hidden; display: flex; flex-direction: column; min-height: 300px;">
            <div style="padding:0 16px; border-bottom:1px solid var(--border-color); display:flex; gap: 20px;">
                <div id="tab-ex-summary" class="ex-tab active" onclick="AtlasTrack.switchDetailTab('summary')">Summary</div>
                <div id="tab-ex-history" class="ex-tab" onclick="AtlasTrack.switchDetailTab('history')">History</div>
            </div>
            <div id="pane-ex-summary" style="padding:16px; flex: 1;">
                <canvas id="progressChart" style="width:100%; height:200px;"></canvas>
            </div>
            <div id="pane-ex-history" style="padding:0; flex: 1; display: none; overflow-y: auto; max-height: 400px;">
                <!-- History list injected here -->
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin:24px 0 12px 0;">
            <h2 style="font-size:16px; margin:0;"><i data-lucide="award" style="width:18px; vertical-align:middle; margin-right:6px; color:var(--warn);"></i> Personal Records</h2>
        </div>

        <div class="card" style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
            <div>
                <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase;">Heaviest Weight</div>
                <div id="pr-weight" style="font-size:18px; font-weight:800; color:var(--primary-accent);">0 kg</div>
            </div>
            <div>
                <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase;">Best 1RM</div>
                <div id="pr-1rm" style="font-size:18px; font-weight:800; color:var(--primary-accent);">0 kg</div>
            </div>
            <div>
                <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase;">Best Set Volume</div>
                <div id="pr-volume" style="font-size:18px; font-weight:800; color:var(--primary-accent);">0 kg</div>
            </div>
        </div>

        <div style="margin:24px 0 12px 0; display:flex; justify-content:space-between; align-items:center;">
            <h2 style="font-size:16px; margin:0;">Strength Level</h2>
            <i data-lucide="help-circle" style="width:16px; color:var(--text-secondary);"></i>
        </div>

        <div class="card" style="padding:20px 16px;">
            <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                <div style="font-size:12px; color:var(--text-secondary);">Best 1RM <strong id="gauge-1rm" style="color:white;">0kg</strong></div>
                <div style="font-size:12px; color:var(--text-secondary);">Level <strong id="gauge-level" style="color:var(--primary-accent);">Beginner</strong></div>
            </div>
            
            <!-- The Gauge -->
            <div style="height:6px; background:#2C2C2E; border-radius:3px; position:relative; margin:20px 0;">
                <div id="gauge-bar" style="position:absolute; left:0; top:0; height:100%; width:0%; background:var(--primary-accent); border-radius:3px; transition: width 1s cubic-bezier(0.2, 0, 0, 1);"></div>
                <div id="gauge-marker" style="position:absolute; left:0%; top:50%; width:12px; height:12px; background:white; border:3px solid var(--primary-accent); border-radius:50%; transform:translate(-50%, -50%); transition: left 1s cubic-bezier(0.2, 0, 0, 1);"></div>
            </div>

            <div style="display:flex; justify-content:space-between; font-size:9px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">
                <span>Beginner</span>
                <span>Intermediate</span>
                <span>Advanced</span>
                <span>Elite</span>
            </div>
        </div>
    </div>
</div>

<div id="view-logger" class="logger-view">
    <div class="logger-header">
        <div style="display:flex; align-items:center; gap:8px; cursor:pointer;" onclick="AtlasTrack.minimizeWorkout()">
            <i data-lucide="chevron-down"></i>
            <span id="logger-title" style="font-weight:600; font-size:16px;">Log Workout</span>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <button id="btn-logger-reorder" class="btn-secondary" onclick="AtlasTrack.openReorder()" style="padding:6px; width:34px; height:34px; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.05); border-radius:8px;">
                <i data-lucide="list-ordered" style="width:20px;"></i>
            </button>
            <button id="btn-logger-save-template" class="btn-secondary" onclick="AtlasTrack.saveAsRoutine()" style="padding:6px 12px; width:auto; font-size:13px; background:rgba(255,255,255,0.05);">Save Template</button>
            <button id="btn-logger-finish" class="btn-primary" onclick="AtlasTrack.finishWorkout()" style="padding:6px 16px; width:auto; font-size:14px;">Finish</button>
        </div>
    </div>
    
    <div class="logger-stats" id="logger-stats-row">
        <div>Duration<strong id="live-duration">00:00</strong></div>
        <div>Volume<strong id="live-volume">0 kg</strong></div>
        <div>Sets<strong id="live-sets">0</strong></div>
        <div><i data-lucide="activity" style="color:var(--text-secondary); width:28px; height:28px;"></i></div>
    </div>

    <div id="workout-exercises">
        <!-- Dynamic exercises go here -->
    </div>

    <div style="padding: 16px;">
        <button class="btn-primary" style="background:rgba(0,122,255,0.1); color:var(--primary-accent);" onclick="AtlasTrack.showExercisePicker()">
            <i data-lucide="plus" style="width:18px; vertical-align:middle; margin-right:4px;"></i> Add Exercise
        </button>
        <button id="btn-logger-cancel" class="btn-secondary" style="width:100%; margin-top:12px; color:var(--danger); background:rgba(255,59,48,0.1);" onclick="AtlasTrack.cancelWorkout()">
            Cancel Workout
        </button>
    </div>
</div>

    <!-- Workout Detail Modal -->
    <div id="detail-modal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="detail-title" style="margin:0;">Workout Detail</h3>
                <i data-lucide="x" style="cursor:pointer;" onclick="document.getElementById('detail-modal').style.display='none'"></i>
            </div>
            <div id="detail-body" style="overflow-y:auto; flex:1; padding:16px;">
                <!-- Set details go here -->
            </div>
        </div>
    </div>

</div>

<div id="rest-timer-bar" class="rest-timer-bar">
    <button class="timer-btn timer-resume-btn" onclick="AtlasTrack.switchTab('logger')" style="background:white; color:var(--primary-accent); display:none; align-items:center; gap:6px;">
        <i data-lucide="play" style="width:14px; fill:currentColor;"></i> Resume
    </button>
    <button class="timer-btn" onclick="AtlasTrack.adjustRestTimer(-15)">-15</button>
    <div id="rest-timer-display" style="font-size:24px; font-weight:700; font-variant-numeric:tabular-nums;">02:00</div>
    <div style="display:flex; gap:8px;">
        <button class="timer-btn" onclick="AtlasTrack.adjustRestTimer(15)">+15</button>
        <button class="timer-btn" style="background:white; color:#0A84FF;" onclick="AtlasTrack.skipRestTimer()">Skip</button>
    </div>
</div>

<!-- Exercise Editor Modal -->
<div id="editor-modal" class="modal-overlay">
    <div class="modal-content" style="height:auto; max-height:85vh;">
        <div class="modal-header">
            <h3 style="margin:0;">New Exercise</h3>
            <i data-lucide="x" style="cursor:pointer;" onclick="AtlasTrack.closeExerciseEditor()"></i>
        </div>
        <div style="padding:16px; overflow-y:auto;">
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase;">Name</label>
                <input type="text" id="edit-ex-name" placeholder="e.g. Bulgarian Split Squat" style="width:100%; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary); font-size:16px;">
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase;">Equipment / Category</label>
                <select id="edit-ex-category" style="width:100%; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary); font-size:16px;">
                    <option value="Barbell">Barbell</option>
                    <option value="Dumbbell">Dumbbell</option>
                    <option value="Machine">Machine</option>
                    <option value="Cable">Cable</option>
                    <option value="Bodyweight">Bodyweight</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase;">Primary Muscle</label>
                <input type="text" id="edit-ex-primary" placeholder="e.g. Quads" style="width:100%; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary); font-size:16px;">
            </div>
            <div style="margin-bottom:24px;">
                <label style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase;">Secondary Muscles</label>
                <input type="text" id="edit-ex-secondary" placeholder="e.g. Glutes, Core" style="width:100%; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary); font-size:16px;">
            </div>
            <button class="btn-primary" onclick="AtlasTrack.saveExercise()">Save Exercise</button>
        </div>
    </div>
</div>

<!-- Action Sheet (Generic Menu) -->
<div id="action-sheet" class="modal-overlay" onclick="AtlasTrack.closeActionSheet()">
    <div class="modal-content" style="height: auto; padding-bottom: calc(20px + env(safe-area-inset-bottom));" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3 id="action-sheet-title" style="margin:0; font-size: 16px;">Menu</h3>
            <i data-lucide="x" style="cursor:pointer;" onclick="AtlasTrack.closeActionSheet()"></i>
        </div>
        <div id="action-sheet-options" style="padding: 8px 0;">
            <!-- Options injected here -->
        </div>
    </div>
</div>

<!-- Reorder Exercises Modal -->
<div id="reorder-modal" class="modal-overlay">
    <div class="modal-content" style="height: 70vh;">
        <div class="modal-header">
            <h3 style="margin:0; font-size: 18px;">Reorder Exercises</h3>
            <i data-lucide="x" style="cursor:pointer;" onclick="AtlasTrack.closeReorder()"></i>
        </div>
        <div id="reorder-list" style="overflow-y:auto; flex:1; padding:16px;">
            <!-- List items injected here -->
        </div>
        <div style="padding:16px; display:flex; gap:12px; border-top:1px solid var(--border-color); background: var(--card-bg);">
            <button class="btn-secondary" style="flex:1;" onclick="AtlasTrack.closeReorder()">Cancel</button>
            <button class="btn-primary" style="flex:2;" onclick="AtlasTrack.saveReorder()">Save Order</button>
        </div>
    </div>
</div>

<!-- Exercise Picker Modal -->
<div id="exercise-modal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="margin:0;">Add Exercise</h3>
            <i data-lucide="x" style="cursor:pointer;" onclick="AtlasTrack.closeExercisePicker()"></i>
        </div>
        <div style="padding:10px 16px;">
            <input type="text" id="ex-search" placeholder="Search exercises..." style="width:100%; padding:10px; border-radius:8px; border:none; background:var(--bg-color); color:var(--text-primary); font-size:16px;" oninput="AtlasTrack.filterExercises(this.value)">
        </div>
        <div id="exercise-list" style="overflow-y:auto; flex:1;">
            <!-- List populated via JS -->
        </div>
    </div>
</div>

<nav id="main-nav" class="bottom-nav">
    <a href="#" class="nav-item active" onclick="AtlasTrack.switchTab('workout')">
        <i data-lucide="dumbbell"></i>
        <span>Workout</span>
    </a>
    <a href="#" class="nav-item" onclick="AtlasTrack.switchTab('library')">
        <i data-lucide="library"></i>
        <span>Library</span>
    </a>
    <a href="#" class="nav-item" onclick="AtlasTrack.switchTab('history')">
        <i data-lucide="clock"></i>
        <span>History</span>
    </a>
</nav>

<div class="build-hash">v.<?php echo $v; ?></div>

<?php include __DIR__ . '/modules/keyboard.php'; ?>
<?php include __DIR__ . '/modules/settings.php'; ?>
    <script>
        // Initialize icons
        if (window.lucide) lucide.createIcons();
    </script>
</body>
</html>