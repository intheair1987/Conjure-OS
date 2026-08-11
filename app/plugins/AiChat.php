<?php
// ==============================================================================
// PLUGIN: AiChat Studio
// DESCRIPTION: Private AI Chat interface with cost tracking and branching.
// ==============================================================================

$aichat_data_dir = CJOS_PATH_DATA . DIRECTORY_SEPARATOR . 'ai-chat';
$aichat_db_file = $aichat_data_dir . DIRECTORY_SEPARATOR . 'chats-private.db';

// --- DATABASE INITIALIZATION ---
function aichat_get_db() {
    global $aichat_data_dir, $aichat_db_file;
    if (!is_dir($aichat_data_dir)) mkdir($aichat_data_dir, 0777, true);
    
    try {
        $db = new PDO("sqlite:$aichat_db_file");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Folders
        $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_folders (
            id TEXT PRIMARY KEY,
            name TEXT,
            icon TEXT,
            color TEXT,
            sort_order INTEGER
        )");
        
        // Threads
        $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_threads (
            id TEXT PRIMARY KEY,
            folder_id TEXT,
            title TEXT,
            model TEXT,
            temperature REAL,
            system_prompt TEXT,
            total_cost REAL DEFAULT 0,
            created_at INTEGER
        )");
        
        // Messages
        $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_messages (
            id TEXT PRIMARY KEY,
            thread_id TEXT,
            parent_id TEXT,
            role TEXT,
            content TEXT,
            cost_usd REAL DEFAULT 0,
            timestamp INTEGER
        )");

        // Reusable Prompts Gallery
        $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_prompts (
            id TEXT PRIMARY KEY,
            title TEXT,
            content TEXT,
            icon TEXT,
            created_at INTEGER
        )");

        // Default Inbox Folder
        $inboxExists = $db->query("SELECT COUNT(*) FROM ai_chat_folders WHERE id = 'f_inbox'")->fetchColumn();
        if (!$inboxExists) {
            $db->prepare("INSERT INTO ai_chat_folders (id, name, icon, color, sort_order) VALUES (?, ?, ?, ?, ?)")
               ->execute(['f_inbox', 'Inbox', '📥', 'var(--primary)', -100]);
        }

        // Seed default prompts if empty
        $count = $db->query("SELECT COUNT(*) FROM ai_chat_prompts")->fetchColumn();
        if ($count == 0) {
            $defaults = [
                ['p_arch', 'Code Architect', 'You are an expert software architect. Focus on clean code, design patterns, and scalability.', '🏗️'],
                ['p_writ', 'Creative Writer', 'You are a professional editor and creative writer. Help me refine my prose and brainstorm ideas.', '✍️'],
                ['p_logi', 'Logic Tutor', 'You are a Socratic tutor. Don\'t give answers directly; guide me through the reasoning process.', '🧠']
            ];
            foreach ($defaults as $d) {
                $db->prepare("INSERT INTO ai_chat_prompts (id, title, content, icon, created_at) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$d[0], $d[1], $d[2], $d[3], time()]);
            }
        }

        // Migration: Add model and cache columns if missing
        try {
            $cols = $db->query("PRAGMA table_info(ai_chat_messages)")->fetchAll(PDO::FETCH_ASSOC);
            $hasModel = false; $hasCache = false;
            foreach ($cols as $c) { 
                if ($c['name'] === 'model') $hasModel = true; 
                if ($c['name'] === 'cached_tokens') $hasCache = true;
            }
            if (!$hasModel) $db->exec("ALTER TABLE ai_chat_messages ADD COLUMN model TEXT DEFAULT NULL");
            if (!$hasCache) $db->exec("ALTER TABLE ai_chat_messages ADD COLUMN cached_tokens INTEGER DEFAULT 0");
            
            // Add token breakdown columns
            $hasPromptT = false; $hasCompT = false;
            foreach ($cols as $c) { 
                if ($c['name'] === 'prompt_tokens') $hasPromptT = true; 
                if ($c['name'] === 'completion_tokens') $hasCompT = true;
            }
            if (!$hasPromptT) $db->exec("ALTER TABLE ai_chat_messages ADD COLUMN prompt_tokens INTEGER DEFAULT 0");
            if (!$hasCompT) $db->exec("ALTER TABLE ai_chat_messages ADD COLUMN completion_tokens INTEGER DEFAULT 0");

            $hasReasoning = false;
            foreach ($cols as $c) { if ($c['name'] === 'reasoning') $hasReasoning = true; }
            if (!$hasReasoning) $db->exec("ALTER TABLE ai_chat_messages ADD COLUMN reasoning TEXT DEFAULT NULL");

            $hasRaw = false;
            foreach ($cols as $c) { if ($c['name'] === 'raw_response') $hasRaw = true; }
            if (!$hasRaw) $db->exec("ALTER TABLE ai_chat_messages ADD COLUMN raw_response TEXT DEFAULT NULL");

            // Update Threads table
            $colsT = $db->query("PRAGMA table_info(ai_chat_threads)")->fetchAll(PDO::FETCH_ASSOC);
            $hasTotalT = false;
            foreach ($colsT as $c) { if ($c['name'] === 'total_tokens') $hasTotalT = true; }
            if (!$hasTotalT) $db->exec("ALTER TABLE ai_chat_threads ADD COLUMN total_tokens INTEGER DEFAULT 0");
            
            $hasPinned = false;
            foreach ($colsT as $c) { if ($c['name'] === 'is_pinned') $hasPinned = true; }
            if (!$hasPinned) $db->exec("ALTER TABLE ai_chat_threads ADD COLUMN is_pinned INTEGER DEFAULT 0");
            
            $hasThinking = false;
            foreach ($colsT as $c) { if ($c['name'] === 'thinking_tokens') $hasThinking = true; }
            if (!$hasThinking) $db->exec("ALTER TABLE ai_chat_threads ADD COLUMN thinking_tokens INTEGER DEFAULT 0");

            // Cost Logging Table (Accounting)
            $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_cost_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id TEXT,
                message_id TEXT,
                model TEXT,
                cost_usd REAL,
                timestamp INTEGER
            )");

            // Migration: If log is empty, populate from existing messages
            $logCount = $db->query("SELECT COUNT(*) FROM ai_chat_cost_log")->fetchColumn();
            if ($logCount == 0) {
                $db->exec("INSERT INTO ai_chat_cost_log (thread_id, message_id, model, cost_usd, timestamp) 
                           SELECT thread_id, id, model, cost_usd, timestamp FROM ai_chat_messages WHERE cost_usd > 0");
                
                // Handle existing discrepancies (like the one the user reported)
                $threads = $db->query("SELECT id, total_cost FROM ai_chat_threads WHERE total_cost > 0")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($threads as $t) {
                    $sum = $db->query("SELECT SUM(cost_usd) FROM ai_chat_cost_log WHERE thread_id = '{$t['id']}'")->fetchColumn() ?: 0;
                    $diff = $t['total_cost'] - $sum;
                    if ($diff > 0.000001) {
                        $db->prepare("INSERT INTO ai_chat_cost_log (thread_id, message_id, model, cost_usd, timestamp) VALUES (?, 'legacy_adj', 'Rewound/Deleted Context', ?, ?)")
                           ->execute([$t['id'], $diff, time()]);
                    }
                }
            }

            $hasCtxMode = false;
            foreach ($colsT as $c) { if ($c['name'] === 'context_mode') $hasCtxMode = true; }
            if (!$hasCtxMode) {
                $db->exec("ALTER TABLE ai_chat_threads ADD COLUMN context_mode TEXT DEFAULT 'none'");
                $db->exec("ALTER TABLE ai_chat_threads ADD COLUMN context_folder_id TEXT DEFAULT NULL");
            }
        } catch (Exception $e) {}
        
        return $db;
    } catch (PDOException $e) {
        return null;
    }
}

// --- BACKEND API ---
if (isset($_POST['plugin_action'])) {
    if (strpos($_POST['plugin_action'], 'aichat_') === 0) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $db = aichat_get_db();
        if (!$db) { echo json_encode(['status' => 'error', 'message' => 'DB Connection Failed']); exit; }

        $action = $_POST['plugin_action'];

        if ($action === 'aichat_get_state') {
            $folders = $db->query("SELECT * FROM ai_chat_folders ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
            $threads = $db->query("SELECT * FROM ai_chat_threads ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            
            // 1. Defaults (Hardcoded/Read-Only)
            $defaults = [
                ['id' => 'p_arch', 'title' => 'Code Architect', 'content' => 'You are an expert software architect. Focus on clean code, design patterns, and scalability.', 'icon' => '🏗️', 'is_default' => true, 'model' => 'google/gemini-2.0-flash-exp:free', 'temperature' => 0.3],
                ['id' => 'p_writ', 'title' => 'Creative Writer', 'content' => 'You are a professional editor and creative writer. Help me refine my prose and brainstorm ideas.', 'icon' => '✍️', 'is_default' => true, 'model' => 'google/gemini-2.0-flash-exp:free', 'temperature' => 0.8],
                ['id' => 'p_logi', 'title' => 'Logic Tutor', 'content' => 'You are a Socratic tutor. Don\'t give answers directly; guide me through the reasoning process.', 'icon' => '🧠', 'is_default' => true, 'model' => 'google/gemini-2.0-flash-exp:free', 'temperature' => 0.5]
            ];

            // 2. Custom (Private JSON)
            $customFile = $aichat_data_dir . '/custom-prompts-private.json';
            $customs = file_exists($customFile) ? json_decode(file_get_contents($customFile), true) : [];
            
            // 3. Settings (includes disabled defaults)
            $settingsFile = $aichat_data_dir . '/settings-private.json';
            $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : ['recent_limit' => 5, 'disabled_defaults' => []];
            if (!isset($settings['disabled_defaults'])) $settings['disabled_defaults'] = [];

            echo json_encode([
                'status' => 'success', 
                'folders' => $folders, 
                'threads' => $threads, 
                'prompts' => array_merge($defaults, $customs), 
                'settings' => $settings
            ]);
            exit;
        }

        if ($action === 'aichat_save_prompt') {
            $customFile = $aichat_data_dir . '/custom-prompts-private.json';
            $customs = file_exists($customFile) ? json_decode(file_get_contents($customFile), true) : [];
            
            $id = $_POST['id'] ?: uniqid('cp_');
            $newPrompt = [
                'id' => $id,
                'title' => $_POST['title'],
                'content' => $_POST['content'],
                'icon' => $_POST['icon'] ?: '💬',
                'model' => $_POST['model'] ?: 'google/gemini-2.0-flash-exp:free',
                'temperature' => (float)($_POST['temperature'] ?? 0.7),
                'created_at' => time(),
                'is_default' => false
            ];

            $found = false;
            foreach ($customs as &$p) { if ($p['id'] === $id) { $p = $newPrompt; $found = true; break; } }
            if (!$found) $customs[] = $newPrompt;

            file_put_contents($customFile, json_encode($customs, JSON_PRETTY_PRINT));
            echo json_encode(['status' => 'success', 'id' => $id]);
            exit;
        }

        if ($action === 'aichat_delete_prompt') {
            $customFile = $aichat_data_dir . '/custom-prompts-private.json';
            $customs = file_exists($customFile) ? json_decode(file_get_contents($customFile), true) : [];
            $customs = array_values(array_filter($customs, function($p) { return $p['id'] !== $_POST['id']; }));
            file_put_contents($customFile, json_encode($customs, JSON_PRETTY_PRINT));
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_save_settings') {
            $settingsFile = $aichat_data_dir . '/settings-private.json';
            file_put_contents($settingsFile, $_POST['settings']);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_get_billing') {
            $threadId = $_POST['thread_id'];
            
            // 1. Get OpenRouter Credits
            $keyFile = CJOS_PATH_DATA . '/openrouter-private.json';
            $apiKey = file_exists($keyFile) ? json_decode(file_get_contents($keyFile), true)['api_key'] : '';
            
            $credits = ['total_credits' => 0, 'total_usage' => 0];
            if ($apiKey) {
                $ch = curl_init("https://openrouter.ai/api/v1/credits");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey"]);
                $res = curl_exec($ch);
                $cData = json_decode($res, true);
                if (isset($cData['data'])) $credits = $cData['data'];
                curl_close($ch);
            }

            // 2. Get Accounting Log
            $stmt = $db->prepare("SELECT message_id, model, cost_usd, timestamp FROM ai_chat_cost_log WHERE thread_id = ? ORDER BY timestamp ASC");
            $stmt->execute([$threadId]);
            $breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Get list of currently active message IDs to detect "Ghost" costs
            $activeIds = $db->query("SELECT id FROM ai_chat_messages WHERE thread_id = '$threadId'")->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode([
                'status' => 'success', 
                'credits' => $credits, 
                'breakdown' => $breakdown,
                'active_ids' => $activeIds
            ]);
            exit;
        }

        if ($action === 'aichat_save_folder') {
            $id = $_POST['id'] ?? uniqid('f_');
            $stmt = $db->prepare("INSERT OR REPLACE INTO ai_chat_folders (id, name, icon, color, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $_POST['name'], $_POST['icon'] ?? '💬', $_POST['color'] ?? 'var(--primary)', $_POST['sort_order'] ?? 0]);
            echo json_encode(['status' => 'success', 'id' => $id]);
            exit;
        }

        if ($action === 'aichat_delete_folder') {
            $id = $_POST['id'];
            $db->prepare("DELETE FROM ai_chat_folders WHERE id = ?")->execute([$id]);
            $db->prepare("DELETE FROM ai_chat_threads WHERE folder_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM ai_chat_messages WHERE thread_id IN (SELECT id FROM ai_chat_threads WHERE folder_id = ?)")->execute([$id]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_create_thread') {
            $id = uniqid('t_');
            $stmt = $db->prepare("INSERT INTO ai_chat_threads (id, folder_id, title, model, temperature, system_prompt, thinking_tokens, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $id, 
                $_POST['folder_id'], 
                $_POST['title'] ?: date('Y-m-d H:i:s'), 
                $_POST['model'] ?: 'google/gemini-2.0-flash-exp:free', 
                (float)($_POST['temperature'] ?? 0.7),
                $_POST['system_prompt'] ?: 'You are a helpful assistant.',
                (int)($_POST['thinking_tokens'] ?? 0),
                time()
            ]);
            echo json_encode(['status' => 'success', 'id' => $id]);
            exit;
        }

        if ($action === 'aichat_send_message') {
            // Disable all buffering for streaming
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // For Nginx

            $threadId = $_POST['thread_id'];
            $content = $_POST['content'];
            
            // 2. Get Thread Config & Context
            $stmtThread = $db->prepare("SELECT * FROM ai_chat_threads WHERE id = ?");
            $stmtThread->execute([$threadId]);
            $thread = $stmtThread->fetch(PDO::FETCH_ASSOC);

            // --- EXTERNAL CONTEXT GATHERING ---
            $externalContext = "";
            $ctxMode = $thread['context_mode'] ?? 'none';

            if ($ctxMode === 'folder' && !empty($thread['context_folder_id'])) {
                $mainDbFile = CJOS_PATH_ROOT . '/conjure.db';
                // Demo Mode Check
                $demoStateFile = CJOS_PATH_DATA . '/demo-mode.json';
                if (file_exists($demoStateFile)) {
                    $dm = json_decode(file_get_contents($demoStateFile), true);
                    if (!empty($dm['enabled'])) $mainDbFile = CJOS_PATH_DATA . '/demo/demo.db';
                }

                if (file_exists($mainDbFile)) {
                    try {
                        $mainDb = new PDO("sqlite:$mainDbFile");
                        $stmtCtx = $mainDb->prepare("SELECT l.date_display, l.transcription FROM logs l JOIN folder_map fm ON l.id = fm.log_id WHERE fm.folder_id = ? ORDER BY l.timestamp ASC");
                        $stmtCtx->execute([$thread['context_folder_id']]);
                        $notes = $stmtCtx->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($notes)) {
                            $externalContext = "### EXTERNAL FOLDER CONTEXT (Smart Organizer)\n";
                            foreach ($notes as $n) {
                                $externalContext .= "[{$n['date_display']}]: {$n['transcription']}\n---\n";
                            }
                        }
                    } catch (Exception $e) { $externalContext = "Error loading folder context: " . $e->getMessage(); }
                }
            } elseif ($ctxMode === 'foundation' || $ctxMode === 'project') {
                // Dependency Check: Ensure ContextExporter is loaded
                if (!function_exists('ce_generate_context_text')) {
                    $ce_path = CJOS_PATH_PLUGINS . '/ContextExporter.php';
                    if (file_exists($ce_path)) include_once $ce_path;
                }

                if (function_exists('ce_generate_context_text')) {
                    $externalContext = ce_generate_context_text($ctxMode, CJOS_PATH_ROOT);
                } else {
                    $externalContext = "Error: ContextExporter plugin is required for system code context.";
                }
            }

            // 1. Save User Message (Include current model for record)
            $uId = uniqid('m_');
            $uTs = time();
            $db->prepare("INSERT INTO ai_chat_messages (id, thread_id, role, content, model, timestamp) VALUES (?, ?, 'user', ?, ?, ?)")
               ->execute([$uId, $threadId, $content, $thread['model'], $uTs]);
            $stmtHist = $db->prepare("SELECT role, content FROM ai_chat_messages WHERE thread_id = ? ORDER BY timestamp ASC LIMIT 20");
            $stmtHist->execute([$threadId]);
            $history = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
            
            $messages = [['role' => 'system', 'content' => $thread['system_prompt']]];
            
            // Inject Context as a System-level instruction if present
            if (!empty($externalContext)) {
                $messages[] = [
                    'role' => 'system', 
                    'content' => "The following is the EXTERNAL CONTEXT for this conversation. Use this data to answer user queries accurately:\n\n" . $externalContext
                ];
            }

            foreach($history as $h) $messages[] = $h;

            // 3. Call OpenRouter
            $keyFile = CJOS_PATH_DATA . '/openrouter-private.json';
            $apiKey = file_exists($keyFile) ? json_decode(file_get_contents($keyFile), true)['api_key'] : '';    
            if (!$apiKey) { echo json_encode(['status' => 'error', 'message' => 'API Key missing']); exit; }

            $ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
            curl_setopt($ch, CURLOPT_POST, true);
            
            $payload = [
                'model' => $thread['model'],
                'messages' => $messages,
                'temperature' => (float)$thread['temperature'],
                'stream' => true
            ];
            
            if (!empty($thread['thinking_tokens']) && $thread['thinking_tokens'] > 0) {
                $payload['max_completion_tokens'] = (int)$thread['thinking_tokens'];
                $payload['include_reasoning'] = true;
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey", "Content-Type: application/json"]);
            
            $fullContent = "";
            $fullReasoning = "";
            $actualModel = $thread['model'];
            $lastFullJson = "";

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$fullContent, &$fullReasoning, &$actualModel, &$lastFullJson) {
                echo $data;
                if (ob_get_level() > 0) ob_flush();
                flush();

                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    if (strpos($line, 'data: ') === 0) {
                        $jsonStr = substr($line, 6);
                        if ($jsonStr === '[DONE]') continue;
                        $chunk = json_decode($jsonStr, true);
                        if ($chunk) {
                            $lastFullJson = $jsonStr;
                            if (isset($chunk['model'])) $actualModel = $chunk['model'];
                            $delta = $chunk['choices'][0]['delta'] ?? [];
                            if (isset($delta['content'])) $fullContent .= $delta['content'];
                            if (isset($delta['reasoning_content'])) $fullReasoning .= $delta['delta']['reasoning_content'];
                            if (isset($delta['reasoning'])) $fullReasoning .= $delta['reasoning'];
                        }
                    }
                }
                return strlen($data);
            });

            curl_exec($ch);
            curl_close($ch);

            // POST-STREAM: Persist to DB
            if (!empty($fullContent) || !empty($fullReasoning)) {
                $usage = [];
                if (!empty($lastFullJson)) {
                    $lastChunk = json_decode($lastFullJson, true);
                    $usage = $lastChunk['usage'] ?? [];
                }
                
                $cost = floatval($usage['total_cost'] ?? $usage['cost'] ?? 0);
                $pTokens = intval($usage['prompt_tokens'] ?? 0);
                $cTokens = intval($usage['completion_tokens'] ?? 0);
                
                // Fallback for reasoning extraction from content if reasoning_content was missing in stream
                if (empty($fullReasoning)) {
                    if (preg_match('/<(thought|thinking)>(.*?)<\/\1>/is', $fullContent, $matches)) {
                        $fullReasoning = trim($matches[2]);
                        $fullContent = trim(str_replace($matches[0], '', $fullContent));
                    }
                }

                $aiId = uniqid('m_');
                $db->prepare("INSERT INTO ai_chat_messages (id, thread_id, role, content, reasoning, model, cost_usd, raw_response, timestamp) VALUES (?, ?, 'assistant', ?, ?, ?, ?, ?, ?)")
                   ->execute([$aiId, $threadId, $fullContent, $fullReasoning, $actualModel, $cost, $lastFullJson, time()]);
                
                $db->prepare("INSERT INTO ai_chat_cost_log (thread_id, message_id, model, cost_usd, timestamp) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$threadId, $aiId, $actualModel, $cost, time()]);

                $db->prepare("UPDATE ai_chat_threads SET total_cost = total_cost + ?, total_tokens = total_tokens + ? WHERE id = ?")
                   ->execute([$cost, ($pTokens + $cTokens), $threadId]);
            }
            exit;
        }

        if ($action === 'aichat_update_thread_settings') {
            $stmt = $db->prepare("UPDATE ai_chat_threads SET model = ?, temperature = ?, system_prompt = ?, context_mode = ?, context_folder_id = ?, thinking_tokens = ? WHERE id = ?");
            $stmt->execute([
                $_POST['model'], 
                $_POST['temperature'], 
                $_POST['system_prompt'], 
                $_POST['context_mode'] ?? 'none',
                $_POST['context_folder_id'] ?? null,
                (int)($_POST['thinking_tokens'] ?? 0),
                $_POST['id']
            ]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_get_context_size') {
            $threadId = $_POST['id'];
            $thread = $db->query("SELECT * FROM ai_chat_threads WHERE id = '$threadId'")->fetch(PDO::FETCH_ASSOC);
            $ctxMode = $thread['context_mode'] ?? 'none';
            $size = 0;

            if ($ctxMode === 'folder' && !empty($thread['context_folder_id'])) {
$mainDbFile = CJOS_PATH_ROOT . '/conjure.db';
$demoStateFile = CJOS_PATH_DATA . '/demo-mode.json';if (file_exists($demoStateFile)) {
                    $dm = json_decode(file_get_contents($demoStateFile), true);
                    if (!empty($dm['enabled'])) $mainDbFile = CJOS_PATH_DATA . '/demo/demo.db';
                }
                if (file_exists($mainDbFile)) {
                    $mainDb = new PDO("sqlite:$mainDbFile");
                    $stmt = $mainDb->prepare("SELECT SUM(LENGTH(transcription)) FROM logs l JOIN folder_map fm ON l.id = fm.log_id WHERE fm.folder_id = ?");
                    $stmt->execute([$thread['context_folder_id']]);
                    $size = (int)$stmt->fetchColumn();
                }
            } elseif ($ctxMode === 'foundation' || $ctxMode === 'project') {
                if (!function_exists('ce_generate_context_text')) {
                    $ce_path = CJOS_PATH_PLUGINS . '/ContextExporter.php';
                    if (file_exists($ce_path)) include_once $ce_path;
                }
                if (function_exists('ce_generate_context_text')) {
                    $size = strlen(ce_generate_context_text($ctxMode, CJOS_PATH_ROOT));
                }
            }
            echo json_encode(['status' => 'success', 'chars' => $size, 'tokens' => ceil($size / 4)]);
            exit;
        }

        if ($action === 'aichat_get_payload_preview') {
            $threadId = $_POST['id'];
            $thread = $db->query("SELECT * FROM ai_chat_threads WHERE id = '$threadId'")->fetch(PDO::FETCH_ASSOC);
            
            // 1. Context
            $externalContext = "";
            $ctxMode = $thread['context_mode'] ?? 'none';
            if ($ctxMode === 'folder' && !empty($thread['context_folder_id'])) {
$mainDbFile = CJOS_PATH_ROOT . '/conjure.db';
$demoStateFile = CJOS_PATH_DATA . '/demo-mode.json';if (file_exists($demoStateFile)) {
                    $dm = json_decode(file_get_contents($demoStateFile), true);
                    if (!empty($dm['enabled'])) $mainDbFile = CJOS_PATH_DATA . '/demo/demo.db';
                }
                if (file_exists($mainDbFile)) {
                    $mainDb = new PDO("sqlite:$mainDbFile");
                    $stmtCtx = $mainDb->prepare("SELECT l.date_display, l.transcription FROM logs l JOIN folder_map fm ON l.id = fm.log_id WHERE fm.folder_id = ? ORDER BY l.timestamp ASC");
                    $stmtCtx->execute([$thread['context_folder_id']]);
                    $notes = $stmtCtx->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($notes as $n) { $externalContext .= "[{$n['date_display']}]: {$n['transcription']}\n---\n"; }
                }
            } elseif ($ctxMode === 'foundation' || $ctxMode === 'project') {
                if (!function_exists('ce_generate_context_text')) { $ce_path = CJOS_PATH_PLUGINS . '/ContextExporter.php'; if (file_exists($ce_path)) include_once $ce_path; }
                if (function_exists('ce_generate_context_text')) $externalContext = ce_generate_context_text($ctxMode, CJOS_PATH_ROOT);
            }

            // 2. History
            $history = $db->query("SELECT role, content FROM ai_chat_messages WHERE thread_id = '$threadId' ORDER BY timestamp ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            
            // 3. Assemble
            $messages = [['role' => 'system', 'content' => $thread['system_prompt']]];
            if (!empty($externalContext)) {
                $messages[] = ['role' => 'system', 'content' => "EXTERNAL CONTEXT:\n\n" . $externalContext];
            }
            foreach($history as $h) $messages[] = $h;
            
            $payload = [
                'model' => $thread['model'],
                'temperature' => (float)$thread['temperature'],
                'messages' => $messages
            ];

            if (!empty($thread['thinking_tokens']) && $thread['thinking_tokens'] > 0) {
                $payload['max_completion_tokens'] = (int)$thread['thinking_tokens'];
            }
            
            echo json_encode(['status' => 'success', 'payload' => $payload]);
            exit;
        }

        if ($action === 'aichat_delete_message') {
            $db->prepare("DELETE FROM ai_chat_messages WHERE id = ?")->execute([$_POST['id']]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_edit_message') {
            $db->prepare("UPDATE ai_chat_messages SET content = ? WHERE id = ?")->execute([$_POST['content'], $_POST['id']]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_retry_cleanup') {
            $stmt = $db->prepare("DELETE FROM ai_chat_messages WHERE thread_id = ? AND timestamp >= ?");
            $stmt->execute([$_POST['thread_id'], $_POST['timestamp']]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_fork_thread') {
            // Suppress warnings to ensure clean JSON
            error_reporting(0);
            
            $origId = $_POST['thread_id'];
            $timestamp = (int)$_POST['timestamp'];
            $newId = uniqid('t_');
            
            // 1. Clone Thread Metadata
            $stmt = $db->prepare("SELECT * FROM ai_chat_threads WHERE id = ?");
            $stmt->execute([$origId]);
            $orig = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $newTitle = "Branch of " . $orig['title'];
            $stmtIns = $db->prepare("INSERT INTO ai_chat_threads (id, folder_id, title, model, temperature, system_prompt, thinking_tokens, context_mode, context_folder_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([
                $newId, $orig['folder_id'], $newTitle, $orig['model'], $orig['temperature'], 
                $orig['system_prompt'], $orig['thinking_tokens'], $orig['context_mode'], 
                $orig['context_folder_id'], time()
            ]);
            
            // 2. Clone Ancestor Messages
            $stmtMsgs = $db->prepare("SELECT * FROM ai_chat_messages WHERE thread_id = ? AND timestamp <= ? ORDER BY timestamp ASC");
            $stmtMsgs->execute([$origId, $timestamp]);
            $ancestors = $stmtMsgs->fetchAll(PDO::FETCH_ASSOC);
            
            $totalCost = 0;
            $totalTokens = 0;
            
            foreach ($ancestors as $m) {
                $mId = uniqid('m_');
                $stmtM = $db->prepare("INSERT INTO ai_chat_messages (id, thread_id, role, content, model, cost_usd, cached_tokens, prompt_tokens, completion_tokens, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtM->execute([
                    $mId, $newId, $m['role'], $m['content'], $m['model'], 
                    $m['cost_usd'], $m['cached_tokens'], $m['prompt_tokens'], $m['completion_tokens'], $m['timestamp']
                ]);
                $totalCost += $m['cost_usd'];
                $totalTokens += ($m['prompt_tokens'] + $m['completion_tokens']);
            }
            
            // 3. Update New Thread Totals
            $db->prepare("UPDATE ai_chat_threads SET total_cost = ?, total_tokens = ? WHERE id = ?")->execute([$totalCost, $totalTokens, $newId]);
            
            echo json_encode(['status' => 'success', 'id' => $newId]);
            exit;
        }

        if ($action === 'aichat_get_messages') {
            $stmt = $db->prepare("SELECT * FROM ai_chat_messages WHERE thread_id = ? ORDER BY timestamp ASC");
            $stmt->execute([$_POST['thread_id']]);
            echo json_encode(['status' => 'success', 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($action === 'aichat_rename_thread') {
            $stmt = $db->prepare("UPDATE ai_chat_threads SET title = ? WHERE id = ?");
            $stmt->execute([$_POST['title'], $_POST['id']]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_auto_rename') {
            $threadId = $_POST['thread_id'];
            $history = $db->query("SELECT role, content FROM ai_chat_messages WHERE thread_id = '$threadId' ORDER BY timestamp ASC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($history)) { echo json_encode(['status' => 'error']); exit; }

            // Resolve Model: Studio Default -> Thread Model -> Fallback Free
            $settingsFile = $aichat_data_dir . '/settings-private.json';
            $sModel = null;
            if (file_exists($settingsFile)) {
                $sConf = json_decode(file_get_contents($settingsFile), true);
                if (!empty($sConf['default_model'])) $sModel = $sConf['default_model'];
            }
            $tModel = $db->query("SELECT model FROM ai_chat_threads WHERE id = '$threadId'")->fetchColumn();
            $targetModel = $sModel ?: ($tModel ?: 'google/gemini-2.0-flash-exp:free');

            $snippet = "";
            foreach($history as $h) { $snippet .= strtoupper($h['role']) . ": " . substr($h['content'], 0, 300) . "\n"; }

            $keyFile = CJOS_PATH_DATA . '/openrouter-private.json';
            $apiKey = file_exists($keyFile) ? json_decode(file_get_contents($keyFile), true)['api_key'] : '';
            if (!$apiKey) { echo json_encode(['status' => 'error', 'message' => 'API Key missing']); exit; }

            $ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'model' => $targetModel,
                'messages' => [
                    ['role' => 'system', 'content' => 'Generate a concise, catchy 3-5 word title for this chat. Return ONLY the title text, no quotes, no period.'],
                    ['role' => 'user', 'content' => "Context:\n" . $snippet]
                ],
                'temperature' => 0.6
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey", "Content-Type: application/json"]);
            $response = curl_exec($ch);
            $resData = json_decode($response, true);
            curl_close($ch);

            if (isset($resData['choices'][0]['message'])) {
                $newTitle = trim($resData['choices'][0]['message']['content'], " \"\n\r.");
                $db->prepare("UPDATE ai_chat_threads SET title = ? WHERE id = ?")->execute([$newTitle, $threadId]);
                echo json_encode(['status' => 'success', 'title' => $newTitle]);
            } else {
                echo json_encode(['status' => 'error']);
            }
            exit;
        }

        if ($action === 'aichat_delete_thread') {
            $db->prepare("DELETE FROM ai_chat_messages WHERE thread_id = ?")->execute([$_POST['id']]);
            $db->prepare("DELETE FROM ai_chat_threads WHERE id = ?")->execute([$_POST['id']]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_duplicate_thread') {
            $id = $_POST['id'];
            $newId = uniqid('t_');
            
            // Thread Data
            $stmt = $db->prepare("SELECT * FROM ai_chat_threads WHERE id = ?");
            $stmt->execute([$id]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) { echo json_encode(['status' => 'error']); exit; }
            
            $db->prepare("INSERT INTO ai_chat_threads (id, folder_id, title, model, temperature, system_prompt, total_cost, total_tokens, thinking_tokens, context_mode, context_folder_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   $newId, $t['folder_id'], $t['title'] . " (Copy)", $t['model'], $t['temperature'], 
                   $t['system_prompt'], $t['total_cost'], $t['total_tokens'], $t['thinking_tokens'],
                   $t['context_mode'], $t['context_folder_id'], time()
               ]);
            
            // Message Data
            $stmtMsgs = $db->prepare("SELECT * FROM ai_chat_messages WHERE thread_id = ?");
            $stmtMsgs->execute([$id]);
            $msgs = $stmtMsgs->fetchAll(PDO::FETCH_ASSOC);
            foreach($msgs as $m) {
                $db->prepare("INSERT INTO ai_chat_messages (id, thread_id, role, content, model, cost_usd, cached_tokens, prompt_tokens, completion_tokens, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([uniqid('m_'), $newId, $m['role'], $m['content'], $m['model'], $m['cost_usd'], $m['cached_tokens'], $m['prompt_tokens'], $m['completion_tokens'], $m['timestamp']]);
            }

            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_delete_threads_bulk') {
            $ids = json_decode($_POST['ids'], true);
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM ai_chat_messages WHERE thread_id IN ($placeholders)")->execute($ids);
                $db->prepare("DELETE FROM ai_chat_threads WHERE id IN ($placeholders)")->execute($ids);
            }
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'aichat_toggle_pin') {
            $db->prepare("UPDATE ai_chat_threads SET is_pinned = NOT is_pinned WHERE id = ?")->execute([$_POST['id']]);
            echo json_encode(['status' => 'success']);
            exit;
        }
    }
}

// --- UI INTEGRATION ---
$plugin_overlays[] = <<<'HTML'
    <style>
        .aichat-floating-controls {
            position: absolute;
            left: 20px;
            z-index: 100;
            display: flex;
            gap: 12px;
            pointer-events: none;
            /* Align with the page title offset (Header + 50px + slight padding) */
            top: calc(var(--header-base-height) + var(--inner-padding-top) + 52px);
            transition: top 0.4s cubic-bezier(0.16, 1, 0.3, 1), transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s;
            transform-origin: left center;
            will-change: transform, top;
        }

        .aichat-floating-controls.shrunk {
            transform: scale(0.75);
            opacity: 0.5;
        }

        /* Tightly tuck buttons under the collapsed header when scrolling */
        body.header-collapsed .aichat-floating-controls {
            top: calc(var(--header-collapsed-height) + var(--inner-padding-top) + 10px);
        }

        .aichat-floating-right {
            position: absolute; right: 20px; z-index: 100;
            top: calc(var(--header-base-height) + var(--inner-padding-top) + 52px);
            transition: top 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.2s;
            opacity: 0; pointer-events: none;
        }
        body.header-collapsed .aichat-floating-right {
            top: calc(var(--header-collapsed-height) + var(--inner-padding-top) + 10px);
        }
        .aichat-floating-right.visible { opacity: 1; pointer-events: auto; }

        /* FAB DISPLACEMENT HANDSHAKE */
        /* Only displace the global FAB if a thread is open AND the user is looking at this page */
        body.aichat-thread-active.aichat-visible:not(.aichat-input-hidden) {
            --fab-bottom-offset: var(--aichat-dynamic-offset, 100px);
        }

        /* ANCHORED CHAT INPUT BAR */
        #aichat-input-area {
            display: none; /* Hidden by default (Lobby mode) */
            position: absolute;
            bottom: 0; left: 0; right: 0;
            background: var(--card-bg);
            border-top: 1px solid var(--border-color);
            padding: 12px 20px 32px 20px;
            z-index: 3000;
            box-shadow: 0 -10px 30px rgba(0,0,0,0.05);
            align-items: flex-end;
            gap: 8px;
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            will-change: transform;
        }

        #aichat-input-area.hidden {
            transform: translateY(100%);
        }

        /* Dynamic Scroll Padding */
        #aichat-scroll-view {
            --aichat-bottom-padding: 140px;
            transition: padding-bottom 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        body.aichat-input-hidden #aichat-scroll-view {
            --aichat-bottom-padding: 40px;
        }

        body.aichat-thread-active #aichat-input-area {
            display: flex; /* Only show in Thread mode */
        }

        /* Prevent scroll-view from overlapping the fixed header/footer area */
        body.aichat-thread-active #aichat-scroll-view {
            padding-bottom: 20px; /* Minimal padding now that bar is a sibling */
        }
        
        /* Message Padding for Anchored Bar */
        body.aichat-thread-active #aichat-scroll-view {
            padding-bottom: calc(var(--aichat-dynamic-offset, 140px) + 20px);
        }

        /* CODE BLOCK STYLING */
        .aichat-code-wrapper {
            position: relative;
            margin: 12px 0;
            background: #000;
            border: 1px solid #333;
            border-radius: 12px;
            overflow: hidden;
        }
        .aichat-bubble pre {
            background: transparent !important;
            color: #eee !important;
            padding: 14px;
            padding-top: 40px; /* Space for the pinned button */
            margin: 0;
            overflow-x: auto;
            border: none;
        }
        .aichat-bubble code {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.4;
        }
        .aichat-copy-code {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #333; /* Solid background for visibility */
            color: #fff;
            border: 1px solid #444;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            opacity: 1; /* Always visible */
            transition: all 0.2s;
            z-index: 100;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }
        .aichat-copy-code:active { transform: scale(0.9); background: var(--primary); border-color: var(--primary); }
        .aichat-copy-code:active { background: var(--primary); color: white; }

        #aichat-sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; width: 280px;
            background: var(--bg-color); border-right: 1px solid var(--border-color);
            z-index: 4000; transform: translateX(-100%);
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex; flex-direction: column; box-shadow: 20px 0 50px rgba(0,0,0,0.1);
        }
        #aichat-sidebar.open { transform: translateX(0); }
        .aichat-sidebar-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.3); z-index: 3999; opacity: 0; visibility: hidden;
            transition: all 0.3s ease; backdrop-filter: blur(4px);
        }
        .aichat-sidebar-overlay.visible { opacity: 1; visibility: visible; }
        
        .aichat-folder-item {
            padding: 12px 16px; border-radius: 12px; display: flex; align-items: center; gap: 12px;
            cursor: pointer; transition: background 0.2s; margin-bottom: 4px;
            border: 1px solid transparent;
            user-select: none; -webkit-touch-callout: none;
        }
        .aichat-folder-item:active { background: var(--btn-bg); }
        .aichat-folder-item.active { background: var(--card-bg); border-color: var(--border-color); font-weight: 700; }
        
        /* FONT CUSTOMIZER COMPATIBILITY */
        #aichat-sidebar, 
        #aichat-container, 
        #aichat-input-area, 
        .aichat-bubble, 
        .aichat-folder-item,
        .aichat-bubble * {
            font-family: inherit !important;
        }
        
        /* Hide default details marker */
        .aichat-bubble details summary::-webkit-details-marker { display: none; }
        .aichat-bubble details summary { list-style: none; }
        
        /* Keep code blocks monospace */
        .aichat-bubble pre, .aichat-bubble code {
            font-family: 'Courier New', monospace !important;
        }
    </style>

    <div id="aichat-sidebar">
        <div style="padding: 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <div style="font-style: italic; font-size: 20px; font-weight: 500;">Studio Files</div>
            <button onclick="aichatToggleSidebar()" style="background:none; border:none; color:var(--text-secondary); cursor:pointer;">
                <span data-sui-icon="close" data-sui-size="20"></span>
            </button>
        </div>
        <div id="aichat-folder-list" style="flex:1; overflow-y:auto; padding:16px;">
            <!-- Folders injected here -->
        </div>
        <div style="padding: 16px; border-top: 1px solid var(--border-color); display:flex; flex-direction:column; gap:10px;">
            <button onclick="aichatCreateThread(); aichatToggleSidebar();" class="text-btn" style="width:100%; display:flex; align-items:center; justify-content:center; gap:10px; background:var(--ai-accent-bg); color:var(--ai-accent); border-radius:12px; padding:12px; font-weight:700; border:1px solid rgba(88, 86, 214, 0.1);">
                <span data-sui-icon="plus" data-sui-size="16" data-sui-color="var(--ai-accent)"></span> New Chat
            </button>
            <button onclick="aichatPromptCreateFolder()" class="text-btn" style="width:100%; display:flex; align-items:center; justify-content:center; gap:10px; background:var(--btn-bg); border-radius:12px; padding:12px; font-weight:700; color:var(--text-primary); border:1px solid var(--border-color);">
                <span data-sui-icon="plus" data-sui-size="16" data-sui-color="var(--text-primary)"></span> New Folder
            </button>
            <button onclick="aichatOpenGallery()" class="text-btn" style="width:100%; display:flex; align-items:center; justify-content:center; gap:10px; background:var(--btn-bg); border-radius:12px; padding:12px; font-weight:700; color:var(--text-primary); border:1px solid var(--border-color);">
                <span data-sui-icon="star" data-sui-size="16" data-sui-color="var(--text-primary)"></span> Prompt Gallery
            </button>
            <button onclick="aichatOpenSettings()" class="text-btn" style="width:100%; display:flex; align-items:center; justify-content:center; gap:10px; background:var(--btn-bg); border-radius:12px; padding:12px; font-weight:700; color:var(--text-primary); border:1px solid var(--border-color);">
                <span data-sui-icon="shield" data-sui-size="16" data-sui-color="var(--text-primary)"></span> Studio Settings
            </button>
        </div>
    </div>
    <div id="aichat-sidebar-overlay" class="aichat-sidebar-overlay" onclick="aichatToggleSidebar()"></div>
