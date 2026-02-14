<?php
require_once __DIR__ . '/../includes/telegram.php';
require_once __DIR__ . '/../includes/network_utils.php';
require_once __DIR__ . '/../includes/pihole_manager.php';
require_once __DIR__ . '/../includes/env_loader.php';

loadEnv();

$data_file = __DIR__ . '/../requests.json';
$known_devices_file = __DIR__ . '/../known_devices.json';
$telegram_bot_token = getenv('TELEGRAM_BOT_TOKEN');
$telegram_chat_id = getenv('TELEGRAM_CHAT_ID');

// Pi-hole configuration
$pihole_url = getenv('PIHOLE_URL');
$pihole_password = getenv('PIHOLE_PASSWORD');

date_default_timezone_set('Asia/Karachi');

// Load known devices
$known_devices = file_exists($known_devices_file) ? json_decode(file_get_contents($known_devices_file), true) ?? [] : [];

$raw_body = file_get_contents('php://input');
$json_data = json_decode($raw_body, true);

// Get or create client ID for Google Analytics
$client_id = getClientId();

if (json_last_error() !== JSON_ERROR_NONE) {
    // Track error event in Google Analytics
    trackEvent('api_error', [
        'error_type' => 'invalid_json',
        'error_message' => json_last_error_msg(),
        'endpoint' => 'mac_api'
    ], $client_id);
    
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit();
}

if (isset($json_data['mac'])) {
    $mac_address = is_array($json_data['mac']) ? implode(", ", $json_data['mac']) : $json_data['mac'];
    $mac_address = str_replace('-', ':', $mac_address);
    $mac_list = explode(", ", $mac_address);
    $primary_mac = $mac_list[0];
    
    // MAC validation (should be 17 chars, 6 pairs, colons)
    if (!preg_match('/^([a-f0-9]{2}:){5}[a-f0-9]{2}$/i', $primary_mac)) {
        // Track invalid MAC event
        trackEvent('api_error', [
            'error_type' => 'invalid_mac_format',
            'error_message' => 'Invalid MAC address format',
            'mac_received' => $primary_mac,
            'endpoint' => 'mac_api'
        ], $client_id);
        
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(["status" => "error", "message" => "Invalid MAC address format"]);
        exit();
    }
    
    // Check if MAC is known
    $is_known = in_array($primary_mac, $known_devices);
    
    // Save complete data structure to requests.json
    $requests = file_exists($data_file) ? json_decode(file_get_contents($data_file), true) ?? [] : [];
    $requests[] = [
        "time" => date("Y-m-d H:i:s"),
        "mac" => $primary_mac,
        "ip" => null, // Will be updated by background process
        "known" => $is_known,
        "added_to_pihole" => false // Will be updated by background process
    ];
    
    // Try to write to file, ignore errors in production
    @file_put_contents($data_file, json_encode($requests));
    
    // Track successful API hit in Google Analytics
    trackEvent('api_request', [
        'mac_is_known' => $is_known ? 'true' : 'false',
        'mac_count' => count($mac_list),
        'status' => 'success',
        'endpoint' => 'mac_api'
    ], $client_id);
    
    // Respond immediately
    header('Content-Type: application/json');
    echo json_encode(["status" => "success", "message" => "Data saved"]);
    
    // Start background process for processing - use full path
    $mac_arg = escapeshellarg($primary_mac);
    $script_path = escapeshellarg(__DIR__ . "/../includes/process_mac.php");
    $cmd = "php $script_path $mac_arg > /dev/null 2>&1 &";
    exec($cmd);
    
    exit();
} else {
    // Track missing MAC field error
    trackEvent('api_error', [
        'error_type' => 'missing_mac_field',
        'error_message' => 'MAC field is missing',
        'received_fields' => implode(',', array_keys($json_data)),
        'endpoint' => 'mac_api'
    ], $client_id);
    
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "'mac' field is missing"]);
    exit();
}

/**
 * Get or create a client ID for Google Analytics
 */
function getClientId() {
    // Use IP address + User Agent hash as consistent client ID
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    return substr(md5($ip . $ua), 0, 32);
}

/**
 * Track event to Google Analytics 4 using Measurement Protocol
 * @param string $event_name Event name
 * @param array $params Event parameters
 * @param string $client_id Client ID
 */
function trackEvent($event_name, $params = [], $client_id = null) {
    $ga_measurement_id = getenv('GA_MEASUREMENT_ID'); // Format: G-XXXXXXXXXX
    $ga_api_secret = getenv('GA_API_SECRET');
    
    if (!$ga_measurement_id || !$ga_api_secret) {
        error_log("Google Analytics not configured - skipping tracking");
        return false;
    }
    
    if (!$client_id) {
        $client_id = getClientId();
    }
    
    // Prepare the endpoint
    $endpoint = "https://www.google-analytics.com/mp/collect?measurement_id={$ga_measurement_id}&api_secret={$ga_api_secret}";
    
    // Prepare event data
    $data = [
        'client_id' => $client_id,
        'events' => [
            [
                'name' => $event_name,
                'params' => array_merge($params, [
                    'engagement_time_msec' => 100,
                    'session_id' => time(),
                ])
            ]
        ]
    ];
    
    // Initialize cURL
    $ch = curl_init($endpoint);
    
    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2
    ]);
    
    // Execute request (async, don't wait for response)
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Silent fail - don't block API response
    if ($http_code < 200 || $http_code >= 300) {
        error_log("Google Analytics tracking failed: HTTP $http_code - Response: $response");
    }
    
    return true;
}

/**
 * Track page view to Google Analytics 4
 * @param string $page_path Page path
 * @param string $page_title Page title
 */
function trackPageView($page_path, $page_title = '') {
    trackEvent('page_view', [
        'page_location' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $page_path,
        'page_path' => $page_path,
        'page_title' => $page_title ?: $page_path
    ]);
}