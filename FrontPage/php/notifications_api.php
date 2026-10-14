<?php
/**
 * Resident notifications API.
 * GET  ?action=counts|list&resident_id=
 * PUT  ?action=read&id=&resident_id=
 * PUT  ?action=read_all&resident_id=
 * POST ?action=admin_alert  body: { resident_id, title, message, priority? }
 */
require_once __DIR__ . '/notifications_lib.php';
require_once __DIR__ . '/requests_lib.php';
require_once __DIR__ . '/supabase_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function bc_notif_json_error(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$action = trim($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$residentSession = null;
if (($method === 'GET' && in_array($action, ['counts', 'list'], true))
    || ($method === 'PUT' && in_array($action, ['read', 'read_all'], true))) {
    $residentSession = bc_sb_require_role(['resident']);
} elseif ($method === 'POST' && $action === 'admin_alert') {
    bc_sb_require_role(['admin']);
} else {
    bc_notif_json_error(405, 'Method not allowed');
}

$conn = bc_db_connect();
if (!$conn) {
    bc_notif_json_error(500, bc_db_error_response());
}

bc_ensure_resident_notifications_table($conn);

$residentId = 0;
if ($residentSession) {
    $residentAuthId = (string) ($residentSession['user_id'] ?? '');
    $residentCode = trim((string) ($residentSession['resident']['resident_id'] ?? ''));
    if ($residentCode !== '') {
        $residentOwner = bc_resolve_resident_owner($conn, $residentCode);
        $residentId = (int) ($residentOwner['pk'] ?? 0);
    }
}

if ($method === 'GET' && $action === 'counts') {
    if (preg_match('/^[0-9a-f-]{36}$/i', $residentAuthId)) {
        bc_sync_request_notifications_for_resident($conn, $residentId, $residentAuthId);
    }
    echo json_encode(bc_notification_counts_for_resident($conn, $residentId, $residentAuthId));
    $conn->close();
    exit;
}

if ($method === 'GET' && $action === 'list') {
    if (preg_match('/^[0-9a-f-]{36}$/i', $residentAuthId)) {
        bc_sync_request_notifications_for_resident($conn, $residentId, $residentAuthId);
    }
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
    if ($residentId > 0) {
        $stmt = $conn->prepare(
            'SELECT notification_id, resident_user_id, type, priority, title, message,
                    link_section, reference_id, is_read, created_at
             FROM resident_notifications
             WHERE resident_user_id = ? OR resident_auth_id = ?
             ORDER BY created_at DESC
             LIMIT ?'
        );
        $stmt->bind_param('isi', $residentId, $residentAuthId, $limit);
    } else {
        $stmt = $conn->prepare(
            'SELECT notification_id, resident_user_id, type, priority, title, message,
                    link_section, reference_id, is_read, created_at
             FROM resident_notifications
             WHERE resident_auth_id = ?
             ORDER BY created_at DESC
             LIMIT ?'
        );
        $stmt->bind_param('si', $residentAuthId, $limit);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $out = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $out[] = bc_notification_row_to_api($row);
        }
    }
    $stmt->close();
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'PUT' && $action === 'read') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        bc_notif_json_error(400, 'id is required');
    }
    if ($residentId > 0) {
        $stmt = $conn->prepare(
            'UPDATE resident_notifications SET is_read = 1
             WHERE notification_id = ? AND (resident_user_id = ? OR resident_auth_id = ?)'
        );
        $stmt->bind_param('iis', $id, $residentId, $residentAuthId);
    } else {
        $stmt = $conn->prepare(
            'UPDATE resident_notifications SET is_read = 1
             WHERE notification_id = ? AND resident_auth_id = ?'
        );
        $stmt->bind_param('is', $id, $residentAuthId);
    }
    $stmt->execute();
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'PUT' && $action === 'read_all') {
    if ($residentId > 0) {
        $stmt = $conn->prepare(
            'UPDATE resident_notifications SET is_read = 1
             WHERE (resident_user_id = ? OR resident_auth_id = ?) AND is_read = 0'
        );
        $stmt->bind_param('is', $residentId, $residentAuthId);
    } else {
        $stmt = $conn->prepare(
            'UPDATE resident_notifications SET is_read = 1
             WHERE resident_auth_id = ? AND is_read = 0'
        );
        $stmt->bind_param('s', $residentAuthId);
    }
    $stmt->execute();
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'POST' && $action === 'admin_alert') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        bc_notif_json_error(400, 'Invalid JSON body');
    }
    $targetId = (int) ($body['resident_id'] ?? 0);
    $title = trim((string) ($body['title'] ?? ''));
    $message = trim((string) ($body['message'] ?? ''));
    $priority = strtolower(trim((string) ($body['priority'] ?? 'urgent')));
    if (!in_array($priority, ['urgent', 'normal'], true)) {
        $priority = 'urgent';
    }
    if ($targetId <= 0 || $title === '' || $message === '') {
        bc_notif_json_error(400, 'resident_id, title, and message are required');
    }
    bc_insert_resident_notification(
        $conn,
        $targetId,
        'admin_alert',
        $priority,
        $title,
        $message,
        null,
        null
    );
    $conn->close();
    echo json_encode(['success' => true]);
    exit;
}

$conn->close();
bc_notif_json_error(400, 'Invalid action or method');
