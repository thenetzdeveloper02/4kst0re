<?php
include 'db.php';

if (!ensureProductStockColumn($conn)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Product inventory is temporarily unavailable.']);
    exit;
}

$sql = "SELECT * FROM products WHERE stock > 0 ORDER BY id DESC";
$result = $conn->query($sql);

$products = [];

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode($products);
?>
