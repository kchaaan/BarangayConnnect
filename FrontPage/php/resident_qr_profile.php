<?php
declare(strict_types=1);

/** Return a resident record to its owner or authenticated barangay staff for QR print view. */
require_once __DIR__ . '/supabase_client.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function bc_rqp_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $session = bc_sb_require_role(['resident', 'admin', 'superadmin']);
    $residentCode = trim((string) ($_GET['resident'] ?? ''));
    if ($residentCode === '' || strlen($residentCode) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/', $residentCode)) {
        bc_rqp_reply(400, ['success' => false, 'error' => 'Invalid resident QR link.']);
    }

    $result = bc_sb_rest('residents', 'GET', [
        'resident_id' => 'eq.' . $residentCode,
        'select' => '*',
        'limit' => '1',
    ]);
    $row = $result['data'][0] ?? null;
    if (!is_array($row)) bc_rqp_reply(404, ['success' => false, 'error' => 'Resident record not found.']);

    $ownerId = (string) ($row['user_id'] ?? '');
    if (($session['role'] ?? '') === 'resident' && ($ownerId === '' || $ownerId !== (string) ($session['user_id'] ?? ''))) {
        bc_rqp_reply(403, ['success' => false, 'error' => 'This QR code belongs to another resident.']);
    }

    $extra = $row['profile_data'] ?? [];
    if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
    if (!is_array($extra)) $extra = [];
    $birthDate = (string) ($row['birth_date'] ?? '');
    $age = null;
    if ($birthDate !== '') {
        try { $age = (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y; }
        catch (Throwable $ignored) { $age = null; }
    }
    $accountResult = bc_sb_rest('profiles', 'GET', [
        'id' => 'eq.' . $ownerId,
        'select' => 'email,role',
        'limit' => '1',
    ]);
    $account = $accountResult['data'][0] ?? [];
    if (($account['role'] ?? '') !== 'resident') bc_rqp_reply(404, ['success' => false, 'error' => 'Resident record not found.']);

    $mobile = preg_replace('/\D/', '', (string) ($row['contact_number'] ?? '')) ?? '';
    if (str_starts_with($mobile, '63') && strlen($mobile) === 12) $mobile = '0' . substr($mobile, 2);
    $street = (string) ($extra['street'] ?? $extra['houseStreet'] ?? $extra['house_street'] ?? '');
    $address = (string) ($row['address'] ?? '');
    if ($street !== '') {
        $address = implode(', ', array_filter([
            $street,
            (string) ($extra['barangay'] ?? 'Tambo'),
            (string) ($extra['city'] ?? 'Parañaque City'),
            (string) ($extra['province'] ?? 'Metro Manila'),
            (string) ($extra['postalCode'] ?? $extra['postal_code'] ?? ''),
        ], static fn($part) => trim($part) !== ''));
    }
    bc_rqp_reply(200, [
        'success' => true,
        'profile' => [
            'residentId' => (string) ($row['resident_id'] ?? ''),
            'firstName' => (string) ($row['first_name'] ?? ''),
            'middleName' => (string) ($row['middle_name'] ?? ''),
            'lastName' => (string) ($row['last_name'] ?? ''),
            'suffix' => (string) ($row['suffix'] ?? $extra['suffix'] ?? ''),
            'dateOfBirth' => $birthDate,
            'age' => $age,
            'gender' => (string) ($row['gender'] ?? $extra['gender'] ?? ''),
            'placeOfBirth' => (string) ($extra['placeOfBirth'] ?? $extra['place_of_birth'] ?? ''),
            'civilStatus' => (string) ($row['civil_status'] ?? $extra['civilStatus'] ?? $extra['civil_status'] ?? ''),
            'citizenship' => (string) ($extra['citizenship'] ?? $extra['nationality'] ?? ''),
            'religion' => (string) ($extra['religion'] ?? ''),
            'address' => $address,
            'mobile' => $mobile,
            'email' => (string) ($account['email'] ?? ''),
            'emergencyName' => (string) ($extra['emergencyName'] ?? $extra['emergency_contact_name'] ?? ''),
            'emergencyNumber' => (string) ($extra['emergencyNumber'] ?? $extra['emergency_contact_number'] ?? ''),
            'emergencyRelationship' => (string) ($extra['emergencyRelationship'] ?? $extra['emergency_contact_relationship'] ?? ''),
        ],
    ]);
} catch (Throwable $error) {
    error_log('Resident QR profile: ' . $error->getMessage());
    bc_rqp_reply(503, ['success' => false, 'error' => 'Could not load the resident print page. Try again later.']);
}
