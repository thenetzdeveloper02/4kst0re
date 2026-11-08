<?php
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/google-auth.php';

$user = requireUserLogin($conn, 'index.php');

// Check admin
$adminEmailsRaw = applicationConfigValue('ADMIN_GOOGLE_EMAILS') ?? '';
$adminEmails    = array_filter(array_map('strtolower', array_map('trim', explode(',', $adminEmailsRaw))));
$isAdminUser    = in_array(strtolower(trim($user['email'] ?? '')), $adminEmails, true);

// Store settings
$storeSettings = [];
$settingsResult = $conn->query('SELECT setting_name, setting_value FROM system_settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $storeSettings[$row['setting_name']] = $row['setting_value'];
    }
}
$storeName        = $storeSettings['store_name'] ?? '4K STORE';
$nav_brand_logo   = $storeSettings['store_logo_url'] ?? '';
$nav_store_name   = $storeName;
$nav_contact_url  = $storeSettings['store_contact_url'] ?: 'https://t.me/nate1234567890';

// CSRF token
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['profile_csrf_token'])) {
    $_SESSION['profile_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken     = $_SESSION['profile_csrf_token'];
$updateSuccess = false;
$updateError   = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals($csrfToken, $submitted)) {
        $updateError = 'Security validation failed. Please refresh and try again.';
    } else {
        $name     = trim($_POST['name']         ?? '');
        $bio      = trim($_POST['bio']          ?? '');
        $phone    = trim($_POST['phone_number'] ?? '');
        $sex      = trim($_POST['sex']          ?? '');
        $address  = trim($_POST['address']      ?? '');
        $facebook = trim($_POST['facebook_url'] ?? '');
        $tiktok   = trim($_POST['tiktok_url']   ?? '');

        if ($name === '') {
            $updateError = 'Display name is required.';
        } elseif (strlen($name) > 100) {
            $updateError = 'Name is too long (max 100 characters).';
        } elseif (strlen($bio) > 500) {
            $updateError = 'Bio is too long (max 500 characters).';
        } elseif ($phone !== '' && !preg_match('/^\+?[\d\s\-()]{6,20}$/', $phone)) {
            $updateError = 'Phone number format is invalid.';
        } elseif (!in_array($sex, ['', 'male', 'female', 'other'], true)) {
            $updateError = 'Invalid gender selection.';
        } elseif (strlen($address) > 300) {
            $updateError = 'Address is too long (max 300 characters).';
        } elseif ($facebook !== '' && !filter_var($facebook, FILTER_VALIDATE_URL)) {
            $updateError = 'Facebook URL is invalid.';
        } elseif ($tiktok !== '' && !filter_var($tiktok, FILTER_VALIDATE_URL)) {
            $updateError = 'TikTok URL is invalid.';
        } else {
            $userId = (int) $user['id'];
            $stmt = $conn->prepare('UPDATE account SET name=?,bio=?,phone_number=?,sex=?,address=?,facebook_url=?,tiktok_url=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            if ($stmt) {
                $stmt->bind_param('sssssssi', $name, $bio, $phone, $sex, $address, $facebook, $tiktok, $userId);
                if ($stmt->execute()) {
                    $updateSuccess = true;
                    $user['name'] = $name; $user['bio'] = $bio;
                    $user['phone_number'] = $phone; $user['sex'] = $sex;
                    $user['address'] = $address; $user['facebook_url'] = $facebook;
                    $user['tiktok_url'] = $tiktok;
                } else { $updateError = 'Failed to update profile.'; }
                $stmt->close();
            } else { $updateError = 'Database error.'; }
        }
    }
}

