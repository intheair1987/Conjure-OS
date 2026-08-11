// apps/TravelPacker/js/app.js

// Toggle custom styled trip select dropdown
function tpToggleTripDropdown(e) {
    e.stopPropagation();
    const dropdown = document.getElementById('tpTripDropdown');
    if (dropdown) dropdown.classList.toggle('active');
}

// Global window dismiss listener for active overlays
document.addEventListener('click', (e) => {
    const dropdown = document.getElementById('tpTripDropdown');
    if (dropdown && !e.target.closest('#tpTripSelectWrapper')) {
        dropdown.classList.remove('active');
    }
    
    const addCatDropdown = document.getElementById('tpAddCategoryDropdown');
    if (addCatDropdown && !e.target.closest('#tpAddCategoryWrapper')) {
        addCatDropdown.classList.remove('active');
    }
    
    // Dismiss context menus
    if (!e.target.closest('.tp-context-menu')) {
        tpCloseContextMenu();
        if (typeof tpCloseTripContextMenu === 'function') tpCloseTripContextMenu();
        if (typeof tpClosePriorityContextMenu === 'function') tpClosePriorityContextMenu();
        if (typeof tpCloseCatContextMenu === 'function') tpCloseCatContextMenu();
    }
});

// --- Long Press & Context Menu Engine ---
let tpPressTimer = null;
let tpPressItemId = null;
let tpIsPressing = false;
let tpStartX = 0, tpStartY = 0;
let tpActiveContextId = null;

function tpStartPress(e, itemId) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    tpIsPressing = true;
    tpPressItemId = itemId;
    tpStartX = e.clientX;
    tpStartY = e.clientY;
    
    tpPressTimer = setTimeout(() => {
        if (tpIsPressing) {
            tpIsPressing = false;
            if (navigator.vibrate) navigator.vibrate(20);
            tpShowContextMenu(e.clientX, e.clientY, itemId);
        }
    }, 500); // 500ms long press threshold
}

function tpMovePress(e) {
    if (!tpIsPressing) return;
    // Cancel if finger moves (scrolling)
    if (Math.abs(e.clientX - tpStartX) > 10 || Math.abs(e.clientY - tpStartY) > 10) {
        tpCancelPress();
    }
}

function tpEndPress(e) {
    tpCancelPress();
}

function tpCancelPress() {
    tpIsPressing = false;
    if (tpPressTimer) {
        clearTimeout(tpPressTimer);
        tpPressTimer = null;
    }
}

function tpShowContextMenu(x, y, itemId) {
    tpActiveContextId = itemId;
    const menu = document.getElementById('tpContextMenu');
    if (!menu) return;
    
    menu.style.display = 'flex';
    
    // Boundary collision detection
    const rect = menu.getBoundingClientRect();
    let posX = x;
    let posY = y;
    
    if (posX + rect.width > window.innerWidth) posX = window.innerWidth - rect.width - 10;
    if (posY + rect.height > window.innerHeight) posY = window.innerHeight - rect.height - 10;
    
    menu.style.left = posX + 'px';
    menu.style.top = posY + 'px';
    
    void menu.offsetWidth; // Force reflow
    menu.classList.add('active');
}

function tpCloseContextMenu() {
    const menu = document.getElementById('tpContextMenu');
    if (menu && menu.classList.contains('active')) {
        menu.classList.remove('active');
        setTimeout(() => { menu.style.display = 'none'; }, 150);
    }
}

function tpHandleEdit() {
    tpCloseContextMenu();
    if (!tpActiveContextId) return;
    
    const row = document.getElementById(`item-row-${tpActiveContextId}`);
    if (!row) return;
    
    // Extract data attributes
    const name = row.getAttribute('data-name');
    const cat = row.getAttribute('data-cat');
    const qty = row.getAttribute('data-qty');
    const note = row.getAttribute('data-note');
    const needed = row.getAttribute('data-needed');
    
    // Populate form
    document.getElementById('tpEditId').value = tpActiveContextId;
    document.getElementById('tpNewName').value = name;
    document.getElementById('tpNewQty').value = qty;
    document.getElementById('tpNewNote').value = note;
    document.getElementById('tpNewNeeded').checked = (needed === '1');
    
    // Populate custom styled category selector
    const opt = document.querySelector(`#tpAddCategoryDropdown .tp-select-option[data-value="${cat}"]`);
    if (opt) {
        const catName = opt.querySelector('span').textContent;
        tpSelectAddCategory(cat, catName);
    }
    
    // Rebrand modal UI
    document.getElementById('tpAddModalTitle').textContent = 'Edit Packing Item';
    document.getElementById('tpAddModalSubmit').textContent = 'Save Changes';
    
    document.getElementById('tpAddModal').classList.add('active');
}

function tpHandleContextHide() {
    tpCloseContextMenu();
    if (tpActiveContextId) {
        tpToggleItemHidden(tpActiveContextId, 1);
    }
}

function tpHandleContextDelete() {
    tpCloseContextMenu();
    if (tpActiveContextId) {
        tpDeleteItem(tpActiveContextId);
    }
}
// --- End Context Menu Engine ---

// Toggle Hidden Items expandable sub-sections
function tpToggleHiddenSection(toggleBtn) {
    const container = toggleBtn.nextElementSibling;
    const svg = toggleBtn.querySelector('svg');
    const textSpan = toggleBtn.querySelector('.toggle-text');
    
    if (container.style.display === 'none') {
        container.style.display = 'block';
        svg.style.transform = 'rotate(180deg)';
        if (textSpan) textSpan.textContent = 'Hide';
    } else {
        container.style.display = 'none';
        svg.style.transform = '';
        if (textSpan) textSpan.textContent = 'Show';
    }
}

