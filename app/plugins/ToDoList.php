<?php
// ==============================================================================
// PLUGIN: To-Do List
// DESCRIPTION: Notes-to-Tasks.
// ==============================================================================

try {
    // Basic Tables
    $db->exec("CREATE TABLE IF NOT EXISTS todo_lists (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        is_starred INTEGER DEFAULT 0,
        created_at INTEGER
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS todo_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        list_id INTEGER,
        log_id TEXT,
        is_done INTEGER DEFAULT 0,
        FOREIGN KEY(list_id) REFERENCES todo_lists(id) ON DELETE CASCADE
    )");

    // MIGRATION: Add sort_order columns if they don't exist
    $cols = $db->query("PRAGMA table_info(todo_lists)")->fetchAll(PDO::FETCH_ASSOC);
    $hasSort = false; foreach($cols as $c) if($c['name'] === 'sort_order') $hasSort = true;
    if(!$hasSort) $db->exec("ALTER TABLE todo_lists ADD COLUMN sort_order INTEGER DEFAULT 0");

    $cols2 = $db->query("PRAGMA table_info(todo_items)")->fetchAll(PDO::FETCH_ASSOC);
    $hasSort2 = false; foreach($cols2 as $c) if($c['name'] === 'sort_order') $hasSort2 = true;
    if(!$hasSort2) $db->exec("ALTER TABLE todo_items ADD COLUMN sort_order INTEGER DEFAULT 0");

    $hasTaskText = false; foreach($cols2 as $c) if($c['name'] === 'task_text') $hasTaskText = true;
    if(!$hasTaskText) $db->exec("ALTER TABLE todo_items ADD COLUMN task_text TEXT DEFAULT NULL");

} catch (Exception $e) {}

// --- HELPER: CLEAN OUTPUT ---
function todo_clean_output() {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    ob_start();
}

// --- API ACTIONS ---

