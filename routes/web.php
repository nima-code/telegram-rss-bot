<?php
/** @var \Laravel\Lumen\Routing\Router $router */

use App\TelegramHandler;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;

$router->get('/', function () use ($router) {
    return 'Lumen RSS Bot is running!';
});

$router->post('/telegram/webhook', function () use ($router) {
    try {
        $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
        $update = $telegram->getWebhookUpdate();
        $chatId = $update['message']['chat']['id'] ?? null;

        if ($chatId) {
            $handler = new TelegramHandler($telegram, (string)$chatId);
            $handler->handleMessage($update['message']);
            Log::info("Processed webhook for chat_id: $chatId");
        } else {
            Log::warning('No chat_id found in webhook update');
        }
    } catch (\Exception $e) {
        Log::error("Webhook error: {$e->getMessage()}");
    }

    return response('OK', 200);
});

$router->get('/health', function () {
    Log::info('Health check called at ' . now());
    return response('OK', 200);
});
?>