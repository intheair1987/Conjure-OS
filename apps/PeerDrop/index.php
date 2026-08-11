<?php
/**
 * PeerDrop - P2P File Transfer via WebRTC & QR
 * Signaling is handled via local SQLite polling.
 */

function get_asset_hash($files) {
    $combined = '';
    foreach ($files as $f) {
        $path = __DIR__ . '/' . $f;
        if (file_exists($path)) $combined .= md5_file($path);
    }
    return $combined ? substr(md5($combined), 0, 8) : 'dev';
}
$v = get_asset_hash(['css/style.css', 'js/app.js']);
$room_id = $_GET['room'] ?? '';

// Handle Ephemeral Public Receiver Publishing (Multi-Tier Self-Destruct API Chain)
if (isset($_GET['action']) && $_GET['action'] === 'publish_litterbox') {
    header('Content-Type: application/json');
    $room = preg_replace('/[^a-zA-Z0-9\-_]/', '', $_GET['room'] ?? '');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Inspect whether current host is a local IP or localhost
    $is_local = false;
    $clean_host = explode(':', $host)[0];
    if ($clean_host === 'localhost' || $clean_host === '127.0.0.1' || $clean_host === '::1') {
        $is_local = true;
    } elseif (filter_var($clean_host, FILTER_VALIDATE_IP)) {
        if (preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $clean_host)) {
            $is_local = true;
        }
    }
    
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/apps/PeerDrop/';
    $default_url = "{$scheme}://{$host}{$path}?room={$room}";
    
    if (!$is_local) {
        // VPS / Public Domain Mode: Use current public domain directly
        echo json_encode(['status' => 'success', 'public_url' => $default_url, 'is_tunneled' => false, 'mode' => 'public_vps']);
        exit;
    }
    
    // Generate standalone self-contained receiver HTML bundle for public CDN hosting
    $css_content = file_exists(__DIR__ . '/css/style.css') ? file_get_contents(__DIR__ . '/css/style.css') : '';
    $js_content = file_exists(__DIR__ . '/js/app.js') ? file_get_contents(__DIR__ . '/js/app.js') : '';
    
    $bundle_html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
    <title>PeerDrop Conduit</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
    {$css_content}
    </style>
    <script>
        window.PEERDROP_ROOM_ID = "{$room}";
        // Multi-CDN Resilient Script Loader (Protects cellular WebKit from CDN script drops)
        (function() {
            var qrCdns = [
                "https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js",
                "https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"
            ];
            var peerCdns = [
                "https://unpkg.com/peerjs@1.5.2/dist/peerjs.min.js",
                "https://cdnjs.cloudflare.com/ajax/libs/peerjs/1.5.2/peerjs.min.js",
                "https://cdn.jsdelivr.net/npm/peerjs@1.5.2/dist/peerjs.min.js"
            ];
            function loadNext(list, cb) {
                var idx = 0;
                function run() {
                    if (idx >= list.length) return cb && cb(false);
                    var s = document.createElement('script');
                    s.src = list[idx++];
                    s.onload = function() { cb && cb(true); };
                    s.onerror = run;
                    document.head.appendChild(s);
                }
                run();
            }
            window._loadPeerDropLibs = function(done) {
                loadNext(qrCdns, function() {
                    loadNext(peerCdns, function() {
                        if (done) done();
                    });
                });
            };
        })();
    </script>
