<?php
namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\FeedWorker::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        // خالی برای استفاده از Command جدید
    }
}
?>