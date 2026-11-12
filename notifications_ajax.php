<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $csrf_token = $input['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        echo json_encode(['error' => 'CSRF verification failed.']);
        exit;
    }
    $action = $input['action'] ?? $action;
}

try {
    if ($action === 'get_unread_count') {
        $count = get_unread_notification_count($pdo, $user_id);
        echo json_encode(['success' => true, 'count' => $count]);
        exit;
    }

    if ($action === 'get_recent') {
        $stmt = $pdo->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 7
        ");
        $stmt->execute([$user_id]);
        $notifications = $stmt->fetchAll();
        $unread_count = get_unread_notification_count($pdo, $user_id);

        $html = '';
        if (empty($notifications)) {
            $html = '<div class="notif-empty"><i class="fas fa-bell-slash"></i><p>No notifications yet</p></div>';
        } else {
            foreach ($notifications as $n) {
                $icon = 'fas fa-bell';
                if ($n['type'] === 'topic_reply' || $n['type'] === 'followed_reply') $icon = 'fas fa-reply';
                elseif ($n['type'] === 'proposal_update') $icon = 'fas fa-lightbulb';
                elseif ($n['type'] === 'debate_argument') $icon = 'fas fa-scale-balanced';
                elseif ($n['type'] === 'warning') $icon = 'fas fa-triangle-exclamation';

                $unread_cls = $n['is_read'] ? '' : 'unread';
                $html .= '<a href="' . htmlspecialchars($n['link'] ?: '#') . '" class="notif-dropdown-item ' . $unread_cls . '" data-id="' . $n['id'] . '">';
                $html .= '<div class="notif-icon"><i class="' . $icon . '"></i></div>';
                $html .= '<div class="notif-body">';
                $html .= '<div class="notif-text">' . htmlspecialchars($n['message']) . '</div>';
                $html .= '<div class="notif-time">' . time_ago($n['created_at']) . '</div>';
                $html .= '</div>';
                $html .= '</a>';
            }
        }

        echo json_encode([
            'success' => true,
            'count' => $unread_count,
            'html' => $html
        ]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_read') {
        $notif_id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if ($notif_id) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$notif_id, $user_id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $notif_id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if ($notif_id) {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$notif_id, $user_id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
