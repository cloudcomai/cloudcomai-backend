<?php
require __DIR__ . '/../../lib/bootstrap.php';

$user = auth_user();
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int)($_GET['page_size'] ?? 50)));
$offset = ($page - 1) * $pageSize;

$countStmt = db()->prepare('SELECT COUNT(*) FROM google_contacts WHERE user_id = ? AND deleted_at IS NULL');
$countStmt->execute([(int)$user['id']]);
$total = (int)$countStmt->fetchColumn();

$stmt = db()->prepare(
    'SELECT resource_name, display_name, given_name, family_name, email, phone, photo_url, updated_at
     FROM google_contacts
     WHERE user_id = ? AND deleted_at IS NULL
     ORDER BY COALESCE(display_name, email, phone) ASC, id ASC
     LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();

out([
    'contacts' => $stmt->fetchAll(),
    'pagination' => [
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'has_more' => ($offset + $pageSize) < $total,
    ],
]);
