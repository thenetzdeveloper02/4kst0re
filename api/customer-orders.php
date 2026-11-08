<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function customerOrdersRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    customerOrdersRespond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google-auth.php';

if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
    customerOrdersRespond(500, ['success' => false, 'message' => 'Could not start your session.']);
}

if (!ensureSalesAnalyticsSchema($conn) || !ensureAdminOperationsSchema($conn)) {
    customerOrdersRespond(500, ['success' => false, 'message' => 'Could not load order history.']);
}

$user = getCurrentUser($conn);
if ($user === null) {
    customerOrdersRespond(401, ['success' => false, 'message' => 'Sign in to see your orders.']);
}

$accountId = (int) $user['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    $action = is_array($input) ? ($input['action'] ?? '') : '';
    if (!in_array($action, ['confirm_received', 'report_not_received'], true)) {
        customerOrdersRespond(400, ['success' => false, 'message' => 'Invalid order action.']);
    }

    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['customer_orders_csrf_token'] ?? '';
    if (
        !is_string($submittedToken)
        || !is_string($sessionToken)
        || $sessionToken === ''
        || !hash_equals($sessionToken, $submittedToken)
    ) {
        customerOrdersRespond(403, ['success' => false, 'message' => 'Refresh the page and try again.']);
    }

    $orderId = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$orderId || $orderId < 1) {
        customerOrdersRespond(400, ['success' => false, 'message' => 'Invalid order.']);
    }

    $targetStatus = $action === 'confirm_received' ? 'success' : 'failed';
    $statement = $action === 'confirm_received'
        ? $conn->prepare("UPDATE sales_orders SET customer_delivery_status = 'success', customer_received_at = NOW()
            WHERE id = ? AND account_id = ? AND customer_delivery_status = 'pending'
                AND customer_received_at IS NULL AND status <> 'Cancelled'")
        : $conn->prepare("UPDATE sales_orders SET customer_delivery_status = 'failed'
            WHERE id = ? AND account_id = ? AND customer_delivery_status = 'pending'
                AND customer_received_at IS NULL AND status <> 'Cancelled'");
    if (!$statement) {
        error_log('Could not prepare customer delivery status update: ' . $conn->error);
        customerOrdersRespond(500, ['success' => false, 'message' => 'Could not update delivery status.']);
    }
    $statement->bind_param('ii', $orderId, $accountId);
    if (!$statement->execute()) {
        error_log('Could not save customer delivery status: ' . $statement->error);
        $statement->close();
        customerOrdersRespond(500, ['success' => false, 'message' => 'Could not update delivery status.']);
    }
    $updated = $statement->affected_rows === 1;
    $statement->close();

    $lookup = $conn->prepare('SELECT customer_delivery_status, customer_received_at, status FROM sales_orders WHERE id = ? AND account_id = ? LIMIT 1');
    if (!$lookup) {
        error_log('Could not read customer delivery status: ' . $conn->error);
        customerOrdersRespond(500, ['success' => false, 'message' => 'Delivery status was saved, but could not be loaded.']);
    }
    $lookup->bind_param('ii', $orderId, $accountId);
    if (!$lookup->execute()) {
        error_log('Could not execute customer delivery status read: ' . $lookup->error);
        $lookup->close();
        customerOrdersRespond(500, ['success' => false, 'message' => 'Delivery status was saved, but could not be loaded.']);
    }
    $order = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$order) {
        customerOrdersRespond(404, ['success' => false, 'message' => 'Order not found.']);
    }

    $deliveryStatus = $order['customer_received_at'] !== null ? 'success' : (string) $order['customer_delivery_status'];
    if ($order['status'] === 'Cancelled') {
        customerOrdersRespond(409, ['success' => false, 'message' => 'Cancelled orders cannot have a delivery status.']);
    }
    if (!$updated && $deliveryStatus !== $targetStatus) {
        customerOrdersRespond(409, ['success' => false, 'message' => 'This delivery status was already reported and cannot be changed.']);
    }
    customerOrdersRespond(200, [
        'success' => true,
        'delivery_status' => $deliveryStatus,
        'received_at' => $order['customer_received_at'] === null ? null : (string) $order['customer_received_at'],
    ]);
}

$statement = $conn->prepare(
    "SELECT s.id, s.order_reference, s.product_name, s.quantity, s.unit_price,
            s.discount_amount, s.status, s.payment_status, s.customer_delivery_status,
            s.customer_received_at, s.admin_delivery_confirmed_at,
            s.admin_delivery_confirmed_by, s.location, s.created_at,
            p.image AS product_image
     FROM sales_orders s
     LEFT JOIN products p ON p.id = s.product_id
     WHERE s.account_id = ?
     ORDER BY s.created_at DESC, s.id DESC
     LIMIT 50"
);
if (!$statement) {
    error_log('Could not prepare customer order history: ' . $conn->error);
    customerOrdersRespond(500, ['success' => false, 'message' => 'Could not load order history.']);
}

$statement->bind_param('i', $accountId);
if (!$statement->execute()) {
    error_log('Could not execute customer order history query: ' . $statement->error);
    $statement->close();
    customerOrdersRespond(500, ['success' => false, 'message' => 'Could not load order history.']);
}

$result = $statement->get_result();
$orders = [];
$pendingCount = 0;
while ($row = $result->fetch_assoc()) {
    $image = (string) ($row['product_image'] ?? '');
    if (preg_match('~^https?://~i', $image) !== 1) {
        $image = $image !== '' ? '../' . ltrim($image, '/') : '../home/logo/logome.png';
    }

    $status = (string) $row['status'];
    if (!in_array($status, ['Completed', 'Cancelled'], true)) {
        $pendingCount++;
    }

    $orders[] = [
        'id' => (int) $row['id'],
        'reference' => (string) ($row['order_reference'] ?? ''),
        'product_name' => (string) $row['product_name'],
        'product_image' => $image,
        'quantity' => (int) $row['quantity'],
        'unit_price' => (float) $row['unit_price'],
        'discount_amount' => (float) $row['discount_amount'],
        'status' => $status,
        'payment_status' => (string) $row['payment_status'],
        'delivery_status' => $row['customer_received_at'] !== null ? 'success' : (string) $row['customer_delivery_status'],
        'received_at' => $row['customer_received_at'] === null ? null : (string) $row['customer_received_at'],
        'admin_delivered_at' => $row['admin_delivery_confirmed_at'] === null ? null : (string) $row['admin_delivery_confirmed_at'],
        'admin_delivered_by' => $row['admin_delivery_confirmed_by'] === null ? null : (string) $row['admin_delivery_confirmed_by'],
        'location' => (string) $row['location'],
        'created_at' => (string) $row['created_at'],
    ];
}
$statement->close();

customerOrdersRespond(200, [
    'success' => true,
    'pending_count' => $pendingCount,
    'currency' => storeCurrencySymbol($conn),
    'orders' => $orders,
]);
