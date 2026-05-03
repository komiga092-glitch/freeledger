<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

require_once 'includes/password_validator.php';

set_security_headers();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);

// Only organization can add accountants
require_role(['organization']);
$pageTitle = 'Add Accountant';
$pageDescription = 'Create a new accountant account for your organization';

$msg = '';
$msgType = 'success';
$accountants = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['delete_accountant'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            $msg = 'Invalid form submission. Please try again.';
            $msgType = 'danger';
        } else {
            $delete_user_id = (int)($_POST['delete_user_id'] ?? 0);

            if ($delete_user_id <= 0) {
                $msg = 'Invalid accountant selected.';
                $msgType = 'danger';
            } elseif ($delete_user_id === $user_id) {
                $msg = 'You cannot delete your own account.';
                $msgType = 'danger';
            } else {
                $conn->begin_transaction();

                try {
                    // Remove accountant access from this company
                    $deleteAccess = $conn->prepare("
                        DELETE FROM company_user_access
                        WHERE company_id = ?
                          AND user_id = ?
                          AND role_in_company = 'accountant'
                    ");

                    if (!$deleteAccess) {
                        throw new Exception('Failed to prepare accountant access delete.');
                    }

                    $deleteAccess->bind_param("ii", $company_id, $delete_user_id);

                    if (!$deleteAccess->execute()) {
                        throw new Exception('Failed to delete accountant access.');
                    }

                    $affected = $deleteAccess->affected_rows;
                    $deleteAccess->close();

                    if ($affected <= 0) {
                        throw new Exception('Accountant access not found.');
                    }

                    /*
                     * Check whether this user has any other company access.
                     * If no other access exists, delete app_users record also.
                     */
                    $checkOtherAccess = $conn->prepare("
                        SELECT COUNT(*) AS total
                        FROM company_user_access
                        WHERE user_id = ?
                    ");

                    if (!$checkOtherAccess) {
                        throw new Exception('Failed to check other access.');
                    }

                    $checkOtherAccess->bind_param("i", $delete_user_id);
                    $checkOtherAccess->execute();
                    $otherAccess = $checkOtherAccess->get_result()->fetch_assoc();
                    $checkOtherAccess->close();

                    $totalAccess = (int)($otherAccess['total'] ?? 0);

                    if ($totalAccess === 0) {
                        $deleteUser = $conn->prepare("
                            DELETE FROM app_users
                            WHERE user_id = ?
                              AND user_id <> ?
                        ");

                        if (!$deleteUser) {
                            throw new Exception('Failed to prepare user delete.');
                        }

                        $deleteUser->bind_param("ii", $delete_user_id, $user_id);

                        if (!$deleteUser->execute()) {
                            throw new Exception('Failed to delete user account.');
                        }

                        $deleteUser->close();
                    }

                    log_security_event(
                        'accountant_deleted',
                        $user_id,
                        $delete_user_id,
                        $company_id,
                        "Accountant deleted from company"
                    );

                    $conn->commit();

                    $msg = 'Accountant deleted successfully.';
                    $msgType = 'success';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $msg = 'Failed to delete accountant. Please try again.';
                    $msgType = 'danger';
                }
            }
        }
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
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
                                    SET role_in_company = 'accountant',
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
                                            'accountant_assigned',
                                            $user_id,
                                            $existing_user_id,
                                            $company_id,
                                            "Existing user reactivated as accountant: {$email}"
                                        );

                                        $msg = 'Existing user reactivated as accountant for this company.';
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
                            $role = 'accountant';

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
                                        'accountant_assigned',
                                        $user_id,
                                        $existing_user_id,
                                        $company_id,
                                        "Existing user assigned as accountant: {$email}"
                                    );

                                    $msg = 'Existing user assigned as accountant successfully! Email: ' . e($email);
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

                                $role = 'accountant';

                                $stmt2 = $conn->prepare("
                                    INSERT INTO company_user_access (company_id, user_id, role_in_company, access_status)
                                    VALUES (?, ?, ?, 'Active')
                                ");

                                if (!$stmt2) {
                                    throw new Exception('Failed to prepare role assignment.');
                                }

                                $stmt2->bind_param("iis", $company_id, $new_user_id, $role);

                                if (!$stmt2->execute()) {
                                    throw new Exception('Failed to assign accountant role.');
                                }

                                $stmt2->close();

                                log_security_event(
                                    'accountant_created',
                                    $user_id,
                                    $new_user_id,
                                    $company_id,
                                    "Accountant account created: {$email}"
                                );

                                $conn->commit();

                                $msg = 'Accountant account created successfully! Email: ' . e($email);
                                $msgType = 'success';
                                $_POST = [];
                            } catch (Throwable $e) {
                                $conn->rollback();
                                $msg = 'Failed to create accountant account. Please try again.';
                                $msgType = 'danger';
                            }
                        }
                    }
                }
            }
        }
    }
}
$listStmt = $conn->prepare("
    SELECT 
        u.user_id,
        u.full_name,
        u.email,
        u.status,
        cua.role_in_company,
        cua.access_status
    FROM company_user_access cua
    INNER JOIN app_users u 
        ON u.user_id = cua.user_id
    WHERE cua.company_id = ?
      AND cua.role_in_company = 'accountant'
    ORDER BY u.full_name ASC
");

if ($listStmt) {
    $listStmt->bind_param("i", $company_id);
    $listStmt->execute();
    $accountants = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $listStmt->close();
}
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <div class="page-heading">
                <h1>Add Accountant</h1>
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
                <h3>Create Accountant Account</h3>
                <span class="badge badge-primary">Accountant Role</span>
            </div>

            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="form-grid">
                        <div class="form-group full">
                            <label class="form-label">Full Name</label><br>
                            <input type="text" name="full_name" class="form-control"
                                value="<?= e($_POST['full_name'] ?? '') ?>" required>
                        </div>
                        <br>
                        <div class="form-group full">
                            <label class="form-label">Email Address</label><br>
                            <input type="email" name="email" class="form-control"
                                value="<?= e($_POST['email'] ?? '') ?>" required>
                        </div><br>

                        <div class="form-group full">
                            <label class="form-label">
                                Password <small class="text-small">(required for new users)</small>
                            </label><br>
                            <div class="input-with-toggle">
                                <input type="password" name="password" id="password" class="form-control">
                                <button type="button" class="btn btn-light password-toggle"
                                    data-toggle-password="password">
                                    <span class="eye-icon">👁</span>

                                </button>
                                <br>
                            </div>
                            <small class="text-small" style="margin-top:5px; display:block;">
                                Password must be 6 to 12 characters and include uppercase, lowercase, and numbers.
                            </small>
                        </div>
                        <br>
                        <div class="form-group full">
                            <label class="form-label">
                                Conform Password <small class="text-small">(required for new users)</small>
                            </label><br>
                            <div class="input-with-toggle">
                                <input type="password" name="confirm_password" id="confirm_password"
                                    class="form-control">
                                <button type="button" class="btn btn-light password-toggle"
                                    data-toggle-password="confirm_password">
                                    <span class="eye-icon">👁</span>

                                </button>
                            </div>
                        </div>
                        <br>
                        <div class="form-group full">
                            <button type="submit" class="btn btn-primary btn-block">
                                Add / Assign Accountant
                            </button><br>
                            <small class="text-small" style="margin-top:5px; display:block; text-align:center;">
                                If the email already exists, the user will be assigned to this company.
                                Otherwise, a new accountant account will be created.
                            </small>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="card" style="margin-top:20px;">
        <div class="card-header">
            <h3>Accountant Details</h3>
            <span class="badge badge-primary"><?= count($accountants) ?> Accountants</span>
        </div>

        <div class="card-body">
            <?php if (empty($accountants)): ?>
            <p class="text-small">No accountants assigned to this company yet.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Full Name</th>
                            <th>Email / Username</th>
                            <th>Role</th>
                            <th>Access Status</th>
                            <th>Account Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accountants as $index => $acc): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= e($acc['full_name']) ?></td>
                            <td><?= e($acc['email']) ?></td>
                            <td><?= e($acc['role_in_company']) ?></td>
                            <td>
                                <span class="badge badge-success">
                                    <?= e($acc['access_status']) ?>
                                </span>
                            </td>
                            <td><?= e($acc['status']) ?></td>
                            <td>
                                <form method="POST"
                                    onsubmit="return confirm('Are you sure you want to delete this accountant? This will remove the accountant access from this company.');"
                                    style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_user_id" value="<?= (int)$acc['user_id'] ?>">
                                    <button type="submit" name="delete_accountant" class="btn btn-danger btn-sm">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>