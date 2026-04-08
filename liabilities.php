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

$pageTitle = 'Liabilities Management';
$pageDescription = 'Add and manage company liability records';

$msg = '';
$msgType = 'success';

$currentRole = verify_user_role($user_id, $company_id);

$canView = in_array($currentRole, ['organization', 'auditor', 'accountant'], true);
$canCrud = ($currentRole === 'accountant');

if (!$canView) {
    header("Location: dashboard.php");
    exit;
}

function resetForm(): array
{
    return [
        'liability_id' => '',
        'liability_name' => '',
        'liability_type' => '',
        'amount' => '',
        'liability_date' => '',
        'description' => '',
        'due_date' => '',
        'payment_source' => 'Cash',
        'bank_name' => '',
        'account_number' => ''
    ];
}

$edit_mode = false;
$edit = resetForm();

/* =========================
   EDIT FETCH
========================= */
if (isset($_GET['edit'])) {
    $id = (int)($_GET['edit'] ?? 0);

    $stmt = $conn->prepare("
        SELECT 
            liability_id,
            liability_name,
            liability_type,
            amount,
            liability_date,
            description,
            due_date,
            payment_source,
            bank_name,
            account_number
        FROM liabilities
        WHERE company_id = ? AND liability_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("ii", $company_id, $id);
        $stmt->execute();

        $stmt->bind_result(
            $liability_id,
            $liability_name,
            $liability_type,
            $amount,
            $liability_date,
            $description,
            $due_date,
            $payment_source,
            $bank_name,
            $account_number
        );

        if ($stmt->fetch()) {
            $edit = [
                'liability_id'   => $liability_id,
                'liability_name' => $liability_name,
                'liability_type' => $liability_type,
                'amount'         => $amount,
                'liability_date' => $liability_date,
                'description'    => $description,
                'due_date'       => $due_date,
                'payment_source' => $payment_source,
                'bank_name'      => $bank_name,
                'account_number' => $account_number
            ];
            $edit_mode = true;
        }

        $stmt->close();
    }
}

