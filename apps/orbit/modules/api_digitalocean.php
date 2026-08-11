<?php
/**
 * DigitalOcean API v2 Wrapper
 * Handles server discovery and pre-flight snapshots for Orbit.
 */
class DigitalOceanAPI {
    private $token;
    private $baseUrl = 'https://api.digitalocean.com/v2';

    public function __construct($token) {
        $this->token = $token;
    }

    private function request($method, $endpoint, $payload = null) {
        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
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
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }
        
        if ($http_code === 204) {
            return ['success' => true];
        }
        
        return json_decode($response, true);
    }

    /**
     * Retrieves all active Droplets (VPS instances) for the account.
     * Useful for auto-discovering the server IP for DNS routing.
     */
    public function getDroplets() {
        return $this->request('GET', '/droplets');
    }

    public function getDropletByIp($ip) {
        $res = $this->getDroplets();
        if (isset($res['droplets'])) {
            foreach ($res['droplets'] as $droplet) {
                if (isset($droplet['networks']['v4'])) {
                    foreach ($droplet['networks']['v4'] as $network) {
                        if ($network['ip_address'] === $ip) {
                            return $droplet;
                        }
                    }
                }
            }
        }
        return null;
    }

    public function getDropletSnapshots($droplet_id) {
        return $this->request('GET', "/droplets/{$droplet_id}/snapshots");
    }

    public function deleteSnapshot($snapshot_id) {
        return $this->request('DELETE', "/snapshots/{$snapshot_id}");
    }

    public function restoreDroplet($droplet_id, $image_id) {
        $payload = [
            'type' => 'restore',
            'image' => $image_id
        ];
        return $this->request('POST', "/droplets/{$droplet_id}/actions", $payload);
    }

    /**
     * Triggers a live snapshot of a Droplet.
     * Used as a pre-flight fail-safe before a major deployment.
     */
    public function snapshotDroplet($droplet_id, $snapshot_name) {
        $payload = [
            'type' => 'snapshot',
            'name' => $snapshot_name
        ];
        return $this->request('POST', "/droplets/{$droplet_id}/actions", $payload);
    }
}