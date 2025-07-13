<?php
namespace App;

use Telegram\Bot\Api;

class TelegramHandler
{
    protected $telegram;
    protected $chatId;

    public function __construct(Api $telegram, string $chatId)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
    }

    protected function getConfig()
    {
        $envKey = "FEEDS_CONFIG_{$this->chatId}";
        $config = json_decode(env($envKey, '{"feeds":{},"auto_send":false}'), true);
        if ($config === null) {
            file_put_contents('/tmp/telegram-webhook.log', "Invalid JSON in env $envKey\n", FILE_APPEND);
            return ['feeds' => [], 'auto_send' => false];
        }
        return $config;
    }

    protected function saveConfig($config)
    {
        $envKey = "FEEDS_CONFIG_{$this->chatId}";
        $jsonConfig = json_encode($config, JSON_UNESCAPED_UNICODE);
        file_put_contents('/tmp/telegram-webhook.log', "Please set env $envKey=$jsonConfig\n", FILE_APPEND);
        return true; // در Render باید دستی اضافه بشه
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
        file_put_contents('/tmp/telegram-webhook.log', "Handling message: " . json_encode($message) . "\n", FILE_APPEND);
        
        $text = isset($message['text']) ? $message['text'] : '';
        $replyMarkup = json_encode($this->getReplyMarkup());

        if ($text === '/start') {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'به بات خوش اومدی! اخبار رو با دکمه‌ها مدیریت کن.',
                'reply_markup' => $replyMarkup
            ]);
            file_put_contents('/tmp/telegram-webhook.log', "Sent welcome message to chat_id: {$this->chatId}\n", FILE_APPEND);
        } elseif ($text === 'دریافت اخبار') {
            $this->sendLatestNews($replyMarkup);
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
            file_put_contents('/tmp/telegram-webhook.log', "Sent feed list to chat_id: {$this->chatId}\n", FILE_APPEND);
        } elseif ($text === 'شروع فیدها') {
            $config = $this->getConfig();
            $config['auto_send'] = true;
            $this->saveConfig($config);
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => "ارسال خودکار اخبار فعال شد! هر ۱۵ دقیقه اخبار جدید میاد.\nلطفاً متغیر محیطی زیر رو تو Render اضافه کنید:\nFEEDS_CONFIG_{$this->chatId}=" . json_encode($config, JSON_UNESCAPED_UNICODE),
                'reply_markup' => $replyMarkup
            ]);
            file_put_contents('/tmp/telegram-webhook.log', "Enabled auto-send for chat_id: {$this->chatId}\n", FILE_APPEND);
        } elseif ($text === 'توقف') {
            $config = $this->getConfig();
            $config['auto_send'] = false;
            $this->saveConfig($config);
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'ارسال خودکار اخبار متوقف شد!',
                'reply_markup' => $replyMarkup
            ]);
            file_put_contents('/tmp/telegram-webhook.log', "Disabled auto-send for chat_id: {$this->chatId}\n", FILE_APPEND);
        } elseif ($text === 'تغییر فید') {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => "فید جدید رو اینجوری وارد کن (هر خط یه فید):\nنام: آدرس فید\nمثال:\nخبرآنلاین: https://www.khabaronline.ir/rss",
                'reply_markup' => json_encode(['force_reply' => true])
            ]);
            file_put_contents('/tmp/telegram-webhook.log', "Sent feed instructions to chat_id: {$this->chatId}\n", FILE_APPEND);
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
                file_put_contents('/tmp/telegram-webhook.log', "Set new feeds for chat_id: {$this->chatId}: " . json_encode($newFeeds) . "\n", FILE_APPEND);
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'فید معتبر وارد کن!',
                    'reply_markup' => $replyMarkup
                ]);
                file_put_contents('/tmp/telegram-webhook.log', "Invalid feed input for chat_id: {$this->chatId}\n", FILE_APPEND);
            }
        } elseif ($text === 'درباره') {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'این بات اخبار و مطالب رو از فیدهای دلخواهت جمع می‌کنه و می‌فرسته.',
                'reply_markup' => $replyMarkup
            ]);
            file_put_contents('/tmp/telegram-webhook.log', "Sent about message to chat_id: {$this->chatId}\n", FILE_APPEND);
        } else {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'دستور نامعتبر! از دکمه‌ها استفاده کن.',
                'reply_markup' => $replyMarkup
            ]);
            file_put_contents('/tmp/telegram-webhook.log', "Invalid command for chat_id: {$this->chatId}: $text\n", FILE_APPEND);
        }
    }

    protected function sendLatestNews($replyMarkup)
    {
        $config = $this->getConfig();
        if (empty($config['feeds'])) {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'هیچ فیدی ثبت نشده. لطفاً با "تغییر فید" یه فید اضافه کنید.',
                'reply_markup' => $replyMarkup
            ]);
            file_put_contents('/tmp/telegram-webhook.log', "No feeds for chat_id: {$this->chatId}\n", FILE_APPEND);
            return;
        }

        foreach ($config['feeds'] as $name => $url) {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => "در حال بررسی فید: $name ($url)",
                'reply_markup' => $replyMarkup
            ]);
            file_put_contents('/tmp/telegram-webhook.log', "Checking feed: $name ($url) for chat_id: {$this->chatId}\n", FILE_APPEND);
            // منطق بررسی فید باید تو CheckFeedsController پیاده‌سازی بشه
        }
    }
}
?>