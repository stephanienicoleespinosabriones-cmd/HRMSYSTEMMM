<?php
require_once 'config.php';
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');

function leaveRespond(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function ensureLeaveSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS leave_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        reference_code VARCHAR(40) NOT NULL,
        leave_type ENUM('vacation','sick','personal','emergency','maternity') NOT NULL,
        pay_type ENUM('with-pay','without-pay') NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        days DECIMAL(5,1) NOT NULL,
        contact_no VARCHAR(11) NOT NULL,
        reason TEXT NOT NULL,
        leave_during ENUM('whole-day','half-day-am','half-day-pm') NOT NULL,
        comments TEXT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_leave_reference (reference_code),
        KEY idx_leave_employee (employee_id),
        KEY idx_leave_status (status),
        KEY idx_leave_dates (start_date, end_date),
        CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        CONSTRAINT fk_leave_reviewer FOREIGN KEY (reviewed_by) REFERENCES employees(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function leaveUser(PDO $pdo): array {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        http_response_code(401);
        leaveRespond(false, 'Your session has expired. Please sign in again.');
    }
    $stmt = $pdo->prepare('SELECT id, full_name, email, position, status FROM employees WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'approved') {
        http_response_code(403);
        leaveRespond(false, 'Your employee account is not active.');
    }
    return $user;
}

function leaveIsPrivileged(string $position): bool {
    return $position === 'Administrator' || stripos($position, 'HR') !== false || strcasecmp($position, 'Human Resources') === 0;
}

function leaveDepartment(string $position): string {
    $value = strtolower($position);
    if (strpos($value, 'cook') !== false || strpos($value, 'chef') !== false || strpos($value, 'kitchen') !== false) return 'Kitchen';
    if (strpos($value, 'manager') !== false || strpos($value, 'supervisor') !== false) return 'Management';
    if (strpos($value, 'finance') !== false || strpos($value, 'admin') !== false) return 'Admin & Finance';
    if (strpos($value, 'maintenance') !== false) return 'Maintenance';
    return 'Operations';
}

function leaveRow(array $row): array {
    return [
        'id' => (int)$row['id'],
        'reference_code' => $row['reference_code'],
        'full_name' => $row['full_name'] ?? null,
        'email' => $row['email'] ?? null,
        'position' => $row['position'] ?? null,
        'department' => isset($row['position']) ? leaveDepartment($row['position']) : null,
        'leave_type' => $row['leave_type'],
        'pay_type' => $row['pay_type'],
        'start_date' => $row['start_date'],
        'end_date' => $row['end_date'],
        'days' => (float)$row['days'],
        'contact_no' => $row['contact_no'],
        'reason' => $row['reason'],
        'leave_during' => $row['leave_during'],
        'comments' => $row['comments'],
        'status' => $row['status'],
        'reviewed_at' => $row['reviewed_at'],
        'created_at' => $row['created_at'],
    ];
}

function leaveStats(array $requests): array {
    $stats = ['total' => count($requests), 'pending' => 0, 'approved' => 0, 'rejected' => 0];
    foreach ($requests as $request) {
        if (isset($stats[$request['status']])) $stats[$request['status']]++;
    }
    return $stats;
}

ensureLeaveSchema($pdo);
$user = leaveUser($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'mine';

if ($action === 'mine') {
    if (leaveIsPrivileged($user['position'])) {
        http_response_code(403);
        leaveRespond(false, 'Leave requests are for employee accounts only.');
    }
    $stmt = $pdo->prepare('SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$user['id']]);
    $requests = array_map('leaveRow', $stmt->fetchAll());
    leaveRespond(true, '', ['requests' => $requests, 'stats' => leaveStats($requests)]);
}

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (leaveIsPrivileged($user['position'])) {
        http_response_code(403);
        leaveRespond(false, 'Leave requests are for employee accounts only.');
    }
    $leaveType = trim($_POST['leave_type'] ?? '');
    $payType = trim($_POST['pay_type'] ?? '');
    $start = trim($_POST['start_date'] ?? '');
    $end = trim($_POST['end_date'] ?? '');
    $contact = trim($_POST['contact_no'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $during = trim($_POST['leave_during'] ?? '');
    $comments = trim($_POST['comments'] ?? '');
    $allowedTypes = ['vacation', 'sick', 'personal', 'emergency', 'maternity'];
    $allowedPay = ['with-pay', 'without-pay'];
    $allowedDuring = ['whole-day', 'half-day-am', 'half-day-pm'];
    if (!in_array($leaveType, $allowedTypes, true) || !in_array($payType, $allowedPay, true) || !in_array($during, $allowedDuring, true)) {
        leaveRespond(false, 'Please select valid leave details.');
    }
    if (!preg_match('/^\d{11}$/', $contact)) leaveRespond(false, 'Contact number must be exactly 11 digits.');
    if ($reason === '' || mb_strlen($reason) > 2000 || mb_strlen($comments) > 2000) leaveRespond(false, 'Please provide a valid reason and comments.');
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end || $endDate < $startDate) {
        leaveRespond(false, 'Please select a valid date range.');
    }
    $today = new DateTimeImmutable('today');
    if ($startDate < $today) leaveRespond(false, 'The leave start date cannot be in the past.');
    if ($during !== 'whole-day' && $start !== $end) leaveRespond(false, 'Half-day leave must use the same start and end date.');
    $days = $during === 'whole-day' ? (float)($startDate->diff($endDate)->days + 1) : 0.5;

    $overlap = $pdo->prepare("SELECT id FROM leave_requests WHERE employee_id = ? AND status IN ('pending','approved') AND start_date <= ? AND end_date >= ? LIMIT 1");
    $overlap->execute([$user['id'], $end, $start]);
    if ($overlap->fetch()) leaveRespond(false, 'You already have a pending or approved leave request for these dates.');

    try {
        $reference = 'LR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare('INSERT INTO leave_requests (employee_id, reference_code, leave_type, pay_type, start_date, end_date, days, contact_no, reason, leave_during, comments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$user['id'], $reference, $leaveType, $payType, $start, $end, $days, $contact, $reason, $during, $comments !== '' ? $comments : null]);
        leaveRespond(true, 'Leave request submitted for administrator review.', ['reference_code' => $reference, 'request_id' => (int)$pdo->lastInsertId()]);
    } catch (Throwable $error) {
        http_response_code(500);
        leaveRespond(false, 'Leave request could not be submitted. Please try again.');
    }
}

if ($action === 'admin') {
    if ($user['position'] !== 'Administrator') {
        http_response_code(403);
        leaveRespond(false, 'Administrator access is required.');
    }
    $stmt = $pdo->query('SELECT l.*, e.full_name, e.email, e.position FROM leave_requests l JOIN employees e ON e.id = l.employee_id ORDER BY l.created_at DESC, l.id DESC');
    $requests = array_map('leaveRow', $stmt->fetchAll());
    leaveRespond(true, '', ['requests' => $requests, 'stats' => leaveStats($requests)]);
}

if ($action === 'decision' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user['position'] !== 'Administrator') {
        http_response_code(403);
        leaveRespond(false, 'Administrator access is required.');
    }
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = trim($_POST['decision'] ?? '');
    if (!$requestId || !in_array($decision, ['approved', 'rejected'], true)) leaveRespond(false, 'Invalid leave decision.');
    $stmt = $pdo->prepare("UPDATE leave_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
    $stmt->execute([$decision, $user['id'], $requestId]);
    if ($stmt->rowCount() !== 1) leaveRespond(false, 'This leave request was already processed or does not exist.');
    leaveRespond(true, 'Leave request ' . $decision . ' successfully.');
}

http_response_code(400);
leaveRespond(false, 'Invalid leave request action.');
