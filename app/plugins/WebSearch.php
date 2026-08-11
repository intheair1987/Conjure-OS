<?php
// ==============================================================================
// PLUGIN: Web Search
// DESCRIPTION: Multi-modality Search Lab.
// Features: Portable Studio, Debug Console, Private API Key storage.
// ==============================================================================

$ws_config_file = CJOS_PATH_DATA . '/web-search-config.json';
$ws_private_file = CJOS_PATH_DATA . '/web-search-private.json';

// --- DATA BRIDGE ---
$ws_conf_init = file_exists($ws_config_file) ? json_decode(file_get_contents($ws_config_file), true) : [];
$ws_priv_init = file_exists($ws_private_file) ? json_decode(file_get_contents($ws_private_file), true) : [];
$ws_bridge = json_encode(['config' => $ws_conf_init, 'private' => $ws_priv_init]);
$plugin_js .= "\nwindow.__WS_BRIDGE__ = $ws_bridge;\n";

// --- BACKEND HANDLERS ---
if (isset($_POST['plugin_action'])) {
    
    if ($_POST['plugin_action'] === 'ws_get_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $defaults = [
            'provider' => 'ddg', 
            'searx_instance' => 'https://searx.work', 
            'max_results' => 5,
            'proxy_mode' => 'none',
            'proxy_list' => '',
            'rotate_ua' => true,
            'auto_extract' => true
        ];
        $conf = file_exists($ws_config_file) ? json_decode(file_get_contents($ws_config_file), true) : $defaults;
        if (!isset($conf['max_results'])) $conf['max_results'] = 5;
        
        $priv_defaults = ['brave_key' => '', 'tavily_key' => '', 'bridge_url' => ''];
        $priv = file_exists($ws_private_file) ? json_decode(file_get_contents($ws_private_file), true) : $priv_defaults;
        
        echo json_encode(['status' => 'success', 'config' => $conf, 'private' => $priv]);
        exit;
    }

    if ($_POST['plugin_action'] === 'ws_save_config') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $conf = [
            'provider' => $_POST['provider'], 
            'searx_instance' => $_POST['searx_instance'],
            'max_results' => (int)$_POST['max_results'],
            'proxy_mode' => $_POST['proxy_mode'],
            'proxy_list' => $_POST['proxy_list'],
            'rotate_ua' => ($_POST['rotate_ua'] === 'true'),
            'auto_extract' => ($_POST['auto_extract'] === 'true')
        ];
        $priv = [
            'brave_key' => $_POST['brave_key'], 
            'tavily_key' => $_POST['tavily_key'],
            'bridge_url' => $_POST['bridge_url']
        ];
        
        file_put_contents($ws_config_file, json_encode($conf, JSON_PRETTY_PRINT));
        file_put_contents($ws_private_file, json_encode($priv, JSON_PRETTY_PRINT));
        
        echo json_encode(['status' => 'success']);
        exit;
    }

