<?php
function sanitize_input($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function time_ago($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . "m ago";
    if ($diff < 86400) return floor($diff / 3600) . "h ago";
    if ($diff < 2592000) return floor($diff / 86400) . "d ago";
    return date("M j, Y", $time);
}

function get_avatar_initials($name) {
    preg_match_all('/(?<=\s|^)[a-z]/i', $name, $matches);
    if(empty($matches[0])) {
        return strtoupper(substr($name, 0, 1));
    }
    $initials = strtoupper(implode('', $matches[0]));
    return substr($initials, 0, 2);
}

function paginate($total, $per_page, $current_page, $url_params = '') {
    $pages = ceil($total / $per_page);
    if ($pages <= 1) return '';
    
    $html = '<div class="pagination">';
    
    if ($current_page > 1) {
        $html .= '<a href="?page=' . ($current_page - 1) . $url_params . '">&laquo; Prev</a>';
    }
    
    for ($i = 1; $i <= $pages; $i++) {
        $active = ($i == $current_page) ? 'class="active"' : '';
        $html .= '<a href="?page=' . $i . $url_params . '" ' . $active . '>' . $i . '</a>';
    }
    
    if ($current_page < $pages) {
        $html .= '<a href="?page=' . ($current_page + 1) . $url_params . '">Next &raquo;</a>';
    }
    
    $html .= '</div>';
    return $html;
}

function generate_slug($string) {
    $slug = preg_replace('/[^a-zA-Z0-9-]/', '-', strtolower($string));
    return preg_replace('/-+/', '-', $slug);
}

function get_reputation_badge($score) {
    if ($score < 15) {
        return '<span class="rep-tier-badge tier-newcomer" title="Newcomer (<15 rep)"><i class="fas fa-seedling"></i> Newcomer</span>';
    } elseif ($score < 50) {
        return '<span class="rep-tier-badge tier-active" title="Active Contributor (15-49 rep)"><i class="fas fa-star"></i> Contributor</span>';
    } elseif ($score < 100) {
        return '<span class="rep-tier-badge tier-respected" title="Respected Voice (50-99 rep)"><i class="fas fa-award"></i> Respected</span>';
    } else {
        return '<span class="rep-tier-badge tier-pillar" title="Community Pillar (100+ rep)"><i class="fas fa-crown"></i> Pillar</span>';
    }
}

function get_debate_poll_stats($pdo, $debate_id) {
    $stmt = $pdo->prepare("SELECT vote, COUNT(*) as count FROM debate_polls WHERE debate_id = ? GROUP BY vote");
    $stmt->execute([$debate_id]);
    $results = $stmt->fetchAll();
    
    $stats = [
        'for' => 0, 
        'against' => 0, 
        'undecided' => 0, 
        'total' => 0, 
        'pct_for' => 0, 
        'pct_against' => 0, 
        'pct_undecided' => 0
    ];
    foreach ($results as $r) {
        if (isset($stats[$r['vote']])) {
            $stats[$r['vote']] = (int)$r['count'];
            $stats['total'] += (int)$r['count'];
        }
    }
    if ($stats['total'] > 0) {
        $stats['pct_for'] = round(($stats['for'] / $stats['total']) * 100);
        $stats['pct_against'] = round(($stats['against'] / $stats['total']) * 100);
        $stats['pct_undecided'] = max(0, 100 - $stats['pct_for'] - $stats['pct_against']);
    }
    return $stats;
}

function get_proposal_statuses() {
    return [
        'open' => [
            'key' => 'open',
            'label' => 'Open',
            'step' => 1,
            'icon' => 'fas fa-door-open',
            'color' => '#6366f1'
        ],
        'under_review' => [
            'key' => 'under_review',
            'label' => 'Under Review',
            'step' => 2,
            'icon' => 'fas fa-magnifying-glass',
            'color' => '#f59e0b'
        ],
        'accepted' => [
            'key' => 'accepted',
            'label' => 'Accepted',
            'step' => 3,
            'icon' => 'fas fa-circle-check',
            'color' => '#10b981'
        ],
        'in_progress' => [
            'key' => 'in_progress',
            'label' => 'In Progress',
            'step' => 4,
            'icon' => 'fas fa-screwdriver-wrench',
            'color' => '#3b82f6'
        ],
        'resolved' => [
            'key' => 'resolved',
            'label' => 'Resolved',
            'step' => 5,
            'icon' => 'fas fa-flag-checkered',
            'color' => '#a855f7'
        ]
    ];
}

function get_proposal_status_badge($status) {
    $statuses = get_proposal_statuses();
    $meta = $statuses[$status] ?? [
        'key' => $status,
        'label' => ucfirst(str_replace('_', ' ', $status)),
        'icon' => 'fas fa-circle-info',
        'color' => '#9898a6'
    ];
    return '<span class="proposal-status-badge status-' . htmlspecialchars($meta['key'] ?? $status) . '">'
        . '<i class="' . htmlspecialchars($meta['icon']) . '"></i> '
        . htmlspecialchars($meta['label'])
        . '</span>';
}

/**
 * Ensure engagement tables and schema adjustments exist automatically
 */
function ensure_engagement_schema($pdo) {
    static $initialized = false;
    if ($initialized || !$pdo) return;
    $initialized = true;

    try {
        // 1. Users extra columns
        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'interests'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN interests VARCHAR(500) DEFAULT NULL");
        }
        $cols_ban = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_banned'")->fetchAll();
        if (empty($cols_ban)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_banned TINYINT(1) DEFAULT 0");
        }

        // 2. Saved discussions table
        $pdo->exec("CREATE TABLE IF NOT EXISTS saved_discussions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            item_type ENUM('topic', 'debate', 'proposal') NOT NULL,
            item_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_saved (user_id, item_type, item_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 3. Followed topics table
        $pdo->exec("CREATE TABLE IF NOT EXISTS followed_topics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            item_type ENUM('topic', 'debate', 'proposal') NOT NULL DEFAULT 'topic',
            item_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_followed (user_id, item_type, item_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4. Blocked users table
        $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            blocker_id INT NOT NULL,
            blocked_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_block (blocker_id, blocked_id),
            FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 5. Notifications table
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT DEFAULT 0,
            link VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_read (user_id, is_read),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 6. Reports extra columns
        $rep_cols = $pdo->query("SHOW COLUMNS FROM reports LIKE 'item_type'")->fetchAll();
        if (empty($rep_cols)) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN item_type ENUM('post', 'topic', 'debate', 'proposal', 'user') NOT NULL DEFAULT 'post'");
            $pdo->exec("ALTER TABLE reports ADD COLUMN item_id INT NULL");
            $pdo->exec("UPDATE reports SET item_id = post_id WHERE item_id IS NULL AND post_id IS NOT NULL");
        }
    } catch (Exception $e) {
        // Silently ignore if already altered or restricted
    }
}

/**
 * ==========================================================
 * BADGES & REPUTATION ENGINE (Constructive Contributions)
 * ==========================================================
 */

function get_user_badges($user_id, $pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo || !$user_id) return [];

    // Fetch user details
    $stmt = $pdo->prepare("SELECT id, username, reputation, role, created_at, interests FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();
    if (!$u) return [];

    $reputation = (int)$u['reputation'];

    // Topic count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM topics WHERE user_id = ? AND is_deleted = 0");
    $stmt->execute([$user_id]);
    $topics_count = (int)$stmt->fetchColumn();

    // Reply count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ? AND is_deleted = 0");
    $stmt->execute([$user_id]);
    $posts_count = (int)$stmt->fetchColumn();

    // Total likes received on posts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM likes l JOIN posts p ON l.post_id = p.id WHERE p.user_id = ?");
    $stmt->execute([$user_id]);
    $likes_received = (int)$stmt->fetchColumn();

    // Debates created
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM debates WHERE user_id = ? AND is_deleted = 0");
    $stmt->execute([$user_id]);
    $debates_count = (int)$stmt->fetchColumn();

    // Debate arguments contributed
    $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(helpful_count), 0) FROM debate_arguments WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $arg_stats = $stmt->fetch(PDO::FETCH_NUM);
    $arguments_count = (int)($arg_stats[0] ?? 0);
    $helpful_votes_received = (int)($arg_stats[1] ?? 0);

    // Proposals created & accepted
    $stmt = $pdo->prepare("SELECT COUNT(*), SUM(CASE WHEN status IN ('accepted', 'in_progress', 'resolved') THEN 1 ELSE 0 END) FROM proposals WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $prop_stats = $stmt->fetch(PDO::FETCH_NUM);
    $proposals_count = (int)($prop_stats[0] ?? 0);
    $proposals_accepted = (int)($prop_stats[1] ?? 0);

    // Badges definitions
    $all_badges = [
        [
            'id' => 'newcomer',
            'title' => 'Community Member',
            'icon' => 'fas fa-seedling',
            'color' => '#10b981',
            'gradient' => 'linear-gradient(135deg, #10b981, #059669)',
            'description' => 'Joined the Forum community and started exploring campus discussions.',
            'unlocked' => true,
            'progress' => 'Achieved'
        ],
        [
            'id' => 'idea_catalyst',
            'title' => 'Idea Catalyst',
            'icon' => 'fas fa-lightbulb',
            'color' => '#f59e0b',
            'gradient' => 'linear-gradient(135deg, #f59e0b, #d97706)',
            'description' => 'Submitted constructive proposals in Ideas to Action to improve campus life.',
            'unlocked' => ($proposals_count >= 1),
            'progress' => "$proposals_count / 1 Proposals"
        ],
        [
            'id' => 'change_maker',
            'title' => 'Change Maker',
            'icon' => 'fas fa-rocket',
            'color' => '#8b5cf6',
            'gradient' => 'linear-gradient(135deg, #8b5cf6, #6d28d9)',
            'description' => 'Authored a proposal that was accepted, put in progress, or successfully resolved.',
            'unlocked' => ($proposals_accepted >= 1),
            'progress' => "$proposals_accepted / 1 Accepted Proposals"
        ],
        [
            'id' => 'persuasive_thinker',
            'title' => 'Persuasive Thinker',
            'icon' => 'fas fa-scale-balanced',
            'color' => '#0ea5e9',
            'gradient' => 'linear-gradient(135deg, #0ea5e9, #0284c7)',
            'description' => 'Received 3+ helpful votes from peers for well-reasoned debate arguments.',
            'unlocked' => ($helpful_votes_received >= 3),
            'progress' => "$helpful_votes_received / 3 Helpful Votes"
        ],
        [
            'id' => 'debate_champion',
            'title' => 'Debate Champion',
            'icon' => 'fas fa-trophy',
            'color' => '#eab308',
            'gradient' => 'linear-gradient(135deg, #eab308, #ca8a04)',
            'description' => 'Contributed 5 or more structured perspectives to community debates.',
            'unlocked' => ($arguments_count >= 5),
            'progress' => "$arguments_count / 5 Debate Arguments"
        ],
        [
            'id' => 'insightful_voice',
            'title' => 'Insightful Voice',
            'icon' => 'fas fa-heart',
            'color' => '#ec4899',
            'gradient' => 'linear-gradient(135deg, #ec4899, #db2777)',
            'description' => 'Received 5 or more likes across discussion replies for helpful answers.',
            'unlocked' => ($likes_received >= 5),
            'progress' => "$likes_received / 5 Likes"
        ],
        [
            'id' => 'discussion_pro',
            'title' => 'Engaged Scholar',
            'icon' => 'fas fa-comments',
            'color' => '#6366f1',
            'gradient' => 'linear-gradient(135deg, #6366f1, #4f46e5)',
            'description' => 'Started 3 or more campus discussions that fostered student dialogue.',
            'unlocked' => ($topics_count >= 3),
            'progress' => "$topics_count / 3 Topics"
        ],
        [
            'id' => 'community_pillar',
            'title' => 'Community Pillar',
            'icon' => 'fas fa-crown',
            'color' => '#f97316',
            'gradient' => 'linear-gradient(135deg, #f97316, #ea580c)',
            'description' => 'Reached 50+ reputation points through positive and constructive community impact.',
            'unlocked' => ($reputation >= 50),
            'progress' => "$reputation / 50 Reputation"
        ],
        [
            'id' => 'civic_guardian',
            'title' => 'Civic Guardian',
            'icon' => 'fas fa-shield-halved',
            'color' => '#06b6d4',
            'gradient' => 'linear-gradient(135deg, #06b6d4, #0891b2)',
            'description' => 'Serves as an appointed Community Moderator or Platform Administrator.',
            'unlocked' => in_array($u['role'], ['moderator', 'admin']),
            'progress' => ucfirst($u['role'])
        ]
    ];

    return [
        'stats' => [
            'reputation' => $reputation,
            'topics_count' => $topics_count,
            'posts_count' => $posts_count,
            'likes_received' => $likes_received,
            'debates_count' => $debates_count,
            'arguments_count' => $arguments_count,
            'helpful_votes_received' => $helpful_votes_received,
            'proposals_count' => $proposals_count,
            'proposals_accepted' => $proposals_accepted
        ],
        'badges' => $all_badges
    ];
}

function get_user_top_badges_html($user_id, $pdo = null, $limit = 2) {
    $data = get_user_badges($user_id, $pdo);
    if (empty($data['badges'])) return '';

    $unlocked = array_filter($data['badges'], fn($b) => $b['unlocked'] && $b['id'] !== 'newcomer');
    if (empty($unlocked)) {
        return '<span class="badge-mini" title="Community Member"><i class="fas fa-seedling" style="color:#10b981;"></i></span>';
    }

    $html = '';
    $count = 0;
    foreach ($unlocked as $badge) {
        if ($count >= $limit) break;
        $html .= '<span class="badge-mini" title="' . htmlspecialchars($badge['title'] . ': ' . $badge['description']) . '">'
              . '<i class="' . htmlspecialchars($badge['icon']) . '" style="color:' . htmlspecialchars($badge['color']) . '"></i> '
              . htmlspecialchars($badge['title'])
              . '</span> ';
        $count++;
    }
    return $html;
}

/**
 * ==========================================================
 * NOTIFICATIONS HELPER ENGINE
 * ==========================================================
 */

function create_notification($pdo, $user_id, $type, $message, $link = '#') {
    if (!$pdo || !$user_id || empty($message)) return false;
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$user_id, $type, $message, $link]);
    } catch (Exception $e) {
        return false;
    }
}