HTML;

$plugin_tools[] = [
    'name' => 'Chat Studio',
    'desc' => 'Private AI bunker',
    'sui_icon' => 'message',
    'color' => 'var(--ai-accent-bg)',
    'icon_color' => 'var(--ai-accent)',
    'action' => "dashNavToPage('aichat-scroll-view')",
    'linked_page' => 'aichat-scroll-view'
];

$plugin_pages[] = <<<'HTML'
    <!-- FLOATING CONTROLS -->
    <div class="aichat-floating-controls">
        <button onclick="aichatToggleSidebar()" class="icon-btn" style="pointer-events: auto; background: var(--card-bg); color: var(--ai-accent); width: 44px; height: 44px; box-shadow: var(--shadow-card); border: 1px solid var(--border-color);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:20px; height:20px;"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
        </button>
        <div id="aichat-header-actions" style="pointer-events: auto; display: flex; gap: 8px;"></div>
    </div>

    <!-- RIGHT FLOATING ACTIONS (Bulk Delete) -->
    <div id="aichat-floating-right" class="aichat-floating-right">
        <button onclick="aichatBulkDelete()" class="icon-btn" style="background:var(--danger); color:white; width:auto; min-width:44px; height:44px; padding:0 12px; border-radius:14px; box-shadow:0 4px 12px rgba(255, 59, 48, 0.3); border:none; display:flex; align-items:center; justify-content:center; gap:6px;">
            <span data-sui-icon="trash" data-sui-size="20" data-sui-color="white"></span>
        </button>
    </div>

    <div class="scroll-view" id="aichat-scroll-view">
        <div class="page-title" style="margin-left: 60px; margin-bottom: 24px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">AiChat Studio</div>
        
        <div id="aichat-container" style="display:flex; flex-direction:column; gap:20px;">
            <div id="aichat-welcome" style="text-align:center; padding:0 0 20px 0;">
                <div style="font-size:42px; margin-bottom:15px;">🤖</div>
                <h2 style="margin:0; font-style:italic; font-weight: 600;">The Chat Studio</h2>
                <p style="color:var(--text-secondary); font-size:13px; margin-top:8px; opacity:0.8;">Private. Isolated. Cost-Aware.</p>
            </div>

            <div id="aichat-main-grid" style="display:grid; grid-template-columns: 1fr; gap:12px;">
                <!-- Lobby Content -->
            </div>

            <!-- CHAT VIEW (Hidden by default) -->
            <div id="aichat-chat-view" style="display:none; flex-direction:column; gap:16px; margin-top: 60px;">
                <div id="aichat-chat-header" style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:20px; padding:18px; display:flex; flex-direction:column; gap:14px; box-shadow: var(--shadow-card);">
                    <!-- Row 1: Title & Actions -->
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
                        <div id="aichat-thread-title" style="font-weight:800; font-size:18px; line-height:1.3; flex:1; word-break:break-word; color:var(--text-title);">Chat Session</div>
                        <div style="display:flex; gap:6px; flex-shrink:0;">
                            <button onclick="aichatPromptRenameThread()" class="icon-btn secondary" style="width:32px; height:32px; background:var(--btn-bg);"><span data-sui-icon="edit" data-sui-size="14"></span></button>
                            <button onclick="aichatDeleteActiveThread()" class="icon-btn danger" style="width:32px; height:32px; background:rgba(255,59,48,0.1);"><span data-sui-icon="trash" data-sui-size="14"></span></button>
                        </div>
                    </div>

                    <!-- Row 2: Stats Capsule Bar -->
                    <div style="display:flex; align-items:center; gap:10px; background:var(--bg-color); padding:8px 12px; border-radius:14px; border:1px solid var(--border-color); overflow-x:auto; -ms-overflow-style:none; scrollbar-width:none;">
                        <div id="aichat-thread-folder" style="font-size:11px; font-weight:700; color:var(--text-primary); white-space:nowrap; display:flex; align-items:center; gap:5px;">
                            <span data-sui-icon="folder" data-sui-size="12" data-sui-color="var(--text-secondary)"></span> <span>Inbox</span>
                        </div>
                        <div id="aichat-context-divider" style="width:1px; height:12px; background:rgba(0,0,0,0.1); flex-shrink:0; display:none;"></div>
                        <div id="aichat-context-size" style="font-size:11px; font-weight:600; color:var(--text-secondary); white-space:nowrap; display:none;">
                            Ctx: 0 tkn
                        </div>
                        <div id="aichat-stats-divider" style="width:1px; height:12px; background:rgba(0,0,0,0.1); flex-shrink:0;"></div>
                        <div id="aichat-thread-tokens" style="font-size:11px; font-weight:600; color:var(--text-secondary); white-space:nowrap; opacity:0.8;">Chat: 0 tkn</div>
                        <div style="flex:1;"></div>
                        <div id="aichat-thread-cost" onclick="aichatOpenBilling()" style="font-size:11px; font-weight:800; color:var(--ai-accent); background:var(--card-bg); padding:3px 10px; border-radius:8px; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,0.05); border:1px solid var(--border-color);">$0.0000</div>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:8px;">
                        <button id="aichat-model-picker-btn" onclick="aichatOpenModelPicker()" style="background:var(--btn-bg); border:1px solid var(--border-color); border-radius:10px; padding:8px; font-size:10px; font-weight:600; text-align:left; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; color:var(--ai-accent);">
                            <span style="color:var(--text-secondary)">Model:</span> Loading...
                        </button>
                        <button id="aichat-temp-picker-btn" onclick="aichatOpenTempPicker()" style="background:var(--btn-bg); border:1px solid var(--border-color); border-radius:10px; padding:8px; font-size:10px; font-weight:600; text-align:left;">
                            <span style="color:var(--text-secondary)">Temp:</span> 0.7
                        </button>
                        <button id="aichat-think-picker-btn" onclick="aichatOpenThinkingPicker()" style="background:var(--btn-bg); border:1px solid var(--border-color); border-radius:10px; padding:8px; font-size:10px; font-weight:600; text-align:left; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">
                            <span style="color:var(--text-secondary)">Think:</span> Off
                        </button>
                    </div>
                    <div id="aichat-system-prompt-container" style="margin-top: 4px;"></div>
                </div>

                <div id="aichat-messages-container" style="display:flex; flex-direction:column; gap:12px; min-height:200px;">
                    <!-- Messages injected here -->
                </div>
            </div>
        </div>
    </div>

    <!-- INPUT BAR (Pinned Flex Sibling) -->
    <div id="aichat-input-area">
        <textarea id="aichat-input" placeholder="Message..." style="flex:1; border:none; background:transparent; padding:10px; font-size:15px; max-height:150px; resize:none; outline:none;"></textarea>
        <button id="aichat-send-btn" onclick="aichatSendMessage()" style="background:var(--ai-accent); color:var(--primary-text); border:none; width:40px; height:40px; border-radius:14px; display:flex; align-items:center; justify-content:center; cursor:pointer;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:18px; height:18px;"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
        </button>
    </div>
    <div class="bottom-fade"></div>
