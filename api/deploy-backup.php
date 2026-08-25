<?php

declare(strict_types=1);

/**
 * Creates a compressed backup of the live apiapp directory before deployment.
 *
 * The backup is stored outside public_html so FTP synchronization cannot delete
 * or overwrite it. The endpoint is intended for GitHub Actions only.
 */
header('Content-Type: application/json; charset=utf-8');

$homeDirectory = (string)($_SERVER['HOME'] ?? getenv('HOME') ?: '');
if ($homeDirectory === '') {
    $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $homeDirectory = dirname($documentRoot);
}

if ($homeDirectory === '' || $homeDirectory === '/') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Backup service is not configured.']);
    exit;
}

$secretFile = rtrim($homeDirectory, '/') . '/.cloudcomai_migration_token.php';
if (!is_file($secretFile) || !is_readable($secretFile)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Backup service is not configured.']);
    exit;
}

try {
    $secrets = require $secretFile;
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Backup service is not configured.']);
    exit;
}

$expectedToken = is_array($secrets) ? (string)($secrets['migration_token'] ?? '') : '';
if ($expectedToken === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Backup service is not configured.']);
    exit;
}

// GoDaddy/Apache can expose Authorization under different server variables.
// Prefer the standard header, then the CGI/redirected variants.
$authorization = trim((string)(
    $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION']
    ?? ''
));

if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        if (strcasecmp((string)$name, 'Authorization') === 0) {
            $authorization = trim((string)$value);
            break;
        }
    }
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

$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($documentRoot === '') {
    $documentRoot = rtrim($homeDirectory, '/') . '/public_html';
}

$applicationDirectory = $documentRoot . '/apiapp';
$backupDirectory = rtrim($homeDirectory, '/') . '/deployment-backups';

if (!is_dir($applicationDirectory)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Live application directory was not found.']);
    exit;
}

if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0750, true) && !is_dir($backupDirectory)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to create backup directory.']);
    exit;
}

$timestamp = gmdate('Ymd-His');
$backupFile = $backupDirectory . '/cloudcomai-apiapp-' . $timestamp . '.tar.gz';

// Create the archive from the public_html directory. The backup directory is
// outside public_html, so it cannot recursively include itself.
$command = sprintf(
    'tar -czf %s -C %s apiapp 2>&1',
    escapeshellarg($backupFile),
    escapeshellarg($documentRoot)
);

$output = [];
$returnCode = 0;
exec($command, $output, $returnCode);

if ($returnCode !== 0 || !is_file($backupFile) || filesize($backupFile) <= 0) {
    if (is_file($backupFile)) {
        @unlink($backupFile);
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to create compressed deployment backup.',
        'details' => trim(implode("\n", $output)),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Retain the five newest backups. Older archives are removed only after the
// new archive has been verified successfully.
$backups = glob($backupDirectory . '/cloudcomai-apiapp-*.tar.gz') ?: [];
usort($backups, static function (string $a, string $b): int {
    return filemtime($b) <=> filemtime($a);
});

foreach (array_slice($backups, 5) as $oldBackup) {
    @unlink($oldBackup);
}

$size = filesize($backupFile);

http_response_code(200);
echo json_encode([
    'success' => true,
    'backup' => basename($backupFile),
    'size' => $size,
    'retained' => min(5, count($backups)),
    'message' => 'Compressed deployment backup created successfully.',
], JSON_UNESCAPED_SLASHES);
