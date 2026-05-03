<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

set_security_headers();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);
$role = normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));

if ($company_id <= 0) {
    header('Location: select_company.php');
    exit;
}

if (!in_array($role, ['organization', 'accountant'], true)) {
    die('Access denied.');
}

$auditor_id = (int)($_GET['auditor_id'] ?? $_POST['auditor_id'] ?? 0);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = (int)($_POST['rating'] ?? 0);
    $feedback = trim((string)($_POST['feedback'] ?? ''));

    if ($auditor_id <= 0) {
        $error = 'Invalid auditor.';
    } elseif ($rating < 1 || $rating > 5) {
        $error = 'Rating must be between 1 and 5.';
    } elseif ($feedback === '') {
        $error = 'Feedback cannot be empty.';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO auditor_feedback
                (company_id, auditor_user_id, reviewer_user_id, rating, feedback)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iiiis', $company_id, $auditor_id, $user_id, $rating, $feedback);
        $stmt->execute();
        $stmt->close();

        $message = 'Feedback submitted successfully.';
    }
}

$auditorsStmt = $conn->prepare("
    SELECT au.user_id, au.full_name, au.email
    FROM company_user_access cua
    INNER JOIN app_users au ON au.user_id = cua.user_id
    WHERE cua.company_id = ?
      AND cua.role_in_company = 'auditor'
      AND cua.access_status = 'Active'
    ORDER BY au.full_name ASC
");
$auditorsStmt->bind_param('i', $company_id);
$auditorsStmt->execute();
$auditors = $auditorsStmt->get_result();

$feedbackStmt = $conn->prepare("
    SELECT 
        af.rating,
        af.feedback,
        af.created_at,
        au.full_name AS auditor_name,
        ru.full_name AS reviewer_name
    FROM auditor_feedback af
    INNER JOIN app_users au ON au.user_id = af.auditor_user_id
    INNER JOIN app_users ru ON ru.user_id = af.reviewer_user_id
    WHERE af.company_id = ?
    ORDER BY af.created_at DESC
");
$feedbackStmt->bind_param('i', $company_id);
$feedbackStmt->execute();
$feedbackList = $feedbackStmt->get_result();

$pageTitle = 'Auditor Feedback';
$pageDescription = 'Rate and review assigned auditors';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="content-wrapper">

        <div class="page-header">
            <h1>Auditor Feedback</h1>
            <p>Submit feedback for assigned auditors.</p>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>Give Feedback</h3>
            </div>

            <div class="card-body">
                <form method="POST" class="form-grid">
                    <div class="form-group full">
                        <label class="form-label">Auditor</label>
                        <select name="auditor_id" class="form-control" required>
                            <option value="">Select auditor</option>
                            <?php while ($a = $auditors->fetch_assoc()): ?>
                            <option value="<?= (int)$a['user_id'] ?>"
                                <?= $auditor_id === (int)$a['user_id'] ? 'selected' : '' ?>>
                                <?= e($a['full_name']) ?> - <?= e($a['email']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Rating</label>
                        <select name="rating" class="form-control" required>
                            <option value="">Select rating</option>
                            <option value="5">5 - Excellent</option>
                            <option value="4">4 - Good</option>
                            <option value="3">3 - Average</option>
                            <option value="2">2 - Poor</option>
                            <option value="1">1 - Very Poor</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Feedback</label>
                        <textarea name="feedback" class="form-control" rows="5" required></textarea>
                    </div>

                    <div class="form-group full">
                        <button type="submit" class="btn">Submit Feedback</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Feedback History</h3>
            </div>

            <div class="card-body">
                <?php if ($feedbackList->num_rows > 0): ?>
                <?php while ($f = $feedbackList->fetch_assoc()): ?>
                <div class="message-item">
                    <strong><?= e($f['auditor_name']) ?></strong>
                    <p><strong>Rating:</strong> <?= (int)$f['rating'] ?>/5</p>
                    <p><?= nl2br(e($f['feedback'])) ?></p>
                    <small>By <?= e($f['reviewer_name']) ?> | <?= e($f['created_at']) ?></small>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <p>No feedback found.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php
$auditorsStmt->close();
$feedbackStmt->close();
include 'includes/footer.php';
?>