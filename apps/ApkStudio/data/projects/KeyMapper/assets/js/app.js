// KeyMapper Client State Manager
let mappings = [];
let isRecordingKey = false;
let capturedKey = null;
let currentConditions = [];
let currentActions = [];
let editingRuleId = null;
let editingActionIndex = null;
let currentTriggerSource = 'button';
let currentSortOrder = localStorage.getItem('keymapper_sort') || 'newest';

function selectSortOption(val, labelText) {
    currentSortOrder = val;
    localStorage.setItem('keymapper_sort', val);
    if (window.Android && typeof window.Android.saveSortOrder === 'function') {
        window.Android.saveSortOrder(val);
    }
    applySortOrderUI(val);
    const wrapper = document.getElementById('cs-sort-order');
    if (wrapper) wrapper.classList.remove('open');
    renderMappings();
}

function getSortedMappings() {
    const list = [...mappings];
    list.sort((a, b) => {
        if (currentSortOrder === 'alpha_asc') {
            return (a.name || '').localeCompare(b.name || '');
        } else if (currentSortOrder === 'alpha_desc') {
            return (b.name || '').localeCompare(a.name || '');
        } else if (currentSortOrder === 'oldest') {
            const timeA = a.updatedAt ? new Date(a.updatedAt).getTime() : 0;
            const timeB = b.updatedAt ? new Date(b.updatedAt).getTime() : 0;
            return timeA - timeB;
        } else { // 'newest'
            const timeA = a.updatedAt ? new Date(a.updatedAt).getTime() : 0;
            const timeB = b.updatedAt ? new Date(b.updatedAt).getTime() : 0;
            return timeB - timeA;
        }
    });
    return list;
}

function updateGroupDatalist() {
    const datalist = document.getElementById('group-suggestions');
    if (!datalist) return;
    const groupsSet = new Set(['General', 'Recording', 'Flashlight', 'Gestures']);
    mappings.forEach(m => {
        if (m.group && m.group.trim()) groupsSet.add(m.group.trim());
    });
    datalist.innerHTML = Array.from(groupsSet).map(g => `<option value="${escapeHtml(g)}">`).join('');
}

let pillLongPressTimer = null;
let isPillLongPress = false;

function startPillLongPress(index) {
    isPillLongPress = false;
    pillLongPressTimer = setTimeout(() => {
        isPillLongPress = true;
        if (navigator.vibrate) navigator.vibrate(40);
        if (window.Android && typeof window.Android.vibrate === 'function') window.Android.vibrate(40);
        editActionSettings(index);
    }, 400);
}

function cancelPillLongPress() {
    if (pillLongPressTimer) {
        clearTimeout(pillLongPressTimer);
        pillLongPressTimer = null;
    }
}

function editActionSettings(index) {
    const a = currentActions[index];
    if (!a) return;
    if (a.type === 'wait') {
        editWaitAction(index);
    } else if (a.type === 'start_recording') {
        editRecordingAction(index);
    }
}

function switchTriggerSource(source) {
    currentTriggerSource = source;
    const btnButton = document.getElementById('tab-trigger-button');
    const btnState = document.getElementById('tab-trigger-state');
    const paneButton = document.getElementById('pane-trigger-button');
    const paneState = document.getElementById('pane-trigger-state');

    if (source === 'button') {
        if (btnButton) btnButton.className = 'active';
        if (btnState) btnState.className = '';
        if (paneButton) paneButton.style.display = 'block';
        if (paneState) paneState.style.display = 'none';
    } else {
        if (btnState) btnState.className = 'active';
        if (btnButton) btnButton.className = '';
        if (paneState) paneState.style.display = 'block';
        if (paneButton) paneButton.style.display = 'none';
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/_/g, " ")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function showToast(msg) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add('active');
    setTimeout(() => toast.classList.remove('active'), 2500);
}

// Custom Select Component Helpers
function toggleCustomSelect(wrapperId) {
    const activeWrappers = document.querySelectorAll('.custom-select-wrapper.open');
    activeWrappers.forEach(w => {
        if (w.id !== wrapperId) w.classList.remove('open');
    });
    const wrapper = document.getElementById(wrapperId);
    if (wrapper) {
        wrapper.classList.toggle('open');
    }
}

