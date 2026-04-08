<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_id']) <= 0) {
    header("Location: login.php");
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);

if ($company_id <= 0) {
    header("Location: login.php");
    exit;
}

$pageTitle = 'Dashboard';
$pageDescription = 'Professional overview of your selected company';

$accessOk = false;
$company = [];
$verifiedRole = '';

$accessSql = "
    SELECT 
        c.company_id,
        c.company_name,
        c.registration_no,
        c.address,
        c.phone,
        c.email,
        cua.role_in_company,
        cua.access_status
    FROM company_user_access cua
    INNER JOIN companies c ON c.company_id = cua.company_id
    WHERE cua.user_id = ?
      AND cua.company_id = ?
      AND cua.access_status = 'Active'
    LIMIT 1
";

if ($stmt = $conn->prepare($accessSql)) {
    $stmt->bind_param("ii", $user_id, $company_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $company = $res->fetch_assoc() ?: [];
    $stmt->close();

    if (!empty($company)) {
        $accessOk = true;
        $verifiedRole = normalize_role_value((string)($company['role_in_company'] ?? ''));

        $_SESSION['company_name'] = (string)($company['company_name'] ?? '');
        $_SESSION['company_registration_no'] = (string)($company['registration_no'] ?? '');
        $_SESSION['company_email'] = (string)($company['email'] ?? '');
        $_SESSION['company_phone'] = (string)($company['phone'] ?? '');
        $_SESSION['company_address'] = (string)($company['address'] ?? '');
        $_SESSION['role'] = $verifiedRole;
        $_SESSION['role_in_company'] = $verifiedRole;
    }
}

if (!$accessOk || $verifiedRole === '') {
    // Clear stale or invalid session state before redirecting to prevent redirect loops.
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    header("Location: login.php");
    exit;
}

function getSingleValue(mysqli $conn, string $sql, int $bindValue): float
{
    $value = 0.0;

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $bindValue);
        $stmt->execute();
        $stmt->bind_result($result);
        $stmt->fetch();
        $value = (float)($result ?? 0);
        $stmt->close();
    }

    return $value;
}

/* =========================
   COMMON METRICS
========================= */
$totalIncome = getSingleValue(
    $conn,
    "SELECT COALESCE(SUM(amount),0) FROM income WHERE company_id = ?",
    $company_id
);

$totalExpense = getSingleValue(
    $conn,
    "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE company_id = ?",
    $company_id
);

$totalAssets = getSingleValue(
    $conn,
    "SELECT COALESCE(SUM(
        CASE 
            WHEN current_value IS NOT NULL AND current_value > 0 THEN current_value
            WHEN cost_value IS NOT NULL AND cost_value > 0 THEN cost_value
            ELSE asset_value
        END
    ),0) FROM assets WHERE company_id = ?",
    $company_id
);

$totalLiabilities = getSingleValue(
    $conn,
    "SELECT COALESCE(SUM(amount),0) FROM liabilities WHERE company_id = ?",
    $company_id
);

$totalCashIn = getSingleValue(
    $conn,
    "SELECT COALESCE(SUM(amount),0) FROM cash_account WHERE company_id = ? AND transaction_type = 'Cash In'",
    $company_id
);

$totalCashOut = getSingleValue(
    $conn,
    "SELECT COALESCE(SUM(amount),0) FROM cash_account WHERE company_id = ? AND transaction_type = 'Cash Out'",
    $company_id
);

$totalBankDeposit = getSingleValue(
    $conn,
    "SELECT COALESCE(SUM(amount),0) FROM bank_account WHERE company_id = ? AND transaction_type = 'Deposit'",
    $company_id
);

$totalBankWithdrawal = getSingleValue(
    $conn,
    "SELECT COALESCE(SUM(amount),0) FROM bank_account WHERE company_id = ? AND transaction_type = 'Withdrawal'",
    $company_id
);

$cashBalance = $totalCashIn - $totalCashOut;
$bankBalance = $totalBankDeposit - $totalBankWithdrawal;
$netIncomePosition = $totalIncome - $totalExpense;
$netWorthEstimate = ($totalAssets + $cashBalance + $bankBalance) - $totalLiabilities;

/* =========================
   RECENT TRANSACTIONS
========================= */
$recentTransactions = [];

