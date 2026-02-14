<?php
require_once __DIR__ . '/../includes/env_loader.php';
require_once __DIR__ . '/../includes/pihole_manager.php';
require_once __DIR__ . '/../includes/telegram.php';

loadEnv();

$blacklisted_file = __DIR__ . '/../blacklisted.json';
$device_database_file = __DIR__ . '/../device_database.json';
$known_devices_file = __DIR__ . '/../known_devices.json';

// Get the request data
$raw_body = file_get_contents('php://input');
$json_data = json_decode($raw_body, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit();
}

if (!isset($json_data['mac']) || !isset($json_data['unblock_type'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "'mac' and 'unblock_type' fields are required"]);
    exit();
}

$mac_address = $json_data['mac'];
$unblock_type = $json_data['unblock_type']; // 'permanent' or 'temporary'

// Validate MAC address
if (!preg_match('/^([a-f0-9]{2}:){5}[a-f0-9]{2}$/i', $mac_address)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Invalid MAC address format"]);
    exit();
}

// Load data files
$blacklisted = file_exists($blacklisted_file) ? json_decode(file_get_contents($blacklisted_file), true) ?? [] : [];
$device_db = file_exists($device_database_file) ? json_decode(file_get_contents($device_database_file), true) ?? ['devices' => []] : ['devices' => []];
$known_devices = file_exists($known_devices_file) ? json_decode(file_get_contents($known_devices_file), true) ?? [] : [];

// Check if device is blacklisted
$blacklisted_entry = null;
$blacklisted_index = -1;
foreach ($blacklisted as $index => $entry) {
    if ($entry['mac'] === $mac_address) {
        $blacklisted_entry = $entry;
        $blacklisted_index = $index;
        break;
    }
}

if (!$blacklisted_entry) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Device is not blacklisted"]);
    exit();
}

// Find device in database (look for the most recent entry with a valid IP)
$device_entry = null;
$device_index = -1;
$ip = null;

// First, try to find the most recent entry with a valid IP in device database
foreach (array_reverse($device_db['devices']) as $index => $device) {
    if ($device['mac'] === $mac_address && !empty($device['ip'])) {
        $device_entry = $device;
        $device_index = $index;
        $ip = $device['ip'];
        break;
    }
}

// If no entry with IP found in device database, check blacklisted.json
if (!$ip) {
    foreach ($blacklisted as $entry) {
        if ($entry['mac'] === $mac_address && !empty($entry['ip'])) {
            $ip = $entry['ip'];
            break;
        }
    }
}

// If still no IP found, use the most recent entry from device database (even if IP is null)
if (!$ip) {
    foreach (array_reverse($device_db['devices']) as $index => $device) {
        if ($device['mac'] === $mac_address) {
            $device_entry = $device;
            $device_index = $index;
            break;
        }
    }
    $ip = $device_entry['ip'] ?? null;
}

if (!$ip) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "IP address not found for this device"]);
    exit();
}

// Unblock device in Pi-hole (move from group 4 to group 0)
try {
    $pihole = new PiHoleClientManager(getenv('PIHOLE_URL'), getenv('PIHOLE_PASSWORD'));
    
    // Remove from group 4 and add to group 0
    $comment = $unblock_type === 'permanent' ? "Unblocked permanently" : "Unblocked temporarily";
    $pihole_result = $pihole->updateClientGroup($ip, 0, 4, $comment);
    
    if (!$pihole_result['success']) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(["status" => "error", "message" => "Failed to unblock device in Pi-hole: " . $pihole_result['error']]);
        exit();
    }
    
    // Update device database
    if ($device_index >= 0) {
        $device_db['devices'][$device_index]['added_to_pihole'] = false;
        $device_db['devices'][$device_index]['pihole_added_time'] = null;
        $device_db['devices'][$device_index]['last_updated'] = date("Y-m-d H:i:s");
        file_put_contents($device_database_file, json_encode($device_db));
    }
    
    // Remove from blacklisted
    if ($blacklisted_index >= 0) {
        array_splice($blacklisted, $blacklisted_index, 1);
        file_put_contents($blacklisted_file, json_encode($blacklisted));
    }
    
    // Add to known devices if permanent unblock
    if ($unblock_type === 'permanent' && !in_array($mac_address, $known_devices)) {
        $known_devices[] = $mac_address;
        file_put_contents($known_devices_file, json_encode($known_devices));
    }
    
    // Send Telegram notification
    $message = "✅ *Device Unblocked*\n\n" .
               "📱 MAC: `$mac_address`\n" .
               "🌐 IP: `$ip`\n" .
               "🔓 Type: " . ucfirst($unblock_type) . " unblock\n" .
               "🕐 Time: " . date("Y-m-d H:i:s") . "\n\n" .
               "Device has been successfully unblocked.";
    sendTelegramMessage($message, getenv('TELEGRAM_BOT_TOKEN'), getenv('TELEGRAM_CHAT_ID'));
    
    // Return success response
    header('Content-Type: application/json');
    echo json_encode([
        "status" => "success", 
        "message" => "Device successfully unblocked",
        "mac" => $mac_address,
        "unblock_type" => $unblock_type
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Error unblocking device: " . $e->getMessage()]);
    exit();
}