<?php
/**
 * Admin account registration (localhost / authorized setup only).
 * Enforces one account per admin via employee ID, staff ID, and official email.
 */
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/admins_lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$isLocal =
    stripos($httpHost, 'localhost') !== false
    || stripos($httpHost, '127.0.0.1') !== false;

if (!$isLocal) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin registration is only available on localhost.']);
    exit;
}

$conn = bc_db_connect();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => bc_db_error_response()]);
    exit;
}

bc_upgrade_admins_schema($conn);

$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';
$fullName = trim($_POST['full_name'] ?? '');
$employeeId = bc_normalize_admin_id($_POST['employee_id'] ?? '');
$staffId = bc_normalize_admin_id($_POST['staff_id'] ?? '');

if ($password === '' || $password !== $confirm) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Passwords do not match']);
    $conn->close();
    exit;
}

$conflict = bc_check_admin_registration_conflicts($conn, [
    'username' => $username,
    'email' => $email,
    'employee_id' => $employeeId,
    'staff_id' => $staffId,
]);
if (!$conflict['ok']) {
    http_response_code((int) $conflict['http_status']);
    echo json_encode(['success' => false, 'error' => $conflict['error']]);
    $conn->close();
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$table = bc_admins_table();
$fullNameDb = $fullName !== '' ? $fullName : 'Barangay Admin';
$employeeDb = $employeeId !== '' ? $employeeId : null;
$staffDb = $staffId !== '' ? $staffId : null;

$sql = "INSERT INTO `{$table}` (username, email, password, full_name, employee_id, staff_id)
    VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error (prepare)']);
    $conn->close();
    exit;
}

$stmt->bind_param('ssssss', $username, $email, $hash, $fullNameDb, $employeeDb, $staffDb);

if (!$stmt->execute()) {
    $errno = $stmt->errno;
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    if ($errno === 1062) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'An admin account with the same username, email, or ID already exists']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $err ?: 'Admin registration failed']);
    exit;
}

$newId = $conn->insert_id;
$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'id' => $newId,
    'username' => $username,
    'message' => 'Admin account created',
]);
