<?php

require __DIR__ . '/../lib/bootstrap.php';

$user = auth_user();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $type = trim((string)($_GET['type'] ?? ''));

    $sql = '
        SELECT
            c.id,
            c.type,
            c.name,
            c.group_category,
            c.owner_id,
            c.retention_seconds,
            c.created_at,
            MAX(m.created_at) AS last_message_at
        FROM chats c
        INNER JOIN chat_members cm ON cm.chat_id = c.id
        LEFT JOIN messages m ON m.chat_id = c.id
        WHERE cm.user_id = ?
          AND cm.status = "active"
    ';

    $params = [$user['id']];
    if ($type !== '') {
        if (!in_array($type, ['private', 'group', 'public', 'community'], true)) fail('Invalid chat type');
        $sql .= ' AND c.type = ?';
        $params[] = $type;
    }

    $sql .= '
        GROUP BY c.id, c.type, c.name, c.group_category, c.owner_id, c.retention_seconds, c.created_at
        ORDER BY COALESCE(MAX(m.created_at), c.created_at) DESC
    ';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $chats = $st->fetchAll();

        foreach ($chats as &$chat) {
            $chat['id'] = (int)$chat['id'];
            $chat['owner_id'] = $chat['owner_id'] !== null ? (int)$chat['owner_id'] : null;
            $chat['isGroup'] = $chat['type'] === 'group';

            if ($chat['type'] === 'private') {
                $other = $pdo->prepare('
                    SELECT u.id, u.name, u.user_id, u.updated_at,
                           CASE WHEN u.updated_at IS NOT NULL AND u.updated_at >= UTC_TIMESTAMP() - INTERVAL 90 SECOND THEN 1 ELSE 0 END AS online
                    FROM chat_members cm
                    INNER JOIN users u ON u.id = cm.user_id
                    WHERE cm.chat_id = ?
                      AND cm.user_id <> ?
                      AND cm.status = "active"
                    ORDER BY u.id ASC
                    LIMIT 1
                ');
                $other->execute([$chat['id'], $user['id']]);
                $participant = $other->fetch();
                if ($participant) {
                    $chat['other_user_id'] = (int)$participant['id'];
                    $chat['other_user_name'] = $participant['name'];
                    $chat['other_user_id_text'] = $participant['user_id'];
                    $chat['name'] = $participant['name'];
                    $chat['online'] = (bool)$participant['online'];
                    $chat['other_user_online'] = (bool)$participant['online'];
                }
            }
        }
        unset($chat);

        out(['chats' => $chats]);
    } catch (Throwable $e) {
        error_log('chats.php GET error: ' . $e->getMessage());
        fail('Unable to load chats', 500);
    }
}

if ($method === 'POST') {
    $d = input();
    $type = (string)($d['type'] ?? '');
    if ($type !== 'private') fail('Unsupported chat creation type');

    $targetUserId = (int)($d['target_user_id'] ?? 0);
    if ($targetUserId <= 0 || $targetUserId === (int)$user['id']) fail('A valid target user is required');

    try {
        $target = $pdo->prepare('SELECT id, name, user_id, account_status, updated_at, CASE WHEN updated_at IS NOT NULL AND updated_at >= UTC_TIMESTAMP() - INTERVAL 90 SECOND THEN 1 ELSE 0 END AS online FROM users WHERE id=? LIMIT 1');
        $target->execute([$targetUserId]);
        $targetUser = $target->fetch();
        if (!$targetUser || $targetUser['account_status'] !== 'active') fail('Target user is unavailable', 404);

        $existing = $pdo->prepare('
            SELECT c.id
            FROM chats c
            INNER JOIN chat_members a ON a.chat_id=c.id AND a.user_id=? AND a.status="active"
            INNER JOIN chat_members b ON b.chat_id=c.id AND b.user_id=? AND b.status="active"
            WHERE c.type="private"
            LIMIT 1
        ');
        $existing->execute([$user['id'], $targetUserId]);
        $chat = $existing->fetch();

        if (!$chat) {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO chats(type,name,owner_id,created_at) VALUES("private",NULL,?,UTC_TIMESTAMP())');
            $insert->execute([$user['id']]);
            $chatId = (int)$pdo->lastInsertId();
            $member = $pdo->prepare('INSERT INTO chat_members(chat_id,user_id,role,status,joined_at) VALUES(?,?,"member","active",UTC_TIMESTAMP())');
            $member->execute([$chatId, $user['id']]);
            $member->execute([$chatId, $targetUserId]);
            $pdo->commit();
        } else {
            $chatId = (int)$chat['id'];
        }

        out(['chat' => [
            'id' => $chatId,
            'type' => 'private',
            'name' => $targetUser['name'],
            'owner_id' => (int)$user['id'],
            'other_user_id' => (int)$targetUser['id'],
            'other_user_name' => $targetUser['name'],
            'other_user_id_text' => $targetUser['user_id'],
            'online' => (bool)$targetUser['online'],
            'other_user_online' => (bool)$targetUser['online']
        ]], $chat ? 200 : 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('chats.php POST error: ' . $e->getMessage());
        fail('Unable to create private chat', 500);
    }
}

fail('Method not allowed', 405);
