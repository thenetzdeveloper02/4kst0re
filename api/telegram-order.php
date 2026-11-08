<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/db.php';
if (!isset($conn) || !$conn instanceof mysqli) {
    respond(500, ['success' => false, 'message' => 'មិនអាចភ្ជាប់ទៅមូលដ្ឋានទិន្នន័យបានទេ។']);
}
if (!ensureProductStockColumn($conn)) {
    respond(500, ['success' => false, 'message' => 'មិនអាចពិនិត្យស្តុកផលិតផលបានទេ។ សូមទាក់ទង Admin។']);
}
if (!ensureSalesAnalyticsSchema($conn)) {
    respond(500, ['success' => false, 'message' => 'មិនអាចរក្សាទុកប្រវត្តិការបញ្ជាទិញបានទេ។ សូមទាក់ទង Admin។']);
}
if (!ensureAdminOperationsSchema($conn)) {
    respond(500, ['success' => false, 'message' => 'មិនអាចរៀបចំប្រព័ន្ធគ្រប់គ្រងការបញ្ជាទិញបានទេ។ សូមទាក់ទង Admin។']);
}
if (!ensureStoreManagementSchema($conn)) {
    respond(500, ['success' => false, 'message' => 'មិនអាចរៀបចំការជូនដំណឹង និងប្រូម៉ូសិនបានទេ។ សូមទាក់ទង Admin។']);
}

function restoreReservedProductStock(mysqli $conn, int $productId, string $orderReference): bool
{
    if (!$conn->begin_transaction()) {
        error_log('Could not start stock restoration transaction after Telegram order failure.');
        return false;
    }

    $lookup = $conn->prepare('SELECT name, stock FROM products WHERE id = ? FOR UPDATE');
    if (!$lookup) {
        $conn->rollback();
        error_log('Could not prepare product stock restoration after Telegram order failure.');
        return false;
    }
    $lookup->bind_param('i', $productId);
    if (!$lookup->execute()) {
        $lookup->close();
        $conn->rollback();
        error_log('Could not read product stock during Telegram failure recovery.');
        return false;
    }
    $product = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$product) {
        $conn->rollback();
        error_log('Could not find product while restoring stock after Telegram order failure.');
        return false;
    }

    $stockBefore = (int) $product['stock'];
    $stockAfter = $stockBefore + 1;
    $update = $conn->prepare('UPDATE products SET stock = ? WHERE id = ?');
    if (!$update) {
        $conn->rollback();
        error_log('Could not prepare product stock restoration after Telegram order failure.');
        return false;
    }
    $update->bind_param('ii', $stockAfter, $productId);
    $updated = $update->execute();
    $update->close();
    $logged = $updated && recordInventoryAdjustment(
        $conn,
        $productId,
        (string) $product['name'],
        1,
        $stockBefore,
        $stockAfter,
        'Reservation released after failed Telegram order ' . $orderReference,
        'System'
    );
    if (!$logged || !$conn->commit()) {
        $conn->rollback();
        error_log('Could not complete product stock restoration after Telegram order failure.');
        return false;
    }

    return true;
}

