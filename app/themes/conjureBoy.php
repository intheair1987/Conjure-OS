<?php
// MODULAR THEME: Conjure Boy Console
// Inspired by retro DMG/CGB skeuomorphism

$themeData = [
    'conjureBoy' => [
        'name' => "Conjure Boy (Console)",
        'vars' => [
            "--bg-color" => "#c5c6c0", // Classic DMG grey
            "--card-bg" => "#d1d2cc",  // Light cartridge grey background for high contrast
            "--header-bg" => "#c5c6c0",
            "--text-primary" => "#0f380f", // Deep DMG dark ink
            "--text-secondary" => "#353638", // Slate charcoal
            "--text-title" => "#1a1d16",
            "--primary" => "#a92031", // Glossy red A-button
            "--btn-bg" => "#7b7e80",  // Matte SELECT/START grey
            "--btn-text" => "#1a1d16",
            "--input-bg" => "#9bbc0f",  // LCD green input
            "--input-text" => "#0f380f", // Crisp dark green text
            "--primary-text" => "#ffffff",
            "--border-color" => "#8c8d87", // Medium grey
            "--border-heavy" => "#1a1d16",
            "--warn-bg" => "#0c0d10",
            "--warn-text" => "#9bbc0f",
            "--success-bg" => "#0c0d10",
            "--success-text" => "#39ff14",
            "--skeleton-bg" => "#7b7e80",
            "--shadow-card" => "inset -2px -2px 0px rgba(0,0,0,0.35), inset 2px 2px 0px rgba(255,255,255,0.4), 0 6px 12px rgba(0,0,0,0.15)",
            "--ai-accent" => "#8b3cd4", // GBC Color badge
            "--ai-accent-bg" => "rgba(139, 60, 212, 0.15)",
            "--glass-bg" => "#9bbc0f",
            "--glass-border" => "#0f380f",
            "--shadow-floating" => "inset -3px -3px 0px rgba(0,0,0,0.45), inset 3px 3px 0px rgba(255,255,255,0.5), 0 15px 30px rgba(0,0,0,0.25)",
            "--player-active" => "#a92031",
            "--range-thumb" => "#a92031",
            "--range-shadow" => "0 2px 5px rgba(0, 0, 0, 0.4)",
            "--bottom-fade-bg" => "#c5c6c0"
        ],
        'options' => [
            [
                'id' => 'boot_vol',
                'label' => 'Boot Sound Volume',
                'desc' => 'Adjust the GBC double-chime booting sweep volume.',
                'type' => 'slider',
                'min' => 0,
                'max' => 100,
                'step' => 5,
                'default' => 50,
                'unit' => '%'
            ],
            [
                'id' => 'click_vol',
                'label' => 'Button Click Volume',
                'desc' => 'Adjust the volume of tactile button and key clicks.',
                'type' => 'slider',
                'min' => 0,
                'max' => 100,
                'step' => 5,
                'default' => 50,
                'unit' => '%'
            ]
        ],
        'extra' => "
            /* Custom Google Font import to make typography match GBC perfectly */
            @import url('https://fonts.googleapis.com/css2?family=VT323&display=swap');

            /* Apply VT323 to all key visual elements in the OS */
            .page-title, .bar-title, .section-header, .todo-text, .stack-title-text, .done-btn, .btn-primary, .org-chip, .tool-title, .meta-badge {
                font-family: 'VT323', monospace !important;
                letter-spacing: 0.5px !important;
            }
            .bar-title {
                font-size: 24px !important;
            }
            .transcription {
                font-family: 'VT323', monospace !important;
                font-size: 18px !important;
                line-height: 1.4 !important;
                color: #0f380f !important;
            }

            /* Skeuomorphic Hem Title styled as a recessed, perfectly proportioned olive green LCD Screen panel */
            .page-title {
                background-color: #9bbc0f !important; /* LCD Screen green */
                color: #0f380f !important;            /* Crisp liquid crystal dark green text */
                font-family: 'VT323', monospace !important;
                font-size: 20px !important;           /* Perfectly proportioned font size */
                font-weight: bold !important;
                text-transform: uppercase !important;
                padding: 6px 18px !important;         /* Balanced compact padding */
                border: 2px solid #0f380f !important;  /* Screen border bezel */
                border-radius: 4px !important;
                box-shadow: 
                    inset 0 2px 4px rgba(0,0,0,0.5),  /* Debossed recess shadow */
                    0.5px 0.5px 0 rgba(255,255,255,0.4) !important;
                text-shadow: 0.5px 0.5px 0px rgba(255,255,255,0.2) !important;
                display: inline-block !important;
                width: auto !important;
                height: auto !important;
                transform: none !important;
                margin: 10px 0 !important;
            }
            
            /* Parent container of page title on GBC theme (Targeted specifically to prevent grid hijacking) */
            .scroll-view > div:has(> .page-title) {
                position: relative !important;
                min-height: 52px !important;
                display: flex !important;
                align-items: center !important;
            }

            /* Force Right Alignment for Conjure Boy (Overrides User Preference) */
            html body[data-pt-align] .page-title {
                position: absolute !important;
                right: 0 !important;
                left: auto !important;
                top: 50% !important;
                transform: translateY(-50%) !important;
                margin: 0 !important;
            }

            /* Visually disable alignment controls in settings to indicate theme override */
            #pt-align-left, #pt-align-center, #pt-align-right {
                opacity: 0.4 !important;
                pointer-events: none !important;
            }

            /* DMG Casing background texture */
            .app-frame {
                background-color: #c5c6c0 !important;
                background-image: radial-gradient(rgba(0, 0, 0, 0.04) 1px, transparent 1px) !important;
                background-size: 8px 8px !important;
                position: relative;
            }
            .scroll-view {
                background: transparent !important;
            }

            /* Page Title styled with LED indicator */
            .title-container {
                position: relative;
                overflow: visible !important;
            }
            .title-container::before {
                content: '';
                display: inline-block;
                width: 8px;
                height: 8px;
                background-color: #f21d1d;
                border-radius: 50%;
                margin-right: 8px;
                box-shadow: 0 0 5px #f21d1d, 0 0 10px #f21d1d;
                vertical-align: middle;
                animation: cjb-led-glow 1.5s infinite ease-in-out;
            }
            @keyframes cjb-led-glow {
                0%, 100% { opacity: 0.6; }
                50% { opacity: 1; }
            }

            /* Unified High-Contrast Container Styles */
            .card, .po-folder, .settings-group, .picker-item, .tool-card, .dash-widget > div, [id^='todo-list-wrap'], #todo-pinned-wrapper > div, #draft-pad-card, .player-capsule {
                background-color: #d1d2cc !important; /* Clean light cartridge grey */
                color: #0f380f !important;
                border: 2px solid #8c8d87 !important;
                box-shadow: 
                    inset 1px 1px 0px rgba(255,255,255,0.4),
                    inset -1.5px -1.5px 0px rgba(0,0,0,0.25),
                    0 4px 8px rgba(0,0,0,0.12) !important;
                border-radius: 8px !important;
            }
            /* Enforce crisp dark green lettering over light-grey cartridge plates */
            .card *, .po-folder *, .settings-group *, .picker-item *, .tool-card *, .dash-widget * {
                color: #0f380f !important;
            }

            /* Main Notes Cards Styled as Game Boy Cartridges with a bright inner LCD Screen */
            .card {
                border-radius: 6px 6px 32px 6px !important; /* Angled lower right corner matching cartridges */
                padding: 14px !important;
                position: relative;
                margin-bottom: 24px !important;
                overflow: hidden;
            }
            /* Cartridge top gripping ridges */
            .card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 16px;
                right: 16px;
                height: 5px;
                background: repeating-linear-gradient(90deg, #9b9c96 0px, #9b9c96 2px, transparent 2px, transparent 4px);
                opacity: 0.8;
                border-bottom: 1px solid rgba(0, 0, 0, 0.15);
            }
            /* Indented finger well */
            .card::after {
                content: '';
                position: absolute;
                top: 10px;
                left: 50%;
                transform: translateX(-50%);
                width: 50px;
                height: 10px;
                background-color: rgba(0,0,0,0.1);
                border-radius: 50px;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.25);
            }
            /* Sticker label context container styled as a bright, legible retro Game Boy Screen */
            .card-content {
                background-color: #9bbc0f !important; /* Retro olive-green LCD screen background */
                border: 2px solid #0f380f !important;
                border-radius: 4px !important;
                padding: 12px !important;
                margin-top: 15px; /* Clear finger well space */
                box-shadow: inset 0 3px 6px rgba(0,0,0,0.4) !important;
                position: relative;
            }
            .card-content * {
                color: #0f380f !important;
            }

            /* Input Fields and Textareas Styled as Legible, Glowing LCD Bezel Screens */
            input[type=text], textarea, select {
                background-color: #9bbc0f !important; /* High-contrast screen olive-green */
                color: #0f380f !important;            /* Crisp dark green text for absolute legibility */
                border: 2px solid #0f380f !important;
                border-radius: 4px !important;
                font-family: monospace !important;
                font-weight: bold !important;
                box-shadow: inset 0 3px 6px rgba(0,0,0,0.5) !important;
            }
            ::placeholder {
                color: rgba(15, 56, 15, 0.4) !important;
            }

            /* Section Header styled as dark screen bezel with GBC accent lines */
            .section-header {
                background-color: #656769 !important; /* Bezel slate grey */
                border-top: 3px solid #8b3cd4 !important; /* Purple accent stripe */
                border-bottom: 3px solid #00f0ff !important; /* Cyan accent stripe */
                color: #ffffff !important;
                border-radius: 4px !important;
                padding: 8px 14px !important;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.3), 0 3px 6px rgba(0,0,0,0.15) !important;
                font-size: 16px !important;
                font-weight: bold;
                text-transform: uppercase;
                margin-top: 30px !important;
                text-shadow: 0 1px 3px rgba(0,0,0,0.5);
            }

            /* High-Fidelity Tactile Game Boy Buttons (Completely Flat, No Angle/Slant, No Text-Squishing Circles) */
            .done-btn, .btn-primary, .text-btn, .btn-secondary, .bar-action-btn, #dialog-ok, #dialog-cancel, .edit-btn-action {
                font-family: 'VT323', monospace !important;
                font-weight: bold !important;
                font-size: 18px !important;
                text-transform: uppercase !important;
                padding: 10px 18px !important;  /* Fluid padding that scales to fit text without cramming */
                border-radius: 8px !important;    /* Tactile rounded rectangles instead of squished circles */
                border: 2px solid #1a1d16 !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                transform: none !important;       /* Permanently remove slanted rotation angles */
                transition: transform 0.05s ease, box-shadow 0.05s ease !important;
                width: auto !important;           /* Natural width mapping for label contents */
                height: auto !important;
                opacity: 1 !important;
            }

            /* Style all top bar, header, and tool icon buttons as clicky, circular skeuomorphic rubber buttons */
            .icon-btn, .btn-icon-only, .top-bar button, .top-bar a, .top-bar-actions a, .header-btn, .po-folder-header button, .tool-btn, [onclick*='fbCycleView'], .sui-close-trigger, .sui-studio-close, .sui-studio-header button, .sui-studio-header a, .sui-studio-actions button, .sui-studio-actions a, .cp-header-actions button, .cp-header-actions a, .settings-header button, .picker-header button, button[onclick*='cpOpenStudio'], .stack-corner-btn {
                background-color: #7b7e80 !important; /* Rubber grey */
                border: 2px solid #1a1d16 !important;
                border-radius: 50% !important; /* Perfect circle shape */
                width: 32px !important;
                height: 32px !important;
                padding: 0 !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                transform: none !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.3),
                    inset -1px -1px 1px rgba(0,0,0,0.4),
                    0 3px 0 #4a4d50,
                    0 4px 6px rgba(0,0,0,0.3) !important;
                transition: transform 0.05s ease, box-shadow 0.05s ease !important;
            }
            .icon-btn:active, .btn-icon-only:active, .top-bar button:active, .top-bar a:active, .header-btn:active, .tool-btn:active, .sui-close-trigger:active, .sui-studio-close:active, button[onclick*='cpOpenStudio']:active, .stack-corner-btn:active {
                transform: translateY(2px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    inset -1px -1px 1px rgba(255,255,255,0.15),
                    0 0.5px 0 #4a4d50,
                    0 1px 2px rgba(0,0,0,0.2) !important;
            }
            .icon-btn svg, .btn-icon-only svg, .top-bar button svg, .top-bar a svg, .header-btn svg, .po-folder-header button svg, .tool-btn svg, .top-bar-actions a svg, .sui-close-trigger svg, .sui-studio-close svg, button[onclick*='cpOpenStudio'] svg, .stack-corner-btn svg {
                stroke: #1a1d16 !important;
                width: 16px !important;
                height: 16px !important;
            }

            /* Skeuomorphic Stacks (Piles and Expanded Wrappers) */
            .stack-visual-card {
                background-color: #d1d2cc !important; /* Cartridge grey */
                border: 2px solid #8c8d87 !important;
                border-radius: 6px 6px 32px 6px !important; /* Cartridge angle */
                box-shadow: 
                    inset 1px 1px 0px rgba(255,255,255,0.4),
                    inset -1.5px -1.5px 0px rgba(0,0,0,0.25),
                    0 4px 8px rgba(0,0,0,0.12) !important;
                padding-top: 25px !important; /* Clear finger well */
                overflow: hidden !important;
            }
            .stack-visual-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 16px;
                right: 16px;
                height: 5px;
                background: repeating-linear-gradient(90deg, #9b9c96 0px, #9b9c96 2px, transparent 2px, transparent 4px);
                opacity: 0.8;
                border-bottom: 1px solid rgba(0, 0, 0, 0.15);
            }
            .stack-visual-card::after {
                content: '';
                position: absolute;
                top: 10px;
                left: 50%;
                transform: translateX(-50%);
                width: 50px;
                height: 10px;
                background-color: rgba(0,0,0,0.1);
                border-radius: 50px;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.25);
            }
            .stack-title-text, .stack-preview-line {
                font-family: 'VT323', monospace !important;
                color: #0f380f !important;
                font-style: normal !important;
                text-shadow: none !important;
            }
            .stack-title-text {
                font-size: 22px !important;
                font-weight: 900 !important;
                margin-top: 4px !important;
                padding-right: 65px !important; /* Prevent overlap with top-right label */
            }
            .stack-preview-line {
                font-size: 16px !important;
                font-weight: bold !important;
            }
            .stack-preview-area {
                padding-right: 55px !important; /* Prevent preview text from running into the top-right label */
            }
            /* Enforce physical LCD screen for stack count in both layouts */
            #stacks-section-wrapper .stack-label-pill,
            #stacks-section-wrapper.mode-grid .stack-label-pill {
                position: absolute !important;
                top: 22px !important; /* Shifted below the finger well */
                right: 8px !important;
                bottom: auto !important; /* Cancel bottom-10px from Grid Mode */
                background-color: #9bbc0f !important; /* LCD green */
                color: #0f380f !important;
                border: 2px solid #0f380f !important;
                border-radius: 4px !important;
                box-shadow: inset 0 1.5px 3px rgba(0,0,0,0.4) !important;
                font-family: 'VT323', monospace !important;
                font-size: 11px !important; /* Ultra-compact pixel size for grid */
                padding: 2px 5px !important; /* Tight padding to clear the finger well */
                text-shadow: 0.5px 0.5px 0px rgba(255,255,255,0.2) !important;
                z-index: 10 !important;
                opacity: 1 !important; /* Fully opaque GBC screen */
                line-height: 1.1 !important;
                text-transform: uppercase !important;
            }

            .expanded-stack-wrapper {
                background-color: #b0b1a8 !important; /* Darker recessed DMG plastic */
                border: 3px solid #8c8d87 !important;
                border-radius: 12px !important;
                box-shadow: inset 2px 2px 6px rgba(0,0,0,0.3), 0 2px 0 rgba(255,255,255,0.5) !important;
            }
            .stack-title-heading {
                font-family: 'VT323', monospace !important;
                font-size: 28px !important;
                font-weight: 900 !important;
                color: #1a1d16 !important;
                text-shadow: 1px 1px 0px rgba(255,255,255,0.4) !important;
                font-style: normal !important;
            }

            /* Symmetrical skeuomorphic square buttons for secondary action bars (e.g. folder toggle, organizer search, and patcher link triggers) */
            .scrollable-action-bar button, .scrollable-action-bar a, #action-bar button, #action-bar a, .bar-action-btn, [class*='action-bar'] button, [class*='action-bar'] a, #organizer-bar-wrapper button, #organizer-bar-wrapper a, #organizer-search-row button, #organizer-search-row a, .org-btn, .folder-btn, .search-btn, .sui-studio .icon-btn, .cp-container .icon-btn, [id*='cp-'] .icon-btn, .settings-sheet .icon-btn {
                background-color: #7b7e80 !important; /* Rubber grey */
                border: 2px solid #1a1d16 !important;
                border-radius: 8px !important;       /* Skeuomorphic rounded square */
                width: 34px !important;
                height: 34px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 !important;
                transform: none !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.3),
                    inset -1px -1px 1px rgba(0,0,0,0.4),
                    0 3px 0 #4a4d50,
                    0 4px 6px rgba(0,0,0,0.3) !important;
                transition: transform 0.05s ease, box-shadow 0.05s ease !important;
            }
            .scrollable-action-bar button:active, #action-bar button:active, .bar-action-btn:active, #organizer-bar-wrapper button:active, #organizer-search-row button:active, .sui-studio .icon-btn:active {
                transform: translateY(2px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    inset -1px -1px 1px rgba(255,255,255,0.15),
                    0 1px 0 #4a4d50,
                    0 2px 4px rgba(0,0,0,0.2) !important;
            }
            .scrollable-action-bar svg, #action-bar svg, .bar-action-btn svg, #organizer-bar-wrapper svg, #organizer-search-row svg, .sui-studio .icon-btn svg, .cp-container .icon-btn svg {
                stroke: #1a1d16 !important;
                width: 16px !important;
                height: 16px !important;
            }

            /* Skeuomorphic grey recess wells under small badge and pushpin/widget card icons (excluding App Maker / Portal launcher grids) */
            .pinnable-icon, .dash-widget-icon, .card-icon, .todo-icon, .card img[src*='pin'], .card-content span[data-sui-icon*='pin'], .card span[data-sui-icon*='pin'], img[src*='pushpin'], .card img[src*='pushpin'], 
            .icon-container:not([class*='am-']):not([class*='portal-']):not([class*='app-grid'] *), 
            .icon-wrapper:not([class*='am-']):not([class*='portal-']):not([class*='app-grid'] *), 
            [class*='icon-container']:not([class*='am-']):not([class*='portal-']):not([class*='app-grid'] *):not(.am-app-card *):not(.portal-grid *), 
            [class*='icon-wrapper']:not([class*='am-']):not([class*='portal-']):not([class*='app-grid'] *):not(.am-app-card *):not(.portal-grid *), 
            .widget-icon, .tool-icon, .card-header img, .card-header svg, .dash-widget h3 img, .dash-widget h3 svg, .dash-widget h4 img, .dash-widget h4 svg, .tool-card h3 img, .tool-card h3 svg, h3 span[data-sui-icon], h4 span[data-sui-icon] {
                background-color: #7b7e80 !important; /* Grey rubber well background */
                border: 1.5px solid #1a1d16 !important;
                border-radius: 4px !important;
                box-shadow: inset 1px 1px 3px rgba(0,0,0,0.4), 0.5px 0.5px 0 rgba(255,255,255,0.2) !important;
                padding: 4px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
            /* Sync color vectors inside skeuomorphic badge wells */
            .pinnable-icon svg, .dash-widget-icon svg, .card-icon svg, .todo-icon svg, .widget-icon svg, .tool-icon svg, .icon-container svg, .icon-wrapper svg, h3 span[data-sui-icon] svg, h4 span[data-sui-icon] svg {
                stroke: #1a1d16 !important;
                stroke-width: 2.5px !important;
            }

            /* Explicit Reset to completely exclude App Maker and Portal grid icon containers from skeuomorphic grey box styles */
            .am-app-card [class*='icon-'],
            .am-grid [class*='icon-'],
            .am-apps [class*='icon-'],
            .app-grid [class*='icon-'],
            .portal-grid [class*='icon-'],
            #app-maker-grid [class*='icon-'] {
                background-color: transparent !important;
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                display: flex !important; /* Restore default layout */
            }

            /* Red Action Buttons (Glossy A-button red) */
            .done-btn, .btn-primary, #dialog-ok, .danger, .edit-save {
                background-color: #a92031 !important;
                color: #ffffff !important;
                box-shadow: 
                    inset 1px 1px 2px rgba(255,255,255,0.4),
                    inset -1.5px -1.5px 2px rgba(0,0,0,0.4),
                    0 4px 0 #630c17,
                    0 6px 8px rgba(0,0,0,0.3) !important;
            }
            .done-btn:active, .btn-primary:active, #dialog-ok:active, .danger:active, .edit-save:active {
                transform: translateY(3px) !important;
                box-shadow: 
                    inset 1px 1px 2px rgba(255,255,255,0.2),
                    inset -1.5px -1.5px 2px rgba(0,0,0,0.5),
                    0 1px 0 #630c17,
                    0 2px 4px rgba(0,0,0,0.2) !important;
            }

            /* Grey Utility Buttons (SELECT/START matte rubber grey) */
            .text-btn:not(.danger), .btn-secondary, #dialog-cancel, .edit-cancel, .ce-app-load.btn-primary {
                background-color: #7b7e80 !important;
                color: #1a1d16 !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.25),
                    inset -1px -1px 1px rgba(0,0,0,0.35),
                    0 4px 0 #4a4d50,
                    0 6px 8px rgba(0,0,0,0.3) !important;
            }
            .text-btn:not(.danger):active, .btn-secondary:active, #dialog-cancel:active, .edit-cancel:active, .ce-app-load.btn-primary:active {
                transform: translateY(3px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    inset -1px -1px 1px rgba(255,255,255,0.1),
                    0 1px 0 #4a4d50,
                    0 2px 4px rgba(0,0,0,0.2) !important;
            }

            /* Folder Pills (Folder badges) styled as retro Specs label sheets */
            .org-chip {
                background-color: #e2e2df !important; /* Classic light-grey specs label */
                border: 1px solid #9b9c96 !important;
                color: #1a1d16 !important;
                border-radius: 4px !important;
                font-weight: bold !important;
                box-shadow: inset 0 1px 2px rgba(255,255,255,0.5), 0 2px 4px rgba(0,0,0,0.1) !important;
            }
            .org-chip.folder-active {
                background-color: #9bbc0f !important; /* Glowing LCD green for active state */
                border-color: #0f380f !important;
                color: #0f380f !important;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.15) !important;
            }

            /* Speaker Slits Detail for Bottom Bars (Removed) */
            .selection-bottom-bar::after {
                display: none !important;
                content: none !important;
            }

            /* Top and bottom system bar aesthetics */
            .top-bar {
                background-color: #c5c6c0 !important;
                border-bottom: 3px solid #9b9c96 !important;
                box-shadow: 0 4px 10px rgba(0,0,0,0.1) !important;
            }
            .selection-bottom-bar {
                background-color: #c5c6c0 !important;
                border-top: 3px solid #9b9c96 !important;
                box-shadow: 0 -4px 10px rgba(0,0,0,0.1) !important;
            }
            .bar-action-btn {
                color: #1a1d16 !important;
            }

            /* Floating Record Button (Glossy A-button red with 3D mechanical spring depth) */
            #fab-record {
                background-color: #a92031 !important; /* Glossy red */
                background-image: radial-gradient(circle at 35% 35%, #f25a5a 0%, #a92031 60%) !important; /* Curved light reflection */
                border: 2.5px solid #1a1d16 !important;
                color: #ffffff !important;
                box-shadow: 
                    inset 1.5px 1.5px 1px rgba(255,255,255,0.4),
                    inset -1.5px -1.5px 1px rgba(0,0,0,0.4),
                    0 6px 0 #630c17,
                    0 10px 15px rgba(0,0,0,0.3) !important;
                transition: transform 0.05s ease, box-shadow 0.05s ease !important;
                transform: translateX(-50%) translateY(0) !important;
            }
            #fab-record::before {
                display: none !important; /* Disable D-pad icon helper */
            }
            #fab-record:active {
                transform: translateX(-50%) translateY(4px) !important; /* Mechanical press down */
                box-shadow: 
                    inset 1.5px 1.5px 1.5px rgba(0,0,0,0.4),
                    inset -1px -1px 1px rgba(255,255,255,0.15),
                    0 2px 0 #630c17,
                    0 4px 6px rgba(0,0,0,0.2) !important;
            }
            #fab-record svg {
                stroke: #ffffff !important;
                filter: drop-shadow(0px 1px 2px rgba(0,0,0,0.3)) !important;
            }

            /* BACK MODE: Minimize to a matte-grey rubber START/SELECT button */
            #fab-record.back-mode {
                background-color: #7b7e80 !important; /* Matte rubber grey */
                background-image: none !important;
                color: #1a1d16 !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.25),
                    inset -1px -1px 1px rgba(0,0,0,0.35),
                    0 4px 0 #4a4d50,
                    0 6px 8px rgba(0,0,0,0.3) !important;
                transform: scale(1) !important; /* Allow normal back positioning */
            }
            #fab-record.back-mode:active {
                transform: scale(0.9) translateY(3px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    inset -1px -1px 1px rgba(255,255,255,0.1),
                    0 1px 0 #4a4d50,
                    0 2px 4px rgba(0,0,0,0.2) !important;
            }
            #fab-record.back-mode svg {
                stroke: #1a1d16 !important;
                filter: none !important;
            }

            /* Skeuomorphic mini-circular toggle buttons for accordion chevrons (such as the Capabilities trigger) */
            span[data-sui-arrow], [data-sui-icon='chevron'][data-sui-arrow] {
                background-color: #7b7e80 !important; /* Rubber grey */
                border: 1.5px solid #1a1d16 !important;
                border-radius: 50% !important;        /* Perfect circle */
                width: 26px !important;
                height: 26px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                box-shadow: 
                    inset 0.5px 0.5px 1px rgba(255,255,255,0.3),
                    inset -1px -1px 1px rgba(0,0,0,0.4),
                    0 2px 0 #4a4d50,
                    0 3px 5px rgba(0,0,0,0.3) !important;
                transition: transform 0.35s ease, box-shadow 0.05s ease !important;
            }

            /* Skeuomorphic tactile styling for the textarea close/clear button (x-button) */
            #cp-btn-clear, .toast-container button, .custom-toast button, .custom-toast .close {
                background-color: #7b7e80 !important;
                border: 1.5px solid #1a1d16 !important;
                border-radius: 50% !important;
                width: 24px !important;
                height: 24px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.3),
                    inset -1px -1px 1px rgba(0,0,0,0.4),
                    0 2px 0 #4a4d50,
                    0 3px 5px rgba(0,0,0,0.3) !important;
                color: #1a1d16 !important;
                cursor: pointer !important;
                position: absolute !important;
                top: 8px !important;
                right: 8px !important;
            }
            #cp-btn-clear:active {
                transform: translateY(1.5px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    0 0.5px 0 #4a4d50,
                    0 1px 2px rgba(0,0,0,0.2) !important;
            }
            #cp-btn-clear svg, #cp-btn-clear span, #cp-btn-clear svg stroke {
                stroke: #1a1d16 !important;
                stroke-width: 3px !important;
            }

            /* High-contrast and skeuomorphic styles for patcher staged file chips to prevent bad red/grey contrast */
            #cp-staging-area div[onclick^='cpJumpToFile'] {
                background-color: #e2e2df !important; /* Authentic light-grey labels */
                color: #1a1d16 !important;            /* Clear black text */
                border: 1.5px solid #9b9c96 !important;
                box-shadow: inset 1px 1px 1px rgba(255,255,255,0.5), 0 2px 4px rgba(0,0,0,0.1) !important;
                font-family: 'VT323', monospace !important;
                font-size: 13px !important;
                font-weight: bold !important;
            }
            /* Done/Success state staged chips */
            #cp-staging-area div[onclick^='cpJumpToFile'][style*='var(--success-bg)'], #cp-staging-area div[onclick^='cpJumpToFile'][style*='var(--success-text)'], #cp-staging-area div[onclick^='success'] {
                background-color: #9bbc0f !important; /* LCD green screen */
                color: #0f380f !important;            /* Deep green text */
                border-color: #0f380f !important;
            }
            /* Error/Failed state staged chips */
            #cp-staging-area div[onclick^='cpJumpToFile'][style*='var(--danger)'], #cp-staging-area div[onclick^='white'], #cp-staging-area div[onclick^='error'] {
                background-color: #a92031 !important; /* Action button red */
                color: #ffffff !important;            /* Solid high-contrast white */
                border-color: #1a1d16 !important;
            }

            /* Fix vertical alignment of Patcher header row buttons (Capabilities vs Open Studio) */
            .cp-header-row {
                align-items: center !important;
            }

            /* Patcher Summary Bar (LCD Screen) */
            #cp-summary-bar {
                background-color: #9bbc0f !important; /* LCD green screen */
                border: 2px solid #0f380f !important;
                border-radius: 4px !important;
                box-shadow: inset 0 3px 6px rgba(0,0,0,0.5) !important;
            }
            #cp-summary-bar div {
                color: #0f380f !important;
                font-family: 'VT323', monospace !important;
            }

            /* Patcher Bulk Actions Buttons (Copy Errors, Commit All) */
            #cp-bulk-actions button {
                font-family: 'VT323', monospace !important;
                font-weight: bold !important;
                font-size: 14px !important;
                text-transform: uppercase !important;
                padding: 6px 12px !important;
                border-radius: 6px !important;
                border: 2px solid #1a1d16 !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                transform: none !important;
                transition: transform 0.05s ease, box-shadow 0.05s ease !important;
                background-color: #7b7e80 !important; /* Rubber grey */
                color: #1a1d16 !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.25),
                    inset -1.5px -1.5px 1px rgba(0,0,0,0.35),
                    0 3px 0 #4a4d50,
                    0 4px 6px rgba(0,0,0,0.3) !important;
            }
            #cp-bulk-actions button:active {
                transform: translateY(2px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    inset -1px -1px 1px rgba(255,255,255,0.1),
                    0 1px 0 #4a4d50,
                    0 2px 3px rgba(0,0,0,0.2) !important;
            }
            /* Make primary bulk buttons (like Commit All) red */
            #cp-bulk-actions button[style*='var(--primary)'], #cp-bulk-actions button[style*='background:var(--primary)'] {
                background-color: #a92031 !important;
                color: #ffffff !important;
                box-shadow: 
                    inset 1px 1px 2px rgba(255,255,255,0.4),
                    inset -1.5px -1.5px 2px rgba(0,0,0,0.4),
                    0 3px 0 #630c17,
                    0 4px 6px rgba(0,0,0,0.3) !important;
            }
            #cp-bulk-actions button[style*='var(--primary)']:active, #cp-bulk-actions button[style*='background:var(--primary)']:active {
                transform: translateY(2px) !important;
                box-shadow: 
                    inset 1px 1px 2px rgba(255,255,255,0.2),
                    inset -1.5px -1.5px 2px rgba(0,0,0,0.5),
                    0 1px 0 #630c17,
                    0 2px 4px rgba(0,0,0,0.2) !important;
            }

            /* Skeuomorphic Modals and Overlays (Styled as thick molded DMG plastic casing) */
            .settings-sheet, .shared-bottom-sheet, #shared-picker-sheet, #shared-input-sheet, #shared-confirm-sheet, #ai-manager-sheet, .sui-studio, #folder-manager-sheet, #folder-move-sheet {
                background-color: #c5c6c0 !important; /* Base DMG grey */
                border: 3px solid #1a1d16 !important;
                border-radius: 12px 12px 0 0 !important;
                box-shadow: 
                    inset 2px 2px 2px rgba(255,255,255,0.7), 
                    inset -2px -2px 2px rgba(0,0,0,0.3), 
                    0 -10px 40px rgba(0,0,0,0.7) !important;
            }
            /* Full-screen studios get all corners rounded and float slightly */
            .sui-studio {
                border-radius: 12px !important;
                margin-top: 10px !important;
                height: calc(100% - 10px) !important;
                border-bottom: none !important;
            }

            /* Force VT323 retro font globally inside all modals and sheets */
            .settings-sheet *, .shared-bottom-sheet *, .sui-studio * {
                font-family: 'VT323', monospace !important;
            }

            /* Modal Headers */
            .settings-header, .sui-studio-header {
                background: transparent !important;
                border-bottom: 3px solid #8c8d87 !important;
                box-shadow: 0 2px 0 rgba(255,255,255,0.4) !important;
                padding-bottom: 12px !important;
                margin-bottom: 12px !important;
            }
            .settings-header h2, .sui-studio-title, .shared-bottom-sheet h3 {
                font-size: 28px !important;
                font-weight: 900 !important;
                text-transform: uppercase !important;
                text-shadow: 1px 1px 0px rgba(255,255,255,0.6) !important;
                color: #1a1d16 !important;
            }

            /* Setting Items & Dividers */
            .setting-item {
                border-bottom: 2px solid #8c8d87 !important;
                box-shadow: 0 1px 0 rgba(255,255,255,0.4) !important;
            }
            .setting-item:last-child {
                border-bottom: none !important;
                box-shadow: none !important;
            }

            /* Hidden Section Divider */
            #hidden-section-divider {
                background-color: #9bbc0f !important; /* LCD green */
                color: #0f380f !important;
                border: 2px solid #0f380f !important;
                border-radius: 4px !important;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.3) !important;
                margin-top: 20px !important;
                font-weight: bold !important;
                text-transform: uppercase !important;
                letter-spacing: 1px !important;
            }

            /* Ensure text inside modals remains dark ink */
            .shared-bottom-sheet p, .setting-label, .setting-desc, .po-folder-header, .pm-accordion-header, .sui-accordion-header {
                color: #1a1d16 !important;
            }
            .setting-label {
                font-size: 18px !important;
                font-weight: bold !important;
            }
            .setting-desc {
                font-size: 14px !important;
                opacity: 0.8 !important;
            }

            /* Reset Settings Section Titles (e.g. APPEARANCE, PLUGINS) to blend into the plastic */
            .settings-sheet .section-header {
                background: transparent !important;
                border: none !important;
                box-shadow: none !important;
                color: #1a1d16 !important;
                text-shadow: none !important;
                padding-top: 20px !important;
                margin-bottom: 0 !important;
            }

            /* --- EXPLICIT SKEUOMORPHIC SWITCHES (Physical GBC power slide style) --- */
            .switch {
                background-color: #1a1d16 !important; /* Deep recessed slider track well */
                border: 2px solid #8c8d87 !important;
                border-radius: 4px !important;
                box-shadow: inset 0 3px 5px rgba(0,0,0,0.6) !important;
                width: 44px !important;
                height: 22px !important;
                position: relative !important;
                cursor: pointer !important;
            }
            .switch .slider {
                background: transparent !important;
                position: absolute !important;
                top: 0; left: 0; right: 0; bottom: 0;
                transition: background-color 0.2s !important;
                border-radius: 0 !important;
            }
            .switch .slider::before {
                content: '' !important;
                position: absolute !important;
                height: 16px !important;
                width: 16px !important;
                left: 2px !important;
                bottom: 1px !important;
                background-color: #d1d2cc !important; /* Tactile grey plastic slider knob */
                border: 1.5px solid #1a1d16 !important;
                border-radius: 2px !important;
                box-shadow: 
                    inset 1px 1px 0px rgba(255,255,255,0.5),
                    0 1px 3px rgba(0,0,0,0.4) !important;
                transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), background-color 0.2s !important;
                background-image: repeating-linear-gradient(90deg, transparent, transparent 2px, #8c8d87 2px, #8c8d87 3.5px) !important; /* Gripping ridges */
            }
            .switch input:checked + .slider {
                background-color: #9bbc0f !important; /* Glows classic LCD screen green when ON */
            }
            .switch input:checked + .slider::before {
                transform: translateX(20px) !important;
                background-color: #d1d2cc !important; /* Slider remains grey but moves right */
            }

            /* --- FILE STUDIO / CONTEXT ACTIVE SELECTION CIRCULAR BUTTONS --- */
            [id^='sui-fs-history'], [id^='sui-fs-refresh'], [id^='sui-fs-download-small'], [id^='sui-fs-copy-small'], [id^='sui-fs-clear'], .sui-fs-search-clear {
                background-color: #7b7e80 !important; /* Matte rubber grey */
                border: 2px solid #1a1d16 !important;
                border-radius: 50% !important;
                width: 32px !important;
                height: 32px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.3),
                    inset -1px -1px 1px rgba(0,0,0,0.4),
                    0 3px 0 #4a4d50,
                    0 4px 6px rgba(0,0,0,0.3) !important;
                transition: transform 0.05s ease, box-shadow 0.05s ease !important;
                transform: none !important;
            }
            [id^='sui-fs-history']:active, [id^='sui-fs-refresh']:active, [id^='sui-fs-download-small']:active, [id^='sui-fs-copy-small']:active, [id^='sui-fs-clear']:active, .sui-fs-search-clear:active {
                transform: translateY(2px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    inset -1px -1px 1px rgba(255,255,255,0.15),
                    0 1px 0 #4a4d50,
                    0 2px 4px rgba(0,0,0,0.2) !important;
            }
            [id^='sui-fs-history'] svg, [id^='sui-fs-refresh'] svg, [id^='sui-fs-download-small'] svg, [id^='sui-fs-copy-small'] svg, [id^='sui-fs-clear'] svg, .sui-fs-search-clear svg {
                stroke: #1a1d16 !important;
                width: 14px !important;
                height: 14px !important;
            }
            [id^='sui-fs-clear'] svg, .sui-fs-search-clear svg {
                stroke: #a92031 !important; /* Red X / trash icons for danger clarity */
            }

            /* --- FILE STUDIO SEARCH EXECUTE BUTTON (Glossy Red A-button style) --- */
            .sui-fs-search-execute {
                background-color: #a92031 !important;
                color: #ffffff !important;
                border: 2px solid #1a1d16 !important;
                border-radius: 50% !important; /* Render search arrow execute as circle */
                width: 32px !important;
                height: 32px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 !important;
                transform: none !important;
                box-shadow: 
                    inset 1px 1px 2px rgba(255,255,255,0.4),
                    inset -1.5px -1.5px 2px rgba(0,0,0,0.4),
                    0 3px 0 #630c17,
                    0 4px 6px rgba(0,0,0,0.3) !important;
                transition: transform 0.05s ease, box-shadow 0.05s ease !important;
            }
            .sui-fs-search-execute:active {
                transform: translateY(2px) !important;
                box-shadow: 
                    inset 1px 1px 2px rgba(255,255,255,0.2),
                    inset -1.5px -1.5px 2px rgba(0,0,0,0.5),
                    0 1px 0 #630c17,
                    0 2px 4px rgba(0,0,0,0.2) !important;
            }
            .sui-fs-search-execute svg {
                stroke: #ffffff !important;
                width: 14px !important;
                height: 14px !important;
            }

            /* --- ALL / JSON SELECTION CONTROLS & FILTER BUTTONS (START/SELECT flat grey style) --- */
            [id^='sui-fs-all-toggle'], [id^='sui-fs-json-toggle'], .sui-fs-filter-btn, .sui-fs-path-btn, .sui-fs-fuzzy-btn {
                background-color: #7b7e80 !important;
                color: #1a1d16 !important;
                border: 2px solid #1a1d16 !important;
                border-radius: 6px !important;
                font-family: 'VT323', monospace !important;
                font-size: 14px !important;
                font-weight: bold !important;
                padding: 4px 10px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                transform: none !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.25),
                    inset -1px -1px 1px rgba(0,0,0,0.35),
                    0 3px 0 #4a4d50,
                    0 4px 6px rgba(0,0,0,0.3) !important;
                transition: transform 0.05s ease, box-shadow 0.05s ease !important;
            }
            [id^='sui-fs-all-toggle']:active, [id^='sui-fs-json-toggle']:active, .sui-fs-filter-btn:active, .sui-fs-path-btn:active, .sui-fs-fuzzy-btn:active {
                transform: translateY(2px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    inset -1px -1px 1px rgba(255,255,255,0.1),
                    0 1px 0 #4a4d50,
                    0 2px 4px rgba(0,0,0,0.2) !important;
            }
            
            /* --- INLINE ACTIVE FILTERS AND SWITCHED STATES (Embossed glowing screen feel via style detection) --- */
            .sui-fs-filter-btn[style*='var(--primary)'], 
            .sui-fs-path-btn[style*='var(--primary)'], 
            .sui-fs-fuzzy-btn[style*='var(--ai-accent)'],
            .sui-fs-fuzzy-btn[style*='var(--primary)'] {
                background-color: #9bbc0f !important; /* LCD green on active filters */
                color: #0f380f !important;
                border-color: #0f380f !important;
                transform: translateY(2px) !important;
                box-shadow: 
                    inset 0 2px 4px rgba(0,0,0,0.4),
                    0 1px 0 rgba(255,255,255,0.2) !important;
            }

            /* --- CONTEXT SELECTION BADGES & FILE CHIPS (Specs sticker label style) --- */
            .sui-badge-ai, .sui-badge-default, .sui-badge-success, [id^='sui-fs-active-box'] .meta-badge {
                background-color: #e2e2df !important; /* Sticker label white-grey */
                border: 1.5px solid #9b9c96 !important;
                color: #1a1d16 !important;
                border-radius: 4px !important;
                font-family: 'VT323', monospace !important;
                font-size: 15px !important;
                font-weight: bold !important;
                box-shadow: inset 0 1px 2px rgba(255,255,255,0.5), 0 2px 4px rgba(0,0,0,0.1) !important;
                padding: 4px 8px !important;
            }

            /* --- LEGIBLE CARD-SPECIFIC METADATA BADGES (Retro Specs Sticker Labels) --- */
            .card .meta-badge {
                background-color: #e2e2df !important;
                border: 1.5px solid #9b9c96 !important;
                color: #0f380f !important;
                border-radius: 4px !important;
                font-size: 13px !important;
                font-weight: bold !important;
                text-shadow: none !important;
                box-shadow: inset 0 1px 1px rgba(255,255,255,0.5), 0 1px 3px rgba(0,0,0,0.15) !important;
            }
            .card .meta-badge,
            .card .meta-badge * {
                color: #0f380f !important;
            }

            /* AI / Smart Badges Override (Skeuomorphic Light Purple specs label with purple ink) */
            .card .meta-badge.sui-badge-ai {
                background-color: #eae2f8 !important;
                border: 1.5px solid #b3a2df !important;
            }
            .card .meta-badge.sui-badge-ai,
            .card .meta-badge.sui-badge-ai * {
                color: #8b3cd4 !important;
            }

            /* Todo Badges Override (Skeuomorphic Light Yellow specs label with warm amber ink) */
            .card .meta-badge.sui-badge-todo {
                background-color: #fdf6e2 !important;
                border: 1.5px solid #d3b53d !important;
            }
            .card .meta-badge.sui-badge-todo,
            .card .meta-badge.sui-badge-todo * {
                color: #b58900 !important;
            }

            /* Danger Badges Override (Skeuomorphic Light Red specs label with crimson ink) */
            .card .meta-badge.sui-badge-danger {
                background-color: #fce8e6 !important;
                border: 1.5px solid #e1b1af !important;
            }
            .card .meta-badge.sui-badge-danger,
            .card .meta-badge.sui-badge-danger * {
                color: #a92031 !important;
            }
            /* Excluded selection badges are greyed out specs labels */
            [id^='sui-fs-active-box'] .meta-badge[style*='opacity'] {
                background-color: #b8b9b2 !important;
                border-color: #7b7e80 !important;
                opacity: 0.55 !important;
                box-shadow: none !important;
            }

            /* --- PROJECT SCOPE AND HIERARCHY ACCORDION ROWS (Grey cartridge plates) --- */
            [id^='ce-fs-projects'] > div, [id^='ce-fs-projects'] div[data-path], [id^='ce-fs-foundation'] div[data-path], [id^='ce-fs-categories'] > div {
                background-color: #d1d2cc !important; /* Base cartridge grey plate */
                border: 2px solid #8c8d87 !important;
                border-radius: 6px !important;
                box-shadow: 
                    inset 1px 1px 0px rgba(255,255,255,0.4),
                    inset -1px -1px 0px rgba(0,0,0,0.2),
                    0 3px 6px rgba(0,0,0,0.08) !important;
                margin-bottom: 6px !important;
            }
            [id^='ce-fs-projects'] *, [id^='ce-fs-foundation'] *, [id^='ce-fs-categories'] * {
                color: #0f380f !important;
                font-family: 'VT323', monospace !important;
                font-size: 16px !important;
            }

            /* Skeuomorphic tactile up/down reorder buttons on Category and Foundation lists */
            [id^='ce-fs-categories'] button, [id^='ce-fs-foundation'] button {
                background-color: #7b7e80 !important; /* Rubber grey */
                border: 2px solid #1a1d16 !important;
                border-radius: 6px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.3),
                    inset -1px -1px 1px rgba(0,0,0,0.4),
                    0 2px 0 #4a4d50,
                    0 3px 5px rgba(0,0,0,0.2) !important;
                transition: transform 0.05s ease, box-shadow 0.05s ease !important;
                transform: none !important;
            }
            [id^='ce-fs-categories'] button:active, [id^='ce-fs-foundation'] button:active {
                transform: translateY(1.5px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    0 0.5px 0 #4a4d50,
                    0 1px 2px rgba(0,0,0,0.15) !important;
            }
            [id^='ce-fs-categories'] button svg, [id^='ce-fs-foundation'] button svg {
                stroke: #1a1d16 !important;
                stroke-width: 3.5px !important;
            }
            [id^='ce-fs-projects'] div[style*='opacity: 0.5'], [id^='ce-fs-foundation'] div[style*='opacity: 0.5'] {
                background-color: #b0b1a8 !important; /* Debossed dark well for excluded files */
                border-color: #7b7e80 !important;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.15) !important;
            }

            /* Prevent !important display overrides from un-hiding dynamically hidden elements */
            #select-btn[style*='display:none'], #select-btn[style*='display: none'],
            .icon-btn[style*='display:none'], .icon-btn[style*='display: none'],
            .btn-icon-only[style*='display:none'], .btn-icon-only[style*='display: none'],
            #organizer-bar-wrapper button[style*='display:none'], #organizer-bar-wrapper button[style*='display: none'],
            #organizer-search-row button[style*='display:none'], #organizer-search-row button[style*='display: none'] {
                display: none !important;
            }

            /* --- SKEUOMORPHIC SHINY DOG-EAR CORNER STICKER --- */
            .dog-ear-zone {
                width: 32px !important;
                height: 32px !important;
            }
            .dog-ear-visual {
                width: 32px !important;
                height: 32px !important;
                border: none !important; /* Disable standard flat borders */
                filter: drop-shadow(1px 2px 2px rgba(0,0,0,0.3)) !important;
                position: absolute !important;
                top: 0 !important;
            }
            .card.is-dogeared .dog-ear-visual {
                opacity: 1 !important;
            }
            .card.is-dogeared .dog-ear-visual::after {
                content: '★' !important;
                position: absolute !important;
                top: 3px !important;
                font-size: 9px !important;
                color: #ffffff !important;
                text-shadow: 0 1px 1px rgba(0,0,0,0.6) !important;
                font-family: monospace !important;
                line-height: 1 !important;
            }

            /* LEFT POSITION OVERRIDES */
            body.de-pos-left .dog-ear-zone {
                left: 0 !important;
                right: auto !important;
            }
            body.de-pos-left .dog-ear-visual {
                left: 0 !important;
                right: auto !important;
                background: linear-gradient(135deg, #f25a5a 0%, #a92031 50%, #630c17 100%) !important; /* Glossy red */
                clip-path: polygon(0 0, 100% 0, 0 100%) !important;
                box-shadow: inset 1px 1px 1px rgba(255,255,255,0.5) !important;
            }
            body.de-pos-left .dog-ear-visual::after {
                left: 5px !important;
                right: auto !important;
            }

            /* RIGHT POSITION OVERRIDES */
            body.de-pos-right .dog-ear-zone {
                right: 0 !important;
                left: auto !important;
            }
            body.de-pos-right .dog-ear-visual {
                right: 0 !important;
                left: auto !important;
                background: linear-gradient(225deg, #f25a5a 0%, #a92031 50%, #630c17 100%) !important; /* Glossy red reversed */
                clip-path: polygon(0 0, 100% 0, 100% 100%) !important;
                box-shadow: inset -1px 1px 1px rgba(255,255,255,0.5) !important;
            }
            body.de-pos-right .dog-ear-visual::after {
                right: 5px !important;
                left: auto !important;
            }

            /* High-Fidelity Compact Buttons for Standalone Apps Cartridges */
            .ce-app-load.btn-primary, .ce-app-replace.btn-primary {
                font-size: 11px !important;
                padding: 5px 2px !important;
                border: 1.5px solid #1a1d16 !important;
                border-radius: 6px !important;
                letter-spacing: 0px !important;
                min-height: auto !important;
                height: auto !important;
            }
            /* ADD Button (Grey B-Button) */
            .ce-app-load.btn-primary {
                background-color: #7b7e80 !important;
                color: #1a1d16 !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.3),
                    inset -1px -1px 1px rgba(0,0,0,0.4),
                    0 2px 0 #4a4d50,
                    0 3px 4px rgba(0,0,0,0.2) !important;
            }
            .ce-app-load.btn-primary:active {
                transform: translateY(1.5px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    0 0.5px 0 #4a4d50,
                    0 1px 2px rgba(0,0,0,0.15) !important;
            }
            /* REPLACE Button (Red A-Button) */
            .ce-app-replace.btn-primary {
                background-color: #a92031 !important;
                color: #ffffff !important;
                box-shadow: 
                    inset 1px 1px 1.5px rgba(255,255,255,0.4),
                    inset -1px -1px 1.5px rgba(0,0,0,0.4),
                    0 2px 0 #630c17,
                    0 3px 4px rgba(0,0,0,0.3) !important;
            }
            .ce-app-replace.btn-primary:active {
                transform: translateY(1.5px) !important;
                box-shadow: 
                    inset 1px 1px 1.5px rgba(255,255,255,0.2),
                    inset -1px -1px 1.5px rgba(0,0,0,0.5),
                    0 0.5px 0 #630c17,
                    0 1px 2px rgba(0,0,0,0.15) !important;
            }

            /* --- SKEUOMORPHIC FLOATING COMMAND BAR (FCB) --- */
            #fcb-container {
                background-color: #c5c6c0 !important; /* Classic DMG grey body */
                border: 3px solid #1a1d16 !important;
                box-shadow: 
                    inset 1.5px 1.5px 0px rgba(255,255,255,0.5),
                    inset -1.5px -1.5px 0px rgba(0,0,0,0.3),
                    0 10px 25px rgba(0,0,0,0.3) !important;
                border-radius: 30px !important;
            }
            body.fcb-docked #fcb-container {
                border-radius: 0 !important;
                border-bottom: none !important;
                border-left: none !important;
                border-right: none !important;
                box-shadow: inset 0 2px 0 rgba(255,255,255,0.4) !important;
            }
            
            /* Style buttons inside FCB */
            #fcb-left {
                border-right: 2px solid #8c8d87 !important;
            }
            #fcb-btn-record {
                background-color: #7b7e80 !important; /* Matte grey */
                border: 2px solid #1a1d16 !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.3),
                    inset -1px -1px 1px rgba(0,0,0,0.4),
                    0 2px 0 #4a4d50,
                    0 3px 5px rgba(0,0,0,0.2) !important;
            }
            body.is-recording #fcb-btn-record {
                background-color: #a92031 !important; /* Active glowing red */
                box-shadow: 
                    0 0 8px #f21d1d,
                    0 0 15px #f21d1d,
                    inset 1px 1px 2px rgba(255,255,255,0.4) !important;
            }
            #fcb-btn-back, #fcb-btn-omni, #fcb-btn-switch, #fcb-btn-settings {
                background-color: #7b7e80 !important;
                border: 2px solid #1a1d16 !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(255,255,255,0.3),
                    inset -1px -1px 1px rgba(0,0,0,0.4),
                    0 2px 0 #4a4d50,
                    0 3px 5px rgba(0,0,0,0.2) !important;
            }
            #fcb-btn-back:active, #fcb-btn-omni:active, #fcb-btn-switch:active, #fcb-btn-settings:active {
                transform: translateY(2px) !important;
                box-shadow: 
                    inset 1px 1px 1px rgba(0,0,0,0.3),
                    0 0.5px 0 #4a4d50 !important;
            }
            
            /* Style Strip Items (Navigation labels) as Specs Labels */
            #fcb-container .fcb-strip-item {
                background-color: #e2e2df !important; /* Specs label white-grey */
                border: 1.5px solid #9b9c96 !important;
                color: #1a1d16 !important;
                border-radius: 6px !important;
                box-shadow: inset 0 1px 2px rgba(255,255,255,0.5), 0 2px 4px rgba(0,0,0,0.1) !important;
                height: 44px !important;
                margin: 0 2px !important;
                transition: transform 0.05s, box-shadow 0.05s !important;
            }
            #fcb-container .fcb-strip-item.active {
                background-color: #9bbc0f !important; /* LCD Active Green */
                border-color: #0f380f !important;
                color: #0f380f !important;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.2) !important;
                transform: scale(1) !important; /* Prevent size bloat over borders */
            }
            #fcb-container .fcb-strip-item.active::after {
                display: none !important; /* Cancel modern indicator line */
            }
            
            /* Fast Travel Dial Overlay (Styled as classic olive green LCD screen bezel) */
            #fcb-dial-overlay {
                background-color: #c5c6c0 !important; /* DMG Grey plate */
                border: 3px solid #1a1d16 !important;
                box-shadow: 
                    inset 1.5px 1.5px 0px rgba(255,255,255,0.5),
                    inset -1.5px -1.5px 0px rgba(0,0,0,0.3),
                    0 10px 25px rgba(0,0,0,0.4) !important;
                border-radius: 12px !important; /* Structured plate */
            }
            /* Embed tiny structural screw visual elements on the sides of the dial overlay */
            #fcb-dial-overlay::before {
                content: '•';
                position: absolute;
                top: 4px; left: 8px;
                font-size: 16px;
                color: #55595f;
                text-shadow: 0 1px 0 rgba(255,255,255,0.3);
            }
            #fcb-dial-overlay::after {
                content: '•';
                position: absolute;
                top: 4px; right: 8px;
                font-size: 16px;
                color: #55595f;
                text-shadow: 0 1px 0 rgba(255,255,255,0.3);
            }
            .fcb-dial-item {
                background-color: #9bbc0f !important; /* LCD Green window inside Dial */
                border: 2px solid #0f380f !important;
                border-radius: 4px !important;
                margin: 0 4px !important;
                height: 58px !important;
                width: 58px !important;
                color: #0f380f !important;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.3) !important;
                font-family: 'VT323', monospace !important;
                opacity: 0.45 !important;
            }
            .fcb-dial-item.active {
                opacity: 1.0 !important;
                box-shadow: inset 0 2px 5px rgba(0,0,0,0.5), 0 0 8px rgba(155, 188, 15, 0.4) !important;
                transform: scale(1.1) !important;
            }
            .fcb-dial-item * {
                color: #0f380f !important;
            }
            
            /* Replace the flat clipping zone with a physical recessed sliding track */
            #fcb-right {
                margin: 4px 12px 4px 6px !important;
                height: 52px !important;
                border-radius: 8px !important;
                background-color: #b0b1a8 !important; /* Darker recessed DMG plastic */
                border: 2px solid #8c8d87 !important;
                box-shadow: inset 2px 2px 4px rgba(0,0,0,0.3), 0 1px 1px rgba(255,255,255,0.5) !important;
                overflow: hidden !important;
            }
            #fcb-container .fcb-strip {
                padding: 0 4px !important;
                align-items: center !important;
            }
            
            /* Fast Travel Notification layer styled as discrete LCD pop-up box */
            #fcb-notif-layer {
                background-color: #9bbc0f !important; /* LCD green */
                border: 2px solid #0f380f !important;
                border-radius: 4px !important;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.4) !important;
            }
            #fcb-notif-layer .ft-text-inner {
                color: #0f380f !important;
                font-family: 'VT323', monospace !important;
                font-size: 14px !important;
            }

            /* Remove the speaker grille entirely */
            #fcb-container::after {
                display: none !important;
            }

            @media (min-width: 1024px) {
                #fcb-right {
                    background: transparent !important;
                    border: none !important;
                    box-shadow: none !important;
                    margin: 0 16px 0 6px !important;
                    height: 100% !important;
                }
            }
        "
    ]
];

