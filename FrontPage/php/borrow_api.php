<?php
/**
 * Borrow requests API.
 * GET  ?action=list|my|stats|monitoring|history|get&id=
 * POST ?action=submit
 * PUT  ?action=status&id=
 */
require_once __DIR__ . '/borrow_lib.php';
require_once __DIR__ . '/notifications_lib.php';
require_once __DIR__ . '/supabase_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
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

bc_ensure_borrow_tables($conn);

$action = trim($_GET['action'] ?? '');
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$authSession = null;
if ($method === 'GET' && $action === 'my') {
    $authSession = bc_sb_require_role(['resident']);
} elseif (($method === 'GET' && in_array($action, ['list', 'stats', 'monitoring', 'history', 'get'], true))
    || ($method === 'PUT' && $action === 'status')) {
    $authSession = bc_sb_require_role(['admin']);
} elseif ($method === 'POST' && $action === 'submit') {
    $authSession = bc_sb_require_role(['resident']);
}

if ($method === 'GET' && $action === 'list') {
    bc_mark_overdue_borrows($conn);
    $status = trim($_GET['status'] ?? 'all');
    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
    $search = $_GET['search'] ?? null;
    $statuses = [];
    if ($status !== 'all') {
        $statuses = [$status];
    }
    $out = bc_fetch_borrow_requests(
        $conn,
        $statuses,
        null,
        $categoryId > 0 ? $categoryId : null,
        $search
    );
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'my') {
    $authUserId = (string) ($authSession['user_id'] ?? '');
    $residentId = 0;
    $email = trim((string) ($authSession['email'] ?? ''));
    if ($email !== '') {
        $lookup = $conn->prepare('SELECT user_id FROM users_resident WHERE LOWER(email) = LOWER(?) LIMIT 1');
        if ($lookup) {
            $lookup->bind_param('s', $email);
            $lookup->execute();
            $residentId = (int) (($lookup->get_result()->fetch_assoc()['user_id'] ?? 0));
            $lookup->close();
        }
    }
    if ($authUserId === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Resident session expired. Please sign in again.']);
        $conn->close();
        exit;
    }
    $out = bc_fetch_borrow_requests($conn, [], $residentId > 0 ? $residentId : null, null, null, $authUserId);
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'stats') {
    $stats = bc_borrow_stats($conn);
    $conn->close();
    echo json_encode($stats);
    exit;
}

if ($method === 'GET' && $action === 'monitoring') {
    bc_mark_overdue_borrows($conn);
    $out = bc_fetch_borrow_requests($conn, ['approved', 'released', 'returned', 'overdue', 'damaged_lost']);
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'history') {
    $out = bc_fetch_borrow_requests($conn, ['returned', 'rejected', 'damaged_lost']);
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'get' && $id > 0) {
    $row = bc_get_borrow_request($conn, $id);
    $conn->close();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Borrow request not found']);
        exit;
    }
    echo json_encode($row);
    exit;
}

$body = [];
if (in_array($method, ['POST', 'PUT'], true)) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
}

if ($method === 'POST' && $action === 'submit') {
    $authUserId = (string) ($authSession['user_id'] ?? '');
    $residentId = 0;
    $itemId = (int) ($body['itemId'] ?? 0);
    $quantity = max(1, (int) ($body['quantity'] ?? 1));
    $dateNeeded = trim((string) ($body['dateNeeded'] ?? ''));
    $returnDate = trim((string) ($body['returnDate'] ?? ''));
    $purpose = trim((string) ($body['purpose'] ?? ''));
    $remarks = trim((string) ($body['remarks'] ?? ''));
    $fullName = '';
    $residentCode = '';
    $contact = '';

    if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId) || $itemId <= 0 || $dateNeeded === '' || $returnDate === '' || $purpose === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        $conn->close();
        exit;
    }

    try {
        $residentResponse = bc_sb_rest('residents', 'GET', [
            'user_id' => 'eq.' . $authUserId,
            'select' => 'resident_id,first_name,middle_name,last_name,contact_number',
            'limit' => '1',
        ]);
        $resident = $residentResponse['data'][0] ?? null;
    } catch (Throwable $e) {
        $resident = null;
        error_log('Borrow submit could not load authenticated resident profile: ' . $e->getMessage());
    }
    if (!is_array($resident)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Your resident profile is missing. Please sign out and sign in again.']);
        $conn->close();
        exit;
    }
    $fullName = trim(implode(' ', array_filter([
        trim((string) ($resident['first_name'] ?? '')),
        trim((string) ($resident['middle_name'] ?? '')),
        trim((string) ($resident['last_name'] ?? '')),
    ])));
    $residentCode = trim((string) ($resident['resident_id'] ?? ''));
    $contact = trim((string) ($resident['contact_number'] ?? ''));
    if ($contact === '') $contact = trim((string) ($body['contact'] ?? ''));
    if ($fullName === '' || $residentCode === '' || $contact === '') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Your resident name, ID, or contact is missing. Please ask an administrator to update your profile.']);
        $conn->close();
        exit;
    }
    $email = trim((string) ($authSession['email'] ?? ''));
    if ($email !== '') {
        $lookup = $conn->prepare('SELECT user_id FROM users_resident WHERE LOWER(email) = LOWER(?) LIMIT 1');
        if ($lookup) {
            $lookup->bind_param('s', $email);
            $lookup->execute();
            $residentId = (int) (($lookup->get_result()->fetch_assoc()['user_id'] ?? 0));
            $lookup->close();
        }
    }

    $itemStmt = $conn->prepare('SELECT available_qty, name FROM inventory_items WHERE item_id = ? LIMIT 1');
    $itemStmt->bind_param('i', $itemId);
    $itemStmt->execute();
    $itemRow = $itemStmt->get_result()->fetch_assoc();
    $itemStmt->close();

    if (!$itemRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Item not found']);
        $conn->close();
        exit;
    }
    if ((int) $itemRow['available_qty'] < $quantity) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Not enough items available']);
        $conn->close();
        exit;
    }

    $code = bc_generate_borrow_code($conn);
    $status = 'pending';
    $stmt = $conn->prepare(
        'INSERT INTO borrow_requests
         (borrow_code, resident_user_id, auth_user_id, item_id, quantity, date_needed, return_date, purpose, remarks, status,
          applicant_name, applicant_resident_id, applicant_contact)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'sisii' . 'ssssssss',
        $code,
        $residentId,
        $authUserId,
        $itemId,
        $quantity,
        $dateNeeded,
        $returnDate,
        $purpose,
        $remarks,
        $status,
        $fullName,
        $residentCode,
        $contact
    );
    $ok = $stmt->execute();
    $newId = (int) $conn->insert_id;
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => $ok, 'borrowPk' => $newId, 'borrowCode' => $code]);
    exit;
}

