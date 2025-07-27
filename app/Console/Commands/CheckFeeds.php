<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Api;
use App\TelegramHandler;

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
            $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
            $directory = storage_path('app/feeds');
            $files = glob($directory . '/*.json');
            
            foreach ($files as $file) {
                if (strpos($file, 'sent_') === false) {
                    $chatId = str_replace('.json', '', basename($file));
                    $handler = new TelegramHandler($telegram, $chatId);
                    $handler->checkAndSendFeeds();
                }
            }
            Log::info("Feed check completed for all chats");
        } catch (\Exception $e) {
            Log::error("Error in rss:check: {$e->getMessage()}");
        }
    }
}
?>