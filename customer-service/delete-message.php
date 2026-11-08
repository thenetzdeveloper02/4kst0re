<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/admin-auth.php';

if (!ensureAdminSecuritySchema($conn) || !adminStartSession()) {
    http_response_code(500);
    exit('Could not prepare the admin session.');
}
$currentAdmin = adminRequireLogin($conn, '../admin/index.php');
adminRequirePermission($currentAdmin, 'support');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !adminCsrfValid()) {
    http_response_code(403);
    exit('The form expired. Reload the page and try again.');
}

$messageId = filter_var($_POST['message_id'] ?? null, FILTER_VALIDATE_INT);
if (!$messageId || $messageId < 1 || !$conn->begin_transaction()) {
    header('Location: dashboard.php?status=error');
    exit;
}

$statement = $conn->prepare('DELETE FROM messages WHERE id = ?');
if (!$statement) {
    $conn->rollback();
    header('Location: dashboard.php?status=error');
    exit;
}
$statement->bind_param('i', $messageId);
$deleted = $statement->execute() && $statement->affected_rows === 1;
$statement->close();
$auditSaved = $deleted && adminWriteAudit($conn, 'customer_message_deleted', null, null, ['message_id' => (int) $messageId]);
if ($auditSaved && $conn->commit()) {
    header('Location: dashboard.php?status=deleted');
    exit;
}

$conn->rollback();
header('Location: dashboard.php?status=error');
exit;