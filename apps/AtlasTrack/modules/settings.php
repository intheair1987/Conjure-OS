<?php
// Settings & Tools Module
?>
<div id="settings-modal" class="modal-overlay">
    <div class="modal-content" style="height: 85vh;">
        <div class="modal-header">
            <h3 style="margin:0;">Settings</h3>
            <i data-lucide="x" style="cursor:pointer;" onclick="AtlasTrack.closeSettings()"></i>
        </div>
        <div style="padding:16px; overflow-y:auto; flex:1;">
            <div class="card">
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase;">App Theme</label>
                    <select id="prof-theme" style="width:100%; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary); font-size:16px;" onchange="AtlasTrack.saveSettings()">
                        <option value="dark">Midnight (Default)</option>
                        <option value="neon">Electric Neon</option>
                        <option value="crimson">Crimson Industrial</option>
                        <option value="frost">Midnight Frost</option>
                        <option value="vintage">Vintage Strength</option>
                        <option value="solarized">Solarized Light</option>
                        <option value="slate">Slate Pro</option>
                    </select>
                </div>
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase;">Body Weight (kg)</label>
                    <input type="number" id="prof-weight" style="width:100%; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary); font-size:18px; font-weight:700;" onchange="AtlasTrack.saveSettings()">
                </div>
                <div style="margin-bottom:10px;">
                    <label style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase;">Gender</label>
                    <select id="prof-gender" style="width:100%; padding:12px; border-radius:10px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary); font-size:16px;" onchange="AtlasTrack.saveSettings()">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
            </div>

            <h2 style="font-size:18px; margin:24px 0 16px 0;">Notifications</h2>
            <div class="card">
                <div style="margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <label style="font-size:12px; color:var(--text-secondary); text-transform:uppercase;">Chime Volume</label>
                        <span id="vol-val" style="font-size:12px; font-weight:800; color:var(--primary-accent);">50%</span>
                    </div>
                    <input type="range" id="notif-volume" min="0" max="1" step="0.1" style="width:100%; accent-color:var(--primary-accent);" oninput="document.getElementById('vol-val').innerText = Math.round(this.value*100)+'%'" onchange="AtlasTrack.saveSettings()">
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                    <div>
                        <label style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase;">Repetitions</label>
                        <input type="number" id="notif-repeats" min="1" max="10" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary);" onchange="AtlasTrack.saveSettings()">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase;">Interval (sec)</label>
                        <input type="number" id="notif-interval" min="2" max="60" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-primary);" onchange="AtlasTrack.saveSettings()">
                    </div>
                </div>
            </div>

            <h2 style="font-size:18px; margin:24px 0 16px 0;">Data & Tools</h2>
            <div class="card" style="padding: 0; overflow: hidden;">
                <button class="action-item" onclick="AtlasTrack.importHevy()">
                    <i data-lucide="upload-cloud" style="width:18px; color:var(--primary-accent);"></i> Import from Hevy (CSV)
                </button>
                <input type="file" id="hevy-import-file" style="display:none;" accept=".csv" onchange="AtlasTrack.handleHevyUpload(event)">
                <button class="action-item" onclick="AtlasTrack.resetAction('workout')">
                    <i data-lucide="rotate-ccw" style="width:18px; color:var(--warn);"></i> Reset Active Workout
                </button>
                <button class="action-item" onclick="AtlasTrack.resetAction('history')">
                    <i data-lucide="calendar-x" style="width:18px; color:var(--danger);"></i> Clear Workout History
                </button>
                <button class="action-item" onclick="AtlasTrack.resetAction('routines')">
                    <i data-lucide="trash-2" style="width:18px; color:var(--danger);"></i> Delete All Routines
                </button>
                <button class="action-item" style="border-bottom: none;" onclick="AtlasTrack.resetAction('app')">
                    <i data-lucide="refresh-cw" style="width:18px; color:var(--danger);"></i> Factory Reset App
                </button>
            </div>
            
            <div style="text-align:center; margin-top:30px; font-size:12px; color:var(--text-secondary);">
                AtlasTrack v.<?php echo $v; ?>
            </div>
        </div>
    </div>
</div>