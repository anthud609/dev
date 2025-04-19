<?php

namespace App\Core;

use Throwable;

class Sentinel
{
    protected string $logFile;
    private bool $handlingError = false;
    private bool $debugMode;

    public function __construct(string $logFile = null, bool $debugMode = true)  // Debug mode ON by default
    {
        $this->debugMode = $debugMode;
        $this->logFile = $logFile ?? __DIR__ . '/../../storage/logs/app.log';  // Fixed path depth
        
        try {
            $this->initializeLogFile();
            $this->registerErrorHandlers();
            
            if ($this->debugMode) {
                $this->log('DEBUG', 'Logger initialized', [
                    'logFile' => $this->logFile,
                    'realpath' => realpath($this->logFile) ?: 'NOT FOUND',
                    'writable' => is_writable($this->logFile) ? 'YES' : 'NO'
                ]);
            }
        } catch (\Throwable $e) {
            if ($this->debugMode) {
                error_log("LOGGER CONSTRUCTION FAILED: " . $e->getMessage());
            }
            throw $e;
        }
    }

    private function initializeLogFile(): void
    {
        $logDir = dirname($this->logFile);
        
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0775, true) && !is_dir($logDir)) {
                throw new \RuntimeException("Cannot create log directory: {$logDir}");
            }
            if ($this->debugMode) {
                error_log("Created log directory: {$logDir}");
            }
        }
        
        if (!file_exists($this->logFile)) {
            if (touch($this->logFile)) {
                chmod($this->logFile, 0664);
                if ($this->debugMode) {
                    error_log("Created log file: {$this->logFile}");
                }
            } else {
                throw new \RuntimeException("Cannot create log file: {$this->logFile}");
            }
        }
        
        if (!is_writable($this->logFile)) {
            throw new \RuntimeException("Log file is not writable: {$this->logFile}");
        }
    }

    public function log(string $level, string $message, $context = null, string $module = null): bool
    {
        if ($this->handlingError) {
            if ($this->debugMode) {
                error_log("Prevented recursive logging during error handling");
            }
            return false;
        }
        
        $this->handlingError = true;
        $success = false;
        
        try {
            $timestamp = date('Y-m-d H:i:s');
            $moduleStr = $module ? "[$module] " : '';
            
            $contextStr = '';
            if ($context !== null) {
                if (is_scalar($context)) {
                    $contextStr = (string)$context;
                } else {
                    $contextStr = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($contextStr === false) {
                        $contextStr = '{"json_error":"'.json_last_error_msg().'"}';
                    }
                }
            }
            
            $entry = "[$timestamp] $moduleStr[$level] $message $contextStr" . PHP_EOL;
            
            $bytesWritten = file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
            $success = $bytesWritten !== false;
            
            if ($this->debugMode) {
                if ($success) {
                    error_log("Logged successfully to {$this->logFile}");
                } else {
                    error_log("FAILED to log to {$this->logFile}");
                    error_log("Attempted entry: " . $entry);
                }
            }
        } catch (Throwable $e) {
            if ($this->debugMode) {
                error_log("Logger error: " . $e->getMessage());
                error_log("Log file: " . $this->logFile);
                error_log("File exists: " . (file_exists($this->logFile) ? 'YES' : 'NO'));
                error_log("Writable: " . (is_writable($this->logFile) ? 'YES' : 'NO'));
            }
        } finally {
            $this->handlingError = false;
        }
        
        return $success;
    }
    public function exception(Throwable $e): void
    {
        $this->log('ERROR', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function registerErrorHandlers(): void
    {
        set_error_handler([$this, 'handlePhpError']);
        set_exception_handler([$this, 'handleUncaughtException']);
        register_shutdown_function([$this, 'handleFatalError']);
    }

    public function handlePhpError(int $errno, string $errstr, string $errfile = null, int $errline = null): bool
    {
        $this->log('WARNING', "PHP Error [$errno]: $errstr", [
            'file' => $errfile,
            'line' => $errline
        ]);
        return false; // Let PHP continue with its normal error handler
    }

    public function handleUncaughtException(Throwable $e): void
    {
        $this->exception($e);
        exit(1); // Exit after logging uncaught exception
    }

    public function handleFatalError(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->log('CRITICAL', "Fatal Error: {$error['message']}", [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]);
        }
    }
}