function readConfigValue(array $keys): ?string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && trim($value) !== '') {
            return trim($value);
        }
    }

    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($envFile)) {
        return null;
    }

    $config = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $matches)) {
            $value = trim($matches[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = substr($value, 1, -1);
            }
            $config[$matches[1]] = $value;
        }
    }

    foreach ($keys as $key) {
        if (isset($config[$key]) && $config[$key] !== '') {
            return $config[$key];
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
    respond(500, ['success' => false, 'message' => 'មិនអាចចាប់ផ្ដើម session បានទេ។ សូមព្យាយាមម្តងទៀត។']);
}

require_once __DIR__ . '/google-auth.php';
$orderUser = getCurrentUser($conn);
if ($orderUser === null) {
    respond(401, ['success' => false, 'message' => 'សូមចូលគណនីដោយប្រើ Google មុនពេលបញ្ជាទិញ។']);
}

$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['order_csrf_token'] ?? '';
if (!is_string($submittedToken) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    respond(403, ['success' => false, 'message' => 'សំណើមិនត្រឹមត្រូវទេ។ សូមបិទហើយបើកផ្ទាំងទិញម្ដងទៀត។']);
}

$item = $_POST['item'] ?? '';
$customerNameInput = $_POST['customer_name'] ?? '';
$phoneNumberInput = $_POST['phone_number'] ?? '';
$locationInput = $_POST['location'] ?? '';
$locationDetailsInput = $_POST['location_details'] ?? '';
if (
    !is_string($item)
    || !ctype_digit($item)
    || (int) $item < 1
    || !is_string($customerNameInput)
    || !is_string($phoneNumberInput)
    || !is_string($locationInput)
    || !is_string($locationDetailsInput)
) {
    respond(422, ['success' => false, 'message' => 'សូមបំពេញឈ្មោះ លេខទូរស័ព្ទ និងទីតាំងឱ្យបានត្រឹមត្រូវ។']);
}

$customerName = trim($customerNameInput);
$phoneNumber = trim($phoneNumberInput);
$provinceOptions = cambodiaProvinceOptions();
$province = trim($locationInput);
$locationDetails = trim($locationDetailsInput);
$location = $province . ($locationDetails !== '' ? ', ' . $locationDetails : '');
$phoneNormalized = normalizeCustomerPhone($phoneNumber);
if (
    $customerName === ''
    || strlen($customerName) > 100
    || $phoneNumber === ''
    || strlen($phoneNumber) > 32
    || preg_match('/^[+\d\s().-]+$/D', $phoneNumber) !== 1
    || strlen($phoneNormalized) < 6
    || strlen($phoneNormalized) > 20
    || !array_key_exists($province, $provinceOptions)
    || ($locationDetails !== '' && strlen($locationDetails) > 260)
    || strlen($location) > 300
) {
    respond(422, ['success' => false, 'message' => 'សូមបំពេញឈ្មោះ លេខទូរស័ព្ទ និងទីតាំងឱ្យបានត្រឹមត្រូវ។']);
}

$customerStatement = $conn->prepare('SELECT is_blocked FROM customer_profiles WHERE phone_normalized = ? LIMIT 1');
if (!$customerStatement) {
    error_log('Could not prepare customer checkout eligibility lookup.');
    respond(500, ['success' => false, 'message' => 'មិនអាចពិនិត្យស្ថានភាពអតិថិជនបានទេ។']);
}
$customerStatement->bind_param('s', $phoneNormalized);
if (!$customerStatement->execute()) {
    $customerStatement->close();
    error_log('Could not execute customer checkout eligibility lookup.');
    respond(500, ['success' => false, 'message' => 'មិនអាចពិនិត្យស្ថានភាពអតិថិជនបានទេ។']);
}
$customerProfile = $customerStatement->get_result()->fetch_assoc();
$customerStatement->close();
if ($customerProfile && (int) $customerProfile['is_blocked'] === 1) {
    respond(403, ['success' => false, 'message' => 'លេខទូរស័ព្ទនេះមិនអាចធ្វើការបញ្ជាទិញបានទេ។ សូមទាក់ទងហាង។']);
}

$statement = $conn->prepare('SELECT name, price, cost_price, stock, low_stock_threshold FROM products WHERE id = ?');
if (!$statement) {
    error_log('Could not prepare Telegram order product lookup.');
    respond(500, ['success' => false, 'message' => 'មានបញ្ហាក្នុងការរៀបចំព័ត៌មានបញ្ជាទិញ។']);
}
$productId = (int) $item;
$statement->bind_param('i', $productId);
if (!$statement->execute()) {
    $statement->close();
    error_log('Could not execute Telegram order product lookup.');
    respond(500, ['success' => false, 'message' => 'មានបញ្ហាក្នុងការរៀបចំព័ត៌មានបញ្ជាទិញ។']);
}
$result = $statement->get_result();
$productRow = $result ? $result->fetch_assoc() : null;
$statement->close();
if (!$productRow) {
    respond(404, ['success' => false, 'message' => 'រកមិនឃើញផលិតផលនេះទេ។']);
}
if ((int) $productRow['stock'] < 1) {
    respond(409, ['success' => false, 'message' => 'ផលិតផលនេះអស់ស្តុកហើយ។ សូមជ្រើសរើសផលិតផលផ្សេង។']);
}
$couponCodeInput = $_POST['coupon_code'] ?? '';
$couponCode = is_string($couponCodeInput) ? strtoupper(trim($couponCodeInput)) : '';
if ($couponCode !== '' && preg_match('/^[A-Z0-9_-]{3,40}$/D', $couponCode) !== 1) {
    respond(422, ['success' => false, 'message' => 'Coupon code is invalid.']);
}
$coupon = $couponCode === '' ? null : findAvailableCoupon($conn, $couponCode);
$subtotal = round((float) $productRow['price'], 2);
$discountAmount = 0.0;
if ($couponCode !== '') {
    $discountAmount = $coupon ? calculateCouponDiscount($subtotal, $coupon) : null;
    if (!$coupon || $discountAmount === null || $discountAmount <= 0) {
        respond(422, ['success' => false, 'message' => 'Coupon is invalid, expired, fully used, or does not meet its minimum subtotal.']);
    }
}
$couponId = $coupon ? (int) $coupon['id'] : null;

$receipt = $_FILES['receipt'] ?? null;
if (!is_array($receipt) || !isset($receipt['error']) || !is_int($receipt['error']) || $receipt['error'] === UPLOAD_ERR_NO_FILE) {
    respond(422, ['success' => false, 'message' => 'សូមភ្ជាប់វិក្កយបត្រ ឬរូបថតអេក្រង់បង់ប្រាក់។']);
}
if (
    $receipt['error'] !== UPLOAD_ERR_OK
    || !isset($receipt['tmp_name'], $receipt['size'])
    || !is_string($receipt['tmp_name'])
    || !is_int($receipt['size'])
    || !is_uploaded_file($receipt['tmp_name'])
) {
    $message = $receipt['error'] === UPLOAD_ERR_INI_SIZE || $receipt['error'] === UPLOAD_ERR_FORM_SIZE
        ? 'ឯកសារធំពេក។ សូមជ្រើសរើសឯកសារមិនលើស 5 MB។'
        : 'មិនអាចទទួលយកឯកសារបានទេ។ សូមព្យាយាមម្ដងទៀត។';
    respond(422, ['success' => false, 'message' => $message]);
}
if ($receipt['size'] < 1 || $receipt['size'] > 5 * 1024 * 1024) {
    respond(422, ['success' => false, 'message' => 'ឯកសារធំពេក។ សូមជ្រើសរើសឯកសារមិនលើស 5 MB។']);
}

$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$receiptMime = $fileInfo->file($receipt['tmp_name']);
$receiptTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
    'application/pdf' => 'pdf',
];
if (!is_string($receiptMime) || !isset($receiptTypes[$receiptMime])) {
    respond(422, ['success' => false, 'message' => 'ប្រភេទឯកសារមិនត្រឹមត្រូវ។ អនុញ្ញាតតែ JPG, PNG, WEBP, GIF, HEIC ឬ PDF។']);
}
if (!function_exists('curl_init') || !class_exists('CURLFile')) {
    error_log('PHP cURL is required for Telegram order notifications.');
    respond(500, ['success' => false, 'message' => 'សេវាបញ្ជូន Telegram មិនទាន់អាចប្រើបានទេ។ សូមទាក់ទង Admin។']);
}

