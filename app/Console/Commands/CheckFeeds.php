<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\CheckFeedsController;

class CheckFeeds extends Command
{
    protected $signature = 'rss:check';
    protected $description = 'Check RSS feeds for new items';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        try {
            $directory = storage_path('app');
            $files = glob($directory . '/feeds_config_*.json');
            Log::info("Found " . count($files) . " feed config files");
            foreach ($files as $file) {
                $chatId = str_replace(['feeds_config_', '.json'], '', basename($file));
                Log::info("Processing feeds for chat_id: $chatId");
                try {
                    $controller = new CheckFeedsController();
                    $request = \Illuminate\Http\Request::create('/check-feeds?chat_id=' . $chatId, 'GET');
                    $response = $controller->check($request);
                    Log::info("Result for chat_id $chatId: " . json_encode($response->getData()));
                } catch (\Exception $e) {
                    Log::error("Error processing feeds for chat_id $chatId: {$e->getMessage()}");
                }
            }
        } catch (\Exception $e) {
            Log::error("Error in rss:check: {$e->getMessage()}");
        }
    }
}
?>