// --- HELPER: CONTENT EXTRACTOR ---
function ws_extract_content($html, $anchor = '') {
    if (empty($html)) return "";

    // 1. Pre-clean: Aggressively remove content of non-display tags
    $html = preg_replace('/<(script|style|svg|textarea|noscript|head|footer|nav|header|aside|iframe|form|button)[^>]*>.*?<\/\1>/is', '', $html);
    
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    
    // 2. Structural Spacing: Insert newlines after block elements to preserve paragraph boundaries
    $blockTags = ['p', 'div', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'tr', 'article', 'section'];
    foreach ($blockTags as $tag) {
        foreach ($dom->getElementsByTagName($tag) as $node) {
            $node->appendChild($dom->createTextNode("\n\n"));
        }
    }

    // 3. Extract Text
    $extractedText = $dom->textContent;
    if (empty(trim($extractedText))) $extractedText = strip_tags($html);

    // 4. Normalize whitespace and layout
    $text = str_replace(["\r", "\t"], ["\n", " "], $extractedText);
    $lines = explode("\n", $text);
    $cleanLines = [];
    foreach ($lines as $line) {
        $line = trim(str_replace("\xC2\xA0", " ", $line));
        if ($line !== '') {
            $line = preg_replace('/[ ]+/', ' ', $line);
            $cleanLines[] = $line;
        }
    }
    $finalText = implode("\n\n", $cleanLines);

    // 5. Anchor Logic: Find the snippet and slice content from that point
    if (!empty($anchor)) {
        $cleanAnchor = trim(str_replace(['...', '…'], ' ', $anchor));
        $pos = false;

        // Robust Anchor Match: Use regex to ignore whitespace/punctuation differences
        $normAnchor = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $cleanAnchor);
        $words = array_filter(explode(' ', $normAnchor), function($w) { return mb_strlen($w) > 2; });
        
        if (!empty($words)) {
            // Try 4 words for precision, fallback to 2
            foreach ([4, 2] as $count) {
                if (count($words) < $count) continue;
                $searchWords = array_slice($words, 0, $count);
                $regex = '/' . implode('[^\p{L}\p{N}]+', array_map('preg_quote', $searchWords)) . '/ui';
                if (preg_match($regex, $finalText, $matches, PREG_OFFSET_CAPTURE)) {
                    $pos = $matches[0][1];
                    break;
                }
            }
        }

        if ($pos !== false) {
            // Slice from the anchor point, providing a little leading context (start of paragraph)
            $lookback = mb_strrpos(mb_substr($finalText, 0, $pos), "\n\n");
            $start = ($lookback === false) ? 0 : $lookback + 2;
            $finalText = mb_substr($finalText, $start);
        }
    }

    $finalText = mb_strimwidth($finalText, 0, 8000, "... [Truncated]");

    // --- 6. VERIFICATION: Anti-Garbage Check ---
    $garbageSignals = ['cloudflare', 'enable javascript', 'enable cookies', 'access denied', '403 forbidden', 'checking your browser'];
    $checkText = mb_strtolower(mb_strimwidth($finalText, 0, 500));
    foreach ($garbageSignals as $signal) {
        if (strpos($checkText, $signal) !== false) return "";
    }

    if (!empty($anchor)) {
        $anchorKeywords = array_unique(array_filter(preg_split('/[^a-zA-Z0-9\x{4e00}-\x{9fa5}]+/u', mb_strtolower($anchor)), function($k) {
            return mb_strlen($k) > 3;
        }));

        if (!empty($anchorKeywords)) {
            $matchCount = 0;
            $lowerText = mb_strtolower($finalText);
            foreach ($anchorKeywords as $kw) {
                if (strpos($lowerText, $kw) !== false) $matchCount++;
            }
            $ratio = $matchCount / count($anchorKeywords);
            if ($matchCount < 2 && $ratio < 0.3) {
                if (!(count($anchorKeywords) <= 2 && $matchCount > 0)) return ""; 
            }
        }
    }

    return $finalText;
}

    if ($_POST['plugin_action'] === 'ws_fetch_url') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $target = $_POST['url'];
    $anchor = $_POST['anchor'] ?? '';

    // --- URL UNWRAPPER ---
    if (strpos($target, 'duckduckgo.com/l/') !== false) {
        $query = parse_url($target, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $params);
            if (!empty($params['uddg'])) $target = $params['uddg'];
        }
    }
    if (strpos($target, '//') === 0) $target = 'https:' . $target;

    $conf = file_exists($ws_config_file) ? json_decode(file_get_contents($ws_config_file), true) : [];
    $priv = file_exists($ws_private_file) ? json_decode(file_get_contents($ws_private_file), true) : [];
        
    $url = $target;
    $debug = ['bridge_active' => false];
    $bridgeBase = $priv['bridge_url'] ?? '';
        
    if (!empty($conf['proxy_mode']) && $conf['proxy_mode'] === 'bridge' && !empty($bridgeBase)) {
        if (strpos($bridgeBase, '{{URL}}') !== false) {
            $url = str_replace('{{URL}}', urlencode($target), $bridgeBase);
        } else {
            $url = rtrim($bridgeBase, '/') . '/?url=' . urlencode($target);
        }
        $debug['bridge_active'] = true;
        $debug['bridge_url'] = $url;
    }try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headerText = substr($response, 0, $headerSize);
            $html = substr($response, $headerSize);
            curl_close($ch);

            if (preg_match('/CF-RAY: ([^\r\n]+)/i', $headerText, $m)) $debug['cf_ray'] = $m[1];
            if (preg_match('/X-Conjure-Status: ([^\r\n]+)/i', $headerText, $m)) $debug['bridge_status'] = $m[1];

            if ($httpCode >= 400) {
                $msg = ($httpCode == 500 && !empty($html)) ? $html : "Server returned error $httpCode";
                throw new Exception($msg);
            }

            $text = ws_extract_content($html, $anchor);
            $method = (strpos($text, $anchor) !== false) ? "anchor" : "density";

            echo json_encode([
                'status' => 'success', 
                'content' => $text, 
                'method' => $method,
                'raw_size' => strlen($html),
                'debug' => $debug
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error', 
                'message' => $e->getMessage(),
                'debug' => $debug
            ]);
        }
        exit;
    }

