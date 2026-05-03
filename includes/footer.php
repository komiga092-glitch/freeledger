<?php
declare(strict_types=1);

if (!isset($base_url)) {
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
}
?>

<script src="<?= htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8') ?>assets/js/app.js"></script>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (!sidebar || !overlay) {
        return;
    }

    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (!sidebar || !overlay) {
        return;
    }

    sidebar.classList.remove('show');
    overlay.classList.remove('show');
}

document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');

    if (!sidebar || !toggle) {
        return;
    }

    const clickedInsideSidebar = sidebar.contains(event.target);
    const clickedToggle = toggle.contains(event.target);

    if (!clickedInsideSidebar && !clickedToggle && window.innerWidth <= 1200) {
        closeSidebar();
    }
});

window.addEventListener('resize', function() {
    if (window.innerWidth > 1200) {
        closeSidebar();
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeSidebar();
    }
});

function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    if (!dropdown) return;
    dropdown.classList.toggle('show');
}

function markNotificationAsRead(notificationId, url) {
    fetch('<?= htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8') ?>mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'notification_id=' + encodeURIComponent(notificationId) +
            '&csrf_token=' + encodeURIComponent(
                '<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>')
    }).finally(function() {
        if (url && url !== '') {
            window.location.href = url;
        }
    });
}

document.addEventListener('click', function(event) {
    const container = document.querySelector('.notification-container');
    const dropdown = document.getElementById('notificationDropdown');

    if (!container || !dropdown) return;

    if (!container.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});
</script>


</body>

</html>