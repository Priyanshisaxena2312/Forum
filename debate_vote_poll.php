<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'You must be logged in to cast an opinion.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

$debate_id = filter_var($input['debate_id'] ?? null, FILTER_VALIDATE_INT);
$vote = trim(strtolower($input['vote'] ?? ''));

if (!$debate_id || !in_array($vote, ['for', 'against', 'undecided'])) {
    echo json_encode(['error' => 'Invalid debate vote parameters']);
    exit;
}

try {
    // Check if debate exists
    $stmt = $pdo->prepare("SELECT id, is_locked FROM debates WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$debate_id]);
    $debate = $stmt->fetch();

    if (!$debate) {
        echo json_encode(['error' => 'Debate motion not found']);
        exit;
    }

    if ($debate['is_locked']) {
        echo json_encode(['error' => 'This debate is locked.']);
        exit;
    }

    $user_id = $_SESSION['user_id'];

    // Insert or update poll vote
    $stmt = $pdo->prepare("
        INSERT INTO debate_polls (debate_id, user_id, vote)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$debate_id, $user_id, $vote]);

    $stats = get_debate_poll_stats($pdo, $debate_id);

    echo json_encode([
        'success' => true,
        'user_vote' => $vote,
        'stats' => $stats
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