if (!function_exists('ws_perform_search_internal')) {
    function ws_perform_search_internal($query, $provider, $isDeep = false, $autoExtract = false) {
        $ws_config_file = CJOS_PATH_DATA . '/web-search-config.json';
        $ws_private_file = CJOS_PATH_DATA . '/web-search-private.json';
        $priv = file_exists($ws_private_file) ? json_decode(file_get_contents($ws_private_file), true) : [];
        $conf = file_exists($ws_config_file) ? json_decode(file_get_contents($ws_config_file), true) : [];
        $limit = isset($conf['max_results']) ? (int)$conf['max_results'] : 5;

        $results = [];
        $debug = ['timestamp' => date('Y-m-d H:i:s')];

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true); // Capture headers in output
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            // --- USER AGENT ROTATION ---
            $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
            // Disable rotation if using bridge; let the Worker handle identity
            if (!empty($conf['rotate_ua']) && $conf['proxy_mode'] !== 'bridge') {
                $uas = [
                    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/124.0.0.0 Safari/537.36',
                    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Safari/537.36',
                    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Safari/537.36'
                ];
                $ua = $uas[array_rand($uas)];
            }
            curl_setopt($ch, CURLOPT_USERAGENT, $ua);
            curl_setopt($ch, CURLOPT_REFERER, 'https://duckduckgo.com/');

            // --- PROXY / CDN ROUTING ---
            if (!empty($conf['proxy_mode']) && $conf['proxy_mode'] === 'http' && !empty($conf['proxy_list'])) {
                $proxies = array_filter(explode("\n", str_replace("\r", "", $conf['proxy_list'])));
                if (!empty($proxies)) {
                    $selected = trim($proxies[array_rand($proxies)]);
                    curl_setopt($ch, CURLOPT_PROXY, $selected);
                    $debug['active_proxy'] = $selected;
                }
            }

            if ($provider === 'ddg') {
                $target = "https://duckduckgo.com/lite/?q=" . urlencode($query);
                $url = $target;
                
                $bridgeBase = $priv['bridge_url'] ?? '';
                if (!empty($conf['proxy_mode']) && $conf['proxy_mode'] === 'bridge' && !empty($bridgeBase)) {
                    if (strpos($bridgeBase, '{{URL}}') !== false) {
                        $url = str_replace('{{URL}}', $target, $bridgeBase);
                    } else {
                        $url = rtrim($bridgeBase, '/') . '/?url=' . urlencode($target);
                    }
                    if ($isDeep) $url .= "&deep=true";
                    $debug['bridge_active'] = true;
                    $debug['bridge_url_used'] = $url;
                }
                if (!empty($debug['bridge_active'])) $debug['actual_endpoint'] = $target;
                $debug['request_url'] = $url;
                curl_setopt($ch, CURLOPT_URL, $url);
                $response = curl_exec($ch);
if ($response === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new Exception("CURL Error: " . $err);
}
            
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headerText = substr($response, 0, $headerSize);
$rawBody = substr($response, $headerSize);// Handle Worker-side JSON Errors (like IP_BLOCKED)
                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 403) {
                    $errData = json_decode($rawBody, true);
                    if (isset($errData['error']) && $errData['error'] === 'IP_BLOCKED') {
                        throw new Exception("Cloudflare IP is currently flagged by DuckDuckGo. Please wait a few minutes for the IP to rotate.");
                    }
                }

                $deepDataMap = [];
                if ($isDeep && $debug['bridge_active']) {
                    $bundle = json_decode($rawBody, true);
                    if (!is_array($bundle)) {
                        $debug['bridge_json_error'] = "Bridge returned non-JSON response (likely HTML error/block).";
                        $html = $rawBody; // Fallback to scraping the raw response
                    } else {
                        $html = $bundle['search_html'] ?? '';
                        foreach (($bundle['deep_data'] ?? []) as $dp) {
                            $deepDataMap[$dp['url']] = $dp['content'];
                        }
                    }
                } else {
                    $html = $rawBody;
                }

                // Extract Proof Headers
                if (preg_match('/CF-RAY: ([^\r\n]+)/i', $headerText, $m)) $debug['cf_ray'] = $m[1];
                if (preg_match('/X-Conjure-Status: ([^\r\n]+)/i', $headerText, $m)) $debug['bridge_status'] = $m[1];
                if (preg_match('/X-Worker-ID: ([^\r\n]+)/i', $headerText, $m)) $debug['worker_id'] = $m[1];
                if (preg_match('/Server: ([^\r\n]+)/i', $headerText, $m)) $debug['server_type'] = $m[1];

                $debug['response_size'] = strlen($html) . " bytes";
                
                // Split by result blocks (DDG Lite uses table rows or simple divs)
                $blocks = explode('class="result-link"', $html);
                array_shift($blocks); // Remove header

                foreach($blocks as $i => $block) {
                    if (count($results) >= $limit) break;

                    // Extract Link and Title from Lite structure
                    if (preg_match('/href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/i', $block, $linkMatch)) {
                        $title = trim(strip_tags($linkMatch[2]));
                        $link = $linkMatch[1];
                        if (strpos($link, '//') === 0) $link = 'https:' . $link;
                        
                        // Extract Snippet (In Lite, it follows the link in a result-snippet div)
                        $snippet = "";
                        if (preg_match('/class="result-snippet"[^>]*>([\s\S]*?)<\/td>/i', $block, $snippetMatch)) {
                            $snippet = trim(strip_tags($snippetMatch[1]));
                        }

                        $results[] = [
                            'title' => $title,
                            'url' => $link,
                            'snippet' => $snippet,
                            'prefetched_content' => $deepDataMap[$link] ?? null
                        ];
                    }
                }

                // Debug: If we found results but no snippets, capture a sample
                if (count($results) > 0 && empty($results[0]['snippet'])) {
                    $debug['block_sample'] = mb_strimwidth($blocks[0], 0, 2000, "...");
                }
            } 
            elseif ($provider === 'tavily') {
                $key = $priv['tavily_key'] ?? '';
                $depth = ($conf['tavily_advanced'] ?? false) ? 'advanced' : 'basic';
                curl_setopt($ch, CURLOPT_URL, "https://api.tavily.com/search");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['api_key' => $key, 'query' => $query, 'search_depth' => $depth, 'max_results' => $limit]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
                $response = curl_exec($ch);
                
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $body = substr($response, $headerSize);
                $data = json_decode($body, true);
                $debug['raw_response'] = $data;
                if (isset($data['results'])) {
                    foreach($data['results'] as $r) {
                        $results[] = ['title' => $r['title'], 'url' => $r['url'], 'snippet' => $r['content'] ?? ''];
                    }
                }
            }

            $info = curl_getinfo($ch);
            $debug['http_code'] = $info['http_code'];
            $debug['total_time'] = $info['total_time'] . "s";
            $debug['results_found'] = count($results);
            
            if (count($results) === 0 && isset($html)) {
                $debug['block_detect_html'] = mb_strimwidth(strip_tags($html), 0, 500, "...");
            }
            
            if (curl_errno($ch)) {
                $debug['curl_error'] = curl_error($ch);
                $debug['curl_errno'] = curl_errno($ch);
            }
            curl_close($ch);

            // --- BULK AUTO-EXTRACTION (PARALLEL) ---
            if ($autoExtract && !empty($results)) {
                $mh = curl_multi_init();
                $handles = [];
                $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';
                
                // Check if bridge is enabled for bulk routing
                $useBridge = (!empty($conf['proxy_mode']) && $conf['proxy_mode'] === 'bridge' && !empty($conf['bridge_url']));

                foreach ($results as $idx => $r) {
                    $ch_p = curl_init();
                    $targetUrl = $r['url'];
                    
                    // Apply Bridge Transformation if active
                    if ($useBridge) {
                        $targetUrl = str_replace('{{URL}}', urlencode($targetUrl), $conf['bridge_url']);
                    }

                    curl_setopt($ch_p, CURLOPT_URL, $targetUrl);
                    curl_setopt($ch_p, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch_p, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch_p, CURLOPT_TIMEOUT, 8);
                    curl_setopt($ch_p, CURLOPT_USERAGENT, $ua);
                    curl_setopt($ch_p, CURLOPT_SSL_VERIFYPEER, false);
                    curl_multi_add_handle($mh, $ch_p);
                    $handles[$idx] = $ch_p;
                }

                $active = null;
                do { $mrc = curl_multi_exec($mh, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
                while ($active && $mrc == CURLM_OK) {
                    if (curl_multi_select($mh) != -1) {
                        do { $mrc = curl_multi_exec($mh, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
                    }
                }

                foreach ($handles as $idx => $ch_p) {
                    $html_p = curl_multi_getcontent($ch_p);
                    if ($html_p) {
                        $results[$idx]['prefetched_content'] = ws_extract_content($html_p, $results[$idx]['snippet']);
                    } else {
                        $results[$idx]['prefetched_content'] = "(Fetch Failed)";
                    }
                    curl_multi_remove_handle($mh, $ch_p);
                    curl_close($ch_p);
                }
                curl_multi_close($mh);
                $debug['bulk_extract_count'] = count($results);
            }

        } catch (Exception $e) {
            $debug['exception'] = $e->getMessage();
        }

        return ['results' => $results, 'debug' => $debug];
    }
}

    if ($_POST['plugin_action'] === 'ws_perform_search') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        
        $query = $_POST['query'];
        $provider = $_POST['provider'];
        $isDeep = ($_POST['deep'] === 'true');
        $autoExtract = ($_POST['auto_extract'] === 'true');
        
        $res = ws_perform_search_internal($query, $provider, $isDeep, $autoExtract);
        
        echo json_encode(['status' => 'success', 'results' => $res['results'], 'debug' => $res['debug']]);
        exit;
    }
}

