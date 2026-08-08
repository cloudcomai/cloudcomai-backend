<?php
require __DIR__ . '/lib/bootstrap.php';
$pdo=db();
$pdo->exec('UPDATE messages SET deleted_for_everyone=1, body=NULL WHERE expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP() AND deleted_for_everyone=0');
$pdo->exec('UPDATE live_locations SET active=0 WHERE expires_at<=UTC_TIMESTAMP() AND active=1');
$pdo->exec('UPDATE stories SET deleted_at=UTC_TIMESTAMP() WHERE expires_at<=UTC_TIMESTAMP() AND deleted_at IS NULL');
$pdo->exec('UPDATE calls SET status="missed",updated_at=UTC_TIMESTAMP() WHERE status="ringing" AND expires_at<=UTC_TIMESTAMP()');
echo "Cleanup completed at ".gmdate('c').PHP_EOL;
