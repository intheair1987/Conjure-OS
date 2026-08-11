<?php
require_once __DIR__ . '/modules/scanner.php';

// Initialize the scanner and fetch all sibling applications
$scanner = new PWAScanner(dirname(__DIR__));
$apps = $scanner->scanApps();

// Read local settings for custom emojis and preset background colors
$settingsFile = __DIR__ . '/data/settings.json';
$defaultEmojis = ['🍳', '✨', '📝', '🚀', '📊', '🌱', '⚡', '💬', '🔥', '🎨', '🥑', '🧩'];
$defaultColors = ['#FEF3C7', '#E0F2FE', '#FEE2E2', '#DCFCE7', '#F3E8FF', '#FFF1F2'];
$emojis = $defaultEmojis;
$colors = $defaultColors;
$securePressEnabled = true;
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    if (isset($settings['emojis']) && is_array($settings['emojis'])) {
        $emojis = $settings['emojis'];
    }
    if (isset($settings['colors']) && is_array($settings['colors'])) {
        $colors = $settings['colors'];
    }
    if (isset($settings['securePressEnabled'])) {
        $securePressEnabled = (bool)$settings['securePressEnabled'];
    }
}

$presetsFile = __DIR__ . '/data/presets.json';
$globalPresets = file_exists($presetsFile) ? json_decode(file_get_contents($presetsFile), true) : [];
if (!is_array($globalPresets)) $globalPresets = [];

// Cache-busting version fingerprint
function get_asset_hash($files) {
    $combined = '';
    foreach ($files as $f) {
        $path = __DIR__ . '/' . $f;
        if (file_exists($path)) $combined .= md5_file($path);
    }
    return $combined ? substr(md5($combined), 0, 8) : 'dev';
}
$v = get_asset_hash(['css/style.css', 'js/app.js']);

