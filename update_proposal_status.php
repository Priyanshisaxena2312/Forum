<?php
/**
 * Update Proposal Status and Record in Timeline
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: proposals.php");
    exit;
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['flash_error'] = "You must be logged in to update a proposal.";
    header("Location: login.php");
    exit;
}

verify_csrf();

$proposal_id = filter_var($_POST['proposal_id'] ?? null, FILTER_VALIDATE_INT);
$new_status = strtolower(trim($_POST['new_status'] ?? ''));
$note = trim($_POST['note'] ?? '');

$allowed_statuses = array_keys(get_proposal_statuses());

if (!$proposal_id || !in_array($new_status, $allowed_statuses)) {
    $_SESSION['flash_error'] = "Invalid proposal status submitted.";
    header("Location: proposals.php");
    exit;
}

if (empty($note)) {
    $_SESSION['flash_error'] = "Please provide an update note explaining why the status was changed.";
    header("Location: proposal.php?id=" . $proposal_id);
    exit;
}

// Fetch proposal
$stmt = $pdo->prepare("SELECT * FROM proposals WHERE id = ?");
$stmt->execute([$proposal_id]);
$proposal = $stmt->fetch();

if (!$proposal) {
    $_SESSION['flash_error'] = "Proposal not found.";
    header("Location: proposals.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'member';
$is_author = ($proposal['user_id'] == $user_id);
$is_staff = in_array($user_role, ['admin', 'moderator']);

if (!$is_author && !$is_staff) {
    $_SESSION['flash_error'] = "Only the proposal creator or community moderators can change this proposal's status.";
    header("Location: proposal.php?id=" . $proposal_id);
    exit;
}

$old_status = $proposal['status'];

// Update proposal status
$stmt = $pdo->prepare("UPDATE proposals SET status = ?, updated_at = NOW() WHERE id = ?");
$stmt->execute([$new_status, $proposal_id]);

// Add entry to proposal_updates timeline
$stmt = $pdo->prepare("
    INSERT INTO proposal_updates (proposal_id, user_id, old_status, new_status, note) 
    VALUES (?, ?, ?, ?, ?)
");
$stmt->execute([$proposal_id, $user_id, $old_status, $new_status, $note]);

$status_labels = get_proposal_statuses();
$new_label = $status_labels[$new_status]['label'] ?? ucfirst($new_status);

// Notify proposal author, followers, and voters
notify_proposal_followers($pdo, $proposal_id, $user_id, $proposal['title'], $new_label);

// If proposal reached 'accepted' stage, reward author with +10 reputation for constructive impact
if ($new_status === 'accepted' && $old_status !== 'accepted') {
    $pdo->prepare("UPDATE users SET reputation = reputation + 10 WHERE id = ?")->execute([$proposal['user_id']]);
    create_notification($pdo, $proposal['user_id'], 'proposal_update', "🌟 Major Milestone: Your proposal \"" . htmlspecialchars(mb_substr($proposal['title'], 0, 40)) . "...\" was ACCEPTED! (+10 reputation)", "proposal.php?id=$proposal_id");
}

$_SESSION['flash_success'] = "Proposal status updated to '$new_label' and recorded in timeline!";
header("Location: proposal.php?id=" . $proposal_id);
exit;
