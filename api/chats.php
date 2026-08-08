<?php

require __DIR__ . '/../lib/bootstrap.php';

$user = auth_user();

$pdo = db();

$sql = '
    SELECT
        c.id,
        c.type,
        c.name,
        c.group_category,
        c.retention_seconds,
        c.created_at,
        MAX(m.created_at) AS last_message_at
    FROM chats c
    INNER JOIN chat_members cm
        ON cm.chat_id = c.id
    LEFT JOIN messages m
        ON m.chat_id = c.id
    WHERE cm.user_id = ?
      AND cm.status = "active"
    GROUP BY
        c.id,
        c.type,
        c.name,
        c.group_category,
        c.retention_seconds,
        c.created_at
    ORDER BY
        COALESCE(MAX(m.created_at), c.created_at) DESC
';

try {

    $st = $pdo->prepare($sql);

    $st->execute([
        $user['id']
    ]);

    $chats = $st->fetchAll();

    out([
        'chats' => $chats
    ]);

} catch (Throwable $e) {

    error_log(
        'chats.php error: ' . $e->getMessage()
    );

    fail(
        'Unable to load chats',
        500
    );
}