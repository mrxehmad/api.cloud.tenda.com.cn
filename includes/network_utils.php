<?php
// Returns the IP address for a given MAC address using ping + ip neigh
function findIpByMacNmap($macAddress, $interface = null) {
    // Use environment variable if not specified, fallback to 'eth0'
    if ($interface === null) {
        $interface = getenv('NETWORK_INTERFACE') ?: 'eth0';
    }
    
    // Normalize MAC address to lower case and with colons
    $macAddress = strtolower(str_replace(['-', ' '], ':', $macAddress));
    
    // Get IP range from environment or use defaults
    $ip_base = getenv('IP_BASE') ?: '10.1.15';
    $start_ip = getenv('IP_START') ?: '100';
    $end_ip = getenv('IP_END') ?: '200';
    
    // Step 1: Ping all IPs in range to populate ARP table
    $ping_processes = [];
    for ($i = $start_ip; $i <= $end_ip; $i++) {
        $ip = $ip_base . '.' . $i;
        $cmd = "ping -c 1 -W 1 $ip > /dev/null 2>&1 &";
        exec($cmd);
        $ping_processes[] = $i;
    }
    
    // Wait for all ping processes to complete
    sleep(2);
    
    // Step 2: Search ARP table for the MAC address
    $arp_output = [];
    exec("ip neigh", $arp_output);
    
    foreach ($arp_output as $line) {
        // Parse ARP table line: IP dev interface lladdr MAC state
        // Example: 10.1.15.113 dev enp4s0 lladdr 4c:d3:af:32:e2:f4 DELAY
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+dev\s+\S+\s+lladdr\s+([a-f0-9:]{17})\s+(\S+)/i', $line, $matches)) {
            $ip = $matches[1];
            $mac = strtolower($matches[2]);
            $state = $matches[3];
            
            // Only consider entries that are not FAILED
            if ($state !== 'FAILED' && $mac === $macAddress) {
                return $ip;
            }
        }
    }
    
    return null; // Not found
}