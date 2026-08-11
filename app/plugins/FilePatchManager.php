<?php
// ==============================================================================
// PLUGIN: File Patch Manager
// DESCRIPTION: AI Development Tool.
// Purpose: Reliable Search & Replace patching with grouped in-memory commits.
// Features: 
// 1. Atomic Writes: Groups patches by file to prevent partial syntax breakage.
// 2. Chained Staging: Handle multiple files and multiple patches per file.
// 3. Capabilities UI: Dynamic list of supported protocols on the main screen.
// 4. Smart Diagnostics: Returns actual file content on mismatch with hints.
// 5. File Creation: Supports 'action' => 'create' for new files.
// ==============================================================================

// --- PREFLIGHT STATE SANDBOX ---
class CPSandbox {
    public static $files = [];
    public static $vars = [];
    public static function reset() {
        self::$files = [];
        self::$vars = [];
    }
}

// --- HELPER: NORMALIZE CONTENT ---
function cp_normalize($str) {
    if (is_array($str) || $str === '__CJ_DIR__') return $str;
    return str_replace(["\r\n", "\r"], "\n", $str);
}

// --- HELPER: ACTION NAME NORMALIZER ---
if (!function_exists('cp_normalize_action_name')) {
    function cp_normalize_action_name($action) {
        if ($action === 'update') return 'file_update';
        if ($action === 'create') return 'file_create';
        if ($action === 'cut_code') return 'code_cut';
        if ($action === 'delete_code') return 'code_delete';
        if ($action === 'patch_var') return 'var_patch';
        if ($action === 'refactor_var') return 'var_refactor';
        if ($action === 'move_file') return 'file_move';
        if ($action === 'delete_file') return 'file_delete';
        if ($action === 'export') return 'file_export';
        if ($action === 'export_skeleton') return 'file_export_skeleton';
        if ($action === 'code_logic_trace') return 'logic_trace';
        return $action;
    }
}

// --- HELPER: RECURSIVE DELETE ---
function cp_recursive_delete($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!cp_recursive_delete($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// --- ACTION REGISTRY & MODULES ---
$CP_REG = [];
$CP_JS_HANDLERS = "";

// --- SHARED HELPERS ---
function cp_preview_cut_or_delete($patch, $realPath, &$res, $isCut, &$temp_file_buffers = null, $root = '') {
    $f = $patch['file'] ?? '';
    $fullPath = $root . $f;
    if (isset(CPSandbox::$files[$f])) {
        $content = cp_normalize(CPSandbox::$files[$f]);
    } else {
        if (!$realPath || !file_exists($realPath)) {
            $res['status'] = 'error';
            $res['msg'] = 'File not found.';
            return;
        }
        $content = cp_normalize(file_get_contents($realPath));
    }
    $start = cp_normalize($isCut ? ($patch['range_start'] ?? '') : ($patch['delete_start'] ?? ''));
    $end = cp_normalize($isCut ? ($patch['range_end'] ?? '') : ($patch['delete_end'] ?? ''));
    
    $posStart = (strlen($start) > 0) ? strpos($content, $start) : false;
    $posEnd = ($posStart !== false && strlen($end) > 0) ? strpos($content, $end, $posStart + strlen($start)) : false;
    
    if ($posStart === false || $posEnd === false) {
        $res['status'] = 'error';
        $res['msg'] = ($posStart === false) ? 'Start anchor not found.' : 'End anchor not found.';
        
        $targetAnchor = ($posStart === false) ? $start : $end;
        if (strlen($targetAnchor) > 5) {
            $normAnchor = preg_replace('/\s+/', '', $targetAnchor);
            $normContent = preg_replace('/\s+/', '', $content);
            if (strpos($normContent, $normAnchor) !== false) {
                $regex = '/';
                for($i=0; $i<strlen($targetAnchor); $i++) {
                    $char = $targetAnchor[$i];
                    if (trim($char) == '') continue;
                    $regex .= preg_quote($char, '/') . '\s*';
                }
                $regex .= '/s';
                if (preg_match($regex, $content, $matches)) {
                    $res['msg'] .= ' Whitespace mismatch detected.';
                    $res['hint'] = $matches[0];
                    $res['hint_target'] = $isCut ? (($posStart === false) ? 'range_start' : 'range_end') : (($posStart === false) ? 'delete_start' : 'delete_end');
                }
            }
        }
    } else {
        $blockLen = ($posEnd + strlen($end)) - $posStart;
        $res['delete_block'] = substr($content, $posStart, $blockLen);
        $res['status'] = 'success';
        $res['msg'] = $isCut ? 'Cut range identified.' : 'Deletion range identified.';
        $replaceStr = ($patch['action'] === 'code_cut') ? ($patch['replace'] ?? '') : '';
        CPSandbox::$files[$f] = substr_replace($content, $replaceStr, $posStart, $blockLen);
    }
}

function cp_preview_update_or_trace($patch, $realPath, &$res, $isTrace, &$temp_file_buffers = null, $root = '', &$temp_vars = null) {
    $f = $patch['file'] ?? '';
    $fullPath = $root . $f;
    if (isset(CPSandbox::$files[$f])) {
        $content = cp_normalize(CPSandbox::$files[$f]);
    } else {
        if (!$realPath || !file_exists($realPath)) {
            $res['status'] = 'error';
            $res['msg'] = 'File does not exist. Use #ACTION: file_create for new files.';
            return;
        }
        $content = cp_normalize(file_get_contents($realPath));
    }
    $find = cp_normalize($patch['find'] ?? '');
    if ($find === '') {
        $res['status'] = 'error';
        $res['msg'] = 'Search block (#FIND) is empty or missing.';
        return;
    }
    $count = substr_count($content, $find);

    if ($count === 0) {
        $res['status'] = 'error';
        $res['msg'] = 'Code block not found.';
        
        $quoted = preg_quote($find, '/');
        $loosePattern = '/' . preg_replace('/\\s+/', '[\\s\\v\\h]+', $quoted) . '/s';
        $quotePattern = '/' . str_replace(["'", '"'], "['\"]", $quoted) . '/s';
        $decoded = html_entity_decode($find, ENT_QUOTES | ENT_HTML5);

        $normFind = preg_replace('/\s+/', '', $find);
        $normContent = preg_replace('/\s+/', '', $content);
        $normPos = strpos($normContent, $normFind);

        if ($normPos !== false) {
            $regex = '/';
            for($i=0; $i<strlen($find); $i++) {
                $char = $find[$i];
                if (trim($char) == '') continue;
                $regex .= preg_quote($char, '/') . '\s*';
            }
            $regex .= '/s';
            if (preg_match($regex, $content, $matches)) {
                $res['msg'] = 'Structural match found. Whitespace or Newline mismatch detected.';
                $res['hint'] = $matches[0];
            }
        } elseif (strlen($find) > 5) {
            $tail = substr(trim($find), -10);
            $tailPos = strrpos($content, $tail);
            if ($tailPos !== false) {
                $contextStart = max(0, $tailPos - 150);
                $res['msg'] = 'Block mismatch. Tail found, but preceding logic differs (check brace counts).';
                $res['hint'] = '...' . substr($content, $contextStart, ($tailPos + strlen($tail)) - $contextStart);
            }
        }
        if ($res['status'] === 'error' && !$res['hint'] && preg_match($loosePattern, $content, $matches)) {
            $res['msg'] = 'Whitespace/Indentation mismatch detected.';
            $res['hint'] = $matches[0];
        } elseif (preg_match($quotePattern, $content, $matches)) {
            $res['msg'] = 'Quote style mismatch detected (Single vs Double).';
            $res['hint'] = $matches[0];
        } elseif ($decoded !== $find && strpos($content, $decoded) !== false) {
            $res['msg'] = 'HTML Entity Encoding mismatch detected.';
            $res['hint'] = $decoded;
        } elseif (strlen(trim($find)) > 10 && strpos($content, trim($find)) !== false) {
            $res['msg'] = 'Surrounding whitespace mismatch detected.';
            $res['hint'] = trim($find);
        } elseif (strlen($find) > 25) {
            $anchor = substr($find, 10, -10);
            $pos = strpos($content, $anchor);
            if ($pos !== false) {
                $start = strrpos(substr($content, 0, $pos), "\n") ?: 0;
                $end = strpos($content, "\n", $pos + strlen($anchor)) ?: strlen($content);
                $res['msg'] = 'Partial match found. Check variable names or assignment operators.';
                $res['hint'] = trim(substr($content, $start, $end - $start));
            }
        }
    } elseif ($count > 1 && !isset($patch['match_index'])) {
        $res['status'] = 'error';
        $res['msg'] = "Ambiguous: $count matches found. Need match_index.";
    } else {
    $mIdx = isset($patch['match_index']) ? (int)$patch['match_index'] : 1;
    if ($mIdx > $count) {
        $res['status'] = 'error';
        $res['msg'] = "Match Index Mismatch: You requested #$mIdx, but only $count occurrences exist.";
    } else {
        $res['status'] = 'success';
        $res['msg'] = $isTrace ? "Logic point verified." : (($count > 1) ? "Match #$mIdx of $count found." : "Unique match found.");
        if (!$isTrace) {
            $replaceStr = cp_normalize($patch['replace'] ?? '');
            // Substitute variable tokens in-memory if present
            foreach (CPSandbox::$vars as $name => $val) {
                $replaceStr = str_replace('{{' . $name . '}}', $val, $replaceStr);
            }
            $offset = 0; $foundPos = false;
            for ($i = 1; $i <= $mIdx; $i++) {
                $pos = strpos($content, $find, $offset);
                if ($pos === false) break;
                if ($i === $mIdx) { $foundPos = $pos; break; }
                $offset = $pos + strlen($find);
            }
            if ($foundPos !== false) {
                CPSandbox::$files[$f] = substr_replace($content, $replaceStr, $foundPos, strlen($find));
            }
        }
    }
}}// ==============================================================================
// ACTION: update
// ==============================================================================
$CP_REG['update'] = [
    'desc' => 'Standard find and replace for modifying existing code.',
    'required' => ['ACTION', 'PATCH_ID', 'FILE', 'COMMENT', 'FIND', 'REPLACE'],
    'literal' => ['FIND', 'REPLACE'],
    'legacy' => true,
    'example' => <<<'EOT'
#ACTION: update
#PATCH_ID: [ID]
#FILE: path/to/file.php
#COMMENT: [Description of change]
#FIND:
old code
#REPLACE:
new code
#END
EOT
];
function cp_preview_update($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    cp_preview_update_or_trace($patch, $realPath, $res, false, $temp_file_buffers, $root, $temp_vars);
}

// ==============================================================================
// ACTION: file_update
// ==============================================================================
$CP_REG['file_update'] = [
    'desc' => 'Standard find and replace for modifying existing code.',
    'required' => ['ACTION', 'PATCH_ID', 'FILE', 'COMMENT', 'FIND', 'REPLACE'],
    'literal' => ['FIND', 'REPLACE'],
    'example' => "#" . "ACTION: file_update\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "FILE: path/to/file.php\n" .
                 "#" . "COMMENT: [Description of change]\n" .
                 "#" . "FIND:\n" .
                 "old code\n" .
                 "#" . "REPLACE:\n" .
                 "new code\n" .
                 "#" . "END"
];
function cp_preview_file_update($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    cp_preview_update($patch, $realPath, $res, $root, $temp_vars, $temp_file_buffers);
}
function cp_commit_file_update($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    cp_commit_update($patch, $buffers, $variables, $variables_used, $forceLiteral);
}
function cp_commit_update($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $f = $patch['file'];
    $find = cp_normalize($patch['find']);
    $matchIndex = (int)($patch['match_index'] ?? 1);
    $offset = 0; $foundPos = false;
    for ($i = 1; $i <= $matchIndex; $i++) {
        $pos = strpos($buffers[$f], $find, $offset);
        if ($pos === false) break;
        if ($i === $matchIndex) { $foundPos = $pos; break; }
        $offset = $pos + strlen($find);
    }
    if ($foundPos === false) {
        $snippet = mb_substr($find, 0, 50) . (mb_strlen($find) > 50 ? '...' : '');
        throw new Exception("Patch [{$patch['id']}] Failed: Block not found in '{$f}'. Search snippet: [{$snippet}] (Match #$matchIndex)");
    }
    
    $replaceStr = cp_normalize($patch['replace']);
    foreach ($variables as $name => $val) {
        if (strpos($replaceStr, '{{' . $name . '}}') !== false) {
            $variables_used[$name] = true;
            $replaceStr = str_replace('{{' . $name . '}}', $val, $replaceStr);
        }
    }
    if (!$forceLiteral && preg_match('/\{\{([A-Z0-9_-]+)\}\}/', $replaceStr, $matches)) {
        throw new Exception("ID:{$patch['id']} - Variable {{".$matches[1]."}} used but never cut.");
    }
    
    $buffers[$f] = substr_replace($buffers[$f], $replaceStr, $foundPos, strlen($find));
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['file_update'] = {
    statusText: 'UPDATE',
    statusColor: '#007AFF',
    renderDiff: function(patch, res, idx) {
        let findContent = cpFormatDiffLines(res.find, res.replace, 'remove');
        if (res.audit_pattern && res.audit_verified) {
            const escPattern = escapeHtml(res.audit_pattern);
            const patternHl = `<mark style="background:var(--primary); color:white; border-radius:2px; padding:0 2px; font-weight:700;">${escPattern}</mark>`;
            if (res.audit_context_verified && res.audit_details && res.audit_details.context) {
                const ctx = res.audit_details.context;
                const escPrev = escapeHtml(ctx.prev || "");
                const escNext = escapeHtml(ctx.next || "");
                if (escPrev) findContent = findContent.replace(escPrev, `<span class="cp-audit-context-hl">${escPrev}</span>`);
                if (escNext) findContent = findContent.replace(escNext, `<span class="cp-audit-context-hl">${escNext}</span>`);
            }
            findContent = findContent.replace(escPattern, patternHl);
        }
        return `<div class="cp-diff-old" onclick="cpToggleDiff(${idx})" style="cursor:pointer;"><span class="cp-diff-label">${res.hint ? '⚠ MISMATCH' : (res.audit_verified ? '✓ AUDIT MATCH' : '✘ REMOVE')}</span>${findContent}</div><div class="cp-diff-new" onclick="cpToggleDiff(${idx})" style="cursor:pointer;"><span class="cp-diff-label">✚ ADD</span>${cpFormatDiffLines(res.replace, res.find, 'add', true)}</div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>` : '';
    }
};
window.CP_UI['update'] = window.CP_UI['file_update'];
JS;

// ==============================================================================
// ACTION: create
// ==============================================================================
$CP_REG['create'] = [
    'desc' => 'Creates a new file with the specified content.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE', 'COMMENT', 'REPLACE'],
    'literal' => ['REPLACE'],
    'legacy' => true,
    'example' => <<<'EOT'
#ACTION: create
#PATCH_ID: [ID]
#FILE: path/to/new_file.php
#COMMENT: [Description of new file]
#REPLACE:
<?php
// new content
?>
#END
EOT
];
function cp_preview_create($patch, $realPath, &$res, $root) {
    $fullPath = $root . $patch['file'];
    if (file_exists($fullPath)) {
        $res['status'] = 'error';
        $res['msg'] = 'File already exists. Overwriting is strictly forbidden.';
    } else {
        $res['status'] = 'success';
        $res['msg'] = 'Ready to create new file.';
    }
}
function cp_commit_create($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $f = $patch['file'];
    $replaceStr = cp_normalize($patch['replace']);
    foreach ($variables as $name => $val) {
        if (strpos($replaceStr, '{{' . $name . '}}') !== false) {
            $variables_used[$name] = true;
            $replaceStr = str_replace('{{' . $name . '}}', $val, $replaceStr);
        }
    }
    if (!$forceLiteral && preg_match('/\{\{([A-Z0-9_-]+)\}\}/', $replaceStr, $matches)) {
        throw new Exception("ID:{$patch['id']} - Variable {{".$matches[1]."}} used but never cut.");
    }
    $buffers[$f] = $replaceStr;
}

// ==============================================================================
// ACTION: file_overwrite
// ==============================================================================
$CP_REG['file_overwrite'] = [
    'desc' => 'Overwrites or creates a file with the specified content.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE', 'COMMENT', 'REPLACE'],
    'literal' => ['REPLACE'],
    'example' => "#" . "ACTION: file_overwrite\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "FILE: path/to/file.php\n" .
                 "#" . "COMMENT: [Description of overwrite]\n" .
                 "#" . "REPLACE:\n" .
                 "new content\n" .
                 "#" . "END"
];
function cp_preview_file_overwrite($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    $f = $patch['file'];
    $fullPath = $root . $f;
    if (file_exists($fullPath)) {
        $res['status'] = 'success';
        $res['msg'] = 'Ready to overwrite existing file.';
    } else {
        $res['status'] = 'success';
        $res['msg'] = 'Ready to create new file (file did not exist).';
    }
    CPSandbox::$files[$f] = cp_normalize($patch['replace'] ?? '');
}
function cp_commit_file_overwrite($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $f = $patch['file'];
    $replaceStr = cp_normalize($patch['replace']);
    foreach ($variables as $name => $val) {
        if (strpos($replaceStr, '{{' . $name . '}}') !== false) {
            $variables_used[$name] = true;
            $replaceStr = str_replace('{{' . $name . '}}', $val, $replaceStr);
        }
    }
    if (!$forceLiteral && preg_match('/\{\{([A-Z0-9_-]+)\}\}/', $replaceStr, $matches)) {
        throw new Exception("ID:{$patch['id']} - Variable {{".$matches[1]."}} used but never cut.");
    }
    $buffers[$f] = $replaceStr;
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['file_create'] = {
    statusText: 'CREATE',
    statusColor: '#5856D6',
    renderDiff: function(patch, res, idx) {
        return `<div class="cp-diff-new" onclick="cpToggleDiff(${idx})" style="cursor:pointer;"><span class="cp-diff-label">✚ CREATE CONTENT</span>${cpFormatDiffLines(res.replace, "", 'add', true)}</div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>` : '';
    }
};
window.CP_UI['create'] = window.CP_UI['file_create'];
window.CP_UI['file_overwrite'] = {
    statusText: 'OVERWRITE',
    statusColor: '#FF9500',
    renderDiff: function(patch, res, idx) {
        return `<div class="cp-diff-new" onclick="cpToggleDiff(${idx})" style="cursor:pointer; border-left: 4px solid #FF9500; background: rgba(255, 149, 0, 0.02);"><span class="cp-diff-label" style="color:#D97706;">✚ OVERWRITE CONTENT</span>${cpFormatDiffLines(res.replace, "", 'add', true)}</div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>` : '';
    }
};
JS;

// ==============================================================================
// ACTION: delete_code
// ==============================================================================
$CP_REG['delete_code'] = [
    'desc' => 'Deletes a block of code between two precise anchors.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE', 'COMMENT', 'DELETE_START', 'DELETE_END'],
    'literal' => ['DELETE_START', 'DELETE_END', 'REPLACE'],
    'legacy' => true,
    'example' => <<<'EOT'
#ACTION: delete_code
#PATCH_ID: [ID]
#FILE: path/to/file.php
#COMMENT: [Description of deletion]
#DELETE_START:
// start anchor
#DELETE_END:
// end anchor
#END
EOT
];
function cp_preview_delete_code($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    cp_preview_cut_or_delete($patch, $realPath, $res, false, $temp_file_buffers, $root);
}

// ==============================================================================
// ACTION: code_delete
// ==============================================================================
$CP_REG['code_delete'] = [
    'desc' => 'Deletes a block of code between two precise anchors.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE', 'COMMENT', 'DELETE_START', 'DELETE_END'],
    'literal' => ['DELETE_START', 'DELETE_END', 'REPLACE'],
    'example' => "#" . "ACTION: code_delete\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "FILE: path/to/file.php\n" .
                 "#" . "COMMENT: [Description of deletion]\n" .
                 "#" . "DELETE_START:\n" .
                 "// start anchor\n" .
                 "#" . "DELETE_END:\n" .
                 "// end anchor\n" .
                 "#" . "END"
];
function cp_preview_code_delete($patch, $realPath, &$res, $root) {
    cp_preview_delete_code($patch, $realPath, $res, $root);
}
function cp_commit_code_delete($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    cp_commit_delete_code($patch, $buffers, $variables, $variables_used, $forceLiteral);
}

