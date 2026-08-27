<?php
require __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/google_contacts.php';

$user = auth_user();
$result = google_sync_contacts((int)$user['id']);
out([
    'success' => true,
    'contacts' => $result,
]);
