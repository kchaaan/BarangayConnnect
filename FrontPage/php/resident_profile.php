<?php
declare(strict_types=1);
require_once __DIR__ . '/supabase_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function bc_profile_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function bc_profile_body(): array
{
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($decoded)) return $decoded;
    $form = $_POST;
    return is_array($form) ? $form : [];
}

function bc_profile_user_record(array $resident, array $profile): array
{
    $extra = is_array($resident['profile_data'] ?? null) ? $resident['profile_data'] : [];
    $birthDate = (string) ($resident['birth_date'] ?? '');
    $age = 0;
    if ($birthDate !== '') {
        try { $age = (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y; } catch (Throwable $ignored) {}
    }
    $first = (string) ($resident['first_name'] ?? '');
    $middle = (string) ($resident['middle_name'] ?? '');
    $last = (string) ($resident['last_name'] ?? '');
    $address = (string) ($resident['address'] ?? '');

    return array_merge($extra, [
        'success' => true,
        'id' => (string) ($resident['user_id'] ?? ''),
        'residentId' => (string) ($resident['resident_id'] ?? ''),
        'firstname' => $first,
        'lastname' => $last,
        'firstName' => $first,
        'middleName' => $middle,
        'lastName' => $last,
        'email' => (string) ($profile['email'] ?? $resident['email'] ?? ''),
        'mobile' => (string) ($resident['contact_number'] ?? ''),
        'dateOfBirth' => $birthDate,
        'age' => $age,
        'gender' => strtolower((string) ($resident['gender'] ?? '')),
        'civilStatus' => strtolower((string) ($resident['civil_status'] ?? '')),
        'street' => (string) ($extra['street'] ?? $address),
        'barangay' => (string) ($extra['barangay'] ?? ''),
        'city' => (string) ($extra['city'] ?? ''),
        'province' => (string) ($extra['province'] ?? ''),
        'fullAddress' => $address,
        'status' => strtoupper((string) ($profile['status'] ?? 'active')),
        'username' => (string) ($profile['username'] ?? $resident['username'] ?? ''),
    ]);
}

function bc_profile_current_resident(array $user): array
{
    $uid = (string) ($user['user_id'] ?? '');
    if ($uid === '') bc_profile_error(401, 'Authentication required');
    $result = bc_sb_rest('residents', 'GET', [
        'select' => '*',
        'user_id' => 'eq.' . $uid,
        'limit' => '1',
    ]);
    $row = is_array($result['data']) ? ($result['data'][0] ?? null) : null;
    if (!is_array($row)) bc_profile_error(404, 'Resident profile not found');
    return $row;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST', 'PUT'], true)) bc_profile_error(405, 'Method not allowed');

try {
    $user = bc_sb_require_role(['resident']);
    $resident = bc_profile_current_resident($user);
    $requestedId = trim((string) ($_GET['id'] ?? ''));
    $ownedIds = array_filter([
        (string) ($resident['id'] ?? ''),
        (string) ($resident['resident_id'] ?? ''),
        (string) ($resident['user_id'] ?? ''),
    ], static fn($value) => $value !== '');
    if ($requestedId !== '' && !in_array($requestedId, $ownedIds, true)) {
        bc_profile_error(403, 'You may only access your own profile.');
    }

    if ($method === 'GET') {
        echo json_encode(bc_profile_user_record($resident, $user), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = bc_profile_body();
    if (!$body) bc_profile_error(400, 'Invalid JSON body');

    $currentEmail = strtolower(trim((string) ($user['email'] ?? $resident['email'] ?? '')));
    $currentMobile = preg_replace('/\D/', '', (string) ($resident['contact_number'] ?? '')) ?? '';
    $nextEmail = strtolower(trim((string) ($body['email'] ?? $currentEmail)));
    $nextMobile = preg_replace('/\D/', '', (string) ($body['mobile'] ?? $currentMobile)) ?? '';
    if ($nextEmail !== $currentEmail || $nextMobile !== $currentMobile) {
        bc_profile_error(400, 'Email or mobile changes require a separate verified contact update.');
    }

    $allowedTextFields = [
        'firstName' => 'first_name',
        'middleName' => 'middle_name',
        'lastName' => 'last_name',
        'dateOfBirth' => 'birth_date',
        'gender' => 'gender',
        'civilStatus' => 'civil_status',
    ];
    $payload = [];
    foreach ($allowedTextFields as $input => $column) {
        if (!array_key_exists($input, $body)) continue;
        $value = trim((string) $body[$input]);
        if (in_array($input, ['firstName', 'lastName'], true) && $value === '') {
            bc_profile_error(400, 'First and last name are required.');
        }
        if ($input === 'dateOfBirth' && $value !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) bc_profile_error(400, 'Enter a valid date of birth.');
        }
        $payload[$column] = $value;
    }

    $extra = is_array($resident['profile_data'] ?? null) ? $resident['profile_data'] : [];
    $extraFields = [
        'suffix', 'placeOfBirth', 'citizenship', 'nationality', 'religion', 'street',
        'blockLot', 'houseUnit', 'region', 'residentType', 'ownerName', 'employerName',
        'employerAddress', 'yearsResidency', 'employment', 'voterStatus', 'precinctNumber',
        'bloodType', 'vehicleOwner', 'vehicleType', 'plateNumber', 'emergencyName',
        'emergencyNumber', 'emergencyRelationship', 'postalCode', 'barangay', 'city', 'province',
    ];
    foreach ($extraFields as $field) {
        if (array_key_exists($field, $body)) $extra[$field] = trim((string) $body[$field]);
    }
    if (isset($body['sectors']) && is_array($body['sectors'])) {
        $extra['sectors'] = array_values(array_filter(array_map(static fn($v) => trim((string) $v), $body['sectors'])));
    }

    if (array_intersect(['street', 'barangay', 'city', 'province'], array_keys($extra)) !== []) {
        $addressParts = array_filter([
            trim((string) ($extra['street'] ?? '')),
            trim((string) ($extra['barangay'] ?? '')),
            trim((string) ($extra['city'] ?? '')),
            trim((string) ($extra['province'] ?? '')),
        ], static fn($v) => $v !== '');
        $payload['address'] = implode(', ', $addressParts);
    }
    $payload['profile_data'] = $extra;

    if (isset($payload['first_name']) || isset($payload['middle_name']) || isset($payload['last_name'])) {
        $first = (string) ($payload['first_name'] ?? $resident['first_name'] ?? '');
        $middle = (string) ($payload['middle_name'] ?? $resident['middle_name'] ?? '');
        $last = (string) ($payload['last_name'] ?? $resident['last_name'] ?? '');
        $fullName = trim(implode(' ', array_filter([$first, $middle, $last], static fn($v) => trim($v) !== '')));
    } else {
        $fullName = (string) ($user['full_name'] ?? '');
    }

    if (!$payload) bc_profile_error(400, 'No editable profile fields were provided.');
    $residentId = (string) ($resident['id'] ?? '');
    $uid = (string) ($user['user_id'] ?? '');
    if ($residentId === '' || $uid === '') bc_profile_error(503, 'Profile could not be updated.');

    $updated = bc_sb_rest('residents', 'PATCH', [
        'id' => 'eq.' . $residentId,
        'user_id' => 'eq.' . $uid,
    ], $payload, ['return=representation']);
    $updatedResident = is_array($updated['data']) ? ($updated['data'][0] ?? null) : null;
    if (!is_array($updatedResident)) bc_profile_error(404, 'Resident profile not found.');

    if (isset($payload['first_name']) || isset($payload['middle_name']) || isset($payload['last_name'])) {
        try {
            bc_sb_rest('profiles', 'PATCH', ['id' => 'eq.' . $uid], ['full_name' => $fullName], ['return=minimal']);
        } catch (Throwable $ignored) {
            error_log('Resident profile name updated but profile display name sync failed.');
        }
    }

    echo json_encode([
        'success' => true,
        'profile' => bc_profile_user_record($updatedResident, array_merge($user, ['full_name' => $fullName])),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Supabase resident profile API: ' . $e->getMessage());
    bc_profile_error(503, 'Profile service is unavailable. Check Supabase configuration and schema.');
}
