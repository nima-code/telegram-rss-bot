<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Jalalian;
use Telegram\Bot\Api;

class CheckFeedsController extends Controller
{
    protected function fetchRssFeed($url)
    {
        try {
            $cacheKey = 'rss_feed_' . md5($url);
            return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($url) {
                $response = Http::withOptions([
                    'timeout' => 10,
                    'verify' => false,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
                ])->get($url);
                if ($response->successful()) {
                    $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR);
                    if ($xml !== false) {
                        Log::info("Successfully fetched RSS feed for $url");
                        return $xml;
                    }
                    Log::error("Invalid RSS XML for $url");
                } else {
                    Log::error("Error fetching RSS feed $url: HTTP {$response->status()}");
                }
                return false;
            });
        } catch (\Exception $e) {
            Log::error("Error fetching RSS feed $url: {$e->getMessage()}");
            return false;
        }
    }

    protected function getFullUrl($url)
    {
        if (strpos($url, 'http') !== 0) {
            $url = 'https://' . ltrim($url, '/');
        }
        return $url;
    }

    public function check(Request $request)
    {
        try {
            $chatId = $request->query('chat_id');
            if (!$chatId) {
                Log::error('No chat_id provided in /check-feeds');
                return response()->json(['error' => 'چت آیدی مشخص نشده'], 400);
            }

            $envKey = 'FEEDS_CONFIG_' . $chatId;
            $configJson = env($envKey, json_encode(['feeds' => [], 'auto_send' => true]));
            $config = json_decode($configJson, true);
            if ($config === null) {
                Log::error("Invalid JSON in env FEEDS_CONFIG_$chatId");
                $config = ['feeds' => [], 'auto_send' => true];
            }

            if (!$config['auto_send']) {
                Log::info('Auto send disabled for chat_id: ' . $chatId);
                return response()->json(['message' => 'ارسال خودکار غیرفعال است']);
            }

            $feeds = $config['feeds'] ?? [];
            if (empty($feeds)) {
                Log::info('No feeds configured for chat_id: ' . $chatId);
                return response()->json(['message' => 'هیچ فیدی تنظیم نشده']);
            }

            $sentFile = storage_path('app/sent_links_' . $chatId . '.json');
            $sentLinks = file_exists($sentFile) ? json_decode(file_get_contents($sentFile), true) : [];
            if (!is_array($sentLinks)) {
                $sentLinks = [];
            }

            $timeThreshold = now()->subMinutes(60)->timestamp;
            $results = [];
            Log::info("Checking feeds for chat_id {$chatId}, feeds: " . json_encode($feeds));
            foreach ($feeds as $name => $url) {
                $feed = $this->fetchRssFeed($url);
                if ($feed && isset($feed->channel->item)) {
                    $items = array_slice((array) $feed->channel->item, 0, 5);
                    Log::info("Found {$name} feed items: " . count($items));
                    foreach ($items as $item) {
                        try {
                            $pubDateStr = (string) ($item->pubDate ?? '');
                            if (empty($pubDateStr)) {
                                Log::warning("No pubDate for item in feed $url, skipping");
                                continue;
                            }
                            $pubDateObj = new \DateTime($pubDateStr, new \DateTimeZone('UTC'));
                            $pubDateObj->setTimezone(new \DateTimeZone('Asia/Tehran'));
                            $pubDate = $pubDateObj->getTimestamp();
                            $jalaliDate = Jalalian::fromDateTime($pubDateObj)->format('d-m-Y H:i');
                            $link = (string) ($item->link ?? '');
                            if (empty($link)) {
                                Log::warning("No link for item in feed $url, skipping");
                                continue;
                            }
                            $fullLink = $this->getFullUrl($link);
                            $title = (string) ($item->title ?? 'بدون عنوان');

                            $normalizedLink = rtrim($fullLink, '/');
                            $normalizedSentLinks = array_map(function ($link) {
                                return rtrim($link, '/');
                            }, $sentLinks);
                            if ($pubDate >= $timeThreshold && !in_array($normalizedLink, $normalizedSentLinks)) {
                                $sentLinks[] = $fullLink;
                                $results[] = [
                                    'feed' => $name,
                                    'title' => $title,
                                    'link' => $fullLink,
                                    'pubDate' => $jalaliDate
                                ];
                                $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
                                $image = '';
                                if (isset($item->enclosure) && isset($item->enclosure['type']) && $item->enclosure['type'] == 'image/jpeg') {
                                    $image = (string) $item->enclosure['url'];
                                } elseif (isset($item->{'media:content'})) {
                                    $image = (string) $item->{'media:content'}['url'];
                                }
                                $text = "🦗 <b>سایت:</b> $name\n\n" .
                                        "📝 <b>عنوان:</b>\n$title\n\n" .
                                        "⏰ <b>زمان انتشار:</b> $jalaliDate\n\n" .
                                        "🔗 <a href='$fullLink'>مشاهده خبر</a>";
                                if ($image) {
                                    $telegram->sendPhoto([
                                        'chat_id' => $chatId,
                                        'photo' => $image,
                                        'caption' => $text,
                                        'parse_mode' => 'HTML'
                                    ]);
                                } else {
                                    $telegram->sendMessage([
                                        'chat_id' => $chatId,
                                        'text' => $text,
                                        'parse_mode' => 'HTML'
                                    ]);
                                }
                            }
                        } catch (\Exception $e) {
                            Log::error("Error processing item in feed $url: {$e->getMessage()}");
                        }
                    }
                }
            }

            Log::info("Saving sent_links for chat_id {$chatId}: " . json_encode($sentLinks));
            $sentLinks = array_slice($sentLinks, -50);
            try {
                $directory = storage_path('app');
                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                    chown($directory, 'www-data');
                    chgrp($directory, 'www-data');
                }
                if (!is_writable($directory)) {
                    chmod($directory, 0755);
                }
                file_put_contents($sentFile, json_encode($sentLinks, JSON_UNESCAPED_UNICODE));
                Log::info("Successfully saved sent_links_{$chatId}.json");
            } catch (\Exception $e) {
                Log::error("Error updating sent_links_{$chatId}.json: {$e->getMessage()}");
            }

            return response()->json([
                'items' => $results,
                'message' => empty($results) ? 'خبری یافت نشد...' : 'اخبار جدید فرستاده شد'
            ]);
        } catch (\Exception $e) {
            Log::error("Error in check feeds for chat_id {$chatId}: {$e->getMessage()}");
            return response()->json(['error' => 'خطا در بررسی فیدها'], 500);
        }
    }
}
?>