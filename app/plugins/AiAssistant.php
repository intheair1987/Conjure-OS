<?php
// ==============================================================================
// PLUGIN: AI Assistant
// DESCRIPTION: Autonomous Dispatcher & Agents.
// Orchestrates the AI Pipeline: Dispatcher -> Assistant -> Action.
// ==============================================================================

$ai_config_file = CJOS_PATH_DATA . '/ai-assistant-config.json';
$ai_assistants_file = CJOS_PATH_DATA . '/ai-assistants-list.json';

// --- CENTRAL VERB LIBRARY ---
$ai_verb_library = [
    "REPLACE_TEXT" => [
        "desc" => "Clean up transcript",
        "full" => 'Clean up and polish the transcription. Use this to fix grammar, remove filler words, or reformat text. IMPORTANT: If you are performing a DISCOVER or WEB_SEARCH action, DO NOT use REPLACE_TEXT in the same turn. Only provide the cleaned text on your FINAL TURN once all data is found. Format: {"type":"REPLACE_TEXT", "text":"..."}'
    ],
    "MOVE_FOLDER" => [
        "desc" => "File into folder",
        "full" => 'Move the note to a specific folder. Use the folder names provided in the context. Format: {"type":"MOVE_FOLDER", "target":"FolderName"}'
    ],
    "CREATE_TODO" => [
        "desc" => "Add to task list",
        "full" => 'Identify actionable tasks, future intents, or reminders and add them to a list. If the user mentions doing something in the future (e.g., "I am going to..."), it is a task. Use the list names provided in the context or create a new one if necessary. Format: {"type":"CREATE_TODO", "text":"Task description", "list":"ListName"}'
    ],
    "CREATE_NOTE" => [
        "desc" => "Plant new note",
        "full" => 'Create a secondary note. Offset controls order (negative = before, positive = after). Format: {"type":"CREATE_NOTE", "text":"...", "offset": -5}'
    ],
    "CREATE_FOOD" => [
        "desc" => "Add definition to DB",
        "full" => 'Add a new food definition to the database. Use this for new items or nutrition labels. \'calories\', \'protein\', etc. MUST be the TOTAL PACKAGE values. \'ref_calories\' MUST be the calories for the \'ref_amount_g\' (portion/100g). Format: {"type":"CREATE_FOOD", "data":{"name":"...", "calories":0, "protein":0, "fat":0, "sat_fat":0, "trans_fat":0, "carbs":0, "sugar":0, "sodium":0, "total_weight_g":0, "ref_amount_g":0, "ref_calories":0, "portion_name":"..."}}'
    ],
    "UPDATE_FOOD" => [
        "desc" => "Update existing food",
        "full" => 'Update an existing food definition. Requires a valid \'food_id\' from the context. You MUST provide the full set of nutrition fields even if only one is changing. Format: {"type":"UPDATE_FOOD", "food_id": 123, "data":{"name":"...", "calories":0, "protein":0, "fat":0, "sat_fat":0, "trans_fat":0, "carbs":0, "sugar":0, "sodium":0, "total_weight_g":0, "ref_amount_g":0, "ref_calories":0, "portion_name":"..."}}'
    ],
    "ADD_FOOD_LOG_DB" => [
        "desc" => "Log from Library",
        "full" => 'MANDATORY: Use this if a matching item exists in your context (Recent Items or Search Results). Requires a valid \'food_id\'. The \'multiplier\' represents the quantity of the base unit. Format: {"type":"ADD_FOOD_LOG_DB", "data":{"name":"...", "date":"YYYY-MM-DD", "food_id": 123, "meal":"breakfast|lunch|dinner|snack", "multiplier":1}}'
    ],
    "ADD_FOOD_LOG_MANUAL" => [
        "desc" => "Log Manual/Estimated",
        "full" => 'Log a manual item with estimated macros. Use this for items not in the database. All macro fields are MANDATORY (use 0 if unknown). Format: {"type":"ADD_FOOD_LOG_MANUAL", "data":{"name":"...", "date":"YYYY-MM-DD", "meal":"breakfast|lunch|dinner|snack", "multiplier":1, "calories":0, "protein":0, "fat":0, "sat_fat":0, "trans_fat":0, "carbs":0, "sugar":0, "sodium":0}}'
    ],
    "NOTIFY" => [
        "desc" => "Trigger sound/toast",
        "full" => 'Trigger a system notification or sound. Format: {"type":"NOTIFY", "message":"...", "type":"success|error|info"}'
    ],
    "WEB_SEARCH" => [
        "desc" => "Search the Web",
        "full" => 'Search the web for real-time information, news, or facts. Use this if DISCOVER fails to find a specific product or if you need external nutritional data. Format: {"type":"WEB_SEARCH", "query":"search keywords"}. If your context contains WEB SEARCH RESULTS and they are empty or irrelevant, you may try ONE more search with different keywords, otherwise estimate based on general knowledge.'
    ],
    "DISCUSS" => [
        "desc" => "Reply/Discuss",
        "full" => 'Speak directly to the user. Use this to ask questions, explain your reasoning, or provide information that doesn\'t fit into a database field. Format: {"type":"DISCUSS", "text":"..."}'
    ]
];

// DATA BRIDGE
$ai_bridge_data = [
    'config' => file_exists($ai_config_file) ? json_decode(file_get_contents($ai_config_file), true) : ['monitoring_enabled' => false, 'dispatcher_model' => '', 'dispatcher_prompt' => ''],
    'verbLibrary' => $ai_verb_library
];
$plugin_js .= "\nwindow.__AI_ASST_BRIDGE__ = " . json_encode($ai_bridge_data) . ";\n";

// Prevent PHP warnings from corrupting JSON responses
ini_set('display_errors', 0);

// --- 0. BACKEND TOOL ABSTRACTION ---
if (!function_exists('ai_log_event')) {
    function ai_log_event($logId, $type, $message, $details = '', $asstId = null) {
        global $db;
        try {
            $stmt = $db->prepare("INSERT INTO ai_audit_log (log_id, event_type, assistant_id, message, details, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$logId, $type, $asstId, $message, $details, time()]);
        } catch (Exception $e) {}
    }
}

if (!function_exists('ai_perform_discovery_internal')) {
    function ai_perform_discovery_internal($query) {
        $rawTerms = array_unique(array_filter(preg_split('/[\s,]+/u', $query)));
        $keywords = [];
        foreach ($rawTerms as $term) {
            if (preg_match('/[\x{4e00}-\x{9fa5}\x{3040}-\x{30ff}\x{31f0}-\x{31ff}]/u', $term)) {
                $chars = preg_split('//u', $term, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($chars as $c) $keywords[] = $c;
            } else {
                $keywords[] = $term;
            }
        }
        $keywords = array_unique(array_filter($keywords));
        if (empty($keywords)) return [];
        
        try {
            $fdb_path = CJOS_PATH_DATA . '/food-database/food.db';
            if (!file_exists($fdb_path)) return [];
            $tmpFdb = new PDO("sqlite:$fdb_path");
            
            $scoreSql = [];
            $params = [];
            foreach ($keywords as $k) {
                $scoreSql[] = "CASE WHEN name LIKE ? THEN 1 ELSE 0 END";
                $params[] = "%$k%";
            }
            $scoreExpr = "(" . implode(" + ", $scoreSql) . ")";
            
            $minScore = (count($keywords) > 1) ? 2 : 1;
            
            $sql = "SELECT *, $scoreExpr as match_score FROM cal_foods 
                    WHERE $scoreExpr >= $minScore 
                    ORDER BY match_score DESC, name ASC 
                    LIMIT 60";
            
            $stmt = $tmpFdb->prepare($sql);
            $stmt->execute(array_merge($params, $params));
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Feedback: Detect Query Stuffing (No commas but many words)
            if (count($keywords) > 6 && strpos($query, ',') === false) {
                array_unshift($results, ['SYSTEM_WARNING' => 'QUERY STUFFING DETECTED. You searched for too many words without commas. These results are likely low-relevance noise. Re-search using commas for distinct items.']);
            }

            if (empty($results) && $minScore > 1) {
                $sql = "SELECT *, $scoreExpr as match_score FROM cal_foods 
                        WHERE $scoreExpr >= 1 
                        ORDER BY match_score DESC, name ASC 
                        LIMIT 60";
                $stmt = $tmpFdb->prepare($sql);
                $stmt->execute(array_merge($params, $params));
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($results as &$f) { foreach ($f as $k => $v) { if (is_numeric($v) && strpos($v, '.') !== false) $f[$k] = round((float)$v, 2); } }
            return $results;
        } catch(Exception $e) { 
            return []; 
        }
    }
}

if (!function_exists('ai_execute_backend_action')) {
    function ai_execute_backend_action($logId, $action) {
        global $db;
        $type = $action['type'] ?? '';
        
        try {
            if ($type === 'REPLACE_TEXT') {
                $newText = $action['text'] ?? '';
                $stmt = $db->prepare("SELECT transcription FROM logs WHERE id = ?");
                $stmt->execute([$logId]);
                $oldText = $stmt->fetchColumn();
                $db->prepare("UPDATE logs SET transcription = ?, original_text = ? WHERE id = ?")
                   ->execute([$newText, $oldText, $logId]);
                ai_log_event($logId, 'ACTION', "Replaced text", mb_strimwidth($newText, 0, 50, "..."));
            }
            elseif ($type === 'MOVE_FOLDER') {
                $target = $action['target'] ?? '';
                $stmt = $db->prepare("SELECT id FROM folders WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $stmt->execute([$target]);
                $folderId = $stmt->fetchColumn();
                
                if (!$folderId) {
                    $db->prepare("INSERT INTO folders (name, created_at, updated_at) VALUES (?, ?, ?)")
                       ->execute([$target, time(), time()]);
                    $folderId = $db->lastInsertId();
                }
                
                $db->prepare("DELETE FROM folder_map WHERE log_id = ?")->execute([$logId]);
                $db->prepare("INSERT INTO folder_map (log_id, folder_id) VALUES (?, ?)")->execute([$logId, $folderId]);
                $db->prepare("UPDATE folders SET updated_at = ? WHERE id = ?")->execute([time(), $folderId]);
                ai_log_event($logId, 'ACTION', "Moved to folder: $target");
            }
            elseif ($type === 'CREATE_NOTE') {
                $text = $action['text'] ?? '';
                $offset = (int)($action['offset'] ?? 0);
                
                $stmt = $db->prepare("SELECT timestamp FROM logs WHERE id = ?");
                $stmt->execute([$logId]);
                $refTs = $stmt->fetchColumn() ?: time();
                
                $stmtF = $db->prepare("SELECT folder_id FROM folder_map WHERE log_id = ?");
                $stmtF->execute([$logId]);
                $folderId = $stmtF->fetchColumn();
                
                $newTs = $refTs + $offset;
                $newId = date('Ymd_His', $newTs);
                while ($db->query("SELECT COUNT(*) FROM logs WHERE id = '$newId'")->fetchColumn() > 0) {
                    $newTs++;
                    $newId = date('Ymd_His', $newTs);
                }
                
                $dateDisp = date('Y-m-d H:i:s', $newTs);
                $db->prepare("INSERT INTO logs (id, date_display, audio_path, transcription, timestamp) VALUES (?, ?, 'text_only', ?, ?)")
                   ->execute([$newId, $dateDisp, $text, $newTs]);
                   
                if ($folderId) {
                    $db->prepare("INSERT INTO folder_map (log_id, folder_id) VALUES (?, ?)")->execute([$newId, $folderId]);
                }
                ai_log_event($logId, 'ACTION', "Created new note", "New ID: $newId");
            }
            elseif ($type === 'CREATE_TODO') {
                $listName = trim($action['list'] ?? 'AI Tasks');
                $taskText = $action['text'] ?? '';
                
                $stmtCheck = $db->prepare("SELECT id FROM todo_lists WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $stmtCheck->execute([$listName]);
                $listId = $stmtCheck->fetchColumn();

                if (!$listId) {
                    $db->prepare("INSERT INTO todo_lists (name, created_at, sort_order) VALUES (?, ?, ?)")
                       ->execute([$listName, time(), 0]);
                    $listId = $db->lastInsertId();
                }

                $maxQ = $db->prepare("SELECT MAX(sort_order) FROM todo_items WHERE list_id = ?");
                $maxQ->execute([$listId]);
                $nextSort = ($maxQ->fetchColumn() ?: 0) + 1;

                $db->prepare("INSERT INTO todo_items (list_id, log_id, sort_order, task_text) VALUES (?, ?, ?, ?)")
                   ->execute([$listId, $logId, $nextSort, $taskText]);
                ai_log_event($logId, 'ACTION', "Added To-Do", "List: $listName, Task: $taskText");
            }
            elseif ($type === 'CREATE_FOOD' || $type === 'UPDATE_FOOD') {
                $fdb_path = CJOS_PATH_DATA . '/food-database/food.db';
                if (file_exists($fdb_path)) {
                    $fdb = new PDO("sqlite:$fdb_path");
                    $d = $action['data'] ?? [];
                    $pn = trim($d['portion_name'] ?? '');
                    if ($pn === '' || strtolower($pn) === 'null') $pn = null;
                    
                    if ($type === 'CREATE_FOOD') {
                        $stmt = $fdb->prepare("INSERT INTO cal_foods (name, calories, protein, fat, sat_fat, trans_fat, carbs, sugar, sodium, total_weight_g, ref_amount_g, ref_calories, portion_name, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $d['name'], $d['calories'], $d['protein'] ?? 0, $d['fat'] ?? 0, $d['sat_fat'] ?? 0, $d['trans_fat'] ?? 0,
                            $d['carbs'] ?? 0, $d['sugar'] ?? 0, $d['sodium'] ?? 0, $d['total_weight_g'] ?? 0, $d['ref_amount_g'] ?? 100,
                            $d['ref_calories'] ?? 0, $pn, time()
                        ]);
                        ai_log_event($logId, 'ACTION', "Created Food", $d['name']);
                    } else {
                        $stmt = $fdb->prepare("UPDATE cal_foods SET name=?, calories=?, protein=?, fat=?, sat_fat=?, trans_fat=?, carbs=?, sugar=?, sodium=?, total_weight_g=?, ref_amount_g=?, ref_calories=?, portion_name=?, updated_at=? WHERE id=?");
                        $stmt->execute([
                            $d['name'], $d['calories'], $d['protein'] ?? 0, $d['fat'] ?? 0, $d['sat_fat'] ?? 0, $d['trans_fat'] ?? 0,
                            $d['carbs'] ?? 0, $d['sugar'] ?? 0, $d['sodium'] ?? 0, $d['total_weight_g'] ?? 0, $d['ref_amount_g'] ?? 100,
                            $d['ref_calories'] ?? 0, $pn, time(), $action['food_id']
                        ]);
                        ai_log_event($logId, 'ACTION', "Updated Food", $d['name']);
                    }
                }
            }
            elseif (in_array($type, ['ADD_FOOD_LOG', 'ADD_FOOD_LOG_DB', 'ADD_FOOD_LOG_MANUAL'])) {
                $cal_db_path = CJOS_PATH_DATA . '/calorie-tracker/logs.db';
                if (file_exists($cal_db_path)) {
                    $cal_db = new PDO("sqlite:$cal_db_path");
                    $d = $action['data'] ?? [];
                    
                    if ($type === 'ADD_FOOD_LOG_DB' && !empty($d['food_id'])) {
                        $fdb_path = CJOS_PATH_DATA . '/food-database/food.db';
                        if (file_exists($fdb_path)) {
                            $fdb = new PDO("sqlite:$fdb_path");
                            $stmt = $fdb->prepare("SELECT * FROM cal_foods WHERE id = ?");
                            $stmt->execute([$d['food_id']]);
                            $food = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($food) {
                                $m = (float)($d['multiplier'] ?? 1);
                                $ratio = ($food['calories'] > 0) ? ($food['ref_calories'] / $food['calories']) : 0;
                                $d['calories'] = round($food['ref_calories'] * $m);
                                $d['protein'] = round($food['protein'] * $ratio * $m, 1);
                                $d['fat'] = round($food['fat'] * $ratio * $m, 1);
                                $d['sat_fat'] = round($food['sat_fat'] * $ratio * $m, 1);
                                $d['trans_fat'] = round($food['trans_fat'] * $ratio * $m, 1);
                                $d['carbs'] = round($food['carbs'] * $ratio * $m, 1);
                                $d['sugar'] = round($food['sugar'] * $ratio * $m, 1);
                                $d['sodium'] = round($food['sodium'] * $ratio * $m, 1);
                                $d['portion_name'] = $food['portion_name'];
                                $d['ref_amount_g'] = $food['ref_amount_g'];
                                $d['name'] = $food['name'];
                            }
                        }
                    }
                    
                    $date = $d['date'] ?? date('Y-m-d');
                    $pn = (isset($d['portion_name']) && $d['portion_name'] !== 'null' && $d['portion_name'] !== '') ? $d['portion_name'] : null;
                    
                    $stmt = $cal_db->prepare("INSERT INTO cal_logs (date_ref, meal_type, food_name, calories, protein, fat, sat_fat, trans_fat, carbs, sugar, sodium, portion_name, multiplier, mode, ref_amount_g, log_timestamp, food_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $date, $d['meal'] ?? 'snack', $d['name'] ?? 'Unknown', (int)($d['calories'] ?? 0),
                        (float)($d['protein'] ?? 0), (float)($d['fat'] ?? 0), (float)($d['sat_fat'] ?? 0), (float)($d['trans_fat'] ?? 0),
                        (float)($d['carbs'] ?? 0), (float)($d['sugar'] ?? 0), (float)($d['sodium'] ?? 0),
                        $pn, (float)($d['multiplier'] ?? 1), $d['mode'] ?? 'portion', (int)($d['ref_amount_g'] ?? 0), time(),
                        (!empty($d['food_id']) ? (int)$d['food_id'] : null)
                    ]);
                    ai_log_event($logId, 'ACTION', "Logged Food", $d['name'] . " (" . ($d['calories'] ?? 0) . " cal)");
                }
            }
        } catch (Exception $e) {
            ai_log_event($logId, 'ERROR', "Action Execution Failed ($type)", $e->getMessage());
        }
    }
}

if (!function_exists('ai_run_autonomous_pipeline')) {
    function ai_run_autonomous_pipeline($logId, $correctionText = null, $isManual = false) {
        global $db;

        // Ensure WebSearch is loaded since AiAssistant runs before it alphabetically
        if (file_exists(CJOS_PATH_PLUGINS . '/WebSearch.php')) {
            include_once CJOS_PATH_PLUGINS . '/WebSearch.php';
        }

        // 1. FETCH LOG & READINESS CHECK (Priority 1)
        $stmt = $db->prepare("SELECT transcription, original_text, ai_processed FROM logs WHERE id = ?");
        $stmt->execute([$logId]);
        $logRow = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$logRow) return false;
        
        $text = trim($logRow['transcription'] ?? '');
        $isPlaceholder = (
            $text === "" || 
            strpos($text, '(') === 0 || 
            strpos($text, 'Pending') !== false || 
            strpos($text, 'Transcribing') !== false
        );
        
        if ($isPlaceholder && !$isManual) {
            return 'not_ready';
        }

        // 2. STATE GUARD: Check if already processed, ghosted, or has a pending suggestion
        $stmtSugg = $db->prepare("SELECT COUNT(*) FROM ai_suggestions WHERE log_id = ?");
        $stmtSugg->execute([$logId]);
        $hasSuggestion = $stmtSugg->fetchColumn() > 0;

        // If already processed (2) or ghosted (0) or has suggestion, don't auto-run
        if (($logRow['ai_processed'] == 2 || $logRow['ai_processed'] == 0 || $hasSuggestion) && !$correctionText && !$isManual) {
            return 'already_done';
        }

        // 3. GLOBAL SYSTEM LOCK: AI Pipeline respects the lock but does NOT hold it.
        // This ensures a new transcription can always start even if AI is researching.
        $globalLock = CJOS_PATH_DATA . "/system-busy.lock";
        if (file_exists($globalLock) && (time() - filemtime($globalLock) < 120)) {
            return 'system_busy';
        }
        
        // 4. PER-NOTE LOCK: Prevents multiple AI runs for the same note.
        $lockFile = CJOS_PATH_DATA . "/ai-lock-$logId.tmp";
        if (file_exists($lockFile) && (time() - filemtime($lockFile) < 60)) {
            return 'locked';
        }
        file_put_contents($lockFile, time());
        
        register_shutdown_function(function() use ($lockFile) { 
            if(file_exists($lockFile)) unlink($lockFile); 
        });

        ai_log_event($logId, 'INFO', "Pipeline Started", "Manual: " . ($isManual ? 'Yes' : 'No'));
        $rawText = $text;
        $rawText = $logRow['transcription'];

        // 2. Load Configs
        $ai_config_file = CJOS_PATH_DATA . '/ai-assistant-config.json';
        $ai_assistants_file = CJOS_PATH_DATA . '/ai-assistants-list.json';
        $or_priv = CJOS_PATH_DATA . '/openrouter-private.json';

        $ai_conf = file_exists($ai_config_file) ? json_decode(file_get_contents($ai_config_file), true) : [];
        $assistants = file_exists($ai_assistants_file) ? json_decode(file_get_contents($ai_assistants_file), true) : [];
        $activeAssistants = array_filter($assistants, function($a) { return ($a['is_active'] ?? 1) == 1; });

        $or_settings = ['api_key' => '', 'model' => 'openai/gpt-3.5-turbo'];
        if (file_exists($or_priv)) {
            $privData = json_decode(file_get_contents($or_priv), true);
            if (isset($privData['api_key'])) $or_settings['api_key'] = $privData['api_key'];
        }
        
        if (empty($or_settings['api_key'])) {
            ai_log_event($logId, 'ERROR', "Pipeline Aborted", "Missing OpenRouter API Key");
            return false;
        }

        // 3. DISPATCHER
        $matchedId = "NONE";
        if (!empty($ai_conf['mechanical_mode'])) {
            $textLower = mb_strtolower($rawText);
            foreach ($activeAssistants as $a) {
                $name = mb_strtolower($a['name'] ?? '');
                $nick = mb_strtolower($a['nickname'] ?? '');
                if ((!empty($name) && strpos($textLower, $name) !== false) || (!empty($nick) && strpos($textLower, $nick) !== false)) {
                    $matchedId = $a['id']; break;
                }
                if (!empty($a['trigger_phrases'])) {
                    foreach (explode(',', $a['trigger_phrases']) as $t) {
                        $t = trim(mb_strtolower($t));
                        if (!empty($t) && strpos($textLower, $t) !== false) { $matchedId = $a['id']; break 2; }
                    }
                }
            }
        } else {
            $dispatchModel = !empty($ai_conf['dispatcher_model']) ? $ai_conf['dispatcher_model'] : $or_settings['model'];
            $dispatchPrompt = !empty($ai_conf['dispatcher_prompt']) ? $ai_conf['dispatcher_prompt'] : "You are a routing system. Return ONLY the ID of the assistant. If none match, return 'NONE'.";
            $listStr = "";
            foreach($activeAssistants as $a) { 
                $info = ["ID: {$a['id']}"];
                if (($ai_conf['include_name'] ?? true) !== false) $info[] = "NAME: {$a['name']}";
                if (($ai_conf['include_nickname'] ?? true) !== false && !empty($a['nickname'])) $info[] = "NICKNAME: {$a['nickname']}";
                if (($ai_conf['include_role'] ?? true) !== false) $info[] = "ROLE: {$a['role_desc']}";
                if (($ai_conf['include_prompt'] ?? false) === true) $info[] = "PROMPT: {$a['prompt']}";
                if (($ai_conf['include_triggers'] ?? true) !== false && !empty($a['trigger_phrases'])) $info[] = "TRIGGERS: {$a['trigger_phrases']}";
                $listStr .= implode(" | ", $info) . "\n"; 
            }

            $payload = [
                'model' => $dispatchModel,
                'messages' => [['role' => 'system', 'content' => "CURRENT_TIME: " . date('Y-m-d H:i:s') . "\n\n" . $dispatchPrompt . "\n\nASSISTANTS:\n" . $listStr], ['role' => 'user', 'content' => $rawText]],
                'temperature' => 0.1
            ];
            
            $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $or_settings['api_key'], "Content-Type: application/json"]);
            $res = curl_exec($ch); curl_close($ch);
            $json = json_decode($res, true);
            
            if (is_array($json) && isset($json['choices'][0]['message']['content'])) {
                $matchedId = trim($json['choices'][0]['message']['content']);
                $matchedId = preg_replace('/^[`\s]+|[`\s]+$/', '', $matchedId);
                $matchedId = preg_replace('/^ID:\s*/i', '', $matchedId);
            }
        }

        if (strpos(strtoupper($matchedId), 'NONE') !== false) {
            $db->prepare("UPDATE logs SET ai_processed = 0 WHERE id = ?")->execute([$logId]);
            ai_log_event($logId, 'GHOST', "No match", "AI: " . $matchedId);
            return true;
        }

        $assistant = null;
        foreach($assistants as $a) { if($a['id'] === $matchedId) $assistant = $a; }
        if (!$assistant) {
            ai_log_event($logId, 'ERROR', "Dispatcher Failed", "Matched ID not found: $matchedId");
            return false;
        }

        ai_log_event($logId, 'INFO', "Dispatcher Matched", "Assistant: {$assistant['name']}", $matchedId);

        // 4. ASSISTANT LOOP
        $asModel = !empty($assistant['model_override']) ? $assistant['model_override'] : $or_settings['model'];
        $ctxCfg = $assistant['context_config'] ?? ['food' => false, 'folders' => true, 'todo' => true];
        $verbsCfg = $assistant['verbs_config'] ?? [];
        $maxTurns = (int)($assistant['max_turns'] ?? 2);
        
        $turnHistory = [];
        $discoveryResults = $_POST['existing_research'] ?? null;

        if ($correctionText && !$discoveryResults) {
            $stmtCtx = $db->prepare("SELECT discovery_context FROM ai_suggestions WHERE log_id = ? LIMIT 1");
            $stmtCtx->execute([$logId]);
            $discoveryResults = $stmtCtx->fetchColumn() ?: null;
        }

        $finalActions = [];
        $finalCleanedText = $rawText;
        
        // --- STATEFUL MESSAGE THREAD ---
        $messages = [];

        for ($turn = 1; $turn <= $maxTurns; $turn++) {
            $turnOptions = [
                'current_turn' => $turn,
                'max_turns' => $maxTurns,
                'turn_history' => $turnHistory,
                'correction_text' => $correctionText
            ];

            $asPrompt = ai_build_system_prompt($assistant, $ctxCfg, $verbsCfg, $discoveryResults, $turnOptions);
            
            // Always update System Prompt to reflect latest Turn Instructions/Results
            if ($turn === 1) {
                $messages[] = ['role' => 'system', 'content' => $asPrompt];
                $userContent = $rawText;
                if ($correctionText) {
                    $userContent = "--- USER CORRECTION (PRIORITY) ---\n" . $correctionText . "\n\n--- ORIGINAL TRANSCRIPTION (DEPRECATED) ---\n" . $rawText;
                }
                $messages[] = ['role' => 'user', 'content' => $userContent];
            } else {
                $messages[0]['content'] = $asPrompt;
            }

            $payload = [
                'model' => $asModel,
                'messages' => $messages,
                'temperature' => (float)($assistant['temperature'] ?? 0.7)
            ];

            $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $or_settings['api_key'], "Content-Type: application/json"]);
            $res = curl_exec($ch); curl_close($ch);

            $json = json_decode($res, true);
            if (!is_array($json) || isset($json['error']) || !isset($json['choices'][0]['message']['content'])) {
                $errDetail = (is_array($json) && isset($json['error']['message'])) ? $json['error']['message'] : 'Empty/Invalid API Response';
                // If API returned a raw string error (non-JSON), capture the raw response
                $details = is_array($json) ? $errDetail : mb_strimwidth($res, 0, 500, '...');
                ai_log_event($logId, 'ERROR', "Assistant API Failed", $details, $matchedId);
                return false;
            }

            $rawResult = $json['choices'][0]['message']['content'];
            
            // Log exactly what the AI said this turn for debugging
            ai_log_event($logId, 'DEBUG', "Assistant Turn $turn Response", $rawResult, $matchedId);
            
            // Add Assistant's own words to the thread so it has "memory"
            $messages[] = ['role' => 'assistant', 'content' => $rawResult];
            $resultData = null;
            if (preg_match('/(\{[\s\S]*\})/s', $rawResult, $matches)) { 
                // Strip control characters EXCEPT newline, carriage return, and tab
                $jsonStr = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $matches[1]);
                // Remove trailing commas
                $jsonStr = preg_replace('/,\s*([\]\}])/', '$1', $jsonStr);
                // Fix malformed unicode escapes (e.g. Gemini glitch: \u7碗)
                $jsonStr = preg_replace('/\\\\u[0-9a-fA-F]{0,3}(?![0-9a-fA-F])/i', '', $jsonStr);
                
                $resultData = json_decode($jsonStr, true);
                if (!$resultData) {
                    // Fallback: escape literal newlines and tabs inside strings
                    $fixedJson = preg_replace_callback('/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/s', function($m) {
                        return '"' . str_replace(["\n", "\r", "\t"], ["\\n", "\\r", "\\t"], $m[1]) . '"';
                    }, $jsonStr);
                    $resultData = json_decode($fixedJson, true);
                }
            }

            if (!$resultData) {
                // Log the full raw result so you can see exactly what broke the parser
                ai_log_event($logId, 'ERROR', "Bad JSON from Assistant", $rawResult, $matchedId);
                return false;
            }

            $cleaned = $resultData['cleaned_text'] ?? $rawText;
            $actions = is_array($resultData['actions'] ?? null) ? $resultData['actions'] : [];
            $finalCleanedText = $cleaned;

            $currentTurnData = [
                'turn' => $turn,
                'actions' => $actions,
                'results' => null,
                'time' => date('H:i:s')
            ];

            $searchActions = array_filter($actions, function($a) {
                return in_array(strtoupper($a['type'] ?? ''), ['DISCOVER', 'WEB_SEARCH', 'SEARCH']);
            });

            if (!empty($searchActions) && $turn < $maxTurns) {
                $turnResults = [];
                foreach ($searchActions as $act) {
                    $type = strtoupper($act['type'] ?? '');
                    if ($type === 'WEB_SEARCH') {
                        if (function_exists('ws_perform_search_internal')) {
                            // Resilience: Increase limits for heavy research
                            @ini_set('memory_limit', '512M');
                            
                            $wsConfPath = CJOS_PATH_DATA . '/web-search-config.json';
                            $wsConf = file_exists($wsConfPath) ? json_decode(file_get_contents($wsConfPath), true) : [];
                            $provider = !empty($wsConf['provider']) ? $wsConf['provider'] : 'ddg';
                            
                            $searchRes = ws_perform_search_internal($act['query'] ?? '', $provider, true, true);
                            $results = $searchRes['results'] ?? [];
                            $count = count($results);
                            
                            $resStr = ($count > 0) ? json_encode(array_map(function($r){ 
                                return [
                                    'title' => $r['title'] ?? 'Untitled',
                                    'url' => $r['url'] ?? '',
                                    'snippet' => $r['snippet'] ?? '',
                                    'content' => mb_strimwidth($r['prefetched_content'] ?? '', 0, 3000, '...') 
                                ]; 
                            }, $results), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : "No web results found.";
                            
                            $turnResults[] = "--- WEB SEARCH RESULTS ---\nQUERY: {$act['query']}\nRESULTS: $resStr";
                            
                            $debugStr = isset($searchRes['debug']) ? json_encode($searchRes['debug'], JSON_UNESCAPED_UNICODE) : '';
                            ai_log_event($logId, 'INFO', "Web Search Executed", "Query: {$act['query']} | Found: $count | Provider: $provider" . ($count === 0 ? " | Debug: $debugStr" : ""), $matchedId);
                            
                            // Prevent API rate-limiting on multiple searches
                            if (count($searchActions) > 1) usleep(500000); 
                        }
                    } elseif ($type === 'DISCOVER') {
                        $searchRes = ai_perform_discovery_internal($act['query'] ?? '');
                        $count = count($searchRes);
                        $resStr = ($count > 0) ? json_encode($searchRes) : "No matching items found.";
                        $turnResults[] = "--- SEARCH RESULTS ---\nQUERY: {$act['query']}\nRESULTS: $resStr";
                        ai_log_event($logId, 'INFO', "DB Discovery Executed", "Query: {$act['query']} | Found: $count", $matchedId);
                    }
                }

                // ALWAYS proceed to next turn if search was requested, even if results are empty
                $finalResults = !empty($turnResults) ? implode("\n\n", $turnResults) : "--- RESEARCH RESULTS ---\nNo results found for your query.";
                
                $currentTurnData['results'] = $finalResults;
                $turnHistory[] = $currentTurnData;
                $discoveryResults = ($discoveryResults ? $discoveryResults . "\n\n" : "") . $finalResults;
                
                // Add the results as a "User" message to the thread
                $messages[] = ['role' => 'user', 'content' => $finalResults . "\n\nINSTRUCTION: Use these results to fulfill the request. If you have enough info, provide your final actions now. If not, you may search again."];
                
                ai_log_event($logId, 'INFO', "Research Handoff Triggered", "Turn $turn results provided to thread.", $matchedId);
                continue; // Loop to next turn
            }

            $turnHistory[] = $currentTurnData;
            $finalActions = $actions;
            break;
        }

        // --- BACKEND PERSISTENCE ENGINE ---
        // Ensure substantive actions from previous turns are not lost if the AI forgot to re-include them.
        $historicalSubstantive = [];
        foreach ($turnHistory as $turn) {
            foreach ($turn['actions'] as $act) {
                $t = strtoupper($act['type'] ?? '');
                // Only track substantive actions (exclude research/chat)
                if (!in_array($t, ['DISCOVER', 'WEB_SEARCH', 'SEARCH', 'DISCUSS'])) {
                    // Create a unique key based on type and the primary target/name
                    $name = $act['data']['name'] ?? $act['target'] ?? $act['text'] ?? '';
                    $key = $t . '|' . trim(mb_strtolower($name));
                    $historicalSubstantive[$key] = $act;
                }
            }
        }

        // Merge missing historical actions into the final set
        foreach ($historicalSubstantive as $key => $hAct) {
            $found = false;
            foreach ($finalActions as $fAct) {
                $fName = $fAct['data']['name'] ?? $fAct['target'] ?? $fAct['text'] ?? '';
                $fKey = strtoupper($fAct['type'] ?? '') . '|' . trim(mb_strtolower($fName));
                if ($key === $fKey) { $found = true; break; }
            }
            if (!$found) {
                $finalActions[] = $hAct;
                ai_log_event($logId, 'INFO', "Persistence Engine Recovered Action", ($hAct['data']['name'] ?? $hAct['type']), $matchedId);
            }
        }

        // 5. COMMIT PHASE
        $substantiveActions = array_filter($finalActions, function($a) {
            return !in_array(strtoupper($a['type'] ?? ''), ['DISCOVER', 'WEB_SEARCH', 'SEARCH', 'DISCUSS']);
        });

        if (!empty($substantiveActions)) {
            $finalActions = array_filter($finalActions, function($a) {
                return !in_array(strtoupper($a['type'] ?? ''), ['DISCOVER', 'WEB_SEARCH', 'SEARCH']);
            });
        }

        if ($assistant['commit_mode'] === 'direct') {
            $db->prepare("DELETE FROM ai_suggestions WHERE log_id = ?")->execute([$logId]);
            $db->prepare("UPDATE logs SET transcription = ?, original_text = ?, ai_processed = 2, ai_assistant_id = ? WHERE id = ?")
               ->execute([$finalCleanedText, $rawText, $matchedId, $logId]);
            
            foreach ($finalActions as $act) {
                ai_execute_backend_action($logId, $act);
            }
            ai_log_event($logId, 'SUCCESS', "Direct Commit Applied", "Executed " . count($finalActions) . " actions.", $matchedId);
        } else {
            $stmtCtx = $db->prepare("SELECT discovery_context FROM ai_suggestions WHERE log_id = ? LIMIT 1");
            $stmtCtx->execute([$logId]);
            $existingCtx = $stmtCtx->fetchColumn();
            $cumulativeCtx = $existingCtx ? ($existingCtx . "\n\n" . $discoveryResults) : $discoveryResults;

            $db->prepare("DELETE FROM ai_suggestions WHERE log_id = ?")->execute([$logId]);
            $db->prepare("UPDATE logs SET ai_processed = 2, ai_assistant_id = ? WHERE id = ?")->execute([$matchedId, $logId]);
            
            $hasReplace = false;
            foreach($finalActions as $act) { if(($act['type'] ?? '') === 'REPLACE_TEXT') $hasReplace = true; }
            if(!$hasReplace && $finalCleanedText !== $rawText) {
                $finalActions[] = ['type' => 'REPLACE_TEXT', 'text' => $finalCleanedText];
            }

            $db->prepare("INSERT INTO ai_suggestions (log_id, assistant_id, actions_json, reasoning, correction_history, discovery_context, turn_history, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$logId, $matchedId, json_encode($finalActions), "AI Suggestion from " . $assistant['name'], $correctionText, $cumulativeCtx, json_encode($turnHistory), time()]);
            
            ai_log_event($logId, 'SUCCESS', "Suggestion Queued", "Generated " . count($finalActions) . " actions.", $matchedId);
        }

        return true;
    }
}

