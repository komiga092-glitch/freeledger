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

$pageTitle = 'Assets & Liabilities Report';
$pageDescription = 'Professional English statement with final balance check';

$from_date = trim($_GET['from_date'] ?? date('Y-01-01'));
$to_date   = trim($_GET['to_date'] ?? date('Y-m-d'));

if ($from_date === '' || $to_date === '') {
    $from_date = date('Y-01-01');
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
   ASSETS
========================= */
$asset_rows = [];
$total_assets = 0;

$stmt = $conn->prepare("
    SELECT asset_name, asset_type, purchase_date,
           COALESCE(NULLIF(current_value, 0), NULLIF(cost_value, 0), asset_value, 0) AS report_value
    FROM assets
    WHERE company_id = ?
      AND purchase_date <= ?
    ORDER BY asset_type ASC, asset_name ASC
");
$stmt->bind_param("is", $company_id, $to_date);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $amount = (float)$row['report_value'];
    $asset_rows[] = [
        'name' => $row['asset_name'],
        'type' => $row['asset_type'],
        'date' => $row['purchase_date'],
        'amount' => $amount
    ];
    $total_assets += $amount;
}
$stmt->close();

/* =========================
   CASH BALANCE
========================= */
$opening_cash = 0;
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(opening_balance), 0) AS opening_cash
    FROM cash_accounts
    WHERE company_id = ?
      AND status = 'Active'
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $opening_cash = (float)$row['opening_cash'];
}
$stmt->close();

$cash_transactions = 0;
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(
        CASE
            WHEN transaction_type = 'Cash In' THEN amount
            WHEN transaction_type = 'Cash Out' THEN -amount
            ELSE 0
        END
    ), 0) AS cash_transactions
    FROM cash_account
    WHERE company_id = ?
      AND transaction_date <= ?
");
$stmt->bind_param("is", $company_id, $to_date);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $cash_transactions = (float)$row['cash_transactions'];
}
$stmt->close();

$cash_balance = $opening_cash + $cash_transactions;

/* =========================
   BANK BALANCE
========================= */
$opening_bank = 0;
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(balance), 0) AS opening_bank
    FROM bank_accounts
    WHERE company_id = ?
      AND status = 'Active'
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $opening_bank = (float)$row['opening_bank'];
}
$stmt->close();

$bank_transactions = 0;
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(
        CASE
            WHEN transaction_type = 'Deposit' THEN amount
            WHEN transaction_type = 'Withdrawal' THEN -amount
            ELSE 0
        END
    ), 0) AS bank_transactions
    FROM bank_account
    WHERE company_id = ?
      AND transaction_date <= ?
");
$stmt->bind_param("is", $company_id, $to_date);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $bank_transactions = (float)$row['bank_transactions'];
}
$stmt->close();

$bank_balance = $opening_bank + $bank_transactions;

/* =========================
   INCOME TOTAL
========================= */
$total_income = 0;
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_income
    FROM income
    WHERE company_id = ?
      AND income_date <= ?
");
$stmt->bind_param("is", $company_id, $to_date);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $total_income = (float)$row['total_income'];
}
$stmt->close();

/* =========================
   EXPENSE TOTAL
========================= */
$total_expense = 0;
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_expense
    FROM expenses
    WHERE company_id = ?
      AND expense_date <= ?
");
$stmt->bind_param("is", $company_id, $to_date);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $total_expense = (float)$row['total_expense'];
}
$stmt->close();

$net_result = $total_income - $total_expense;
$net_label  = $net_result >= 0 ? 'Accumulated Surplus' : 'Accumulated Deficit';

/* =========================
   LIABILITIES
========================= */
$liability_rows = [];
$total_liabilities = 0;

$stmt = $conn->prepare("
    SELECT liability_name, liability_type, liability_date, amount
    FROM liabilities
    WHERE company_id = ?
      AND liability_date <= ?
    ORDER BY liability_type ASC, liability_name ASC
");
$stmt->bind_param("is", $company_id, $to_date);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $amount = (float)$row['amount'];
    $liability_rows[] = [
        'name' => $row['liability_name'],
        'type' => $row['liability_type'],
        'date' => $row['liability_date'],
        'amount' => $amount
    ];
    $total_liabilities += $amount;
}
$stmt->close();

