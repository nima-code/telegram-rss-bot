<?php
use App\TelegramHandler;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Storage;
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
        }
        return response('OK', 200);
    } catch (\Exception $e) {
        Log::error("Webhook error: {$e->getMessage()}");
        return response('Error processing webhook', 500);
    }
});

$router->get('/check-feeds', function () use ($router) {
    set_time_limit(120); // جلوگیری از تایم‌اوت
    try {
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
    } catch (\Exception $e) {
        Log::error("Check-feeds error: {$e->getMessage()}");
        return response()->json(['status' => 'error', 'message' => 'Failed to process feeds'], 500);
    }
});

$router->get('/test-feed', function () use ($router) {
    try {
        $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
        $url = $router->app->request->query('url');
        $chatId = $router->app->request->query('chat_id', '1428476584');

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or missing URL'], 400);
        }

        $handler = new TelegramHandler($telegram, $chatId);
        $result = $handler->testFeed($url);
        return response()->json($result);
    } catch (\Exception $e) {
        Log::error("Test-feed error: {$e->getMessage()}");
        return response()->json(['status' => 'error', 'message' => 'Failed to test feed'], 500);
    }
});