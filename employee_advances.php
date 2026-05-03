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

$pageTitle = 'Employee Advances';
$pageDescription = 'Manage employee advance payments and salary deductions';

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

function isValidIsoDate(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

function resetAdvanceForm(): array
{
    return [
        'advance_id' => '',
        'employee_id' => '',
        'advance_amount' => '',
        'deduction_months' => '',
        'monthly_deduction' => '',
        'start_month' => date('Y-m-01'),
        'notes' => ''
    ];
}

$edit_mode = false;
$edit = resetAdvanceForm();

/* =========================
   EDIT FETCH
========================= */
if (isset($_GET['edit'])) {
    $id = (int)($_GET['edit'] ?? 0);

    if ($id > 0) {
        $stmt = failIfPrepareFalse($conn->prepare("
            SELECT *
            FROM employee_advances
            WHERE company_id = ? AND advance_id = ?
            LIMIT 1
        "));

        $stmt->bind_param('ii', $company_id, $id);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $edit = array_merge($edit, $row);
            $edit_mode = true;
        }
    }
}

/* =========================
   SAVE ADVANCE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_advance'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid request.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Permission denied.';
        $msgType = 'danger';
    } else {
        $advance_id = (int)($_POST['advance_id'] ?? 0);
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $advance_amount = (float)($_POST['advance_amount'] ?? 0);
        $deduction_months = (int)($_POST['deduction_months'] ?? 0);
        $start_month = trim($_POST['start_month'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        try {
            if ($employee_id <= 0) {
                throw new RuntimeException('Please select employee.');
            }

            if ($advance_amount <= 0) {
                throw new RuntimeException('Advance amount must be greater than zero.');
            }

            if ($deduction_months <= 0) {
                throw new RuntimeException('Deduction months must be greater than zero.');
            }

            if ($start_month === '' || !isValidIsoDate($start_month)) {
                throw new RuntimeException('Please enter valid start month.');
            }

            $monthly_deduction = round($advance_amount / $deduction_months, 2);

            if ($advance_id > 0) {
                $stmt = failIfPrepareFalse($conn->prepare("
                    UPDATE employee_advances
                    SET employee_id = ?,
                        advance_amount = ?,
                        balance_amount = advance_amount - paid_amount,
                        deduction_months = ?,
                        monthly_deduction = ?,
                        start_month = ?,
                        notes = ?
                    WHERE company_id = ? AND advance_id = ?
                "));

                $stmt->bind_param(
                    'ididssii',
                    $employee_id,
                    $advance_amount,
                    $deduction_months,
                    $monthly_deduction,
                    $start_month,
                    $notes,
                    $company_id,
                    $advance_id
                );

                $stmt->execute();
                $stmt->close();

                $msg = 'Employee advance updated successfully.';
            } else {
                $stmt = failIfPrepareFalse($conn->prepare("
                    INSERT INTO employee_advances (
                        company_id,
                        employee_id,
                        advance_amount,
                        paid_amount,
                        balance_amount,
                        deduction_months,
                        monthly_deduction,
                        start_month,
                        status,
                        notes
                    )
                    VALUES (?, ?, ?, 0.00, ?, ?, ?, ?, 'Active', ?)
                "));

                $stmt->bind_param(
                    'iiddidss',
                    $company_id,
                    $employee_id,
                    $advance_amount,
                    $advance_amount,
                    $deduction_months,
                    $monthly_deduction,
                    $start_month,
                    $notes
                );

              $stmt->execute();

$new_advance_id = (int)$stmt->insert_id;
$stmt->close();

$description = '[EMPLOYEE_ADVANCE:' . $new_advance_id . '] Employee advance paid';

$cashStmt = failIfPrepareFalse($conn->prepare("
    INSERT INTO cash_account (
        company_id,
        transaction_date,
        description,
        transaction_type,
        amount
    )
    VALUES (?, ?, ?, 'Cash Out', ?)
"));

$cashStmt->bind_param(
    "issd",
    $company_id,
    $start_month,
    $description,
    $advance_amount
);

$cashStmt->execute();
$cashStmt->close();

$msg = 'Employee advance added successfully and cash account updated.';
            }
            $msgType = 'success';
            $edit = resetAdvanceForm();
            $edit_mode = false;

        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $msgType = 'danger';
        }
    }
}

/* =========================
   DELETE ADVANCE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_advance'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid delete request.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Permission denied.';
        $msgType = 'danger';
    } else {
        $advance_id = (int)($_POST['advance_id'] ?? 0);

        try {
            $stmt = failIfPrepareFalse($conn->prepare("
                SELECT paid_amount
                FROM employee_advances
                WHERE company_id = ? AND advance_id = ?
                LIMIT 1
            "));

            $stmt->bind_param('ii', $company_id, $advance_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                throw new RuntimeException('Advance record not found.');
            }

            if ((float)$row['paid_amount'] > 0) {
                throw new RuntimeException('Cannot delete. This advance already has salary deductions.');
            }

            $stmt = failIfPrepareFalse($conn->prepare("
                DELETE FROM employee_advances
                WHERE company_id = ? AND advance_id = ?
            "));

            $stmt->bind_param('ii', $company_id, $advance_id);
            $stmt->execute();
            $stmt->close();

            $msg = 'Employee advance deleted successfully.';
            $msgType = 'success';

        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $msgType = 'danger';
        }
    }
}

/* =========================
   FETCH EMPLOYEES
========================= */
$employees = [];

$stmt = failIfPrepareFalse($conn->prepare("
    SELECT employee_id, employee_name, total_salary
    FROM employees
    WHERE company_id = ?
    ORDER BY employee_name ASC
"));

$stmt->bind_param('i', $company_id);
$stmt->execute();

$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $employees[] = $row;
}

$stmt->close();

/* =========================
   FETCH ADVANCES
========================= */
$rows = [];

$stmt = failIfPrepareFalse($conn->prepare("
    SELECT 
        ea.*,
        e.employee_name
    FROM employee_advances ea
    INNER JOIN employees e ON e.employee_id = ea.employee_id
    WHERE ea.company_id = ?
    ORDER BY ea.advance_id DESC
"));

$stmt->bind_param('i', $company_id);
$stmt->execute();

$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}

$stmt->close();

include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/topbar.php';
?>

<div class="main-area">
    <div class="content">
    </div>

    <div class="content">
        <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <?php if ($canCrud): ?>
        <div class="card">
            <div class="card-header">
                <h3><?= $edit_mode ? 'Edit Advance' : 'Add Employee Advance' ?></h3>
                <span class="badge badge-primary">Salary Deduction</span>
            </div>

            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="advance_id" value="<?= e((string)$edit['advance_id']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Employee</label>
                            <select name="employee_id" class="form-control" required>
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= (int)$emp['employee_id'] ?>"
                                    <?= (int)($edit['employee_id'] ?? 0) === (int)$emp['employee_id'] ? 'selected' : '' ?>>
                                    <?= e((string)$emp['employee_name']) ?>
                                    - Salary Rs. <?= number_format((float)$emp['total_salary'], 2) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Advance Amount</label>
                            <input type="number" step="0.01" min="0.01" name="advance_amount" id="advanceAmount"
                                class="form-control" value="<?= e((string)$edit['advance_amount']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Deduction Months</label>
                            <input type="number" min="1" name="deduction_months" id="deductionMonths"
                                class="form-control" value="<?= e((string)$edit['deduction_months']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Monthly Deduction</label>
                            <input type="text" id="monthlyDeductionPreview" class="form-control"
                                value="<?= $edit['monthly_deduction'] !== '' ? 'Rs. ' . number_format((float)$edit['monthly_deduction'], 2) : '' ?>"
                                readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Start Month</label>
                            <input type="date" name="start_month" class="form-control"
                                value="<?= e((string)$edit['start_month']) ?>" required>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control"><?= e((string)$edit['notes']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="save_advance" class="btn btn-primary">
                                <?= $edit_mode ? 'Update Advance' : 'Save Advance' ?>
                            </button>

                            <?php if ($edit_mode): ?>
                            <a href="employee_advances.php" class="btn btn-light">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Employee Advance Records</h3>
                <span class="badge badge-success"><?= count($rows) ?> Records</span>
            </div>

            <div class="card-body">
                <input type="text" id="advanceSearch" class="form-control" placeholder="Search employee advances..."
                    style="margin-bottom:16px;">

                <div class="table-wrap">
                    <table class="table" id="advanceTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Employee</th>
                                <th>Advance</th>
                                <th>Deducted</th>
                                <th>Balance</th>
                                <th>Months</th>
                                <th>Monthly</th>
                                <th>Start Month</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($rows): ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e((string)$row['advance_id']) ?></td>
                                <td><?= e((string)$row['employee_name']) ?></td>
                                <td>Rs. <?= number_format((float)$row['advance_amount'], 2) ?></td>
                                <td>Rs. <?= number_format((float)$row['paid_amount'], 2) ?></td>
                                <td>Rs. <?= number_format((float)$row['balance_amount'], 2) ?></td>
                                <td><?= e((string)$row['deduction_months']) ?></td>
                                <td>Rs. <?= number_format((float)$row['monthly_deduction'], 2) ?></td>
                                <td><?= e((string)$row['start_month']) ?></td>
                                <td>
                                    <span class="badge badge-primary">
                                        <?= e((string)$row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($canCrud): ?>
                                    <a href="?edit=<?= (int)$row['advance_id'] ?>" class="btn btn-light">Edit</a>

                                    <form method="POST" class="inline-form"
                                        onsubmit="return confirm('Delete this advance?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="advance_id" value="<?= (int)$row['advance_id'] ?>">
                                        <button type="submit" name="delete_advance"
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
                                <td colspan="10">No employee advance records found.</td>
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
const advanceAmount = document.getElementById('advanceAmount');
const deductionMonths = document.getElementById('deductionMonths');
const monthlyPreview = document.getElementById('monthlyDeductionPreview');

function calculateMonthlyDeduction() {
    if (!advanceAmount || !deductionMonths || !monthlyPreview) return;

    const amount = parseFloat(advanceAmount.value || '0');
    const months = parseInt(deductionMonths.value || '0', 10);

    if (amount > 0 && months > 0) {
        const monthly = amount / months;
        monthlyPreview.value = 'Rs. ' + monthly.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    } else {
        monthlyPreview.value = '';
    }
}

if (advanceAmount && deductionMonths) {
    advanceAmount.addEventListener('input', calculateMonthlyDeduction);
    deductionMonths.addEventListener('input', calculateMonthlyDeduction);
    calculateMonthlyDeduction();
}

const advanceSearch = document.getElementById('advanceSearch');
const advanceTable = document.getElementById('advanceTable');

if (advanceSearch && advanceTable) {
    advanceSearch.addEventListener('keyup', function() {
        const value = this.value.toLowerCase().trim();

        advanceTable.querySelectorAll('tbody tr').forEach(function(row) {
            row.style.display = row.innerText.toLowerCase().includes(value) ? '' : 'none';
        });
    });
}
</script>