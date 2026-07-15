<?php
require_once 'config.php';

header('Content-Type: application/json');

function ensureEmployeeContactColumn(PDO $pdo): void {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'contact_no'");
    $check->execute();
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN contact_no VARCHAR(11) NULL AFTER email");
    }
}

ensureEmployeeContactColumn($pdo);

function normalizedHiringPosition(string $position): string {
    $value = strtolower(trim($position));
    if (strpos($value, 'barista') !== false) return 'barista';
    if (strpos($value, 'cashier') !== false) return 'cashier';
    return $value;
}

function ensureHiringSlotAvailable(PDO $pdo, string $position): ?string {
    $normalized = normalizedHiringPosition($position);
    if (!in_array($normalized, ['barista', 'cashier'], true)) {
        return 'Only Barista and Cashier positions can be hired through this form.';
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE status = 'approved' AND LOWER(position) LIKE ?");
    $stmt->execute(['%' . $normalized . '%']);
    if ((int)$stmt->fetchColumn() >= 4) {
        return ucfirst($normalized) . ' is already full. Set an existing employee to Not Active/Resigned before hiring another one.';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// Employee accounts may only be created by the Administrator.
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Administrator') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the Administrator can create employee accounts.']);
    exit;
}

$full_name = trim($_POST['full_name'] ?? '');
$email     = trim($_POST['email'] ?? '');
$contact_no = trim($_POST['contact_no'] ?? '');
$username  = trim($_POST['username'] ?? '');
$position  = trim($_POST['position'] ?? 'Employee');
$applicant_id = (int)($_POST['applicant_id'] ?? 0);

if (!$full_name || !$email || !$contact_no || !$username) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
if (!preg_match('/^\d{11}$/', $contact_no)) {
    echo json_encode(['success' => false, 'message' => 'Contact number must be exactly 11 digits.']);
    exit;
}

// HR creates employee accounts only; privileged accounts cannot be created here.
if (stripos($position, 'administrator') !== false || stripos($position, 'HR') !== false) {
    echo json_encode(['success' => false, 'message' => 'This form can create employee accounts only.']);
    exit;
}
$slotError = ensureHiringSlotAvailable($pdo, $position);
if ($slotError) {
    echo json_encode(['success' => false, 'message' => $slotError]);
    exit;
}

// Prevent duplicate email / username
$stmt = $pdo->prepare("SELECT id FROM employees WHERE email = ? OR username = ? LIMIT 1");
$stmt->execute([$email, $username]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Email or username already exists.']);
    exit;
}

$pin = (string)random_int(100000, 999999);
$pinHash = password_hash($pin, PASSWORD_DEFAULT);
$passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$subject = 'Your Quadra Cafe Employee Account PIN';
$message = "Dear {$full_name},\n\nYour Quadra Cafe employee account has been created.\n\nAccess PIN: {$pin}\n\nPlease remember this PIN and keep it private. You will use it to sign in to your employee account.\n\nRegards,\nQuadra Cafe HR Team";

try {
    require_once 'send_email.php';
    sendQuadraEmail($email, $full_name, $subject, $message);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The employee account was not created because the PIN email could not be sent: ' . $error->getMessage()]);
    exit;
}

$stmt = $pdo->prepare("INSERT INTO employees (full_name, email, contact_no, username, password, pin, position, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 'approved')");
$stmt->execute([$full_name, $email, $contact_no, $username, $passwordHash, $pinHash, $position]);
$employeeId = (int)$pdo->lastInsertId();

if ($applicant_id > 0) {
    $stmt = $pdo->prepare("UPDATE applicants SET status = 'hired', employee_id = ? WHERE id = ? AND status = 'final_interview_passed'");
    $stmt->execute([(string)$employeeId, $applicant_id]);
}

echo json_encode([
    'success' => true,
    'message' => 'Employee account created successfully. The access PIN was emailed to ' . $email . '.'
]);
