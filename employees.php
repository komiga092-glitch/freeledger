<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

set_security_headers();

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header("Location: login.php");
    exit;
}

$company_id = (int)($_SESSION['company_id'] ?? 0);
$user_id    = (int)($_SESSION['user_id'] ?? 0);

if ($company_id <= 0) {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Employees';
$pageDescription = 'Manage employee records for the selected company';

$msg = '';
$msgType = 'success';
$edit_mode = false;

$currentRole = verify_user_role($user_id, $company_id);
$currentRole = normalize_role_value($currentRole);

$canView = in_array($currentRole, ['organization', 'accountant', 'auditor'], true);
$canCrud = $currentRole === 'accountant'; // Only accountant can CRUD

if (!$canView) {
    header("Location: dashboard.php");
    exit;
}

function resetEmployeeForm(): array
{
    return [
        'employee_id'   => '',
        'employee_name' => '',
        'nic'           => '',
        'phone'         => '',
        'email'         => '',
        'position'      => '',
        'basic_salary'  => '',
        'increment'     => '0.00',
        'total_salary'  => '0.00',
        'join_date'     => ''
    ];
}

function isValidDateString(string $date): bool
{
    if ($date === '') {
        return true;
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d instanceof DateTime && $d->format('Y-m-d') === $date;
}

$edit = resetEmployeeForm();

/* =========================
   EDIT FETCH
========================= */
if (isset($_GET['edit'])) {
    $employee_id = (int)($_GET['edit'] ?? 0);

    if ($employee_id > 0) {
        $stmt = $conn->prepare("
            SELECT
                employee_id,
                employee_name,
                nic,
                phone,
                email,
                position,
                basic_salary,
                increment,
                total_salary,
                join_date
            FROM employees
            WHERE company_id = ? AND employee_id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("ii", $company_id, $employee_id);
            $stmt->execute();

            $stmt->bind_result(
                $edit_employee_id,
                $edit_employee_name,
                $edit_nic,
                $edit_phone,
                $edit_email,
                $edit_position,
                $edit_basic_salary,
                $edit_increment,
                $edit_total_salary,
                $edit_join_date
            );

            if ($stmt->fetch()) {
                $edit = [
                    'employee_id'   => (string)$edit_employee_id,
                    'employee_name' => $edit_employee_name ?? '',
                    'nic'           => $edit_nic ?? '',
                    'phone'         => $edit_phone ?? '',
                    'email'         => $edit_email ?? '',
                    'position'      => $edit_position ?? '',
                    'basic_salary'  => number_format((float)$edit_basic_salary, 2, '.', ''),
                    'increment'     => number_format((float)$edit_increment, 2, '.', ''),
                    'total_salary'  => number_format((float)$edit_total_salary, 2, '.', ''),
                    'join_date'     => $edit_join_date ?? ''
                ];
                $edit_mode = true;
            } else {
                $msg = 'Employee record not found.';
                $msgType = 'warning';
            }

            $stmt->close();
        } else {
            $msg = 'Failed to prepare employee edit query.';
            $msgType = 'danger';
        }
    }
}

/* =========================
   ADD / UPDATE EMPLOYEE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_employee'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can create or edit employee records.';
        $msgType = 'danger';
    } else {
        $employee_id   = (int)($_POST['employee_id'] ?? 0);
        $employee_name = trim($_POST['employee_name'] ?? '');
        $nic           = trim($_POST['nic'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $position      = trim($_POST['position'] ?? '');
        $basic_salary  = (float)($_POST['basic_salary'] ?? 0);
        $increment     = (float)($_POST['increment'] ?? 0);
        $join_date     = trim($_POST['join_date'] ?? '');

        $allowSave = true;

        /* Preserve form values on validation failure */
        $edit = [
            'employee_id'   => (string)$employee_id,
            'employee_name' => $employee_name,
            'nic'           => $nic,
            'phone'         => $phone,
            'email'         => $email,
            'position'      => $position,
            'basic_salary'  => number_format($basic_salary, 2, '.', ''),
            'increment'     => number_format($increment, 2, '.', ''),
            'total_salary'  => number_format($basic_salary + $increment, 2, '.', ''),
            'join_date'     => $join_date
        ];
        $edit_mode = ($employee_id > 0);

        /* On update, do not allow basic salary modification from form */
        if ($employee_id > 0) {
            $stmt = $conn->prepare("
                SELECT basic_salary
                FROM employees
                WHERE company_id = ? AND employee_id = ?
                LIMIT 1
            ");

            if ($stmt) {
                $stmt->bind_param("ii", $company_id, $employee_id);
                $stmt->execute();
                $stmt->bind_result($existing_basic_salary);

                if ($stmt->fetch()) {
                    $basic_salary = (float)$existing_basic_salary;
                    $edit['basic_salary'] = number_format($basic_salary, 2, '.', '');
                } else {
                    $msg = 'Employee record not found for update.';
                    $msgType = 'danger';
                    $allowSave = false;
                }

                $stmt->close();
            } else {
                $msg = 'Failed to validate employee update.';
                $msgType = 'danger';
                $allowSave = false;
            }
        }

        $total_salary = $basic_salary + $increment;
        $edit['total_salary'] = number_format($total_salary, 2, '.', '');

        /* Validations */
        if (!$allowSave) {
            // keep previous error
        } elseif ($employee_name === '') {
            $msg = 'Employee name is required.';
            $msgType = 'danger';
        } elseif (mb_strlen($employee_name) < 2 || mb_strlen($employee_name) > 150) {
            $msg = 'Employee name must be between 2 and 150 characters.';
            $msgType = 'danger';
        } elseif ($nic !== '' && mb_strlen($nic) > 20) {
            $msg = 'NIC is too long.';
            $msgType = 'danger';
        } elseif ($phone !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
            $msg = 'Please enter a valid phone number.';
            $msgType = 'danger';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Please enter a valid email address.';
            $msgType = 'danger';
        } elseif ($position !== '' && mb_strlen($position) > 100) {
            $msg = 'Position is too long.';
            $msgType = 'danger';
        } elseif ($basic_salary < 0) {
            $msg = 'Basic salary cannot be negative.';
            $msgType = 'danger';
        } elseif ($increment < 0) {
            $msg = 'Increment cannot be negative.';
            $msgType = 'danger';
        } elseif ($total_salary < 0) {
            $msg = 'Total salary cannot be negative.';
            $msgType = 'danger';
        } elseif (!isValidDateString($join_date)) {
            $msg = 'Please enter a valid join date.';
            $msgType = 'danger';
        } else {
            /* Optional duplicate email check within same company */
            if ($email !== '') {
                if ($employee_id > 0) {
                    $dup = $conn->prepare("
                        SELECT employee_id
                        FROM employees
                        WHERE company_id = ? AND email = ? AND employee_id <> ?
                        LIMIT 1
                    ");
                    if ($dup) {
                        $dup->bind_param("isi", $company_id, $email, $employee_id);
                    }
                } else {
                    $dup = $conn->prepare("
                        SELECT employee_id
                        FROM employees
                        WHERE company_id = ? AND email = ?
                        LIMIT 1
                    ");
                    if ($dup) {
                        $dup->bind_param("is", $company_id, $email);
                    }
                }

                if (!empty($dup) && $dup instanceof mysqli_stmt) {
                    $dup->execute();
                    $dup->store_result();

                    if ($dup->num_rows > 0) {
                        $msg = 'This email is already used by another employee in this company.';
                        $msgType = 'danger';
                        $allowSave = false;
                    }

                    $dup->close();
                } elseif ($allowSave) {
                    $msg = 'Failed to validate employee email.';
                    $msgType = 'danger';
                    $allowSave = false;
                }
            }

            if ($allowSave) {
                if ($employee_id > 0) {
                    $stmt = $conn->prepare("
                        UPDATE employees
                        SET
                            employee_name = ?,
                            nic = ?,
                            phone = ?,
                            email = ?,
                            position = ?,
                            basic_salary = ?,
                            increment = ?,
                            total_salary = ?,
                            join_date = ?
                        WHERE company_id = ? AND employee_id = ?
                    ");

                    if (!$stmt) {
                        $msg = 'Failed to prepare update query.';
                        $msgType = 'danger';
                    } else {
                        $stmt->bind_param(
                            "sssssdddssi",
                            $employee_name,
                            $nic,
                            $phone,
                            $email,
                            $position,
                            $basic_salary,
                            $increment,
                            $total_salary,
                            $join_date,
                            $company_id,
                            $employee_id
                        );

                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                $msg = 'Employee updated successfully.';
                                $msgType = 'success';
                                $edit_mode = false;
                                $edit = resetEmployeeForm();
                            } else {
                                $msg = 'No changes were made.';
                                $msgType = 'warning';
                            }
                        } else {
                            $msg = 'Failed to update employee.';
                            $msgType = 'danger';
                        }

                        $stmt->close();
                    }
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO employees
                        (
                            company_id,
                            employee_name,
                            nic,
                            phone,
                            email,
                            position,
                            basic_salary,
                            increment,
                            total_salary,
                            join_date
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    if (!$stmt) {
                        $msg = 'Failed to prepare insert query.';
                        $msgType = 'danger';
                    } else {
                        $stmt->bind_param(
                            "isssssddds",
                            $company_id,
                            $employee_name,
                            $nic,
                            $phone,
                            $email,
                            $position,
                            $basic_salary,
                            $increment,
                            $total_salary,
                            $join_date
                        );

                        if ($stmt->execute()) {
                            $msg = 'Employee added successfully.';
                            $msgType = 'success';
                            $edit_mode = false;
                            $edit = resetEmployeeForm();
                        } else {
                            $msg = 'Failed to add employee.';
                            $msgType = 'danger';
                        }

                        $stmt->close();
                    }
                }
            }
        }
    }
}

/* =========================
   DELETE EMPLOYEE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_employee'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid form submission. Please try again.';
        $msgType = 'danger';
    } elseif (!$canCrud) {
        $msg = 'Only accountants can delete employee records.';
        $msgType = 'danger';
    } else {
        $employee_id = (int)($_POST['employee_id'] ?? 0);

        if ($employee_id <= 0) {
            $msg = 'Invalid employee selected.';
            $msgType = 'danger';
        } else {
            /* Prevent delete if salary records exist */
            $checkStmt = $conn->prepare("
                SELECT COUNT(*)
                FROM salaries
                WHERE company_id = ? AND employee_id = ?
            ");

            if (!$checkStmt) {
                $msg = 'Failed to validate employee deletion.';
                $msgType = 'danger';
            } else {
                $checkStmt->bind_param("ii", $company_id, $employee_id);
                $checkStmt->execute();
                $checkStmt->bind_result($salaryCount);
                $checkStmt->fetch();
                $checkStmt->close();

                if ((int)$salaryCount > 0) {
                    $msg = 'This employee cannot be deleted because salary records already exist.';
                    $msgType = 'warning';
                } else {
                    $stmt = $conn->prepare("
                        DELETE FROM employees
                        WHERE company_id = ? AND employee_id = ?
                    ");

                    if (!$stmt) {
                        $msg = 'Failed to prepare delete query.';
                        $msgType = 'danger';
                    } else {
                        $stmt->bind_param("ii", $company_id, $employee_id);

                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                $msg = 'Employee deleted successfully.';
                                $msgType = 'success';

                                if ($edit_mode && (int)$edit['employee_id'] === $employee_id) {
                                    $edit_mode = false;
                                    $edit = resetEmployeeForm();
                                }
                            } else {
                                $msg = 'Employee not found or already deleted.';
                                $msgType = 'warning';
                            }
                        } else {
                            $msg = 'Delete failed.';
                            $msgType = 'danger';
                        }

                        $stmt->close();
                    }
                }
            }
        }
    }
}

