<?php
declare(strict_types=1);

/**
 * Security Functions Library
 * Provides CSRF protection, session validation, rate limiting,
 * security headers, and role-based access control.
 */

/* =========================================================
   DB CONNECTION
========================================================= */
if (!isset($conn) && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
    $conn = $GLOBALS['conn'];
}

/* =========================================================
   ROLE NORMALIZATION
========================================================= */
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

/* =========================================================
   CSRF
========================================================= */
if (!function_exists('get_csrf_token')) {
    function get_csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' .
            htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') .
            '">';
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($token === '' || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals((string) $_SESSION['csrf_token'], $token);
    }
}

/* =========================================================
   SESSION HELPERS
========================================================= */
if (!function_exists('current_session_user_id')) {
    function current_session_user_id(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('current_session_company_id')) {
    function current_session_company_id(): int
    {
        return (int) ($_SESSION['company_id'] ?? 0);
    }
}

if (!function_exists('current_session_role')) {
    function current_session_role(): string
    {
        return normalize_role_value((string) ($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));
    }
}

/* =========================================================
   ROLE / ACCESS
========================================================= */
if (!function_exists('verify_user_role')) {
    function verify_user_role(int $user_id, int $company_id, array $allowed_roles = []): ?string
    {
        global $conn;

        if (!isset($conn) || !($conn instanceof mysqli)) {
            return null;
        }

        if ($user_id <= 0 || $company_id <= 0) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT role_in_company
            FROM company_user_access
            WHERE user_id = ?
              AND company_id = ?
              AND access_status = 'Active'
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("ii", $user_id, $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        if (!$result) {
            return null;
        }

        $row = $result->fetch_assoc();
        if (!$row) {
            return null;
        }

        $role = normalize_role_value((string) ($row['role_in_company'] ?? ''));
        if ($role === '') {
            return null;
        }

        if (!empty($allowed_roles)) {
            $allowed_roles = array_values(array_unique(array_filter(array_map(
                static fn($r) => normalize_role_value((string) $r),
                $allowed_roles
            ))));

            if (empty($allowed_roles) || !in_array($role, $allowed_roles, true)) {
                return null;
            }
        }

        return $role;
    }
}

if (!function_exists('user_has_company_access')) {
    function user_has_company_access(int $user_id, int $company_id): bool
    {
        global $conn;

        if (!isset($conn) || !($conn instanceof mysqli)) {
            return false;
        }

        if ($user_id <= 0 || $company_id <= 0) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT 1
            FROM company_user_access
            WHERE user_id = ?
              AND company_id = ?
              AND access_status = 'Active'
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("ii", $user_id, $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}

if (!function_exists('user_can')) {
    function user_can(int $user_id, int $company_id, array $required_roles = []): bool
    {
        if ($user_id <= 0 || $company_id <= 0) {
            return false;
        }

        if (empty($required_roles)) {
            return user_has_company_access($user_id, $company_id);
        }

        return verify_user_role($user_id, $company_id, $required_roles) !== null;
    }
}

if (!function_exists('is_organization')) {
    function is_organization(int $user_id, int $company_id): bool
    {
        return user_can($user_id, $company_id, ['organization']);
    }
}

if (!function_exists('is_owner')) {
    function is_owner(int $user_id, int $company_id): bool
    {
        return is_organization($user_id, $company_id);
    }
}

if (!function_exists('is_accountant')) {
    function is_accountant(int $user_id, int $company_id): bool
    {
        return user_can($user_id, $company_id, ['accountant']);
    }
}

if (!function_exists('is_manager')) {
    function is_manager(int $user_id, int $company_id): bool
    {
        return is_accountant($user_id, $company_id);
    }
}

if (!function_exists('is_employee')) {
    function is_employee(int $user_id, int $company_id): bool
    {
        return is_accountant($user_id, $company_id);
    }
}

if (!function_exists('is_auditor')) {
    function is_auditor(int $user_id, int $company_id): bool
    {
        return user_can($user_id, $company_id, ['auditor']);
    }
}

if (!function_exists('require_role')) {
    function require_role(
        int $user_id,
        int $company_id,
        array $required_roles = [],
        string $redirect_page = 'dashboard.php'
    ): void {
        if (!user_can($user_id, $company_id, $required_roles)) {
            header('Location: ' . $redirect_page);
            exit;
        }
    }
}

if (!function_exists('get_role_permissions')) {
    function get_role_permissions(string $role): array
    {
        $role = normalize_role_value($role);

        return [
            'role'                  => $role,
            'can_view_transactions' => in_array($role, ['organization', 'auditor', 'accountant'], true),
            'can_crud_transactions' => ($role === 'accountant'),
            'can_view_reports'      => in_array($role, ['organization', 'auditor', 'accountant'], true),
            'can_view_audit_menus'  => ($role === 'auditor'),
            'can_manage_users'      => ($role === 'organization'),
        ];
    }
}

/* =========================================================
   PASSWORD VALIDATION
========================================================= */
if (!function_exists('validate_password_strength')) {
    function validate_password_strength(string $password): array
    {
        $errors = [];

        if (strlen($password) < 6 || strlen($password) > 15) {
            $errors[] = 'Password must be between 6 and 15 characters.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one digit.';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }
}

/* =========================================================
   AUDIT / LOGIN ATTEMPTS
========================================================= */
if (!function_exists('log_security_event')) {
    function log_security_event(
        string $event_type,
        int $user_id,
        ?int $affected_user_id = null,
        ?int $company_id = null,
        string $details = ''
    ): bool {
        global $conn;

        if (!isset($conn) || !($conn instanceof mysqli) || $user_id <= 0) {
            return false;
        }

        $ip_address = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'), 0, 45);
        $user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 255);

        $stmt = $conn->prepare("
            INSERT INTO audit_log (
                event_type,
                user_id,
                affected_user_id,
                company_id,
                ip_address,
                user_agent,
                details
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "siiiiss",
            $event_type,
            $user_id,
            $affected_user_id,
            $company_id,
            $ip_address,
            $user_agent,
            $details
        );

        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('check_login_rate_limit')) {
    function check_login_rate_limit(string $email): array
    {
        global $conn;

        if (!isset($conn) || !($conn instanceof mysqli)) {
            return ['allowed' => true, 'message' => '', 'retry_after' => 0];
        }

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $time_str = date('Y-m-d H:i:s', strtotime('-15 minutes'));

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS failed_count
            FROM login_attempts
            WHERE email = ?
              AND ip_address = ?
              AND success = 0
              AND attempt_time > ?
        ");

        if (!$stmt) {
            return ['allowed' => true, 'message' => '', 'retry_after' => 0];
        }

        $stmt->bind_param("sss", $email, $ip_address, $time_str);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            if ((int) ($row['failed_count'] ?? 0) >= 5) {
                return [
                    'allowed'     => false,
                    'message'     => 'Too many failed login attempts. Please try again in 15 minutes.',
                    'retry_after' => 900,
                ];
            }
        }

        return ['allowed' => true, 'message' => '', 'retry_after' => 0];
    }
}

if (!function_exists('record_login_attempt')) {
    function record_login_attempt(string $email, bool $success = false): void
    {
        global $conn;

        if (!isset($conn) || !($conn instanceof mysqli)) {
            return;
        }

        $ip_address = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'), 0, 45);
        $success_int = $success ? 1 : 0;

        $stmt = $conn->prepare("
            INSERT INTO login_attempts (email, ip_address, success)
            VALUES (?, ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param("ssi", $email, $ip_address, $success_int);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/* =========================================================
   SECURITY HEADERS
========================================================= */
if (!function_exists('set_security_headers')) {
    function set_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline'; " .
            "style-src 'self' 'unsafe-inline'; " .
            "img-src 'self' data:; " .
            "font-src 'self' data:; " .
            "connect-src 'self'; " .
            "frame-ancestors 'self'; " .
            "base-uri 'self'; " .
            "form-action 'self';"
        );

        if (
            !empty($_SERVER['HTTPS']) &&
            strtolower((string) $_SERVER['HTTPS']) !== 'off'
        ) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

/* =========================================================
   INPUT SANITIZE
========================================================= */
if (!function_exists('sanitize_input')) {
    function sanitize_input(string $value, string $type = 'string')
    {
        switch ($type) {
            case 'email':
                return trim((string) filter_var($value, FILTER_SANITIZE_EMAIL));

            case 'int':
                return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);

            case 'url':
                return trim((string) filter_var($value, FILTER_SANITIZE_URL));

            case 'string':
            default:
                return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
        }
    }
}