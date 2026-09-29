<?php
/**
 * Residents table (users_resident) — readable columns + profile_extra JSON backup.
 */
require_once __DIR__ . '/db_config.php';

/** MySQL table name for registered residents (renamed from `users`). */
function bc_users_table(): string
{
    return 'users_resident';
}

function bc_rename_users_table_if_needed(mysqli $conn): bool
{
    $newName = bc_users_table();
    $hasOld = $conn->query("SHOW TABLES LIKE 'users'");
    $hasNew = $conn->query("SHOW TABLES LIKE '{$newName}'");
    if ($hasOld && $hasOld->num_rows > 0 && (!$hasNew || $hasNew->num_rows === 0)) {
        return (bool) $conn->query("RENAME TABLE `users` TO `{$newName}`");
    }

    return false;
}

function bc_users_detail_columns(): array
{
    return [
        'suffix' => 'VARCHAR(20) NULL',
        'place_of_birth' => 'VARCHAR(128) NULL',
        'citizenship' => 'VARCHAR(64) NULL',
        'nationality' => 'VARCHAR(64) NULL',
        'religion' => 'VARCHAR(128) NULL',
        'street' => 'VARCHAR(128) NULL',
        'block_lot' => 'VARCHAR(32) NULL',
        'house_unit' => 'VARCHAR(32) NULL',
        'region' => 'VARCHAR(32) NULL',
        'full_address' => 'VARCHAR(512) NULL',
        'resident_type' => 'VARCHAR(64) NULL',
        'owner_name' => 'VARCHAR(128) NULL',
        'employer_name' => 'VARCHAR(128) NULL',
        'employer_address' => 'VARCHAR(255) NULL',
        'years_residency' => 'VARCHAR(16) NULL',
        'employment' => 'VARCHAR(128) NULL',
        'voter_status' => 'VARCHAR(16) NULL',
        'precinct_number' => 'VARCHAR(32) NULL',
        'blood_type' => 'VARCHAR(16) NULL',
        'vehicle_owner' => 'VARCHAR(8) NULL',
        'vehicle_type' => 'VARCHAR(64) NULL',
        'plate_number' => 'VARCHAR(32) NULL',
        'sector' => 'VARCHAR(128) NULL',
        'emergency_contact_name' => 'VARCHAR(128) NULL',
        'emergency_contact_number' => 'VARCHAR(20) NULL',
        'emergency_contact_relationship' => 'VARCHAR(64) NULL',
    ];
}

/** Columns used for one-account-per-resident identity checks. */
function bc_resident_identity_columns(): array
{
    return [
        'barangay_id_number' => 'VARCHAR(32) NULL',
        'national_id_number' => 'VARCHAR(32) NULL',
        'voter_id_number' => 'VARCHAR(32) NULL',
        'identity_fingerprint' => 'CHAR(64) NULL',
    ];
}

function bc_normalize_identity_token(?string $value): string
{
    $v = strtolower(trim((string) $value));
    $v = preg_replace('/\s+/', ' ', $v) ?? $v;

    return $v;
}

function bc_normalize_id_number(?string $value): string
{
    $v = strtoupper(trim((string) $value));
    $v = preg_replace('/[\s\-]+/', '', $v) ?? $v;

    return $v;
}

/**
 * Stable fingerprint: full name + birth date + address (not username alone).
 */
function bc_build_resident_identity_fingerprint(
    string $first,
    string $middle,
    string $last,
    ?string $suffix,
    string $birthdate,
    string $houseStreet,
    string $barangay,
    string $city,
    string $province
): string {
    $nameParts = array_filter([
        bc_normalize_identity_token($first),
        bc_normalize_identity_token($middle),
        bc_normalize_identity_token($last),
        bc_normalize_identity_token($suffix),
    ], static fn ($p) => $p !== '');
    $addrParts = array_filter([
        bc_normalize_identity_token($houseStreet),
        bc_normalize_identity_token($barangay),
        bc_normalize_identity_token($city),
        bc_normalize_identity_token($province),
    ], static fn ($p) => $p !== '');

    $payload = implode('|', $nameParts)
        . '||' . trim($birthdate)
        . '||' . implode('|', $addrParts);

    return hash('sha256', $payload);
}

