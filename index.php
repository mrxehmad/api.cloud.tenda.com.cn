<?php
// Define the file to store request data
$data_file = 'requests.json';

// Get the request URI and method
$request_uri = $_SERVER['REQUEST_URI'];
$request_method = $_SERVER['REQUEST_METHOD'];

// Handle requests to /route/mac/v1
if ($request_uri == "/route/mac/v1" && $request_method == 'POST') {
    // Read the raw JSON body of the request
    $raw_body = file_get_contents('php://input');
    
    // Debug output to inspect raw body
    error_log("Raw Body: " . $raw_body);
    
    // Decode JSON data
    $json_data = json_decode($raw_body, true);
    
    // Debug output to inspect json_data
    error_log("JSON Data: " . print_r($json_data, true));

    // Check if the "mac" field exists
    if (isset($json_data['mac'])) {
        // Proceed with saving data if "mac" is present
        $entry = [
            "time" => date("Y-m-d H:i:s"),
            "mac" => $json_data['mac']
        ];

        // Read existing data from file
        $data = [];
        if (file_exists($data_file)) {
            $data = json_decode(file_get_contents($data_file), true) ?? [];
        }

        // Append the new entry and save back to the file
        $data[] = $entry;
        file_put_contents($data_file, json_encode($data));

        // Respond with a success message
        header('Content-Type: application/json');
        echo json_encode(["status" => "success", "message" => "Data saved"]);
    } else {
        // Respond with an error if "mac" data is missing
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(["status" => "error", "message" => "'mac' field is missing"]);
    }
    exit();
}


$data = [];
if (file_exists($data_file)) {
    $data = json_decode(file_get_contents($data_file), true) ?? [];
}

// Aggregate MAC connections count
$mac_connections = [];
$hourly_activity = array_fill(0, 24, 0);

foreach ($data as $entry) {
    $mac = implode(", ", $entry['mac']);
    $time = strtotime($entry['time']);
    $hour = date("H", $time);

    // Count connections per MAC
    if (!isset($mac_connections[$mac])) {
        $mac_connections[$mac] = 0;
    }
    $mac_connections[$mac]++;

    // Count connections by hour
    $hourly_activity[(int)$hour]++;
}

// Prepare data for JavaScript
$mac_labels = json_encode(array_keys($mac_connections));
$mac_counts = json_encode(array_values($mac_connections));
$hour_labels = json_encode(range(0, 23));
$hour_counts = json_encode($hourly_activity);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAC Address Activity Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>

<div class="container mt-5">
    <h2 class="mb-4">MAC Address Activity Dashboard</h2>

    <!-- MAC Address Connections Bar Graph -->
    <div class="mb-5">
        <h4>MAC Connections Count</h4>
        <canvas id="macConnectionsChart"></canvas>
    </div>

    <!-- Hourly Activity Bar Graph -->
    <div>
        <h4>Client Activity Over the Last 24 Hours</h4>
        <canvas id="hourlyActivityChart"></canvas>
    </div>
</div>

<div class="container mt-5">
    <h2 class="mb-4">MAC Address Requests Log</h2>
    <ul class="list-group" id="request-list">
        <?php
        // Read and display the request log data
        if (file_exists($data_file)) {
            // Read the data from the file
            $data = json_decode(file_get_contents($data_file), true) ?? [];
            
            // Limit to the last 25 requests
            $data = array_slice($data, -25);
            
            // Display each entry
            foreach ($data as $entry) {
                echo "<li class='list-group-item d-flex justify-content-between align-items-center'>";
                echo "<span>" . implode(", ", $entry['mac']) . "</span>";
                echo "<span class='badge badge-primary badge-pill'>" . $entry['time'] . "</span>";
                echo "</li>";
            }
        } else {
            echo "<li class='list-group-item'>No requests logged yet.</li>";
        }
        ?>
    </ul>
</div>
 

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Data for MAC Connections Count
const macLabels = <?php echo $mac_labels; ?>;
const macCounts = <?php echo $mac_counts; ?>;
const macConnectionsCtx = document.getElementById('macConnectionsChart').getContext('2d');

new Chart(macConnectionsCtx, {
    type: 'bar',
    data: {
        labels: macLabels,
        datasets: [{
            label: 'Connections Count',
            data: macCounts,
            backgroundColor: 'rgba(54, 162, 235, 0.6)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Connections Count'
                }
            },
            x: {
                title: {
                    display: true,
                    text: 'MAC Addresses'
                }
            }
        }
    }
});

// Data for Hourly Activity
const hourLabels = <?php echo $hour_labels; ?>;
const hourCounts = <?php echo $hour_counts; ?>;
const hourlyActivityCtx = document.getElementById('hourlyActivityChart').getContext('2d');

new Chart(hourlyActivityCtx, {
    type: 'bar',
    data: {
        labels: hourLabels,
        datasets: [{
            label: 'Connections Count',
            data: hourCounts,
            backgroundColor: 'rgba(255, 99, 132, 0.6)',
            borderColor: 'rgba(255, 99, 132, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Connections Count'
                }
            },
            x: {
                title: {
                    display: true,
                    text: 'Hour of the Day'
                }
            }
        }
    }
});
</script>

</body>
</html>
