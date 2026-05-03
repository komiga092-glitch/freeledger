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

$pageTitle = 'Income Management';
$pageDescription = 'Add and manage company income records';

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

function resetIncomeForm(): array
{
    return [
        'income_id' => '',
        'income_type' => '',
        'amount' => '',
        'income_date' => date('Y-m-d'),
        'description' => '',
        'payment_source' => 'Cash',
        'bank_name' => '',
        'account_number' => '',
        'proof_file' => ''
    ];
}

function deleteIncomeLedgerEntries(mysqli $conn, int $company_id, int $income_id): void
{
    $tag = '[INCOME:' . $income_id . ']';
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

function insertIncomeLedgerEntry(
    mysqli $conn,
    int $company_id,
    int $income_id,
    string $income_type,
    string $income_date,
    float $amount,
    string $payment_source,
    string $bank_name,
    string $account_number
): void {
    $description = '[INCOME:' . $income_id . '] Income: ' . $income_type;

    if ($payment_source === 'Cash') {
        $stmt = $conn->prepare("
            INSERT INTO cash_account (
                company_id,
                transaction_date,
                description,
                transaction_type,
                amount
            ) VALUES (?, ?, ?, 'Cash In', ?)
        ");
        if (!$stmt) {
            throw new Exception('Failed to prepare cash ledger entry.');
        }

        $stmt->bind_param("issd", $company_id, $income_date, $description, $amount);
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
        ) VALUES (?, ?, ?, ?, 'Deposit', ?, ?)
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare bank ledger entry.');
    }

    $stmt->bind_param("issdss", $company_id, $income_date, $description, $amount, $bank_name, $account_number);
    $stmt->execute();
    $stmt->close();
}

$edit_mode = false;
$edit = resetIncomeForm();

/* =========================
   DELETE INCOME
========================= */
if (isset($_GET['delete'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can delete income records.';
        $msgType = 'danger';
    } else {
        $income_id = (int)($_GET['delete'] ?? 0);

        if ($income_id > 0) {
            $conn->begin_transaction();

            try {
                deleteIncomeLedgerEntries($conn, $company_id, $income_id);

                $stmt = $conn->prepare("DELETE FROM income WHERE company_id = ? AND income_id = ?");
                if (!$stmt) {
                    throw new Exception('Failed to prepare delete query.');
                }

                $stmt->bind_param("ii", $company_id, $income_id);
                $stmt->execute();

                if ($stmt->affected_rows <= 0) {
                    $stmt->close();
                    throw new Exception('Income record not found or already deleted.');
                }

                $stmt->close();
                $conn->commit();

                $msg = 'Income record deleted successfully.';
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
    $income_id = (int)($_GET['edit'] ?? 0);

    $stmt = $conn->prepare("
        SELECT *
        FROM income
        WHERE company_id = ? AND income_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("ii", $company_id, $income_id);
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
   ADD / UPDATE INCOME
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_income'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can add or edit income records.';
        $msgType = 'danger';
    } else {
        $income_id      = (int)($_POST['income_id'] ?? 0);
        $income_type    = trim($_POST['income_type'] ?? '');
        $amount         = (float)($_POST['amount'] ?? 0);
        $income_date    = trim($_POST['income_date'] ?? '');
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

        if ($income_type === '') {
            $msg = 'Income type is required.';
            $msgType = 'danger';
        } elseif ($amount <= 0) {
            $msg = 'Amount must be greater than 0.';
            $msgType = 'danger';
        } elseif ($income_date === '') {
            $msg = 'Income date is required.';
            $msgType = 'danger';
        } elseif ($payment_source === 'Bank' && ($bank_name === '' || $account_number === '')) {
            $msg = 'Bank name and account number are required for bank payments.';
            $msgType = 'danger';
        } else {
            // Handle file upload
            $proof_file_path = '';
            if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/transactions/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $fileName = $_FILES['proof_file']['name'];
                $fileTmp = $_FILES['proof_file']['tmp_name'];
                $fileSize = $_FILES['proof_file']['size'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
                $maxSize = 5 * 1024 * 1024; // 5MB

                if (!in_array($fileExt, $allowedExts)) {
                    $msg = 'Invalid file type. Only JPG, PNG, PDF, DOC, DOCX allowed.';
                    $msgType = 'danger';
                } elseif ($fileSize > $maxSize) {
                    $msg = 'File size too large. Maximum 5MB allowed.';
                    $msgType = 'danger';
                } else {
                    $safeName = uniqid('income_' . $company_id . '_', true) . '.' . $fileExt;
                    $targetPath = $uploadDir . $safeName;

                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        $proof_file_path = $safeName;
                    } else {
                        $msg = 'Failed to upload file.';
                        $msgType = 'danger';
                    }
                }
            }

            if ($msgType !== 'danger') {
            $conn->begin_transaction();

            try {
                if ($income_id > 0) {
                    $stmt = $conn->prepare("
                        UPDATE income
                        SET income_type = ?, amount = ?, income_date = ?, description = ?, payment_source = ?, bank_name = ?, account_number = ?" . 
                        ($proof_file_path ? ", proof_file = ?" : "") . "
                        WHERE company_id = ? AND income_id = ?
                    ");

                    if (!$stmt) {
                        throw new Exception('Failed to prepare update query.');
                    }

                    if ($proof_file_path) {
                        $stmt->bind_param(
                            "sdsssssssii",
                            $income_type,
                            $amount,
                            $income_date,
                            $description,
                            $payment_source,
                            $bank_name,
                            $account_number,
                            $proof_file_path,
                            $company_id,
                            $income_id
                        );
                    } else {
                        $stmt->bind_param(
                            "sdsssssii",
                            $income_type,
                            $amount,
                            $income_date,
                            $description,
                            $payment_source,
                            $bank_name,
                            $account_number,
                            $company_id,
                            $income_id
                        );
                    }
                    $stmt->execute();
                    $stmt->close();

                    deleteIncomeLedgerEntries($conn, $company_id, $income_id);

                    insertIncomeLedgerEntry(
                        $conn,
                        $company_id,
                        $income_id,
                        $income_type,
                        $income_date,
                        $amount,
                        $payment_source,
                        $bank_name,
                        $account_number
                    );

                    $conn->commit();

                    $msg = 'Income record updated successfully.';
                    $msgType = 'success';
                    $edit_mode = false;
                    $edit = resetIncomeForm();
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO income (
                            company_id,
                            income_type,
                            amount,
                            income_date,
                            description,
                            payment_source,
                            bank_name,
                            account_number" .
                            ($proof_file_path ? ", proof_file" : "") . "
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?" .
                        ($proof_file_path ? ", ?" : "") . ")
                    ");

                    if (!$stmt) {
                        throw new Exception('Failed to prepare insert query.');
                    }

                    if ($proof_file_path) {
                        $stmt->bind_param(
                            "isdssssss",
                            $company_id,
                            $income_type,
                            $amount,
                            $income_date,
                            $description,
                            $payment_source,
                            $bank_name,
                            $account_number,
                            $proof_file_path
                        );
                    } else {
                        $stmt->bind_param(
                            "isdsssss",
                            $company_id,
                            $income_type,
                            $amount,
                            $income_date,
                            $description,
                            $payment_source,
                            $bank_name,
                            $account_number
                        );
                    }
                    $stmt->execute();
                    $new_income_id = (int)$stmt->insert_id;
                    $stmt->close();

                    insertIncomeLedgerEntry(
                        $conn,
                        $company_id,
                        $new_income_id,
                        $income_type,
                        $income_date,
                        $amount,
                        $payment_source,
                        $bank_name,
                        $account_number
                    );

                    $conn->commit();

                    $msg = 'Income record added successfully.';
                    $msgType = 'success';
                    $edit = resetIncomeForm();
                }
            } catch (Throwable $e) {
                $conn->rollback();
                $msg = 'Failed to save income record: ' . $e->getMessage();
                $msgType = 'danger';

                $edit = [
                    'income_id' => $income_id,
                    'income_type' => $income_type,
                    'amount' => $amount,
                    'income_date' => $income_date,
                    'description' => $description,
                    'payment_source' => $payment_source,
                    'bank_name' => $bank_name,
                    'account_number' => $account_number
                ];
                $edit_mode = $income_id > 0;
            }
        }
    }
}
}

/* =========================
   FETCH ALL INCOME ROWS
========================= */
$rows = [];
$stmt = $conn->prepare("
    SELECT *
    FROM income
    WHERE company_id = ?
    ORDER BY income_date DESC, income_id DESC
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
                <h3><?= $edit_mode ? 'Edit Income' : 'Add Income' ?></h3>
                <span class="badge badge-primary">Professional Entry</span>
            </div>

            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="income_id" value="<?= e($edit['income_id']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Income Type</label>
                            <input type="text" name="income_type" class="form-control"
                                placeholder="Donations / Grants / Membership Fees"
                                value="<?= e($edit['income_type']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Amount</label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control"
                                value="<?= e($edit['amount']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Income Date</label>
                            <input type="date" name="income_date" class="form-control"
                                value="<?= e($edit['income_date']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Source</label>
                            <select name="payment_source" class="form-control" id="incomePaymentSource"
                                onchange="toggleIncomeBankFields(this.value)">
                                <option value="Cash"
                                    <?= ($edit['payment_source'] ?? 'Cash') === 'Cash' ? 'selected' : '' ?>>Cash
                                </option>
                                <option value="Bank"
                                    <?= ($edit['payment_source'] ?? '') === 'Bank' ? 'selected' : '' ?>>Bank</option>
                            </select>
                        </div>

                        <div class="form-group hidden" id="incomeBankNameWrap">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control"
                                value="<?= e($edit['bank_name']) ?>">
                        </div>

                        <div class="form-group hidden" id="incomeAccountNoWrap">
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
                            <label class="form-label">Proof/Attachment (Optional)</label>
                            <input type="file" name="proof_file" class="form-control"
                                accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                            <small class="form-text">Upload receipts, documents, or images as proof (Max 5MB)</small>
                            <?php if ($edit_mode && !empty($edit['proof_file'])): ?>
                            <div class="mt-2">
                                <a href="uploads/transactions/<?= e($edit['proof_file']) ?>" target="_blank"
                                    class="btn btn-sm btn-outline-primary">View Current File</a>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="save_income" class="btn btn-primary">
                                <?= $edit_mode ? 'Update Income' : 'Save Income' ?>
                            </button>

                            <?php if ($edit_mode): ?>
                            <a href="income.php" class="btn btn-light">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card<?= $canCrud ? ' mt-24' : '' ?>">
            <div class="card-header">
                <h3>Income Records</h3>
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
                                <td><?= e($row['income_id']) ?></td>
                                <td><?= e($row['income_type']) ?></td>
                                <td>Rs. <?= number_format((float)$row['amount'], 2) ?></td>
                                <td><?= e($row['income_date']) ?></td>
                                <td>
                                    <span class="badge badge-primary"><?= e($row['payment_source']) ?></span>
                                </td>
                                <td><?= e($row['description']) ?></td>
                                <td>
                                    <?php if ($canCrud): ?>
                                    <a class="btn btn-light" href="?edit=<?= (int)$row['income_id'] ?>">Edit</a>
                                    <a class="btn btn-danger"
                                        href="?delete=<?= (int)$row['income_id'] ?>&csrf_token=<?= urlencode(get_csrf_token()) ?>"
                                        onclick="return confirm('Delete this income record?')">Delete</a>
                                    <?php else: ?>
                                    <span class="text-muted">View Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="7">No income records found.</td>
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
function toggleIncomeBankFields(value) {
    const bankWrap = document.getElementById('incomeBankNameWrap');
    const accWrap = document.getElementById('incomeAccountNoWrap');

    if (!bankWrap || !accWrap) {
        return;
    }

    bankWrap.style.display = value === 'Bank' ? 'block' : 'none';
    accWrap.style.display = value === 'Bank' ? 'block' : 'none';
}

const incomePaymentSource = document.getElementById('incomePaymentSource');
if (incomePaymentSource) {
    toggleIncomeBankFields(incomePaymentSource.value);
}
</script>