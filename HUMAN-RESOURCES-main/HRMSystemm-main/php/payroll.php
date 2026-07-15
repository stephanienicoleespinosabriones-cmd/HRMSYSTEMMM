<?php
require_once 'config.php';
require_once __DIR__ . '/attendance_settings.php';
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');

function payrollRespond(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function payrollUser(PDO $pdo): array {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        http_response_code(401);
        payrollRespond(false, 'Your login session has expired. Please sign in again.');
    }
    $stmt = $pdo->prepare("SELECT id, full_name, email, position, status FROM employees WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'approved') {
        http_response_code(403);
        payrollRespond(false, 'This account is not active.');
    }
    return $user;
}

function payrollEnsureSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        work_date DATE NOT NULL,
        clock_in DATETIME NOT NULL,
        break_start DATETIME NULL,
        break_end DATETIME NULL,
        clock_out DATETIME NULL,
        status ENUM('on_time','late') NOT NULL DEFAULT 'on_time',
        day_status ENUM('in_progress','full_day','half_day','undertime') NOT NULL DEFAULT 'in_progress',
        approval_status ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_attendance_employee_date (employee_id, work_date),
        KEY idx_attendance_date (work_date),
        CONSTRAINT fk_attendance_employee_payroll FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'day_status' => "ENUM('in_progress','full_day','half_day','undertime') NOT NULL DEFAULT 'in_progress' AFTER status",
        'approval_status' => "ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required' AFTER day_status"
    ] as $column => $definition) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = ?");
        $check->execute([$column]);
        if (!$check->fetchColumn()) $pdo->exec("ALTER TABLE attendance_records ADD COLUMN {$column} {$definition}");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        period VARCHAR(10) NOT NULL,
        monthly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
        basic_pay DECIMAL(10,2) NOT NULL DEFAULT 0,
        rice_allowance DECIMAL(10,2) NOT NULL DEFAULT 0,
        transport_allowance DECIMAL(10,2) NOT NULL DEFAULT 0,
        overtime_pay DECIMAL(10,2) NOT NULL DEFAULT 0,
        gross_pay DECIMAL(10,2) NOT NULL DEFAULT 0,
        sss DECIMAL(10,2) NOT NULL DEFAULT 0,
        philhealth DECIMAL(10,2) NOT NULL DEFAULT 0,
        pagibig DECIMAL(10,2) NOT NULL DEFAULT 0,
        withholding_tax DECIMAL(10,2) NOT NULL DEFAULT 0,
        absence_deduction DECIMAL(10,2) NOT NULL DEFAULT 0,
        unpaid_leave_deduction DECIMAL(10,2) NOT NULL DEFAULT 0,
        late_deduction DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_deductions DECIMAL(10,2) NOT NULL DEFAULT 0,
        net_pay DECIMAL(10,2) NOT NULL DEFAULT 0,
        days_present INT NOT NULL DEFAULT 0,
        late_count INT NOT NULL DEFAULT 0,
        late_minutes INT NOT NULL DEFAULT 0,
        undertime_minutes INT NOT NULL DEFAULT 0,
        overtime_minutes INT NOT NULL DEFAULT 0,
        approved_worked_seconds INT NOT NULL DEFAULT 0,
        working_days INT NOT NULL DEFAULT 0,
        status ENUM('issued') NOT NULL DEFAULT 'issued',
        issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_employee_period (employee_id, period),
        CONSTRAINT payroll_employee_fk FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $periodColumn = $pdo->query("SHOW COLUMNS FROM payroll_records LIKE 'period'")->fetch();
    if ($periodColumn && stripos($periodColumn['Type'], 'char(7)') !== false) {
        $pdo->exec("ALTER TABLE payroll_records MODIFY period VARCHAR(10) NOT NULL");
    }
    $unpaidLeaveColumn = $pdo->query("SHOW COLUMNS FROM payroll_records LIKE 'unpaid_leave_deduction'")->fetch();
    if (!$unpaidLeaveColumn) {
        $pdo->exec("ALTER TABLE payroll_records ADD COLUMN unpaid_leave_deduction DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER absence_deduction");
        $pdo->exec("UPDATE payroll_records SET unpaid_leave_deduction = GREATEST(0, total_deductions - (sss + philhealth + pagibig + withholding_tax + absence_deduction + late_deduction))");
    }
    foreach ([
        'late_minutes' => 'INT NOT NULL DEFAULT 0 AFTER late_count',
        'undertime_minutes' => 'INT NOT NULL DEFAULT 0 AFTER late_minutes',
        'overtime_minutes' => 'INT NOT NULL DEFAULT 0 AFTER undertime_minutes',
        'approved_worked_seconds' => 'INT NOT NULL DEFAULT 0 AFTER overtime_minutes'
    ] as $column => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM payroll_records LIKE " . $pdo->quote($column))->fetch();
        if (!$check) $pdo->exec("ALTER TABLE payroll_records ADD COLUMN {$column} {$definition}");
    }
}

