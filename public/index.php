<?php
// public/index.php

declare(strict_types=1);

use Dotenv\Dotenv;
use App\Core\Sentinel;
use Whoops\Run as WhoopsRun;
use Whoops\Handler\PrettyPageHandler;

// 1) Autoload + env
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$appEnv = $_ENV['APP_ENV'] ?? 'production';

// 2) Instantiate Sentinel — this now registers all PHP handlers for you
$logger = new Sentinel(
    __DIR__ . '/../storage/logs/app.log',
    $appEnv === 'debug',
    'app'
);

// 3) Whoops for debug‑mode pretty pages (optional)
if ($appEnv === 'debug') {
    $whoops = new WhoopsRun();
    $whoops->pushHandler(new PrettyPageHandler());
    $whoops->register();
}

// 4) Your application bootstrap and routing here…
//    Any PHP error, uncaught exception or fatal shutdown
//    will now be logged via Sentinel automatically.

// Example manual logs:
$logger->log('auth', 'CRITICAL', 'Auth API Service Unavailable');
$logger->log('app',  'INFO',     'Configurations loaded.');

// …rest of your framework boot or route dispatch…
fdsf();