-- Migration for Perspective Debates

CREATE TABLE IF NOT EXISTS debates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    views INT DEFAULT 0,
    is_locked TINYINT DEFAULT 0,
    is_deleted TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS debate_arguments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    debate_id INT NOT NULL,
    user_id INT NOT NULL,
    parent_id INT NULL DEFAULT NULL,
    side ENUM('for', 'against') NOT NULL,
    content TEXT NOT NULL,
    helpful_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (debate_id) REFERENCES debates(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES debate_arguments(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS debate_argument_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    argument_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_arg_vote (argument_id, user_id),
    FOREIGN KEY (argument_id) REFERENCES debate_arguments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS debate_polls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    debate_id INT NOT NULL,
    user_id INT NOT NULL,
    vote ENUM('for', 'against', 'undecided') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_debate_vote (debate_id, user_id),
    FOREIGN KEY (debate_id) REFERENCES debates(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Seed Debates if empty
INSERT INTO debates (id, user_id, category_id, title, slug, description, views)
SELECT 1, 1, 2, 'Will Generative AI Replace Junior Software Developers by 2030?', 'will-generative-ai-replace-junior-developers-by-2030', 
'With AI agents writing code, generating entire web apps, and diagnosing bugs, will entry-level software engineering positions disappear or evolve into prompt architecture?', 42
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM debates WHERE id = 1);

INSERT INTO debates (id, user_id, category_id, title, slug, description, views)
SELECT 2, 2, 1, 'Remote Work vs. Hybrid/In-Office: Is Full Remote Better for Long-term Career Growth?', 'remote-work-vs-in-office-career-growth',
'While remote work offers zero commute and flexible hours, does lack of in-person mentorship and hallway encounters stunt junior and mid-level promotion velocity?', 29
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM debates WHERE id = 2);

-- Seed Arguments for Debate 1
INSERT INTO debate_arguments (id, debate_id, user_id, parent_id, side, content, helpful_count)
SELECT 1, 1, 2, NULL, 'for', 'AI tooling is accelerating productivity by 5-10x. Routine tasks like boilerplate API design, unit testing, and basic CRUD generation require fewer heads. Companies will hire fewer junior devs and expect seniors to leverage AI directly.', 14
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM debate_arguments WHERE id = 1);

INSERT INTO debate_arguments (id, debate_id, user_id, parent_id, side, content, helpful_count)
SELECT 2, 1, 3, NULL, 'against', 'Software development is primarily understanding complex business requirements, edge cases, system architecture, and security trade-offs. AI cannot replace the critical thinking and contextual understanding that juniors develop into seniors.', 19
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM debate_arguments WHERE id = 2);

-- Seed replies to arguments
INSERT INTO debate_arguments (id, debate_id, user_id, parent_id, side, content, helpful_count)
SELECT 3, 1, 1, 2, 'against', 'Completely agree. Without juniors today, who becomes the senior engineer tomorrow? The pipeline of talent would collapse if companies stopped hiring entry-level talent.', 8
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM debate_arguments WHERE id = 3);

INSERT INTO debate_arguments (id, debate_id, user_id, parent_id, side, content, helpful_count)
SELECT 4, 1, 1, NULL, 'for', 'The definition of junior will shift: knowing syntax will be table stakes, and juniors will need to know systems thinking much earlier.', 6
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM debate_arguments WHERE id = 4);

-- Seed Poll votes for Debate 1
INSERT INTO debate_polls (debate_id, user_id, vote)
SELECT 1, 1, 'against' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM debate_polls WHERE debate_id = 1 AND user_id = 1);

INSERT INTO debate_polls (debate_id, user_id, vote)
SELECT 1, 2, 'for' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM debate_polls WHERE debate_id = 1 AND user_id = 2);

INSERT INTO debate_polls (debate_id, user_id, vote)
SELECT 1, 3, 'against' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM debate_polls WHERE debate_id = 1 AND user_id = 3);
