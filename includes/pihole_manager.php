<?php

class PiHoleClientManager {
    private $piholeUrl;
    private $adminPassword;
    private $sessionId;
    private $sessionExpiry;
    
    public function __construct($piholeUrl, $adminPassword) {
        $this->piholeUrl = rtrim($piholeUrl, '/');
        $this->adminPassword = $adminPassword;
        $this->sessionId = null;
        $this->sessionExpiry = 0;
    }
    
    /**
     * Authenticate with Pi-hole and get session ID
     */
    private function authenticate() {
        $url = $this->piholeUrl . '/api/auth';
        
        $data = json_encode([
            'password' => $this->adminPassword
        ]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Authentication failed with HTTP code: $httpCode");
        }
        
        $authData = json_decode($response, true);
        
        if (!$authData || !isset($authData['session']['sid'])) {
            throw new Exception("Failed to get session ID from authentication response");
        }
        
        $this->sessionId = $authData['session']['sid'];
        $this->sessionExpiry = time() + $authData['session']['validity'];
        
        return $authData;
    }
    
    /**
     * Check if session is valid and authenticate if needed
     */
    private function ensureAuthenticated() {
        if (!$this->sessionId || time() >= $this->sessionExpiry - 60) {
            $this->authenticate();
        }
    }
    
    /**
     * Add IP to specific group in Pi-hole
     */
    public function addIpToGroup($ip, $groupId = 4, $comment = '') {
        try {
            $this->ensureAuthenticated();
            
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return [
                    'success' => false,
                    'error' => 'Invalid IP address format',
                    'ip' => $ip
                ];
            }
            
            $addResult = $this->addClient($ip, $groupId, $comment);
            
            if (isset($addResult['processed']['errors']) && 
                !empty($addResult['processed']['errors'])) {
                
                foreach ($addResult['processed']['errors'] as $error) {
                    if (strpos($error['error'], 'UNIQUE constraint failed') !== false) {
                        $updateResult = $this->updateClient($ip, $groupId, $comment);
                        return [
                            'success' => true,
                            'action' => 'updated',
                            'ip' => $ip,
                            'group' => $groupId,
                            'data' => $updateResult
                        ];
                    }
                }
                
                return [
                    'success' => false,
                    'error' => $addResult['processed']['errors'][0]['error'] ?? 'Unknown error',
                    'ip' => $ip
                ];
            }
            
            return [
                'success' => true,
                'action' => 'added',
                'ip' => $ip,
                'group' => $groupId,
                'data' => $addResult
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'ip' => $ip
            ];
        }
    }
    
    private function addClient($ip, $groupId, $comment) {
        $url = $this->piholeUrl . '/api/clients';
        
        $data = json_encode([
            'client' => $ip,
            'comment' => $comment,
            'groups' => [$groupId]
        ]);
        
        return $this->makeApiCall($url, 'POST', $data);
    }
    
    private function updateClient($ip, $groupId, $comment) {
        $url = $this->piholeUrl . '/api/clients/' . urlencode($ip);
        
        $data = json_encode([
            'comment' => $comment,
            'groups' => [$groupId]
        ]);
        
        return $this->makeApiCall($url, 'PUT', $data);
    }
    
    /**
     * Update client groups (move from one group to another)
     */
    public function updateClientGroup($ip, $newGroupId, $oldGroupId, $comment = '') {
        try {
            $this->ensureAuthenticated();
            
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return [
                    'success' => false,
                    'error' => 'Invalid IP address format',
                    'ip' => $ip
                ];
            }
            
            // Get current client info
            $getClientUrl = $this->piholeUrl . '/api/clients/' . urlencode($ip);
            $clientData = $this->makeApiCall($getClientUrl, 'GET');
            
            if (!isset($clientData['clients']) || empty($clientData['clients'])) {
                return [
                    'success' => false,
                    'error' => 'Client not found',
                    'ip' => $ip
                ];
            }
            
            $client = $clientData['clients'][0];
            $currentGroups = $client['groups'] ?? [];
            
            // Remove old group and add new group
            $newGroups = array_filter($currentGroups, function($group) use ($oldGroupId) {
                return $group != $oldGroupId;
            });
            
            // Add new group if not already present
            if (!in_array($newGroupId, $newGroups)) {
                $newGroups[] = $newGroupId;
            }
            
            // Update client with new groups
            $updateUrl = $this->piholeUrl . '/api/clients/' . urlencode($ip);
            $updateData = json_encode([
                'comment' => $comment,
                'groups' => array_values($newGroups)
            ]);
            
            $result = $this->makeApiCall($updateUrl, 'PUT', $updateData);
            
            return [
                'success' => true,
                'ip' => $ip,
                'from_group' => $oldGroupId,
                'to_group' => $newGroupId,
                'data' => $result
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'ip' => $ip
            ];
        }
    }
    
    private function makeApiCall($url, $method = 'GET', $data = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $headers = [
            'Content-Type: application/json',
            'X-FTL-SID: ' . $this->sessionId
        ];
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                $headers[] = 'Content-Length: ' . strlen($data);
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                $headers[] = 'Content-Length: ' . strlen($data);
            }
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("API call failed with HTTP code: $httpCode. Response: $response");
        }
        
        return json_decode($response, true);
    }
}