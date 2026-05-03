<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

set_security_headers();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$pageTitle = 'Notifications';
$pageDescription = 'View all your notifications';

$msg = '';
$msgType = 'success';

// Handle mark all as read
if (isset($_POST['mark_all_read']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    $msg = "Marked $affected notifications as read.";
    $msgType = 'success';
}

// Get notifications
$currentRole = normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));

if ($currentRole === 'auditor') {

    $stmt = $conn->prepare("
        SELECT n.*, c.company_name
        FROM notifications n
        LEFT JOIN companies c ON n.company_id = c.company_id
        WHERE n.user_id = ?
          AND n.type IN ('auditor_invite', 'auditor_assigned', 'auditor_cancel')
        ORDER BY n.created_at DESC
    ");

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} else {

    $notifications = get_user_notifications($conn, $user_id, 100);
}
include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/topbar.php';
?>

<div class="main-area">
    <div class="content">
        <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>All Notifications</h3>
                <span class="badge badge-primary"><?= count($notifications) ?> Total</span>
                <?php if (count(array_filter($notifications, fn($n) => !$n['is_read'])) > 0): ?>
                <form method="POST" style="display: inline;">
                    <?= csrf_field() ?>
                    <button type="submit" name="mark_all_read" class="btn btn-sm btn-outline-primary">Mark All as
                        Read</button>
                </form>
                <?php endif; ?>
            </div>

            <div class="card-body">
                <?php if (empty($notifications)): ?>
                <div class="text-center" style="padding: 40px;">
                    <p style="color: var(--muted); font-size: 16px;">No notifications found.</p>
                </div>
                <?php else: ?>
                <div class="notification-list-full">
                    <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item-full <?= (int)$notif['is_read'] === 1 ? '' : 'unread' ?>"
                        onclick="markAsRead(<?= (int)$notif['notification_id'] ?>, <?= json_encode((string)$notif['related_url']) ?>)">
                        <div class="notification-content">
                            <div class="message"><?php echo e($notif['message']); ?></div>
                            <div class="meta">
                                <span><?php echo e($notif['company_name']); ?></span> •
                                <span><?php echo date('M j, Y H:i', strtotime($notif['created_at'])); ?></span>
                                <?php if (!$notif['is_read']): ?>
                                <span class="unread-indicator">New</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="notification-actions">
                            <?php if (($notif['type'] ?? '') === 'auditor_invite'): ?>
                            <a href="<?= e((string)$notif['related_url']) ?>" class="btn btn-sm btn-primary">
                                Accept
                            </a>

                            <?php elseif (($notif['type'] ?? '') === 'auditor_assigned'): ?>
                            <a href="auditor_profile.php" class="btn btn-sm btn-light">
                                View Company
                            </a>

                            <?php elseif (!empty($notif['related_url'])): ?>
                            <a href="<?= e((string)$notif['related_url']) ?>" class="btn btn-sm btn-primary">
                                View
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.notification-list-full {
    max-height: 600px;
    overflow-y: auto;
}

.notification-item-full {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background 0.2s;
}

.notification-item-full:hover {
    background: var(--bg);
}

.notification-item-full.unread {
    background: #f8f9fa;
    border-left: 3px solid var(--primary);
}

.notification-content {
    flex: 1;
}

.notification-content .message {
    font-size: 14px;
    color: var(--text);
    margin-bottom: 4px;
}

.notification-content .meta {
    font-size: 12px;
    color: var(--muted);
}

.unread-indicator {
    color: var(--primary);
    font-weight: bold;
}

.notification-actions {
    margin-left: 16px;
}
</style>

<script>
function markAsRead(notificationId, url) {
    fetch('mark_notification_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'notification_id=' + notificationId + '&csrf_token=' + encodeURIComponent(document.querySelector(
                'input[name="csrf_token"]')?.value || '')
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const item = document.querySelector('.notification-item-full[onclick*="markAsRead(' +
                    notificationId + '"]');
                if (item) {
                    item.classList.remove('unread');
                    const indicator = item.querySelector('.unread-indicator');
                    if (indicator) indicator.remove();
                }
            }
        })
        .catch(error => console.error('Error:', error));

    if (url) {
        window.location.href = url;
    }
}
</script>
<?php include 'includes/footer.php'; ?>