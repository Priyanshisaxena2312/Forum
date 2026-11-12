<?php
require_once 'config.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$u = $stmt->fetch();

$error = '';
$success = '';

// Handle unblock action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unblock_user') {
    verify_csrf();
    $unblock_id = (int)($_POST['target_user_id'] ?? 0);
    if ($unblock_id) {
        $stmt = $pdo->prepare("DELETE FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?");
        $stmt->execute([$user_id, $unblock_id]);
        $_SESSION['flash_success'] = "User unblocked successfully.";
    }
    header('Location: edit_profile.php');
    exit;
}

// Handle Profile and Password update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    verify_csrf();
    
    // Process form upload / profile update
    $bio = sanitize_input($_POST['bio'] ?? '');
    $interests = sanitize_input($_POST['interests'] ?? '');
    
    // Handle File Upload
    $avatar_url = $u['avatar_url'];
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['avatar']['tmp_name'];
        $file_name = $_FILES['avatar']['name'];
        $file_size = $_FILES['avatar']['size'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (!in_array($file_ext, $allowed_ext)) {
            $error = "Only JPG, PNG and GIF files are allowed.";
        } elseif ($file_size > 2097152) { // 2MB
            $error = "File size must be exactly 2 MB or smaller.";
        } else {
            // assure uploads dir exists
            if (!is_dir('uploads')) {
                mkdir('uploads', 0755, true);
            }
            $new_file_name = 'avatar_' . $user_id . '_' . time() . '.' . $file_ext;
            $upload_path = 'uploads/' . $new_file_name;
            
            if (move_uploaded_file($file_tmp, $upload_path)) {
                $avatar_url = $upload_path;
            } else {
                $error = "Failed to upload avatar.";
            }
        }
    }
    
    // Update Password logic
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $password_has_changed = false;
    
    if (!empty($new_password)) {
        if (!password_verify($current_password, $u['password_hash'])) {
            $error = "Current password is incorrect.";
        } elseif (strlen($new_password) < 8) {
            $error = "New password must be at least 8 characters.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            $password_has_changed = true;
        }
    }
    
    if (empty($error)) {
        if ($password_has_changed) {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET bio = ?, interests = ?, avatar_url = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$bio, $interests, $avatar_url, $hash, $user_id]);
            $_SESSION['flash_success'] = "Profile and password updated successfully.";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET bio = ?, interests = ?, avatar_url = ? WHERE id = ?");
            $stmt->execute([$bio, $interests, $avatar_url, $user_id]);
            $_SESSION['flash_success'] = "Profile updated successfully.";
        }
        
        $_SESSION['avatar_url'] = $avatar_url; // update navbar instantly
        header('Location: edit_profile.php');
        exit;
    }
}

// Fetch currently blocked users for this user
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.avatar_url, u.role, b.created_at as blocked_at
    FROM blocked_users b
    JOIN users u ON b.blocked_id = u.id
    WHERE b.blocker_id = ?
    ORDER BY b.created_at DESC
");
$stmt->execute([$user_id]);
$blocked_users_list = $stmt->fetchAll();

// Pre-defined popular interests tags
$popular_tags = [
    'AI & Machine Learning', 'Web Development', 'System Architecture',
    'Competitive Programming', 'Placements & Career', 'UI/UX Design',
    'Cybersecurity', 'Open Source', 'Campus Life', 'Mobile Dev',
    'Cloud & DevOps', 'Data Science', 'Esports & Gaming'
];

