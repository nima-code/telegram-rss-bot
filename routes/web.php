<?php
use Laravel\Lumen\Routing\Router;
use Telegram\Bot\Api;
use App\TelegramHandler;
use Illuminate\Support\Facades\Log;

$router->get('/', function () use ($router) {
    return 'Lumen RSS Bot is running!';
});

$router->post('/telegram/webhook', function () use ($router) {
    $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    $update = $telegram->getWebhookUpdate();
    $handler = new TelegramHandler($telegram, $update['message']['chat']['id']);
    $handler->handle($update);
    return response()->json(['status' => 'ok']);
});

$router->get('/check-feeds', function () use ($router) {
    set_time_limit(150); // افزایش به 150 ثانیه
    $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    $feedFiles = Storage::files('feeds');
    $processedChats = [];

    foreach ($feedFiles as $file) {
        if (preg_match('/feeds\/(\d+)\.json/', $file, $matches)) {
            $chatId = $matches[1];
            try {
                $handler = new TelegramHandler($telegram, $chatId);
                $config = $handler->getConfig();
                if ($config['auto_send'] === true) {
                    $handler->checkAndSendFeeds();
                    $processedChats[] = $chatId;
                    Log::info("Processed feeds for chat_id: $chatId", ['config' => $config]);
                } else {
                    Log::info("Skipping chat_id: $chatId due to auto_send being disabled");
                }
            } catch (\Exception $e) {
                Log::error("Error processing feeds for chat_id: $chatId: {$e->getMessage()}");
            }
        }
    }

    return response()->json([
        'status' => 'ok',
        'processed_chats' => $processedChats,
        'message' => 'Feed check completed for ' . count($processedChats) . ' chats'
    ], 200, ['Cache-Control' => 'no-cache']);
});

$router->get('/test-feed', function () use ($router) {
    $url = request()->input('url');
    $chatId = request()->input('chat_id');
    if (!$url || !$chatId) {
        return response()->json(['status' => 'error', 'message' => 'Missing url or chat_id'], 400);
    }
    $feedManager = new App\FeedManager($chatId);
    return response()->json($feedManager->testFeed($url));
});