<?php
function agent_get_openrouter_key() {
    $private_file = __DIR__ . '/../data/secrets-private.json';
    if (file_exists($private_file)) {
        $secrets = json_decode(file_get_contents($private_file), true);
        if (!empty($secrets['openrouter_api_key'])) {
            return trim($secrets['openrouter_api_key']);
        }
    }
    // Fallback to system-wide OpenRouter key if present
    if (defined('CJOS_PATH_DATA')) {
        $sys_private = CJOS_PATH_DATA . '/openrouter-private.json';
        if (file_exists($sys_private)) {
            $sys_secrets = json_decode(file_get_contents($sys_private), true);
            if (!empty($sys_secrets['api_key'])) {
                return trim($sys_secrets['api_key']);
            }
        }
    }
    return null;
}

function agent_openrouter_complete($messages, $model = 'anthropic/claude-3.5-sonnet', $temperature = 0.2) {
    $api_key = agent_get_openrouter_key();
    if (!$api_key) {
        return ['success' => false, 'error' => 'OpenRouter API Key is missing. Please configure it in AgentStudio settings.'];
    }

    $url = 'https://openrouter.ai/api/v1/chat/completions';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
        'HTTP-Referer: http://localhost:8000',
        'X-Title: Conjure OS AgentStudio'
    ];

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => (float)$temperature
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'error' => 'cURL Error: ' . $err];
    }

    $json = json_decode($response, true);
    if ($httpCode !== 200 || !isset($json['choices'][0]['message'])) {
        $errMsg = $json['error']['message'] ?? ($json['message'] ?? 'OpenRouter API Error (HTTP ' . $httpCode . ')');
        return ['success' => false, 'error' => $errMsg, 'raw' => $json];
    }

    $msg = $json['choices'][0]['message'];
    return [
        'success' => true,
        'content' => $msg['content'] ?? '',
        'role' => $msg['role'] ?? 'assistant',
        'raw' => $json
    ];
}

function agent_openrouter_stream($messages, $model = 'anthropic/claude-3.5-sonnet', $temperature = 0.2, $onChunk = null) {
    $api_key = agent_get_openrouter_key();
    if (!$api_key) {
        return ['success' => false, 'error' => 'OpenRouter API Key is missing.'];
    }

    $url = 'https://openrouter.ai/api/v1/chat/completions';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
        'HTTP-Referer: http://localhost:8000',
        'X-Title: Conjure OS AgentStudio'
    ];

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => (float)$temperature,
        'stream' => true
    ];

    $fullContent = "";
    $streamError = null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$fullContent, &$streamError, $onChunk) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // Intercept HTTP Error Responses (400, 401, 402, 429, 500, etc.)
            if ($httpCode >= 400) {
                $json = json_decode($data, true);
                if (isset($json['error']['message'])) {
                    $streamError = $json['error']['message'];
                } else if (isset($json['message'])) {
                    $streamError = $json['message'];
                } else {
                    $streamError = "OpenRouter HTTP Error " . $httpCode;
                }
                return strlen($data);
            }

            // Normal SSE Event Stream Parsing
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, 'data: ') === 0) {
                    $rawJson = substr($line, 6);
                    if ($rawJson === '[DONE]') continue;
                    $json = json_decode($rawJson, true);
                    
                    if (isset($json['error']['message'])) {
                        $streamError = $json['error']['message'];
                        continue;
                    }

                    if (isset($json['choices'][0]['delta']['content'])) {
                        $chunk = $json['choices'][0]['delta']['content'];
                        $fullContent .= $chunk;
                        if (is_callable($onChunk)) {
                            $onChunk($chunk, $fullContent);
                        }
                    }
                }
            }
            return strlen($data);
        }
    ]);

    curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'error' => 'cURL Error: ' . $err];
    }

    if ($streamError) {
        return ['success' => false, 'error' => $streamError];
    }

    if ($httpCode >= 400) {
        return ['success' => false, 'error' => 'OpenRouter HTTP ' . $httpCode];
    }

    return [
        'success' => true,
        'content' => $fullContent,
        'role' => 'assistant'
    ];
}

function agent_get_openrouter_credits() {
    $api_key = agent_get_openrouter_key();
    if (!$api_key) return ['success' => false, 'error' => 'No API Key configured'];

    // 1. Try /api/v1/credits endpoint
    $ch = curl_init('https://openrouter.ai/api/v1/credits');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $api_key],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    if ($res) {
        $json = json_decode($res, true);
        if (isset($json['data'])) {
            $data = $json['data'];
            $total = (float)($data['total_credits'] ?? 0);
            $usage = (float)($data['total_usage'] ?? 0);
            $remaining = max(0, $total - $usage);
            return [
                'success' => true,
                'remaining' => $remaining,
                'total' => $total,
                'usage' => $usage
            ];
        }
    }

    // 2. Fallback to /api/v1/key endpoint
    $chKey = curl_init('https://openrouter.ai/api/v1/key');
    curl_setopt_array($chKey, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $api_key],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resKey = curl_exec($chKey);
    curl_close($chKey);

    if ($resKey) {
        $jsonKey = json_decode($resKey, true);
        if (isset($jsonKey['data'])) {
            $dataKey = $jsonKey['data'];
            $rem = $dataKey['limit_remaining'] ?? null;
            if ($rem !== null) {
                return ['success' => true, 'remaining' => (float)$rem];
            }
        }
    }

    return ['success' => false, 'error' => 'Unable to fetch credit balance'];
}
?>