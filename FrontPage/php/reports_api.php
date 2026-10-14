<?php
/**
 * Community reports API (resident list + admin A_Reports.html).
 *
 * GET  ?action=all|archived|get&id= (community listing)
 * GET  ?action=mine (resident-owned reports, including archived, for personal dashboard counts)
 * PUT  ?action=cancel&id=
 * PUT  ?action=status&id=  body: plain text status (e.g. "In Progress")
 */
require_once __DIR__ . '/reports_lib.php';
require_once __DIR__ . '/notifications_lib.php';
require_once __DIR__ . '/supabase_client.php';
require_once __DIR__ . '/requests_lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$reportMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$reportAction = trim((string) ($_GET['action'] ?? ''));
$reportSession = null;
if ($reportMethod === 'GET' && in_array($reportAction, ['all', 'list', 'archived', 'get'], true)) {
    // Residents retain the existing community-report read screens. Ordinary
    // admins cannot open the report inbox; the SuperAdmin retains full access.
    $reportSession = bc_sb_require_role(['resident', 'superadmin']);
} elseif ($reportMethod === 'GET' && $reportAction === 'mine') {
    $reportSession = bc_sb_require_role(['resident']);
} elseif ($reportMethod === 'PUT' && $reportAction === 'cancel') {
    $reportSession = bc_sb_require_role(['resident']);
} elseif ($reportMethod === 'PUT' && $reportAction === 'status') {
    $reportSession = bc_sb_require_role(['superadmin']);
} else {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Unsupported report action.']);
    exit;
}

$conn = bc_db_connect();
if (!$conn) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => function_exists('bc_db_error_response')
            ? bc_db_error_response()
            : 'The reports database is unavailable. Start MySQL in XAMPP and try again.',
    ]);
    exit;
}

bc_ensure_reports_archive_columns($conn);
bc_auto_archive_expired_resolved($conn);

$reportAuthId = (string) ($reportSession['user_id'] ?? '');
$reportOwner = null;
$reportOwnerId = 0;
if ($reportMethod === 'GET' && $reportAction === 'mine' || $reportMethod === 'PUT' && $reportAction === 'cancel') {
    $residentCode = trim((string) ($reportSession['resident']['resident_id'] ?? ''));
    $reportOwner = $residentCode !== '' ? bc_resolve_resident_owner($conn, $residentCode) : null;
    $reportOwnerId = (int) ($reportOwner['pk'] ?? 0);
}

$action = trim($_GET['action'] ?? '');
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && ($action === 'all' || $action === 'list')) {
    header('Content-Type: application/json; charset=utf-8');
    $res = $conn->query(bc_reports_select_sql() . ' WHERE archived_at IS NULL ORDER BY created_at DESC');
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = bc_report_row_to_api($row, false);
        }
    }
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'mine') {
    header('Content-Type: application/json; charset=utf-8');
    $stmt = $conn->prepare(
        bc_reports_select_sql() . ' WHERE resident_auth_id = ? OR (resident_auth_id IS NULL AND resident_user_id = ?) ORDER BY created_at DESC'
    );
    $stmt->bind_param('si', $reportAuthId, $reportOwnerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) $out[] = bc_report_row_to_api($row, false);
    }
    $stmt->close();
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'archived') {
    header('Content-Type: application/json; charset=utf-8');
    $res = $conn->query(bc_reports_select_sql() . ' WHERE archived_at IS NOT NULL ORDER BY archived_at DESC');
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = bc_report_row_to_api($row, false);
        }
    }
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'get' && $id > 0) {
    header('Content-Type: application/json; charset=utf-8');
    $stmt = $conn->prepare(bc_reports_select_sql() . ' WHERE report_id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Report not found']);
        exit;
    }

    echo json_encode(bc_report_row_to_api($row, true));
    exit;
}

if ($method === 'PUT' && $action === 'cancel' && $id > 0) {
    header('Content-Type: text/plain; charset=utf-8');
    $status = 'Cancelled';
    $stmt = $conn->prepare(
        "UPDATE community_reports
         SET status = ?, archived_at = NOW(), resolved_at = NULL, updated_at = NOW()
         WHERE report_id = ?
           AND (resident_auth_id = ? OR (resident_auth_id IS NULL AND resident_user_id = ?))
           AND archived_at IS NULL AND status NOT IN ('Cancelled', 'Resolved')"
    );
    $stmt->bind_param('sis', $status, $id, $reportAuthId, $reportOwnerId);
    $ok = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    echo $ok ? 'SUCCESS' : 'ERROR';
    exit;
}

if ($method === 'PUT' && $action === 'status' && $id > 0) {
    header('Content-Type: text/plain; charset=utf-8');
    $raw = file_get_contents('php://input');
    $newStatus = bc_normalize_report_status(trim((string) $raw));
    $allowed = ['Pending', 'In Progress', 'Resolved', 'Cancelled'];
    if (!in_array($newStatus, $allowed, true)) {
        http_response_code(400);
        echo 'ERROR';
        $conn->close();
        exit;
    }

    $prevRow = null;
    $oldStatus = '';
    $prevStmt = $conn->prepare(bc_reports_select_sql() . ' WHERE report_id = ? LIMIT 1');
    $prevStmt->bind_param('i', $id);
    $prevStmt->execute();
    $prevResult = $prevStmt->get_result();
    if ($prevResult) {
        $prevRow = $prevResult->fetch_assoc() ?: null;
        $oldStatus = $prevRow ? (string) ($prevRow['status'] ?? '') : '';
    }
    $prevStmt->close();

    if ($newStatus === 'Cancelled') {
        $stmt = $conn->prepare(
            "UPDATE community_reports
             SET status = ?, archived_at = NOW(), resolved_at = NULL, updated_at = NOW()
             WHERE report_id = ? AND archived_at IS NULL"
        );
        $stmt->bind_param('si', $newStatus, $id);
    } elseif ($newStatus === 'Resolved') {
        $stmt = $conn->prepare(
            "UPDATE community_reports
             SET status = ?, resolved_at = NOW(), archived_at = NULL, updated_at = NOW()
             WHERE report_id = ? AND archived_at IS NULL"
        );
        $stmt->bind_param('si', $newStatus, $id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE community_reports
             SET status = ?, resolved_at = NULL, archived_at = NULL, updated_at = NOW()
             WHERE report_id = ? AND archived_at IS NULL"
        );
        $stmt->bind_param('si', $newStatus, $id);
    }

    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $prevRow) {
        bc_notify_report_update($conn, $prevRow, $newStatus, $oldStatus);
    }

    $conn->close();
    echo $ok ? 'SUCCESS' : 'ERROR';
    exit;
}

$conn->close();
http_response_code(400);
header('Content-Type: text/plain; charset=utf-8');
echo 'ERROR';
