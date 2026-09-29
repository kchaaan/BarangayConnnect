<?php
/**
 * One-time migration: borrow & inventory tables.
 * Open: http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/FrontPage/php/migrate_borrow_inventory.php
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/borrow_lib.php';

$conn = bc_db_connect();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => bc_db_error_response()]);
    exit;
}

bc_ensure_borrow_tables($conn);

$items = (int) ($conn->query('SELECT COUNT(*) AS c FROM inventory_items')->fetch_assoc()['c'] ?? 0);
$cats = (int) ($conn->query('SELECT COUNT(*) AS c FROM inventory_categories')->fetch_assoc()['c'] ?? 0);
$conn->close();

echo json_encode([
    'ok' => true,
    'message' => 'Borrow & inventory tables ready.',
    'inventoryItems' => $items,
    'categories' => $cats,
]);
