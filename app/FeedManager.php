<?php
namespace App;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use DateTime;
use DateTimeZone;
use Morilog\Jalali\Jalalian;

class FeedManager
{
    protected $chatId;
    protected $httpClient;
    protected $defaultImage = 'https://www.khabaronline.ir/assets/khabaronline-logo.png';
    protected $localImagePath = 'public/images/';
    protected $imageCacheFile;

    public function __construct(string $chatId)
    {
        $this->chatId = $chatId;
        $this->imageCacheFile = "feeds/image_cache_{$chatId}.json";
        $this->httpClient = new Client([
            'timeout' => 120,
            'connect_timeout' => 20,
            'verify' => false,
            'proxy' => env('HTTP_PROXY', ''),
            'headers' => [
                'User-Agent' => 'TelegramBot/1.0 (+https://core.telegram.org/bots)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'X-Telegram-Bot' => 'LumenRSSBot/1.0'
            ]
        ]);
        $this->initializeImageCache();
        Log::warning("SSL verification disabled for HTTP requests in FeedManager", ['chat_id' => $this->chatId]);
    }

    protected function initializeImageCache()
    {
        if (!Storage::exists($this->imageCacheFile)) {
            Storage::put($this->imageCacheFile, json_encode([], JSON_UNESCAPED_UNICODE));
            Log::info("Initialized image cache file for chat_id: {$this->chatId}", ['file' => $this->imageCacheFile]);
        }
        if (!Storage::exists($this->localImagePath)) {
            Storage::makeDirectory($this->localImagePath);
            Log::info("Created local image directory for chat_id: {$this->chatId}", ['path' => $this->localImagePath]);
        }
    }

    protected function loadImageCache()
    {
        if (Storage::exists($this->imageCacheFile)) {
            $cache = json_decode(Storage::get($this->imageCacheFile), true);
            return is_array($cache) ? $cache : [];
        }
        return [];
    }