HTML;

$plugin_js .= <<<'JS'
let aichatState = { folders: [], threads: [], activeFolderId: null, selectionMode: false, selectedThreads: [] };

function aichatToggleSidebar() {
    const sidebar = document.getElementById('aichat-sidebar');
    const overlay = document.getElementById('aichat-sidebar-overlay');
    
    const isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('visible', isOpen);
    
    if (isOpen && window.sui) {
        window.sui.haptic('light');
        // Register with ABO/SharedUI stack
        window.sui.registerOverlay('aichat-sidebar', aichatToggleSidebar);
    } else if (window.sui) {
        window.sui.unregisterOverlay('aichat-sidebar');
    }
}

async function aichatInit() {
    const lobby = document.getElementById('aichat-main-grid');
    if (!lobby) return;
    
    // 1. Populate Data
    await aichatRefreshState();

    // 2. HEIGHT OBSERVER: Sync FAB and Scroll Padding with dynamic input bar height
    const inputArea = document.getElementById('aichat-input-area');
    if (inputArea && window.ResizeObserver) {
        new ResizeObserver(() => {
            const h = inputArea.offsetHeight;
            document.documentElement.style.setProperty('--aichat-dynamic-offset', (h + 10) + 'px');
        }).observe(inputArea);
    }

    // 3. VISIBILITY OBSERVER: Detect if the AiChat page is currently in view
    const aiChatPage = document.getElementById('aichat-scroll-view').closest('.page-view');
    if (aiChatPage) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && entry.intersectionRatio > 0.5) {
                    document.body.classList.add('aichat-visible');
                } else {
                    document.body.classList.remove('aichat-visible');
                }
            });
        }, { 
            root: document.querySelector('.horizontal-viewport'),
            threshold: 0.5 
        });
        observer.observe(aiChatPage);
    }
}