$sqlRecent = "
    SELECT 'Cash' AS source_name, transaction_date AS txn_date, description, transaction_type, amount
    FROM cash_account
    WHERE company_id = ?
    UNION ALL
    SELECT 'Bank' AS source_name, transaction_date AS txn_date, description, transaction_type, amount
    FROM bank_account
    WHERE company_id = ?
    ORDER BY txn_date DESC
    LIMIT 10
";

if ($stmt = $conn->prepare($sqlRecent)) {
    $stmt->bind_param("ii", $company_id, $company_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $recentTransactions[] = $row;
    }

    $stmt->close();
}

/* =========================
   AUDITOR METRICS
========================= */
$assignedCompaniesCount = 0;
$assignmentCount = 0;
$auditReportCount = 0;
$auditNoteCount = 0;

if ($verifiedRole === 'auditor') {
    $assignedCompaniesCount = getSingleValue(
        $conn,
        "SELECT COUNT(*) FROM company_user_access WHERE user_id = ? AND access_status = 'Active'",
        $user_id
    );

    if ($stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM audit_assignments
        WHERE auditor_id = ? AND company_id = ?
    ")) {
        $stmt->bind_param("ii", $user_id, $company_id);
        $stmt->execute();
        $stmt->bind_result($assignmentCountResult);
        $stmt->fetch();
        $assignmentCount = (int)($assignmentCountResult ?? 0);
        $stmt->close();
    }

    if ($stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM audit_reports ar
        INNER JOIN audit_assignments aa ON aa.assignment_id = ar.assignment_id
        WHERE aa.auditor_id = ? AND aa.company_id = ?
    ")) {
        $stmt->bind_param("ii", $user_id, $company_id);
        $stmt->execute();
        $stmt->bind_result($reportCountResult);
        $stmt->fetch();
        $auditReportCount = (int)($reportCountResult ?? 0);
        $stmt->close();
    }

    $checkTable = $conn->query("SHOW TABLES LIKE 'audit_notes'");
    if ($checkTable && $checkTable->num_rows > 0) {
        if ($stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM audit_notes
            WHERE company_id = ? AND auditor_id = ?
        ")) {
            $stmt->bind_param("ii", $company_id, $user_id);
            $stmt->execute();
            $stmt->bind_result($noteCountResult);
            $stmt->fetch();
            $auditNoteCount = (int)($noteCountResult ?? 0);
            $stmt->close();
        }
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="page-heading">
                <h1>Dashboard</h1>
                <p><?= e($pageDescription) ?></p>
            </div>
        </div>

        <div class="topbar-right">
            <div class="company-pill"><?= e($_SESSION['company_name'] ?? 'Company') ?></div>
            <div class="role-pill"><?= e(ucfirst($_SESSION['role'] ?? 'user')) ?></div>
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

        <?php if ($verifiedRole === 'auditor'): ?>
        <div class="grid grid-4">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Assigned Companies</div>
                    <div class="stat-icon">🏢</div>
                </div>
                <div class="stat-value"><?= number_format($assignedCompaniesCount) ?></div>
                <div class="stat-note">Companies linked to this auditor</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Audit Assignments</div>
                    <div class="stat-icon">📋</div>
                </div>
                <div class="stat-value"><?= number_format($assignmentCount) ?></div>
                <div class="stat-note">Assignments for this company</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Audit Reports</div>
                    <div class="stat-icon">✅</div>
                </div>
                <div class="stat-value"><?= number_format($auditReportCount) ?></div>
                <div class="stat-note">Submitted reports for this company</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Audit Notes</div>
                    <div class="stat-icon">📝</div>
                </div>
                <div class="stat-value"><?= number_format($auditNoteCount) ?></div>
                <div class="stat-note">Recorded notes for this company</div>
            </div>
        </div>

        <div class="grid grid-2 mt-24">
            <div class="card">
                <div class="card-header">
                    <h3>Company Information</h3>
                    <span class="badge badge-primary"><?= e(ucfirst($verifiedRole)) ?></span>
                </div>
                <div class="card-body">
                    <p><strong>Company Name:</strong> <?= e($company['company_name'] ?? '-') ?></p>
                    <p><strong>Registration No:</strong> <?= e($company['registration_no'] ?? '-') ?></p>
                    <p><strong>Email:</strong> <?= e($company['email'] ?? '-') ?></p>
                    <p><strong>Phone:</strong> <?= e($company['phone'] ?? '-') ?></p>
                    <p><strong>Address:</strong> <?= e($company['address'] ?? '-') ?></p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Audit Access Summary</h3>
                    <span class="badge badge-success">Auditor View</span>
                </div>
                <div class="card-body">
                    <p><strong>Access Role:</strong> <?= e(ucfirst($verifiedRole)) ?></p>
                    <p><strong>Company Access:</strong> Active</p>
                    <p><strong>Available Actions:</strong> Review reports, add audit notes, submit audit reports</p>
                    <p><strong>Restriction:</strong> Transaction editing is not allowed for auditors</p>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="grid grid-4">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Total Income</div>
                    <div class="stat-icon">💰</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($totalIncome, 2) ?></div>
                <div class="stat-note">Company income total</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Total Expenses</div>
                    <div class="stat-icon">💸</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($totalExpense, 2) ?></div>
                <div class="stat-note">Company expense total</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Total Assets</div>
                    <div class="stat-icon">📦</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($totalAssets, 2) ?></div>
                <div class="stat-note">Current asset valuation</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Liabilities</div>
                    <div class="stat-icon">📉</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($totalLiabilities, 2) ?></div>
                <div class="stat-note">Outstanding liabilities</div>
            </div>
        </div>

        <div class="grid grid-4 mt-24">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Cash Balance</div>
                    <div class="stat-icon">💵</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($cashBalance, 2) ?></div>
                <div class="stat-note">Cash In minus Cash Out</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Bank Balance</div>
                    <div class="stat-icon">🏦</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($bankBalance, 2) ?></div>
                <div class="stat-note">Deposit minus Withdrawal</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Income Position</div>
                    <div class="stat-icon">📊</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($netIncomePosition, 2) ?></div>
                <div class="stat-note">Income minus Expenses</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Net Worth</div>
                    <div class="stat-icon">✅</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($netWorthEstimate, 2) ?></div>
                <div class="stat-note">Assets + Cash + Bank - Liabilities</div>
            </div>
        </div>

        <div class="grid grid-2 mt-24">
            <div class="card">
                <div class="card-header">
                    <h3>Company Information</h3>
                    <span class="badge badge-primary"><?= e(ucfirst($verifiedRole)) ?></span>
                </div>
                <div class="card-body">
                    <p><strong>Company Name:</strong> <?= e($company['company_name'] ?? '-') ?></p>
                    <p><strong>Registration No:</strong> <?= e($company['registration_no'] ?? '-') ?></p>
                    <p><strong>Email:</strong> <?= e($company['email'] ?? '-') ?></p>
                    <p><strong>Phone:</strong> <?= e($company['phone'] ?? '-') ?></p>
                    <p><strong>Address:</strong> <?= e($company['address'] ?? '-') ?></p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Financial Summary</h3>
                    <span class="badge badge-success">Overview</span>
                </div>
                <div class="card-body">
                    <p><strong>Cash Balance:</strong> Rs. <?= number_format($cashBalance, 2) ?></p>
                    <p><strong>Bank Balance:</strong> Rs. <?= number_format($bankBalance, 2) ?></p>
                    <p><strong>Net Income Position:</strong> Rs. <?= number_format($netIncomePosition, 2) ?></p>
                    <p><strong>Estimated Net Worth:</strong> Rs. <?= number_format($netWorthEstimate, 2) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Recent Cash / Bank Transactions</h3>
                <span class="badge badge-success"><?= count($recentTransactions) ?> Records</span>
            </div>

            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Source</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentTransactions)): ?>
                            <?php foreach ($recentTransactions as $row): ?>
                            <?php
                                    $type = $row['transaction_type'] ?? '';
                                    $badgeClass = 'badge-primary';

                                    if ($type === 'Cash In' || $type === 'Deposit') {
                                        $badgeClass = 'badge-success';
                                    } elseif ($type === 'Cash Out' || $type === 'Withdrawal') {
                                        $badgeClass = 'badge-danger';
                                    }
                                    ?>
                            <tr>
                                <td><?= e($row['txn_date']) ?></td>
                                <td><?= e($row['source_name']) ?></td>
                                <td><?= e($row['description']) ?></td>
                                <td><span class="badge <?= e($badgeClass) ?>"><?= e($type) ?></span></td>
                                <td>Rs. <?= number_format((float)$row['amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="5">No recent transactions found for this company.</td>
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