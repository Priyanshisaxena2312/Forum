<nav class="navbar">
    <a href="index.php" class="navbar-brand">
        <i class="fas fa-graduation-cap"></i> Forum Home
    </a>

    <div class="nav-main-links">
        <a href="index.php" class="nav-tab-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
            <i class="fas fa-comments"></i> Discussions
        </a>
        <a href="debates.php" class="nav-tab-link <?= in_array(basename($_SERVER['PHP_SELF']), ['debates.php', 'debate.php', 'new_debate.php']) ? 'active' : '' ?>">
            <i class="fas fa-scale-balanced"></i> Debates
        </a>
        <a href="proposals.php" class="nav-tab-link <?= in_array(basename($_SERVER['PHP_SELF']), ['proposals.php', 'proposal.php', 'new_proposal.php']) ? 'active' : '' ?>">
            <i class="fas fa-lightbulb"></i> Ideas to Action
        </a>
    </div>
    
    <div class="search-bar">
        <form action="search.php" method="GET" style="margin:0; padding:0;">
            <input type="text" id="searchInput" name="q" placeholder="Search discussions..." autocomplete="off">
        </form>
        <div class="search-results" id="searchResults"></div>
    </div>
    
    <div class="nav-links">
        <?php if (isset($_SESSION['user_id'])): ?>
            <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'moderator'): ?>
                <a href="admin/index.php" class="nav-admin-link"><i class="fas fa-shield-halved"></i> Admin</a>
            <?php endif; ?>

            <!-- Notifications Bell & Dropdown -->
            <div class="nav-notif-wrap" id="navNotifWrap">
                <?php
                    global $pdo;
                    $unread_notifs = isset($pdo) ? get_unread_notification_count($pdo, (int)$_SESSION['user_id']) : 0;
                ?>
                <button type="button" class="nav-notif-btn" id="navNotifBtn" aria-label="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="nav-notif-badge <?= $unread_notifs > 0 ? 'visible' : '' ?>" id="navNotifBadge">
                        <?= $unread_notifs > 99 ? '99+' : $unread_notifs ?>
                    </span>
                </button>
                <div class="nav-notif-dropdown" id="navNotifDropdown">
                    <div class="nav-notif-dropdown-header">
                        <span>Notifications</span>
                        <div style="display:flex; gap:0.5rem; align-items:center;">
                            <button type="button" class="notif-action-btn" id="markAllReadBtn" title="Mark all as read">
                                <i class="fas fa-check-double"></i>
                            </button>
                            <a href="notifications.php" class="notif-action-link" title="Open full notification center">
                                <i class="fas fa-arrow-up-right-from-square"></i>
                            </a>
                        </div>
                    </div>
                    <div class="nav-notif-dropdown-list" id="navNotifList">
                        <div style="padding: 1rem; text-align:center; color:var(--text-muted); font-size:0.85rem;">
                            <i class="fas fa-spinner fa-spin"></i> Loading...
                        </div>
                    </div>
                    <a href="notifications.php" class="nav-notif-dropdown-footer">
                        View All in Notifications Hub <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>

            <a href="profile.php?id=<?= $_SESSION['user_id'] ?>" style="display:flex; align-items:center; gap:0.5rem; text-decoration:none;">
                <?php
                    // Fetch user info for navbar to be robust if avatar changes
                    $nav_stmt = $pdo->prepare("SELECT avatar_url, username FROM users WHERE id = ?");
                    $nav_stmt->execute([$_SESSION['user_id']]);
                    $nav_u = $nav_stmt->fetch();
                ?>
                <?php if ($nav_u && $nav_u['avatar_url']): ?>
                    <img src="<?= htmlspecialchars($nav_u['avatar_url']) ?>" alt="Avatar" class="avatar">
                <?php else: ?>
                    <div class="avatar-initials"><?= get_avatar_initials($nav_u['username'] ?? 'U') ?></div>
                <?php endif; ?>
                <span style="font-weight: 600;"><?= htmlspecialchars($nav_u['username'] ?? '') ?></span>
            </a>
            <a href="logout.php" class="btn btn-secondary btn-sm" style="margin-left: 0.5rem;">Logout</a>
        <?php else: ?>
            <a href="login.php" class="btn btn-secondary btn-sm">Login</a>
            <a href="register.php" class="btn btn-primary btn-sm">Sign Up</a>
        <?php endif; ?>
    </div>

    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Menu">
        <i class="fas fa-bars"></i>
    </button>
</nav>