function payrollCurrentPeriod(): string {
    $today = new DateTime('today');
    $day = (int)$today->format('j');
    $lastDay = (int)$today->format('t');
    if ($day >= $lastDay) return $today->format('Y-m') . '-30';
    if ($day >= 15) return $today->format('Y-m') . '-15';
    return $today->modify('first day of last month')->format('Y-m') . '-30';
}

function payrollPeriod(string $period): string {
    if (preg_match('/^\d{4}-\d{2}-(15|30)$/', $period)) return $period;
    if (preg_match('/^\d{4}-\d{2}$/', $period)) {
        $latest = payrollCurrentPeriod();
        return str_starts_with($latest, $period) ? $latest : $period . '-30';
    }
    return payrollCurrentPeriod();
}

function payrollPeriodLabel(string $period): string {
    if (preg_match('/^(\d{4}-\d{2})-(15|30)$/', $period, $match)) {
        $date = new DateTime($match[1] . '-01');
        $cutoff = $match[2] === '15' ? '1-15' : '16-' . $date->format('t');
        return $date->format('F Y') . ' (' . $cutoff . ')';
    }
    if (preg_match('/^\d{4}-\d{2}$/', $period)) return date('F Y', strtotime($period . '-01'));
    return $period;
}

function payrollCutoffDate(string $period): ?DateTime {
    if (!preg_match('/^(\d{4}-\d{2})-(15|30)$/', $period, $match)) return null;
    if ($match[2] === '15') return new DateTime($match[1] . '-15');
    return (new DateTime($match[1] . '-01'))->modify('last day of this month');
}

function payrollPeriodClosed(string $period): bool {
    $cutoffDate = payrollCutoffDate($period);
    if (!$cutoffDate) return false;
    return new DateTime('today') >= $cutoffDate;
}

function payrollRate(string $position): float {
    $value = strtolower($position);
    if (strpos($value, 'barista') !== false) return 16000.00;
    if (strpos($value, 'cashier') !== false) return 15000.00;
    return 14000.00;
}

function payrollDepartment(string $position): string {
    $value = strtolower($position);
    if (strpos($value, 'cook') !== false || strpos($value, 'chef') !== false || strpos($value, 'kitchen') !== false) return 'Kitchen';
    if (strpos($value, 'manager') !== false || strpos($value, 'supervisor') !== false) return 'Management';
    if (strpos($value, 'finance') !== false || strpos($value, 'admin') !== false) return 'Admin & Finance';
    if (strpos($value, 'maintenance') !== false) return 'Maintenance';
    return 'Operations';
}

function payrollBusinessDays(DateTime $start, DateTime $end): int {
    $count = 0;
    $dayOffIso = (int)attendanceSettings()['day_off_iso'];
    while ($start <= $end) {
        if ((int)$start->format('N') !== $dayOffIso) $count++;
        $start->modify('+1 day');
    }
    return $count;
}

function payrollPeriodBounds(string $period, ?string $joinedAt = null): array {
    if (preg_match('/^(\d{4}-\d{2})-(15|30)$/', $period, $match)) {
        $month = $match[1];
        if ($match[2] === '15') {
            $start = new DateTime($month . '-01');
            $end = new DateTime($month . '-15');
        } else {
            $start = new DateTime($month . '-16');
            $end = (new DateTime($month . '-01'))->modify('last day of this month');
        }
    } else {
        $start = new DateTime($period . '-01');
        $end = (clone $start)->modify('last day of this month');
    }
    $today = new DateTime('today');
    if (substr($period, 0, 7) === date('Y-m') && $end > $today) $end = clone $today;
    if ($joinedAt) {
        $joined = new DateTime(substr($joinedAt, 0, 10));
        if ($joined > $start) $start = $joined;
    }
    return [$start, $end];
}