// Listen for Lazy Hydration
window.addEventListener('cjos-hydrated', (e) => {
    if (e.detail.id === 'aichat-scroll-view') {
        aichatInit();
    }
});

async function aichatRefreshState() {
    try {
        const data = await window.sui.api('aichat_get_state', {}, { toast: false });
        aichatState.folders = data.folders;
        aichatState.threads = data.threads;
        aichatState.prompts = data.prompts || [];
        aichatState.settings = data.settings || { recent_limit: 5 };
        
        // Re-sync active thread reference to prevent orphaned object state
        if (aichatActiveThread) {
            const fresh = aichatState.threads.find(t => t.id === aichatActiveThread.id);
            if (fresh) aichatActiveThread = fresh;
        }
        
        renderAiChatSidebar();
        renderAiChatLobby();
        window.suiHydrateIcons();
    } catch(e) {
        console.error(e);
    }
}

function renderAiChatSidebar() {
    const list = document.getElementById('aichat-folder-list');
    if (!list) return;
    
    const limit = aichatState.settings?.recent_limit || 5;
    const recentThreads = [...aichatState.threads].sort((a,b) => b.created_at - a.created_at).slice(0, limit);

    let html = `
        <div class="aichat-folder-item ${!aichatState.activeFolderId && !aichatActiveThread ? 'active' : ''}" onclick="aichatSelectFolder(null)">
            <span style="font-size:18px;">🏠</span>
            <div style="flex:1; font-size:14px; font-weight:700;">Studio Home</div>
        </div>
    `;

    if (recentThreads.length > 0) {
        html += `
            <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin: 16px 0 8px 12px; opacity:0.6;">Recent Activity</div>
            ${recentThreads.map(t => `
                <div class="aichat-folder-item ${aichatActiveThread?.id === t.id ? 'active' : ''}" 
                     onclick="aichatOpenThread('${t.id}'); aichatToggleSidebar();"
                     style="padding: 8px 16px; min-height: 0;">
                    <span style="font-size:14px; opacity:0.7;">💬</span>
                    <div style="flex:1; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${t.title || 'Untitled'}</div>
                </div>
            `).join('')}
        `;
    }

    html += `
        <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin: 16px 0 8px 12px; opacity:0.6;">Folders</div>
    `;

    html += aichatState.folders.map(f => `
        <div class="aichat-folder-item ${aichatState.activeFolderId === f.id ? 'active' : ''}" 
             onclick="aichatSelectFolder('${f.id}')"
             oncontextmenu="aichatOpenFolderMenu(event, '${f.id}')">
            <span style="font-size:18px;">${f.icon}</span>
            <div style="flex:1; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${f.name}</div>
        </div>
    `).join('');

    list.innerHTML = html;
}

// Legacy renderAiChatLobby removed to resolve duplication conflict.

function aichatSelectFolder(id) {
    // Cleanup thread UI state when navigating
    document.body.classList.remove('aichat-thread-active');
    aichatActiveThread = null;
    if (window.sui) window.sui.unregisterOverlay('aichat-thread');

    aichatState.activeFolderId = id;

    if (window.sui) {
        if (id) {
            // Register folder view as an overlay that returns to home (null)
            window.sui.registerOverlay('aichat-folder', () => aichatSelectFolder(null));
        } else {
            // Unregister when we are back at the Bunker Home
            window.sui.unregisterOverlay('aichat-folder');
        }
    }

    renderAiChatSidebar();
    renderAiChatLobby();
    
    // Only toggle sidebar if it's currently open (standard for mobile selection)
    const sidebar = document.getElementById('aichat-sidebar');
    if (sidebar && sidebar.classList.contains('open')) {
        aichatToggleSidebar();
    }
}

function aichatPromptCreateFolder() {
    window.openInput("New Studio Folder", "Folder Name", "", async (name) => {
        if (!name) return;
        const icons = ['📦', '📁', '🔐', '💼', '🧪', '🧬', '🧠'];
        const icon = icons[Math.floor(Math.random() * icons.length)];
        await window.sui.api('aichat_save_folder', { name, icon });
        aichatRefreshState();
    });
}

async function aichatCreateThread() {
    const folderId = aichatState.activeFolderId || 'f_inbox';
    const now = new Date();
    const ts = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0') + ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');

    window.openInput("New Chat Title", "Title", ts, async (title) => {
        if (!title) return;
        const res = await window.sui.api('aichat_create_thread', { 
            folder_id: folderId,
            title: title,
            model: aichatState.settings?.default_model || '',
            temperature: aichatState.settings?.default_temperature ?? '',
            system_prompt: aichatState.settings?.default_system_prompt || ''
        });
        if (res.status === 'success') {
            await aichatRefreshState();
            aichatOpenThread(res.id);
        }
    });
}

let aichatActiveThread = null;
let aichatCurrentMessages = [];
let aichatLastScrollTop = 0;

// Initialize Scroll Listener for Input Bar Auto-Hide
window.addEventListener('load', () => {
    const scrollView = document.getElementById('aichat-scroll-view');
    const inputArea = document.getElementById('aichat-input-area');
    if (!scrollView || !inputArea) return;

    scrollView.addEventListener('scroll', () => {
        const st = scrollView.scrollTop;
        const controls = document.querySelector('.aichat-floating-controls');

        // Guard: If we just opened a thread or jumped, ignore the first few px of noise
        if (Math.abs(st - aichatLastScrollTop) > 300 && aichatLastScrollTop === 0) {
            aichatLastScrollTop = st;
            return;
        }
        
        // 1. Shrink/Expand Top Controls (Active throughout Studio)
        if (st > aichatLastScrollTop && st > 80) {
            controls?.classList.add('shrunk');
        } else if (st < aichatLastScrollTop - 15 || st < 30) {
            controls?.classList.remove('shrunk');
        }

        // 2. Chat Input Auto-Hide (Only in active thread)
        if (aichatActiveThread && inputArea) {
            const scrollHeight = scrollView.scrollHeight;
            const clientHeight = scrollView.clientHeight;

            if (st + clientHeight >= scrollHeight - 30) {
                inputArea.classList.remove('hidden');
                document.body.classList.remove('aichat-input-hidden');
            } else if (st > aichatLastScrollTop && st > 50) {
                inputArea.classList.remove('hidden');
                document.body.classList.remove('aichat-input-hidden');
            } else if (st < aichatLastScrollTop - 15) {
                inputArea.classList.add('hidden');
                document.body.classList.add('aichat-input-hidden');
            }
        }

        // 3. Jump Button Direction
        const jumpIcon = document.getElementById('aichat-jump-icon');
        if (jumpIcon) {
            const scrollHeight = scrollView.scrollHeight;
            const clientHeight = scrollView.clientHeight;

            if (st <= 0) {
                // At Top: Always point Down
                jumpIcon.style.transform = 'rotate(0deg)';
                jumpIcon.dataset.dir = 'bottom';
            } else if (st + clientHeight >= scrollHeight - 10) {
                // At Bottom: Always point Up
                jumpIcon.style.transform = 'rotate(180deg)';
                jumpIcon.dataset.dir = 'top';
            } else if (st > aichatLastScrollTop) {
                // Scrolling Down: Point Down
                jumpIcon.style.transform = 'rotate(0deg)';
                jumpIcon.dataset.dir = 'bottom';
            } else if (st < aichatLastScrollTop - 5) {
                // Scrolling Up: Point Up
                jumpIcon.style.transform = 'rotate(180deg)';
                jumpIcon.dataset.dir = 'top';
            }
        }

        aichatLastScrollTop = st <= 0 ? 0 : st;
    }, { passive: true });
});

function aichatScrollToBottom(smooth = true) {
    const scrollView = document.getElementById('aichat-scroll-view');
    const inputArea = document.getElementById('aichat-input-area');
    if (!scrollView) return;

    // 1. Temporarily disable transitions to snap layout to final height
    scrollView.style.transition = 'none';
    if (inputArea) inputArea.style.transition = 'none';

    // 2. Expand UI
    if (inputArea) inputArea.classList.remove('hidden');
    document.body.classList.remove('aichat-input-hidden');

    // 3. Force Reflow (Layout snap)
    void scrollView.offsetHeight;

    // 4. Perform Scroll (Target is now calculated against full height)
    scrollView.scrollTo({ top: 10000000, behavior: smooth ? 'smooth' : 'auto' });

    // 5. Restore transitions after the scroll animation has initiated
    setTimeout(() => {
        scrollView.style.transition = '';
        if (inputArea) inputArea.style.transition = '';
    }, 50);
}

function aichatScrollJump() {
    const scrollView = document.getElementById('aichat-scroll-view');
    const jumpIcon = document.getElementById('aichat-jump-icon');
    if (!scrollView || !jumpIcon) return;
    
    if (jumpIcon.dataset.dir === 'top') {
        scrollView.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
        aichatScrollToBottom(true);
    }
    window.sui.haptic('light');
}

function aichatRenderThreadCard(t, showFolder) {
    const folder = aichatState.folders.find(f => f.id === t.folder_id);
    const isSel = aichatState.selectedThreads.includes(t.id);
    
    // Checkbox UI
    const checkUI = aichatState.selectionMode ? `
        <div style="padding-right:12px;">
            <div style="width:20px; height:20px; border-radius:50%; border:2px solid ${isSel ? 'var(--primary)' : '#D1D1D6'}; background:${isSel ? 'var(--primary)' : 'transparent'}; display:flex; align-items:center; justify-content:center;">
                ${isSel ? '<span data-sui-icon="check" data-sui-color="white" data-sui-size="14" data-sui-stroke="3"></span>' : ''}
            </div>
        </div>
    ` : '';

    const clickAction = aichatState.selectionMode ? `aichatToggleThreadSelect('${t.id}')` : `aichatOpenThread('${t.id}')`;

    return `
    <div class="card" onclick="${clickAction}" oncontextmenu="aichatThreadContextMenu(event, '${t.id}')" 
         style="padding:16px; display:flex; align-items:center; cursor:pointer; background:${isSel ? 'var(--selected-bg)' : 'var(--card-bg)'};">
        ${checkUI}
        <div style="flex:1; min-width:0; padding-right:12px;">
            <div style="font-weight:700; font-size:15px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:${isSel ? 'var(--ai-accent)' : 'var(--text-primary)'};">${t.title || 'Untitled Chat'}</div>
            <div style="font-size:10px; color:var(--text-secondary); margin-top:2px;">${showFolder ? (folder ? folder.icon + ' ' + folder.name : 'Unsorted') : t.model.split('/').pop()}</div>
        </div>
        <div class="meta-badge sui-badge-ai-alt" style="font-size:9px;">$${(t.total_cost || 0).toFixed(4)}</div>
    </div>`;
}

function aichatThreadContextMenu(e, id) {
    e.preventDefault();
    e.stopPropagation();
    window.sui.haptic('light');
    
    if (aichatState.selectionMode) return; // Disable context menu in selection mode

    const thread = aichatState.threads.find(t => t.id === id);
    if (!thread) return;

    window.openPicker(`Thread: ${thread.title}`, [
        { label: thread.is_pinned ? "📌 Unpin from Home" : "📌 Pin to Home", value: "toggle_pin" },
        { label: "Rename", value: "rename" },
        { label: "Duplicate", value: "duplicate" },
        { label: "Delete", value: "delete" },
        { label: "Select Multiple", value: "select_mode" }
    ], null, async (val) => {
        if (val === 'toggle_pin') {
            await window.sui.api('aichat_toggle_pin', { id });
            aichatRefreshState();
        }
        if (val === 'rename') aichatPromptRenameThread(id);
        if (val === 'delete') aichatDeleteThreadById(id);
        if (val === 'duplicate') aichatDuplicateThread(id);
        if (val === 'select_mode') aichatToggleSelectionMode(true, id);
    });
}

function aichatToggleSelectionMode(enabled, initialId = null) {
    aichatState.selectionMode = enabled;
    aichatState.selectedThreads = [];
    if (enabled && initialId) aichatState.selectedThreads.push(initialId);
    
    if (enabled && window.sui) {
        // Register with ABO/SharedUI stack
        window.sui.registerOverlay('aichat-selection', () => aichatToggleSelectionMode(false));
    } else if (window.sui) {
        window.sui.unregisterOverlay('aichat-selection');
    }

    renderAiChatLobby();
    
    // Auto-exit if disabled
    if (!enabled) aichatState.selectedThreads = [];
}

function aichatToggleThreadSelect(id) {
    if (aichatState.selectedThreads.includes(id)) {
        aichatState.selectedThreads = aichatState.selectedThreads.filter(i => i !== id);
    } else {
        aichatState.selectedThreads.push(id);
    }
    
    // Auto-exit if last item deselected? No, keep mode open until user hits Back or similar.
    // Actually, user might want to select 0 items. 
    // But if they tap the back button (top left), we should exit selection mode.
    renderAiChatLobby();
}

// Selection mode and folder navigation are now handled by the natural SharedUI overlay stack

function aichatDeleteThreadById(id) {
    window.openConfirm("Delete Thread?", "This cannot be undone.", async () => {
        await window.sui.api('aichat_delete_thread', { id: id });
        aichatRefreshState();
    }, true);
}

