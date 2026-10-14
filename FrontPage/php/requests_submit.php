<?php
/**
 * Submit resident service request → MySQL (shows on Admin A_Request.html).
 * POST multipart: data (JSON blob), files[] (optional file_labels[]).
 */
require_once __DIR__ . '/requests_lib.php';
require_once __DIR__ . '/supabase_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$requestSession = bc_sb_require_role(['resident']);
$authUserId = trim((string) ($requestSession['user_id'] ?? ''));
if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Resident account is not linked. Please sign in again.']);
    exit;
}

try {
    $residentResult = bc_sb_rest('residents', 'GET', [
        'user_id' => 'eq.' . $authUserId,
        'select' => 'id,resident_id',
        'limit' => '1',
    ]);
    $resident = $residentResult['data'][0] ?? null;
} catch (Throwable $e) {
    error_log('Service request resident lookup failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Could not verify your resident profile. Please try again.']);
    exit;
}
$residentCode = is_array($resident) ? trim((string) ($resident['resident_id'] ?? '')) : '';
if ($residentCode === '' || empty($resident['id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Resident profile is incomplete. Contact the barangay administrator.']);
    exit;
}

$conn = bc_db_connect();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => bc_db_error_response()]);
    exit;
}

bc_ensure_requests_tables($conn);

$rawData = $_POST['data'] ?? '';
if ($rawData === '' && isset($_FILES['data'])) {
    $rawData = file_get_contents($_FILES['data']['tmp_name']);
}

$payload = json_decode($rawData, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request data (JSON required)']);
    $conn->close();
    exit;
}

$documentType = trim((string) ($payload['documentType'] ?? ''));
if ($documentType === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Document type is required']);
    $conn->close();
    exit;
}

// Never trust client-supplied owner IDs or status.
$residentId = 0;
$payload['residentId'] = $residentCode;
unset($payload['residentIdLocal']);
$status = 'pending';

$requestType = trim((string) ($payload['requestType'] ?? 'personal_identification'));
if ($requestType === '') {
    $requestType = 'personal_identification';
}

$code = bc_generate_request_code($conn);
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$rowCols = bc_payload_to_row_columns($payload);

$stmt = $conn->prepare(
    'INSERT INTO service_requests (
        request_code, resident_user_id, resident_auth_id, request_type, document_type, status,
        first_name, middle_name, last_name, suffix, gender, birthdate, age,
        place_of_birth, civil_status, citizenship, religion, email, mobile,
        street, house_unit, block_lot, house_street, barangay, city, province, region,
        full_address, request_notes, payload_json
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error (prepare)']);
    $conn->close();
    exit;
}

$ageVal = $rowCols['age'] !== null ? (string) $rowCols['age'] : null;
$stmt->bind_param(
    'sissss' . str_repeat('s', 24),
    $code,
    $residentId,
    $authUserId,
    $requestType,
    $documentType,
    $status,
    $rowCols['first_name'],
    $rowCols['middle_name'],
    $rowCols['last_name'],
    $rowCols['suffix'],
    $rowCols['gender'],
    $rowCols['birthdate'],
    $ageVal,
    $rowCols['place_of_birth'],
    $rowCols['civil_status'],
    $rowCols['citizenship'],
    $rowCols['religion'],
    $rowCols['email'],
    $rowCols['mobile'],
    $rowCols['street'],
    $rowCols['house_unit'],
    $rowCols['block_lot'],
    $rowCols['house_street'],
    $rowCols['barangay'],
    $rowCols['city'],
    $rowCols['province'],
    $rowCols['region'],
    $rowCols['full_address'],
    $rowCols['request_notes'],
    $json
);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $stmt->error ?: 'Insert failed']);
    $stmt->close();
    $conn->close();
    exit;
}

$requestPk = (int) $conn->insert_id;
$stmt->close();

$defaultLabels = ['Valid ID', 'Proof of Residency', '1×1 or 2×2 Photo'];
$fileLabels = $_POST['file_labels'] ?? [];
if (!is_array($fileLabels)) {
    $fileLabels = $fileLabels !== '' && $fileLabels !== null ? [$fileLabels] : [];
}

$uploadBase = bc_requests_upload_dir() . DIRECTORY_SEPARATOR . $requestPk;
if (!is_dir($uploadBase)) {
    mkdir($uploadBase, 0755, true);
}

$uploads = bc_parse_uploaded_files('files');
$savedFiles = 0;

foreach ($uploads as $i => $file) {
    if ($file['error'] !== UPLOAD_ERR_OK || $file['tmp_name'] === '') {
        continue;
    }

    $label = $fileLabels[$i] ?? $defaultLabels[$i] ?? ('Attachment ' . ($i + 1));
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
    $destName = ($i + 1) . '_' . $safeName;
    $destPath = $uploadBase . DIRECTORY_SEPARATOR . $destName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        continue;
    }

    $relPath = 'requests/' . $requestPk . '/' . $destName;
    $fStmt = $conn->prepare(
        'INSERT INTO service_request_files (request_pk, file_label, file_name, file_type, file_path)
         VALUES (?,?,?,?,?)'
    );
    if ($fStmt) {
        $fStmt->bind_param('issss', $requestPk, $label, $safeName, $file['type'], $relPath);
        $fStmt->execute();
        $fStmt->close();
        $savedFiles++;
    }
}

$conn->close();

echo json_encode([
    'success' => true,
    'id' => $requestPk,
    'requestId' => $code,
    'message' => 'Request submitted successfully',
    'filesSaved' => $savedFiles,
]);
