-- Migration: Add AI Discussion Insights Table
CREATE TABLE IF NOT EXISTS ai_discussion_insights (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discussion_type ENUM('topic', 'debate') NOT NULL DEFAULT 'topic',
    discussion_id INT NOT NULL,
    summary TEXT NOT NULL,
    arguments_for TEXT NOT NULL,
    arguments_against TEXT NOT NULL,
    common_ground TEXT NOT NULL,
    unresolved_questions TEXT NOT NULL,
    model_used VARCHAR(100) DEFAULT 'Gemini',
    post_count_analyzed INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_discussion_insight (discussion_type, discussion_id)
);