function tpToggleItemHidden(itemId, isHidden) {
    if (navigator.vibrate) {
        navigator.vibrate(12);
    }
    
    const row = document.getElementById(`item-row-${itemId}`);
    if (row) {
        row.style.opacity = '0';
        row.style.transform = 'scale(0.95)';
    }
    
    const formData = new FormData();
    formData.append('id', itemId);
    formData.append('is_hidden', isHidden);
    
    fetch('index.php?action=toggle_hidden', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            tpUpdateProgressUI(data.pct);
            tpUpdatePriorityBanner(data.priority_items);
            
            if (row) {
                const group = row.closest('.category-group');
                const badge = group.querySelector('.category-group-badge');
                const hiddenSection = group.querySelector('.hidden-items-section');
                const hiddenContainer = group.querySelector('.hidden-items-container');
                const hiddenCountEl = group.querySelector('.hidden-count');
                
                setTimeout(() => {
                    if (isHidden === 1) {
                        // Migrate row node structurally to the hidden items list
                        tpUpdateRowDOMToHidden(row);
                        hiddenContainer.appendChild(row);
                        
                        // Update badge totals
                        badge.textContent = (parseInt(badge.textContent) - 1) + " items";
                        hiddenCountEl.textContent = parseInt(hiddenCountEl.textContent) + 1;
                        hiddenSection.style.display = 'block';
                    } else {
                        // Migrate row node structurally back to the main checklist
                        tpUpdateRowDOMToVisible(row);
                        const list = group.querySelector('.item-list');
                        list.appendChild(row);
                        
                        // Update badge totals
                        badge.textContent = (parseInt(badge.textContent) + 1) + " items";
                        const newCount = parseInt(hiddenCountEl.textContent) - 1;
                        hiddenCountEl.textContent = newCount;
                        if (newCount === 0) {
                            hiddenSection.style.display = 'none';
                            hiddenContainer.style.display = 'none';
                            const toggleSvg = hiddenSection.querySelector('.hidden-section-toggle svg');
                            if (toggleSvg) toggleSvg.style.transform = '';
                            const toggleText = hiddenSection.querySelector('.toggle-text');
                            if (toggleText) toggleText.textContent = 'Show';
                        }
                    }
                    
                    // Re-apply visibility filters after structural DOM node migration finishes
                    tpApplyVisibilityFilters();
                    
                    // Trigger dynamic fade-in
                    void row.offsetWidth;
                    row.style.opacity = '1';
                    row.style.transform = 'scale(1)';
                }, 200);
            }
        } else {
            if (row) {
                row.style.opacity = '1';
                row.style.transform = 'scale(1)';
            }
            alert("Error: " + data.error);
        }
    });
}

function tpUpdateRowDOMToHidden(row) {
    row.classList.add('hidden-item');
    row.querySelector('.item-left').style.opacity = '0.5';
    const checkbox = row.querySelector('.item-checkbox-wrapper');
    if (checkbox) checkbox.style.display = 'none';
    
    // Add balanced spacing padding where checkbox sat
    const details = row.querySelector('.item-details');
    if (details) details.style.paddingLeft = '12px';
    
    const starBtn = row.querySelector('.star-btn');
    if (starBtn) starBtn.style.display = 'none';
    
    const hideBtn = row.querySelector('[title="Hide Item from Checklist"]');
    if (hideBtn) {
        hideBtn.setAttribute('title', 'Unhide Item');
        hideBtn.setAttribute('onclick', `tpToggleItemHidden(${row.getAttribute('data-id')}, 0)`);
        hideBtn.style.color = 'var(--primary-accent)';
        hideBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye-off"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.52 13.16 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>`;
    }
}

