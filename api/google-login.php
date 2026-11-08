<?php
declare(strict_types=1);

require_once __DIR__ . '/google-auth.php';

// Start session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Get Google login URL
$loginUrl = getGoogleLoginUrl();

if (empty($loginUrl)) {
    http_response_code(500);
    exit(json_encode([
        'success' => false,
        'error' => 'Google OAuth is not configured. Please contact the administrator.'
    ]));
}

// Redirect to Google OAuth
header('Location: ' . $loginUrl);
exit;