/* =========================
   FETCH EMPLOYEES
========================= */
$rows = [];

$stmt = $conn->prepare("
    SELECT
        employee_id,
        employee_name,
        nic,
        phone,
        email,
        position,
        basic_salary,
        increment,
        total_salary,
        join_date
    FROM employees
    WHERE company_id = ?
    ORDER BY employee_id DESC
");

if ($stmt) {
    $stmt->bind_param("i", $company_id);
    $stmt->execute();

    $stmt->bind_result(
        $row_employee_id,
        $row_employee_name,
        $row_nic,
        $row_phone,
        $row_email,
        $row_position,
        $row_basic_salary,
        $row_increment,
        $row_total_salary,
        $row_join_date
    );

    while ($stmt->fetch()) {
        $rows[] = [
            'employee_id'   => $row_employee_id,
            'employee_name' => $row_employee_name ?? '',
            'nic'           => $row_nic ?? '',
            'phone'         => $row_phone ?? '',
            'email'         => $row_email ?? '',
            'position'      => $row_position ?? '',
            'basic_salary'  => (float)$row_basic_salary,
            'increment'     => (float)$row_increment,
            'total_salary'  => (float)$row_total_salary,
            'join_date'     => $row_join_date ?? ''
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
                <h3><?= $edit_mode ? 'Edit Employee' : 'Add Employee' ?></h3>
                <span class="badge badge-primary">Employee Master</span>
            </div>

            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="employee_id" value="<?= e($edit['employee_id']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Employee Name</label>
                            <input type="text" name="employee_name" class="form-control"
                                value="<?= e($edit['employee_name']) ?>" maxlength="150" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">NIC</label>
                            <input type="text" name="nic" class="form-control" value="<?= e($edit['nic']) ?>"
                                maxlength="20">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= e($edit['phone']) ?>"
                                maxlength="20">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= e($edit['email']) ?>"
                                maxlength="255">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Position</label>
                            <input type="text" name="position" class="form-control" value="<?= e($edit['position']) ?>"
                                maxlength="100">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Basic Salary</label>
                            <input type="number" step="0.01" min="0" name="basic_salary" class="form-control"
                                value="<?= e((string)$edit['basic_salary']) ?>"
                                <?= $edit_mode ? 'readonly' : 'required' ?>>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Increment</label>
                            <input type="number" step="0.01" min="0" name="increment" class="form-control"
                                value="<?= e((string)$edit['increment']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Total Salary (Basic + Increment)</label>
                            <input type="text" class="form-control"
                                value="Rs. <?= number_format((float)$edit['total_salary'], 2) ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Join Date</label>
                            <input type="date" name="join_date" class="form-control"
                                value="<?= e($edit['join_date']) ?>">
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="save_employee" class="btn btn-primary">
                                <?= $edit_mode ? 'Update Employee' : 'Save Employee' ?>
                            </button>

                            <?php if ($edit_mode): ?>
                            <a href="employees.php" class="btn btn-light">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Employee List</h3>
                <span class="badge badge-success"><?= count($rows) ?> Records</span>
            </div>

            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>NIC</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Position</th>
                                <th>Basic Salary</th>
                                <th>Increment</th>
                                <th>Total Salary</th>
                                <th>Join Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e((string)$row['employee_id']) ?></td>
                                <td><?= e($row['employee_name']) ?></td>
                                <td><?= e($row['nic']) ?></td>
                                <td><?= e($row['phone']) ?></td>
                                <td><?= e($row['email']) ?></td>
                                <td><?= e($row['position']) ?></td>
                                <td>Rs. <?= number_format((float)$row['basic_salary'], 2) ?></td>
                                <td>Rs. <?= number_format((float)$row['increment'], 2) ?></td>
                                <td>Rs. <?= number_format((float)$row['total_salary'], 2) ?></td>
                                <td><?= e($row['join_date']) ?></td>
                                <td>
                                    <?php if ($canCrud): ?>
                                    <a href="?edit=<?= (int)$row['employee_id'] ?>" class="btn btn-light">Edit</a>

                                    <form method="POST" class="inline-form"
                                        onsubmit="return confirm('Delete this employee?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="employee_id" value="<?= (int)$row['employee_id'] ?>">
                                        <button type="submit" name="delete_employee" class="btn btn-danger">
                                            Delete
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-muted">View Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="11">No employee records found.</td>
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