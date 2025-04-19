<?php
namespace App\Core;

use Throwable;

/**
 * Bridges PHP native errors and exceptions into the Sentinel logger.
 *
 * Call ErrorHandler::register($sentinel) once during bootstrap to
 * forward all PHP errors, uncaught exceptions, and fatal shutdowns
 * through your central Sentinel instance.
 */
class ErrorHandler
{
    /** @var Sentinel */
    private static Sentinel $sentinel;

    /**
     * Mapping of PHP error constants to Sentinel log levels.
     * These correspond to the string levels Sentinel::log() expects.
     * @var array<int,string>
     */
    private static array $levelMap = [
        E_ERROR             => 'ERROR',       // unrecoverable run-time fatal errors
        E_CORE_ERROR        => 'ERROR',       // PHP initial startup fatal errors
        E_COMPILE_ERROR     => 'ERROR',       // compile-time fatal errors
        E_USER_ERROR        => 'ERROR',       // user-generated fatal errors
        E_RECOVERABLE_ERROR => 'ERROR',       // catchable fatal errors

        E_WARNING           => 'WARNING',     // run-time warnings
        E_CORE_WARNING      => 'WARNING',     // startup warnings
        E_COMPILE_WARNING   => 'WARNING',     // compile-time warnings
        E_USER_WARNING      => 'WARNING',     // user-generated warnings

        E_NOTICE            => 'NOTICE',      // run-time notices
        E_USER_NOTICE       => 'NOTICE',      // user-generated notices
        E_STRICT            => 'NOTICE',      // suggestions for interoperability
        E_DEPRECATED        => 'NOTICE',      // deprecation notices
        E_USER_DEPRECATED   => 'NOTICE',      // user-generated deprecation warnings

        E_PARSE             => 'ALERT',       // parse (syntax) errors
    ];

    /**
     * Register PHP error, exception, and shutdown handlers.
     *
     * @param Sentinel $sentinel  Your initialized Sentinel instance
     */
    public static function register(Sentinel $sentinel): void
    {
        self::$sentinel = $sentinel;

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Error handler: forwards PHP errors to Sentinel.
     *
     * @return bool  Return false to allow PHP internal handler as well.
     */
    public static function handleError(
        int    $errno,
        string $errstr,
        string $errfile,
        int    $errline
    ): bool {
        $level = self::$levelMap[$errno] ?? 'ERROR';
        self::$sentinel->log(
            'php_error',
            $level,
            $errstr,
            ['file' => $errfile, 'line' => $errline, 'type' => $errno]
        );

        // false = allow PHP's internal error handler to run as well
        return false;
    }

    /**
     * Exception handler: forwards uncaught exceptions to Sentinel.
     */
    public static function handleException(Throwable $ex): void
    {
        self::$sentinel->log(
            'exception',
            'ERROR',
            $ex->getMessage(),
            [
                'file'  => $ex->getFile(),
                'line'  => $ex->getLine(),
                'trace' => $ex->getTraceAsString(),
            ]
        );

        if (!headers_sent()) {
            http_response_code(500);
        }
        // Optionally render a friendly page/JSON and exit;
    }

    /**
     * Shutdown handler: catches fatal errors on shutdown and logs them.
     */
    public static function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err && in_array($err['type'], [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR
        ], true)) {
            $level = self::$levelMap[$err['type']] ?? 'EMERGENCY';
            self::$sentinel->log(
                'fatal_error',
                $level,
                $err['message'],
                ['file' => $err['file'], 'line' => $err['line']]
            );
        }
    }
}
