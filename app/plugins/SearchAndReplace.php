<?php
// ==============================================================================
// PLUGIN: Search & Replace (Refactoring Tool)
// Purpose: System-wide bulk text replacement.
// Features: 
// 1. Dry Run / Preview Mode (Safety First).
// 2. recursive scan of all source files (.php, .js, .css, .html).
// 3. Excludes data/media directories to prevent corruption.
// ==============================================================================

// --- CONFIG ---
function sr_get_exclusions() {
    return [
        'recordings', 
        'transcriptions', 
        'backups', 
        '.git', 
        'vendor', 
        'node_modules', 
        'conjure.db', 
        'conjure.db-journal',
        'recovery.php',
        'vault',        // FileVault storage
        'demo',         // Demo mode data
        'fitbit',       // Fitbit logs
        'calorie-tracker' // Calorie logs
    ];
}

// --- BACKEND HANDLERS ---

if (isset($_POST['plugin_action'])) {

    // ACTION: SCAN (PREFLIGHT)
    if ($_POST['plugin_action'] === 'sr_scan') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        // 1. Resource Hardening
        @ini_set('memory_limit', '256M');
        @set_time_limit(60);

        $find = $_POST['find'] ?? '';
        $isRegex = ($_POST['is_regex'] === 'true');

        // Auto-wrap regex in delimiters if missing
        if ($isRegex && !empty($find) && !preg_match('/^([^\w\s\\\\]).*\1[a-z]*$/i', $find)) {
            $find = '/' . str_replace('/', '\/', $find) . '/';
        }
        
        if (empty($find)) {
            echo json_encode(['status' => 'error', 'message' => 'Search term required']);
            exit;
        }

        $root = CJOS_PATH_ROOT;
        $exclusions = sr_get_exclusions();
        $results = [];
        $totalMatches = 0;
        $filesScanned = 0;
        $isCapped = false;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), 
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            if ($totalMatches >= 100 || $filesScanned > 1000) {
                if ($totalMatches >= 100) $isCapped = true;
                break;
            }
            if ($file->isDir()) continue;
            
            $relPath = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
            
            // FAST PATH SKIP: Avoid deep data directories entirely
            if (strpos($relPath, 'recordings/') !== false || 
                strpos($relPath, 'vault/') !== false || 
                strpos($relPath, 'backups/') !== false ||
                strpos($relPath, 'demo/') !== false) continue;

            $parts = explode(DIRECTORY_SEPARATOR, $relPath);
            if (in_array($parts[0], $exclusions)) continue; 
            
            $ext = pathinfo($relPath, PATHINFO_EXTENSION);
            if (!in_array($ext, ['php', 'js', 'css', 'html', 'json', 'txt', 'md'])) continue;

            $filesScanned++;
            $fullPath = $file->getPathname();
            if (filesize($fullPath) > 512 * 1024) continue; // 512KB limit for speed

            $content = file_get_contents($fullPath);
            if ($content === false) continue;
            
            $fileMatches = [];

            if ($isRegex) {
                try {
                    if (preg_match_all($find, $content, $matches, PREG_OFFSET_CAPTURE)) {
                        foreach ($matches[0] as $m) {
                            if ($totalMatches >= 300) break 2; // Hard cap at 300 for stability
                            
                            $offset = $m[1];
                            $matchText = $m[0];
                            $totalMatches++;
                            
                            $start = max(0, $offset - 60); // Smaller snippets
                            $end = min(strlen($content), $offset + strlen($matchText) + 60);
                            $snippet = substr($content, $start, $end - $start);
                            
                            $fileMatches[] = [
                                'offset' => $offset, 'text' => $matchText, 'snippet' => $snippet,
                                'line' => substr_count(substr($content, 0, $offset), "\n") + 1
                            ];
                        }
                    }
                } catch (Exception $e) {
                    while (ob_get_level()) ob_end_clean();
                    echo json_encode(['status' => 'error', 'message' => 'Invalid Regex']); exit;
                }
            } else {
                $offset = 0;
                while (($offset = strpos($content, $find, $offset)) !== false) {
                    if ($totalMatches >= 300) break 2; // Hard cap at 300 for stability
                    
                    $totalMatches++;
                    $start = max(0, $offset - 60); // Smaller snippets
                    $end = min(strlen($content), $offset + strlen($find) + 60);
                    $snippet = substr($content, $start, $end - $start);
                    
                    $fileMatches[] = [
                        'offset' => $offset, 'text' => $find, 'snippet' => $snippet,
                        'line' => substr_count(substr($content, 0, $offset), "\n") + 1
                    ];
                    $offset += strlen($find);
                }
            }

            if (!empty($fileMatches)) {
                // Sanitize Snippets for JSON safety (Force UTF-8)
                foreach ($fileMatches as &$fm) {
                    $fm['snippet'] = mb_convert_encoding($fm['snippet'], 'UTF-8', 'UTF-8');
                    $fm['text'] = mb_convert_encoding($fm['text'], 'UTF-8', 'UTF-8');
                }
                $results[] = [
                    'file' => $relPath,
                    'is_writable' => is_writable($fullPath),
                    'matches' => $fileMatches
                ];
            }
            
            unset($content); // Free memory immediately
        }

        $response = json_encode([
            'status' => 'success', 
            'results' => $results, 
            'total' => $totalMatches, 
            'scanned' => $filesScanned,
            'is_capped' => $isCapped
        ], JSON_INVALID_UTF8_SUBSTITUTE);

        if ($response === false) {
            echo json_encode(['status' => 'error', 'message' => 'JSON Encoding Failed: ' . json_last_error_msg()]);
        } else {
            echo $response;
        }
        exit;
    }

    // ACTION: EXECUTE (REPLACE)
    if ($_POST['plugin_action'] === 'sr_execute') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        @ini_set('memory_limit', '512M');
        @set_time_limit(300); // 5 minutes for deep system refactor

        $find = $_POST['find'] ?? '';
        $replace = $_POST['replace'] ?? '';
        $isRegex = ($_POST['is_regex'] === 'true');
        $isSystemWide = (isset($_POST['system_wide']) && $_POST['system_wide'] === 'true');

        // Auto-wrap regex in delimiters if missing
        if ($isRegex && !empty($find) && !preg_match('/^([^\w\s\\\\]).*\1[a-z]*$/i', $find)) {
            $find = '/' . str_replace('/', '\/', $find) . '/';
        }
        
        $targetFiles = [];
        if ($isSystemWide) {
            // RE-SCAN ENTIRE SYSTEM (Uncapped)
            $root = CJOS_PATH_ROOT;
            $exclusions = sr_get_exclusions();
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), 
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
                if (strpos($rel, 'recordings/') !== false || strpos($rel, 'vault/') !== false || 
                    strpos($rel, 'backups/') !== false || strpos($rel, 'demo/') !== false) continue;
                $parts = explode(DIRECTORY_SEPARATOR, $rel);
                if (in_array($parts[0], $exclusions)) continue;
                $ext = pathinfo($rel, PATHINFO_EXTENSION);
                if (!in_array($ext, ['php', 'js', 'css', 'html', 'json', 'txt', 'md'])) continue;
                $targetFiles[] = $rel;
            }
        } else {
            $targetFiles = json_decode($_POST['files'], true) ?: [];
        }

        if (empty($find) || empty($targetFiles)) {
            echo json_encode(['status' => 'error', 'message' => 'No files to process.']);
            exit;
        }

        $root = CJOS_PATH_ROOT;
        $processed = 0;
        $totalChanges = 0;

        foreach ($targetFiles as $relPath) {
            $fullPath = $root . DIRECTORY_SEPARATOR . $relPath;
            if (file_exists($fullPath) && is_writable($fullPath)) {
                $content = file_get_contents($fullPath);
                $newContent = $content;

                if ($isRegex) {
                    $newContent = preg_replace($find, $replace, $content, -1, $count);
                    $totalChanges += $count;
                } else {
                    $newContent = str_replace($find, $replace, $content, $count);
                    $totalChanges += $count;
                }

                if ($newContent !== $content) {
                    file_put_contents($fullPath, $newContent);
                    $processed++;
                }
                unset($content); unset($newContent);
            }
        }

        echo json_encode(['status' => 'success', 'processed' => $processed, 'changes' => $totalChanges]);
        exit;
    }
}

