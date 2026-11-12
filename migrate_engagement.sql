-- Migration: Improve User Profiles, Engagement, Followed Topics, Notifications, Badges, Blocks & Moderation

-- 1. Add interests and is_banned columns to users table
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS interests VARCHAR(500) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS is_banned TINYINT(1) DEFAULT 0;

-- 2. Saved discussions (topics, debates, proposals)
CREATE TABLE IF NOT EXISTS saved_discussions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    item_type ENUM('topic', 'debate', 'proposal') NOT NULL,
    item_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_saved (user_id, item_type, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Followed topics / discussions for reply & update notifications
CREATE TABLE IF NOT EXISTS followed_topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    item_type ENUM('topic', 'debate', 'proposal') NOT NULL DEFAULT 'topic',
    item_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_followed (user_id, item_type, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Blocked users table
CREATE TABLE IF NOT EXISTS blocked_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blocker_id INT NOT NULL,
    blocked_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_block (blocker_id, blocked_id),
    FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Ensure notifications table has appropriate indexes
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT DEFAULT 0,
    link VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Add item_type and item_id to reports table for universal reporting
ALTER TABLE reports
ADD COLUMN IF NOT EXISTS item_type ENUM('post', 'topic', 'debate', 'proposal', 'user') NOT NULL DEFAULT 'post',
ADD COLUMN IF NOT EXISTS item_id INT NULL;

-- Populate item_id from post_id if missing
UPDATE reports SET item_id = post_id WHERE item_id IS NULL AND post_id IS NOT NULL;