// GET FULL STATE (For LiveSync/AI Handshake)
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'todo_get_state') {
    todo_clean_output();
    header('Content-Type: application/json');
    
    $all_lists = $db->query("SELECT * FROM todo_lists ORDER BY is_starred DESC, sort_order ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $all_items = $db->query("SELECT t.*, l.transcription FROM todo_items t JOIN logs l ON t.log_id = l.id ORDER BY t.sort_order ASC, t.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    $full_data = [];
    foreach ($all_lists as $l) {
        $l['items'] = [];
        $full_data[$l['id']] = $l;
    }
    foreach ($all_items as $i) {
        if (isset($full_data[$i['list_id']])) {
            $i['full_text'] = !empty($i['task_text']) ? $i['task_text'] : $i['transcription'];
            $clean = str_replace(["\r", "\n"], " ", $i['full_text']);
            $i['short_text'] = mb_strimwidth($clean, 0, 60, "...");
            $full_data[$i['list_id']]['items'][] = $i;
        }
    }
    
    ob_end_clean();
    echo json_encode(['status' => 'success', 'full_todo_data' => array_values($full_data)]);
    exit;
}

// Add Items
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'todo_add_to_list') {
    todo_clean_output();
    header('Content-Type: application/json');
    
    $listName = trim($_POST['list_name'] ?? '');
    $listId = $_POST['list_id'] ?? ''; 
    $rawLogIds = $_POST['log_ids'] ?? '[]';
    $logIds = json_decode($rawLogIds, true);

    if ($listId === 'new') {
        if ($listName === '') { echo json_encode(['status'=>'error', 'message'=>'Empty name']); exit; }
        
        // --- DEDUPLICATION: Check if list with this name already exists ---
        $stmtCheck = $db->prepare("SELECT id FROM todo_lists WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmtCheck->execute([$listName]);
        $existingId = $stmtCheck->fetchColumn();

        if ($existingId) {
            $listId = $existingId;
        } else {
            // Create new only if it doesn't exist
            $stmt = $db->prepare("INSERT INTO todo_lists (name, created_at, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$listName, time(), 0]);
            $listId = $db->lastInsertId();
        }
    }

    if (!empty($logIds) && is_array($logIds)) {
        // We no longer delete all old items for this log_id to support multiple unique tasks per card
        $insertNew = $db->prepare("INSERT INTO todo_items (list_id, log_id, sort_order, task_text) VALUES (?, ?, ?, ?)");
        
        $maxQ = $db->prepare("SELECT MAX(sort_order) FROM todo_items WHERE list_id = ?");
        $maxQ->execute([$listId]);
        $nextSort = ($maxQ->fetchColumn() ?: 0) + 1;

        $taskText = $_POST['task_text'] ?? null;
        foreach ($logIds as $logId) {
            $insertNew->execute([$listId, $logId, $nextSort, $taskText]);
            $nextSort++;
        }
    }

    // Fetch the final name in case it was an existing list
    if ($listId !== 'new') {
        $stmt = $db->prepare("SELECT name FROM todo_lists WHERE id = ?");
        $stmt->execute([$listId]);
        $listName = $stmt->fetchColumn();
    }
    
    // --- SYNC UPGRADE: Fetch full data for immediate frontend update ---
    $all_lists = $db->query("SELECT * FROM todo_lists ORDER BY is_starred DESC, sort_order ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $all_items = $db->query("SELECT t.*, l.transcription FROM todo_items t JOIN logs l ON t.log_id = l.id ORDER BY t.sort_order ASC, t.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $full_data = [];
    foreach ($all_lists as $l) {
        $l['items'] = [];
        $full_data[$l['id']] = $l;
    }
    foreach ($all_items as $i) {
        if (isset($full_data[$i['list_id']])) {
            $i['full_text'] = $i['transcription'];
            $clean = str_replace(["\r", "\n"], " ", $i['full_text']);
            $i['short_text'] = mb_strimwidth($clean, 0, 60, "...");
            $full_data[$i['list_id']]['items'][] = $i;
        }
    }

    ob_end_clean();
    echo json_encode([
        'status' => 'success', 
        'list_id' => $listId, 
        'list_name' => $listName,
        'full_todo_data' => array_values($full_data)
    ]);
    exit;
}

// Rename List
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'todo_rename_list') {
    todo_clean_output();
    $id = $_POST['list_id'];
    $name = trim($_POST['name']);
    if($name) {
        $db->prepare("UPDATE todo_lists SET name = ? WHERE id = ?")->execute([$name, $id]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// Remove from List (Bulk)
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'todo_remove_from_list') {
    todo_clean_output();
    header('Content-Type: application/json');
    $listId = $_POST['list_id'] ?? ''; 
    $logIds = json_decode($_POST['log_ids'] ?? '[]', true);
    
    $stmtName = $db->prepare("SELECT name FROM todo_lists WHERE id = ?");
    $stmtName->execute([$listId]);
    $listName = $stmtName->fetchColumn();

    if (!empty($logIds) && is_array($logIds)) {
        $stmt = $db->prepare("DELETE FROM todo_items WHERE list_id = ? AND log_id = ?");
        foreach ($logIds as $lid) $stmt->execute([$listId, $lid]);
    }
    
    ob_end_clean();
    echo json_encode(['status' => 'success', 'list_id' => $listId, 'list_name' => $listName]);
    exit;
}

// Reorder Lists
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'todo_reorder_lists') {
    todo_clean_output();
    $orderMap = json_decode($_POST['order'], true); // {id: sort_order, id: sort_order}
    if ($orderMap) {
        $stmt = $db->prepare("UPDATE todo_lists SET sort_order = ? WHERE id = ?");
        foreach ($orderMap as $id => $sort) {
            $stmt->execute([$sort, $id]);
        }
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// Reorder Items
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'todo_reorder_items') {
    todo_clean_output();
    $orderMap = json_decode($_POST['order'], true); 
    if ($orderMap) {
        $stmt = $db->prepare("UPDATE todo_items SET sort_order = ? WHERE id = ?");
        foreach ($orderMap as $id => $sort) {
            $stmt->execute([$sort, $id]);
        }
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// Move Item to Different List
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'todo_move_item') {
    todo_clean_output();
    $itemId = $_POST['item_id'];
    $targetListId = $_POST['target_list_id'];
    
    // Get max sort of target
    $maxQ = $db->prepare("SELECT MAX(sort_order) FROM todo_items WHERE list_id = ?");
    $maxQ->execute([$targetListId]);
    $nextSort = ($maxQ->fetchColumn() ?: 0) + 1;
    
    $db->prepare("UPDATE todo_items SET list_id = ?, sort_order = ? WHERE id = ?")
       ->execute([$targetListId, $nextSort, $itemId]);
       
    echo json_encode(['status' => 'success']);
    exit;
}

// Toggle Check/Star/Delete Single
if (isset($_GET['plugin_action'])) {
    if ($_GET['plugin_action'] === 'todo_delete_single_item') {
        todo_clean_output();
        $db->prepare("DELETE FROM todo_items WHERE id = ?")->execute([$_GET['id']]);
        exit;
    }
    if ($_GET['plugin_action'] === 'todo_toggle_check') {
        todo_clean_output();
        $db->prepare("UPDATE todo_items SET is_done = ? WHERE id = ?")->execute([$_GET['state'], $_GET['item_id']]);
        exit;
    }
    if ($_GET['plugin_action'] === 'todo_toggle_star') {
        todo_clean_output();
        $db->prepare("UPDATE todo_lists SET is_starred = ? WHERE id = ?")->execute([$_GET['state'], $_GET['list_id']]);
        exit;
    }
    if ($_GET['plugin_action'] === 'todo_delete_list') {
        todo_clean_output();
        $id = $_GET['list_id'];
        $db->prepare("DELETE FROM todo_lists WHERE id = ?")->execute([$id]);
        $db->prepare("DELETE FROM todo_items WHERE list_id = ?")->execute([$id]);
        exit;
    }
}

// --- DATA FETCHING ---
$db->exec("DELETE FROM todo_items WHERE log_id NOT IN (SELECT id FROM logs)");

// Fetch lists (Sorted by Starred, then custom Sort Order, then Date)
$lists = $db->query("SELECT * FROM todo_lists ORDER BY is_starred DESC, sort_order ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch items (Sorted by custom Sort Order, then ID)
$items = $db->query("
    SELECT t.*, l.transcription 
    FROM todo_items t 
    JOIN logs l ON t.log_id = l.id
    ORDER BY t.sort_order ASC, t.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$todoData = [];
foreach ($lists as $l) {
    $l['items'] = [];
    $todoData[$l['id']] = $l;
}
foreach ($items as $i) {
    if (isset($todoData[$i['list_id']])) {
        // UI Logic: Prioritize specific task_text, fallback to log transcription
        $i['full_text'] = !empty($i['task_text']) ? $i['task_text'] : $i['transcription'];
        
        $clean = str_replace(["\r", "\n"], " ", $i['full_text']);
        $i['short_text'] = mb_strimwidth($clean, 0, 60, "...");
        $todoData[$i['list_id']]['items'][] = $i;
    }
}

// Create Map for Badges
$logLabels = [];
foreach ($items as $i) {
    if (isset($todoData[$i['list_id']])) {
        $logLabels[$i['log_id']][] = $todoData[$i['list_id']]['name'];
    }
}

$todo_bridge = [
    'todoData' => array_values($todoData),
    'logLabels' => $logLabels
];
$todo_bridge_json = json_encode($todo_bridge);
$plugin_js .= "\nwindow.__TODO_BRIDGE__ = $todo_bridge_json;\n";


// ==============================================================================
// 2. FRONTEND: HTML VIEW (Page 3)
// ==============================================================================

$todo_page_html = <<<'HTML'
<div class="scroll-view" id="todo-app-view">
    
    <!-- Title Row -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-top:10px;">
        <div class="page-title" style="margin-bottom:0; padding-top:0;">To-Do Lists</div>
    </div>

    <div id="todo-lists-container"></div>
    
    <!-- "Create List" Button (Bottom) -->
    <div onclick="createNewListManual()" style="
        background:var(--card-bg); 
        border-radius:18px; 
        padding:16px; 
        text-align:center; 
        font-weight:600; 
        color:var(--primary); 
        cursor:pointer; 
        border: 1px solid var(--border-color);
        box-shadow:var(--shadow-card); 
        margin-top:24px; 
        margin-bottom:40px; 
        display:flex; 
        align-items:center; 
        justify-content:center; 
        gap:8px;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    ">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:20px;height:20px;stroke-width:2.5;"><path d="M12 5v14M5 12h14"></path></svg>
        Create New List
    </div>

    <div style="height:100px;"></div>
</div>
HTML;

if(!isset($plugin_pages)) $plugin_pages = [];
$plugin_pages[] = $todo_page_html;

$plugin_widgets[] = [
    'id' => 'pinned-todo',
    'title' => 'Pinned Lists',
    'icon' => '📌',
    'icon_color' => 'var(--primary)',
    'html' => '<div id="todo-pinned-wrapper" style="display:flex; flex-direction:column; gap:12px;"></div>'
];

$plugin_tools[] = [
    'name' => 'To-Do Lists',
    'desc' => 'Manage tasks',
    'sui_icon' => 'check',
    'color' => 'rgba(52, 199, 89, 0.1)',
    'icon_color' => '#34C759',
    'action' => "dashNavToPage('todo-app-view')",
    'linked_page' => 'todo-app-view',
    'linked_widget' => 'pinned-todo'
];


// ==============================================================================
// 3. FRONTEND: JAVASCRIPT LOGIC
// ==============================================================================

$plugin_js .= <<<'JS'
// --- TODO PLUGIN JS ---

window.syncTodoDataWithLogs = function(targetId = null) {
    if (typeof todoData === 'undefined' || typeof logs === 'undefined') return;
    todoData.forEach(list => {
        list.items.forEach(item => {
            // If targetId provided, only sync that one; otherwise sync all
            if (targetId && item.log_id !== targetId) return;
            
            const entry = logs.find(l => l.id === item.log_id);
            if (entry && entry.transcription !== item.full_text) {
                item.full_text = entry.transcription;
                const clean = entry.transcription.replace(/[\r\n]/g, " ");
                item.short_text = clean.substring(0, 60) + "...";
            }
        });
    });
};

// Register for global updates
if (window.registerUpdateHook) {
    window.registerUpdateHook((id) => {
        window.syncTodoDataWithLogs(id);
        renderTodoApp();
        renderDashboardPinnedLists();
    });
}

const todoBridge = window.__TODO_BRIDGE__ || { todoData: [], logLabels: {} };
let todoData = todoBridge.todoData;
const logLabels = todoBridge.logLabels;

let selectedForTodo = [];
let editingListIds = [];

// Inject Styles for Soft Pulse Animation
const todoStyle = document.createElement("style");
todoStyle.innerHTML = `
    @keyframes softPulse {
        0% { box-shadow: 0 0 0 0 rgba(0, 122, 255, 0); background-color: rgba(0, 122, 255, 0); transform: scale(1); }
        50% { box-shadow: 0 0 20px 0 rgba(0, 122, 255, 0.25); background-color: rgba(0, 122, 255, 0.08); transform: scale(1.02); }
        100% { box-shadow: 0 0 0 0 rgba(0, 122, 255, 0); background-color: rgba(0, 122, 255, 0); transform: scale(1); }
    }
    .highlight-once { animation: softPulse 0.8s ease-in-out 1; }
    .highlight-twice { animation: softPulse 0.8s ease-in-out 2; }
    .todo-double-bounce { animation: imCardBounce 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275) 2 !important; }
`;document.head.appendChild(todoStyle);

// 1. INJECT BUTTON INTO SELECTION BAR
const bottomBar = document.querySelector(".selection-bottom-bar");
if (bottomBar) {
    const listBtn = document.createElement("button");
    listBtn.className = "bar-action-btn";
    listBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path></svg>`;
    listBtn.onclick = () => initiateAddToList();
    bottomBar.appendChild(listBtn);
}

// 2. INJECT DASHBOARD ITEMS (TOOLS GRID)

// Phase 8: Badge Engine Registration
if (window.sui && window.sui.registerBadge) {
    window.sui.registerBadge("todo-label-badge", (entry) => {
        const listNames = logLabels[entry.id];
        if (!listNames || listNames.length === 0) return null;
        
        return listNames.map(name => {
            // Use specific styling for Todo badges via factory if needed, or default 'todo' type
            return window.suiBadge(name, "todo");
        });
    }, 30); // Priority 30: Structural/State
}

function todoInit() {
    // Register for global refreshes (e.g. after folder switch or search)
    if (window.registerRefreshHook) {
        window.registerRefreshHook(renderLogLabels);
    }

    // B. Pinned Lists Widget (Handled by Dashboard hydration, but safe to call here)
    renderDashboardPinnedLists();
    
    // C. Main App
    renderTodoApp();
    renderLogLabels();
}

// Listen for Lazy Hydration
window.addEventListener('cjos-hydrated', (e) => {
    if (e.detail.id === 'todo-app-view') {
        todoInit();
    }
});

function renderLogLabels() {
    // 1. Rebuild the map from current todoData to ensure sync with AI actions
    for (let key in logLabels) delete logLabels[key];
    todoData.forEach(list => {
        list.items.forEach(item => {
            if (!logLabels[item.log_id]) logLabels[item.log_id] = [];
            if (!logLabels[item.log_id].includes(list.name)) logLabels[item.log_id].push(list.name);
        });
    });

    // 2. Trigger Engine Update for all visible cards
    if (window.sui && window.sui.decorateCard) {
        document.querySelectorAll(".card").forEach(card => {
             const id = card.querySelector(".custom-checkbox")?.getAttribute("data-id");
             const entry = logs.find(l => l.id === id);
             if (entry) window.sui.decorateCard(card, entry);
        });
    }
}

// --- DASHBOARD PINNED LISTS RENDERER ---
window.renderDashboardPinnedLists = function() {
    let wrapper = document.getElementById("todo-pinned-wrapper");
    if (!wrapper) return;

    wrapper.innerHTML = "";
    let hasPinned = false;

    // Sort Dashboard items by sort_order as well (to match Main view)
    const sortedData = [...todoData].sort((a,b) => {
        if(a.is_starred !== b.is_starred) return b.is_starred - a.is_starred;
        return a.sort_order - b.sort_order;
    });

    sortedData.forEach(list => {
        if(list.is_starred == 1) {
            hasPinned = true;
            const pin = document.createElement("div");
            pin.style.cssText = "width:100%; box-sizing:border-box; background:var(--card-bg); border:1px solid var(--border-color); padding:20px; border-radius:22px; box-shadow:var(--shadow-card); cursor:pointer; display:flex; flex-direction:column; gap:10px; color:var(--text-primary);";
            
            let itemsHtml = "";
            list.items.slice(0, 3).forEach(item => {
                const checkColor = item.is_done == 1 ? "#34C759" : "#C7C7CC";
                const textColor = item.is_done == 1 ? "var(--text-secondary)" : "var(--text-primary)";
                const deco = item.is_done == 1 ? "line-through" : "none";
                itemsHtml += `
                    <div style="display:flex; align-items:flex-start; gap:10px; font-family:system-ui, -apple-system, sans-serif; font-size:15px; color:${textColor}; line-height:1.4;">
                        <div style="width:18px; height:18px; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px;">
                            <div style="width:8px; height:8px; border-radius:50%; background:${checkColor};"></div>
                        </div>
                        <div style="text-decoration:${deco}; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden;">${item.full_text}</div>
                    </div>`;
            });
            
            if(list.items.length === 0) itemsHtml = "<div style='color:var(--text-secondary); font-size:14px; font-style:italic; opacity:0.6;'>Empty list</div>";
            else if(list.items.length > 3) itemsHtml += `<div style="color:var(--text-secondary); font-size:12px; margin-top:4px; font-weight:600; padding-left:28px;">+ ${list.items.length - 3} more items</div>`;

            pin.innerHTML = `
                <div style="font-weight:800; font-size:18px; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-bottom:4px;">${list.name}</div>
                <div style="display:flex; flex-direction:column; gap:8px;">${itemsHtml}</div>
            `;
            pin.onclick = () => document.querySelector(".horizontal-viewport").scrollTo({left: window.innerWidth * 2, behavior: "smooth"});
            wrapper.appendChild(pin);
        }
    });

    const label = document.getElementById("todo-pinned-label");
    if(label) label.style.display = hasPinned ? "block" : "none";
    wrapper.style.display = hasPinned ? "flex" : "none";
};

// --- MAIN UI RENDERER ---

function renderTodoApp() {
    const cont = document.getElementById("todo-lists-container");
    if(!cont) return;
    cont.innerHTML = "";
    
    // Sort logic for display: Starred -> Order -> Date
    todoData.forEach((list, listIdx) => {
        const isEditing = editingListIds.includes(list.id);
        const listDiv = document.createElement("div");
        listDiv.id = "todo-list-wrap-" + list.id;
        listDiv.style.cssText = "background:var(--card-bg); border:1px solid var(--border-color); border-radius:22px; padding:24px; box-shadow:var(--shadow-card); margin-bottom:20px; position:relative; transition: transform 0.2s;";
        const starColor = list.is_starred == 1 ? "#FFCC00" : "#D4D4D4";
        
        let headerHtml = "";
        
        if (isEditing) {
            // --- EDIT MODE HEADER ---
            // List Reorder Controls (Minimal - Transparent)
            const listArrows = `
                <div style="display:flex; flex-direction:column; gap:0px; margin-right:8px;">
                    <button onclick="reorderList(${listIdx}, -1)" style="background:transparent; border:none; width:24px; height:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-secondary); padding:0;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px;"><polyline points="18 15 12 9 6 15"></polyline></svg>
                    </button>
                    <button onclick="reorderList(${listIdx}, 1)" style="background:transparent; border:none; width:24px; height:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-secondary); padding:0;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                </div>
            `;

            headerHtml = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <div style="display:flex; align-items:center; gap:4px;">
                    ${listArrows}
                    <div style="font-size:22px; font-weight:800; color:var(--text-primary); letter-spacing:-0.5px;">${list.name}</div>
                    <button onclick="renameTodoList(${list.id}, '${list.name.replace(/'/g, "\\'")}')" style="background:var(--btn-bg); border:none; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--btn-text); margin-left:4px;">
                        <span data-sui-icon="edit" data-sui-size="14" data-sui-stroke="2.5"></span>
                    </button>
                </div>
                <div style="display:flex; gap:12px;">
                    <button onclick="toggleListEditMode(${list.id})" style="background:var(--success-bg); border:none; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--success-text); box-shadow:0 2px 5px rgba(0,0,0,0.1);" title="Done">
                        <span data-sui-icon="check" data-sui-size="20" data-sui-stroke="3"></span>
                    </button>
                    <button onclick="deleteTodoList(${list.id})" style="background:var(--danger); border:none; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--primary-text); box-shadow:0 2px 5px rgba(255, 59, 48, 0.2);" title="Delete List">
                        <span data-sui-icon="trash" data-sui-size="18"></span>
                    </button>
                </div>
            </div>`;
        } else {
            // --- VIEW MODE HEADER ---
            headerHtml = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <div style="font-size:22px; font-weight:800; color:var(--text-primary); letter-spacing:-0.5px;">${list.name}</div>
                <div style="display:flex; gap:12px;">
                    <button onclick="toggleListEditMode(${list.id})" style="background:none; border:none; color:var(--text-primary); opacity:0.4; cursor:pointer; display:flex; align-items:center; transition:opacity 0.2s;" title="Edit List">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:20px;height:20px;stroke-width:2.5;"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                    </button>
                    <button onclick="toggleTodoStar(${list.id}, ${list.is_starred})" style="background:none; border:none; color:${starColor}; cursor:pointer; display:flex; align-items:center;">
                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:24px;height:24px;"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    </button>
                </div>
            </div>`;
        }
        
        const itemsCont = document.createElement("div");
        if(list.items.length === 0) {
            itemsCont.innerHTML = `<div style="color:#B0B0B5; font-style:italic; font-size:14px;">Empty list</div>`;
        } else {
            list.items.forEach((item, itemIdx) => {
                const itemRow = document.createElement("div");
                const isDone = item.is_done == 1;
                itemRow.id = "todo-item-wrap-" + item.id;
                itemRow.style.cssText = `display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid rgba(0,0,0,0.05); transition:all 0.2s; ${isDone && !isEditing ? "opacity:0.5;" : ""}`;
                
                let checkHtml = "";
                let itemReorder = "";
                let itemActions = "";

                if (isEditing) {
                    // Item Reorder (Minimalist Chevrons)
                    itemReorder = `
                        <div style="display:flex; flex-direction:column; gap:0px;">
                            <button onclick="reorderItem(${listIdx}, ${itemIdx}, -1)" style="background:transparent; border:none; width:20px; height:14px; cursor:pointer; padding:0; display:flex; align-items:center; justify-content:center; color:var(--text-secondary);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px; height:12px;"><polyline points="18 15 12 9 6 15"></polyline></svg>
                            </button>
                            <button onclick="reorderItem(${listIdx}, ${itemIdx}, 1)" style="background:transparent; border:none; width:20px; height:14px; cursor:pointer; padding:0; display:flex; align-items:center; justify-content:center; color:var(--text-secondary);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:12px; height:12px;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                            </button>
                        </div>
                    `;

                    // Delete Button
                    checkHtml = `
                    <div onclick="removeTodoItem(${item.id})" style="width:24px; height:24px; border-radius:50%; background:#FF3B30; display:flex; align-items:center; justify-content:center; flex-shrink:0; cursor:pointer;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="white" style="width:14px; stroke-width:3;"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    </div>`;

                    // Move Button (Minimalist Folder)
                    itemActions = `
                    <div style="display:flex; gap:8px; margin-left:auto;">
                        <button onclick="moveItemToList(${item.id})" title="Move to another list" style="background:transparent; border:none; cursor:pointer; color:var(--text-secondary); padding:4px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:20px; height:20px; stroke-width:2;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
                        </button>
                    </div>`;

                } else {
                    // Standard Checkbox
                    checkHtml = `
                    <div onclick="toggleTodoItem(${item.id}, ${item.is_done})" style="width:24px; height:24px; border-radius:50%; border:${isDone ? '2px' : '1.5px'} solid ${isDone ? "var(--primary)" : "var(--text-secondary)"}; background:${isDone ? "var(--primary)" : "transparent"}; opacity:${isDone ? '1' : '0.8'}; display:flex; align-items:center; justify-content:center; flex-shrink:0; cursor:pointer;">
                        ${isDone ? "<svg viewBox='0 0 24 24' fill='none' stroke='white' style='width:16px;'><polyline points='20 6 9 17 4 12'></polyline></svg>" : ""}
                    </div>`;
                    
                    // Jump to context Arrow (Minimalist)
                    itemActions = `
                    <div style="margin-left:auto;">
                        <button onclick="jumpToLog('${item.log_id}')" title="Jump to entry" style="background:transparent; border:none; color:var(--text-secondary); cursor:pointer; padding:4px; opacity:0.8;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:18px; height:18px; stroke-width:1.5;"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </button>
                    </div>`;
                }

                itemRow.innerHTML = `
                    ${itemReorder}
                    ${checkHtml}
                    <div style="flex:1; cursor:pointer; padding-right:8px;" onclick="toggleExpandText(this)">
                        <div class="todo-text ${isDone ? "done" : ""}" style="
                            font-family: system-ui, -apple-system, sans-serif;
                            font-size: 16px; 
                            line-height: 1.5; 
                            color: ${isDone && !isEditing ? "var(--text-secondary)" : "var(--text-primary)"};
                            text-decoration: ${isDone && !isEditing ? "line-through" : "none"};
                            display: -webkit-box;
                            -webkit-line-clamp: 2;
                            -webkit-box-orient: vertical;
                            overflow: hidden;
                        ">${item.full_text}</div>
                    </div>
                    ${itemActions}
                `;
                itemsCont.appendChild(itemRow);
            });
        }
        
        listDiv.innerHTML = headerHtml;
        listDiv.appendChild(itemsCont);
        cont.appendChild(listDiv);
        if (typeof window.srWatch === 'function') window.srWatch(listDiv);
    });
    if (window.suiHydrateIcons) window.suiHydrateIcons(cont);
}

// --- HELPER: POST-MOVE HIGHLIGHT (GLOW 1x) ---
function highlightAfterRender(id, type) {
    const prefix = type === "list" ? "todo-list-wrap-" : "todo-item-wrap-";
    setTimeout(() => {
        const el = document.getElementById(prefix + id);
        if(el) {
            el.scrollIntoView({ behavior: "smooth", block: "center" });
            el.classList.add("highlight-once");
            setTimeout(() => el.classList.remove("highlight-once"), 1000);
        }
    }, 150);
}

// --- REORDERING LOGIC ---

window.reorderList = function(index, direction) {
    window.sui.haptic('light');
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= todoData.length) return;

    const temp = todoData[index];
    todoData[index] = todoData[newIndex];
    todoData[newIndex] = temp;

    const orderMap = {};
    todoData.forEach((l, i) => orderMap[l.id] = i);
    
    window.sui.api("todo_reorder_lists", { order: orderMap }, { toast: false });

    renderTodoApp();
    renderDashboardPinnedLists();
    
    highlightAfterRender(temp.id, "list");
};

