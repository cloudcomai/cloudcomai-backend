<?php
require __DIR__ . '/../lib/bootstrap.php';

$user = auth_user();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    fail('Method not allowed', 405);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(500, max(1, (int)($_GET['page_size'] ?? 500)));
$offset = ($page - 1) * $pageSize;

$countStmt = db()->prepare('
    SELECT COUNT(*)
    FROM google_contacts
    WHERE user_id = ? AND deleted_at IS NULL
');
$countStmt->execute([$user['id']]);
$total = (int)$countStmt->fetchColumn();

$stmt = db()->prepare('
    SELECT id, resource_name, display_name, given_name, family_name,
           email, phone, photo_url, created_at, updated_at
    FROM google_contacts
    WHERE user_id = ? AND deleted_at IS NULL
    ORDER BY COALESCE(NULLIF(display_name, \'\'), email, phone, resource_name) ASC, id ASC
    LIMIT ? OFFSET ?
');
$stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();

$contacts = $stmt->fetchAll();
foreach ($contacts as &$contact) {
    $contact['id'] = (int)$contact['id'];
}
unset($contact);

out([
    'contacts' => $contacts,
    'pagination' => [
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'has_more' => ($offset + count($contacts)) < $total,
    ],
]);
