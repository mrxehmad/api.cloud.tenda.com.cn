<?php
$data_file = 'requests.json';
$known_devices_file = 'known_devices.json';
$telegram_bot_token = 'YOUR_BOT_TOKEN';
$telegram_chat_id = 'CHAT_ID';

function sendTelegramMessage($message) {
    global $telegram_bot_token, $telegram_chat_id;
    $url = "https://api.telegram.org/bot$telegram_bot_token/sendMessage";
    $data = ['chat_id' => $telegram_chat_id, 'text' => $message];
    
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
        ]
    ];
    $context = stream_context_create($options);
    file_get_contents($url, false, $context);
}

$known_devices = file_exists($known_devices_file) ? json_decode(file_get_contents($known_devices_file), true) ?? [] : [];

$request_uri = $_SERVER['REQUEST_URI'];
$request_method = $_SERVER['REQUEST_METHOD'];

if (file_exists($data_file)) {
    $data = json_decode(file_get_contents($data_file), true) ?? [];
    $cleaned_data = [];

    foreach ($data as $entry) {
        $entry_time = strtotime($entry['time']);
        if ($entry_time >= (time() - 7 * 24 * 60 * 60)) {
            $cleaned_data[] = $entry;
        }
    }
    file_put_contents($data_file, json_encode($cleaned_data));
}

if ($request_uri == "/route/mac/v1" && $request_method == 'POST') {
    $raw_body = file_get_contents('php://input');
    $json_data = json_decode($raw_body, true);

    if (isset($json_data['mac'])) {
        $mac_address = is_array($json_data['mac']) ? implode(", ", $json_data['mac']) : $json_data['mac'];
        
        $mac_address = str_replace('-', ':', $mac_address);
        
        $entry = [
            "time" => date("Y-m-d H:i:s"),
            "mac" => $mac_address
        ];

        $data = file_exists($data_file) ? json_decode(file_get_contents($data_file), true) ?? [] : [];
        $data[] = $entry;
        file_put_contents($data_file, json_encode($data));

        $mac_list = explode(", ", $mac_address);
        $unknown_macs = array_filter($mac_list, fn($mac) => !in_array($mac, $known_devices));
        
        if (!empty($unknown_macs)) {
            sendTelegramMessage("New Unknown MAC Address Logged: " . implode(", ", $unknown_macs) . " at " . $entry['time']);
        }

        header('Content-Type: application/json');
        echo json_encode(["status" => "success", "message" => "Data saved"]);
    } else {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(["status" => "error", "message" => "'mac' field is missing"]);
    }
    exit();
}

$data = file_exists($data_file) ? json_decode(file_get_contents($data_file), true) ?? [] : [];
$mac_connections = [];
$hourly_activity = array_fill(0, 24, 0);


foreach ($data as $entry) {
    $time = strtotime($entry['time']);
    $hour = date("H", $time);

    if (!is_array($entry['mac'])) {
        $mac_list = explode(", ", (string)$entry['mac']);
    } else {
        $mac_list = $entry['mac'];
    }

    foreach ($mac_list as $mac) {
        if (!isset($mac_connections[$mac])) {
            $mac_connections[$mac] = 0;
        }
        $mac_connections[$mac]++;
    }

    $hourly_activity[(int)$hour]++;
}



$hour_labels = json_encode(range(0, 23));
$hour_counts = json_encode($hourly_activity);
$mac_labels = json_encode(array_keys($mac_connections));
$mac_counts = json_encode(array_values($mac_connections));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAC Address Activity Dashboard</title>
    <link rel="stylesheet" href="bootstrap.min.css">
</head>
<body>

<div class="container mt-5">
    <h2 class="mb-4">MAC Address Activity Dashboard</h2>
    <div class="mb-5">
        <h4>MAC Connections Count</h4>
      <canvas id="macConnectionsChart"></canvas>
    </div>
    <div>
        <h4>Client Activity Over the Last 24 Hours</h4>
        <canvas id="hourlyActivityChart"></canvas>
    </div>
</div>

<div class="container mt-5">
    <h2 class="mb-4">MAC Address Requests Log</h2>
    <ul class="list-group" id="request-list">
        <?php
        if (file_exists($data_file)) {
            $data = json_decode(file_get_contents($data_file), true) ?? [];
            $last_requests = array_slice($data, -25);
            foreach ($last_requests as $entry) {
                echo "<li class='list-group-item d-flex justify-content-between align-items-center'>";
               echo "<span>MAC: " . htmlspecialchars(is_array($entry['mac']) ? implode(", ", $entry['mac']) : $entry['mac']) . "</span>"; 
              echo "<span class='badge badge-primary badge-pill'>" . htmlspecialchars($entry['time']) . "</span>";
                echo "</li>";
            }
        } else {
            echo "<li class='list-group-item'>No requests logged yet.</li>";
        }
        ?>
    </ul>
</div>

<script src="chart.js"></script>
<script>
const hourLabels = <?php echo json_encode(range(0, 23)); ?>;
const hourCounts = <?php echo json_encode(array_map('intval', $hourly_activity)); ?>;
const hourlyCtx = document.getElementById('hourlyActivityChart').getContext('2d');

new Chart(hourlyCtx, {
    type: 'line',
    data: {
        labels: hourLabels,
        datasets: [{
            label: 'Client Activity',
            data: hourCounts,
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            borderColor: 'rgba(75, 192, 192, 1)',
            borderWidth: 2,
            fill: true
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, title: { display: true, text: 'Activity Count' } },
            x: { title: { display: true, text: 'Hour of the Day' } }
        }
    }
});
document.addEventListener("DOMContentLoaded", function () {
    var canvas = document.getElementById('macConnectionsChart');
    
    if (!canvas) {
        console.error("Canvas element #macConnectionsChart not found!");
        return;
    }

    var ctx = canvas.getContext('2d');

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo $mac_labels; ?>,
            datasets: [{
                label: 'Connections Count',
                data: <?php echo $mac_counts; ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true },
                x: { title: { display: true, text: 'MAC Addresses' } }
            }
        }
    });
});

</script>
</body>
</html>
