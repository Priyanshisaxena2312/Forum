<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$filter = $_GET['filter'] ?? 'all';

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $_SESSION['flash_success'] = "All notifications marked as read.";
    } elseif ($action === 'delete_all') {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $_SESSION['flash_success'] = "All notifications cleared.";
    } elseif ($action === 'delete_single') {
        $notif_id = (int)($_POST['notif_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notif_id, $user_id]);
        $_SESSION['flash_success'] = "Notification deleted.";
    }
    header('Location: notifications.php?filter=' . urlencode($filter));
    exit;
}

// Build query based on filter
$sql = "SELECT * FROM notifications WHERE user_id = ?";
$params = [$user_id];

if ($filter === 'unread') {
    $sql .= " AND is_read = 0";
} elseif ($filter === 'replies') {
    $sql .= " AND type IN ('topic_reply', 'followed_reply')";
} elseif ($filter === 'proposals') {
    $sql .= " AND type = 'proposal_update'";
} elseif ($filter === 'debates') {
    $sql .= " AND type = 'debate_argument'";
}

$sql .= " ORDER BY created_at DESC LIMIT 50";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$unread_count = get_unread_notification_count($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <title>Notifications Hub — Forum Home</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="page-wrapper" style="max-width: 900px; margin: 2rem auto; padding: 0 1.25rem;">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="font-size: 1.75rem; font-weight: 700; display: flex; align-items: center; gap: 0.6rem;">
                    <i class="fas fa-bell" style="color: var(--primary);"></i> Notifications Hub
                </h1>
                <p style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.25rem;">
                    Stay updated with replies to your topics, perspective debates, and community proposal milestones.
                </p>
            </div>

            <div style="display: flex; gap: 0.5rem;">
                <?php if ($unread_count > 0): ?>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <i class="fas fa-check-double"></i> Mark all read
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (!empty($notifications)): ?>
                    <form method="POST" action="" class="require-confirm" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="delete_all">
                        <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger);">
                            <i class="fas fa-trash-can"></i> Clear all
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter tabs -->
        <div class="notif-filter-tabs" style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; overflow-x: auto; padding-bottom: 0.5rem;">
            <a href="notifications.php?filter=all" class="notif-filter-pill <?= $filter === 'all' ? 'active' : '' ?>">All Notifications</a>
            <a href="notifications.php?filter=unread" class="notif-filter-pill <?= $filter === 'unread' ? 'active' : '' ?>">
                Unread <?= $unread_count > 0 ? "($unread_count)" : '' ?>
            </a>
            <a href="notifications.php?filter=replies" class="notif-filter-pill <?= $filter === 'replies' ? 'active' : '' ?>">Replies</a>
            <a href="notifications.php?filter=proposals" class="notif-filter-pill <?= $filter === 'proposals' ? 'active' : '' ?>">Proposals</a>
            <a href="notifications.php?filter=debates" class="notif-filter-pill <?= $filter === 'debates' ? 'active' : '' ?>">Debates</a>
        </div>

        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div>
        <?php endif; ?>

        <!-- Notification List -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <?php if (empty($notifications)): ?>
                <div style="text-align: center; padding: 4rem 2rem; color: var(--text-muted);">
                    <i class="fas fa-bell-slash" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.4;"></i>
                    <h3 style="color: var(--text-secondary); margin-bottom: 0.5rem;">All caught up!</h3>
                    <p style="font-size: 0.9rem;">You don't have any notifications under this filter right now.</p>
                </div>
            <?php else: ?>
                <div class="notif-full-list">
                    <?php foreach ($notifications as $n): ?>
                        <?php 
                            $icon = 'fas fa-bell';
                            $icon_color = '#6366f1';
                            if ($n['type'] === 'topic_reply' || $n['type'] === 'followed_reply') {
                                $icon = 'fas fa-reply';
                                $icon_color = '#38bdf8';
                            } elseif ($n['type'] === 'proposal_update') {
                                $icon = 'fas fa-lightbulb';
                                $icon_color = '#f59e0b';
                            } elseif ($n['type'] === 'debate_argument') {
                                $icon = 'fas fa-scale-balanced';
                                $icon_color = '#a855f7';
                            } elseif ($n['type'] === 'warning') {
                                $icon = 'fas fa-triangle-exclamation';
                                $icon_color = '#ef4444';
                            }
                        ?>
                        <div class="notif-full-item <?= !$n['is_read'] ? 'unread' : '' ?>">
                            <div class="notif-type-icon" style="background: rgba(255,255,255,0.05); color: <?= $icon_color ?>; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;">
                                <i class="<?= $icon ?>"></i>
                            </div>

                            <div style="flex-grow: 1;">
                                <div style="font-size: 0.95rem; line-height: 1.4; color: var(--text); margin-bottom: 0.35rem;">
                                    <?= htmlspecialchars($n['message']) ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.75rem;">
                                    <span><i class="far fa-clock"></i> <?= time_ago($n['created_at']) ?></span>
                                    <span>•</span>
                                    <span style="text-transform: capitalize;"><?= str_replace('_', ' ', $n['type']) ?></span>
                                </div>
                            </div>

                            <div style="display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
                                <?php if (!empty($n['link']) && $n['link'] !== '#'): ?>
                                    <a href="<?= htmlspecialchars($n['link']) ?>" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 0.3rem;">
                                        <span>View</span> <i class="fas fa-arrow-right"></i>
                                    </a>
                                <?php endif; ?>

                                <form method="POST" action="" style="display: inline; margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="delete_single">
                                    <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Delete notification" style="padding: 0.4rem 0.6rem; opacity: 0.6;">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="scripts.js"></script>
</body>
</html>
