<?php
// Force clear opcode cache
if (function_exists('opcache_reset')) {
    opcache_reset();
}

echo "<h2>Google OAuth Configuration Debug</h2><pre>";

// Step 1: Check getenv() BEFORE loading anything
echo "=== Step 1: getenv() BEFORE any includes ===\n";
echo "getenv(GOOGLE_REDIRECT_URI): " . var_export(getenv('GOOGLE_REDIRECT_URI'), true) . "\n";
echo "\$_ENV[GOOGLE_REDIRECT_URI]: " . var_export($_ENV['GOOGLE_REDIRECT_URI'] ?? 'not set', true) . "\n\n";

// Step 2: Read .env file directly
echo "=== Step 2: Raw .env file ===\n";
$envFile = dirname(__DIR__) . '/.env';
echo "Path: $envFile\n";
$raw = file_get_contents($envFile);
echo htmlspecialchars($raw) . "\n\n";

// Step 3: Now load db.php and check
echo "=== Step 3: After loading db.php ===\n";
require_once __DIR__ . '/db.php';
echo "applicationConfigValue result: " . var_export(applicationConfigValue('GOOGLE_REDIRECT_URI'), true) . "\n\n";

// Step 4: Check all env sources
echo "=== Step 4: All GOOGLE_ env vars ===\n";
foreach ($_ENV as $k => $v) {
    if (strpos($k, 'GOOGLE') !== false) {
        echo "$k=$v\n";
    }
}
foreach (getenv() as $k => $v) {
    if (strpos($k, 'GOOGLE') !== false) {
        echo "getenv: $k=$v\n";
    }
}

echo "</pre>";
