<?php
session_start();
require_once 'config/db.php';
require_once 'includes/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$company_id = (int)($_SESSION['company_id'] ?? 0);
$user_id    = (int)($_SESSION['user_id'] ?? 0);

$msg = '';
$msgType = 'success';

$currentRole = verify_user_role($user_id, $company_id);
$canCrud = in_array($currentRole, ['accountant', 'organization'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_increment'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'You are not allowed to add increments.';
        $msgType = 'danger';
    } else {
        $employee_id       = (int)($_POST['employee_id'] ?? 0);
        $increment_amount  = (float)($_POST['increment_amount'] ?? 0);
        $increment_date    = trim($_POST['increment_date'] ?? '');
        $reason            = trim($_POST['reason'] ?? '');

        if ($employee_id <= 0) {
            $msg = 'Please select an employee.';
            $msgType = 'danger';
        } elseif ($increment_amount <= 0) {
            $msg = 'Increment amount must be greater than zero.';
            $msgType = 'danger';
        } elseif ($increment_date === '') {
            $msg = 'Increment date is required.';
            $msgType = 'danger';
        } else {
            $check = $conn->prepare("
                SELECT employee_id
                FROM employees
                WHERE employee_id = ? AND company_id = ?
                LIMIT 1
            ");
            $check->bind_param("ii", $employee_id, $company_id);
            $check->execute();
            $res = $check->get_result();

            if ($res->num_rows === 0) {
                $msg = 'Invalid employee selected.';
                $msgType = 'danger';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO employee_increments
                    (company_id, employee_id, increment_amount, increment_date, reason)
                    VALUES (?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    $msg = 'Failed to prepare increment query.';
                    $msgType = 'danger';
                } else {
                    $stmt->bind_param(
                        "iidss",
                        $company_id,
                        $employee_id,
                        $increment_amount,
                        $increment_date,
                        $reason
                    );

                    if ($stmt->execute()) {
                        $msg = 'Increment added successfully.';
                        $msgType = 'success';
                    } else {
                        $msg = 'Failed to add increment.';
                        $msgType = 'danger';
                    }

                    $stmt->close();
                }
            }

            $check->close();
        }
    }
}
?>