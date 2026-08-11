/**
 * WHITEBOARD TOUCH LAB
 * Diagnostic tools for palm rejection and pointer events.
 */

// --- TOUCH LAB DIAGNOSTICS ---
let isRecordingTouches = false;
let currentTouchLog = [];
let savedTouchLogs = JSON.parse(localStorage.getItem('wb_touch_logs') || '[]');

window.wbOpenTouchLab = function() {
    document.getElementById('touch-lab-overlay').style.display = 'flex';
    wbRenderTouchLogs();
};

window.wbToggleTouchRecording = async function() {
    if (!isRecordingTouches) {
        // Start
        isRecordingTouches = true;
        currentTouchLog = [];
        document.getElementById('touch-lab-overlay').style.display = 'none';
        document.getElementById('wb-rec-indicator').style.display = 'flex';
        if (window.navigator.vibrate) navigator.vibrate([30, 30]);
    } else {
        // Stop
        isRecordingTouches = false;
        document.getElementById('wb-rec-indicator').style.display = 'none';
        if (window.navigator.vibrate) navigator.vibrate(50);

        if (currentTouchLog.length > 0) {
            const name = await window.wbui.input("Describe what happened (e.g., 'Palm Jump')", "Save Touch Log", "Log " + (savedTouchLogs.length + 1), "💾");
            if (name) {
                savedTouchLogs.unshift({
                    id: Date.now(),
                    name: name,
                    timestamp: new Date().toISOString(),
                    data: currentTouchLog
                });
                localStorage.setItem('wb_touch_logs', JSON.stringify(savedTouchLogs.slice(0, 10))); // Keep last 10
                wbOpenTouchLab();
            }
        }
    }
};

function wbRenderTouchLogs() {
    const list = document.getElementById('touch-lab-list');
    const recBtn = document.getElementById('wb-start-rec-btn');
    const copyAllBtn = document.getElementById('wb-copy-all-logs-btn');
    
    recBtn.innerText = isRecordingTouches ? "■ Stop Recording" : "● Start Recording";
    recBtn.style.background = isRecordingTouches ? "var(--text-primary)" : "#ff3b30";

    if (copyAllBtn) copyAllBtn.style.display = (savedTouchLogs.length > 0) ? 'block' : 'none';

    if (savedTouchLogs.length === 0) {
        list.innerHTML = '<div style="text-align:center; padding:20px; opacity:0.5;">No logs captured yet.</div>';
        return;
    }

    list.innerHTML = '';
    savedTouchLogs.forEach(log => {
        const item = document.createElement('div');
        item.className = 'touch-log-item';
        item.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                <div>
                    <div style="font-weight:800; font-size:14px;">${log.name}</div>
                    <div style="font-size:10px; opacity:0.6;">${new Date(log.id).toLocaleString()} • ${log.data.length} events</div>
                </div>
                <button class="tool-btn" onclick="wbDeleteTouchLog(${log.id})" style="padding:4px; color:#ff3b30; border:none; background:none;"><i data-lucide="trash-2" style="width:16px;"></i></button>
            </div>
            <button class="tool-btn" onclick="wbCopyTouchLog(${log.id})" style="width:100%; background:var(--primary-accent); color:white; border:none; font-size:11px; font-weight:800;">
                COPY DATA FOR AI ANALYSIS
            </button>
        `;
        list.appendChild(item);
    });
    if (window.lucide) lucide.createIcons();
}

window.wbCopyTouchLog = function(id) {
    const log = savedTouchLogs.find(l => l.id === id);
    if (log) {
        const json = JSON.stringify(log, null, 2);
        wbCopyText("TOUCH DIAGNOSTIC DATA:\n```json\n" + json + "\n```");
        window.sui?.toast?.("Log Copied to Clipboard");
    }
};

window.wbCopyAllTouchLogs = function() {
    if (savedTouchLogs.length === 0) return;
    const payload = {
        system: "Conjure Whiteboard Touch Lab",
        exportDate: new Date().toISOString(),
        logCount: savedTouchLogs.length,
        logs: savedTouchLogs
    };
    const json = JSON.stringify(payload, null, 2);
    wbCopyText("FULL TOUCH DIAGNOSTIC BUNDLE:\n```json\n" + json + "\n```");
    window.sui?.toast?.("All Logs Copied to Clipboard");
};

window.wbDeleteTouchLog = function(id) {
    savedTouchLogs = savedTouchLogs.filter(l => l.id !== id);
    localStorage.setItem('wb_touch_logs', JSON.stringify(savedTouchLogs));
    wbRenderTouchLogs();
};

function wbLogPointerEvent(e, type) {
    if (!isRecordingTouches) return;
    const vp = getActiveViewport();
    currentTouchLog.push({
        t: Date.now(),
        type: type,
        id: e.pointerId,
        pType: e.pointerType,
        x: Math.round(e.clientX),
        y: Math.round(e.clientY),
        w: Math.round(e.width || 0),
        h: Math.round(e.height || 0),
        press: parseFloat(e.pressure.toFixed(3)),
        tx: e.tiltX,
        ty: e.tiltY,
        btns: e.buttons,
        view: {
            scale: parseFloat(vp.transform.scale.toFixed(4)),
            x: Math.round(vp.transform.x),
            y: Math.round(vp.transform.y)
        }
    });
    // Safety cap: 5000 events per log
    if (currentTouchLog.length > 5000) wbToggleTouchRecording();
}