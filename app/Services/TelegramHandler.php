<?php

namespace App\Services;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\CheckFeedsController;

class TelegramHandler
{
    protected $telegram;
    protected $chatId;
    protected $config;

    public function __construct(Api $telegram, string $chatId)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
        $this->config = $this->loadConfig();
        Log::info("Initialized TelegramHandler for chat_id: {$this->chatId}");
    }

    protected function loadConfig()
    {
        try {
            Log::info("Loading feeds for chat_id {$this->chatId}");
            $config = [
                'feeds' => ['خبرآنلاین' => 'https://www.khabaronline.ir/rss'],
                'auto_send' => true
            ];
            Log::info("Loaded config for chat_id {$this->chatId}: " . json_encode($config));
            return $config;
        } catch (\Exception $e) {
            Log::error("Error loading config for chat_id {$this->chatId}: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
            return [
                'feeds' => ['خبرآنلاین' => 'https://www.khabaronline.ir/rss'],
                'auto_send' => true
            ];
        }
    }

    protected function saveConfig($config = null)
    {
        Log::info("Config saving is disabled in Render free plan for chat_id {$this->chatId}");
        return true;
    }

    protected function getReplyMarkup()
    {
        return [
            'keyboard' => [
                ['دریافت اخبار', 'نمایش فیدها'],
                ['شروع فیدها', 'توقف'],
                ['درباره']
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
    }

    public function handleMessage($message)
    {
        try {
            Log::info("Received Telegram message for chat_id {$this->chatId}: " . json_encode($message));
            $text = isset($message['text']) ? $message['text'] : '';
            $replyMarkup = json_encode($this->getReplyMarkup());

            if ($text === '/start') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'به بات خوش اومدی! اخبار رو با دکمه‌ها مدیریت کن. فید پیش‌فرض: خبرآنلاین.',
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'دریافت اخبار') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'چک کردن اخبار جدید...'
                ]);

                try {
                    Log::info("Calling /check-feeds for chat_id {$this->chatId}");
                    $controller = new CheckFeedsController();
                    $request = Request::create('/check-feeds?chat_id=' . $this->chatId, 'GET');
                    $response = $controller->check($request);
                    $statusCode = $response->getStatusCode();
                    $content = $response->getContent();
                    Log::info("Check-feeds response for chat_id {$this->chatId}: Status {$statusCode}, Content: {$content}");

                    if ($statusCode !== 200) {
                        Log::error("Non-200 response from /check-feeds for chat_id {$this->chatId}: Status {$statusCode}");
                        $this->telegram->sendMessage([
                            'chat_id' => $this->chatId,
                            'text' => "خطا: سرور پاسخ {$statusCode} داد",
                            'reply_markup' => $replyMarkup
                        ]);
                        return;
                    }

                    if (empty($content)) {
                        Log::error("Empty response from /check-feeds for chat_id {$this->chatId}");
                        $this->telegram->sendMessage([
                            'chat_id' => $this->chatId,
                            'text' => 'خطا: پاسخ سرور خالی است',
                            'reply_markup' => $replyMarkup
                        ]);
                        return;
                    }

                    $results = json_decode($content, true);
                    if ($results === null) {
                        Log::error("Invalid JSON response from /check-feeds for chat_id {$this->chatId}: {$content}");
                        $this->telegram->sendMessage([
                            'chat_id' => $this->chatId,
                            'text' => 'خطا: پاسخ سرور نامعتبر است',
                            'reply_markup' => $replyMarkup
                        ]);
                        return;
                    }

                    $message = isset($results['message']) ? $results['message'] : 
                               (isset($results['error']) ? $results['error'] : 'خطای ناشناخته');

                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => $message,
                        'reply_markup' => $replyMarkup
                    ]);
                } catch (\Exception $e) {
                    Log::error("Error calling /check-feeds for chat_id {$this->chatId}: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'خطا در دریافت اخبار: ' . $e->getMessage(),
                        'reply_markup' => $replyMarkup
                    ]);
                }
            } elseif ($text === 'نمایش فیدها') {
                $feedList = !empty($this->config['feeds']) ? implode("\n", array_map(function ($name, $url) {
                    return "🦗 $name: " . htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                }, array_keys($this->config['feeds']), $this->config['feeds'])) : 'هیچ فیدی نیست!';
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "فید‌های فعال:\n$feedList",
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'شروع فیدها') {
                $this->config['auto_send'] = true;
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'ارسال خودکار اخبار فعال شد! هر ۱۵ دقیقه اخبار جدید میاد.',
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'توقف') {
                $this->config['auto_send'] = false;
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'ارسال خودکار اخبار متوقف شد!',
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'درباره') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'این بات اخبار رو از فید خبرآنلاین جمع می‌کنه و می‌فرسته.',
                    'reply_markup' => $replyMarkup
                ]);
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'دستور نامعتبر! از دکمه‌ها استفاده کن.',
                    'reply_markup' => $replyMarkup
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error handling message for chat_id {$this->chatId}: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'خطا: ' . $e->getMessage(),
                'reply_markup' => $replyMarkup
            ]);
        }
    }
}