// --- 1. PROMPT BUS (CENTRALIZED BUILDER) ---
/**
 * 1. CONTEXT PASSENGERS: Discrete data sources.
 * Output: Context Packet (String)
 */
function ai_get_context_packet($type, $ctxCfg = [], $options = []) {
    global $db;
    switch ($type) {
        case 'folders':
            try { 
                $names = $db->query("SELECT name FROM folders")->fetchAll(PDO::FETCH_COLUMN);
                return !empty($names) ? "EXISTING NOTE FOLDERS:\n- " . implode("\n- ", $names) : ""; 
            } catch(Exception $e) { return ""; }
        case 'todo':
            try { 
                $names = $db->query("SELECT name FROM todo_lists")->fetchAll(PDO::FETCH_COLUMN);
                return !empty($names) ? "EXISTING TODO LISTS:\n- " . implode("\n- ", $names) : ""; 
            } catch(Exception $e) { return ""; }
        case 'naming_rule':
            return "INSTRUCTION: Prioritize using these existing names for your actions if they are relevant. Only create new names if no suitable existing one is found.";
        case 'discovery_protocol':
            $returns = ["id", "name"];
            if (!empty($ctxCfg['food_metrics'])) $returns[] = "metrics";
            if (!empty($ctxCfg['food_nutrition'])) $returns[] = "nutrition_facts";
            return "--- FOOD DISCOVERY PROTOCOL ---\n" .
                   "STATUS: Discovery Available\n" .
                   "SEARCHABLE_FIELDS: [name]\n" .
                   "RETURNABLE_FIELDS: [" . implode(", ", $returns) . "]\n\n" .
                   "BEHAVIORAL INSTRUCTION:\n" .
                   "1. Use this to search the food database for keywords. The engine prioritizes items matching multiple keywords.\n" .
                   "2. QUERY STUFFING FORBIDDEN: Use SPACES to narrow a SINGLE item (e.g. '7-11 Chicken'). Use COMMAS to search for multiple DISTINCT items (e.g. 'Apple, Banana'). NEVER bundle distinct products into a single space-separated string.\n" .
                   "3. RELEVANCE GUARD: If the results returned do not contain the EXACT brand or product mentioned in the transcript, treat it as a failure. Do not settle for 'similar' items. Pivot to WEB_SEARCH immediately.\n" .
                   "4. CRITICAL: If you see 'No matching items' in your context for a specific query, DO NOT use DISCOVER again for that item. Pivot to WEB_SEARCH.\n" .
                   "5. If discovery is available and you do not see a food list in your context, you MUST use DISCOVER before logging any meals.\n\n" .
                   "JSON PROTOCOL MANDATE:\n" .
                   '{"type": "DISCOVER", "query": "7-11 Java Curry"}';
        case 'food_library':
            try {
                $fdb_path = CJOS_PATH_DATA . '/food-database/food.db';
                if (!file_exists($fdb_path)) return "";
                $tmpFdb = new PDO("sqlite:$fdb_path");
                $fields = ["id", "name"];
                if (!empty($options['food_metrics'])) $fields = array_merge($fields, ["total_weight_g", "ref_amount_g", "portion_name"]);
                if (!empty($options['food_nutrition'])) $fields = array_merge($fields, ["calories", "protein", "fat", "carbs", "sugar", "sodium"]);
                $foods = $tmpFdb->query("SELECT " . implode(", ", $fields) . " FROM cal_foods")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($foods)) return "";
                foreach ($foods as &$f) { foreach ($f as $k => $v) { if (is_numeric($v) && strpos($v, '.') !== false) $f[$k] = round((float)$v, 2); } }
                if (count($fields) === 2) return "FOOD LIBRARY NAMES (".count($foods)." items):\n- " . implode("\n- ", array_column($foods, 'name'));
                return "FOOD DATABASE ENTRIES (".count($foods)." items):\n" . json_encode($foods, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } catch(Exception $e) { return ""; }
        case 'recent_usage':
            try {
                $cal_db_path = CJOS_PATH_DATA . '/calorie-tracker/logs.db';
                $fdb_path = CJOS_PATH_DATA . '/food-database/food.db';
                if (!file_exists($cal_db_path)) return "";

                $limit = 20;
                $cFile = CJOS_PATH_DATA . '/calorie-config.json';
                if (file_exists($cFile)) {
                    $cConf = json_decode(file_get_contents($cFile), true);
                    if (!empty($cConf['recent_limit'])) $limit = (int)$cConf['recent_limit'];
                }

                $tmpCal = new PDO("sqlite:$cal_db_path");
                // Use a subquery to ensure the food_id matches the most recent log_timestamp for each name
                $sql = "SELECT l.food_name as name, l.food_id as id 
                        FROM cal_logs l
                        INNER JOIN (
                            SELECT food_name, MAX(log_timestamp) as max_ts 
                            FROM cal_logs 
                            WHERE meal_type != 'exercise' AND food_name IS NOT NULL 
                            GROUP BY food_name
                        ) tm ON l.food_name = tm.food_name AND l.log_timestamp = tm.max_ts
                        ORDER BY l.log_timestamp DESC LIMIT ?";
                
                $stmt = $tmpCal->prepare($sql);
                $stmt->execute([$limit]);
                $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (file_exists($fdb_path)) {
                    $tmpFdb = new PDO("sqlite:$fdb_path");
                    foreach ($recent as &$r) {
                        if (empty($r['id'])) {
                            $stmt = $tmpFdb->prepare("SELECT id FROM cal_foods WHERE name = ? LIMIT 1");
                            $stmt->execute([$r['name']]);
                            $foundId = $stmt->fetchColumn();
                            if ($foundId) $r['id'] = (int)$foundId;
                        }
                    }
                }
                return !empty($recent) ? "RECENTLY LOGGED ITEMS (Last $limit unique):\n" . json_encode($recent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : "";
            } catch(Exception $e) { return ""; }
        case 'discovery_results':
            // SILENCE GUARD: If not in a discovery flow, return nothing.
            if (empty($ctxCfg['food_discovery'])) return "";

            $allResults = [];
            if (!empty($options['turn_history'])) {
                foreach ($options['turn_history'] as $h) {
                    if (!empty($h['results'])) $allResults[] = $h['results'];
                }
            }
            if (!empty($options['results']) && $options['results'] !== 'null' && $options['results'] !== '[]') {
                $allResults[] = $options['results'];
            }
            
            if (empty($allResults)) {
                // Only show 'No matching items' if we are actually in a multi-turn flow (Turn > 1)
                if (($options['current_turn'] ?? 1) > 1) {
                    return "--- SEARCH RESULTS ---\nSTATUS: No matching items found for your previous query. DO NOT search for the same keywords again. You MUST change your query, pivot to WEB_SEARCH, or perform a manual estimation.";
                }
                return "";
            }

            $isFinal = ($options['current_turn'] ?? 1) >= ($options['max_turns'] ?? 2);
            $header = $isFinal ? "--- SEARCH RESULTS (FINAL TURN) ---" : "--- SEARCH RESULTS (INTERMEDIATE TURN) ---";
            $note = $isFinal ? "NOTE: This is your LAST TURN. You MUST finalize all actions now. Do not request further discovery." : "NOTE: You may request further discovery or search if these results are insufficient.";
            
            return "$header\n" . implode("\n\n", $allResults) . "\n\n" .
                   "$note For each item in the results above, you MUST include its 'id' as the 'food_id' in your ADD_FOOD_LOG action. If an item is missing from the results, estimate values and omit food_id.";
    }
    return "";
}

/**
 * 2. CONTEXT GROUPS: Domain aggregators.
 * Output: Context Bundle (String)
 */
function ai_get_context_bundle($groupId, $ctxCfg, $options = []) {
    $packets = [];
    $isBusView = ($options['view'] ?? '') === 'bus';

    switch ($groupId) {
        case 'food_master':
            $packets[] = ai_get_context_packet('recent_usage', $ctxCfg);
            if (empty($ctxCfg['food_discovery'])) {
                $packets[] = ai_get_context_packet('food_library', $ctxCfg);
            }
            // Protocols are handled by the Bus in Bus view, but included here for tooltips
            if (!$isBusView) {
                $packets[] = ai_get_context_packet('naming_rule', $ctxCfg);
                if (!empty($ctxCfg['food_discovery'])) $packets[] = ai_get_context_packet('discovery_protocol', $ctxCfg);
            }
            break;

        case 'notes':
            $packets[] = ai_get_context_packet('folders', $ctxCfg);
            if (!$isBusView) $packets[] = ai_get_context_packet('naming_rule', $ctxCfg);
            break;

        case 'tasks':
            $packets[] = ai_get_context_packet('todo', $ctxCfg);
            if (!$isBusView) $packets[] = ai_get_context_packet('naming_rule', $ctxCfg);
            break;

        case 'discovery_results':
            $packets[] = ai_get_context_packet('discovery_results', $ctxCfg, $options);
            break;
            
        case 'recent_usage': 
            $packets[] = ai_get_context_packet('recent_usage'); 
            $packets[] = ai_get_context_packet('naming_rule'); 
            break;
        case 'discovery': 
            $packets[] = ai_get_context_packet('discovery_protocol', $ctxCfg); 
            break;
    }

    $content = implode("\n\n", array_filter($packets));
    if (!$content) return "";

    // Add Sectional Header for Bus View
    if ($isBusView && $groupId !== 'discovery_results') {
        $header = strtoupper(str_replace('_', ' ', $groupId));
        return "--- $header DATA ---\n" . $content;
    }

    return $content;
}

/**
 * 3. CONTEXT BUS: Top-level orchestrator.
 * Output: Context Manifest (String)
 */
function ai_assemble_context_block($ctxCfg, $discoveryResults = null, $targetType = 'all', $turnOptions = []) {
    $now = date('Y-m-d H:i:s');
    $isFullManifest = ($targetType === 'all');
    
    $manifest = $isFullManifest ? ["--- YOUR SYSTEM CONTEXT ---\nCURRENT_TIME: $now"] : [];
    $dataBundles = [];
    $protocolBundles = [];

    $opt = array_merge(['view' => $isFullManifest ? 'bus' : 'tooltip', 'results' => $discoveryResults], $turnOptions);

    // 1. Collect Discovery Results (Always include in System Prompt for Decision Support)
    if ($targetType === 'all' || $targetType === 'discovery_results') {
        if (!empty($ctxCfg['food_discovery']) || $targetType === 'discovery_results') {
            $res = ai_get_context_bundle('discovery_results', $ctxCfg, $opt);
            if ($res) $dataBundles[] = $res;
        }
    }

    // 2. Collect Data Bundles
    if ($targetType === 'all' || $targetType === 'folders' || $targetType === 'notes') {
        if (!empty($ctxCfg['folders'])) $dataBundles[] = ai_get_context_bundle('notes', $ctxCfg, $opt);
    }
    if ($targetType === 'all' || $targetType === 'todo' || $targetType === 'tasks') {
        if (!empty($ctxCfg['todo'])) $dataBundles[] = ai_get_context_bundle('tasks', $ctxCfg, $opt);
    }

    $foodMaster = !empty($ctxCfg['food_master']) || (!empty($ctxCfg['food']) && !isset($ctxCfg['food_master']));
    if ($foodMaster) {
        if ($targetType === 'all' || $targetType === 'food_master' || $targetType === 'recent_usage') {
            $dataBundles[] = ai_get_context_bundle($targetType === 'recent_usage' ? 'recent_usage' : 'food_master', $ctxCfg, $opt);
        }
        // Discovery Protocol is a Method, collected separately for Bus view
        if ($targetType === 'all' || $targetType === 'discovery') {
            if (!empty($ctxCfg['food_discovery']) || $targetType === 'discovery') {
                $protocolBundles[] = ai_get_context_packet('discovery_protocol', $ctxCfg);
            }
        }
    }

    // 3. Assemble Manifest with SSOT Spacing
    if (!empty($dataBundles)) {
        $manifest[] = implode("\n\n", array_filter($dataBundles));
        
        // 4. Inject Global Constraint (Naming Rule) exactly once after data
        if ($isFullManifest) {
            $manifest[] = ai_get_context_packet('naming_rule', $ctxCfg);
        }
    }

    // 5. Inject Protocols (Methods) at the absolute end
    if (!empty($protocolBundles)) {
        $manifest[] = implode("\n\n", array_filter($protocolBundles));
    }

    return implode("\n\n", array_filter($manifest)) . "\n";
}

/**
 * The Prompt Bus collects "passengers" (Context & Verbs) to build the final system prompt.
 */
function ai_build_system_prompt($assistant, $ctxCfg, $verbsCfg, $discoveryResults = null, $turnOptions = []) {
    $prompt = trim($assistant['prompt'] ?? '');
    
    // 1. ASSEMBLE CONTEXT (SSOT)
    $contextStr = ai_assemble_context_block($ctxCfg, $discoveryResults, 'all', $turnOptions);

    // 1.5 TURNS & PROGRESS
    $currentTurn = $turnOptions['current_turn'] ?? 1;
    $maxTurns = $turnOptions['max_turns'] ?? 2;
    $turnHistory = $turnOptions['turn_history'] ?? [];
    
    $turnInstruction = "\n\n--- PIPELINE PROGRESS ---\n";
    $turnInstruction .= "CURRENT_TURN: $currentTurn\n";
    $turnInstruction .= "MAX_TURNS: $maxTurns\n";

    if (!empty($turnHistory)) {
        $turnInstruction .= "PREVIOUS ACTIONS:\n";
        foreach ($turnHistory as $h) {
            $turnInstruction .= "- Turn {$h['turn']}: " . json_encode($h['actions']) . "\n";
        }
    }

    if ($currentTurn < $maxTurns) {
        $rem = $maxTurns - $currentTurn;
        $turnInstruction .= "INSTRUCTION: You have $rem more turn(s) after this one.";
        if (!empty($verbsCfg['WEB_SEARCH'])) {
            $turnInstruction .= " If your previous DISCOVER attempt returned 'No matching items', you MUST pivot to WEB_SEARCH or perform a manual estimation.";
        }
    } else {
        $turnInstruction .= "INSTRUCTION: This is your FINAL TURN. You MUST finalize all actions now. Do not request further discovery or search.\n";
        $turnInstruction .= "PERSISTENCE MANDATE: Your 'actions' array MUST include EVERY item mentioned in the original transcript. Look at the 'PREVIOUS ACTIONS' list below. If you already successfully identified an item (e.g., an ADD_FOOD_LOG_DB action from a previous turn), you MUST re-include that exact action in your final JSON response. Do not omit previously found items just because you are adding new ones from research. Your final JSON response is the ONLY one that will be executed; it must be complete.";
    }

    // --- CORRECTION PROTOCOL ---
    if (!empty($turnOptions['correction_text'])) {
        $turnInstruction .= "\n\n--- USER CORRECTION ACTIVE ---\n";
        $turnInstruction .= "The user has rejected your previous proposal and provided feedback in 'INSTRUCTION HISTORY'.\n";
        $turnInstruction .= "MANDATE: Do NOT assume the 'PREVIOUS ACTIONS' below were successful. However, you MUST utilize all research data, search results, and IDs found in the 'PIPELINE PROGRESS' or 'SEARCH RESULTS' context to fulfill the user's request immediately. Do not request discovery or search again for items that have already returned results in the history.";
    }

    // 2. VERB PASSENGERS (Capability Providers)
    global $ai_verb_library;
    $verbInstructions = "\n\n--- ACTION CAPABILITIES ---\nYou are authorized to use the following action types in your 'actions' array:\n";
    
    // Inject consolidated DISCOVER instruction if Discovery Mode is active
    if (!empty($ctxCfg['food_discovery'])) {
        $verbInstructions .= "- DISCOVER: Search the food database for keywords. (See Food Discovery Protocol for details)\n";
    }

    $hasAnyVerb = false;
    foreach ($ai_verb_library as $vId => $vData) { 
        $enabled = !empty($verbsCfg[$vId]);
        if ($enabled) { $verbInstructions .= "- $vId: {$vData['full']}\n"; $hasAnyVerb = true; } 
    }
    if (!$hasAnyVerb) $verbInstructions .= "- NONE: You should return an empty 'actions' array.\n";

    $genericProtocol = "\n\n--- GENERIC ACTION PROTOCOL ---\n1. You MUST return a valid JSON object.\n2. The 'actions' array should contain objects representing the tasks you want to perform.\n3. If multiple actions are needed, include them all in the array.\n4. If no actions are needed, return an empty array.\n5. The 'cleaned_text' field should contain the polished version of the user's transcript.";

    $decisionMandate = "\n\n--- DECISION MANDATE ---\nIf your context contains 'RECENTLY LOGGED ITEMS' or 'SEARCH RESULTS', you MUST evaluate them for accuracy. If a HIGH QUALITY match exists (correct brand and product), log it immediately. If results are ambiguous or missing the specific brand, you MUST use WEB_SEARCH or request more turns. Do not guess nutrition facts for branded items if research is possible.";

    // 4. FINAL ASSEMBLY
    return $prompt . "\n\n" . $contextStr . $turnInstruction . $verbInstructions . $genericProtocol . $decisionMandate . "\n\nIMPORTANT: Return a JSON object ONLY.";
}

// --- 2. BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    
    // GET HUB CONFIG
    if ($_POST['plugin_action'] === 'ai_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults = ['monitoring_enabled' => false, 'dispatcher_model' => '', 'dispatcher_prompt' => ''];
        $conf = file_exists($ai_config_file) ? json_decode(file_get_contents($ai_config_file), true) : $defaults;
        echo json_encode(['status' => 'success', 'config' => $conf]);
        exit;
    }

    // SAVE HUB CONFIG
    if ($_POST['plugin_action'] === 'ai_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $settings = json_decode($_POST['settings'], true);
        file_put_contents($ai_config_file, json_encode($settings, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // FLAG FOR PROCESSING
    if ($_POST['plugin_action'] === 'ai_flag_pending') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $id = $_POST['id'];
        $stmt = $db->prepare("UPDATE logs SET ai_processed = 1 WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET ASSISTANTS (FROM JSON)
    if ($_POST['plugin_action'] === 'ai_get_assistants') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $list = file_exists($ai_assistants_file) ? json_decode(file_get_contents($ai_assistants_file), true) : [];
        echo json_encode(['status' => 'success', 'assistants' => $list]);
        exit;
    }

    // SAVE ASSISTANT (TO JSON)
    if ($_POST['plugin_action'] === 'ai_save_assistant') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $newA = json_decode($_POST['assistant'], true);
        $list = file_exists($ai_assistants_file) ? json_decode(file_get_contents($ai_assistants_file), true) : [];
        
        $found = false;
        foreach ($list as &$a) {
            if ($a['id'] === $newA['id']) { $a = $newA; $found = true; break; }
        }
        if (!$found) $list[] = $newA;
        
        file_put_contents($ai_assistants_file, json_encode($list, JSON_PRETTY_PRINT));

        // SYNC TO SQL
        try {
            $stmt = $db->prepare("INSERT OR REPLACE INTO ai_assistants (id, name, nickname, trigger_phrases, role_desc, prompt, model_override, temperature, commit_mode, is_active, workflow_json, max_turns) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $newA['id'], $newA['name'], $newA['nickname'], $newA['trigger_phrases'] ?? '', $newA['role_desc'], 
                $newA['prompt'], $newA['model_override'], $newA['temperature'], $newA['commit_mode'], 
                $newA['is_active'], $newA['workflow_json'], $newA['max_turns'] ?? 2
            ]);
        } catch(Exception $e) {}

        echo json_encode(['status' => 'success']);
        exit;
    }

    // DELETE ASSISTANT (FROM JSON)
    if ($_POST['plugin_action'] === 'ai_delete_assistant') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $id = $_POST['id'];
        $list = file_exists($ai_assistants_file) ? json_decode(file_get_contents($ai_assistants_file), true) : [];
        $list = array_filter($list, function($a) use ($id) { return $a['id'] !== $id; });
        file_put_contents($ai_assistants_file, json_encode(array_values($list), JSON_PRETTY_PRINT));

        // SYNC TO SQL
        try { $db->prepare("DELETE FROM ai_assistants WHERE id = ?")->execute([$id]); } catch(Exception $e) {}

        echo json_encode(['status' => 'success']);
        exit;
    }

    // GET SUGGESTIONS
    if ($_POST['plugin_action'] === 'ai_get_suggestions') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $sql = "SELECT s.*, l.transcription, l.date_display FROM ai_suggestions s JOIN logs l ON s.log_id = l.id ORDER BY s.created_at DESC";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'suggestions' => $rows]);
        exit;
    }

    // DISMISS SUGGESTION
    if ($_POST['plugin_action'] === 'ai_dismiss_suggestion') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $id = $_POST['id'];
        $keepBadge = ($_POST['keep_badge'] === 'true');
        $stmt = $db->prepare("SELECT log_id FROM ai_suggestions WHERE id = ?");
        $stmt->execute([$id]);
        $logId = $stmt->fetchColumn();
        
        if ($logId && !$keepBadge) {
            // Manual Dismiss: Revert state to standard note
            $db->prepare("UPDATE logs SET ai_processed = 0, ai_assistant_id = NULL WHERE id = ?")->execute([$logId]);
        }

        $db->prepare("DELETE FROM ai_suggestions WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success', 'log_id' => $logId]);
        exit;
    }

    // COMMIT TEXT REPLACE (Physical DB Update)
    if ($_POST['plugin_action'] === 'ai_commit_text_replace') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $logId = $_POST['log_id'];
        $newText = $_POST['text'];

        // 1. Fetch current to back up
        $stmt = $db->prepare("SELECT transcription FROM logs WHERE id = ?");
        $stmt->execute([$logId]);
        $oldText = $stmt->fetchColumn();

        // 2. Perform Swap
        $stmtUpdate = $db->prepare("UPDATE logs SET transcription = ?, original_text = ? WHERE id = ?");
        $stmtUpdate->execute([$newText, $oldText, $logId]);

        // 3. Return fresh entry
        $stmtFresh = $db->prepare("SELECT * FROM logs WHERE id = ?");
        $stmtFresh->execute([$logId]);
        echo json_encode(['status' => 'success', 'updated_entry' => $stmtFresh->fetch(PDO::FETCH_ASSOC)]);
        exit;
    }

    // CLEAR FLAGS (For Revert)
    if ($_POST['plugin_action'] === 'ai_clear_flags') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $id = $_POST['id'];
        $db->prepare("UPDATE logs SET ai_processed = 0, ai_assistant_id = NULL, original_text = NULL WHERE id = ?")->execute([$id]);
        // Purge any associated suggestions/search results
        $db->prepare("DELETE FROM ai_suggestions WHERE log_id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // RESET AI ENTRIES (Full Reversion)
    if ($_POST['plugin_action'] === 'ai_reset_entries') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $ids = json_decode($_POST['ids'], true);
        
        foreach ($ids as $id) {
            // 1. Fetch original text if available
            $stmt = $db->prepare("SELECT original_text FROM logs WHERE id = ?");
            $stmt->execute([$id]);
            $orig = $stmt->fetchColumn();

            // 2. Restore Text and Clear Flags
            if ($orig !== null) {
                $db->prepare("UPDATE logs SET transcription = ?, original_text = NULL, ai_processed = 0, ai_assistant_id = NULL WHERE id = ?")
                   ->execute([$orig, $id]);
            } else {
                $db->prepare("UPDATE logs SET ai_processed = 0, ai_assistant_id = NULL WHERE id = ?")
                   ->execute([$id]);
            }

            // 3. Purge related suggestions from the queue
            $db->prepare("DELETE FROM ai_suggestions WHERE log_id = ?")->execute([$id]);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    // CLEAR ALL PENDING DOTS
    if ($_POST['plugin_action'] === 'ai_clear_all_pending') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $db->exec("UPDATE logs SET ai_processed = 0 WHERE ai_processed = 1");
        echo json_encode(['status' => 'success']);
        exit;
    }

    // CREATE NOTE (Action Engine)
    if ($_POST['plugin_action'] === 'ai_create_note') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $refId = $_POST['log_id'];
        $text = $_POST['text'];
        $offset = (int)$_POST['offset'];

        // 1. Get reference timestamp and folder
        $stmt = $db->prepare("SELECT timestamp FROM logs WHERE id = ?");
        $stmt->execute([$refId]);
        $refTs = $stmt->fetchColumn();

        $stmtF = $db->prepare("SELECT folder_id FROM folder_map WHERE log_id = ?");
        $stmtF->execute([$refId]);
        $folderId = $stmtF->fetchColumn();

        // 2. Calculate new ID
        $newTs = $refTs + $offset;
        $newId = date('Ymd_His', $newTs);
        
        // Collision avoidance
        while (true) {
            $check = $db->prepare("SELECT COUNT(*) FROM logs WHERE id = ?");
            $check->execute([$newId]);
            if ($check->fetchColumn() == 0) break;
            $newTs++;
            $newId = date('Ymd_His', $newTs);
        }

        $dateDisp = date('Y-m-d H:i:s', $newTs);

        // 3. Insert Note
        $db->prepare("INSERT INTO logs (id, date_display, audio_path, transcription, timestamp) VALUES (?, ?, 'text_only', ?, ?)")
           ->execute([$newId, $dateDisp, $text, $newTs]);

        // 4. Map to same folder
        if ($folderId) {
            $db->prepare("INSERT OR REPLACE INTO folder_map (log_id, folder_id) VALUES (?, ?)")
               ->execute([$newId, $folderId]);
        }

        $stmtFresh = $db->prepare("SELECT * FROM logs WHERE id = ?");
        $stmtFresh->execute([$newId]);
        $entry = $stmtFresh->fetch(PDO::FETCH_ASSOC);
        $entry['folder_id'] = $folderId;

        echo json_encode(['status' => 'success', 'new_entry' => $entry]);
        exit;
    }

    // GET API PAYLOAD PREVIEW (Single Source of Truth with Step 2 Pipeline)
    if ($_POST['plugin_action'] === 'ai_get_api_preview') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $prompt = $_POST['prompt'] ?? '';
        $temp = (float)($_POST['temperature'] ?? 0.7);
        $model = $_POST['model'] ?: 'openai/gpt-3.5-turbo';
        $ctxCfg = json_decode($_POST['context_config'], true);
        $verbsCfg = json_decode($_POST['verbs_config'], true);
        $maxTurns = (int)($_POST['max_turns'] ?? 2);

        // --- PROMPT BUS HANDSHAKE ---
        $fullPrompt = ai_build_system_prompt(['prompt' => $prompt], $ctxCfg, $verbsCfg, null, ['current_turn' => 1, 'max_turns' => $maxTurns]);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $fullPrompt],
                ['role' => 'user', 'content' => '[User Transcript Placeholder]']
            ],
            'temperature' => $temp
        ];

        echo json_encode(['status' => 'success', 'payload' => $payload]);
        exit;
    }

    // GET SPECIFIC CONTEXT FRAGMENT (Tooltip SSOT)
    if ($_POST['plugin_action'] === 'ai_get_context_fragment') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $ctxCfg = [
            'food_master' => true,
            'food_discovery' => ($_POST['discovery_mode'] ?? 'false') === 'true',
            'food_metrics' => ($_POST['metrics_enabled'] ?? 'false') === 'true',
            'food_nutrition' => ($_POST['nutrition_enabled'] ?? 'false') === 'true',
            'folders' => true,
            'todo' => true
        ];

        // Use the master assembler to ensure fragment formatting matches the full prompt
        $fragment = ai_assemble_context_block($ctxCfg, null, $_POST['type']);

        echo json_encode(['status' => 'success', 'fragment' => $fragment]);
        exit;
    }

    // GET CONTEXT PREVIEW (Studio Preview SSOT)
    if ($_POST['plugin_action'] === 'ai_get_context_preview') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $ctxCfg = json_decode($_POST['context_config'], true);
        $counts = ['food' => 0, 'folders' => 0, 'todo' => 0, 'recent' => 0];
        
        // 1. Calculate Counts for UI Labels
        try {
            $fdb_path = CJOS_PATH_DATA . '/food-database/food.db';
            if (file_exists($fdb_path)) {
                $tmpFdb = new PDO("sqlite:$fdb_path");
                $counts['food'] = $tmpFdb->query("SELECT COUNT(*) FROM cal_foods")->fetchColumn();
            }
            $counts['folders'] = $db->query("SELECT COUNT(*) FROM folders")->fetchColumn();
            $counts['todo'] = $db->query("SELECT COUNT(*) FROM todo_lists")->fetchColumn();
            $cal_db_path = CJOS_PATH_DATA . '/calorie-tracker/logs.db';
            if (file_exists($cal_db_path)) {
                $tmpCal = new PDO("sqlite:$cal_db_path");
                $counts['recent'] = $tmpCal->query("SELECT COUNT(DISTINCT food_name) FROM cal_logs WHERE meal_type != 'exercise'")->fetchColumn();
            }
        } catch(Exception $e) {}

        // 2. Build Primary Preview (SSOT)
        $primary = ai_assemble_context_block($ctxCfg);

        // 3. Assemble Reference (Full Dump) for Discovery mode UI
        $reference = "--- DATABASE AVAILABILITY (REFERENCE) ---\n" . ai_get_context_packet('food_library', $ctxCfg);

        echo json_encode(['status' => 'success', 'primary' => $primary, 'reference' => $reference, 'counts' => $counts]);
        exit;
    }

    // SEARCH FOOD DATABASE (Discovery Mode)
    if ($_POST['plugin_action'] === 'ai_discover_data') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $query = $_POST['query'] ?? '';
        $results = ai_perform_discovery_internal($query);
        echo json_encode(['status' => 'success', 'results' => $results]);
        exit;
    }

    // COMMIT SUGGESTION (Backend Action Engine)
    if ($_POST['plugin_action'] === 'ai_commit_suggestion') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $suggId = $_POST['suggestion_id'];
        $logId = $_POST['log_id'];
        
        $stmt = $db->prepare("SELECT actions_json FROM ai_suggestions WHERE id = ?");
        $stmt->execute([$suggId]);
        $actionsRaw = $stmt->fetchColumn();
        
        if ($actionsRaw) {
            $actions = json_decode($actionsRaw, true);
            if (is_array($actions)) {
                foreach ($actions as $act) {
                    ai_execute_backend_action($logId, $act);
                }
            }
            $db->prepare("DELETE FROM ai_suggestions WHERE id = ?")->execute([$suggId]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Suggestion not found']);
        }
        exit;
    }

    // CLEAR AUDIT LOG
    if ($_POST['plugin_action'] === 'ai_clear_audit') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $db->exec("DELETE FROM ai_audit_log");
        echo json_encode(['status' => 'success']);
        exit;
    }

    // TRIGGER PIPELINE (Backend Entry Point)
    if ($_POST['plugin_action'] === 'ai_trigger_pipeline') {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        set_time_limit(0);
        while (ob_get_level()) ob_end_clean();
        
        $logId = $_POST['log_id'] ?? '';
        $correction = $_POST['correction_text'] ?? null;
        $isManual = ($_POST['is_manual'] ?? 'false') === 'true';
        
        if (!$logId) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Missing log_id']);
            exit;
        }

        // Check lock before flushing
        $lockFile = CJOS_PATH_DATA . "/ai-lock-$logId.tmp";
        if (file_exists($lockFile) && (time() - filemtime($lockFile) < 60)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ghosted', 'message' => 'Pipeline running in background']);
            exit;
        }

        // Return success immediately to release the browser queue
        $response = ['status' => 'backgrounded', 'message' => 'Pipeline started in background'];
        $jsonResponse = json_encode($response);
        
        header('Connection: close');
        header('Content-Length: ' . strlen($jsonResponse));
        header('Content-Type: application/json');
        echo $jsonResponse;
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_end_flush();
            @ob_flush();
            @flush();
        }

        // Run the heavy pipeline in the background, completely detached from the browser.
        ai_run_autonomous_pipeline($logId, $correction, $isManual);
        exit;
    }

    // GET AUDIT LOG
    if ($_POST['plugin_action'] === 'ai_get_audit') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $rows = $db->query("SELECT * FROM ai_audit_log ORDER BY timestamp DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'audit' => $rows]);
        exit;
    }


}

