<?php
require_once __DIR__ . '/../api/google-auth.php';

$nav_home_url     = $nav_home_url    ?? 'index.php';
$nav_about_url    = $nav_about_url   ?? 'index.php?page=about';
$nav_support_url  = $nav_support_url ?? '../customer-service/index.php';
$nav_payments_url = $nav_payments_url ?? '#payments';
$nav_brand_logo   = $nav_brand_logo  ?? '';
$nav_store_name   = $nav_store_name  ?? '4K STORE';
$nav_contact_url  = $nav_contact_url ?? 'https://t.me/nate1234567890';
$nav_admin_url    = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/admin/index.php';
$nav_active       = $nav_active ?? 'home';
$nav_link = static function ($page, $label, $icon, $url) use ($nav_active) {
    $active_class = $nav_active === $page ? ' active' : '';
    echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="nav-tab-btn' . $active_class . '"><i class="fas ' . $icon . '"></i> ' . $label . '</a>';
};

// Reuse already-resolved user
if (!isset($currentUser)) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $currentUser = getCurrentUser($conn);
}
$isLoggedIn = $currentUser !== null;
if (empty($_SESSION['notification_csrf_token']) || !is_string($_SESSION['notification_csrf_token'])) {
    $_SESSION['notification_csrf_token'] = bin2hex(random_bytes(32));
}
$notificationCsrfToken = $_SESSION['notification_csrf_token'];
if (empty($_SESSION['customer_orders_csrf_token']) || !is_string($_SESSION['customer_orders_csrf_token'])) {
    $_SESSION['customer_orders_csrf_token'] = bin2hex(random_bytes(32));
}
$customerOrdersCsrfToken = $_SESSION['customer_orders_csrf_token'];
?>
<header class="cyber-nav-container">
    <nav class="cyber-island-nav" aria-label="Main Navigation">
        <a href="<?php echo htmlspecialchars($nav_home_url, ENT_QUOTES, 'UTF-8'); ?>" class="brand-emblem">
            <div class="brand-hologram-cube">
                <img src="<?php echo htmlspecialchars($nav_brand_logo !== '' ? $nav_brand_logo : (dirname($nav_home_url) === '.' ? './logo/icon.png' : dirname($nav_home_url) . '/logo/icon.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($nav_store_name, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.src='<?php echo htmlspecialchars(dirname($nav_home_url) === '.' ? './logo/logome.png' : dirname($nav_home_url) . '/logo/logome.png', ENT_QUOTES, 'UTF-8'); ?>'">
            </div>
            <div class="brand-titles">
                <h1><?php echo htmlspecialchars($nav_store_name, ENT_QUOTES, 'UTF-8'); ?> <span class="dna-badge">Verified</span></h1>
                <span>Curated Goods & Digital</span>
            </div>
        </a>

        <div class="nav-hologram-links" role="menubar">
            <?php
            $nav_link('home', 'ទំព័រដើម', 'fa-house', $nav_home_url);
            $nav_link('about', 'អំពីយើង', 'fa-circle-info', $nav_about_url);
            $nav_link('support', 'ជំនួយការ', 'fa-headset', $nav_support_url);
            $nav_link('payments', 'ការទូទាត់', 'fa-credit-card', $nav_payments_url);
            ?>
            <div class="theme-mode-control theme-mode-nav">
                <label class="theme-mode-label" for="themeModeSelect">Theme</label>
                <select id="themeModeSelect" data-theme-mode aria-label="Color theme">
                    <option value="system">System</option>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                </select>
            </div>
        </div>

        <div class="nav-actions">
            <div class="notification-widget" data-notification-widget data-role="user" data-endpoint="../api/notifications.php" data-csrf="<?php echo htmlspecialchars($notificationCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="notification-trigger cyber-glow-btn" type="button" aria-expanded="false" aria-controls="storeNotificationPanel" aria-label="Notifications">
                    <i class="fas fa-bell" aria-hidden="true"></i>
                    <span class="notification-count" data-notification-count hidden></span>
                </button>
                <section class="notification-panel" id="storeNotificationPanel" data-notification-panel hidden>
                    <div class="notification-panel-heading"><strong>Notifications</strong><button class="notification-mark-all" type="button" data-notification-mark-all>Mark all read</button></div>
                    <div class="notification-list" data-notification-list><p class="notification-empty">Loading notifications…</p></div>
                </section>
            </div>
            <?php if ($isLoggedIn): ?>
                <div class="customer-orders-widget" data-customer-orders-widget data-endpoint="../api/customer-orders.php" data-csrf="<?php echo htmlspecialchars($customerOrdersCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <button class="customer-orders-trigger cyber-glow-btn" type="button" aria-expanded="false" aria-controls="customerOrdersPanel" aria-label="My orders">
                        <i class="fas fa-cart-shopping" aria-hidden="true"></i>
                        <span class="notification-count" data-orders-count hidden></span>
                    </button>
                    <section class="customer-orders-panel" id="customerOrdersPanel" data-orders-panel hidden>
                        <div class="notification-panel-heading">
                            <strong>My orders</strong>
                            <span data-orders-summary>Recent purchases</span>
                        </div>
                        <div class="customer-orders-list" data-orders-list><p class="notification-empty">Loading orders…</p></div>
                    </section>
                </div>
            <?php else: ?>
                <a href="../api/google-login.php" class="cyber-glow-btn" title="Sign in to view your orders" aria-label="Sign in to view your orders">
                    <i class="fas fa-cart-shopping" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
            <?php if ($isLoggedIn): ?>
                <a href="profile.php" class="cyber-glow-btn" title="គណនីរបស់អ្នក">
                    <?php if (!empty($currentUser['profile_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($currentUser['profile_picture'], ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <i class="fas fa-circle-user" style="color: var(--app-accent);"></i>
                    <?php endif; ?>
                    <span class="nav-action-label"><?php echo htmlspecialchars($currentUser['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
            <?php else: ?>
                <a href="../api/google-login.php" class="cyber-glow-btn" title="Sign in with Google">
                    <i class="fab fa-google" style="color: #4285f4;"></i>
                    <span class="nav-action-label">Sign In</span>
                </a>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($nav_contact_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="cyber-glow-btn" title="Telegram Concierge">
                <i class="fab fa-telegram-plane" style="color: var(--app-accent);"></i>
                <span class="nav-action-label">Telegram</span>
            </a>
            <button class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Toggle navigation" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </nav>
</header>

<div id="mobileMenuDrawer" class="mobile-menu-drawer" hidden>
    <div class="mobile-menu-links">
        <?php
        $nav_link('home', 'ទំព័រដើម', 'fa-house', $nav_home_url);
        $nav_link('about', 'អំពីយើង', 'fa-circle-info', $nav_about_url);
        $nav_link('support', 'ជំនួយការ', 'fa-headset', $nav_support_url);
        $nav_link('payments', 'ការទូទាត់', 'fa-credit-card', $nav_payments_url);
        ?>
        <div class="theme-mode-control theme-mode-mobile" style="margin-top: 8px;">
            <label class="theme-mode-label" for="mobileThemeModeSelect" style="display: inline;">Appearance</label>
            <select id="mobileThemeModeSelect" data-theme-mode aria-label="Color theme">
                <option value="system">System</option>
                <option value="light">Light</option>
                <option value="dark">Dark</option>
            </select>
        </div>
        <?php if ($isLoggedIn): ?>
            <a href="profile.php" class="nav-tab-btn" style="color: var(--app-accent);"><i class="fas fa-circle-user"></i> <?php echo htmlspecialchars($currentUser['name'], ENT_QUOTES, 'UTF-8'); ?></a>
        <?php else: ?>
            <a href="../api/google-login.php" class="nav-tab-btn" style="color: #4285f4;"><i class="fab fa-google"></i> Sign in with Google</a>
        <?php endif; ?>
    </div>
</div>

<script>
    (() => {
        const mobileToggle = document.getElementById("mobileNavToggle");
        const mobileDrawer = document.getElementById("mobileMenuDrawer");
        if (mobileToggle && mobileDrawer) {
            mobileToggle.addEventListener("click", () => {
                const isOpen = mobileToggle.getAttribute("aria-expanded") === "true";
                mobileDrawer.hidden = isOpen;
                mobileToggle.setAttribute("aria-expanded", String(!isOpen));
                const icon = mobileToggle.querySelector("i");
                if (icon) {
                    icon.className = isOpen ? "fas fa-bars" : "fas fa-xmark";
                }
            });

            window.addEventListener("resize", () => {
                if (window.innerWidth > 860 && !mobileDrawer.hidden) {
                    mobileDrawer.hidden = true;
                    mobileToggle.setAttribute("aria-expanded", "false");
                    const icon = mobileToggle.querySelector("i");
                    if (icon) icon.className = "fas fa-bars";
                }
            });
        }
    })();
</script>
<script src="../js/notifications.js?v=<?php echo filemtime(__DIR__ . '/../js/notifications.js'); ?>" defer></script>
<script src="../js/customer-orders.js?v=<?php echo filemtime(__DIR__ . '/../js/customer-orders.js'); ?>" defer></script>
