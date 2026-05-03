<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

set_security_headers();

if (isset($_SESSION['user_id']) && isset($_SESSION['company_id'])) {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Login';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!verify_csrf_token($csrf)) {
        $message = 'Invalid form submission. Please try again.';
        $messageType = 'danger';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $message = 'Please enter email and password.';
            $messageType = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'danger';
        } else {
            $stmt = $conn->prepare("
                SELECT user_id, full_name, email, password, status, created_at
                FROM app_users
                WHERE LOWER(email) = ?
                LIMIT 1
            ");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res->fetch_assoc();
            $stmt->close();

            $passwordOk = false;
            $needsRehash = false;

            if ($user) {
                $dbPassword = (string)$user['password'];

                if (password_get_info($dbPassword)['algo'] !== 0) {
                    $passwordOk = password_verify($password, $dbPassword);
                    $needsRehash = $passwordOk && password_needs_rehash($dbPassword, PASSWORD_DEFAULT);
                } else {
                    // Old plain-text password support. After login, it will be converted to hash.
                    $passwordOk = hash_equals($dbPassword, $password);
                    $needsRehash = $passwordOk;
                }
            }

            if (!$user || !$passwordOk) {
                $message = 'Invalid email or password.';
                $messageType = 'danger';
            } elseif (strtolower(trim((string)$user['status'])) !== 'active') {
                $message = 'Your account is inactive. Please contact administrator.';
                $messageType = 'danger';
            } else {
                $user_id = (int)$user['user_id'];

                if ($needsRehash) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $up = $conn->prepare("UPDATE app_users SET password = ? WHERE user_id = ?");
                    $up->bind_param("si", $newHash, $user_id);
                    $up->execute();
                    $up->close();
                }

               $stmt = $conn->prepare("
    SELECT 
        c.company_id,
        c.company_name,
        c.registration_no,
        c.email AS company_email,
        c.phone AS company_phone,
        c.address AS company_address,
        cua.role_in_company,
        cua.access_status
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
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $res = $stmt->get_result();

                $companies = [];
                while ($row = $res->fetch_assoc()) {
                    $row['role_in_company'] = normalize_role_value((string)($row['role_in_company'] ?? ''));
                    $companies[] = $row;
                }
                $stmt->close();

               if (empty($companies)) {
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user_id;
    $_SESSION['full_name'] = (string)$user['full_name'];
    $_SESSION['email'] = (string)$user['email'];
    $_SESSION['account_status'] = (string)$user['status'];
    $_SESSION['created_at'] = (string)$user['created_at'];

    $_SESSION['role'] = 'auditor';
    $_SESSION['role_in_company'] = 'auditor';
    $_SESSION['assigned_companies'] = [];

  header("Location: auditor_profile.php");
    exit;
} else {
                    session_regenerate_id(true);

                    $company = $companies[0];
                    $role = normalize_role_value((string)$company['role_in_company']);

                    if ($role === '') {
                        $message = 'Invalid user role. Please contact administrator.';
                        $messageType = 'danger';
                    } else {
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['full_name'] = (string)$user['full_name'];
                        $_SESSION['email'] = (string)$user['email'];
                        $_SESSION['account_status'] = (string)$user['status'];
                        $_SESSION['created_at'] = (string)$user['created_at'];

                        $_SESSION['company_id'] = (int)$company['company_id'];
                        $_SESSION['company_name'] = (string)$company['company_name'];
                        $_SESSION['company_registration_no'] = (string)($company['registration_no'] ?? '');
                        $_SESSION['company_email'] = (string)($company['company_email'] ?? '');
                        $_SESSION['company_phone'] = (string)($company['company_phone'] ?? '');
                        $_SESSION['company_address'] = (string)($company['company_address'] ?? '');

                        $_SESSION['role'] = $role;
                        $_SESSION['role_in_company'] = $role;
                        $_SESSION['assigned_companies'] = $companies;

                        header("Location: dashboard.php");
                        exit;
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
    <title><?= e($pageTitle) ?> | FreeLedger</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="assets/css/style.css">
</head>
</head>

<body>

    <div class="auth-shell">
        <section class="auth-left">
            <div class="auth-brand">
                <div class="logo">📊</div>
                <h1>FreeLedger Accounting & Audit System</h1>
                <p>
                    A professional platform for companies, accountants and auditors to manage
                    financial records, reports, audit submissions and approval workflows.
                </p>

                <div class="auth-points">
                    <div>✔ Income, expenses, assets and liabilities management</div>
                    <div>✔ Cash and bank account tracking</div>
                    <div>✔ Payroll, EPF and ETF calculation support</div>
                    <div>✔ Auditor assignment and final audit report workflow</div>
                    <div>✔ Role-based access control for secure operations</div>
                </div>
            </div>
        </section>

        <section class="auth-right">
            <div class="auth-card">
                <a href="index.php" class="back-home">← Back to Home</a>

                <h2>Welcome Back</h2>
                <p>Login to continue to your workspace.</p>

                <?php if ($message !== ''): ?>
                <div class="alert alert-<?= e($messageType) ?>">
                    <?= e($message) ?>
                </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" placeholder="Enter your email"
                            value="<?= e($_POST['email'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <div class="password-wrap">
                            <input type="password" name="password" id="loginPassword" placeholder="Enter your password"
                                required>
                            <button type="button" class="eye-btn" onclick="togglePassword()">👁</button>
                        </div>
                    </div>

                    <button type="submit" class="btn">Login Now</button>

                    <div class="auth-footer">
                        Don’t have an account?
                        <a href="register.php">Create Company</a> |
                        <a href="auditor_register.php">Auditor Register</a>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <script>
    function togglePassword() {
        const input = document.getElementById('loginPassword');
        input.type = input.type === 'password' ? 'text' : 'password';
    }
    </script>

</body>

</html>