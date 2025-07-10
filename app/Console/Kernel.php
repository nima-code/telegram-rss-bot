<?php
namespace App\Console;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\CheckFeeds::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('rss:check')
            ->everyMinute()
            ->when(function () {
                $startTime = strtotime('2025-01-01 00:00:00');
                $currentTime = time();
                $interval = 15 * 60; // 15 دقیقه
                $elapsed = $currentTime - $startTime;
                $isDue = $elapsed % $interval < 60;
                return $isDue;
            });
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
?>