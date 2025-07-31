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

class FeedProcessor
{
    protected $telegram;
    protected $chatId;
    protected $imageHandler;
    protected $httpClient;

    public function __construct(Api $telegram, string $chatId, ImageHandler $imageHandler)
    {
        $this->telegram = $telegram;
        $this->chatId = $chatId;
        $this->imageHandler = $imageHandler;
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
                    $value = isset($item->{$source['tag']}) ? (string)$item->{$source['tag']} : '';
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
            $dateTime = new DateTime($pubDate, new DateTimeZone('GMT'));
            $dateTime->setTimezone(new DateTimeZone('Asia/Tehran'));
            $jalali = Jalalian::fromDateTime($dateTime);
            return $jalali->format('l j F Y، H:i');
        } catch (\Exception $e) {
            Log::error("Failed to convert pubDate to Jalali: $pubDate", ['error' => $e->getMessage()]);
            return $pubDate;
        }
    }

    protected function extractDescriptionFromPage($url)
    {
        try {
            $response = $this->httpClient->get($url);
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
            $pos = mb_strrpos(mb_substr($cleaned, 0, 200, 'UTF-8'), '.', 0, 'UTF-8');
            if ($pos === false) {
                $pos = mb_strrpos(mb_substr($cleaned, 0, 200, 'UTF-8'), '،', 0, 'UTF-8');
            }
            if ($pos === false) {
                $pos = mb_strrpos(mb_substr($cleaned, 0, 200, 'UTF-8'), ' ', 0, 'UTF-8');
            }
            $cleaned = mb_substr($cleaned, 0, $pos !== false ? $pos + 1 : 200, 'UTF-8');
        }

        return !empty($cleaned) ? $cleaned : 'بدون توضیحات';
    }

    public function testFeed($url)
    {
        try {
            Log::info("Testing feed: $url for chat_id: {$this->chatId}");
            $response = null;
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                try {
                    $response = $this->httpClient->get($url);
                    break;
                } catch (RequestException $e) {
                    if ($attempt < 5) {
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
                $xmlSnippet = substr($xmlContent, 0, 500);
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

    public function sendLatestNews($replyMarkup, $previewOnly = false)
    {
        $startTime = microtime(true);
        $config = (new ConfigHandler($this->chatId))->getConfig();
        Log::info("Processing sendLatestNews for chat_id: {$this->chatId}", [
            'previewOnly' => $previewOnly, 
            'auto_send' => $config['auto_send'],
            'feeds' => array_keys($config['feeds'])
        ]);
        if (empty($config['feeds'])) {
            $this->telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'هیچ فیدی ثبت نشده. لطفاً با "تغییر فید" یه فید اضافه کنید.',
                'reply_markup' => $replyMarkup
            ]);
            Log::info("No feeds for chat_id: {$this->chatId}");
            return;
        }

        set_time_limit(120);

        $sentLinks = (new ConfigHandler($this->chatId))->loadSentLinks();
        $hasNews = false;
        $inactiveFeeds = [];
        $feedsToCheck = $config['feeds'];
        $requests = [];

        foreach ($feedsToCheck as $name => $url) {
            $name = (new ConfigHandler($this->chatId))->normalizeFeedName($name);
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
                $this->telegram->sendMessage([
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
                    Log::info("Fetched and cached feed content for $name ($url)", ['file' => $cacheFile, 'length' => strlen($xmlContent), 'headers' => $result['headers']]);
                }

                $xml = @simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
                if ($xml === false) {
                    $errors = libxml_get_errors();
                    $errorMsg = "Failed to parse XML for feed: $name ($url). Errors: " . json_encode($errors, JSON_UNESCAPED_UNICODE);
                    $xmlSnippet = substr($xmlContent, 0, 500);
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
                    }, $latestItems),
                    'rawDescriptions' => array_map(function($item) use ($namespaces) {
                        return isset($item->description) ? substr((string)$item->description, 0, 500) : '';
                    }, $latestItems),
                    'rawContents' => array_map(function($item) use ($namespaces) {
                        return isset($namespaces['content']) ? substr((string)$item->children($namespaces['content'])->encoded, 0, 500) : '';
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
                    $ogImage = $this->imageHandler->getOgImage($link);

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
                        Log::info("Sent news #$index: $title from $name for chat_id: {$this->chatId}", ['link' => $link, 'pubDate' => $pubDate, 'ogImage' => $ogImage, 'description' => $description]);
                        (new ConfigHandler($this->chatId))->saveSentLink($link);
                        sleep(1);
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
            $text = 'هیچ خبر جدیدی در 10 دقیقه اخیر یافت نشد. لطفاً بعداً دوباره تلاش کنید یا فیدها را بررسی کنید.';
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

        Log::info("Finished sendLatestNews for chat_id: {$this->chatId}", ['duration' => microtime(true) - $startTime, 'hasNews' => $hasNews]);
    }
}
?>