function get_contrast_color($hex) {
    if (!$hex || strpos($hex, '#') !== 0) return '#1C1917';
    $hex = ltrim($hex, '#');
    if (strlen($hex) == 3) {
        $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
        $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
        $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    return ($yiq >= 128) ? '#1C1917' : '#FFFFFF';
}

// SSOT Icon Compiler for PHP
function build_icon_svg($config, $innerSvgContent = null) {
    $bg = $config['bg'] ?? '#E5E7EB';
    $sizeVal = isset($config['size']) && $config['size'] !== null ? (float)$config['size'] : 100;
    $id = htmlspecialchars($config['id'] ?? 'main');

    $bgTexture = $config['bgTexture'] ?? 'none';
    $bgTextureScale = (int)($config['bgTextureScale'] ?? 20);
    $bgTextureThickness = (int)($config['bgTextureThickness'] ?? 2);
    $bgTextureColor = $config['bgTextureColor'] ?? '';
    $contShadowDist = (int)($config['contShadowDist'] ?? 0);
    $contShadowBlur = (int)($config['contShadowBlur'] ?? 0);
    $contShadowAngle = (int)($config['contShadowAngle'] ?? 90);
    $contOutlineStyle = $config['contOutlineStyle'] ?? 'none';
    $contOutlineWidth = (int)($config['contOutlineWidth'] ?? 4);
    $contOutlineColor = htmlspecialchars($config['contOutlineColor'] ?? '#1c1917');
    $innerRotation = (int)($config['innerRotation'] ?? 0);
    $bgOpacity = isset($config['bgOpacity']) ? (float)$config['bgOpacity'] : 100;
    $fillOpacity = $bgOpacity / 100;
    $contShadowEnabled = isset($config['contShadowEnabled']) ? (bool)$config['contShadowEnabled'] : ((int)($config['contShadowDist'] ?? 0) > 0 || (int)($config['contShadowBlur'] ?? 0) > 0);

    $contSize = isset($config['contSize']) ? (float)$config['contSize'] : 100;
    $contScale = $contSize / 100;

    $defs = '';
    if ($bgTexture !== 'none') {
        $patColor = $bgTextureColor ? htmlspecialchars($bgTextureColor) : get_contrast_color($bg);
        $patId = "pat-{$bgTexture}-{$id}";
        $halfScale = $bgTextureScale / 2;
        if ($bgTexture === 'dots') {
            $defs .= "<pattern id=\"{$patId}\" width=\"{$bgTextureScale}\" height=\"{$bgTextureScale}\" patternUnits=\"userSpaceOnUse\"><circle cx=\"{$halfScale}\" cy=\"{$halfScale}\" r=\"{$bgTextureThickness}\" fill=\"{$patColor}\" opacity=\"0.15\"/></pattern>";
        } else if ($bgTexture === 'lines') {
            $defs .= "<pattern id=\"{$patId}\" width=\"{$bgTextureScale}\" height=\"{$bgTextureScale}\" patternUnits=\"userSpaceOnUse\" patternTransform=\"rotate(45)\"><line x1=\"0\" y1=\"0\" x2=\"0\" y2=\"{$bgTextureScale}\" stroke=\"{$patColor}\" stroke-width=\"{$bgTextureThickness}\" opacity=\"0.15\"/></pattern>";
        } else if ($bgTexture === 'grid') {
            $defs .= "<pattern id=\"{$patId}\" width=\"{$bgTextureScale}\" height=\"{$bgTextureScale}\" patternUnits=\"userSpaceOnUse\"><rect width=\"{$bgTextureScale}\" height=\"{$bgTextureScale}\" fill=\"none\" stroke=\"{$patColor}\" stroke-width=\"{$bgTextureThickness}\" opacity=\"0.15\"/></pattern>";
        }
    }

    $contFilterAttr = '';
    $masterTransform = '';
    $needsPadding = ($contShadowEnabled && ($contShadowDist > 0 || $contShadowBlur > 0)) || ($contOutlineStyle !== 'none' && $contOutlineWidth > 0);
    
    $baseScale = $needsPadding ? 0.83 : 1.0;
    $masterTransform = '';
    if ($baseScale !== 1.0) {
        $trans = 256 * (1 - $baseScale);
        $masterTransform = "transform=\"translate({$trans}, {$trans}) scale({$baseScale})\"";
    }
    
    $contTransform = '';
    if ($contScale !== 1.0) {
        $ctrans = 256 * (1 - $contScale);
        $contTransform = "transform=\"translate({$ctrans}, {$ctrans}) scale({$contScale})\"";
    }
    if ($contShadowEnabled && ($contShadowDist > 0 || $contShadowBlur > 0)) {
        $filterId = "cont-shadow-{$id}";
        $dx = round($contShadowDist * cos(deg2rad($contShadowAngle)), 2);
        $dy = round($contShadowDist * sin(deg2rad($contShadowAngle)), 2);
        $defs .= "<filter id=\"{$filterId}\" x=\"-20%\" y=\"-20%\" width=\"140%\" height=\"140%\"><feDropShadow dx=\"{$dx}\" dy=\"{$dy}\" stdDeviation=\"{$contShadowBlur}\" flood-color=\"#000000\" flood-opacity=\"0.4\"/></filter>";
        $contFilterAttr = "filter=\"url(#{$filterId})\"";
    }

    $shapePath = '';
    if (($config['shape'] ?? '') === 'circle') {
        $shapePath = '<circle cx="256" cy="256" r="256"';
    } else if (($config['shape'] ?? '') === 'square') {
        $shapePath = '<rect width="512" height="512" rx="112"';
    } else {
        $shapePath = '<path d="M256,0 C76.8,0 0,76.8 0,256 C0,435.2 76.8,512 256,512 C435.2,512 512,435.2 512,256 C512,76.8 435.2,0 256,0 Z"';
    }

    $shapeXml = "{$shapePath} fill=\"" . htmlspecialchars($bg) . "\" fill-opacity=\"{$fillOpacity}\" />";
    if ($bgTexture !== 'none') {
        $shapeXml .= "\n  {$shapePath} fill=\"url(#pat-{$bgTexture}-{$id})\" />";
    }
    if ($contOutlineStyle !== 'none') {
        $shapeXml .= "\n  {$shapePath} fill=\"none\" stroke=\"{$contOutlineColor}\" stroke-width=\"{$contOutlineWidth}\" />";
        if ($contOutlineStyle === 'double') {
            $halfWidth = $contOutlineWidth / 2;
            $shapeXml .= "\n  {$shapePath} fill=\"none\" stroke=\"" . htmlspecialchars($bg) . "\" stroke-width=\"{$halfWidth}\" stroke-opacity=\"{$fillOpacity}\" />";
        }
    }

    $contentXml = '';
    $shadowVal = isset($config['shadow']) && $config['shadow'] !== null ? $config['shadow'] : 5;
    $innerFilterAttr = '';
    if ($shadowVal > 0) {
        $opacity = $shadowVal / 100;
        $filterId = "inner-shadow-{$id}";
        $defs .= "<filter id=\"{$filterId}\" x=\"-20%\" y=\"-20%\" width=\"140%\" height=\"140%\"><feDropShadow dx=\"0\" dy=\"16\" stdDeviation=\"24\" flood-color=\"#112C20\" flood-opacity=\"{$opacity}\"/></filter>";
        $innerFilterAttr = "filter=\"url(#{$filterId})\"";
    }

    if (($config['type'] ?? '') === 'outline') {
        if ($innerSvgContent) {
            $scale = 12 * ($sizeVal / 100);
            $translate = (512 - (24 * $scale)) / 2;
            $strokeColor = htmlspecialchars($config['strokeColor'] ?? '#1c1917');
            $stroke = htmlspecialchars($config['stroke'] ?? 2);
            $contentXml = "<g {$innerFilterAttr}><g class=\"lucide-" . htmlspecialchars($config['icon'] ?? '') . "\" transform=\"translate({$translate}, {$translate}) scale({$scale})\" stroke=\"{$strokeColor}\" stroke-width=\"{$stroke}\" fill=\"none\" stroke-linecap=\"round\" stroke-linejoin=\"round\" color=\"{$strokeColor}\">{$innerSvgContent}</g></g>";
        }
    } else {
        $emoji = htmlspecialchars($config['emoji'] ?? '');
        $fontSize = 240 * ($sizeVal / 100);
        $contentXml = "<text x=\"256\" y=\"340\" font-size=\"{$fontSize}\" font-family=\"'Apple Color Emoji', 'Segoe UI Emoji', 'Noto Color Emoji', sans-serif\" text-anchor=\"middle\" {$innerFilterAttr}>{$emoji}</text>";
    }

    $innerGroup = "<g transform=\"rotate({$innerRotation}, 256, 256)\">\n    {$contentXml}\n  </g>";
    $containerGroup = "<g {$contTransform} {$contFilterAttr}>\n    {$shapeXml}\n  </g>";
    $masterGroup = "<g {$masterTransform}>\n    {$containerGroup}\n    {$innerGroup}\n  </g>";
    $defsXml = $defs ? "<defs>\n    {$defs}\n  </defs>\n  " : "";

    $configJson = htmlspecialchars(json_encode($config), ENT_QUOTES, 'UTF-8');
    return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 512 512\" width=\"100%\" height=\"100%\" data-pwa-studio-config=\"{$configJson}\">\n  {$defsXml}{$masterGroup}\n</svg>";
}?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>PWA Studio — UI Workspace</title>
  
  <link rel="manifest" href="manifest.json">
  <link rel="icon" href="icon.svg">
  
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  
  <!-- Google Fonts (Inter) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Lucide Icons -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <script>
    window.PWA_STUDIO_EMOJIS = <?php echo json_encode($emojis); ?>;
    window.PWA_STUDIO_COLORS = <?php echo json_encode($colors); ?>;
    window.PWA_STUDIO_PRESETS = <?php echo json_encode($globalPresets); ?>;
    window.PWA_STUDIO_SECURE_PRESS = <?php echo $securePressEnabled ? 'true' : 'false'; ?>;
  </script>

  <!-- App Styles -->
  <link rel="stylesheet" href="css/style.css?v=<?php echo $v; ?>">
  
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: {
              DEFAULT: '#1B4332',
              dark: '#112C20',
              light: '#EAF0EC',
            },
            accent: '#065F46',
          }
        }
      }
    }
  </script>
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="PWAStudio">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
</head>
<body class="bg-stone-100 text-stone-800 h-full flex flex-col items-center justify-center overflow-hidden antialiased">

  <!-- SVG Definition for perfect organic squircle clip-path -->
  <svg class="absolute w-0 h-0" width="0" height="0">
    <defs>
      <clipPath id="squircle-clip" clipPathUnits="objectBoundingBox">
        <path d="M 0,0.5 C 0,0.1 0.1,0 0.5,0 C 0.9,0 1,0.1 1,0.5 C 1,0.9 0.9,1 0.5,1 C 0.1,1 0,0.9 0,0.5" />
      </clipPath>
    </defs>
  </svg>

  <!-- Interactive Desktop Wrap Device Frame -->
  <div class="relative w-full max-w-md h-full md:h-[840px] md:max-h-[90vh] bg-stone-50 md:rounded-[36px] md:shadow-[0_16px_40px_rgba(0,0,0,0.06)] md:border-8 md:border-stone-900 flex flex-col overflow-hidden transition-all duration-300">
    
    <!-- Phone Notch/StatusBar Simulator for Desktop viewports -->
    <div class="hidden md:flex justify-between items-center px-8 pt-3 pb-1 bg-stone-50 select-none text-xs text-stone-400 font-medium z-50">
      <span>9:41</span>
      <div class="w-16 h-4 bg-stone-900 rounded-full mx-2"></div>
      <div class="flex items-center space-x-1">
        <i data-lucide="signal" class="w-3 h-3 stroke-[2]"></i>
        <i data-lucide="wifi" class="w-3 h-3 stroke-[2]"></i>
        <i data-lucide="battery" class="w-3 h-3 stroke-[2]"></i>
      </div>
    </div>

    <!-- Active App Notification Toast -->
    <div id="toast" class="absolute top-12 left-4 right-4 bg-stone-900 text-stone-100 text-xs py-3 px-4 rounded-xl shadow-lg flex items-center space-x-2 transform translate-y-[-100px] opacity-0 transition-all duration-300 z-50 pointer-events-none">
      <i data-lucide="check-circle" class="w-4 h-4 text-emerald-400 stroke-[2.5]"></i>
      <span id="toast-text" class="font-medium">Active icon config updated quietly!</span>
    </div>

    <!-- MAIN APP SCREEN WRAPPER -->
    <main class="flex-1 relative overflow-hidden flex flex-col bg-stone-50/50">

      <!-- ==================== SCREEN 1: HOME (MINI-APPS LIST) ==================== -->
      <section id="screen-home" class="absolute inset-0 flex flex-col transition-all duration-300 z-20">
        <!-- Stable Header -->
        <header class="px-6 py-5 flex items-center justify-between bg-white border-b border-stone-200/60 select-none">
          <div>
            <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Workspace <span class="ml-2 bg-stone-200 text-stone-500 px-1.5 py-0.5 rounded">v<?php echo $v; ?></span></span>
            <h1 class="text-xl font-bold text-stone-900 tracking-tight">PWA Studio</h1>
          </div>
          <div class="flex items-center space-x-2">
            <button onclick="toggleBatchDrawer()" class="p-2 rounded-xl bg-stone-100 text-stone-600 hover:bg-stone-200/60 active:scale-95 transition-all" aria-label="Global Presets">
              <i data-lucide="palette" class="w-5 h-5 stroke-[2]"></i>
            </button>
            <button onclick="openSettingsModal()" class="p-2 rounded-xl bg-stone-100 text-stone-600 hover:bg-stone-200/60 active:scale-95 transition-all" aria-label="Settings">
              <i data-lucide="sliders" class="w-5 h-5 stroke-[2]"></i>
            </button>
          </div>
        </header>

        <!-- Static Search Frame -->
        <div class="px-5 py-4 bg-white/40 border-b border-stone-200/40">
          <div class="relative flex items-center bg-stone-100/80 rounded-xl px-3.5 py-2.5 border border-stone-200/30">
            <i data-lucide="search" class="w-4 h-4 text-stone-400 stroke-[2] mr-2"></i>
            <input id="app-search-input" type="text" oninput="handleAppSearch()" placeholder="Search mini-apps..." class="bg-transparent text-sm text-stone-700 outline-none w-full placeholder-stone-400" />
          </div>
        </div>

        <!-- Scrollable content list dynamically rendered from scanner -->
        <div class="flex-1 overflow-y-auto overscroll-contain px-5 py-4 space-y-3 no-scrollbar">
          
          <?php foreach ($apps as $app): ?>
            <?php
              $iconActive = $app['is_high_res'] ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100/50 text-stone-300';
              $fsActive = $app['is_fullscreen'] ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100/50 text-stone-300';
              $bgActive = $app['is_backup_safe'] ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100/50 text-stone-300';
              
              // Fallbacks for display
              $displayBg = !empty($app['color']) ? $app['color'] : '#E5E7EB';
              $displayIcon = !empty($app['icon']) ? $app['icon'] : '📦';
              $tabIconActive = $app['is_tab_icon'] ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100/50 text-stone-300';

              $isTransparent = false;
              $shape = 'squircle';
              if (strpos(trim($displayIcon), '<svg') === 0) {
                  if (strpos($displayIcon, 'fill-opacity="0"') !== false || strpos($displayIcon, 'fill-opacity="0.0"') !== false) {
                      $isTransparent = true;
                      if (strpos($displayIcon, '<circle cx="256" cy="256"') !== false) {
                          $shape = 'circle';
                      } elseif (strpos($displayIcon, '<rect width="512" height="512"') !== false) {
                          $shape = 'square';
                      }
                  }
              }
              
              $wrapperClasses = "app-icon-wrapper w-12 h-12 shrink-0 relative flex items-center justify-center pwa-app-icon";
              if ($isTransparent) {
                  $wrapperClasses .= " border border-dashed border-stone-300 bg-stone-100/20";
                  if ($shape === 'circle') {
                      $wrapperClasses .= " rounded-full";
                  } else {
                      $wrapperClasses .= " rounded-xl";
                  }
              } else {
                  $wrapperClasses .= " drop-shadow-sm";
              }
            ?>
            <div class="group bg-white p-4 rounded-2xl border border-stone-200/60 hover:border-stone-300 shadow-[0_4px_12px_rgba(0,0,0,0.01)] hover:shadow-[0_8px_24px_rgba(0,0,0,0.02)] active:scale-[0.98] transition-all duration-200 cursor-pointer flex items-center justify-between app-card"
                 data-app-name="<?php echo htmlspecialchars($app['name'], ENT_QUOTES); ?>"
                 data-app-folder="<?php echo htmlspecialchars($app['folder'], ENT_QUOTES); ?>"
                 data-app-icon="<?php echo htmlspecialchars($displayIcon, ENT_QUOTES); ?>"
                 data-app-bg="<?php echo htmlspecialchars($displayBg, ENT_QUOTES); ?>"
                 onclick="openWorkspaceFromElement(this)">
              <div class="flex items-center space-x-3.5 min-w-0">
                <div class="<?php echo $wrapperClasses; ?>">
                  <?php if (strpos(trim($displayIcon), '<svg') === 0): ?>
                    <?php echo $displayIcon; ?>
                  <?php else: ?>
                    <?php 
                      echo build_icon_svg([
                          'type' => 'emoji',
                          'bg' => $displayBg,
                          'shape' => 'squircle',
                          'emoji' => $displayIcon,
                          'shadow' => 5,
                          'id' => 'list-' . htmlspecialchars($app['name'], ENT_QUOTES)
                      ]); 
                    ?>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <h3 class="font-semibold text-stone-900 text-sm truncate"><?php echo htmlspecialchars($app['name'], ENT_QUOTES); ?></h3>
                  <p class="text-xs text-stone-400 truncate">/apps/<?php echo htmlspecialchars($app['folder'], ENT_QUOTES); ?></p>
                </div>
              </div>
              <!-- Dynamic Status Indicators -->
              <div class="flex items-center space-x-1.5 shrink-0" onclick="event.stopPropagation()">
                <button onclick="showIndicatorStatus('high_res', <?php echo $app['is_high_res'] ? 'true' : 'false'; ?>, '<?php echo htmlspecialchars($app['folder'], ENT_QUOTES); ?>')" class="w-8 h-8 rounded-lg <?php echo $iconActive; ?> hover:opacity-80 active:scale-90 transition-all flex items-center justify-center active-indicator" title="High-Res Icon">
                  <i data-lucide="image" class="w-4 h-4 stroke-[2]"></i>
                </button>
                <button onclick="showIndicatorStatus('fullscreen', <?php echo $app['is_fullscreen'] ? 'true' : 'false'; ?>, '<?php echo htmlspecialchars($app['folder'], ENT_QUOTES); ?>')" class="w-8 h-8 rounded-lg <?php echo $fsActive; ?> hover:opacity-80 active:scale-90 transition-all flex items-center justify-center active-indicator" title="Fullscreen Mode">
                  <i data-lucide="maximize" class="w-4 h-4 stroke-[2]"></i>
                </button>
                <button onclick="showIndicatorStatus('tab_icon', <?php echo $app['is_tab_icon'] ? 'true' : 'false'; ?>, '<?php echo htmlspecialchars($app['folder'], ENT_QUOTES); ?>')" class="w-8 h-8 rounded-lg <?php echo $tabIconActive; ?> hover:opacity-80 active:scale-90 transition-all flex items-center justify-center active-indicator" title="Tab Favicon">
                  <i data-lucide="globe" class="w-4 h-4 stroke-[2]"></i>
                </button>
                <button onclick="showIndicatorStatus('backup', <?php echo $app['is_backup_safe'] ? 'true' : 'false'; ?>, '<?php echo htmlspecialchars($app['folder'], ENT_QUOTES); ?>')" class="w-8 h-8 rounded-lg <?php echo $bgActive; ?> hover:opacity-80 active:scale-90 transition-all flex items-center justify-center active-indicator" title="Backup Safe">
                  <i data-lucide="cloud-check" class="w-4 h-4 stroke-[2]"></i>
                </button>
              </div>
            </div>
          <?php endforeach; ?>

        </div>
      </section>


      <!-- ==================== SCREEN 2: WORKSPACE (ICON CUSTOMIZER) ==================== -->
      <section id="screen-workspace" class="absolute inset-0 flex flex-col transition-all duration-300 translate-x-full z-30">
        
        <!-- Stable Header with Back Button -->
        <header class="px-5 py-4 flex items-center justify-between bg-white border-b border-stone-200/60 select-none">
          <div class="flex items-center space-x-3">
            <button onclick="closeWorkspace()" class="w-9 h-9 rounded-xl bg-stone-100 text-stone-600 hover:bg-stone-200/60 active:scale-95 transition-all flex items-center justify-center" aria-label="Go Back">
              <i data-lucide="chevron-left" class="w-5 h-5 stroke-[2.5]"></i>
            </button>
            <div>
              <span class="text-[10px] font-semibold uppercase tracking-wider text-stone-400">Customizer</span>
              <h2 id="workspace-title" class="text-sm font-bold text-stone-900 tracking-tight leading-none">App Name</h2>
            </div>
          </div>
          <button id="save-draft-btn" onclick="saveCurrentDraft()" class="px-3.5 py-1.5 text-xs font-semibold rounded-xl bg-stone-100 hover:bg-stone-200/80 active:scale-95 text-stone-700 transition-all flex items-center space-x-1">
            <i data-lucide="folder-plus" class="w-3.5 h-3.5 stroke-[2.5]"></i>
            <span>Save Draft</span>
          </button>
        </header>

        <!-- Dynamic Scrolling Container -->
        <div class="flex-1 overflow-y-auto overscroll-contain no-scrollbar flex flex-col">
          
          <!-- TOP HALF: LIVE PREVIEW SYSTEM -->
          <div class="p-5 bg-stone-50/70 backdrop-blur-xl border-b border-stone-200/40 flex flex-col shrink-0 select-none sticky top-0 z-40">
            <div class="flex justify-between items-center mb-3">
              <span class="text-xs font-bold text-stone-400 uppercase tracking-wider">Simultaneous Mockup Previews</span>
              <div class="flex space-x-1.5 text-[10px] font-semibold text-stone-500 bg-stone-200/60 p-0.5 rounded-lg">
                <span class="px-2 py-0.5 bg-white text-stone-800 rounded-md shadow-[0_1px_3px_rgba(0,0,0,0.03)]">All Views</span>
              </div>
            </div>

            <div class="grid grid-cols-3 gap-3.5 h-36">
              <!-- Preview 1: Isolated -->
              <div class="bg-gradient-to-tr from-sky-400 to-indigo-500 rounded-2xl flex flex-col items-center justify-center relative overflow-hidden shadow-inner p-2">
                <div class="absolute inset-0 bg-black/5"></div>
                <div id="preview-mockup-main" class="w-14 h-14 flex items-center justify-center drop-shadow-lg transition-all duration-300 transform scale-100 hover:scale-105">
                  <div id="preview-element-icon" class="w-full h-full flex items-center justify-center"></div>
                </div>
                <span class="text-[9px] font-semibold text-white/95 mt-2 bg-black/10 px-2 py-0.5 rounded-full z-10 backdrop-blur-[2px]">Isolated</span>
              </div>

              <!-- Preview 2: Grid -->
              <div class="bg-gradient-to-b from-[#2B1B43] to-[#120921] rounded-2xl flex flex-col items-center justify-center p-2 relative overflow-hidden">
                <div class="grid grid-cols-2 gap-2 transform scale-90">
                  <div class="w-7 h-7 flex items-center justify-center drop-shadow-sm pwa-app-icon">
                    <?php echo build_icon_svg(['type'=>'emoji', 'bg'=>'#34C759', 'shape'=>'squircle', 'emoji'=>'💬', 'shadow'=>5, 'id'=>'grid-1']); ?>
                  </div>
                  <div class="w-7 h-7 flex items-center justify-center drop-shadow-sm pwa-app-icon">
                    <?php echo build_icon_svg(['type'=>'emoji', 'bg'=>'#E5E7EB', 'shape'=>'squircle', 'emoji'=>'📸', 'shadow'=>5, 'id'=>'grid-2']); ?>
                  </div>
                  <div id="preview-mockup-grid" class="w-7 h-7 flex items-center justify-center drop-shadow-sm transition-all duration-300 pwa-app-icon">
                    <div id="preview-element-grid-icon" class="w-full h-full flex items-center justify-center"></div>
                  </div>
                  <div class="w-7 h-7 flex items-center justify-center drop-shadow-sm pwa-app-icon">
                    <?php echo build_icon_svg(['type'=>'emoji', 'bg'=>'#E5E7EB', 'shape'=>'squircle', 'emoji'=>'🧭', 'shadow'=>5, 'id'=>'grid-3']); ?>
                  </div>
                </div>
                <span class="text-[9px] font-semibold text-white/90 mt-1.5 z-10 bg-black/25 px-1.5 py-0.5 rounded-full">Grid view</span>
              </div>

              <!-- Preview 3: Dock -->
              <div class="bg-stone-800 rounded-2xl flex flex-col items-center justify-end p-2 pb-3.5 relative overflow-hidden">
                <div class="absolute inset-x-2 bottom-2 h-11 bg-white/15 rounded-xl backdrop-blur-md border border-white/5 flex items-center justify-center">
                  <div id="preview-mockup-dock" class="w-7 h-7 flex items-center justify-center drop-shadow-md transition-all duration-300">
                    <div id="preview-element-dock-icon" class="w-full h-full flex items-center justify-center"></div>
                  </div>
                </div>
                <span class="text-[9px] font-semibold text-stone-300 z-10 bg-black/40 px-1.5 py-0.5 rounded-full mb-8">System Dock</span>
              </div>
            </div>
          </div>

          <!-- DRAFT SHELF / VARIATIONS TRAY -->
          <div class="py-4 border-b border-stone-200/50 bg-white flex flex-col shrink-0">
            <div class="flex justify-between items-center mb-2.5 px-5 select-none">
              <span class="text-xs font-bold text-stone-400 uppercase tracking-wider">Presets & Variations</span>
              <span class="text-[10px] text-stone-400 font-medium">Tap to quickly set as active icon</span>
            </div>
            <div id="drafts-container" class="flex space-x-3 overflow-x-auto overscroll-x-contain overscroll-y-auto no-scrollbar py-1 px-5">
              <!-- Dynamically injected drafts go here -->
            </div>
          </div>

          <!-- BOTTOM HALF: CONTROLS -->
          <div class="flex-1 bg-white p-5 flex flex-col">
            
            <div class="flex bg-stone-100 p-1.5 rounded-xl mb-6 select-none relative h-12">
              <button onclick="switchTab('outline')" id="tab-btn-outline" class="flex-1 text-xs font-semibold py-2 rounded-lg text-center transition-all duration-200 flex items-center justify-center space-x-1.5 bg-white text-stone-800 shadow-[0_2px_8px_rgba(0,0,0,0.03)]">
                <i data-lucide="pen-tool" class="w-3.5 h-3.5 stroke-[2.5]"></i>
                <span>Outline Drawing</span>
              </button>
              <button onclick="switchTab('emoji')" id="tab-btn-emoji" class="flex-1 text-xs font-semibold py-2 rounded-lg text-center transition-all duration-200 flex items-center justify-center space-x-1.5 text-stone-500">
                <i data-lucide="smile" class="w-3.5 h-3.5 stroke-[2.5]"></i>
                <span>Simple Emoji</span>
              </button>
            </div>

            <div class="flex-1 flex flex-col min-h-[300px]">

              <!-- TAB 1 CONTENT: OUTLINE -->
              <div id="tab-content-outline" class="space-y-6 flex flex-col flex-1">
                
                <div>
                  <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2.5">Look up Vector from Online Database</label>
                  <div class="relative flex items-center bg-stone-100/90 rounded-xl px-3.5 py-2.5 border border-stone-200/30">
                    <i data-lucide="search" class="w-4 h-4 text-stone-400 stroke-[2.5] mr-2"></i>
                    <input id="icon-search-input" type="text" oninput="handleIconSearch()" placeholder="Search library (e.g. sparkles, heart)..." class="bg-transparent text-xs text-stone-700 outline-none w-full placeholder-stone-400" />
                  </div>
                  <div class="flex space-x-2 overflow-x-auto no-scrollbar mt-2.5 items-center">
                    <button onpointerdown="SecurePress.start(event, () => selectDatabase('Lucide'))" id="db-chip-Lucide" class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider rounded-full bg-[#1B4332] text-white transition-all duration-150 whitespace-nowrap">Lucide Lib</button>
                    <button onpointerdown="SecurePress.start(event, () => selectDatabase('Feather'))" id="db-chip-Feather" class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider rounded-full bg-stone-100 text-stone-500 hover:bg-stone-200/60 transition-all duration-150 whitespace-nowrap">Feather</button>
                    <button onpointerdown="SecurePress.start(event, () => selectDatabase('Phosphor'))" id="db-chip-Phosphor" class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider rounded-full bg-stone-100 text-stone-500 hover:bg-stone-200/60 transition-all duration-150 whitespace-nowrap">Phosphor</button>
                    <button onpointerdown="SecurePress.start(event, () => selectDatabase('Local'))" id="db-chip-Local" class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider rounded-full bg-stone-100 text-stone-500 hover:bg-stone-200/60 transition-all duration-150 whitespace-nowrap">Local SVGs</button>
                    
                    <div class="flex-1 min-w-[8px]"></div>
                    
                    <button id="upload-local-svg-btn" onclick="document.getElementById('local-svg-upload').click()" class="hidden px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-full bg-stone-200 text-stone-600 hover:bg-stone-300 transition-all duration-150 flex items-center space-x-1 whitespace-nowrap shrink-0">
                      <i data-lucide="upload" class="w-3 h-3 stroke-[2.5]"></i>
                      <span>Upload</span>
                    </button>
                    <input type="file" id="local-svg-upload" accept="*/*" class="hidden" onchange="handleLocalSvgUpload(event)">
                  </div>
                </div>

                <div id="vector-search-results" class="grid grid-cols-6 gap-2 bg-stone-50 p-3 rounded-xl border border-stone-200/40 max-h-24 overflow-y-auto no-scrollbar overscroll-contain">
                  <!-- Search results -->
                </div>

                <div>
                  <div class="flex justify-between items-center mb-2">
                    <label class="text-xs font-bold text-stone-400 uppercase tracking-wider">Line stroke thickness</label>
                    <span id="stroke-value-label" class="text-xs font-semibold text-stone-700 bg-stone-100 px-2.5 py-0.5 rounded-md">2px</span>
                  </div>
                  <div class="relative flex items-center h-8">
                    <input id="stroke-slider" type="range" min="1" max="4" step="0.5" value="2" oninput="updateStrokeWeight(this.value)" class="w-full accent-brand h-1.5 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
                  </div>
                </div>

                <div class="pt-2">
                  <div class="flex items-center justify-between mb-2.5 select-none">
                    <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider">Line Stroke Color</label>
                    <button onclick="promptAddColor('stroke')" class="w-6 h-6 rounded-lg bg-stone-100 hover:bg-stone-200 text-stone-600 flex items-center justify-center transition-all active:scale-90" aria-label="Add Stroke Color Preset">
                      <i data-lucide="plus" class="w-3.5 h-3.5 stroke-[3]"></i>
                    </button>
                  </div>
                  <div class="flex justify-between items-center bg-stone-50 p-2.5 rounded-xl border border-stone-200/40 select-none">
                    <div class="flex space-x-2 overflow-x-auto no-scrollbar py-0.5 flex-1 pr-3" id="stroke-color-presets-container">
                      <!-- Rendered dynamically by JS -->
                    </div>
                    <div class="flex items-center space-x-1 border-l border-stone-200 pl-3 shrink-0">
                      <span id="stroke-hex-color-label" class="text-xs font-mono font-medium text-stone-500">#1C1917</span>
                    </div>
                  </div>
                </div>



              </div>

              <!-- TAB 2 CONTENT: EMOJI -->
              <div id="tab-content-emoji" class="space-y-6 flex flex-col flex-1 hidden">
                <div>
                  <div class="flex items-center justify-between mb-2.5 select-none">
                    <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider">Select simple emoji</label>
                    <button onclick="promptAddEmoji()" class="w-6 h-6 rounded-lg bg-stone-100 hover:bg-stone-200 text-stone-600 flex items-center justify-center transition-all active:scale-90" aria-label="Add Emoji">
                      <i data-lucide="plus" class="w-3.5 h-3.5 stroke-[3]"></i>
                    </button>
                  </div>
                  <div class="grid grid-cols-6 gap-2.5 bg-stone-50 p-3.5 rounded-xl border border-stone-200/40" id="emoji-picker-grid">
                    <!-- Dynamically rendered by JS using SSOT pipeline -->
                  </div>
                </div>
              </div>

              <!-- SHARED SETTINGS AREA -->
<div class="mt-auto pt-6 border-t border-stone-200/60 space-y-6">

  <!-- SECTION 1: INNER ELEMENT SETTINGS -->
<div class="p-4 bg-stone-50 rounded-xl border border-stone-200/60 space-y-3 shadow-sm">
  <h3 class="text-[10px] font-black text-stone-400 uppercase tracking-widest border-b border-stone-200/60 pb-2 mb-2">Inner Element Settings</h3>
                  
  <div class="flex items-center space-x-3">
    <div class="flex justify-between w-28 text-[9px] font-bold text-stone-400 uppercase tracking-wider"><span>Icon Size</span><span id="size-value-label">100%</span></div>
    <input id="size-slider" type="range" min="50" max="150" step="5" value="100" oninput="updateIconSize(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
  </div>

  <div class="flex items-center space-x-3">
    <div class="flex justify-between w-28 text-[9px] font-bold text-stone-400 uppercase tracking-wider"><span>Drop Shadow</span><span id="shadow-value-label">5%</span></div>
    <input id="shadow-slider" type="range" min="0" max="25" step="5" value="5" oninput="updateShadowIntensity(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
  </div>

  <div class="flex items-center space-x-3">
    <div class="flex justify-between w-28 text-[9px] font-bold text-stone-400 uppercase tracking-wider"><span>Rotation</span><span id="inner-rotation-label">0&deg;</span></div>
    <input id="inner-rotation-slider" type="range" min="-180" max="180" step="15" value="0" oninput="updateInnerRotation(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
  </div>
</div><!-- SECTION 2: CONTAINER BASE -->
  <div class="p-4 bg-stone-50 rounded-xl border border-stone-200/60 space-y-5 shadow-sm">
    <h3 class="text-[10px] font-black text-stone-400 uppercase tracking-widest border-b border-stone-200/60 pb-2 mb-2">Container Base</h3>
                  
    <div>
      <label class="block text-xs font-bold text-stone-500 uppercase tracking-wider mb-2.5">Background Shape</label>
      <div class="grid grid-cols-3 gap-2.5 select-none">
        <button id="shape-chip-squircle" onpointerdown="SecurePress.start(event, () => updateShape('squircle'))" class="py-2 text-xs font-semibold rounded-xl transition-all border border-transparent bg-white shadow-sm text-stone-800 flex flex-col items-center space-y-1">
          <div class="w-4 h-4 bg-stone-700 squircle mt-1"></div>
          <span>Squircle</span>
        </button>
        <button id="shape-chip-circle" onpointerdown="SecurePress.start(event, () => updateShape('circle'))" class="py-2 text-xs font-semibold rounded-xl transition-all border border-transparent bg-white shadow-sm text-stone-800 flex flex-col items-center space-y-1">
          <div class="w-4 h-4 bg-stone-700 rounded-full mt-1"></div>
          <span>Circle</span>
        </button>
        <button id="shape-chip-square" onpointerdown="SecurePress.start(event, () => updateShape('square'))" class="py-2 text-xs font-semibold rounded-xl transition-all border border-transparent bg-white shadow-sm text-stone-800 flex flex-col items-center space-y-1">
          <div class="w-4 h-4 bg-stone-700 rounded-lg mt-1"></div>
          <span>Square</span>
        </button>
      </div>
    </div>

    <div>
      <div class="flex items-center justify-between mb-2.5 select-none">
        <label class="block text-xs font-bold text-stone-500 uppercase tracking-wider">Backdrop Color</label>
        <button onclick="promptAddColor('bg')" class="w-6 h-6 rounded-lg bg-white border border-stone-200/60 hover:bg-stone-200 text-stone-600 flex items-center justify-center transition-all active:scale-90 shadow-sm" aria-label="Add Color Preset">
          <i data-lucide="plus" class="w-3.5 h-3.5 stroke-[3]"></i>
        </button>
      </div>
      <div class="flex justify-between items-center bg-white p-2.5 rounded-xl border border-stone-200/60 select-none shadow-sm">
        <div class="flex space-x-2 overflow-x-auto no-scrollbar py-0.5 flex-1 pr-3" id="color-presets-container"></div>
        <div class="flex items-center space-x-1 border-l border-stone-200 pl-3 shrink-0">
          <span id="hex-color-label" class="text-xs font-mono font-medium text-stone-500">#FEF3C7</span>
        </div>
      </div>
    </div>

    <div class="flex items-center space-x-3 pt-2 border-t border-stone-200/60">
      <div class="flex justify-between w-28 text-[9px] font-bold text-stone-400 uppercase tracking-wider shrink-0"><span>BG Opacity</span><span id="bg-opacity-label">100%</span></div>
      <input id="bg-opacity-slider" type="range" min="0" max="100" step="5" value="100" oninput="updateBgOpacity(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
    </div>

    <div class="flex items-center space-x-3">
      <div class="flex justify-between w-28 text-[9px] font-bold text-stone-400 uppercase tracking-wider items-center shrink-0">
        <div class="flex items-center space-x-1">
          <span>CTR Size</span>
          <button id="cont-size-lock-btn" onpointerdown="SecurePress.start(event, () => toggleContSizeLock())" class="text-stone-400 hover:text-brand transition-colors focus:outline-none" title="Toggle Lock">
            <i data-lucide="unlock" id="cont-size-lock-icon" class="w-3 h-3 stroke-[2.5]"></i>
          </button>
        </div>
        <span id="cont-size-label">100%</span>
      </div>
      <input id="cont-size-slider" type="range" min="50" max="100" step="5" value="100" oninput="updateContSize(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
    </div>
  </div>

  <!-- SECTION 3: CONTAINER EFFECTS -->
  <div class="p-4 bg-stone-50 rounded-xl border border-stone-200/60 space-y-5 shadow-sm">
    <h3 class="text-[10px] font-black text-stone-400 uppercase tracking-widest border-b border-stone-200/60 pb-2 mb-2">Container Effects</h3>
                  
    <!-- Background Texture -->
    <div class="space-y-3">
      <div class="flex justify-between items-center">
        <label class="text-[10px] font-bold text-stone-500 uppercase tracking-wider">Background Texture</label>
        <div class="flex space-x-1 bg-stone-200/60 p-0.5 rounded-md">
          <button id="texture-chip-none" onpointerdown="SecurePress.start(event, () => updateBgTexture('none'))" class="px-2 py-1 text-[9px] font-bold uppercase rounded bg-white text-stone-800 shadow-sm">None</button>
          <button id="texture-chip-dots" onpointerdown="SecurePress.start(event, () => updateBgTexture('dots'))" class="px-2 py-1 text-[9px] font-bold uppercase rounded text-stone-500 hover:bg-stone-200/60">Dots</button>
          <button id="texture-chip-lines" onpointerdown="SecurePress.start(event, () => updateBgTexture('lines'))" class="px-2 py-1 text-[9px] font-bold uppercase rounded text-stone-500 hover:bg-stone-200/60">Lines</button>
          <button id="texture-chip-grid" onpointerdown="SecurePress.start(event, () => updateBgTexture('grid'))" class="px-2 py-1 text-[9px] font-bold uppercase rounded text-stone-500 hover:bg-stone-200/60">Grid</button>
        </div>
      </div>
      <div class="flex items-center space-x-3">
        <div class="flex justify-between w-16 text-[9px] font-bold text-stone-400"><span>Scale</span><span id="bg-texture-scale-label">20px</span></div>
        <input id="bg-texture-scale-slider" type="range" min="4" max="100" step="2" value="20" oninput="updateBgTextureScale(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
      </div>
      <div class="flex items-center space-x-3">
        <div class="flex justify-between w-16 text-[9px] font-bold text-stone-400"><span>Thick</span><span id="bg-texture-thickness-label">2px</span></div>
        <input id="bg-texture-thickness-slider" type="range" min="1" max="10" step="1" value="2" oninput="updateBgTextureThickness(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
      </div>
      <div class="flex items-center justify-between select-none pt-1">
        <label class="block text-[10px] font-bold text-stone-400 uppercase tracking-wider">Color</label>
        <button onclick="promptAddColor('bgTexture')" class="w-5 h-5 rounded bg-white border border-stone-200/60 hover:bg-stone-200 text-stone-600 flex items-center justify-center transition-all active:scale-90 shadow-sm">
          <i data-lucide="plus" class="w-3 h-3 stroke-[3]"></i>
        </button>
      </div>
      <div class="flex justify-between items-center bg-white p-1.5 rounded-lg border border-stone-200/60 select-none shadow-sm">
        <div class="flex space-x-2 overflow-x-auto no-scrollbar py-0.5 flex-1 pr-3" id="bg-texture-color-presets-container"></div>
        <div class="flex items-center border-l border-stone-100 pl-2 shrink-0">
          <span id="bg-texture-hex-color-label" class="text-[9px] font-mono font-medium text-stone-500">Auto</span>
        </div>
      </div>
    </div>

    <hr class="border-stone-200/60">

    <!-- Container Outline -->
    <div class="space-y-3">
      <div class="flex justify-between items-center">
        <label class="text-[10px] font-bold text-stone-500 uppercase tracking-wider">Container Outline</label>
        <div class="flex space-x-1 bg-stone-200/60 p-0.5 rounded-md">
          <button id="outline-style-none" onpointerdown="SecurePress.start(event, () => updateContOutlineStyle('none'))" class="px-2 py-1 text-[9px] font-bold uppercase rounded bg-white text-stone-800 shadow-sm">None</button>
          <button id="outline-style-solid" onpointerdown="SecurePress.start(event, () => updateContOutlineStyle('solid'))" class="px-2 py-1 text-[9px] font-bold uppercase rounded text-stone-500 hover:bg-stone-200/60">Solid</button>
          <button id="outline-style-double" onpointerdown="SecurePress.start(event, () => updateContOutlineStyle('double'))" class="px-2 py-1 text-[9px] font-bold uppercase rounded text-stone-500 hover:bg-stone-200/60">Double</button>
        </div>
      </div>
      <div class="flex items-center space-x-3">
        <span class="text-[10px] font-semibold text-stone-400 w-12">Width</span>
        <input id="cont-outline-width-slider" type="range" min="2" max="24" step="2" value="4" oninput="updateContOutlineWidth(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
      </div>
      <div class="flex items-center justify-between select-none pt-1">
        <label class="block text-[10px] font-bold text-stone-400 uppercase tracking-wider">Color</label>
        <button onclick="promptAddColor('contOutline')" class="w-5 h-5 rounded bg-white border border-stone-200/60 hover:bg-stone-200 text-stone-600 flex items-center justify-center transition-all active:scale-90 shadow-sm">
          <i data-lucide="plus" class="w-3 h-3 stroke-[3]"></i>
        </button>
      </div>
      <div class="flex justify-between items-center bg-white p-1.5 rounded-lg border border-stone-200/60 select-none shadow-sm">
        <div class="flex space-x-2 overflow-x-auto no-scrollbar py-0.5 flex-1 pr-3" id="cont-outline-color-presets-container"></div>
        <div class="flex items-center border-l border-stone-100 pl-2 shrink-0">
          <span id="cont-outline-hex-color-label" class="text-[9px] font-mono font-medium text-stone-500">#1C1917</span>
        </div>
      </div>
    </div>

    <hr class="border-stone-200/60">

    <!-- Container Drop Shadow -->
    <div class="space-y-3">
      <div class="flex justify-between items-center">
        <label class="text-[10px] font-bold text-stone-500 uppercase tracking-wider">Drop Shadow</label>
        <div class="flex space-x-1 bg-stone-200/60 p-0.5 rounded-md select-none">
          <button id="shadow-toggle-off" onpointerdown="SecurePress.start(event, () => toggleContShadow(false))" class="px-2.5 py-1 text-[9px] font-bold uppercase rounded bg-white text-stone-800 shadow-sm">Off</button>
          <button id="shadow-toggle-on" onpointerdown="SecurePress.start(event, () => toggleContShadow(true))" class="px-2.5 py-1 text-[9px] font-bold uppercase rounded text-stone-500 hover:bg-stone-200/60">On</button>
        </div>
      </div>
      <div class="flex items-center space-x-3">
        <div class="flex justify-between w-16 text-[9px] font-bold text-stone-400"><span>Dist</span><span id="cont-shadow-dist-label">0px</span></div>
        <input id="cont-shadow-dist-slider" type="range" min="0" max="40" step="2" value="0" oninput="updateContShadowDist(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
      </div>
      <div class="flex items-center space-x-3">
        <div class="flex justify-between w-16 text-[9px] font-bold text-stone-400"><span>Blur</span><span id="cont-shadow-blur-label">0px</span></div>
        <input id="cont-shadow-blur-slider" type="range" min="0" max="40" step="2" value="0" oninput="updateContShadowBlur(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
      </div>
      <div class="flex items-center space-x-3">
        <div class="flex justify-between w-16 text-[9px] font-bold text-stone-400"><span>Angle</span><span id="cont-shadow-angle-label">90&deg;</span></div>
        <input id="cont-shadow-angle-slider" type="range" min="0" max="360" step="15" value="90" oninput="updateContShadowAngle(this.value)" class="flex-1 accent-brand h-1 bg-stone-200 rounded-lg appearance-none cursor-pointer" />
      </div>
    </div>

  </div>

</div><button onclick="openApplyModal()" class="w-full py-3.5 mt-4 rounded-xl font-bold text-white bg-brand hover:bg-brand-dark active:scale-[0.98] transition-all flex items-center justify-center space-x-2 shadow-lg shadow-brand/20">
                  <i data-lucide="rocket" class="w-5 h-5 stroke-[2.5]"></i>
                  <span>Apply PWA Setup to App</span>
                </button>
              </div>

            </div>

          </div>

        </div>

      </section>

    </main>

  </div>

  <!-- Apply PWA Confirmation Modal -->
  <div id="apply-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-sm z-[100] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="apply-modal-panel">
      <div class="p-5 border-b border-stone-100">
        <div class="flex items-center space-x-3 mb-1">
          <div class="w-8 h-8 bg-brand-light text-brand rounded-full flex items-center justify-center">
            <i data-lucide="rocket" class="w-4 h-4 stroke-[2.5]"></i>
          </div>
          <h3 class="font-bold text-stone-900 text-lg">Apply PWA Setup</h3>
        </div>
        <p class="text-sm text-stone-500 ml-11">Ready to configure <span id="apply-app-name" class="font-semibold text-stone-700">App</span>.</p>
      </div>
      
      <div class="p-5 bg-stone-50 space-y-4">
        <div class="text-xs text-stone-600 space-y-2 font-mono bg-white p-3 rounded-xl border border-stone-200/60">
          <div class="flex items-start space-x-2">
            <i data-lucide="plus-circle" class="w-4 h-4 text-emerald-500 shrink-0"></i>
            <span>Create: <span class="text-stone-800" id="apply-path-icon">/apps/.../icon.svg</span></span>
          </div>
          <div class="flex items-start space-x-2">
            <i data-lucide="refresh-cw" class="w-4 h-4 text-amber-500 shrink-0"></i>
            <span>Update: <span class="text-stone-800">manifest.json</span></span>
          </div>
          <div class="flex items-start space-x-2">
            <i data-lucide="code" class="w-4 h-4 text-sky-500 shrink-0"></i>
            <span>Patch: <span class="text-stone-800">index.php</span> (Meta tags)</span>
          </div>
        </div>
        
        <div class="flex items-start space-x-2 text-xs text-stone-500 bg-emerald-50/50 p-3 rounded-xl border border-emerald-100">
          <i data-lucide="shield-check" class="w-4 h-4 text-emerald-600 shrink-0 mt-0.5"></i>
          <p><strong>Safety Guardrails Active:</strong> Original index.php will be backed up to .bak. Surgical injection uses strict comment boundaries.</p>
        </div>
      </div>
      
      <div class="p-4 bg-white flex space-x-3">
        <button onclick="closeApplyModal()" class="flex-1 py-2.5 rounded-xl font-semibold text-stone-600 bg-stone-100 hover:bg-stone-200/80 active:scale-95 transition-all">Cancel</button>
        <button id="btn-confirm-apply" onclick="commitPwaSetup()" class="flex-1 py-2.5 rounded-xl font-semibold text-white bg-brand hover:bg-brand-dark active:scale-95 transition-all flex items-center justify-center space-x-2">
          <i data-lucide="check" class="w-4 h-4 stroke-[2.5]"></i>
          <span>Confirm & Write</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Draft Action Modal -->
<div id="draft-action-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-sm z-[100] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="draft-action-modal-panel">
    <div class="p-5 border-b border-stone-100">
      <div class="flex items-center space-x-3 mb-1">
        <div class="w-8 h-8 bg-stone-100 text-stone-600 rounded-full flex items-center justify-center">
          <i data-lucide="layers" class="w-4 h-4 stroke-[2.5]"></i>
        </div>
        <h3 class="font-bold text-stone-900 text-lg">Variation Options</h3>
      </div>
      <p class="text-sm text-stone-500 ml-11">Save this variation as a global preset, or delete it permanently?</p>
    </div>
          
    <div class="p-4 bg-white flex flex-col space-y-2.5">
      <button id="btn-confirm-save-preset" onclick="commitSavePresetFromDraft()" class="w-full py-3 rounded-xl font-semibold text-white bg-brand hover:bg-brand-dark active:scale-95 transition-all flex items-center justify-center space-x-2 shadow-sm shadow-brand/20">
        <i data-lucide="globe" class="w-5 h-5 stroke-[2.5]"></i>
        <span>Save as Global Preset</span>
      </button>
      <button id="btn-confirm-delete" onclick="commitDeleteDraft()" class="w-full py-3 rounded-xl font-semibold text-rose-600 bg-rose-50 border border-rose-100 hover:bg-rose-100 active:scale-95 transition-all flex items-center justify-center space-x-2">
        <i data-lucide="trash-2" class="w-5 h-5 stroke-[2.5]"></i>
        <span>Delete Variation</span>
      </button>
      <button onclick="closeDraftActionModal()" class="w-full py-3 rounded-xl font-semibold text-stone-600 bg-stone-100 hover:bg-stone-200/80 active:scale-95 transition-all">Cancel</button>
    </div>
  </div>
</div>

<!-- Delete Preset Modal -->
<div id="delete-preset-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-sm z-[100] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="delete-preset-modal-panel">
    <div class="p-5 border-b border-stone-100">
      <div class="flex items-center space-x-3 mb-1">
        <div class="w-8 h-8 bg-rose-50 text-rose-600 rounded-full flex items-center justify-center">
          <i data-lucide="trash-2" class="w-4 h-4 stroke-[2.5]"></i>
        </div>
        <h3 class="font-bold text-stone-900 text-lg">Delete Preset</h3>
      </div>
      <p class="text-sm text-stone-500 ml-11">Are you sure you want to permanently delete this global preset?</p>
    </div>
          
    <div class="p-4 bg-white flex space-x-3">
      <button onclick="closePresetActionModal()" class="flex-1 py-2.5 rounded-xl font-semibold text-stone-600 bg-stone-100 hover:bg-stone-200/80 active:scale-95 transition-all">Cancel</button>
      <button id="btn-confirm-delete-preset" onclick="commitDeletePreset()" class="flex-1 py-2.5 rounded-xl font-semibold text-white bg-rose-600 hover:bg-rose-700 active:scale-95 transition-all flex items-center justify-center space-x-2">
        <i data-lucide="trash-2" class="w-4 h-4 stroke-[2.5]"></i>
        <span>Delete</span>
      </button>
    </div>
  </div>
</div>

<!-- Add Emoji Custom Input Modal -->
<div id="emoji-input-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-sm z-[100] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="emoji-input-modal-panel">
    <div class="p-5 border-b border-stone-100">
      <div class="flex items-center space-x-3 mb-1">
        <div class="w-8 h-8 bg-brand-light text-brand rounded-full flex items-center justify-center">
          <i data-lucide="smile" class="w-4 h-4 stroke-[2.5]"></i>
        </div>
        <h3 class="font-bold text-stone-900 text-lg">Add Custom Emoji</h3>
      </div>
      <p class="text-sm text-stone-500 ml-11">Type or paste any emoji to add to your quick-selector list.</p>
    </div>
          
    <div class="p-5 bg-stone-50 space-y-4">
      <div>
        <label class="block text-[10px] font-bold text-stone-400 uppercase tracking-widest mb-2">Emoji Character</label>
        <input type="text" id="custom-emoji-input" placeholder="🦄" 
               class="w-full px-4 py-3 bg-white border border-stone-200 rounded-xl font-bold text-xl text-center text-stone-700 outline-none focus:border-brand transition-all">
      </div>
    </div>
          
    <div class="p-4 bg-white flex space-x-3">
      <button onclick="closeEmojiInputModal()" class="flex-1 py-2.5 rounded-xl font-semibold text-stone-600 bg-stone-100 hover:bg-stone-200/80 active:scale-95 transition-all">Cancel</button>
      <button onclick="commitAddEmoji()" class="flex-1 py-2.5 rounded-xl font-semibold text-white bg-brand hover:bg-brand-dark active:scale-95 transition-all flex items-center justify-center space-x-2">
        <i data-lucide="check" class="w-4 h-4 stroke-[2.5]"></i>
        <span>Add Emoji</span>
      </button>
    </div>
  </div>
</div>

<!-- Add Color Custom Input Modal -->
<div id="color-input-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-sm z-[100] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="color-input-modal-panel">
    <div class="p-5 border-b border-stone-100">
      <div class="flex items-center space-x-3 mb-1">
        <div class="w-8 h-8 bg-brand-light text-brand rounded-full flex items-center justify-center">
          <i data-lucide="palette" class="w-4 h-4 stroke-[2.5]"></i>
        </div>
        <h3 class="font-bold text-stone-900 text-lg">Add Preset Color</h3>
      </div>
      <p class="text-sm text-stone-500 ml-11">Choose a custom color from the swatches, type a hex code, or open the system color wheel.</p>
    </div>
          
    <div class="p-5 bg-stone-50 space-y-4">
  <!-- Popular swatches spectrum -->
  <div>
    <label class="block text-[10px] font-bold text-stone-400 uppercase tracking-widest mb-2">Palette swatches</label>
    <div class="grid grid-cols-6 gap-2 bg-white p-3 rounded-xl border border-stone-200/60">
      <button onclick="selectSwatchInModal('#FFD166')" class="w-8 h-8 rounded-full bg-[#FFD166] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#06D6A0')" class="w-8 h-8 rounded-full bg-[#06D6A0] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#118AB2')" class="w-8 h-8 rounded-full bg-[#118AB2] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#EF476F')" class="w-8 h-8 rounded-full bg-[#EF476F] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#073B4C')" class="w-8 h-8 rounded-full bg-[#073B4C] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#8338EC')" class="w-8 h-8 rounded-full bg-[#8338EC] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#3A86C8')" class="w-8 h-8 rounded-full bg-[#3A86C8] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#FF5A5F')" class="w-8 h-8 rounded-full bg-[#FF5A5F] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#8AC926')" class="w-8 h-8 rounded-full bg-[#8AC926] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#14213D')" class="w-8 h-8 rounded-full bg-[#14213D] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#F72585')" class="w-8 h-8 rounded-full bg-[#F72585] border border-stone-200/20 active:scale-90 transition-all"></button>
      <button onclick="selectSwatchInModal('#7209B7')" class="w-8 h-8 rounded-full bg-[#7209B7] border border-stone-200/20 active:scale-90 transition-all"></button>
    </div>
  </div>

  <!-- Pure HTML5 Custom Color Wheel -->
  <div class="flex flex-col items-center justify-center py-2">
    <div class="relative w-48 h-48 flex items-center justify-center bg-stone-100 rounded-full border border-stone-200/20">
      <canvas id="color-wheel-canvas" width="180" height="180" class="rounded-full cursor-crosshair border border-stone-200/50 shadow-sm" style="touch-action: none;"></canvas>
      <div id="color-wheel-marker" class="absolute w-4 h-4 rounded-full border-2 border-white shadow-md pointer-events-none" style="display: none; transform: translate(-50%, -50%);"></div>
    </div>
  </div>

  <!-- Manual input -->
  <div>
    <label class="block text-[10px] font-bold text-stone-400 uppercase tracking-widest mb-2">HEX COLOR</label>
    <div class="relative flex items-center">
      <input type="text" id="custom-color-hex-input" placeholder="e.g. #FFD166" oninput="updateCustomColorDot(this.value)"
             class="w-full pl-4 pr-10 py-3 bg-white border border-stone-200 rounded-xl font-mono text-sm text-center text-stone-700 outline-none focus:border-brand transition-all">
      <div id="custom-color-dot" class="absolute right-3 w-5 h-5 rounded-full border border-stone-200/50 shadow-sm transition-colors duration-200" style="background-color: transparent;"></div>
    </div>
  </div>
</div><div class="p-4 bg-white flex space-x-3">
      <button onclick="closeColorInputModal()" class="flex-1 py-2.5 rounded-xl font-semibold text-stone-600 bg-stone-100 hover:bg-stone-200/80 active:scale-95 transition-all">Cancel</button>
      <button onclick="commitAddColor()" class="flex-1 py-2.5 rounded-xl font-semibold text-white bg-brand hover:bg-brand-dark active:scale-95 transition-all flex items-center justify-center space-x-2">
        <i data-lucide="check" class="w-4 h-4 stroke-[2.5]"></i>
        <span>Add Preset</span>
      </button>
    </div>
  </div>
</div>

<!-- Restore Backup Confirmation Modal --><div id="restore-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-sm z-[100] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="restore-modal-panel">
    <div class="p-5 border-b border-stone-100">
      <div class="flex items-center space-x-3 mb-1">
        <div class="w-8 h-8 bg-indigo-50 text-indigo-600 rounded-full flex items-center justify-center">
          <i data-lucide="rotate-ccw" class="w-4 h-4 stroke-[2.5]"></i>
        </div>
        <h3 class="font-bold text-stone-900 text-lg">Restore Original File</h3>
      </div>
      <p class="text-sm text-stone-500 ml-11">Would you like to restore <span id="restore-app-name" class="font-semibold text-stone-700">index.php</span> from the secure backup and revert meta tag configurations?</p>
    </div>
          
    <div class="p-4 bg-white flex space-x-3">
      <button onclick="closeRestoreModal()" class="flex-1 py-2.5 rounded-xl font-semibold text-stone-600 bg-stone-100 hover:bg-stone-200/80 active:scale-95 transition-all">Cancel</button>
      <button id="btn-confirm-restore" onclick="commitRestoreBackup()" class="flex-1 py-2.5 rounded-xl font-semibold text-white bg-indigo-600 hover:bg-indigo-700 active:scale-95 transition-all flex items-center justify-center space-x-2">
        <i data-lucide="check" class="w-4 h-4 stroke-[2.5]"></i>
        <span>Restore</span>
      </button>
    </div>
  </div>
</div>  <!-- Batch Drawer (Thin Vertical Bar) -->
  <div id="batch-drawer" class="fixed top-0 right-0 w-20 h-full bg-white/80 backdrop-blur-2xl border-l border-white/40 shadow-2xl z-[90] transform translate-x-full transition-transform duration-300 flex flex-col pt-safe md:absolute md:rounded-r-[28px]">
    <!-- Header -->
    <div class="p-4 flex items-center justify-center shrink-0 border-b border-stone-200/50">
      <button onclick="toggleBatchDrawer()" class="w-10 h-10 flex items-center justify-center rounded-xl bg-stone-100 text-stone-500 hover:bg-stone-200 active:scale-95 transition-all">
        <i data-lucide="chevron-right" class="w-5 h-5 stroke-[2.5]"></i>
      </button>
    </div>

    <!-- Preview Mode Controls -->
    <div id="batch-preview-controls" class="hidden flex-col items-center space-y-3 p-4 border-b border-stone-200/50 bg-brand-light/30">
      <button onclick="commitBatchApply()" id="btn-batch-apply" class="w-12 h-12 rounded-xl font-bold text-white bg-brand hover:bg-brand-dark active:scale-[0.98] transition-all flex items-center justify-center shadow-lg shadow-brand/20" title="Apply to All">
        <i data-lucide="check" class="w-6 h-6 stroke-[2.5]"></i>
      </button>
      <button onclick="cancelBatchPreview()" class="w-12 h-12 rounded-xl font-semibold text-rose-600 bg-white border border-rose-100 hover:bg-rose-50 active:scale-95 transition-all flex items-center justify-center" title="Cancel Preview">
        <i data-lucide="x" class="w-6 h-6 stroke-[2.5]"></i>
      </button>
    </div>

    <!-- Presets List -->
    <div class="flex-1 overflow-y-auto no-scrollbar py-4 flex flex-col items-center space-y-4">
      <div id="batch-presets-container" class="flex flex-col space-y-3 w-full items-center">
        <!-- Populated by JS -->
      </div>
    </div>

    <!-- Footer (Backups) -->
    <div class="p-4 flex items-center justify-center shrink-0 border-t border-stone-200/50">
      <button onclick="openBatchBackupsModal()" class="w-12 h-12 flex items-center justify-center rounded-xl bg-stone-100 text-stone-600 hover:bg-stone-200 active:scale-95 transition-all" title="Batch Backups">
        <i data-lucide="history" class="w-5 h-5 stroke-[2.5]"></i>
      </button>
    </div>
  </div>

  <!-- Batch Backups Modal -->
  <div id="batch-backups-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-sm z-[100] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="batch-backups-modal-panel">
      <div class="p-5 border-b border-stone-100 flex justify-between items-center">
        <div class="flex items-center space-x-3">
          <div class="w-8 h-8 bg-stone-100 text-stone-600 rounded-full flex items-center justify-center">
            <i data-lucide="history" class="w-4 h-4 stroke-[2.5]"></i>
          </div>
          <h3 class="font-bold text-stone-900 text-lg">Batch Backups</h3>
        </div>
        <button onclick="closeBatchBackupsModal()" class="w-8 h-8 flex items-center justify-center rounded-lg bg-stone-100 text-stone-500 hover:bg-stone-200 active:scale-95 transition-all">
          <i data-lucide="x" class="w-4 h-4 stroke-[2.5]"></i>
        </button>
      </div>
      <div class="p-5 bg-stone-50 max-h-64 overflow-y-auto no-scrollbar space-y-2" id="batch-backups-container">
        <!-- Populated by JS -->
      </div>
    </div>
  </div>

  <!-- Batch Restore Confirm Modal -->
  <div id="batch-restore-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-sm z-[110] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="batch-restore-modal-panel">
      <div class="p-5 border-b border-stone-100">
        <div class="flex items-center space-x-3 mb-1">
          <div class="w-8 h-8 bg-amber-50 text-amber-600 rounded-full flex items-center justify-center">
            <i data-lucide="alert-triangle" class="w-4 h-4 stroke-[2.5]"></i>
          </div>
          <h3 class="font-bold text-stone-900 text-lg">Apps Modified</h3>
        </div>
        <p class="text-sm text-stone-500 ml-11">Some apps have been manually modified since this batch backup was created.</p>
      </div>
      <div class="p-5 bg-stone-50 max-h-40 overflow-y-auto no-scrollbar">
        <ul id="batch-drifted-list" class="text-xs font-mono text-stone-600 space-y-1"></ul>
      </div>
      <div class="p-4 bg-white flex space-x-3">
        <button onclick="closeBatchRestoreModal()" class="flex-1 py-2.5 rounded-xl font-semibold text-stone-600 bg-stone-100 hover:bg-stone-200/80 active:scale-95 transition-all">Abort</button>
        <button id="btn-batch-restore-skip" class="flex-1 py-2.5 rounded-xl font-semibold text-white bg-amber-500 hover:bg-amber-600 active:scale-95 transition-all flex items-center justify-center space-x-2">
          <span>Skip Modified</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Settings Modal -->
  <div id="settings-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-sm z-[100] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="settings-modal-panel">
      <div class="p-5 border-b border-stone-100 flex justify-between items-center">
        <div class="flex items-center space-x-3">
          <div class="w-8 h-8 bg-stone-100 text-stone-600 rounded-full flex items-center justify-center">
            <i data-lucide="sliders" class="w-4 h-4 stroke-[2.5]"></i>
          </div>
          <h3 class="font-bold text-stone-900 text-lg">Settings</h3>
        </div>
        <button onclick="closeSettingsModal()" class="w-8 h-8 flex items-center justify-center rounded-lg bg-stone-100 text-stone-500 hover:bg-stone-200 active:scale-95 transition-all">
          <i data-lucide="x" class="w-4 h-4 stroke-[2.5]"></i>
        </button>
      </div>
      <div class="p-5 bg-stone-50 space-y-4">
        <div class="flex items-center justify-between bg-white p-4 rounded-xl border border-stone-200/60 shadow-sm">
          <div class="pr-4">
            <h4 class="text-sm font-bold text-stone-800">Secure Press</h4>
            <p class="text-[10px] text-stone-500 mt-1 leading-snug">Require a long-press on customizer options to prevent accidental taps while scrolling.</p>
          </div>
          <button id="toggle-secure-press-btn" onclick="toggleSecurePress()" class="relative w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none bg-emerald-500 shrink-0">
            <span id="toggle-secure-press-knob" class="absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform duration-200 transform translate-x-5"></span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Batch Backup Action Modal -->
  <div id="batch-action-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-sm z-[110] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden transform scale-95 transition-transform duration-300" id="batch-action-modal-panel">
      <div class="p-5 border-b border-stone-100">
        <div class="flex items-center space-x-3 mb-1">
          <div class="w-8 h-8 bg-stone-100 text-stone-600 rounded-full flex items-center justify-center">
            <i data-lucide="layers" class="w-4 h-4 stroke-[2.5]"></i>
          </div>
          <h3 class="font-bold text-stone-900 text-lg">Batch Options</h3>
        </div>
        <p class="text-sm text-stone-500 ml-11" id="batch-action-date"></p>
      </div>
      <div class="p-4 bg-white flex flex-col space-y-2.5">
        <button id="btn-batch-restore" class="w-full py-3 rounded-xl font-semibold text-white bg-indigo-600 hover:bg-indigo-700 active:scale-95 transition-all flex items-center justify-center space-x-2 shadow-sm shadow-indigo-600/20">
          <i data-lucide="rotate-ccw" class="w-5 h-5 stroke-[2.5]"></i>
          <span>Restore Batch</span>
        </button>
        <button id="btn-batch-delete" class="w-full py-3 rounded-xl font-semibold text-rose-600 bg-rose-50 border border-rose-100 hover:bg-rose-100 active:scale-95 transition-all flex items-center justify-center space-x-2">
          <i data-lucide="trash-2" class="w-5 h-5 stroke-[2.5]"></i>
          <span>Delete Backup</span>
        </button>
        <button onclick="closeBatchActionModal()" class="w-full py-3 rounded-xl font-semibold text-stone-600 bg-stone-100 hover:bg-stone-200/80 active:scale-95 transition-all">Cancel</button>
      </div>
    </div>
  </div>

<script src="js/app.js?v=<?php echo $v; ?>"></script>
</body>
</html>