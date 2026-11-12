<?php
/**
 * Ideas to Action: Community Proposals Hub
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$filter_status = strtolower(trim($_GET['status'] ?? 'all'));
$sort = strtolower(trim($_GET['sort'] ?? 'votes'));
$search_query = trim($_GET['q'] ?? '');

$statuses = get_proposal_statuses();
$allowed_statuses = array_keys($statuses);

// Status counts
$counts = ['all' => 0];
foreach ($allowed_statuses as $st) {
    $counts[$st] = 0;
}
$stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM proposals GROUP BY status");
while ($r = $stmt->fetch()) {
    $counts[$r['status']] = (int)$r['cnt'];
    $counts['all'] += (int)$r['cnt'];
}

// Build query
$sql = "
    SELECT p.*, u.username, u.avatar_url, u.reputation,
    (SELECT COUNT(*) FROM proposal_updates WHERE proposal_id = p.id) as update_count
    FROM proposals p
    JOIN users u ON p.user_id = u.id
    WHERE 1=1
";
$params = [];

if (in_array($filter_status, $allowed_statuses)) {
    $sql .= " AND p.status = ?";
    $params[] = $filter_status;
}

if (!empty($search_query)) {
    $sql .= " AND (p.title LIKE ? OR p.problem_description LIKE ? OR p.solution_description LIKE ?)";
    $like = '%' . $search_query . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($sort === 'recent') {
    $sql .= " ORDER BY p.created_at DESC";
} elseif ($sort === 'status') {
    $sql .= " ORDER FIELD(p.status, 'open', 'under_review', 'accepted', 'in_progress', 'resolved'), p.votes_count DESC";
} else {
    // Default: most voted
    $sql .= " ORDER BY p.votes_count DESC, p.created_at DESC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$proposals = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ideas to Action: Community Proposals — Forum Home</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="page-wrapper">
        
        <!-- Hero Header -->
        <div class="proposals-hero">
            <div class="proposals-hero-text">
                <div class="proposals-hero-badge">
                    <i class="fas fa-lightbulb"></i> COMMUNITY PROBLEM-SOLVING
                </div>
                <h1 class="proposals-hero-title">Ideas to Action</h1>
                <p class="proposals-hero-desc">
                    Transforming forum discussions into actionable community proposals. Vote on high-impact initiatives and track their progress from review to completion.
                </p>
            </div>
            <div class="proposals-hero-actions">
                <a href="new_proposal.php" class="btn btn-primary btn-lg" style="box-shadow: 0 4px 20px rgba(99, 102, 241, 0.4);">
                    <i class="fas fa-plus"></i> Submit Proposal
                </a>
            </div>
        </div>

        <!-- Filter Bar: Status Tabs & Sorting -->
        <div class="proposals-filter-bar">
            <!-- Status Tabs -->
            <div class="proposal-status-tabs">
                <a href="proposals.php?status=all&sort=<?= urlencode($sort) ?>" 
                   class="status-tab <?= $filter_status === 'all' ? 'active' : '' ?>">
                    <span>All Proposals</span>
                    <span class="tab-count"><?= $counts['all'] ?></span>
                </a>
                <?php foreach ($statuses as $k => $st): ?>
                    <a href="proposals.php?status=<?= $k ?>&sort=<?= urlencode($sort) ?>" 
                       class="status-tab <?= $filter_status === $k ? 'active' : '' ?>">
                        <i class="<?= htmlspecialchars($st['icon']) ?>" style="font-size: 0.8rem; color: <?= $st['color'] ?>;"></i>
                        <span><?= htmlspecialchars($st['label']) ?></span>
                        <span class="tab-count"><?= $counts[$k] ?? 0 ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Sort Controls -->
            <div class="proposal-sort-controls">
                <form action="proposals.php" method="GET" style="display: flex; gap: 0.5rem; align-items: center; margin: 0;">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                    <label for="sortSelect" style="font-size: 0.8rem; color: var(--text-secondary); white-space: nowrap;">Sort by:</label>
                    <select name="sort" id="sortSelect" class="form-control" onchange="this.form.submit()" style="font-size: 0.85rem; padding: 0.4rem 0.8rem;">
                        <option value="votes" <?= $sort === 'votes' ? 'selected' : '' ?>>Most Voted</option>
                        <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Most Recent</option>
                        <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Progress Status</option>
                    </select>
                </form>
            </div>
        </div>

        <!-- Proposals List Grid -->
        <?php if (empty($proposals)): ?>
            <div class="card" style="text-align: center; padding: 3rem 1.5rem; margin-top: 1.5rem;">
                <div style="font-size: 2.5rem; color: var(--text-muted); margin-bottom: 1rem;">
                    <i class="fas fa-lightbulb"></i>
                </div>
                <h3>No proposals found</h3>
                <p style="color: var(--text-secondary); max-width: 420px; margin: 0.5rem auto 1.5rem auto;">
                    <?= !empty($search_query) ? 'No proposals match your search criteria.' : 'No proposals currently match this status filter.' ?>
                </p>
                <a href="new_proposal.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Submit the First Proposal
                </a>
            </div>
        <?php else: ?>
            <div class="proposals-grid">
                <?php foreach ($proposals as $prop): ?>
                    <?php 
                        $st_info = $statuses[$prop['status']] ?? null;
                        $step_num = $st_info['step'] ?? 1;
                        $pct_progress = ($step_num / 5) * 100;
                    ?>
                    <div class="proposal-card-item">
                        <div class="prop-item-header">
                            <div class="prop-item-badges">
                                <?= get_proposal_status_badge($prop['status']) ?>
                                <?php if (!empty($prop['discussion_id'])): ?>
                                    <span class="badge-from-disc" title="Converted from community discussion">
                                        <i class="fas fa-comments"></i> From Discussion
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="prop-vote-pill">
                                <i class="fas fa-arrow-up"></i>
                                <span><?= (int)$prop['votes_count'] ?> votes</span>
                            </div>
                        </div>

                        <h3 class="prop-item-title">
                            <a href="proposal.php?id=<?= $prop['id'] ?>"><?= htmlspecialchars($prop['title']) ?></a>
                        </h3>

                        <!-- Problem excerpt -->
                        <div class="prop-item-excerpt">
                            <span class="excerpt-tag"><i class="fas fa-triangle-exclamation"></i> Problem:</span>
                            <p><?= htmlspecialchars(substr($prop['problem_description'], 0, 140)) ?>...</p>
                        </div>

                        <!-- Solution excerpt -->
                        <div class="prop-item-excerpt solution-excerpt">
                            <span class="excerpt-tag"><i class="fas fa-rocket"></i> Solution:</span>
                            <p><?= htmlspecialchars(substr($prop['solution_description'], 0, 140)) ?>...</p>
                        </div>

                        <!-- Mini Stepper Progress Bar -->
                        <div class="prop-mini-pipeline">
                            <div class="mini-pipeline-bar">
                                <div class="mini-pipeline-fill" style="width: <?= $pct_progress ?>%; background: <?= $st_info['color'] ?? 'var(--primary)' ?>;"></div>
                            </div>
                            <div class="mini-pipeline-labels">
                                <span>Stage <?= $step_num ?>/5</span>
                                <span><?= htmlspecialchars($st_info['label'] ?? '') ?></span>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="prop-item-footer">
                            <div class="prop-item-author">
                                <?php if ($prop['avatar_url']): ?>
                                    <img src="<?= htmlspecialchars($prop['avatar_url']) ?>" alt="Avatar" class="avatar-xs">
                                <?php else: ?>
                                    <div class="avatar-initials avatar-initials-xs"><?= get_avatar_initials($prop['username']) ?></div>
                                <?php endif; ?>
                                <span><?= htmlspecialchars($prop['username']) ?></span>
                                <span class="dot-separator">•</span>
                                <span style="color: var(--text-muted);"><?= time_ago($prop['created_at']) ?></span>
                            </div>

                            <a href="proposal.php?id=<?= $prop['id'] ?>" class="prop-view-link">
                                View Proposal <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <script src="scripts.js"></script>
</body>
</html>
