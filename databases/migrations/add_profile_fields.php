<?php
/**
 * Migration: add extended profile fields to account table
 * Run once: http://localhost/Ecommerce/databases/migrations/add_profile_fields.php
 */
require_once __DIR__ . '/../../api/db.php';

$migrations = [
    "phone_number"  => "ALTER TABLE account ADD COLUMN phone_number VARCHAR(32)  NULL DEFAULT NULL AFTER bio",
    "sex"           => "ALTER TABLE account ADD COLUMN sex           VARCHAR(16)  NULL DEFAULT NULL AFTER phone_number",
    "address"       => "ALTER TABLE account ADD COLUMN address        VARCHAR(300) NULL DEFAULT NULL AFTER sex",
    "facebook_url"  => "ALTER TABLE account ADD COLUMN facebook_url   VARCHAR(255) NULL DEFAULT NULL AFTER address",
    "tiktok_url"    => "ALTER TABLE account ADD COLUMN tiktok_url     VARCHAR(255) NULL DEFAULT NULL AFTER facebook_url",
    "local_avatar"  => "ALTER TABLE account ADD COLUMN local_avatar   VARCHAR(255) NULL DEFAULT NULL AFTER tiktok_url",
];

echo "<pre style='font-family:monospace;font-size:14px;'>";
echo "Running profile field migrations…\n\n";

foreach ($migrations as $column => $sql) {
    // Skip if column already exists
    $check = $conn->query("SHOW COLUMNS FROM account LIKE '$column'");
    if ($check && $check->num_rows > 0) {
        echo "✔ $column — already exists, skipped.\n";
        continue;
    }
    if ($conn->query($sql)) {
        echo "✔ $column — added successfully.\n";
    } else {
        echo "✘ $column — FAILED: " . $conn->error . "\n";
    }
}

echo "\nDone! You can delete this file now.\n";
echo "</pre>";
