<?php
/**
 * Sipway Campus - Database Configuration
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sipway_results');

// Hardcoded Admin Credentials
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'sipway2026');  // Change this after first login recommendation

// App settings
define('APP_NAME', 'Sipway Campus');
define('APP_TITLE', 'Student Exam Results Management System');
define('BASE_URL', '/sipway_results'); // Adjust if needed

function getConnection() {
    static $conn = null;
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($conn->connect_error) {
                die("Connection failed: " . $conn->connect_error);
            }
            $conn->set_charset("utf8mb4");
        } catch (Exception $e) {
            die("Database connection error: " . $e->getMessage());
        }
    }
    return $conn;
}

function logActivity($username, $action, $details = '') {
    $conn = getConnection();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare("INSERT INTO activity_log (username, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();
}
?>
