<?php
namespace App;

use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;

class TelegramHandler
{
    protected $telegram;
    protected $chatId;
    protected $configHandler;
    protected $feedProcessor;

    public function __construct(Api $telegram, string $chatId, ConfigHandler $configHandler, FeedProcessor $feedProcessor)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
        $this->configHandler = $configHandler;
        $this->feedProcessor = $feedProcessor;
    }

    public function getReplyMarkup()
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
                $config = $this->configHandler->getConfig();
                $feedList = !empty($config['feeds']) ? implode("\n", array_map(function ($name, $url) {
                    $cleanedName = $this->configHandler->normalizeFeedName($name);
                    return "🦗 $cleanedName: " . htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
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
                $startTime = microtime(true);
                $this->feedProcessor->sendLatestNews($replyMarkup, true);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'اخبار بررسی شد!',
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Sent confirmation for news fetch for chat_id: {$this->chatId}", ['duration' => microtime(true) - $startTime]);
            } elseif ($text === 'شروع فیدها') {
                $config = $this->configHandler->getConfig();
                $config['auto_send'] = true;
                $this->configHandler->saveConfig($config);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "ارسال خودکار اخبار فعال شد!",
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Enabled auto-send for chat_id: {$this->chatId}", ['auto_send' => true]);
            } elseif ($text === 'توقف') {
                $config = $this->configHandler->getConfig();
                $config['auto_send'] = false;
                $this->configHandler->saveConfig($config);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'ارسال خودکار اخبار متوقف شد!',
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Disabled auto-send for chat_id: {$this->chatId}", ['auto_send' => false]);
            } elseif ($text === 'تغییر فید') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "فید جدید رو اینجوری وارد کن (هر خط یه فید):\nنام: آدرس فید\nمثال:\nزومیت: https://www.zoomit.ir/feed",
                    'reply_markup' => json_encode(['force_reply' => true])
                ]);
                Log::info("Sent feed instructions to chat_id: {$this->chatId}");
            } elseif (isset($message['reply_to_message']) && strpos($message['reply_to_message']['text'], 'فید جدید رو اینجوری وارد کن') !== false) {
                $lines = explode("\n", $text);
                $newFeeds = [];
                foreach ($lines as $line) {
                    if (strpos($line, ':') !== false) {
                        [$name, $url] = array_map('trim', explode(':', $line, 2));
                        $name = $this->configHandler->normalizeFeedName($name);
                        if (filter_var($url, FILTER_VALIDATE_URL)) {
                            $newFeeds[$name] = $url;
                        }
                    }
                }
                if (!empty($newFeeds)) {
                    $config = $this->configHandler->getConfig();
                    $config['feeds'] = $newFeeds;
                    $this->configHandler->saveConfig($config);
                    $feedList = implode("\n", array_map(function ($name, $url) {
                        $cleanedName = $this->configHandler->normalizeFeedName($name);
                        return "🦗 $cleanedName: " . htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                    }, array_keys($config['feeds']), $config['feeds']));
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => "فیدها با موفقیت جایگزین شدند:\n$feedList",
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                        'reply_markup' => $replyMarkup
                    ]);
                    Log::info("Replaced feeds for chat_id: {$this->chatId}", ['feeds' => $newFeeds]);
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
            Log::error("Error in handleMessage for chat_id: {$this->chatId}: {$e->getMessage()}", ['exception' => $e]);
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'خطا: مشکلی پیش اومد. دوباره امتحان کن.',
                'reply_markup' => $replyMarkup
            ]);
        }
    }

    public function checkAndSendFeeds()
    {
        Log::info("Checking feeds for auto-send for chat_id: {$this->chatId}", ['auto_send' => $this->configHandler->getConfig()['auto_send']]);
        if ($this->configHandler->getConfig()['auto_send'] === true) {
            $replyMarkup = json_encode($this->getReplyMarkup());
            $startTime = microtime(true);
            $this->feedProcessor->sendLatestNews($replyMarkup, false);
            Log::info("Auto-send completed for chat_id: {$this->chatId}", ['duration' => microtime(true) - $startTime]);
        } else {
            Log::info("Auto-send is disabled for chat_id: {$this->chatId}");
        }
    }
}
?>