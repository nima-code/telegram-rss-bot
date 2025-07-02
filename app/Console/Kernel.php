<?php

namespace App\Console;

use Illuminate\Support\Facades\Log;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;
use App\Console\Commands\CheckFeeds;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        CheckFeeds::class,
    ];

    protected function schedule($schedule)
    {
        $tmpDir = '/tmp';
        if (!is_dir($tmpDir)) {
            Log::info("Creating directory: $tmpDir");
            mkdir($tmpDir, 0755, true);
            chown($tmpDir, 'www-data');
            chgrp($tmpDir, 'www-data');
        }
        if (!is_writable($tmpDir)) {
            Log::warning("Directory not writable: $tmpDir");
            chmod($tmpDir, 0755);
        }

        $startTimeFile = $tmpDir . '/schedule_start_time.txt';
        if (!file_exists($startTimeFile)) {
            file_put_contents($startTimeFile, time());
            chmod($startTimeFile, 0755);
            chown($startTimeFile, 'www-data');
            chgrp($startTimeFile, 'www-data');
            Log::info('Schedule start time set: ' . date('Y-m-d H:i:s'));
        }

        $startTime = (int) file_get_contents($startTimeFile);

        $schedule->command('rss:check')->everyFiveMinutes()->when(function () use ($startTime) {
            $currentTime = time();
            $interval = 15 * 60; // 15 دقیقه
            $elapsed = $currentTime - $startTime;
            $isDue = $elapsed % $interval < 60;
            if ($isDue) {
                Log::info('Running rss:check at ' . date('Y-m-d H:i:s', $currentTime));
            }
            return $isDue;
        });
    }
}