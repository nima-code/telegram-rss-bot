<?php
namespace App;

use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;

class TelegramHandler
{
    protected $telegram;
    protected $chatId;

    public function __construct(Api $telegram, string $chatId)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
        Log::info("TelegramHandler initialized for chat_id: {$this->chatId}");
    }

    protected function getConfig()
    {
        $envKey = "FEEDS_CONFIG_{$this->chatId}";
        $config = json_decode(env($envKey, '{"feeds":{},"auto_send":false}'), true);
        if ($config === null) {
            Log::error("Invalid JSON in env $envKey for chat_id: {$this->chatId}");
            return ['feeds' => [], 'auto_send' => false];
        }
        Log::info("Loaded config for chat_id: {$this->chatId}", ['config' => $config]);
        return $config;
    }

    protected function saveConfig($config)
    {
        $envKey = "FEEDS_CONFIG_{$this->chatId}";
        $jsonConfig = json_encode($config, JSON_UNESCAPED_UNICODE);
        Log::info("Please set env $envKey=$jsonConfig for chat_id: {$this->chatId}");
        return true;
    }

    protected function getReplyMarkup()
    {
        return [
            'keyboard' => [
                ['دریافت اخبار', 'نمایش فیدها'],
                ['شروع فیدها', 'توقف'],
                ['تغییر فید', 'درباره']
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
    }

    public function handleMessage($message)
    {
        Log::info("Handling message for chat_id: {$this->chatId}", ['message' => $message]);
        
        $text = isset($message['text']) ? $message['text'] : '';
        $replyMarkup = json_encode($this->getReplyMarkup());

        try {
            if ($text === '/start') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'به بات خوش اومدی! اخبار رو با دکمه‌ها مدیریت کن.',
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Sent welcome message to chat_id: {$this->chatId}");
            } elseif ($text === 'درباره') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'این بات اخبار و مطالب رو از فیدهای دلخواهت جمع می‌کنه و می‌فرسته.',
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Sent about message to chat_id: {$this->chatId}");
            } elseif ($text === 'نمایش فیدها') {
                $config = $this->getConfig();
                $feedList = !empty($config['feeds']) ? implode("\n", array_map(function ($name, $url) {
                    return "🦗 $name: " . htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                }, array_keys($config['feeds']), $config['feeds'])) : 'هیچ فیدی نیست!';
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "فید‌های فعال:\n$feedList",
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Sent feed list to chat_id: {$this->chatId}", ['feedList' => $feedList]);
            } elseif ($text === 'دریافت اخبار') {
                $this->sendLatestNews($replyMarkup);
            } elseif ($text === 'شروع فیدها') {
                $config = $this->getConfig();
                $config['auto_send'] = true;
                $this->saveConfig($config);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "ارسال خودکار اخبار فعال شد! هر ۱۵ دقیقه اخبار جدید میاد.\nلطفاً متغیر محیطی زیر رو تو Render اضافه کنید:\nFEEDS_CONFIG_{$this->chatId}=" . json_encode($config, JSON_UNESCAPED_UNICODE),
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Enabled auto-send for chat_id: {$this->chatId}");
            } elseif ($text === 'توقف') {
                $config = $this->getConfig();
                $config['auto_send'] = false;
                $this->saveConfig($config);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'ارسال خودکار اخبار متوقف شد!',
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Disabled auto-send for chat_id: {$this->chatId}");
            } elseif ($text === 'تغییر فید') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "فید جدید رو اینجوری وارد کن (هر خط یه فید):\nنام: آدرس فید\nمثال:\nخبرآنلاین: https://www.khabaronline.ir/rss",
                    'reply_markup' => json_encode(['force_reply' => true])
                ]);
                Log::info("Sent feed instructions to chat_id: {$this->chatId}");
            } elseif (isset($message['reply_to_message']) && strpos($message['reply_to_message']['text'], 'فید جدید رو اینجوری وارد کن') !== false) {
                $lines = explode("\n", $text);
                $newFeeds = [];
                foreach ($lines as $line) {
                    if (strpos($line, ':') !== false) {
                        [$name, $url] = array_map('trim', explode(':', $line, 2));
                        if (filter_var($url, FILTER_VALIDATE_URL)) {
                            $newFeeds[$name] = $url;
                        }
                    }
                }
                if (!empty($newFeeds)) {
                    $config = $this->getConfig();
                    $config['feeds'] = $newFeeds;
                    $this->saveConfig($config);
                    $feedList = implode("\n", array_map(function ($name, $url) {
                        return "🦗 $name: " . htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                    }, array_keys($newFeeds), $newFeeds));
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => "فیدهای جدید تنظیم شد:\n$feedList",
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                        'reply_markup' => $replyMarkup
                    ]);
                    Log::info("Set new feeds for chat_id: {$this->chatId}", ['feeds' => $newFeeds]);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'فید معتبر وارد کن!',
                        'reply_markup' => $replyMarkup
                    ]);
                    Log::info("Invalid feed input for chat_id: {$this->chatId}");
                }
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'دستور نامعتبر! از دکمه‌ها استفاده کن.',
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Invalid command for chat_id: {$this->chatId}: $text");
            }
        } catch (\Exception $e) {
            Log::error("Error in handleMessage for chat_id: {$this->chatId}: {$e->getMessage()}", ['exception' => $e, 'trace' => $e->getTraceAsString()]);
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'خطا: مشکلی پیش اومد. دوباره امتحان کن.',
                'reply_markup' => $replyMarkup
            ]);
        }
    }

    protected function sendLatestNews($replyMarkup)
    {
        Log::info("Processing sendLatestNews for chat_id: {$this->chatId}");
        $config = $this->getConfig();
        if (empty($config['feeds'])) {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'هیچ فیدی ثبت نشده. لطفاً با "تغییر فید" یه فید اضافه کنید.',
                'reply_markup' => $replyMarkup
            ]);
            Log::info("No feeds for chat_id: {$this->chatId}");
            return;
        }

        foreach ($config['feeds'] as $name => $url) {
            try {
                Log::info("Loading feed: $name ($url) for chat_id: {$this->chatId}");
                $xml = @simplexml_load_file($url);
                if ($xml === false) {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => "خطا در بارگذاری فید: $name ($url)",
                        'reply_markup' => $replyMarkup
                    ]);
                    Log::error("Failed to load feed: $name ($url) for chat_id: {$this->chatId}");
                    continue;
                }

                $items = $xml->channel->item;
                $latestItems = array_slice((array)$items, 0, 3);
                foreach ($latestItems as $item) {
                    $title = (string)($item->title ?? 'بدون عنوان');
                    $link = (string)($item->link ?? '#');
                    $pubDate = (string)($item->pubDate ?? 'نامشخص');
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => "📰 $name\n$title\n$link\n$pubDate",
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => false,
                        'reply_markup' => $replyMarkup
                    ]);
                    Log::info("Sent news: $title from $name for chat_id: {$this->chatId}");
                }
            } catch (\Exception $e) {
                Log::error("Error processing feed $name for chat_id: {$this->chatId}: {$e->getMessage()}");
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "خطا در پردازش فید $name: {$e->getMessage()}",
                    'reply_markup' => $replyMarkup
                ]);
            }
        }
    }
}
?>