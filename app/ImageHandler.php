<?php
namespace App;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;

class ImageHandler
{
    protected $chatId;
    protected $httpClient;
    protected $defaultImage = 'https://www.khabaronline.ir/assets/khabaronline-logo.png';
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
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
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
        $filename = md5($imageUrl) . '.' . pathinfo($imageUrl, PATHINFO_EXTENSION);
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

    public function getOgImage($url)
    {
        $cache = $this->loadImageCache();
        if (isset($cache[$url]) && (time() - $cache[$url]['timestamp']) < 3600) {
            Log::info("Using cached og:image for $url", ['image' => $cache[$url]['image']]);
            return $this->cacheImageLocally($cache[$url]['image']);
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $response = $this->httpClient->get($url);
                $html = $response->getBody()->getContents();
                $metaTags = [
                    '/<meta\s+property="og:image"\s+content="([^"]+\.(jpg|jpeg|png))"/i',
                    '/<meta\s+property="og:image:secure_url"\s+content="([^"]+\.(jpg|jpeg|png))"/i',
                    '/<meta\s+name="twitter:image"\s+content="([^"]+\.(jpg|jpeg|png))"/i',
                    '/<meta\s+property="og:image"\s+content="([^"]+)"/i',
                    '/<meta\s+name="image"\s+content="([^"]+)"/i',
                    '/<img\s+src="([^"]+\.(jpg|jpeg|png))"[^>]*>/i'
                ];
                $foundImages = [];
                foreach ($metaTags as $pattern) {
                    if (preg_match_all($pattern, $html, $matches)) {
                        $foundImages = array_merge($foundImages, $matches[1]);
                    }
                }
                $image = !empty($foundImages) ? $foundImages[0] : $this->defaultImage;
                $cachedImage = $this->cacheImageLocally($image);
                $cache[$url] = ['image' => $image, 'timestamp' => time()];
                $this->saveImageCache($cache);
                Log::info("Found og:image for $url", [
                    'image' => $image, 
                    'foundImages' => $foundImages, 
                    'cachedImage' => $cachedImage,
                    'response_time' => $response->getHeader('X-Response-Time') ?? 'unknown'
                ]);
                return $cachedImage;
            } catch (\Exception $e) {
                Log::warning("Retry $attempt for og:image $url: {$e->getMessage()}", [
                    'error_code' => $e->getCode(),
                    'error_details' => $e instanceof RequestException && $e->hasResponse() 
                        ? $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() 
                        : 'No response'
                ]);
                if ($attempt < 5) {
                    sleep($attempt * 2);
                    continue;
                }
                Log::error("Failed to fetch og:image for $url after 5 attempts: {$e->getMessage()}");
                $cache[$url] = ['image' => $this->defaultImage, 'timestamp' => time()];
                $this->saveImageCache($cache);
                return $this->cacheImageLocally($this->defaultImage);
            }
        }
        return $this->cacheImageLocally($this->defaultImage);
    }
}
?>