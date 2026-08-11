<?php
// MODULAR THEME: Holo Foil (Laser Hologram)
// Inspired by TCG rare cards and forgery prevention stickers.

$themeData = [
    'holoFoil' => [
        'name' => "Holo Foil (Laser Hologram)",
        'options' => [
            ['id' => 'intensity', 'label' => 'Refraction Intensity', 'type' => 'slider', 'min' => 10, 'max' => 100, 'default' => 50, 'unit' => '%'],
            ['id' => 'auto_animate', 'label' => 'Auto-Animate (Idle)', 'type' => 'toggle', 'default' => true],
            ['id' => 'anim_speed', 'label' => 'Animation Speed', 'type' => 'slider', 'min' => 10, 'max' => 300, 'default' => 100, 'unit' => '%'],
            ['id' => 'show_sparkles', 'label' => 'Metallic Grain', 'type' => 'toggle', 'default' => true]
        ],
        'vars' => [
            "--bg-color" => "#0a0a0b", 
            "--card-bg" => "linear-gradient(135deg, #1a1a1c 0%, #2a2a2e 100%)", 
            "--header-bg" => "rgba(25, 25, 35, 0.35)",
            "--text-primary" => "#e0e0e0", 
            "--text-secondary" => "#a0a0a0", 
            "--text-title" => "#ffffff",
            "--primary" => "#ffffff", 
            "--btn-bg" => "rgba(255, 255, 255, 0.1)", 
            "--btn-text" => "#ffffff",
            "--input-bg" => "#050506", 
            "--input-text" => "#ffffff", 
            "--primary-text" => "#000000",
            "--border-color" => "rgba(255, 255, 255, 0.15)", 
            "--shadow-card" => "0 10px 30px rgba(0, 0, 0, 0.5)",
            "--ai-accent" => "#00ffff", 
            "--ai-accent-bg" => "rgba(0, 255, 255, 0.1)",
            "--glass-bg" => "rgba(20, 20, 22, 0.6)", 
            "--glass-border" => "rgba(255, 255, 255, 0.2)",
            "--shadow-floating" => "0 20px 80px rgba(0, 0, 0, 0.8)", 
            "--player-active" => "#ffffff"
        ],
        'extra' => "
            :root {
                --holo-x: 50%;
                --holo-y: 50%;
                --holo-opacity: 0.5;
            }
            .app-frame { background: #0a0a0b !important; overflow: hidden !important; }
            .scroll-view { background: transparent !important; position: relative; z-index: 2; }
            
            /* Isolate blend modes to prevent backdrop-filter flickering */
            .card, .app-frame, .top-bar, .selection-bottom-bar, #organizer-bar-wrapper,
            .sui-studio, .settings-sheet, .shared-bottom-sheet, #shared-picker-sheet, 
            #shared-input-sheet, #shared-confirm-sheet, #ai-manager-sheet, #folder-manager-sheet, 
            #folder-move-sheet, #draft-pad-card, .fab, .fr-action-zone, .fr-menu-btn {
                isolation: isolate;
            }

            /* The Rainbow Foil Layer */
            .card::before, .app-frame::before, .top-bar::before, .selection-bottom-bar::before, #organizer-bar-wrapper::before,
            .sui-studio::before, .settings-sheet::before, .shared-bottom-sheet::before, #shared-picker-sheet::before, 
            #shared-input-sheet::before, #shared-confirm-sheet::before, #ai-manager-sheet::before, #folder-manager-sheet::before, 
            #folder-move-sheet::before, #draft-pad-card::before, .fab::before, .fr-action-zone::before, .fr-menu-btn::before {
                content: '';
                position: absolute;
                top: -50%; left: -50%; width: 200%; height: 200%;
                background: linear-gradient(
                    115deg,
                    transparent 0%,
                    rgba(255, 0, 0, var(--holo-opacity)) 15%,
                    rgba(255, 255, 0, var(--holo-opacity)) 30%,
                    rgba(0, 255, 0, var(--holo-opacity)) 45%,
                    rgba(0, 255, 255, var(--holo-opacity)) 60%,
                    rgba(0, 0, 255, var(--holo-opacity)) 75%,
                    rgba(255, 0, 255, var(--holo-opacity)) 90%,
                    transparent 100%
                );
                background-size: 100% 100%;
                mix-blend-mode: color-dodge;
                pointer-events: none;
                z-index: 1;
                opacity: 0.6;
                transform: translate3d(calc((50% - var(--holo-x)) * 0.5), calc((50% - var(--holo-y)) * 0.5), 0);
                transition: transform 0.1s ease-out;
                will-change: transform;
            }

            /* Metallic Grain / Sparkles */
            .card::after, .top-bar::after, .selection-bottom-bar::after, #organizer-bar-wrapper::after,
            .sui-studio::after, .settings-sheet::after, .shared-bottom-sheet::after, #shared-picker-sheet::after, 
            #shared-input-sheet::after, #shared-confirm-sheet::after, #ai-manager-sheet::after, #folder-manager-sheet::after, 
            #folder-move-sheet::after, #draft-pad-card::after, .fab::after, .fr-action-zone::after, .fr-menu-btn::after {
                content: '';
                position: absolute;
                inset: 0;
                background-image: url('data:image/svg+xml,%3Csvg viewBox=\"0 0 200 200\" xmlns=\"http://www.w3.org/2000/svg\"%3E%3Cfilter id=\"noiseFilter\"%3E%3CfeTurbulence type=\"fractalNoise\" baseFrequency=\"0.8\" numOctaves=\"1\" stitchTiles=\"stitch\"/%3E%3C/filter%3E%3Crect width=\"100%25\" height=\"100%25\" filter=\"url(%23noiseFilter)\"/%3E%3C/svg%3E');
                opacity: 0.05;
                pointer-events: none;
                z-index: 2;
                display: var(--sparkle-display, block);
                transform: translate3d(0,0,0);
            }

            .card { 
                background: var(--card-bg) !important;
                border: 1px solid rgba(255, 255, 255, 0.1) !important;
                overflow: hidden;
                position: relative;
            }

            .card-content { position: relative; z-index: 3; }

            .page-title { 
                color: #fff !important;
                text-transform: uppercase;
                letter-spacing: 2px;
                font-weight: 900 !important;
                background: linear-gradient(to right, #fff, #a0a0a0, #fff);
                -webkit-background-clip: text !important;
                -webkit-text-fill-color: transparent !important;
                filter: drop-shadow(0 0 5px rgba(255,255,255,0.3));
            }

            .section-header { 
                color: #ffffff !important; 
                background: transparent !important;
                border-bottom: 1px solid rgba(255,255,255,0.1) !important;
                text-transform: uppercase;
                font-size: 10px !important;
                letter-spacing: 4px !important;
                padding-top: 40px !important;
            }

            /* System Bar Glassmorphism */
            .top-bar, .selection-bottom-bar, #organizer-bar-wrapper {
                background: var(--header-bg) !important;
                backdrop-filter: blur(30px) saturate(200%) !important;
                -webkit-backdrop-filter: blur(30px) saturate(200%) !important;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
                overflow: hidden;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3) !important;
            }
            
            /* Rounded Header Slab */
            .top-bar {
                border-bottom-left-radius: 24px !important;
                border-bottom-right-radius: 24px !important;
            }

            /* Integration with Organizer Bar */
            #organizer-bar-wrapper {
                border-bottom-left-radius: 24px !important;
                border-bottom-right-radius: 24px !important;
                margin-top: -10px; /* Slight overlap for seamless look */
                padding-top: 10px;
                border-top: 1px solid rgba(255, 255, 255, 0.05) !important;
                background: rgba(25, 25, 35, 0.45) !important;
            }

            /* Round the selection bar top instead */
            .selection-bottom-bar {
                border-bottom: none !important;
                border-top: 1px solid rgba(255, 255, 255, 0.1) !important;
                border-top-left-radius: 24px !important;
                border-top-right-radius: 24px !important;
            }

            /* Floating UI & Studios */
            .sui-studio, .settings-sheet, .shared-bottom-sheet, #shared-picker-sheet, #shared-input-sheet, 
            #shared-confirm-sheet, #ai-manager-sheet, #folder-manager-sheet, #folder-move-sheet, #draft-pad-card {
                background: rgba(25, 25, 35, 0.45) !important;
                backdrop-filter: blur(25px) saturate(200%) !important;
                -webkit-backdrop-filter: blur(25px) saturate(200%) !important;
                border: 1px solid rgba(255, 255, 255, 0.15) !important;
                border-radius: 28px !important;
                overflow: hidden;
                box-shadow: 0 20px 80px rgba(0, 0, 0, 0.6) !important;
                transform: translate3d(0,0,0);
                -webkit-transform: translate3d(0,0,0);
                backface-visibility: hidden;
                -webkit-backface-visibility: hidden;
            }

            /* Floating Buttons (FAB) */
            .fab, .fr-action-zone, .fr-menu-btn {
                background: rgba(40, 40, 50, 0.4) !important;
                backdrop-filter: blur(15px) saturate(150%) !important;
                -webkit-backdrop-filter: blur(15px) saturate(150%) !important;
                border: 1px solid rgba(255, 255, 255, 0.3) !important;
                overflow: hidden;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4) !important;
            }

            /* Inner Content Adjustments */
            .settings-header, .sui-studio-header, .sui-accordion-inner, .settings-group, .picker-item {
                background: transparent !important;
                border-color: rgba(255, 255, 255, 0.1) !important;
            }

            .picker-item.selected {
                background: rgba(255, 255, 255, 0.1) !important;
                border-color: rgba(255, 255, 255, 0.4) !important;
            }

            /* Ensure content stays above the rainbow layer */
            .top-bar > *, .selection-bottom-bar > * {
                position: relative;
                z-index: 10;
            }
        "
    ],
];