// --- DASHBOARD TOOL REGISTRATION ---
if (!isset($plugin_tools)) $plugin_tools = [];
$plugin_tools[] = [
    'name' => 'Refactor Lab',
    'desc' => 'System-wide Search & Replace',
    'sui_icon' => 'search',
    'icon_color' => 'var(--primary)',
    'color' => 'var(--btn-bg)',
    'action' => 'srOpenStudio()'
];

// --- SETTINGS UI ---
$plugin_settings_map['SearchAndReplace'] = <<<'HTML'
    <div class="setting-item">
        <div class="setting-text-wrap">
            <label class="setting-label">Refactor Lab</label>
            <span class="setting-desc">Launch the system-wide search and replace studio.</span>
        </div>
        <button onclick="srOpenStudio()" class="text-btn" style="color:var(--primary); font-weight:600;">Launch Studio</button>
    </div>
HTML;

// --- JS LOGIC ---
$plugin_js .= <<<'JS'
// --- SEARCH AND REPLACE STUDIO JS ---

let srScanResults = [];
let srCheckpointCreated = false;

window.srOpenStudio = function() {
    window.sui.openStudio({
        id: 'sr-studio',
        title: 'Refactor Lab',
        content: `
            <style>
                .sr-input-group { background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 16px; padding: 16px; margin-bottom: 20px; box-sizing: border-box; }
                .sr-textarea { width: 100%; height: 80px; padding: 12px; border-radius: 10px; border: 1px solid var(--border-color); font-family: monospace; font-size: 13px; background: var(--input-bg); color: var(--input-text); resize: vertical; margin-top: 8px; box-sizing: border-box; }
                .sr-preflight-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px; padding: 16px; margin-bottom: 12px; box-sizing: border-box; overflow: hidden; max-width: 100%; }
                .sr-snippet { background: rgba(0,0,0,0.03); padding: 10px; border-radius: 8px; font-family: monospace; font-size: 11px; white-space: pre-wrap; word-break: break-all; overflow-wrap: anywhere; margin-top: 8px; border-left: 3px solid var(--primary); }
                .sr-match-hl { background: var(--primary); color: white; padding: 0 2px; border-radius: 2px; font-weight: 700; }
                .sr-safety-banner { background: var(--warn-bg); color: var(--warn-text); padding: 12px; border-radius: 12px; margin-bottom: 20px; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 10px; box-sizing: border-box; }
            </style>

            <div class="sr-input-group">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label style="font-size:11px; font-weight:800; text-transform:uppercase; color:var(--text-secondary);">Search Pattern</label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:11px; font-weight:600;">Regex</span>
                        <label class="switch" style="width:34px; height:20px;">
                            <input type="checkbox" id="sr-studio-regex">
                            <span class="slider" style="border-radius:20px;"></span>
                        </label>
                    </div>
                </div>
                <textarea id="sr-studio-find" class="sr-textarea" placeholder="What are we looking for?"></textarea>
                
                <label style="font-size:11px; font-weight:800; text-transform:uppercase; color:var(--text-secondary); display:block; margin-top:16px;">Replacement Text</label>
                <textarea id="sr-studio-replace" class="sr-textarea" placeholder="What should it become?"></textarea>
                
                <button onclick="srStudioScan()" class="text-btn" style="width:100%; background:var(--primary); color:white; border-radius:12px; padding:14px; font-weight:700; margin-top:16px;">Run Preflight Scan</button>
            </div>

            <div id="sr-studio-results-area" style="display:none;">
                <div id="sr-safety-gate" class="sr-safety-banner">
                    <span data-sui-icon="alert-triangle" data-sui-size="18"></span>
                    <div style="flex:1;">A System Checkpoint is highly recommended before bulk refactoring.</div>
                    <button onclick="srCreateSafetyCheckpoint()" class="text-btn" style="background:var(--primary); color:white; padding:6px 12px; border-radius:8px; font-size:11px;">Create Checkpoint</button>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div id="sr-studio-summary" style="font-size:13px; font-weight:700; color:var(--text-primary);"></div>
                    <button id="sr-studio-commit-btn" onclick="srStudioExecute()" class="text-btn" style="background:var(--danger); color:white; border-radius:10px; padding:10px 20px; font-weight:700; opacity:0.5; pointer-events:none;">Commit All Changes</button>
                </div>

                <div id="sr-studio-cards"></div>
            </div>
        `,
        onSetup: (content) => {
            if (window.suiHydrateIcons) window.suiHydrateIcons(content);
        }
    });
};

