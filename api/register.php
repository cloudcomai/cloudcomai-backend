<?php
require __DIR__ . '/../lib/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
$d = input();
$name = trim((string)($d['name'] ?? ''));
$email = strtolower(trim((string)($d['email'] ?? '')));
$mobile = trim((string)($d['mobile'] ?? ''));
$userId = strtolower(trim((string)($d['user_id'] ?? '')));
$password = (string)($d['password'] ?? '');
$dob = (string)($d['dob'] ?? '');
$gender = (string)($d['gender'] ?? '');
if ($name === '' || strlen($password) < 8) fail('Name and a password of at least 8 characters are required');
if (!$email && !$mobile && !$userId) fail('Email, mobile or User ID is required');
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email');
if ($userId && !preg_match('/^[a-z0-9_]{3,30}$/', $userId)) fail('User ID must contain 3-30 letters, numbers or underscores');
if (!in_array($gender, ['Male','Female'], true)) fail('Gender must be Male or Female');
if (age_from_dob($dob) < 18) fail('CloudComAI is available only to users aged 18 or older');
try {
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare('INSERT INTO users (user_id,name,email,mobile,password_hash,dob,gender,email_verified,mobile_verified,account_status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
    $st->execute([$userId ?: null,$name,$email ?: null,$mobile ?: null,password_hash($password,PASSWORD_DEFAULT),$dob,$gender,$email?0:1,$mobile?0:1,'active']);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
    out(['token'=>token_for($id),'user'=>['id'=>$id,'name'=>$name,'user_id'=>$userId,'email'=>$email,'mobile'=>$mobile,'gender'=>$gender]],201);
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    if ($e->getCode()==='23000') fail('Email, mobile number or User ID is already registered',409);
    fail('Registration failed',500);
}
