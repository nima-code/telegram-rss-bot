<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DebugController extends Controller
{
    public function debugFeed(Request $request)
    {
        try {
            $url = $request->query('url', 'https://www.isna.ir/rss');
            Log::info("Starting debug for RSS feed: $url");

            // چک کردن پیش‌نیازها
            if (!extension_loaded('simplexml')) {
                Log::error("PHP simplexml extension is not loaded");
                return response()->json([
                    'status' => 'error',
                    'message' => 'PHP simplexml extension is not loaded',
                    'url' => $url
                ], 500);
            }

            // چک کردن دایرکتوری storage
            $directory = storage_path('app');
            if (!is_dir($directory)) {
                Log::info("Creating directory: $directory");
                mkdir($directory, 0755, true);
                chown($directory, 'www-data');
                chgrp($directory, 'www-data');
            }
            if (!is_writable($directory)) {
                Log::warning("Directory not writable: $directory");
                chmod($directory, 0755);
                if (!is_writable($directory)) {
                    Log::error("Failed to make directory writable: $directory");
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Storage directory is not writable',
                        'url' => $url
                    ], 500);
                }
            }

            // ارسال درخواست HTTP
            Log::info("Sending HTTP request to: $url");
            $response = Http::withOptions([
                'timeout' => 20,
                'verify' => false,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            ])->get($url);

            // مدیریت ریدایرکت
            if ($response->status() === 301 || $response->status() === 302) {
                $newUrl = $response->header('Location');
                Log::info("Redirect detected for $url, new URL: $newUrl");
                $response = Http::withOptions([
                    'timeout' => 20,
                    'verify' => false,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                ])->get($newUrl);
                $url = $newUrl;
            }

            // چک کردن پاسخ HTTP
            if (!$response->successful()) {
                Log::error("HTTP error fetching RSS feed $url: HTTP {$response->status()}, response: " . substr($response->body(), 0, 500));
                return response()->json([
                    'status' => 'error',
                    'message' => "HTTP error: {$response->status()}",
                    'url' => $url,
                    'response_body' => substr($response->body(), 0, 500)
                ], 500);
            }

            // پارس XML
            Log::info("Parsing XML for feed: $url");
            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR);
            if ($xml === false || !isset($xml->channel->item)) {
                Log::error("Invalid RSS XML or no items for $url, raw response: " . substr($response->body(), 0, 500));
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid RSS XML or no items',
                    'url' => $url,
                    'raw_response' => substr($response->body(), 0, 500)
                ], 500);
            }

            // جمع‌آوری آیتم‌ها
            $items = [];
            foreach (array_slice((array) $xml->channel->item, 0, 3) as $item) {
                $items[] = [
                    'title' => (string) ($item->title ?? 'بدون عنوان'),
                    'link' => (string) ($item->link ?? ''),
                    'pubDate' => (string) ($item->pubDate ?? ''),
                    'description' => substr((string) ($item->description ?? ''), 0, 200)
                ];
            }

            Log::info("Successfully debugged feed $url, found " . count($items) . " items");
            return response()->json([
                'status' => 'ok',
                'url' => $url,
                'item_count' => count($items),
                'items' => $items,
                'raw_xml' => substr($response->body(), 0, 500)
            ]);
        } catch (\Exception $e) {
            Log::error("Error debugging feed $url: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'url' => $url,
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}