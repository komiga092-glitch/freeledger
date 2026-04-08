<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/security.php';

// Set security headers
set_security_headers();

if (isset($_SESSION['user_id']) && isset($_SESSION['company_id'])) {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Register';
$pageDescription = 'Create your professional accounting and audit system account with complete company setup';

$message = '';
$messageType = '';

/* Preserve invite token flow */
$pendingInviteToken = trim($_GET['token'] ?? $_SESSION['pending_invite_token'] ?? '');
if ($pendingInviteToken !== '') {
    $_SESSION['pending_invite_token'] = $pendingInviteToken;
}

// Check if there's a valid invite
$inviteData = null;
if ($pendingInviteToken !== '') {
    $stmt = $conn->prepare("
        SELECT ai.invite_id, ai.company_id, ai.invited_email, c.company_name, c.registration_no, c.email AS company_email, c.phone AS company_phone, c.address AS company_address
        FROM auditor_invites ai
        INNER JOIN companies c ON c.company_id = ai.company_id
        WHERE ai.invite_token = ?
          AND ai.status = 'pending'
          AND (ai.expires_at IS NULL OR ai.expires_at > NOW())
        LIMIT 1
    ");
    
    if ($stmt) {
        $stmt->bind_param("s", $pendingInviteToken);
        $stmt->execute();
        $result = $stmt->get_result();
        $inviteData = $result->fetch_assoc();
        $stmt->close();
    }
}

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

        $isInviteRegistration = ($inviteData !== null);
        
        // Validation
        if ($full_name === '' || $email === '' || $password === '' || $confirm_password === '') {
            $message = 'Please fill all required fields.';
            $messageType = 'danger';
        } elseif (!$isInviteRegistration && $company_name === '') {
            $message = 'Company name is required for new registrations.';
            $messageType = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'danger';
        } elseif ($isInviteRegistration && strtolower($email) !== strtolower($inviteData['invited_email'])) {
            $message = 'The email address does not match the invited email.';
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

                        $conn->begin_transaction();

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

                            if ($isInviteRegistration) {
                                // 2. Accept invite - assign as auditor to existing company
                                $company_id = (int)$inviteData['company_id'];
                                $role = 'auditor';

                                $stmt_access = $conn->prepare("
                                    INSERT INTO company_user_access (company_id, user_id, role_in_company, access_status)
                                    VALUES (?, ?, ?, 'Active')
                                ");

                                if (!$stmt_access) {
                                    throw new Exception('Failed to prepare company access insert.');
                                }

                                $stmt_access->bind_param("iis", $company_id, $new_user_id, $role);

                                if (!$stmt_access->execute()) {
                                    throw new Exception('Failed to assign auditor role.');
                                }

                                $stmt_access->close();

                                // 3. Update invite status
                                $stmt_update_invite = $conn->prepare("
                                    UPDATE auditor_invites
                                    SET status = 'accepted', accepted_at = NOW()
                                    WHERE invite_id = ?
                                ");

                                if ($stmt_update_invite) {
                                    $stmt_update_invite->bind_param("i", $inviteData['invite_id']);
                                    $stmt_update_invite->execute();
                                    $stmt_update_invite->close();
                                }

                                log_security_event(
                                    'auditor_invite_accepted',
                                    $new_user_id,
                                    null,
                                    $company_id,
                                    'Invite accepted during registration'
                                );

                                // Set company data from invite
                                $company_name = $inviteData['company_name'];
                                $registration_no = $inviteData['registration_no'];
                                $company_email = $inviteData['company_email'];
                                $company_phone = $inviteData['company_phone'];
                                $company_address = $inviteData['company_address'];
                            } else {
                                // 2. Create organization
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

                                // 3. Assign organization role
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
                            }

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

                            if (isset($_SESSION['pending_invite_token'])) {
                                unset($_SESSION['pending_invite_token']);
                            }

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
<?php include 'includes/header.php'; ?>

<div class="auth-shell">
    <div class="auth-left">
        <div class="auth-brand">
            <div class="logo">🏢</div>
            <h1>Create Your Account</h1>
            <p>
                Register a secure account for accountant or auditor workflows with
                multi-company support, invite-token onboarding, and complete company setup.
            </p>

            <div class="auth-points">
                <div class="auth-point">✅ app_users exact schema compatible</div>
                <div class="auth-point">✅ invite-token flow supported</div>
                <div class="auth-point">✅ password securely hashed</div>
                <div class="auth-point">✅ complete company information collection</div>
                <div class="auth-point">✅ ready for company add or invite acceptance</div>
            </div>
        </div>
    </div>

    <div class="auth-right">
        <div class="auth-card">
            <h2>Create Account</h2>
            <p>Fill the details below to create your account.</p>

            <?php if (!empty($_SESSION['pending_invite_token'])): ?>
            <div class="alert alert-warning">
                <?php if ($inviteData): ?>
                Auditor invite detected. You are registering to join <strong><?= e($inviteData['company_name']) ?></strong> as an auditor.
                <?php else: ?>
                Auditor invite detected. Register with the invited email to continue.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($message !== ''): ?>
            <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>

                <div class="form-group full">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control" placeholder="Enter full name"
                        value="<?= e($_POST['full_name'] ?? '') ?>" required>
                </div>

                <div class="form-group full">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="Enter email address"
                        value="<?= e($_POST['email'] ?? ($inviteData['invited_email'] ?? '')) ?>" required>
                </div>

                <?php if (!$inviteData): ?>
                <div class="form-group full">
                    <label class="form-label">Company Name</label>
                    <input type="text" name="company_name" class="form-control" placeholder="Enter company name"
                        value="<?= e($_POST['company_name'] ?? '') ?>" <?= $inviteData ? '' : 'required' ?>>
                </div>

                <div class="form-group full">
                    <label class="form-label">Registration Number</label>
                    <input type="text" name="registration_no" class="form-control"
                        placeholder="Enter registration number" value="<?= e($_POST['registration_no'] ?? '') ?>">
                </div>

                <div class="form-group full">
                    <label class="form-label">Company Email</label>
                    <input type="email" name="company_email" class="form-control" placeholder="Enter company email"
                        value="<?= e($_POST['company_email'] ?? '') ?>">
                </div>

                <div class="form-group full">
                    <label class="form-label">Company Phone</label>
                    <input type="text" name="company_phone" class="form-control" placeholder="Enter company phone"
                        value="<?= e($_POST['company_phone'] ?? '') ?>">
                </div>

                <div class="form-group full">
                    <label class="form-label">Company Address</label>
                    <textarea name="company_address" class="form-control" placeholder="Enter company address"
                        rows="3"><?= e($_POST['company_address'] ?? '') ?></textarea>
                </div>
                <?php endif; ?>

                <div class="form-group full">
                    <label class="form-label">Password</label>
                    <div class="input-with-toggle">
                        <input type="password" name="password" id="registerPassword" class="form-control"
                            placeholder="Enter password" required>
                        <button type="button" class="btn btn-light password-toggle"
                            data-toggle-password="registerPassword" aria-label="Show password" title="Show password">
                            <span class="eye-icon">👁</span>
                        </button>
                    </div>
                </div>

                <div class="form-group full">
                    <label class="form-label">Confirm Password</label>
                    <div class="input-with-toggle">
                        <input type="password" name="confirm_password" id="confirmPassword" class="form-control"
                            placeholder="Confirm password" required>
                        <button type="button" class="btn btn-light password-toggle"
                            data-toggle-password="confirmPassword" aria-label="Show password" title="Show password">
                            <span class="eye-icon">👁</span>
                        </button>
                    </div>
                </div>

                <br>

                <div class="form-group full">
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        Register Now
                    </button>
                </div>

                <br>

                <div class="auth-footer">
                    Already have an account?
                    <a
                        href="login.php<?= !empty($_SESSION['pending_invite_token']) ? '?token=' . urlencode($_SESSION['pending_invite_token']) : '' ?>">
                        Login here
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>