function bc_users_try_add_unique_index(mysqli $conn, string $table, string $indexName, string $column): void
{
    $safeTable = $conn->real_escape_string($table);
    $safeIndex = $conn->real_escape_string($indexName);
    $safeCol = $conn->real_escape_string($column);
    $exists = $conn->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
    if ($exists && $exists->num_rows > 0) {
        return;
    }
    if (!$conn->query("ALTER TABLE `{$safeTable}` ADD UNIQUE KEY `{$safeIndex}` (`{$safeCol}`)")) {
        $errno = (int) $conn->errno;
        if ($errno !== 1061 && $errno !== 1062) {
            error_log('bc_users_try_add_unique_index: ' . $conn->error);
        }
    }
}

/**
 * @param array<string, mixed> $data
 * @return array{ok:bool,error:?string,http_status:int}
 */
function bc_check_resident_registration_conflicts(mysqli $conn, array $data): array
{
    $table = bc_users_table();
    $username = trim((string) ($data['username'] ?? ''));
    $contact = trim((string) ($data['contact_number'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $barangayId = bc_normalize_id_number($data['barangay_id_number'] ?? '');
    $nationalId = bc_normalize_id_number($data['national_id_number'] ?? '');
    $voterId = bc_normalize_id_number($data['voter_id_number'] ?? '');
    $fingerprint = trim((string) ($data['identity_fingerprint'] ?? ''));

    $checks = [
        ['column' => 'username', 'value' => $username, 'label' => 'Username is already registered'],
        ['column' => 'contact_number', 'value' => $contact, 'label' => 'This mobile number is already registered'],
        ['column' => 'email', 'value' => $email, 'label' => 'This email address is already registered', 'lower' => true],
        ['column' => 'barangay_id_number', 'value' => $barangayId, 'label' => 'This Barangay Resident ID is already linked to an account'],
        ['column' => 'national_id_number', 'value' => $nationalId, 'label' => 'This National ID number is already registered'],
        ['column' => 'voter_id_number', 'value' => $voterId, 'label' => 'This Voter\'s ID number is already registered'],
        ['column' => 'identity_fingerprint', 'value' => $fingerprint, 'label' => 'An account already exists for this person (same name, birth date, and address)'],
    ];

    foreach ($checks as $check) {
        $value = (string) $check['value'];
        if ($value === '') {
            continue;
        }

        $column = $check['column'];
        if (!empty($check['lower'])) {
            $sql = "SELECT user_id FROM `{$table}` WHERE LOWER(TRIM(`{$column}`)) = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return ['ok' => false, 'error' => 'Server error (duplicate check)', 'http_status' => 500];
            }
            $stmt->bind_param('s', $value);
        } else {
            $sql = "SELECT user_id FROM `{$table}` WHERE `{$column}` = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return ['ok' => false, 'error' => 'Server error (duplicate check)', 'http_status' => 500];
            }
            $stmt->bind_param('s', $value);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows > 0;
        $stmt->close();

        if ($exists) {
            return ['ok' => false, 'error' => (string) $check['label'], 'http_status' => 409];
        }
    }

    return ['ok' => true, 'error' => null, 'http_status' => 200];
}

function bc_upgrade_users_schema(mysqli $conn): void
{
    bc_rename_users_table_if_needed($conn);
    $table = bc_users_table();

    foreach (bc_users_detail_columns() as $name => $definition) {
        $safe = $conn->real_escape_string($name);
        $check = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safe}'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN {$name} {$definition}");
        }
    }

    foreach (bc_resident_identity_columns() as $name => $definition) {
        $safe = $conn->real_escape_string($name);
        $check = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safe}'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN {$name} {$definition}");
        }
    }

    bc_users_try_add_unique_index($conn, $table, 'uk_users_email', 'email');
    bc_users_try_add_unique_index($conn, $table, 'uk_users_barangay_id', 'barangay_id_number');
    bc_users_try_add_unique_index($conn, $table, 'uk_users_national_id', 'national_id_number');
    bc_users_try_add_unique_index($conn, $table, 'uk_users_voter_id', 'voter_id_number');
    bc_users_try_add_unique_index($conn, $table, 'uk_users_identity_fp', 'identity_fingerprint');

    // Keep a readable view for browsing resident rows in phpMyAdmin.
    // Renamed from `v_users_detail` → `v_users_resident_detail`.
    $conn->query('DROP VIEW IF EXISTS v_users_detail');
    $conn->query("CREATE OR REPLACE VIEW v_users_resident_detail AS
        SELECT
            user_id,
            resident_id,
            first_name,
            middle_name,
            last_name,
            suffix,
            gender,
            birthdate,
            age,
            civil_status,
            place_of_birth,
            citizenship,
            nationality,
            religion,
            contact_number,
            email,
            street,
            house_unit,
            block_lot,
            house_street,
            barangay,
            city,
            province,
            region,
            full_address,
            resident_type,
            owner_name,
            employer_name,
            employer_address,
            years_residency,
            employment,
            voter_status,
            precinct_number,
            blood_type,
            vehicle_owner,
            vehicle_type,
            plate_number,
            sector,
            emergency_contact_name,
            emergency_contact_number,
            emergency_contact_relationship,
            username,
            status,
            created_at,
            approved_at
        FROM `{$table}`");
}

