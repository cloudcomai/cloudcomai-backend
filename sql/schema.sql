CREATE DATABASE IF NOT EXISTS cloudcomai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE i10982974_m6at1;

CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id VARCHAR(30) UNIQUE NULL,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) UNIQUE NULL,
 mobile VARCHAR(30) UNIQUE NULL,
 password_hash VARCHAR(255) NOT NULL,
 dob DATE NOT NULL,
 gender ENUM('Male','Female') NOT NULL,
 email_verified TINYINT(1) NOT NULL DEFAULT 0,
 mobile_verified TINYINT(1) NOT NULL DEFAULT 0,
 account_status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE chats (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 type ENUM('private','group','public','community') NOT NULL,
 name VARCHAR(160) NULL,
 group_category VARCHAR(80) NULL,
 owner_id BIGINT UNSIGNED NULL,
 retention_seconds INT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NULL,
 INDEX(owner_id), INDEX(group_category)
) ENGINE=InnoDB;

CREATE TABLE chat_members (
 chat_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 role ENUM('owner','admin','moderator','member','readonly') NOT NULL DEFAULT 'member',
 status ENUM('active','pending','removed','banned') NOT NULL DEFAULT 'active',
 joined_at DATETIME NOT NULL,
 PRIMARY KEY(chat_id,user_id), INDEX(user_id,status)
) ENGINE=InnoDB;

CREATE TABLE messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chat_id BIGINT UNSIGNED NOT NULL,
 sender_id BIGINT UNSIGNED NOT NULL,
 type VARCHAR(40) NOT NULL DEFAULT 'text',
 body TEXT NULL,
 reply_to_message_id BIGINT UNSIGNED NULL,
 edit_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
 edited_at DATETIME NULL,
 deleted_for_everyone TINYINT(1) NOT NULL DEFAULT 0,
 expires_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 INDEX(chat_id,id), INDEX(sender_id), INDEX(expires_at), INDEX(reply_to_message_id)
) ENGINE=InnoDB;

CREATE TABLE message_user_states (
 message_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 hidden TINYINT(1) NOT NULL DEFAULT 0,
 PRIMARY KEY(message_id,user_id)
) ENGINE=InnoDB;

CREATE TABLE group_invites (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chat_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 created_by BIGINT UNSIGNED NOT NULL,
 requires_approval TINYINT(1) NOT NULL DEFAULT 0,
 max_uses INT UNSIGNED NULL,
 use_count INT UNSIGNED NOT NULL DEFAULT 0,
 expires_at DATETIME NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 INDEX(chat_id), INDEX(active,expires_at)
) ENGINE=InnoDB;

CREATE TABLE group_shortcuts (
 user_id BIGINT UNSIGNED NOT NULL,
 chat_id BIGINT UNSIGNED NOT NULL,
 display_order INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(user_id,chat_id)
) ENGINE=InnoDB;

CREATE TABLE user_interests (
 user_id BIGINT UNSIGNED NOT NULL,
 interest VARCHAR(100) NOT NULL,
 display_order INT NOT NULL DEFAULT 0,
 pinned TINYINT(1) NOT NULL DEFAULT 1,
 hidden TINYINT(1) NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(user_id,interest)
) ENGINE=InnoDB;

CREATE TABLE polls (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chat_id BIGINT UNSIGNED NOT NULL,
 creator_id BIGINT UNSIGNED NOT NULL,
 question VARCHAR(500) NOT NULL,
 multiple_choice TINYINT(1) NOT NULL DEFAULT 0,
 anonymous TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 closes_at DATETIME NULL,
 closed_at DATETIME NULL,
 INDEX(chat_id)
) ENGINE=InnoDB;
CREATE TABLE poll_options (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,poll_id BIGINT UNSIGNED NOT NULL,option_text VARCHAR(300) NOT NULL,display_order INT NOT NULL,INDEX(poll_id)) ENGINE=InnoDB;
CREATE TABLE poll_votes (poll_id BIGINT UNSIGNED NOT NULL,option_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(poll_id,option_id,user_id)) ENGINE=InnoDB;

CREATE TABLE stories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 type VARCHAR(30) NOT NULL,
 content TEXT NOT NULL,
 audience VARCHAR(40) NOT NULL DEFAULT 'friends',
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 deleted_at DATETIME NULL,
 INDEX(user_id), INDEX(expires_at)
) ENGINE=InnoDB;

CREATE TABLE live_locations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chat_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 latitude DECIMAL(10,7) NOT NULL,
 longitude DECIMAL(10,7) NOT NULL,
 started_at DATETIME NOT NULL,
 last_update_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 stopped_at DATETIME NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 INDEX(chat_id,active), INDEX(expires_at)
) ENGINE=InnoDB;

CREATE TABLE calls (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 caller_id BIGINT UNSIGNED NOT NULL,
 recipient_id BIGINT UNSIGNED NOT NULL,
 type ENUM('audio','video') NOT NULL,
 status VARCHAR(30) NOT NULL,
 session_token_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NULL,
 expires_at DATETIME NOT NULL,
 INDEX(caller_id),INDEX(recipient_id),INDEX(status)
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 INDEX(user_id),
 INDEX(expires_at),
 INDEX(user_id,used_at)
) ENGINE=InnoDB;
