<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api;
use App\TelegramHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
        Log::info('FeedWorker started');

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
                        sleep(7); // تأخیر ۷ ثانیه بین هر چت
                    } catch (\Exception $e) {
                        Log::error("Error in feed worker for chat_id: $chatId: {$e->getMessage()}");
                    }
                }
            } catch (\Exception $e) {
                Log::error("General error in FeedWorker: {$e->getMessage()}");
            }

            Log::info('FeedWorker sleeping for 10 minutes');
            sleep(600); // ۱۰ دقیقه
        }
    }

    protected function processSingleFeed($handler, $chatId)
    {
        $config = $handler->getConfig();
        if (!empty($config['feeds']) && !empty($config['auto_send'])) {
            foreach ($config['feeds'] as $name => $feedUrl) {
                try {
                    $cacheKey = "feed_{$chatId}_{$name}";
                    $feed = Cache::remember($cacheKey, 600, function () use ($feedUrl) {
                        Log::info("Fetching feed: $feedUrl");
                        $response = (new \GuzzleHttp\Client(['timeout' => 10]))->get($feedUrl);
                        return simplexml_load_string($response->getBody()->getContents(), 'SimpleXMLElement', LIBXML_NOCDATA);
                    });
                    $items = array_slice($feed->channel->item ?? [], 0, 8); // محدود به ۸ آیتم
                    foreach ($items as $item) {
                        $handler->sendFeedItem($item, $name);
                        sleep(3); // تأخیر ۳ ثانیه بین هر آیتم
                    }
                    Log::info("Processed feed $name for chat_id: $chatId");
                    sleep(7); // تأخیر ۷ ثانیه بین هر فید
                } catch (\Exception $e) {
                    Log::error("Failed to process feed $name for chat_id: $chatId: {$e->getMessage()}");
                }
            }
        }
    }
}
?>