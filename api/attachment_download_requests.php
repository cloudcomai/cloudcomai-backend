<?php
require __DIR__ . '/../lib/bootstrap.php';
$user = auth_user();
$chat = (int)($_GET['chat_id'] ?? 0);
if ($chat <= 0) fail('Invalid chat');

$st = db()->prepare('SELECT r.id AS request_id,r.attachment_id,r.requester_id,r.status,r.requested_at,a.original_filename,a.mime_type,m.id AS message_id FROM attachment_download_requests r JOIN message_attachments a ON a.id=r.attachment_id JOIN messages m ON m.id=a.message_id WHERE m.chat_id=? AND r.sender_id=? AND r.status="PENDING" ORDER BY r.requested_at ASC');
$st->execute([$chat, $user['id']]);
out(['requests' => $st->fetchAll()]);
