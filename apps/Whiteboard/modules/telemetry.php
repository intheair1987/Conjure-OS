<?php
/**
 * Whiteboard Telemetry Module
 * Provides real-time Surface Pen / Pointer data overlay.
 */
?>
<div id="wb-telemetry-overlay" style="
    position: fixed; top: 70px; left: 20px; 
    background: rgba(0,0,0,0.7); color: #00ff00; 
    padding: 12px; border-radius: 12px; font-family: monospace; 
    font-size: 10px; pointer-events: none; z-index: 9999;
    display: none; min-width: 160px; backdrop-filter: blur(4px);
    border: 1px solid rgba(255,255,255,0.1);
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
">
    <div style="font-weight: 900; border-bottom: 1px solid rgba(0,255,0,0.3); margin-bottom: 6px; padding-bottom: 4px; text-transform: uppercase; letter-spacing: 1px;">Pen Telemetry</div>
    <div id="tele-type">Type: ---</div>
    <div id="tele-pos">Pos: 0, 0</div>
    <div id="tele-press">Press: 0.00</div>
    <div id="tele-tilt">Tilt: 0°, 0°</div>
    <div id="tele-azi">Azi: 0° (Calc)</div>
    <div id="tele-alt">Alt: 90° (Calc)</div>
    <div id="tele-twist">Twist: 0° (Raw)</div>
    <div id="tele-buttons">Btns: 0</div>
    <div style="display:flex; align-items:center; gap:10px; margin-top:8px; padding-top:8px; border-top:1px solid rgba(0,255,0,0.2);">
        <div id="tele-compass" style="width:24px; height:24px; border:1px solid #00ff00; border-radius:50%; position:relative;">
            <div id="tele-needle" style="position:absolute; top:50%; left:50%; width:1px; height:10px; background:#00ff00; transform-origin:bottom center; transform: translate(-50%, -100%) rotate(0deg);"></div>
        </div>
        <div id="tele-hover" style="font-weight: 800; flex:1;">STATUS: IDLE</div>
    </div>
</div>

<script>
(function() {
    let isEnabled = false;
    const overlay = document.getElementById('wb-telemetry-overlay');
    const needle = document.getElementById('tele-needle');
    const fields = {
        type: document.getElementById('tele-type'),
        pos: document.getElementById('tele-pos'),
        press: document.getElementById('tele-press'),
        tilt: document.getElementById('tele-tilt'),
        azi: document.getElementById('tele-azi'),
        alt: document.getElementById('tele-alt'),
        twist: document.getElementById('tele-twist'),
        btns: document.getElementById('tele-buttons'),
        hover: document.getElementById('tele-hover')
    };

    window.wbToggleTelemetry = function(show) {
        isEnabled = show;
        overlay.style.display = show ? 'block' : 'none';
    };

    function update(e) {
        if (!isEnabled) return;

        fields.type.innerText = `Type: ${e.pointerType.toUpperCase()}`;
        fields.pos.innerText = `Pos: ${Math.round(e.clientX)}, ${Math.round(e.clientY)}`;
        fields.press.innerText = `Press: ${e.pressure.toFixed(3)}`;
        fields.tilt.innerText = `Tilt: ${e.tiltX}°, ${e.tiltY}°`;
        fields.twist.innerText = `Twist: ${e.twist || 0}° (Raw)`;
        fields.btns.innerText = `Btns: ${e.buttons} (Bitmask)`;

        // Calculate Azimuth and Altitude from Tilt
        // tiltX/Y are angles from the normal (perpendicular) plane.
        const tx = e.tiltX * (Math.PI / 180);
        const ty = e.tiltY * (Math.PI / 180);
        
        // Azimuth: The direction the pen is pointing (0-360)
        let azimuth = Math.atan2(Math.sin(ty), Math.sin(tx)) * (180 / Math.PI);
        if (azimuth < 0) azimuth += 360;
        
        // Altitude: The angle between the pen and the screen (0 is flat, 90 is vertical)
        const tiltMag = Math.sqrt(Math.pow(Math.tan(tx), 2) + Math.pow(Math.tan(ty), 2));
        const altitude = Math.atan(1 / (tiltMag || 0.0001)) * (180 / Math.PI);

        fields.azi.innerText = `Azi: ${Math.round(azimuth)}° (Calc)`;
        fields.alt.innerText = `Alt: ${Math.round(altitude)}° (Calc)`;

        // Update Visual Compass
        // We only rotate the needle if there is actual tilt detected
        if (Math.abs(e.tiltX) > 0.5 || Math.abs(e.tiltY) > 0.5) {
            needle.style.transform = `translate(-50%, -100%) rotate(${azimuth + 90}deg)`;
            needle.style.opacity = "1";
            // Scale needle height based on tilt (longer = flatter pen)
            needle.style.height = (12 * (1 - altitude/90)) + "px";
        } else {
            needle.style.opacity = "0.3";
            needle.style.height = "2px";
        }

        // Interpret Bitmask & Pointer Type
        let status = "IDLE";
        let color = "#00ff00"; // Default Green

        if (e.pointerType === 'pen') {
            if (e.buttons === 0) {
                status = "PEN HOVERING";
                color = "#00ccff"; // Cyan for Hover
            } else if (e.buttons & 32) {
                status = "PEN ERASER (Tail)";
                color = "#ff3b30"; // Red for Erase
            } else if (e.buttons & 2) {
                status = "PEN BARREL BTN";
                color = "#ff9500"; // Orange for Barrel
            } else if (e.buttons & 1) {
                status = "PEN DRAWING (Tip)";
                color = "#34c759"; // Bright Green for Active Pen
            }
        } else if (e.pointerType === 'touch') {
            status = "FINGER TOUCH";
            color = "#af52de"; // Purple for Touch
        } else if (e.pointerType === 'mouse') {
            status = e.buttons > 0 ? "MOUSE CLICK" : "MOUSE MOVE";
            color = "#ffffff";
        }
        
        fields.hover.innerText = `STATUS: ${status}`;
        fields.hover.style.color = color;
    }

    // Hook into the main container for comprehensive coverage
    const container = document.getElementById('canvas-container');
    container.addEventListener('pointermove', update, { passive: true });
    container.addEventListener('pointerdown', update, { passive: true });
    container.addEventListener('pointerup', update, { passive: true });
    container.addEventListener('pointerleave', () => {
        if (!isEnabled) return;
        fields.hover.innerText = "STATUS: OUT OF RANGE";
        fields.hover.style.color = "#ff3b30";
    });
})();
</script>