function payrollWorkingDays(string $period, ?string $joinedAt = null): int {
    [$start, $end] = payrollPeriodBounds($period, $joinedAt);
    return max(0, payrollBusinessDays($start, $end));
}

function payrollTax(float $taxablePay): float {
    if ($taxablePay <= 20833) return 0.00;
    if ($taxablePay <= 33333) return ($taxablePay - 20833) * 0.15;
    if ($taxablePay <= 66667) return 1875 + (($taxablePay - 33333) * 0.20);
    return 8541.80 + (($taxablePay - 66667) * 0.25);
}

function payrollComputeRow(array $employee, array $attendance, array $leaves, string $period): array {
    $workingDays = payrollWorkingDays($period, $employee['created_at'] ?? null);
    $monthlyRate = payrollRate($employee['position']);
    $monthStart = new DateTime(substr($period, 0, 7) . '-01');
    $monthlyWorkingDays = max(1, payrollBusinessDays(clone $monthStart, (clone $monthStart)->modify('last day of this month')));
    $dailyRate = $monthlyRate / $monthlyWorkingDays;
    $present = min($workingDays, (int)($attendance['days_present'] ?? 0));
    $late = (int)($attendance['late_count'] ?? 0);
    $lateMinutes = (int)($attendance['late_minutes'] ?? 0);
    $undertimeMinutes = (int)($attendance['undertime_minutes'] ?? 0);
    $overtimeMinutes = (int)($attendance['overtime_minutes'] ?? 0);
    $approvedWorkedSeconds = (int)($attendance['approved_worked_seconds'] ?? 0);
    $paidLeaveDays = min($workingDays, (float)($leaves['paid_leave_days'] ?? 0));
    $unpaidLeaveDays = min($workingDays, (float)($leaves['unpaid_leave_days'] ?? 0));
    $paidDays = min($workingDays, $present + $paidLeaveDays);
    $absence = max(0, $workingDays - $present - $paidLeaveDays);
    $basic = round($dailyRate * $paidDays, 2);
    $allowanceRatio = $workingDays > 0 ? ($paidDays / $workingDays) : 0;
    $rice = round(1000.00 * $allowanceRatio, 2);
    $transport = round(800.00 * $allowanceRatio, 2);
    $minuteRate = $dailyRate / 480;
    $overtime = round($overtimeMinutes * $minuteRate * 1.25, 2);
    $gross = round($basic + $rice + $transport + $overtime, 2);
    $sss = $gross > 0 ? round(min($gross, $monthlyRate) * 0.045, 2) : 0.00;
    $philhealth = $gross > 0 ? round(min($gross, $monthlyRate) * 0.025, 2) : 0.00;
    $pagibig = $gross > 0 ? min(100.00, round(min($gross, $monthlyRate) * 0.02, 2)) : 0.00;
    $tax = round(payrollTax($gross), 2);
    $absenceDeduction = round($dailyRate * max(0, $absence - $unpaidLeaveDays), 2);
    $unpaidLeaveDeduction = round($dailyRate * $unpaidLeaveDays, 2);
    $lateDeduction = round(($lateMinutes + $undertimeMinutes) * $minuteRate, 2);
    $deductions = min($gross, round($sss + $philhealth + $pagibig + $tax + $absenceDeduction + $unpaidLeaveDeduction + $lateDeduction, 2));
    $net = max(0, round($gross - $deductions, 2));

    return [
        'employee_id' => (int)$employee['id'], 'full_name' => $employee['full_name'], 'email' => $employee['email'],
        'position' => $employee['position'], 'department' => payrollDepartment($employee['position']), 'period' => $period, 'monthly_rate' => $monthlyRate,
        'basic_pay' => $basic, 'rice_allowance' => $rice, 'transport_allowance' => $transport, 'overtime_pay' => $overtime,
        'gross_pay' => $gross, 'sss' => $sss, 'philhealth' => $philhealth, 'pagibig' => $pagibig,
        'withholding_tax' => $tax, 'absence_deduction' => $absenceDeduction, 'unpaid_leave_deduction' => $unpaidLeaveDeduction, 'late_deduction' => $lateDeduction,
        'total_deductions' => $deductions, 'net_pay' => $net, 'days_present' => $present,
        'paid_leave_days' => $paidLeaveDays, 'unpaid_leave_days' => $unpaidLeaveDays,
        'late_count' => $late, 'late_minutes' => $lateMinutes, 'undertime_minutes' => $undertimeMinutes,
        'overtime_minutes' => $overtimeMinutes, 'approved_worked_seconds' => $approvedWorkedSeconds,
        'working_days' => $workingDays, 'status' => 'pending'
    ];
}