// --- SETTINGS UI ---
$plugin_settings_map['WebSearch'] = <<<'HTML'
<div id="ws-tray-anchor">
    <div id="ws-gui-root">
        <div style="padding: 16px 16px 12px 16px;">
            <button onclick="wsOpenStudio()" class="btn-primary" style="width:100%; gap:10px;">
                <span data-sui-icon="search" data-sui-size="18"></span> Open Search Lab
            </button>
        </div>

        <div style="margin: 0 16px 20px 16px; border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; background: var(--card-bg);">
            <!-- MAJOR ACCORDION -->
            <div style="display:flex; justify-content:space-between; align-items:center; cursor:pointer; padding:16px; background:var(--btn-bg);" onclick="suiToggle('ws-major-acc')">
                <div style="font-size:12px; font-weight:800; text-transform:uppercase; color:var(--text-primary); letter-spacing:0.5px;">Search Engine Configuration</div>
                <span data-sui-icon="chevron" data-sui-arrow="ws-major-acc" data-sui-size="16" style="transition:transform 0.3s; transform:rotate(0deg);"></span>
            </div>
            
            <div id="ws-major-acc" class="sui-accordion open">
                <div class="sui-accordion-inner" style="padding: 8px;">
                    
                    <!-- SUB 1: PROVIDERS -->
                    <div style="margin-bottom:8px; border:1px solid var(--border-color); border-radius:12px; overflow:hidden;">
                        <div style="display:flex; justify-content:space-between; align-items:center; cursor:pointer; padding:12px; background:rgba(0,0,0,0.02);" onclick="suiToggle('ws-acc-providers')">
                            <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary);">Providers & Credentials</div>
                            <span data-sui-icon="chevron" data-sui-arrow="ws-acc-providers" data-sui-size="12" style="transition:transform 0.3s; transform:rotate(-90deg);"></span>
                        </div>
                        <div id="ws-acc-providers" class="sui-accordion" style="display: none; overflow: hidden;">
                            <div class="sui-accordion-inner" style="padding:12px;">
                                <label class="setting-label" style="font-size:11px; opacity:0.6; text-transform:uppercase;">Default Search Engine</label>
                                <div onclick="wsOpenProviderPicker()" style="background:var(--btn-bg); color:var(--text-primary); padding:12px 16px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; cursor:pointer; border:1px solid var(--border-color); margin-bottom:16px;">
                                    <span id="ws-provider-label" style="font-weight:600; font-size:14px;">DuckDuckGo</span>
                                    <span data-sui-icon="chevron" data-sui-size="14" style="opacity:0.5;"></span>
                                </div>
                                <input type="hidden" id="ws-pref-provider" value="ddg">

                                <div style="display:flex; flex-direction:column; gap:16px; border-top:1px solid var(--border-color); padding-top:16px;">
                                    <div>
                                        <label class="setting-label" style="font-size:11px; opacity:0.6; text-transform:uppercase;">Tavily AI API Key</label>
                                        <input type="text" id="ws-key-tavily" class="input-secret-key" autocomplete="off" data-bwignore="true" data-1p-ignore="true" data-lpignore="true" spellcheck="false" placeholder="Enter Key..." onchange="wsSaveConfig()" style="margin-top:4px; font-size:12px; font-family:monospace;">
                                        <div style="margin-top:12px; border:1px solid var(--border-color); padding:4px 12px; border-radius:12px; background:rgba(0,0,0,0.02);">
                                            <div data-sui-setting="Tavily Deep Crawl" data-sui-desc="Use Tavily's agentic crawl mode." data-sui-id="ws-pref-advanced" data-sui-onchange="wsSaveConfig()"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SUB 2: BEHAVIOR -->
                    <div style="margin-bottom:8px; border:1px solid var(--border-color); border-radius:12px; overflow:hidden;">
                        <div style="display:flex; justify-content:space-between; align-items:center; cursor:pointer; padding:12px; background:rgba(0,0,0,0.02);" onclick="suiToggle('ws-acc-behavior')">
                            <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary);">Research Behavior</div>
                            <span data-sui-icon="chevron" data-sui-arrow="ws-acc-behavior" data-sui-size="12" style="transition:transform 0.3s; transform:rotate(-90deg);"></span>
                        </div>
                        <div id="ws-acc-behavior" class="sui-accordion" style="display: none; overflow: hidden;">
                            <div class="sui-accordion-inner" style="padding:12px;">
                                <div class="setting-item vertical" style="padding:0; margin-bottom:16px;">
                                    <label class="setting-label" style="font-size:13px;">Max Results: <span id="ws-max-val" style="color:var(--primary);">5</span></label>
                                    <div data-sui-slider="true" data-sui-id="ws-max-slider" data-sui-min="1" data-sui-max="20" data-sui-value="max_results" data-sui-oninput="document.getElementById('ws-max-val').innerText = this.value" data-sui-onchange="wsSaveConfig()"></div>
                                </div>
                                <div data-sui-setting="Full Research Mode" data-sui-desc="Automatically read and extract content for all results." data-sui-id="ws-pref-auto-extract" data-sui-onchange="wsSaveConfig()"></div>
                            </div>
                        </div>
                    </div>

                    <!-- SUB 3: PRIVACY & ROUTING -->
                    <div style="border:1px solid var(--border-color); border-radius:12px; overflow:hidden;">
                        <div style="display:flex; justify-content:space-between; align-items:center; cursor:pointer; padding:12px; background:rgba(0,0,0,0.02);" onclick="suiToggle('ws-acc-routing')">
                            <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary);">Privacy & CDN Routing</div>
                            <span data-sui-icon="chevron" data-sui-arrow="ws-acc-routing" data-sui-size="12" style="transition:transform 0.3s; transform:rotate(-90deg);"></span>
                        </div>
                        <div id="ws-acc-routing" class="sui-accordion" style="display: none; overflow: hidden;">
                            <div class="sui-accordion-inner" style="padding:12px;">
                                <label class="setting-label" style="font-size:11px; opacity:0.6; text-transform:uppercase;">Routing Mode</label>
                                <div onclick="wsOpenProxyPicker()" style="background:var(--btn-bg); color:var(--text-primary); padding:12px 16px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; cursor:pointer; border:1px solid var(--border-color); margin-bottom:12px;">
                                    <span id="ws-proxy-label" style="font-weight:600; font-size:14px;">Direct (No Proxy)</span>
                                    <span data-sui-icon="chevron" data-sui-size="14" style="opacity:0.5;"></span>
                                </div>
                                <input type="hidden" id="ws-pref-proxy-mode" value="none">

                                <div id="ws-proxy-list-box" style="margin-top:12px; display:none;">
                                    <label class="setting-label" style="font-size:12px;">Proxy List (one per line)</label>
                                    <textarea id="ws-pref-proxy-list" placeholder="1.2.3.4:8080" onchange="wsSaveConfig()" style="margin-top:4px; font-family:monospace; font-size:11px; height:80px; background:var(--input-bg);"></textarea>
                                </div>

                                <div id="ws-bridge-box" style="margin-top:12px; display:none;">
                                    <label class="setting-label" style="font-size:11px; opacity:0.6; text-transform:uppercase;">Worker Bridge URL</label>
                                    <input type="text" id="ws-pref-bridge-url" placeholder="https://my-worker.subdomain.workers.dev/" onchange="wsSaveConfig()" style="margin-top:4px; font-size:12px; font-family:monospace;">
                                    <div style="font-size:10px; color:var(--text-secondary); margin-top:4px; opacity:0.7;">Just paste your base Worker URL. The system handles the parameters.</div>
                                </div>

                                <div style="margin-top:12px; display:flex; justify-content:space-between; align-items:center;">
                                    <label class="setting-label" style="font-size:12px; margin:0;">Rotate User-Agents</label>
                                    <label class="switch" style="width:40px; height:22px;"><input type="checkbox" id="ws-pref-rotate-ua" onchange="wsSaveConfig()"><span class="slider" style="border-radius:20px;"></span></label>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Ported Elements for Studio -->
        <div id="ws-studio-only" style="display:none; padding: 0 16px;">
            <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:20px; padding:20px; margin-bottom:20px; box-shadow:var(--shadow-card);">
                <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">Test Bench</div>
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                    <label class="setting-label" style="font-size:12px; margin:0; color:var(--primary); font-weight:800;">Full Research (Auto-Read)</label>
                    <label class="switch" style="width:40px; height:22px;"><input type="checkbox" id="ws-deep-toggle" onchange="document.getElementById('ws-pref-auto-extract').checked = this.checked; wsSaveConfig();"><span class="slider" style="border-radius:20px;"></span></label>
                </div>
                <div style="display:flex; gap:10px;">
                    <input type="text" id="ws-test-query" placeholder="What are you looking for?" style="flex:1; height:48px; font-size:16px;">
                    <button onclick="wsRunTestSearch()" id="ws-btn-run" style="width:48px; height:48px; border-radius:12px; background:var(--primary); color:white; border:none; display:flex; align-items:center; justify-content:center; cursor:pointer;">
                        <span data-sui-icon="search" data-sui-size="20" data-sui-stroke="3"></span>
                    </button>
                </div>
                <div id="ws-history-chips" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:12px;"></div>
            </div>

            <div id="ws-results-container" style="display:flex; flex-direction:column; gap:12px; margin-bottom:24px;"></div>

            <div style="margin-bottom:100px;">
                <div style="display:flex; justify-content:space-between; align-items:center; cursor:pointer; padding:10px 14px; border-radius:12px; border:1px solid var(--border-color); background:var(--card-bg);" onclick="suiToggle('ws-debug-acc')">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-secondary); letter-spacing:0.5px;">Debug Console</div>
                        <button onclick="event.stopPropagation(); wsCopyDebugLog()" class="text-btn" style="padding:2px 8px; font-size:9px; background:var(--btn-bg); border-radius:6px; font-weight:800;">COPY</button>
                    </div>
                    <span data-sui-icon="chevron" data-sui-arrow="ws-debug-acc" data-sui-size="14" style="transition:transform 0.3s; transform:rotate(-90deg);"></span>
                </div>
                <div id="ws-debug-acc" class="sui-accordion">
                    <div class="sui-accordion-inner" style="padding:12px 0;">
                        <pre id="ws-debug-log" style="background:#000; color:#00FF41; padding:15px; border-radius:12px; font-family:monospace; font-size:10px; height:200px; overflow-y:auto; margin:0; border:1px solid #333;"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;

