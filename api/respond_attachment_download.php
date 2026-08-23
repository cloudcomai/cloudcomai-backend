<?php
require __DIR__ . '/../lib/bootstrap.php';
$user = auth_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
$d = input();
$id = (int)($d['request_id'] ?? 0);
$status = strtoupper((string)($d['status'] ?? ''));
if ($id <= 0 || !in_array($status, ['APPROVED','DENIED'], true)) fail('Invalid request');

$st = db()->prepare('SELECT r.id,r.status,r.sender_id,a.id AS attachment_id,m.chat_id FROM attachment_download_requests r JOIN message_attachments a ON a.id=r.attachment_id JOIN messages m ON m.id=a.message_id WHERE r.id=?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) fail('Download request not found', 404);
if ((int)$r['sender_id'] !== (int)$user['id']) fail('Only the sender can approve this request', 403);
if ($r['status'] !== 'PENDING') fail('Request has already been answered');

$member = db()->prepare('SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=? AND status="active"');
$member->execute([(int)$r['chat_id'], $user['id']]);
if (!$member->fetch()) fail('Not a member', 403);

$st = db()->prepare('UPDATE attachment_download_requests SET status=?,responded_at=? WHERE id=? AND status="PENDING"');
$st->execute([$status, gmdate('Y-m-d H:i:s'), $id]);
out(['request_id' => $id, 'status' => $status, 'attachment_id' => (int)$r['attachment_id']]);
