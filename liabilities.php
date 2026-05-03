<?php

declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

set_security_headers();

if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_id'] ?? 0) <= 0) {
    header("Location: login.php");
    exit;
}

$company_id = (int)($_SESSION['company_id'] ?? 0);
$user_id    = (int)($_SESSION['user_id'] ?? 0);

if ($company_id <= 0) {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Liabilities Management';
$pageDescription = 'Add and manage company liability records';

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
function resetForm(): array
{
    return [
        'liability_id'    => '',
        'liability_name'  => '',
        'liability_type'  => '',
        'amount'          => '',
        'original_amount' => '',
        'paid_amount'     => '0.00',
        'balance_amount'  => '',
        'liability_date'  => date('Y-m-d'),
        'description'     => '',
        'due_date'        => '',
        'payment_source'  => 'Cash',
        'bank_name'       => '',
        'account_number'  => '',
        'status'          => 'Active',
        'proof_file'      => '',
        'expense_id'      => ''
    ];
}
function insertLoanReceivedLedgerEntry(
    mysqli $conn,
    int $company_id,
    int $liability_id,
    string $liability_name,
    string $liability_date,
    float $amount,
    string $payment_source,
    string $bank_name,
    string $account_number,
    string $proof_file
): void {
    $description = '[LOAN_RECEIVED:' . $liability_id . '] Loan received - ' . $liability_name;

    if ($payment_source === 'Cash') {
        $stmt = failIfPrepareFalse(
            $conn->prepare("
                INSERT INTO cash_account (
                    company_id,
                    transaction_date,
                    description,
                    transaction_type,
                    amount,
                    proof_file
                )
                VALUES (?, ?, ?, 'Cash In', ?, ?)
            "),
            'Failed to prepare loan cash received query.'
        );

        $stmt->bind_param(
            "issds",
            $company_id,
            $liability_date,
            $description,
            $amount,
            $proof_file
        );

        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = failIfPrepareFalse(
        $conn->prepare("
            INSERT INTO bank_account (
                company_id,
                transaction_date,
                description,
                amount,
                transaction_type,
                bank_name,
                account_number,
                proof_file
            )
            VALUES (?, ?, ?, ?, 'Deposit', ?, ?, ?)
        "),
        'Failed to prepare loan bank received query.'
    );

    $stmt->bind_param(
        "issdsss",
        $company_id,
        $liability_date,
        $description,
        $amount,
        $bank_name,
        $account_number,
        $proof_file
    );

    $stmt->execute();
    $stmt->close();
}

function failIfPrepareFalse($stmt, string $context = 'Database prepare failed'): mysqli_stmt
{
    if ($stmt === false) {
        throw new RuntimeException($context);
    }
    return $stmt;
}

function isValidIsoDate(string $date): bool
{
    $dateTime = DateTime::createFromFormat('Y-m-d', $date);
    return $dateTime !== false && $dateTime->format('Y-m-d') === $date;
}

function uploadTransactionFile(array $file, string $prefix, int $company_id): string
{
    if (empty($file['name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed with error code ' . (int)$file['error']);
    }

    $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
    $maxSize = 5 * 1024 * 1024;

    $fileName = basename((string)$file['name']);
    $fileTmp  = (string)$file['tmp_name'];
    $fileSize = (int)$file['size'];
    $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!in_array($fileExt, $allowedExts, true)) {
        throw new RuntimeException('Invalid file type. Allowed: JPG, JPEG, PNG, PDF, DOC, DOCX.');
    }

    if ($fileSize > $maxSize) {
        throw new RuntimeException('File size too large. Maximum 5MB allowed.');
    }

    $uploadDir = __DIR__ . '/uploads/transactions';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to create upload directory.');
    }

    $safeName = uniqid($prefix . '_' . $company_id . '_', true) . '.' . $fileExt;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

    if (!move_uploaded_file($fileTmp, $targetPath)) {
        throw new RuntimeException('Failed to move uploaded file.');
    }

    return $safeName;
}

function deletePhysicalFile(string $fileName): void
{
    if ($fileName === '') {
        return;
    }

    $filePath = __DIR__ . '/uploads/transactions/' . $fileName;
    if (is_file($filePath)) {
        @unlink($filePath);
    }
}

function getLiabilityById(mysqli $conn, int $company_id, int $liability_id): ?array
{
    $stmt = failIfPrepareFalse(
        $conn->prepare("
            SELECT 
                liability_id,
                expense_id,
                liability_name,
                liability_type,
                amount,
                original_amount,
                paid_amount,
                balance_amount,
                liability_date,
                description,
                due_date,
                payment_source,
                bank_name,
                account_number,
                status,
                proof_file
            FROM liabilities
            WHERE company_id = ? AND liability_id = ?
            LIMIT 1
        "),
        'Failed to prepare liability lookup query.'
    );

    $stmt->bind_param('ii', $company_id, $liability_id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Liability lookup failed: ' . $error);
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function getLiabilityPaymentHistory(mysqli $conn, int $company_id, int $liability_id): array
{
    $payments = [];

    $stmt = failIfPrepareFalse(
        $conn->prepare("
            SELECT 
                payment_id,
                payment_date,
                amount,
                payment_source,
                bank_id,
                cash_id,
                reference_no,
                notes,
                proof_file,
                created_at
            FROM liability_payments
            WHERE liability_id = ? AND company_id = ?
            ORDER BY payment_date DESC, payment_id DESC
        "),
        'Failed to prepare liability payments query.'
    );

    $stmt->bind_param('ii', $liability_id, $company_id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Liability payment history failed: ' . $error);
    }

    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }

    $stmt->close();
    return $payments;
}

function getLiabilityPaymentCount(mysqli $conn, int $company_id, int $liability_id): int
{
    $stmt = failIfPrepareFalse(
        $conn->prepare("
            SELECT COUNT(*)
            FROM liability_payments
            WHERE company_id = ? AND liability_id = ?
        "),
        'Failed to prepare liability payment count query.'
    );

    $stmt->bind_param('ii', $company_id, $liability_id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Liability payment count failed: ' . $error);
    }

    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int)$count;
}

function insertLiabilityPaymentLedgerEntry(
    mysqli $conn,
    int $company_id,
    int $payment_id,
    string $liability_name,
    string $payment_date,
    float $amount,
    string $payment_source,
    string $bank_name,
    string $account_number,
    string $proof_file
): array {
    $description = '[LIABILITY_PAYMENT:' . $payment_id . '] Liability payment - ' . $liability_name;

    if ($payment_source === 'Cash') {
        $stmt = failIfPrepareFalse(
            $conn->prepare("
                INSERT INTO cash_account (
                    company_id,
                    transaction_date,
                    description,
                    transaction_type,
                    amount,
                    proof_file
                )
                VALUES (?, ?, ?, 'Cash Out', ?, ?)
            "),
            'Failed to prepare cash payment ledger query.'
        );

        $stmt->bind_param(
            "issds",
            $company_id,
            $payment_date,
            $description,
            $amount,
            $proof_file
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Cash payment ledger insert failed: ' . $error);
        }

        $cash_id = (int)$stmt->insert_id;
        $stmt->close();

        return ['cash_id' => $cash_id, 'bank_id' => null];
    }

    $stmt = failIfPrepareFalse(
        $conn->prepare("
            INSERT INTO bank_account (
                company_id,
                transaction_date,
                description,
                amount,
                transaction_type,
                bank_name,
                account_number,
                proof_file
            )
            VALUES (?, ?, ?, ?, 'Withdrawal', ?, ?, ?)
        "),
        'Failed to prepare bank payment ledger query.'
    );

    $stmt->bind_param(
        "issdsss",
        $company_id,
        $payment_date,
        $description,
        $amount,
        $bank_name,
        $account_number,
        $proof_file
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Bank payment ledger insert failed: ' . $error);
    }

    $bank_id = (int)$stmt->insert_id;
    $stmt->close();

    return ['cash_id' => null, 'bank_id' => $bank_id];
}

$edit_mode = false;
$edit = resetForm();
$liability_payments = [];

/* =========================
   EDIT FETCH
========================= */
if (isset($_GET['edit'])) {
    $id = (int)($_GET['edit'] ?? 0);

    if ($id > 0) {
        try {
            $row = getLiabilityById($conn, $company_id, $id);

            if ($row) {
                $edit = [
                    'liability_id'   => (string)$row['liability_id'],
                    'liability_name' => (string)$row['liability_name'],
                    'liability_type' => (string)($row['liability_type'] ?? ''),
                    'amount'         => number_format((float)$row['amount'], 2, '.', ''),
                    'original_amount'=> number_format((float)$row['original_amount'], 2, '.', ''),
                    'paid_amount'    => number_format((float)$row['paid_amount'], 2, '.', ''),
                    'balance_amount' => number_format((float)$row['balance_amount'], 2, '.', ''),
                    'liability_date' => (string)$row['liability_date'],
                    'description'    => (string)($row['description'] ?? ''),
                    'due_date'       => (string)($row['due_date'] ?? ''),
                    'payment_source' => (string)$row['payment_source'],
                    'bank_name'      => (string)($row['bank_name'] ?? ''),
                    'account_number' => (string)($row['account_number'] ?? ''),
                    'status'         => (string)$row['status'],
                    'proof_file'     => (string)($row['proof_file'] ?? ''),
                    'expense_id'     => (string)($row['expense_id'] ?? '')
                ];
                $edit_mode = true;
                $liability_payments = getLiabilityPaymentHistory($conn, $company_id, $id);
            }
        } catch (Throwable $e) {
            $msg = 'Failed to load liability record: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

/* =========================
   SAVE LIABILITY
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_liability'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid request';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can perform this action';
        $msgType = 'danger';
    } else {
        $id     = (int)($_POST['liability_id'] ?? 0);
        $name   = trim($_POST['liability_name'] ?? '');
        $type   = trim($_POST['liability_type'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $date   = trim($_POST['liability_date'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $due    = trim($_POST['due_date'] ?? '');
        $source = trim($_POST['payment_source'] ?? 'Cash');
        $bank   = trim($_POST['bank_name'] ?? '');
        $acc    = trim($_POST['account_number'] ?? '');
        $existing_proof_file = trim($_POST['existing_proof_file'] ?? '');
        $proof_file = $existing_proof_file;

        if (!in_array($source, ['Cash', 'Bank'], true)) {
            $source = 'Cash';
        }

        if ($source !== 'Bank') {
            $bank = '';
            $acc = '';
        }

        $edit = [
            'liability_id'   => (string)$id,
            'liability_name' => $name,
            'liability_type' => $type,
            'amount'         => $amount > 0 ? number_format($amount, 2, '.', '') : '',
            'original_amount'=> $amount > 0 ? number_format($amount, 2, '.', '') : '',
            'paid_amount'    => '0.00',
            'balance_amount' => $amount > 0 ? number_format($amount, 2, '.', '') : '',
            'liability_date' => $date,
            'description'    => $desc,
            'due_date'       => $due,
            'payment_source' => $source,
            'bank_name'      => $bank,
            'account_number' => $acc,
            'status'         => 'Active',
            'proof_file'     => $proof_file,
            'expense_id'     => ''
        ];
        $edit_mode = ($id > 0);

        if ($name === '' || $date === '' || $amount <= 0) {
            $msg = 'Please fill all required fields correctly.';
            $msgType = 'danger';
        } elseif (mb_strlen($name) > 255) {
            $msg = 'Liability name is too long.';
            $msgType = 'danger';
        } elseif (!isValidIsoDate($date)) {
            $msg = 'Liability date is invalid.';
            $msgType = 'danger';
        } elseif ($due !== '' && !isValidIsoDate($due)) {
            $msg = 'Due date is invalid.';
            $msgType = 'danger';
        } elseif ($source === 'Bank' && ($bank === '' || $acc === '')) {
            $msg = 'Bank name and account number are required for bank payments.';
            $msgType = 'danger';
        } else {
            try {
                if (
                    isset($_FILES['proof_file']) &&
                    (
                        (int)($_FILES['proof_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ||
                        !empty($_FILES['proof_file']['name'])
                    )
                ) {
                    $proof_file = uploadTransactionFile($_FILES['proof_file'], 'liability', $company_id);
                    $edit['proof_file'] = $proof_file;
                }

                if ($id > 0) {
                    $existing = getLiabilityById($conn, $company_id, $id);

                    if (!$existing) {
                        throw new RuntimeException('Liability record not found.');
                    }

                    $paid_amount = (float)$existing['paid_amount'];
                    $expense_id = (int)($existing['expense_id'] ?? 0);

                    if ($amount < $paid_amount) {
                        throw new RuntimeException('Amount cannot be less than the already paid amount.');
                    }

                    $balance_amount = $amount - $paid_amount;
                    $status = $balance_amount <= 0 ? 'Paid' : ($paid_amount > 0 ? 'Pending' : 'Active');

                    $conn->begin_transaction();

                    $stmt = failIfPrepareFalse(
                        $conn->prepare("
                            UPDATE liabilities
                            SET
                                liability_name = ?,
                                liability_type = ?,
                                amount = ?,
                                original_amount = ?,
                                balance_amount = ?,
                                liability_date = ?,
                                description = ?,
                                due_date = ?,
                                payment_source = ?,
                                bank_name = ?,
                                account_number = ?,
                                status = ?,
                                proof_file = ?
                            WHERE company_id = ? AND liability_id = ?
                        "),
                        'Failed to prepare liability update query'
                    );

                    $stmt->bind_param(
                        "ssdddssssssssii",
                        $name,
                        $type,
                        $amount,
                        $amount,
                        $balance_amount,
                        $date,
                        $desc,
                        $due,
                        $source,
                        $bank,
                        $acc,
                        $status,
                        $proof_file,
                        $company_id,
                        $id
                    );

                    if ($stmt->execute()) {
                        $stmt->close();
                        $conn->commit();

                        if ($proof_file !== '' && $existing_proof_file !== '' && $proof_file !== $existing_proof_file) {
                            deletePhysicalFile($existing_proof_file);
                        }

                        $msg = 'Liability updated successfully';
                        $msgType = 'success';
                        $edit_mode = false;
                        $edit = resetForm();
                        $liability_payments = [];
                    } else {
                        $error = $stmt->error;
                        $stmt->close();
                        $conn->rollback();
                        throw new RuntimeException('Failed to update record: ' . $error);
                    }
                } else {
                    $original_amount = $amount;
                    $paid_amount = 0.00;
                    $balance_amount = $amount;
                    $status = 'Active';

                    $stmt = failIfPrepareFalse(
                        $conn->prepare("
                            INSERT INTO liabilities
                            (
                                company_id,
                                liability_name,
                                liability_type,
                                amount,
                                original_amount,
                                paid_amount,
                                balance_amount,
                                liability_date,
                                description,
                                due_date,
                                payment_source,
                                bank_name,
                                account_number,
                                status,
                                created_by,
                                proof_file
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        "),
                        'Failed to prepare liability insert query'
                    );

                    $conn->begin_transaction();

                    $stmt->bind_param(
                        "issddddssssssiss",
                        $company_id,
                        $name,
                        $type,
                        $amount,
                        $original_amount,
                        $paid_amount,
                        $balance_amount,
                        $date,
                        $desc,
                        $due,
                        $source,
                        $bank,
                        $acc,
                        $status,
                        $user_id,
                        $proof_file
                    );

try {
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Failed to add record: ' . $error);
    }

    $new_liability_id = (int)$stmt->insert_id;
    $stmt->close();

    insertLoanReceivedLedgerEntry(
        $conn,
        $company_id,
        $new_liability_id,
        $name,
        $date,
        $amount,
        $source,
        $bank,
        $acc,
        $proof_file
    );

    $conn->commit();

    $msg = 'Liability added successfully and cash/bank ledger updated.';
    $msgType = 'success';
    $edit = resetForm();
    $edit_mode = false;

} catch (Throwable $e) {
    $conn->rollback();
    throw $e;
}
                 
                }
            } catch (Throwable $e) {
                if ($proof_file !== '' && $proof_file !== $existing_proof_file) {
                    deletePhysicalFile($proof_file);
                }
                $msg = $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

/* =========================
   LIABILITY PAYMENT
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_liability_payment'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid payment request.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can record liability payments.';
        $msgType = 'danger';
    } else {
        $liability_id   = (int)($_POST['liability_id'] ?? 0);
        $payment_date   = trim($_POST['payment_date'] ?? '');
        $payment_amount = (float)($_POST['payment_amount'] ?? 0);
        $payment_source = trim($_POST['payment_source'] ?? 'Cash');
        $bank_name      = trim($_POST['payment_bank_name'] ?? '');
        $account_number = trim($_POST['payment_account_number'] ?? '');
        $reference_no   = trim($_POST['reference_no'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');
        $proof_file_path = '';

        if (!in_array($payment_source, ['Cash', 'Bank'], true)) {
            $payment_source = 'Cash';
        }

        if ($payment_source !== 'Bank') {
            $bank_name = '';
            $account_number = '';
        }

        if ($payment_date === '' || !isValidIsoDate($payment_date) || $payment_amount <= 0 || $liability_id <= 0) {
            $msg = 'Please enter valid payment details.';
            $msgType = 'danger';
        } elseif ($payment_source === 'Bank' && ($bank_name === '' || $account_number === '')) {
            $msg = 'Bank name and account number are required for bank payment.';
            $msgType = 'danger';
        } else {
            try {
                if (
                    isset($_FILES['payment_proof_file']) &&
                    (
                        (int)($_FILES['payment_proof_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ||
                        !empty($_FILES['payment_proof_file']['name'])
                    )
                ) {
                    $proof_file_path = uploadTransactionFile($_FILES['payment_proof_file'], 'liability_payment', $company_id);
                }

                $liability = getLiabilityById($conn, $company_id, $liability_id);

                if (!$liability) {
                    throw new RuntimeException('Liability not found.');
                }

                $liability_name = (string)$liability['liability_name'];
                $original_amount = (float)$liability['original_amount'];
                $paid_amount = (float)$liability['paid_amount'];
                $balance_amount = (float)$liability['balance_amount'];

                if ($balance_amount <= 0) {
                    throw new RuntimeException('This liability is already fully paid.');
                }

                if ($payment_amount > $balance_amount) {
                    throw new RuntimeException('Payment amount cannot exceed the outstanding balance.');
                }

                $conn->begin_transaction();

                try {
                    $stmt = failIfPrepareFalse(
                        $conn->prepare("
                            INSERT INTO liability_payments
                            (
                                liability_id,
                                company_id,
                                payment_date,
                                amount,
                                payment_source,
                                reference_no,
                                notes,
                                proof_file,
                                created_by
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        "),
                        'Failed to prepare liability payment query.'
                    );

                    $stmt->bind_param(
                        'iisdssssi',
                        $liability_id,
                        $company_id,
                        $payment_date,
                        $payment_amount,
                        $payment_source,
                        $reference_no,
                        $notes,
                        $proof_file_path,
                        $user_id
                    );

                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('Failed to save liability payment: ' . $error);
                    }

                    $payment_id = (int)$stmt->insert_id;
                    $stmt->close();

                    $ledgerIds = insertLiabilityPaymentLedgerEntry(
                        $conn,
                        $company_id,
                        $payment_id,
                        $liability_name,
                        $payment_date,
                        $payment_amount,
                        $payment_source,
                        $bank_name,
                        $account_number,
                        $proof_file_path
                    );

                    $stmt = failIfPrepareFalse(
                        $conn->prepare("
                            UPDATE liability_payments
                            SET bank_id = ?, cash_id = ?
                            WHERE payment_id = ? AND company_id = ?
                        "),
                        'Failed to prepare liability payment ledger id update query.'
                    );

                    $bank_id = $ledgerIds['bank_id'];
                    $cash_id = $ledgerIds['cash_id'];

                    $stmt->bind_param('iiii', $bank_id, $cash_id, $payment_id, $company_id);

                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('Failed to update liability payment ledger references: ' . $error);
                    }
                    $stmt->close();

                    $new_paid_amount = $paid_amount + $payment_amount;
                    $new_balance_amount = $original_amount - $new_paid_amount;
                    if ($new_balance_amount < 0) {
                        $new_balance_amount = 0;
                    }

                    $new_status = $new_balance_amount <= 0 ? 'Paid' : 'Pending';

                    $stmt = failIfPrepareFalse(
                        $conn->prepare("
                            UPDATE liabilities
                            SET
                                paid_amount = ?,
                                balance_amount = ?,
                                status = ?
                            WHERE liability_id = ? AND company_id = ?
                        "),
                        'Failed to prepare liability totals update query.'
                    );

                    $stmt->bind_param(
                        'ddsii',
                        $new_paid_amount,
                        $new_balance_amount,
                        $new_status,
                        $liability_id,
                        $company_id
                    );

                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('Failed to update liability balance: ' . $error);
                    }
                    $stmt->close();

                    $conn->commit();
                    $msg = 'Liability payment recorded successfully.';
                    $msgType = 'success';

                    if ($edit_mode && (int)$edit['liability_id'] === $liability_id) {
                        $fresh = getLiabilityById($conn, $company_id, $liability_id);
                        if ($fresh) {
                            $edit = [
                                'liability_id'   => (string)$fresh['liability_id'],
                                'liability_name' => (string)$fresh['liability_name'],
                                'liability_type' => (string)($fresh['liability_type'] ?? ''),
                                'amount'         => number_format((float)$fresh['amount'], 2, '.', ''),
                                'original_amount'=> number_format((float)$fresh['original_amount'], 2, '.', ''),
                                'paid_amount'    => number_format((float)$fresh['paid_amount'], 2, '.', ''),
                                'balance_amount' => number_format((float)$fresh['balance_amount'], 2, '.', ''),
                                'liability_date' => (string)$fresh['liability_date'],
                                'description'    => (string)($fresh['description'] ?? ''),
                                'due_date'       => (string)($fresh['due_date'] ?? ''),
                                'payment_source' => (string)$fresh['payment_source'],
                                'bank_name'      => (string)($fresh['bank_name'] ?? ''),
                                'account_number' => (string)($fresh['account_number'] ?? ''),
                                'status'         => (string)$fresh['status'],
                                'proof_file'     => (string)($fresh['proof_file'] ?? ''),
                                'expense_id'     => (string)($fresh['expense_id'] ?? '')
                            ];
                            $liability_payments = getLiabilityPaymentHistory($conn, $company_id, $liability_id);
                        }
                    }
                } catch (Throwable $e) {
                    $conn->rollback();

                    if ($proof_file_path !== '') {
                        deletePhysicalFile($proof_file_path);
                    }

                    $msg = 'Liability payment failed: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            } catch (Throwable $e) {
                if ($proof_file_path !== '') {
                    deletePhysicalFile($proof_file_path);
                }
                $msg = $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

/* =========================
   DELETE LIABILITY
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_liability'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can delete liabilities.';
        $msgType = 'danger';
    } else {
        $id = (int)($_POST['liability_id'] ?? 0);

        if ($id <= 0) {
            $msg = 'Invalid liability selected.';
            $msgType = 'danger';
        } else {
            try {
                $liability = getLiabilityById($conn, $company_id, $id);

                if (!$liability) {
                    throw new RuntimeException('Liability not found.');
                }

                if ((int)($liability['expense_id'] ?? 0) > 0) {
                    throw new RuntimeException('This liability is linked to an expense record and cannot be deleted here.');
                }

                if (getLiabilityPaymentCount($conn, $company_id, $id) > 0 || (float)$liability['paid_amount'] > 0) {
                    throw new RuntimeException('This liability already has payment history and cannot be deleted.');
                }

                $conn->begin_transaction();

                try {
                    // Reverse the loan-received ledger entry created when liability was added
                    $ledgerDesc = '[LOAN_RECEIVED:' . $id . '] Loan received - ' . $liability['liability_name'];

                    if ($liability['payment_source'] === 'Cash') {
                        $stmtDel = failIfPrepareFalse(
                            $conn->prepare("DELETE FROM cash_account WHERE company_id = ? AND description = ? LIMIT 1"),
                            'Failed to prepare cash ledger reversal.'
                        );
                        $stmtDel->bind_param("is", $company_id, $ledgerDesc);
                        $stmtDel->execute();
                        $stmtDel->close();
                    } else {
                        $stmtDel = failIfPrepareFalse(
                            $conn->prepare("DELETE FROM bank_account WHERE company_id = ? AND description = ? LIMIT 1"),
                            'Failed to prepare bank ledger reversal.'
                        );
                        $stmtDel->bind_param("is", $company_id, $ledgerDesc);
                        $stmtDel->execute();
                        $stmtDel->close();
                    }

                    $stmt = failIfPrepareFalse(
                        $conn->prepare("
                            DELETE FROM liabilities
                            WHERE company_id = ? AND liability_id = ?
                        "),
                        'Failed to prepare liability delete query'
                    );

                    $stmt->bind_param("ii", $company_id, $id);

                    if ($stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            $stmt->close();
                            $conn->commit();
                            deletePhysicalFile((string)($liability['proof_file'] ?? ''));
                            $msg = 'Liability and ledger entry deleted successfully.';
                            $msgType = 'success';

                            if ($edit_mode && (int)$edit['liability_id'] === $id) {
                                $edit_mode = false;
                                $edit = resetForm();
                                $liability_payments = [];
                            }
                        } else {
                            $stmt->close();
                            $conn->rollback();
                            throw new RuntimeException('Liability not found or already deleted.');
                        }
                    } else {
                        $error = $stmt->error;
                        $stmt->close();
                        $conn->rollback();
                        throw new RuntimeException('Delete failed: ' . $error);
                    }
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

/* =========================
   FETCH DATA
========================= */
$rows = [];

$stmt = $conn->prepare("
    SELECT
        liability_id,
        expense_id,
        liability_name,
        liability_type,
       original_amount AS amount,
        original_amount,
        paid_amount,
        balance_amount,
        liability_date,
        description,
        due_date,
        payment_source,
        bank_name,
        account_number,
        status,
        proof_file
    FROM liabilities
    WHERE company_id = ?
    ORDER BY liability_id DESC
");

if ($stmt) {
    $stmt->bind_param("i", $company_id);
    $stmt->execute();

    $stmt->bind_result(
        $liability_id,
        $expense_id,
        $liability_name,
        $liability_type,
        $amount,
        $original_amount,
        $paid_amount,
        $balance_amount,
        $liability_date,
        $description,
        $due_date,
        $payment_source,
        $bank_name,
        $account_number,
        $status,
        $proof_file
    );

    while ($stmt->fetch()) {
        $rows[] = [
            'liability_id'   => $liability_id,
            'expense_id'     => $expense_id,
            'liability_name' => $liability_name,
            'liability_type' => $liability_type,
            'amount'         => $amount,
            'original_amount'=> $original_amount,
            'paid_amount'    => $paid_amount,
            'balance_amount' => $balance_amount,
            'liability_date' => $liability_date,
            'description'    => $description,
            'due_date'       => $due_date,
            'payment_source' => $payment_source,
            'bank_name'      => $bank_name,
            'account_number' => $account_number,
            'status'         => $status,
            'proof_file'     => $proof_file
        ];
    }

    $stmt->close();
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
                <h3><?= $edit_mode ? 'Edit Liability' : 'Add Liability' ?></h3>
                <span class="badge badge-primary">Professional Entry</span>
            </div>

            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="liability_id" value="<?= e((string)$edit['liability_id']) ?>">
                    <input type="hidden" name="existing_proof_file" value="<?= e((string)$edit['proof_file']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Liability Name</label>
                            <input type="text" name="liability_name" class="form-control"
                                placeholder="Loan / Payable / Advance" value="<?= e((string)$edit['liability_name']) ?>"
                                maxlength="255" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Liability Type</label>
                            <input type="text" name="liability_type" class="form-control"
                                placeholder="Short Term / Long Term / Other"
                                value="<?= e((string)$edit['liability_type']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Amount</label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control"
                                value="<?= e((string)$edit['amount']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Paid Amount</label>
                            <input type="text" class="form-control"
                                value="Rs. <?= number_format((float)($edit['paid_amount'] !== '' ? $edit['paid_amount'] : 0), 2) ?>"
                                readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Balance Amount</label>
                            <input type="text" class="form-control"
                                value="Rs. <?= number_format((float)($edit['balance_amount'] !== '' ? $edit['balance_amount'] : 0), 2) ?>"
                                readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Liability Date</label>
                            <input type="date" name="liability_date" class="form-control"
                                value="<?= e((string)$edit['liability_date']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" class="form-control"
                                value="<?= e((string)$edit['due_date']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Source</label>
                            <select name="payment_source" class="form-control" id="liabilityPaymentSource"
                                onchange="toggleLiabilityBankFields(this.value)">
                                <option value="Cash"
                                    <?= ($edit['payment_source'] ?? 'Cash') === 'Cash' ? 'selected' : '' ?>>Cash
                                </option>
                                <option value="Bank"
                                    <?= ($edit['payment_source'] ?? '') === 'Bank' ? 'selected' : '' ?>>Bank</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <input type="text" class="form-control" value="<?= e((string)$edit['status']) ?>" readonly>
                        </div>

                        <div class="form-group hidden" id="liabilityBankNameWrap">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control"
                                value="<?= e((string)$edit['bank_name']) ?>">
                        </div>

                        <div class="form-group hidden" id="liabilityAccountNoWrap">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" class="form-control"
                                value="<?= e((string)$edit['account_number']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Proof File</label>
                            <input type="file" name="proof_file" class="form-control"
                                accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                            <?php if (!empty($edit['proof_file'])): ?>
                            <small class="text-muted">
                                Current file:
                                <a href="uploads/transactions/<?= e((string)$edit['proof_file']) ?>"
                                    target="_blank">View</a>
                            </small>
                            <?php endif; ?>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control"
                                placeholder="Enter liability details"><?= e((string)$edit['description']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="save_liability" class="btn btn-primary">
                                <?= $edit_mode ? 'Update Liability' : 'Save Liability' ?>
                            </button>

                            <?php if ($edit_mode): ?>
                            <a href="liabilities.php" class="btn btn-light">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($edit_mode): ?>
        <div class="card mt-24">
            <div class="card-header">
                <h3>Record Liability Payment</h3>
                <span class="badge badge-primary">Payment Tracking</span>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="liability_id" value="<?= e((string)$edit['liability_id']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Amount</label>
                            <input type="number" step="0.01" min="0.01" name="payment_amount" class="form-control"
                                required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Source</label>
                            <select name="payment_source" class="form-control" id="liabilityPaymentMode"
                                onchange="toggleLiabilityPaymentBankFields(this.value)">
                                <option value="Cash">Cash</option>
                                <option value="Bank">Bank</option>
                            </select>
                        </div>

                        <div class="form-group hidden" id="liabilityPaymentBankNameWrap">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="payment_bank_name" class="form-control">
                        </div>

                        <div class="form-group hidden" id="liabilityPaymentAccountNoWrap">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="payment_account_number" class="form-control">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Reference No</label>
                            <input type="text" name="reference_no" class="form-control">
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" placeholder="Optional payment notes"></textarea>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Proof File</label>
                            <input type="file" name="payment_proof_file" class="form-control"
                                accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                        </div>
                    </div>

                    <div class="form-group full">
                        <button type="submit" name="save_liability_payment" class="btn btn-secondary">
                            Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($liability_payments)): ?>
        <div class="card mt-24">
            <div class="card-header">
                <h3>Payment History</h3>
            </div>
            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Source</th>
                                <th>Reference</th>
                                <th>Notes</th>
                                <th>Proof</th>
                                <th>Recorded</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($liability_payments as $payment): ?>
                            <tr>
                                <td><?= e((string)$payment['payment_id']) ?></td>
                                <td><?= e((string)$payment['payment_date']) ?></td>
                                <td>Rs. <?= number_format((float)$payment['amount'], 2) ?></td>
                                <td><?= e((string)$payment['payment_source']) ?></td>
                                <td><?= e((string)($payment['reference_no'] ?? '')) ?></td>
                                <td><?= e((string)($payment['notes'] ?? '')) ?></td>
                                <td>
                                    <?php if (!empty($payment['proof_file'])): ?>
                                    <a href="uploads/transactions/<?= e((string)$payment['proof_file']) ?>"
                                        target="_blank">View</a>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string)($payment['created_at'] ?? '')) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>

        <div class="card<?= $canCrud ? ' mt-24' : '' ?>">
            <div class="card-header">
                <h3>Liability Records</h3>
                <span class="badge badge-success"><?= count($rows) ?> Records</span>
            </div>
            <div class="card-body" style="padding-bottom: 0;">
                <div class="form-group">
                    <input type="text" id="liabilitySearch" class="form-control"
                        placeholder="Search by name, type, status, source, due date...">
                </div>
            </div>
            <div class="card-body">
                <div class="table-wrap">
                    <table class="table" id="liabilityTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Original</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Due Date</th>
                                <th>Source</th>
                                <th>Proof</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e((string)($row['liability_id'] ?? '')) ?></td>
                                <td><?= e((string)($row['liability_name'] ?? '')) ?></td>
                                <td><?= e((string)($row['liability_type'] ?? '')) ?></td>
                                <td>Rs. <?= number_format((float)($row['original_amount'] ?? 0), 2) ?></td>
                                <td>Rs. <?= number_format((float)($row['paid_amount'] ?? 0), 2) ?></td>
                                <td>Rs. <?= number_format((float)($row['balance_amount'] ?? 0), 2) ?></td>
                                <td>
                                    <span class="badge badge-primary">
                                        <?= e((string)($row['status'] ?? 'Active')) ?>
                                    </span>
                                </td>
                                <td><?= e((string)($row['due_date'] ?? '')) ?></td>
                                <td><?= e((string)($row['payment_source'] ?? 'Cash')) ?></td>
                                <td>
                                    <?php if (!empty($row['proof_file'])): ?>
                                    <a href="uploads/transactions/<?= e((string)$row['proof_file']) ?>"
                                        target="_blank">View</a>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($canCrud): ?>
                                    <a class="btn btn-light" href="?edit=<?= (int)$row['liability_id'] ?>">Edit</a>

                                    <form method="POST" class="inline-form"
                                        onsubmit="return confirm('Delete this liability record?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="liability_id"
                                            value="<?= (int)$row['liability_id'] ?>">
                                        <button type="submit" name="delete_liability"
                                            class="btn btn-danger">Delete</button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-muted">View Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="11">No liability records found.</td>
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
function toggleLiabilityBankFields(value) {
    const bankWrap = document.getElementById('liabilityBankNameWrap');
    const accountWrap = document.getElementById('liabilityAccountNoWrap');

    if (!bankWrap || !accountWrap) return;

    bankWrap.style.display = value === 'Bank' ? 'block' : 'none';
    accountWrap.style.display = value === 'Bank' ? 'block' : 'none';
}

function toggleLiabilityPaymentBankFields(value) {
    const bankWrap = document.getElementById('liabilityPaymentBankNameWrap');
    const accountWrap = document.getElementById('liabilityPaymentAccountNoWrap');

    if (!bankWrap || !accountWrap) return;

    bankWrap.style.display = value === 'Bank' ? 'block' : 'none';
    accountWrap.style.display = value === 'Bank' ? 'block' : 'none';
}

const liabilityPaymentSource = document.getElementById('liabilityPaymentSource');
if (liabilityPaymentSource) {
    toggleLiabilityBankFields(liabilityPaymentSource.value);
    liabilityPaymentSource.addEventListener('change', function() {
        toggleLiabilityBankFields(this.value);
    });
}

const liabilityPaymentMode = document.getElementById('liabilityPaymentMode');
if (liabilityPaymentMode) {
    toggleLiabilityPaymentBankFields(liabilityPaymentMode.value);
    liabilityPaymentMode.addEventListener('change', function() {
        toggleLiabilityPaymentBankFields(this.value);
    });
}
const liabilitySearch = document.getElementById('liabilitySearch');
const liabilityTable = document.getElementById('liabilityTable');

if (liabilitySearch && liabilityTable) {
    liabilitySearch.addEventListener('keyup', function() {
        const searchValue = this.value.toLowerCase().trim();
        const rows = liabilityTable.querySelectorAll('tbody tr');

        rows.forEach(function(row) {
            const rowText = row.innerText.toLowerCase();
            row.style.display = rowText.includes(searchValue) ? '' : 'none';
        });
    });
}
</script>