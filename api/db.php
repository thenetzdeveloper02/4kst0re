<?php
mysqli_report(MYSQLI_REPORT_OFF);

if (!function_exists('applicationConfigValue')) {
    function applicationConfigValue(string $key): ?string
    {
        static $fileConfig = null;
        $environmentValue = getenv($key);
        if ($environmentValue !== false) {
            return (string) $environmentValue;
        }
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        if ($fileConfig === null) {
            $fileConfig = [];
            $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
            if (is_readable($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $matches)) {
                        $value = trim($matches[2]);
                        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                            $value = substr($value, 1, -1);
                        }
                        $fileConfig[$matches[1]] = $value;
                    }
                }
            }
        }

        return array_key_exists($key, $fileConfig) ? $fileConfig[$key] : null;
    }
}

$appEnvironment = strtolower(trim(applicationConfigValue('APP_ENV') ?? 'local'));
$databaseHost = applicationConfigValue('ECOMMERCE_DB_HOST');
$databaseUser = applicationConfigValue('ECOMMERCE_DB_USER');
$databasePassword = applicationConfigValue('ECOMMERCE_DB_PASSWORD');
$databaseName = applicationConfigValue('ECOMMERCE_DB_NAME');
$databasePort = applicationConfigValue('ECOMMERCE_DB_PORT');

if ($appEnvironment === 'local') {
    $databaseHost = $databaseHost ?? 'localhost';
    $databaseUser = $databaseUser ?? 'root';
    $databasePassword = $databasePassword ?? '';
    $databaseName = $databaseName ?? 'ecommerce';
} elseif (
    !$databaseHost
    || !$databaseUser
    || !$databasePassword
    || !$databaseName
    || strtolower($databaseUser) === 'root'
) {
    error_log('Production database configuration is incomplete or uses the root account.');
    http_response_code(500);
    exit('Database service is not configured.');
}

$databasePortNumber = $databasePort === null || $databasePort === '' ? 3306 : filter_var($databasePort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
if ($databasePortNumber === false) {
    error_log('Invalid database port configuration.');
    http_response_code(500);
    exit('Database service is not configured.');
}

$conn = new mysqli($databaseHost, $databaseUser, $databasePassword, $databaseName, (int) $databasePortNumber);
if ($conn->connect_errno) {
    error_log('Database connection failed (' . $conn->connect_errno . '): ' . $conn->connect_error);
    http_response_code(500);
    exit('Database service is temporarily unavailable.');
}
if (!$conn->set_charset('utf8mb4')) {
    error_log('Could not set the database connection charset: ' . $conn->error);
    http_response_code(500);
    exit('Database service is temporarily unavailable.');
}

if (!function_exists('applicationTableExists')) {
    function applicationTableExists(mysqli $connection, string $table): bool
    {
        $statement = $connection->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        if (!$statement) {
            error_log('Could not prepare database table check: ' . $connection->error);
            return false;
        }
        $statement->bind_param('s', $table);
        $exists = $statement->execute() && $statement->get_result()->num_rows > 0;
        if ($statement->errno !== 0) {
            error_log('Could not check database table: ' . $statement->error);
        }
        $statement->close();
        return $exists;
    }
}

if (!function_exists('ensureAccountLoginHistorySchema')) {
    function ensureAccountLoginHistorySchema(mysqli $connection): bool
    {
        $loginHistoryTable = "CREATE TABLE IF NOT EXISTS account_login_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id INT NOT NULL,
            login_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45) NULL DEFAULT NULL,
            user_agent VARCHAR(512) NOT NULL DEFAULT '',
            login_method VARCHAR(32) NOT NULL DEFAULT 'google',
            PRIMARY KEY (id),
            KEY idx_account_login_history_account_time (account_id, login_at),
            KEY idx_account_login_history_time (login_at),
            CONSTRAINT fk_account_login_history_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$connection->query($loginHistoryTable)) {
            error_log('Could not create account login history table: ' . $connection->error);
            return false;
        }

        return true;
    }
}

