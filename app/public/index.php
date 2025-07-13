<?php
use Laravel\Lumen\Application;
use Laravel\Lumen\Routing\Router;

require __DIR__ . '/../vendor/autoload.php';

try {
    (new Dotenv\Dotenv(__DIR__ . '/../'))->load();
} catch (Dotenv\Exception\InvalidPathException $e) {
    //
}

$app = new Application(__DIR__ . '/../');

$app->withFacades();
$app->withEloquent();

$app->router->group(['namespace' => 'App\Http\Controllers'], function (Router $router) {
    require __DIR__ . '/../routes/web.php';
});

$app->run();
?>