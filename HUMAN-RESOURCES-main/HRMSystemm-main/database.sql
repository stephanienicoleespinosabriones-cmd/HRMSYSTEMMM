CREATE DATABASE IF NOT EXISTS quadra_hrms
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE quadra_hrms;

CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL,
  contact_no VARCHAR(11) NULL,
  username VARCHAR(50) NOT NULL,
  password VARCHAR(255) NOT NULL,
  pin VARCHAR(255) NULL,
  pending_pin_hash VARCHAR(255) NULL,
  pending_pin_token VARCHAR(255) NULL,
  pending_pin_code VARCHAR(255) NULL,
  pending_pin_expires_at DATETIME NULL,
  position VARCHAR(50) NOT NULL,
  status ENUM('pending', 'approved', 'inactive', 'rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employees_email (email),
  UNIQUE KEY uq_employees_username (username),
  KEY idx_employees_status (status),
  KEY idx_employees_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  position_key VARCHAR(30) NOT NULL,
  title VARCHAR(100) NOT NULL,
  salary VARCHAR(100) NOT NULL,
  qualification TEXT NOT NULL,
  description TEXT NOT NULL,
  status ENUM('published', 'closed') NOT NULL DEFAULT 'published',
  created_by INT NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_job_posts_status (status),
  KEY idx_job_posts_position (position_key),
  CONSTRAINT fk_job_posts_creator FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  work_date DATE NOT NULL,
  clock_in DATETIME NOT NULL,
  break_start DATETIME NULL,
  break_end DATETIME NULL,
  clock_out DATETIME NULL,
  status ENUM('on_time', 'late') NOT NULL DEFAULT 'on_time',
  day_status ENUM('in_progress','full_day','half_day','undertime') NOT NULL DEFAULT 'in_progress',
  approval_status ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required',
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  late_minutes INT NOT NULL DEFAULT 0,
  undertime_minutes INT NOT NULL DEFAULT 0,
  overtime_minutes INT NOT NULL DEFAULT 0,
  worked_seconds INT NOT NULL DEFAULT 0,
  attendance_remark VARCHAR(100) NOT NULL DEFAULT 'In Progress',
  admin_note TEXT NULL,
  auto_clock_out TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_attendance_employee_date (employee_id, work_date),
  KEY idx_attendance_date (work_date),
  CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_documents (
  document_id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  document_type ENUM('sss','philhealth','pagibig','tin','valid_id') NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  rejection_reason TEXT NULL,
  verified_by INT NULL,
  verified_at DATETIME NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employee_document_type (employee_id, document_type),
  KEY idx_document_status (verification_status),
  KEY idx_document_type (document_type),
  CONSTRAINT fk_document_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_document_verifier FOREIGN KEY (verified_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  reference_code VARCHAR(40) NOT NULL,
  leave_type ENUM('vacation', 'sick', 'personal', 'emergency', 'maternity') NOT NULL,
  pay_type ENUM('with-pay', 'without-pay') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  days DECIMAL(5,1) NOT NULL,
  contact_no VARCHAR(11) NOT NULL,
  reason TEXT NOT NULL,
  leave_during ENUM('whole-day', 'half-day-am', 'half-day-pm') NOT NULL,
  comments TEXT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS applicants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_code VARCHAR(30) NOT NULL,
  job_post_id INT NULL,
  employee_id VARCHAR(30) NULL,
  first_name VARCHAR(80) NOT NULL,
  middle_name VARCHAR(80) NULL,
  last_name VARCHAR(80) NOT NULL,
  birthdate DATE NOT NULL,
  gender VARCHAR(30) NOT NULL,
  civil_status VARCHAR(30) NOT NULL,
  email VARCHAR(100) NOT NULL,
  contact_no VARCHAR(30) NOT NULL,
  address TEXT NOT NULL,
  position VARCHAR(80) NOT NULL,
  employment_type VARCHAR(40) NOT NULL,
  availability ENUM('available','not-available') NOT NULL DEFAULT 'available',
  years_experience VARCHAR(30) NOT NULL,
  preferred_shift VARCHAR(40) NOT NULL,
  education VARCHAR(100) NOT NULL,
  school VARCHAR(150) NOT NULL,
  course VARCHAR(150) NOT NULL,
  year_graduated VARCHAR(10) NOT NULL,
  high_school VARCHAR(150) NOT NULL,
  high_school_year VARCHAR(10) NOT NULL,
  honors VARCHAR(255) NULL,
  resume_name VARCHAR(255) NULL,
  resume_path VARCHAR(255) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  interview_stage VARCHAR(20) NULL,
  interview_result VARCHAR(20) NULL,
  hr_remarks TEXT NULL,
  interview_date DATE NULL,
  interview_time VARCHAR(50) NULL,
  interview_location VARCHAR(255) NULL,
  email_sent_at DATETIME NULL,
  result_email_sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_applicants_application_code (application_code),
  KEY idx_applicants_status (status),
  KEY idx_applicants_job_post (job_post_id),
  KEY idx_applicants_email (email),
  KEY idx_applicants_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO employees (full_name, email, username, password, pin, position, status)
VALUES
  (
    'System Administrator',
    'admin@quadra.com',
    'admin',
    '$2y$10$PpNfwtudL0Aae.o61tY4Luq9hjZ38kZIMEw49q0Ckf0uMtzdVeVES',
    '$2y$10$stTeEDNCFp8zfnmk6FnCyuBRUNDsDTIE/WAg0hOfxf/ClUIdq9eT6',
    'Administrator',
    'approved'
  ),
  (
    'HR Manager',
    'hr@quadra.com',
    'hr',
    '$2y$10$rBpfHWhyYciakNk6obima.ILw24.Ny5qTW5T94LnmbCABPqQDcfni',
    '$2y$10$PGDoS/hvXDGY6fMrkGFyf.LDrnHbcMWWrHNgvY.7krC0Dyj83Eal6',
    'HR Manager',
    'approved'
  )
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  password = VALUES(password),
  pin = VALUES(pin),
  position = VALUES(position),
  status = VALUES(status);
