<?php
require_once 'config.php';
require_once 'helpers.php';

$debate_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$slug = trim($_GET['slug'] ?? '');

if (!$debate_id && empty($slug)) {
    header("Location: debates.php");
    exit;
}

// Fetch debate
if ($debate_id) {
    $stmt = $pdo->prepare("
        SELECT d.*, c.name as category_name, c.icon as category_icon, u.username, u.avatar_url, u.reputation
        FROM debates d
        JOIN categories c ON d.category_id = c.id
        JOIN users u ON d.user_id = u.id
        WHERE d.id = ? AND d.is_deleted = 0
    ");
    $stmt->execute([$debate_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT d.*, c.name as category_name, c.icon as category_icon, u.username, u.avatar_url, u.reputation
        FROM debates d
        JOIN categories c ON d.category_id = c.id
        JOIN users u ON d.user_id = u.id
        WHERE d.slug = ? AND d.is_deleted = 0
    ");
    $stmt->execute([$slug]);
}
$debate = $stmt->fetch();

if (!$debate) {
    header("HTTP/1.0 404 Not Found");
    echo "Debate motion not found.";
    exit;
}
$debate_id = $debate['id'];

// Increment views once per session
if (!isset($_SESSION['viewed_debates'][$debate_id])) {
    $stmt = $pdo->prepare("UPDATE debates SET views = views + 1 WHERE id = ?");
    $stmt->execute([$debate_id]);
    $_SESSION['viewed_debates'][$debate_id] = true;
    $debate['views']++;
}

$me_id = $_SESSION['user_id'] ?? null;

// User's poll vote
$my_vote = null;
if ($me_id) {
    $stmt = $pdo->prepare("SELECT vote FROM debate_polls WHERE debate_id = ? AND user_id = ?");
    $stmt->execute([$debate_id, $me_id]);
    $my_vote = $stmt->fetchColumn();
}

// Poll stats
$poll_stats = get_debate_poll_stats($pdo, $debate_id);

// User's helpful votes
$my_helpful_votes = [];
if ($me_id) {
    $stmt = $pdo->prepare("
        SELECT av.argument_id 
        FROM debate_argument_votes av 
        JOIN debate_arguments a ON av.argument_id = a.id 
        WHERE a.debate_id = ? AND av.user_id = ?
    ");
    $stmt->execute([$debate_id, $me_id]);
    $my_helpful_votes = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Top (most useful) argument for FOR
$stmt = $pdo->prepare("
    SELECT a.*, u.username, u.avatar_url, u.reputation
    FROM debate_arguments a
    JOIN users u ON a.user_id = u.id
    WHERE a.debate_id = ? AND a.side = 'for' AND a.parent_id IS NULL
    ORDER BY a.helpful_count DESC, a.created_at ASC
    LIMIT 1
");
$stmt->execute([$debate_id]);
$top_for = $stmt->fetch();

// Top (most useful) argument for AGAINST
$stmt = $pdo->prepare("
    SELECT a.*, u.username, u.avatar_url, u.reputation
    FROM debate_arguments a
    JOIN users u ON a.user_id = u.id
    WHERE a.debate_id = ? AND a.side = 'against' AND a.parent_id IS NULL
    ORDER BY a.helpful_count DESC, a.created_at ASC
    LIMIT 1
");
$stmt->execute([$debate_id]);
$top_against = $stmt->fetch();

// All root arguments FOR
$stmt = $pdo->prepare("
    SELECT a.*, u.username, u.avatar_url, u.reputation
    FROM debate_arguments a
    JOIN users u ON a.user_id = u.id
    WHERE a.debate_id = ? AND a.side = 'for' AND a.parent_id IS NULL
    ORDER BY a.helpful_count DESC, a.created_at DESC
");
$stmt->execute([$debate_id]);
$arguments_for = $stmt->fetchAll();

// All root arguments AGAINST
$stmt = $pdo->prepare("
    SELECT a.*, u.username, u.avatar_url, u.reputation
    FROM debate_arguments a
    JOIN users u ON a.user_id = u.id
    WHERE a.debate_id = ? AND a.side = 'against' AND a.parent_id IS NULL
    ORDER BY a.helpful_count DESC, a.created_at DESC
");
$stmt->execute([$debate_id]);
$arguments_against = $stmt->fetchAll();

// All replies
$stmt = $pdo->prepare("
    SELECT a.*, u.username, u.avatar_url, u.reputation
    FROM debate_arguments a
    JOIN users u ON a.user_id = u.id
    WHERE a.debate_id = ? AND a.parent_id IS NOT NULL
    ORDER BY a.created_at ASC
");
$stmt->execute([$debate_id]);
$all_replies = $stmt->fetchAll();
$replies_by_parent = [];
foreach ($all_replies as $rep) {
    $replies_by_parent[$rep['parent_id']][] = $rep;
}

$total_arguments_count = count($arguments_for) + count($arguments_against) + count($all_replies);

$is_debate_saved = $me_id ? is_discussion_saved($pdo, $me_id, 'debate', $debate_id) : false;
$is_debate_followed = $me_id ? is_discussion_followed($pdo, $me_id, 'debate', $debate_id) : false;
$blocked_uids = $me_id ? get_blocked_user_ids($pdo, $me_id) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <title><?= htmlspecialchars($debate['title']) ?> — Perspective Debate | Forum Home</title>
    <meta name="description" content="<?= htmlspecialchars(substr($debate['description'], 0, 150)) ?>">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="page-wrapper debate-page-wrapper">
        <!-- Back Navigation & Breadcrumb -->
        <div class="debate-breadcrumb" style="margin-bottom: 1.25rem;">
            <a href="debates.php" class="back-link"><i class="fas fa-arrow-left"></i> All Debates</a>
            <span style="color: var(--text-muted); margin: 0 0.5rem;">/</span>
            <span style="color: var(--text-secondary);"><?= htmlspecialchars($debate['category_name']) ?></span>
        </div>

        <!-- Flash messages -->
        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="flash-message flash-error"><i class="fas fa-exclamation-circle"></i> <?= $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?></div>
        <?php endif; ?>

        <!-- ==============================================
             1. MOTION HERO HEADER
             ============================================== -->
        <div class="debate-hero-card">
            <div class="debate-hero-badge">
                <i class="fas fa-scale-balanced"></i> PERSPECTIVE DEBATE
            </div>
            <h1 class="debate-hero-title"><?= htmlspecialchars($debate['title']) ?></h1>
            
            <div class="debate-hero-meta" style="flex-wrap: wrap; gap: 0.5rem; align-items: center;">
                <div class="author-info">
                    <?php if ($debate['avatar_url']): ?>
                        <img src="<?= htmlspecialchars($debate['avatar_url']) ?>" alt="Avatar" class="avatar-sm">
                    <?php else: ?>
                        <div class="avatar-initials avatar-initials-sm"><?= get_avatar_initials($debate['username']) ?></div>
                    <?php endif; ?>
                    <span>Proposed by <strong><?= htmlspecialchars($debate['username']) ?></strong></span>
                </div>
                <span>•</span>
                <span><i class="far fa-clock"></i> <?= time_ago($debate['created_at']) ?></span>
                <span>•</span>
                <span><i class="far fa-eye"></i> <?= $debate['views'] ?> views</span>
                <span>•</span>
                <span><i class="far fa-comments"></i> <?= $total_arguments_count ?> contributions</span>
                <?php if ($debate['is_locked']): ?>
                    <span class="badge-locked"><i class="fas fa-lock"></i> Locked</span>
                <?php endif; ?>
                <span>•</span>
                
                <!-- Save / Bookmark Button -->
                <button type="button" class="btn btn-secondary btn-sm save-discussion-btn" data-type="debate" data-id="<?= $debate_id ?>" data-saved="<?= $is_debate_saved ? 'true' : 'false' ?>" title="Save debate to bookmarks">
                    <i class="<?= $is_debate_saved ? 'fas fa-bookmark' : 'far fa-bookmark' ?>" style="color: <?= $is_debate_saved ? 'var(--accent-rose)' : 'inherit' ?>;"></i>
                    <span class="save-btn-text"><?= $is_debate_saved ? 'Saved' : 'Save' ?></span>
                </button>

                <!-- Follow Debate Button -->
                <button type="button" class="btn btn-secondary btn-sm follow-discussion-btn" data-type="debate" data-id="<?= $debate_id ?>" data-followed="<?= $is_debate_followed ? 'true' : 'false' ?>" title="Get notified of new arguments">
                    <i class="<?= $is_debate_followed ? 'fas fa-bell-slash' : 'far fa-bell' ?>" style="color: <?= $is_debate_followed ? 'var(--accent-sky)' : 'inherit' ?>;"></i>
                    <span class="follow-btn-text"><?= $is_debate_followed ? 'Following' : 'Follow' ?></span>
                </button>

                <a href="new_proposal.php?debate_id=<?= $debate_id ?>" class="btn-convert-prop-link" style="color: #fde68a; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <i class="fas fa-lightbulb" style="color: #f59e0b;"></i> Convert to Proposal
                </a>

                <button type="button" class="btn btn-secondary btn-sm report-item-btn" data-type="debate" data-id="<?= $debate_id ?>" title="Report this debate" style="color: var(--text-muted);">
                    <i class="fas fa-flag"></i>
                </button>
            </div>

            <div class="debate-hero-context">
                <div class="context-label"><i class="fas fa-circle-info"></i> Context & Motion Background:</div>
                <p><?= nl2br(htmlspecialchars($debate['description'])) ?></p>
            </div>
        </div>

        <!-- AI Conversation Insights Component -->
        <?php 
            $ai_discussion_type = 'debate';
            $ai_discussion_id = $debate_id;
            include 'ai_insights_panel.php';
        ?>

        <!-- ==============================================
             2. OVERALL OPINION POLL
             ============================================== -->

        <div class="debate-poll-card" id="opinionPollCard" data-debate-id="<?= $debate_id ?>">
            <div class="poll-header">
                <div class="poll-title-wrap">
                    <h3><i class="fas fa-square-poll-vertical" style="color:var(--primary);"></i> Overall Opinion Poll</h3>
                    <p class="poll-subtitle">Where do you stand on this motion? Cast your stance below.</p>
                </div>
                <div class="poll-total-count" id="pollTotalVotes">
                    <span><?= $poll_stats['total'] ?></span> total votes
                </div>
            </div>

            <!-- Opinion Meter Gauge Bar -->
            <div class="poll-gauge-bar">
                <div class="gauge-fill gauge-for" id="gaugeFor" style="width: <?= $poll_stats['pct_for'] ?>%;" title="FOR: <?= $poll_stats['pct_for'] ?>%">
                    <?php if ($poll_stats['pct_for'] >= 10): ?><span><?= $poll_stats['pct_for'] ?>% FOR</span><?php endif; ?>
                </div>
                <div class="gauge-fill gauge-undecided" id="gaugeUndecided" style="width: <?= $poll_stats['pct_undecided'] ?>%;" title="UNDECIDED: <?= $poll_stats['pct_undecided'] ?>%">
                    <?php if ($poll_stats['pct_undecided'] >= 10): ?><span><?= $poll_stats['pct_undecided'] ?>%</span><?php endif; ?>
                </div>
                <div class="gauge-fill gauge-against" id="gaugeAgainst" style="width: <?= $poll_stats['pct_against'] ?>%;" title="AGAINST: <?= $poll_stats['pct_against'] ?>%">
                    <?php if ($poll_stats['pct_against'] >= 10): ?><span><?= $poll_stats['pct_against'] ?>% AGAINST</span><?php endif; ?>
                </div>
            </div>

            <!-- Poll Percentages Breakdown -->
            <div class="poll-breakdown">
                <div class="breakdown-item item-for">
                    <span class="dot"></span>
                    <strong id="pctFor"><?= $poll_stats['pct_for'] ?>%</strong> FOR (<span id="countFor"><?= $poll_stats['for'] ?></span>)
                </div>
                <div class="breakdown-item item-undecided">
                    <span class="dot"></span>
                    <strong id="pctUndecided"><?= $poll_stats['pct_undecided'] ?>%</strong> Undecided (<span id="countUndecided"><?= $poll_stats['undecided'] ?></span>)
                </div>
                <div class="breakdown-item item-against">
                    <span class="dot"></span>
                    <strong id="pctAgainst"><?= $poll_stats['pct_against'] ?>%</strong> AGAINST (<span id="countAgainst"><?= $poll_stats['against'] ?></span>)
                </div>
            </div>

            <!-- Vote Actions -->
            <div class="poll-actions">
                <?php if ($me_id): ?>
                    <button class="poll-vote-btn btn-vote-for <?= $my_vote === 'for' ? 'voted' : '' ?>" data-vote="for">
                        <i class="fas fa-thumbs-up"></i>
                        <span>Vote FOR</span>
                    </button>
                    <button class="poll-vote-btn btn-vote-undecided <?= $my_vote === 'undecided' ? 'voted' : '' ?>" data-vote="undecided">
                        <i class="fas fa-handshake"></i>
                        <span>Undecided</span>
                    </button>
                    <button class="poll-vote-btn btn-vote-against <?= $my_vote === 'against' ? 'voted' : '' ?>" data-vote="against">
                        <i class="fas fa-thumbs-down"></i>
                        <span>Vote AGAINST</span>
                    </button>
                <?php else: ?>
                    <div class="poll-login-hint">
                        <a href="login.php" class="btn btn-secondary btn-sm">Log in to cast your stance</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ==============================================
             3. MOST USEFUL ARGUMENTS SHOWCASE
             ============================================== -->
        <?php if ($top_for || $top_against): ?>
        <div class="showcase-card">
            <div class="showcase-title">
                <i class="fas fa-star" style="color:var(--accent-amber);"></i> Key Takeaways: Most Compelling Arguments
            </div>
            <div class="showcase-grid">
                <!-- Most Useful FOR -->
                <div class="showcase-side showcase-for">
                    <div class="showcase-side-header">
                        <span class="badge-tag tag-for"><i class="fas fa-check"></i> Most Useful FOR</span>
                        <?php if ($top_for): ?>
                            <span class="helpful-badge"><i class="fas fa-award"></i> <?= $top_for['helpful_count'] ?> found helpful</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($top_for): ?>
                        <div class="showcase-author">
                            <?php if ($top_for['avatar_url']): ?>
                                <img src="<?= htmlspecialchars($top_for['avatar_url']) ?>" alt="Avatar" class="avatar-sm">
                            <?php else: ?>
                                <div class="avatar-initials avatar-initials-sm"><?= get_avatar_initials($top_for['username']) ?></div>
                            <?php endif; ?>
                            <strong><?= htmlspecialchars($top_for['username']) ?></strong>
                            <span class="time-muted">• <?= time_ago($top_for['created_at']) ?></span>
                        </div>
                        <p class="showcase-content"><?= nl2br(htmlspecialchars($top_for['content'])) ?></p>
                        <a href="#arg-<?= $top_for['id'] ?>" class="jump-link">View in discussion <i class="fas fa-arrow-down"></i></a>
                    <?php else: ?>
                        <p style="color:var(--text-muted);font-size:0.85rem;font-style:italic;">No arguments for this side yet. Be the first to contribute!</p>
                    <?php endif; ?>
                </div>

                <!-- Most Useful AGAINST -->
                <div class="showcase-side showcase-against">
                    <div class="showcase-side-header">
                        <span class="badge-tag tag-against"><i class="fas fa-times"></i> Most Useful AGAINST</span>
                        <?php if ($top_against): ?>
                            <span class="helpful-badge"><i class="fas fa-award"></i> <?= $top_against['helpful_count'] ?> found helpful</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($top_against): ?>
                        <div class="showcase-author">
                            <?php if ($top_against['avatar_url']): ?>
                                <img src="<?= htmlspecialchars($top_against['avatar_url']) ?>" alt="Avatar" class="avatar-sm">
                            <?php else: ?>
                                <div class="avatar-initials avatar-initials-sm"><?= get_avatar_initials($top_against['username']) ?></div>
                            <?php endif; ?>
                            <strong><?= htmlspecialchars($top_against['username']) ?></strong>
                            <span class="time-muted">• <?= time_ago($top_against['created_at']) ?></span>
                        </div>
                        <p class="showcase-content"><?= nl2br(htmlspecialchars($top_against['content'])) ?></p>
                        <a href="#arg-<?= $top_against['id'] ?>" class="jump-link">View in discussion <i class="fas fa-arrow-down"></i></a>
                    <?php else: ?>
                        <p style="color:var(--text-muted);font-size:0.85rem;font-style:italic;">No arguments against this side yet. Be the first to contribute!</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ==============================================
             4. MOBILE SIDE SELECTOR TABS
             ============================================== -->
        <div class="arena-mobile-tabs" id="arenaMobileTabs">
            <button class="arena-tab active" data-side="for">
                <i class="fas fa-thumbs-up" style="color:var(--accent-emerald);"></i> FOR (<?= count($arguments_for) ?>)
            </button>
            <button class="arena-tab" data-side="against">
                <i class="fas fa-thumbs-down" style="color:var(--accent-rose);"></i> AGAINST (<?= count($arguments_against) ?>)
            </button>
        </div>

        <!-- ==============================================
             5. TWO-COLUMN DUAL PERSPECTIVES ARENA
             ============================================== -->
        <div class="arena-grid">
            <!-- ========== COLUMN 1: FOR ========== -->
            <section class="arena-column column-for" id="columnFor">
                <div class="column-header">
                    <div class="column-title">
                        <span class="icon-circle icon-for"><i class="fas fa-thumbs-up"></i></span>
                        <h2>FOR Perspective</h2>
                    </div>
                    <span class="column-count"><?= count($arguments_for) ?> arguments</span>
                </div>

                <!-- Composer FOR -->
                <?php if (!$debate['is_locked']): ?>
                    <?php if ($me_id): ?>
                    <div class="argument-composer-box composer-for">
                        <form action="add_argument.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="debate_id" value="<?= $debate_id ?>">
                            <input type="hidden" name="side" value="for">
                            <div class="composer-header">
                                <span style="font-weight:600;font-size:0.85rem;color:var(--accent-emerald);"><i class="fas fa-pen-nib"></i> Add your argument FOR</span>
                            </div>
                            <textarea name="content" class="form-control" rows="3" placeholder="Provide reasonings, evidence, or insights supporting this motion..." required></textarea>
                            <div class="composer-footer">
                                <span class="char-count">Markdown supported</span>
                                <button type="submit" class="btn btn-emerald btn-sm"><i class="fas fa-paper-plane"></i> Submit FOR</button>
                            </div>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="composer-placeholder">
                        <a href="login.php">Log in to submit an argument FOR</a>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- List of FOR arguments -->
                <div class="arguments-list">
                    <?php if (empty($arguments_for)): ?>
                        <div class="empty-arguments">
                            <i class="fas fa-comments" style="color:var(--text-muted);font-size:1.5rem;margin-bottom:0.5rem;"></i>
                            <p>No supporting arguments yet. Be the first to build the case!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($arguments_for as $arg): ?>
                            <div class="argument-card" id="arg-<?= $arg['id'] ?>">
                                <div class="argument-header">
                                    <div class="arg-user">
                                        <?php if ($arg['avatar_url']): ?>
                                            <img src="<?= htmlspecialchars($arg['avatar_url']) ?>" alt="Avatar" class="avatar-sm">
                                        <?php else: ?>
                                            <div class="avatar-initials avatar-initials-sm"><?= get_avatar_initials($arg['username']) ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <span class="arg-author-name"><?= htmlspecialchars($arg['username']) ?></span>
                                            <span class="arg-time"><?= time_ago($arg['created_at']) ?></span>
                                        </div>
                                    </div>
                                    <?= get_reputation_badge($arg['reputation']) ?>
                                </div>

                                <div class="argument-body">
                                    <?= nl2br(htmlspecialchars($arg['content'])) ?>
                                </div>

                                <div class="argument-footer">
                                    <button class="helpful-vote-btn <?= in_array($arg['id'], $my_helpful_votes) ? 'voted' : '' ?>" 
                                            data-argument-id="<?= $arg['id'] ?>"
                                            title="Mark as helpful">
                                        <i class="fas fa-thumbs-up"></i>
                                        <span>Helpful</span>
                                        <span class="helpful-count-badge"><?= $arg['helpful_count'] ?></span>
                                    </button>

                                    <?php if ($me_id && !$debate['is_locked']): ?>
                                    <button class="reply-toggle-btn" data-target="reply-box-<?= $arg['id'] ?>">
                                        <i class="fas fa-reply"></i> Reply (<?= isset($replies_by_parent[$arg['id']]) ? count($replies_by_parent[$arg['id']]) : 0 ?>)
                                    </button>
                                    <?php endif; ?>
                                </div>

                                <!-- In-place reply form -->
                                <?php if ($me_id && !$debate['is_locked']): ?>
                                <div class="nested-reply-form" id="reply-box-<?= $arg['id'] ?>" style="display:none;">
                                    <form action="add_argument.php" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="debate_id" value="<?= $debate_id ?>">
                                        <input type="hidden" name="parent_id" value="<?= $arg['id'] ?>">
                                        <input type="hidden" name="side" value="for">
                                        <textarea name="content" class="form-control" rows="2" placeholder="Write a response or counterpoint..." required></textarea>
                                        <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:0.4rem;">
                                            <button type="button" class="btn btn-secondary btn-sm cancel-reply-btn" data-target="reply-box-<?= $arg['id'] ?>">Cancel</button>
                                            <button type="submit" class="btn btn-primary btn-sm">Post Reply</button>
                                        </div>
                                    </form>
                                </div>
                                <?php endif; ?>

                                <!-- Threaded Replies -->
                                <?php if (!empty($replies_by_parent[$arg['id']])): ?>
                                <div class="replies-container">
                                    <?php foreach ($replies_by_parent[$arg['id']] as $reply): ?>
                                    <div class="nested-reply" id="arg-<?= $reply['id'] ?>">
                                        <div class="nested-reply-header">
                                            <div class="arg-user">
                                                <?php if ($reply['avatar_url']): ?>
                                                    <img src="<?= htmlspecialchars($reply['avatar_url']) ?>" alt="Avatar" class="avatar-xs">
                                                <?php else: ?>
                                                    <div class="avatar-initials avatar-initials-xs"><?= get_avatar_initials($reply['username']) ?></div>
                                                <?php endif; ?>
                                                <span class="nested-reply-author"><?= htmlspecialchars($reply['username']) ?></span>
                                                <span class="arg-time"><?= time_ago($reply['created_at']) ?></span>
                                            </div>
                                        </div>
                                        <div class="nested-reply-body">
                                            <?= nl2br(htmlspecialchars($reply['content'])) ?>
                                        </div>
                                        <div class="nested-reply-actions">
                                            <button class="helpful-vote-btn helpful-vote-btn-sm <?= in_array($reply['id'], $my_helpful_votes) ? 'voted' : '' ?>" 
                                                    data-argument-id="<?= $reply['id'] ?>">
                                                <i class="fas fa-thumbs-up"></i>
                                                <span class="helpful-count-badge"><?= $reply['helpful_count'] ?></span>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ========== COLUMN 2: AGAINST ========== -->
            <section class="arena-column column-against" id="columnAgainst">
                <div class="column-header">
                    <div class="column-title">
                        <span class="icon-circle icon-against"><i class="fas fa-thumbs-down"></i></span>
                        <h2>AGAINST Perspective</h2>
                    </div>
                    <span class="column-count"><?= count($arguments_against) ?> arguments</span>
                </div>

                <!-- Composer AGAINST -->
                <?php if (!$debate['is_locked']): ?>
                    <?php if ($me_id): ?>
                    <div class="argument-composer-box composer-against">
                        <form action="add_argument.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="debate_id" value="<?= $debate_id ?>">
                            <input type="hidden" name="side" value="against">
                            <div class="composer-header">
                                <span style="font-weight:600;font-size:0.85rem;color:var(--accent-rose);"><i class="fas fa-pen-nib"></i> Add your argument AGAINST</span>
                            </div>
                            <textarea name="content" class="form-control" rows="3" placeholder="Provide counter-arguments, caveats, or alternative evidence..." required></textarea>
                            <div class="composer-footer">
                                <span class="char-count">Markdown supported</span>
                                <button type="submit" class="btn btn-rose btn-sm"><i class="fas fa-paper-plane"></i> Submit AGAINST</button>
                            </div>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="composer-placeholder">
                        <a href="login.php">Log in to submit an argument AGAINST</a>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- List of AGAINST arguments -->
                <div class="arguments-list">
                    <?php if (empty($arguments_against)): ?>
                        <div class="empty-arguments">
                            <i class="fas fa-comments" style="color:var(--text-muted);font-size:1.5rem;margin-bottom:0.5rem;"></i>
                            <p>No opposing arguments yet. Share a counter perspective!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($arguments_against as $arg): ?>
                            <div class="argument-card" id="arg-<?= $arg['id'] ?>">
                                <div class="argument-header">
                                    <div class="arg-user">
                                        <?php if ($arg['avatar_url']): ?>
                                            <img src="<?= htmlspecialchars($arg['avatar_url']) ?>" alt="Avatar" class="avatar-sm">
                                        <?php else: ?>
                                            <div class="avatar-initials avatar-initials-sm"><?= get_avatar_initials($arg['username']) ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <span class="arg-author-name"><?= htmlspecialchars($arg['username']) ?></span>
                                            <span class="arg-time"><?= time_ago($arg['created_at']) ?></span>
                                        </div>
                                    </div>
                                    <?= get_reputation_badge($arg['reputation']) ?>
                                </div>

                                <div class="argument-body">
                                    <?= nl2br(htmlspecialchars($arg['content'])) ?>
                                </div>

                                <div class="argument-footer">
                                    <button class="helpful-vote-btn <?= in_array($arg['id'], $my_helpful_votes) ? 'voted' : '' ?>" 
                                            data-argument-id="<?= $arg['id'] ?>"
                                            title="Mark as helpful">
                                        <i class="fas fa-thumbs-up"></i>
                                        <span>Helpful</span>
                                        <span class="helpful-count-badge"><?= $arg['helpful_count'] ?></span>
                                    </button>

                                    <?php if ($me_id && !$debate['is_locked']): ?>
                                    <button class="reply-toggle-btn" data-target="reply-box-<?= $arg['id'] ?>">
                                        <i class="fas fa-reply"></i> Reply (<?= isset($replies_by_parent[$arg['id']]) ? count($replies_by_parent[$arg['id']]) : 0 ?>)
                                    </button>
                                    <?php endif; ?>
                                </div>

                                <!-- In-place reply form -->
                                <?php if ($me_id && !$debate['is_locked']): ?>
                                <div class="nested-reply-form" id="reply-box-<?= $arg['id'] ?>" style="display:none;">
                                    <form action="add_argument.php" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="debate_id" value="<?= $debate_id ?>">
                                        <input type="hidden" name="parent_id" value="<?= $arg['id'] ?>">
                                        <input type="hidden" name="side" value="against">
                                        <textarea name="content" class="form-control" rows="2" placeholder="Write a response or counterpoint..." required></textarea>
                                        <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:0.4rem;">
                                            <button type="button" class="btn btn-secondary btn-sm cancel-reply-btn" data-target="reply-box-<?= $arg['id'] ?>">Cancel</button>
                                            <button type="submit" class="btn btn-primary btn-sm">Post Reply</button>
                                        </div>
                                    </form>
                                </div>
                                <?php endif; ?>

                                <!-- Threaded Replies -->
                                <?php if (!empty($replies_by_parent[$arg['id']])): ?>
                                <div class="replies-container">
                                    <?php foreach ($replies_by_parent[$arg['id']] as $reply): ?>
                                    <div class="nested-reply" id="arg-<?= $reply['id'] ?>">
                                        <div class="nested-reply-header">
                                            <div class="arg-user">
                                                <?php if ($reply['avatar_url']): ?>
                                                    <img src="<?= htmlspecialchars($reply['avatar_url']) ?>" alt="Avatar" class="avatar-xs">
                                                <?php else: ?>
                                                    <div class="avatar-initials avatar-initials-xs"><?= get_avatar_initials($reply['username']) ?></div>
                                                <?php endif; ?>
                                                <span class="nested-reply-author"><?= htmlspecialchars($reply['username']) ?></span>
                                                <span class="arg-time"><?= time_ago($reply['created_at']) ?></span>
                                            </div>
                                        </div>
                                        <div class="nested-reply-body">
                                            <?= nl2br(htmlspecialchars($reply['content'])) ?>
                                        </div>
                                        <div class="nested-reply-actions">
                                            <button class="helpful-vote-btn helpful-vote-btn-sm <?= in_array($reply['id'], $my_helpful_votes) ? 'voted' : '' ?>" 
                                                    data-argument-id="<?= $reply['id'] ?>">
                                                <i class="fas fa-thumbs-up"></i>
                                                <span class="helpful-count-badge"><?= $reply['helpful_count'] ?></span>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <script src="scripts.js"></script>
</body>
</html>
