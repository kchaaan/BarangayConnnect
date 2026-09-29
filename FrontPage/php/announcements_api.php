<?php
declare(strict_types=1);
require_once __DIR__ . '/announcements_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function bc_ann_json_error(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function bc_ann_read_json_body(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function bc_ann_admin_write(): void
{
    bc_sb_require_role(['admin']);
}

function bc_ann_is_admin(): bool
{
    $user = bc_sb_current_user();
    return is_array($user) && bc_sb_role_has_admin_access($user['role'] ?? null);
}

function bc_ann_id(): string
{
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '' || !preg_match('/^[0-9a-f-]{36}$/i', $id)) bc_ann_json_error(400, 'A valid id is required');
    return $id;
}

try {
    $action = trim((string) ($_GET['action'] ?? ''));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $admin = bc_ann_is_admin();

    if ($method === 'GET' && in_array($action, ['list', 'latest'], true)) {
        $query = ['select' => '*', 'order' => 'featured.desc,announcement_date.desc,created_at.desc'];
        if (!$admin || $action === 'latest') $query['status'] = 'eq.published';
        if ($action === 'latest') $query['limit'] = (string) min(20, max(1, (int) ($_GET['limit'] ?? 5)));
        $result = bc_sb_rest('announcements', 'GET', $query);
        $rows = is_array($result['data']) ? $result['data'] : [];
        echo json_encode(array_map('bc_announcement_row_to_api', $rows), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $action === 'get') {
        $id = bc_ann_id();
        $row = bc_announcement_fetch_by_id($id);
        if (!$row || (!$admin && ($row['status'] ?? '') !== 'published')) bc_ann_json_error(404, 'Announcement not found');
        echo json_encode(bc_announcement_row_to_api($row), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && $action === 'create') {
        bc_ann_admin_write();
        $payload = bc_announcement_payload_from_body(bc_ann_read_json_body());
        if ($payload['title'] === '' || $payload['content'] === '') bc_ann_json_error(400, 'title and description are required');
        unset($payload['active']);
        $result = bc_sb_rest('announcements', 'POST', [], $payload, ['return=representation']);
        $row = is_array($result['data']) ? ($result['data'][0] ?? null) : null;
        echo json_encode(['success' => true, 'announcement' => is_array($row) ? bc_announcement_row_to_api($row) : null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PUT' && $action === 'update') {
        bc_ann_admin_write();
        $id = bc_ann_id();
        if (!bc_announcement_fetch_by_id($id)) bc_ann_json_error(404, 'Announcement not found');
        $payload = bc_announcement_payload_from_body(bc_ann_read_json_body());
        if ($payload['title'] === '' || $payload['content'] === '') bc_ann_json_error(400, 'title and description are required');
        $result = bc_sb_rest('announcements', 'PATCH', ['id' => 'eq.' . $id], $payload, ['return=representation']);
        $row = is_array($result['data']) ? ($result['data'][0] ?? null) : null;
        echo json_encode(['success' => true, 'announcement' => is_array($row) ? bc_announcement_row_to_api($row) : null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE' && $action === 'delete') {
        bc_ann_admin_write();
        $id = bc_ann_id();
        $result = bc_sb_rest('announcements', 'DELETE', ['id' => 'eq.' . $id], null, ['return=representation']);
        if (!is_array($result['data']) || !$result['data']) bc_ann_json_error(404, 'Announcement not found');
        echo json_encode(['success' => true]);
        exit;
    }

    bc_ann_json_error(400, 'Invalid action or method');
} catch (Throwable $e) {
    error_log('Supabase announcements API: ' . $e->getMessage());
    bc_ann_json_error(503, 'Announcements service is unavailable. Check Supabase configuration and schema.');
}