</head>
<body>
    <div id="bg-glow" style="position:fixed; top:-50px; left:50%; transform:translateX(-50%); width:350px; height:350px; background:radial-gradient(circle, rgba(0,122,255,0.08) 0%, transparent 70%); filter:blur(45px); pointer-events:none; z-index:-1;"></div>
    <div id="app">
        <header>
            <div class="logo">🚀 PeerDrop <span class="version-chip">Relay</span></div>
            <div style="display:flex; gap:8px;">
                <button id="btn-console" onclick="pd.toggleConsole()" style="background:none; border: 1px solid var(--primary-accent); color: var(--primary-accent); padding: 6px 12px; border-radius: 10px; font-size: 12px; font-weight: 600; cursor:pointer;">Logs</button>
                <button id="btn-reset" onclick="location.reload()">Reset</button>
            </div>
        </header>
        <main id="main-content">
            <div id="view-conduit" class="view active">
                <div class="card conduit-card">
                    <div class="hero" style="margin-bottom:12px;">
                        <h1 style="font-size:28px; margin-bottom:4px;">PeerDrop Conduit</h1>
                        <p style="font-size:13px; margin-bottom:12px; color:var(--text-secondary);">Two-way encrypted P2P transfer bridge.</p>
                    </div>
                    <div id="conduit-status" class="status-pill">Connecting to partner...</div>
                    <div id="qr-section" style="margin-top:16px; width:100%; display:flex; flex-direction:column; align-items:center;">
                        <div id="qrcode-container"></div>
                        <p class="hint" id="qr-hint" style="margin-top:8px;">Scan or share link to connect partner device.</p>
                        <button onclick="pd.copyRoomLink()" class="btn-secondary" style="margin-top:12px; font-size:12px; padding:10px 20px;">📋 Copy Conduit Link</button>
                    </div>
                    <div id="file-management-section" style="display:none; width:100%; flex-direction:column; align-items:center;">
                        <label class="drop-zone" id="drop-zone" style="margin-top:16px; width:100%; max-width:280px;">
                            <input type="file" id="file-input" hidden multiple>
                            <div class="drop-icon">📁</div>
                            <span id="drop-zone-text">Select or Drop Files to Send</span>
                        </label>
                        <div id="progress-section" style="display:none; width:100%; margin-top:16px;">
                            <div class="progress-wrap">
                                <div id="progress-bar" class="progress-fill" style="width: 0%"></div>
                            </div>
                            <div id="transfer-meta" class="transfer-meta">Transferring...</div>
                        </div>
                        <div id="ledger-section" style="width:100%; margin-top:20px; text-align:left;">
                            <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Live Conduit Activity</div>
                            <div id="activity-ledger" class="activity-ledger">
                                <div class="ledger-empty">No active transfers yet. Drop a file to send.</div>
                            </div>
                        </div>
                        <div class="conduit-actions" style="display:flex; gap:10px; width:100%; margin-top:20px;">
                            <button onclick="pd.copyRoomLink()" class="btn-secondary" style="flex:1; font-size:12px; padding:12px;">📋 Copy Link</button>
                            <button onclick="pd.disconnectConduit()" class="btn-danger" style="flex:1; font-size:12px; padding:12px;">🔴 Disconnect</button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <div id="toast" class="toast"></div>
    </div>
    <div id="console-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); z-index:2000; align-items:center; justify-content:center; padding:20px;">
        <div class="card" style="max-width:420px; width:100%; height:380px; display:flex; flex-direction:column; justify-content:space-between; padding:20px; box-shadow:0 20px 40px rgba(0,0,0,0.3); overflow:hidden;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid rgba(142,142,147,0.15); padding-bottom:8px; margin-bottom:12px;">
                <h3 style="margin:0; font-size:16px; font-weight:800;">Telemetry Log</h3>
                <div style="display:flex; gap:10px;">
                    <button onclick="pd.copyConsole()" style="background:none; border:none; color:var(--primary-accent); font-size:12px; font-weight:600; cursor:pointer;">📋 Copy</button>
                    <button onclick="pd.clearConsole()" style="background:none; border:none; color:var(--text-secondary); font-size:12px; font-weight:600; cursor:pointer;">Clear</button>
                </div>
            </div>
            <div id="console-log" style="flex:1; overflow-y:auto; font-family:monospace; font-size:10px; text-align:left; background:rgba(0,0,0,0.25); padding:10px; border-radius:10px; display:flex; flex-direction:column; gap:4px; max-height:220px;"></div>
            <button onclick="pd.toggleConsole()" class="btn-secondary" style="margin-top:12px; width:100%; padding:10px; font-size:12px;">Close</button>
        </div>
    </div>
    <script>
    {$js_content}
    </script>