    protected function saveImageCache($cache)
    {
        try {
            Storage::put($this->imageCacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE));
            Log::info("Saved image cache for chat_id: {$this->chatId}", ['cache_size' => count($cache)]);
        } catch (\Exception $e) {
            Log::error("Failed to save image cache for chat_id: {$this->chatId}: {$e->getMessage()}");
        }
    }

    protected function cacheImageLocally($imageUrl)
    {
        $filename = md5($imageUrl) . '.' . (pathinfo($imageUrl, PATHINFO_EXTENSION) ?: 'png');
        $localPath = $this->localImagePath . $filename;
        $publicUrl = env('APP_URL') . '/images/' . $filename;

        if (Storage::exists($localPath) && (time() - Storage::lastModified($localPath)) < 86400) {
            Log::info("Using cached local image for $imageUrl", ['localPath' => $localPath, 'publicUrl' => $publicUrl]);
            return $publicUrl;
        }

        try {
            $response = $this->httpClient->get($imageUrl);
            if ($response->getStatusCode() === 200) {
                $imageContent = $response->getBody()->getContents();
                Storage::put($localPath, $imageContent);
                Log::info("Cached image locally for $imageUrl", ['localPath' => $localPath, 'publicUrl' => $publicUrl]);
                return $publicUrl;
            }
            Log::warning("Invalid response for image $imageUrl", ['status' => $response->getStatusCode()]);
            return $this->defaultImage;
        } catch (\Exception $e) {
            Log::error("Failed to cache image locally for $imageUrl: {$e->getMessage()}");
            return $this->defaultImage;
        }
    }

    protected function getOgImage($url)
    {
        $cache = $this->loadImageCache();
        if (isset($cache[$url]) && (time() - $cache[$url]['timestamp']) < 3600) {
            Log::info("Using cached og:image for $url", ['image' => $cache[$url]['image']]);
            return $this->cacheImageLocally($cache[$url]['image']);
        }

        try {
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $html = $response->getBody()->getContents();
            $metaTags = [
                '/<meta\s+property="og:image"\s+content="([^"]+\.(jpg|jpeg|png|webp))"/i',
                '/<meta\s+property="og:image:secure_url"\s+content="([^"]+\.(jpg|jpeg|png|webp))"/i',
                '/<meta\s+name="twitter:image"\s+content="([^"]+\.(jpg|jpeg|png|webp))"/i',
                '/<meta\s+property="og:image"\s+content="([^"]+)"/i',
                '/<img\s+src="([^"]+\.(jpg|jpeg|png|webp))"[^>]*>/i'
            ];
            $foundImages = [];
            foreach ($metaTags as $pattern) {
                if (preg_match_all($pattern, $html, $matches)) {
                    $foundImages = array_merge($foundImages, $matches[1]);
                }
            }
            $image = !empty($foundImages) ? $foundImages[0] : $this->defaultImage;
            if ($image !== $this->defaultImage) {
                // چک اعتبار تصویر
                try {
                    $imageResponse = $this->httpClient->head($image, ['timeout' => 5]);
                    if ($imageResponse->getStatusCode() !== 200 || !in_array(strtolower($imageResponse->getHeaderLine('Content-Type')), ['image/jpeg', 'image/png', 'image/webp'])) {
                        $image = $this->defaultImage;
                    }
                } catch (\Exception $e) {
                    Log::warning("Invalid image URL $image: {$e->getMessage()}");
                    $image = $this->defaultImage;
                }
            }
            $cachedImage = $this->cacheImageLocally($image);
            $cache[$url] = ['image' => $image, 'timestamp' => time()];
            $this->saveImageCache($cache);
            Log::info("Found og:image for $url", ['image' => $image, 'cachedImage' => $cachedImage]);
            return $cachedImage;
        } catch (\Exception $e) {
            Log::error("Failed to fetch og:image for $url: {$e->getMessage()}");
            $cache[$url] = ['image' => $this->defaultImage, 'timestamp' => time()];
            $this->saveImageCache($cache);
            return $this->cacheImageLocally($this->defaultImage);
        }
    }

    protected function extractDescriptionFromPage($url)
    {
        try {
            $response = $this->httpClient->get($url, ['timeout' => 10]);
            $html = $response->getBody()->getContents();
            $doc = new \DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new \DOMXPath($doc);
            $nodes = $xpath->query("//p[not(contains(@class, 'source') or contains(@class, 'meta') or contains(@class, 'footer'))]");
            $text = '';
            foreach ($nodes as $node) {
                $nodeText = trim($node->textContent);
                if ($nodeText && !preg_match('/(منبع:.*|به گزارش.*|خبرگزاری.*|ادامه مطلب.*|کلیک کنید.*|بیشتر بخوانید.*)/iu', $nodeText)) {
                    $text .= $nodeText . ' ';
                    if (mb_strlen($text, 'UTF-8') > 200) {
                        break;
                    }
                }
            }
            $text = $this->cleanDescription($text);
            Log::info("Extracted description from page $url", ['description' => $text]);
            return $text !== '' ? $text : 'بدون توضیحات';
        } catch (\Exception $e) {
            Log::error("Failed to extract description from page $url: {$e->getMessage()}");
            return 'بدون توضیحات';
        }
    }

    protected function cleanDescription($description)
    {
        $cleaned = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleaned = strip_tags($cleaned);
        $cleaned = preg_replace('/[\n\r\t]+/', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned);
        $patterns = [
            '/(ادامه مطلب.*|کلیک کنید.*|بیشتر بخوانید.*|لینک منبع.*|اینجا کلیک کنید.*|مشاهده در.*|تماشا کنید.*)/iu',
            '/\[.*?\]/u',
            '/\(.*?\)/u',
            '/\b(https?:\/\/[^\s]+)/u',
            '/\s*[-–—]\s*/u',
            '/(&nbsp;|&#160;|&zwnj;|&#8204;)/u',
        ];
        $cleaned = preg_replace($patterns, '', $cleaned);
        $cleaned = trim($cleaned);

        if (mb_strlen($cleaned, 'UTF-8') > 200) {
            $pos = mb_strrpos(mb_substr($cleaned, 0, 200, 'UTF-8'), '.', 0, 'UTF-8') ?: 
                   mb_strrpos(mb_substr($cleaned, 0, 200, 'UTF-8'), '،', 0, 'UTF-8') ?: 
                   mb_strrpos(mb_substr($cleaned, 0, 200, 'UTF-8'), ' ', 0, 'UTF-8');
            $cleaned = mb_substr($cleaned, 0, $pos !== false ? $pos + 1 : 200, 'UTF-8');
        }

        return !empty($cleaned) ? $cleaned : 'بدون توضیحات';
    }

    protected function cleanXmlContent($xmlContent)
    {
        $xmlContent = preg_replace('/^\xEF\xBB\xBF/', '', $xmlContent);
        $xmlContent = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $xmlContent);
        if (!mb_check_encoding($xmlContent, 'UTF-8')) {
            $xmlContent = mb_convert_encoding($xmlContent, 'UTF-8', 'auto');
        }
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

        if ($tag === 'description') {
            $contentSources = [
                ['namespace' => 'content', 'tag' => 'encoded'],
                ['namespace' => null, 'tag' => 'description'],
                ['namespace' => null, 'tag' => 'summary'],
                ['namespace' => null, 'tag' => 'content']
            ];
            foreach ($contentSources as $source) {
                if ($source['namespace'] && isset($namespaces[$source['namespace']])) {
                    $value = (string)$item->children($namespaces[$source['namespace']])->{$source['tag']};
                } else {
                    $value = isset($item->{$source['tag']}) ? (string)$item->children($source['tag']) : '';
                }
                if ($value !== '') {
                    $cleaned = $this->cleanDescription($value);
                    Log::debug("Using {$source['tag']} for description", ['raw' => substr($value, 0, 500)]);
                    if ($cleaned !== 'بدون توضیحات') {
                        return $cleaned;
                    }
                }
            }
            $link = $this->getFeedData($item, $namespaces, 'link', 'identifier');
            if ($link !== '#') {
                return $this->extractDescriptionFromPage($link);
            }
            return 'بدون توضیحات';
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

        if ($tag === 'link' && isset($namespaces['atom']) && $item->children($namespaces['atom'])->link) {
            $link = $item->children($namespaces['atom'])->link;
            if (isset($link->attributes()->href)) {
                $value = (string)$link->attributes()->href;
                return $value !== '' ? $value : '#';
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
            $pubDateTime = new DateTime($pubDate, new DateTimeZone('GMT'));
            $now = new DateTime('now', new DateTimeZone('GMT'));
            $interval = $now->getTimestamp() - $pubDateTime->getTimestamp();
            $isRecent = $interval <= 10 * 60;
            if (!$isRecent) {
                Log::debug("Item filtered out due to old pubDate", ['pubDate' => $pubDate, 'interval' => $interval]);
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
            $dateTime = new DateTime($pubDate, new DateTimeZone('GMT'));
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
            $response = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $response = $this->httpClient->get($url);
                    break;
                } catch (RequestException $e) {
                    if ($attempt < 3) {
                        Log::warning("Retry $attempt for feed $url: {$e->getMessage()}");
                        sleep($attempt * 2);
                        continue;
                    }
                    throw $e;
                }
            }
            $xmlContent = $response->getBody()->getContents();
            $xmlContent = $this->cleanXmlContent($xmlContent);
            $xml = @simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($xml === false) {
                $errors = libxml_get_errors();
                $errorMsg = "Failed to parse XML for feed: $url. Errors: " . json_encode($errors, JSON_UNESCAPED_UNICODE);
                Log::error($errorMsg, ['chat_id' => $this->chatId, 'xml_snippet' => substr($xmlContent, 0, 500)]);
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
                    'description' => $description,
                    'rawDescription' => (string)$item->description,
                    'rawContent' => isset($namespaces['content']) ? (string)$item->children($namespaces['content'])->encoded : ''
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

    public function sendLatestNews($telegram, $replyMarkup, $previewOnly = false)
    {
        $startTime = microtime(true);
        $storageManager = new StorageManager($this->chatId);
        $config = $storageManager->loadConfig();
        Log::info("Processing sendLatestNews for chat_id: {$this->chatId}", [
            'previewOnly' => $previewOnly,
            'auto_send' => $config['auto_send'],
            'feeds' => array_keys($config['feeds'])
        ]);

        if (empty($config['feeds'])) {
            $telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'هیچ فیدی ثبت نشده. لطفاً با "تغییر فید" یه فید اضافه کنید.',
                'reply_markup' => $replyMarkup
            ]);
            Log::info("No feeds for chat_id: {$this->chatId}");
            return;
        }

        set_time_limit(120);
        $sentLinks = $storageManager->loadSentLinks();
        $hasNews = false;
        $inactiveFeeds = [];
        $requests = [];

        foreach ($config['feeds'] as $name => $url) {
            $name = $storageManager->normalizeFeedName($name);
            $cacheFile = "feeds/cache_{$this->chatId}_" . md5($url) . ".xml";
            $requests[] = ['name' => $name, 'url' => $url, 'cacheFile' => $cacheFile];
        }

        $results = [];
        $pool = new Pool($this->httpClient, array_map(function ($req) {
            return new Request('GET', $req['url']);
        }, $requests), [
            'concurrency' => 4,
            'fulfilled' => function ($response, $index) use (&$results, $requests) {
                $results[$index] = [
                    'status' => 'success',
                    'name' => $requests[$index]['name'],
                    'url' => $requests[$index]['url'],
                    'cacheFile' => $requests[$index]['cacheFile'],
                    'content' => $response->getBody()->getContents(),
                    'headers' => $response->getHeaders()
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
                $telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => "خطا در بارگذاری فید $name: {$result['error']}",
                    'reply_markup' => $replyMarkup
                ]);
                $inactiveFeeds[] = $name;
                continue;
            }

            try {
                $cacheTTL = 300;
                $forceRefresh = !Storage::exists($cacheFile) || (time() - Storage::lastModified($cacheFile)) >= $cacheTTL;
                if (!$forceRefresh) {
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
                    Log::error($errorMsg, ['chat_id' => $this->chatId, 'xml_snippet' => substr($xmlContent, 0, 500)]);
                    $telegram->sendMessage([
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
                foreach ($items as $index => $item) {
                    if ($index >= 10) break;
                    $link = $this->getFeedData($item, $namespaces, 'link', 'identifier');
                    $pubDate = $this->getFeedData($item, $namespaces, 'pubDate', 'date');
                    if ($this->isRecent($pubDate) && !in_array($link, $sentLinks)) {
                        $latestItems[] = $item;
                    }
                    if (count($latestItems) >= 5) {
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
                    $ogImage = $this->getOgImage($link);

                    // فرمت پیام برای Instant View
                    $message = "<b>$name</b>\n";
                    $message .= "<b>$title</b>\n\n";
                    if ($description !== 'بدون توضیحات') {
                        $message .= "$description\n\n";
                    }
                    $message .= "<i>$jalaliDate</i>\n";
                    $message .= "<a href=\"$link\">مشاهده خبر</a>";

                    try {
                        $telegram->sendPhoto([
                            'chat_id' => $this->chatId,
                            'photo' => $ogImage,
                            'caption' => $message,
                            'parse_mode' => 'HTML',
                            'disable_web_page_preview' => false,
                            'reply_markup' => $replyMarkup
                        ]);
                        Log::info("Sent news #$index: $title from $name for chat_id: {$this->chatId}", ['link' => $link, 'pubDate' => $pubDate, 'ogImage' => $ogImage]);
                        $storageManager->saveSentLink($link);
                        sleep(1);
                    } catch (\Exception $e) {
                        Log::error("Failed to send photo for news #$index: $title from $name: {$e->getMessage()}");
                        try {
                            $telegram->sendMessage([
                                'chat_id' => $this->chatId,
                                'text' => $message,
                                'parse_mode' => 'HTML',
                                'disable_web_page_preview' => false,
                                'reply_markup' => $replyMarkup
                            ]);
                            Log::info("Sent news #$index without photo: $title from $name for chat_id: {$this->chatId}", ['link' => $link]);
                            $storageManager->saveSentLink($link);
                        } catch (\Exception $e2) {
                            Log::error("Failed to send message for news #$index: $title from $name: {$e2->getMessage()}");
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("Error processing feed $name ($url) for chat_id: {$this->chatId}: {$e->getMessage()}");
                $inactiveFeeds[] = $name;
            }
        }

        if (!$hasNews) {
            $text = 'هیچ خبر جدیدی در 10 دقیقه اخیر یافت نشد.';
            if (!empty($inactiveFeeds)) {
                $text .= "\nفیدهای بدون خبر جدید: " . implode(', ', $inactiveFeeds);
            }
            try {
                $telegram->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $text,
                    'reply_markup' => $replyMarkup
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to send no-news message for chat_id: {$this->chatId}: {$e->getMessage()}");
            }
            Log::info("No recent news found within 10 minutes for any feed for chat_id: {$this->chatId}", ['inactiveFeeds' => $inactiveFeeds]);
        }

        Log::info("Finished sendLatestNews for chat_id: {$this->chatId}", ['duration' => microtime(true) - $startTime, 'hasNews' => $hasNews]);
    }
}