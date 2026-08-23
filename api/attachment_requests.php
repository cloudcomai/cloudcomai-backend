<?php
require __DIR__ . '/../lib/bootstrap.php';
$user = auth_user();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Method not allowed', 405);
$st = db()->prepare('SELECT r.id AS request_id,r.status,r.requested_at,a.id AS attachment_id,a.original_filename,a.mime_type,a.file_size,m.chat_id,m.sender_id,u.name AS requester_name FROM attachment_download_requests r JOIN message_attachments a ON a.id=r.attachment_id JOIN messages m ON m.id=a.message_id JOIN users u ON u.id=r.requester_id WHERE r.sender_id=? AND r.status="PENDING" ORDER BY r.requested_at ASC LIMIT 100');
$st->execute([$user['id']]);
out(['requests' => $st->fetchAll()]);
