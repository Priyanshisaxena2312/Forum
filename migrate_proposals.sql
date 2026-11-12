-- Migration: Add Ideas to Action (Proposals, Proposal Votes, Proposal Updates)

CREATE TABLE IF NOT EXISTS proposals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    problem_description TEXT NOT NULL,
    solution_description TEXT NOT NULL,
    discussion_id INT NULL,
    discussion_type ENUM('topic', 'debate') DEFAULT 'topic',
    status ENUM('open', 'under_review', 'accepted', 'in_progress', 'resolved') NOT NULL DEFAULT 'open',
    votes_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (discussion_id) REFERENCES topics(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposal_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    user_id INT NOT NULL,
    vote_type ENUM('up', 'down') DEFAULT 'up',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_proposal_vote (proposal_id, user_id),
    FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposal_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    user_id INT NOT NULL,
    old_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed initial proposal linked to topic 1 if empty
INSERT INTO proposals (id, user_id, title, slug, problem_description, solution_description, discussion_id, discussion_type, status, votes_count)
SELECT 1, 1, 'Official Campus Esports & Gaming Arena Setup', 'official-campus-esports-gaming-arena-setup',
'Gamers on campus currently lack dedicated high-speed infrastructure, team practice rooms, and official event organization, leading to fragmented informal groups and missed tournament opportunities.',
'Establish a student-run Esports & Tech Hub in the student center with 10 high-performance workstations, scheduled weekly scrimmage brackets, and official collegiate tournament sponsorship.',
1, 'topic', 'under_review', 12
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM proposals WHERE id = 1);

INSERT INTO proposal_updates (proposal_id, user_id, old_status, new_status, note)
SELECT 1, 1, NULL, 'open', 'Proposal initially submitted by student council gaming reps based on community interest.'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM proposal_updates WHERE proposal_id = 1 AND new_status = 'open');

INSERT INTO proposal_updates (proposal_id, user_id, old_status, new_status, note)
SELECT 1, 1, 'open', 'under_review', 'Reviewed by Student Activities Board. Equipment budget and room reservation being evaluated with IT department.'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM proposal_updates WHERE proposal_id = 1 AND new_status = 'under_review');
