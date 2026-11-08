<?php
require_once __DIR__ . '/../../api/db.php';
$r = $conn->query("SHOW COLUMNS FROM account_sessions LIKE 'last_seen_at'");
if ($r && $r->num_rows === 0) {
    $conn->query('ALTER TABLE account_sessions ADD COLUMN last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    echo 'Added last_seen_at column to account_sessions.';
} else {
    echo 'Column last_seen_at already exists.';
}
