<?php
require_once '../config.php';
require_once '../helpers.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $report_id = (int)$_POST['report_id'];
    $action = $_POST['action'] ?? '';
    
    $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch();
    
    if ($report) {
        $item_type = $report['item_type'] ?? 'post';
        $item_id = $report['item_id'] ?: $report['post_id'];

        if ($action === 'dismiss') {
            $pdo->prepare("UPDATE reports SET status = 'dismissed' WHERE id = ?")->execute([$report_id]);
            $_SESSION['flash_success'] = "Report dismissed.";
        } elseif ($action === 'delete') {
            if ($item_type === 'post') {
                $pdo->prepare("UPDATE posts SET is_deleted = 1 WHERE id = ?")->execute([$item_id]);
            } elseif ($item_type === 'topic') {
                $pdo->prepare("UPDATE topics SET is_deleted = 1 WHERE id = ?")->execute([$item_id]);
            } elseif ($item_type === 'debate') {
                $pdo->prepare("UPDATE debates SET is_deleted = 1 WHERE id = ?")->execute([$item_id]);
            }
            $pdo->prepare("UPDATE reports SET status = 'resolved' WHERE id = ?")->execute([$report_id]);
            $_SESSION['flash_success'] = "Reported content removed/soft-deleted.";
        } elseif ($action === 'warn') {
            // Find offender user ID
            $offender_id = null;
            if ($item_type === 'post') {
                $s = $pdo->prepare("SELECT user_id FROM posts WHERE id = ?");
                $s->execute([$item_id]);
                $offender_id = $s->fetchColumn();
            } elseif ($item_type === 'topic') {
                $s = $pdo->prepare("SELECT user_id FROM topics WHERE id = ?");
                $s->execute([$item_id]);
                $offender_id = $s->fetchColumn();
            } elseif ($item_type === 'debate') {
                $s = $pdo->prepare("SELECT user_id FROM debates WHERE id = ?");
                $s->execute([$item_id]);
                $offender_id = $s->fetchColumn();
            } elseif ($item_type === 'proposal') {
                $s = $pdo->prepare("SELECT user_id FROM proposals WHERE id = ?");
                $s->execute([$item_id]);
                $offender_id = $s->fetchColumn();
            } elseif ($item_type === 'user') {
                $offender_id = $item_id;
            }

            if ($offender_id) {
                create_notification(
                    $pdo,
                    $offender_id,
                    'warning',
                    'Official Community Warning: Content created by your account was reported and found in violation of our community standards. Please maintain respectful discourse.',
                    '#'
                );
            }
            $pdo->prepare("UPDATE reports SET status = 'resolved' WHERE id = ?")->execute([$report_id]);
            $_SESSION['flash_success'] = "Official warning issued to user.";
        } elseif ($action === 'ban_user') {
            $offender_id = (int)($_POST['offender_id'] ?? 0);
            if ($offender_id) {
                $pdo->prepare("UPDATE users SET role = 'banned', is_banned = 1 WHERE id = ?")->execute([$offender_id]);
                $pdo->prepare("UPDATE reports SET status = 'resolved' WHERE id = ?")->execute([$report_id]);
                $_SESSION['flash_success'] = "User suspended/banned.";
            }
        }
    }
    header('Location: reports.php');
    exit;
}

