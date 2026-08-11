<?php
// ==============================================================================
// PLUGIN: DogEar
// DESCRIPTION: Pin Notes to Top.
// Pin entries to the top by clicking the corner.
// (Folder-Aware Version)
// ==============================================================================

// 1. DATABASE MIGRATION
try {
    $cols = $db->query("PRAGMA table_info(logs)")->fetchAll(PDO::FETCH_ASSOC);
    $hasCol = false;
    foreach ($cols as $c) { if ($c['name'] === 'is_dogeared') $hasCol = true; }
    if (!$hasCol) {
        $db->exec("ALTER TABLE logs ADD COLUMN is_dogeared INTEGER DEFAULT 0");
    }
} catch (Exception $e) {}

// 2. API HANDLER
if (isset($_POST['plugin_action']) && $_POST['plugin_action'] === 'dogear_toggle') {
    error_reporting(0);
    ini_set('display_errors', 0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $id = $_POST['id'];
    $state = $_POST['state']; 
    
    $stmt = $db->prepare("UPDATE logs SET is_dogeared = ? WHERE id = ?");
    $stmt->execute([$state, $id]);
    
    echo json_encode(['status' => 'success']);
    exit;
}

// 3. SETTINGS UI
$plugin_settings_map['DogEar'] = <<<'HTML'
    <div class="setting-item vertical">
        <label class="setting-label">Corner Position</label>
        <div class="setting-desc">Which corner triggers the pin action.</div>
        <div style="display:flex; background:#E5E5EA; border-radius:10px; padding:2px; margin-top:8px;">
            <button onclick="setDogEarPos('left')" id="de-pos-left" style="flex:1; border:none; background:white; border-radius:8px; padding:8px; cursor:pointer; font-size:14px; color:var(--text-primary); box-shadow:0 1px 3px rgba(0,0,0,0.1); transition:all 0.2s;">Top Left</button>
            <button onclick="setDogEarPos('right')" id="de-pos-right" style="flex:1; border:none; background:transparent; border-radius:8px; padding:8px; cursor:pointer; font-size:14px; color:var(--text-secondary); transition:all 0.2s;">Top Right</button>
        </div>
    </div>
HTML;

// 4. JS LOGIC
$plugin_js .= <<<'JS'
// --- DOG EAR PLUGIN JS ---

const dePrefs = {
    pos: localStorage.getItem("cjos_dogear_pos") || "left"
};

// 1. Settings
window.setDogEarPos = function(pos) {
    dePrefs.pos = pos;
    localStorage.setItem("cjos_dogear_pos", pos);
    updateDogEarSettingsUI();
    applyDogEarStyles(); 
};

function updateDogEarSettingsUI() {
    const left = document.getElementById("de-pos-left");
    const right = document.getElementById("de-pos-right");
    if(left && right) {
        if(dePrefs.pos === "left") {
            left.style.background = "var(--selected-bg)"; left.style.color = "var(--selected-text)"; left.style.borderColor = "var(--primary)";
            right.style.background = "var(--btn-bg)"; right.style.color = "var(--text-secondary)"; right.style.borderColor = "var(--border-color)";
        } else {
            right.style.background = "var(--selected-bg)"; right.style.color = "var(--selected-text)"; right.style.borderColor = "var(--primary)";
            left.style.background = "var(--btn-bg)"; left.style.color = "var(--text-secondary)"; left.style.borderColor = "var(--border-color)";
        }
    }
}

// 2. CSS
function applyDogEarStyles() {
    let style = document.getElementById("dogear-style");
    if(!style) {
        style = document.createElement("style");
        style.id = "dogear-style";
        document.head.appendChild(style);
    }
    
    const isRight = dePrefs.pos === "right";
    const posRule = isRight ? "right: 0; border-width: 0 50px 50px 0; border-color: transparent var(--danger) transparent transparent;" : "left: 0; border-width: 50px 50px 0 0; border-color: var(--danger) transparent transparent transparent;";
    
    style.innerHTML = `
        .dog-ear-zone {
            position: absolute;
            top: 0;
            ${isRight ? "right: 0;" : "left: 0;"}
            width: 50px;
            height: 50px;
            z-index: 20;
            cursor: pointer;
            overflow: hidden;
        }
        
        .dog-ear-visual {
            width: 0;
            height: 0;
            border-style: solid;
            ${posRule}
            position: absolute;
            top: 0;
            ${isRight ? "right: 0;" : "left: 0;"}
            opacity: 0;
            transition: opacity 0.2s, transform 0.2s;
            pointer-events: none;
            filter: drop-shadow(0 2px 3px rgba(0,0,0,0.2));
        }
        
        .card.is-dogeared .dog-ear-visual { opacity: 1; }
        .dog-ear-zone:active .dog-ear-visual { opacity: 0.4; }
    `;
}

// 3. Init & Folders Patch
window.addEventListener("load", () => {
    updateDogEarSettingsUI();
    applyDogEarStyles();
    
    if (window.registerCardPlugin) {
        window.registerCardPlugin(setupSingleCard, 70);
    }

    if (!window.cjosVsDataProcessors) window.cjosVsDataProcessors = [];
    window.cjosVsDataProcessors.push((data) => {
        const pinned = data.filter(e => e.is_dogeared == 1);
        const others = data.filter(e => e.is_dogeared != 1);
        return [...pinned, ...others];
    });

    if (window.registerRefreshHook) {
        window.registerRefreshHook(sortPinnedItems);
    }

    setTimeout(() => {
        
        // --- PATCH FOLDERS PLUGIN ---
        if (typeof window.applyFolderFilter === "function") {
            const originalApply = window.applyFolderFilter;
            window.applyFolderFilter = function(fid) {
                originalApply(fid);
                updatePinHeaderVisibility();
            };
        }
        updatePinHeaderVisibility();
    }, 100);
});

function setupSingleCard(card) {
    if(card.querySelector(".dog-ear-zone")) return; 
    
    const checkbox = card.querySelector(".custom-checkbox");
    if(!checkbox) return;
    
    const id = checkbox.getAttribute("data-id");
    const entry = logs.find(l => l.id === id);
    if(!entry) return;

    const zone = document.createElement("div");
    zone.className = "dog-ear-zone";
    zone.title = "Pin to top";
    const visual = document.createElement("div");
    visual.className = "dog-ear-visual";
    zone.appendChild(visual);
    card.appendChild(zone);
    
    // Click handled via InteractionManager delegation
    
    if(entry.is_dogeared == 1) {
        card.classList.add("is-dogeared");
        updateCardTimestamp(card, entry, true);
    }
}

function processDogEars() {
    const cards = document.querySelectorAll(".card");
    cards.forEach(card => setupSingleCard(card));
}

// Logic: Ensure header exists if ANY pinned items exist (even if hidden)
// We control actual visibility in updatePinHeaderVisibility
function sortPinnedItems() {
    const container = document.getElementById("entries-container");
    if(!container) return;
    
    let header = document.getElementById("plugin-dogear-header");
    const cards = Array.from(container.querySelectorAll(".card"));
    // Only sort items that are not currently hidden in a stack or inside an expanded stack view
    const pinned = cards.filter(c => c.classList.contains("is-dogeared") && !c.classList.contains("is-stacked-hidden") && !c.closest('.expanded-stack-wrapper'));
    
    if (pinned.length > 0) {
        const stacksWrap = document.getElementById("stacks-section-wrapper");
        if (!header) {
            header = document.createElement("div");
            header.className = "section-header";
            header.id = "plugin-dogear-header";
            header.innerHTML = "📌 Pinned";
            
            // STACKS AWARE PREPEND
            if (stacksWrap && stacksWrap.nextSibling) {
                container.insertBefore(header, stacksWrap.nextSibling);
            } else if (stacksWrap) {
                container.appendChild(header);
            } else {
                container.prepend(header);
            }
        } else {
            // STACKS AWARE PREPEND
            if (stacksWrap && stacksWrap.nextSibling && header.previousSibling !== stacksWrap) {
                container.insertBefore(header, stacksWrap.nextSibling);
            } else if (!stacksWrap) {
                container.prepend(header);
            }
        }
        // Move items
        for (let i = pinned.length - 1; i >= 0; i--) {
            header.after(pinned[i]);
        }
    }
    
    updatePinHeaderVisibility();

    // Cleanup: Remove empty date headers left behind when items are moved to Pinned section
    container.querySelectorAll(".section-header").forEach(h => {
        if (h.id === "plugin-dogear-header") return;
        let next = h.nextElementSibling;
        if (!next || next.classList.contains("section-header") || next.id === "vs-sentinel") {
            h.remove();
        }
    });
}

// NEW: Check which pinned items are actually visible (due to Folders)
// and toggle the header accordingly.
function updatePinHeaderVisibility() {
    const header = document.getElementById("plugin-dogear-header");
    if (!header) return; 
    
    const pinned = document.querySelectorAll(".card.is-dogeared");
    let visibleCount = 0;
    
    pinned.forEach(card => {
        // Check if hidden by folders OR hidden by stacks
        if (card.style.display !== "none" && !card.classList.contains("is-stacked-hidden")) visibleCount++;
    });
    
    // Only show header if there are visible items beneath it
    if (visibleCount > 0) {
        header.style.display = "block";
    } else {
        header.style.display = "none";
    }
}

function restoreCardPosition(card) {
    const container = document.getElementById("entries-container");
    const currentId = card.querySelector(".custom-checkbox").getAttribute("data-id");
    const cards = Array.from(container.querySelectorAll(".card:not(.is-dogeared)"));
    const targets = cards.filter(c => c !== card);

    let inserted = false;
    for (let i = 0; i < targets.length; i++) {
        const targetId = targets[i].querySelector(".custom-checkbox").getAttribute("data-id");
        if (targetId < currentId) {
            container.insertBefore(card, targets[i]);
            inserted = true;
            break;
        }
    }
    if (!inserted) container.appendChild(card);
}

function toggleDogEar(entry, card) {
    const isPinned = card.classList.contains("is-dogeared");
    const newState = isPinned ? 0 : 1;
    
    // 1. Optimistic UI
    if (newState === 1) {
        // PINNING
        card.classList.add("is-dogeared");
        entry.is_dogeared = 1;
        updateCardTimestamp(card, entry, true);
        
        // Only trigger list sort if we aren't inside an expanded stack
        if (!card.closest('.expanded-stack-wrapper')) sortPinnedItems(); 
        
        // Feedback: Ripple & Scroll
        card.classList.add("ai-just-finished");
        
        // Manual Scroll Fix: Target the specific scroll container to prevent app-frame shifting
        const container = document.getElementById("main-scroll");
        if (container) {
            const containerRect = container.getBoundingClientRect();
            const cardRect = card.getBoundingClientRect();
            const scrollTarget = container.scrollTop + (cardRect.top - containerRect.top) - (containerRect.height / 2) + (cardRect.height / 2);
            container.scrollTo({ top: scrollTarget, behavior: "smooth" });
        }
        
        setTimeout(() => card.classList.remove("ai-just-finished"), 2000);
        
    } else {
        // UNPINNING
        card.classList.remove("is-dogeared");
        entry.is_dogeared = 0;
        updateCardTimestamp(card, entry, false);
        
        // Clean up header if empty
        const container = document.getElementById("entries-container");
        const remaining = container.querySelectorAll(".card.is-dogeared").length;
        
        if(remaining === 0) {
            const header = document.getElementById("plugin-dogear-header");
            if(header) header.remove();
        }
        
        if (!card.closest('.expanded-stack-wrapper')) {
            restoreCardPosition(card);
            updatePinHeaderVisibility();
        }

        // Feedback: Ripple & Scroll
        card.classList.add("ai-just-finished");

        const containerScroll = document.getElementById("main-scroll");
        if (containerScroll) {
            const containerRect = containerScroll.getBoundingClientRect();
            const cardRect = card.getBoundingClientRect();
            const scrollTarget = containerScroll.scrollTop + (cardRect.top - containerRect.top) - (containerRect.height / 2) + (cardRect.height / 2);
            containerScroll.scrollTo({ top: scrollTarget, behavior: "smooth" });
        }

        setTimeout(() => card.classList.remove("ai-just-finished"), 2000);
    }
    
    // 2. Background Save
    window.sui.api("dogear_toggle", { id: entry.id, state: newState }, { toast: false });
}

function updateCardTimestamp(card, entry, isFull) {
    const badge = card.querySelector(".time-badge");
    if(!badge) return;
    
    if(isFull) {
        badge.textContent = entry.date_display || entry.id;
        badge.style.fontWeight = "700";
        badge.style.color = "var(--danger)";
    } else {
        const parts = entry.date_display.split(" ");
        if(parts.length > 1) {
            let [h, m] = parts[1].split(":"); 
            h = parseInt(h); 
            const ampm = h >= 12 ? "PM" : "AM"; 
            h = h % 12; h = h ? h : 12; 
            badge.textContent = `${h}:${m} ${ampm}`;
        }
        badge.style.fontWeight = "600";
        badge.style.color = "var(--text-secondary)";
    }
}
JS;

$plugin_js .= <<<'JS'
// --- DOG EAR INTERACTION MANAGER ADAPTER ---
(function() {
    window.addEventListener('load', () => {
        if (window.InteractionManager) {
            InteractionManager.subscribe({
                plugin: 'DogEar',
                event: 'onDogEarTap',
                priority: 70,
                handler: ({ entry, card, vibrate }) => {
                    if (entry && card) {
                        toggleDogEar(entry, card);
                        vibrate('light');
                    }
                }
            });
        }
    });
})();
JS;
?>