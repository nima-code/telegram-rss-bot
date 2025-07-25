<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api;
use App\TelegramHandler;

class FeedWorker extends Command
{
    protected $signature = 'feeds:worker';
    protected $description = 'Run feed checks every 15 minutes';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
        while (true) {
            $envVars = array_filter($_ENV, function($key) {
                return strpos($key, 'FEEDS_CONFIG_') === 0;
            }, ARRAY_FILTER_USE_KEY);

            foreach ($envVars as $key => $value) {
                $chatId = str_replace('FEEDS_CONFIG_', '', $key);
                try {
                    $handler = new TelegramHandler($telegram, $chatId);
                    $handler->checkAndSendFeeds();
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("Error in feed worker for chat_id: $chatId: {$e->getMessage()}");
                }
            }

            sleep(900); // 15 دقیقه
        }
    }
}
?>