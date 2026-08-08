<?php
require __DIR__ . '/../lib/bootstrap.php';$user=auth_user();if($_SERVER['REQUEST_METHOD']!=='PATCH'&&$_SERVER['REQUEST_METHOD']!=='POST')fail('Method not allowed',405);
$d=input();$id=(int)($d['message_id']??0);$body=trim((string)($d['body']??''));if(!$id||$body==='')fail('Message and body required');
$st=db()->prepare('UPDATE messages SET body=?,edit_count=1,edited_at=UTC_TIMESTAMP() WHERE id=? AND sender_id=? AND edit_count=0 AND deleted_for_everyone=0 AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) AND created_at>UTC_TIMESTAMP()-INTERVAL 15 MINUTE');
$st->execute([$body,$id,$user['id']]);if($st->rowCount()!==1)fail('Message cannot be edited or was already edited',409);out(['message'=>'Message edited once']);
