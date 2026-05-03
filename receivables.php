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
$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($company_id <= 0) {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Receivables Management';
$pageDescription = 'Manage loans given and money to receive';

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

function failIfPrepareFalse($stmt, string $context = 'Database prepare failed'): mysqli_stmt
{
    if ($stmt === false) {
        throw new RuntimeException($context);
    }
    return $stmt;
}

function resetReceivableForm(): array
{
    return [
        'receivable_id' => '',
        'borrower_type' => 'Person',
        'employee_id' => '',
        'borrower_name' => '',
        'receivable_type' => 'Loan Given',
        'original_amount' => '',
        'given_date' => date('Y-m-d'),
        'due_date' => '',
        'payment_source' => 'Cash',
        'bank_name' => '',
        'account_number' => '',
        'description' => ''
    ];
}

function isValidIsoDate(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

function insertReceivableGivenLedger(
    mysqli $conn,
    int $company_id,
    int $receivable_id,
    string $borrower_name,
    string $given_date,
    float $amount,
    string $source,
    string $bank_name,
    string $account_number
): void {
    $description = '[RECEIVABLE_GIVEN:' . $receivable_id . '] Loan given to ' . $borrower_name;

    if ($source === 'Cash') {
        $stmt = failIfPrepareFalse($conn->prepare("
            INSERT INTO cash_account (
                company_id, transaction_date, description, transaction_type, amount
            ) VALUES (?, ?, ?, 'Cash Out', ?)
        "));
        $stmt->bind_param('issd', $company_id, $given_date, $description, $amount);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = failIfPrepareFalse($conn->prepare("
        INSERT INTO bank_account (
            company_id, transaction_date, description, amount, transaction_type, bank_name, account_number
        ) VALUES (?, ?, ?, ?, 'Withdrawal', ?, ?)
    "));
    $stmt->bind_param('issdss', $company_id, $given_date, $description, $amount, $bank_name, $account_number);
    $stmt->execute();
    $stmt->close();
}

$edit_mode = false;
$edit = resetReceivableForm();

if (isset($_GET['edit'])) {
    $id = (int)($_GET['edit'] ?? 0);

    if ($id > 0) {
        $stmt = failIfPrepareFalse($conn->prepare("
            SELECT *
            FROM receivables
            WHERE company_id = ? AND receivable_id = ?
            LIMIT 1
        "));
        $stmt->bind_param('ii', $company_id, $id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $edit = array_merge($edit, $row);
            $edit_mode = true;
        }

        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_receivable'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid request.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Permission denied.';
        $msgType = 'danger';
    } else {
        $id = (int)($_POST['receivable_id'] ?? 0);
        $borrower_type = trim($_POST['borrower_type'] ?? 'Person');
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $borrower_name = trim($_POST['borrower_name'] ?? '');
        $receivable_type = trim($_POST['receivable_type'] ?? 'Loan Given');
        $amount = (float)($_POST['original_amount'] ?? 0);
        $given_date = trim($_POST['given_date'] ?? '');
        $due_date = trim($_POST['due_date'] ?? '');
        $source = trim($_POST['payment_source'] ?? 'Cash');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (!in_array($borrower_type, ['Person', 'Organization', 'Employee'], true)) {
            $borrower_type = 'Person';
        }

        if (!in_array($source, ['Cash', 'Bank'], true)) {
            $source = 'Cash';
        }

        if ($source !== 'Bank') {
            $bank_name = '';
            $account_number = '';
        }

        if ($borrower_type !== 'Employee') {
            $employee_id = 0;
        }

        try {
            if ($borrower_name === '' || $amount <= 0 || $given_date === '') {
                throw new RuntimeException('Please fill required fields correctly.');
            }

            if (!isValidIsoDate($given_date)) {
                throw new RuntimeException('Invalid given date.');
            }

            if ($due_date !== '' && !isValidIsoDate($due_date)) {
                throw new RuntimeException('Invalid due date.');
            }

            if ($source === 'Bank' && ($bank_name === '' || $account_number === '')) {
                throw new RuntimeException('Bank name and account number required.');
            }

            $conn->begin_transaction();

            if ($id > 0) {
                $stmt = failIfPrepareFalse($conn->prepare("
                    UPDATE receivables
                    SET borrower_type = ?, employee_id = ?, borrower_name = ?, receivable_type = ?,
                        original_amount = ?, balance_amount = (original_amount - received_amount),
                        given_date = ?, due_date = ?, payment_source = ?, bank_name = ?, account_number = ?, description = ?
                    WHERE company_id = ? AND receivable_id = ?
                "));

                $employee_id_db = $employee_id > 0 ? $employee_id : null;

                $stmt->bind_param(
                    'sissdssssssii',
                    $borrower_type,
                    $employee_id_db,
                    $borrower_name,
                    $receivable_type,
                    $amount,
                    $given_date,
                    $due_date,
                    $source,
                    $bank_name,
                    $account_number,
                    $description,
                    $company_id,
                    $id
                );

                $stmt->execute();
                $stmt->close();

                $msg = 'Receivable updated successfully.';
            } else {
                $balance = $amount;
                $employee_id_db = $employee_id > 0 ? $employee_id : null;

                $stmt = failIfPrepareFalse($conn->prepare("
                    INSERT INTO receivables (
                        company_id, borrower_type, employee_id, borrower_name, receivable_type,
                        original_amount, received_amount, balance_amount, given_date, due_date,
                        payment_source, bank_name, account_number, status, description, created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)
                "));

                $stmt->bind_param(
                    'isissddssssssi',
                    $company_id,
                    $borrower_type,
                    $employee_id_db,
                    $borrower_name,
                    $receivable_type,
                    $amount,
                    $balance,
                    $given_date,
                    $due_date,
                    $source,
                    $bank_name,
                    $account_number,
                    $description,
                    $user_id
                );

                $stmt->execute();
                $new_id = (int)$stmt->insert_id;
                $stmt->close();

                insertReceivableGivenLedger(
                    $conn,
                    $company_id,
                    $new_id,
                    $borrower_name,
                    $given_date,
                    $amount,
                    $source,
                    $bank_name,
                    $account_number
                );

                $msg = 'Receivable added successfully.';
            }

            $conn->commit();
            $msgType = 'success';
            $edit = resetReceivableForm();
            $edit_mode = false;
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignore) {
            }
            $msg = $e->getMessage();
            $msgType = 'danger';
        }
    }
}

if (isset($_GET['delete']) && $canCrud) {
    $id = (int)($_GET['delete'] ?? 0);

    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request.';
        $msgType = 'danger';
    } elseif ($id > 0) {
        try {
            $stmt = failIfPrepareFalse($conn->prepare("
                SELECT received_amount
                FROM receivables
                WHERE company_id = ? AND receivable_id = ?
            "));
            $stmt->bind_param('ii', $company_id, $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                throw new RuntimeException('Record not found.');
            }

            if ((float)$row['received_amount'] > 0) {
                throw new RuntimeException('Cannot delete. This receivable already has collection history.');
            }

            $stmt = failIfPrepareFalse($conn->prepare("
                DELETE FROM receivables
                WHERE company_id = ? AND receivable_id = ?
            "));
            $stmt->bind_param('ii', $company_id, $id);
            $stmt->execute();
            $stmt->close();

            $msg = 'Receivable deleted successfully.';
            $msgType = 'success';
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $msgType = 'danger';
        }
    }
}

$employees = [];
$stmt = $conn->prepare("SELECT employee_id, employee_name FROM employees WHERE company_id = ? ORDER BY employee_name ASC");
if ($stmt) {
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $employees[] = $r;
    }
    $stmt->close();
}

$rows = [];
$stmt = failIfPrepareFalse($conn->prepare("
    SELECT *
    FROM receivables
    WHERE company_id = ?
    ORDER BY receivable_id DESC
"));
$stmt->bind_param('i', $company_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/topbar.php';
?>

<div class="main-area">
    <div class="content">

    <div class="content">
        <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <?php if ($canCrud): ?>
        <div class="card">
            <div class="card-header">
                <h3><?= $edit_mode ? 'Edit Receivable' : 'Add Receivable / Loan Given' ?></h3>
                <span class="badge badge-primary">Money To Receive</span>
            </div>

            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="receivable_id" value="<?= e((string)$edit['receivable_id']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Borrower Type</label>
                            <select name="borrower_type" id="borrowerType" class="form-control">
                                <option value="Person"
                                    <?= ($edit['borrower_type'] ?? '') === 'Person' ? 'selected' : '' ?>>Person</option>
                                <option value="Organization"
                                    <?= ($edit['borrower_type'] ?? '') === 'Organization' ? 'selected' : '' ?>>
                                    Organization</option>
                                <option value="Employee"
                                    <?= ($edit['borrower_type'] ?? '') === 'Employee' ? 'selected' : '' ?>>Employee
                                </option>
                            </select>
                        </div>

                        <div class="form-group hidden" id="employeeWrap">
                            <label class="form-label">Employee</label>
                            <select name="employee_id" id="employeeSelect" class="form-control">
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= (int)$emp['employee_id'] ?>"
                                    <?= (int)($edit['employee_id'] ?? 0) === (int)$emp['employee_id'] ? 'selected' : '' ?>>
                                    <?= e((string)$emp['employee_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Borrower Name</label>
                            <input type="text" name="borrower_name" id="borrowerName" class="form-control"
                                value="<?= e((string)$edit['borrower_name']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Receivable Type</label>
                            <select name="receivable_type" class="form-control">
                                <option value="Loan Given"
                                    <?= ($edit['receivable_type'] ?? '') === 'Loan Given' ? 'selected' : '' ?>>Loan
                                    Given</option>
                                <option value="Employee Advance"
                                    <?= ($edit['receivable_type'] ?? '') === 'Employee Advance' ? 'selected' : '' ?>>
                                    Employee Advance</option>
                                <option value="Supplier Advance"
                                    <?= ($edit['receivable_type'] ?? '') === 'Supplier Advance' ? 'selected' : '' ?>>
                                    Supplier Advance</option>
                                <option value="Other Receivable"
                                    <?= ($edit['receivable_type'] ?? '') === 'Other Receivable' ? 'selected' : '' ?>>
                                    Other Receivable</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Amount</label>
                            <input type="number" step="0.01" min="0.01" name="original_amount" class="form-control"
                                value="<?= e((string)$edit['original_amount']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Given Date</label>
                            <input type="date" name="given_date" class="form-control"
                                value="<?= e((string)$edit['given_date']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" class="form-control"
                                value="<?= e((string)$edit['due_date']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Paid From</label>
                            <select name="payment_source" id="paymentSource" class="form-control">
                                <option value="Cash"
                                    <?= ($edit['payment_source'] ?? '') === 'Cash' ? 'selected' : '' ?>>Cash</option>
                                <option value="Bank"
                                    <?= ($edit['payment_source'] ?? '') === 'Bank' ? 'selected' : '' ?>>Bank</option>
                            </select>
                        </div>

                        <div class="form-group hidden" id="bankNameWrap">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control"
                                value="<?= e((string)$edit['bank_name']) ?>">
                        </div>

                        <div class="form-group hidden" id="accountNoWrap">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" class="form-control"
                                value="<?= e((string)$edit['account_number']) ?>">
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Description</label>
                            <textarea name="description"
                                class="form-control"><?= e((string)$edit['description']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="save_receivable" class="btn btn-primary">
                                <?= $edit_mode ? 'Update Receivable' : 'Save Receivable' ?>
                            </button>
                            <?php if ($edit_mode): ?>
                            <a href="receivables.php" class="btn btn-light">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card<?= $canCrud ? ' mt-24' : '' ?>">
            <div class="card-header">
                <h3>Receivable Records</h3>
                <span class="badge badge-success"><?= count($rows) ?> Records</span>
            </div>

            <div class="card-body">
                <input type="text" id="receivableSearch" class="form-control" placeholder="Search receivables..."
                    style="margin-bottom:16px;">

                <div class="table-wrap">
                    <table class="table" id="receivableTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Borrower</th>
                                <th>Type</th>
                                <th>Original</th>
                                <th>Received</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Due Date</th>
                                <th>Source</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e((string)$row['receivable_id']) ?></td>
                                <td><?= e((string)$row['borrower_name']) ?></td>
                                <td><?= e((string)$row['receivable_type']) ?></td>
                                <td>Rs. <?= number_format((float)$row['original_amount'], 2) ?></td>
                                <td>Rs. <?= number_format((float)$row['received_amount'], 2) ?></td>
                                <td>Rs. <?= number_format((float)$row['balance_amount'], 2) ?></td>
                                <td><span class="badge badge-primary"><?= e((string)$row['status']) ?></span></td>
                                <td><?= e((string)($row['due_date'] ?? '')) ?></td>
                                <td><?= e((string)$row['payment_source']) ?></td>
                                <td>
                                    <?php if ($canCrud): ?>
                                    <a class="btn btn-secondary"
                                        href="receivable_collection.php?id=<?= (int)$row['receivable_id'] ?>">Collect</a>
                                    <a class="btn btn-light" href="?edit=<?= (int)$row['receivable_id'] ?>">Edit</a>
                                    <a class="btn btn-danger"
                                        href="?delete=<?= (int)$row['receivable_id'] ?>&csrf_token=<?= urlencode(get_csrf_token()) ?>"
                                        onclick="return confirm('Delete this receivable?')">Delete</a>
                                    <?php else: ?>
                                    <span class="text-muted">View Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="10">No receivable records found.</td>
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
const borrowerType = document.getElementById('borrowerType');
const employeeWrap = document.getElementById('employeeWrap');
const employeeSelect = document.getElementById('employeeSelect');
const borrowerName = document.getElementById('borrowerName');

function toggleEmployee() {
    if (!borrowerType || !employeeWrap) return;
    employeeWrap.style.display = borrowerType.value === 'Employee' ? 'block' : 'none';
}

if (borrowerType) {
    toggleEmployee();
    borrowerType.addEventListener('change', toggleEmployee);
}

if (employeeSelect && borrowerName) {
    employeeSelect.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        if (selected && selected.text.trim() !== '-- Select Employee --') {
            borrowerName.value = selected.text.trim();
        }
    });
}

const paymentSource = document.getElementById('paymentSource');
const bankNameWrap = document.getElementById('bankNameWrap');
const accountNoWrap = document.getElementById('accountNoWrap');

function toggleBankFields() {
    if (!paymentSource || !bankNameWrap || !accountNoWrap) return;
    bankNameWrap.style.display = paymentSource.value === 'Bank' ? 'block' : 'none';
    accountNoWrap.style.display = paymentSource.value === 'Bank' ? 'block' : 'none';
}

if (paymentSource) {
    toggleBankFields();
    paymentSource.addEventListener('change', toggleBankFields);
}

const search = document.getElementById('receivableSearch');
const table = document.getElementById('receivableTable');

if (search && table) {
    search.addEventListener('keyup', function() {
        const value = this.value.toLowerCase();
        table.querySelectorAll('tbody tr').forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(value) ? '' : 'none';
        });
    });
}
</script>