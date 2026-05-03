<?php
session_start();
require_once 'config/db.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';
require_once 'includes/password_validator.php';

set_security_headers();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$pageTitle = 'Change Password';
$pageDescription = 'Update your account password securely';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$msg = '';
$msgType = 'success';

if (isset($_POST['change_password'])) {
    // Verify CSRF token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $msg = 'All password fields are required.';
            $msgType = 'danger';
        } elseif ($new_password !== $confirm_password) {
            $msg = 'New password and confirm password do not match.';
            $msgType = 'danger';
        } else {
            // Validate password strength
            $pwd_validation = validate_password_strength($new_password);
            if (!$pwd_validation['valid']) {
                $msg = implode(' ', $pwd_validation['errors']);
                $msgType = 'danger';
            } else {
                $stmt = $conn->prepare("SELECT password FROM app_users WHERE user_id = ? LIMIT 1");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->bind_result($db_password);
                $stmt->fetch();
                $stmt->close();

                if (!$db_password || !password_verify($current_password, $db_password)) {
                    $msg = 'Current password is incorrect.';
                    $msgType = 'danger';
                    log_security_event('password_change_failed', $user_id, null, null, 'Incorrect current password');
                } else {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE app_users SET password = ? WHERE user_id = ?");
                    $stmt->bind_param("si", $hashed, $user_id);

                    if ($stmt->execute()) {
                        $msg = 'Password changed successfully.';
                        $msgType = 'success';
                        log_security_event('password_changed', $user_id, null, null, 'User changed their password');
                    } else {
                        $msg = 'Failed to change password.';
                        $msgType = 'danger';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="page-heading">
                <h1>Change Password</h1>
                <p><?= e($pageDescription) ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <div class="company-pill"><?= e($_SESSION['company_name'] ?? 'Company') ?></div>
            <div class="role-pill"><?= e($_SESSION['role'] ?? 'User') ?></div>
            <div class="user-chip">
                <div class="avatar"><?= e(strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1))) ?></div>
                <div class="meta">
                    <strong><?= e($_SESSION['full_name'] ?? 'User') ?></strong>
                    <span><?= e($_SESSION['email'] ?? '') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="content">
        <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <div class="grid grid-2">
            <div class="card">
                <div class="card-header">
                    <h3>Update Password</h3>
                    <span class="badge badge-primary">Secure Access</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <div class="form-grid">
                            <div class="form-group full">
                                <label class="form-label">Current Password</label>
                                <div class="input-with-toggle">
                                    <input type="password" name="current_password" id="current_password"
                                        class="form-control" required>
                                    <button type="button" class="btn btn-light password-toggle"
                                        data-toggle-password="current_password">
                                        <span class="eye-icon">👁</span>

                                    </button>
                                </div>
                            </div>

                            <div class="form-group full">
                                <label class="form-label">New Password</label>
                                <div class="input-with-toggle">
                                    <input type="password" name="new_password" id="new_password" class="form-control"
                                        required>
                                    <button type="button" class="btn btn-light password-toggle"
                                        data-toggle-password="new_password">
                                        <span class="eye-icon">👁</span>

                                    </button>
                                </div>
                            </div>

                            <div class="form-group full">
                                <label class="form-label">Confirm New Password</label>
                                <div class="input-with-toggle">
                                    <input type="password" name="confirm_password" id="confirm_password"
                                        class="form-control" required>
                                    <button type="button" class="btn btn-light password-toggle"
                                        data-toggle-password="confirm_password">
                                        <span class="eye-icon">👁</span>
                                        <span class="eye-label">Show</span>
                                    </button>
                                </div>
                            </div>

                            <div class="form-group full">
                                <button type="submit" name="change_password" class="btn btn-primary">Change
                                    Password</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Password Rules</h3>
                    <span class="badge badge-warning">Important</span>
                </div>
                <div class="card-body">
                    <div class="grid">
                        <div class="stat-card">
                            <div class="stat-top">
                                <div class="stat-title">Minimum Length</div>
                                <div class="stat-icon">🔐</div>
                            </div>
                            <div class="stat-value">6+</div>
                            <div class="stat-note">Use at least 6 characters</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-top">
                                <div class="stat-title">Recommendation</div>
                                <div class="stat-icon">🛡️</div>
                            </div>
                            <div class="stat-value stat-value-small">Strong Password</div>
                            <div class="stat-note">Use letters, numbers, and symbols</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-top">
                                <div class="stat-title">Security</div>
                                <div class="stat-icon">✅</div>
                            </div>
                            <div class="stat-value stat-value-small">Protected</div>
                            <div class="stat-note">Passwords are stored hashed</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>