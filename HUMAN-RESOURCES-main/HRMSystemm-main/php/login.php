<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$pin  = $_POST['pin'] ?? '';

if (!$pin) {
    echo json_encode(['success' => false, 'message' => 'Enter your access PIN.']);
    exit;
}

// The matching PIN determines the account. Admin and HR go to their dashboards;
// regular employees go to the employee portal.
$stmt = $pdo->query("SELECT * FROM employees WHERE pin IS NOT NULL AND pin <> ''");
$user = null;
foreach ($stmt->fetchAll() as $candidate) {
    if (!empty($candidate['pin']) && password_verify($pin, $candidate['pin'])) {
        $user = $candidate;
        break;
    }
}

// No matching account PIN.
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Account does not exist.']);
    exit;
}

// Account exists but is still waiting for an admin decision.
if ($user['status'] === 'pending') {
    echo json_encode(['success' => false, 'message' => 'Account not yet approved. Please wait for the admin to accept your account.']);
    exit;
}

if ($user['status'] === 'rejected') {
    echo json_encode(['success' => false, 'message' => 'This employee account was not approved. Please contact HR.']);
    exit;
}

if ($user['status'] === 'inactive') {
    echo json_encode(['success' => false, 'message' => 'This employee account is not active. Please contact HR.']);
    exit;
}

// Success
session_regenerate_id(true);
$_SESSION['user_id']   = $user['id'];
$_SESSION['user_name'] = $user['full_name'];
$_SESSION['user_role'] = $user['position'];
$_SESSION['login_started_at'] = time();
$_SESSION['last_activity'] = time();

if ($user['position'] === 'Administrator') {
    $redirect = 'Admin.html';
} elseif (stripos($user['position'], 'HR') !== false || strcasecmp($user['position'], 'Human Resources') === 0) {
    $redirect = 'Hr.html';
} else {
    $redirect = 'Employee.html';
}

echo json_encode([
    'success'  => true,
    'redirect' => $redirect,
    'name'     => $user['full_name']
]);
