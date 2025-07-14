<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckFeedsController extends Controller
{
    public function check(Request $request)
    {
        $chatId = $request->query('chat_id');
        if (!$chatId) {
            Log::error('No chat_id provided in check-feeds');
            return response()->json(['error' => 'No chat_id provided'], 400);
        }

        $envKey = "FEEDS_CONFIG_{$chatId}";
        $config = json_decode(env($envKey, '{"feeds":{},"auto_send":false}'), true);
        if (empty($config['feeds'])) {
            Log::info("No feeds configured for chat_id: {$chatId}");
            return response()->json(['message' => 'No feeds configured']);
        }

        $results = [];
        foreach ($config['feeds'] as $name => $url) {
            try {
                $xml = @simplexml_load_file($url);
                if ($xml === false) {
                    Log::error("Failed to load feed: $name ($url) for chat_id: {$chatId}");
                    $results[] = "Failed to load feed: $name";
                    continue;
                }

                $items = $xml->channel->item;
                $latestItems = array_slice((array)$items, 0, 3); // فقط 3 خبر آخر
                foreach ($latestItems as $item) {
                    $title = (string)($item->title ?? 'بدون عنوان');
                    $link = (string)($item->link ?? '#');
                    $pubDate = (string)($item->pubDate ?? 'نامشخص');
                    $results[] = "📰 $name\n$title\n$link\n$pubDate";
                }
                Log::info("Processed feed: $name for chat_id: {$chatId}");
            } catch (\Exception $e) {
                Log::error("Error processing feed $name for chat_id: {$chatId}: {$e->getMessage()}");
                $results[] = "Error processing feed $name: {$e->getMessage()}";
            }
        }

        return response()->json(['message' => implode("\n", $results)]);
    }
}
?>