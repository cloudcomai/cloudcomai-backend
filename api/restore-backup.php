<?php

declare(strict_types=1);

/**
 * Restores a previously created compressed apiapp deployment backup.
 * The archive is stored outside public_html by deploy-backup.php.
 * The live /apiapp/config directory is never replaced during rollback.
 * This endpoint is intended for GitHub Actions only.
 */
header('Content-Type: application/json; charset=utf-8');

$homeDirectory = (string)($_SERVER['HOME'] ?? getenv('HOME') ?: '');
if ($homeDirectory === '') {
    $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $homeDirectory = dirname($documentRoot);
}

$secretFile = rtrim($homeDirectory, '/') . '/.cloudcomai_migration_token.php';
if ($homeDirectory === '' || $homeDirectory === '/' || !is_file($secretFile) || !is_readable($secretFile)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Restore service is not configured.']);
    exit;
}

try {
    $secrets = require $secretFile;
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Restore service is not configured.']);
    exit;
}

$expectedToken = is_array($secrets) ? (string)($secrets['migration_token'] ?? '') : '';
$authorization = trim((string)(
    $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION']
    ?? ''
));

if ($authorization === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string)$name, 'Authorization') === 0) {
            $authorization = trim((string)$value);
            break;
        }
    }
}

if ($expectedToken === '' || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
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

$backupName = trim((string)($_POST['backup'] ?? $_GET['backup'] ?? ''));
if (!preg_match('/^cloudcomai-apiapp-[0-9]{8}-[0-9]{6}\.tar\.gz$/', $backupName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid backup name.']);
    exit;
}

$backupDirectory = rtrim($homeDirectory, '/') . '/deployment-backups';
$backupFile = $backupDirectory . '/' . $backupName;
if (!is_file($backupFile) || !is_readable($backupFile)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Requested backup was not found.']);
    exit;
}

$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($documentRoot === '') {
    $documentRoot = rtrim($homeDirectory, '/') . '/public_html';
}

$applicationDirectory = $documentRoot . '/apiapp';
$restoreRoot = rtrim($homeDirectory, '/') . '/deployment-restore-' . bin2hex(random_bytes(8));
$oldApplicationDirectory = $documentRoot . '/apiapp-rollback-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
$preservedConfigDirectory = rtrim($homeDirectory, '/') . '/apiapp-config-preserved-' . bin2hex(random_bytes(8));
$configDirectory = $applicationDirectory . '/config';
$configWasPreserved = false;

try {
    if (!mkdir($restoreRoot, 0750, true)) {
        throw new RuntimeException('Unable to create temporary restore directory.');
    }

    // Inspect the archive before extraction. Every entry must remain inside
    // the expected apiapp/ root and must not contain path traversal segments.
    $listCommand = sprintf('tar -tzf %s 2>&1', escapeshellarg($backupFile));
    $listOutput = [];
    $listCode = 0;
    exec($listCommand, $listOutput, $listCode);

    if ($listCode !== 0 || $listOutput === []) {
        throw new RuntimeException('Backup archive could not be inspected.');
    }

    foreach ($listOutput as $entry) {
        $entry = trim($entry);
        if ($entry === '' || str_starts_with($entry, '/') || str_contains($entry, '../') || str_contains($entry, '/..') || (!str_starts_with($entry, 'apiapp/') && $entry !== 'apiapp')) {
            throw new RuntimeException('Backup archive contains an invalid path.');
        }
    }

    $extractCommand = sprintf(
        'tar -xzf %s -C %s --no-same-owner 2>&1',
        escapeshellarg($backupFile),
        escapeshellarg($restoreRoot)
    );
    $extractOutput = [];
    $extractCode = 0;
    exec($extractCommand, $extractOutput, $extractCode);

    if ($extractCode !== 0 || !is_dir($restoreRoot . '/apiapp')) {
        throw new RuntimeException('Backup archive could not be extracted.');
    }

    // Preserve the currently live config directory before replacing apiapp.
    // The production config is intentionally server-managed and is never
    // restored from Git or from an application backup.
    if (is_dir($configDirectory)) {
        if (!rename($configDirectory, $preservedConfigDirectory)) {
            throw new RuntimeException('Unable to preserve the live config directory.');
        }
        $configWasPreserved = true;
    }

    if (is_dir($applicationDirectory)) {
        if (!rename($applicationDirectory, $oldApplicationDirectory)) {
            if ($configWasPreserved && !is_dir($configDirectory)) {
                @rename($preservedConfigDirectory, $configDirectory);
                $configWasPreserved = false;
            }
            throw new RuntimeException('Unable to move the current application out of the way.');
        }
    }

    // Never allow the backup archive to replace the live config directory.
    $restoredConfigDirectory = $restoreRoot . '/apiapp/config';
    if (is_dir($restoredConfigDirectory)) {
        $deleteConfigCommand = sprintf('rm -rf -- %s 2>&1', escapeshellarg($restoredConfigDirectory));
        $deleteConfigOutput = [];
        $deleteConfigCode = 0;
        exec($deleteConfigCommand, $deleteConfigOutput, $deleteConfigCode);
        if ($deleteConfigCode !== 0) {
            throw new RuntimeException('Unable to remove config from the restore payload.');
        }
    }

    if (!rename($restoreRoot . '/apiapp', $applicationDirectory)) {
        if (is_dir($oldApplicationDirectory)) {
            @rename($oldApplicationDirectory, $applicationDirectory);
        }
        if ($configWasPreserved && !is_dir($configDirectory)) {
            @rename($preservedConfigDirectory, $configDirectory);
            $configWasPreserved = false;
        }
        throw new RuntimeException('Unable to restore the backup application directory.');
    }

    if ($configWasPreserved) {
        if (!rename($preservedConfigDirectory, $configDirectory)) {
            // Do not leave a deployment without its live config. Put the old
            // application back if possible, then report rollback failure.
            $deleteRestoredCommand = sprintf('rm -rf -- %s 2>&1', escapeshellarg($applicationDirectory));
            $deleteRestoredOutput = [];
            $deleteRestoredCode = 0;
            exec($deleteRestoredCommand, $deleteRestoredOutput, $deleteRestoredCode);
            if (is_dir($oldApplicationDirectory)) {
                @rename($oldApplicationDirectory, $applicationDirectory);
            }
            @rename($preservedConfigDirectory, $configDirectory);
            throw new RuntimeException('Unable to restore the protected config directory.');
        }
        $configWasPreserved = false;
    }

    if (is_dir($oldApplicationDirectory)) {
        $deleteCommand = sprintf('rm -rf -- %s 2>&1', escapeshellarg($oldApplicationDirectory));
        $deleteOutput = [];
        $deleteCode = 0;
        exec($deleteCommand, $deleteOutput, $deleteCode);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'backup' => $backupName,
        'message' => 'Deployment backup restored successfully; protected config was preserved.',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (!is_dir($applicationDirectory) && is_dir($oldApplicationDirectory)) {
        @rename($oldApplicationDirectory, $applicationDirectory);
    }

    if ($configWasPreserved && !is_dir($configDirectory) && is_dir($preservedConfigDirectory)) {
        @rename($preservedConfigDirectory, $configDirectory);
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Deployment backup restore failed.',
        'details' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
} finally {
    if (is_dir($restoreRoot)) {
        $deleteCommand = sprintf('rm -rf -- %s 2>&1', escapeshellarg($restoreRoot));
        $deleteOutput = [];
        $deleteCode = 0;
        exec($deleteCommand, $deleteOutput, $deleteCode);
    }

    if ($configWasPreserved && is_dir($preservedConfigDirectory) && !is_dir($configDirectory)) {
        @rename($preservedConfigDirectory, $configDirectory);
    }
}
