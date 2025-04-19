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
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

//
// 1) Load environment variables
//
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();




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
// 3) Turn ALL PHP errors into ErrorExceptions
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    // respect @ operator
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// 4) Central exception handler
set_exception_handler(static function(\Throwable $e) use ($logger): void {
    // 4a) Generate a user‑facing ID
    $errorId = bin2hex(random_bytes(8));

    // 4b) Log full details to Sentinel
    $logger->log(
        'exception',              // module
        'ERROR',                  // level
        $e->getMessage(),         // message
        [
            'id'    => $errorId,
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]
    );

    // 4c) Render the friendly error page
    http_response_code(500);
    $userMessage = 'An unexpected error occurred. Our engineers have been notified.'; 
    // variables for the view:
    include __DIR__ . '/../app/views/error.php';

    exit(1);
});

// 5) Shutdown handler to catch fatals
register_shutdown_function(static function() use ($logger): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        // wrap it in an ErrorException so it reuses our exception handler
        $ex = new \ErrorException(
            $err['message'],
            0,
            $err['type'],
            $err['file'],
            $err['line']
        );
        // forward to exception handler
        (ini_get('display_errors') === '1')
            ? throw $ex
            : call_user_func('App\Core\Sentinel::handleUncaughtException', $ex);
    }
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