window.reorderItem = function(listIdx, itemIdx, direction) {
    const list = todoData[listIdx];
    const newIdx = itemIdx + direction;
    if (newIdx < 0 || newIdx >= list.items.length) return;

    const temp = list.items[itemIdx];
    list.items[itemIdx] = list.items[newIdx];
    list.items[newIdx] = temp;

    const orderMap = {};
    list.items.forEach((item, i) => orderMap[item.id] = i);

    window.sui.api("todo_reorder_items", { order: orderMap }, { toast: false });

    renderTodoApp();
    renderDashboardPinnedLists();
    
    highlightAfterRender(temp.id, "item");
};

// --- REASSIGN / MOVE ITEM ---

window.moveItemToList = function(itemId) {
    let foundItem = null;
    let fromListId = null;
    
    for(const list of todoData) {
        const i = list.items.find(x => x.id == itemId);
        if(i) { foundItem = i; fromListId = list.id; break; }
    }
    if(!foundItem) return;

    const options = todoData
        .filter(l => l.id !== fromListId) 
        .map(l => ({ value: l.id, label: l.name }));
        
    if(options.length === 0) { window.openConfirm("Move Error", "No other lists to move to.", null, false, "OK", null); return; }

    if(window.openPicker) {
        window.openPicker("Move to List", options, null, (targetListId) => {
            if(targetListId) performMoveItem(itemId, targetListId);
        });
    } else {
        window.openConfirm("System Error", "SharedUI plugin missing.", null, false, "OK", null);
    }
};

