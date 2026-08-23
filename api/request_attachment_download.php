<?php
require __DIR__ . '/../lib/bootstrap.php';
$user = auth_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
$d = input();
$id = (int)($d['attachment_id'] ?? 0);
if ($id <= 0) fail('Invalid attachment');

$st = db()->prepare('SELECT a.id,a.download_policy,m.chat_id,m.sender_id FROM message_attachments a JOIN messages m ON m.id=a.message_id WHERE a.id=?');
$st->execute([$id]);
$a = $st->fetch();
if (!$a) fail('Attachment not found', 404);
$m = db()->prepare('SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=? AND status="active"');
$m->execute([(int)$a['chat_id'], $user['id']]);
if (!$m->fetch()) fail('Not a member', 403);
if ((int)$a['sender_id'] === (int)$user['id']) out(['status' => 'APPROVED']);
if ($a['download_policy'] === 'ALLOW') out(['status' => 'APPROVED']);
if ($a['download_policy'] === 'VIEW_ONLY') fail('Download is disabled for this attachment', 403);

$existing = db()->prepare('SELECT status FROM attachment_download_requests WHERE attachment_id=? AND requester_id=?');
$existing->execute([$id, $user['id']]);
$row = $existing->fetch();
if ($row) out(['status' => $row['status']]);

$st = db()->prepare('INSERT INTO attachment_download_requests(attachment_id,requester_id,sender_id,status,requested_at) VALUES(?,?,?,?,?)');
$st->execute([$id,$user['id'],(int)$a['sender_id'],'PENDING',gmdate('Y-m-d H:i:s')]);
out(['status' => 'PENDING'], 201);
