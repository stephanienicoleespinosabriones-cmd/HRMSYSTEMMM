<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Manila');

header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');

function documentRespond(bool $success, string $message = '', array $extra = [], int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function documentUser(PDO $pdo): array {
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id < 1) documentRespond(false, 'Your session has expired. Please sign in again.', [], 401);
    $stmt = $pdo->prepare('SELECT id, full_name, position, status FROM employees WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'approved') documentRespond(false, 'Your employee account is not active.', [], 403);
    return $user;
}

function documentIsHr(array $user): bool {
    return $user['position'] === 'Administrator' || stripos($user['position'], 'HR') !== false || strcasecmp($user['position'], 'Human Resources') === 0;
}

function ensureDocumentSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_documents (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function documentTypes(): array {
    return ['sss' => 'SSS', 'philhealth' => 'PhilHealth', 'pagibig' => 'Pag-IBIG', 'tin' => 'TIN', 'valid_id' => 'Valid ID'];
}

function documentPublicRow(array $row): array {
    return [
        'document_id' => (int)$row['document_id'],
        'employee_id' => (int)$row['employee_id'],
        'employee_name' => $row['employee_name'] ?? null,
        'document_type' => $row['document_type'],
        'document_label' => documentTypes()[$row['document_type']] ?? $row['document_type'],
        'file_name' => $row['file_name'],
        'verification_status' => $row['verification_status'],
        'rejection_reason' => $row['rejection_reason'],
        'verified_by_name' => $row['verified_by_name'] ?? null,
        'verified_at' => $row['verified_at'],
        'uploaded_at' => $row['uploaded_at'],
        'updated_at' => $row['updated_at'],
    ];
}

ensureDocumentSchema($pdo);
$user = documentUser($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'mine';

if ($action === 'mine') {
    if (documentIsHr($user)) documentRespond(false, 'Employee document uploads are available to employee accounts only.', [], 403);
    $stmt = $pdo->prepare('SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY document_type');
    $stmt->execute([$user['id']]);
    $documents = [];
    foreach ($stmt->fetchAll() as $row) $documents[] = documentPublicRow($row);
    documentRespond(true, '', ['documents' => $documents, 'document_types' => documentTypes()]);
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (documentIsHr($user)) documentRespond(false, 'Only employee accounts can upload their own documents.', [], 403);
    $type = trim($_POST['document_type'] ?? '');
    if (!isset(documentTypes()[$type])) documentRespond(false, 'Please select a valid document type.');
    if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) documentRespond(false, 'Please choose a document file to upload.');
    $file = $_FILES['document'];
    if ((int)$file['size'] > 10 * 1024 * 1024) documentRespond(false, 'Document file must not exceed 10 MB.');
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($extension, $allowedExtensions, true)) documentRespond(false, 'Only PDF, JPG, JPEG, and PNG files are allowed.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $allowedMime = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($mime, $allowedMime, true)) documentRespond(false, 'The selected file content is not a valid PDF, JPG, JPEG, or PNG document.');

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'employee_documents';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) documentRespond(false, 'Unable to prepare secure document storage.', [], 500);
    $denyFile = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($denyFile)) @file_put_contents($denyFile, "Require all denied\n");
    $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
    $relativePath = 'uploads/employee_documents/' . $storedName;
    $target = $directory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $target)) documentRespond(false, 'The document could not be saved.', [], 500);

    $existing = $pdo->prepare('SELECT file_path FROM employee_documents WHERE employee_id = ? AND document_type = ? LIMIT 1');
    $existing->execute([$user['id'], $type]);
    $old = $existing->fetch();
    try {
        $stmt = $pdo->prepare("INSERT INTO employee_documents (employee_id, document_type, file_name, file_path, mime_type, verification_status, rejection_reason, verified_by, verified_at, uploaded_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NULL, NULL, NULL, NOW())
            ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), file_path = VALUES(file_path), mime_type = VALUES(mime_type), verification_status = 'pending', rejection_reason = NULL, verified_by = NULL, verified_at = NULL, uploaded_at = NOW()");
        $stmt->execute([$user['id'], $type, basename($file['name']), $relativePath, $mime]);
    } catch (Throwable $error) {
        @unlink($target);
        documentRespond(false, 'The document record could not be saved.', [], 500);
    }
    if ($old && !empty($old['file_path'])) {
        $oldPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $old['file_path']);
        if (is_file($oldPath) && realpath(dirname($oldPath)) === realpath($directory)) @unlink($oldPath);
    }
    documentRespond(true, documentTypes()[$type] . ' uploaded successfully and is pending HR review.');
}

if ($action === 'hr_list') {
    if (!documentIsHr($user)) documentRespond(false, 'HR access is required.', [], 403);
    $stmt = $pdo->query("SELECT d.*, e.full_name AS employee_name, verifier.full_name AS verified_by_name
        FROM employee_documents d
        JOIN employees e ON e.id = d.employee_id
        LEFT JOIN employees verifier ON verifier.id = d.verified_by
        ORDER BY d.updated_at DESC, d.document_id DESC");
    $documents = [];
    foreach ($stmt->fetchAll() as $row) $documents[] = documentPublicRow($row);
    documentRespond(true, '', ['documents' => $documents, 'document_types' => documentTypes()]);
}

if ($action === 'review' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!documentIsHr($user)) documentRespond(false, 'HR access is required.', [], 403);
    $id = (int)($_POST['document_id'] ?? 0);
    $decision = trim($_POST['decision'] ?? '');
    $reason = trim($_POST['rejection_reason'] ?? '');
    if ($id < 1 || !in_array($decision, ['verified', 'rejected'], true)) documentRespond(false, 'Invalid document review decision.');
    if ($decision === 'rejected' && $reason === '') documentRespond(false, 'Enter a rejection reason before rejecting this document.');
    if (mb_strlen($reason) > 1000) documentRespond(false, 'Rejection reason must not exceed 1000 characters.');
    $stmt = $pdo->prepare("UPDATE employee_documents SET verification_status = ?, rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE document_id = ? AND verification_status = 'pending'");
    $stmt->execute([$decision, $decision === 'rejected' ? $reason : null, $user['id'], $id]);
    if ($stmt->rowCount() !== 1) documentRespond(false, 'This document is no longer pending review. Refresh the list and try again.');
    documentRespond(true, $decision === 'verified' ? 'Document verified successfully.' : 'Document rejected. The employee can now see the reason and upload a replacement.');
}

if ($action === 'file') {
    $id = (int)($_GET['document_id'] ?? 0);
    if ($id < 1) documentRespond(false, 'Invalid document request.', [], 400);
    $stmt = $pdo->prepare('SELECT * FROM employee_documents WHERE document_id = ? LIMIT 1');
    $stmt->execute([$id]);
    $document = $stmt->fetch();
    if (!$document) documentRespond(false, 'Document not found.', [], 404);
    if (!documentIsHr($user) && (int)$document['employee_id'] !== (int)$user['id']) documentRespond(false, 'You cannot access another employee\'s document.', [], 403);
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'employee_documents';
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $document['file_path']);
    if (!is_file($path) || realpath(dirname($path)) !== realpath($base)) documentRespond(false, 'The document file is unavailable.', [], 404);
    $download = ($_GET['download'] ?? '') === '1';
    header('Content-Type: ' . $document['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . rawurlencode($document['file_name']) . '"');
    readfile($path);
    exit;
}

documentRespond(false, 'Invalid employee document action.', [], 400);
