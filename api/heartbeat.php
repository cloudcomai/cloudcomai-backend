<?php
require __DIR__ . '/../lib/bootstrap.php';

$user = auth_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);

db()->prepare('UPDATE users SET updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$user['id']]);
out(['ok' => true, 'last_seen_at' => gmdate('c')]);
