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

$pageTitle = 'Expense Management';
$pageDescription = 'Add and manage company expense records';

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
function resetExpenseForm(): array
{
    return [
        'expense_id' => '',
        'expense_type' => '',
        'amount' => '',
        'expense_date' => date('Y-m-d'),
        'description' => '',
        'payment_source' => 'Cash',
        'payment_method' => 'Paid',
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

function isValidIsoDate(string $date): bool
{
    $dateTime = DateTime::createFromFormat('Y-m-d', $date);
    return $dateTime !== false && $dateTime->format('Y-m-d') === $date;
}

function getExpenseLedgerTag(int $expense_id): string
{
    return '[EXPENSE:' . $expense_id . ']';
}

function deleteExpenseLedgerEntries(mysqli $conn, int $company_id, int $expense_id): void
{
    $tag = getExpenseLedgerTag($expense_id);
    $like = '%' . $tag . '%';

    $stmt = failIfPrepareFalse(
        $conn->prepare("DELETE FROM cash_account WHERE company_id = ? AND description LIKE ?"),
        'Failed to prepare cash ledger delete query.'
    );
    $stmt->bind_param("is", $company_id, $like);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Cash ledger delete failed: ' . $error);
    }
    $stmt->close();

    $stmt = failIfPrepareFalse(
        $conn->prepare("DELETE FROM bank_account WHERE company_id = ? AND description LIKE ?"),
        'Failed to prepare bank ledger delete query.'
    );
    $stmt->bind_param("is", $company_id, $like);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Bank ledger delete failed: ' . $error);
    }
    $stmt->close();
}

function insertExpenseLedgerEntry(
    mysqli $conn,
    int $company_id,
    int $expense_id,
    string $expense_type,
    string $expense_date,
    float $amount,
    string $payment_source,
    string $bank_name,
    string $account_number,
    string $proof_file = ''
): void {
    $description = getExpenseLedgerTag($expense_id) . ' Expense: ' . $expense_type;

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
                ) VALUES (?, ?, ?, 'Cash Out', ?, ?)
            "),
            'Failed to prepare cash ledger insert query.'
        );

        $stmt->bind_param("issds", $company_id, $expense_date, $description, $amount, $proof_file);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Cash ledger insert failed: ' . $error);
        }

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
            ) VALUES (?, ?, ?, ?, 'Withdrawal', ?, ?, ?)
        "),
        'Failed to prepare bank ledger insert query.'
    );

    $stmt->bind_param(
        "issdsss",
        $company_id,
        $expense_date,
        $description,
        $amount,
        $bank_name,
        $account_number,
        $proof_file
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Bank ledger insert failed: ' . $error);
    }

    $stmt->close();
}