/**
 * @param array<string, mixed> $extra Decoded profile_extra JSON
 * @return array<string, mixed>
 */
function bc_profile_extra_to_columns(array $extra, array $address = []): array
{
    $str = static function ($key) use ($extra): ?string {
        if (!isset($extra[$key])) {
            return null;
        }
        $v = trim((string) $extra[$key]);
        return $v === '' ? null : $v;
    };

    $sector = null;
    if (!empty($extra['sectors']) && is_array($extra['sectors'])) {
        $sector = trim((string) ($extra['sectors'][0] ?? ''));
        if ($sector === '') {
            $sector = null;
        }
    }

    $parts = array_filter([
        trim((string) ($address['house_street'] ?? '')),
        trim((string) ($address['barangay'] ?? '')),
        trim((string) ($address['city'] ?? '')),
        trim((string) ($address['province'] ?? '')),
        trim((string) ($extra['region'] ?? $address['region'] ?? '')),
    ], static fn ($s) => $s !== '');

    $fullAddress = $parts !== [] ? implode(', ', $parts) : null;

    return [
        'suffix' => $str('suffix'),
        'place_of_birth' => $str('placeOfBirth') ?? $str('birthplace'),
        'citizenship' => $str('citizenship'),
        'nationality' => $str('nationality'),
        'religion' => $str('religion'),
        'street' => $str('street'),
        'block_lot' => $str('blockLot'),
        'house_unit' => $str('houseUnit'),
        'region' => $str('region'),
        'full_address' => $fullAddress,
        'resident_type' => $str('residentType'),
        'owner_name' => $str('ownerName'),
        'employer_name' => $str('employerName'),
        'employer_address' => $str('employerAddress'),
        'years_residency' => isset($extra['yearsResidency']) ? trim((string) $extra['yearsResidency']) : null,
        'employment' => $str('employment'),
        'voter_status' => $str('voterStatus'),
        'precinct_number' => $str('precinctNumber'),
        'blood_type' => $str('bloodType'),
        'vehicle_owner' => $str('vehicleOwner'),
        'vehicle_type' => $str('vehicleType'),
        'plate_number' => $str('plateNumber'),
        'sector' => $sector,
        'emergency_contact_name' => $str('emergencyName') ?? $str('emergencyContactName'),
        'emergency_contact_number' => $str('emergencyNumber') ?? $str('emergencyContactNumber'),
        'emergency_contact_relationship' => $str('emergencyRelationship'),
    ];
}

function bc_backfill_users_from_profile_extra(mysqli $conn): int
{
    $table = bc_users_table();
    $updated = 0;
    $res = $conn->query(
        "SELECT user_id, house_street, barangay, city, province, profile_extra
         FROM `{$table}` WHERE profile_extra IS NOT NULL AND profile_extra != ''"
    );
    if (!$res) {
        return 0;
    }

    $detailCols = array_keys(bc_users_detail_columns());
    $setParts = [];
    foreach ($detailCols as $c) {
        $setParts[] = "{$c} = ?";
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $setParts) . ' WHERE user_id = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    while ($row = $res->fetch_assoc()) {
        $extra = json_decode($row['profile_extra'], true);
        if (!is_array($extra)) {
            continue;
        }
        $cols = bc_profile_extra_to_columns($extra, $row);
        $types = str_repeat('s', count($detailCols)) . 'i';
        $values = [];
        foreach ($detailCols as $c) {
            $values[] = $cols[$c];
        }
        $values[] = (int) $row['user_id'];
        $stmt->bind_param($types, ...$values);
        if ($stmt->execute()) {
            $updated++;
        }
    }
    $stmt->close();

    return $updated;
}

