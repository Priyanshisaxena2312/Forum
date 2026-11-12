<?php
/**
 * AJAX Endpoint for AI Conversation Insights
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_insights_service.php';

// Accept both GET and POST
$action = $_REQUEST['action'] ?? 'get';
$type = strtolower(trim($_REQUEST['type'] ?? 'topic'));
$id = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT);
$force = !empty($_REQUEST['force']) && $_REQUEST['force'] == 1;

if (!in_array($type, ['topic', 'debate'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid discussion type. Must be "topic" or "debate".']);
    exit;
}

if (!$id || $id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid or missing discussion ID.']);
    exit;
}

try {
    if ($action === 'get') {
        $insights = get_cached_insights($pdo, $type, $id);
        if ($insights) {
            echo json_encode([
                'success' => true,
                'cached' => true,
                'insights' => $insights
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'cached' => false,
                'insights' => null
            ]);
        }
        exit;
    }

    if ($action === 'analyze') {
        // If force refresh requested via POST, verify CSRF token
        if ($force && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid security token (CSRF). Please reload the page.']);
                exit;
            }
        }

        $result = get_or_generate_discussion_insights($pdo, $type, $id, $force);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to process AI insights: ' . $e->getMessage()
    ]);
}
