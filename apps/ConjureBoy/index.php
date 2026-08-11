<?php
// apps/ConjureBoy/index.php

// Prevent browser caching of the entry point page to avoid stale core/state detection
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Ensure data folder exists
$data_dir = __DIR__ . '/data';
if (!is_dir($data_dir)) {
    mkdir($data_dir, 0777, true);
}

// Ensure covers directory exists
$covers_dir = $data_dir . '/covers';
if (!is_dir($covers_dir)) {
    mkdir($covers_dir, 0777, true);
}

// Ensure fonts directory exists and download VT323-Regular.ttf if missing for offline compliance
$fonts_dir = $data_dir . '/fonts';
if (!is_dir($fonts_dir)) {
    mkdir($fonts_dir, 0777, true);
}
$font_path = $fonts_dir . '/VT323-Regular.ttf';
if (!file_exists($font_path)) {
    $font_url = "https://raw.githubusercontent.com/google/fonts/main/ofl/vt323/VT323-Regular.ttf";
    $ch = curl_init($font_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $font_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && !empty($font_data)) {
        file_put_contents($font_path, $font_data);
    } else {
        // Fallback directly to jsDelivr if raw.github is blocked
        $fallback_font_url = "https://cdn.jsdelivr.net/gh/google/fonts@main/ofl/vt323/VT323-Regular.ttf";
        $font_data = @file_get_contents($fallback_font_url);
        if (!empty($font_data)) {
            file_put_contents($font_path, $font_data);
        }
    }
}

// 1. Initialize Core
require __DIR__ . '/modules/db.php';

