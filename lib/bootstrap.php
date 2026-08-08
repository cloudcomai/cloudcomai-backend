<?php
declare(strict_types=1);

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Backend is not configured. Copy config.example.php to config.php.']);
    exit;
}
$config = require $configFile;

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $config['app']['allowed_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Idempotency-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function db(): PDO {
    global $config;
    static $pdo;
    if (!$pdo) {
        $d = $config['db'];
        $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}";
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
function input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
function out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $message, int $status = 400): never { out(['message' => $message], $status); }
function token_for(int $userId): string {
    global $config;
    $payload = $userId . '|' . time() . '|' . bin2hex(random_bytes(12));
    $sig = hash_hmac('sha256', $payload, $config['app']['token_secret']);
    return base64_encode($payload . '|' . $sig);
}
function auth_user(): array {
    global $config;
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/i', $header, $m)) fail('Authentication required', 401);
    $decoded = base64_decode($m[1], true);
    if (!$decoded) fail('Invalid token', 401);
    $parts = explode('|', $decoded);
    if (count($parts) !== 4) fail('Invalid token', 401);
    [$uid,$issued,$nonce,$sig] = $parts;
    $payload = "$uid|$issued|$nonce";
    $expected = hash_hmac('sha256', $payload, $config['app']['token_secret']);
    if (!hash_equals($expected, $sig)) fail('Invalid token', 401);
    if ((int)$issued < time() - 60*60*24*30) fail('Token expired', 401);
    $st = db()->prepare('SELECT id, user_id, name, email, mobile, gender, account_status FROM users WHERE id=?');
    $st->execute([(int)$uid]);
    $user = $st->fetch();
    if (!$user || $user['account_status'] !== 'active') fail('Account unavailable', 401);
    return $user;
}
function age_from_dob(string $dob): int {
    try { return (new DateTime($dob))->diff(new DateTime('today'))->y; }
    catch (Throwable $e) { return -1; }
}
function random_token(int $bytes=32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