// --- 2. PAGE VIEW (HUB) ---
$ai_hub_html = <<<'HTML'
<div class="scroll-view" id="ai-assistant-view">
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
        <div class="page-title" style="margin-bottom:0; padding-top:0;">AI Hub</div>
        <div id="ai-monitoring-pill" onclick="aiToggleMonitoring()" style="
            display:flex; align-items:center; gap:10px; 
            padding:6px 16px; border-radius:20px; 
            border:1px solid var(--border-color); 
            box-shadow:var(--shadow-card); 
            cursor:pointer; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            user-select:none; -webkit-tap-highlight-color:transparent;
        ">
            <span id="ai-mon-label" style="font-size:10px; font-weight:900; text-transform:uppercase; letter-spacing:1px;">Monitoring</span>
            <div id="ai-mon-indicator" style="width:10px; height:10px; border-radius:50%; border:2px solid rgba(0,0,0,0.1); transition: all 0.3s;"></div>
        </div>
    </div>

    <!-- ZONE A: DISPATCHER -->
    <div id="ai-dispatcher-section" class="settings-group" style="margin:0 0 24px 0; border:1px solid var(--primary) !important; position:relative; overflow:visible !important;">
        <div style="position:absolute; top:-10px; left:20px; background:var(--primary); color:var(--primary-text); font-size:9px; font-weight:900; padding:2px 8px; border-radius:4px; text-transform:uppercase;">System Router</div>
        <div class="setting-item" style="padding:20px; cursor:pointer;" onclick="aiOpenDispatcherStudio()">
            <div style="display:flex; align-items:center; gap:15px;">
                <div style="width:44px; height:44px; border-radius:12px; background:var(--ai-accent-bg); color:var(--ai-accent); display:flex; align-items:center; justify-content:center;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:24px; height:24px;"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                </div>
                <div>
                    <div style="font-weight:700; font-size:16px; color:var(--text-primary);">AI Dispatcher</div>
                    <div id="ai-dispatcher-status" style="font-size:12px; color:var(--text-secondary); margin-top:2px;">Inheriting OpenRouter Default</div>
                </div>
            </div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:16px; height:16px; color:var(--text-secondary); opacity:0.3;"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </div>
    </div>

    <!-- ZONE B: ASSISTANTS -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding:0 4px;">
        <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">Active Assistants</div>
        <button onclick="aiOpenAssistantStudio('new')" style="background:none; border:none; color:var(--primary); font-weight:700; font-size:12px; cursor:pointer;">+ Create New</button>
    </div>
    <div id="ai-assistant-list" style="display:flex; flex-direction:column; gap:12px; margin-bottom:32px;">
        <!-- Injected via JS -->
    </div>

    <!-- ZONE C: SUGGESTION QUEUE -->
    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px; padding:0 4px;">Suggestion Queue</div>
    <div id="ai-suggestion-queue" style="display:flex; flex-direction:column; gap:12px; margin-bottom:24px;">
        <div style="text-align:center; padding:40px; background:rgba(0,0,0,0.02); border-radius:20px; border:1px dashed var(--border-color); color:var(--text-secondary); font-size:13px;">
            No pending AI suggestions.
        </div>
    </div>

    <!-- ZONE D: SYSTEM DIAGNOSTICS (COLLAPSIBLE) -->
    <details id="ai-diagnostics-details" style="margin-bottom: 120px;">
        <summary style="list-style:none; cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding:0 4px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">System Diagnostics</div>
                    <svg class="diag-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:12px; height:12px; stroke-width:3; color:var(--text-secondary); transition:transform 0.3s; transform:rotate(-90deg);"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </div>
                <div style="display:flex; gap:8px;" onclick="event.preventDefault()">
                    <button onclick="aiConsole.clear()" style="background:none; border:none; color:var(--text-secondary); font-size:10px; font-weight:700; cursor:pointer;">CLEAR</button>
                    <button onclick="aiConsole.copy()" style="background:none; border:none; color:var(--primary); font-size:10px; font-weight:700; cursor:pointer;">COPY ALL</button>
                </div>
            </div>
        </summary>

        <div style="animation: diagFadeIn 0.4s ease-out;">
            <!-- LIVE CONSOLE -->
            <div id="ai-console" style="
                background: #000; color: #00FF41; font-family: 'Courier New', monospace; 
                font-size: 11px; padding: 15px; border-radius: 14px; height: 180px; 
                overflow-y: auto; margin-bottom: 20px; border: 1px solid #333; line-height: 1.4;
                box-shadow: inset 0 2px 10px rgba(0,0,0,0.5);
            ">
                <div>[SYSTEM] Console Initialized. Waiting for events...</div>
            </div>

            <!-- DATA INSPECTOR -->
            <div class="settings-group" style="margin:0 0 24px 0;">
                <div class="setting-item" style="padding:16px; cursor:pointer;" onclick="aiOpenDataInspector()">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div style="color:var(--text-secondary);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div>
                        <div style="font-weight:600; font-size:14px; color:var(--text-primary);">Raw Database Inspector</div>
                    </div>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:14px; height:14px; color:var(--text-secondary); opacity:0.3;"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </div>
            </div>
        </div>
    </details>
</div>
HTML;
?>
<?php
$plugin_overlays[] = <<<'HTML'
<!-- AI Studios now dynamically generated by SharedUI openStudio -->
HTML;

$plugin_pages[] = $ai_hub_html;

$plugin_tools[] = [
    'name' => 'AI Hub',
    'desc' => 'Autonomous agents',
    'sui_icon' => 'activity',
    'color' => 'var(--ai-accent-bg)',
    'icon_color' => 'var(--ai-accent)',
    'action' => "dashNavToPage('ai-assistant-view')",
    'linked_page' => 'ai-assistant-view'
];

// --- 3. JAVASCRIPT LOGIC ---
$plugin_js .= <<<'JS'
// --- AI ASSISTANT ENGINE ---

// Ensure Markdown support is available
if (typeof marked === 'undefined') {
    const script = document.createElement('script');
    script.src = window.CJOS_ASSET_PATH + '/marked.min.js';
    script.onload = () => { if (typeof aiLoadSuggestions === 'function') aiLoadSuggestions(); };
    document.head.appendChild(script);
}

const aiAsstBridge = window.__AI_ASST_BRIDGE__ || { config: { monitoring_enabled: false }, verbLibrary: {} };
let aiConfig = aiAsstBridge.config;
const aiVerbLibrary = Object.entries(aiAsstBridge.verbLibrary).map(([id, data]) => ({ id, ...data }));

window.aiUpdateFabStatus = function() {
    const fab = document.getElementById('fab-record');
    if (!fab) return;
    if (window._aiActivePipelineRuns && window._aiActivePipelineRuns.size > 0) {
        fab.classList.add('ai-active');
    } else {
        fab.classList.remove('ai-active');
    }
};

const aiConsole = {
    log: function(msg, data = null) {
        console.log(`[AI-HUB-LOCAL] ${msg}`, data || '');
    },
    clear: async function() {
        await window.sui.api("ai_clear_audit", {}, { toast: "Console Cleared" });
        this.poll();
    },
    copy: async function() {
        const data = await window.sui.api("ai_get_audit", {}, { toast: false });
        const text = "```json\n" + JSON.stringify(data.audit || [], null, 2) + "\n```";
        navigator.clipboard.writeText(text);
        if (window.sui && window.sui.toast) window.sui.toast("Diagnostics Copied");
    },
    poll: async function() {
        const el = document.getElementById("ai-console");
        if (!el) return;
        try {
            const data = await window.sui.api("ai_get_audit", {}, { toast: false });
            if (data && data.audit) {
                const sorted = data.audit.reverse();
                el.innerHTML = sorted.map(a => {
                    const time = new Date(a.timestamp * 1000).toLocaleTimeString([], {hour12:false, hour:'2-digit', minute:'2-digit', second:'2-digit'});
                    const col = a.event_type === 'ERROR' ? '#FF3B30' : (a.event_type === 'SUCCESS' ? '#34C759' : '#00FF41');
                    let html = `<div style="margin-bottom:4px;"><span style="color:#8E8E93;">[${time}]</span> <span style="color:${col}; font-weight:bold;">[${a.event_type}]</span> ${a.message}</div>`;
                    if (a.details) {
                        html += `<pre style="margin:4px 0 8px 15px; color:#00F0FF; white-space:pre-wrap; font-size:10px; opacity:0.8;">${a.details.replace(/</g, '&lt;')}</pre>`;
                    }
                    return html;
                }).join('');
                if (sorted.length === 0) el.innerHTML = "<div>[SYSTEM] Console is empty.</div>";
                
                // Sticky Scroll: Only jump to bottom if user was already near the bottom
                const isAtBottom = el.scrollHeight - el.scrollTop <= el.clientHeight + 40;
                if (isAtBottom) el.scrollTop = el.scrollHeight;
            }
        } catch (e) {}
    }
};

