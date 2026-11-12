<?php
require_once 'config.php';
require_once 'helpers.php';

$category_id = filter_var($_GET['category'] ?? null, FILTER_VALIDATE_INT);
$filter = trim($_GET['filter'] ?? 'latest');

// Fetch categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $stmt->fetchAll();

// Build query
$where = "WHERE d.is_deleted = 0";
$params = [];

if ($category_id) {
    $where .= " AND d.category_id = ?";
    $params[] = $category_id;
}

$order = "d.created_at DESC";
if ($filter === 'views') {
    $order = "d.views DESC, d.created_at DESC";
}

$stmt = $pdo->prepare("
    SELECT d.*, c.name as category_name, c.slug as category_slug, c.icon as category_icon, 
           u.username, u.avatar_url,
           (SELECT COUNT(*) FROM debate_arguments WHERE debate_id = d.id AND side = 'for' AND parent_id IS NULL) as count_for,
           (SELECT COUNT(*) FROM debate_arguments WHERE debate_id = d.id AND side = 'against' AND parent_id IS NULL) as count_against,
           (SELECT COUNT(*) FROM debate_polls WHERE debate_id = d.id) as poll_total
    FROM debates d
    JOIN categories c ON d.category_id = c.id
    JOIN users u ON d.user_id = u.id
    $where
    ORDER BY $order
");
$stmt->execute($params);
$debates = $stmt->fetchAll();

// Compute poll stats for each debate
$tag_colors = ['tag-indigo', 'tag-rose', 'tag-amber', 'tag-emerald', 'tag-sky', 'tag-violet', 'tag-pink'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perspective Debates — Forum Home</title>
    <meta name="description" content="Engage in structured debates with For and Against perspectives, opinion polls, and helpfulness voting.">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="page-wrapper">
        <!-- ===== LEFT SIDEBAR ===== -->
        <aside class="sidebar-left">
            <nav class="sidebar-nav">
                <a href="index.php" class="sidebar-nav-item" id="nav-home">
                    <i class="fas fa-house"></i> Home
                </a>
                <a href="search.php" class="sidebar-nav-item" id="nav-explore">
                    <i class="fas fa-compass"></i> Explore
                </a>
                <a href="debates.php" class="sidebar-nav-item active" id="nav-debates">
                    <i class="fas fa-scale-balanced"></i> Debates
                </a>
                <a href="index.php" class="sidebar-nav-item" id="nav-insights">
                    <i class="fas fa-brain"></i> AI Insights
                </a>
                <a href="index.php" class="sidebar-nav-item" id="nav-action">
                    <i class="fas fa-clipboard-check"></i> Action Board
                </a>
            </nav>

            <!-- Categories Filter -->
            <div>
                <div class="sidebar-section-title">Debate Categories</div>
                <div class="tag-cloud">
                    <a href="debates.php" class="tag-pill <?= !$category_id ? 'active-tag' : 'tag-indigo' ?>">
                        All Topics
                    </a>
                    <?php foreach ($categories as $i => $cat): ?>
                        <a href="debates.php?category=<?= $cat['id'] ?>" class="tag-pill <?= ($category_id == $cat['id']) ? 'active-tag' : $tag_colors[$i % count($tag_colors)] ?>">
                            #<?= htmlspecialchars($cat['slug']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Propose Motion Prompt -->
            <div style="background: rgba(99,102,241,0.08); border: 1px solid rgba(99,102,241,0.25); border-radius: var(--radius-md); padding: 1.1rem; margin-top: 1.5rem; text-align:center;">
                <i class="fas fa-gavel" style="font-size:1.75rem; color:var(--primary); margin-bottom:0.5rem; display:inline-block;"></i>
                <h4 style="font-size: 0.95rem; margin-bottom: 0.35rem;">Have a Debate Idea?</h4>
                <p style="font-size: 0.78rem; color: var(--text-muted); margin-bottom: 0.85rem;">Propose a motion and let the community test arguments.</p>
                <a href="new_debate.php" class="btn btn-primary btn-sm" style="width:100%; display:inline-block;">+ Propose Motion</a>
            </div>
        </aside>

        <!-- ===== MAIN CONTENT ===== -->
        <main class="main-feed">
            <!-- Flash messages -->
            <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="flash-message flash-error"><i class="fas fa-exclamation-circle"></i> <?= $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?></div>
            <?php endif; ?>

            <!-- Page Header with Propose CTA -->
            <div class="debates-hub-header">
                <div>
                    <div style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.3rem 0.65rem; border-radius:16px; background:rgba(99,102,241,0.15); color:var(--primary); font-size:0.75rem; font-weight:700; margin-bottom:0.5rem; border:1px solid rgba(99,102,241,0.3);">
                        <i class="fas fa-scale-balanced"></i> PERSPECTIVE DEBATES
                    </div>
                    <h1>Perspective Debates</h1>
                    <p style="color:var(--text-muted); font-size:0.92rem; margin-top:0.25rem;">
                        Structured discussions with opposing For & Against arguments, community stance polls, and helpfulness voting.
                    </p>
                </div>
                <div>
                    <a href="new_debate.php" class="btn btn-primary" style="white-space:nowrap;">
                        <i class="fas fa-plus"></i> Propose Motion
                    </a>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="feed-tabs" style="margin-top: 1.25rem;">
                <a href="debates.php<?= $category_id ? '?category='.$category_id : '' ?>" class="feed-tab <?= $filter === 'latest' ? 'active' : '' ?>">Latest Motions</a>
                <a href="debates.php?filter=views<?= $category_id ? '&category='.$category_id : '' ?>" class="feed-tab <?= $filter === 'views' ? 'active' : '' ?>">Most Active</a>
            </div>

            <!-- Debates List -->
            <div class="debates-list" style="margin-top: 1.25rem; display:flex; flex-direction:column; gap:1.25rem;">
                <?php if (empty($debates)): ?>
                    <div class="empty-state">
                        <i class="fas fa-scale-balanced" style="font-size:2.5rem; color:var(--text-muted); margin-bottom:1rem;"></i>
                        <h3>No Debates Found</h3>
                        <p>Be the first to launch a perspective debate motion!</p>
                        <a href="new_debate.php" class="btn btn-primary" style="margin-top:1rem;">Propose a Motion</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($debates as $idx => $d): 
                        $stats = get_debate_poll_stats($pdo, $d['id']);
                    ?>
                        <div class="debate-hub-card" style="animation-delay: <?= $idx * 0.05 ?>s;">
                            <!-- Header Info -->
                            <div class="debate-hub-card-header">
                                <div class="author-info">
                                    <?php if ($d['avatar_url']): ?>
                                        <img src="<?= htmlspecialchars($d['avatar_url']) ?>" alt="Avatar" class="avatar-sm">
                                    <?php else: ?>
                                        <div class="avatar-initials avatar-initials-sm"><?= get_avatar_initials($d['username']) ?></div>
                                    <?php endif; ?>
                                    <span style="font-size:0.85rem; color:var(--text-secondary);"><?= htmlspecialchars($d['username']) ?></span>
                                    <span class="time-muted" style="font-size:0.75rem;">• <?= time_ago($d['created_at']) ?></span>
                                </div>
                                <span class="tag-pill <?= $tag_colors[($d['category_id'] - 1) % count($tag_colors)] ?>">
                                    <i class="<?= htmlspecialchars($d['category_icon']) ?>" style="font-size:0.7rem;margin-right:0.25rem;"></i>
                                    <?= htmlspecialchars($d['category_name']) ?>
                                </span>
                            </div>

                            <!-- Motion Title -->
                            <h2 class="debate-hub-title">
                                <a href="debate.php?id=<?= $d['id'] ?>">
                                    <?= htmlspecialchars($d['title']) ?>
                                </a>
                            </h2>

                            <!-- Context snippet -->
                            <p class="debate-hub-desc">
                                <?= htmlspecialchars(substr($d['description'], 0, 160)) ?><?= strlen($d['description']) > 160 ? '...' : '' ?>
                            </p>

                            <!-- Poll Bar Preview -->
                            <div class="debate-hub-poll-preview">
                                <div class="poll-preview-labels">
                                    <span class="for-label"><i class="fas fa-thumbs-up"></i> <?= $stats['pct_for'] ?>% FOR</span>
                                    <span class="total-label"><?= $stats['total'] ?> votes cast</span>
                                    <span class="against-label"><?= $stats['pct_against'] ?>% AGAINST <i class="fas fa-thumbs-down"></i></span>
                                </div>
                                <div class="poll-preview-bar">
                                    <div class="bar-for" style="width: <?= $stats['pct_for'] ?>%;"></div>
                                    <div class="bar-undecided" style="width: <?= $stats['pct_undecided'] ?>%;"></div>
                                    <div class="bar-against" style="width: <?= $stats['pct_against'] ?>%;"></div>
                                </div>
                            </div>

                            <!-- Footer Meta & CTA -->
                            <div class="debate-hub-footer">
                                <div class="hub-stats">
                                    <span class="stat-item"><i class="fas fa-thumbs-up" style="color:var(--accent-emerald);"></i> <?= $d['count_for'] ?> For</span>
                                    <span class="stat-item"><i class="fas fa-thumbs-down" style="color:var(--accent-rose);"></i> <?= $d['count_against'] ?> Against</span>
                                    <span class="stat-item"><i class="far fa-eye"></i> <?= $d['views'] ?> views</span>
                                </div>
                                <a href="debate.php?id=<?= $d['id'] ?>" class="btn btn-outline-primary btn-sm">
                                    Join Debate & Vote <i class="fas fa-arrow-right" style="margin-left:0.3rem;"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>

        <!-- ===== RIGHT SIDEBAR ===== -->
        <aside class="sidebar-right">
            <!-- How debates work guide -->
            <div class="trending-widget">
                <h3><i class="fas fa-info-circle" style="color:var(--accent-sky);"></i> How Debates Work</h3>
                <div style="font-size:0.83rem; color:var(--text-secondary); line-height:1.6; margin-top:0.75rem;">
                    <div style="margin-bottom:0.75rem; display:flex; gap:0.6rem;">
                        <span style="color:var(--accent-emerald); font-weight:700;"><i class="fas fa-check"></i></span>
                        <span><strong>Cast your Stance:</strong> Vote For, Against, or Undecided on the live opinion poll.</span>
                    </div>
                    <div style="margin-bottom:0.75rem; display:flex; gap:0.6rem;">
                        <span style="color:var(--accent-violet); font-weight:700;"><i class="fas fa-layer-group"></i></span>
                        <span><strong>Structured Arguments:</strong> Read separated For and Against reasoning.</span>
                    </div>
                    <div style="display:flex; gap:0.6rem;">
                        <span style="color:var(--accent-amber); font-weight:700;"><i class="fas fa-star"></i></span>
                        <span><strong>Helpful Voting:</strong> Upvote compelling logic to surface the most useful arguments.</span>
                    </div>
                </div>
            </div>

            <!-- Categories -->
            <div class="categories-widget">
                <h3><i class="fas fa-folder" style="color:var(--accent-sky);margin-right:0.3rem;"></i> Categories</h3>
                <ul class="cat-list">
                    <?php foreach ($categories as $cat): ?>
                        <li>
                            <a href="debates.php?category=<?= $cat['id'] ?>">
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
        <a href="index.php"><i class="fas fa-house"></i> Home</a>
        <a href="debates.php" class="active"><i class="fas fa-scale-balanced"></i> Debates</a>
        <a href="new_debate.php"><i class="fas fa-plus-circle"></i> Propose</a>
        <a href="search.php"><i class="fas fa-compass"></i> Explore</a>
        <a href="<?= isset($_SESSION['user_id']) ? 'profile.php?id='.$_SESSION['user_id'] : 'login.php' ?>"><i class="fas fa-user"></i> Profile</a>
    </nav>

    <script src="scripts.js"></script>
</body>
</html>
