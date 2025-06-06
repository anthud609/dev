<?php
// src/Core/Sentinel.php

namespace App\Core;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\JsonFormatter as BaseJsonFormatter;
use Monolog\LogRecord;
use Throwable;

/**
 * Forces pretty‑printing and full stack traces.
 */
class PrettyJsonFormatter extends BaseJsonFormatter
{
    public function __construct(
        int  $batchMode                  = self::BATCH_MODE_NEWLINES,
        bool $appendNewline              = true,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces         = true
    ) {
        parent::__construct(
            $batchMode,
            $appendNewline,
            $ignoreEmptyContextAndExtra,
            $includeStacktraces
        );
    }

    protected function toJson(mixed $data, bool $ignoreErrors = false): string
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $json  = json_encode($data, $flags);
        if ($this->appendNewline) {
            $json .= "\n";
        }
        return $json === false
            ? ($ignoreErrors ? '' : parent::toJson($data, $ignoreErrors))
            : $json;
    }
}

/**
 * Wraps multiple LogRecords into a single JSON array with commas.
 */
class JsonArrayHandler extends StreamHandler
{
    private bool $firstRecord = true;

    public function __construct(
        string $stream,
        int    $level          = Logger::DEBUG,
        bool   $bubble         = true,
        ?int   $filePermission = null,
        bool   $useLocking     = false
    ) {
        parent::__construct($stream, $level, $bubble, $filePermission, $useLocking);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->firstRecord) {
            if (!is_resource($this->stream)) {
                $this->stream = fopen($this->url, 'a');
                if ($this->filePermission !== null) {
                    @chmod($this->url, $this->filePermission);
                }
            }
            fwrite($this->stream, "[\n");
            $this->firstRecord = false;
        } else {
            fwrite($this->stream, ",\n");
        }

        parent::write($record);
    }

    public function close(): void
    {
        if (!$this->firstRecord && is_resource($this->stream)) {
            fwrite($this->stream, "\n]\n");
        }
        parent::close();
    }
}

class Sentinel
{
    protected Logger $logger;
    private bool $debugMode;
    private string $defaultModule;

    public function __construct(
        ?string $logFile       = null,
        bool    $debugMode     = false,
        string  $defaultModule = 'app'
    ) {
        $this->debugMode     = $debugMode;
        $this->defaultModule = $defaultModule;
        $logFile = $logFile ?? __DIR__ . '/../../storage/logs/app.log';

        $this->initializeLogFile($logFile);
        $this->initializeMonolog($logFile);

        // Register PHP error/exception/shutdown handlers
        set_error_handler([$this, 'handlePhpError']);
        set_exception_handler([$this, 'handleUncaughtException']);
        register_shutdown_function([$this, 'handleFatalError']);

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
        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            throw new \RuntimeException("Cannot create log directory: {$logDir}");
        }
        if (!file_exists($logFile) && !touch($logFile)) {
            throw new \RuntimeException("Cannot create log file: {$logFile}");
        }
        @chmod($logFile, 0664);
        if (!is_writable($logFile)) {
            throw new \RuntimeException("Log file is not writable: {$logFile}");
        }
    }

    private function initializeMonolog(string $logFile): void
    {
        $this->logger = new Logger($this->defaultModule);

        // 1) Plain-text handler for app.log
        $textStream = new StreamHandler($logFile, Logger::DEBUG);
        $textStream->setFormatter(new LineFormatter(
            "%datetime% %message% %context%\n",
            "Y-m-d\\TH:i:s.uP",
            true,
            true
        ));
        $this->logger->pushHandler($textStream);

        // 2) JSON-array handler for app.json
        $jsonFile    = dirname($logFile) . '/app.json';
        $jsonHandler = new JsonArrayHandler($jsonFile, Logger::DEBUG);
        $jsonHandler->setFormatter(new PrettyJsonFormatter());
        $this->logger->pushHandler($jsonHandler);
    }

    public function log(
        ?string $module,
        string  $level,
        string  $message,
        array   $context = []
    ): bool {
        $level  = strtoupper($level);
        $mod    = trim((string)$module);
        $prefix = $mod !== '' ? "{$mod}.{$level}" : $level;

        $this->logger->log(
            Logger::toMonologLevel(strtolower($level)),
            "{$prefix} {$message}",
            $context
        );
        return true;
    }

    private function mapErrorLevel(int $errNo): string
    {
        return match ($errNo) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'ERROR',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING              => 'WARNING',
            E_NOTICE, E_USER_NOTICE, E_STRICT, E_DEPRECATED, E_USER_DEPRECATED        => 'NOTICE',
            default                                                                    => 'INFO',
        };
    }

    public function handlePhpError(
        int    $errno,
        string $errstr,
        string $errfile,
        int    $errline
    ): bool {
        $lvl = $this->mapErrorLevel($errno);
        $this->log('php_error', $lvl, $errstr, ['file' => $errfile, 'line' => $errline]);

        // return false to allow PHP internal handler too, or true to swallow
        return false;
    }

    public function handleUncaughtException(Throwable $e): void
    {
        $mod = str_contains(get_class($e), 'Illuminate\\Database') ? 'eloquent' : 'exception';
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
        http_response_code(500);
        // optionally render friendly page or JSON here, then exit
    }

    public function handleFatalError(): void
    {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $this->log(
                'fatal_error',
                'ERROR',
                $err['message'],
                ['file' => $err['file'], 'line' => $err['line']]
            );
        }
    }
}