$plugin_js .= <<<'JS'
// --- WEB SEARCH ENGINE JS ---

window.addEventListener('load', wsLoadConfig);

async function wsLoadConfig() {
    const applyData = (data) => {
        if (!data || !data.config) return;
        const provider = data.config.provider || 'ddg';
        const prefProv = document.getElementById('ws-pref-provider');
        if (prefProv) prefProv.value = provider;
        
        const provMap = { ddg: 'DuckDuckGo', tavily: 'Tavily AI' };
        const provLabel = document.getElementById('ws-provider-label');
        if (provLabel) provLabel.innerText = provMap[provider] || provider;
        
        const advToggle = document.getElementById('ws-pref-advanced');
        if (advToggle) advToggle.checked = data.config.tavily_advanced === true;
        
        const autoExtract = data.config.auto_extract !== false;
        const autoExtToggle = document.getElementById('ws-pref-auto-extract');
        if (autoExtToggle) autoExtToggle.checked = autoExtract;
        
        const deepToggle = document.getElementById('ws-deep-toggle');
        if (deepToggle) deepToggle.checked = autoExtract;
        
        const proxyMode = data.config.proxy_mode || 'none';
        const prefProxy = document.getElementById('ws-pref-proxy-mode');
        if (prefProxy) prefProxy.value = proxyMode;
        
        const proxyMap = { none: 'Direct (No Proxy)', http: 'HTTP Proxy List', bridge: 'URL Bridge (CDN)' };
        const proxyLabel = document.getElementById('ws-proxy-label');
        if (proxyLabel) proxyLabel.innerText = proxyMap[proxyMode] || proxyMode;
        
        const proxyList = document.getElementById('ws-pref-proxy-list');
        if (proxyList) proxyList.value = data.config.proxy_list || '';
        
        const bridgeUrl = document.getElementById('ws-pref-bridge-url');
        if (bridgeUrl) bridgeUrl.value = (data.private && data.private.bridge_url) ? data.private.bridge_url : '';
        
        const rotateUa = document.getElementById('ws-pref-rotate-ua');
        if (rotateUa) rotateUa.checked = data.config.rotate_ua !== false;
        
        const maxVal = data.config.max_results || 5;
        const slider = document.getElementById('ws-max-slider');
        if (slider) {
            slider.value = maxVal;
            const maxLabel = document.getElementById('ws-max-val');
            if (maxLabel) maxLabel.innerText = maxVal;
            slider.style.setProperty('--range-pct', ((maxVal - 1) / (20 - 1)) * 100 + '%');
        }
        
        const braveKey = document.getElementById('ws-key-brave');
        if (braveKey) braveKey.value = (data.private && data.private.brave_key) ? data.private.brave_key : '';
        
        const tavilyKey = document.getElementById('ws-key-tavily');
        if (tavilyKey) tavilyKey.value = (data.private && data.private.tavily_key) ? data.private.tavily_key : '';
        
        wsUpdateVisibility();
    };

    // 1. Immediate Hydration from Bridge
    if (window.__WS_BRIDGE__) applyData(window.__WS_BRIDGE__);

    // 2. Background Sync
    try {
        const data = await window.sui.api('ws_get_config', {}, { toast: false });
        if (data) applyData(data);
    } catch(e) {}
}

