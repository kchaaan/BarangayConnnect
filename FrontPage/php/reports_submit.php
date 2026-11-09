<?php
/**
 * Submit anonymous community report (resident R_Reports.html → admin A_Reports.html).
 * POST multipart: data (JSON blob), image (optional file).
 * Response: JSON with the saved report ID, or a specific validation/database error.
 */
require_once __DIR__ . '/reports_lib.php';
require_once __DIR__ . '/supabase_client.php';
require_once __DIR__ . '/requests_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bc_report_submit_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    bc_report_submit_reply(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$reportSession = bc_sb_require_role(['resident']);

$conn = bc_db_connect();
if (!$conn) {
    bc_report_submit_reply(503, ['success' => false, 'error' => bc_db_error_response()]);
}

bc_ensure_reports_table($conn);

$residentCode = trim((string) ($reportSession['resident']['resident_id'] ?? ''));
$reportOwner = $residentCode !== '' ? bc_resolve_resident_owner($conn, $residentCode) : null;
$residentUserId = (int) ($reportOwner['pk'] ?? 0);
if ($residentUserId <= 0) {
    $conn->close();
    bc_report_submit_reply(403, ['success' => false, 'error' => 'Your resident profile is not linked to the report database. Please contact the barangay office.']);
}

$rawData = $_POST['data'] ?? '';
if ($rawData === '' && isset($_FILES['data'])) {
    $rawData = file_get_contents($_FILES['data']['tmp_name']);
}

$payload = json_decode($rawData, true);
if (!is_array($payload)) {
    $conn->close();
    bc_report_submit_reply(400, ['success' => false, 'error' => 'The report form data could not be read. Please review the form and try again.']);
}

$title = trim((string) ($payload['title'] ?? ''));
$category = trim((string) ($payload['category'] ?? ''));
$location = trim((string) ($payload['location'] ?? ''));
$description = trim((string) ($payload['description'] ?? ''));

if ($title === '' || $category === '' || $location === '' || $description === '') {
    $conn->close();
    bc_report_submit_reply(400, ['success' => false, 'error' => 'Complete the issue title, category, location, and description.']);
}

$status = 'Pending';
$stmt = $conn->prepare(
    'INSERT INTO community_reports (title, category, location, description, status, resident_user_id)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('sssssi', $title, $category, $location, $description, $status, $residentUserId);

if (!$stmt->execute()) {
    error_log('BarangayConnect report insert failed: ' . $stmt->error);
    $stmt->close();
    $conn->close();
    bc_report_submit_reply(500, ['success' => false, 'error' => 'The report could not be saved to the reports database. Please try again.']);
}

$reportId = (int) $conn->insert_id;
$stmt->close();

$imagePath = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK && $_FILES['image']['tmp_name'] !== '') {
    $uploadBase = bc_reports_upload_dir() . DIRECTORY_SEPARATOR . $reportId;
    if (!is_dir($uploadBase)) {
        mkdir($uploadBase, 0755, true);
    }
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['image']['name']));
    if ($safeName === '') {
        $safeName = 'attachment.jpg';
    }
    $destPath = $uploadBase . DIRECTORY_SEPARATOR . $safeName;
    if (move_uploaded_file($_FILES['image']['tmp_name'], $destPath)) {
        $imagePath = 'reports/' . $reportId . '/' . $safeName;
        $u = $conn->prepare('UPDATE community_reports SET image_path = ? WHERE report_id = ?');
        if ($u) {
            $u->bind_param('si', $imagePath, $reportId);
            if (!$u->execute()) {
                error_log('BarangayConnect report attachment path update failed: ' . $u->error);
            }
            $u->close();
        }
    }
}

$conn->close();

bc_report_submit_reply(201, [
    'success' => true,
    'reportId' => $reportId,
    'message' => 'Your report was saved successfully.',
]);