$botToken = readConfigValue(['Telegram_bot_token', 'TELEGRAM_BOT_TOKEN']);
$chatId = readConfigValue(['Telegram_chat_id', 'TELEGRAM_CHAT_ID']);
if ($botToken === null || $chatId === null) {
    error_log('Telegram order notification is missing bot token or chat ID configuration.');
    respond(503, ['success' => false, 'message' => 'ការជូនដំណឹង Telegram មិនទាន់បានកំណត់ទេ។ សូមទាក់ទង Admin។']);
}

$orderReference = 'ORD-' . date('ymdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
$receiptDirectory = privateReceiptDirectory();
if (!is_dir($receiptDirectory) && !mkdir($receiptDirectory, 0750, true) && !is_dir($receiptDirectory)) {
    error_log('Could not create private receipt storage directory.');
    respond(500, ['success' => false, 'message' => 'មិនអាចរក្សាទុកវិក្កយបត្របានទេ។ សូមទាក់ទង Admin។']);
}
$receiptDirectoryReal = realpath($receiptDirectory);
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$publicDirectory = is_string($documentRoot) && $documentRoot !== '' ? realpath($documentRoot) : false;
if (
    $receiptDirectoryReal === false
    || ($publicDirectory !== false && (
        $receiptDirectoryReal === $publicDirectory
        || strpos($receiptDirectoryReal, $publicDirectory . DIRECTORY_SEPARATOR) === 0
    ))
) {
    error_log('Private receipt storage must resolve outside the public document root.');
    respond(500, ['success' => false, 'message' => 'ទីតាំងរក្សាទុកវិក្កយបត្រមិនមានសុវត្ថិភាពទេ។ សូមទាក់ទង Admin។']);
}
$receiptFilename = 'receipt_' . bin2hex(random_bytes(16)) . '.' . $receiptTypes[$receiptMime];
$receiptPath = $receiptDirectoryReal . DIRECTORY_SEPARATOR . $receiptFilename;
if (!copy($receipt['tmp_name'], $receiptPath)) {
    error_log('Could not store a private copy of the uploaded receipt.');
    respond(500, ['success' => false, 'message' => 'មិនអាចរក្សាទុកវិក្កយបត្របានទេ។ សូមព្យាយាមម្ដងទៀត។']);
}

if (!$conn->begin_transaction()) {
    unlink($receiptPath);
    error_log('Could not start product stock reservation transaction.');
    respond(500, ['success' => false, 'message' => 'មានបញ្ហាក្នុងការកក់ស្តុកផលិតផល។']);
}
$stockLookup = $conn->prepare('SELECT name, stock, low_stock_threshold FROM products WHERE id = ? FOR UPDATE');
if (!$stockLookup) {
    $conn->rollback();
    unlink($receiptPath);
    error_log('Could not prepare product stock reservation lookup.');
    respond(500, ['success' => false, 'message' => 'មានបញ្ហាក្នុងការកក់ស្តុកផលិតផល។']);
}
$stockLookup->bind_param('i', $productId);
if (!$stockLookup->execute()) {
    $stockLookup->close();
    $conn->rollback();
    unlink($receiptPath);
    error_log('Could not reserve product stock.');
    respond(500, ['success' => false, 'message' => 'មានបញ្ហាក្នុងការកក់ស្តុកផលិតផល។']);
}
$lockedProduct = $stockLookup->get_result()->fetch_assoc();
$stockLookup->close();
if (!$lockedProduct || (int) $lockedProduct['stock'] < 1) {
    $conn->rollback();
    unlink($receiptPath);
    respond(409, ['success' => false, 'message' => 'ផលិតផលនេះទើបតែអស់ស្តុក។ សូមជ្រើសរើសផលិតផលផ្សេង។']);
}
$stockBefore = (int) $lockedProduct['stock'];
$stockAfter = $stockBefore - 1;
$reserveStatement = $conn->prepare('UPDATE products SET stock = ? WHERE id = ?');
if (!$reserveStatement) {
    $conn->rollback();
    unlink($receiptPath);
    error_log('Could not prepare product stock reservation.');
    respond(500, ['success' => false, 'message' => 'មានបញ្ហាក្នុងការកក់ស្តុកផលិតផល។']);
}
$reserveStatement->bind_param('ii', $stockAfter, $productId);
$stockReserved = $reserveStatement->execute();
$reserveStatement->close();
$reservationLogged = $stockReserved && recordInventoryAdjustment(
    $conn,
    $productId,
    (string) $productRow['name'],
    -1,
    $stockBefore,
    $stockAfter,
    'Stock reserved for online order ' . $orderReference,
    'Customer checkout'
);
if (!$reservationLogged || !$conn->commit()) {
    $conn->rollback();
    unlink($receiptPath);
    error_log('Product stock reservation could not be confirmed; inspect the inventory audit before retrying.');
    respond(500, ['success' => false, 'message' => 'មិនអាចបញ្ជាក់ការកក់ស្តុកបានទេ។ សូមទាក់ទង Admin មុនពេលព្យាយាមម្ដងទៀត។']);
}

$productName = (string) $productRow['name'];
$productPrice = (string) $productRow['price'];
$currencySymbol = storeCurrencySymbol($conn);
$orderTotal = max(0, round($subtotal - $discountAmount, 2));
$discountDetails = $coupon ? "\nCoupon: {$couponCode}\nDiscount: -{$currencySymbol}" . number_format($discountAmount, 2) : '';
$message = "ការបញ្ជាទិញថ្មី\nOrder: {$orderReference}\nផលិតផល: {$productName}\nតម្លៃ: {$currencySymbol}{$productPrice}{$discountDetails}\nសរុប: {$currencySymbol}" . number_format($orderTotal, 2) . "\nឈ្មោះ: {$customerName}\nលេខទូរស័ព្ទ: {$phoneNumber}\nទីតាំង: {$location}";
$originalName = basename((string) ($receipt['name'] ?? 'payment-receipt'));
$safeBaseName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
$safeBaseName = is_string($safeBaseName) && $safeBaseName !== '' ? substr($safeBaseName, 0, 100) : 'payment-receipt';
$safeFileName = $safeBaseName . '.' . $receiptTypes[$receiptMime];
$curl = curl_init('https://api.telegram.org/bot' . $botToken . '/sendDocument');
if ($curl === false) {
    unlink($receiptPath);
    restoreReservedProductStock($conn, $productId, $orderReference);
    error_log('Could not initialize Telegram document upload.');
    respond(500, ['success' => false, 'message' => 'មិនអាចចាប់ផ្តើមការបញ្ជូន Telegram បានទេ។ សូមព្យាយាមម្ដងទៀត។']);
}
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'chat_id' => $chatId,
        'document' => new CURLFile($receipt['tmp_name'], $receiptMime, $safeFileName),
        'caption' => $message,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);
