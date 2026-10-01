<?php
/**
 * Feedback API (MySQL).
 *
 * Residents/public submit feedback; admin is view-only (no edit/delete of content).
 *
 * GET  ?action=list|get&id=
 * POST ?action=create          JSON body
 * PUT  ?action=read&id=          marks feedback as read (admin inbox only)
 */
require_once __DIR__ . '/feedback_lib.php';
require_once __DIR__ . '/supabase_client.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function bc_fb_json_error(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function bc_fb_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$conn = bc_db_connect();
if (!$conn) {
    bc_fb_json_error(500, bc_db_error_response());
}

bc_ensure_feedback_table($conn);

$action = trim($_GET['action'] ?? '');
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Residents and public visitors may submit feedback. Reading the inbox and
// marking entries read is reserved for the SuperAdmin.
if (($method === 'GET' && in_array($action, ['list', 'get'], true)) || ($method === 'PUT' && $action === 'read')) {
    bc_sb_require_role(['superadmin']);
}

if ($method === 'GET' && $action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $res = $conn->query(
        'SELECT feedback_id, resident_user_id, submitter_name, status, message, source, is_read, created_at
         FROM resident_feedback
         ORDER BY created_at DESC'
    );
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = bc_feedback_row_to_api($row);
        }
    }
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) {
        bc_fb_json_error(400, 'id is required');
    }
    $row = bc_feedback_fetch_by_id($conn, $id);
    $conn->close();
    if (!$row) {
        bc_fb_json_error(404, 'Feedback not found');
    }
    echo json_encode(bc_feedback_row_to_api($row));
    exit;
}

if ($method === 'POST' && $action === 'create') {
    header('Content-Type: application/json; charset=utf-8');
    $body = bc_fb_read_json_body();
    $payload = bc_feedback_payload_from_body($body);

    if ($payload['status'] === '') {
        bc_fb_json_error(400, 'status must be Satisfied or Not Satisfied');
    }
    if ($payload['message'] === '') {
        bc_fb_json_error(400, 'message is required');
    }

    $residentUserId = $payload['resident_user_id'];
    $submitterName = $payload['submitter_name'];
    $status = $payload['status'];
    $message = $payload['message'];
    $source = $payload['source'];

    if ($residentUserId === null) {
        $stmt = $conn->prepare(
            'INSERT INTO resident_feedback (resident_user_id, submitter_name, status, message, source)
             VALUES (NULL, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssss', $submitterName, $status, $message, $source);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO resident_feedback (resident_user_id, submitter_name, status, message, source)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issss', $residentUserId, $submitterName, $status, $message, $source);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        bc_fb_json_error(500, 'Failed to save feedback');
    }
    $newId = (int) $stmt->insert_id;
    $stmt->close();

    $row = bc_feedback_fetch_by_id($conn, $newId);
    $conn->close();
    echo json_encode([
        'success' => true,
        'feedback' => $row ? bc_feedback_row_to_api($row) : ['id' => $newId],
    ]);
    exit;
}

if ($method === 'PUT' && $action === 'read') {
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) {
        bc_fb_json_error(400, 'id is required');
    }
    $stmt = $conn->prepare('UPDATE resident_feedback SET is_read = 1 WHERE feedback_id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        bc_fb_json_error(500, 'Failed to mark feedback as read');
    }
    $stmt->close();
    $row = bc_feedback_fetch_by_id($conn, $id);
    $conn->close();
    echo json_encode([
        'success' => true,
        'feedback' => $row ? bc_feedback_row_to_api($row) : null,
    ]);
    exit;
}

$conn->close();
bc_fb_json_error(400, 'Unknown action or method');
