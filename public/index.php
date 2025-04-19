<?php
// public/index.php

declare(strict_types=1);

use Dotenv\Dotenv;
use App\Core\Sentinel;
use Whoops\Run as WhoopsRun;
use Whoops\Handler\PrettyPageHandler;

require __DIR__ . '/../vendor/autoload.php';

// 1) Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$appEnv = $_ENV['APP_ENV'] ?? 'production';

// 2) Instantiate Sentinel — registers PHP error/exception/shutdown handlers
//    Now any error or uncaught exception will be sent to Monolog
$logger = new Sentinel(
    __DIR__ . '/../storage/logs/app.log',
    $appEnv === 'debug',
    'app'
);

// 3) If you’re in debug, wire up Whoops _after_ Sentinel
//    Whoops will save Sentinel’s handlers as “previous,” so on exception:
//      • Whoops shows its pretty page
//      • THEN it calls Sentinel to log the error
if ($appEnv === 'debug') {
    $whoops = new WhoopsRun();
    $whoops->pushHandler(new PrettyPageHandler());
    $whoops->register();  // ← no parameters!
}
// 4) Your application bootstrap and routing here…
//    Any PHP error, uncaught exception or fatal shutdown
//    will now be logged via Sentinel automatically.

// Example manual logs:
$logger->log('auth', 'CRITICAL', 'Auth API Service Unavailable');
$logger->log('app',  'INFO',     'Configurations loaded.');

// …rest of your framework boot or route dispatch…
fdsf();