// Poll console when diagnostics tab is open
setInterval(() => {
    if (document.visibilityState !== "visible") return;
    const details = document.getElementById('ai-diagnostics-details');
    if (details && details.open) aiConsole.poll();
}, 2000);

window.aiOpenDataInspector = async function() {
    const options = [
        { label: "View Active Assistants (JSON)", value: "asst" },
        { label: "View Suggestion Queue (JSON)", value: "sugg" },
        { label: "View Recent Audit Log (JSON)", value: "audit" }
    ];
    window.openPicker("System Data Inspector", options, null, async (val) => {
        let endpoint = "";
        if (val === "asst") endpoint = "ai_get_assistants";
        if (val === "sugg") endpoint = "ai_get_suggestions";
        if (val === "audit") endpoint = "ai_get_audit";
        
        const data = await window.sui.api(endpoint, {}, { toast: false });
        
        // Show raw JSON in a prompt/alert or a secondary picker? 
        // Let's use a standard alert for now, but formatted
        const jsonStr = JSON.stringify(data, null, 2);
        console.log("[AI Data Inspector]", data);
        
        // Copy to clipboard automatically for the user
        navigator.clipboard.writeText(jsonStr);
        window.openConfirm("Data Copied", "JSON Data for '" + val + "' has been copied to your clipboard for debugging.", null, false, "OK", null);
    });
};
let aiAssistants = [];
window._aiActivePipelineRuns = new Set();
window._aiPipelineQueue = [];
window._aiIsProcessingQueue = false;
window._aiKillSwitch = new Set(); // IDs marked for immediate termination
window._aiTransientActions = {}; // Maps logId -> current turn actions
window.aiIsArchived = function(logId, entry = null) {
    if (typeof so_folders === 'undefined') return false;
    const archiveFolder = so_folders.find(f => f.name.toLowerCase() === "archived");
    if (!archiveFolder) return false;
    
    const folderId = (entry && entry.folder_id) ? entry.folder_id : (typeof so_map !== 'undefined' ? so_map[logId] : null);
    return folderId == archiveFolder.id;
};

window.aiEnqueuePipeline = function(logId, card = null, isManual = false, correctionText = null) {
    // Avoid duplicates in queue or active runs
    if (window._aiActivePipelineRuns.has(logId)) return;
    if (window._aiPipelineQueue.some(t => t.logId === logId)) return;

    window._aiPipelineQueue.push({ logId, card, isManual, correctionText });
    aiConsole.log(`Queued AI Task for ${logId.substring(0,8)}. Queue size: ${window._aiPipelineQueue.length}`);
    
    aiProcessQueue();
};

async function aiProcessQueue() {
    // 1. Guard: Already running or nothing to do
    if (window._aiIsProcessingQueue || window._aiPipelineQueue.length === 0) return;
    
    // 2. Guard: System Lock (Wait if transcribing)
    if (window.lsIsProcessing) {
        setTimeout(aiProcessQueue, 1000);
        return;
    }

    window._aiIsProcessingQueue = true;
    window.lsIsProcessing = true; // Set global system lock
    
    const task = window._aiPipelineQueue.shift();
    
    try {
        if (typeof aiRunPipeline === 'function') {
            await aiRunPipeline(task.logId, task.card, task.isManual, task.correctionText);
        }
    } catch (e) {
        console.error("Queue Task Failed", e);
    } finally {
        window.lsIsProcessing = false; // Release system lock
        window._aiIsProcessingQueue = false;
        // Small delay between tasks to let the DB breathe
        setTimeout(aiProcessQueue, 500);
    }
}

window.aiSetStatusLabel = function(card, text) {
    if (!card) return;
    const headerRow = card.querySelector('.header-row');
    if (!headerRow) return;
    
    let label = headerRow.querySelector('.ai-status-label');
    if (!text) {
        if (label && !label.classList.contains('fade-out')) {
            label.classList.add('fade-out');
            setTimeout(() => { if (label.parentNode) label.remove(); }, 400);
        }
        return;
    }

    if (!label) {
        label = document.createElement('span');
        label.className = 'ai-status-label';
        // Insert after the time-badge
        const timeBadge = headerRow.querySelector('.time-badge');
        if (timeBadge) timeBadge.after(label);
        else headerRow.prepend(label);
    }
    
    if (label.innerText !== text) {
        label.innerText = text;
    }
};

// Listen for Lazy Hydration
window.addEventListener('cjos-hydrated', (e) => {
    if (e.detail.id === 'ai-assistant-view') {
        aiLoadHubConfig();
    }
});

window.addEventListener("load", () => {
    // 1. Inject Visual Styles
    
    // Ensure the Studio reacts to commit mode changes for the flowchart
    const modeSelect = document.getElementById("as-commit-mode");
    if (modeSelect) {
        modeSelect.addEventListener("change", () => {
            const a = {
                name: document.getElementById("as-name").value,
                commit_mode: modeSelect.value
            };
            aiRenderFlowchart(a);
        });
    }
    const style = document.createElement("style");
    style.innerHTML = `
        /* THE HOLLOW DOT (Unprocessed State) */
        .card.ai-state-pending .time-badge::after {
            content: "";
            display: inline-block;
            width: 6px;
            height: 6px;
            border: 1.5px solid var(--primary);
            background: transparent;
            border-radius: 50%;
            margin-left: 8px;
            vertical-align: middle;
            box-shadow: 0 0 6px color-mix(in srgb, var(--primary), transparent 70%);
            animation: ai-pulse-hollow 2s infinite ease-in-out;
        }
        @keyframes ai-pulse-hollow {
            0%, 100% { opacity: 0.4; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.2); }
        }

        /* THE SOLID DOT (Active Processing State) */
        .card.ai-is-running .time-badge::after {
            background: var(--ai-accent) !important;
            border-color: var(--ai-accent) !important;
            animation: ai-pulse-solid 0.8s infinite ease-in-out !important;
            box-shadow: 0 0 8px var(--ai-accent-bg);
        }
        @keyframes ai-pulse-solid {
            0%, 100% { transform: scale(1); opacity: 0.7; }
            50% { transform: scale(1.4); opacity: 1; }
        }

        /* STATUS LABELS */
        .ai-status-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 8px;
            color: var(--primary);
            opacity: 0.6;
            vertical-align: middle;
            animation: ai-label-in 0.3s ease-out;
            white-space: nowrap;
            /* Anchoring Logic */
            flex: 1;
            text-align: left;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ai-is-running .ai-status-label { color: var(--ai-accent); opacity: 0.9; }
        @keyframes ai-label-in { from { opacity: 0; transform: translateX(-5px); } to { opacity: 0.6; transform: translateX(0); } }
        @keyframes ai-label-out { from { opacity: 0.6; transform: translateX(0); } to { opacity: 0; transform: translateX(5px); } }
        .ai-status-label.fade-out { animation: ai-label-out 0.4s ease-in forwards; pointer-events: none; }
        
        /* HUB STYLES */
        .ai-assistant-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-card);
            cursor: pointer;
            transition: transform 0.1s;
        }
        .ai-assistant-card:active { transform: scale(0.98); }

        /* FAB AI Running Light (State-Aware) */
        .fab.ai-active::after {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            /* Fill container height and force width to match to keep it a circle */
            height: 100%;
            width: var(--fab-size, 68px); 
            border-radius: 50%;
            border: 4px solid transparent;
            border-top-color: #A5B4FC; 
            border-right-color: var(--ai-accent);
            animation: ai-fab-spin 0.8s linear infinite;
            pointer-events: none;
            z-index: 10;
            box-sizing: border-box;
        }

        /* Smaller size when minimized in Back Mode */
        .fab.back-mode.ai-active::after {
            width: 48px;
        }
        .fab.ai-active {
            box-shadow: 0 0 20px color-mix(in srgb, var(--ai-accent), transparent 50%), var(--shadow-fab-default) !important;
        }
        @keyframes ai-fab-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* ACTION WIDGETS */
        .ai-action-widget {
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px;
            margin-top: 8px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .ai-widget-header {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }
        .ai-widget-title { font-size: 14px; font-weight: 700; color: var(--text-primary); }
        .ai-widget-sub { font-size: 12px; color: var(--text-secondary); }

        /* Nutrition Label Widget */
        .ai-nutrition-label {
            background: #fff;
            color: #000;
            border: 1px solid #000;
            padding: 8px;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            margin-top: 4px;
        }
        body.theme-midnight .ai-nutrition-label { background: #f0f0f0; } /* Keep label readable in dark mode */
        .ai-nutri-row { display: flex; justify-content: space-between; border-bottom: 1px solid #000; padding: 2px 0; font-size: 11px; }
        .ai-nutri-row.thick { border-bottom-width: 4px; }
        .ai-nutri-val { font-weight: 800; }

        /* Markdown Discussion Styles */
        .ai-discuss-content { width: 100%; overflow-wrap: break-word; }
        .ai-discuss-content p:first-child { margin-top: 0; }
        .ai-discuss-content p:last-child { margin-bottom: 0; }
        .ai-discuss-content p { margin: 8px 0; }
        .ai-discuss-content ul, .ai-discuss-content ol { padding-left: 20px; margin: 8px 0; }
        .ai-discuss-content code { background: rgba(0,0,0,0.05); padding: 2px 4px; border-radius: 4px; font-family: monospace; font-size: 12px; }

        /* Chat Bubble System */
        .ai-chat-thread { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
        .ai-bubble { 
            max-width: 85%; 
            padding: 12px 16px; 
            border-radius: 20px; 
            font-size: 13.5px; 
            line-height: 1.5; 
            position: relative; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .ai-bubble-user { 
            align-self: flex-end; 
            background: var(--primary); 
            color: white; 
            border-bottom-right-radius: 4px; 
        }
        .ai-bubble-asst { 
            align-self: flex-start; 
            background: var(--card-bg); 
            color: var(--text-primary); 
            border: 1px solid var(--border-color); 
            border-bottom-left-radius: 4px; 
        }
        .ai-bubble-meta { 
            font-size: 9px; 
            font-weight: 900; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            margin-bottom: 4px; 
            display: block; 
            opacity: 0.6;
        }
        .ai-bubble-user .ai-bubble-meta { color: rgba(255,255,255,0.8); }
        .ai-bubble-asst .ai-bubble-meta { color: var(--ai-accent); }

        @keyframes bubble-highlight-pulse {
            0% { box-shadow: 0 0 0 0 rgba(88, 86, 214, 0.4); transform: scale(1); }
            30% { transform: scale(1.05); }
            100% { box-shadow: 0 0 0 15px rgba(88, 86, 214, 0); transform: scale(1); }
        }
        .ai-bubble-highlight {
            animation: bubble-highlight-pulse 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) 2;
            z-index: 100;
            border-color: var(--ai-accent) !important;
        }

        .ai-sugg-skeleton {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 16px;
            border: 1px solid var(--border-color);
            margin-bottom: 12px;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
        }
        .ai-sugg-skeleton::after {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transform: translateX(-100%);
            animation: ai-skel-shimmer 1.5s infinite;
        }
        @keyframes ai-skel-shimmer { 100% { transform: translateX(100%); } }

        #ai-diagnostics-details[open] .diag-arrow { transform: rotate(0deg) !important; }
        @keyframes diagFadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    `;
    document.head.appendChild(style);

    // 2. Load State
    aiLoadHubConfig();
    
    // 3. Register Card Decorator (Priority 10: Structural - Hollow Dot Only)
    if (window.registerCardPlugin) {
        window.registerCardPlugin(aiDecorateCard, 10);
    }

    if (window.registerRefreshHook) {
        window.registerRefreshHook(aiLoadSuggestions);
    }

    // Hook into the system bus
    if (window.cjosHooks) {
        window.cjosHooks.register('onIngest', (id, entry) => {
            if (aiConfig.monitoring_enabled || localStorage.getItem('cjos_ai_monitoring') === 'true') {
                if (entry && entry.ai_processed < 1) {
                    if (window.aiIsArchived(id, entry)) return;
                    
                    const isPlaceholder = !entry.transcription || entry.transcription.startsWith('(');
                    if (isPlaceholder) return; // Wait for onTranscribe hook

                    entry.ai_processed = 1;
                    const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
                    if (cb) aiDecorateCard(cb.closest(".card"), entry);
                    window.sui.api("ai_flag_pending", { id: id }, { toast: false }).catch(() => {});
                }
            }
        });

        window.cjosHooks.register('onTranscribe', (id, text, entry) => {
            if (entry) {
                if (window.aiIsArchived(id, entry)) return;

                // If not armed yet (0), arm it now because we have text
                if (entry.ai_processed == 0 && (aiConfig.monitoring_enabled || localStorage.getItem('cjos_ai_monitoring') === 'true')) {
                    entry.ai_processed = 1;
                    window.sui.api("ai_flag_pending", { id: id }, { toast: false }).catch(() => {});
                }

                if (entry.ai_processed == 1) {
                    const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
                    const card = cb ? cb.closest(".card") : null;
                    if (card) aiDecorateCard(card, entry);
                    aiEnqueuePipeline(id, card);
                }
            }
        });
    }

    // Phase 8: Badge Engine Registration (Priority 40)
    if (window.sui && window.sui.registerBadge) {
        window.sui.registerBadge("ai-asst-badge", (entry, card) => {
            // Coordinator: If processed, block standard OpenRouter badges
            if (entry.ai_processed == 2) {
                if (card) card.setAttribute("data-ai-prohibited", "true");
                
                // If it was processed by a specific robot, use its name
                const assistant = entry.ai_assistant_id ? aiAssistants.find(a => a.id === entry.ai_assistant_id) : null;
                const asstName = assistant ? assistant.name : (entry.ai_assistant_id ? "Robot" : "AI Edited");
                
                const isShowingRaw = entry._asst_show_raw === true;
                const badge = window.suiBadge(`✨ ${asstName}`, "ai");
                badge.style.cursor = "pointer";
                badge.setAttribute('data-sui-id', 'ai-asst-trigger');
                
                badge.onclick = (e) => { 
                    e.stopPropagation(); 
                    const suggestionId = window._aiLogToSuggMap ? window._aiLogToSuggMap[entry.id] : null;
                    
                    if (suggestionId) {
                        if (typeof aiJumpToSuggestion === 'function') aiJumpToSuggestion(suggestionId);
                        return;
                    }

                    // Build Action Elements
                    const actions = [];
                    
                    // 1. Toggle View Action
                    const viewLabel = entry._asst_show_raw ? "Show Clean Version" : "Show Original Transcript";
                    const viewBtn = window.suiBadge(viewLabel, "default");
                    viewBtn.onclick = (ev) => { ev.stopPropagation(); aiToggleCleanRaw(entry.id, card); };
                    actions.push(viewBtn);

                    // 2. Revert Action
                    const revertBtn = window.suiBadge("↺ Revert Permanently", "danger");
                    revertBtn.onclick = (ev) => { 
                        ev.stopPropagation(); 
                        window.openConfirm("Revert Text", "Permanently delete the AI version and restore your raw transcript?", () => {
                            aiRevertAssistantText(entry.id, card);
                        }, true);
                    };
                    actions.push(revertBtn);

                    // 3. Hub Shortcut
                    const hubBtn = window.suiBadge("Open AI Hub", "default");
                    hubBtn.onclick = (ev) => { ev.stopPropagation(); if (typeof dashNavToPage === 'function') dashNavToPage('ai-assistant-view'); };
                    actions.push(hubBtn);

                    window.sui.toggleActions(card, actions, badge);
                };
                
                return badge;
            } else {
                card.removeAttribute("data-ai-prohibited");
                return null;
            }
        }, 40); // Priority 40: Runs BEFORE OpenRouter (45)
    }

    // 4. Inject Robot Button
    setTimeout(aiInjectRobotBtn, 500);

    // 5. Inject Dashboard Shortcut to Settings Header
    setTimeout(aiInjectSettingsHeaderBtn, 800);
});

window.aiInjectSettingsHeaderBtn = function() {
    const headerActions = document.getElementById("settings-header-actions");
    if (headerActions && !document.getElementById("btn-ai-dashboard-trigger")) {
        const btn = document.createElement("button");
        btn.id = "btn-ai-dashboard-trigger";
        // Position relative for the dot
        btn.style.cssText = "background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; margin-right:4px; position:relative;";
        btn.innerHTML = `
            <span style="font-family: -apple-system, system-ui, sans-serif; font-weight: 900; font-size: 13px; letter-spacing: -0.5px; margin-left: -1px;">Ai</span>
            <span id="ai-dash-indicator-dot" style="position:absolute; top:2px; right:2px; width:8px; height:8px; border-radius:50%; background:transparent; border:2px solid var(--btn-bg); transition: background 0.3s ease;"></span>
        `;
        btn.onclick = (e) => { e.stopPropagation(); aiOpenDashboardStudio(); };
        headerActions.prepend(btn);
        // Refresh indicator immediately if data is already loaded
        aiRefreshIndicatorDot();
    }
};

window.aiRefreshIndicatorDot = function(count = null) {
    const dot = document.getElementById("ai-dash-indicator-dot");
    if (!dot) return;
    
    const queueCount = (count !== null) ? count : (Object.keys(window._aiActiveSuggestions || {}).length);
    
    if (queueCount > 0) {
        dot.style.background = "var(--danger)";
    } else {
        dot.style.background = "transparent";
    }
};

window.aiJumpToSuggestion = function(id) {
    window.sui.closeStudio('ai-dashboard');
    
    // Dismiss the Settings menu if it's open
    const settingsClose = document.getElementById('settings-close');
    if (settingsClose) settingsClose.click();

    if (typeof dashNavToPage === 'function') {
        dashNavToPage('ai-assistant-view');
        // Small delay to allow the horizontal page swipe to finish
        setTimeout(() => {
            const el = document.getElementById(`ai-sugg-card-${id}`);
            const container = el ? el.closest('.scroll-view') : null;
            if (el && container) {
                // Manual calculation to prevent browser-level "body shift"
                const containerRect = container.getBoundingClientRect();
                const elRect = el.getBoundingClientRect();
                const relativeTop = elRect.top - containerRect.top + container.scrollTop;
                const targetScroll = relativeTop - (containerRect.height / 2) + (elRect.height / 2);
                
                container.scrollTo({ top: targetScroll, behavior: 'smooth' });
                
                // Visual highlight bounce (Timed to fire AFTER scroll completes)
                setTimeout(() => {
                    el.classList.add('ai-jump-bounce');
                    el.style.borderColor = 'var(--primary)';
                    setTimeout(() => {
                        el.classList.remove('ai-jump-bounce');
                        el.style.borderColor = '';
                    }, 1000);
                }, 500);
            }
        }, 450);
    }
};

window.aiOpenDashboardStudio = function() {
    window.sui.openStudio({
        id: 'ai-dashboard',
        title: 'Intelligence Dashboard',
        onSetup: async (container) => {
            container.innerHTML = `
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:24px;">
                    <div style="background:var(--bg-color); padding:16px; border-radius:16px; border:1px solid var(--border-color);">
                        <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:4px;">Credits (OR)</div>
                        <div id="ai-dash-credits" style="font-size:18px; font-weight:800; color:var(--text-primary);">...</div>
                    </div>
                    <div style="background:var(--bg-color); padding:16px; border-radius:16px; border:1px solid var(--border-color);">
                        <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:4px;">In Queue</div>
                        <div id="ai-dash-queue-count" style="font-size:18px; font-weight:800; color:var(--primary);">...</div>
                    </div>
                </div>

                <div style="margin-bottom:24px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">Recent Audit</div>
                        <button onclick="aiShowAuditLog()" style="background:none; border:none; color:var(--primary); font-size:11px; font-weight:700;">View Full Log</button>
                    </div>
                    <div id="ai-dash-audit-preview" style="display:flex; flex-direction:column; gap:8px;"></div>
                </div>

                <div>
                    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">Active Suggestions</div>
                    <div id="ai-dash-suggestions"></div>
                </div>
            `;

            // 1. Fetch Credits
            try {
                const orData = await window.sui.api("or_get_usage", {}, { toast: false });
                if (orData && orData.data) {
                    const usage = parseFloat(orData.data.usage || 0).toFixed(3);
                    document.getElementById("ai-dash-credits").innerText = `$${usage}`;
                    // Add secondary info if available
                    if (orData.data.label) {
                        const label = document.createElement('div');
                        label.style.cssText = "font-size:9px; opacity:0.5; margin-top:2px; font-weight:400;";
                        label.innerText = orData.data.label;
                        document.getElementById("ai-dash-credits").appendChild(label);
                    }
                } else {
                    document.getElementById("ai-dash-credits").innerText = "API Busy";
                }
            } catch(e) { 
                document.getElementById("ai-dash-credits").innerText = "Error"; 
                console.error("AI Dashboard Usage Error:", e);
            }

            // 2. Fetch Queue & Suggestions
            try {
                const suggData = await window.sui.api("ai_get_suggestions", {}, { toast: false });
                const count = suggData.suggestions ? suggData.suggestions.length : 0;
                document.getElementById("ai-dash-queue-count").innerText = count;
                
                const suggCont = document.getElementById("ai-dash-suggestions");
                if (count > 0) {
                    suggCont.innerHTML = suggData.suggestions.slice(0, 3).map(s => `
                        <div onclick="aiJumpToSuggestion(${s.id})" style="background:var(--card-bg); border:1px solid var(--border-color); padding:12px; border-radius:12px; margin-bottom:8px; font-size:13px; cursor:pointer; transition:transform 0.1s;" onactive="this.style.transform='scale(0.98)'">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <div style="color:var(--text-secondary); font-size:10px;">${s.date_display}</div>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:10px; height:10px; color:var(--text-secondary); opacity:0.5;"><polyline points="9 18 15 12 9 6"></polyline></svg>
                            </div>
                            <div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-weight:600; color:var(--text-primary);">${s.transcription}</div>
                        </div>
                    `).join('') + (count > 3 ? `<div onclick="aiJumpToSuggestion(${suggData.suggestions[3].id})" style="text-align:center; font-size:11px; color:var(--primary); font-weight:700; margin-top:8px; cursor:pointer;">+ Show All (${count})</div>` : '');
                } else {
                    suggCont.innerHTML = `<div style="text-align:center; padding:20px; color:var(--text-secondary); font-size:12px; opacity:0.6;">Queue is empty</div>`;
                }
            } catch(e) {}

            // 3. Fetch Audit Preview
            try {
                const auditData = await window.sui.api("ai_get_audit", {}, { toast: false });
                const auditCont = document.getElementById("ai-dash-audit-preview");
                if (auditData && auditData.audit) {
                    auditCont.innerHTML = auditData.audit.slice(0, 3).map(a => {
                        const col = a.event_type === 'ERROR' ? 'var(--danger)' : '#34C759';
                        return `<div style="display:flex; align-items:center; gap:8px; font-size:12px;">
                            <div style="width:6px; height:6px; border-radius:50%; background:${col};"></div>
                            <div style="flex:1; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${a.message}</div>
                        </div>`;
                    }).join('');
                }
            } catch(e) {}
        }
    });
};

window.aiDecorateCard = aiDecorateCard;
function aiDecorateCard(card, entry) {
    // If entry is missing OR is a number (from forEach index), perform lookup
    if (!entry || typeof entry !== 'object') {
        const id = card.querySelector('.custom-checkbox')?.getAttribute('data-id');
        entry = (typeof logs !== 'undefined') ? logs.find(l => l.id === id) : null;
    }
    if (!entry) return;

    // Ensure stale classes are removed if state changed
    if (entry.ai_processed != 1) {
        card.classList.remove("ai-state-pending", "ai-is-running");
        if (typeof aiSetStatusLabel === 'function') aiSetStatusLabel(card, null);
        
        // Clean up any background running state
        if (window._aiActivePipelineRuns && window._aiActivePipelineRuns.has(entry.id)) {
            window._aiActivePipelineRuns.delete(entry.id);
            if (typeof aiUpdateFabStatus === 'function') aiUpdateFabStatus();
            if (typeof aiLoadSuggestions === 'function') aiLoadSuggestions();
        }
    }
    
    // 1. Apply Hollow Dot if Pending
    // 2. Trigger Badge Engine
    if (window.sui && window.sui.decorateCard) window.sui.decorateCard(card, entry);

    if (entry.ai_processed == 1) {
        if (window.aiIsArchived(entry.id, entry)) return;

        card.classList.add("ai-state-pending");
        
        // If text is already present (e.g. initial load or Remote Upload), trigger pipeline
const hasRealText = entry.transcription && 
                   entry.transcription !== "(Pending Transcription...)" && 
                   entry.transcription !== "(Transcribing...)" && 
                   entry.transcription.trim() !== "";
                       
if (hasRealText) {
    aiEnqueuePipeline(entry.id, card);}
    }
}

