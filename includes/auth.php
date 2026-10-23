<?php
/**
 * Authentication Helper
 */
session_start();

require_once __DIR__ . '/../config/database.php';

function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function loginAdmin($username, $password) {
    if ($username === ADMIN_USERNAME && $password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        logActivity($username, 'LOGIN', 'Admin logged in successfully');
        return true;
    }
    return false;
}

function logoutAdmin() {
    if (isset($_SESSION['admin_username'])) {
        logActivity($_SESSION['admin_username'], 'LOGOUT', 'Admin logged out');
    }
    session_unset();
    session_destroy();
}

function getAdminUsername() {
    return $_SESSION['admin_username'] ?? 'admin';
}

function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function statusBadge($status) {
    $map = [
        'PRESENT'       => 'badge-success',
        'ABSENT'        => 'badge-danger',
        'PENDING'       => 'badge-warning',
        'NOT_ELIGIBLE'  => 'badge-danger',
        'PAID'          => 'badge-success',
        'ONE_TIME'      => 'badge-info',
        'COMPLETED'     => 'badge-success',
        'NOT_PAID'      => 'badge-danger',
        'CONVA_PAY'     => 'badge-primary',
        'ISSUED'        => 'badge-success',
        'READY'         => 'badge-info',
    ];
    $class = $map[$status] ?? 'badge-secondary';
    $label = str_replace('_', ' ', $status);
    return '<span class="badge ' . $class . '">' . $label . '</span>';
}
?>
