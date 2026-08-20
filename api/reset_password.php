<?php
require __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);

$d = input();
$token = trim((string)($d['token'] ?? ''));
$password = (string)($d['password'] ?? '');

if ($token === '' || $password === '') fail('Reset token and new password are required');
if (strlen($password) < 8) fail('Password must be at least 8 characters');

$pdo = db();
$st = $pdo->prepare('
    SELECT id, user_id
    FROM password_reset_tokens
    WHERE token_hash=?
      AND used_at IS NULL
      AND expires_at > UTC_TIMESTAMP()
    LIMIT 1
');
$st->execute([hash('sha256', $token)]);
$reset = $st->fetch();
if (!$reset) fail('Reset token is invalid or expired', 400);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET password_hash=?, updated_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$reset['id']]);
    $pdo->commit();
    out(['message' => 'Password has been reset successfully']);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('reset_password.php error: ' . $e->getMessage());
    fail('Unable to reset password', 500);
}
