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

$router->get('/check-feeds', function () use ($router) {
    $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    
    $envVars = array_filter($_ENV, function($key) {
        return strpos($key, 'FEEDS_CONFIG_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    foreach ($envVars as $key => $value) {
        $chatId = str_replace('FEEDS_CONFIG_', '', $key);
        $handler = new TelegramHandler($telegram, $chatId);
        $handler->checkAndSendFeeds();
    }

    return response('Feed check completed', 200);
});

$router->get('/test-feed', function () use ($router) {
    $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    $url = $router->app->request->query('url');
    $chatId = $router->app->request->query('chat_id', '1428476584');
    
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        return response()->json(['status' => 'error', 'message' => 'Invalid or missing URL'], 400);
    }

    $handler = new TelegramHandler($telegram, $chatId);
    $result = $handler->testFeed($url);
    return response()->json($result);
});
?>