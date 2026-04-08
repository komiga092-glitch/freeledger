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

$pageTitle = 'Bank Account';
$pageDescription = 'Manage company bank ledger transactions';

$msg = '';
$msgType = 'success';

$currentRole = verify_user_role($user_id, $company_id);

$canView = in_array($currentRole, ['organization', 'auditor', 'accountant'], true);
$canCrud = ($currentRole === 'accountant');

if (!$canView) {
    header("Location: dashboard.php");
    exit;
}

function ensureBankAccountTransactionColumns(mysqli $conn): void
{
    $requiredColumns = [
        'bank_name' => "ALTER TABLE bank_account ADD COLUMN bank_name VARCHAR(255) DEFAULT NULL AFTER transaction_type",
        'account_number' => "ALTER TABLE bank_account ADD COLUMN account_number VARCHAR(50) DEFAULT NULL AFTER bank_name",
    ];

    foreach ($requiredColumns as $columnName => $alterSql) {
        $columnNameSql = $conn->real_escape_string($columnName);
        $result = $conn->query("SHOW COLUMNS FROM bank_account LIKE '{$columnNameSql}'");

        if ($result && $result->num_rows === 0) {
            $conn->query($alterSql);
        }
    }
}

ensureBankAccountTransactionColumns($conn);

function resetBankForm(): array
{
    return [
        'bank_id' => '',
        'transaction_date' => '',
        'bank_name' => '',
        'account_number' => '',
        'description' => '',
        'transaction_type' => '',
        'amount' => ''
    ];
}

$edit_mode = false;
$edit = resetBankForm();

/* =========================
   EDIT FETCH
========================= */
if (isset($_GET['edit'])) {
    $bank_id = (int)($_GET['edit'] ?? 0);

    $stmt = $conn->prepare("
        SELECT 
            bank_id,
            transaction_date,
            bank_name,
            account_number,
            description,
            transaction_type,
            amount
        FROM bank_account
        WHERE company_id = ? AND bank_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("ii", $company_id, $bank_id);
        $stmt->execute();

        $stmt->bind_result(
            $edit_bank_id,
            $edit_transaction_date,
            $edit_bank_name,
            $edit_account_number,
            $edit_description,
            $edit_transaction_type,
            $edit_amount
        );

        if ($stmt->fetch()) {
            $edit = [
                'bank_id' => $edit_bank_id,
                'transaction_date' => $edit_transaction_date,
                'bank_name' => $edit_bank_name,
                'account_number' => $edit_account_number,
                'description' => $edit_description,
                'transaction_type' => $edit_transaction_type,
                'amount' => $edit_amount
            ];
            $edit_mode = true;
        }

        $stmt->close();
    }
}

/* =========================
   SAVE BANK ENTRY
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bank'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can add or edit bank transactions.';
        $msgType = 'danger';
    } else {
        $bank_id          = (int)($_POST['bank_id'] ?? 0);
        $transaction_date = trim($_POST['transaction_date'] ?? '');
        $bank_name        = trim($_POST['bank_name'] ?? '');
        $account_number   = trim($_POST['account_number'] ?? '');
        $description      = trim($_POST['description'] ?? '');
        $transaction_type = trim($_POST['transaction_type'] ?? '');
        $amount           = (float)($_POST['amount'] ?? 0);

        if (
            $transaction_date === '' ||
            $bank_name === '' ||
            $account_number === '' ||
            $description === '' ||
            $amount <= 0 ||
            !in_array($transaction_type, ['Deposit', 'Withdrawal'], true)
        ) {
            $msg = 'Please fill all required bank fields correctly.';
            $msgType = 'danger';
        } else {
            if ($bank_id > 0) {
                $stmt = $conn->prepare("
                    UPDATE bank_account
                    SET transaction_date = ?, bank_name = ?, account_number = ?, description = ?, transaction_type = ?, amount = ?
                    WHERE company_id = ? AND bank_id = ?
                ");

                if (!$stmt) {
                    $msg = 'Failed to prepare update query.';
                    $msgType = 'danger';
                } else {
                    $stmt->bind_param(
                        "sssssdii",
                        $transaction_date,
                        $bank_name,
                        $account_number,
                        $description,
                        $transaction_type,
                        $amount,
                        $company_id,
                        $bank_id
                    );

                    if ($stmt->execute()) {
                        $msg = 'Bank transaction updated successfully.';
                        $msgType = 'success';
                        $edit_mode = false;
                        $edit = resetBankForm();
                    } else {
                        $msg = 'Failed to update bank transaction.';
                        $msgType = 'danger';
                    }

                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO bank_account
                    (
                        company_id,
                        transaction_date,
                        bank_name,
                        account_number,
                        description,
                        transaction_type,
                        amount
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    $msg = 'Failed to prepare insert query.';
                    $msgType = 'danger';
                } else {
                    $stmt->bind_param(
                        "isssssd",
                        $company_id,
                        $transaction_date,
                        $bank_name,
                        $account_number,
                        $description,
                        $transaction_type,
                        $amount
                    );

                    if ($stmt->execute()) {
                        $msg = 'Bank transaction added successfully.';
                        $msgType = 'success';
                        $edit = resetBankForm();
                    } else {
                        $msg = 'Failed to add bank transaction.';
                        $msgType = 'danger';
                    }

                    $stmt->close();
                }
            }
        }
    }
}

/* =========================
   DELETE BANK ENTRY
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bank'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can delete bank transactions.';
        $msgType = 'danger';
    } else {
        $bank_id = (int)($_POST['bank_id'] ?? 0);

        if ($bank_id > 0) {
            $stmt = $conn->prepare("
                DELETE FROM bank_account
                WHERE company_id = ? AND bank_id = ?
            ");

            if (!$stmt) {
                $msg = 'Failed to prepare delete query.';
                $msgType = 'danger';
            } else {
                $stmt->bind_param("ii", $company_id, $bank_id);

                if ($stmt->execute()) {
                    $msg = 'Bank transaction deleted successfully.';
                    $msgType = 'success';
                } else {
                    $msg = 'Delete failed.';
                    $msgType = 'danger';
                }

                $stmt->close();
            }
        }
    }
}

/* =========================
   FETCH ALL BANK ROWS
========================= */
$rows = [];

