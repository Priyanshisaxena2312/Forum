<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'You must be logged in to submit a report.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$csrf_token = $data['csrf_token'] ?? '';

if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    echo json_encode(['error' => 'CSRF verification failed.']);
    exit;
}

$item_type = strtolower(trim($data['item_type'] ?? 'post'));
$item_id = filter_var($data['item_id'] ?? $data['post_id'] ?? null, FILTER_VALIDATE_INT);
$reason = trim($data['reason'] ?? '');

$allowed_types = ['post', 'topic', 'debate', 'proposal', 'user'];
if (!$item_id || !in_array($item_type, $allowed_types)) {
    echo json_encode(['error' => 'Invalid report target.']);
    exit;
}

if (empty($reason)) {
    echo json_encode(['error' => 'Please provide a reason for the report.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    // If reporting a post, populate post_id for backwards compatibility
    $post_id = ($item_type === 'post') ? $item_id : null;

    $stmt = $pdo->prepare("
        INSERT INTO reports (post_id, item_type, item_id, reported_by, reason) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$post_id, $item_type, $item_id, $user_id, sanitize_input($reason)]);

    // If it's a post, also increment reported_count on posts
    if ($item_type === 'post') {
        $pdo->prepare("UPDATE posts SET reported_count = reported_count + 1 WHERE id = ?")->execute([$item_id]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Thank you. Your report has been submitted to community moderators for review.'
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