function performMoveItem(itemId, targetListId) {
    let itemObj = null;
    for(const list of todoData) {
        const idx = list.items.findIndex(i => i.id == itemId);
        if(idx !== -1) {
            itemObj = list.items.splice(idx, 1)[0];
            break;
        }
    }
    const targetList = todoData.find(l => l.id == targetListId);
    if(itemObj && targetList) {
        itemObj.list_id = targetListId;
        targetList.items.push(itemObj); 
    }
    
    renderTodoApp();
    renderDashboardPinnedLists();
    renderLogLabels();
    highlightAfterRender(itemId, "item");

    window.sui.api("todo_move_item", { item_id: itemId, target_list_id: targetListId }, { toast: false });
}

// --- JUMP TO CONTEXT LOGIC (SMART SCROLL & GLOW 2x) ---

window.jumpToLog = function(logId) {
    // 1. Switch View to Stream
    const viewport = document.querySelector(".horizontal-viewport");
    if(viewport) viewport.scrollTo({ left: 0, behavior: "smooth" });

    // 2. Ensure Correct Folder is Active (SmartOrganizer)
    if(typeof so_map !== "undefined") {
        // If not in map, it belongs to Unsorted (0). 
        // We use undefined check because 0 is falsy in JS.
        const targetFid = (so_map[logId] !== undefined) ? so_map[logId] : 0;
        if(typeof setFolderFilter === "function") {
            setFolderFilter(targetFid); 
        }
    }
    // 3. Find Card, Scroll, and Animate WHEN VISIBLE
    setTimeout(() => {
        const checkbox = document.querySelector(`.custom-checkbox[data-id="${logId}"]`);
        if(checkbox) {
            const card = checkbox.closest(".card");
            
            if(card.classList.contains("phantom-card")) card.classList.remove("phantom-card");
            const actions = card.querySelector(".phantom-actions");
            if (actions) actions.remove();
            
            card.style.display = "block"; 
            
            // Trigger Scroll
            card.scrollIntoView({ behavior: "smooth", block: "center" });

            // WAIT FOR SCROLL TO FINISH (Intersection Observer)
            const observer = new IntersectionObserver((entries) => {
                if(entries[0].isIntersecting) {
                    observer.disconnect(); 
                    
                    // Trigger animation IMMEDIATELY upon visibility
                    card.classList.add("jump-highlight");
                    
                    // Add physical bounce effect (Double Bounce)
                    card.classList.add("todo-double-bounce");
                    
                    setTimeout(() => {
                        card.classList.remove("jump-highlight");
                        card.classList.remove("todo-double-bounce");
                    }, 4000);                }
            }, { threshold: 0.6 });            
            observer.observe(card);
        } else {
            if(typeof searchQuery !== "undefined" && searchQuery !== "") {
                window.openConfirm("Search Active", "Please clear search to view entry.", null, false, "OK", null);
            }
        }
    }, 400); 
};


