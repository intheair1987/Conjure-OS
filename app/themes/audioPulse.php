<?php
// MODULAR THEME: Audio Pulse (Thematic)
// Extracted from ThemePresets.php

$themeData = [
    'audioPulse' => [
        'name' => "Audio Pulse (Thematic)",
        'vars' => [
            "--bg-color" => "#0A0A0C", "--card-bg" => "rgba(20, 20, 25, 0.7)", "--header-bg" => "rgba(10, 10, 12, 0.85)",
            "--text-primary" => "#F2F2F7", "--text-secondary" => "#8E8E93", "--text-title" => "#FFFFFF",
            "--primary" => "#007AFF", "--btn-bg" => "rgba(255, 255, 255, 0.1)", "--btn-text" => "#F2F2F7",
            "--input-bg" => "#1C1C1E", "--input-text" => "#FFFFFF", "--primary-text" => "#FFFFFF",
            "--border-color" => "rgba(0, 122, 255, 0.3)", "--shadow-card" => "0 10px 40px rgba(0, 0, 0, 0.6)",
            "--ai-accent" => "#00F0FF", "--ai-accent-bg" => "rgba(0, 240, 255, 0.1)",
            "--glass-bg" => "rgba(28, 28, 30, 0.4)", "--glass-border" => "rgba(0, 122, 255, 0.2)",
            "--shadow-floating" => "0 20px 80px rgba(0, 0, 0, 0.8)", "--player-active" => "#007AFF"
        ],
        'extra' => "
            .app-frame { background: #0A0A0C !important; overflow: hidden !important; }
            .scroll-view { background: transparent !important; position: relative; z-index: 2; }
            .card { 
                backdrop-filter: blur(20px) saturate(180%) !important; 
                -webkit-backdrop-filter: blur(20px) saturate(180%) !important;
                border: 1px solid rgba(0, 122, 255, 0.15) !important;
                box-shadow: 0 8px 32px rgba(0,0,0,0.4), inset 0 0 0 0.5px rgba(255,255,255,0.05) !important;
            }
            #pulse-canvas {
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                z-index: 1; pointer-events: none; opacity: 0; transition: opacity 2s ease;
            }
            #pulse-canvas.visible { opacity: 0.6; }
            .page-title { 
                color: #FFFFFF !important; 
                text-shadow: 0 0 20px rgba(0, 122, 255, 0.5);
                font-weight: 900 !important;
            }
            .section-header { 
                color: #007AFF !important; 
                background: transparent !important;
                text-transform: uppercase; letter-spacing: 2px;
                border-bottom: 1px solid rgba(0, 122, 255, 0.2) !important;
            }
            .top-bar, .selection-bottom-bar { 
                background: rgba(10, 10, 12, 0.8) !important; 
                backdrop-filter: blur(25px) !important;
                border-color: rgba(0, 122, 255, 0.2) !important;
            }
            .done-btn, .btn-primary { 
                background: #007AFF !important; 
                box-shadow: 0 4px 15px rgba(0, 122, 255, 0.4) !important;
            }
        "
    ],
];

$theme = reset($themeData);
$theme['js'] = <<<'JS'
window.tp_init_audioPulse = function() {
    if (!window._cjosPulseEngine) {
        const canvas = document.createElement('canvas');
        canvas.id = 'pulse-canvas';
        document.querySelector('.app-frame')?.appendChild(canvas);
        
        window._cjosPulseEngine = {
canvas, ctx: canvas.getContext('2d'),
active: true, paused: false, phase: 0,
start() {
    this.active = true;
    this.paused = false;
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
    this.phase += 0.015;
    requestAnimationFrame(() => this.loop());
},
draw() {
    const { ctx, canvas, phase } = this;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const drawWave = (amplitude, freq, opacity, offset) => {
        ctx.beginPath();
        ctx.strokeStyle = `rgba(0, 122, 255, ${opacity})`;
        ctx.lineWidth = 2;
        for (let x = 0; x <= canvas.width; x += 5) {
            const y = canvas.height / 2 + Math.sin(x * freq + phase + offset) * amplitude;
            if (x === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        }
        ctx.stroke();
    };
    drawWave(80, 0.005, 0.2, 0);
    drawWave(120, 0.003, 0.15, 2);
    drawWave(60, 0.008, 0.3, 4);
},
stop() {
    this.active = false;
    if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler);
    if (this._visHandler) document.removeEventListener('visibilitychange', this._visHandler);
    canvas.remove();
    window._cjosPulseEngine = null;
}
        };
        window._cjosPulseEngine.start();
    }
};
window.tp_destroy_audioPulse = function() {
    if (window._cjosPulseEngine) window._cjosPulseEngine.stop();
};
JS;

return $theme;