// ==============================================================================
// ACTION: code_cut
// ==============================================================================
$CP_REG['code_cut'] = [
    'desc' => 'Cuts a block of code and saves it to a variable for later use.',
    'required' => ['ACTION', 'PATCH_ID', 'FILE', 'COMMENT', 'VAR_NAME', 'CONSUMER_ID', 'RANGE_START', 'RANGE_END'],
    'literal' => ['RANGE_START', 'RANGE_END', 'REPLACE'],
    'example' => "#" . "ACTION: code_cut\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "FILE: path/to/file.php\n" .
                 "#" . "COMMENT: [Description of code being cut]\n" .
                 "#" . "VAR_NAME: MY_VAR\n" .
                 "#" . "CONSUMER_ID: [ID]\n" .
                 "#" . "RANGE_START:\n" .
                 "function old() {\n" .
                 "#" . "RANGE_END:\n" .
                 "}\n" .
                 "#" . "END"
];
function cp_preview_code_cut($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    cp_preview_cut_code($patch, $realPath, $res, $root, $temp_vars, $temp_file_buffers);
}
function cp_commit_code_cut($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    cp_commit_cut_code($patch, $buffers, $variables, $variables_used, $forceLiteral);
}
function cp_commit_delete_code($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $f = $patch['file'];
    $start = cp_normalize($patch['delete_start']);
    $end = cp_normalize($patch['delete_end']);
    
    $posS = strpos($buffers[$f], $start);
    if ($posS === false) {
        $snippet = mb_substr($start, 0, 40) . '...';
        throw new Exception("Patch [{$patch['id']}] Failed: Deletion START anchor not found in '{$f}'. Snippet: [{$snippet}]");
    }

    $posE = strpos($buffers[$f], $end, $posS + strlen($start));
    if ($posE === false) {
        $snippet = mb_substr($end, 0, 40) . '...';
        throw new Exception("Patch [{$patch['id']}] Failed: Deletion END anchor not found in '{$f}'. Snippet: [{$snippet}]");
    }

    $replaceStr = (!empty($patch['replace'])) ? cp_normalize($patch['replace']) : '';
    $buffers[$f] = substr_replace($buffers[$f], $replaceStr, $posS, ($posE + strlen($end)) - $posS);
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['code_delete'] = {
    statusText: 'DELETE',
    statusColor: '#FF3B30',
    renderDiff: function(patch, res, idx) {
        return `<div class="cp-diff-old" onclick="cpToggleDiff(${idx})" style="cursor:pointer;"><span class="cp-diff-label">✘ DELETE RANGE</span>${escapeHtml(res.delete_block || "Range not found")}</div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        let btns = '';
        if (!isDone && res.delete_block) {
            btns += `<button onclick="cpCopyDeletionBlock(${idx})" style="background:var(--warn-bg); color:var(--warn-text); border:1px solid var(--border-color); padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; margin-right:8px;">Copy</button>`;
        }
        if (!isDone && !isErr) {
            btns += `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>`;
        }
        return btns;
    }
};
window.CP_UI['delete_code'] = window.CP_UI['code_delete'];
JS;

// ==============================================================================
// ACTION: cut_code
// ==============================================================================
$CP_REG['cut_code'] = [
    'desc' => 'Cuts a block of code and saves it to a variable for later use.',
    'required' => ['ACTION', 'PATCH_ID', 'FILE', 'COMMENT', 'VAR_NAME', 'CONSUMER_ID', 'RANGE_START', 'RANGE_END'],
    'literal' => ['RANGE_START', 'RANGE_END', 'REPLACE'],
    'legacy' => true,
    'example' => <<<'EOT'
#ACTION: cut_code
#PATCH_ID: [ID]
#FILE: path/to/file.php
#COMMENT: [Description of code being cut]
#VAR_NAME: MY_VAR
#RANGE_START:
function old() {
#RANGE_END:
}
#END
EOT
];
function cp_preview_cut_code($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    cp_preview_cut_or_delete($patch, $realPath, $res, true, $temp_file_buffers, $root);
    if ($res['status'] === 'success' && isset($res['delete_block'])) {
        if ($temp_vars !== null) {
            $temp_vars[$patch['var_name'] ?? 'TEMP_VAR'] = $res['delete_block'];
        }
        CPSandbox::$vars[$patch['var_name'] ?? 'TEMP_VAR'] = $res['delete_block'];
    }
}
function cp_commit_cut_code($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $f = $patch['file'];
    $mS = cp_normalize($patch['range_start'] ?? '');
    $mE = cp_normalize($patch['range_end'] ?? '');
    $varName = $patch['var_name'] ?? 'TEMP_VAR';
    
    $posS = strpos($buffers[$f], $mS);
    if ($posS === false) {
        $snippet = mb_substr($mS, 0, 40) . '...';
        throw new Exception("Patch [{$patch['id']}] Failed: Cut START anchor not found in '{$f}'. Snippet: [{$snippet}]");
    }
    
    $posE = strpos($buffers[$f], $mE, $posS + strlen($mS));
    if ($posE === false) {
        $snippet = mb_substr($mE, 0, 40) . '...';
        throw new Exception("Patch [{$patch['id']}] Failed: Cut END anchor not found in '{$f}'. Snippet: [{$snippet}]");
    }
    
    $blockLen = ($posE + strlen($mE)) - $posS;
    $variables[$varName] = substr($buffers[$f], $posS, $blockLen);
    $replaceStr = (!empty($patch['replace'])) ? cp_normalize($patch['replace']) : '';
    $buffers[$f] = substr_replace($buffers[$f], $replaceStr, $posS, $blockLen);
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['code_cut'] = {
    statusText: 'CUT',
    statusColor: '#FF9500',
    renderDiff: function(patch, res, idx) {
        return `<div class="cp-diff-old" onclick="cpToggleDiff(${idx})" style="cursor:pointer;"><span class="cp-diff-label">✂ CUT TO {{${patch.var_name}}}</span>${escapeHtml(res.delete_block || "Range not found")}</div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>` : '';
    }
};
window.CP_UI['cut_code'] = window.CP_UI['code_cut'];
JS;

// ==============================================================================
// ACTION: patch_var
// ==============================================================================
$CP_REG['patch_var'] = [
    'desc' => 'Modifies the contents of a previously cut variable before it is used.',
    'required' => ['PATCH_ID', 'ACTION', 'VAR_NAME', 'COMMENT', 'FIND', 'REPLACE'],
    'literal' => ['FIND', 'REPLACE'],
    'legacy' => true,
    'example' => <<<'EOT'
#ACTION: patch_var
#PATCH_ID: [ID]
#VAR_NAME: MY_VAR
#COMMENT: [Description of variable modification]
#FIND:
old var text
#REPLACE:
new var text
#END
EOT
];
function cp_preview_patch_var($patch, $realPath, &$res, $root, &$temp_vars = null) {
    $varName = $patch['var_name'] ?? '';
    $varsSource = null;
    if (isset(CPSandbox::$vars[$varName])) {
        $varsSource = 'sandbox';
    } elseif ($temp_vars !== null && isset($temp_vars[$varName])) {
        $varsSource = 'reference';
    }

    if ($varsSource !== null) {
        $content = ($varsSource === 'sandbox') ? CPSandbox::$vars[$varName] : $temp_vars[$varName];
        $find = cp_normalize($patch['find'] ?? '');
        
        $matchIndex = (int)($patch['match_index'] ?? 1);
        $offset = 0; $foundPos = false;
        for ($i = 1; $i <= $matchIndex; $i++) {
            $pos = strpos($content, $find, $offset);
            if ($pos === false) break;
            if ($i === $matchIndex) { $foundPos = $pos; break; }
            $offset = $pos + strlen($find);
        }
        
        if ($foundPos === false) {
            $res['status'] = 'error';
            $res['msg'] = "Refactor target not found in variable '{$varName}'.";
            
            $normFind = preg_replace('/\s+/', '', $find);
            $normContent = preg_replace('/\s+/', '', $content);
            if (strpos($normContent, $normFind) !== false) {
                $regex = '/';
                for($i=0; $i<strlen($find); $i++) {
                    $char = $find[$i];
                    if (trim($char) == '') continue;
                    $regex .= preg_quote($char, '/') . '\s*';
                }
                $regex .= '/s';
                if (preg_match($regex, $content, $matches)) {
                    $res['msg'] = 'Whitespace or Newline mismatch detected in variable.';
                    $res['hint'] = $matches[0];
                }
            }
        } else {
            $res['status'] = 'success';
            $res['msg'] = "Variable patch staged and validated in memory.";
            $replaceStr = cp_normalize($patch['replace'] ?? '');
            $newVal = substr_replace($content, $replaceStr, $foundPos, strlen($find));
            if ($varsSource === 'sandbox') {
                CPSandbox::$vars[$varName] = $newVal;
            }
            if ($temp_vars !== null) {
                $temp_vars[$varName] = $newVal;
            }
        }
    } else {
        $res['status'] = 'error';
        $res['msg'] = "Variable '{$varName}' not found in memory. Ensure the cut_code step succeeded in this batch.";
    }
}

// ==============================================================================
// ACTION: var_patch
// ==============================================================================
$CP_REG['var_patch'] = [
    'desc' => 'Modifies the contents of a previously cut variable before it is used.',
    'required' => ['PATCH_ID', 'ACTION', 'VAR_NAME', 'COMMENT', 'FIND', 'REPLACE'],
    'literal' => ['FIND', 'REPLACE'],
    'example' => "#" . "ACTION: var_patch\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "VAR_NAME: MY_VAR\n" .
                 "#" . "COMMENT: [Description of variable modification]\n" .
                 "#" . "FIND:\n" .
                 "old var text\n" .
                 "#" . "REPLACE:\n" .
                 "new var text\n" .
                 "#" . "END"
];
function cp_preview_var_patch($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    cp_preview_patch_var($patch, $realPath, $res, $root, $temp_vars);
}
function cp_commit_var_patch($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    cp_commit_patch_var($patch, $buffers, $variables, $variables_used, $forceLiteral);
}

// ==============================================================================
// ACTION: var_refactor
// ==============================================================================
$CP_REG['var_refactor'] = [
    'desc' => 'Performs surgical find/replace on a cut variable (useful for renaming vars or fixing indentation during moves).',
    'required' => ['PATCH_ID', 'ACTION', 'VAR_NAME', 'COMMENT', 'FIND', 'REPLACE'],
    'literal' => ['FIND', 'REPLACE'],
    'example' => "#" . "ACTION: var_refactor\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "VAR_NAME: MY_CODE_BLOCK\n" .
                 "#" . "COMMENT: [Description of refactor]\n" .
                 "#" . "FIND:\n" .
                 "old_variable_name\n" .
                 "#" . "REPLACE:\n" .
                 "new_variable_name\n" .
                 "#" . "END"
];
function cp_preview_var_refactor($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    cp_preview_refactor_var($patch, $realPath, $res, $root, $temp_vars);
}
function cp_commit_var_refactor($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    cp_commit_refactor_var($patch, $buffers, $variables, $variables_used, $forceLiteral);
}
function cp_commit_patch_var($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $varName = $patch['var_name'] ?? '';
    if (!isset($variables[$varName])) {
        throw new Exception("Patch [{$patch['id']}] Failed: Variable '{$varName}' not found. Did you cut it first?");
    }
    
    $find = cp_normalize($patch['find']);
    $replaceStr = cp_normalize($patch['replace']);
    $content = $variables[$varName];
    
    $matchIndex = (int)($patch['match_index'] ?? 1);
    $offset = 0; $foundPos = false;
    for ($i = 1; $i <= $matchIndex; $i++) {
        $pos = strpos($content, $find, $offset);
        if ($pos === false) break;
        if ($i === $matchIndex) { $foundPos = $pos; break; }
        $offset = $pos + strlen($find);
    }
    if ($foundPos === false) {
        $snippet = mb_substr($find, 0, 50) . (mb_strlen($find) > 50 ? '...' : '');
        throw new Exception("Patch [{$patch['id']}] Failed: Block not found in variable '{$varName}'. Search snippet: [{$snippet}] (Match #$matchIndex)");
    }
    
    $variables[$varName] = substr_replace($content, $replaceStr, $foundPos, strlen($find));
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['var_patch'] = {
    statusText: 'PATCH VAR',
    statusColor: '#AF52DE',
    renderDiff: function(patch, res, idx) {
        return `<div class="cp-diff-old" onclick="cpToggleDiff(${idx})" style="cursor:pointer;"><span class="cp-diff-label">PATCH VARIABLE {{${patch.var_name}}}</span>${cpFormatDiffLines(res.find, res.replace, 'remove')}</div><div class="cp-diff-new" onclick="cpToggleDiff(${idx})" style="cursor:pointer;"><span class="cp-diff-label">✚ ADD</span>${cpFormatDiffLines(res.replace, res.find, 'add', true)}</div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>` : '';
    }
};
window.CP_UI['patch_var'] = window.CP_UI['var_patch'];
JS;

// ==============================================================================
// ACTION: move_file
// ==============================================================================
$CP_REG['move_file'] = [
    'desc' => 'Moves or renames a file.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE', 'DEST_FILE'],
    'literal' =>[],
    'legacy' => true,
    'example' => <<<'EOT'
#ACTION: move_file
#PATCH_ID: [ID]
#FILE: path/to/old.php
#DEST_FILE: path/to/new.php
#END
EOT
];
function cp_preview_move_file($patch, $realPath, &$res, $root) {
    if (!$realPath || !file_exists($realPath)) {
        $res['status'] = 'error';
        $res['msg'] = 'Source file not found.';
    } else {
        $destPath = $root . ($patch['dest_file'] ?? '');
        if (file_exists($destPath)) {
            $res['status'] = 'error';
            $res['msg'] = 'Destination file already exists.';
        } else {
            $res['status'] = 'success';
            $res['msg'] = 'Ready to move file to: ' . ($patch['dest_file'] ?? '');
        }
    }
}
function cp_commit_move_file($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $f = $patch['file'];
    $dest = $patch['dest_file'];
    if (empty($dest)) throw new Exception("Patch [{$patch['id']}] Failed: Missing DEST_FILE.");
    if (!isset($buffers[$f])) {
        throw new Exception("Patch [{$patch['id']}] Failed: Move source '{$f}' not loaded in buffer.");
    }
    $buffers[$dest] = $buffers[$f];
    $buffers[$f] = null;
    $buffers['__CJ_CREATED_FILES__'][$dest] = true;
}

// ==============================================================================
// ACTION: file_copy
// ==============================================================================
$CP_REG['file_copy'] = [
    'desc' => 'Duplicates a file from one path to another.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE', 'DEST_FILE'],
    'literal' => [],
    'example' => "#" . "ACTION: file_copy\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "FILE: path/to/source.php\n" .
                 "#" . "DEST_FILE: path/to/destination.php\n" .
                 "#" . "END"
];
function cp_preview_file_copy($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    $f = $patch['file'];
    $dest = $patch['dest_file'] ?? '';
    $fullPath = $root . $f;
    $destFull = $root . $dest;
        
    $srcExists = (isset(CPSandbox::$files[$f]) || file_exists($fullPath));
    $destExists = (isset(CPSandbox::$files[$dest]) || file_exists($destFull));
        
    if (!$srcExists) {
        $res['status'] = 'error';
        $res['msg'] = 'Source file not found.';
    } elseif ($destExists) {
        $res['status'] = 'error';
        $res['msg'] = 'Destination file already exists.';
    } else {
        $res['status'] = 'success';
        $res['msg'] = 'Ready to copy file to: ' . $dest;
        $srcContent = isset(CPSandbox::$files[$f]) ? CPSandbox::$files[$f] : @file_get_contents($fullPath);
        CPSandbox::$files[$dest] = cp_normalize($srcContent);
    }
}function cp_commit_file_copy($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $f = $patch['file'];
    $dest = $patch['dest_file'];
    if (empty($dest)) throw new Exception("Patch [{$patch['id']}] Failed: Missing DEST_FILE.");
    if (!isset($buffers[$f])) {
        throw new Exception("Patch [{$patch['id']}] Failed: Copy source '{$f}' not loaded in buffer.");
    }
    $buffers[$dest] = $buffers[$f];
    $buffers['__CJ_CREATED_FILES__'][$dest] = true;
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['file_move'] = {
    statusText: 'RELOCATING',
    statusColor: '#FF9500',
    renderDiff: function(patch, res, idx) {
        return `<div class="cp-diff-old" style="padding:15px; background: rgba(255, 149, 0, 0.1); border-left: 4px solid #FF9500;"><span class="cp-diff-label" style="color:#CC7A00;">🚚 FROM (SOURCE)</span><code style="font-size:12px; font-weight:bold;">${escapeHtml(patch.file)}</code></div><div class="cp-diff-new" style="padding:15px; background: rgba(52, 199, 89, 0.05); border-left: 4px solid #34C759;"><span class="cp-diff-label" style="color:#248A3D;">🏁 TO (DESTINATION)</span><code style="font-size:12px; font-weight:bold;">${escapeHtml(patch.dest_file)}</code></div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>` : '';
    }
};
window.CP_UI['move_file'] = window.CP_UI['file_move'];
window.CP_UI['file_copy'] = {
    statusText: 'COPYING',
    statusColor: '#007AFF',
    renderDiff: function(patch, res, idx) {
        return `<div class="cp-diff-old" style="padding:15px; background: rgba(0, 122, 255, 0.05); border-left: 4px solid #007AFF;"><span class="cp-diff-label" style="color:#0051C4;">📋 FROM (SOURCE)</span><code style="font-size:12px; font-weight:bold;">${escapeHtml(patch.file)}</code></div><div class="cp-diff-new" style="padding:15px; background: rgba(52, 199, 89, 0.05); border-left: 4px solid #34C759;"><span class="cp-diff-label" style="color:#248A3D;">🏁 TO (COPY DESTINATION)</span><code style="font-size:12px; font-weight:bold;">${escapeHtml(patch.dest_file)}</code></div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>` : '';
    }
};
JS;

// ==============================================================================
// ACTION: delete_file
// ==============================================================================
$CP_REG['delete_file'] = [
    'desc' => 'Deletes a file or directory.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE'],
    'literal' =>[],
    'legacy' => true,
    'example' => <<<'EOT'
#ACTION: delete_file
#PATCH_ID: [ID]
#FILE: path/to/file.php
#END
EOT
];
function cp_preview_delete_file($patch, $realPath, &$res, $root) {
    if (!$realPath || !file_exists($realPath)) {
        $res['status'] = 'error';
        $res['msg'] = 'Path not found.';
    } else {
        $res['status'] = 'success';
        $res['msg'] = 'Ready to delete path.';
    }
}
function cp_commit_delete_file($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $f = $patch['file'];
    $buffers[$f] = null;
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['file_delete'] = {
    statusText: 'DESTRUCTION',
    statusColor: '#FF3B30',
    renderDiff: function(patch, res, idx) {
        return `<div class="cp-diff-old" style="padding:20px; background: rgba(255, 59, 48, 0.15); border: 2px dashed #FF3B30; border-radius: 10px; text-align:center;"><span class="cp-diff-label" style="color:#FF3B30; font-size:11px; margin-bottom:10px;">⚠️ PERMANENT DELETION</span><code style="font-size:14px; font-weight:900; color:#FF3B30;">${escapeHtml(patch.file)}</code></div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--danger); color:white; border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer;">Commit</button>` : '';
    }
};
window.CP_UI['delete_file'] = window.CP_UI['file_delete'];
JS;

// ==============================================================================
// ACTION: logic_trace
// ==============================================================================
$CP_REG['logic_trace'] = [
    'desc' => 'Verifies the existence of a code block without modifying it.',
    'required' => ['PATCH_ID', 'FILE', 'FIND', 'REPLACE'],
    'literal' => ['FIND', 'REPLACE'],
    'example' => <<<'EOT'
#ACTION: logic_trace
#PATCH_ID: [ID]
#FILE: path/to/file.php
#COMMENT: [Description of logic point to verify]
#FIND:
code to verify
#REPLACE:
code to verify
#END
EOT
];
function cp_preview_logic_trace($patch, $realPath, &$res, $root) {
    cp_preview_update_or_trace($patch, $realPath, $res, true);
}

// ==============================================================================
// ACTION: audit
// ==============================================================================
$CP_REG['audit'] = [
    'desc' => 'Scans the codebase for specific patterns or regular expressions.',
    'required' => ['PATCH_ID', 'ACTION', 'PATTERN'],
    'literal' => ['PATTERN'],
    'example' => <<<'EOT'
#ACTION: audit
#PATCH_ID: [ID]
#REGEX: true
#PATTERN:
my search pattern
#END
EOT
];
function cp_preview_audit($patch, $realPath, &$res, $root) {
    $res['status'] = 'success';
    $res['msg'] = 'Audit block parsed successfully.';
}

// ==============================================================================
// ACTION: refactor
// ==============================================================================
$CP_REG['refactor'] = [
    'desc' => 'Launches Search and Replace studio for bulk refactoring.',
    'required' => ['PATCH_ID', 'ACTION', 'FIND', 'REPLACE'],
    'literal' => ['FIND', 'REPLACE'],
    'example' => <<<'EOT'
#ACTION: refactor
#PATCH_ID: [ID]
#FIND:
old pattern
#REPLACE:
new pattern
#END
EOT
];
function cp_preview_refactor($patch, $realPath, &$res, $root) {
    $res['status'] = 'success';
    $res['msg'] = 'Refactor block parsed successfully.';
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['logic_trace'] = {
    statusText: 'TRACE',
    statusColor: '#FF9500',
    renderDiff: window.CP_UI['file_update'].renderDiff,
    renderExtraButtons: function(patch, res, idx, isDone, isErr) { return ''; }
};
window.CP_UI['audit'] = {
    statusText: 'AUDIT',
    statusColor: '#AF52DE',
    forceHideDiff: true,
    renderDiff: function() { return ''; },
    renderExtraButtons: function() { return ''; }
};
window.CP_UI['refactor'] = {
    statusText: 'REFACTOR',
    statusColor: '#FF9500',
    forceHideDiff: true,
    renderDiff: function() { return ''; },
    renderExtraButtons: function() { return ''; }
};
JS;

// ==============================================================================
// ACTION: download_icon
// ==============================================================================
$CP_REG['download_icon'] = [
    'desc' => 'Downloads an SVG icon from the Lucide CDN to the specified file path.',
    'required' => ['PATCH_ID', 'FILE', 'ICON_NAME'],
    'literal' => [],
    'example' => <<<'EOT'
#ACTION: download_icon
#PATCH_ID: [ID]
#FILE: app/data/icons/camera.svg
#ICON_NAME: camera
#END
EOT
];
function cp_preview_download_icon($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    $f = $patch['file'];
    $iconName = preg_replace('/[^a-z0-9\-]/', '', strtolower($patch['icon_name'] ?? ''));
    if (!$iconName) {
        $res['status'] = 'error';
        $res['msg'] = 'Missing required tag: ICON_NAME';
        return;
    }
    $fullPath = $root . $f;
    if (file_exists($fullPath)) {
        $res['msg'] = "Ready to download '{$iconName}' (Will overwrite existing file).";
    } else {
        $res['msg'] = "Ready to download '{$iconName}' to new file.";
    }
    $res['status'] = 'success';
    CPSandbox::$files[$f] = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>';
}
function cp_commit_download_icon($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $f = $patch['file'];
    $iconName = preg_replace('/[^a-z0-9\-]/', '', strtolower($patch['icon_name'] ?? ''));
    if (!$iconName) throw new Exception("Patch [{$patch['id']}] Failed: Missing or invalid ICON_NAME.");
    
    $url = "https://unpkg.com/lucide-static@latest/icons/{$iconName}.svg";
    if (!ini_get('allow_url_fopen')) throw new Exception("Patch [{$patch['id']}] Failed: allow_url_fopen is disabled.");

    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $http_response_header = [];
    $svg = @file_get_contents($url, false, $context);
    
    $isOk = false;
    foreach ($http_response_header as $header) {
        if (strpos($header, 'HTTP/') === 0 && strpos($header, '200') !== false) {
            $isOk = true; break;
        }
    }
    
    if ($svg && $isOk && strpos($svg, '<svg') !== false) {
        $buffers[$f] = $svg;
    } else {
        throw new Exception("Patch [{$patch['id']}] Failed: Icon '{$iconName}' not found on CDN or invalid SVG.");
    }
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['download_icon'] = {
    statusText: 'DOWNLOAD',
    statusColor: '#E91E63',
    renderDiff: function(patch, res, idx) {
        const iconName = (patch.icon_name || '').toLowerCase().replace(/[^a-z0-9\-]/g, '');
        const iconUrl = `https://unpkg.com/lucide-static@latest/icons/${iconName}.svg`;
        return `<div class="cp-diff-new" onclick="cpToggleDiff(${idx})" style="cursor:pointer; display:flex; align-items:stretch; justify-content:space-between; padding:0; padding-left:10px; min-height:42px; overflow:hidden;">
            <div style="flex:1; min-width:0; display:flex; flex-direction:column; justify-content:center; padding:4px 0;">
                <span class="cp-diff-label" style="margin:0; line-height:1.2;">⬇️ DOWNLOAD FROM CDN</span>
                <span class="cp-diff-line cp-diff-line-added" style="background:none; padding:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin:0; border:none;">
                    Fetch Lucide Icon: <strong>${escapeHtml(patch.icon_name)}</strong>
                </span>
            </div>
            <div style="width:42px; background:rgba(255,255,255,0.25); display:flex; align-items:center; justify-content:center; flex-shrink:0; border-left: 1px solid rgba(0,0,0,0.05);">
                <img src="${iconUrl}" style="width:24px; height:24px; display:block;" onerror="this.style.display='none'; this.parentElement.innerHTML='❓';">
            </div>
        </div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>` : '';
    }
};
JS;

// ==============================================================================
// ACTION: download_file
// ==============================================================================
$CP_REG['download_file'] = [
    'desc' => 'Downloads a file from a URL to the specified file path.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE', 'REPLACE'],
    'literal' => ['REPLACE'],
    'example' => <<<'EOT'
#ACTION: download_file
#PATCH_ID: [ID]
#FILE: path/to/destination.md
#REPLACE:
https://example.com/file.md
#END
EOT
];
function cp_preview_download_file($patch, $realPath, &$res, $root) {
    $url = trim($patch['replace'] ?? '');
    if (!$url) {
        $res['status'] = 'error';
        $res['msg'] = 'Missing URL in REPLACE block.';
        return;
    }
    
    if (preg_match('#^https?://github\.com/([^/]+)/([^/]+)/blob/([^/]+)/(.*)$#', $url, $m)) {
        $url = "https://raw.githubusercontent.com/{$m[1]}/{$m[2]}/{$m[3]}/{$m[4]}";
        $url = preg_replace('/\?.*$/', '', $url);
    }
    
    $context = stream_context_create(['http' => ['method' => 'HEAD', 'ignore_errors' => true, 'timeout' => 5]]);
    $headers = @get_headers($url, 1, $context);
    
    $isOk = false;
    if ($headers) {
        foreach ($headers as $k => $v) {
            if (is_numeric($k) && preg_match('#HTTP/\d+\.\d+ 200#i', $v)) {
                $isOk = true;
                break;
            }
        }
    }
    
    if (!$isOk) {
        $res['status'] = 'error';
        $res['msg'] = 'URL is unreachable or returned non-200 status.';
        return;
    }
    
    $size = 0;
    $headersLower = array_change_key_case($headers, CASE_LOWER);
    if (isset($headersLower['content-length'])) {
        $val = $headersLower['content-length'];
        $size = (int)(is_array($val) ? end($val) : $val);
    }
    
    $sizeStr = $size > 0 ? round($size / 1024, 2) . ' KB' : 'Unknown size';
    $fullPath = $root . $patch['file'];
    $existStr = file_exists($fullPath) ? "(Will overwrite)" : "(New file)";
    
    $res['status'] = 'success';
    $res['msg'] = "File available ($sizeStr). $existStr";
    $res['download_url'] = $url;
    $res['download_size'] = $sizeStr;
    CPSandbox::$files[$patch['file']] = "STAGED_REMOTE_CONTENT";
}
function cp_commit_download_file($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $f = $patch['file'];
    $url = trim($patch['replace'] ?? '');
    
    if (preg_match('#^https?://github\.com/([^/]+)/([^/]+)/blob/([^/]+)/(.*)$#', $url, $m)) {
        $url = "https://raw.githubusercontent.com/{$m[1]}/{$m[2]}/{$m[3]}/{$m[4]}";
        $url = preg_replace('/\?.*$/', '', $url);
    }
    
    if (!ini_get('allow_url_fopen')) throw new Exception("Patch [{$patch['id']}] Failed: allow_url_fopen is disabled.");

    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 15]]);
    $content = @file_get_contents($url, false, $context);
    
    if ($content === false) {
        throw new Exception("Patch [{$patch['id']}] Failed: Could not download from URL.");
    }
    
    $buffers[$f] = $content;
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['download_file'] = {
    statusText: 'DOWNLOAD',
    statusColor: '#00C7BE',
    renderDiff: function(patch, res, idx) {
        const url = res.download_url || patch.replace.trim();
        const size = res.download_size || 'Unknown';
        return `<div class="cp-diff-new" onclick="cpToggleDiff(${idx})" style="cursor:pointer; padding:6px 12px; display:flex; flex-direction:column; gap:2px;">
            <span class="cp-diff-label" style="margin:0;">⬇️ DOWNLOAD FILE</span>
            <div style="font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                <strong>Source:</strong> <a href="${escapeHtml(url)}" target="_blank" style="color:var(--primary);">${escapeHtml(url)}</a>
            </div>
            <div style="font-size:11px;"><strong>Size:</strong> ${size}</div>
        </div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>` : '';
    }
};
JS;

// ==============================================================================
// ACTION: export
// ==============================================================================
$CP_REG['export'] = [
    'desc' => 'Exports the full content of a file.',
    'required' => ['PATCH_ID', 'FILE'],
    'literal' => [],
    'legacy' => true,
    'example' => <<<'EOT'
#ACTION: export
#PATCH_ID: [ID]
#FILE: path/to/file.php
#END
EOT
];
function cp_preview_export($patch, $realPath, &$res, $root) {
    if (!$realPath || strpos($realPath, realpath($root)) !== 0 || !file_exists($realPath)) {
        $res['status'] = 'error';
        $res['msg'] = 'File not found for export.';
    } else {
        $content = file_get_contents($realPath);
        $relPath = $patch['file'];
        $res['status'] = 'success';
        $res['msg'] = 'Source captured.';
        $res['export_block'] = "```\n================================================================================\nFILE START: $relPath\n================================================================================\n" . $content . "\n```\n\n";
    }
}
function cp_commit_file_export($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    // Read-only inspection action: no file modifications required during commit
}
function cp_commit_export($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    // Read-only inspection action: no file modifications required during commit
}
function cp_commit_logic_trace($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    // Read-only inspection action: no file modifications required during commit
}

// ==============================================================================
// ACTION: file_create
// ==============================================================================
$CP_REG['file_create'] = [
    'desc' => 'Creates a new file with the specified content.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE', 'COMMENT', 'REPLACE'],
    'literal' => ['REPLACE'],
    'example' => "#" . "ACTION: file_create\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "FILE: path/to/new_file.php\n" .
                 "#" . "COMMENT: [Description of new file]\n" .
                 "#" . "REPLACE:\n" .
                 "<?php\n" .
                 "// new content\n" .
                 "?>\n" .
                 "#" . "END"
];
function cp_preview_file_create($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    $f = $patch['file'];
    $fullPath = $root . $f;
    if (isset(CPSandbox::$files[$f]) || file_exists($fullPath)) {
        $res['status'] = 'error';
        $res['msg'] = 'File already exists. Overwriting is strictly forbidden.';
    } else {
        $res['status'] = 'success';
        $res['msg'] = 'Ready to create new file.';
        CPSandbox::$files[$f] = cp_normalize($patch['replace'] ?? '');
    }
}
function cp_commit_file_create($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    cp_commit_create($patch, $buffers, $variables, $variables_used, $forceLiteral);
}