// --- STANDARD TODO FUNCTIONS (Renaming, Toggle, Etc) ---

window.toggleListEditMode = function(listId) {
    if (editingListIds.includes(listId)) {
        editingListIds = editingListIds.filter(id => id !== listId);
    } else {
        editingListIds.push(listId);
    }
    renderTodoApp();
}

window.renameTodoList = function(id, oldName) {
    window.openInput("Rename List", "New name", oldName, (newName) => {
        if(newName && newName.trim() !== "") {
            const list = todoData.find(l => l.id == id);
            if(list) list.name = newName;
            renderTodoApp();
            renderDashboardPinnedLists();
            renderLogLabels();
            
            window.sui.api("todo_rename_list", { list_id: id, name: newName }, { toast: false });
        }
    });
};

window.removeTodoItem = function(itemId) {
    let deletedItem = null;
    let deletedIndex = -1;
    let targetList = null;

    for(let list of todoData) {
        const idx = list.items.findIndex(i => i.id == itemId);
        if(idx !== -1) {
            deletedItem = list.items[idx];
            deletedIndex = idx;
            targetList = list;
            list.items.splice(idx, 1);
            break;
        }
    }
    renderTodoApp();
    renderDashboardPinnedLists();
    renderLogLabels();
    
    fetch(`index.php?plugin_action=todo_delete_single_item&id=${itemId}`);
};

