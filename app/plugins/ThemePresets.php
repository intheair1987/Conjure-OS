<?php
// ==============================================================================
// PLUGIN: Theme Presets
// DESCRIPTION: App Visual Themes.
$tp_config_file = CJOS_PATH_DATA . '/theme-presets-config.json';

// PHP THEME REGISTRY (For SSR Zero-Flash Loading)
// This allows app.php to see colors before the page renders.
$tp_themes_registry = [
    'default' => [
        'name' => "Luminous (Default)",
        'vars' => [
            "--bg-color" => "#F2F2F7", "--card-bg" => "#FFFFFF", "--header-bg" => "rgba(242, 242, 247, 0.98)",
            "--text-primary" => "#1C1C1E", "--text-secondary" => "#8E8E93", "--text-title" => "#48484A",
            "--primary" => "#007AFF", "--btn-bg" => "#E5E5EA", "--btn-text" => "#1C1C1E",
            "--input-bg" => "#EBEBEF", "--input-text" => "#1C1C1E", "--primary-text" => "#FFFFFF",
            "--border-color" => "rgba(0, 0, 0, 0.08)", "--shadow-card" => "0 4px 24px rgba(0, 0, 0, 0.04)",
            "--ai-accent" => "#5856D6", "--ai-accent-bg" => "rgba(88, 86, 214, 0.12)",
            "--glass-bg" => "rgba(255, 255, 255, 0.7)", "--glass-border" => "rgba(0, 0, 0, 0.08)",
            "--shadow-floating" => "0 12px 40px rgba(0, 0, 0, 0.12)", "--player-active" => "#007AFF"
        ],
        'extra' => ".scroll-view { background-color: var(--bg-color); } .todo-text { color: var(--text-primary); } #cal-grid > div:not(:empty) { background-color: #F9F9F9; } #cal-grid > div:nth-child(-n+7) { background-color: transparent !important; } .settings-close { background: var(--btn-bg) !important; color: var(--text-secondary) !important; } .po-folder-header { background: var(--card-bg) !important; } .po-folder { border-color: rgba(0,0,0,0.05) !important; } #hidden-section-divider, #hidden-plugins-container { background: rgba(0,0,0,0.02) !important; border-top-color: rgba(0,0,0,0.04) !important; } .pm-accordion-header { background: var(--card-bg) !important; border-color: rgba(0,0,0,0.05) !important; } .plugin-tray { background: rgba(0,0,0,0.01) !important; } [id^='todo-list-wrap'], #todo-pinned-wrapper > div { background-color: #FFFBE6 !important; border-color: #F5E8B0 !important; }"
    ],
    'slateFrost' => [
        'name' => "Slate Frost (Eco-Glass)",
        'vars' => [
            "--bg-color" => "#20242c",
            "--card-bg" => "rgba(255, 255, 255, 0.04)",
            "--header-bg" => "rgba(32, 36, 44, 0.95)",
            "--text-primary" => "#F3F4F6",
            "--text-secondary" => "#9CA3AF",
            "--text-title" => "#FFFFFF",
            "--primary" => "#93C5FD",
            "--btn-bg" => "rgba(255, 255, 255, 0.07)",
            "--btn-text" => "#F3F4F6",
            "--input-bg" => "rgba(255, 255, 255, 0.03)",
            "--input-text" => "#FFFFFF",
            "--primary-text" => "#1E293B",
            "--border-color" => "rgba(255, 255, 255, 0.12)",
            "--shadow-card" => "0 8px 32px rgba(0, 0, 0, 0.15)",
            "--ai-accent" => "#C084FC",
            "--ai-accent-bg" => "rgba(192, 132, 252, 0.12)",
            "--glass-bg" => "rgba(255, 255, 255, 0.03)",
            "--glass-border" => "rgba(255, 255, 255, 0.15)",
            "--shadow-floating" => "0 16px 48px rgba(0, 0, 0, 0.3)",
            "--selected-bg" => "rgba(147, 197, 253, 0.12)",
            "--selected-text" => "#FFFFFF",
            "--player-active" => "#93C5FD"
        ],
        'extra' => ".app-frame { background: linear-gradient(135deg, #242933 0%, #171b22 100%) !important; } .scroll-view { background: transparent !important; } .card, .top-bar, .selection-bottom-bar, .settings-sheet, #shared-picker-sheet, #shared-confirm-sheet, #shared-input-sheet, .po-folder, .player-capsule, .done-btn, #organizer-bar-wrapper { background: linear-gradient(135deg, rgba(255, 255, 255, 0.06) 0%, rgba(255, 255, 255, 0.02) 100%) !important; border: 1px solid rgba(255, 255, 255, 0.14) !important; box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.25), inset 1px 1px 0px rgba(255, 255, 255, 0.05) !important; backdrop-filter: none !important; -webkit-backdrop-filter: none !important; } #organizer-bar-wrapper { background: rgba(32, 36, 44, 0.95) !important; border-top: none !important; } .section-header { background: transparent !important; color: #93C5FD !important; text-shadow: 0 1px 4px rgba(0,0,0,0.3); border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important; padding-top: 30px !important; } .org-chip { background: rgba(255, 255, 255, 0.04) !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; color: #9CA3AF !important; } .org-chip.folder-active { background: rgba(147, 197, 253, 0.15) !important; border-color: rgba(147, 197, 253, 0.4) !important; color: #93C5FD !important; } input[type=text], textarea, select { background: rgba(255, 255, 255, 0.03) !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; color: #FFFFFF !important; } .text-btn:not(.danger), .done-btn, .btn-primary:not(.danger) { background: rgba(255, 255, 255, 0.06) !important; border: 1px solid rgba(255, 255, 255, 0.15) !important; color: #FFFFFF !important; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15), inset 1px 1px 0px rgba(255, 255, 255, 0.05) !important; } .fab { background: linear-gradient(135deg, #374151 0%, #1F2937 100%) !important; border: 1px solid rgba(255, 255, 255, 0.18) !important; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3) !important; } .page-title { text-shadow: 0 0 12px rgba(147, 197, 253, 0.15); } .settings-group { background: rgba(255, 255, 255, 0.03) !important; border: 1px solid rgba(255, 255, 255, 0.08) !important; } .picker-item { background: rgba(255, 255, 255, 0.03) !important; border: 1px solid rgba(255, 255, 255, 0.08) !important; } .picker-item.selected { background: rgba(147, 197, 253, 0.15) !important; border-color: rgba(147, 197, 253, 0.4) !important; color: #93C5FD !important; }"
    ],
    'midnight' => [
        'name' => "Midnight OLED",
        'vars' => [
            "--range-thumb" => "#0A84FF", "--range-shadow" => "0 0 10px rgba(10, 132, 255, 0.4)",
            "--bg-color" => "#000000", "--card-bg" => "#1C1C1E", "--header-bg" => "rgba(0, 0, 0, 0.95)",
            "--text-primary" => "#FFFFFF", "--text-secondary" => "#98989D", "--text-title" => "#FFFFFF",
            "--primary" => "#0A84FF", "--btn-bg" => "#2C2C2E", "--btn-text" => "#FFFFFF",
            "--input-bg" => "#1C1C1E", "--input-text" => "#FFFFFF", "--primary-text" => "#FFFFFF",
            "--border-color" => "rgba(255, 255, 255, 0.12)", "--border-heavy" => "#3A3A3C",
            "--warn-bg" => "#2C2100", "--warn-text" => "#FFD60A", "--success-bg" => "#0E2E0E",
            "--success-text" => "#34C759", "--skeleton-bg" => "#2C2C2E", "--shadow-card" => "0 0 0 1px rgba(255,255,255,0.08)",
            "--ai-accent" => "#BF5AF2", "--ai-accent-bg" => "rgba(191, 90, 242, 0.2)",
            "--glass-bg" => "rgba(28, 28, 30, 0.85)", "--glass-border" => "rgba(255, 255, 255, 0.15)",
            "--shadow-floating" => "0 20px 60px rgba(0, 0, 0, 0.9)", "--selected-bg" => "#004085",
            "--selected-text" => "#FFFFFF", "--player-active" => "#0A84FF"
        ],
        'extra' => ".scroll-view, .app-frame { background-color: #000 !important; } .section-header { background-color: #000 !important; } input[type=text], textarea, select { background: var(--input-bg) !important; color: var(--input-text) !important; border-color: var(--border-color) !important; } .org-chip { background-color: #1C1C1E !important; color: #98989D !important; } .org-chip.pinned { background-color: #2C2C2E !important; } .org-chip.folder-active { background-color: var(--primary) !important; color: white !important; } .tool-card { background: #1C1C1E !important; border-color: #2C2C2E !important; } .tool-title { color: white !important; } .dash-widget > div { background: #1C1C1E !important; color: white !important; } .todo-text { color: white !important; } [id^='todo-list-wrap'], #todo-pinned-wrapper > div { background: #1C1C1E !important; border-color: #2C2C2E !important; } #cal-grid > div:not(:empty) { background-color: #1C1C1E !important; color: white !important; } #cal-grid > div.today { border-color: var(--primary) !important; } .settings-sheet, .settings-header, .accordion-inner { background: #000 !important; } .settings-sheet, #shared-picker-sheet, #shared-input-sheet, #ai-manager-sheet, #folder-manager-sheet, #folder-move-sheet, #draft-pad-card { border-top: 1px solid rgba(255, 255, 255, 0.2) !important; } .plugin-tray { background: #161618 !important; box-shadow: inset 0 2px 10px rgba(0,0,0,0.5) !important; } .settings-group { background: #121214 !important; border: 1px solid #1C1C1E !important; } .setting-item { border-bottom: none !important; } .setting-label { color: white !important; } .po-folder { border-color: #2C2C2E !important; background: #000 !important; } .po-folder-header { background: #1C1C1E !important; color: #FFFFFF !important; border-bottom-color: #2C2C2E !important; } .settings-close { background: #2C2C2E !important; color: white !important; } #el-header-history-btn { transition: all 0.3s ease !important; } #el-header-history-btn[title*='Pending'] { color: #FF3B30 !important; background: #333333 !important; border: 1px solid #FF3B30 !important; } .color-chip { background: var(--btn-bg) !important; color: var(--btn-text) !important; border-color: var(--border-color) !important; } #po-tools-header input { background: #1C1C1E !important; color: white !important; border-color: #2C2C2E !important; } #hidden-section-divider { background: #1C1C1E !important; border-top-color: #2C2C2E !important; } .po-drop-zone { background: var(--input-bg) !important; border-color: var(--border-color) !important; } #shared-picker-sheet, #shared-input-sheet, #shared-picker-title, #shared-input-title { background: #121214 !important; color: white !important; } .picker-item { background: #1C1C1E !important; color: white !important; border-color: #2C2C2E !important; } #draft-pad-card { background: rgba(18, 18, 20, 0.95) !important; border-color: #2C2C2E !important; } #draft-pad-header { background: #1C1C1E !important; } #draft-pad-input { color: white !important; }"
    ],
    'glass' => [
        'name' => "Glass Frost (Glassmorphism)",
        'vars' => [
            "--bg-color" => "transparent", "--card-bg" => "rgba(255, 255, 255, 0.25)", "--header-bg" => "rgba(255, 255, 255, 0.15)",
            "--text-primary" => "#FFFFFF", "--text-secondary" => "rgba(255, 255, 255, 0.7)", "--text-title" => "#FFFFFF",
            "--primary" => "#FFFFFF", "--btn-bg" => "rgba(255, 255, 255, 0.2)", "--btn-text" => "#FFFFFF",
            "--input-bg" => "rgba(255, 255, 255, 0.1)", "--input-text" => "#FFFFFF", "--primary-text" => "#1C1C1E",
            "--border-color" => "rgba(255, 255, 255, 0.3)", "--shadow-card" => "0 8px 32px rgba(0, 0, 0, 0.1)",
            "--ai-accent" => "#FFD60A", "--ai-accent-bg" => "rgba(255, 214, 10, 0.2)", "--glass-bg" => "rgba(255, 255, 255, 0.1)",
            "--glass-border" => "rgba(255, 255, 255, 0.4)", "--shadow-floating" => "0 20px 50px rgba(0, 0, 0, 0.2)",
            "--selected-bg" => "rgba(255, 255, 255, 0.35)", "--selected-text" => "#FFFFFF",
            "--warn-bg" => "rgba(255, 149, 0, 0.2)", "--warn-text" => "#FFD60A", "--success-bg" => "rgba(52, 199, 89, 0.2)",
            "--success-text" => "#34C759", "--skeleton-bg" => "rgba(255, 255, 255, 0.15)"
        ],
        'extra' => ".app-frame { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; } .scroll-view { background: transparent !important; } .org-chip.folder-active, .org-chip.smart-active { background: rgba(255, 255, 255, 0.4) !important; border: 1px solid rgba(255, 255, 255, 0.5) !important; color: #FFFFFF !important; } .picker-item.selected { background: rgba(255, 255, 255, 0.3) !important; border-color: rgba(255, 255, 255, 0.5) !important; color: #FFFFFF !important; } .seq-badge { background: rgba(0, 0, 0, 0.3) !important; backdrop-filter: blur(15px) saturate(150%) !important; -webkit-backdrop-filter: blur(15px) saturate(150%) !important; color: #FFFFFF !important; border: 1px solid rgba(255, 255, 255, 0.2) !important; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3) !important; font-weight: 800 !important; text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3); } .card, .top-bar, .selection-bottom-bar, .settings-sheet, #shared-picker-sheet, #shared-confirm-sheet, #shared-input-sheet, .po-folder, .player-capsule, .done-btn, #organizer-bar-wrapper { backdrop-filter: blur(25px) saturate(180%) !important; -webkit-backdrop-filter: blur(25px) saturate(180%) !important; border: 1px solid rgba(255, 255, 255, 0.3) !important; box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.2) !important; } #organizer-bar-wrapper { background: var(--header-bg) !important; border-top: none !important; } #organizer-search-row { background: transparent !important; } .section-header { background: linear-gradient(to bottom, rgba(255, 255, 255, 0.18) 0%, rgba(255, 255, 255, 0.08) 80%, transparent 100%) !important; backdrop-filter: blur(15px) !important; -webkit-backdrop-filter: blur(15px) !important; color: #FFFFFF !important; text-shadow: 0 2px 8px rgba(0,0,0,0.2); border: none !important; padding-top: 30px !important; padding-bottom: 20px !important; -webkit-mask-image: linear-gradient(to bottom, black 60%, transparent 100%); mask-image: linear-gradient(to bottom, black 60%, transparent 100%); } .bar-title, .page-title { text-shadow: 0 2px 10px rgba(0,0,0,0.3); } .time-badge { color: rgba(255, 255, 255, 0.9) !important; } .transcription { color: #FFFFFF !important; } .settings-header, .accordion-inner { background: transparent !important; } .settings-group { background: rgba(255, 255, 255, 0.1) !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; } .plugin-tray { background: rgba(0, 0, 0, 0.1) !important; } input[type=text], textarea, select { background: rgba(255, 255, 255, 0.08) !important; backdrop-filter: blur(10px) !important; -webkit-backdrop-filter: blur(10px) !important; color: white !important; border: 1px solid rgba(255, 255, 255, 0.2) !important; } ::placeholder { color: rgba(255, 255, 255, 0.5) !important; } .icon-btn, .bar-action-btn { color: white !important; } .text-btn:not(.danger), .done-btn, .btn-primary:not(.danger), .btn-mini:not(.red) { background: rgba(255, 255, 255, 0.15) !important; backdrop-filter: blur(12px) !important; -webkit-backdrop-filter: blur(12px) !important; color: white !important; border: 1px solid rgba(255, 255, 255, 0.25) !important; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1) !important; } .text-btn:active, .done-btn:active, .btn-primary:active { transform: scale(0.97) !important; background: rgba(255, 255, 255, 0.25) !important; } .text-btn.danger, .bar-action-btn.danger, .btn-primary.danger { background: rgba(255, 59, 48, 0.3) !important; backdrop-filter: blur(25px) saturate(160%) !important; -webkit-backdrop-filter: blur(25px) saturate(160%) !important; border: 1px solid rgba(255, 255, 255, 0.25) !important; color: #FFFFFF !important; text-shadow: 0 1px 4px rgba(0,0,0,0.3); } .slider { background-color: rgba(255, 255, 255, 0.1) !important; backdrop-filter: blur(5px) !important; -webkit-backdrop-filter: blur(5px) !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; } input:checked + .slider { background-color: rgba(255, 255, 255, 0.4) !important; } .slider:before { background-color: #FFFFFF !important; box-shadow: 0 2px 8px rgba(0,0,0,0.2) !important; }"
    ],
    'obsidianSlab' => [
        'name' => "Obsidian Slab (Dark Grey)",
        'vars' => [
            "--bg-color" => "#374151", "--card-bg" => "rgba(0, 0, 0, 0.25)", "--header-bg" => "rgba(55, 65, 81, 0.6)",
            "--text-primary" => "#FFFFFF", "--text-secondary" => "rgba(255, 255, 255, 0.5)", "--text-title" => "#FFFFFF",
            "--primary" => "#94A3B8", "--btn-bg" => "rgba(255, 255, 255, 0.1)", "--btn-text" => "#FFFFFF",
            "--input-bg" => "rgba(0, 0, 0, 0.15)", "--input-text" => "#FFFFFF", "--primary-text" => "#0F172A",
            "--border-color" => "rgba(255, 255, 255, 0.2)", "--shadow-card" => "0 8px 32px rgba(0, 0, 0, 0.3)",
            "--ai-accent" => "#FFFFFF", "--ai-accent-bg" => "rgba(255, 255, 255, 0.2)", "--glass-bg" => "rgba(0, 0, 0, 0.2)",
            "--glass-border" => "rgba(255, 255, 255, 0.25)", "--shadow-floating" => "0 20px 60px rgba(0, 0, 0, 0.5)",
            "--selected-bg" => "rgba(255, 255, 255, 0.2)", "--selected-text" => "#FFFFFF", "--player-active" => "rgba(255, 255, 255, 0.3)"
        ],
        'extra' => ".app-frame { background: linear-gradient(135deg, #4B5563 0%, #1F2937 100%) !important; } .scroll-view { background: transparent !important; } .page-title, .bar-title, .transcription, .setting-label { text-shadow: 0 0 10px rgba(255, 255, 255, 0.4); letter-spacing: 0.03em; } .card, .top-bar, .selection-bottom-bar, .settings-sheet, #shared-picker-sheet, #shared-confirm-sheet, #shared-input-sheet, .po-folder, .player-capsule, .done-btn, #organizer-bar-wrapper { backdrop-filter: blur(35px) saturate(140%) !important; -webkit-backdrop-filter: blur(35px) saturate(140%) !important; border: 0.5px solid rgba(255, 255, 255, 0.25) !important; box-shadow: 0 12px 40px 0 rgba(0, 0, 0, 0.3) !important; border-radius: 12px !important; } .section-header { background: transparent !important; color: rgba(255, 255, 255, 0.7) !important; text-transform: uppercase; font-size: 10px !important; letter-spacing: 4px !important; border-bottom: 0.5px solid rgba(255, 255, 255, 0.15) !important; padding-top: 30px !important; } .org-chip { background: rgba(0, 0, 0, 0.2) !important; border: 0.5px solid rgba(255, 255, 255, 0.15) !important; color: #FFFFFF !important; border-radius: 8px !important; } .org-chip.folder-active { background: rgba(255, 255, 255, 0.25) !important; } input[type=text], textarea, select { background: rgba(0, 0, 0, 0.2) !important; border: 0.5px solid rgba(255, 255, 255, 0.2) !important; color: white !important; border-radius: 8px !important; } ::placeholder { color: rgba(255, 255, 255, 0.3) !important; } .done-btn, .btn-primary { background: rgba(255, 255, 255, 0.15) !important; color: white !important; border: 0.5px solid rgba(255, 255, 255, 0.3) !important; } .fr-action-zone:not(.active) { background: rgba(0, 0, 0, 0.3) !important; border: 0.5px solid rgba(255, 255, 255, 0.2) !important; color: white !important; } .fab.ft-active { background: rgba(31, 41, 55, 0.8) !important; border-color: rgba(255,255,255,0.1) !important; } #ft-label { color: #FFFFFF !important; } .seq-badge { background: #FFFFFF !important; color: #111827 !important; border: none !important; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5) !important; font-weight: 900 !important; border-radius: 4px !important; }"
    ],
    'slab' => [
        'name' => "Engraved Slab (Sci-Fi)",
        'vars' => [
            "--bg-color" => "#9CA3AF", "--card-bg" => "rgba(255, 255, 255, 0.15)", "--header-bg" => "rgba(156, 163, 175, 0.5)",
            "--text-primary" => "#FFFFFF", "--text-secondary" => "rgba(255, 255, 255, 0.6)", "--text-title" => "#FFFFFF",
            "--primary" => "#FFFFFF", "--btn-bg" => "rgba(255, 255, 255, 0.1)", "--btn-text" => "#FFFFFF",
            "--input-bg" => "rgba(255, 255, 255, 0.05)", "--input-text" => "#FFFFFF", "--primary-text" => "#4B5563",
            "--border-color" => "rgba(255, 255, 255, 0.3)", "--shadow-card" => "0 4px 30px rgba(0, 0, 0, 0.1)",
            "--ai-accent" => "#FFFFFF", "--ai-accent-bg" => "rgba(255, 255, 255, 0.2)", "--glass-bg" => "rgba(255, 255, 255, 0.1)",
            "--glass-border" => "rgba(255, 255, 255, 0.4)", "--shadow-floating" => "0 15px 45px rgba(0, 0, 0, 0.2)",
            "--selected-bg" => "rgba(255, 255, 255, 0.25)", "--selected-text" => "#FFFFFF", "--player-active" => "rgba(255, 255, 255, 0.4)"
        ],
        'extra' => ".app-frame { background: linear-gradient(135deg, #D1D5DB 0%, #9CA3AF 100%) !important; } .scroll-view { background: transparent !important; } .page-title, .bar-title, .transcription, .setting-label { text-shadow: 0 0 8px rgba(255, 255, 255, 0.3); letter-spacing: 0.02em; } .card, .top-bar, .selection-bottom-bar, .settings-sheet, #shared-picker-sheet, #shared-confirm-sheet, #shared-input-sheet, .po-folder, .player-capsule, .done-btn, #organizer-bar-wrapper { backdrop-filter: blur(30px) saturate(150%) !important; -webkit-backdrop-filter: blur(30px) saturate(150%) !important; border: 0.5px solid rgba(255, 255, 255, 0.4) !important; box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.05) !important; border-radius: 12px !important; } .section-header { background: transparent !important; color: rgba(255, 255, 255, 0.8) !important; text-transform: uppercase; font-size: 10px !important; letter-spacing: 3px !important; border-bottom: 0.5px solid rgba(255, 255, 255, 0.2) !important; padding-top: 30px !important; } .org-chip { background: rgba(255, 255, 255, 0.1) !important; border: 0.5px solid rgba(255, 255, 255, 0.2) !important; color: #FFFFFF !important; border-radius: 8px !important; } .org-chip.folder-active { background: rgba(255, 255, 255, 0.3) !important; } input[type=text], textarea, select { background: rgba(255, 255, 255, 0.05) !important; border: 0.5px solid rgba(255, 255, 255, 0.3) !important; color: white !important; border-radius: 8px !important; } ::placeholder { color: rgba(255, 255, 255, 0.4) !important; } .done-btn, .btn-primary { background: rgba(255, 255, 255, 0.2) !important; color: white !important; font-weight: 800 !important; text-transform: uppercase; letter-spacing: 1px; } .fr-action-zone:not(.active) { background: rgba(255, 255, 255, 0.1) !important; border: 0.5px solid rgba(255, 255, 255, 0.3) !important; color: white !important; } .seq-badge { background: #1F2937 !important; color: #FFFFFF !important; border: 0.5px solid rgba(255, 255, 255, 0.4) !important; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3) !important; font-weight: 900 !important; border-radius: 4px !important; }"
    ],
    'neonSign' => [
        'name' => "Neon Sign (Electric)",
        'vars' => [
            "--bg-color" => "#020205", "--card-bg" => "#08080C", "--header-bg" => "rgba(2, 2, 5, 0.45)",
            "--text-primary" => "#E0F7FA", "--text-secondary" => "#00E5FF", "--text-title" => "#FF007A",
            "--primary" => "#00E5FF", "--btn-bg" => "#101018", "--btn-text" => "#00E5FF",
            "--input-bg" => "#000000", "--input-text" => "#00E5FF", "--primary-text" => "#000000",
            "--border-color" => "rgba(0, 229, 255, 0.4)", "--shadow-card" => "0 0 15px rgba(0, 229, 255, 0.2)",
            "--ai-accent" => "#39FF14", "--ai-accent-bg" => "rgba(57, 255, 20, 0.1)", "--player-active" => "#FF007A",
            "--success-bg" => "#051A05", "--success-text" => "#39FF14"
        ],
        'extra' => "@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap'); @keyframes neon-flicker { 0%, 18%, 22%, 25%, 53%, 57%, 100% { opacity: 1; filter: drop-shadow(0 0 10px currentColor) drop-shadow(0 0 20px currentColor); } 20%, 24%, 55% { opacity: 0.7; filter: none; } } @keyframes neon-smoke-layer-1 { 0% { transform: translate(-10%, -10%) scale(1) rotate(0deg); opacity: 0.4; } 50% { transform: translate(10%, 5%) scale(1.2) rotate(5deg); opacity: 0.7; } 100% { transform: translate(-10%, -10%) scale(1) rotate(0deg); opacity: 0.4; } } @keyframes neon-smoke-layer-2 { 0% { transform: translate(10%, 10%) scale(1.1) rotate(0deg); opacity: 0.3; } 50% { transform: translate(-5%, -10%) scale(1.3) rotate(-8deg); opacity: 0.6; } 100% { transform: translate(10%, 10%) scale(1.1) rotate(0deg); opacity: 0.3; } } * { font-family: 'Orbitron', sans-serif !important; } .app-frame { position: relative; overflow: hidden; background: #020205 !important; } .app-frame::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(ellipse at 30% 40%, rgba(26, 16, 61, 0.9) 0%, transparent 60%); animation: neon-smoke-layer-1 12s ease-in-out infinite; pointer-events: none; z-index: 0; } .app-frame::after { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(ellipse at 70% 60%, rgba(45, 10, 61, 0.6) 0%, transparent 50%); animation: neon-smoke-layer-2 18s ease-in-out infinite; pointer-events: none; z-index: 0; } .scroll-view { position: relative; z-index: 1; background: transparent !important; } .top-bar, .title-container, .title-wrapper { overflow: visible !important; } .top-bar { background: rgba(2, 2, 5, 0.5) !important; backdrop-filter: blur(24px) saturate(180%) !important; -webkit-backdrop-filter: blur(24px) saturate(180%) !important; border-bottom: 1px solid rgba(0, 229, 255, 0.25) !important; } .app-logo { color: #00E5FF !important; filter: drop-shadow(0 0 8px #00E5FF); animation: neon-flicker 5s linear infinite; } .bar-title { color: #00E5FF !important; text-shadow: 0 0 10px #00E5FF, 0 0 20px #00E5FF; animation: neon-flicker 7s linear infinite 0.5s; font-style: normal !important; } .page-title { color: #FF007A !important; text-shadow: 0 0 10px #FF007A, 0 0 20px #FF007A; font-weight: 900 !important; font-style: normal !important; animation: neon-flicker 6s linear infinite; } #organizer-bar-wrapper { background: rgba(2, 2, 5, 0.4) !important; backdrop-filter: blur(15px) !important; -webkit-backdrop-filter: blur(15px) !important; border-top: 1px solid rgba(0, 229, 255, 0.2) !important; border-bottom: 1px solid rgba(0, 229, 255, 0.2) !important; } #organizer-search-row { background: transparent !important; } .card { border: 2px solid #00E5FF !important; box-shadow: 0 0 15px rgba(0, 229, 255, 0.4), inset 0 0 10px rgba(0, 229, 255, 0.1) !important; border-radius: 12px !important; background: rgba(8, 8, 12, 0.9) !important; backdrop-filter: blur(10px); } .section-header { color: #39FF14 !important; text-shadow: 0 0 10px #39FF14; background: transparent !important; padding-top: 35px !important; font-weight: 900 !important; animation: neon-flicker 8s linear infinite reverse; } .done-btn, .btn-primary { background: transparent !important; border: 2px solid #39FF14 !important; color: #39FF14 !important; text-shadow: 0 0 5px #39FF14; box-shadow: 0 0 15px rgba(57, 255, 20, 0.4) !important; text-transform: uppercase; letter-spacing: 2px; } .done-btn:active { background: #39FF14 !important; color: #000 !important; } .fab { background: transparent !important; border: 3px solid #FF007A !important; color: #FF007A !important; box-shadow: 0 0 20px #FF007A, inset 0 0 10px #FF007A !important; animation: neon-flicker 4s linear infinite; } .transcription { text-shadow: 0 0 2px rgba(0, 229, 255, 0.5); color: #B2EBF2 !important; } .org-chip { border: 1px solid #00E5FF !important; background: transparent !important; color: #00E5FF !important; } .org-chip.folder-active { background: #00E5FF !important; color: #000 !important; box-shadow: 0 0 15px #00E5FF !important; } .time-badge { color: #FF007A !important; font-weight: 800 !important; } .meta-badge { border: 1px solid currentColor !important; background: transparent !important; } .settings-sheet, #shared-picker-sheet { border-top: 3px solid #FF007A !important; box-shadow: 0 -10px 40px rgba(255, 0, 122, 0.4) !important; background: #050508 !important; } .slider:before { background: #00E5FF !important; box-shadow: 0 0 10px #00E5FF !important; }"
    ],
    'memphis' => [
        'name' => "Memphis Pop (Artistic)",
        'vars' => [
            "--bg-color" => "#FFF200", 
            "--card-bg" => "#FFFFFF", 
            "--header-bg" => "rgba(255, 102, 204, 0.95)",
            "--text-primary" => "#000000", 
            "--text-secondary" => "#9027FF", 
            "--text-title" => "#000000",
            "--primary" => "#00FFFF", 
            "--btn-bg" => "#9027FF", 
            "--btn-text" => "#FFFFFF",
            "--input-bg" => "#FFFFFF", 
            "--input-text" => "#000000", 
            "--primary-text" => "#000000",
            "--border-color" => "#000000", 
            "--shadow-card" => "8px 8px 0px #000000",
            "--ai-accent" => "#FF66CC", 
            "--ai-accent-bg" => "#9027FF",
            "--player-active" => "#FF66CC"
        ],
        'extra' => "
            @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;700;900&display=swap');
            * { font-family: 'Space Grotesk', sans-serif !important; border-radius: 0px !important; }
            .app-frame { 
                background-color: #FFF200 !important;
                background-image: 
                    radial-gradient(#000 12%, transparent 12%),
                    radial-gradient(#000 12%, transparent 12%) !important;
                background-size: 36px 36px !important;
                background-position: 0 0, 18px 18px !important;
            }
            .scroll-view { background: transparent !important; }
            
            /* CARDS & HEADERS */
            .card { 
                border: 3px solid #000 !important; 
                transform: rotate(-1deg);
                margin-bottom: 25px !important;
                box-shadow: 8px 8px 0px #000 !important;
            }
            .card:nth-child(even) { transform: rotate(1.5deg); }
            .section-header { 
                background: #00FFFF !important; 
                color: #000 !important; 
                border: 3px solid #000 !important;
                display: table !important;
                padding: 5px 15px !important;
                margin-bottom: 15px !important;
                font-weight: 900 !important;
                text-transform: uppercase;
                box-shadow: 5px 5px 0px #000 !important;
                position: sticky !important;
                top: 0 !important;
                z-index: 95 !important;
            }
            .page-title { 
                background: #FF66CC !important; 
                color: #FFFFFF !important; 
                padding: 10px 20px !important; 
                border: 4px solid #000 !important;
                box-shadow: 10px 10px 0px #9027FF !important;
                font-weight: 900 !important;
                font-style: normal !important;
                text-transform: uppercase;
                display: table !important;
                transform: rotate(-1.5deg) translateX(10px) !important;
            }
            
            /* INTERACTABLES */
            .fab { 
                border: 4px solid #000 !important; 
                box-shadow: 8px 8px 0px #000 !important;
                background: #FF66CC !important;
                color: #FFF !important;
            }
            .fab:active { transform: translateX(-50%) translate(3px, 3px) !important; box-shadow: 2px 2px 0px #000 !important; }
            .custom-checkbox { border: 3px solid #000 !important; background: #FFF !important; }
            .custom-checkbox.checked { background: #00FFFF !important; }
            .org-chip, .btn-primary, .done-btn, .text-btn { 
                border: 3px solid #000 !important;
                background: #00FFFF !important;
                color: #000 !important;
                font-weight: 900 !important;
                box-shadow: 4px 4px 0px #000 !important;
                text-transform: uppercase;
            }
            .org-chip.folder-active { background: #FF66CC !important; color: #FFF !important; }
            
            /* PLAYER & METADATA */
            .player-capsule {
                border: 2px solid #000 !important;
                background: #FFFFFF !important;
                box-shadow: 4px 4px 0px #000 !important;
            }
            .player-capsule.is-playing { background: #FF66CC !important; }
            .meta-badge { 
                border: 2px solid #000 !important; 
                background: #FFF !important; 
                color: #000 !important; 
                font-weight: 900 !important;
                box-shadow: 3px 3px 0px #000 !important;
            }
            .meta-badge.sui-badge-ai { background: #9027FF !important; color: #FFF !important; }
            
            /* SYSTEM BARS */
            .top-bar, .selection-bottom-bar { border-bottom: 5px solid #000 !important; background: #FF66CC !important; }
            .selection-bottom-bar { border-top: 5px solid #000 !important; border-bottom: none !important; }
            .bar-action-btn { color: #000 !important; font-weight: 900 !important; }
            
            /* INPUTS & SLIDERS */
            input[type=text], textarea, select { border: 3px solid #000 !important; box-shadow: 4px 4px 0px #9027FF !important; }
            .slider { background-color: #FFF !important; border: 3px solid #000 !important; }
            .slider:before { border: 3px solid #000 !important; background: #FFF200 !important; box-shadow: none !important; }
            input:checked + .slider { background-color: #00FFFF !important; }

            .time-badge { color: #000 !important; font-weight: 900 !important; letter-spacing: 0px !important; }
            .transcription { font-weight: 700 !important; color: #000 !important; line-height: 1.3 !important; }

            /* DASHBOARD RADICAL STYLE */
            .dash-widget > div { 
                border: 4px solid #000 !important; 
                background: #FFF200 !important; 
                box-shadow: 10px 10px 0px #9027FF !important;
                transform: rotate(-0.5deg) !important;
            }
            .tool-card { 
                border: 3px solid #000 !important; 
                background: #FFFFFF !important; 
                box-shadow: 6px 6px 0px #00FFFF !important;
                transform: rotate(1deg) !important;
            }
            .tool-title { color: #000 !important; font-weight: 900 !important; text-transform: uppercase; }

            /* TO-DO LIST RADICAL STYLE */
            [id^='todo-list-wrap'], #todo-pinned-wrapper > div { 
                border: 4px solid #000 !important; 
                background: #00FFFF !important; 
                box-shadow: 12px 12px 0px #000 !important;
                transform: rotate(0.8deg) !important;
                margin-bottom: 30px !important;
            }
            .todo-item { 
                border-bottom: 2px solid #000 !important; 
                padding: 12px !important;
                background: #FFF !important;
            }
            .todo-text { color: #000 !important; font-weight: 700 !important; }

            /* AI HUB CHAT BUBBLES */
            .ai-bubble { 
                border: 4px solid #000 !important; 
                box-shadow: 6px 6px 0px #000 !important; 
                padding: 15px !important;
            }
            .ai-bubble-user { 
                background: #00FFFF !important; 
                color: #000 !important; 
                transform: rotate(1.5deg) !important; 
                margin-right: 10px !important;
            }
            .ai-bubble-asst { 
                background: #FF66CC !important; 
                color: #000 !important; 
                transform: rotate(-1.5deg) !important; 
                margin-left: 10px !important;
            }
            .ai-bubble-meta { color: #000 !important; font-weight: 900 !important; border-bottom: 2px solid #000; display: inline-block; }

            /* NUTRITION & CALORIE TRACKER */
            #cal-grid > div:not(:empty) { 
                border: 3px solid #000 !important; 
                background: #FFF !important; 
                box-shadow: 4px 4px 0px #9027FF !important;
                font-weight: 900 !important;
            }
            #cal-grid > div.today { background: #FFF200 !important; border-width: 4px !important; }
            .ai-nutrition-label { 
                border: 5px solid #000 !important; 
                box-shadow: 10px 10px 0px #FF66CC !important; 
                transform: rotate(1deg);
            }
            .cal-card { border: 3px solid #000 !important; box-shadow: 8px 8px 0px #00FFFF !important; }

            /* PROJECT PLANNER */
            .pp-project-card { 
                border: 4px solid #000 !important; 
                background: #FFFFFF !important; 
                box-shadow: 8px 8px 0px #9027FF !important; 
                transform: rotate(-1deg);
            }
            .pp-viewer { border: 4px solid #000 !important; background: #FFF !important; }

            /* DE-EXCEL-IFY NUTRITION TABLES */
            .ai-nutrition-label { background: #FFF200 !important; padding: 15px !important; }
            .ai-nutri-grid { 
                border: 3px solid #000 !important; 
                margin-bottom: -3px !important; 
                background: #FFFFFF !important; 
                padding: 8px !important;
                display: grid !important;
                grid-template-columns: 1fr 70px 60px !important;
            }
            .ai-nutri-grid:nth-child(odd) { background: #00FFFF !important; }
            .ai-nutri-grid:nth-child(even) { background: #FF66CC !important; }
            .ai-nutri-label-col, .ai-nutri-srv-col, .ai-nutri-100-col { color: #000 !important; font-weight: 900 !important; text-transform: uppercase; font-size: 10px !important; }

            /* THEME COVERAGE GRID (STAMP STYLE) */
            #tp-coverage-grid { gap: 12px !important; padding: 10px !important; }
            #tp-coverage-grid > div { 
                border: 3px solid #000 !important; 
                background: #FFFFFF !important; 
                box-shadow: 4px 4px 0px #9027FF !important; 
                transform: rotate(1.5deg);
                padding: 8px !important;
            }
            #tp-coverage-grid > div:nth-child(even) { transform: rotate(-2deg); background: #FFF200 !important; }

            /* SYSTEM CONSOLE & AUDIT LOGS */
            #ai-console { 
                border: 5px solid #000 !important; 
                background: #000000 !important; 
                color: #39FF14 !important; 
                box-shadow: 10px 10px 0px #FF66CC !important;
                font-family: 'Courier New', monospace !important;
                padding: 15px !important;
            }
            .ai-console-line { border-bottom: 1px dashed #39FF14; padding: 4px 0; }

            /* PICKER LISTS (FLOATING BUTTON STYLE) */
            #shared-picker-list { gap: 12px !important; padding-bottom: 40px !important; }
            .picker-item { 
                border: 4px solid #000 !important; 
                background: #FFFFFF !important; 
                box-shadow: 6px 6px 0px #000 !important; 
                margin-bottom: 5px !important;
                transition: all 0.1s !important;
            }
            .picker-item:active { transform: translate(3px, 3px) !important; box-shadow: 1px 1px 0px #000 !important; }
            .picker-item.selected { background: #FFF200 !important; border-color: #000 !important; }

            /* ACCORDIONS & SETTINGS GROUPS */
            .sui-accordion-inner { background: transparent !important; }
            .settings-group { 
                background: #FFFFFF !important; 
                border: 4px solid #000 !important; 
                box-shadow: 12px 12px 0px #9027FF !important;
                margin-top: 20px !important;
            }
            .setting-item { border-bottom: 2px solid #000 !important; }
            .setting-item:last-child { border-bottom: none !important; }
            
            /* PLUGIN ORGANIZER FOLDERS */
            .po-folder { 
                border: 4px solid #000 !important; 
                box-shadow: 8px 8px 0px #00FFFF !important; 
                transform: rotate(-0.5deg) !important;
                margin-bottom: 20px !important;
            }
            .po-folder-header { background: #FF66CC !important; color: #FFF !important; border-bottom: 4px solid #000 !important; }

            /* FIX: SETTINGS HEADER BUTTONS (Contrast Correction) */
            #settings-header-actions button, .settings-close, .sui-close-trigger, .sui-studio-close, [onclick*='closeInput'], [onclick*='closeSharedPicker'] {
                background: #FFFFFF !important;
                color: #000000 !important;
                border: 3px solid #000 !important;
                box-shadow: 3px 3px 0px #000 !important;
                opacity: 1 !important;
                border-radius: 0px !important;
            }
            #settings-header-actions button span, #settings-header-actions button svg {
                color: #000 !important;
                stroke: #000 !important;
            }
            #settings-header-actions button:active {
                transform: translate(2px, 2px) !important;
                box-shadow: 0px 0px 0px #000 !important;
            }

            /* FIX: PLUGIN ORGANIZER HIDDEN TEXT */
            #hidden-section-divider {
                background: #FFF200 !important;
                color: #000 !important;
                border-top: 4px solid #000 !important;
                border-bottom: 4px solid #000 !important;
                font-weight: 900 !important;
                text-transform: uppercase;
                letter-spacing: 1px;
                padding: 10px !important;
                margin-top: 20px !important;
            }
            #hidden-plugins-container { background: rgba(0,0,0,0.05) !important; }

            /* FIX: DASHBOARD ACTIVITY TOGGLES (Day/Week/Month) */
            [onclick*='fbCycleView'], .fb-cycle-btn, .fb-trend-toggle {
                background: #00FFFF !important;
                color: #000 !important;
                border: 3px solid #000 !important;
                box-shadow: 4px 4px 0px #9027FF !important;
                font-weight: 900 !important;
                text-transform: uppercase;
                font-size: 10px !important;
                padding: 5px 10px !important;
                cursor: pointer !important;
            }
            [onclick*='fbCycleView']:active {
                transform: translate(2px, 2px) !important;
                box-shadow: 1px 1px 0px #9027FF !important;
            }

            /* RESTORE RADICAL RED (Danger/Undo Actions) */
            .danger, .btn-primary.danger, .text-btn.danger, .bar-action-btn.danger, [onclick*='scConfirmRestore'], [onclick*='Undo'] {
                background: #FF3B30 !important;
                color: #FFFFFF !important;
                border: 3px solid #000 !important;
                box-shadow: 4px 4px 0px #000 !important;
                text-shadow: 2px 2px 0px #000;
                opacity: 1 !important;
            }
            .danger:active, [onclick*='scConfirmRestore']:active {
                transform: translate(2px, 2px) !important;
                box-shadow: 1px 1px 0px #000 !important;
            }
            
            /* Ensure icons inside danger buttons are white */
            .danger span, .danger svg, [onclick*='Undo'] span, [onclick*='Undo'] svg {
    color: #FFFFFF !important;
    stroke: #FFFFFF !important;
}

/* FIX: PATCHER UI CONTRAST */
#cp-btn-clear {
    background: #FFF200 !important;
    color: #000 !important;
    border: 2px solid #000 !important;
    box-shadow: 2px 2px 0px #000 !important;
    opacity: 1 !important;
    border-radius: 0px !important;
}
#cp-btn-clear span, #cp-btn-clear svg { color: #000 !important; stroke: #000 !important; }

#cp-btn-commit-all-bottom, #cp-bulk-actions button {
    background: #00FFFF !important;color: #000 !important;
    border: 3px solid #000 !important;
    box-shadow: 4px 4px 0px #9027FF !important;
    font-weight: 900 !important;
    text-transform: uppercase !important;
    opacity: 1 !important;
}
#cp-btn-commit-all-bottom:active, .cp-bulk-actions button:active {
    transform: translate(2px, 2px) !important;
    box-shadow: 1px 1px 0px #9027FF !important;
}
#cp-btn-commit-all-bottom:disabled, .cp-bulk-actions button:disabled {
    background: #E5E5EA !important;
    color: #8E8E93 !important;
    border-color: #8E8E93 !important;
    box-shadow: none !important;
    transform: none !important;
}
        "
    ],
    'cyber' => [
        'name' => "Cyberpunk Neon",
        'vars' => [
            "--bg-color" => "#050505", "--card-bg" => "#0D0D0D", "--header-bg" => "rgba(5, 5, 5, 0.95)",
            "--text-primary" => "#FFFFFF", "--text-secondary" => "#00F0FF", "--text-title" => "#FF007A",
            "--primary" => "#FF007A", "--primary-text" => "#FFFFFF", "--btn-bg" => "#1A1A1A",
            "--btn-text" => "#FFFFFF", "--input-bg" => "#000000", "--input-text" => "#00F0FF",
            "--border-color" => "rgba(0, 240, 240, 0.3)", "--shadow-card" => "0 0 15px rgba(255, 0, 122, 0.15)",
            "--ai-accent" => "#00F0FF", "--ai-accent-bg" => "rgba(0, 240, 255, 0.1)"
        ],
        'extra' => ".card { border-left: 4px solid var(--primary) !important; } .page-title { text-shadow: 0 0 10px var(--text-title); }"
    ],
    'forest' => [
        'name' => "Deep Forest",
        'vars' => [
            "--bg-color" => "#0F1412", "--card-bg" => "#1A231F", "--header-bg" => "rgba(15, 20, 18, 0.98)",
            "--text-primary" => "#E8F5E9", "--text-secondary" => "#81C784", "--text-title" => "#A5D6A7",
            "--primary" => "#4CAF50", "--btn-bg" => "#2E3D36", "--btn-text" => "#E8F5E9",
            "--input-bg" => "#0F1412", "--input-text" => "#E8F5E9", "--border-color" => "rgba(255, 255, 255, 0.05)"
        ],
        'extra' => ".card { border: 1px solid rgba(76, 175, 80, 0.15) !important; }"
    ],
    'paper' => [
        'name' => "Vintage Paper",
        'vars' => [
            "--bg-color" => "#F4F1EA", "--card-bg" => "#FCF9F2", "--header-bg" => "rgba(244, 241, 234, 0.98)",
            "--text-primary" => "#3C3836", "--text-secondary" => "#7C6F64", "--text-title" => "#3C3836",
            "--primary" => "#AF3A03", "--btn-bg" => "#EBE5D8", "--btn-text" => "#3C3836"
        ]
    ],
    'terminal' => [
        'name' => "Matrix Terminal",
        'vars' => [
            "--range-thumb" => "#00FF41", "--range-shadow" => "0 0 15px rgba(0, 255, 65, 0.5)",
            "--bg-color" => "#0D0D0D", "--card-bg" => "#000000", "--header-bg" => "rgba(13, 13, 13, 0.95)",
            "--text-primary" => "#00FF41", "--text-secondary" => "#008F11", "--text-title" => "#00FF41",
            "--primary" => "#00FF41", "--btn-bg" => "#1A1A1A", "--btn-text" => "#00FF41",
            "--input-bg" => "#050505", "--input-text" => "#00FF41", "--primary-text" => "#000000",
            "--border-color" => "#00FF41", "--border-heavy" => "#00FF41", "--warn-bg" => "#000000",
            "--warn-text" => "#00FF41", "--success-bg" => "#000000", "--success-text" => "#00FF41",
            "--skeleton-bg" => "#1A1A1A", "--shadow-card" => "0 0 0 1px #00FF41",
            "--ai-accent" => "#00FFFF", "--ai-accent-bg" => "rgba(0, 255, 255, 0.15)",
            "--glass-bg" => "rgba(0, 255, 65, 0.12)", "--glass-border" => "#00FF41",
            "--shadow-floating" => "0 0 20px rgba(0, 255, 65, 0.3)", "--selected-bg" => "#1A1A1A",
            "--selected-text" => "#00FF41", "--player-active" => "#00FF41"
        ],
        'extra' => "* { font-family: 'Courier New', monospace !important; } .card { border-radius: 0 !important; } .section-header { background-color: #0D0D0D !important; border-bottom: 1px solid #008F11 !important; } [id^='todo-list-wrap'], #todo-pinned-wrapper > div { background: #000 !important; border: 1px solid #00FF41 !important; color: #00FF41 !important; } .dash-widget > div { background: #000 !important; border: 1px solid #00FF41 !important; color: #00FF41 !important; } .tool-card { background: #000 !important; border-color: #00FF41 !important; color: #00FF41 !important; } .org-chip { background-color: #1A1A1A !important; border: 1px solid #008F11 !important; color: #00FF41 !important; } .settings-sheet, .settings-header, .accordion-inner { background: #000 !important; } .settings-sheet, #shared-picker-sheet, #shared-input-sheet, #ai-manager-sheet, #folder-manager-sheet, #folder-move-sheet, #draft-pad-card { border-top: 2px solid #00FF41 !important; } .plugin-tray { background: #1A1A1A !important; box-shadow: inset 0 4px 12px rgba(0,0,0,0.5) !important; } .settings-group { background: #000 !important; border: 1px solid #00FF41 !important; } .setting-item { border-bottom: none !important; } .shared-picker-sheet, .picker-item { background: #000 !important; border: 1px solid #00FF41 !important; color: #00FF41 !important; } .color-chip { background: #000 !important; color: #00FF41 !important; border-color: #00FF41 !important; } mark { background: #00FF41 !important; color: black !important; } input[type=text], textarea, .po-folder-header { background: var(--input-bg) !important; color: var(--input-text) !important; border: 1px solid var(--border-color) !important; }"
    ],
    'brutalist' => [
        'name' => "Brutalist Raw",
        'vars' => [
            "--range-thumb" => "#000000", "--range-shadow" => "4px 4px 0px rgba(0,0,0,1)",
            "--bg-color" => "#E0E0E0", "--card-bg" => "#FFFFFF", "--header-bg" => "#FFFFFF",
            "--text-primary" => "#000000", "--text-secondary" => "#000000", "--text-title" => "#000000",
            "--primary" => "#000000", "--primary-text" => "#FFFFFF", "--btn-bg" => "#FFFFFF",
            "--btn-text" => "#000000", "--input-bg" => "#FFFFFF", "--input-text" => "#000000",
            "--border-color" => "#000000", "--shadow-card" => "6px 6px 0px #000000",
            "--ai-accent" => "#000000", "--ai-accent-bg" => "#FFFF00", "--player-active" => "#007AFF"
        ],
        'extra' => "* { font-family: 'Courier New', Courier, monospace !important; border-radius: 0 !important; } .card:not(.section-marker-header), .top-bar, .selection-bottom-bar, .settings-sheet, .po-folder, .player-capsule, .done-btn, .org-chip, .tool-card, .dash-widget > div, [id^='todo-list-wrap'], #todo-pinned-wrapper > div, #shared-picker-sheet, #shared-input-sheet, #ai-manager-sheet, #folder-manager-sheet, #folder-move-sheet, #draft-pad-card { border: 3px solid #000 !important; box-shadow: 6px 6px 0px #000 !important; background-color: #FFF !important; backdrop-filter: none !important; } .card.section-marker-header { background-color: #000 !important; border: 3px solid #000 !important; } .section-header { background: #000 !important; color: #FFF !important; text-transform: uppercase; padding: 8px 12px !important; margin-bottom: 12px; border: none !important; letter-spacing: 2px; } .page-title { background: #FFFF00 !important; color: #000 !important; display: table !important; padding: 10px 20px !important; border: 3px solid #000 !important; box-shadow: 8px 8px 0px #000 !important; font-style: normal !important; margin-bottom: 20px !important; text-transform: uppercase; } .time-badge { font-weight: 900; color: #000 !important; } .done-btn { background: #000 !important; color: #FFF !important; box-shadow: 4px 4px 0px #666 !important; } input[type=text], textarea, select { border: 2px solid #000 !important; } #organizer-search-row > div { border: 1px solid #000 !important; box-shadow: 2px 2px 0px #000 !important; } .org-chip.folder-active { background: #000 !important; color: #FFFF00 !important; border-color: #000 !important; } .org-chip.smart-active { background: #000 !important; color: #00F0FF !important; border-color: #000 !important; } .app-frame { background-color: #E0E0E0 !important; } mark { background: #000 !important; color: #FFFF00 !important; } input[type=range] { border: none !important; box-shadow: none !important; background: transparent !important; } input[type=range]::-webkit-slider-runnable-track { background: #000 !important; height: 2px !important; } input[type=range]::-webkit-slider-thumb { border-radius: 0 !important; width: 16px !important; height: 16px !important; margin-top: -7px !important; border: 2px solid #000 !important; }"
    ],
    'lcd' => [
        'name' => "Retro LCD (GameBoy)",
        'vars' => [
            "--bg-color" => "#9bbc0f", "--card-bg" => "#8bac0f", "--header-bg" => "#9bbc0f",
            "--text-primary" => "#0f380f", "--text-secondary" => "#306230", "--text-title" => "#0f380f",
            "--primary" => "#306230", "--btn-bg" => "#8bac0f", "--btn-text" => "#0f380f",
            "--input-bg" => "#9bbc0f", "--input-text" => "#0f380f", "--primary-text" => "#9bbc0f",
            "--border-color" => "#0f380f", "--shadow-card" => "none", "--ai-accent" => "#306230",
            "--ai-accent-bg" => "rgba(48, 98, 48, 0.2)", "--glass-bg" => "#8bac0f",
            "--glass-border" => "#0f380f", "--player-active" => "#0f380f"
        ],
        'extra' => "* { font-family: 'Courier New', monospace !important; text-shadow: none !important; } .app-frame { background-color: #9bbc0f !important; background-image: radial-gradient(#8bac0f 0.5px, transparent 0.5px) !important; background-size: 3px 3px !important; } .scroll-view { background: transparent !important; } .card, .top-bar, .selection-bottom-bar, .settings-sheet, .po-folder, .player-capsule, .done-btn, .org-chip, .tool-card, .dash-widget > div, [id^='todo-list-wrap'], #todo-pinned-wrapper > div, #shared-picker-sheet, #shared-input-sheet, #ai-manager-sheet, #folder-manager-sheet, #folder-move-sheet, #draft-pad-card { border: 2px solid #0f380f !important; background-color: #8bac0f !important; box-shadow: none !important; backdrop-filter: none !important; border-radius: 2px !important; } .section-header { background: transparent !important; color: #0f380f !important; border-bottom: 2px solid #0f380f !important; text-transform: uppercase; font-weight: 900; letter-spacing: 1px; } .page-title { border-bottom: 4px double #0f380f !important; border-radius: 0 !important; display: table !important; margin-bottom: 20px !important; } .done-btn { background: #0f380f !important; color: #9bbc0f !important; } mark { background: #0f380f !important; color: #9bbc0f !important; } .time-badge { color: #0f380f !important; opacity: 0.8; font-weight: 800; } input[type=text], textarea, select { border: 2px solid #0f380f !important; border-radius: 0 !important; } svg { color: #0f380f !important; } .player-capsule.is-playing { background-color: #0f380f !important; } .player-capsule.is-playing .player-btn { color: #9bbc0f !important; } .player-divider { background-color: #0f380f !important; } .skel-line { background: #306230 !important; opacity: 0.3; } input[type=range] { border: none !important; box-shadow: none !important; background: transparent !important; } input[type=range]::-webkit-slider-runnable-track { background: #0f380f !important; height: 2px !important; } input[type=range]::-webkit-slider-thumb { border-radius: 0 !important; width: 16px !important; height: 16px !important; margin-top: -7px !important; background: #0f380f !important; border: none !important; }"
    ],
    'notebook' => [
        'name' => "Paper Notebook",
        'vars' => [
            "--bg-color" => "#fdfaf0", "--card-bg" => "#ffffff", "--header-bg" => "rgba(253, 250, 240, 0.95)",
            "--text-primary" => "#1d3557", "--text-secondary" => "#457b9d", "--text-title" => "#1d3557",
            "--primary" => "#e63946", "--btn-bg" => "#f1faee", "--btn-text" => "#1d3557",
            "--input-bg" => "#ffffff", "--input-text" => "#1d3557", "--primary-text" => "#ffffff",
            "--border-color" => "rgba(0,0,0,0.08)", "--shadow-card" => "1px 2px 5px rgba(0,0,0,0.05)",
            "--ai-accent" => "#5856D6", "--ai-accent-bg" => "rgba(88, 86, 214, 0.1)", "--player-active" => "#e63946"
        ],
        'extra' => "@import url('https://fonts.googleapis.com/css2?family=Indie+Flower&display=swap'); .page-title, .bar-title, .transcription, .todo-text, .section-header, .stack-title-text { font-family: 'Indie Flower', cursive !important; font-weight: 400 !important; } .transcription { font-size: 19px !important; line-height: 1.6 !important; } .page-title { font-size: 38px !important; color: var(--primary) !important; } .scroll-view { background-color: #fdfaf0 !important; background-image: linear-gradient(90deg, transparent 49px, #abced4 49px, #abced4 51px, transparent 51px), linear-gradient(#eee .1em, transparent .1em) !important; background-size: 100% 100%, 100% 1.6em !important; position: relative; } .app-frame::before { content: ''; position: absolute; top: 0; left: 50px; bottom: 0; width: 1px; background: rgba(230, 57, 70, 0.3); z-index: 10; pointer-events: none; } .card { border: 1px solid #e0e0e0 !important; border-radius: 1px !important; box-shadow: 2px 2px 0px rgba(0,0,0,0.05) !important; transform: rotate(-0.3deg); margin-left: 10px !important; } .card:nth-child(even) { transform: rotate(0.3deg); } .section-header { background: transparent !important; color: var(--primary) !important; font-size: 16px !important; text-decoration: underline; text-decoration-style: wavy; } .top-bar, .selection-bottom-bar { border-bottom: 2px solid var(--text-primary) !important; box-shadow: none !important; } .done-btn, .btn-primary { border-radius: 4px !important; border: 2px solid var(--text-primary) !important; box-shadow: 3px 3px 0px var(--text-primary) !important; } .player-capsule { background: transparent !important; border: 1px dashed var(--text-secondary) !important; }"
    ],
    'win95' => [
        'name' => "Windows 95",
        'vars' => [
            "--bg-color" => "#008080", "--card-bg" => "#C0C0C0", "--header-bg" => "#C0C0C0",
            "--text-primary" => "#000000", "--text-secondary" => "#000000", "--text-title" => "#FFFFFF",
            "--primary" => "#000080", "--btn-bg" => "#C0C0C0", "--btn-text" => "#000000",
            "--input-bg" => "#FFFFFF", "--input-text" => "#000000", "--primary-text" => "#FFFFFF",
            "--border-color" => "#808080", "--shadow-card" => "none", "--ai-accent" => "#000080",
            "--ai-accent-bg" => "#C0C0C0", "--player-active" => "#000080"
        ],
        'extra' => "* { border-radius: 0 !important; font-family: 'Tahoma', 'Arial', sans-serif !important; } .app-frame { background-color: #008080 !important; } .scroll-view { background: transparent !important; } .card { border: 2px solid !important; border-color: #dfdfdf #000 #000 #dfdfdf !important; background: #C0C0C0 !important; padding: 4px !important; margin-bottom: 20px !important; } .card-content { background: #C0C0C0 !important; border: 1px solid #fff !important; border-right-color: #808080 !important; border-bottom-color: #808080 !important; padding: 15px !important; } .section-header { background: #000080 !important; color: #FFFFFF !important; font-size: 12px !important; font-weight: bold !important; padding: 4px 10px !important; border: 1px solid #dfdfdf !important; border-bottom: 1px solid #000 !important; margin-bottom: 12px !important; letter-spacing: 0.5px !important; text-transform: none !important; } .top-bar, .selection-bottom-bar, .settings-sheet, #shared-picker-sheet, #shared-input-sheet, #draft-pad-card { background: #C0C0C0 !important; border: 2px solid !important; border-color: #dfdfdf #000 #000 #dfdfdf !important; box-shadow: none !important; } .done-btn, .btn-primary, .bar-action-btn, .icon-btn, .po-btn, .text-btn { background: #C0C0C0 !important; color: #000 !important; border: 2px solid !important; border-color: #dfdfdf #000 #000 #dfdfdf !important; box-shadow: none !important; font-size: 13px !important; } .done-btn:active, .btn-primary:active, .bar-action-btn:active { border-color: #000 #dfdfdf #dfdfdf #000 !important; transform: translate(1px, 1px) !important; } input[type=text], textarea, select { background: #FFFFFF !important; color: #000000 !important; border: 2px solid !important; border-color: #808080 #dfdfdf #dfdfdf #808080 !important; } .time-badge { color: #000 !important; font-family: monospace !important; font-size: 11px !important; } .player-capsule { background: #C0C0C0 !important; border: 1px solid #808080 !important; box-shadow: inset 1px 1px 0 #fff !important; } .player-btn { color: #000 !important; } .org-chip { background: #C0C0C0 !important; border: 2px solid !important; border-color: #dfdfdf #000 #000 #dfdfdf !important; color: #000 !important; border-radius: 0 !important; } .org-chip.folder-active { background: #000080 !important; color: #FFFFFF !important; }"
    ],
    'macosGraphite' => [
        'name' => "macOS Sonoma (Graphite)",
        'vars' => [
            "--bg-color" => "#2C2C2E", "--card-bg" => "rgba(63, 63, 70, 0.55)", "--header-bg" => "rgba(44, 44, 46, 0.8)",
            "--text-primary" => "#EBEBF5", "--text-secondary" => "#A1A1AA", "--text-title" => "#FFFFFF",
            "--primary" => "#A1A1AA", "--btn-bg" => "rgba(255, 255, 255, 0.08)", "--btn-text" => "#EBEBF5",
            "--input-bg" => "#3A3A3C", "--input-text" => "#FFFFFF", "--primary-text" => "#1C1C1E",
            "--border-color" => "rgba(255, 255, 255, 0.1)", "--shadow-card" => "0 8px 30px rgba(0, 0, 0, 0.3)",
            "--ai-accent" => "#D1D1D6", "--ai-accent-bg" => "rgba(255, 255, 255, 0.1)",
            "--glass-bg" => "rgba(63, 63, 70, 0.7)", "--glass-border" => "rgba(255, 255, 255, 0.15)",
            "--player-active" => "#A1A1AA", "--radius-card" => "14px"
        ],
        'extra' => "
            .app-frame { 
                background: linear-gradient(135deg, #3A3A3C 0%, #1C1C1E 100%) !important;
                position: relative;
            }
            .app-frame::before {
                content: ''; position: absolute; inset: 0;
                background: url('https://help.apple.com/assets/6500A7D439A3645065099395/6500A7D639A36450650993A3/en_US/934c194e9f743c4ec59473d09795167e.png') center/cover no-repeat;
                filter: grayscale(1) brightness(0.4) contrast(1.2); opacity: 0.6; z-index: 0;
            }
            .scroll-view { background: transparent !important; position: relative; z-index: 1; }
            .card { 
                backdrop-filter: blur(30px) saturate(140%) brightness(0.9) !important; 
                -webkit-backdrop-filter: blur(30px) saturate(140%) brightness(0.9) !important;
                border: 1px solid rgba(255, 255, 255, 0.12) !important;
                padding-top: 12px !important;
                box-shadow: 0 4px 20px rgba(0,0,0,0.2), inset 0 0 0 0.5px rgba(255,255,255,0.05) !important;
            }
            .card::before {
                content: ''; position: absolute; top: 14px; left: 14px;
                width: 10px; height: 10px; border-radius: 50%;
                background: #FF5F56; box-shadow: 16px 0 0 #FFBD2E, 32px 0 0 #27C93F;
                z-index: 10; opacity: 0.8;
            }
            .header-row { padding-left: 45px !important; }
            .top-bar { 
                backdrop-filter: blur(40px) brightness(0.7) !important;
                -webkit-backdrop-filter: blur(40px) brightness(0.7) !important;
                border-bottom: 1px solid rgba(255,255,255,0.08) !important;
            }
            .section-header { 
                background: transparent !important; color: #A1A1AA !important; 
                font-weight: 700 !important; padding-top: 35px !important;
            }
            .done-btn, .btn-primary { 
                border-radius: 8px !important; background: #F2F2F7 !important; color: #1C1C1E !important;
                box-shadow: 0 2px 8px rgba(0,0,0,0.2) !important; font-weight: 700 !important;
            }
            .org-chip { 
                background: rgba(255,255,255,0.1) !important; backdrop-filter: blur(10px) !important;
                border: 1px solid rgba(255,255,255,0.05) !important; border-radius: 8px !important; color: #EBEBF5 !important;
            }
            .org-chip.folder-active { background: rgba(255,255,255,0.2) !important; color: #FFF !important; }
            .settings-sheet, #shared-picker-sheet { 
                background: rgba(44, 44, 46, 0.9) !important; backdrop-filter: blur(50px) !important;
                -webkit-backdrop-filter: blur(50px) !important; border-top: 1px solid rgba(255,255,255,0.1) !important;
            }
        "
    ],
    'macosDark' => [
        'name' => "macOS Sonoma (Dark)",
        'vars' => [
            "--bg-color" => "#121212", "--card-bg" => "rgba(28, 28, 30, 0.7)", "--header-bg" => "rgba(20, 20, 20, 0.75)",
            "--text-primary" => "#F5F5F7", "--text-secondary" => "#8E8E93", "--text-title" => "#FFFFFF",
            "--primary" => "#0A84FF", "--btn-bg" => "rgba(255, 255, 255, 0.1)", "--btn-text" => "#F5F5F7",
            "--input-bg" => "#1C1C1E", "--input-text" => "#FFFFFF", "--primary-text" => "#FFFFFF",
            "--border-color" => "rgba(255, 255, 255, 0.12)", "--shadow-card" => "0 10px 40px rgba(0, 0, 0, 0.5)",
            "--ai-accent" => "#BF5AF2", "--ai-accent-bg" => "rgba(191, 90, 242, 0.2)",
            "--glass-bg" => "rgba(44, 44, 46, 0.8)", "--glass-border" => "rgba(255, 255, 255, 0.15)",
            "--player-active" => "#0A84FF", "--radius-card" => "14px"
        ],
        'extra' => "
            .app-frame { 
                background: url('https://raw.githubusercontent.com/zhifanzhu/resources/master/macos-sonoma-dark.jpg') center/cover no-repeat !important; 
                background-color: #000 !important;
            }
            .scroll-view { background: transparent !important; }
            .card { 
                backdrop-filter: blur(35px) saturate(160%) brightness(0.8) !important; 
                -webkit-backdrop-filter: blur(35px) saturate(160%) brightness(0.8) !important;
                border: 1px solid rgba(255, 255, 255, 0.15) !important;
                padding-top: 12px !important;
                box-shadow: 0 4px 24px rgba(0,0,0,0.4), inset 0 0 0 0.5px rgba(255,255,255,0.1) !important;
            }
            .card::before {
                content: '';
                position: absolute;
                top: 14px;
                left: 14px;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                background: #FF5F56;
                box-shadow: 16px 0 0 #FFBD2E, 32px 0 0 #27C93F;
                z-index: 10;
                opacity: 0.9;
            }
            .header-row { padding-left: 45px !important; }
            .top-bar { 
                backdrop-filter: blur(40px) saturate(180%) brightness(0.6) !important;
                -webkit-backdrop-filter: blur(40px) saturate(180%) brightness(0.6) !important;
                border-bottom: 1px solid rgba(255,255,255,0.1) !important;
            }

            .page-title { padding-top: 0 !important; margin-bottom: 20px !important; }
            .section-header { 
                background: transparent !important; 
                color: rgba(255,255,255,0.8) !important; 
                font-weight: 700 !important;
                padding-top: 35px !important;
                text-shadow: 0 1px 4px rgba(0,0,0,0.5);
            }
            .done-btn, .btn-primary { 
                border-radius: 8px !important; 
                background: #0A84FF !important;
                box-shadow: 0 4px 12px rgba(10, 132, 255, 0.3) !important;
            }
            .org-chip { 
                background: rgba(44, 44, 46, 0.7) !important;
                backdrop-filter: blur(15px) !important;
                border: 1px solid rgba(255,255,255,0.1) !important;
                border-radius: 8px !important;
                color: #FFF !important;
            }
            .org-chip.folder-active { background: #0A84FF !important; box-shadow: 0 0 10px rgba(10, 132, 255, 0.4) !important; }
            .player-capsule { border-radius: 8px !important; background: rgba(255,255,255,0.08) !important; }
            .settings-sheet, #shared-picker-sheet, #shared-input-sheet { 
                background: rgba(28, 28, 30, 0.85) !important;
                backdrop-filter: blur(50px) brightness(0.7) !important;
                -webkit-backdrop-filter: blur(50px) brightness(0.7) !important;
                border-top: 1px solid rgba(255,255,255,0.15) !important;
            }
            .setting-label { color: #FFF !important; }
            .settings-close { background: rgba(255,255,255,0.1) !important; color: #FFF !important; }
        "
    ],
    'macos' => [
        'name' => "macOS Sonoma (Lucent)",
        'vars' => [
            "--bg-color" => "#E2E2E2", "--card-bg" => "rgba(255, 255, 255, 0.55)", "--header-bg" => "rgba(236, 236, 236, 0.7)",
            "--text-primary" => "#1E1E1E", "--text-secondary" => "#6B6B6B", "--text-title" => "#000000",
            "--primary" => "#007AFF", "--btn-bg" => "rgba(0, 0, 0, 0.05)", "--btn-text" => "#1E1E1E",
            "--input-bg" => "#FFFFFF", "--input-text" => "#1E1E1E", "--primary-text" => "#FFFFFF",
            "--border-color" => "rgba(0, 0, 0, 0.1)", "--shadow-card" => "0 10px 30px rgba(0, 0, 0, 0.08)",
            "--ai-accent" => "#007AFF", "--ai-accent-bg" => "rgba(0, 122, 255, 0.1)",
            "--glass-bg" => "rgba(255, 255, 255, 0.4)", "--glass-border" => "rgba(0, 0, 0, 0.08)",
            "--player-active" => "#007AFF", "--radius-card" => "14px"
        ],
        'extra' => "
            .app-frame { 
                background: url('https://help.apple.com/assets/6500A7D439A3645065099395/6500A7D639A36450650993A3/en_US/934c194e9f743c4ec59473d09795167e.png') center/cover no-repeat !important; 
            }
            .scroll-view { background: transparent !important; }
            .card { 
                backdrop-filter: blur(25px) saturate(190%) !important; 
                -webkit-backdrop-filter: blur(25px) saturate(190%) !important;
                border: 1px solid rgba(255, 255, 255, 0.4) !important;
                padding-top: 12px !important;
            }
            /* Window Controls (Traffic Lights) */
            .card::before {
                content: '';
                position: absolute;
                top: 14px;
                left: 14px;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                background: #FF5F56;
                box-shadow: 16px 0 0 #FFBD2E, 32px 0 0 #27C93F;
                z-index: 10;
                opacity: 0.8;
            }
            .header-row { padding-left: 45px !important; }
            .top-bar { 
                backdrop-filter: blur(30px) saturate(150%) brightness(1.1) !important;
                -webkit-backdrop-filter: blur(30px) saturate(150%) brightness(1.1) !important;
                border-bottom: 1px solid rgba(0,0,0,0.1) !important;
            }

            .page-title { padding-top: 0 !important; margin-bottom: 20px !important; }
            .section-header { 
                background: transparent !important; 
                color: #000 !important; 
                font-weight: 800 !important;
                padding-top: 35px !important;
            }
            .done-btn, .btn-primary { 
                border-radius: 8px !important; 
                background: #007AFF !important;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.2) !important;
            }
            .org-chip { 
                background: rgba(255,255,255,0.5) !important;
                backdrop-filter: blur(10px) !important;
                border: 1px solid rgba(0,0,0,0.05) !important;
                border-radius: 8px !important;
            }
            .org-chip.folder-active { background: #007AFF !important; color: #FFF !important; }
            .player-capsule { border-radius: 8px !important; background: rgba(0,0,0,0.05) !important; }
            .settings-sheet, #shared-picker-sheet { 
                background: rgba(245, 245, 245, 0.85) !important;
                backdrop-filter: blur(40px) !important;
                -webkit-backdrop-filter: blur(40px) !important;
                border-top: 1px solid rgba(255,255,255,0.5) !important;
            }
        "
    ],
    'auraZen' => [
        'name' => "Aura Zen (2026 Trend)",
        'vars' => [
            "--bg-color" => "#0A0C10", "--card-bg" => "#12151C", "--header-bg" => "rgba(10, 12, 16, 0.94)",
            "--text-primary" => "#F1F5F9", "--text-secondary" => "#94A3B8", "--text-title" => "#FFFFFF",
            "--primary" => "#818CF8", "--primary-dark" => "#6366F1", "--btn-bg" => "#1E293B",
            "--btn-text" => "#F1F5F9", "--input-bg" => "#020617", "--input-text" => "#F1F5F9",
            "--primary-text" => "#FFFFFF", "--border-color" => "rgba(255, 255, 255, 0.06)",
            "--shadow-card" => "0 12px 40px rgba(0, 0, 0, 0.4)", "--ai-accent" => "#10B981",
            "--ai-accent-bg" => "rgba(16, 185, 129, 0.12)", "--glass-bg" => "rgba(18, 21, 28, 0.8)",
            "--glass-border" => "rgba(255, 255, 255, 0.1)", "--shadow-floating" => "0 20px 80px rgba(0, 0, 0, 0.6)",
            "--selected-bg" => "rgba(129, 140, 248, 0.15)", "--selected-text" => "#A5B4FC", "--player-active" => "#818CF8"
        ],
        'extra' => ".app-frame { background-color: #0A0C10 !important; } .scroll-view { background: radial-gradient(circle at 50% 0%, rgba(99, 102, 241, 0.08) 0%, transparent 70%) !important; } .card { border: 1px solid rgba(255, 255, 255, 0.05) !important; transition: transform 0.3s cubic-bezier(0.2, 0, 0.2, 1), border-color 0.3s !important; } .card:active { border-color: rgba(129, 140, 248, 0.4) !important; } .page-title { letter-spacing: -0.03em !important; font-family: -apple-system, BlinkMacSystemFont, sans-serif !important; font-weight: 800 !important; font-style: normal !important; background: linear-gradient(135deg, #FFF 0%, #94A3B8 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; } .section-header { background: transparent !important; backdrop-filter: none !important; color: #6366F1 !important; font-size: 11px !important; letter-spacing: 2px !important; padding-top: 40px !important; } .done-btn, .btn-primary { background: linear-gradient(135deg, #818CF8 0%, #6366F1 100%) !important; border: none !important; box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3) !important; } .player-capsule { background: rgba(255, 255, 255, 0.03) !important; border: 1px solid rgba(255, 255, 255, 0.05) !important; } .org-chip.folder-active { background: #818CF8 !important; color: white !important; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2) !important; } .meta-badge { border-radius: 100px !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; } ::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1) !important; }"
    ],
    'nebula' => [
        'name' => "Nebula Drift (Deep Space)",
        'vars' => [
            "--bg-color" => "#07090F", "--card-bg" => "#0D1120", "--header-bg" => "rgba(7, 9, 15, 0.45)",
            "--text-primary" => "#E8EAF6", "--text-secondary" => "#7986CB", "--text-title" => "#FFFFFF",
            "--primary" => "#7C6FD6", "--btn-bg" => "#151929", "--btn-text" => "#C5CAE9",
            "--input-bg" => "#050710", "--input-text" => "#E8EAF6", "--primary-text" => "#FFFFFF",
            "--border-color" => "rgba(124, 111, 214, 0.18)", "--shadow-card" => "0 4px 32px rgba(0, 0, 0, 0.6)",
            "--ai-accent" => "#26D9C7", "--ai-accent-bg" => "rgba(38, 217, 199, 0.12)",
            "--glass-bg" => "rgba(13, 17, 32, 0.8)", "--glass-border" => "rgba(124, 111, 214, 0.25)",
            "--shadow-floating" => "0 24px 80px rgba(0, 0, 0, 0.8)", "--selected-bg" => "rgba(124, 111, 214, 0.2)",
            "--selected-text" => "#B0BEF8", "--player-active" => "#7C6FD6",
            "--warn-bg" => "#1A1000", "--warn-text" => "#FFD740",
            "--success-bg" => "#001A14", "--success-text" => "#26D9C7",
            "--skeleton-bg" => "#151929", "--border-heavy" => "#1F2544",
            "--range-thumb" => "#7C6FD6", "--range-shadow" => "0 0 12px rgba(124, 111, 214, 0.5)"
        ],
        'extra' => "@import url('https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;500;600;700;800&display=swap'); @keyframes nebula-drift { 0% { transform: translate(0%, 0%) scale(1); } 25% { transform: translate(6%, -4%) scale(1.08); } 50% { transform: translate(-4%, 6%) scale(1.05); } 75% { transform: translate(4%, 2%) scale(1.10); } 100% { transform: translate(0%, 0%) scale(1); } } @keyframes nebula-breathe { 0%, 100% { opacity: 0.45; } 50% { opacity: 1; } } @keyframes nebula-aurora { 0%, 100% { filter: hue-rotate(0deg) brightness(1); } 33% { filter: hue-rotate(24deg) brightness(1.15); } 66% { filter: hue-rotate(-16deg) brightness(0.9); } } @keyframes nebula-disturb { 0% { transform: translate(0%, 0%) scale(1) rotate(0deg); } 20% { transform: translate(-8%, 5%) scale(1.12) rotate(-2deg); } 50% { transform: translate(5%, -7%) scale(1.08) rotate(1.5deg); } 80% { transform: translate(-3%, 4%) scale(1.06) rotate(-1deg); } 100% { transform: translate(0%, 0%) scale(1) rotate(0deg); } } * { font-family: 'Exo 2', sans-serif !important; } .app-frame { position: relative !important; background-color: #07090F !important; background-image: none !important; overflow: hidden !important; } .app-frame::before { content: '' !important; position: absolute !important; inset: -30% !important; width: 160% !important; height: 160% !important; background-image: radial-gradient(ellipse at 20% 25%, rgba(72, 52, 212, 0.38) 0%, transparent 52%), radial-gradient(ellipse at 80% 70%, rgba(168, 52, 212, 0.28) 0%, transparent 48%), radial-gradient(ellipse at 55% 45%, rgba(38, 217, 199, 0.16) 0%, transparent 55%), radial-gradient(ellipse at 30% 75%, rgba(92, 82, 192, 0.20) 0%, transparent 40%) !important; animation: nebula-drift 38s ease-in-out infinite, nebula-breathe 9s ease-in-out infinite, nebula-aurora 22s ease-in-out infinite !important; pointer-events: none !important; z-index: 0 !important; will-change: transform, opacity, filter !important; transition: animation-duration 0.5s !important; } .app-frame::after { content: '' !important; position: absolute !important; inset: -20% !important; width: 140% !important; height: 140% !important; background-image: radial-gradient(ellipse at 70% 20%, rgba(38, 217, 199, 0.18) 0%, transparent 45%), radial-gradient(ellipse at 15% 60%, rgba(124, 111, 214, 0.22) 0%, transparent 42%) !important; animation: nebula-drift 52s ease-in-out infinite reverse, nebula-breathe 13s ease-in-out infinite 4s, nebula-aurora 30s ease-in-out infinite 8s !important; pointer-events: none !important; z-index: 0 !important; will-change: transform, opacity, filter !important; } .app-frame.nebula-disturbed::before { animation: nebula-disturb 1.8s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards, nebula-breathe 9s ease-in-out infinite, nebula-aurora 22s ease-in-out infinite !important; } .app-frame.nebula-disturbed::after { animation: nebula-disturb 2.2s cubic-bezier(0.25, 0.46, 0.45, 0.94) reverse forwards, nebula-breathe 13s ease-in-out infinite 4s, nebula-aurora 30s ease-in-out infinite 8s !important; } .scroll-view { background: transparent !important; position: relative !important; z-index: 1 !important; } .top-bar { background: rgba(7, 9, 15, 0.45) !important; backdrop-filter: blur(24px) saturate(160%) !important; -webkit-backdrop-filter: blur(24px) saturate(160%) !important; border-bottom: 1px solid rgba(124, 111, 214, 0.2) !important; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4) !important; } 0% { transform: translate(0%, 0%) scale(1); } 25% { transform: translate(3%, -2%) scale(1.04); } 50% { transform: translate(-2%, 3%) scale(1.02); } 75% { transform: translate(2%, 1%) scale(1.05); } 100% { transform: translate(0%, 0%) scale(1); } } @keyframes nebula-breathe { 0%, 100% { opacity: 0.75; } 50% { opacity: 1; } } @keyframes nebula-aurora { 0%, 100% { filter: hue-rotate(0deg) brightness(1); } 33% { filter: hue-rotate(18deg) brightness(1.08); } 66% { filter: hue-rotate(-12deg) brightness(0.95); } } * { font-family: 'Exo 2', sans-serif !important; } .app-frame { position: relative !important; background-color: #07090F !important; background-image: none !important; overflow: hidden !important; } .app-frame::before { content: '' !important; position: absolute !important; inset: -30% !important; width: 160% !important; height: 160% !important; background-image: radial-gradient(ellipse at 20% 25%, rgba(72, 52, 212, 0.22) 0%, transparent 52%), radial-gradient(ellipse at 80% 70%, rgba(168, 52, 212, 0.16) 0%, transparent 48%), radial-gradient(ellipse at 55% 45%, rgba(38, 217, 199, 0.08) 0%, transparent 55%), radial-gradient(ellipse at 30% 75%, rgba(92, 82, 192, 0.10) 0%, transparent 40%) !important; animation: nebula-drift 38s ease-in-out infinite, nebula-breathe 9s ease-in-out infinite, nebula-aurora 22s ease-in-out infinite !important; pointer-events: none !important; z-index: 0 !important; will-change: transform, opacity, filter !important; } .app-frame::after { content: '' !important; position: absolute !important; inset: -20% !important; width: 140% !important; height: 140% !important; background-image: radial-gradient(ellipse at 70% 20%, rgba(38, 217, 199, 0.07) 0%, transparent 45%), radial-gradient(ellipse at 15% 60%, rgba(124, 111, 214, 0.09) 0%, transparent 42%) !important; animation: nebula-drift 52s ease-in-out infinite reverse, nebula-breathe 13s ease-in-out infinite 4s, nebula-aurora 30s ease-in-out infinite 8s !important; pointer-events: none !important; z-index: 0 !important; will-change: transform, opacity, filter !important; } .scroll-view { background: transparent !important; position: relative !important; z-index: 1 !important; } .card { border: 1px solid rgba(124, 111, 214, 0.15) !important; background: linear-gradient(135deg, rgba(13, 17, 32, 0.95) 0%, rgba(10, 13, 24, 0.98) 100%) !important; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.04) !important; transition: border-color 0.3s ease, box-shadow 0.3s ease !important; } .card:active { border-color: rgba(124, 111, 214, 0.45) !important; box-shadow: 0 4px 24px rgba(124, 111, 214, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.06) !important; } .page-title { font-weight: 700 !important; letter-spacing: -0.02em !important; background: linear-gradient(135deg, #FFFFFF 0%, #B0BEF8 50%, #26D9C7 100%) !important; -webkit-background-clip: text !important; background-clip: text !important; -webkit-text-fill-color: transparent !important; } .bar-title { font-weight: 600 !important; letter-spacing: 0.5px !important; } .section-header { background: transparent !important; color: rgba(124, 111, 214, 0.7) !important; text-transform: uppercase !important; font-size: 10px !important; letter-spacing: 3px !important; font-weight: 600 !important; padding-top: 36px !important; border-bottom: 1px solid rgba(124, 111, 214, 0.12) !important; } .top-bar, .selection-bottom-bar { background: rgba(7, 9, 15, 0.97) !important; border-bottom: 1px solid rgba(124, 111, 214, 0.12) !important; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6) !important; } .transcription { color: #C5CAE9 !important; line-height: 1.7 !important; font-weight: 400 !important; } .time-badge { color: rgba(121, 134, 203, 0.7) !important; font-size: 10px !important; font-weight: 500 !important; letter-spacing: 0.5px !important; } .done-btn, .btn-primary { background: linear-gradient(135deg, #5C52C0 0%, #7C6FD6 100%) !important; border: 1px solid rgba(124, 111, 214, 0.5) !important; box-shadow: 0 4px 16px rgba(124, 111, 214, 0.3) !important; color: #FFFFFF !important; font-weight: 600 !important; letter-spacing: 0.3px !important; } .done-btn:active, .btn-primary:active { transform: scale(0.97) !important; box-shadow: 0 2px 8px rgba(124, 111, 214, 0.2) !important; } .org-chip { background: rgba(13, 17, 32, 0.8) !important; border: 1px solid rgba(124, 111, 214, 0.2) !important; color: #8B9DE8 !important; backdrop-filter: blur(10px) !important; -webkit-backdrop-filter: blur(10px) !important; font-weight: 500 !important; } .org-chip.folder-active, .org-chip.smart-active { background: rgba(124, 111, 214, 0.2) !important; border-color: rgba(124, 111, 214, 0.6) !important; color: #B0BEF8 !important; box-shadow: 0 0 12px rgba(124, 111, 214, 0.2) !important; } input[type=text], textarea, select { background: #050710 !important; color: #E8EAF6 !important; border: 1px solid rgba(124, 111, 214, 0.2) !important; } input[type=text]:focus, textarea:focus { border-color: rgba(124, 111, 214, 0.5) !important; box-shadow: 0 0 0 3px rgba(124, 111, 214, 0.08) !important; } ::placeholder { color: rgba(121, 134, 203, 0.35) !important; } .fab { background: linear-gradient(135deg, #4A3EA8 0%, #6B5FC4 100%) !important; box-shadow: 0 8px 28px rgba(124, 111, 214, 0.4) !important; border: 1px solid rgba(124, 111, 214, 0.5) !important; } .player-capsule { background: rgba(13, 17, 32, 0.9) !important; border: 1px solid rgba(124, 111, 214, 0.2) !important; backdrop-filter: blur(20px) !important; -webkit-backdrop-filter: blur(20px) !important; } .player-btn { color: #B0BEF8 !important; } .settings-sheet, #shared-picker-sheet, #shared-input-sheet, #shared-confirm-sheet, #ai-manager-sheet, #folder-manager-sheet, #folder-move-sheet, #draft-pad-card { background: #0A0D18 !important; border-top: 1px solid rgba(124, 111, 214, 0.25) !important; box-shadow: 0 -20px 60px rgba(0, 0, 0, 0.7) !important; } .settings-header, .accordion-inner { background: #07090F !important; } .settings-group { background: rgba(13, 17, 32, 0.8) !important; border: 1px solid rgba(124, 111, 214, 0.1) !important; border-radius: 14px !important; } .setting-label { color: #C5CAE9 !important; } .setting-item { border-bottom: 1px solid rgba(124, 111, 214, 0.07) !important; } .settings-close { background: #151929 !important; color: #7986CB !important; } .picker-item { background: #0D1120 !important; border: 1px solid rgba(124, 111, 214, 0.15) !important; color: #C5CAE9 !important; } .picker-item.selected { background: rgba(124, 111, 214, 0.2) !important; border-color: rgba(124, 111, 214, 0.5) !important; color: #B0BEF8 !important; } .po-folder { border-color: rgba(124, 111, 214, 0.15) !important; background: #07090F !important; } .po-folder-header { background: #0D1120 !important; color: #B0BEF8 !important; border-bottom-color: rgba(124, 111, 214, 0.15) !important; } .plugin-tray { background: #050710 !important; box-shadow: inset 0 2px 12px rgba(0, 0, 0, 0.6) !important; } .dash-widget > div { background: #0D1120 !important; color: #C5CAE9 !important; border: 1px solid rgba(124, 111, 214, 0.12) !important; } .tool-card { background: #0D1120 !important; border-color: rgba(124, 111, 214, 0.15) !important; } .tool-title { color: #E8EAF6 !important; } [id^='todo-list-wrap'], #todo-pinned-wrapper > div { background: #0D1120 !important; border-color: rgba(124, 111, 214, 0.2) !important; } .todo-text { color: #E8EAF6 !important; } .seq-badge { background: linear-gradient(135deg, #7C6FD6, #26D9C7) !important; color: #07090F !important; border: none !important; font-weight: 800 !important; box-shadow: 0 4px 14px rgba(124, 111, 214, 0.4) !important; } mark { background: rgba(38, 217, 199, 0.2) !important; color: #26D9C7 !important; } .slider { background-color: #151929 !important; border: 1px solid rgba(124, 111, 214, 0.2) !important; } input:checked + .slider { background: linear-gradient(135deg, #5C52C0, #7C6FD6) !important; box-shadow: 0 0 10px rgba(124, 111, 214, 0.3) !important; } .slider:before { background: #E8EAF6 !important; } .color-chip { background: #151929 !important; color: #C5CAE9 !important; border-color: rgba(124, 111, 214, 0.2) !important; } #hidden-section-divider { background: #0D1120 !important; border-top-color: rgba(124, 111, 214, 0.1) !important; } .pm-accordion-header { background: #0D1120 !important; border-color: rgba(124, 111, 214, 0.1) !important; } #cal-grid > div:not(:empty) { background-color: #0D1120 !important; color: #C5CAE9 !important; border-color: rgba(124, 111, 214, 0.15) !important; } #cal-grid > div.today { border-color: #7C6FD6 !important; box-shadow: 0 0 8px rgba(124, 111, 214, 0.3) !important; } #draft-pad-header { background: #0D1120 !important; } #draft-pad-input { color: #E8EAF6 !important; } .fr-action-zone:not(.active) { background: #0D1120 !important; border: 1px solid rgba(124, 111, 214, 0.2) !important; color: #C5CAE9 !important; } .po-drop-zone { background: rgba(124, 111, 214, 0.05) !important; border-color: rgba(124, 111, 214, 0.2) !important; } ::-webkit-scrollbar-thumb { background: rgba(124, 111, 214, 0.25) !important; border-radius: 4px !important; } ::-webkit-scrollbar-track { background: #07090F !important; } .text-btn:not(.danger) { background: #151929 !important; color: #C5CAE9 !important; border: 1px solid rgba(124, 111, 214, 0.2) !important; } #el-header-history-btn[title*='Pending'] { color: #FFD740 !important; background: #1A1300 !important; border: 1px solid #FFD740 !important; } #po-tools-header input { background: #050710 !important; color: #E8EAF6 !important; border-color: rgba(124, 111, 214, 0.2) !important; }"
    ],
    'blueprint' => [
        'name' => "Architect Blueprint",
        'vars' => [
            "--bg-color" => "#003366", "--card-bg" => "#004080", "--header-bg" => "rgba(0, 51, 102, 0.95)",
            "--text-primary" => "#FFFFFF", "--text-secondary" => "#8ECAFF", "--text-title" => "#FFFFFF",
            "--primary" => "#FFF200", "--btn-bg" => "#00264d", "--btn-text" => "#FFFFFF",
            "--input-bg" => "#001a33", "--input-text" => "#FFF200", "--primary-text" => "#003366",
            "--border-color" => "rgba(255, 255, 255, 0.3)", "--shadow-card" => "none",
            "--ai-accent" => "#FFF200", "--ai-accent-bg" => "rgba(255, 242, 0, 0.1)", "--player-active" => "#FFF200"
        ],
        'extra' => ".app-frame { background-color: #003366 !important; background-image: linear-gradient(rgba(255,255,255,.07) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.07) 1px, transparent 1px) !important; background-size: 25px 25px !important; } .scroll-view { background: transparent !important; } * { font-family: 'Courier New', Courier, monospace !important; } .card { border-radius: 0 !important; border: 1px solid rgba(255, 255, 255, 0.4) !important; } .section-header { background: #00264d !important; color: #FFF200 !important; border: 1px solid rgba(255, 255, 255, 0.2) !important; border-radius: 0 !important; } .page-title { border-bottom: 2px solid #FFF200 !important; border-radius: 0 !important; display: table !important; font-style: normal !important; text-transform: uppercase; } .done-btn, .btn-primary { border: 1px solid #FFF200 !important; background: transparent !important; color: #FFF200 !important; border-radius: 0 !important; box-shadow: none !important; } .settings-sheet, #shared-picker-sheet, #shared-input-sheet { background: #00264d !important; border-top: 2px solid #FFF200 !important; border-radius: 0 !important; } .settings-group { background: #003366 !important; border: 1px solid rgba(255, 255, 255, 0.2) !important; border-radius: 0 !important; } .picker-item { background: #003366 !important; border-color: rgba(255, 255, 255, 0.2) !important; color: white !important; border-radius: 0 !important; } .org-chip { background: #00264d !important; border: 1px solid rgba(255, 255, 255, 0.3) !important; color: #8ECAFF !important; border-radius: 0 !important; } .org-chip.folder-active { background: #FFF200 !important; color: #003366 !important; } .icon-btn, .bar-action-btn { color: #FFF200 !important; } .slider { background-color: #001a33 !important; border: 1px solid rgba(255, 255, 255, 0.2) !important; border-radius: 0 !important; } .slider:before { border-radius: 0 !important; background-color: #FFF200 !important; } .fab, .fr-action-zone, .fr-menu-btn { background: #FFF200 !important; color: #003366 !important; border: 2px solid #FFF !important; box-shadow: 0 4px 15px rgba(0,0,0,0.3) !important; } .fab span, .fab svg, .fr-action-zone span, .fr-action-zone svg { color: #003366 !important; stroke: #003366 !important; }"
    ],
    'neumorphic' => [
        'name' => "Neumorphic Soft",
        'vars' => [
            "--bg-color" => "#E0E5EC", "--card-bg" => "#E0E5EC", "--header-bg" => "rgba(224, 229, 236, 0.9)",
            "--text-primary" => "#31344B", "--text-secondary" => "#44476A", "--text-title" => "#31344B",
            "--primary" => "#6D5DFC", "--btn-bg" => "#E0E5EC", "--btn-text" => "#31344B",
            "--input-bg" => "#E0E5EC", "--input-text" => "#31344B", "--primary-text" => "#FFFFFF",
            "--border-color" => "transparent", "--shadow-card" => "10px 10px 20px #a3b1c6, -10px -10px 20px #ffffff",
            "--ai-accent" => "#6D5DFC", "--ai-accent-bg" => "rgba(109, 93, 252, 0.1)",
            "--glass-bg" => "#E0E5EC", "--glass-border" => "rgba(255, 255, 255, 0.6)",
            "--shadow-floating" => "12px 12px 25px rgba(0,0,0,0.1), -10px -10px 20px rgba(255,255,255,0.7)",
            "--selected-bg" => "rgba(109, 93, 252, 0.1)", "--selected-text" => "#6D5DFC", "--player-active" => "#6D5DFC"
        ],
        'extra' => ".card { border: none !important; border-radius: 30px !important; } .top-bar, .selection-bottom-bar, .settings-sheet, #shared-picker-sheet, #shared-input-sheet, #shared-confirm-sheet, #draft-pad-card { box-shadow: 10px 10px 20px #a3b1c6, -10px -10px 20px #ffffff !important; border: none !important; } .done-btn, .btn-primary, .org-chip, .tool-card, .dash-widget > div, [id^='todo-list-wrap'], #todo-pinned-wrapper > div { border: none !important; border-radius: 12px !important; box-shadow: 6px 6px 12px #a3b1c6, -6px -6px 12px #ffffff !important; background: var(--card-bg) !important; color: var(--text-primary) !important; } .done-btn:active, .btn-primary:active, .org-chip.folder-active { box-shadow: inset 4px 4px 8px #a3b1c6, inset -4px -4px 8px #ffffff !important; transform: scale(0.98); } input[type=text], textarea, select { border: none !important; box-shadow: inset 6px 6px 12px #a3b1c6, inset -6px -6px 12px #ffffff !important; background: var(--bg-color) !important; border-radius: 12px !important; } .player-capsule { box-shadow: inset 4px 4px 8px #a3b1c6, inset -4px -4px 8px #ffffff !important; border: none !important; } .fab:not(.recording) { box-shadow: 8px 8px 16px #a3b1c6, -8px -8px 16px #ffffff !important; } .section-header { background: transparent !important; color: var(--text-secondary) !important; font-weight: 800 !important; letter-spacing: 2px !important; } .settings-group { background: var(--bg-color) !important; box-shadow: inset 4px 4px 8px #a3b1c6, inset -4px -4px 8px #ffffff !important; border-radius: 20px !important; }"
    ],
    'arcade' => [
        'name' => "8-Bit Arcade",
        'vars' => [
            "--bg-color" => "#1a103d", "--card-bg" => "#2d1b4e", "--header-bg" => "#1a103d",
            "--text-primary" => "#00ffcc", "--text-secondary" => "#ff00ff", "--text-title" => "#ffcc00",
            "--primary" => "#ffcc00", "--btn-bg" => "#2d1b4e", "--btn-text" => "#00ffcc",
            "--input-bg" => "#000000", "--input-text" => "#00ffcc", "--primary-text" => "#000000",
            "--border-color" => "#ffffff", "--shadow-card" => "4px 4px 0px #000000",
            "--ai-accent" => "#ffffff", "--ai-accent-bg" => "rgba(255, 255, 255, 0.1)", "--player-active" => "#ffcc00"
        ],
        'extra' => "@import url('https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap'); * { font-family: 'Press Start 2P', 'Courier New', monospace !important; border-radius: 0 !important; image-rendering: pixelated; } .page-title { font-size: 20px !important; text-shadow: 4px 4px 0px #ff0055; margin-bottom: 30px !important; } .card { border: 4px solid #ffffff !important; margin-bottom: 24px !important; } .section-header { background: #ff0055 !important; color: #ffffff !important; padding: 4px 8px !important; font-size: 10px !important; border: 4px solid #ffffff !important; border-bottom: none !important; margin-top: 20px !important; } .done-btn, .btn-primary, .org-chip, .tool-card, .dash-widget > div { border: 4px solid #ffffff !important; box-shadow: 4px 4px 0px #000000 !important; background: #2d1b4e !important; color: #ffcc00 !important; font-size: 10px !important; } .done-btn:active, .btn-primary:active { transform: translate(2px, 2px); box-shadow: 2px 2px 0px #000000 !important; } .time-badge { font-size: 8px !important; color: #ff00ff !important; } .transcription { font-size: 12px !important; line-height: 1.8 !important; } .top-bar, .selection-bottom-bar, .settings-sheet { border-bottom: 6px solid #ffffff !important; } .fab { border: 4px solid #ffffff !important; box-shadow: 6px 6px 0px #000000 !important; } .player-capsule { border: 2px solid #ffffff !important; background: #000 !important; } .player-btn { color: #ffcc00 !important; } input[type=text], textarea, select { border: 4px solid #ffffff !important; } ::placeholder { color: rgba(0, 255, 204, 0.3) !important; }"
    ],
    'palm' => [
        'name' => "Palm Pilot (90s PDA)",
        'vars' => [
            "--bg-color" => "#949983", "--card-bg" => "#A9AD94", "--header-bg" => "#8C927D",
            "--text-primary" => "#1A1D16", "--text-secondary" => "#3B4235", "--text-title" => "#1A1D16",
            "--primary" => "#1A1D16", "--btn-bg" => "#949983", "--btn-text" => "#1A1D16",
            "--input-bg" => "#A9AD94", "--input-text" => "#1A1D16", "--primary-text" => "#A9AD94",
            "--border-color" => "rgba(0, 0, 0, 0.4)", "--shadow-card" => "none",
            "--ai-accent" => "#3B4235", "--ai-accent-bg" => "rgba(0, 0, 0, 0.05)",
            "--glass-bg" => "#A9AD94", "--glass-border" => "rgba(0, 0, 0, 0.5)",
            "--shadow-floating" => "4px 4px 0px rgba(0,0,0,0.2)", "--player-active" => "#1A1D16"
        ],
        'extra' => "* { border-radius: 0 !important; font-family: 'Courier New', Courier, monospace !important; } .app-frame { background-color: #8C927D !important; border: 8px solid #7D8469 !important; box-sizing: border-box; } .scroll-view { background-color: #A9AD94 !important; background-image: radial-gradient(#949983 0.5px, transparent 0.5px) !important; background-size: 3px 3px !important; } .card { border: 1px solid #1A1D16 !important; background: transparent !important; margin-bottom: 12px !important; } .top-bar, .selection-bottom-bar { background: #8C927D !important; border-bottom: 2px solid #1A1D16 !important; } .bar-title { font-size: 22px !important; font-weight: 900 !important; } .top-bar svg { width: 28px !important; height: 28px !important; stroke-width: 3px !important; } .top-bar .icon-btn { padding: 4px !important; } .page-title { font-size: 14px !important; text-transform: uppercase; letter-spacing: 2px; border-bottom: 1px solid #1A1D16 !important; display: inline-block; margin-bottom: 10px !important; padding-top: 0 !important; } .section-header { background: #7D8469 !important; color: #1A1D16 !important; border: 1px solid #1A1D16 !important; font-size: 10px !important; padding: 2px 8px !important; } .done-btn, .btn-primary, .org-chip { background: #1A1D16 !important; color: #A9AD94 !important; border: 1px solid #1A1D16 !important; font-weight: 900 !important; } .player-capsule { border: 1px solid #1A1D16 !important; background: transparent !important; } .player-btn { color: #1A1D16 !important; } .fab { background: #7D8469 !important; border: 2px solid #1A1D16 !important; color: #1A1D16 !important; } .settings-sheet, #shared-picker-sheet { background: #949983 !important; border-top: 4px solid #1A1D16 !important; } .settings-group { background: #A9AD94 !important; border: 1px solid #1A1D16 !important; }"
    ],





    'metal' => [
        'name' => "Liquid Metal (Mechanical)",
        'vars' => [
            "--bg-color" => "#dce1e3", "--card-bg" => "#eef2f5", "--header-bg" => "rgba(220, 225, 227, 0.95)",
            "--text-primary" => "#4a5568", "--text-secondary" => "#718096", "--text-title" => "#2d3748",
            "--primary" => "#718096", "--btn-bg" => "#e2e8f0", "--btn-text" => "#4a5568",
            "--input-bg" => "#cbd5e0", "--input-text" => "#2d3748", "--primary-text" => "#ffffff",
            "--border-color" => "rgba(255,255,255,0.5)", "--shadow-card" => "8px 8px 16px #b8bqc1, -8px -8px 16px #ffffff",
            "--ai-accent" => "#3182ce", "--ai-accent-bg" => "rgba(49, 130, 206, 0.1)",
            "--glass-bg" => "rgba(255, 255, 255, 0.3)", "--glass-border" => "rgba(255, 255, 255, 0.6)",
            "--shadow-floating" => "10px 10px 20px #a0a0a0, -5px -5px 10px #ffffff",
            "--selected-bg" => "#cbd5e0", "--selected-text" => "#2d3748", "--player-active" => "#718096"
        ],
        'extra' => ".app-frame { background: linear-gradient(135deg, #eef2f5 0%, #dce1e3 100%) !important; } .scroll-view { background: transparent !important; } .card { background: linear-gradient(145deg, #ffffff, #dce1e3) !important; border-radius: 24px !important; border: 1px solid rgba(255,255,255,0.4) !important; box-shadow: 6px 6px 12px #b0b5b9, -6px -6px 12px #ffffff !important; transition: transform 0.2s !important; } .card:active { transform: scale(0.98); background: linear-gradient(145deg, #dce1e3, #ffffff) !important; } .page-title, .bar-title { color: #2d3748 !important; text-shadow: 1px 1px 0 rgba(255,255,255,0.8), -1px -1px 1px rgba(0,0,0,0.1) !important; font-weight: 800 !important; letter-spacing: 1px; } .section-header { background: transparent !important; color: #718096 !important; text-shadow: 1px 1px 0 #fff; text-transform: uppercase; letter-spacing: 2px; padding-top: 24px !important; } .transcription { color: #4a5568 !important; text-shadow: 0 1px 0 rgba(255,255,255,0.8) !important; font-weight: 500 !important; } .done-btn, .btn-primary, .org-chip { background: linear-gradient(145deg, #eef2f5, #d6dbe0) !important; color: #4a5568 !important; box-shadow: 4px 4px 8px #b0b5b9, -4px -4px 8px #ffffff !important; border-radius: 12px !important; font-weight: 700 !important; border: 1px solid rgba(255,255,255,0.4) !important; } .done-btn:active { box-shadow: inset 4px 4px 8px #b0b5b9, inset -4px -4px 8px #ffffff !important; } input[type=text], textarea, select { background: #cbd5e0 !important; box-shadow: inset 5px 5px 10px #b0b5b9, inset -5px -5px 10px #ffffff !important; border: none !important; border-radius: 12px !important; color: #2d3748 !important; text-shadow: 0 1px 0 rgba(255,255,255,0.5); } ::placeholder { color: #718096 !important; opacity: 0.7; text-shadow: none !important; } .player-capsule { background: linear-gradient(145deg, #eef2f5, #d6dbe0) !important; box-shadow: 5px 5px 10px #b0b5b9, -5px -5px 10px #ffffff !important; border-radius: 30px !important; } .fab { background: linear-gradient(145deg, #f0f0f0, #d6dbe0) !important; color: #4a5568 !important; box-shadow: 8px 8px 16px #a0a0a0, -8px -8px 16px #ffffff !important; border: 1px solid rgba(255,255,255,0.5); } .settings-sheet, #shared-picker-sheet { background: #eef2f5 !important; border-top: 1px solid rgba(255,255,255,0.5) !important; } .settings-group { background: linear-gradient(145deg, #ffffff, #e6ebf0) !important; box-shadow: 5px 5px 10px #b0b5b9, -5px -5px 10px #ffffff !important; border: none !important; border-radius: 16px !important; } .time-badge { color: #718096 !important; text-shadow: 0 1px 0 #fff; }"
    ]
];

// FOLDER-BASED THEME LOADER (Modular Extension)
if (!is_dir(CJOS_PATH_THEMES)) {
    mkdir(CJOS_PATH_THEMES, 0777, true);
    file_put_contents(CJOS_PATH_THEMES . '/.htaccess', "Order allow,deny\nDeny from all");
}

if (is_dir(CJOS_PATH_THEMES)) {
    $themeFiles = glob(CJOS_PATH_THEMES . '/*.php');
    foreach ($themeFiles as $file) {
        $key = basename($file, '.php');
        $themeData = include $file;
        if (is_array($themeData)) {
            $tp_themes_registry[$key] = $themeData;
        }
    }
}

if (isset($_POST['plugin_action'])) {
    if ($_POST['plugin_action'] === 'tp_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $defaults = ['light_theme' => 'default', 'dark_theme' => 'midnight', 'mode' => 'light', 'show_toggle' => true, 'wants_fullscreen' => false];
        $conf = file_exists($tp_config_file) ? json_decode(file_get_contents($tp_config_file), true) : $defaults;
        echo json_encode(['status' => 'success', 'config' => $conf]);
        exit;
    }
    if ($_POST['plugin_action'] === 'tp_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $data = json_decode($_POST['config'], true);
        file_put_contents($tp_config_file, json_encode($data));
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_POST['plugin_action'] === 'tp_reload_themes') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'themes' => $tp_themes_registry]);
        exit;
    }
}

$plugin_settings_map['ThemePresets'] = <<<'HTML'
<div id="tp-tray-anchor">
    <div class="setting-item">
        <button onclick="tpOpenStudio()" class="text-btn" style="width:100%; background:var(--primary); color:var(--primary-text); border-radius:12px; padding:12px; font-weight:700; box-shadow:0 4px 12px rgba(0,122,255,0.2);">
            Launch Theme Studio
        </button>
    </div>

    <div id="tp-gui-root" style="display:none; padding-bottom: 20px;">
    <div class="setting-item vertical">
        <label class="setting-label">Light Theme</label>
        <button onclick="tpOpenThemePicker('light')" class="text-btn" style="
            width: 100%; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 12px; 
            padding: 12px; font-weight: 600; color: var(--input-text); margin-top: 4px;
            display: flex; justify-content: space-between; align-items: center;
        ">
            <span id="tp-light-theme-label">Default</span>
            <span data-sui-icon="chevron" data-sui-size="14" style="opacity:0.5;"></span>
        </button>
    </div>

    <div class="setting-item vertical">
        <label class="setting-label">Dark Theme</label>
        <button onclick="tpOpenThemePicker('dark')" class="text-btn" style="
            width: 100%; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 12px; 
            padding: 12px; font-weight: 600; color: var(--input-text); margin-top: 4px;
            display: flex; justify-content: space-between; align-items: center;
        ">
            <span id="tp-dark-theme-label">Midnight OLED</span>
            <span data-sui-icon="chevron" data-sui-size="14" style="opacity:0.5;"></span>
        </button>
    </div>

    <div data-sui-setting="Theme Mode" data-sui-desc="Switch between Light and Dark themes." data-sui-id="tp-mode-toggle" data-sui-onchange="tpToggleMode(this.checked)"></div>
    <div data-sui-setting="Show Mode Toggle" data-sui-desc="Place a Moon/Sun button in the Settings header." data-sui-id="tp-header-btn-toggle" data-sui-onchange="tpToggleHeaderVisibility(this.checked)"></div>
    <div data-sui-setting="Fullscreen Mode" data-sui-desc="Expand the workspace to fill the entire screen." data-sui-id="tp-fullscreen-toggle" data-sui-onchange="tpToggleFullscreen(this.checked)"></div>

    <!-- SECTION: ANIMATION & PERFORMANCE -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin: 12px 24px 8px 32px; cursor:pointer;" 
         onclick="suiToggle('sec-tp-animation', true)">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Animation & Performance</div>
        <span data-sui-icon="chevron" data-sui-arrow="sec-tp-animation" data-sui-size="14" data-sui-stroke="2.5" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>

    <div class="sui-accordion" id="sec-tp-animation">
        <div class="sui-accordion-inner">
            <div data-sui-setting="GPU Acceleration" data-sui-desc="Force hardware compositing for 60fps scrolling." data-sui-id="tp-ae-gpu-mode" data-sui-onchange="if(window.toggleAeGpu) window.toggleAeGpu(this.checked)"></div>
            <div data-sui-setting="Simplify Visuals" data-sui-desc="Disable Blur and reduce Shadows to save battery." data-sui-id="tp-ae-simplify-mode" data-sui-onchange="if(window.toggleAeSimplify) window.toggleAeSimplify(this.checked)"></div>
            <div data-sui-setting="Reduce Motion" data-sui-desc="Replaces sliding/expanding animations with instant snaps." data-sui-id="tp-ae-reduce-motion" data-sui-onchange="if(window.toggleAeMotion) window.toggleAeMotion(this.checked)"></div>
        </div>
    </div>

    <!-- SECTION: THEME-SPECIFIC OPTIONS -->
    <div id="tp-options-header" style="display:flex; justify-content:space-between; align-items:center; margin: 12px 24px 8px 32px; cursor:pointer;" 
         onclick="suiToggle('sec-tp-theme-options', true)">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Theme Options</div>
        <span data-sui-icon="chevron" data-sui-arrow="sec-tp-theme-options" data-sui-size="14" data-sui-stroke="2.5" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>

    <div class="sui-accordion" id="sec-tp-theme-options">
        <div class="sui-accordion-inner" id="tp-theme-options-container">
            <!-- Dynamic options injected here -->
        </div>
    </div>

    <!-- SECTION: MANUAL OVERRIDES -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin: 12px 24px 8px 32px; cursor:pointer;" 
         onclick="suiToggle('sec-tp-manual-colors', true)">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Manual Color Overrides</div>
        <span data-sui-icon="chevron" data-sui-arrow="sec-tp-manual-colors" data-sui-size="14" data-sui-stroke="2.5" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>

    <div class="sui-accordion" id="sec-tp-manual-colors">
        <div class="sui-accordion-inner">
            <div class="setting-item vertical">
                <label class="setting-label">Bar Background</label>
                <div class="setting-desc">The color of the physical screen area outside the app frame.</div>
                <div class="color-input-group" style="margin-top:8px;">
                    <input type="color" id="input-sys-color" value="#FFFFFF">
                    <input type="text" id="input-sys-text" value="#FFFFFF" maxlength="7">
                    <button id="btn-add-color" class="btn-add-color" title="Save Preset">+</button>
                </div>
                <div id="sys-color-presets" class="color-presets-list"></div>
            </div>

            <div class="setting-item vertical">
                <label class="setting-label">Bottom Fade Color</label>
                <div class="setting-desc">The color of the gradient at the bottom of the screen.</div>
                <div class="color-input-group" style="margin-top:8px;">
                    <input type="color" id="input-fade-color" value="#F2F2F7">
                    <input type="text" id="input-fade-text" value="#F2F2F7" maxlength="7">
                    <button id="btn-add-fade-color" class="btn-add-color" title="Save Preset">+</button>
                </div>
                <div id="fade-color-presets" class="color-presets-list"></div>
            </div>
        </div>
    </div>

    <!-- SECTION: THEME COVERAGE -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin: 12px 24px 8px 32px; cursor:pointer;" 
         onclick="suiToggle('sec-tp-coverage', true)">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Theme Coverage Analysis</div>
        <span data-sui-icon="chevron" data-sui-arrow="sec-tp-coverage" data-sui-size="14" data-sui-stroke="2.5" style="color:var(--text-secondary); transition:transform 0.35s; transform: rotate(-90deg);"></span>
    </div>
    
    <div class="sui-accordion" id="sec-tp-coverage">
        <div class="sui-accordion-inner">
            <div class="settings-group" style="padding:12px; overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <div id="tp-coverage-grid" style="display:grid; grid-template-columns: repeat(2, minmax(max-content, 1fr)); gap:6px;"></div>
                <div style="margin-top:12px; font-size:10px; color:var(--text-secondary); text-align:center; opacity:0.7;">
                    Checkmarks indicate variables or selectors defined by the active theme.
                </div>
            </div>
        </div>
    </div>
    </div> <!-- /tp-gui-root -->
</div> <!-- /tp-tray-anchor -->
    <script>
        initSectionState('sec-tp-animation');
        initSectionState('sec-tp-manual-colors');
        initSectionState('sec-tp-coverage');
    </script>
HTML;

$plugin_js .= 'window.__THEME_REGISTRY__ = ' . json_encode($tp_themes_registry) . ';';
$plugin_js .= <<<'JS'
// --- GLOBAL THEME ENGINE ---

const tpThemes = window.__THEME_REGISTRY__ || {};
let _tpActiveTheme = null;

let tpState = { light_theme: 'default', dark_theme: 'midnight', mode: 'light', show_toggle: true, wants_fullscreen: false };
window.tpState = tpState;

window.tpApplyTheme = function(key) {
    const theme = tpThemes[key] || tpThemes.default;
    const root = document.documentElement;

    // 0. Clean Slate: Clear SSR styles and reset all known theme variables
    const ssrStyles = document.getElementById("tp-ssr-vars");
    if (ssrStyles) ssrStyles.innerHTML = "";
    
    if (typeof tpMarkers !== 'undefined') {
        tpMarkers.forEach(m => root.style.removeProperty(m));
    }
    
    // 1. Apply Base Variables
    Object.entries(theme.vars).forEach(([prop, val]) => {
        root.style.setProperty(prop, val);
    });
    
    // 2. Apply Extra CSS
    let styleTag = document.getElementById("tp-dynamic-overrides");
    if (!styleTag) {
        styleTag = document.createElement("style");
        styleTag.id = "tp-dynamic-overrides";
        document.head.appendChild(styleTag);
    }
    styleTag.innerHTML = theme.extra || "";
    
    // 3. Apply Manual Overrides (if any)
    const manualSys = localStorage.getItem("cjos_sys_color");
    if (manualSys && manualSys !== "theme-default") {
        root.style.setProperty("--system-bar-bg", manualSys);
        const meta = document.getElementById('meta-theme-color');
        if (meta) meta.setAttribute('content', manualSys === 'transparent' ? '#000000' : manualSys);
    }
    
    const manualFade = localStorage.getItem("cjos_fade_color");
    if (manualFade && manualFade !== "theme-default") {
        root.style.setProperty("--bottom-fade-bg", manualFade);
        }

        tpUpdateCoverageUI(key);

        const isNewTheme = (_tpActiveTheme !== key);

        if (isNewTheme) {
            // --- LIFECYCLE: DESTROY PREVIOUS THEME ---
            if (_tpActiveTheme && window['tp_destroy_' + _tpActiveTheme]) {
                try { window['tp_destroy_' + _tpActiveTheme](); } catch(e) { console.error("Theme Destroy Error:", e); }
            }

            // --- LIFECYCLE: INJECT MODULAR JS ---
            if (theme.js && !document.getElementById('tp-js-' + key)) {
                const script = document.createElement('script');
                script.id = 'tp-js-' + key;
                script.text = theme.js;
                document.head.appendChild(script);
            }

            // --- LIFECYCLE: INIT NEW THEME ---
            _tpActiveTheme = key;
            if (window['tp_init_' + key]) {
                try { window['tp_init_' + key](); } catch(e) { console.error("Theme Init Error:", e); }
            }

            // --- LEGACY FALLBACK: NEBULA DISTURBANCE ---
            const frame = document.querySelector('.app-frame');
            if (frame) {
                if (key === 'nebula') {
                    if (!frame._nebulaSwipeActive) {
                        frame._nebulaSwipeActive = true;
                        let _nebulaDisturbTimer = null;
                        frame.addEventListener('touchstart', function() {
                            clearTimeout(_nebulaDisturbTimer);
                            frame.classList.add('nebula-disturbed');
                            _nebulaDisturbTimer = setTimeout(() => frame.classList.remove('nebula-disturbed'), 2200);
                        }, { passive: true });
                    }
                } else {
                    frame._nebulaSwipeActive = false;
                    frame.classList.remove('nebula-disturbed');
                }
            }
        }
};

// --- THEME LIFECYCLE HOOKS (Modular Extraction Ready) ---





window.tpToggleMode = function(isDark) {
    tpState.mode = isDark ? 'dark' : 'light';
    tpRefreshUI();
    tpSaveConfig();
    window.sui.haptic('medium');
};

window.tpToggleHeaderVisibility = function(visible) {
    tpState.show_toggle = visible;
    tpRefreshHeaderBtn();
    tpSaveConfig();
};

window.tpToggleFullscreen = function(enable) {
    const doc = window.document;
    const docEl = doc.documentElement;

    const requestFullScreen = docEl.requestFullscreen || docEl.webkitRequestFullscreen || docEl.mozRequestFullScreen || docEl.msRequestFullscreen;
    const cancelFullScreen = doc.exitFullscreen || doc.webkitExitFullscreen || doc.mozCancelFullScreen || doc.msExitFullscreen;
    const isMobile = /Mobi|Android|iPhone|iPod|iPad/i.test(navigator.userAgent);

    tpState.wants_fullscreen = enable;
    tpSaveConfig();

    if (enable) {
        if (requestFullScreen) {
            let p = requestFullScreen.call(docEl);
            if (p && typeof p.then === 'function') {
                p.then(() => {
                    if (isMobile && window.screen && window.screen.orientation && typeof window.screen.orientation.lock === 'function') {
                        window.screen.orientation.lock('portrait').catch(e => console.warn("Orientation lock rejected:", e));
                    }
                }).catch(err => {
                    console.warn("Fullscreen request failed:", err);
                    const toggle = document.getElementById("tp-fullscreen-toggle");
                    if (toggle) toggle.checked = false;
                });
            } else {
                // Fallback for older browsers without promise support
                if (isMobile && window.screen && window.screen.orientation && typeof window.screen.orientation.lock === 'function') {
                    setTimeout(() => window.screen.orientation.lock('portrait').catch(e => {}), 200);
                }
            }
        }
    } else {
        if (cancelFullScreen) {
            if (isMobile && window.screen && window.screen.orientation && typeof window.screen.orientation.unlock === 'function') {
                window.screen.orientation.unlock();
            }
            let p = cancelFullScreen.call(doc);
            if (p && typeof p.catch === 'function') {
                p.catch(err => console.warn("Exit fullscreen failed:", err));
            }
        }
    }
};

const syncFullscreenState = function() {
    const doc = window.document;
    const isFullscreen = !!(doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || doc.msFullscreenElement);
    
    if (!isFullscreen) {
        // If the document is hidden, the browser forced the exit due to app switching.
        // We ignore it so the toggle stays ON, waiting for the smart resume.
        if (doc.visibilityState !== 'hidden') {
            const toggle = document.getElementById("tp-fullscreen-toggle");
            if (toggle) toggle.checked = false;
            document.body.classList.remove('is-fullscreen');

            // If we didn't expect to lose fullscreen (user explicitly exited), clear preference
            if (tpState.wants_fullscreen) {
                tpState.wants_fullscreen = false;
                tpSaveConfig();
            }
        }
    } else {
        document.body.classList.add('is-fullscreen');
        const toggle = document.getElementById("tp-fullscreen-toggle");
        if (toggle) toggle.checked = true;
    }
};

document.addEventListener('fullscreenchange', syncFullscreenState);
document.addEventListener('webkitfullscreenchange', syncFullscreenState);
document.addEventListener('mozfullscreenchange', syncFullscreenState);
document.addEventListener('MSFullscreenChange', syncFullscreenState);

window.tpTriggerSmartResume = function() {
    if (window.sui && window.sui.toast) {
        window.sui.toast("Tap anywhere to resume fullscreen", { duration: 3000 });
    }

    const resumeFn = () => {
        window.tpToggleFullscreen(true);
        document.removeEventListener('pointerup', resumeFn, true);
    };
    document.addEventListener('pointerup', resumeFn, true);
};

let _wasFullscreenBeforeHide = false;
document.addEventListener('visibilitychange', () => {
    const doc = window.document;
    const isFullscreen = !!(doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || doc.msFullscreenElement);
    
    if (doc.visibilityState === 'hidden') {
        _wasFullscreenBeforeHide = isFullscreen || document.body.classList.contains('is-fullscreen');
    } else if (doc.visibilityState === 'visible') {
        if (_wasFullscreenBeforeHide && !isFullscreen) {
            _wasFullscreenBeforeHide = false;
            window.tpTriggerSmartResume();
        }
    }
});

window.tpRefreshUI = function() {const currentThemeKey = tpState.mode === 'dark' ? tpState.dark_theme : tpState.light_theme;
    tpApplyTheme(currentThemeKey);

    const lightLabel = document.getElementById("tp-light-theme-label");
    if (lightLabel) lightLabel.innerText = tpThemes[tpState.light_theme]?.name || tpState.light_theme;

    const darkLabel = document.getElementById("tp-dark-theme-label");
    if (darkLabel) darkLabel.innerText = tpThemes[tpState.dark_theme]?.name || tpState.dark_theme;

    // Render Theme-Specific Options
    tpRenderThemeOptions(currentThemeKey);

    const modeToggle = document.getElementById("tp-mode-toggle");
    if (modeToggle) modeToggle.checked = (tpState.mode === 'dark');

    const headerToggle = document.getElementById("tp-header-btn-toggle");
    if (headerToggle) headerToggle.checked = tpState.show_toggle;

    const fsToggle = document.getElementById("tp-fullscreen-toggle");
if (fsToggle) {
    const doc = window.document;
    const isFullscreen = !!(doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || doc.msFullscreenElement);
    fsToggle.checked = isFullscreen || !!tpState.wants_fullscreen;
}tpRefreshHeaderBtn();
    if (typeof renderPresets === 'function') renderPresets();
    if (typeof frInitActions === 'function') frInitActions();
};

window.tpRenderThemeOptions = function(key) {
    const container = document.getElementById('tp-theme-options-container');
    const header = document.getElementById('tp-options-header');
    if (!container || !header) return;

    const theme = tpThemes[key];
    if (!theme || !theme.options || theme.options.length === 0) {
        container.innerHTML = '<div style="padding:20px; text-align:center; color:var(--text-secondary); font-size:12px; font-style:italic; opacity:0.7;">No customizable options for this theme.</div>';
        return;
    }

    container.innerHTML = '';

    // Initialize state for this theme if missing
    if (!tpState.theme_options) tpState.theme_options = {};
    if (!tpState.theme_options[key]) tpState.theme_options[key] = {};

    theme.options.forEach(opt => {
        const val = tpState.theme_options[key][opt.id] !== undefined ? tpState.theme_options[key][opt.id] : opt.default;
        let rowHtml = '';

        if (opt.type === 'toggle') {
            const switchHtml = window.suiSwitch(
                `tp-opt-${key}-${opt.id}`, 
                val, 
                `tpUpdateThemeOption('${key}', '${opt.id}', this.checked)`
            );
            rowHtml = window.suiSettingRow(opt.label, opt.desc || '', switchHtml);
        } else if (opt.type === 'slider') {
            const sliderHtml = `
                <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                    <input type="range" min="${opt.min}" max="${opt.max}" step="${opt.step || 1}" value="${val}" 
                           style="flex:1;" oninput="tpUpdateThemeOption('${key}', '${opt.id}', this.value)">
                    <span id="tp-val-${key}-${opt.id}" style="font-family:monospace; font-size:12px; min-width:30px; text-align:right;">${val}${opt.unit || ''}</span>
                </div>
            `;
            rowHtml = window.suiSettingRow(opt.label, opt.desc || '', sliderHtml, null, true);
        }
        
        const temp = document.createElement('div');
        temp.innerHTML = rowHtml;
        const row = temp.firstElementChild;
        if (row) container.appendChild(row);
    });
};

window.tpUpdateThemeOption = function(themeKey, optId, value) {
    if (!tpState.theme_options) tpState.theme_options = {};
    if (!tpState.theme_options[themeKey]) tpState.theme_options[themeKey] = {};
    
    tpState.theme_options[themeKey][optId] = value;

    // Update the UI label next to the slider
    const valEl = document.getElementById(`tp-val-${themeKey}-${optId}`);
    if (valEl) {
        const theme = tpThemes[themeKey];
        const opt = theme?.options?.find(o => o.id === optId);
        valEl.innerText = value + (opt?.unit || '');
    }
    
    // Re-apply theme to reflect changes immediately
    tpApplyTheme(themeKey);
    tpSaveConfig();

    // Trigger preview hook if defined by the theme
    if (typeof window['tp_preview_option_' + themeKey] === 'function') {
        window['tp_preview_option_' + themeKey](optId, value);
    }
};

window.tpRefreshHeaderBtn = function() {
    const actions = document.getElementById('settings-header-actions');
    if (!actions) return;

    let btn = document.getElementById('tp-header-mode-btn');
    if (!tpState.show_toggle) {
        if (btn) btn.remove();
        return;
    }

    if (!btn) {
        btn = document.createElement('button');
        btn.id = 'tp-header-mode-btn';
        btn.style.cssText = 'background:var(--btn-bg); border:none; width:30px; height:30px; border-radius:50%; color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.3s; touch-action:none;';
        
        let tpLongPressTimer = null;
        
        btn.onpointerdown = (e) => {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            btn._isLongPress = false;
            tpLongPressTimer = setTimeout(() => {
                window.sui.haptic('medium');
                window.tpOpenStudio();
                btn._isLongPress = true;
                tpLongPressTimer = null; // Mark as triggered
            }, 600);
        };

        btn.onpointerup = (e) => {
            if (tpLongPressTimer) {
                clearTimeout(tpLongPressTimer);
                tpLongPressTimer = null;
            }
        };

        btn.onclick = (e) => {
            if (btn._isLongPress) return;
            e.stopPropagation();
            tpToggleMode(tpState.mode === 'light');
        };

        btn.onpointerleave = () => {
            if (tpLongPressTimer) {
                clearTimeout(tpLongPressTimer);
                tpLongPressTimer = null;
            }
        };

        // Prevent default context menu on long press
        btn.oncontextmenu = (e) => e.preventDefault();
        
        actions.prepend(btn);
    }

    const isDark = tpState.mode === 'dark';
    btn.innerHTML = `<span data-sui-icon="${isDark ? 'moon' : 'sun'}" data-sui-size="18"></span>`;
    window.suiHydrateIcons(btn);
};

let _tpSaveTimer = null;
window.tpSaveConfig = async function() {
    if (_tpSaveTimer) clearTimeout(_tpSaveTimer);
    _tpSaveTimer = setTimeout(async () => {
        await window.sui.api("tp_save_config", { config: JSON.stringify(tpState) }, { toast: false });
    }, 500);
};

window.tpReloadThemes = async function() {
    try {
        if (window.sui && window.sui.toast) {
            window.sui.toast("Scanning themes...", { plugin: "Theme Presets" });
        }
        const data = await window.sui.api("tp_reload_themes", {}, { toast: "Themes updated!", plugin: "Theme Presets" });
        if (data && data.themes) {
            // Hot-reload in-place to preserve const references
            Object.keys(tpThemes).forEach(k => delete tpThemes[k]);
            Object.assign(tpThemes, data.themes);
            window.__THEME_REGISTRY__ = data.themes;
            
            // Re-apply currently active theme to hot-reload edited CSS values
            const currentThemeKey = tpState.mode === 'dark' ? tpState.dark_theme : tpState.light_theme;
            tpApplyTheme(currentThemeKey);
            
            // Sync current display labels
            const lightLabel = document.getElementById("tp-light-theme-label");
            if (lightLabel) lightLabel.innerText = tpThemes[tpState.light_theme]?.name || tpState.light_theme;

            const darkLabel = document.getElementById("tp-dark-theme-label");
            if (darkLabel) darkLabel.innerText = tpThemes[tpState.dark_theme]?.name || tpState.dark_theme;

            tpRenderThemeOptions(currentThemeKey);
        }
    } catch(e) {
        console.error("Theme reload failed:", e);
    }
};

// --- THEME STUDIO LOGIC ---
window.tpOpenStudio = function() {
    const root = document.getElementById('tp-gui-root');
    const anchor = document.getElementById('tp-tray-anchor');
    if(!root || !anchor) return;

    window.sui.openStudio({
        id: 'tp-studio',
        title: 'Theme & Visuals',
        content: '', // Move existing DOM
        onSetup: (contentBox) => {
            contentBox.appendChild(root);
            root.style.display = 'block';
            root.scrollTop = 0;
            window.tpReloadThemes();
        },
        onClose: () => {
            anchor.appendChild(root);
            root.style.display = 'none';
        }
    });
};

window.tpOpenThemePicker = async function(targetMode) {
    if (typeof window.openPicker !== "function") return;
    
    await window.tpReloadThemes();
    
    const categories = {
        "Default": ['default'],
        "OS Experience": ['macos', 'macosDark', 'macosGraphite', 'win95', 'palm'],
        "Artistic & Retro": ['nebula', 'neonSign', 'memphis', 'arcade', 'lcd', 'brutalist', 'holoFoil', 'conjureBoy'],
        "Tactile & Material": ['glass', 'slateFrost', 'obsidianSlab', 'slab', 'forest', 'paper', 'notebook', 'neumorphic', 'metal']
    };

    const options = [];
    const seen = new Set();

    for (const [catName, keys] of Object.entries(categories)) {
        const matchingKeys = keys.filter(k => tpThemes[k]);
        if (matchingKeys.length > 0) {
            options.push({ label: catName, type: 'header' });
            matchingKeys.forEach(k => {
                options.push({ label: tpThemes[k].name, value: k });
                seen.add(k);
            });
        }
    }

    // Fallback for any themes not explicitly categorized
    const others = Object.keys(tpThemes).filter(k => !seen.has(k));
    if (others.length > 0) {
        options.push({ label: "Other Themes", type: 'header' });
        others.forEach(k => options.push({ label: tpThemes[k].name, value: k }));
    }

    const current = targetMode === 'dark' ? tpState.dark_theme : tpState.light_theme;
    
    window.openPicker(`Choose ${targetMode === 'dark' ? 'Dark' : 'Light'} Theme`, options, current, (val) => {
        if (targetMode === 'dark') tpState.dark_theme = val;
        else tpState.light_theme = val;
        tpRefreshUI();
        tpSaveConfig();
    });
};

const tpMarkers = [
    "--bg-color", "--card-bg", "--header-bg", "--text-primary", "--text-secondary",
    "--text-title", "--primary", "--btn-bg", "--btn-text", "--input-bg",
    "--input-text", "--primary-text", "--border-color", "--shadow-card",
    "--ai-accent", "--ai-accent-bg", "--glass-bg", "--glass-border",
    "--shadow-floating", "--selected-bg", "--selected-text", "--bottom-fade-bg",
    "--player-active", "--range-thumb", "--range-shadow"
];

const tpSelectors = [
    ".scroll-view", ".todo-text", "#cal-grid", ".settings-close", ".po-folder-header",
    ".po-folder", "#hidden-section-divider", ".pm-accordion-header", ".plugin-tray",
    ".app-frame", ".card", ".top-bar", ".selection-bottom-bar", ".player-capsule",
    "input[type=range]", "input[type=range]::-webkit-slider-thumb"
];
      

window.tpUpdateCoverageUI = function(key) {
    const grid = document.getElementById("tp-coverage-grid");
    if (!grid) return;
    const theme = tpThemes[key] || tpThemes.default;
    grid.innerHTML = "";

    const allMarkers = [
        ...tpMarkers.map(m => ({ name: m, type: 'var' })),
        ...tpSelectors.map(s => ({ name: s, type: 'selector' }))
    ];

    allMarkers.forEach(marker => {
        const isCovered = (marker.type === 'var') 
            ? theme.vars.hasOwnProperty(marker.name) 
            : (theme.extra && theme.extra.includes(marker.name));

        const item = document.createElement("div");
        item.style.cssText = "display:flex; align-items:center; justify-content:space-between; background:rgba(0,0,0,0.03); padding:6px 10px; border-radius:8px; font-family:monospace; font-size:10px; border:1px solid var(--border-color); min-width: 120px; gap: 8px;";
        
        const nameSpan = document.createElement("span");
        nameSpan.innerText = marker.name.replace('--', '');
        nameSpan.style.color = isCovered ? "var(--text-primary)" : "var(--text-secondary)";
        nameSpan.style.overflow = "hidden";
        nameSpan.style.textOverflow = "ellipsis";
        nameSpan.style.whiteSpace = "nowrap";
        nameSpan.style.flex = "1";

        const icon = isCovered 
            ? `<svg viewBox="0 0 24 24" fill="none" stroke="#34C759" stroke-width="4" style="width:12px; height:12px; flex-shrink:0;"><polyline points="20 6 9 17 4 12"></polyline></svg>`
            : `<svg viewBox="0 0 24 24" fill="none" stroke="#FF3B30" stroke-width="4" style="width:10px; height:10px; flex-shrink:0; opacity:0.5;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>`;

        item.appendChild(nameSpan);
        item.insertAdjacentHTML('beforeend', icon);
        grid.appendChild(item);
    });
};

// Initialize Theme System
window.addEventListener("load", async () => {
    try {
        const data = await window.sui.api("tp_get_config", {}, { toast: false });
        if (data && data.config) {
            tpState = data.config;
            window.tpState = tpState;
        }
    } catch(e) {}
    tpRefreshUI();

    // Check if user wanted fullscreen from a previous session or page refresh
    if (tpState.wants_fullscreen) {
        const doc = window.document;
        const isFullscreen = !!(doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || doc.msFullscreenElement);
        if (!isFullscreen) {
            window.tpTriggerSmartResume();
        }
    }
});
JS;
$plugin_js .=  <<<'JS'
// --- MANUAL COLOR OVERRIDES ---
const p_root = document.documentElement;

function getPresets() { return JSON.parse(localStorage.getItem("cjos_color_presets") || "[]"); }
function savePresets(presets) { localStorage.setItem("cjos_color_presets", JSON.stringify(presets)); }

function updateSysColor(hex) {
    if (hex === "theme-default") {
        p_root.style.removeProperty("--system-bar-bg");
        localStorage.setItem("cjos_sys_color", "theme-default");
        // Reset meta tag to match the current theme's solid background color
        const themeBg = getComputedStyle(p_root).getPropertyValue('--bg-color').trim();
        const meta = document.getElementById('meta-theme-color');
        if (meta) meta.setAttribute('content', (themeBg === 'transparent' || !themeBg) ? '#000000' : themeBg);
        return;
    }

    p_root.style.setProperty("--system-bar-bg", hex);
    localStorage.setItem("cjos_sys_color", hex);

    // Sync with Android/iOS System Bar
    const meta = document.getElementById('meta-theme-color');
    if (meta) {
        meta.setAttribute('content', hex === 'transparent' ? '#000000' : hex);
    }

    const elCol = document.getElementById("input-sys-color");
    const elTxt = document.getElementById("input-sys-text");
    if(elCol && hex !== "transparent") elCol.value = hex;
    if(elTxt) elTxt.value = hex;
}

function updateFadeColor(hex) {
    if (hex === "theme-default") {
        p_root.style.removeProperty("--bottom-fade-bg");
        localStorage.setItem("cjos_fade_color", "theme-default");
        return;
    }

    p_root.style.setProperty("--bottom-fade-bg", hex);
    localStorage.setItem("cjos_fade_color", hex);
    const elCol = document.getElementById("input-fade-color");
    const elTxt = document.getElementById("input-fade-text");
    if(elCol && hex !== "transparent") elCol.value = hex;
    if(elTxt) elTxt.value = hex;
}

function renderPresets() {
    const presets = getPresets();
    const currentSys = localStorage.getItem("cjos_sys_color") || "#FFFFFF";
    const currentFade = localStorage.getItem("cjos_fade_color") || "#F2F2F7";

    const sysCont = document.getElementById("sys-color-presets");
    const fadeCont = document.getElementById("fade-color-presets");

    if(sysCont) renderPresetList(sysCont, presets, currentSys, updateSysColor);
    if(fadeCont) renderPresetList(fadeCont, presets, currentFade, updateFadeColor);
}

function renderPresetList(container, presets, current, updateFn) {
    container.innerHTML = "";
    
    // 0. Add Theme Default Option
    const themeChip = document.createElement("div");
    themeChip.className = `color-chip ${current === "theme-default" ? "active" : ""}`;
    themeChip.innerHTML = `<div class="color-dot" style="background: var(--primary); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; color:white; font-size:10px; font-weight:900;">T</div><span class="color-hex" style="color:inherit;">Theme</span>`;
    themeChip.onclick = () => { updateFn("theme-default"); renderPresets(); };
    container.appendChild(themeChip);

    // 1. Add Transparent Option
    const trpChip = document.createElement("div");
    trpChip.className = `color-chip ${current === "transparent" ? "active" : ""}`;
    // Checkerboard pattern for transparency visual
    const checker = "linear-gradient(45deg, #ccc 25%, transparent 25%), linear-gradient(-45deg, #ccc 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #ccc 75%), linear-gradient(-45deg, transparent 75%, #ccc 75%)";
    trpChip.innerHTML = `<div class="color-dot" style="background: ${checker}; background-size: 4px 4px; background-position: 0 0, 0 2px, 2px 2px, 2px 0; border:1px solid var(--border-color);"></div><span class="color-hex" style="color:inherit;">Transparent</span>`;
    trpChip.onclick = () => { updateFn("transparent"); renderPresets(); };
    container.appendChild(trpChip);

    // 2. Add Saved Presets
    presets.forEach((color, index) => {
        const chip = document.createElement("div");
        chip.className = `color-chip ${color === current ? "active" : ""}`;
        chip.innerHTML = `<div class="color-dot" style="background-color: ${color}; border:1px solid var(--border-color);"></div><span class="color-hex" style="color:inherit;">${color}</span><span class="btn-del-preset" style="color:inherit; opacity:0.6;" onclick="deletePreset(${index}, event)">×</span>`;
        chip.onclick = () => { updateFn(color); renderPresets(); };
        container.appendChild(chip);
    });
}

window.deletePreset = function(index, e) {
    e.stopPropagation();
    const presets = getPresets();
    presets.splice(index, 1);
    savePresets(presets);
    renderPresets();
};

function addPreset(sourceId) {
    const input = document.getElementById(sourceId);
    if(!input) return;
    const color = input.value;
    const presets = getPresets();
    if(!presets.includes(color)) {
        presets.push(color);
        savePresets(presets);
        renderPresets();
    }
}

// Bindings
(function() {
    const init = () => {
        const btnAddSys = document.getElementById("btn-add-color");
        if(btnAddSys) btnAddSys.onclick = () => addPreset("input-sys-text");
        
        const btnAddFade = document.getElementById("btn-add-fade-color");
        if(btnAddFade) btnAddFade.onclick = () => addPreset("input-fade-text");

        const inSysCol = document.getElementById("input-sys-color");
        const inSysTxt = document.getElementById("input-sys-text");
        if(inSysCol) inSysCol.addEventListener("input", (e) => { updateSysColor(e.target.value); renderPresets(); });
        if(inSysTxt) inSysTxt.addEventListener("input", (e) => { if(e.target.value.startsWith("#")) { updateSysColor(e.target.value); renderPresets(); } });

        const inFadeCol = document.getElementById("input-fade-color");
        const inFadeTxt = document.getElementById("input-fade-text");
        if(inFadeCol) inFadeCol.addEventListener("input", (e) => { updateFadeColor(e.target.value); renderPresets(); });
        if(inFadeTxt) inFadeTxt.addEventListener("input", (e) => { if(e.target.value.startsWith("#")) { updateFadeColor(e.target.value); renderPresets(); } });

        renderPresets();
        
        // Init values
        updateSysColor(localStorage.getItem("cjos_sys_color") || "transparent");
        updateFadeColor(localStorage.getItem("cjos_fade_color") || "transparent");
    };
    
    if (document.readyState === "complete") init();
    else window.addEventListener("load", init);
})();
JS;
?>