function tpUpdateRowDOMToVisible(row) {
    row.classList.remove('hidden-item');
    row.querySelector('.item-left').style.opacity = '';
    const checkbox = row.querySelector('.item-checkbox-wrapper');
    if (checkbox) checkbox.style.display = 'block';
    
    // Remove padding to restore original spacing
    const details = row.querySelector('.item-details');
    if (details) details.style.paddingLeft = '';
    
    const starBtn = row.querySelector('.star-btn');
    if (starBtn) starBtn.style.display = 'block';
    
    const hideBtn = row.querySelector('[title="Unhide Item"]');
    if (hideBtn) {
        hideBtn.setAttribute('title', 'Hide Item from Checklist');
        hideBtn.setAttribute('onclick', `tpToggleItemHidden(${row.getAttribute('data-id')}, 1)`);
        hideBtn.style.color = '';
        hideBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>`;
    }
}

// Trip Modal Interactivity
function tpOpenTripModal() {
    document.getElementById('tpTripForm').reset();
    document.getElementById('tpEditTripId').value = '';
    
    document.getElementById('tpTripModalTitle').textContent = 'Create New Trip List';
    document.getElementById('tpTripModalSubmit').textContent = 'Create Trip';
    
    // Check all categories by default for a new trip
    document.querySelectorAll('.tp-trip-cat-check').forEach(cb => {
        cb.checked = true;
    });
    
    document.getElementById('tpTripModal').classList.add('active');
    const dropdown = document.getElementById('tpTripDropdown');
    if (dropdown) dropdown.classList.remove('active');
}

function tpCloseTripModal(e) {
    if (!e || e.target === document.getElementById('tpTripModal')) {
        document.getElementById('tpTripModal').classList.remove('active');
    }
}

function tpOpenTripEditModalConfig(id, name, activeCats) {
    document.getElementById('tpTripForm').reset();
    document.getElementById('tpEditTripId').value = id;
    document.getElementById('tpTripName').value = name;
    
    document.getElementById('tpTripModalTitle').textContent = 'Edit Trip';
    document.getElementById('tpTripModalSubmit').textContent = 'Save Changes';
    
    // Uncheck all, then check only active ones
    document.querySelectorAll('.tp-trip-cat-check').forEach(cb => {
        cb.checked = activeCats.includes(parseInt(cb.value)) || activeCats.includes(cb.value);
    });
    
    document.getElementById('tpTripModal').classList.add('active');
    const dropdown = document.getElementById('tpTripDropdown');
    if (dropdown) dropdown.classList.remove('active');
}

function tpHandleTripEditCurrent() {
    const container = document.getElementById('tpCurrentTripData');
    if (!container) return;
    const id = container.getAttribute('data-id');
    const name = container.getAttribute('data-name');
    const cats = JSON.parse(container.getAttribute('data-categories') || '[]');
    tpOpenTripEditModalConfig(id, name, cats);
}

// --- Category Pill Long Press & Context Menu Engine ---
let tpCatPressTimer = null;
let tpCatIsPressing = false;
let tpActiveCatContextId = null;

function tpStartCatPress(e, catId) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    tpCatIsPressing = true;
    tpStartX = e.clientX;
    tpStartY = e.clientY;
    
    tpCatPressTimer = setTimeout(() => {
        if (tpCatIsPressing) {
            tpCatIsPressing = false;
            window.tpPreventClick = true; // Lock click focus
            if (navigator.vibrate) navigator.vibrate(20);
            tpShowCatContextMenu(e.clientX, e.clientY, catId);
        }
    }, 500);
}

function tpMoveCatPress(e) {
    if (!tpCatIsPressing) return;
    if (Math.abs(e.clientX - tpStartX) > 10 || Math.abs(e.clientY - tpStartY) > 10) {
        tpCancelCatPress();
    }
}

function tpEndCatPress(e) {
    tpCancelCatPress();
}

function tpCancelCatPress() {
    tpCatIsPressing = false;
    if (tpCatPressTimer) {
        clearTimeout(tpCatPressTimer);
        tpCatPressTimer = null;
    }
}

function tpShowCatContextMenu(x, y, catId) {
    tpActiveCatContextId = catId;
    const menu = document.getElementById('tpCatContextMenu');
    if (!menu) return;
    
    window.tpPreventClick = true;
    menu.style.display = 'flex';
    
    const rect = menu.getBoundingClientRect();
    let posX = x;
    let posY = y;
    
    if (posX + rect.width > window.innerWidth) posX = window.innerWidth - rect.width - 10;
    if (posY + rect.height > window.innerHeight) posY = window.innerHeight - rect.height - 10;
    
    menu.style.left = posX + 'px';
    menu.style.top = posY + 'px';
    
    void menu.offsetWidth;
    menu.classList.add('active');
}

function tpCloseCatContextMenu() {
    const menu = document.getElementById('tpCatContextMenu');
    if (menu && menu.classList.contains('active')) {
        menu.classList.remove('active');
        setTimeout(() => { menu.style.display = 'none'; }, 150);
        setTimeout(() => { window.tpPreventClick = false; }, 100);
    }
}

function tpHandleCatRemove() {
    tpCloseCatContextMenu();
    if (!tpActiveCatContextId) return;
    
    const formData = new FormData();
    formData.append('id', tpActiveCatContextId);
    
    fetch('index.php?action=remove_category_from_trip', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else alert("Error: " + data.error);
    });
}

let tpActiveCategoryDeleteId = null;

function tpHandleCatDelete() {
    tpCloseCatContextMenu();
    if (!tpActiveCatContextId) return;
        
    tpActiveCategoryDeleteId = tpActiveCatContextId;
    document.getElementById('tpCategoryDeleteModal').classList.add('active');
}

function tpCloseCategoryDeleteModal(e) {
    if (!e || e.target === document.getElementById('tpCategoryDeleteModal')) {
        document.getElementById('tpCategoryDeleteModal').classList.remove('active');
    }
}

function tpExecuteCategoryDelete() {
    if (!tpActiveCategoryDeleteId) return;
        
    const formData = new FormData();
    formData.append('id', tpActiveCategoryDeleteId);
        
    fetch('index.php?action=delete_category', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            tpCloseCategoryDeleteModal(null);
            location.reload();
        } else {
            tpShowToast("Error: " + data.error);
        }
    });
}// --- End Category Pill Context Menu Engine ---

// --- Priority Pill Long Press & Context Menu Engine ---
let tpPillPressTimer = null;
let tpPillIsPressing = false;
let tpActivePriorityContextId = null;

function tpStartPillPress(e, itemId) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    tpPillIsPressing = true;
    tpStartX = e.clientX;
    tpStartY = e.clientY;
    
    tpPillPressTimer = setTimeout(() => {
        if (tpPillIsPressing) {
            tpPillIsPressing = false;
            window.tpPreventClick = true; // Lock click focus
            if (navigator.vibrate) navigator.vibrate(20);
            tpShowPriorityContextMenu(e.clientX, e.clientY, itemId);
            setTimeout(() => { window.tpPreventClick = false; }, 300);
        }
    }, 500);
}

function tpMovePillPress(e) {
    if (!tpPillIsPressing) return;
    if (Math.abs(e.clientX - tpStartX) > 10 || Math.abs(e.clientY - tpStartY) > 10) {
        tpCancelPillPress();
    }
}

function tpEndPillPress(e) {
    tpCancelPillPress();
}

function tpCancelPillPress() {
    tpPillIsPressing = false;
    if (tpPillPressTimer) {
        clearTimeout(tpPillPressTimer);
        tpPillPressTimer = null;
    }
}

function tpShowPriorityContextMenu(x, y, itemId) {
    tpActivePriorityContextId = itemId;
    const menu = document.getElementById('tpPriorityContextMenu');
    if (!menu) return;
    
    window.tpPreventClick = true;
    menu.style.display = 'flex';
    
    const rect = menu.getBoundingClientRect();
    let posX = x;
    let posY = y;
    
    if (posX + rect.width > window.innerWidth) posX = window.innerWidth - rect.width - 10;
    if (posY + rect.height > window.innerHeight) posY = window.innerHeight - rect.height - 10;
    
    menu.style.left = posX + 'px';
    menu.style.top = posY + 'px';
    
    void menu.offsetWidth;
    menu.classList.add('active');
}

function tpClosePriorityContextMenu() {
    const menu = document.getElementById('tpPriorityContextMenu');
    if (menu && menu.classList.contains('active')) {
        menu.classList.remove('active');
        setTimeout(() => { menu.style.display = 'none'; }, 150);
        setTimeout(() => { window.tpPreventClick = false; }, 100);
    }
}

function tpHandlePriorityJump() {
    tpClosePriorityContextMenu();
    if (tpActivePriorityContextId) {
        tpFocusItem(tpActivePriorityContextId);
    }
}

function tpHandlePriorityPack() {
    tpClosePriorityContextMenu();
    if (tpActivePriorityContextId) {
        // Pack item & toggle state
        tpToggleItemPacked(tpActivePriorityContextId, true);
        
        // Animate fade-out of priority pill from current view directly
        const pill = document.getElementById(`priority-pill-${tpActivePriorityContextId}`);
        if (pill) {
            pill.style.opacity = '0';
            pill.style.transform = 'scale(0.8)';
            setTimeout(() => {
                pill.remove();
                // If no more items in priority lists, hide the banner
                const banner = document.querySelector('.priority-banner');
                const list = document.querySelector('.priority-items-list');
                if (list && list.children.length === 0 && banner) {
                    banner.style.display = 'none';
                }
            }, 200);
        }
    }
}

function tpHandlePriorityUnstar() {
    tpClosePriorityContextMenu();
    if (tpActivePriorityContextId) {
        tpToggleItemNeeded(tpActivePriorityContextId);
        
        // Animate fade-out of priority pill
        const pill = document.getElementById(`priority-pill-${tpActivePriorityContextId}`);
        if (pill) {
            pill.style.opacity = '0';
            pill.style.transform = 'scale(0.8)';
            setTimeout(() => {
                pill.remove();
                const banner = document.querySelector('.priority-banner');
                const list = document.querySelector('.priority-items-list');
                if (list && list.children.length === 0 && banner) {
                    banner.style.display = 'none';
                }
            }, 200);
        }
    }
}
// --- End Priority Pill Context Menu Engine ---

// --- Trip Long Press & Context Menu Engine ---
let tpTripPressTimer = null;
let tpTripIsPressing = false;
let tpActiveTripContextId = null;
window.tpPreventClick = false;

function tpStartTripPress(e, tripId) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    tpTripIsPressing = true;
    tpStartX = e.clientX;
    tpStartY = e.clientY;
    
    tpTripPressTimer = setTimeout(() => {
        if (tpTripIsPressing) {
            tpTripIsPressing = false;
            window.tpPreventClick = true; // Lock the click navigation
            if (navigator.vibrate) navigator.vibrate(20);
            tpShowTripContextMenu(e.clientX, e.clientY, tripId);
        }
    }, 500);
}

function tpMoveTripPress(e) {
    if (!tpTripIsPressing) return;
    if (Math.abs(e.clientX - tpStartX) > 10 || Math.abs(e.clientY - tpStartY) > 10) {
        tpCancelTripPress();
    }
}

function tpEndTripPress(e) {
    tpCancelTripPress();
}

function tpCancelTripPress() {
    tpTripIsPressing = false;
    if (tpTripPressTimer) {
        clearTimeout(tpTripPressTimer);
        tpTripPressTimer = null;
    }
}

function tpShowTripContextMenu(x, y, tripId) {
    tpActiveTripContextId = tripId;
    const menu = document.getElementById('tpTripContextMenu');
    if (!menu) return;
    
    window.tpPreventClick = true; // Enforce global navigation lock
    menu.style.display = 'flex';
    
    const rect = menu.getBoundingClientRect();
    let posX = x;
    let posY = y;
    
    if (posX + rect.width > window.innerWidth) posX = window.innerWidth - rect.width - 10;
    if (posY + rect.height > window.innerHeight) posY = window.innerHeight - rect.height - 10;
    
    menu.style.left = posX + 'px';
    menu.style.top = posY + 'px';
    
    void menu.offsetWidth;
    menu.classList.add('active');
}

function tpCloseTripContextMenu() {
    const menu = document.getElementById('tpTripContextMenu');
    if (menu && menu.classList.contains('active')) {
        menu.classList.remove('active');
        setTimeout(() => { menu.style.display = 'none'; }, 150);
        
        // Retain lock briefly to consume the tap that triggered the dismissal
        setTimeout(() => {
            window.tpPreventClick = false;
        }, 100);
    }
}

function tpHandleTripEdit() {
    tpCloseTripContextMenu();
    if (!tpActiveTripContextId) return;
    
    const card = document.getElementById(`trip-card-${tpActiveTripContextId}`);
    if (!card) return;
    
    const name = card.getAttribute('data-name');
    const cats = JSON.parse(card.getAttribute('data-categories') || '[]');
    tpOpenTripEditModalConfig(tpActiveTripContextId, name, cats);
}

function tpHandleTripDelete() {
    tpCloseTripContextMenu();
    if (tpActiveTripContextId) {
        tpDeleteCurrentTrip(tpActiveTripContextId);
    }
}

function tpHandleTripDuplicate() {
    tpCloseTripContextMenu();
    if (!tpActiveTripContextId) return;
    
    const card = document.getElementById(`trip-card-${tpActiveTripContextId}`);
    if (!card) return;
    
    const currentName = card.getAttribute('data-name');
    
    // Populate form fields & show custom overlay drawer
    document.getElementById('tpDuplicateSourceId').value = tpActiveTripContextId;
    document.getElementById('tpDuplicateTripName').value = currentName + " (Copy)";
    
    document.getElementById('tpTripDuplicateModal').classList.add('active');
}

function tpCloseTripDuplicateModal(e) {
    if (!e || e.target === document.getElementById('tpTripDuplicateModal')) {
        document.getElementById('tpTripDuplicateModal').classList.remove('active');
    }
}

function tpSubmitTripDuplicate(event) {
    event.preventDefault();
    const sourceId = document.getElementById('tpDuplicateSourceId').value;
    const name = document.getElementById('tpDuplicateTripName').value.trim();
    
    if (name === "") {
        tpShowToast("Trip name cannot be empty.");
        return;
    }
    
    const formData = new FormData();
    formData.append('id', sourceId);
    formData.append('name', name);
    
    fetch('index.php?action=duplicate_trip', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            tpCloseTripDuplicateModal(null);
            document.getElementById('tpTripDuplicateForm').reset();
            location.reload();
        } else {
            tpShowToast("Error: " + data.error);
        }
    });
}
// --- End Trip Context Menu Engine ---

// Trip Edit/Delete Management
let tpActiveTripDeleteId = null;

function tpDeleteCurrentTrip(tripId) {
    tpActiveTripDeleteId = tripId;
    document.getElementById('tpTripDeleteConfirmModal').classList.add('active');
}

function tpCloseTripDeleteConfirmModal(e) {
    if (!e || e.target === document.getElementById('tpTripDeleteConfirmModal')) {
        document.getElementById('tpTripDeleteConfirmModal').classList.remove('active');
    }
}

function tpExecuteTripDelete() {
    if (!tpActiveTripDeleteId) return;
    
    const formData = new FormData();
    formData.append('id', tpActiveTripDeleteId);
    
    fetch('index.php?action=delete_trip', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            tpCloseTripDeleteConfirmModal(null);
            // Redirect cleanly back to the home dashboard view
            window.location.href = 'index.php?view=home';
        } else {
            tpShowToast("Error: " + data.error);
        }
    });
}

// --- Horizontal Swipe Navigation & Sequence Helpers ---
let tpTouchStartX = 0;
let tpTouchStartY = 0;
let tpTouchEndX = 0;
let tpTouchEndY = 0;

document.addEventListener('touchstart', e => {
    // Avoid hijacking swipe events from interactive overlays, drawers, and the category scroller itself
    if (e.target.closest('.bottom-sheet') || e.target.closest('.tp-context-menu') || e.target.closest('.tp-select-dropdown') || e.target.closest('.category-scroller')) {
        return;
    }
    tpTouchStartX = e.changedTouches[0].screenX;
    tpTouchStartY = e.changedTouches[0].screenY;
}, { passive: true });

document.addEventListener('touchend', e => {
    if (e.target.closest('.bottom-sheet') || e.target.closest('.tp-context-menu') || e.target.closest('.tp-select-dropdown') || e.target.closest('.category-scroller')) {
        return;
    }
    tpTouchEndX = e.changedTouches[0].screenX;
    tpTouchEndY = e.changedTouches[0].screenY;
    tpHandleSwipeGesture();
}, { passive: true });

function tpHandleSwipeGesture() {
    const diffX = tpTouchEndX - tpTouchStartX;
    const diffY = tpTouchEndY - tpTouchStartY;
    
    const absX = Math.abs(diffX);
    const absY = Math.abs(diffY);
    
    // Breezy 40px threshold combined with a relative slope filter (X must be 1.4x dominant over Y).
    // This allows curved thumb gestures to register easily while filtering out vertical page scrolling.
    if (absX > 40 && absX > absY * 1.4) {
        const seq = tpGetCategorySequence();
        const active = tpGetActiveCategory();
        let index = seq.indexOf(active);
        
        if (index === -1) return;
        
        if (diffX < 0) {
            // Swipe Left -> cycle to next list
            index = (index + 1) % seq.length;
            tpFilterCategory(seq[index], true); // Force bypass scroll lock
        } else {
            // Swipe Right -> cycle to previous list
            index = (index - 1 + seq.length) % seq.length;
            tpFilterCategory(seq[index], true); // Force bypass scroll lock
        }
    }
}

function tpGetCategorySequence() {
    const pills = Array.from(document.querySelectorAll('.category-pill[data-pill-id], .category-pill:first-child'));
    return pills
        .filter(p => p.offsetWidth > 0) // Only cycle through category tabs that are currently visible on screen
        .map(p => p.getAttribute('data-pill-id') || 'all');
}

function tpGetActiveCategory() {
    const activePill = document.querySelector('.category-pill.active');
    if (!activePill) return 'all';
    return activePill.getAttribute('data-pill-id') || 'all';
}

// --- Dynamic Sticky Header Height Tracker ---
function tpUpdateHeaderHeight() {
    const header = document.querySelector('.sticky-header');
    if (header) {
        document.documentElement.style.setProperty('--sticky-header-height', header.offsetHeight + 'px');
    }
}
window.addEventListener('load', tpUpdateHeaderHeight);
window.addEventListener('resize', tpUpdateHeaderHeight);
tpUpdateHeaderHeight(); // Immediate invocation

// --- Scroll & Drag Lock for Category Pills Scroller ---
let tpPillScrollTimeout = null;
window.tpIsScrollingPills = false;

const tpScrollerEl = document.querySelector('.category-scroller');
if (tpScrollerEl) {
    const lockScroll = () => {
        window.tpIsScrollingPills = true;
        if (tpPillScrollTimeout) clearTimeout(tpPillScrollTimeout);
        tpPillScrollTimeout = setTimeout(() => {
            window.tpIsScrollingPills = false;
        }, 180); // 180ms buffer for slower scrolling inertia release
    };
    
    tpScrollerEl.addEventListener('scroll', lockScroll, { passive: true });
    tpScrollerEl.addEventListener('touchmove', lockScroll, { passive: true });
}

// Isolate and intercept touch gesture momentum to prevent scroll chaining to parent drawers
document.addEventListener('touchmove', (e) => {
    if (e.target.closest('.tp-select-dropdown')) {
        e.stopPropagation();
    }
}, { passive: true });

// Category Management
function tpSelectCategoryIcon(iconName, btnEl) {
    if (navigator.vibrate) navigator.vibrate(10);
    document.getElementById('tpCatIcon').value = iconName;
    
    // Clear all active highlights
    document.querySelectorAll('.icon-picker-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Highlight the selected icon
    btnEl.classList.add('active');
}

function tpOpenCategoryModal() {
    document.getElementById('tpCategoryForm').reset();
    
    // Reset icon picker grid to default (package)
    const defaultIcon = document.querySelector('.icon-picker-btn[data-icon="package"]');
    if (defaultIcon) {
        document.getElementById('tpCatIcon').value = 'package';
        document.querySelectorAll('.icon-picker-btn').forEach(btn => btn.classList.remove('active'));
        defaultIcon.classList.add('active');
    }
    
    document.getElementById('tpCategoryModal').classList.add('active');
}

function tpCloseCategoryModal(e) {
    if (!e || e.target === document.getElementById('tpCategoryModal')) {
        document.getElementById('tpCategoryModal').classList.remove('active');
    }
}

function tpSubmitCategory(event) {
    event.preventDefault();
    const name = document.getElementById('tpCatName').value;
    const icon = document.getElementById('tpCatIcon').value;
    const enableCurrent = document.getElementById('tpCatEnableCurrent').checked ? 1 : 0;
    
    const formData = new FormData();
    formData.append('name', name);
    formData.append('icon', icon);
    formData.append('enable_current', enableCurrent);
    
    fetch('index.php?action=add_category', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            tpCloseCategoryModal(null);
            document.getElementById('tpCategoryForm').reset();
            location.reload();
        } else {
            tpShowToast("Error: " + data.error);
        }
    });
}

function tpSubmitTrip(event) {
    event.preventDefault();
    const id = document.getElementById('tpEditTripId').value;
    const name = document.getElementById('tpTripName').value;
    
    // Extract selected categories
    const checkedCats = [];
    document.querySelectorAll('.tp-trip-cat-check:checked').forEach(cb => {
        checkedCats.push(cb.value);
    });
    
    const formData = new FormData();
    formData.append('name', name);
    formData.append('categories', JSON.stringify(checkedCats));
    
    let endpoint = 'index.php?action=add_trip';
    if (id) {
        formData.append('id', id);
        endpoint = 'index.php?action=edit_trip';
    }
    
    fetch(endpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            tpCloseTripModal(null);
            document.getElementById('tpTripForm').reset();
            location.reload();
        } else {
            tpShowToast("Error: " + data.error);
        }
    });
}

// Real-time Star/Priority Toggling
function tpToggleItemNeeded(itemId) {
    if (navigator.vibrate) {
        navigator.vibrate(10);
    }
    
    const btn = document.getElementById(`star-btn-${itemId}`);
    const svg = btn ? btn.querySelector('svg') : null;
    
    const formData = new FormData();
    formData.append('id', itemId);
    
    fetch('index.php?action=toggle_needed', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (data.is_needed) {
                if (btn) btn.classList.add('active');
                if (svg) svg.setAttribute('fill', 'currentColor');
            } else {
                if (btn) btn.classList.remove('active');
                if (svg) svg.setAttribute('fill', 'none');
            }
            tpUpdatePriorityBanner(data.priority_items);
        }
    });
}

// Real-time Category Filtering Transitions & Auto-Scrolling Pills
window.tpActiveCategory = 'all';
window.tpHidePacked = true;

// Toast Notification Engine
function tpShowToast(message) {
    let toast = document.getElementById('tpToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'tpToast';
        toast.className = 'tp-toast';
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.classList.add('active');
    
    if (window.tpToastTimeout) clearTimeout(window.tpToastTimeout);
    window.tpToastTimeout = setTimeout(() => {
        toast.classList.remove('active');
    }, 2000);
}

function tpToggleUnpackedFilter() {
    if (navigator.vibrate) navigator.vibrate(10);
    window.tpHidePacked = !window.tpHidePacked;
    
    const btn = document.getElementById('tpFilterToggleBtn');
    if (btn) {
        if (window.tpHidePacked) {
            btn.classList.add('active-filter');
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-square"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/></svg>`;
            tpShowToast("Showing Unpacked Items Only");
        } else {
            btn.classList.remove('active-filter');
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check-square"><path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>`;
            tpShowToast("Showing All Items");
        }
    }
    tpApplyVisibilityFilters();
}

