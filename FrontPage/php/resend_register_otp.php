<?php
declare(strict_types=1);
require_once __DIR__ . '/supabase_client.php';
require_once __DIR__ . '/register_otp_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    bc_regotp_json(405, ['success' => false, 'error' => 'Method not allowed.']);
}
$body = json_decode((string) file_get_contents('php://input'), true);
$method = (string) (is_array($body) ? ($body['method'] ?? 'email') : 'email');
$target = trim((string) (is_array($body) ? ($body['target'] ?? '') : ''));
if (!in_array($method, ['email', 'sms'], true)
    || ($method === 'email' && !filter_var($target, FILTER_VALIDATE_EMAIL))
    || ($method === 'sms' && !preg_match('/^\+63\d{10}$/', $target))) {
    bc_regotp_json(400, ['success' => false, 'error' => 'Enter a valid email address or mobile number.']);
}
bc_sb_session_start();
$pending = $_SESSION['bc_pending_registration'] ?? null;
if (!is_array($pending) || ($pending['verification_method'] ?? 'email') !== $method
    || (string) ($pending['verification_target'] ?? $pending['email'] ?? '') !== $target
    || (int) ($pending['created_at'] ?? 0) < time() - 86400) {
    bc_regotp_json(400, ['success' => false, 'error' => 'This registration session expired. Start the registration again to request a new code.']);
}
$limit = bc_regotp_rate_limit('resend', $target, 1, 60);
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    bc_regotp_json(429, ['success' => false, 'error' => 'Please wait a minute before requesting another code.']);
}
try {
    $resendPayload = ['type' => $method === 'sms' ? 'sms' : 'signup'];
    $resendPayload[$method === 'sms' ? 'phone' : 'email'] = $target;
    bc_sb_request('auth/v1/resend', 'POST', $resendPayload, null, false);
    bc_regotp_json(200, ['success' => true, 'message' => 'A new verification code has been requested.']);
} catch (Throwable $e) {
    error_log('BarangayConnect registration OTP resend: ' . $e->getMessage());
    bc_regotp_json(503, ['success' => false, 'error' => 'Could not resend the code. Check your email settings or try again shortly.']);
}
