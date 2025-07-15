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
            $chatId = '1428476584'; // می‌تونی اینو از یه فایل تنظیمات بخونی
            $handler = new \App\TelegramHandler($telegram, $chatId);
            $replyMarkup = json_encode($handler->getReplyMarkup());
            $handler->sendLatestNews($replyMarkup);
            
            Log::info('Cron job executed successfully for chat_id: ' . $chatId);
            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error("Error in CheckFeedsController: {$e->getMessage()}", ['exception' => $e]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
?>