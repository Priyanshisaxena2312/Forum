<?php
/**
 * AJAX Endpoint for Proposal Voting
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'You must be logged in to vote on proposals.']);
    exit;
}

verify_csrf();

$proposal_id = filter_var($_POST['proposal_id'] ?? null, FILTER_VALIDATE_INT);
$vote_type = strtolower(trim($_POST['vote_type'] ?? 'up'));
if (!in_array($vote_type, ['up', 'down'])) {
    $vote_type = 'up';
}

if (!$proposal_id || $proposal_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid proposal ID.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Check if proposal exists
$stmt = $pdo->prepare("SELECT id FROM proposals WHERE id = ?");
$stmt->execute([$proposal_id]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Proposal not found.']);
    exit;
}

// Check existing vote
$stmt = $pdo->prepare("SELECT id, vote_type FROM proposal_votes WHERE proposal_id = ? AND user_id = ?");
$stmt->execute([$proposal_id, $user_id]);
$existing_vote = $stmt->fetch();

$current_user_vote = null;

if ($existing_vote) {
    if ($existing_vote['vote_type'] === $vote_type) {
        // Toggle off / remove vote
        $stmt = $pdo->prepare("DELETE FROM proposal_votes WHERE id = ?");
        $stmt->execute([$existing_vote['id']]);
        $current_user_vote = null;
    } else {
        // Change vote
        $stmt = $pdo->prepare("UPDATE proposal_votes SET vote_type = ? WHERE id = ?");
        $stmt->execute([$vote_type, $existing_vote['id']]);
        $current_user_vote = $vote_type;
    }
} else {
    // New vote
    $stmt = $pdo->prepare("INSERT INTO proposal_votes (proposal_id, user_id, vote_type) VALUES (?, ?, ?)");
    $stmt->execute([$proposal_id, $user_id, $vote_type]);
    $current_user_vote = $vote_type;
}

// Recalculate net votes
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN vote_type = 'up' THEN 1 WHEN vote_type = 'down' THEN -1 ELSE 0 END), 0) as net_votes,
        COUNT(*) as total_votes
    FROM proposal_votes 
    WHERE proposal_id = ?
");
$stmt->execute([$proposal_id]);
$vote_data = $stmt->fetch();
$net_votes = (int)($vote_data['net_votes'] ?? 0);

// Update cached count in proposals table
$stmt = $pdo->prepare("UPDATE proposals SET votes_count = ? WHERE id = ?");
$stmt->execute([$net_votes, $proposal_id]);

echo json_encode([
    'success' => true,
    'votes_count' => $net_votes,
    'user_vote' => $current_user_vote
]);