function selectCustomOption(wrapperId, inputId, labelId, val, labelText) {
    const input = document.getElementById(inputId);
    const label = document.getElementById(labelId);
    const wrapper = document.getElementById(wrapperId);

    if (input) input.value = val;
    if (label) label.textContent = labelText;

    if (wrapper) {
        wrapper.querySelectorAll('.custom-select-option').forEach(opt => {
            opt.classList.toggle('selected', opt.getAttribute('data-val') === String(val));
        });
        wrapper.classList.remove('open');
    }

    if (inputId === 'sel-recording-format' || inputId === 'sel-recording-bitrate') {
        updateRecordingSizeEstimate();
    }
}

function setCustomSelectValue(wrapperId, inputId, labelId, val, textMap) {
    const input = document.getElementById(inputId);
    const label = document.getElementById(labelId);
    const wrapper = document.getElementById(wrapperId);

    if (input) input.value = val;
    if (label && textMap && textMap[val]) label.textContent = textMap[val];

    if (wrapper) {
        wrapper.querySelectorAll('.custom-select-option').forEach(opt => {
            opt.classList.toggle('selected', opt.getAttribute('data-val') === String(val));
        });
    }
}

document.addEventListener('click', (e) => {
    if (!e.target.closest('.custom-select-wrapper')) {
        document.querySelectorAll('.custom-select-wrapper.open').forEach(w => w.classList.remove('open'));
    }
});

function openSettingsModal() {
    requestAccessibilityPermission();
}

const sortLabels = {
    'newest': '🆕 Newest',
    'oldest': '⏳ Oldest',
    'alpha_asc': '🔤 A-Z',
    'alpha_desc': '🔤 Z-A'
};

function applySortOrderUI(sortVal) {
    currentSortOrder = sortVal || 'newest';
    setCustomSelectValue('cs-sort-order', null, 'lbl-sort-order', currentSortOrder, sortLabels);
}

// Native Bridge Synchronization
function loadMappings() {
    let saved = [];
    let savedSort = 'newest';

    if (window.Android && typeof window.Android.getMappingsJson === 'function') {
        try {
            saved = JSON.parse(window.Android.getMappingsJson());
            if (typeof window.Android.getSortOrder === 'function') {
                savedSort = window.Android.getSortOrder();
            }
        } catch (e) { console.error(e); }
    } else {
        try {
            saved = JSON.parse(localStorage.getItem('keymapper_rules') || '[]');
            savedSort = localStorage.getItem('keymapper_sort') || 'newest';
        } catch (e) { saved = []; }
    }

    mappings = Array.isArray(saved) ? saved : [];
    applySortOrderUI(savedSort);
    renderMappings();
    checkAccessibilityStatus();
}

function persistMappings() {
    const jsonStr = JSON.stringify(mappings);
    localStorage.setItem('keymapper_rules', jsonStr);
    if (window.Android && typeof window.Android.saveMappingsJson === 'function') {
        window.Android.saveMappingsJson(jsonStr);
    }
}

function checkAccessibilityStatus() {
    const banner = document.getElementById('service-banner');
    const title = document.getElementById('status-title');
    const sub = document.getElementById('status-sub');
    if (!banner || !title || !sub) return;
    
    let isServiceActive = false;
    if (window.Android && typeof window.Android.isAccessibilityEnabled === 'function') {
        try {
            isServiceActive = window.Android.isAccessibilityEnabled();
        } catch (e) { console.error(e); }
    }

    if (isServiceActive) {
        banner.className = 'status-banner banner-enabled';
        title.textContent = 'Accessibility Listener Active';
        sub.textContent = 'Hardware button presses are being intercepted system-wide';
    } else {
        banner.className = 'status-banner banner-disabled';
        title.textContent = 'Accessibility Listener Inactive';
        sub.textContent = 'Tap to grant Accessibility permission in Android Settings';
    }
}

