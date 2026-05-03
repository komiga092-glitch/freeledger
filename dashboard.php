<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

set_security_headers();

if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_id'] ?? 0) <= 0) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

$currentRole = normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));

if ($company_id <= 0) {
    if (in_array($currentRole, ['organization', 'accountant'])) {
        if ($currentRole === 'organization') {
            $stmt = $conn->prepare("SELECT company_id, company_name FROM companies WHERE owner_id = ? LIMIT 1");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $company_id = (int)$row['company_id'];
                $_SESSION['company_id'] = $company_id;
                $_SESSION['company_name'] = $row['company_name'];
            }
            $stmt->close();
        } elseif ($currentRole === 'accountant') {
            $stmt = $conn->prepare("SELECT c.company_id, c.company_name FROM companies c JOIN company_user_access cua ON c.company_id = cua.company_id WHERE cua.user_id = ? AND cua.role_in_company = 'accountant' AND cua.access_status = 'Active' LIMIT 1");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $company_id = (int)$row['company_id'];
                $_SESSION['company_id'] = $company_id;
                $_SESSION['company_name'] = $row['company_name'];
            }
            $stmt->close();
        }
    }
    if ($company_id <= 0) {
        header("Location: select_company.php");
        exit;
    }
}

$pageTitle = 'Dashboard';
$pageDescription = 'Professional overview of your selected company';

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
      AND (
            cua.role_in_company <> 'auditor'
            OR EXISTS (
                SELECT 1
                FROM auditor_invites ai
                WHERE ai.company_id = cua.company_id
                  AND ai.auditor_user_id = cua.user_id
                  AND ai.status = 'accepted'
            )
      )
    LIMIT 1
";

$stmt = $conn->prepare($accessSql);
$stmt->bind_param("ii", $user_id, $company_id);
$stmt->execute();
$res = $stmt->get_result();
$company = $res->fetch_assoc() ?: [];
$stmt->close();

if (empty($company)) {
    header("Location: select_company.php");
    exit;
}

$verifiedRole = normalize_role_value((string)$company['role_in_company']);

if ($verifiedRole === '') {
    header("Location: select_company.php");
    exit;
}

$_SESSION['company_name'] = (string)$company['company_name'];
$_SESSION['company_registration_no'] = (string)($company['registration_no'] ?? '');
$_SESSION['company_email'] = (string)($company['email'] ?? '');
$_SESSION['company_phone'] = (string)($company['phone'] ?? '');
$_SESSION['company_address'] = (string)($company['address'] ?? '');
$_SESSION['role'] = $verifiedRole;
$_SESSION['role_in_company'] = $verifiedRole;

$isOrganization = ($verifiedRole === 'organization');
$isAccountant = ($verifiedRole === 'accountant');
$isAuditor = ($verifiedRole === 'auditor');

function getSingleValue(mysqli $conn, string $sql, int $company_id): float
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $stmt->bind_result($value);
    $stmt->fetch();
    $stmt->close();

    return (float)($value ?? 0);
}

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
    "SELECT COALESCE(SUM(balance_amount),0) FROM liabilities WHERE company_id = ?",
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
$netWorthEstimate = $totalAssets - $totalLiabilities;

$auditReportsCount = 0;
$pendingReviewCount = 0;
$approvedReportsCount = 0;

if ($isAuditor) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total_reports,
            SUM(CASE WHEN review_status = 'Pending' THEN 1 ELSE 0 END) AS pending_reports,
            SUM(CASE WHEN review_status = 'Approved' THEN 1 ELSE 0 END) AS approved_reports
        FROM audit_final_reports
        WHERE company_id = ?
          AND auditor_user_id = ?
    ");
    $stmt->bind_param("ii", $company_id, $user_id);
} else {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total_reports,
            SUM(CASE WHEN review_status = 'Pending' THEN 1 ELSE 0 END) AS pending_reports,
            SUM(CASE WHEN review_status = 'Approved' THEN 1 ELSE 0 END) AS approved_reports
        FROM audit_final_reports
        WHERE company_id = ?
    ");
    $stmt->bind_param("i", $company_id);
}

$stmt->execute();
$auditStats = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$auditReportsCount = (int)($auditStats['total_reports'] ?? 0);
$pendingReviewCount = (int)($auditStats['pending_reports'] ?? 0);
$approvedReportsCount = (int)($auditStats['approved_reports'] ?? 0);

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

$stmt = $conn->prepare($sqlRecent);
$stmt->bind_param("ii", $company_id, $company_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $recentTransactions[] = $row;
}

$stmt->close();

include 'includes/header.php';
include 'includes/sidebar.php';

// Check for due notifications after login
check_and_create_due_notifications($conn, $user_id, $company_id);
?>

