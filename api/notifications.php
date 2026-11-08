<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/db.php';

function notificationsRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($decoded)) {
        notificationsRespond(400, ['success' => false, 'error' => 'Invalid request body.']);
    }
    $input = $decoded;
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    notificationsRespond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$roleValue = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($input['role'] ?? '') : ($_GET['role'] ?? '');
$role = is_string($roleValue) ? $roleValue : '';
if (!in_array($role, ['admin', 'user'], true)) {
    notificationsRespond(400, ['success' => false, 'error' => 'Invalid notification audience.']);
}
if (!ensureStoreManagementSchema($conn)) {
    notificationsRespond(500, ['success' => false, 'error' => 'Notifications are temporarily unavailable.']);
}

$recipientKey = '';
$accountId = 0;
$csrfToken = '';
if ($role === 'admin') {
    require_once __DIR__ . '/admin-auth.php';
    if (!ensureAdminSecuritySchema($conn) || !adminStartSession()) {
        notificationsRespond(500, ['success' => false, 'error' => 'Admin notifications are temporarily unavailable.']);
    }
    if (adminCurrentUser($conn) === null) {
        notificationsRespond(401, ['success' => false, 'error' => 'Admin sign-in is required.']);
    }
    $csrfToken = adminCsrfToken();
} else {
    require_once __DIR__ . '/google-auth.php';
    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
        notificationsRespond(500, ['success' => false, 'error' => 'Notifications are temporarily unavailable.']);
    }
    $user = getCurrentUser($conn);
    if ($user !== null) {
        $accountId = (int) $user['id'];
        $recipientKey = hash('sha256', 'account:' . $accountId);
    } else {
        $guestToken = $_COOKIE['store_notification_guest'] ?? '';
        if (!is_string($guestToken) || preg_match('/^[a-f0-9]{64}$/D', $guestToken) !== 1) {
            $guestToken = bin2hex(random_bytes(32));
            setcookie('store_notification_guest', $guestToken, [
                'expires' => time() + 31536000,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $recipientKey = hash('sha256', 'guest:' . $guestToken);
    }

    if (empty($_SESSION['notification_csrf_token']) || !is_string($_SESSION['notification_csrf_token'])) {
        $_SESSION['notification_csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['notification_csrf_token'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($submittedToken) || $csrfToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        notificationsRespond(403, ['success' => false, 'error' => 'The request expired. Refresh the page and try again.']);
    }

    $action = is_string($input['action'] ?? null) ? $input['action'] : '';
    if ($role === 'admin') {
        if ($action === 'mark_all_read') {
            $saved = $conn->query('UPDATE admin_notifications SET read_at = NOW() WHERE read_at IS NULL');
        } elseif ($action === 'mark_read') {
            $notificationId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            $statement = $conn->prepare('UPDATE admin_notifications SET read_at = NOW() WHERE id = ? AND read_at IS NULL');
            if (!$statement || !$notificationId || $notificationId < 1) {
                notificationsRespond(400, ['success' => false, 'error' => 'Invalid notification.']);
            }
            $statement->bind_param('i', $notificationId);
            $saved = $statement->execute();
            if (!$saved) {
                error_log('Could not mark admin notification read: ' . $statement->error);
            }
            $statement->close();
        } else {
            notificationsRespond(400, ['success' => false, 'error' => 'Unknown notification action.']);
        }
    } else {
        if ($action === 'mark_all_read') {
            $statement = $conn->prepare('INSERT IGNORE INTO store_announcement_reads (announcement_id, recipient_key) SELECT id, ? FROM store_announcements WHERE is_active = 1 AND (target_account_id IS NULL OR target_account_id = ?)');
            if (!$statement) {
                error_log('Could not prepare mark-all store announcements read: ' . $conn->error);
                notificationsRespond(500, ['success' => false, 'error' => 'Could not update notifications.']);
            }
            $statement->bind_param('si', $recipientKey, $accountId);
            $saved = $statement->execute();
            if (!$saved) {
                error_log('Could not mark all store announcements read: ' . $statement->error);
            }
            $statement->close();
        } elseif ($action === 'mark_read') {
            $notificationId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$notificationId || $notificationId < 1) {
                notificationsRespond(400, ['success' => false, 'error' => 'Invalid notification.']);
            }
            $statement = $conn->prepare('INSERT IGNORE INTO store_announcement_reads (announcement_id, recipient_key) SELECT id, ? FROM store_announcements WHERE id = ? AND is_active = 1 AND (target_account_id IS NULL OR target_account_id = ?)');
            if (!$statement) {
                error_log('Could not prepare store announcement read update: ' . $conn->error);
                notificationsRespond(500, ['success' => false, 'error' => 'Could not update the notification.']);
            }
            $statement->bind_param('sii', $recipientKey, $notificationId, $accountId);
            $saved = $statement->execute();
            if (!$saved) {
                error_log('Could not mark store announcement read: ' . $statement->error);
            }
            $statement->close();
        } else {
            notificationsRespond(400, ['success' => false, 'error' => 'Unknown notification action.']);
        }
    }

    if (!$saved) {
        notificationsRespond(500, ['success' => false, 'error' => 'Could not update notifications.']);
    }
}

if ($role === 'admin') {
    $unreadResult = $conn->query('SELECT COUNT(*) AS unread FROM admin_notifications WHERE read_at IS NULL');
    if (!$unreadResult) {
        error_log('Could not count unread admin notifications: ' . $conn->error);
        notificationsRespond(500, ['success' => false, 'error' => 'Could not load notifications.']);
    }
    $unreadCount = (int) $unreadResult->fetch_assoc()['unread'];
    $result = $conn->query('SELECT id, notification_type, title, message, target_url, created_at, read_at FROM admin_notifications ORDER BY created_at DESC, id DESC LIMIT 10');
} else {
    $countStatement = $conn->prepare('SELECT COUNT(*) AS unread FROM store_announcements a LEFT JOIN store_announcement_reads r ON r.announcement_id = a.id AND r.recipient_key = ? WHERE a.is_active = 1 AND (a.target_account_id IS NULL OR a.target_account_id = ?) AND r.announcement_id IS NULL');
    if (!$countStatement) {
        error_log('Could not prepare unread store announcement count: ' . $conn->error);
        notificationsRespond(500, ['success' => false, 'error' => 'Could not load notifications.']);
    }
    $countStatement->bind_param('si', $recipientKey, $accountId);
    if (!$countStatement->execute()) {
        error_log('Could not execute unread store announcement count: ' . $countStatement->error);
        $countStatement->close();
        notificationsRespond(500, ['success' => false, 'error' => 'Could not load notifications.']);
    }
    $unreadCount = (int) $countStatement->get_result()->fetch_assoc()['unread'];
    $countStatement->close();
    $statement = $conn->prepare('SELECT a.id, a.title, a.message, a.created_at, r.read_at FROM store_announcements a LEFT JOIN store_announcement_reads r ON r.announcement_id = a.id AND r.recipient_key = ? WHERE a.is_active = 1 AND (a.target_account_id IS NULL OR a.target_account_id = ?) ORDER BY a.created_at DESC, a.id DESC LIMIT 10');
    if (!$statement) {
        error_log('Could not prepare store announcements list: ' . $conn->error);
        notificationsRespond(500, ['success' => false, 'error' => 'Could not load notifications.']);
    }
    $statement->bind_param('si', $recipientKey, $accountId);
    if (!$statement->execute()) {
        error_log('Could not execute store announcements list: ' . $statement->error);
        $statement->close();
        notificationsRespond(500, ['success' => false, 'error' => 'Could not load notifications.']);
    }
    $result = $statement->get_result();
}

if (!$result) {
    error_log('Could not load notifications: ' . $conn->error);
    notificationsRespond(500, ['success' => false, 'error' => 'Could not load notifications.']);
}

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id' => (int) $row['id'],
        'type' => (string) ($row['notification_type'] ?? 'announcement'),
        'title' => (string) $row['title'],
        'message' => (string) $row['message'],
        'target_url' => $role === 'admin' && is_string($row['target_url'] ?? null) ? $row['target_url'] : null,
        'created_at' => (string) $row['created_at'],
        'read' => $row['read_at'] !== null,
    ];
}
if ($role === 'user') {
    $statement->close();
}

notificationsRespond(200, [
    'success' => true,
    'unread_count' => $unreadCount,
    'notifications' => $notifications,
]);
