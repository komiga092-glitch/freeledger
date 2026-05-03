<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Output escaping
|--------------------------------------------------------------------------
*/
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| Session helpers
|--------------------------------------------------------------------------
*/
if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(): void
    {
        if (!isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('requireCompany')) {
    function requireCompany(): void
    {
        if (empty($_SESSION['company_id'])) {
            header('Location: select_company.php');
            exit;
        }
    }
}

if (!function_exists('currentUserId')) {
    function currentUserId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('currentCompanyId')) {
    function currentCompanyId(): int
    {
        return (int)($_SESSION['company_id'] ?? 0);
    }
}

/*
|--------------------------------------------------------------------------
| Role normalization
|--------------------------------------------------------------------------
*/
if (!function_exists('normalize_role_value')) {
    function normalize_role_value(string $role): string
    {
        $role = strtolower(trim($role));

        $map = [
            'owner'        => 'organization',
            'admin'        => 'organization',
            'organization' => 'organization',

            'manager'      => 'accountant',
            'employee'     => 'accountant',
            'accountant'   => 'accountant',

            'auditor'      => 'auditor',
            'external_auditor' => 'auditor',
        ];

        return $map[$role] ?? '';
    }
}

if (!function_exists('currentRole')) {
    function currentRole(): string
    {
        return normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));
    }
}

/*
|--------------------------------------------------------------------------
| Company role access
|--------------------------------------------------------------------------
*/
if (!function_exists('userHasCompanyRole')) {
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
FROM company_user_access cua
WHERE cua.user_id = ?
  AND cua.company_id = ?
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
}

if (!function_exists('requireRoles')) {
    function requireRoles(mysqli $conn, array $roles): void
    {
        requireLogin();
        requireCompany();

        if (!userHasCompanyRole($conn, currentUserId(), currentCompanyId(), $roles)) {
            http_response_code(403);
            die('Access denied');
        }
    }
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/
if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = [
            'type' => $type,
            'message' => $message
        ];
    }
}

if (!function_exists('getFlash')) {
    function getFlash(): ?array
    {
        if (empty($_SESSION['flash'])) {
            return null;
        }

        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return $flash;
    }
}

/*
|--------------------------------------------------------------------------
| Company helper
|--------------------------------------------------------------------------
*/
if (!function_exists('companyName')) {
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
}

