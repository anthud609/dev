<?php
namespace App\Core;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Throwable;

class Sentinel
{
    protected Logger $logger;
    private bool $debugMode;
    private string $defaultModule;

    public function __construct(string $logFile = null, bool $debugMode = true, string $defaultModule = 'app_logger') 
    {
        $this->debugMode = $debugMode;
        $this->defaultModule = $defaultModule;
        
        $logFile = $logFile ?? __DIR__ . '/../../storage/logs/app.log';

        try {
            $this->initializeLogFile($logFile);
            $this->initializeMonolog($logFile);

            if ($this->debugMode) {
                $this->logger->debug('Logger initialized', [
                    'logFile' => $logFile,
                    'realpath' => realpath($logFile) ?: 'NOT FOUND',
                    'writable' => is_writable($logFile) ? 'YES' : 'NO'
                ]);
            }
        } catch (\Throwable $e) {
            if ($this->debugMode) {
                error_log("LOGGER CONSTRUCTION FAILED: " . $e->getMessage());
            }
            throw $e;
        }
    }

    private function initializeLogFile(string $logFile): void
    {
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0775, true) && !is_dir($logDir)) {
                throw new \RuntimeException("Cannot create log directory: {$logDir}");
            }
            if ($this->debugMode) {
                error_log("Created log directory: {$logDir}");
            }
        }
        
        if (!file_exists($logFile)) {
            if (touch($logFile)) {
                chmod($logFile, 0664);
                if ($this->debugMode) {
                    error_log("Created log file: {$logFile}");
                }
            } else {
                throw new \RuntimeException("Cannot create log file: {$logFile}");
            }
        }
        
        if (!is_writable($logFile)) {
            throw new \RuntimeException("Log file is not writable: {$logFile}");
        }
    }

    private function initializeMonolog(string $logFile): void
    {
        // Create the logger with the default module name
        $this->logger = new Logger($this->defaultModule);
        
        // Create a handler for logging to the file
        $streamHandler = new StreamHandler($logFile, Logger::DEBUG);  // Adjust log level as needed
        
        // Custom format (do not include timestamp in message)
        $formatter = new LineFormatter('%datetime% %channel%.%level_name%: %message%' . PHP_EOL, 'Y-m-d\TH:i:s.uP', true, true);
        $streamHandler->setFormatter($formatter);
        
        // Push the handler to the logger
        $this->logger->pushHandler($streamHandler);
    }

    public function log(string $module = null, string $level = 'info', string $message = '', array $context = []): bool
    {
        // If module name is not provided, use the default one
        $module = $module ?: $this->defaultModule;

        // Format message: Only include module_name.LEVEL
        $formattedMessage = "$module.$level: $message";

        // Strip out the module name from the beginning of the message if it matches the default module
        if (strpos($formattedMessage, "$this->defaultModule.") === 0) {
            // Remove the default module prefix
            $formattedMessage = substr($formattedMessage, strlen($this->defaultModule) + 1);
        }

        // Log the message with the module name and level only
        $this->logger->log(Logger::toMonologLevel($level), $formattedMessage, $context);

        return true;
    }

    public function exception(Throwable $e): void
    {
        $this->log('app', 'error', 'ERROR: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    // Handle PHP errors
    public function handlePhpError(int $level, string $message, string $file, int $line): bool
    {
        $this->log('php_error', 'ERROR', "PHP Error: [$level] $message in $file on line $line");
        return true; // Prevent default PHP error handler
    }

    // Handle uncaught exceptions
    public function handleUncaughtException(Throwable $e): void
    {
        $this->exception($e);
    }

    // Handle fatal errors
    public function handleFatalError(): void
    {
        $error = error_get_last();
        if ($error) {
            $this->log('fatal_error', 'ERROR', "Fatal error: {$error['message']} in {$error['file']} on line {$error['line']}");
        }
    }
}
