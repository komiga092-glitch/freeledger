<?php

declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

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

$pageTitle = 'Cash Account';
$pageDescription = 'Manage company cash ledger transactions';

$msg = '';
$msgType = 'success';

$currentRole = verify_user_role($user_id, $company_id);
$canView = in_array($currentRole, ['organization', 'auditor', 'accountant'], true);
$canCrud = ($currentRole === 'accountant');

if (!$canView) {
    header("Location: dashboard.php");
    exit;
}

function resetCashForm(): array
{
    return [
        'cash_id' => '',
        'transaction_date' => date('Y-m-d'),
        'description' => '',
        'transaction_type' => '',
        'amount' => ''
    ];
}

$edit_mode = false;
$edit = resetCashForm();

/* EDIT FETCH */
if (isset($_GET['edit'])) {
    $cash_id = (int)($_GET['edit'] ?? 0);

    $stmt = $conn->prepare("
        SELECT cash_id, transaction_date, description, transaction_type, amount
        FROM cash_account
        WHERE company_id = ? AND cash_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("ii", $company_id, $cash_id);
        $stmt->execute();
        $stmt->bind_result($cash_id_r, $transaction_date_r, $description_r, $transaction_type_r, $amount_r);

        if ($stmt->fetch()) {
            $edit = [
                'cash_id' => $cash_id_r,
                'transaction_date' => $transaction_date_r,
                'description' => $description_r,
                'transaction_type' => $transaction_type_r,
                'amount' => $amount_r
            ];
            $edit_mode = true;
        }

        $stmt->close();
    }
}

/* SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cash'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can add or edit cash transactions.';
        $msgType = 'danger';
    } else {
        $cash_id          = (int)($_POST['cash_id'] ?? 0);
        $transaction_date = trim($_POST['transaction_date'] ?? '');
        $description      = trim($_POST['description'] ?? '');
        $transaction_type = trim($_POST['transaction_type'] ?? '');
        $amount           = (float)($_POST['amount'] ?? 0);

        if (
            $transaction_date === '' ||
            $description === '' ||
            $amount <= 0 ||
            !in_array($transaction_type, ['Cash In', 'Cash Out'], true)
        ) {
            $msg = 'Please fill all required fields correctly.';
            $msgType = 'danger';
        } else {
            if ($cash_id > 0) {
                $stmt = $conn->prepare("
                    UPDATE cash_account
                    SET transaction_date = ?, description = ?, transaction_type = ?, amount = ?
                    WHERE company_id = ? AND cash_id = ?
                ");

                if ($stmt) {
                    $stmt->bind_param("sssdii", $transaction_date, $description, $transaction_type, $amount, $company_id, $cash_id);

                    if ($stmt->execute()) {
                        $msg = 'Cash transaction updated successfully.';
                        $msgType = 'success';
                        $edit_mode = false;
                        $edit = resetCashForm();
                    } else {
                        $msg = 'Failed to update cash transaction.';
                        $msgType = 'danger';
                    }

                    $stmt->close();
                } else {
                    $msg = 'Failed to prepare update query.';
                    $msgType = 'danger';
                }
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO cash_account (company_id, transaction_date, description, transaction_type, amount)
                    VALUES (?, ?, ?, ?, ?)
                ");

                if ($stmt) {
                    $stmt->bind_param("isssd", $company_id, $transaction_date, $description, $transaction_type, $amount);

                    if ($stmt->execute()) {
                        $msg = 'Cash transaction added successfully.';
                        $msgType = 'success';
                        $edit = resetCashForm();
                    } else {
                        $msg = 'Failed to add cash transaction.';
                        $msgType = 'danger';
                    }

                    $stmt->close();
                } else {
                    $msg = 'Failed to prepare insert query.';
                    $msgType = 'danger';
                }
            }
        }
    }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cash'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can delete cash transactions.';
        $msgType = 'danger';
    } else {
        $cash_id = (int)($_POST['cash_id'] ?? 0);

        if ($cash_id > 0) {
            $stmt = $conn->prepare("DELETE FROM cash_account WHERE company_id = ? AND cash_id = ?");

            if ($stmt) {
                $stmt->bind_param("ii", $company_id, $cash_id);

                if ($stmt->execute()) {
                    $msg = 'Cash transaction deleted successfully.';
                    $msgType = 'success';
                } else {
                    $msg = 'Delete failed.';
                    $msgType = 'danger';
                }

                $stmt->close();
            } else {
                $msg = 'Failed to prepare delete query.';
                $msgType = 'danger';
            }
        }
    }
}

/* FETCH ROWS */
$rows = [];
$stmt = $conn->prepare("
    SELECT cash_id, transaction_date, description, transaction_type, amount
    FROM cash_account
    WHERE company_id = ?
    ORDER BY transaction_date DESC, cash_id DESC
");

if ($stmt) {
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $stmt->bind_result($cash_id_r, $transaction_date_r, $description_r, $transaction_type_r, $amount_r);

    while ($stmt->fetch()) {
        $rows[] = [
            'cash_id' => $cash_id_r,
            'transaction_date' => $transaction_date_r,
            'description' => $description_r,
            'transaction_type' => $transaction_type_r,
            'amount' => $amount_r
        ];
    }

    $stmt->close();
}

$totalIn = 0.0;
$totalOut = 0.0;
foreach ($rows as $r) {
    if (($r['transaction_type'] ?? '') === 'Cash In') {
        $totalIn += (float)$r['amount'];
    } else {
        $totalOut += (float)$r['amount'];
    }
}
$balance = $totalIn - $totalOut;

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="page-heading">
                <h1>Cash Account</h1>
                <p><?= e($pageDescription) ?></p>
            </div>
        </div>
        <div class="topbar-right">
            <div class="company-pill"><?= e($_SESSION['company_name'] ?? 'Company') ?></div>
            <div class="role-pill"><?= e($_SESSION['role'] ?? 'User') ?></div>
        </div>
    </div>

    <div class="content">
        <?php if ($msg !== ''): ?>
            <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <div class="grid grid-3">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Total Cash In</div>
                    <div class="stat-icon">⬇️</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($totalIn, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Total Cash Out</div>
                    <div class="stat-icon">⬆️</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($totalOut, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Cash Balance</div>
                    <div class="stat-icon">💵</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($balance, 2) ?></div>
            </div>
        </div>

        <?php if ($canCrud): ?>
            <div class="card mt-24">
                <div class="card-header">
                    <h3><?= $edit_mode ? 'Edit Cash Transaction' : 'Add Cash Transaction' ?></h3>
                    <span class="badge badge-primary">Cash Ledger Entry</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cash_id" value="<?= e($edit['cash_id']) ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Transaction Date</label>
                                <input type="date" name="transaction_date" class="form-control"
                                    value="<?= e($edit['transaction_date']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Transaction Type</label>
                                <select name="transaction_type" class="form-control" required>
                                    <option value="">Select Type</option>
                                    <option value="Cash In"
                                        <?= ($edit['transaction_type'] ?? '') === 'Cash In' ? 'selected' : '' ?>>Cash In
                                    </option>
                                    <option value="Cash Out"
                                        <?= ($edit['transaction_type'] ?? '') === 'Cash Out' ? 'selected' : '' ?>>Cash Out
                                    </option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Amount</label>
                                <input type="number" step="0.01" name="amount" class="form-control"
                                    value="<?= e($edit['amount']) ?>" required>
                            </div>

                            <div class="form-group full">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control"
                                    required><?= e($edit['description']) ?></textarea>
                            </div>

                            <div class="form-group full">
                                <button type="submit" name="save_cash"
                                    class="btn btn-primary"><?= $edit_mode ? 'Update Cash Entry' : 'Save Cash Entry' ?></button>
                                <?php if ($edit_mode): ?>
                                    <a href="cash_account.php" class="btn btn-light">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Cash Ledger Records</h3>
                <span class="badge badge-success"><?= count($rows) ?> Records</span>
            </div>
            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
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
                                        <td><?= e($row['cash_id']) ?></td>
                                        <td><?= e($row['transaction_date']) ?></td>
                                        <td><?= e($row['description']) ?></td>
                                        <td>
                                            <?php $cls = ($row['transaction_type'] === 'Cash In') ? 'badge-success' : 'badge-danger'; ?>
                                            <span class="badge <?= e($cls) ?>"><?= e($row['transaction_type']) ?></span>
                                        </td>
                                        <td>Rs. <?= number_format((float)$row['amount'], 2) ?></td>
                                        <td>
                                            <?php if ($canCrud): ?>
                                                <a class="btn btn-light" href="?edit=<?= (int)$row['cash_id'] ?>">Edit</a>
                                                <form method="POST" style="display:inline-block;"
                                                    onsubmit="return confirm('Delete this cash entry?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="cash_id" value="<?= (int)$row['cash_id'] ?>">
                                                    <button type="submit" name="delete_cash" class="btn btn-danger">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">View Only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">No cash transactions found.</td>
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