function handleExpenseProofUpload(array $file): string
{
    if (empty($file['name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Expense proof upload failed with error code ' . (int)$file['error']);
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
    $maxFileSize = 5 * 1024 * 1024;

    $originalName = basename((string)$file['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Invalid file type. Only JPG, JPEG, PNG, PDF, DOC, DOCX allowed.');
    }

    if ((int)$file['size'] > $maxFileSize) {
        throw new RuntimeException('File size too large. Maximum 5MB allowed.');
    }

    $uploadDir = __DIR__ . '/uploads/transactions';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $safeName = uniqid('expense_' . $GLOBALS['company_id'] . '_', true) . '.' . $extension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

    if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Failed to move uploaded file.');
    }

    return $safeName;
}

function deletePhysicalProofFile(string $fileName): void
{
    if ($fileName === '') {
        return;
    }

    $filePath = __DIR__ . '/uploads/transactions/' . $fileName;
    if (is_file($filePath)) {
        @unlink($filePath);
    }
}

function getLinkedLiability(mysqli $conn, int $company_id, int $expense_id): ?array
{
    $stmt = failIfPrepareFalse(
        $conn->prepare("
            SELECT liability_id, original_amount, paid_amount, balance_amount, status, proof_file
            FROM liabilities
            WHERE company_id = ? AND expense_id = ?
            LIMIT 1
        "),
        'Failed to prepare linked liability query.'
    );

    $stmt->bind_param("ii", $company_id, $expense_id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Linked liability lookup failed: ' . $error);
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
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

    $stmt->bind_param("ii", $company_id, $liability_id);

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

function upsertExpenseLiability(
    mysqli $conn,
    int $company_id,
    int $expense_id,
    string $expense_type,
    float $amount,
    string $expense_date,
    string $description,
    string $payment_source,
    string $bank_name,
    string $account_number,
    int $user_id,
    string $proof_file
): void {
    $existing = getLinkedLiability($conn, $company_id, $expense_id);
    $liability_name = 'Credit Purchase - ' . $expense_type;
    $due_date = date('Y-m-d', strtotime($expense_date . ' +30 days'));

    if ($existing) {
        $liability_id = (int)$existing['liability_id'];
        $paid_amount = (float)$existing['paid_amount'];
        $balance_amount = max(0, $amount - $paid_amount);
        $status = $balance_amount <= 0 ? 'Paid' : 'Active';

        $stmt = failIfPrepareFalse(
            $conn->prepare("
                UPDATE liabilities
                SET
                    liability_name = ?,
                    liability_type = 'Credit Purchase',
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
                WHERE liability_id = ? AND company_id = ?
            "),
            'Failed to prepare liability update query.'
        );

        $stmt->bind_param(
            "sdddssssssssii",
            $liability_name,
            $amount,
            $amount,
            $balance_amount,
            $expense_date,
            $description,
            $due_date,
            $payment_source,
            $bank_name,
            $account_number,
            $status,
            $proof_file,
            $liability_id,
            $company_id
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Liability update failed: ' . $error);
        }

        $stmt->close();
        return;
    }

    $stmt = failIfPrepareFalse(
        $conn->prepare("
            INSERT INTO liabilities (
                company_id,
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
                created_by,
                proof_file
            ) VALUES (?, ?, ?, 'Credit Purchase', ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)
        "),
        'Failed to prepare liability insert query.'
    );

    $stmt->bind_param(
        "iisddsssssssis",
        $company_id,
        $expense_id,
        $liability_name,
        $amount,
        $amount,
        $amount,
        $expense_date,
        $description,
        $due_date,
        $payment_source,
        $bank_name,
        $account_number,
        $user_id,
        $proof_file
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Liability insert failed: ' . $error);
    }

    $stmt->close();
}

function removeOrCloseExpenseLiability(mysqli $conn, int $company_id, int $expense_id): void
{
    $existing = getLinkedLiability($conn, $company_id, $expense_id);

    if (!$existing) {
        return;
    }

    $liability_id = (int)$existing['liability_id'];
    $paid_amount = (float)$existing['paid_amount'];

    if ($paid_amount > 0 || getLiabilityPaymentCount($conn, $company_id, $liability_id) > 0) {
        throw new RuntimeException('This expense already has liability payments. You cannot convert or delete it directly.');
    }

    $stmt = failIfPrepareFalse(
        $conn->prepare("DELETE FROM liabilities WHERE liability_id = ? AND company_id = ?"),
        'Failed to prepare liability delete query.'
    );

    $stmt->bind_param("ii", $liability_id, $company_id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Liability delete failed: ' . $error);
    }

    $stmt->close();
}

/* =========================
   EDIT DEFAULTS
========================= */
$edit_mode = false;
$edit = resetExpenseForm();

/* =========================
   DELETE EXPENSE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_expense'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can delete expense records.';
        $msgType = 'danger';
    } else {
        $expense_id = (int)($_POST['expense_id'] ?? 0);

        if ($expense_id <= 0) {
            $msg = 'Invalid expense selected.';
            $msgType = 'danger';
        } else {
            $proofFileToDelete = '';

            try {
                $stmt = failIfPrepareFalse(
                    $conn->prepare("
                        SELECT proof_file, salary_id
                        FROM expenses
                        WHERE company_id = ? AND expense_id = ?
                        LIMIT 1
                    "),
                    'Failed to prepare expense delete lookup query.'
                );

                $stmt->bind_param("ii", $company_id, $expense_id);

                if (!$stmt->execute()) {
                    throw new RuntimeException('Expense delete lookup failed: ' . $stmt->error);
                }

                $stmt->bind_result($foundProofFile, $linkedSalaryId);
                if ($stmt->fetch()) {
                    $proofFileToDelete = (string)($foundProofFile ?? '');
                    $linkedSalaryId = (int)($linkedSalaryId ?? 0);
                } else {
                    $stmt->close();
                    throw new RuntimeException('Expense record not found.');
                }
                $stmt->close();

                if ($linkedSalaryId > 0) {
                    throw new RuntimeException('This expense is linked to a salary record and cannot be deleted here.');
                }

                $conn->begin_transaction();

                $expenseLiability = getLinkedLiability($conn, $company_id, $expense_id);
                if ($expenseLiability) {
                    removeOrCloseExpenseLiability($conn, $company_id, $expense_id);
                }

                deleteExpenseLedgerEntries($conn, $company_id, $expense_id);

                $stmt = failIfPrepareFalse(
                    $conn->prepare("DELETE FROM expenses WHERE company_id = ? AND expense_id = ?"),
                    'Failed to prepare expense delete query.'
                );

                $stmt->bind_param("ii", $company_id, $expense_id);

                if (!$stmt->execute()) {
                    $error = $stmt->error;
                    $stmt->close();
                    throw new RuntimeException('Expense delete failed: ' . $error);
                }

                if ($stmt->affected_rows <= 0) {
                    $stmt->close();
                    throw new RuntimeException('Expense record not found or already deleted.');
                }

                $stmt->close();
                $conn->commit();

                deletePhysicalProofFile($proofFileToDelete);

                $msg = 'Expense record deleted successfully.';
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
   EDIT FETCH
========================= */
if (isset($_GET['edit'])) {
    $expense_id = (int)($_GET['edit'] ?? 0);

    if ($expense_id > 0) {
        $stmt = $conn->prepare("
            SELECT *
            FROM expenses
            WHERE company_id = ? AND expense_id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("ii", $company_id, $expense_id);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($row = $res->fetch_assoc()) {
                $edit = [
                    'expense_id' => $row['expense_id'] ?? '',
                    'expense_type' => $row['expense_type'] ?? '',
                    'amount' => $row['amount'] ?? '',
                    'expense_date' => $row['expense_date'] ?? date('Y-m-d'),
                    'description' => $row['description'] ?? '',
                    'payment_source' => $row['payment_source'] ?? 'Cash',
                    'payment_method' => $row['payment_method'] ?? 'Paid',
                    'bank_name' => $row['bank_name'] ?? '',
                    'account_number' => $row['account_number'] ?? '',
                    'proof_file' => $row['proof_file'] ?? ''
                ];
                $edit_mode = true;
            }

            $stmt->close();
        }
    }
}

/* =========================
   ADD / UPDATE EXPENSE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_expense'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can add or edit expense records.';
        $msgType = 'danger';
    } else {
        $expense_id     = (int)($_POST['expense_id'] ?? 0);
        $expense_type   = trim($_POST['expense_type'] ?? '');
        $amount         = (float)($_POST['amount'] ?? 0);
        $expense_date   = trim($_POST['expense_date'] ?? '');
        $description    = trim($_POST['description'] ?? '');
        $payment_source = trim($_POST['payment_source'] ?? 'Cash');
        $payment_method = trim($_POST['payment_method'] ?? 'Paid');
        $bank_name      = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $existing_proof_file = trim($_POST['existing_proof_file'] ?? '');
        $proof_file = $existing_proof_file;

        if (!in_array($payment_source, ['Cash', 'Bank'], true)) {
            $payment_source = 'Cash';
        }

        if (!in_array($payment_method, ['Paid', 'Credit'], true)) {
            $payment_method = 'Paid';
        }

        if ($payment_source !== 'Bank') {
            $bank_name = '';
            $account_number = '';
        }

        $edit = [
            'expense_id' => (string)$expense_id,
            'expense_type' => $expense_type,
            'amount' => $amount > 0 ? number_format($amount, 2, '.', '') : '',
            'expense_date' => $expense_date,
            'description' => $description,
            'payment_source' => $payment_source,
            'payment_method' => $payment_method,
            'bank_name' => $bank_name,
            'account_number' => $account_number,
            'proof_file' => $proof_file
        ];
        $edit_mode = ($expense_id > 0);

        if ($expense_type === '') {
            $msg = 'Expense type is required.';
            $msgType = 'danger';
        } elseif (mb_strlen($expense_type) > 100) {
            $msg = 'Expense type is too long.';
            $msgType = 'danger';
        } elseif ($amount <= 0) {
            $msg = 'Amount must be greater than 0.';
            $msgType = 'danger';
        } elseif ($expense_date === '' || !isValidIsoDate($expense_date)) {
            $msg = 'Expense date is invalid.';
            $msgType = 'danger';
        } elseif ($payment_source === 'Bank' && ($bank_name === '' || $account_number === '')) {
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
                    $proof_file = handleExpenseProofUpload($_FILES['proof_file']);
                    $edit['proof_file'] = $proof_file;
                }

                $conn->begin_transaction();

                try {
                    if ($expense_id > 0) {
                        $stmt = failIfPrepareFalse(
                            $conn->prepare("
                                SELECT salary_id, proof_file
                                FROM expenses
                                WHERE company_id = ? AND expense_id = ?
                                LIMIT 1
                            "),
                            'Failed to prepare expense update validation query.'
                        );

                        $stmt->bind_param("ii", $company_id, $expense_id);

                        if (!$stmt->execute()) {
                            $error = $stmt->error;
                            $stmt->close();
                            throw new RuntimeException('Expense update validation failed: ' . $error);
                        }

                        $stmt->bind_result($linkedSalaryId, $oldProofFile);
                        if (!$stmt->fetch()) {
                            $stmt->close();
                            throw new RuntimeException('Expense record not found for update.');
                        }
                        $stmt->close();

                        if ((int)$linkedSalaryId > 0) {
                            throw new RuntimeException('This expense is linked to a salary record and cannot be edited here.');
                        }

                        $stmt = failIfPrepareFalse(
                            $conn->prepare("
                                UPDATE expenses
                                SET
                                    expense_type = ?,
                                    amount = ?,
                                    expense_date = ?,
                                    description = ?,
                                    payment_source = ?,
                                    payment_method = ?,
                                    bank_name = ?,
                                    account_number = ?,
                                    proof_file = ?
                                WHERE company_id = ? AND expense_id = ?
                            "),
                            'Failed to prepare expense update query.'
                        );

                        $stmt->bind_param(
                            "sdsssssssii",
                            $expense_type,
                            $amount,
                            $expense_date,
                            $description,
                            $payment_source,
                            $payment_method,
                            $bank_name,
                            $account_number,
                            $proof_file,
                            $company_id,
                            $expense_id
                        );

                        if (!$stmt->execute()) {
                            $error = $stmt->error;
                            $stmt->close();
                            throw new RuntimeException('Expense update failed: ' . $error);
                        }
                        $stmt->close();

                        deleteExpenseLedgerEntries($conn, $company_id, $expense_id);

                        if ($payment_method === 'Paid') {
                            insertExpenseLedgerEntry(
                                $conn,
                                $company_id,
                                $expense_id,
                                $expense_type,
                                $expense_date,
                                $amount,
                                $payment_source,
                                $bank_name,
                                $account_number,
                                $proof_file
                            );

                            $linkedLiability = getLinkedLiability($conn, $company_id, $expense_id);
                            if ($linkedLiability) {
                                removeOrCloseExpenseLiability($conn, $company_id, $expense_id);
                            }
                        } else {
                            upsertExpenseLiability(
                                $conn,
                                $company_id,
                                $expense_id,
                                $expense_type,
                                $amount,
                                $expense_date,
                                $description,
                                $payment_source,
                                $bank_name,
                                $account_number,
                                $user_id,
                                $proof_file
                            );
                        }

                        $conn->commit();

                        if ($proof_file !== '' && $oldProofFile !== '' && $proof_file !== $oldProofFile) {
                            deletePhysicalProofFile((string)$oldProofFile);
                        }

                        $msg = 'Expense record updated successfully.';
                        $msgType = 'success';
                        $edit_mode = false;
                        $edit = resetExpenseForm();
                    } else {
                        $stmt = failIfPrepareFalse(
                            $conn->prepare("
                                INSERT INTO expenses (
                                    company_id,
                                    expense_type,
                                    amount,
                                    expense_date,
                                    description,
                                    payment_source,
                                    payment_method,
                                    bank_name,
                                    account_number,
                                    proof_file
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            "),
                            'Failed to prepare expense insert query.'
                        );

                        $stmt->bind_param(
                            "isdsssssss",
                            $company_id,
                            $expense_type,
                            $amount,
                            $expense_date,
                            $description,
                            $payment_source,
                            $payment_method,
                            $bank_name,
                            $account_number,
                            $proof_file
                        );

                        if (!$stmt->execute()) {
                            $error = $stmt->error;
                            $stmt->close();
                            throw new RuntimeException('Expense insert failed: ' . $error);
                        }

                        $new_expense_id = (int)$stmt->insert_id;
                        $stmt->close();

                        if ($payment_method === 'Paid') {
                            insertExpenseLedgerEntry(
                                $conn,
                                $company_id,
                                $new_expense_id,
                                $expense_type,
                                $expense_date,
                                $amount,
                                $payment_source,
                                $bank_name,
                                $account_number,
                                $proof_file
                            );
                        } else {
                            upsertExpenseLiability(
                                $conn,
                                $company_id,
                                $new_expense_id,
                                $expense_type,
                                $amount,
                                $expense_date,
                                $description,
                                $payment_source,
                                $bank_name,
                                $account_number,
                                $user_id,
                                $proof_file
                            );
                        }

                        $conn->commit();

                        $msg = 'Expense record added successfully.';
                        $msgType = 'success';
                        $edit_mode = false;
                        $edit = resetExpenseForm();
                    }
                } catch (Throwable $e) {
                    $conn->rollback();

                    if ($proof_file !== '' && $proof_file !== $existing_proof_file) {
                        deletePhysicalProofFile($proof_file);
                    }

                    throw $e;
                }
            } catch (Throwable $e) {
                $msg = 'Failed to save expense record: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

/* =========================
   FETCH ALL EXPENSE ROWS
========================= */
$rows = [];
$stmt = $conn->prepare("
    SELECT *
    FROM expenses
    WHERE company_id = ?
    ORDER BY expense_date DESC, expense_id DESC
");

if ($stmt) {
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
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
                <h3><?= $edit_mode ? 'Edit Expense' : 'Add Expense' ?></h3>
                <span class="badge badge-primary">Professional Entry</span>
            </div>

            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="expense_id" value="<?= e((string)$edit['expense_id']) ?>">
                    <input type="hidden" name="existing_proof_file" value="<?= e((string)$edit['proof_file']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Expense Type</label>
                            <input type="text" name="expense_type" class="form-control"
                                placeholder="Office / Salary / Travel / Utility"
                                value="<?= e((string)$edit['expense_type']) ?>" maxlength="100" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Amount</label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control"
                                value="<?= e((string)$edit['amount']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Expense Date</label>
                            <input type="date" name="expense_date" class="form-control"
                                value="<?= e((string)$edit['expense_date']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Source</label>
                            <select name="payment_source" class="form-control" id="expensePaymentSource"
                                onchange="toggleExpenseBankFields(this.value)">
                                <option value="Cash"
                                    <?= ($edit['payment_source'] ?? 'Cash') === 'Cash' ? 'selected' : '' ?>>Cash
                                </option>
                                <option value="Bank"
                                    <?= ($edit['payment_source'] ?? '') === 'Bank' ? 'selected' : '' ?>>Bank</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-control">
                                <option value="Paid"
                                    <?= ($edit['payment_method'] ?? 'Paid') === 'Paid' ? 'selected' : '' ?>>Paid
                                </option>
                                <option value="Credit"
                                    <?= ($edit['payment_method'] ?? '') === 'Credit' ? 'selected' : '' ?>>Credit
                                    Purchase</option>
                            </select>
                        </div>

                        <div class="form-group hidden" id="expenseBankNameWrap">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control"
                                value="<?= e((string)$edit['bank_name']) ?>">
                        </div>

                        <div class="form-group hidden" id="expenseAccountNoWrap">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" class="form-control"
                                value="<?= e((string)$edit['account_number']) ?>">
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control"
                                placeholder="Enter remarks"><?= e((string)$edit['description']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Proof/Attachment (Optional)</label>
                            <input type="file" name="proof_file" class="form-control"
                                accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                            <small class="form-text">Upload receipts, documents, or images as proof (Max 5MB)</small>

                            <?php if ($edit_mode && !empty($edit['proof_file'])): ?>
                            <div class="mt-2">
                                <a href="uploads/transactions/<?= e((string)$edit['proof_file']) ?>" target="_blank"
                                    class="btn btn-sm btn-outline-primary">
                                    View Current File
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="save_expense" class="btn btn-primary">
                                <?= $edit_mode ? 'Update Expense' : 'Save Expense' ?>
                            </button>

                            <?php if ($edit_mode): ?>
                            <a href="expenses.php" class="btn btn-light">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card<?= $canCrud ? ' mt-24' : '' ?>">
            <div class="card-header">
                <h3>Expense Records</h3>
                <span class="badge badge-success"><?= count($rows) ?> Records</span>
            </div>

            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Source</th>
                                <th>Method</th>
                                <th>Description</th>
                                <th>Proof</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e((string)$row['expense_id']) ?></td>
                                <td><?= e((string)$row['expense_type']) ?></td>
                                <td>Rs. <?= number_format((float)$row['amount'], 2) ?></td>
                                <td><?= e((string)$row['expense_date']) ?></td>
                                <td><span class="badge badge-primary"><?= e((string)$row['payment_source']) ?></span>
                                </td>
                                <td><span class="badge badge-success"><?= e((string)$row['payment_method']) ?></span>
                                </td>
                                <td><?= e((string)$row['description']) ?></td>
                                <td>
                                    <?php if (!empty($row['proof_file'])): ?>
                                    <a href="uploads/transactions/<?= e((string)$row['proof_file']) ?>" target="_blank"
                                        class="btn btn-light">View</a>
                                    <?php else: ?>
                                    <span class="text-muted">No File</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($canCrud): ?>
                                    <a class="btn btn-light" href="?edit=<?= (int)$row['expense_id'] ?>">Edit</a>

                                    <form method="POST" class="inline-form"
                                        onsubmit="return confirm('Delete this expense record?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="expense_id" value="<?= (int)$row['expense_id'] ?>">
                                        <button type="submit" name="delete_expense"
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
                                <td colspan="9">No expense records found.</td>
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
function toggleExpenseBankFields(value) {
    const bankWrap = document.getElementById('expenseBankNameWrap');
    const accWrap = document.getElementById('expenseAccountNoWrap');

    if (!bankWrap || !accWrap) return;

    bankWrap.style.display = value === 'Bank' ? 'block' : 'none';
    accWrap.style.display = value === 'Bank' ? 'block' : 'none';
}

const expensePaymentSource = document.getElementById('expensePaymentSource');
if (expensePaymentSource) {
    toggleExpenseBankFields(expensePaymentSource.value);
    expensePaymentSource.addEventListener('change', function() {
        toggleExpenseBankFields(this.value);
    });
}
</script>