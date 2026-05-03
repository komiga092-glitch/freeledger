<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

set_security_headers();

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$token = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    die("Invalid invitation token.");
}

$stmt = $conn->prepare("
    SELECT 
        ai.*,
        c.company_name
    FROM auditor_invites ai
    INNER JOIN companies c 
        ON c.company_id = ai.company_id
    WHERE ai.token = ?
      AND LOWER(ai.status) = 'pending'
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$invite = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invite) {
    die("Invalid or already used invitation.");
}

if ((int)$invite['auditor_user_id'] !== $user_id) {
    die("This invitation does not belong to your account.");
}

$company_id = (int)$invite['company_id'];
$invited_by = (int)$invite['invited_by'];
$companyName = (string)$invite['company_name'];

$stmt = $conn->prepare("
    INSERT INTO company_user_access
        (company_id, user_id, role_in_company, access_status)
    VALUES
        (?, ?, 'auditor', 'Active')
    ON DUPLICATE KEY UPDATE
        role_in_company = 'auditor',
        access_status = 'Active'
");
$stmt->bind_param("ii", $company_id, $user_id);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare("
    UPDATE auditor_invites
    SET status = 'accepted',
        accepted_at = NOW()
    WHERE invite_id = ?
");
$stmt->bind_param("i", $invite['invite_id']);
$stmt->execute();
$stmt->close();

create_notification(
    $conn,
    $invited_by,
    $company_id,
    "Auditor accepted your invitation for {$companyName}.",
    "auditor_assigned",
    "auditor_directory.php"
);

$_SESSION['company_id'] = $company_id;
$_SESSION['company_name'] = $companyName;
$_SESSION['role_in_company'] = 'auditor';

header("Location: auditor_profile.php");
exit;