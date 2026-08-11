<?php
require_once __DIR__ . '/../../app/paths.php';

// Route API requests if action is present
if (isset($_GET['action']) || isset($_POST['action'])) {
    require_once __DIR__ . '/modules/api.php';
    exit;
}

require_once __DIR__ . '/modules/db.php';

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
    <title>Agent Studio</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo $v; ?>">
    <link rel="icon" type="image/svg+xml" href="icon.svg">
    <link rel="manifest" href="manifest.json">
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="AgentStudio">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
</head>
<body>
    <div class="app-header">
        <div class="app-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/></svg>
            Agent Studio
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <div class="credit-pill" id="credit-pill" title="Tap to refresh | Long press to open top-up page">
                <span id="credit-val">--</span>
                <button type="button" class="btn-refresh-credits" id="btn-refresh-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6"/><path d="M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                </button>
            </div>
            <div class="app-version">v<?php echo $v; ?></div>
            <button class="btn-icon" id="btn-open-settings" onclick="App.openSettings()" title="Settings">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </button>
        </div>
    </div>

    <div class="app-container">
        <div class="sidebar" id="thread-sidebar">
            <div class="sidebar-header">
                <h3>Missions</h3>
                <button class="btn-icon" onclick="App.createThread()" title="New Mission">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
            </div>
            <div class="thread-list" id="thread-list">
                <!-- Thread items will be rendered here -->
            </div>
        </div>

        <div class="main-content">
            <div class="empty-state" id="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.4; margin-bottom: 12px;"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/></svg>
                <h2>No Mission Selected</h2>
                <p>Select an existing mission or create a new one to begin.</p>
            </div>

            <div class="chat-workspace" id="chat-workspace" style="display: none;">
                <div class="chat-header">
                    <button class="btn-icon btn-back-missions" onclick="App.showSidebarMobile()" title="Back to Missions" style="display: none; margin-right: 8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    </button>
                    <span id="chat-header-title" style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Mission Thread</span>
                    <span id="chat-token-badge" class="chat-token-pill" style="display: none;" title="Total context length for active mission">~0 Tokens</span>
                    <button class="btn-icon" id="btn-copy-thread" onclick="App.copyFullThread()" title="Copy Full Thread (4 Tildes)" style="margin-left: 8px; flex-shrink: 0;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                    </button>
                </div>
                <div class="messages-container" id="messages-container">
                    <!-- Messages will be rendered here -->
                </div>
                <div id="attachment-preview-bar" class="attachment-tray" style="display: none;"></div>
                <div class="input-container">
                    <button class="btn-attach" id="btn-attach-file" onclick="App.triggerFileAttachment()" title="Attach image or file">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                    <button class="btn-agentic" id="btn-agentic-mode" onclick="App.openAgenticModal()" title="Start Agentic Automation Mode">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/></svg>
                    </button>
                    <input type="file" id="inp-chat-file" multiple style="display: none;" onchange="App.handleFileAttachmentSelect(event)">
                    <textarea id="chat-input" placeholder="Type your message here..." rows="1"></textarea>
                    <button class="btn-stop" id="btn-stop-agent" onclick="App.stopAgent()" title="Stop Agent" style="display: none;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
                    </button>
                    <button class="btn-send" id="btn-send-message" onclick="App.sendMessage()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Model Picker -->
    <div class="modal-overlay" id="modal-model-picker">
        <div class="modal-content" style="max-width: 520px; max-height: 85vh; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h3>Select Model</h3>
                <button class="btn-icon" onclick="App.closeModal('modal-model-picker')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            
            <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                <input type="text" id="inp-model-search" placeholder="Search models (e.g. claude, gpt-4o, llama)..." oninput="App.filterModels()" style="flex: 1; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-primary); font-size: 13px;">
                <button type="button" class="btn-filter-free" id="btn-filter-free" onclick="App.toggleFreeFilter()">
                    🆓 Free Only
                </button>
            </div>

            <div class="model-picker-list" id="model-picker-list" style="flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 4px;">
                <!-- Model cards rendered dynamically -->
            </div>
        </div>
    </div>

    <!-- Modal: Message Action Sheet -->
    <div class="modal-overlay" id="modal-msg-actions">
        <div class="modal-content" style="max-width: 380px;">
            <h3 style="margin-bottom: 12px;">Message Options</h3>
            <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
                <button class="btn btn-secondary" onclick="App.copyMessageText()" style="text-align: left; padding: 12px;">📋 Copy Text</button>
                <button class="btn btn-secondary" id="btn-action-edit" onclick="App.openEditMessageModal()" style="text-align: left; padding: 12px; display: none;">✏️ Edit & Retry</button>
                <button class="btn btn-secondary" onclick="App.executeBranchThread()" style="text-align: left; padding: 12px;">🌿 Branch Thread</button>
                <button class="btn btn-secondary" onclick="App.executeDeleteMessage()" style="text-align: left; padding: 12px; color: var(--danger); border-color: rgba(239,68,68,0.3);">🗑️ Delete Message</button>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-msg-actions')">Close</button>
            </div>
        </div>
    </div>

    <!-- Modal: Edit Message -->
    <div class="modal-overlay" id="modal-edit-msg">
        <div class="modal-content" style="max-width: 480px;">
            <h3>Edit Message & Retry</h3>
            <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 12px;">Editing will truncate all messages after this point and re-run the turn from this prompt.</p>
            <div class="form-group">
                <textarea id="inp-edit-msg-content" rows="4" style="width:100%; font-size:13px;"></textarea>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-edit-msg')">Cancel</button>
                <button class="btn btn-primary" onclick="App.submitEditMessage()">Save & Retry</button>
            </div>
        </div>
    </div>

    <!-- Modal: New Thread -->
    <div class="modal-overlay" id="modal-new-thread">
        <div class="modal-content" style="max-width: 380px;">
            <h3>Create New Mission</h3>
            <div class="form-group">
                <label>Mission Title</label>
                <input type="text" id="inp-thread-title" placeholder="e.g. Build Feature X" value="New Mission">
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-new-thread')">Cancel</button>
                <button class="btn btn-primary" onclick="App.submitNewThread()">Create Mission</button>
            </div>
        </div>
    </div>

    <!-- Modal: Agentic Automation Mode -->
    <div class="modal-overlay" id="modal-agentic-mode">
        <div class="modal-content" style="max-width: 420px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                <span style="font-size: 24px;">🤖</span>
                <h3 style="margin: 0;">Agentic Automation Mode</h3>
            </div>
            <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 16px; line-height: 1.4;">
                Grants the AI autonomous turns to inspect files, execute code patches, and perform multi-step modifications automatically.
            </p>
            <div class="form-group">
                <label>Maximum Autonomous Turns</label>
                <div style="display: flex; gap: 8px; margin-bottom: 8px;">
                    <input type="number" id="inp-agentic-turns" min="1" max="30" value="10" style="font-size: 15px; font-weight: 700; text-align: center; font-family: monospace;">
                </div>
                <div style="display: flex; gap: 6px;">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('inp-agentic-turns').value=5" style="flex:1; font-size:11px; padding:6px;">5 Turns</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('inp-agentic-turns').value=10" style="flex:1; font-size:11px; padding:6px;">10 Turns</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('inp-agentic-turns').value=15" style="flex:1; font-size:11px; padding:6px;">15 Turns</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('inp-agentic-turns').value=20" style="flex:1; font-size:11px; padding:6px;">20 Turns</button>
                </div>
            </div>
            <div class="form-group" style="margin-top: 12px;">
                <label>Task Instructions / Goal <span style="font-weight: normal; opacity: 0.6;">(Optional)</span></label>
                <textarea id="inp-agentic-goal" rows="3" placeholder="Optional: Leave blank to proceed directly with the current chat context / agreed plan..."></textarea>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-agentic-mode')">Cancel</button>
                <button class="btn btn-primary" onclick="App.launchAgenticMode()" style="background: var(--primary-accent); display: flex; align-items: center; gap: 6px;">
                    <span>🚀</span> Start Automation
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Settings -->
    <div class="modal-overlay" id="modal-settings">
        <div class="modal-content">
            <h3>Agent Settings</h3>
            <div class="form-group">
                <label>OpenRouter API Key</label>
                <div style="display: flex; gap: 8px;">
                    <input type="password" id="inp-api-key" placeholder="sk-or-v1-..." style="flex: 1;">
                    <button type="button" class="btn btn-secondary" id="btn-toggle-key" onclick="App.toggleApiKeyVisibility()">Show</button>
                </div>
            </div>
            <div class="form-group">
                <label>Target LLM Model</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="inp-model" placeholder="anthropic/claude-3.5-sonnet" readonly style="flex: 1; cursor: pointer;" onclick="App.openModelPicker()">
                    <button type="button" class="btn btn-secondary" onclick="App.openModelPicker()" style="white-space: nowrap;">Browse Models</button>
                </div>
            </div>
            <div class="form-group">
                <label>System Prompt</label>
                <textarea id="inp-system-prompt" rows="3" placeholder="You are an autonomous AI engineer..."></textarea>
            </div>
            <div class="form-group">
                <label>Max Autonomous Iterations</label>
                <input type="number" id="inp-max-iter" min="1" max="25" value="10">
            </div>
            <div class="form-group" style="margin-top: 12px;">
                <label style="font-size: 13px; font-weight: 600; margin-bottom: 8px; display: block;">System Context Mode</label>
                <div class="context-mode-selector" style="display: flex; gap: 4px; background: var(--bg-color); padding: 4px; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 10px;">
                    <button type="button" class="btn-context-mode" id="btn-ctx-none" onclick="App.selectContextMode('none')" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 600; transition: all 0.2s;">None</button>
                    <button type="button" class="btn-context-mode" id="btn-ctx-foundation" onclick="App.selectContextMode('foundation')" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 600; transition: all 0.2s;">Foundation</button>
                    <button type="button" class="btn-context-mode" id="btn-ctx-project" onclick="App.selectContextMode('project')" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 600; transition: all 0.2s;">Foundation + Project</button>
                </div>
                <input type="hidden" id="inp-context-mode" value="foundation">

                <div id="foundation-files-wrap" style="background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px; margin-top: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; border-bottom: 1px solid var(--border-color); padding-bottom: 6px;">
                        <span style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;"><span id="context-mode-title-label">Foundation</span> Files (<span id="foundation-file-count">0</span>)</span>
                        <span style="font-size: 10px; font-family: monospace; color: var(--primary-accent); font-weight: 700;" id="foundation-file-size">0 KB</span>
                    </div>
                    <div id="foundation-files-list" style="max-height: 150px; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; font-family: monospace; font-size: 11px; color: var(--text-secondary);">
                        <!-- Dynamically populated from SSOT -->
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-settings')">Cancel</button>
                <button class="btn btn-primary" onclick="App.saveSettings()">Save Settings</button>
            </div>
        </div>
    </div>

    <!-- Modal: Generic Confirmation -->
    <div class="modal-overlay" id="modal-confirm">
        <div class="modal-content" style="max-width: 380px;">
            <h3 id="confirm-title">Confirm Action</h3>
            <p id="confirm-message" style="font-size: 13px; color: var(--text-secondary); margin-bottom: 20px; line-height: 1.5;"></p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-confirm')">Cancel</button>
                <button class="btn btn-primary" id="btn-confirm-ok">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Modal: Non-Persistent Manual Patch Result -->
    <div class="modal-overlay manual-patch-result-overlay" id="modal-manual-patch-result">
        <div class="modal-content manual-patch-result-content">
            <div class="manual-patch-result-header">
                <div>
                    <h3 id="manual-patch-result-title">Manual Patch Result</h3>
                    <span id="manual-patch-result-status" class="manual-patch-result-status">RESULT</span>
                </div>
                <button class="btn-icon" type="button" onclick="App.closeManualPatchResult()" title="Close result">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div id="manual-patch-result-summary" class="manual-patch-result-summary"></div>
            <pre id="manual-patch-result-body" class="manual-patch-result-body"></pre>
            <div class="modal-actions manual-patch-result-actions">
                <button class="btn btn-secondary" type="button" onclick="App.copyManualPatchResult()">Copy Result</button>
                <button class="btn btn-primary" type="button" onclick="App.closeManualPatchResult()">Done</button>
            </div>
        </div>
    </div>

    <script src="js/lib/marked.min.js"></script>
    <script src="js/app.js?v=<?php echo $v; ?>"></script>
</body>
</html>