<?php
require __DIR__ . '/../lib/bootstrap.php';

$user = auth_user();
$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');
$pdo = db();

if ($method !== 'POST') fail('Method not allowed', 405);
$d = input();

if ($action === 'vote') {
    $pollId = (int)($d['poll_id'] ?? 0);
    $optionId = (int)($d['option_id'] ?? 0);
    if ($pollId <= 0 || $optionId <= 0) fail('Poll and option are required');

    $membership = $pdo->prepare('SELECT p.chat_id FROM polls p INNER JOIN chat_members cm ON cm.chat_id=p.chat_id WHERE p.id=? AND cm.user_id=? AND cm.status="active" LIMIT 1');
    $membership->execute([$pollId, $user['id']]);
    $poll = $membership->fetch();
    if (!$poll) fail('Poll not found or access denied', 403);

    $option = $pdo->prepare('SELECT id FROM poll_options WHERE id=? AND poll_id=? LIMIT 1');
    $option->execute([$optionId, $pollId]);
    if (!$option->fetch()) fail('Invalid poll option');

    $existing = $pdo->prepare('SELECT option_id FROM poll_votes WHERE poll_id=? AND user_id=? ORDER BY created_at ASC LIMIT 1');
    $existing->execute([$pollId, $user['id']]);
    $previousVote = $existing->fetch();

    try {
        $pdo->beginTransaction();
        if ($previousVote) {
            $pdo->prepare('UPDATE poll_votes SET option_id=?, created_at=UTC_TIMESTAMP() WHERE poll_id=? AND user_id=?')->execute([$optionId, $pollId, $user['id']]);
        } else {
            $pdo->prepare('INSERT INTO poll_votes(poll_id,option_id,user_id,created_at) VALUES(?,?,?,UTC_TIMESTAMP())')->execute([$pollId, $optionId, $user['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('poll vote error: '.$e->getMessage());
        fail('Failed to save vote', 500);
    }

    $options = $pdo->prepare('SELECT po.id, po.option_text AS text, COUNT(pv.user_id) AS votes, MAX(CASE WHEN pv.user_id=? THEN 1 ELSE 0 END) AS selected FROM poll_options po LEFT JOIN poll_votes pv ON pv.option_id=po.id AND pv.poll_id=po.poll_id WHERE po.poll_id=? GROUP BY po.id,po.option_text,po.display_order ORDER BY po.display_order ASC,po.id ASC');
    $options->execute([$user['id'], $pollId]);
    out(['poll_id'=>$pollId,'options'=>array_map(static function($row){return ['id'=>(int)$row['id'],'text'=>$row['text'],'votes'=>(int)$row['votes'],'selected'=>(bool)$row['selected']];}, $options->fetchAll())]);
}

$chat = (int)($d['chat_id'] ?? 0);
$question = trim((string)($d['question'] ?? ''));
$options = $d['options'] ?? $d['choices'] ?? [];
if ((!is_array($options) || count($options) < 2) && isset($d['option_a'], $d['option_b'])) $options = [$d['option_a'], $d['option_b']];
if ($question === '' || !is_array($options)) fail('Invalid poll structure. Provide a question and at least 2 options.');

$cleanOptions = [];
foreach ($options as $option) {
    $value = trim((string)$option);
    if ($value !== '' && !in_array($value, $cleanOptions, true)) $cleanOptions[] = $value;
}
if (count($cleanOptions) < 2) fail('Invalid poll structure. Provide a question and at least 2 options.');

$membership = $pdo->prepare('SELECT c.retention_seconds FROM chats c INNER JOIN chat_members cm ON cm.chat_id=c.id WHERE c.id=? AND cm.user_id=? AND cm.status="active" LIMIT 1');
$membership->execute([$chat, $user['id']]);
$chatRow = $membership->fetch();
if (!$chatRow) fail('Not a member', 403);

$expiresAt = !empty($chatRow['retention_seconds']) ? gmdate('Y-m-d H:i:s', time() + (int)$chatRow['retention_seconds']) : null;

try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO polls(chat_id,creator_id,question,multiple_choice,anonymous,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')->execute([$chat,$user['id'],$question,!empty($d['multiple_choice'])?1:0,!empty($d['anonymous'])?1:0]);
    $pollId = (int)$pdo->lastInsertId();

    $st = $pdo->prepare('INSERT INTO poll_options(poll_id,option_text,display_order) VALUES(?,?,?)');
    foreach ($cleanOptions as $index=>$option) $st->execute([$pollId,$option,$index]);

    $messageBody = json_encode(['poll_id'=>$pollId], JSON_UNESCAPED_SLASHES);
    $messageInsert = $pdo->prepare('INSERT INTO messages(chat_id,sender_id,type,body,expires_at,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');
    $messageInsert->execute([$chat,$user['id'],'poll',$messageBody,$expiresAt]);
    $messageId = (int)$pdo->lastInsertId();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Poll creation failed: '.$e->getMessage());
    fail('Poll creation failed', 500);
}

$createdOptions = [];
$optionQuery = $pdo->prepare('SELECT id, option_text AS text, display_order FROM poll_options WHERE poll_id=? ORDER BY display_order ASC,id ASC');
$optionQuery->execute([$pollId]);
foreach ($optionQuery->fetchAll() as $row) $createdOptions[] = ['id'=>(int)$row['id'],'text'=>$row['text'],'votes'=>0,'selected'=>false];

$message = [
    'id' => $messageId,
    'chat_id' => $chat,
    'sender_id' => (int)$user['id'],
    'type' => 'poll',
    'body' => $messageBody,
    'poll_id' => $pollId,
    'poll' => ['id'=>$pollId,'question'=>$question,'options'=>$createdOptions]
];

out(['message'=>$message,'poll_id'=>$pollId],201);
