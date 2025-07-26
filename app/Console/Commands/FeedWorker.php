<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api;
use App\Telegram\TelegramHandler;

class FeedWorker extends Command
{
    protected $signature = 'feeds:worker';
    protected $description = 'Run feed checks every 10 minutes, processing up to 8 items per feed';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
        \Log::info('FeedWorker started');

        while (true) {
            try {
                $envVars = array_filter($_ENV, function($key) {
                    return strpos($key, 'FEEDS_CONFIG_') === 0;
                }, ARRAY_FILTER_USE_KEY);

                foreach ($envVars as $key => $value) {
                    $chatId = str_replace('FEEDS_CONFIG_', '', $key);
                    try {
                        $handler = new TelegramHandler($telegram, $chatId);
                        $this->processSingleFeed($handler, $chatId);
                        sleep(5); // تأخیر ۵ ثانیه بین هر چت
                    } catch (\Exception $e) {
                        \Log::error("Error in feed worker for chat_id: $chatId: {$e->getMessage()}");
                    }
                }
            } catch (\Exception $e) {
                \Log::error("General error in FeedWorker: {$e->getMessage()}");
            }

            // خواب طولانی‌تر برای کاهش مصرف منابع
            sleep(600); // 10 دقیقه
        }
    }

    protected function processSingleFeed($handler, $chatId)
    {
        $config = $handler->getConfig();
        if (!empty($config['feeds']) && !empty($config['auto_send'])) {
            foreach ($config['feeds'] as $name => $feedUrl) {
                try {
                    $feed = simplexml_load_file($feedUrl, null, LIBXML_NOCDATA);
                    $items = array_slice($feed->channel->item, 0, 8); // محدود به ۸ آیتم
                    foreach ($items as $item) {
                        $handler->sendFeedItem($item, $name);
                        sleep(2); // تأخیر ۲ ثانیه بین هر آیتم
                    }
                    \Log::info("Processed feed $name for chat_id: $chatId");
                } catch (\Exception $e) {
                    \Log::error("Failed to process feed $name for chat_id: $chatId: {$e->getMessage()}");
                }
                sleep(5); // تأخیر ۵ ثانیه بین هر فید
            }
        }
    }
}
?>