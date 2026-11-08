<?php
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/google-auth.php';

// Start session and resolve persistent login cookie BEFORE any output
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_indexCurrentUser = getCurrentUser($conn);

// Check for login/logout messages
$loginSuccess  = isset($_GET['login_success']) && $_GET['login_success'] === '1';
$loginError    = isset($_GET['login_error'])   ? $_GET['login_error']   : null;
$logoutSuccess = isset($_GET['logout'])        && $_GET['logout']       === '1';

$storeSettings = [];
$settingsResult = $conn->query('SELECT setting_name, setting_value FROM system_settings');
if ($settingsResult) {
    while ($setting = $settingsResult->fetch_assoc()) {
        $storeSettings[$setting['setting_name']] = $setting['setting_value'];
    }
}
$storeName = $storeSettings['store_name'] ?? '4K STORE';
$storeCurrency = $storeSettings['currency_symbol'] ?? '$';
$nav_brand_logo = $storeSettings['store_logo_url'] ?? '';
$nav_store_name = $storeName;
$nav_contact_url = $storeSettings['store_contact_url'] ?: 'https://t.me/nate1234567890';
$heroTitle = $storeSettings['home_hero_title'] ?? 'គុណភាពខ្ពស់ 100%';
$heroDescription = $storeSettings['home_hero_description'] ?? 'ទិញ-លក់ផលិតផល ប្រកបដោយសុវត្ថិភាព មានការធានាជូន និងគាំទ្រការទូទាត់គ្រប់ធនាគារក្នុងប្រទេសកម្ពុជា។';
$heroBannerUrl = $storeSettings['home_banner_url'] ?? '';

date_default_timezone_set('Asia/Phnom_Penh');
$hour = (int)date('H');
if ($hour < 12) {
    $greeting = "អរុណសួស្តី";
    $greeting_en = "Good Morning";
    $greeting_icon = "☀️";
} elseif ($hour < 18) {
    $greeting = "ទិវាសួស្តី";
    $greeting_en = "Good Afternoon";
    $greeting_icon = "🌤️";
} else {
    $greeting = "រាត្រីសួស្តី";
    $greeting_en = "Good Evening";
    $greeting_icon = "🌙";
}

$page = $_GET['page'] ?? '';
$is_checkout = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item'])) || $page === 'checkout' || isset($_GET['item']);
$orderCsrfToken = '';
$checkoutProvinceOptions = cambodiaProvinceOptions();
$checkoutProfileAddress = is_array($_indexCurrentUser) ? trim((string) ($_indexCurrentUser['address'] ?? '')) : '';
$checkoutProfileAddressDetails = $checkoutProfileAddress;
$checkoutSelectedProvince = '';
foreach ($checkoutProvinceOptions as $provinceKhmer => $provinceEnglish) {
    if (strpos($checkoutProfileAddress, $provinceKhmer) !== false || stripos($checkoutProfileAddress, $provinceEnglish) !== false) {
        $checkoutSelectedProvince = $provinceKhmer;
        $checkoutProfileAddressDetails = trim(
            str_ireplace($provinceEnglish, '', str_replace($provinceKhmer, '', $checkoutProfileAddress)),
            " \t\n\r\0\x0B,-"
        );
        break;
    }
}

if ($is_checkout) {
    $item = $_POST['item'] ?? $_GET['item'] ?? '';
    if (!is_string($item) && !is_int($item)) {
        $item = '';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($item === '') {
            header('Location: index.php', true, 303);
            exit;
        }
        if ($_indexCurrentUser === null) {
            if (!ctype_digit((string) $item) || (int) $item < 1) {
                http_response_code(400);
                exit('Choose a valid product before signing in.');
            }
            $_SESSION['pending_checkout_item'] = (string) $item;
            $_SESSION['pending_checkout_item_expires'] = time() + 600;
            header('Location: ../api/google-login.php', true, 303);
            exit;
        }
        $checkout_url = $item === '' ? 'index.php' : 'index.php?item=' . rawurlencode((string) $item);
        header('Location: ' . $checkout_url, true, 303);
        exit;
    }

    if ($item !== '' && $_indexCurrentUser === null) {
        if (!ctype_digit((string) $item) || (int) $item < 1) {
            http_response_code(400);
            exit('Choose a valid product before signing in.');
        }
        $_SESSION['pending_checkout_item'] = (string) $item;
        $_SESSION['pending_checkout_item_expires'] = time() + 600;
        header('Location: ../api/google-login.php', true, 303);
        exit;
    }

    if (empty($_SESSION['order_csrf_token'])) {
        $_SESSION['order_csrf_token'] = bin2hex(random_bytes(32));
    }
    $orderCsrfToken = $_SESSION['order_csrf_token'];

    $product = null;
    if ($item !== '' && ctype_digit((string) $item)) {
        if (isset($conn) && !$conn->connect_error && !ensureProductStockColumn($conn)) {
            http_response_code(500);
            exit('Product inventory is temporarily unavailable.');
        }
        if (isset($conn) && !$conn->connect_error) {
            $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND stock > 0");
            if ($stmt) {
                $stmt->bind_param("i", $item);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $product = [
                        'name' => $row['name'],
                        'price' => $row['price'],
                        'img' => !empty($row['image'])
                            ? (preg_match('~^https?://~i', $row['image']) ? $row['image'] : '../' . $row['image'])
                            : './logo/logome.png',
                        'desc' => 'ផលិតផលមានគុណភាពខ្ពស់ និងមានការធានាជូន'
                    ];
                }
                $stmt->close();
            }
        }
    }

    if (!$product && $item !== '') {
        http_response_code(404);
        exit('This product is out of stock or no longer available.');
    }

    if (!$product) {
        $product = [
            'name' => 'Special Order',
            'price' => 'Special Offer',
            'img' => './logo/logome.png',
            'desc' => 'សូមទាក់ទងមកកាន់ Admin តាម Telegram ដើម្បីពិនិត្យតម្លៃ និងទិញ'
        ];
    }
}

