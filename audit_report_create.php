<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['company_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = (int) $_SESSION['user_id'];
$company_id = (int) $_SESSION['company_id'];
$role       = strtolower(trim((string) $_SESSION['role']));

if ($role !== 'auditor') {
    die("Access denied. Only auditors can submit audit reports.");
}

$message = "";
$error   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_title      = trim((string)($_POST['report_title'] ?? ''));
    $audit_period_from = trim((string)($_POST['audit_period_from'] ?? ''));
    $audit_period_to   = trim((string)($_POST['audit_period_to'] ?? ''));
    $opinion_type      = trim((string)($_POST['opinion_type'] ?? ''));
    $summary           = trim((string)($_POST['summary'] ?? ''));
    $recommendations   = trim((string)($_POST['recommendations'] ?? ''));

    $allowedOpinions = ['Clean', 'Qualified', 'Adverse', 'Disclaimer'];

    if ($report_title === '' || $summary === '') {
        $error = "Report title and summary are required.";
    } elseif ($audit_period_from === '' || $audit_period_to === '') {
        $error = "Audit period is required.";
    } elseif ($audit_period_from > $audit_period_to) {
        $error = "Invalid audit period.";
    } elseif (!in_array($opinion_type, $allowedOpinions, true)) {
        $error = "Invalid audit opinion selected.";
    } else {
        $report_file = null;

        if (!empty($_FILES['report_file']['name'])) {
            $maxSize = 5 * 1024 * 1024;

            if ($_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
                $error = "File upload error.";
            } elseif ($_FILES['report_file']['size'] > $maxSize) {
                $error = "PDF file must be less than 5MB.";
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['report_file']['tmp_name']);
                finfo_close($finfo);

                if ($mime !== 'application/pdf') {
                    $error = "Only valid PDF files are allowed.";
                } else {
                    $uploadDir = __DIR__ . "/uploads/audit_reports/";

                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $originalName = basename($_FILES['report_file']['name']);
                    $safeName = time() . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "_", $originalName);
                    $targetPath = $uploadDir . $safeName;

                    if (!move_uploaded_file($_FILES['report_file']['tmp_name'], $targetPath)) {
                        $error = "File upload failed.";
                    } else {
                        $report_file = "uploads/audit_reports/" . $safeName;
                    }
                }
            }
        }

        if ($error === '') {
            $sql = "
                INSERT INTO audit_final_reports
                (
                    company_id,
                    auditor_user_id,
                    report_title,
                    audit_period_from,
                    audit_period_to,
                    opinion_type,
                    summary,
                    recommendations,
                    report_file
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                $error = "Database prepare failed: " . $conn->error;
            } else {
                $stmt->bind_param(
                    "iisssssss",
                    $company_id,
                    $user_id,
                    $report_title,
                    $audit_period_from,
                    $audit_period_to,
                    $opinion_type,
                    $summary,
                    $recommendations,
                    $report_file
                );

                if ($stmt->execute()) {
                    $message = "Audit report submitted successfully.";
                } else {
                    $error = "Database insert failed: " . $stmt->error;
                }

                $stmt->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Submit Final Audit Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
    :root {
        --primary: #7a1f3d;
        --primary-dark: #5b0f1a;
        --primary-light: #a63a5b;
        --bg: #f8f4f6;
        --card: #ffffff;
        --text: #2b1b22;
        --muted: #6d5861;
        --border: #e8d9df;
        --success-bg: #e8f7ee;
        --success-text: #1f7a3f;
        --error-bg: #fdeaea;
        --error-text: #b42318;
        --shadow: 0 15px 40px rgba(122, 31, 61, 0.12);
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: "Segoe UI", Arial, sans-serif;
        background: linear-gradient(135deg, #f8f4f6, #ffffff);
        color: var(--text);
        min-height: 100vh;
        padding: 30px 15px;
    }

    .page-wrapper {
        max-width: 980px;
        margin: 0 auto;
    }

    .page-header {
        margin-bottom: 24px;
    }

    .breadcrumb {
        color: var(--muted);
        font-size: 14px;
        margin-bottom: 8px;
    }

    .page-title {
        font-size: 30px;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 8px;
    }

    .page-subtitle {
        color: var(--muted);
        font-size: 15px;
        line-height: 1.6;
    }

    .card {
        background: var(--card);
        border-radius: 18px;
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        overflow: hidden;
    }

    .card-header {
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        padding: 22px 28px;
    }

    .card-header h2 {
        font-size: 22px;
        margin-bottom: 5px;
    }

    .card-header p {
        font-size: 14px;
        opacity: 0.9;
    }

    .card-body {
        padding: 28px;
    }

    .alert {
        padding: 14px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-weight: 500;
        font-size: 14px;
    }

    .alert-success {
        background: var(--success-bg);
        color: var(--success-text);
        border: 1px solid #b7ebc6;
    }

    .alert-error {
        background: var(--error-bg);
        color: var(--error-text);
        border: 1px solid #fac5c5;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 18px;
    }

    .form-group {
        margin-bottom: 18px;
    }

    .form-group.full {
        grid-column: 1 / -1;
    }

    label {
        display: block;
        font-weight: 600;
        margin-bottom: 7px;
        font-size: 14px;
        color: var(--text);
    }

    .required {
        color: #d92d20;
    }

    input,
    select,
    textarea {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 14px;
        font-family: inherit;
        background: white;
        color: var(--text);
        outline: none;
        transition: 0.2s ease;
    }

    input:focus,
    select:focus,
    textarea:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 4px rgba(122, 31, 61, 0.08);
    }

    textarea {
        resize: vertical;
        min-height: 120px;
    }

    .file-box {
        border: 2px dashed var(--border);
        border-radius: 14px;
        padding: 22px;
        text-align: center;
        background: #fffafb;
        transition: 0.2s ease;
    }

    .file-box:hover {
        border-color: var(--primary);
        background: #fff4f7;
    }

    .file-box input {
        border: none;
        padding: 0;
        margin-top: 10px;
        box-shadow: none;
    }

    .file-note {
        font-size: 13px;
        color: var(--muted);
        margin-top: 8px;
    }

    .actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        margin-top: 8px;
        border-top: 1px solid var(--border);
        padding-top: 22px;
    }

    .btn {
        border: none;
        border-radius: 10px;
        padding: 12px 20px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: 0.2s ease;
    }

    .btn-submit {
        background: var(--primary);
        color: white;
    }

    .btn-submit:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
    }

    .btn-reset {
        background: #f3e8ed;
        color: var(--primary-dark);
    }

    .btn-reset:hover {
        background: #ead3dc;
    }

    @media (max-width: 768px) {
        body {
            padding: 18px 10px;
        }

        .page-title {
            font-size: 24px;
        }

        .card-body {
            padding: 20px;
        }

        .form-grid {
            grid-template-columns: 1fr;
        }

        .actions {
            flex-direction: column-reverse;
        }

        .btn {
            width: 100%;
        }
    }
    </style>
