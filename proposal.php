<?php
/**
 * Single Proposal View: Problem, Solution, Voting, Progress Pipeline, and Update Timeline
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$proposal_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$slug = trim($_GET['slug'] ?? '');

if (!$proposal_id && empty($slug)) {
    header("Location: proposals.php");
    exit;
}

// Fetch Proposal
if ($proposal_id) {
    $stmt = $pdo->prepare("
        SELECT p.*, u.username, u.avatar_url, u.reputation, u.role
        FROM proposals p
        JOIN users u ON p.user_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$proposal_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT p.*, u.username, u.avatar_url, u.reputation, u.role
        FROM proposals p
        JOIN users u ON p.user_id = u.id
        WHERE p.slug = ?
    ");
    $stmt->execute([$slug]);
}
$proposal = $stmt->fetch();

if (!$proposal) {
    header("HTTP/1.0 404 Not Found");
    echo "Proposal not found.";
    exit;
}
$proposal_id = (int)$proposal['id'];

// Fetch Supporting Discussion details if linked
$supporting_discussion = null;
if (!empty($proposal['discussion_id'])) {
    if ($proposal['discussion_type'] === 'debate') {
        $stmt = $pdo->prepare("
            SELECT d.id, d.title, d.description, c.name as category_name 
            FROM debates d 
            JOIN categories c ON d.category_id = c.id 
            WHERE d.id = ?
        ");
        $stmt->execute([$proposal['discussion_id']]);
        $disc = $stmt->fetch();
        if ($disc) {
            $supporting_discussion = [
                'type' => 'debate',
                'title' => $disc['title'],
                'category' => $disc['category_name'],
                'url' => 'debate.php?id=' . $disc['id'],
                'snippet' => substr($disc['description'], 0, 200) . '...'
            ];
        }
    } else {
        $stmt = $pdo->prepare("
            SELECT t.id, t.title, c.name as category_name,
            (SELECT content FROM posts WHERE topic_id = t.id ORDER BY created_at ASC LIMIT 1) as snippet
            FROM topics t 
            JOIN categories c ON t.category_id = c.id 
            WHERE t.id = ?
        ");
        $stmt->execute([$proposal['discussion_id']]);
        $disc = $stmt->fetch();
        if ($disc) {
            $supporting_discussion = [
                'type' => 'topic',
                'title' => $disc['title'],
                'category' => $disc['category_name'],
                'url' => 'topic.php?id=' . $disc['id'],
                'snippet' => !empty($disc['snippet']) ? (substr(strip_tags($disc['snippet']), 0, 200) . '...') : 'Community discussion topic.'
            ];
        }
    }
}

// User's current vote
$user_vote = null;
$me_id = $_SESSION['user_id'] ?? null;
if ($me_id) {
    $stmt = $pdo->prepare("SELECT vote_type FROM proposal_votes WHERE proposal_id = ? AND user_id = ?");
    $stmt->execute([$proposal_id, $me_id]);
    $user_vote = $stmt->fetchColumn();
}

// Fetch timeline of updates
$stmt = $pdo->prepare("
    SELECT pu.*, u.username, u.avatar_url, u.role
    FROM proposal_updates pu
    JOIN users u ON pu.user_id = u.id
    WHERE pu.proposal_id = ?
    ORDER BY pu.created_at ASC
");
$stmt->execute([$proposal_id]);
$updates = $stmt->fetchAll();

// Progress steps definition
$statuses = get_proposal_statuses();
$current_step = $statuses[$proposal['status']]['step'] ?? 1;

// Permissions for updating status
$is_author = ($me_id && $proposal['user_id'] == $me_id);
$is_staff = ($me_id && in_array($_SESSION['role'] ?? '', ['admin', 'moderator']));
$can_update_status = ($is_author || $is_staff);

$is_proposal_saved = $me_id ? is_discussion_saved($pdo, $me_id, 'proposal', $proposal_id) : false;
$is_proposal_followed = $me_id ? is_discussion_followed($pdo, $me_id, 'proposal', $proposal_id) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <title><?= htmlspecialchars($proposal['title']) ?> — Community Proposal | Forum Home</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="page-wrapper proposal-page-wrapper">
        <!-- Breadcrumb -->
        <div class="debate-breadcrumb" style="margin-bottom: 1.5rem;">
            <a href="proposals.php" class="back-link"><i class="fas fa-arrow-left"></i> All Proposals</a>
            <span style="color: var(--text-muted); margin: 0 0.5rem;">/</span>
            <span style="color: var(--text-secondary);"><?= htmlspecialchars($proposal['title']) ?></span>
        </div>

        <!-- Flash messages -->
        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="flash-message flash-error"><i class="fas fa-exclamation-circle"></i> <?= $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?></div>
        <?php endif; ?>

        <!-- ==============================================
             1. PROPOSAL HERO CARD
             ============================================== -->
        <div class="proposal-hero-card">
            <div class="proposal-hero-top">
                <div class="proposal-badge-wrap">
                    <span class="badge-proposal-pill"><i class="fas fa-lightbulb"></i> ACTION PROPOSAL</span>
                    <?= get_proposal_status_badge($proposal['status']) ?>
                </div>

                <!-- Community Voting Counter & Buttons -->
                <div class="proposal-vote-widget" id="proposalVoteWidget" data-id="<?= $proposal_id ?>">
                    <button class="btn-proposal-vote vote-up <?= $user_vote === 'up' ? 'active' : '' ?>" 
                            id="btnVoteUp" title="Support this proposal" <?= !$me_id ? 'disabled' : '' ?>>
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <div class="proposal-vote-tally" id="proposalVoteTally">
                        <span class="tally-number"><?= (int)$proposal['votes_count'] ?></span>
                        <span class="tally-label">votes</span>
                    </div>
                    <button class="btn-proposal-vote vote-down <?= $user_vote === 'down' ? 'active' : '' ?>" 
                            id="btnVoteDown" title="Oppose this proposal" <?= !$me_id ? 'disabled' : '' ?>>
                        <i class="fas fa-arrow-down"></i>
                    </button>
                </div>
            </div>

            <h1 class="proposal-hero-title"><?= htmlspecialchars($proposal['title']) ?></h1>

            <div class="proposal-hero-meta" style="flex-wrap: wrap; gap: 0.5rem; align-items: center;">
                <div class="author-info">
                    <?php if ($proposal['avatar_url']): ?>
                        <img src="<?= htmlspecialchars($proposal['avatar_url']) ?>" alt="Avatar" class="avatar-sm">
                    <?php else: ?>
                        <div class="avatar-initials avatar-initials-sm"><?= get_avatar_initials($proposal['username']) ?></div>
                    <?php endif; ?>
                    <span>Proposed by <strong><?= htmlspecialchars($proposal['username']) ?></strong></span>
                    <?php if ($proposal['role'] === 'admin'): ?>
                        <span class="badge badge-danger">Admin</span>
                    <?php elseif ($proposal['role'] === 'moderator'): ?>
                        <span class="badge badge-primary">Moderator</span>
                    <?php endif; ?>
                    <?= get_user_top_badges_html($proposal['user_id'], $pdo, 2) ?>
                </div>
                <span>•</span>
                <span><i class="far fa-clock"></i> <?= time_ago($proposal['created_at']) ?></span>
                <?php if ($supporting_discussion): ?>
                    <span>•</span>
                    <a href="<?= htmlspecialchars($supporting_discussion['url']) ?>" class="meta-disc-link">
                        <i class="fas fa-comments"></i> In response to discussion
                    </a>
                <?php endif; ?>
                <span>•</span>

                <!-- Save / Bookmark Button -->
                <button type="button" class="btn btn-secondary btn-sm save-discussion-btn" data-type="proposal" data-id="<?= $proposal_id ?>" data-saved="<?= $is_proposal_saved ? 'true' : 'false' ?>" title="Save proposal to bookmarks">
                    <i class="<?= $is_proposal_saved ? 'fas fa-bookmark' : 'far fa-bookmark' ?>" style="color: <?= $is_proposal_saved ? 'var(--accent-rose)' : 'inherit' ?>;"></i>
                    <span class="save-btn-text"><?= $is_proposal_saved ? 'Saved' : 'Save' ?></span>
                </button>

                <!-- Follow Proposal Updates Button -->
                <button type="button" class="btn btn-secondary btn-sm follow-discussion-btn" data-type="proposal" data-id="<?= $proposal_id ?>" data-followed="<?= $is_proposal_followed ? 'true' : 'false' ?>" title="Get notified of stage updates">
                    <i class="<?= $is_proposal_followed ? 'fas fa-bell-slash' : 'far fa-bell' ?>" style="color: <?= $is_proposal_followed ? 'var(--accent-sky)' : 'inherit' ?>;"></i>
                    <span class="follow-btn-text"><?= $is_proposal_followed ? 'Following' : 'Follow' ?></span>
                </button>

                <button type="button" class="btn btn-secondary btn-sm report-item-btn" data-type="proposal" data-id="<?= $proposal_id ?>" title="Report this proposal" style="color: var(--text-muted);">
                    <i class="fas fa-flag"></i>
                </button>
            </div>
        </div>

        <!-- ==============================================
             2. PROGRESS STATUS PIPELINE STEPPER
             ============================================== -->
        <div class="proposal-pipeline-card">
            <div class="pipeline-title-wrap">
                <div class="pipeline-heading"><i class="fas fa-bars-progress"></i> Implementation Pipeline</div>
                <div class="pipeline-current-status">
                    Current stage: <strong><?= htmlspecialchars($statuses[$proposal['status']]['label']) ?></strong>
                </div>
            </div>

            <div class="pipeline-stepper">
                <?php foreach ($statuses as $key => $st): ?>
                    <?php 
                        $step_num = $st['step'];
                        $is_completed = ($step_num < $current_step);
                        $is_current = ($step_num === $current_step);
                        $is_upcoming = ($step_num > $current_step);
                    ?>
                    <div class="pipeline-step <?= $is_completed ? 'completed' : '' ?> <?= $is_current ? 'current' : '' ?> <?= $is_upcoming ? 'upcoming' : '' ?>">
                        <div class="step-indicator">
                            <?php if ($is_completed): ?>
                                <i class="fas fa-check"></i>
                            <?php else: ?>
                                <i class="<?= htmlspecialchars($st['icon']) ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="step-content">
                            <div class="step-label"><?= htmlspecialchars($st['label']) ?></div>
                            <div class="step-sub"><?= $is_current ? 'Active stage' : ($is_completed ? 'Passed' : 'Pending') ?></div>
                        </div>
                        <?php if ($step_num < count($statuses)): ?>
                            <div class="step-connector"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ==============================================
             3. MAIN PROPOSAL CONTENT & TIMELINE GRID
             ============================================== -->
        <div class="proposal-layout-grid">
            
            <!-- Left Column: Problem, Solution & Supporting Discussion -->
            <div class="proposal-main-content">
                
                <!-- Problem Description -->
                <div class="proposal-card proposal-problem-card">
                    <div class="proposal-card-header">
                        <div class="proposal-card-icon problem-icon"><i class="fas fa-triangle-exclamation"></i></div>
                        <div>
                            <h3>Identified Problem Description</h3>
                            <div class="proposal-card-sub">The community issue or campus challenge that prompted this proposal</div>
                        </div>
                    </div>
                    <div class="proposal-card-body">
                        <?= nl2br(htmlspecialchars($proposal['problem_description'])) ?>
                    </div>
                </div>

                <!-- Suggested Solution -->
                <div class="proposal-card proposal-solution-card">
                    <div class="proposal-card-header">
                        <div class="proposal-card-icon solution-icon"><i class="fas fa-rocket"></i></div>
                        <div>
                            <h3>Suggested Solution & Action Plan</h3>
                            <div class="proposal-card-sub">Concrete, actionable steps and implementation roadmap proposed</div>
                        </div>
                    </div>
                    <div class="proposal-card-body">
                        <?= nl2br(htmlspecialchars($proposal['solution_description'])) ?>
                    </div>
                </div>

                <!-- Supporting Community Discussion -->
                <?php if ($supporting_discussion): ?>
                    <div class="proposal-card proposal-discussion-card">
                        <div class="proposal-card-header">
                            <div class="proposal-card-icon disc-icon"><i class="fas fa-comments"></i></div>
                            <div>
                                <h3>Supporting Community Discussion</h3>
                                <div class="proposal-card-sub">Originating forum thread where this idea was brainstormed</div>
                            </div>
                        </div>
                        <div class="proposal-card-body">
                            <div class="supporting-disc-box">
                                <div class="supporting-disc-info">
                                    <span class="disc-cat-pill"><?= htmlspecialchars($supporting_discussion['category']) ?></span>
                                    <h4><?= htmlspecialchars($supporting_discussion['title']) ?></h4>
                                    <p><?= htmlspecialchars($supporting_discussion['snippet']) ?></p>
                                </div>
                                <a href="<?= htmlspecialchars($supporting_discussion['url']) ?>" class="btn btn-secondary btn-sm" target="_blank">
                                    <span>Read Discussion</span> <i class="fas fa-arrow-up-right-from-square"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

            <!-- Right Column: Timeline & Status Actions -->
            <div class="proposal-sidebar">
                
                <!-- Status Update Action Card (Author or Staff) -->
                <?php if ($can_update_status): ?>
                    <div class="proposal-sidebar-card status-change-card">
                        <div class="sidebar-card-title">
                            <i class="fas fa-sliders" style="color: var(--primary);"></i> Advance Progress Status
                        </div>
                        <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 1rem;">
                            As <?= $is_author ? 'the proposal creator' : 'a moderator' ?>, you can update the implementation status and record progress notes for the community.
                        </p>
                        <form method="POST" action="update_proposal_status.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="proposal_id" value="<?= $proposal_id ?>">

                            <div class="form-group" style="margin-bottom: 1rem;">
                                <label for="new_status" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.4rem;">
                                    New Status:
                                </label>
                                <select name="new_status" id="new_status" class="form-control" style="font-size: 0.88rem;">
                                    <?php foreach ($statuses as $k => $st): ?>
                                        <option value="<?= $k ?>" <?= $proposal['status'] === $k ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($st['label']) ?> (Stage <?= $st['step'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" style="margin-bottom: 1rem;">
                                <label for="status_note" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.4rem;">
                                    Reason & Progress Note <span style="color: var(--danger);">*</span>:
                                </label>
                                <textarea name="note" id="status_note" class="form-control" rows="3" required
                                          placeholder="Explain why the status was changed, what milestones were met, or next steps..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm" style="width: 100%; justify-content: center;">
                                <i class="fas fa-check"></i> Post Status Update
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Update Timeline Card -->
                <div class="proposal-sidebar-card timeline-card">
                    <div class="sidebar-card-title">
                        <i class="fas fa-timeline" style="color: #a855f7;"></i> Update Timeline
                    </div>
                    <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 1.25rem;">
                        Transparent record of all status changes, milestones, and reviewer notes.
                    </p>

                    <div class="proposal-timeline-stream">
                        <?php if (empty($updates)): ?>
                            <div style="font-size: 0.85rem; color: var(--text-muted); text-align: center; padding: 1rem;">
                                No status updates recorded yet.
                            </div>
                        <?php else: ?>
                            <?php foreach ($updates as $upd): ?>
                                <?php 
                                    $new_st_meta = $statuses[$upd['new_status']] ?? null;
                                    $old_st_meta = !empty($upd['old_status']) ? ($statuses[$upd['old_status']] ?? null) : null;
                                ?>
                                <div class="timeline-event-item">
                                    <div class="timeline-event-marker" style="background: <?= $new_st_meta['color'] ?? 'var(--primary)' ?>;">
                                        <i class="<?= htmlspecialchars($new_st_meta['icon'] ?? 'fas fa-circle-dot') ?>"></i>
                                    </div>
                                    <div class="timeline-event-content">
                                        <div class="timeline-event-header">
                                            <div class="timeline-user">
                                                <strong><?= htmlspecialchars($upd['username']) ?></strong>
                                                <?php if ($upd['role'] === 'admin'): ?>
                                                    <span class="badge badge-danger badge-xs">Admin</span>
                                                <?php elseif ($upd['role'] === 'moderator'): ?>
                                                    <span class="badge badge-primary badge-xs">Mod</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="timeline-time"><?= time_ago($upd['created_at']) ?></div>
                                        </div>

                                        <div class="timeline-transition-badge">
                                            <?php if ($old_st_meta): ?>
                                                <span class="status-chip chip-old"><?= htmlspecialchars($old_st_meta['label']) ?></span>
                                                <i class="fas fa-arrow-right" style="font-size: 0.7rem; color: var(--text-muted);"></i>
                                            <?php endif; ?>
                                            <span class="status-chip chip-new status-<?= htmlspecialchars($upd['new_status']) ?>">
                                                <?= htmlspecialchars($new_st_meta['label'] ?? ucfirst($upd['new_status'])) ?>
                                            </span>
                                        </div>

                                        <div class="timeline-note-bubble">
                                            <?= nl2br(htmlspecialchars($upd['note'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <script src="scripts.js"></script>
</body>
</html>
