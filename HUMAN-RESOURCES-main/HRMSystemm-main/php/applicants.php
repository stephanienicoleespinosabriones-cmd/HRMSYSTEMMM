<?php
require_once 'config.php';
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');

function respond(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function requireRole(string $role): void {
    global $pdo;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        http_response_code(401);
        respond(false, 'Your login session has expired. Please sign in again.');
    }
    $userStmt = $pdo->prepare("SELECT position, status FROM employees WHERE id = ? LIMIT 1");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user || $user['status'] !== 'approved') {
        http_response_code(403);
        respond(false, 'Your account is not active or could not be verified. Please sign in again.');
    }
    $currentRole = trim($user['position']);
    $_SESSION['user_role'] = $currentRole;
    $allowed = $role === 'admin'
        ? $currentRole === 'Administrator'
        : ($currentRole === 'Administrator' || stripos($currentRole, 'HR') !== false || strcasecmp($currentRole, 'Human Resources') === 0);
    if (!$allowed) {
        http_response_code(403);
        respond(false, $role === 'hr' ? 'This action requires an active HR or Administrator account.' : 'This action requires an Administrator account.');
    }
}

function normalizedHiringPosition(string $position): string {
    $value = strtolower(trim($position));
    if (strpos($value, 'barista') !== false) return 'barista';
    if (strpos($value, 'cashier') !== false) return 'cashier';
    return $value;
}

function isPositionAvailable(PDO $pdo, string $position): bool {
    $normalized = normalizedHiringPosition($position);
    if (!in_array($normalized, ['barista', 'cashier'], true)) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE status = 'approved' AND LOWER(position) LIKE ?");
    $stmt->execute(['%' . $normalized . '%']);
    return (int)$stmt->fetchColumn() < 4;
}

function findOpenJobPost(PDO $pdo, string $position): ?array {
    $normalized = normalizedHiringPosition($position);
    $table = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_posts'")->fetchColumn();
    if (!$table) return null;
    $stmt = $pdo->prepare("SELECT id, title FROM job_posts WHERE position_key = ? AND status = 'published' ORDER BY published_at DESC, id DESC LIMIT 1");
    $stmt->execute([$normalized]);
    $post = $stmt->fetch();
    return $post ?: null;
}

function ensureApplicantSchema(PDO $pdo): void {
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'availability'");
    $check->execute();
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE applicants ADD COLUMN availability ENUM('available','not-available') NOT NULL DEFAULT 'available' AFTER employment_type");
    } else {
        $pdo->exec("ALTER TABLE applicants MODIFY availability ENUM('available','not-available') NOT NULL DEFAULT 'available'");
    }
    $resultEmail = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicants' AND COLUMN_NAME = 'result_email_sent_at'");
    $resultEmail->execute();
    if (!$resultEmail->fetchColumn()) {
        $pdo->exec("ALTER TABLE applicants ADD COLUMN result_email_sent_at DATETIME NULL AFTER email_sent_at");
    }
}

