-- Chat attachment support with sender-controlled download approval.
-- Run once against the existing CloudComAI database.

CREATE TABLE IF NOT EXISTS message_attachments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 message_id BIGINT UNSIGNED NOT NULL,
 original_filename VARCHAR(255) NOT NULL,
 stored_filename VARCHAR(255) NOT NULL UNIQUE,
 storage_path VARCHAR(500) NOT NULL,
 mime_type VARCHAR(150) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 download_policy ENUM('ALLOW','APPROVAL_REQUIRED','VIEW_ONLY') NOT NULL DEFAULT 'APPROVAL_REQUIRED',
 created_at DATETIME NOT NULL,
 INDEX(message_id),
 INDEX(download_policy)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attachment_download_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 attachment_id BIGINT UNSIGNED NOT NULL,
 requester_id BIGINT UNSIGNED NOT NULL,
 sender_id BIGINT UNSIGNED NOT NULL,
 status ENUM('PENDING','APPROVED','DENIED') NOT NULL DEFAULT 'PENDING',
 requested_at DATETIME NOT NULL,
 responded_at DATETIME NULL,
 UNIQUE KEY uq_attachment_request(attachment_id,requester_id),
 INDEX(sender_id,status),
 INDEX(requester_id,status),
 INDEX(attachment_id,status)
) ENGINE=InnoDB;
