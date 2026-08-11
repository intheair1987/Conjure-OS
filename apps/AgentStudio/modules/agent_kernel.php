<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openrouter.php';

if (file_exists(__DIR__ . '/../../../app/paths.php')) {
    require_once __DIR__ . '/../../../app/paths.php';
}

function agent_execute_tool_blocks($content) {
    if (strpos($content, '#ACTION:') === false && strpos($content, '#PATCH_ID:') === false) {
        return null;
    }

    if (!file_exists(CJOS_PATH_PLUGINS . '/FilePatchManager.php')) {
        return null;
    }
    require_once CJOS_PATH_PLUGINS . '/FilePatchManager.php';

    $parsed = cp_parse_raw_input($content);
    if (empty($parsed['patches'])) {
        return null;
    }

    $tool_results = [];
    $root = CJOS_PATH_ROOT . DIRECTORY_SEPARATOR;
    $patches = $parsed['patches'];

    // 1. Process Code Auditor Blocks (#ACTION: audit)
    if (file_exists(CJOS_PATH_PLUGINS . '/CodeAuditor.php') && strpos($content, '#ACTION: audit') !== false) {
        require_once CJOS_PATH_PLUGINS . '/CodeAuditor.php';
        $res = ca_execute_audit_payload($content, '', true, true);
        if (isset($res['status']) && $res['status'] === 'success') {
            $formattedReport = ca_format_audit_report($res['results']);
            $tool_results[] = "SYSTEM TOOL RESPONSE: CODE AUDITOR RESULTS\n\n" . $formattedReport;
        } else {
            $tool_results[] = "SYSTEM TOOL RESPONSE: CODE AUDITOR FAILED\n\n" . ($res['message'] ?? 'Audit failed.');
        }
    }

    // 2. Check for Executable / Modifying Patches
    $hasExecPatches = false;
    foreach ($patches as $p) {
        $act = $p['action'] ?? '';
        if ($act !== 'file_export' && $act !== 'export' && $act !== 'audit') {
            $hasExecPatches = true;
            break;
        }
    }

    if ($hasExecPatches) {
        CPSandbox::reset();
        $temp_variables = [];
        $temp_file_buffers = [];
        $results = [];
        $hasError = false;

        // Preflight check with auto-healing
        $autoApplied = 0;
        foreach ($patches as $idx => &$patch) {
            $act = $patch['action'] ?? 'file_update';
            $relPath = $patch['file'] ?? '';
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

            // Indentation Auto-Healing
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

            if ($res['status'] === 'error') $hasError = true;
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
                $act = $patch['action'] ?? 'file_update';
                $relPath = $patch['file'] ?? '';
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
            $diagReport = cp_generate_error_report_php($errorResults, $patches);
            $tool_results[] = "SYSTEM TOOL RESPONSE: PATCH PREFLIGHT FAILED\n\n" . $diagReport;
        } else {
            // 100% Preflight Pass -> Atomic Commit
            $buffers = [];
            $variables = [];
            $variables_used = [];

            try {
                foreach ($patches as $p) {
                    $act = $p['action'];
                    if ($act === 'var_patch' || $act === 'var_refactor' || $act === 'logic_trace' || $act === 'file_export' || $act === 'edit_log') continue;
                    $files = [$p['file']];
                    if (!empty($p['dest_file'])) $files[] = $p['dest_file'];

                    foreach ($files as $f) {
                        if (!isset($buffers[$f])) {
                            $full = $root . $f;
                            $isCreationAction = ($act === 'file_create' || $act === 'download_icon' || $act === 'download_file');
                            $isMoveDest = ($act === 'file_move' && $f === $p['dest_file']);
                            $isCopyDest = ($act === 'file_copy' && $f === $p['dest_file']);

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
                    $prio = ['file_create' => 0, 'file_overwrite' => 0, 'code_cut' => 1, 'var_patch' => 2, 'var_refactor' => 2];
                    $pA = $prio[$a['action']] ?? 3;
                    $pB = $prio[$b['action']] ?? 3;
                    if ($pA === $pB) return $a['_original_idx'] <=> $b['_original_idx'];
                    return $pA <=> $pB;
                });

                foreach ($patches as $p) {
                    if ($p['action'] === 'logic_trace' || $p['action'] === 'file_export') continue;
                    $handler = "cp_commit_" . $p['action'];
                    if (function_exists($handler)) {
                        $handler($p, $buffers, $variables, $variables_used, false);
                    } else {
                        throw new Exception("Unknown action in commit: " . $p['action']);
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

                $filesList = implode(', ', array_unique($touchedFiles));
                $tool_results[] = "SYSTEM TOOL RESPONSE: COMMIT SUCCESSFUL\n\nAll " . count($patches) . " patch(es) passed preflight and were atomically committed to disk.\nUpdated Files: " . ($filesList ?: 'None');
            } catch (Exception $e) {
                $tool_results[] = "SYSTEM TOOL RESPONSE: COMMIT FAILED\n\n" . $e->getMessage();
            }
        }
    } else {
        // 3. Process File Exports if no modifying patches present
        $exportedBlocks = [];
        foreach ($patches as $p) {
            if ($p['action'] === 'file_export' || $p['action'] === 'export') {
                $cleanPath = ltrim(str_replace('\\', '/', $p['file']), '/');
                $full = $root . $cleanPath;
                if (file_exists($full) && !is_dir($full)) {
                    $exportedBlocks[] = "FILE: {$cleanPath}\n```\n" . file_get_contents($full) . "\n```";
                } else {
                    $exportedBlocks[] = "FILE: {$cleanPath}\nERROR: File not found on server.";
                }
            }
        }
        if (!empty($exportedBlocks)) {
            $tool_results[] = "SYSTEM TOOL RESPONSE: EXPORTED FILE CONTENTS\n\n" . implode("\n\n", $exportedBlocks);
        }
    }

    return !empty($tool_results) ? implode("\n\n---\n\n", $tool_results) : null;
}

function agent_run_turn($db, $thread_id, $user_prompt = null, $custom_max_iterations = null) {
    $settings_file = __DIR__ . '/../data/settings.json';
    $settings = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];
    
    $model = $settings['model'] ?? 'anthropic/claude-3.5-sonnet';
    $system_prompt = $settings['system_prompt'] ?? 'You are an autonomous AI software engineer in Conjure OS.';
    $max_iterations = ($custom_max_iterations !== null && (int)$custom_max_iterations > 0) 
        ? (int)$custom_max_iterations 
        : (int)($settings['max_iterations'] ?? 10);

    $context_mode = $settings['context_mode'] ?? null;
    if (empty($context_mode)) {
        $include_foundation = $settings['include_foundation_context'] ?? true;
        $context_mode = $include_foundation ? 'foundation' : 'none';
    }

    if ($context_mode !== 'none') {
        $context_prompt = agent_get_context_prompt($context_mode);
        if (!empty($context_prompt)) {
            $system_prompt = $context_prompt . "\n" . $system_prompt;
        }
    }

    if (!empty($user_prompt)) {
        $stmt = $db->prepare("INSERT INTO messages (thread_id, role, content) VALUES (?, 'user', ?)");
        $stmt->execute([$thread_id, $user_prompt]);
    }

    $iteration = 0;
    $finalResponse = null;

    while ($iteration < $max_iterations) {
        $iteration++;

        $stmt = $db->prepare("SELECT role, content FROM messages WHERE thread_id = ? ORDER BY id ASC");
        $stmt->execute([$thread_id]);
        $raw_msgs = $stmt->fetchAll();

        $formatted_msgs = [
            ['role' => 'system', 'content' => $system_prompt]
        ];

        foreach ($raw_msgs as $m) {
            $formatted_msgs[] = agent_format_message_for_llm($m['role'], $m['content']);
        }

        $api_res = agent_openrouter_complete($formatted_msgs, $model);
        if (!$api_res['success']) {
            $err_msg = "Error from OpenRouter: " . $api_res['error'];
            $stmt = $db->prepare("INSERT INTO messages (thread_id, role, content) VALUES (?, 'assistant', ?)");
            $stmt->execute([$thread_id, $err_msg]);
            return ['success' => false, 'error' => $err_msg];
        }

        $ai_content = $api_res['content'];

        $stmt = $db->prepare("INSERT INTO messages (thread_id, role, content) VALUES (?, 'assistant', ?)");
        $stmt->execute([$thread_id, $ai_content]);

        // Check for tool execution
        $tool_output = agent_execute_tool_blocks($ai_content);

        if ($tool_output !== null) {
            $currentTurn = $iteration;
            $remainingTurns = max(0, $max_iterations - $currentTurn);
            $turnFooter = "\n\n⏱️ Turn {$currentTurn} Complete | {$remainingTurns} Turn(s) Remaining";
            $fullToolOutput = $tool_output . $turnFooter;

            $stmt = $db->prepare("INSERT INTO messages (thread_id, role, content) VALUES (?, 'user', ?)");
            $stmt->execute([$thread_id, $fullToolOutput]);
        } else {
            $finalResponse = $ai_content;
            break;
        }
    }

    $stmt = $db->prepare("UPDATE threads SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$thread_id]);

    return ['success' => true, 'iterations' => $iteration, 'final_response' => $finalResponse];
}

function agent_get_context_prompt($tier = 'foundation') {
    if ($tier === 'none') return '';
    $root = defined('CJOS_PATH_ROOT') ? CJOS_PATH_ROOT : realpath(__DIR__ . '/../../..');
    if (file_exists(CJOS_PATH_PLUGINS . '/ContextExporter.php')) {
        require_once CJOS_PATH_PLUGINS . '/ContextExporter.php';
        return ce_generate_context_text($tier, $root);
    }

    $prompt = "=== CONJURE FOUNDATION CONTEXT ===\n";
    $confFile = $root . '/app/data/foundation-config.json';
    $files = file_exists($confFile) ? json_decode(file_get_contents($confFile), true) : [];
    foreach ($files as $f) {
        $full = $root . '/' . $f;
        if (file_exists($full) && !is_dir($full)) {
            $prompt .= "FILE: $f\n" . file_get_contents($full) . "\n\n";
        }
    }
    return $prompt;
}

function agent_get_foundation_prompt($tier = 'foundation') {
    return agent_get_context_prompt($tier);
}

function agent_get_context_files($tier = 'foundation') {
    if ($tier === 'none') return [];
    $root = defined('CJOS_PATH_ROOT') ? CJOS_PATH_ROOT : realpath(__DIR__ . '/../../..');
    if (file_exists(CJOS_PATH_PLUGINS . '/ContextExporter.php')) {
        require_once CJOS_PATH_PLUGINS . '/ContextExporter.php';
        $fileList = ce_get_context_file_list($tier, $root);
        $result = [];
        foreach ($fileList as $f) {
            $full = $root . '/' . $f;
            $size = file_exists($full) ? round(filesize($full) / 1024, 1) . ' KB' : '0 KB';
            $result[] = [
                'path' => $f,
                'name' => basename($f),
                'size' => $size
            ];
        }
        return $result;
    }

    $confFile = $root . '/app/data/foundation-config.json';
    $files = file_exists($confFile) ? json_decode(file_get_contents($confFile), true) : [];
    $result = [];
    foreach ($files as $f) {
        $full = $root . '/' . $f;
        $size = file_exists($full) ? round(filesize($full) / 1024, 1) . ' KB' : '0 KB';
        $result[] = [
            'path' => $f,
            'name' => basename($f),
            'size' => $size
        ];
    }
    return $result;
}

function agent_get_foundation_files($tier = 'foundation') {
    return agent_get_context_files($tier);
}

function agent_format_message_for_llm($role, $content) {
    if ($role !== 'user' || empty($content)) {
        return ['role' => $role, 'content' => $content];
    }

    $pattern = '/!\[(.*?)\]\((data:image\/[^)]+)\)/i';
    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
        $extractedImages = [];
        $cleanText = $content;

        foreach ($matches as $match) {
            $fullMarkdown = $match[0];
            $altName = $match[1];
            $dataUrl = trim($match[2]);

            $extractedImages[] = [
                'name' => $altName,
                'url' => $dataUrl
            ];

            $cleanText = str_replace($fullMarkdown, "[Attached Image: " . ($altName ?: 'Image') . "]", $cleanText);
        }

        $cleanText = preg_replace('/<!-- ATTACHMENTS_META:.*? -->/s', '', $cleanText);
        $cleanText = trim($cleanText);

        $contentArray = [];
        if (!empty($cleanText)) {
            $contentArray[] = [
                'type' => 'text',
                'text' => $cleanText
            ];
        }

        foreach ($extractedImages as $img) {
            $contentArray[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $img['url']
                ]
            ];
        }

        return [
            'role' => 'user',
            'content' => $contentArray
        ];
    }

    return ['role' => $role, 'content' => $content];
}

function agent_run_turn_stream($db, $thread_id, $user_prompt = null, $custom_max_iterations = null) {
    $settings_file = __DIR__ . '/../data/settings.json';
    $settings = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];
    
    $model = $settings['model'] ?? 'anthropic/claude-3.5-sonnet';
    $base_system_prompt = $settings['system_prompt'] ?? 'You are an autonomous AI software engineer in Conjure OS.';
    $max_iterations = ($custom_max_iterations !== null && (int)$custom_max_iterations > 0) 
        ? (int)$custom_max_iterations 
        : (int)($settings['max_iterations'] ?? 10);

    $context_mode = $settings['context_mode'] ?? null;
    if (empty($context_mode)) {
        $include_foundation = $settings['include_foundation_context'] ?? true;
        $context_mode = $include_foundation ? 'foundation' : 'none';
    }

    if ($context_mode !== 'none') {
        $context_prompt = agent_get_context_prompt($context_mode);
        if (!empty($context_prompt)) {
            $system_prompt = $context_prompt . "\n" . $base_system_prompt;
        } else {
            $system_prompt = $base_system_prompt;
        }
    } else {
        $system_prompt = $base_system_prompt;
    }

    if (!empty($user_prompt)) {
        $stmt = $db->prepare("INSERT INTO messages (thread_id, role, content) VALUES (?, 'user', ?)");
        $stmt->execute([$thread_id, $user_prompt]);
    }

    $iteration = 0;

    while ($iteration < $max_iterations) {
        $iteration++;

        $stmt = $db->prepare("SELECT role, content FROM messages WHERE thread_id = ? ORDER BY id ASC");
        $stmt->execute([$thread_id]);
        $raw_msgs = $stmt->fetchAll();

        $formatted_msgs = [
            ['role' => 'system', 'content' => $system_prompt]
        ];

        foreach ($raw_msgs as $m) {
            $formatted_msgs[] = agent_format_message_for_llm($m['role'], $m['content']);
        }

        echo "event: thinking\ndata: " . json_encode(['status' => 'Agent is thinking']) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();

        $api_res = agent_openrouter_stream($formatted_msgs, $model, 0.2, function($chunk, $full) {
            echo "event: chunk\ndata: " . json_encode(['chunk' => $chunk]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        });

        if (!$api_res['success']) {
            $err_msg = "Error from OpenRouter: " . $api_res['error'];
            $stmt = $db->prepare("INSERT INTO messages (thread_id, role, content) VALUES (?, 'assistant', ?)");
            $stmt->execute([$thread_id, $err_msg]);
            echo "event: error\ndata: " . json_encode(['error' => $err_msg]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
            return;
        }

        $ai_content = $api_res['content'];
        $stmt = $db->prepare("INSERT INTO messages (thread_id, role, content) VALUES (?, 'assistant', ?)");
        $stmt->execute([$thread_id, $ai_content]);

        // Tool execution check
        $tool_output = agent_execute_tool_blocks($ai_content);

        if ($tool_output !== null) {
            $currentTurn = $iteration;
            $remainingTurns = max(0, $max_iterations - $currentTurn);
            $turnFooter = "\n\n⏱️ Turn {$currentTurn} Complete | {$remainingTurns} Turn(s) Remaining";
            $fullToolOutput = $tool_output . $turnFooter;

            $stmt = $db->prepare("INSERT INTO messages (thread_id, role, content) VALUES (?, 'user', ?)");
            $stmt->execute([$thread_id, $fullToolOutput]);

            echo "event: tool_result\ndata: " . json_encode(['output' => $fullToolOutput, 'turn' => $currentTurn, 'remaining' => $remainingTurns]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        } else {
            break;
        }
    }

    $stmt = $db->prepare("UPDATE threads SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$thread_id]);

    echo "event: done\ndata: " . json_encode(['status' => 'complete']) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}
?>