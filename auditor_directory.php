<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

set_security_headers();

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);
$role = normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));

if ($company_id <= 0) {
    header("Location: select_company.php");
    exit;
}

if (!in_array($role, ['organization', 'accountant'], true)) {
    die("Access denied. Only organization/accountant can assign auditors.");
}

$message = '';
if (isset($_GET['cancel_auditor'])) {
    $auditor_user_id = (int)$_GET['cancel_auditor'];

    $stmt = $conn->prepare("
        SELECT invite_id
        FROM auditor_invites
        WHERE company_id = ?
          AND auditor_user_id = ?
          AND status = 'accepted'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $company_id, $auditor_user_id);
    $stmt->execute();
    $invite = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($invite) {
        $stmt = $conn->prepare("
            UPDATE company_user_access
            SET access_status = 'Inactive'
            WHERE company_id = ?
              AND user_id = ?
              AND role_in_company = 'auditor'
        ");
        $stmt->bind_param("ii", $company_id, $auditor_user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE auditor_invites
            SET status = 'cancelled',
                cancelled_at = NOW()
            WHERE invite_id = ?
        ");
        $stmt->bind_param("i", $invite['invite_id']);
        $stmt->execute();
        $stmt->close();

        if ((int)($_SESSION['company_id'] ?? 0) === $company_id && (int)($_SESSION['user_id'] ?? 0) === $auditor_user_id) {
    unset(
        $_SESSION['company_id'],
        $_SESSION['company_name'],
        $_SESSION['company_registration_no'],
        $_SESSION['company_email'],
        $_SESSION['company_phone'],
        $_SESSION['company_address']
    );
}

       create_notification(
    $conn,
    $auditor_user_id,
    $company_id,
    "Your auditor access has been cancelled by the company.",
    "auditor_cancel",
    ""
);

        $message = "Auditor assignment cancelled successfully.";
    } else {
        $message = "Cancel option is available only after auditor accepts the invitation.";
    }
}
$search = trim((string)($_GET['search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auditor_user_id'])) {
    $auditor_user_id = (int)$_POST['auditor_user_id'];

    if ($auditor_user_id <= 0) {
        $message = "Invalid auditor selected.";
    } else {
        $token = bin2hex(random_bytes(32));
        $companyName = companyName($conn, $company_id);

        $stmt = $conn->prepare("
            INSERT INTO auditor_invites
                (company_id, auditor_user_id, invited_by, token, status)
            VALUES
                (?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("iiis", $company_id, $auditor_user_id, $user_id, $token);
        $stmt->execute();
        $stmt->close();

       create_notification(
    $conn,
    $auditor_user_id,
    $company_id,
    "You have been invited as an auditor for {$companyName}. Please accept the invitation.",
    "auditor_invite",
    "auditor_accept_invite.php?token={$token}"
);

        $message = "Auditor invitation sent successfully.";
    }
}

$sql = "
    SELECT 
        au.user_id,
        au.full_name,
        au.email,
        ap.nic,
        ap.degree,
        ap.qualification,
        ap.experience_years,
        ap.photo_path,
        COALESCE(AVG(af.rating), 0) AS avg_rating,
        ai.status AS invite_status
    FROM app_users au
    INNER JOIN auditor_profiles ap 
        ON ap.user_id = au.user_id
    LEFT JOIN auditor_feedback af 
        ON af.auditor_user_id = au.user_id
    LEFT JOIN auditor_invites ai
        ON ai.auditor_user_id = au.user_id
       AND ai.company_id = ?
       AND ai.status IN ('pending','accepted')
    WHERE au.auditor_type = 'external'
      AND (
            au.full_name LIKE ?
         OR au.email LIKE ?
         OR ap.nic LIKE ?
         OR ap.degree LIKE ?
         OR ap.qualification LIKE ?
      )
    GROUP BY 
        au.user_id,
        au.full_name,
        au.email,
        ap.nic,
        ap.degree,
        ap.qualification,
        ap.experience_years,
        ap.photo_path,
        ai.status
    ORDER BY au.full_name ASC
";

$like = "%{$search}%";

$stmt = $conn->prepare($sql);
$stmt->bind_param("isssss", $company_id, $like, $like, $like, $like, $like);
$stmt->execute();
$auditors = $stmt->get_result();

$pageTitle = 'Auditor Directory';
$pageDescription = 'Search and assign auditors to your company';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="content-wrapper">

        <div class="page-header">
            <h1>Auditor Directory</h1>
            <p>Find external auditors and assign them to your selected company.</p>
        </div>

        <?php if ($message !== ''): ?>
        <div class="alert alert-success">
            <?= e($message) ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>Search Auditors</h3>
            </div>

            <div class="card-body">
                <form method="GET" class="form-grid">
                    <div class="form-group full">
                        <label class="form-label">Search by name, email, NIC, degree or qualification</label>
                        <input type="text" name="search" class="form-control"
                            placeholder="Example: CA, ACCA, audit, NIC, name" value="<?= e($search) ?>">
                    </div>

                    <div class="form-group full">
                        <button type="submit" class="btn">Search Auditor</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="grid grid-3 mt-24">
            <?php if ($auditors->num_rows > 0): ?>
            <?php while ($a = $auditors->fetch_assoc()): ?>
            <div class="card">
                <div class="card-body">
                    <?php if (!empty($a['photo_path'])): ?>
                    <img src="<?= e((string)$a['photo_path']) ?>" alt="Auditor Photo" class="auditor-photo">
                    <?php else: ?>
                    <div class="auditor-placeholder">
                        👤
                    </div>
                    <?php endif; ?>

                    <h3 class="auditor-name">
                        <?= e((string)$a['full_name']) ?>
                    </h3>

                    <p><strong>Email:</strong> <?= e((string)$a['email']) ?></p>
                    <p><strong>NIC:</strong> <?= e((string)$a['nic']) ?></p>
                    <p><strong>Degree:</strong> <?= e((string)($a['degree'] ?? '-')) ?></p>
                    <p><strong>Qualification:</strong> <?= e((string)$a['qualification']) ?></p>
                    <p><strong>Experience:</strong> <?= (int)$a['experience_years'] ?> years</p>
                    <p><strong>Rating:</strong> <?= number_format((float)$a['avg_rating'], 1) ?>/5</p>

                    <?php if ($a['invite_status'] === 'pending'): ?>

                    <span class="badge badge-warning">Invite Pending</span>

                    <?php elseif ($a['invite_status'] === 'accepted'): ?>

                    <span class="badge badge-success">Accepted Auditor</span>

                    <a href="auditor_directory.php?cancel_auditor=<?= (int)$a['user_id'] ?>"
                        class="btn btn-danger mt-16"
                        onclick="return confirm('Are you sure you want to cancel this auditor assignment?')">
                        Cancel Assignment
                    </a>

                    <?php else: ?>

                    <form method="POST" class="mt-16">
                        <input type="hidden" name="auditor_user_id" value="<?= (int)$a['user_id'] ?>">
                        <button type="submit" class="btn">Invite Auditor</button>
                    </form>

                    <?php endif; ?>

                    <div class="mt-16">
                        <a href="auditor_feedback.php?auditor_id=<?= (int)$a['user_id'] ?>" class="btn btn-light">
                            Give Feedback
                        </a>
                        <a href="auditor_messages.php?receiver_id=<?= (int)$a['user_id'] ?>" class="btn btn-light">
                            Message
                        </a>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
            <?php else: ?>
            <div class="card">
                <div class="card-body">
                    No auditors found.
                </div>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php
$stmt->close();
include 'includes/footer.php';
?>