if ($method === 'PUT' && $action === 'status' && $id > 0) {
    $newStatus = trim((string) ($body['status'] ?? ''));
    $adminRemarks = trim((string) ($body['adminRemarks'] ?? ''));
    $rejectionReason = trim((string) ($body['rejectionReason'] ?? ''));
    $returnCondition = trim((string) ($body['returnCondition'] ?? ''));
    $returnRemarks = trim((string) ($body['returnRemarks'] ?? ''));

    $allowed = ['approved', 'rejected', 'released', 'returned', 'damaged_lost'];
    if (!in_array($newStatus, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        $conn->close();
        exit;
    }

    $curStmt = $conn->prepare('SELECT * FROM borrow_requests WHERE borrow_pk = ? LIMIT 1');
    $curStmt->bind_param('i', $id);
    $curStmt->execute();
    $cur = $curStmt->get_result()->fetch_assoc();
    $curStmt->close();

    if (!$cur) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Borrow request not found']);
        $conn->close();
        exit;
    }

    $itemId = (int) $cur['item_id'];
    $qty = (int) $cur['quantity'];
    $residentId = (int) $cur['resident_user_id'];
    $currentStatus = (string) $cur['status'];

    if ($newStatus === 'released' && $currentStatus !== 'released') {
        if ($currentStatus !== 'approved') {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Request must be approved before release']);
            $conn->close();
            exit;
        }
        if (!bc_adjust_item_available($conn, $itemId, -$qty)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Insufficient stock to release item']);
            $conn->close();
            exit;
        }
    }

    if ($newStatus === 'returned' && in_array($currentStatus, ['released', 'overdue'], true)) {
        bc_adjust_item_available($conn, $itemId, $qty);
    }

    if ($newStatus === 'damaged_lost' && in_array($currentStatus, ['released', 'overdue'], true)) {
        /* Stock already deducted on release — do not restore */
    }

    if ($newStatus === 'rejected' && in_array($currentStatus, ['released', 'overdue'], true)) {
        bc_adjust_item_available($conn, $itemId, $qty);
    }

    $releasedAt = $newStatus === 'released' ? date('Y-m-d H:i:s') : ($cur['released_at'] ?? null);
    $returnedAt = $newStatus === 'returned' ? date('Y-m-d H:i:s') : ($cur['returned_at'] ?? null);

    $stmt = $conn->prepare(
        'UPDATE borrow_requests SET status = ?, admin_remarks = ?, rejection_reason = ?,
         return_condition = ?, return_remarks = ?, released_at = COALESCE(?, released_at),
         returned_at = COALESCE(?, returned_at), updated_at = NOW()
         WHERE borrow_pk = ?'
    );
    $stmt->bind_param(
        'sssssssi',
        $newStatus,
        $adminRemarks,
        $rejectionReason,
        $returnCondition,
        $returnRemarks,
        $releasedAt,
        $returnedAt,
        $id
    );
    $stmt->execute();
    $stmt->close();

    bc_ensure_resident_notifications_table($conn);
    $itemName = '';
    $iStmt = $conn->prepare('SELECT name FROM inventory_items WHERE item_id = ? LIMIT 1');
    $iStmt->bind_param('i', $itemId);
    $iStmt->execute();
    $iRow = $iStmt->get_result()->fetch_assoc();
    $iStmt->close();
    $itemName = (string) ($iRow['name'] ?? 'item');

    $titles = [
        'approved' => 'Borrow request approved',
        'rejected' => 'Borrow request rejected',
        'released' => 'Item released',
        'returned' => 'Item returned',
        'damaged_lost' => 'Item reported damaged/lost',
    ];
    $messages = [
        'approved' => "Your request for {$itemName} was approved.",
        'rejected' => "Your request for {$itemName} was rejected." . ($rejectionReason ? " Reason: {$rejectionReason}" : ''),
        'released' => "Your borrowed item ({$itemName}) has been released.",
        'returned' => "Thank you — {$itemName} was marked as returned.",
        'damaged_lost' => "Your borrow record for {$itemName} was updated to damaged/lost.",
    ];
    if (isset($titles[$newStatus])) {
        bc_insert_resident_notification(
            $conn,
            $residentId,
            'borrow',
            $newStatus === 'rejected' ? 'high' : 'normal',
            $titles[$newStatus],
            $messages[$newStatus],
            'borrow',
            $id
        );
    }

    $updated = bc_get_borrow_request($conn, $id);
    $conn->close();
    echo json_encode(['success' => true, 'borrow' => $updated]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);
$conn->close();
