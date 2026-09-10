CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('officer','advisor') NOT NULL DEFAULT 'officer',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(255) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    nickname VARCHAR(80) NULL,
    confirmation_nickname VARCHAR(80) NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NULL,
    grade VARCHAR(30) NULL,
    graduation_year SMALLINT UNSIGNED NULL,
    schoology_user_id CHAR(36) NULL,
    schoology_username VARCHAR(50) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    standing_reset_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_member_name (last_name, first_name),
    INDEX idx_member_email (email),
    INDEX idx_schoology_user_id (schoology_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_token CHAR(32) NOT NULL UNIQUE,
    early_public_token CHAR(32) NULL UNIQUE,
    attendance_mode VARCHAR(32) NOT NULL DEFAULT 'standard',
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    location VARCHAR(160) NULL,
    term VARCHAR(80) NOT NULL,
    event_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    checkin_opens DATETIME NOT NULL,
    checkin_closes DATETIME NOT NULL,
    max_points DECIMAL(5,2) NOT NULL DEFAULT 2,
    required TINYINT(1) NOT NULL DEFAULT 1,
    question VARCHAR(255) NOT NULL,
    answer_hash VARCHAR(255) NOT NULL,
    schoology_assignment VARCHAR(255) NULL,
    is_open TINYINT(1) NOT NULL DEFAULT 2 COMMENT '0=forced closed, 1=forced open, 2=scheduled',
    early_is_open TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Decorations Day early-departure QR state',
    locked_at DATETIME NULL,
    finalized_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_creator FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_event_date (event_date),
    INDEX idx_event_window (is_open, checkin_opens, checkin_closes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    status ENUM('present','late','excused','absent','exempt') NOT NULL,
    points DECIMAL(5,2) NOT NULL DEFAULT 0,
    check_in_time DATETIME NULL,
    checkin_device_hash CHAR(64) NULL,
    answer_attempt VARCHAR(255) NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_member_event (member_id, event_id),
    CONSTRAINT fk_att_member FOREIGN KEY (member_id) REFERENCES members(id),
    CONSTRAINT fk_att_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    shift_time TIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_shift_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_shift_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_event_shift_member (event_id, member_id),
    INDEX idx_event_shift_time (event_id, shift_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    shift_time TIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_shift_member (event_id, member_id),
    INDEX idx_event_shift_time (event_id, shift_time),
    CONSTRAINT fk_event_shift_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_shift_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkin_device_events (
    device_hash CHAR(64) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NULL,
    correction_used TINYINT(1) NOT NULL DEFAULT 0,
    first_checkin_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (device_hash, event_id),
    CONSTRAINT fk_device_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_member FOREIGN KEY (member_id) REFERENCES members(id),
    INDEX idx_device_event_member (event_id, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    category ENUM('school_event','sports','illness','family','work','other') NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_notice_member_event (member_id, event_id),
    CONSTRAINT fk_notice_member FOREIGN KEY (member_id) REFERENCES members(id),
    CONSTRAINT fk_notice_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_notice_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
