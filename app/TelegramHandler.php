<?php
namespace App;

use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use DateTime;
use DateTimeZone;
use Morilog\Jalali\Jalalian;

class TelegramHandler
{
    protected $telegram;
    protected $chatId;
    protected $config;
    protected $httpClient;
    protected $sentLinksFile;

    public function __construct(Api $telegram, string $chatId)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
        $this->sentLinksFile = "feeds/sent_{$this->chatId}.json";
        $this->httpClient = new Client([
            'timeout' => 10,
            'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; LumenRSSBot/1.0; +https://telegram-rss-bot-kmgj.onrender.com)']
        ]);
        $this->loadConfig();
        $this->initializeSentLinks();
    }

    protected function initializeSentLinks()
    {
        if (!Storage::exists($this->sentLinksFile)) {
            Storage::put($this->sentLinksFile, json_encode(['sent_links' => []], JSON_UNESCAPED_UNICODE));
        }
    }

    protected function loadSentLinks()
    {
        if (Storage::exists($this->sentLinksFile)) {
            $data = json_decode(Storage::get($this->sentLinksFile), true);
            return $data['sent_links'] ?? [];
        }
        return [];
    }

    protected function saveSentLink($link)
    {
        $sentLinks = $this->loadSentLinks();
        if (!in_array($link, $sentLinks)) {
            $sentLinks[] = $link;
            Storage::put($this->sentLinksFile, json_encode(['sent_links' => $sentLinks], JSON_UNESCAPED_UNICODE));
            Log::info("Saved sent link for chat_id: {$this->chatId}", ['link' => $link]);
        }
    }

    protected function loadConfig()
    {
        $envKey = "FEEDS_CONFIG_{$this->chatId}";
        $envConfig = env($envKey);
        if ($envConfig) {
            $this->config = json_decode($envConfig, true);
            if ($this->config === null) {
                Log::error("Invalid JSON in env $envKey for chat_id: {$this->chatId}");
                $this->config = ['feeds' => [], 'auto_send' => false];
            }
        } else {
            $this->config = ['feeds' => [], 'auto_send' => false];
            $configFile = "feeds/{$this->chatId}.json";
            if (Storage::exists($configFile)) {
                $this->config = json_decode(Storage::get($configFile), true);
                if ($this->config === null) {
                    Log::error("Invalid JSON in $configFile for chat_id: {$this->chatId}");
                    $this->config = ['feeds' => [], 'auto_send' => false];
                }
            }
        }
    }

    protected function saveConfig($config)
    {
        $this->config = $config;
        $configFile = "feeds/{$this->chatId}.json";
        try {
            Storage::put($configFile, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            Log::info("Saved config to $configFile for chat_id: {$this->chatId}", ['config' => $config]);
        } catch (\Exception $e) {
            Log::error("Failed to save config to $configFile for chat_id: {$this->chatId}: {$e->getMessage()}");
            throw $e;
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
        $text = isset($message['text']) ? $message['text'] : '';
        $replyMarkup = json_encode($this->getReplyMarkup());

        try {
            if ($text === '/start') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'به بات خوش اومدی! اخبار رو با دکمه‌ها مدیریت کن.',
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'درباره') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'این بات اخبار و مطالب رو از فیدهای دلخواهت جمع می‌کنه و می‌فرسته.',
                    'reply_markup' => $replyMarkup
                ]);
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
            } elseif ($text === 'دریافت اخبار') {
                $this->sendLatestNews($replyMarkup);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'اخبار فرستاده شد!',
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'شروع فیدها') {
                $this->config['auto_send'] = true;
                $this->saveConfig($this->config);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "ارسال خودکار اخبار فعال شد!",
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'توقف') {
                $this->config['auto_send'] = false;
                $this->saveConfig($this->config);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'ارسال خودکار اخبار متوقف شد!',
                    'reply_markup' => $replyMarkup
                ]);
            } elseif ($text === 'تغییر فید') {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "فید جدید رو اینجوری وارد کن (هر خط یه فید):\nنام: آدرس فید\nمثال:\nخبرآنلاین: https://www.khabaronline.ir/rss",
                    'reply_markup' => json_encode(['force_reply' => true])
                ]);
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
                    $this->config['feeds'] = $newFeeds;
                    $this->saveConfig($this->config);
                    $feedList = implode("\n", array_map(function ($name, $url) {
                        return "🦗 $name: " . htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                    }, array_keys($this->config['feeds']), $this->config['feeds']));
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => "فیدها با موفقیت جایگزین شدند:\n$feedList",
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                        'reply_markup' => $replyMarkup
                    ]);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => 'فید معتبر وارد کن!',
                        'reply_markup' => $replyMarkup
                    ]);
                }
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'دستور نامعتبر! از دکمه‌ها استفاده کن.',
                    'reply_markup' => $replyMarkup
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error in handleMessage for chat_id: {$this->chatId}: {$e->getMessage()}");
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'خطا: مشکلی پیش اومد. دوباره امتحان کن.',
                'reply_markup' => $replyMarkup
            ]);
        }
    }

    public function checkAndSendFeeds()
    {
        if ($this->config['auto_send'] === true) {
            $replyMarkup = json_encode($this->getReplyMarkup());
            $this->sendLatestNews($replyMarkup);
        }
    }

    protected function getFeedData($item, $namespaces, $tag, $fallbackTag = null)
    {
        if (!($item instanceof \SimpleXMLElement)) {
            Log::error("Invalid item type in getFeedData, expected SimpleXMLElement, got " . gettype($item));
            return $tag === 'link' ? '#' : ($tag === 'image' ? null : 'بدون ' . $tag);
        }

        if (isset($item->$tag)) {
            $value = (string)$item->$tag;
            return $value !== '' ? $value : ($tag === 'image' ? null : 'بدون ' . $tag);
        }

        if ($fallbackTag && isset($namespaces['dc']) && $item->children($namespaces['dc'])->$fallbackTag) {
            $value = (string)$item->children($namespaces['dc'])->$fallbackTag;
            return $value !== '' ? $value : ($tag === 'image' ? null : 'بدون ' . $tag);
        }

        if ($tag === 'link' && isset($namespaces['atom']) && $item->children($namespaces['atom'])->link) {
            $link = $item->children($namespaces['atom'])->link;
            if (isset($link->attributes()->href)) {
                $value = (string)$link->attributes()->href;
                return $value !== '' ? $value : '#';
            }
        }

        if ($tag === 'title' && isset($namespaces['content']) && $item->children($namespaces['content'])->encoded) {
            $value = strip_tags((string)$item->children($namespaces['content'])->encoded);
            return $value !== '' ? substr($value, 0, 100) : 'بدون ' . $tag;
        }

        if ($tag === 'pubDate' && isset($namespaces['dc']) && $item->children($namespaces['dc'])->date) {
            $value = (string)$item->children($namespaces['dc'])->date;
            return $value !== '' ? $value : 'بدون ' . $tag;
        }

        if ($tag === 'image' && isset($item->enclosure)) {
            $enclosure = $item->enclosure;
            if (isset($enclosure->attributes()->url) && isset($enclosure->attributes()->type) && strpos((string)$enclosure->attributes()->type, 'image') !== false) {
                return (string)$enclosure->attributes()->url;
            }
        }

        if ($tag === 'image' && isset($namespaces['media']) && $item->children($namespaces['media'])->content) {
            $media = $item->children($namespaces['media'])->content;
            if (isset($media->attributes()->url) && isset($media->attributes()->medium) && (string)$media->attributes()->medium === 'image') {
                return (string)$media->attributes()->url;
            }
        }

        if ($tag === 'description') {
            $value = strip_tags((string)$item->description);
            return $value !== '' ? substr($value, 0, 200) : 'بدون توضیحات';
        }

        return $tag === 'image' ? null : ($tag === 'link' ? '#' : 'بدون ' . $tag);
    }

    protected function isRecent($pubDate)
    {
        try {
            $pubDateTime = new DateTime($pubDate, new DateTimeZone('GMT'));
            $now = new DateTime('now', new датуTimeZone('GMT'));
            $interval = $now->getTimestamp() - $pubDateTime->getTimestamp();
            return $interval <= 15 * 60;
        } catch (\Exception $e) {
            Log::error("Invalid pubDate format: $pubDate", ['error' => $e->getMessage()]);
            return false;
        }
    }

    protected function formatJalaliDate($pubDate)
    {
        try {
            $dateTime = new DateTime($pubDate, new DateTimeZone('GMT'));
            $dateTime->setTimezone(new DateTimeZone('Asia/Tehran'));
            $jalali = Jalalian::fromDateTime($dateTime);
            return $jalali->format('l j F Y، H:i');
        } catch (\Exception $e) {
            Log::error("Failed to convert pubDate to Jalali: $pubDate", ['error' => $e->getMessage()]);
            return $pubDate;
        }
    }

    protected function checkOpenGraphMetadata($url)
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || $url === '#') {
            return ['hasOgImage' => false, 'hasOgTitle' => false, 'hasOgDescription' => false, 'statusCode' => null];
        }

        try {
            $response = $this->httpClient->get($url);
            $html = $response->getBody()->getContents();
            $hasOgImage = preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']/i', $html, $ogImage);
            $hasOgTitle = preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\'](.*?)["\']/i', $html, $ogTitle);
            $hasOgDescription = preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\'](.*?)["\']/i', $html, $ogDescription);
            
            return [
                'hasOgImage' => $hasOgImage && !empty($ogImage[1]) && filter_var($ogImage[1], FILTER_VALIDATE_URL),
                'hasOgTitle' => $hasOgTitle && !empty($ogTitle[1]),
                'hasOgDescription' => $hasOgDescription && !empty($ogDescription[1]),
                'statusCode' => $response->getStatusCode()
            ];
        } catch (\Exception $e) {
            Log::error("Failed to check Open Graph metadata for $url: {$e->getMessage()}");
            return ['hasOgImage' => false, 'hasOgTitle' => false, 'hasOgDescription' => false, 'statusCode' => null];
        }
    }

    protected function sendLatestNews($replyMarkup)
    {
        if (empty($this->config['feeds'])) {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'هیچ فیدی ثبت نشده. لطفاً با "تغییر فید" یه فید اضافه کنید.',
                'reply_markup' => $replyMarkup
            ]);
            return;
        }

        $sentLinks = $this->loadSentLinks();
        $hasNews = false;

        foreach ($this->config['feeds'] as $name => $url) {
            try {
                $response = $this->httpClient->get($url);
                $xmlContent = $response->getBody()->getContents();
                $xml = @simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml === false) {
                    Log::error("Failed to parse XML for feed: $name ($url) for chat_id: {$this->chatId}");
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => "خطا در پردازش XML فید: $name",
                        'reply_markup' => $replyMarkup
                    ]);
                    continue;
                }

                $namespaces = $xml->getNamespaces(true);
                $items = $xml->channel->item ?? [];
                if (empty($items)) {
                    continue;
                }

                $items = array_slice(iterator_to_array($items), 0, 10);
                $latestItems = [];
                foreach ($items as $item) {
                    $link = $this->getFeedData($item, $namespaces, 'link', 'identifier');
                    $pubDate = $this->getFeedData($item, $namespaces, 'pubDate', 'date');
                    if ($this->isRecent($pubDate) && !in_array($link, $sentLinks)) {
                        $latestItems[] = $item;
                    }
                    if (count($latestItems) >= 3) {
                        break;
                    }
                }

                if (!empty($latestItems)) {
                    $hasNews = true;
                }

                foreach ($latestItems as $index => $item) {
                    $title = $this->getFeedData($item, $namespaces, 'title', 'title');
                    $link = $this->getFeedData($item, $namespaces, 'link', 'identifier');
                    $pubDate = $this->getFeedData($item, $namespaces, 'pubDate', 'date');
                    $image = $this->getFeedData($item, $namespaces, 'image');
                    $description = $this->getFeedData($item, $namespaces, 'description');
                    $jalaliDate = $this->formatJalaliDate($pubDate);

                    $linkHtml = $link !== '#' ? "<a href=\"$link\">مشاهده خبر</a>" : 'بدون لینک';
                    $message = "📰 سایت: $name\n🗞️ عنوان: <b>$title</b>\n\n🕒 زمان انتشار: <i>$jalaliDate</i>";

                    $metadata = $this->checkOpenGraphMetadata($link);
                    $hasValidMetadata = $metadata['hasOgImage'] && $metadata['hasOgTitle'] && $metadata['hasOgDescription'];
                    if (!$hasValidMetadata) {
                        if ($description !== 'بدون توضیحات') {
                            $message .= "\n\n📝 توضیحات: <i>" . htmlspecialchars(substr($description, 0, 200)) . "</i>";
                        }
                        if ($image && filter_var($image, FILTER_VALIDATE_URL)) {
                            $message .= "\n\n🖼️ <a href=\"$image\">تصویر خبر</a>";
                        }
                    }

                    $message .= "\n\n🔗 $linkHtml";

                    try {
                        $this->telegram->sendMessage([
                            'chat_id' => $this->chatId,
                            'text' => $message,
                            'parse_mode' => 'HTML',
                            'disable_web_page_preview' => false,
                            'reply_markup' => $replyMarkup
                        ]);
                        $this->saveSentLink($link);
                    } catch (\Exception $e) {
                        Log::error("Failed to send news #$index: $title from $name: {$e->getMessage()}");
                    }
                }
            } catch (\Exception $e) {
                Log::error("Error processing feed $name ($url) for chat_id: {$this->chatId}: {$e->getMessage()}");
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "خطا در پردازش فید $name",
                    'reply_markup' => $replyMarkup
                ]);
            }
        }

        if (!$hasNews) {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'هیچ خبر جدیدی یافت نشد.',
                'reply_markup' => $replyMarkup
            ]);
        }
    }

    public function getConfig()
    {
        return $this->config;
    }
}
?>