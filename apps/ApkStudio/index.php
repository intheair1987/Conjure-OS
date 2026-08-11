<?php
require_once __DIR__ . '/../../app/paths.php';

// Route API/AJAX requests
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    require_once __DIR__ . '/modules/api.php';
    exit;
}

// Initialize Database
require_once __DIR__ . '/modules/db.php';

// Asset Fingerprinting (Cache-Busting)
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
    <title>ApkStudio</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo $v; ?>">
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="ApkStudio">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
</head>
<body>
    <div class="app-header">
        <div class="app-title" onclick="App.closeActiveProject()" title="Return to Home">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 12-8.5 8.5c-.83.83-2.17.83-3 0 0 0 0 0 0 0a2.12 2.12 0 0 1 0-3L12 9"/><path d="M17.64 15 22 10.64"/><path d="m20.91 11.7-1.25-1.25c-.6-.6-.93-1.4-.93-2.25v-.86L16.01 4.6a5.56 5.56 0 0 0-3.94-1.64H9l.92.82A6.18 6.18 0 0 1 12 8.4v1.56l2 2h2.47l2.26 1.91"/></svg>
            ApkStudio
        </div>
        <div class="app-version">v<?php echo $v; ?></div>
    </div>

    <div class="app-container">
        <div class="sidebar" id="project-sidebar">
            <div class="sidebar-header">
                <h3>Projects</h3>
                <button class="btn-icon" id="btn-new-project" title="New Project">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                </button>
            </div>
            <div class="project-list" id="project-list">
                <!-- Projects will be rendered here -->
            </div>
        </div>

        <div class="main-content" id="main-content">
            <div class="empty-state" id="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.5; margin-bottom: 16px;"><path d="m15 12-8.5 8.5c-.83.83-2.17.83-3 0 0 0 0 0 0 0a2.12 2.12 0 0 1 0-3L12 9"/><path d="M17.64 15 22 10.64"/><path d="m20.91 11.7-1.25-1.25c-.6-.6-.93-1.4-.93-2.25v-.86L16.01 4.6a5.56 5.56 0 0 0-3.94-1.64H9l.92.82A6.18 6.18 0 0 1 12 8.4v1.56l2 2h2.47l2.26 1.91"/></svg>
                <h2>No Project Selected</h2>
                <p>Select a project from the sidebar or create a new one to begin.</p>
            </div>
            
            <div class="project-workspace" id="project-workspace">
                <div class="file-explorer" id="file-explorer">
                    <div class="workspace-header">
                        <div style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;">
                            <button class="btn-icon" id="btn-close-project" title="Close Project" onclick="App.closeActiveProject()" style="color: var(--text-secondary); padding: 2px; flex-shrink: 0;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                            </button>
                            <span id="active-project-title">Workspace</span>
                        </div>
                        <div style="display: flex; gap: 6px; align-items: center;">
                            <button class="btn-icon" title="Edit App Name and Package Name" onclick="App.openEditProjectModal()" style="color: var(--text-secondary); padding: 2px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            </button>
                            <button class="btn-icon" title="Configure App Icon" onclick="App.openAppIconModal()" style="color: var(--text-secondary); padding: 2px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            </button>
                            <button class="btn-icon" title="Configure Signing Key" onclick="App.openUploadKeyModal(App.currentProjectId, App.currentProjectName)" style="color: var(--text-secondary); padding: 2px;">
                                <img src="key.svg" class="btn-key-img" alt="Key" style="width: 16px; height: 16px;">
                            </button>
                            <button class="btn-icon" title="Delete Project" onclick="App.deleteProject(App.currentProjectId, App.currentProjectName)" style="color: var(--text-secondary); padding: 2px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                            </button>
                        </div>
                    </div>
                    <div style="padding-bottom: 12px; border-bottom: 1px solid var(--border-color); margin-bottom: 12px; display: flex; flex-direction: column; gap: 8px;" id="build-button-container">
                        <button class="btn btn-success-daemon" id="btn-daemon-build" style="width: 100%; display: none; align-items: center; justify-content: center; gap: 8px; background: #34c759; color: white;" onclick="App.triggerDaemonBuild()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            One-Click Build
                        </button>
                        <button class="btn btn-primary" id="btn-termux-build" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px;" onclick="App.showBuildCommand()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
                            Compile in Termux
                        </button>
                    </div>
                    <div id="tree-container"></div>
                </div>
                <div class="code-viewer" id="code-viewer">
                    <div class="code-header">
                        <span id="code-header-path">Select a file</span>
                        
                        <div class="view-tabs" id="view-tabs" style="display: none;">
                            <button class="tab-btn active" data-tab="code" onclick="App.switchViewTab('code')">Code</button>
                            <button class="tab-btn" data-tab="preview" onclick="App.switchViewTab('preview')">Preview</button>
                        </div>

                        <button class="btn-icon" id="btn-copy-path" title="Copy Path for Patcher" style="display:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                        </button>
                    </div>
                    
                    <div class="content-wrapper" style="flex: 1; position: relative; overflow: hidden; display: flex; flex-direction: column;">
                        <pre class="code-content" id="code-content"></pre>
                        
                        <div class="preview-content" id="preview-content">
                            <div class="phone-frame-scaler" id="phone-frame-scaler">
                                <div class="phone-frame" id="phone-frame">
                                    <div class="phone-screen" id="preview-phone-screen"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Modals -->
    <div class="modal-overlay" id="modal-build-cmd">
        <div class="modal-content" style="max-width: 500px;">
            <h3>Compile in Termux</h3>
            <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px;">Copy the command below and paste it into Termux. It will download the project, compile the APK without Gradle, and place it in your Downloads folder.</p>
            <div class="form-group">
                <textarea id="inp-build-cmd" readonly style="width: 100%; height: 100px; padding: 10px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-primary); border-radius: 6px; box-sizing: border-box; font-family: monospace; font-size: 12px; resize: none;"></textarea>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-build-cmd')">Close</button>
                <button class="btn btn-primary" onclick="App.copyBuildCommand()">Copy Command</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-build-console">
        <div class="modal-content" style="max-width: 600px; width: 100%;">
            <h3>Build Progress</h3>
            <div id="build-console-output" class="console" style="margin-bottom: 16px;"></div>
            <div class="modal-actions">
                <button class="btn btn-primary" id="btn-copy-console" onclick="App.copyConsoleLogs()">Copy Logs</button>
                <button class="btn btn-secondary" id="btn-close-console" onclick="App.closeModal('modal-build-console')" disabled>Compiling...</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-new-project">
        <div class="modal-content">
            <h3>Create New Project</h3>
            <div class="form-group">
                <label>App Name</label>
                <input type="text" id="inp-proj-name" placeholder="e.g. My Awesome App">
            </div>
            <div class="form-group">
                <label>Package Name</label>
                <input type="text" id="inp-proj-pkg" placeholder="e.g. com.example.myapp" autocapitalize="none" autocorrect="off" autocomplete="off" spellcheck="false">
            </div>
            <div class="form-group">
                <label>Project Type</label>
                <div class="custom-select" id="custom-proj-type">
                    <div class="select-trigger" onclick="App.toggleCustomSelect()">
                        <span id="selected-type-text">Standard Android App (Java/XML)</span>
                        <svg class="chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </div>
                    <div class="select-options">
                        <div class="select-option active" data-value="standard" onclick="App.selectCustomOption('standard', 'Standard Android App (Java/XML)')">Standard Android App (Java/XML)</div>
                        <div class="select-option" data-value="wrapper" onclick="App.selectCustomOption('wrapper', 'Web View Wrapper App')">Web View Wrapper App</div>
                    </div>
                </div>
                <input type="hidden" id="inp-proj-type" value="standard">
            </div>
            <div class="form-group" id="form-group-wrapper-url" style="display: none;">
                <label>Target Web URL / Address</label>
                <input type="text" id="inp-proj-url" placeholder="e.g. https://localhost:8000" autocapitalize="none" autocorrect="off" autocomplete="off" spellcheck="false">
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-new-project')">Cancel</button>
                <button class="btn btn-primary" onclick="App.submitNewProject()">Create</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-edit-project">
        <div class="modal-content">
            <h3>Edit Project Settings</h3>
            <div class="form-group">
                <label>App Name</label>
                <input type="text" id="inp-edit-proj-name" placeholder="e.g. My Awesome App">
            </div>
            <div class="form-group">
                <label>Package Name</label>
                <input type="text" id="inp-edit-proj-pkg" placeholder="e.g. com.example.myapp" autocapitalize="none" autocorrect="off" autocomplete="off" spellcheck="false">
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-edit-project')">Cancel</button>
                <button class="btn btn-primary" onclick="App.submitEditProject()">Save Changes</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-delete-confirm">
        <div class="modal-content" style="max-width: 380px;">
            <h3 style="color: var(--danger); margin-bottom: 8px;">Delete Project?</h3>
            <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 24px; line-height: 1.5;">
                Are you sure you want to delete <strong id="delete-project-name" style="color: var(--text-primary);"></strong>? This will permanently erase all associated source code and directory structures.
            </p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-delete-confirm')">Cancel</button>
                <button class="btn" style="background: var(--danger, #ff3b30); color: var(--btn-text, #fff);" id="btn-confirm-delete">Delete</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-generic-confirm">
        <div class="modal-content" style="max-width: 380px;">
            <h3 id="generic-confirm-title" style="margin-bottom: 8px;">Confirm Action</h3>
            <p id="generic-confirm-message" style="font-size: 13px; color: var(--text-secondary); margin-bottom: 24px; line-height: 1.5;"></p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="App.closeModal('modal-generic-confirm')">Cancel</button>
                <button class="btn btn-primary" id="btn-generic-confirm-ok">Confirm</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-upload-icon">
        <div class="modal-content" style="max-width: 500px;">
            <h3>Configure App Icon</h3>
            
            <div class="tab-menu" id="icon-tab-menu" style="display: flex; gap: 4px; background: var(--bg-color); padding: 4px; border-radius: 8px; margin-bottom: 16px; border: 1px solid var(--border-color);">
                <button type="button" class="tab-btn active" id="btn-icon-tab-upload" onclick="App.switchIconTab('upload')" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 500; transition: all 0.2s;">Upload Icon</button>
                <button type="button" class="tab-btn" id="btn-icon-tab-generate" onclick="App.switchIconTab('generate')" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 500; transition: all 0.2s;">Generate Icon</button>
            </div>

            <div id="active-icon-banner" style="display: none; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 12px; border-radius: 12px; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 12px; cursor: pointer;" onclick="App.editActiveIcon()" title="Tap to Edit Icon">
                    <img id="active-icon-preview" src="" style="width: 44px; height: 44px; border-radius: 8px; object-fit: contain; background: #000; padding: 4px; border: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 13px; font-weight: 600; color: var(--text-primary);">Active App Icon</div>
                        <div id="active-icon-filename" style="font-size: 11px; color: var(--text-secondary); font-family: monospace;">res/drawable/app_icon.png</div>
                    </div>
                </div>
                <button type="button" class="btn-icon" title="Remove Icon" onclick="App.deleteProjectIcon()" style="color: var(--danger); padding: 4px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <form id="form-upload-icon" onsubmit="event.preventDefault(); App.submitUploadIcon();">
    <!-- Upload Pane -->
    <div class="icon-tab-pane" id="pane-icon-upload">
        <div class="icon-upload-zone" id="iconZoneStudio" style="height: 120px; border-radius: 12px; border: 2px dashed var(--border-color); background: rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; position: relative; cursor: pointer; margin-bottom: 16px;">
            <input type="file" id="inpIconStudio" accept="*/*" style="display: none;">
            <div class="icon-preview-container" id="iconPreviewContainerStudio" style="display:none; width: 100%; height: 100%; align-items: center; justify-content: center; background: #000; padding: 10px;">
                <img id="iconPreviewStudio" src="" alt="Icon Preview" style="max-height: 100%; max-width: 100%; object-fit: contain; border-radius: 8px;">
            </div>
            <div class="icon-placeholder" id="iconPlaceholderStudio" style="color: var(--text-secondary); font-size: 13px; text-align: center; padding: 20px; pointer-events: none;">
                <span>Tap to upload a square image (e.g. 512x512)</span>
            </div>
        </div>
        <div id="upload-svg-controls" class="form-group" style="display: none; margin-bottom: 16px;">
            <!-- Checkbox: Use raw SVG as-is -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px; border-radius: 12px;">
                <div>
                    <div style="font-size: 13px; font-weight: 600; color: var(--text-primary);">Use raw SVG as-is</div>
                    <div style="font-size: 11px; color: var(--text-secondary);">Bypass backdrop backgrounds and custom scaling</div>
                </div>
                <label class="switch" style="position: relative; display: inline-block; width: 40px; height: 22px;">
                    <input type="checkbox" id="chkRawSvgUpload" style="opacity: 0; width: 0; height: 0;">
                    <span class="switch-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(255,255,255,0.1); transition: .3s; border-radius: 22px; border: 1px solid var(--border-color);"></span>
                </label>
            </div>

            <!-- Group: Customizer Controls (Hidden if Raw SVG is checked) -->
            <div id="svg-customizer-fields" class="space-y-4">
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 12px; margin-bottom: 8px; font-weight: 600; display: block;">SVG Background Color</label>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <input type="color" id="inpBgColorUpload" value="#6366f1" style="height: 36px; padding: 2px; width: 60px; flex-shrink: 0; background: none; border: none; cursor: pointer;">
                        <span id="txtBgColorUpload" style="font-size: 13px; font-family: monospace; color: var(--text-secondary);">#6366f1</span>
                    </div>
                    <div class="color-presets" data-target="inpBgColorUpload">
                        <div class="color-chip" style="background-color: #ffb7c5;" data-color="#ffb7c5" title="Sakura Pink"></div>
                        <div class="color-chip" style="background-color: #fbcfe8;" data-color="#fbcfe8" title="Pastel Pink"></div>
                        <div class="color-chip" style="background-color: #ff8a65;" data-color="#ff8a65" title="Peach Coral"></div>
                        <div class="color-chip" style="background-color: #fef08a;" data-color="#fef08a" title="Butter Yellow"></div>
                        <div class="color-chip" style="background-color: #a7f3d0;" data-color="#a7f3d0" title="Sage Green"></div>
                        <div class="color-chip" style="background-color: #0abab5;" data-color="#0abab5" title="Tiffany Blue"></div>
                        <div class="color-chip" style="background-color: #81e6d9;" data-color="#81e6d9" title="Soft Turquoise"></div>
                        <div class="color-chip" style="background-color: #c084fc;" data-color="#c084fc" title="Lilac Lavender"></div>
                        <div class="color-chip" style="background-color: #6366f1;" data-color="#6366f1" title="Indigo Blue"></div>
                        <div class="color-chip" style="background-color: #64748b;" data-color="#64748b" title="Slate Gray"></div>
                        <div class="color-chip" style="background-color: #1e1b4b;" data-color="#1e1b4b" title="Midnight Blue"></div>
                        <div class="color-chip" style="background-color: #121214;" data-color="#121214" title="Dark Charcoal"></div>
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <label style="font-size: 12px; margin-bottom: 8px; font-weight: 600; display: block;">SVG Icon Color</label>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <input type="color" id="inpFgColorUpload" value="#ffffff" style="height: 36px; padding: 2px; width: 60px; flex-shrink: 0; background: none; border: none; cursor: pointer;">
                        <span id="txtFgColorUpload" style="font-size: 13px; font-family: monospace; color: var(--text-secondary);">#ffffff</span>
                    </div>
                    <div class="color-presets" data-target="inpFgColorUpload">
                        <div class="color-chip" style="background-color: #ffffff;" data-color="#ffffff" title="Pure White"></div>
                        <div class="color-chip" style="background-color: #f3f4f6;" data-color="#f3f4f6" title="Pastel Gray"></div>
                        <div class="color-chip" style="background-color: #cbd5e1;" data-color="#cbd5e1" title="Slate Gray"></div>
                        <div class="color-chip" style="background-color: #ffb7c5;" data-color="#ffb7c5" title="Sakura Pink"></div>
                        <div class="color-chip" style="background-color: #fbcfe8;" data-color="#fbcfe8" title="Pastel Pink"></div>
                        <div class="color-chip" style="background-color: #ff8a65;" data-color="#ff8a65" title="Peach Coral"></div>
                        <div class="color-chip" style="background-color: #fef08a;" data-color="#fef08a" title="Butter Yellow"></div>
                        <div class="color-chip" style="background-color: #a7f3d0;" data-color="#a7f3d0" title="Sage Green"></div>
                        <div class="color-chip" style="background-color: #0abab5;" data-color="#0abab5" title="Tiffany Blue"></div>
                        <div class="color-chip" style="background-color: #81e6d9;" data-color="#81e6d9" title="Soft Turquoise"></div>
                        <div class="color-chip" style="background-color: #c084fc;" data-color="#c084fc" title="Lilac Lavender"></div>
                        <div class="color-chip" style="background-color: #6366f1;" data-color="#6366f1" title="Indigo Blue"></div>
                    </div>
                </div><div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <label style="font-size: 12px; font-weight: 600;">Vector Scale</label>
                                    <span id="txtSizeUpload" style="font-size: 11px; font-weight: bold; color: var(--primary-accent);">100%</span>
                                </div>
                                <input id="inpSizeUpload" type="range" min="50" max="150" step="5" value="100" style="width: 100%; accent-color: var(--primary-accent); cursor: pointer;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Generate Pane -->
                <div class="icon-tab-pane" id="pane-icon-generate" style="display:none;">
                    <div class="icon-preview-container" style="height: 120px; border-radius: 12px; border: 1px solid var(--border-color); background: rgba(0,0,0,0.2); margin-bottom: 12px; display: flex; align-items: center; justify-content: center; padding: 10px;">
                        <img id="genPreviewStudio" src="" alt="Generated Preview" style="display:none; max-height: 100%; max-width: 100%; object-fit: contain; border-radius: 8px;">
                        <div id="genPlaceholderStudio" style="color: var(--text-secondary); font-size: 13px;">Select an icon below</div>
                    </div>
                    <div class="form-group" style="display: flex; flex-direction: column; gap: 12px;">
                       <div style="margin-bottom: 12px;">
                         <label style="font-size: 12px; margin-bottom: 8px; font-weight: 600; display: block;">Background Color</label>
                         <div style="display: flex; gap: 12px; align-items: center;">
                           <input type="color" id="inpBgColorStudio" value="#6366f1" style="height: 36px; padding: 2px; width: 60px; flex-shrink: 0; background: none; border: none; cursor: pointer;">
                           <span id="txtBgColorStudio" style="font-size: 13px; font-family: monospace; color: var(--text-secondary);">#6366f1</span>
                         </div>
                         <div class="color-presets" data-target="inpBgColorStudio">
                             <div class="color-chip" style="background-color: #ffb7c5;" data-color="#ffb7c5" title="Sakura Pink"></div>
                             <div class="color-chip" style="background-color: #fbcfe8;" data-color="#fbcfe8" title="Pastel Pink"></div>
                             <div class="color-chip" style="background-color: #ff8a65;" data-color="#ff8a65" title="Peach Coral"></div>
                             <div class="color-chip" style="background-color: #fef08a;" data-color="#fef08a" title="Butter Yellow"></div>
                             <div class="color-chip" style="background-color: #a7f3d0;" data-color="#a7f3d0" title="Sage Green"></div>
                             <div class="color-chip" style="background-color: #0abab5;" data-color="#0abab5" title="Tiffany Blue"></div>
                             <div class="color-chip" style="background-color: #81e6d9;" data-color="#81e6d9" title="Soft Turquoise"></div>
                             <div class="color-chip" style="background-color: #c084fc;" data-color="#c084fc" title="Lilac Lavender"></div>
                             <div class="color-chip" style="background-color: #6366f1;" data-color="#6366f1" title="Indigo Blue"></div>
                             <div class="color-chip" style="background-color: #64748b;" data-color="#64748b" title="Slate Gray"></div>
                             <div class="color-chip" style="background-color: #1e1b4b;" data-color="#1e1b4b" title="Midnight Blue"></div>
                             <div class="color-chip" style="background-color: #121214;" data-color="#121214" title="Dark Charcoal"></div>
                         </div>
                       </div>
                       <div style="margin-bottom: 12px;">
                         <label style="font-size: 12px; margin-bottom: 8px; font-weight: 600; display: block;">Icon Color</label>
                         <div style="display: flex; gap: 12px; align-items: center;">
                           <input type="color" id="inpFgColorStudio" value="#ffffff" style="height: 36px; padding: 2px; width: 60px; flex-shrink: 0; background: none; border: none; cursor: pointer;">
                           <span id="txtFgColorStudio" style="font-size: 13px; font-family: monospace; color: var(--text-secondary);">#ffffff</span>
                         </div>
                         <div class="color-presets" data-target="inpFgColorStudio">
                             <div class="color-chip" style="background-color: #ffffff;" data-color="#ffffff" title="Pure White"></div>
                             <div class="color-chip" style="background-color: #f3f4f6;" data-color="#f3f4f6" title="Pastel Gray"></div>
                             <div class="color-chip" style="background-color: #cbd5e1;" data-color="#cbd5e1" title="Slate Gray"></div>
                             <div class="color-chip" style="background-color: #ffb7c5;" data-color="#ffb7c5" title="Sakura Pink"></div>
                             <div class="color-chip" style="background-color: #fbcfe8;" data-color="#fbcfe8" title="Pastel Pink"></div>
                             <div class="color-chip" style="background-color: #ff8a65;" data-color="#ff8a65" title="Peach Coral"></div>
                             <div class="color-chip" style="background-color: #fef08a;" data-color="#fef08a" title="Butter Yellow"></div>
                             <div class="color-chip" style="background-color: #a7f3d0;" data-color="#a7f3d0" title="Sage Green"></div>
                             <div class="color-chip" style="background-color: #0abab5;" data-color="#0abab5" title="Tiffany Blue"></div>
                             <div class="color-chip" style="background-color: #81e6d9;" data-color="#81e6d9" title="Soft Turquoise"></div>
                             <div class="color-chip" style="background-color: #c084fc;" data-color="#c084fc" title="Lilac Lavender"></div>
                             <div class="color-chip" style="background-color: #6366f1;" data-color="#6366f1" title="Indigo Blue"></div>
                         </div>
                       </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 8px;">
                       <input type="text" id="inpIconSearchStudio" placeholder="Search Lucide icons..." style="padding: 10px; font-size: 13px;">
                    </div>
                    <div id="iconGridStudio" class="icon-grid" style="margin-bottom: 16px;">
                       <div style="grid-column: 1 / -1; text-align: center; color: var(--text-secondary); font-size: 12px; padding: 12px;">Loading library...</div>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal('modal-upload-icon')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-submit-icon">Save Icon</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="modal-upload-key">
        <div class="modal-content" style="max-width: 500px;">
            <h3>Configure Signing Key</h3>
            
            <div class="tab-menu" id="signing-tab-menu" style="display: flex; gap: 4px; background: var(--bg-color); padding: 4px; border-radius: 8px; margin-bottom: 16px; border: 1px solid var(--border-color);">
                <button type="button" class="tab-btn active" onclick="App.switchSigningTab('debug')" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 500; transition: all 0.2s;">Debug Key</button>
                <button type="button" class="tab-btn" onclick="App.switchSigningTab('upload')" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 500; transition: all 0.2s;">Upload Key</button>
                <button type="button" class="tab-btn" id="tab-btn-generate" onclick="App.switchSigningTab('generate')" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 500; transition: all 0.2s;">Generate Key</button>
                <button type="button" class="tab-btn" id="tab-btn-active" onclick="App.switchSigningTab('active')" style="flex: 1; padding: 8px 4px; font-size: 11px; border: none; background: none; color: var(--text-secondary); cursor: pointer; border-radius: 6px; font-weight: 500; transition: all 0.2s; display: none;">Custom Key</button>
            </div>

            <form id="form-upload-key" onsubmit="event.preventDefault(); App.submitUploadKey();">
                <input type="hidden" id="key-project-id">
                <input type="hidden" id="inp-signing-mode" value="debug">

                <!-- Tab Pane: Debug -->
                <div class="signing-tab-pane" id="pane-signing-debug">
                    <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px; line-height: 1.4;">
                        Default debug signature key is selected. Safe for testing but not suitable for production app stores.
                    </p>
                    <div class="form-group">
                        <label>Keystore Password</label>
                        <input type="text" value="android" readonly style="opacity: 0.6; font-family: monospace;">
                    </div>
                    <div class="form-group">
                        <label>Key Alias</label>
                        <input type="text" value="androiddebugkey" readonly style="opacity: 0.6; font-family: monospace;">
                    </div>
                    <div class="form-group">
                        <label>Key Password</label>
                        <input type="text" value="android" readonly style="opacity: 0.6; font-family: monospace;">
                    </div>
                </div>

                <!-- Tab Pane: Upload -->
                <div class="signing-tab-pane" id="pane-signing-upload" style="display: none;">
                    <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px; line-height: 1.4;">
                        To sign with an existing key, upload your keystore file and specify its credentials so the builder can unlock it.
                    </p>
                    <div class="form-group" id="group-key-file">
                        <label>Keystore File (.keystore / .jks)</label>
                        <input type="file" id="inp-key-file" accept=".keystore,.jks">
                    </div>
                    <div class="form-group">
                        <label>Keystore Password</label>
                        <input type="password" id="inp-key-storepass" placeholder="Password to decrypt keystore">
                    </div>
                    <div class="form-group">
                        <label>Key Alias</label>
                        <input type="text" id="inp-key-alias" placeholder="The key pair alias name" autocapitalize="none" autocorrect="off" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="form-group">
                        <label>Key Password</label>
                        <input type="password" id="inp-key-pass" placeholder="Leave blank if same as Keystore Password">
                    </div>
                </div>

                <!-- Tab Pane: Generate -->
                <div class="signing-tab-pane" id="pane-signing-generate" style="display: none;">
                    <div id="generate-locked-msg" style="display: none; padding: 12px; background: rgba(255, 159, 10, 0.1); border: 1px solid rgba(255, 159, 10, 0.2); border-radius: 6px; font-size: 13px; color: #ff9f0a; line-height: 1.4; margin-bottom: 16px;">
                        A custom key is already active. Please delete your active custom key first if you want to generate a new one.
                    </div>
                    <div id="generate-form-fields">
                        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px; line-height: 1.4;">
                            Define credentials for a brand-new self-signed production-grade key generated natively on the server.
                        </p>
                        <div class="form-group">
                            <label>Keystore Password (New Key)</label>
                            <input type="password" id="inp-gen-storepass" placeholder="Minimum 6 characters">
                        </div>
                        <div class="form-group">
                            <label>Key Alias (New Key)</label>
                            <input type="text" id="inp-gen-alias" placeholder="e.g. my-alias" autocapitalize="none" autocorrect="off" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="form-group">
                            <label>Key Password (New Key)</label>
                            <input type="password" id="inp-gen-pass" placeholder="Leave blank if same as Keystore Password">
                        </div>
                    </div>
                </div>

                <!-- Tab Pane: Active Custom Key -->
                <div class="signing-tab-pane" id="pane-signing-active" style="display: none;">
                    <div id="key-status-container" style="margin-bottom: 16px; padding: 12px; border-radius: 6px; font-size: 13px; display: flex; align-items: center; justify-content: space-between; box-sizing: border-box; background: rgba(40, 167, 69, 0.1); border: 1px solid rgba(40, 167, 69, 0.2);">
                        <span id="key-status-text" style="font-weight: 500; color: #34c759;">🟢 Custom key uploaded and active</span>
                        <button type="button" class="btn" id="btn-delete-key" style="background: var(--danger); color: #fff; padding: 4px 8px; font-size: 11px; font-weight: 600; cursor: pointer; border-radius: 4px;" onclick="App.deleteKeystore()">Delete Key</button>
                    </div>
                    <div class="form-group">
                        <label>Active Keystore Password</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="password" id="active-key-storepass-display" readonly style="opacity: 0.6; font-family: monospace; flex: 1;">
                            <button type="button" class="btn btn-secondary" onclick="App.togglePasswordVisibility('active-key-storepass-display')" style="padding: 0 12px; font-size: 12px;">Show</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Active Key Alias</label>
                        <input type="text" id="active-key-alias-display" readonly style="opacity: 0.6; font-family: monospace;">
                    </div>
                    <div class="form-group">
                        <label>Active Key Password</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="password" id="active-key-pass-display" readonly style="opacity: 0.6; font-family: monospace; flex: 1;">
                            <button type="button" class="btn btn-secondary" onclick="App.togglePasswordVisibility('active-key-pass-display')" style="padding: 0 12px; font-size: 12px;">Show</button>
                        </div>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal('modal-upload-key')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-submit-key">Use Selected Key</button>
                </div>
            </form>
        </div>
    </div>

    <script src="js/app.js?v=<?php echo $v; ?>"></script>
</body>
</html>