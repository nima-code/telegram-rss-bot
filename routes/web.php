<?php
use App\TelegramHandler;
use Telegram\Bot\Api;

$router->get('/', function () use ($router) {
    return 'Lumen RSS Bot is running!';
});

$router->post('/telegram/webhook', function () use ($router) {
    $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    $update = $telegram->getWebhookUpdate();
    $chatId = $update['message']['chat']['id'] ?? null;

    if ($chatId) {
        $handler = new TelegramHandler($telegram, (string)$chatId);
        $handler->handleMessage($update['message']);
    }

    return response('OK', 200);
});
?>