<?php
/**
 * Shared helpers for service requests (XAMPP / MySQL).
 */
require_once __DIR__ . '/db_config.php';

/** DB columns for resident form fields (readable in phpMyAdmin). */
function bc_service_request_detail_columns(): array
{
    return [
        'first_name' => 'VARCHAR(80) NULL',
        'middle_name' => 'VARCHAR(80) NULL',
        'last_name' => 'VARCHAR(80) NULL',
        'suffix' => 'VARCHAR(20) NULL',
        'gender' => 'VARCHAR(24) NULL',
        'birthdate' => 'DATE NULL',
        'age' => 'TINYINT UNSIGNED NULL',
        'place_of_birth' => 'VARCHAR(128) NULL',
        'civil_status' => 'VARCHAR(32) NULL',
        'citizenship' => 'VARCHAR(64) NULL',
        'religion' => 'VARCHAR(128) NULL',
        'email' => 'VARCHAR(128) NULL',
        'mobile' => 'VARCHAR(20) NULL',
        'street' => 'VARCHAR(128) NULL',
        'house_unit' => 'VARCHAR(32) NULL',
        'block_lot' => 'VARCHAR(32) NULL',
        'house_street' => 'VARCHAR(255) NULL',
        'barangay' => 'VARCHAR(64) NULL',
        'city' => 'VARCHAR(128) NULL',
        'province' => 'VARCHAR(64) NULL',
        'region' => 'VARCHAR(32) NULL',
        'full_address' => 'VARCHAR(512) NULL',
        'request_notes' => 'TEXT NULL',
    ];
}

function bc_upgrade_service_requests_schema(mysqli $conn): void
{
    foreach (bc_service_request_detail_columns() as $name => $definition) {
        $safe = $conn->real_escape_string($name);
        $check = $conn->query("SHOW COLUMNS FROM service_requests LIKE '{$safe}'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE service_requests ADD COLUMN {$name} {$definition}");
        }
    }

    $authColumn = $conn->query("SHOW COLUMNS FROM service_requests LIKE 'resident_auth_id'");
    if ($authColumn && $authColumn->num_rows === 0) {
        $conn->query('ALTER TABLE service_requests ADD COLUMN resident_auth_id CHAR(36) NULL AFTER resident_user_id');
    }

    $conn->query('CREATE OR REPLACE VIEW v_service_requests_detail AS
        SELECT
            request_pk,
            request_code,
            resident_user_id,
            resident_auth_id,
            request_type,
            document_type,
            status,
            remarks,
            first_name,
            middle_name,
            last_name,
            suffix,
            gender,
            birthdate,
            age,
            place_of_birth,
            civil_status,
            citizenship,
            religion,
            email,
            mobile,
            street,
            house_unit,
            block_lot,
            house_street,
            barangay,
            city,
            province,
            region,
            full_address,
            request_notes,
            submission_date,
            updated_at
        FROM service_requests');
}

/**
 * Map JSON payload keys → database column values.
 *
 * @return array<string, mixed>
 */
function bc_payload_to_row_columns(array $payload): array
{
    $str = static function ($key) use ($payload): ?string {
        if (!isset($payload[$key])) {
            return null;
        }
        $v = trim((string) $payload[$key]);
        return $v === '' ? null : $v;
    };

    $birthdate = null;
    if (!empty($payload['birthdate'])) {
        $ts = strtotime((string) $payload['birthdate']);
        if ($ts !== false) {
            $birthdate = date('Y-m-d', $ts);
        }
    }

    $age = null;
    if (isset($payload['age']) && $payload['age'] !== '' && is_numeric($payload['age'])) {
        $age = (int) $payload['age'];
        if ($age < 0 || $age > 150) {
            $age = null;
        }
    }

    return [
        'first_name' => $str('firstName'),
        'middle_name' => $str('middleName'),
        'last_name' => $str('lastName'),
        'suffix' => $str('suffix'),
        'gender' => $str('gender'),
        'birthdate' => $birthdate,
        'age' => $age,
        'place_of_birth' => $str('placeOfBirth'),
        'civil_status' => $str('civilStatus'),
        'citizenship' => $str('citizenship'),
        'religion' => $str('religion'),
        'email' => $str('email'),
        'mobile' => $str('mobile'),
        'street' => $str('street'),
        'house_unit' => $str('houseUnit'),
        'block_lot' => $str('blockLot'),
        'house_street' => $str('houseStreet'),
        'barangay' => $str('barangay'),
        'city' => $str('city'),
        'province' => $str('province'),
        'region' => $str('region'),
        'full_address' => $str('address'),
        'request_notes' => $str('requestNotes'),
    ];
}

/**
 * Copy payload_json into dedicated columns for older rows.
 */
