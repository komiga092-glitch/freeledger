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

/* ---------- ROLE CHECK ---------- */
if (function_exists('verify_user_role')) {
    $currentRole = verify_user_role($user_id, $company_id);
} else {
    $currentRole = normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));
}

$currentRole = normalize_role_value((string)$currentRole);

if (!in_array($currentRole, ['organization', 'accountant', 'auditor'], true)) {
    die('Access denied.');
}

$pageTitle = 'Assets & Liabilities Report';
$pageDescription = 'Professional English statement with final balance check';

$from_date = trim((string)($_GET['from_date'] ?? date('Y-01-01')));
$to_date   = trim((string)($_GET['to_date'] ?? date('Y-m-d')));

if ($from_date === '' || $to_date === '') {
    $from_date = date('Y-01-01');
    $to_date   = date('Y-m-d');
}

if ($from_date > $to_date) {
    [$from_date, $to_date] = [$to_date, $from_date];
}

/* COMPANY */
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

/* ASSETS */
$asset_rows = [];
$total_assets = 0.00;

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

/* CASH BALANCE */
$opening_cash = 0.00;
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

$cash_transactions = 0.00;
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

/* BANK BALANCE */
$opening_bank = 0.00;
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

$bank_transactions = 0.00;
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

/* LIABILITIES */
$liability_rows = [];
$total_liabilities = 0.00;

$stmt = $conn->prepare("
    SELECT liability_name, liability_type, liability_date, balance_amount AS amount
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

/* FINAL BALANCE */
$left_total   = $total_assets + $cash_balance + $bank_balance;
$equity       = $left_total - $total_liabilities;
$equity_label = $equity >= 0 ? 'Accumulated Surplus' : 'Accumulated Deficit';
$right_total  = $total_liabilities + $equity;
$is_balanced  = abs($left_total - $right_total) < 0.01;

include 'includes/header.php';
?>

<script src="assets/js/html2pdf.bundle.min.js"></script>

<?php
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
            <div class="role-pill"><?= e(ucfirst($currentRole)) ?></div>
        </div>
    </div>

    <div class="content">
        <div class="card no-print">
            <div class="card-header">
                <h3>Report Filters</h3>

                <div class="report-header" style="display:flex; gap:10px; align-items:center;">
                    <button type="button" onclick="window.print()" class="btn btn-primary">
                        🖨 Print
                    </button>

                    <button type="button" onclick="downloadPDF()" class="btn btn-secondary">
                        ⬇ Download PDF
                    </button>
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
                <div class="report-title">
                    <h2 style="margin-bottom:8px;">STATEMENT OF ASSETS & LIABILITIES</h2>
                    <h3 style="margin-bottom:6px;"><?= e($company['company_name'] ?? 'Company') ?></h3>
                    <div class="report-period">Registration No: <?= e($company['registration_no'] ?? '-') ?></div>
                    <div class="report-period">As at: <?= e($to_date) ?></div>
                </div>

                <div class="grid grid-3 no-print" style="margin-bottom:24px;">
                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-title">Assets + Cash + Bank</div>
                            <div class="stat-icon">📦</div>
                        </div>
                        <div class="stat-value">Rs. <?= number_format($left_total, 2) ?></div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-title"><?= e($equity_label) ?> + Liabilities</div>
                            <div class="stat-icon">📉</div>
                        </div>
                        <div class="stat-value">Rs. <?= number_format($right_total, 2) ?></div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-title">Status</div>
                            <div class="stat-icon"><?= $is_balanced ? '✅' : '⚠️' ?></div>
                        </div>
                        <div class="stat-value"><?= $is_balanced ? 'Balanced' : 'Not Balanced' ?></div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="table report-table">
                        <thead>
                            <tr>
                                <th>Assets</th>
                                <th class="amount">Amount (Rs.)</th>
                                <th>Capital / Liabilities</th>
                                <th class="amount">Amount (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $left_items = [];
                            foreach ($asset_rows as $asset) {
                                $left_items[] = [
                                    'label' => $asset['name'] . ' (' . $asset['type'] . ')',
                                    'amount' => $asset['amount']
                                ];
                            }

                            $left_items[] = ['label' => 'Cash Balance', 'amount' => $cash_balance];
                            $left_items[] = ['label' => 'Bank Balance', 'amount' => $bank_balance];

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

                            $max_rows = max(count($left_items), count($right_items));

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

<script>
function downloadPDF() {
    if (typeof html2pdf === 'undefined') {
        alert('PDF library not loaded. Check: assets/js/html2pdf.bundle.min.js');
        return;
    }

    const element = document.getElementById('printArea');

    if (!element) {
        alert('Report area not found.');
        return;
    }

    document.body.classList.add('pdf-export-mode');

    const options = {
        margin: 0.25,
        filename: 'assets_liabilities_report.pdf',
        image: {
            type: 'jpeg',
            quality: 1
        },
        html2canvas: {
            scale: 2,
            useCORS: true,
            scrollX: 0,
            scrollY: 0,
            windowWidth: 1120
        },
        jsPDF: {
            unit: 'in',
            format: 'a4',
            orientation: 'landscape'
        },
        pagebreak: {
            mode: ['avoid-all', 'css', 'legacy']
        }
    };

    setTimeout(function() {
        html2pdf()
            .set(options)
            .from(element)
            .save()
            .then(function() {
                document.body.classList.remove('pdf-export-mode');
            })
            .catch(function() {
                document.body.classList.remove('pdf-export-mode');
                alert('PDF generation failed.');
            });
    }, 300);
}
</script>

<?php include 'includes/footer.php'; ?>