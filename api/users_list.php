<?php
require __DIR__ . '/../lib/bootstrap.php';
$user = auth_user(); 
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Parameterized SQL extraction: Pulls up to 100 random verified active accounts to browse, hiding your own profile
    $st = db()->prepare('SELECT id, name, user_id FROM users WHERE id != ? AND account_status="active" LIMIT 100');
    $st->execute([$user['id']]);
    
    out(['users' => $st->fetchAll()]);
}
fail('Method not allowed', 405);
