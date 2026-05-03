<?php
declare(strict_types=1);

require_once 'config/db.php';
require_once 'includes/functions.php';

// Check for liabilities due today or overdue
$today = date('Y-m-d');

// Get all active liabilities due today or past due
$sql = "SELECT l.liability_id, l.company_id, l.liability_name, l.amount, l.due_date,
               c.company_name, c.email as company_email
        FROM liabilities l
        INNER JOIN companies c ON l.company_id = c.company_id
        WHERE l.status = 'Active'
        AND l.due_date <= ?
        AND l.due_date IS NOT NULL";

$notifications_created = 0;

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($liability = $result->fetch_assoc()) {
        $company_id = $liability['company_id'];
        $liability_name = $liability['liability_name'];
        $due_date = $liability['due_date'];
        $amount = $liability['amount'];

        // Check if notification already exists for this liability today
        $check_sql = "SELECT COUNT(*) as count FROM notifications
                     WHERE company_id = ?
                     AND type = 'liability_due'
                     AND DATE(created_at) = CURDATE()
                     AND message LIKE ?";

        $like_pattern = "%{$liability_name}%";

        if ($check_stmt = $conn->prepare($check_sql)) {
            $check_stmt->bind_param("is", $company_id, $like_pattern);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_row = $check_result->fetch_assoc();

            if ($check_row['count'] == 0) {
                // Get all users in this company (organization and accountant roles)
                $user_sql = "SELECT cua.user_id, u.full_name, u.email
                           FROM company_user_access cua
                           INNER JOIN app_users u ON cua.user_id = u.user_id
                           WHERE cua.company_id = ?
                           AND cua.access_status = 'Active'
                           AND cua.role_in_company IN ('organization', 'accountant')";

                if ($user_stmt = $conn->prepare($user_sql)) {
                    $user_stmt->bind_param("i", $company_id);
                    $user_stmt->execute();
                    $user_result = $user_stmt->get_result();

                    while ($user = $user_result->fetch_assoc()) {
                        $user_id = $user['user_id'];
                        $title = "Liability Due";
                        $type = "liability_due";
                        $message = "Liability '{$liability_name}' of amount $" . number_format((float)$amount, 2) . " is due on " . date('M j, Y', strtotime($due_date));

                        // Insert notification
                        $insert_sql = "INSERT INTO notifications (user_id, company_id, title, message, type) VALUES (?, ?, ?, ?, ?)";
                        if ($insert_stmt = $conn->prepare($insert_sql)) {
                            $insert_stmt->bind_param("iisss", $user_id, $company_id, $title, $message, $type);
                            $insert_stmt->execute();
                            $insert_stmt->close();
                            $notifications_created++;
                        }
                    }

                    $user_stmt->close();
                }
            }

            $check_stmt->close();
        }
    }

    $stmt->close();
}

echo "Liability due date notifications processed. Created: {$notifications_created}\n";
?>