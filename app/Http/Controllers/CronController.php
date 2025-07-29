<?php
namespace App\Http\Controllers;

use App\TelegramHandler;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;

class CronController extends Controller
{
    public function checkFeeds()
    {
        Log::info("CronController: Starting check-feeds for all chats");
        try {
            $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
            $configDir = storage_path('feeds');
            $files = scandir($configDir);

            foreach ($files as $file) {
                if (preg_match('/(\d+)\.json$/', $file, $matches)) {
                    $chatId = $matches[1];
                    Log::info("Processing check-feeds for chat_id: {$chatId}");
                    $handler = new TelegramHandler($telegram, $chatId);
                    $handler->checkAndSendFeeds();
                }
            }

            return response()->json(['status' => 'success', 'message' => 'Check feeds completed']);
        } catch (\Exception $e) {
            Log::error("CronController: Failed to process check-feeds: {$e->getMessage()}");
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
?>