<?php
namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;
use App\Console\Commands\CheckFeeds;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        CheckFeeds::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('rss:check')->everyFifteenMinutes();
    }
}
?>