<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nic = trim($_POST['nic'] ?? '');
    $degree = trim($_POST['degree'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $experience_years = (int)($_POST['experience_years'] ?? 0);
    $bio = trim($_POST['bio'] ?? '');

    if ($nic === '' || $qualification === '') {
        $error = "NIC and qualification are required.";
    } elseif ($experience_years < 0) {
        $error = "Experience years cannot be negative.";
    } else {
        $photo_path = null;

        if (!empty($_FILES['photo']['name'])) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            $maxSize = 5 * 1024 * 1024;

            if (!in_array($_FILES['photo']['type'], $allowedTypes, true)) {
                $error = "Only JPG, PNG, or WEBP images are allowed.";
            } elseif ($_FILES['photo']['size'] > $maxSize) {
                $error = "Photo must be less than 5MB.";
            } else {
                $dir = "uploads/auditor_profiles/";

                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }

                $extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $safeName = "auditor_" . $user_id . "_" . time() . "." . strtolower($extension);
                $photo_path = $dir . $safeName;

                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $photo_path)) {
                    $error = "Photo upload failed.";
                }
            }
        }

        if ($error === '') {
            if ($photo_path !== null) {
                $sql = "
                    UPDATE auditor_profiles
                    SET nic = ?, degree = ?, qualification = ?, experience_years = ?, bio = ?, photo_path = ?
                    WHERE user_id = ?
                ";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "sssissi",
                    $nic,
                    $degree,
                    $qualification,
                    $experience_years,
                    $bio,
                    $photo_path,
                    $user_id
                );
            } else {
                $sql = "
                    UPDATE auditor_profiles
                    SET nic = ?, degree = ?, qualification = ?, experience_years = ?, bio = ?
                    WHERE user_id = ?
                ";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "sssisi",
                    $nic,
                    $degree,
                    $qualification,
                    $experience_years,
                    $bio,
                    $user_id
                );
            }

            $stmt->execute();
            $stmt->close();

            $success = "Profile updated successfully.";
        }
    }
}

