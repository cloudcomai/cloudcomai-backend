# CloudComAI Google Contacts Integration

This branch implements Google Contacts only. Gmail is intentionally not requested.

## Google OAuth configuration

In the CloudComAI production `config/config.php`, add:

```php
'google' => [
    'client_id' => 'YOUR_GOOGLE_OAUTH_CLIENT_ID',
    'client_secret' => 'YOUR_GOOGLE_OAUTH_CLIENT_SECRET',
    'redirect_uri' => 'https://www.cloudcomai.com/apiapp/api/google/callback.php',
],
```

The Google OAuth client must be a **Web application** and its authorized redirect URI must exactly match the value above.

The OAuth request uses:

- `https://www.googleapis.com/auth/contacts.readonly`
- `https://www.googleapis.com/auth/userinfo.email`

No Gmail scope is requested.

## Database

Run migration `database/migrations/004_google_contacts_sync.sql` using the existing CloudComAI migration process.

It creates:

- `google_oauth_states` — short-lived, single-use OAuth state values tied to the authenticated CloudComAI user.
- `google_accounts` — one Google account per CloudComAI user. The refresh token is encrypted with the existing application token secret.
- `google_contacts` — the local synchronized contacts.

## API endpoints

All endpoints except the OAuth callback require the existing CloudComAI Bearer token.

### Start connection

`GET /api/google/connect.php`

Returns an `authorization_url`. The frontend should open that URL in the same tab or a popup.

### OAuth callback

`GET /api/google/callback.php`

Google redirects here after consent. The callback exchanges the authorization code, stores the encrypted refresh token, and performs the initial contacts synchronization.

### Connection status

`GET /api/google/status.php`

Returns connection state, Google email, last sync time and local contact count.

### Manual/incremental sync

`POST /api/google/sync.php`

Uses the saved People API sync token when available. A 410 response from Google causes a safe full local rebuild and obtains a new sync token.

### Read local contacts

`GET /api/google/contacts.php?page=1&page_size=50`

Returns contacts already synchronized into CloudComAI.

## Frontend flow

1. Call `GET /api/google/connect.php` with the CloudComAI Bearer token.
2. Open the returned `authorization_url`.
3. User signs in to Google and grants Contacts permission.
4. Google redirects to the callback.
5. Callback saves the Google account and performs the initial sync.
6. Frontend can call `GET /api/google/status.php` and `GET /api/google/contacts.php`.
7. A later sync calls `POST /api/google/sync.php`; it uses the stored People API `syncToken` so only changed contacts are processed.

The People API supports sync tokens for subsequent `connections.list` calls, and deleted contacts are surfaced during sync requests so CloudComAI can mark them deleted locally.
