<?php

declare(strict_types=1);

// This script is deployed under /apiapp/deploy-migrations/scripts/.
// The migration package contains its own bootstrap under /apiapp/deploy-migrations/lib/.
$bootstrap = __DIR__ . '/../lib/bootstrap.php';

if (!is_file($bootstrap)) {
    throw new RuntimeException("Migration bootstrap not found: {$bootstrap}");
}

require_once $bootstrap;

$migrationRoot = dirname(__DIR__);

function cloudcomaiRunMigrations(): int
{
    global $migrationRoot;

    $pdo = db();
    $migrationsPath = $migrationRoot . '/database/migrations';
    $lockName = 'cloudcomai_database_migration';

    if (!is_dir($migrationsPath)) {
        throw new RuntimeException("Migration directory not found: {$migrationsPath}");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(255) NOT NULL PRIMARY KEY,
        executed_at DATETIME NOT NULL
    ) ENGINE=InnoDB");

    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);

    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire migration lock. Another migration may be running.');
    }

    try {
        $files = glob($migrationsPath . '/*.sql');
        if ($files === false) {
            throw new RuntimeException('Unable to read migration directory.');
        }

        sort($files, SORT_STRING);

        $executed = [];
        foreach ($pdo->query('SELECT version FROM schema_migrations') as $row) {
            $executed[$row['version']] = true;
        }

        $applied = 0;

        foreach ($files as $file) {
            $version = basename($file);

            if (isset($executed[$version])) {
                echo "[SKIP] {$version}\n";
                continue;
            }

            $sql = trim((string)file_get_contents($file));
            if ($sql === '') {
                echo "[SKIP] {$version} (empty)\n";
                continue;
            }

            echo "[RUN ] {$version}\n";

            try {
                $pdo->beginTransaction();
                $pdo->exec($sql);

                $insert = $pdo->prepare(
                    'INSERT INTO schema_migrations(version, executed_at) VALUES (?, UTC_TIMESTAMP())'
                );
                $insert->execute([$version]);
                $pdo->commit();

                echo "[ OK ] {$version}\n";
                $applied++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw new RuntimeException(
                    "Migration failed: {$version}. {$e->getMessage()}",
                    0,
                    $e
                );
            }
        }

        echo "Migration completed successfully. Applied: {$applied}\n";
        return $applied;
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

if (PHP_SAPI === 'cli') {
    try {
        cloudcomaiRunMigrations();
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "MIGRATION FAILED: {$e->getMessage()}\n");
        exit(1);
    }
}