window.toggleTodoItem = function(id, current) {
    let targetItem = null;
    for (let list of todoData) {
        let item = list.items.find(i => i.id == id);
        if (item) {
            item.is_done = item.is_done == 1 ? 0 : 1;
            targetItem = item;
            break;
        }
    }
    renderTodoApp();
    renderDashboardPinnedLists();
    
    if(targetItem) {
        const newState = current ? 0 : 1;
        fetch(`index.php?plugin_action=todo_toggle_check&item_id=${id}&state=${newState}`);
    }
};

window.toggleTodoStar = function(id, current) {
    const list = todoData.find(l => l.id == id);
    if(list) {
        list.is_starred = list.is_starred == 1 ? 0 : 1;
        todoData.sort((a, b) => {
            if (a.is_starred !== b.is_starred) return b.is_starred - a.is_starred;
            return a.sort_order - b.sort_order;
        });
        
        renderTodoApp();
        renderDashboardPinnedLists();
        
        const newState = current ? 0 : 1;
        fetch(`index.php?plugin_action=todo_toggle_star&list_id=${id}&state=${newState}`);
    }
};

window.deleteTodoList = function(id) {
    window.openConfirm("Delete List", "Are you sure you want to delete this list and all its tasks?", () => {
        const idx = todoData.findIndex(l => l.id == id);
        if(idx !== -1) todoData.splice(idx, 1);
        
        renderTodoApp();
        renderDashboardPinnedLists();
        renderLogLabels();
        fetch(`index.php?plugin_action=todo_delete_list&list_id=${id}`);
    }, true);
};

