<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'You must be logged in to vote on argument helpfulness.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

$argument_id = filter_var($input['argument_id'] ?? null, FILTER_VALIDATE_INT);

if (!$argument_id) {
    echo json_encode(['error' => 'Invalid argument ID']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    // Check argument
    $stmt = $pdo->prepare("SELECT a.id, a.user_id, a.helpful_count, d.is_locked FROM debate_arguments a JOIN debates d ON a.debate_id = d.id WHERE a.id = ?");
    $stmt->execute([$argument_id]);
    $arg = $stmt->fetch();

    if (!$arg) {
        echo json_encode(['error' => 'Argument not found']);
        exit;
    }

    if ($arg['is_locked']) {
        echo json_encode(['error' => 'This debate is locked.']);
        exit;
    }

    if ($arg['user_id'] == $user_id) {
        echo json_encode(['error' => 'You cannot vote your own argument as helpful.']);
        exit;
    }

    // Check existing vote
    $stmt = $pdo->prepare("SELECT id FROM debate_argument_votes WHERE argument_id = ? AND user_id = ?");
    $stmt->execute([$argument_id, $user_id]);
    $vote = $stmt->fetch();

    if ($vote) {
        // Remove vote
        $stmt = $pdo->prepare("DELETE FROM debate_argument_votes WHERE argument_id = ? AND user_id = ?");
        $stmt->execute([$argument_id, $user_id]);

        $stmt = $pdo->prepare("UPDATE debate_arguments SET helpful_count = GREATEST(0, helpful_count - 1) WHERE id = ?");
        $stmt->execute([$argument_id]);

        $stmt = $pdo->prepare("UPDATE users SET reputation = GREATEST(0, reputation - 1) WHERE id = ?");
        $stmt->execute([$arg['user_id']]);

        $new_count = max(0, (int)$arg['helpful_count'] - 1);
        $voted = false;
    } else {
        // Add vote
        $stmt = $pdo->prepare("INSERT INTO debate_argument_votes (argument_id, user_id) VALUES (?, ?)");
        $stmt->execute([$argument_id, $user_id]);

        $stmt = $pdo->prepare("UPDATE debate_arguments SET helpful_count = helpful_count + 1 WHERE id = ?");
        $stmt->execute([$argument_id]);

        $stmt = $pdo->prepare("UPDATE users SET reputation = reputation + 1 WHERE id = ?");
        $stmt->execute([$arg['user_id']]);

        create_notification($pdo, $arg['user_id'], 'helpful_vote', "A peer found your debate argument helpful! (+1 reputation)", "debate.php");

        $new_count = (int)$arg['helpful_count'] + 1;
        $voted = true;
    }

    echo json_encode([
        'success' => true,
        'voted' => $voted,
        'count' => $new_count
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
