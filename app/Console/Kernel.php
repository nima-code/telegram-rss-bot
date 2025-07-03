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
        Log::info('Scheduling is disabled on Render free plan');
        return;
    }
}