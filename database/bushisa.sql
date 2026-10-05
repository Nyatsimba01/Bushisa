-- database/bushisa.sql
-- NUST Bushisa Database Schema based on Structural Feasibility Report

CREATE DATABASE IF NOT EXISTS bushisa_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bushisa_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS consent_records, audit_log, reports, confessions, messages, matches, swipes, preferences, profiles, users;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Users Table (Restricted to NUST student emails)
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nust_student_id VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_verified TINYINT(1) DEFAULT 0,
    is_suspended TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Profiles Table (Core matchmaking dimensions & anonymous confession handle)
CREATE TABLE profiles (
    user_id INT UNSIGNED PRIMARY KEY,
    display_name VARCHAR(50) NOT NULL,
    anon_handle VARCHAR(50) NOT NULL UNIQUE,
    bio VARCHAR(500) DEFAULT '',
    gender ENUM('male', 'female') NOT NULL,
    date_of_birth DATE NULL,
    faculty VARCHAR(100) NOT NULL DEFAULT 'Applied Sciences',
    year_of_study ENUM('Part 1', 'Part 2', 'Part 3', 'Part 4', 'Postgrad') NOT NULL DEFAULT 'Part 1',
    profile_photo_path VARCHAR(255) NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Preferences Table (Opposite-gender / discovery matching criteria)
CREATE TABLE preferences (
    user_id INT UNSIGNED PRIMARY KEY,
    preferred_gender ENUM('male', 'female') NOT NULL,
    age_range_min TINYINT UNSIGNED DEFAULT 18,
    age_range_max TINYINT UNSIGNED DEFAULT 30,
    preferred_faculty VARCHAR(100) DEFAULT 'Any',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Swipes Table (Interaction records for matching logic)
CREATE TABLE swipes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    swiper_id INT UNSIGNED NOT NULL,
    swiped_id INT UNSIGNED NOT NULL,
    direction ENUM('like', 'pass') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_swipe (swiper_id, swiped_id),
    FOREIGN KEY (swiper_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (swiped_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Matches Table (Created when both swipe 'like')
CREATE TABLE matches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_a_id INT UNSIGNED NOT NULL,
    user_b_id INT UNSIGNED NOT NULL,
    matched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_match (user_a_id, user_b_id),
    FOREIGN KEY (user_a_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user_b_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Messages Table (Chat history per match)
CREATE TABLE messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) DEFAULT 0,
    FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Confessions Table (Board with anonymous attribution)
CREATE TABLE confessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    is_flagged TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Reports Table (User safety moderation)
CREATE TABLE reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT UNSIGNED NOT NULL,
    reported_user_id INT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    evidence_path VARCHAR(255) NULL,
    status ENUM('pending', 'reviewed', 'dismissed', 'action_taken') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Audit Log Table (Tracking sensitive actions)
CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Consent Records Table (POPIA/GDPR compliance tracking)
CREATE TABLE consent_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    consent_type VARCHAR(100) NOT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;