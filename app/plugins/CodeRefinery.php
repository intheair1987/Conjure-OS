<?php
// ==============================================================================
// PLUGIN: CodeRefinery
// Purpose: Uses the native PHP engine to interpret escaped strings and convert 
// them into clean NOWDOC blocks. Effectively "Refines" spaghetti code.
// ==============================================================================

// --- 1. BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {

    // A. LIST PLUGINS
    if ($_POST['plugin_action'] === 'cr_list_plugins') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $files = glob(dirname(__DIR__) . "/plugins/*.php");
        $list = array_map(function($f) { return basename($f); }, $files);
        sort($list);
        echo json_encode(['status' => 'success', 'plugins' => $list]);
        exit;
    }

    // B. ANALYZE & PREVIEW
    if ($_POST['plugin_action'] === 'cr_analyze') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $filename = basename($_POST['file']);
        $path = dirname(__DIR__) . "/plugins/" . $filename;
        if (!file_exists($path)) { echo json_encode(['status' => 'error', 'message' => 'File not found']); exit; }
        
        $content = file_get_contents($path);
        $result = cr_refine_content($content);
        
        echo json_encode([
            'status' => 'success', 
            'original' => $content, 
            'refined' => $result['content'],
            'changes' => $result['changes']
        ]);
        exit;
    }

    // C. APPLY REFINEMENT (SINGLE OR BATCH)
    if ($_POST['plugin_action'] === 'cr_apply') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $filename = basename($_POST['file']);
        $path = dirname(__DIR__) . "/plugins/" . $filename;
        $newContent = $_POST['content'];
        
        $refinery_backup_dir = dirname(__DIR__) . '/backups/refinery';
        if (!is_dir($refinery_backup_dir)) mkdir($refinery_backup_dir, 0777, true);

        if (file_exists($path)) {
            $backup_path = $refinery_backup_dir . '/' . $filename . '_' . date('Ymd_His') . '.bak';
            copy($path, $backup_path);
            file_put_contents($path, $newContent);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Save failed']);
        }
        exit;
    }

    // D. REFINE ALL PLUGINS
    if ($_POST['plugin_action'] === 'cr_refine_all') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $files = glob(dirname(__DIR__) . "/plugins/*.php");
        $results = [];
        $totalChanges = 0;

        $refinery_backup_dir = dirname(__DIR__) . '/backups/refinery';
        if (!is_dir($refinery_backup_dir)) mkdir($refinery_backup_dir, 0777, true);

        foreach ($files as $path) {
            $filename = basename($path);
            if ($filename === 'CodeRefinery.php') continue; // Don't refine self during loop

            $content = file_get_contents($path);
            $refined = cr_refine_content($content);

            if ($refined['changes'] > 0) {
                // Backup
                $backup_path = $refinery_backup_dir . '/' . $filename . '_' . date('Ymd_His') . '.bak';
                copy($path, $backup_path);
                // Save
                file_put_contents($path, $refined['content']);
                
                $results[] = "Refined $filename (" . $refined['changes'] . " blocks)";
                $totalChanges += $refined['changes'];
            }
        }

        echo json_encode([
            'status' => 'success', 
            'summary' => $totalChanges . " blocks refined across " . count($results) . " files.",
            'details' => $results
        ]);
        exit;
    }
}

/**
 * THE REFINERY CORE
 * Tokenizer Edition: Uses PHP's built-in lexer to identify strings.
 * This is the most accurate method possible as it is the same engine that runs the app.
 */
function cr_refine_content($code) {
    $changes = 0;
    $tokens = token_get_all($code);
    $newCode = "";
    
    $targets = ['$plugin_js', '$plugin_settings_map', '$plugin_overlays'];

    $i = 0;
    while ($i < count($tokens)) {
        $token = $tokens[$i];

        // Is it a target variable?
        if (is_array($token) && $token[0] === T_VARIABLE && in_array($token[1], $targets)) {
            $j = $i + 1;
            $prefix = $token[1];
            
            // Look ahead for assignment (= or .=) and then a string
            while ($j < count($tokens) && (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE)) {
                $prefix .= $tokens[$j][1];
                $j++;
            }

            // Check for = or CONCAT_EQUAL (.=)
            if ($j < count($tokens) && (
                $tokens[$j] === '=' || 
                (is_array($tokens[$j]) && $tokens[$j][0] === T_CONCAT_EQUAL)
            )) {
                $prefix .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                $j++;

                // Skip whitespace after operator
                while ($j < count($tokens) && (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE)) {
                    $prefix .= $tokens[$j][1];
                    $j++;
                }

                // --- REFINERY ENGINE V4 (HYBRID / VARIABLE AWARE) ---
                if ($j < count($tokens)) {
                    $literalValue = null;
                    $foundMatch = false;

                    // Target Encapsed Strings ('...' or "...")
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $raw = $tokens[$j][1];
                        
                        // SAFETY: If double-quoted and contains a '$', skip it.
                        if ($raw[0] === '"' && strpos($raw, '$') !== false) {
                            $foundMatch = false;
                        } 
                        // Process if it is a standard single or double quoted string
                        elseif ($raw[0] === "'" || $raw[0] === '"') {
                            try {
                                $literalValue = eval("return $raw;");
                                $foundMatch = true;
                            } catch (Throwable $e) {}
                        }
                    } 
                    // HEREDOC (T_START_HEREDOC) is explicitly ignored here to preserve dynamic blocks.

                    if ($foundMatch && $literalValue !== null) {
                        $tag = (strpos($token[1], 'plugin_js') !== false) ? 'JS' : 'HTML';
                        $literalValue = rtrim($literalValue);
                        if (substr($literalValue, 0, 1) === "\n") $literalValue = substr($literalValue, 1);
                        
                        // Normalize Prefix Whitespace (e.g. ".=  " -> ".= ")
                        $cleanPrefix = preg_replace('/\s+$/', ' ', $prefix);
                        $newCode .= "{$cleanPrefix}<<<'{$tag}'\n{$literalValue}\n{$tag}";
                        
                        $i = $j; $changes++; $i++;
                        continue;
                    }
                }
            }
        }

        // Append current token as-is
        $newCode .= is_array($token) ? $token[1] : $token;
        $i++;
    }

    return ['content' => $newCode, 'changes' => $changes];
}

