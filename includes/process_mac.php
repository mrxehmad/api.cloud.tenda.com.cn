<?php
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/network_utils.php';
require_once __DIR__ . '/pihole_manager.php';
require_once __DIR__ . '/telegram.php';

loadEnv();

$known_devices_file = __DIR__ . '/../known_devices.json';
$device_database_file = __DIR__ . '/../device_database.json';
$requests_file = __DIR__ . '/../requests.json';
$mac_address = $argv[1] ?? '';
$mac_address = str_replace('-', ':', $mac_address);
$mac_list = explode(", ", $mac_address);
$primary_mac = $mac_list[0];
$now = date("Y-m-d H:i:s");

// Load data files
$known_devices = file_exists($known_devices_file) ? json_decode(file_get_contents($known_devices_file), true) ?? [] : [];
$device_db = file_exists($device_database_file) ? json_decode(file_get_contents($device_database_file), true) ?? ['devices' => []] : ['devices' => []];
$requests = file_exists($requests_file) ? json_decode(file_get_contents($requests_file), true) ?? [] : [];

// Step 1: Check if MAC is valid
if (!preg_match('/^([a-f0-9]{2}:){5}[a-f0-9]{2}$/i', $primary_mac)) {
    // Log invalid MAC to requests.json
    $log_entry = [
        'time' => $now,
        'mac' => $primary_mac,
        'ip' => null,
        'known' => false,
        'added_to_pihole' => false,
        'status' => 'invalid_mac',
        'message' => 'Invalid MAC address format'
    ];
    $requests[] = $log_entry;
    file_put_contents($requests_file, json_encode($requests));
    
    // Log to Firebase
    logToFirebase($log_entry);
    return;
}

// Step 2: Check if MAC is in known_devices.json
if (in_array($primary_mac, $known_devices)) {
    // Log known device to requests.json
    $log_entry = [
        'time' => $now,
        'mac' => $primary_mac,
        'ip' => null,
        'known' => true,
        'added_to_pihole' => false,
        'status' => 'known_device',
        'message' => 'Device is in known devices list'
    ];
    $requests[] = $log_entry;
    file_put_contents($requests_file, json_encode($requests));
    
    // Log to Firebase
    logToFirebase($log_entry);
    return;
}

// Step 3: Check device database for existing entry
$existing_device = null;
$device_index = -1;
foreach ($device_db['devices'] as $index => $device) {
    if ($device['mac'] === $primary_mac) {
        $existing_device = $device;
        $device_index = $index;
        break;
    }
}

// Step 4: Handle different scenarios based on existing device
if ($existing_device) {
    // Device exists in database
    $pihole_added_time = $existing_device['pihole_added_time'];
    $hours_since_added = 0;
    
    if ($pihole_added_time && $existing_device['added_to_pihole']) {
        $hours_since_added = (strtotime($now) - strtotime($pihole_added_time)) / 3600;
    }
    
    if ($existing_device['added_to_pihole'] && $hours_since_added < 24) {
        // Added to Pi-hole and less than 24 hours old - send notification without scanning
        $message = "ℹ️ *Device Already Blacklisted in Pi-hole*\n\n" .
                   "📱 MAC: `$primary_mac`\n" .
                   "🌐 IP: `" . ($existing_device['ip'] ?: 'Not found') . "`\n" .
                   "🕐 Time: $now\n\n" .
                   "This device was already in the Pi-hole blacklist.";
        sendTelegramMessage($message, getenv('TELEGRAM_BOT_TOKEN'), getenv('TELEGRAM_CHAT_ID'));
        
        $status = 'already_added_recent';
        $message = 'Device already added to Pi-hole (recent)';
        $ip = $existing_device['ip']; // Use stored IP
        
    } else if ($existing_device['added_to_pihole'] && $hours_since_added >= 24) {
        // Added to Pi-hole but older than 24 hours - scan for IP update
        $ip = findIpByMacNmap($primary_mac);
        
        if ($ip) {
            $device_db['devices'][$device_index]['ip'] = $ip;
            $device_db['devices'][$device_index]['last_updated'] = $now;
            file_put_contents($device_database_file, json_encode($device_db));
            
            $message = "🔄 *Device IP Updated*\n\n" .
                      "📱 MAC: `$primary_mac`\n" .
                      "🌐 New IP: `$ip`\n" .
                      "🕐 Time: $now\n\n" .
                      "Device IP has been updated in the database.";
            sendTelegramMessage($message, getenv('TELEGRAM_BOT_TOKEN'), getenv('TELEGRAM_CHAT_ID'));
            
            $status = 'ip_updated';
            $message = 'Device IP updated (older than 24h)';
        } else {
            $message = "⚠️ *Device IP Not Found*\n\n" .
                      "📱 MAC: `$primary_mac`\n" .
                      "🌐 IP: Not found\n" .
                      "🕐 Time: $now\n\n" .
                      "Could not find IP address for this device.";
            sendTelegramMessage($message, getenv('TELEGRAM_BOT_TOKEN'), getenv('TELEGRAM_CHAT_ID'));
            
            $status = 'ip_not_found';
            $message = 'Device IP not found (older than 24h)';
            $ip = null;
        }
        
    } else {
        // Not added to Pi-hole - scan and add it
        $ip = findIpByMacNmap($primary_mac);
        addDeviceToPihole($primary_mac, $ip, $device_db, -1, $now);
        $status = 'added_to_pihole';
        $message = 'Device added to Pi-hole';
    }
    
} else {
    // New device - scan and add to database and Pi-hole
    $ip = findIpByMacNmap($primary_mac);
    addDeviceToPihole($primary_mac, $ip, $device_db, -1, $now);
    $status = 'new_device_added';
    $message = 'New device added to Pi-hole';
}

