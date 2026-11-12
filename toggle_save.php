<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'You must be logged in to save discussions.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$csrf_token = $input['csrf_token'] ?? '';

if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    echo json_encode(['error' => 'CSRF verification failed.']);
    exit;
}

$item_type = strtolower(trim($input['item_type'] ?? ''));
$item_id = filter_var($input['item_id'] ?? null, FILTER_VALIDATE_INT);

if (!$item_id || !in_array($item_type, ['topic', 'debate', 'proposal'])) {
    echo json_encode(['error' => 'Invalid discussion item.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    // Check if already saved
    $stmt = $pdo->prepare("SELECT id FROM saved_discussions WHERE user_id = ? AND item_type = ? AND item_id = ?");
    $stmt->execute([$user_id, $item_type, $item_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("DELETE FROM saved_discussions WHERE id = ?");
        $stmt->execute([$existing['id']]);
        $saved = false;
        $message = "Removed from your saved items.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO saved_discussions (user_id, item_type, item_id) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $item_type, $item_id]);
        $saved = true;
        $message = "Saved to your bookmarks!";
    }

    echo json_encode([
        'success' => true,
        'saved' => $saved,
        'message' => $message
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