function tpApplyVisibilityFilters() {
    const catId = window.tpActiveCategory || 'all';
    const hidePacked = window.tpHidePacked || false;
    
    // Check if the trip is fully completed (progress text displays 100%)
    const progressVal = document.getElementById('tpProgressText');
    const isFullyPacked = progressVal && progressVal.textContent === '100%';
    
    // Restore layout visibilities to all category tabs
    document.querySelectorAll('.category-pill[data-pill-id]').forEach(pill => {
        pill.style.display = 'flex';
    });
    
    document.querySelectorAll('.category-group').forEach(group => {
        const groupCatId = group.getAttribute('data-cat-id');
        let hasVisibleItems = false;
        
        // Filter individual active items
        group.querySelectorAll('.item-list .item-row').forEach(row => {
            const isPacked = row.classList.contains('packed');
            if (hidePacked && isPacked) {
                row.style.display = 'none';
            } else {
                row.style.display = 'flex';
                hasVisibleItems = true;
            }
        });
        
        const pill = document.querySelector(`.category-pill[data-pill-id="${groupCatId}"]`);
        
        if (hidePacked && !hasVisibleItems) {
            // Hide the scroller tab pill if it contains zero unpacked items
            if (pill) pill.style.display = 'none';
            
            // Revert viewed category back to 'all' if the active tab has just been completely packed
            if (catId === groupCatId) {
                window.tpActiveCategory = 'all';
                const allPill = document.querySelector('.category-pill:first-child');
                if (allPill) {
                    document.querySelectorAll('.category-pill').forEach(p => p.classList.remove('active'));
                    allPill.classList.add('active');
                }
            }
        }
        
        // Re-evaluate group visibility bounds
        const currentCatId = window.tpActiveCategory || 'all';
        const matchesCategory = (currentCatId === 'all' || groupCatId == currentCatId);
        
        if (matchesCategory && (!hidePacked || hasVisibleItems)) {
            group.style.display = 'block';
        } else {
            group.style.display = 'none';
        }
    });
    
    // Real-time completed celebration card toggle
    const banner = document.getElementById('tpCompletedBanner');
    const board = document.querySelector('.items-board');
    if (banner) {
        if (isFullyPacked && hidePacked) {
            banner.classList.add('active');
            if (board) board.style.display = 'none';
        } else {
            banner.classList.remove('active');
            if (board) board.style.display = 'flex';
        }
    }
}

