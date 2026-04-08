<?php
require_once __DIR__ . '/config/db.php';
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
header('Location: login.php');
exit;
