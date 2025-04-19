<?php
namespace App\Core;

use Monolog\Logger;
use App\Core\PrettyJsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\JsonFormatter;
use Throwable;

class Sentinel
{
    protected Logger $logger;
    private bool $debugMode;
    private string $defaultModule;

    public function __construct(
        ?string $logFile = null,
        bool $debugMode = true,
        string $defaultModule = 'app'
    ) {
        $this->debugMode     = $debugMode;
        $this->defaultModule = $defaultModule;
        $logFile = $logFile ?? __DIR__ . '/../../storage/logs/app.log';

        // Ensure directory & file exist
        $this->initializeLogFile($logFile);

        // Wire up Monolog
        $this->initializeMonolog($logFile);

        if ($this->debugMode) {
            $this->log(
                'sentinel',
                'DEBUG',
                'Logger initialized',
                [
                    'logFile'  => $logFile,
                    'realpath' => realpath($logFile) ?: 'NOT FOUND',
                    'writable' => is_writable($logFile) ? 'YES' : 'NO',
                ]
            );
        }
    }

    private function initializeLogFile(string $logFile): void
    {
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0775, true) && !is_dir($logDir)) {
                throw new \RuntimeException("Cannot create log directory: {$logDir}");
            }
        }
        if (!file_exists($logFile)) {
            if (!touch($logFile)) {
                throw new \RuntimeException("Cannot create log file: {$logFile}");
            }
            chmod($logFile, 0664);
        }
        if (!is_writable($logFile)) {
            throw new \RuntimeException("Log file is not writable: {$logFile}");
        }
    }

    private function initializeMonolog(string $logFile): void
    {
        // 1) Create the Monolog logger with your default module name
        $this->logger = new Logger($this->defaultModule);

        // ───────────────────────────────────────────────────────────────
        // 2) First handler: regular line‑formatted log (unchanged)
        // ───────────────────────────────────────────────────────────────
        $stream = new StreamHandler($logFile, Logger::DEBUG);

        $lineFmt = new LineFormatter(
            "%datetime% %message% %context%" . PHP_EOL,
            "Y-m-d\\TH:i:s.uP",  // ISO‑8601 w/ microseconds + offset
            true,                // allowInlineLineBreaks
            true                 // ignoreEmptyContextAndExtra
        );
        $stream->setFormatter($lineFmt);
        $this->logger->pushHandler($stream);

       // JSON file path
$jsonLogFile = dirname($logFile) . '/app.json';
$jsonStream  = new StreamHandler($jsonLogFile, Logger::DEBUG);

// Use your pretty‑printer
$jsonStream->setFormatter(new PrettyJsonFormatter());
$this->logger->pushHandler($jsonStream);
    }
    /**
     * General-purpose logger
     *
     * @param  string|null  $module   name of your module (e.g. 'auth', 'db', or null/empty to omit)
     * @param  string       $level    one of: DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL…
     * @param  string       $message  your log message
     * @param  array        $context  any extra data (will be JSON‑encoded)
     */
    public function log(
        ?string $module,
        string  $level,
        string  $message,
        array   $context = []
    ): bool {
        // 1) Uppercase the level
        $level = strtoupper($level);

        // 2) Prepare module (trim to guard against whitespace/null)
        $mod = trim((string)$module);

        // 3) Build prefix:
        //    - with module:   "module.LEVEL."
        //    - without module: "LEVEL"
        if ($mod !== '') {
            $prefix = $mod . '.' . $level;
        } else {
            $prefix = $level;
        }

        // 4) Prepend prefix to the actual message
        $fullMessage = $prefix . ' ' . $message;

        // 5) Dispatch to Monolog
        $monologLevel = Logger::toMonologLevel(strtolower($level));
        $this->logger->log($monologLevel, $fullMessage, $context);

        return true;
    }

    /**
     * Map PHP’s error constants to our LEVEL names
     */
    private function mapErrorLevel(int $errNo): string
    {
        return match ($errNo) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'ERROR',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING         => 'WARNING',
            E_NOTICE, E_USER_NOTICE, E_STRICT, E_DEPRECATED, E_USER_DEPRECATED   => 'NOTICE',
            default                                                               => 'INFO',
        };
    }

    /**
     * PHP’s error handler for warnings, notices, etc.
     * Will catch E_ALL (set error_reporting(E_ALL)).
     */
    public function handlePhpError(
        int    $errno,
        string $errstr,
        string $errfile,
        int    $errline
    ): bool {
        $lvl = $this->mapErrorLevel($errno);
        $this->log(
            'php_error',
            $lvl,
            $errstr,
            ['file' => $errfile, 'line' => $errline]
        );
        // prevent PHP internal handler from running
        return true;
    }

    /**
     * Uncaught exceptions (including Eloquent ones)
     */
    public function handleUncaughtException(Throwable $e): void
    {
        // if it’s an Eloquent/Illuminate exception, tag it specially:
        $mod = str_contains(get_class($e), 'Illuminate\\Database')
             ? 'eloquent'
             : 'exception';

        $this->log(
            $mod,
            'ERROR',
            $e->getMessage(),
            [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]
        );
    }

    /**
     * Fatal shutdown errors
     */
    public function handleFatalError(): void
    {
        $err = error_get_last();
        if (!$err) {
            return;
        }
        $this->log(
            'fatal_error',
            'ERROR',
            $err['message'],
            ['file' => $err['file'], 'line' => $err['line']]
        );
    }
}
