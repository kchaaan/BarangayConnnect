<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bc_recovery_otp_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    bc_recovery_otp_reply(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$input = json_decode((string) file_get_contents('php://input'), true);
$email = strtolower(trim((string) (is_array($input) ? ($input['email'] ?? '') : '')));
$token = trim((string) (is_array($input) ? ($input['token'] ?? '') : ''));
$password = (string) (is_array($input) ? ($input['password'] ?? '') : '');
$confirmation = (string) (is_array($input) ? ($input['confirm_password'] ?? '') : '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    bc_recovery_otp_reply(400, ['success' => false, 'error' => 'Enter the email address registered to your account.']);
}
if (!preg_match('/^\d{6}$/', $token)) {
    bc_recovery_otp_reply(400, ['success' => false, 'error' => 'Enter the six-digit recovery code from your email.']);
}
if (strlen($password) < 8 || strlen($password) > 1024 || $password !== $confirmation) {
    bc_recovery_otp_reply(400, ['success' => false, 'error' => 'Use a password of at least 8 characters and make sure both entries match.']);
}

try {
    // Verify the one-time recovery code, then immediately use its short-lived
    // access token to update only the account that redeemed the code.
    $verified = bc_sb_request('auth/v1/verify', 'POST', [
        'email' => $email,
        'token' => $token,
        'type' => 'recovery',
    ], null, false);
    $accessToken = (string) ($verified['data']['access_token'] ?? '');
    if ($accessToken === '') {
        bc_recovery_otp_reply(400, ['success' => false, 'error' => 'That code is invalid or expired. Ask staff to send a new code after verifying your identity.']);
    }

    bc_sb_request('auth/v1/user', 'PUT', ['password' => $password], $accessToken, false);
    bc_recovery_otp_reply(200, ['success' => true]);
} catch (Throwable $e) {
    $status = (int) $e->getCode();
    if (in_array($status, [400, 401, 403], true)) {
        bc_recovery_otp_reply(400, ['success' => false, 'error' => 'That code is invalid or expired, or the password was rejected. Ask staff to verify your identity before sending a new code.']);
    }

    error_log('BarangayConnect OTP password recovery failed: ' . $e->getMessage());
    bc_recovery_otp_reply(503, ['success' => false, 'error' => 'Password recovery is temporarily unavailable. Try again later or contact barangay staff.']);
}
