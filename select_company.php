<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

set_security_headers();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$pageTitle = 'Select Company';
$pageDescription = 'Choose which company to work with';
$showSidebarToggle = false;

// Handle company selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_company'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $company_id = (int)($_POST['company_id'] ?? 0);

        if ($company_id > 0) {
            // Verify user has access to this company
         $stmt = $conn->prepare("
    SELECT c.company_id, c.company_name, c.registration_no, c.address, 
           c.phone, c.email, cua.role_in_company, cua.access_status
    FROM company_user_access cua
    INNER JOIN companies c ON c.company_id = cua.company_id
    WHERE cua.user_id = ? 
      AND cua.company_id = ?
      AND cua.access_status = 'Active'
      AND (
            cua.role_in_company <> 'auditor'
            OR EXISTS (
                SELECT 1
                FROM auditor_invites ai
                WHERE ai.company_id = cua.company_id
                  AND ai.auditor_user_id = cua.user_id
                  AND ai.status = 'accepted'
            )
      )
    LIMIT 1
");

            if ($stmt) {
                $stmt->bind_param("ii", $user_id, $company_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $company = $res->fetch_assoc();
                $stmt->close();

                if ($company) {
                    $_SESSION['company_id'] = (int)$company['company_id'];
                    $_SESSION['company_name'] = (string)($company['company_name'] ?? '');
                    $_SESSION['company_registration_no'] = (string)($company['registration_no'] ?? '');
                    $_SESSION['company_email'] = (string)($company['email'] ?? '');
                    $_SESSION['company_phone'] = (string)($company['phone'] ?? '');
                    $_SESSION['company_address'] = (string)($company['address'] ?? '');
                    $_SESSION['role'] = normalize_role_value((string)($company['role_in_company'] ?? ''));
                    $_SESSION['role_in_company'] = $_SESSION['role'];

                    header("Location: dashboard.php");
                    exit;
                } else {
                    $error = 'You do not have access to this company.';
                }
            } else {
                $error = 'Database error occurred. Please try again.';
            }
        } else {
            $error = 'Please select a valid company.';
        }
    }
}

// Get list of companies user has access to
$companies = [];
$stmt = $conn->prepare("
    SELECT c.company_id, c.company_name, c.registration_no, cua.role_in_company
    FROM company_user_access cua
    INNER JOIN companies c ON c.company_id = cua.company_id
    WHERE cua.user_id = ?
      AND cua.access_status = 'Active'
      AND (
            cua.role_in_company <> 'auditor'
            OR EXISTS (
                SELECT 1
                FROM auditor_invites ai
                WHERE ai.company_id = cua.company_id
                  AND ai.auditor_user_id = cua.user_id
                  AND ai.status = 'accepted'
            )
      )
    ORDER BY c.company_name ASC
");

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $companies[] = $row;
    }
    $stmt->close();
}

// Get user info
$user = null;
$stmt = $conn->prepare("SELECT full_name, email FROM app_users WHERE user_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();
}

include 'includes/header.php';
?>

<div class="main-content"
    style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="container" style="max-width: 600px; width: 90%;">
        <div class="card" style="border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
            <div class="card-body" style="padding: 40px;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #333; margin: 0 0 10px 0;">Select Company</h1>
                    <p style="color: #666; margin: 0;">Choose which company to work with</p>
                </div>

                <?php if (isset($error)): ?>
                <div class="alert alert-danger" style="margin-bottom: 20px;">
                    <?= e($error) ?>
                </div>
                <?php endif; ?>

                <?php if ($user): ?>
                <div
                    style="text-align: center; margin-bottom: 30px; padding: 15px; background: #f5f5f5; border-radius: 5px;">
                    <p style="margin: 0 0 5px 0;"><strong>Logged in as:</strong></p>
                    <p style="margin: 0; color: #666;"><?= e($user['full_name']) ?></p>
                    <p style="margin: 5px 0 0 0; font-size: 0.9em; color: #999;"><?= e($user['email']) ?></p>
                </div>
                <?php endif; ?>

                <?php if (empty($companies)): ?>
                <div style="text-align: center; padding: 30px;">
                    <p style="color: #666; margin-bottom: 20px;">You don't have access to any companies yet.</p>
                    <a href="logout.php" class="btn btn-secondary">Log Out</a>
                </div>
                <?php else: ?>
                <form method="POST" id="company-form">
                    <?= csrf_field() ?>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <label class="form-label"
                            style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">
                            Select a Company
                        </label>
                        <div style="display: grid; gap: 10px;">
                            <?php foreach ($companies as $company): ?>
                            <label
                                style="display: flex; align-items: center; padding: 15px; border: 2px solid #e0e0e0; border-radius: 5px; cursor: pointer; transition: all 0.3s ease;">
                                <input type="radio" name="company_id" value="<?= (int)$company['company_id'] ?>"
                                    style="margin-right: 15px; cursor: pointer; width: 18px; height: 18px;">
                                <div style="flex: 1;">
                                    <strong
                                        style="display: block; color: #333;"><?= e($company['company_name']) ?></strong>
                                    <small style="color: #999;">
                                        <?php 
                                        $role = normalize_role_value((string)($company['role_in_company'] ?? ''));
                                        echo 'Reg: ' . e($company['registration_no']) . ' • Role: ' . e($role);
                                        ?>
                                    </small>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="display: grid; gap: 10px;">
                        <button type="submit" name="select_company" value="1" class="btn btn-primary"
                            style="width: 100%; padding: 12px;">
                            Continue to Dashboard
                        </button>
                        <a href="logout.php" class="btn btn-secondary"
                            style="width: 100%; padding: 12px; text-align: center; text-decoration: none;">
                            Log Out
                        </a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>