<?php
/**
 * WHISPERLOG SHIELD
 * This file is the indestructible entry point of the app.
 * It sets up the Safety Net first, then includes the actual app logic.
 */
require_once 'app/paths.php';



/**
 * SHARED SHIELD UI TEMPLATE
 * Unifies the aesthetic for both Server-side and Client-side emergency states.
 */
function cjosRenderShield($title, $desc, $details, $is_js = false) {
    $css = '
        body.shield-active { background: #050505; color: #E5E5EA; font-family: -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; text-align: center; }
        .shield-box { max-width: 400px; width: 100%; padding: 40px 30px; border-radius: 28px; background: #121214; border: 1px solid #FF3B30; box-shadow: 0 20px 50px rgba(0,0,0,0.5); position: relative; overflow: hidden; box-sizing: border-box; }
        .shield-box::before { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: #FF3B30; opacity: 0.5; }
        .shield-title { color: #FF3B30; font-size: 14px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; margin-bottom: 15px; margin-top: 0; }
        .shield-desc { font-size: 15px; color: #8E8E93; line-height: 1.6; margin-bottom: 25px; }
        .shield-details { background: rgba(255,255,255,0.05); color: #E5E5EA; padding: 15px; border-radius: 12px; margin-bottom: 25px; text-align: left; font-family: monospace; font-size: 11px; border: 1px solid rgba(255,59,48,0.3); overflow-x: auto; line-height: 1.4; word-break: break-all; }
        .shield-btn-bunker { display: inline-block; width: 100%; padding: 16px; background: #FF3B30; color: white; text-decoration: none; border-radius: 16px; font-weight: 700; font-size: 14px; letter-spacing: 1px; transition: transform 0.2s; box-shadow: 0 10px 20px rgba(255, 59, 48, 0.2); box-sizing: border-box; }
        .shield-btn-bunker:active { transform: scale(0.98); }
        .shield-btn-patcher { display: inline-block; width: 100%; padding: 16px; background: #007AFF; color: white; text-decoration: none; border-radius: 16px; font-weight: 700; font-size: 14px; letter-spacing: 1px; transition: transform 0.2s; box-shadow: 0 10px 20px rgba(0, 122, 255, 0.2); box-sizing: border-box; margin-bottom: 12px; }
        .shield-btn-patcher:active { transform: scale(0.98); }
        .shield-copy-btn { position: absolute; top: 15px; right: 15px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #8E8E93; padding: 6px 10px; border-radius: 8px; font-size: 10px; font-weight: 700; cursor: pointer; transition: all 0.2s; z-index: 10; }
        .shield-copy-btn:hover { background: rgba(255,255,255,0.1); color: #E5E5EA; }
    ';

    $html = '
        <div class="shield-box">
            <button id="shield-copy-trigger" class="shield-copy-btn">COPY ERROR</button>
            <h1 class="shield-title">' . htmlspecialchars($title) . '</h1>
            <p class="shield-desc">' . htmlspecialchars($desc) . '</p>
            <div class="shield-details">' . $details . '</div>
            <a href="patcher.php" class="shield-btn-patcher">OPEN EMERGENCY PATCHER</a>
            <a href="recovery.php" class="shield-btn-bunker">OPEN RECOVERY BUNKER</a>
        </div>
    ';

    if ($is_js) return ['html' => $html, 'css' => $css];

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"><title>System Interrupted</title><style>' . $css . '</style></head><body class="shield-active">' . $html;
}

// --- MANUAL SHIELD TRIGGER ---
if (isset($_GET['shield'])) {
    echo cjosRenderShield(
        'System Shield', 
        'Manual access to recovery tools. Choose your path below.',
        '<div style="text-align:center;"><b style="color: #FF3B30;">MANUAL OVERRIDE:</b><br>No error detected. Maintenance mode active.</div>'
    );
    exit;
}

// --- EMERGENCY PHP SAFETY NET ---
register_shutdown_function(function() {
    $error = error_get_last();
    $output = ob_get_contents(); // Capture what has been buffered so far
    
    // 1. Detect API request (don't show HTML shield for JSON/Audio endpoints)
    $is_api = isset($_GET['action']) || isset($_POST['action']) || isset($_GET['plugin_action']) || isset($_POST['plugin_action']);
    
    // 2. Structural Integrity Check
    $failed_integrity = false;
    if (!$is_api && !empty($output)) {
        $has_header = strpos($output, 'top-bar') !== false;
        $plugins_loaded = strpos($output, '<!-- PLUGINS_LOADED -->') !== false;
        $has_content = strpos($output, 'entries-container') !== false;
        $has_footer = strpos($output, '</html>') !== false;
        
        if (!$has_header || !$plugins_loaded || !$has_content || !$has_footer) {
            $failed_integrity = true;
        }
    }

    // 3. Trigger Shield if Fatal Error OR Integrity Failure
    if ($failed_integrity || ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]))) {
        while (ob_get_level()) ob_end_clean();

        $details = '';
        if ($error) {
            $details = '<b style="color: #FF3B30;">ERROR:</b> ' . htmlspecialchars($error['message']) . '<br><br>' .
                       '<b style="color: #FF3B30;">FILE:</b> ' . htmlspecialchars(basename($error['file'])) . '<br>' .
                       '<b style="color: #FF3B30;">LINE:</b> ' . $error['line'];
        } elseif ($failed_integrity) {
            $details = '<div style="text-align:center;"><b style="color: #FF3B30;">INTEGRITY FAILURE:</b><br>The app finished executing but the UI structure is incomplete.</div>';
        }

        echo cjosRenderShield(
            'System Interrupted', 
            'A syntax error, plugin conflict, or incomplete load prevented the app from rendering correctly. Your data remains safe.',
            $details
        );
        ?>
            <script>
                document.getElementById('shield-copy-trigger').onclick = () => {
                    const text = document.querySelector('.shield-details').innerText.trim();
                    navigator.clipboard.writeText("```\n" + text + "\n```");
                    const btn = document.getElementById('shield-copy-trigger');
                    btn.innerText = 'COPIED!';
                    setTimeout(() => btn.innerText = 'COPY ERROR', 2000);
                };


            </script>
        </body></html>
        <?php
    }
});

// Include the actual application logic with output verification
ob_start();
include CJOS_PATH_APP . '/app.php';
$final_output = ob_get_clean();

// --- CLIENT-SIDE INTEGRITY WATCHDOG ---
$js_shield = cjosRenderShield(
    'JS Initialization Failed',
    "The app's interface (SharedUI) failed to load. This usually indicates a plugin syntax error or a conflict in the generated JavaScript.",
    '<b style="color:#FF3B30;">STATUS:</b> window.sui is undefined<br><b style="color:#FF3B30;">ADVICE:</b> Check for missing semicolons or NOWDOC escaping errors in recent patches.',
    true
);

$watchdog = '<script>
(function() {
    const checkIntegrity = () => {
        if (window.location.search.includes("action=") || window.location.search.includes("plugin_action=") || document.getElementById("auth-gate")) return;
        if (typeof window.sui !== "undefined" && typeof window.cjosPluginRegistry !== "undefined") return;

        console.error("WHISPERLOG SHIELD: JS Integrity Failure Detected.");
        
        const style = document.createElement("style");
        style.innerHTML = ' . json_encode($js_shield['css']) . ';
        document.head.appendChild(style);

        const shield = document.createElement("div");
        shield.style.cssText = "position:fixed; top:0; left:0; width:100%; height:100%; background:#050505; display:flex; align-items:center; justify-content:center; z-index:999999; text-align:center; padding:20px; box-sizing:border-box;";
        shield.className = "shield-active";
        shield.innerHTML = ' . json_encode($js_shield['html']) . ';
        document.body.appendChild(shield);

        document.getElementById("shield-copy-trigger").onclick = () => {
            const text = shield.querySelector(".shield-details").innerText.trim();
            navigator.clipboard.writeText("```\n" + text + "\n```");
            const btn = document.getElementById("shield-copy-trigger");
            btn.innerText = "COPIED!";
            setTimeout(() => btn.innerText = "COPY ERROR", 2000);
        };


    };
    setTimeout(checkIntegrity, 2000);
})();
</script>';echo str_replace('</body>', $watchdog . '</body>', $final_output);
?>