// Log to requests.json
$log_entry = [
    'time' => $now,
    'mac' => $primary_mac,
    'ip' => $ip,
    'known' => false,
    'added_to_pihole' => $existing_device ? $existing_device['added_to_pihole'] : false,
    'status' => $status,
    'message' => $message
];
$requests[] = $log_entry;
@file_put_contents($requests_file, json_encode($requests));

// Log to Firebase
logToFirebase($log_entry);

// Helper function to add device to Pi-hole
function addDeviceToPihole($mac, $ip, &$device_db, $device_index, $now) {
    if (!$ip) {
        // IP not found - send notification and add to database
        $message = "⚠️ *Unknown Device Detected*\n\n" .
                  "📱 MAC: `$mac`\n" .
                  "🌐 IP: Not found\n" .
                  "🕐 Time: $now\n\n" .
                  "Could not find IP address for this MAC. Device not blacklisted.";
        sendTelegramMessage($message, getenv('TELEGRAM_BOT_TOKEN'), getenv('TELEGRAM_CHAT_ID'));
        
        // Add to database without Pi-hole
        $device_db['devices'][] = [
            'mac' => $mac,
            'ip' => null,
            'first_seen' => $now,
            'last_updated' => $now,
            'known' => false,
            'added_to_pihole' => false,
            'pihole_added_time' => null
        ];
        @file_put_contents(__DIR__ . '/../device_database.json', json_encode($device_db));
        return;
    }
    
    // Add to Pi-hole
    $pihole = new PiHoleClientManager(getenv('PIHOLE_URL'), getenv('PIHOLE_PASSWORD'));
    $pihole_result = $pihole->addIpToGroup($ip, 4, "Unknown device - MAC: $mac");
    
    if ($pihole_result['success']) {
        $action = $pihole_result['action'] === 'added' ? 'added to' : 'updated in';
        $message = "🚫 *Unknown Device Detected & Blacklisted*\n\n" .
                  "📱 MAC: `$mac`\n" .
                  "🌐 IP: `$ip`\n" .
                  "⚡ Action: Device $action Pi-hole Group 4 (Blacklist)\n" .
                  "🕐 Time: $now\n\n" .
                  "This device has been automatically blocked.";
        sendTelegramMessage($message, getenv('TELEGRAM_BOT_TOKEN'), getenv('TELEGRAM_CHAT_ID'));
        
        // Update or add to database
        if ($device_index >= 0) {
            $device_db['devices'][$device_index]['ip'] = $ip;
            $device_db['devices'][$device_index]['last_updated'] = $now;
            $device_db['devices'][$device_index]['added_to_pihole'] = true;
            $device_db['devices'][$device_index]['pihole_added_time'] = $now;
        } else {
            $device_db['devices'][] = [
                'mac' => $mac,
                'ip' => $ip,
                'first_seen' => $now,
                'last_updated' => $now,
                'known' => false,
                'added_to_pihole' => true,
                'pihole_added_time' => $now
            ];
        }
        @file_put_contents(__DIR__ . '/../device_database.json', json_encode($device_db));
        
        // Also add to blacklisted.json
        $blacklisted_file = __DIR__ . '/../blacklisted.json';
        $blacklisted = file_exists($blacklisted_file) ? json_decode(file_get_contents($blacklisted_file), true) ?? [] : [];
        
        // Check if already in blacklist
        $already_blacklisted = false;
        foreach ($blacklisted as $entry) {
            if ($entry['mac'] === $mac) {
                $already_blacklisted = true;
                break;
            }
        }
        
        // Add to blacklist if not already there
        if (!$already_blacklisted) {
            $blacklisted[] = [
                'mac' => $mac,
                'ip' => $ip,
                'first_seen' => $now,
                'blacklisted_time' => $now,
                'status' => 'blacklisted'
            ];
            @file_put_contents($blacklisted_file, json_encode($blacklisted));
        }
        
    } else {
        $error_message = "❌ *Pi-hole Error*\n\n" .
                        "📱 MAC: `$mac`\n" .
                        "🌐 IP: `$ip`\n" .
                        "❌ Error: " . $pihole_result['error'] . "\n" .
                        "🕐 Time: $now\n\n" .
                        "Failed to add device to blacklist.";
        sendTelegramMessage($error_message, getenv('TELEGRAM_BOT_TOKEN'), getenv('TELEGRAM_CHAT_ID'));
    }
}

/**
 * Log query data to Firebase Realtime Database
 * @param array $data The log entry data
 */
function logToFirebase($data) {
    $firebase_url = getenv('FIREBASE_DATABASE_URL');
    
    if (!$firebase_url) {
        error_log("Firebase URL not configured in environment variables");
        return false;
    }
    
    // Remove trailing slash if present
    $firebase_url = rtrim($firebase_url, '/');
    
    // Create a unique key using timestamp and random string
    $timestamp = microtime(true);
    $unique_key = str_replace(['.', ' ', ':'], '_', $data['time']) . '_' . substr(md5(uniqid()), 0, 8);
    
    // Prepare the endpoint - no auth needed with proper security rules
    $endpoint = $firebase_url . '/device_queries/' . $unique_key . '.json';
    
    // Add timestamp in milliseconds for Firebase
    $data['timestamp'] = round($timestamp * 1000);
    
    // Initialize cURL
    $ch = curl_init($endpoint);
    
    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 5, // Reduced timeout for logging
        CURLOPT_CONNECTTIMEOUT => 3
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Check for errors (but don't break the main script)
    if ($error) {
        error_log("Firebase cURL error: " . $error);
        return false;
    }
    
    if ($http_code >= 200 && $http_code < 300) {
        return true;
    } else {
        error_log("Firebase HTTP error: " . $http_code . " - Response: " . $response);
        return false;
    }
}