window.toggleExpandText = (el) => {
    const textDiv = el.querySelector(".todo-text");
    if(textDiv.style.webkitLineClamp) {
        textDiv.style.webkitLineClamp = "";
        textDiv.style.display = "block";
    } else {
        textDiv.style.webkitLineClamp = "2";
        textDiv.style.display = "-webkit-box";
    }
};

// --- PIPELINE HOOKS ---
if (window.cjosHooks) {
    window.cjosHooks.register('onDelete', (id) => {
        todoData.forEach(list => {
            list.items = list.items.filter(i => i.log_id !== id);
        });
    });
}

if (window.registerRefreshHook) {
    window.registerRefreshHook(() => {
        window.syncTodoDataWithLogs();
        if (document.getElementById('todo-app-view')) renderTodoApp();
        renderDashboardPinnedLists();
        renderLogLabels();
    });
}

function initiateAddToList() {
    selectedForTodo = getSelectedItems().map(i => i.id);
    let commonListId = null;
    if (selectedForTodo.length > 0) {
        for (const list of todoData) {
            const listLogIds = list.items.map(i => i.log_id);
            const allPresent = selectedForTodo.every(id => listLogIds.includes(id));
            if (allPresent) {
                commonListId = list.id;
                break; 
            }
        }
    }
    const options = todoData.map(l => ({ 
        value: l.id, 
        label: l.name + (l.is_starred == 1 ? " ★" : "") 
    }));
    options.unshift({ value: "create_new", label: "+ Create New List" });
    
    if(window.openPicker) {
        window.openPicker("Add to List", options, commonListId, (val) => {
            if (val === "create_new") {
                window.openInput("Create New List", "List Name", "", (name) => {
                    if (name) handleTodoListAction("add", "new", name);
                });
            } else if (val === commonListId) {
                handleTodoListAction("remove", val);
            } else {
                handleTodoListAction("add", val);
            }
        });
    } else {
        window.openConfirm("System Error", "SharedUI Plugin required.", null, false, "OK", null);
    }
}