$responseBody = curl_exec($curl);
$curlError = curl_error($curl);
$httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

if ($responseBody === false) {
    unlink($receiptPath);
    restoreReservedProductStock($conn, $productId, $orderReference);
    error_log('Telegram order notification request failed: ' . $curlError);
    respond(502, ['success' => false, 'message' => 'មិនអាចភ្ជាប់ទៅ Telegram បានទេ។ សូមព្យាយាមម្តងទៀត។']);
}

$telegramResponse = json_decode($responseBody, true);
if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($telegramResponse) || ($telegramResponse['ok'] ?? false) !== true) {
    unlink($receiptPath);
    restoreReservedProductStock($conn, $productId, $orderReference);
    error_log('Telegram rejected an order notification (HTTP ' . $httpStatus . ').');
    respond(502, ['success' => false, 'message' => 'Telegram មិនអាចទទួលព័ត៌មានបានទេ។ សូមព្យាយាមម្តងទៀត។']);
}

$saleStatement = $conn->prepare("INSERT INTO sales_orders (account_id, product_id, product_name, customer_name, phone_number, phone_normalized, location, quantity, unit_price, unit_cost, order_reference, payment_method, status, payment_status, receipt_filename, receipt_mime, inventory_reserved, coupon_id, coupon_code, discount_amount) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 'online', 'Pending', 'pending', ?, ?, 1, ?, ?, ?)");
if (!$saleStatement) {
    unlink($receiptPath);
    restoreReservedProductStock($conn, $productId, $orderReference);
    error_log('Could not prepare accepted Telegram order record.');
    respond(500, ['success' => false, 'message' => 'ការបញ្ជាទិញត្រូវបានផ្ញើ ប៉ុន្តែមិនអាចរក្សាទុកប្រវត្តិបានទេ។ សូមទាក់ទង Admin។']);
}
$unitCost = $productRow['cost_price'] === null ? null : (float) $productRow['cost_price'];
if (!$conn->begin_transaction()) {
    $saleStatement->close();
    unlink($receiptPath);
    restoreReservedProductStock($conn, $productId, $orderReference);
    error_log('Could not start accepted Telegram order transaction.');
    respond(500, ['success' => false, 'message' => 'ការបញ្ជាទិញត្រូវបានផ្ញើ ប៉ុន្តែមិនអាចរក្សាទុកប្រវត្តិបានទេ។ សូមទាក់ទង Admin។']);
}
if ($coupon) {
    $couponLock = $conn->prepare('SELECT id, coupon_code, discount_type, discount_value, minimum_subtotal, max_uses, uses_count, starts_at, expires_at, is_active FROM discount_coupons WHERE id = ? FOR UPDATE');
    if (!$couponLock) {
        $conn->rollback();
        $saleStatement->close();
        unlink($receiptPath);
        restoreReservedProductStock($conn, $productId, $orderReference);
        respond(503, ['success' => false, 'message' => 'Could not confirm the coupon. Please retry.']);
    }
    $couponLock->bind_param('i', $couponId);
    $couponLock->execute();
    $lockedCoupon = $couponLock->get_result()->fetch_assoc();
    $couponLock->close();
    $currentTime = new DateTimeImmutable('now', new DateTimeZone('Asia/Phnom_Penh'));
    $couponStillAvailable = $lockedCoupon
        && (int) $lockedCoupon['is_active'] === 1
        && ($lockedCoupon['starts_at'] === null || new DateTimeImmutable((string) $lockedCoupon['starts_at'], new DateTimeZone('Asia/Phnom_Penh')) <= $currentTime)
        && ($lockedCoupon['expires_at'] === null || new DateTimeImmutable((string) $lockedCoupon['expires_at'], new DateTimeZone('Asia/Phnom_Penh')) > $currentTime)
        && ($lockedCoupon['max_uses'] === null || (int) $lockedCoupon['uses_count'] < (int) $lockedCoupon['max_uses']);
    $lockedDiscount = $couponStillAvailable ? calculateCouponDiscount($subtotal, $lockedCoupon) : null;
    if (
        !$couponStillAvailable
        || strtoupper((string) $lockedCoupon['coupon_code']) !== $couponCode
        || $lockedDiscount === null
        || (int) round($lockedDiscount * 100) !== (int) round($discountAmount * 100)
    ) {
        $conn->rollback();
        $saleStatement->close();
        unlink($receiptPath);
        restoreReservedProductStock($conn, $productId, $orderReference);
        respond(409, ['success' => false, 'message' => 'The coupon changed or reached its usage limit. Please check the total and try again.']);
    }
    $useCoupon = $conn->prepare('UPDATE discount_coupons SET uses_count = uses_count + 1 WHERE id = ? AND (max_uses IS NULL OR uses_count < max_uses)');
    if (!$useCoupon) {
        $conn->rollback();
        $saleStatement->close();
        unlink($receiptPath);
        restoreReservedProductStock($conn, $productId, $orderReference);
        respond(503, ['success' => false, 'message' => 'Could not reserve the coupon. Please retry.']);
    }
    $useCoupon->bind_param('i', $couponId);
    $couponUseSaved = $useCoupon->execute() && $useCoupon->affected_rows === 1;
    $useCoupon->close();
    if (!$couponUseSaved) {
        $conn->rollback();
        $saleStatement->close();
        unlink($receiptPath);
        restoreReservedProductStock($conn, $productId, $orderReference);
        respond(409, ['success' => false, 'message' => 'Coupon usage limit was reached. Please check the total and try again.']);
    }
}
$accountId = (int) $orderUser['id'];
$saleStatement->bind_param('iisssssddsssisd', $accountId, $productId, $productName, $customerName, $phoneNumber, $phoneNormalized, $location, $productPrice, $unitCost, $orderReference, $receiptFilename, $receiptMime, $couponId, $couponCode, $discountAmount);
if (!$saleStatement->execute()) {
    error_log('Could not save accepted Telegram order: ' . $saleStatement->error);
    $saleStatement->close();
    $conn->rollback();
    unlink($receiptPath);
    restoreReservedProductStock($conn, $productId, $orderReference);
    respond(500, ['success' => false, 'message' => 'ការបញ្ជាទិញត្រូវបានផ្ញើ ប៉ុន្តែមិនអាចរក្សាទុកប្រវត្តិបានទេ។ សូមទាក់ទង Admin។']);
}
$orderId = (int) $conn->insert_id;
$saleStatement->close();

