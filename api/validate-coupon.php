<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function couponResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    couponResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}
$productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
$codeInput = $_POST['coupon_code'] ?? '';
$code = is_string($codeInput) ? strtoupper(trim($codeInput)) : '';
if (!$productId || $productId < 1 || preg_match('/^[A-Z0-9_-]{3,40}$/D', $code) !== 1) {
    couponResponse(422, ['success' => false, 'message' => 'Enter a valid coupon code.']);
}

require_once __DIR__ . '/db.php';
if (!ensureProductStockColumn($conn) || !ensureAdminOperationsSchema($conn) || !ensureStoreManagementSchema($conn)) {
    couponResponse(503, ['success' => false, 'message' => 'Promotions are temporarily unavailable.']);
}
$productStatement = $conn->prepare('SELECT price FROM products WHERE id = ? AND stock > 0 LIMIT 1');
if (!$productStatement) {
    error_log('Could not prepare product lookup for coupon validation: ' . $conn->error);
    couponResponse(503, ['success' => false, 'message' => 'Promotions are temporarily unavailable.']);
}
$productStatement->bind_param('i', $productId);
if (!$productStatement->execute()) {
    error_log('Could not execute product lookup for coupon validation: ' . $productStatement->error);
    $productStatement->close();
    couponResponse(503, ['success' => false, 'message' => 'Promotions are temporarily unavailable.']);
}
$product = $productStatement->get_result()->fetch_assoc();
$productStatement->close();
if (!$product) {
    couponResponse(404, ['success' => false, 'message' => 'Product is unavailable.']);
}
$subtotal = (float) $product['price'];
$coupon = findAvailableCoupon($conn, $code);
$discount = $coupon ? calculateCouponDiscount($subtotal, $coupon) : null;
if (!$coupon || $discount === null || $discount <= 0) {
    couponResponse(422, ['success' => false, 'message' => 'Coupon is invalid, expired, fully used, or does not meet its minimum subtotal.']);
}

couponResponse(200, [
    'success' => true,
    'coupon_code' => (string) $coupon['coupon_code'],
    'subtotal' => round($subtotal, 2),
    'discount' => $discount,
    'total' => round($subtotal - $discount, 2),
]);
