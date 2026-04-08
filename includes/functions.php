<?php
declare(strict_types=1);

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireCompany(): void
{
    if (empty($_SESSION['company_id'])) {
        header('Location: select_company.php');
        exit;
    }
}

function currentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentCompanyId(): int
{
    return (int)($_SESSION['company_id'] ?? 0);
}

/**
 * Normalize old and new role values into current system roles.
 */
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

function currentRole(): string
{
    return normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));
}

function userHasCompanyRole(mysqli $conn, int $userId, int $companyId, array $roles): bool
{
    if ($userId <= 0 || $companyId <= 0 || empty($roles)) {
        return false;
    }

    $normalizedRoles = array_values(array_unique(array_filter(array_map(
        static fn($role) => normalize_role_value((string)$role),
        $roles
    ))));

    if (empty($normalizedRoles)) {
        return false;
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
        return false;
    }

    $stmt->bind_param('ii', $userId, $companyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return false;
    }

    $actualRole = normalize_role_value((string)($row['role_in_company'] ?? ''));

    return in_array($actualRole, $normalizedRoles, true);
}

function requireRoles(mysqli $conn, array $roles): void
{
    requireLogin();
    requireCompany();

    if (!userHasCompanyRole($conn, currentUserId(), currentCompanyId(), $roles)) {
        http_response_code(403);
        die('Access denied');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function companyName(mysqli $conn, int $companyId): string
{
    if ($companyId <= 0) {
        return '';
    }

    $stmt = $conn->prepare('SELECT company_name FROM companies WHERE company_id = ? LIMIT 1');

    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return (string)($row['company_name'] ?? '');
}

if (!function_exists('get_employee_current_basic_salary')) {
    function get_employee_current_basic_salary(mysqli $conn, int $company_id, int $employee_id, string $salary_date): float
    {
        $sql = "
            SELECT 
                e.basic_salary,
                COALESCE(SUM(
                    CASE 
                        WHEN ei.status = 'Active' AND ei.increment_date <= ? 
                        THEN ei.increment_amount
                        ELSE 0
                    END
                ), 0) AS total_increment
            FROM employees e
            LEFT JOIN employee_increments ei
                ON ei.employee_id = e.employee_id
               AND ei.company_id = e.company_id
            WHERE e.company_id = ?
              AND e.employee_id = ?
            GROUP BY e.employee_id, e.basic_salary
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $salary_date, $company_id, $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return 0.00;
        }

        return (float)$row['basic_salary'] + (float)$row['total_increment'];
    }
}