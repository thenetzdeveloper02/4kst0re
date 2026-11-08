<?php
require_once __DIR__ . '/../api/admin-auth.php';
require_once __DIR__ . '/../admin/primary-navigation.php';

if (!ensureAdminSecuritySchema($conn) || !adminStartSession()) {
  http_response_code(500);
  exit('Could not prepare the admin session.');
}
$currentAdmin = adminRequireLogin($conn, '../admin/index.php');
adminRequirePermission($currentAdmin, 'support');
$csrfToken = adminCsrfToken();
$result = $conn->query("SELECT * FROM messages ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="km">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>សាររបស់អតិថិជន • Customer Messages</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="icon" href="../home/logo/logome.png" type="image/x-icon">
  <link rel="apple-touch-icon" href="../home/logo/logome.png">
  <link rel="stylesheet" href="../home/page.css?v=<?php echo filemtime(__DIR__ . '/../home/page.css'); ?>">
  <link rel="stylesheet" href="../home/fashion-theme.css?v=<?php echo filemtime(__DIR__ . '/../home/fashion-theme.css'); ?>">
  <style>
    .dashboard-container {
      max-width: 1140px;
      margin: 40px auto 60px;
      padding: 0 20px;
      position: relative;
      z-index: 10;
    }

    .dashboard-header {
      background: linear-gradient(135deg, rgba(15, 23, 42, 0.88) 0%, rgba(30, 27, 75, 0.8) 50%, rgba(15, 23, 42, 0.92) 100%);
      backdrop-filter: blur(28px) saturate(180%);
      -webkit-backdrop-filter: blur(28px) saturate(180%);
      border: 1px solid rgba(99, 102, 241, 0.35);
      border-radius: 28px;
      padding: 24px 30px;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 16px;
      box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.3), 0 20px 40px rgba(0, 0, 0, 0.6);
    }

    .dashboard-table-card {
      width: 100%;
      background: linear-gradient(135deg, rgba(15, 23, 42, 0.85) 0%, rgba(20, 25, 55, 0.8) 100%);
      backdrop-filter: blur(28px) saturate(180%);
      -webkit-backdrop-filter: blur(28px) saturate(180%);
      border: 1px solid rgba(99, 102, 241, 0.28);
      border-radius: 28px;
      overflow: hidden;
      box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.3), 0 30px 60px -10px rgba(0, 0, 0, 0.65);
    }

    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    thead {
      background: rgba(255, 255, 255, 0.06);
      border-bottom: 1px solid rgba(99, 102, 241, 0.25);
    }

    th {
      padding: 16px 20px;
      font-size: 13.5px;
      font-weight: 700;
      color: var(--cyber-cyan);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    td {
      padding: 16px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.06);
      color: rgba(255, 255, 255, 0.88);
      font-size: 14px;
      vertical-align: middle;
    }

    tbody tr:hover {
      background: rgba(255, 255, 255, 0.04);
    }

    .btn-delete {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.4);
      color: #f87171;
      padding: 7px 16px;
      border-radius: 9999px;
      font-size: 12.5px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s ease;
    }

    .btn-delete:hover {
      background: rgba(239, 68, 68, 0.3);
      border-color: #f87171;
      color: #fff;
    }

    .delete-warning-overlay {
      position: fixed;
      inset: 0;
      z-index: 1000;
      display: grid;
      place-items: center;
      padding: 20px;
      background: rgba(2, 6, 23, 0.72);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      animation: delete-overlay-in 180ms ease-out;
    }

    .delete-warning-overlay[hidden] {
      display: none;
    }

    .delete-warning-dialog {
      width: min(100%, 440px);
      padding: 30px;
      border: 1px solid rgba(248, 113, 113, 0.38);
      border-radius: 26px;
      background: linear-gradient(145deg, rgba(25, 20, 39, 0.98), rgba(12, 17, 34, 0.98));
      box-shadow: 0 28px 90px rgba(0, 0, 0, 0.58), 0 0 42px rgba(239, 68, 68, 0.12);
      text-align: center;
      animation: delete-dialog-in 220ms cubic-bezier(0.2, 0.8, 0.2, 1);
    }

    .delete-warning-icon {
      display: grid;
      place-items: center;
      width: 64px;
      height: 64px;
      margin: 0 auto 18px;
      border: 1px solid rgba(248, 113, 113, 0.35);
      border-radius: 20px;
      background: rgba(239, 68, 68, 0.13);
      color: #f87171;
      font-size: 25px;
      box-shadow: 0 0 30px rgba(239, 68, 68, 0.12);
    }

    .delete-warning-dialog h2 {
      margin-bottom: 10px;
      color: #fff;
      font-size: 22px;
      font-weight: 800;
    }

    .delete-warning-dialog p {
      color: #cbd5e1;
      font-size: 14px;
      line-height: 1.7;
    }

    .delete-warning-actions {
      display: flex;
      justify-content: center;
      gap: 12px;
      margin-top: 24px;
    }

    .delete-warning-actions button {
      min-height: 44px;
      padding: 10px 20px;
      border: 1px solid transparent;
      border-radius: 9999px;
      color: #fff;
      font: inherit;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: transform 180ms ease, background 180ms ease, border-color 180ms ease;
    }

    .delete-warning-actions button:hover {
      transform: translateY(-1px);
    }

    .delete-warning-cancel {
      border-color: rgba(148, 163, 184, 0.28) !important;
      background: rgba(255, 255, 255, 0.07);
    }

    .delete-warning-cancel:hover {
      background: rgba(255, 255, 255, 0.13);
    }

    .delete-warning-confirm {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #dc3545;
      box-shadow: 0 8px 22px rgba(220, 53, 69, 0.24);
    }

    .delete-warning-confirm:hover {
      background: #ef4444;
    }

    @keyframes delete-overlay-in {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes delete-dialog-in {
      from { opacity: 0; transform: translateY(12px) scale(0.97); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    @media screen and (max-width: 768px) {
      .dashboard-table-card {
        overflow-x: auto;
      }
      table {
        min-width: 650px;
      }
    }

    @media screen and (max-width: 480px) {
      .delete-warning-dialog {
        padding: 26px 20px;
      }

      .delete-warning-actions {
        flex-direction: column-reverse;
      }

      .delete-warning-actions button {
        width: 100%;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .delete-warning-overlay,
      .delete-warning-dialog {
        animation: none;
      }

      .delete-warning-actions button {
        transition: none;
      }
    }
  </style>
  <link rel="stylesheet" href="../admin/backoffice-theme.css?v=<?php echo filemtime(__DIR__ . '/../admin/backoffice-theme.css'); ?>">
  <link rel="stylesheet" href="../css/theme-mode.css?v=<?php echo filemtime(__DIR__ . '/../css/theme-mode.css'); ?>">
  <script src="../js/page-navigation.js?v=<?php echo filemtime(__DIR__ . '/../js/page-navigation.js'); ?>" defer></script>
</head>

<body class="fashion-storefront message-storefront">
  <div class="cyber-grid-lines"></div>

  <div class="dashboard-container">
    <div class="dashboard-header">
      <div>
        <span class="dna-badge" style="display:inline-flex; margin-bottom: 6px;">
          <i class="fas fa-inbox"></i> Customer Support Hub
        </span>
        <h2 style="font-size: 24px; font-weight: 800; color: #fff;">
          សាររបស់អតិថិជន (Messages)
        </h2>
      </div>

      <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="../home/index.php" class="cyber-glow-btn" style="padding: 8px 18px; font-size: 13.5px; border-color: rgba(255,255,255,0.3); background: rgba(255,255,255,0.06);">
          <i class="fas fa-globe"></i> មើលទំព័រមុខ
        </a>
      </div>
    </div>
    <?php renderAdminPrimaryNavigation($currentAdmin, 'customers'); ?>

    <div class="dashboard-table-card">
      <table>
        <thead>
          <tr>
            <th style="width: 60px;">ល.រ</th>
            <th style="width: 160px;">ឈ្មោះ</th>
            <th style="width: 200px;">អ៊ីម៉ែល / Contact</th>
            <th>សាររបស់អតិថិជន</th>
            <th style="width: 170px;">កាលបរិច្ឆេទ</th>
            <th style="width: 110px; text-align: center;">សកម្មភាព</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()) { ?>
              <tr>
                <td style="font-weight: 700; color: var(--cyber-cyan);"><?= $row['id'] ?></td>
                <td style="font-weight: 700; color: #fff;"><?= htmlspecialchars($row['name']) ?></td>
                <td style="color: var(--cyber-purple); font-weight: 600;"><?= htmlspecialchars($row['email']) ?></td>
                <td style="color: rgba(255, 255, 255, 0.85); line-height: 1.5;"><?= htmlspecialchars($row['message']) ?></td>
                <td style="color: var(--text-dim); font-size: 12.5px;"><?= $row['created_at'] ?></td>
                <td style="text-align: center;">
                  <form action="delete-message.php" method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="message_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn-delete">
                      <i class="fas fa-trash"></i> លុប
                    </button>
                  </form>
                </td>
              </tr>
            <?php } ?>
          <?php else: ?>
            <tr>
              <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                មិនទាន់មានសារពីអតិថិជននៅឡើយទេ (No messages received)
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="delete-warning-overlay" id="deleteWarningOverlay" hidden>
    <section class="delete-warning-dialog" role="alertdialog" aria-modal="true" aria-labelledby="deleteWarningTitle" aria-describedby="deleteWarningMessage" tabindex="-1">
      <div class="delete-warning-icon"><i class="fas fa-triangle-exclamation"></i></div>
      <h2 id="deleteWarningTitle">បញ្ជាក់ការលុបសារ</h2>
      <p id="deleteWarningMessage">តើអ្នកពិតជាចង់លុបសាររបស់អតិថិជននេះមែនទេ? សកម្មភាពនេះមិនអាចត្រឡប់វិញបានទេ។</p>
      <div class="delete-warning-actions">
        <button type="button" class="delete-warning-cancel" id="cancelDeleteMessage">បោះបង់</button>
        <button type="button" class="delete-warning-confirm" id="confirmDeleteMessage">
          <i class="fas fa-trash"></i> លុបសារ
        </button>
      </div>
    </section>
  </div>

  <script>
    (() => {
      const overlay = document.getElementById('deleteWarningOverlay');
      const dialog = overlay.querySelector('[role="alertdialog"]');
      const cancelButton = document.getElementById('cancelDeleteMessage');
      const confirmButton = document.getElementById('confirmDeleteMessage');
      let pendingDeleteForm = null;
      let previouslyFocusedElement = null;

      const closeDialog = () => {
        overlay.hidden = true;
        document.body.style.overflow = '';
        pendingDeleteForm = null;
        if (previouslyFocusedElement) {
          previouslyFocusedElement.focus();
        }
      };

      document.querySelectorAll('.btn-delete').forEach((button) => {
        button.closest('form').addEventListener('submit', (event) => {
          event.preventDefault();
          pendingDeleteForm = event.currentTarget;
          previouslyFocusedElement = button;
          overlay.hidden = false;
          document.body.style.overflow = 'hidden';
          cancelButton.focus();
        });
      });

      cancelButton.addEventListener('click', closeDialog);
      confirmButton.addEventListener('click', () => {
        if (pendingDeleteForm) {
          pendingDeleteForm.submit();
        }
      });

      overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
          closeDialog();
        }
      });

      document.addEventListener('keydown', (event) => {
        if (overlay.hidden) {
          return;
        }

        if (event.key === 'Escape') {
          event.preventDefault();
          closeDialog();
        } else if (event.key === 'Tab') {
          event.preventDefault();
          (document.activeElement === cancelButton ? confirmButton : cancelButton).focus();
        }
      });
    })();
  </script>
  <script src="../js/theme-mode.js?v=<?php echo filemtime(__DIR__ . '/../js/theme-mode.js'); ?>"></script>
</body>

</html>