function requestAccessibilityPermission() {
    if (window.Android && typeof window.Android.openAccessibilitySettings === 'function') {
        window.Android.openAccessibilitySettings();
    } else {
        showToast("Open Android Settings > Accessibility > KeyMapper to enable hardware listener.");
    }
}

function renderMappingCardHtml(m) {
    const condPills = (m.conditions || []).map(c => `<span class="pill-item">${escapeHtml(c.label)}</span>`).join('');
    const actPills = (m.actions || []).map(a => `<span class="pill-item action-pill">${escapeHtml(a.label)}</span>`).join('');

    let badgeHtml = '';
    if (m.triggerType === 'state') {
        const stateLabels = {
            'flashlight_on': '🔦 Flashlight Turned ON',
            'flashlight_off': '💡 Flashlight Turned OFF',
            'screen_off': '📱 Screen Turned OFF',
            'screen_on': '🔓 Screen Turned ON',
            'screen_folded': '📖 Device Screen Folded',
            'ringer_silent': '🔇 Ringer Mode: Silent',
            'ringer_vibrate': '📳 Ringer Mode: Vibrate',
            'ringer_normal': '🔔 Ringer Mode: Normal'
        };
        badgeHtml = `<span class="key-badge" style="background:rgba(52, 211, 153, 0.15); border-color:rgba(52, 211, 153, 0.3); color:var(--success);">⚡ State: ${stateLabels[m.stateEvent] || m.stateEvent}</span>`;
    } else {
        let keyDisplay = escapeHtml(m.keyLabel);
        if (m.secondaryKeyLabel) {
            keyDisplay += ` + ${escapeHtml(m.secondaryKeyLabel)}`;
        }
        badgeHtml = `<span class="key-badge">⌨️ ${keyDisplay} (${escapeHtml(m.pressType)})</span>`;
    }

    return `
        <div class="mapping-card">
            <div class="card-header-row">
                <div class="card-title">${escapeHtml(m.name)}</div>
                <label class="switch">
                    <input type="checkbox" ${m.enabled ? 'checked' : ''} onchange="toggleMapping('${m.id}')">
                    <span class="switch-slider"></span>
                </label>
            </div>
            <div>
                ${badgeHtml}
            </div>
            <div class="pill-cloud">
                ${condPills || '<span class="pill-item">Always Active</span>'}
            </div>
            <div class="pill-cloud">
                ${actPills}
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px;">
                <button class="btn-small-add" style="background:rgba(255,255,255,0.05); color:var(--text-secondary); border-color:var(--card-border);" onclick="editMapping('${m.id}')">Edit</button>
                <button class="btn-small-add" style="background:rgba(248,113,113,0.1); color:var(--danger); border-color:rgba(248,113,113,0.3);" onclick="deleteMapping('${m.id}')">Delete</button>
            </div>
        </div>
    `;
}

function renderMappings() {
    const container = document.getElementById('mappings-container');
    const emptyState = document.getElementById('empty-state');
    if (!container || !emptyState) return;

    if (mappings.length === 0) {
        container.innerHTML = '';
        emptyState.style.display = 'block';
        return;
    }

    emptyState.style.display = 'none';
    const sorted = getSortedMappings();

    const groups = {};
    sorted.forEach(m => {
        const gName = (m.group && m.group.trim()) ? m.group.trim() : 'General';
        if (!groups[gName]) groups[gName] = [];
        groups[gName].push(m);
    });

    let html = '';
    for (const gName in groups) {
        const items = groups[gName];
        const cardListHtml = items.map(m => renderMappingCardHtml(m)).join('');

        html += `
            <div class="group-section">
                <div class="group-header">
                    <span class="group-title">📁 ${escapeHtml(gName)}</span>
                    <span class="group-count">${items.length} rule${items.length > 1 ? 's' : ''}</span>
                </div>
                <div class="group-cards-list">
                    ${cardListHtml}
                </div>
            </div>
        `;
    }

    container.innerHTML = html;
    updateGroupDatalist();
}

function toggleMapping(id) {
    const item = mappings.find(m => m.id === id);
    if (item) {
        item.enabled = !item.enabled;
        persistMappings();
        showToast(item.enabled ? "Mapping Enabled" : "Mapping Disabled");
    }
}

