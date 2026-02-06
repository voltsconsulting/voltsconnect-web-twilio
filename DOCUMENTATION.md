# Volts Connect Web - Twilio (PHP CRM)

## 1) What this project is

A PHP 8+ web application (deployable on shared hosting) that provides:

- A multi-user CRM-style dashboard
- Twilio browser calling (Programmable Voice + Twilio Voice JS SDK)
- SMS inbox with threaded conversations (Haymaker-style inbox foundation)
- Contact management + notes + conversation assignment
- Installer wizard for shared hosting

Tech stack:

- PHP 8+
- MySQL (PDO)
- Twilio PHP SDK (vendor included)

## 2) Folder structure

- `public/`
  - `index.php` – main router, UI rendering, API endpoints, Twilio webhooks
  - `app.js` – dashboard frontend (inbox + dialpad + theme)
  - `styles.css` – styling
- `src/`
  - `Config.php` – `.env` loader
  - `Db.php` – MySQL connection + schema creation
  - `Auth.php` – login/register/logout
- `storage/`
  - `installed.lock` – created after install
- `vendor/`
  - Composer dependencies (ship this folder so the user does NOT need `composer install`)

## 3) Shared hosting requirements (Hostinger)

- PHP 8.0+ (8.1/8.2 recommended)
- Extensions:
  - `pdo`
  - `pdo_mysql`
  - `openssl`
- A MySQL database + credentials

## 4) Deployment to Hostinger (public_html)

### 4.1 Upload

Upload the **entire project** into `public_html/`.

Make sure these are present on the server:

- `public/index.php`
- `src/`
- `vendor/`
- `storage/` (writable recommended)
- `.htaccess`

### 4.2 .htaccess

This project includes `.htaccess` in the project root. It:

- rewrites requests to `public/index.php`
- blocks access to `.env` and `storage/`

If you see a 404 for routes like `/login`, your rewrites are not active.

## 5) First-time install wizard

Open:

- `https://YOUR_DOMAIN/install`

Steps:

- Step 1: server checks
- Step 2: MySQL + Base URL + Twilio credentials
- Step 3: create admin

At the end, the installer writes:

- `storage/installed.lock`

If you need to re-run install:

- Delete `storage/installed.lock`
- Set `APP_INSTALLED=0` in `.env` (if you wrote one)

## 6) IMPORTANT: BASE_URL

### 6.1 Correct format

Your `BASE_URL` must be a clean origin with a single scheme.

Correct examples:

- `BASE_URL=https://call.volts-consulting.com`
- `BASE_URL=https://call.volts-consulting.com/subfolder` (only if installed in a subfolder)

Incorrect examples:

- `BASE_URL=http:https://call.volts-consulting.com/`
- `BASE_URL=https://http:https://call.volts-consulting.com/`

### 6.2 If you are behind a proxy

Hostinger / shared hosting can be behind proxies. The app attempts to infer `https` using:

- `HTTP_X_FORWARDED_PROTO`
- `HTTPS`

But you should still set `BASE_URL` explicitly in `.env`.

## 7) Twilio setup

Twilio credentials are managed **in the database** (Admin UI), not in `.env`.

In the app (admin):

- Settings → Twilio Accounts
- Add your:
  - Account SID
  - Auth Token
  - (optional) API Key / API Secret
  - (optional) TwiML App SID
  - Default From Number

Then set the default Twilio account in Settings.

### 7.1 Configure Twilio webhooks

#### SMS webhook

In Twilio Console:

- Phone Numbers
- Select your number
- Messaging

Set:

- **A message comes in**: `POST https://YOUR_DOMAIN/webhooks/twilio/sms`
- **Status callback**: handled automatically by this app when sending (it sets `.../webhooks/twilio/sms/status`)

#### Voice (TwiML App)

In Twilio Console:

- Voice
- TwiML Apps

Set:

- **Voice Request URL**: `POST https://YOUR_DOMAIN/webhooks/twilio/voice`
- **Status Callback URL** (optional): `POST https://YOUR_DOMAIN/webhooks/twilio/voice/status`

### 7.2 Webhook validation

Optional but recommended:

- `TWILIO_VALIDATE_WEBHOOK=1`

If enabled, Twilio signatures must validate.

Common validation failure causes:

- Wrong `BASE_URL`
- HTTP vs HTTPS mismatch
- Reverse proxy changing host/proto

## 8) Database schema & migrations

The schema is created via:

- `App\Db::ensureSchema($pdo)`

This uses `CREATE TABLE IF NOT EXISTS`, so it can be re-run safely.

### 8.1 Running migrations after updates

Open (admin only):

- `https://YOUR_DOMAIN/migrate`

This will ensure all tables exist.

If you add new columns later, we will extend the migration system to apply `ALTER TABLE` migrations.

## 9) How the inbox works (high level)

Tables:

- `contacts`
- `conversations`
- `messages`
- `conversation_notes`
- `users`, `numbers`, `user_numbers`

Rules:

- Inbound SMS creates/updates:
  - contact (by phone)
  - conversation (by contact)
  - message row
  - conversation preview + timestamp
  - conversation default “from number” (the Twilio number that received the inbound)

- Outbound SMS sends with Twilio and creates a message row.

## 10) Shipping without composer

To avoid requiring end users to run composer:

- Include the `vendor/` folder in your release ZIP.

This project is designed to run on shared hosting with only file upload + installer.

## 11) Troubleshooting

### 11.1 “Twilio Voice SDK not loaded”

Check:

- Your page is loaded over HTTPS
- The script loads:
  - `https://sdk.twilio.com/js/voice/releases/2.11.2/twilio.min.js`
- Browser console errors (CORS, mixed content)

### 11.2 SMS inbound not showing

Check:

- Twilio number incoming message webhook points to the correct URL
- `BASE_URL` is correct
- The server can write to the DB

### 11.3 404 on /login or /app

Your `.htaccess` rewrite is not being applied.

Confirm Hostinger has Apache rewrite enabled for your hosting plan.
