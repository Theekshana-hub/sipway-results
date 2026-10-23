<?php
if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/database.php';
}
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$pageIcons = [
    'index' => 'bi-speedometer2',
    'courses' => 'bi-book',
    'batches' => 'bi-collection',
    'students' => 'bi-people',
    'results' => 'bi-clipboard-data',
    'payments' => 'bi-cash-coin',
    'certificates' => 'bi-award',
    'reports' => 'bi-file-earmark-bar-graph',
];
$pageTitles = [
    'index' => 'Dashboard',
    'courses' => 'Courses',
    'batches' => 'Batches',
    'students' => 'Students',
    'results' => 'Examination Results',
    'payments' => 'Payments',
    'certificates' => 'Certificates',
    'reports' => 'Reports',
];
$icon = $pageIcons[$currentPage] ?? 'bi-grid';
$title = $pageTitles[$currentPage] ?? ($pageTitle ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?> | <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ========== SIDEBAR ========== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <a href="<?php echo BASE_URL; ?>/admin/index.php">
            <img src="<?php echo BASE_URL; ?>/assets/img/logo.png" alt="Sipway Campus">
        </a>
        <div class="brand-sub">Results System</div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">test</div>
        <a class="nav-link <?php echo $currentPage=='index'?'active':''; ?>" href="<?php echo BASE_URL; ?>/admin/index.php">
            <i class="bi bi-speedometer2"></i> Dashboard 121
        </a>

        <div class="nav-section-label">Academic</div>
        <a class="nav-link <?php echo $currentPage=='courses'?'active':''; ?>" href="<?php echo BASE_URL; ?>/admin/courses.php">
            <i class="bi bi-book"></i> Courses
        </a>
        <a class="nav-link <?php echo $currentPage=='batches'?'active':''; ?>" href="<?php echo BASE_URL; ?>/admin/batches.php">
            <i class="bi bi-collection"></i> Batches
        </a>
        <a class="nav-link <?php echo $currentPage=='students'?'active':''; ?>" href="<?php echo BASE_URL; ?>/admin/students.php">
            <i class="bi bi-people"></i> Students
        </a>
        <a class="nav-link <?php echo $currentPage=='results'?'active':''; ?>" href="<?php echo BASE_URL; ?>/admin/results.php">
            <i class="bi bi-clipboard-data"></i> Results
        </a>

        <div class="nav-section-label">Finance & Certificates</div>
        <a class="nav-link <?php echo $currentPage=='payments'?'active':''; ?>" href="<?php echo BASE_URL; ?>/admin/payments.php">
            <i class="bi bi-cash-coin"></i> Payments
        </a>
        <a class="nav-link <?php echo $currentPage=='certificates'?'active':''; ?>" href="<?php echo BASE_URL; ?>/admin/certificates.php">
            <i class="bi bi-award"></i> Certificates
        </a>

        <div class="nav-section-label">Reports</div>
        <a class="nav-link <?php echo $currentPage=='reports'?'active':''; ?>" href="<?php echo BASE_URL; ?>/admin/reports.php">
            <i class="bi bi-file-earmark-bar-graph"></i> Reports
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?php echo strtoupper(substr(getAdminUsername(), 0, 1)); ?></div>
            <div>
                <div class="fw-semibold" style="font-size:0.9rem"><?php echo getAdminUsername(); ?></div>
                <small style="color:rgba(255,255,255,0.4)">Administrator</small>
            </div>
        </div>
        <a href="<?php echo BASE_URL; ?>/admin/logout.php" class="logout-btn">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</aside>

<!-- ========== MAIN ========== -->
<div class="main-wrapper">
    <div class="topbar">
        <div class="d-flex align-items-center gap-2">
            <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle menu">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="page-title"><i class="bi <?php echo $icon; ?> me-2"></i><?php echo $title; ?></h1>
        </div>
        <div class="text-muted small d-none d-md-block">
            <i class="bi bi-calendar3 me-1"></i><?php echo date('d M Y, l'); ?>
        </div>
    </div>

    <main class="main-content">
        <?php
        $flash = getFlash();
        if ($flash):
        ?>
        <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show" role="alert">
            <?php echo sanitize($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>