function tpShowAllPackedItems() {
    if (window.tpHidePacked) {
        tpToggleUnpackedFilter();
    }
}

function tpFilterCategory(catId, force = false) {
    if (window.tpIsScrollingPills && !force) return; // Absorb click triggers when scrolling the pill scroller unless forced
    
    document.querySelectorAll('.category-pill').forEach(pill => {
        pill.classList.remove('active');
    });
    
    const target = (catId === 'all') 
        ? document.querySelector('.category-pill:first-child') 
        : document.querySelector(`.category-pill[data-pill-id="${catId}"]`);
        
    if (target) {
        target.classList.add('active');
        // Centering scroll alignment support
        target.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    }
    
    window.tpActiveCategory = catId;
    tpApplyVisibilityFilters();
}

// Tactile Checkbox Toggling
function tpToggleItemPacked(itemId, checked) {
    // Mobile tactile haptic vibrations (12ms)
    if (navigator.vibrate) {
        navigator.vibrate(12);
    }
    
    const row = document.getElementById(`item-row-${itemId}`);
    if (row) {
        if (checked) row.classList.add('packed');
        else row.classList.remove('packed');
        const cb = row.querySelector('input[type="checkbox"]');
        if (cb) cb.checked = checked;
    }
    
    // Instantly hide the item if the Unpacked Only filter is active
    if (window.tpHidePacked) {
        tpApplyVisibilityFilters();
    }
    
    const formData = new FormData();
    formData.append('id', itemId);
    formData.append('checked', checked ? 1 : 0);
    
    fetch('index.php?action=toggle_packed', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            tpUpdateProgressUI(data.pct);
            tpUpdatePriorityBanner(data.priority_items);
        }
    });
}

