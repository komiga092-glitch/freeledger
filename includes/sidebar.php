<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

if (!function_exists('isActive')) {
    function isActive(array $fileNames): string
    {
        $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
        return in_array($currentPage, $fileNames, true) ? 'active' : '';
    }
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);
$verified_role = '';

if ($user_id > 0 && $company_id > 0 && function_exists('verify_user_role')) {
    $verified_role = (string) verify_user_role($user_id, $company_id);
}

if ($verified_role === '') {
    $verified_role = normalize_role_value((string)($_SESSION['role'] ?? $_SESSION['role_in_company'] ?? ''));
}

$verified_role = normalize_role_value($verified_role);

$isOrganization = ($verified_role === 'organization');
$isAuditor      = ($verified_role === 'auditor');
$isAccountant   = ($verified_role === 'accountant');

$base_url = $base_url ?? '/';

/*
|--------------------------------------------------------------------------
| Permission Model
|--------------------------------------------------------------------------
| organization -> view transactions only
| auditor      -> view transactions only + audit menus
| accountant   -> full transaction work
*/
$canManageUsers       = $isOrganization;
$canViewTransactions  = in_array($verified_role, ['organization', 'auditor', 'accountant'], true);
$canCrudTransactions  = $isAccountant;
$canViewReports       = in_array($verified_role, ['organization', 'auditor', 'accountant'], true);
$canAudit             = $isAuditor;
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-badge">📊</div>
        <h2>FreeLedger</h2>
        <p>Professional Finance Suite</p>
    </div>

    <div class="sidebar-menu">
        <div class="menu-label">Main</div>

        <a href="<?= e($base_url) ?>dashboard.php" class="<?= e(isActive(['dashboard.php'])) ?>">
            <span class="icon">🏠</span>
            <span>Dashboard</span>
        </a>

        <?php if ($canManageUsers): ?>
        <div class="menu-label">User Management</div>

        <a href="add_accountant.php" class="<?= e(isActive(['add_accountant.php'])) ?>">
            <span class="icon">👤</span>
            <span>Add Accountant</span>
        </a>

        <a href="add_auditor.php" class="<?= e(isActive(['add_auditor.php'])) ?>">
            <span class="icon">🔍</span>
            <span>Add Auditor</span>
        </a>
        <?php endif; ?>

        <?php if ($canViewTransactions): ?>
        <div class="menu-label">Transactions</div>

        <a href="<?= e($base_url) ?>income.php" class="<?= e(isActive(['income.php'])) ?>">
            <span class="icon">💰</span>
            <span>Income</span>
        </a>

        <a href="<?= e($base_url) ?>expenses.php" class="<?= e(isActive(['expenses.php'])) ?>">
            <span class="icon">💸</span>
            <span>Expenses</span>
        </a>

        <a href="<?= e($base_url) ?>cash_account.php" class="<?= e(isActive(['cash_account.php'])) ?>">
            <span class="icon">💵</span>
            <span>Cash Account</span>
        </a>

        <a href="<?= e($base_url) ?>bank_account.php" class="<?= e(isActive(['bank_account.php'])) ?>">
            <span class="icon">🏦</span>
            <span>Bank Account</span>
        </a>

        <div class="menu-label">Statements</div>

        <a href="<?= e($base_url) ?>assets.php" class="<?= e(isActive(['assets.php'])) ?>">
            <span class="icon">📦</span>
            <span>Assets</span>
        </a>

        <a href="<?= e($base_url) ?>liabilities.php" class="<?= e(isActive(['liabilities.php'])) ?>">
            <span class="icon">📉</span>
            <span>Liabilities</span>
        </a>

        <div class="menu-label">HR & Payroll</div>

        <a href="<?= e($base_url) ?>employees.php" class="<?= e(isActive(['employees.php'])) ?>">
            <span class="icon">👥</span>
            <span>Employees</span>
        </a>

        <a href="<?= e($base_url) ?>salaries.php" class="<?= e(isActive(['salaries.php'])) ?>">
            <span class="icon">🧾</span>
            <span>Salaries</span>
        </a>
        <?php endif; ?>


        <?php if ($canViewReports): ?>
        <div class="menu-label">Reports</div>

        <a href="<?= e($base_url) ?>income_expenditure_report.php"
            class="<?= e(isActive(['income_expenditure_report.php'])) ?>">
            <span class="icon">📄</span>
            <span>Income & Expenditure</span>
        </a>

        <a href="<?= e($base_url) ?>assets_liabilities_report.php"
            class="<?= e(isActive(['assets_liabilities_report.php'])) ?>">
            <span class="icon">📑</span>
            <span>Assets & Liabilities</span>
        </a>
        <?php endif; ?>


        <?php if ($canAudit): ?>
        <div class="menu-label">Audit Management</div>

        <a href="<?= e($base_url) ?>auditor/audit_notes.php" class="<?= e(isActive(['audit_notes.php'])) ?>">
            <span class="icon">📝</span>
            <span>Audit Notes</span>
        </a>

        <a href="<?= e($base_url) ?>auditor/audit_reports.php" class="<?= e(isActive(['audit_reports.php'])) ?>">
            <span class="icon">✅</span>
            <span>Audit Reports</span>
        </a>
        <?php endif; ?>


        <div class="menu-label">Account</div>

        <a href="<?= e($base_url) ?>profile.php" class="<?= e(isActive(['profile.php'])) ?>">
            <span class="icon">🙍</span>
            <span>My Profile</span>
        </a>

        <a href="<?= e($base_url) ?>change_password.php" class="<?= e(isActive(['change_password.php'])) ?>">
            <span class="icon">🔐</span>
            <span>Change Password</span>
        </a>

        <a href="<?= e($base_url) ?>logout.php" class="<?= e(isActive(['logout.php'])) ?>">
            <span class="icon">🚪</span>
            <span>Logout</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <div><strong><?= e($_SESSION['company_name'] ?? 'No Company') ?></strong></div>
        <div><?= e($verified_role !== '' ? ucfirst($verified_role) : 'User') ?></div>
    </div>
</aside>