function bc_backfill_request_columns_from_json(mysqli $conn): int
{
    $updated = 0;
    $res = $conn->query(
        "SELECT request_pk, payload_json FROM service_requests
         WHERE payload_json IS NOT NULL AND payload_json != ''
         LIMIT 500"
    );
    if (!$res) {
        return 0;
    }

    $detailCols = array_keys(bc_service_request_detail_columns());
    $setParts = [];
    foreach ($detailCols as $c) {
        $setParts[] = "{$c} = ?";
    }
    $sql = 'UPDATE service_requests SET ' . implode(', ', $setParts) . ' WHERE request_pk = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    while ($row = $res->fetch_assoc()) {
        $payload = json_decode($row['payload_json'], true);
        if (!is_array($payload)) {
            continue;
        }
        $cols = bc_payload_to_row_columns($payload);
        $types = str_repeat('s', count($detailCols)) . 'i';
        $values = [];
        foreach ($detailCols as $c) {
            $values[] = $cols[$c];
        }
        $values[] = (int) $row['request_pk'];
        $stmt->bind_param($types, ...$values);
        if ($stmt->execute()) {
            $updated++;
        }
    }
    $stmt->close();

    return $updated;
}

function bc_ensure_requests_tables(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS service_requests (
        request_pk INT AUTO_INCREMENT PRIMARY KEY,
        request_code VARCHAR(32) NOT NULL UNIQUE,
        resident_user_id INT NULL,
        resident_auth_id CHAR(36) NULL,
        request_type VARCHAR(64) NOT NULL DEFAULT 'personal_identification',
        document_type VARCHAR(128) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        remarks TEXT NULL,
        first_name VARCHAR(80) NULL,
        middle_name VARCHAR(80) NULL,
        last_name VARCHAR(80) NULL,
        suffix VARCHAR(20) NULL,
        gender VARCHAR(24) NULL,
        birthdate DATE NULL,
        age TINYINT UNSIGNED NULL,
        place_of_birth VARCHAR(128) NULL,
        civil_status VARCHAR(32) NULL,
        citizenship VARCHAR(64) NULL,
        religion VARCHAR(128) NULL,
        email VARCHAR(128) NULL,
        mobile VARCHAR(20) NULL,
        street VARCHAR(128) NULL,
        house_unit VARCHAR(32) NULL,
        block_lot VARCHAR(32) NULL,
        house_street VARCHAR(255) NULL,
        barangay VARCHAR(64) NULL,
        city VARCHAR(128) NULL,
        province VARCHAR(64) NULL,
        region VARCHAR(32) NULL,
        full_address VARCHAR(512) NULL,
        request_notes TEXT NULL,
        payload_json LONGTEXT NOT NULL,
        submission_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sr_status (status),
        INDEX idx_sr_resident (resident_user_id),
        INDEX idx_sr_name (last_name, first_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS service_request_files (
        file_id INT AUTO_INCREMENT PRIMARY KEY,
        request_pk INT NOT NULL,
        file_label VARCHAR(64) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_type VARCHAR(128) NOT NULL,
        file_path VARCHAR(512) NOT NULL,
        INDEX idx_srf_request (request_pk)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    bc_upgrade_service_requests_schema($conn);
    bc_backfill_request_columns_from_json($conn);
}

function bc_generate_request_code(mysqli $conn): string
{
    $r = $conn->query('SELECT MAX(request_pk) AS m FROM service_requests');
    $n = 1;
    if ($r) {
        $row = $r->fetch_assoc();
        $n = ((int) ($row['m'] ?? 0)) + 1;
    }

    return 'REQ-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
}

/**
 * @return array<string, mixed>
 */
function bc_row_columns_to_api(array $row): array
{
    $fromDb = [
        'firstName' => $row['first_name'] ?? null,
        'middleName' => $row['middle_name'] ?? null,
        'lastName' => $row['last_name'] ?? null,
        'suffix' => $row['suffix'] ?? null,
        'gender' => $row['gender'] ?? null,
        'birthdate' => $row['birthdate'] ?? null,
        'age' => isset($row['age']) ? (int) $row['age'] : null,
        'placeOfBirth' => $row['place_of_birth'] ?? null,
        'civilStatus' => $row['civil_status'] ?? null,
        'citizenship' => $row['citizenship'] ?? null,
        'religion' => $row['religion'] ?? null,
        'email' => $row['email'] ?? null,
        'mobile' => $row['mobile'] ?? null,
        'street' => $row['street'] ?? null,
        'houseUnit' => $row['house_unit'] ?? null,
        'blockLot' => $row['block_lot'] ?? null,
        'houseStreet' => $row['house_street'] ?? null,
        'barangay' => $row['barangay'] ?? null,
        'city' => $row['city'] ?? null,
        'province' => $row['province'] ?? null,
        'region' => $row['region'] ?? null,
        'address' => $row['full_address'] ?? null,
        'requestNotes' => $row['request_notes'] ?? null,
    ];

    $out = [];
    foreach ($fromDb as $k => $v) {
        if ($v !== null && $v !== '') {
            $out[$k] = $v;
        }
    }

    return $out;
}

function bc_service_requests_select_sql(): string
{
    return 'SELECT request_pk, request_code, resident_user_id, resident_auth_id, request_type, document_type, status, remarks,
        first_name, middle_name, last_name, suffix, gender, birthdate, age, place_of_birth,
        civil_status, citizenship, religion, email, mobile, street, house_unit, block_lot,
        house_street, barangay, city, province, region, full_address, request_notes,
        payload_json, submission_date, updated_at FROM service_requests';
}

/**
 * Resolve logged-in resident to DB user_id and match tokens (RES-xxx, numeric id).
 *
 * @return array{pk: int, code: string, first_name: string, last_name: string, match_tokens: list<string>}|null
 */
function bc_resolve_resident_owner(mysqli $conn, string $residentRaw): ?array
{
    require_once __DIR__ . '/users_lib.php';

    $residentRaw = trim($residentRaw);
    if ($residentRaw === '') {
        return null;
    }

    $ut = bc_users_table();
    $pk = 0;
    $code = '';
    $ownerFirst = '';
    $ownerLast = '';

    if (preg_match('/^RES-/i', $residentRaw)) {
        $stmt = $conn->prepare("SELECT user_id, resident_id, first_name, last_name FROM `{$ut}` WHERE resident_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $residentRaw);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                $pk = (int) $row['user_id'];
                $code = trim((string) ($row['resident_id'] ?? ''));
                $ownerFirst = trim((string) ($row['first_name'] ?? ''));
                $ownerLast = trim((string) ($row['last_name'] ?? ''));
            }
        }
    } elseif (is_numeric($residentRaw)) {
        $pk = (int) $residentRaw;
        if ($pk > 0) {
            $stmt = $conn->prepare("SELECT user_id, resident_id, first_name, last_name FROM `{$ut}` WHERE user_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $pk);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                if ($row) {
                    $code = trim((string) ($row['resident_id'] ?? ''));
                    $ownerFirst = trim((string) ($row['first_name'] ?? ''));
                    $ownerLast = trim((string) ($row['last_name'] ?? ''));
                }
            }
        }
    }

    if ($pk <= 0) {
        return null;
    }

    $tokens = [];
    foreach ([(string) $pk, $code, $residentRaw] as $t) {
        $t = trim($t);
        if ($t !== '') {
            $tokens[$t] = true;
        }
    }

    return [
        'pk' => $pk,
        'code' => $code,
        'first_name' => $ownerFirst,
        'last_name' => $ownerLast,
        'match_tokens' => array_keys($tokens),
    ];
}

/**
 * Service requests belonging to one resident only (strict owner filter).
 *
 * @return list<array<string, mixed>>
 */
function bc_fetch_service_requests_for_resident(mysqli $conn, string $residentRaw, string $authUserId = ''): array
{
    if ($authUserId !== '' && preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        $stmt = $conn->prepare(
            bc_service_requests_select_sql() . ' WHERE resident_auth_id = ? ORDER BY submission_date DESC'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $authUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $out[] = bc_request_row_to_api($row);
            }
        }
        $stmt->close();
        return $out;
    }

    $owner = bc_resolve_resident_owner($conn, $residentRaw);
    if (!$owner || $owner['pk'] <= 0) {
        return [];
    }

    $pk = $owner['pk'];
    $code = trim((string) ($owner['code'] ?? ''));
    $ownerFirst = strtolower(trim((string) ($owner['first_name'] ?? '')));
    $ownerLast = strtolower(trim((string) ($owner['last_name'] ?? '')));
    $tokens = $owner['match_tokens'];
    $jsonParts = [];
    $jsonTypes = '';
    $jsonParams = [];

    if ($code !== '') {
        // Connected data rule:
        // 1) Always include rows owned by this resident_user_id.
        // 2) Also include older rows without resident_user_id that carry resident code in JSON.
        $sql = bc_service_requests_select_sql() . "
            WHERE resident_user_id = ?
               OR (
                    COALESCE(resident_user_id, 0) = 0
                    AND (
                        JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.residentIdLocal')) = ?
                        OR JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.residentId')) = ?
                        OR JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.residentId')) = ?
                    )
               )
            ORDER BY submission_date DESC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $pkStr = (string) $pk;
        $stmt->bind_param('isss', $pk, $code, $code, $pkStr);
    } else {
        foreach ($tokens as $token) {
            $jsonParts[] = 'JSON_UNQUOTE(JSON_EXTRACT(payload_json, \'$.residentId\')) = ?';
            $jsonParts[] = 'JSON_UNQUOTE(JSON_EXTRACT(payload_json, \'$.residentIdLocal\')) = ?';
            $jsonTypes .= 'ss';
            $jsonParams[] = $token;
            $jsonParams[] = $token;
        }

        $legacyMatch = $jsonParts !== [] ? '(' . implode(' OR ', $jsonParts) . ')' : '0';

        $sql = bc_service_requests_select_sql() . "
            WHERE resident_user_id = ?
               OR (
                    COALESCE(resident_user_id, 0) = 0
                    AND {$legacyMatch}
               )
            ORDER BY submission_date DESC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $types = 'i' . $jsonTypes;
        $params = array_merge([$pk], $jsonParams);
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $out = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rowPk = isset($row['resident_user_id']) ? (int) $row['resident_user_id'] : 0;
            if ($rowPk > 0 && $rowPk !== $pk) {
                continue;
            }

            // Extra guard for mixed legacy data:
            // if rows share the same numeric resident_user_id, keep only rows that match owner name.
            if ($rowPk > 0 && $ownerFirst !== '' && $ownerLast !== '') {
                $rowFirst = strtolower(trim((string) ($row['first_name'] ?? '')));
                $rowLast = strtolower(trim((string) ($row['last_name'] ?? '')));
                if ($rowFirst !== '' && $rowLast !== '' && ($rowFirst !== $ownerFirst || $rowLast !== $ownerLast)) {
                    continue;
                }
            }
            $out[] = bc_request_row_to_api($row);
        }
    }
    $stmt->close();

    return $out;
}

