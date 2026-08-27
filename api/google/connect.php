<?php
require __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/google_contacts.php';

$user = auth_user();
out([
    'authorization_url' => google_authorization_url((int)$user['id']),
    'scope' => ['https://www.googleapis.com/auth/contacts.readonly'],
]);
