# ECOMMERCE

Welcome to the **OUR** Web Application!  
This platform allows users to buy and sell gaming accounts easily and securely.

---

## 🌟 Features

- ✅ Google Login Integration
- 🛒 Add to Cart and Checkout System
- 📦 Dynamic Product Listings
- 📱 Fully Responsive Design
- 🔔 Admin Notifications via Telegram
- 📧 Email Notifications via SMTP
- 🇰🇭 KHQR Top-up Payment Integration

---

## ⚙️ Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/your-username/net-account-seller.git

   
## Move into the project folder:
cd net-account-seller

## Configure your environment:
Set database connection values in the process environment or a protected `.env` file:

```dotenv
APP_ENV=production
ECOMMERCE_DB_HOST=127.0.0.1
ECOMMERCE_DB_PORT=3306
ECOMMERCE_DB_NAME=ecommerce
ECOMMERCE_DB_USER=ecommerce_app
ECOMMERCE_DB_PASSWORD=replace-with-a-secret
```

When `APP_ENV` is not `local`, the application refuses missing credentials and the MySQL `root` account. The local-only fallback (`localhost`, `root`, empty password) exists for XAMPP compatibility. Prefer environment variables or a secret store in production; keep `.env` outside the document root when possible. The included Apache rules deny access to dotfiles and `databases/`; configure equivalent denials when using Nginx or another web server. Apply the base `databases/ecommerce.sql` only to a new/empty database; it contains destructive `DROP TABLE` statements. For an existing database, apply versioned scripts from `databases/migrations/` with a migration/DBA account.

After applying the base schema and migrations, give the web application a dedicated database account scoped to `ecommerce` with only the required data privileges (`SELECT`, `INSERT`, `UPDATE`, `DELETE`). Schema helpers now skip `CREATE TABLE` for existing tables; missing columns/tables still need to be provisioned by a migration account before deploying the DML-only account.

Google OAuth credentials
Telegram Bot Token
Email SMTP settings

### Telegram order notifications

Set the following values in the project-root `.env` file. The bot must be started by the recipient account before it can send that account a message.

```dotenv
Telegram_bot_token=YOUR_BOT_TOKEN
Telegram_chat_id=YOUR_CHAT_ID
```

Order notifications contain the product name, price, customer name, phone number, location, and uploaded payment receipt (JPG, PNG, WEBP, GIF, HEIC, HEIF, or PDF up to 5 MB). Receipts are forwarded to Telegram and retained outside the public document root for authenticated admin review. Set `ECOMMERCE_PRIVATE_RECEIPT_DIR` to choose another private writable directory; the application rejects paths inside the public document root. Never request or send account passwords or two-factor authentication codes. Keep `.env` private and do not commit it.

### Product inventory

Products have a non-negative stock amount managed from the admin dashboard. Inventory is reserved during checkout to prevent overselling and restored if Telegram rejects the notification or an admin cancels the order. Products with zero stock are hidden from the storefront and cannot be ordered. Each product has an editable low-stock threshold, and the admin inventory page records stock changes, reasons, and the responsible admin. The stock and threshold columns are added automatically when the site is opened; existing products start with a threshold of 5.

### Admin sales and activity reports

The admin dashboard requires a username and password from the `admins` table. Online orders are initially Pending, and an uploaded receipt never automatically marks an order Paid. An admin must independently verify the transaction ID and exact amount in the order review before the payment becomes verified. Rejections and refunds are recorded in the order history; refunds are records of transfers completed outside this app. The operations page provides order search and history, customer search/notes and phone-based checkout blocking, inventory management, daily/weekly/monthly reports, and Excel-compatible CSV exports. Existing orders without a recorded payment state remain Pending verification.

The storefront checkout submits one product per order through `api/telegram-order.php`; `api/cart.php` and `api/checkout.php` are unused placeholders and do not implement cart or checkout behavior. A multi-product cart would require a separate order-items design and is not included in the current flow.

### Promotions, store settings, and notifications

The Admin menu groups Dashboard, Products & Inventory, Orders & Payments, Customers & Customer Service, Reports & Analytics, Promotions & Homepage, Staff/Security, and Database Backup & Settings. Staff menu access is permission-gated. Admins can create percent/fixed coupons with start/expiry dates, minimum subtotal, and a usage cap. Checkout recalculates and reserves a coupon under a database row lock; payment verification and reports use the discounted amount. Store name, logo/contact URLs, currency display symbol, homepage headline/description, and hero banner URL are configurable from Admin. Currency changes affect display only; they do not convert stored prices or payments. Admin notifications are recorded for saved new orders and low-stock threshold crossings.

### Database backups

Admin backups are encrypted using AES-256-GCM and stored outside the web root. Production must set `ECOMMERCE_BACKUP_KEY` to a persistent random 32-byte key encoded as 64 hex characters or base64; retain it securely and preserve it when rotating deployments, or those backups cannot be restored. Local mode generates a mode-0600 `.backup-key` file in the private backup directory. `ECOMMERCE_BACKUP_DIR` can choose another private writable directory. Restore accepts only this app's encrypted JSON format, the same database name, a valid checksum/authentication tag, the current Admin password, and the typed confirmation `RESTORE`; it replaces rows only in matching tables and does not change schema. Keep additional off-host backup copies for disaster recovery, and store the encryption key separately from those copies.

### Admin security and audit

Open **Security & audit** from the admin dashboard to create Admin or Staff accounts, assign Staff permissions by section, review active sessions, and revoke sessions. Admins can change their own password; passwords must be at least 12 characters. Sessions expire after 30 minutes idle or 12 hours total. Login failures are rate-limited to five attempts per username or 20 per IP address in 15 minutes; login, account, session, customer-message deletion, and product activity are recorded. Admin state-changing forms require CSRF tokens, and privileged pages verify permissions server-side. The security schema is added automatically without deleting existing admin accounts. Use HTTPS in production so admin session cookies are marked Secure; configure the web server to supply the real client address as `REMOTE_ADDR` when operating behind a trusted proxy.

Set each product's cost price in the dashboard to calculate estimated profit. Cost is optional when unknown; sales without a recorded cost are excluded from known-cost profit totals rather than being treated as zero-cost profit. Analytics, order history, customer profile, stock audit tables, and sales metadata columns are included in `databases/ecommerce.sql`. The security changes have an idempotent, additive migration at `databases/migrations/20261027_001_admin_security.sql`; it can safely skip columns/tables that the app has already created. Do not import the destructive initialization dump over a live database.

Store-management schema additions use `databases/migrations/20261027_002_store_features.sql`. Apply versioned migrations with a migration/DBA account before deploying the web app with its restricted database user.

## Start local server (example using XAMPP or similar):
Place in htdocs
Access via http://localhost/net-account-seller

🛠 Tech Stack
Frontend: HTML, CSS, JavaScript
-Backend: PHP 8+
-Database: MySQL
-Auth: Google OAuth 2.0
-Notifications: Telegram API + PHPMailer

🤝 Contributing
Contributions are welcome!
Please fork the repo and submit a pull request.
Make sure to follow best practices and comment your code.

📜 License
This project is licensed under the MIT License.

📧 Contact
For inquiries or support, contact us via Telegram or email.


Would you like the ASCII art “NET” in a different style, or should I help you generate a large banner-style title using other characters like `#`, `=`, or Unicode art?
