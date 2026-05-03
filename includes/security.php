<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
    $conn = $GLOBALS['conn'];
}

/* =========================
   SECURITY HEADERS
========================= */
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
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    }
}

/* optional: only call manually from pages if needed */
/* set_security_headers(); */

/* =========================
   ROLE NORMALIZATION
========================= */
if (!function_exists('normalize_role_value')) {
    function normalize_role_value(string $role): string
    {
        $role = strtolower(trim($role));

        $map = [
            'owner'        => 'organization',
            'admin'        => 'organization',
            'manager'      => 'accountant',
            'employee'     => 'accountant',
            'organization' => 'organization',
            'accountant'   => 'accountant',
            'auditor'      => 'auditor',
        ];

        return $map[$role] ?? '';
    }
}

/* =========================
   SESSION HELPERS
========================= */
if (!function_exists('current_user_id')) {
    function current_user_id(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('current_company_id')) {
    function current_company_id(): int
    {
        return (int)($_SESSION['company_id'] ?? 0);
    }
}

if (!function_exists('current_role')) {
    function current_role(): string
    {
        return normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));
    }
}

if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (current_user_id() <= 0) {
            header("Location: login.php");
            exit;
        }
    }
}

if (!function_exists('require_company')) {
    function require_company(): void
    {
        if (current_company_id() <= 0) {
            header("Location: select_company.php");
            exit;
        }
    }
}

if (!function_exists('require_role')) {
    function require_role(array $allowed_roles): void
    {
        $role = current_role();

        $allowed_roles = array_map(
            static fn($r) => normalize_role_value((string)$r),
            $allowed_roles
        );

        if (!in_array($role, $allowed_roles, true)) {
            header("Location: dashboard.php");
            exit;
        }
    }
}

/* =========================
   COMPANY ACCESS
========================= */
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

        $stmt->bind_param("ii", $user_id, $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return null;
        }

        $role = normalize_role_value((string)($row['role_in_company'] ?? ''));

        if ($role === '') {
            return null;
        }

        if (!empty($allowed_roles)) {
            $allowed_roles = array_map(
                static fn($r) => normalize_role_value((string)$r),
                $allowed_roles
            );

            if (!in_array($role, $allowed_roles, true)) {
                return null;
            }
        }

        return $role;
    }
}

if (!function_exists('user_has_company_access')) {
    function user_has_company_access(int $user_id, int $company_id): bool
    {
        return verify_user_role($user_id, $company_id) !== null;
    }
}

/* =========================
   CSRF - OLD + NEW COMPATIBLE
========================= */
if (!function_exists('get_csrf_token')) {
    function get_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return get_csrf_token();
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

if (!function_exists('csrf_input')) {
    function csrf_input(): string
    {
        return csrf_field();
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(string $token): bool
    {
        if ($token === '' || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals((string)$_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(): void
    {
        $token = (string)($_POST['csrf_token'] ?? '');

        if (!verify_csrf_token($token)) {
            die("Invalid CSRF token.");
        }
    }
}

/* =========================
   INPUT / FILE HELPERS
========================= */
if (!function_exists('clean')) {
    function clean(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_input')) {
    function sanitize_input(string $value, string $type = 'string')
    {
        switch ($type) {
            case 'email':
                return trim((string)filter_var($value, FILTER_SANITIZE_EMAIL));
            case 'int':
                return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
            case 'url':
                return trim((string)filter_var($value, FILTER_SANITIZE_URL));
            default:
                return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
        }
    }
}

if (!function_exists('validate_upload')) {
    function validate_upload(string $filename, int $size, array $allowed_ext): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext, true)) {
            return false;
        }

        if ($size > 5 * 1024 * 1024) {
            return false;
        }

        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return false;
        }

        return true;
    }
}
if (!function_exists('log_security_event')) {
    function log_security_event(string $event_type, ?int $actor_user_id = null, ?int $target_user_id = null, ?int $company_id = null, string $description = ''): void
    {
        global $conn;

        if (!isset($conn) || !($conn instanceof mysqli)) {
            return;
        }

        try {
            $check = $conn->query("SHOW TABLES LIKE 'audit_log'");
            if (!$check || $check->num_rows === 0) {
                return;
            }

            $cols = [];
            $res = $conn->query("SHOW COLUMNS FROM audit_log");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $cols[] = $r['Field'];
                }
            }

            $possible = [
                'event_type' => $event_type,
                'action' => $event_type,
                'actor_user_id' => $actor_user_id,
                'user_id' => $actor_user_id,
                'target_user_id' => $target_user_id,
                'company_id' => $company_id,
                'description' => $description,
                'details' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ];

            $data = [];
            foreach ($possible as $k => $v) {
                if (in_array($k, $cols, true)) {
                    $data[$k] = $v;
                }
            }

            if (!$data) {
                return;
            }

            $fields = array_keys($data);
            $sql = 'INSERT INTO audit_log (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return;
            }

            $types = '';
            $values = [];

            foreach ($data as $v) {
                $types .= is_int($v) || $v === null ? 'i' : 's';
                $values[] = $v;
            }

            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $stmt->close();

        } catch (Throwable $e) {
            error_log('Security log skipped: ' . $e->getMessage());
        }
    }
}