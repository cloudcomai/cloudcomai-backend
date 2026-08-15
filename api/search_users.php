<?php
require __DIR__ . '/../lib/bootstrap.php';

// --- ENFORCE PRODUCTION CORS FOR LOCALHOST DEVELOPMENT ---
$allowed_origins = ['https://cloudcomai.com', 'http://localhost:5173'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
}

// Exit early if it's an OPTIONS preflight verification check
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Authenticate session token block
$user = auth_user(); 
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $query = trim((string)($_GET['q'] ?? ''));

    if ($query === '') {
        out(['users' => []]);
    }

    // Secure parameterized query looking up matching names, emails, or user IDs
    // We append the % wildcards safely to prevent SQL injection vulnerabilities
    $searchString = '%' . $query . '%';
    $st = db()->prepare('SELECT id, name, user_id FROM users WHERE (name LIKE ? OR email LIKE ? OR user_id LIKE ?) AND id != ? LIMIT 15');
    $st->execute([$searchString, $searchString, $searchString, $user['id']]);
    
    out(['users' => $st->fetchAll()]);
}

fail('Method not allowed', 405);
