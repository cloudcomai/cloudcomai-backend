<?php
require __DIR__ . '/../lib/bootstrap.php';

$user = auth_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$d = input();
$chat = (int)($d['chat_id'] ?? 0);
$question = trim((string)($d['question'] ?? ''));

$options = $d['options'] ?? null;
if (!is_array($options)) {
    $options = $d['choices'] ?? null;
}
if (!is_array($options)) {
    $options = [];
}

// Backward-compatible fallback for clients that send two explicit option fields.
if (count($options) < 2) {
    $optionA = trim((string)($d['option_a'] ?? ''));
    $optionB = trim((string)($d['option_b'] ?? ''));
    $fallback = array_values(array_filter([$optionA, $optionB], static fn($value) => $value !== ''));
    if (count($fallback) >= 2) {
        $options = $fallback;
    }
}

$cleanOptions = [];
foreach ($options as $option) {
    $value = trim((string)$option);
    if ($value !== '') {
        $cleanOptions[] = $value;
    }
}

if ($question === '' || count($cleanOptions) < 2) {
    fail('Invalid poll structure. Provide a question and at least 2 options.');
}

$m = db()->prepare('SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=? AND status="active"');
$m->execute([$chat, $user['id']]);
if (!$m->fetch()) {
    fail('Not a member', 403);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO polls(chat_id,creator_id,question,multiple_choice,anonymous,created_at)
         VALUES(?,?,?,?,?,UTC_TIMESTAMP())'
    )->execute([
        $chat,
        $user['id'],
        $question,
        !empty($d['multiple_choice']) ? 1 : 0,
        !empty($d['anonymous']) ? 1 : 0
    ]);

    $pollId = (int)$pdo->lastInsertId();
    $st = $pdo->prepare(
        'INSERT INTO poll_options(poll_id,option_text,display_order) VALUES(?,?,?)'
    );

    foreach ($cleanOptions as $index => $option) {
        $st->execute([$pollId, $option, $index]);
    }

    $pdo->commit();

    out([
        'poll_id' => $pollId,
        'question' => $question,
        'options' => $cleanOptions
    ], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('polls.php creation error: ' . $e->getMessage());
    fail('Poll creation failed', 500);
}
