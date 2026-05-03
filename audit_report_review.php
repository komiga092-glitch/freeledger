<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

set_security_headers();

if (!isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['company_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$company_id = (int)$_SESSION['company_id'];
$role = normalize_role_value((string)$_SESSION['role']);

if (!in_array($role, ['organization', 'accountant'], true)) {
    die("Access denied. Only organization/accountant can review audit reports.");
}

$report_id = (int)($_GET['report_id'] ?? $_POST['report_id'] ?? 0);

if ($report_id <= 0) {
    die("Invalid report ID.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $review_status = (string)($_POST['review_status'] ?? '');
    $review_comment = trim((string)($_POST['review_comment'] ?? ''));

    $allowedStatuses = ['Approved', 'Rejected', 'Changes Requested'];

    if (!in_array($review_status, $allowedStatuses, true)) {
        die("Invalid review status.");
    }

    if ($review_status !== 'Approved' && $review_comment === '') {
        die("Review comment is required for rejected or changes requested reports.");
    }

    $sql = "
        UPDATE audit_final_reports
        SET review_status = ?,
            review_comment = ?,
            reviewed_by = ?,
            reviewed_at = NOW()
        WHERE report_id = ?
          AND company_id = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssiii",
        $review_status,
        $review_comment,
        $user_id,
        $report_id,
        $company_id
    );
    $stmt->execute();
    $stmt->close();

    header("Location: audit_reports.php");
    exit;
}

$sql = "
    SELECT 
        afr.*,
        au.full_name AS auditor_name,
        c.company_name,
        reviewer.full_name AS reviewer_name
    FROM audit_final_reports afr
    JOIN app_users au ON au.user_id = afr.auditor_user_id
    JOIN companies c ON c.company_id = afr.company_id
    LEFT JOIN app_users reviewer ON reviewer.user_id = afr.reviewed_by
    WHERE afr.report_id = ?
      AND afr.company_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $report_id, $company_id);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$report) {
    die("Report not found.");
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="content-wrapper">

        <div class="page-header">
            <h1>Review Audit Report</h1>
            <p>Approve, reject, or request changes.</p>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><?= e((string)$report['report_title']) ?></h3>
                <span class="badge badge-primary">
                    <?= e((string)($report['review_status'] ?? 'Pending')) ?>
                </span>
            </div>

            <div class="card-body">
                <p><strong>Company:</strong> <?= e((string)$report['company_name']) ?></p>
                <p><strong>Auditor:</strong> <?= e((string)$report['auditor_name']) ?></p>

                <p>
                    <strong>Period:</strong>
                    <?= e((string)$report['audit_period_from']) ?>
                    to
                    <?= e((string)$report['audit_period_to']) ?>
                </p>

                <p><strong>Opinion:</strong> <?= e((string)$report['opinion_type']) ?></p>
                <p><strong>Submitted At:</strong> <?= e((string)$report['created_at']) ?></p>

                <?php if (!empty($report['reviewed_at'])): ?>
                <p><strong>Reviewed By:</strong> <?= e((string)($report['reviewer_name'] ?? '-')) ?></p>
                <p><strong>Reviewed At:</strong> <?= e((string)$report['reviewed_at']) ?></p>
                <?php endif; ?>

                <hr>

                <p><strong>Summary:</strong></p>
                <p><?= nl2br(e((string)$report['summary'])) ?></p>

                <p><strong>Recommendations:</strong></p>
                <p>
                    <?= !empty($report['recommendations'])
                        ? nl2br(e((string)$report['recommendations']))
                        : '-'
                    ?>
                </p>

                <?php if (!empty($report['report_file'])): ?>
                <p>
                    <a href="<?= e((string)$report['report_file']) ?>" target="_blank" class="btn">
                        View Uploaded PDF
                    </a>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Review Decision</h3>
            </div>

            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="report_id" value="<?= (int)$report_id ?>">

                    <label>Review Status</label><br>
                    <select name="review_status" required>
                        <option value="">Select Status</option>
                        <option value="Approved">Approve</option>
                        <option value="Rejected">Reject</option>
                        <option value="Changes Requested">Request Changes</option>
                    </select>

                    <br><br>

                    <label>Review Comment</label><br>
                    <textarea name="review_comment" rows="5"
                        placeholder="Write review comment..."><?= e((string)($report['review_comment'] ?? '')) ?></textarea>

                    <br><br>

                    <button type="submit" class="btn">Submit Review</button>
                    <a href="audit_reports.php" class="btn">Back</a>
                </form>
            </div>
        </div>

    </div>
</div>

<?php include 'includes/footer.php'; ?>