function get_unread_notification_count($pdo, $user_id) {
    if (!$pdo || !$user_id) return 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function notify_topic_followers($pdo, $topic_id, $exclude_user_id, $author_name, $topic_title, $post_id) {
    if (!$pdo || !$topic_id) return;
    try {
        // Fetch topic author
        $stmt = $pdo->prepare("SELECT user_id, title FROM topics WHERE id = ?");
        $stmt->execute([$topic_id]);
        $topic = $stmt->fetch();
        if (!$topic) return;

        $link = "topic.php?id=$topic_id#post-$post_id";
        $recipients = [];

        // Notify topic author if not the replier
        if ($topic['user_id'] != $exclude_user_id) {
            $recipients[$topic['user_id']] = [
                'type' => 'topic_reply',
                'message' => htmlspecialchars($author_name) . " replied to your topic: \"" . htmlspecialchars(mb_substr($topic['title'], 0, 40)) . "...\""
            ];
        }

        // Notify followers
        $stmt = $pdo->prepare("SELECT user_id FROM followed_topics WHERE item_type = 'topic' AND item_id = ? AND user_id != ?");
        $stmt->execute([$topic_id, $exclude_user_id]);
        $followers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($followers as $f_id) {
            if (!isset($recipients[$f_id])) {
                $recipients[$f_id] = [
                    'type' => 'followed_reply',
                    'message' => "New reply by " . htmlspecialchars($author_name) . " in followed topic: \"" . htmlspecialchars(mb_substr($topic['title'], 0, 40)) . "...\""
                ];
            }
        }

        foreach ($recipients as $uid => $data) {
            create_notification($pdo, $uid, $data['type'], $data['message'], $link);
        }
    } catch (Exception $e) {
        // Log or ignore
    }
}

function notify_proposal_followers($pdo, $proposal_id, $exclude_user_id, $proposal_title, $new_status_label) {
    if (!$pdo || !$proposal_id) return;
    try {
        $stmt = $pdo->prepare("SELECT user_id, title FROM proposals WHERE id = ?");
        $stmt->execute([$proposal_id]);
        $prop = $stmt->fetch();
        if (!$prop) return;

        $link = "proposal.php?id=$proposal_id";
        $recipients = [];

        // Always notify proposal author
        if ($prop['user_id'] != $exclude_user_id) {
            $recipients[$prop['user_id']] = "Your proposal \"" . htmlspecialchars(mb_substr($prop['title'], 0, 40)) . "...\" status is now: " . htmlspecialchars($new_status_label);
        }

        // Notify followed users
        $stmt = $pdo->prepare("SELECT user_id FROM followed_topics WHERE item_type = 'proposal' AND item_id = ? AND user_id != ?");
        $stmt->execute([$proposal_id, $exclude_user_id]);
        $followers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($followers as $f_id) {
            if (!isset($recipients[$f_id])) {
                $recipients[$f_id] = "Proposal update: \"" . htmlspecialchars(mb_substr($prop['title'], 0, 40)) . "...\" moved to " . htmlspecialchars($new_status_label);
            }
        }

        // Notify voters
        $stmt = $pdo->prepare("SELECT user_id FROM proposal_votes WHERE proposal_id = ? AND user_id != ?");
        $stmt->execute([$proposal_id, $exclude_user_id]);
        $voters = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($voters as $v_id) {
            if (!isset($recipients[$v_id])) {
                $recipients[$v_id] = "Proposal you voted on moved to stage: " . htmlspecialchars($new_status_label);
            }
        }

        foreach ($recipients as $uid => $msg) {
            create_notification($pdo, $uid, 'proposal_update', $msg, $link);
        }
    } catch (Exception $e) {
        // Log or ignore
    }
}

function notify_debate_followers($pdo, $debate_id, $exclude_user_id, $author_name, $debate_title, $arg_id, $side) {
    if (!$pdo || !$debate_id) return;
    try {
        $stmt = $pdo->prepare("SELECT user_id, title FROM debates WHERE id = ?");
        $stmt->execute([$debate_id]);
        $debate = $stmt->fetch();
        if (!$debate) return;

        $link = "debate.php?id=$debate_id#arg-$arg_id";
        $recipients = [];

        // Notify debate creator
        if ($debate['user_id'] != $exclude_user_id) {
            $side_text = ($side === 'for') ? 'FOR' : 'AGAINST';
            $recipients[$debate['user_id']] = htmlspecialchars($author_name) . " posted a perspective ($side_text) in your debate: \"" . htmlspecialchars(mb_substr($debate['title'], 0, 40)) . "...\"";
        }

        // Notify followers
        $stmt = $pdo->prepare("SELECT user_id FROM followed_topics WHERE item_type = 'debate' AND item_id = ? AND user_id != ?");
        $stmt->execute([$debate_id, $exclude_user_id]);
        $followers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($followers as $f_id) {
            if (!isset($recipients[$f_id])) {
                $recipients[$f_id] = "New perspective in followed debate \"" . htmlspecialchars(mb_substr($debate['title'], 0, 40)) . "...\" by " . htmlspecialchars($author_name);
            }
        }

        foreach ($recipients as $uid => $msg) {
            create_notification($pdo, $uid, 'debate_argument', $msg, $link);
        }
    } catch (Exception $e) {
        // Log or ignore
    }
}

/**
 * ==========================================================
 * SAVE (BOOKMARK) & FOLLOW HELPER FUNCTIONS
 * ==========================================================
 */

function is_discussion_saved($pdo, $user_id, $item_type, $item_id) {
    if (!$pdo || !$user_id || !$item_id) return false;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM saved_discussions WHERE user_id = ? AND item_type = ? AND item_id = ?");
        $stmt->execute([$user_id, $item_type, $item_id]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function is_discussion_followed($pdo, $user_id, $item_type, $item_id) {
    if (!$pdo || !$user_id || !$item_id) return false;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM followed_topics WHERE user_id = ? AND item_type = ? AND item_id = ?");
        $stmt->execute([$user_id, $item_type, $item_id]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * ==========================================================
 * BLOCKING & MODERATION HELPER FUNCTIONS
 * ==========================================================
 */

function is_user_blocked($pdo, $blocker_id, $target_user_id) {
    if (!$pdo || !$blocker_id || !$target_user_id) return false;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?");
        $stmt->execute([$blocker_id, $target_user_id]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function get_blocked_user_ids($pdo, $user_id) {
    if (!$pdo || !$user_id) return [];
    try {
        $stmt = $pdo->prepare("SELECT blocked_id FROM blocked_users WHERE blocker_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

// Automatically ensure schema on helpers include
if (isset($pdo)) {
    ensure_engagement_schema($pdo);
}
?>
