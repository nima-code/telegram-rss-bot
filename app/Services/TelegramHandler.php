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
    }

    protected function loadConfig()
    {
        try {
            $feedsEnv = env('FEEDS');
            $config = [
                'feeds' => [],
                'auto_send' => true
            ];

            if ($feedsEnv) {
                Log::info("Loading feeds from environment variable for chat_id {$this->chatId}");
                $feeds = explode(',', $feedsEnv);
                foreach ($feeds as $feed) {
                    [$name, $url] = array_map('trim', explode(':', $feed, 2));
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $config['feeds'][$name] = $url;
                    }
                }
            } else {
                Log::info("No FEEDS env variable found for chat_id {$this->chatId}, using default feeds");
                $config['feeds'] = [
                    'زومیت' => 'https://www.zoomit.ir/rss',
                    'بی‌بی‌سی' => 'https://www.bbc.com/persian/index.xml',
                    'خبرآنلاین' => 'https://www.khabaronline.ir/rss',
                    'تابناک' => 'https://www.tabnak.ir/fa/rss/allnews',
                    'ایسنا' => 'https://www.isna.ir/rss'
                ];
            }

            Log::info("Loaded config for chat_id {$this->chatId}: " . json_encode($config));
            return $config;
        } catch (\Exception $e) {
            Log::error("Error loading config for chat_id {$this->chatId}: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
            return [
                'feeds' => [
                    'زومیت' => 'https://www.zoomit.ir/rss',
                    'بی‌بی‌سی' => 'https://www.bbc.com/persian/index.xml',
                    'خبرآنلاین' => 'https://www.khabaronline.ir/rss',
                    'تابناک' => 'https://www.tabnak.ir/fa/rss/allnews',
                    'ایسنا' => 'https://www.isna.ir/rss'
                ],
                'auto_send' => true
            ];
        }
    }

    protected function saveConfig($config = null)
    {
        // در پلن رایگان Render، از نوشتن به دیسک اجتناب می‌کنیم
        Log::info("Config saving is disabled in Render free plan for chat_id {$this->chatId}");
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
        try {
            $text = isset($message['text']) ? $message['text'] : '';
            $replyMarkup = json_encode($this->getReplyMarkup());

            if ($text === '/start') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'به بات خوش اومدی! اخبار رو با دکمه‌ها مدیریت کن. فیدهای پیش‌فرض: زومیت، بی‌بی‌سی، خبرآنلاین، تابناک، ایسنا.',
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'دریافت اخبار') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'چک کردن اخبار جدید...'
                ]);

                try {
                    $controller = new CheckFeedsController();
                    $request = Request::create('/check-feeds?chat_id=' . $this->chatId, 'GET');
                    $response = $controller->check($request);
                    $content = $response->getContent();
                    Log::info("Check-feeds response for chat_id {$this->chatId}: {$content}");

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
                // در Render رایگان، ذخیره‌سازی غیرفعال است
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'ارسال خودکار اخبار فعال شد! هر ۱۵ دقیقه اخبار جدید میاد.',
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'توقف') {
                $this->config['auto_send'] = false;
                // در Render رایگان، ذخیره‌سازی غیرفعال است
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'ارسال خودکار اخبار متوقف شد!',
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'تغییر فید') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "متأسفانه تغییر فید در پلن رایگان Render پشتیبانی نمی‌شود. فیدهای پیش‌فرض استفاده می‌شوند.",
                    'reply_markup' => $replyMarkup
                ]);
            } elseif (isset($message['reply_to_message']) && strpos($message['reply_to_message']['text'], 'فید جدید رو اینجوری وارد کن') !== false) {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'تغییر فید در پلن رایگان Render پشتیبانی نمی‌شود.',
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'درباره') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'این بات اخبار و مطالب رو از فیدهای دلخواهت جمع می‌کنه و می‌فرسته. فیدهای پیش‌فرض: زومیت، بی‌بی‌سی، خبرآنلاین، تابناک، ایسنا.',
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