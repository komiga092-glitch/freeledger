<?php

declare(strict_types=1);

requireRoles($conn, ['accountant']); // ONLY accountant allowed

header('Content-Type: application/json');

$response = [
    'status' => 'error',
    'data' => null,
    'message' => ''
];

try {
    $employeeId = (int)($_GET['employee_id'] ?? 0);
    $companyId  = currentCompanyId();

    if ($employeeId <= 0) {
        throw new Exception('Invalid employee ID');
    }

    $stmt = $conn->prepare("
        SELECT 
            employee_id,
                     employee_name,
                     basic_salary
        FROM employees
        WHERE employee_id = ? 
          AND company_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Database prepare failed');
    }

    $stmt->bind_param('ii', $employeeId, $companyId);
    $stmt->execute();

    $result = $stmt->get_result();
    $data = $result->fetch_assoc();

    if (!$data) {
        throw new Exception('Employee not found');
    }

    $response['status'] = 'success';
    $response['data'] = $data;
} catch (Throwable $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
