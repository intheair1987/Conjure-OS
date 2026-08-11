<?php
/**
 * Cloudflare API v4 Wrapper
 * Handles DNS record creation and deletion for Orbit deployments.
 */
class CloudflareAPI {
    private $token;
    private $baseUrl = 'https://api.cloudflare.com/client/v4';

    public function __construct($token) {
        $this->token = $token;
    }

    private function request($method, $endpoint, $payload = null) {
        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10s connection limit
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);        // 30s overall limit
        
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }
        
        return json_decode($response, true);
    }

    /**
     * Resolves a domain name to its Cloudflare Account ID
     */
    public function getAccountId($domain) {
        $res = $this->request('GET', '/zones?name=' . urlencode($domain));
        if (isset($res['success']) && $res['success'] && !empty($res['result'])) {
            return $res['result'][0]['account']['id'];
        }
        return null;
    }

    /**
     * Retrieves all Zero Trust Access Groups for the account
     */
    /**
     * Retrieves all Cloudflare Tunnels for the account
     */
    public function getTunnels($account_id) {
        $res = $this->request('GET', "/accounts/{$account_id}/cfd_tunnel");
        if (isset($res['success']) && $res['success'] && isset($res['result'])) {
            return $res['result'];
        }
        return [];
    }

    /**
     * Creates a new Cloudflare Tunnel
     */
    public function createTunnel($account_id, $name, $secret_base64) {
        $payload = [
            'name' => $name,
            'tunnel_secret' => $secret_base64
        ];
        return $this->request('POST', "/accounts/{$account_id}/cfd_tunnel", $payload);
    }

    /**
     * Retrieves the installation token for a specific tunnel
     */
    public function getTunnelToken($account_id, $tunnel_id) {
        $res = $this->request('GET', "/accounts/{$account_id}/cfd_tunnel/{$tunnel_id}/token");
        if (isset($res['success']) && $res['success'] && isset($res['result'])) {
            return $res['result'];
        }
        return null;
    }

    /**
     * Configures the ingress routing rules for the tunnel
     */
    public function configureTunnel($account_id, $tunnel_id, $domain) {
        $payload = [
            'config' => [
                'ingress' => [
                    ['hostname' => '*.' . $domain, 'service' => 'http://127.0.0.1:80'],
                    ['hostname' => $domain, 'service' => 'http://127.0.0.1:80'],
                    ['service' => 'http_status:404']
                ]
            ]
        ];
        return $this->request('PUT', "/accounts/{$account_id}/cfd_tunnel/{$tunnel_id}/configurations", $payload);
    }

    public function getAccessGroups($account_id) {
        $res = $this->request('GET', "/accounts/{$account_id}/access/groups");
        if (isset($res['success']) && $res['success'] && isset($res['result'])) {
            return $res['result'];
        }
        return [];
    }

    /**
     * Creates a new Zero Trust Access Group
     */
    public function createAccessGroup($account_id, $name, $emails) {
        $include = [];
        foreach ($emails as $email) {
            $include[] = ['email' => ['email' => $email]];
        }
        // Access groups require at least one include rule.
        if (empty($include)) {
            $include[] = ['email' => ['email' => 'admin@placeholder.local']];
        }
        $payload = [
            'name' => $name,
            'include' => $include
        ];
        return $this->request('POST', "/accounts/{$account_id}/access/groups", $payload);
    }

    /**
     * Updates an existing Zero Trust Access Group
     */
    public function updateAccessGroup($account_id, $group_id, $name, $emails) {
        $include = [];
        foreach ($emails as $email) {
            $include[] = ['email' => ['email' => $email]];
        }
        if (empty($include)) {
            $include[] = ['email' => ['email' => 'admin@placeholder.local']];
        }
        $payload = [
            'name' => $name,
            'include' => $include
        ];
        return $this->request('PUT', "/accounts/{$account_id}/access/groups/{$group_id}", $payload);
    }

    public function getAccessApplications($account_id) {
        $res = $this->request('GET', "/accounts/{$account_id}/access/apps");
        if (isset($res['success']) && $res['success'] && isset($res['result'])) {
            return $res['result'];
        }
        return [];
    }

    /**
     * Deletes a Zero Trust Access Application
     */
    public function deleteAccessApplication($account_id, $app_id) {
        return $this->request('DELETE', "/accounts/{$account_id}/access/apps/{$app_id}");
    }

    /**
     * Automates the creation of a Zero Trust gatekeeper lock (Access Application) pointing to your subdomain
     */
    public function createAccessApplication($account_id, $name, $domain, $group_id) {
        // 1. Create the Application
        $app_payload = [
            'name' => 'Orbit: ' . $name,
            'domain' => $domain,
            'type' => 'self_hosted',
            'session_duration' => '24h'
        ];
        $app_res = $this->request('POST', "/accounts/{$account_id}/access/apps", $app_payload);
        
        if (!isset($app_res['success']) || !$app_res['success']) {
            return $app_res;
        }
        
        $app_id = $app_res['result']['id'];
        
        // 2. Create the Policy linking the Group
        $policy_payload = [
            'name' => 'Orbit Access Policy',
            'decision' => 'allow',
            'include' => [
                [
                    'group' => [
                        'id' => $group_id
                    ]
                ]
            ]
        ];
        return $this->request('POST', "/accounts/{$account_id}/access/apps/{$app_id}/policies", $policy_payload);
    }

    /**
     * Resolves a domain name (e.g., conjure.com) to its Cloudflare Zone ID
     */
    public function getZoneId($domain) {
        $res = $this->request('GET', '/zones?name=' . urlencode($domain));
        if (isset($res['success']) && $res['success'] && !empty($res['result'])) {
            return $res['result'][0]['id'];
        }
        return null;
    }

    /**
     * Retrieves DNS records for a given name and optional type
     */
    public function getRecords($zone_id, $name, $type = null) {
        $endpoint = '/zones/' . $zone_id . '/dns_records?name=' . urlencode($name);
        if ($type !== null) {
            $endpoint .= '&type=' . urlencode($type);
        }
        $res = $this->request('GET', $endpoint);
        if (isset($res['success']) && $res['success'] && !empty($res['result'])) {
            return $res['result'];
        }
        return [];
    }

    /**
     * Creates a DNS Record (CNAME or A)
     */
    public function createRecord($zone_id, $type, $name, $content, $proxied = true) {
        $payload = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => 1, // 1 = Auto
            'proxied' => $proxied
        ];
        return $this->request('POST', "/zones/{$zone_id}/dns_records", $payload);
    }

    /**
     * Deletes a DNS Record by ID
     */
    public function deleteRecord($zone_id, $record_id) {
        return $this->request('DELETE', "/zones/{$zone_id}/dns_records/{$record_id}");
    }
}