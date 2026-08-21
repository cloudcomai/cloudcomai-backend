<?php
require __DIR__ . '/../lib/bootstrap.php';

$map = [
    'user' => 'users',
    'group' => 'groups'
];
$type = trim((string)($_GET['type'] ?? ''));
$id = (int)($_GET['id'] ?? 0);
if (!isset($map[$type]) || $id <= 0) { http_response_code(404); exit; }

$folder = dirname(__DIR__) . '/uploads/' . $map[$type];
$files = glob($folder . '/' . $id . '.*') ?: [];
$path = null;
foreach ($files as $candidate) {
    if (is_file($candidate)) { $path = $candidate; break; }
}
if (!$path) { http_response_code(404); exit; }

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=300, must-revalidate');
readfile($path);
exit;