</body>
</html>
HTML;

    // Stage temporary receiver bundle file
    $tmp_file = sys_get_temp_dir() . '/pd_rec_' . uniqid() . '.html';
    file_put_contents($tmp_file, $bundle_html);
    
    $public_url = null;

    if (function_exists('curl_init')) {
        // High-Reliability Ephemeral Host Pipeline (Litterbox Primary -> Retry -> tmpfiles -> 0x0.st)
        $fetch_litterbox = function($file) {
            $ch = curl_init('https://litterbox.catbox.moe/resources/internals/api.php');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                CURLOPT_POSTFIELDS => [
                    'reqtype' => 'fileupload',
                    'time' => '1h',
                    'fileToUpload' => new CURLFile($file, 'text/html', 'peerdrop.html')
                ]
            ]);
            $res = trim((string)curl_exec($ch));
            curl_close($ch);
            return filter_var($res, FILTER_VALIDATE_URL) ? $res : null;
        };

        $debug_logs = [];

        $fetch_litterbox = function($file) use (&$debug_logs) {
            $ch = curl_init('https://litterbox.catbox.moe/resources/internals/api.php');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                CURLOPT_POSTFIELDS => [
                    'reqtype' => 'fileupload',
                    'time' => '1h',
                    'fileToUpload' => new CURLFile($file, 'text/html', 'peerdrop.html')
                ]
            ]);
            $res = trim((string)curl_exec($ch));
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $snippet = substr(trim(strip_tags($res)), 0, 120);
            $debug_logs[] = "[litterbox] HTTP {$code} | Err: " . ($err ?: 'None') . " | Resp: " . ($snippet ?: 'Empty');

            return filter_var($res, FILTER_VALIDATE_URL) ? $res : null;
        };

        // Execution Chain: Pure Litterbox Engine with Fast 5s Timeout + Instant Retry
        $public_url = $fetch_litterbox($tmp_file);

        if (!$public_url) {
            usleep(200000); // 200ms backoff retry
            $public_url = $fetch_litterbox($tmp_file);
        }
    }

    @unlink($tmp_file);

    if ($public_url) {
        echo json_encode([
            'status' => 'success', 
            'public_url' => $public_url, 
            'is_tunneled' => true, 
            'mode' => 'ephemeral_public',
            'debug_logs' => $debug_logs
        ]);
    } else {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Failed to publish public receiver bundle to ephemeral hosts.',
            'debug_logs' => $debug_logs
        ]);
    }
    exit;
}

// Master PeerDrop Route - Unified single application template for all participants
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no, viewport-fit=cover">
    <meta name="description" content="Secure, peer-to-peer file transfer. Share files directly between browsers using WebRTC and QR codes—private, encrypted, with no applications or setup required.">
    <title>PeerDrop</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Lora:ital,wght@0,400;0,500;0,600;1,400;1,500&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo $v; ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://unpkg.com/peerjs@1.5.2/dist/peerjs.min.js"></script>
  <!-- CONJURE_PWA_START -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="PeerDrop">
  <meta name="theme-color" content="#FFF1F2">
  <link rel="apple-touch-icon" href="icon.svg?v=1785513779">
  <link rel="icon" type="image/svg+xml" href="icon.svg?v=1785513779">
  <link rel="manifest" href="manifest.json?v=1785513779">
  <!-- CONJURE_PWA_END -->
  <script>
      window.PEERDROP_ROOM_ID = "<?php echo htmlspecialchars($_GET['room'] ?? ''); ?>";
  </script>
