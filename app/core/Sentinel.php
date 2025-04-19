<?php
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

    /**
     * @param string        $stream         Path to file
     * @param int           $level          Monolog level
     * @param bool          $bubble
     * @param int|null      $filePermission
     * @param bool          $useLocking
     */
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
            bool    $debugMode     = true,
            string  $defaultModule = 'app'
        ) {
            // Load from env or use fallback values
            $this->debugMode     = filter_var($_ENV['APP_ENV'] ?? '', FILTER_SANITIZE_STRING) === 'debug' || $debugMode;
            $this->defaultModule = $_ENV['LOG_DEFAULT_MODULE'] ?? $defaultModule;
    
            // Resolve log path from env or fallback
            $logFile = $logFile
                ?? $_ENV['LOG_PLAIN_FILE']
                ?? __DIR__ . '/../../storage/logs/app.log';
    
            $this->initializeLogFile($logFile);
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
            if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
                throw new \RuntimeException("Cannot create log directory: {$logDir}");
            }
            if (!file_exists($logFile) && !touch($logFile)) {
                throw new \RuntimeException("Cannot create log file: {$logFile}");
            }
    
            $permissions = octdec($_ENV['LOG_FILE_PERMISSIONS'] ?? '0664');
            @chmod($logFile, $permissions);
    
            if (!is_writable($logFile)) {
                throw new \RuntimeException("Log file is not writable: {$logFile}");
            }
        }
    
        private function initializeMonolog(string $logFile): void
        {
            $this->logger = new Logger($this->defaultModule);
    
            // Parse log level from env or fallback to DEBUG
            $level = Logger::toMonologLevel(strtolower($_ENV['LOG_LEVEL'] ?? 'debug'));
    
            // Parse date format and line format from env or use default
            $lineFormat = $_ENV['LOG_TEXT_FORMAT'] ?? "%datetime% %message% %context%\n";
            $dateFormat = $_ENV['LOG_DATE_FORMAT'] ?? "Y-m-d\\TH:i:s.uP";
    
            // 1) Plain-text handler for app.log
            $textStream = new StreamHandler($logFile, $level);
            $textStream->setFormatter(new LineFormatter(
                $lineFormat,
                $dateFormat,
                true,
                true
            ));
            $this->logger->pushHandler($textStream);
    
            // 2) JSON-array handler for app.json
            $jsonFile    = $_ENV['LOG_JSON_FILE'] ?? dirname($logFile) . '/app.json';
            $useLocking  = filter_var($_ENV['LOG_USE_LOCKING'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
            $jsonHandler = new JsonArrayHandler($jsonFile, $level, true, null, $useLocking);
            $jsonHandler->setFormatter(new PrettyJsonFormatter());
            $this->logger->pushHandler($jsonHandler);
        }

    public function log(
        ?string $module,
        string  $level,
        string  $message,
        array   $context = []
    ): bool {
        $level = strtoupper($level);
        $mod   = trim((string)$module);
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
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING         => 'WARNING',
            E_NOTICE, E_USER_NOTICE, E_STRICT, E_DEPRECATED, E_USER_DEPRECATED   => 'NOTICE',
            default                                                               => 'INFO',
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
        return true;
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
    }

    public function handleFatalError(): void
    {
        $err = error_get_last();
        if ($err) {
            $this->log(
                'fatal_error',
                'ERROR',
                $err['message'],
                ['file' => $err['file'], 'line' => $err['line']]
            );
        }
    }
}