$purchaseHistory = [];
$purchaseHistoryError = '';
if (!ensureSalesAnalyticsSchema($conn) || !ensureAdminOperationsSchema($conn)) {
    $purchaseHistoryError = 'Purchase history is temporarily unavailable.';
} else {
    $purchaseStatement = $conn->prepare(
        "SELECT s.id, s.order_reference, s.product_name, s.quantity, s.unit_price,
                s.discount_amount, s.status, s.payment_status, s.customer_delivery_status,
                s.customer_received_at, s.admin_delivery_confirmed_at,
                s.admin_delivery_confirmed_by, s.location, s.created_at,
                p.image AS product_image
         FROM sales_orders s
         LEFT JOIN products p ON p.id = s.product_id
         WHERE s.account_id = ?
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT 100"
    );
    if (!$purchaseStatement) {
        error_log('Could not prepare profile purchase history: ' . $conn->error);
        $purchaseHistoryError = 'Purchase history is temporarily unavailable.';
    } else {
        $profileAccountId = (int) $user['id'];
        $purchaseStatement->bind_param('i', $profileAccountId);
        if (!$purchaseStatement->execute()) {
            error_log('Could not load profile purchase history: ' . $purchaseStatement->error);
            $purchaseHistoryError = 'Purchase history is temporarily unavailable.';
        } else {
            $purchaseResult = $purchaseStatement->get_result();
            while ($purchase = $purchaseResult->fetch_assoc()) {
                $image = (string) ($purchase['product_image'] ?? '');
                if (preg_match('~^https?://~i', $image) !== 1) {
                    $image = $image !== '' ? '../' . ltrim($image, '/') : './logo/logome.png';
                }
                $purchase['product_image_url'] = $image;
                $purchaseHistory[] = $purchase;
            }
        }
        $purchaseStatement->close();
    }
}

function getAvatarUrl(array $user): string {
    if (!empty($user['local_avatar'])) return '../' . htmlspecialchars($user['local_avatar'], ENT_QUOTES, 'UTF-8') . '?v=' . time();
    if (!empty($user['profile_picture'])) return htmlspecialchars($user['profile_picture'], ENT_QUOTES, 'UTF-8');
    return '';
}