// Dynamic progress gauge updating with smooth stroke dash offset curves
function tpUpdateProgressUI(pct) {
    const textVal = document.getElementById('tpProgressText');
    const arc = document.getElementById('tpProgressArc');
    const title = document.getElementById('tpProgressTitle');
    
    // Defensive check: fallback to 0 if parameter is unassigned, null, or non-numeric
    if (pct === undefined || pct === null || isNaN(pct)) {
        pct = 0;
    }
    
    if (textVal) textVal.textContent = `${pct}%`;
    if (arc) {
        // Total dasharray bounds is 144.5
        const offset = 144.5 - (pct / 100 * 144.5);
        arc.style.strokeDashoffset = offset;
    }
    if (title) {
        title.textContent = (pct === 100) ? 'Ready to Fly!' : 'Packing in Progress...';
    }
}

// Scroll directly to a selected critical priority item on the board
function tpFocusItem(itemId) {
    const row = document.getElementById(`item-row-${itemId}`);
    if (row) {
        tpFilterCategory('all', true); // Bypass scroll lock during focusing
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.style.background = 'rgba(99, 102, 241, 0.15)';
        setTimeout(() => {
            row.style.background = '';
        }, 1500);
    }
}

// Real-time Priority List Rendering
function tpUpdatePriorityBanner(items) {
    const banner = document.querySelector('.priority-banner');
    if (!items || items.length === 0) {
        if (banner) banner.style.display = 'none';
        return;
    }
    
    if (!banner) {
        location.reload();
        return;
    }
    
    banner.style.display = 'flex';
    const list = banner.querySelector('.priority-items-list');
    if (list) {
        list.innerHTML = '';
        items.forEach(item => {
            const span = document.createElement('span');
            span.className = 'priority-item-tag';
            span.id = `priority-pill-${item.id}`;
            span.setAttribute('data-id', item.id);
            span.setAttribute('data-name', item.name);
            span.style.transition = 'all 0.2s cubic-bezier(0.4, 0, 0.2, 1)';
            
            span.innerHTML = `${item.name} <span style="opacity: 0.6; font-size: 10px;">×${item.quantity}</span>`;
            
            // Programmatically bind interactive and long-press pointer listeners to dynamic elements
            span.onclick = (e) => {
                if (!window.tpPreventClick) tpFocusItem(item.id);
            };
            span.onpointerdown = (e) => tpStartPillPress(e, item.id);
            span.onpointerup = (e) => tpEndPillPress(e);
            span.onpointercancel = (e) => tpCancelPillPress(e);
            span.onpointermove = (e) => tpMovePillPress(e);
            
            list.appendChild(span);
        });
    }
}

