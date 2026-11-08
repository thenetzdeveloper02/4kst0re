<?php
declare(strict_types=1);

/**
 * Session Cleanup Script
 * This script removes expired user sessions from the database.
 * It can be run manually or scheduled as a cron job.
 */

require_once __DIR__ . '/db.php';

// Delete expired sessions
$deleted = $conn->query('DELETE FROM account_sessions WHERE expires_at <= NOW()');

if ($deleted) {
    $count = $conn->affected_rows;
    echo "Session cleanup completed. Removed {$count} expired session(s).\n";
    error_log("Session cleanup: Removed {$count} expired session(s)");
} else {
    echo "Session cleanup failed: " . $conn->error . "\n";
    error_log("Session cleanup failed: " . $conn->error);
    http_response_code(500);
}
