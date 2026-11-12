<?php
require_once 'config.php';
require_once 'helpers.php';

$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$user_id && isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$u = $stmt->fetch();

if (!$u) {
    die("User not found.");
}

$is_me = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $u['id']);
$viewer_id = $_SESSION['user_id'] ?? 0;

// Check if blocked by current viewer
$is_blocked_by_viewer = false;
if ($viewer_id && !$is_me) {
    $is_blocked_by_viewer = is_user_blocked($pdo, $viewer_id, $u['id']);
}

// Fetch badges & stats
$badges_data = get_user_badges($u['id'], $pdo);
$stats = $badges_data['stats'];
$all_badges = $badges_data['badges'];

// Active tab
$active_tab = $_GET['tab'] ?? 'topics';

// 1. Fetch Topics created by user
$stmt = $pdo->prepare("
    SELECT t.*, c.name as category_name, c.icon as category_icon,
    (SELECT COUNT(*) FROM posts WHERE topic_id = t.id AND is_deleted = 0) as reply_count
    FROM topics t
    JOIN categories c ON t.category_id = c.id
    WHERE t.user_id = ? AND t.is_deleted = 0
    ORDER BY t.created_at DESC
");
$stmt->execute([$user_id]);
$user_topics = $stmt->fetchAll();

// 2. Fetch Debates created by user
$stmt = $pdo->prepare("
    SELECT d.*, c.name as category_name,
    (SELECT COUNT(*) FROM debate_arguments WHERE debate_id = d.id) as argument_count
    FROM debates d
    JOIN categories c ON d.category_id = c.id
    WHERE d.user_id = ? AND d.is_deleted = 0
    ORDER BY d.created_at DESC
");
$stmt->execute([$user_id]);
$user_debates = $stmt->fetchAll();

// 3. Fetch Debate Arguments contributed by user
$stmt = $pdo->prepare("
    SELECT a.*, d.title as debate_title, d.slug as debate_slug
    FROM debate_arguments a
    JOIN debates d ON a.debate_id = d.id
    WHERE a.user_id = ? AND d.is_deleted = 0
    ORDER BY a.created_at DESC LIMIT 20
");
$stmt->execute([$user_id]);
$user_arguments = $stmt->fetchAll();

// 4. Fetch Proposals created by user
$stmt = $pdo->prepare("
    SELECT * FROM proposals
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$user_id]);
$user_proposals = $stmt->fetchAll();

// 5. Fetch Posts / Replies by user
$stmt = $pdo->prepare("
    SELECT p.*, t.title as topic_title
    FROM posts p
    JOIN topics t ON p.topic_id = t.id
    WHERE p.user_id = ? AND p.is_deleted = 0
    ORDER BY p.created_at DESC LIMIT 20
");
$stmt->execute([$user_id]);
$user_posts = $stmt->fetchAll();

// 6. Fetch Saved Discussions (If viewer is owner or admin)
$user_saved = [];
if ($is_me || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
    $stmt = $pdo->prepare("
        SELECT s.*,
            CASE 
                WHEN s.item_type = 'topic' THEN (SELECT title FROM topics WHERE id = s.item_id)
                WHEN s.item_type = 'debate' THEN (SELECT title FROM debates WHERE id = s.item_id)
                WHEN s.item_type = 'proposal' THEN (SELECT title FROM proposals WHERE id = s.item_id)
            END as item_title,
            CASE 
                WHEN s.item_type = 'topic' THEN (SELECT CONCAT('topic.php?id=', id) FROM topics WHERE id = s.item_id)
                WHEN s.item_type = 'debate' THEN (SELECT CONCAT('debate.php?id=', id) FROM debates WHERE id = s.item_id)
                WHEN s.item_type = 'proposal' THEN (SELECT CONCAT('proposal.php?id=', id) FROM proposals WHERE id = s.item_id)
            END as item_url
        FROM saved_discussions s
        WHERE s.user_id = ?
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $user_saved = $stmt->fetchAll();
}

// 7. Fetch Followed Topics
$user_followed = [];
if ($is_me || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
    $stmt = $pdo->prepare("
        SELECT f.*,
            CASE 
                WHEN f.item_type = 'topic' THEN (SELECT title FROM topics WHERE id = f.item_id)
                WHEN f.item_type = 'debate' THEN (SELECT title FROM debates WHERE id = f.item_id)
                WHEN f.item_type = 'proposal' THEN (SELECT title FROM proposals WHERE id = f.item_id)
            END as item_title,
            CASE 
                WHEN f.item_type = 'topic' THEN (SELECT CONCAT('topic.php?id=', id) FROM topics WHERE id = f.item_id)
                WHEN f.item_type = 'debate' THEN (SELECT CONCAT('debate.php?id=', id) FROM debates WHERE id = f.item_id)
                WHEN f.item_type = 'proposal' THEN (SELECT CONCAT('proposal.php?id=', id) FROM proposals WHERE id = f.item_id)
            END as item_url
        FROM followed_topics f
        WHERE f.user_id = ?
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $user_followed = $stmt->fetchAll();
}

$interests_arr = array_filter(array_map('trim', explode(',', $u['interests'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <title><?= htmlspecialchars($u['username']) ?> — Community Profile | Forum Home</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="page-wrapper" style="max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem;">
        
        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="flash-message flash-error"><i class="fas fa-exclamation-circle"></i> <?= $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?></div>
        <?php endif; ?>

        <!-- Blocked notice banner if viewing a blocked user -->
        <?php if ($is_blocked_by_viewer): ?>
            <div class="blocked-alert-banner">
                <i class="fas fa-user-slash"></i>
                <span>You have blocked <strong>@<?= htmlspecialchars($u['username']) ?></strong>. Their discussions and replies are hidden from your view.</span>
                <button type="button" class="btn btn-secondary btn-sm block-user-btn" data-user-id="<?= $u['id'] ?>" data-blocked="true">
                    <i class="fas fa-user-plus"></i> Unblock User
                </button>
            </div>
        <?php endif; ?>

        <div class="profile-layout-grid">
            
            <!-- ==============================================
                 SIDEBAR: User Identity, Reputation, Interests
                 ============================================== -->
            <div class="profile-sidebar">
                
                <!-- Main Identity Card -->
                <div class="card profile-card" style="text-align: center; position: relative;">
                    
                    <div class="profile-avatar-wrap">
                        <?php if ($u['avatar_url']): ?>
                            <img src="<?= htmlspecialchars($u['avatar_url']) ?>" alt="Avatar" class="avatar profile-avatar">
                        <?php else: ?>
                            <div class="avatar-initials profile-avatar-initials">
                                <?= get_avatar_initials($u['username']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <h2 class="profile-username"><?= htmlspecialchars($u['username']) ?></h2>
                    
                    <!-- Roles & Tier Badge -->
                    <div class="profile-role-row">
                        <?php if ($u['role'] === 'admin'): ?>
                            <span class="badge badge-danger"><i class="fas fa-shield-halved"></i> Admin</span>
                        <?php elseif ($u['role'] === 'moderator'): ?>
                            <span class="badge badge-primary"><i class="fas fa-shield"></i> Moderator</span>
                        <?php elseif ($u['role'] === 'banned' || !empty($u['is_banned'])): ?>
                            <span class="badge badge-secondary" style="background:var(--danger); color:white;"><i class="fas fa-ban"></i> Suspended</span>
                        <?php endif; ?>
                        
                        <?= get_reputation_badge((int)$u['reputation']) ?>
                    </div>

                    <!-- Top Contribution Badges -->
                    <div class="profile-top-badges">
                        <?= get_user_top_badges_html($u['id'], $pdo, 3) ?>
                    </div>
                    
                    <div class="profile-meta-list">
                        <div class="profile-meta-item">
                            <i class="fas fa-calendar-alt"></i> Joined <?= date('F Y', strtotime($u['created_at'])) ?>
                        </div>
                        <div class="profile-meta-item">
                            <i class="fas fa-star" style="color: var(--accent-amber);"></i> <?= (int)$u['reputation'] ?> Reputation Points
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="profile-actions-wrap">
                        <?php if ($is_me): ?>
                            <a href="edit_profile.php" class="btn btn-primary btn-sm" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.4rem;">
                                <i class="fas fa-edit"></i> Edit Profile & Interests
                            </a>
                        <?php elseif ($viewer_id): ?>
                            <div style="display: flex; gap: 0.5rem; width: 100%;">
                                <button type="button" class="btn btn-secondary btn-sm block-user-btn" data-user-id="<?= $u['id'] ?>" data-blocked="<?= $is_blocked_by_viewer ? 'true' : 'false' ?>" style="flex-grow: 1;">
                                    <i class="fas <?= $is_blocked_by_viewer ? 'fa-user-plus' : 'fa-user-slash' ?>"></i>
                                    <span class="block-btn-text"><?= $is_blocked_by_viewer ? 'Unblock' : 'Block' ?></span>
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm report-item-btn" data-type="user" data-id="<?= $u['id'] ?>" title="Report this user" style="color: var(--text-muted);">
                                    <i class="fas fa-flag"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- About / Bio Card -->
                <?php if (!empty($u['bio'])): ?>
                    <div class="card" style="margin-top: 1rem;">
                        <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-user-pen" style="color: var(--primary);"></i> About
                        </h4>
                        <p style="font-size: 0.9rem; color: var(--text); line-height: 1.5;"><?= nl2br(htmlspecialchars($u['bio'])) ?></p>
                    </div>
                <?php endif; ?>

                <!-- Topics of Interest Card -->
                <div class="card" style="margin-top: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                        <h4 style="font-size: 0.95rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin: 0;">
                            <i class="fas fa-tags" style="color: var(--primary);"></i> Interests
                        </h4>
                        <?php if ($is_me): ?>
                            <a href="edit_profile.php" style="font-size: 0.75rem; color: var(--primary); text-decoration: none;">Manage</a>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($interests_arr)): ?>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
                            <?= $is_me ? 'You haven\'t added any interests yet. <a href="edit_profile.php" style="color:var(--primary);">Add some</a> to discover tailored topics!' : 'No interests listed yet.' ?>
                        </p>
                    <?php else: ?>
                        <div class="interests-cloud">
                            <?php foreach ($interests_arr as $interest): ?>
                                <span class="interest-chip">
                                    <i class="fas fa-hashtag"></i> <?= htmlspecialchars($interest) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Community Contribution Stats -->
                <div class="card" style="margin-top: 1rem;">
                    <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 1rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                        <i class="fas fa-chart-simple" style="color: var(--primary);"></i> Engagement Stats
                    </h4>
                    
                    <div class="profile-stats-grid">
                        <div class="profile-stat-box">
                            <span class="stat-number"><?= $stats['topics_count'] ?></span>
                            <span class="stat-name">Topics</span>
                        </div>
                        <div class="profile-stat-box">
                            <span class="stat-number"><?= $stats['debates_count'] + $stats['arguments_count'] ?></span>
                            <span class="stat-name">Debate Pts</span>
                        </div>
                        <div class="profile-stat-box">
                            <span class="stat-number"><?= $stats['proposals_count'] ?></span>
                            <span class="stat-name">Proposals</span>
                        </div>
                        <div class="profile-stat-box">
                            <span class="stat-number"><?= $stats['helpful_votes_received'] ?></span>
                            <span class="stat-name">Helpful Votes</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ==============================================
                 MAIN CONTENT: Discussion History Tabs & Badges
                 ============================================== -->
            <div class="profile-main-area">
                
                <!-- Profile Tabs Navigation -->
                <div class="profile-tabs-header">
                    <a href="?id=<?= $user_id ?>&tab=topics" class="profile-tab-item <?= $active_tab === 'topics' ? 'active' : '' ?>">
                        <i class="fas fa-comments"></i> Topics <span class="tab-badge"><?= count($user_topics) ?></span>
                    </a>
                    <a href="?id=<?= $user_id ?>&tab=debates" class="profile-tab-item <?= $active_tab === 'debates' ? 'active' : '' ?>">
                        <i class="fas fa-scale-balanced"></i> Debates <span class="tab-badge"><?= count($user_debates) + count($user_arguments) ?></span>
                    </a>
                    <a href="?id=<?= $user_id ?>&tab=proposals" class="profile-tab-item <?= $active_tab === 'proposals' ? 'active' : '' ?>">
                        <i class="fas fa-lightbulb"></i> Proposals <span class="tab-badge"><?= count($user_proposals) ?></span>
                    </a>
                    <a href="?id=<?= $user_id ?>&tab=replies" class="profile-tab-item <?= $active_tab === 'replies' ? 'active' : '' ?>">
                        <i class="fas fa-reply"></i> Replies <span class="tab-badge"><?= count($user_posts) ?></span>
                    </a>
                    <a href="?id=<?= $user_id ?>&tab=badges" class="profile-tab-item <?= $active_tab === 'badges' ? 'active' : '' ?>">
                        <i class="fas fa-medal"></i> Badges
                    </a>
                    <?php if ($is_me): ?>
                        <a href="?id=<?= $user_id ?>&tab=saved" class="profile-tab-item <?= $active_tab === 'saved' ? 'active' : '' ?>">
                            <i class="fas fa-bookmark"></i> Saved <span class="tab-badge"><?= count($user_saved) ?></span>
                        </a>
                        <a href="?id=<?= $user_id ?>&tab=followed" class="profile-tab-item <?= $active_tab === 'followed' ? 'active' : '' ?>">
                            <i class="fas fa-bell"></i> Followed <span class="tab-badge"><?= count($user_followed) ?></span>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Tab 1: Topics Created -->
                <?php if ($active_tab === 'topics'): ?>
                    <div class="card" style="border-top-left-radius: 0;">
                        <h3 style="font-size: 1.15rem; margin-bottom: 1.25rem; font-weight: 700; display: flex; align-items: center; justify-content: space-between;">
                            <span><i class="fas fa-comments" style="color: var(--primary);"></i> Topics Authored</span>
                            <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500;"><?= count($user_topics) ?> total</span>
                        </h3>

                        <?php if (empty($user_topics)): ?>
                            <div class="profile-empty-state">
                                <i class="fas fa-comment-slash"></i>
                                <p>No discussion topics created yet.</p>
                                <?php if ($is_me): ?>
                                    <a href="new_topic.php" class="btn btn-primary btn-sm" style="margin-top: 0.75rem;"><i class="fas fa-plus"></i> Start a Discussion</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="activity-history-list">
                                <?php foreach ($user_topics as $topic): ?>
                                    <div class="activity-history-item">
                                        <div style="flex-grow: 1;">
                                            <a href="topic.php?id=<?= $topic['id'] ?>" class="activity-item-title">
                                                <?= htmlspecialchars($topic['title']) ?>
                                            </a>
                                            <div class="activity-item-meta">
                                                <span class="category-pill"><i class="<?= htmlspecialchars($topic['category_icon']) ?>"></i> <?= htmlspecialchars($topic['category_name']) ?></span>
                                                <span><i class="far fa-clock"></i> <?= time_ago($topic['created_at']) ?></span>
                                                <span><i class="far fa-eye"></i> <?= $topic['views'] ?> views</span>
                                                <span><i class="far fa-comments"></i> <?= $topic['reply_count'] ?> replies</span>
                                                <?php if ($topic['is_pinned']): ?>
                                                    <span class="badge-mini" style="color: var(--accent-amber);"><i class="fas fa-thumbtack"></i> Pinned</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <a href="topic.php?id=<?= $topic['id'] ?>" class="btn btn-secondary btn-sm" style="flex-shrink: 0;">View</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <!-- Tab 2: Debates & Perspectives -->
                <?php elseif ($active_tab === 'debates'): ?>
                    <div class="card" style="border-top-left-radius: 0;">
                        <h3 style="font-size: 1.15rem; margin-bottom: 1.25rem; font-weight: 700;">
                            <i class="fas fa-scale-balanced" style="color: var(--accent-violet);"></i> Perspective Debates & Arguments
                        </h3>

                        <!-- Debates Created -->
                        <h4 style="font-size: 0.95rem; color: var(--text-secondary); margin-bottom: 0.75rem; text-transform: uppercase;">Debates Started</h4>
                        <?php if (empty($user_debates)): ?>
                            <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1.5rem;">No debate motions started yet.</p>
                        <?php else: ?>
                            <div class="activity-history-list" style="margin-bottom: 2rem;">
                                <?php foreach ($user_debates as $d): ?>
                                    <div class="activity-history-item">
                                        <div style="flex-grow: 1;">
                                            <a href="debate.php?id=<?= $d['id'] ?>" class="activity-item-title">
                                                <?= htmlspecialchars($d['title']) ?>
                                            </a>
                                            <div class="activity-item-meta">
                                                <span class="badge-mini" style="color: var(--accent-sky);"><?= htmlspecialchars($d['category_name']) ?></span>
                                                <span><i class="far fa-clock"></i> <?= time_ago($d['created_at']) ?></span>
                                                <span><i class="far fa-comments"></i> <?= $d['argument_count'] ?> contributions</span>
                                            </div>
                                        </div>
                                        <a href="debate.php?id=<?= $d['id'] ?>" class="btn btn-secondary btn-sm">Debate</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Perspectives Contributed -->
                        <h4 style="font-size: 0.95rem; color: var(--text-secondary); margin-bottom: 0.75rem; text-transform: uppercase;">Perspectives Contributed</h4>
                        <?php if (empty($user_arguments)): ?>
                            <p style="color: var(--text-muted); font-size: 0.875rem;">No debate arguments submitted yet.</p>
                        <?php else: ?>
                            <div class="activity-history-list">
                                <?php foreach ($user_arguments as $arg): ?>
                                    <div class="activity-history-item" style="flex-direction: column; align-items: flex-start;">
                                        <div style="display: flex; justify-content: space-between; width: 100%; margin-bottom: 0.4rem; flex-wrap: wrap; gap: 0.5rem;">
                                            <a href="debate.php?id=<?= $arg['debate_id'] ?>#arg-<?= $arg['id'] ?>" style="font-weight: 600; color: var(--text); font-size: 0.95rem;">
                                                In: <?= htmlspecialchars($arg['debate_title']) ?>
                                            </a>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <span class="arg-side-pill side-<?= $arg['side'] ?>">
                                                    <?= strtoupper($arg['side']) ?>
                                                </span>
                                                <span class="badge-mini" style="color: var(--accent-emerald);">
                                                    <i class="fas fa-thumbs-up"></i> <?= (int)$arg['helpful_count'] ?> helpful
                                                </span>
                                                <span style="font-size: 0.75rem; color: var(--text-muted);"><?= time_ago($arg['created_at']) ?></span>
                                            </div>
                                        </div>
                                        <div class="activity-snippet">
                                            <?= htmlspecialchars(mb_substr($arg['content'], 0, 180)) . (mb_strlen($arg['content']) > 180 ? '...' : '') ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <!-- Tab 3: Proposals Created -->
                <?php elseif ($active_tab === 'proposals'): ?>
                    <div class="card" style="border-top-left-radius: 0;">
                        <h3 style="font-size: 1.15rem; margin-bottom: 1.25rem; font-weight: 700; display: flex; justify-content: space-between; align-items: center;">
                            <span><i class="fas fa-lightbulb" style="color: var(--accent-amber);"></i> Ideas to Action Proposals</span>
                            <?php if ($is_me): ?>
                                <a href="new_proposal.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Proposal</a>
                            <?php endif; ?>
                        </h3>

                        <?php if (empty($user_proposals)): ?>
                            <div class="profile-empty-state">
                                <i class="fas fa-lightbulb"></i>
                                <p>No action proposals created yet.</p>
                                <?php if ($is_me): ?>
                                    <a href="new_proposal.php" class="btn btn-primary btn-sm" style="margin-top: 0.75rem;"><i class="fas fa-plus"></i> Submit an Action Proposal</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="activity-history-list">
                                <?php foreach ($user_proposals as $prop): ?>
                                    <div class="activity-history-item">
                                        <div style="flex-grow: 1;">
                                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem; flex-wrap: wrap;">
                                                <a href="proposal.php?id=<?= $prop['id'] ?>" class="activity-item-title" style="margin: 0;">
                                                    <?= htmlspecialchars($prop['title']) ?>
                                                </a>
                                                <?= get_proposal_status_badge($prop['status']) ?>
                                            </div>
                                            <div class="activity-item-meta">
                                                <span><i class="far fa-clock"></i> <?= time_ago($prop['created_at']) ?></span>
                                                <span><i class="fas fa-arrow-up"></i> <?= (int)$prop['votes_count'] ?> votes</span>
                                            </div>
                                            <div class="activity-snippet" style="margin-top: 0.4rem;">
                                                <?= htmlspecialchars(mb_substr($prop['problem_description'], 0, 140)) ?>...
                                            </div>
                                        </div>
                                        <a href="proposal.php?id=<?= $prop['id'] ?>" class="btn btn-secondary btn-sm" style="flex-shrink: 0;">View Plan</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <!-- Tab 4: Replies & Posts -->
                <?php elseif ($active_tab === 'replies'): ?>
                    <div class="card" style="border-top-left-radius: 0;">
                        <h3 style="font-size: 1.15rem; margin-bottom: 1.25rem; font-weight: 700;">
                            <i class="fas fa-reply" style="color: var(--accent-sky);"></i> Discussion Replies & Answers
                        </h3>

                        <?php if (empty($user_posts)): ?>
                            <div class="profile-empty-state">
                                <i class="fas fa-comments"></i>
                                <p>No replies posted in discussions yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="activity-history-list">
                                <?php foreach ($user_posts as $post): ?>
                                    <div class="activity-history-item" style="flex-direction: column; align-items: flex-start;">
                                        <div style="display: flex; justify-content: space-between; width: 100%; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                                            <div style="font-size: 0.9rem; color: var(--text-secondary);">
                                                Replied to <a href="topic.php?id=<?= $post['topic_id'] ?>#post-<?= $post['id'] ?>" style="color: var(--primary); font-weight: 600; text-decoration: none;">
                                                    <?= htmlspecialchars($post['topic_title']) ?>
                                                </a>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);"><i class="far fa-clock"></i> <?= time_ago($post['created_at']) ?></div>
                                        </div>
                                        <div class="activity-snippet" style="width: 100%;">
                                            <?= htmlspecialchars(mb_substr($post['content'], 0, 200)) . (mb_strlen($post['content']) > 200 ? '...' : '') ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <!-- Tab 5: Saved Discussions -->
                <?php elseif ($active_tab === 'saved' && $is_me): ?>
                    <div class="card" style="border-top-left-radius: 0;">
                        <h3 style="font-size: 1.15rem; margin-bottom: 1.25rem; font-weight: 700; display: flex; justify-content: space-between; align-items: center;">
                            <span><i class="fas fa-bookmark" style="color: var(--accent-rose);"></i> Bookmarked Discussions</span>
                            <span style="font-size: 0.85rem; color: var(--text-muted);"><?= count($user_saved) ?> saved</span>
                        </h3>

                        <?php if (empty($user_saved)): ?>
                            <div class="profile-empty-state">
                                <i class="far fa-bookmark"></i>
                                <p>You haven't bookmarked any discussions yet.</p>
                                <span style="font-size: 0.85rem; color: var(--text-muted);">Click the "Save" button on any topic, debate, or proposal to bookmark it for later!</span>
                            </div>
                        <?php else: ?>
                            <div class="activity-history-list">
                                <?php foreach ($user_saved as $s): ?>
                                    <div class="activity-history-item">
                                        <div style="flex-grow: 1;">
                                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem;">
                                                <span class="badge-mini" style="text-transform: uppercase; font-size: 0.65rem;">
                                                    <?= htmlspecialchars($s['item_type']) ?>
                                                </span>
                                                <a href="<?= htmlspecialchars($s['item_url']) ?>" class="activity-item-title" style="margin: 0;">
                                                    <?= htmlspecialchars($s['item_title'] ?: 'Untitled Discussion') ?>
                                                </a>
                                            </div>
                                            <div class="activity-item-meta">
                                                <span><i class="far fa-clock"></i> Saved <?= time_ago($s['created_at']) ?></span>
                                            </div>
                                        </div>
                                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                                            <a href="<?= htmlspecialchars($s['item_url']) ?>" class="btn btn-secondary btn-sm">Open</a>
                                            <button type="button" class="btn btn-secondary btn-sm save-discussion-btn" data-type="<?= $s['item_type'] ?>" data-id="<?= $s['item_id'] ?>" data-saved="true" title="Remove bookmark" style="color: var(--accent-rose);">
                                                <i class="fas fa-bookmark"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <!-- Tab 6: Followed Topics -->
                <?php elseif ($active_tab === 'followed' && $is_me): ?>
                    <div class="card" style="border-top-left-radius: 0;">
                        <h3 style="font-size: 1.15rem; margin-bottom: 1.25rem; font-weight: 700; display: flex; justify-content: space-between; align-items: center;">
                            <span><i class="fas fa-bell" style="color: var(--accent-sky);"></i> Followed Discussions</span>
                            <span style="font-size: 0.85rem; color: var(--text-muted);"><?= count($user_followed) ?> followed</span>
                        </h3>

                        <?php if (empty($user_followed)): ?>
                            <div class="profile-empty-state">
                                <i class="far fa-bell"></i>
                                <p>You aren't following any discussions yet.</p>
                                <span style="font-size: 0.85rem; color: var(--text-muted);">Click the "Follow" button on any topic to get notified when replies or updates are posted.</span>
                            </div>
                        <?php else: ?>
                            <div class="activity-history-list">
                                <?php foreach ($user_followed as $f): ?>
                                    <div class="activity-history-item">
                                        <div style="flex-grow: 1;">
                                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem;">
                                                <span class="badge-mini" style="text-transform: uppercase; font-size: 0.65rem;">
                                                    <?= htmlspecialchars($f['item_type']) ?>
                                                </span>
                                                <a href="<?= htmlspecialchars($f['item_url']) ?>" class="activity-item-title" style="margin: 0;">
                                                    <?= htmlspecialchars($f['item_title'] ?: 'Untitled Discussion') ?>
                                                </a>
                                            </div>
                                            <div class="activity-item-meta">
                                                <span><i class="far fa-clock"></i> Followed <?= time_ago($f['created_at']) ?></span>
                                            </div>
                                        </div>
                                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                                            <a href="<?= htmlspecialchars($f['item_url']) ?>" class="btn btn-secondary btn-sm">Visit</a>
                                            <button type="button" class="btn btn-secondary btn-sm follow-discussion-btn" data-type="<?= $f['item_type'] ?>" data-id="<?= $f['item_id'] ?>" data-followed="true" title="Unfollow" style="color: var(--accent-sky);">
                                                <i class="fas fa-bell-slash"></i> Unfollow
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <!-- Tab 7: Badges & Reputation Showcase -->
                <?php elseif ($active_tab === 'badges'): ?>
                    <div class="card" style="border-top-left-radius: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.5rem;">
                            <div>
                                <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.25rem;">
                                    <i class="fas fa-medal" style="color: var(--accent-amber);"></i> Badges & Reputation Engine
                                </h3>
                                <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">
                                    Recognizing positive, civil, and constructive community contributions.
                                </p>
                            </div>
                            <div>
                                <?= get_reputation_badge((int)$u['reputation']) ?>
                            </div>
                        </div>

                        <div class="badges-showcase-grid">
                            <?php foreach ($all_badges as $badge): ?>
                                <div class="badge-showcase-card <?= $badge['unlocked'] ? 'unlocked' : 'locked' ?>">
                                    <div class="badge-icon-wrap" style="background: <?= $badge['gradient'] ?>;">
                                        <i class="<?= htmlspecialchars($badge['icon']) ?>"></i>
                                    </div>
                                    <div class="badge-info-wrap">
                                        <div class="badge-card-header">
                                            <div class="badge-card-title"><?= htmlspecialchars($badge['title']) ?></div>
                                            <span class="badge-status-pill <?= $badge['unlocked'] ? 'unlocked' : 'locked' ?>">
                                                <?= $badge['unlocked'] ? '<i class="fas fa-check"></i> Earned' : '<i class="fas fa-lock"></i> Locked' ?>
                                            </span>
                                        </div>
                                        <div class="badge-card-desc"><?= htmlspecialchars($badge['description']) ?></div>
                                        <div class="badge-card-progress">
                                            <i class="fas fa-bullseye"></i> <?= htmlspecialchars($badge['progress']) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        </div>
    </div>

    <!-- Universal Report Modal -->
    <div class="modal" id="universalReportModal">
        <div class="modal-content">
            <h3 id="reportModalTitle"><i class="fas fa-flag" style="color: var(--accent-rose);"></i> Report Content</h3>
            <p style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 1rem;">
                Help moderators maintain high standards of constructive campus discourse.
            </p>
            <input type="hidden" id="reportTargetType" value="user">
            <input type="hidden" id="reportTargetId" value="">
            
            <div class="form-group">
                <label>Reason for reporting:</label>
                <select id="reportReasonCategory" class="form-control" style="margin-bottom: 0.75rem;">
                    <option value="Inappropriate or Offensive">Inappropriate or Offensive Behavior</option>
                    <option value="Spam or Unwanted Promotion">Spam or Unwanted Promotion</option>
                    <option value="Harassment or Hate Speech">Harassment or Hate Speech</option>
                    <option value="Misinformation or Harmful Advice">Misinformation</option>
                    <option value="Other">Other Violation</option>
                </select>
                <textarea id="reportReasonDetails" class="form-control" rows="3" placeholder="Provide helpful details for moderators..."></textarea>
            </div>
            
            <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.25rem;">
                <button type="button" class="btn btn-secondary" id="closeUniversalReportModal">Cancel</button>
                <button type="button" class="btn btn-danger" id="submitUniversalReport">
                    <i class="fas fa-flag"></i> Submit Report
                </button>
            </div>
        </div>
    </div>

    <script src="scripts.js"></script>
</body>
</html>