// --- 2. SETTINGS UI ---
$plugin_settings_map['CodeRefinery'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Native Code Refinery</label>
        <div class="setting-desc">
            Convert escaped "Spaghetti" strings into clean, readable <strong>NOWDOC</strong> blocks using the server's native PHP engine.
        </div>
        
        <div style="display:flex; gap:10px; margin-top:12px;">
            <button onclick="crOpenPicker()" class="text-btn" style="
                flex:1; background: var(--input-bg); color: var(--input-text); 
                border-radius: 12px; padding: 14px; font-weight: 600; border: 1px solid var(--border-color);
            ">
                Select Plugin
            </button>
            <button onclick="crRefineAll()" class="text-btn" style="
                flex:1; background: var(--primary); color: white; 
                border-radius: 12px; padding: 14px; font-weight: 600; box-shadow: 0 4px 122, 255, 0.3);
            ">
                Refine All Plugins
            </button>
        </div>
        <div id="cr-batch-status" style="font-size:11px; color:#8E8E93; margin-top:10px; text-align:center; height:14px;"></div>
    </div>
HTML;

// --- 3. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- CODE REFINERY JS ---

let crSelectedFile = null;
let crRefinedContent = null;

window.crOpenPicker = async function() {
    try {
        const fd = new FormData();
        fd.append("plugin_action", "cr_list_plugins");
        const res = await fetch("index.php", { method: "POST", body: fd });
        const data = await res.json();
        
        if (data.status === "success") {
            const options = data.plugins.map(p => ({ label: "🧩 " + p, value: p }));
            if (window.openPicker) {
                window.openPicker("Refine Plugin Code", options, null, (val) => {
                    crAnalyzePlugin(val);
                });
            } else { alert("SharedUI required"); }
        }
    } catch(e) { alert("Failed to load plugin list"); }
};

async function crAnalyzePlugin(file) {
    crSelectedFile = file;
    const toast = document.getElementById("toast");
    if(toast) { toast.innerText = "Analyzing engine..."; toast.classList.add("show"); }

    const fd = new FormData();
    fd.append("plugin_action", "cr_analyze");
    fd.append("file", file);

    try {
        const res = await fetch("index.php", { method: "POST", body: fd });
        const data = await res.json();
        if(toast) toast.classList.remove("show");

        if (data.status === "success") {
            crRefinedContent = data.refined;
            crShowPreview(data);
        }
    } catch(e) { alert("Analysis failed"); }
}

function crShowPreview(data) {
    const options = [
        { 
            label: `<div style="text-align:center; padding:10px;">
                        <div style="font-size:18px; font-weight:800; color:var(--primary);">${data.changes} Blocks Refined</div>
                        <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">PHP interpretation complete. Click to apply.</div>
                    </div>`, 
            value: "apply",
            noStyle: true 
        }
    ];

    if (data.changes === 0) {
        alert("This file is already refined or contains no compatible string blocks.");
        return;
    }

    window.openPicker("Refinery Preview: " + crSelectedFile, options, null, (val) => {
        if (val === "apply") {
            if (confirm("DANGER: This will overwrite " + crSelectedFile + ". It is highly recommended to create a System Checkpoint first.\n\nApply refinement?")) {
                crApplyRefinement();
            }
        }
    });
}

async function crApplyRefinement() {
    const fd = new FormData();
    fd.append("plugin_action", "cr_apply");
    fd.append("file", crSelectedFile);
    fd.append("content", crRefinedContent);

    try {
        const res = await fetch("index.php", { method: "POST", body: fd });
        const data = await res.json();
        if (data.status === "success") {
            alert("Refinement successful! The app will now reload to initialize the clean code.");
            location.reload();
        }
    } catch(e) { alert("Save failed"); }
}

window.crRefineAll = async function() {
    if (!confirm("DANGER: This will process ALL plugin files. \n\nBackups will be created in /backups/refinery/, but it is highly recommended to create a System Checkpoint first.\n\nProceed?")) return;

    const status = document.getElementById("cr-batch-status");
    if(status) status.innerText = "Refining entire codebase...";
    
    const fd = new FormData();
    fd.append("plugin_action", "cr_refine_all");

    try {
        const res = await fetch("index.php", { method: "POST", body: fd });
        const data = await res.json();
        
        if (data.status === "success") {
            alert("Batch Refinement Complete!\n\n" + data.summary + "\n\nThe app will now reload.");
            location.reload();
        } else {
            alert("Batch failed: " + data.message);
            if(status) status.innerText = "";
        }
    } catch(e) { 
        alert("Connection error during batch process."); 
        if(status) status.innerText = "";
    }
};
JS;
