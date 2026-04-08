<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/security.php';

set_security_headers();

if (!function_exists('normalize_role_value')) {
    function normalize_role_value(string $role): string
    {
        $role = strtolower(trim($role));

        $map = [
            'owner'        => 'organization',
            'manager'      => 'accountant',
            'employee'     => 'accountant',
            'organization' => 'organization',
            'accountant'   => 'accountant',
            'auditor'      => 'auditor',
        ];

        return $map[$role] ?? '';
    }
}

if (isset($_SESSION['user_id']) && isset($_SESSION['company_id'])) {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Login';
$pageDescription = 'Login to your professional accounting and audit system';

$message = '';
$messageType = '';

/* Preserve invite token flow */
$pendingInviteToken = trim($_GET['token'] ?? $_SESSION['pending_invite_token'] ?? '');
if ($pendingInviteToken !== '') {
    $_SESSION['pending_invite_token'] = $pendingInviteToken;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid form submission. Please try again.';
        $messageType = 'danger';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $message = 'Please enter email and password.';
            $messageType = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'danger';
        } else {
            $rate_limit = check_login_rate_limit($email);

            if (!$rate_limit['allowed']) {
                $message = $rate_limit['message'];
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("
                    SELECT user_id, full_name, email, password, status, created_at
                    FROM app_users
                    WHERE email = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    $message = 'Database error occurred. Please try again.';
                    $messageType = 'danger';
                } else {
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $user = $res->fetch_assoc();
                    $stmt->close();

                    if (!$user) {
                        record_login_attempt($email, false);
                        $message = 'Invalid email or password.';
                        $messageType = 'danger';
                    } elseif (strtolower(trim((string)($user['status'] ?? ''))) !== 'active') {
                        record_login_attempt($email, false);
                        $message = 'Your account is inactive. Please contact administrator.';
                        $messageType = 'danger';
                    } elseif (!password_verify($password, (string)$user['password'])) {
                        record_login_attempt($email, false);
                        $message = 'Invalid email or password.';
                        $messageType = 'danger';
                    } else {
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
                            ORDER BY c.company_name ASC
                        ");

                        if (!$stmt) {
                            record_login_attempt($email, false);
                            $message = 'Database error occurred. Please try again.';
                            $messageType = 'danger';
                        } else {
                            $user_id = (int)$user['user_id'];

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
                                record_login_attempt($email, false);
                                $message = 'Your account has not been assigned to an organization. Please contact administrator.';
                                $messageType = 'danger';
                            } else {
                                record_login_attempt($email, true);
                                log_security_event('login_success', $user_id, null, null, $email);

                                session_regenerate_id(true);

                                // Default first assigned company
                                $company = $companies[0];
                                $role = normalize_role_value((string)($company['role_in_company'] ?? ''));

                                if ($role === '') {
                                    record_login_attempt($email, false);
                                    $message = 'Your account role is invalid. Please contact administrator.';
                                    $messageType = 'danger';
                                } else {
                                    $_SESSION['user_id'] = $user_id;
                                    $_SESSION['full_name'] = (string)$user['full_name'];
                                    $_SESSION['email'] = (string)$user['email'];
                                    $_SESSION['account_status'] = (string)$user['status'];
                                    $_SESSION['created_at'] = (string)$user['created_at'];

                                    $_SESSION['company_id'] = (int)$company['company_id'];
                                    $_SESSION['company_name'] = (string)$company['company_name'];
                                    $_SESSION['company_registration_no'] = (string)$company['registration_no'];
                                    $_SESSION['company_email'] = (string)$company['company_email'];
                                    $_SESSION['company_phone'] = (string)$company['company_phone'];
                                    $_SESSION['company_address'] = (string)$company['company_address'];

                                    $_SESSION['role'] = $role;
                                    $_SESSION['role_in_company'] = $role;

                                    // Store assigned companies for future company switch support
                                    $_SESSION['assigned_companies'] = $companies;

                                    header("Location: dashboard.php");
                                    exit;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="auth-shell">
    <div class="auth-left">
        <div class="auth-brand">
            <div class="logo">📘</div>
            <h1>TrustLedger – Accounting & Audit System for NGOs</h1>
            <p>
                TrustLedger is a structured financial management platform designed for
                Non-Profit Organizations to maintain accurate accounting records, ensure
                compliance, and support transparent auditing processes. The system enables
                efficient tracking of income, expenditures, assets, and liabilities while
                maintaining financial integrity across multiple entities.
            </p>

            <div class="auth-points">
                <div class="auth-point">✔ Accurate recording of Income & Expenditure transactions</div>
                <div class="auth-point">✔ Real-time tracking of Assets and Liabilities</div>
                <div class="auth-point">✔ Automated salary, EPF & ETF calculations</div>
                <div class="auth-point">✔ Cash and Bank account reconciliation</div>
                <div class="auth-point">✔ Structured financial reports (Income & Expenditure, Balance Sheet)</div>
                <div class="auth-point">✔ Auditor verification with supporting documents</div>
                <div class="auth-point">✔ Role-based access control for secure operations</div>
            </div>
        </div>
    </div>

    <div class="auth-right">
        <div class="auth-card">
            <h2>Welcome Back</h2>
            <p>Login to continue to your company workspace.</p>

            <?php if (!empty($_SESSION['pending_invite_token'])): ?>
            <div class="alert alert-warning">
                Auditor invite detected. Please login with the invited account to continue.
            </div>
            <?php endif; ?>

            <?php if ($message !== ''): ?>
            <div class="alert alert-<?= e($messageType) ?>">
                <?= e($message) ?>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>

                <div class="form-group full">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="Enter your email"
                        value="<?= e($_POST['email'] ?? '') ?>" required>
                </div>

                <div class="form-group full">
                    <label class="form-label">Password</label>
                    <div class="input-with-toggle">
                        <input type="password" name="password" id="loginPassword" class="form-control"
                            placeholder="Enter your password" required>
                        <button type="button" class="btn btn-light password-toggle" data-toggle-password="loginPassword"
                            aria-label="Toggle password visibility" title="Show password">
                            <span class="eye-icon">👁</span>
                        </button>
                    </div>
                </div>
                <br>
                <div class="form-group full">
                    <button type="submit" class="btn btn-primary btn-block">Login Now</button>
                </div>

                <div class="auth-footer">
                    Don’t have an account? <a
                        href="register.php<?= !empty($_SESSION['pending_invite_token']) ? '?token=' . urlencode($_SESSION['pending_invite_token']) : '' ?>">Create
                        one</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>