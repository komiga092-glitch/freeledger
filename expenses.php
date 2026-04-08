<?php

declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

set_security_headers();

if (!isset($_SESSION['user_id'])) {
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

$canView = in_array($currentRole, ['organization', 'auditor', 'accountant'], true);
$canCrud = ($currentRole === 'accountant');

if (!$canView) {
    header("Location: dashboard.php");
    exit;
}

function resetExpenseForm(): array
{
    return [
        'expense_id' => '',
        'expense_type' => '',
        'amount' => '',
        'expense_date' => date('Y-m-d'),
        'description' => '',
        'payment_source' => 'Cash',
        'bank_name' => '',
        'account_number' => ''
    ];
}

function deleteExpenseLedgerEntries(mysqli $conn, int $company_id, int $expense_id): void
{
    $tag = '[EXPENSE:' . $expense_id . ']';
    $like = '%' . $tag . '%';

    $stmt = $conn->prepare("DELETE FROM cash_account WHERE company_id = ? AND description LIKE ?");
    if ($stmt) {
        $stmt->bind_param("is", $company_id, $like);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("DELETE FROM bank_account WHERE company_id = ? AND description LIKE ?");
    if ($stmt) {
        $stmt->bind_param("is", $company_id, $like);
        $stmt->execute();
        $stmt->close();
    }
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
    string $account_number
): void {
    $description = '[EXPENSE:' . $expense_id . '] Expense: ' . $expense_type;

    if ($payment_source === 'Cash') {
        $stmt = $conn->prepare("
            INSERT INTO cash_account (
                company_id,
                transaction_date,
                description,
                transaction_type,
                amount
            ) VALUES (?, ?, ?, 'Cash Out', ?)
        ");

        if (!$stmt) {
            throw new Exception('Failed to prepare cash ledger entry.');
        }

        $stmt->bind_param("issd", $company_id, $expense_date, $description, $amount);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO bank_account (
            company_id,
            transaction_date,
            description,
            amount,
            transaction_type,
            bank_name,
            account_number
        ) VALUES (?, ?, ?, ?, 'Withdrawal', ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('Failed to prepare bank ledger entry.');
    }

    $stmt->bind_param("issdss", $company_id, $expense_date, $description, $amount, $bank_name, $account_number);
    $stmt->execute();
    $stmt->close();
}

$edit_mode = false;
$edit = resetExpenseForm();

/* =========================
   DELETE EXPENSE
========================= */
if (isset($_GET['delete'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can delete expense records.';
        $msgType = 'danger';
    } else {
        $expense_id = (int)($_GET['delete'] ?? 0);

        if ($expense_id > 0) {
            $conn->begin_transaction();

            try {
                deleteExpenseLedgerEntries($conn, $company_id, $expense_id);

                $stmt = $conn->prepare("DELETE FROM expenses WHERE company_id = ? AND expense_id = ?");
                if (!$stmt) {
                    throw new Exception('Failed to prepare delete query.');
                }

                $stmt->bind_param("ii", $company_id, $expense_id);
                $stmt->execute();

                if ($stmt->affected_rows <= 0) {
                    $stmt->close();
                    throw new Exception('Expense record not found or already deleted.');
                }

                $stmt->close();
                $conn->commit();

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
            $edit = $row;
            $edit_mode = true;
        }

        $stmt->close();
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
        $bank_name      = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');

        if (!in_array($payment_source, ['Cash', 'Bank'], true)) {
            $payment_source = 'Cash';
        }

        if ($payment_source !== 'Bank') {
            $bank_name = '';
            $account_number = '';
        }

        if ($expense_type === '') {
            $msg = 'Expense type is required.';
            $msgType = 'danger';
        } elseif ($amount <= 0) {
            $msg = 'Amount must be greater than 0.';
            $msgType = 'danger';
        } elseif ($expense_date === '') {
            $msg = 'Expense date is required.';
            $msgType = 'danger';
        } elseif ($payment_source === 'Bank' && ($bank_name === '' || $account_number === '')) {
            $msg = 'Bank name and account number are required for bank payments.';
            $msgType = 'danger';
        } else {
            $conn->begin_transaction();

            try {
                if ($expense_id > 0) {
                    $stmt = $conn->prepare("
                        UPDATE expenses
                        SET expense_type = ?, amount = ?, expense_date = ?, description = ?, payment_source = ?, bank_name = ?, account_number = ?
                        WHERE company_id = ? AND expense_id = ?
                    ");

                    if (!$stmt) {
                        throw new Exception('Failed to prepare update query.');
                    }

                    $stmt->bind_param(
                        "sdsssssii",
                        $expense_type,
                        $amount,
                        $expense_date,
                        $description,
                        $payment_source,
                        $bank_name,
                        $account_number,
                        $company_id,
                        $expense_id
                    );
                    $stmt->execute();
                    $stmt->close();

                    deleteExpenseLedgerEntries($conn, $company_id, $expense_id);

                    insertExpenseLedgerEntry(
                        $conn,
                        $company_id,
                        $expense_id,
                        $expense_type,
                        $expense_date,
                        $amount,
                        $payment_source,
                        $bank_name,
                        $account_number
                    );

                    $conn->commit();

                    $msg = 'Expense record updated successfully.';
                    $msgType = 'success';
                    $edit_mode = false;
                    $edit = resetExpenseForm();
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO expenses (
                            company_id,
                            expense_type,
                            amount,
                            expense_date,
                            description,
                            payment_source,
                            bank_name,
                            account_number
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    if (!$stmt) {
                        throw new Exception('Failed to prepare insert query.');
                    }

                    $stmt->bind_param(
                        "isdsssss",
                        $company_id,
                        $expense_type,
                        $amount,
                        $expense_date,
                        $description,
                        $payment_source,
                        $bank_name,
                        $account_number
                    );
                    $stmt->execute();
                    $new_expense_id = (int)$stmt->insert_id;
                    $stmt->close();

                    insertExpenseLedgerEntry(
                        $conn,
                        $company_id,
                        $new_expense_id,
                        $expense_type,
                        $expense_date,
                        $amount,
                        $payment_source,
                        $bank_name,
                        $account_number
                    );

                    $conn->commit();

                    $msg = 'Expense record added successfully.';
                    $msgType = 'success';
                    $edit = resetExpenseForm();
                }
            } catch (Throwable $e) {
                $conn->rollback();
                $msg = 'Failed to save expense record: ' . $e->getMessage();
                $msgType = 'danger';

                $edit = [
                    'expense_id' => $expense_id,
                    'expense_type' => $expense_type,
                    'amount' => $amount,
                    'expense_date' => $expense_date,
                    'description' => $description,
                    'payment_source' => $payment_source,
                    'bank_name' => $bank_name,
                    'account_number' => $account_number
                ];
                $edit_mode = $expense_id > 0;
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
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="page-heading">
                <h1>Expense Management</h1>
                <p><?= e($pageDescription) ?></p>
            </div>
        </div>

        <div class="topbar-right">
            <div class="company-pill"><?= e($_SESSION['company_name'] ?? 'Company') ?></div>
            <div class="role-pill"><?= e($_SESSION['role'] ?? 'User') ?></div>
            <div class="user-chip">
                <div class="avatar"><?= e(strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1))) ?></div>
                <div class="meta">
                    <strong><?= e($_SESSION['full_name'] ?? 'User') ?></strong>
                    <span><?= e($_SESSION['email'] ?? '') ?></span>
                </div>
            </div>
        </div>
    </div>

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
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="expense_id" value="<?= e($edit['expense_id']) ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Expense Type</label>
                                <input type="text" name="expense_type" class="form-control"
                                    placeholder="Office / Salary / Travel / Utility" value="<?= e($edit['expense_type']) ?>"
                                    required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Amount</label>
                                <input type="number" step="0.01" min="0.01" name="amount" class="form-control"
                                    value="<?= e($edit['amount']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Expense Date</label>
                                <input type="date" name="expense_date" class="form-control"
                                    value="<?= e($edit['expense_date']) ?>" required>
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

                            <div class="form-group" id="expenseBankNameWrap" style="display:none;">
                                <label class="form-label">Bank Name</label>
                                <input type="text" name="bank_name" class="form-control"
                                    value="<?= e($edit['bank_name']) ?>">
                            </div>

                            <div class="form-group" id="expenseAccountNoWrap" style="display:none;">
                                <label class="form-label">Account Number</label>
                                <input type="text" name="account_number" class="form-control"
                                    value="<?= e($edit['account_number']) ?>">
                            </div>

                            <div class="form-group full">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control"
                                    placeholder="Enter remarks"><?= e($edit['description']) ?></textarea>
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
                                <th>Payment</th>
                                <th>Description</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?= e($row['expense_id']) ?></td>
                                        <td><?= e($row['expense_type']) ?></td>
                                        <td>Rs. <?= number_format((float)$row['amount'], 2) ?></td>
                                        <td><?= e($row['expense_date']) ?></td>
                                        <td>
                                            <span class="badge badge-primary"><?= e($row['payment_source']) ?></span>
                                        </td>
                                        <td><?= e($row['description']) ?></td>
                                        <td>
                                            <?php if ($canCrud): ?>
                                                <a class="btn btn-light" href="?edit=<?= (int)$row['expense_id'] ?>">Edit</a>
                                                <a class="btn btn-danger" href="?delete=<?= (int)$row['expense_id'] ?>&csrf_token=<?= urlencode(get_csrf_token()) ?>"
                                                    onclick="return confirm('Delete this expense record?')">Delete</a>
                                            <?php else: ?>
                                                <span class="text-muted">View Only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7">No expense records found.</td>
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

        if (!bankWrap || !accWrap) {
            return;
        }

        bankWrap.style.display = value === 'Bank' ? 'block' : 'none';
        accWrap.style.display = value === 'Bank' ? 'block' : 'none';
    }

    const expensePaymentSource = document.getElementById('expensePaymentSource');
    if (expensePaymentSource) {
        toggleExpenseBankFields(expensePaymentSource.value);
    }
</script>