$theme = reset($themeData);
$theme['js'] = <<<'JS'
window.tp_init_holoFoil = function() {
    const root = document.documentElement;
    
    window._cjosHoloEngine = {
        active: true,
        paused: false,
        lastX: 50,
        lastY: 50,
        time: 0,
        rafId: null,
        
        init() {
            this.active = true;
            this.paused = false;
            const intensity = tpState.theme_options?.holoFoil?.intensity ?? 50;
            const showSparkles = tpState.theme_options?.holoFoil?.show_sparkles ?? true;
            root.style.setProperty('--holo-opacity', (intensity / 100).toFixed(2));
            root.style.setProperty('--sparkle-display', showSparkles ? 'block' : 'none');

            const autoAnim = tpState.theme_options?.holoFoil?.auto_animate !== false;

            this.bindVisibility();

            if (autoAnim) {
                this.startAnimation();
            } else {
                this.bindSensors();
                this.bindMouse();
            }
        },

        bindVisibility() {
            this._visHandler = () => {
                if (!this.active) return;
                if (document.hidden) {
                    this.paused = true;
                    if (this.rafId) cancelAnimationFrame(this.rafId);
                } else if (this.paused) {
                    this.paused = false;
                    const autoAnim = tpState.theme_options?.holoFoil?.auto_animate !== false;
                    if (autoAnim) this.startAnimation();
                }
            };
            document.addEventListener('visibilitychange', this._visHandler);
        },

        startAnimation() {
            const speed = (tpState.theme_options?.holoFoil?.anim_speed ?? 100) / 100;
            const loop = (t) => {
                if (!this.active || document.hidden) {
                    this.paused = true;
                    return;
                }
                // Base increment is 0.01 per frame
                this.time += 0.01 * speed;
                const x = 50 + Math.sin(this.time * 0.8) * 25;
                const y = 50 + Math.cos(this.time * 0.5) * 25;
                this.update(x, y);
                this.rafId = requestAnimationFrame(loop);
            };
            if (this.rafId) cancelAnimationFrame(this.rafId);
            this.rafId = requestAnimationFrame(loop);
        },

        bindSensors() {
            // Request permission for iOS 13+
            if (typeof DeviceOrientationEvent !== 'undefined' && typeof DeviceOrientationEvent.requestPermission === 'function') {
                // We typically need a user gesture, but we can try to bind the listener
                // If it fails, the user can toggle it in settings which counts as a gesture
                DeviceOrientationEvent.requestPermission()
                    .then(response => {
                        if (response == 'granted') {
                            window.addEventListener('deviceorientation', (e) => this.handleOrientation(e), true);
                        }
                    }).catch(console.error);
            } else {
                window.addEventListener('deviceorientation', (e) => this.handleOrientation(e), true);
            }
        },

        bindMouse() {
            this._mouseHandler = (e) => {
                if (this.active) {
                    const x = (e.clientX / window.innerWidth) * 100;
                    const y = (e.clientY / window.innerHeight) * 100;
                    this.update(x, y);
                }
            };
            window.addEventListener('mousemove', this._mouseHandler);
        },

        handleOrientation(e) {
            if (!this.active) return;

            let x = ((e.gamma + 90) / 180) * 100;
            let y = ((e.beta + 180) / 360) * 100;
            
            x = Math.max(0, Math.min(100, x));
            y = Math.max(0, Math.min(100, y));
            
            this.update(x, y);
        },

        update(x, y) {
            const newX = this.lastX + (x - this.lastX) * 0.1;
            const newY = this.lastY + (y - this.lastY) * 0.1;
            
            if (Math.abs(newX - this.lastX) > 0.01 || Math.abs(newY - this.lastY) > 0.01) {
                this.lastX = newX;
                this.lastY = newY;
                root.style.setProperty('--holo-x', `${this.lastX.toFixed(2)}%`);
                root.style.setProperty('--holo-y', `${this.lastY.toFixed(2)}%`);
            }
        },

        stop() {
            this.active = false;
            if (this.rafId) cancelAnimationFrame(this.rafId);
            if (this._visHandler) document.removeEventListener('visibilitychange', this._visHandler);
            if (this._orientHandler) window.removeEventListener('deviceorientation', this._orientHandler);
            if (this._mouseHandler) window.removeEventListener('mousemove', this._mouseHandler);
        }
    };

    window._cjosHoloEngine.init();
};

window.tp_destroy_holoFoil = function() {
    if (window._cjosHoloEngine) window._cjosHoloEngine.stop();
};
JS;

return $theme;