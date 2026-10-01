<?php
declare(strict_types=1);

/** Authenticated resident profile read/update for the Supabase-backed portal. */
require_once __DIR__ . '/supabase_client.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function bc_sr_out(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bc_sr_extra(array $resident): array
{
    $extra = $resident['profile_data'] ?? [];
    if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
    return is_array($extra) ? $extra : [];
}

function bc_sr_profile(array $resident, array $account, string $userId): array
{
    $extra = bc_sr_extra($resident);
    $birthDate = (string) ($resident['birth_date'] ?? '');
    $age = null;
    if ($birthDate !== '') {
        try {
            $dob = new DateTimeImmutable($birthDate);
            $age = $dob->diff(new DateTimeImmutable('today'))->y;
        } catch (Throwable $ignored) { $age = null; }
    }
    $street = (string) ($extra['street'] ?? $extra['houseStreet'] ?? $extra['house_street'] ?? '');
    $address = (string) ($resident['address'] ?? '');
    if ($street === '' && $address !== '') {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), static fn($part) => $part !== ''));
        if (count($parts) > 1) {
            $tail = strtolower(implode(' ', array_slice($parts, -3)));
            if (str_contains($tail, 'tambo') || str_contains($tail, 'parañaque') || str_contains($tail, 'metro manila')) {
                $street = implode(', ', array_slice($parts, 0, max(1, count($parts) - 3)));
            }
        }
        if ($street === '') $street = implode(', ', $parts);
    }
    $mobile = preg_replace('/\D/', '', (string) ($resident['contact_number'] ?? '')) ?? '';
    if (str_starts_with($mobile, '63') && strlen($mobile) === 12) $mobile = '0' . substr($mobile, 2);

    return [
        'success' => true,
        'id' => $userId,
        'residentId' => (string) ($resident['resident_id'] ?? ''),
        'firstName' => (string) ($resident['first_name'] ?? ''),
        'firstname' => (string) ($resident['first_name'] ?? ''),
        'middleName' => (string) ($resident['middle_name'] ?? ''),
        'lastName' => (string) ($resident['last_name'] ?? ''),
        'lastname' => (string) ($resident['last_name'] ?? ''),
        'suffix' => (string) ($resident['suffix'] ?? $extra['suffix'] ?? ''),
        'username' => (string) ($resident['username'] ?? $account['username'] ?? ''),
        'email' => (string) ($account['email'] ?? $resident['email'] ?? ''),
        'mobile' => $mobile,
        'dateOfBirth' => $birthDate,
        'age' => $age,
        'gender' => (string) ($resident['gender'] ?? $extra['gender'] ?? ''),
        'placeOfBirth' => (string) ($extra['placeOfBirth'] ?? $extra['place_of_birth'] ?? ''),
        'civilStatus' => (string) ($resident['civil_status'] ?? $extra['civilStatus'] ?? $extra['civil_status'] ?? ''),
        'citizenship' => (string) ($extra['citizenship'] ?? $extra['nationality'] ?? ''),
        'religion' => (string) ($extra['religion'] ?? ''),
        'street' => $street,
        'barangay' => (string) ($extra['barangay'] ?? 'Tambo'),
        'city' => (string) ($extra['city'] ?? 'Parañaque City'),
        'province' => (string) ($extra['province'] ?? 'Metro Manila'),
        'postalCode' => (string) ($extra['postalCode'] ?? $extra['postal_code'] ?? ''),
        'emergencyName' => (string) ($extra['emergencyName'] ?? $extra['emergency_contact_name'] ?? ''),
        'emergencyNumber' => (string) ($extra['emergencyNumber'] ?? $extra['emergency_contact_number'] ?? ''),
        'emergencyRelationship' => (string) ($extra['emergencyRelationship'] ?? $extra['emergency_contact_relationship'] ?? ''),
        'status' => (string) ($account['status'] ?? 'active'),
        'fullAddress' => $address,
    ];
}

