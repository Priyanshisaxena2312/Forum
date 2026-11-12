<?php
require_once 'config.php';
require_once 'helpers.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['flash_error'] = "You must be logged in to create a new debate motion.";
    header("Location: login.php");
    exit;
}

$stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $stmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $title = trim($_POST['title'] ?? '');
    $category_id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
    $description = trim($_POST['description'] ?? '');
    $opening_side = trim(strtolower($_POST['opening_side'] ?? ''));
    $opening_arg = trim($_POST['opening_arg'] ?? '');

    if (empty($title) || mb_strlen($title) < 10) {
        $errors[] = "The debate motion title must be at least 10 characters.";
    } elseif (mb_strlen($title) > 255) {
        $errors[] = "The title cannot exceed 255 characters.";
    }

    if (!$category_id) {
        $errors[] = "Please select a valid category.";
    }

    if (empty($description) || mb_strlen($description) < 20) {
        $errors[] = "Please provide context or background for the motion (at least 20 characters).";
    }

    if (!empty($opening_arg) && !in_array($opening_side, ['for', 'against'])) {
        $errors[] = "If providing an opening argument, please choose whether it is For or Against.";
    }

    if (empty($errors)) {
        try {
            $base_slug = generate_slug($title);
            $slug = $base_slug;
            $counter = 1;

            while (true) {
                $check = $pdo->prepare("SELECT id FROM debates WHERE slug = ?");
                $check->execute([$slug]);
                if (!$check->fetch()) {
                    break;
                }
                $slug = $base_slug . '-' . $counter;
                $counter++;
            }

            $stmt = $pdo->prepare("
                INSERT INTO debates (user_id, category_id, title, slug, description)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$_SESSION['user_id'], $category_id, $title, $slug, $description]);
            $debate_id = $pdo->lastInsertId();

            // If opening argument provided, insert it
            if (!empty($opening_arg) && in_array($opening_side, ['for', 'against'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO debate_arguments (debate_id, user_id, side, content)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$debate_id, $_SESSION['user_id'], $opening_side, $opening_arg]);

                // Also auto-vote in poll
                $stmt = $pdo->prepare("
                    INSERT INTO debate_polls (debate_id, user_id, vote)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$debate_id, $_SESSION['user_id'], $opening_side]);
            }

            // Reward reputation
            $stmt = $pdo->prepare("UPDATE users SET reputation = reputation + 5 WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);

            $_SESSION['flash_success'] = "Perspective Debate created successfully!";
            header("Location: debate.php?id=" . $debate_id);
            exit;

        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Propose a Perspective Debate — Forum Home</title>
    <meta name="description" content="Launch a structured debate with For and Against perspectives on Forum Home.">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="page-wrapper" style="max-width: 900px; margin: 2rem auto; padding: 0 1.25rem;">
        <div style="margin-bottom: 1.5rem;">
            <a href="debates.php" style="color: var(--text-muted); text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                <i class="fas fa-arrow-left"></i> Back to Debates
            </a>
        </div>

        <div class="debate-form-card">
            <div class="debate-form-header">
                <div class="debate-badge" style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.35rem 0.75rem; border-radius:20px; background:rgba(99,102,241,0.15); color:var(--primary); font-size:0.8rem; font-weight:700; margin-bottom:0.75rem; border:1px solid rgba(99,102,241,0.3);">
                    <i class="fas fa-scale-balanced"></i> PERSPECTIVE DEBATE
                </div>
                <h1 style="font-size: 1.85rem; font-weight: 800; margin-bottom: 0.5rem;">Propose a Motion</h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Pose a clear topic or controversial question with opposing perspectives. The community can vote their stance and submit structured arguments under For or Against.
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="flash-message flash-error" style="margin-top: 1.25rem;">
                    <ul style="margin: 0; padding-left: 1.2rem; text-align: left;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form action="new_debate.php" method="POST" style="margin-top: 1.5rem;">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label for="title">Debate Motion / Question <span style="color:var(--accent-rose);">*</span></label>
                    <input type="text" id="title" name="title" class="form-control" 
                           placeholder="e.g. Is remote work genuinely superior for software engineering productivity?"
                           value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                    <small style="color: var(--text-muted); display: block; margin-top: 0.35rem;">
                        State the motion neutrally or clearly so both sides have room to articulate points.
                    </small>
                </div>

                <div class="form-group">
                    <label for="category_id">Category <span style="color:var(--accent-rose);">*</span></label>
                    <select id="category_id" name="category_id" class="form-control" required>
                        <option value="">-- Select a Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= (isset($_POST['category_id']) && $_POST['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="description">Background & Context <span style="color:var(--accent-rose);">*</span></label>
                    <textarea id="description" name="description" class="form-control" rows="4" 
                              placeholder="Provide the background context, key definitions, or framing for this discussion..." required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    <small style="color: var(--text-muted); display: block; margin-top: 0.35rem;">Explain the premises and what makes this debate timely or important.</small>
                </div>

                <!-- Optional Opening Argument -->
                <div style="background: rgba(255,255,255,0.02); border: 1px dashed var(--border); border-radius: var(--radius-md); padding: 1.25rem; margin-top: 1.5rem;">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.75rem;">
                        <span style="font-weight: 700; font-size: 0.95rem; color: var(--text);">Opening Argument <span style="color:var(--text-muted); font-weight:normal; font-size:0.8rem;">(Optional)</span></span>
                    </div>
                    
                    <div class="form-group">
                        <label>Which side will you argue?</label>
                        <div style="display:flex; gap: 1rem; margin-top: 0.35rem;">
                            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:normal;">
                                <input type="radio" name="opening_side" value="for" <?= (isset($_POST['opening_side']) && $_POST['opening_side'] === 'for') ? 'checked' : '' ?>>
                                <span style="color:var(--accent-emerald); font-weight:600;"><i class="fas fa-thumbs-up"></i> FOR (Supporting)</span>
                            </label>
                            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:normal;">
                                <input type="radio" name="opening_side" value="against" <?= (isset($_POST['opening_side']) && $_POST['opening_side'] === 'against') ? 'checked' : '' ?>>
                                <span style="color:var(--accent-rose); font-weight:600;"><i class="fas fa-thumbs-down"></i> AGAINST (Opposing)</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label for="opening_arg">Your Opening Argument</label>
                        <textarea id="opening_arg" name="opening_arg" class="form-control" rows="3" 
                                  placeholder="Lay out your core supporting or opposing points..."><?= htmlspecialchars($_POST['opening_arg'] ?? '') ?></textarea>
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.75rem;">
                    <a href="debates.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.75rem;">
                        <i class="fas fa-paper-plane" style="margin-right: 0.4rem;"></i> Launch Debate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="scripts.js"></script>
</body>
</html>
