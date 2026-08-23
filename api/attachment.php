<?php
require __DIR__ . '/../lib/bootstrap.php';
$user = auth_user();
$id = (int)($_GET['id'] ?? 0);
$preview = ($_GET['preview'] ?? '') === '1';
if ($id <= 0) fail('Invalid attachment', 400);

$st = db()->prepare('SELECT a.*,m.chat_id,m.sender_id,c.type AS chat_type FROM message_attachments a JOIN messages m ON m.id=a.message_id JOIN chats c ON c.id=m.chat_id WHERE a.id=?');
$st->execute([$id]);
$a = $st->fetch();
if (!$a) fail('Attachment not found', 404);

$member = db()->prepare('SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=? AND status="active"');
$member->execute([(int)$a['chat_id'], $user['id']]);
if (!$member->fetch()) fail('Not a member', 403);

$isImage = str_starts_with((string)$a['mime_type'], 'image/');
if ($preview) {
    if (!$isImage) fail('Preview is not available for this file type', 400);
    // Images may be viewed by chat members, but the original download remains protected.
    $authorizedPreview = true;
} else {
    $authorizedPreview = (int)$a['sender_id'] === (int)$user['id'] || $a['download_policy'] === 'ALLOW';
    if (!$authorizedPreview && $a['download_policy'] === 'APPROVAL_REQUIRED') {
        $q = db()->prepare('SELECT 1 FROM attachment_download_requests WHERE attachment_id=? AND requester_id=? AND status="APPROVED"');
        $q->execute([$id, $user['id']]);
        $authorizedPreview = (bool)$q->fetch();
    }
}
if (!$authorizedPreview) fail($a['download_policy'] === 'VIEW_ONLY' ? 'Download is disabled for this attachment' : 'Download requires sender approval', 403);

$root = dirname(__DIR__);
$path = $root . '/' . ltrim($a['storage_path'], '/');
if (!is_file($path)) fail('Attachment file not found', 404);

header('Content-Type: ' . $a['mime_type']);
header('Content-Length: ' . (string)$a['file_size']);
header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . addcslashes(basename($a['original_filename']), '"\\') . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
