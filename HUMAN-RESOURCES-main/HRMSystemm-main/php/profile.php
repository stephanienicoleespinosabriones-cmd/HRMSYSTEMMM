<?php
require_once 'config.php';

function ensureEmployeeProfileSchema(PDO $pdo): void {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'contact_no'");
    $check->execute();
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN contact_no VARCHAR(11) NULL AFTER email");
    }

    $columns = [
        'pending_pin_hash' => "ALTER TABLE employees ADD COLUMN pending_pin_hash VARCHAR(255) NULL AFTER pin",
        'pending_pin_token' => "ALTER TABLE employees ADD COLUMN pending_pin_token VARCHAR(255) NULL AFTER pending_pin_hash",
        'pending_pin_code' => "ALTER TABLE employees ADD COLUMN pending_pin_code VARCHAR(255) NULL AFTER pending_pin_token",
        'pending_pin_expires_at' => "ALTER TABLE employees ADD COLUMN pending_pin_expires_at DATETIME NULL AFTER pending_pin_code",
    ];
    foreach ($columns as $column => $sql) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = ?");
        $stmt->execute([$column]);
        if (!$stmt->fetchColumn()) {
            $pdo->exec($sql);
        }
    }
}

ensureEmployeeProfileSchema($pdo);

function renderPinConfirmationPage(string $title, string $message, bool $success = true): void {
    http_response_code($success ? 200 : 400);
    header('Content-Type: text/html; charset=UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $icon = $success ? '✓' : '!';
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$safeTitle}</title>
  <style>
    body{margin:0;min-height:100vh;display:grid;place-items:center;background:#111014;color:#f7f2e8;font-family:Arial,sans-serif}
    .box{width:min(92vw,460px);padding:34px;border:1px solid rgba(214,174,98,.35);border-radius:18px;background:#1a1820;text-align:center;box-shadow:0 22px 70px rgba(0,0,0,.35)}
    .icon{width:64px;height:64px;margin:0 auto 18px;border-radius:50%;display:grid;place-items:center;background:#d6ae62;color:#171318;font-size:32px;font-weight:700}
    h1{margin:0 0 12px;font-family:Georgia,serif;font-size:30px;color:#f1cd82}
    p{margin:0;line-height:1.65;color:rgba(255,255,255,.76)}
  </style>
</head>
<body>
  <main class="box">
    <div class="icon">{$icon}</div>
    <h1>{$safeTitle}</h1>
    <p>{$safeMessage}</p>
  </main>
</body>
</html>
HTML;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'confirm_pin') {
    $token = trim($_GET['token'] ?? '');
    if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        renderPinConfirmationPage('Invalid Link', 'This PIN change confirmation link is invalid. Please request a new one from your employee portal.', false);
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("SELECT id, full_name, email, pending_pin_expires_at FROM employees WHERE pending_pin_token = ? AND status = 'approved' LIMIT 1");
    $stmt->execute([$tokenHash]);
    $employee = $stmt->fetch();

    if (!$employee || empty($employee['pending_pin_expires_at']) || strtotime($employee['pending_pin_expires_at']) < time()) {
        renderPinConfirmationPage('Link Expired', 'This PIN change confirmation link is expired or already used. Please request a new PIN change from your employee portal.', false);
    }

    $pin = (string)random_int(100000, 999999);
    $pinHash = password_hash($pin, PASSWORD_DEFAULT);
    $subject = 'Your New Quadra Cafe Employee Account PIN';
    $message = "Dear {$employee['full_name']},\n\nYour PIN change was confirmed successfully.\n\nNew Access PIN: {$pin}\n\nPlease remember this PIN and keep it private. You will use it to sign in to your employee account.\n\nRegards,\nQuadra Cafe HR Team";

    try {
        require_once 'send_email.php';
        sendQuadraEmail($employee['email'], $employee['full_name'], $subject, $message);
    } catch (Throwable $error) {
        renderPinConfirmationPage('Email Not Sent', 'Your confirmation link is valid, but the new PIN email could not be sent. Please click the confirmation link again in a few minutes or contact HR.', false);
    }

    $stmt = $pdo->prepare("UPDATE employees SET pin = ?, pending_pin_hash = NULL, pending_pin_token = NULL, pending_pin_code = NULL, pending_pin_expires_at = NULL WHERE id = ?");
    $stmt->execute([$pinHash, $employee['id']]);
    renderPinConfirmationPage('PIN Change Confirmed', 'Your new access PIN was emailed to your registered email address. You may now close this page.');
}

header('Content-Type: application/json');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId < 1) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Account does not exist. Please sign in again.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, full_name, email, contact_no, position, status, created_at, pin FROM employees WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Account does not exist.']);
    exit;
}

if ($user['status'] !== 'approved') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This employee account is not active. Please contact HR.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contactNo = trim($_POST['contact_no'] ?? '');
        $currentPin = trim($_POST['current_pin'] ?? '');

        if (!preg_match('/^\d{6}$/', $currentPin) || empty($user['pin']) || !password_verify($currentPin, $user['pin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Verification failed. Enter your current 6-digit access PIN before changing personal information.']);
            exit;
        }

        if (!$fullName || !$email || !$contactNo) {
            echo json_encode(['success' => false, 'message' => 'Please fill in all required profile fields.']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }
        if (!preg_match('/^\d{11}$/', $contactNo)) {
            echo json_encode(['success' => false, 'message' => 'Contact number must be exactly 11 digits.']);
            exit;
        }

        $check = $pdo->prepare("SELECT id FROM employees WHERE email = ? AND id <> ? LIMIT 1");
        $check->execute([$email, $userId]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already belongs to another account.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE employees SET full_name = ?, email = ?, contact_no = ? WHERE id = ?");
        $stmt->execute([$fullName, $email, $contactNo, $userId]);
        $_SESSION['user_name'] = $fullName;
        echo json_encode(['success' => true, 'message' => 'Your profile information was updated successfully.']);
        exit;
    }

    if ($action === 'regenerate_pin') {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');
        $subject = 'Confirm Your Quadra Cafe Employee PIN Change';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/php/profile.php')), '/');
        $confirmUrl = $scheme . '://' . $host . $basePath . '/profile.php?action=confirm_pin&token=' . urlencode($token);
        $message = "Dear {$user['full_name']},\n\nWe received a request to change your Quadra Cafe employee account PIN.\n\nClick this confirmation link if you want to change your PIN:\n{$confirmUrl}\n\nAfter you click the link, we will email your new PIN in a separate email. Your current PIN will remain active until the link is confirmed. This link expires in 30 minutes.\n\nIf you did not request this change, you can ignore this email.\n\nRegards,\nQuadra Cafe HR Team";

        try {
            require_once 'send_email.php';
            sendQuadraEmail($user['email'], $user['full_name'], $subject, $message);
        } catch (Throwable $error) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'The PIN confirmation email could not be sent: ' . $error->getMessage()]);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE employees SET pending_pin_hash = NULL, pending_pin_token = ?, pending_pin_code = NULL, pending_pin_expires_at = ? WHERE id = ?");
        $stmt->execute([$tokenHash, $expiresAt, $userId]);
        echo json_encode(['success' => true, 'message' => 'A PIN change confirmation link was emailed to ' . $user['email'] . '. Click that link to receive your new PIN in a second email.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid profile action.']);
    exit;
}

$safeUser = $user;
unset($safeUser['pin']);
echo json_encode(['success' => true, 'user' => $safeUser]);
