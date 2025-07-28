<?php
namespace App;

use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
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
    protected $maxSentLinks = 500; // حداکثر تعداد لینک‌های ذخیره‌شده

    public function __construct(Api $telegram, string $chatId)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
        $this->sentLinksFile = "feeds/sent_{$chatId}.json";

        $this->httpClient = new Client([
            'timeout' => 15,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; LumenRSSBot/1.0; +https://telegram-rss-bot-kmgj.onrender.com)'
            ]
        ]);
        $this->loadConfig();
        $this->initializeSentLinks();
        Log::warning("SSL verification disabled for HTTP requests. Consider updating CA bundle for security.", ['chat_id' => $this->chatId]);
    }

    protected function initializeSentLinks()
    {
        if (!Storage::exists($this->sentLinksFile)) {
            Storage::put($this->sentLinksFile, json_encode(['sent_links' => []], JSON_UNESCAPED_UNICODE));
            Log::info("Initialized sent links file for chat_id: {$this->chatId}", ['file' => $this->sentLinksFile]);
        }
    }

    protected function loadSentLinks()
    {
        if (Storage::exists($this->sentLinksFile)) {
            $data = json_decode(Storage::get($this->sentLinksFile), true);
            $sentLinks = $data['sent_links'] ?? [];
            if (count($sentLinks) > $this->maxSentLinks) {
                $sentLinks = array_slice($sentLinks, -$this->maxSentLinks);
                Storage::put($this->sentLinksFile, json_encode(['sent_links' => $sentLinks], JSON_UNESCAPED_UNICODE));
                Log::info("Trimmed sent links to {$this->maxSentLinks} for chat_id: {$this->chatId}");
            }
            return $sentLinks;
        }
        return [];
    }

    protected function saveSentLink($link)
    {
        if (!filter_var($link, FILTER_VALIDATE_URL) || $link === '#') {
            Log::warning("Invalid link not saved for chat_id: {$this->chatId}", ['link' => $link]);
            return;
        }
        $sentLinks = $this->loadSentLinks();
        if (!in_array($link, $sentLinks)) {
            $sentLinks[] = $link;
            Storage::put($this->sentLinksFile, json_encode(['sent_links' => $sentLinks], JSON_UNESCAPED_UNICODE));
            Log::info("Saved sent link for chat_id: {$this->chatId}", ['link' => $link]);
        } else {
            Log::debug("Link already in sentLinks for chat_id: {$this->chatId}", ['link' => $link]);
        }
    }

    protected function normalizeFeedName($name)
    {
        $cleaned = preg_replace('/[\s\t\n\r]+/u', ' ', trim($name));
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned);
        return $cleaned;
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
            if (class_exists('\Illuminate\Support\Facades\Storage')) {
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
        $cleanedFeeds = [];
        foreach ($this->config['feeds'] as $name => $url) {
            $cleanedName = $this->normalizeFeedName($name);
            $cleanedFeeds[$cleanedName] = $url;
        }
        $this->config['feeds'] = $cleanedFeeds;
        Log::info("Loaded config for chat_id: {$this->chatId}", ['config' => $this->config]);
    }

    protected function saveConfig($config)
    {
        $cleanedFeeds = [];
        foreach ($config['feeds'] as $name => $url) {
            $cleanedName = $this->normalizeFeedName($name);
            $cleanedFeeds[$cleanedName] = $url;
        }
        $config['feeds'] = $cleanedFeeds;

        $this->config = $config;
        $envKey = "FEEDS_CONFIG_{$this->chatId}";
        $jsonConfig = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        Log::info("Please set env $envKey=$jsonConfig for chat_id: {$this->chatId} to persist changes permanently");
        
        if (class_exists('\Illuminate\Support\Facades\Storage')) {
            $configFile = "feeds/{$this->chatId}.json";
            try {
                Storage::put($configFile, $jsonConfig);
                Log::info("Saved config to $configFile for chat_id: {$this->chatId}", ['config' => $config]);
            } catch (\Exception $e) {
                Log::error("Failed to save config to $configFile for chat_id: {$this->chatId}: {$e->getMessage()}");
                throw $e;
            }
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
                $feedList = !empty($this->config['feeds']) ? implode("\n", array_map(function ($name, $url) {
                    $cleanedName = $this->normalizeFeedName($name);
                    return "🦗 $cleanedName: " . htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                }, array_keys($this->config['feeds']), $this->config['feeds'])) : 'هیچ فیدی نیست!';
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
                $this->sendLatestNews($replyMarkup, true);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'اخبار بررسی شد!',
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Sent confirmation for news fetch for chat_id: {$this->chatId}", ['duration' => microtime(true) - $startTime]);
            } elseif ($text === 'شروع فیدها') {
                $this->config['auto_send'] = true;
                $this->saveConfig($this->config);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "ارسال خودکار اخبار فعال شد!",
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Enabled auto-send for chat_id: {$this->chatId}");
            } elseif ($text === 'توقف') {
                $this->config['auto_send'] = false;
                $this->saveConfig($this->config);
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => 'ارسال خودکار اخبار متوقف شد!',
                    'reply_markup' => $replyMarkup
                ]);
                Log::info("Disabled auto-send for chat_id: {$this->chatId}");
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
                        $name = $this->normalizeFeedName($name);
                        if (filter_var($url, FILTER_VALIDATE_URL)) {
                            $newFeeds[$name] = $url;
                        }
                    }
                }
                if (!empty($newFeeds)) {
                    $this->config['feeds'] = $newFeeds;
                    $this->saveConfig($this->config);
                    $feedList = implode("\n", array_map(function ($name, $url) {
                        $cleanedName = $this->normalizeFeedName($name);
                        return "🦗 $cleanedName: " . htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                    }, array_keys($this->config['feeds']), $this->config['feeds']));
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
        Log::info("Checking feeds for auto-send for chat_id: {$this->chatId}");
        if ($this->config['auto_send'] === true) {
            $replyMarkup = json_encode($this->getReplyMarkup());
            $startTime = microtime(true);
            $this->sendLatestNews($replyMarkup, false);
            Log::info("Auto-send triggered for chat_id: {$this->chatId}", ['duration' => microtime(true) - $startTime]);
        } else {
            Log::info("Auto-send is disabled for chat_id: {$this->chatId}");
        }
    }

    protected function cleanDescription($description)
    {
        $cleaned = strip_tags($description);
        $cleaned = preg_replace('/[\n\r\t]+/', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned);
        $cleaned = preg_replace('/(منبع:.*|The post.*|خبرگزاری.*|برای اولین بار.*|نوشته شده.*|به گزارش سایت اخبار تکنولوژی تکراتو.*|به گزارش تکراتو و به نقل از.*|بیسیمچی مدیا.*|ایرنا.*|مهر.*|خبرفوری.*)/iu', '', $cleaned);
        $cleaned = trim($cleaned);
        $cleaned = mb_substr($cleaned, 0, 150, 'UTF-8');
        return !empty($cleaned) ? $cleaned : 'بدون توضیحات';
    }

    protected function cleanXmlContent($xmlContent)
    {
        // حذف BOM (Byte Order Mark)
        $xmlContent = preg_replace('/^\xEF\xBB\xBF/', '', $xmlContent);
        // حذف کاراکترهای غیر UTF-8
        $xmlContent = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $xmlContent);
        // اطمینان از UTF-8 encoding
        if (!mb_check_encoding($xmlContent, 'UTF-8')) {
            $xmlContent = mb_convert_encoding($xmlContent, 'UTF-8', 'auto');
        }
        // اضافه کردن XML declaration اگه وجود نداشته باشه
        if (!preg_match('/^<\?xml\s+version=/i', $xmlContent)) {
            $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlContent;
        }
        return $xmlContent;
    }

    protected function getFeedData($item, $namespaces, $tag, $fallbackTag = null)
    {
        if (!($item instanceof \SimpleXMLElement)) {
            Log::error("Invalid item type in getFeedData, expected SimpleXMLElement, got " . gettype($item));
            return $tag === 'link' ? '#' : 'بدون ' . $tag;
        }

        if (isset($item->$tag)) {
            $value = (string)$item->$tag;
            if ($tag === 'description') {
                $value = $this->cleanDescription($value);
            }
            return $value !== '' ? $value : 'بدون ' . $tag;
        }

        if ($fallbackTag && isset($namespaces['dc']) && $item->children($namespaces['dc'])->$fallbackTag) {
            $value = (string)$item->children($namespaces['dc'])->$fallbackTag;
            if ($tag === 'description') {
                $value = $this->cleanDescription($value);
            }
            return $value !== '' ? $value : 'بدون ' . $tag;
        }

        if ($tag === 'link') {
            if (isset($namespaces['atom']) && $item->children($namespaces['atom'])->link) {
                $link = $item->children($namespaces['atom'])->link;
                if (isset($link->attributes()->href)) {
                    $value = (string)$link->attributes()->href;
                    return $value !== '' ? $value : '#';
                }
            }
        }

        if ($tag === 'title' && isset($namespaces['content']) && $item->children($namespaces['content'])->encoded) {
            $value = $this->cleanDescription((string)$item->children($namespaces['content'])->encoded);
            return $value !== '' ? $value : 'بدون ' . $tag;
        }

        if ($tag === 'pubDate' && isset($namespaces['dc']) && $item->children($namespaces['dc'])->date) {
            $value = (string)$item->children($namespaces['dc'])->date;
            return $value !== '' ? $value : 'بدون ' . $tag;
        }

        return $tag === 'link' ? '#' : 'بدون ' . $tag;
    }

    protected function isRecent($pubDate)
    {
        try {
            $pubDateTime = new DateTime($pubDate);
            $pubDateTime->setTimezone(new DateTimeZone('GMT')); // تبدیل به GMT
            $now = new DateTime('now', new DateTimeZone('GMT'));
            $interval = $now->getTimestamp() - $pubDateTime->getTimestamp();
            $isRecent = $interval <= 10 * 60; // 10 دقیقه
            if (!$isRecent) {
                Log::debug("Item filtered out due to old pubDate (older than 10 minutes)", ['pubDate' => $pubDate, 'interval' => $interval]);
            }
            return $isRecent;
        } catch (\Exception $e) {
            Log::error("Invalid pubDate format: $pubDate", ['error' => $e->getMessage()]);
            return false;
        }
    }

    protected function formatJalaliDate($pubDate)
    {
        try {
            $dateTime = new DateTime($pubDate);
            $dateTime->setTimezone(new DateTimeZone('Asia/Tehran'));
            $jalali = Jalalian::fromDateTime($dateTime);
            return $jalali->format('l j F Y، H:i');
        } catch (\Exception $e) {
            Log::error("Failed to convert pubDate to Jalali: $pubDate", ['error' => $e->getMessage()]);
            return $pubDate;
        }
    }

    public function testFeed($url)
    {
        try {
            Log::info("Testing feed: $url for chat_id: {$this->chatId}");
            $response = $this->httpClient->get($url);
            $xmlContent = $response->getBody()->getContents();
            $xmlContent = $this->cleanXmlContent($xmlContent);
            $xml = @simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($xml === false) {
                $errors = libxml_get_errors();
                $errorMsg = "Failed to parse XML for feed: $url. Errors: " . json_encode($errors, JSON_UNESCAPED_UNICODE);
                $xmlSnippet = substr($xmlContent, 0, 500); // لاگ 500 کاراکتر اول XML
                Log::error($errorMsg, ['chat_id' => $this->chatId, 'xml_snippet' => $xmlSnippet]);
                return ['status' => 'error', 'message' => $errorMsg];
            }
            libxml_clear_errors();

            $namespaces = $xml->getNamespaces(true);
            $items = $xml->channel->item ?? [];
            $itemCount = 0;
            $itemArray = [];
            foreach ($items as $item) {
                $itemCount++;
                $pubDate = $this->getFeedData($item, $namespaces, 'pubDate', 'date');
                $link = $this->getFeedData($item, $namespaces, 'link', 'identifier');
                $description = $this->getFeedData($item, $namespaces, 'description');
                $itemArray[] = [
                    'title' => $this->getFeedData($item, $namespaces, 'title', 'title'),
                    'link' => $link,
                    'pubDate' => $pubDate,
                    'isRecent' => $this->isRecent($pubDate),
                    'description' => $description
                ];
            }
            Log::info("Found $itemCount items in feed: $url for chat_id: {$this->chatId}", ['items' => $itemArray]);

            return [
                'status' => 'success',
                'item_count' => $itemCount,
                'items' => $itemArray
            ];
        } catch (RequestException $e) {
            $errorMsg = $e->hasResponse() ? $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() : $e->getMessage();
            Log::error("Failed to load feed content: $url for chat_id: {$this->chatId}: $errorMsg");
            return ['status' => 'error', 'message' => "Failed to load feed: $errorMsg"];
        }
    }

    protected function sendLatestNews($replyMarkup, $previewOnly = false)
    {
        $startTime = microtime(true);
        Log::info("Processing sendLatestNews for chat_id: {$this->chatId}", ['previewOnly' => $previewOnly]);
        if (empty($this->config['feeds'])) {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'هیچ فیدی ثبت نشده. لطفاً با "تغییر فید" یه فید اضافه کنید.',
                'reply_markup' => $replyMarkup
            ]);
            Log::info("No feeds for chat_id: {$this->chatId}");
            return;
        }

        set_time_limit(30); // Timeout کلی 30 ثانیه

        $sentLinks = $this->loadSentLinks();
        $hasNews = false;
        $inactiveFeeds = [];
        $feedsToCheck = $this->config['feeds'];
        $requests = [];

        foreach ($feedsToCheck as $name => $url) {
            $name = $this->normalizeFeedName($name);
            $cacheFile = "feeds/cache_{$this->chatId}_" . md5($url) . ".xml";
            $requests[] = ['name' => $name, 'url' => $url, 'cacheFile' => $cacheFile];
        }

        $results = [];
        $pool = new Pool($this->httpClient, array_map(function ($req) {
            return new Request('GET', $req['url']);
        }, $requests), [
            'concurrency' => 2, // حداکثر 2 درخواست همزمان
            'fulfilled' => function ($response, $index) use (&$results, $requests) {
                $results[$index] = [
                    'status' => 'success',
                    'name' => $requests[$index]['name'],
                    'url' => $requests[$index]['url'],
                    'cacheFile' => $requests[$index]['cacheFile'],
                    'content' => $response->getBody()->getContents()
                ];
            },
            'rejected' => function ($reason, $index) use (&$results, $requests) {
                $errorMsg = $reason instanceof RequestException && $reason->hasResponse()
                    ? $reason->getResponse()->getStatusCode() . ' ' . $reason->getResponse()->getReasonPhrase()
                    : $reason->getMessage();
                $results[$index] = [
                    'status' => 'error',
                    'name' => $requests[$index]['name'],
                    'url' => $requests[$index]['url'],
                    'cacheFile' => $requests[$index]['cacheFile'],
                    'error' => $errorMsg
                ];
            }
        ]);

        $promise = $pool->promise();
        $promise->wait();

        foreach ($results as $result) {
            $name = $result['name'];
            $url = $result['url'];
            $cacheFile = $result['cacheFile'];

            if ($result['status'] === 'error') {
                Log::error("Failed to load feed content: $name ($url) for chat_id: {$this->chatId}: {$result['error']}");
                $this->telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "خطا در بارگذاری فید $name: {$result['error']}",
                    'reply_markup' => $replyMarkup
                ]);
                $inactiveFeeds[] = $name;
                continue;
            }

            try {
                $cacheTTL = 120; // 2 دقیقه
                if (Storage::exists($cacheFile) && (time() - Storage::lastModified($cacheFile)) < $cacheTTL) {
                    $xmlContent = Storage::get($cacheFile);
                    Log::info("Using cached feed content for $name ($url)", ['file' => $cacheFile]);
                } else {
                    $xmlContent = $this->cleanXmlContent($result['content']);
                    Storage::put($cacheFile, $xmlContent);
                    Log::info("Fetched and cached feed content for $name ($url)", ['file' => $cacheFile, 'length' => strlen($xmlContent)]);
                }

                $xml = @simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
                if ($xml === false) {
                    $errors = libxml_get_errors();
                    $errorMsg = "Failed to parse XML for feed: $name ($url). Errors: " . json_encode($errors, JSON_UNESCAPED_UNICODE);
                    $xmlSnippet = substr($xmlContent, 0, 500); // لاگ 500 کاراکتر اول XML
                    Log::error($errorMsg, ['chat_id' => $this->chatId, 'xml_snippet' => $xmlSnippet]);
                    $this->telegram->sendMessage([
                        'chat_id' => $this->chatId,
                        'text' => "خطا در پارس XML فید $name",
                        'reply_markup' => $replyMarkup
                    ]);
                    $inactiveFeeds[] = $name;
                    libxml_clear_errors();
                    continue;
                }

                $namespaces = $xml->getNamespaces(true);
                $items = $xml->channel->item ?? [];
                $latestItems = [];
                foreach ($items as $item) {
                    $link = $this->getFeedData($item, $namespaces, 'link', 'identifier');
                    $pubDate = $this->getFeedData($item, $namespaces, 'pubDate', 'date');
                    if ($this->isRecent($pubDate) && !in_array($link, $sentLinks)) {
                        $latestItems[] = $item;
                    }
                    if (count($latestItems) >= 5) { // حداکثر 5 خبر
                        break;
                    }
                }

                Log::info("Processing $name: Selected " . count($latestItems) . " items for sending", [
                    'titles' => array_map(function($item) use ($namespaces) {
                        return (string)$this->getFeedData($item, $namespaces, 'title', 'title');
                    }, $latestItems),
                    'pubDates' => array_map(function($item) use ($namespaces) {
                        return (string)$this->getFeedData($item, $namespaces, 'pubDate', 'date');
                    }, $latestItems)
                ]);

                if (!empty($latestItems)) {
                    $hasNews = true;
                } else {
                    Log::info("No recent items found for $name ($url) within 10 minutes");
                    $inactiveFeeds[] = $name;
                }

                foreach ($latestItems as $index => $item) {
                    $title = $this->getFeedData($item, $namespaces, 'title', 'title');
                    $link = $this->getFeedData($item, $namespaces, 'link', 'identifier');
                    $pubDate = $this->getFeedData($item, $namespaces, 'pubDate', 'date');
                    $description = $this->getFeedData($item, $namespaces, 'description');
                    $jalaliDate = $this->formatJalaliDate($pubDate);

                    $linkHtml = $link !== '#' ? "<a href=\"$link\">مشاهده خبر</a>" : 'بدون لینک';
                    $message = "📰 سایت: $name\n🗞️ عنوان: <b>$title</b>\n\n📝 توضیحات: " . htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
                    $message .= "\n\n🕒 زمان انتشار: <i>$jalaliDate</i>\n\n🔗 $linkHtml";

                    try {
                        $this->telegram->sendMessage([
                            'chat_id' => $this->chatId,
                            'text' => $message,
                            'parse_mode' => 'HTML',
                            'disable_web_page_preview' => false,
                            'reply_markup' => $replyMarkup
                        ]);
                        Log::info("Sent news #$index: $title from $name for chat_id: {$this->chatId}", ['link' => $link, 'pubDate' => $pubDate]);
                        $this->saveSentLink($link);
                    } catch (\Exception $e) {
                        Log::error("Failed to send news #$index: $title from $name: {$e->getMessage()}");
                    }
                }
            } catch (\Exception $e) {
                Log::error("Error processing feed $name ($url) for chat_id: {$this->chatId}: {$e->getMessage()}");
                $inactiveFeeds[] = $name;
            }
        }

        if (!$hasNews) {
            $text = 'خبری در 10 دقیقه اخیر یافت نشد.';
            if (!empty($inactiveFeeds)) {
                $text .= "\nفیدهای بدون خبر جدید: " . implode(', ', $inactiveFeeds);
            }
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => $text,
                'reply_markup' => $replyMarkup
            ]);
            Log::info("No recent news found within 10 minutes for any feed for chat_id: {$this->chatId}", ['inactiveFeeds' => $inactiveFeeds]);
        }

        Log::info("Finished sendLatestNews for chat_id: {$this->chatId}", ['duration' => microtime(true) - $startTime]);
    }

    public function getConfig()
    {
        return $this->config;
    }
}
?>