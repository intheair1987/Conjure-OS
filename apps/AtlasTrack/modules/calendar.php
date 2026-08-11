<?php
/**
 * AtlasTrack Calendar Module
 * Displays workout frequency and routine names in a monthly grid.
 */
function atlas_render_calendar_shell() {
    ?>
    <div class="card calendar-card" style="padding: 12px; margin-bottom: 20px;">
        <div class="calendar-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <h3 id="cal-month-year" style="margin:0; font-size:16px; font-weight:700;">Month Year</h3>
                <button class="timer-btn" id="cal-today-btn" onclick="AtlasTrack.goToToday()" style="font-size:10px; padding:2px 8px; height:20px; display:none; background:rgba(0,122,255,0.1); color:var(--primary-accent); border:1px solid rgba(0,122,255,0.2);">TODAY</button>
            </div>
            <div style="display:flex; gap:8px;">
                <button class="timer-btn" onclick="AtlasTrack.changeMonth(-1)" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">
                    <i data-lucide="chevron-left" style="width:18px;"></i>
                </button>
                <button class="timer-btn" onclick="AtlasTrack.changeMonth(1)" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">
                    <i data-lucide="chevron-right" style="width:18px;"></i>
                </button>
            </div>
        </div>
        <div class="calendar-days-header">
            <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
        </div>
        <div id="calendar-grid" class="calendar-grid"></div>
    </div>

    <script>
    Object.assign(AtlasTrack, {
        calDate: new Date(),
        workoutDays: {},

        async fetchCalendarData() {
            // Initial render to show correct month/year while loading
            this.renderCalendar();
            
            try {
                const year = this.calDate.getFullYear();
                const month = this.calDate.getMonth() + 1;
                const res = await fetch(`index.php?ajax=get_calendar_data&year=${year}&month=${month}`);
                this.workoutDays = await res.json();
                this.renderCalendar();
            } catch(e) {
                console.error("Calendar fetch failed", e);
            }
        },

        changeMonth(dir) {
            this.haptic();
            this.calDate.setMonth(this.calDate.getMonth() + dir);
            this.fetchCalendarData();
        },

        goToToday() {
            this.haptic();
            this.calDate = new Date();
            this.fetchCalendarData();
        },

        handleDayClick(dateKey) {
            const workouts = this.workoutDays[dateKey];
            if (!workouts || workouts.length === 0) return;

            this.haptic();
            if (workouts.length === 1) {
                this.viewWorkoutDetail(workouts[0].id);
            } else {
                const options = workouts.map(w => ({
                    label: `<i data-lucide="activity" style="width:18px;"></i> ${w.name}`,
                    value: w.id
                }));
                this.openActionSheet(`${dateKey} Workouts`, options, (id) => {
                    this.viewWorkoutDetail(id);
                });
            }
        },

        renderCalendar() {
            const grid = document.getElementById('calendar-grid');
            const label = document.getElementById('cal-month-year');
            if (!grid || !label) return;

            grid.innerHTML = '';
            const year = this.calDate.getFullYear();
            const month = this.calDate.getMonth();
            
            label.innerText = this.calDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            
            // Show/Hide Today button
            const isCurrentMonth = new Date().getMonth() === month && new Date().getFullYear() === year;
            document.getElementById('cal-today-btn').style.display = isCurrentMonth ? 'none' : 'block';

            // Monday Start Logic
            const firstDay = new Date(year, month, 1).getDay();
            const offset = (firstDay === 0 ? 6 : firstDay - 1);
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            // Padding
            for (let i = 0; i < offset; i++) {
                grid.insertAdjacentHTML('beforeend', '<div class="cal-day empty"></div>');
            }

            // Days
            for (let d = 1; d <= daysInMonth; d++) {
                const dateKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const hasWorkout = this.workoutDays[dateKey];
                const isToday = new Date().toDateString() === new Date(year, month, d).toDateString();
                
                let routineDots = '';
                if (hasWorkout) {
                    routineDots = hasWorkout.map(w => `<div class="cal-dot" title="${w.name}"></div>`).join('');
                }

                const html = `
                    <div class="cal-day ${hasWorkout ? 'has-workout' : ''} ${isToday ? 'is-today' : ''}" 
                         onclick="AtlasTrack.handleDayClick('${dateKey}')"
                         style="${hasWorkout ? 'cursor:pointer;' : ''}">
                        <span class="cal-num">${d}</span>
                        <div class="cal-dots-wrap">${routineDots}</div>
                    </div>
                `;
                grid.insertAdjacentHTML('beforeend', html);
            }
            if (window.lucide) lucide.createIcons();
        }
    });
    </script>
    <?php
}