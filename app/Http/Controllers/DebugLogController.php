<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class DebugLogController extends Controller
{
    public function debugSystem(Request $request)
    {
        try {
            $debugInfo = [
                'timestamp' => now()->toDateTimeString(),
                'php_version' => phpversion(),
                'memory_limit' => ini_get('memory_limit'),
                'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
                'storage_writable' => is_writable(storage_path('app')) ? 'Yes' : 'No',
                'logs_writable' => is_writable(storage_path('logs')) ? 'Yes' : 'No',
                'tmp_writable' => is_writable('/tmp') ? 'Yes' : 'No',
                'simplexml_loaded' => extension_loaded('simplexml') ? 'Yes' : 'No',
                'last_logs' => []
            ];

            // خواندن 10 خط آخر لاگ‌ها
            $logFile = storage_path('logs/laravel.log');
            if (file_exists($logFile)) {
                $debugInfo['last_logs'] = array_slice(array_filter(explode("\n", file_get_contents($logFile))), -10);
            }

            // تست درخواست به فید خبرآنلاین
            $url = 'https://www.khabaronline.ir/rss';
            Log::info("Debug: Testing RSS feed: $url");
            $response = Http::withOptions([
                'timeout' => 8,
                'verify' => false,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
            ])->get($url);

            $debugInfo['rss_test'] = [
                'url' => $url,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'response_snippet' => substr($response->body(), 0, 500)
            ];

            if ($response->successful()) {
                $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
                $debugInfo['rss_test']['xml_valid'] = ($xml !== false && isset($xml->channel->item)) ? 'Yes' : 'No';
                $debugInfo['rss_test']['item_count'] = $xml && isset($xml->channel->item) ? count($xml->channel->item) : 0;
            } else {
                $debugInfo['rss_test']['xml_valid'] = 'N/A';
                $debugInfo['rss_test']['item_count'] = 0;
            }

            Log::info("Debug system info: " . json_encode($debugInfo));
            return response()->json($debugInfo);
        } catch (\Exception $e) {
            Log::error("Debug system error: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}