function aichatDuplicateThread(id) {
    window.sui.api('aichat_duplicate_thread', { id: id }).then(() => {
        aichatRefreshState();
        const t = document.getElementById("toast");
        if(t) { t.innerText = "Thread Duplicated"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
    });
}

async function aichatBulkDelete() {
    const ids = aichatState.selectedThreads;
    if (ids.length === 0) { aichatToggleSelectionMode(false); return; }

    window.openConfirm(`Delete ${ids.length} Threads?`, "This cannot be undone.", async () => {
        await window.sui.api('aichat_delete_threads_bulk', { ids: JSON.stringify(ids) });
        aichatToggleSelectionMode(false);
        aichatRefreshState();
    }, true);
}

function aichatPromptRenameThread(id = null) {
    const target = id ? aichatState.threads.find(t => t.id === id) : aichatActiveThread;
    if (!target) return;

    // Inject a "Magic" button into the Input Overlay if it doesn't exist
    const inputOverlay = document.getElementById('shared-input-overlay');
    let magicBtn = document.getElementById('aichat-magic-rename');
    if (!magicBtn && inputOverlay) {
        magicBtn = document.createElement('button');
        magicBtn.id = 'aichat-magic-rename';
        magicBtn.innerHTML = '🪄 AI Suggestion';
        magicBtn.style.cssText = "position:absolute; right:34px; top:85px; font-size:10px; font-weight:800; background:var(--ai-accent-bg); color:var(--ai-accent); border:1px solid var(--ai-accent); padding:4px 8px; border-radius:6px; cursor:pointer;";
        inputOverlay.querySelector('.shared-bottom-sheet').appendChild(magicBtn);
    }
    if (magicBtn) {
        magicBtn.style.display = 'block';
        magicBtn.onclick = async () => {
            magicBtn.innerText = '⌛...';
            const res = await window.sui.api('aichat_auto_rename', { thread_id: target.id }, { toast: false });
            if (res.status === 'success') {
                const field = document.getElementById('shared-input-field');
                if (field) field.value = res.title;
            }
            magicBtn.innerText = '🪄 AI Suggestion';
        };
    }

    window.openInput("Rename Thread", "Thread Title", target.title, async (newTitle) => {
        if (magicBtn) magicBtn.style.display = 'none';
        if (!newTitle || newTitle === target.title) return;
        await window.sui.api('aichat_rename_thread', { id: target.id, title: newTitle });
        
        target.title = newTitle; // optimistic
        if (aichatActiveThread && aichatActiveThread.id === target.id) {
            document.getElementById('aichat-thread-title').innerText = newTitle;
        }
        aichatRefreshState(); 
    });
}

function aichatDeleteActiveThread() {
    if (!aichatActiveThread) return;
    window.openConfirm("Delete Thread?", "This will permanently remove this conversation and all its messages.", async () => {
        await window.sui.api('aichat_delete_thread', { id: aichatActiveThread.id });
        aichatCloseThread();
        aichatRefreshState();
    }, true);
}

async function aichatRefreshContextSize() {
    if (!aichatActiveThread) return;
    const badge = document.getElementById('aichat-context-size');
    const divider = document.getElementById('aichat-context-divider');
    const statsDivider = document.getElementById('aichat-stats-divider');
    if (aichatActiveThread.context_mode === 'none') {
        if (badge) badge.style.display = 'none';
        if (divider) divider.style.display = 'none';
        if (statsDivider) statsDivider.style.display = 'none';
        return;
    }

    try {
        const data = await window.sui.api('aichat_get_context_size', { id: aichatActiveThread.id }, { toast: false });
        if (badge) {
            badge.innerText = `Ctx: ${data.tokens.toLocaleString()} tkn`;
            badge.style.display = 'block';
        }
        if (divider) divider.style.display = 'block';
    } catch(e) { 
        if (badge) badge.style.display = 'none';
        if (divider) divider.style.display = 'none';
    }
}

async function aichatOpenThread(id) {
    const thread = aichatState.threads.find(t => t.id === id);
    if (!thread) return;
    
    // 1. IMMEDIATE STATE NORMALIZATION (Before any async/await)
    const scrollView = document.getElementById('aichat-scroll-view');
    if (scrollView) aichatState.lobbyScrollPos = scrollView.scrollTop;

    aichatActiveThread = thread;
    aichatLastScrollTop = 0;
    const inputArea = document.getElementById('aichat-input-area');
    const jumpIcon = document.getElementById('aichat-jump-icon');
    const controls = document.querySelector('.aichat-floating-controls');

    if (scrollView) scrollView.scrollTop = 0;
    if (controls) controls.classList.remove('shrunk');
    if (inputArea) inputArea.classList.remove('hidden');
    if (jumpIcon) {
        jumpIcon.style.transform = 'rotate(0deg)';
        jumpIcon.dataset.dir = 'bottom';
    }
    document.body.classList.remove('aichat-input-hidden');
    
    // Show Loading Skeleton
    const msgContainer = document.getElementById('aichat-messages-container');
    if (msgContainer) {
        msgContainer.innerHTML = `
            <div style="display:flex; flex-direction:column; gap:20px; padding:10px 0;">
                <div class="card" style="padding:16px; width:80%; border-bottom-left-radius:4px;">${window.suiSkeleton(3)}</div>
                <div class="card" style="padding:16px; width:70%; align-self:flex-end; border-bottom-right-radius:4px; opacity:0.6;">${window.suiSkeleton(2)}</div>
                <div class="card" style="padding:16px; width:85%; border-bottom-left-radius:4px;">${window.suiSkeleton(4)}</div>
            </div>
        `;
    }

    // 2. UI SETUP
    aichatUpdateHeaderActions();
    if (window.sui) window.sui.registerOverlay('aichat-thread', aichatCloseThread);
    document.body.classList.add('aichat-thread-active');
    
    document.getElementById('aichat-welcome').style.display = 'none';
    document.getElementById('aichat-main-grid').style.display = 'none';
    document.getElementById('aichat-chat-view').style.display = 'flex';
    
    document.getElementById('aichat-thread-title').innerText = thread.title;
    const folder = aichatState.folders.find(f => f.id === thread.folder_id);
    document.getElementById('aichat-thread-folder').innerText = folder ? `${folder.icon} ${folder.name}` : 'Unsorted';
    document.getElementById('aichat-thread-cost').innerText = `$${(thread.total_cost || 0).toFixed(4)}`;
    document.getElementById('aichat-thread-tokens').innerText = `Chat: ${(thread.total_tokens || 0).toLocaleString()} tkn`;
    aichatRefreshContextSize();
    document.getElementById('aichat-model-picker-btn').innerHTML = `<span style="color:var(--text-secondary)">Model:</span> <span style="color:var(--ai-accent)">${thread.model.split('/').pop()}</span>`;
    document.getElementById('aichat-temp-picker-btn').innerHTML = `<span style="color:var(--text-secondary)">Temp:</span> <span style="color:var(--ai-accent)">${thread.temperature}</span>`;
    const thinkLabel = thread.thinking_tokens > 0 ? (thread.thinking_tokens >= 1000 ? (thread.thinking_tokens/1000)+'k' : thread.thinking_tokens) : 'Off';
    document.getElementById('aichat-think-picker-btn').innerHTML = `<span style="color:var(--text-secondary)">Think:</span> <span style="color:var(--ai-accent)">${thinkLabel}</span>`;
    
    const promptHtml = `
        <div style="padding: 12px 0;">
            <textarea id="aichat-system-prompt-input" style="width:100%; min-height:80px; font-size:13px; padding:10px; border-radius:10px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--input-text); resize:vertical; line-height:1.4; outline:none;">${thread.system_prompt || ''}</textarea>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;">
                <button onclick="aichatSaveSystemPrompt()" class="btn-primary" style="font-size:12px; padding:10px; background:var(--ai-accent);">Update Prompt</button>
                <button onclick="aichatImportPromptFromGallery()" class="btn-primary" style="font-size:12px; padding:10px; background:var(--btn-bg); color:var(--text-primary); border:1px solid var(--border-color);">Import Gallery</button>
            </div>
        </div>
    `;
    document.getElementById('aichat-system-prompt-container').innerHTML = window.suiAccordion('aichat-sp-acc', 'System Prompt', promptHtml, false);
    
    // Render Context Accordion
    const ctxMode = thread.context_mode || 'none';
    const ctxFolderId = thread.context_folder_id || '';
    let folderName = "Select Folder...";
    if (ctxFolderId && typeof so_folders !== 'undefined') {
        const f = so_folders.find(x => x.id == ctxFolderId);
        if (f) folderName = f.name;
    }

    const contextHtml = `
        <div style="padding: 12px 0; display:flex; flex-direction:column; gap:12px;">
            <div style="display:flex; align-items:center; justify-content:space-between; background:var(--btn-bg); padding:10px 14px; border-radius:12px;">
                <div style="flex:1;">
                    <div style="font-size:13px; font-weight:700;">Folder Context</div>
                    <div style="font-size:11px; color:var(--text-secondary);">Include all notes from a Smart Organizer folder.</div>
                </div>
                <label class="switch" style="width:40px; height:22px;">
                    <input type="checkbox" id="ctx-chk-folder" ${ctxMode === 'folder' ? 'checked' : ''} onchange="aichatToggleContext('folder', this.checked)">
                    <span class="slider"></span>
                </label>
            </div>
            <div id="ctx-folder-picker-row" style="display:${ctxMode === 'folder' ? 'block' : 'none'}; padding-left:12px;">
                <button onclick="aichatPickContextFolder()" id="ctx-folder-btn" style="width:100%; text-align:left; background:var(--card-bg); border:1px solid var(--border-color); padding:10px; border-radius:10px; font-size:12px; font-weight:600; color:var(--primary);">
                    📂 ${folderName}
                </button>
            </div>

            <div style="display:flex; align-items:center; justify-content:space-between; background:var(--btn-bg); padding:10px 14px; border-radius:12px;">
                <div style="flex:1;">
                    <div style="font-size:13px; font-weight:700;">System Code Context</div>
                    <div style="font-size:11px; color:var(--text-secondary);">Include foundation or project source code.</div>
                </div>
                <label class="switch" style="width:40px; height:22px;">
                    <input type="checkbox" id="ctx-chk-code" ${ctxMode === 'foundation' || ctxMode === 'project' ? 'checked' : ''} onchange="aichatToggleContext('code', this.checked)">
                    <span class="slider"></span>
                </label>
            </div>
            <div id="ctx-code-picker-row" style="display:${ctxMode === 'foundation' || ctxMode === 'project' ? 'block' : 'none'}; padding-left:12px;">
                <button onclick="aichatPickCodeTier()" id="ctx-code-btn" style="width:100%; text-align:left; background:var(--card-bg); border:1px solid var(--border-color); padding:10px; border-radius:10px; font-size:12px; font-weight:600; color:var(--ai-accent);">
                    🛠️ ${ctxMode === 'project' ? 'Foundation + Project' : 'Foundation Only'}
                </button>
            </div>
        </div>
    `;
    
    let ctxContainer = document.getElementById('aichat-context-container');
    if (!ctxContainer) {
        ctxContainer = document.createElement('div');
        ctxContainer.id = 'aichat-context-container';
        ctxContainer.style.marginTop = '8px';
        document.getElementById('aichat-chat-header').appendChild(ctxContainer);
    }
    ctxContainer.innerHTML = window.suiAccordion('aichat-ctx-acc', 'External Context', contextHtml, false);

    let payContainer = document.getElementById('aichat-payload-container');
    if (!payContainer) {
        payContainer = document.createElement('div');
        payContainer.id = 'aichat-payload-container';
        payContainer.style.marginTop = '8px';
        document.getElementById('aichat-chat-header').appendChild(payContainer);
    }
    const payHtml = `
        <div style="padding: 12px 0;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Next API Transmission</span>
                <button onclick="aichatRefreshPayloadPreview()" class="text-btn" style="font-size:10px; font-weight:800; color:var(--primary);">REFRESH</button>
            </div>
            <div id="aichat-payload-content" style="background:#000; color:#0f0; padding:12px; border-radius:10px; font-family:monospace; font-size:10px; white-space:pre-wrap; overflow-x:auto; max-height:300px; border:1px solid #333; line-height:1.4;">
                Click "Raw API Payload" to load...
            </div>
        </div>
    `;
    payContainer.innerHTML = window.suiAccordion('aichat-pay-acc', 'Raw API Payload', payHtml, false);

    // Hook accordion click to auto-refresh
    const payTitle = payContainer.querySelector('div[onclick*="aichat-pay-acc"]');
    if (payTitle) {
        const origClick = payTitle.onclick;
        payTitle.onclick = (e) => {
            origClick(e);
            if (document.getElementById('aichat-pay-acc').classList.contains('open')) {
                aichatRefreshPayloadPreview();
            }
        };
    }

    window.suiHydrateIcons(document.getElementById('aichat-chat-header'));

    // 3. ASYNC DATA LOAD
    const msgRes = await window.sui.api('aichat_get_messages', { thread_id: id }, { toast: false });
    aichatCurrentMessages = msgRes.messages || [];
    
    // Ensure Markdown is ready before first render
    await aichatEnsureMarked();
    renderAiChatMessages(aichatCurrentMessages);
    
    window.sui.haptic('medium');
}

function aichatCloseThread() {
    if (window.sui) window.sui.unregisterOverlay('aichat-thread');
    document.body.classList.remove('aichat-thread-active');
    aichatActiveThread = null;
    renderAiChatLobby();

    const scrollView = document.getElementById('aichat-scroll-view');
    if (scrollView && aichatState.lobbyScrollPos !== undefined) {
        scrollView.scrollTop = aichatState.lobbyScrollPos;
    }
}

function renderAiChatMessages(messages) {
    const container = document.getElementById('aichat-messages-container');
    if (!container) return;

    // Sync global state so context menu can find newly added messages
    aichatCurrentMessages = messages;
    
    if (messages.length === 0) {
        container.innerHTML = `
            <div style="text-align:center; padding:40px; opacity:0.3; font-style:italic; font-size:13px;">
                No messages yet. Start the conversation below.
            </div>
        `;
        return;
    }
    
    container.innerHTML = messages.map(m => {
        const content = (typeof marked !== 'undefined') ? marked.parse(m.content) : m.content;
        const hasReasoning = m.reasoning && m.reasoning.trim().length > 0;
        const reasoning = (hasReasoning && typeof marked !== 'undefined') 
            ? `<details style="margin-bottom:12px; font-size:13px; background:color-mix(in srgb, var(--ai-accent), transparent 95%); padding:12px; border-radius:12px; border:1px solid color-mix(in srgb, var(--ai-accent), transparent 80%);">
                <summary style="cursor:pointer; color:var(--ai-accent); font-weight:800; list-style:none; display:flex; align-items:center; justify-content:space-between; user-select:none;">
                    <div style="display:flex; align-items:center; gap:8px;"><span style="font-size:16px;">🧠</span> THOUGHT PROCESS</div>
                    <span style="font-size:10px; opacity:0.5;">TAP TO VIEW</span>
                </summary>
                <div style="margin-top:12px; color:var(--text-primary); font-style:italic; line-height:1.6; border-top:1px dashed color-mix(in srgb, var(--ai-accent), transparent 70%); padding-top:12px; opacity:0.9;">${marked.parse(m.reasoning)}</div>
               </details>` 
            : '';
            
        const modelName = m.model ? m.model.split('/').pop() : 'Unknown';
        
        let cacheBadge = '';
        if (m.role === 'assistant') {
            if (m.cached_tokens > 0) {
                cacheBadge = `<span style="color:#FFCC00; font-weight:800;" title="${m.cached_tokens} tokens reused">⚡ Cached</span> • `;
            } else {
                cacheBadge = `<span style="color:var(--text-secondary); opacity:0.5; font-weight:600;">⚪ Uncached</span> • `;
            }
        }

        const tokenInfo = (m.role === 'assistant') ? ` • ${m.prompt_tokens}p+${m.completion_tokens}c` : '';
        return `
            <div style="display:flex; flex-direction:column; align-items:${m.role === 'user' ? 'flex-end' : 'flex-start'}; margin-bottom:12px;">
                <div class="aichat-bubble" 
                     oncontextmenu="aichatShowMessageMenu(event, '${m.id}')"
                     style="max-width:90%; padding:12px 16px; border-radius:18px; font-size:15px; line-height:1.5; 
                            background:${m.role === 'user' ? 'var(--ai-accent)' : 'var(--card-bg)'}; 
                            color:${m.role === 'user' ? 'var(--primary-text)' : 'var(--text-primary)'};
                            border:${m.role === 'user' ? 'none' : '1px solid var(--border-color)'};
                            ${m.role === 'user' ? 'border-bottom-right-radius:4px;' : 'border-bottom-left-radius:4px;'}">
                    ${reasoning}
                    ${content}
                </div>
                <div style="font-size:9px; color:var(--text-secondary); margin-top:4px; padding:0 4px;">
                    ${m.role === 'user' ? 'You' : 'AI'} • ${cacheBadge}${modelName}${tokenInfo} • $${(m.cost_usd || 0).toFixed(6)}
                </div>
            </div>
        `;
    }).join('');
    
    // Auto-scroll to bottom
    aichatScrollToBottom(true);
    if (typeof window.srScan === 'function') window.srScan(container);

    // Manually enhance code blocks to ensure the button is always present
    aiEnhanceCodeBlocks(container);
}

function aiEnhanceCodeBlocks(container) {
    container.querySelectorAll('pre').forEach(pre => {
        // 1. Wrap in a stable container if not already wrapped
        let wrapper = pre.parentElement;
        if (!wrapper.classList.contains('aichat-code-wrapper')) {
            wrapper = document.createElement('div');
            wrapper.className = 'aichat-code-wrapper';
            pre.parentNode.insertBefore(wrapper, pre);
            wrapper.appendChild(pre);
        }

        // 2. Add pinned button to the wrapper (not the scrolling pre)
        if (!wrapper.querySelector('.aichat-copy-code')) {
            const btn = document.createElement('button');
            btn.className = 'aichat-copy-code';
            btn.innerText = 'COPY';
            btn.onclick = (e) => {
                e.stopPropagation();
                const code = pre.querySelector('code')?.innerText || pre.innerText;
                navigator.clipboard.writeText(code);
                btn.innerText = "COPIED!";
                btn.style.background = "var(--primary)";
                setTimeout(() => { 
                    btn.innerText = "COPY"; 
                    btn.style.background = "#333";
                }, 2000);
            };
            wrapper.appendChild(btn);
        }
    });
}

function aichatShowMessageMenu(e, id) {
    e.preventDefault();
    const msg = aichatCurrentMessages.find(m => m.id === id);
    if (!msg) return;

    window.sui.haptic('light');

    const options = [
        { label: "Copy Text", value: "copy" },
        { label: "Fork from here", value: "fork" },
        { label: "Edit Message", value: "edit" },
        { label: "Delete", value: "delete" }
    ];

    if (msg.role === 'user') {
        options.unshift({ label: "Retry / Resend", value: "retry" });
    } else if (msg.role === 'assistant') {
        options.unshift({ label: "🔍 Show Raw API JSON", value: "raw" });
    }

    window.openPicker("Message Options", options, null, async (val) => {
        if (val === 'copy') {
            navigator.clipboard.writeText(msg.content);
            const t = document.getElementById("toast");
            if(t) { t.innerText = "Text Copied"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
        } else if (val === 'edit') {
            window.openInput("Edit Message", "Message content", msg.content, async (newContent) => {
                if (!newContent || newContent === msg.content) return;
                await window.sui.api('aichat_edit_message', { id, content: newContent });
                aichatOpenThread(aichatActiveThread.id); // Refresh
            }, true);
        } else if (val === 'delete') {
            window.openConfirm("Delete Message?", "This will remove it from the thread history.", async () => {
                await window.sui.api('aichat_delete_message', { id });
                aichatOpenThread(aichatActiveThread.id); // Refresh
            }, true);
        } else if (val === 'retry') {
            // REWIND: Delete this message and everything that came after it
            await window.sui.api('aichat_retry_cleanup', { 
                thread_id: aichatActiveThread.id, 
                timestamp: msg.timestamp 
            }, { toast: false });
            
            // Refresh UI to show the rewound state (removes the "future" messages)
            const msgRes = await window.sui.api('aichat_get_messages', { thread_id: aichatActiveThread.id }, { toast: false });
            aichatCurrentMessages = msgRes.messages || [];
            renderAiChatMessages(aichatCurrentMessages);

            // Re-send the original content
            document.getElementById('aichat-input').value = msg.content;
            aichatSendMessage();
        } else if (val === 'raw') {
            aichatShowRawMessage(msg.id);
        } else if (val === 'fork') {
            const res = await window.sui.api('aichat_fork_thread', {
                thread_id: aichatActiveThread.id,
                timestamp: msg.timestamp
            }, { toast: "Conversation Forked" });
            
            if (res.status === 'success') {
                await aichatRefreshState();
                aichatOpenThread(res.id);
            }
        }
    });
}

function aichatShowRawMessage(id) {
    const msg = aichatCurrentMessages.find(m => m.id === id);
    if (!msg) return;

    let displayJson = "No raw response data available for this message.";
    if (msg.raw_response) {
        try {
            const parsed = JSON.parse(msg.raw_response);
            displayJson = JSON.stringify(parsed, null, 2);
        } catch(e) { displayJson = msg.raw_response; }
    }

    const content = `
        <div id="aichat-raw-json-display" style="background:#000; color:#0f0; padding:16px; border-radius:12px; font-family:monospace; font-size:11px; white-space:pre-wrap; overflow-x:auto; border:1px solid #333; line-height:1.4;">${displayJson}</div>
        <div style="margin-top:20px;">
            <button onclick="navigator.clipboard.writeText(document.getElementById('aichat-raw-json-display').innerText); window.sui.haptic('success');" class="btn-primary" style="width:100%; background:var(--ai-accent);">Copy JSON to Clipboard</button>
        </div>
    `;

    window.sui.openStudio({
        id: 'aichat-raw-json',
        title: 'Raw API Response',
        content: content
    });
}

// Global listener for Code Copy buttons
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('aichat-copy-code')) {
        const pre = e.target.closest('pre');
        const code = pre.querySelector('code');
        navigator.clipboard.writeText(code.innerText);
        e.target.innerText = "COPIED!";
        setTimeout(() => { e.target.innerText = "COPY"; }, 2000);
    }
});

