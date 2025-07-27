<?php
namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [];

    protected function schedule(Schedule $schedule)
    {
        // Schedule غیرفعال شده چون تو پلن رایگان Render از Cron Job خارجی استفاده می‌کنیم
        // $schedule->call(function () {
        //     \Illuminate\Support\Facades\Log::info('Cron job running');
        // })->everyFifteenMinutes();
    }
}
?>