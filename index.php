<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>FreeLedger | Accounting & Audit Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        font-family: Arial, sans-serif;
    }

    body {
        background: #f8f4f5;
        color: #222;
    }

    .hero {
        min-height: 100vh;
        background: linear-gradient(135deg, #5b0f1a, #8b1e2d);
        color: white;
        padding: 40px 8%;
    }

    .navbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 80px;
    }

    .logo {
        font-size: 26px;
        font-weight: bold;
    }

    .nav-buttons a {
        text-decoration: none;
        color: white;
        border: 1px solid white;
        padding: 10px 18px;
        border-radius: 8px;
        margin-left: 10px;
        font-weight: bold;
    }

    .nav-buttons a.primary {
        background: white;
        color: #7b1525;
    }

    .hero-content {
        max-width: 800px;
    }

    .hero-content h1 {
        font-size: 48px;
        line-height: 1.2;
        margin-bottom: 20px;
    }

    .hero-content p {
        font-size: 18px;
        line-height: 1.7;
        margin-bottom: 30px;
        color: #f5dfe3;
    }

    .cta-buttons a {
        display: inline-block;
        text-decoration: none;
        padding: 14px 24px;
        border-radius: 10px;
        margin-right: 12px;
        font-weight: bold;
    }

    .cta-main {
        background: white;
        color: #7b1525;
    }

    .cta-secondary {
        border: 1px solid white;
        color: white;
    }

    .section {
        padding: 70px 8%;
    }

    .section h2 {
        text-align: center;
        font-size: 34px;
        color: #5b0f1a;
        margin-bottom: 15px;
    }

    .section-desc {
        text-align: center;
        max-width: 750px;
        margin: 0 auto 40px;
        line-height: 1.7;
        color: #555;
    }

    .grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
    }

    .card {
        background: white;
        padding: 28px;
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        border-top: 5px solid #7b1525;
    }

    .card h3 {
        color: #7b1525;
        margin-bottom: 12px;
        font-size: 22px;
    }

    .card p {
        color: #555;
        line-height: 1.6;
    }

    .roles {
        background: white;
    }

    .final-cta {
        background: #5b0f1a;
        color: white;
        text-align: center;
        padding: 70px 8%;
    }

    .final-cta h2 {
        font-size: 34px;
        margin-bottom: 18px;
    }

    .final-cta p {
        color: #f3d8dc;
        margin-bottom: 30px;
        line-height: 1.7;
    }

    .footer {
        background: #26070b;
        color: #ccc;
        text-align: center;
        padding: 18px;
        font-size: 14px;
    }

    @media (max-width: 900px) {
        .grid {
            grid-template-columns: 1fr;
        }

        .hero-content h1 {
            font-size: 34px;
        }

        .navbar {
            flex-direction: column;
            gap: 20px;
        }

        .nav-buttons a {
            display: inline-block;
            margin-bottom: 8px;
        }
    }
    </style>
</head>

<body>

    <section class="hero">
        <div class="navbar">
            <div class="logo">📊 FreeLedger</div>

            <div class="nav-buttons">
                <a href="register.php">Company Register</a>
                <a href="auditor_register.php">Auditor Register</a>
                <a href="login.php" class="primary">Login</a>
            </div>
        </div>

        <div class="hero-content">
            <h1>Professional Accounting & Audit Management System</h1>

            <p>
                FreeLedger helps organizations manage income, expenses, assets,
                liabilities, payroll, cash, bank transactions, and audit reports in one
                secure role-based platform.
            </p>

            <div class="cta-buttons">
                <a href="register.php" class="cta-main">Register Company</a>
                <a href="auditor_register.php" class="cta-secondary">Register as Auditor</a>
                <a href="login.php" class="cta-secondary">Login</a>
            </div>
        </div>
    </section>

    <section class="section">
        <h2>Accounting Features</h2>
        <p class="section-desc">
            Manage financial records professionally with structured transaction modules
            and clear financial summaries.
        </p>

        <div class="grid">
            <div class="card">
                <h3>Income & Expenses</h3>
                <p>Record income and expenses with proper company-based tracking and transaction history.</p>
            </div>

            <div class="card">
                <h3>Assets & Liabilities</h3>
                <p>Maintain asset values and outstanding liabilities for financial statement preparation.</p>
            </div>

            <div class="card">
                <h3>Cash & Bank</h3>
                <p>Track cash in, cash out, deposits, and withdrawals with clear balances.</p>
            </div>
        </div>
    </section>

    <section class="section roles">
        <h2>Audit Management</h2>
        <p class="section-desc">
            Auditors can be assigned to companies, view financial data, submit final
            audit reports, and receive review comments from the organization.
        </p>

        <div class="grid">
            <div class="card">
                <h3>Auditor Profile</h3>
                <p>Auditors can maintain NIC, qualification, degree, experience, and profile photo.</p>
            </div>

            <div class="card">
                <h3>Audit Reports</h3>
                <p>Auditors can submit audit summaries, recommendations, opinions, and signed PDF reports.</p>
            </div>

            <div class="card">
                <h3>Approval System</h3>
                <p>Organizations can approve, reject, or request changes for submitted audit reports.</p>
            </div>
        </div>
    </section>

    <section class="section">
        <h2>User Roles</h2>
        <p class="section-desc">
            FreeLedger uses role-based access control to protect financial data and
            separate responsibilities.
        </p>

        <div class="grid">
            <div class="card">
                <h3>Organization</h3>
                <p>Registers company, manages users, assigns auditors, views reports, and reviews audit submissions.</p>
            </div>

            <div class="card">
                <h3>Accountant</h3>
                <p>Handles income, expenses, assets, liabilities, payroll, cash, and bank transactions.</p>
            </div>

            <div class="card">
                <h3>Auditor</h3>
                <p>Views assigned company records and submits final audit reports with professional opinions.</p>
            </div>
        </div>
    </section>

    <section class="final-cta">
        <h2>Start Managing Accounting & Audit Work Professionally</h2>
        <p>
            Register your company, invite auditors, manage financial records, and review
            audit reports from one secure system.
        </p>

        <div class="cta-buttons">
            <a href="register.php" class="cta-main">Company Register</a>
            <a href="auditor_register.php" class="cta-secondary">Auditor Register</a>
            <a href="login.php" class="cta-secondary">Login</a>
        </div>
    </section>

    <footer class="footer">
        © <?= date('Y') ?> FreeLedger. Accounting & Audit Management System.
    </footer>

</body>

</html>