<?php
/**
 * Migration: profile v2 — DOB, cover photo, extra social links,
 *            IP + user-agent on account_sessions
 * Run once via CLI or browser.
 */
require_once __DIR__ . '/../../api/db.php';

$accountCols = [
    'date_of_birth'  => "ALTER TABLE account ADD COLUMN date_of_birth  DATE         NULL DEFAULT NULL AFTER sex",
    'cover_photo'    => "ALTER TABLE account ADD COLUMN cover_photo     VARCHAR(255) NULL DEFAULT NULL AFTER local_avatar",
    'telegram_url'   => "ALTER TABLE account ADD COLUMN telegram_url    VARCHAR(255) NULL DEFAULT NULL AFTER tiktok_url",
    'instagram_url'  => "ALTER TABLE account ADD COLUMN instagram_url   VARCHAR(255) NULL DEFAULT NULL AFTER telegram_url",
    'youtube_url'    => "ALTER TABLE account ADD COLUMN youtube_url     VARCHAR(255) NULL DEFAULT NULL AFTER instagram_url",
];

$sessionCols = [
    'ip_address' => "ALTER TABLE account_sessions ADD COLUMN ip_address  VARCHAR(45)  NULL DEFAULT NULL AFTER session_token",
    'user_agent' => "ALTER TABLE account_sessions ADD COLUMN user_agent  VARCHAR(255) NOT NULL DEFAULT '' AFTER ip_address",
];

echo "<pre>";
echo "=== account columns ===\n";
foreach ($accountCols as $col => $sql) {
    $check = $conn->query("SHOW COLUMNS FROM account LIKE '$col'");
    if ($check && $check->num_rows > 0) { echo "✔ $col — already exists\n"; continue; }
    echo $conn->query($sql) ? "✔ $col — added\n" : "✘ $col — FAILED: {$conn->error}\n";
}

echo "\n=== account_sessions columns ===\n";
foreach ($sessionCols as $col => $sql) {
    $check = $conn->query("SHOW COLUMNS FROM account_sessions LIKE '$col'");
    if ($check && $check->num_rows > 0) { echo "✔ $col — already exists\n"; continue; }
    echo $conn->query($sql) ? "✔ $col — added\n" : "✘ $col — FAILED: {$conn->error}\n";
}
echo "\nDone.\n</pre>";