window.aiToggleCleanRaw = function(logId, card) {
    const entry = logs.find(l => l.id === logId);
    if (!entry || !entry.original_text) return;

    const textDiv = card.querySelector(".transcription");
    const isShowingRaw = entry._asst_show_raw === true;

    if (!isShowingRaw) {
        // SHOW RAW
        textDiv.innerText = entry.original_text;
        entry._asst_show_raw = true;
    } else {
        // SHOW CLEAN
        textDiv.innerText = entry.transcription;
        entry._asst_show_raw = false;
    }
    
    // Refresh Badge State
    if (window.sui && window.sui.decorateCard) window.sui.decorateCard(card, entry);

    // Refresh Action Row if open
    const badge = card.querySelector('.meta-badge[data-sui-id="ai-asst-trigger"]');
    if (badge && badge.classList.contains('sui-badge-active')) {
        badge.click(); // Close
        badge.click(); // Re-open with new labels
    }
};

async function aiRevertAssistantText(logId, card) {
    const entry = logs.find(l => l.id === logId);
    
    await window.sui.api("manual_edit_save", { id: logId, text: entry.original_text }, { toast: false });
    window.sui.api("ai_clear_flags", { id: logId }, { toast: false });
    
    // Update Local Data
    entry.transcription = entry.original_text;
    entry.original_text = null;
    entry.ai_processed = 0;
    entry.ai_assistant_id = null;
    delete entry._asst_show_raw;
    
    // Refresh UI
    const textDiv = card.querySelector(".transcription");
    if(textDiv) textDiv.innerText = entry.transcription;
    
    // Close Action Row
    const container = window.getActionContainer(card.querySelector('.card-content'));
    if (container) container.classList.remove('open');

    if (window.sui && window.sui.decorateCard) window.sui.decorateCard(card, entry);
}




window.aiSubmitCorrection = async function(logId, suggestionId) {
    const input = document.getElementById(`ai-correction-input-${suggestionId}`);
    const val = input ? input.value.trim() : "";
    if (!val) return;

    // 0. CAPTURE RESEARCH CONTEXT BEFORE DISMISSAL
    const disc = window._aiDiscoveryCache ? window._aiDiscoveryCache[suggestionId] : null;
    
    // Look for existing history
    const existingHistory = window._aiCorrectionCache ? (window._aiCorrectionCache[suggestionId] || "") : "";

    // Build Cumulative History
    const timestamp = new Date().toLocaleTimeString('en-GB', {hour: '2-digit', minute:'2-digit', second:'2-digit'});
    const newHistoryEntry = `[${timestamp}] ${val}`;
    const fullHistory = existingHistory ? (existingHistory + "\n" + newHistoryEntry) : newHistoryEntry;
    
    aiConsole.log(`Manual Correction submitted. Carrying over ${disc ? disc.length : 0} chars of research.`);
    
    // 1. Dismiss the old suggestion
    await window.sui.api("ai_dismiss_suggestion", { id: suggestionId, keep_badge: false }, { toast: false });
    
    // 2. Trigger pipeline with FULL cumulative history AND previous research results
    const cb = document.querySelector(`.custom-checkbox[data-id="${logId}"]`);
    const card = cb ? cb.closest(".card") : null;
    aiRunPipeline(logId, card, true, fullHistory, disc);
    
    // 3. Refresh Hub
    aiLoadSuggestions();
};

window.aiRunPipeline = async function(logId, card = null, isManual = false, correctionText = null, existingResearch = null) {
    if (window._aiKillSwitch.has(logId)) {
        window._aiKillSwitch.delete(logId);
        window._aiActivePipelineRuns.delete(logId);
        aiUpdateFabStatus();
        return;
    }

    window._aiActivePipelineRuns.add(logId);
    aiUpdateFabStatus();
    
    if (typeof aiLoadSuggestions === 'function') aiLoadSuggestions();

    if (!card) {
        const cb = document.querySelector(`.custom-checkbox[data-id="${logId}"]`);
        if (cb) card = cb.closest(".card");
    }

    if (card) { 
        card.classList.add("ai-is-running", "ai-state-pending"); 
        aiSetStatusLabel(card, "Processing (Backend)...");
    }
    
    let isGhosted = false;
try {
    const res = await window.sui.api("ai_trigger_pipeline", {
        log_id: logId,
        correction_text: correctionText,
        is_manual: isManual,
        existing_research: existingResearch
    }, { toast: false });
            
    if (res && res.status === 'ghosted') {
        isGhosted = true;
    }
    
    if (res && res.status === 'backgrounded') {
        aiConsole.log(`Pipeline is running in the background for ${logId.substring(0,8)}.`);
    }
} catch(e) {console.error("Pipeline trigger failed", e);
} finally {
    if (!isGhosted) {
        window._aiActivePipelineRuns.delete(logId);
        aiUpdateFabStatus();

        const currentCb = document.querySelector(`.custom-checkbox[data-id="${logId}"]`);
        const currentCard = currentCb ? currentCb.closest(".card") : card;

        if (currentCard) {
            currentCard.classList.remove("ai-state-pending", "ai-is-running");
            aiSetStatusLabel(currentCard, null);
        }
        if (typeof aiLoadSuggestions === 'function') aiLoadSuggestions();
    }
}};let currentStudioId = null;
let aiInitialState = null;
let dsInitialState = null;

async function aiLoadAssistants() {
    const cont = document.getElementById("ai-assistant-list");
    if(!cont) return;
    
    try {
        const data = await window.sui.api("ai_get_assistants", {}, { toast: false });
        if (data) {
            aiAssistants = data.assistants;
            aiRenderAssistantList();
            
            // HYDRATION: Now that we have names, draw the badges on existing cards
            document.querySelectorAll(".card").forEach(aiDecorateCard);
            
            // HYDRATION: Re-render suggestions so they show the correct names
            aiLoadSuggestions();
        }
    } catch(e) {}
}

function aiRenderAssistantList() {
    const cont = document.getElementById("ai-assistant-list");
    if(!cont) return;
    cont.innerHTML = "";

    if (aiAssistants.length === 0) {
        cont.innerHTML = window.suiEmptyState('🤖', 'No assistants created yet');
        return;
    }

    aiAssistants.forEach(a => {
        const card = document.createElement("div");
        const isActive = a.is_active !== 0;
        card.className = "ai-assistant-card";
        if (!isActive) card.style.opacity = "0.5";
        card.onclick = () => aiOpenAssistantStudio(a.id);
        if (typeof window.srWatch === 'function') window.srWatch(card);
        card.innerHTML = `
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:36px; height:36px; border-radius:10px; background:var(--btn-bg); display:flex; align-items:center; justify-content:center; font-size:18px;">🤖</div>
                <div>
                    <div style="font-weight:700; font-size:15px; color:var(--text-primary);">${a.name} ${a.nickname ? `<span style="font-weight:400; opacity:0.6; font-style:italic; font-size:13px; margin-left:4px;">"${a.nickname}"</span>` : ''}</div>
                    <div style="font-size:11px; color:var(--text-secondary);">${a.commit_mode === 'direct' ? '⚡ Direct Commit' : '📋 Suggestion Mode'}</div>
                </div>
            </div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:14px; height:14px; color:var(--text-secondary); opacity:0.3;"><polyline points="9 18 15 12 9 6"></polyline></svg>
        `;
        cont.appendChild(card);
    });
}

// Verb Library now provided by Data Bridge

function aiRenderVerbLibrary() {
    const cont = document.getElementById("ai-verb-list");
    if(!cont) return;
    cont.innerHTML = aiVerbLibrary.map((v, idx) => `
        <div onclick="aiCopyVerbInstruction(${idx})" style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:12px; display:flex; flex-direction:column; gap:6px; cursor:pointer; transition:transform 0.1s;">
            <div style="display:flex; justify-content:space-between; align-items:center; pointer-events:none;">
                <code style="color:var(--primary); font-weight:800; font-size:12px;">${v.id}</code>
                <span style="font-size:10px; color:var(--text-secondary); font-weight:600; text-transform:uppercase;">${v.desc}</span>
            </div>
            <div style="background:var(--bg-color); padding:6px 10px; border-radius:6px; font-family:monospace; font-size:10px; color:var(--text-secondary); border:1px solid rgba(0,0,0,0.03); overflow-x:auto; pointer-events:none;">
                ${v.usage}
            </div>
        </div>
    `).join('');
}

window.aiShowContextInfo = async function(type) {
    const titles = {
        'folders': 'Context: Note Folders',
        'todo': 'Context: To-Do Lists',
        'discovery': 'Protocol: Food Discovery',
        'food_master': 'Context: Food Database'
    };

    const payload = { type: type };
    if (type === 'food_master') {
        payload.discovery_mode = document.getElementById("ctx-food-discovery").checked;
        payload.metrics_enabled = document.getElementById("ctx-food-metrics").checked;
        payload.nutrition_enabled = document.getElementById("ctx-food-nutrition").checked;
    }

    try {
        const data = await window.sui.api('ai_get_context_fragment', payload, { toast: false });
        if (data && data.fragment) {
            const html = `
                <div style="text-align:left; display:flex; flex-direction:column; gap:16px;">
                    <div>
                        <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">Raw System Context</div>
                        <div style="position:relative;">
                            <button onclick="aiCopyText(this.nextElementSibling.innerText)" style="position:absolute; top:10px; right:10px; background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); color:white; font-size:9px; font-weight:900; padding:4px 8px; border-radius:6px; cursor:pointer; z-index:10; text-transform:uppercase;">Copy</button>
                            <pre style="background:#1e1e1e; padding:15px; border-radius:12px; font-family:monospace; font-size:11px; white-space:pre-wrap; margin:0; border:1px solid #333; color:#d4d4d4; line-height:1.5; box-shadow: inset 0 2px 8px rgba(0,0,0,0.4); max-height: 350px; overflow-y: auto; -webkit-overflow-scrolling: touch;">${data.fragment}</pre>
                        </div>
                    </div>
                    <div style="font-size:12px; color:var(--text-secondary); font-style:italic;">This block is injected into the AI's prompt to give it knowledge of your existing data.</div>
                </div>
            `;
            window.openConfirm(titles[type] || 'Context Info', html, null, false, "Done", null);
        }
    } catch(e) { console.error("Context fetch failed", e); }
};

window.aiShowVerbInfo = function(idx) {
    const v = aiVerbLibrary[idx];
    if (!v) return;

    // Split instruction from JSON format mandate
    const parts = v.full.split('Format:');
    const description = parts[0].trim();
    let jsonProtocol = parts[1] ? parts[1].trim() : '';

    // Technical Formatter for Pseudo-JSON
    if (jsonProtocol) {
        try {
            // Try to parse and re-stringify for perfect nesting
            const obj = JSON.parse(jsonProtocol);
            jsonProtocol = JSON.stringify(obj, null, 2);
        } catch(e) {
            // Fallback to manual indentation if it's a partial/pseudo JSON string
            let level = 0;
            let pretty = "";
            for (let i = 0; i < jsonProtocol.length; i++) {
                const char = jsonProtocol[i];
                if (char === '{' || char === '[') {
                    level++;
                    pretty += char + '\n' + '  '.repeat(level);
                } else if (char === '}' || char === ']') {
                    level--;
                    pretty += '\n' + '  '.repeat(level) + char;
                } else if (char === ',' && (jsonProtocol[i+1] === ' ' || jsonProtocol[i+1] === '"')) {
                    pretty += ',\n' + '  '.repeat(level);
                    if (jsonProtocol[i+1] === ' ') i++;
                } else {
                    pretty += char;
                }
            }
            jsonProtocol = pretty;
        }
    }

    // Generate Mock Data for Preview
    const mockMap = {
        'REPLACE_TEXT': { type: 'REPLACE_TEXT', text: "This is a polished version of the user's original voice transcript." },
        'MOVE_FOLDER': { type: 'MOVE_FOLDER', target: 'Work Projects' },
        'CREATE_TODO': { type: 'CREATE_TODO', text: 'Buy some groceries for dinner', list: 'Shopping' },
        'CREATE_NOTE': { type: 'CREATE_NOTE', text: 'Additional context or detailed thoughts...', offset: 1 },
        'CREATE_FOOD': { type: 'CREATE_FOOD', data: { name: 'Oatmeal Cookies', calories: 450, protein: 5, fat: 20, sat_fat: 10, trans_fat: 0, carbs: 65, sugar: 30, sodium: 150, total_weight_g: 200, ref_amount_g: 20, ref_calories: 45, portion_name: 'cookie' } },
        'UPDATE_FOOD': { type: 'UPDATE_FOOD', food_id: 123, data: { name: 'Ricky Oatmeal Mini Bites', calories: 533, protein: 6.7, fat: 26.7, sat_fat: 26.7, trans_fat: 0, carbs: 60, sugar: 16.7, sodium: 212, total_weight_g: 250, ref_amount_g: 3, ref_calories: 16, portion_name: 'piece' } },
        'ADD_FOOD_LOG_DB': { type: 'ADD_FOOD_LOG_DB', data: { name: 'Oatmeal Mini Bites', food_id: 231, meal: 'snack', multiplier: 5, calories: 80, protein: 1 } },
        'ADD_FOOD_LOG_MANUAL': { type: 'ADD_FOOD_LOG_MANUAL', data: { name: 'Homemade Sandwich', meal: 'lunch', calories: 350, protein: 15, fat: 12, carbs: 40, sodium: 450 } },
        'NOTIFY': { type: 'NOTIFY', message: 'Action successfully completed!', type: 'success' },
        'WEB_SEARCH': { type: 'WEB_SEARCH', query: 'nutrition facts for dragon fruit' }
    };
    
    const mockAction = mockMap[v.id] || { type: v.id, text: 'Sample action content' };
    const previewHtml = aiRenderActionWidget(mockAction, 'preview_id');

    const html = `
        <div style="text-align:left; display:flex; flex-direction:column; gap:20px;">
            <div>
                <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">Behavioral Instruction</div>
                <div style="font-size:14px; line-height:1.5; color:var(--text-primary);">${description}</div>
            </div>

            <div>
                <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">Visual Preview</div>
                <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:16px; padding:4px 16px 16px 16px; box-shadow:var(--shadow-card);">
                    ${previewHtml}
                </div>
            </div>

            ${jsonProtocol ? `
            <div>
                <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">JSON Protocol Mandate</div>
                <div style="position:relative;">
                    <button onclick="aiCopyText(this.nextElementSibling.innerText)" style="position:absolute; top:8px; right:8px; background:rgba(0,0,0,0.05); border:1px solid var(--border-color); color:var(--primary); font-size:9px; font-weight:900; padding:4px 8px; border-radius:6px; cursor:pointer; z-index:10; text-transform:uppercase;">Copy</button>
                    <pre style="background:var(--btn-bg); padding:12px; border-radius:10px; font-family:monospace; font-size:11px; white-space:pre-wrap; margin:0; border:1px solid var(--border-color); color:var(--primary); line-height:1.4; max-height: 200px; overflow-y: auto; -webkit-overflow-scrolling: touch;">${jsonProtocol}</pre>
                </div>
            </div>
            ` : ''}
        </div>
    `;

    window.openConfirm(
        `Capability: ${v.id}`, 
        html, 
        null, false, "Done", null
    );
};

window.aiCopyVerbInstruction = function(idx) {
    const v = aiVerbLibrary[idx];
    const instruction = `Return JSON: ${v.usage}`;
    
    if (typeof window.openConfirm === "function") {
        window.openConfirm(
            "Copy Instruction?",
            `Would you like to copy the prompt instruction for ${v.id} to your clipboard?`,
            () => {
                navigator.clipboard.writeText(instruction).then(() => {
                    const t = document.getElementById("toast");
                    if(t) { t.innerText = "Instruction Copied"; t.classList.add("show"); setTimeout(()=>t.classList.remove("show"), 2000); }
                });
            }
        );
    }
};

window.aiOpenAssistantStudio = function(id) {
    currentStudioId = id === 'new' ? 'as_' + Date.now() : id;
    const a = aiAssistants.find(x => x.id === id) || {
        id: currentStudioId, name: "", nickname: "", trigger_phrases: "", role_desc: "", prompt: "", model_override: "", temperature: 0.7, commit_mode: "suggestion", workflow_json: ""
    };

    const identityHtml = `
        <div style="padding:16px; background:var(--bg-color); display:flex; flex-direction:column; gap:16px;">
            <div class="setting-item vertical" style="padding:0; border:none;"><label class="setting-label">Robot Name</label><input type="text" id="as-name" style="height:44px;"></div>
            <div class="setting-item vertical" style="padding:0; border:none;"><label class="setting-label">Nickname</label><input type="text" id="as-nickname" style="height:44px;"></div>
            <div class="setting-item vertical" style="padding:0; border:none;"><label class="setting-label">Role Description</label><textarea id="as-role-desc" style="height:60px;"></textarea></div>
            <div class="setting-item vertical" style="padding:0; border:none;"><label class="setting-label">Trigger Phrases</label><input type="text" id="as-trigger-phrases" style="height:44px;"><span class="setting-desc" style="font-size:10px; margin-top:4px;">Comma-separated words or phrases.</span></div>
            <div class="setting-item vertical" style="padding:0; border:none;"><label class="setting-label">Commit Mode</label><div class="setting-desc" style="margin-bottom:8px;">Current: <span id="as-commit-mode-display" style="font-weight:600; color:var(--primary);">Suggestion</span></div><button onclick="aiPickCommitMode()" class="text-btn" style="width:100%; text-align:center; background:var(--card-bg); border: 1px solid var(--border-color); padding:12px; border-radius:10px; font-weight:600;">Change Mode</button><input type="hidden" id="as-commit-mode"></div>
        </div>
    `;

    const brainHtml = `
        <div style="padding:16px; background:var(--bg-color); display:flex; flex-direction:column; gap:16px;">
            ${window.suiAccordion('as-logic-acc', 'Model & Logic Settings', `
                <div style="padding:16px; display:flex; flex-direction:column; gap:16px;">
                    <div class="setting-item vertical" style="padding:0; border:none;">
                        <label class="setting-label">Brain Temperature: <span id="as-temp-val">0.7</span></label>
                        <div class="setting-desc" style="margin-bottom:8px;">Lower is precise, higher is creative.</div>
                        ${window.suiSlider('as-temp-slider', 0, 2, 0.1, a.temperature || 0.7, "document.getElementById('as-temp-val').innerText = this.value")}
                    </div>

                    <div class="setting-item vertical" style="padding:0; border:none;">
                        <label class="setting-label">Model Override</label>
                        <div class="setting-desc" style="margin-bottom:8px;">Current: <span id="as-model-display" data-sui-capture="as-model-display" style="font-weight:600; color:var(--primary);">Inherit Default</span></div>
                        <button onclick="aiPickAssistantModel()" class="text-btn" style="width:100%; text-align:center; background:var(--card-bg); border: 1px solid var(--border-color); padding:12px; border-radius:10px; font-size:13px; font-weight:600;">Select Model</button>
                        <input type="hidden" id="as-model">
                    </div>

                    <div class="setting-item vertical" style="padding:0; border:none;">
                        <label class="setting-label">System Prompt</label>
                        <textarea id="as-prompt" style="height:120px; font-family:monospace; font-size:12px;"></textarea>
                    </div>
                </div>
            `, false)}

            <!-- Context Group -->
            <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:12px; display:flex; flex-direction:column; gap:8px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Context Injection</div>
                    <div style="display:flex; gap:6px;">
                        <button id="ai-context-copy-btn" onclick="aiCopyContextPreview()" class="text-btn" style="display:none; font-size:10px; font-weight:800; padding:2px 8px; background:var(--btn-bg); border-radius:6px; color:var(--primary);">📋 Copy</button>
                        <button onclick="aiRefreshContextPreview(true)" class="text-btn" style="font-size:10px; font-weight:800; padding:2px 8px; background:var(--btn-bg); border-radius:6px; color:var(--primary);">👁️ Preview</button>
                    </div>
                </div>
                
                <div id="ai-context-preview-box" style="display:none; background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:10px; font-family:monospace; font-size:10px; white-space:pre-wrap; max-height:200px; overflow-y:auto; margin-bottom:8px; border:1px solid #333;"></div>
                
                <!-- Dedicated Reference Preview -->
                <div id="ai-discovery-map-box" style="display:none; background:#1e1e1e; color:#8E8E93; padding:12px; border-radius:10px; font-family:monospace; font-size:10px; white-space:pre-wrap; max-height:150px; overflow-y:auto; margin-bottom:8px; border:1px solid #333; box-shadow: inset 0 2px 8px rgba(0,0,0,0.2);"></div>

                <div class="setting-item" style="background:var(--bg-color); border-radius:10px; padding:10px 14px;">
                    <label class="setting-label" style="font-size:14px; margin:0; display:flex; align-items:center; gap:8px;">
                        <span data-sui-icon="chevron" id="as-food-tiers-arrow" data-sui-size="12" style="transition:transform 0.3s; transform:rotate(-90deg); opacity:0.5;"></span>
                        Food Database <span id="lbl-ctx-food-count" style="font-weight:400; opacity:0.6; font-size:11px;"></span>
                    </label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <button onclick="aiShowContextInfo('food_master')" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                            <span data-sui-icon="info" data-sui-size="16" data-sui-stroke="2.5"></span>
                        </button>
                        <div data-sui-switch="true" data-sui-id="ctx-food-master" data-sui-onchange="aiToggleFoodTiers(this.checked); aiRefreshPreviews();"></div>
                    </div>
                </div>
                <div id="ctx-food-tiers" class="sui-accordion" style="display:none;">
                    <div class="sui-accordion-inner" style="display:flex; flex-direction:column; gap:4px; padding-left:15px; padding-bottom:8px;">
                        <div class="setting-item" style="background:var(--bg-color); border-radius:10px; padding:8px 12px; min-height:40px;">
                            <label class="setting-label" style="font-size:12px; margin:0; color:var(--primary);">Discovery Mode (Multi-Turn)</label>
                            <div style="display:flex; align-items:center; gap:12px;">
                                <button onclick="aiShowContextInfo('discovery')" style="background:var(--btn-bg); border:none; width:24px; height:24px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                                    <span data-sui-icon="info" data-sui-size="14" data-sui-stroke="2.5"></span>
                                </button>
                                <div data-sui-switch="true" data-sui-id="ctx-food-discovery" data-sui-onchange="aiUpdateDiscoveryUI(); aiRefreshPreviews();"></div>
                            </div>
                        </div>
                        <!-- Discovery Lab Simulator -->
                        <div id="ai-discovery-lab" style="display:none; background:var(--card-bg); border:1px dashed var(--primary); border-radius:10px; padding:12px; margin:4px 0 8px 0;">
                            <div style="font-size:10px; font-weight:800; color:var(--primary); text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:5px;">
                                <span>🧪 Discovery Lab</span>
                                <span style="font-weight:400; opacity:0.6; text-transform:none;">(Simulate Turn 2)</span>
                                <span id="ai-sim-count" style="margin-left:auto; opacity:0.8;"></span>
                            </div>
                            <div style="display:flex; gap:8px;">
                                <input type="text" id="ai-sim-search-query" placeholder="Enter keywords (e.g. apple, egg)" style="flex:1; height:32px; font-size:12px; padding:0 10px;">
                                <button onclick="aiSimulateDiscovery()" class="text-btn" style="background:var(--primary); color:white; font-size:10px; padding:0 12px; border-radius:6px; height:32px; font-weight:700;">TEST</button>
                            </div>
                            <div id="ai-sim-results-box" style="display:none; margin-top:10px; background:#1e1e1e; color:#34C759; padding:10px; border-radius:8px; font-family:monospace; font-size:10px; white-space:pre-wrap; max-height:150px; overflow-y:auto; border:1px solid #333;"></div>
                        </div>
                        <div class="setting-item" style="background:var(--bg-color); border-radius:10px; padding:8px 12px; min-height:40px; opacity:0.7;">
                            <label class="setting-label" style="font-size:12px; margin:0;"><span class="ctx-icon-search">🔍</span><span class="ctx-food-indicator"></span> Item Names (Required) <span id="lbl-ctx-food-names-count" style="font-weight:400; opacity:0.6; font-size:10px;"></span></label>
                            <div data-sui-switch="true" data-sui-id="ctx-food-names" data-sui-checked="true"></div>
                        </div>
                        <div class="setting-item" style="background:var(--bg-color); border-radius:10px; padding:8px 12px; min-height:40px;">
                            <label class="setting-label" style="font-size:12px; margin:0;"><span class="ctx-food-indicator">📦</span> Metrics (Weight/Servings) <span id="lbl-ctx-food-metrics-count" style="font-weight:400; opacity:0.6; font-size:10px;"></span></label>
                            <div data-sui-switch="true" data-sui-id="ctx-food-metrics" data-sui-onchange="aiRefreshPreviews()"></div>
                        </div>
                        <div class="setting-item" style="background:var(--bg-color); border-radius:10px; padding:8px 12px; min-height:40px;">
                            <label class="setting-label" style="font-size:12px; margin:0;"><span class="ctx-food-indicator">📦</span> Nutrition Facts <span id="lbl-ctx-food-nutrition-count" style="font-weight:400; opacity:0.6; font-size:10px;"></span></label>
                            <div data-sui-switch="true" data-sui-id="ctx-food-nutrition" data-sui-onchange="aiRefreshPreviews()"></div>
                        </div>
                        <div class="setting-item" style="background:var(--bg-color); border-radius:10px; padding:8px 12px; min-height:40px; opacity:0.6;">
                            <label class="setting-label" style="font-size:12px; margin:0; display:flex; align-items:center; gap:4px;">
                                <span data-sui-icon="clock" data-sui-size="14"></span> Recently Used <span id="lbl-ctx-recent-count" style="font-weight:400; opacity:0.6; font-size:10px;"></span>
                            </label>
                            <div style="display:flex; align-items:center; gap:12px;">
                                <button onclick="aiShowContextInfo('recent_usage')" style="background:var(--btn-bg); border:none; width:24px; height:24px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                                    <span data-sui-icon="info" data-sui-size="14" data-sui-stroke="2.5"></span>
                                </button>
                                <div data-sui-switch="true" data-sui-id="ctx-recent-always" data-sui-checked="true" data-sui-disabled="true"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="setting-item" style="background:var(--bg-color); border-radius:10px; padding:10px 14px;">
                    <label class="setting-label" style="font-size:14px; margin:0;">Note Folders <span id="lbl-ctx-folders-count" style="font-weight:400; opacity:0.6; font-size:11px;"></span></label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <button onclick="aiShowContextInfo('folders')" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                            <span data-sui-icon="info" data-sui-size="16" data-sui-stroke="2.5"></span>
                        </button>
                        <div data-sui-switch="true" data-sui-id="ctx-folders" data-sui-onchange="aiRefreshPreviews()"></div>
                    </div>
                </div>
                <div class="setting-item" style="background:var(--bg-color); border-radius:10px; padding:10px 14px;">
                    <label class="setting-label" style="font-size:14px; margin:0;">To-Do Lists <span id="lbl-ctx-todo-count" style="font-weight:400; opacity:0.6; font-size:11px;"></span></label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <button onclick="aiShowContextInfo('todo')" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                            <span data-sui-icon="info" data-sui-size="16" data-sui-stroke="2.5"></span>
                        </button>
                        <div data-sui-switch="true" data-sui-id="ctx-todo" data-sui-onchange="aiRefreshPreviews()"></div>
                    </div>
                </div>
            </div>

            <!-- Verb Capabilities Group -->
            <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:12px; display:flex; flex-direction:column; gap:8px;">
                <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Capabilities (Verbs)</div>
                <div id="ai-verb-toggles-list" style="display:flex; flex-direction:column; gap:4px;"></div>
                
                <!-- Max Turns Slider -->
                <div class="setting-item vertical" style="margin-top:12px; border-top:1px solid var(--border-color); padding-top:16px;">
                    <label class="setting-label">Autonomous Turn Limit: <span id="as-turns-val">2</span></label>
                    <div class="setting-desc" style="margin-bottom:8px;">Max turns for research and discovery.</div>
                    ${window.suiSlider('as-turns-slider', 1, 10, 1, a.max_turns || 2, "document.getElementById('as-turns-val').innerText = this.value; aiRefreshApiPreview();")}
                </div>
            </div>

            <!-- API Payload Preview -->
            <div style="margin-top:8px; border-top:1px solid var(--border-color); padding-top:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">API Payload Preview</div>
                    <div style="display:flex; gap:6px;">
                        <button id="ai-api-json-btn" onclick="aiOpenApiPreviewInJson()" class="text-btn" style="display:none; font-size:10px; font-weight:800; padding:2px 8px; background:var(--btn-bg); border-radius:6px; color:var(--ai-accent);">{} JSON</button>
                        <button id="ai-api-copy-btn" onclick="aiCopyApiPreview()" class="text-btn" style="display:none; font-size:10px; font-weight:800; padding:2px 8px; background:var(--btn-bg); border-radius:6px; color:var(--primary);">📋 Copy</button>
                        <button onclick="aiRefreshApiPreview(true)" class="text-btn" style="font-size:10px; font-weight:800; padding:2px 8px; background:var(--btn-bg); border-radius:6px; color:var(--primary);">👁️ Preview Call</button>
                    </div>
                </div>
                <div id="ai-api-preview-box" style="display:none; background:#1e1e1e; color:#d4d4d4; padding:15px; border-radius:12px; font-family:monospace; font-size:11px; line-height:1.5; white-space:pre-wrap; max-height:350px; overflow-y:auto; border:1px solid #333; box-shadow: inset 0 2px 10px rgba(0,0,0,0.3);"></div>
            </div>
        </div>
    `;

    const content = `
        <div id="ai-flowchart-container" style="background:var(--bg-color); border:1px solid var(--border-color); border-radius:16px; padding:20px; margin-bottom:16px; display:flex; flex-direction:column; align-items:center; gap:10px;"></div>
        
        <div style="padding:0 4px 20px 4px;">
            <div data-sui-setting="Robot Active" data-sui-desc="Enable or disable this robot." data-sui-id="as-active-toggle"></div>
        </div>

        <div style="margin-bottom:12px;">
            ${window.suiAccordion('as-identity-acc', 'Identity & Behavior', identityHtml, false)}
        </div>

        <div style="margin-bottom:24px;">
            ${window.suiAccordion('as-brain-acc', 'Brain Configuration', brainHtml, false)}
        </div>

        <div style="display:flex; gap:12px; margin-top:20px;">
            <button onclick="aiDeleteAssistant()" id="as-delete-btn" class="text-btn" style="flex:1; background:rgba(255, 59, 48, 0.1); color:var(--danger); border-radius:12px; padding:14px; font-weight:700;">Delete</button>
            <button onclick="aiSaveAssistant()" class="text-btn" style="flex:2; background:var(--primary); color:var(--primary-text); border-radius:12px; padding:14px; font-weight:700;">Save Robot</button>
        </div>
    `;

    window.sui.openStudio({
        id: 'assistant',
        title: 'Assistant Studio' + (a.name ? ': ' + a.name : ''),
        content: content,
        hasChanges: aiHasChanges,
        onSave: aiSaveAssistant,
        onSetup: (container, overlay) => {
            // Real-time Header Sync
            const nameInput = document.getElementById("as-name");
            const titleEl = overlay.querySelector('.sui-studio-title');
            if (nameInput && titleEl) {
                nameInput.addEventListener('input', () => {
                    const val = nameInput.value.trim();
                    titleEl.innerText = 'Assistant Studio' + (val ? ': ' + val : '');
                });
            }

            nameInput.value = a.name || "";
            document.getElementById("as-nickname").value = a.nickname || "";
            document.getElementById("as-trigger-phrases").value = a.trigger_phrases || "";
            document.getElementById("as-role-desc").value = a.role_desc || "";
            document.getElementById("as-prompt").value = a.prompt || "";
            document.getElementById("as-model").value = a.model_override || "";
            document.getElementById("as-model-display").innerText = a.model_override || "Inherit Default";
            document.getElementById("as-commit-mode").value = a.commit_mode || "suggestion";
            document.getElementById("as-commit-mode-display").innerText = a.commit_mode === 'direct' ? 'Direct (Auto-Apply)' : 'Suggestion (Queue)';
            document.getElementById("as-active-toggle").checked = a.is_active !== 0;
            document.getElementById("as-temp-slider").value = a.temperature || 0.7;
            document.getElementById("as-temp-val").innerText = a.temperature || 0.7;
            document.getElementById("as-turns-slider").value = a.max_turns || 2;
            document.getElementById("as-turns-val").innerText = a.max_turns || 2;
            
            // Hydrate Context Checks
            const ctx = a.context_config || { food: false, folders: true, todo: true };
            const foodMaster = ctx.food_master === true || (ctx.food === true && ctx.food_master === undefined);
            const foodNames = true; // Mandatory
            const foodMetrics = ctx.food_metrics === true || (ctx.food === true && ctx.food_metrics === undefined);
            const foodNutrition = ctx.food_nutrition === true || (ctx.food === true && ctx.food_nutrition === undefined);

            document.getElementById("ctx-food-master").checked = foodMaster;
            const nameToggle = document.getElementById("ctx-food-names");
            nameToggle.checked = true;
            nameToggle.disabled = true;
            document.getElementById("ctx-food-metrics").checked = foodMetrics;
            document.getElementById("ctx-food-nutrition").checked = foodNutrition;
            document.getElementById("ctx-food-discovery").checked = ctx.food_discovery === true;
            
            aiToggleFoodTiers(foodMaster, true);
            aiUpdateDiscoveryUI();
            
            // Reset Preview State
            const previewBox = document.getElementById("ai-context-preview-box");
            const copyBtn = document.getElementById("ai-context-copy-btn");
            if (previewBox) previewBox.style.display = "none";
            if (copyBtn) copyBtn.style.display = "none";
            
            const apiBox = document.getElementById("ai-api-preview-box");
            if (apiBox) apiBox.style.display = "none";
            window._lastApiPayload = null;

            // Live Listeners for API Preview
            document.getElementById("as-prompt").addEventListener("input", () => aiRefreshApiPreview());
            document.getElementById("as-temp-slider").addEventListener("input", () => aiRefreshApiPreview());

            document.getElementById("ctx-folders").checked = ctx.folders === true;
            document.getElementById("ctx-todo").checked = ctx.todo === true;

            // Hydrate Verb Toggles
            const verbs = a.verbs_config || {};
            const verbListCont = document.getElementById("ai-verb-toggles-list");
            verbListCont.innerHTML = aiVerbLibrary.map((v, idx) => `
                <div class="setting-item" style="background:var(--bg-color); border-radius:10px; padding:8px 12px; min-height:44px;">
                    <div style="display:flex; flex-direction:column; flex:1;">
                        <label class="setting-label" style="font-size:13px; margin:0;">${v.id}</label>
                        <span style="font-size:10px; opacity:0.6;">${v.desc}</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <button onclick="aiShowVerbInfo(${idx})" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                            <span data-sui-icon="info" data-sui-size="16" data-sui-stroke="2.5"></span>
                        </button>
                        <div data-sui-switch="true" data-sui-id="verb-${v.id}" data-sui-checked="${verbs[v.id] === true}" data-sui-onchange="aiRefreshApiPreview()"></div>
                    </div>
                </div>
            `).join('');
            window.suiHydrateSettings(verbListCont);
            window.suiHydrateIcons(verbListCont);

            document.getElementById("as-delete-btn").style.display = id === 'new' ? 'none' : 'block';
            
            // Capture exactly what the DOM holds after hydration to prevent precision/type mismatches
            aiInitialState = window.sui.takeSnapshot(overlay);
            aiRenderFlowchart(a);
        }
    });
};

