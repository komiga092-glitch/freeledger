<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$company_id = (int)($_SESSION['company_id'] ?? 0);
if ($company_id <= 0) {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Income & Expenditure Report';
$pageDescription = 'Professional English financial statement';

$from_date = trim($_GET['from_date'] ?? date('Y-m-01'));
$to_date   = trim($_GET['to_date'] ?? date('Y-m-d'));

if ($from_date === '' || $to_date === '') {
    $from_date = date('Y-m-01');
    $to_date   = date('Y-m-d');
}

if ($from_date > $to_date) {
    $temp = $from_date;
    $from_date = $to_date;
    $to_date = $temp;
}

/* =========================
   COMPANY
========================= */
$company = [];
$stmt = $conn->prepare("
    SELECT company_id, company_name, registration_no, email, phone, address
    FROM companies
    WHERE company_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
$company = $res->fetch_assoc() ?: [];
$stmt->close();

/* =========================
   INCOME SUMMARY
========================= */
$total_income = 0;
$income_summary = [];

$stmt = $conn->prepare("
    SELECT income_type, COALESCE(SUM(amount), 0) AS total_amount
    FROM income
    WHERE company_id = ?
      AND income_date BETWEEN ? AND ?
    GROUP BY income_type
    ORDER BY income_type ASC
");
$stmt->bind_param("iss", $company_id, $from_date, $to_date);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $type = trim($row['income_type'] ?? '') ?: 'Other Income';
    $amount = (float)$row['total_amount'];
    $income_summary[] = [
        'income_type' => $type,
        'amount' => $amount
    ];
    $total_income += $amount;
}
$stmt->close();

/* =========================
   EXPENSE SUMMARY
========================= */
$total_expense = 0;
$expense_summary = [];

$stmt = $conn->prepare("
    SELECT expense_type, COALESCE(SUM(amount), 0) AS total_amount
    FROM expenses
    WHERE company_id = ?
      AND expense_date BETWEEN ? AND ?
    GROUP BY expense_type
    ORDER BY expense_type ASC
");
$stmt->bind_param("iss", $company_id, $from_date, $to_date);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $type = trim($row['expense_type'] ?? '') ?: 'Other Expense';
    $amount = (float)$row['total_amount'];
    $expense_summary[] = [
        'expense_type' => $type,
        'amount' => $amount
    ];
    $total_expense += $amount;
}
$stmt->close();

$net_result = $total_income - $total_expense;
$result_label = $net_result >= 0 ? 'Surplus' : 'Deficit';

/* =========================
   DETAILED INCOME
========================= */
$income_rows = [];

$stmt = $conn->prepare("
    SELECT income_date, income_type, description, payment_source, amount
    FROM income
    WHERE company_id = ?
      AND income_date BETWEEN ? AND ?
    ORDER BY income_date ASC, income_id ASC
");
$stmt->bind_param("iss", $company_id, $from_date, $to_date);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $income_rows[] = $row;
}
$stmt->close();

/* =========================
   DETAILED EXPENSE
========================= */
$expense_rows = [];

$stmt = $conn->prepare("
    SELECT expense_date, expense_type, description, payment_source, amount
    FROM expenses
    WHERE company_id = ?
      AND expense_date BETWEEN ? AND ?
    ORDER BY expense_date ASC, expense_id ASC
");
$stmt->bind_param("iss", $company_id, $from_date, $to_date);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $expense_rows[] = $row;
}
$stmt->close();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar no-print">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="page-heading">
                <h1>Income & Expenditure Report</h1>
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
        <div class="card no-print">
            <div class="card-header">
                <h3>Report Filters</h3>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" onclick="window.print()" class="btn btn-primary">Print Report</button>
                </div>
            </div>

            <div class="card-body">
                <form method="GET">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">From Date</label>
                            <input type="date" name="from_date" class="form-control" value="<?= e($from_date) ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">To Date</label>
                            <input type="date" name="to_date" class="form-control" value="<?= e($to_date) ?>" required>
                        </div>

                        <div class="form-group full">
                            <button type="submit" class="btn btn-primary">Generate Report</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card print-card" id="printArea">
            <div class="card-body">
                <div style="text-align:center; margin-bottom:28px;">
                    <h2 style="margin-bottom:8px;">INCOME & EXPENDITURE STATEMENT</h2>
                    <h3 style="margin-bottom:6px;"><?= e($company['company_name'] ?? 'Company') ?></h3>
                    <div style="margin-bottom:4px;">Registration No: <?= e($company['registration_no'] ?? '-') ?></div>
                    <div style="margin-bottom:4px;">Period: <?= e($from_date) ?> to <?= e($to_date) ?></div>
                </div>

                <div class="grid grid-3 no-print" style="margin-bottom:24px;">
                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-title">Total Income</div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-value">Rs. <?= number_format($total_income, 2) ?></div>
                        <div class="stat-note">Total income for selected period</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-title">Total Expenditure</div>
                            <div class="stat-icon">💸</div>
                        </div>
                        <div class="stat-value">Rs. <?= number_format($total_expense, 2) ?></div>
                        <div class="stat-note">Total expenditure for selected period</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-title"><?= e($result_label) ?></div>
                            <div class="stat-icon">📊</div>
                        </div>
                        <div class="stat-value">Rs. <?= number_format(abs($net_result), 2) ?></div>
                        <div class="stat-note">Net result of the reporting period</div>
                    </div>
                </div>

                <div class="table-wrap" style="margin-bottom:24px;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:50%;">Income</th>
                                <th style="width:20%; text-align:right;">Amount (Rs.)</th>
                                <th style="width:30%;">Expenditure</th>
                                <th style="width:20%; text-align:right;">Amount (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $max_rows = max(count($income_summary), count($expense_summary));
                            if ($max_rows === 0):
                            ?>
                            <tr>
                                <td colspan="4" style="text-align:center;">No report data found for the selected period.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php for ($i = 0; $i < $max_rows; $i++): ?>
                            <tr>
                                <td><?= e($income_summary[$i]['income_type'] ?? '') ?></td>
                                <td style="text-align:right;">
                                    <?= isset($income_summary[$i]) ? number_format((float)$income_summary[$i]['amount'], 2) : '' ?>
                                </td>
                                <td><?= e($expense_summary[$i]['expense_type'] ?? '') ?></td>
                                <td style="text-align:right;">
                                    <?= isset($expense_summary[$i]) ? number_format((float)$expense_summary[$i]['amount'], 2) : '' ?>
                                </td>
                            </tr>
                            <?php endfor; ?>

                            <tr>
                                <th>Total Income</th>
                                <th style="text-align:right;">Rs. <?= number_format($total_income, 2) ?></th>
                                <th>Total Expenditure</th>
                                <th style="text-align:right;">Rs. <?= number_format($total_expense, 2) ?></th>
                            </tr>
                            <tr>
                                <th colspan="3"><?= e($result_label) ?></th>
                                <th style="text-align:right;">Rs. <?= number_format(abs($net_result), 2) ?></th>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card" style="margin-top:24px;">
                    <div class="card-header">
                        <h3>Detailed Income Transactions</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Income Type</th>
                                        <th>Description</th>
                                        <th>Payment Source</th>
                                        <th style="text-align:right;">Amount (Rs.)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($income_rows)): ?>
                                    <?php foreach ($income_rows as $row): ?>
                                    <tr>
                                        <td><?= e($row['income_date']) ?></td>
                                        <td><?= e($row['income_type']) ?></td>
                                        <td><?= e($row['description']) ?></td>
                                        <td><?= e($row['payment_source']) ?></td>
                                        <td style="text-align:right;"><?= number_format((float)$row['amount'], 2) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center;">No income transactions found.</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-top:24px;">
                    <div class="card-header">
                        <h3>Detailed Expenditure Transactions</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Expense Type</th>
                                        <th>Description</th>
                                        <th>Payment Source</th>
                                        <th style="text-align:right;">Amount (Rs.)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($expense_rows)): ?>
                                    <?php foreach ($expense_rows as $row): ?>
                                    <tr>
                                        <td><?= e($row['expense_date']) ?></td>
                                        <td><?= e($row['expense_type']) ?></td>
                                        <td><?= e($row['description']) ?></td>
                                        <td><?= e($row['payment_source']) ?></td>
                                        <td style="text-align:right;"><?= number_format((float)$row['amount'], 2) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center;">No expense transactions found.</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div style="margin-top:40px;">
                    <table style="width:100%; border-collapse:collapse;">
                        <tr>
                            <td style="width:50%; padding-top:40px;">
                                ___________________________<br>
                                Prepared By
                            </td>
                            <td style="width:50%; padding-top:40px; text-align:right;">
                                ___________________________<br>
                                Approved By
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {

    .sidebar,
    .topbar,
    .menu-toggle,
    .no-print,
    .btn,
    form {
        display: none !important;
    }

    body,
    .main-area,
    .content,
    .card,
    .card-body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        box-shadow: none !important;
    }

    .print-card {
        border: none !important;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
    }

    .table th,
    .table td {
        border: 1px solid #000 !important;
        padding: 8px !important;
        font-size: 12px !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>