function payrollEmployees(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, full_name, email, position, created_at FROM employees WHERE status = 'approved' AND position <> 'Administrator' AND position NOT LIKE '%HR%' ORDER BY full_name");
    return $stmt->fetchAll();
}

function payrollAttendance(PDO $pdo, string $period): array {
    [$start, $end] = payrollPeriodBounds($period);
    $stmt = $pdo->prepare("SELECT employee_id, COUNT(*) AS days_present, SUM(status = 'late') AS late_count,
        COALESCE(SUM(late_minutes), 0) AS late_minutes,
        COALESCE(SUM(undertime_minutes), 0) AS undertime_minutes,
        COALESCE(SUM(overtime_minutes), 0) AS overtime_minutes,
        COALESCE(SUM(worked_seconds), 0) AS approved_worked_seconds
        FROM attendance_records
        WHERE work_date BETWEEN ? AND ? AND approval_status = 'approved'
        GROUP BY employee_id");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(int)$row['employee_id']] = $row;
    }
    return $rows;
}

function payrollLeaves(PDO $pdo, string $period): array {
    $table = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests'")->fetchColumn();
    if (!$table) return [];
    [$periodStart, $periodEnd] = payrollPeriodBounds($period);
    $stmt = $pdo->prepare("SELECT employee_id, leave_type, pay_type, start_date, end_date, days, leave_during
        FROM leave_requests
        WHERE status = 'approved' AND start_date <= ? AND end_date >= ?");
    $stmt->execute([$periodEnd->format('Y-m-d'), $periodStart->format('Y-m-d')]);
    $rows = [];
    foreach ($stmt->fetchAll() as $leave) {
        $start = new DateTime($leave['start_date']) > $periodStart ? new DateTime($leave['start_date']) : clone $periodStart;
        $end = new DateTime($leave['end_date']) < $periodEnd ? new DateTime($leave['end_date']) : clone $periodEnd;
        $days = $leave['leave_during'] === 'whole-day' ? payrollBusinessDays($start, $end) : 0.5;
        $key = $leave['pay_type'] === 'with-pay' ? 'paid_leave_days' : 'unpaid_leave_days';
        $employeeId = (int)$leave['employee_id'];
        if (!isset($rows[$employeeId])) $rows[$employeeId] = ['paid_leave_days' => 0, 'unpaid_leave_days' => 0];
        $rows[$employeeId][$key] += $days;
    }
    return $rows;
}

function payrollSaved(PDO $pdo, string $period): array {
    $stmt = $pdo->prepare("SELECT * FROM payroll_records WHERE period = ?");
    $stmt->execute([$period]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(int)$row['employee_id']] = $row;
    }
    return $rows;
}

function payrollSummary(array $records): array {
    $total = array_sum(array_map(static fn($row) => (float)$row['net_pay'], $records));
    $gross = array_sum(array_map(static fn($row) => (float)$row['gross_pay'], $records));
    $deductions = array_sum(array_map(static fn($row) => (float)$row['total_deductions'], $records));
    $count = count($records);
    return ['total_net' => round($total, 2), 'total_gross' => round($gross, 2), 'total_deductions' => round($deductions, 2), 'employees' => $count, 'average_net' => $count ? round($total / $count, 2) : 0];
}

