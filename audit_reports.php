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

if ($role === 'auditor') {
    $sql = "
        SELECT afr.*, au.full_name AS auditor_name, c.company_name
        FROM audit_final_reports afr
        JOIN app_users au ON au.user_id = afr.auditor_user_id
        JOIN companies c ON c.company_id = afr.company_id
        WHERE afr.company_id = ?
          AND afr.auditor_user_id = ?
        ORDER BY afr.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $company_id, $user_id);
} else {
    $sql = "
        SELECT afr.*, au.full_name AS auditor_name, c.company_name
        FROM audit_final_reports afr
        JOIN app_users au ON au.user_id = afr.auditor_user_id
        JOIN companies c ON c.company_id = afr.company_id
        WHERE afr.company_id = ?
        ORDER BY afr.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $company_id);
}

$stmt->execute();
$reports = $stmt->get_result();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="content-wrapper">

        <div class="page-header">
            <h1>Audit Reports</h1>
            <p>View submitted audit reports and approval status.</p>
        </div>

        <?php if ($role === 'auditor'): ?>
        <div class="mb-16">
            <a href="audit_report_create.php" class="btn">+ Submit New Audit Report</a>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>Audit Report List</h3>
                <span class="badge badge-primary"><?= (int)$reports->num_rows ?> Reports</span>
            </div>

            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Company</th>
                                <th>Auditor</th>
                                <th>Period</th>
                                <th>Opinion</th>
                                <th>Status</th>
                                <th>PDF</th>
                                <th>Date</th>
                                <th>Review Status</th>
                                <th>Review Comment</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($reports->num_rows > 0): ?>
                            <?php while ($row = $reports->fetch_assoc()): ?>
                            <?php
                                    $reviewStatus = (string)($row['review_status'] ?? 'Pending');

                                    $badgeClass = 'badge-primary';
                                    if ($reviewStatus === 'Approved') {
                                        $badgeClass = 'badge-success';
                                    } elseif ($reviewStatus === 'Rejected') {
                                        $badgeClass = 'badge-danger';
                                    } elseif ($reviewStatus === 'Changes Requested') {
                                        $badgeClass = 'badge-warning';
                                    }
                                    ?>

                            <tr>
                                <td><?= e((string)$row['report_title']) ?></td>
                                <td><?= e((string)$row['company_name']) ?></td>
                                <td><?= e((string)$row['auditor_name']) ?></td>

                                <td>
                                    <?= e((string)$row['audit_period_from']) ?>
                                    to
                                    <?= e((string)$row['audit_period_to']) ?>
                                </td>

                                <td><?= e((string)$row['opinion_type']) ?></td>
                                <td><?= e((string)$row['status']) ?></td>

                                <td>
                                    <?php if (!empty($row['report_file'])): ?>
                                    <a href="<?= e((string)$row['report_file']) ?>" target="_blank">View PDF</a>
                                    <?php else: ?>
                                    No file
                                    <?php endif; ?>
                                </td>

                                <td><?= e((string)$row['created_at']) ?></td>

                                <td>
                                    <span class="badge <?= e($badgeClass) ?>">
                                        <?= e($reviewStatus) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= !empty($row['review_comment'])
                                                ? nl2br(e((string)$row['review_comment']))
                                                : '-'
                                            ?>
                                </td>

                                <td>
                                    <?php if ($role === 'organization' || $role === 'accountant'): ?>
                                    <a href="audit_report_review.php?report_id=<?= (int)$row['report_id'] ?>"
                                        class="btn">
                                        Review
                                    </a>
                                    <?php else: ?>
                                    View Only
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="11">No audit reports found.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
$stmt->close();
include 'includes/footer.php';
?>