/* =========================
   FINAL BALANCE
========================= */
$left_total   = $total_assets + $cash_balance + $bank_balance;
$equity       = $left_total - $total_liabilities;
$equity_label = $equity >= 0 ? 'Accumulated Surplus' : 'Accumulated Deficit';
$right_total  = $total_liabilities + $equity;

$is_balanced = abs($left_total - $right_total) < 0.01;

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar no-print">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="page-heading">
                <h1>Assets & Liabilities Report</h1>
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

        <div class="card print-card">
            <div class="card-body">
                <div style="text-align:center; margin-bottom:28px;">
                    <h2 style="margin-bottom:8px;">STATEMENT OF ASSETS & LIABILITIES</h2>
                    <h3 style="margin-bottom:6px;"><?= e($company['company_name'] ?? 'Company') ?></h3>
                    <div style="margin-bottom:4px;">Registration No: <?= e($company['registration_no'] ?? '-') ?></div>
                    <div style="margin-bottom:4px;">As at: <?= e($to_date) ?></div>
                </div>

                <div class="grid grid-3 no-print" style="margin-bottom:24px;">
                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-title">Assets + Cash + Bank</div>
                            <div class="stat-icon">📦</div>
                        </div>
                        <div class="stat-value">Rs. <?= number_format($left_total, 2) ?></div>
                        <div class="stat-note">Left side of statement</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-title">
                                <?= $equity_label ?> + Liabilities</div>
                            <div class="stat-icon">📉</div>
                        </div>
                        <div class="stat-value">Rs. <?= number_format($right_total, 2) ?></div>
                        <div class="stat-note">Right side of statement</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-title">Status</div>
                            <div class="stat-icon"><?= $is_balanced ? '✅' : '⚠️' ?></div>
                        </div>
                        <div class="stat-value"><?= $is_balanced ? 'Balanced' : 'Not Balanced' ?></div>
                        <div class="stat-note">Final statement check</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:40%;">Assets</th>
                                <th style="width:20%; text-align:right;">Amount (Rs.)</th>
                                <th style="width:40%;">Capital / Liabilities</th>
                                <th style="width:20%; text-align:right;">Amount (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $right_items = [];

                            $right_items[] = [
                                'label' => $equity_label,
                                'amount' => abs($equity)
                            ];

                            foreach ($liability_rows as $liability) {
                                $right_items[] = [
                                    'label' => $liability['name'] . ' (' . $liability['type'] . ')',
                                    'amount' => $liability['amount']
                                ];
                            }

                            $left_items = [];
                            foreach ($asset_rows as $asset) {
                                $left_items[] = [
                                    'label' => $asset['name'] . ' (' . $asset['type'] . ')',
                                    'amount' => $asset['amount']
                                ];
                            }

                            $left_items[] = ['label' => 'Cash Balance', 'amount' => $cash_balance];
                            $left_items[] = ['label' => 'Bank Balance', 'amount' => $bank_balance];

                            $max_rows = max(count($left_items), count($right_items));
                            if ($max_rows === 0) {
                                $max_rows = 1;
                            }

                            for ($i = 0; $i < $max_rows; $i++):
                            ?>
                            <tr>
                                <td><?= e($left_items[$i]['label'] ?? '') ?></td>
                                <td style="text-align:right;">
                                    <?= isset($left_items[$i]) ? number_format((float)$left_items[$i]['amount'], 2) : '' ?>
                                </td>
                                <td><?= e($right_items[$i]['label'] ?? '') ?></td>
                                <td style="text-align:right;">
                                    <?= isset($right_items[$i]) ? number_format((float)$right_items[$i]['amount'], 2) : '' ?>
                                </td>
                            </tr>
                            <?php endfor; ?>

                            <tr>
                                <th>Total Assets + Cash + Bank</th>
                                <th style="text-align:right;">Rs. <?= number_format($left_total, 2) ?></th>
                                <th>Total Surplus/Deficit + Liabilities</th>
                                <th style="text-align:right;">Rs. <?= number_format($right_total, 2) ?></th>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:24px; padding:14px; border:1px solid #ddd; border-radius:8px;">
                    <strong>Balance Check:</strong>
                    <?php if ($is_balanced): ?>
                    <span style="color:green;">The statement is balanced.</span>
                    <?php else: ?>
                    <span style="color:red;">
                        The statement is not balanced. Difference: Rs.
                        <?= number_format(abs($left_total - $right_total), 2) ?>
                    </span>
                    <?php endif; ?>
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