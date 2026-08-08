# CloudComAI PHP/MySQL Backend Beta

## Included
- Registration and login
- Backend age 18+ validation
- Male/Female validation
- Bearer-token authentication
- Chat and message APIs
- Reply relationships
- One-time message editing
- Group category creation
- Group-specific invitation links
- In-app group shortcuts
- Poll creation
- 24-hour stories
- Live-location sessions
- Audio/video call session records and signaling token foundation
- Retention cleanup cron

## Setup
1. Import `sql/schema.sql` into MySQL.
2. Copy `config/config.example.php` to `config/config.php`.
3. Fill in database credentials, allowed frontend origin and a long token secret.
4. Upload to a PHP 8.1+ host.
5. Confirm `api/health.php` returns `{"status":"ok"...}`.

## Security limitations
This is a beta foundation, not a final audited production messenger. Before public launch add verified OTP/email delivery, stronger session storage and revocation, robust rate limiting, file scanning, audited E2EE, push notification workers, WebRTC signaling transport, STUN/TURN, privacy/legal review, and full automated tests.

## GoDaddy
See `GODADDY_BACKEND_DEPLOYMENT.md`.