$user = payrollUser($pdo);
payrollEnsureSchema($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'preview';

if ($action === 'preview') {
    if ($user['position'] !== 'Administrator') {
        http_response_code(403);
        payrollRespond(false, 'Administrator access is required.');
    }
    $period = payrollPeriod($_GET['period'] ?? payrollCurrentPeriod());
    $employees = payrollEmployees($pdo);
    $attendance = payrollAttendance($pdo, $period);
    $leaves = payrollLeaves($pdo, $period);
    $saved = payrollSaved($pdo, $period);
    $records = [];
    foreach ($employees as $employee) {
        $computed = payrollComputeRow($employee, $attendance[(int)$employee['id']] ?? [], $leaves[(int)$employee['id']] ?? [], $period);
        if (isset($saved[(int)$employee['id']])) {
            // Issued payroll is immutable display data: Admin and Employee must read the exact same saved figures.
            $computed = array_merge($computed, $saved[(int)$employee['id']]);
            $computed['full_name'] = $employee['full_name'];
            $computed['email'] = $employee['email'];
            $computed['position'] = $employee['position'];
            $computed['department'] = payrollDepartment($employee['position']);
        }
        $records[] = $computed;
    }
    payrollRespond(true, '', ['period' => $period, 'period_label' => payrollPeriodLabel($period), 'records' => $records, 'summary' => payrollSummary($records)]);
}

if ($action === 'issue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user['position'] !== 'Administrator') {
        http_response_code(403);
        payrollRespond(false, 'Administrator access is required.');
    }
    $period = payrollPeriod($_POST['period'] ?? payrollCurrentPeriod());
    if (!payrollPeriodClosed($period)) {
        $cutoffDate = payrollCutoffDate($period);
        payrollRespond(false, 'Payroll for ' . payrollPeriodLabel($period) . ' cannot be issued yet. Cutoff date is ' . $cutoffDate->format('F j, Y') . '.');
    }
    $employees = payrollEmployees($pdo);
    $attendance = payrollAttendance($pdo, $period);
    $leaves = payrollLeaves($pdo, $period);
    $stmt = $pdo->prepare("INSERT INTO payroll_records (employee_id, period, monthly_rate, basic_pay, rice_allowance, transport_allowance, overtime_pay, gross_pay, sss, philhealth, pagibig, withholding_tax, absence_deduction, unpaid_leave_deduction, late_deduction, total_deductions, net_pay, days_present, late_count, late_minutes, undertime_minutes, overtime_minutes, approved_worked_seconds, working_days, status, issued_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', NOW())
        ON DUPLICATE KEY UPDATE monthly_rate = VALUES(monthly_rate), basic_pay = VALUES(basic_pay), rice_allowance = VALUES(rice_allowance), transport_allowance = VALUES(transport_allowance), overtime_pay = VALUES(overtime_pay), gross_pay = VALUES(gross_pay), sss = VALUES(sss), philhealth = VALUES(philhealth), pagibig = VALUES(pagibig), withholding_tax = VALUES(withholding_tax), absence_deduction = VALUES(absence_deduction), unpaid_leave_deduction = VALUES(unpaid_leave_deduction), late_deduction = VALUES(late_deduction), total_deductions = VALUES(total_deductions), net_pay = VALUES(net_pay), days_present = VALUES(days_present), late_count = VALUES(late_count), late_minutes = VALUES(late_minutes), undertime_minutes = VALUES(undertime_minutes), overtime_minutes = VALUES(overtime_minutes), approved_worked_seconds = VALUES(approved_worked_seconds), working_days = VALUES(working_days), status = 'issued', issued_at = NOW()");
    foreach ($employees as $employee) {
        $row = payrollComputeRow($employee, $attendance[(int)$employee['id']] ?? [], $leaves[(int)$employee['id']] ?? [], $period);
        $stmt->execute([$row['employee_id'], $period, $row['monthly_rate'], $row['basic_pay'], $row['rice_allowance'], $row['transport_allowance'], $row['overtime_pay'], $row['gross_pay'], $row['sss'], $row['philhealth'], $row['pagibig'], $row['withholding_tax'], $row['absence_deduction'], $row['unpaid_leave_deduction'], $row['late_deduction'], $row['total_deductions'], $row['net_pay'], $row['days_present'], $row['late_count'], $row['late_minutes'], $row['undertime_minutes'], $row['overtime_minutes'], $row['approved_worked_seconds'], $row['working_days']]);
    }
    payrollRespond(true, 'Payroll for ' . payrollPeriodLabel($period) . ' was computed and issued. Employees can now view their payslip copy.');
}

if ($action === 'mine') {
    $stmt = $pdo->prepare("SELECT pr.*, e.full_name, e.email, e.position
        FROM payroll_records pr
        JOIN employees e ON e.id = pr.employee_id
        WHERE pr.employee_id = ?
          AND pr.status = 'issued'
          AND pr.period REGEXP '^[0-9]{4}-[0-9]{2}-(15|30)$'
        ORDER BY pr.period DESC");
    $stmt->execute([(int)$user['id']]);
    $records = array_values(array_filter($stmt->fetchAll(), static fn($row) => payrollPeriodClosed($row['period'])));
    payrollRespond(true, '', ['records' => $records]);
}

http_response_code(400);
payrollRespond(false, 'Invalid payroll action.');