// Deleting Packing Items
let tpActiveItemDeleteId = null;

function tpDeleteItem(itemId) {
    tpActiveItemDeleteId = itemId;
    document.getElementById('tpItemDeleteModal').classList.add('active');
}

function tpCloseItemDeleteModal(e) {
    if (!e || e.target === document.getElementById('tpItemDeleteModal')) {
        document.getElementById('tpItemDeleteModal').classList.remove('active');
    }
}

function tpExecuteItemDelete() {
    if (!tpActiveItemDeleteId) return;
        
    const itemId = tpActiveItemDeleteId;
    const row = document.getElementById(`item-row-${itemId}`);
    if (row) {
        // Trigger visual fade-out instantly
        row.style.opacity = '0';
        row.style.transform = 'translateX(20px)';
    }
        
    const formData = new FormData();
    formData.append('id', itemId);
        
    fetch('index.php?action=delete_item', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            tpCloseItemDeleteModal(null);
            tpUpdateProgressUI(data.pct);
                
            if (row) {
                const group = row.closest('.category-group');
                    
                // Wait for the visual transition to settle, then remove the node structurally
                setTimeout(() => {
                    row.remove();
                        
                    // Update the active category count badge text dynamically
                    if (group) {
                        const badge = group.querySelector('.category-group-badge');
                        const list = group.querySelector('.item-list');
                        if (badge && list) {
                            badge.textContent = list.children.length + " items";
                        }
                    }
                        
                    // Safely re-apply visibility filters after DOM node is guaranteed removed
                    tpApplyVisibilityFilters();
                }, 250);
            } else {
                tpApplyVisibilityFilters();
            }
        } else {
            if (row) {
                row.style.opacity = '1';
                row.style.transform = '';
            }
            alert("Error: " + data.error);
        }
    });
}// Helper to escape HTML characters for dynamic DOM insertion
function tpEscapeHtml(unsafe) {
    return (unsafe || '').toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

// Form Submission adding/editing elements
function tpSubmitItem(event) {
    event.preventDefault();
    
    const editId = document.getElementById('tpEditId').value;
    const name = document.getElementById('tpNewName').value;
    const catId = document.getElementById('tpNewCategory').value;
    const qty = document.getElementById('tpNewQty').value;
    const note = document.getElementById('tpNewNote').value;
    const isNeeded = document.getElementById('tpNewNeeded').checked ? 1 : 0;
    
    const formData = new FormData();
    if (editId) formData.append('id', editId);
    formData.append('name', name);
    formData.append('category_id', catId);
    formData.append('quantity', qty);
    formData.append('note', note);
    formData.append('is_needed', isNeeded);
    
    const endpoint = editId ? 'index.php?action=edit_item' : 'index.php?action=add_item';
    
    fetch(endpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            tpCloseAddModal(null);
            document.getElementById('tpAddItemForm').reset();
            
            // Real-time metric sync
            tpUpdateProgressUI(data.pct);
            tpUpdatePriorityBanner(data.priority_items);
            
            const group = document.querySelector(`.category-group[data-cat-id="${data.item.category_id}"]`);
            if (!group) {
                // Rare edge case: Category was completely empty and container doesn't exist in DOM yet.
                // Safest approach is to reload to build the category container shell.
                location.reload();
                return;
            }
            
            const list = group.querySelector('.item-list');
            const badge = group.querySelector('.category-group-badge');
            
            const safeName = tpEscapeHtml(data.item.name);
            const safeNote = tpEscapeHtml(data.item.note);
            const noteHtml = data.item.note ? `<span style="opacity: 0.8;">• ${safeNote}</span>` : '';
            const starActive = data.item.is_needed ? 'active' : '';
            const starFill = data.item.is_needed ? 'currentColor' : 'none';
            
            if (editId) {
                // Editing an existing item
                const row = document.getElementById(`item-row-${data.item.id}`);
                if (row) {
                    const oldCatId = row.getAttribute('data-cat');
                    
                    // Update dataset attributes
                    row.setAttribute('data-name', data.item.name);
                    row.setAttribute('data-cat', data.item.category_id);
                    row.setAttribute('data-qty', data.item.quantity);
                    row.setAttribute('data-note', data.item.note);
                    row.setAttribute('data-needed', data.item.is_needed);
                    
                    // Update visual text nodes
                    row.querySelector('.item-name').textContent = data.item.name;
                    const metaContainer = row.querySelector('.item-meta');
                    metaContainer.innerHTML = `<span class="item-quantity">Qty: ${data.item.quantity}</span>\n${noteHtml}`;
                    
                    // Update star state
                    const starBtn = row.querySelector('.star-btn');
                    if (starBtn) {
                        starBtn.className = `action-btn star-btn ${starActive}`;
                        starBtn.querySelector('svg').setAttribute('fill', starFill);
                    }
                    
                    // Handle category migration if category was changed
                    if (oldCatId !== String(data.item.category_id)) {
                        list.appendChild(row);
                        
                        // Update old group badge
                        const oldGroup = document.querySelector(`.category-group[data-cat-id="${oldCatId}"]`);
                        if (oldGroup) {
                            const oldBadge = oldGroup.querySelector('.category-group-badge');
                            const oldList = oldGroup.querySelector('.item-list');
                            if (oldBadge && oldList) {
                                oldBadge.textContent = oldList.children.length + " items";
                            }
                        }
                        // Update new group badge
                        if (badge) badge.textContent = list.children.length + " items";
                    }
                }
            } else {
                // Adding a brand new item
                const row = document.createElement('div');
                row.className = 'item-row';
                row.id = `item-row-${data.item.id}`;
                row.setAttribute('data-id', data.item.id);
                row.setAttribute('data-name', data.item.name);
                row.setAttribute('data-cat', data.item.category_id);
                row.setAttribute('data-qty', data.item.quantity);
                row.setAttribute('data-note', data.item.note);
                row.setAttribute('data-needed', data.item.is_needed);
                
                // Bind pointer events
                row.onpointerdown = (e) => tpStartPress(e, data.item.id);
                row.onpointerup = (e) => tpEndPress(e);
                row.onpointercancel = (e) => tpCancelPress(e);
                row.onpointermove = (e) => tpMovePress(e);
                
                row.innerHTML = `
                    <div class="item-left">
                        <label class="item-checkbox-wrapper">
                            <input type="checkbox" onchange="tpToggleItemPacked(${data.item.id}, this.checked)">
                            <span class="item-checkbox-custom"></span>
                        </label>
                        <div class="item-details">
                            <span class="item-name">${safeName}</span>
                            <div class="item-meta">
                                <span class="item-quantity">Qty: ${data.item.quantity}</span>
                                ${noteHtml}
                            </div>
                        </div>
                    </div>
                    <div class="item-actions">
                        <button class="action-btn star-btn ${starActive}" id="star-btn-${data.item.id}" onclick="tpToggleItemNeeded(${data.item.id})" title="Toggle Priority Star">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="${starFill}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-star"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        </button>
                        <button class="action-btn" onclick="tpToggleItemHidden(${data.item.id}, 1)" title="Hide Item from Checklist">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                `;
                
                list.appendChild(row);
                if (badge) badge.textContent = list.children.length + " items";
            }
            
            // Re-apply filters so the new/edited item respects the current view mode
            tpApplyVisibilityFilters();
            
        } else {
            alert("Error: " + data.error);
        }
    });
}