$stmt = $conn->prepare("
    SELECT 
        ap.*,
        au.full_name,
        au.email
    FROM auditor_profiles ap
    JOIN app_users au ON au.user_id = ap.user_id
    WHERE ap.user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$auditor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$auditor) {
    die("Auditor profile not found.");
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Auditor Profile | FreeLedger</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        font-family: Arial, sans-serif;
    }

    body {
        min-height: 100vh;
        background: #f8f4f5;
        color: #222;
    }

    .page {
        min-height: 100vh;
        display: grid;
        grid-template-columns: 360px 1fr;
    }

    .profile-side {
        background: linear-gradient(160deg, #4a0b14, #7b1525);
        color: #fff;
        padding: 45px 30px;
    }

    .profile-side h1 {
        font-size: 30px;
        margin-bottom: 12px;
    }

    .profile-side p {
        color: #f5d7dd;
        line-height: 1.7;
        margin-bottom: 24px;
    }

    .profile-card {
        background: rgba(255, 255, 255, 0.1);
        padding: 22px;
        border-radius: 18px;
        text-align: center;
    }

    .profile-photo {
        width: 130px;
        height: 130px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid rgba(255, 255, 255, 0.8);
        margin-bottom: 15px;
        background: #fff;
    }

    .profile-placeholder {
        width: 130px;
        height: 130px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.18);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 44px;
        margin: 0 auto 15px;
        border: 4px solid rgba(255, 255, 255, 0.5);
    }

    .profile-card h2 {
        font-size: 22px;
        margin-bottom: 6px;
    }

    .profile-card span {
        color: #f4d8dd;
        font-size: 14px;
        display: block;
        word-break: break-word;
    }

    .content {
        padding: 45px;
    }

    .topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 28px;
        gap: 15px;
    }

    .topbar h2 {
        color: #5b0f1a;
        font-size: 32px;
    }

    .topbar a {
        text-decoration: none;
        color: #7b1525;
        font-weight: bold;
    }

    .form-card {
        background: #fff;
        border-radius: 22px;
        padding: 35px;
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);
        border-top: 5px solid #7b1525;
    }

    .grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
    }

    .form-group {
        margin-bottom: 18px;
    }

    .full {
        grid-column: 1 / -1;
    }

    label {
        display: block;
        font-weight: bold;
        color: #333;
        margin-bottom: 8px;
        font-size: 14px;
    }

    input,
    textarea {
        width: 100%;
        padding: 13px 14px;
        border: 1px solid #ddd;
        border-radius: 11px;
        font-size: 15px;
        outline: none;
    }

    textarea {
        min-height: 130px;
        resize: vertical;
    }

    input:focus,
    textarea:focus {
        border-color: #7b1525;
        box-shadow: 0 0 0 3px rgba(123, 21, 37, 0.12);
    }

    .readonly-box {
        background: #f8f4f5;
        padding: 13px 14px;
        border-radius: 11px;
        border: 1px solid #ead6da;
        color: #555;
    }

    .btn-row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    .btn {
        border: none;
        background: #7b1525;
        color: #fff;
        padding: 13px 22px;
        border-radius: 11px;
        font-size: 15px;
        font-weight: bold;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .btn:hover {
        background: #5b0f1a;
    }

    .btn-light {
        background: #eee;
        color: #333;
    }

    .btn-light:hover {
        background: #ddd;
    }

    .alert {
        padding: 13px 15px;
        border-radius: 11px;
        margin-bottom: 18px;
        font-size: 14px;
    }

    .alert-error {
        background: #fde8e8;
        color: #9b1c1c;
        border: 1px solid #f5b5b5;
    }

    .alert-success {
        background: #e9f8ef;
        color: #176b35;
        border: 1px solid #a8e5bd;
    }

    .hint {
        font-size: 13px;
        color: #777;
        margin-top: 6px;
    }

    @media (max-width: 900px) {
        .page {
            grid-template-columns: 1fr;
        }

        .content {
            padding: 28px;
        }

        .grid {
            grid-template-columns: 1fr;
        }

        .topbar {
            align-items: flex-start;
            flex-direction: column;
        }
    }
    </style>
</head>

<body>

    <div class="page">

        <aside class="profile-side">
            <h1>Auditor Profile</h1>
            <p>
                Keep your professional auditor details updated. Companies can review
                your qualification, experience, and profile before assigning audit work.
            </p>

            <div class="profile-card">
                <?php if (!empty($auditor['photo_path'])): ?>
                <img src="<?= h((string)$auditor['photo_path']) ?>" class="profile-photo" alt="Auditor Photo">
                <?php else: ?>
                <div class="profile-placeholder">👤</div>
                <?php endif; ?>

                <h2><?= h((string)$auditor['full_name']) ?></h2>
                <span><?= h((string)$auditor['email']) ?></span>
                <br>
                <span><?= h((string)$auditor['qualification']) ?></span>
            </div>
        </aside>

        <main class="content">
            <div class="topbar">
                <div>
                    <h2>Update Auditor Details</h2>
                    <p>NIC, qualification, experience and profile photo.</p>
                </div>

                <div>
                    <a href="dashboard.php">Dashboard</a> |
                    <a href="select_company.php">Select Company</a>
                </div>
            </div>

            <div class="form-card">

                <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="grid">

                        <div class="form-group">
                            <label>Full Name</label>
                            <div class="readonly-box"><?= h((string)$auditor['full_name']) ?></div>
                        </div>

                        <div class="form-group">
                            <label>Email</label>
                            <div class="readonly-box"><?= h((string)$auditor['email']) ?></div>
                        </div>

                        <div class="form-group">
                            <label>NIC Number *</label>
                            <input name="nic" value="<?= h((string)($auditor['nic'] ?? '')) ?>"
                                placeholder="Enter NIC number" required>
                        </div>

                        <div class="form-group">
                            <label>Experience Years</label>
                            <input name="experience_years" type="number" min="0"
                                value="<?= (int)($auditor['experience_years'] ?? 0) ?>" placeholder="Example: 2">
                        </div>

                        <div class="form-group">
                            <label>Degree</label>
                            <input name="degree" value="<?= h((string)($auditor['degree'] ?? '')) ?>"
                                placeholder="Example: BSc Accounting / Finance">
                        </div>

                        <div class="form-group">
                            <label>Qualification *</label>
                            <input name="qualification" value="<?= h((string)($auditor['qualification'] ?? '')) ?>"
                                placeholder="Example: CA, AAT, ACCA, Diploma" required>
                        </div>

                        <div class="form-group full">
                            <label>Bio / Professional Summary</label>
                            <textarea name="bio"
                                placeholder="Write your audit/accounting experience summary..."><?= h((string)($auditor['bio'] ?? '')) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label>Profile Photo</label>
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                            <div class="hint">Allowed: JPG, PNG, WEBP. Maximum size: 5MB.</div>
                        </div>

                    </div>

                    <div class="btn-row">
                        <button type="submit" class="btn">Update Profile</button>
                        <a href="dashboard.php" class="btn btn-light">Back to Dashboard</a>
                    </div>
                </form>

            </div>
        </main>

    </div>

</body>

</html>