<?php

declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

set_security_headers();

if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_id']) <= 0) {
    header("Location: login.php");
    exit;
}

$company_id = (int)($_SESSION['company_id'] ?? 0);
$user_id    = (int)($_SESSION['user_id'] ?? 0);

if ($company_id <= 0) {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Salary Management';
$pageDescription = 'Manage employee salary payments';

$msg = '';
$msgType = 'success';

$currentRole = verify_user_role($user_id, $company_id);
$currentRole = normalize_role_value($currentRole);

$canView = in_array($currentRole, ['organization', 'accountant', 'auditor'], true);
$canCrud = $currentRole === 'accountant'; // Only accountant can CRUD

if (!$canView) {
    header("Location: dashboard.php");
    exit;
}

/* =========================
   HELPERS
========================= */
function resetSalaryEdit(): array
{
    return [
        'salary_id' => '',
        'employee_id' => '',
        'salary_amount' => '',
        'bonus' => '0.00',
        'allowance' => '0.00',
        'deduction' => '0.00',
        'epf_employee' => '0.00',
        'epf_employer' => '0.00',
        'etf_employer' => '0.00',
        'gross_salary' => '0.00',
        'net_salary' => '0.00',
        'salary_date' => date('Y-m-d'),
        'description' => '',
        'payment_source' => 'Cash',
        'bank_name' => '',
        'account_number' => '',
        'proof_file' => ''
    ];
}

function failIfPrepareFalse($stmt, string $context = 'Database prepare failed'): mysqli_stmt
{
    if ($stmt === false) {
        throw new RuntimeException($context);
    }
    return $stmt;
}

function hasDuplicateSalaryForMonth(mysqli $conn, int $company_id, int $employee_id, string $salary_date, int $exclude_salary_id = 0): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $salary_date);
    if ($date === false) {
        return false;
    }

    $year = (int)$date->format('Y');
    $month = (int)$date->format('m');

    $query = "SELECT 1 FROM salaries WHERE company_id = ? AND employee_id = ? AND YEAR(salary_date) = ? AND MONTH(salary_date) = ?";
    if ($exclude_salary_id > 0) {
        $query .= " AND salary_id != ?";
    }
    $query .= " LIMIT 1";

    $stmt = failIfPrepareFalse(
        $conn->prepare($query),
        'Failed to prepare duplicate salary check query.'
    );

    if ($exclude_salary_id > 0) {
        $stmt->bind_param('iiiii', $company_id, $employee_id, $year, $month, $exclude_salary_id);
    } else {
        $stmt->bind_param('iiii', $company_id, $employee_id, $year, $month);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Duplicate salary check failed: ' . $stmt->error);
    }

    $stmt->store_result();
    $hasDuplicate = $stmt->num_rows > 0;
    $stmt->close();

    return $hasDuplicate;
}

function isValidIsoDate(string $date): bool
{
    $dateTime = DateTime::createFromFormat('Y-m-d', $date);
    return $dateTime !== false && $dateTime->format('Y-m-d') === $date;
}

function deleteSalaryPaymentEntries(mysqli $conn, int $salary_id, int $company_id): void
{
    $stmt = failIfPrepareFalse(
        $conn->prepare("DELETE FROM cash_account WHERE salary_id = ? AND company_id = ?"),
        'Failed to prepare cash account delete query.'
    );
    $stmt->bind_param("ii", $salary_id, $company_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Cash account delete failed: ' . $stmt->error);
    }
    $stmt->close();

    $stmt = failIfPrepareFalse(
        $conn->prepare("DELETE FROM bank_account WHERE salary_id = ? AND company_id = ?"),
        'Failed to prepare bank account delete query.'
    );
    $stmt->bind_param("ii", $salary_id, $company_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Bank account delete failed: ' . $stmt->error);
    }
    $stmt->close();
}

function handleSalaryProofUpload(array $file): string
{
    if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Salary proof upload failed with error code ' . $file['error']);
    }

    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
    $maxFileSize = 5 * 1024 * 1024; // 5 MB

    $originalName = basename($file['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Invalid proof file type. Allowed types: PDF, JPG, JPEG, PNG, GIF.');
    }

    if ($file['size'] > $maxFileSize) {
        throw new RuntimeException('Proof file exceeds maximum size of 5 MB.');
    }

    $uploadDir = __DIR__ . '/uploads/transactions';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create upload directory for salary proofs.');
    }

    $targetName = uniqid('salary_proof_', true) . '.' . $extension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Failed to move uploaded proof file.');
    }

    return $targetName;
}