$nav_active       = 'profile';
$nav_home_url     = 'index.php';
$nav_about_url    = 'index.php?page=about';
$nav_support_url  = '../customer-service/index.php';
$nav_payments_url = 'index.php#payments';
// Pass $currentUser to navbar so it doesn't call getCurrentUser() again after output
$currentUser = $user;
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile • <?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="./logo/logome.png" type="image/x-icon">
    <link rel="stylesheet" href="./page.css?v=<?php echo filemtime(__DIR__ . '/page.css'); ?>">
    <link rel="stylesheet" href="./fashion-theme.css?v=<?php echo filemtime(__DIR__ . '/fashion-theme.css'); ?>">
    <link rel="stylesheet" href="../css/theme-mode.css?v=<?php echo filemtime(__DIR__ . '/../css/theme-mode.css'); ?>">
    <script src="../js/page-navigation.js?v=<?php echo filemtime(__DIR__ . '/../js/page-navigation.js'); ?>" defer></script>
    <style>
        .profile-cyber-layer {
            position: relative;
            z-index: 10;
            width: min(980px, 100%);
            margin: 32px auto 70px;
            padding: 0 16px;
        }

        .profile-section {
            background: var(--app-surface-solid);
            border: 1px solid var(--app-border);
            border-radius: var(--radius-xl);
            box-shadow: var(--card-shadow);
            padding: clamp(24px, 4vw, 42px);
            margin-bottom: 24px;
        }

        .profile-hero {
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 28px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--app-border);
            flex-wrap: wrap;
        }

        .avatar-ring {
            position: relative;
            width: 96px; height: 96px;
            flex-shrink: 0;
        }
        .avatar-ring img,
        .avatar-ring .avatar-placeholder {
            width: 96px; height: 96px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--app-border);
            box-shadow: var(--card-shadow);
            display: block;
        }
        .avatar-ring .avatar-placeholder {
            display: flex; align-items: center; justify-content: center;
            background: var(--app-surface-secondary);
            font-size: 38px; color: var(--app-accent);
        }
        .avatar-edit-btn {
            position: absolute; bottom: 2px; right: 2px;
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--app-action-bg); color: var(--app-action-text);
            border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; transition: transform var(--duration-fast) var(--ease-apple);
        }
        .avatar-edit-btn:hover { transform: scale(1.15); }
        .profile-meta h2 {
            color: var(--app-label-primary); font-size: clamp(20px,4vw,28px);
            font-weight: 700; margin: 0 0 5px;
        }
        .profile-meta p {
            color: var(--app-label-secondary); font-size: 13.5px; margin: 3px 0;
        }
        .badge-admin {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 12px; border-radius: var(--radius-pill); margin-top: 7px;
            background: var(--app-accent-surface);
            border: 1px solid rgba(0,113,227,0.15);
            color: var(--app-accent);
            font-size: 11px; font-weight: 600;
        }

        .profile-label {
            display: flex; align-items: center; gap: 10px;
            margin: 26px 0 14px;
        }
        .profile-label span {
            color: var(--app-accent);
            text-transform: uppercase;
            font-size: 11.5px; font-weight: 700;
            letter-spacing: .12em; white-space: nowrap;
        }
        .profile-label::after {
            content: ''; flex: 1; height: 1px;
            background: var(--app-border);
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 16px;
        }
        .profile-grid .span-full { grid-column: 1 / -1; }

        .profile-field {
            border: 1px solid var(--app-border-subtle);
            border-radius: var(--radius-lg);
            background: var(--app-surface-secondary);
            padding: 16px 18px;
            display: flex; flex-direction: column; gap: 8px;
        }
        .profile-field label {
            color: var(--app-label-secondary);
            font-size: 11.5px; font-weight: 600;
            letter-spacing: .05em; text-transform: uppercase;
            display: flex; align-items: center; gap: 6px;
        }
        .profile-field input,
        .profile-field textarea,
        .profile-field select {
            width: 100%; padding: 10px 14px;
            background: var(--app-surface-solid);
            border: 1px solid var(--app-border);
            border-radius: var(--radius-sm);
            color: var(--app-label-primary); font-size: 14.5px; font-family: inherit;
            transition: all var(--duration-fast) var(--ease-apple);
            box-sizing: border-box;
        }
        .profile-field input:focus,
        .profile-field textarea:focus,
        .profile-field select:focus {
            outline: none;
            border-color: var(--app-accent);
            box-shadow: 0 0 0 3px var(--app-accent-surface);
        }
        .profile-field textarea { min-height: 88px; resize: vertical; }

        .social-row {
            display: flex; align-items: center;
            background: var(--app-surface-solid);
            border: 1px solid var(--app-border);
            border-radius: var(--radius-sm); overflow: hidden;
            transition: border-color var(--duration-fast);
        }
        .social-row:focus-within { border-color: var(--app-accent); }
        .social-row .social-ico {
            padding: 10px 14px;
            background: var(--app-surface-secondary);
            border-right: 1px solid var(--app-border);
            font-size: 15px; color: var(--app-accent); flex-shrink: 0;
        }
        .social-row input {
            flex:1; border:none!important; border-radius:0!important;
            background:transparent!important; padding:10px 14px;
        }

        .p-alert {
            padding: 14px 18px; border-radius: var(--radius-md);
            margin-bottom: 16px; font-size: 14px;
            display: flex; align-items: center; gap: 10px;
        }
        .p-alert-ok  { background:var(--app-success-bg); border:1px solid rgba(52,199,89,.25); color:var(--app-success); }
        .p-alert-err { background:var(--app-danger-bg); border:1px solid rgba(255,59,48,.25); color:var(--app-danger); }

        .profile-btn-row { display:flex; gap:12px; flex-wrap:wrap; margin-top:24px; }
        .purchase-history-list {
            display: grid;
            gap: 12px;
        }
        .purchase-history-card {
            display: grid;
            grid-template-columns: 72px minmax(0, 1fr);
            align-items: center;
            gap: 16px;
            padding: 14px;
            border: 1px solid var(--app-border-subtle);
            border-radius: var(--radius-lg);
            background: var(--app-surface-secondary);
        }
        .purchase-history-image {
            width: 72px;
            height: 72px;
            border: 1px solid var(--app-border);
            border-radius: var(--radius-md);
            background: var(--app-surface-solid);
            object-fit: cover;
        }
        .purchase-history-details {
            display: grid;
            min-width: 0;
            gap: 5px;
            color: var(--app-label-secondary);
            font-size: 12px;
            overflow-wrap: anywhere;
        }
        .purchase-history-name {
            color: var(--app-label-primary);
            font-size: 15px;
            font-weight: 700;
        }
        .purchase-history-status {
            width: fit-content;
            padding: 3px 9px;
            border-radius: var(--radius-pill);
            background: var(--app-accent-surface);
            color: var(--app-accent);
            font-size: 11px;
            font-weight: 600;
        }
        .purchase-history-status.is-complete {
            background: var(--app-success-bg);
            color: var(--app-success);
        }
        .purchase-history-status.is-cancelled {
            background: var(--app-danger-bg);
            color: var(--app-danger);
        }
        .purchase-history-status.is-delivery-failed {
            background: var(--app-danger-bg);
            color: var(--app-danger);
        }
        .purchase-history-actions,
        .purchase-history-contact-links {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }
        .purchase-history-action,
        .purchase-history-contact-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 7px 12px;
            border: 1px solid var(--app-border);
            border-radius: var(--radius-pill);
            background: var(--app-surface-solid);
            color: var(--app-label-primary);
            font: inherit;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .purchase-history-action:disabled {
            cursor: wait;
            opacity: .65;
        }
        .purchase-history-received-button {
            border-color: rgba(52,199,89,.3);
            color: var(--app-success);
        }
        .purchase-history-contact-links a:hover,
        .purchase-history-action:hover {
            background: var(--app-surface-hover);
        }
        .purchase-history-contact-links[hidden] {
            display: none;
        }
        .purchase-history-action-error {
            color: var(--app-danger);
            font-size: 12px;
        }
        .purchase-history-empty {
            margin: 0;
            padding: 20px;
            border: 1px dashed var(--app-border-strong);
            border-radius: var(--radius-lg);
            color: var(--app-label-secondary);
            text-align: center;
        }
        .btn-admin-dash {
            border-color: var(--app-border)!important;
            background: var(--app-surface-secondary)!important;
            color: var(--app-label-primary)!important;
        }
        .btn-admin-dash:hover {
            background: var(--app-surface-hover)!important;
        }
        .btn-logout-dash {
            border-color: rgba(255,59,48,.25)!important;
            background: var(--app-danger-bg)!important;
            color: var(--app-danger)!important;
        }
        .btn-logout-dash:hover {
            background: rgba(255,59,48,.2)!important;
        }

        #avatarUploadOverlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.45); backdrop-filter:blur(16px);
            z-index:9999; align-items:center; justify-content:center;
            padding: 20px;
        }
        #avatarUploadOverlay.active { display:flex; }
        .avatar-upload-card {
            background:var(--app-surface-solid);
            border:1px solid var(--app-border);
            border-radius:var(--radius-xl); padding:32px 24px;
            width:min(420px,94vw); text-align:center;
            box-shadow:var(--modal-shadow);
        }
        .avatar-upload-card h3 {
            color:var(--app-label-primary); margin:0 0 6px;
            font-size:20px; font-weight:700;
        }
        .avatar-upload-card > p { color:var(--app-label-secondary); font-size:13.5px; margin-bottom:18px; }
        .avatar-drop-zone {
            border:2px dashed var(--app-border-strong); border-radius:var(--radius-lg);
            padding:28px 16px; cursor:pointer; margin-bottom:12px;
            background: var(--app-surface-secondary);
            transition:all var(--duration-fast) var(--ease-apple);
        }
        .avatar-drop-zone:hover,
        .avatar-drop-zone.drag-over { background:var(--app-accent-surface); border-color:var(--app-accent); }
        .avatar-drop-zone i   { font-size:32px; color:var(--app-accent); display:block; margin-bottom:8px; }
        .avatar-drop-zone p   { color:var(--app-label-secondary); font-size:13px; margin:0; }
        #avatarPreview        { width:84px; height:84px; border-radius:50%; object-fit:cover; border:2px solid var(--app-border); margin:10px auto; display:none; }
        .upload-progress      { height:3px; background:rgba(255,255,255,.1); border-radius:99px; overflow:hidden; margin:10px 0; display:none; }
        .upload-progress-bar  { height:100%; background:var(--cyber-cyan); width:0; transition:width .3s; }

        /* ── Responsive ── */
        @media(max-width:760px){
            .profile-cyber-layer{padding:0 14px;margin-top:16px;}
            .profile-grid{grid-template-columns:1fr;}
            .profile-grid .span-full{grid-column:1;}
        }
        @media(max-width:520px){
            .profile-section{border-radius:22px;}
            .profile-hero{flex-direction:column;align-items:center;text-align:center;}
            .purchase-history-card{grid-template-columns:56px minmax(0,1fr);gap:12px;padding:11px;}
            .purchase-history-image{width:56px;height:56px;}
        }
    </style>
