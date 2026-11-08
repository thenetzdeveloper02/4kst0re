<?php
declare(strict_types=1);

require_once __DIR__ . '/google-auth.php';

// Logout user and destroy session
logoutUser($conn);

// Redirect to home page
header('Location: ../home/index.php?logout=1');
exit;
