<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/security.php';
require_once 'includes/password_validator.php';

// Set security headers
set_security_headers();

if (isset($_SESSION['user_id']) && isset($_SESSION['company_id'])) {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Register';
$pageDescription = 'Create your professional accounting and audit system account with complete company setup';

$showSidebarToggle = false;

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid form submission. Please try again.';
        $messageType = 'danger';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $company_name = trim($_POST['company_name'] ?? '');
        $registration_no = trim($_POST['registration_no'] ?? '');
        $company_email = trim($_POST['company_email'] ?? '');
        $company_phone = trim($_POST['company_phone'] ?? '');
        $company_address = trim($_POST['company_address'] ?? '');

        // Validation
        if ($full_name === '' || $email === '' || $password === '' || $confirm_password === '') {
            $message = 'Please fill all required fields.';
            $messageType = 'danger';
        } elseif ($company_name === '') {
            $message = 'Company name is required for new registrations.';
            $messageType = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'danger';
        } elseif ($company_email !== '' && !filter_var($company_email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid company email address.';
            $messageType = 'danger';
        } elseif ($password !== $confirm_password) {
            $message = 'Password and confirm password do not match.';
            $messageType = 'danger';
        } else {
            $pwd_validation = validate_password_strength($password);

            if (!$pwd_validation['valid']) {
                $message = implode(' ', $pwd_validation['errors']);
                $messageType = 'danger';
            } else {
                $checkStmt = $conn->prepare("
                    SELECT user_id
                    FROM app_users
                    WHERE email = ?
                    LIMIT 1
                ");

                if (!$checkStmt) {
                    $message = 'Database error occurred. Please try again.';
                    $messageType = 'danger';
                } else {
                    $checkStmt->bind_param("s", $email);
                    $checkStmt->execute();
                    $checkStmt->store_result();

                    if ($checkStmt->num_rows > 0) {
                        $message = 'This email is already registered.';
                        $messageType = 'danger';
                        $checkStmt->close();
                    } else {
                        $checkStmt->close();

                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                        $conn->autocommit(false);

                        try {
                            // 1. Create user
                            $insertStmt = $conn->prepare("
                                INSERT INTO app_users (full_name, email, password, status)
                                VALUES (?, ?, ?, 'active')
                            ");

                            if (!$insertStmt) {
                                throw new Exception('Failed to prepare user insert.');
                            }

                            $insertStmt->bind_param("sss", $full_name, $email, $hashedPassword);

                            if (!$insertStmt->execute()) {
                                throw new Exception('Failed to create user account.');
                            }

                            $new_user_id = (int)$insertStmt->insert_id;
                            $insertStmt->close();

                            log_security_event('account_created', $new_user_id, null, null, 'Self-registration');

                            // Create organization
                            $stmt_company = $conn->prepare("
                                INSERT INTO companies (company_name, registration_no, email, phone, address)
                                VALUES (?, ?, ?, ?, ?)
                            ");

                            if (!$stmt_company) {
                                throw new Exception('Failed to prepare company insert.');
                            }

                            $stmt_company->bind_param("sssss", $company_name, $registration_no, $company_email, $company_phone, $company_address);

                            if (!$stmt_company->execute()) {
                                throw new Exception('Failed to create organization.');
                            }

                            $company_id = (int)$stmt_company->insert_id;
                            $stmt_company->close();

                            // Assign organization role
                            $role = 'organization';

                            $stmt_access = $conn->prepare("
                                INSERT INTO company_user_access (company_id, user_id, role_in_company, access_status)
                                VALUES (?, ?, ?, 'Active')
                            ");

                            if (!$stmt_access) {
                                throw new Exception('Failed to prepare company access insert.');
                            }

                            $stmt_access->bind_param("iis", $company_id, $new_user_id, $role);

                            if (!$stmt_access->execute()) {
                                throw new Exception('Failed to assign organization role.');
                            }

                            $stmt_access->close();

                            log_security_event(
                                'organization_created',
                                $new_user_id,
                                null,
                                $company_id,
                                'Auto-created organization during registration'
                            );

                            $conn->commit();

                            session_regenerate_id(true);

                            // 4. Auto-login and session setup
                            $_SESSION['user_id'] = $new_user_id;
                            $_SESSION['full_name'] = $full_name;
                            $_SESSION['email'] = $email;
                            $_SESSION['account_status'] = 'active';

                            $_SESSION['company_id'] = $company_id;
                            $_SESSION['company_name'] = $company_name;
                            $_SESSION['company_registration_no'] = $registration_no;
                            $_SESSION['company_email'] = $company_email;
                            $_SESSION['company_phone'] = $company_phone;
                            $_SESSION['company_address'] = $company_address;

                            $_SESSION['role'] = $role;
                            $_SESSION['role_in_company'] = $role;

                            header("Location: dashboard.php");
                            exit;
                        } catch (Throwable $e) {
                            $conn->rollback();

                            $message = 'Registration failed. Please try again.';
                            $messageType = 'danger';
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Company Registration | FreeLedger</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="assets/css/style.css">
</head>
</head>

<body class="register-page">

    <div class="register-wrapper">

        <div class="info-panel">
            <h1>Register Your Company</h1>
            <p>
                Register your company to access FreeLedger's professional accounting 
                and audit management system. Set up your organization, invite accountants, 
                and manage all financial records in one secure platform.
            </p>

            <div class="feature">✔ Complete company information setup</div>
            <div class="feature">✔ Invite accountants and auditors</div>
            <div class="feature">✔ Multi-user role-based access control</div>
            <div class="feature">✔ Professional audit report management</div>
            <div class="feature">✔ Comprehensive financial reporting</div>
        </div>

        <div class="form-panel">
            <a href="index.php" class="back-link">← Back to Home</a>

            <h2>Create Company Account</h2>
            <p class="subtitle">Fill your company details to register and get started.</p>

            <?php if ($message !== ''): ?>
            <div class="alert alert-<?= e($messageType) ?>">
                <?= e($message) ?>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" placeholder="Enter your full name" 
                        value="<?= e($_POST['full_name'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" placeholder="Enter your email address" 
                        value="<?= e($_POST['email'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Company Name *</label>
                    <input type="text" name="company_name" placeholder="Enter your company name" 
                        value="<?= e($_POST['company_name'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Registration Number</label>
                    <input type="text" name="registration_no" placeholder="Enter company registration number" 
                        value="<?= e($_POST['registration_no'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Company Email</label>
                    <input type="email" name="company_email" placeholder="Enter company email" 
                        value="<?= e($_POST['company_email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Company Phone</label>
                    <input type="text" name="company_phone" placeholder="Enter company phone number" 
                        value="<?= e($_POST['company_phone'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Company Address</label>
                    <textarea name="company_address" placeholder="Enter company address"><?= e($_POST['company_address'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" id="registerPassword" placeholder="Create a strong password" required>
                </div>

                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password" id="confirmPassword" placeholder="Confirm your password" required>
                </div>

                <button type="submit" class="btn">Register Now</button>

                <div class="links">
                    Already registered? <a href="login.php">Login here</a>
                </div>
            </form>
        </div>
    </div>

</body>

</html>