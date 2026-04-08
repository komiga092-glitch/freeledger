<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/security.php';

set_security_headers();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($role, ['auditor', 'organization'])) {
    header("Location: ../dashboard.php");
    exit;
}

$user_id    = (int)($_SESSION['user_id'] ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);

if ($company_id <= 0) {
    header("Location: ../dashboard.php");
    exit;
}

$pageTitle = 'Audit Reports';
$pageDescription = 'Create professional audit reports with signature section';

$msg = '';
$msgType = 'success';
$edit_mode = false;

/* company */
$company = null;
$stmt = $conn->prepare("
    SELECT company_id, company_name, registration_no, email, phone, address
    FROM companies
    WHERE company_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
$company = $res->fetch_assoc();
$stmt->close();

if (!$company) {
    header("Location: ../dashboard.php");
    exit;
}

/* assignments */
$assignments = [];
$stmt = $conn->prepare("
    SELECT assignment_id, audit_title, period_from, period_to, status, priority
    FROM audit_assignments
    WHERE company_id = ?
    ORDER BY assignment_id DESC
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $assignments[] = $row;
}
$stmt->close();

/* edit defaults */
$edit = [
    'report_id' => '',
    'assignment_id' => '',
    'report_title' => '',
    'main_description' => '',
    'audit_findings' => '',
    'recommendations' => '',
    'final_conclusion' => '',
    'signature_name' => $_SESSION['full_name'] ?? '',
    'signature_designation' => 'Auditor',
    'signature_date' => date('Y-m-d'),
    'report_file' => ''
];

/* edit mode */
if (isset($_GET['edit'])) {
    $report_id = (int)($_GET['edit'] ?? 0);

    $stmt = $conn->prepare("
        SELECT *
        FROM audit_reports
        WHERE report_id = ?
          AND company_id = ?
          AND auditor_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("iii", $report_id, $company_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $edit['report_id'] = $row['report_id'];
        $edit['assignment_id'] = $row['assignment_id'];
        $edit['report_title'] = $row['report_title'];
        $edit['report_file'] = $row['report_file'];

        $desc = (string)($row['report_description'] ?? '');

        preg_match('/\[MAIN_DESCRIPTION\](.*?)\[\/MAIN_DESCRIPTION\]/s', $desc, $m1);
        preg_match('/\[AUDIT_FINDINGS\](.*?)\[\/AUDIT_FINDINGS\]/s', $desc, $m2);
        preg_match('/\[RECOMMENDATIONS\](.*?)\[\/RECOMMENDATIONS\]/s', $desc, $m3);
        preg_match('/\[FINAL_CONCLUSION\](.*?)\[\/FINAL_CONCLUSION\]/s', $desc, $m4);
        preg_match('/\[SIGNATURE_NAME\](.*?)\[\/SIGNATURE_NAME\]/s', $desc, $m5);
        preg_match('/\[SIGNATURE_DESIGNATION\](.*?)\[\/SIGNATURE_DESIGNATION\]/s', $desc, $m6);
        preg_match('/\[SIGNATURE_DATE\](.*?)\[\/SIGNATURE_DATE\]/s', $desc, $m7);

        $edit['main_description'] = trim($m1[1] ?? '');
        $edit['audit_findings'] = trim($m2[1] ?? '');
        $edit['recommendations'] = trim($m3[1] ?? '');
        $edit['final_conclusion'] = trim($m4[1] ?? '');
        $edit['signature_name'] = trim($m5[1] ?? ($_SESSION['full_name'] ?? ''));
        $edit['signature_designation'] = trim($m6[1] ?? 'Auditor');
        $edit['signature_date'] = trim($m7[1] ?? date('Y-m-d'));

        $edit_mode = true;
    }
    $stmt->close();
}

/* save report */
if (isset($_POST['save_report'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } else {
        $report_id              = (int)($_POST['report_id'] ?? 0);
        $assignment_id          = (int)($_POST['assignment_id'] ?? 0);
        $report_title           = trim($_POST['report_title'] ?? '');
        $main_description       = trim($_POST['main_description'] ?? '');
        $audit_findings         = trim($_POST['audit_findings'] ?? '');
        $recommendations        = trim($_POST['recommendations'] ?? '');
        $final_conclusion       = trim($_POST['final_conclusion'] ?? '');
        $signature_name         = trim($_POST['signature_name'] ?? '');
        $signature_designation  = trim($_POST['signature_designation'] ?? 'Auditor');
        $signature_date         = trim($_POST['signature_date'] ?? date('Y-m-d'));
        $existing_file          = trim($_POST['existing_file'] ?? '');
        $report_file            = $existing_file;

        if ($report_title === '' || $main_description === '' || $signature_name === '') {
            $msg = 'Please fill report title, executive summary, and signature name.';
            $msgType = 'danger';
        } else {
            if (!empty($_FILES['report_file']['name'])) {
                $uploadDir = __DIR__ . '/../uploads/audit_reports/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $tmpName = $_FILES['report_file']['tmp_name'];
                $originalName = basename($_FILES['report_file']['name']);
                $safeName = time() . '_' . preg_replace('/[^A-Za-z0-9_\.-]/', '_', $originalName);
                $targetPath = $uploadDir . $safeName;
                $dbPath = 'uploads/audit_reports/' . $safeName;

                if (move_uploaded_file($tmpName, $targetPath)) {
                    $report_file = $dbPath;
                } else {
                    $msg = 'File upload failed.';
                    $msgType = 'danger';
                }
            }

            if ($msg === '') {
                $full_report_description =
                    "[MAIN_DESCRIPTION]\n" . $main_description . "\n[/MAIN_DESCRIPTION]\n\n" .
                    "[AUDIT_FINDINGS]\n" . $audit_findings . "\n[/AUDIT_FINDINGS]\n\n" .
                    "[RECOMMENDATIONS]\n" . $recommendations . "\n[/RECOMMENDATIONS]\n\n" .
                    "[FINAL_CONCLUSION]\n" . $final_conclusion . "\n[/FINAL_CONCLUSION]\n\n" .
                    "[SIGNATURE_NAME]\n" . $signature_name . "\n[/SIGNATURE_NAME]\n\n" .
                    "[SIGNATURE_DESIGNATION]\n" . $signature_designation . "\n[/SIGNATURE_DESIGNATION]\n\n" .
                    "[SIGNATURE_DATE]\n" . $signature_date . "\n[/SIGNATURE_DATE]";

                if ($report_id > 0) {
                    $stmt = $conn->prepare("
                    UPDATE audit_reports
                    SET assignment_id = ?, report_title = ?, report_description = ?, report_file = ?
                    WHERE report_id = ?
                      AND company_id = ?
                      AND auditor_id = ?
                ");
                    $stmt->bind_param(
                        "isssiii",
                        $assignment_id,
                        $report_title,
                        $full_report_description,
                        $report_file,
                        $report_id,
                        $company_id,
                        $user_id
                    );

                    if ($stmt->execute()) {
                        $msg = 'Audit report updated successfully.';
                        $msgType = 'success';
                        $edit_mode = false;
                    } else {
                        $msg = 'Failed to update audit report.';
                        $msgType = 'danger';
                    }
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("
                    INSERT INTO audit_reports
                    (assignment_id, company_id, auditor_id, report_title, report_description, report_file)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                    $stmt->bind_param(
                        "iiisss",
                        $assignment_id,
                        $company_id,
                        $user_id,
                        $report_title,
                        $full_report_description,
                        $report_file
                    );

                    if ($stmt->execute()) {
                        $msg = 'Audit report saved successfully.';
                        $msgType = 'success';
                    } else {
                        $msg = 'Failed to save audit report.';
                        $msgType = 'danger';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

/* delete */
if (isset($_GET['delete'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request. Please try again.';
        $msgType = 'danger';
    } else {
        $report_id = (int)($_GET['delete'] ?? 0);

        $stmt = $conn->prepare("
        DELETE FROM audit_reports
        WHERE report_id = ?
          AND company_id = ?
          AND auditor_id = ?
    ");
        $stmt->bind_param("iii", $report_id, $company_id, $user_id);

        if ($stmt->execute()) {
            $msg = 'Audit report deleted successfully.';
            $msgType = 'success';
        } else {
            $msg = 'Delete failed.';
            $msgType = 'danger';
        }
        $stmt->close();
    }
}

/* list reports */
$rows = [];
$stmt = $conn->prepare("
    SELECT r.*, a.audit_title, a.status, a.priority, a.period_from, a.period_to
    FROM audit_reports r
    LEFT JOIN audit_assignments a ON a.assignment_id = r.assignment_id
    WHERE r.company_id = ?
      AND r.auditor_id = ?
    ORDER BY r.report_id DESC
");
$stmt->bind_param("ii", $company_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">Γÿ░</button>
            <div class="page-heading">
                <h1>Audit Reports</h1>
                <p><?= e($pageDescription) ?></p>
            </div>
        </div>

        <div class="topbar-right">
            <div class="company-pill"><?= e($_SESSION['company_name'] ?? 'Company') ?></div>
            <div class="role-pill"><?= e($_SESSION['role'] ?? 'Auditor') ?></div>
            <div class="user-chip">
                <div class="avatar"><?= e(strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1))) ?></div>
                <div class="meta">
                    <strong><?= e($_SESSION['full_name'] ?? 'Auditor') ?></strong>
                    <span><?= e($_SESSION['email'] ?? '') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <?php if ($msg !== ''): ?>
            <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3><?= $edit_mode ? 'Edit Audit Report' : 'Create Audit Report' ?></h3>
                <span class="badge badge-primary">Final Audit Document</span>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="report_id" value="<?= e($edit['report_id']) ?>">
                    <input type="hidden" name="existing_file" value="<?= e($edit['report_file']) ?>">

                    <div class="form-grid">


                        <div class="form-group">
                            <label class="form-label">Report Title</label>
                            <input type="text" name="report_title" class="form-control" value="<?= e($edit['report_title']) ?>" required>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Executive Summary / Main Description</label>
                            <textarea name="main_description" class="form-control" required><?= e($edit['main_description']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Audit Findings</label>
                            <textarea name="audit_findings" class="form-control" placeholder="Key findings, control weaknesses, exceptions..."><?= e($edit['audit_findings']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Recommendations</label>
                            <textarea name="recommendations" class="form-control" placeholder="Corrective actions and recommendations..."><?= e($edit['recommendations']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Final Conclusion</label>
                            <textarea name="final_conclusion" class="form-control" placeholder="Overall audit conclusion..."><?= e($edit['final_conclusion']) ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Signature Name</label>
                            <input type="text" name="signature_name" class="form-control" value="<?= e($edit['signature_name']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Designation</label>
                            <input type="text" name="signature_designation" class="form-control" value="<?= e($edit['signature_designation']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Signature Date</label>
                            <input type="date" name="signature_date" class="form-control" value="<?= e($edit['signature_date']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Attach Report File (optional)</label>
                            <input type="file" name="report_file" class="form-control">
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="save_report" class="btn btn-primary">
                                <?= $edit_mode ? 'Update Report' : 'Save Report' ?>
                            </button>
                            <?php if ($edit_mode): ?>
                                <a href="auditor/audit_reports.php" class="btn btn-light">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Saved Audit Reports</h3>
                <span class="badge badge-success"><?= count($rows) ?> Reports</span>
            </div>
            <div class="card-body">
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $desc = (string)($row['report_description'] ?? '');

                        preg_match('/\[MAIN_DESCRIPTION\](.*?)\[\/MAIN_DESCRIPTION\]/s', $desc, $m1);
                        preg_match('/\[AUDIT_FINDINGS\](.*?)\[\/AUDIT_FINDINGS\]/s', $desc, $m2);
                        preg_match('/\[RECOMMENDATIONS\](.*?)\[\/RECOMMENDATIONS\]/s', $desc, $m3);
                        preg_match('/\[FINAL_CONCLUSION\](.*?)\[\/FINAL_CONCLUSION\]/s', $desc, $m4);
                        preg_match('/\[SIGNATURE_NAME\](.*?)\[\/SIGNATURE_NAME\]/s', $desc, $m5);
                        preg_match('/\[SIGNATURE_DESIGNATION\](.*?)\[\/SIGNATURE_DESIGNATION\]/s', $desc, $m6);
                        preg_match('/\[SIGNATURE_DATE\](.*?)\[\/SIGNATURE_DATE\]/s', $desc, $m7);

                        $main_description = trim($m1[1] ?? '');
                        $audit_findings = trim($m2[1] ?? '');
                        $recommendations = trim($m3[1] ?? '');
                        $final_conclusion = trim($m4[1] ?? '');
                        $signature_name = trim($m5[1] ?? '');
                        $signature_designation = trim($m6[1] ?? '');
                        $signature_date = trim($m7[1] ?? '');
                        ?>
                        <div class="card" style="margin-bottom:20px;">
                            <div class="card-header">
                                <h3><?= e($row['report_title']) ?></h3>
                                <div class="flex items-center gap-12">
                                    <span class="badge badge-primary"><?= e($row['audit_title'] ?? 'General Report') ?></span>
                                    <span class="badge badge-warning"><?= e($row['priority'] ?? 'N/A') ?></span>
                                </div>
                            </div>
                            <div class="card-body">
                                <p><strong>Assignment Status:</strong> <?= e($row['status'] ?? 'N/A') ?></p>
                                <p><strong>Audit Period:</strong> <?= e($row['period_from'] ?? '-') ?> to <?= e($row['period_to'] ?? '-') ?></p>
                                <p><strong>Created At:</strong> <?= e($row['created_at']) ?></p>

                                <hr style="margin:18px 0; border:none; border-top:1px solid #e8d9df;">

                                <h4 style="color:var(--primary-dark); margin-bottom:8px;">Executive Summary</h4>
                                <p><?= nl2br(e($main_description)) ?></p>

                                <h4 style="color:var(--primary-dark); margin:18px 0 8px;">Audit Findings</h4>
                                <p><?= nl2br(e($audit_findings)) ?></p>

                                <h4 style="color:var(--primary-dark); margin:18px 0 8px;">Recommendations</h4>
                                <p><?= nl2br(e($recommendations)) ?></p>

                                <h4 style="color:var(--primary-dark); margin:18px 0 8px;">Final Conclusion</h4>
                                <p><?= nl2br(e($final_conclusion)) ?></p>

                                <div class="card mt-24" style="box-shadow:none;">
                                    <div class="card-header">
                                        <h3>Signature Section</h3>
                                        <span class="badge badge-success">Signed Block</span>
                                    </div>
                                    <div class="card-body">
                                        <div class="grid grid-2">
                                            <div>
                                                <p><strong>Company:</strong> <?= e($company['company_name'] ?? ($_SESSION['company_name'] ?? 'Company')) ?></p>
                                                <p><strong>Auditor Name:</strong> <?= e($signature_name) ?></p>
                                                <p><strong>Designation:</strong> <?= e($signature_designation) ?></p>
                                                <p><strong>Date:</strong> <?= e($signature_date) ?></p>
                                            </div>
                                            <div style="text-align:right;">
                                                <div style="height:60px;"></div>
                                                <p><strong>Signature:</strong> ____________________</p>
                                                <p class="text-muted">Auditor Sign / Seal</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($row['report_file'])): ?>
                                    <p class="mt-20">
                                        <strong>Attached File:</strong>
                                        <a href="../<?= e($row['report_file']) ?>" target="_blank" class="text-primary">Open Attachment</a>
                                    </p>
                                <?php endif; ?>

                                <div class="mt-20 flex gap-12" style="flex-wrap:wrap;">
                                    <a href="?edit=<?= (int)$row['report_id'] ?>" class="btn btn-light">Edit</a>
                                    <a href="?delete=<?= (int)$row['report_id'] ?>&csrf_token=<?= urlencode(get_csrf_token()) ?>" class="btn btn-danger" onclick="return confirm('Delete this audit report?')">Delete</a>
                                    <button type="button" onclick="window.print()" class="btn btn-primary">Print</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-warning">No audit reports found.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>