/**
 * Ensures marked.js is loaded and the custom renderer is initialized.
 */
async function aichatEnsureMarked() {
    if (window._aiMarkedReady) return true;

    // 1. Check if script needs to be injected
    if (typeof marked === 'undefined') {
        console.log("[AiChat] Loading marked.js...");
        await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = window.CJOS_ASSET_PATH + '/marked.min.js';
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    // 2. Initialize custom renderer
    if (typeof marked !== 'undefined' && !window._aiMarkedReady) {
        aiInitMarked();
    }
    return true;
}

// Hook into marked to add copy buttons to code blocks
function aiInitMarked() {
    if (typeof marked !== 'undefined' && !window._aiMarkedReady) {
        const renderer = new marked.Renderer();
        const originalCode = renderer.code.bind(renderer);
        
        renderer.code = function(code, language) {
            // Check if code is an object (newer marked versions) or string
            const content = (typeof code === 'object') ? code.text : code;
            return `<pre><code>${content}</code><button class="aichat-copy-code">COPY</button></pre>`;
        };
        
        marked.setOptions({ renderer: renderer });
        window._aiMarkedReady = true;
    }
}

// Call during load and before any render
window.addEventListener('load', aiInitMarked);

async function aichatSendMessage() {
    const input = document.getElementById('aichat-input');
    const content = input.value.trim();
    if (!content || !aichatActiveThread) return;

    input.value = "";
    input.style.height = "auto";
    
    // Optimistic UI update
    const container = document.getElementById('aichat-messages-container');
    const tempId = 'temp_' + Date.now();
    const tempTs = Math.floor(Date.now()/1000);
    
    // Create mock message and sync with global state immediately
    const userMsgObj = { id: tempId, role: 'user', content: content, timestamp: tempTs, cost_usd: 0 };
    aichatCurrentMessages.push(userMsgObj);
    
    // Append user message immediately with context menu support
    const userDiv = document.createElement('div');
    userDiv.style.cssText = "display:flex; flex-direction:column; align-items:flex-end; margin-bottom:12px;";
    userDiv.innerHTML = `
        <div class="aichat-bubble" 
             data-temp-id="${tempId}"
             oncontextmenu="aichatShowMessageMenu(event, '${tempId}')"
             style="max-width:90%; padding:12px 16px; border-radius:18px; font-size:15px; line-height:1.5; background:var(--ai-accent); color:var(--primary-text); border-bottom-right-radius:4px;">${content}</div>
        <div class="aichat-status" style="font-size:9px; color:var(--text-secondary); margin-top:4px;">Sending...</div>
    `;
    container.appendChild(userDiv);
    
    // Loading Indicator
    const loader = document.createElement('div');
    loader.id = "aichat-ai-loader";
    loader.style.cssText = "display:flex; flex-direction:column; align-items:flex-start; margin-bottom:12px;";
    loader.innerHTML = `
        <div class="card" style="padding:12px 16px; border-radius:18px; border-bottom-left-radius:4px;">
            <div class="spinner" style="width:16px; height:16px; border-width:2px; margin:0;"></div>
        </div>
    `;
    container.appendChild(loader);
    
    const scroll = document.getElementById('aichat-scroll-view');
    scroll.scrollTo({ top: scroll.scrollHeight, behavior: 'smooth' });

    try {
        const fd = new FormData();
        fd.append('plugin_action', 'aichat_send_message');
        fd.append('thread_id', aichatActiveThread.id);
        fd.append('content', content);
        if (typeof cjosCsrfToken !== 'undefined') fd.append('csrf_token', cjosCsrfToken);

        const response = await fetch('index.php', { method: 'POST', body: fd });
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        
        loader.remove();
        
        // Create live bubble
        const aiDiv = document.createElement('div');
        aiDiv.style.cssText = "display:flex; flex-direction:column; align-items:flex-start; margin-bottom:12px;";
        aiDiv.innerHTML = `
            <div class="aichat-bubble" style="max-width:90%; padding:12px 16px; border-radius:18px; font-size:15px; line-height:1.5; background:var(--card-bg); border:1px solid var(--border-color); border-bottom-left-radius:4px;">
                <div class="ai-live-reasoning" style="display:none;"></div>
                <div class="ai-live-content"></div>
            </div>
        `;
        container.appendChild(aiDiv);
        
        const reasoningBox = aiDiv.querySelector('.ai-live-reasoning');
        const contentBox = aiDiv.querySelector('.ai-live-content');
        
        let fullText = "";
        let fullReasoning = "";

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            
            const chunk = decoder.decode(value, { stream: true });
            const lines = chunk.split("\n");
            
            for (const line of lines) {
                if (line.startsWith("data: ")) {
                    const dataStr = line.substring(6).trim();
                    if (dataStr === "[DONE]") break;
                    try {
                        const json = JSON.parse(dataStr);
                        const delta = json.choices[0].delta;
                        
                        if (delta.reasoning_content || delta.reasoning) {
                            fullReasoning += (delta.reasoning_content || delta.reasoning);
                            reasoningBox.style.display = "block";
                            reasoningBox.innerHTML = `<details open style="margin-bottom:12px; font-size:13px; background:color-mix(in srgb, var(--ai-accent), transparent 95%); padding:12px; border-radius:12px; border:1px solid color-mix(in srgb, var(--ai-accent), transparent 80%);">
                                <summary style="color:var(--ai-accent); font-weight:800; list-style:none;">🧠 THINKING...</summary>
                                <div style="margin-top:10px; font-style:italic; opacity:0.8;">${marked.parse(fullReasoning)}</div>
                            </details>`;
                        }
                        
                        if (delta.content) {
                            fullText += delta.content;
                            contentBox.innerHTML = marked.parse(fullText);
                            aiEnhanceCodeBlocks(aiDiv);
                        }
                    } catch(e) {}
                }
            }
            aichatScrollToBottom(true);
        }

        // Finalize UI
        await aichatRefreshState();
        const msgRes = await window.sui.api('aichat_get_messages', { thread_id: aichatActiveThread.id }, { toast: false });
        renderAiChatMessages(msgRes.messages);

    } catch(e) {
        loader.innerHTML = `<div style="color:var(--danger); font-size:12px;">Stream Error: ${e.message}</div>`;
    }
}

async function aichatToggleContext(type, enabled) {
    if (!aichatActiveThread) return;
    
    const folderChk = document.getElementById('ctx-chk-folder');
    const codeChk = document.getElementById('ctx-chk-code');
    const folderRow = document.getElementById('ctx-folder-picker-row');
    const codeRow = document.getElementById('ctx-code-picker-row');

    if (enabled) {
        if (type === 'folder') {
            codeChk.checked = false;
            codeRow.style.display = 'none';
            folderRow.style.display = 'block';
            aichatActiveThread.context_mode = 'folder';
        } else {
            folderChk.checked = false;
            folderRow.style.display = 'none';
            codeRow.style.display = 'block';
            // Default to foundation if not already in a code context mode
            if (aichatActiveThread.context_mode !== 'foundation' && aichatActiveThread.context_mode !== 'project') {
                aichatActiveThread.context_mode = 'foundation';
            }
            // Update the display label immediately
            const btn = document.getElementById('ctx-code-btn');
            if (btn) btn.innerText = `🛠️ ${aichatActiveThread.context_mode === 'project' ? 'Foundation + Project' : 'Foundation Only'}`;
        }
    } else {
        folderRow.style.display = 'none';
        codeRow.style.display = 'none';
        aichatActiveThread.context_mode = 'none';
    }

    aichatPersistThreadContext();
}

function aichatPickContextFolder() {
    if (typeof so_folders === 'undefined') return;
    const options = so_folders.map(f => ({ label: `📁 ${f.name}`, value: f.id }));
    window.openPicker("Select Context Folder", options, aichatActiveThread.context_folder_id, (val) => {
        aichatActiveThread.context_folder_id = val;
        const f = so_folders.find(x => x.id == val);
        document.getElementById('ctx-folder-btn').innerText = `📂 ${f ? f.name : 'Selected'}`;
        aichatPersistThreadContext();
    });
}

function aichatPickCodeTier() {
    const options = [
        { label: "Foundation Only", value: "foundation" },
        { label: "Foundation + Project", value: "project" }
    ];
    window.openPicker("Code Context Tier", options, aichatActiveThread.context_mode, (val) => {
        aichatActiveThread.context_mode = val;
        document.getElementById('ctx-code-btn').innerText = `🛠️ ${val === 'project' ? 'Foundation + Project' : 'Foundation Only'}`;
        aichatPersistThreadContext();
    });
}

async function aichatPersistThreadContext() {
    await window.sui.api('aichat_update_thread_settings', {
        id: aichatActiveThread.id,
        model: aichatActiveThread.model,
        temperature: aichatActiveThread.temperature,
        system_prompt: aichatActiveThread.system_prompt,
        context_mode: aichatActiveThread.context_mode,
        context_folder_id: aichatActiveThread.context_folder_id
    }, { toast: false });
    aichatRefreshContextSize();
}

async function aichatSaveSystemPrompt() {
    if (!aichatActiveThread) return;
    const newPrompt = document.getElementById('aichat-system-prompt-input').value;
    aichatActiveThread.system_prompt = newPrompt;
    
    await window.sui.api('aichat_update_thread_settings', {
        id: aichatActiveThread.id,
        model: aichatActiveThread.model,
        temperature: aichatActiveThread.temperature,
        system_prompt: newPrompt
    }, { toast: "System Prompt Updated" });
    
    window.suiToggle('aichat-sp-acc'); // Close accordion
}

async function aichatOpenModelPicker() {
    if (!aichatActiveThread) return;

    if (typeof window.openModelPicker === "function") {
        // Use Robust OpenRouter Picker
        window.openModelPicker();
        
        const origSelect = window.selectModel;
        window.selectModel = async (id) => {
            aichatActiveThread.model = id;
            
            // Update UI
            const btn = document.getElementById('aichat-model-picker-btn');
            if (btn) btn.innerHTML = `<span style="color:var(--text-secondary)">Model:</span> <span style="color:var(--ai-accent)">${id.split('/').pop()}</span>`;
            
            // Persist to DB
            await window.sui.api('aichat_update_thread_settings', {
                id: aichatActiveThread.id,
                model: id,
                temperature: aichatActiveThread.temperature,
                system_prompt: aichatActiveThread.system_prompt
            }, { toast: "Model Updated" });

            // Restore original handler and close
            window.selectModel = origSelect;
            if (typeof window.closeAiManager === "function") window.closeAiManager();
        };
    } else {
        // Fallback to simple picker if OpenRouterAI plugin is disabled
        const models = [
            { label: "Gemini 2.0 Flash (Free)", value: "google/gemini-2.0-flash-exp:free" },
            { label: "Claude 3.5 Sonnet", value: "anthropic/claude-3.5-sonnet" },
            { label: "GPT-4o", value: "openai/gpt-4o" },
            { label: "Llama 3.1 70B", value: "meta-llama/llama-3.1-70b-instruct" }
        ];
        window.openPicker("Select Model", models, aichatActiveThread.model, async (val) => {
            aichatActiveThread.model = val;
            document.getElementById('aichat-model-picker-btn').innerText = `Model: ${val.split('/').pop()}`;
            await window.sui.api('aichat_update_thread_settings', {
                id: aichatActiveThread.id,
                model: val,
                temperature: aichatActiveThread.temperature,
                system_prompt: aichatActiveThread.system_prompt
            }, { toast: "Model Updated" });
        });
    }
}

async function aichatOpenThinkingPicker() {
    if (!aichatActiveThread) return;
    const options = [
        { label: "Off (Standard)", value: 0 },
        { label: "4k Tokens", value: 4000 },
        { label: "8k Tokens", value: 8000 },
        { label: "16k Tokens", value: 16000 },
        { label: "32k Tokens", value: 32000 }
    ];
    
    window.openPicker("Max Thinking Tokens", options, aichatActiveThread.thinking_tokens, async (val) => {
        aichatActiveThread.thinking_tokens = val;
        const label = val > 0 ? (val >= 1000 ? (val/1000)+'k' : val) : 'Off';
        document.getElementById('aichat-think-picker-btn').innerHTML = `<span style="color:var(--text-secondary)">Think:</span> <span style="color:var(--ai-accent)">${label}</span>`;
        await window.sui.api('aichat_update_thread_settings', {
            id: aichatActiveThread.id,
            model: aichatActiveThread.model,
            temperature: aichatActiveThread.temperature,
            system_prompt: aichatActiveThread.system_prompt,
            context_mode: aichatActiveThread.context_mode,
            context_folder_id: aichatActiveThread.context_folder_id,
            thinking_tokens: val
        }, { toast: "Thinking Limit Updated" });
    });
}