// ==============================================================================
// ACTION: file_move
// ==============================================================================
$CP_REG['file_move'] = [
    'desc' => 'Moves or renames a file.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE', 'DEST_FILE'],
    'literal' => [],
    'example' => "#" . "ACTION: file_move\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "FILE: path/to/old.php\n" .
                 "#" . "DEST_FILE: path/to/new.php\n" .
                 "#" . "END"
];
function cp_preview_file_move($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    $f = $patch['file'];
    $dest = $patch['dest_file'] ?? '';
    $fullPath = $root . $f;
    $destFull = $root . $dest;
        
    $srcExists = (isset(CPSandbox::$files[$f]) || file_exists($fullPath));
    $destExists = (isset(CPSandbox::$files[$dest]) || file_exists($destFull));
        
    if (!$srcExists) {
        $res['status'] = 'error';
        $res['msg'] = 'Source file not found.';
    } elseif ($destExists) {
        $res['status'] = 'error';
        $res['msg'] = 'Destination file already exists.';
    } else {
        $res['status'] = 'success';
        $res['msg'] = 'Ready to move file to: ' . $dest;
        $srcContent = isset(CPSandbox::$files[$f]) ? CPSandbox::$files[$f] : @file_get_contents($fullPath);
        CPSandbox::$files[$dest] = cp_normalize($srcContent);
        CPSandbox::$files[$f] = null; // Mark as deleted
    }
}
function cp_commit_file_move($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    cp_commit_move_file($patch, $buffers, $variables, $variables_used, $forceLiteral);
}

// ==============================================================================
// ACTION: file_delete
// ==============================================================================
$CP_REG['file_delete'] = [
    'desc' => 'Deletes a file or directory.',
    'required' => ['PATCH_ID', 'ACTION', 'FILE'],
    'literal' => [],
    'example' => "#" . "ACTION: file_delete\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "FILE: path/to/file.php\n" .
                 "#" . "END"
];
function cp_preview_file_delete($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    $f = $patch['file'];
    $fullPath = $root . $f;
    $exists = (array_key_exists($f, CPSandbox::$files) ? (CPSandbox::$files[$f] !== null) : file_exists($fullPath));
        
    if (!$exists) {
        $res['status'] = 'error';
        $res['msg'] = 'Path not found.';
    } else {
        $res['status'] = 'success';
        $res['msg'] = 'Ready to delete path.';
        CPSandbox::$files[$f] = null; // Mark as deleted
    }
}
function cp_commit_file_delete($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    cp_commit_delete_file($patch, $buffers, $variables, $variables_used, $forceLiteral);
}

// ==============================================================================
// ACTION: file_export_skeleton
// ==============================================================================
$CP_REG['file_export_skeleton'] = [
    'desc' => 'Exports the structural skeleton (signatures, variables, bridge methods) of a file.',
    'required' => ['PATCH_ID', 'FILE'],
    'literal' => [],
    'example' => "#" . "ACTION: file_export_skeleton\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "FILE: path/to/file.php\n" .
                 "#" . "END"
];
function cp_preview_file_export_skeleton($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    $f = $patch['file'];
    $fullPath = $root . $f;
    $content = null;

    if (isset(CPSandbox::$files[$f]) && CPSandbox::$files[$f] !== null) {
        $content = CPSandbox::$files[$f];
    } elseif (file_exists($fullPath) && !is_dir($fullPath)) {
        $content = file_get_contents($fullPath);
    }

    if ($content !== null) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!function_exists('ce_skeletonize_content')) {
            $cePath = defined('CJOS_PATH_PLUGINS') ? (CJOS_PATH_PLUGINS . '/ContextExporter.php') : (realpath(__DIR__) . '/ContextExporter.php');
            if (file_exists($cePath)) @include_once $cePath;
        }
        $skeleton = function_exists('ce_skeletonize_content') ? ce_skeletonize_content($content, $ext) : $content;
        $res['status'] = 'success';
        $res['msg'] = 'Structural skeleton captured.';
        $res['export_block'] = "```\n================================================================================\nFILE SKELETON: $f\n================================================================================\n" . $skeleton . "\n```\n\n";
    } else {
        $res['status'] = 'error';
        $res['msg'] = 'File not found for skeleton export.';
    }
}
function cp_commit_file_export_skeleton($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    // Read-only inspection action: no file modifications required during commit
}

// ==============================================================================
// ACTION: file_export
// ==============================================================================
$CP_REG['file_export'] = [
    'desc' => 'Exports the full content of a file.',
    'required' => ['PATCH_ID', 'FILE'],
    'literal' => [],
    'example' => "#" . "ACTION: file_export\n" .
                 "#" . "PATCH_ID: [ID]\n" .
                 "#" . "FILE: path/to/file.php\n" .
                 "#" . "END"
];
function cp_preview_file_export($patch, $realPath, &$res, $root, &$temp_vars = null, &$temp_file_buffers = null) {
    $f = $patch['file'];
    $fullPath = $root . $f;
    if (isset(CPSandbox::$files[$f]) && CPSandbox::$files[$f] !== null) {
        $res['status'] = 'success';
        $res['msg'] = 'Source captured from virtual staging.';
        $res['export_block'] = "```\n================================================================================\nFILE START: $f (Staged)\n================================================================================\n" . CPSandbox::$files[$f] . "\n```\n\n";
    } else {
        cp_preview_export($patch, $realPath, $res, $root);
    }
}$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['file_export_skeleton'] = {
    statusText: 'SKELETON',
    statusColor: '#5856D6',
    hideDiffOnDone: true,
    forceHideDiff: true,
    renderDiff: function() { return ''; },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone) ? `<button onclick="cpAddDependencies(${idx})" style="background:var(--ai-accent-bg); color:var(--ai-accent); border:1px solid rgba(88, 86, 214, 0.2); padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer;">Source + Deps</button>` : '';
    }
};
window.CP_UI['export_skeleton'] = window.CP_UI['file_export_skeleton'];

window.CP_UI['file_export'] = {
    statusText: 'EXPORT',
    statusColor: '#007AFF',
    hideDiffOnDone: true,
    forceHideDiff: true,
    renderDiff: function() { return ''; },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone) ? `<button onclick="cpAddDependencies(${idx})" style="background:var(--ai-accent-bg); color:var(--ai-accent); border:1px solid rgba(88, 86, 214, 0.2); padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer;">Source + Deps</button>` : '';
    }
};
window.CP_UI['export'] = window.CP_UI['file_export'];
JS;

// ==============================================================================
// ACTION: edit_log
// ==============================================================================
$CP_REG['edit_log'] = [
    'desc' => 'Appends a summary of changes to the system edit log.',
    'required' => ['PATCH_ID', 'FILE', 'REPLACE'],
    'literal' => ['FIND', 'REPLACE'],
    'example' => <<<'EOT'
#ACTION: edit_log
#PATCH_ID: [ID]
#FILE: app/data/edit-log.json
#COMMENT: [Summary of changes]
#REPLACE:
Summary of changes here.
#END
EOT
];
function cp_refresh_edit_log_state_manifest($db_file) {
    $state_file = defined('CJOS_PATH_DATA')
        ? (CJOS_PATH_DATA . '/edit-log-state.json')
        : (realpath(__DIR__ . '/../data') . '/edit-log-state.json');

    try {
        $db = new PDO('sqlite:' . $db_file);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE IF NOT EXISTS edit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, date DATETIME DEFAULT CURRENT_TIMESTAMP, summary TEXT)");

        $rows = $db->query("SELECT date, summary FROM edit_log ORDER BY id DESC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new Exception('Could not encode edit-log state manifest.');
        }

        $tmp_file = $state_file . '.tmp.' . getmypid();
        try {
            if (file_put_contents($tmp_file, $json, LOCK_EX) !== false) {
                @rename($tmp_file, $state_file);
            }
        } finally {
            if (file_exists($tmp_file)) {
                @unlink($tmp_file);
            }
            foreach (glob($state_file . '.tmp.*') as $orphan) {
                @unlink($orphan);
            }
        }
    } catch (Exception $e) {
        error_log("EditLog state manifest update error: " . $e->getMessage());
    }
}

function cp_execute_edit_log($summary) {
    if (empty(trim($summary))) return;
    $summary = trim($summary);

    $db_file = defined('CJOS_PATH_DATA') ? (CJOS_PATH_DATA . '/edit-log.db') : (realpath(__DIR__ . '/../data') . '/edit-log.db');
    if (file_exists(dirname($db_file))) {
        try {
            $db = new PDO('sqlite:' . $db_file);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec("CREATE TABLE IF NOT EXISTS edit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, date DATETIME DEFAULT CURRENT_TIMESTAMP, summary TEXT)");
            
            $stmt = $db->prepare("INSERT INTO edit_log (date, summary) VALUES (DATETIME('now', 'localtime'), ?)");
            $stmt->execute([$summary]);
            cp_refresh_edit_log_state_manifest($db_file);
        } catch (Exception $e) {
            error_log("EditLog DB write error: " . $e->getMessage());
        }
    }

    $json_file = defined('CJOS_PATH_DATA') ? (CJOS_PATH_DATA . '/edit-log.json') : (realpath(__DIR__ . '/../data') . '/edit-log.json');
    if (file_exists($json_file)) {
        try {
            $data = json_decode(file_get_contents($json_file), true);
            if (!is_array($data)) $data = [];
            array_unshift($data, [
                'date' => date('Y-m-d H:i:s'),
                'summary' => $summary
            ]);
            file_put_contents($json_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            error_log("EditLog JSON write error: " . $e->getMessage());
        }
    }
}

function cp_preview_edit_log($patch, $realPath, &$res, $root) {
    $res['status'] = 'success';
    $res['msg'] = 'Ready to append text to System Edit Log.';
}

function cp_commit_edit_log($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $summary = $patch['replace'] ?? ($patch['comment'] ?? '');
    if (!empty($summary)) {
        cp_execute_edit_log($summary);
    }
}
$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['edit_log'] = {
    statusText: 'LOG',
    statusColor: '#AF52DE',
    renderDiff: window.CP_UI['file_update'].renderDiff,
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return (!isDone && !isErr) ? `<button onclick="cpCommitSingle(${idx})" style="background:var(--primary); color:var(--primary-text); border:none; padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(0,122,255,0.2);">Commit</button>` : '';
    }
};
JS;

// ==============================================================================
// ACTION: refactor_var
// ==============================================================================
$CP_REG['refactor_var'] = [
    'desc' => 'Performs surgical find/replace on a cut variable (useful for renaming vars or fixing indentation during moves).',
    'required' => ['PATCH_ID', 'ACTION', 'VAR_NAME', 'COMMENT', 'FIND', 'REPLACE'],
    'literal' => ['FIND', 'REPLACE'],
    'legacy' => true,
    'example' => <<<'EOT'
#ACTION: refactor_var
#PATCH_ID: [ID]
#VAR_NAME: MY_CODE_BLOCK
#COMMENT: [Description of refactor]
#FIND:
old_variable_name
#REPLACE:
new_variable_name
#END
EOT
];

function cp_preview_refactor_var($patch, $realPath, &$res, $root, &$temp_vars = null) {
    cp_preview_patch_var($patch, $realPath, $res, $root, $temp_vars);
}

function cp_commit_refactor_var($patch, &$buffers, &$variables, &$variables_used, $forceLiteral) {
    $varName = $patch['var_name'] ?? '';
    if (!isset($variables[$varName])) {
        throw new Exception("Patch [{$patch['id']}] Failed: Variable '{$varName}' not found. Did you cut it first?");
    }
    
    $find = cp_normalize($patch['find']);
    $replaceStr = cp_normalize($patch['replace']);
    $content = $variables[$varName];
    
    // Support multiple occurrences within the variable
    if (strpos($content, $find) === false) {
        throw new Exception("Patch [{$patch['id']}] Failed: Refactor target not found in variable '{$varName}'.");
    }
    
    $variables[$varName] = str_replace($find, $replaceStr, $content);
}

$CP_JS_HANDLERS .= <<<'JS'
window.CP_UI['var_refactor'] = {
    statusText: 'REFACTOR VAR',
    statusColor: '#AF52DE',
    renderDiff: function(patch, res, idx) {
        return `<div class="cp-diff-old" onclick="cpToggleDiff(${idx})" style="cursor:pointer;"><span class="cp-diff-label">REFACTOR VARIABLE {{${patch.var_name}}}</span>${cpFormatDiffLines(res.find, res.replace, 'remove')}</div><div class="cp-diff-new" onclick="cpToggleDiff(${idx})" style="cursor:pointer;"><span class="cp-diff-label">✚ NEW LOGIC</span>${cpFormatDiffLines(res.replace, res.find, 'add', true)}</div>`;
    },
    renderExtraButtons: function(patch, res, idx, isDone, isErr) {
        return ''; // Usually part of a batch, no single commit needed
    }
};
window.CP_UI['refactor_var'] = window.CP_UI['var_refactor'];
JS;

// --- STAGED SESSION HELPERS (SERVER-PERSISTED PREFLIGHT SESSIONS) ---
function cp_get_staged_dir() {
    $dir = defined('CJOS_PATH_DATA') ? (CJOS_PATH_DATA . '/staged_patches') : (realpath(__DIR__ . '/../data') . '/staged_patches');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function cp_prune_staged_sessions() {
    $dir = cp_get_staged_dir();
    if (!is_dir($dir)) return;
    $files = glob($dir . '/SES_*.json');
    if (empty($files)) return;

    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $maxSessions = 1;
    $maxAge = 86400; // 24 hours
    $now = time();

    foreach ($files as $idx => $f) {
        if ($idx >= $maxSessions || ($now - filemtime($f) > $maxAge)) {
            @unlink($f);
        }
    }
}

function cp_save_failed_session($patches, $rawInput, $existingSessionId = null) {
    cp_prune_staged_sessions();
    $dir = cp_get_staged_dir();
    
    $sessionId = $existingSessionId;
    if (!$sessionId || !preg_match('/^SES_[A-Z0-9]{6,12}$/i', $sessionId)) {
        $sessionId = 'SES_' . strtoupper(substr(md5(uniqid(microtime(), true)), 0, 8));
    }

    $sessionFile = $dir . '/' . $sessionId . '.json';
    $data = [
        'session_id' => $sessionId,
        'updated_at' => time(),
        'raw_input' => $rawInput,
        'patches' => $patches
    ];

    file_put_contents($sessionFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    cp_prune_staged_sessions();
    return $sessionId;
}

function cp_get_staged_session($sessionId = null) {
    if (!$sessionId || !preg_match('/^SES_[A-Z0-9]{6,12}$/i', $sessionId)) {
        return null;
    }

    $dir = cp_get_staged_dir();
    if (!is_dir($dir)) return null;

    $file = $dir . '/' . $sessionId . '.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['patches'])) return $data;
    }

    return null;
}

function cp_clear_staged_session($sessionId) {
    if (!$sessionId || !preg_match('/^SES_[A-Z0-9]{6,12}$/i', $sessionId)) return;
    $dir = cp_get_staged_dir();
    $file = $dir . '/' . $sessionId . '.json';
    if (file_exists($file)) {
        @unlink($file);
    }
}

function cp_splice_surgical_fixes($stagedPatches, $fixPatches) {
    $stagedMap = [];
    foreach ($stagedPatches as $idx => $p) {
        $id = $p['_id'] ?? $p['id'] ?? null;
        if ($id) {
            $stagedMap[$id] = $idx;
        }
    }

    $updatedBatch = $stagedPatches;

    foreach ($fixPatches as $fix) {
        $targetId = $fix['_id'] ?? $fix['id'] ?? null;
        if ($targetId && isset($stagedMap[$targetId])) {
            $targetIdx = $stagedMap[$targetId];
            $fix['_isFix'] = true;
            $updatedBatch[$targetIdx] = $fix;
        } else {
            $fix['_isFix'] = true;
            $updatedBatch[] = $fix;
        }
    }

    return $updatedBatch;
}

// --- SSOT PHP PROTOCOL V10 PARSER ---
function cp_parse_raw_input($text) {
    global $CP_REG;
    $patches = [];
    $normalized = str_replace(["\r\n", "\r"], "\n", $text);
    $inputTrimmed = trim($normalized);

    // JSON Fallback Parser Guard (Agent API & Legacy JSON Support)
    if (strpos($inputTrimmed, '{') === 0 || strpos($inputTrimmed, '[') === 0) {
        $data = json_decode($inputTrimmed, true);
        if ($data) {
            $rawList = [];
            if (isset($data['patches']) && is_array($data['patches'])) {
                $rawList = $data['patches'];
            } elseif (isset($data['fixes']) && is_array($data['fixes'])) {
                foreach ($data['fixes'] as $fix) {
                    if (isset($fix['patches']) && is_array($fix['patches'])) {
                        foreach ($fix['patches'] as $fp) {
                            $fp['target_id'] = $fix['target_id'] ?? null;
                            $rawList[] = $fp;
                        }
                    }
                }
            } elseif (is_array($data) && isset($data[0]['action'])) {
                $rawList = $data;
            }

            foreach ($rawList as $p) {
                $act = cp_normalize_action_name($p['action'] ?? $p['#ACTION'] ?? 'file_update');
                $patches[] = [
                    '_id' => $p['_id'] ?? $p['patch_id'] ?? $p['#PATCH_ID'] ?? $p['id'] ?? $p['target_id'] ?? null,
                    'file' => $p['file'] ?? $p['#FILE'] ?? '',
                    'dest_file' => $p['dest_file'] ?? $p['#DEST_FILE'] ?? '',
                    'find' => $p['find'] ?? $p['#FIND'] ?? '',
                    'replace' => $p['replace'] ?? $p['#REPLACE'] ?? '',
                    'delete_start' => $p['delete_start'] ?? $p['#DELETE_START'] ?? '',
                    'delete_end' => $p['delete_end'] ?? $p['#DELETE_END'] ?? '',
                    'range_start' => $p['range_start'] ?? $p['#RANGE_START'] ?? '',
                    'range_end' => $p['range_end'] ?? $p['#RANGE_END'] ?? '',
                    'spacing' => $p['spacing'] ?? $p['#SPACING'] ?? 'inline',
                    'match_index' => isset($p['match_index']) ? (int)$p['match_index'] : (isset($p['#MATCH']) ? (int)$p['#MATCH'] : 1),
                    'comment' => $p['comment'] ?? $p['#COMMENT'] ?? 'JSON Patch',
                    'action' => $act,
                    'var_name' => $p['var_name'] ?? $p['#VAR_NAME'] ?? null,
                    'icon_name' => $p['icon_name'] ?? $p['#ICON_NAME'] ?? null,
                    'audit_link' => $p['audit_link'] ?? $p['#AUDIT_LINK'] ?? null,
                    'regex' => $p['regex'] ?? $p['#REGEX'] ?? null,
                    'pattern' => $p['pattern'] ?? $p['#PATTERN'] ?? null
                ];
            }

            if (!empty($patches)) {
                return [
                    'patches' => $patches,
                    'residue' => '',
                    'session_id' => $data['session_id'] ?? null,
                    'input_format' => 'json'
                ];
            }
        }
    }

    $sessionId = null;
    if (preg_match('/^#SESSION_ID:\s*([^\n]+)/m', $normalized, $sMatch)) {
        $sessionId = trim($sMatch[1]);
    }
    
    $blockRegex = '/(?:^|\n)(#(?:ACTION|PATCH_ID):[\s\S]*?\n#END(?:[:\s][^\n]*)?)/i';
    $leftover = $normalized;

    if (preg_match_all($blockRegex, $normalized, $matches)) {
        foreach ($matches[0] as $block) {
            if (!trim($block)) continue;

            $action = "file_update";
            if (preg_match('/^#ACTION:\s*([^\n]+)/m', $block, $aMatch)) {
                $action = trim($aMatch[1]);
            }

            if ($action === 'update') $action = 'file_update';
            if ($action === 'patch_var') $action = 'var_patch';
            if ($action === 'refactor_var') $action = 'var_refactor';
            if ($action === 'delete_code') $action = 'code_delete';
            if ($action === 'cut_code') $action = 'code_cut';
            if ($action === 'create') $action = 'file_create';
            if ($action === 'move_file') $action = 'file_move';
            if ($action === 'delete_file') $action = 'file_delete';
            if ($action === 'export') $action = 'file_export';
            if ($action === 'code_logic_trace') $action = 'logic_trace';

            $reg = $CP_REG[$action] ?? ($CP_REG['file_update'] ?? []);
            $literalTags = $reg['literal'] ?? [];
            $requiredTags = $reg['required'] ?? [];

            $globalTags = ['PATCH_ID', 'ACTION', 'FILE', 'DEST_FILE', 'MATCH', 'COMMENT', 'AUDIT_LINK', 'SPACING', 'VAR_NAME', 'CONSUMER_ID', 'RANGE_START', 'RANGE_END', 'DELETE_START', 'DELETE_END', 'ICON_NAME', 'REGEX', 'PATTERN', 'FIND', 'REPLACE', 'SESSION_ID'];
            $validTags = array_unique(array_merge($globalTags, $requiredTags, $literalTags));

            $lines = explode("\n", $block);
            $parsedData = [];
            $currentTag = null;
            $isLiteral = false;

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (strpos($trimmed, '#END') === 0) break;

                if (preg_match('/^#([A-Z_]+):?(.*)$/', $line, $tagMatch)) {
                    $tagName = $tagMatch[1];
                    if (in_array($tagName, $validTags)) {
                        $currentTag = $tagName;
                        $isLiteral = in_array($currentTag, $literalTags);
                        $inlineText = $tagMatch[2];
                        if (strpos($inlineText, ' ') === 0) $inlineText = substr($inlineText, 1);
                        $parsedData[$currentTag] = $inlineText;
                        continue;
                    }
                }
                if ($currentTag !== null) {
                    $parsedData[$currentTag] = ($parsedData[$currentTag] ?? '') . "\n" . $line;
                }
            }

            foreach ($parsedData as $k => $v) {
                if (in_array($k, $literalTags)) {
                    if (strpos($v, "\n") === 0) $parsedData[$k] = substr($v, 1);
                } else {
                    $parsedData[$k] = trim($v);
                }
            }

            $isValid = true;
            $missingTags = [];
            foreach ($requiredTags as $req) {
                if (!isset($parsedData[$req])) {
                    $missingTags[] = $req;
                    $isValid = false;
                }
            }

            if ($isValid || !empty($parsedData['PATCH_ID'])) {
                $patches[] = [
                    '_id' => $parsedData['PATCH_ID'] ?? null,
                    'file' => $parsedData['FILE'] ?? '',
                    'dest_file' => $parsedData['DEST_FILE'] ?? '',
                    'find' => $parsedData['FIND'] ?? '',
                    'replace' => $parsedData['REPLACE'] ?? '',
                    'delete_start' => $parsedData['DELETE_START'] ?? '',
                    'delete_end' => $parsedData['DELETE_END'] ?? '',
                    'range_start' => $parsedData['RANGE_START'] ?? '',
                    'range_end' => $parsedData['RANGE_END'] ?? '',
                    'spacing' => $parsedData['SPACING'] ?? 'inline',
                    'match_index' => isset($parsedData['MATCH']) ? (int)$parsedData['MATCH'] : 1,
                    'comment' => $parsedData['COMMENT'] ?? 'Auto-patch',
                    'action' => $action,
                    'var_name' => $parsedData['VAR_NAME'] ?? null,
                    'icon_name' => $parsedData['ICON_NAME'] ?? null,
                    'audit_link' => $parsedData['AUDIT_LINK'] ?? null,
                    'regex' => $parsedData['REGEX'] ?? null,
                    'pattern' => $parsedData['PATTERN'] ?? null,
                    '_parse_error' => !$isValid ? ('Missing required tag(s): #' . implode(', #', $missingTags)) : null
                ];
                $leftover = str_replace($block, " [PATCH_BLOCK_REMOVED] ", $leftover);
            }
        }
    }

    $residue = trim(str_replace(" [PATCH_BLOCK_REMOVED] ", "", $leftover));
    return ['patches' => $patches, 'residue' => $residue, 'session_id' => $sessionId];
}

