<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';

set_security_headers();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);

// Only organization can add auditors
require_role($user_id, $company_id, ['organization']);

$pageTitle = 'Add Auditor';
$pageDescription = 'Create a new auditor account for your organization';

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($full_name === '' || $email === '') {
            $msg = 'Full name and email are required.';
            $msgType = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Please enter a valid email address.';
            $msgType = 'danger';
        } else {
            $stmt = $conn->prepare("
                SELECT user_id, full_name
                FROM app_users
                WHERE email = ?
                LIMIT 1
            ");

            if (!$stmt) {
                $msg = 'Database error occurred. Please try again.';
                $msgType = 'danger';
            } else {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                $existing_user = $result->fetch_assoc();
                $stmt->close();

                if ($existing_user) {
                    $existing_user_id = (int)$existing_user['user_id'];

                    $check_stmt = $conn->prepare("
                        SELECT role_in_company, access_status
                        FROM company_user_access
                        WHERE company_id = ? AND user_id = ?
                        LIMIT 1
                    ");

                    if (!$check_stmt) {
                        $msg = 'Database error occurred. Please try again.';
                        $msgType = 'danger';
                    } else {
                        $check_stmt->bind_param("ii", $company_id, $existing_user_id);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        $existing_access = $check_result->fetch_assoc();
                        $check_stmt->close();

                        if ($existing_access) {
                            $existingRole = normalize_role_value((string)($existing_access['role_in_company'] ?? ''));
                            $existingStatus = (string)($existing_access['access_status'] ?? '');

                            if (strcasecmp($existingStatus, 'Active') === 0) {
                                $msg = 'This user is already assigned to this company as ' . ($existingRole !== '' ? $existingRole : 'user') . '.';
                                $msgType = 'warning';
                            } else {
                                $update_stmt = $conn->prepare("
                                    UPDATE company_user_access
                                    SET role_in_company = 'auditor',
                                        access_status = 'Active'
                                    WHERE company_id = ? AND user_id = ?
                                ");

                                if (!$update_stmt) {
                                    $msg = 'Database error occurred. Please try again.';
                                    $msgType = 'danger';
                                } else {
                                    $update_stmt->bind_param("ii", $company_id, $existing_user_id);

                                    if ($update_stmt->execute()) {
                                        log_security_event(
                                            'auditor_assigned',
                                            $user_id,
                                            $existing_user_id,
                                            $company_id,
                                            "Existing user reactivated as auditor: {$email}"
                                        );

                                        $msg = 'Existing user reactivated as auditor for this company.';
                                        $msgType = 'success';
                                        $_POST = [];
                                    } else {
                                        $msg = 'Failed to reactivate user access.';
                                        $msgType = 'danger';
                                    }

                                    $update_stmt->close();
                                }
                            }
                        } else {
                            $role = 'auditor';

                            $stmt2 = $conn->prepare("
                                INSERT INTO company_user_access (company_id, user_id, role_in_company, access_status)
                                VALUES (?, ?, ?, 'Active')
                            ");

                            if (!$stmt2) {
                                $msg = 'Database error occurred. Please try again.';
                                $msgType = 'danger';
                            } else {
                                $stmt2->bind_param("iis", $company_id, $existing_user_id, $role);

                                if ($stmt2->execute()) {
                                    log_security_event(
                                        'auditor_assigned',
                                        $user_id,
                                        $existing_user_id,
                                        $company_id,
                                        "Existing user assigned as auditor: {$email}"
                                    );

                                    $msg = 'Existing user assigned as auditor successfully! Email: ' . e($email);
                                    $msgType = 'success';
                                    $_POST = [];
                                } else {
                                    $msg = 'Failed to assign existing user.';
                                    $msgType = 'danger';
                                }

                                $stmt2->close();
                            }
                        }
                    }
                } else {
                    if ($password === '' || $confirm_password === '') {
                        $msg = 'Password and confirm password are required for new users.';
                        $msgType = 'danger';
                    } elseif ($password !== $confirm_password) {
                        $msg = 'Passwords do not match.';
                        $msgType = 'danger';
                    } else {
                        $pwd_validation = validate_password_strength($password);

                        if (!$pwd_validation['valid']) {
                            $msg = implode(' ', $pwd_validation['errors']);
                            $msgType = 'danger';
                        } else {
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                            $conn->begin_transaction();

                            try {
                                $stmt = $conn->prepare("
                                    INSERT INTO app_users (full_name, email, password, status)
                                    VALUES (?, ?, ?, 'active')
                                ");

                                if (!$stmt) {
                                    throw new Exception('Failed to prepare user insert.');
                                }

                                $stmt->bind_param("sss", $full_name, $email, $hashedPassword);

                                if (!$stmt->execute()) {
                                    throw new Exception('Failed to create user account.');
                                }

                                $new_user_id = (int)$stmt->insert_id;
                                $stmt->close();

                                $role = 'auditor';

                                $stmt2 = $conn->prepare("
                                    INSERT INTO company_user_access (company_id, user_id, role_in_company, access_status)
                                    VALUES (?, ?, ?, 'Active')
                                ");

                                if (!$stmt2) {
                                    throw new Exception('Failed to prepare role assignment.');
                                }

                                $stmt2->bind_param("iis", $company_id, $new_user_id, $role);

                                if (!$stmt2->execute()) {
                                    throw new Exception('Failed to assign auditor role.');
                                }

                                $stmt2->close();

                                log_security_event(
                                    'auditor_created',
                                    $user_id,
                                    $new_user_id,
                                    $company_id,
                                    "Auditor account created: {$email}"
                                );

                                $conn->commit();

                                $msg = 'Auditor account created successfully! Email: ' . e($email);
                                $msgType = 'success';
                                $_POST = [];
                            } catch (Throwable $e) {
                                $conn->rollback();
                                $msg = 'Failed to create auditor account. Please try again.';
                                $msgType = 'danger';
                            }
                        }
                    }
                }
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="page-heading">
                <h1>Add Auditor</h1>
                <p><?= e($pageDescription) ?></p>
            </div>
        </div>
    </div>

    <div class="content">
        <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>Create Auditor Account</h3>
                <span class="badge badge-primary">Auditor Role</span>
            </div>

            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="form-grid">
                        <div class="form-group full">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control"
                                value="<?= e($_POST['full_name'] ?? '') ?>" required>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control"
                                value="<?= e($_POST['email'] ?? '') ?>" required>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">
                                Password <small style="color:#666;">(required for new users)</small>
                            </label>
                            <div class="input-with-toggle">
                                <input type="password" name="password" id="password" class="form-control">
                                <button type="button" class="btn btn-light password-toggle"
                                    data-toggle-password="password">
                                    <span class="eye-icon">👁</span>

                                </button>
                            </div>
                            <small style="color:#666; margin-top:5px; display:block;">
                                Password must be 6 to 15 characters and include uppercase, lowercase, and numbers.
                            </small>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">
                                Confirm Password <small style="color:#666;">(required for new users)</small>
                            </label>
                            <div class="input-with-toggle">
                                <input type="password" name="confirm_password" id="confirm_password"
                                    class="form-control">
                                <button type="button" class="btn btn-light password-toggle"
                                    data-toggle-password="confirm_password">
                                    <span class="eye-icon">👁</span>

                                </button>
                            </div>
                        </div>

                        <div class="form-group full">
                            <button type="submit" class="btn btn-primary" style="width:100%;">
                                Add / Assign Auditor
                            </button>
                            <small style="color:#666; margin-top:5px; display:block; text-align:center;">
                                If the email already exists, the user will be assigned to this company.
                                Otherwise, a new auditor account will be created.
                            </small>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>