$stmt = $conn->prepare("
    SELECT
        bank_id,
        transaction_date,
        bank_name,
        account_number,
        description,
        transaction_type,
        amount
    FROM bank_account
    WHERE company_id = ?
    ORDER BY transaction_date DESC, bank_id DESC
");

if ($stmt) {
    $stmt->bind_param("i", $company_id);
    $stmt->execute();

    $stmt->bind_result(
        $row_bank_id,
        $row_transaction_date,
        $row_bank_name,
        $row_account_number,
        $row_description,
        $row_transaction_type,
        $row_amount
    );

    while ($stmt->fetch()) {
        $rows[] = [
            'bank_id' => $row_bank_id,
            'transaction_date' => $row_transaction_date,
            'bank_name' => $row_bank_name,
            'account_number' => $row_account_number,
            'description' => $row_description,
            'transaction_type' => $row_transaction_type,
            'amount' => $row_amount
        ];
    }

    $stmt->close();
}

/* =========================
   CALCULATE TOTALS
========================= */
$totalDeposit = 0.0;
$totalWithdrawal = 0.0;

foreach ($rows as $r) {
    if (($r['transaction_type'] ?? '') === 'Deposit') {
        $totalDeposit += (float)$r['amount'];
    } else {
        $totalWithdrawal += (float)$r['amount'];
    }
}

$balance = $totalDeposit - $totalWithdrawal;

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="page-heading">
                <h1>Bank Account</h1>
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

        <div class="grid grid-3">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Total Deposits</div>
                    <div class="stat-icon">🏦</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($totalDeposit, 2) ?></div>
                <div class="stat-note">All bank deposit entries</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Total Withdrawals</div>
                    <div class="stat-icon">💳</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($totalWithdrawal, 2) ?></div>
                <div class="stat-note">All bank withdrawal entries</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Bank Balance</div>
                    <div class="stat-icon">📊</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($balance, 2) ?></div>
                <div class="stat-note">Deposits - Withdrawals</div>
            </div>
        </div>

        <?php if ($canCrud): ?>
            <div class="card mt-24">
                <div class="card-header">
                    <h3><?= $edit_mode ? 'Edit Bank Transaction' : 'Add Bank Transaction' ?></h3>
                    <span class="badge badge-primary">Bank Ledger Entry</span>
                </div>

                <div class="card-body">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="bank_id" value="<?= e($edit['bank_id']) ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Transaction Date</label>
                                <input type="date" name="transaction_date" class="form-control"
                                    value="<?= e($edit['transaction_date']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Bank Name</label>
                                <input type="text" name="bank_name" class="form-control"
                                    value="<?= e($edit['bank_name']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Account Number</label>
                                <input type="text" name="account_number" class="form-control"
                                    value="<?= e($edit['account_number']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Transaction Type</label>
                                <select name="transaction_type" class="form-control" required>
                                    <option value="">Select Type</option>
                                    <option value="Deposit"
                                        <?= ($edit['transaction_type'] ?? '') === 'Deposit' ? 'selected' : '' ?>>Deposit
                                    </option>
                                    <option value="Withdrawal"
                                        <?= ($edit['transaction_type'] ?? '') === 'Withdrawal' ? 'selected' : '' ?>>
                                        Withdrawal</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Amount</label>
                                <input type="number" step="0.01" min="0.01" name="amount" class="form-control"
                                    value="<?= e($edit['amount']) ?>" required>
                            </div>

                            <div class="form-group full">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control"
                                    placeholder="Enter transaction description"
                                    required><?= e($edit['description']) ?></textarea>
                            </div>

                            <div class="form-group full">
                                <button type="submit" name="save_bank" class="btn btn-primary">
                                    <?= $edit_mode ? 'Update Bank Entry' : 'Save Bank Entry' ?>
                                </button>

                                <?php if ($edit_mode): ?>
                                    <a href="bank_account.php" class="btn btn-light">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Bank Ledger Records</h3>
                <span class="badge badge-success"><?= count($rows) ?> Records</span>
            </div>

            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Bank Name</th>
                                <th>Account No</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($rows): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?= e($row['bank_id']) ?></td>
                                        <td><?= e($row['transaction_date']) ?></td>
                                        <td><?= e($row['bank_name']) ?></td>
                                        <td><?= e($row['account_number']) ?></td>
                                        <td><?= e($row['description']) ?></td>
                                        <td>
                                            <?php $cls = (($row['transaction_type'] ?? '') === 'Deposit') ? 'badge-success' : 'badge-danger'; ?>
                                            <span class="badge <?= e($cls) ?>"><?= e($row['transaction_type']) ?></span>
                                        </td>
                                        <td>Rs. <?= number_format((float)$row['amount'], 2) ?></td>
                                        <td>
                                            <?php if ($canCrud): ?>
                                                <a class="btn btn-light" href="?edit=<?= (int)$row['bank_id'] ?>">Edit</a>

                                                <form method="POST" style="display:inline-block;"
                                                    onsubmit="return confirm('Delete this bank entry?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="bank_id" value="<?= (int)$row['bank_id'] ?>">
                                                    <button type="submit" name="delete_bank" class="btn btn-danger">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">View Only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">No bank records found.</td>
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