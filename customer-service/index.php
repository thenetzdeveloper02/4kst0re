<?php
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/google-auth.php';
// Start session and resolve persistent login cookie BEFORE any HTML output
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$currentUser = getCurrentUser($conn);
?>
<!DOCTYPE html>
<html lang="km">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ជំនួយអតិថិជន • 4K STORE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="../home/logo/logome.png" type="image/x-icon">
    <link rel="stylesheet" href="../home/page.css?v=<?php echo filemtime(__DIR__ . '/../home/page.css'); ?>">
    <link rel="stylesheet" href="../home/fashion-theme.css?v=<?php echo filemtime(__DIR__ . '/../home/fashion-theme.css'); ?>">
    <link rel="stylesheet" href="../css/theme-mode.css?v=<?php echo filemtime(__DIR__ . '/../css/theme-mode.css'); ?>">
    <script src="../js/page-navigation.js?v=<?php echo filemtime(__DIR__ . '/../js/page-navigation.js'); ?>" defer></script>
    <style>
        .support-cyber-layer {
            position: relative;
            z-index: 10;
            width: min(1140px, 100%);
            min-height: calc(100vh - 112px);
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(20px, 4vw, 48px) 16px;
        }

        .support-cyber-card {
            width: 100%;
            display: grid;
            grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
            align-content: center;
            align-items: center;
            column-gap: clamp(28px, 5vw, 64px);
            row-gap: 24px;
            background: var(--app-surface-solid);
            border: 1px solid var(--app-border);
            border-radius: var(--radius-xl);
            padding: clamp(28px, 5vw, 56px);
            box-shadow: var(--modal-shadow);
        }

        .support-intro {
            grid-column: 1;
            grid-row: 1 / span 2;
            text-align: left;
        }

        .support-intro h2 {
            font-size: clamp(26px, 3.2vw, 38px);
            font-weight: 700;
            line-height: 1.25;
            color: var(--app-label-primary);
            letter-spacing: -0.02em;
        }

        .support-intro p {
            max-width: 440px;
            margin-top: 14px;
            color: var(--app-label-secondary);
            font-size: 15px;
            line-height: 1.65;
        }

        .support-intro .dna-badge {
            display: inline-flex;
            margin-top: 20px;
        }

        .support-form {
            grid-column: 2;
            grid-row: 1;
            width: 100%;
        }

        .form-group {
            margin-bottom: 18px;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--app-label-secondary);
            margin-bottom: 6px;
        }

        .form-control-cyber {
            width: 100%;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            background: var(--app-surface-secondary);
            border: 1px solid var(--app-border);
            color: var(--app-label-primary);
            font-family: inherit;
            font-size: 14.5px;
            outline: none;
            transition: all var(--duration-fast) var(--ease-apple);
        }

        .form-control-cyber:focus {
            background: var(--app-surface-solid);
            border-color: var(--app-accent);
            box-shadow: 0 0 0 3px var(--app-accent-surface);
        }

        textarea.form-control-cyber {
            min-height: 110px;
            resize: vertical;
        }

        .alert-cyber {
            padding: 14px 18px;
            border-radius: var(--radius-md);
            font-size: 13.5px;
            font-weight: 600;
            margin-top: 18px;
            text-align: center;
        }

        .alert-success {
            background: var(--app-success-bg);
            border: 1px solid rgba(52, 199, 89, 0.25);
            color: var(--app-success);
        }

        .alert-error {
            background: var(--app-danger-bg);
            border: 1px solid rgba(255, 59, 48, 0.25);
            color: var(--app-danger);
        }

        .support-after-form {
            grid-column: 2;
            grid-row: 2;
        }

        .support-direct-contact {
            margin-top: 0;
        }

        @media (max-width: 760px) {
            .support-cyber-layer {
                min-height: calc(100vh - 90px);
                padding: 22px 16px;
            }

            .support-cyber-card {
                min-height: 0;
                grid-template-columns: minmax(0, 1fr);
                gap: 22px;
                padding: clamp(22px, 6vw, 36px);
                border-radius: var(--radius-lg);
            }

            .support-intro,
            .support-form,
            .support-after-form {
                grid-column: 1;
                grid-row: auto;
            }

            .support-intro {
                text-align: center;
            }

            .support-intro p {
                margin-right: auto;
                margin-left: auto;
            }
        }

        @media (max-width: 480px) {
            .support-cyber-layer {
                padding: 18px 10px;
            }

            .support-cyber-card {
                padding: 22px 16px;
                border-radius: var(--radius-md);
            }
        }
    </style>
</head>

<body class="fashion-storefront">
    <div class="cyber-grid-lines"></div>

    <?php
    $nav_active = 'support';
    $nav_home_url = '../home/index.php';
    $nav_about_url = '../home/index.php?page=about';
    $nav_support_url = 'index.php';
    $nav_payments_url = '../home/index.php#payments';
    require __DIR__ . '/../home/navbar.php';
    ?>

    <div class="support-cyber-layer">
        <div class="support-cyber-card">
            <div class="support-intro">
                <h2>
                    ជំនួយ & ទំនាក់ទំនងអតិថិជន
                </h2>
                <p>
                    មានសំណួរ ឬត្រូវការជំនួយអំពីផលិតផល ការបង់ប្រាក់ ឬការដឹកជញ្ជូនមែនទេ? ផ្ញើសារមកក្រុមការងារ ហើយយើងនឹងជួយណែនាំអ្នក។
                </p>
                <span class="dna-badge" style="font-size: 11px; padding: 4px 10px;">
                    <i class="fas fa-satellite-dish"></i> Support 24/7
                </span>
            </div>

            <form class="support-form" method="POST" action="submit-form.php">
                <div class="form-group">
                    <label><i class="fas fa-user" style="color: var(--cyber-cyan); margin-right: 6px;"></i> ឈ្មោះរបស់អ្នក (Name)</label>
                    <input type="text" name="name" class="form-control-cyber" maxlength="100" placeholder="ឧទាហរណ៍: Sokha" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-envelope" style="color: var(--cyber-purple); margin-right: 6px;"></i> អ៊ីម៉ែល ឬ Telegram ID</label>
                    <input type="email" name="email" class="form-control-cyber" maxlength="100" placeholder="ឧទាហរណ៍: sokha@gmail.com" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-comment-dots" style="color: var(--cyber-pink); margin-right: 6px;"></i> មតិយោបល់ ឬសំណួររបស់អ្នក (Message)</label>
                    <textarea name="message" class="form-control-cyber" maxlength="10000" placeholder="សូមសរសេរសារ ឬបញ្ហារបស់លោកអ្នកនៅទីនេះ..." required></textarea>
                </div>

                <button type="submit" class="cyber-buy-action" style="width: 100%; padding: 13px; margin-top: 8px;">
                    <i class="fas fa-paper-plane"></i> បញ្ជូនសារ (Send Message)
                </button>
            </form>

            <div class="support-after-form">
            <?php
            if (isset($_GET['success'])) {
                echo '<div class="alert-cyber alert-success"><i class="fas fa-check-circle"></i> សាររបស់អ្នកត្រូវបានបញ្ជូនដោយជោគជ័យ!</div>';
            }
            if (isset($_GET['error'])) {
                echo '<div class="alert-cyber alert-error"><i class="fas fa-triangle-exclamation"></i> មានបញ្ហាក្នុងការបញ្ជូនសារ សូមព្យាយាមម្តងទៀត។</div>';
            }
            ?>
            </div>
        </div>
    </div>

    <script src="../js/theme-mode.js?v=<?php echo filemtime(__DIR__ . '/../js/theme-mode.js'); ?>"></script>
</body>

</html>