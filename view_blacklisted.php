<?php
// View current blacklisted devices
$blacklisted_file = 'blacklisted.json';

if (file_exists($blacklisted_file)) {
    $blacklisted = json_decode(file_get_contents($blacklisted_file), true) ?? [];
    
    echo "Blacklisted Devices:\n";
    echo str_repeat("-", 50) . "\n";
    
    if (empty($blacklisted)) {
        echo "No blacklisted devices found.\n";
    } else {
        foreach ($blacklisted as $device) {
            echo "MAC: " . $device['mac'] . "\n";
            echo "IP: " . ($device['ip'] ?? 'Not found') . "\n";
            echo "First Seen: " . $device['first_seen'] . "\n";
            echo "Blacklisted Time: " . $device['blacklisted_time'] . "\n";
            echo str_repeat("-", 30) . "\n";
        }
    }
} else {
    echo "Blacklisted devices file not found.\n";
}