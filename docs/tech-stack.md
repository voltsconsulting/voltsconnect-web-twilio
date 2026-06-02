# Tech Stack

## Backend

- PHP (routing and HTML rendering)
- Twilio PHP SDK (`Twilio\Rest\Client`)
- MySQL via PDO

## Frontend

- Plain JavaScript (`public/app.js`)
- CSS (`public/styles.css`)
- Hash-based SPA routing (`/app#...`)

## Infrastructure

- Shared hosting compatible
- Cron-driven background processing via HTTP endpoints

## Security

- Server-side sessions
- Permission checks via `requirePermission(...)`
- Cron endpoints protected by token (`app_settings.notify_cron_token`)