// --------------------------------------------------------------------------
// 1. About Page Rendering
// --------------------------------------------------------------------------
if ($page === 'about' && !$is_checkout):
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>អំពីយើង • <?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="./logo/logome.png" type="image/x-icon">
    <link rel="stylesheet" href="./page.css?v=<?php echo filemtime(__DIR__ . '/page.css'); ?>">
    <link rel="stylesheet" href="../css/theme-mode.css?v=<?php echo filemtime(__DIR__ . '/../css/theme-mode.css'); ?>">
    <script src="../js/page-navigation.js?v=<?php echo filemtime(__DIR__ . '/../js/page-navigation.js'); ?>" defer></script>
</head>
<body>
    <div class="app-content-layer">
        <?php
        $nav_active = 'about';
        $nav_home_url = 'index.php';
        $nav_about_url = 'index.php?page=about';
        $nav_support_url = '../customer-service/index.php';
        $nav_payments_url = 'index.php#payments';
        require __DIR__ . '/navbar.php';
        ?>

        <main class="about-cyber-layer">
            <section class="about-section">
                <div class="about-section-heading">
                    <span>Who We Are</span>
                    <h3>អំពី <?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p>យើងផ្តល់ជូននូវបទពិសោធន៍ទិញផលិតផល និងសេវាកម្មឌីជីថលប្រកបដោយភាពងាយស្រួល តម្លាភាព និងទំនុកចិត្តខ្ពស់បំផុត។</p>
                </div>
                <div class="about-story-grid">
                    <article class="about-info-card">
                        <div class="about-icon"><i class="fas fa-users"></i></div>
                        <h4>យើងជានរណា?</h4>
                        <p><?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?> ជាហាងលក់ផលិតផល និងសេវាកម្មឌីជីថលដែលត្រូវបានជ្រើសរើសយ៉ាងយកចិត្តទុកដាក់ សម្រាប់តម្រូវការការងារ និងការកម្សាន្តប្រចាំថ្ងៃរបស់អ្នក។</p>
                    </article>
                    <article class="about-info-card">
                        <div class="about-icon"><i class="fas fa-bullseye"></i></div>
                        <h4>បេសកកម្មរបស់យើង</h4>
                        <p>បេសកកម្មចម្បងគឺផ្តល់សេវាកម្មរហ័សទាន់ចិត្ត មានការធានាត្រឹមត្រូវ ព្រមទាំងការទូទាត់ប្រាក់ងាយស្រួលតាមប្រព័ន្ធ KHQR Bakong។</p>
                    </article>
                    <article class="about-info-card">
                        <div class="about-icon"><i class="fas fa-eye"></i></div>
                        <h4>ចក្ខុវិស័យរបស់យើង</h4>
                        <p>ក្លាយជាវេទិកាទិញ-លក់ទំនិញឌីជីថលឈានមុខគេក្នុងប្រទេសកម្ពុជា ដែលផ្តោតលើភាពស្មោះត្រង់ និងការពេញចិត្តរបស់អតិថិជនជាចម្បង។</p>
                    </article>
                    <article class="about-info-card">
                        <div class="about-icon"><i class="fas fa-heart"></i></div>
                        <h4>អតិថិជនជាចម្បង</h4>
                        <p>ក្រុមការងារជំនួយការផ្ទាល់របស់យើងរង់ចាំឆ្លើយតប និងដោះស្រាយរាល់ចម្ងល់របស់អ្នក 24 ម៉ោងក្នុងមួយថ្ងៃ 7 ថ្ងៃក្នុងមួយសប្តាហ៍។</p>
                    </article>
                </div>
            </section>

            <section class="about-section">
                <div class="about-section-heading">
                    <span>Our Principles</span>
                    <h3>គោលការណ៍ដែលយើងប្រកាន់ខ្ជាប់</h3>
                    <p>គុណតម្លៃស្នូលដែលដឹកនាំរាល់សកម្មភាព និងការបម្រើអតិថិជនរបស់យើង។</p>
                </div>
                <div class="about-values-grid">
                    <article class="about-value-card">
                        <div class="about-icon"><i class="fas fa-circle-check"></i></div>
                        <h4>ភាពច្បាស់លាស់</h4>
                        <p>រាល់ព័ត៌មានផលិតផល តម្លៃ និងលក្ខខណ្ឌប្រើប្រាស់ត្រូវបានបញ្ជាក់យ៉ាងច្បាស់ គ្មានការចំណាយលាក់កំបាំងឡើយ។</p>
                    </article>
                    <article class="about-value-card">
                        <div class="about-icon"><i class="fas fa-shield-halved"></i></div>
                        <h4>សុវត្ថិភាពខ្ពស់</h4>
                        <p>ការពារទិន្នន័យ និងព័ត៌មានផ្ទាល់ខ្លួនរបស់អ្នកជាមួយស្តង់ដារសុវត្ថិភាពទំនើបបំផុត។</p>
                    </article>
                    <article class="about-value-card">
                        <div class="about-icon"><i class="fas fa-handshake"></i></div>
                        <h4>ទំនួលខុសត្រូវ</h4>
                        <p>យើងទទួលខុសត្រូវ 100% លើរាល់ការបញ្ជាទិញ ជាមួយគោលការណ៍ធានា និងការប្តូរប្រាក់សងវិញតាមលក្ខខណ្ឌ។</p>
                    </article>
                </div>
            </section>

            <section class="about-contact">
                <span class="about-eyebrow"><i class="fas fa-headset"></i> We're Here To Help</span>
                <h3>មានសំណួរអំពីផលិតផល ឬការបញ្ជាទិញ?</h3>
                <p>ទាក់ទងមកកាន់ក្រុមការងារ concierge របស់យើងតាម Telegram គ្រប់ពេលវេលា ដើម្បីទទួលបានការប្រឹក្សា និងជំនួយរហ័ស។</p>
                <a href="<?php echo htmlspecialchars($nav_contact_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="fashion-shop-cta">
                    <i class="fab fa-telegram-plane"></i> ទាក់ទង <?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?>
                </a>
            </section>

            <footer class="about-page-footer">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?> • All rights reserved.
            </footer>
        </main>
    </div>
    <script src="../js/theme-mode.js?v=<?php echo filemtime(__DIR__ . '/../js/theme-mode.js'); ?>"></script>
