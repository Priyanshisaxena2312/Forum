<?php
require_once 'config.php';
require_once 'helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: debates.php");
    exit;
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['flash_error'] = "You must be logged in to participate in the debate.";
    header("Location: login.php");
    exit;
}

verify_csrf();

$debate_id = filter_var($_POST['debate_id'] ?? null, FILTER_VALIDATE_INT);
$side = trim(strtolower($_POST['side'] ?? ''));
$content = trim($_POST['content'] ?? '');
$parent_id = !empty($_POST['parent_id']) ? filter_var($_POST['parent_id'], FILTER_VALIDATE_INT) : null;

if (!$debate_id || !in_array($side, ['for', 'against'])) {
    $_SESSION['flash_error'] = "Invalid debate or argument side selected.";
    header("Location: debates.php");
    exit;
}

if (empty($content) || mb_strlen($content) < 5) {
    $_SESSION['flash_error'] = "Your argument must be at least 5 characters long.";
    header("Location: debate.php?id=" . $debate_id);
    exit;
}

if (mb_strlen($content) > 5000) {
    $_SESSION['flash_error'] = "Your argument exceeds the 5,000 character limit.";
    header("Location: debate.php?id=" . $debate_id);
    exit;
}

try {
    // Check debate status
    $stmt = $pdo->prepare("SELECT id, title, is_locked, is_deleted FROM debates WHERE id = ?");
    $stmt->execute([$debate_id]);
    $debate = $stmt->fetch();

    if (!$debate || $debate['is_deleted']) {
        $_SESSION['flash_error'] = "Debate motion not found.";
        header("Location: debates.php");
        exit;
    }

    if ($debate['is_locked']) {
        $_SESSION['flash_error'] = "This debate is locked for new contributions.";
        header("Location: debate.php?id=" . $debate_id);
        exit;
    }

    // If parent_id is specified, verify it exists and belongs to this debate
    if ($parent_id) {
        $stmt = $pdo->prepare("SELECT id, debate_id, side FROM debate_arguments WHERE id = ?");
        $stmt->execute([$parent_id]);
        $parent = $stmt->fetch();
        if (!$parent || $parent['debate_id'] != $debate_id) {
            $parent_id = null; // fallback to root
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO debate_arguments (debate_id, user_id, parent_id, side, content)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$debate_id, $_SESSION['user_id'], $parent_id, $side, $content]);
    $new_arg_id = $pdo->lastInsertId();

    // Reward reputation for contribution
    $stmt = $pdo->prepare("UPDATE users SET reputation = reputation + 2 WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);

    // Send notifications to debate creator & followers
    notify_debate_followers($pdo, $debate_id, (int)$_SESSION['user_id'], $_SESSION['username'], $debate['title'], $new_arg_id, $side);

    // If replying to a parent argument, notify that argument's author
    if ($parent_id) {
        $p_stmt = $pdo->prepare("SELECT user_id FROM debate_arguments WHERE id = ?");
        $p_stmt->execute([$parent_id]);
        $p_author_id = $p_stmt->fetchColumn();
        if ($p_author_id && $p_author_id != $_SESSION['user_id']) {
            create_notification(
                $pdo,
                $p_author_id,
                'debate_argument',
                $_SESSION['username'] . " replied to your perspective in debate: \"" . htmlspecialchars(mb_substr($debate['title'], 0, 40)) . "...\"",
                "debate.php?id=$debate_id#arg-$new_arg_id"
            );
        }
    }

    $_SESSION['flash_success'] = $parent_id ? "Your reply has been posted!" : "Your argument has been published!";
    header("Location: debate.php?id=" . $debate_id . "#arg-" . $new_arg_id);
    exit;

} catch (PDOException $e) {
    $_SESSION['flash_error'] = "Error submitting argument: " . $e->getMessage();
    header("Location: debate.php?id=" . $debate_id);
    exit;
}
