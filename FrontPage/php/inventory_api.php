<?php
/**
 * Inventory API — categories & items.
 * GET  ?action=list|categories|stats|get&id=
 * POST ?action=create|category  (admin)
 * PUT  ?action=update&id=
 * DELETE ?action=delete&id=
 */
require_once __DIR__ . '/inventory_lib.php';

header('Content-Type: application/json; charset=utf-8');
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
    echo json_encode(['success' => false, 'error' => bc_db_error_response()]);
    exit;
}

bc_ensure_inventory_tables($conn);

$action = trim($_GET['action'] ?? '');
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $action === 'list') {
    $status = trim($_GET['status'] ?? 'all');
    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
    $search = $_GET['search'] ?? null;
    $availableOnly = isset($_GET['available_only']) && $_GET['available_only'] === '1';

    $items = bc_fetch_inventory_items(
        $conn,
        $status === 'all' ? null : $status,
        $categoryId > 0 ? $categoryId : null,
        $search
    );

    if ($availableOnly) {
        $items = array_values(array_filter($items, static function ($item) {
            return ($item['availableQty'] ?? 0) > 0 && ($item['status'] ?? '') !== 'unavailable';
        }));
    }

    $conn->close();
    echo json_encode($items);
    exit;
}

if ($method === 'GET' && $action === 'categories') {
    $sql = 'SELECT c.*, (SELECT COUNT(*) FROM inventory_items i WHERE i.category_id = c.category_id) AS item_count
            FROM inventory_categories c ORDER BY c.name ASC';
    $res = $conn->query($sql);
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = bc_inventory_category_row_to_api($row);
        }
    }
    $conn->close();
    echo json_encode($out);
    exit;
}

if ($method === 'GET' && $action === 'stats') {
    $stats = bc_inventory_stats($conn);
    $conn->close();
    echo json_encode($stats);
    exit;
}

if ($method === 'GET' && $action === 'get' && $id > 0) {
    $stmt = $conn->prepare(bc_inventory_items_select_sql() . ' WHERE i.item_id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Item not found']);
        exit;
    }
    echo json_encode(bc_inventory_item_row_to_api($row));
    exit;
}

$body = [];
if (in_array($method, ['POST', 'PUT'], true)) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
}

if ($method === 'POST' && $action === 'category') {
    $name = trim((string) ($body['name'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Category name is required']);
        $conn->close();
        exit;
    }
    $stmt = $conn->prepare('INSERT INTO inventory_categories (name, description) VALUES (?, ?)');
    $stmt->bind_param('ss', $name, $description);
    $ok = $stmt->execute();
    $newId = (int) $conn->insert_id;
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => $ok, 'categoryId' => $newId]);
    exit;
}

if ($method === 'POST' && $action === 'create') {
    $name = trim((string) ($body['name'] ?? ''));
    $categoryId = isset($body['categoryId']) ? (int) $body['categoryId'] : null;
    $description = trim((string) ($body['description'] ?? ''));
    $totalQty = max(0, (int) ($body['totalQty'] ?? 0));
    $availableQty = isset($body['availableQty']) ? max(0, (int) $body['availableQty']) : $totalQty;
    $unit = trim((string) ($body['unit'] ?? 'pcs')) ?: 'pcs';
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Item name is required']);
        $conn->close();
        exit;
    }
    if ($availableQty > $totalQty) {
        $availableQty = $totalQty;
    }
    $status = bc_inventory_item_status($availableQty, $totalQty);
    $catParam = $categoryId > 0 ? $categoryId : null;

    if ($catParam) {
        $stmt = $conn->prepare(
            'INSERT INTO inventory_items (category_id, name, description, total_qty, available_qty, unit, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issiiss', $catParam, $name, $description, $totalQty, $availableQty, $unit, $status);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO inventory_items (name, description, total_qty, available_qty, unit, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssiiss', $name, $description, $totalQty, $availableQty, $unit, $status);
    }
    $ok = $stmt->execute();
    $newId = (int) $conn->insert_id;
    $stmt->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create item']);
        $conn->close();
        exit;
    }
    $conn->close();
    echo json_encode(['success' => true, 'itemId' => $newId]);
    exit;
}

if ($method === 'PUT' && $action === 'update' && $id > 0) {
    $name = trim((string) ($body['name'] ?? ''));
    $categoryId = isset($body['categoryId']) ? (int) $body['categoryId'] : null;
    $description = trim((string) ($body['description'] ?? ''));
    $totalQty = max(0, (int) ($body['totalQty'] ?? 0));
    $availableQty = max(0, (int) ($body['availableQty'] ?? 0));
    $unit = trim((string) ($body['unit'] ?? 'pcs')) ?: 'pcs';
    if ($availableQty > $totalQty) {
        $availableQty = $totalQty;
    }
    $status = bc_inventory_item_status($availableQty, $totalQty);
    $catParam = $categoryId > 0 ? $categoryId : null;

    $stmt = $conn->prepare(
        'UPDATE inventory_items SET category_id = ?, name = ?, description = ?, total_qty = ?,
         available_qty = ?, unit = ?, status = ? WHERE item_id = ?'
    );
    $stmt->bind_param('issiissi', $catParam, $name, $description, $totalQty, $availableQty, $unit, $status, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update item']);
        $conn->close();
        exit;
    }
    bc_sync_item_status($conn, $id);
    $conn->close();
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE' && $action === 'delete' && $id > 0) {
    $check = $conn->prepare('SELECT COUNT(*) AS c FROM borrow_requests WHERE item_id = ?');
    $check->bind_param('i', $id);
    $check->execute();
    $c = (int) ($check->get_result()->fetch_assoc()['c'] ?? 0);
    $check->close();
    if ($c > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Cannot delete item with borrow history']);
        $conn->close();
        exit;
    }
    $stmt = $conn->prepare('DELETE FROM inventory_items WHERE item_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);
$conn->close();
