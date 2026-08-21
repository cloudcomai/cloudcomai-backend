<?php
require __DIR__ . '/../lib/bootstrap.php';

$allowed_origins = ['https://cloudcomai.com', 'http://localhost:5173'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$user = auth_user();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $chat_id = (int)($_GET['chat_id'] ?? 0);
    $m = db()->prepare('SELECT role, status FROM chat_members WHERE chat_id = ? AND (user_id = ? OR user_id = ?)');
    $m->execute([$chat_id, $user['id'] ?? 0, $user['user_id'] ?? '']);
    $db_row = $m->fetch();
    if (!$db_row || $db_row['status'] !== 'active') fail('Unauthorized access to group data.', 403);

    $st = db()->prepare('SELECT cm.user_id, cm.role, u.name, u.user_id as username FROM chat_members cm JOIN users u ON (u.id = cm.user_id OR u.user_id = cm.user_id) WHERE cm.chat_id=? AND cm.status="active"');
    $st->execute([$chat_id]);
    out(['members' => $st->fetchAll()]);
}

if ($method === 'POST') {
    $d = input();
    $chat_id = (int)($d['chat_id'] ?? 0);
    $target_user_id = (int)($d['user_id'] ?? 0);
    $action = (string)($d['action'] ?? 'add');
    $currentSessionUserId = $user['id'] ?? $user['user_id'] ?? 0;

    $m = db()->prepare('SELECT role, status FROM chat_members WHERE chat_id=? AND user_id=? AND status="active"');
    $m->execute([$chat_id, $currentSessionUserId]);
    $currentUserRole = $m->fetch();
    if (!$currentUserRole) fail('Unauthorized access to group settings', 403);

    if ($action === 'add') {
        $check = db()->prepare('SELECT role, status FROM chat_members WHERE chat_id=? AND user_id=?');
        $check->execute([$chat_id, $target_user_id]);
        $existingRow = $check->fetch();
        if ($existingRow) {
            $st = db()->prepare('UPDATE chat_members SET status="active", role="member" WHERE chat_id=? AND user_id=?');
            $st->execute([$chat_id, $target_user_id]);
        } else {
            $st = db()->prepare('INSERT INTO chat_members (chat_id, user_id, role, status) VALUES (?, ?, "member", "active")');
            $st->execute([$chat_id, $target_user_id]);
        }
        out(['status' => 'ok', 'message' => 'Member added successfully']);
    }

    if ($action === 'remove') {
        if (($currentUserRole['role'] !== 'admin' && $currentUserRole['role'] !== 'owner') && $currentSessionUserId != $target_user_id) {
            fail('Only administrators can remove members from this group chat.', 403);
        }

        // Owner protection: ownership cannot be removed by another member/admin.
        $target = db()->prepare('SELECT role, status FROM chat_members WHERE chat_id=? AND user_id=?');
        $target->execute([$chat_id, $target_user_id]);
        $targetRow = $target->fetch();
        if ($targetRow && $targetRow['role'] === 'owner' && (string)$currentSessionUserId !== (string)$target_user_id) {
            fail('The group owner cannot be removed. Transfer ownership first.', 403);
        }

        $st = db()->prepare('UPDATE chat_members SET status="removed" WHERE chat_id=? AND user_id=?');
        $st->execute([$chat_id, $target_user_id]);
        out(['status' => 'ok', 'message' => 'Member removed successfully']);
    }
}
fail('Method not allowed', 405);
