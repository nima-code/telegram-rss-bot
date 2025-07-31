<?php
namespace App;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;

class ImageHandler
{
    protected $chatId;
    protected $httpClient;
    protected $defaultImage = '/images/default.jpg'; // تصویر پیش‌فرض عمومی
    protected $fallbackImages = [
        'khabaronline.ir' => '/images/khabaronline.jpg', // تصویر پیش‌فرض برای خبرانلاین
        'default' => '/images/default.jpg'
    ];
    protected $imageCacheFile;
    protected $localImagePath = 'public/images/';

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
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/jpeg,image/png,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'X-Telegram-Bot' => 'LumenRSSBot/1.0'
            ]
        ]);
        $this->initializeImageCache();
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
            return json_decode(Storage::get($this->imageCacheFile), true) ?? [];
        }
        return [];
    }

    protected function saveImageCache($cache)
    {
        Storage::put($this->imageCacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE));
        Log::info("Saved image cache for chat_id: {$this->chatId}", ['cache_size' => count($cache)]);
    }

    public function cacheImageLocally($imageUrl)
    {
        $filename = md5($imageUrl) . '.' . pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
        $localPath = $this->localImagePath . $filename;
        $publicUrl = env('APP_URL') . '/images/' . $filename;

        if (Storage::exists($localPath) && (time() - Storage::lastModified($localPath)) < 86400) {
            Log::info("Using cached local image for $imageUrl", ['localPath' => $localPath, 'publicUrl' => $publicUrl]);
            return $publicUrl;
        }

        try {
            $response = $this->httpClient->get($imageUrl);
            $imageContent = $response->getBody()->getContents();
            Storage::put($localPath, $imageContent);
            Log::info("Cached image locally for $imageUrl", ['localPath' => $localPath, 'publicUrl' => $publicUrl]);
            return $publicUrl;
        } catch (\Exception $e) {
            Log::error("Failed to cache image locally for $imageUrl: {$e->getMessage()}");
            return $imageUrl;
        }
    }

    public function getOgImage($url, $rssItem = null, $namespaces = [])
    {
        $cache = $this->loadImageCache();
        if (isset($cache[$url]) && (time() - $cache[$url]['timestamp']) < 3600) {
            Log::info("Using cached og:image for $url", ['image' => $cache[$url]['image']]);
            return $this->cacheImageLocally($cache[$url]['image']);
        }

        // Step 1: Try extracting image from RSS feed
        $rssImage = null;
        if ($rssItem && !empty($namespaces)) {
            try {
                if (isset($namespaces['media']) && $rssItem->children($namespaces['media'])->content) {
                    $mediaContent = $rssItem->children($namespaces['media'])->content;
                    if (isset($mediaContent->attributes()->url)) {
                        $rssImage = (string)$mediaContent->attributes()->url;
                    }
                } elseif (isset($namespaces['media']) && $rssItem->children($namespaces['media'])->thumbnail) {
                    $rssImage = (string)$rssItem->children($namespaces['media'])->thumbnail->attributes()->url;
                }
                if ($rssImage && filter_var($rssImage, FILTER_VALIDATE_URL)) {
                    $cachedImage = $this->cacheImageLocally($rssImage);
                    $cache[$url] = ['image' => $rssImage, 'timestamp' => time()];
                    $this->saveImageCache($cache);
                    Log::info("Found RSS image for $url", ['image' => $rssImage, 'cachedImage' => $cachedImage]);
                    return $cachedImage;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to extract RSS image for $url: {$e->getMessage()}");
            }
        }

        // Step 2: Try extracting image from webpage
        for ($attempt = 1; $attempt <= 7; $attempt++) {
            try {
                $response = $this->httpClient->get($url);
                $html = $response->getBody()->getContents();
                $metaTags = [
                    '/<meta\s+property="og:image"\s+content="([^"]+\.(jpg|jpeg|png|webp|gif))"/i',
                    '/<meta\s+property="og:image:secure_url"\s+content="([^"]+\.(jpg|jpeg|png|webp|gif))"/i',
                    '/<meta\s+name="twitter:image"\s+content="([^"]+\.(jpg|jpeg|png|webp|gif))"/i',
                    '/<meta\s+property="og:image"\s+content="([^"]+)"/i',
                    '/<meta\s+name="image"\s+content="([^"]+)"/i',
                    '/<img\s+src="([^"]+\.(jpg|jpeg|png|webp|gif))"[^>]*width=["\']?\d{2,4}["\']?[^>]*height=["\']?\d{2,4}["\']?[^>]*>/i',
                    '/<img\s+src="([^"]+\.(jpg|jpeg|png|webp|gif))"[^>]*>/i'
                ];
                $foundImages = [];
                foreach ($metaTags as $pattern) {
                    if (preg_match_all($pattern, $html, $matches)) {
                        $foundImages = array_merge($foundImages, $matches[1]);
                    }
                }
                $image = !empty($foundImages) ? $foundImages[0] : null;
                if ($image && filter_var($image, FILTER_VALIDATE_URL)) {
                    $cachedImage = $this->cacheImageLocally($image);
                    $cache[$url] = ['image' => $image, 'timestamp' => time()];
                    $this->saveImageCache($cache);
                    Log::info("Found og:image for $url", [
                        'image' => $image,
                        'foundImages' => $foundImages,
                        'cachedImage' => $cachedImage,
                        'response_time' => $response->getHeader('X-Response-Time') ?? 'unknown',
                        'headers' => $response->getHeaders()
                    ]);
                    return $cachedImage;
                } else {
                    Log::warning("No valid image found in webpage for $url", ['foundImages' => $foundImages]);
                }
            } catch (\Exception $e) {
                $delay = pow(2, $attempt - 1) * 2; // Exponential backoff: 2s, 4s, 8s, 16s, 32s, 64s, 128s
                Log::warning("Retry $attempt for og:image $url: {$e->getMessage()}", [
                    'error_code' => $e->getCode(),
                    'error_details' => $e instanceof RequestException && $e->hasResponse() 
                        ? $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() 
                        : 'No response',
                    'delay' => $delay,
                    'html_snippet' => isset($html) ? substr($html, 0, 500) : 'No HTML'
                ]);
                if ($attempt < 7) {
                    sleep($delay);
                    continue;
                }
                Log::error("Failed to fetch og:image for $url after 7 attempts: {$e->getMessage()}");
            }
        }

        // Step 3: Fallback to site-specific or default image
        $host = parse_url($url, PHP_URL_HOST);
        $fallbackImage = isset($this->fallbackImages[$host]) ? $this->fallbackImages[$host] : $this->fallbackImages['default'];
        $cache[$url] = ['image' => $fallbackImage, 'timestamp' => time()];
        $this->saveImageCache($cache);
        Log::info("Using fallback image for $url", ['fallbackImage' => $fallbackImage]);
        return env('APP_URL') . $fallbackImage;
    }

    public function getOgMetaTags($url)
    {
        try {
            $response = $this->httpClient->get($url);
            $html = $response->getBody()->getContents();
            $metaTags = [
                'og:title' => '/<meta\s+property="og:title"\s+content="([^"]+)"/i',
                'og:description' => '/<meta\s+property="og:description"\s+content="([^"]+)"/i',
                'og:image' => '/<meta\s+property="og:image"\s+content="([^"]+)"/i'
            ];
            $results = [];
            foreach ($metaTags as $key => $pattern) {
                if (preg_match($pattern, $html, $matches)) {
                    $results[$key] = $matches[1];
                }
            }
            Log::info("Extracted og:meta tags for $url", ['metaTags' => $results]);
            return $results;
        } catch (\Exception $e) {
            Log::error("Failed to extract og:meta tags for $url: {$e->getMessage()}");
            return [];
        }
    }
}
?>