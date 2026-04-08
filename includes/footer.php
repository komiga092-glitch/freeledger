<?php
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])) : '';
$appRoot = str_replace('\\', '/', realpath(dirname(__DIR__)));
$basePath = '';
if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
    $basePath = substr($appRoot, strlen($docRoot));
}
$basePath = trim($basePath, '/');
$base_url = $basePath === '' ? '/' : '/' . $basePath . '/';
?>
</div> <!-- .app-shell -->
<script src="<?= $base_url ?>assets/js/app.js"></script>
</body>

</html>