</body>
</html>
<?php
exit;
endif;
?>

<!-- ------------------------------------------------------------------------
     2. Main Storefront & Catalog
-------------------------------------------------------------------------- -->
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?> • Store</title>
    <!-- Icons & Typography -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="./logo/logome.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="./logo/logome.png">
    <!-- Apple HIG Design System -->
    <link rel="stylesheet" href="./page.css?v=<?php echo filemtime(__DIR__ . '/page.css'); ?>">
    <link rel="stylesheet" href="../css/theme-mode.css?v=<?php echo filemtime(__DIR__ . '/../css/theme-mode.css'); ?>">
    <script src="../js/page-navigation.js?v=<?php echo filemtime(__DIR__ . '/../js/page-navigation.js'); ?>" defer></script>
</head>

<body class="<?php echo $is_checkout ? 'buy-modal-open' : ''; ?>">

    <!-- Login/Logout Toast Notification -->
    <?php if ($loginSuccess || $loginError || $logoutSuccess): ?>
        <div class="cyber-notify-overlay" role="presentation" id="authNotification">
            <section class="cyber-notify-card" role="alertdialog" aria-modal="true" tabindex="-1">
                <div class="cyber-notify-icon">
                    <?php if ($loginSuccess): ?>
                        <i class="fas fa-circle-check" style="color: var(--app-success);"></i>
                    <?php elseif ($logoutSuccess): ?>
                        <i class="fas fa-arrow-right-from-bracket" style="color: var(--app-accent);"></i>
                    <?php else: ?>
                        <i class="fas fa-circle-exclamation" style="color: var(--app-danger);"></i>
                    <?php endif; ?>
                </div>
                <h3>
                    <?php if ($loginSuccess): ?>
                        ចូលប្រព័ន្ធជោគជ័យ
                    <?php elseif ($logoutSuccess): ?>
                        បានចាកចេញពីប្រព័ន្ធ
                    <?php else: ?>
                        ការជូនដំណឹង
                    <?php endif; ?>
                </h3>
                <p>
                    <?php if ($loginSuccess): ?>
                        សូមស្វាគមន៍! អ្នកបានចូលប្រើប្រាស់ដោយជោគជ័យជាមួយគណនី Google។
                    <?php elseif ($logoutSuccess): ?>
                        អ្នកបានចាកចេញដោយជោគជ័យ។ សូមអរគុណ!
                    <?php else: ?>
                        <?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </p>
                <button type="button" class="fashion-shop-cta" style="width: 100%; justify-content: center;" onclick="document.getElementById('authNotification').remove();">
                    យល់ព្រម
                </button>
            </section>
        </div>
        <script>
            setTimeout(() => {
                const notif = document.getElementById('authNotification');
                if (notif) {
                    notif.style.opacity = '0';
                    setTimeout(() => notif.remove(), 250);
                }
            }, 4500);
        </script>
    <?php endif; ?>

    <!-- Success Modal Center Screen -->
    <div id="cyberSuccessModal" class="cyber-notify-overlay" role="presentation" hidden>
        <section class="cyber-notify-card" role="alertdialog" aria-modal="true" aria-labelledby="successNotifyTitle" aria-describedby="successNotifyMessage" tabindex="-1">
            <div class="cyber-notify-icon">
                <i class="fas fa-circle-check"></i>
            </div>
            <h3 id="successNotifyTitle">បញ្ជូនព័ត៌មានជោគជ័យ!</h3>
            <p id="successNotifyMessage">ការបញ្ជាទិញ និងវិក្កយបត្ររបស់អ្នកត្រូវបានផ្ញើទៅកាន់ Telegram Admin រួចរាល់ហើយ។</p>
            <button type="button" id="closeNotifyBtn" class="fashion-shop-cta" style="width: 100%; justify-content: center;">
                យល់ព្រម
            </button>
        </section>
    </div>

    <!-- App Content Layer -->
    <div class="app-content-layer">
        <?php
        $nav_active = 'home';
        $nav_home_url = 'index.php';
        $nav_about_url = 'index.php?page=about';
        $nav_support_url = '../customer-service/index.php';
        $nav_payments_url = '#payments';
        require __DIR__ . '/navbar.php';
        ?>

        <!-- Hero Showcase Deck -->
        <main class="hero-deck">
            <div class="hero-centerpiece">
                <div class="hero-text-side">
                    <div class="hero-tag-pill">
                        <i class="fas fa-sparkles"></i> <?php echo $greeting; ?> • <?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <h2><?php echo htmlspecialchars($heroTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p><?php echo htmlspecialchars($heroDescription, ENT_QUOTES, 'UTF-8'); ?></p>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <a class="fashion-shop-cta" href="#productsCatalogMatrix">
                            <span>ទិញផលិតផលឥឡូវនេះ</span>
                            <i class="fas fa-arrow-down" aria-hidden="true"></i>
                        </a>
                        <a class="cyber-glow-btn" href="<?php echo htmlspecialchars($nav_contact_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" style="height: 48px; padding: 0 20px; font-size: 14.5px;">
                            <i class="fab fa-telegram-plane" style="color: var(--app-accent);"></i>
                            <span>សួរព័ត៌មានបន្ថែម</span>
                        </a>
                    </div>
                    <div class="hero-stats-row">
                        <div class="stat-chip">
                            <div class="stat-num">99.9<span>%</span></div>
                            <div class="stat-label">Uptime</div>
                        </div>
                        <div class="stat-chip">
                            <div class="stat-num">100<span>%</span></div>
                            <div class="stat-label">Full Warranty</div>
                        </div>
                        <div class="stat-chip">
                            <div class="stat-num">10K<span>+</span></div>
                            <div class="stat-label">Customers</div>
                        </div>
                    </div>
                </div>

                <div class="hero-visual-side" aria-hidden="true">
                    <?php if ($heroBannerUrl !== ''): ?>
                        <img class="hero-managed-banner" src="<?php echo htmlspecialchars($heroBannerUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                    <?php else: ?>
                        <span class="hero-brand-mark">4K</span>
                    <?php endif; ?>
                    <span class="hero-brand-caption"><?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?> • OFFICIAL STORE</span>
                </div>
            </div>
        </main>

        <!-- Catalog Section -->
        <section class="catalog-header" id="catalog">
            <div class="catalog-heading-copy">
                <span class="catalog-kicker">CURATED COLLECTION</span>
                <h2>ស្វែងរកផលិតផលដែលអ្នកចូលចិត្ត</h2>
                <p>រើសយកផលិតផល ឬសេវាកម្មដែលស័ក្តិសមជាមួយតម្រូវការរបស់អ្នក</p>
            </div>

            <!-- Apple Search & Segmented Filter Bar -->
            <div class="catalog-filter-container">
                <div class="apple-search-wrapper">
                    <i class="fas fa-magnifying-glass apple-search-icon"></i>
                    <input type="text" id="liveSearchInput" class="apple-search-input" placeholder="ស្វែងរកផលិតផល... (Search products)" autocomplete="off" aria-label="Search products">
                </div>

                <div class="segmented-filter-bar" role="tablist" aria-label="Product Categories">
                    <button type="button" class="cyber-category-pill active" data-category="all" role="tab" aria-selected="true">ទាំងអស់</button>
                    <button type="button" class="cyber-category-pill" data-category="ai" role="tab" aria-selected="false">AI Tools</button>
                    <button type="button" class="cyber-category-pill" data-category="design" role="tab" aria-selected="false">Design & Art</button>
                    <button type="button" class="cyber-category-pill" data-category="streaming" role="tab" aria-selected="false">Entertainment</button>
                    <button type="button" class="cyber-category-pill" data-category="social" role="tab" aria-selected="false">Social & Other</button>
                </div>
            </div>
        </section>

        <!-- Product Cards Matrix -->
        <div class="cyber-cards-matrix" id="productsCatalogMatrix">
            <?php
            if (isset($conn) && !$conn->connect_error && !ensureProductStockColumn($conn)) {
                http_response_code(500);
                exit('Product inventory is temporarily unavailable.');
            }
            if (isset($conn) && !$conn->connect_error) {
                $db_result = $conn->query("SELECT * FROM products WHERE stock > 0 ORDER BY id DESC");
                if ($db_result && $db_result->num_rows > 0) {
                    while ($p = $db_result->fetch_assoc()) {
                        $p_name = htmlspecialchars($p['name']);
                        $p_price = htmlspecialchars($p['price']);
                        $p_img = !empty($p['image'])
                            ? (preg_match('~^https?://~i', $p['image']) ? htmlspecialchars($p['image'], ENT_QUOTES, 'UTF-8') : '../' . htmlspecialchars($p['image'], ENT_QUOTES, 'UTF-8'))
                            : './logo/logome.png';
                        $p_id = htmlspecialchars($p['id']);

                        $cat = "other";
                        $name_lower = strtolower($p['name']);
                        if (strpos($name_lower, 'gpt') !== false || strpos($name_lower, 'ai') !== false || strpos($name_lower, 'gemini') !== false) {
                            $cat = "ai";
                        } elseif (strpos($name_lower, 'capcut') !== false || strpos($name_lower, 'pic') !== false || strpos($name_lower, 'art') !== false || strpos($name_lower, 'motion') !== false) {
                            $cat = "design";
                        } elseif (strpos($name_lower, 'sportify') !== false || strpos($name_lower, 'spotify') !== false || strpos($name_lower, 'youtube') !== false || strpos($name_lower, 'netflix') !== false) {
                            $cat = "streaming";
                        } elseif (strpos($name_lower, 'tiktok') !== false || strpos($name_lower, 'telegram') !== false || strpos($name_lower, 'meta') !== false) {
                            $cat = "social";
                        }
            ?>
                        <article class="cyber-card" data-category="<?php echo $cat; ?>" data-name="<?php echo strtolower($p_name); ?>">
                            <span class="card-top-tag"><i class="fas fa-check"></i> មានក្នុងស្តុក</span>
                            <div class="card-hologram-frame">
                                <img src="<?php echo $p_img; ?>" alt="<?php echo $p_name; ?>" loading="lazy" onerror="this.src='./logo/logome.png'">
                            </div>
                            <h3 class="card-app-title"><?php echo $p_name; ?></h3>
                            <div class="card-feature-specs">
                                <span><i class="fas fa-shield-halved" style="color:var(--app-success);"></i> ធានាគុណភាព 100%</span>
                            </div>
                            <div class="cyber-price-pill">
                                <?php echo htmlspecialchars($storeCurrency, ENT_QUOTES, 'UTF-8'); ?><?php echo $p_price; ?> <span>/ មួយ</span>
                            </div>
                            <div class="card-action-wrap">
                                <form method="post" action="index.php">
                                    <input type="hidden" name="item" value="<?php echo $p_id; ?>">
                                    <button type="submit" class="cyber-buy-action">
                                        <i class="fas fa-cart-shopping"></i> ទិញឥឡូវនេះ
                                    </button>
                                </form>
                            </div>
                        </article>
            <?php
                    }
                } else {
            ?>
                    <p class="catalog-empty-state">មិនទាន់មានផលិតផលសម្រាប់លក់នៅឡើយទេ (No products available)</p>
            <?php
                }
            }
            ?>
        </div>

        <!-- Capabilities & Trust Grid -->
        <div class="cyber-trust-grid">
            <div class="cyber-trust-card">
                <div class="trust-glow-icon"><i class="fas fa-bolt"></i></div>
                <div class="trust-texts">
                    <h4>ដឹកជញ្ជូនរហ័ស</h4>
                    <p>Instant Auto-Delivery</p>
                </div>
            </div>
            <div class="cyber-trust-card">
                <div class="trust-glow-icon" style="color: var(--app-success); background: var(--app-success-bg);"><i class="fas fa-shield-halved"></i></div>
                <div class="trust-texts">
                    <h4>ធានាសុវត្ថិភាព 100%</h4>
                    <p>100% Verified & Safe</p>
                </div>
            </div>
            <div class="cyber-trust-card">
                <div class="trust-glow-icon" style="color: var(--app-khqr-red); background: rgba(225, 25, 40, 0.1);"><i class="fas fa-qrcode"></i></div>
                <div class="trust-texts">
                    <h4>ទូទាត់រហ័ស KHQR</h4>
                    <p>Bakong & All Banks</p>
                </div>
            </div>
            <div class="cyber-trust-card">
                <div class="trust-glow-icon"><i class="fas fa-headset"></i></div>
                <div class="trust-texts">
                    <h4>ជំនួយផ្ទាល់ 24/7</h4>
                    <p>Telegram Support Desk</p>
                </div>
            </div>
        </div>

        <!-- Payment Partners & Footer -->
        <footer class="cyber-footer" id="payments">
            <h3>
                <i class="fas fa-credit-card" style="color: var(--app-accent);"></i>
                ដៃគូសហការ និងវិធីទូទាត់ប្រាក់
            </h3>
            <p>ទទួលការទូទាត់រហ័សតាមរយៈប្រព័ន្ធ KHQR និងគ្រប់ធនាគារក្នុងប្រទេសកម្ពុជា</p>
            <div class="bank-capsules-row">
                <div class="bank-cyber-chip">
                    <img src="../img/khqr.png" alt="KHQR" onerror="this.style.display='none'">
                    <span>Bakong KHQR</span>
                </div>
                <div class="bank-cyber-chip">
                    <img src="../img/aba.png" alt="ABA Bank" onerror="this.style.display='none'">
                    <span>ABA Bank</span>
                </div>
                <div class="bank-cyber-chip">
                    <img src="../img/wink.png" alt="Wing Bank" onerror="this.style.display='none'">
                    <span>Wing Bank</span>
                </div>
                <div class="bank-cyber-chip">
                    <img src="../img/aceleda.png" alt="ACLEDA Bank" onerror="this.style.display='none'">
                    <span>ACLEDA</span>
                </div>
                <div class="bank-cyber-chip">
                    <img src="../img/canadia.png" alt="Canadia Bank" onerror="this.style.display='none'">
                    <span>Canadia</span>
                </div>
            </div>
            <div style="font-size: 12.5px; color: var(--app-label-tertiary); padding-top: 18px;">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?> • All rights reserved.
            </div>
        </footer>
    </div>

    <!-- Apple HIG Checkout Sheet Modal -->
    <?php if ($is_checkout): ?>
        <div class="buy-checkout-modal" id="buyCheckoutModal" role="presentation">
            <section class="checkout-cyber-card" role="dialog" aria-modal="true" aria-labelledby="buyCheckoutTitle" tabindex="-1">
                <div class="checkout-modal-heading">
                    <span class="dna-badge"><i class="fas fa-shield-halved"></i> 100% Secure Checkout</span>
                    <button type="button" class="checkout-modal-close" aria-label="បិទផ្ទាំងទូទាត់" data-close-checkout>
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
                
                <div class="card-hologram-frame checkout-product-image">
                    <img src="<?php echo htmlspecialchars($product['img']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                </div>
                <h2 id="buyCheckoutTitle"><?php echo htmlspecialchars($product['name']); ?></h2>
                <p class="checkout-product-description"><?php echo htmlspecialchars($product['desc']); ?></p>
                
                <div class="cyber-price-pill checkout-product-price">
                    <?php echo htmlspecialchars($storeCurrency, ENT_QUOTES, 'UTF-8'); ?><?php echo htmlspecialchars($product['price'], ENT_QUOTES, 'UTF-8'); ?> <span>/ មួយ</span>
                </div>

                <?php if ($item !== '' && ctype_digit((string) $item) && is_numeric($product['price'])): ?>
                    <div class="checkout-coupon-panel">
                        <label for="checkoutCouponCode">Discount code</label>
                        <div class="checkout-coupon-row">
                            <input id="checkoutCouponCode" name="coupon_code" type="text" maxlength="40" autocomplete="off" placeholder="Enter coupon code">
                            <button id="applyCheckoutCoupon" type="button" class="fashion-shop-cta" style="padding: 0 16px; height: 38px;">Apply</button>
                        </div>
                        <p id="checkoutCouponStatus" class="checkout-coupon-status" role="status" aria-live="polite"></p>
                        <p id="checkoutDiscountLine" hidden>Discount: <strong></strong></p>
                        <p id="checkoutTotalLine" style="margin-top: 6px; font-weight: 600;">Total: <strong><?php echo htmlspecialchars($storeCurrency, ENT_QUOTES, 'UTF-8'); ?><?php echo number_format((float) $product['price'], 2); ?></strong></p>
                    </div>
                <?php endif; ?>

                <div class="checkout-payment-instructions">
                    <div><i class="fas fa-circle-info"></i> របៀបទូទាត់ប្រាក់៖</div>
                    1. ស្កេន QR Code ឬថតរូបអេក្រង់ (Screenshot)<br>
                    2. បំពេញព័ត៌មានខាងក្រោម និងភ្ជាប់វិក្កយបត្រដើម្បីផ្ញើទៅ Admin
                </div>

                <div class="qr-cyber-frame">
                    <img src="../img/khqr.png" alt="KHQR Payment" onerror="this.src='./logo/icon.png'">
                </div>

                <form class="checkout-order-form" id="checkoutOrderForm" action="../api/telegram-order.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($orderCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="item" value="<?php echo htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8'); ?>">

                    <!-- Customer Name -->
                    <label for="checkoutName">ឈ្មោះអតិថិជន</label>
                    <input id="checkoutName" name="customer_name" type="text" maxlength="100" autocomplete="name" value="<?php echo htmlspecialchars((string) ($_indexCurrentUser['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="នាម/ឈ្មោះ" required>

                    <!-- Phone Number -->
                    <label for="checkoutPhone">លេខទូរស័ព្ទ</label>
                    <input id="checkoutPhone" name="phone_number" type="tel" inputmode="tel" autocomplete="tel" value="<?php echo htmlspecialchars((string) ($_indexCurrentUser['phone_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="បញ្ចូលលេខទូរស័ព្ទ" required>

                    <!-- Location -->
                    <label for="checkoutLocation">ទីតាំង</label>
                    <select id="checkoutLocation" name="location" required>
                        <option value="" disabled <?php echo $checkoutSelectedProvince === '' ? 'selected' : ''; ?>>ជ្រើសរើសរាជធានី/ខេត្ត</option>
                        <?php foreach ($checkoutProvinceOptions as $provinceKhmer => $provinceEnglish): ?>
                            <option value="<?php echo htmlspecialchars($provinceKhmer, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $checkoutSelectedProvince === $provinceKhmer ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($provinceKhmer . ' (' . $provinceEnglish . ')', ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="checkoutLocationDetails">អាសយដ្ឋានលម្អិត (ផ្ទះ/ផ្លូវ/ភូមិ)</label>
                    <input id="checkoutLocationDetails" name="location_details" type="text" maxlength="260" autocomplete="street-address" value="<?php echo htmlspecialchars($checkoutProfileAddressDetails, ENT_QUOTES, 'UTF-8'); ?>" placeholder="បញ្ចូលអាសយដ្ឋានលម្អិត">

                    <!-- Receipt Upload -->
                    <label for="checkoutReceipt">វិក្កយបត្រ ឬរូបថតអេក្រង់បង់ប្រាក់</label>
                    <label class="checkout-receipt-upload" for="checkoutReceipt">
                        <i class="fas fa-cloud-arrow-up"></i>
                        <span>ជ្រើសរើសរូបភាព ឬ PDF នៃការបង់ប្រាក់</span>
                        <small>JPG, PNG, WEBP, HEIC ឬ PDF — ទំហំអតិបរមា 5 MB</small>
                    </label>
                    <input id="checkoutReceipt" class="checkout-receipt-input" name="receipt" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf" required>
                    <span class="checkout-receipt-filename" id="checkoutReceiptFilename">មិនទាន់បានជ្រើសរើសឯកសារ</span>
                    
                    <div class="checkout-order-status" id="checkoutOrderStatus" role="status" aria-live="polite"></div>

                    <button type="submit" class="cyber-buy-action checkout-telegram-action">
                        <i class="fab fa-telegram-plane"></i> បញ្ជូនព័ត៌មាន និងវិក្កយបត្រ
                    </button>
                </form>

                <div class="checkout-delivery-note">
                    <i class="fas fa-clock"></i> Admin នឹងបញ្ជាក់ការបញ្ជាទិញ និងផ្ញើព័ត៌មានជូនតាម Telegram ភ្លាមៗ
                </div>
            </section>
        </div>

        <script>
            (() => {
                document.documentElement.classList.add('buy-modal-open');
                const modal = document.getElementById('buyCheckoutModal');
                const dialog = modal.querySelector('[role="dialog"]');
                const closeModal = () => {
                    modal.hidden = true;
                    document.body.classList.remove('buy-modal-open');
                    document.documentElement.classList.remove('buy-modal-open');
                };

                modal.querySelector('[data-close-checkout]').addEventListener('click', closeModal);
                modal.addEventListener('click', (event) => {
                    if (event.target === modal) closeModal();
                });
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && !modal.hidden) closeModal();
                });

                const orderForm = document.getElementById('checkoutOrderForm');
                const successModal = document.getElementById('cyberSuccessModal');
                const closeSuccessModal = () => {
                    successModal.hidden = true;
                    document.body.classList.remove('buy-modal-open');
                    document.documentElement.classList.remove('buy-modal-open');
                };
                const closeNotifyButton = document.getElementById('closeNotifyBtn');
                closeNotifyButton.addEventListener('click', closeSuccessModal);

                const receiptInput = document.getElementById('checkoutReceipt');
                const receiptFilename = document.getElementById('checkoutReceiptFilename');
                const orderStatus = document.getElementById('checkoutOrderStatus');
                const couponCodeInput = document.getElementById('checkoutCouponCode');
                const applyCouponButton = document.getElementById('applyCheckoutCoupon');
                const storeCurrency = <?= json_encode($storeCurrency, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const submitButton = orderForm.querySelector('button[type="submit"]');
                const submitButtonContent = submitButton.innerHTML;

                receiptInput.addEventListener('change', () => {
                    const receipt = receiptInput.files[0];
                    receiptFilename.textContent = receipt
                        ? `${receipt.name} (${(receipt.size / (1024 * 1024)).toFixed(2)} MB)`
                        : 'មិនទាន់បានជ្រើសរើសឯកសារ';
                });

                if (couponCodeInput && applyCouponButton) {
                    const couponStatus = document.getElementById('checkoutCouponStatus');
                    const discountLine = document.getElementById('checkoutDiscountLine');
                    const totalLine = document.getElementById('checkoutTotalLine');
                    const checkoutSubtotal = Number(<?= json_encode((float) $product['price']) ?>);
                    const resetCouponPreview = () => {
                        discountLine.hidden = true;
                        totalLine.querySelector('strong').textContent = `${storeCurrency}${checkoutSubtotal.toFixed(2)}`;
                    };
                    couponCodeInput.addEventListener('input', () => {
                        resetCouponPreview();
                        couponStatus.textContent = '';
                        couponStatus.dataset.state = '';
                    });
                    applyCouponButton.addEventListener('click', async () => {
                        const couponCode = couponCodeInput.value.trim();
                        if (!couponCode) {
                            couponStatus.dataset.state = 'error';
                            couponStatus.textContent = 'Enter a discount code.';
                            return;
                        }
                        applyCouponButton.disabled = true;
                        couponStatus.dataset.state = '';
                        couponStatus.textContent = 'Checking code...';
                        try {
                            const response = await fetch('../api/validate-coupon.php', {
                                method: 'POST',
                                body: new URLSearchParams({ product_id: orderForm.elements.namedItem('item').value, coupon_code: couponCode })
                            });
                            const data = await response.json();
                            if (!response.ok || data.success !== true) throw new Error(data.message || 'Coupon could not be applied.');
                            couponCodeInput.value = data.coupon_code;
                            discountLine.hidden = false;
                            discountLine.querySelector('strong').textContent = `-${storeCurrency}${Number(data.discount).toFixed(2)}`;
                            totalLine.querySelector('strong').textContent = `${storeCurrency}${Number(data.total).toFixed(2)}`;
                            couponStatus.dataset.state = 'success';
                            couponStatus.textContent = 'Discount applied.';
                        } catch (error) {
                            resetCouponPreview();
                            couponStatus.dataset.state = 'error';
                            couponStatus.textContent = error.message || 'Coupon could not be applied.';
                        } finally {
                            applyCouponButton.disabled = false;
                        }
                    });
                }

                orderForm.addEventListener('submit', (e) => {
                    e.preventDefault();

                    const receipt = receiptInput.files[0];
                    if (!receipt) {
                        orderStatus.dataset.state = 'error';
                        orderStatus.textContent = 'សូមភ្ជាប់វិក្កយបត្រ ឬរូបថតអេក្រង់បង់ប្រាក់។';
                        receiptInput.focus();
                        return;
                    }
                    if (receipt.size > 5 * 1024 * 1024) {
                        orderStatus.dataset.state = 'error';
                        orderStatus.textContent = 'ឯកសារធំពេក។ សូមជ្រើសរើសឯកសារមិនលើស 5 MB។';
                        receiptInput.focus();
                        return;
                    }

                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> កំពុងបញ្ជូន...';
                    orderStatus.dataset.state = '';
                    orderStatus.textContent = 'កំពុងបញ្ជូនព័ត៌មាន និងវិក្កយបត្រទៅ Admin...';

                    fetch(orderForm.action, {
                        method: 'POST',
                        body: new FormData(orderForm)
                    })
                    .then(async (response) => {
                        const data = await response.json();
                        if (!response.ok || data.success !== true) {
                            throw new Error(data.message || 'មិនអាចបញ្ជូនការបញ្ជាទិញបានទេ។');
                        }
                        modal.hidden = true;
                        successModal.hidden = false;
                        closeNotifyButton.focus();
                    })
                    .catch((error) => {
                        orderStatus.dataset.state = 'error';
                        orderStatus.textContent = error.message || 'មានបញ្ហាក្នុងការតភ្ជាប់បណ្តាញ!';
                    })
                    .finally(() => {
                        submitButton.disabled = false;
                        submitButton.innerHTML = submitButtonContent;
                    });
                });

                window.history.replaceState({}, document.title, 'index.php');
                dialog.focus();

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && !successModal.hidden) {
                        closeSuccessModal();
                    }
                });
            })();
        </script>
    <?php endif; ?>

    <!-- Scroll To Top Button -->
    <button id="cyberScrollTop" aria-label="Scroll to top">
        <i class="fas fa-chevron-up"></i>
    </button>

    <!-- Client Interactive Logic (Search, Segmented Filter, Scroll Top) -->
    <script>
        (() => {
            const searchInput = document.getElementById("liveSearchInput");
            const cards = document.querySelectorAll(".cyber-card");
            const categoryPills = document.querySelectorAll(".cyber-category-pill");

            function filterProducts() {
                const query = (searchInput ? searchInput.value : "").trim().toLowerCase();
                const activeTab = document.querySelector(".cyber-category-pill.active");
                const activeCat = activeTab ? activeTab.getAttribute("data-category") : "all";

                cards.forEach((card) => {
                    const name = card.getAttribute("data-name") || "";
                    const cat = card.getAttribute("data-category") || "all";
                    const matchSearch = query === "" || name.includes(query);
                    const matchCategory = activeCat === "all" || cat === activeCat;

                    if (matchSearch && matchCategory) {
                        card.style.display = "flex";
                    } else {
                        card.style.display = "none";
                    }
                });
            }

            if (searchInput) {
                searchInput.addEventListener("input", filterProducts);
            }

            categoryPills.forEach((pill) => {
                pill.addEventListener("click", () => {
                    categoryPills.forEach(p => {
                        p.classList.remove("active");
                        p.setAttribute("aria-selected", "false");
                    });
                    pill.classList.add("active");
                    pill.setAttribute("aria-selected", "true");
                    filterProducts();
                });
            });

            // Scroll to Top
            const scrollBtn = document.getElementById("cyberScrollTop");
            window.addEventListener("scroll", () => {
                if (!scrollBtn) return;
                if (window.scrollY > 300) {
                    scrollBtn.style.display = "flex";
                } else {
                    scrollBtn.style.display = "none";
                }
            });
            if (scrollBtn) {
                scrollBtn.addEventListener("click", () => {
                    window.scrollTo({ top: 0, behavior: "smooth" });
                });
            }
        })();
    </script>
    <script src="../js/theme-mode.js?v=<?php echo filemtime(__DIR__ . '/../js/theme-mode.js'); ?>"></script>
</body>
</html>
