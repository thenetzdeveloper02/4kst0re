# Google OAuth Login Setup Guide

This guide will help you set up Google OAuth login for your e-commerce application.

## 📋 Prerequisites

- PHP 7.4 or higher
- MySQL database
- cURL extension enabled in PHP
- A Google Cloud Platform account

## 🚀 Setup Instructions

### Step 1: Create Google OAuth Credentials

1. Go to the [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Navigate to **APIs & Services** > **Credentials**
4. Click **Create Credentials** > **OAuth client ID**
5. Configure the OAuth consent screen if you haven't already:
   - Choose **External** user type
   - Fill in the application name and support email
   - Add your domain to authorized domains
6. Create OAuth 2.0 Client ID:
   - Application type: **Web application**
   - Name: Your app name (e.g., "Ecommerce Store")
   - Authorized JavaScript origins:
     - `http://localhost` (for local development)
     - `https://yourdomain.com` (for production)
   - Authorized redirect URIs:
     - `http://localhost/Ecommerce/api/google-callback.php` (for local)
     - `https://yourdomain.com/api/google-callback.php` (for production)
7. Copy your **Client ID** and **Client Secret**

### Step 2: Configure Your Application

1. Open the `.env` file in your project root
2. Update the Google OAuth credentials:

```env
GOOGLE_CLIENT_ID=your-actual-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-actual-client-secret
GOOGLE_REDIRECT_URI=http://localhost/Ecommerce/api/google-callback.php
```

**Important:** Update the `GOOGLE_REDIRECT_URI` to match your actual domain and path.

### Step 3: Database Setup

The required database tables (`account` and `account_sessions`) are already included in your `ecommerce.sql` file. If you need to add them manually:

```sql
-- The tables are already in your database schema
-- Check databases/ecommerce.sql for the complete schema
```

Successful Google sign-ins are also recorded in `account_login_history`. The table is created automatically when a user signs in or an admin opens **Login history**. Admins with the **Customers** permission can search sign-in dates, email addresses, profile details, IP addresses, and browser/device information in the admin sidebar.

### Step 4: Test the Integration

1. Start your local server (e.g., XAMPP)
2. Navigate to `http://localhost/Ecommerce/home/`
3. Click the "Sign in with Google" button in the navigation
4. You should be redirected to Google's login page
5. After successful authentication, you'll be redirected back to your profile page

## 📁 Files Created

### API Files
- `api/google-auth.php` - Core authentication functions
- `api/google-login.php` - Initiates Google OAuth flow
- `api/google-callback.php` - Handles OAuth callback
- `api/logout.php` - Logs out users
- `api/cleanup-sessions.php` - Removes expired sessions

### Frontend Files
- `home/profile.php` - User profile/dashboard page
- `home/navbar.php` - Updated with login/logout buttons

### Configuration Files
- `.env` - Environment configuration (contains credentials)

## 🔧 Configuration Options

### Session Duration
Edit `api/google-auth.php` to change session duration:

```php
const USER_SESSION_MAX_SECONDS = 2592000; // 30 days (default)
```

### Redirect URLs
After login, users are redirected to the profile page. To change this, edit `api/google-callback.php`:

```php
// Change this line:
header('Location: ../home/profile.php?login_success=1');

// To redirect elsewhere:
header('Location: ../home/index.php?login_success=1');
```

## 🔐 Security Features

- **CSRF Protection**: State token validation prevents cross-site request forgery
- **Secure Sessions**: Session tokens stored securely in database
- **Token Expiration**: Sessions automatically expire after 30 days
- **HTTPS Support**: Configure for HTTPS in production
- **Input Validation**: All user inputs are validated and sanitized

## 🧹 Session Cleanup

Run the session cleanup script periodically to remove expired sessions:

```bash
php api/cleanup-sessions.php
```

Or set up a cron job (Linux/Mac):

```bash
# Run daily at 2 AM
0 2 * * * /usr/bin/php /path/to/Ecommerce/api/cleanup-sessions.php
```

Windows Task Scheduler:
- Create a new task
- Trigger: Daily at 2:00 AM
- Action: Start a program
- Program: `C:\xampp\php\php.exe`
- Arguments: `C:\xampp\htdocs\Ecommerce\api\cleanup-sessions.php`

## 📱 Features

### User Authentication
- ✅ Sign in with Google
- ✅ Automatic account creation
- ✅ Profile picture from Google
- ✅ Secure session management
- ✅ Logout functionality

### User Profile
- ✅ View profile information
- ✅ Edit display name
- ✅ Update bio
- ✅ View order history
- ✅ Google profile picture display

### Navigation
- ✅ Dynamic login/logout button
- ✅ User profile link when logged in
- ✅ Mobile-responsive navigation

## 🐛 Troubleshooting

### "Google OAuth is not configured" Error
- Verify your `.env` file contains valid credentials
- Check that the credentials are not still set to placeholder values

### "Security validation failed" Error
- Clear your browser cookies and try again
- Ensure your session cookie settings are correct

### Redirect URI Mismatch
- Verify the redirect URI in Google Cloud Console exactly matches the one in your `.env` file
- Check for trailing slashes and protocol (http vs https)

### Session Not Persisting
- Check PHP session configuration in `php.ini`
- Verify database connection is working
- Check that `account_sessions` table exists

### cURL Errors
- Ensure cURL extension is enabled in PHP
- Check your internet connection
- Verify firewall allows outbound HTTPS requests

## 🌐 Production Deployment

Before deploying to production:

1. **Update Redirect URI**:
   ```env
   GOOGLE_REDIRECT_URI=https://yourdomain.com/api/google-callback.php
   ```

2. **Add Production Domain to Google Cloud Console**:
   - Add your production domain to authorized JavaScript origins
   - Add your production callback URL to authorized redirect URIs

3. **Enable HTTPS**:
   - Ensure your site uses HTTPS
   - Update session cookie settings for secure cookies

4. **Set Environment**:
   ```env
   APP_ENV=production
   ```

5. **Test OAuth Flow**:
   - Test login/logout completely
   - Verify session persistence
   - Check profile updates work

## 📞 Support

If you encounter any issues:

1. Check the error logs in `php_error.log`
2. Verify all configuration values in `.env`
3. Ensure database tables exist and are accessible
4. Test with different browsers to rule out cookie issues

## 🔄 Updates and Maintenance

- Regularly run the session cleanup script
- Monitor Google Cloud Console for API usage
- Keep PHP and dependencies updated
- Review and rotate credentials periodically

## 📝 License

This implementation follows the same license as your main e-commerce application.
