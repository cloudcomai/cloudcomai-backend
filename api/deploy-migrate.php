<?php

declare(strict_types=1);

/**
 * Protected deployment endpoint used by GitHub Actions to run database migrations.
 *
 * Required server environment variable:
 *   MIGRATION_TOKEN
 */

header('Content-Type: application/json');

$expectedToken = getenv('MIGRATION_TOKEN');

if ($expectedToken === false || $expectedToken === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Migration service is not configured.']);
    exit;
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
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

ob_start();

try {
    require __DIR__ . '/../scripts/migrate.php';
    $output = trim(ob_get_clean());

    http_response_code(200);
    echo json_encode([
        'success' => true,
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
