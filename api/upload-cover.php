<?php
declare(strict_types=1);

require_once __DIR__ . '/google-auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$user = getCurrentUser($conn);
if ($user === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

// CSRF check
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$submitted = $_POST['csrf_token'] ?? '';
$stored    = $_SESSION['profile_csrf_token'] ?? '';
if ($stored === '' || !is_string($submitted) || !hash_equals($stored, $submitted)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security validation failed.']);
    exit;
}

if (!isset($_FILES['cover']) || $_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit.',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload stopped by extension.',
    ];
    $code = $_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'error' => $uploadErrors[$code] ?? 'Upload failed.']);
    exit;
}

$tmpPath  = $_FILES['cover']['tmp_name'];
$fileSize = $_FILES['cover']['size'];

// Max 8 MB for cover photo
if ($fileSize > 8 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Cover photo must be 8 MB or smaller.']);
    exit;
}

// Validate MIME from actual file content
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpPath);
$allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

if (!array_key_exists($mimeType, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG and WebP are allowed for cover photos.']);
    exit;
}

$ext      = $allowed[$mimeType];
$userId   = (int) $user['id'];
$filename = 'cover_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$saveDir  = dirname(__DIR__) . '/img/covers/';
$savePath = $saveDir . $filename;

// Delete old cover if stored locally
if (!empty($user['cover_photo'])) {
    $oldPath = dirname(__DIR__) . '/' . ltrim($user['cover_photo'], '/');
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

if (!move_uploaded_file($tmpPath, $savePath)) {
    echo json_encode(['success' => false, 'error' => 'Could not save image. Please try again.']);
    exit;
}

$relPath = 'img/covers/' . $filename;
$stmt = $conn->prepare('UPDATE account SET cover_photo = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
if (!$stmt) {
    @unlink($savePath);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
    exit;
}
$stmt->bind_param('si', $relPath, $userId);
if (!$stmt->execute()) {
    @unlink($savePath);
    echo json_encode(['success' => false, 'error' => 'Could not update cover photo.']);
    exit;
}
$stmt->close();

echo json_encode([
    'success'   => true,
    'cover_url' => '../' . $relPath . '?v=' . time(),
]);