window.aiPickAssistantModel = function() {
    if (typeof window.openModelPicker === "function") {
        window.openModelPicker();
        
        // Use a temporary global override that OpenRouterAI's selectModel will call
        const origSelect = window.selectModel;
        window.selectModel = (id) => {
            // Update the Studio UI
            const input = document.getElementById("as-model");
            const display = document.getElementById("as-model-display");
            
            if (input) input.value = id;
            if (display) {
                display.innerText = id;
                display.style.color = "var(--text-primary)";
            }
            
            // Restore and close
            window.selectModel = origSelect; 
            if (typeof window.closeAiManager === "function") window.closeAiManager();
        };
    } else {
        window.openConfirm("Plugin Required", "OpenRouter plugin required for model picker.", null, false, "OK", null);
    }
};

window.aiPickCommitMode = function() {
    const options = [
        { label: "Suggestion (Queue)", value: "suggestion" },
        { label: "Direct (Auto-Apply)", value: "direct" }
    ];
    const current = document.getElementById("as-commit-mode").value;
    
    window.openPicker("Commit Mode", options, current, (val) => {
        document.getElementById("as-commit-mode").value = val;
        const modeDisplay = document.getElementById("as-commit-mode-display");
        if (modeDisplay) {
            modeDisplay.innerText = val === "direct" ? "Direct (Auto-Apply)" : "Suggestion (Queue)";
            modeDisplay.style.color = val === "direct" ? "var(--text-primary)" : "var(--primary)";
        }
        aiRenderFlowchart({ name: document.getElementById("as-name").value, commit_mode: val });
    });
};

window.aiCopyContextPreview = function() {
    const box = document.getElementById("ai-context-preview-box");
    if (!box || !box.innerText) return;
    
    const text = "```\n" + box.innerText.trim() + "\n```";
    navigator.clipboard.writeText(text).then(() => {
        const t = document.getElementById("toast");
        if(t) {
            t.innerText = "Context Copied";
            t.classList.add("show");
            setTimeout(() => t.classList.remove("show"), 2000);
        }
    });
};

window.aiRefreshPreviews = function() {
    aiRefreshContextPreview();
    aiRefreshApiPreview();
};

window.aiOpenApiPreviewInJson = function() {
    if (window._lastApiPayload && typeof window.fsOpenJson === 'function') {
        window.fsOpenJson(window._lastApiPayload, "API Payload Preview");
    }
};

