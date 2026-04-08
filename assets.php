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

$pageTitle = 'Assets Management';
$pageDescription = 'Add and manage company asset records';

$msg = '';
$msgType = 'success';

$currentRole = verify_user_role($user_id, $company_id);

$canView = in_array($currentRole, ['organization', 'auditor', 'accountant'], true);
$canCrud = ($currentRole === 'accountant');

if (!$canView) {
    header("Location: dashboard.php");
    exit;
}

function resetAssetForm(): array
{
    return [
        'asset_id' => '',
        'asset_name' => '',
        'asset_type' => '',
        'purchase_date' => '',
        'cost_value' => '',
        'current_value' => '',
        'description' => '',
        'payment_source' => 'Cash',
        'bank_name' => '',
        'account_number' => ''
    ];
}

$edit_mode = false;
$edit = resetAssetForm();

/* =========================
   EDIT FETCH
========================= */
if (isset($_GET['edit'])) {
    $asset_id = (int)($_GET['edit'] ?? 0);

    $stmt = $conn->prepare("
        SELECT *
        FROM assets
        WHERE company_id = ? AND asset_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("ii", $company_id, $asset_id);
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
   ADD / UPDATE ASSET
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_asset'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can add or edit asset records.';
        $msgType = 'danger';
    } else {
        $asset_id       = (int)($_POST['asset_id'] ?? 0);
        $asset_name     = trim($_POST['asset_name'] ?? '');
        $asset_type     = trim($_POST['asset_type'] ?? '');
        $purchase_date  = trim($_POST['purchase_date'] ?? '');
        $cost_value     = (float)($_POST['cost_value'] ?? 0);
        $current_value  = trim($_POST['current_value'] ?? '');
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

        if ($current_value === '') {
            $current_value = (string)$cost_value;
        }

        $current_value_float = (float)$current_value;

        if ($asset_name === '' || $purchase_date === '' || $cost_value <= 0) {
            $msg = 'Please fill required fields correctly.';
            $msgType = 'danger';
        } elseif ($payment_source === 'Bank' && ($bank_name === '' || $account_number === '')) {
            $msg = 'Bank name and account number are required for bank payments.';
            $msgType = 'danger';
        } else {
            if ($asset_id > 0) {
                $sql = "
                    UPDATE assets
                    SET asset_name = ?, asset_type = ?, purchase_date = ?, cost_value = ?, current_value = ?, description = ?, payment_source = ?, bank_name = ?, account_number = ?
                    WHERE company_id = ? AND asset_id = ?
                ";

                $stmt = $conn->prepare($sql);

                if (!$stmt) {
                    $msg = 'Failed to prepare update query.';
                    $msgType = 'danger';
                } else {
                    $stmt->bind_param(
                        "sssddssssii",
                        $asset_name,
                        $asset_type,
                        $purchase_date,
                        $cost_value,
                        $current_value_float,
                        $description,
                        $payment_source,
                        $bank_name,
                        $account_number,
                        $company_id,
                        $asset_id
                    );

                    if ($stmt->execute()) {
                        $msg = 'Asset record updated successfully.';
                        $msgType = 'success';
                        $edit_mode = false;
                        $edit = resetAssetForm();
                    } else {
                        $msg = 'Failed to update asset record.';
                        $msgType = 'danger';
                    }

                    $stmt->close();
                }
            } else {
                $sql = "
                    INSERT INTO assets
                    (
                        company_id,
                        asset_name,
                        asset_type,
                        purchase_date,
                        cost_value,
                        current_value,
                        description,
                        payment_source,
                        bank_name,
                        account_number
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";

                $stmt = $conn->prepare($sql);

                if (!$stmt) {
                    $msg = 'Failed to prepare insert query.';
                    $msgType = 'danger';
                } else {
                    $stmt->bind_param(
                        "isssddssss",
                        $company_id,
                        $asset_name,
                        $asset_type,
                        $purchase_date,
                        $cost_value,
                        $current_value_float,
                        $description,
                        $payment_source,
                        $bank_name,
                        $account_number
                    );

                    if ($stmt->execute()) {
                        $msg = 'Asset record added successfully.';
                        $msgType = 'success';
                        $edit = resetAssetForm();
                    } else {
                        $msg = 'Failed to add asset record.';
                        $msgType = 'danger';
                    }

                    $stmt->close();
                }
            }
        }
    }
}

/* =========================
   DELETE ASSET
========================= */
if (isset($_GET['delete'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can delete asset records.';
        $msgType = 'danger';
    } else {
        $asset_id = (int)($_GET['delete'] ?? 0);

        if ($asset_id > 0) {
            $stmt = $conn->prepare("DELETE FROM assets WHERE company_id = ? AND asset_id = ?");

            if (!$stmt) {
                $msg = 'Failed to prepare delete query.';
                $msgType = 'danger';
            } else {
                $stmt->bind_param("ii", $company_id, $asset_id);

                if ($stmt->execute()) {
                    $msg = 'Asset record deleted successfully.';
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
   FETCH ALL ASSET ROWS
========================= */
$rows = [];
$sql = "SELECT * FROM assets WHERE company_id = ? ORDER BY asset_id DESC";

$stmt = $conn->prepare($sql);
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
                <h1>Assets Management</h1>
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
                    <h3><?= $edit_mode ? 'Edit Asset' : 'Add Asset' ?></h3>
                    <span class="badge badge-primary">Professional Entry</span>
                </div>

                <div class="card-body">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="asset_id" value="<?= e($edit['asset_id']) ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Asset Name</label>
                                <input type="text" name="asset_name" class="form-control"
                                    placeholder="Laptop / Vehicle / Building" value="<?= e($edit['asset_name']) ?>"
                                    required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Asset Type</label>
                                <input type="text" name="asset_type" class="form-control"
                                    placeholder="Equipment / Furniture / Property" value="<?= e($edit['asset_type']) ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Purchase Date</label>
                                <input type="date" name="purchase_date" class="form-control"
                                    value="<?= e($edit['purchase_date']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Cost Value</label>
                                <input type="number" step="0.01" name="cost_value" class="form-control"
                                    value="<?= e($edit['cost_value']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Current Value</label>
                                <input type="number" step="0.01" name="current_value" class="form-control"
                                    value="<?= e($edit['current_value']) ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Payment Source</label>
                                <select name="payment_source" class="form-control" id="assetPaymentSource"
                                    onchange="toggleAssetBankFields(this.value)">
                                    <option value="Cash"
                                        <?= ($edit['payment_source'] ?? 'Cash') === 'Cash' ? 'selected' : '' ?>>Cash
                                    </option>
                                    <option value="Bank"
                                        <?= ($edit['payment_source'] ?? '') === 'Bank' ? 'selected' : '' ?>>Bank</option>
                                </select>
                            </div>

                            <div class="form-group" id="assetBankNameWrap" style="display:none;">
                                <label class="form-label">Bank Name</label>
                                <input type="text" name="bank_name" class="form-control"
                                    value="<?= e($edit['bank_name']) ?>">
                            </div>

                            <div class="form-group" id="assetAccountNoWrap" style="display:none;">
                                <label class="form-label">Account Number</label>
                                <input type="text" name="account_number" class="form-control"
                                    value="<?= e($edit['account_number']) ?>">
                            </div>

                            <div class="form-group full">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control"
                                    placeholder="Enter asset details"><?= e($edit['description']) ?></textarea>
                            </div>

                            <div class="form-group full">
                                <button type="submit" name="save_asset" class="btn btn-primary">
                                    <?= $edit_mode ? 'Update Asset' : 'Save Asset' ?>
                                </button>

                                <?php if ($edit_mode): ?>
                                    <a href="assets.php" class="btn btn-light">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card<?= $canCrud ? ' mt-24' : '' ?>">
            <div class="card-header">
                <h3>Asset Records</h3>
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
                                <th>Purchase Date</th>
                                <th>Cost Value</th>
                                <th>Current Value</th>
                                <th>Payment</th>
                                <th>Description</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?= e($row['asset_id'] ?? '') ?></td>
                                        <td><?= e($row['asset_name'] ?? '') ?></td>
                                        <td><?= e($row['asset_type'] ?? '') ?></td>
                                        <td><?= e($row['purchase_date'] ?? '') ?></td>
                                        <td>Rs. <?= number_format((float)($row['cost_value'] ?? 0), 2) ?></td>
                                        <td>Rs. <?= number_format((float)($row['current_value'] ?? 0), 2) ?></td>
                                        <td>
                                            <span class="badge badge-primary">
                                                <?= e($row['payment_source'] ?? 'Cash') ?>
                                            </span>
                                        </td>
                                        <td><?= e($row['description'] ?? '') ?></td>
                                        <td>
                                            <?php if ($canCrud): ?>
                                                <a class="btn btn-light" href="?edit=<?= (int)$row['asset_id'] ?>">Edit</a>
                                                <a class="btn btn-danger" href="?delete=<?= (int)$row['asset_id'] ?>&csrf_token=<?= urlencode(get_csrf_token()) ?>"
                                                    onclick="return confirm('Delete this asset record?')">Delete</a>
                                            <?php else: ?>
                                                <span class="text-muted">View Only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9">No asset records found.</td>
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
    function toggleAssetBankFields(value) {
        const bankWrap = document.getElementById('assetBankNameWrap');
        const accountWrap = document.getElementById('assetAccountNoWrap');

        if (!bankWrap || !accountWrap) {
            return;
        }

        bankWrap.style.display = value === 'Bank' ? 'block' : 'none';
        accountWrap.style.display = value === 'Bank' ? 'block' : 'none';
    }

    const assetPaymentSource = document.getElementById('assetPaymentSource');
    if (assetPaymentSource) {
        toggleAssetBankFields(assetPaymentSource.value);
    }
</script>