ensureApplicantSchema($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'track' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = strtoupper(trim($_POST['application_code'] ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    if ($code === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(false, 'Please enter your Application ID and the email address used in your application.');
    $stmt = $pdo->prepare("SELECT application_code, first_name, last_name, position, status, interview_date, interview_time, interview_location, created_at, updated_at FROM applicants WHERE application_code = ? AND LOWER(email) = ? LIMIT 1");
    $stmt->execute([$code, $email]);
    $applicant = $stmt->fetch();
    if (!$applicant) { http_response_code(404); respond(false, 'No application matched those details. Check your Application ID and email, then try again.'); }
    $statuses = [
        'pending' => ['Pending review', 'Your application has been received and is waiting for HR review.'],
        'initial_interview_pending' => ['Qualified for initial interview', 'HR reviewed your application. Your initial interview schedule is being prepared.'],
        'initial_interview_scheduled' => ['Initial interview scheduled', 'Review the schedule below and check your email for the invitation.'],
        'final_interview_pending' => ['Initial interview passed', 'You passed the initial interview. HR is preparing your final interview schedule.'],
        'final_interview_scheduled' => ['Final interview scheduled', 'Review the schedule below and check your email for the invitation.'],
        'final_interview_passed' => ['Selected for hiring', 'Congratulations! You passed the final interview. HR will contact you about onboarding.'],
        'hired' => ['Hired', 'Congratulations and welcome to Quadra Cafe. Please follow the onboarding instructions from HR.'],
        'hr_rejected' => ['Application not selected', 'Thank you for applying. Your application was not selected to continue at this time.'],
        'admin_rejected' => ['Application not selected', 'Thank you for applying. Your application was not selected to continue at this time.'],
        'rejected' => ['Application not selected', 'Thank you for applying. Your application was not selected to continue at this time.'],
        'interview_failed' => ['Application not selected', 'Thank you for your time. The application will not continue to the next stage.'],
    ];
    [$label, $message] = $statuses[$applicant['status']] ?? ['Application in progress', 'Your application is still being processed.'];
    $updated = new DateTime($applicant['updated_at'] ?: $applicant['created_at']);
    $waitingDays = max(0, (int)$updated->diff(new DateTime())->format('%a'));
    if (in_array($applicant['status'], ['pending', 'initial_interview_pending', 'final_interview_pending'], true) && $waitingDays >= 7) $message .= ' We apologize for the wait. Your application remains active; HR has been flagged to provide an update.';
    respond(true, '', ['application' => [
        'application_code' => $applicant['application_code'], 'applicant_name' => trim($applicant['first_name'] . ' ' . $applicant['last_name']),
        'position' => $applicant['position'], 'status' => $applicant['status'], 'status_label' => $label, 'message' => $message,
        'submitted_at' => $applicant['created_at'], 'updated_at' => $applicant['updated_at'], 'waiting_days' => $waitingDays,
        'interview_date' => $applicant['interview_date'], 'interview_time' => $applicant['interview_time'], 'interview_location' => $applicant['interview_location'],
    ]]);
}

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['first_name', 'last_name', 'birthdate', 'gender', 'civil_status', 'email', 'contact_no', 'address', 'position', 'years_experience', 'education', 'school', 'course', 'year_graduated', 'high_school', 'high_school_year'];
    $data = [];
    foreach ($fields as $field) {
        $data[$field] = trim($_POST[$field] ?? '');
        if ($data[$field] === '') respond(false, 'Please complete all required application fields.');
    }
    $data['employment_type'] = 'full-time';
    $data['preferred_shift'] = '8:00 AM - 5:00 PM';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) respond(false, 'Please enter a valid email address.');
    if (!preg_match('/^\d{11}$/', $data['contact_no'])) respond(false, 'Contact number must be exactly 11 digits.');
    if (!isPositionAvailable($pdo, $data['position'])) respond(false, 'This position is not available right now. Please choose an available Barista or Cashier opening.');
    $jobPost = findOpenJobPost($pdo, $data['position']);
    if (!$jobPost) respond(false, 'This job posting is no longer open. Please refresh the application page and choose an active opening.');

    if (empty($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) respond(false, 'Please attach a resume file.');
    $resume = $_FILES['resume'];
    if ($resume['size'] > 5 * 1024 * 1024) respond(false, 'Resume file must not exceed 5 MB.');
    $extension = strtolower(pathinfo($resume['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'doc', 'docx', 'jpg', 'jpeg'], true)) respond(false, 'Resume must be a PDF, DOC, DOCX, JPG, or JPEG file.');
    $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'resumes';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) respond(false, 'Unable to prepare resume storage.');
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($resume['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $storedName)) respond(false, 'Unable to save the resume file.');

    $code = 'QC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $resumePath = 'uploads/resumes/' . $storedName;
    $stmt = $pdo->prepare('INSERT INTO applicants (application_code, job_post_id, first_name, middle_name, last_name, birthdate, gender, civil_status, email, contact_no, address, position, employment_type, availability, years_experience, preferred_shift, education, school, course, year_graduated, high_school, high_school_year, honors, resume_name, resume_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$code, $jobPost['id'], $data['first_name'], trim($_POST['middle_name'] ?? ''), $data['last_name'], $data['birthdate'], $data['gender'], $data['civil_status'], $data['email'], $data['contact_no'], $data['address'], $data['position'], $data['employment_type'], 'available', $data['years_experience'], $data['preferred_shift'], $data['education'], $data['school'], $data['course'], $data['year_graduated'], $data['high_school'], $data['high_school_year'], trim($_POST['honors'] ?? ''), basename($resume['name']), $resumePath]);
    respond(true, 'Application submitted successfully.', ['application_code' => $code]);
}

if ($action === 'list') {
    $view = $_GET['view'] ?? '';
    if ($view === 'hr') {
        requireRole('hr');
        $stmt = $pdo->query("SELECT applicants.*, employees.status AS employee_status
                             FROM applicants
                             LEFT JOIN employees ON employees.id = applicants.employee_id
                             ORDER BY applicants.created_at DESC");
    } elseif ($view === 'admin') {
        requireRole('admin');
        respond(true, '', ['applicants' => []]);
    } else {
        respond(false, 'Invalid applicant view.');
    }
    respond(true, '', ['applicants' => $stmt->fetchAll()]);
}

if ($action === 'update_availability' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('hr');
    $id = (int)($_POST['id'] ?? 0);
    $availability = trim($_POST['availability'] ?? '');
    if ($id < 1 || !in_array($availability, ['available', 'not-available'], true)) {
        respond(false, 'Please select Available or Not Available.');
    }
    $stmt = $pdo->prepare("UPDATE applicants SET availability = ? WHERE id = ?");
    $stmt->execute([$availability, $id]);
    if ($stmt->rowCount() < 1) respond(false, 'Applicant availability was not changed.');
    respond(true, 'Applicant availability updated.');
}

if ($action === 'hr_decision' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('hr');
    $id = (int)($_POST['id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    if ($id < 1 || !in_array($decision, ['qualified', 'rejected'], true)) respond(false, 'Invalid applicant decision.');
    $status = $decision === 'qualified' ? 'initial_interview_pending' : 'hr_rejected';
    $availability = $decision === 'qualified' ? 'available' : 'not-available';
    $stmt = $pdo->prepare("UPDATE applicants SET status = ?, availability = ?, hr_remarks = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([$status, $availability, trim($_POST['remarks'] ?? ''), $id]);
    if ($stmt->rowCount() !== 1) respond(false, 'Only pending applications can be processed by HR.');
    respond(true, $decision === 'qualified' ? 'Applicant accepted. HR may now schedule the initial interview.' : 'Applicant has been rejected.');
}

if ($action === 'schedule_interview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('hr');
    $id = (int)($_POST['id'] ?? 0);
    $stage = $_POST['stage'] ?? '';
    if ($id < 1 || !in_array($stage, ['initial', 'final'], true)) respond(false, 'Invalid interview stage.');
    $date = trim($_POST['interview_date'] ?? '');
    $time = trim($_POST['interview_time'] ?? '');
    $location = trim($_POST['interview_location'] ?? '');
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date || !$time || !$location) respond(false, 'Please provide the initial interview date, time, and location.');
    $interviewDateTime = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    if (!$interviewDateTime) respond(false, 'Please enter a valid interview date and time.');
    $now = new DateTime();
    if ($interviewDateTime <= $now) respond(false, 'The interview schedule must be in the future.');
    $maxInterviewDate = (clone $now)->modify('+7 days')->setTime(23, 59, 59);
    if ($interviewDateTime > $maxInterviewDate) respond(false, 'Interview schedule must be within 1 week from today only.');

    $requiredStatus = $stage === 'initial' ? 'initial_interview_pending' : 'final_interview_pending';
    $scheduledStatus = $stage === 'initial' ? 'initial_interview_scheduled' : 'final_interview_scheduled';
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, position, availability FROM applicants WHERE id = ? AND status = ?");
    $stmt->execute([$id, $requiredStatus]);
    $applicant = $stmt->fetch();
    if (!$applicant) respond(false, 'The applicant is not ready for this interview stage.');
    if (($applicant['availability'] ?? '') === 'not-available') respond(false, 'This applicant is marked Not Available and cannot be scheduled for interview yet.');

    $stageLabel = ucfirst($stage);
    $cleanLocation = trim(str_replace(["\r", "\n"], ' ', $location));
    $stmt = $pdo->prepare("UPDATE applicants SET status = ?, interview_date = ?, interview_time = ?, interview_location = ?, email_sent_at = NULL WHERE id = ? AND status = ?");
    $stmt->execute([$scheduledStatus, $date, $interviewDateTime->format('H:i'), $cleanLocation, $id, $requiredStatus]);
    if ($stmt->rowCount() !== 1) respond(false, 'The interview schedule was not saved. Please refresh and try again.');
    respond(true, $stageLabel . ' interview saved. The invitation email is being sent in the background.', [
        'email_pending' => true,
        'applicant_id' => $id,
        'stage' => $stage,
    ]);
}

if ($action === 'send_interview_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('hr');
    $id = (int)($_POST['id'] ?? 0);
    $stage = $_POST['stage'] ?? '';
    if ($id < 1 || !in_array($stage, ['initial', 'final'], true)) respond(false, 'Invalid interview email request.');
    $scheduledStatus = $stage === 'initial' ? 'initial_interview_scheduled' : 'final_interview_scheduled';

    // Atomically claim this email so rapid double-clicks cannot send duplicates.
    $claim = $pdo->prepare("UPDATE applicants SET email_sent_at = '1970-01-01 00:00:01' WHERE id = ? AND status = ? AND email_sent_at IS NULL");
    $claim->execute([$id, $scheduledStatus]);
    if ($claim->rowCount() !== 1) respond(true, 'Invitation email is already being processed or was already sent.');

    $stmt = $pdo->prepare("SELECT first_name, last_name, email, position, interview_date, interview_time, interview_location FROM applicants WHERE id = ? AND status = ?");
    $stmt->execute([$id, $scheduledStatus]);
    $applicant = $stmt->fetch();
    if (!$applicant) respond(false, 'The scheduled applicant could not be found.');

    $clean = static fn(string $value): string => trim(str_replace(["\r", "\n"], ' ', $value));
    $name = $clean($applicant['first_name'] . ' ' . $applicant['last_name']);
    $dateObject = DateTime::createFromFormat('Y-m-d', $applicant['interview_date']);
    $interviewDateTime = DateTime::createFromFormat('Y-m-d H:i', $applicant['interview_date'] . ' ' . substr($applicant['interview_time'], 0, 5));
    if (!$dateObject || !$interviewDateTime) {
        $pdo->prepare('UPDATE applicants SET email_sent_at = NULL WHERE id = ?')->execute([$id]);
        respond(false, 'The saved interview schedule is invalid.');
    }

    $stageLabel = ucfirst($stage);
    $subject = $stageLabel . ' Interview Invitation - Quadra Cafe';
    $message = "Dear {$name},\n\nThank you for applying for the {$applicant['position']} position at Quadra Cafe. You are invited to your {$stage} interview.\n\n{$stageLabel} INTERVIEW DETAILS\nDate: " . $dateObject->format('l, F j, Y') . "\nTime: " . $interviewDateTime->format('g:i A') . "\nVenue / meeting link: " . $clean($applicant['interview_location']) . "\n\nPlease arrive at least 10 minutes early for an in-person interview. If you need to reschedule, please reply to this email.\n\nRegards,\nQuadra Cafe HR Team";
    // Release the PHP session lock before SMTP so other HR buttons stay responsive.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    try {
        require_once 'send_email.php';
        sendQuadraEmail($applicant['email'], $name, $subject, $message);
        $pdo->prepare('UPDATE applicants SET email_sent_at = NOW() WHERE id = ?')->execute([$id]);
    } catch (Throwable $error) {
        $pdo->prepare('UPDATE applicants SET email_sent_at = NULL WHERE id = ?')->execute([$id]);
        http_response_code(500);
        respond(false, 'The schedule was saved, but the invitation email could not be sent: ' . $error->getMessage());
    }
    respond(true, 'Invitation email sent to ' . $applicant['email'] . '.');
}

if ($action === 'interview_result' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('hr');
    $id = (int)($_POST['id'] ?? 0);
    $result = $_POST['result'] ?? '';
    if ($id < 1 || !in_array($result, ['passed', 'failed'], true)) respond(false, 'Invalid interview result. Please select Yes or No.');
    $stage = $_POST['stage'] ?? '';
    if (!in_array($stage, ['initial', 'final'], true)) respond(false, 'Invalid interview stage.');
    $requiredStatus = $stage === 'initial' ? 'initial_interview_scheduled' : 'final_interview_scheduled';
    $status = $result === 'failed' ? 'interview_failed' : ($stage === 'initial' ? 'final_interview_pending' : 'final_interview_passed');
    $scheduleStmt = $pdo->prepare('SELECT status, interview_date, interview_time, first_name, last_name, email, position FROM applicants WHERE id = ?');
    $scheduleStmt->execute([$id]);
    $schedule = $scheduleStmt->fetch();
    if (!$schedule || $schedule['status'] !== $requiredStatus) respond(false, 'This applicant does not have a completed scheduled interview for this stage.');
    $scheduledAt = DateTime::createFromFormat('Y-m-d H:i', $schedule['interview_date'] . ' ' . substr($schedule['interview_time'], 0, 5));
    if (!$scheduledAt) respond(false, 'The saved interview schedule is invalid. Please schedule the interview again.');
    if (new DateTime() < $scheduledAt) {
        respond(false, 'Yes/No will be available on ' . $scheduledAt->format('F j, Y') . ' at ' . $scheduledAt->format('g:i A') . ', after the scheduled interview time starts.');
    }
    $availability = $result === 'failed' ? 'not-available' : 'available';
    $stmt = $pdo->prepare("UPDATE applicants SET status = ?, availability = ?, interview_stage = ?, result_email_sent_at = IF(? = 'final', NULL, result_email_sent_at) WHERE id = ? AND status = ?");
    $stmt->execute([$status, $availability, $stage, $stage, $id, $requiredStatus]);
    if ($stmt->rowCount() !== 1) respond(false, 'Only applicants who completed this scheduled interview can have a result.');
    if ($stage === 'final') {
        respond(true, $result === 'passed'
            ? 'Final interview passed. The result email is being sent in the background.'
            : 'Final interview result saved. The result email is being sent in the background.', [
                'email_pending' => true,
                'applicant_id' => $id,
            ]);
    }
    respond(true, $result === 'passed' ? 'Initial interview passed. You may now schedule the final interview.' : 'Interview result saved: the applicant did not pass.');
}

if ($action === 'send_result_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('hr');
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) respond(false, 'Invalid result email request.');

    $claim = $pdo->prepare("UPDATE applicants SET result_email_sent_at = '1970-01-01 00:00:01' WHERE id = ? AND interview_stage = 'final' AND status IN ('final_interview_passed','interview_failed') AND result_email_sent_at IS NULL");
    $claim->execute([$id]);
    if ($claim->rowCount() !== 1) respond(true, 'Result email is already being processed or was already sent.');

    $stmt = $pdo->prepare("SELECT first_name, last_name, email, position, status FROM applicants WHERE id = ? AND interview_stage = 'final'");
    $stmt->execute([$id]);
    $applicant = $stmt->fetch();
    if (!$applicant) respond(false, 'The applicant result could not be found.');
    $applicantName = trim($applicant['first_name'] . ' ' . $applicant['last_name']);
    $passed = $applicant['status'] === 'final_interview_passed';
    $subject = $passed ? "You're Hired - Quadra Cafe" : 'Application Update - Quadra Cafe';
    $message = $passed
        ? "Dear {$applicantName},\n\nCongratulations! We are pleased to inform you that you passed your final interview and have been selected for the {$applicant['position']} position at Quadra Cafe.\n\nFor the next meeting, please be ready for your onboarding discussion. The Administrator will prepare your employee account, and the HR team will assist you with the next steps.\n\nWelcome to the Quadra Cafe team!\n\nRegards,\nQuadra Cafe HR Team"
        : "Dear {$applicantName},\n\nThank you for the time and effort you invested throughout our interview process for the {$applicant['position']} position.\n\nAfter careful consideration, we are sorry to inform you that we will not be moving forward with your application at this time. We appreciate your interest in Quadra Cafe and wish you success in your future opportunities.\n\nRegards,\nQuadra Cafe HR Team";
    // Release the PHP session lock before SMTP so other HR buttons stay responsive.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    try {
        require_once 'send_email.php';
        sendQuadraEmail($applicant['email'], $applicantName, $subject, $message);
        $pdo->prepare('UPDATE applicants SET result_email_sent_at = NOW() WHERE id = ?')->execute([$id]);
    } catch (Throwable $error) {
        $pdo->prepare('UPDATE applicants SET result_email_sent_at = NULL WHERE id = ?')->execute([$id]);
        http_response_code(500);
        respond(false, 'The result was saved, but the email could not be sent: ' . $error->getMessage());
    }
    respond(true, 'Result email sent to ' . $applicant['email'] . '.');
}

respond(false, 'Invalid action.');
