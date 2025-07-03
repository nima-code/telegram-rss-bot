<?php

require_once __DIR__.'/../vendor/autoload.php';

(new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(
    dirname(__DIR__)
))->bootstrap();

date_default_timezone_set('Asia/Tehran');

$app = new Laravel\Lumen\Application(
    dirname(__DIR__)
);

// فعال‌سازی Facades
$app->withFacades();

// فعال‌سازی Eloquent (در صورت نیاز)
$app->withEloquent();

// تنظیم پیکربندی‌ها
$app->configure('app');
$app->configure('filesystems');

// ثبت middleware
$app->middleware([
    App\Http\Middleware\ExampleMiddleware::class,
    App\Http\Middleware\EnsureJsonResponse::class,
]);

// ثبت route middleware
$app->routeMiddleware([
    'auth' => App\Http\Middleware\Authenticate::class,
]);

// ثبت سرویس‌های لازم
$app->register(App\Providers\AppServiceProvider::class);
$app->register(App\Providers\AuthServiceProvider::class);
$app->register(App\Providers\EventServiceProvider::class);
$app->register(Illuminate\Routing\RoutingServiceProvider::class);
$app->register(Telegram\Bot\Laravel\TelegramServiceProvider::class); // اضافه کردن برای تلگرام

// ثبت مسیرهای روت
$app->router->group([
    'namespace' => 'App\Http\Controllers',
], function ($router) {
    // لود روت‌های موجود از web.php
    require __DIR__.'/../routes/web.php';
    
    // روت وب‌هوک برای تلگرام
    $router->post('/webhook/telegram/{token}', 'TelegramHandlerController@handleWebhook');
});

// فعال کردن لاگ
\Illuminate\Support\Facades\Log::channel('single')->info('Application bootstrapped');

// غیرفعال کردن نمایش خطاها
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

return $app;