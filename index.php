<?php
require_once 'config.php';
require_once 'helpers.php';

// Fetch categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $stmt->fetchAll();

// Fetch recent topics across all categories
$stmt = $pdo->query("
    SELECT t.*, c.name as category_name, c.slug as category_slug, c.icon as category_icon, u.username, u.avatar_url,
    (SELECT COUNT(*) FROM posts WHERE topic_id = t.id) - 1 as reply_count,
    (SELECT COUNT(*) FROM likes l JOIN posts p ON l.post_id = p.id WHERE p.topic_id = t.id) as like_count,
    (SELECT LEFT(p2.content, 160) FROM posts p2 WHERE p2.topic_id = t.id ORDER BY p2.created_at ASC LIMIT 1) as preview
    FROM topics t
    JOIN categories c ON t.category_id = c.id
    JOIN users u ON t.user_id = u.id
    WHERE t.is_deleted = 0
    ORDER BY t.is_pinned DESC, t.created_at DESC LIMIT 15
");
$recent_topics = $stmt->fetchAll();

// Fetch trending topics (most views in last 7 days)
$stmt = $pdo->query("
    SELECT t.id, t.title, t.views,
    (SELECT COUNT(*) FROM posts WHERE topic_id = t.id) - 1 as reply_count,
    c.name as category_name
    FROM topics t
    JOIN categories c ON t.category_id = c.id
    WHERE t.is_deleted = 0
    ORDER BY t.views DESC, t.created_at DESC
    LIMIT 5
");
$trending_topics = $stmt->fetchAll();

// Current user
$me = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $me = $stmt->fetch();
}

// Tag color mapping for categories
$tag_colors = ['tag-indigo', 'tag-rose', 'tag-amber', 'tag-emerald', 'tag-sky', 'tag-violet', 'tag-pink'];

// Fetch latest featured perspective debate
$stmt = $pdo->query("
    SELECT d.*, c.name as category_name, c.icon as category_icon,
    (SELECT COUNT(*) FROM debate_arguments WHERE debate_id = d.id AND side = 'for' AND parent_id IS NULL) as count_for,
    (SELECT COUNT(*) FROM debate_arguments WHERE debate_id = d.id AND side = 'against' AND parent_id IS NULL) as count_against,
    (SELECT COUNT(*) FROM debate_polls WHERE debate_id = d.id) as poll_total
    FROM debates d
    JOIN categories c ON d.category_id = c.id
    WHERE d.is_deleted = 0
    ORDER BY d.created_at DESC
    LIMIT 1
");
$featured_debate = $stmt->fetch();
$featured_poll_stats = $featured_debate ? get_debate_poll_stats($pdo, $featured_debate['id']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forum Home — Social Discussion Feed</title>
    <meta name="description" content="Forum Home — a modern social discussion platform for sharing ideas, debating topics, and discovering trending conversations.">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="page-wrapper">
        <!-- ===== LEFT SIDEBAR ===== -->
        <aside class="sidebar-left">
            <!-- Navigation -->
            <nav class="sidebar-nav">
                <a href="index.php" class="sidebar-nav-item active" id="nav-home">
                    <i class="fas fa-house"></i> Home
                </a>
                <a href="search.php" class="sidebar-nav-item" id="nav-explore">
                    <i class="fas fa-compass"></i> Explore
                </a>
                <a href="debates.php" class="sidebar-nav-item" id="nav-debates">
                    <i class="fas fa-scale-balanced"></i> Debates
                </a>
                <a href="proposals.php" class="sidebar-nav-item" id="nav-action">
                    <i class="fas fa-lightbulb" style="color: #fbbf24;"></i> Ideas to Action
                </a>
            </nav>

            <!-- Trending Tags -->
            <div>
                <div class="sidebar-section-title">Trending Tags</div>
                <div class="tag-cloud">
                    <?php foreach ($categories as $i => $cat): ?>
                        <a href="category.php?id=<?= $cat['id'] ?>" class="tag-pill <?= $tag_colors[$i % count($tag_colors)] ?>">
                            #<?= htmlspecialchars($cat['slug'] ?? strtolower(str_replace(' ', '', $cat['name']))) ?>
                        </a>
                    <?php endforeach; ?>
                    <span class="tag-pill tag-pink">#college</span>
                    <span class="tag-pill tag-sky">#trending</span>
                </div>
            </div>

            <!-- User Card -->
            <?php if ($me): ?>
            <div class="sidebar-user-card">
                <?php if ($me['avatar_url']): ?>
                    <img src="<?= htmlspecialchars($me['avatar_url']) ?>" alt="Avatar" class="avatar">
                <?php else: ?>
                    <div class="avatar-initials"><?= get_avatar_initials($me['username']) ?></div>
                <?php endif; ?>
                <h4><?= htmlspecialchars($me['username']) ?></h4>
                <div style="margin-top: 0.25rem;"><?= get_reputation_badge($me['reputation']) ?></div>
                <a href="profile.php?id=<?= $me['id'] ?>" class="btn btn-secondary btn-sm">My Profile</a>
            </div>
            <?php endif; ?>
        </aside>

        <!-- ===== MAIN FEED ===== -->
        <main class="main-feed">
            <!-- Flash messages -->
            <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="flash-message flash-error"><i class="fas fa-exclamation-circle"></i> <?= $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?></div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="feed-header">
                <h1>Forum Home</h1>
            </div>

            <!-- Composer -->
            <?php if ($me): ?>
            <div class="composer" id="composer">
                <div class="composer-inner">
                    <?php if ($me['avatar_url']): ?>
                        <img src="<?= htmlspecialchars($me['avatar_url']) ?>" alt="Avatar" class="avatar">
                    <?php else: ?>
                        <div class="avatar-initials"><?= get_avatar_initials($me['username']) ?></div>
                    <?php endif; ?>
                    <input type="text" class="composer-input" placeholder="Share your thoughts..." readonly id="composerInput">
                    <button class="composer-btn" id="composerBtn"><i class="fas fa-pen"></i> Post</button>
                </div>
            </div>
            <?php else: ?>
            <div class="composer">
                <div class="composer-inner">
                    <div class="avatar-initials" style="opacity:0.5;">?</div>
                    <a href="login.php" class="composer-input" style="display:flex;align-items:center;color:var(--text-muted);">Login to share your thoughts...</a>
                    <a href="register.php" class="composer-btn">Sign Up</a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Featured Perspective Debate Spotlight -->
            <?php if ($featured_debate && $featured_poll_stats): ?>
            <div class="featured-debate-spotlight">
                <div class="spotlight-badge">
                    <i class="fas fa-scale-balanced"></i> PERSPECTIVE DEBATE
                </div>
                <h3 class="spotlight-title">
                    <a href="debate.php?id=<?= $featured_debate['id'] ?>"><?= htmlspecialchars($featured_debate['title']) ?></a>
                </h3>
                <p class="spotlight-desc"><?= htmlspecialchars(substr($featured_debate['description'], 0, 130)) ?>...</p>
                
                <div class="spotlight-poll-preview">
                    <div class="poll-preview-labels">
                        <span class="for-label"><i class="fas fa-thumbs-up"></i> <?= $featured_poll_stats['pct_for'] ?>% FOR</span>
                        <span class="against-label"><?= $featured_poll_stats['pct_against'] ?>% AGAINST <i class="fas fa-thumbs-down"></i></span>
                    </div>
                    <div class="poll-preview-bar">
                        <div class="bar-for" style="width: <?= $featured_poll_stats['pct_for'] ?>%;"></div>
                        <div class="bar-undecided" style="width: <?= $featured_poll_stats['pct_undecided'] ?>%;"></div>
                        <div class="bar-against" style="width: <?= $featured_poll_stats['pct_against'] ?>%;"></div>
                    </div>
                </div>

                <div class="spotlight-footer">
                    <span><i class="fas fa-comments"></i> <?= $featured_debate['count_for'] + $featured_debate['count_against'] ?> arguments</span>
                    <a href="debate.php?id=<?= $featured_debate['id'] ?>" class="btn-spotlight-cta">
                        Vote & Join Debate <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Feed Filter Tabs -->
            <div class="feed-tabs" id="feedTabs">
                <button class="feed-tab active" data-filter="latest">Latest</button>
                <button class="feed-tab" data-filter="trending">Trending</button>
                <button class="feed-tab" data-filter="hot">Hot</button>
            </div>

            <!-- Discussion Feed -->
            <div class="post-feed" id="postFeed">
                <?php if (empty($recent_topics)): ?>
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <p>No discussions yet. Be the first to start a conversation!</p>
                        <?php if ($me): ?>
                            <a href="new_topic.php" class="btn btn-primary" style="margin-top:1rem;">Start a Discussion</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_topics as $idx => $topic): ?>
                        <a href="topic.php?id=<?= $topic['id'] ?>" class="feed-card" style="animation-delay: <?= $idx * 0.05 ?>s;">
                            <!-- Card Header -->
                            <div class="feed-card-header">
                                <?php if ($topic['avatar_url']): ?>
                                    <img src="<?= htmlspecialchars($topic['avatar_url']) ?>" alt="<?= htmlspecialchars($topic['username']) ?>" class="avatar">
                                <?php else: ?>
                                    <div class="avatar-initials"><?= get_avatar_initials($topic['username']) ?></div>
                                <?php endif; ?>
                                <div class="feed-card-author">
                                    <div class="feed-card-author-name"><?= htmlspecialchars($topic['username']) ?></div>
                                    <div class="feed-card-time"><?= time_ago($topic['created_at']) ?></div>
                                </div>
                                <span class="feed-card-menu"><i class="fas fa-ellipsis"></i></span>
                            </div>

                            <!-- Card Title -->
                            <div class="feed-card-title">
                                <?php if ($topic['is_pinned']): ?>
                                    <i class="fas fa-thumbtack pinned-icon" title="Pinned"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($topic['title']) ?>
                                <?php if ($topic['reply_count'] > 20): ?>
                                    <span class="badge-hot">🔥 Hot</span>
                                <?php endif; ?>
                                <?php if ($topic['is_locked']): ?>
                                    <span class="badge-locked"><i class="fas fa-lock"></i></span>
                                <?php endif; ?>
                            </div>

                            <!-- Preview Text -->
                            <?php if (!empty($topic['preview'])): ?>
                                <div class="feed-card-preview"><?= htmlspecialchars($topic['preview']) ?></div>
                            <?php endif; ?>

                            <!-- Tags -->
                            <div class="feed-card-tags">
                                <span class="tag-pill <?= $tag_colors[($topic['category_id'] - 1) % count($tag_colors)] ?>">
                                    <i class="<?= htmlspecialchars($topic['category_icon']) ?>" style="margin-right:0.3rem;font-size:0.7rem;"></i>
                                    <?= htmlspecialchars($topic['category_name']) ?>
                                </span>
                            </div>

                            <!-- Footer Stats -->
                            <div class="feed-card-footer">
                                <span class="feed-stat"><i class="far fa-comment"></i> <?= $topic['reply_count'] ?> replies</span>
                                <span class="feed-stat"><i class="far fa-heart"></i> <?= $topic['like_count'] ?? 0 ?> likes</span>
                                <span class="feed-stat"><i class="far fa-eye"></i> <?= $topic['views'] ?> views</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>

        <!-- ===== RIGHT SIDEBAR ===== -->
        <aside class="sidebar-right">
            <!-- Trending Discussions -->
            <div class="trending-widget">
                <h3><i class="fas fa-fire" style="color:var(--accent-amber);"></i> Trending</h3>
                <div class="trending-list">
                    <?php if (empty($trending_topics)): ?>
                        <div style="font-size:0.8rem; color:var(--text-muted); text-align:center; padding:0.5rem 0;">No trending discussions yet.</div>
                    <?php else: ?>
                        <?php foreach ($trending_topics as $rank => $trend): ?>
                            <a href="topic.php?id=<?= $trend['id'] ?>" class="trending-item">
                                <span class="trending-rank"><?= $rank + 1 ?></span>
                                <div class="trending-item-content">
                                    <div class="trending-item-title"><?= htmlspecialchars($trend['title']) ?></div>
                                    <div class="trending-item-meta">
                                        <?= htmlspecialchars($trend['category_name']) ?> · <?= $trend['reply_count'] ?> replies · <?= $trend['views'] ?> views
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Categories -->
            <div class="categories-widget">
                <h3><i class="fas fa-folder" style="color:var(--accent-sky);margin-right:0.3rem;"></i> Categories</h3>
                <ul class="cat-list">
                    <?php foreach ($categories as $cat): ?>
                        <li>
                            <a href="category.php?id=<?= $cat['id'] ?>">
                                <span><i class="<?= htmlspecialchars($cat['icon']) ?>"></i> <?= htmlspecialchars($cat['name']) ?></span>
                                <span class="cat-count"><?= $cat['post_count'] ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </aside>
    </div>

    <!-- Mobile Bottom Nav -->
    <nav class="mobile-bottom-nav">
        <a href="index.php" class="active"><i class="fas fa-house"></i> Home</a>
        <a href="debates.php"><i class="fas fa-scale-balanced"></i> Debates</a>
        <a href="proposals.php"><i class="fas fa-lightbulb"></i> Proposals</a>
        <?php if ($me): ?>
            <a href="new_topic.php"><i class="fas fa-plus-circle"></i> Post</a>
        <?php endif; ?>
        <a href="<?= $me ? 'profile.php?id=' . $me['id'] : 'login.php' ?>"><i class="fas fa-user"></i> Profile</a>
    </nav>

    <script src="scripts.js"></script>
</body>
</html>
