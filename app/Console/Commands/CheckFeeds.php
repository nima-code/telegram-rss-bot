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

           // بارگذاری فیدها از متغیر محیطی
           $feedsEnv = env('FEEDS');
           $config = ['feeds' => [], 'auto_send' => true];
           if ($feedsEnv) {
               $feeds = explode(',', $feedsEnv);
               foreach ($feeds as $feed) {
                   [$name, $url] = array_map('trim', explode(':', $feed, 2));
                   if (filter_var($url, FILTER_VALIDATE_URL)) {
                       $config['feeds'][$name] = $url;
                   }
               }
           } else {
               $config['feeds'] = [
                   'زومیت' => 'https://www.zoomit.ir/rss',
                   'بی‌بی‌سی' => 'https://www.bbc.com/persian/index.xml',
                   'خبرآنلاین' => 'https://www.khabaronline.ir/rss',
                   'تابناک' => 'https://www.tabnak.ir/fa/rss/allnews',
                   'ایسنا' => 'https://www.isna.ir/rss'
               ];
           }

           // بارگذاری chat_idها از متغیر محیطی
           $chatIds = env('CHAT_IDS') ? explode(',', env('CHAT_IDS')) : [];

           if (empty($chatIds)) {
               Log::warning('No chat_ids found in CHAT_IDS env variable');
               $chatIds = ['default']; // برای تست
           }

           foreach ($chatIds as $chatId) {
               foreach ($config['feeds'] as $name => $url) {
                   try {
                       Log::info("Processing feed $name ($url) for chat_id $chatId");
                       $controller = new CheckFeedsController();
                       $request = Request::create('/check-feeds?chat_id=' . $chatId, 'GET');
                       $response = $controller->check($request);
                       $content = $response->getContent();
                       if (empty($content)) {
                           Log::error("Empty response for feed: $name ($url) for chat_id $chatId");
                           continue;
                       }

                       $results = json_decode($content, true);
                       if ($results === null) {
                           Log::error("Invalid JSON response for feed: $name ($url) for chat_id $chatId");
                           continue;
                       }

                       if (isset($results['error'])) {
                           Log::error("Error for feed $name ($url) for chat_id $chatId: {$results['error']}");
                       } elseif (isset($results['message'])) {
                           Log::info("Result for feed $name ($url) for chat_id $chatId: {$results['message']}");
                       }
                   } catch (\Exception $e) {
                       Log::error("Error processing feed $name ($url) for chat_id $chatId: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
                   }
               }
           }

           $this->info('RSS feed check completed.');
           Log::info('RSS feed check completed.');
       }
   }