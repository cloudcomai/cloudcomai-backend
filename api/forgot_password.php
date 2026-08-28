<?php
require __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);

$d = input();
$identifier = strtolower(trim((string)($d['identifier'] ?? '')));
if ($identifier === '') fail('Email, mobile or User ID is required');

$pdo = db();
$st = $pdo->prepare('SELECT id, name, email FROM users WHERE email=? OR mobile=? OR user_id=? LIMIT 1');
$st->execute([$identifier, $identifier, $identifier]);
$user = $st->fetch();

// Always return a generic response to avoid exposing whether an account exists.
if (!$user || empty($user['email'])) {
    out(['message' => 'If the account exists and has a registered email address, password reset instructions have been sent.']);
}

try {
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$user['id']]);

    $rawToken = random_token(32);
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 1800);

    $insert = $pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at,created_at) VALUES(?,?,?,UTC_TIMESTAMP())');
    $insert->execute([$user['id'], $tokenHash, $expiresAt]);

    global $config;
    $webBaseUrl = rtrim((string)($config['app']['web_url'] ?? 'https://app.cloudcomai.com'), '/');
    $resetUrl = $webBaseUrl . '/?reset_token=' . urlencode($rawToken);

    $subject = 'CloudComAI password reset';
    $body = "Hello {$user['name']},\n\nUse the link below to reset your CloudComAI password:\n{$resetUrl}\n\nThis link expires in 30 minutes.\n\nIf you did not request this, you can ignore this email.";

    // Keep the sender configurable so the production cPanel/GoDaddy mailbox can be used
    // without committing credentials or other environment-specific settings to Git.
    $mailFrom = trim((string)($config['app']['mail_from'] ?? 'no-reply@cloudcomai.com'));
    if (!filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
        $mailFrom = 'no-reply@cloudcomai.com';
    }
    $headers = "From: CloudComAI <{$mailFrom}>\r\n";
    $headers .= "Reply-To: {$mailFrom}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    // The envelope sender helps shared/cPanel hosting accept and deliver mail from the
    // same domain as the From address. No SMTP credentials are stored in the repository.
    $mailResult = @mail($user['email'], $subject, $body, $headers, '-f' . $mailFrom);
    if (!$mailResult) {
        error_log('Password reset email could not be sent for user ' . $user['id'] . ' to ' . $user['email']);
    }

    out(['message' => 'If the account exists and has a registered email address, password reset instructions have been sent.']);
} catch (Throwable $e) {
    error_log('forgot_password.php error: ' . $e->getMessage());
    fail('Unable to process password reset request', 500);
}
