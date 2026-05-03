<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_raw = (string)($_POST['password'] ?? '');
    $nic = trim($_POST['nic'] ?? '');
    $degree = trim($_POST['degree'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');

    if ($full_name === '' || $email === '' || $password_raw === '' || $nic === '' || $qualification === '') {
        $error = "Please fill all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password_raw) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $check = $conn->prepare("SELECT user_id FROM app_users WHERE email = ? LIMIT 1");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "This email is already registered. Please login instead.";
        } else {
            $password = password_hash($password_raw, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO app_users 
                    (full_name, email, password, status, auditor_type) 
                VALUES 
                    (?, ?, ?, 'active', 'external')
            ");
            $stmt->bind_param("sss", $full_name, $email, $password);
            $stmt->execute();

            $user_id = $stmt->insert_id;

            $stmt = $conn->prepare("
                INSERT INTO auditor_profiles 
                    (user_id, nic, degree, qualification) 
                VALUES 
                    (?, ?, ?, ?)
            ");
            $stmt->bind_param("isss", $user_id, $nic, $degree, $qualification);
            $stmt->execute();

            $_SESSION['user_id'] = $user_id;
            $_SESSION['role'] = 'auditor';

            header("Location: auditor_profile.php");
            exit;
        }

        $check->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Auditor Register | FreeLedger</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        font-family: Arial, sans-serif;
    }

    body {
        min-height: 100vh;
        background: linear-gradient(135deg, #5b0f1a, #8b1e2d);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }

    .register-wrapper {
        width: 100%;
        max-width: 1050px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        background: #fff;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25);
    }

    .info-panel {
        background: linear-gradient(160deg, #4a0b14, #7b1525);
        color: #fff;
        padding: 50px;
    }

    .info-panel h1 {
        font-size: 36px;
        margin-bottom: 18px;
    }

    .info-panel p {
        color: #f4d8dd;
        line-height: 1.7;
        margin-bottom: 25px;
    }

    .feature {
        margin-bottom: 16px;
        background: rgba(255, 255, 255, 0.1);
        padding: 14px;
        border-radius: 12px;
    }

    .form-panel {
        padding: 45px;
    }

    .form-panel h2 {
        color: #5b0f1a;
        margin-bottom: 8px;
        font-size: 30px;
    }

    .form-panel .subtitle {
        color: #666;
        margin-bottom: 28px;
    }

    .form-group {
        margin-bottom: 16px;
    }

    label {
        display: block;
        font-weight: bold;
        color: #333;
        margin-bottom: 7px;
        font-size: 14px;
    }

    input {
        width: 100%;
        padding: 13px 14px;
        border: 1px solid #ddd;
        border-radius: 10px;
        font-size: 15px;
        outline: none;
    }

    input:focus {
        border-color: #7b1525;
        box-shadow: 0 0 0 3px rgba(123, 21, 37, 0.12);
    }

    .btn {
        width: 100%;
        border: none;
        background: #7b1525;
        color: #fff;
        padding: 14px;
        border-radius: 10px;
        font-size: 16px;
        font-weight: bold;
        cursor: pointer;
        margin-top: 8px;
    }

    .btn:hover {
        background: #5b0f1a;
    }

    .alert {
        padding: 12px 14px;
        border-radius: 10px;
        margin-bottom: 18px;
        font-size: 14px;
    }

    .alert-error {
        background: #fde8e8;
        color: #9b1c1c;
        border: 1px solid #f5b5b5;
    }

    .links {
        text-align: center;
        margin-top: 20px;
        color: #666;
        font-size: 14px;
    }

    .links a {
        color: #7b1525;
        font-weight: bold;
        text-decoration: none;
    }

    .back-link {
        display: inline-block;
        margin-bottom: 20px;
        color: #7b1525;
        text-decoration: none;
        font-weight: bold;
    }

    @media (max-width: 850px) {
        .register-wrapper {
            grid-template-columns: 1fr;
        }

        .info-panel {
            padding: 35px;
        }

        .form-panel {
            padding: 32px;
        }

        .info-panel h1 {
            font-size: 28px;
        }
    }
    </style>
</head>

<body>

    <div class="register-wrapper">

        <div class="info-panel">
            <h1>Auditor Registration</h1>
            <p>
                Join FreeLedger as an external auditor. Complete your professional profile,
                get assigned to companies, view accounting records, and submit audit reports.
            </p>

            <div class="feature">✔ Auditor profile with NIC, degree and qualification</div>
            <div class="feature">✔ Work with multiple assigned companies</div>
            <div class="feature">✔ Submit final audit reports with PDF evidence</div>
            <div class="feature">✔ Receive company feedback and review status</div>
        </div>

        <div class="form-panel">
            <a href="index.php" class="back-link">← Back to Home</a>

            <h2>Create Auditor Account</h2>
            <p class="subtitle">Fill your details to register as an auditor.</p>

            <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input name="full_name" placeholder="Enter full name"
                        value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Email Address *</label>
                    <input name="email" type="email" placeholder="example@email.com"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Password *</label>
                    <input name="password" type="password" placeholder="Minimum 6 characters" required>
                </div>

                <div class="form-group">
                    <label>NIC Number *</label>
                    <input name="nic" placeholder="Enter NIC number"
                        value="<?= htmlspecialchars($_POST['nic'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Degree</label>
                    <input name="degree" placeholder="Example: BSc Accounting / Finance"
                        value="<?= htmlspecialchars($_POST['degree'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Qualification *</label>
                    <input name="qualification" placeholder="Example: CA, AAT, ACCA, Diploma"
                        value="<?= htmlspecialchars($_POST['qualification'] ?? '') ?>" required>
                </div>

                <button type="submit" class="btn">Register as Auditor</button>
            </form>

            <div class="links">
                Already registered?
                <a href="login.php">Login here</a>
            </div>
        </div>

    </div>

</body>

</html>