function bc_users_select_sql(): string
{
    $table = bc_users_table();

    return 'SELECT user_id, resident_id, first_name, middle_name, last_name, suffix, gender, birthdate, age,
        civil_status, place_of_birth, citizenship, nationality, religion, contact_number, email,
        street, block_lot, house_unit, house_street, barangay, city, province, region, full_address,
        resident_type, owner_name, employer_name, employer_address, years_residency, employment,
        voter_status, precinct_number, blood_type, vehicle_owner, vehicle_type, plate_number, sector,
        emergency_contact_name, emergency_contact_number, emergency_contact_relationship,
        profile_extra, username, password, status, created_at, approved_at FROM `' . $table . '`';
}

function bc_normalize_profile_select_value(?string $value): string
{
    $v = strtolower(trim((string) $value));
    return $v;
}

function bc_user_fetch_by_id_or_code(mysqli $conn, string $idParam): ?array
{
    $idParam = trim($idParam);
    if ($idParam === '') {
        return null;
    }

    if (preg_match('/^RES-/i', $idParam) || !ctype_digit($idParam)) {
        $stmt = $conn->prepare(bc_users_select_sql() . ' WHERE resident_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $idParam);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    $id = (int) $idParam;
    if ($id <= 0) {
        return null;
    }

    $stmt = $conn->prepare(bc_users_select_sql() . ' WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function bc_user_row_to_profile(array $row): array
{
    $extra = [];
    if (!empty($row['profile_extra'])) {
        $decoded = json_decode($row['profile_extra'], true);
        if (is_array($decoded)) {
            $extra = $decoded;
        }
    }

    $fromDb = [
        'suffix' => $row['suffix'] ?? null,
        'placeOfBirth' => $row['place_of_birth'] ?? null,
        'citizenship' => $row['citizenship'] ?? null,
        'nationality' => $row['nationality'] ?? null,
        'religion' => $row['religion'] ?? null,
        'blockLot' => $row['block_lot'] ?? null,
        'houseUnit' => $row['house_unit'] ?? null,
        'region' => $row['region'] ?? null,
        'residentType' => $row['resident_type'] ?? null,
        'ownerName' => $row['owner_name'] ?? null,
        'employerName' => $row['employer_name'] ?? null,
        'employerAddress' => $row['employer_address'] ?? null,
        'yearsResidency' => $row['years_residency'] ?? null,
        'employment' => $row['employment'] ?? null,
        'voterStatus' => $row['voter_status'] ?? null,
        'precinctNumber' => $row['precinct_number'] ?? null,
        'bloodType' => $row['blood_type'] ?? null,
        'vehicleOwner' => $row['vehicle_owner'] ?? null,
        'vehicleType' => $row['vehicle_type'] ?? null,
        'plateNumber' => $row['plate_number'] ?? null,
        'emergencyName' => $row['emergency_contact_name'] ?? null,
        'emergencyNumber' => $row['emergency_contact_number'] ?? null,
        'emergencyRelationship' => $row['emergency_contact_relationship'] ?? null,
    ];

    if (!empty($row['sector'])) {
        $fromDb['sectors'] = [$row['sector']];
    }

    $dbStatus = $row['status'] ?? '';
    if (strcasecmp($dbStatus, 'Rejected') === 0) {
        $status = 'REJECTED';
    } elseif (strcasecmp($dbStatus, 'Approved') === 0) {
        $status = 'ACTIVE';
    } else {
        $status = strtoupper($dbStatus);
    }

    $profile = array_merge($extra, [
        'success' => true,
        'id' => (int) $row['user_id'],
        'residentId' => $row['resident_id'] ?? '',
        'firstname' => $row['first_name'],
        'lastname' => $row['last_name'],
        'firstName' => $row['first_name'],
        'middleName' => $row['middle_name'] ?? '',
        'lastName' => $row['last_name'],
        'email' => $row['email'] ?? '',
        'mobile' => $row['contact_number'] ?? '',
        'dateOfBirth' => $row['birthdate'] ?? '',
        'age' => (int) ($row['age'] ?? 0),
        'gender' => bc_normalize_profile_select_value($row['gender'] ?? ''),
        'civilStatus' => bc_normalize_profile_select_value($row['civil_status'] ?? ''),
        'street' => $row['street'] ?? $row['house_street'] ?? '',
        'barangay' => $row['barangay'] ?? '',
        'city' => $row['city'] ?? '',
        'province' => $row['province'] ?? '',
        'status' => $status,
        'username' => $row['username'] ?? '',
    ]);

    foreach ($fromDb as $k => $v) {
        if ($v !== null && $v !== '') {
            $profile[$k] = $v;
        }
    }

    if (!empty($row['full_address'])) {
        $profile['fullAddress'] = $row['full_address'];
    }

    if (!isset($profile['postalCode']) && !empty($extra['postalCode'])) {
        $profile['postalCode'] = $extra['postalCode'];
    }

    return $profile;
}

/**
 * @param array<string, mixed> $body
 * @return array{ok:bool,error:?string,fields:array<string,mixed>}
 */
function bc_user_profile_update_fields_from_body(array $body, array $existingRow): array
{
    $firstName = trim((string) ($body['firstName'] ?? ''));
    $lastName = trim((string) ($body['lastName'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $mobile = trim((string) ($body['mobile'] ?? ''));
    $dateOfBirth = trim((string) ($body['dateOfBirth'] ?? ''));
    $gender = trim((string) ($body['gender'] ?? ''));

    if ($firstName === '' || $lastName === '' || $email === '' || $mobile === '' || $dateOfBirth === '' || $gender === '') {
        return ['ok' => false, 'error' => 'Missing required profile fields', 'fields' => []];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email address', 'fields' => []];
    }

    $digits = preg_replace('/\D/', '', $mobile) ?? '';
    if (strlen($digits) !== 11) {
        return ['ok' => false, 'error' => 'Mobile number must be 11 digits', 'fields' => []];
    }

    $age = isset($body['age']) ? (int) $body['age'] : 0;
    if ($age <= 0 && $dateOfBirth !== '') {
        $dob = strtotime($dateOfBirth);
        if ($dob) {
            $age = (int) date('Y') - (int) date('Y', $dob);
            if (date('md') < date('md', $dob)) {
                $age--;
            }
        }
    }

    $street = trim((string) ($body['street'] ?? ''));
    $barangay = trim((string) ($body['barangay'] ?? $existingRow['barangay'] ?? 'Tambo'));
    $city = trim((string) ($body['city'] ?? $existingRow['city'] ?? 'Parañaque City'));
    $province = trim((string) ($body['province'] ?? $existingRow['province'] ?? 'Metro Manila'));
    $postalCode = trim((string) ($body['postalCode'] ?? ''));

    $extra = [];
    if (!empty($existingRow['profile_extra'])) {
        $decoded = json_decode((string) $existingRow['profile_extra'], true);
        if (is_array($decoded)) {
            $extra = $decoded;
        }
    }

    $extra['suffix'] = trim((string) ($body['suffix'] ?? ''));
    $extra['placeOfBirth'] = trim((string) ($body['placeOfBirth'] ?? ''));
    $extra['civilStatus'] = trim((string) ($body['civilStatus'] ?? ''));
    $extra['citizenship'] = trim((string) ($body['citizenship'] ?? ''));
    $extra['religion'] = trim((string) ($body['religion'] ?? ''));
    $extra['street'] = $street;
    $extra['postalCode'] = $postalCode;
    $extra['emergencyName'] = trim((string) ($body['emergencyName'] ?? ''));
    $extra['emergencyNumber'] = trim((string) ($body['emergencyNumber'] ?? ''));
    $extra['emergencyRelationship'] = trim((string) ($body['emergencyRelationship'] ?? ''));

    $detail = bc_profile_extra_to_columns($extra, [
        'house_street' => $street,
        'barangay' => $barangay,
        'city' => $city,
        'province' => $province,
        'region' => $existingRow['region'] ?? null,
    ]);

    return [
        'ok' => true,
        'error' => null,
        'fields' => [
            'first_name' => $firstName,
            'middle_name' => trim((string) ($body['middleName'] ?? '')),
            'last_name' => $lastName,
            'suffix' => $detail['suffix'],
            'gender' => $gender,
            'birthdate' => $dateOfBirth,
            'age' => max(0, $age),
            'civil_status' => trim((string) ($body['civilStatus'] ?? '')),
            'place_of_birth' => $detail['place_of_birth'],
            'citizenship' => $detail['citizenship'],
            'religion' => $detail['religion'],
            'contact_number' => $digits,
            'email' => $email,
            'street' => $street,
            'house_street' => $street,
            'barangay' => $barangay,
            'city' => $city,
            'province' => $province,
            'full_address' => $detail['full_address'],
            'emergency_contact_name' => $detail['emergency_contact_name'],
            'emergency_contact_number' => $detail['emergency_contact_number'],
            'emergency_contact_relationship' => $detail['emergency_contact_relationship'],
            'profile_extra' => json_encode($extra, JSON_UNESCAPED_UNICODE),
        ],
    ];
}

/**
 * @param array<string, mixed> $body
 * @return array{success:bool,error?:string,profile?:array<string,mixed>}
 */
function bc_update_resident_profile(mysqli $conn, int $userId, array $body): array
{
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Invalid resident id'];
    }

    $stmt = $conn->prepare(bc_users_select_sql() . ' WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        return ['success' => false, 'error' => 'Server error'];
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$existing) {
        return ['success' => false, 'error' => 'Resident not found'];
    }

    if (strcasecmp((string) ($existing['status'] ?? ''), 'Rejected') === 0) {
        return ['success' => false, 'error' => 'Account is not active'];
    }

    $parsed = bc_user_profile_update_fields_from_body($body, $existing);
    if (!$parsed['ok']) {
        return ['success' => false, 'error' => (string) $parsed['error']];
    }

    $f = $parsed['fields'];
    $table = bc_users_table();
    $sql = "UPDATE `{$table}` SET
        first_name = ?, middle_name = ?, last_name = ?, suffix = ?,
        gender = ?, birthdate = ?, age = ?, civil_status = ?,
        place_of_birth = ?, citizenship = ?, religion = ?,
        contact_number = ?, email = ?,
        street = ?, house_street = ?, barangay = ?, city = ?, province = ?, full_address = ?,
        emergency_contact_name = ?, emergency_contact_number = ?, emergency_contact_relationship = ?,
        profile_extra = ?
        WHERE user_id = ?";

    $update = $conn->prepare($sql);
    if (!$update) {
        return ['success' => false, 'error' => 'Server error (update prepare)'];
    }

    $update->bind_param(
        'ssssssissssssssssssssssi',
        $f['first_name'],
        $f['middle_name'],
        $f['last_name'],
        $f['suffix'],
        $f['gender'],
        $f['birthdate'],
        $f['age'],
        $f['civil_status'],
        $f['place_of_birth'],
        $f['citizenship'],
        $f['religion'],
        $f['contact_number'],
        $f['email'],
        $f['street'],
        $f['house_street'],
        $f['barangay'],
        $f['city'],
        $f['province'],
        $f['full_address'],
        $f['emergency_contact_name'],
        $f['emergency_contact_number'],
        $f['emergency_contact_relationship'],
        $f['profile_extra'],
        $userId
    );

    if (!$update->execute()) {
        $err = $update->error;
        $errno = $update->errno;
        $update->close();
        if ($errno === 1062) {
            return ['success' => false, 'error' => 'Email or phone number is already used by another account'];
        }
        return ['success' => false, 'error' => $err ?: 'Failed to update profile'];
    }
    $update->close();

    $fetch = $conn->prepare(bc_users_select_sql() . ' WHERE user_id = ? LIMIT 1');
    $fetch->bind_param('i', $userId);
    $fetch->execute();
    $row = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    return [
        'success' => true,
        'profile' => $row ? bc_user_row_to_profile($row) : null,
    ];
}