$profileStatement = $conn->prepare('INSERT INTO customer_profiles (phone_normalized, phone_number, customer_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE phone_number = VALUES(phone_number), customer_name = VALUES(customer_name)');
if (!$profileStatement) {
    $conn->rollback();
    unlink($receiptPath);
    restoreReservedProductStock($conn, $productId, $orderReference);
    error_log('Could not prepare customer profile update for accepted order.');
    respond(500, ['success' => false, 'message' => 'ការបញ្ជាទិញត្រូវបានផ្ញើ ប៉ុន្តែមិនអាចរក្សាទុកប្រវត្តិអតិថិជនបានទេ។']);
}
$profileStatement->bind_param('sss', $phoneNormalized, $phoneNumber, $customerName);
$profileSaved = $profileStatement->execute();
$profileStatement->close();
if (!$profileSaved || !recordSalesOrderHistory(
    $conn,
    $orderId,
    'order_created',
    null,
    'Pending',
    'Receipt uploaded; payment is not verified.',
    null,
    null,
    'Customer checkout'
)) {
    $conn->rollback();
    unlink($receiptPath);
    restoreReservedProductStock($conn, $productId, $orderReference);
    error_log('Could not save order history for accepted Telegram order.');
    respond(500, ['success' => false, 'message' => 'ការបញ្ជាទិញត្រូវបានផ្ញើ ប៉ុន្តែមិនអាចរក្សាទុកប្រវត្តិពេញលេញបានទេ។ សូមទាក់ទង Admin។']);
}
if (!$conn->commit()) {
    $conn->rollback();
    error_log('Could not confirm accepted Telegram order transaction; preserve reserved stock and receipt for reconciliation.');
    respond(500, ['success' => false, 'message' => 'ការបញ្ជាទិញត្រូវបានផ្ញើ ប៉ុន្តែមិនអាចបញ្ជាក់ការរក្សាទុកបានទេ។ សូមទាក់ទង Admin មុនពេលព្យាយាមម្ដងទៀត។']);
}

if (!createAdminNotification(
    $conn,
    'new_order',
    'New order ' . $orderReference,
    $productName . ' · ' . $customerName . ' · ' . $currencySymbol . number_format($orderTotal, 2),
    'operations.php?view=orders&details=' . $orderId
)) {
    error_log('Order was saved but the admin notification could not be recorded.');
}
if ($stockBefore > (int) $lockedProduct['low_stock_threshold'] && $stockAfter <= (int) $lockedProduct['low_stock_threshold']) {
    if (!createAdminNotification(
        $conn,
        'low_stock',
        'Low stock: ' . $productName,
        'Only ' . $stockAfter . ' unit(s) remain after the order was saved.',
        'operations.php?view=inventory'
    )) {
        error_log('Order was saved but the low-stock notification could not be recorded.');
    }
}

unset($_SESSION['order_csrf_token']);
respond(200, ['success' => true, 'message' => 'បានបញ្ជូនការបញ្ជាទិញ ' . $orderReference . ' ទៅ Telegram រួចរាល់។ ការទូទាត់កំពុងរង់ចាំការផ្ទៀងផ្ទាត់។']);