if (!function_exists('ensureAccountSessionTrackingSchema')) {
    function ensureAccountSessionTrackingSchema(mysqli $connection): bool
    {
        $columns = [
            'ip_address' => "ALTER TABLE account_sessions ADD COLUMN ip_address VARCHAR(45) NULL DEFAULT NULL AFTER session_token",
            'user_agent' => "ALTER TABLE account_sessions ADD COLUMN user_agent VARCHAR(255) NOT NULL DEFAULT '' AFTER ip_address",
        ];

        foreach ($columns as $column => $migration) {
            $result = $connection->query("SHOW COLUMNS FROM account_sessions LIKE '" . $column . "'");
            if (!$result) {
                error_log('Could not inspect account session tracking schema: ' . $connection->error);
                return false;
            }
            if ($result->num_rows > 0) {
                continue;
            }
            if (!$connection->query($migration)) {
                error_log('Could not add account session tracking column ' . $column . ': ' . $connection->error);
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('ensureAccountProfileSchema')) {
    function ensureAccountProfileSchema(mysqli $connection): bool
    {
        $columns = [
            'phone_number' => "ALTER TABLE account ADD COLUMN phone_number VARCHAR(32) NULL DEFAULT NULL AFTER bio",
            'sex' => "ALTER TABLE account ADD COLUMN sex VARCHAR(16) NULL DEFAULT NULL AFTER phone_number",
            'address' => "ALTER TABLE account ADD COLUMN address VARCHAR(300) NULL DEFAULT NULL AFTER sex",
            'facebook_url' => "ALTER TABLE account ADD COLUMN facebook_url VARCHAR(255) NULL DEFAULT NULL AFTER address",
            'tiktok_url' => "ALTER TABLE account ADD COLUMN tiktok_url VARCHAR(255) NULL DEFAULT NULL AFTER facebook_url",
            'local_avatar' => "ALTER TABLE account ADD COLUMN local_avatar VARCHAR(255) NULL DEFAULT NULL AFTER tiktok_url",
            'date_of_birth' => "ALTER TABLE account ADD COLUMN date_of_birth DATE NULL DEFAULT NULL AFTER sex",
            'cover_photo' => "ALTER TABLE account ADD COLUMN cover_photo VARCHAR(255) NULL DEFAULT NULL AFTER local_avatar",
            'telegram_url' => "ALTER TABLE account ADD COLUMN telegram_url VARCHAR(255) NULL DEFAULT NULL AFTER tiktok_url",
            'instagram_url' => "ALTER TABLE account ADD COLUMN instagram_url VARCHAR(255) NULL DEFAULT NULL AFTER telegram_url",
            'youtube_url' => "ALTER TABLE account ADD COLUMN youtube_url VARCHAR(255) NULL DEFAULT NULL AFTER instagram_url",
        ];

        foreach ($columns as $column => $migration) {
            $result = $connection->query("SHOW COLUMNS FROM account LIKE '" . $column . "'");
            if (!$result) {
                error_log('Could not inspect account profile schema: ' . $connection->error);
                return false;
            }
            if ($result->num_rows > 0) {
                continue;
            }
            if (!$connection->query($migration)) {
                error_log('Could not add account profile column ' . $column . ': ' . $connection->error);
                return false;
            }
        }

        return true;
    }
}

if (!$conn->query("SET time_zone = '+07:00'")) {
    error_log('Could not set the application database time zone: ' . $conn->error);
    die('Could not initialize the application database time zone.');
}

if (!function_exists('ensureProductStockColumn')) {
    function ensureProductStockColumn(mysqli $connection): bool
    {
        $stockColumn = $connection->query("SHOW COLUMNS FROM products LIKE 'stock'");
        if (!$stockColumn) {
            error_log('Could not check product stock schema: ' . $connection->error);
            return false;
        }

        if ($stockColumn->num_rows === 0 && !$connection->query('ALTER TABLE products ADD stock INT UNSIGNED NOT NULL DEFAULT 0')) {
            error_log('Could not add product stock column: ' . $connection->error);
            return false;
        }

        return true;
    }
}

if (!function_exists('ensureSalesAnalyticsSchema')) {
    function ensureSalesAnalyticsSchema(mysqli $connection): bool
    {
        $costColumn = $connection->query("SHOW COLUMNS FROM products LIKE 'cost_price'");
        if (!$costColumn) {
            error_log('Could not check product cost schema: ' . $connection->error);
            return false;
        }

        if ($costColumn->num_rows === 0 && !$connection->query('ALTER TABLE products ADD cost_price DECIMAL(10,2) NULL DEFAULT NULL')) {
            error_log('Could not add product cost column: ' . $connection->error);
            return false;
        }

        $salesTable = "CREATE TABLE IF NOT EXISTS sales_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id INT NULL DEFAULT NULL,
            product_id INT NULL,
            product_name VARCHAR(255) NOT NULL,
            customer_name VARCHAR(100) NOT NULL,
            phone_number VARCHAR(32) NOT NULL,
            location VARCHAR(300) NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL,
            unit_cost DECIMAL(10,2) NULL DEFAULT NULL,
            order_reference VARCHAR(40) NULL DEFAULT NULL,
            payment_method VARCHAR(20) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sales_created_at (created_at),
            KEY idx_sales_product_id (product_id),
            KEY idx_sales_account_created (account_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!applicationTableExists($connection, 'sales_orders') && !$connection->query($salesTable)) {
            error_log('Could not create sales history table: ' . $connection->error);
            return false;
        }

        $accountColumn = $connection->query("SHOW COLUMNS FROM sales_orders LIKE 'account_id'");
        if (!$accountColumn) {
            error_log('Could not check sales order account schema: ' . $connection->error);
            return false;
        }
        if ($accountColumn->num_rows === 0 && !$connection->query('ALTER TABLE sales_orders ADD account_id INT NULL DEFAULT NULL AFTER id')) {
            if ($connection->errno !== 1060) {
                error_log('Could not add sales order account column: ' . $connection->error);
                return false;
            }
            $accountColumn = $connection->query("SHOW COLUMNS FROM sales_orders LIKE 'account_id'");
            if (!$accountColumn || $accountColumn->num_rows === 0) {
                error_log('Sales order account column creation raced with another schema update.');
                return false;
            }
        }

        $accountIndex = $connection->query("SHOW INDEX FROM sales_orders WHERE Key_name = 'idx_sales_account_created'");
        if (!$accountIndex) {
            error_log('Could not check sales order account index: ' . $connection->error);
            return false;
        }
        if ($accountIndex->num_rows === 0 && !$connection->query('ALTER TABLE sales_orders ADD INDEX idx_sales_account_created (account_id, created_at)')) {
            if ($connection->errno !== 1061) {
                error_log('Could not add sales order account index: ' . $connection->error);
                return false;
            }
            $accountIndex = $connection->query("SHOW INDEX FROM sales_orders WHERE Key_name = 'idx_sales_account_created'");
            if (!$accountIndex || $accountIndex->num_rows === 0) {
                error_log('Sales order account index creation raced with another schema update.');
                return false;
            }
        }

        $receivedColumn = $connection->query("SHOW COLUMNS FROM sales_orders LIKE 'customer_received_at'");
        if (!$receivedColumn) {
            error_log('Could not check customer delivery confirmation schema: ' . $connection->error);
            return false;
        }
        if ($receivedColumn->num_rows === 0 && !$connection->query('ALTER TABLE sales_orders ADD customer_received_at DATETIME NULL DEFAULT NULL AFTER account_id')) {
            if ($connection->errno !== 1060) {
                error_log('Could not add customer delivery confirmation column: ' . $connection->error);
                return false;
            }
            $receivedColumn = $connection->query("SHOW COLUMNS FROM sales_orders LIKE 'customer_received_at'");
            if (!$receivedColumn || $receivedColumn->num_rows === 0) {
                error_log('Customer delivery confirmation column creation raced with another schema update.');
                return false;
            }
        }

        $deliveryStatusColumn = $connection->query("SHOW COLUMNS FROM sales_orders LIKE 'customer_delivery_status'");
        if (!$deliveryStatusColumn) {
            error_log('Could not check customer delivery status schema: ' . $connection->error);
            return false;
        }
        if ($deliveryStatusColumn->num_rows === 0 && !$connection->query("ALTER TABLE sales_orders ADD customer_delivery_status VARCHAR(12) NOT NULL DEFAULT 'pending' AFTER customer_received_at")) {
            if ($connection->errno !== 1060) {
                error_log('Could not add customer delivery status column: ' . $connection->error);
                return false;
            }
            $deliveryStatusColumn = $connection->query("SHOW COLUMNS FROM sales_orders LIKE 'customer_delivery_status'");
            if (!$deliveryStatusColumn || $deliveryStatusColumn->num_rows === 0) {
                error_log('Customer delivery status column creation raced with another schema update.');
                return false;
            }
        }

        $orderReferenceColumn = $connection->query("SHOW COLUMNS FROM sales_orders LIKE 'order_reference'");
        if (!$orderReferenceColumn) {
            error_log('Could not check sales order reference schema: ' . $connection->error);
            return false;
        }
        if ($orderReferenceColumn->num_rows === 0 && !$connection->query('ALTER TABLE sales_orders ADD order_reference VARCHAR(40) NULL DEFAULT NULL')) {
            error_log('Could not add sales order reference column: ' . $connection->error);
            return false;
        }

        $paymentMethodColumn = $connection->query("SHOW COLUMNS FROM sales_orders LIKE 'payment_method'");
        if (!$paymentMethodColumn) {
            error_log('Could not check sales payment method schema: ' . $connection->error);
            return false;
        }
        if ($paymentMethodColumn->num_rows === 0 && !$connection->query('ALTER TABLE sales_orders ADD payment_method VARCHAR(20) NULL DEFAULT NULL')) {
            error_log('Could not add sales payment method column: ' . $connection->error);
            return false;
        }

        $activityTable = "CREATE TABLE IF NOT EXISTS product_activity (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id INT NULL,
            product_name VARCHAR(255) NOT NULL,
            action VARCHAR(20) NOT NULL,
            actor VARCHAR(100) NOT NULL,
            old_values TEXT NULL,
            new_values TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_product_activity_created_at (created_at),
            KEY idx_product_activity_product_id (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!applicationTableExists($connection, 'product_activity') && !$connection->query($activityTable)) {
            error_log('Could not create product activity table: ' . $connection->error);
            return false;
        }

        return true;
    }
}

require_once __DIR__ . '/admin-operations.php';
?>
