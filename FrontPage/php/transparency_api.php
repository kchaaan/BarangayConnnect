<?php
/**
 * Transparency API — budget, projects/programs, documents.
 *
 * GET    ?action=items
 * POST   ?action=items          JSON body
 * PUT    ?action=item&id=       JSON body
 * DELETE ?action=item&id=
 * GET    ?action=budget
 * PUT    ?action=budget         JSON { "amount": number }
 * GET    ?action=budget_history
 * POST   ?action=document&id=   multipart file field "file"
 */
require_once __DIR__ . '/transparency_lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$conn = bc_db_connect();
if (!$conn) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => bc_db_error_response()]);
    exit;
}

bc_ensure_transparency_tables($conn);

$action = trim($_GET['action'] ?? '');
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function bc_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

if ($method === 'GET' && $action === 'items') {
    header('Content-Type: application/json; charset=utf-8');
    $res = $conn->query(
        'SELECT item_id, name, type, budget, spent, status, description, start_date, end_date
         FROM transparency_items
         ORDER BY created_at DESC'
    );
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $itemId = (int) $row['item_id'];
            $out[] = bc_transparency_item_to_api($row, bc_transparency_fetch_documents($conn, $itemId));
        }
    }
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'budget') {
    header('Content-Type: application/json; charset=utf-8');
    $amount = bc_transparency_get_current_budget($conn);
    $conn->close();
    if ($amount === null) {
        http_response_code(204);
        exit;
    }
    echo json_encode(['amount' => $amount]);
    exit;
}

if ($method === 'GET' && $action === 'budget_history') {
    header('Content-Type: application/json; charset=utf-8');
    $res = $conn->query(
        'SELECT previous_amount, new_amount, changed_at
         FROM transparency_budget_history
         ORDER BY changed_at DESC'
    );
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'previous' => (float) ($row['previous_amount'] ?? 0),
                'newAmount' => (float) ($row['new_amount'] ?? 0),
                'changedAt' => date('c', strtotime((string) ($row['changed_at'] ?? 'now'))),
            ];
        }
    }
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'PUT' && $action === 'budget') {
    header('Content-Type: application/json; charset=utf-8');
    $body = bc_read_json_body();
    $newAmount = max(0, (float) ($body['amount'] ?? 0));
    $previous = bc_transparency_get_current_budget($conn) ?? 0.0;

    $stmt = $conn->prepare('INSERT INTO transparency_budget (amount) VALUES (?)');
    $stmt->bind_param('d', $newAmount);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $hist = $conn->prepare(
            'INSERT INTO transparency_budget_history (previous_amount, new_amount) VALUES (?, ?)'
        );
        $hist->bind_param('dd', $previous, $newAmount);
        $hist->execute();
        $hist->close();
    }

    $conn->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not save budget']);
        exit;
    }
    echo json_encode(['success' => true, 'amount' => $newAmount]);
    exit;
}

if ($method === 'POST' && $action === 'items') {
    header('Content-Type: application/json; charset=utf-8');
    $body = bc_read_json_body();
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Name is required']);
        $conn->close();
        exit;
    }

    $type = trim((string) ($body['type'] ?? 'Project'));
    $budget = max(0, (float) ($body['budget'] ?? 0));
    $spent = max(0, (float) ($body['spent'] ?? 0));
    $status = trim((string) ($body['status'] ?? 'Ongoing'));
    $description = trim((string) ($body['description'] ?? ''));
    $startDate = $body['startDate'] ?? null;
    $endDate = $body['endDate'] ?? null;
    $startDate = $startDate ? (string) $startDate : null;
    $endDate = $endDate ? (string) $endDate : null;

    $stmt = $conn->prepare(
        'INSERT INTO transparency_items (name, type, budget, spent, status, description, start_date, end_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssddssss', $name, $type, $budget, $spent, $status, $description, $startDate, $endDate);
    $ok = $stmt->execute();
    $newId = $ok ? (int) $conn->insert_id : 0;
    $stmt->close();
    $conn->close();

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not create item']);
        exit;
    }
    echo json_encode(['success' => true, 'id' => $newId]);
    exit;
}

if ($method === 'PUT' && $action === 'item' && $id > 0) {
    header('Content-Type: application/json; charset=utf-8');
    $body = bc_read_json_body();
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Name is required']);
        $conn->close();
        exit;
    }

    $type = trim((string) ($body['type'] ?? 'Project'));
    $budget = max(0, (float) ($body['budget'] ?? 0));
    $spent = max(0, (float) ($body['spent'] ?? 0));
    $status = trim((string) ($body['status'] ?? 'Ongoing'));
    $description = trim((string) ($body['description'] ?? ''));
    $startDate = $body['startDate'] ?? null;
    $endDate = $body['endDate'] ?? null;
    $startDate = $startDate ? (string) $startDate : null;
    $endDate = $endDate ? (string) $endDate : null;

    $stmt = $conn->prepare(
        'UPDATE transparency_items
         SET name = ?, type = ?, budget = ?, spent = ?, status = ?, description = ?, start_date = ?, end_date = ?
         WHERE item_id = ?'
    );
    $stmt->bind_param('ssddssssi', $name, $type, $budget, $spent, $status, $description, $startDate, $endDate, $id);
    $ok = $stmt->execute() && $stmt->affected_rows >= 0;
    $stmt->close();
    $conn->close();

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not update item']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE' && $action === 'item' && $id > 0) {
    header('Content-Type: application/json; charset=utf-8');
    $stmt = $conn->prepare('DELETE FROM transparency_items WHERE item_id = ?');
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => (bool) $ok]);
    exit;
}

if ($method === 'POST' && $action === 'document' && $id > 0) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        $conn->close();
        exit;
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only PDF files are allowed']);
        $conn->close();
        exit;
    }

    $uploadDir = bc_transparency_upload_dir();
    $safeName = 'item_' . $id . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) $file['name']));
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
        $conn->close();
        exit;
    }

    $relPath = $safeName;
    $stmt = $conn->prepare('INSERT INTO transparency_documents (item_id, file_path) VALUES (?, ?)');
    $stmt->bind_param('is', $id, $relPath);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => (bool) $ok]);
    exit;
}

$conn->close();
http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'error' => 'Invalid request']);
