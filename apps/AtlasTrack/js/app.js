const AtlasTrack = {
    state: {
        view: 'routines',
        isLogging: false,
        workoutStartTime: null,
        timerInterval: null,
        prMap: {} // Stores {exId: {weight, reps}}
    },

    init() {
        console.log("AtlasTrack Initialized");
        this.loadSettings();
        this.loadHistory();
        this.loadLibrary();
        this.loadRoutines();
        this.bindEvents();
        
        // Check for ongoing workout
        this.checkActiveWorkout();
    },

    async checkActiveWorkout() {
        const res = await fetch('index.php?ajax=get_active_state');
        const data = await res.json();
        if (data && data.isLogging) {
            this.hydrateActiveWorkout(data);
        } else {
            this.switchTab('workout');
        }
    },

    async hydrateActiveWorkout(data) {
        this.state = { ...this.state, ...data };
        this.state.prMap = data.prMap || {};
        this.state.isHydrating = true; 
        
        this.updateLoggerUI();
        this.switchTab('logger');
        this.startDurationTimer();

        const container = document.getElementById('workout-exercises');
        container.innerHTML = "";

        for (const ex of data.exercises) {
            const instanceId = ex.instanceId;
            const meta = ex.meta || { note: ex.note, base_active: false, base_val: 20, single_side: false, is_assisted: false, is_drop_active: false };
            
            this.injectExerciseCard(ex.exId, ex.name, instanceId, meta, this.state.prMap[ex.exId] || null);

            ex.sets.forEach(s => {
                this.addSetRow(instanceId, s.prevWeight, null, s.prevReps, s.prevDate, s.isDrop);
                const row = document.getElementById(`sets_${instanceId}`).lastElementChild;
                row.querySelector('.weight-input').value = s.weight;
                row.querySelector('.reps-input').value = s.reps;
                if (s.completed) this.toggleSet(row.id);
            });
            this.updateCalculatedWeights(instanceId);
        }

        this.calculateVolume();
        
        // Resume Rest Timer if it was active
        if (data.isRestTimerActive && data.restTimerTarget) {
            this.state.restTimerTarget = data.restTimerTarget;
            document.body.classList.add('timer-active');
            this.startRestTimerLogic(); 
        }

        this.state.isHydrating = false;
    },

    syncActiveState() {
        if (!this.state.isLogging || this.state.isHydrating) return;
        
        // Debounce to prevent server hammering
        clearTimeout(this._syncTimer);
        this._syncTimer = setTimeout(async () => {
            const data = {
                isLogging: true,
                workoutName: this.state.workoutName,
                workoutStartTime: this.state.workoutStartTime,
                isEditingHistory: this.state.isEditingHistory,
                editingWorkoutId: this.state.editingWorkoutId,
                restTimerTarget: this.state.restTimerTarget,
                isRestTimerActive: !!this.state.restTimerInterval,
                prMap: this.state.prMap || {},
                exercises: []
            };

            document.querySelectorAll('.ex-card').forEach(card => {
                let noteText = card.querySelector('.ex-notes').innerText.trim();
                if (noteText === "Add notes here...") noteText = "";

                const ex = {
                    instanceId: card.id,
                    exId: card.dataset.exId,
                    name: card.querySelector('.ex-card-title').innerText,
                    note: noteText,
                    meta: {
                        note: noteText,
                        base_active: card.querySelector('.base-active-check').checked,
                        base_val: parseFloat(card.querySelector('.base-weight-input').value) || 0,
                        single_side: card.querySelector('.single-side-check').checked,
                        is_assisted: card.querySelector('.assisted-check').checked,
                        is_drop_active: card.querySelector('.drop-sets-check').checked
                    },
                    sets: []
                };
                card.querySelectorAll('.set-row').forEach(row => {
                    ex.sets.push({
                        weight: row.querySelector('.weight-input').value,
                        reps: row.querySelector('.reps-input').value,
                        completed: row.classList.contains('completed'),
                        isDrop: row.classList.contains('is-drop-set'),
                        prevWeight: row.dataset.prevW || null,
                        prevReps: row.dataset.prevR || null,
                        prevDate: row.dataset.prevD || null
                    });
                });
                data.exercises.push(ex);
            });

            await fetch('index.php?ajax=save_active_state', {
                method: 'POST',
                body: JSON.stringify(data)
            });
        }, 1000);
    },

    async clearActiveState() {
        await fetch('index.php?ajax=clear_active_state');
    },

    async loadRoutines() {
        const res = await fetch('index.php?ajax=get_routines');
        const data = await res.json();
        const container = document.getElementById('routines-list');
        if (data.html) {
            container.innerHTML = data.html;
            if (window.lucide) lucide.createIcons();
        } else {
            container.innerHTML = '<div style="text-align:center; padding:20px; color:var(--text-secondary); font-size:14px;">No routines saved.</div>';
        }
    },

    async saveAsRoutine() {
        this.haptic();
        const name = prompt("Enter Routine Name:", this.state.editingRoutineName || "");
        if (!name) return;

        const exercises = [];
        document.querySelectorAll('.ex-card').forEach(card => {
            exercises.push(card.dataset.exId);
        });

        if (exercises.length === 0) {
            alert("Add at least one exercise first.");
            return;
        }

        const res = await fetch('index.php?ajax=save_routine', {
            method: 'POST',
            body: JSON.stringify({ 
                id: this.state.editingRoutineId, 
                name, 
                exercises 
            })
        });
        const result = await res.json();
        if (result.status === 'success') {
            alert("Routine Saved!");
            this.clearActiveState();
            this.resetLoggerUI();
            this.loadRoutines();
        }
    },

    async startWorkoutFromRoutine(id, name) {
        this.haptic();
        // Fetch routine details
        const res = await fetch(`index.php?ajax=get_routine_detail&id=${id}`);
        const exercises = await res.json();

        // Start workout logic
        this.state.isLogging = true;
        this.state.workoutName = name || "Routine Workout";
        this.state.isEditingRoutine = false;
        this.state.isEditingHistory = false;
        this.state.editingWorkoutId = null;
        this.state.workoutStartTime = Date.now();
        
        this.updateLoggerUI();
        this.switchTab('logger');
        
        this.startDurationTimer();
        
        // Clear previous exercises
        document.getElementById('workout-exercises').innerHTML = "";

        // Add exercises from routine (Sequential to maintain order)
        for (const ex of exercises) {
            await this.addExerciseToWorkout(ex.id, ex.name);
        }

        window.scrollTo(0,0);
    },

    async loadSettings() {
        const res = await fetch('index.php?ajax=get_settings');
        this.settings = await res.json();
        
        // Apply Theme
        const theme = this.settings.theme || 'dark';
        document.body.setAttribute('data-theme', theme);
        document.getElementById('prof-theme').value = theme;

        document.getElementById('prof-weight').value = this.settings.body_weight;
        document.getElementById('prof-gender').value = this.settings.gender;
        
        // Notification settings
        document.getElementById('notif-volume').value = this.settings.timer_volume || 0.5;
        document.getElementById('vol-val').innerText = Math.round((this.settings.timer_volume || 0.5) * 100) + '%';
        document.getElementById('notif-repeats').value = this.settings.timer_repeats || 1;
        document.getElementById('notif-interval').value = this.settings.timer_interval || 5;
    },

    async saveSettings() {
        this.settings.theme = document.getElementById('prof-theme').value;
        document.body.setAttribute('data-theme', this.settings.theme);

        this.settings.body_weight = parseFloat(document.getElementById('prof-weight').value);
        this.settings.gender = document.getElementById('prof-gender').value;
        
        // Notification settings
        this.settings.timer_volume = parseFloat(document.getElementById('notif-volume').value);
        this.settings.timer_repeats = parseInt(document.getElementById('notif-repeats').value);
        this.settings.timer_interval = parseInt(document.getElementById('notif-interval').value);

        await fetch('index.php?ajax=save_settings', {
            method: 'POST',
            body: JSON.stringify(this.settings)
        });
    },

    openSettings() {
        this.haptic();
        document.getElementById('settings-modal').style.display = 'block';
    },

    closeSettings() {
        document.getElementById('settings-modal').style.display = 'none';
    },

    importHevy() {
        this.haptic();
        document.getElementById('hevy-import-file').click();
    },

    async handleHevyUpload(event) {
        const file = event.target.files[0];
        if (!file) return;
        
        this.haptic();
        
        // Show a simple loading indicator
        const btn = document.querySelector('button[onclick="AtlasTrack.importHevy()"]');
        const origText = btn.innerHTML;
        btn.innerHTML = '<i data-lucide="loader" style="width:18px; color:var(--primary-accent);" class="lucide-spin"></i> Importing...';
        if (window.lucide) lucide.createIcons();

        const fd = new FormData();
        fd.append('csv_file', file);
        
        try {
            const res = await fetch('index.php?ajax=import_hevy', {
                method: 'POST',
                body: fd
            });
            const result = await res.json();
            if (result.status === 'success') {
                alert(`Successfully imported ${result.count} workouts!`);
                this.loadHistory();
                this.loadLibrary(); // Refresh library in case new exercises were added
            } else {
                alert("Import failed: " + result.message);
            }
        } catch (e) {
            alert("Upload error.");
        }
        
        // Reset UI
        btn.innerHTML = origText;
        if (window.lucide) lucide.createIcons();
        event.target.value = '';
    },

    async resetAction(type) {
        this.haptic();
        let msg = "";
        let endpoint = "";

        if (type === 'workout') {
            if (!confirm("Discard current workout progress?")) return;
            await this.clearActiveState();
            location.reload();
            return;
        }

        if (type === 'history') {
            msg = "Delete all workout logs? This cannot be undone.";
            endpoint = "reset_history";
        } else if (type === 'routines') {
            msg = "Delete all your custom routines?";
            endpoint = "reset_routines";
        } else if (type === 'app') {
            msg = "FACTORY RESET: This will wipe all history, routines, exercises, and settings. Continue?";
            endpoint = "reset_app";
        }

        if (confirm(msg)) {
            const res = await fetch(`index.php?ajax=${endpoint}`);
            const data = await res.json();
            if (data.status === 'success') {
                alert("Reset Complete");
                location.reload();
            }
        }
    },

    minimizeWorkout() {
        this.haptic();
        this.switchTab('workout');
    },

    switchTab(tab) {
        this.haptic();

        // Update Main Action Button if a workout is active
        const mainBtn = document.getElementById('btn-main-action');
        if (mainBtn) {
            if (this.state.isLogging || this.state.isEditingRoutine || this.state.isEditingHistory) {
                let label = "Resume Workout";
                if (this.state.isEditingRoutine) label = "Resume Routine Edit";
                if (this.state.isEditingHistory) label = "Resume History Edit";
                
                mainBtn.innerHTML = `<i data-lucide="play" style="width:18px;"></i> ${label}`;
                mainBtn.style.background = 'rgba(0, 122, 255, 0.1)';
                mainBtn.style.color = 'var(--primary-accent)';
                mainBtn.onclick = () => this.switchTab('logger');
            } else {
                mainBtn.innerHTML = `<i data-lucide="plus" style="width:18px;"></i> Start Empty Workout`;
                mainBtn.style.background = 'var(--card-bg)';
                mainBtn.style.color = 'var(--text-primary)';
                mainBtn.onclick = () => this.startEmptyWorkout();
            }
            if (window.lucide) lucide.createIcons();
        }

        // Track return path when entering exercise-detail
        if (tab === 'exercise-detail') {
            this.state.returnTab = this.state.view;
        }
        this.state.view = tab;

        // Hide all main views
        const views = ['library', 'workout', 'exercise-detail', 'history', 'logger', 'explore'];
        views.forEach(v => {
            const el = document.getElementById(`view-${v}`);
            if (el) el.style.display = 'none';
        });
        
        // Show target view
        const target = document.getElementById(`view-${tab}`);
        if (target) target.style.display = 'block';

        if (tab === 'history') {
            this.fetchCalendarData();
        }
        
        // Update Nav Active State
        document.querySelectorAll('.nav-item').forEach(nav => {
            nav.classList.remove('active');
            const span = nav.querySelector('span');
            if (span && span.innerText.toLowerCase() === tab) nav.classList.add('active');
        });

        // --- SHARED HEADER LOGIC ---
        const header = document.getElementById('main-header');
        const nav = document.getElementById('main-nav');
        const titleEl = document.getElementById('header-title');
        const backBtn = document.getElementById('header-back');
        const actions = document.getElementById('header-actions');

        // Hide bars during active logging
        if (tab === 'logger') {
            header.style.display = 'none';
            nav.style.display = 'none';
            document.body.classList.add('is-logging');
        } else {
            header.style.display = 'flex';
            nav.style.display = 'flex';
            document.body.classList.remove('is-logging');
            
            // Set Title
            const titles = { 'workout': 'Workout', 'library': 'Library', 'history': 'History', 'exercise-detail': 'Exercise', 'explore': 'Explore' };
            titleEl.innerText = titles[tab] || 'AtlasTrack';
            
            // Handle Back Button
            backBtn.style.display = (tab === 'exercise-detail' || tab === 'explore') ? 'block' : 'none';
            backBtn.onclick = () => {
                if (tab === 'explore') this.switchTab('workout');
                else if (tab === 'exercise-detail') this.switchTab(this.state.returnTab || 'library');
                else this.switchTab('library');
            };

            // Handle Actions
            actions.innerHTML = '';
            const gearIcon = '<i data-lucide="settings" style="cursor:pointer; width:24px; color:var(--text-secondary);" onclick="AtlasTrack.openSettings()" onpointerdown="AtlasTrack.startSettingsLongPress()" onpointerup="AtlasTrack.clearSettingsLongPress()" onpointerleave="AtlasTrack.clearSettingsLongPress()" oncontextmenu="return false;"></i>';
            
            if (tab === 'library') {
                actions.innerHTML = `
                    <button class="btn-primary" style="width:auto; padding:6px 12px; font-size:13px; display:flex; align-items:center; gap:6px; margin-right:8px;" onclick="AtlasTrack.openExerciseEditor()">
                        <i data-lucide="plus" style="width:16px;"></i> New
                    </button>
                    ${gearIcon}`;
            } else {
                actions.innerHTML = gearIcon;
            }
            if (window.lucide) lucide.createIcons();
        }
    },

    async viewExerciseDetail(id, name) {
        this.haptic();
        this.switchTab('exercise-detail');
        this.switchDetailTab('summary'); // Reset to summary tab
        
        const titleEl = document.getElementById('header-title');
        if (titleEl) titleEl.innerText = name;

        const res = await fetch(`index.php?ajax=get_exercise_analytics&id=${id}`);
        const data = await res.json();
        this.state.currentExData = data; // Store for tab switching

        document.getElementById('pr-weight').innerText = data.heaviest_weight + ' kg';
        document.getElementById('pr-1rm').innerText = data.best_1rm + ' kg';
        document.getElementById('pr-volume').innerText = data.best_set_volume + ' kg';

        this.renderProgressChart(data.history);
        this.renderExerciseHistoryList(data.full_history);
        this.updateStrengthGauge(name, data.best_1rm);
    },

    switchDetailTab(tab) {
        this.haptic();
        document.querySelectorAll('.ex-tab').forEach(el => el.classList.remove('active'));
        document.getElementById(`tab-ex-${tab}`).classList.add('active');

        document.getElementById('pane-ex-summary').style.display = (tab === 'summary') ? 'block' : 'none';
        document.getElementById('pane-ex-history').style.display = (tab === 'history') ? 'block' : 'none';
    },

    renderExerciseHistoryList(history) {
        const container = document.getElementById('pane-ex-history');
        if (!history || history.length === 0) {
            container.innerHTML = '<div style="padding:20px; text-align:center; color:var(--text-secondary);">No history found.</div>';
            return;
        }

        // Group by date/workout
        const groups = {};
        history.forEach(s => {
            const key = s.date + '_' + s.workout_id;
            if (!groups[key]) groups[key] = { date: s.date, name: s.workout_name, sets: [] };
            groups[key].sets.push(s);
        });

        container.innerHTML = Object.values(groups).map(g => `
            <div style="border-bottom: 1px solid var(--border-color);">
                <div style="padding:10px 16px; background: rgba(255,255,255,0.02); display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:11px; font-weight:800; color:var(--primary-accent); text-transform:uppercase;">${this.getRelativeDate(g.date)}</div>
                    <div style="font-size:11px; color:var(--text-secondary);">${g.name}</div>
                </div>
                ${g.sets.map((s, idx) => `
                    <div class="ex-history-row">
                        <div style="font-size:13px; font-weight:600; color:var(--text-secondary);">Set ${s.set_index || (idx + 1)}</div>
                        <div style="font-size:13px; font-weight:700;">${s.weight}kg x ${s.reps}</div>
                    </div>
                `).join('')}
            </div>
        `).join('');
    },

    updateStrengthGauge(exName, oneRM) {
        const weight = this.settings.body_weight || 75;
        const ratio = oneRM / weight;
        
        // Define standards (Ratio of 1RM to Bodyweight)
        // Defaulting to Bench Press style standards for this demo
        let levels = { beg: 0.5, int: 1.0, adv: 1.5, elite: 2.0 };
        
        // Adjust for Squat/Deadlift if detected in name
        if (exName.toLowerCase().includes('squat')) levels = { beg: 0.75, int: 1.25, adv: 1.75, elite: 2.5 };
        if (exName.toLowerCase().includes('deadlift')) levels = { beg: 1.0, int: 1.5, adv: 2.25, elite: 3.0 };

        let pct = 0;
        let label = "Beginner";
        let color = "#8E8E93";

        if (ratio >= levels.elite) { pct = 100; label = "Elite"; color = "#AF52DE"; }
        else if (ratio >= levels.adv) { 
            pct = 66 + ((ratio - levels.adv) / (levels.elite - levels.adv) * 33);
            label = "Advanced"; color = "#FF9500";
        }
        else if (ratio >= levels.int) {
            pct = 33 + ((ratio - levels.int) / (levels.adv - levels.int) * 33);
            label = "Intermediate"; color = "#34C759";
        }
        else {
            pct = (ratio / levels.int) * 33;
            label = "Beginner"; color = "#007AFF";
        }

        document.getElementById('gauge-bar').style.width = pct + '%';
        document.getElementById('gauge-marker').style.left = pct + '%';
        document.getElementById('gauge-1rm').innerText = oneRM + 'kg';
        document.getElementById('gauge-level').innerText = label;
        document.getElementById('gauge-level').style.color = color;
    },

    async loadLibrary() {
        const res = await fetch('index.php?ajax=get_exercises');
        this.exercises = await res.json();
        const container = document.getElementById('library-list');
        container.innerHTML = this.exercises.map(ex => `
            <div class="card" style="display:flex; justify-content:space-between; align-items:center; cursor:pointer;" onclick="AtlasTrack.viewExerciseDetail(${ex.id}, '${ex.name.replace(/'/g, "\\'")}')">
                <div>
                    <div style="font-weight:700;">${ex.name}</div>
                    <div style="font-size:12px; color:var(--text-secondary);">${ex.muscles_primary}</div>
                </div>
                <i data-lucide="chevron-right" style="color:var(--text-secondary); width:18px;"></i>
            </div>
        `).join('');
        if (window.lucide) lucide.createIcons();
    },



    renderProgressChart(history) {
        const ctx = document.getElementById('progressChart').getContext('2d');
        if (this.chart) this.chart.destroy();

        const labels = history.map(h => new Date(h.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
        const weights = history.map(h => h.max_weight);

        // Vertical Line (Crosshair) Plugin
        const crosshairPlugin = {
            id: 'crosshair',
            afterDraw: (chart) => {
                if (chart.tooltip?._active?.length) {
                    const activePoint = chart.tooltip._active[0];
                    const { ctx } = chart;
                    const { x } = activePoint.element;
                    const topY = chart.scales.y.top;
                    const bottomY = chart.scales.y.bottom;

                    ctx.save();
                    ctx.beginPath();
                    ctx.moveTo(x, topY);
                    ctx.lineTo(x, bottomY);
                    ctx.lineWidth = 1;
                    ctx.strokeStyle = 'rgba(255, 255, 255, 0.2)';
                    ctx.setLineDash([3, 3]);
                    ctx.stroke();
                    ctx.restore();
                }
            }
        };

        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Max Weight',
                    data: weights,
                    borderColor: '#007AFF',
                    backgroundColor: 'rgba(0, 122, 255, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 0, // Hide points by default for clean look
                    pointHitRadius: 20,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#007AFF',
                    pointHoverBorderColor: '#FFFFFF',
                    pointHoverBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false, // Allows scrubbing anywhere on the X-axis
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        backgroundColor: '#1C1C1E',
                        titleColor: '#8E8E93',
                        titleFont: { size: 10, weight: 'bold' },
                        bodyColor: '#FFFFFF',
                        bodyFont: { size: 16, weight: '800' },
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: false,
                        borderColor: '#2C2C2E',
                        borderWidth: 1,
                        callbacks: {
                            label: (context) => context.parsed.y + ' kg'
                        }
                    }
                },
                scales: {
                    y: { 
                        grid: { color: '#2C2C2E', drawBorder: false }, 
                        ticks: { color: '#8E8E93', font: { size: 10 } } 
                    },
                    x: { 
                        grid: { display: false }, 
                        ticks: { color: '#8E8E93', font: { size: 10 }, maxRotation: 0 } 
                    }
                }
            },
            plugins: [crosshairPlugin]
        });
    },

    loadHistory() {
        fetch('index.php?ajax=get_history')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    if (data.recent_html) {
                        document.getElementById('recent-history-list').innerHTML = data.recent_html;
                    }
                    if (data.all_html) {
                        document.getElementById('full-history-list').innerHTML = data.all_html;
                    }
                    if (window.lucide) lucide.createIcons();
                }
            });
    },

    async viewWorkoutDetail(id) {
        this.haptic();
        const modal = document.getElementById('detail-modal');
        const body = document.getElementById('detail-body');
        body.innerHTML = '<div style="text-align:center; padding:20px;">Loading...</div>';
        modal.style.display = 'block';

        try {
            const res = await fetch(`index.php?ajax=get_workout_detail&id=${id}`);
            const sets = await res.json();
            
            // Group sets by exercise
            const groups = {};
            sets.forEach(s => {
                if (!groups[s.ex_name]) groups[s.ex_name] = [];
                groups[s.ex_name].push(s);
            });

            let html = '';
            for (const exName in groups) {
                html += `
                    <div style="margin-bottom:20px;">
                        <div style="color:var(--primary-accent); font-weight:700; margin-bottom:8px;">${exName}</div>
                        <div style="background:var(--bg-color); border-radius:10px; padding:8px;">
                            <table style="width:100%; font-size:13px; border-collapse:collapse;">
                                <tr style="color:var(--text-secondary); text-align:left;">
                                    <th style="padding:4px;">SET</th>
                                    <th style="padding:4px;">KG</th>
                                    <th style="padding:4px;">REPS</th>
                                </tr>
                                ${groups[exName].map(s => `
                                    <tr style="border-top:1px solid var(--border-color);">
                                        <td style="padding:8px 4px;">${s.set_index}</td>
                                        <td style="padding:8px 4px;">${s.weight}</td>
                                        <td style="padding:8px 4px;">${s.reps}</td>
                                    </tr>
                                `).join('')}
                            </table>
                        </div>
                    </div>
                `;
            }
            body.innerHTML = html;
        } catch (e) {
            body.innerHTML = '<div style="color:var(--danger);">Failed to load details.</div>';
        }
    },

    bindEvents() {
        // Sync notes live as user types
        const container = document.getElementById('workout-exercises');
        if (container) {
            container.addEventListener('input', (e) => {
                if (e.target.classList.contains('ex-notes')) {
                    this.syncActiveState();
                }
            });
        }
    },

    getDefaultWorkoutName() {
        const hour = new Date().getHours();
        let period = "Morning";
        if (hour >= 12 && hour < 17) period = "Afternoon";
        else if (hour >= 17 && hour < 21) period = "Evening";
        else if (hour >= 21 || hour < 5) period = "Night";
        return `${period} Workout`;
    },

    startEmptyWorkout() {
        this.haptic();
        this.state.isLogging = true;
        this.state.workoutName = this.getDefaultWorkoutName();
        this.state.isEditingRoutine = false;
        this.state.editingRoutineId = null;
        this.state.workoutStartTime = Date.now();
        
        this.updateLoggerUI();
        this.switchTab('logger');
        
        this.startDurationTimer();
        window.scrollTo(0,0);
    },

    createNewRoutine() {
        this.haptic();
        this.state.isLogging = false;
        this.state.isEditingRoutine = true;
        this.state.editingRoutineId = null;
        this.state.editingRoutineName = "";
        
        this.updateLoggerUI();
        this.switchTab('logger');
        
        // Clear exercises and show picker immediately
        document.getElementById('workout-exercises').innerHTML = "";
        this.showExercisePicker();
    },

    async editRoutine(id, name) {
        this.haptic();
        const res = await fetch(`index.php?ajax=get_routine_detail&id=${id}`);
        const exercises = await res.json();

        this.state.isLogging = false;
        this.state.isEditingRoutine = true;
        this.state.editingRoutineId = id;
        this.state.editingRoutineName = name;

        this.updateLoggerUI();
        this.switchTab('logger');

        // Populate logger with routine exercises
        const container = document.getElementById('workout-exercises');
        container.innerHTML = "";
        for (const ex of exercises) {
            await this.addExerciseToWorkout(ex.id, ex.name);
        }
    },

    updateLoggerUI() {
        const title = document.getElementById('logger-title');
        const stats = document.getElementById('logger-stats-row');
        const finishBtn = document.getElementById('btn-logger-finish');
        const saveBtn = document.getElementById('btn-logger-save-template');
        const cancelBtn = document.getElementById('btn-logger-cancel');

        if (this.state.isEditingRoutine) {
            title.innerText = this.state.editingRoutineId ? "Edit Routine" : "New Routine";
            stats.style.display = 'none';
            finishBtn.innerText = "Save Routine";
            finishBtn.onclick = () => this.saveAsRoutine();
            saveBtn.style.display = 'none';
            cancelBtn.innerText = "Discard Routine";
        } else if (this.state.isEditingHistory) {
            title.innerText = "Edit History";
            stats.style.display = 'flex';
            finishBtn.innerText = "Update Workout";
            finishBtn.onclick = () => this.finishWorkout();
            saveBtn.style.display = 'none';
            cancelBtn.innerText = "Cancel Edits";
        } else {
            title.innerText = "Log Workout";
            stats.style.display = 'flex';
            finishBtn.innerText = "Finish";
            finishBtn.onclick = () => this.finishWorkout();
            saveBtn.style.display = 'block';
            cancelBtn.innerText = "Cancel Workout";
        }
    },

    openExplore() {
        this.haptic();
        this.switchTab('explore');
        this.renderExplore();
    },

    async renderExplore() {
        const container = document.getElementById('explore-list');
        container.innerHTML = '<div style="text-align:center; padding:40px; color:var(--text-secondary);">Loading featured routines...</div>';
        
        try {
            const res = await fetch('index.php?ajax=get_explore_routines');
            const routines = await res.json();
            
            container.innerHTML = routines.map(r => `
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:8px;">
                        <h3 style="margin:0; font-size:16px;">${r.name}</h3>
                        <span style="font-size:10px; background:var(--primary-accent); color:white; padding:2px 6px; border-radius:4px; font-weight:bold;">FREE</span>
                    </div>
                    <p style="font-size:12px; color:var(--text-secondary); margin:0 0 16px 0; line-height:1.4;">${r.description}</p>
                    <div style="display:flex; gap:10px;">
                        <button class="btn-secondary" style="padding:8px; font-size:13px;" onclick="AtlasTrack.previewExploreRoutine('${r.id}', '${r.name.replace(/'/g, "\\'")}')">Preview</button>
                        <button class="btn-primary" style="padding:8px; font-size:13px;" onclick="AtlasTrack.importRoutine('${r.id}')">Add Routine</button>
                    </div>
                </div>
            `).join('');
        } catch (e) {
            container.innerHTML = '<div style="color:var(--danger);">Failed to load routines.</div>';
        }
    },

    async previewExploreRoutine(id, name) {
        this.haptic();
        const modal = document.getElementById('detail-modal');
        const body = document.getElementById('detail-body');
        const title = document.getElementById('detail-title');
        
        title.innerText = name;
        body.innerHTML = '<div style="text-align:center; padding:20px;">Loading exercises...</div>';
        modal.style.display = 'block';

        const res = await fetch(`index.php?ajax=get_routine_detail_by_key&key=${id}`);
        const exercises = await res.json();

        body.innerHTML = `
            <div style="margin-bottom:16px; font-size:13px; color:var(--text-secondary);">This routine includes:</div>
            <div style="display:flex; flex-direction:column; gap:8px;">
                ${exercises.map(ex => `
                    <div style="background:var(--bg-color); padding:12px; border-radius:10px; display:flex; align-items:center; gap:12px;">
                        <div style="width:32px; height:32px; border-radius:50%; background:var(--border-color); display:flex; align-items:center; justify-content:center;">
                            <i data-lucide="dumbbell" style="width:16px; color:var(--primary-accent);"></i>
                        </div>
                        <div onclick="document.getElementById('detail-modal').style.display='none'; AtlasTrack.viewExerciseDetail(${ex.id}, '${ex.name.replace(/'/g, "\\'")}')" style="cursor:pointer;">
                            <div style="font-weight:600; font-size:14px; color:var(--primary-accent);">${ex.name}</div>
                            <div style="font-size:11px; color:var(--text-secondary);">${ex.muscles_primary}</div>
                        </div>
                    </div>
                `).join('')}
            </div>
            <button class="btn-primary" style="margin-top:24px;" onclick="document.getElementById('detail-modal').style.display='none'; AtlasTrack.importRoutine('${id}')">Add to My Routines</button>
        `;
        if (window.lucide) lucide.createIcons();
    },

    async importRoutine(id) {
        this.haptic();
        const res = await fetch(`index.php?ajax=import_routine&id=${id}`);
        const result = await res.json();
        if (result.status === 'success') {
            alert("Routine Added to your list!");
            this.loadRoutines();
        }
    },

    openActionSheet(title, options, callback) {
        document.getElementById('action-sheet-title').innerText = title;
        const container = document.getElementById('action-sheet-options');
        container.innerHTML = '';

        options.forEach(opt => {
            const btn = document.createElement('button');
            btn.className = 'action-item';
            btn.innerHTML = opt.label;
            btn.onclick = () => {
                this.haptic();
                this.closeActionSheet();
                callback(opt.value);
            };
            container.appendChild(btn);
        });

        document.getElementById('action-sheet').style.display = 'block';
        if (window.lucide) lucide.createIcons();
    },

    closeActionSheet() {
        document.getElementById('action-sheet').style.display = 'none';
    },

    showFillMenu(e, input) {
        e.preventDefault(); // Prevent system context menu
        this.haptic();
        
        const val = input.value;
        if (!val || val === "0") {
            // Optional: show a toast that a value is needed to fill
            return;
        }

        const type = input.classList.contains('weight-input') ? 'Weight' : 'Reps';
        const unit = input.classList.contains('weight-input') ? 'kg' : 'reps';
        
        const options = [
            { label: '<i data-lucide="arrow-down" style="width:18px;"></i> Fill Down', value: 'down' },
            { label: '<i data-lucide="arrow-up" style="width:18px;"></i> Fill Up', value: 'up' },
            { label: '<i data-lucide="copy" style="width:18px;"></i> Fill All Sets', value: 'all' }
        ];

        this.openActionSheet(`Fill ${type}: ${val}${unit}`, options, (action) => {
            this.executeFill(input, action);
        });
    },

    executeFill(sourceInput, direction) {
        const val = sourceInput.value;
        const isWeight = sourceInput.classList.contains('weight-input');
        const selector = isWeight ? '.weight-input' : '.reps-input';
        const card = sourceInput.closest('.ex-card');
        const allInputs = Array.from(card.querySelectorAll(selector));
        const sourceIdx = allInputs.indexOf(sourceInput);

        allInputs.forEach((input, idx) => {
            let shouldFill = false;
            if (direction === 'all') shouldFill = true;
            else if (direction === 'down' && idx > sourceIdx) shouldFill = true;
            else if (direction === 'up' && idx < sourceIdx) shouldFill = true;

            if (shouldFill) {
                input.value = val;
                // Highlight the change briefly
                input.style.transition = 'background 0.3s';
                input.style.background = 'rgba(0, 122, 255, 0.3)';
                setTimeout(() => { input.style.background = ''; }, 500);
            }
        });

        if (isWeight) this.updateCalculatedWeights(card.id);
        this.calculateVolume();
        this.haptic();
    },

    showRoutineMenu(e, id, name) {
        e.stopPropagation();
        this.haptic();
        
        const options = [
            { label: '<i data-lucide="edit" style="width:18px;"></i> Edit Routine', value: 'edit' },
            { label: '<i data-lucide="edit-2" style="width:18px;"></i> Rename Routine', value: 'rename' },
            { label: '<i data-lucide="trash-2" style="width:18px; color:var(--danger);"></i> <span style="color:var(--danger);">Delete Routine</span>', value: 'delete' }
        ];

        this.openActionSheet(`Routine: ${name}`, options, (val) => {
            if (val === 'edit') this.editRoutine(id, name);
            if (val === 'rename') this.renameRoutine(id, name);
            if (val === 'delete') this.deleteRoutine(id, name);
        });
    },

    async renameRoutine(id, oldName) {
        const newName = prompt("Rename Routine:", oldName);
        if (!newName || newName === oldName) return;

        const res = await fetch('index.php?ajax=rename_routine', {
            method: 'POST',
            body: JSON.stringify({ id, name: newName })
        });
        const result = await res.json();
        if (result.status === 'success') {
            this.loadRoutines();
        }
    },

    async deleteRoutine(id, name) {
        if (!confirm(`Are you sure you want to delete "${name}"?`)) return;

        const res = await fetch(`index.php?ajax=delete_routine&id=${id}`);
        const result = await res.json();
        if (result.status === 'success') {
            this.loadRoutines();
        }
    },

    showExerciseMenu(e, instanceId) {
        e.stopPropagation();
        this.haptic();
        
        const card = document.getElementById(instanceId);
        const name = card.querySelector('.ex-card-title').innerText;
        const settingsRow = card.querySelector('.weight-calculator-settings');
        const isHidden = settingsRow.classList.contains('hidden');
        
        const options = [
            { label: '<i data-lucide="settings-2" style="width:18px;"></i> ' + (isHidden ? 'Show Advanced Options' : 'Hide Advanced Options'), value: 'toggle_advanced' },
            { label: '<i data-lucide="list-ordered" style="width:18px;"></i> Reorder All', value: 'reorder' },
            { label: '<i data-lucide="trash-2" style="width:18px; color:var(--danger);"></i> <span style="color:var(--danger);">Remove Exercise</span>', value: 'remove' }
        ];

        this.openActionSheet(name, options, (val) => {
            if (val === 'toggle_advanced') {
                settingsRow.classList.toggle('hidden');
                if (!settingsRow.classList.contains('hidden')) {
                    settingsRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
            if (val === 'reorder') this.openReorder();
            if (val === 'remove') this.removeExercise(instanceId);
        });
    },

    openReorder() {
        this.haptic();
        const cards = document.querySelectorAll('.ex-card');
        this.state.tempOrder = Array.from(cards).map(card => ({
            id: card.id,
            name: card.querySelector('.ex-card-title').innerText
        }));
        
        if (this.state.tempOrder.length < 2) {
            alert("Add at least two exercises to reorder.");
            return;
        }

        this.renderReorderList();
        document.getElementById('reorder-modal').style.display = 'block';
    },

    renderReorderList() {
        const container = document.getElementById('reorder-list');
        container.innerHTML = this.state.tempOrder.map((item, idx) => `
            <div style="display:flex; align-items:center; justify-content:space-between; padding:14px; background:var(--bg-color); border-radius:12px; margin-bottom:10px; border:1px solid var(--border-color);">
                <div style="font-weight:700; font-size:15px; flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; padding-right:12px;">${item.name}</div>
                <div style="display:flex; gap:6px;">
                    <button class="timer-btn" style="width:36px; height:36px; display:flex; align-items:center; justify-content:center;" onclick="AtlasTrack.moveInReorder(${idx}, -1)" ${idx === 0 ? 'disabled style="opacity:0.2;"' : ''}>
                        <i data-lucide="chevron-up" style="width:18px;"></i>
                    </button>
                    <button class="timer-btn" style="width:36px; height:36px; display:flex; align-items:center; justify-content:center;" onclick="AtlasTrack.moveInReorder(${idx}, 1)" ${idx === this.state.tempOrder.length - 1 ? 'disabled style="opacity:0.2;"' : ''}>
                        <i data-lucide="chevron-down" style="width:18px;"></i>
                    </button>
                </div>
            </div>
        `).join('');
        if (window.lucide) lucide.createIcons();
    },

    moveInReorder(idx, dir) {
        this.haptic();
        const targetIdx = idx + dir;
        if (targetIdx < 0 || targetIdx >= this.state.tempOrder.length) return;
        
        const item = this.state.tempOrder.splice(idx, 1)[0];
        this.state.tempOrder.splice(targetIdx, 0, item);
        this.renderReorderList();
    },

    saveReorder() {
        this.haptic();
        const container = document.getElementById('workout-exercises');
        this.state.tempOrder.forEach(item => {
            const card = document.getElementById(item.id);
            if (card) container.appendChild(card);
        });
        this.syncActiveState();
        this.closeReorder();
    },

    closeReorder() {
        document.getElementById('reorder-modal').style.display = 'none';
    },

    removeExercise(instanceId) {
        const card = document.getElementById(instanceId);
        const name = card.querySelector('.ex-card-title').innerText;
        
        if (confirm(`Remove ${name} from this session?`)) {
            card.remove();
            this.calculateVolume();
            this.syncActiveState();
            this.haptic();
        }
    },

    showHistoryMenu(e, id) {
        e.stopPropagation();
        this.haptic();
        
        const options = [
            { label: '<i data-lucide="edit" style="width:18px;"></i> Edit Workout', value: 'edit' },
            { label: '<i data-lucide="copy" style="width:18px;"></i> Save as Routine', value: 'copy' },
            { label: '<i data-lucide="trash-2" style="width:18px; color:var(--danger);"></i> <span style="color:var(--danger);">Delete Workout</span>', value: 'delete' }
        ];

        this.openActionSheet(`Workout History`, options, (val) => {
            if (val === 'edit') this.editWorkoutHistory(id);
            if (val === 'copy') this.saveHistoryAsRoutine(id);
            if (val === 'delete') this.deleteWorkout(id);
        });
    },

    async deleteWorkout(id) {
        if (!confirm("Delete this workout history? This cannot be undone.")) return;

        const res = await fetch(`index.php?ajax=delete_workout&id=${id}`);
        const result = await res.json();
        if (result.status === 'success') {
            this.loadHistory();
        } else {
            alert("Error: " + result.message);
        }
    },

    async saveHistoryAsRoutine(id) {
        this.haptic();
        const res = await fetch(`index.php?ajax=get_workout_full_detail&id=${id}`);
        const data = await res.json();
        
        // Extract unique exercise IDs in the order they were performed
        const exerciseIds = [];
        data.sets.forEach(s => {
            if (!exerciseIds.includes(s.exercise_id)) {
                exerciseIds.push(s.exercise_id);
            }
        });

        if (exerciseIds.length === 0) {
            alert("This workout has no exercises to save.");
            return;
        }

        const name = prompt("Enter Routine Name:", data.workout.name || "New Routine");
        if (!name) return;

        const saveRes = await fetch('index.php?ajax=save_routine', {
            method: 'POST',
            body: JSON.stringify({ name, exercises: exerciseIds })
        });
        const result = await saveRes.json();
        if (result.status === 'success') {
            alert("Routine Created!");
            this.loadRoutines();
        }
    },

    async editWorkoutHistory(id) {
        this.haptic();
        const res = await fetch(`index.php?ajax=get_workout_full_detail&id=${id}`);
        const data = await res.json();

        this.state.isLogging = true;
        this.state.workoutName = data.workout.name || "Workout";
        this.state.isEditingHistory = true;
        this.state.editingWorkoutId = id;
        this.state.workoutStartTime = new Date(data.workout.start_time).getTime();

        this.updateLoggerUI();
        this.switchTab('logger');

        const container = document.getElementById('workout-exercises');
        container.innerHTML = "";

        const groups = {};
        data.sets.forEach(s => {
            if (!groups[s.exercise_id]) groups[s.exercise_id] = { name: s.ex_name, sets: [] };
            groups[s.exercise_id].sets.push(s);
        });

        for (const exId in groups) {
            const ex = groups[exId];
            const instanceId = 'ex_' + Date.now() + exId;
            const meta = data.meta[exId] || { note: "", base_active: false, base_val: 20, single_side: false };
            
            this.injectExerciseCard(exId, ex.name, instanceId, meta, null);
            
            ex.sets.forEach(s => {
                // Use raw_weight if available, fallback to weight
                const displayWeight = s.raw_weight !== null ? s.raw_weight : s.weight;
                this.addSetRow(instanceId, null, null); 
                const row = document.getElementById(`sets_${instanceId}`).lastElementChild;
                row.querySelector('.weight-input').value = displayWeight;
                row.querySelector('.reps-input').value = s.reps;
                this.toggleSet(row.id); 
            });
            this.updateCalculatedWeights(instanceId);
        }
        this.calculateVolume();
    },

    cancelWorkout() {
        const msg = this.state.isEditingRoutine ? "Discard this routine?" : "Cancel this workout?";
        if(confirm(msg)) {
            this.clearActiveState();
            this.state.isLogging = false;
            this.state.isEditingRoutine = false;
            clearInterval(this.state.timerInterval);
            this.skipRestTimer();
            
            this.switchTab('workout');
            
            document.getElementById('live-duration').innerText = "00:00";
            document.getElementById('live-volume').innerText = "0 kg";
            document.getElementById('live-sets').innerText = "0";
            document.getElementById('workout-exercises').innerHTML = "";
        }
    },

    startDurationTimer() {
        const el = document.getElementById('live-duration');
        this.state.timerInterval = setInterval(() => {
            const diff = Math.floor((Date.now() - this.state.workoutStartTime) / 1000);
            const m = String(Math.floor(diff / 60)).padStart(2, '0');
            const s = String(diff % 60).padStart(2, '0');
            el.innerText = `${m}:${s}`;
        }, 1000);
    },

    showExercisePicker() {
        this.haptic();
        document.getElementById('exercise-modal').style.display = 'block';
        if (!this.exercises) {
            fetch('index.php?ajax=get_exercises')
                .then(res => res.json())
                .then(data => {
                    this.exercises = data;
                    this.renderExerciseList(data);
                });
        } else {
            this.renderExerciseList(this.exercises);
        }
    },

    closeExercisePicker() {
        document.getElementById('exercise-modal').style.display = 'none';
        document.getElementById('ex-search').value = '';
    },

    filterExercises(query) {
        if (!this.exercises) return;
        const q = query.toLowerCase();
        const filtered = this.exercises.filter(ex => ex.name.toLowerCase().includes(q) || ex.muscles_primary.toLowerCase().includes(q));
        this.renderExerciseList(filtered);
    },

    renderExerciseList(list) {
        const container = document.getElementById('exercise-list');
        container.innerHTML = list.map(ex => `
            <div class="ex-list-item" onclick="AtlasTrack.addExerciseToWorkout(${ex.id}, '${ex.name.replace(/'/g, "\\'")}')">
                <div style="width:40px; height:40px; border-radius:50%; background:var(--border-color); display:flex; align-items:center; justify-content:center;">
                    <i data-lucide="dumbbell" style="width:20px; color:var(--text-secondary);"></i>
                </div>
                <div>
                    <div style="font-weight:600; font-size:15px;">${ex.name}</div>
                    <div style="font-size:12px; color:var(--text-secondary);">${ex.muscles_primary}</div>
                </div>
            </div>
        `).join('');
        if (window.lucide) lucide.createIcons();
    },

    injectExerciseCard(exId, exName, instanceId, meta, prData) {
        const container = document.getElementById('workout-exercises');
        const noteDisplay = meta.note || "Add notes here...";
        
        // Only show row if at least one toggle is active
        const hasActiveOption = meta.base_active || meta.single_side || meta.is_assisted || meta.is_drop_active;
        const rowClass = hasActiveOption ? "" : "hidden";

        const html = `
            <div class="ex-card" id="${instanceId}" data-ex-id="${exId}">
                <div class="ex-card-header">
                    <div style="width:40px; height:40px; border-radius:50%; background:white; display:flex; align-items:center; justify-content:center;">
                        <i data-lucide="activity" style="color:black; width:24px;"></i>
                    </div>
                    <div class="ex-card-title" onclick="AtlasTrack.viewExerciseDetail(${exId}, '${exName.replace(/'/g, "\\'")}')" style="cursor:pointer;">${exName}</div>
                    <i data-lucide="more-vertical" style="color:var(--text-secondary); cursor:pointer;" onclick="AtlasTrack.showExerciseMenu(event, '${instanceId}')"></i>
                </div>
                <div class="ex-notes" contenteditable="true" 
                     onfocus="if(this.innerText==='Add notes here...')this.innerText=''" 
                     onblur="if(this.innerText.trim()==='')this.innerText='Add notes here...'"
                     style="outline:none; min-height:1em; cursor:text;">${noteDisplay}</div>
                
                <div class="weight-calculator-settings weight-calc-row ${rowClass}">
                    <label class="calc-pill ${meta.base_active ? 'active' : ''}">
                        <input type="checkbox" class="base-active-check" ${meta.base_active ? 'checked' : ''} onchange="AtlasTrack.updateCalculatedWeights('${instanceId}')">
                        <i data-lucide="anvil" style="width:14px;"></i>
                        <span>Base:</span>
                        <input type="number" class="base-weight-input" value="${meta.base_val || 20}" onchange="AtlasTrack.updateCalculatedWeights('${instanceId}')" onclick="event.stopPropagation()">
                        <span>kg</span>
                    </label>
                    <label class="calc-pill ${meta.single_side ? 'active' : ''}">
                        <input type="checkbox" class="single-side-check" ${meta.single_side ? 'checked' : ''} onchange="AtlasTrack.updateCalculatedWeights('${instanceId}')">
                        <i data-lucide="split" style="width:14px;"></i>
                        <span>Single Side</span>
                    </label>
                    <label class="calc-pill ${meta.is_assisted ? 'active' : ''}">
                        <input type="checkbox" class="assisted-check" ${meta.is_assisted ? 'checked' : ''} onchange="AtlasTrack.updateCalculatedWeights('${instanceId}')">
                        <i data-lucide="circle-minus" style="width:14px;"></i>
                        <span>Assisted</span>
                    </label>
                    <label class="calc-pill ${meta.is_drop_active ? 'active' : ''}">
                        <input type="checkbox" class="drop-sets-check" ${meta.is_drop_active ? 'checked' : ''} onchange="AtlasTrack.toggleDropSets('${instanceId}')">
                        <i data-lucide="layers" style="width:14px;"></i>
                        <span>Drop Sets</span>
                    </label>
                </div>

                <div style="display:flex; gap:15px; padding: 0 16px; margin-bottom: 12px;">
                    <div class="ex-rest-timer" style="padding:0; margin:0;"><i data-lucide="clock" style="width:14px;"></i> 2m 0s</div>
                    ${prData ? `
                        <div style="color:var(--warn); font-size:13px; display:flex; align-items:center; gap:4px;">
                            <i data-lucide="award" style="width:14px;"></i> 
                            Best: ${prData.weight}kg x ${prData.reps} (${this.getRelativeDate(prData.created_at)})
                        </div>
                    ` : ''}
                </div>
                
                <div class="set-table">
                    <div class="set-header-row">
                        <div style="text-align:center;">SET</div>
                        <div>PREVIOUS</div>
                        <div style="text-align:center;">KG</div>
                        <div style="text-align:center;">REPS</div>
                        <div style="text-align:center; display:flex; justify-content:center;"><i data-lucide="check" style="width:14px;"></i></div>
                    </div>
                    <div id="sets_${instanceId}"></div>
                </div>
                <button class="btn-add-set" onclick="AtlasTrack.addSetRow('${instanceId}')">+ Add Set</button>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', html);
        if (window.lucide) lucide.createIcons();
    },

    async addExerciseToWorkout(exId, exName) {
        this.haptic();
        this.closeExercisePicker();
        
        const res = await fetch(`index.php?ajax=get_last_exercise_data&id=${exId}`);
        const data = await res.json();
        
        if (data.pr) this.state.prMap[exId] = data.pr;

        const instanceId = 'ex_' + Date.now();
        // Safeguard: If history contains drop sets, force the toggle to active
        const hasDropSetsInHistory = data.sets && data.sets.some(s => s.is_drop);
        
        const meta = {
            note: data.lastNote,
            base_active: data.base_active,
            base_val: data.base_val,
            single_side: data.single_side,
            is_assisted: data.is_assisted,
            is_drop_active: data.is_drop_active || hasDropSetsInHistory
        };

        this.injectExerciseCard(exId, exName, instanceId, meta, data.pr);
        
        if (data.sets && data.sets.length > 0) {
            data.sets.forEach(s => {
                this.addSetRow(instanceId, s.weight, s.raw_weight, s.reps, data.lastDate, s.is_drop);
            });
        } else {
            this.addSetRow(instanceId);
            if (meta.is_drop_active) this.syncDropSets(instanceId);
        }
        
        // Trigger calculation to show blue hints for the pre-filled raw values
        this.updateCalculatedWeights(instanceId);
        this.syncActiveState();
    },

    getRelativeDate(dateStr) {
        if (!dateStr) return '';
        // Parse date and normalize both to midnight for calendar-day comparison
        const dateObj = new Date(dateStr.replace(' ', 'T'));
        const now = new Date();
        
        const d1 = new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate());
        const d2 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        // d1 - d2 makes past dates negative
        const diffDays = Math.round((d1 - d2) / (1000 * 60 * 60 * 24));
        
        const shortDate = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        
        if (diffDays === 0) return `${shortDate} · Today`;
        if (diffDays === -1) return `${shortDate} · Yesterday`;
        if (diffDays < -1) return `${shortDate} · ${Math.abs(diffDays)}d ago`;
        return `${shortDate} · In ${diffDays}d`; // Future safety
    },

    addSetRow(instanceId, prevTotalWeight = null, prevRawWeight = null, prevReps = null, prevDate = null, isDrop = false) {
        this.haptic();
        const container = document.getElementById(`sets_${instanceId}`);
        
        // Calculate set number based on type
        const standardCount = container.querySelectorAll('.set-row:not(.is-drop-set)').length;
        const dropCount = container.querySelectorAll('.set-row.is-drop-set').length;
        const setNum = isDrop ? (dropCount + 1) : (standardCount + 1);
        const displayNum = isDrop ? `D${setNum}` : setNum;
        
        const rowId = `${instanceId}_${isDrop ? 'drop' : 'set'}_${setNum}`;
        
        const hasPrev = prevTotalWeight !== null && prevTotalWeight !== '' && prevTotalWeight !== '-';
        // The "Previous" label always shows the Total Weight for clarity
        const prevDisplay = hasPrev ? `${prevTotalWeight}kg x ${prevReps}` : '';
        const dateDisplay = (hasPrev && prevDate) ? `<div style="font-size:9px; opacity:0.5; margin-top:2px; white-space:nowrap;">${this.getRelativeDate(prevDate)}</div>` : '';
        
        // The input box gets the Raw Weight (the "lazy" number)
        const weightVal = (prevRawWeight !== null) ? prevRawWeight : (hasPrev ? prevTotalWeight : '');
        const repsVal = (prevReps !== null) ? prevReps : '';
        
        const html = `
            <div class="set-row ${isDrop ? 'is-drop-set' : ''}" id="${rowId}" 
                 data-is-drop="${isDrop ? '1' : '0'}"
                 data-prev-w="${prevTotalWeight || ''}" 
                 data-prev-r="${prevReps || ''}" 
                 data-prev-d="${prevDate || ''}">
                <div class="set-num" onclick="AtlasTrack.showSetMenu(event, '${rowId}')" style="${isDrop ? 'color:var(--warn); border-color:var(--warn);' : ''}">${displayNum}</div>
                <div class="set-prev">${prevDisplay}${dateDisplay}</div>
                <div style="text-align:center; display:flex; align-items:center; justify-content:flex-end;">
                    <span class="total-weight-hint"></span>
                    <input type="text" inputmode="none" class="set-input weight-input" placeholder="0" value="${weightVal}" readonly onclick="AtlasTrack.openKeyboard(this)" oncontextmenu="AtlasTrack.showFillMenu(event, this); return false;">
                </div>
                <div style="text-align:center;"><input type="text" inputmode="none" class="set-input reps-input" placeholder="0" value="${repsVal}" readonly onclick="AtlasTrack.openKeyboard(this)" oncontextmenu="AtlasTrack.showFillMenu(event, this); return false;"></div>
                <div>
                    <button class="set-check" onclick="AtlasTrack.toggleSet('${rowId}')">
                        <i data-lucide="check" style="width:18px;"></i>
                    </button>
                </div>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', html);
        if (window.lucide) lucide.createIcons();
        this.updateCalculatedWeights(instanceId);
        this.syncActiveState();
    },

    updateCalculatedWeights(instanceId) {
        const card = document.getElementById(instanceId);
        if (!card) return;

        const baseActive = card.querySelector('.base-active-check').checked;
        const baseVal = parseFloat(card.querySelector('.base-weight-input').value) || 0;
        const singleSide = card.querySelector('.single-side-check').checked;
        const isAssisted = card.querySelector('.assisted-check').checked;
        const bodyWeight = this.settings.body_weight || 75;

        // Update UI pills
        card.querySelector('.base-active-check').closest('.calc-pill').classList.toggle('active', baseActive);
        card.querySelector('.single-side-check').closest('.calc-pill').classList.toggle('active', singleSide);
        card.querySelector('.assisted-check').closest('.calc-pill').classList.toggle('active', isAssisted);

        card.querySelectorAll('.set-row').forEach(row => {
            const rawWeight = parseFloat(row.querySelector('.weight-input').value) || 0;
            let total = rawWeight;
            
            if (isAssisted) {
                total = Math.max(0, bodyWeight - rawWeight);
            } else {
                if (singleSide) total *= 2;
                if (baseActive) total += baseVal;
            }

            const hint = row.querySelector('.total-weight-hint');
            const isModified = singleSide || baseActive || isAssisted;
            
            if (isModified && rawWeight > 0) {
                hint.innerText = total + 'kg';
                hint.style.display = 'inline-block';
            } else {
                hint.style.display = 'none';
            }
            // Store total in dataset for volume calculation
            row.dataset.totalWeight = total;
        });

        this.calculateVolume();
        this.syncActiveState();
    },

    toggleSet(rowId) {
        this.closeKeyboard();
        AtlasTrack.haptic();
        const row = document.getElementById(rowId);
        if (!row) return;

        const isCompleted = row.classList.toggle('completed');
        
        const checkIcon = row.querySelector('.set-check i');
        if (checkIcon) {
            checkIcon.style.color = isCompleted ? "white" : "var(--text-secondary)";
        }

        // PR Detection Logic
        const numCell = row.querySelector('.set-num');
        // Remove existing badge if any
        const oldBadge = numCell.querySelector('.pr-badge-hit');
        if (oldBadge) oldBadge.remove();

        if (isCompleted) {
            const exCard = row.closest('.ex-card');
            const exId = exCard.dataset.exId;
            const weight = parseFloat(row.querySelector('.weight-input').value) || 0;
            const reps = parseInt(row.querySelector('.reps-input').value) || 0;
            const pr = this.state.prMap[exId];

            let isNewPR = false;
            if (!pr) {
                if (weight > 0) isNewPR = true; // First time is always a PR
            } else {
                if (weight > pr.weight) isNewPR = true;
                else if (weight === pr.weight && reps > pr.reps) isNewPR = true;
            }

            if (isNewPR) {
                const badge = document.createElement('div');
                badge.className = 'pr-badge-hit';
                badge.innerText = 'PR';
                numCell.appendChild(badge);
                if (!this.state.isHydrating) this.haptic(); // Extra haptic for PR
            }

            const isDropActive = exCard.querySelector('.drop-sets-check').checked;
            const isDropRow = row.classList.contains('is-drop-set');

            // Only start timer if: 
            // 1. Drop sets are NOT active
            // 2. Drop sets ARE active AND this IS the drop set (rest after the pair)
            if (!isDropActive || isDropRow) {
                if (!this.state.isHydrating) AtlasTrack.startRestTimer(120);
            }
        }

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                AtlasTrack.calculateVolume();
                this.syncActiveState();
            });
        });
    },

    calculateVolume() {
        let totalVolume = 0;
        let totalSets = 0;
        
        const completedRows = document.querySelectorAll('.set-row.completed');
        completedRows.forEach(row => {
            const weight = parseFloat(row.dataset.totalWeight) || parseFloat(row.querySelector('.weight-input').value) || 0;
            const reps = parseInt(row.querySelector('.reps-input').value, 10) || 0;
            
            totalVolume += (weight * reps);
            totalSets += 1;
        });
        
        // Format to prevent crazy decimals, e.g., 120.5
        document.getElementById('live-volume').innerText = Number(totalVolume.toFixed(2)) + ' kg';
        document.getElementById('live-sets').innerText = totalSets;
    },

    startRestTimer(seconds) {
        this.haptic();
        this.state.restTimerTarget = Date.now() + (seconds * 1000);
        this.startRestTimerLogic();
        this.syncActiveState(); // Immediate sync for timer start
    },

    startRestTimerLogic() {
        clearInterval(this.state.restTimerInterval);
        const bar = document.getElementById('rest-timer-bar');
        bar.classList.add('active');
        document.body.classList.add('timer-active');
        
        this.state.restTimerInterval = setInterval(() => {
            const now = Date.now();
            const diffMs = this.state.restTimerTarget - now;
            const isOvertime = diffMs <= 0;
            
            const totalSec = Math.abs(Math.floor(diffMs / 1000));
            const m = String(Math.floor(totalSec / 60)).padStart(2, '0');
            const s = String(totalSec % 60).padStart(2, '0');
            
            const display = document.getElementById('rest-timer-display');
            display.innerText = (isOvertime ? '+' : '') + `${m}:${s}`;
            
            if (isOvertime) {
                if (!bar.classList.contains('overtime')) {
                    bar.classList.add('overtime');
                    this.haptic();
                    this.playTimerChime();
                    setTimeout(() => this.haptic(), 300); // Double pulse on zero
                }
            } else {
                bar.classList.remove('overtime');
            }
        }, 1000);
    },

    updateRestTimerUI() {
        // Legacy helper - logic moved to interval for real-time target diff
    },

    adjustRestTimer(seconds) {
        this.haptic();
        if (this.state.restTimerTarget) {
            this.state.restTimerTarget += (seconds * 1000);
            this.syncActiveState();
        }
    },

    skipRestTimer() {
        clearInterval(this.state.restTimerInterval);
        document.getElementById('rest-timer-bar').classList.remove('active');
        document.body.classList.remove('timer-active');
    },

    async finishWorkout() {
        const completedRows = document.querySelectorAll('.set-row.completed');
        if (completedRows.length === 0) {
            alert("Please complete at least one set before finishing.");
            return;
        }

        this.haptic();
        const endTime = Date.now();
        const workoutData = {
            id: this.state.editingWorkoutId || null,
            name: this.state.workoutName,
            startTime: this.state.workoutStartTime,
            endTime: endTime,
            totalVolume: parseFloat(document.getElementById('live-volume').innerText),
            totalSets: parseInt(document.getElementById('live-sets').innerText),
            sets: [],
            exerciseMeta: {}
        };

        document.querySelectorAll('.ex-card').forEach(card => {
            const exId = card.dataset.exId;
            
            workoutData.exerciseMeta[exId] = {
                note: card.querySelector('.ex-notes').innerText.trim(),
                base_active: card.querySelector('.base-active-check').checked,
                base_val: parseFloat(card.querySelector('.base-weight-input').value) || 0,
                single_side: card.querySelector('.single-side-check').checked,
                is_assisted: card.querySelector('.assisted-check').checked
            };

            card.querySelectorAll('.set-row.completed').forEach((row, idx) => {
                const isDrop = row.classList.contains('is-drop-set');
                workoutData.sets.push({
                    exId: exId,
                    weight: parseFloat(row.dataset.totalWeight) || parseFloat(row.querySelector('.weight-input').value) || 0,
                    raw_weight: parseFloat(row.querySelector('.weight-input').value) || 0,
                    reps: parseInt(row.querySelector('.reps-input').value) || 0,
                    index: idx + 1,
                    isDrop: isDrop
                });
            });
        });

        try {
            const response = await fetch('index.php?ajax=save_workout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(workoutData)
            });
            
            const text = await response.text();
            let result;
            try {
                result = JSON.parse(text);
            } catch(e) {
                console.error("Server returned non-JSON:", text);
                throw new Error("Invalid server response");
            }

            if (result.status === 'success') {
                alert("Workout Saved!");
                this.clearActiveState();
                this.resetLoggerUI();
                this.loadHistory(); // Refresh the home list
            } else {
                alert("Error saving workout: " + (result.message || "Unknown error"));
            }
        } catch (e) {
            console.error("Finish Workout Error:", e);
            alert("Save failed: " + e.message);
        }
    },

    openExerciseEditor() {
        this.haptic();
        document.getElementById('editor-modal').style.display = 'block';
        document.getElementById('edit-ex-name').value = '';
        document.getElementById('edit-ex-primary').value = '';
        document.getElementById('edit-ex-secondary').value = '';
    },

    closeExerciseEditor() {
        document.getElementById('editor-modal').style.display = 'none';
    },

    async saveExercise() {
        this.haptic();
        const data = {
            name: document.getElementById('edit-ex-name').value,
            category: document.getElementById('edit-ex-category').value,
            muscles_primary: document.getElementById('edit-ex-primary').value,
            muscles_secondary: document.getElementById('edit-ex-secondary').value
        };

        if (!data.name.trim()) {
            alert("Please enter an exercise name.");
            return;
        }

        const res = await fetch('index.php?ajax=save_exercise', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.status === 'success') {
            this.closeExerciseEditor();
            this.loadLibrary();
        } else {
            alert("Error: " + result.message);
        }
    },

    resetLoggerUI() {
        this.state.isLogging = false;
        this.state.isEditingRoutine = false;
        this.state.isEditingHistory = false;
        this.state.editingWorkoutId = null;
        this.state.editingRoutineId = null;
        
        clearInterval(this.state.timerInterval);
        this.skipRestTimer();
        
        this.switchTab('workout');
        
        document.getElementById('live-duration').innerText = "00:00";
        document.getElementById('live-volume').innerText = "0 kg";
        document.getElementById('live-sets').innerText = "0";
        document.getElementById('workout-exercises').innerHTML = "";
        window.scrollTo(0,0);
    },

    toggleDropSets(instanceId) {
        this.haptic();
        const card = document.getElementById(instanceId);
        const isChecked = card.querySelector('.drop-sets-check').checked;
        card.querySelector('.drop-sets-check').closest('.calc-pill').classList.toggle('active', isChecked);
        this.syncDropSets(instanceId);
    },

    showSetMenu(e, rowId) {
        e.stopPropagation();
        this.haptic();
        const row = document.getElementById(rowId);
        const isDrop = row.classList.contains('is-drop-set');
        const num = row.querySelector('.set-num').innerText;

        const options = [
            { label: `<i data-lucide="trash-2" style="width:18px; color:var(--danger);"></i> <span style="color:var(--danger);">Delete Set ${num}</span>`, value: 'delete' }
        ];

        this.openActionSheet(`Set ${num}`, options, (val) => {
            if (val === 'delete') this.deleteSet(rowId);
        });
    },

    deleteSet(rowId) {
        const row = document.getElementById(rowId);
        if (!row) return;
        
        const container = row.parentElement;
        const instanceId = container.id.replace('sets_', '');
        const isDrop = row.classList.contains('is-drop-set');
        const card = document.getElementById(instanceId);
        const isDropActive = card.querySelector('.drop-sets-check').checked;

        // If deleting a standard set and drop sets are active, delete the corresponding drop set too
        if (!isDrop && isDropActive) {
            const standardRows = Array.from(container.querySelectorAll('.set-row:not(.is-drop-set)'));
            const idx = standardRows.indexOf(row);
            const dropRows = container.querySelectorAll('.set-row.is-drop-set');
            if (dropRows[idx]) dropRows[idx].remove();
        }

        row.remove();
        this.reindexSets(instanceId);
        this.calculateVolume();
        this.syncActiveState();
        this.haptic();
    },

    reindexSets(instanceId) {
        const container = document.getElementById(`sets_${instanceId}`);
        
        // Re-index standard sets
        const standardRows = container.querySelectorAll('.set-row:not(.is-drop-set)');
        standardRows.forEach((row, i) => {
            const newNum = i + 1;
            row.querySelector('.set-num').innerText = newNum;
            row.id = `${instanceId}_set_${newNum}`;
            // Update the menu trigger with the new ID
            row.querySelector('.set-num').setAttribute('onclick', `AtlasTrack.showSetMenu(event, '${row.id}')`);
        });

        // Re-index drop sets
        const dropRows = container.querySelectorAll('.set-row.is-drop-set');
        dropRows.forEach((row, i) => {
            const newNum = i + 1;
            row.querySelector('.set-num').innerText = `D${newNum}`;
            row.id = `${instanceId}_drop_${newNum}`;
            row.querySelector('.set-num').setAttribute('onclick', `AtlasTrack.showSetMenu(event, '${row.id}')`);
        });
    },

    syncDropSets(instanceId) {
        const card = document.getElementById(instanceId);
        const isDropActive = card.querySelector('.drop-sets-check').checked;
        const setsContainer = document.getElementById(`sets_${instanceId}`);
        
        const standardRows = setsContainer.querySelectorAll('.set-row:not(.is-drop-set)');
        const dropRows = setsContainer.querySelectorAll('.set-row.is-drop-set');
        
        if (!isDropActive) {
            dropRows.forEach(r => r.remove());
            this.calculateVolume();
            this.syncActiveState();
            return;
        }
        
        const n = standardRows.length;
        const m = dropRows.length;
        
        if (n > m) {
            // Add missing drop sets to the bottom
            for (let i = m + 1; i <= n; i++) {
                this.addSetRow(instanceId, null, null, null, null, true);
            }
        } else if (n < m) {
            // Remove extra drop sets from the bottom
            for (let i = m; i > n; i--) {
                dropRows[i-1].remove();
            }
        }
        this.calculateVolume();
        this.syncActiveState();
    },

    playTimerChime() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const vol = this.settings.timer_volume ?? 0.5;
            const repeats = this.settings.timer_repeats ?? 1;
            const interval = this.settings.timer_interval ?? 5;

            const playTripleBeep = (blockStartTime) => {
                const playBeep = (startTime) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(880, startTime);
                    gain.gain.setValueAtTime(0, startTime);
                    gain.gain.linearRampToValueAtTime(vol * 0.4, startTime + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.01, startTime + 0.2);
                    osc.start(startTime);
                    osc.stop(startTime + 0.25);
                };
                playBeep(blockStartTime);
                playBeep(blockStartTime + 0.3);
                playBeep(blockStartTime + 0.6);
            };

            for (let i = 0; i < repeats; i++) {
                playTripleBeep(ctx.currentTime + (i * interval));
            }
        } catch(e) {
            console.error("Audio chime failed", e);
        }
    },

    startSettingsLongPress() {
        this._settingsTimer = setTimeout(() => {
            this.haptic();
            location.reload();
        }, 800);
    },

    clearSettingsLongPress() {
        clearTimeout(this._settingsTimer);
    },

    haptic() {
        if (window.navigator && window.navigator.vibrate) {
            window.navigator.vibrate(10);
        }
    }
};

document.addEventListener('DOMContentLoaded', () => AtlasTrack.init());