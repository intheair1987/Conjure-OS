<?php
// apps/TravelPacker/modules/layout.php

function tp_render_ambient_bg() {
    ?>
    <div class="ambient-bg">
        <div class="ambient-blob blob-1"></div>
        <div class="ambient-blob blob-2"></div>
        <div class="ambient-blob blob-3"></div>
    </div>
    <?php
}

function tp_render_dashboard($trips_data) {
    ?>
    <header class="sticky-header">
        <div class="header-container">
            <div class="title-section">
                <h1 style="display: flex; align-items: center; gap: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-briefcase"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg>
                    My Trips
                </h1>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <button class="action-btn" onclick="tpOpenSettings()" title="Theme Customizer">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-settings"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.1a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
        </div>
    </header>

    <div class="app-container">
        <div class="dashboard-grid">
            <?php foreach ($trips_data as $t): ?>
                <div class="trip-card" 
                     id="trip-card-<?php echo $t['id']; ?>"
                     data-id="<?php echo $t['id']; ?>"
                     data-name="<?php echo htmlspecialchars($t['name']); ?>"
                     data-categories="<?php echo htmlspecialchars(json_encode($t['active_cats'])); ?>"
                     onclick="if(!window.tpPreventClick) tpChangeTrip(<?php echo $t['id']; ?>)"
                     onpointerdown="tpStartTripPress(event, <?php echo $t['id']; ?>)"
                     onpointerup="tpEndTripPress(event)"
                     onpointercancel="tpCancelTripPress(event)"
                     onpointermove="tpMoveTripPress(event)">
                    <div class="trip-card-header">
                        <div>
                            <div class="trip-card-title"><?php echo htmlspecialchars($t['name']); ?></div>
                            <div class="trip-card-date">Created: <?php echo htmlspecialchars($t['date']); ?></div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button class="action-btn star-btn <?php echo ($t['is_starred'] == 1) ? 'active' : ''; ?>" onclick="tpToggleTripStar(event, <?php echo $t['id']; ?>)" title="Pin as Default Auto-Open List" style="padding: 4px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="<?php echo ($t['is_starred'] == 1) ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-star"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            </button>
                            <div class="trip-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-map"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" x2="9" y1="3" y2="18"/><line x1="15" x2="15" y1="6" y2="21"/></svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="trip-card-stats">
                        <span style="color: var(--text-secondary);">Progress</span>
                        <span style="color: var(--primary-accent);"><?php echo $t['packed_items']; ?> / <?php echo $t['total_items']; ?></span>
                    </div>
                    
                    <div class="trip-linear-progress">
                        <div class="trip-linear-fill" style="width: <?php echo $t['pct']; ?>%;"></div>
                    </div>
                    
                    <div class="trip-categories-preview">
                        <?php foreach ($t['icons'] as $icon): ?>
                            <div class="trip-cat-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-<?php echo htmlspecialchars($icon); ?>"><use href="#icon-<?php echo htmlspecialchars($icon); ?>"></use></svg>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($t['icons'])): ?>
                            <span style="font-size: 11px; color: var(--text-secondary);">No items added yet</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function tp_render_header($current_trip, $all_trips, $pct) {
    ?>
    <header class="sticky-header">
        <div class="header-container">
            <div class="title-section">
    <button class="action-btn" onclick="window.location.href='?view=home'" title="Back to Dashboard" style="padding: 6px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
</button>
                
<div class="tp-select-wrapper" id="tpTripSelectWrapper"><div class="tp-select-trigger" onclick="tpToggleTripDropdown(event)">
                        <span><?php echo htmlspecialchars($current_trip['name']); ?></span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-down"><path d="m6 9 6 6 6-6"/></svg>
                    </div>
                    <div class="tp-select-dropdown" id="tpTripDropdown">
                        <?php foreach ($all_trips as $t): ?>
                            <div class="tp-select-option <?php echo ($t['id'] == $current_trip['id']) ? 'selected' : ''; ?>" onclick="tpChangeTrip(<?php echo $t['id']; ?>)">
                                <span><?php echo htmlspecialchars($t['name']); ?></span>
                                <?php if ($t['id'] == $current_trip['id']): ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check" style="color: var(--primary-accent);"><path d="M20 6 9 17l-5-5"/></svg>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="tp-select-divider"></div>
                        <div class="tp-select-option" onclick="tpHandleTripEditCurrent()">
                            <span style="color: var(--text-primary);">Edit Current Trip</span>
                        </div>
                        <div class="tp-select-option" onclick="tpDeleteCurrentTrip(<?php echo $current_trip['id']; ?>)">
                            <span style="color: var(--danger-color);">Delete Current Trip</span>
                        </div>
                        <div class="tp-select-divider"></div>
                        <div class="tp-select-option tp-select-action" onclick="tpOpenTripModal()">
                            <span>+ Create New Trip</span>
                        </div>
                    </div>
                </div>
                
                <button class="action-btn active-filter" id="tpFilterToggleBtn" onclick="tpToggleUnpackedFilter()" title="Toggle Unpacked Only" style="padding: 6px; margin-left: 4px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-square"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/></svg>
                </button>
            </div>
            
            <div style="display: flex; align-items: center; gap: 8px;">
                <button class="action-btn" onclick="tpOpenSettings()" title="Theme Customizer">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-settings"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.1a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <button class="action-btn" onclick="tpResetTrip()" title="Reset All Items">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-refresh-cw"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                </button>
            </div>
        </div>
        
        <div class="header-container">
            <div class="progress-section" id="tpCurrentTripData" data-id="<?php echo $current_trip['id']; ?>" data-name="<?php echo htmlspecialchars($current_trip['name']); ?>" data-categories="<?php echo htmlspecialchars(json_encode($current_trip['active_cats'])); ?>">
                <div class="progress-circle-container">
                    <svg class="progress-circle-svg" width="54" height="54">
                        <circle class="progress-circle-bg" cx="27" cy="27" r="23" />
                        <circle class="progress-circle-bar" id="tpProgressArc" cx="27" cy="27" r="23" style="stroke-dashoffset: <?php echo (144.5 - ($pct / 100 * 144.5)); ?>; stroke-dasharray: 144.5;" />
                    </svg>
                    <div class="progress-text-val" id="tpProgressText"><?php echo $pct; ?>%</div>
                </div>
                <div class="progress-info">
                    <div class="progress-title" id="tpProgressTitle"><?php echo ($pct == 100) ? 'Ready to Fly!' : 'Packing in Progress...'; ?></div>
                    <div class="progress-desc" id="tpProgressDesc">Select items below to mark them as packed.</div>
                </div>
                <button class="action-btn" onclick="tpHandleTripEditCurrent()" title="Edit Trip" style="margin-left: auto; padding: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pencil"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                </button>
            </div>
        </div>
    </header>
    <?php
}