function deleteMapping(id) {
    const item = mappings.find(m => m.id === id);
    const ruleName = item ? item.name : 'Mapping';
    
    mappings = mappings.filter(m => m.id !== id);
    persistMappings();
    renderMappings();
    showToast(`Deleted: ${ruleName}`);
}

function openConfiguratorDrawer() {
    editingRuleId = null;
    editingActionIndex = null;
    document.getElementById('drawer-title').textContent = 'New Mapping';
    document.getElementById('inp-mapping-name').value = '';
    document.getElementById('inp-mapping-group').value = 'General';
    
    setCustomSelectValue('cs-press-type', 'sel-press-type', 'lbl-press-type', 'single', {
        'single': 'Single Press', 'double': 'Double Press', 'long': 'Long Press', 'combo': 'Key Combination'
    });
    
    switchTriggerSource('button');
    capturedKey = { code: '25', label: 'Volume Down', secondaryCode: '', secondaryLabel: '' };
    currentConditions = [];
    currentActions = [{ type: 'toggle_flashlight', label: '🔦 Toggle Flashlight' }];

    updateCaptureBoxUI();
    renderConditionPills();
    renderActionPills();

    document.getElementById('configurator-drawer').style.display = 'flex';
}

function editMapping(id) {
    const item = mappings.find(m => m.id === id);
    if (!item) return;

    editingRuleId = item.id;
    document.getElementById('drawer-title').textContent = 'Edit Mapping';
    document.getElementById('inp-mapping-name').value = item.name || '';
    document.getElementById('inp-mapping-group').value = item.group || 'General';

    const pressTypeVal = item.pressType || 'single';
    setCustomSelectValue('cs-press-type', 'sel-press-type', 'lbl-press-type', pressTypeVal, {
        'single': 'Single Press', 'double': 'Double Press', 'long': 'Long Press', 'combo': 'Key Combination'
    });

    switchTriggerSource(item.triggerType || 'button');
    if (item.stateEvent) {
        setCustomSelectValue('cs-state-event', 'sel-state-event', 'lbl-state-event', item.stateEvent, {
            'flashlight_on': '🔦 Flashlight Turned ON', 'flashlight_off': '💡 Flashlight Turned OFF',
            'screen_off': '📱 Screen Turned OFF', 'screen_on': '🔓 Screen Turned ON / Unlocked',
            'screen_folded': '📖 Device Screen Folded', 'ringer_silent': '🔇 Ringer Mode: Switched to Silent',
            'ringer_vibrate': '📳 Ringer Mode: Switched to Vibrate', 'ringer_normal': '🔔 Ringer Mode: Switched to Normal / Ring'
        });
    }

    capturedKey = { 
        code: item.keyCode || '25', 
        label: item.keyLabel || 'Volume Down',
        secondaryCode: item.secondaryKeyCode || '',
        secondaryLabel: item.secondaryKeyLabel || ''
    };
    currentConditions = [...(item.conditions || [])];
    currentActions = [...(item.actions || [])];

    updateCaptureBoxUI();
    renderConditionPills();
    renderActionPills();

    document.getElementById('configurator-drawer').style.display = 'flex';
}

function closeConfiguratorDrawer() {
    document.getElementById('configurator-drawer').style.display = 'none';
    isRecordingKey = false;
}

function startKeyRecording() {
    isRecordingKey = true;
    const box = document.getElementById('key-capture-box');
    if (box) box.className = 'key-capture-box recording';
    document.getElementById('capture-icon').textContent = '🎙️';
    document.getElementById('capture-text').textContent = 'Listening... Press key(s) now!';
    document.getElementById('capture-sub').textContent = 'Press single key or two keys simultaneously for combo';

    if (window.Android && typeof window.Android.startKeyRecording === 'function') {
        window.Android.startKeyRecording();
    }
}