async function aichatOpenTempPicker() {
    if (!aichatActiveThread) return;
    const temps = [
        { label: "0.1 (Precise)", value: 0.1 },
        { label: "0.4 (Balanced)", value: 0.4 },
        { label: "0.7 (Creative)", value: 0.7 },
        { label: "1.0 (Wild)", value: 1.0 }
    ];
    
    window.openPicker("Select Temperature", temps, aichatActiveThread.temperature, async (val) => {
        aichatActiveThread.temperature = val;
        document.getElementById('aichat-temp-picker-btn').innerHTML = `<span style="color:var(--text-secondary)">Temp:</span> <span style="color:var(--ai-accent)">${val}</span>`;
        await window.sui.api('aichat_update_thread_settings', {
            id: aichatActiveThread.id,
            model: aichatActiveThread.model,
            temperature: val,
            system_prompt: aichatActiveThread.system_prompt
        }, { toast: "Temperature Updated" });
    });
}

// Auto-expand input
document.addEventListener('input', (e) => {
    if (e.target.id === 'aichat-input') {
        e.target.style.height = 'auto';
        e.target.style.height = e.target.scrollHeight + 'px';
    }
});

function aichatOpenSettings() {
    const currentLimit = aichatState.settings?.recent_limit || 5;
    const defModel = aichatState.settings?.default_model || 'google/gemini-2.0-flash-exp:free';
    const defTemp = aichatState.settings?.default_temperature !== undefined ? aichatState.settings.default_temperature : 0.7;
    const defPrompt = aichatState.settings?.default_system_prompt || 'You are a helpful assistant.';
    
    const content = `
        <div class="group-title">Account & Billing</div>
        <div class="settings-group" style="background:none; margin-bottom:12px;">
            <div id="as-credit-card-container">
                <div style="background:var(--card-bg); border-radius:20px; padding:20px; text-align:center; border:1px solid var(--border-color); box-shadow:var(--shadow-card); opacity: 0.6;">
                    <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">Loading Credits...</div>
                </div>
            </div>
        </div>

        <div class="group-title">Workspace</div>
        <div class="settings-group">
            <div class="setting-item vertical">
                <label class="setting-label">Recent Threads Limit</label>
                <div class="setting-desc">How many threads to show in the 'Recent Activity' section.</div>
                <div style="display:flex; align-items:center; gap:15px; margin-top:10px;">
                    ${window.suiSlider('as-recent-limit', 0, 20, 1, currentLimit, "document.getElementById('as-limit-val').innerText = this.value", '', 'var(--ai-accent)')}
                    <span id="as-limit-val" style="font-weight:800; color:var(--ai-accent); min-width:30px;">${currentLimit}</span>
                </div>
            </div>
        </div>

        <div class="group-title">Default Persona</div>
        <div class="settings-group">
            <div class="setting-item vertical">
                <label class="setting-label">Default Model</label>
                <button id="as-model-btn" data-model="${defModel}" onclick="aichatSettingsModelPicker()" style="width:100%; background:var(--input-bg); border:1px solid var(--border-color); border-radius:10px; padding:12px; font-size:13px; font-weight:600; text-align:left; margin-top:8px; color:var(--ai-accent); overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">
                    ${defModel.split('/').pop()}
                </button>
            </div>

            <div class="setting-item vertical" style="border-top:1px solid var(--border-color);">
                <label class="setting-label">Default Temperature: <span id="as-temp-val" style="color:var(--ai-accent);">${defTemp}</span></label>
                <div style="margin-top:10px;">
                    ${window.suiSlider('as-temp', 0, 1, 0.1, defTemp, "document.getElementById('as-temp-val').innerText = this.value", '', 'var(--ai-accent)')}
                </div>
            </div>

            <div class="setting-item vertical" style="border-top:1px solid var(--border-color);">
                <label class="setting-label">Default Thinking Limit: <span id="as-think-val" style="color:var(--ai-accent);">${aichatState.settings?.default_thinking || 0}</span></label>
                <div style="margin-top:10px;">
                    ${window.suiSlider('as-think', 0, 32000, 1000, aichatState.settings?.default_thinking || 0, "document.getElementById('as-think-val').innerText = this.value", '', 'var(--ai-accent)')}
                </div>
                <div style="font-size:10px; color:var(--text-secondary); margin-top:8px;">Max completion tokens for reasoning models (o1, DeepSeek). 0 = Off.</div>
            </div>

            <div class="setting-item vertical" style="border-top:1px solid var(--border-color);">
                <label class="setting-label">Default System Prompt</label>
                <textarea id="as-prompt" style="width:100%; min-height:120px; margin-top:10px; font-size:13px; line-height:1.5; padding:12px;">${defPrompt}</textarea>
            </div>
        </div>

        <div style="margin:30px 16px;">
            <button onclick="aichatSaveSettings()" class="btn-primary" style="width:100%; background:var(--ai-accent);">Save Studio Settings</button>
        </div>
    `;

    window.sui.openStudio({
        id: 'aichat-settings',
        title: 'Studio Configuration',
        content: content,
        onSetup: async (container) => {
            try {
                // Fetch billing data (thread_id not strictly needed for just credits, but API expects it)
                const data = await window.sui.api('aichat_get_billing', { thread_id: 'none' }, { toast: false });
                const remaining = data.credits.total_credits - data.credits.total_usage;
                const card = container.querySelector('#as-credit-card-container');
                if (card) {
                    card.innerHTML = `
                        <div style="background:var(--card-bg); border-radius:20px; padding:24px; text-align:center; border:1px solid var(--border-color); box-shadow:var(--shadow-card);">
                            <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1.5px; margin-bottom:8px;">Remaining Studio Credit</div>
                            <div style="font-size:32px; font-weight:900; color:var(--primary); font-family:'Courier New', monospace;">$${remaining.toFixed(4)}</div>
                            <div style="font-size:9px; color:var(--text-secondary); margin-top:8px; opacity:0.6;">OpenRouter API Balance</div>
                        </div>
                    `;
                }
            } catch(e) { console.error("Failed to load credits in settings", e); }
        }
    });
}

window.aichatSettingsModelPicker = function() {
    if (typeof window.openModelPicker === "function") {
        window.openModelPicker();
        const origSelect = window.selectModel;
        window.selectModel = (id) => {
            const btn = document.getElementById('as-model-btn');
            if (btn) {
                btn.dataset.model = id;
                btn.innerText = id.split('/').pop();
            }
            window.selectModel = origSelect;
            if (typeof window.closeAiManager === "function") window.closeAiManager();
        };
    }
};

async function aichatSaveSettings() {
    aichatState.settings.recent_limit = parseInt(document.getElementById('as-recent-limit').value);
    aichatState.settings.default_model = document.getElementById('as-model-btn').dataset.model;
    aichatState.settings.default_temperature = parseFloat(document.getElementById('as-temp').value);
    aichatState.settings.default_system_prompt = document.getElementById('as-prompt').value;
    aichatState.settings.default_thinking = parseInt(document.getElementById('as-think').value);
    
    await window.sui.api('aichat_save_settings', { settings: JSON.stringify(aichatState.settings) });
    window.sui.closeStudio('aichat-settings');
    renderAiChatLobby();
}

async function aichatOpenBilling() {
    if (!aichatActiveThread) return;
    
    window.sui.openStudio({
        id: 'aichat-billing',
        title: 'Thread Financials',
        content: `<div style="text-align:center; padding:40px;">${window.suiSpinner()}</div>`
    });

    try {
        const data = await window.sui.api('aichat_get_billing', { thread_id: aichatActiveThread.id }, { toast: false });
        const remaining = data.credits.total_credits - data.credits.total_usage;
        
        let html = `
            <div style="background:var(--card-bg); border-radius:20px; padding:24px; text-align:center; border:1px solid var(--border-color); margin-bottom:24px; box-shadow:var(--shadow-card);">
                <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1.5px; margin-bottom:8px;">Remaining Studio Credit</div>
                <div style="font-size:36px; font-weight:900; color:var(--primary); font-family:'Courier New', monospace;">$${remaining.toFixed(4)}</div>
                <div style="font-size:10px; color:var(--text-secondary); margin-top:8px; opacity:0.6;">Verified via OpenRouter API</div>
            </div>

            <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px; padding:0 4px;">Cost Breakdown</div>
            <div style="display:flex; flex-direction:column; gap:8px;">
        `;

        if (data.breakdown.length === 0) {
            html += `<div style="text-align:center; padding:40px; opacity:0.4; font-style:italic; font-size:13px;">No paid messages in this thread yet.</div>`;
        } else {
            data.breakdown.forEach(m => {
                const isGhost = m.message_id !== 'legacy_adj' && !data.active_ids.includes(m.message_id);
                const dateObj = new Date(m.timestamp * 1000);
                const dateStr = dateObj.toLocaleDateString([], {month:'short', day:'numeric'});
                const timeStr = dateObj.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
                
                const style = isGhost ? 'opacity:0.5; filter:grayscale(1);' : '';
                const title = isGhost ? 'Erased Message' : 'Active Message';
                const modelLabel = m.message_id === 'legacy_adj' ? 'Erased Messages Context' : m.model.split('/').pop();

                html += `
                    <div style="background:var(--card-bg); padding:12px 16px; border-radius:14px; border:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; ${style}">
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:12px; font-weight:700; color:var(--text-primary); ${isGhost ? 'text-decoration:line-through;' : ''}">${modelLabel}</div>
                            <div style="font-size:10px; color:var(--text-secondary);">${dateStr}, ${timeStr} • ${title}</div>
                        </div>
                        <div style="font-family:monospace; font-weight:700; color:var(--text-primary); font-size:13px;">$${m.cost_usd.toFixed(6)}</div>
                    </div>
                `;
            });
        }

        html += `
            </div>
            <div style="margin-top:24px; padding:20px; border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                <div style="font-weight:800; font-size:14px;">Thread Total</div>
                <div style="font-weight:900; font-size:18px; color:var(--primary);">$${aichatActiveThread.total_cost.toFixed(5)}</div>
            </div>
        `;

        const overlay = document.getElementById('sui-studio-aichat-billing');
        if (overlay) overlay.querySelector('.sui-studio-content').innerHTML = html;

    } catch(e) {
        const overlay = document.getElementById('sui-studio-aichat-billing');
        if (overlay) overlay.querySelector('.sui-studio-content').innerHTML = `<div style="color:var(--danger); padding:40px; text-align:center;">Failed to fetch billing data.</div>`;
    }
}

function aichatOpenFolderMenu(e, id) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const folder = aichatState.folders.find(f => f.id === id);
    if (!folder) return;

    window.sui.haptic('light');

    const threadCount = aichatState.threads.filter(t => t.folder_id === id).length;
    const canDelete = threadCount === 0;

    const options = [
        { label: "Rename Folder", value: "rename" },
        { 
            label: `<span style="color:${canDelete ? 'var(--danger)' : 'var(--text-secondary)'}">Delete Folder ${!canDelete ? '(Not Empty)' : ''}</span>`, 
            value: "delete" 
        }
    ];
    
    window.openPicker(`Folder: ${folder.name}`, options, null, (val) => {
        if (val === 'rename') {
            window.openInput("Rename Folder", "Name", folder.name, async (newName) => {
                if (!newName) return;
                await window.sui.api('aichat_save_folder', { id, name: newName, icon: folder.icon });
                aichatRefreshState();
            });
        } else if (val === 'delete') {
            if (!canDelete) {
                window.openConfirm(
                    "Folder Not Empty", 
                    `The folder "${folder.name}" contains ${threadCount} thread(s). You must move or delete these threads before the folder can be removed.`,
                    null, 
                    false, 
                    "Understood", 
                    null // Passing null hides the second button
                );
                return;
            }
            window.openConfirm("Delete Folder?", "Are you sure? This cannot be undone.", async () => {
                await window.sui.api('aichat_delete_folder', { id });
                if (aichatState.activeFolderId === id) aichatState.activeFolderId = null;
                aichatRefreshState();
            }, true);
        }
    });
}
JS;

