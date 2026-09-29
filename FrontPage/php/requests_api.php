<?php
/**
 * Service requests API for admin pages (A_Request.html, etc.).
 * GET  ?action=list|get|files&id=
 * PUT  ?action=status&id=  body: {"status":"in_progress","remarks":"..."}
 */
require_once __DIR__ . '/requests_lib.php';
require_once __DIR__ . '/notifications_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$conn = bc_db_connect();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => bc_db_error_response()]);
    exit;
}

bc_ensure_requests_tables($conn);

$action = trim($_GET['action'] ?? '');
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $action === 'list') {
    $res = $conn->query(bc_service_requests_select_sql() . ' ORDER BY submission_date DESC');
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = bc_request_row_to_api($row);
        }
    }
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'my') {
    $residentRaw = trim((string) ($_GET['resident_id'] ?? ''));
    if ($residentRaw === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'resident_id is required']);
        $conn->close();
        exit;
    }

    $out = bc_fetch_service_requests_for_resident($conn, $residentRaw);
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'get' && $id > 0) {
    $stmt = $conn->prepare(bc_service_requests_select_sql() . ' WHERE request_pk = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Request not found']);
        exit;
    }

    echo json_encode(bc_request_row_to_api($row));
    exit;
}

if ($method === 'GET' && $action === 'files' && $id > 0) {
    $stmt = $conn->prepare(
        'SELECT file_label, file_name, file_type, file_path FROM service_request_files WHERE request_pk = ? ORDER BY file_id'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $files = [];
    $uploadRoot = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $abs = $uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['file_path']);
            if (!is_readable($abs)) {
                continue;
            }
            $bin = file_get_contents($abs);
            if ($bin === false) {
                continue;
            }
            $mime = $row['file_type'] ?: 'application/octet-stream';
            $files[] = [
                'label' => $row['file_label'],
                'fileName' => $row['file_name'],
                'fileType' => $mime,
                'fileBase64' => base64_encode($bin),
            ];
        }
    }
    $stmt->close();
    $conn->close();
    echo json_encode($files);
    exit;
}

if ($method === 'PUT' && $action === 'status' && $id > 0) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        $conn->close();
        exit;
    }

    $newStatus = strtolower(trim((string) ($body['status'] ?? '')));
    $allowed = ['pending', 'in_progress', 'completed', 'rejected'];
    if (!in_array($newStatus, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        $conn->close();
        exit;
    }

    $remarks = isset($body['remarks']) ? trim((string) $body['remarks']) : null;
    if ($remarks === '') {
        $remarks = null;
    }

    $prevRow = null;
    $oldStatus = '';
    $prevStmt = $conn->prepare(bc_service_requests_select_sql() . ' WHERE request_pk = ? LIMIT 1');
    $prevStmt->bind_param('i', $id);
    $prevStmt->execute();
    $prevResult = $prevStmt->get_result();
    if ($prevResult) {
        $prevRow = $prevResult->fetch_assoc() ?: null;
        $oldStatus = $prevRow ? (string) ($prevRow['status'] ?? '') : '';
    }
    $prevStmt->close();

    $stmt = $conn->prepare('UPDATE service_requests SET status = ?, remarks = ? WHERE request_pk = ?');
    $stmt->bind_param('ssi', $newStatus, $remarks, $id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        $conn->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Update failed']);
        exit;
    }

    if ($prevRow) {
        $prevRow['request_pk'] = $id;
        bc_notify_request_update($conn, $prevRow, $newStatus, $remarks, $oldStatus);
    }

    $conn->close();
    echo json_encode(['success' => true, 'id' => $id, 'status' => $newStatus]);
    exit;
}

$conn->close();
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action or method']);
