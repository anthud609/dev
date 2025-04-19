<?php
// public/index.php
// 1. show all errors (dev only)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 2. bootstrap
require __DIR__ . '/../vendor/autoload.php';
use App\Core\Sentinel;

// 3. initialize
$logger = new Sentinel();

// 4. send all PHP errors/exceptions through Sentinel
set_error_handler([$logger, 'handlePhpError']);
set_exception_handler([$logger, 'handleUncaughtException']);
register_shutdown_function([$logger, 'handleFatalError']);


// 4. Your application code / manual logs

// Log a message with the 'auth' module
$logger->log('auth', 'CRITICAL', 'Auth API Service Unavailable');

// Log a message with the 'app' module
$logger->log('app', 'INFO', 'Configurations for PRODUCTION environment loaded successfully.');

// Log a message with a custom module
$logger->log('custom_module', 'DEBUG', 'This is a debug log message');

// Log with blank module (falls back to default)
$logger->log('', 'INFO', 'This is an info log message');

// ...the rest of your app bootstrapping or routing here...
testfgd();