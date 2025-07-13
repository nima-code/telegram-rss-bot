<?php
namespace App;

use Telegram\Bot\Api;
use Illuminate\Support\Facades\Cache;

class TelegramHandler
{
    protected $telegram;
    protected $chatId;

    public function __construct(Api $telegram, $chatId)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
    }

    public function handleMessage($message)
    {
        file_put_contents('/tmp/telegram-webhook.log', "Handling message: " . json_encode($message) . "\n", FILE_APPEND);
        
        if (isset($message['text'])) {
            $text = $message['text'];
            
            if ($text === '/start') {
                $this->sendWelcomeMessage();
            } elseif (strpos($text, ':') !== false) {
                $this->addFeed($text);
            } elseif ($text === 'دریافت اخبار') {
                $this->sendLatestNews();
            } elseif ($text === 'شروع فیدها') {
                $this->enableAutoSend();
            } elseif ($text === 'تغییر فید') {
                $this->sendFeedInstructions();
            } else {
                $this->sendHelpMessage();
            }
        }
        
        file_put_contents('/tmp/telegram-webhook.log', "Message processed for chat_id: {$this->chatId}\n", FILE_APPEND);
    }

    protected function sendWelcomeMessage()
    {
        $keyboard = [
            ['تغییر فید', 'دریافت اخبار'],
            ['شروع فیدها'],
        ];
        $replyMarkup = [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
        
        $this->telegram->sendMessage([
            'chat_id' => $this->chatId,
            'text' => 'به بات RSS خوش اومدید! لطفاً یه گزینه انتخاب کنید:',
            'reply_markup' => json_encode($replyMarkup),
        ]);
        
        file_put_contents('/tmp/telegram-webhook.log', "Sent welcome message to chat_id: {$this->chatId}\n", FILE_APPEND);
    }

    protected function sendFeedInstructions()
    {
        $this->telegram->sendMessage([
            'chat_id' => $this->chatId,
            'text' => 'لطفاً فید RSS رو با فرمت "نام: آدرس" وارد کنید. مثلاً: خبرآنلاین: https://www.khabaronline.ir/rss',
        ]);
    }

    protected function addFeed($text)
    {
        [$name, $url] = explode(':', $text, 2);
        $name = trim($name);
        $url = trim($url);
        
        $config = Cache::get("feeds_config_{$this->chatId}", ['feeds' => [], 'auto_send' => false]);
        $config['feeds'][$name] = $url;
        
        Cache::put("feeds_config_{$this->chatId}", $config, now()->addDays(30));
        
        $this->telegram->sendMessage([
            'chat_id' => $this->chatId,
            'text' => "فید '$name' اضافه شد! برای فعال‌سازی ارسال خودکار، 'شروع فیدها' رو بزنید.\nلطفاً این متغیر محیطی رو تو Render اضافه کنید:\nFEEDS_CONFIG_{$this->chatId}=" . json_encode($config),
        ]);
        
        file_put_contents('/tmp/telegram-webhook.log', "Added feed: $name, URL: $url for chat_id: {$this->chatId}\n", FILE_APPEND);
    }

    protected function sendLatestNews()
    {
        $config = Cache::get("feeds_config_{$this->chatId}", ['feeds' => []]);
        if (empty($config['feeds'])) {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'هیچ فیدی ثبت نشده. لطفاً با "تغییر فید" یه فید اضافه کنید.',
            ]);
            return;
        }
        
        foreach ($config['feeds'] as $name => $url) {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => "در حال بررسی فید: $name ($url)",
            ]);
            // اینجا باید منطق بررسی فید و ارسال اخبار پیاده‌سازی بشه
        }
        
        file_put_contents('/tmp/telegram-webhook.log', "Sent latest news for chat_id: {$this->chatId}\n", FILE_APPEND);
    }

    protected function enableAutoSend()
    {
        $config = Cache::get("feeds_config_{$this->chatId}", ['feeds' => [], 'auto_send' => false]);
        $config['auto_send'] = true;
        
        Cache::put("feeds_config_{$this->chatId}", $config, now()->addDays(30));
        
        $this->telegram->sendMessage([
            'chat_id' => $this->chatId,
            'text' => "ارسال خودکار فیدها فعال شد!\nلطفاً این متغیر محیطی رو تو Render آپدیت کنید:\nFEEDS_CONFIG_{$this->chatId}=" . json_encode($config),
        ]);
        
        file_put_contents('/tmp/telegram-webhook.log', "Enabled auto-send for chat_id: {$this->chatId}\n", FILE_APPEND);
    }

    protected function sendHelpMessage()
    {
        $this->telegram->sendMessage([
            'chat_id' => $this->chatId,
            'text' => 'دستورات موجود:
            /start - شروع بات
            تغییر فید - اضافه کردن فید RSS
            دریافت اخبار - دریافت آخرین اخبار
            شروع فیدها - فعال‌سازی ارسال خودکار',
        ]);
    }
}
?>