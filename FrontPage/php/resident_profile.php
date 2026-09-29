<?php
/**
 * Resident profile for portal session (XAMPP / MySQL).
 * GET  ?id=4 or ?id=RES-001
 * POST ?id=4  JSON body — update profile (POST used for broad Apache compatibility)
 * PUT  ?id=4  JSON body — update profile
 */
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/users_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$idParam = trim((string) ($_GET['id'] ?? ''));

if ($idParam === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Resident id is required']);
    exit;
}

$conn = bc_db_connect();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => bc_db_error_response()]);
    exit;
}

bc_upgrade_users_schema($conn);

if ($method === 'GET') {
    $row = bc_user_fetch_by_id_or_code($conn, $idParam);
    $conn->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Resident not found']);
        exit;
    }

    echo json_encode(bc_user_row_to_profile($row));
    exit;
}

if ($method === 'POST' || $method === 'PUT') {
    $raw = file_get_contents('php://input');
    $body = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) {
        $conn->close();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $row = bc_user_fetch_by_id_or_code($conn, $idParam);
    if (!$row) {
        $conn->close();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Resident not found']);
        exit;
    }

    $userId = (int) ($row['user_id'] ?? 0);
    $result = bc_update_resident_profile($conn, $userId, $body);
    $conn->close();

    if (empty($result['success'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Update failed',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'profile' => $result['profile'] ?? null,
    ]);
    exit;
}

$conn->close();
http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
