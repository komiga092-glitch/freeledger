<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/db.php';

$pageTitle = $pageTitle ?? 'Dashboard';
$pageDescription = $pageDescription ?? 'Professional Accounting & Audit Management System';

/*
|--------------------------------------------------------------------------
| Base URL
|--------------------------------------------------------------------------
*/
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$docRootReal  = realpath($documentRoot);
$appRootReal  = realpath(dirname(__DIR__));

$docRoot = $docRootReal ? str_replace('\\', '/', $docRootReal) : '';
$appRoot = $appRootReal ? str_replace('\\', '/', $appRootReal) : '';

$basePath = '';

if ($docRoot !== '' && $appRoot !== '' && strpos($appRoot, $docRoot) === 0) {
    $basePath = substr($appRoot, strlen($docRoot));
}

$basePath = trim((string)$basePath, '/');
$base_url = ($basePath === '') ? '/' : '/' . $basePath . '/';

$showSidebarToggle = $showSidebarToggle ?? true;

$headerUserId = (int)($_SESSION['user_id'] ?? 0);
$headerCompanyId = (int)($_SESSION['company_id'] ?? 0);
$headerRole = normalize_role_value((string)($_SESSION['role_in_company'] ?? $_SESSION['role'] ?? ''));

if (
    $headerUserId > 0 &&
    $headerCompanyId > 0 &&
    in_array($headerRole, ['organization', 'accountant'], true)
) {
    check_and_create_due_notifications($conn, $headerUserId, $headerCompanyId);
}

$headerUnreadCount = $headerUserId > 0
    ? get_unread_notification_count($conn, $headerUserId)
    : 0;

$headerNotifications = $headerUserId > 0
    ? get_user_notifications($conn, $headerUserId, 8)
    : [];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | FreeLedger</title>
    <meta name="description" content="<?= e($pageDescription) ?>">

    <link rel="stylesheet" href="<?= e($base_url) ?>assets/css/style.css">
</head>

<body>

    <?php if ($showSidebarToggle): ?>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <?php endif; ?>

    <header class="top-header">
        <div class="header-left">
            <?php if ($showSidebarToggle): ?>
            <button type="button" id="sidebarToggle" class="sidebar-toggle" aria-label="Open menu">
                <span></span>
                <span></span>
                <span></span>
            </button>
            <?php endif; ?>

            <div class="header-title-block">
                <h1><?= e($pageTitle) ?></h1>
                <p><?= e($pageDescription) ?></p>
            </div>
        </div>

        <div class="header-right">
            <?php if ($headerUserId > 0): ?>
            <div class="notification-container">
                <button type="button" class="notification-bell" onclick="toggleNotifications()">
                    🔔
                    <?php if ($headerUnreadCount > 0): ?>
                    <span class="notification-count">
                        <?= $headerUnreadCount > 99 ? '99+' : (int)$headerUnreadCount ?>
                    </span>
                    <?php endif; ?>
                </button>

                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <h4>Notifications</h4>
                        <a href="<?= e($base_url) ?>notifications.php">View All</a>
                    </div>

                    <div class="notification-list">
                        <?php if (empty($headerNotifications)): ?>
                        <div class="notification-item no-notifications">
                            No notifications
                        </div>
                        <?php else: ?>
                        <?php foreach ($headerNotifications as $notif): ?>
                        <?php
                                $notificationId = (int)$notif['notification_id'];
                                $relatedUrl = (string)($notif['related_url'] ?? '');
                                $isUnread = (int)($notif['is_read'] ?? 0) === 0;
                                ?>

                        <div class="notification-item <?= $isUnread ? 'unread' : '' ?>"
                            onclick='markNotificationAsRead(<?= $notificationId ?>, <?= json_encode($relatedUrl) ?>)'>
                            <div class="message">
                                <?= e((string)$notif['message']) ?>
                            </div>

                            <div class="meta">
                                <?= e((string)($notif['company_name'] ?? 'Company')) ?> •
                                <?= e(date('M j, H:i', strtotime((string)$notif['created_at']))) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <a href="<?= e($base_url) ?>logout.php" class="top-login-link">
                Logout
            </a>
        </div>
    </header>