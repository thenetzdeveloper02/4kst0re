<?php
declare(strict_types=1);

require_once __DIR__ . '/google-auth.php';

header('Content-Type: application/json');

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// Must be logged in
$user = getCurrentUser($conn);
if ($user === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

// CSRF check
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$submitted = $_POST['csrf_token'] ?? '';
$stored    = $_SESSION['profile_csrf_token'] ?? '';
if ($stored === '' || !is_string($submitted) || !hash_equals($stored, $submitted)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security validation failed.']);
    exit;
}

// File present?
if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload stopped by extension.',
    ];
    $code = $_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'error' => $uploadErrors[$code] ?? 'Upload failed.']);
    exit;
}

$tmpPath  = $_FILES['avatar']['tmp_name'];
$origName = $_FILES['avatar']['name'];
$fileSize = $_FILES['avatar']['size'];

// Max 5 MB
if ($fileSize > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Image must be 5 MB or smaller.']);
    exit;
}

// Validate MIME type from actual file content (not from $_FILES['type'])
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpPath);
$allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

if (!array_key_exists($mimeType, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, GIF and WebP images are allowed.']);
    exit;
}

$ext      = $allowed[$mimeType];
$userId   = (int) $user['id'];
$filename = 'avatar_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$saveDir  = dirname(__DIR__) . '/img/avatars/';
$savePath = $saveDir . $filename;

// Delete old local avatar if exists
if (!empty($user['local_avatar'])) {
    $oldPath = $saveDir . basename($user['local_avatar']);
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

if (!move_uploaded_file($tmpPath, $savePath)) {
    echo json_encode(['success' => false, 'error' => 'Could not save image. Please try again.']);
    exit;
}

// Save relative path to DB
$relPath = 'img/avatars/' . $filename;
$stmt = $conn->prepare('UPDATE account SET local_avatar = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
if (!$stmt) {
    @unlink($savePath);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
    exit;
}
$stmt->bind_param('si', $relPath, $userId);
if (!$stmt->execute()) {
    @unlink($savePath);
    echo json_encode(['success' => false, 'error' => 'Could not update profile picture.']);
    exit;
}
$stmt->close();

echo json_encode([
    'success'    => true,
    'avatar_url' => '../' . $relPath . '?v=' . time(),
]);
