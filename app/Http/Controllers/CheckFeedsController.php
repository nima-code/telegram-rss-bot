<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class CheckFeedsController extends Controller
{
    public function check()
    {
        Log::info('CheckFeedsController::check called');
        
        try {
            $token = env('TELEGRAM_BOT_TOKEN');
            if (!$token) {
                throw new \Exception('TELEGRAM_BOT_TOKEN is not set');
            }
            
            $telegram = new Api($token);
            $envVars = array_filter($_ENV, function($key) {
                return strpos($key, 'FEEDS_CONFIG_') === 0;
            }, ARRAY_FILTER_USE_KEY);

            foreach ($envVars as $key => $value) {
                $chatId = str_replace('FEEDS_CONFIG_', '', $key);
                $handler = new \App\TelegramHandler($telegram, $chatId);
                $handler->checkAndSendFeeds();
                Log::info("Processed feeds for chat_id: $chatId");
            }
            
            Log::info('Cron job executed successfully');
            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error("Error in CheckFeedsController: {$e->getMessage()}", ['exception' => $e]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
?>