<?php
require __DIR__ . '/../../lib/bootstrap.php';

$user = auth_user();
$st = db()->prepare('SELECT google_email, last_contacts_sync_at FROM google_accounts WHERE user_id = ? LIMIT 1');
$st->execute([(int)$user['id']]);
$account = $st->fetch();

if (!$account) {
    out(['connected' => false, 'email' => null, 'last_contacts_sync_at' => null, 'contact_count' => 0]);
}

$count = db()->prepare('SELECT COUNT(*) FROM google_contacts WHERE user_id = ? AND deleted_at IS NULL');
$count->execute([(int)$user['id']]);

out([
    'connected' => true,
    'email' => $account['google_email'],
    'last_contacts_sync_at' => $account['last_contacts_sync_at'],
    'contact_count' => (int)$count->fetchColumn(),
]);
