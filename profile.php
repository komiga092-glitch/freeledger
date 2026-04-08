<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$pageTitle = 'My Profile';
$pageDescription = 'Manage your account profile';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$msg = '';
$msgType = 'success';

$stmt = $conn->prepare("SELECT user_id, full_name, email, status, created_at FROM app_users WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if (isset($_POST['save_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');

    if ($full_name === '' || $email === '') {
        $msg = 'Name and email are required.';
        $msgType = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.';
        $msgType = 'danger';
    } else {
        $check = $conn->prepare("SELECT user_id FROM app_users WHERE email = ? AND user_id != ? LIMIT 1");
        $check->bind_param("si", $email, $user_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $msg = 'This email is already used by another account.';
            $msgType = 'danger';
        } else {
            $stmt = $conn->prepare("UPDATE app_users SET full_name = ?, email = ? WHERE user_id = ?");
            $stmt->bind_param("ssi", $full_name, $email, $user_id);

            if ($stmt->execute()) {
                $_SESSION['full_name'] = $full_name;
                $_SESSION['email'] = $email;
                $user['full_name'] = $full_name;
                $user['email'] = $email;
                $msg = 'Profile updated successfully.';
                $msgType = 'success';
            } else {
                $msg = 'Failed to update profile.';
                $msgType = 'danger';
            }
            $stmt->close();
        }
        $check->close();
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
                <h1>My Profile</h1>
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
                    <h3>Profile Details</h3>
                    <span class="badge badge-primary">Account Info</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group full">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" class="form-control" value="<?= e($user['full_name']) ?>" required>
                            </div>

                            <div class="form-group full">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required>
                            </div>

                            <div class="form-group full">
                                <button type="submit" name="save_profile" class="btn btn-primary">Update Profile</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Account Summary</h3>
                    <span class="badge badge-success">Live</span>
                </div>
                <div class="card-body">
                    <div class="grid">
                        <div class="stat-card">
                            <div class="stat-top">
                                <div class="stat-title">User ID</div>
                                <div class="stat-icon">🆔</div>
                            </div>
                            <div class="stat-value"><?= e($user['user_id']) ?></div>
                            <div class="stat-note">System user identification</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-top">
                                <div class="stat-title">Status</div>
                                <div class="stat-icon">✅</div>
                            </div>
                            <div class="stat-value" style="font-size:22px;"><?= e($user['status']) ?></div>
                            <div class="stat-note">Current account status</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-top">
                                <div class="stat-title">Role</div>
                                <div class="stat-icon">👤</div>
                            </div>
                            <div class="stat-value" style="font-size:22px;"><?= e($_SESSION['role'] ?? 'User') ?></div>
                            <div class="stat-note">Access role in system</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-top">
                                <div class="stat-title">Created At</div>
                                <div class="stat-icon">📅</div>
                            </div>
                            <div class="stat-value" style="font-size:18px;"><?= e($user['created_at']) ?></div>
                            <div class="stat-note">Account creation date</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

<?php include 'includes/footer.php'; ?>