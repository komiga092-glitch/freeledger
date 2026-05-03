<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

/* ---------- ACTIVE MENU ---------- */
if (!function_exists('isActive')) {
    function isActive(array $pages): string
    {
        $current = basename($_SERVER['PHP_SELF'] ?? '');
        return in_array($current, $pages, true) ? 'active' : '';
    }
}

/* ---------- SAFE BASE URL ---------- */
$base_url = $base_url ?? './';

/* ---------- ROLE DETECTION ---------- */
$user_id    = (int)($_SESSION['user_id'] ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);

$role = '';

if ($user_id > 0 && $company_id > 0 && function_exists('verify_user_role')) {
    $verifiedRole = verify_user_role($user_id, $company_id);
    if (is_string($verifiedRole) && trim($verifiedRole) !== '') {
        $role = $verifiedRole;
    }
}

if ($role === '') {
    $role = (string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? '');
}

$role = function_exists('normalize_role_value')
    ? normalize_role_value($role)
    : strtolower(trim($role));

/* ---------- ROLE FLAGS ---------- */
$isOrganization = $role === 'organization';
$isAccountant   = $role === 'accountant';
$isAuditor      = $role === 'auditor';

/*
|--------------------------------------------------------------------------
| Permission Rules
|--------------------------------------------------------------------------
| Organization : manage users + view reports + view transactions (VIEW ONLY)
| Accountant   : add/edit/delete accounting data + reports (FULL CRUD)
| Auditor      : view company data + reports + audit tools only (VIEW ONLY)
|--------------------------------------------------------------------------
*/
$canManageUsers  = $isOrganization;
$canViewCompany  = in_array($role, ['organization', 'accountant', 'auditor'], true);
$canCrud         = $isAccountant; // Only accountant can CRUD
$canReports      = $canViewCompany;
$canAudit        = $isAuditor;

/* ---------- DISPLAY INFO ---------- */
$companyName = (string)($_SESSION['company_name'] ?? 'No Company Selected');
$displayRole = $role !== '' ? ucfirst($role) : 'User';
?>

