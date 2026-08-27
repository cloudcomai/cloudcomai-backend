<?php
declare(strict_types=1);

function google_config(): array
{
    global $config;
    $google = $config['google'] ?? [];
    foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
        if (empty($google[$key])) {
            fail('Google integration is not configured', 503);
        }
    }
    return $google;
}

function google_http(string $url, array $headers = [], ?string $body = null, string $method = 'GET'): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Google request failed: ' . $error);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);
    if (!is_array($json)) {
        $json = ['raw' => $response];
    }
    return [$status, $json];
}

function google_encrypt(string $plaintext): string
{
    global $config;
    $key = hash('sha256', $config['app']['token_secret'], true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Unable to encrypt Google token');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function google_decrypt(string $encoded): string
{
    global $config;
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) {
        throw new RuntimeException('Invalid encrypted Google token');
    }
    $key = hash('sha256', $config['app']['token_secret'], true);
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        throw new RuntimeException('Unable to decrypt Google token');
    }
    return $plaintext;
}

function google_authorization_url(int $userId): string
{
    $google = google_config();
    $state = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO google_oauth_states(state, user_id, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 10 MINUTE)'
    )->execute([$state, $userId]);

    $params = [
        'client_id' => $google['client_id'],
        'redirect_uri' => $google['redirect_uri'],
        'response_type' => 'code',
        'scope' => implode(' ', [
            'https://www.googleapis.com/auth/contacts.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
        ]),
        'access_type' => 'offline',
        'include_granted_scopes' => 'true',
        'state' => $state,
        'prompt' => 'consent',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function google_exchange_code(string $code): array
{
    $google = google_config();
    [$status, $response] = google_http(
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'code' => $code,
            'client_id' => $google['client_id'],
            'client_secret' => $google['client_secret'],
            'redirect_uri' => $google['redirect_uri'],
            'grant_type' => 'authorization_code',
        ], '', '&', PHP_QUERY_RFC3986),
        'POST'
    );
    if ($status !== 200 || empty($response['access_token'])) {
        throw new RuntimeException('Google authorization code exchange failed');
    }
    return $response;
}

function google_refresh_access_token(string $refreshToken): string
{
    $google = google_config();
    [$status, $response] = google_http(
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id' => $google['client_id'],
            'client_secret' => $google['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ], '', '&', PHP_QUERY_RFC3986),
        'POST'
    );
    if ($status !== 200 || empty($response['access_token'])) {
        throw new RuntimeException('Google access token refresh failed');
    }
    return $response['access_token'];
}

function google_userinfo(string $accessToken): array
{
    [$status, $response] = google_http(
        'https://openidconnect.googleapis.com/v1/userinfo',
        ['Authorization: Bearer ' . $accessToken]
    );
    if ($status !== 200 || empty($response['sub'])) {
        throw new RuntimeException('Unable to retrieve Google account information');
    }
    return $response;
}

function google_access_token_for_account(array $account): string
{
    return google_refresh_access_token(google_decrypt($account['refresh_token_encrypted']));
}

function google_sync_contacts(int $userId): array
{
    $accountStmt = db()->prepare('SELECT * FROM google_accounts WHERE user_id = ? LIMIT 1');
    $accountStmt->execute([$userId]);
    $account = $accountStmt->fetch();
    if (!$account) {
        fail('Google account is not connected', 404);
    }

    $accessToken = google_access_token_for_account($account);
    $syncToken = $account['contacts_sync_token'] ?: null;
    $personFields = 'metadata,names,emailAddresses,phoneNumbers,photos';
    $pageToken = null;
    $nextSyncToken = null;
    $addedOrUpdated = 0;
    $deleted = 0;
    $seen = 0;
    $refreshedAccessToken = false;
    $fullResync = false;

    do {
        $params = [
            'personFields' => $personFields,
            'pageSize' => '1000',
            'requestSyncToken' => $syncToken ? 'false' : 'true',
            'sortOrder' => 'LAST_MODIFIED_ASCENDING',
        ];
        if ($syncToken) {
            $params['syncToken'] = $syncToken;
        }
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        [$status, $response] = google_http(
            'https://people.googleapis.com/v1/people/me/connections?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            ['Authorization: Bearer ' . $accessToken]
        );

        if ($status === 401 && !$refreshedAccessToken) {
            $accessToken = google_access_token_for_account($account);
            $refreshedAccessToken = true;
            continue;
        }
        if ($status === 410 && $syncToken) {
            // Google invalidated the stored sync token. Rebuild the local contact set.
            $syncToken = null;
            $pageToken = null;
            $nextSyncToken = null;
            $fullResync = true;
            db()->prepare('UPDATE google_accounts SET contacts_sync_token = NULL WHERE id = ?')->execute([$account['id']]);
            db()->prepare('UPDATE google_contacts SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE google_account_id = ? AND deleted_at IS NULL')->execute([$account['id']]);
            continue;
        }
        if ($status !== 200) {
            throw new RuntimeException('Google Contacts sync failed');
        }

        foreach (($response['connections'] ?? []) as $person) {
            $seen++;
            $resourceName = (string)($person['resourceName'] ?? '');
            if ($resourceName === '') {
                continue;
            }

            $metadata = $person['metadata'] ?? [];
            if (!empty($metadata['deleted'])) {
                db()->prepare(
                    'UPDATE google_contacts SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE google_account_id = ? AND resource_name = ?'
                )->execute([$account['id'], $resourceName]);
                $deleted++;
                continue;
            }

            $name = $person['names'][0] ?? [];
            $email = $person['emailAddresses'][0]['value'] ?? null;
            $phone = $person['phoneNumbers'][0]['value'] ?? null;
            $photo = $person['photos'][0]['url'] ?? null;
            $etag = $person['etag'] ?? null;
            $displayName = $name['displayName'] ?? null;
            $givenName = $name['givenName'] ?? null;
            $familyName = $name['familyName'] ?? null;

            $sql = 'INSERT INTO google_contacts
                (user_id, google_account_id, resource_name, display_name, given_name, family_name, email, phone, photo_url, google_etag, deleted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
                ON DUPLICATE KEY UPDATE
                    display_name=VALUES(display_name), given_name=VALUES(given_name), family_name=VALUES(family_name),
                    email=VALUES(email), phone=VALUES(phone), photo_url=VALUES(photo_url), google_etag=VALUES(google_etag),
                    deleted_at=NULL, updated_at=UTC_TIMESTAMP()';
            db()->prepare($sql)->execute([
                $userId, $account['id'], $resourceName, $displayName, $givenName, $familyName,
                $email, $phone, $photo, $etag
            ]);
            $addedOrUpdated++;
        }

        $pageToken = $response['nextPageToken'] ?? null;
        if (!$pageToken && !empty($response['nextSyncToken'])) {
            $nextSyncToken = $response['nextSyncToken'];
        }
    } while ($pageToken);

    if ($nextSyncToken) {
        db()->prepare(
            'UPDATE google_accounts SET contacts_sync_token = ?, last_contacts_sync_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$nextSyncToken, $account['id']]);
    } else {
        db()->prepare('UPDATE google_accounts SET last_contacts_sync_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$account['id']]);
    }

    return [
        'synced' => $addedOrUpdated,
        'deleted' => $deleted,
        'processed' => $seen,
        'incremental' => !$fullResync && (bool)$account['contacts_sync_token'],
        'full_resync' => $fullResync,
    ];
}
