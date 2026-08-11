<?php
// Asset fingerprinting for cache-busting
function get_asset_hash($files) {
    $combined = '';
    foreach ($files as $f) {
        $path = __DIR__ . '/' . $f;
        if (file_exists($path)) $combined .= md5_file($path);
    }
    return $combined ? substr(md5($combined), 0, 8) : 'dev';
}
$v = get_asset_hash(['css/style.css', 'js/app.js']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="description" content="A physics-based 3D stacking game. Drop each building block with precision, stabilize the swaying tower, and build the highest skyscraper possible!">
    <title>Tower Bloxx</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo $v; ?>">
    <!-- Load Three.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tween.js/18.6.4/tween.umd.js"></script>
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="TowerBloxx">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
</head>
<body>
    <div id="game-container"></div>
    
    <div id="ui-layer">
        <div class="hud-top">
            <div class="score-pill">
                <span id="score">0</span>
                <div class="combo-text" id="combo-display">Combo x1</div>
            </div>
            <div class="lives" id="lives-display">❤️❤️❤️</div>
        </div>

        <div id="start-screen" class="overlay active">
            <h1 class="title">TOWER BLOXX</h1>
            <p class="subtitle">Tap anywhere to drop the block.</p>
            <p class="subtitle" style="font-size:14px; opacity:0.7;">Stack perfectly to stabilize the tower!</p>
            <button id="start-btn" class="action-btn">PLAY NOW</button>
        </div>

        <div id="game-over-screen" class="overlay">
            <h1 class="title" style="color: #ff4757;">TOWER COLLAPSED</h1>
            <p class="subtitle">Final Height: <span id="final-score">0</span></p>
            <button id="restart-btn" class="action-btn">TRY AGAIN</button>
        </div>
    </div>

    <!-- Flash effect for perfect drops -->
    <div id="flash-fx"></div>

    <script src="js/app.js?v=<?php echo $v; ?>"></script>
</body>
</html>