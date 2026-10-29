<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    (new Dotenv())->bootEnv($envFile);
}

$appEnv = (string) ($_SERVER['APP_ENV'] ?? 'prod');
$appDebug = (bool) ($_SERVER['APP_DEBUG'] ?? ('prod' !== $appEnv));

$kernel = new Kernel($appEnv, $appDebug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
