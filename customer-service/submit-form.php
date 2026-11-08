<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
$email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
$message = is_string($_POST['message'] ?? null) ? trim($_POST['message']) : '';
if (
    $name === ''
    || strlen($name) > 100
    || strlen($email) > 100
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || $message === ''
    || strlen($message) > 10000
) {
    header('Location: index.php?error=1', true, 303);
    exit;
}

$statement = $conn->prepare('INSERT INTO messages (name, email, message) VALUES (?, ?, ?)');
if (!$statement) {
    error_log('Could not prepare customer message insert: ' . $conn->error);
    header('Location: index.php?error=1', true, 303);
    exit;
}
$statement->bind_param('sss', $name, $email, $message);
$saved = $statement->execute();
if (!$saved) {
    error_log('Could not save customer message: ' . $statement->error);
}
$statement->close();

header('Location: index.php?' . ($saved ? 'success=1' : 'error=1'), true, 303);
exit;
