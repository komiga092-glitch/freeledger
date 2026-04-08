<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pageTitle)) {
    $pageTitle = 'Dashboard';
}

if (!isset($pageDescription)) {
    $pageDescription = 'Professional Accounting & Audit Management System';
}

require_once __DIR__ . '/functions.php';

/* project base path */
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])) : '';
$appRoot = str_replace('\\', '/', realpath(dirname(__DIR__)));
$basePath = '';
if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
    $basePath = substr($appRoot, strlen($docRoot));
}
$basePath = trim($basePath, '/');
$base_url = $basePath === '' ? '/' : '/' . $basePath . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | TrustLedger Pro</title>
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
</head>
<body>
<div class="app-shell">