// Single key native callback
window.onNativeKeyCaptured = function(keyCode, keyLabel, keyCodeStr) {
    capturedKey = {
        code: String(keyCode),
        label: keyLabel || ('Key ' + keyCode),
        secondaryCode: '',
        secondaryLabel: ''
    };
    isRecordingKey = false;
    updateCaptureBoxUI();
    showToast(`Captured: ${capturedKey.label}`);
};

// Multi-key combination native callback
window.onNativeKeyCapturedCombo = function(primaryCode, primaryLabel, secondaryCode, secondaryLabel) {
    capturedKey = {
        code: String(primaryCode),
        label: primaryLabel || ('Key ' + primaryCode),
        secondaryCode: String(secondaryCode),
        secondaryLabel: secondaryLabel || ('Key ' + secondaryCode)
    };
    isRecordingKey = false;
    document.getElementById('sel-press-type').value = 'combo';
    updateCaptureBoxUI();
    showToast(`Captured Combo: ${capturedKey.label} + ${capturedKey.secondaryLabel}`);
};

// Keyboard event listener fallback for browser simulation
window.addEventListener('keydown', (e) => {
    if (!isRecordingKey) return;
    e.preventDefault();

    let code = String(e.keyCode);
    let label = e.key;

    if (e.keyCode === 25 || e.code === 'AudioVolumeDown') {
        code = '25';
        label = 'Volume Down';
    } else if (e.keyCode === 24 || e.code === 'AudioVolumeUp') {
        code = '24';
        label = 'Volume Up';
    }

    capturedKey = { code: code, label: label, secondaryCode: '', secondaryLabel: '' };
    isRecordingKey = false;
    updateCaptureBoxUI();
    showToast(`Captured: ${label}`);
});

function updateCaptureBoxUI() {
    const box = document.getElementById('key-capture-box');
    if (box) box.className = 'key-capture-box';
    document.getElementById('capture-icon').textContent = '⌨️';
    if (capturedKey) {
        let text = capturedKey.label;
        if (capturedKey.secondaryLabel) {
            text += ` + ${capturedKey.secondaryLabel}`;
        }
        document.getElementById('capture-text').textContent = text;
        document.getElementById('capture-sub').textContent = `Code: ${capturedKey.code}${capturedKey.secondaryCode ? ' + ' + capturedKey.secondaryCode : ''} (Tap to re-record)`;
    } else {
        document.getElementById('capture-text').textContent = 'Tap here to record button press...';
        document.getElementById('capture-sub').textContent = 'Press Volume Down, Volume Up, or Media keys';
    }
}

// Condition Management
function openConditionPicker() {
    document.getElementById('modal-condition-picker').style.display = 'flex';
}

function closeConditionPicker() {
    document.getElementById('modal-condition-picker').style.display = 'none';
}

function selectCondition(type, label) {
    if (!currentConditions.some(c => c.type === type)) {
        currentConditions.push({ type: type, label: label });
        renderConditionPills();
    }
    closeConditionPicker();
}

function selectConditionPromptApp() {
    closeConditionPicker();
    document.getElementById('inp-app-package').value = '';
    document.getElementById('modal-app-input').style.display = 'flex';
}

function closeAppInputModal() {
    document.getElementById('modal-app-input').style.display = 'none';
}

function confirmAppCondition() {
    const pkg = document.getElementById('inp-app-package').value.trim();
    if (!pkg) return showToast("Please enter an app package name");

    currentConditions.push({ type: 'in_app', package: pkg, label: `🎯 App: ${pkg}` });
    renderConditionPills();
    closeAppInputModal();
}

function removeCondition(index) {
    currentConditions.splice(index, 1);
    renderConditionPills();
}

function renderConditionPills() {
    const container = document.getElementById('conditions-list');
    if (!container) return;
    if (currentConditions.length === 0) {
        container.innerHTML = '<div class="empty-pill-msg">No conditions added (Triggers in any state)</div>';
        return;
    }
    container.innerHTML = currentConditions.map((c, i) => `
        <span class="pill-item">
            ${escapeHtml(c.label)}
            <span class="remove-btn" onclick="removeCondition(${i})">✕</span>
        </span>
    `).join('');
}

// Action Management
function openActionPicker() {
    document.getElementById('modal-action-picker').style.display = 'flex';
}