function bc_request_row_to_api(array $row): array
{
    $payload = [];
    if (!empty($row['payload_json'])) {
        $decoded = json_decode($row['payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $fromColumns = bc_row_columns_to_api($row);

    $updatedAt = null;
    if (!empty($row['updated_at'])) {
        $ts = strtotime($row['updated_at']);
        if ($ts !== false) {
            $updatedAt = date('c', $ts);
        }
    }

    $out = array_merge($payload, $fromColumns, [
        'id' => (int) $row['request_pk'],
        'requestId' => $row['request_code'],
        'requestType' => $row['request_type'] ?? ($payload['requestType'] ?? null),
        'documentType' => $row['document_type'],
        'status' => $row['status'],
        'submissionDate' => date('c', strtotime($row['submission_date'])),
        'updatedAt' => $updatedAt,
        'remarks' => $row['remarks'] ?? null,
        'residentUserId' => isset($row['resident_user_id']) ? (int) $row['resident_user_id'] : 0,
        'residentId' => $payload['residentId'] ?? null,
        'residentIdLocal' => $payload['residentIdLocal'] ?? null,
    ]);

    foreach ($fromColumns as $k => $v) {
        if ($v !== null && $v !== '') {
            $out[$k] = $v;
        }
    }

    if (!isset($out['requestNotes']) && isset($payload['requestNotes'])) {
        $out['requestNotes'] = $payload['requestNotes'];
    }

    return $out;
}

/**
 * Parse $_FILES field (supports files[] multi-upload from FormData).
 *
 * @return list<array{name: string, tmp_name: string, error: int, type: string, size: int}>
 */
function bc_parse_uploaded_files(string $field = 'files'): array
{
    if (empty($_FILES[$field])) {
        return [];
    }

    $files = $_FILES[$field];
    $list = [];

    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $list[] = [
                'name' => (string) $files['name'][$i],
                'tmp_name' => (string) $files['tmp_name'][$i],
                'error' => (int) $files['error'][$i],
                'type' => (string) ($files['type'][$i] ?? ''),
                'size' => (int) ($files['size'][$i] ?? 0),
            ];
        }
    } elseif (($files['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $list[] = [
            'name' => (string) ($files['name'] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'] ?? ''),
            'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'type' => (string) ($files['type'] ?? ''),
            'size' => (int) ($files['size'] ?? 0),
        ];
    }

    return $list;
}

function bc_requests_upload_dir(): string
{
    $dir = realpath(__DIR__ . '/../uploads');
    if ($dir === false) {
        $dir = __DIR__ . '/../uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $dir = realpath($dir) ?: __DIR__ . '/../uploads';
    }
    $requestsDir = $dir . DIRECTORY_SEPARATOR . 'requests';
    if (!is_dir($requestsDir)) {
        mkdir($requestsDir, 0755, true);
    }

    return $requestsDir;
}
