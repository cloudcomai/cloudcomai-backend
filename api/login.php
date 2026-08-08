<?php
require __DIR__ . '/../lib/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed',405);
$d=input(); $identifier=trim((string)($d['identifier']??'')); $password=(string)($d['password']??'');
if ($identifier===''||$password==='') fail('Identifier and password are required');
$st=db()->prepare('SELECT * FROM users WHERE email=? OR mobile=? OR user_id=? LIMIT 1');
$st->execute([strtolower($identifier),$identifier,strtolower($identifier)]); $u=$st->fetch();
if(!$u||!password_verify($password,$u['password_hash'])) fail('Invalid credentials',401);
if($u['account_status']!=='active') fail('Account unavailable',403);
out(['token'=>token_for((int)$u['id']),'user'=>['id'=>(int)$u['id'],'name'=>$u['name'],'user_id'=>$u['user_id'],'email'=>$u['email'],'mobile'=>$u['mobile'],'gender'=>$u['gender']]]);
