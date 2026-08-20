<?php
require __DIR__ . '/../lib/bootstrap.php';

$user = auth_user();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = trim((string)($_GET['action'] ?? ''));

$types = ['Family Group','Friend Group','Fan Group','Study Group','College Group','Class Group','Department Group','Project Group','Club Group','Alumni Group','Workplace Group','Neighborhood Group','Event Group','Staff Group'];

if ($method === 'GET') {
    $st = $pdo->prepare('
        SELECT
            c.id,
            c.type,
            c.name,
            c.group_category,
            c.retention_seconds,
            c.created_at,
            MAX(m.created_at) AS last_message_at
        FROM chats c
        INNER JOIN chat_members cm ON cm.chat_id = c.id
        LEFT JOIN messages m ON m.chat_id = c.id
        WHERE c.type = "group"
          AND cm.user_id = ?
          AND cm.status = "active"
        GROUP BY c.id, c.type, c.name, c.group_category, c.retention_seconds, c.created_at
        ORDER BY COALESCE(MAX(m.created_at), c.created_at) DESC
    ');
    $st->execute([$user['id']]);
    out(['groups' => $st->fetchAll()]);
}

if ($method === 'POST' && $action === 'invite') {
    $chatId = (int)($_GET['id'] ?? 0);
    if ($chatId <= 0) fail('Group id is required');

    $owner = $pdo->prepare('SELECT id FROM chats WHERE id=? AND type="group" AND owner_id=? LIMIT 1');
    $owner->execute([$chatId, $user['id']]);
    if (!$owner->fetch()) fail('Only the group owner can generate an invite link', 403);

    try {
        $raw = random_token();
        $pdo->prepare('UPDATE group_invites SET active=0 WHERE chat_id=?')->execute([$chatId]);
        $pdo->prepare('INSERT INTO group_invites(chat_id,token_hash,created_by,active,created_at) VALUES(?,SHA2(?,256),?,1,UTC_TIMESTAMP())')
            ->execute([$chatId, $raw, $user['id']]);
        out(['invite_url' => ($config['app']['base_url'] ?? '') . '/join.php?token=' . urlencode($raw)]);
    } catch (Throwable $e) {
        error_log('groups.php invite error: ' . $e->getMessage());
        fail('Unable to generate invite link', 500);
    }
}

if ($method === 'POST') {
    $d = input();
    $name = trim((string)($d['name'] ?? ''));
    $type = (string)($d['group_category'] ?? $d['group_type'] ?? '');
    $retention = (int)($d['retention_seconds'] ?? 0);

    if ($name === '' || !in_array($type, $types, true)) {
        fail('Valid group name and category are required');
    }

    $allowed = [0, 86400, 604800, 1296000, 2592000, 7776000];
    if (!in_array($retention, $allowed, true)) {
        fail('Invalid retention policy');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO chats(type,name,group_category,owner_id,retention_seconds,created_at) VALUES("group",?,?,?,?,UTC_TIMESTAMP())');
        $st->execute([$name, $type, $user['id'], $retention]);
        $chatId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO chat_members(chat_id,user_id,role,status,joined_at) VALUES(?,?,"owner","active",UTC_TIMESTAMP())')
            ->execute([$chatId, $user['id']]);

        $raw = random_token();
        $pdo->prepare('INSERT INTO group_invites(chat_id,token_hash,created_by,active,created_at) VALUES(?,SHA2(?,256),?,1,UTC_TIMESTAMP())')
            ->execute([$chatId, $raw, $user['id']]);

        $pdo->commit();
        out([
            'group' => [
                'id' => $chatId,
                'type' => 'group',
                'name' => $name,
                'group_category' => $type,
                'retention_seconds' => $retention,
                'isGroup' => true
            ],
            'invite_url' => ($config['app']['base_url'] ?? '') . '/join.php?token=' . urlencode($raw)
        ], 201);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('groups.php POST error: ' . $e->getMessage());
        fail('Group creation failed', 500);
    }
}

if ($method === 'DELETE') {
    $chatId = (int)($_GET['id'] ?? 0);
    if ($chatId <= 0) fail('Group id is required');

    $owner = $pdo->prepare('SELECT id FROM chats WHERE id=? AND type="group" AND owner_id=? LIMIT 1');
    $owner->execute([$chatId, $user['id']]);
    if (!$owner->fetch()) fail('Only the group owner can delete this group', 403);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE chat_members SET status="removed" WHERE chat_id=?')->execute([$chatId]);
        $pdo->prepare('UPDATE group_invites SET active=0 WHERE chat_id=?')->execute([$chatId]);
        $pdo->commit();
        out(['message' => 'Group deleted', 'group_id' => $chatId]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('groups.php DELETE error: ' . $e->getMessage());
        fail('Group deletion failed', 500);
    }
}

fail('Method not allowed', 405);