function tp_render_priority_banner($priority_items) {
    ?>
    <div class="priority-banner" style="<?php echo empty($priority_items) ? 'display: none;' : 'display: flex;'; ?>">
        <div class="priority-banner-header">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-star" style="color: #fbbf24;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            Critical Starred Needs (Unpacked)
        </div>
        <div class="priority-items-list">
            <?php foreach ($priority_items as $item): ?>
                <span class="priority-item-tag" 
                      id="priority-pill-<?php echo $item['id']; ?>"
                      data-id="<?php echo $item['id']; ?>"
                      data-name="<?php echo htmlspecialchars($item['name']); ?>"
                      onclick="if(!window.tpPreventClick) tpFocusItem(<?php echo $item['id']; ?>)"
                      onpointerdown="tpStartPillPress(event, <?php echo $item['id']; ?>)"
                      onpointerup="tpEndPillPress(event)"
                      onpointercancel="tpCancelPillPress(event)"
                      onpointermove="tpMovePillPress(event)"
                      style="transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);">
                    <?php echo htmlspecialchars($item['name']); ?>
                    <span style="opacity: 0.6; font-size: 10px;">×<?php echo $item['quantity']; ?></span>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function tp_render_category_pills($categories, $active_id = 'all') {
    ?>
    <div class="category-scroller">
        <div class="category-pill <?php echo ($active_id === 'all') ? 'active' : ''; ?>" onclick="if(!window.tpPreventClick) tpFilterCategory('all')">
            All Items
        </div>
        <?php foreach ($categories as $cat): ?>
            <div class="category-pill <?php echo ($active_id == $cat['id']) ? 'active' : ''; ?>" 
                 data-pill-id="<?php echo $cat['id']; ?>" 
                 onclick="if(!window.tpPreventClick) tpFilterCategory('<?php echo $cat['id']; ?>')"
                 onpointerdown="tpStartCatPress(event, <?php echo $cat['id']; ?>)"
                 onpointerup="tpEndCatPress(event)"
                 onpointercancel="tpCancelCatPress(event)"
                 onpointermove="tpMoveCatPress(event)">
                <?php echo htmlspecialchars($cat['name']); ?>
            </div>
        <?php endforeach; ?>
        <div class="category-pill" style="border-style: dashed; color: var(--primary-accent);" onclick="if(!window.tpPreventClick) tpOpenCategoryModal()">
            + New Category
        </div>
    </div>
    <?php
}

function tp_render_board($grouped_items) {
    ?>
    <div class="items-board">
        <?php foreach ($grouped_items as $group): 
            $visible_items = [];
            $hidden_items = [];
            foreach ($group['items'] as $item) {
                if (isset($item['is_hidden']) && $item['is_hidden'] == 1) {
                    $hidden_items[] = $item;
                } else {
                    $visible_items[] = $item;
                }
            }
            ?>
            <div class="category-group" data-cat-id="<?php echo $group['category']['id']; ?>">
                <div class="category-group-header">
                    <div class="category-group-title">
                        <?php echo htmlspecialchars($group['category']['name']); ?>
                    </div>
                    <span class="category-group-badge"><?php echo count($visible_items); ?> items</span>
                </div>
                <div class="item-list">
                    <?php foreach ($visible_items as $item): ?>
                        <div class="item-row <?php echo $item['is_packed'] ? 'packed' : ''; ?>" 
                             id="item-row-<?php echo $item['id']; ?>" 
                             data-id="<?php echo $item['id']; ?>"
                             data-name="<?php echo htmlspecialchars($item['name']); ?>"
                             data-cat="<?php echo $item['category_id']; ?>"
                             data-qty="<?php echo $item['quantity']; ?>"
                             data-note="<?php echo htmlspecialchars($item['note']); ?>"
                             data-needed="<?php echo $item['is_needed']; ?>"
                             onpointerdown="tpStartPress(event, <?php echo $item['id']; ?>)"
                             onpointerup="tpEndPress(event)"
                             onpointercancel="tpCancelPress(event)"
                             onpointermove="tpMovePress(event)">
                            <div class="item-left">
                                <label class="item-checkbox-wrapper">
                                    <input type="checkbox" <?php echo $item['is_packed'] ? 'checked' : ''; ?> onchange="tpToggleItemPacked(<?php echo $item['id']; ?>, this.checked)">
                                    <span class="item-checkbox-custom"></span>
                                </label>
                                <div class="item-details">
                                    <span class="item-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                    <div class="item-meta">
                                        <span class="item-quantity">Qty: <?php echo $item['quantity']; ?></span>
                                        <?php if (!empty($item['note'])): ?>
                                            <span style="opacity: 0.8;">• <?php echo htmlspecialchars($item['note']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="item-actions">
                                <button class="action-btn star-btn <?php echo $item['is_needed'] ? 'active' : ''; ?>" id="star-btn-<?php echo $item['id']; ?>" onclick="tpToggleItemNeeded(<?php echo $item['id']; ?>)" title="Toggle Priority Star">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="<?php echo $item['is_needed'] ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-star"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                </button>
                                <button class="action-btn" onclick="tpToggleItemHidden(<?php echo $item['id']; ?>, 1)" title="Hide Item from Checklist">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Expandable Hidden Items Segment (Always present but hidden if empty to allow dynamic JS migrations) -->
                <div class="hidden-items-section" style="<?php echo empty($hidden_items) ? 'display: none;' : ''; ?>">
                    <div class="hidden-section-toggle" onclick="tpToggleHiddenSection(this)">
                        <span><span class="toggle-text">Show</span> Hidden (<span class="hidden-count"><?php echo count($hidden_items); ?></span>)</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-down" style="transition: transform 0.2s;"><path d="m6 9 6 6 6-6"/></svg>
                    </div>
                    <div class="hidden-items-container" style="display: none;">
                        <?php foreach ($hidden_items as $item): ?>
                            <div class="item-row hidden-item" 
                                 id="item-row-<?php echo $item['id']; ?>"
                                 data-id="<?php echo $item['id']; ?>"
                                 data-name="<?php echo htmlspecialchars($item['name']); ?>"
                                 data-cat="<?php echo $item['category_id']; ?>"
                                 data-qty="<?php echo $item['quantity']; ?>"
                                 data-note="<?php echo htmlspecialchars($item['note']); ?>"
                                 data-needed="<?php echo $item['is_needed']; ?>"
                                 onpointerdown="tpStartPress(event, <?php echo $item['id']; ?>)"
                                 onpointerup="tpEndPress(event)"
                                 onpointercancel="tpCancelPress(event)"
                                 onpointermove="tpMovePress(event)">
                                <div class="item-left" style="opacity: 0.5;">
                                    <div class="item-details" style="padding-left: 12px;">
                                        <span class="item-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                        <div class="item-meta">
                                            <span class="item-quantity">Qty: <?php echo $item['quantity']; ?></span>
                                            <?php if (!empty($item['note'])): ?>
                                                <span style="opacity: 0.8;">• <?php echo htmlspecialchars($item['note']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="item-actions">
                                    <button class="action-btn" onclick="tpToggleItemHidden(<?php echo $item['id']; ?>, 0)" title="Unhide Item" style="color: var(--primary-accent);">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye-off"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.52 13.16 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function tp_render_trip_modal($categories) {
    ?>
    <div class="overlay-panel" id="tpTripModal" onclick="tpCloseTripModal(event)">
        <div class="bottom-sheet" onclick="event.stopPropagation()">
            <div class="sheet-header">
                <span class="sheet-title" id="tpTripModalTitle">Create New Trip List</span>
                <button class="action-btn" onclick="tpCloseTripModal(null)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <form id="tpTripForm" onsubmit="tpSubmitTrip(event)">
                <input type="hidden" id="tpEditTripId" value="">
                <div class="form-group">
                    <label class="form-label">Trip Destination / Name</label>
                    <input type="text" id="tpTripName" class="form-input" placeholder="e.g. Tokyo & Kyoto Autumn 2026" required>
                </div>
                <div class="form-group" style="margin-bottom: 24px;">
                    <label class="form-label">Select Categories to Include</label>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-top: 6px; max-height: 200px; overflow-y: auto; padding: 4px 0;">
                        <?php foreach ($categories as $cat): ?>
                            <label class="checkbox-row" style="font-size: 13px;">
                                <input type="checkbox" class="tp-trip-cat-check" value="<?php echo $cat['id']; ?>" checked style="width: 16px; height: 16px; accent-color: var(--primary-accent);">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn-primary" id="tpTripModalSubmit">Create Trip</button>
            </form>
        </div>
    </div>
    <?php
}

function tp_render_trip_context_menu() {
    ?>
    <div id="tpTripContextMenu" class="tp-context-menu">
        <div class="tp-context-item" onclick="tpHandleTripEdit()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-edit-3"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            Edit Trip
        </div>
        <div class="tp-context-divider"></div>
        <div class="tp-context-item" onclick="tpHandleTripDuplicate()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-copy"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
            Duplicate Trip
        </div>
        <div class="tp-context-divider"></div>
        <div class="tp-context-item tp-context-danger" onclick="tpHandleTripDelete()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash-2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
            Delete Trip
        </div>
    </div>
    <?php
}

function tp_render_trip_duplicate_modal() {
    ?>
    <div class="overlay-panel" id="tpTripDuplicateModal" onclick="tpCloseTripDuplicateModal(event)">
        <div class="bottom-sheet" onclick="event.stopPropagation()">
            <div class="sheet-header">
                <span class="sheet-title">Duplicate Trip</span>
                <button class="action-btn" onclick="tpCloseTripDuplicateModal(null)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <form id="tpTripDuplicateForm" onsubmit="tpSubmitTripDuplicate(event)">
                <input type="hidden" id="tpDuplicateSourceId" value="">
                <div class="form-group" style="margin-bottom: 24px;">
                    <label class="form-label">New Trip Name</label>
                    <input type="text" id="tpDuplicateTripName" class="form-input" placeholder="e.g. Tokyo Trip (Copy)" required>
                </div>
                <button type="submit" class="btn-primary">Create Duplicate</button>
            </form>
        </div>
    </div>
    <?php
}

function tp_render_category_pill_context_menu() {
    ?>
    <div id="tpCatContextMenu" class="tp-context-menu">
        <div class="tp-context-item" onclick="tpHandleCatRemove()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-minus-circle"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/></svg>
            Remove from Trip
        </div>
        <div class="tp-context-divider"></div>
        <div class="tp-context-item tp-context-danger" onclick="tpHandleCatDelete()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash-2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
            Delete Category
        </div>
    </div>
    <?php
}

function tp_render_reset_confirm_modal() {
    ?>
    <div class="overlay-panel" id="tpResetConfirmModal" onclick="tpCloseResetConfirmModal(event)">
        <div class="bottom-sheet" onclick="event.stopPropagation()">
            <div class="sheet-header">
                <span class="sheet-title">Reset Checklist?</span>
                <button class="action-btn" onclick="tpCloseResetConfirmModal(null)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div style="margin-bottom: 24px; font-size: 14px; color: var(--text-secondary); line-height: 1.5;">
                Are you sure you want to reset all packed items in this trip back to unpacked status? This action cannot be undone.
            </div>
            <div style="display: flex; gap: 12px;">
                <button class="btn-secondary" onclick="tpCloseResetConfirmModal(null)" style="flex: 1; padding: 12px; border-radius: 8px;">Cancel</button>
                <button class="btn-primary" onclick="tpExecuteResetTrip()" style="flex: 1; padding: 12px; border-radius: 8px; background: var(--danger, #ef4444); border: none; color: white;">Reset List</button>
            </div>
        </div>
    </div>
    <?php
}

function tp_render_celebration_banner() {
    ?>
    <div class="completed-banner" id="tpCompletedBanner">
        <div class="completed-airplane">✈️</div>
        <div class="completed-title">Fully Packed & Ready!</div>
        <div class="completed-desc">Every single checklist item has been successfully checked and packed. Enjoy your trip!</div>
        <button class="btn-primary" onclick="tpShowAllPackedItems()" style="max-width: 200px;">Review Packed Items</button>
    </div>
    <?php
}

function tp_render_item_delete_modal() {
    ?>
    <div class="overlay-panel" id="tpItemDeleteModal" onclick="tpCloseItemDeleteModal(event)">
        <div class="bottom-sheet" onclick="event.stopPropagation()">
            <div class="sheet-header">
                <span class="sheet-title">Delete Item?</span>
                <button class="action-btn" onclick="tpCloseItemDeleteModal(null)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div style="margin-bottom: 24px; font-size: 14px; color: var(--text-secondary); line-height: 1.5;">
                Are you sure you want to permanently delete this item from your packing list? This action cannot be undone.
            </div>
            <div style="display: flex; gap: 12px;">
                <button class="btn-secondary" onclick="tpCloseItemDeleteModal(null)" style="flex: 1; padding: 12px; border-radius: 8px;">Cancel</button>
                <button class="btn-primary" onclick="tpExecuteItemDelete()" style="flex: 1; padding: 12px; border-radius: 8px; background: var(--danger, #ef4444); border: none; color: white;">Delete Item</button>
            </div>
        </div>
    </div>
    <?php
}

function tp_render_trip_delete_modal() {
    ?>
    <div class="overlay-panel" id="tpTripDeleteConfirmModal" onclick="tpCloseTripDeleteConfirmModal(event)">
        <div class="bottom-sheet" onclick="event.stopPropagation()">
            <div class="sheet-header">
                <span class="sheet-title">Delete Trip?</span>
                <button class="action-btn" onclick="tpCloseTripDeleteConfirmModal(null)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div style="margin-bottom: 24px; font-size: 14px; color: var(--text-secondary); line-height: 1.5;">
                Are you sure you want to permanently delete this trip and all its associated checklist item states? This action is permanent and cannot be undone.
            </div>
            <div style="display: flex; gap: 12px;">
                <button class="btn-secondary" onclick="tpCloseTripDeleteConfirmModal(null)" style="flex: 1; padding: 12px; border-radius: 8px;">Cancel</button>
                <button class="btn-primary" onclick="tpExecuteTripDelete()" style="flex: 1; padding: 12px; border-radius: 8px; background: var(--danger, #ef4444); border: none; color: white;">Delete Trip</button>
            </div>
        </div>
    </div>
    <?php
}function tp_render_category_delete_modal() {
    ?>
    <div class="overlay-panel" id="tpCategoryDeleteModal" onclick="tpCloseCategoryDeleteModal(event)">
        <div class="bottom-sheet" onclick="event.stopPropagation()">
            <div class="sheet-header">
                <span class="sheet-title">Delete Category?</span>
                <button class="action-btn" onclick="tpCloseCategoryDeleteModal(null)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div style="margin-bottom: 24px; font-size: 14px; color: var(--text-secondary); line-height: 1.5;">
                Are you sure you want to permanently delete this category and ALL items inside it across ALL trips? This action is permanent and cannot be undone.
            </div>
            <div style="display: flex; gap: 12px;">
                <button class="btn-secondary" onclick="tpCloseCategoryDeleteModal(null)" style="flex: 1; padding: 12px; border-radius: 8px;">Cancel</button>
                <button class="btn-primary" onclick="tpExecuteCategoryDelete()" style="flex: 1; padding: 12px; border-radius: 8px; background: var(--danger, #ef4444); border: none; color: white;">Delete Category</button>
            </div>
        </div>
    </div>
    <?php
}function tp_render_category_modal() {
    ?>
    <div class="overlay-panel" id="tpCategoryModal" onclick="tpCloseCategoryModal(event)">
        <div class="bottom-sheet" onclick="event.stopPropagation()">
            <div class="sheet-header">
                <span class="sheet-title">Create New Category</span>
                <button class="action-btn" onclick="tpCloseCategoryModal(null)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <form id="tpCategoryForm" onsubmit="tpSubmitCategory(event)">
                <div class="form-group">
                    <label class="form-label">Category Name</label>
                    <input type="text" id="tpCatName" class="form-input" placeholder="e.g. Photography Gear" required>
                </div>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label">Category Icon</label>
                    <input type="hidden" id="tpCatIcon" value="package" required>
                    <div class="icon-picker-grid">
                        <?php 
                        $icons = ['package', 'briefcase', 'map', 'camera', 'music', 'coffee', 'sun', 'moon', 'shopping-bag', 'heart', 'shirt', 'dumbbell', 'laptop', 'pill', 'droplet', 'compass', 'file-text', 'check-square'];
                        foreach ($icons as $idx => $ic): ?>
                            <div class="icon-picker-btn <?php echo $idx === 0 ? 'active' : ''; ?>" data-icon="<?php echo $ic; ?>" onclick="tpSelectCategoryIcon('<?php echo $ic; ?>', this)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><use href="#icon-<?php echo $ic; ?>"></use></svg>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 24px;">
                    <label class="checkbox-row" style="font-size: 13px;">
                        <input type="checkbox" id="tpCatEnableCurrent" checked style="width: 16px; height: 16px; accent-color: var(--primary-accent);">
                        Enable for current trip
                    </label>
                </div>
                <button type="submit" class="btn-primary">Create Category</button>
            </form>
        </div>
    </div>
    <?php
}

function tp_render_context_menu() {
    ?>
    <div id="tpContextMenu" class="tp-context-menu">
        <div class="tp-context-item" onclick="tpHandleEdit()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-edit-3"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            Edit Item
        </div>
        <div class="tp-context-divider"></div>
        <div class="tp-context-item" onclick="tpHandleContextHide()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye-off"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.52 13.16 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
            Hide Item
        </div>
        <div class="tp-context-divider"></div>
        <div class="tp-context-item tp-context-danger" onclick="tpHandleContextDelete()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash-2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
            Delete Item
        </div>
    </div>
    <?php
}function tp_render_priority_context_menu() {
    ?>
    <div id="tpPriorityContextMenu" class="tp-context-menu">
        <div class="tp-context-item" onclick="tpHandlePriorityJump()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-navigation"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
            Jump to Item
        </div>
        <div class="tp-context-divider"></div>
        <div class="tp-context-item" onclick="tpHandlePriorityPack()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check-circle"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Check / Pack Item
        </div>
        <div class="tp-context-divider"></div>
        <div class="tp-context-item tp-context-danger" onclick="tpHandlePriorityUnstar()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-star-off"><path d="M8.34 8.34L2 9.27l5 4.87L5.82 21 12 17.77 18.18 21l-.56-4.14"/><path d="M22 9l-5 4.87L18 18"/><path d="M11.75 3l1.83 3.68L17.5 7.2l-.71.69"/><path d="M2 2l20 20"/></svg>
            Unstar / Remove
        </div>
    </div>
    <?php
}function tp_render_fab($view = 'trip') {
    if ($view === 'home') {
        ?>
        <button class="fab" onclick="tpOpenTripModal()" title="Create New Trip">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
        </button>
        <?php
    } else {
        ?>
        <button class="fab" onclick="tpOpenAddModal()" title="Add Custom Item">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
        </button>
        <?php
    }
}

function tp_render_add_modal($categories) {
    ?>
    <div class="overlay-panel" id="tpAddModal" onclick="tpCloseAddModal(event)">
        <div class="bottom-sheet" onclick="event.stopPropagation()">
            <div class="sheet-header">
                <span class="sheet-title" id="tpAddModalTitle">Add Packing Item</span>
                <button class="action-btn" onclick="tpCloseAddModal(null)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <form id="tpAddItemForm" onsubmit="tpSubmitItem(event)">
                <input type="hidden" id="tpEditId" value="">
                <div class="form-group">
                    <label class="form-label">Item Name</label>
                    <input type="text" id="tpNewName" class="form-input" placeholder="e.g. Hiking shoes" required>
                </div>
                <div class="form-row">
                    <div class="form-group" style="position: relative;">
                        <label class="form-label">Category</label>
                        <div class="tp-select-wrapper" id="tpAddCategoryWrapper" style="width: 100%;">
                            <input type="hidden" id="tpNewCategory" value="" required>
                            <div class="tp-select-trigger" id="tpAddCategoryTrigger" onclick="tpToggleAddCategoryDropdown(event)" style="width: 100%; justify-content: space-between; padding: 12px;">
                                <span>Select Category</span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-down"><path d="m6 9 6 6 6-6"/></svg>
                            </div>
                            <div class="tp-select-dropdown" id="tpAddCategoryDropdown" style="width: 100%;">
                                <?php foreach ($categories as $cat): ?>
                                    <div class="tp-select-option" data-value="<?php echo $cat['id']; ?>" onclick="tpSelectAddCategory(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars($cat['name']); ?>')">
                                        <span><?php echo htmlspecialchars($cat['name']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quantity</label>
                        <input type="number" id="tpNewQty" class="form-input" value="1" min="1" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Custom Note (Optional)</label>
                    <input type="text" id="tpNewNote" class="form-input" placeholder="e.g. inside front pocket">
                </div>
                <div class="form-group" style="margin-top: 10px; margin-bottom: 24px;">
                    <label class="checkbox-row">
                        <input type="checkbox" id="tpNewNeeded" style="width: 16px; height: 16px; accent-color: var(--primary-accent);">
                        Mark as Starred (Critical Priority)
                    </label>
                </div>
                <button type="submit" class="btn-primary" id="tpAddModalSubmit">Add to Packing List</button>
            </form>
        </div>
    </div>
    <?php
}

function tp_render_settings_modal($settings) {
    $presets = [
        [
            "id" => "space-indigo",
            "name" => "Space Indigo",
            "bg" => "#0f172a",
            "card" => "#1e293b",
            "accent" => "#6366f1",
            "text" => "#f8fafc",
            "secondary" => "#94a3b8"
        ],
        [
            "id" => "midnight-frost",
            "name" => "Midnight Frost",
            "bg" => "#090d16",
            "card" => "#111a2e",
            "accent" => "#38bdf8",
            "text" => "#f0fdf4",
            "secondary" => "#64748b"
        ],
        [
            "id" => "sunset-glow",
            "name" => "Sunset Glow",
            "bg" => "#1c0d12",
            "card" => "#2d151c",
            "accent" => "#f43f5e",
            "text" => "#fff1f2",
            "secondary" => "#fda4af"
        ],
        [
            "id" => "cyberpunk",
            "name" => "Cyberpunk Neon",
            "bg" => "#050505",
            "card" => "#121212",
            "accent" => "#10b981",
            "text" => "#ecfdf5",
            "secondary" => "#6ee7b7"
        ],
        [
            "id" => "obsidian",
            "name" => "Slate Obsidian",
            "bg" => "#0a0a0a",
            "card" => "#171717",
            "accent" => "#ffffff",
            "text" => "#ffffff",
            "secondary" => "#a3a3a3"
        ]
    ];
    ?>
    <div class="overlay-panel" id="tpSettingsModal" onclick="tpCloseSettings(event)">
        <div class="bottom-sheet" onclick="event.stopPropagation()">
            <div class="sheet-header">
                <span class="sheet-title">App Theme Presets</span>
                <button class="action-btn" onclick="tpCloseSettings(null)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div style="margin-bottom: 8px; font-size: 13px; color: var(--text-secondary);">
                Select a curated color theme for your travel packing assistant.
            </div>
            <div class="theme-presets-grid">
                <?php foreach ($presets as $p): ?>
                    <?php 
                        $is_active = ($settings['bg-color'] === $p['bg'] && $settings['primary-accent'] === $p['accent']);
                    ?>
                    <div class="preset-card <?php echo $is_active ? 'active' : ''; ?>" onclick='tpApplyPresetTheme(<?php echo json_encode($p); ?>)'>
                        <div class="preset-name"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="preset-preview-row">
                            <span class="preset-color-dot" style="background: <?php echo $p['bg']; ?>;" title="Background"></span>
                            <span class="preset-color-dot" style="background: <?php echo $p['card']; ?>;" title="Cards"></span>
                            <span class="preset-color-dot" style="background: <?php echo $p['accent']; ?>;" title="Accent"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}