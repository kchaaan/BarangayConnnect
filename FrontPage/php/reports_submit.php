<?php
/**
 * Submit anonymous community report (resident R_Reports.html → admin A_Reports.html).
 * POST multipart: data (JSON blob), image (optional file).
 * Response: plain text SUCCESS (legacy UI) or JSON if Accept: application/json
 */
require_once __DIR__ . '/reports_lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'ERROR';
    exit;
}

$conn = bc_db_connect();
if (!$conn) {
    http_response_code(500);
    echo 'ERROR';
    exit;
}

bc_ensure_reports_table($conn);

$rawData = $_POST['data'] ?? '';
if ($rawData === '' && isset($_FILES['data'])) {
    $rawData = file_get_contents($_FILES['data']['tmp_name']);
}

$payload = json_decode($rawData, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'ERROR';
    $conn->close();
    exit;
}

$title = trim((string) ($payload['title'] ?? ''));
$category = trim((string) ($payload['category'] ?? ''));
$location = trim((string) ($payload['location'] ?? ''));
$description = trim((string) ($payload['description'] ?? ''));

if ($title === '' || $category === '' || $location === '' || $description === '') {
    http_response_code(400);
    echo 'ERROR';
    $conn->close();
    exit;
}

$status = 'Pending';
$residentUserId = (int) ($payload['residentUserId'] ?? 0);
$stmt = $conn->prepare(
    'INSERT INTO community_reports (title, category, location, description, status, resident_user_id)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('sssssi', $title, $category, $location, $description, $status, $residentUserId);

if (!$stmt->execute()) {
    http_response_code(500);
    echo 'ERROR';
    $stmt->close();
    $conn->close();
    exit;
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
            $u->execute();
            $u->close();
        }
    }
}

$conn->close();

$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
if (stripos($accept, 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'reportId' => $reportId,
        'message' => 'SUCCESS',
    ]);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo 'SUCCESS';
