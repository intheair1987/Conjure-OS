<?php
/**
 * Whiteboard Tilt: Palette Overlay
 * Visual selection UI that appears at the pen tip.
 */
?>
<div id="wb-tilt-palette" style="
    position: fixed; pointer-events: auto; z-index: 8600; display: none;
    width: 200px; height: 200px; transform: translate(-50%, -50%);
">
    <!-- Circular Palette Structure -->
    <div style="
        width: 100%; height: 100%; border-radius: 50%; background: rgba(255,255,255,0.1);
        backdrop-filter: blur(10px); border: 2px solid rgba(255,255,255,0.2);
        box-shadow: 0 15px 40px rgba(0,0,0,0.4); position: relative;
    ">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 10px; font-weight: 900; color: white; opacity: 0.5;">PALETTE</div>
    </div>
</div>

<script>
(function() {
    const palette = document.getElementById('wb-tilt-palette');

    window.wbTiltPaletteActivate = function(e) {
        palette.style.left = e.clientX + 'px';
        palette.style.top = e.clientY + 'px';
        palette.style.display = 'block';
    };

    window.wbTiltPaletteDeactivate = function() {
        palette.style.display = 'none';
    };
})();
</script>