function cp_generate_error_report($errorResults, $patches, $sessionId = null, $isJson = false, $isTrace = false) {
    $text = "";
    if (!$isTrace) {
        $text .= "⚠️ TRANSACTION HALTED (PREFLIGHT ON HOLD): Changes for this batch are on hold because one or more patches failed preflight checks. Provide surgical fixes using the same #PATCH_IDs. Once surgical fixes are committed by the user, all patches apply to disk as live code. Never assume committed fixes failed in future turns.\n\n";
    }
    $text .= $isTrace 
        ? "## LOGIC TRACE FAILED: " . count($errorResults) . " Points Mismatched.\n"
        : "## PREFLIGHT DIAGNOSTIC REPORT: " . count($errorResults) . " Patches Failed.\n";
    
    if ($sessionId) {
        $text .= "#SESSION_ID: " . $sessionId . "\n";
    }
    $text .= "SYSTEM: Conjure Patcher Engine\n\n";

    // 1. DATA DUMP
    $text .= "~~~\nFAILED PATCHES DATA:\n";
    $text .= "``` " . ($isJson ? 'json' : 'text') . "\n";
    
    if ($isJson) {
        $failedPatches = [];
        foreach ($errorResults as $res) {
            if (isset($patches[$res['id']])) {
                $failedPatches[] = $patches[$res['id']];
            }
        }
        $text .= json_encode(['patches' => $failedPatches], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        foreach ($errorResults as $res) {
            $p = $patches[$res['id']] ?? null;
            if (!$p) continue;
            $patchId = !empty($p['_id']) ? $p['_id'] : (!empty($p['id']) ? $p['id'] : (!empty($p['patch_id']) ? $p['patch_id'] : 'unknown'));
            $text .= "#PATCH_ID: " . $patchId . "\n";
            $text .= "#FILE: " . ($p['file'] ?? '') . "\n";

            if (!empty($p['audit_link']) && !empty($res['audit_link_error'])) {
                $text .= "#AUDIT_LINK: " . $p['audit_link'] . "\n";
                $text .= "#ERROR: Audit Protocol Failure\n";
                $text .= "#REMARK: The code block matched perfectly, but the #AUDIT_LINK is malformed (Check filename).\n";
            } else {
                $text .= "#ERROR: " . ($res['msg'] ?? 'Preflight failed') . "\n";
            }

            $originalFind = $p['find'] ?: ($p['range_start'] ?: ($p['delete_start'] ?: ''));
            $text .= "\n# --- ORIGINAL FIND (FAILED) ---\n" . $originalFind . "\n";

            if (!empty($res['hint'])) {
                $text .= "\n# --- DIAGNOSTIC HINT (USE THIS FOR #FIND) ---\n" . $res['hint'] . "\n";
            } else {
                $text .= "\n# --- DIAGNOSTIC HINT ---\n(No partial match found. Check the file content manually.)\n";
            }

            $text .= "\n# --- ORIGINAL REPLACE ---\n" . ($p['replace'] ?? '') . "\n";
            $text .= "#END\n\n";
        }
    }
    $text .= "\n```\n\n";

    if ($isTrace) {
        $text .= "### ANALYSIS REQUIRED\n";
        $text .= "The logic points above do not match the current source code. Do not attempt a surgical repair. Instead, re-examine the files, update your mental model of the execution path, and provide a NEW Logic Trace.";
        $text .= "\n~~~\n";
        return $text;
    }

    // 2. AI DECISION MANDATE
    $text .= "### AI DECISION MANDATE\n";
    $text .= "Analyze the failures above. You MUST choose one of the following two paths and explicitly tell the user which one you are taking:\n\n";
    $text .= "PATH A: SURGICAL REPAIR (Recommended for minor mismatches)\n";
    $text .= "- Use the existing #PATCH_IDs to provide fixes.\n";
    if ($sessionId) {
        $text .= "- Include #SESSION_ID: " . $sessionId . " at the top of your fix block.\n";
    }
    $text .= "- Tell the user: \"I have provided surgical fixes. Click 'Update Staged' to apply them.\"\n\n";
    $text .= "PATH B: FRESH START (Required if logic has shifted, files reorganized, or >50% failed)\n";
    $text .= "- Provide an entirely new, complete set of patches with new IDs.\n";
    $text .= "- Tell the user: \"Logic has shifted significantly. Click 'Clear' in the Patcher, then paste this new block and click 'Analyze & Stage'.\"\n\n";

    // 3. INSTRUCTIONS & EXAMPLES
    $firstRes = reset($errorResults);
    $tid = 'patch_1';
    if ($firstRes && isset($patches[$firstRes['id']])) {
        $fp = $patches[$firstRes['id']];
        $tid = !empty($fp['_id']) ? $fp['_id'] : (!empty($fp['id']) ? $fp['id'] : (!empty($fp['patch_id']) ? $fp['patch_id'] : 'patch_1'));
    }
    $text .= "### SURGICAL REPAIR INSTRUCTIONS\n";

    $sidTag = $sessionId ? "#SESSION_ID: {$sessionId}\n" : "";

    if ($isJson) {
        $text .= "To fix these errors, return a JSON object with a \"fixes\" array targeting the \"target_id\".\n\n";
        $text .= "EXAMPLE 1: STANDARD FIX\n";
        $text .= "```json\n{\n  \"fixes\": [\n    {\n      \"target_id\": \"{$tid}\",\n      \"patches\": [{ \"action\": \"[ACTION]\", \"file\": \"path/to/file.php\", \"find\": \"[CODE]\", \"replace\": \"[NEW]\" }]\n    }\n  ]\n}\n```\n\n";
    } else {
        $text .= "To fix these errors, return a FIXED RawBlock using the SAME #PATCH_ID as the failed patch.\n\n";
        $text .= "EXAMPLE 1: STANDARD FIX\n";
        $text .= "```text\n{$sidTag}#ACTION: [ACTION]\n#PATCH_ID: {$tid}\n#FILE: [PATH]\n#FIND:\n[CORRECTED_CODE]\n#REPLACE:\n[NEW_CODE]\n#END\n```\n\n";
        $text .= "EXAMPLE 2: SPLIT FIX (Repeat ID to replace 1 failed patch with multiple small ones)\n";
        $text .= "```text\n{$sidTag}#ACTION: [ACTION]\n#PATCH_ID: {$tid}\n#FILE: [PATH]\n#FIND:\n[PART_A]\n#REPLACE:\n[NEW_A]\n#END\n\n{$sidTag}#ACTION: [ACTION]\n#PATCH_ID: {$tid}\n#FILE: [PATH]\n#FIND:\n[PART_B]\n#REPLACE:\n[NEW_B]\n#END\n```\n";
    }
    $text .= "\n~~~\n";
    return $text;
}

// --- BACKEND HANDLERS ---

// Global CORS preflight handler for API requests
if (isset($_SERVER['HTTP_ORIGIN']) || (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS')) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Access-Control-Allow-Headers: *");
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        while (ob_get_level()) ob_end_clean();
        exit(0);
    }
}

if (isset($_POST['plugin_action'])) {

    // 0. AGENT HEADLESS EXECUTE ENDPOINT
    if ($_POST['plugin_action'] === 'cp_agent_execute') {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');
        while (ob_get_level()) @ob_end_clean();
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: *');
        header('Content-Type: application/json');
        
        $rawInput = $_POST['raw_input'] ?? ($_POST['payload'] ?? '');
        if (empty(trim($rawInput))) {
            echo json_encode(['status' => 'error', 'message' => 'No patch payload received.']);
            exit;
        }

        $parsed = cp_parse_raw_input($rawInput);
        $patches = $parsed['patches'];
        $sessionId = $parsed['session_id'] ?? ($_POST['session_id'] ?? null);

        if (empty($patches)) {
            echo json_encode(['status' => 'error', 'message' => 'No valid Protocol V10 or JSON patch blocks parsed from input.']);
            exit;
        }

        // Session Binding & Surgical Fix Splicing
        $session = cp_get_staged_session($sessionId);
        if ($session) {
            $sessionId = $session['session_id'];
            $stagedIds = array_filter(array_map(function($p) { return $p['_id'] ?? $p['id'] ?? null; }, $session['patches']));
            $hasFixes = false;
            foreach ($patches as $p) {
                $pId = $p['_id'] ?? $p['id'] ?? null;
                if ($pId && in_array($pId, $stagedIds)) {
                    $hasFixes = true;
                    break;
                }
            }
            if ($parsed['session_id'] || $hasFixes) {
                $patches = cp_splice_surgical_fixes($session['patches'], $patches);
            }
        }

        // --- Check for Read-Only Transactions (Export / Audit / Logic Trace) ---
        $isExportOnly = true;
        $isAuditOnly = true;
        foreach ($patches as $p) {
            $act = $p['action'] ?? '';
            if ($act !== 'file_export' && $act !== 'export' && $act !== 'file_export_skeleton' && $act !== 'export_skeleton') $isExportOnly = false;
            if ($act !== 'audit') $isAuditOnly = false;
        }

        if ($isExportOnly) {
            $root = CJOS_PATH_ROOT . DIRECTORY_SEPARATOR;
            $exportedBlocks = [];
            $exportedFiles = [];

            foreach ($patches as $p) {
                $act = $p['action'] ?? '';
                $f = ltrim(str_replace('\\', '/', $p['file'] ?? ''), '/');
                $full = $root . $f;
                if (file_exists($full) && !is_dir($full)) {
                    $content = file_get_contents($full);
                    if ($act === 'file_export_skeleton' || $act === 'export_skeleton') {
                        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                        if (!function_exists('ce_skeletonize_content')) {
                            $cePath = defined('CJOS_PATH_PLUGINS') ? (CJOS_PATH_PLUGINS . '/ContextExporter.php') : (realpath(__DIR__) . '/ContextExporter.php');
                            if (file_exists($cePath)) @include_once $cePath;
                        }
                        $content = function_exists('ce_skeletonize_content') ? ce_skeletonize_content($content, $ext) : $content;
                        $exportedBlocks[] = "================================================================================\nFILE SKELETON: {$f}\n================================================================================\n" . $content;
                    } else {
                        $exportedBlocks[] = "================================================================================\nFILE START: {$f}\n================================================================================\n" . $content;
                    }
                    $exportedFiles[] = $f;
                } else {
                    $exportedBlocks[] = "================================================================================\nFILE START: {$f}\n================================================================================\nERROR: File not found on server.";
                }
            }

            $combinedExport = implode("\n\n", $exportedBlocks);
            echo json_encode([
                'status' => 'exported',
                'message' => 'Exported ' . count($exportedFiles) . ' file(s) successfully.',
                'exported_content' => "~~~\n" . $combinedExport . "\n~~~",
                'files' => $exportedFiles
            ]);
            exit;
        }

        if ($isAuditOnly) {
            $codeAuditorPath = defined('CJOS_PATH_PLUGINS') ? (CJOS_PATH_PLUGINS . '/CodeAuditor.php') : (realpath(__DIR__) . '/CodeAuditor.php');
            if (file_exists($codeAuditorPath)) {
                require_once $codeAuditorPath;
                $res = ca_execute_audit_payload($rawInput, '', false, false, false);

                if (isset($res['status']) && $res['status'] === 'success') {
                    $formattedReport = ca_format_audit_report($res['results']);
                    echo json_encode([
                        'status' => 'audit_complete',
                        'message' => 'Audit scan completed.',
                        'audit_report' => $formattedReport,
                        'results' => $res['results']
                    ]);
                    exit;
                } else {
                    echo json_encode($res);
                    exit;
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'CodeAuditor plugin is missing.']);
                exit;
            }
        }

        CPSandbox::reset();
        $root = CJOS_PATH_ROOT . DIRECTORY_SEPARATOR;
        $temp_variables = [];
        $temp_file_buffers = [];
        $results = [];
        $hasError = false;

        $autoApplied = 0;
        foreach ($patches as $idx => &$patch) {
            $act = cp_normalize_action_name($patch['action'] ?? 'file_update');
            $patch['action'] = $act;
            $relPath = $patch['file'] ?? '';
            if ($act === 'var_patch' || $act === 'var_refactor') {
                $relPath = 'VAR: ' . ($patch['var_name'] ?? 'UNKNOWN');
            }
            $fullPath = $root . $relPath;
            $realPath = ($act === 'var_patch' || $act === 'var_refactor') ? false : realpath($fullPath);

            $res = [
                'id' => $idx,
                'file' => $relPath,
                'status' => 'pending',
                'msg' => '',
                'hint' => null,
                'find' => $patch['find'] ?? '',
                'replace' => $patch['replace'] ?? ''
            ];

            if (!empty($patch['_parse_error'])) {
                $res['status'] = 'error';
                $res['msg'] = 'Parse Error: ' . $patch['_parse_error'];
            } else {
                $handler = "cp_preview_" . $act;
                if (function_exists($handler)) {
                    $args = [$patch, $realPath, &$res, $root, &$temp_variables, &$temp_file_buffers];
                    call_user_func_array($handler, $args);
                } else {
                    $res['status'] = 'error';
                    $res['msg'] = "Unknown action: $act";
                }
            }

            if ($res['status'] === 'error' && !empty($res['hint'])) {
                $targetField = $res['hint_target'] ?? 'find';
                $normTarget = preg_replace('/\s+/', '', $patch[$targetField] ?? '');
                $normHint = preg_replace('/\s+/', '', $res['hint']);

                if ($normTarget !== '' && $normTarget === $normHint) {
                    $oldFind = $patch[$targetField] ?? '';
                    $newFind = $res['hint'];
                    $oldLead = '';
                    foreach (explode("\n", $oldFind) as $line) {
                        if (trim($line) !== '') {
                            preg_match('/^[ \t]*/', $line, $m);
                            $oldLead = $m[0] ?? '';
                            break;
                        }
                    }
                    $newLead = '';
                    foreach (explode("\n", $newFind) as $line) {
                        if (trim($line) !== '') {
                            preg_match('/^[ \t]*/', $line, $m);
                            $newLead = $m[0] ?? '';
                            break;
                        }
                    }
                    if ($oldLead !== $newLead && !empty($patch['replace'])) {
                        $replaceLines = explode("\n", $patch['replace']);
                        $newReplaceLines = [];
                        foreach ($replaceLines as $line) {
                            if (trim($line) === '') {
                                $newReplaceLines[] = $line;
                            } else if (strpos($line, $oldLead) === 0) {
                                $newReplaceLines[] = $newLead . substr($line, strlen($oldLead));
                            } else {
                                $newReplaceLines[] = $newLead . ltrim($line);
                            }
                        }
                        $patch['replace'] = implode("\n", $newReplaceLines);
                    }
                    $patch[$targetField] = $newFind;
                    $autoApplied++;
                }
            }

            if ($res['status'] === 'error') {
                $hasError = true;
            }
            $results[$idx] = $res;
        }
        unset($patch);

        if ($autoApplied > 0) {
            CPSandbox::reset();
            $temp_variables = [];
            $temp_file_buffers = [];
            $results = [];
            $hasError = false;

            foreach ($patches as $idx => &$patch) {
                $act = cp_normalize_action_name($patch['action'] ?? 'file_update');
                $patch['action'] = $act;
                $relPath = $patch['file'] ?? '';
                if ($act === 'var_patch' || $act === 'var_refactor') {
                    $relPath = 'VAR: ' . ($patch['var_name'] ?? 'UNKNOWN');
                }
                $fullPath = $root . $relPath;
                $realPath = ($act === 'var_patch' || $act === 'var_refactor') ? false : realpath($fullPath);

                $res = [
                    'id' => $idx,
                    'file' => $relPath,
                    'status' => 'pending',
                    'msg' => '',
                    'hint' => null,
                    'find' => $patch['find'] ?? '',
                    'replace' => $patch['replace'] ?? ''
                ];

                $handler = "cp_preview_" . $act;
                if (function_exists($handler)) {
                    $args = [$patch, $realPath, &$res, $root, &$temp_variables, &$temp_file_buffers];
                    call_user_func_array($handler, $args);
                }

                if ($res['status'] === 'error') $hasError = true;
                $results[$idx] = $res;
            }
            unset($patch);
        }

        if ($hasError) {
            $errorResults = array_filter($results, function($r) { return $r['status'] === 'error'; });
            $sessionId = cp_save_failed_session($patches, $rawInput, $sessionId);
            $isJson = ($parsed['input_format'] ?? 'raw') === 'json';
            $isTrace = !empty($patches) && array_reduce($patches, function($c, $p) { return $c && ($p['action'] === 'logic_trace'); }, true);
            $diagReport = cp_generate_error_report($errorResults, $patches, $sessionId, $isJson, $isTrace);

            $failedFiles = array_values(array_unique(array_filter(array_map(function($res) use ($patches) {
                $p = $patches[$res['id']] ?? null;
                return ($p && !empty($p['file'])) ? $p['file'] : null;
            }, $errorResults))));

            echo json_encode([
                'status' => 'mismatch',
                'session_id' => $sessionId,
                'error_count' => count($errorResults),
                'diagnostic_report' => $diagReport,
                'failed_files' => $failedFiles,
                'results' => array_values($results)
            ]);
            exit;
        }

        // 100% Success -> Atomic Commit
        $buffers = [];
        $variables = [];
        $variables_used = [];

        try {
            foreach ($patches as $p) {
                $act = cp_normalize_action_name($p['action'] ?? '');
                if ($act === 'var_patch' || $act === 'var_refactor' || $act === 'logic_trace' || $act === 'file_export' || $act === 'edit_log' || $act === 'audit' || $act === 'refactor') continue;
                $files = array_filter([$p['file'] ?? '', $p['dest_file'] ?? '']);

                foreach ($files as $f) {
                    if (empty($f)) continue;
                    if (!isset($buffers[$f])) {
                        $full = $root . $f;
                        $isCreationAction = ($act === 'file_create' || $act === 'download_icon' || $act === 'download_file');
                        $isMoveDest = ($act === 'file_move' && $f === ($p['dest_file'] ?? ''));
                        $isCopyDest = ($act === 'file_copy' && $f === ($p['dest_file'] ?? ''));

                        if (($isCreationAction && $f === $p['file']) || $isMoveDest || $isCopyDest) {
                            $buffers[$f] = "";
                        } else {
                            if (!file_exists($full)) {
                                if ($act === 'file_overwrite' && $f === $p['file']) {
                                    $buffers[$f] = "";
                                } else {
                                    throw new Exception("Path not found: $f");
                                }
                            } else {
                                $buffers[$f] = cp_normalize(file_get_contents($full));
                            }
                        }
                    }
                }
            }

            foreach ($patches as $idx => &$p) { $p['_original_idx'] = $idx; }
            unset($p);

            usort($patches, function($a, $b) {
                $prio = [
                    'file_create' => 0, 'file_overwrite' => 0, 'download_icon' => 0, 'download_file' => 0,
                    'code_cut' => 1,
                    'var_patch' => 2, 'var_refactor' => 2,
                    'file_update' => 3, 'code_delete' => 3, 'file_move' => 3, 'file_copy' => 3, 'file_delete' => 3
                ];
                $actA = cp_normalize_action_name($a['action'] ?? '');
                $actB = cp_normalize_action_name($b['action'] ?? '');
                $pA = $prio[$actA] ?? 3;
                $pB = $prio[$actB] ?? 3;
                if ($pA === $pB) return $a['_original_idx'] <=> $b['_original_idx'];
                return $pA <=> $pB;
            });

            $hasCutCode = false;
            foreach ($patches as $p) {
                $act = cp_normalize_action_name($p['action'] ?? '');
                if ($act === 'code_cut') {
                    $hasCutCode = true;
                    break;
                }
            }
            $forceLiteral = isset($_POST['force_literal']) ? ($_POST['force_literal'] == '1') : !$hasCutCode;

            foreach ($patches as $p) {
                $act = cp_normalize_action_name($p['action'] ?? '');
                if ($act === 'logic_trace' || $act === 'file_export') continue;
                $handler = "cp_commit_" . $act;
                if (function_exists($handler)) {
                    $handler($p, $buffers, $variables, $variables_used, $forceLiteral);
                } else {
                    throw new Exception("Unknown action in commit: " . $act);
                }
            }

            // Verify all cuts were used (Variable-First Refactor Protocol)
            foreach ($variables as $name => $val) {
                if (!isset($variables_used[$name])) {
                    $msg = "REFACTOR ERROR: Variable {{".$name."}} was cut but never consumed.\n\n";
                    $msg .= "TO FIX: Provide a subsequent patch using #ACTION: file_update or file_create and include the tag {{".$name."}} in your #REPLACE block to safely place the code.";
                    throw new Exception($msg);
                }
            }

            $touchedFiles = [];
            foreach ($buffers as $relPath => $content) {
                if ($relPath === '__CJ_CREATED_FILES__' || empty($relPath)) continue;
                $full = $root . $relPath;
                if ($content === null) {
                    if (file_exists($full)) unlink($full);
                } else {
                    $dir = dirname($full);
                    if (!is_dir($dir) && !empty($dir)) mkdir($dir, 0777, true);
                    file_put_contents($full, $content);
                    $touchedFiles[] = $relPath;
                }
            }

            if ($sessionId) {
                cp_clear_staged_session($sessionId);
            }

            $uniqueTouched = array_values(array_unique(array_filter($touchedFiles)));
            echo json_encode([
                'status' => 'committed',
                'message' => 'Batch committed successfully.',
                'files_updated' => $uniqueTouched,
                'success_files' => $uniqueTouched
            ]);

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Fallback handler for el_manual_log in standalone / emergency mode
    if ($_POST['plugin_action'] === 'el_manual_log') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $summary = trim($_POST['summary'] ?? '');
        if (!empty($summary)) {
            cp_execute_edit_log($summary);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Summary cannot be empty.']);
        }
        exit;
    }

    // 1. GET INSTRUCTIONS (Protocol v5)
    if ($_POST['plugin_action'] === 'cp_get_inst') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success', 
            'instructions' => "Return a JSON Object with a 'patches' array.\n\n" .
                              "EXAMPLE:\n{\n  \"patches\": [\n    {\n      \"file\": \"plugins/MyPlugin.php\",\n      \"find\": \"old code\",\n      \"replace\": \"new code\",\n      \"match_index\": 1\n    }\n  ]\n}"
        ]);
        exit;
    }

    // 1.7 DIRECT DELETE (Server-side)
    if ($_POST['plugin_action'] === 'cp_direct_delete') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $file = $_POST['file'] ?? '';
        if (!$file || strpos($file, '..') !== false) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid path.']);
            exit;
        }
        $full = CJOS_PATH_ROOT . DIRECTORY_SEPARATOR . ltrim($file, '/\\');
        if (file_exists($full)) {
            if (is_dir($full)) cp_recursive_delete($full);
            else unlink($full);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File not found.']);
        }
        exit;
    }

    // 1.6 GET DEPENDENCIES (sys_map.json lookup)
    if ($_POST['plugin_action'] === 'cp_get_deps') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $file = $_POST['file'] ?? '';
        $mapFile = CJOS_PATH_DATA . '/knowledge/sys_map.json';
        $manifestFile = CJOS_PATH_DATA . '/last-context-export.json';
        
        $deps = [];
        if (file_exists($mapFile)) {
            $map = json_decode(file_get_contents($mapFile), true);
            if (isset($map[$file]) && is_array($map[$file])) {
                $deps = $map[$file];
            }
        }
        
        $manifest = [];
        if (file_exists($manifestFile)) {
            $m = json_decode(file_get_contents($manifestFile), true);
            if (isset($m['files']) && is_array($m['files'])) $manifest = $m['files'];
        }

        echo json_encode(['status' => 'success', 'dependencies' => $deps, 'manifest_files' => $manifest]);
        exit;
    }

    // 2. PREVIEW / VERIFY (Protocol V9 & SSOT Raw Input Support)
