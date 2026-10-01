<?php
declare(strict_types=1);
require_once __DIR__ . '/supabase_client.php';
require_once __DIR__ . '/register_otp_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    bc_regotp_json(405, ['success' => false, 'error' => 'Method not allowed.']);
}
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) bc_regotp_json(400, ['success' => false, 'error' => 'Invalid verification request.']);
$method = (string) ($body['method'] ?? 'email');
$target = trim((string) ($body['target'] ?? ''));
$token = preg_replace('/\D/', '', (string) ($body['token'] ?? '')) ?? '';
if (!in_array($method, ['email', 'sms'], true) || !preg_match('/^\d{6}$/', $token)
    || ($method === 'email' && !filter_var($target, FILTER_VALIDATE_EMAIL))
    || ($method === 'sms' && !preg_match('/^\+63\d{10}$/', $target))) {
    bc_regotp_json(422, ['success' => false, 'error' => 'Enter the six-digit code sent to your selected contact.']);
}
bc_sb_session_start();
$pending = $_SESSION['bc_pending_registration'] ?? null;
if (!is_array($pending) || ($pending['verification_method'] ?? 'email') !== $method
    || (string) ($pending['verification_target'] ?? $pending['email'] ?? '') !== $target
    || (int) ($pending['created_at'] ?? 0) < time() - 86400) {
    unset($_SESSION['bc_pending_registration']);
    bc_regotp_json(400, ['success' => false, 'error' => 'This registration session expired. Start the registration again to receive a new code.']);
}
$limit = bc_regotp_rate_limit('verify', $target, 5, 900);
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    bc_regotp_json(429, ['success' => false, 'error' => 'Too many incorrect attempts. Request a new code and try again later.']);
}

try {
    $auth = is_array($pending['verified_auth'] ?? null) ? $pending['verified_auth'] : null;
    if (!$auth) {
        $verifyPayload = ['type' => $method === 'sms' ? 'sms' : 'email', 'token' => $token];
        $verifyPayload[$method === 'sms' ? 'phone' : 'email'] = $target;
        $verified = bc_sb_request('auth/v1/verify', 'POST', $verifyPayload, null, false);
        $auth = is_array($verified['data'] ?? null) ? $verified['data'] : [];
        $pending['verified_auth'] = $auth;
        $_SESSION['bc_pending_registration'] = $pending;
    }
    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $userId = (string) ($user['id'] ?? '');
    $verifiedIdentity = $method === 'sms'
        ? (string) ($user['phone'] ?? '')
        : strtolower((string) ($user['email'] ?? ''));
    $expectedIdentity = $method === 'sms' ? $target : strtolower($target);
    if ($userId === '' || $userId !== (string) ($pending['user_id'] ?? '') || $verifiedIdentity !== $expectedIdentity) {
        throw new RuntimeException('Contact verification did not match the pending registration.');
    }

    $pendingProfile = $pending['profile'] ?? null;
    $residentPayload = $pending['resident'] ?? null;
    if (!is_array($pendingProfile) || !is_array($residentPayload)) {
        throw new RuntimeException('Pending registration details are incomplete.');
    }
    foreach ([
        'username' => (string) ($pendingProfile['username'] ?? ''),
        'phone' => (string) ($pendingProfile['phone'] ?? ''),
        'email' => (string) ($pending['email'] ?? ''),
    ] as $column => $value) {
        $duplicate = bc_sb_rest('profiles', 'GET', [
            $column => 'eq.' . $value,
            'id' => 'neq.' . $userId,
            'select' => 'id',
            'limit' => '1',
        ]);
        if (!empty($duplicate['data'])) {
            bc_regotp_json(409, ['success' => false, 'error' => 'This username, email, or mobile number was just used by another account. Contact the barangay administrator to finish registration.']);
        }
    }

    $profileResult = bc_sb_rest('profiles', 'GET', [
        'id' => 'eq.' . $userId,
        'select' => 'id,role,status,full_name,email,username',
        'limit' => '1',
    ]);
    $profile = $profileResult['data'][0] ?? null;
    if (!is_array($profile)) {
        bc_sb_rest('profiles', 'POST', [], $pendingProfile, ['return=minimal']);
        $profile = $pendingProfile;
    }
    $residentResult = bc_sb_rest('residents', 'GET', [
        'user_id' => 'eq.' . $userId,
        'select' => 'id,resident_id,first_name,last_name',
        'limit' => '1',
    ]);
    $resident = $residentResult['data'][0] ?? null;
    if (!is_array($resident)) {
        bc_sb_rest('residents', 'POST', [], $residentPayload, ['return=minimal']);
        $resident = [
            'id' => '',
            'resident_id' => (string) ($residentPayload['resident_id'] ?? ''),
            'first_name' => (string) ($residentPayload['first_name'] ?? ''),
            'last_name' => (string) ($residentPayload['last_name'] ?? ''),
        ];
    }
    if (($profile['role'] ?? '') !== 'resident' || ($profile['status'] ?? '') !== 'active') {
        bc_regotp_json(403, ['success' => false, 'error' => 'The email is verified, but the resident profile is incomplete. Please contact the barangay administrator.']);
    }

    if (!empty($auth['access_token'])) {
        bc_sb_session_start();
        session_regenerate_id(true);
        $_SESSION['bc_supabase'] = [
            'access_token' => (string) $auth['access_token'],
            'refresh_token' => $auth['refresh_token'] ?? null,
            'expires_at' => time() + (int) ($auth['expires_in'] ?? 3600),
            'user_id' => $userId,
            'role' => 'resident',
            'email' => (string) ($pending['email'] ?? ''),
            'full_name' => (string) ($profile['full_name'] ?? 'BarangayConnect resident'),
            'username' => (string) ($profile['username'] ?? ''),
            'resident' => $resident,
        ];
    }
    bc_regotp_clear_rate_limit('verify', $target);
    unset($_SESSION['bc_pending_registration']);
    bc_regotp_json(200, [
        'success' => true,
        'message' => 'Registration successful. Your contact has been verified.',
        'id' => $userId,
        'residentId' => (string) ($resident['resident_id'] ?? ''),
        'firstName' => (string) ($resident['first_name'] ?? ''),
        'lastName' => (string) ($resident['last_name'] ?? ''),
        'username' => (string) ($profile['username'] ?? ''),
        'fullName' => (string) ($profile['full_name'] ?? ''),
    ]);
} catch (Throwable $e) {
    if (in_array($e->getCode(), [400, 401, 403, 422], true)) {
        bc_regotp_json(422, ['success' => false, 'error' => 'That code is incorrect or expired. Check the code and try again.']);
    }
    error_log('BarangayConnect registration OTP verification: ' . $e->getMessage());
    bc_regotp_json(503, ['success' => false, 'error' => 'Verification service is unavailable. Please try again.']);
}
