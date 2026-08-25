<?php

declare(strict_types=1);

// Deployed as /apiapp/deploy-migrations/lib/bootstrap.php.
// Load the production application configuration without copying credentials
// into the migration package.
$configFile = dirname(__DIR__, 2) . '/config/config.php';

if (!is_file($configFile)) {
    throw new RuntimeException("Production config not found: {$configFile}");
}

$config = require $configFile;

if (!is_array($config) || !isset($config['db']) || !is_array($config['db'])) {
    throw new RuntimeException('Production database configuration is invalid.');
}

function db(): PDO
{
    global $config;
    static $pdo;

    if (!$pdo) {
        $d = $config['db'];
        $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}";
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}