</head>

<body>

    <div class="page-wrapper">



        <div class="card">
            <div class="card-header">
                <h2>Final Audit Report Form</h2>
                <p>Complete all required details before submitting the report.</p>
            </div>

            <div class="card-body">

                <?php if ($message !== ''): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($message) ?>
                </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="auditReportForm">

                    <div class="form-grid">

                        <div class="form-group full">
                            <label>Report Title <span class="required">*</span></label>
                            <input type="text" name="report_title"
                                placeholder="Example: Final Audit Report - Financial Year 2025" required>
                        </div>

                        <div class="form-group">
                            <label>Audit Period From <span class="required">*</span></label>
                            <input type="date" name="audit_period_from" required>
                        </div>

                        <div class="form-group">
                            <label>Audit Period To <span class="required">*</span></label>
                            <input type="date" name="audit_period_to" required>
                        </div>

                        <div class="form-group full">
                            <label>Audit Opinion <span class="required">*</span></label>
                            <select name="opinion_type" required>
                                <option value="">Select audit opinion</option>
                                <option value="Clean">Clean Opinion</option>
                                <option value="Qualified">Qualified Opinion</option>
                                <option value="Adverse">Adverse Opinion</option>
                                <option value="Disclaimer">Disclaimer Opinion</option>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label>Audit Summary <span class="required">*</span></label>
                            <textarea name="summary" rows="6"
                                placeholder="Enter the main audit findings and conclusion..." required></textarea>
                        </div>

                        <div class="form-group full">
                            <label>Recommendations</label>
                            <textarea name="recommendations" rows="5"
                                placeholder="Enter recommendations for improvement..."></textarea>
                        </div>

                        <div class="form-group full">
                            <label>Upload Signed PDF Report</label>

                            <div class="file-box">
                                <strong>Choose signed PDF report</strong>
                                <input type="file" name="report_file" accept="application/pdf">
                                <div class="file-note">
                                    Only PDF files are allowed. Maximum file size: 5MB.
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="actions">
                        <a href="dashboard.php" class="btn btn-reset">← Back</a>
                        <button type="reset" class="btn btn-reset">Clear Form</button>
                        <button type="submit" class="btn btn-submit" id="submitBtn">
                            Submit Report
                        </button>
                    </div>

                </form>

            </div>
        </div>

    </div>

    <script>
    const form = document.getElementById("auditReportForm");
    const submitBtn = document.getElementById("submitBtn");

    form.addEventListener("submit", function() {
        submitBtn.disabled = true;
        submitBtn.innerText = "Submitting...";
    });
    </script>

</body>

</html>