function closeActionPicker() {
    document.getElementById('modal-action-picker').style.display = 'none';
}

function selectAction(type, label) {
    currentActions.push({ type: type, label: label });
    renderActionPills();
    closeActionPicker();
}

function selectActionPromptWait() {
    editingActionIndex = null;
    closeActionPicker();
    document.getElementById('inp-wait-ms').value = '1000';
    document.getElementById('modal-wait-input').style.display = 'flex';
}

function editWaitAction(index) {
    editingActionIndex = index;
    const a = currentActions[index];
    if (a) {
        document.getElementById('inp-wait-ms').value = a.durationMs || 1000;
        document.getElementById('modal-wait-input').style.display = 'flex';
    }
}

function closeWaitInputModal() {
    document.getElementById('modal-wait-input').style.display = 'none';
    editingActionIndex = null;
}

function confirmWaitAction() {
    const ms = parseInt(document.getElementById('inp-wait-ms').value) || 1000;
    const actionObj = { 
        type: 'wait', 
        durationMs: ms, 
        label: `⏱️ Wait ${ms}ms` 
    };

    if (editingActionIndex !== null && editingActionIndex >= 0 && editingActionIndex < currentActions.length) {
        currentActions[editingActionIndex] = actionObj;
        editingActionIndex = null;
    } else {
        currentActions.push(actionObj);
    }
    renderActionPills();
    closeWaitInputModal();
}

function updateRecordingSizeEstimate() {
    const format = document.getElementById('sel-recording-format').value;
    const bitrateSelect = document.getElementById('sel-recording-bitrate');
    
    if (format === 'amr' && bitrateSelect.value !== '12200') {
        bitrateSelect.value = '12200';
    }

    const bitrateBps = parseInt(bitrateSelect.value) || 64000;
    const mbPerMin = ((bitrateBps * 60) / (8 * 1024 * 1024)).toFixed(2);
    const mbPerHour = ((bitrateBps * 3600) / (8 * 1024 * 1024)).toFixed(1);

    const chip = document.getElementById('est-size-chip');
    if (chip) {
        chip.innerHTML = `📊 Estimated File Size: <strong>~${mbPerMin} MB / min</strong> (~${mbPerHour} MB / hr)`;
    }
}

function selectActionPromptRecording() {
    editingActionIndex = null;
    closeActionPicker();
    document.getElementById('inp-recording-interval-ms').value = '3000';
    document.getElementById('inp-recording-pulse-len-ms').value = '100';
    
    setCustomSelectValue('cs-rec-strength', 'sel-recording-pulse-strength', 'lbl-rec-strength', '128', {
        '50': 'Soft (~20%)', '128': 'Medium (~50%)', '200': 'Strong (~80%)', '255': 'Maximum (100%)'
    });
    setCustomSelectValue('cs-rec-format', 'sel-recording-format', 'lbl-rec-format', 'm4a', {
        'm4a': 'AAC (.m4a) - Standard High Quality', 'amr': 'AMR-NB (.amr) - Ultra Compressed Voice', '3gp': 'AMR-WB (.3gp) - Wideband Voice'
    });
    setCustomSelectValue('cs-rec-bitrate', 'sel-recording-bitrate', 'lbl-rec-bitrate', '64000', {
        '12200': '12.2 kbps - Ultra Light Voice (~0.09 MB/min)', '32000': '32 kbps - Low Compressed (~0.23 MB/min)',
        '64000': '64 kbps - Standard Voice (~0.46 MB/min)', '128000': '128 kbps - High Quality (~0.92 MB/min)', '256000': '256 kbps - Studio Quality (~1.83 MB/min)'
    });
    setCustomSelectValue('cs-rec-samplerate', 'sel-recording-samplerate', 'lbl-rec-samplerate', '16000', {
        '8000': '8,000 Hz (Telephone)', '16000': '16,000 Hz (Voice Standard)', '44100': '44,100 Hz (CD Quality)', '48000': '48,000 Hz (Studio)'
    });

    updateRecordingSizeEstimate();
    document.getElementById('modal-recording-input').style.display = 'flex';
}