</head>
<body class="fashion-storefront">
    <canvas id="dna-canvas"></canvas>
    <div class="canvas-overlay-vignette"></div>
    <div class="cyber-grid-lines"></div>

    <?php require __DIR__ . '/navbar.php'; ?>

    <!-- ── Avatar Upload Overlay ── -->
    <div id="avatarUploadOverlay">
        <div class="avatar-upload-card">
            <h3><i class="fas fa-camera" style="color:var(--cyber-cyan);margin-right:8px;"></i>Change Photo</h3>
            <p>JPG, PNG, WebP or GIF · Max 5 MB</p>
            <div class="avatar-drop-zone" id="avatarDropZone">
                <i class="fas fa-cloud-upload-alt"></i>
                <p>Drag & drop or click to select</p>
            </div>
            <img id="avatarPreview" src="" alt="Preview">
            <div class="upload-progress" id="uploadProgress">
                <div class="upload-progress-bar" id="uploadProgressBar"></div>
            </div>
            <p id="uploadMsg" style="font-size:13px;min-height:18px;color:var(--text-muted);margin:6px 0;"></p>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:12px;">
                <button type="button" id="confirmUploadBtn" class="cyber-glow-btn" style="display:none;padding:9px 20px;">
                    <i class="fas fa-check"></i> Upload
                </button>
                <button type="button" id="cancelUploadBtn" class="cyber-glow-btn btn-logout-dash" style="padding:9px 20px;">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
            <input type="file" id="avatarFileInput" accept="image/*" style="display:none;">
        </div>
    </div>

    <!-- ── Profile Page ── -->
    <div class="profile-cyber-layer">
        <div class="profile-section">

            <!-- Avatar + info -->
            <div class="profile-hero">
                <div class="avatar-ring">
                    <?php $avatarUrl = getAvatarUrl($user); ?>
                    <?php if ($avatarUrl): ?>
                        <img id="currentAvatarImg" src="<?php echo $avatarUrl; ?>" alt="Avatar">
                    <?php else: ?>
                        <div class="avatar-placeholder" id="currentAvatarImg">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                    <button type="button" class="avatar-edit-btn" id="openAvatarUpload" title="Change photo">
                        <i class="fas fa-camera"></i>
                    </button>
                </div>
                <div class="profile-meta">
                    <h2><?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p><i class="fas fa-envelope" style="color:var(--cyber-cyan);margin-right:6px;"></i><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p><i class="fas fa-calendar-days" style="color:var(--cyber-cyan);margin-right:6px;"></i>Member since <?php echo !empty($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : 'Recently'; ?></p>
                    <?php if ($isAdminUser): ?>
                        <div class="badge-admin"><i class="fas fa-shield-halved"></i> Administrator</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($updateSuccess): ?>
                <div class="p-alert p-alert-ok"><i class="fas fa-circle-check"></i> Profile updated successfully!</div>
            <?php endif; ?>
            <?php if ($updateError): ?>
                <div class="p-alert p-alert-err"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($updateError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <!-- Personal Information -->
                <div class="profile-label"><span><i class="fas fa-user"></i> Personal Information</span></div>
                <div class="profile-grid">
                    <div class="profile-field">
                        <label><i class="fas fa-signature"></i> Display Name *</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="100" placeholder="Your display name">
                    </div>
                    <div class="profile-field">
                        <label><i class="fas fa-phone"></i> Phone Number</label>
                        <input type="tel" name="phone_number" value="<?php echo htmlspecialchars($user['phone_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" maxlength="20" placeholder="+855 12 345 678">
                    </div>
                    <div class="profile-field">
                        <label><i class="fas fa-venus-mars"></i> Gender</label>
                        <select name="sex">
                            <option value="">— Prefer not to say —</option>
                            <option value="male"   <?php echo ($user['sex'] ?? '') === 'male'   ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($user['sex'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="other"  <?php echo ($user['sex'] ?? '') === 'other'  ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="profile-field">
                        <label><i class="fas fa-location-dot"></i> Address</label>
                        <input type="text" name="address" value="<?php echo htmlspecialchars($user['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" maxlength="300" placeholder="City, Country">
                    </div>
                    <div class="profile-field span-full">
                        <label><i class="fas fa-pen-to-square"></i> Bio</label>
                        <textarea name="bio" maxlength="500" placeholder="Tell us about yourself..."><?php echo htmlspecialchars($user['bio'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>

                <!-- Social Media -->
                <div class="profile-label"><span><i class="fas fa-share-nodes"></i> Social Media</span></div>
                <div class="profile-grid">
                    <div class="profile-field">
                        <label><i class="fab fa-facebook"></i> Facebook</label>
                        <div class="social-row">
                            <span class="social-ico"><i class="fab fa-facebook-f" style="color:#4267B2;"></i></span>
                            <input type="url" name="facebook_url" value="<?php echo htmlspecialchars($user['facebook_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://facebook.com/yourprofile" maxlength="255">
                        </div>
                    </div>
                    <div class="profile-field">
                        <label><i class="fab fa-tiktok"></i> TikTok</label>
                        <div class="social-row">
                            <span class="social-ico"><i class="fab fa-tiktok"></i></span>
                            <input type="url" name="tiktok_url" value="<?php echo htmlspecialchars($user['tiktok_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://tiktok.com/@youraccount" maxlength="255">
                        </div>
                    </div>
                </div>

                <!-- Buttons -->
                <div class="profile-btn-row">
                    <button type="submit" name="update_profile" class="cyber-glow-btn" style="padding:10px 22px;">
                        <i class="fas fa-floppy-disk"></i> Save Changes
                    </button>
                    <?php if ($isAdminUser): ?>
                        <a href="../admin/index.php" class="cyber-glow-btn btn-admin-dash" style="padding:10px 22px;text-decoration:none;">
                            <i class="fas fa-shield-halved"></i> Admin Dashboard
                        </a>
                    <?php endif; ?>
                    <a href="../api/logout.php" class="cyber-glow-btn btn-logout-dash" style="padding:10px 22px;text-decoration:none;" onclick="return confirm('Logout from your account?');">
                        <i class="fas fa-right-from-bracket"></i> Logout
                    </a>
                </div>
            </form>

            <section aria-labelledby="purchaseHistoryTitle">
                <div class="profile-label"><span id="purchaseHistoryTitle"><i class="fas fa-bag-shopping"></i> Purchase History</span></div>
                <?php if ($purchaseHistoryError !== ''): ?>
                    <div class="p-alert p-alert-err"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($purchaseHistoryError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php elseif (!$purchaseHistory): ?>
                    <p class="purchase-history-empty">Your products and purchases will appear here after you place an order.</p>
                <?php else: ?>
                    <div class="purchase-history-list">
                        <?php foreach ($purchaseHistory as $purchase): ?>
                            <?php
                            $purchaseStatus = (string) $purchase['status'];
                            $purchaseStatusClass = $purchaseStatus === 'Completed'
                                ? ' is-complete'
                                : ($purchaseStatus === 'Cancelled' ? ' is-cancelled' : '');
                            $purchaseTotal = max(
                                0,
                                (float) $purchase['unit_price'] * (int) $purchase['quantity'] - (float) $purchase['discount_amount']
                            );
                            ?>
                            <article class="purchase-history-card">
                                <img
                                    class="purchase-history-image"
                                    src="<?php echo htmlspecialchars($purchase['product_image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                    alt=""
                                    loading="lazy"
                                    onerror="this.src='./logo/logome.png'"
                                >
                                <div class="purchase-history-details">
                                    <strong class="purchase-history-name"><?php echo htmlspecialchars((string) $purchase['product_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span><?php echo !empty($purchase['order_reference']) ? 'Order ' . htmlspecialchars((string) $purchase['order_reference'], ENT_QUOTES, 'UTF-8') : 'Order #' . (int) $purchase['id']; ?></span>
                                    <span>Qty <?php echo (int) $purchase['quantity']; ?> · <?php echo htmlspecialchars($storeSettings['currency_symbol'] ?? '$', ENT_QUOTES, 'UTF-8'); ?><?php echo number_format($purchaseTotal, 2); ?></span>
                                    <span class="purchase-history-status<?php echo $purchaseStatusClass; ?>">
                                        <?php echo htmlspecialchars($purchaseStatus, ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $purchase['payment_status'])), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <time datetime="<?php echo htmlspecialchars((string) $purchase['created_at'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) $purchase['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                                    </time>
                                    <?php if (!empty($purchase['admin_delivery_confirmed_at'])): ?>
                                        <span class="purchase-history-status">រដ្ឋបាលបានបញ្ជាក់ថាឥវ៉ាន់ដល់ទីតាំង៖ <?php echo htmlspecialchars((string) $purchase['location'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) $purchase['admin_delivery_confirmed_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($purchase['customer_received_at']) || $purchase['customer_delivery_status'] === 'success'): ?>
                                        <span class="purchase-history-status is-complete">បានបញ្ជាក់ថាទទួលបានឥវ៉ាន់<?php if (!empty($purchase['customer_received_at'])): ?> · <?php echo htmlspecialchars((string) $purchase['customer_received_at'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span>
                                    <?php elseif ($purchase['customer_delivery_status'] === 'failed'): ?>
                                        <span class="purchase-history-status is-delivery-failed">មិនបានទទួលឥវ៉ាន់</span>
                                        <?php
                                        $purchaseReference = !empty($purchase['order_reference'])
                                            ? (string) $purchase['order_reference']
                                            : '#' . (int) $purchase['id'];
                                        $telegramMessage = 'I have not received order ' . $purchaseReference . ' (' . (string) $purchase['product_name'] . ').';
                                        ?>
                                        <div class="purchase-history-contact-links">
                                            <a href="https://t.me/nate1234567890?text=<?php echo rawurlencode($telegramMessage); ?>" target="_blank" rel="noopener noreferrer">ទាក់ទងតាម Telegram</a>
                                            <a href="tel:+85560269308">ហៅទូរស័ព្ទ +855 60 269 308</a>
                                        </div>
                                    <?php elseif ($purchaseStatus !== 'Cancelled'): ?>
                                        <?php
                                        $purchaseReference = !empty($purchase['order_reference'])
                                            ? (string) $purchase['order_reference']
                                            : '#' . (int) $purchase['id'];
                                        $telegramMessage = 'I have not received order ' . $purchaseReference . ' (' . (string) $purchase['product_name'] . ').';
                                        ?>
                                        <div class="purchase-history-actions">
                                            <button class="purchase-history-action purchase-history-received-button" type="button" data-profile-delivery-action="confirm_received" data-order-id="<?php echo (int) $purchase['id']; ?>">
                                                ទទួលបានឥវ៉ាន់
                                            </button>
                                            <button class="purchase-history-action" type="button" data-profile-delivery-action="report_not_received" data-order-id="<?php echo (int) $purchase['id']; ?>">
                                                មិនបានទទួល
                                            </button>
                                        </div>
                                        <div class="purchase-history-contact-links" data-profile-contact-links hidden>
                                            <a href="https://t.me/nate1234567890?text=<?php echo rawurlencode($telegramMessage); ?>" target="_blank" rel="noopener noreferrer">ទាក់ទងតាម Telegram</a>
                                            <a href="tel:+85560269308">ហៅទូរស័ព្ទ +855 60 269 308</a>
                                        </div>
                                        <span class="purchase-history-action-error" data-profile-order-error role="status" aria-live="polite"></span>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <script src="../js/theme-mode.js?v=<?php echo filemtime(__DIR__ . '/../js/theme-mode.js'); ?>"></script>
    <script>
    (() => {
        const overlay       = document.getElementById('avatarUploadOverlay');
        const dropZone      = document.getElementById('avatarDropZone');
        const fileInput     = document.getElementById('avatarFileInput');
        const preview       = document.getElementById('avatarPreview');
        const confirmBtn    = document.getElementById('confirmUploadBtn');
        const cancelBtn     = document.getElementById('cancelUploadBtn');
        const uploadMsg     = document.getElementById('uploadMsg');
        const progressWrap  = document.getElementById('uploadProgress');
        const progressBar   = document.getElementById('uploadProgressBar');
        const openBtn       = document.getElementById('openAvatarUpload');
        let selectedFile    = null;

        openBtn.addEventListener('click', () => overlay.classList.add('active'));
        cancelBtn.addEventListener('click', reset);
        dropZone.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));

        dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
        dropZone.addEventListener('drop', e => { e.preventDefault(); dropZone.classList.remove('drag-over'); handleFile(e.dataTransfer.files[0]); });

        function handleFile(file) {
            if (!file) return;
            if (!file.type.startsWith('image/')) { uploadMsg.textContent = 'Please select an image file.'; return; }
            if (file.size > 5 * 1024 * 1024)    { uploadMsg.textContent = 'Image must be 5 MB or smaller.'; return; }
            selectedFile = file;
            const reader = new FileReader();
            reader.onload = e => {
                preview.src = e.target.result;
                preview.style.display = 'block';
                confirmBtn.style.display = 'inline-flex';
                uploadMsg.textContent = file.name;
            };
            reader.readAsDataURL(file);
        }

        confirmBtn.addEventListener('click', () => {
            if (!selectedFile) return;
            const fd = new FormData();
            fd.append('avatar', selectedFile);
            fd.append('csrf_token', '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>');
            confirmBtn.disabled = true;
            progressWrap.style.display = 'block';
            uploadMsg.textContent = 'Uploading…';
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '../api/upload-avatar.php');
            xhr.upload.addEventListener('progress', e => {
                if (e.lengthComputable) progressBar.style.width = (e.loaded / e.total * 100) + '%';
            });
            xhr.addEventListener('load', () => {
                let res;
                try { res = JSON.parse(xhr.responseText); } catch(e) { res = {success:false, error:'Server error.'}; }
                if (res.success) {
                    uploadMsg.textContent = '✔ Photo updated!';
                    uploadMsg.style.color = '#00ff66';
                    const curImg = document.getElementById('currentAvatarImg');
                    if (curImg.tagName === 'IMG') {
                        curImg.src = res.avatar_url;
                    } else {
                        const img = document.createElement('img');
                        img.id = 'currentAvatarImg'; img.src = res.avatar_url; img.alt = 'Avatar';
                        img.style.cssText = 'width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--cyber-cyan);box-shadow:0 0 20px rgba(0,240,255,.35);display:block;';
                        curImg.replaceWith(img);
                    }
                    setTimeout(reset, 1200);
                } else {
                    uploadMsg.textContent = '✘ ' + (res.error || 'Upload failed.');
                    uploadMsg.style.color = '#ff2a85';
                    confirmBtn.disabled = false;
                }
            });
            xhr.addEventListener('error', () => {
                uploadMsg.textContent = '✘ Network error.';
                uploadMsg.style.color = '#ff2a85';
                confirmBtn.disabled = false;
            });
            xhr.send(fd);
        });

        function reset() {
            overlay.classList.remove('active');
            selectedFile = null; fileInput.value = '';
            preview.style.display = 'none'; preview.src = '';
            confirmBtn.style.display = 'none'; confirmBtn.disabled = false;
            progressWrap.style.display = 'none'; progressBar.style.width = '0';
            uploadMsg.textContent = ''; uploadMsg.style.color = '';
        }
    })();
    </script>
    <script>
    (() => {
        const ordersWidget = document.querySelector("[data-customer-orders-widget]");
        const endpoint = ordersWidget?.dataset.endpoint;
        const csrfToken = ordersWidget?.dataset.csrf || "";
        document.querySelectorAll("[data-profile-delivery-action]").forEach((button) => {
            button.addEventListener("click", async () => {
                const errorMessage = button.closest(".purchase-history-details")?.querySelector("[data-profile-order-error]");
                if (!endpoint || !csrfToken) {
                    if (errorMessage) errorMessage.textContent = "Could not update delivery status. Refresh the page and try again.";
                    return;
                }

                button.disabled = true;
                if (errorMessage) errorMessage.textContent = "";
                const deliveryAction = button.dataset.profileDeliveryAction;
                try {
                    const response = await fetch(endpoint, {
                        method: "POST",
                        credentials: "same-origin",
                        headers: {
                            "Content-Type": "application/json",
                            "X-CSRF-Token": csrfToken
                        },
                        body: JSON.stringify({
                            action: deliveryAction,
                            order_id: Number(button.dataset.orderId)
                        })
                    });
                    const result = await response.json();
                    if (!response.ok || result.success !== true) {
                        throw new Error(result.message || "Could not update delivery status.");
                    }

                    const details = button.closest(".purchase-history-details");
                    const actions = details?.querySelector(".purchase-history-actions");
                    if (deliveryAction === "confirm_received") {
                        const confirmed = document.createElement("span");
                        confirmed.className = "purchase-history-status is-complete";
                        confirmed.textContent = `បានបញ្ជាក់ថាទទួលបានឥវ៉ាន់ · ${result.received_at}`;
                        actions?.replaceWith(confirmed);
                        details?.querySelector("[data-profile-contact-links]")?.remove();
                    } else {
                        const failed = document.createElement("span");
                        failed.className = "purchase-history-status is-delivery-failed";
                        failed.textContent = "មិនបានទទួលឥវ៉ាន់";
                        actions?.replaceWith(failed);
                        const links = details?.querySelector("[data-profile-contact-links]");
                        if (links) links.hidden = false;
                    }
                    errorMessage?.remove();
                } catch (error) {
                    button.disabled = false;
                    if (errorMessage) errorMessage.textContent = error instanceof Error ? error.message : "Could not update delivery status.";
                }
            });
        });
    })();
    </script>
</body>
</html>
