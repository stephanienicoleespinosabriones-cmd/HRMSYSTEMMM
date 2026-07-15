<?php
// Database configuration (MySQL via PDO)
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
session_start();

// Expire inactive sessions after 30 minutes and all sessions after 8 hours.
if (!empty($_SESSION['user_id'])) {
    $now = time();
    $inactiveTooLong = !empty($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity']) > 1800;
    $sessionTooOld = !empty($_SESSION['login_started_at']) && ($now - (int)$_SESSION['login_started_at']) > 28800;
    if ($inactiveTooLong || $sessionTooOld) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    } else {
        $_SESSION['last_activity'] = $now;
    }
}

$host     = 'localhost';
$db       = 'quadra_hrms';
$user     = 'root';
$pass     = '';
$charset  = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}