window.srCreateSafetyCheckpoint = async function() {
    if (typeof window.scCreateCheckpoint !== 'function') {
        window.sui.toast("Checkpoint system unavailable");
        return;
    }
    
    await window.scCreateCheckpoint(false); // Silent/Background create
    srCheckpointCreated = true;
    
    const gate = document.getElementById('sr-safety-gate');
    if (gate) {
        gate.style.background = 'var(--success-bg)';
        gate.style.color = 'var(--success-text)';
        gate.innerHTML = `<span data-sui-icon="check-circle" data-sui-size="18"></span> <div style="flex:1;">Safety Checkpoint Created. You are ready to refactor.</div>`;
        if (window.suiHydrateIcons) window.suiHydrateIcons(gate);
    }
    
    const commitBtn = document.getElementById('sr-studio-commit-btn');
    if (commitBtn) {
        commitBtn.style.opacity = '1';
        commitBtn.style.pointerEvents = 'auto';
    }
};

window.srStudioScan = async function() {
    const find = document.getElementById('sr-studio-find').value;
    const isRegex = document.getElementById('sr-studio-regex').checked;
    const cards = document.getElementById('sr-studio-cards');
    const area = document.getElementById('sr-studio-results-area');
    const summary = document.getElementById('sr-studio-summary');

    if (!find) { window.sui.toast("Search term required"); return; }

    cards.innerHTML = `<div style="text-align:center; padding:40px; color:var(--text-secondary);">${window.suiSpinner(24)}<br><br>Scanning System...</div>`;
    area.style.display = 'block';

    try {
        const data = await window.sui.api('sr_scan', { find, is_regex: isRegex });
        if (data.status === 'success') {
            srScanResults = data.results;
            srIsCapped = data.is_capped;
            let summaryText = `${data.total} Matches in ${data.results.length} Files`;
            if (data.is_capped) summaryText += ' (Capped for Preview)';
            summary.innerText = summaryText;
            
            if (data.total === 0) {
                cards.innerHTML = `<div style="text-align:center; padding:40px; color:var(--text-secondary);">No matches found.</div>`;
                return;
            }

            cards.innerHTML = '';
            data.results.forEach(res => {
                const card = document.createElement('div');
                card.className = 'sr-preflight-card';
                
                let matchesHtml = '';
                res.matches.forEach(m => {
                    // Escape HTML and highlight match
                    const escSnippet = m.snippet.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
                    const escMatch = m.text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
                    const highlighted = escSnippet.replace(escMatch, `<span class="sr-match-hl">${escMatch}</span>`);
                    
                    matchesHtml += `
                        <div style="margin-top:12px;">
                            <div style="font-size:10px; font-weight:700; color:var(--text-secondary);">LINE ${m.line}</div>
                            <div class="sr-snippet">${highlighted}</div>
                        </div>
                    `;
                });

                card.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
                        <div style="font-family:monospace; font-size:12px; font-weight:700; color:var(--primary); word-break:break-all;">${res.file}</div>
                        ${!res.is_writable ? '<span style="font-size:9px; background:var(--danger); color:white; padding:2px 6px; border-radius:4px; font-weight:900;">READ ONLY</span>' : ''}
                    </div>
                    ${matchesHtml}
                `;
                cards.appendChild(card);
            });

        } else {
            cards.innerHTML = `<div style="color:var(--danger); padding:20px;">${data.message}</div>`;
        }
    } catch (e) {
        cards.innerHTML = `<div style="color:var(--danger); padding:20px;">Connection Error</div>`;
    }
};

let srIsCapped = false;

window.srStudioExecute = async function() {
    const find = document.getElementById('sr-studio-find').value;
    const replace = document.getElementById('sr-studio-replace').value;
    const isRegex = document.getElementById('sr-studio-regex').checked;
    
    if (srScanResults.length === 0) return;

    const msg = srIsCapped 
        ? `The preview was capped, but clicking 'Confirm' will apply this change to EVERY matching file in the entire system. Proceed?`
        : `Apply replacement to ${srScanResults.length} files?`;

    window.openConfirm("Execute Refactor", msg, async () => {
        const btn = document.getElementById('sr-studio-commit-btn');
        btn.innerText = "Processing...";
        btn.disabled = true;

        const files = srScanResults.map(r => r.file);
        try {
            const data = await window.sui.api('sr_execute', {
                find, replace, is_regex: isRegex,
                system_wide: srIsCapped,
                files: JSON.stringify(files)
            });

            if (data.status === 'success') {
                window.sui.toast(`Updated ${data.processed} files (${data.changes} changes)`);
                window.sui.api('el_manual_log', { 
                    summary: `Refactor Lab: Replaced "${find}" with "${replace}". Files: ${data.processed}, Total Changes: ${data.changes}.` 
                }, { toast: false });
                
                // Close Studio and refresh
                setTimeout(() => {
                    window.sui.closeStudio('sr-studio');
                    location.reload();
                }, 1500);
            } else {
                window.openConfirm("Refactor Error", data.message, null, false, "OK", null);
                btn.innerText = "Commit All Changes";
                btn.disabled = false;
            }
        } catch (e) {
            window.sui.toast("Execution failed");
            btn.innerText = "Commit All Changes";
            btn.disabled = false;
        }
    });
};
JS;