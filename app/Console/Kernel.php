<?php
namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [];

    protected function schedule(Schedule $schedule)
    {
        // Cron Job داخلی حذف شد چون پلن رایگان10 دقیقه‌ای پشتیبانی نمی‌شود
    }
}
?>