<?php
declare(strict_types=1);

// Migration package location:
// /apiapp/deploy-migrations/lib/bootstrap.php
// Production configuration remains outside the package at:
// /apiapp/config/config.php
$configFile = dirname(__DIR__, 2) . '/config/config.php';

if (!file_exists($configFile)) {
    throw new RuntimeException('Backend database configuration is not available on the server.');
}

$config = require $configFile;

function db(): PDO {
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