if ($_POST['plugin_action'] === 'cp_preview' || $_POST['plugin_action'] === 'cp_parse_and_preview') {
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    while (ob_get_level()) @ob_end_clean();
    header('Content-Type: application/json');
    CPSandbox::reset();

    $patches = [];
    if (isset($_POST['raw_input'])) {
        $parsed = cp_parse_raw_input($_POST['raw_input']);
        $patches = $parsed['patches'];
    } elseif (isset($_POST['patch_count'])) {
            $count = (int)$_POST['patch_count'];
            for ($i = 0; $i < $count; $i++) {
                $patches[] = [
                    '_id' => $_POST["p_{$i}_id"] ?? ($_POST["p_{$i}_patch_id"] ?? null),
                    'file' => $_POST["p_{$i}_file"] ?? '',
                    'dest_file' => $_POST["p_{$i}_dest_file"] ?? '',
                    'find' => $_POST["p_{$i}_find"] ?? '',
                    'replace' => $_POST["p_{$i}_replace"] ?? '',
                    'delete_start' => $_POST["p_{$i}_delete_start"] ?? '',
                    'delete_end' => $_POST["p_{$i}_delete_end"] ?? '',
                    'range_start' => $_POST["p_{$i}_range_start"] ?? '',
                    'range_end' => $_POST["p_{$i}_range_end"] ?? '',
                    'var_name' => $_POST["p_{$i}_var_name"] ?? '',
                    'icon_name' => $_POST["p_{$i}_icon_name"] ?? '',
                    'spacing' => $_POST["p_{$i}_spacing"] ?? 'inline',
                    'match_index' => $_POST["p_{$i}_match"] ?? 1,
                    'action' => $_POST["p_{$i}_action"] ?? 'update',
                    '_parse_error' => $_POST["p_{$i}_parse_error"] ?? null
                ];
            }
        } else {
            // Legacy JSON Fallback
            $input = json_decode($_POST['payload'], true);
            if ($input && isset($input['patches'])) $patches = $input['patches'];
        }

        if (empty($patches)) {
            echo json_encode(['status' => 'error', 'message' => 'No patches received (Protocol V9/JSON mismatch).']);
            exit;
        }

        $results = [];
$root = CJOS_PATH_ROOT . DIRECTORY_SEPARATOR;
$temp_variables = [];
$temp_file_buffers = [];

foreach ($patches as $idx => $patch) {
    $action = $patch['action'] ?? 'update';
    if ($action === 'update') $action = 'file_update';
    if ($action === 'patch_var') $action = 'var_patch';
    if ($action === 'refactor_var') $action = 'var_refactor';
    if ($action === 'delete_code') $action = 'code_delete';
    if ($action === 'cut_code') $action = 'code_cut';
    if ($action === 'create') $action = 'file_create';
    if ($action === 'move_file') $action = 'file_move';
    if ($action === 'delete_file') $action = 'file_delete';
    if ($action === 'export') $action = 'file_export';
                        
    $relPath = $patch['file'] ?? 'unknown';
    if ($action === 'var_patch' || $action === 'var_refactor') {
        $relPath = 'VAR: ' . ($patch['var_name'] ?? 'UNKNOWN');
    }
    $fullPath = $root . $relPath;
    $realPath = ($action === 'var_patch' || $action === 'var_refactor') ? false : realpath($fullPath);

    $res = [
        'id' => $idx,
        'file' => $relPath,
        'status' => 'pending',
        'msg' => '',
        'hint' => null,
        'find' => $patch['find'] ?? '',
        'replace' => $patch['replace'] ?? ''
    ];

    $handler = "cp_preview_" . $action;
if (function_exists($handler)) {
    $args = [
        $patch,
        $realPath,
        &$res,
        $root,
        &$temp_variables,
        &$temp_file_buffers
    ];
    call_user_func_array($handler, $args);
} else {
    $res['status'] = 'error';
    $res['msg'] = "Unknown action: $action";
}$results[$idx] = $res;
}ksort($results);
$results = array_values($results);

$files_on_disk = [];
foreach ($results as $res) {
    $f = $res['file'];
    if (strpos($f, 'VAR:') !== 0) {
        $files_on_disk[$f] = file_exists($root . $f);
    }
}

$errorResults = array_filter($results, function($r) { return $r['status'] === 'error'; });
$diagReport = null;
$sessionId = null;
$failedFiles = [];
if (!empty($errorResults)) {
    $sessionId = cp_save_failed_session($patches, $_POST['raw_input'] ?? '', null);
    $isJson = (isset($_POST['raw_input']) && strpos(trim($_POST['raw_input']), '{') === 0);
    $isTrace = !empty($patches) && array_reduce($patches, function($c, $p) { return $c && ($p['action'] === 'logic_trace'); }, true);
    $diagReport = cp_generate_error_report($errorResults, $patches, $sessionId, $isJson, $isTrace);

    $failedFiles = array_values(array_unique(array_filter(array_map(function($res) use ($patches) {
        $p = $patches[$res['id']] ?? null;
        return ($p && !empty($p['file'])) ? $p['file'] : null;
    }, $errorResults))));
}

echo json_encode([
    'status' => 'success', 
    'session_id' => $sessionId,
    'diagnostic_report' => $diagReport,
    'failed_files' => $failedFiles,
    'results' => $results,
    'files_on_disk' => $files_on_disk
], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
exit;}

    // 3. ATOMIC BATCH COMMIT (Protocol V11 Support)
    if ($_POST['plugin_action'] === 'cp_commit_batch') {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');
        while (ob_get_level()) @ob_end_clean();
        header('Content-Type: application/json');

        $patches = [];
        if (isset($_POST['patch_count'])) {
            $count = (int)$_POST['patch_count'];
            for ($i = 0; $i < $count; $i++) {
                $patches[] = [
                    'id' => $_POST["p_{$i}_id"] ?? 'unknown',
                    'file' => $_POST["p_{$i}_file"],
                    'dest_file' => $_POST["p_{$i}_dest_file"] ?? '',
                    'find' => $_POST["p_{$i}_find"] ?? '',
                    'replace' => $_POST["p_{$i}_replace"] ?? '',
                    'delete_start' => $_POST["p_{$i}_delete_start"] ?? '',
                    'delete_end' => $_POST["p_{$i}_delete_end"] ?? '',
                    'range_start' => $_POST["p_{$i}_range_start"] ?? '',
                    'range_end' => $_POST["p_{$i}_range_end"] ?? '',
                    'var_name' => $_POST["p_{$i}_var_name"] ?? '',
                    'icon_name' => $_POST["p_{$i}_icon_name"] ?? '',
                    'spacing' => $_POST["p_{$i}_spacing"] ?? 'inline',
                    'match_index' => $_POST["p_{$i}_match"] ?? 1,
                    'action' => $_POST["p_{$i}_action"]
                ];
            }
        } else {
            $patches = json_decode($_POST['patches'], true);
        }

        if (empty($patches)) { echo json_encode(['status' => 'error', 'message' => 'No patches received.']); exit; }

        $root = CJOS_PATH_ROOT . DIRECTORY_SEPARATOR;
        $buffers = [];
        $variables = [];
        $variables_used = [];
        $patchResults = array_fill(0, count($patches), ['success' => false, 'error' => 'Pending']);

        $hasCutCode = false;
        foreach ($patches as $p) {
            $act = cp_normalize_action_name($p['action'] ?? '');
            if ($act === 'code_cut') {
                $hasCutCode = true;
                break;
            }
        }
        $forceLiteral = isset($_POST['force_literal']) ? ($_POST['force_literal'] == '1') : !$hasCutCode;
        try {
    // 1. Pre-load all involved files into memory
    foreach ($patches as $p) {
        $act = cp_normalize_action_name($p['action'] ?? '');
        if ($act === 'var_patch' || $act === 'var_refactor' || $act === 'logic_trace' || $act === 'file_export' || $act === 'export' || $act === 'file_export_skeleton' || $act === 'export_skeleton' || $act === 'edit_log' || $act === 'audit' || $act === 'refactor') continue;$files = [$p['file']];
                if (!empty($p['dest_file'])) $files[] = $p['dest_file'];
                
                foreach ($files as $f) {
                    if (!isset($buffers[$f])) {
                        $full = $root . $f;
                        $isCreationAction = ($p['action'] === 'create' || $p['action'] === 'file_create' || $p['action'] === 'download_icon' || $p['action'] === 'download_file');
                        $isMoveDest = (($p['action'] === 'move_file' || $p['action'] === 'file_move') && $f === $p['dest_file']);
                        $isCopyDest = ($p['action'] === 'file_copy' && $f === $p['dest_file']);
                        
                        if (($isCreationAction && $f === $p['file']) || $isMoveDest || $isCopyDest) {
                            // Creation/Move/Copy Target
                            $isOverwritable = ($p['action'] === 'download_icon' || $p['action'] === 'download_file');
                            if (!$isOverwritable && file_exists($full)) {
                                throw new Exception("File already exists: $f");
                            }
                            $buffers[$f] = ""; 
                        } else {
                            // Existing Source
                            if (!file_exists($full)) {
                                if ($p['action'] === 'file_overwrite' && $f === $p['file']) {
                                    $buffers[$f] = "";
                                } else {
                                    throw new Exception("Path not found: $f");
                                }
                            } else {
                                if (is_dir($full)) {
                                    $buffers[$f] = '__CJ_DIR__';
                                } else {
                                    $buffers[$f] = cp_normalize(file_get_contents($full));
                                }
                            }
                        }
                    }
                }
            }

            // Attach original chronological index to ensure stable sorting in all PHP environments
            foreach ($patches as $idx => &$p) {
                $p['_original_idx'] = $idx;
            }
            unset($p);

            // 2. Stable Priority Sorting (0: Creators, 1: Cuts, 2: Variables, 3: Updates/Deletes)
            usort($patches, function($a, $b) {
                $prio = [
                    'create' => 0, 'file_create' => 0, 'file_overwrite' => 0,
                    'cut_code' => 1, 'code_cut' => 1,
                    'patch_var' => 2, 'var_patch' => 2, 'refactor_var' => 2, 'var_refactor' => 2
                ];
                $actA = $a['action'];
                if ($actA === 'update') $actA = 'file_update';
                elseif ($actA === 'patch_var') $actA = 'var_patch';
                elseif ($actA === 'refactor_var') $actA = 'var_refactor';
                elseif ($actA === 'cut_code') $actA = 'code_cut';
                elseif ($actA === 'create') $actA = 'file_create';
                
                $actB = $b['action'];
                if ($actB === 'update') $actB = 'file_update';
                elseif ($actB === 'patch_var') $actB = 'var_patch';
                elseif ($actB === 'refactor_var') $actB = 'var_refactor';
                elseif ($actB === 'cut_code') $actB = 'code_cut';
                elseif ($actB === 'create') $actB = 'file_create';
                
                $pA = $prio[$actA] ?? 3;
                $pB = $prio[$actB] ?? 3;
                
                if ($pA === $pB) {
                    return $a['_original_idx'] <=> $b['_original_idx'];
                }
                return $pA <=> $pB;
            });

            // 2. Apply patches to buffers in order
            foreach ($patches as $idx => $p) {
                $act = cp_normalize_action_name($p['action'] ?? '');
                if ($act === 'logic_trace' || $act === 'file_export' || $act === 'export' || $act === 'file_export_skeleton' || $act === 'export_skeleton' || $act === 'audit' || $act === 'refactor') {
                    $patchResults[$idx] = ['success' => true, 'error' => ''];
                    continue;
                }

                $handler = "cp_commit_" . $act;
                if (function_exists($handler)) {
                    $handler($p, $buffers, $variables, $variables_used, $forceLiteral);
                } else {
                    throw new Exception("Unknown action in commit: " . $act);
                }
                $patchResults[$idx] = ['success' => true, 'error' => ''];
            }

            // Verify all cuts were used (Variable-First Refactor Protocol)
            foreach ($variables as $name => $val) {
                if (!isset($variables_used[$name])) {
                    $msg = "REFACTOR ERROR: Variable {{".$name."}} was cut but never consumed.\n\n";
                    $msg .= "TO FIX: Provide a subsequent patch using #ACTION: update or create and include the tag {{".$name."}} in your #REPLACE block to safely place the code.";
                    throw new Exception($msg);
                }
            }

            // 3. Write all buffers to disk (Two-Pass Atomic Write)
            
            // PASS 1: Validation (Ensure all paths are valid and writable)
            foreach ($buffers as $relPath => $content) {
                if ($relPath === '__CJ_CREATED_FILES__') continue;
                if (empty($relPath)) {
                    throw new Exception("Abort: Internal error. Attempted to write to an empty filename. Check your Action Registry for missing FILE tags.");
                }
                $full = $root . $relPath;
                $dir = dirname($full);
                
                if ($content === null) {
                    if (file_exists($full) && !is_writable($full)) {
                        throw new Exception("Abort: Path is not writable for deletion: $relPath");
                    }
                    continue;
                }

                // Check directory permissions
                if (!is_dir($dir) && !empty($dir)) {
                    // Try to create it in dry-run mode or check parent
                    $parent = $dir;
                    while(!is_dir($parent) && !empty($parent)) { $parent = dirname($parent); }
                    if (!is_writable($parent)) throw new Exception("Abort: Directory path not creatable: $dir");
                }
                
                // Check file permissions
                if (file_exists($full) && !is_writable($full)) {
                    throw new Exception("Abort: File is not writable: $relPath");
                }
            }

            // PASS 2: Execution (Only starts if Pass 1 succeeds 100%)
            $touchedFiles = [];
            foreach ($buffers as $relPath => $content) {
                if ($relPath === '__CJ_CREATED_FILES__') continue;
                $full = $root . $relPath;
                
                // Handle physical deletions
                if ($content === null) {
                    if (file_exists($full)) {
                        if (is_dir($full)) cp_recursive_delete($full);
                        else unlink($full);
                    }
                    continue;
                }

                // Handle Standard Directory Creation
                if ($content === '__CJ_DIR__') {
                    if (!is_dir($full)) {
                        $parent = dirname($full);
                        if (!is_dir($parent)) mkdir($parent, 0777, true);
                        mkdir($full, 0777, true);
                    }
                    continue;
                }

                // Handle Standard File Writes (The transactional content is written directly)
                $dir = dirname($full);
                if (!is_dir($dir) && !empty($dir)) mkdir($dir, 0777, true);
                if (file_put_contents($full, $content) === false) {
                    throw new Exception("CRITICAL: Write failed mid-batch: $relPath. System state may be inconsistent.");
                }
            }

            $uniqueTouched = array_values(array_unique(array_filter($touchedFiles)));
            echo json_encode([
                'status' => 'success',
                'patch_results' => $patchResults,
                'files_updated' => $uniqueTouched,
                'success_files' => $uniqueTouched
            ]);

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// 4. GENERATE MANUAL (Protocol V10)
function cp_generate_manual_internal() {
    global $CP_REG;
    $manual = "# Patcher Protocol V10 Manual\n\n";
    $manual .= "This file is auto-generated by the File Patch Manager. It defines the current registry of supported actions and their required tags.\n\n";
    
    $sorted_reg = $CP_REG;
    ksort($sorted_reg);
    
    foreach ($sorted_reg as $action => $data) {
        if (!empty($data['legacy'])) continue;
        $manual .= "## Action: `{$action}`\n";
        $manual .= "**Description:** {$data['desc']}\n\n";
        $manual .= "**Required Tags:** `" . implode('`, `', $data['required']) . "`\n\n";
        $manual .= "**Literal Tags:** `" . implode('`, `', $data['literal']) . "`\n\n";
        $manual .= "### Example\n```text\n{$data['example']}\n```\n\n";
    }
    
    $path = CJOS_PATH_DATA . '/knowledge/patcher_manual.md';
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, $manual);
    return true;
}

if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'cp_generate_manual') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    cp_generate_manual_internal();
    echo json_encode(['status' => 'success', 'message' => 'Manual generated successfully.']);
    exit;
}

