<?php
require __DIR__ . '/../lib/bootstrap.php';
$user = auth_user();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = db()->prepare('
        SELECT id, name, user_id, updated_at,
               CASE WHEN updated_at IS NOT NULL AND updated_at >= UTC_TIMESTAMP() - INTERVAL 90 SECOND THEN 1 ELSE 0 END AS online
        FROM users
        WHERE id != ? AND account_status="active"
        LIMIT 100
    ');
    $st->execute([$user['id']]);
    $users = $st->fetchAll();
    foreach ($users as &$row) {
        $row['id'] = (int)$row['id'];
        $row['online'] = (bool)$row['online'];
    }
    unset($row);
    out(['users' => $users]);
}
fail('Method not allowed', 405);