$theme = reset($themeData);

$theme['js'] = <<<'JS'
window.tp_init_conjureBoy = function() {
    // Prevent layout shift pointer triggers during theme initialization using a physical blocker
    let blocker = document.getElementById('cb-click-blocker');
    if (!blocker) {
        blocker = document.createElement('div');
        blocker.id = 'cb-click-blocker';
        blocker.style.cssText = 'position:fixed; inset:0; z-index:999999; background:transparent; touch-action:none;';
        document.body.appendChild(blocker);
    }
    setTimeout(() => {
        if (blocker && blocker.parentNode) blocker.parentNode.removeChild(blocker);
    }, 750);

    // Sync DogEar position to body class
    const syncDogEarClass = () => {
        const pos = localStorage.getItem("cjos_dogear_pos") || "left";
        document.body.classList.remove("de-pos-left", "de-pos-right");
        document.body.classList.add("de-pos-" + pos);
    };
    syncDogEarClass();
    
    // Hijack setDogEarPos to update the body class in real-time when changed in settings
    if (typeof window.setDogEarPos === 'function') {
        const originalSet = window.setDogEarPos;
        window.setDogEarPos = function(pos) {
            originalSet(pos);
            syncDogEarClass();
        };
    }

    // Helper to retrieve GBC Theme Customizer options safely
    const getGbcVolume = (type) => {
        let val = 50; // default 50%
        try {
            const state = window.tpState;
            if (state && state.theme_options && state.theme_options.conjureBoy) {
                const optVal = state.theme_options.conjureBoy[type];
                if (optVal !== undefined) val = parseInt(optVal);
            }
        } catch(e) {}
        return val / 100;
    };

    // Play authentic GBC double chime boot sweep using Web Audio API
    const playGbcBoot = () => {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            if (!window._gbcAudioCtx) {
                window._gbcAudioCtx = new AudioCtx();
            }
            const ctx = window._gbcAudioCtx;
            if (ctx.state === 'suspended') {
                ctx.resume();
            }
            
            const volMultiplier = getGbcVolume('boot_vol');
            if (volMultiplier <= 0) return; // Muted

            const osc = ctx.createOscillator();
            const gainNode = ctx.createGain();
            
            osc.type = 'square';
            osc.connect(gainNode);
            gainNode.connect(ctx.destination);
            
            const now = ctx.currentTime;
            osc.frequency.setValueAtTime(987.77, now);
            osc.frequency.setValueAtTime(1318.51, now + 0.08);
            
            gainNode.gain.setValueAtTime(0, now);
            gainNode.gain.linearRampToValueAtTime(0.04 * volMultiplier, now + 0.04);
            gainNode.gain.setValueAtTime(0.04 * volMultiplier, now + 0.35);
            gainNode.gain.linearRampToValueAtTime(0, now + 0.45);
            
            osc.start(now);
            osc.stop(now + 0.45);
        } catch(e) {
            console.warn("Skeuomorphic boot chime failed:", e);
        }
    };

    // Ensure booting sound only executes on initial theme load, not on dynamic option slides
    if (!window._gbcBooted) {
        playGbcBoot();
        window._gbcBooted = true;
    }

    // --- SKEUOMORPHIC 8-BIT AUDIO SYNTHESIS ENGINE ---
    const playGbcSound = (type) => {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            
            // Reuse the persistent single context
            if (!window._gbcAudioCtx) {
                window._gbcAudioCtx = new AudioCtx();
            }
            const ctx = window._gbcAudioCtx;
            if (ctx.state === 'suspended') {
                ctx.resume();
            }
            
            const volMultiplier = getGbcVolume('click_vol');
            if (volMultiplier <= 0) return; // Muted
            
            const osc = ctx.createOscillator();
            const gainNode = ctx.createGain();
            osc.connect(gainNode);
            gainNode.connect(ctx.destination);
            
            const now = ctx.currentTime;
            
            if (type === 'primary') {
                // Confident A-Button/Primary selection beep
                osc.type = 'square';
                osc.frequency.setValueAtTime(1400, now);
                osc.frequency.setValueAtTime(1800, now + 0.05);
                gainNode.gain.setValueAtTime(0, now);
                gainNode.gain.linearRampToValueAtTime(0.03 * volMultiplier, now + 0.01);
                gainNode.gain.setValueAtTime(0.03 * volMultiplier, now + 0.05);
                gainNode.gain.linearRampToValueAtTime(0, now + 0.12);
                osc.start(now);
                osc.stop(now + 0.13);
            } else if (type === 'secondary') {
                // Neutral soft-releasing B-Button/Cancel tone
                osc.type = 'square';
                osc.frequency.setValueAtTime(650, now);
                osc.frequency.exponentialRampToValueAtTime(120, now + 0.08);
                gainNode.gain.setValueAtTime(0, now);
                gainNode.gain.linearRampToValueAtTime(0.04 * volMultiplier, now + 0.01);
                gainNode.gain.linearRampToValueAtTime(0, now + 0.08);
                osc.start(now);
                osc.stop(now + 0.09);
            } else {
                // Subtle mechanical direction/folder tap click
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(700, now);
                osc.frequency.exponentialRampToValueAtTime(180, now + 0.04);
                gainNode.gain.setValueAtTime(0, now);
                gainNode.gain.linearRampToValueAtTime(0.05 * volMultiplier, now + 0.01);
                gainNode.gain.linearRampToValueAtTime(0, now + 0.04);
                osc.start(now);
                osc.stop(now + 0.05);
            }
        } catch(e) {
            console.warn("Click synthesis error:", e);
        }
    };

    // --- TACTILE HAPTIC VIBRATION ROUTER ---
    const triggerHaptic = (type) => {
        try {
            if (typeof window.sui !== 'undefined' && typeof window.sui.haptic === 'function') {
                window.sui.haptic(type === 'primary' ? 'medium' : 'light');
            } else if (navigator.vibrate) {
                navigator.vibrate(type === 'primary' ? [15, 10, 15] : 10);
            }
        } catch(e) {}
    };

    // --- GLOBAL EVENT DELEGATION LISTENER ---
    let lastClickTime = 0;
    const _cbInitTime = Date.now();
    const handleGlobalGbcClick = (e) => {
        if (Date.now() - _cbInitTime < 750) return; // Prevent bubbling click from the theme picker itself
        
        const target = e.target.closest('button, a, .org-chip, .picker-item, input[type="checkbox"], .switch, .fcb-strip-item, .fcb-dial-item, [onclick]');
        if (!target) return;
        
        // Prevent double triggers inside label/input couplings (standard browser click bubble)
        const now = Date.now();
        if (now - lastClickTime < 25) return;
        lastClickTime = now;
        
        let type = 'default';
        if (target.classList.contains('done-btn') || 
            target.classList.contains('btn-primary') || 
            target.classList.contains('edit-save') || 
            target.id === 'dialog-ok' ||
            target.classList.contains('ce-app-replace') ||
            target.classList.contains('danger') ||
            target.classList.contains('btn-danger')) {
            type = 'primary';
        } else if (target.classList.contains('btn-secondary') || 
                   target.classList.contains('text-btn') || 
                   target.id === 'dialog-cancel' || 
                   target.classList.contains('edit-cancel') ||
                   target.classList.contains('ce-app-load')) {
            type = 'secondary';
        }
        
        playGbcSound(type);
        triggerHaptic(type);
    };

    // Safely remove existing listeners before binding to prevent overlap
    if (window._gbcClickListener) {
        document.removeEventListener('click', window._gbcClickListener);
    }
    document.addEventListener('click', handleGlobalGbcClick, { passive: true });
    window._gbcClickListener = handleGlobalGbcClick;

    // --- SLIDER PREVIEW HOOK ---
    let _gbcPreviewTimer = null;
    window.tp_preview_option_conjureBoy = function(optId, value) {
        if (_gbcPreviewTimer) clearTimeout(_gbcPreviewTimer);
        _gbcPreviewTimer = setTimeout(() => {
            if (optId === 'boot_vol') playGbcBoot();
            if (optId === 'click_vol') playGbcSound('primary');
        }, 200);
    };
};

window.tp_destroy_conjureBoy = function() {
    // Prevent layout shift pointer triggers during theme teardown using a physical blocker
    let blocker = document.getElementById('cb-click-blocker');
    if (!blocker) {
        blocker = document.createElement('div');
        blocker.id = 'cb-click-blocker';
        blocker.style.cssText = 'position:fixed; inset:0; z-index:999999; background:transparent; touch-action:none;';
        document.body.appendChild(blocker);
    }
    setTimeout(() => {
        if (blocker && blocker.parentNode) blocker.parentNode.removeChild(blocker);
    }, 750);

    if (window.tp_preview_option_conjureBoy) {
        delete window.tp_preview_option_conjureBoy;
    }
    document.body.classList.remove("de-pos-left", "de-pos-right");
    if (window._gbcClickListener) {
        document.removeEventListener('click', window._gbcClickListener);
        delete window._gbcClickListener;
    }
    // Safely stop and destroy persistent audio contexts
    if (window._gbcAudioCtx) {
        try {
            window._gbcAudioCtx.close();
        } catch(e) {}
        delete window._gbcAudioCtx;
    }
    delete window._gbcBooted;
};
JS;

return $theme;