// --- FRONTEND UI ---
$plugin_settings_map['FilePatchManager'] = '<script>window.CP_REGISTRY = ' . json_encode($CP_REG) . '; window.CP_UI = {};</script>' . <<<'HTML'
<div id="cp-tray-anchor">
    <div id="cp-gui-root">
    <style>
        .cp-diff-container { margin-top: 10px; border-radius: 10px; overflow: hidden; font-family: monospace; font-size: 11px; border: 1px solid var(--border-color); box-shadow: inset 0 1px 4px rgba(0,0,0,0.03); }
        .cp-diff-old { background: rgba(255, 59, 48, 0.04); color: #D32F2F; padding: 10px; border-bottom: 1px solid rgba(0,0,0,0.03); white-space: pre-wrap; border-left: 4px solid #FF3B30; }
        .cp-diff-new { background: rgba(52, 199, 89, 0.04); color: #1E4620; padding: 10px; white-space: pre-wrap; border-left: 4px solid #34C759; }
        .cp-diff-line { display: block; min-height: 1.2em; }
        .cp-diff-line-same { opacity: 0.5; }
        .cp-diff-line-added { background: rgba(52, 199, 89, 0.2); font-weight: 600; }
        .cp-diff-line-removed { background: rgba(255, 59, 48, 0.15); text-decoration: line-through; text-decoration-color: rgba(211, 47, 47, 0.4); }
        .cp-diff-label { font-size: 9px; font-weight: 900; text-transform: uppercase; margin-bottom: 6px; display: block; opacity: 0.7; letter-spacing: 0.5px; }
        .cp-hint-box { background: #FFFBE6; border: 1px solid #F5E8B0; padding: 12px; border-radius: 10px; font-size: 11px; margin-bottom: 12px; font-family: monospace; overflow-x: auto; position: relative; color: #856404; }
        
        .cp-cap-badge { display:inline-block; font-size:9px; font-weight:800; padding:2px 6px; border-radius:4px; background:var(--btn-bg); color:var(--text-secondary); text-transform:uppercase; margin-right:4px; margin-bottom:4px; border:1px solid var(--border-color); }
        .cp-cap-badge.active { background:var(--primary); color:var(--primary-text); border-color:var(--primary); }

        /* Color-Blind Mode Overrides */
        body.cp-accessible-diffs .cp-diff-old { background: rgba(255, 149, 0, 0.1) !important; color: #E67E22 !important; border-left-color: #FF9500 !important; }
        body.cp-accessible-diffs .cp-diff-new { background: rgba(0, 122, 255, 0.1) !important; color: #2980B9 !important; border-left-color: #007AFF !important; }
        body.cp-accessible-diffs .cp-diff-line-added { background: rgba(0, 122, 255, 0.2) !important; }
        body.cp-accessible-diffs .cp-diff-line-removed { background: rgba(255, 149, 0, 0.2) !important; text-decoration-color: rgba(230, 126, 34, 0.5) !important; }

        @keyframes cp-jump-flash { 0% { background-color: var(--cp-jump-color, #FFFBE6); } 100% { background-color: var(--card-bg); } }
        .cp-jump-active { animation: cp-jump-flash 1.5s cubic-bezier(0.2, 0, 0.2, 1) forwards !important; }
        :root { --cp-jump-color: #FFFBE6; }
        body.theme-midnight { --cp-jump-color: rgba(0, 122, 255, 0.2); }
        .cp-file-path { color: var(--text-primary) !important; }
        .cp-patch-card { scroll-margin-top: 20px; }
        .cp-patch-fixed { border-color: var(--ai-accent) !important; background: var(--ai-accent-bg) !important; }
        .cp-fix-badge { background: var(--ai-accent) !important; color: var(--primary-text) !important; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 4px; margin-left: 6px; vertical-align: middle; }
        .cp-fix-comment { background: var(--ai-accent-bg) !important; border-color: var(--ai-accent) !important; color: var(--text-primary) !important; }
        .cp-hint-hl { background: rgba(0, 122, 255, 0.12); color: var(--primary); font-weight: 700; border-radius: 2px; }
        .cp-audit-context-hl { background: rgba(0, 122, 255, 0.08); border: 1px dashed rgba(0, 122, 255, 0.2); border-radius: 4px; }
        .cp-variable-hl { background: var(--ai-accent); color: var(--primary-text); padding: 0 4px; border-radius: 4px; font-weight: 800; box-shadow: 0 2px 4px rgba(0,0,0,0.1); font-size: 10px; vertical-align: middle; }
        .cp-unrecognized-card { background: #FFF5F5 !important; border: 1px solid #FFCFD0 !important; color: #D32F2F !important; }
        .cp-unrecognized-header { font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .cp-unrecognized-body { font-family: monospace; font-size: 11px; white-space: pre-wrap; opacity: 0.8; max-height: 150px; overflow-y: auto; padding: 8px; background: rgba(255,255,255,0.5); border-radius: 6px; }
        .cp-tray-actions-wrap { display: flex; gap: 4px; align-items: center; }
        .cp-hide-in-studio { display: none !important; }
        .cp-header-row { display: flex; gap: 8px; margin: 16px 16px 6px 16px; transition: margin 0.2s; }
        .cp-is-studio .cp-header-row { margin-top: 0px !important; }
#cp-input::placeholder { color: var(--text-secondary) !important; opacity: 0.5 !important; font-style: italic; }
    </style>
          <div id="cp-main-ui">
        <!-- HEADER ROW -->
        <div class="cp-header-row">
            <!-- META TOGGLE -->
            <div style="flex:1; display:flex; justify-content:space-between; align-items:center; cursor:pointer; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); background:var(--card-bg);" 
                 onclick="suiToggle('cp-meta-section')">
                <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Capabilities</div>
                <span data-sui-icon="chevron" data-sui-arrow="cp-meta-section" data-sui-size="14" data-sui-stroke="2.5" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(-90deg);"></span>
            </div>

            <!-- TRAY ACTIONS (Desktop Only) -->
            <div id="cp-tray-actions" class="cp-tray-actions-wrap"></div>
            
            <!-- STUDIO TOGGLE -->
            <button onclick="cpOpenStudio()" style="width:40px; border-radius:12px; border:1px solid var(--border-color); background:var(--btn-bg); color:var(--primary); cursor:pointer; display:flex; align-items:center; justify-content:center;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px; height:18px;"><path d="M15 3h6v6"></path><path d="M10 14L21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path></svg>
            </button>
        </div>

        <div class="sui-accordion" id="cp-meta-section" style="margin: 0 16px;">
            <div class="sui-accordion-inner" style="padding: 0 2px 12px 2px;">
                <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px; padding: 16px; box-shadow: inset 0 2px 8px rgba(0,0,0,0.02);">
                    <div id="cp-capabilities-list" style="margin-bottom:12px;">
                        <div class="cp-cap-badge active">Protocol V8</div>
                        <div class="cp-cap-badge active">Zero-Escaping</div>
                        <div class="cp-cap-badge active">Multipart Raw</div>
                        <div class="cp-cap-badge active">Atomic Batch</div>
                        <div class="cp-cap-badge active">File Creation</div>
                        <div class="cp-cap-badge active">Match Indexing</div>
                        <div class="cp-cap-badge active">Smart Diagnostics</div>
                        <div class="cp-cap-badge active">Spacing Control</div>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
                        <label class="setting-label" style="margin:0; font-size:13px;">Color-Blind Friendly Diffs</label>
                        <label class="switch" style="width:40px; height:22px;">
                            <input type="checkbox" id="cp-accessible-toggle" onchange="cpToggleAccessibility(this.checked)">
                            <span class="slider" style="border-radius:20px;"></span>
                        </label>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
                        <label class="setting-label" style="margin:0; font-size:13px;">Disable Audit Redirect</label>
                        <label class="switch" style="width:40px; height:22px;">
                            <input type="checkbox" id="cp-no-audit-toggle" onchange="localStorage.setItem('cjos_cp_no_audit', this.checked)">
                            <span class="slider" style="border-radius:20px;"></span>
                        </label>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
                        <label class="setting-label" style="margin:0; font-size:13px;">Disable Refactor Redirect</label>
                        <label class="switch" style="width:40px; height:22px;">
                            <input type="checkbox" id="cp-no-refactor-toggle" onchange="localStorage.setItem('cjos_cp_no_refactor', this.checked)">
                            <span class="slider" style="border-radius:20px;"></span>
                        </label>
                    </div>
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <button onclick="cpGenerateManual()" class="text-btn" style="flex:1; background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:12px; font-weight:700; border:1px solid var(--border-color);">
                            Generate Manual
                        </button>
                        <button onclick="if(typeof fsOpen === 'function') fsOpen('app/data/knowledge/patcher_manual.md'); else window.sui.toast('File Studio not available');" class="text-btn" style="flex:1; background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:12px; font-weight:700; border:1px solid var(--border-color);">
                            View Manual
                        </button>
                    </div>
                    <button onclick="if(typeof caOpenStudio === 'function') caOpenStudio()" class="text-btn" style="width:100%; background:var(--ai-accent-bg); color:var(--ai-accent); border-radius:10px; padding:10px; font-size:12px; font-weight:700; border:1px solid rgba(88, 86, 214, 0.2); margin-bottom:8px;">
                        Launch Code Auditor Studio
                    </button>
                    <button onclick="if(typeof phOpenStudio === 'function') phOpenStudio()" class="text-btn" style="width:100%; background:var(--btn-bg); color:var(--text-primary); border-radius:10px; padding:10px; font-size:12px; font-weight:700; border:1px solid var(--border-color); margin-bottom:8px;">
                        View Patch History
                    </button>
                    <button onclick="cpOpenStandaloneOptions()" class="text-btn" style="width:100%; background:var(--danger); color:white; border-radius:10px; padding:10px; font-size:12px; font-weight:700; border:none;">
                        Emergency Standalone Mode
                    </button>
                </div>
            </div>
        </div>

        <div class="setting-item vertical" style="padding-top: 10px; padding-bottom: 8px;">
            <label class="setting-label">Surgical Patcher</label>
            <div class="setting-desc">Paste a Protocol V8 or JSON patch block to stage and apply code changes.</div>
            <div style="position:relative; margin-top:10px;">
                <textarea id="cp-input" inputmode="text" placeholder=\'{ "patches": [ ... ] }\' style="
                    width:100%; height:120px; padding:12px 40px 12px 12px; border-radius:10px; 
                    border:1px solid var(--border-color); font-family:monospace; font-size:12px; 
                    background:var(--input-bg); color:var(--input-text); outline:none; resize:vertical;
                    display:block;
                "></textarea>
                <button id="cp-btn-clear" onclick="cpReset()" style="position:absolute; top:8px; right:8px; width:28px; height:28px; border-radius:50%; border:none; background:var(--btn-bg); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s; z-index:5; opacity:0; pointer-events:none;">
                    <span data-sui-icon="close" data-sui-size="14" data-sui-stroke="3"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px; height:14px; display:block;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></span>
                </button>
            </div>
            <div style="display:flex; gap:10px; margin-top:8px;">
                <button onclick="cpVerifyBatch()" class="text-btn" style="
                    flex:1; background:var(--primary); color:var(--primary-text);
                    border-radius:10px; padding:12px; font-weight:600;
                ">Analyze & Stage</button>
                <button id="cp-btn-supplemental" onclick="cpSupplementalUpdate()" class="text-btn" style="
                    flex:1; background:var(--btn-bg); color:var(--text-primary);
                    border-radius:10px; padding:12px; font-weight:600; display:none;
                ">Update Staged</button>
            </div>
        </div>

        <div id="cp-staging-area" style="display:none; flex-direction:column; gap:12px; margin: 0; padding: 0 16px 20px 16px;">
            <div id="cp-summary-bar" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; background:var(--bg-color); padding:12px; border-radius:14px; border:1px solid var(--border-color); margin-bottom:8px;"></div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:4px;">
                <div style="font-size:11px; font-weight:700; color:#8E8E93; text-transform:uppercase; letter-spacing:0.5px;">Staged Files</div>
                <div id="cp-bulk-actions" style="display:flex; gap:8px;"></div>
            </div>
            <div id="cp-cards-container" style="display:flex; flex-direction:column; gap:12px;"></div>
        </div>
    </div>
    </div> <!-- /cp-gui-root -->
</div> <!-- /cp-tray-anchor -->
HTML;

$plugin_js .= $CP_JS_HANDLERS;
$plugin_js .= <<<'JS'
// --- CODE PATCHER v6 JS ---

window.cpDownloadText = function(filename, text) {
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.style.display = 'none';
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    // Use a standard click event to ensure user-activation is recognized
    a.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
    setTimeout(() => {
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }, 100);
};

window.cpCopyToClipboard = async function(text) {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            // High-Capacity Method: Use ClipboardItem with a Blob
            // This is more robust for large strings than writeText()
            const type = "text/plain";
            const blob = new Blob([text], { type });
            const data = [new ClipboardItem({ [type]: blob })];
            await navigator.clipboard.write(data);
            return true;
        } catch (err) {
            // Fallback to standard writeText if ClipboardItem fails
            console.warn("ClipboardItem failed, falling back to writeText", err);
            return navigator.clipboard.writeText(text);
        }
    } else {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-9999px";
        textArea.style.top = "0";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        let success = false;
        try {
            success = document.execCommand('copy');
        } catch (err) {}
        document.body.removeChild(textArea);
        return success ? Promise.resolve() : Promise.reject();
    }
};

window.cpCopyUnrecognized = function() {
    const msg = "UNRECOGNIZED CONTENT: The following text was ignored by the parser. Check for malformed tags or missing #FILE headers.\n\n";
    cpCopyToClipboard("```\n" + msg + cpUnrecognizedContent + "\n```").then(() => sui.toast('Copied Unrecognized Content'));
};

window.addEventListener("load", () => {
    // Load Accessibility State
    const isAcc = localStorage.getItem("cjos_cp_accessible") === "true";
    const accToggle = document.getElementById("cp-accessible-toggle");
    if (accToggle) accToggle.checked = isAcc;
    cpToggleAccessibility(isAcc);

    const noAuditToggle = document.getElementById("cp-no-audit-toggle");
    if (noAuditToggle) noAuditToggle.checked = localStorage.getItem("cjos_cp_no_audit") === "true";

    const noRefactorToggle = document.getElementById("cp-no-refactor-toggle");
    if (noRefactorToggle) noRefactorToggle.checked = localStorage.getItem("cjos_cp_no_refactor") === "true";

    // Initialize Tray Actions for Desktop
    window.cpPopulateActions(document.getElementById('cp-tray-actions'), 'cp-tray');

    window.cpToggleMetaSection = function() {
        const el = document.getElementById('cp-meta-section');
        const arrow = document.getElementById('cp-meta-arrow');
        const isOpen = el.classList.contains('open');
        if (isOpen) {
            el.classList.remove('open');
            arrow.style.transform = 'rotate(-90deg)';
        } else {
            el.classList.add('open');
            arrow.style.transform = 'rotate(0deg)';
        }
    };

    const cpInput = document.getElementById("cp-input");
    if (cpInput) {
        cpInput.placeholder = "#FILE: [PATH]\n#FIND:\n...\n#REPLACE:\n...\n#END";
        cpInput.addEventListener("focus", function() {
            this.select();
            this.setSelectionRange(0, 999999);
        });

        // Retract keyboard after paste
        cpInput.addEventListener("paste", function() {
            setTimeout(() => {
                this.blur();
                if (window.cpUpdateClearBtn) window.cpUpdateClearBtn();
            }, 100);
        });

        const cpClearBtn = document.getElementById("cp-btn-clear");
        const updateClearBtn = () => {
            if(!cpClearBtn) return;
            const hasVal = cpInput.value.trim().length > 0;
            cpClearBtn.style.opacity = hasVal ? "1" : "0";
            cpClearBtn.style.pointerEvents = hasVal ? "auto" : "none";
        };
        cpInput.addEventListener("input", updateClearBtn);
        window.cpUpdateClearBtn = updateClearBtn;
        updateClearBtn();
    }
});

window.cpExtractRawBlocksByAction = function(text, actionType) {
    const normalized = text.replace(/\r\n/g, '\n');
    const blockRegex = /(?:^|\n)(#(?:ACTION|PATCH_ID):[\s\S]*?\n#END(?:[:\s][^\n]*)?)/g;
    let match;
    let extracted = [];
    while ((match = blockRegex.exec(normalized)) !== null) {
        const block = match[1];
        const actionMatch = block.match(/^#ACTION:\s*([^\n]+)/m);
        const blockAction = actionMatch ? actionMatch[1].trim() : "update";
        if (blockAction === actionType) {
            extracted.push(block.trim());
        }
    }
    return extracted.join('\n\n');
};

// --- RESET LOGIC ---
window.cpReset = function() {
    const input = document.getElementById("cp-input");
    if (input) {
        input.value = "";
        if (window.cpUpdateClearBtn) window.cpUpdateClearBtn();
    }
    
    // Clear State
    cpCurrentBatch = [];
    cpResults = [];
    window._cpPassedActions = [];
    
    // Reset UI
    document.getElementById("cp-staging-area").style.display = "none";
    document.getElementById("cp-btn-supplemental").style.display = "none";
    
    renderPatchCards();
    if (window.sui && window.sui.haptic) window.sui.haptic('light');
};

window.cpPopulateActions = function(container, idPrefix) {
    if (!container) return;
    container.innerHTML = '';

    const createBtn = (id, icon, title, color, onclick, onlongpress) => {
        const btn = document.createElement('button');
        btn.id = id;
        btn.title = title;
        btn.style.cssText = 'background:var(--btn-bg); border:none; width:32px; height:32px; border-radius:50%; color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s; flex-shrink:0;';
        if (color) btn.style.color = color;
        btn.innerHTML = `<span data-sui-icon="${icon}" data-sui-size="18"></span>`;
        btn.onclick = (e) => { e.stopPropagation(); onclick(); };

        if (onlongpress) {
            let timer = null;
            const start = (e) => {
                if (e.pointerType === 'mouse' && e.button !== 0) return;
                timer = setTimeout(() => {
                    timer = null;
                    if (window.sui && window.sui.haptic) window.sui.haptic('medium');
                    onlongpress();
                }, 600);
            };
            const clear = () => { if(timer) { clearTimeout(timer); timer = null; } };
            btn.addEventListener('pointerdown', start);
            btn.addEventListener('pointerup', clear);
            btn.addEventListener('pointerleave', clear);
            btn.addEventListener('contextmenu', e => e.preventDefault());
        }
        return btn;
    };

    const actions = [
{ 
    id: 'export', 
    icon: 'export', 
    title: 'Export Foundation Context', 
    color: 'var(--primary)', 
    fn: () => window.ceDownload?.('foundation') || window.sui.toast("Context Exporter unavailable"),
    longFn: () => {
        if (typeof window.openPicker === 'function') {
            const options = [
                { label: "📤 Export Foundation Context", value: "foundation" },
                { label: "🎯 Export Foundation + Project Context", value: "project" },
                { label: "🕒 Export Foundation + Latest Session Capsule", value: "capsule" },
                { label: "⚙️ Open Manual Context Extras", value: "extras" }
            ];
            window.openPicker("Context Export Options", options, null, (val) => {
                if (val === "foundation") window.ceDownload?.("foundation");
                if (val === "project") window.ceDownload?.("project");
                if (val === "capsule") window.ceDownload?.("capsule");
                if (val === "extras") window.ceOpenManualExtrasStudio?.();
            });
        } else {
            window.ceOpenManualExtrasStudio?.();
        }
    }
},{ 
    id: 'undo', 
    icon: 'rotate-ccw', 
    title: 'Undo (Restore Latest)', 
    fn: () => window.elTriggerCheckpointAction?.('restore') || window.sui.toast("Undo unavailable"),
    longFn: (e) => {
        if (typeof window.scShowBunkerMenu === 'function') {
            window.scShowBunkerMenu(e);
        } else if (typeof window.scOpenBunkerOverlay === 'function') {
            window.scOpenBunkerOverlay();
        } else {
            const options = [
                { 
                    label: `<div style="display:flex; align-items:center; gap:10px;">
                                <span style="color:var(--primary);">${window.suiIcon ? window.suiIcon('rotate-ccw', 'currentColor', 18) : '🔄'}</span>
                                <div style="text-align:left;">
                                    <div style="font-weight:700; font-size:14px; color:var(--text-primary);">Restore Latest Checkpoint</div>
                                    <div style="font-size:11px; color:var(--text-secondary);">Revert all changes to latest snapshot</div>
                                </div>
                            </div>`, 
                    value: "restore" 
                },
                { 
                    label: `<div style="display:flex; align-items:center; gap:10px;">
                                <span style="color:var(--primary);">${window.suiIcon ? window.suiIcon('maximize-2', 'currentColor', 18) : '🔲'}</span>
                                <div style="text-align:left;">
                                    <div style="font-weight:700; font-size:14px; color:var(--text-primary);">Open in Overlay</div>
                                    <div style="font-size:11px; color:var(--text-secondary);">Stay inside the Conjure app</div>
                                </div>
                            </div>`, 
                    value: "overlay" 
                },
                { 
                    label: `<div style="display:flex; align-items:center; gap:10px;">
                                <span style="color:var(--danger);">${window.suiIcon ? window.suiIcon('external-link', 'currentColor', 18) : '🚀'}</span>
                                <div style="text-align:left;">
                                    <div style="font-weight:700; font-size:14px; color:var(--text-primary);">Open Directly</div>
                                    <div style="font-size:11px; color:var(--text-secondary);">Navigate away to standalone recovery.php</div>
                                </div>
                            </div>`, 
                    value: "direct" 
                }
            ];
            if (window.openPicker) {
                window.openPicker("Recovery Bunker", options, null, (val) => {
                    if (val === "restore") {
                        window.elTriggerCheckpointAction?.('restore') || window.sui.toast("Undo unavailable");
                    } else if (val === "overlay") {
                        if (typeof window.scOpenBunkerOverlay === 'function') {
                            window.scOpenBunkerOverlay();
                        } else {
                            window.location.href = 'recovery.php';
                        }
                    } else if (val === "direct") {
                        window.location.href = 'recovery.php';
                    }
                });
            } else {
                window.location.href = 'recovery.php';
            }
        }
    }
},
        { 
            id: 'save', 
            icon: 'save', 
            title: 'Save Checkpoint', 
            fn: () => window.elTriggerCheckpointAction?.('save') || window.sui.toast("Save unavailable"),
            longFn: () => window.scCreateCheckpoint?.(true) || window.sui.toast("Checkpoint Studio unavailable")
        },
        { 
            id: 'history', 
            icon: 'clock', 
            title: 'Checkpoint Studio', 
            fn: () => window.scCreateCheckpoint?.() || window.sui.toast("Checkpoint Studio unavailable"),
            longFn: () => window.elShowHistoryPicker?.() || window.sui.toast("Edit Log History unavailable")
        }
    ];

    actions.forEach(a => {
        container.appendChild(createBtn(`${idPrefix}-${a.id}-btn`, a.icon, a.title, a.color, a.fn, a.longFn));
    });

    if (window.suiHydrateIcons) window.suiHydrateIcons(container);
};

// --- SHARED UI STUDIO LOGIC ---
window.cpOpenStudio = function() {
    const root = document.getElementById('cp-gui-root');
    const anchor = document.getElementById('cp-tray-anchor');
    if(!root || !anchor) return;

    window.sui.openStudio({
        id: 'cp-studio',
        title: 'Surgical Patcher',
        content: '', // Empty because we inject existing DOM
        onSetup: (contentBox, overlay) => {
            // Set Studio State
            const mainUi = document.getElementById('cp-main-ui');
            mainUi?.classList.add('cp-is-studio');
            
            // Hide the tray-specific action row to avoid duplication with Studio header
            document.getElementById('cp-tray-actions')?.classList.add('cp-hide-in-studio');

            // Move the DOM node from Tray to Studio (Preserves Event Listeners & State)
            contentBox.appendChild(root);
            contentBox.scrollTop = 0;
            
            // Adjust styles for Studio context if needed
            root.style.paddingTop = "0";
            root.style.paddingBottom = "0";

            // Inject Studio Header Actions (Undo, Save, History)
            const actions = overlay.querySelector('.sui-studio-actions');
            if (actions) {
                actions.style.display = 'flex';
                actions.style.gap = '8px';
                window.cpPopulateActions(actions, 'cp-studio');
                if (typeof window.elRefreshStatus === 'function') window.elRefreshStatus();
            }
        },
        onClose: () => {
            // Clear Studio State
            document.getElementById('cp-main-ui')?.classList.remove('cp-is-studio');

            // Restore tray-specific action row visibility
            document.getElementById('cp-tray-actions')?.classList.remove('cp-hide-in-studio');

            // Move DOM node back to Tray anchor
            anchor.appendChild(root);
            root.style.paddingBottom = "0";
        }
    });
};

let cpCurrentBatch = [];
let cpResults = [];
window._cpIsCommittingBatch = false;

window.cpApplyHintToPatch = function(idx, hint, targetField = 'find') {
    const patch = cpCurrentBatch[idx];
    if (!patch || !hint) return;

    const oldFind = patch[targetField] || "";
    const newFind = hint;
    const getLead = (str) => {
        const match = str.match(/^[ \t]*/);
        return match ? match[0] : "";
    };
    const oldLead = getLead(oldFind.split('\n').find(l => l.trim().length > 0) || "");
    const newLead = getLead(newFind.split('\n').find(l => l.trim().length > 0) || "");
    if (oldLead !== newLead) {
        const lines = patch.replace.split('\n');
        patch.replace = lines.map(line => {
            if (line.trim().length === 0) return line;
            if (line.startsWith(oldLead)) return newLead + line.substring(oldLead.length);
            return newLead + line;
        }).join('\n');
    }
    patch[targetField] = hint;
};
      

window.cpToggleDiff = function(idx) {
    const el = document.getElementById(`cp-diff-${idx}`);
    if (el) el.style.display = (el.style.display === 'none' ? 'block' : 'none');
};

function cpFormatDiffLines(currentBlock, comparisonBlock, mode, highlightVars = false) {
    const lines = currentBlock.split('\n');
    const otherLines = comparisonBlock.split('\n');
    return lines.map(line => {
        const isMatch = otherLines.includes(line);
        const className = isMatch ? 'cp-diff-line-same' : (mode === 'add' ? 'cp-diff-line-added' : 'cp-diff-line-removed');
        let content = escapeHtml(line);
        if (highlightVars) {
            content = content.replace(/\{\{([A-Z0-9_-]+)\}\}/g, '<span class="cp-variable-hl">{{$1}}</span>');
        }
        return `<span class="cp-diff-line ${className}">${content || '&nbsp;'}</span>`;
    }).join('');
}

window._cpInputFormat = 'raw';

let cpUnrecognizedContent = "";

window.cpVerifyBatch = async function() {
    const rawInput = document.getElementById("cp-input").value;
    const input = rawInput.trim();
    if(!input) return;

    const disableAuditRedirect = localStorage.getItem("cjos_cp_no_audit") === "true";
    const disableRefactorRedirect = localStorage.getItem("cjos_cp_no_refactor") === "true";

    try {
        let parsed;
        if (input.startsWith('{')) {
            window._cpInputFormat = 'json';
            parsed = JSON.parse(input);
        } else {
            window._cpInputFormat = 'raw';
            parsed = cpParseRawInput(rawInput);
        }

        if (parsed.patches && parsed.patches.length > 0) {
            cpUnrecognizedContent = parsed.residue || "";
            
            const auditPatches = parsed.patches.filter(p => p.action === 'audit');
            const refactorPatches = parsed.patches.filter(p => p.action === 'refactor');
            const executablePatches = parsed.patches.filter(p => p.action !== 'audit' && p.action !== 'refactor');

            if (!window._cpPassedActions) window._cpPassedActions = [];

            // Confirm & Pass Audits
            if (auditPatches.length > 0 && !disableAuditRedirect && typeof window.caOpenStudio === 'function') {
                const count = auditPatches.length;
                const ids = auditPatches.map(p => p._id || 'unnamed').join(', ');
                window.openConfirm(
                    "Redirect to Code Auditor", 
                    `Found ${count} audit patch(es) (${ids}). Would you like to pass them through to Code Auditor?`,
                    () => {
                        const strippedAuditText = cpExtractRawBlocksByAction(rawInput, 'audit');
                        window.caOpenStudio();
                        setTimeout(() => {
                            const caInp = document.getElementById('ca-input');
                            if (caInp) {
                                caInp.value = strippedAuditText;
                                const runBtn = document.getElementById('ca-btn-run');
                                if (runBtn) {
                                    runBtn.classList.add('ca-highlight-pulse');
                                    setTimeout(() => runBtn.classList.remove('ca-highlight-pulse'), 1600);
                                }
                            } 
                        }, 400);
                        auditPatches.forEach(p => {
                            window._cpPassedActions.push({ id: p._id || 'unnamed', action: 'audit' });
                        });
                        renderPatchCards();
                    },
                    true, "Confirm", "Cancel"
                );
            }

            // Confirm & Pass Refactors
            if (refactorPatches.length > 0 && !disableRefactorRedirect && typeof window.srOpenStudio === 'function') {
                const count = refactorPatches.length;
                const ids = refactorPatches.map(p => p._id || 'unnamed').join(', ');
                window.openConfirm(
                    "Redirect to Search & Replace", 
                    `Found ${count} refactor patch(es) (${ids}). Would you like to pass them through to Search & Replace?`,
                    () => {
                        const strippedRefactorText = cpExtractRawBlocksByAction(rawInput, 'refactor');
                        window.srOpenStudio();
                        setTimeout(() => { 
                            const srInp = document.getElementById('sr-studio-payload');
                            if (srInp) {
                                srInp.value = strippedRefactorText;
                                const runBtn = document.getElementById('sr-btn-scan');
                                if (runBtn) {
                                    runBtn.classList.add('ca-highlight-pulse');
                                    setTimeout(() => runBtn.classList.remove('ca-highlight-pulse'), 1600);
                                }
                            }
                        }, 400);
                        refactorPatches.forEach(p => {
                            window._cpPassedActions.push({ id: p._id || 'unnamed', action: 'refactor' });
                        });
                        renderPatchCards();
                    },
                    true, "Confirm", "Cancel"
                );
            }

            if (executablePatches.length > 0) {
                cpCurrentBatch = executablePatches.map((p, i) => ({
                    ...p,
                    _id: p._id || `${p.file.split('/').pop()}#${i}`
                }));
                cpResults = [];
                cpRunVerification(cpCurrentBatch);
            } else {
                document.getElementById("cp-staging-area").style.display = "flex";
                renderPatchCards();
            }
        } else {
            window.openConfirm("Patcher", "No valid patches found. Check format.", null, false, "OK", null);
        }
    } catch(e) {
        console.error(e);
        window.openConfirm("Patcher Error", "Syntax Error: " + e.message, null, false, "OK", null);
    }
};window.cpRunVerification = async function(patches) {
    const container = document.getElementById("cp-cards-container");
    container.innerHTML = `<div style="text-align:center; padding:20px; color:#888;">Verifying files...</div>`;
    document.getElementById("cp-staging-area").style.display = "flex";
    document.getElementById("cp-btn-supplemental").style.display = "block";

    const fd = new FormData();
    fd.append("plugin_action", "cp_preview");
    
    // PROTOCOL V9: Multipart Stream
    fd.append("patch_count", patches.length);
    patches.forEach((p, i) => {
        fd.append(`p_${i}_id`, p._id || p.id || '');
        fd.append(`p_${i}_file`, p.file);
        fd.append(`p_${i}_dest_file`, p.dest_file || '');
        fd.append(`p_${i}_find`, p.find);
        fd.append(`p_${i}_replace`, p.replace);
        fd.append(`p_${i}_delete_start`, p.delete_start || '');
        fd.append(`p_${i}_delete_end`, p.delete_end || '');
        fd.append(`p_${i}_range_start`, p.range_start || '');
        fd.append(`p_${i}_range_end`, p.range_end || '');
        fd.append(`p_${i}_var_name`, p.var_name || '');
        fd.append(`p_${i}_icon_name`, p.icon_name || '');
        fd.append(`p_${i}_spacing`, p.spacing || 'inline');
        fd.append(`p_${i}_match`, p.match_index || 1);
        fd.append(`p_${i}_action`, p.action || 'update');
        if (p._parse_error) fd.append(`p_${i}_parse_error`, p._parse_error);
    });

    try {
    const res = await fetch(window.CP_API_ENDPOINT || "index.php", { method: "POST", body: fd });
    const data = await res.json();
    if(data.status === "success") {
        window.cpFilesOnDisk = data.files_on_disk || {};
        let autoApplied = 0;
        data.results.forEach(r => {
            if (r.status === 'error' && r.hint) {
                const patch = cpCurrentBatch[r.id];
                const targetField = r.hint_target || 'find';
                const normTarget = (patch[targetField] || "").replace(/\s+/g, '');
                let matchCount = 0; let fIdx = 0;
                for (let k = 0; k < r.hint.length; k++) {
                    if (fIdx < normTarget.length && r.hint[k] === normTarget[fIdx]) { matchCount++; fIdx++; }
                }
                if (normTarget.length > 0 && matchCount === normTarget.length) {
                    cpApplyHintToPatch(r.id, r.hint, targetField);
                    autoApplied++;
                }
            }
        });
        if (autoApplied > 0) {
            cpRunVerification(cpCurrentBatch);
            return;
        }
        window.cpServerDiagnosticReport = data.diagnostic_report || null;
        window.cpActiveSessionId = data.session_id || null;
        const oldDoneIds = cpResults.filter(r => r.status === 'done').map(r => r.id);
        cpResults = data.results.map(res => {
            if (oldDoneIds.includes(res.id)) res.status = 'done';
            return res;
        });
        
        // --- DUAL-KEY AUDIT VERIFICATION ---
        for (let res of cpResults) {
            const patch = cpCurrentBatch[res.id];
            if (patch && patch.audit_link && res.status !== 'done' && typeof window.caGetPattern === 'function') {
                const data = await window.caGetPattern(patch.audit_link);
                if (data && data.pattern) {
                    res.audit_pattern = data.pattern;
                    res.audit_details = data.match_details;
                    // Normalizer for loose comparison
                    const norm = (str) => (str || "").replace(/\s+/g, '');
                    
                    res.audit_pattern_verified = patch.find.includes(data.pattern);
                    res.audit_file_verified = (res.file === data.expected_file);
                    res.audit_verified = res.audit_pattern_verified && res.audit_file_verified;

                    // Stricter Context Check (Prev + Content + Next)
                    if (data.match_details) {
                        const d = data.match_details;
                        const fullContext = (d.context.prev || "") + d.content + (d.context.next || "");
                        res.audit_context_verified = norm(patch.find).includes(norm(fullContext));
                    } else {
                        res.audit_context_verified = false;
                    }

                    if (!res.audit_verified) {
                        res.status = 'error';
                        res.msg = res.audit_file_verified ? "Audit Pattern Mismatch" : "Audit File Mismatch";
                    }
                } else {
                    // LINK FAILURE: The code matched, but the audit handshake failed.
                    res.status = 'error';
                    res.audit_link_error = true; // Flag specifically for UI
                    res.msg = "Code block matched, but Audit Link is malformed (Check filename).";
                    res.audit_verified = false;
                }
            }
        }

        renderPatchCards();
    } else { container.innerHTML = `<div style="color:red;">${data.message}</div>`; }
      
    } catch(e) { 
console.error("cpRunVerification Error:", e);
container.innerHTML = `<div style="color:red; text-align:center; padding:20px;">Error: ${e.message}</div>`; 
    }
};

window.cpCommitAll = async function(forceLiteral = false) {
    const readyResults = cpResults.filter(r => r.status === 'success');
    if (readyResults.length === 0) return;
    
    const uniqueFiles = [...new Set(readyResults.map(r => r.file))];
    const confirmMsg = forceLiteral ? `Force commit ${readyResults.length} patches as literal text?` : `Apply ${readyResults.length} patches to ${uniqueFiles.length} files?`;
    
    window.openConfirm("Apply Batch", confirmMsg, async () => {
        window._cpIsCommittingBatch = true;
        renderPatchCards(); 

        const auditQueue = [];
        const allPatches = readyResults.map(res => cpCurrentBatch[res.id]);

        try {
            const fd = new FormData();
            fd.append("plugin_action", "cp_commit_batch");
            if (forceLiteral) fd.append("force_literal", "1");
            fd.append("patch_count", allPatches.length);
            allPatches.forEach((p, i) => {
                fd.append(`p_${i}_id`, p._id || '');
                fd.append(`p_${i}_file`, p.file || '');
                fd.append(`p_${i}_dest_file`, p.dest_file || '');
                fd.append(`p_${i}_find`, p.find || '');
                fd.append(`p_${i}_replace`, p.replace || '');
                fd.append(`p_${i}_delete_start`, p.delete_start || '');
                fd.append(`p_${i}_delete_end`, p.delete_end || '');
                fd.append(`p_${i}_range_start`, p.range_start || '');
                fd.append(`p_${i}_range_end`, p.range_end || '');
                fd.append(`p_${i}_var_name`, p.var_name || '');
                fd.append(`p_${i}_icon_name`, p.icon_name || '');
                fd.append(`p_${i}_spacing`, p.spacing || 'inline');
                fd.append(`p_${i}_match`, p.match_index || 1);
                fd.append(`p_${i}_action`, p.action || 'update');
            });

            const res = await fetch(window.CP_API_ENDPOINT || "index.php", { method: "POST", body: fd });
            const data = await res.json();
            if (data.status === "success") {
                data.patch_results.forEach((pRes, idx) => {
                    const resObj = readyResults[idx];
                    if (!resObj) return;
                    
                    if (pRes.success) {
                        resObj.status = 'done';
                        resObj.msg = "Applied successfully.";
                        const patch = allPatches[idx];
                        if (patch && patch.audit_link) auditQueue.push(patch.audit_link);
                    } else {
                        resObj.status = 'error';
                        resObj.msg = pRes.error;
                    }
                });

            // Process Audit Handshakes Sequentially
            if (auditQueue.length > 0 && typeof window.caMarkMatchDone === 'function') {
                for (const link of auditQueue) {
                    try { await window.caMarkMatchDone(link); } catch(e) { console.error("Audit handshake failed:", e); }
                }
            }

            // --- PATCH HISTORY HOOK ---
            // Save the raw text of the patcher input upon 100% successful commit
            if (typeof window.phSavePatch === 'function') {
                const rawText = document.getElementById("cp-input").value;
                window.phSavePatch(rawText);
            }

        } else {
    setTimeout(() => {
        if (data.message.includes("Variable") && data.message.includes("never cut")) {
            window.openConfirm("Template Tags Detected", 
                "The patcher detected curly-brace tags that weren't cut. Are these literal template tags for your code?\n\n" + data.message,
                () => cpCommitAll(true),
                true, "Yes, Commit as Literal", "Cancel"
            );
        } else {
            const errText = "Batch commit failed: " + data.message;
            window.openConfirm("Batch Error", errText, () => {
                cpCopyToClipboard(errText).then(() => window.sui.toast("Error Message Copied"));
            }, true, "Copy Error", "Close");
        }
    }, 400);
}
        } catch(e) { 
setTimeout(() => {
    window.openConfirm("Network Error", "Network error during batch commit.", null, false, "OK", null); 
}, 400);
        }

        window._cpIsCommittingBatch = false;
        renderPatchCards();
        });
    };window.cpRemovePatch = function(idx) {
    cpCurrentBatch.splice(idx, 1);
    cpResults.splice(idx, 1);
    // Re-index results so the pointer always matches the current array position
    cpResults.forEach((res, i) => { res.id = i; });
    renderPatchCards();
};

window.cpCommitSingle = async function(idx) {
    const res = cpResults[idx];
    const patch = cpCurrentBatch[idx];
    if (res.status !== 'success' || window._cpIsCommittingBatch) return;

    window.openConfirm("Apply Patch", `Apply this patch to ${res.file}?`, async () => {
        window._cpIsCommittingBatch = true;
    renderPatchCards();

    const fd = new FormData();
    fd.append("plugin_action", "cp_commit_batch");
    
    // PROTOCOL V11: Multipart Stream
    fd.append("patch_count", 1);
    fd.append("p_0_id", patch._id || '');
    fd.append("p_0_file", patch.file);
    fd.append("p_0_dest_file", patch.dest_file || '');
    fd.append("p_0_find", patch.find || '');
    fd.append("p_0_replace", patch.replace || '');
    fd.append("p_0_delete_start", patch.delete_start || '');
    fd.append("p_0_delete_end", patch.delete_end || '');
    fd.append("p_0_range_start", patch.range_start || '');
    fd.append("p_0_range_end", patch.range_end || '');
    fd.append("p_0_var_name", patch.var_name || '');
    fd.append("p_0_icon_name", patch.icon_name || '');
    fd.append("p_0_spacing", patch.spacing || 'inline');
    fd.append("p_0_match", patch.match_index || 1);
    fd.append("p_0_action", patch.action || 'update');

    try {
        const fetchRes = await fetch(window.CP_API_ENDPOINT || "index.php", { method: "POST", body: fd });
        const data = await fetchRes.json();
        if (data.status === "success" && data.results[0].success) {
            res.status = 'done';
            res.msg = "Applied successfully.";
        } else {
    setTimeout(() => {
        window.openConfirm("Commit Error", "Commit failed: " + (data.results[0].error || data.message), null, false, "OK", null);
    }, 400);
}
        } catch(e) { 
setTimeout(() => {
    window.openConfirm("Network Error", "Network error.", null, false, "OK", null); 
}, 400);
        }

        window._cpIsCommittingBatch = false;
        renderPatchCards();
        });
    };window.cpShowAuditTooltip = function(idx) {
    const res = cpResults[idx];
    const patch = cpCurrentBatch[idx];
    if (!res || !res.audit_details || !window.openPicker) return;

    const d = res.audit_details;
    const options = [
        { label: "Target Reference", type: "header" },
        { 
            label: `<div style="font-size:11px;">
                        <div style="font-weight:800; color:var(--text-secondary); margin-bottom:4px;">AUDIT TARGET (SCOPE)</div>
                        <div style="font-family:monospace; color:var(--primary); margin-bottom:10px;">${res.file_filter || 'System-wide'}</div>
                        <div style="font-weight:800; color:var(--text-secondary); margin-bottom:4px;">EXPECTED MATCH FILE</div>
                        <div style="font-family:monospace; color:${res.audit_file_verified ? 'var(--success-text)' : 'var(--danger)'};">${d.file}</div>
                        <div style="font-weight:800; color:var(--text-secondary); margin:10px 0 4px 0;">EXPECTED PATTERN</div>
                        <div style="font-family:monospace; background:rgba(0,0,0,0.03); padding:8px; border-radius:6px; color:var(--text-primary); white-space:pre-wrap;">${escapeHtml(res.audit_pattern)}</div>
                    </div>`,
            type: "info"
        },
        { label: "Original Context (Line " + d.line + ")", type: "header" },
        {
            label: `<div style="font-family:monospace; font-size:10px; background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:10px; line-height:1.4;">
                        ${d.context.prev ? `<div style="opacity:0.4;">${escapeHtml(d.context.prev)}</div>` : ''}
                        <div style="color:var(--primary); font-weight:700;">${escapeHtml(d.content)}</div>
                        ${d.context.next ? `<div style="opacity:0.4;">${escapeHtml(d.context.next)}</div>` : ''}
                    </div>`,
            type: "info"
        }
    ];

    window.openPicker("Audit Source of Truth", options, null, null);
};

window.cpJumpToFile = function(fileName) {
    const fileResults = cpResults.filter(r => r.file === fileName);
    if (fileResults.length > 0) {
        // Priority: Jump to first error, otherwise first patch
        const target = fileResults.find(r => r.status === 'error') || fileResults[0];
        const card = document.getElementById(`cp-card-${target.id}`);
        if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            card.classList.remove('cp-jump-active');
            void card.offsetWidth; 
            card.classList.add('cp-jump-active');
            setTimeout(() => card.classList.remove('cp-jump-active'), 1600);
        }
    }
};

function renderPatchCards() {
    const container = document.getElementById("cp-cards-container");
    const bulkArea = document.getElementById("cp-bulk-actions");
    const summary = document.getElementById("cp-summary-bar");
    container.innerHTML = ""; bulkArea.innerHTML = "";

    const isExportAction = (act) => act === 'file_export' || act === 'export' || act === 'file_export_skeleton' || act === 'export_skeleton';

    if (window._cpPassedActions && window._cpPassedActions.length > 0) {
        const passedDiv = document.createElement("div");
        passedDiv.style.cssText = "background:var(--card-bg); border:1px dashed var(--border-color); border-radius:12px; padding:12px; margin-bottom:12px;";
        let passedHtml = `<div style='font-size:10px; font-weight:900; color:var(--primary); text-transform:uppercase; margin-bottom:8px; letter-spacing:1px;'>➡️ Redirected Actions (${window._cpPassedActions.length})</div>`;
        passedHtml += `<div style='display:flex; flex-direction:column; gap:6px;'>`;
        window._cpPassedActions.forEach(act => {
            passedHtml += `
                <div style="display:flex; align-items:center; justify-content:space-between; gap:16px; font-size:11px; font-weight:700; background:var(--btn-bg); padding:8px 12px; border-radius:10px; border:1px solid rgba(0,0,0,0.03);">
                    <div style="color:var(--text-primary); word-break:break-all; flex:1; min-width:0; text-align:left;">
                        <span style="color:var(--text-secondary); font-size:10px; font-weight:500; margin-right:4px;">ID:</span>${act.id}
                    </div>
                    <span style="background:var(--bg-color); color:var(--primary); font-size:9px; font-weight:900; padding:2px 6px; border-radius:4px; text-transform:uppercase; border:1px solid rgba(0,0,0,0.05); flex-shrink:0;">
                        ${act.action}
                    </span>
                </div>
            `;
        });
        passedHtml += `</div>`;
        passedDiv.innerHTML = passedHtml;
        container.appendChild(passedDiv);
    }

    const errorResults = cpResults.filter(r => r.status === 'error');
    const readyResults = cpResults.filter(r => r.status === 'success');
    const doneResults = cpResults.filter(r => r.status === 'done');

    summary.innerHTML = `
        <div style="text-align:center;"><div style="font-size:9px; color:#8E8E93; font-weight:700; text-transform:uppercase;">Total</div><div style="font-size:16px; font-weight:800;">${cpResults.length}</div></div>
        <div style="text-align:center;"><div style="font-size:9px; color:#34C759; font-weight:700; text-transform:uppercase;">Ready</div><div style="font-size:16px; font-weight:800; color:#34C759;">${readyResults.length}</div></div>
        <div style="text-align:center;"><div style="font-size:9px; color:#FF3B30; font-weight:700; text-transform:uppercase;">Failed</div><div style="font-size:16px; font-weight:800; color:#FF3B30;">${errorResults.length}</div></div>
    `;

    // --- MANIFEST BADGES ---
    const manifest = document.createElement("div");
    manifest.style.cssText = "background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:12px; margin-bottom:12px;";
    
    const uniqueFiles = [...new Set(cpResults.map(r => r.file))];
    const deletedFiles = uniqueFiles.filter(f => {
        const fileRes = cpResults.filter(r => r.file === f);
        return fileRes.some(r => cpCurrentBatch[r.id] && cpCurrentBatch[r.id].action === 'file_delete');
    });
    const movedFiles = uniqueFiles.filter(f => {
        const fileRes = cpResults.filter(r => r.file === f);
        return fileRes.some(r => cpCurrentBatch[r.id] && cpCurrentBatch[r.id].action === 'file_move');
    });
    const copiedFiles = uniqueFiles.filter(f => {
        if (deletedFiles.includes(f) || movedFiles.includes(f)) return false;
        const fileRes = cpResults.filter(r => r.file === f);
        return fileRes.some(r => cpCurrentBatch[r.id] && cpCurrentBatch[r.id].action === 'file_copy');
    });
    const newFiles = uniqueFiles.filter(f => {
        if (deletedFiles.includes(f) || movedFiles.includes(f) || copiedFiles.includes(f)) return false;
        const fileRes = cpResults.filter(r => r.file === f);
        return fileRes.some(r => cpCurrentBatch[r.id] && cpCurrentBatch[r.id].action === 'file_create');
    });
    const overwrittenFiles = uniqueFiles.filter(f => {
        if (deletedFiles.includes(f) || movedFiles.includes(f) || copiedFiles.includes(f) || newFiles.includes(f)) return false;
        const fileRes = cpResults.filter(r => r.file === f);
        return fileRes.some(r => cpCurrentBatch[r.id] && cpCurrentBatch[r.id].action === 'file_overwrite');
    });
    const exportedFiles = uniqueFiles.filter(f => {
        if (deletedFiles.includes(f) || movedFiles.includes(f) || copiedFiles.includes(f) || newFiles.includes(f) || overwrittenFiles.includes(f)) return false;
        const fileRes = cpResults.filter(r => r.file === f);
        return fileRes.some(r => cpCurrentBatch[r.id] && isExportAction(cpCurrentBatch[r.id].action));
    });
    const patchedFiles = uniqueFiles.filter(f => 
        !newFiles.includes(f) && 
        !deletedFiles.includes(f) && 
        !movedFiles.includes(f) && 
        !copiedFiles.includes(f) && 
        !overwrittenFiles.includes(f) &&
        !exportedFiles.includes(f)
    );

    const getBadge = (f) => {
    const fileRes = cpResults.filter(r => r.file === f);
    const patch = fileRes.length > 0 ? (cpCurrentBatch[fileRes[0].id] || {}) : {};
    const hasErr = fileRes.some(r => r.status === 'error');
    const allDone = fileRes.every(r => r.status === 'done');
            
    let color = "var(--primary)";
    let bg = "var(--btn-bg)";
    let icon = "";

    if (patch.action === 'file_delete') {
    color = "#FF3B30"; bg = "rgba(255, 59, 48, 0.1)"; icon = "🗑️ ";
} else if (patch.action === 'file_move') {
    color = "#FF9500"; bg = "rgba(255, 149, 0, 0.1)"; icon = "🚚 ";
} else if (patch.action === 'file_copy') {
    color = "#007AFF"; bg = "rgba(0, 122, 255, 0.1)"; icon = "📋 ";
} else if (patch.action === 'file_create') {
    color = "#5856D6"; bg = "rgba(88, 86, 214, 0.1)"; icon = "✨ ";
} else if (patch.action === 'file_overwrite') {
    color = "#FF9500"; bg = "rgba(255, 149, 0, 0.1)"; icon = "💥 ";
} else if (isExportAction(patch.action)) {
    color = (patch.action && patch.action.includes('skeleton')) ? "#5856D6" : "var(--primary)";
    bg = (patch.action && patch.action.includes('skeleton')) ? "rgba(88, 86, 214, 0.08)" : "rgba(0, 122, 255, 0.05)";
    icon = (patch.action && patch.action.includes('skeleton')) ? "⚡ " : "📤 ";
}if (hasErr) { color = "white"; bg = "var(--danger)"; icon = "⚠️ "; }
    else if (allDone) { color = "var(--success-text)"; bg = "var(--success-bg)"; icon = "✅ "; }return `<div onclick="cpJumpToFile('${f}')" 
                     onpointerdown="cpStartLongPress(event, '${f}')" 
                     onpointerup="cpEndLongPress()" 
                     onpointerleave="cpEndLongPress()" 
                     style="background:${bg}; color:${color}; font-size:11px; font-weight:700; padding:6px 12px; border-radius:10px; border:1px solid ${color}33; cursor:pointer; user-select:none; -webkit-user-select:none; word-break:break-all; display:flex; align-items:center; gap:4px;">${icon}${f}</div>`;
    };

    let manifestHtml = "";
    if (deletedFiles.length > 0) {
        manifestHtml += `<div style='font-size:10px; font-weight:900; color:#FF3B30; text-transform:uppercase; margin-bottom:8px; letter-spacing:1px;'>🗑️ Files to Delete (${deletedFiles.length})</div>`;
        manifestHtml += `<div style='display:flex; flex-wrap:wrap; gap:6px; margin-bottom:20px;'>${deletedFiles.map(getBadge).join('')}</div>`;
    }
    if (movedFiles.length > 0) {
        manifestHtml += `<div style='font-size:10px; font-weight:900; color:#FF9500; text-transform:uppercase; margin-bottom:8px; letter-spacing:1px;'>🚚 Files to Relocate (${movedFiles.length})</div>`;
        manifestHtml += `<div style='display:flex; flex-wrap:wrap; gap:6px; margin-bottom:20px;'>${movedFiles.map(getBadge).join('')}</div>`;
    }
    if (copiedFiles.length > 0) {
        manifestHtml += `<div style='font-size:10px; font-weight:900; color:#007AFF; text-transform:uppercase; margin-bottom:8px; letter-spacing:1px;'>📋 Files to Copy (${copiedFiles.length})</div>`;
        manifestHtml += `<div style='display:flex; flex-wrap:wrap; gap:6px; margin-bottom:20px;'>${copiedFiles.map(getBadge).join('')}</div>`;
    }
    if (newFiles.length > 0) {
        manifestHtml += `<div style='font-size:10px; font-weight:900; color:#5856D6; text-transform:uppercase; margin-bottom:8px; letter-spacing:1px;'>✨ New Files (${newFiles.length})</div>`;
        manifestHtml += `<div style='display:flex; flex-wrap:wrap; gap:6px; margin-bottom:20px;'>${newFiles.map(getBadge).join('')}</div>`;
    }
    if (overwrittenFiles.length > 0) {
        manifestHtml += `<div style='font-size:10px; font-weight:900; color:#FF9500; text-transform:uppercase; margin-bottom:8px; letter-spacing:1px;'>💥 Files to Overwrite (${overwrittenFiles.length})</div>`;
        manifestHtml += `<div style='display:flex; flex-wrap:wrap; gap:6px; margin-bottom:20px;'>${overwrittenFiles.map(getBadge).join('')}</div>`;
    }
    if (exportedFiles.length > 0) {
        manifestHtml += `<div style='font-size:10px; font-weight:900; color:var(--primary); text-transform:uppercase; margin-bottom:8px; letter-spacing:1px;'>📤 Files to Export (${exportedFiles.length})</div>`;
        manifestHtml += `<div style='display:flex; flex-wrap:wrap; gap:6px; margin-bottom:20px;'>${exportedFiles.map(getBadge).join('')}</div>`;
    }
    if (patchedFiles.length > 0) {
        manifestHtml += `<div style='font-size:10px; font-weight:900; color:#8E8E93; text-transform:uppercase; margin-bottom:8px; letter-spacing:1px;'>📝 Modified Files (${patchedFiles.length})</div>`;
        manifestHtml += `<div style='display:flex; flex-wrap:wrap; gap:6px;'>${patchedFiles.map(getBadge).join('')}</div>`;
    }
    
    manifest.innerHTML = manifestHtml;
    container.appendChild(manifest);

    // --- UNRECOGNIZED CONTENT CARD ---
    if (cpUnrecognizedContent) {
        const unrec = document.createElement("div");
        unrec.className = "cp-patch-card cp-unrecognized-card";
        unrec.style.cssText = "border-radius:14px; padding:16px; margin-bottom:12px;";
        unrec.innerHTML = `
            <div class="cp-unrecognized-header" style="justify-content: space-between;">
                <div style="display:flex; align-items:center; gap:6px;">
                    <span data-sui-icon="alert-triangle" data-sui-size="14"></span>
                    Unrecognized Content
                </div>
                <button onclick="cpCopyUnrecognized()" 'UNRECOGNIZED CONTENT: The following text was ignored by the parser. Check for malformed tags or missing #FILE headers.\n\n' + style="background:rgba(211, 47, 47, 0.1); border:1px solid rgba(211, 47, 47, 0.2); color:#D32F2F; padding:2px 8px; border-radius:6px; cursor:pointer; display:flex; align-items:center; gap:4px; font-size:9px; font-weight:900; transition: all 0.2s;">
                    <span data-sui-icon="copy" data-sui-size="10"></span>
                    COPY
                </button>
            </div>
            <div style="font-size:11px; margin-bottom:8px; opacity:0.8;">The following text was ignored by the parser. Check for malformed tags or missing #FILE headers.</div>
            <div class="cp-unrecognized-body">${escapeHtml(cpUnrecognizedContent)}</div>
        `;
        container.appendChild(unrec);
        if (window.suiHydrateIcons) window.suiHydrateIcons(unrec);
    }

    if (errorResults.length > 0) {
        const btnErrors = document.createElement("button");
        btnErrors.innerText = `Copy ${errorResults.length} Errors`;
        btnErrors.title = "Tap to copy error report. Long-press to copy report + source code context.";
        btnErrors.style.cssText = "padding:4px 8px; font-size:10px; font-weight:700; background:rgba(255, 59, 48, 0.1); color:var(--danger); border:1px solid var(--danger); border-radius:6px; cursor:pointer; user-select:none; -webkit-user-select:none;";
        
        let errorHoldTimer = null;
        let isErrorLongHold = false;
        
        btnErrors.addEventListener('contextmenu', e => e.preventDefault());
        
        btnErrors.onpointerdown = (e) => {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            isErrorLongHold = false;
            errorHoldTimer = setTimeout(() => {
                isErrorLongHold = true;
                if (window.sui && window.sui.haptic) window.sui.haptic('medium');
                window.sui.toast("Release to copy errors + context");
            }, 600);
        };
        
        btnErrors.onpointerup = (e) => {
            clearTimeout(errorHoldTimer);
            if (isErrorLongHold) {
                cpCopyAllErrorReportsWithContext();
            } else {
                cpCopyAllReports();
            }
            isErrorLongHold = false;
        };
        
        btnErrors.onpointerleave = () => {
            clearTimeout(errorHoldTimer);
            isErrorLongHold = false;
        };
        
        bulkArea.appendChild(btnErrors);
    }

    const exportResults = cpResults.filter(r => r.status === 'success' && isExportAction(cpCurrentBatch[r.id].action));
    const isExportOnly = cpCurrentBatch.length > 0 && cpCurrentBatch.every(p => isExportAction(p.action));
    const isTraceOnly = cpCurrentBatch.length > 0 && cpCurrentBatch.every(p => p.action === 'logic_trace');
    const canCommitAll = errorResults.length === 0 && readyResults.length > 0 && !isExportOnly;

    if (exportResults.length > 0) {
        const btnCopyAll = document.createElement("button");
        const count = exportResults.length;
        btnCopyAll.innerText = count > 1 ? `Copy All (${count}) Source` : "Copy Exported Source";
        btnCopyAll.style.cssText = "padding:4px 10px; font-size:10px; font-weight:700; border-radius:6px; border:none; cursor:pointer; background:var(--primary); color:var(--primary-text); margin-right:8px;";
        let exportHoldTimer;
        let isLongHold = false;
        const getExportContent = () => {
            const allSource = exportResults.map(r => r.export_block).join("\n");
            return "~~~\n" + allSource.trim() + "\n\n~~~";
        };

        // Prevent system context menu on long-press
        btnCopyAll.addEventListener('contextmenu', e => e.preventDefault());

        btnCopyAll.onpointerdown = (e) => {
            isLongHold = false;
            exportHoldTimer = setTimeout(() => {
                isLongHold = true;
                if (window.sui && window.sui.haptic) window.sui.haptic('medium');
                window.sui.toast("Release to Download TXT");
            }, 800);
        };

        btnCopyAll.onpointerup = (e) => {
            clearTimeout(exportHoldTimer);
            const content = getExportContent();
            
            if (isLongHold) {
                // Trigger download on release to maintain User Activation chain
                const ts = new Date().getTime();
                cpDownloadText(`cjos_export_${ts}.txt`, content);
            } else {
                // Standard Copy logic
                cpCopyToClipboard(content).then(success => {
                    showCopyToast(success, exportResults.length);
                }).catch(() => {
                    showCopyToast(false, 0);
                });
            }
            isLongHold = false;
        };

        const showCopyToast = (success, count) => {
            const t = document.getElementById("toast");
            if(!t) return;
            if (success) {
                t.innerText = count > 1 ? `All (${count}) Source Blocks Copied` : "Source Block Copied";
                t.style.background = "var(--success-bg)";
                t.style.color = "var(--success-text)";
            } else {
                t.innerText = "Copy Failed (Try Download)";
                t.style.background = "var(--warn-bg)";
                t.style.color = "var(--danger)";
            }
            t.classList.add("show");
            setTimeout(() => t.classList.remove("show"), 2500);
        };

        btnCopyAll.onpointerleave = () => {
            clearTimeout(exportHoldTimer);
            isLongHold = false;
        };
        bulkArea.appendChild(btnCopyAll);
    }

    const btnAll = document.createElement("button");
    if (window._cpIsCommittingBatch) {
        btnAll.innerText = "Committing..."; btnAll.disabled = true;
        btnAll.style.cssText = "padding:4px 10px; font-size:10px; font-weight:700; border-radius:6px; border:none; background:var(--btn-bg); color:var(--text-secondary);";
    } else if (isTraceOnly && canCommitAll) {
        btnAll.innerText = "Copy 'Let's Go'";
        btnAll.style.cssText = "padding:4px 10px; font-size:10px; font-weight:700; border-radius:6px; border:none; cursor:pointer; background:var(--primary); color:var(--primary-text);";
        btnAll.onclick = () => {
            navigator.clipboard.writeText("All logic points verified. Let's go.");
            const t = document.getElementById("toast");
            if(t) { t.innerText = "Message Copied"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
        };
    } else if (doneResults.length > 0 && readyResults.length === 0) {
        btnAll.innerHTML = "Reload"; btnAll.style.cssText = "padding:4px 10px; font-size:10px; font-weight:700; border-radius:6px; border:none; cursor:pointer; background:#34C759; color:white;";
        btnAll.onclick = () => location.reload();
    } else {
        btnAll.innerText = "Commit All";
        btnAll.style.cssText = `padding:4px 10px; font-size:10px; font-weight:700; border-radius:6px; border:none; cursor:${canCommitAll ? 'pointer' : 'not-allowed'}; background:${canCommitAll ? 'var(--primary)' : 'var(--btn-bg)'}; color:${canCommitAll ? 'var(--primary-text)' : 'var(--text-secondary)'};`;
        btnAll.disabled = !canCommitAll; btnAll.onclick = cpCommitAll;
    }
    bulkArea.appendChild(btnAll);

    // --- BOTTOM COMMIT BUTTON ---
    const stagingArea = document.getElementById("cp-staging-area");
    let bottomBtn = document.getElementById("cp-btn-commit-all-bottom");
    if (!bottomBtn) {
        bottomBtn = document.createElement("button");
        bottomBtn.id = "cp-btn-commit-all-bottom";
        bottomBtn.style.cssText = "width:100%; padding:14px; border-radius:12px; font-weight:700; margin-top:10px; border:none; transition: all 0.2s;";
        stagingArea.appendChild(bottomBtn);
    }
    
    if (window._cpIsCommittingBatch) {
        bottomBtn.innerText = "Committing Batch...";
        bottomBtn.disabled = true;
        bottomBtn.style.background = "var(--btn-bg)";
        bottomBtn.style.color = "var(--text-secondary)";
    } else if (doneResults.length > 0 && readyResults.length === 0) {
        bottomBtn.innerText = "Finalize & Reload App";
        bottomBtn.style.display = "block";
        bottomBtn.style.background = "var(--primary)";
        bottomBtn.style.color = "var(--primary-text)";
        bottomBtn.disabled = false;
        bottomBtn.onclick = () => location.reload();
    } else if (isTraceOnly) {
        bottomBtn.innerText = "Copy Confirmation Message";
        bottomBtn.style.display = readyResults.length > 0 ? "block" : "none";
        bottomBtn.style.background = canCommitAll ? "var(--primary)" : "var(--btn-bg)";
        bottomBtn.style.color = canCommitAll ? "var(--primary-text)" : "var(--text-secondary)";
        bottomBtn.disabled = !canCommitAll;
        bottomBtn.onclick = () => {
            navigator.clipboard.writeText("All logic points verified. Let's go.");
            const t = document.getElementById("toast");
            if(t) { t.innerText = "Message Copied"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
        };
    } else {
        bottomBtn.innerText = "Commit All Patches";
        bottomBtn.style.display = readyResults.length > 0 ? "block" : "none";
        bottomBtn.style.background = canCommitAll ? "var(--primary)" : "var(--btn-bg)";
        bottomBtn.style.color = canCommitAll ? "var(--primary-text)" : "var(--text-secondary)";
        bottomBtn.disabled = !canCommitAll;
        bottomBtn.onclick = cpCommitAll;
    }

    cpResults.forEach((res, idx) => {
        const patch = cpCurrentBatch[idx];
        const card = document.createElement("div");
        card.id = `cp-card-${idx}`; card.className = "cp-patch-card";
        card.style.cssText = "background:var(--card-bg); border:1px solid var(--border-color); border-radius:14px; padding:16px; margin-bottom:10px;";
        
        const isErr = res.status === "error";
const isDone = res.status === "done";
if (patch._isFix) card.classList.add('cp-patch-fixed');
const fixBadge = patch._isFix ? `<span class="cp-fix-badge">FIX APPLIED</span>` : '';

let statusText = 'READY';
let statusColor = '#007AFF';
let hideDiff = isDone;
let diffHtml = '';
let extraBtns = '';

if (window.CP_UI[patch.action]) {
    const uiDef = window.CP_UI[patch.action];
    statusText = uiDef.statusText || statusText;
    statusColor = uiDef.statusColor || statusColor;
    if (uiDef.hideDiffOnDone !== undefined) hideDiff = isDone || uiDef.hideDiffOnDone;
    if (uiDef.forceHideDiff) hideDiff = true;
    if (uiDef.renderDiff) diffHtml = uiDef.renderDiff(patch, res, idx);
    if (uiDef.renderExtraButtons) extraBtns = uiDef.renderExtraButtons(patch, res, idx, isDone, isErr);
}

if (isErr) {
    const errBadge = `<span style="background:#ff3b30; color:#ffffff; font-size:9px; font-weight:800; padding:2px 6px; border-radius:4px; margin-left:6px; vertical-align:middle; display:inline-block; line-height:1;">ERROR</span>`;
    statusText = (statusText === 'READY') ? errBadge : `${statusText}${errBadge}`;
    statusColor = '#FF3B30';
} else if (isDone) {
    statusText = 'DONE';
    statusColor = '#34C759';
}if (res.audit_link_error) { statusText = "AUDIT FAIL"; statusColor = "#AF52DE"; }

let auditBadge = "";
if (patch.audit_link) {
    const isV = res.audit_verified;
    const isStrong = res.audit_context_verified;
    
    let auditStatusText = isStrong ? "Verified" : (isV ? "Weak Match" : "Mismatch");
    if (!res.audit_file_verified) auditStatusText = "File Mismatch";
    else if (!res.audit_pattern_verified) auditStatusText = "Pattern Mismatch";

    let color = "var(--danger)";
    let bg = "rgba(255, 59, 48, 0.1)";
    let icon = "⚠";

    if (isStrong) {
        color = "var(--success-text)";
        bg = "var(--success-bg)";
        icon = "✓";
    } else if (isV) {
        color = "#B45309";
        bg = "#FFFBE6";
        icon = "⚠️";
    }

    auditBadge = `<div onclick="cpShowAuditTooltip(${idx})" style="margin-top:4px; display:inline-flex; align-items:center; gap:4px; background:${bg}; color:${color}; font-size:9px; font-weight:900; padding:2px 8px; border-radius:4px; text-transform:uppercase; border:1px solid ${color}44; cursor:pointer;">${icon} Audit ${auditStatusText} <span style="margin-left:2px; font-size:10px; opacity:0.8;">ℹ️</span></div>`;
}

const statusHtml = `<div style="text-align:right;"><div style="color:${statusColor}; font-weight:700; font-size:11px;">${statusText}${fixBadge}</div>${auditBadge}</div>`;
const idBadge = `<span style="font-family:monospace; font-size:10px; background:rgba(0,0,0,0.05); padding:2px 6px; border-radius:4px; color:var(--text-secondary); margin-right:8px;">ID: ${patch._id}</span>`;

let commentHtml = "";
if (patch.comment) {
    const commClass = patch._isFix ? 'cp-fix-comment' : '';
    commentHtml = `<div onclick="cpToggleDiff(${idx})" class="${commClass}" style="font-size:12px; color:var(--text-primary); font-style:italic; margin-bottom:8px; background:var(--btn-bg); padding:8px; border-radius:10px; cursor:pointer; border:1px solid var(--border-color);">💡 ${escapeHtml(patch.comment)}</div>`;
}

card.innerHTML = `
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
        <div style="display:flex; flex-direction:column; gap:4px; flex:1; min-width:0;">
            <div style="display:flex;">${idBadge}</div>
            <div class="cp-file-path" style="font-weight:700; font-size:13px; word-break:break-all; color:var(--text-primary);">
                ${res.file}
            </div>
        </div>
        ${statusHtml}
    </div>
    ${commentHtml}
    <div style="font-size:12px; color:#666; margin-bottom:8px;">${res.msg}</div>
    <div id="cp-diff-${idx}" class="cp-diff-container" style="display: ${hideDiff ? 'none' : 'block'}">
        ${diffHtml}
    </div>
    <div style="margin-bottom:12px; margin-top:8px; display:flex; justify-content:space-between; align-items:center;">
    ${isExportAction(patch.action) ? `<div style="font-size:11px; color:var(--text-secondary); font-style:italic;">Ready to export ${patch.action.includes('skeleton') ? 'skeleton' : 'source'}.</div>` : `<a href="#" onclick="event.preventDefault(); cpToggleDiff(${idx})" style="font-size:11px; color:var(--primary); text-decoration:none;">Toggle Diff View</a>`}
    <div style="display:flex; gap:8px;">${isErr ? `<button onclick="cpCopySingleReport(${idx})" style="background:rgba(255, 59, 48, 0.1); color:var(--danger); border:1px solid var(--danger); padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer;">Copy Error</button>` : ''}
            ${extraBtns}
            ${!isDone ? `<button onclick="cpRemovePatch(${idx})" style="background:var(--btn-bg); color:var(--btn-text); border:1px solid var(--border-color); padding:6px 12px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer;">Remove</button>` : ''}
        </div>
    </div>
`;

if (res.hint) {
    const targetField = res.hint_target || 'find';
    const normFind = (patch[targetField] || "").replace(/\s+/g, '');
    let matchCount = 0; let fIdx = 0;
    for (let i = 0; i < res.hint.length; i++) {
        if (fIdx < normFind.length && res.hint[i] === normFind[fIdx]) { matchCount++; fIdx++; }
    }
    const pct = normFind.length > 0 ? Math.round((matchCount / normFind.length) * 100) : 0;
    const pctCol = pct < 50 ? "#FF3B30" : (pct < 90 ? "#FF9500" : "#34C759");

    const hintBox = document.createElement("div");
    hintBox.className = "cp-hint-box";
    hintBox.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; border-bottom:1px solid rgba(0,0,0,0.05); padding-bottom:8px;">
            <span style="font-weight:800; color:#856404; text-transform:uppercase; letter-spacing:0.5px;">Diagnostic Reference</span>
            <div style="display:flex; gap:6px;">
                <button onclick="cpCopyToClipboard(cpResults[${idx}].hint).then(() => { window.openConfirm('Clipboard', 'Hint copied to clipboard', null, false, 'OK', null); });" style="background:#856404; color:white; border:none; padding:4px 10px; border-radius:6px; font-size:10px; font-weight:700; cursor:pointer; opacity:0.8;">COPY</button>
                <button onclick="cpApplyHint(${idx})" style="background:#856404; color:white; border:none; padding:4px 10px; border-radius:6px; font-size:10px; font-weight:700; cursor:pointer; box-shadow:0 2px 4px rgba(0,0,0,0.1);">APPLY TO ${res.hint_target ? res.hint_target.replace('_',' ').toUpperCase() : 'SEARCH'}</button>
            </div>
        </div>
        <div style="white-space:pre-wrap; color:#856404; line-height:1.4; margin-bottom:10px;">${cpHighlightHint(patch[res.hint_target || 'find'], res.hint)}</div>
        <div style="display:flex; justify-content:space-between; align-items:flex-end; border-top:1px solid rgba(133,100,4,0.1); padding-top:6px;">
            <div style="font-size:9px; color:#A67C00; font-style:italic; flex:1; padding-right:12px;">
                ⚠️ WARNING: Hint only fixes search. Ensure "replace" block is contextually correct.
            </div>
            <span style="font-family:monospace; font-weight:900; font-size:8px; padding:1px 4px; border-radius:3px; background:rgba(0,0,0,0.04); color:${pctCol}; border:1px solid rgba(0,0,0,0.04); white-space:nowrap;">${pct}% MATCH</span>
        </div>
    `;
    card.appendChild(hintBox);
}
container.appendChild(card);
    });
}

window.cpHighlightHint = function(find, hint) {
    if (!find || !hint) return escapeHtml(hint);
    const normFind = find.replace(/\s+/g, '');
    if (!normFind) return escapeHtml(hint);

    let result = "";
    let findIdx = 0;
    // Walk through the hint and wrap characters that match the logic sequence of "find"
    for (let i = 0; i < hint.length; i++) {
        const char = hint[i];
        if (findIdx < normFind.length && char === normFind[findIdx]) {
            result += '<span class="cp-hint-hl">' + escapeHtml(char) + '</span>';
            findIdx++;
        } else {
            result += escapeHtml(char);
        }
    }
    return result;
};

window.cpApplyHint = function(idx) {
    const res = cpResults[idx];
    if(!res || !res.hint) return;
    
    cpApplyHintToPatch(idx, res.hint, res.hint_target || 'find');
    
    if (window._cpInputFormat === 'json') {
        const input = document.getElementById("cp-input");
        if(input) input.value = JSON.stringify({ patches: cpCurrentBatch }, null, 2);
    }
    
    cpRunVerification(cpCurrentBatch);
};
      

window.cpGenerateManual = async function() {
    try {
        const fd = new FormData();
        fd.append("plugin_action", "cp_generate_manual");
        const res = await fetch(window.CP_API_ENDPOINT || "index.php", { method: "POST", body: fd });
        const data = await res.json();
        if (data.status === "success") {
            window.sui.toast("Manual Updated!");
        } else {
            window.openConfirm("Error", data.message, null, false, "OK", null);
        }
    } catch(e) {
        window.sui.toast("Network Error");
    }
};

window.cpParseRawInput = function(text) {
    const patches = [];
    const normalized = text.replace(/\r\n/g, '\n');
    
    // V10: Support either ACTION or PATCH_ID as the block start anchor
    const blockRegex = /(^|\n)#(?:ACTION|PATCH_ID):[\s\S]*?\n#END(?:[:\s][^\n]*)?/g;
    
    let leftover = normalized;
    let match;
    const foundBlocks = [];

    while ((match = blockRegex.exec(normalized)) !== null) {
        foundBlocks.push({
            content: match[0],
            index: match.index,
            length: match[0].length
        });
    }

    foundBlocks.forEach(item => {
    const block = item.content;
    if (!block.trim()) return;

    // Extract ACTION early
const actionMatch = block.match(/^#ACTION:\s*([^\n]+)/m);
let action = actionMatch ? actionMatch[1].trim() : "update";
if (action === 'update') action = 'file_update';
if (action === 'patch_var') action = 'var_patch';
if (action === 'refactor_var') action = 'var_refactor';
if (action === 'delete_code') action = 'code_delete';
if (action === 'cut_code') action = 'code_cut';
if (action === 'create') action = 'file_create';
if (action === 'move_file') action = 'file_move';
if (action === 'delete_file') action = 'file_delete';
if (action === 'export') action = 'file_export';
if (action === 'code_logic_trace') action = 'logic_trace';// Get Registry Rules
const reg = window.CP_REGISTRY[action] || window.CP_REGISTRY['update'];const literalTags = reg.literal || [];
        const requiredTags = reg.required || [];

        const globalTags = ['PATCH_ID', 'ACTION', 'FILE', 'MATCH', 'COMMENT', 'AUDIT_LINK', 'SPACING', 'VAR_NAME', 'ICON_NAME', 'REGEX', 'PATTERN', 'FIND', 'REPLACE'];
        const validTags = [...new Set([...globalTags, ...requiredTags, ...literalTags])];

        const lines = block.split('\n');
        let parsedData = {};
        let currentTag = null;
        let isLiteral = false;

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            if (line.trim().startsWith('#END')) break;

            const tagMatch = line.match(/^#([A-Z_]+):?(.*)$/);
            if (tagMatch && validTags.includes(tagMatch[1])) {
                currentTag = tagMatch[1];
                isLiteral = literalTags.includes(currentTag);
                let inlineText = tagMatch[2];
                if (inlineText.startsWith(' ')) inlineText = inlineText.substring(1);
                parsedData[currentTag] = inlineText;
            } else if (currentTag) {
                parsedData[currentTag] += '\n' + line;
            }
        }

        // Cleanup
        for (let key in parsedData) {
            if (literalTags.includes(key) && parsedData[key].startsWith('\n')) {
                parsedData[key] = parsedData[key].substring(1);
            }
            if (!literalTags.includes(key)) {
                parsedData[key] = parsedData[key].trim();
            }
        }

        // Validation
        let isValid = true;
        let missingTags = [];
        for (const req of requiredTags) {
            if (parsedData[req] === undefined) {
                missingTags.push(req);
                isValid = false;
            }
        }

        if (isValid || parsedData['PATCH_ID']) {
    patches.push({
        _id: parsedData['PATCH_ID'] || null,
        file: parsedData['FILE'] || "",
        dest_file: parsedData['DEST_FILE'] || "",
        find: parsedData['FIND'] || "",
        replace: parsedData['REPLACE'] || "",
        delete_start: parsedData['DELETE_START'] || "",
        delete_end: parsedData['DELETE_END'] || "",
        range_start: parsedData['RANGE_START'] || "",
        range_end: parsedData['RANGE_END'] || "",
        spacing: parsedData['SPACING'] || "inline",
        match_index: parsedData['MATCH'] ? parseInt(parsedData['MATCH']) : 1,
        comment: parsedData['COMMENT'] || "Auto-patch",
        action: action,
        var_name: parsedData['VAR_NAME'] || null,
        icon_name: parsedData['ICON_NAME'] || null,
        audit_link: parsedData['AUDIT_LINK'] || null,
        regex: parsedData['REGEX'] || null,
        pattern: parsedData['PATTERN'] || null,
        _parse_error: !isValid ? ('Missing required tag(s): #' + missingTags.join(', #')) : null
    });
    leftover = leftover.replace(block, " [PATCH_BLOCK_REMOVED] ");
}});

    // Clean up the leftover text (remove the placeholders and trim)
    const residue = leftover.replace(/ \[PATCH_BLOCK_REMOVED\] /g, "").trim();

    return { patches: patches, residue: residue };
};



window.cpCopySingleReport = function(idx) {
    const res = cpResults[idx];
    const p = cpCurrentBatch[idx];
    if (!res || !p) return;

    let text = `ERROR REPORT: Patch Failed (ID: ${p._id})\n`;
    text += `FILE: ${p.file}\n`;
    text += `ERROR: ${res.msg}\n`;

    if (p.audit_link && !res.audit_verified) {
        text += `#AUDIT_LINK: ${p.audit_link}\n`;
        if (res.audit_link_error) {
            text += `REMARK: Code matched, but Audit Link is malformed.\n`;
        } else if (!res.audit_file_verified) {
            text += `EXPECTED_FILE: ${res.audit_details ? res.audit_details.file : 'Unknown'}\n`;
        } else if (!res.audit_pattern_verified) {
            text += `EXPECTED_PATTERN: ${res.audit_pattern || 'Unknown'}\n`;
        }
    }

    const originalFind = p.find || p.range_start || p.delete_start || "";
    text += `\n# --- ORIGINAL FIND ---\n${originalFind}\n`;
    if (res.hint) text += `\n# --- DIAGNOSTIC HINT ---\n${res.hint}\n`;
    const originalReplace = p.replace || "";
    text += `\n# --- ORIGINAL REPLACE ---\n${originalReplace}\n`;

    cpCopyToClipboard(text).then(() => {
        window.sui.toast("Patch Error Copied");
    });
};

window.cpCopyAllErrorReportsWithContext = async function() {
    const errorResults = cpResults.filter(r => r.status === 'error');
    if (errorResults.length === 0) return;
    
    window.sui.toast("Fetching file context...");
    
    // 1. Generate the standard error report text
    const baseReport = cpGenerateErrorReportText(errorResults);
    
    // 2. Identify unique files involved in errors
    const errorFiles = [...new Set(errorResults.map(res => cpCurrentBatch[res.id].file))];
    
    try {
        const fd = new FormData();
        fd.append("plugin_action", "cp_preview");
        fd.append("patch_count", errorFiles.length);
        errorFiles.forEach((file, i) => {
            fd.append(`p_${i}_file`, file);
            fd.append(`p_${i}_action`, "export");
            fd.append(`p_${i}_find`, "");
            fd.append(`p_${i}_replace`, "");
            fd.append(`p_${i}_match`, 1);
        });
        
        const res = await fetch(window.CP_API_ENDPOINT || "index.php", { method: "POST", body: fd });
        const data = await res.json();
        
        let contextText = "\n\n### CONTEXT FILES\n\n";
        if (data && data.status === "success" && data.results) {
            const allSource = data.results
                .filter(r => r.export_block)
                .map(r => r.export_block)
                .join("\n");
            
            if (allSource.trim()) {
                contextText += allSource.trim();
                await cpCopyToClipboard(baseReport + contextText);
                window.sui.toast(`Copied Errors + ${errorFiles.length} File(s) Context`);
            } else {
                await cpCopyToClipboard(baseReport);
                window.sui.toast("Copied Errors (Failed to fetch context)");
            }
        } else {
            await cpCopyToClipboard(baseReport);
            window.sui.toast("Copied Errors (Context fetch failed)");
        }
    } catch(e) {
        console.error("Export of error context failed", e);
        await cpCopyToClipboard(baseReport);
        window.sui.toast("Copied Errors (Network error on context)");
    }
};

window.cpGenerateErrorReportText = function(errorResults) {
    if (window.cpServerDiagnosticReport) {
        return window.cpServerDiagnosticReport;
    }
    return "## ERROR REPORT: " + (errorResults ? errorResults.length : 0) + " Patches Failed.\nSYSTEM: Conjure Patcher Engine";
};

window.cpCopyAllReports = function() {
    const errorResults = cpResults.filter(r => r.status === 'error');
    if (errorResults.length === 0) return;

    const text = cpGenerateErrorReportText(errorResults);
    const isTrace = cpCurrentBatch.length > 0 && cpCurrentBatch.every(p => p.action === 'logic_trace');

    if (isTrace) {
        cpCopyToClipboard(text).then(() => { window.openConfirm("Trace Report", "Logic Trace Error Report Copied.", null, false, "OK", null); });
        return;
    }

    cpCopyToClipboard(text).then(() => {
        window.openConfirm("Diagnostic Report", "Detailed Diagnostic Report Copied. Paste this to the AI to generate a Surgical Fix.", null, false, "OK", null);
    });
};

window.cpSupplementalUpdate = async function() {
    const rawInput = document.getElementById("cp-input").value;
    const input = rawInput.trim();
    if(!input) return;

    try {
        let updateCount = 0;
        if (input.startsWith('{')) {
            // JSON FIXES
            const parsed = JSON.parse(input);
            if (parsed.fixes && Array.isArray(parsed.fixes)) {
                parsed.fixes.forEach(fix => {
                    const targetId = fix.target_id;
                    const index = cpCurrentBatch.findIndex(p => p._id === targetId);
                    if (index !== -1 && fix.patches) {
                        const newItems = fix.patches.map((p, i) => {
                            // If the previous attempt for this ID had a hint, and the new fix
                            // still uses the "wrong" whitespace, we can potentially auto-fix it here.
                            return {
                                ...p,
                                _isFix: true,
                                _diffOpen: true,
                                _id: (i === 0) ? targetId : (targetId + "." + i)
                            };
                        });
                        cpCurrentBatch.splice(index, 1, ...newItems);
                        updateCount++;
                    }
                });
            }
        } else {
            // RAW FIXES
            const parsed = cpParseRawInput(rawInput);
            if (parsed.patches) {
                console.log("[CP] Processing Surgical Update. Staged IDs:", cpCurrentBatch.map(p => p._id));
                // Group incoming fixes by their ID
                const groups = {};
                parsed.patches.forEach(p => {
                    console.log("[CP] Parsed patch from input. ID:", p._id, "File:", p.file);
                    if (!groups[p._id]) groups[p._id] = [];
                    groups[p._id].push(p);
                });

                for (const id in groups) {
                    const index = cpCurrentBatch.findIndex(p => p._id === id);
                    if (index !== -1) {
                        // Map the group to surgical fix objects with sub-indexing (.1, .2)
                        const newPatches = groups[id].map((p, i) => ({
                            ...p,
                            _isFix: true,
                            _diffOpen: true,
                            _id: (i === 0) ? id : (id + "." + i)
                        }));
                        // Replace the 1 failed item with the N new items
                        cpCurrentBatch.splice(index, 1, ...newPatches);
                        updateCount += newPatches.length;
                    }
                }
            }
        }

        if (updateCount > 0) { 
            cpRunVerification(cpCurrentBatch); 
        } else { 
            window.openConfirm("Patcher", "No matching IDs found to update.", null, false, "OK", null);
        }
    } catch(e) {
        window.openConfirm("Update Error", "Update Error: " + e.message, null, false, "OK", null);
    }
};

window.cpToggleAccessibility = function(enabled) {
    localStorage.setItem("cjos_cp_accessible", enabled);
    document.body.classList.toggle("cp-accessible-diffs", enabled);
};

let cpLongPressTimer = null;
window.cpStartLongPress = function(e, fileName) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    cpLongPressTimer = setTimeout(() => {
        window.sui.haptic('medium');
        const errorFiles = [...new Set(cpResults.filter(r => r.status === 'error').map(r => r.file))];
        const existsOnDisk = window.cpFilesOnDisk && window.cpFilesOnDisk[fileName] === true;
        
        const options = [];
        if (existsOnDisk) {
            options.push({ label: "📋 Copy Source Code", value: "copy" });
            options.push({ label: "🔗 Source + Dependencies", value: "export_deps" });
        }
        options.push({ label: "📍 Jump to Patch", value: "jump" });
        if (existsOnDisk) {
            options.push({ label: "🗑️ Delete File (Server)", value: "delete" });
        }
        if (errorFiles.length > 0) {
            options.push({ label: `⚠️ Copy All Error Files Source (${errorFiles.length})`, value: "copy_errors" });
        }
        window.openPicker(`File: ${fileName.split('/').pop()}`, options, null, (val) => {
            if (val === "copy") cpCopyFileSource(fileName);
            if (val === "export_deps") cpStageExportWithDeps(fileName);
            if (val === "jump") cpJumpToFile(fileName);
            if (val === "delete") cpDirectDeleteFile(fileName);
            if (val === "copy_errors") cpCopyAllErrorFilesSource();
        });
    }, 600);
};

window.cpEndLongPress = function() {
    clearTimeout(cpLongPressTimer);
};

async function cpCopyAllErrorFilesSource() {
    const errorFiles = [...new Set(cpResults.filter(r => r.status === 'error').map(r => r.file))];
    if (errorFiles.length === 0) {
        window.sui.toast("No files with errors found");
        return;
    }
    
    try {
        const fd = new FormData();
        fd.append("plugin_action", "cp_preview");
        fd.append("patch_count", errorFiles.length);
        errorFiles.forEach((file, i) => {
            fd.append(`p_${i}_file`, file);
            fd.append(`p_${i}_action`, "export");
            fd.append(`p_${i}_find`, "");
            fd.append(`p_${i}_replace`, "");
            fd.append(`p_${i}_match`, 1);
        });
        
        const res = await fetch(window.CP_API_ENDPOINT || "index.php", { method: "POST", body: fd });
        const data = await res.json();
        
        if (data && data.status === "success" && data.results) {
            const allSource = data.results
                .filter(r => r.export_block)
                .map(r => r.export_block)
                .join("\n");
            
            if (allSource.trim()) {
                await cpCopyToClipboard("~~~\n" + allSource.trim() + "\n\n~~~");
                window.sui.toast(`Source of ${errorFiles.length} error file(s) copied`);
            } else {
                window.sui.toast("Failed to gather source code");
            }
        }
    } catch(e) {
        console.error("Export of error files failed", e);
        window.sui.toast("Failed to fetch source of error files");
    }
}

window.cpDirectDeleteFile = function(fileName) {
    window.openConfirm("Delete File", `Are you sure you want to permanently delete ${fileName} from the server? This cannot be undone.`, async () => {
        try {
            const fd = new FormData();
            fd.append("plugin_action", "cp_direct_delete");
            fd.append("file", fileName);
            const res = await fetch(window.CP_API_ENDPOINT || "index.php", { method: "POST", body: fd });
            const data = await res.json();
            if (data.status === "success") {
                window.sui.toast("File Deleted");
                if (cpCurrentBatch.length > 0) {
                    cpRunVerification(cpCurrentBatch);
                }
            } else {
                window.openConfirm("Delete Error", data.message, null, false, "OK", null);
            }
        } catch(e) {
            window.sui.toast("Network Error");
        }
    }, true, "Delete", "Cancel");
};

async function cpCopyFileSource(fileName) {
    try {
        const data = await window.sui.api("cp_preview", {
            patch_count: 1,
            p_0_file: fileName,
            p_0_action: "export",
            p_0_find: "",
            p_0_replace: "",
            p_0_match: 1
        }, { toast: "Fetching source..." });

        if (data && data.results && data.results[0].export_block) {
            const block = data.results[0].export_block;
            cpCopyToClipboard(block);
            
            const t = document.getElementById("toast");
            if (t) {
                t.innerText = "Source Copied to Clipboard";
                t.classList.add("show");
                setTimeout(() => t.classList.remove("show"), 2000);
            }
        }
    } catch(e) { console.error("Export failed", e); }
}

window.cpAddDependencies = async function(idx) {
    const patch = cpCurrentBatch[idx];
    if (!patch || !patch.file) return;

    try {
        const data = await window.sui.api('cp_get_deps', { file: patch.file }, { toast: "Fetching dependencies..." });
        if (data && data.dependencies) {
            const manifest = data.manifest_files || [];
            let added = 0;
            data.dependencies.forEach((dep, i) => {
                // Check if already staged (as export, patch, or create)
                const exists = cpCurrentBatch.some(p => p.file === dep);
                // Check if already in the last context export (Foundation/Project)
                const inManifest = manifest.includes(dep);

                if (!exists && !inManifest) {
    cpCurrentBatch.push({
        _id: `dep_${idx}_${i}`,
        file: dep,
        action: 'file_export',
        find: '',
        replace: '',
        comment: `Dependency of ${patch.file}`
    });
    added++;
}
                });
                if (added > 0) {
cpRunVerification(cpCurrentBatch);
                } else {
window.openConfirm("Patcher", "All dependencies are already staged.", null, false, "OK", null);
                }
            }
        } catch(e) { console.error("Dependency fetch failed", e); }
    };

    window.cpStageExportWithDeps = async function(fileName) {
        let idx = cpCurrentBatch.findIndex(p => p.file === fileName);
        if (idx === -1) {
            cpCurrentBatch.push({
                _id: `export_${Date.now()}`,
                file: fileName,
                action: 'file_export',
                find: '',
                replace: '',
                comment: 'Manual export'
            });
            idx = cpCurrentBatch.length - 1;
        }await cpAddDependencies(idx);
};

window.cpCopyDeletionBlock = function(idx) {
    const res = cpResults[idx];
    if (!res || !res.delete_block) return;
    const wrapped = "~~~\n" + res.delete_block + "\n~~~";
    cpCopyToClipboard(wrapped).then(() => {
        window.sui.toast("Deletion Block Copied");
    }).catch(err => {
        console.error("Copy failed", err);
        window.sui.toast("Copy Failed");
    });
};



function escapeHtml(text) { return text ? text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\"/g, "&quot;").replace(/'/g, "&#039;") : ""; }

window.cpOpenStandaloneOptions = function() {
    const url = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/patcher.php');
    const options = [
        { label: "🚀 Open Standalone Patcher", value: "open" },
        { label: "🔗 Copy Standalone URL", value: "copy" }
    ];
    
    if (typeof window.openPicker === 'function') {
        window.openPicker("Emergency Mode", options, null, (val) => {
            if (val === "open") {
                window.open('patcher.php', '_blank');
            } else if (val === "copy") {
                cpCopyToClipboard(url).then(() => window.sui.toast("URL Copied"));
            }
        });
    } else {
        // Fallback if picker is unavailable
        if (confirm("Open Standalone Patcher in new tab?")) {
            window.open('patcher.php', '_blank');
        }
    }
};
JS;
?>