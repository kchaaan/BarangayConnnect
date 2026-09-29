<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase_client.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bc_recovery_out(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bc_recovery_out(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$input = json_decode((string) file_get_contents('php://input'), true);
$token = is_array($input) ? trim((string) ($input['access_token'] ?? '')) : '';
$password = is_array($input) ? (string) ($input['password'] ?? '') : '';
if ($token === '' || strlen($token) > 8192 || strlen($password) < 8 || strlen($password) > 1024) {
    bc_recovery_out(400, ['success' => false, 'error' => 'Invalid or expired recovery link, or invalid password.']);
}

try {
    // Supabase validates the short-lived recovery access token and updates only its user.
    bc_sb_request('auth/v1/user', 'PUT', ['password' => $password], $token, false);
    bc_recovery_out(200, ['success' => true]);
} catch (Throwable $e) {
    error_log('BarangayConnect password recovery failed: ' . $e->getMessage());
    $status = in_array($e->getCode(), [400, 401, 403], true) ? 400 : 503;
    bc_recovery_out($status, ['success' => false, 'error' => $status === 400
        ? 'Invalid or expired recovery link. Request a new password recovery email.'
        : 'Password recovery service is unavailable. Try again later.']);
}
