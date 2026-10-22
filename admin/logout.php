<?php
require_once __DIR__ . '/../includes/auth.php';
logoutAdmin();
redirect(BASE_URL . '/index.php');
?>
