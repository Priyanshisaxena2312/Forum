<?php
/**
 * Create / Convert Discussion to Proposal
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['flash_error'] = "You must be logged in to create or convert a proposal.";
    header("Location: login.php");
    exit;
}

$topic_id = filter_var($_GET['topic_id'] ?? null, FILTER_VALIDATE_INT);
$debate_id = filter_var($_GET['debate_id'] ?? null, FILTER_VALIDATE_INT);

$prefill_title = '';
$prefill_problem = '';
$linked_discussion = null;
$discussion_type = 'topic';
$discussion_id = null;

// If converting from a topic
if ($topic_id) {
    $stmt = $pdo->prepare("
        SELECT t.*, c.name as category_name,
        (SELECT content FROM posts WHERE topic_id = t.id ORDER BY created_at ASC LIMIT 1) as first_post
        FROM topics t
        JOIN categories c ON t.category_id = c.id
        WHERE t.id = ? AND t.is_deleted = 0
    ");
    $stmt->execute([$topic_id]);
    $topic = $stmt->fetch();
    if ($topic) {
        $discussion_type = 'topic';
        $discussion_id = $topic['id'];
        $linked_discussion = [
            'type' => 'topic',
            'id' => $topic['id'],
            'title' => $topic['title'],
            'category' => $topic['category_name'],
            'url' => 'topic.php?id=' . $topic['id']
        ];
        $prefill_title = 'Action Plan: ' . $topic['title'];
        $prefill_problem = !empty($topic['first_post']) 
            ? "From discussion in " . $topic['category_name'] . ":\n\n" . trim(strip_tags($topic['first_post']))
            : "Based on community discussion in '" . $topic['title'] . "'.";
    }
} elseif ($debate_id) {
    // If converting from a debate
    $stmt = $pdo->prepare("
        SELECT d.*, c.name as category_name
        FROM debates d
        JOIN categories c ON d.category_id = c.id
        WHERE d.id = ? AND d.is_deleted = 0
    ");
    $stmt->execute([$debate_id]);
    $debate = $stmt->fetch();
    if ($debate) {
        $discussion_type = 'debate';
        $discussion_id = $debate['id'];
        $linked_discussion = [
            'type' => 'debate',
            'id' => $debate['id'],
            'title' => $debate['title'],
            'category' => $debate['category_name'],
            'url' => 'debate.php?id=' . $debate['id']
        ];
        $prefill_title = 'Resolution: ' . $debate['title'];
        $prefill_problem = "Identified through perspective debate in " . $debate['category_name'] . ":\n\n" . trim(strip_tags($debate['description']));
    }
}

// Fetch recent topics for optional discussion linking if none was pre-filled
$recent_topics = [];
if (!$linked_discussion) {
    $stmt = $pdo->query("SELECT id, title FROM topics WHERE is_deleted = 0 ORDER BY created_at DESC LIMIT 20");
    $recent_topics = $stmt->fetchAll();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $title = sanitize_input($_POST['title'] ?? '');
    $problem = trim($_POST['problem_description'] ?? '');
    $solution = trim($_POST['solution_description'] ?? '');
    $post_disc_id = filter_var($_POST['discussion_id'] ?? null, FILTER_VALIDATE_INT);
    $post_disc_type = in_array($_POST['discussion_type'] ?? '', ['topic', 'debate']) ? $_POST['discussion_type'] : 'topic';

    if (empty($title)) {
        $error = "Please provide a descriptive proposal title.";
    } elseif (empty($problem)) {
        $error = "Please describe the problem or challenge this proposal addresses.";
    } elseif (empty($solution)) {
        $error = "Please specify the suggested solution and actionable steps.";
    } else {
        // Generate unique slug
        $base_slug = generate_slug($title);
        $slug = $base_slug;
        $counter = 1;
        while (true) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM proposals WHERE slug = ?");
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn() == 0) break;
            $slug = $base_slug . '-' . $counter++;
        }

        // Insert Proposal
        $stmt = $pdo->prepare("
            INSERT INTO proposals 
                (user_id, title, slug, problem_description, solution_description, discussion_id, discussion_type, status, votes_count) 
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, 'open', 1)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $title,
            $slug,
            $problem,
            $solution,
            $post_disc_id ?: null,
            $post_disc_type
        ]);
        $new_proposal_id = $pdo->lastInsertId();

        // Creator automatically casts initial upvote
        $stmt = $pdo->prepare("INSERT INTO proposal_votes (proposal_id, user_id, vote_type) VALUES (?, ?, 'up')");
        $stmt->execute([$new_proposal_id, $_SESSION['user_id']]);

        // Record initial status update in timeline
        $initial_note = $linked_discussion 
            ? "Proposal created and converted from discussion: " . $linked_discussion['title'] 
            : "Proposal submitted to community for initial review and voting.";
        $stmt = $pdo->prepare("
            INSERT INTO proposal_updates (proposal_id, user_id, old_status, new_status, note) 
            VALUES (?, ?, NULL, 'open', ?)
        ");
        $stmt->execute([$new_proposal_id, $_SESSION['user_id'], $initial_note]);

        $_SESSION['flash_success'] = "Proposal created! The community can now review, vote, and advance it toward implementation.";
        header("Location: proposal.php?id=" . $new_proposal_id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <title><?= $linked_discussion ? 'Convert Discussion to Proposal' : 'Create New Proposal' ?> — Forum Home</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="page-wrapper">
        <div class="debate-breadcrumb" style="margin-bottom: 1.5rem;">
            <a href="proposals.php" class="back-link"><i class="fas fa-arrow-left"></i> Ideas to Action</a>
            <span style="color: var(--text-muted); margin: 0 0.5rem;">/</span>
            <span style="color: var(--text-secondary);"><?= $linked_discussion ? 'Convert Discussion' : 'New Proposal' ?></span>
        </div>

        <div class="card proposal-form-card" style="max-width: 860px; margin: 0 auto; padding: 2.25rem;">
            
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); padding-bottom: 1.25rem;">
                <div class="proposal-icon-badge" style="background: linear-gradient(135deg, #f59e0b, #ef4444); color: #fff; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                    <i class="fas fa-lightbulb"></i>
                </div>
                <div>
                    <h2 style="font-family: var(--font-display); font-size: 1.5rem; margin-bottom: 0.2rem;">
                        <?= $linked_discussion ? 'Convert Discussion into Action Proposal' : 'Create Community Action Proposal' ?>
                    </h2>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">
                        Turn campus discussions and problem reports into concrete, trackable solutions.
                    </p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="flash-message flash-error" style="margin-bottom: 1.5rem;">
                    <i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($linked_discussion): ?>
                <div class="supporting-discussion-preview-card" style="margin-bottom: 1.75rem; background: rgba(99, 102, 241, 0.08); border: 1px solid rgba(99, 102, 241, 0.25); border-radius: var(--radius-md); padding: 1rem 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                    <div>
                        <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #a5b4fc; letter-spacing: 0.05em; margin-bottom: 0.25rem;">
                            <i class="fas fa-link"></i> Supporting Community Discussion
                        </div>
                        <div style="font-weight: 600; color: #fff; font-size: 1rem;">
                            <?= htmlspecialchars($linked_discussion['title']) ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.2rem;">
                            In <?= htmlspecialchars($linked_discussion['category']) ?> • <a href="<?= htmlspecialchars($linked_discussion['url']) ?>" target="_blank" style="color: var(--primary);">View original thread <i class="fas fa-arrow-up-right-from-square" style="font-size: 0.75rem;"></i></a>
                        </div>
                    </div>
                    <span class="badge badge-primary"><i class="fas fa-check"></i> Linked</span>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="discussion_id" value="<?= htmlspecialchars($discussion_id ?? '') ?>">
                <input type="hidden" name="discussion_type" value="<?= htmlspecialchars($discussion_type) ?>">

                <!-- Proposal Title -->
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="title" style="font-weight: 600; display: block; margin-bottom: 0.5rem; color: #f1f1f5;">
                        Proposal Title <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="text" name="title" id="title" class="form-control" required
                           placeholder="e.g. Official Campus Esports & Gaming Arena Setup"
                           value="<?= htmlspecialchars($_POST['title'] ?? $prefill_title) ?>">
                    <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.3rem; display: block;">
                        A concise, action-oriented title summarizing what should be done.
                    </small>
                </div>

                <!-- Problem Description -->
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="problem_description" style="font-weight: 600; display: block; margin-bottom: 0.5rem; color: #f1f1f5;">
                        <i class="fas fa-triangle-exclamation" style="color: #f59e0b;"></i> Problem Description <span style="color: var(--danger);">*</span>
                    </label>
                    <textarea name="problem_description" id="problem_description" class="form-control" rows="5" required
                              placeholder="Describe the specific problem, student frustration, or unmet campus need..."><?= htmlspecialchars($_POST['problem_description'] ?? $prefill_problem) ?></textarea>
                    <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.3rem; display: block;">
                        Detail the problem that was raised in community discussions.
                    </small>
                </div>

                <!-- Suggested Solution -->
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="solution_description" style="font-weight: 600; display: block; margin-bottom: 0.5rem; color: #f1f1f5;">
                        <i class="fas fa-rocket" style="color: #10b981;"></i> Suggested Solution & Action Plan <span style="color: var(--danger);">*</span>
                    </label>
                    <textarea name="solution_description" id="solution_description" class="form-control" rows="6" required
                              placeholder="Detail the actionable solution, proposed steps, resources required, and how to execute it..."><?= htmlspecialchars($_POST['solution_description'] ?? '') ?></textarea>
                    <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.3rem; display: block;">
                        Propose clear, concrete steps for students, clubs, or administration to implement.
                    </small>
                </div>

                <!-- Optional discussion select if not prefilled -->
                <?php if (!$linked_discussion && !empty($recent_topics)): ?>
                    <div class="form-group" style="margin-bottom: 1.75rem;">
                        <label for="select_discussion" style="font-weight: 600; display: block; margin-bottom: 0.5rem; color: #f1f1f5;">
                            <i class="fas fa-comments"></i> Link Supporting Discussion (Optional)
                        </label>
                        <select name="discussion_id" id="select_discussion" class="form-control">
                            <option value="">-- No linked discussion / Standalone proposal --</option>
                            <?php foreach ($recent_topics as $rt): ?>
                                <option value="<?= $rt['id'] ?>"><?= htmlspecialchars($rt['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                    <a href="proposals.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.75rem; font-size: 1rem;">
                        <i class="fas fa-paper-plane"></i> Publish Proposal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="scripts.js"></script>
</body>
</html>
