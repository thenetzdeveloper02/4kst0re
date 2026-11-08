<?php
declare(strict_types=1);

require_once __DIR__ . '/google-auth.php';

// Start the DEFAULT user session first
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ── Error from Google ────────────────────────────────────────────────────────
if (isset($_GET['error'])) {
    error_log('Google OAuth error: ' . $_GET['error']);
    header('Location: ../home/index.php?login_error=' . urlencode('Google login was cancelled or failed.'));
    exit;
}

if (!isset($_GET['code'], $_GET['state'])) {
    header('Location: ../home/index.php?login_error=' . urlencode('Invalid callback from Google.'));
    exit;
}

// ── CSRF state check ─────────────────────────────────────────────────────────
if (!isset($_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], $_GET['state'])) {
    error_log('Google OAuth state mismatch');
    header('Location: ../home/index.php?login_error=' . urlencode('Security validation failed. Please try again.'));
    exit;
}
unset($_SESSION['google_oauth_state']);

// ── Exchange code → token → user info ────────────────────────────────────────
$tokenData = exchangeGoogleAuthCode($_GET['code']);
if ($tokenData === null) {
    header('Location: ../home/index.php?login_error=' . urlencode('Failed to authenticate with Google.'));
    exit;
}

$googleUser = getGoogleUserInfo($tokenData['access_token']);
if ($googleUser === null) {
    header('Location: ../home/index.php?login_error=' . urlencode('Failed to retrieve your Google profile.'));
    exit;
}

// ── Save user to account table ────────────────────────────────────────────────
$userId = createOrUpdateGoogleUser($conn, $googleUser);
if ($userId === null) {
    header('Location: ../home/index.php?login_error=' . urlencode('Failed to create user account.'));
    exit;
}
$firstAccountLogin = false;
if (ensureAccountLoginHistorySchema($conn)) {
    $historyStatement = $conn->prepare('SELECT 1 FROM account_login_history WHERE account_id = ? LIMIT 1');
    if ($historyStatement) {
        $historyStatement->bind_param('i', $userId);
        if ($historyStatement->execute()) {
            $firstAccountLogin = $historyStatement->get_result()->num_rows === 0;
        } else {
            error_log('Could not check whether this is the account first login: ' . $historyStatement->error);
        }
        $historyStatement->close();
    } else {
        error_log('Could not prepare account first-login check: ' . $conn->error);
    }
} else {
    error_log('Could not prepare account first-login check because login history is unavailable.');
}
$sessionToken = createUserSession($conn, $userId);
if ($sessionToken === null) {
    header('Location: ../home/index.php?login_error=' . urlencode('Could not create your login session. Please try again.'));
    exit;
}
if (!recordAccountLoginHistory($conn, $userId)) {
    error_log('Successful Google login could not be added to account login history for account ' . $userId);
}

$pendingCheckoutItem = $_SESSION['pending_checkout_item'] ?? null;
$pendingCheckoutExpires = $_SESSION['pending_checkout_item_expires'] ?? 0;
$hasPendingCheckout = is_string($pendingCheckoutItem)
    && ctype_digit($pendingCheckoutItem)
    && (int) $pendingCheckoutItem > 0
    && is_int($pendingCheckoutExpires)
    && $pendingCheckoutExpires >= time();
unset($_SESSION['pending_checkout_item'], $_SESSION['pending_checkout_item_expires']);

// ── Check if this email is an admin ──────────────────────────────────────────
$adminEmailsRaw = applicationConfigValue('ADMIN_GOOGLE_EMAILS') ?? '';
$adminEmails    = array_filter(array_map('strtolower', array_map('trim', explode(',', $adminEmailsRaw))));
$userEmail      = strtolower(trim($googleUser['email'] ?? ''));

if (
    !$hasPendingCheckout
    && !empty($adminEmails)
    && in_array($userEmail, $adminEmails, true)
) {

    // Find the superadmin row
    $adminStmt = $conn->prepare(
        "SELECT id, username FROM admins WHERE is_active = 1 AND role = 'admin' ORDER BY id ASC LIMIT 1"
    );
    if (!$adminStmt || !$adminStmt->execute()) {
        header('Location: ../home/index.php?login_error=' . urlencode('Admin lookup failed.'));
        exit;
    }
    $adminRow = $adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();

    if ($adminRow) {
        // Store admin credentials in the CURRENT session so google-bridge.php can read them.
        // Bridge runs in the same domain, picks these up, then opens the ecommerce_admin session.
        $_SESSION['pending_admin_id']       = (int) $adminRow['id'];
        $_SESSION['pending_admin_username'] = (string) $adminRow['username'];
        $_SESSION['pending_admin_token']    = bin2hex(random_bytes(32));

        // Save the token in DB for extra verification (using account_sessions with real userId)
        $bridgeExpiry = date('Y-m-d H:i:s', time() + 90);
        $bridgeToken  = $_SESSION['pending_admin_token'];
        $bridgeStmt   = $conn->prepare(
            "INSERT INTO account_sessions (account_id, session_token, expires_at) VALUES (?, ?, ?)"
        );
        if ($bridgeStmt) {
            // Store with real userId so FK constraint is satisfied
            $bridgeStmt->bind_param('iss', $userId, $bridgeToken, $bridgeExpiry);
            $bridgeStmt->execute();
            $bridgeStmt->close();
        }

        session_write_close(); // flush session data before redirect

        header('Location: ../admin/google-bridge.php?token=' . urlencode($bridgeToken));
        exit;
    }
}

if ($hasPendingCheckout) {
    header('Location: ../home/index.php?item=' . rawurlencode($pendingCheckoutItem));
    exit;
}

if ($firstAccountLogin) {
    if (
        !ensureStoreManagementSchema($conn)
        || !createStoreAnnouncement(
            $conn,
            'Welcome to the store',
            'Your account is ready. Browse the latest products and store updates.',
            null,
            $userId
        )
    ) {
        error_log('First-login welcome notification could not be created for account ' . $userId);
    }
}

// ── Regular user → profile ───────────────────────────────────────────────────
header('Location: ../home/profile.php?login_success=1');
exit;
