<?php
return [
    'name' => "Skyward Jackie",
    'vars' => [
        "--bg-color" => "#4A90E2", 
        "--card-bg" => "#D0021B", 
        "--header-bg" => "rgba(74, 144, 226, 0.95)",
        "--text-primary" => "#FFFFFF", 
        "--text-secondary" => "#E1E1E6", 
        "--text-title" => "#FFFFFF",
        "--primary" => "#FFFFFF", 
        "--btn-bg" => "rgba(255, 255, 255, 0.15)", 
        "--btn-text" => "#FFFFFF",
        "--input-bg" => "rgba(255, 255, 255, 0.1)", 
        "--input-text" => "#FFFFFF", 
        "--primary-text" => "#D0021B",
        "--border-color" => "rgba(255, 255, 255, 0.2)", 
        "--shadow-card" => "0 20px 40px rgba(0, 0, 0, 0.25)",
        "--ai-accent" => "#FF6B6B", 
        "--ai-accent-bg" => "rgba(255, 107, 107, 0.2)",
        "--player-active" => "#FFFFFF"
    ],
    'extra' => "
        .app-frame { 
            background: linear-gradient(to bottom, #4A90E2 0%, #357ABD 100%) !important; 
        }
        .scroll-view { background: transparent !important; }
        .card { 
            border: none !important; 
            border-radius: 20px !important;
            margin-bottom: 25px !important;
            transition: transform 0.3s cubic-bezier(0.2, 0, 0.2, 1) !important;
        }
        .card:active { transform: scale(0.97); }
        .section-header { 
            background: transparent !important; 
            color: #FFFFFF !important; 
            font-weight: 800 !important; 
            text-transform: uppercase; 
            letter-spacing: 1px;
            padding-top: 40px !important;
            border-bottom: 1px solid rgba(255,255,255,0.3) !important;
        }
        .page-title { 
            font-weight: 900 !important; 
            font-style: normal !important;
            letter-spacing: -1px !important;
            text-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .top-bar, .selection-bottom-bar { 
            background: rgba(74, 144, 226, 0.8) !important; 
            backdrop-filter: blur(20px) !important;
            -webkit-backdrop-filter: blur(20px) !important;
            border-bottom: 1px solid rgba(255,255,255,0.1) !important;
        }
        .done-btn, .btn-primary { 
            background: #FFFFFF !important; 
            color: #D0021B !important; 
            border-radius: 12px !important;
            font-weight: 800 !important;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15) !important;
        }
        .player-capsule { 
            background: rgba(255, 255, 255, 0.1) !important; 
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            backdrop-filter: blur(10px) !important;
        }
        .time-badge { color: rgba(255, 255, 255, 0.8) !important; font-weight: 600 !important; }
        .meta-badge { 
            background: rgba(255, 255, 255, 0.15) !important; 
            color: #FFFFFF !important; 
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
        }
        .org-chip { 
            background: rgba(255, 255, 255, 0.1) !important; 
            color: #FFFFFF !important; 
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
        }
        .org-chip.folder-active { background: #FFFFFF !important; color: #4A90E2 !important; }
    "
];