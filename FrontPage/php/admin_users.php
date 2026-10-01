<?php
/**
 * Admin resident directory backed by the same Supabase profiles/residents tables
 * used by resident registration. Password resets use the profile's Auth email.
 */
declare(strict_types=1);
require_once __DIR__ . '/supabase_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bc_admin_users_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bc_admin_uuid(string $id): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
}

function bc_admin_resident_id($value): string
{
    $id = trim((string) ($value ?? ''));
    if ($id === '') return '';
    if (preg_match('/^BRGY-\d{4}-[A-Z0-9]+$/i', $id)) return strtoupper($id);
    if (preg_match('/^RES(?:-)?(\d+)$/i', $id, $match)) {
        return 'RES-' . str_pad((string) ((int) $match[1]), 3, '0', STR_PAD_LEFT);
    }
    return $id;
}

function bc_admin_resident_row(array $profile, ?array $resident): array
{
    $resident ??= [];
    $extra = $resident['profile_data'] ?? [];
    if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
    if (!is_array($extra)) $extra = [];
    $phone = (string) ($resident['contact_number'] ?? '');
    if ($phone === '') $phone = preg_replace('/^\+63/', '0', (string) ($profile['phone'] ?? '')) ?? '';

    return [
        'id' => (string) ($profile['id'] ?? ''),
        'hasResidentRecord' => $resident !== [],
        'residentId' => bc_admin_resident_id($resident['resident_id'] ?? ''),
        'firstname' => (string) ($resident['first_name'] ?? ''),
        'lastname' => (string) ($resident['last_name'] ?? ''),
        'username' => (string) ($profile['username'] ?? $resident['username'] ?? ''),
        'addressInfo' => (string) ($resident['address'] ?? ''),
        'houseUnit' => (string) ($extra['houseUnit'] ?? ''),
        'blockLot' => (string) ($extra['blockLot'] ?? ''),
        'street' => (string) ($extra['street'] ?? ''),
        'phoneNum' => $phone,
        'email' => (string) ($profile['email'] ?? $resident['email'] ?? ''),
        'status' => (string) ($profile['status'] ?? 'inactive'),
        'approvedDateTime' => !empty($profile['created_at']) ? date('c', strtotime((string) $profile['created_at'])) : null,
    ];
}

/** Return only resident profiles, with profile details joined to their resident records by Auth UUID. */
function bc_admin_residents(): array
{
    $profilesResponse = bc_sb_rest('profiles', 'GET', [
        'role' => 'eq.resident',
        'select' => 'id,role,status,full_name,email,phone,username,created_at',
        'order' => 'created_at.desc',
        'limit' => '1000',
    ]);
    $profiles = $profilesResponse['data'] ?? null;
    if (!is_array($profiles)) throw new RuntimeException('Invalid profiles response from Supabase.');
    if ($profiles === []) return [];

    $userIds = array_values(array_filter(
        array_map(static fn ($profile) => (string) ($profile['id'] ?? ''), $profiles),
        'bc_admin_uuid'
    ));
    if ($userIds === []) return [];

    $residentResponse = bc_sb_rest('residents', 'GET', [
        'user_id' => 'in.(' . implode(',', $userIds) . ')',
        'select' => 'user_id,resident_id,first_name,middle_name,last_name,username,email,address,contact_number,profile_data,created_at',
        'limit' => '1000',
    ]);
    $residentRows = $residentResponse['data'] ?? null;
    if (!is_array($residentRows)) throw new RuntimeException('Invalid residents response from Supabase.');
    $residentsByUser = [];
    foreach ($residentRows as $resident) {
        if (is_array($resident)) $residentsByUser[(string) ($resident['user_id'] ?? '')] = $resident;
    }

    $rows = [];
    foreach ($profiles as $profile) {
        if (!is_array($profile)) continue;
        $id = (string) ($profile['id'] ?? '');
        if (!bc_admin_uuid($id)) continue;
        $rows[] = bc_admin_resident_row($profile, $residentsByUser[$id] ?? null);
    }
    return $rows;
}