$plugin_settings_map['AiChat'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">AiChat Studio</label>
        <div class="setting-desc">Conversation data is stored in the private Chat Bunker.</div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:4px; font-style:italic;">
            Swipe horizontally on the main screen to access the Studio.
        </div>
    </div>
HTML;

$plugin_js .= <<<'JS'
/**
 * AICHAT STUDIO CORE
 */
// Initialization handled by aichatInit via cjos-hydrated event.

function aichatUpdateHeaderActions() {
    const leftContainer = document.getElementById('aichat-header-actions');
    const rightContainer = document.getElementById('aichat-floating-right');
    if (!leftContainer || !rightContainer) return;

    // 1. Define Components
    const backBtn = `<button onclick="${aichatActiveThread ? 'aichatCloseThread()' : (aichatState.selectionMode ? 'aichatToggleSelectionMode(false)' : 'aichatSelectFolder(null)')}" class="icon-btn" style="background:var(--card-bg); color:var(--text-secondary); width:44px; height:44px; box-shadow:var(--shadow-card); border:1px solid var(--border-color);">
        <span data-sui-icon="chevron" data-sui-size="20" data-sui-stroke="3" style="transform: rotate(90deg);"></span>
    </button>`;

    const newChatBtn = `<button onclick="aichatCreateThread()" class="icon-btn" style="background:var(--card-bg); color:var(--ai-accent); width:44px; height:44px; box-shadow:var(--shadow-card); border:1px solid var(--border-color);">
        <span data-sui-icon="plus" data-sui-size="20" data-sui-stroke="3"></span>
    </button>`;

    const jumpBtn = `<button id="aichat-jump-btn" onclick="aichatScrollJump()" class="icon-btn" style="background:var(--card-bg); color:var(--primary); width:44px; height:44px; box-shadow:var(--shadow-card); border:1px solid var(--border-color);">
        <span id="aichat-jump-icon" data-sui-icon="chevron" data-sui-size="20" data-sui-stroke="3" style="transition: transform 0.3s;"></span>
    </button>`;

    const bulkDeleteBtn = `<button onclick="aichatBulkDelete()" class="icon-btn" style="background:var(--danger); color:white; width:auto; min-width:44px; height:44px; padding:0 12px; border-radius:14px; box-shadow:0 4px 12px rgba(255, 59, 48, 0.3); border:none; display:flex; align-items:center; justify-content:center; gap:6px;">
        <span data-sui-icon="trash" data-sui-size="20" data-sui-color="white"></span>
        <span style="font-size:12px; font-weight:700;">${aichatState.selectedThreads.length}</span>
    </button>`;

    // 2. Clear & Reset
    leftContainer.innerHTML = '';
    rightContainer.innerHTML = '';
    rightContainer.classList.remove('visible');

    // 3. Route Logic
    if (aichatActiveThread) {
        // THREAD VIEW: Back + New Chat (Left), Jump (Right)
        leftContainer.innerHTML = backBtn + newChatBtn;
        rightContainer.innerHTML = jumpBtn;
        rightContainer.classList.add('visible');
    } else if (aichatState.selectionMode) {
        // SELECTION MODE: Back (Left), Bulk Delete (Right)
        leftContainer.innerHTML = backBtn;
        rightContainer.innerHTML = bulkDeleteBtn;
        rightContainer.classList.add('visible');
    } else if (aichatState.activeFolderId) {
        // FOLDER VIEW: Back (Left)
        leftContainer.innerHTML = backBtn;
    } else {
        // STUDIO HOME: New Chat (Left)
        leftContainer.innerHTML = newChatBtn;
    }

    window.suiHydrateIcons(leftContainer);
    window.suiHydrateIcons(rightContainer);
}

async function aichatToggleDefaultPrompt(id, enabled) {
    if (!aichatState.settings.disabled_defaults) aichatState.settings.disabled_defaults = [];
    
    if (enabled) {
        aichatState.settings.disabled_defaults = aichatState.settings.disabled_defaults.filter(i => i !== id);
    } else {
        if (!aichatState.settings.disabled_defaults.includes(id)) aichatState.settings.disabled_defaults.push(id);
    }
    
    await window.sui.api('aichat_save_settings', { settings: JSON.stringify(aichatState.settings) }, { toast: false });
    aichatRefreshState();
}

function renderAiChatLobby() {
    const grid = document.getElementById('aichat-main-grid');
    if (!grid) return;

    const disabledIds = aichatState.settings?.disabled_defaults || [];
    const visiblePrompts = aichatState.prompts.filter(p => !disabledIds.includes(p.id));

    aichatUpdateHeaderActions();
    
    // Only toggle visibility if no thread is active
    if (!aichatActiveThread) {
        document.getElementById('aichat-welcome').style.display = 'block';
        document.getElementById('aichat-main-grid').style.display = 'grid';
        document.getElementById('aichat-chat-view').style.display = 'none';
    }

    if (aichatState.folders.length === 0) {
        grid.innerHTML = `
            <div style="text-align:center; padding:40px; border:2px dashed var(--border-color); border-radius:20px;">
                <p style="color:var(--text-secondary); margin-bottom:20px;">No folders found in the bunker.</p>
                <button onclick="aichatPromptCreateFolder()" class="btn-primary" style="background:var(--ai-accent);">
                    Create First Folder
                </button>
            </div>
        `;
        return;
    }

    // Navigation visibility is now fully handled by aichatUpdateHeaderActions()
    if (typeof window.srScan === 'function') setTimeout(() => window.srScan(grid), 50);

    if (!aichatState.activeFolderId) {
        // HOME VIEW: Pinned + Recent Activity + Folder Grid
        const pinnedThreads = aichatState.threads.filter(t => t.is_pinned);
        const limit = aichatState.settings?.recent_limit || 5;
        const recentThreads = [...aichatState.threads]
            .filter(t => !t.is_pinned) // Don't duplicate pinned items in recent
            .sort((a,b) => b.created_at - a.created_at)
            .slice(0, limit);

        let pinnedHtml = "";
        if (pinnedThreads.length > 0) {
            pinnedHtml = `
                <div style="font-size:11px; font-weight:800; color:var(--ai-accent); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                    <span data-sui-icon="star" data-sui-size="10" data-sui-color="var(--ai-accent)"></span> Pinned Chats
                </div>
                <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
                    ${pinnedThreads.map(t => aichatRenderThreadCard(t, true)).join('')}
                </div>
            `;
        }

        let recentHtml = "";
        if (recentThreads.length > 0) {
            recentHtml = `
                <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px;">
                    Recent activity
                </div>
                <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
                    ${recentThreads.map(t => aichatRenderThreadCard(t, true)).join('')}
                </div>
            `;
        }

        grid.innerHTML = `
            ${pinnedHtml}
            ${recentHtml}
            
            <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px;">
                Studio Folders
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:24px;">
                ${aichatState.folders.map(f => `
                    <div class="card" onclick="aichatSelectFolder('${f.id}')" oncontextmenu="aichatOpenFolderMenu(event, '${f.id}')" style="padding:20px; display:flex; flex-direction:column; align-items:center; gap:10px; cursor:pointer; text-align:center; user-select:none; -webkit-touch-callout:none;">
                        <div style="font-size:32px;">${f.icon}</div>
                        <div style="font-weight:700; font-size:14px; color:var(--text-primary);">${f.name}</div>
                        <div style="font-size:10px; color:var(--text-secondary);">${aichatState.threads.filter(t => t.folder_id === f.id).length} Threads</div>
                    </div>
                `).join('')}
                <div class="card" onclick="aichatPromptCreateFolder()" style="padding:20px; display:flex; flex-direction:column; align-items:center; gap:10px; cursor:pointer; text-align:center; border:2px dashed var(--border-color); background:transparent; box-shadow:none;">
                    <div style="font-size:32px; opacity:0.3;">➕</div>
                    <div style="font-weight:700; font-size:14px; opacity:0.5;">New Folder</div>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Prompt Gallery</div>
                <button onclick="aichatOpenGallery()" class="text-btn" style="font-size:11px; font-weight:700;">Manage</button>
            </div>
            <div style="display:flex; gap:12px; overflow-x:auto; padding-bottom:16px; margin-bottom:12px; -ms-overflow-style:none; scrollbar-width:none;">
                ${visiblePrompts.map(p => `
                    <div class="card" onclick="aichatPromptActionPicker('${p.id}')" style="flex:0 0 140px; padding:16px; display:flex; flex-direction:column; align-items:center; gap:8px; cursor:pointer; text-align:center;">
                        <div style="font-size:24px;">${p.icon}</div>
                        <div style="font-weight:700; font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; width:100%;">${p.title}</div>
                    </div>
                `).join('')}
                <div class="card" onclick="aichatEditPrompt(null)" style="flex:0 0 140px; padding:16px; display:flex; flex-direction:column; align-items:center; gap:8px; cursor:pointer; text-align:center; border:2px dashed var(--border-color); background:transparent; box-shadow:none;">
                    <div style="font-size:24px; opacity:0.3;">➕</div>
                    <div style="font-weight:700; font-size:12px; opacity:0.5;">Add New</div>
                </div>
            </div>
        `;
    } else {
        // FOLDER VIEW: Show Threads (Split Pinned/Others)
        const activeFolder = aichatState.folders.find(f => f.id === aichatState.activeFolderId);
        const threads = aichatState.threads.filter(t => t.folder_id === aichatState.activeFolderId);
        
        const pinned = threads.filter(t => t.is_pinned);
        const others = threads.filter(t => !t.is_pinned);

        let folderContent = "";
        
        if (threads.length === 0) {
            folderContent = `
                <div style="text-align:center; padding:60px 20px; opacity:0.4;">
                    <span data-sui-icon="search" data-sui-size="32"></span>
                    <div style="margin-top:10px; font-size:13px;">No active threads here.</div>
                </div>
            `;
        } else {
            if (pinned.length > 0) {
                folderContent += `
                    <div style="font-size:10px; font-weight:800; color:var(--ai-accent); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
                        <span data-sui-icon="star" data-sui-size="10" data-sui-color="var(--ai-accent)"></span> Pinned in Folder
                    </div>
                    <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
                        ${pinned.map(t => aichatRenderThreadCard(t, false)).join('')}
                    </div>
                `;
            }

            if (others.length > 0) {
                if (pinned.length > 0) {
                    folderContent += `<div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">Other Threads</div>`;
                }
                folderContent += `
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        ${others.map(t => aichatRenderThreadCard(t, false)).join('')}
                    </div>
                `;
            }
        }

        grid.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">
                    ${activeFolder ? activeFolder.name : 'Threads'} (${threads.length})
                </div>
                <button onclick="aichatCreateThread()" class="text-btn" style="font-size:12px; font-weight:700;">+ New Thread</button>
            </div>
            ${folderContent}
        `;
    }
    window.suiHydrateIcons(grid);
}

async function aichatCreateFirstFolder() {
    await window.sui.api('aichat_save_folder', { name: 'General', icon: '📦', color: 'var(--primary)', sort_order: 0 });
    const data = await window.sui.api('aichat_get_state', {}, { toast: false });
    renderAiChatLobby(data);
}

function aichatOpenGallery() {
    const disabledIds = aichatState.settings?.disabled_defaults || [];
    const defaults = aichatState.prompts.filter(p => p.is_default);
    const customs = aichatState.prompts.filter(p => !p.is_default);

    const renderItem = (p) => {
        const isDef = p.is_default;
        const isDisabled = disabledIds.includes(p.id);
        const opacity = isDisabled ? '0.5' : '1';
        
        return `
            <div class="card" style="padding:16px; display:flex; align-items:center; gap:16px; opacity:${opacity};">
                <div style="font-size:28px;">${p.icon}</div>
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:800; font-size:15px;">${p.title} ${isDef ? '<span style="font-size:9px; opacity:0.5; vertical-align:middle;">DEFAULT</span>' : ''}</div>
                    <div style="font-size:12px; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${p.content}</div>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    ${isDef ? `
                        <div data-sui-switch="true" data-sui-id="tg-${p.id}" data-sui-checked="${!isDisabled}" data-sui-onchange="aichatToggleDefaultPrompt('${p.id}', this.checked)"></div>
                    ` : `
                        <button onclick="aichatUsePrompt('${p.id}')" class="icon-btn" style="background:var(--ai-accent-bg); color:var(--ai-accent); width:36px; height:36px;"><span data-sui-icon="plus"></span></button>
                        <button onclick="aichatEditPrompt('${p.id}')" class="icon-btn" style="background:var(--btn-bg); color:var(--text-secondary); width:36px; height:36px;"><span data-sui-icon="edit"></span></button>
                    `}
                </div>
            </div>
        `;
    };

    const content = `
        <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:12px;">Custom Prompts</div>
        <div id="gallery-customs" style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
            ${customs.length ? customs.map(renderItem).join('') : '<div style="text-align:center; padding:20px; opacity:0.4; font-size:12px;">No custom prompts yet.</div>'}
            <button onclick="aichatEditPrompt(null)" class="text-btn" style="width:100%; background:var(--btn-bg); border-radius:12px; padding:12px; font-weight:700;">+ Create Custom Prompt</button>
        </div>

        <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:12px;">System Defaults</div>
        <div id="gallery-defaults" style="display:flex; flex-direction:column; gap:10px;">
            ${defaults.map(renderItem).join('')}
        </div>
    `;

    window.sui.openStudio({
        id: 'aichat-gallery',
        title: 'Prompt Gallery',
        content: content,
        onSetup: (container) => window.suiHydrateIcons(container)
    });
}

function aichatEditPrompt(id = null) {
    const p = id ? aichatState.prompts.find(x => x.id === id) : { title: '', content: '', icon: '💬', model: 'google/gemini-2.0-flash-exp:free', temperature: 0.7 };
    
    const content = `
        <div style="display:flex; flex-direction:column; gap:16px;">
            <div style="display:grid; grid-template-columns: 1fr 80px; gap:12px;">
                <div>
                    <label class="setting-label">Title</label>
                    <input type="text" id="ep-title" value="${p.title}" placeholder="e.g. Code Architect">
                </div>
                <div>
                    <label class="setting-label">Icon</label>
                    <input type="text" id="ep-icon" value="${p.icon}" placeholder="🏗️" style="text-align:center;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div>
                    <label class="setting-label">Default Model</label>
                    <button id="ep-model-btn" data-model="${p.model}" onclick="aichatPromptModelPicker()" style="width:100%; background:var(--input-bg); border:1px solid var(--border-color); border-radius:10px; padding:10px; font-size:12px; font-weight:600; text-align:left; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">
                        ${p.model.split('/').pop()}
                    </button>
                </div>
                <div>
                    <label class="setting-label">Temperature: <span id="ep-temp-val" style="color:var(--ai-accent);">${p.temperature}</span></label>
                    <input type="range" id="ep-temp" min="0" max="1" step="0.1" value="${p.temperature}" oninput="document.getElementById('ep-temp-val').innerText = this.value" style="margin-top:12px;">
                </div>
            </div>

            <div>
                <label class="setting-label">System Instructions</label>
                <textarea id="ep-content" style="width:100%; min-height:150px; font-size:14px; line-height:1.5;">${p.content}</textarea>
            </div>

            <div style="display:flex; gap:12px; margin-top:10px;">
                <button onclick="aichatSavePrompt('${id || ''}')" class="btn-primary" style="flex:1; background:var(--ai-accent);">Save Prompt</button>
                ${id ? `<button onclick="aichatDeletePrompt('${id}')" class="btn-primary danger" style="width:50px;"><span data-sui-icon="trash" data-sui-color="white"></span></button>` : ''}
            </div>
        </div>
    `;

    window.sui.openStudio({
        id: 'aichat-edit-prompt',
        title: id ? 'Edit Prompt' : 'New Prompt',
        content: content,
        onSetup: (container) => window.suiHydrateIcons(container)
    });
}

function aichatPromptModelPicker() {
    if (typeof window.openModelPicker === "function") {
        window.openModelPicker();
        const origSelect = window.selectModel;
        window.selectModel = (id) => {
            const btn = document.getElementById('ep-model-btn');
            if (btn) {
                btn.dataset.model = id;
                btn.innerText = id.split('/').pop();
            }
            window.selectModel = origSelect;
            if (typeof window.closeAiManager === "function") window.closeAiManager();
        };
    }
}

async function aichatSavePrompt(id) {
    const title = document.getElementById('ep-title').value;
    const icon = document.getElementById('ep-icon').value;
    const content = document.getElementById('ep-content').value;
    const model = document.getElementById('ep-model-btn').dataset.model;
    const temperature = document.getElementById('ep-temp').value;
    
    if (!title || !content) return;
    
    await window.sui.api('aichat_save_prompt', { id, title, icon, content, model, temperature });
    window.sui.closeStudio('aichat-edit-prompt');
    await aichatRefreshState();
    aichatOpenGallery(); // Refresh list
}

async function aichatDeletePrompt(id) {
    window.openConfirm("Delete Prompt?", "This cannot be undone.", async () => {
        await window.sui.api('aichat_delete_prompt', { id });
        window.sui.closeStudio('aichat-edit-prompt');
        await aichatRefreshState();
        aichatOpenGallery();
    }, true);
}

function aichatPromptActionPicker(id) {
    const p = aichatState.prompts.find(x => x.id === id);
    if (!p) return;

    const options = [
        { label: "🚀 Create New Chat", value: "create" }
    ];

    if (!p.is_default) {
        options.push({ label: "✍️ Edit Prompt Instructions", value: "edit" });
    }

    window.openPicker(p.title, options, null, (val) => {
        if (val === "create") aichatUsePrompt(id);
        if (val === "edit") aichatEditPrompt(id);
    });
}

async function aichatUsePrompt(promptId) {
    const p = aichatState.prompts.find(x => x.id === promptId);
    if (!p) return;

    // 1. Ensure we have a folder
    if (aichatState.folders.length === 0) {
        await window.sui.api('aichat_save_folder', { name: 'General', icon: '📦' });
        await aichatRefreshState();
    }
    
    const folderId = aichatState.activeFolderId || aichatState.folders[0].id;
    
    // 2. Create Thread
    const res = await window.sui.api('aichat_create_thread', {
        folder_id: folderId,
        title: p.title + " Chat",
        system_prompt: p.content,
        model: p.model,
        temperature: p.temperature
    });

    if (res.status === 'success') {
        // Close gallery if open
        window.sui.closeStudio('aichat-gallery');
        await aichatRefreshState();
        aichatOpenThread(res.id);
    }
}

async function aichatImportPromptFromGallery() {
    if (!aichatActiveThread) return;
    const disabledIds = aichatState.settings?.disabled_defaults || [];
    const options = aichatState.prompts
        .filter(p => !disabledIds.includes(p.id))
        .map(p => ({ label: `${p.icon} ${p.title}`, value: p.id }));

    window.openPicker("Import Persona", options, null, async (pid) => {
        const p = aichatState.prompts.find(x => x.id === pid);
        if (!p) return;

        const applyImport = async (overwriteConfig) => {
            aichatActiveThread.system_prompt = p.content;
            if (overwriteConfig) {
                aichatActiveThread.model = p.model || aichatActiveThread.model;
                aichatActiveThread.temperature = p.temperature !== undefined ? p.temperature : aichatActiveThread.temperature;
            }

            await window.sui.api('aichat_update_thread_settings', {
                id: aichatActiveThread.id,
                model: aichatActiveThread.model,
                temperature: aichatActiveThread.temperature,
                system_prompt: aichatActiveThread.system_prompt
            }, { toast: "Thread Persona Updated" });

            // Refresh UI Elements
            const input = document.getElementById('aichat-system-prompt-input');
            if (input) input.value = p.content;
            
            document.getElementById('aichat-model-picker-btn').innerHTML = `<span style="color:var(--text-secondary)">Model:</span> <span style="color:var(--ai-accent)">${aichatActiveThread.model.split('/').pop()}</span>`;
            document.getElementById('aichat-temp-picker-btn').innerHTML = `<span style="color:var(--text-secondary)">Temp:</span> <span style="color:var(--ai-accent)">${aichatActiveThread.temperature}</span>`;
            
            window.suiToggle('aichat-sp-acc'); 
        };

        // Check if prompt has distinct settings to offer
        if (p.model || p.temperature !== undefined) {
            window.openConfirm(
                "Apply Settings?",
                `The "${p.title}" prompt includes specific model (${p.model?.split('/').pop()}) and temperature (${p.temperature}) preferences. Apply them to this chat?`,
                () => applyImport(true),
                false,
                "Apply All",
                "Prompt Only",
                () => applyImport(false)
            );
        } else {
            applyImport(false);
        }
    });
}

async function aichatRefreshPayloadPreview() {
    if (!aichatActiveThread) return;
    const content = document.getElementById('aichat-payload-content');
    if (!content) return;
    
    content.innerHTML = `<div class="spinner" style="width:14px; height:14px; border-width:2px; margin:0;"></div> Gathering payload data...`;
    
    try {
        const data = await window.sui.api('aichat_get_payload_preview', { id: aichatActiveThread.id }, { toast: false });
        if (data && data.payload) {
            content.innerText = JSON.stringify(data.payload, null, 2);
        }
    } catch(e) {
        content.innerText = "Error loading payload: " + e.message;
    }
}
JS;