/*
|--------------------------------------------------------------------------
| Salary helper
|--------------------------------------------------------------------------
*/
if (!function_exists('get_employee_current_basic_salary')) {
    function get_employee_current_basic_salary(
        mysqli $conn,
        int $company_id,
        int $employee_id,
        string $salary_date
    ): float {
        if ($company_id <= 0 || $employee_id <= 0 || $salary_date === '') {
            return 0.00;
        }

        $sql = "
            SELECT 
                e.total_salary,
                COALESCE(SUM(
                    CASE 
                        WHEN ei.status = 'Active' 
                         AND ei.increment_date <= ? 
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
            GROUP BY e.employee_id, e.total_salary
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return 0.00;
        }

        $stmt->bind_param("sii", $salary_date, $company_id, $employee_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;

        $stmt->close();

        if (!$row) {
            return 0.00;
        }

        return max(0.00, (float)$row['total_salary'] - (float)$row['total_increment']);
    }
}

/*
|--------------------------------------------------------------------------
| Notification System
|--------------------------------------------------------------------------
*/
if (!function_exists('create_notification')) {
    function create_notification(
        mysqli $conn,
        int $user_id,
        int $company_id,
        string $message,
        string $type = 'info',
        ?string $related_url = null
    ): bool {
        if ($user_id <= 0 || $company_id <= 0 || trim($message) === '') {
            return false;
        }

        $related_url = $related_url ?? '';
        $title = '';

        $stmt = $conn->prepare("
            INSERT INTO notifications (
                user_id,
                company_id,
                title,
                message,
                type,
                related_url
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "iissss",
            $user_id,
            $company_id,
            $title,
            $message,
            $type,
            $related_url
        );

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }
}

if (!function_exists('get_user_notifications')) {
    function get_user_notifications(mysqli $conn, int $user_id, int $limit = 50): array
    {
        if ($user_id <= 0) {
            return [];
        }

        $role = normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));

        if ($role === 'auditor') {
            $stmt = $conn->prepare("
                SELECT n.*, c.company_name
                FROM notifications n
                LEFT JOIN companies c ON n.company_id = c.company_id
                WHERE n.user_id = ?
                  AND n.type IN ('auditor_invite', 'auditor_assigned', 'auditor_cancel')
                ORDER BY n.created_at DESC
                LIMIT ?
            ");
        } else {
            $stmt = $conn->prepare("
                SELECT n.*, c.company_name
                FROM notifications n
                LEFT JOIN companies c ON n.company_id = c.company_id
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT ?
            ");
        }

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $notifications;
    }
}

if (!function_exists('mark_notification_read')) {
    function mark_notification_read(mysqli $conn, int $notification_id, int $user_id): bool
    {
        if ($notification_id <= 0 || $user_id <= 0) {
            return false;
        }

        $stmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE notification_id = ? AND user_id = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("ii", $notification_id, $user_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }
}


if (!function_exists('get_unread_notification_count')) {
    function get_unread_notification_count(mysqli $conn, int $user_id): int
    {
        if ($user_id <= 0) {
            return 0;
        }

        $role = normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));

        if ($role === 'auditor') {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS count
                FROM notifications
                WHERE user_id = ?
                  AND is_read = 0
                  AND type IN ('auditor_invite', 'auditor_assigned', 'auditor_cancel')
            ");
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS count
                FROM notifications
                WHERE user_id = ?
                  AND is_read = 0
            ");
        }

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }
}

if (!function_exists('check_and_create_due_notifications')) {
    function check_and_create_due_notifications(mysqli $conn, int $user_id, int $company_id): void
    {
        if ($user_id <= 0 || $company_id <= 0) {
            return;
        }

        $role = '';

        $stmt = $conn->prepare("
            SELECT role_in_company
            FROM company_user_access
            WHERE user_id = ?
              AND company_id = ?
              AND access_status = 'Active'
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("ii", $user_id, $company_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $role = normalize_role_value((string)($row['role_in_company'] ?? ''));
        }

        // Auditor should NOT receive due liability/receivable notifications.
        if (!in_array($role, ['organization', 'accountant'], true)) {
            return;
        }

        $today = date('Y-m-d');

        // Liability due notifications
      $stmt = $conn->prepare("
    SELECT liability_id, liability_name, due_date
    FROM liabilities
    WHERE company_id = ?
      AND due_date IS NOT NULL
      AND due_date <= ?
      AND balance_amount > 0
");

        if ($stmt) {
            $stmt->bind_param("is", $company_id, $today);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $liabilityId = (int)$row['liability_id'];
                $name = (string)$row['liability_name'];
                $dueDate = (string)$row['due_date'];

                $message = "Liability '{$name}' is due/overdue on {$dueDate}.";
                $related_url = "liabilities.php?edit={$liabilityId}";

                $check = $conn->prepare("
                    SELECT notification_id
                    FROM notifications
                    WHERE user_id = ?
                      AND company_id = ?
                      AND type = 'liability_due'
                      AND related_url = ?
                    LIMIT 1
                ");

                if ($check) {
                    $check->bind_param("iis", $user_id, $company_id, $related_url);
                    $check->execute();
                    $exists = $check->get_result()->fetch_assoc();
                    $check->close();

                    if (!$exists) {
                        create_notification($conn, $user_id, $company_id, $message, 'liability_due', $related_url);
                    }
                }
            }

            $stmt->close();
        }

        // Receivable due notifications
        $stmt = $conn->prepare("
            SELECT receivable_id, borrower_name, due_date
            FROM receivables
            WHERE company_id = ?
              AND due_date IS NOT NULL
              AND due_date IS NOT NULL
              AND due_date <= ?
              AND balance_amount > 0
        ");

        if ($stmt) {
            $stmt->bind_param("is", $company_id, $today);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $receivableId = (int)$row['receivable_id'];
                $name = (string)$row['borrower_name'];
                $dueDate = (string)$row['due_date'];

                $message = "Receivable from '{$name}' is due/overdue on {$dueDate}.";
                $related_url = "receivables.php?edit={$receivableId}";

                $check = $conn->prepare("
                    SELECT notification_id
                    FROM notifications
                    WHERE user_id = ?
                      AND company_id = ?
                      AND type = 'receivable_due'
                      AND related_url = ?
                    LIMIT 1
                ");

                if ($check) {
                    $check->bind_param("iis", $user_id, $company_id, $related_url);
                    $check->execute();
                    $exists = $check->get_result()->fetch_assoc();
                    $check->close();

                    if (!$exists) {
                        create_notification($conn, $user_id, $company_id, $message, 'receivable_due', $related_url);
                    }
                }
            }

            $stmt->close();
        }
    }

}