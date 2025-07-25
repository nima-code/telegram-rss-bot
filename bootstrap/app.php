<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

try {
    // بارگذاری فایل .env با متد جدید
    Dotenv::createImmutable(__DIR__ . '/..')->load();
} catch (\Exception $e) {
    die('Failed to load .env file: ' . $e->getMessage());
}

$app = new Laravel\Lumen\Application(
    dirname(__DIR__)
);

// تنظیمات Lumen
$app->withFacades();
$app->withEloquent();

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->configure('app');

$app->router->group(['namespace' => 'App\Http\Controllers'], function ($router) {
    require __DIR__ . '/../routes/web.php';
});

return $app;
?>