// Fetch all pending reports
$stmt = $pdo->query("
    SELECT r.*, u1.username as reporter
    FROM reports r 
    JOIN users u1 ON r.reported_by = u1.id 
    WHERE r.status = 'pending' 
    ORDER BY r.created_at ASC
");
$raw_reports = $stmt->fetchAll();

// Enrich reports with content details
$pending_reports = [];
foreach ($raw_reports as $r) {
    $type = $r['item_type'] ?? 'post';
    $target_id = $r['item_id'] ?: $r['post_id'];
    $snippet = '';
    $link = '#';
    $offender_name = 'Unknown';
    $offender_id = null;

    if ($type === 'post') {
        $st = $pdo->prepare("SELECT p.content, p.topic_id, u.username, u.id as user_id FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
        $st->execute([$target_id]);
        $p = $st->fetch();
        if ($p) {
            $snippet = $p['content'];
            $link = "../topic.php?id=" . $p['topic_id'] . "#post-" . $target_id;
            $offender_name = $p['username'];
            $offender_id = $p['user_id'];
        }
    } elseif ($type === 'topic') {
        $st = $pdo->prepare("SELECT t.title, u.username, u.id as user_id FROM topics t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
        $st->execute([$target_id]);
        $t = $st->fetch();
        if ($t) {
            $snippet = "Topic: " . $t['title'];
            $link = "../topic.php?id=" . $target_id;
            $offender_name = $t['username'];
            $offender_id = $t['user_id'];
        }
    } elseif ($type === 'debate') {
        $st = $pdo->prepare("SELECT d.title, d.description, u.username, u.id as user_id FROM debates d JOIN users u ON d.user_id = u.id WHERE d.id = ?");
        $st->execute([$target_id]);
        $d = $st->fetch();
        if ($d) {
            $snippet = "Debate: " . $d['title'] . "\n" . $d['description'];
            $link = "../debate.php?id=" . $target_id;
            $offender_name = $d['username'];
            $offender_id = $d['user_id'];
        }
    } elseif ($type === 'proposal') {
        $st = $pdo->prepare("SELECT p.title, p.problem_description, u.username, u.id as user_id FROM proposals p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
        $st->execute([$target_id]);
        $pr = $st->fetch();
        if ($pr) {
            $snippet = "Proposal: " . $pr['title'] . "\n" . $pr['problem_description'];
            $link = "../proposal.php?id=" . $target_id;
            $offender_name = $pr['username'];
            $offender_id = $pr['user_id'];
        }
    } elseif ($type === 'user') {
        $st = $pdo->prepare("SELECT username, bio, id as user_id FROM users WHERE id = ?");
        $st->execute([$target_id]);
        $u = $st->fetch();
        if ($u) {
            $snippet = "Reported Profile: @" . $u['username'] . ($u['bio'] ? "\nBio: " . $u['bio'] : '');
            $link = "../profile.php?id=" . $target_id;
            $offender_name = $u['username'];
            $offender_id = $u['user_id'];
        }
    }

    $r['content_snippet'] = $snippet ?: '[Content removed or not found]';
    $r['content_link'] = $link;
    $r['offender_name'] = $offender_name;
    $r['offender_id'] = $offender_id;
    $pending_reports[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Moderation - Admin Panel - Forum Home</title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Syne:wght@500;700&display=swap" rel="stylesheet">
    <style>
        .admin-nav {
            background-color: var(--card);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            border: 1px solid var(--border);
        }
        .admin-nav a {
            padding: 0.5rem 1rem;
            text-decoration: none;
            color: var(--text);
            border-radius: 4px;
            font-weight: 500;
        }
        .admin-nav a.active, .admin-nav a:hover {
            background-color: var(--primary);
            color: white;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th { background-color: rgba(255,255,255,0.02); }
    </style>
</head>
<body>
    <nav class="navbar" style="background: #1e1e2d; border-bottom: none;">
        <a href="../index.php" class="navbar-brand" style="color: white;">
            <i class="fas fa-shield-alt"></i> Forum Home <span style="font-size: 0.7em; font-weight: normal; opacity: 0.8;">Admin Panel</span>
        </a>
        <div class="nav-links">
            <a href="../index.php" style="color: white;"><i class="fas fa-arrow-left"></i> Back to Forum</a>
        </div>
    </nav>
    
    <div class="container">
        <div style="width: 100%;">
            <nav class="admin-nav">
                <a href="index.php"><i class="fas fa-chart-line"></i> Dashboard</a>
                <a href="reports.php" class="active"><i class="fas fa-flag"></i> Reports (<?= count($pending_reports) ?>)</a>
                <a href="users.php"><i class="fas fa-users"></i> Users</a>
                <a href="categories.php"><i class="fas fa-folder"></i> Categories</a>
            </nav>

            <h2 style="margin-bottom: 1.5rem;">Moderation Reports Queue</h2>
            
            <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="flash-message flash-success"><?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div>
            <?php endif; ?>

            <div class="card" style="padding: 0; overflow-x: auto;">
                <?php if (empty($pending_reports)): ?>
                    <div style="padding: 3rem; color: var(--text-muted); text-align: center;">
                        <i class="fas fa-shield-check" style="font-size: 2.5rem; margin-bottom: 0.75rem; color: var(--accent-emerald);"></i>
                        <p style="font-size: 1rem; color: var(--text);">Queue is clear! No pending reports.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Report Details</th>
                                <th>Flagged Item</th>
                                <th>Moderation Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_reports as $report): ?>
                                <tr>
                                    <td style="vertical-align: top; width: 30%;">
                                        <div style="margin-bottom: 0.35rem;">
                                            <span class="badge badge-secondary" style="text-transform: uppercase; font-size: 0.65rem;">
                                                <?= htmlspecialchars($report['item_type'] ?? 'post') ?>
                                            </span>
                                        </div>
                                        <strong>Reported by:</strong> <?= htmlspecialchars($report['reporter']) ?><br>
                                        <span style="font-size: 0.8rem; color: var(--text-muted);"><i class="far fa-clock"></i> <?= date('M j, Y H:i', strtotime($report['created_at'])) ?> (<?= time_ago($report['created_at']) ?>)</span>
                                        <div style="margin-top:0.5rem; background: rgba(255,255,255,0.03); padding:0.6rem; border-radius:6px; border: 1px solid var(--border);">
                                            <strong style="color: var(--accent-rose); font-size: 0.8rem;">Reason:</strong><br>
                                            <span style="font-size: 0.85rem;"><?= nl2br(htmlspecialchars($report['reason'])) ?></span>
                                        </div>
                                    </td>

                                    <td style="vertical-align: top; width: 45%;">
                                        <div style="margin-bottom: 0.5rem; font-size: 0.875rem;">
                                            <strong>Offender:</strong> 
                                            <?php if ($report['offender_id']): ?>
                                                <a href="../profile.php?id=<?= $report['offender_id'] ?>" target="_blank" style="color: var(--primary); font-weight: 600;">
                                                    @<?= htmlspecialchars($report['offender_name']) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= htmlspecialchars($report['offender_name']) ?>
                                            <?php endif; ?>
                                            | 
                                            <a href="<?= htmlspecialchars($report['content_link']) ?>" target="_blank" style="color: var(--accent-sky);">
                                                View Source <i class="fas fa-external-link-alt"></i>
                                            </a>
                                        </div>
                                        <div style="background: rgba(0,0,0,0.3); padding: 0.85rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.85rem; max-height: 150px; overflow-y: auto;">
                                            <?= nl2br(htmlspecialchars(mb_substr($report['content_snippet'], 0, 400) . (mb_strlen($report['content_snippet']) > 400 ? '...' : ''))) ?>
                                        </div>
                                    </td>

                                    <td style="vertical-align: top; width: 25%;">
                                        <!-- Delete / Soft-delete content -->
                                        <form method="POST" action="" class="require-confirm" style="display:block; margin-bottom: 0.4rem;">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn btn-danger btn-sm" style="width: 100%;">
                                                <i class="fas fa-trash"></i> Remove Content
                                            </button>
                                        </form>
                                        
                                        <!-- Official Warning -->
                                        <form method="POST" action="" style="display:block; margin-bottom: 0.4rem;">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                            <input type="hidden" name="action" value="warn">
                                            <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; background: #f59e0b; color: white;">
                                                <i class="fas fa-triangle-exclamation"></i> Official Warning
                                            </button>
                                        </form>

                                        <!-- Suspend / Ban User -->
                                        <?php if ($report['offender_id']): ?>
                                            <form method="POST" action="" class="require-confirm" style="display:block; margin-bottom: 0.4rem;">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                                <input type="hidden" name="offender_id" value="<?= $report['offender_id'] ?>">
                                                <input type="hidden" name="action" value="ban_user">
                                                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; color: var(--danger); border: 1px solid var(--danger);">
                                                    <i class="fas fa-ban"></i> Suspend User
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <!-- Dismiss -->
                                        <form method="POST" action="" style="display:block;">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                            <input type="hidden" name="action" value="dismiss">
                                            <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%;">
                                                <i class="fas fa-times"></i> Dismiss
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="../scripts.js"></script>
</body>
</html>
