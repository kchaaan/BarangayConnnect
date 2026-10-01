<?php
declare(strict_types=1);
require_once __DIR__ . '/supabase_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function bc_staff_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bc_staff_uuid(string $id): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
}

try {
    $session = bc_sb_current_user();
    if (!$session || ($session['role'] ?? '') !== 'superadmin') {
        bc_staff_reply($session ? 403 : 401, ['success' => false, 'error' => 'SuperAdmin access is required.']);
    }

    $actorId = (string) ($session['user_id'] ?? '');
    if (!bc_staff_uuid($actorId)) bc_staff_reply(403, ['success' => false, 'error' => 'Invalid SuperAdmin session.']);
    $actorResult = bc_sb_rest('profiles', 'GET', [
        'id' => 'eq.' . $actorId,
        'role' => 'eq.superadmin',
        'status' => 'eq.active',
        'select' => 'id',
        'limit' => '1',
    ]);
    if (empty($actorResult['data'][0]['id'])) {
        unset($_SESSION['bc_supabase']);
        bc_staff_reply(403, ['success' => false, 'error' => 'Active SuperAdmin access is required.']);
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        if (($_GET['action'] ?? '') !== 'list') bc_staff_reply(400, ['success' => false, 'error' => 'Invalid action.']);
        if (empty($_SESSION['bc_staff_accounts_csrf'])) {
            $_SESSION['bc_staff_accounts_csrf'] = bin2hex(random_bytes(32));
        }
        $result = bc_sb_rest('profiles', 'GET', [
            'role' => 'eq.admin',
            'select' => 'id,username,full_name,status',
            'order' => 'username.asc',
            'limit' => '100',
        ]);
        $accounts = [];
        foreach (($result['data'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $accounts[] = [
                'id' => (string) ($row['id'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'fullName' => (string) ($row['full_name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
            ];
        }
        bc_staff_reply(200, [
            'success' => true,
            'accounts' => $accounts,
            'csrfToken' => (string) $_SESSION['bc_staff_accounts_csrf'],
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        bc_staff_reply(405, ['success' => false, 'error' => 'Method not allowed.']);
    }

    $expectedToken = (string) ($_SESSION['bc_staff_accounts_csrf'] ?? '');
    $providedToken = (string) ($_SERVER['HTTP_X_BC_CSRF'] ?? '');
    if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        bc_staff_reply(403, ['success' => false, 'error' => 'Security check failed. Reload this page and try again.']);
    }

    $raw = file_get_contents('php://input');
    $body = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($body)) bc_staff_reply(400, ['success' => false, 'error' => 'Invalid request.']);
    if (($body['action'] ?? '') !== 'reset-password') bc_staff_reply(400, ['success' => false, 'error' => 'Invalid action.']);

    $targetId = trim((string) ($body['id'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $confirmation = (string) ($body['confirmPassword'] ?? '');
    if (!bc_staff_uuid($targetId)) bc_staff_reply(400, ['success' => false, 'error' => 'Invalid admin account.']);
    if (strlen($password) < 15 || strlen($password) > 128 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $password)) {
        bc_staff_reply(400, ['success' => false, 'error' => 'Use a new password with at least 15 characters.']);
    }
    if (!hash_equals($password, $confirmation)) bc_staff_reply(400, ['success' => false, 'error' => 'The passwords do not match.']);

    $targetResult = bc_sb_rest('profiles', 'GET', [
        'id' => 'eq.' . $targetId,
        'role' => 'eq.admin',
        'status' => 'eq.active',
        'select' => 'id,username',
        'limit' => '1',
    ]);
    $target = $targetResult['data'][0] ?? null;
    if (!is_array($target)) bc_staff_reply(404, ['success' => false, 'error' => 'Active admin account not found.']);

    $rateKey = 'bc_staff_pw_reset_times';
    $now = time();
    $attempts = array_values(array_filter(
        is_array($_SESSION[$rateKey] ?? null) ? $_SESSION[$rateKey] : [],
        static fn ($timestamp) => is_int($timestamp) && $timestamp > $now - 900
    ));
    if (count($attempts) >= 6) bc_staff_reply(429, ['success' => false, 'error' => 'Too many password changes. Wait 15 minutes and try again.']);

    try {
        bc_sb_request('auth/v1/admin/users/' . rawurlencode($targetId), 'PUT', ['password' => $password]);
    } catch (Throwable $error) {
        error_log('BarangayConnect staff password reset failed for admin ' . $targetId . ': ' . $error->getCode());
        bc_staff_reply(502, ['success' => false, 'error' => 'Supabase could not update this password. Check the Auth user and server configuration.']);
    }

    $attempts[] = $now;
    $_SESSION[$rateKey] = $attempts;
    error_log('BarangayConnect staff password reset by SuperAdmin ' . $actorId . ' for admin ' . $targetId . ' at ' . gmdate('c'));
    bc_staff_reply(200, ['success' => true, 'message' => 'Admin password updated. The new password was not emailed or stored in the application.']);
} catch (Throwable $error) {
    error_log('BarangayConnect SuperAdmin staff account endpoint failed: ' . $error->getCode());
    bc_staff_reply(503, ['success' => false, 'error' => 'Staff account service is temporarily unavailable.']);
}