window.createNewListManual = function() {
    window.openInput("Create New List", "List Name", "", (name) => {
        if (name) {
            selectedForTodo = [];
            handleTodoListAction("add", "new", name);
        }
    });
};

function handleTodoListAction(action, listId, listName = "") {
    const apiAction = action === "add" ? "todo_add_to_list" : "todo_remove_from_list";
    const apiData = { log_ids: selectedForTodo, list_id: listId };
    if (action === "add") apiData.list_name = listName;
    
    const overlay = document.getElementById("processing-overlay");
    const procText = document.getElementById("proc-text");
    if(overlay) { 
        overlay.classList.add("visible"); 
        procText.textContent = action === "add" ? "Adding..." : "Removing...";
    }
    
    window.sui.api(apiAction, apiData, { toast: false })
        .then(data => {
            if(data.status === "success") {
                const targetListId = data.list_id;
                const targetListName = data.list_name;

                if (action === "add") {
                    // 1. Update todoData
                    let list = todoData.find(l => l.id == targetListId);
                    if (!list) {
                        list = { id: targetListId, name: targetListName, is_starred: 0, items: [], sort_order: 0 };
                        todoData.unshift(list);
                    }

                    selectedForTodo.forEach(lid => {
                        const entry = logs.find(l => l.id === lid);
                        if (entry && !list.items.find(i => i.log_id === lid)) {
                            const clean = entry.transcription.replace(/[\r\n]/g, " ");
                            list.items.push({
                                id: "temp_" + Date.now() + Math.random(),
                                list_id: targetListId,
                                log_id: lid,
                                is_done: 0,
                                full_text: entry.transcription,
                                short_text: clean.substring(0, 60) + "..."
                            });
                            // 2. Update logLabels for card badges
                            if (!logLabels[lid]) logLabels[lid] = [];
                            if (!logLabels[lid].includes(targetListName)) logLabels[lid].push(targetListName);
                        }
                    });
                } else {
                    // Remove Action
                    const list = todoData.find(l => l.id == targetListId);
                    if (list) {
                        list.items = list.items.filter(i => !selectedForTodo.includes(i.log_id));
                        selectedForTodo.forEach(lid => {
                            if (logLabels[lid]) {
                                logLabels[lid] = logLabels[lid].filter(n => n !== list.name);
                                if (logLabels[lid].length === 0) delete logLabels[lid];
                            }
                        });
                    }
                }

                // 3. Refresh UI Components via Bus
                if (window.cjosRefreshPlugins) window.cjosRefreshPlugins();
                
                if (typeof cjosToggleSelectMode === "function") cjosToggleSelectMode(false);
if (overlay) overlay.classList.remove("visible");if (window.sui && window.sui.toast) {
    window.sui.toast(action === "add" ? "Added to List" : "Removed from List", { 
        plugin: "ToDoList", 
        caller: "handleTodoListAction", 
        metrics: { action: action, list_id: listId, items_count: selectedForTodo.length } 
    });
}} else {
                window.openConfirm("Action Failed", data.message, null, true, "OK", null);
                if(overlay) overlay.classList.remove("visible");
            }
        })
        .catch(err => {
            console.error(err);
            window.openConfirm("Network Error", "Connection failed. Please check your server.", null, true, "OK", null);
            if(overlay) overlay.classList.remove("visible");
        });
}
JS;
?>