<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openrouter.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_threads':
        $stmt = $db->query("SELECT * FROM threads ORDER BY updated_at DESC");
        echo json_encode(['success' => true, 'threads' => $stmt->fetchAll()]);
        break;

    case 'create_thread':
        $title = trim($_POST['title'] ?? '');
        if (empty($title) || strtolower($title) === 'new mission') {
            $stmt = $db->query("SELECT title FROM threads WHERE title LIKE 'New Mission%'");
            $existing = array_map(function($r) { return strtolower(trim($r['title'])); }, $stmt->fetchAll());
            
            if (!in_array('new mission', $existing)) {
                $title = 'New Mission';
            } else {
                $counter = 1;
                while (in_array(strtolower("New Mission $counter"), $existing)) {
                    $counter++;
                }
                $title = "New Mission $counter";
            }
        }

        $stmt = $db->prepare("INSERT INTO threads (title) VALUES (?)");
        $stmt->execute([$title]);
        $id = $db->lastInsertId();
        echo json_encode(['success' => true, 'thread_id' => $id, 'title' => $title]);
        break;

    case 'delete_thread':
        $id = (int)($_POST['thread_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM threads WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    case 'get_messages':
        $thread_id = (int)($_GET['thread_id'] ?? $_POST['thread_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE thread_id = ? ORDER BY id ASC");
        $stmt->execute([$thread_id]);
        echo json_encode(['success' => true, 'messages' => $stmt->fetchAll()]);
        break;

    case 'edit_message':
        $msg_id = (int)($_POST['message_id'] ?? 0);
        $new_content = trim($_POST['content'] ?? '');
        
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$msg_id]);
        $msg = $stmt->fetch();
        
        if (!$msg) {
            echo json_encode(['success' => false, 'error' => 'Message not found']);
            exit;
        }

        $thread_id = $msg['thread_id'];
        
        // Delete all messages in the thread following this message ID
        $stmt = $db->prepare("DELETE FROM messages WHERE thread_id = ? AND id > ?");
        $stmt->execute([$thread_id, $msg_id]);

        // Update the target message content
        $stmt = $db->prepare("UPDATE messages SET content = ? WHERE id = ?");
        $stmt->execute([$new_content, $msg_id]);

        // Re-run the turn from this updated prompt
        require_once __DIR__ . '/agent_kernel.php';
        $result = agent_run_turn($db, $thread_id);
        echo json_encode($result);
        break;

    case 'branch_thread':
        $msg_id = (int)($_POST['message_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$msg_id]);
        $msg = $stmt->fetch();

        if (!$msg) {
            echo json_encode(['success' => false, 'error' => 'Target message not found']);
            exit;
        }

        $source_thread_id = $msg['thread_id'];
        $stmt = $db->prepare("SELECT title FROM threads WHERE id = ?");
        $stmt->execute([$source_thread_id]);
        $source = $stmt->fetch();
        $source_title = $source['title'] ?? 'Mission';

        // Create new branched thread
        $new_title = "Branch: " . $source_title;
        $stmt = $db->prepare("INSERT INTO threads (title) VALUES (?)");
        $stmt->execute([$new_title]);
        $new_thread_id = $db->lastInsertId();

        // Copy all messages up to and including the target message ID
        $stmt = $db->prepare("
            INSERT INTO messages (thread_id, role, content, tool_calls, created_at)
            SELECT ?, role, content, tool_calls, created_at 
            FROM messages 
            WHERE thread_id = ? AND id <= ? 
            ORDER BY id ASC
        ");
        $stmt->execute([$new_thread_id, $source_thread_id, $msg_id]);

        echo json_encode([
            'success' => true, 
            'new_thread_id' => $new_thread_id, 
            'title' => $new_title
        ]);
        break;

    case 'delete_message':
        $msg_id = (int)($_POST['message_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM messages WHERE id = ?");
        $stmt->execute([$msg_id]);
        echo json_encode(['success' => true]);
        break;

    case 'manual_execute_patch':
        $raw_input = $_POST['raw_input'] ?? ($_POST['payload'] ?? '');

        if (empty(trim($raw_input))) {
            echo json_encode([
                'status' => 'error',
                'message' => 'No patch payload received.'
            ]);
            exit;
        }

        /*
         * Delegate directly to FilePatchManager's existing headless SSOT
         * execution handler. This route intentionally does not touch the
         * Agent Studio messages or threads tables.
         */
        require_once __DIR__ . '/../../../app/paths.php';
        $_POST['plugin_action'] = 'cp_agent_execute';
        $_POST['raw_input'] = $raw_input;
        require_once CJOS_PATH_PLUGINS . '/FilePatchManager.php';
        exit;

    case 'send_message':
        $thread_id = (int)($_POST['thread_id'] ?? $_GET['thread_id'] ?? 0);
        $content = trim($_POST['content'] ?? $_GET['content'] ?? '');
        $max_iter = (int)($_POST['max_iterations'] ?? $_GET['max_iterations'] ?? 0);
        if (!$thread_id || empty($content)) {
            echo json_encode(['success' => false, 'error' => 'Missing thread_id or message content']);
            exit;
        }

        require_once __DIR__ . '/agent_kernel.php';
        $result = agent_run_turn($db, $thread_id, $content, $max_iter);
        echo json_encode($result);
        break;

    case 'stream_message':
        @set_time_limit(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $thread_id = (int)($_POST['thread_id'] ?? $_GET['thread_id'] ?? 0);
        $content = trim($_POST['content'] ?? $_GET['content'] ?? '');
        $max_iter = (int)($_POST['max_iterations'] ?? $_GET['max_iterations'] ?? 0);

        require_once __DIR__ . '/agent_kernel.php';
        agent_run_turn_stream($db, $thread_id, $content, $max_iter);
        exit;

    case 'stream_edit_message':
        @set_time_limit(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $msg_id = (int)($_POST['message_id'] ?? $_GET['message_id'] ?? 0);
        $new_content = trim($_POST['content'] ?? $_GET['content'] ?? '');
        $max_iter = (int)($_POST['max_iterations'] ?? $_GET['max_iterations'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$msg_id]);
        $msg = $stmt->fetch();

        if (!$msg) {
            echo "event: error\ndata: " . json_encode(['error' => 'Message not found']) . "\n\n";
            exit;
        }

        $thread_id = $msg['thread_id'];

        $stmt = $db->prepare("DELETE FROM messages WHERE thread_id = ? AND id > ?");
        $stmt->execute([$thread_id, $msg_id]);

        $stmt = $db->prepare("UPDATE messages SET content = ? WHERE id = ?");
        $stmt->execute([$new_content, $msg_id]);

        require_once __DIR__ . '/agent_kernel.php';
        agent_run_turn_stream($db, $thread_id, null, $max_iter);
        exit;

    case 'get_models':
        $cache_file = __DIR__ . '/../data/models_cache.json';
        $models = [];
        
        // Cache for 6 hours to keep UI snappy
        if (file_exists($cache_file) && (time() - filemtime($cache_file) < 21600)) {
            $models = json_decode(file_get_contents($cache_file), true);
        } else {
            $ch = curl_init('https://openrouter.ai/api/v1/models');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            
            if ($res) {
                $json = json_decode($res, true);
                if (isset($json['data']) && is_array($json['data'])) {
                    foreach ($json['data'] as $m) {
                        $p_prompt = (float)($m['pricing']['prompt'] ?? 0) * 1000000;
                        $p_comp = (float)($m['pricing']['completion'] ?? 0) * 1000000;
                        $is_free = ($p_prompt == 0 && $p_comp == 0) || (strpos($m['id'], ':free') !== false);

                        $models[] = [
                            'id' => $m['id'],
                            'name' => $m['name'] ?? $m['id'],
                            'context_length' => $m['context_length'] ?? 0,
                            'prompt_price' => $p_prompt,
                            'completion_price' => $p_comp,
                            'is_free' => $is_free
                        ];
                    }
                    file_put_contents($cache_file, json_encode($models, JSON_PRETTY_PRINT));
                }
            }
        }

        $settings_file = __DIR__ . '/../data/settings.json';
        $settings = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];
        $starred = $settings['starred_models'] ?? [];

        echo json_encode(['success' => true, 'models' => $models, 'starred_models' => $starred]);
        break;

    case 'toggle_star_model':
        $settings_file = __DIR__ . '/../data/settings.json';
        $settings = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];
        $model_id = trim($_POST['model_id'] ?? '');
        if (!$model_id) {
            echo json_encode(['success' => false, 'error' => 'Missing model_id']);
            exit;
        }

        $starred = $settings['starred_models'] ?? [];
        if (!is_array($starred)) $starred = [];

        if (in_array($model_id, $starred)) {
            $starred = array_values(array_filter($starred, function($m) use ($model_id) { return $m !== $model_id; }));
        } else {
            $starred[] = $model_id;
        }

        $settings['starred_models'] = $starred;
        file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'starred_models' => $starred]);
        break;

    case 'get_credits':
        $credits = agent_get_openrouter_credits();
        echo json_encode($credits);
        break;

    case 'get_settings':
        require_once __DIR__ . '/agent_kernel.php';
        $settings_file = __DIR__ . '/../data/settings.json';
        $settings = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];
        $api_key = agent_get_openrouter_key() ?: '';
        $has_key = !empty($api_key);

        $context_mode = $settings['context_mode'] ?? null;
        if (empty($context_mode)) {
            $context_mode = ($settings['include_foundation_context'] ?? true) ? 'foundation' : 'none';
        }

        $context_files = agent_get_context_files($context_mode);
        echo json_encode([
            'success' => true, 
            'settings' => $settings, 
            'api_key' => $api_key,
            'has_key' => $has_key,
            'context_mode' => $context_mode,
            'context_files' => $context_files,
            'foundation_files' => $context_files,
            'starred_models' => $settings['starred_models'] ?? []
        ]);
        break;

    case 'get_context_files':
        require_once __DIR__ . '/agent_kernel.php';
        $tier = trim($_GET['tier'] ?? $_POST['tier'] ?? 'foundation');
        if (!in_array($tier, ['none', 'foundation', 'project'])) {
            $tier = 'foundation';
        }
        $files = agent_get_context_files($tier);
        echo json_encode(['success' => true, 'tier' => $tier, 'files' => $files]);
        break;

    case 'save_settings':
        $settings_file = __DIR__ . '/../data/settings.json';
        $secrets_file = __DIR__ . '/../data/secrets-private.json';

        $existing = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];

        $model = trim($_POST['model'] ?? 'anthropic/claude-3.5-sonnet');
        $prompt = trim($_POST['system_prompt'] ?? '');
        $auto_commit = (($_POST['auto_commit'] ?? 'true') === 'true');
        $max_iter = (int)($_POST['max_iterations'] ?? 10);
        $api_key = trim($_POST['openrouter_api_key'] ?? '');

        $context_mode = trim($_POST['context_mode'] ?? 'foundation');
        if (!in_array($context_mode, ['none', 'foundation', 'project'])) {
            $context_mode = 'foundation';
        }

        $settings = [
            'model' => $model,
            'system_prompt' => $prompt,
            'auto_commit' => $auto_commit,
            'max_iterations' => $max_iter,
            'context_mode' => $context_mode,
            'include_foundation_context' => ($context_mode !== 'none'),
            'starred_models' => $existing['starred_models'] ?? []
        ];
        file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));

        if (!empty($api_key)) {
            $secrets = file_exists($secrets_file) ? json_decode(file_get_contents($secrets_file), true) : [];
            $secrets['openrouter_api_key'] = $api_key;
            file_put_contents($secrets_file, json_encode($secrets, JSON_PRETTY_PRINT));
        }

        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown API action: ' . $action]);
        break;
}
exit;
?>