// Global active trip switcher
function tpChangeTrip(tripId) {
    const formData = new FormData();
    formData.append('trip_id', tripId);
    
    fetch('index.php?action=change_trip', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'index.php?view=trip'; // Force trip detailed checklist view
        }
    });
}

function tpToggleTripStar(event, tripId) {
    event.stopPropagation(); // Stop navigation click propagation
    
    if (navigator.vibrate) {
        navigator.vibrate(10);
    }
    
    const formData = new FormData();
    formData.append('id', tripId);
    
    fetch('index.php?action=toggle_star_trip', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// Trip full state pack resetting
function tpResetTrip() {
    document.getElementById('tpResetConfirmModal').classList.add('active');
}

function tpCloseResetConfirmModal(e) {
    if (!e || e.target === document.getElementById('tpResetConfirmModal')) {
        document.getElementById('tpResetConfirmModal').classList.remove('active');
    }
}

function tpExecuteResetTrip() {
    fetch('index.php?action=reset_trip')
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            tpCloseResetConfirmModal(null);
            location.reload();
        } else {
            tpShowToast("Error resetting trip.");
        }
    });
}

// Custom theme preset application
function tpApplyPresetTheme(preset) {
    if (navigator.vibrate) {
        navigator.vibrate(10);
    }
    
    const formData = new FormData();
    formData.append('bg_color', preset.bg);
    formData.append('card_bg', preset.card);
    formData.append('primary_accent', preset.accent);
    
    fetch('index.php?action=save_settings', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Apply CSS variables dynamically across DOM
            document.documentElement.style.setProperty('--bg-color', preset.bg);
            document.documentElement.style.setProperty('--card-bg', preset.card);
            document.documentElement.style.setProperty('--primary-accent', preset.accent);
            document.documentElement.style.setProperty('--text-primary', preset.text);
            document.documentElement.style.setProperty('--text-secondary', preset.secondary);
            
            tpCloseSettings(null);
            
            // Reload to update dynamic SVG previews & backgrounds
            setTimeout(() => {
                location.reload();
            }, 100);
        }
    });
}

// Custom Category Selector Dropdown for Form Drawer
function tpToggleAddCategoryDropdown(e) {
    e.stopPropagation();
    const dropdown = document.getElementById('tpAddCategoryDropdown');
    if (dropdown) dropdown.classList.toggle('active');
}

function tpSelectAddCategory(catId, catName) {
    const input = document.getElementById('tpNewCategory');
    const trigger = document.getElementById('tpAddCategoryTrigger').querySelector('span');
    
    if (input) input.value = catId;
    if (trigger) trigger.textContent = catName;
    
    // Set selected highlights
    document.querySelectorAll('#tpAddCategoryDropdown .tp-select-option').forEach(opt => {
        opt.classList.remove('selected');
        if (opt.getAttribute('data-value') == catId) {
            opt.classList.add('selected');
        }
    });
    
    const dropdown = document.getElementById('tpAddCategoryDropdown');
    if (dropdown) dropdown.classList.remove('active');
}

// Modal Drawer Interactivity
function tpOpenAddModal() {
    document.getElementById('tpAddItemForm').reset();
    document.getElementById('tpEditId').value = '';
    document.getElementById('tpAddModalTitle').textContent = 'Add Packing Item';
    document.getElementById('tpAddModalSubmit').textContent = 'Add to Packing List';
    
    // Reset custom select to first option by default
    const firstOpt = document.querySelector('#tpAddCategoryDropdown .tp-select-option');
    if (firstOpt) {
        const catId = firstOpt.getAttribute('data-value');
        const catName = firstOpt.querySelector('span').textContent;
        tpSelectAddCategory(catId, catName);
    }
    
    // Detect active list filter and pre-assign target dropdown category value
    const activeCat = tpGetActiveCategory();
    if (activeCat !== 'all') {
        const opt = document.querySelector(`#tpAddCategoryDropdown .tp-select-option[data-value="${activeCat}"]`);
        if (opt) {
            const catName = opt.querySelector('span').textContent;
            tpSelectAddCategory(activeCat, catName);
        }
    }
    
    document.getElementById('tpAddModal').classList.add('active');
}
function tpCloseAddModal(e) {
    if (!e || e.target === document.getElementById('tpAddModal')) {
        document.getElementById('tpAddModal').classList.remove('active');
    }
}
function tpOpenSettings() {
    document.getElementById('tpSettingsModal').classList.add('active');
}
function tpCloseSettings(e) {
    if (!e || e.target === document.getElementById('tpSettingsModal')) {
        document.getElementById('tpSettingsModal').classList.remove('active');
    }
}