$user_interests_arr = array_filter(array_map('trim', explode(',', $u['interests'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <title>Edit Profile — Forum Home</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container" style="max-width: 860px; justify-content: center; margin: 2rem auto; flex-direction: column;">
        <div class="card" style="width: 100%; margin-bottom: 2rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1.5rem; flex-wrap:wrap; gap:1rem;">
                <a href="profile.php?id=<?= $user_id ?>" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Profile</a>
                <span style="font-size: 0.85rem; color: var(--text-muted);"><i class="fas fa-user-gear"></i> Account Settings</span>
            </div>

            <h2 style="margin-bottom: 20px; font-weight: 700;">Edit Profile & Interests</h2>
            
            <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="flash-message flash-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <!-- Avatar row -->
                <div style="display: flex; gap: 2rem; margin-bottom: 2rem; align-items: center; flex-wrap: wrap;">
                    <div>
                        <?php if ($u['avatar_url']): ?>
                            <img src="<?= htmlspecialchars($u['avatar_url']) ?>" alt="Avatar" class="avatar" style="width: 110px; height: 110px; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <div class="avatar-initials" style="width: 110px; height: 110px; font-size: 2.75rem; border-radius: 50%;">
                                <?= get_avatar_initials($u['username']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="flex-grow: 1; min-width: 260px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-weight: 600; margin-bottom: 0.5rem; display:block;">Change Avatar (JPG/PNG/GIF max 2MB)</label>
                            <input type="file" name="avatar" class="form-control" accept="image/png, image/jpeg, image/gif">
                        </div>
                    </div>
                </div>
                
                <!-- Bio -->
                <div class="form-group">
                    <label style="font-weight: 600;">Bio / About Me</label>
                    <textarea name="bio" class="form-control" style="min-height: 100px;" placeholder="Tell the community about yourself, your department, skills, or what you enjoy discussing..."><?= htmlspecialchars($u['bio'] ?? '') ?></textarea>
                </div>
                
                <!-- Interests & Discussion Topics -->
                <div class="form-group" style="margin-top: 1.5rem; border-top: 1px solid var(--border); padding-top: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.5rem;">
                        <label style="font-weight: 600; font-size: 1.05rem;">
                            <i class="fas fa-tags" style="color: var(--primary);"></i> Topics of Interest
                        </label>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Select or add topics you follow</span>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">
                        Your interests appear on your profile and help connect you with relevant discussions and proposals.
                    </p>

                    <!-- Hidden input holding comma-separated interests -->
                    <input type="hidden" name="interests" id="interestsHiddenInput" value="<?= htmlspecialchars($u['interests'] ?? '') ?>">

                    <!-- Active Selected Tags Container -->
                    <div class="active-interests-wrap" id="activeInterestsWrap" style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; min-height: 40px; padding: 0.5rem; background: rgba(0,0,0,0.25); border: 1px solid var(--border); border-radius: var(--radius-md);">
                        <?php foreach ($user_interests_arr as $interest): ?>
                            <span class="interest-chip active" data-tag="<?= htmlspecialchars($interest) ?>">
                                <?= htmlspecialchars($interest) ?>
                                <i class="fas fa-xmark remove-tag-btn" title="Remove"></i>
                            </span>
                        <?php endforeach; ?>
                        <?php if (empty($user_interests_arr)): ?>
                            <span class="no-tags-hint" id="noTagsHint" style="color: var(--text-muted); font-size: 0.85rem; align-self: center; padding: 0 0.5rem;">No interests added yet. Click tags below or add your own!</span>
                        <?php endif; ?>
                    </div>

                    <!-- Add Custom Tag Input -->
                    <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
                        <input type="text" id="customTagInput" class="form-control" placeholder="Add custom interest (e.g. Robotics, Fintech, Hackathons)..." style="flex-grow: 1;">
                        <button type="button" class="btn btn-secondary btn-sm" id="addCustomTagBtn">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>

                    <!-- Popular suggested tags to click -->
                    <div>
                        <span style="font-size: 0.8rem; color: var(--text-muted); display: block; margin-bottom: 0.5rem;">Quick Suggestions:</span>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                            <?php foreach ($popular_tags as $ptag): ?>
                                <?php $is_in = in_array(strtolower($ptag), array_map('strtolower', $user_interests_arr)); ?>
                                <button type="button" class="suggested-tag-btn <?= $is_in ? 'added' : '' ?>" data-tag="<?= htmlspecialchars($ptag) ?>">
                                    <i class="fas <?= $is_in ? 'fa-check' : 'fa-plus' ?>"></i> <?= htmlspecialchars($ptag) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Password section -->
                <h3 style="margin: 2rem 0 1rem 0; border-top: 1px solid var(--border); padding-top: 2rem; font-weight: 700;">Change Password</h3>
                <p style="color:var(--text-muted); font-size:0.875rem; margin-bottom:1rem;">Leave blank if you do not want to change your password.</p>
                
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" minlength="8">
                </div>
                
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" minlength="8">
                </div>
                
                <div style="margin-top: 2rem; border-top: 1px solid var(--border); padding-top: 1.25rem; text-align: right;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Profile Changes</button>
                </div>
            </form>
        </div>

        <!-- Blocked Users Management Section -->
        <div class="card" style="width: 100%;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="margin: 0; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-user-slash" style="color: var(--accent-rose);"></i> Blocked Users
                </h3>
                <span class="badge badge-secondary"><?= count($blocked_users_list) ?> blocked</span>
            </div>
            <p style="color: var(--text-secondary); font-size: 0.875rem; margin-bottom: 1.5rem;">
                When you block a user, their discussions, replies, and debate arguments will be automatically hidden from your view.
            </p>

            <?php if (empty($blocked_users_list)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--text-muted); background: rgba(0,0,0,0.2); border-radius: var(--radius-md);">
                    <i class="fas fa-user-check" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                    <p>You haven't blocked any users.</p>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <?php foreach ($blocked_users_list as $bu): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.85rem 1rem; background: rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius: var(--radius-md);">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <?php if ($bu['avatar_url']): ?>
                                    <img src="<?= htmlspecialchars($bu['avatar_url']) ?>" alt="Avatar" class="avatar-sm">
                                <?php else: ?>
                                    <div class="avatar-initials avatar-initials-sm"><?= get_avatar_initials($bu['username']) ?></div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight: 600; color: var(--text);"><?= htmlspecialchars($bu['username']) ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">Blocked <?= time_ago($bu['blocked_at']) ?></div>
                                </div>
                            </div>
                            <form method="POST" action="" class="require-confirm" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="action" value="unblock_user">
                                <input type="hidden" name="target_user_id" value="<?= $bu['id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--accent-emerald);">
                                    <i class="fas fa-user-plus"></i> Unblock
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script src="scripts.js"></script>
    <script>
    // Interests Selector Client Logic
    document.addEventListener('DOMContentLoaded', () => {
        const hiddenInput = document.getElementById('interestsHiddenInput');
        const activeWrap = document.getElementById('activeInterestsWrap');
        const customInput = document.getElementById('customTagInput');
        const addBtn = document.getElementById('addCustomTagBtn');
        const suggestedBtns = document.querySelectorAll('.suggested-tag-btn');
        let noHint = document.getElementById('noTagsHint');

        function getCurrentTags() {
            return hiddenInput.value.split(',').map(t => t.trim()).filter(Boolean);
        }

        function updateHidden(tags) {
            hiddenInput.value = tags.join(', ');
            if (tags.length === 0) {
                if (!noHint) {
                    noHint = document.createElement('span');
                    noHint.id = 'noTagsHint';
                    noHint.style.cssText = 'color: var(--text-muted); font-size: 0.85rem; align-self: center; padding: 0 0.5rem;';
                    noHint.textContent = 'No interests added yet. Click tags below or add your own!';
                    activeWrap.appendChild(noHint);
                }
            } else if (noHint) {
                noHint.remove();
                noHint = null;
            }

            // Sync suggestion buttons
            suggestedBtns.forEach(btn => {
                const tag = btn.dataset.tag.toLowerCase();
                const has = tags.some(t => t.toLowerCase() === tag);
                if (has) {
                    btn.classList.add('added');
                    btn.innerHTML = `<i class="fas fa-check"></i> ${btn.dataset.tag}`;
                } else {
                    btn.classList.remove('added');
                    btn.innerHTML = `<i class="fas fa-plus"></i> ${btn.dataset.tag}`;
                }
            });
        }

        function addTag(tag) {
            tag = tag.trim();
            if (!tag) return;
            const tags = getCurrentTags();
            if (tags.some(t => t.toLowerCase() === tag.toLowerCase())) return;
            tags.push(tag);

            const chip = document.createElement('span');
            chip.className = 'interest-chip active';
            chip.dataset.tag = tag;
            chip.innerHTML = `${escapeHtml(tag)} <i class="fas fa-xmark remove-tag-btn" title="Remove"></i>`;
            activeWrap.appendChild(chip);

            updateHidden(tags);
        }

        function removeTag(tag) {
            let tags = getCurrentTags();
            tags = tags.filter(t => t.toLowerCase() !== tag.toLowerCase());
            const chip = activeWrap.querySelector(`.interest-chip[data-tag="${CSS.escape(tag)}"]`);
            if (chip) chip.remove();
            updateHidden(tags);
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        activeWrap.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-tag-btn')) {
                const chip = e.target.closest('.interest-chip');
                if (chip) removeTag(chip.dataset.tag);
            }
        });

        if (addBtn && customInput) {
            addBtn.addEventListener('click', () => {
                addTag(customInput.value);
                customInput.value = '';
                customInput.focus();
            });

            customInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addTag(customInput.value);
                    customInput.value = '';
                }
            });
        }

        suggestedBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const tag = btn.dataset.tag;
                const tags = getCurrentTags();
                if (tags.some(t => t.toLowerCase() === tag.toLowerCase())) {
                    removeTag(tag);
                } else {
                    addTag(tag);
                }
            });
        });
    });
    </script>
</body>
</html>
