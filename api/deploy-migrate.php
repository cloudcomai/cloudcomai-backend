<?php

declare(strict_types=1);

/**
 * Protected deployment endpoint used by GitHub Actions to run database migrations.
 *
 * The migration token is stored in a server-only file outside public_html.
 * Expected location:
 *   /home/<cpanel-user>/.cloudcomai_migration_token.php
 *
 * File format:
 *   <?php
 *   return ['migration_token' => '...'];
 */

header('Content-Type: application/json');

$homeDirectory = (string)($_SERVER['HOME'] ?? getenv('HOME') ?: '');

if ($homeDirectory === '') {
    $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $homeDirectory = dirname($documentRoot);
}

$secretFile = rtrim($homeDirectory, '/') . '/.cloudcomai_migration_token.php';

if (!is_file($secretFile) || !is_readable($secretFile)) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'Migration service is not configured.'
    ]);
    exit;
}

try {
    $secrets = require $secretFile;
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'Migration service is not configured.'
    ]);
    exit;
}

$expectedToken = is_array($secrets)
    ? (string)($secrets['migration_token'] ?? '')
    : '';

if ($expectedToken === '') {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'Migration service is not configured.'
    ]);
    exit;
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

// Some Apache/PHP configurations do not populate HTTP_AUTHORIZATION.
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

$prefix = 'Bearer ';

if (!str_starts_with($authorization, $prefix)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$providedToken = substr($authorization, strlen($prefix));

if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../scripts/migrate.php';

ob_start();

try {
    $applied = cloudcomaiRunMigrations();
    $output = trim((string)ob_get_clean());

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'applied' => $applied,
        'message' => 'Database migrations completed successfully.',
        'output' => $output,
    ]);
} catch (Throwable $e) {
    ob_end_clean();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database migration failed.',
    ]);
}