window.aiCopyApiPreview = function() {
    const box = document.getElementById("ai-api-preview-box");
    if (!box || !box.innerText) return;
    const text = "```json\n" + box.innerText.trim() + "\n```";
    navigator.clipboard.writeText(text).then(() => {
        const t = document.getElementById("toast");
        if(t) { t.innerText = "Payload Copied"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
    });
};

window.aiRefreshApiPreview = async function(isExplicitToggle = false) {
    const box = document.getElementById("ai-api-preview-box");
    const copyBtn = document.getElementById("ai-api-copy-btn");
    const jsonBtn = document.getElementById("ai-api-json-btn");
    if (!box) return;

    const isVisible = box.style.display === "block";

    if (isExplicitToggle) {
        if (isVisible) {
            box.style.display = "none";
            if (copyBtn) copyBtn.style.display = "none";
            if (jsonBtn) jsonBtn.style.display = "none";
            return;
        } else {
            box.style.display = "block";
            if (copyBtn) copyBtn.style.display = "block";
            if (jsonBtn) jsonBtn.style.display = "block";
        }
    } else if (!isVisible) {
        return; // Live update but box is closed
    }

    box.innerHTML = "Generating payload...";

    const ctx = {
        food_master: document.getElementById("ctx-food-master").checked,
        food_discovery: document.getElementById("ctx-food-discovery").checked,
        food_names: true,
        food_metrics: document.getElementById("ctx-food-metrics").checked,
        food_nutrition: document.getElementById("ctx-food-nutrition").checked,
        folders: document.getElementById("ctx-folders").checked,
        todo: document.getElementById("ctx-todo").checked
    };

    const verbs = {};
    aiVerbLibrary.forEach(v => {
        const el = document.getElementById("verb-" + v.id);
        if (el) verbs[v.id] = el.checked;
    });

    const payloadData = {
        prompt: document.getElementById("as-prompt").value,
        temperature: parseFloat(document.getElementById("as-temp-slider").value),
        model: document.getElementById("as-model").value,
        max_turns: parseInt(document.getElementById("as-turns-slider").value),
        context_config: ctx,
        verbs_config: verbs
    };

    try {
        const data = await window.sui.api("ai_get_api_preview", payloadData, { toast: false });
        if (data && data.payload) {
            window._lastApiPayload = data.payload;
            // Unescape newline and carriage return characters for visual rendering
            const prettyJson = JSON.stringify(data.payload, null, 2);
            box.innerText = prettyJson.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
            box.scrollTop = 0;
        }
    } catch(e) { box.innerText = "Error generating payload."; }
};

window.aiRefreshContextPreview = async function(isExplicitToggle = false) {
    const box = document.getElementById("ai-context-preview-box");
    const mapBox = document.getElementById("ai-discovery-map-box");
    const copyBtn = document.getElementById("ai-context-copy-btn");
    if (!box) return;

    const isVisible = box.style.display === "block";
    const discoveryOn = document.getElementById("ctx-food-discovery").checked;

    if (isExplicitToggle) {
        if (isVisible) {
            box.style.display = "none";
            mapBox.style.display = "none";
            if (copyBtn) copyBtn.style.display = "none";
            return;
        } else {
            box.style.display = "block";
            mapBox.style.display = discoveryOn ? "block" : "none";
            if (copyBtn) copyBtn.style.display = "block";
        }
    } else if (!isVisible) {
        return; 
    }

    box.innerHTML = "Generating preview...";
    if (discoveryOn) {
        mapBox.style.display = "block";
        mapBox.innerHTML = "Generating reference...";
    } else {
        mapBox.style.display = "none";
    }
    
    const ctx = {
        food_master: document.getElementById("ctx-food-master").checked,
        food_discovery: discoveryOn,
        food_names: true,
        food_metrics: document.getElementById("ctx-food-metrics").checked,
        food_nutrition: document.getElementById("ctx-food-nutrition").checked,
        folders: document.getElementById("ctx-folders").checked,
        todo: document.getElementById("ctx-todo").checked
    };

    try {
        const data = await window.sui.api("ai_get_context_preview", { context_config: ctx }, { toast: false });
        if (data && data.primary) {
            box.innerText = data.primary;
            box.scrollTop = 0;
            
            if (discoveryOn && data.reference) {
                mapBox.innerText = data.reference;
                mapBox.scrollTop = 0;
            }
            
            // Hydrate Labels
            if (data.counts) {
                const c = data.counts;
                const setLabel = (id, count) => {
                    const el = document.getElementById(id);
                    if (el) el.innerText = count > 0 ? `(${count})` : "";
                };
                setLabel("lbl-ctx-food-count", c.food);
                setLabel("lbl-ctx-food-names-count", c.food);
                setLabel("lbl-ctx-food-metrics-count", c.food);
                setLabel("lbl-ctx-food-nutrition-count", c.food);
                setLabel("lbl-ctx-recent-count", c.recent);
                setLabel("lbl-ctx-folders-count", c.folders);
                setLabel("lbl-ctx-todo-count", c.todo);
            }
        }
    } catch(e) {
        box.innerText = "Error generating preview.";
    }
};

window.aiUpdateDiscoveryUI = function() {
    const isDiscovery = document.getElementById("ctx-food-discovery").checked;
    
    // Update Icons
    document.querySelectorAll(".ctx-food-indicator").forEach(el => {
        el.innerText = isDiscovery ? "📦" : "";
    });

    // Toggle Lab
    const lab = document.getElementById("ai-discovery-lab");
    if (lab) lab.style.display = isDiscovery ? "block" : "none";
    
    // Clear simulation results on toggle
    const simResults = document.getElementById("ai-sim-results-box");
    const countLabel = document.getElementById("ai-sim-count");
    if (simResults) {
        simResults.style.display = "none";
        simResults.innerText = "";
    }
    if (countLabel) countLabel.innerText = "";
};

window.aiSimulateDiscovery = async function() {
    const query = document.getElementById("ai-sim-search-query").value.trim();
    const box = document.getElementById("ai-sim-results-box");
    const countLabel = document.getElementById("ai-sim-count");
    if (!query) return;

    box.style.display = "block";
    box.style.color = "#8E8E93";
    box.innerText = "Searching database...";
    if (countLabel) countLabel.innerText = "";

    try {
        const data = await window.sui.api("ai_discover_data", { query: query }, { toast: false });
        if (data && data.results) {
            box.style.color = "#34C759";
            const count = data.results.length;
            if (countLabel) countLabel.innerText = count + " items";
            
            if (count === 0) {
                box.innerText = "--- TURN 2 CONTEXT ---\nNo matching items found.";
            } else {
                box.innerText = "--- TURN 2 CONTEXT ---\n" + JSON.stringify(data.results, null, 2);
            }
            box.scrollTop = 0;
        }
    } catch(e) {
        box.style.color = "var(--danger)";
        box.innerText = "Search Error: " + e.message;
    }
};

window.aiToggleFoodTiers = function(enabled, immediate = false) {
    const tiers = document.getElementById("ctx-food-tiers");
    const arrow = document.getElementById("as-food-tiers-arrow");
    if (arrow) arrow.style.transform = enabled ? 'rotate(0deg)' : 'rotate(-90deg)';
    if (!tiers) return;
    
    if (enabled) {
        tiers.style.display = 'grid';
        if (immediate) {
            tiers.classList.add('open');
        } else {
            void tiers.offsetHeight; // Force reflow
            tiers.classList.add('open');
        }
    } else {
        tiers.classList.remove('open');
        if (immediate) {
            tiers.style.display = 'none';
        } else {
            // Wait for transition (350ms) before removing from flow
            setTimeout(() => {
                if (!tiers.classList.contains('open')) tiers.style.display = 'none';
            }, 350);
        }
    }
};

function aiHasChanges() {
    const overlay = document.getElementById('sui-studio-assistant');
    return window.sui.hasChanges(overlay, aiInitialState);
}

window.aiPerformCloseStudio = () => window.sui.closeStudio('assistant');

window.aiCopyText = function(text) {
    const wrapped = "```\n" + text.trim() + "\n```";
    navigator.clipboard.writeText(wrapped).then(() => {
        const t = document.getElementById("toast");
        if(t) {
            t.innerText = "Copied to Clipboard";
            t.classList.add("show");
            setTimeout(() => t.classList.remove("show"), 2000);
        }
    });
};

window.aiShowAuditLog = async function() {
    try {
        const data = await window.sui.api("ai_get_audit", {}, { toast: false });
        
        if (data) {
            const options = data.audit.map(item => {
                const time = new Date(item.timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                const color = item.event_type === 'ERROR' ? 'var(--danger)' : (item.event_type === 'SUCCESS' ? '#34C759' : 'var(--text-secondary)');
                return {
                    label: `<div style="display:flex; flex-direction:column; gap:2px; text-align:left; width:100%;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <span style="font-size:9px; font-weight:800; color:${color}; text-transform:uppercase;">${item.event_type} • ${time}</span>
                                    <span style="font-size:9px; opacity:0.5;">ID: ${item.log_id.substring(0,8)}</span>
                                </div>
                                <div style="font-size:13px; font-weight:600; color:var(--text-primary);">${item.message}</div>
                                ${item.details ? `<div style="font-size:10px; color:var(--text-secondary); opacity:0.7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${item.details}</div>` : ''}
                            </div>`,
                    value: item.id
                };
            });

            if (options.length === 0) {
                window.openConfirm("Audit Log", "No activity recorded yet.", null, false, "OK", null);
                return;
            }

            window.openPicker("AI Audit Log", options, null, (val) => {
                const entry = data.audit.find(x => x.id == val);
                if (entry && entry.details) {
                    window.openConfirm("Entry Details", "Full Details:\n\n" + entry.details, null, false, "OK", null);
                }
            });
        }
    } catch(e) { window.openConfirm("Error", "Failed to load audit log.", null, false, "OK", null); }
};

window.aiOpenDispatcherStudio = function() {
    const includeHtml = `
        <div style="padding:16px; background:var(--bg-color); display:flex; flex-direction:column; gap:12px;">
            <div class="setting-item" style="background:var(--card-bg); border-radius:10px; padding:10px 14px;"><label class="setting-label" style="font-size:14px; margin:0;">Include Name</label><div data-sui-switch="true" data-sui-id="ds-include-name"></div></div>
            <div class="setting-item" style="background:var(--card-bg); border-radius:10px; padding:10px 14px;"><label class="setting-label" style="font-size:14px; margin:0;">Include Nickname</label><div data-sui-switch="true" data-sui-id="ds-include-nickname"></div></div>
            <div class="setting-item" style="background:var(--card-bg); border-radius:10px; padding:10px 14px;"><label class="setting-label" style="font-size:14px; margin:0;">Include Triggers</label><div data-sui-switch="true" data-sui-id="ds-include-triggers"></div></div>
            <div class="setting-item" style="background:var(--card-bg); border-radius:10px; padding:10px 14px;"><label class="setting-label" style="font-size:14px; margin:0;">Include Role</label><div data-sui-switch="true" data-sui-id="ds-include-role"></div></div>
            <div class="setting-item" style="background:var(--card-bg); border-radius:10px; padding:10px 14px;"><label class="setting-label" style="font-size:14px; margin:0;">Include Prompt</label><div data-sui-switch="true" data-sui-id="ds-include-prompt"></div></div>
        </div>
    `;

    const modelHtml = `
        <div style="padding:16px; background:var(--bg-color); display:flex; flex-direction:column; gap:16px;">
            <div class="setting-item vertical" style="padding:0; border:none;">
                <label class="setting-label">Routing Model</label>
                <div class="setting-desc" style="margin-bottom:8px;">Current: <span id="ds-model-display" data-sui-capture="ds-model-display" style="font-weight:600; color:var(--primary);">Default</span></div>
                <button onclick="aiPickDispatcherModel()" class="text-btn" style="width:100%; background:var(--card-bg); border: 1px solid var(--border-color); padding:12px; border-radius:10px; font-weight:600;">Select Model</button>
            </div>
            
            <div class="setting-item vertical" style="padding:0; border:none;">
                <label class="setting-label">Temperature: <span id="ds-temp-val">0.1</span></label>
                <div class="setting-desc" style="margin-bottom:8px;">Lower is better for routing accuracy.</div>
                ${window.suiSlider('ds-temp-slider', 0, 1, 0.05, aiConfig.dispatcher_temperature || 0.1, "document.getElementById('ds-temp-val').innerText = this.value")}
            </div>

            <div class="setting-item vertical" style="padding:0; border:none;">
                <label class="setting-label">Intent Analysis Prompt</label>
                <textarea id="ds-prompt" style="height:100px; font-family:monospace; font-size:12px;"></textarea>
            </div>
        </div>
    `;

    const content = `
        <div id="ai-dispatcher-flowchart" style="background:var(--bg-color); border:1px solid var(--border-color); border-radius:16px; padding:20px; margin-bottom:20px; display:flex; flex-direction:column; align-items:center;"></div>
        
        <div style="padding:0 4px 16px 4px;">
            <div data-sui-setting="Mechanical Mode" data-sui-desc="Route by Name match only." data-sui-id="ds-mechanical-mode" data-sui-onchange="aiUpdateMechanicalUI()"></div>
        </div>

        <div style="margin-bottom:12px;">
            ${window.suiAccordion('ds-include-acc', 'Metadata Inclusion', includeHtml, false)}
        </div>

        <div style="margin-bottom:24px;">
            ${window.suiAccordion('ds-model-acc', 'Brain & Routing', modelHtml, false)}
        </div>

        <button onclick="aiSaveDispatcherStudio()" class="text-btn" style="width:100%; background:var(--primary); color:white; border-radius:12px; padding:16px; font-weight:700;">Save Dispatcher Settings</button>
    `;

    window.sui.openStudio({
        id: 'dispatcher',
        title: 'Dispatcher Configuration',
        content: content,
        hasChanges: aiHasDsChanges,
        onSave: aiSaveDispatcherStudio,
        onSetup: (container) => {
            const modelVal = aiConfig.dispatcher_model || "";
            document.getElementById("ds-model-display").innerText = modelVal || "Inheriting Default";
            document.getElementById("ds-prompt").value = aiConfig.dispatcher_prompt || "";
            document.getElementById("ds-include-name").checked = aiConfig.include_name !== false;
            document.getElementById("ds-include-nickname").checked = aiConfig.include_nickname !== false;
            document.getElementById("ds-include-role").checked = aiConfig.include_role !== false;
            document.getElementById("ds-include-prompt").checked = aiConfig.include_prompt === true;
            document.getElementById("ds-include-triggers").checked = aiConfig.include_triggers !== false;
            document.getElementById("ds-mechanical-mode").checked = aiConfig.mechanical_mode === true;
            
            const tempVal = aiConfig.dispatcher_temperature || 0.1;
            document.getElementById("ds-temp-slider").value = tempVal;
            document.getElementById("ds-temp-val").innerText = tempVal;
            
            dsInitialState = window.sui.takeSnapshot(container.closest('.shared-menu-overlay'));
            aiUpdateMechanicalUI();
            aiRenderDispatcherFlowchart();
        }
    });
};

window.aiCloseDispatcherStudio = () => window.sui.closeStudio('dispatcher', aiHasDsChanges, aiSaveDispatcherStudio);

window.aiPerformCloseDispatcherStudio = () => window.sui.closeStudio('dispatcher');

function aiHasDsChanges() {
    const overlay = document.getElementById('sui-studio-dispatcher');
    return window.sui.hasChanges(overlay, dsInitialState);
}

window.aiPickDispatcherModel = function() {
    if (typeof window.openModelPicker === "function") {
        window.openModelPicker();
        const origSelect = window.selectModel;
        window.selectModel = (id) => {
            aiConfig.dispatcher_model = id;
            document.getElementById("ds-model-display").innerText = id;
            document.getElementById("ds-model-display").style.color = "var(--text-primary)";
            window.selectModel = origSelect; 
            if (typeof window.closeAiManager === "function") window.closeAiManager();
        };
    }
};

window.aiUpdateMechanicalUI = function() {
    const isMech = document.getElementById("ds-mechanical-mode").checked;
    
    // Target the entire accordions since most settings are bypassed in Mechanical Mode
    const includeAcc = document.getElementById("ds-include-acc")?.previousElementSibling;
    const modelAcc = document.getElementById("ds-model-acc")?.previousElementSibling;

    [includeAcc, modelAcc].forEach(el => {
        if (!el) return;
        if (isMech) {
            el.style.opacity = "0.4";
            el.style.pointerEvents = "none";
        } else {
            el.style.opacity = "1";
            el.style.pointerEvents = "auto";
        }
    });
};

window.aiSaveDispatcherStudio = async function() {
    aiConfig.dispatcher_prompt = document.getElementById("ds-prompt").value.trim();
    aiConfig.include_name = document.getElementById("ds-include-name").checked;
    aiConfig.include_nickname = document.getElementById("ds-include-nickname").checked;
    aiConfig.include_role = document.getElementById("ds-include-role").checked;
    aiConfig.include_prompt = document.getElementById("ds-include-prompt").checked;
    aiConfig.include_triggers = document.getElementById("ds-include-triggers").checked;
    aiConfig.mechanical_mode = document.getElementById("ds-mechanical-mode").checked;
    aiConfig.dispatcher_temperature = parseFloat(document.getElementById("ds-temp-slider").value);
    await aiSaveHubConfig("Dispatcher Settings Saved");
    aiPerformCloseDispatcherStudio(); // Direct close to bypass guard
};

window.aiDispatcherClearPending = function() {
    window.openConfirm("Clear Pending", "Remove the Hollow Dot from all pending notes? This stops AI processing for them.", () => {
        window.sui.api("ai_clear_all_pending", {}, { toast: "All Pending Flags Cleared" }).then(() => {
            location.reload();
        });
    });
};

function aiRenderDispatcherFlowchart() {
    const cont = document.getElementById("ai-dispatcher-flowchart");
    cont.innerHTML = `
        <style>
            .flow-node { background:var(--card-bg); border:1px solid var(--border-color); padding:6px 12px; border-radius:8px; font-size:10px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; box-shadow:0 2px 5px rgba(0,0,0,0.03); }
            .flow-arrow { color:var(--text-secondary); opacity:0.3; font-size:12px; }
            .flow-active { border-color:var(--primary); color:var(--primary-text); background:var(--primary); }
        </style>
        <div class="flow-node">Input Note</div>
        <div class="flow-arrow">▼</div>
        <div class="flow-node flow-active">Dispatcher (Intent Analysis)</div>
        <div class="flow-arrow">▼</div>
        <div style="display:flex; gap:15px; align-items:center;">
            <div style="display:flex; flex-direction:column; align-items:center; gap:5px;">
                <div class="flow-node">Match Found</div>
                <div class="flow-arrow">▼</div>
                <div class="flow-node">Robot Analysis</div>
            </div>
            <div style="font-size:12px; opacity:0.2;">OR</div>
            <div style="display:flex; flex-direction:column; align-items:center; gap:5px;">
                <div class="flow-node" style="color:var(--danger); border-color:#FFCDCD;">No Match</div>
                <div class="flow-arrow">▼</div>
                <div class="flow-node">Ghost Flag (Silent)</div>
            </div>
        </div>
    `;
}

function aiRenderFlowchart(a) {
    const cont = document.getElementById("ai-flowchart-container");
    const name = a.name || "New Robot";
    const modeLabel = a.commit_mode === 'direct' ? "Auto-Apply" : "Confirm";
    
    cont.innerHTML = `
        <style>
            .flow-node { background:var(--card-bg); border:1px solid var(--border-color); padding:6px 12px; border-radius:8px; font-size:10px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; box-shadow:0 2px 5px rgba(0,0,0,0.03); }
            .flow-arrow { color:var(--text-secondary); opacity:0.3; font-size:12px; }
            .flow-active { border-color:var(--primary); color:var(--primary-text); background:var(--primary); }
        </style>
        <div class="flow-node">Input Transcript</div>
        <div class="flow-arrow">▼</div>
        <div class="flow-node">Dispatcher</div>
        <div class="flow-arrow">▼</div>
        <div class="flow-node flow-active">${name}</div>
        <div class="flow-arrow">▼</div>
        <div style="display:flex; gap:8px; align-items:center;">
            <div class="flow-node">Clean Text</div>
            <div style="font-size:10px; opacity:0.3;">&</div>
            <div class="flow-node">Actions</div>
        </div>
        <div class="flow-arrow">▼</div>
        <div class="flow-node flow-active">${modeLabel}</div>
    `;
}

window.aiSaveAssistant = async function() {
    const verbs = {};
    aiVerbLibrary.forEach(v => {
        verbs[v.id] = document.getElementById("verb-" + v.id).checked;
    });

    const a = {
        id: currentStudioId,
        name: document.getElementById("as-name").value.trim(),
        nickname: document.getElementById("as-nickname").value.trim(),
        trigger_phrases: document.getElementById("as-trigger-phrases").value.trim(),
        role_desc: document.getElementById("as-role-desc").value.trim(),
        prompt: document.getElementById("as-prompt").value.trim(),
        model_override: document.getElementById("as-model").value.trim(),
        temperature: parseFloat(document.getElementById("as-temp-slider").value),
        max_turns: parseInt(document.getElementById("as-turns-slider").value),
        commit_mode: document.getElementById("as-commit-mode").value,
        is_active: document.getElementById("as-active-toggle").checked ? 1 : 0,
        context_config: {
            food_master: document.getElementById("ctx-food-master").checked,
            food_discovery: document.getElementById("ctx-food-discovery").checked,
            food_names: document.getElementById("ctx-food-names").checked,
            food_metrics: document.getElementById("ctx-food-metrics").checked,
            food_nutrition: document.getElementById("ctx-food-nutrition").checked,
            folders: document.getElementById("ctx-folders").checked,
            todo: document.getElementById("ctx-todo").checked
        },
        verbs_config: verbs,
        workflow_json: ""
    };

    if (!a.name || !a.role_desc) { window.openConfirm("Input Required", "Name and Role are required.", null, false, "OK", null); return; }

    await window.sui.api("ai_save_assistant", { assistant: a }, { toast: "Robot Saved" });
    aiPerformCloseStudio(); // Force close without triggering guard
    aiLoadAssistants();
};

window.aiDeleteAssistant = async function() {
    window.openConfirm("Delete Robot", "Delete this robot?", async () => {
        await window.sui.api("ai_delete_assistant", { id: currentStudioId }, { toast: "Robot Deleted" });
        aiPerformCloseStudio();
        aiLoadAssistants();
    }, true);
};

window._aiActiveSuggestions = {};
window._aiLogToSuggMap = {}; // Maps log_id -> suggestion_id

window.aiRenderChatThread = function(suggestion) {
    const thread = [];
    
    // 1. Extract AI Messages from Turn History
    if (suggestion.turn_history) {
        try {
            const history = JSON.parse(suggestion.turn_history);
            history.forEach((turn, idx) => {
                const discussActions = turn.actions.filter(a => a.type === 'DISCUSS');
                discussActions.forEach(act => {
                    thread.push({
                        role: 'assistant',
                        text: act.text,
                        time: turn.time || '...', 
                        sortKey: turn.time || '00:00'
                    });
                });
            });
        } catch(e) { console.error("History parse error", e); }
    }

    // 2. Extract User Messages from Correction History
    if (suggestion.correction_history && suggestion.correction_history !== "null") {
        const lines = suggestion.correction_history.split('\n');
        lines.forEach(line => {
            const match = line.match(/^\[(.*?)\]\s*(.*)/);
            if (match) {
                thread.push({
                    role: 'user',
                    time: match[1],
                    text: match[2],
                    sortKey: match[1]
                });
            }
        });
    }

    // 3. Chronological Sort by Time (HH:MM:SS)
    thread.sort((a, b) => a.sortKey.localeCompare(b.sortKey));

    if (thread.length === 0) return "";

    return `
        <div class="ai-chat-thread">
            ${thread.map(msg => {
                const isUser = msg.role === 'user';
                const meta = isUser ? `YOU • ${msg.time}` : `ROBOT • ${msg.time}`;
                const content = (typeof marked !== 'undefined') ? marked.parse(msg.text) : msg.text;
                const bubbleId = `bubble-${suggestion.id}-${msg.sortKey.replace(/:/g, '-')}`;
                return `
                    <div id="${bubbleId}" class="ai-bubble ai-bubble-${isUser ? 'user' : 'asst'}">
                        <span class="ai-bubble-meta">${meta}</span>
                        <div class="ai-discuss-content">${content}</div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
};

window.aiFormatDiscoveryContext = function(raw) {
    if (!raw || typeof raw !== 'string') return "";
    
    // 1. Detect Web Search JSON
    if (raw.includes('WEB SEARCH RESULTS')) {
        const queryMatch = raw.match(/QUERY:\s*(.*)\n/);
        const jsonMatch = raw.match(/\[\s*\{[\s\S]*\}\s*\]/);
        const queryHtml = queryMatch ? `<div style="margin-bottom:10px; padding:8px 12px; background:var(--ai-accent-bg); border-radius:8px; border:1px solid rgba(88, 86, 214, 0.2); font-size:11px; color:var(--ai-accent); font-weight:700;">Keywords: "${queryMatch[1]}"</div>` : '';
        
        if (jsonMatch) {
            try {
                const data = JSON.parse(jsonMatch[0]);
                return `
                    ${queryHtml}
                    <div style="display:flex; flex-direction:column; gap:8px;">
                    ${data.map((r, idx) => {
                        const contentId = `ws-ext-content-${idx}-${Math.floor(Math.random()*1000)}`;
                        return `
                        <div style="background:var(--bg-color); border:1px solid var(--border-color); padding:10px; border-radius:10px; font-size:12px;">
                            <div style="font-weight:800; color:var(--primary); margin-bottom:4px; display:flex; justify-content:space-between; align-items:center;">
                                <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1;">${r.title}</span>
                                <a href="${r.url}" target="_blank" style="margin-left:8px; opacity:0.5; color:inherit;"><span data-sui-icon="layout" data-sui-size="12"></span></a>
                            </div>
                            <!-- Reliable Snippet -->
                            <div style="font-size:11px; color:var(--text-primary); line-height:1.4; background:var(--card-bg); padding:8px; border-radius:6px; border:1px solid rgba(0,0,0,0.03);">
                                <span style="font-size:9px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; display:block; margin-bottom:2px;">Source Snippet</span>
                                ${r.snippet || 'No snippet available.'}
                            </div>
                            <!-- Collapsible Extraction -->
                            ${r.content ? `
                                <div style="margin-top:8px;">
                                    <div onclick="suiToggle('${contentId}')" style="font-size:9px; font-weight:800; color:var(--primary); text-transform:uppercase; cursor:pointer; display:flex; align-items:center; gap:4px;">
                                        <span data-sui-icon="chevron" data-sui-arrow="${contentId}" data-sui-size="10" style="transform:rotate(-90deg); transition:transform 0.3s;"></span>
                                        View Full Extraction (${r.content.length} chars)
                                    </div>
                                    <div id="${contentId}" class="sui-accordion" style="display:none;">
                                        <div class="sui-accordion-inner" style="padding-top:8px;">
                                            <div style="font-size:10px; color:var(--text-secondary); line-height:1.5; background:rgba(0,0,0,0.02); padding:10px; border-radius:6px; border:1px dashed var(--border-color); max-height:150px; overflow-y:auto; white-space:pre-wrap;">${r.content}</div>
                                        </div>
                                    </div>
                                </div>
                            ` : ''}
                        </div>`;
                    }).join('')}
                </div>`;
            } catch(e) {}
        }
    }

    // 2. Detect Food Discovery JSON
    if (raw.includes('--- SEARCH RESULTS')) {
        const jsonMatch = raw.match(/\[\s*\{[\s\S]*\}\s*\]/);
        if (jsonMatch) {
            try {
                const data = JSON.parse(jsonMatch[0]);
                return `<div style="display:flex; flex-wrap:wrap; gap:6px;">
                    ${data.map(f => `<span class="meta-badge sui-badge-default" style="font-size:10px; text-transform:none; font-family:monospace; background:var(--bg-color);">${f.name} (ID:${f.id})</span>`).join('')}
                </div>`;
            } catch(e) {}
        }
    }

    // 3. Fallback to cleaned text
    return `<div style="font-family:monospace; font-size:10px; background:var(--bg-color); padding:10px; border-radius:8px; white-space:pre-wrap; color:var(--text-secondary); border:1px solid var(--border-color);">${raw.replace(/---.*?---/g, '').trim()}</div>`;
};

window.aiJumpToDiscuss = function(suggId) {
    const details = document.getElementById(`ai-sugg-details-${suggId}`);
    if (!details) return;
    
    // 1. Open Accordion
    details.open = true;
    
    // 2. Find the latest Robot bubble in this suggestion
    // We target assistant bubbles belonging to this suggestion ID
    const bubbles = details.querySelectorAll(`.ai-bubble-asst[id^="bubble-${suggId}-"]`);
    const target = bubbles[bubbles.length - 1];
    
    if (target) {
        // 3. Scroll into view within the Hub scroll container
        const container = details.closest('.scroll-view');
        const containerRect = container.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const relativeTop = targetRect.top - containerRect.top + container.scrollTop;
        
        container.scrollTo({ 
            top: relativeTop - (containerRect.height / 2) + (targetRect.height / 2), 
            behavior: 'smooth' 
        });

        // 4. Trigger Animations
        setTimeout(() => {
            target.classList.remove('ai-bubble-highlight');
            void target.offsetWidth; // Trigger reflow
            target.classList.add('ai-bubble-highlight');
        }, 300);
    }
    
    if (window.sui && window.sui.haptic) window.sui.haptic('light');
};

function aiRenderActionWidget(a, logId, suggestionId = null) {
    const icon = (name) => `<span data-sui-icon="${name}" data-sui-size="12" style="display:inline-flex; vertical-align:middle;"></span>`;
    
    if (a.type === "ADD_FOOD_LOG" || a.type === "ADD_FOOD_LOG_DB" || a.type === "ADD_FOOD_LOG_MANUAL") {
        const d = { ...a.data }; // Clone to avoid mutating original suggestion data
        const isDb = a.type === "ADD_FOOD_LOG_DB" || !!d.food_id;

        // --- DB LOOKUP OVERRIDE ---
        if (isDb && typeof calData !== 'undefined' && calData.foods) {
            const food = calData.foods.find(f => f.id == d.food_id);
            if (food) {
                const m = d.multiplier || 1;
                const ratio = (food.calories > 0) ? (food.ref_calories / food.calories) : 0;
                d.calories = Math.round(food.ref_calories * m);
                d.protein = (food.protein * ratio * m).toFixed(1);
                d.name = food.name; // Use official DB name
            }
        }
        const dateLabel = d.date ? `<div style="font-size:9px; color:var(--primary); font-weight:700; margin-bottom:2px;">📅 ${d.date}</div>` : '';
        const sourceBadge = isDb ? 
            `<span class="meta-badge sui-badge-ai" style="font-size:8px; margin-left:auto; background:var(--success-bg); color:var(--success-text); border:none;">Database</span>` :
            `<span class="meta-badge sui-badge-default" style="font-size:8px; margin-left:auto; opacity:0.6;">Estimated</span>`;

        return `
            <div class="ai-action-widget" style="border-left: 4px solid var(--primary);">
                ${dateLabel}
                <div class="ai-widget-header" style="display:flex; align-items:center; width:100%;">
                    <div style="display:flex; align-items:center; gap:6px;">${icon('activity')} Log to Diary • ${d.meal || 'Meal'}</div>
                    ${sourceBadge}
                </div>
                <div class="ai-widget-title">${d.name || 'Unknown Food'}</div>
                <div class="ai-widget-sub">${d.calories || 0} kcal • ${d.protein || 0}g protein ${d.multiplier ? `• x${d.multiplier}` : ''}</div>
            </div>
        `;
    }

    if (a.type === "CREATE_FOOD" || a.type === "UPDATE_FOOD") {
        const d = a.data || {};
        const isUpdate = a.type === "UPDATE_FOOD";
        const tw = parseFloat(d.total_weight_g) || 0;
        const rw = parseFloat(d.ref_amount_g) || 0;
        
        // Logic: How many servings are in this package?
        const portions = (tw > 0 && rw > 0) ? (tw / rw) : 1;
        const isMulti = portions > 1.05; // Buffer for floating point
        const pName = d.portion_name || 'serving';
        
        // Ratios
        const pkgTo100 = tw > 0 ? (100 / tw) : 0;
        const pkgToSrv = isMulti ? (1 / portions) : 1;

        const fmt = (v, ratio, isRound = false) => {
            const n = (parseFloat(v) || 0) * ratio;
            if (isRound) return Math.round(n);
            return Number.isInteger(n) ? n : n.toFixed(1);
        };

        return `
            <div class="ai-action-widget" style="border-left: 4px solid var(--ai-accent);">
                <div class="ai-widget-header">${icon(isUpdate ? 'edit' : 'plus')} ${isUpdate ? 'Update Food' : 'New Food'} ${isUpdate ? `<small style="margin-left:auto; opacity:0.6;">ID: ${a.food_id}</small>` : ''}</div>
                <div class="ai-widget-title" style="margin-bottom:8px;">${d.name || (isUpdate ? 'Updating Item' : 'New Item')}</div>
                
                <div class="ai-nutrition-label">
                    <div style="font-size:18px; font-weight:900; border-bottom:10px solid #000; padding-bottom:2px;">Nutrition Facts</div>
                    <div style="font-size:12px; font-weight:700; border-bottom:1px solid #000; padding:4px 0;">
                        ${isMulti ? `Approx. ${fmt(portions, 1)} ${pName}s per container` : `1 package container`}
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:900; border-bottom:6px solid #000; padding:4px 0 2px 0;">
                        <span>Serving size</span>
                        <span>${rw > 0 ? rw + 'g' : '1 ' + pName}</span>
                    </div>

                    <style>
                        .ai-nutri-grid { display: grid; grid-template-columns: 1fr 65px 55px; align-items: center; border-bottom: 1px solid #000; padding: 3px 0; font-size: 12px; }
                        .ai-nutri-grid.thick { border-bottom-width: 4px; }
                        .ai-nutri-grid.sub { padding-left: 12px; opacity: 0.8; font-size: 11px; }
                        .ai-nutri-label-col { font-weight: 700; }
                        .ai-nutri-srv-col { text-align: right; font-weight: 800; }
                        .ai-nutri-100-col { text-align: right; font-weight: 500; opacity: 0.5; font-size: 10px; }
                    </style>

                    <div class="ai-nutri-grid" style="border-bottom: 1px solid #000; padding: 4px 0; align-items: flex-end;">
                        <span style="font-size:10px; font-weight:800; text-transform:uppercase;">Amount per:</span>
                        <span class="ai-nutri-srv-col" style="font-size:10px; font-weight:800; text-transform:uppercase;">${rw > 0 ? rw + 'g' : 'Srv'}</span>
                        <span class="ai-nutri-100-col" style="font-size:10px; font-weight:800; text-transform:uppercase; opacity:0.6;">100g</span>
                    </div>

                    <div class="ai-nutri-grid thick" style="font-size:15px;">
                        <span style="font-weight:900;">Calories</span> 
                        <span class="ai-nutri-srv-col">${fmt(d.calories, pkgToSrv, true)}</span>
                        <span class="ai-nutri-100-col">${fmt(d.calories, pkgTo100, true)}</span>
                    </div>

                    <div class="ai-nutri-grid">
                        <span class="ai-nutri-label-col">Protein</span>
                        <span class="ai-nutri-srv-col">${fmt(d.protein, pkgToSrv)}g</span>
                        <span class="ai-nutri-100-col">${fmt(d.protein, pkgTo100)}g</span>
                    </div>

                    <div class="ai-nutri-grid">
                        <span class="ai-nutri-label-col">Total Fat</span>
                        <span class="ai-nutri-srv-col">${fmt(d.fat, pkgToSrv)}g</span>
                        <span class="ai-nutri-100-col">${fmt(d.fat, pkgTo100)}g</span>
                    </div>

                    <div class="ai-nutri-grid sub">
                        <span>Saturated Fat</span>
                        <span class="ai-nutri-srv-col">${fmt(d.sat_fat, pkgToSrv)}g</span>
                        <span class="ai-nutri-100-col">${fmt(d.sat_fat, pkgTo100)}g</span>
                    </div>

                    <div class="ai-nutri-grid sub">
                        <span>Trans Fat</span>
                        <span class="ai-nutri-srv-col">${fmt(d.trans_fat, pkgToSrv)}g</span>
                        <span class="ai-nutri-100-col">${fmt(d.trans_fat, pkgTo100)}g</span>
                    </div>

                    <div class="ai-nutri-grid">
                        <span class="ai-nutri-label-col">Total Carbs</span>
                        <span class="ai-nutri-srv-col">${fmt(d.carbs, pkgToSrv)}g</span>
                        <span class="ai-nutri-100-col">${fmt(d.carbs, pkgTo100)}g</span>
                    </div>

                    <div class="ai-nutri-grid sub">
                        <span>Sugars</span>
                        <span class="ai-nutri-srv-col">${fmt(d.sugar, pkgToSrv)}g</span>
                        <span class="ai-nutri-100-col">${fmt(d.sugar, pkgTo100)}g</span>
                    </div>

                    <div class="ai-nutri-grid thick">
                        <span class="ai-nutri-label-col">Sodium</span>
                        <span class="ai-nutri-srv-col">${fmt(d.sodium, pkgToSrv, true)}mg</span>
                        <span class="ai-nutri-100-col">${fmt(d.sodium, pkgTo100, true)}mg</span>
                    </div>
                    
                    <div style="font-size:9px; color:#444; margin-top:8px; padding-top:6px; border-top:1px solid #ddd; line-height:1.3;">
                        Total Package: <b>${tw}g</b> (${d.calories} cal total)<br>
                        Reference: <b>${rw}g</b> (${d.ref_calories} cal)
                    </div>
                </div>
            </div>
        `;
    }

    if (a.type === "CREATE_TODO") {
        return `
            <div class="ai-action-widget" style="border-left: 4px solid #FF9500;">
                <div class="ai-widget-header">${icon('check')} Add Task to List</div>
                <div class="ai-widget-title" style="font-size:13px;">${a.text}</div>
                <div style="margin-top:4px;"><span class="meta-badge sui-badge-todo" style="font-size:9px;">${a.list || 'Inbox'}</span></div>
            </div>
        `;
    }

    if (a.type === "MOVE_FOLDER") {
        return `
            <div class="ai-action-widget" style="border-left: 4px solid var(--text-secondary);">
                <div class="ai-widget-header">${icon('folder')} Move to Folder</div>
                <div class="ai-widget-title">${a.target}</div>
            </div>
        `;
    }

    if (a.type === "REPLACE_TEXT") {
        return `
            <div class="ai-action-widget" style="border-left: 4px solid #34C759;">
                <div class="ai-widget-header">${icon('edit')} Polish Transcription</div>
                <div class="ai-widget-sub" style="font-style:italic; line-height:1.4; color:var(--text-primary);">"${a.text}"</div>
            </div>
        `;
    }

    if (a.type === "DISCUSS") {
        const mdText = (typeof marked !== 'undefined') ? marked.parse(a.text) : a.text;
        const clickAttr = suggestionId ? `onclick="aiJumpToDiscuss(${suggestionId})"` : '';
        const styleAttr = suggestionId ? 'cursor:pointer;' : '';
        return `
            <div class="ai-action-widget" ${clickAttr} style="${styleAttr} border-left: 4px solid var(--ai-accent); background: var(--ai-accent-bg);">
                <div class="ai-widget-header" style="color:var(--ai-accent);">${icon('message')} Robot Message</div>
                <div class="ai-discuss-content" style="font-size:14px; line-height:1.5; color:var(--text-primary); padding:4px 0;">${mdText}</div>
            </div>
        `;
    }

    if (a.type === "DISCOVER") {
        const isTransient = !!window._aiTransientActions[logId];
        return `
            <div class="ai-action-widget" style="border-left: 4px solid ${isTransient ? 'var(--primary)' : 'var(--danger)'}; opacity: 0.8;">
                <div class="ai-widget-header" style="color:${isTransient ? 'var(--primary)' : 'var(--danger)'};">
                    ${icon('search')} ${isTransient ? 'Database Discovery' : 'Incomplete Discovery'}
                </div>
                <div class="ai-widget-title" style="font-size:12px;">${isTransient ? `Searching for "${a.query}"...` : `Robot search for "${a.query}" was cut short.`}</div>
                ${isTransient ? `<div class="skel-line" style="margin-top:8px; height:4px; border-radius:2px;"></div>` : `<div class="ai-widget-sub">The pipeline ended before this item could be found. Try re-processing this note.</div>`}
            </div>
        `;
    }

    if (a.type === "WEB_SEARCH") {
        const isTransient = !!window._aiTransientActions[logId];
        return `
            <div class="ai-action-widget" style="border-left: 4px solid var(--ai-accent);">
                <div class="ai-widget-header" style="color:var(--ai-accent);">${icon('search')} ${isTransient ? 'Web Research' : 'Research Required'}</div>
                <div class="ai-widget-title" style="font-size:13px;">${isTransient ? `Searching: "${a.query}"...` : `Search: "${a.query}"`}</div>
                ${isTransient ? `<div class="skel-line" style="margin-top:8px; height:4px; border-radius:2px; background:var(--ai-accent); opacity:0.3;"></div>` : `
                <div class="ai-widget-sub" style="margin-bottom:8px;">The robot needs to perform a web search to complete this request.</div>
                <button onclick="event.stopPropagation(); aiRunPipeline('${logId}', null, true)" class="text-btn" style="background:var(--ai-accent); color:white; border-radius:8px; padding:8px; font-size:11px; font-weight:800; width:100%;">Authorize Web Search</button>
                `}
            </div>
        `;
    }

    // Default Fallback
    return `<div class="ai-action-widget"><div class="ai-widget-header">${a.type}</div><div class="ai-widget-title">${a.target || a.text || ""}</div></div>`;
}

async function aiLoadSuggestions() {
    const cont = document.getElementById("ai-suggestion-queue");
    if(!cont) return;

    try {
        const data = await window.sui.api("ai_get_suggestions", {}, { toast: false });
        const suggestions = data.suggestions || [];
        const activeRuns = window._aiActivePipelineRuns || new Set();
        
        if (suggestions.length > 0 || activeRuns.size > 0) {
            cont.innerHTML = "";
            window._aiActiveSuggestions = {}; 
            window._aiLogToSuggMap = {}; 
            window._aiCorrectionCache = {}; 
            window._aiDiscoveryCache = {}; 
            window._aiHistoryCache = {};

            const logIdsWithSuggestions = suggestions.map(s => s.log_id);
            let skeletonCount = 0;

            // 1. Render Skeletons for Active Runs that don't have a suggestion yet
            activeRuns.forEach(logId => {
                if (logIdsWithSuggestions.includes(logId)) return;
                skeletonCount++;
                const log = logs.find(l => l.id === logId);
                const skeleton = document.createElement("div");
                skeleton.className = "ai-sugg-skeleton";
                const transientActions = window._aiTransientActions[logId] || [];
                const actionsHtml = transientActions.map(act => aiRenderActionWidget(act, logId)).join('');

                skeleton.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-size:9px; font-weight:900; color:var(--ai-accent); text-transform:uppercase; letter-spacing:1px;">Robot is thinking...</span>
                            <div class="spinner" style="width:12px; height:12px; border-width:2px; margin:0;"></div>
                        </div>
                        <button onclick="aiStopPipeline('${logId}')" style="background:rgba(255,59,48,0.1); border:none; color:var(--danger); font-size:9px; font-weight:900; padding:4px 8px; border-radius:6px; cursor:pointer; text-transform:uppercase;">Stop</button>
                    </div>
                    <div style="font-size:14px; color:var(--text-primary); font-style:italic; line-height:1.4; opacity:0.5; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        "${log ? log.transcription : 'Processing new note...'}"
                    </div>
                    ${actionsHtml ? `<div style="margin-top:12px; display:flex; flex-direction:column; gap:8px;">${actionsHtml}</div>` : `
                    <div style="margin-top:15px; display:flex; gap:8px; opacity:0.3;">
                        <div style="flex:1; height:36px; background:var(--btn-bg); border-radius:12px;"></div>
                        <div style="flex:2; height:36px; background:var(--btn-bg); border-radius:12px;"></div>
                    </div>`}
                `;
                cont.appendChild(skeleton);
            });

            aiRefreshIndicatorDot(suggestions.length + skeletonCount);

            // 2. Render Actual Suggestions
            suggestions.forEach(s => {
                const actions = JSON.parse(s.actions_json || "[]");
                const assistant = aiAssistants.find(a => a.id === s.assistant_id);
                window._aiActiveSuggestions[s.id] = actions;
                window._aiActiveSuggestions[s.id].asstId = s.assistant_id;
                window._aiLogToSuggMap[s.log_id] = s.id;
                window._aiDiscoveryCache[s.id] = s.discovery_context;

                // SYNC MAIN LIST: Ensure the card in the stream shows the AI badge immediately
                const cb = document.querySelector(`.custom-checkbox[data-id="${s.log_id}"]`);
                const log = logs.find(l => l.id === s.log_id);
                if (cb && log && log.ai_processed != 2) {
                    log.ai_processed = 2;
                    log.ai_assistant_id = s.assistant_id;
                    const card = cb.closest(".card");
                    if (card && window.sui && window.sui.decorateCard) window.sui.decorateCard(card, log);
                }
                window._aiHistoryCache[s.id] = s.turn_history;
                // Guard: Ensure null or 'null' string doesn't enter the cache
                window._aiCorrectionCache[s.id] = (s.correction_history && s.correction_history !== "null") ? s.correction_history : "";
                
                const card = document.createElement("div");
                card.id = `ai-sugg-card-${s.id}`;
                card.style.cssText = "background:var(--card-bg); border-radius:18px; padding:16px; border:1px solid var(--border-color); box-shadow:var(--shadow-card); margin-bottom:12px;";
                
                const actionsHtml = actions.map(act => aiRenderActionWidget(act, s.log_id, s.id)).join('');
                const researchHtml = aiFormatDiscoveryContext(s.discovery_context);

                // --- TURN HISTORY RENDERING ---
                let turnHistoryHtml = "";
                if (s.turn_history) {
                    try {
                        const history = JSON.parse(s.turn_history);
                        turnHistoryHtml = history.map((th, idx) => {
                            let resultsRaw = th.results;
                            if (!resultsRaw && idx === history.length - 1 && s.discovery_context) {
                                resultsRaw = s.discovery_context;
                            }

                            return `
                                <div style="margin-bottom:10px; border-left:2px solid var(--border-color); padding-left:12px;">
                                    <div style="font-size:9px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:6px;">Turn ${th.turn} Intent</div>
                                    <div style="display:flex; flex-direction:column; gap:6px;">
                                        ${th.actions.map((a, actIdx) => {
                                            const isSearch = ['DISCOVER', 'WEB_SEARCH'].includes(a.type.toUpperCase());
                                            const turnId = `ai-turn-${s.id}-${th.turn}-${actIdx}`;
                                            
                                            // DEMULTIPLEXER: Find the specific result block for this query
                                            let specificResultHtml = "";
                                            if (isSearch && resultsRaw && a.query) {
                                                const escapedQuery = a.query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                                                const pattern = new RegExp(`--- .*? RESULTS ---\\s*QUERY: ${escapedQuery}[\\s\\S]*?(?=--- .*? RESULTS ---|$)`, 'i');
                                                const match = resultsRaw.match(pattern);
                                                if (match) specificResultHtml = aiFormatDiscoveryContext(match[0]);
                                            }

                                            return `
                                            <div style="font-size:11px; color:var(--text-primary); display:flex; flex-direction:column; gap:4px;">
                                                <div style="display:flex; align-items:center; gap:6px;">
                                                    <span style="font-family:monospace; font-weight:700; color:var(--primary); font-size:10px;">${a.type}</span>
                                                    <span style="opacity:0.7;">${a.query || a.target || a.text || "Analysis"}</span>
                                                </div>
                                                ${specificResultHtml ? `
                                                    <div style="margin-top:2px; margin-bottom:6px; padding-left:10px; border-left:1px dashed var(--primary);">
                                                        <div onclick="suiToggle('${turnId}')" style="font-size:9px; font-weight:800; color:var(--primary); text-transform:uppercase; cursor:pointer; display:flex; align-items:center; gap:4px; margin-bottom:4px;">
                                                            <span data-sui-icon="chevron" data-sui-arrow="${turnId}" data-sui-size="10" style="transform:rotate(-90deg); transition:transform 0.3s;"></span>
                                                            View Results
                                                        </div>
                                                        <div id="${turnId}" class="sui-accordion" style="display:none;">
                                                            <div class="sui-accordion-inner" style="padding-top:4px;">
                                                                ${specificResultHtml}
                                                            </div>
                                                        </div>
                                                    </div>
                                                ` : ''}
                                            </div>`;
                                        }).join('')}
                                    </div>
                                </div>
                            `;
                        }).join('');
                    } catch(e) {}
                }

                card.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                        <span style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">${assistant ? assistant.name : "AI"} Suggestion</span>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <button onclick="aiRerunPipeline('${s.log_id}', ${s.id})" title="Rerun AI" style="background:var(--btn-bg); border:none; width:22px; height:22px; border-radius:6px; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:12px; height:12px;"><path d="M23 4v6h-6"></path><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                            </button>
                            <button onclick="aiJumpToNote('${s.log_id}')" title="Jump to Note" style="background:var(--btn-bg); border:none; width:22px; height:22px; border-radius:6px; color:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:12px; height:12px;"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>
                    <div style="font-size:10px; color:var(--text-secondary); opacity:0.5; margin-bottom:12px;">${s.date_display}</div>
                    <div style="font-size:14px; color:var(--text-primary); font-style:italic; line-height:1.4; margin-bottom:12px; opacity:0.8;">
                        "${s.transcription}"
                    </div>
                    <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:20px;">${actionsHtml}</div>
                    <div style="display:flex; gap:8px;">
                        <button onclick="aiDismissSuggestion(${s.id})" style="flex:1; background:var(--btn-bg); color:var(--text-primary); border:none; padding:12px; border-radius:12px; font-weight:600; font-size:12px;">Dismiss</button>
                        <button id="btn-commit-${s.id}" onclick="aiCommitSuggestion(${s.id}, '${s.log_id}')" style="flex:2; background:var(--primary); color:var(--primary-text); border:none; padding:12px; border-radius:12px; font-weight:700; font-size:13px; transition:all 0.2s; box-shadow:0 4px 12px rgba(0,122,255,0.2);">Commit Actions</button>
                    </div>
                    
                    <details id="ai-sugg-details-${s.id}" style="margin-top:15px; border-top:1px solid var(--border-color); padding-top:10px;">
                        <summary style="list-style:none; font-size:10px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; cursor:pointer; display:flex; align-items:center; gap:5px; letter-spacing:0.5px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:12px; height:12px;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            Research & Guidance
                        </summary>
                        
                        <div style="padding:12px 0 0 0; display:flex; flex-direction:column; gap:16px;">
                            <!-- Section 0: Pipeline History -->
                            ${turnHistoryHtml ? `
                            <div style="border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; background: rgba(0,0,0,0.01);">
                                <div onclick="suiToggle('ai-chain-${s.id}')" style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; cursor:pointer; background:rgba(0,0,0,0.02);">
                                    <div style="font-size:9px; font-weight:900; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:6px;">
                                        <span data-sui-icon="activity" data-sui-size="12"></span> Autonomous Chain
                                    </div>
                                    <span data-sui-icon="chevron" data-sui-arrow="ai-chain-${s.id}" data-sui-size="12" style="transition:transform 0.3s; transform:rotate(-90deg);"></span>
                                </div>
                                <div id="ai-chain-${s.id}" class="sui-accordion" style="display:none;">
                                    <div class="sui-accordion-inner" style="padding:12px;">
                                        ${turnHistoryHtml}
                                    </div>
                                </div>
                            </div>
                            ` : ''}



                            <!-- Section 2: Conversation -->
                            <div>
                                <div style="font-size:9px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; margin-bottom:12px; display:flex; align-items:center; gap:5px;">
                                    <span data-sui-icon="message" data-sui-size="10"></span> Correspondence
                                </div>
                                
                                ${aiRenderChatThread(s)}

                                <textarea id="ai-correction-input-${s.id}" placeholder="Reply to the robot..." style="width:100%; height:60px; font-size:12px; padding:10px; border-radius:10px; background:var(--bg-color); border:1px solid var(--border-color); color:var(--text-primary); resize:none; outline:none; margin-bottom:8px;"></textarea>
                                <button onclick="aiSubmitCorrection('${s.log_id}', ${s.id})" style="width:100%; background:var(--primary); color:var(--primary-text); border:none; padding:10px; border-radius:10px; font-size:12px; font-weight:700; cursor:pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">Send Reply</button>
                            </div>
                        </div>
                    </details>
                `;
                cont.appendChild(card);

                // Hook into Scroll Reveal for entrance animations
                if (typeof window.srWatch === 'function') window.srWatch(card);
            });
            // Hydrate Icons for the entire queue
            window.suiHydrateIcons(cont);
        } else {
            cont.innerHTML = `<div style="text-align:center; padding:40px; background:rgba(0,0,0,0.02); border-radius:20px; border:1px dashed var(--border-color); color:var(--text-secondary); font-size:13px;">No pending AI suggestions.</div>`;
            window._aiLogToSuggMap = {};
            aiRefreshIndicatorDot(0);
        }
    } catch(e) {}
}

window.aiDismissSuggestion = async function(id, keepBadge = false) {
    const data = await window.sui.api("ai_dismiss_suggestion", { id: id, keep_badge: keepBadge }, { toast: false });
    
    if (data && data.log_id) {
        const log = logs.find(l => l.id === data.log_id);
        if (log) {
            if (!keepBadge) {
                // Reset state so Badge Engine hides the pill
                log.ai_processed = 0;
                log.ai_assistant_id = null;
            }
            
            // Re-map the suggestion ID tracking
            if (window._aiLogToSuggMap) delete window._aiLogToSuggMap[data.log_id];
        }

        // Force UI refresh for that specific card
        const cb = document.querySelector(`.custom-checkbox[data-id="${data.log_id}"]`);
        if (cb) {
            const card = cb.closest(".card");
            if (card) aiDecorateCard(card, log);
        }
    }
    
    aiLoadSuggestions();
};

window.aiCommitSuggestion = async function(id, logId) {
    const btn = document.getElementById(`btn-commit-${id}`);
    if(btn) { btn.innerText = "Executing..."; btn.style.opacity = "0.6"; btn.disabled = true; }

    try {
        await window.sui.api("ai_commit_suggestion", { suggestion_id: id, log_id: logId }, { toast: "Actions Executed" });
        if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
        await aiDismissSuggestion(id, true);
    } catch (e) {
        console.error(e);
        if (window.openConfirm) window.openConfirm("Error", "Commit failed.", null, false, "OK", null);
        if(btn) { btn.innerText = "Commit Actions"; btn.style.opacity = "1"; btn.disabled = false; }
    }
};

async function aiLoadHubConfig() {
    if (typeof window.fdbFetchAll === 'function') {
        window.fdbFetchAll();
    }
    aiLoadAssistants();
    aiLoadSuggestions();
    
    // Register Hub Sections for Scroll Reveal
    if (typeof window.srWatch === 'function') {
        const dispatcher = document.getElementById('ai-dispatcher-section');
        const diagnostics = document.getElementById('ai-diagnostics-details');
        const monPill = document.getElementById('ai-monitoring-pill');
        const suggQueue = document.getElementById('ai-suggestion-queue');
        if (dispatcher) window.srWatch(dispatcher);
        if (diagnostics) window.srWatch(diagnostics);
        if (monPill) window.srWatch(monPill);
        if (suggQueue) window.srWatch(suggQueue);
    }

    try {
        const data = await window.sui.api("ai_get_config", {}, { toast: false });
        if (data) {
            aiConfig = data.config;
            localStorage.setItem('cjos_ai_monitoring', aiConfig.monitoring_enabled);
            
            // Sync Pill UI
            aiUpdateMonitoringPillUI(aiConfig.monitoring_enabled);
            
            const status = document.getElementById("ai-dispatcher-status");if (status) {
                status.innerText = aiConfig.mechanical_mode ? "Mechanical Mode" : (aiConfig.dispatcher_model || "Inheriting OpenRouter Default");
            }
        }
    } catch(e) {}
}

window.aiUpdateMonitoringPillUI = function(enabled) {
    const pill = document.getElementById('ai-monitoring-pill');
    const label = document.getElementById('ai-mon-label');
    const dot = document.getElementById('ai-mon-indicator');
    if (!pill) return;

    if (enabled) {
        pill.style.background = 'var(--primary)';
        pill.style.borderColor = 'var(--primary)';
        label.style.color = 'var(--primary-text)';
        dot.style.background = '#FFFFFF';
        dot.style.boxShadow = '0 0 8px rgba(255,255,255,0.8)';
    } else {
        pill.style.background = 'var(--card-bg)';
        pill.style.borderColor = 'var(--border-color)';
        label.style.color = 'var(--text-secondary)';
        dot.style.background = 'var(--danger)';
        dot.style.boxShadow = 'none';
    }
};

window.aiToggleMonitoring = function() {
    const newState = !aiConfig.monitoring_enabled;
    if (window.sui && window.sui.haptic) window.sui.haptic('medium');
    aiUpdateMonitoringPillUI(newState);
    aiSaveHubConfig(null, newState);
};

window.aiSaveHubConfig = async function(customMsg = null, forceState = null) {
    const enabled = (forceState !== null) ? forceState : aiConfig.monitoring_enabled;
    aiConfig.monitoring_enabled = enabled;
    localStorage.setItem('cjos_ai_monitoring', enabled);
    
    await window.sui.api("ai_save_config", { settings: aiConfig }, { toast: customMsg || (aiConfig.monitoring_enabled ? "AI Monitoring Active" : "AI Monitoring Paused") });
    
    if (window.sui && window.sui.toast) {
    window.sui.toast(customMsg || (aiConfig.monitoring_enabled ? "AI Monitoring Active" : "AI Monitoring Paused"), {
        plugin: "AiAssistant",
        caller: "aiSaveConfig",
        metrics: { monitoring: aiConfig.monitoring_enabled, auto_commit: aiConfig.auto_commit }
    });
}// Update UI display
    const status = document.getElementById("ai-dispatcher-status");
    if (status) {
        status.innerText = aiConfig.mechanical_mode ? "Mechanical Mode" : (aiConfig.dispatcher_model || "Inheriting OpenRouter Default");
    }
};

window.aiJumpToNote = function(logId) {
    // 1. Switch to Home Page (Main List)
    const viewport = document.querySelector('.horizontal-viewport');
    if (viewport) viewport.scrollTo({ left: 0, behavior: 'smooth' });

    // 2. Wait for swipe animation, then find and scroll to card
    setTimeout(() => {
        const cb = document.querySelector(`.custom-checkbox[data-id="${logId}"]`);
        const card = cb ? cb.closest('.card') : null;
        const container = document.getElementById('main-scroll');

        if (card && container) {
            const containerRect = container.getBoundingClientRect();
            const cardRect = card.getBoundingClientRect();
            const relativeTop = cardRect.top - containerRect.top + container.scrollTop;
            const targetScroll = relativeTop - (containerRect.height / 2) + (cardRect.height / 2);

            container.scrollTo({ top: targetScroll, behavior: 'smooth' });

            // Visual Feedback: Pulse the card
            card.classList.add('jump-highlight');
            setTimeout(() => card.classList.remove('jump-highlight'), 3000);
        }
    }, 400);
};

window.aiRerunPipeline = async function(logId, suggestionId) {
    aiConsole.log(`Manual Rerun triggered for Log: ${logId.substring(0,8)}`);

    // 1. HARD RESET LOCAL STATE
    delete window._aiTransientActions[logId];
    window._aiActivePipelineRuns.delete(logId);
    
    // 2. RESET DB STATE & REVERT TEXT (Suggestion is deleted here too)
    await window.sui.api("ai_reset_entries", { ids: [logId] }, { toast: false });
    
    // 3. RE-FLAG AS PENDING (Visual consistency)
    await window.sui.api("ai_flag_pending", { id: logId }, { toast: false });

    // 4. SYNC LOCAL DATA & UI
    const log = logs.find(l => l.id === logId);
    if (log) {
        if (log.original_text) log.transcription = log.original_text;
        log.original_text = null;
        log.ai_processed = 1;
        log.ai_assistant_id = null;
    }

    const cb = document.querySelector(`.custom-checkbox[data-id="${logId}"]`);
    const card = cb ? cb.closest(".card") : null;
    if (card) {
        const textDiv = card.querySelector(".transcription");
        if (textDiv && log) textDiv.innerText = log.transcription;
        aiDecorateCard(card, log);
    }
    
    // 5. TRIGGER PIPELINE (Fresh start, Dispatcher evaluates raw text)
    aiRunPipeline(logId, card, true);
    
    // 6. Refresh Hub UI
    aiLoadSuggestions();
};

function aiInjectRobotBtn() {
    const bar = document.querySelector(".sb-scroll-container") || document.querySelector(".selection-bottom-bar");
    if (bar && !document.getElementById("action-ai-reprocess")) {
        const btn = document.createElement("button");
        btn.className = "bar-action-btn";
        btn.id = "action-ai-reprocess";
        btn.title = "AI Actions";
        btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"></rect><circle cx="12" cy="5" r="2"></circle><path d="M12 7v4"></path><line x1="8" y1="16" x2="8" y2="16"></line><line x1="16" y1="16" x2="16" y2="16"></line></svg>`;
        
        btn.onclick = async () => {
            const items = getSelectedItems();
            if (items.length === 0) return;
            
            const options = [
                { label: "🤖 Send to Dispatcher", value: "dispatch" },
                { label: "🧹 Reset AI State & Text", value: "reset" }
            ];

            if (typeof window.openPicker === "function") {
                window.openPicker(`AI Actions (${items.length})`, options, null, async (val) => {
                    if (val === "dispatch") {
                        if (typeof cjosToggleSelectMode === "function") cjosToggleSelectMode(false);
                        for (const item of items) {
                            await aiRunPipeline(item.id, null, true);
                        }
                    } else if (val === "reset") {
                        window.openConfirm("Reset AI State", `Revert ${items.length} notes to their raw state? This clears badges and pending suggestions.`, () => {
                            aiExecuteReset(items.map(i => i.id));
                        }, true);
                    }
                });
            } else {
                // Fallback: Dispatch only
                if (typeof cjosToggleSelectMode === "function") cjosToggleSelectMode(false);
                for (const item of items) {
                    await aiRunPipeline(item.id, null, true);
                }
            }
        };
        
        // Insert before the delete button
        const delBtn = document.getElementById("action-delete");
        if (delBtn) bar.insertBefore(btn, delBtn);
        else bar.appendChild(btn);
    }
}



window.aiStopPipeline = function(logId) {
    if (window.sui && window.sui.haptic) window.sui.haptic('medium');
    
    // 1. Mark for termination
    window._aiKillSwitch.add(logId);
    
    // 2. Clear Local Visual State
    window._aiActivePipelineRuns.delete(logId);
    delete window._aiTransientActions[logId];
    
    // 3. Reset DB Flag (Stop Hollow Dot)
    window.sui.api("ai_clear_flags", { id: logId }, { toast: false });

    // 4. Update Main Card UI
    const cb = document.querySelector(`.custom-checkbox[data-id="${logId}"]`);
    const card = cb ? cb.closest(".card") : null;
    if (card) {
        card.classList.remove("ai-state-pending", "ai-is-running");
        aiSetStatusLabel(card, "Stopped");
        setTimeout(() => aiSetStatusLabel(card, null), 2000);
    }

    // 5. Refresh Hub UI
    aiLoadSuggestions();
    aiUpdateFabStatus();
};

window.aiExecuteReset = async function(ids) {
    const data = await window.sui.api("ai_reset_entries", { ids: ids }, { toast: "AI States Reset" });
    
    if (data) {
        ids.forEach(id => {
            const log = logs.find(l => l.id === id);
            if (log) {
                if (log.original_text) log.transcription = log.original_text;
                log.original_text = null;
                log.ai_processed = 0;
                log.ai_assistant_id = null;
            }
            // Update DOM
            const cb = document.querySelector(`.custom-checkbox[data-id="${id}"]`);
            if (cb) {
                const card = cb.closest(".card");
                if (card) {
                    const textDiv = card.querySelector(".transcription");
                    if (textDiv && log) textDiv.innerText = log.transcription;
                    card.classList.remove("ai-state-pending", "ai-is-running");
                    aiSetStatusLabel(card, null);
                    
                    // Trigger Engine to clean up badges
                    if (window.sui && window.sui.decorateCard) window.sui.decorateCard(card, log);
                }
            }
        });
        
        if (typeof cjosToggleSelectMode === "function") cjosToggleSelectMode(false);
        aiLoadSuggestions(); 
        
        const t = document.getElementById("toast");
        if (t) { t.innerText = "AI States Reset"; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2000); }
    }
};
JS;
?>