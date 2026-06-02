# System Architecture

## Overview
This application is a PHP-based web CRM and Twilio messaging/calling platform.

It is deployed as:

- `public/` as the web root (SPA + API entry)
- `src/` for backend route handlers and core services
- `storage/` for runtime file storage (logs, temporary MMS uploads)
- MySQL database for persistence

The UI is a single-page app using hash routing (`/app#inbox`, `/app#settings`, etc.) and plain JavaScript (`public/app.js`).

## Request flow

### Browser UI

- UI is served by `public/index.php`.
- Static assets:
  - `public/app.js`
  - `public/styles.css`

### API

- API routes are handled by PHP route handlers in `src/Http/*Routes.php`.
- `/api/*` endpoints are served by the same PHP entry and dispatched to handlers.

## Key modules

### Authentication / session

- Uses server-side session authentication.
- Permissions are checked with `requirePermission(...)` in route handlers.

### Inbox (1:1 messaging)

- Primary routes in `src/Http/InboxRoutes.php`.
- Supports SMS and MMS:
  - Upload: `POST /api/inbox/mms/upload`
  - Send: `POST /api/inbox/send` with `media_urls` -> Twilio `mediaUrl`

### Broadcasts / campaigns

- Primary routes in `src/Http/BroadcastRoutes.php`.
- Two campaign types:
  - **Send now** (immediate run)
  - **Scheduled** (processed by cron endpoint)

Data model:

- `broadcast_jobs`
- `broadcast_job_recipients`

Cron processing:

- `GET /api/cron/broadcasts?token=...` processes a batch at a time.

Throttling:

- Stored on each job:
  - `batch_size` (1..500)
  - `send_delay_ms` (0..5000)

Operational control:

- Cancel: `POST /api/broadcast/cancel` (marks job canceled and skips pending recipients)

### Templates

- Templates are shared between Inbox and Broadcast for message body presets.

### Add-ons / licensing

- Add-ons registry lives in `public/index.php` (`addonRegistry()`).
- Admin can toggle add-ons from Settings → Add-ons.
- Some add-ons can be marked `coming_soon` and are displayed without toggle.

## Hosting notes (shared hosting)

- Hash routing avoids server rewrites for SPA paths.
- Cron endpoints must be invoked by a real cron job with a secure token.
- Avoid long-running requests; use batching + cron for large sends.
