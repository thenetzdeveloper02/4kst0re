<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/admin-auth.php';

if (!ensureAdminSecuritySchema($conn) || !adminStartSession()) {
    http_response_code(500);
    exit('Could not prepare the admin session.');
}
$currentAdmin = adminRequireLogin($conn);
adminRequirePermission($currentAdmin, 'orders');
if (!ensureSalesAnalyticsSchema($conn) || !ensureAdminOperationsSchema($conn)) {
    http_response_code(500);
    exit('Could not prepare private receipt access.');
}

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$orderId || $orderId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}

$statement = $conn->prepare('SELECT receipt_filename, receipt_mime FROM sales_orders WHERE id = ? LIMIT 1');
if (!$statement) {
    error_log('Could not prepare private receipt lookup: ' . $conn->error);
    http_response_code(500);
    exit('Could not load receipt.');
}
$statement->bind_param('i', $orderId);
if (!$statement->execute()) {
    error_log('Could not execute private receipt lookup: ' . $statement->error);
    $statement->close();
    http_response_code(500);
    exit('Could not load receipt.');
}
$receipt = $statement->get_result()->fetch_assoc();
$statement->close();

$allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'application/pdf'];
if (
    !$receipt
    || !is_string($receipt['receipt_filename'])
    || !preg_match('/^receipt_[a-f0-9]{32}\.(jpg|png|webp|gif|heic|heif|pdf)$/D', $receipt['receipt_filename'])
    || !in_array($receipt['receipt_mime'], $allowedTypes, true)
) {
    http_response_code(404);
    exit('Receipt not found.');
}

$receiptDirectory = realpath(privateReceiptDirectory());
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$publicDirectory = is_string($documentRoot) && $documentRoot !== '' ? realpath($documentRoot) : false;
$receiptPath = $receiptDirectory === false
    ? false
    : realpath($receiptDirectory . DIRECTORY_SEPARATOR . $receipt['receipt_filename']);
if (
    $receiptDirectory === false
    || ($publicDirectory !== false && (
        $receiptDirectory === $publicDirectory
        || strpos($receiptDirectory, $publicDirectory . DIRECTORY_SEPARATOR) === 0
    ))
    || $receiptPath === false
    || !is_file($receiptPath)
    || strpos($receiptPath, $receiptDirectory . DIRECTORY_SEPARATOR) !== 0
) {
    http_response_code(404);
    exit('Receipt file is not available.');
}

header('Content-Type: ' . $receipt['receipt_mime']);
header('Content-Length: ' . (string) filesize($receiptPath));
header('Content-Disposition: inline; filename="order-' . $orderId . '-receipt.' . pathinfo($receiptPath, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cross-Origin-Resource-Policy: same-origin');
readfile($receiptPath);
