<?php
$request_uri = $_SERVER['REQUEST_URI'];
$request_method = $_SERVER['REQUEST_METHOD'];

// Route for MAC logging API
if ($request_uri === '/route/mac/v1' && $request_method === 'POST') {
    require __DIR__ . '/api/mac_logger.php';
    exit();
}

// Default: show dashboard
header('Location: /dashboard.php');
exit();