<div class="main-area">
    <div class="content-wrapper">

        <div class="page-header">
            <h1>Dashboard</h1>
            <p><?= e($pageDescription) ?></p>
        </div>

        <?php if ($isAuditor): ?>
        <div class="card mt-24">
            <div class="card-header">
                <h3>Auditor Quick Actions</h3>
                <span class="badge badge-primary">Auditor Panel</span>
            </div>

            <div class="card-body">
                <a href="audit_report_create.php" class="btn">Submit Audit Report</a>
                <a href="audit_reports.php" class="btn">View Reports</a>
                <a href="auditor_profile.php" class="btn">Auditor Profile</a>
                <a href="select_company.php" class="btn btn-light">Switch Company</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isOrganization): ?>
        <div class="card mt-24">
            <div class="card-header">
                <h3>Organization Quick Actions</h3>
                <span class="badge badge-primary">Admin Panel</span>
            </div>

            <div class="card-body">
                <a href="add_accountant.php" class="btn">Add Accountant</a>
                <a href="auditor_directory.php" class="btn">Find Auditor</a>
                <a href="audit_reports.php" class="btn">Review Audit Reports</a>
                <a href="select_company.php" class="btn btn-light">Switch Company</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- FINANCIAL HEALTH OVERVIEW -->
        <div class="card mt-24">
            <div class="card-header">
                <h3>Financial Health Overview</h3>
                <span class="badge badge-<?= $netWorthEstimate >= 0 ? 'success' : 'danger' ?>">
                    <?= $netWorthEstimate >= 0 ? 'Healthy' : 'Alert' ?>
                </span>
            </div>

            <div class="card-body">
                <div class="grid grid-3">
                    <div class="stat-net-worth">
                        <div class="stat-label">Net Worth</div>
                        <div class="stat-value <?= $netWorthEstimate >= 0 ? 'positive' : 'negative' ?>">Rs.
                            <?= number_format($netWorthEstimate, 2) ?></div>
                        <div class="stat-desc">Assets - Liabilities</div>
                    </div>

                    <div class="stat-profit-margin">
                        <div class="stat-label">Profit Margin</div>
                        <div class="stat-value neutral">
                            <?php
                            $margin = $totalIncome > 0 ? (($totalIncome - $totalExpense) / $totalIncome) * 100 : 0;
                            echo number_format($margin, 1) . '%';
                            ?>
                        </div>
                        <div class="stat-desc">Income - Expenses ratio</div>
                    </div>

                    <div class="stat-liability-ratio">
                        <div class="stat-label">Liability Ratio</div>
                        <div class="stat-value neutral">
                            <?php
                            $liabilityRatio = $totalAssets > 0 ? ($totalLiabilities / $totalAssets) * 100 : 0;
                            echo number_format($liabilityRatio, 1) . '%';
                            ?>
                        </div>
                        <div class="stat-desc">Debt to Assets</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-4 mt-24">
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
                <div class="stat-note">Cash In - Cash Out</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Bank Balance</div>
                    <div class="stat-icon">🏦</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($bankBalance, 2) ?></div>
                <div class="stat-note">Deposit - Withdrawal</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Income Position</div>
                    <div class="stat-icon">📊</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($netIncomePosition, 2) ?></div>
                <div class="stat-note">Income - Expenses</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Net Worth</div>
                    <div class="stat-icon">✅</div>
                </div>
                <div class="stat-value">Rs. <?= number_format($netWorthEstimate, 2) ?></div>
                <div class="stat-note">Assets - Liabilities</div>
            </div>
        </div>

        <div class="grid grid-3 mt-24">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title"><?= $isAuditor ? 'My Audit Reports' : 'Audit Reports' ?></div>
                    <div class="stat-icon">📝</div>
                </div>
                <div class="stat-value"><?= (int)$auditReportsCount ?></div>
                <div class="stat-note">Reports in this company</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Pending Reviews</div>
                    <div class="stat-icon">⏳</div>
                </div>
                <div class="stat-value"><?= (int)$pendingReviewCount ?></div>
                <div class="stat-note">Waiting for review</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-title">Approved Reports</div>
                    <div class="stat-icon">✅</div>
                </div>
                <div class="stat-value"><?= (int)$approvedReportsCount ?></div>
                <div class="stat-note">Reviewed and approved</div>
            </div>
        </div>

        <div class="grid grid-2 mt-24">
            <div class="card">
                <div class="card-header">
                    <h3>Company Information</h3>
                    <span class="badge badge-primary"><?= e(ucfirst($verifiedRole)) ?></span>
                </div>

                <div class="card-body">
                    <p><strong>Company Name:</strong> <?= e((string)($company['company_name'] ?? '-')) ?></p>
                    <p><strong>Registration No:</strong> <?= e((string)($company['registration_no'] ?? '-')) ?></p>
                    <p><strong>Email:</strong> <?= e((string)($company['email'] ?? '-')) ?></p>
                    <p><strong>Phone:</strong> <?= e((string)($company['phone'] ?? '-')) ?></p>
                    <p><strong>Address:</strong> <?= e((string)($company['address'] ?? '-')) ?></p>
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
                                    $type = (string)($row['transaction_type'] ?? '');
                                    $badgeClass = 'badge-primary';

                                    if ($type === 'Cash In' || $type === 'Deposit') {
                                        $badgeClass = 'badge-success';
                                    } elseif ($type === 'Cash Out' || $type === 'Withdrawal') {
                                        $badgeClass = 'badge-danger';
                                    }
                                    ?>

                            <tr>
                                <td><?= e((string)($row['txn_date'] ?? '')) ?></td>
                                <td><?= e((string)($row['source_name'] ?? '')) ?></td>
                                <td><?= e((string)($row['description'] ?? '')) ?></td>
                                <td>
                                    <span class="badge <?= e($badgeClass) ?>">
                                        <?= e($type) ?>
                                    </span>
                                </td>
                                <td>Rs. <?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
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