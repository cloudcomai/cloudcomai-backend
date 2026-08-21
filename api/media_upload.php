<?php
require __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);

$user = auth_user();
$type = trim((string)($_POST['type'] ?? ''));
$id = (int)($_POST['id'] ?? 0);
$file = $_FILES['image'] ?? null;

if (!in_array($type, ['user', 'group'], true)) fail('Invalid image target');
if ($id <= 0) fail('Invalid image target id');
if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) fail('Image upload failed');
if ((int)$file['size'] > 2 * 1024 * 1024) fail('Image must be 2 MB or smaller');

if ($type === 'user' && $id !== (int)$user['id']) {
    fail('You can only update your own profile image', 403);
}

if ($type === 'group') {
    $member = db()->prepare('SELECT role FROM chat_members WHERE chat_id=? AND user_id=? AND status="active" LIMIT 1');
    $member->execute([$id, $user['id']]);
    $row = $member->fetch();
    if (!$row || !in_array($row['role'], ['owner', 'admin'], true)) {
        fail('Only group owners or admins can update the group image', 403);
    }

    $group = db()->prepare('SELECT id FROM chats WHERE id=? AND type="group" LIMIT 1');
    $group->execute([$id]);
    if (!$group->fetch()) fail('Group not found', 404);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp'
];
if (!isset($extensions[$mime])) fail('Only JPG, PNG and WebP images are supported');

$imageInfo = @getimagesize($file['tmp_name']);
if (!$imageInfo || empty($imageInfo[0]) || empty($imageInfo[1])) {
    fail('Unable to read image dimensions');
}

$maxDimension = 1024;
if ((int)$imageInfo[0] > $maxDimension || (int)$imageInfo[1] > $maxDimension) {
    fail('Image dimensions must not exceed 1024 x 1024 pixels');
}

$folder = dirname(__DIR__) . '/uploads/' . ($type === 'user' ? 'users' : 'groups');
if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
    fail('Unable to prepare image storage', 500);
}

foreach (glob($folder . '/' . $id . '.*') ?: [] as $oldFile) {
    if (is_file($oldFile)) @unlink($oldFile);
}

$filename = $id . '.' . $extensions[$mime];
$destination = $folder . '/' . $filename;
if (!move_uploaded_file($file['tmp_name'], $destination)) fail('Unable to save uploaded image', 500);

$baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
$imageUrl = $baseUrl . '/media.php?type=' . urlencode($type) . '&id=' . $id;

out([
    'type' => $type,
    'id' => $id,
    'image_url' => $imageUrl,
    'width' => (int)$imageInfo[0],
    'height' => (int)$imageInfo[1],
    'updated_at' => filemtime($destination) ?: time()
]);
