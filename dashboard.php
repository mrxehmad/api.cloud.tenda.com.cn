<?php
$data_file = 'requests.json';
$known_devices_file = 'known_devices.json';
date_default_timezone_set('Asia/Karachi');

$data = file_exists($data_file) ? json_decode(file_get_contents($data_file), true) ?? [] : [];
$mac_connections = [];
$hourly_activity = array_fill(0, 24, 0);

foreach ($data as $entry) {
    $time = strtotime($entry['time']);
    $hour = date("H", $time);
    $mac_entry = $entry['mac'];
    $mac_list = is_array($mac_entry) ? $mac_entry : explode(", ", (string)$mac_entry);
    foreach ($mac_list as $mac) {
        if (!isset($mac_connections[$mac])) {
            $mac_connections[$mac] = 0;
        }
        $mac_connections[$mac]++;
    }
    $hourly_activity[(int)$hour]++;
}
$mac_labels = json_encode(array_keys($mac_connections));
$mac_counts = json_encode(array_values($mac_connections));
$hour_labels = json_encode(range(0, 23));
$hour_counts = json_encode(array_values($hourly_activity));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Activity Dashboard</title>
    <link rel="stylesheet" href="https://file.ehmi.se/Guest/cdn/bootstrap.min.css">
    <style>
        .chart-container { margin-bottom: 30px; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); background-color: #fff; }
        .chart-title { margin-bottom: 20px; color: #333; font-weight: 600; }
        canvas { width: 100% !important; height: 300px !important; }
        body { background-color: #f8f9fa; padding-bottom: 60px; }
        .container { max-width: 1200px; }
        footer { position: fixed; bottom: 0; width: 100%; }
    </style>
</head>
<body>
<div class="container mt-4">
    <h1 class="mb-4 text-center">Network Activity Dashboard</h1>
    <div class="row">
        <div class="col-md-6">
            <div class="chart-container">
                <h3 class="chart-title">MAC Address Activity</h3>
                <canvas id="macConnectionsChart"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-container">
                <h3 class="chart-title">Hourly Activity Distribution</h3>
                <canvas id="hourlyActivityChart"></canvas>
            </div>
        </div>
    </div>
    <div class="chart-container mt-4">
        <h3 class="chart-title">Recent MAC Requests</h3>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>MAC Address</th>
                        <th>IP</th>
                        <th>Known</th>
                        <th>Added to Pi-hole</th>
                    </tr>
                </thead>
                <tbody id="request-list">
                    <?php
                    $requests = file_exists('requests.json') ? json_decode(file_get_contents('requests.json'), true) ?? [] : [];
                    // Limit to most recent 20 entries
                    $recent_requests = array_slice(array_reverse($requests), 0, 20);
                    foreach ($recent_requests as $entry):
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($entry['time']) ?></td>
                        <td><?= htmlspecialchars($entry['mac']) ?></td>
                        <td><?= isset($entry['ip']) && $entry['ip'] ? htmlspecialchars($entry['ip']) : 'Not found' ?></td>
                        <td><?= isset($entry['known']) && $entry['known'] ? 'Yes' : 'No' ?></td>
                        <td><?= isset($entry['added_to_pihole']) && $entry['added_to_pihole'] ? 'Yes' : 'No' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="chart-container mt-4">
        <h3 class="chart-title">Blacklisted Devices</h3>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>MAC Address</th>
                        <th>IP</th>
                        <th>First Seen</th>
                        <th>Blacklisted Time</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="blacklisted-devices">
                    <?php
                    $blacklisted = file_exists('blacklisted.json') ? json_decode(file_get_contents('blacklisted.json'), true) ?? [] : [];
                    foreach (array_reverse($blacklisted) as $entry):
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($entry['mac']) ?></td>
                        <td><?= isset($entry['ip']) && $entry['ip'] ? htmlspecialchars($entry['ip']) : 'Not found' ?></td>
                        <td><?= htmlspecialchars($entry['first_seen']) ?></td>
                        <td><?= htmlspecialchars($entry['blacklisted_time']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-success unblock-btn" data-mac="<?= htmlspecialchars($entry['mac']) ?>" data-ip="<?= htmlspecialchars($entry['ip'] ?? '') ?>">Unblock</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($blacklisted)): ?>
                    <tr>
                        <td colspan="5" class="text-center">No blacklisted devices</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Unblock Modal -->
<div class="modal fade" id="unblockModal" tabindex="-1" aria-labelledby="unblockModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="unblockModalLabel">Unblock Device</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to unblock this device?</p>
        <p><strong>MAC:</strong> <span id="unblockMac"></span></p>
        <p><strong>IP:</strong> <span id="unblockIp"></span></p>
        <div class="mb-3">
            <label class="form-label">Unblock Type:</label>
            <div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="unblockType" id="permanent" value="permanent" checked>
                    <label class="form-check-label" for="permanent">
                        Permanent (add to known devices)
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="unblockType" id="temporary" value="temporary">
                    <label class="form-check-label" for="temporary">
                        Temporary (don't add to known devices)
                    </label>
                </div>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmUnblock">Unblock</button>
      </div>
    </div>
  </div>
</div>
<footer class="text-center p-3 bg-dark text-white">
    <h4 id="clock"></h4>
</footer>
<script src="https://file.ehmi.se/Guest/cdn/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // MAC Connections Chart
    const macCtx = document.getElementById('macConnectionsChart').getContext('2d');
    const macLabels = <?php echo $mac_labels; ?>;
    const macCounts = <?php echo $mac_counts; ?>;
    new Chart(macCtx, {
        type: 'bar',
        data: {
            labels: macLabels,
            datasets: [{
                label: 'Connections Count',
                data: macCounts,
                backgroundColor: 'rgba(255, 99, 132, 0.6)',
                borderColor: 'rgba(255, 99, 132, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true },
                x: { title: { display: true, text: 'MAC Addresses' }, ticks: { maxRotation: 45, minRotation: 45 } }
            }
        }
    });
    // Hourly Activity Chart
    const hourlyCtx = document.getElementById('hourlyActivityChart').getContext('2d');
    const hourLabels = <?php echo $hour_labels; ?>;
    const hourCounts = <?php echo $hour_counts; ?>;
    new Chart(hourlyCtx, {
        type: 'line',
        data: {
            labels: hourLabels,
            datasets: [{
                label: 'Client Activity',
                data: hourCounts,
                backgroundColor: 'rgba(153, 102, 255, 0.2)',
                borderColor: 'rgba(153, 102, 255, 1)',
                borderWidth: 2,
                fill: true,
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Activity Count' } },
                x: { title: { display: true, text: 'Hour of the Day' }, ticks: { callback: function(value) { return value + ':00'; } } }
            }
        }
    });
    // Live Clock
    function updateClock() {
        const now = new Date();
        const timeString = now.toLocaleTimeString();
        document.getElementById('clock').textContent = "Current Time: " + timeString;
    }
    setInterval(updateClock, 1000);
    updateClock();
    
    // Unblock functionality
    let currentMac = '';
    let currentIp = '';
    
    // Show unblock modal
    document.querySelectorAll('.unblock-btn').forEach(button => {
        button.addEventListener('click', function() {
            currentMac = this.getAttribute('data-mac');
            currentIp = this.getAttribute('data-ip');
            
            document.getElementById('unblockMac').textContent = currentMac;
            document.getElementById('unblockIp').textContent = currentIp || 'Not found';
            
            // Reset radio buttons
            document.getElementById('permanent').checked = true;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('unblockModal'));
            modal.show();
        });
    });
    
    // Confirm unblock
    document.getElementById('confirmUnblock').addEventListener('click', function() {
        const unblockType = document.querySelector('input[name="unblockType"]:checked').value;
        
        // Send unblock request
        fetch('/api/unblock_device.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                mac: currentMac,
                unblock_type: unblockType
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                alert('Device successfully unblocked!');
                // Reload the page to update the tables
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
        
        // Hide modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('unblockModal'));
        modal.hide();
    });
});
</script>
</body>
</html> 