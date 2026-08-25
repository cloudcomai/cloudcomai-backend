<?php

declare(strict_types=1);

/**
 * Protected deployment endpoint used by GitHub Actions to run database migrations.
 *
 * This endpoint is deployed under /apiapp/deploy-migrations/api/ so the
 * migration-only FTP sync never deletes the live application files.
 */

header('Content-Type: application/json; charset=utf-8');

$homeDirectory = (string)($_SERVER['HOME'] ?? getenv('HOME') ?: '');
if ($homeDirectory === '') {
    $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $homeDirectory = dirname($documentRoot);
}

$secretFile = rtrim($homeDirectory, '/') . '/.cloudcomai_migration_token.php';

if (!is_file($secretFile) || !is_readable($secretFile)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Migration service is not configured.']);
    exit;
}

try {
    $secrets = require $secretFile;
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Migration service is not configured.']);
    exit;
}

$expectedToken = is_array($secrets) ? (string)($secrets['migration_token'] ?? '') : '';

if ($expectedToken === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Migration service is not configured.']);
    exit;
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$providedToken = trim($matches[1]);
if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$migrationScript = __DIR__ . '/../scripts/migrate.php';

if (!is_file($migrationScript)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Migration package is not installed.']);
    exit;
}

try {
    require_once $migrationScript;

    ob_start();
    $applied = cloudcomaiRunMigrations();
    $output = trim((string)ob_get_clean());

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'applied' => $applied,
        'message' => 'Database migrations completed successfully.',
        'output' => $output,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Safe diagnostic: migration messages only; no credentials or tokens.
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database migration failed.',
        'details' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