</head>
<body>
    <!-- Radial Background Glow (Consistent with LaunchSite Style) -->
    <div id="bg-glow" style="position:fixed; top:-50px; left:50%; transform:translateX(-50%); width:350px; height:350px; background:radial-gradient(circle, rgba(0,122,255,0.08) 0%, transparent 70%); filter:blur(45px); pointer-events:none; z-index:-1;"></div>

    <div id="app">
        <header>
            <div class="logo">🚀 PeerDrop <span class="version-chip"><?php echo $v; ?></span></div>
            <div style="display:flex; gap:8px;">
                <button id="btn-console" onclick="pd.toggleConsole()" style="background:none; border: 1px solid var(--primary-accent); color: var(--primary-accent); padding: 6px 12px; border-radius: 10px; font-size: 12px; font-weight: 600; cursor:pointer;">Logs</button>
                <button id="btn-reset" onclick="location.reload()">Reset</button>
            </div>
        </header>

        <main id="main-content">
            <div id="view-conduit" class="view active">
                <div class="card conduit-card">
                    <div class="hero" style="margin-bottom:12px;">
                        <h1 style="font-size:28px; margin-bottom:4px;">PeerDrop Conduit</h1>
                        <p style="font-size:13px; margin-bottom:12px; color:var(--text-secondary);">Two-way encrypted P2P transfer bridge.</p>
                    </div>

                    <div id="conduit-status" class="status-pill">Connecting to relay...</div>

                    <!-- State 1: Unconnected Room (QR Code & Copy Link ONLY) -->
                    <div id="qr-section" style="margin-top:16px; width:100%; display:flex; flex-direction:column; align-items:center;">
                        <div id="qrcode-container"></div>
                        <p class="hint" id="qr-hint" style="margin-top:8px;">Scan or share link to connect partner device.</p>
                        <button onclick="pd.copyRoomLink()" class="btn-secondary" style="margin-top:12px; font-size:12px; padding:10px 20px;">📋 Copy Conduit Link</button>
                    </div>

                    <!-- Expandable Telemetry Console (Collapsed by Default) -->
                    <details id="telemetry-accordion" style="width:100%; margin-top:16px; border:1px solid rgba(255,255,255,0.12); border-radius:12px; background:rgba(0,0,0,0.25); text-align:left;">
                        <summary style="padding:10px 14px; font-size:12px; font-weight:700; cursor:pointer; color:var(--text-secondary); display:flex; justify-content:space-between; align-items:center; user-select:none;">
                            <span>🛠️ Telemetry & Debug Console</span>
                            <span id="telemetry-badge" style="font-size:10px; padding:2px 8px; border-radius:10px; background:rgba(255,255,255,0.08); font-family:monospace;">IDLE</span>
                        </summary>
                        <div style="padding:12px; border-top:1px solid rgba(255,255,255,0.08); background:rgba(0,0,0,0.35);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <span style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Live Execution Log</span>
                                <div style="display:flex; gap:8px;">
                                    <button onclick="pd.copyConsole()" style="background:none; border:none; color:var(--primary-accent); font-size:11px; font-weight:600; cursor:pointer;">📋 Copy Log</button>
                                    <button onclick="pd.clearConsole()" style="background:none; border:none; color:var(--text-secondary); font-size:11px; font-weight:600; cursor:pointer;">Clear</button>
                                </div>
                            </div>
                            <div id="inline-console-log" style="font-family:monospace; font-size:11px; text-align:left; background:rgba(0,0,0,0.4); padding:10px; border-radius:8px; display:flex; flex-direction:column; gap:4px; max-height:200px; overflow-y:auto; word-break:break-all;">
                                <div style="opacity:0.5;">[System] Console initialized...</div>
                            </div>
                        </div>
                    </details>

                    <!-- State 2: Connected Room File Management (Hidden until partner connects) -->
                    <div id="file-management-section" style="display:none; width:100%; flex-direction:column; align-items:center;">
                        <label class="drop-zone" id="drop-zone" style="margin-top:16px; width:100%; max-width:280px;">
                            <input type="file" id="file-input" hidden multiple>
                            <div class="drop-icon">📁</div>
                            <span id="drop-zone-text">Select or Drop Files to Send</span>
                        </label>

                        <div id="progress-section" style="display:none; width:100%; margin-top:16px;">
                            <div class="progress-wrap">
                                <div id="progress-bar" class="progress-fill" style="width: 0%"></div>
                            </div>
                            <div id="transfer-meta" class="transfer-meta">Transferring...</div>
                        </div>

                        <!-- Live Activity Stream -->
                        <div id="ledger-section" style="width:100%; margin-top:20px; text-align:left;">
                            <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Live Conduit Activity</div>
                            <div id="activity-ledger" class="activity-ledger">
                                <div class="ledger-empty">No active transfers yet. Drop a file to send.</div>
                            </div>
                        </div>

                        <div class="conduit-actions" style="display:flex; gap:10px; width:100%; margin-top:20px;">
                            <button onclick="pd.copyRoomLink()" class="btn-secondary" style="flex:1; font-size:12px; padding:12px;">📋 Copy Link</button>
                            <button onclick="pd.disconnectConduit()" class="btn-danger" style="flex:1; font-size:12px; padding:12px;">🔴 Disconnect</button>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <div id="toast" class="toast"></div>
    </div>

    <!-- Incoming File Transfer Confirmation Modal -->
    <div id="transfer-request-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); z-index:2500; align-items:center; justify-content:center; padding:20px;">
        <div class="card" style="max-width:360px; width:100%; padding:24px; text-align:center; box-shadow:0 20px 50px rgba(0,0,0,0.5);">
            <div style="font-size:36px; margin-bottom:12px;">📁</div>
            <h3 style="margin:0 0 6px 0; font-size:18px; font-weight:800;">Incoming File Request</h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:16px;">Partner wants to send you a file:</p>
            
            <div style="background:rgba(255,255,255,0.06); padding:12px; border-radius:12px; margin-bottom:20px; border:1px solid rgba(255,255,255,0.1); word-break:break-all;">
                <div id="req-file-name" style="font-weight:700; font-size:14px; margin-bottom:4px; color:var(--text-primary);">filename.pdf</div>
                <div id="req-file-size" style="font-size:12px; color:var(--text-secondary);">0.0 MB</div>
            </div>

            <div style="display:flex; gap:12px;">
                <button onclick="pd.rejectIncomingTransfer()" class="btn-danger" style="flex:1; padding:12px; font-size:13px; font-weight:600;">Reject</button>
                <button onclick="pd.acceptIncomingTransfer()" class="btn-secondary" style="flex:1; padding:12px; font-size:13px; font-weight:700; background:var(--primary-accent); color:#fff; border:none;">Accept</button>
            </div>
        </div>
    </div>

    <!-- Telemetry Console Modal -->
    <div id="console-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); z-index:2000; align-items:center; justify-content:center; padding:20px;">
        <div class="card" style="max-width:420px; width:100%; height:380px; display:flex; flex-direction:column; justify-content:space-between; padding:20px; box-shadow:0 20px 40px rgba(0,0,0,0.3); overflow:hidden;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid rgba(142,142,147,0.15); padding-bottom:8px; margin-bottom:12px;">
                <h3 style="margin:0; font-size:16px; font-weight:800;">Telemetry Log</h3>
                <button onclick="pd.clearConsole()" style="background:none; border:none; color:var(--text-secondary); font-size:12px; font-weight:600; cursor:pointer;">Clear</button>
            </div>
            <div id="console-log" style="flex:1; overflow-y:auto; font-family:monospace; font-size:10px; text-align:left; background:rgba(0,0,0,0.25); padding:10px; border-radius:10px; display:flex; flex-direction:column; gap:4px; max-height:220px;">
                <!-- Injected logs -->
            </div>
            <button onclick="pd.toggleConsole()" class="btn-secondary" style="margin-top:12px; width:100%; padding:10px; font-size:12px;">Close</button>
        </div>
    </div>

    <script src="js/app.js?v=<?php echo $v; ?>"></script>
</body>
</html>