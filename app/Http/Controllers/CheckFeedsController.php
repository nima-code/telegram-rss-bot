<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Morilog\Jalali\Jalalian;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CheckFeedsController extends Controller
{
    protected function fetchRssFeed($url)
    {
        try {
            Log::info("Fetching RSS feed: $url");
            $response = Http::withOptions([
                'timeout' => 10,
                'verify' => false,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'
            ])->get($url);

            Log::info("Raw RSS response for $url: " . substr($response->body(), 0, 1000));

            if ($response->successful()) {
                Log::info("Successfully fetched feed $url, parsing XML");
                $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml !== false && isset($xml->channel->item)) {
                    Log::info("Successfully parsed RSS feed for $url, found " . count($xml->channel->item) . " items");
                    return $xml;
                }
                Log::error("Invalid RSS XML or no items for $url, raw response: " . substr($response->body(), 0, 500));
                return false;
            } else {
                Log::error("HTTP error fetching RSS feed $url: HTTP {$response->status()}, response: " . substr($response->body(), 0, 500));
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Error fetching RSS feed $url: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
            return false;
        }
    }

    public function check(Request $request)
    {
        try {
            $chatId = $request->query('chat_id');
            if (!$chatId) {
                Log::error('No chat_id provided in /check-feeds');
                return response()->json(['error' => 'چت آیدی مشخص نشده'], 400);
            }
            Log::info("Starting check-feeds for chat_id: $chatId");

            $feeds = [
                'خبرآنلاین' => 'https://www.khabaronline.ir/rss',
                'ایسنا' => 'https://www.isna.ir/rss'
            ];

            $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
            $results = [];

            date_default_timezone_set('Asia/Tehran');
            foreach ($feeds as $name => $url) {
                Log::info("Processing feed: $name ($url)");
                $feed = $this->fetchRssFeed($url);
                if ($feed && isset($feed->channel->item)) {
                    $items = array_slice((array) $feed->channel->item, 0, 1);
                    foreach ($items as $item) {
                        try {
                            $title = (string) ($item->title ?? 'بدون عنوان');
                            $link = (string) ($item->link ?? '');
                            if (empty($link)) {
                                Log::warning("No link for item in feed $url, skipping");
                                continue;
                            }
                            $pubDateStr = (string) ($item->pubDate ?? '');
                            $pubDateObj = $pubDateStr ? new \DateTime($pubDateStr, new \DateTimeZone('UTC')) : Carbon::now('Asia/Tehran');
                            $pubDateObj->setTimezone(new \DateTimeZone('Asia/Tehran'));
                            $jalaliDate = Jalalian::fromDateTime($pubDateObj)->format('d-m-Y H:i');

                            $text = "🦗 <b>سایت:</b> $name\n\n" .
                                    "📝 <b>عنوان:</b>\n$title\n\n" .
                                    "⏰ <b>زمان انتشار:</b> $jalaliDate\n\n" .
                                    "🔗 <a href='$link'>مشاهده خبر</a>";

                            $telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => $text,
                                'parse_mode' => 'HTML'
                            ]);

                            $results[] = [
                                'feed' => $name,
                                'title' => $title,
                                'link' => $link,
                                'pubDate' => $jalaliDate
                            ];
                        } catch (\Exception $e) {
                            Log::error("Error processing feed item from $url: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
                        }
                    }
                } else {
                    Log::warning("No items or invalid feed: $url");
                }
            }

            Log::info("Completed check-feeds for chat_id: $chatId, results: " . json_encode($results));
            return response()->json([
                'items' => $results,
                'message' => empty($results) ? 'خبری یافت نشد...' : 'اخبار جدید فرستاده شد'
            ]);
        } catch (\Exception $e) {
            Log::error("Error in check-feeds for chat_id: $chatId: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
            return response()->json(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }
}