<?php
namespace App;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class TelegramHandler
{
    protected $telegram;
    protected $chatId;
    protected $config;

    public function __construct(Api $telegram, $chatId)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
        $this->getConfig();
    }

    protected function getConfig()
    {
        $envKey = 'FEEDS_CONFIG_' . $this->chatId;
        $configJson = env($envKey, json_encode(['feeds' => [], 'auto_send' => true]));
        $this->config = json_decode($configJson, true);
        if ($this->config === null) {
            $this->config = ['feeds' => [], 'auto_send' => true];
        }
    }

    protected function saveConfig()
    {
        Log::info("Saving config for chat_id {$this->chatId}: " . json_encode($this->config));
        file_put_contents('php://stderr', "Set env FEEDS_CONFIG_{$this->chatId}=" . json_encode($this->config, JSON_UNESCAPED_UNICODE) . "\n");
        return true;
    }

    public function handleMessage($message)
    {
        try {
            $text = $message['text'] ?? '';
            $replyMarkup = [
                'keyboard' => [
                    [['text' => 'دریافت اخبار'], ['text' => 'نمایش فیدها']],
                    [['text' => 'شروع فیدها'], ['text' => 'توقف']],
                    [['text' => 'تغییر فید'], ['text' => 'درباره']],
                ],
                'resize_keyboard' => true
            ];

            if ($text === '/start') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'به ربات خوش آمدید! لطفاً یکی از گزینه‌های زیر را انتخاب کنید:',
                    'reply_markup' => json_encode($replyMarkup)
                ]);
            } elseif ($text === 'دریافت اخبار') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'چک کردن اخبار جدید...'
                ]);
                $controller = new \App\Http\Controllers\CheckFeedsController();
                $request = \Illuminate\Http\Request::create('/check-feeds?chat_id=' . $this->chatId, 'GET');
                $response = $controller->check($request);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $response->getData()->message
                ]);
            } elseif ($text === 'نمایش فیدها') {
                $feeds = $this->config['feeds'] ?? [];
                if (empty($feeds)) {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'هیچ فیدی تنظیم نشده است.'
                    ]);
                } else {
                    $feedList = implode("\n", array_map(function ($name, $url) {
                        return "$name: $url";
                    }, array_keys($feeds), $feeds));
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => "فیدهای تنظیم‌شده:\n$feedList"
                    ]);
                }
            } elseif ($text === 'شروع فیدها') {
                $this->config['auto_send'] = true;
                if ($this->saveConfig()) {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'ارسال خودکار اخبار فعال شد! هر ۱۵ دقیقه اخبار جدید میاد.',
                        'reply_markup' => json_encode($replyMarkup)
                    ]);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'خطا در فعال‌سازی ارسال خودکار.'
                    ]);
                }
            } elseif ($text === 'توقف') {
                $this->config['auto_send'] = false;
                if ($this->saveConfig()) {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'ارسال خودکار اخبار غیرفعال شد.',
                        'reply_markup' => json_encode($replyMarkup)
                    ]);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'خطا در غیرفعال‌سازی ارسال خودکار.'
                    ]);
                }
            } elseif ($text === 'تغییر فید') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'لطفاً فید جدید را با فرمت زیر وارد کنید: نام:آدرس\nمثال: خبرآنلاین:https://www.khabaronline.ir/rss'
                ]);
            } elseif ($text === 'درباره') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'ربات RSS برای دریافت اخبار از فیدهای RSS. ساخته‌شده با Lumen.'
                ]);
            } elseif (strpos($text, ':') !== false) {
                [$name, $url] = explode(':', $text, 2);
                $name = trim($name);
                $url = trim($url);
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $this->config['feeds'][$name] = $url;
                    if ($this->saveConfig()) {
                        $this->telegram->sendMessage([
                            'chat_id' => $this->chatId,
                            'text' => "فید '$name' اضافه شد.",
                            'reply_markup' => json_encode($replyMarkup)
                        ]);
                    } else {
                        $this->telegram->sendMessage([
                            'chat_id' => $this->chatId,
                            'text' => 'خطا در افزودن فید.'
                        ]);
                    }
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'آدرس فید نامعتبر است.'
                    ]);
                }
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'دستور ناشناخته! لطفاً از گزینه‌های زیر استفاده کنید:',
                    'reply_markup' => json_encode($replyMarkup)
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error handling message for chat_id {$this->chatId}: {$e->getMessage()}");
        }
    }
}
?>