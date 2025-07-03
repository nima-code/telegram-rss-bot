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
    protected $configFile;
    protected $config;

    public function __construct(Api $telegram, string $chatId)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
        $this->configFile = storage_path('app/feeds_config_' . $chatId . '.json');
        $this->config = $this->loadConfig();
    }

    protected function loadConfig()
    {
        try {
            $directory = storage_path('app');
            Log::info("Checking storage directory: $directory");
            if (!is_dir($directory)) {
                Log::info("Creating directory: $directory");
                mkdir($directory, 0755, true);
                chown($directory, 'www-data');
                chgrp($directory, 'www-data');
            }
            if (!is_writable($directory)) {
                Log::warning("Directory not writable: $directory, attempting to fix");
                chmod($directory, 0755);
                if (!is_writable($directory)) {
                    Log::error("Failed to make directory writable: $directory");
                    throw new \Exception("Storage directory is not writable");
                }
            }

            if (file_exists($this->configFile)) {
                $config = json_decode(file_get_contents($this->configFile), true);
                if ($config === null || !isset($config['feeds'])) {
                    Log::error("Invalid JSON in feeds_config_{$this->chatId}.json");
                    $config = [
                        'feeds' => [
                            'زومیت' => 'https://www.zoomit.ir/rss',
                            'بی‌بی‌سی' => 'https://www.bbc.com/persian/index.xml',
                            'خبرآنلاین' => 'https://www.khabaronline.ir/rss',
                            'تابناک' => 'https://www.tabnak.ir/fa/rss/allnews',
                            'ایسنا' => 'https://www.isna.ir/rss'
                        ],
                        'auto_send' => true
                    ];
                    $this->saveConfig($config);
                }
            } else {
                Log::info("No config file found for chat_id {$this->chatId}, using default feeds");
                $config = [
                    'feeds' => [
                        'زومیت' => 'https://www.zoomit.ir/rss',
                        'بی‌بی‌سی' => 'https://www.bbc.com/persian/index.xml',
                        'خبرآنلاین' => 'https://www.khabaronline.ir/rss',
                        'تابناک' => 'https://www.tabnak.ir/fa/rss/allnews',
                        'ایسنا' => 'https://www.isna.ir/rss'
                    ],
                    'auto_send' => true
                ];
                $this->saveConfig($config);
            }
            Log::info("Loaded config for chat_id {$this->chatId}: " . json_encode($config));
            return $config;
        } catch (\Exception $e) {
            Log::error("Error loading config for chat_id {$this->chatId}: {$e->getMessage()}");
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
        try {
            $directory = storage_path('app');
            if (!is_dir($directory)) {
                Log::info("Creating directory: $directory");
                mkdir($directory, 0755, true);
                chown($directory, 'www-data');
                chgrp($directory, 'www-data');
            }
            if (!is_writable($directory)) {
                Log::warning("Directory not writable: $directory, attempting to fix");
                chmod($directory, 0755);
                if (!is_writable($directory)) {
                    Log::error("Failed to make directory writable: $directory");
                    return false;
                }
            }
            file_put_contents($this->configFile, json_encode($config ?? $this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            Log::info("Successfully saved feeds_config_{$this->chatId}.json");
            return true;
        } catch (\Exception $e) {
            Log::error("Error writing feeds_config_{$this->chatId}.json: {$e->getMessage()}");
            return false;
        }
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
                if ($this->saveConfig()) {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'ارسال خودکار اخبار فعال شد! هر ۱۵ دقیقه اخبار جدید میاد.',
                        'reply_markup' => $replyMarkup
                    ]);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'خطا در فعال‌سازی!',
                        'reply_markup' => $replyMarkup
                    ]);
                }
            } elseif ($text === 'توقف') {
                $this->config['auto_send'] = false;
                if ($this->saveConfig()) {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'ارسال خودکار اخبار متوقف شد!',
                        'reply_markup' => $replyMarkup
                    ]);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'خطا در توقف!',
                        'reply_markup' => $replyMarkup
                    ]);
                }
            } elseif ($text === 'تغییر فید') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "فید جدید رو اینجوری وارد کن (هر خط یه فید):\nنام: آدرس فید\nمثال:\nزومیت: https://www.zoomit.ir/rss\nبی‌بی‌سی: https://www.bbc.com/persian/index.xml\nخبرآنلاین: https://www.khabaronline.ir/rss\nتابناک: https://www.tabnak.ir/fa/rss/allnews\nایسنا: https://www.isna.ir/rss\n\nهشدار: فید جدید جایگزین فیدهای قبلی می‌شود!",
                    'reply_markup' => json_encode(['force_reply' => true])
                ]);
            } elseif (isset($message['reply_to_message']) && strpos($message['reply_to_message']['text'], 'فید جدید رو اینجوری وارد کن') !== false) {
                $newFeedsText = $text;
                $lines = explode("\n", $newFeedsText);
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
                    $this->config['feeds'] = $newFeeds;
                    if ($this->saveConfig()) {
                        $feedList = implode("\n", array_map(function ($name, $url) {
                            return "🦗 $name: " . htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                        }, array_keys($newFeeds), $newFeeds));
                        $this->telegram->sendMessage([
                            'chat_id' => $this->chatId,
                            'text' => "فیدهای قبلی پاک شد و فید جدید تنظیم شد:\n$feedList",
                            'parse_mode' => 'HTML',
                            'disable_web_page_preview' => true,
                            'reply_markup' => $replyMarkup
                        ]);
                    } else {
                        $this->telegram->sendMessage([
                            'chat_id' => $this->chatId,
                            'text' => 'خطا در ذخیره فیدها!',
                            'reply_markup' => $replyMarkup
                        ]);
                    }
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'فید معتبر وارد کن!',
                        'reply_markup' => $replyMarkup
                    ]);
                }
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