<aside class="sidebar" id="sidebar">

    <!-- BRAND -->
    <div class="sidebar-brand">
        <div class="brand-icon">📊</div>
        <h2>FreeLedger</h2>
        <small>Finance & Audit</small>
    </div>

    <nav class="sidebar-menu">

        <!-- MAIN -->
        <div class="menu-label">Main</div>

        <a href="<?= e($base_url) ?>dashboard.php" class="<?= isActive(['dashboard.php']) ?>">
            🏠 Dashboard
        </a>

        <?php if ($isAuditor): ?>
        <a href="<?= e($base_url) ?>select_company.php" class="<?= isActive(['select_company.php']) ?>">
            🏢 Select Company
        </a>
        <?php endif; ?>

        <!-- USER MANAGEMENT -->
        <?php if ($canManageUsers): ?>
        <div class="menu-label">User Management</div>

        <a href="<?= e($base_url) ?>add_accountant.php" class="<?= isActive(['add_accountant.php']) ?>">
            👤 Add Accountant
        </a>

        <a href="<?= e($base_url) ?>auditor_directory.php" class="<?= isActive(['auditor_directory.php']) ?>">
            🧑‍⚖️ Find Auditor
        </a>

        <a href="<?= e($base_url) ?>auditor_messages.php" class="<?= isActive(['auditor_messages.php']) ?>">
            💬 Auditor Messages
        </a>

        <a href="<?= e($base_url) ?>auditor_feedback.php" class="<?= isActive(['auditor_feedback.php']) ?>">
            ⭐ Auditor Feedback
        </a>
        <?php endif; ?>

        <!-- TRANSACTIONS -->
        <?php if ($canViewCompany): ?>
        <div class="menu-label">Transactions</div>

        <a href="<?= e($base_url) ?>income.php"
            class="<?= isActive(['income.php', 'income_add.php', 'income_edit.php']) ?>">
            💰 Income
        </a>

        <a href="<?= e($base_url) ?>expenses.php"
            class="<?= isActive(['expenses.php', 'expense_add.php', 'expense_edit.php']) ?>">
            💸 Expenses
        </a>

        <a href="<?= e($base_url) ?>cash_account.php"
            class="<?= isActive(['cash_account.php', 'cash_add.php', 'cash_edit.php']) ?>">
            💵 Cash Account
        </a>

        <a href="<?= e($base_url) ?>bank_account.php"
            class="<?= isActive(['bank_account.php', 'bank_add.php', 'bank_edit.php']) ?>">
            🏦 Bank Account
        </a>

        <!-- STATEMENTS -->
        <div class="menu-label">Statements</div>
        <a href="<?= e($base_url) ?>assets.php"
            class="<?= isActive(['assets.php', 'asset_add.php', 'asset_edit.php']) ?>">
            📦 Assets
        </a>

        <a href="<?= e($base_url) ?>liabilities.php"
            class="<?= isActive(['liabilities.php', 'liability_payment.php', 'liability_add.php', 'liability_edit.php']) ?>">
            📉 Liabilities
        </a>

        <a href="<?= e($base_url) ?>receivables.php"
            class="<?= isActive(['receivables.php', 'receivable_collection.php']) ?>">
            📈 Receivables
        </a>

        <!-- REPORTS -->
        <div class="menu-label">Reports</div>
        <a href="<?= e($base_url) ?>income_expenditure_report.php"
            class="<?= isActive(['income_expenditure_report.php']) ?>">
            📄 Income & Expenditure
        </a>

        <a href="<?= e($base_url) ?>assets_liabilities_report.php"
            class="<?= isActive(['assets_liabilities_report.php']) ?>">
            📑 Balance Sheet
        </a>

        <a href="<?= e($base_url) ?>audit_reports.php"
            class="<?= isActive(['audit_reports.php', 'audit_report_review.php', 'audit_report_create.php']) ?>">
            📝 Audit Reports
        </a>
        <?php endif; ?>

        <!-- HR -->
        <?php if (!$isAuditor && $canViewCompany): ?>
        <div class="menu-label">HR</div>

        <a href="<?= e($base_url) ?>employees.php"
            class="<?= isActive(['employees.php', 'employee_add.php', 'employee_edit.php']) ?>">
            👥 Employees
        </a>

        <a href="<?= e($base_url) ?>employee_advances.php" class="<?= isActive(['employee_advances.php']) ?>">
            💳 Employee Advances
        </a>

        <a href="<?= e($base_url) ?>salaries.php"
            class="<?= isActive(['salaries.php', 'salary_add.php', 'salary_edit.php']) ?>">
            🧾 Salaries
        </a>
        <?php endif; ?>

        <!-- AUDITOR TOOLS -->
        <?php if ($canAudit): ?>
        <div class="menu-label">Auditor Tools</div>

        <a href="<?= e($base_url) ?>auditor_profile.php" class="<?= isActive(['auditor_profile.php']) ?>">
            🧑‍💼 Auditor Profile
        </a>

        <a href="<?= e($base_url) ?>audit_report_create.php" class="<?= isActive(['audit_report_create.php']) ?>">
            📤 Submit Audit Report
        </a>

        <a href="<?= e($base_url) ?>auditor_messages.php" class="<?= isActive(['auditor_messages.php']) ?>">
            💬 Messages
        </a>

        <a href="<?= e($base_url) ?>auditor_feedback.php" class="<?= isActive(['auditor_feedback.php']) ?>">
            ⭐ Feedback
        </a>
        <?php endif; ?>

        <!-- ACCOUNT -->
        <div class="menu-label">Account</div>



        <a href="<?= e($base_url) ?>change_password.php" class="<?= isActive(['change_password.php']) ?>">
            🔐 Password
        </a>

        <a href="<?= e($base_url) ?>logout.php">
            🚪 Logout
        </a>

    </nav>

    <!-- FOOTER -->
    <div class="sidebar-footer">
        <strong><?= e($companyName) ?></strong>
        <span><?= e($displayRole) ?></span>
    </div>

</aside>