function editRecordingAction(index) {
    editingActionIndex = index;
    const a = currentActions[index];
    if (a) {
        document.getElementById('inp-recording-interval-ms').value = a.intervalMs || 3000;
        document.getElementById('inp-recording-pulse-len-ms').value = a.pulseDurationMs || 100;
        
        const formatVal = a.format || 'm4a';
        const bitrateVal = String(a.bitRate || 64000);
        const samplerateVal = String(a.sampleRate || 16000);
        const strengthVal = String(a.pulseAmplitude || 128);

        setCustomSelectValue('cs-rec-strength', 'sel-recording-pulse-strength', 'lbl-rec-strength', strengthVal, {
            '50': 'Soft (~20%)', '128': 'Medium (~50%)', '200': 'Strong (~80%)', '255': 'Maximum (100%)'
        });
        setCustomSelectValue('cs-rec-format', 'sel-recording-format', 'lbl-rec-format', formatVal, {
            'm4a': 'AAC (.m4a) - Standard High Quality', 'amr': 'AMR-NB (.amr) - Ultra Compressed Voice', '3gp': 'AMR-WB (.3gp) - Wideband Voice'
        });
        setCustomSelectValue('cs-rec-bitrate', 'sel-recording-bitrate', 'lbl-rec-bitrate', bitrateVal, {
            '12200': '12.2 kbps - Ultra Light Voice (~0.09 MB/min)', '32000': '32 kbps - Low Compressed (~0.23 MB/min)',
            '64000': '64 kbps - Standard Voice (~0.46 MB/min)', '128000': '128 kbps - High Quality (~0.92 MB/min)', '256000': '256 kbps - Studio Quality (~1.83 MB/min)'
        });
        setCustomSelectValue('cs-rec-samplerate', 'sel-recording-samplerate', 'lbl-rec-samplerate', samplerateVal, {
            '8000': '8,000 Hz (Telephone)', '16000': '16,000 Hz (Voice Standard)', '44100': '44,100 Hz (CD Quality)', '48000': '48,000 Hz (Studio)'
        });

        updateRecordingSizeEstimate();
        document.getElementById('modal-recording-input').style.display = 'flex';
    }
}

function closeRecordingInputModal() {
    document.getElementById('modal-recording-input').style.display = 'none';
    editingActionIndex = null;
}

function confirmStartRecordingAction() {
    const ms = parseInt(document.getElementById('inp-recording-interval-ms').value) || 3000;
    const pulseLen = parseInt(document.getElementById('inp-recording-pulse-len-ms').value) || 100;
    const pulseAmp = parseInt(document.getElementById('sel-recording-pulse-strength').value) || 128;
    const format = document.getElementById('sel-recording-format').value || 'm4a';
    const bitRate = parseInt(document.getElementById('sel-recording-bitrate').value) || 64000;
    const sampleRate = parseInt(document.getElementById('sel-recording-samplerate').value) || 16000;

    const bitrateKbps = Math.round(bitRate / 1000);
    const mbPerMin = ((bitRate * 60) / (8 * 1024 * 1024)).toFixed(2);

    const ampLabels = { '50': 'Soft', '128': 'Medium', '200': 'Strong', '255': 'Max' };
    const ampName = ampLabels[String(pulseAmp)] || `${pulseAmp}`;

    const actionObj = { 
        type: 'start_recording', 
        intervalMs: ms,
        pulseDurationMs: pulseLen,
        pulseAmplitude: pulseAmp,
        format: format,
        bitRate: bitRate,
        sampleRate: sampleRate,
        label: `🎙️ Start Recording (${bitrateKbps}kbps .${format}, Vibrate every ${ms}ms [${pulseLen}ms @ ${ampName}])` 
    };

    if (editingActionIndex !== null && editingActionIndex >= 0 && editingActionIndex < currentActions.length) {
        currentActions[editingActionIndex] = actionObj;
        editingActionIndex = null;
    } else {
        currentActions.push(actionObj);
    }
    renderActionPills();
    closeRecordingInputModal();
}

