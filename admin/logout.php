<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/admin-auth.php';

if (!ensureAdminSecuritySchema($conn) || !adminStartSession()) {
    http_response_code(500);
    exit('Could not prepare the admin session.');
}
adminRequireLogin($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
if (!adminCsrfValid()) {
    http_response_code(403);
    exit('The sign-out form expired. Reload the page and try again.');
}
adminWriteAudit($conn, 'logout');
adminDestroySession($conn);
header('Location: index.php');
exit;
