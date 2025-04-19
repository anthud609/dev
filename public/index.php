<?php

require __DIR__ . '/../vendor/autoload.php'; // or a manual require

use App\Core\Sentinel;

$logger = new Sentinel();
$logger->log('INFO', 'Call module started', 'Just a string context', 'calls');
$logger->log('INFO', 'Auth module started', ['user' => 'john_doe', 'action' => 'login'], 'auth');
strpos(); // Will trigger a warning
throw new Exception("Authentication error occurred"); // Will trigger exception handling