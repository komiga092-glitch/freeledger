<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

set_security_headers();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);
$role = normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));

if ($company_id <= 0) {
    header('Location: select_company.php');
    exit;
}

$receiver_id = (int)($_GET['receiver_id'] ?? $_POST['receiver_id'] ?? 0);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = trim((string)($_POST['message'] ?? ''));

    if ($receiver_id <= 0) {
        $error = 'Please select a receiver.';
    } elseif ($text === '') {
        $error = 'Message cannot be empty.';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO auditor_messages
                (company_id, sender_user_id, receiver_user_id, message)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('iiis', $company_id, $user_id, $receiver_id, $text);
        $stmt->execute();
        $stmt->close();

        $message = 'Message sent successfully.';
    }
}

$usersStmt = $conn->prepare("
    SELECT au.user_id, au.full_name, au.email, cua.role_in_company
    FROM company_user_access cua
    INNER JOIN app_users au ON au.user_id = cua.user_id
    WHERE cua.company_id = ?
      AND cua.access_status = 'Active'
      AND au.user_id != ?
    ORDER BY au.full_name ASC
");
$usersStmt->bind_param('ii', $company_id, $user_id);
$usersStmt->execute();
$users = $usersStmt->get_result();

$msgStmt = $conn->prepare("
    SELECT 
        m.message_id,
        m.message,
        m.created_at,
        s.full_name AS sender_name,
        r.full_name AS receiver_name
    FROM auditor_messages m
    INNER JOIN app_users s ON s.user_id = m.sender_user_id
    INNER JOIN app_users r ON r.user_id = m.receiver_user_id
    WHERE m.company_id = ?
      AND (m.sender_user_id = ? OR m.receiver_user_id = ?)
    ORDER BY m.created_at DESC
");
$msgStmt->bind_param('iii', $company_id, $user_id, $user_id);
$msgStmt->execute();
$messages = $msgStmt->get_result();

$pageTitle = 'Auditor Messages';
$pageDescription = 'Send and view messages between company users and auditors';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="content-wrapper">

        <div class="page-header">
            <h1>Auditor Messages</h1>
            <p>Communicate with auditors and company users.</p>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>Send Message</h3>
            </div>

            <div class="card-body">
                <form method="POST" class="form-grid">
                    <div class="form-group full">
                        <label class="form-label">Receiver</label>
                        <select name="receiver_id" class="form-control" required>
                            <option value="">Select receiver</option>
                            <?php while ($u = $users->fetch_assoc()): ?>
                            <option value="<?= (int)$u['user_id'] ?>"
                                <?= $receiver_id === (int)$u['user_id'] ? 'selected' : '' ?>>
                                <?= e($u['full_name']) ?> - <?= e($u['role_in_company']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Message</label>
                        <textarea name="message" class="form-control" rows="5" required></textarea>
                    </div>

                    <div class="form-group full">
                        <button type="submit" class="btn">Send Message</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-24">
            <div class="card-header">
                <h3>Message History</h3>
            </div>

            <div class="card-body">
                <?php if ($messages->num_rows > 0): ?>
                <?php while ($m = $messages->fetch_assoc()): ?>
                <div class="message-item">
                    <strong><?= e($m['sender_name']) ?></strong>
                    <span>to</span>
                    <strong><?= e($m['receiver_name']) ?></strong>
                    <p style="margin:8px 0;"><?= nl2br(e($m['message'])) ?></p>
                    <small><?= e($m['created_at']) ?></small>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <p>No messages found.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php
$usersStmt->close();
$msgStmt->close();
include 'includes/footer.php';
?>