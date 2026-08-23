<?php
require __DIR__ . '/../lib/bootstrap.php';
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);

$chat = (int)($_POST['chat_id'] ?? 0);
$body = trim((string)($_POST['body'] ?? ''));
$reply = (int)($_POST['reply_to_message_id'] ?? 0);
$policy = strtoupper((string)($_POST['download_policy'] ?? 'APPROVAL_REQUIRED'));
if (!in_array($policy, ['ALLOW','APPROVAL_REQUIRED','VIEW_ONLY'], true)) fail('Invalid download policy');
if ($chat <= 0) fail('Invalid chat');
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) fail('File is required');

$m = db()->prepare('SELECT c.retention_seconds FROM chats c JOIN chat_members cm ON cm.chat_id=c.id WHERE c.id=? AND cm.user_id=? AND cm.status="active"');
$m->execute([$chat, $user['id']]);
$row = $m->fetch();
if (!$row) fail('Not a member', 403);

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('File upload failed');
$maxBytes = 25 * 1024 * 1024;
if ((int)$file['size'] <= 0 || (int)$file['size'] > $maxBytes) fail('File must be between 1 byte and 25 MB');

$allowed = [
 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
 'application/pdf' => 'pdf', 'text/plain' => 'txt',
 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
 'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
 'application/vnd.ms-powerpoint' => 'ppt', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx'
];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if (!isset($allowed[$mime])) fail('File type is not allowed');

if ($reply) {
 $r = db()->prepare('SELECT id FROM messages WHERE id=? AND chat_id=?');
 $r->execute([$reply, $chat]);
 if (!$r->fetch()) fail('Invalid reply target');
}

$root = dirname(__DIR__) . '/storage/attachments';
if (!is_dir($root) && !mkdir($root, 0750, true)) fail('Unable to prepare attachment storage', 500);
$stored = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
$path = $root . '/' . $stored;
if (!move_uploaded_file($file['tmp_name'], $path)) fail('Unable to store attachment', 500);

$expires = $row['retention_seconds'] ? gmdate('Y-m-d H:i:s', time() + (int)$row['retention_seconds']) : null;
$createdAt = gmdate('Y-m-d H:i:s');
$pdo = db();
try {
 $pdo->beginTransaction();
 $st = $pdo->prepare('INSERT INTO messages(chat_id,sender_id,type,body,reply_to_message_id,expires_at,created_at) VALUES(?,?,?,?,?,?,?)');
 $st->execute([$chat,$user['id'],'attachment',$body !== '' ? $body : null,$reply ?: null,$expires,$createdAt]);
 $messageId = (int)$pdo->lastInsertId();
 $st = $pdo->prepare('INSERT INTO message_attachments(message_id,original_filename,stored_filename,storage_path,mime_type,file_size,download_policy,created_at) VALUES(?,?,?,?,?,?,?,?)');
 $st->execute([$messageId, basename((string)$file['name']), $stored, 'storage/attachments/' . $stored, $mime, (int)$file['size'], $policy, $createdAt]);
 $attachmentId = (int)$pdo->lastInsertId();
 $pdo->commit();
} catch (Throwable $e) {
 if ($pdo->inTransaction()) $pdo->rollBack();
 @unlink($path);
 throw $e;
}

out(['message' => [
 'id' => $messageId, 'chat_id' => $chat, 'sender_id' => (int)$user['id'], 'sender_name' => $user['name'],
 'type' => 'attachment', 'body' => $body, 'reply_to_message_id' => $reply ?: null, 'created_at' => $createdAt,
 'attachment' => ['id' => $attachmentId, 'name' => basename((string)$file['name']), 'mime_type' => $mime, 'file_size' => (int)$file['size'], 'download_policy' => $policy]
]], 201);