function insertSalaryPaymentEntry(
    mysqli $conn,
    int $salary_id,
    int $company_id,
    string $salary_date,
    string $payment_source,
    string $employeeName,
    float $paid_amount,
    string $bank_name,
    string $account_number
): void {
    $payment_description = '[SALARY:' . $salary_id . '] Salary paid - ' . $employeeName;
    
    if ($payment_source === 'Cash') {
        $stmt = failIfPrepareFalse(
            $conn->prepare("
                INSERT INTO cash_account (
                    salary_id,
                    company_id,
                    transaction_date,
                    description,
                    transaction_type,
                    amount
                )
                VALUES (?, ?, ?, ?, 'Cash Out', ?)
            "),
            'Failed to prepare cash account insert query.'
        );

        $stmt->bind_param(
            "iissd",
            $salary_id,
            $company_id,
            $salary_date,
            $payment_description,
            $paid_amount
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Cash account insert failed: ' . $stmt->error);
        }
        $stmt->close();
    } else {
        $stmt = failIfPrepareFalse(
            $conn->prepare("
                INSERT INTO bank_account (
                    salary_id,
                    company_id,
                    transaction_date,
                    description,
                    amount,
                    transaction_type,
                    bank_name,
                    account_number
                )
                VALUES (?, ?, ?, ?, ?, 'Withdrawal', ?, ?)
            "),
            'Failed to prepare bank account insert query.'
        );

        $stmt->bind_param(
            "iissdss",
            $salary_id,
            $company_id,
            $salary_date,
            $payment_description,
            $paid_amount,
            $bank_name,
            $account_number
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Bank account insert failed: ' . $stmt->error);
        }
        $stmt->close();
    }
}

/* =========================
   FETCH EMPLOYEES
========================= */
$employees = [];

try {
    $stmt = failIfPrepareFalse(
        $conn->prepare("
            SELECT employee_id, employee_name, position, total_salary, nic, phone, email, basic_salary, increment, join_date
            FROM employees
            WHERE company_id = ?
            ORDER BY employee_name ASC
        "),
        'Failed to prepare employee fetch query.'
    );

    $stmt->bind_param("i", $company_id);

    if (!$stmt->execute()) {
        throw new RuntimeException('Employee fetch failed: ' . $stmt->error);
    }

    $stmt->bind_result($emp_id, $emp_name, $emp_position, $emp_total_salary, $emp_nic, $emp_phone, $emp_email, $emp_basic_salary, $emp_increment, $emp_join_date);

    while ($stmt->fetch()) {
        $employees[] = [
            'employee_id' => $emp_id,
            'employee_name' => $emp_name,
            'position' => $emp_position,
            'total_salary' => $emp_total_salary,
            'nic' => $emp_nic,
            'phone' => $emp_phone,
            'email' => $emp_email,
            'basic_salary' => $emp_basic_salary,
            'increment' => $emp_increment,
            'join_date' => $emp_join_date
        ];
    }

    $stmt->close();
} catch (Throwable $e) {
    $msg = 'Failed to load employees: ' . $e->getMessage();
    $msgType = 'danger';
}

/* =========================
   EDIT DEFAULTS
========================= */
$edit_mode = false;
$edit = resetSalaryEdit();

/* =========================
   EDIT FETCH
========================= */
if (isset($_GET['edit'])) {
    $salary_id = (int)($_GET['edit'] ?? 0);

    if ($salary_id > 0) {
        try {
            $stmt = failIfPrepareFalse(
                $conn->prepare("
                    SELECT 
                        s.salary_id,
                        s.employee_id,
                        s.salary_amount,
                        s.bonus,
                        s.allowance,
                        s.deduction,
                        s.epf_employee,
                        s.epf_employer,
                        s.etf_employer,
                        s.gross_salary,
                        s.net_salary,
                        s.salary_date,
                        s.description,
                        s.payment_source,
                        s.bank_name,
                        s.account_number,
                        s.proof_file
                    FROM salaries s
                    INNER JOIN employees e ON s.employee_id = e.employee_id
                    WHERE s.salary_id = ? AND e.company_id = ?
                    LIMIT 1
                "),
                'Failed to prepare salary edit fetch query.'
            );

            $stmt->bind_param("ii", $salary_id, $company_id);

            if (!$stmt->execute()) {
                throw new RuntimeException('Salary edit fetch failed: ' . $stmt->error);
            }

            $stmt->bind_result(
                $edit_salary_id,
                $edit_employee_id,
                $edit_salary_amount,
                $edit_bonus,
                $edit_allowance,
                $edit_deduction,
                $edit_epf_employee,
                $edit_epf_employer,
                $edit_etf_employer,
                $edit_gross_salary,
                $edit_net_salary,
                $edit_salary_date,
                $edit_description,
                $edit_payment_source,
                $edit_bank_name,
                $edit_account_number,
                $edit_proof_file
            );

            if ($stmt->fetch()) {
                $edit = [
                    'salary_id' => $edit_salary_id,
                    'employee_id' => $edit_employee_id,
                    'salary_amount' => $edit_salary_amount,
                    'bonus' => $edit_bonus,
                    'allowance' => $edit_allowance,
                    'deduction' => $edit_deduction,
                    'epf_employee' => $edit_epf_employee,
                    'epf_employer' => $edit_epf_employer,
                    'etf_employer' => $edit_etf_employer,
                    'gross_salary' => $edit_gross_salary,
                    'net_salary' => $edit_net_salary,
                    'salary_date' => $edit_salary_date,
                    'description' => $edit_description,
                    'payment_source' => $edit_payment_source,
                    'bank_name' => $edit_bank_name,
                    'account_number' => $edit_account_number,
                    'proof_file' => $edit_proof_file
                ];
                $edit_mode = true;
            } else {
                $msg = 'Salary record not found.';
                $msgType = 'danger';
            }

            $stmt->close();
        } catch (Throwable $e) {
            $msg = 'Failed to load salary record: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

/* =========================
   ADD / UPDATE SALARY + EXPENSE + CASH/BANK
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_salary'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can create or edit salary records.';
        $msgType = 'danger';
    } else {
        $salary_id       = (int)($_POST['salary_id'] ?? 0);
        $employee_id     = (int)($_POST['employee_id'] ?? 0);
        $salary_amount   = (float)($_POST['salary_amount'] ?? 0);
        $bonus           = (float)($_POST['bonus'] ?? 0);
        $allowance       = (float)($_POST['allowance'] ?? 0);
        $deduction       = (float)($_POST['deduction'] ?? 0);
        $salary_date     = trim($_POST['salary_date'] ?? '');
        $description     = trim($_POST['description'] ?? '');
        $payment_source  = trim($_POST['payment_source'] ?? 'Cash');
        $bank_name       = trim($_POST['bank_name'] ?? '');
        $account_number  = trim($_POST['account_number'] ?? '');
        $existing_proof_file = trim($_POST['existing_proof_file'] ?? '');
        $proof_file = $existing_proof_file;

        if (!in_array($payment_source, ['Cash', 'Bank'], true)) {
            $payment_source = 'Cash';
        }

        if ($payment_source !== 'Bank') {
            $bank_name = '';
            $account_number = '';
        }

        $employeeExists = false;
        $employeeName = '';
        $validatedTotalSalary = 0.0;

        try {
            $stmt = failIfPrepareFalse(
                $conn->prepare("
                    SELECT employee_name, total_salary
                    FROM employees
                    WHERE employee_id = ? AND company_id = ?
                    LIMIT 1
                "),
                'Failed to prepare employee validation query.'
            );

            $stmt->bind_param("ii", $employee_id, $company_id);

            if (!$stmt->execute()) {
                throw new RuntimeException('Employee validation failed: ' . $stmt->error);
            }

            $stmt->bind_result($validatedEmployeeName, $validatedTotalSalary);

            if ($stmt->fetch()) {
                $employeeExists = true;
                $employeeName = (string)$validatedEmployeeName;
            }

            $stmt->close();

            if ($employeeExists) {
                $salary_amount = $validatedTotalSalary;
            }

            if (isset($_FILES['proof_file']) && ($_FILES['proof_file']['error'] !== UPLOAD_ERR_NO_FILE || !empty($_FILES['proof_file']['name']))) {
                $proof_file = handleSalaryProofUpload($_FILES['proof_file']);
                if ($salary_id > 0 && $existing_proof_file !== '' && $existing_proof_file !== $proof_file) {
                    $existingPath = __DIR__ . '/uploads/transactions/' . $existing_proof_file;
                    if (is_file($existingPath)) {
                        @unlink($existingPath);
                    }
                }
            }
  /* =========================
   AUTO ADVANCE DEDUCTION
========================= */
$advance_id = 0;
$advance_deduction = 0.00;

$stmt = $conn->prepare("
    SELECT advance_id, balance_amount, monthly_deduction
    FROM employee_advances
    WHERE company_id = ?
      AND employee_id = ?
      AND status = 'Active'
      AND balance_amount > 0
    ORDER BY advance_id ASC
    LIMIT 1
");

$stmt->bind_param("ii", $company_id, $employee_id);
$stmt->execute();
$res = $stmt->get_result();
$advance = $res->fetch_assoc();
$stmt->close();

if ($advance && $salary_id <= 0) {
    $advance_id = (int)$advance['advance_id'];
    $advance_deduction = min(
        (float)$advance['monthly_deduction'],
        (float)$advance['balance_amount']
    );

    $deduction += $advance_deduction;
}

            $gross_salary = $salary_amount + $bonus + $allowance;
            $epf_employee = $salary_amount * 0.08;
            $epf_employer = $salary_amount * 0.12;
            $etf_employer = $salary_amount * 0.03;
            $net_salary   = $gross_salary - $epf_employee - $deduction;

            $expense_amount = $net_salary + $epf_employer + $etf_employer;
            $paid_amount = $net_salary;

            $expense_type = 'Salary';
            $expense_description = 'Salary payment - ' . $employeeName;

            if (!$employeeExists) {
                $msg = 'Selected employee is invalid.';
                $msgType = 'danger';
            } elseif ($employee_id <= 0 || $salary_amount <= 0 || $salary_date === '') {
                $msg = 'Please fill all required fields correctly.';
                $msgType = 'danger';
            } elseif (!isValidIsoDate($salary_date)) {
                $msg = 'Salary date is invalid. Please use YYYY-MM-DD format.';
                $msgType = 'danger';
            } elseif ($bonus < 0 || $allowance < 0 || $deduction < 0) {
                $msg = 'Bonus, allowance, and deduction must be zero or positive values.';
                $msgType = 'danger';
            } elseif (abs($salary_amount - $validatedTotalSalary) > 0.0001) {
                $msg = 'Salary amount does not match the selected employee\'s total salary.';
                $msgType = 'danger';
            } elseif ($payment_source === 'Bank' && ($bank_name === '' || $account_number === '')) {
                $msg = 'Bank name and account number are required for bank payment.';
                $msgType = 'danger';
            } elseif ($net_salary < 0) {
                $msg = 'Net salary cannot be negative.';
                $msgType = 'danger';
            } elseif (hasDuplicateSalaryForMonth($conn, $company_id, $employee_id, $salary_date, $salary_id)) {
                $msg = 'A salary record for this employee already exists for the selected month.';
                $msgType = 'danger';
            } else {
                $conn->begin_transaction();

                try {
                    if ($salary_id > 0) {
                        $stmt = failIfPrepareFalse(
                            $conn->prepare("
                                UPDATE salaries s
                                INNER JOIN employees e ON s.employee_id = e.employee_id
                                SET s.employee_id = ?,
                                    s.salary_amount = ?,
                                    s.bonus = ?,
                                    s.allowance = ?,
                                    s.deduction = ?,
                                    s.epf_employee = ?,
                                    s.epf_employer = ?,
                                    s.etf_employer = ?,
                                    s.gross_salary = ?,
                                    s.net_salary = ?,
                                    s.salary_date = ?,
                                    s.description = ?,
                                    s.proof_file = ?,
                                    s.payment_source = ?,
                                    s.bank_name = ?,
                                    s.account_number = ?
                                WHERE s.salary_id = ? AND e.company_id = ?
                            "),
                            'Failed to prepare salary update query.'
                        );

                        $stmt->bind_param(
                            "idddddddddssssssii",
                            $employee_id,
                            $salary_amount,
                            $bonus,
                            $allowance,
                            $deduction,
                            $epf_employee,
                            $epf_employer,
                            $etf_employer,
                            $gross_salary,
                            $net_salary,
                            $salary_date,
                            $description,
                            $proof_file,
                            $payment_source,
                            $bank_name,
                            $account_number,
                            $salary_id,
                            $company_id
                        );

                        if (!$stmt->execute()) {
                            throw new RuntimeException('Salary update failed: ' . $stmt->error);
                        }
                        $stmt->close();

                        $stmt = failIfPrepareFalse(
                            $conn->prepare("
                                UPDATE expenses
                                SET expense_type = ?,
                                    amount = ?,
                                    expense_date = ?,
                                    description = ?,
                                    payment_source = ?,
                                    bank_name = ?,
                                    account_number = ?
                                WHERE salary_id = ? AND company_id = ?
                            "),
                            'Failed to prepare expense update query.'
                        );

                        $stmt->bind_param(
                            "sdsssssii",
                            $expense_type,
                            $expense_amount,
                            $salary_date,
                            $expense_description,
                            $payment_source,
                            $bank_name,
                            $account_number,
                            $salary_id,
                            $company_id
                        );

                        if (!$stmt->execute()) {
                            throw new RuntimeException('Expense update failed: ' . $stmt->error);
                        }

                        $expenseRows = $stmt->affected_rows;
                        $stmt->close();

                        if ($expenseRows === 0) {
                            $stmt = failIfPrepareFalse(
                                $conn->prepare("
                                    INSERT INTO expenses (
                                        salary_id,
                                        company_id,
                                        expense_type,
                                        amount,
                                        expense_date,
                                        description,
                                        payment_source,
                                        bank_name,
                                        account_number
                                    )
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                                "),
                                'Failed to prepare missing expense insert query.'
                            );

                            $stmt->bind_param(
                                "iisdsssss",
                                $salary_id,
                                $company_id,
                                $expense_type,
                                $expense_amount,
                                $salary_date,
                                $expense_description,
                                $payment_source,
                                $bank_name,
                                $account_number
                            );

                            if (!$stmt->execute()) {
                                throw new RuntimeException('Missing expense insert failed: ' . $stmt->error);
                            }
                            $stmt->close();
                        }

                        deleteSalaryPaymentEntries($conn, $salary_id, $company_id);

                        insertSalaryPaymentEntry(
                            $conn,
                            $salary_id,
                            $company_id,
                            $salary_date,
                            $payment_source,
                            $employeeName,
                            $paid_amount,
                            $bank_name,
                            $account_number
                        );

                        $msg = 'Salary record updated successfully.';
                        $msgType = 'success';
                        $edit_mode = false;
                        $edit = resetSalaryEdit();
                    } else {
                        $stmt = failIfPrepareFalse(
                            $conn->prepare("
                                INSERT INTO salaries (
                                    company_id,
                                    employee_id,
                                    salary_amount,
                                    bonus,
                                    allowance,
                                    deduction,
                                    epf_employee,
                                    epf_employer,
                                    etf_employer,
                                    gross_salary,
                                    net_salary,
                                    salary_date,
                                    description,
                                    proof_file,
                                    payment_source,
                                    bank_name,
                                    account_number
                                )
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            "),
                            'Failed to prepare salary insert query.'
                        );

                        $stmt->bind_param(
                           "iidddddddddssssss",
                            $company_id,
                            $employee_id,
                            $salary_amount,
                            $bonus,
                            $allowance,
                            $deduction,
                            $epf_employee,
                            $epf_employer,
                            $etf_employer,
                            $gross_salary,
                            $net_salary,
                            $salary_date,
                            $description,
                            $proof_file,
                            $payment_source,
                            $bank_name,
                            $account_number
                        );

                        if (!$stmt->execute()) {
                            throw new RuntimeException('Salary insert failed: ' . $stmt->error);
                        }

                        $new_salary_id = (int)$stmt->insert_id;
                        $stmt->close();

                        $stmt = failIfPrepareFalse(
                            $conn->prepare("
                                INSERT INTO expenses (
                                    salary_id,
                                    company_id,
                                    expense_type,
                                    amount,
                                    expense_date,
                                    description,
                                    payment_source,
                                    bank_name,
                                    account_number
                                )
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            "),
                            'Failed to prepare expense insert query.'
                        );

                        $stmt->bind_param(
                            "iisdsssss",
                            $new_salary_id,
                            $company_id,
                            $expense_type,
                            $expense_amount,
                            $salary_date,
                            $expense_description,
                            $payment_source,
                            $bank_name,
                            $account_number
                        );

                        if (!$stmt->execute()) {
                            throw new RuntimeException('Expense insert failed: ' . $stmt->error);
                        }
                        $stmt->close();

                        insertSalaryPaymentEntry(
                            $conn,
                            $new_salary_id,
                            $company_id,
                            $salary_date,
                            $payment_source,
                            $employeeName,
                            $paid_amount,
                            $bank_name,
                            $account_number
                        );

                        $msg = 'Salary record added successfully.';
                        $msgType = 'success';
                        $edit_mode = false;
                        $edit = resetSalaryEdit();
                    }

                    /* =========================
   UPDATE ADVANCE AFTER SALARY
========================= */
if ($advance_id > 0 && $advance_deduction > 0) {

    $stmt = $conn->prepare("
        UPDATE employee_advances
        SET 
            paid_amount = paid_amount + ?,
            balance_amount = balance_amount - ?
        WHERE advance_id = ? AND company_id = ?
    ");

    $stmt->bind_param("ddii", $advance_deduction, $advance_deduction, $advance_id, $company_id);
    $stmt->execute();
    $stmt->close();

    // முழுசா settle ஆயிட்டா Completed
    $stmt = $conn->prepare("
        UPDATE employee_advances
        SET status = 'Completed'
        WHERE advance_id = ? AND balance_amount <= 0
    ");

    $stmt->bind_param("i", $advance_id);
    $stmt->execute();
    $stmt->close();
}



                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    $msg = 'Failed to save salary record: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }
        } catch (Throwable $e) {
            $msg = 'Failed to validate employee or salary data: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

/* =========================
   DELETE SALARY + EXPENSE + CASH/BANK
========================= */
if (isset($_GET['delete'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can delete salary records.';
        $msgType = 'danger';
    } else {
        $salary_id = (int)($_GET['delete'] ?? 0);

        if ($salary_id > 0) {
            $conn->begin_transaction();

            try {
                deleteSalaryPaymentEntries($conn, $salary_id, $company_id);

                $stmt = failIfPrepareFalse(
                    $conn->prepare("
                        DELETE FROM expenses
                        WHERE salary_id = ? AND company_id = ?
                    "),
                    'Failed to prepare expense delete query.'
                );

                $stmt->bind_param("ii", $salary_id, $company_id);

                if (!$stmt->execute()) {
                    throw new RuntimeException('Expense delete failed: ' . $stmt->error);
                }
                $stmt->close();

                $stmt = failIfPrepareFalse(
                    $conn->prepare("
                        DELETE FROM salaries
                        WHERE salary_id = ? AND company_id = ?
                    "),
                    'Failed to prepare salary delete query.'
                );

                $stmt->bind_param("ii", $salary_id, $company_id);

                if (!$stmt->execute()) {
                    throw new RuntimeException('Salary delete failed: ' . $stmt->error);
                }
                $stmt->close();

                $conn->commit();
                $msg = 'Salary record deleted successfully.';
                $msgType = 'success';
            } catch (Throwable $e) {
                $conn->rollback();
                $msg = 'Delete failed: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

/* =========================
   FETCH SALARY RECORDS
========================= */
$rows = [];

try {
    $stmt = failIfPrepareFalse(
        $conn->prepare("
            SELECT 
                s.salary_id,
                s.employee_id,
                s.salary_amount,
                s.bonus,
                s.allowance,
                s.deduction,
                s.epf_employee,
                s.epf_employer,
                s.etf_employer,
                s.gross_salary,
                s.net_salary,
                s.salary_date,
                s.description,
                s.payment_source,
                s.bank_name,
                s.account_number,
                s.created_at,
                e.employee_name,
                e.position
            FROM salaries s
            INNER JOIN employees e ON s.employee_id = e.employee_id
            WHERE s.company_id = ?
            ORDER BY s.salary_id DESC
        "),
        'Failed to prepare salary list query.'
    );

    $stmt->bind_param("i", $company_id);

    if (!$stmt->execute()) {
        throw new RuntimeException('Salary list fetch failed: ' . $stmt->error);
    }

    $stmt->bind_result(
        $row_salary_id,
        $row_employee_id,
        $row_salary_amount,
        $row_bonus,
        $row_allowance,
        $row_deduction,
        $row_epf_employee,
        $row_epf_employer,
        $row_etf_employer,
        $row_gross_salary,
        $row_net_salary,
        $row_salary_date,
        $row_description,
        $row_payment_source,
        $row_bank_name,
        $row_account_number,
        $row_created_at,
        $row_employee_name,
        $row_position
    );

    while ($stmt->fetch()) {
        $rows[] = [
            'salary_id' => $row_salary_id,
            'employee_id' => $row_employee_id,
            'salary_amount' => $row_salary_amount,
            'bonus' => $row_bonus,
            'allowance' => $row_allowance,
            'deduction' => $row_deduction,
            'epf_employee' => $row_epf_employee,
            'epf_employer' => $row_epf_employer,
            'etf_employer' => $row_etf_employer,
            'gross_salary' => $row_gross_salary,
            'net_salary' => $row_net_salary,
            'salary_date' => $row_salary_date,
            'description' => $row_description,
            'payment_source' => $row_payment_source,
            'bank_name' => $row_bank_name,
            'account_number' => $row_account_number,
            'created_at' => $row_created_at,
            'employee_name' => $row_employee_name,
            'position' => $row_position
        ];
    }

    $stmt->close();
} catch (Throwable $e) {
    $msg = 'Failed to load salary records: ' . $e->getMessage();
    $msgType = 'danger';
}

include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/topbar.php';
?>

<div class="main-area">
    <div class="content">
        <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <?php if ($canCrud): ?>
        <div class="card">
            <div class="card-header">
                <h3><?= $edit_mode ? 'Edit Salary Payment' : 'Add Salary Payment' ?></h3>
                <span class="badge badge-primary">Professional Entry</span>
            </div>

            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="salary_id" value="<?= e((string)$edit['salary_id']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Employee</label>
                            <select name="employee_id" id="employee_id" class="form-control" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= (int)$emp['employee_id'] ?>"
                                    data-salary="<?= e((string)$emp['total_salary']) ?>"
                                    data-name="<?= e($emp['employee_name']) ?>"
                                    data-position="<?= e($emp['position']) ?>" data-nic="<?= e($emp['nic']) ?>"
                                    data-phone="<?= e($emp['phone']) ?>" data-email="<?= e($emp['email']) ?>"
                                    data-basic-salary="<?= e((string)$emp['basic_salary']) ?>"
                                    data-increment="<?= e((string)$emp['increment']) ?>"
                                    data-join-date="<?= e($emp['join_date']) ?>"
                                    <?= (string)$edit['employee_id'] === (string)$emp['employee_id'] ? 'selected' : '' ?>>
                                    <?= e($emp['employee_name']) ?><?= !empty($emp['position']) ? ' - ' . e($emp['position']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Employee Details Card -->
                        <div id="employee-card" class="employee-details-card" style="display: none;">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Employee Details</h4>
                                </div>
                                <div class="card-body">
                                    <div class="employee-info-grid">
                                        <div class="info-section">
                                            <h5>Personal Details</h5>
                                            <div class="info-item">
                                                <strong>Name:</strong> <span id="emp-name"></span>
                                            </div>
                                            <div class="info-item">
                                                <strong>Position:</strong> <span id="emp-position"></span>
                                            </div>
                                            <div class="info-item">
                                                <strong>NIC:</strong> <span id="emp-nic"></span>
                                            </div>
                                            <div class="info-item">
                                                <strong>Phone:</strong> <span id="emp-phone"></span>
                                            </div>
                                            <div class="info-item">
                                                <strong>Email:</strong> <span id="emp-email"></span>
                                            </div>
                                            <div class="info-item">
                                                <strong>Join Date:</strong> <span id="emp-join-date"></span>
                                            </div>
                                        </div>
                                        <div class="info-section">
                                            <h5>Salary Details</h5>
                                            <div class="info-item">
                                                <strong>Basic Salary:</strong> Rs. <span id="emp-basic-salary"></span>
                                            </div>
                                            <div class="info-item">
                                                <strong>Increment:</strong> Rs. <span id="emp-increment"></span>
                                            </div>
                                            <div class="info-item">
                                                <strong>Total Salary:</strong> Rs. <span id="emp-total-salary"></span>
                                            </div>
                                        </div>
                                        <div class="info-section">
                                            <h5>Payment Information</h5>
                                            <div class="info-item">
                                                <strong>Payment Method:</strong> <span
                                                    id="emp-payment-method">Cash</span>
                                            </div>
                                            <div id="bank-details" style="display: none;">
                                                <div class="info-item">
                                                    <strong>Bank Name:</strong> <span id="emp-bank-name"></span>
                                                </div>
                                                <div class="info-item">
                                                    <strong>Account Number:</strong> <span
                                                        id="emp-account-number"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Employee Total Salary</label>
                            <input type="number" step="0.01" min="0.01" name="salary_amount" id="salary_amount"
                                class="form-control" value="<?= e((string)$edit['salary_amount']) ?>" readonly required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Bonus</label>
                            <input type="number" step="0.01" min="0" name="bonus" id="bonus" class="form-control"
                                value="<?= e((string)$edit['bonus']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Allowance</label>
                            <input type="number" step="0.01" min="0" name="allowance" id="allowance"
                                class="form-control" value="<?= e((string)$edit['allowance']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Deduction</label>
                            <input type="number" step="0.01" min="0" name="deduction" id="deduction"
                                class="form-control" value="<?= e((string)$edit['deduction']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Salary Date</label>
                            <input type="date" name="salary_date" class="form-control"
                                value="<?= e((string)$edit['salary_date']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Source</label>
                            <select name="payment_source" class="form-control" id="salaryPaymentSource"
                                onchange="toggleSalaryBankFields(this.value)">
                                <option value="Cash"
                                    <?= ($edit['payment_source'] ?? 'Cash') === 'Cash' ? 'selected' : '' ?>>Cash
                                </option>
                                <option value="Bank"
                                    <?= ($edit['payment_source'] ?? '') === 'Bank' ? 'selected' : '' ?>>
                                    Bank</option>
                            </select>
                        </div>

                        <div class="form-group" id="salaryBankNameWrap" style="display:none;">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control"
                                value="<?= e((string)$edit['bank_name']) ?>">
                        </div>

                        <div class="form-group" id="salaryAccountNoWrap" style="display:none;">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" class="form-control"
                                value="<?= e((string)$edit['account_number']) ?>">
                        </div>

                        <input type="hidden" name="existing_proof_file" value="<?= e((string)$edit['proof_file']) ?>">

                        <div class="form-group">
                            <label class="form-label">Salary Proof File</label>
                            <input type="file" name="proof_file" class="form-control">
                            <?php if (!empty($edit['proof_file'])): ?>
                            <p class="form-text">Current file: <a
                                    href="uploads/transactions/<?= e($edit['proof_file']) ?>" target="_blank">View
                                    proof</a></p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label">EPF Employee (8%)</label>
                            <input type="number" step="0.01" id="epf_employee" class="form-control"
                                value="<?= e((string)$edit['epf_employee']) ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">EPF Employer (12%)</label>
                            <input type="number" step="0.01" id="epf_employer" class="form-control"
                                value="<?= e((string)$edit['epf_employer']) ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">ETF Employer (3%)</label>
                            <input type="number" step="0.01" id="etf_employer" class="form-control"
                                value="<?= e((string)$edit['etf_employer']) ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Gross Salary</label>
                            <input type="number" step="0.01" id="gross_salary" class="form-control"
                                value="<?= e((string)$edit['gross_salary']) ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Net Salary</label>
                            <input type="number" step="0.01" id="net_salary" class="form-control"
                                value="<?= e((string)$edit['net_salary']) ?>" readonly>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control"
                                placeholder="Optional description"><?= e((string)$edit['description']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="save_salary" class="btn btn-primary">
                                <?= $edit_mode ? 'Update Salary' : 'Save Salary' ?>
                            </button>

                            <?php if ($edit_mode): ?>
                            <a href="salaries.php" class="btn btn-light">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Salary Records</h3>
                <span class="badge badge-success"><?= count($rows) ?> Records</span>
            </div>

            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Employee</th>
                                <th>Position</th>
                                <th>Basic</th>
                                <th>Bonus</th>
                                <th>Allowance</th>
                                <th>Deduction</th>
                                <th>EPF (Emp.)</th>
                                <th>ETF (Employer)</th>
                                <th>Net Salary</th>
                                <th>Salary Date</th>
                                <th>Payment</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e((string)$row['salary_id']) ?></td>
                                <td><?= e((string)($row['employee_name'] ?? 'Unknown Employee')) ?></td>
                                <td><?= e((string)($row['position'] ?? '-')) ?></td>
                                <td>Rs. <?= number_format((float)($row['salary_amount'] ?? 0), 2) ?></td>
                                <td>Rs. <?= number_format((float)($row['bonus'] ?? 0), 2) ?></td>
                                <td>Rs. <?= number_format((float)($row['allowance'] ?? 0), 2) ?></td>
                                <td>Rs. <?= number_format((float)($row['deduction'] ?? 0), 2) ?></td>
                                <td>Rs. <?= number_format((float)($row['epf_employee'] ?? 0), 2) ?></td>
                                <td>Rs. <?= number_format((float)($row['etf_employer'] ?? 0), 2) ?></td>
                                <td>Rs. <?= number_format((float)($row['net_salary'] ?? 0), 2) ?></td>
                                <td><?= e((string)($row['salary_date'] ?? '')) ?></td>
                                <td><span
                                        class="badge badge-primary"><?= e((string)($row['payment_source'] ?? 'Cash')) ?></span>
                                </td>
                                <td>
                                    <?php if ($canCrud): ?>
                                    <a href="?edit=<?= (int)$row['salary_id'] ?>" class="btn btn-light">Edit</a>
                                    <a href="?delete=<?= (int)$row['salary_id'] ?>&csrf_token=<?= urlencode(get_csrf_token()) ?>"
                                        class="btn btn-danger"
                                        onclick="return confirm('Delete this salary record?')">Delete</a>
                                    <?php else: ?>
                                    <span class="text-muted">View Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="13">No salary records found.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function toggleSalaryBankFields(value) {
    const bankWrap = document.getElementById('salaryBankNameWrap');
    const accWrap = document.getElementById('salaryAccountNoWrap');

    if (!bankWrap || !accWrap) return;

    bankWrap.style.display = value === 'Bank' ? 'block' : 'none';
    accWrap.style.display = value === 'Bank' ? 'block' : 'none';
}

function calculateSalaryFields() {
    const salaryAmount = parseFloat(document.getElementById('salary_amount')?.value || 0);
    const bonus = parseFloat(document.getElementById('bonus')?.value || 0);
    const allowance = parseFloat(document.getElementById('allowance')?.value || 0);
    const deduction = parseFloat(document.getElementById('deduction')?.value || 0);

    const epfEmployee = salaryAmount * 0.08;
    const epfEmployer = salaryAmount * 0.12;
    const etfEmployer = salaryAmount * 0.03;
    const grossSalary = salaryAmount + bonus + allowance;
    const netSalary = grossSalary - epfEmployee - deduction;

    const epfEmployeeInput = document.getElementById('epf_employee');
    const epfEmployerInput = document.getElementById('epf_employer');
    const etfEmployerInput = document.getElementById('etf_employer');
    const grossSalaryInput = document.getElementById('gross_salary');
    const netSalaryInput = document.getElementById('net_salary');

    if (epfEmployeeInput) epfEmployeeInput.value = epfEmployee.toFixed(2);
    if (epfEmployerInput) epfEmployerInput.value = epfEmployer.toFixed(2);
    if (etfEmployerInput) etfEmployerInput.value = etfEmployer.toFixed(2);
    if (grossSalaryInput) grossSalaryInput.value = grossSalary.toFixed(2);
    if (netSalaryInput) netSalaryInput.value = netSalary.toFixed(2);
}

const employeeSelect = document.getElementById('employee_id');
const salaryAmountInput = document.getElementById('salary_amount');
const bonusInput = document.getElementById('bonus');
const allowanceInput = document.getElementById('allowance');
const deductionInput = document.getElementById('deduction');
const salaryPaymentSource = document.getElementById('salaryPaymentSource');
const employeeCard = document.getElementById('employee-card');
const bankDetails = document.getElementById('bank-details');

if (employeeSelect && salaryAmountInput) {
    const setEmployeeSalary = function() {
        const selected = employeeSelect.options[employeeSelect.selectedIndex];
        const salary = selected?.getAttribute('data-salary') || '';
        if (salary !== '') {
            salaryAmountInput.value = salary;
        }
    };

    const updateEmployeeCard = function() {
        const selected = employeeSelect.options[employeeSelect.selectedIndex];
        if (selected && selected.value !== '') {
            // Populate card
            document.getElementById('emp-name').textContent = selected.getAttribute('data-name') || '';
            document.getElementById('emp-position').textContent = selected.getAttribute('data-position') || '';
            document.getElementById('emp-nic').textContent = selected.getAttribute('data-nic') || '';
            document.getElementById('emp-phone').textContent = selected.getAttribute('data-phone') || '';
            document.getElementById('emp-email').textContent = selected.getAttribute('data-email') || '';
            document.getElementById('emp-join-date').textContent = selected.getAttribute('data-join-date') || '';
            document.getElementById('emp-basic-salary').textContent = selected.getAttribute('data-basic-salary') ||
                '0.00';
            document.getElementById('emp-increment').textContent = selected.getAttribute('data-increment') ||
                '0.00';
            document.getElementById('emp-total-salary').textContent = selected.getAttribute('data-salary') ||
                '0.00';

            // Show card
            employeeCard.style.display = 'block';
        } else {
            // Hide card
            employeeCard.style.display = 'none';
        }
    };

    if (!salaryAmountInput.value) {
        setEmployeeSalary();
    }
    updateEmployeeCard(); // Show card on page load if employee selected

    employeeSelect.addEventListener('change', function() {
        setEmployeeSalary();
        updateEmployeeCard();
        calculateSalaryFields();
    });
}

[salaryAmountInput, bonusInput, allowanceInput, deductionInput].forEach(function(el) {
    if (el) {
        el.addEventListener('input', calculateSalaryFields);
    }
});

if (salaryPaymentSource) {
    const updatePaymentInfo = function() {
        const method = salaryPaymentSource.value;
        document.getElementById('emp-payment-method').textContent = method;
        if (method === 'Bank') {
            bankDetails.style.display = 'block';
            // For bank details, show the entered values
            const bankName = document.querySelector('input[name="bank_name"]').value || '';
            const accountNumber = document.querySelector('input[name="account_number"]').value || '';
            document.getElementById('emp-bank-name').textContent = bankName;
            document.getElementById('emp-account-number').textContent = accountNumber;
        } else {
            bankDetails.style.display = 'none';
        }
    };

    updatePaymentInfo(); // Initial update
    salaryPaymentSource.addEventListener('change', updatePaymentInfo);

    // Also update when bank fields change
    document.querySelector('input[name="bank_name"]').addEventListener('input', updatePaymentInfo);
    document.querySelector('input[name="account_number"]').addEventListener('input', updatePaymentInfo);
}

calculateSalaryFields();
</script>
</div>
</div>

<?php include 'includes/footer.php'; ?>