window.wsSaveConfig = async function() {
    const payload = {
        provider: document.getElementById('ws-pref-provider').value,
        searx_instance: '',
        max_results: document.getElementById('ws-max-slider').value,
        tavily_advanced: document.getElementById('ws-pref-advanced').checked,
        auto_extract: document.getElementById('ws-pref-auto-extract').checked,
        proxy_mode: document.getElementById('ws-pref-proxy-mode').value,
        proxy_list: document.getElementById('ws-pref-proxy-list').value,
        bridge_url: document.getElementById('ws-pref-bridge-url').value,
        rotate_ua: document.getElementById('ws-pref-rotate-ua').checked,
        brave_key: '',
        tavily_key: document.getElementById('ws-key-tavily').value
    };
    await window.sui.api('ws_save_config', payload, { toast: "Search Config Saved" });
    wsUpdateVisibility();
};

function wsUpdateVisibility() {
    const proxyMode = document.getElementById('ws-pref-proxy-mode').value;
    if (document.getElementById('ws-proxy-list-box')) document.getElementById('ws-proxy-list-box').style.display = (proxyMode === 'http') ? 'block' : 'none';
    if (document.getElementById('ws-bridge-box')) document.getElementById('ws-bridge-box').style.display = (proxyMode === 'bridge') ? 'block' : 'none';
}

