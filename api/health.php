<?php
require __DIR__ . '/../lib/bootstrap.php';
try { db()->query('SELECT 1'); out(['status'=>'ok','time'=>gmdate('c')]); } catch(Throwable $e){ fail('Database unavailable',503); }