function bc_admin_update_resident(array $body): never
{
    $id = trim((string) ($body['id'] ?? ''));
    if (!bc_admin_uuid($id)) bc_admin_users_reply(400, ['success' => false, 'error' => 'Invalid resident account ID.']);

    $houseUnit = trim((string) ($body['houseUnit'] ?? ''));
    $blockLot = trim((string) ($body['blockLot'] ?? ''));
    $street = trim((string) ($body['street'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string) ($body['phoneNum'] ?? '')) ?? '';
    if (strlen($houseUnit) > 32 || strlen($blockLot) > 32 || strlen($street) > 120) {
        bc_admin_users_reply(400, ['success' => false, 'error' => 'Check the address fields; one is too long.']);
    }
    if (strlen($phone) !== 11 || !str_starts_with($phone, '09')) {
        bc_admin_users_reply(400, ['success' => false, 'error' => 'Enter a valid 11-digit Philippine mobile number starting with 09.']);
    }

    $profileResponse = bc_sb_rest('profiles', 'GET', [
        'id' => 'eq.' . $id, 'role' => 'eq.resident', 'select' => 'id,phone', 'limit' => '1',
    ]);
    $profile = $profileResponse['data'][0] ?? null;
    if (!is_array($profile)) bc_admin_users_reply(404, ['success' => false, 'error' => 'Resident account not found.']);
    $residentResponse = bc_sb_rest('residents', 'GET', [
        'user_id' => 'eq.' . $id, 'select' => 'user_id,address,profile_data', 'limit' => '1',
    ]);
    $resident = $residentResponse['data'][0] ?? null;
    if (!is_array($resident)) bc_admin_users_reply(404, ['success' => false, 'error' => 'Resident details are not linked to this account yet.']);

    $phoneE164 = '+63' . substr($phone, 1);
    $duplicateResponse = bc_sb_rest('profiles', 'GET', [
        'phone' => 'eq.' . $phoneE164, 'id' => 'neq.' . $id, 'select' => 'id', 'limit' => '1',
    ]);
    if (!empty($duplicateResponse['data'])) {
        bc_admin_users_reply(409, ['success' => false, 'error' => 'That mobile number is already used by another account.']);
    }

    $extra = $resident['profile_data'] ?? [];
    if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
    if (!is_array($extra)) $extra = [];
    $extra['houseUnit'] = $houseUnit;
    $extra['blockLot'] = $blockLot;
    $extra['street'] = $street;
    $lineParts = [];
    if ($houseUnit !== '') $lineParts[] = 'House/Unit ' . $houseUnit;
    if ($blockLot !== '') $lineParts[] = 'Blk/Lot ' . $blockLot;
    if ($street !== '') $lineParts[] = $street;
    $houseStreet = implode(' · ', $lineParts);
    $oldAddressParts = array_map('trim', explode(',', trim((string) ($resident['address'] ?? ''))));
    $addressTail = count($oldAddressParts) > 1 ? array_slice($oldAddressParts, 1) : [];
    $newAddress = implode(', ', array_values(array_filter(array_merge([$houseStreet], $addressTail), static fn ($part) => $part !== '')));

    bc_sb_rest('residents', 'PATCH', ['user_id' => 'eq.' . $id], [
        'contact_number' => $phone,
        'address' => $newAddress,
        'profile_data' => $extra,
        'updated_at' => gmdate('c'),
    ], ['return=minimal']);
    bc_sb_rest('profiles', 'PATCH', ['id' => 'eq.' . $id], [
        'phone' => $phoneE164,
        'updated_at' => gmdate('c'),
    ], ['return=minimal']);

    bc_admin_users_reply(200, ['success' => true, 'message' => 'Resident address and mobile number updated.']);
}

// Profile and resident data are restricted to authenticated staff accounts.
bc_sb_require_role(['superadmin']);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = $method === 'GET' ? trim((string) ($_GET['action'] ?? '')) : '';
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
    if (!is_array($decoded)) bc_admin_users_reply(400, ['success' => false, 'error' => 'Invalid JSON body.']);
    $body = $decoded;
    $action = trim((string) ($body['action'] ?? ''));
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    bc_admin_users_reply(405, ['success' => false, 'error' => 'Method not allowed.']);
}

try {
    if ($action === 'reset-password' && $method === 'POST') {
        $id = trim((string) ($body['id'] ?? ''));
        if (!bc_admin_uuid($id)) bc_admin_users_reply(400, ['success' => false, 'error' => 'Invalid resident account ID.']);
        $profileResponse = bc_sb_rest('profiles', 'GET', [
            'id' => 'eq.' . $id, 'role' => 'eq.resident', 'select' => 'email', 'limit' => '1',
        ]);
        $email = (string) ($profileResponse['data'][0]['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) bc_admin_users_reply(400, ['success' => false, 'error' => 'This resident account has no valid email address.']);
        bc_sb_request('auth/v1/recover', 'POST', ['email' => $email], null, false);
        bc_admin_users_reply(200, ['success' => true, 'message' => 'Password reset email sent to ' . $email . '.']);
    }

    if ($action === 'update' && $method === 'POST') bc_admin_update_resident($body);
    if ($action === 'counts' && $method === 'GET') {
        $residents = bc_admin_residents();
        $active = count(array_filter($residents, static fn ($row) => ($row['status'] ?? '') === 'active'));
        bc_admin_users_reply(200, [
            'totalUsers' => count($residents),
            'activeUsers' => $active,
            'offlineUsers' => count($residents) - $active,
            'pendingUsers' => 0,
        ]);
    }
    if (in_array($action, ['registered', 'approved'], true) && $method === 'GET') {
        bc_admin_users_reply(200, bc_admin_residents());
    }
    bc_admin_users_reply(400, ['success' => false, 'error' => 'Invalid action.']);
} catch (Throwable $error) {
    error_log('Admin Supabase resident management: ' . $error->getMessage());
    bc_admin_users_reply(502, ['success' => false, 'error' => 'Could not load or update resident accounts from Supabase. Check the server Supabase settings and database schema.']);
}
