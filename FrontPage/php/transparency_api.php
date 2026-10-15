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

// Public/resident pages may read published transparency data. Only the
// SuperAdmin may change budgets, projects, or supporting documents.
if (in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    require_once __DIR__ . '/supabase_client.php';
    bc_sb_require_role(['superadmin']);
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

if ($method === 'GET' && $action === 'download_document' && $id > 0) {
    $stmt = $conn->prepare('SELECT file_path, file_data FROM transparency_documents WHERE document_id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $document = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $fileName = $document ? basename((string) $document['file_path']) : '';
    $contents = null;
    if ($document && isset($document['file_data']) && $document['file_data'] !== null && $document['file_data'] !== '') {
        $contents = (string) $document['file_data'];
    } elseif ($fileName !== '') {
        $legacyPath = bc_transparency_upload_dir() . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($legacyPath) && is_readable($legacyPath)) {
            $legacyContents = file_get_contents($legacyPath);
            if ($legacyContents !== false) {
                $contents = $legacyContents;
            }
        }
    }

    if ($contents === null) {
        $conn->close();
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Document not found.';
        exit;
    }

    $conn->close();
    $downloadName = str_replace(["\r", "\n", '"'], '_', $fileName ?: 'transparency-document.pdf');
    header('Content-Type: application/pdf');
    header("Content-Disposition: attachment; filename=\"" . $downloadName . "\"; filename*=UTF-8''" . rawurlencode($downloadName));
    header('Content-Length: ' . strlen($contents));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $contents;
    exit;
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
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'The PDF upload did not complete']);
        $conn->close();
        exit;
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only PDF files are allowed']);
        $conn->close();
        exit;
    }

    $contents = file_get_contents($file['tmp_name']);
    if ($contents === false || $contents === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'The uploaded PDF is empty or unreadable']);
        $conn->close();
        exit;
    }

    $safeName = 'item_' . $id . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) $file['name']));
    $stmt = $conn->prepare('INSERT INTO transparency_documents (item_id, file_path, file_data) VALUES (?, ?, ?)');
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not prepare the document record']);
        $conn->close();
        exit;
    }
    $stmt->bind_param('isb', $id, $safeName, $contents);
    $stmt->send_long_data(2, $contents);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not save the PDF to storage']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

$conn->close();
http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'error' => 'Invalid request']);