try {
    $session = bc_sb_require_role(['resident']);
    $userId = (string) ($session['user_id'] ?? '');
    if ($userId === '') bc_sr_out(401, ['success' => false, 'error' => 'Resident session is missing. Please sign in again.']);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST', 'PUT'], true)) {
        bc_sr_out(405, ['success' => false, 'error' => 'Method not allowed']);
    }

    $residentResult = bc_sb_rest('residents', 'GET', [
        'user_id' => 'eq.' . $userId,
        'select' => '*',
        'limit' => '1',
    ]);
    $resident = $residentResult['data'][0] ?? null;
    if (!is_array($resident)) bc_sr_out(404, ['success' => false, 'error' => 'Resident record not found. Please contact the barangay office.']);

    $accountResult = bc_sb_rest('profiles', 'GET', [
        'id' => 'eq.' . $userId,
        'select' => 'id,status,email,username,full_name',
        'limit' => '1',
    ]);
    $account = $accountResult['data'][0] ?? [];

    if ($method === 'GET') {
        bc_sr_out(200, bc_sr_profile($resident, $account, $userId));
    }

    $raw = file_get_contents('php://input');
    $body = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) bc_sr_out(400, ['success' => false, 'error' => 'Invalid profile data']);

    $first = trim((string) ($body['firstName'] ?? ''));
    $middle = trim((string) ($body['middleName'] ?? ''));
    $last = trim((string) ($body['lastName'] ?? ''));
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $mobile = preg_replace('/\D/', '', (string) ($body['mobile'] ?? '')) ?? '';
    $birth = trim((string) ($body['dateOfBirth'] ?? ''));
    if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $birth === '' || !preg_match('/^09\d{9}$/', $mobile)) {
        bc_sr_out(400, ['success' => false, 'error' => 'Please provide a valid name, email, birth date, and 11-digit mobile number.']);
    }
    $currentEmail = strtolower((string) ($account['email'] ?? $resident['email'] ?? ''));
    if ($currentEmail !== '' && $email !== $currentEmail) {
        bc_sr_out(400, ['success' => false, 'error' => 'Changing the sign-in email requires email verification. Keep your current email for now.']);
    }
    $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
    if (!$birthDate || $birthDate->format('Y-m-d') !== $birth || $birthDate > new DateTimeImmutable('today')) {
        bc_sr_out(400, ['success' => false, 'error' => 'Enter a valid date of birth.']);
    }

    $extra = bc_sr_extra($resident);
    foreach (['suffix', 'placeOfBirth', 'civilStatus', 'citizenship', 'religion', 'street', 'postalCode', 'emergencyName', 'emergencyNumber', 'emergencyRelationship', 'barangay', 'city', 'province'] as $field) {
        if (array_key_exists($field, $body)) $extra[$field] = trim((string) $body[$field]);
    }
    $street = trim((string) ($body['street'] ?? ''));
    $barangay = trim((string) ($body['barangay'] ?? 'Tambo'));
    $city = trim((string) ($body['city'] ?? 'Parañaque City'));
    $province = trim((string) ($body['province'] ?? 'Metro Manila'));
    $address = implode(', ', array_filter([$street, $barangay, $city, $province], static fn($part) => $part !== ''));
    $extra['street'] = $street;
    $extra['barangay'] = $barangay;
    $extra['city'] = $city;
    $extra['province'] = $province;

    $residentPatch = [
        'first_name' => $first,
        'middle_name' => $middle !== '' ? $middle : null,
        'last_name' => $last,
        'suffix' => trim((string) ($body['suffix'] ?? '')) ?: null,
        'birth_date' => $birth,
        'gender' => trim((string) ($body['gender'] ?? '')),
        'address' => $address,
        'contact_number' => $mobile,
        'civil_status' => trim((string) ($body['civilStatus'] ?? '')),
        'profile_data' => $extra,
    ];
    bc_sb_rest('residents', 'PATCH', ['user_id' => 'eq.' . $userId], $residentPatch, ['return=minimal']);

    $fullName = trim($first . ' ' . $middle . ' ' . $last);
    bc_sb_rest('profiles', 'PATCH', ['id' => 'eq.' . $userId], ['full_name' => $fullName], ['return=minimal']);

    $updatedResult = bc_sb_rest('residents', 'GET', [
        'user_id' => 'eq.' . $userId,
        'select' => '*',
        'limit' => '1',
    ]);
    $updated = $updatedResult['data'][0] ?? $residentPatch;
    $_SESSION['bc_supabase']['resident'] = $updated;
    $_SESSION['bc_supabase']['full_name'] = $fullName;
    $profileResult = bc_sb_rest('profiles', 'GET', [
        'id' => 'eq.' . $userId,
        'select' => 'id,status,email,username,full_name',
        'limit' => '1',
    ]);
    bc_sr_out(200, ['success' => true, 'profile' => bc_sr_profile($updated, $profileResult['data'][0] ?? $account, $userId)]);
} catch (Throwable $error) {
    error_log('Resident profile API: ' . $error->getMessage());
    bc_sr_out(503, ['success' => false, 'error' => 'Could not load or save your profile right now. Please try again.']);
}