window.wsOpenProviderPicker = function() {
    const options = [
        { label: "DuckDuckGo (Free/Scrape)", value: "ddg" },
        { label: "Tavily AI Search", value: "tavily" }
    ];
    window.openPicker("Default Search Engine", options, document.getElementById('ws-pref-provider').value, (val) => {
        document.getElementById('ws-pref-provider').value = val;
        const provMap = { ddg: 'DuckDuckGo', tavily: 'Tavily AI' };
        document.getElementById('ws-provider-label').innerText = provMap[val] || val;
        wsSaveConfig();
    });
};

window.wsOpenProxyPicker = function() {
    const options = [
        { label: "Direct (No Proxy)", value: "none" },
        { label: "HTTP Proxy List (Rotating)", value: "http" },
        { label: "URL Bridge (CDN / Worker)", value: "bridge" }
    ];
    window.openPicker("Routing Mode", options, document.getElementById('ws-pref-proxy-mode').value, (val) => {
        document.getElementById('ws-pref-proxy-mode').value = val;
        const proxyMap = { none: 'Direct (No Proxy)', http: 'HTTP Proxy List', bridge: 'URL Bridge (CDN)' };
        document.getElementById('ws-proxy-label').innerText = proxyMap[val] || val;
        wsSaveConfig();
    });
};

window.wsFetchUrl = async function(url, idx, anchor = '') {
    const resBox = document.getElementById(`ws-fetch-res-${idx}`);
    resBox.style.display = 'block';
    resBox.innerHTML = window.suiSpinner(20);
    
    try {
        const data = await window.sui.api('ws_fetch_url', { url: url, anchor: anchor }, { toast: false });
        const debugLog = document.getElementById('ws-debug-log');
        if (debugLog) {
            debugLog.innerText += `[${new Date().toLocaleTimeString()}] [FETCH] URL: ${url.substring(0,40)}...\n`;
            if (data.debug) {
                debugLog.innerText += `[DEBUG] Bridge: ${data.debug.bridge_active ? 'YES' : 'NO'}\n`;
                if (data.debug.cf_ray) debugLog.innerText += `[DEBUG] CF-RAY: ${data.debug.cf_ray}\n`;
                if (data.debug.bridge_status) debugLog.innerText += `[DEBUG] Status: ${data.debug.bridge_status}\n`;
            }
            debugLog.innerText += `[FETCH] Method: ${data.method} | Size: ${data.raw_size} bytes\n\n`;
            debugLog.scrollTop = debugLog.scrollHeight;
        }

        if (data && data.content) {
            resBox.innerText = data.content;
            resBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            resBox.innerText = "Error: Could not extract text from this page. Content may be blocked or protected.";
        }
    } catch(e) {
        resBox.innerText = "Fetch Failed: " + e.message;
        const debugLog = document.getElementById('ws-debug-log');
        if (debugLog) {
            debugLog.innerText += `[ERROR] Fetch failed for ${url.substring(0,30)}...\n`;
            debugLog.innerText += `[REASON] ${e.message}\n`;
            debugLog.scrollTop = debugLog.scrollHeight;
        }
    }
};