// 2. Load settings
$settings_path = $data_dir . '/settings.json';
if (!file_exists($settings_path)) {
    $default_settings = [
        "theme" => "classic-dmg",
        "lcd_grid" => true,
        "sound_volume" => 0.5,
        "haptics_enabled" => true,
        "magnifier_scale" => 1.15,
        "keyboard_bindings" => [
            "ArrowUp" => "UP",
            "ArrowDown" => "DOWN",
            "ArrowLeft" => "LEFT",
            "ArrowRight" => "RIGHT",
            "KeyZ" => "A",
            "KeyX" => "B",
            "Enter" => "START",
            "ShiftLeft" => "SELECT"
        ],
        "design_tokens" => [
            "--bg-color" => "#131416",
            "--card-bg" => "#1e2022",
            "--text-primary" => "#f8f9fa",
            "--text-secondary" => "#a6abb1",
            "--primary-accent" => "#8b956d",
            "--font-main" => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
            "--radius-container" => "20px"
        ]
    ];
    file_put_contents($settings_path, json_encode($default_settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$settings = json_decode(file_get_contents($settings_path), true);

// 3. API/AJAX routing check
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    require __DIR__ . '/modules/api.php';
    exit;
}

// 4. Asset fingerprinting
function get_asset_hash($files) {
    $combined = '';
    foreach ($files as $f) {
        $path = __DIR__ . '/' . $f;
        if (file_exists($path)) {
            $combined .= md5_file($path);
        }
    }
    return $combined ? substr(md5($combined), 0, 8) : 'dev';
}

$v = get_asset_hash(['css/style.css', 'js/app.js']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>ConjureBoy - Console</title>
    <meta name="description" content="A highly tactile, aesthetic retro Game Boy emulator custom-built on Conjure OS. Experience realistic handheld console simulation, featuring an elastically bouncy screen magnifier with an incandescent light toggle, a sliding auxiliary control drawer with quick save/load states, turbo-fire, customizable speed fast-forwards, and a pre-loaded, zero-registration public ROM cartridge shelf featuring titles like Capybara Garden and indie retro demakes.">
    <style>
        :root {
            <?php foreach ($settings['design_tokens'] as $token => $val): ?>
                <?php echo strip_tags($token); ?>: <?php echo strip_tags($val); ?>;
            <?php endforeach; ?>
            --magnifier-scale: <?php echo htmlspecialchars($settings['magnifier_scale'] ?? 1.15); ?>;
        }
    </style>
    <link rel="stylesheet" href="css/style.css?v=<?php echo $v; ?>">
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="ConjureBoy">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
</head>
<body class="theme-<?php echo htmlspecialchars($settings['theme']); ?>">
    <div id="app-container">
        <!-- Main Bento Split Layout -->
        <div class="split-layout">
            
            <!-- Left Panel: Handheld Console Assembly -->
            <div class="console-column">
                <div class="console-viewport-container">
                    
                    <!-- Dynamic Console Body -->
                    <div class="console-body">
                        <!-- Top Cartridge Entry Slot Background shadow -->
                        <div class="console-top-slot">
                            <div class="cartridge-bezel-shadow"></div>
                            <!-- Loaded cartridge mock element -->
                            <div class="loaded-cartridge" id="loaded-cartridge" style="display: none;">
                                <div class="cart-label">GB</div>
                            </div>
                        </div>

                        <!-- Retro Screen Magnifier Accessory -->
                        <div class="magnifier-accessory" id="magnifier-acc" data-scale="<?php echo htmlspecialchars($settings['magnifier_scale'] ?? 1.15); ?>">
                            <div class="magnifier-bracket">
                                <div class="magnifier-hinge left"></div>
                                <div class="magnifier-hinge right"></div>
                                <div class="magnifier-frame">
                                    <div class="magnifier-lens">
                                        <div class="lens-glare"></div>
                                        <div class="lens-distortion"></div>
                                    </div>
                                    <div class="magnifier-logo">CONJURE BOOSTER</div>
                                    
                                    <!-- Built-in Light bulbs -->
                                    <div class="magnifier-light-bulb left"></div>
                                    <div class="magnifier-light-bulb right"></div>
                                    
                                    <!-- Zoom rate adjust buttons -->
                                    <div class="magnifier-zoom-controls" id="magnifier-zoom-ctrl">
                                        <button class="zoom-btn" id="btn-zoom-down" title="Decrease Magnification">-</button>
                                        <span class="zoom-label" id="zoom-value-label"><?php echo number_format($settings['magnifier_scale'] ?? 1.15, 2); ?>x</span>
                                        <button class="zoom-btn" id="btn-zoom-up" title="Increase Magnification">+</button>
                                    </div>
                                    
                                    <!-- Incandescent Light toggle -->
                                    <div class="magnifier-light-toggle" id="btn-magnifier-light">
                                        <div class="toggle-switch-nob"></div>
                                        <span class="light-label">LIGHT</span>
                                    </div>
                                </div>
                            </div>
                            <div class="magnifier-pull-tab" id="magnifier-tab">
                                <div class="tab-ridges"></div>
                            </div>
                        </div>

                        <!-- Screen Bezel Container Clipper -->
                        <div class="screen-clipper">
                            <!-- Screen Bezel Glass Panel -->
                            <div class="screen-bezel">
                                <div class="bezel-sheen-shine"></div>
                            <div class="bezel-header">
                                <div class="power-block">
                                    <div class="power-led led-off" id="power-led"></div>
                                    <span class="power-label">POWER</span>
                                </div>
                                <div class="system-title">
                                    <span class="c-c">C</span><span class="c-o">o</span><span class="c-n">n</span><span class="c-j">j</span><span class="c-u">u</span><span class="c-r">r</span><span class="c-e">e</span><span class="c-b">B</span><span class="c-oy">o</span><span class="c-g">y</span>
                                    <span class="c-color">COLOR</span>
                                </div>
                            </div>
                            
                            <!-- Internal Active Game Frame -->
                            <div class="screen-viewport">
                                <canvas id="emulator-screen" width="160" height="144"></canvas>
                                <!-- LCD Retro Matrix Overlay Shield -->
                                <div class="lcd-grid-overlay <?php echo $settings['lcd_grid'] ? 'active' : ''; ?>" id="lcd-overlay"></div>
                                
                                <!-- Retro Incandescent Screen Light Glow Overlay -->
                                <div class="screen-light-glow" id="screen-light-glow"></div>

                                <!-- Standby offline overlay -->
                                <div class="standby-screen" id="standby-screen">
                                    <div class="icon-pulse">🎮</div>
                                    <p>Insert Rom Cartridge</p>
                                    <span class="sub-label">Drag ROM to side panel or tap Library</span>
                                </div>
                            </div>
                        </div>
                        </div>

                        <!-- Brand Logotype -->
                        <div class="brand-logotype">N i n t e n d o</div>

                        <!-- Auxiliary Expansion Space -->
                        <div class="aux-space" id="aux-space">
                            <div class="aux-plate">
                                <!-- Col 1: Quick Save/Revert -->
                                <div class="aux-group">
                                    <span class="aux-print-label">Quick State</span>
                                    <div class="aux-buttons-row">
                                        <button class="aux-rubber-btn" id="btn-quick-save" title="Quick Save">SAVE</button>
                                        <button class="aux-rubber-btn" id="btn-aux-load" title="Quick Load">LOAD</button>
                                    </div>
                                </div>

                                <!-- Col 2: Rapid Turbo Mode Switch -->
                                <div class="aux-group">
                                    <span class="aux-print-label">Turbo</span>
                                    <div class="aux-slider-switch" id="toggle-rapid-fire">
                                        <div class="switch-nob"></div>
                                    </div>
                                </div>

                                <!-- Col 3: Fast Forward Speed -->
                                <div class="aux-group">
                                    <span class="aux-print-label">Game Speed</span>
                                    <div class="speed-controls-row">
                                        <button class="aux-speed-btn" id="btn-speed-down">-</button>
                                        <span class="speed-indicator" id="speed-indicator">1.0x</span>
                                        <button class="aux-speed-btn" id="btn-speed-up">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Core Controls Section -->
                        <div class="controls-section">
                            <!-- Tactical D-pad -->
                            <div class="dpad-box">
                                <div class="dpad" id="dpad-cross">
                                    <button class="dpad-btn direction-up" data-btn="UP" id="ctrl-up"></button>
                                    <button class="dpad-btn direction-right" data-btn="RIGHT" id="ctrl-right"></button>
                                    <button class="dpad-btn direction-down" data-btn="DOWN" id="ctrl-down"></button>
                                    <button class="dpad-btn direction-left" data-btn="LEFT" id="ctrl-left"></button>
                                    <div class="dpad-center"></div>
                                </div>
                            </div>

                            <!-- Angled Primary Interactive A/B Buttons -->
                            <div class="action-buttons-box">
                                <div class="action-btn-container b-btn-container">
                                    <button class="action-btn" data-btn="B" id="ctrl-b">B</button>
                                </div>
                                <div class="action-btn-container a-btn-container">
                                    <button class="action-btn" data-btn="A" id="ctrl-a">A</button>
                                </div>
                            </div>
                        </div>

                        <!-- System Utilities Pills (SELECT / START) -->
                        <div class="pills-section">
                            <div class="pill-container">
                                <button class="pill-btn" data-btn="SELECT" id="ctrl-select"></button>
                                <span class="pill-label">SELECT</span>
                            </div>
                            <div class="pill-container">
                                <button class="pill-btn" data-btn="START" id="ctrl-start"></button>
                                <span class="pill-label">START</span>
                            </div>
                        </div>

                        <!-- Console Utility Shelf Drawer Toggler (Lower Left) -->
<div class="shelf-btn-box">
    <button class="shelf-btn" id="btn-mobile-drawer" title="Toggle ROM Shelf Drawer"></button>
    <span class="shelf-label">SHELF</span>
</div>

<!-- Auxiliary Expansion Module Toggler -->
<div class="aux-btn-box">
    <button class="aux-btn" id="btn-aux" title="Toggle Auxiliary Expansion"></button>
    <span class="aux-label">AUX</span>
</div>

<!-- Fullscreen Toggler -->
<div class="fscrn-btn-box">
<button class="fscrn-btn" id="btn-fscrn" title="Toggle Fullscreen"></button>
<span class="fscrn-label">FSCRN</span>
</div>

<!-- Speakers Slits --><div class="speaker-slits">
                            <div class="slit"></div>
                            <div class="slit"></div>
                            <div class="slit"></div>
                            <div class="slit"></div>
                            <div class="slit"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Desktop Control Deck & ROM Library -->
            <div class="deck-column" id="deck-column">
                <!-- Close Button for Mobile Drawer -->
                <button class="deck-close-btn" id="btn-close-deck" title="Close Shelf">✕</button>
                <div class="deck-scroll-container">
                    
                    <!-- Auth & Profile Bento Zone -->
                    <div class="deck-card bento-auth" id="auth-card">
                        <div class="hardware-screw top-left"></div>
                        <div class="hardware-screw top-right"></div>
                        <div class="hardware-screw bottom-left"></div>
                        <div class="hardware-screw bottom-right"></div>
                        
                        <!-- Guest View -->
                        <div id="auth-guest-view">
                            <h3>👤 User Authentication</h3>
                            <p class="auth-desc">Log in to upload ROMs and save your game states.</p>
                            <div class="auth-form">
                                <input type="text" id="auth-username" class="custom-text-input" placeholder="Username" autocapitalize="none" autocorrect="off" spellcheck="false" autocomplete="off">
                                <input type="password" id="auth-password" class="custom-text-input" placeholder="Password">
                                <div class="auth-actions">
                                    <button class="btn btn-secondary" id="btn-register">Register</button>
                                    <button class="btn btn-primary" id="btn-login">Login</button>
                                </div>
                            </div>
                        </div>

                        <!-- Logged In View -->
                        <div id="auth-user-view" style="display: none;">
                            <div class="user-profile-header">
                                <h3>👤 <span id="profile-username">User</span></h3>
                                <span class="role-badge" id="profile-role">USER</span>
                            </div>
                            <div class="user-stats">
                                <span id="profile-quota">0 / 10 Cartridges</span>
                                <button class="btn btn-secondary btn-console-action" id="btn-logout">Logout</button>
                            </div>
                        </div>
                    </div>

                    <!-- File Upload Bento Zone -->
                    <div class="deck-card bento-upload" id="drop-zone" style="display: none;">
                        <input type="file" id="rom-file-input" accept=".gb,.gbc,.gba" style="display: none;">
                        <div class="upload-inner">
                            <span class="upload-icon">📥</span>
                            <h4>Drag & Drop ROMs</h4>
                            <p>or tap here to browse local storage (.gb, .gbc)</p>
                        </div>
                    </div>

                    <!-- ROM Cartridge Deck (Dynamic List) -->
                    <div class="deck-card bento-library">
                        <div class="hardware-screw top-left"></div>
                        <div class="hardware-screw top-right"></div>
                        <div class="hardware-screw bottom-left"></div>
                        <div class="hardware-screw bottom-right"></div>
                        <div class="deck-header">
                            <h3>💾 Cartridge Shelf</h3>
                            <div style="display:flex; gap:8px;">
                                <button class="btn btn-icon-only" id="btn-manual" title="Console Manual">📖</button>
                                <button class="btn btn-icon-only" id="btn-refresh-library" title="Refresh Shelves">🔄</button>
                            </div>
                        </div>
                        <div class="cartridge-deck-grid" id="cartridge-deck">
                            <!-- Loaded Dynamically as physical stickered cards -->
                            <div class="library-empty">
                                <div class="molded-slot-well">
                                    <div class="connector-pins"></div>
                                </div>
                                <p>No cartridges on shelf.</p>
                                <p class="sub">Upload files to populate the collection.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Save States & Execution Utilities -->
                    <div class="deck-card bento-utilities" id="util-card" style="display: none;">
                        <div class="deck-header">
                            <h3>⚙️ Cartridge Console Options</h3>
                        </div>
                        <div class="console-actions-grid">
                            <button class="btn btn-secondary btn-console-action" id="btn-pause-resume">⏸️ Pause</button>
                            <button class="btn btn-secondary btn-console-action" id="btn-reset">🔌 Hard Reset</button>
                            <button class="btn btn-danger btn-console-action" id="btn-eject"> eject </button>
                        </div>

                        <div class="saves-dock">
                            <h4>Save States (Server-Side Sync)</h4>
                            <div class="slots-grid">
                                <button class="slot-btn" data-slot="1">Slot 1<span class="state-date">Empty</span></button>
                                <button class="slot-btn" data-slot="2">Slot 2<span class="state-date">Empty</span></button>
                                <button class="slot-btn" data-slot="3">Slot 3<span class="state-date">Empty</span></button>
                                <button class="slot-btn" data-slot="4">Slot 4<span class="state-date">Empty</span></button>
                                <button class="slot-btn" data-slot="5">Slot 5<span class="state-date">Empty</span></button>
                            </div>
                        </div>
                    </div>

                    <!-- Customizer Console Presets settings -->
                    <div class="deck-card bento-customizer">
                        <div class="hardware-screw top-left"></div>
                        <div class="hardware-screw top-right"></div>
                        <div class="hardware-screw bottom-left"></div>
                        <div class="hardware-screw bottom-right"></div>
                        <h3>🎨 Aesthetic Dashboard</h3>
                        <div class="option-row">
                            <label>Console Shell Design</label>
                            <div class="custom-select-wrapper">
                                <div class="custom-select-trigger" id="theme-selector-trigger">Classic DMG Grey</div>
                                <div class="custom-select-options" id="theme-selector-options">
                                    <div class="custom-option selected" data-value="classic-dmg">Classic DMG Grey</div>
                                    <div class="custom-option" data-value="atomic-purple">Atomic Purple (Translucent)</div>
                                    <div class="custom-option" data-value="midnight-sleek">Midnight Gold</div>
                                    <div class="custom-option" data-value="neon-lime">Neon Lime-Green</div>
                                </div>
                            </div>
                        </div>
                        <div class="option-row">
                            <label for="volume-slider">Volume level</label>
                            <input type="range" id="volume-slider" min="0" max="1" step="0.1" value="<?php echo htmlspecialchars($settings['sound_volume']); ?>">
                        </div>
                        <div class="option-row toggle-row">
                            <label for="toggle-grid">Toggle LCD matrix grid</label>
                            <div class="custom-switch <?php echo $settings['lcd_grid'] ? 'on' : ''; ?>" id="toggle-grid">
                                <div class="switch-nob"></div>
                            </div>
                        </div>
                        <div class="option-row toggle-row">
                            <label for="toggle-haptic">Enable Web Haptics (Vibration)</label>
                            <div class="custom-switch <?php echo $settings['haptics_enabled'] ? 'on' : ''; ?>" id="toggle-haptic">
                                <div class="switch-nob ="></div>
                            </div>
                        </div>
                    </div>

                    <!-- Diagnostic Telemetry Console -->
                    <div class="deck-card bento-terminal">
                        <div class="deck-header">
                            <h3>⌨️ Diagnostic Console</h3>
                            <div class="terminal-actions">
                                <button class="btn btn-secondary btn-icon-only" id="btn-clear-terminal" title="Clear Console">🧹</button>
                                <button class="btn btn-secondary btn-icon-only" id="btn-copy-terminal" title="Copy Log Report">📋</button>
                            </div>
                        </div>
                        <div class="terminal-body">
                            <div class="terminal-output" id="terminal-output">
                                <div class="log-row log-status">[SYSTEM] Standing by...</div>
                            </div>
                        </div>
                    </div>

                    <!-- Retro Specs Warning Decal -->
                    <div class="retro-decal">
                        <div class="decal-header">CONJURE GAME CARD SHELF®</div>
                        <div class="decal-body">
                            MODEL NO. CJB-420R<br>
                            COMPATIBLE WITH GB/GBC SIMULATIONS.<br>
                            © 2026 CONJURE ENTERTAINMENT INC.<br>
                            ALL RIGHTS RESERVED. PATENTS PENDING.
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Bootstrap Core Downloader Overlay -->
        <div class="dialog-overlay" id="bootstrap-overlay">
            <div class="dialog-box">
                <div class="hardware-screw top-left"></div>
                <div class="hardware-screw top-right"></div>
                <div class="hardware-screw bottom-left"></div>
                <div class="hardware-screw bottom-right"></div>
                <h3>⚡ Emulator Core Missing</h3>
                <p>The system needs to download the 1.2MB <code>GameBoyCore.js</code> runtime onto your server before first-time console play can initiate.</p>
                <div class="progress-wrap" id="bootstrap-progress-wrap" style="display: none; margin-bottom: 20px;">
                    <div class="progress-bar" id="bootstrap-progress" style="width: 0%; height: 6px; background-color: var(--primary-accent); border-radius: 3px; transition: width 0.3s ease;"></div>
                </div>
                <div class="dialog-actions">
                    <button id="bootstrap-cancel" class="btn btn-secondary">Cancel</button>
                    <button id="bootstrap-download" class="btn btn-primary">Download Runtime</button>
                </div>
            </div>
        </div>

        <!-- Dynamic Toast Container alerts -->
        <div class="toast-container" id="toast-container"></div>

        <!-- Retro Game Boy Manual overlay -->
        <div class="dialog-overlay" id="manual-overlay">
            <div class="dialog-box manual-box" style="max-width: 440px; width: 95%;">
                <div class="hardware-screw top-left"></div>
                <div class="hardware-screw top-right"></div>
                <div class="hardware-screw bottom-left"></div>
                <div class="hardware-screw bottom-right"></div>
                <h3>📖 CONJUREBOY OWNER'S MANUAL</h3>
                <div class="manual-content-scroll">
                    <div class="manual-section">
                        <h4>1. CARTRIDGE EXPANSION & STORAGE</h4>
                        <ul>
                            <li><strong>Insert & Play (Single Tap):</strong> Tap once on any cassette cartridge on the shelf to slot and power it on instantly.</li>
                            <li><strong>Safe Eject:</strong> Open options on your panel and tap <strong>eject</strong> to safely power down the instruction engine.</li>
                            <li><strong>Cartridge Options:</strong> 
                                <span class="device-tag desc-touch">Touch: Hold down on a cartridge for 600ms to open the options menu.</span>
                                <span class="device-tag desc-desktop">Mouse: Hover to reveal the <strong>⋮</strong> options button in the corner.</span>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="manual-section">
                        <h4>2. TACTICAL GAMEPLAY CONTROLS</h4>
                        <ul>
                            <li><strong>Keyboard Bindings:</strong> Arrow Keys = D-pad | Z Key = A Button | X Key = B Button | Shift = SELECT | Enter = START.</li>
                            <li><strong>Grille Audio Mute:</strong> Hold down on the physical speaker slits in the lower-right corner of the console for 500ms to toggle global mute.</li>
                            <li><strong>D-pad Center Button:</strong> Press the D-pad center to smartly toggle between fast speed and standard 1.0x bypass (asterisk) modes.</li>
                        </ul>
                    </div>

                    <div class="manual-section">
                        <h4>3. AUXILIARY INSERT OPTIONS</h4>
                        <ul>
                            <li><strong>Quick State Save & Load:</strong> Tap <strong>SAVE</strong> or <strong>LOAD</strong> to trigger confirmation popups. Hold down for 500ms to execute instant, silent state commits (Slot 99).</li>
                            <li><strong>Rapid Turbo Switch:</strong> Toggle the slider switch on the Auxiliary plate to alternately fire A/B input states every 50ms automatically.</li>
                            <li><strong>Speed Adjustments:</strong> Press <strong>+</strong> or <strong>-</strong> to shift emulation speed increments from 1.0x up to 20.0x fast-forward.</li>
                            <li><strong>Standard Speed Bypass (LED Screen):</strong> Long-press (500ms) on the speed indicator screen to toggle standard speed bypass (forces 1.0x speed with a blinking asterisk indicator).</li>
                        </ul>
                    </div>

                    <div class="manual-section">
                        <h4>4. CONSOLE POWER & UTILITIES</h4>
                        <ul>
                            <li><strong>SHELF Button:</strong> Tap to slide the ROM library panel open/closed. Long-press (500ms) to slide it open and automatically prompt to **Quick Resume** your last game.</li>
                            <li><strong>AUX Button:</strong> Tap to expand the custom Auxiliary Plate inset below the screen.</li>
                            <li><strong>FSCRN Button:</strong> Tap to toggle immersive fullscreen mode. On mobile screens, this uses counter-rotations to lock a clean vertical portrait layout.</li>
                        </ul>
                    </div>
                </div>
                <div class="dialog-actions" style="margin-top: 15px;">
                    <button id="manual-close" class="btn btn-primary" style="width: 100%;">Close booklet</button>
                </div>
            </div>
        </div>

        <!-- Custom Confirmation/Input dialog overlay -->
        <div class="dialog-overlay" id="dialog-overlay">
            <div class="dialog-box">
                <div class="hardware-screw top-left"></div>
                <div class="hardware-screw top-right"></div>
                <div class="hardware-screw bottom-left"></div>
                <div class="hardware-screw bottom-right"></div>
                <h3 id="dialog-title">Verification</h3>
                <p id="dialog-message">Message text</p>
                <div id="dialog-input-container" style="display: none;">
                    <input type="text" id="dialog-input" class="custom-text-input" spellcheck="false" autocomplete="off">
                </div>
                <div class="dialog-actions">
                    <button id="dialog-cancel" class="btn btn-secondary">Cancel</button>
                    <button id="dialog-ok" class="btn btn-primary">Proceed</button>
                </div>
            </div>
        </div>

    </div>
    
    <script src="js/core/GameBoyCore.js?v=<?php echo $v; ?>"></script>
    <script src="js/core/gba.min.js?v=<?php echo $v; ?>"></script>
    <script src="js/app.js?v=<?php echo $v; ?>"></script>
</body>
</html>