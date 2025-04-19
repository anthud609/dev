<?php
// 

declare(strict_types=1);

/**
 * Public Front Controller
 * public/index.php
 * 
 * This file bootstraps the entire application:
 *   1. Loads environment variables (.env)
 *   2. Builds and compiles the DI container
 *   3. Instantiates and configures the Sentinel logger
 *   4. Registers PHP error / exception / shutdown handlers
 *   5. Creates PSR‑7 Request & Response objects
 *   6. Defines FastRoute routes
 *   7. Builds a PSR‑15 middleware pipeline (Relay)
 *   8. Dispatches the pipeline and emits the HTTP response
 *
 * PHP version 8.1+
 *
 * @package App
 */
use App\Core\Sentinel;

require __DIR__ . '/../vendor/autoload.php';

//
// 1) Load environment variables
//
(new Dotenv())->bootEnv(__DIR__ . '/../.env');  // populates $_ENV and getenv()




// 1. show all errors (dev only)
error_reporting(E_ALL);
ini_set('display_errors', '0');


//
// 3) Instantiate and configure Sentinel logger
//
/** @var Sentinel $logger */
$logger = $container->get(Sentinel::class);

// 4. Hook PHP errors & exceptions into Sentinel
/**
 * set_error_handler: Redirects all PHP warnings, notices, and runtime errors into your Sentinel::handlePhpError() method. You still see them on‑screen (because display_errors=1), but they also end up in both app.log and app.json.
 * 
 * set_exception_handler: Catches any uncaught Throwable (exceptions or errors) and passes them to Sentinel::handleUncaughtException(), which logs the message, file, line, and full stack trace.
 * 
 * register_shutdown_function: Runs when PHP finishes execution (or a fatal error occurs). You call handleFatalError(), which checks error_get_last() to see if there was a fatal (e.g. parse error, out‑of‑memory) and logs it too.
 */
set_error_handler([$logger, 'handlePhpError']);
set_exception_handler([$logger, 'handleUncaughtException']);
register_shutdown_function([$logger, 'handleFatalError']);

// Add a processor to inject a correlation/request ID into every log record
$logger->getLogger()->pushProcessor(static function (array $record): array {
    $record['extra']['request_id'] = bin2hex(random_bytes(8));
    return $record;
});

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