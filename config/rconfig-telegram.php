<?php

return [
    // Operator-managed secrets: never expose via HTTP, logs, templates or Git.
    // No Telegram delivery happens until a reviewed event dispatcher calls the transport.
    'enabled' => env('RCONFIG_TELEGRAM_ENABLED', false),
    'bot_token' => env('RCONFIG_TELEGRAM_BOT_TOKEN', ''),
    'chat_id' => env('RCONFIG_TELEGRAM_CHAT_ID', ''),
];
