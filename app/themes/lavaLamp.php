<?php
// MODULAR THEME: Lava Lamp
// Extracted from ThemePresets.php

$themeData = [
    'lavaLamp' => [
        'name' => "Lava Lamp (Animated)",
        'vars' => [
            "--bg-color" => "#050208", 
            "--card-bg" => "rgba(20, 10, 30, 0.65)", 
            "--header-bg" => "rgba(5, 2, 8, 0.85)",
            "--text-primary" => "#FFDAB9", 
            "--text-secondary" => "#FF7F50", 
            "--text-title" => "#FF4500",
            "--primary" => "#FF4500", 
            "--btn-bg" => "rgba(255, 69, 0, 0.15)", 
            "--btn-text" => "#FFDAB9",
            "--input-bg" => "#12081a", 
            "--input-text" => "#FFDAB9", 
            "--primary-text" => "#000000",
            "--border-color" => "rgba(255, 69, 0, 0.3)", 
            "--shadow-card" => "0 8px 32px rgba(0, 0, 0, 0.5)",
            "--ai-accent" => "#FF00FF", 
            "--ai-accent-bg" => "rgba(255, 0, 255, 0.1)",
            "--glass-bg" => "rgba(20, 10, 30, 0.4)", 
            "--glass-border" => "rgba(255, 69, 0, 0.2)",
            "--shadow-floating" => "0 20px 60px rgba(0, 0, 0, 0.8)", 
            "--player-active" => "#FF4500"
        ],
        'extra' => "
            .app-frame { background: #050208 !important; overflow: hidden !important; }
            .scroll-view { background: transparent !important; position: relative; z-index: 2; }
            .card { 
                backdrop-filter: blur(12px) !important; 
                -webkit-backdrop-filter: blur(12px) !important;
                border: 1px solid rgba(255, 69, 0, 0.2) !important;
                box-shadow: inset 0 0 20px rgba(255, 69, 0, 0.05), 0 10px 30px rgba(0,0,0,0.5) !important;
            }
            .page-title { 
                color: #FF4500 !important; 
                text-shadow: 0 0 15px rgba(255, 69, 0, 0.6), 0 0 30px rgba(255, 69, 0, 0.2);
                font-weight: 900 !important;
            }
            .section-header { 
                color: #FF7F50 !important; 
                background: transparent !important;
                text-shadow: 0 0 8px rgba(255, 127, 80, 0.4);
            }
            #lava-container {
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                z-index: 1; pointer-events: none; filter: url('#lava-goo');
                opacity: 0; transition: opacity 2s ease-in-out;
            }
            #lava-container.lava-visible { opacity: 0.7; }
            .lava-blob {
                position: absolute; background: linear-gradient(#FF4500, #FF00FF);
                border-radius: 50%; will-change: transform;
            }
            .top-bar, .selection-bottom-bar { 
                background: rgba(5, 2, 8, 0.7) !important; 
                backdrop-filter: blur(20px) !important;
                border-color: rgba(255, 69, 0, 0.2) !important;
            }
            /* Frosted Overlays & Deep Containers */
            .settings-sheet, .shared-bottom-sheet, #shared-picker-sheet, #shared-input-sheet, #shared-confirm-sheet, #ai-manager-sheet, .sui-studio-overlay, .sui-studio, #folder-manager-sheet, #folder-move-sheet, #draft-pad-card {
                background: rgba(20, 10, 30, 0.35) !important;
                backdrop-filter: blur(25px) saturate(180%) !important;
                -webkit-backdrop-filter: blur(25px) saturate(180%) !important;
                border-top: 1px solid rgba(255, 69, 0, 0.3) !important;
                box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.5) !important;
                --sui-header-bg: transparent;
                --sui-bottom-fade: transparent;
            }
            .settings-header, .sui-studio-header, .sui-studio-content, .sui-accordion-inner, .settings-group, .picker-item, .po-folder, .po-folder-header, .plugin-tray, .plugin-block {
                background: rgba(255, 255, 255, 0.02) !important;
                border-color: rgba(255, 69, 0, 0.1) !important;
                color: #FFDAB9 !important;
                backdrop-filter: blur(5px) !important;
                -webkit-backdrop-filter: blur(5px) !important;
            }
            .settings-close, .sui-close-trigger {
                background: rgba(255, 69, 0, 0.2) !important;
                color: #FFFFFF !important;
            }
        "
    ],
];

// The extraction includes the key 'lavaLamp' => [...]. 
// We pull the first element to get just the data.
$theme = reset($themeData);

$theme['js'] = <<<'JS'
window.tp_init_lavaLamp = function() {
    if (!window._cjosLavaEngine) {
        if (!document.getElementById('lava-goo-svg')) {
const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
svg.id = 'lava-goo-svg';
svg.style.display = 'none';
svg.innerHTML = `<defs><filter id="lava-goo"><feGaussianBlur in="SourceGraphic" stdDeviation="25" result="blur" /><feColorMatrix in="blur" mode="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 60 -25" result="goo" /><feComposite in="SourceGraphic" in2="goo" operator="atop" /></filter></defs>`;
document.body.appendChild(svg);
        }
        const container = document.createElement('div');
        container.id = 'lava-container';
        document.querySelector('.app-frame')?.appendChild(container);
        window._cjosLavaEngine = {
blobs: [], active: true, paused: false,
start() {
    this.active = true;
    this.paused = false;
    for (let i = 0; i < 8; i++) this.createBlob();
    this.bindVisibility();
    this.loop();
    requestAnimationFrame(() => container.classList.add('lava-visible'));
},
bindVisibility() {
    this._visHandler = () => {
        if (!this.active) return;
        if (document.hidden) {
            this.paused = true;
        } else if (this.paused) {
            this.paused = false;
            this.loop();
        }
    };
    document.addEventListener('visibilitychange', this._visHandler);
},
createBlob() {
    const el = document.createElement('div');
    el.className = 'lava-blob';
    const size = 150 + Math.random() * 250;
    el.style.width = size + 'px';
    el.style.height = size + 'px';
    container.appendChild(el);
    this.blobs.push({ el, x: Math.random() * window.innerWidth, y: Math.random() * window.innerHeight, vy: -0.2 - Math.random() * 0.8, vx: (Math.random() - 0.5) * 0.3, size });
},
loop() {
    if (!this.active) return;
    if (document.hidden) {
        this.paused = true;
        return;
    }
    this.blobs.forEach(b => {
        b.y += b.vy; b.x += b.vx;
        if (b.y < -b.size) { b.y = window.innerHeight + b.size; b.x = Math.random() * window.innerWidth; }
        b.el.style.transform = `translate(${b.x - b.size/2}px, ${b.y - b.size/2}px)`;
    });
    requestAnimationFrame(() => this.loop());
},
stop() {
    this.active = false;
    if (this._visHandler) document.removeEventListener('visibilitychange', this._visHandler);
    container.remove();
    window._cjosLavaEngine = null;
}
        };
        window._cjosLavaEngine.start();
    }
};
window.tp_destroy_lavaLamp = function() {
    if (window._cjosLavaEngine) window._cjosLavaEngine.stop();
};
JS;

return $theme;