/* =========================
   SAVE
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

        if (!in_array($source, ['Cash', 'Bank'], true)) {
            $source = 'Cash';
        }

        if ($source !== 'Bank') {
            $bank = '';
            $acc = '';
        }

        if ($name === '' || $date === '' || $amount <= 0) {
            $msg = 'Invalid input';
            $msgType = 'danger';
        } elseif ($source === 'Bank' && ($bank === '' || $acc === '')) {
            $msg = 'Bank name and account number are required for bank payments.';
            $msgType = 'danger';
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE liabilities 
                    SET liability_name = ?, liability_type = ?, amount = ?, liability_date = ?, description = ?, due_date = ?, payment_source = ?, bank_name = ?, account_number = ?
                    WHERE company_id = ? AND liability_id = ?
                ");

                if (!$stmt) {
                    $msg = 'Failed to prepare update query';
                    $msgType = 'danger';
                } else {
                    $stmt->bind_param(
                        "ssdssssssii",
                        $name,
                        $type,
                        $amount,
                        $date,
                        $desc,
                        $due,
                        $source,
                        $bank,
                        $acc,
                        $company_id,
                        $id
                    );

                    if ($stmt->execute()) {
                        $msg = 'Updated successfully';
                        $msgType = 'success';
                        $edit_mode = false;
                        $edit = resetForm();
                    } else {
                        $msg = 'Failed to update record';
                        $msgType = 'danger';
                    }

                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO liabilities
                    (
                        company_id,
                        liability_name,
                        liability_type,
                        amount,
                        liability_date,
                        description,
                        due_date,
                        payment_source,
                        bank_name,
                        account_number
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    $msg = 'Failed to prepare insert query';
                    $msgType = 'danger';
                } else {
                    $stmt->bind_param(
                        "issdssssss",
                        $company_id,
                        $name,
                        $type,
                        $amount,
                        $date,
                        $desc,
                        $due,
                        $source,
                        $bank,
                        $acc
                    );

                    if ($stmt->execute()) {
                        $msg = 'Added successfully';
                        $msgType = 'success';
                        $edit = resetForm();
                    } else {
                        $msg = 'Failed to add record';
                        $msgType = 'danger';
                    }

                    $stmt->close();
                }
            }
        }
    }
}

/* =========================
   DELETE
========================= */
if (isset($_GET['delete'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can delete';
        $msgType = 'danger';
    } else {
        $id = (int)($_GET['delete'] ?? 0);

        if ($id > 0) {
            $stmt = $conn->prepare("
                DELETE FROM liabilities
                WHERE company_id = ? AND liability_id = ?
            ");

            if ($stmt) {
                $stmt->bind_param("ii", $company_id, $id);

                if ($stmt->execute()) {
                    $msg = 'Deleted successfully';
                    $msgType = 'success';
                } else {
                    $msg = 'Delete failed';
                    $msgType = 'danger';
                }

                $stmt->close();
            } else {
                $msg = 'Failed to prepare delete query';
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
        liability_name,
        liability_type,
        amount,
        liability_date,
        description,
        due_date,
        payment_source,
        bank_name,
        account_number
    FROM liabilities
    WHERE company_id = ?
    ORDER BY liability_id DESC
");

if ($stmt) {
    $stmt->bind_param("i", $company_id);
    $stmt->execute();

    $stmt->bind_result(
        $liability_id,
        $liability_name,
        $liability_type,
        $amount,
        $liability_date,
        $description,
        $due_date,
        $payment_source,
        $bank_name,
        $account_number
    );

    while ($stmt->fetch()) {
        $rows[] = [
            'liability_id'   => $liability_id,
            'liability_name' => $liability_name,
            'liability_type' => $liability_type,
            'amount'         => $amount,
            'liability_date' => $liability_date,
            'description'    => $description,
            'due_date'       => $due_date,
            'payment_source' => $payment_source,
            'bank_name'      => $bank_name,
            'account_number' => $account_number
        ];
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
                <h1>Liabilities Management</h1>
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
                    <h3><?= $edit_mode ? 'Edit Liability' : 'Add Liability' ?></h3>
                    <span class="badge badge-primary">Professional Entry</span>
                </div>

                <div class="card-body">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="liability_id" value="<?= e($edit['liability_id']) ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Liability Name</label>
                                <input type="text" name="liability_name" class="form-control"
                                    placeholder="Loan / Payable / Advance" value="<?= e($edit['liability_name']) ?>"
                                    required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Liability Type</label>
                                <input type="text" name="liability_type" class="form-control"
                                    placeholder="Short Term / Long Term / Other" value="<?= e($edit['liability_type']) ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Amount</label>
                                <input type="number" step="0.01" name="amount" class="form-control"
                                    value="<?= e($edit['amount']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Liability Date</label>
                                <input type="date" name="liability_date" class="form-control"
                                    value="<?= e($edit['liability_date']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Due Date</label>
                                <input type="date" name="due_date" class="form-control" value="<?= e($edit['due_date']) ?>">
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

                            <div class="form-group" id="liabilityBankNameWrap" style="display:none;">
                                <label class="form-label">Bank Name</label>
                                <input type="text" name="bank_name" class="form-control"
                                    value="<?= e($edit['bank_name']) ?>">
                            </div>

                            <div class="form-group" id="liabilityAccountNoWrap" style="display:none;">
                                <label class="form-label">Account Number</label>
                                <input type="text" name="account_number" class="form-control"
                                    value="<?= e($edit['account_number']) ?>">
                            </div>

                            <div class="form-group full">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control"
                                    placeholder="Enter liability details"><?= e($edit['description']) ?></textarea>
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
        <?php endif; ?>

        <div class="card<?= $canCrud ? ' mt-24' : '' ?>">
            <div class="card-header">
                <h3>Liability Records</h3>
                <span class="badge badge-success"><?= count($rows) ?> Records</span>
            </div>

            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Liability Date</th>
                                <th>Due Date</th>
                                <th>Payment</th>
                                <th>Description</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?= e($row['liability_id'] ?? '') ?></td>
                                        <td><?= e($row['liability_name'] ?? '') ?></td>
                                        <td><?= e($row['liability_type'] ?? '') ?></td>
                                        <td>Rs. <?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                                        <td><?= e($row['liability_date'] ?? '') ?></td>
                                        <td><?= e($row['due_date'] ?? '') ?></td>
                                        <td>
                                            <span class="badge badge-primary">
                                                <?= e($row['payment_source'] ?? 'Cash') ?>
                                            </span>
                                        </td>
                                        <td><?= e($row['description'] ?? '') ?></td>
                                        <td>
                                            <?php if ($canCrud): ?>
                                                <a class="btn btn-light" href="?edit=<?= (int)$row['liability_id'] ?>">Edit</a>
                                                <a class="btn btn-danger" href="?delete=<?= (int)$row['liability_id'] ?>&csrf_token=<?= urlencode(get_csrf_token()) ?>"
                                                    onclick="return confirm('Delete this liability record?')">Delete</a>
                                            <?php else: ?>
                                                <span class="text-muted">View Only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9">No liability records found.</td>
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

        if (!bankWrap || !accountWrap) {
            return;
        }

        bankWrap.style.display = value === 'Bank' ? 'block' : 'none';
        accountWrap.style.display = value === 'Bank' ? 'block' : 'none';
    }

    const liabilityPaymentSource = document.getElementById('liabilityPaymentSource');
    if (liabilityPaymentSource) {
        toggleLiabilityBankFields(liabilityPaymentSource.value);
    }
</script>