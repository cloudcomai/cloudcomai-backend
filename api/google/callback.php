<?php
require __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/google_contacts.php';

$webUrl = rtrim($config['app']['web_url'] ?? '', '/');

function google_callback_page(string $webUrl, bool $success, string $message): never
{
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $status = $success ? 'success' : 'error';
    $redirect = $webUrl !== '' ? $webUrl . '/?google=' . rawurlencode($status) : '';

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>CloudComAI Google</title></head><body>';
    echo '<p>' . $safeMessage . '</p>';
    if ($redirect !== '') {
        echo '<p>You can close this window and return to CloudComAI.</p>';
        echo '<script>window.opener && window.opener.postMessage({type:"cloudcomai-google",status:' . json_encode($status) . '}, ' . json_encode($webUrl) . '); setTimeout(function(){ window.location.href=' . json_encode($redirect) . '; }, 500);</script>';
    }
    echo '</body></html>';
    exit;
}

if (!empty($_GET['error'])) {
    google_callback_page($webUrl, false, 'Google authorization was cancelled or denied.');
}

$state = (string)($_GET['state'] ?? '');
$code = (string)($_GET['code'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $state) || $code === '') {
    google_callback_page($webUrl, false, 'Invalid Google authorization response.');
}

$stateStmt = db()->prepare(
    'SELECT state, user_id FROM google_oauth_states WHERE state = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1'
);
$stateStmt->execute([$state]);
$oauthState = $stateStmt->fetch();
if (!$oauthState) {
    google_callback_page($webUrl, false, 'Google authorization session expired. Please try again.');
}

db()->prepare('DELETE FROM google_oauth_states WHERE state = ?')->execute([$state]);

try {
    $tokens = google_exchange_code($code);
    $accessToken = (string)$tokens['access_token'];
    $refreshToken = (string)($tokens['refresh_token'] ?? '');

    $existingStmt = db()->prepare('SELECT * FROM google_accounts WHERE user_id = ? LIMIT 1');
    $existingStmt->execute([(int)$oauthState['user_id']]);
    $existing = $existingStmt->fetch();

    if ($refreshToken === '' && $existing) {
        $refreshToken = google_decrypt($existing['refresh_token_encrypted']);
    }
    if ($refreshToken === '') {
        throw new RuntimeException('Google did not return a refresh token. Disconnect CloudComAI from your Google account and reconnect.');
    }

    $googleUser = google_userinfo($accessToken);
    $encryptedRefreshToken = google_encrypt($refreshToken);

    $sql = 'INSERT INTO google_accounts
        (user_id, google_subject, google_email, refresh_token_encrypted, scope)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            google_subject=VALUES(google_subject), google_email=VALUES(google_email),
            refresh_token_encrypted=VALUES(refresh_token_encrypted), scope=VALUES(scope), updated_at=UTC_TIMESTAMP()';
    db()->prepare($sql)->execute([
        (int)$oauthState['user_id'],
        $googleUser['sub'] ?? null,
        $googleUser['email'] ?? null,
        $encryptedRefreshToken,
        $tokens['scope'] ?? 'https://www.googleapis.com/auth/contacts.readonly'
    ]);

    google_sync_contacts((int)$oauthState['user_id']);
    google_callback_page($webUrl, true, 'Google Contacts connected and synchronized successfully.');
} catch (Throwable $e) {
    error_log('CloudComAI Google callback error: ' . $e->getMessage());
    google_callback_page($webUrl, false, 'Unable to connect Google Contacts. Please try again.');
}
