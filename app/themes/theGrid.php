<?php
// MODULAR THEME: The Grid (Retro-Futurism)
// Extracted from ThemePresets.php

$themeData = [
    'theGrid' => [
        'name' => "The Grid (Retro-Futurism)",
        'options' => [
            ['id' => 'show_stars', 'label' => 'Twinkling Starfield', 'type' => 'toggle', 'default' => true],
            ['id' => 'show_mountains', 'label' => 'Vector Mountains', 'type' => 'toggle', 'default' => true],
            ['id' => 'show_skyline', 'label' => 'City Skyline', 'type' => 'toggle', 'default' => true],
            ['id' => 'show_crt', 'label' => 'CRT Scanlines', 'type' => 'toggle', 'default' => true]
        ],
        'vars' => [
            "--bg-color" => "#000000", "--card-bg" => "rgba(10, 10, 15, 0.8)", "--header-bg" => "rgba(0, 0, 0, 0.9)",
            "--text-primary" => "#00FFFF", "--text-secondary" => "#FF00FF", "--text-title" => "#FF00FF",
            "--primary" => "#00FFFF", "--btn-bg" => "rgba(0, 255, 255, 0.1)", "--btn-text" => "#00FFFF",
            "--input-bg" => "#050505", "--input-text" => "#00FFFF", "--primary-text" => "#000000",
            "--border-color" => "rgba(0, 255, 255, 0.4)", "--shadow-card" => "0 0 20px rgba(0, 255, 255, 0.2)",
            "--ai-accent" => "#FFD700", "--ai-accent-bg" => "rgba(255, 215, 0, 0.1)",
            "--glass-bg" => "rgba(10, 10, 15, 0.6)", "--glass-border" => "rgba(0, 255, 255, 0.3)",
            "--shadow-floating" => "0 20px 80px rgba(0, 0, 0, 0.9)", "--player-active" => "#FF00FF"
        ],
        'extra' => "
            .app-frame { background: #000000 !important; overflow: hidden !important; }
            .app-frame::before {
                content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 50%;
                background: linear-gradient(to bottom, #000 0%, #20002c 40%, #cbb4d4 100%);
                opacity: 0.4; z-index: 1; pointer-events: none;
            }
            .scroll-view { background: transparent !important; position: relative; z-index: 2; }
            .card { 
                backdrop-filter: blur(15px) !important; 
                -webkit-backdrop-filter: blur(15px) !important;
                border: 1px solid rgba(0, 255, 255, 0.2) !important;
                box-shadow: 0 8px 32px rgba(0,0,0,0.8), inset 0 0 10px rgba(0, 255, 255, 0.05) !important;
            }
            #grid-canvas {
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                z-index: 1; pointer-events: none; opacity: 0; transition: opacity 2s ease;
            }
            #grid-canvas.visible { opacity: 0.8; }
            .page-title { 
                background: linear-gradient(to bottom, #fff 0%, #00FFFF 45%, #FF00FF 55%, #fff 100%) !important;
                -webkit-background-clip: text !important;
                -webkit-text-fill-color: transparent !important;
                -webkit-text-stroke: 1px rgba(0, 255, 255, 0.5);
                filter: drop-shadow(0 0 10px rgba(0, 255, 255, 0.3));
                font-weight: 900 !important; font-style: italic !important;
                text-transform: uppercase; letter-spacing: 4px;
            }
            .app-frame::after {
                content: ''; position: fixed; inset: 0;
                background: repeating-linear-gradient(0deg, rgba(0,0,0,0.1), rgba(0,0,0,0.1) 1px, transparent 1px, transparent 2px);
                pointer-events: none; z-index: 999; opacity: var(--crt-opacity, 0.4);
            }
            .section-header { 
                color: #00FFFF !important; 
                background: transparent !important;
                text-shadow: 0 0 10px rgba(0, 255, 255, 0.5);
                border-bottom: none !important;
                padding-top: 40px !important;
            }
            .top-bar, .selection-bottom-bar { 
                background: rgba(0, 0, 0, 0.85) !important; 
                backdrop-filter: blur(20px) !important;
                border-color: rgba(0, 255, 255, 0.3) !important;
            }
            .done-btn, .btn-primary { 
                background: #FF00FF !important; 
                color: #FFFFFF !important;
                box-shadow: 0 0 20px rgba(255, 0, 255, 0.5) !important;
                border-radius: 4px !important;
            }
        "
    ],
];

$theme = reset($themeData);
$theme['js'] = <<<'JS'
window.tp_init_theGrid = function() {
    const root = document.documentElement;
    const showCrt = tpState.theme_options?.theGrid?.show_crt ?? true;
    root.style.setProperty('--crt-opacity', showCrt ? '0.4' : '0');
    if (!window._cjosGridEngine) {
        const canvas = document.createElement('canvas');
        canvas.id = 'grid-canvas';
        document.querySelector('.app-frame')?.appendChild(canvas);
        
        window._cjosGridEngine = {
canvas, ctx: canvas.getContext('2d'),
active: true, paused: false, speed: 0.025, offset: 0,
stars: [],
skyline: [],
start() {
    this.active = true;
    this.paused = false;
    this.generateStars();
    this.generateSkyline();
    this._resizeHandler = () => this.resize();
    window.addEventListener('resize', this._resizeHandler);
    this.bindVisibility();
    this.resize();
    this.loop();
    requestAnimationFrame(() => canvas.classList.add('visible'));
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
generateStars() {
    this.stars = [];
    for(let i=0; i<150; i++) {
        this.stars.push({
            x: Math.random(),
            y: Math.random() * 0.5,
            size: 0.5 + Math.random() * 1.5,
            blink: Math.random() * Math.PI * 2
        });
    }
},
generateSkyline() {
    this.skyline = [];
    let x = 0;
    while (x < 1.1) {
        const w = 0.02 + Math.random() * 0.04;
        const h = 0.01 + Math.random() * 0.03;
        const hasLights = Math.random() > 0.3;
        this.skyline.push({ x, w, h, hasLights });
        x += w + (Math.random() * 0.01);
    }
},
resize() {
    this.canvas.width = window.innerWidth;
    this.canvas.height = window.innerHeight;
},
loop() {
    if (!this.active) return;
    if (document.hidden) {
        this.paused = true;
        return;
    }
    this.draw();
    this.offset = (this.offset + this.speed) % 1;
    requestAnimationFrame(() => this.loop());
},
draw() {
    const { ctx, canvas, offset } = this;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const vanishingPointY = canvas.height * 0.5;

    const showStars = tpState.theme_options?.theGrid?.show_stars ?? true;
    if (showStars) {
        this.stars.forEach(s => {
            const opacity = 0.2 + (Math.sin(Date.now() * 0.002 + s.blink) + 1) * 0.4;
            ctx.fillStyle = `rgba(255, 255, 255, ${opacity})`;
            ctx.beginPath();
            ctx.arc(s.x * canvas.width, s.y * canvas.height, s.size, 0, Math.PI * 2);
            ctx.fill();
        });
    }

    const sunRadius = Math.min(canvas.width, canvas.height) * 0.35;
    const sunX = canvas.width / 2;
    const sunY = vanishingPointY;

    ctx.save();
    const sunGrad = ctx.createLinearGradient(0, sunY - sunRadius, 0, sunY);
    sunGrad.addColorStop(0, '#FFF200');
    sunGrad.addColorStop(0.5, '#FF00FF');
    sunGrad.addColorStop(1, '#FF007A');
    ctx.fillStyle = sunGrad;
    ctx.shadowBlur = 50;
    ctx.shadowColor = '#FF007A';
    ctx.beginPath();
    ctx.arc(sunX, sunY, sunRadius, Math.PI, 0); 
    ctx.fill();
    ctx.globalCompositeOperation = 'destination-out';
    for (let i = 1; i < 8; i++) {
        const stripeH = (8 - i) * 2.5;
        const stripeY = sunY - (i * (sunRadius / 8.5));
        ctx.fillRect(0, stripeY, canvas.width, stripeH);
    }
    ctx.restore();

    const showSkyline = tpState.theme_options?.theGrid?.show_skyline ?? true;
    if (showSkyline) {
        ctx.fillStyle = '#050505';
        this.skyline.forEach(b => {
            const bx = (b.x - 0.05) * canvas.width;
            const bw = b.w * canvas.width;
            const bh = b.h * canvas.height * 0.4;
            ctx.fillRect(bx, vanishingPointY - bh, bw, bh);
            if (b.hasLights) {
                ctx.fillStyle = Math.random() > 0.5 ? '#00FFFF' : '#FF00FF';
                ctx.globalAlpha = 0.3;
                ctx.fillRect(bx + (bw * 0.3), vanishingPointY - (bh * 0.8), 2, 2);
                ctx.fillRect(bx + (bw * 0.6), vanishingPointY - (bh * 0.5), 2, 2);
                ctx.globalAlpha = 1.0;
                ctx.fillStyle = '#050505';
            }
        });
    }
    const showMountains = tpState.theme_options?.theGrid?.show_mountains ?? true;
    if (showMountains) {
        const drawMtn = (x, w, h, color) => {
            ctx.fillStyle = '#050505';
            ctx.strokeStyle = color;
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(x - w/2, vanishingPointY);
            ctx.lineTo(x, vanishingPointY - h);
            ctx.lineTo(x + w/2, vanishingPointY);
            ctx.fill();
            ctx.stroke();
        };
        ctx.globalAlpha = 0.8;
        drawMtn(canvas.width * 0.2, canvas.width * 0.4, 60, '#FF00FF');
        drawMtn(canvas.width * 0.8, canvas.width * 0.5, 80, '#00FFFF');
        drawMtn(canvas.width * 0.5, canvas.width * 0.6, 110, '#FF00FF');
        ctx.globalAlpha = 1.0;
    }

    const gridHeight = canvas.height - vanishingPointY;
    ctx.save();
    ctx.beginPath();
    ctx.rect(0, vanishingPointY, canvas.width, canvas.height - vanishingPointY);
    ctx.clip();
    ctx.strokeStyle = '#00FFFF';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 16; i++) {
        const linePos = (i + offset) / 16;
        const y = vanishingPointY + Math.pow(linePos, 3) * gridHeight;
        ctx.globalAlpha = Math.pow(linePos, 2) * 0.6;
        ctx.beginPath();
        ctx.moveTo(0, y);
        ctx.lineTo(canvas.width, y);
        ctx.stroke();
    }
    const verticalCount = 18;
    for (let i = 0; i <= verticalCount; i++) {
        const xOffset = (i / verticalCount - 0.5) * canvas.width * 5;
        ctx.globalAlpha = 0.25;
        ctx.beginPath();
        ctx.moveTo(canvas.width / 2, vanishingPointY);
        ctx.lineTo(canvas.width / 2 + xOffset, canvas.height);
        ctx.stroke();
    }
    ctx.restore();
    ctx.globalAlpha = 1;
},
stop() {
    this.active = false;
    if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler);
    if (this._visHandler) document.removeEventListener('visibilitychange', this._visHandler);
    canvas.remove();
    window._cjosGridEngine = null;
}
        };
        window._cjosGridEngine.start();
    }
};
window.tp_destroy_theGrid = function() {
    if (window._cjosGridEngine) window._cjosGridEngine.stop();
};
JS;

return $theme;