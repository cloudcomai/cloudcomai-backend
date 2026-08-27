<?php
require __DIR__ . '/../lib/bootstrap.php';
$user = auth_user();
$method = $_SERVER['REQUEST_METHOD'];

function hydrate_message_attachments(array &$messages): void {
    if (!$messages) return;
    $ids = array_values(array_filter(array_map(fn($m) => (int)($m['id'] ?? 0), $messages)));
    if (!$ids) return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id,message_id,original_filename,mime_type,file_size,download_policy FROM message_attachments WHERE message_id IN ($placeholders) ORDER BY id ASC");
    $st->execute($ids);
    $attachments = [];
    foreach ($st->fetchAll() as $a) {
        $attachments[(int)$a['message_id']][] = [
            'id' => (int)$a['id'],
            'name' => $a['original_filename'],
            'mime_type' => $a['mime_type'],
            'file_size' => (int)$a['file_size'],
            'download_policy' => $a['download_policy']
        ];
    }
    foreach ($messages as &$message) {
        $messageAttachments = $attachments[(int)$message['id']] ?? [];
        $message['attachments'] = $messageAttachments;
        // Keep a primary attachment alias for the current chat UI while
        // retaining the array for future multi-attachment messages.
        $message['attachment'] = $messageAttachments[0] ?? null;
    }
    unset($message);
}

if ($method === 'GET') {
    $chat = (int)($_GET['chat_id'] ?? 0);
    $after = (int)($_GET['after_id'] ?? 0);

    $m = db()->prepare('SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=? AND status="active"');
    $m->execute([$chat, $user['id']]);
    if (!$m->fetch()) fail('Not a member', 403);

    $st = db()->prepare('SELECT m.id,m.chat_id,m.sender_id,m.type,m.body,m.reply_to_message_id,m.edit_count,m.edited_at,m.created_at,u.name AS sender_name,r.body AS reply_to_text,ru.name AS reply_to_sender_name FROM messages m JOIN users u ON u.id=m.sender_id LEFT JOIN messages r ON r.id=m.reply_to_message_id LEFT JOIN users ru ON ru.id=r.sender_id WHERE m.chat_id=? AND m.id>? AND m.deleted_for_everyone=0 AND (m.expires_at IS NULL OR m.expires_at>UTC_TIMESTAMP()) ORDER BY m.id ASC LIMIT 200');
    $st->execute([$chat, $after]);
    $messages = $st->fetchAll();
    hydrate_message_attachments($messages);

    $pollQuery = db()->prepare('SELECT p.id,p.question,po.id AS option_id,po.option_text,po.display_order,COUNT(pv.user_id) AS votes,MAX(CASE WHEN pv.user_id=? THEN 1 ELSE 0 END) AS selected FROM polls p INNER JOIN poll_options po ON po.poll_id=p.id LEFT JOIN poll_votes pv ON pv.option_id=po.id AND pv.poll_id=p.id WHERE p.id=? GROUP BY p.id,p.question,po.id,po.option_text,po.display_order ORDER BY po.display_order ASC,po.id ASC');
    foreach ($messages as &$message) {
        if ($message['type'] !== 'poll') continue;
        $meta = json_decode((string)$message['body'], true);
        $pollId = (int)($meta['poll_id'] ?? 0);
        if ($pollId <= 0) continue;
        $pollQuery->execute([$user['id'], $pollId]);
        $rows = $pollQuery->fetchAll();
        if (!$rows) continue;
        $options = [];
        foreach ($rows as $row) $options[] = ['id'=>(int)$row['option_id'],'text'=>$row['option_text'],'votes'=>(int)$row['votes'],'selected'=>(bool)$row['selected']];
        $message['poll_id'] = $pollId;
        $message['poll'] = ['id'=>$pollId,'question'=>$rows[0]['question'],'options'=>$options];
    }
    unset($message);
    out(['messages' => $messages]);
}

if ($method === 'POST') {
    $d = input();
    $chat = (int)($d['chat_id'] ?? 0);
    $type = (string)($d['type'] ?? 'text');
    $body = trim((string)($d['body'] ?? ''));
    $reply = (int)($d['reply_to_message_id'] ?? 0);
    if ($body === '' && $type === 'text') fail('Message is empty');

    $m = db()->prepare('SELECT c.retention_seconds FROM chats c JOIN chat_members cm ON cm.chat_id=c.id WHERE c.id=? AND cm.user_id=? AND cm.status="active"');
    $m->execute([$chat, $user['id']]);
    $row = $m->fetch();
    if (!$row) fail('Not a member', 403);
    if ($reply) {
        $r = db()->prepare('SELECT id FROM messages WHERE id=? AND chat_id=?');
        $r->execute([$reply, $chat]);
        if (!$r->fetch()) fail('Invalid reply target');
    }
    $expires = $row['retention_seconds'] ? gmdate('Y-m-d H:i:s', time() + (int)$row['retention_seconds']) : null;
    $createdAt = gmdate('Y-m-d H:i:s');
    $st = db()->prepare('INSERT INTO messages(chat_id,sender_id,type,body,reply_to_message_id,expires_at,created_at) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$chat,$user['id'],$type,$body,$reply ?: null,$expires,$createdAt]);
    $messageId = (int)db()->lastInsertId();

    $created = db()->prepare('SELECT m.id,m.chat_id,m.sender_id,m.type,m.body,m.reply_to_message_id,m.edit_count,m.edited_at,m.created_at,u.name AS sender_name,r.body AS reply_to_text,ru.name AS reply_to_sender_name FROM messages m JOIN users u ON u.id=m.sender_id LEFT JOIN messages r ON r.id=m.reply_to_message_id LEFT JOIN users ru ON ru.id=r.sender_id WHERE m.id=?');
    $created->execute([$messageId]);
    $message = $created->fetch();
    $message['attachments'] = [];
    out(['message' => [
        'id'=>(int)$message['id'],'chat_id'=>(int)$message['chat_id'],'sender_id'=>(int)$message['sender_id'],'sender_name'=>$message['sender_name'],
        'type'=>$message['type'],'body'=>$message['body'],'reply_to_message_id'=>$message['reply_to_message_id'] !== null ? (int)$message['reply_to_message_id'] : null,
        'reply_to_text'=>$message['reply_to_text'],'reply_to_sender_name'=>$message['reply_to_sender_name'],'created_at'=>$message['created_at'],'attachments'=>[]
    ]], 201);
}
fail('Method not allowed',405);
