<?php
function sendTelegramMessage($message, $telegram_bot_token, $telegram_chat_id) {
    $url = "https://d.litewi5993.workers.dev/123/https/api.telegram.org/bot$telegram_bot_token/sendMessage";
    $data = ['chat_id' => $telegram_chat_id, 'text' => $message];
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
        ]
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    return $result;
} 