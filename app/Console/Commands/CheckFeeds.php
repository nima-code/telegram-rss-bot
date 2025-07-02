<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\CheckFeedsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckFeeds extends Command
{
    protected $signature = 'rss:check';
    protected $description = 'Check RSS feeds for all users';

    public function handle()
    {
        $this->info('Checking RSS feeds...');
        Log::info('Checking RSS feeds for all users');

        $configFiles = glob(storage_path('app/feeds_config_*.json'));

        foreach ($configFiles as $configFile) {
            try {
                preg_match('/feeds_config_(.+)\.json/', $configFile, $matches);
                $chatId = $matches[1] ?? null;
                if (!$chatId) {
                    Log::error("Invalid chat_id in config file: $configFile");
                    continue;
                }

                $config = json_decode(file_get_contents($configFile), true);
                if ($config === null) {
                    Log::error("Invalid JSON in $configFile");
                    continue;
                }

                if (!isset($config['auto_send']) || !$config['auto_send']) {
                    Log::info("Auto send disabled for chat_id: $chatId, skipping");
                    continue;
                }

                $controller = new CheckFeedsController();
                $request = Request::create('/check-feeds?chat_id=' . $chatId, 'GET');
                $response = $controller->check($request);
                $content = $response->getContent();
                if (empty($content)) {
                    Log::error("Empty response for chat_id: $chatId");
                    continue;
                }

                $results = json_decode($content, true);
                if ($results === null) {
                    Log::error("Invalid JSON response for chat_id: $chatId");
                    continue;
                }

                if (isset($results['error'])) {
                    Log::error("Error for chat_id $chatId: {$results['error']}");
                } elseif (isset($results['message'])) {
                    Log::info("Result for chat_id $chatId: {$results['message']}");
                }
            } catch (\Exception $e) {
                Log::error("Error processing $configFile: {$e->getMessage()}");
            }
        }

        $this->info('RSS feed check completed.');
        Log::info('RSS feed check completed.');
    }
}