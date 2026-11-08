<?php
if (!function_exists('renderAdminPrimaryNavigation')) {
    function renderAdminPrimaryNavigation(array $admin, string $activeSection): void
    {
        $can = static fn(string $permission): bool => adminHasPermission($admin, $permission);
        $settingsPermissions = ['store_settings', 'promotions', 'notifications', 'security', 'backup'];
        $canOpenSettings = false;
        foreach ($settingsPermissions as $permission) {
            $canOpenSettings = $canOpenSettings || $can($permission);
        }
        $items = [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fa-gauge-high', 'url' => 'index.php', 'visible' => true],
            ['key' => 'products', 'label' => 'Products', 'icon' => 'fa-boxes-stacked', 'url' => $can('products') ? 'index.php' : 'operations.php?view=inventory', 'visible' => $can('products') || $can('inventory')],
            ['key' => 'orders', 'label' => 'Orders', 'icon' => 'fa-receipt', 'url' => 'operations.php?view=orders', 'visible' => $can('orders')],
            ['key' => 'customers', 'label' => 'Customers', 'icon' => 'fa-users', 'url' => $can('customers') ? 'operations.php?view=customers' : '../customer-service/dashboard.php', 'visible' => $can('customers') || $can('support')],
            ['key' => 'login-history', 'label' => 'Login history', 'icon' => 'fa-clock-rotate-left', 'url' => 'operations.php?view=login-history', 'visible' => $can('customers')],
            ['key' => 'reports', 'label' => 'Reports', 'icon' => 'fa-chart-line', 'url' => 'operations.php?view=reports', 'visible' => $can('reports')],
        ];
        echo '<nav class="admin-primary-nav" aria-label="Admin sections">';
        echo '<a class="admin-primary-brand" href="index.php"><div class="brand-hologram-cube"><img src="../home/logo/icon.png" alt="4K STORE" onerror="this.src=\'../home/logo/logome.png\'"></div><span>4K STORE<small>Admin Portal</small></span></a>';
        echo '<div class="notification-widget notification-widget-admin" data-notification-widget data-role="admin" data-endpoint="../api/notifications.php" data-csrf="' . htmlspecialchars(adminCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
        echo '<button class="notification-trigger" type="button" aria-expanded="false" aria-controls="adminNotificationPanel" aria-label="Notifications"><i class="fas fa-bell" aria-hidden="true"></i><span class="notification-count" data-notification-count hidden></span></button>';
        echo '<section class="notification-panel" id="adminNotificationPanel" data-notification-panel hidden><div class="notification-panel-heading"><strong>Notifications</strong><button class="notification-mark-all" type="button" data-notification-mark-all>Mark all read</button></div><div class="notification-list" data-notification-list><p class="notification-empty">Loading notifications…</p></div>';
        if ($can('notifications')) {
            echo '<a class="notification-footer" href="store-management.php?view=notifications">Manage notifications</a>';
        }
        echo '</section>';
        echo '</div>';
        echo '<span class="admin-primary-label">Workspace</span>';
        foreach ($items as $item) {
            if (!$item['visible']) {
                continue;
            }
            $active = $activeSection === $item['key'] ? ' is-active' : '';
            echo '<a class="admin-primary-nav-link' . $active . '" href="' . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') . '"><i class="fas ' . $item['icon'] . '" aria-hidden="true"></i><span>' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</span></a>';
        }
        if ($canOpenSettings) {
            $settingsActive = in_array($activeSection, ['settings', 'promotions', 'homepage', 'notifications', 'security', 'backup'], true) ? ' is-active' : '';
            echo '<a class="admin-primary-nav-link' . $settingsActive . '" href="store-management.php?view=store"><i class="fas fa-gear" aria-hidden="true"></i><span>Settings</span></a>';
            echo '<div class="admin-settings-subnav">';
            if ($can('promotions')) {
                $active = $activeSection === 'promotions' ? ' is-active' : '';
                echo '<a class="admin-settings-link' . $active . '" href="store-management.php?view=promotions">Promotions</a>';
            }
            if ($can('store_settings')) {
                $active = $activeSection === 'homepage' ? ' is-active' : '';
                echo '<a class="admin-settings-link' . $active . '" href="store-management.php?view=homepage">Homepage</a>';
            }
            if ($can('notifications')) {
                $active = $activeSection === 'notifications' ? ' is-active' : '';
                echo '<a class="admin-settings-link' . $active . '" href="store-management.php?view=notifications">Notifications</a>';
            }
            if ($can('security')) {
                $active = $activeSection === 'security' ? ' is-active' : '';
                echo '<a class="admin-settings-link' . $active . '" href="security.php">Staff &amp; Security</a>';
            } elseif (isset($admin['id'])) {
                echo '<a class="admin-settings-link" href="security.php">Account Security</a>';
            }
            if ($can('backup')) {
                $active = $activeSection === 'backup' ? ' is-active' : '';
                echo '<a class="admin-settings-link' . $active . '" href="backup.php">Database Backup</a>';
            }
            if ($can('store_settings')) {
                $active = $activeSection === 'settings' ? ' is-active' : '';
                echo '<a class="admin-settings-link' . $active . '" href="store-management.php?view=store">Store Settings</a>';
            }
            echo '</div>';
        }
        echo '<form class="admin-sidebar-logout" action="logout.php" method="post"><input type="hidden" name="csrf_token" value="' . htmlspecialchars(adminCsrfToken(), ENT_QUOTES, 'UTF-8') . '"><button type="submit"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Log out</span></button></form>';
        echo '</nav>';
        echo '<script src="../js/notifications.js?v=' . (int) filemtime(__DIR__ . '/../js/notifications.js') . '" defer></script>';
    }
}
