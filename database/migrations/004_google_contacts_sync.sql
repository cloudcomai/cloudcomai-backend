CREATE TABLE IF NOT EXISTS google_oauth_states (
    state VARCHAR(128) NOT NULL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_google_oauth_states_expiry (expires_at),
    INDEX idx_google_oauth_states_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS google_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    google_subject VARCHAR(255) NULL,
    google_email VARCHAR(320) NULL,
    refresh_token_encrypted TEXT NOT NULL,
    scope TEXT NULL,
    contacts_sync_token TEXT NULL,
    last_contacts_sync_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_google_accounts_user (user_id),
    UNIQUE KEY uq_google_accounts_subject (google_subject),
    INDEX idx_google_accounts_email (google_email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS google_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    google_account_id BIGINT UNSIGNED NOT NULL,
    resource_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NULL,
    given_name VARCHAR(255) NULL,
    family_name VARCHAR(255) NULL,
    email VARCHAR(320) NULL,
    phone VARCHAR(100) NULL,
    photo_url TEXT NULL,
    google_etag VARCHAR(255) NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_google_contacts_resource (google_account_id, resource_name),
    INDEX idx_google_contacts_user (user_id),
    INDEX idx_google_contacts_email (user_id, email),
    CONSTRAINT fk_google_contacts_account FOREIGN KEY (google_account_id) REFERENCES google_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;
