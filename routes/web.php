<?php
use App\TelegramHandler;
use App\FeedProcessor;
use App\ImageHandler;
use App\ConfigHandler;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

$router->get('/', function () use ($router) {
    return 'Lumen RSS Bot is running!';
});

$router->post('/telegram/webhook', function () use ($router) {
    $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    $update = $telegram->getWebhookUpdate();
    $chatId = $update['message']['chat']['id'] ?? null;

    if ($chatId) {
        $configHandler = new ConfigHandler($chatId);
        $imageHandler = new ImageHandler($chatId);
        $feedProcessor = new FeedProcessor($telegram, $chatId, $imageHandler);
        $handler = new TelegramHandler($telegram, $chatId, $configHandler, $feedProcessor);
        $handler->handleMessage($update['message']);
    }

    return response('OK', 200);
});

$router->get('/check-feeds', function () use ($router) {
    $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    
    $feedFiles = Storage::files('feeds');
    $processedChats = [];
    
    foreach ($feedFiles as $file) {
        if (preg_match('/feeds\/(\d+)\.json/', $file, $matches)) {
            $chatId = $matches[1];
            try {
                $configHandler = new ConfigHandler($chatId);
                $imageHandler = new ImageHandler($chatId);
                $feedProcessor = new FeedProcessor($telegram, $chatId, $imageHandler);
                $handler = new TelegramHandler($telegram, $chatId, $configHandler, $feedProcessor);
                $config = $configHandler->getConfig();
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
    $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    $url = $router->app->request->query('url');
    $chatId = $router->app->request->query('chat_id', '1428476584');
    
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        return response()->json(['status' => 'error', 'message' => 'Invalid or missing URL'], 400);
    }

    $imageHandler = new ImageHandler($chatId);
    $feedProcessor = new FeedProcessor($telegram, $chatId, $imageHandler);
    $result = $feedProcessor->testFeed($url);
    return response()->json($result);
});
?>