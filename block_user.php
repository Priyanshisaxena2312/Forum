<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'You must be logged in to block or unblock users.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$csrf_token = $input['csrf_token'] ?? '';

if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    echo json_encode(['error' => 'CSRF verification failed.']);
    exit;
}

$target_user_id = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
$user_id = (int)$_SESSION['user_id'];

if (!$target_user_id || $target_user_id === $user_id) {
    echo json_encode(['error' => 'Invalid user selected for blocking.']);
    exit;
}

try {
    // Check if target user exists and is not admin
    $stmt = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
    $stmt->execute([$target_user_id]);
    $target = $stmt->fetch();

    if (!$target) {
        echo json_encode(['error' => 'Target user does not exist.']);
        exit;
    }

    if ($target['role'] === 'admin') {
        echo json_encode(['error' => 'Platform administrators cannot be blocked.']);
        exit;
    }

    // Check if already blocked
    $stmt = $pdo->prepare("SELECT id FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?");
    $stmt->execute([$user_id, $target_user_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("DELETE FROM blocked_users WHERE id = ?");
        $stmt->execute([$existing['id']]);
        $is_blocked = false;
        $message = "You have unblocked @" . $target['username'] . ".";
    } else {
        $stmt = $pdo->prepare("INSERT INTO blocked_users (blocker_id, blocked_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $target_user_id]);
        $is_blocked = true;
        $message = "You have blocked @" . $target['username'] . ". Their posts and arguments will be hidden from your feed.";
    }

    echo json_encode([
        'success' => true,
        'is_blocked' => $is_blocked,
        'message' => $message
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
