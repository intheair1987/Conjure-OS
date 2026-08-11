<?php
// ==============================================================================
// PLUGIN: About Conjure
// DESCRIPTION: System documentation viewer.
// Provides an in-app interface for reading the README.md.
// ==============================================================================

// --- 1. BACKEND HANDLER ---
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'ac_get_readme') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $readmePath = CJOS_PATH_ROOT . '/README.md';
    if (file_exists($readmePath)) {
        echo json_encode([
            'status' => 'success',
            'content' => file_get_contents($readmePath)
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'README.md not found in root.'
        ]);
    }
    exit;
}

// --- 2. SETTINGS UI ---
$plugin_settings_map['AboutConjure'] = <<<'HTML'
<div class="setting-item">
    <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
        <div>
            <label class="setting-label" style="margin:0;">About Conjure</label>
            <div class="setting-desc">View system documentation and mission.</div>
        </div>
        <button onclick="acOpenAbout()" class="text-btn" style="background:var(--btn-bg); color:var(--primary); border:1px solid var(--border-color); padding:8px 16px; border-radius:10px; font-weight:700;">
            Open
        </button>
    </div>
</div>
HTML;

// --- 3. FRONTEND LOGIC ---
$plugin_js .= <<<'JS'
window.acOpenAbout = async function() {
    window.sui.openStudio({
        id: 'ac-studio',
        title: 'About Conjure',
        content: `<div style="padding:40px; text-align:center;">${window.suiSpinner(30)}</div>`,
        onSetup: async (contentBox) => {
            try {
                const res = await window.sui.api('ac_get_readme', {}, { toast: false });
                if (res && res.status === 'success') {
                    // Simple Markdown-ish rendering for the Studio
                    const html = res.content
                        .replace(/^# (.*$)/gm, '<h1 style="color:var(--primary); border-bottom:2px solid var(--border-color); padding-bottom:10px; margin-top:30px;">$1</h1>')
                        .replace(/^## (.*$)/gm, '<h2 style="color:var(--text-primary); margin-top:25px; font-weight:800;">$1</h2>')
                        .replace(/^### (.*$)/gm, '<h3 style="color:var(--text-primary); margin-top:20px; opacity:0.9;">$1</h3>')
                        .replace(/^\> \"(.*)\"/gm, '<blockquote style="border-left:4px solid var(--ai-accent); padding:10px 20px; margin:20px 0; background:var(--btn-bg); font-style:italic; border-radius:0 8px 8px 0;">$1</blockquote>')
                        .replace(/\*\*(.*?)\*\*/g, '<strong style="color:var(--text-primary);">$1</strong>')
                        .replace(/\*(.*?)\*/g, '<em>$1</em>')
                        .replace(/^- (.*$)/gm, '<div style="display:flex; gap:10px; margin-bottom:8px;"><span style="color:var(--primary);">•</span><span>$1</span></div>')
                        .replace(/`([^`]+)`/g, '<code style="background:var(--btn-bg); padding:2px 6px; border-radius:4px; font-family:monospace; font-size:0.9em; border:1px solid var(--border-color);">$1</code>')
                        .split('\n\n').map(p => p.trim().startsWith('<h') || p.trim().startsWith('<div') || p.trim().startsWith('<block') ? p : `<p style="line-height:1.6; margin-bottom:16px; color:var(--text-secondary);">${p}</p>`).join('');

                    contentBox.innerHTML = `
                        <div style="max-width:700px; margin:0 auto; padding:20px 10px 60px 10px; font-family:var(--font-main);">
                            <div style="text-align:center; margin-bottom:40px;">
                                <div style="font-size:64px; margin-bottom:10px;">🔮</div>
                                <div style="font-size:12px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:2px;">Sovereign AI OS</div>
                            </div>
                            ${html}
                            <div style="margin-top:60px; padding-top:20px; border-top:1px solid var(--border-color); text-align:center; opacity:0.5; font-size:11px;">
                                Version 1.0.0 • Open Source Sovereign Software
                            </div>
                        </div>
                    `;
                } else {
                    contentBox.innerHTML = window.suiEmptyState('❓', 'Could not load README.md');
                }
            } catch (e) {
                contentBox.innerHTML = window.suiEmptyState('❌', 'Connection Error');
            }
        }
    });
};
JS;
?>