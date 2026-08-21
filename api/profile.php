<?php
require __DIR__ . '/../lib/bootstrap.php';

$user = auth_user();
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') fail('Method not allowed', 405);

$current = db()->prepare('SELECT dob, gender FROM users WHERE id=? LIMIT 1');
$current->execute([$user['id']]);
$currentUser = $current->fetch();

$d = input();
$name = trim((string)($d['name'] ?? $user['name']));
$dob = trim((string)($d['dob'] ?? ($currentUser['dob'] ?? '')));
$gender = (string)($d['gender'] ?? ($currentUser['gender'] ?? ''));
if ($name === '') fail('Name is required');
if ($dob === '' || age_from_dob($dob) < 18) fail('User must be at least 18 years old');
if (!in_array($gender, ['Male','Female'], true)) fail('Gender must be Male or Female');

$st = db()->prepare('UPDATE users SET name=?, dob=?, gender=?, updated_at=UTC_TIMESTAMP() WHERE id=?');
$st->execute([$name, $dob, $gender, $user['id']]);

out(['user'=>[
    'id'=>(int)$user['id'],
    'name'=>$name,
    'user_id'=>$user['user_id'],
    'email'=>$user['email'],
    'mobile'=>$user['mobile'],
    'gender'=>$gender,
    'dob'=>$dob
]]);
