<?php
require __DIR__ . '/../lib/bootstrap.php';
$user=auth_user(); $method=$_SERVER['REQUEST_METHOD'];
if($method==='GET'){
  $chat=(int)($_GET['chat_id']??0); $after=(int)($_GET['after_id']??0);
  $m=db()->prepare('SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=? AND status="active"');$m->execute([$chat,$user['id']]);if(!$m->fetch()) fail('Not a member',403);
  $st=db()->prepare('SELECT m.id,m.chat_id,m.sender_id,m.type,m.body,m.reply_to_message_id,m.edit_count,m.edited_at,m.created_at,u.name sender_name FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.chat_id=? AND m.id>? AND m.deleted_for_everyone=0 AND (m.expires_at IS NULL OR m.expires_at>UTC_TIMESTAMP()) ORDER BY m.id ASC LIMIT 200');
  $st->execute([$chat,$after]);out(['messages'=>$st->fetchAll()]);
}
if($method==='POST'){
  $d=input();$chat=(int)($d['chat_id']??0);$type=(string)($d['type']??'text');$body=trim((string)($d['body']??''));$reply=(int)($d['reply_to_message_id']??0);
  if($body===''&&$type==='text') fail('Message is empty');
  $m=db()->prepare('SELECT c.retention_seconds FROM chats c JOIN chat_members cm ON cm.chat_id=c.id WHERE c.id=? AND cm.user_id=? AND cm.status="active"');$m->execute([$chat,$user['id']]);$row=$m->fetch();if(!$row) fail('Not a member',403);
  if($reply){$r=db()->prepare('SELECT id FROM messages WHERE id=? AND chat_id=?');$r->execute([$reply,$chat]);if(!$r->fetch()) fail('Invalid reply target');}
  $expires=$row['retention_seconds']?gmdate('Y-m-d H:i:s',time()+(int)$row['retention_seconds']):null;
  $st=db()->prepare('INSERT INTO messages(chat_id,sender_id,type,body,reply_to_message_id,expires_at,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())');$st->execute([$chat,$user['id'],$type,$body,$reply?:null,$expires]);
  out(['message'=>['id'=>(int)db()->lastInsertId(),'chat_id'=>$chat,'sender_id'=>(int)$user['id'],'type'=>$type,'body'=>$body,'reply_to_message_id'=>$reply?:null]],201);
}
fail('Method not allowed',405);