window.wsCopyDebugLog = function() {
    const log = document.getElementById('ws-debug-log').innerText;
    if (!log) return;
    const wrapped = "```text\n" + log.trim() + "\n```";
    navigator.clipboard.writeText(wrapped).then(() => {
        const t = document.getElementById("toast");
        if(t) { t.innerText = "Debug Log Copied"; t.classList.add("show"); setTimeout(()=>t.classList.remove("show"), 2000); }
    });
};

window.wsOpenStudio = function() {
    const root = document.getElementById('ws-gui-root');
    const anchor = document.getElementById('ws-tray-anchor');
    const studioOnly = document.getElementById('ws-studio-only');

    window.sui.openStudio({
        id: 'websearch',
        title: 'Web Search Lab',
        onSetup: (contentBox) => {
            contentBox.appendChild(root);
            studioOnly.style.display = 'block';
            wsUpdateVisibility();
            wsRenderHistory();
        },
        onClose: () => {
            studioOnly.style.display = 'none';
            anchor.appendChild(root);
        }
    });
};

window.wsRenderHistory = function() {
    const cont = document.getElementById('ws-history-chips');
    if (!cont) return;
    const history = JSON.parse(localStorage.getItem('cjos_ws_history') || '[]');
    cont.innerHTML = history.map(q => `
        <div onclick="document.getElementById('ws-test-query').value='${q.replace(/'/g, "\\'")}'; wsRunTestSearch();" style="background:var(--btn-bg); color:var(--text-secondary); font-size:10px; font-weight:700; padding:4px 10px; border-radius:20px; cursor:pointer; border:1px solid var(--border-color);">${q}</div>
    `).join('') + (history.length > 0 ? `<div onclick="localStorage.removeItem('cjos_ws_history'); wsRenderHistory();" style="font-size:10px; color:var(--danger); padding:4px; cursor:pointer; font-weight:800;">CLEAR</div>` : '');
};

window.wsRunTestSearch = async function() {
    const query = document.getElementById('ws-test-query').value.trim();
    if (!query) return;

    // Save History
    let history = JSON.parse(localStorage.getItem('cjos_ws_history') || '[]');
    history = [query, ...history.filter(q => q !== query)].slice(0, 8);
    localStorage.setItem('cjos_ws_history', JSON.stringify(history));
    wsRenderHistory();

    const btn = document.getElementById('ws-btn-run');
    const container = document.getElementById('ws-results-container');
    const debugLog = document.getElementById('ws-debug-log');
    
    btn.disabled = true;
    btn.style.opacity = '0.5';
    container.innerHTML = window.suiSpinner(30);
    debugLog.innerText = `[${new Date().toLocaleTimeString()}] Initializing search for: "${query}"...\n`;

    try {
        const provider = document.getElementById('ws-pref-provider').value;
        const isDeep = document.getElementById('ws-deep-toggle')?.checked || false;
        const autoExtract = document.getElementById('ws-pref-auto-extract')?.checked || false;
        const data = await window.sui.api('ws_perform_search', { query, provider, deep: isDeep, auto_extract: autoExtract }, { toast: false });
        
        debugLog.innerText += `[DEBUG] Provider: ${provider}\n`;
        debugLog.innerText += `[DEBUG] Raw Response:\n${JSON.stringify(data.debug, null, 2)}\n`;
        debugLog.scrollTop = debugLog.scrollHeight;

        if (data.results && data.results.length > 0) {
            container.innerHTML = data.results.map((r, idx) => `
                <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:14px; padding:16px; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:4px;">
                        <a href="${r.url}" target="_blank" style="color:var(--primary); font-weight:700; text-decoration:none; flex:1; font-size:15px;">${r.title}</a>
                        <button onclick="wsFetchUrl('${r.url}', ${idx}, '${(r.snippet || '').replace(/'/g, "\\'")}')" class="text-btn" style="background:var(--btn-bg); padding:4px 8px; border-radius:6px; font-size:9px; font-weight:800; white-space:nowrap;">READ PAGE</button>
                    </div>
                    <div style="font-size:10px; color:var(--text-secondary); margin-bottom:8px; word-break:break-all; opacity:0.6;">${r.url}</div>
                    <div style="font-size:13px; color:var(--text-primary); line-height:1.4;">${r.snippet || '(No snippet available)'}</div>
                    <div id="ws-fetch-res-${idx}" style="${r.prefetched_content ? 'display:block;' : 'display:none;'} margin-top:12px; padding-top:12px; border-top:1px dashed var(--border-color); font-size:11px; color:var(--text-secondary); white-space:pre-wrap; max-height:200px; overflow-y:auto;">${r.prefetched_content || ''}</div>
                </div>
            `).join('');
            
            // Auto-open debug if results failed or are empty
        } else {
            container.innerHTML = window.suiEmptyState('🔎', 'No results found.');
        }
    } catch(e) {
        container.innerHTML = `<div style="color:var(--danger); padding:20px; text-align:center; font-weight:700;">Search Failed. Check Debug Log.</div>`;
        debugLog.innerText += `[ERROR] ${e.message}\n`;
    } finally {
        btn.disabled = false;
        btn.style.opacity = '1';
    }
};
JS;
?>