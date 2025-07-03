<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use App\Services\TelegramHandler;
use Illuminate\Support\Facades\Log;

class TelegramHandlerController extends Controller
{
    public function handleWebhook(Request $request)
    {
        try {
            $update = $request->all();
            if (!isset($update['message']) && !isset($update['callback_query'])) {
                Log::warning("Invalid webhook update received: " . json_encode($update));
                return response()->json(['status' => 'invalid update'], 400);
            }

            $chatId = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
            if (!$chatId) {
                Log::error("No chat_id in webhook update: " . json_encode($update));
                return response()->json(['status' => 'no chat_id'], 400);
            }

            $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
            $handler = new TelegramHandler($telegram, $chatId);
            $handler->handleMessage($update['message'] ?? $update['callback_query']);

            return response()->json(['status' => 'ok'], 200);
        } catch (\Exception $e) {
            Log::error("Webhook error: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}