function removeAction(index) {
    currentActions.splice(index, 1);
    renderActionPills();
}

function renderActionPills() {
    const container = document.getElementById('actions-list');
    if (!container) return;
    if (currentActions.length === 0) {
        container.innerHTML = '<div class="empty-pill-msg">No actions added yet</div>';
        return;
    }
    container.innerHTML = currentActions.map((a, i) => {
        const isConfigurable = (a.type === 'wait' || a.type === 'start_recording');
        const configClass = isConfigurable ? ' configurable' : '';
        const editIcon = isConfigurable ? '<span class="edit-icon">⚙️</span>' : '';

        return `
            <span class="pill-item action-pill${configClass}"
                  onclick="if(!isPillLongPress && ${isConfigurable}) editActionSettings(${i})"
                  onpointerdown="${isConfigurable ? `startPillLongPress(${i})` : ''}"
                  onpointerup="cancelPillLongPress()"
                  onpointerleave="cancelPillLongPress()"
                  oncontextmenu="${isConfigurable ? `event.preventDefault(); editActionSettings(${i});` : ''}">
                <strong>${i + 1}.</strong> ${escapeHtml(a.label)} ${editIcon}
                <span class="remove-btn" onclick="event.stopPropagation(); removeAction(${i})">✕</span>
            </span>
        `;
    }).join('');
}

function saveMappingRule() {
    const name = document.getElementById('inp-mapping-name').value.trim() || 'Custom Mapping';
    const pressType = document.getElementById('sel-press-type').value;

    if (!capturedKey) {
        return showToast("Please capture a hardware button press first!");
    }
    if (currentActions.length === 0) {
        return showToast("Please add at least one trigger action!");
    }

    const groupName = document.getElementById('inp-mapping-group').value.trim() || 'General';

    const ruleObj = {
        id: editingRuleId || ('rule_' + Date.now()),
        name: name,
        group: groupName,
        enabled: true,
        triggerType: currentTriggerSource,
        stateEvent: currentTriggerSource === 'state' ? document.getElementById('sel-state-event').value : '',
        keyCode: capturedKey ? capturedKey.code : '',
        keyLabel: capturedKey ? capturedKey.label : '',
        secondaryKeyCode: capturedKey ? (capturedKey.secondaryCode || '') : '',
        secondaryKeyLabel: capturedKey ? (capturedKey.secondaryLabel || '') : '',
        pressType: (capturedKey && capturedKey.secondaryCode) ? 'combo' : pressType,
        conditions: [...currentConditions],
        actions: [...currentActions],
        updatedAt: new Date().toISOString()
    };

    if (editingRuleId) {
        const idx = mappings.findIndex(m => m.id === editingRuleId);
        if (idx >= 0) mappings[idx] = ruleObj;
    } else {
        mappings.push(ruleObj);
    }

    persistMappings();
    renderMappings();
    closeConfiguratorDrawer();
    showToast("Mapping Rule Saved!");
}

function testCurrentRule() {
    if (currentActions.length === 0) return showToast("No actions added to test!");

    if (window.Android && typeof window.Android.executeActionsSequence === 'function') {
        window.Android.executeActionsSequence(JSON.stringify(currentActions));
        showToast("Executing action sequence...");
    } else {
        // Fallback simulated execution for browser preview
        let delayAccumulator = 0;
        currentActions.forEach((a, i) => {
            if (a.type === 'wait') {
                delayAccumulator += (a.durationMs || 500);
            } else {
                setTimeout(() => {
                    if (a.type === 'vibrate') {
                        if (navigator.vibrate) navigator.vibrate(200);
                    } else if (a.type === 'play_sound') {
                        playTestAudioChime();
                    } else if (a.type === 'toggle_flashlight' || a.type === 'turn_flashlight_off' || a.type === 'turn_flashlight_on') {
                        showToast(`Step ${i + 1}: ${a.label}`);
                    }
                }, delayAccumulator);
            }
        });
    }
}

function playTestAudioChime() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.2);
    } catch (e) {}
}

document.addEventListener('DOMContentLoaded', () => {
    loadMappings();
});