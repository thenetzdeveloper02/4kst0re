<?php
declare(strict_types=1);

/**
 * google-bridge.php
 * Opened by google-callback.php after a successful Google login for an admin email.
 * 1. Reads the one-time bridge token from the default PHP session.
 * 2. Validates it against account_sessions in the DB.
 * 3. Opens the ecommerce_admin session and populates it.
 * 4. Redirects to admin/index.php.
 */

require_once __DIR__ . '/../api/admin-auth.php';

$urlToken = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';

if ($urlToken === '') {
    header('Location: index.php');
    exit;
}

// ── Step 1: Read the default (user) session to get pending admin credentials ──
ini_set('session.use_strict_mode', '1');
// Use default session name (PHPSESSID) to read what google-callback.php wrote
session_name('PHPSESSID');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$pendingAdminId       = isset($_SESSION['pending_admin_id'])       ? (int) $_SESSION['pending_admin_id']          : 0;
$pendingAdminUsername = isset($_SESSION['pending_admin_username'])  ? (string) $_SESSION['pending_admin_username'] : '';
$pendingToken         = isset($_SESSION['pending_admin_token'])     ? (string) $_SESSION['pending_admin_token']    : '';

// Clear pending data right away
unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'], $_SESSION['pending_admin_token']);
session_write_close();

// ── Step 2: Validate – token in URL must match token in session ───────────────
if (
    $pendingAdminId === 0
    || $pendingToken === ''
    || !hash_equals($pendingToken, $urlToken)
) {
    header('Location: index.php?error=bridge_invalid');
    exit;
}

// ── Step 3: Double-check token exists in DB and is not expired ────────────────
$dbStmt = $conn->prepare(
    "SELECT id FROM account_sessions WHERE session_token = ? AND expires_at > NOW() LIMIT 1"
);
if (!$dbStmt) {
    header('Location: index.php?error=bridge_db_error');
    exit;
}
$dbStmt->bind_param('s', $urlToken);
$dbStmt->execute();
$dbRow = $dbStmt->get_result()->fetch_assoc();
$dbStmt->close();

if (!$dbRow) {
    header('Location: index.php?error=token_expired');
    exit;
}

// Delete used token
$delStmt = $conn->prepare("DELETE FROM account_sessions WHERE session_token = ?");
if ($delStmt) {
    $delStmt->bind_param('s', $urlToken);
    $delStmt->execute();
    $delStmt->close();
}

// ── Step 4: Verify admin still exists and is active ───────────────────────────
$adminStmt = $conn->prepare(
    "SELECT id, username FROM admins WHERE id = ? AND is_active = 1 LIMIT 1"
);
if (!$adminStmt) {
    header('Location: index.php?error=bridge_db_error');
    exit;
}
$adminStmt->bind_param('i', $pendingAdminId);
$adminStmt->execute();
$adminRecord = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

if (!$adminRecord) {
    header('Location: index.php?error=admin_not_found');
    exit;
}

// ── Step 5: Open the ecommerce_admin session and populate it ─────────────────
if (!adminStartSession()) {
    header('Location: index.php?error=session_error');
    exit;
}

session_regenerate_id(true);

$_SESSION['admin_id']         = (int) $adminRecord['id'];
$_SESSION['admin_username']   = (string) $adminRecord['username'];
$_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));

if (!adminRegisterSession($conn, (int) $adminRecord['id'])) {
    session_destroy();
    header('Location: index.php?error=session_register_error');
    exit;
}

adminWriteAudit($conn, 'google_login');

// ── All good — go to admin dashboard ─────────────────────────────────────────
header('Location: index.php');
exit;
