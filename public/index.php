<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use App\Core\Sentinel;
use Whoops\Run as WhoopsRun;
use Whoops\Handler\PrettyPageHandler;

// 1) Autoload and load environment
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$appEnv = $_ENV['APP_ENV'] ?? 'production';

// 2) Instantiate Sentinel logger
$logger = new Sentinel();

// 3) Prepare Whoops (but don’t register globally)
$whoops = null;
if ($appEnv === 'debug') {
    $whoops = new WhoopsRun();
    $whoops->pushHandler(new PrettyPageHandler());
}

// 4) Convert PHP errors to exceptions
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // respect @ operator
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// 5) Central exception handler
set_exception_handler(static function (\Throwable $e) use ($logger, $appEnv, $whoops): void {
    // 5a) Always log full details with an Error ID
    $errorId = bin2hex(random_bytes(8));
    $logger->log(
        'exception',
        'ERROR',
        $e->getMessage(),
        [
            'id'    => $errorId,
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]
    );

    // 5b) In debug mode, let Whoops render the page
    if ($appEnv === 'debug' && $whoops instanceof WhoopsRun) {
        $whoops->handleException($e);
        return;
    }

    // 5c) In production (or non-debug), show user-friendly error page
    http_response_code(500);
    $userMessage = 'An unexpected error occurred. Our engineers have been notified.';
    include __DIR__ . '/../app/views/error.php';
    exit(1);
});

// 6) Shutdown handler to catch fatals
register_shutdown_function(static function () use ($logger, $appEnv, $whoops): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $ex = new \ErrorException(
            $err['message'],
            0,
            $err['type'],
            $err['file'],
            $err['line']
        );

        // Log fatal error
        $errorId = bin2hex(random_bytes(8));
        $logger->log(
            'fatal_error',
            'ERROR',
            $ex->getMessage(),
            [
                'id'    => $errorId,
                'file'  => $ex->getFile(),
                'line'  => $ex->getLine(),
                'trace' => $ex->getTraceAsString(),
            ]
        );

        if ($appEnv === 'debug' && $whoops instanceof WhoopsRun) {
            $whoops->handleException($ex);
        } else {
            http_response_code(500);
            $userMessage = 'A fatal error occurred. Our team has been alerted.';
            include __DIR__ . '/../app/views/error.php';
        }
    }
});

// 7) Your application code below… for testing, you can throw:
// throw new \RuntimeException('Test error for both logging & Whoops');

// 8) Example manual logs
$logger->log('auth', 'CRITICAL', 'Auth API Service Unavailable');
$logger->log('app',  'INFO',     'Configurations for PRODUCTION environment loaded successfully.');
$logger->log('custom_module', 'DEBUG', 'This is a debug log message');
$logger->log('', 'INFO', 'This is an info log message');

tesft();