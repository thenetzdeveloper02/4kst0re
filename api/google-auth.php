<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const USER_SESSION_MAX_SECONDS = 2592000; // 30 days

/**
 * Get Google OAuth configuration from environment
 */
function getGoogleOAuthConfig(): array
{
    $clientId = applicationConfigValue('GOOGLE_CLIENT_ID');
    $clientSecret = applicationConfigValue('GOOGLE_CLIENT_SECRET');
    $redirectUri = applicationConfigValue('GOOGLE_REDIRECT_URI');

    if (!$clientId || !$clientSecret || !$redirectUri) {
        error_log('Google OAuth configuration is incomplete');
        return [];
    }

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'userinfo_url' => 'https://www.googleapis.com/oauth2/v2/userinfo',
        'scope' => 'openid email profile'
    ];
}

/**
 * Generate Google OAuth login URL
 */
function getGoogleLoginUrl(): string
{
    $config = getGoogleOAuthConfig();
    
    if (empty($config)) {
        return '';
    }

    // Generate and store state token for CSRF protection
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    $params = [
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'scope' => $config['scope'],
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account'
    ];

    return $config['auth_url'] . '?' . http_build_query($params);
}

/**
 * Exchange authorization code for access token
 */
function exchangeGoogleAuthCode(string $code): ?array
{
    $config = getGoogleOAuthConfig();
    
    if (empty($config)) {
        return null;
    }

    $postData = [
        'code' => $code,
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'],
        'grant_type' => 'authorization_code'
    ];

    $ch = curl_init($config['token_url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log('Google token exchange failed: HTTP ' . $httpCode);
        return null;
    }

    $data = json_decode($response, true);
    
    if (!isset($data['access_token'])) {
        error_log('Google token exchange response missing access_token');
        return null;
    }

    return $data;
}

/**
 * Get user info from Google using access token
 */
function getGoogleUserInfo(string $accessToken): ?array
{
    $config = getGoogleOAuthConfig();
    
    if (empty($config)) {
        return null;
    }

    $ch = curl_init($config['userinfo_url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log('Google userinfo request failed: HTTP ' . $httpCode);
        return null;
    }

    $userInfo = json_decode($response, true);
    
    if (!isset($userInfo['id'], $userInfo['email'])) {
        error_log('Google userinfo response missing required fields');
        return null;
    }

    return $userInfo;
}

/**
 * Create or update user account from Google profile
 */
function createOrUpdateGoogleUser(mysqli $conn, array $googleProfile): ?int
{
    $googleId = $googleProfile['id'];
    $email = $googleProfile['email'];
    $name = $googleProfile['name'] ?? $googleProfile['email'];
    $picture = $googleProfile['picture'] ?? null;

    // Check if user exists
    $stmt = $conn->prepare('SELECT id FROM account WHERE google_id = ? OR email = ? LIMIT 1');
    if (!$stmt) {
        error_log('Could not prepare user lookup: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('ss', $googleId, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $existingUser = $result->fetch_assoc();
    $stmt->close();

    if ($existingUser) {
        // Update existing user
        $userId = (int) $existingUser['id'];
        $updateStmt = $conn->prepare('UPDATE account SET google_id = ?, name = ?, profile_picture = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        
        if (!$updateStmt) {
            error_log('Could not prepare user update: ' . $conn->error);
            return null;
        }

        $updateStmt->bind_param('sssi', $googleId, $name, $picture, $userId);
        $updated = $updateStmt->execute();
        $updateStmt->close();

        if (!$updated) {
            error_log('Could not update user account');
            return null;
        }

        return $userId;
    } else {
        // Create new user
        $insertStmt = $conn->prepare('INSERT INTO account (google_id, name, email, profile_picture) VALUES (?, ?, ?, ?)');
        
        if (!$insertStmt) {
            error_log('Could not prepare user insert: ' . $conn->error);
            return null;
        }

        $insertStmt->bind_param('ssss', $googleId, $name, $email, $picture);
        $inserted = $insertStmt->execute();
        $userId = $inserted ? (int) $insertStmt->insert_id : null;
        $insertStmt->close();

        if (!$inserted) {
            error_log('Could not create user account');
            return null;
        }

        return $userId;
    }
}

/**
 * Create user session with a persistent "remember me" cookie (30 days)
 */
function createUserSession(mysqli $conn, int $accountId): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!ensureAccountSessionTrackingSchema($conn)) {
        return null;
    }

    $sessionToken = bin2hex(random_bytes(32));
    $expiresAt    = date('Y-m-d H:i:s', time() + USER_SESSION_MAX_SECONDS);

    // Clean up old sessions for this account (keep max 5 devices)
    $expiredStmt = $conn->prepare('DELETE FROM account_sessions WHERE account_id = ? AND expires_at <= NOW()');
    if ($expiredStmt) {
        $expiredStmt->bind_param('i', $accountId);
        if (!$expiredStmt->execute()) {
            error_log('Could not delete expired account sessions: ' . $expiredStmt->error);
        }
        $expiredStmt->close();
    } else {
        error_log('Could not prepare expired account session cleanup: ' . $conn->error);
    }

    $cleanStmt = $conn->prepare('DELETE FROM account_sessions WHERE account_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM account_sessions WHERE account_id = ? ORDER BY created_at DESC LIMIT 4) t)');
    if ($cleanStmt) {
        $cleanStmt->bind_param('ii', $accountId, $accountId);
        if (!$cleanStmt->execute()) {
            error_log('Could not limit active account sessions: ' . $cleanStmt->error);
        }
        $cleanStmt->close();
    } else {
        error_log('Could not prepare active account session cleanup: ' . $conn->error);
    }

    $stmt = $conn->prepare('INSERT INTO account_sessions (account_id, session_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) {
        error_log('Could not prepare session insert: ' . $conn->error);
        return null;
    }
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $stmt->bind_param('issss', $accountId, $sessionToken, $ipAddress, $userAgent, $expiresAt);
    $created = $stmt->execute();
    $stmt->close();

    if (!$created) {
        error_log('Could not create user session: ' . $stmt->error);
        return null;
    }

    // Store in PHP session (short-lived, survives page refreshes)
    $_SESSION['user_id']       = $accountId;
    $_SESSION['session_token'] = $sessionToken;

    // Store in a persistent COOKIE (survives browser close, server restart)
    // Cookie name: user_remember | HttpOnly | SameSite=Lax | 30 days
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('user_remember', $sessionToken, [
        'expires'  => time() + USER_SESSION_MAX_SECONDS,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return $sessionToken;
}

function recordAccountLoginHistory(mysqli $conn, int $accountId): bool
{
    if (!ensureAccountLoginHistorySchema($conn)) {
        return false;
    }

    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $ipAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? $remoteAddress : null;
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
    $loginMethod = 'google';
    $statement = $conn->prepare('INSERT INTO account_login_history (account_id, ip_address, user_agent, login_method) VALUES (?, ?, ?, ?)');
    if (!$statement) {
        error_log('Could not prepare account login history insert: ' . $conn->error);
        return false;
    }

    $statement->bind_param('isss', $accountId, $ipAddress, $userAgent, $loginMethod);
    $recorded = $statement->execute();
    if (!$recorded) {
        error_log('Could not record account login history: ' . $statement->error);
    }
    $statement->close();

    return $recorded;
}

/**
 * Get current logged-in user.
 * Priority: $_SESSION token → persistent cookie token → null
 */
function getCurrentUser(mysqli $conn): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // 1. Try PHP session first (fastest path)
    $sessionToken = null;
    $userId       = null;

    if (isset($_SESSION['user_id'], $_SESSION['session_token'])) {
        $userId       = (int) $_SESSION['user_id'];
        $sessionToken = (string) $_SESSION['session_token'];
    }

    // 2. Fall back to the persistent remember-me cookie
    if ($sessionToken === null && !empty($_COOKIE['user_remember'])) {
        $cookieToken = $_COOKIE['user_remember'];
        $cookieStmt = $conn->prepare(
            'SELECT account_id FROM account_sessions WHERE session_token = ? AND expires_at > NOW() LIMIT 1'
        );
        if ($cookieStmt) {
            $cookieStmt->bind_param('s', $cookieToken);
            $cookieStmt->execute();
            $cookieRow = $cookieStmt->get_result()->fetch_assoc();
            $cookieStmt->close();

            if ($cookieRow) {
                $userId       = (int) $cookieRow['account_id'];
                $sessionToken = $cookieToken;
                // Restore into PHP session so next request is fast
                $_SESSION['user_id']       = $userId;
                $_SESSION['session_token'] = $sessionToken;
            }
        }
    }

    if ($sessionToken === null || $userId === null) {
        return null;
    }

    if (!ensureAccountProfileSchema($conn)) {
        return null;
    }

    // 3. Validate token against DB and load full user
    $stmt = $conn->prepare('
        SELECT a.id, a.google_id, a.name, a.email, a.profile_picture, a.bio, a.created_at,
               a.phone_number, a.sex, a.date_of_birth, a.address,
               a.facebook_url, a.tiktok_url, a.telegram_url, a.instagram_url, a.youtube_url,
               a.local_avatar, a.cover_photo
        FROM account a
        INNER JOIN account_sessions s ON a.id = s.account_id
        WHERE s.account_id = ? AND s.session_token = ? AND s.expires_at > NOW()
        LIMIT 1
    ');

    if (!$stmt) {
        error_log('Could not prepare user session lookup: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('is', $userId, $sessionToken);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        // Token invalid — clear stale session & cookie
        $_SESSION = [];
        if (!headers_sent()) {
            setcookie('user_remember', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        }
        return null;
    }

    // 4. Sliding expiry — refresh cookie & DB expiry on each visit (keeps active users logged in)
    $newExpiry = date('Y-m-d H:i:s', time() + USER_SESSION_MAX_SECONDS);
    $refreshStmt = $conn->prepare('UPDATE account_sessions SET expires_at = ? WHERE session_token = ?');
    if ($refreshStmt) {
        $refreshStmt->bind_param('ss', $newExpiry, $sessionToken);
        $refreshStmt->execute();
        $refreshStmt->close();
    }
    // Only set cookie if headers haven't been sent yet
    if (!headers_sent()) {
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('user_remember', $sessionToken, [
            'expires'  => time() + USER_SESSION_MAX_SECONDS,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    return $user;
}

/**
 * Logout user — destroys PHP session, DB session row, and persistent cookie
 */
function logoutUser(mysqli $conn): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Delete DB session row
    $tokenToDelete = $_SESSION['session_token'] ?? ($_COOKIE['user_remember'] ?? null);
    if ($tokenToDelete) {
        $stmt = $conn->prepare('DELETE FROM account_sessions WHERE session_token = ?');
        if ($stmt) {
            $stmt->bind_param('s', $tokenToDelete);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Clear PHP session
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();

    // Clear persistent remember-me cookie
    setcookie('user_remember', '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Check if user is logged in
 */
function isUserLoggedIn(mysqli $conn): bool
{
    return getCurrentUser($conn) !== null;
}

/**
 * Require user to be logged in
 */
function requireUserLogin(mysqli $conn, string $redirectUrl = '../home/index.php'): array
{
    $user = getCurrentUser($conn);
    
    if ($user === null) {
        header('Location: ' . $redirectUrl);
        exit;
    }

    return $user;
}
