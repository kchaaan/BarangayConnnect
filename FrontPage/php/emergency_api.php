<?php
declare(strict_types=1);
require_once __DIR__ . '/emergency_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function bc_em_json_error(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function bc_em_read_json_body(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function bc_em_admin_write(): void
{
    bc_sb_require_role(['admin']);
}

function bc_em_is_admin(): bool
{
    $user = bc_sb_current_user();
    return is_array($user) && bc_sb_role_has_admin_access($user['role'] ?? null);
}

function bc_em_id(): string
{
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '' || !preg_match('/^[0-9a-f-]{36}$/i', $id)) bc_em_json_error(400, 'A valid id is required');
    return $id;
}

try {
    $action = trim((string) ($_GET['action'] ?? ''));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $admin = bc_em_is_admin();

    if ($method === 'GET' && in_array($action, ['contacts', 'procedures'], true)) {
        if ($action === 'contacts') {
            $query = ['select' => '*', 'order' => 'sort_order.asc,name.asc'];
            if (!$admin) $query['active'] = 'eq.true';
            $result = bc_sb_rest('emergency_contacts', 'GET', $query);
            $rows = is_array($result['data']) ? $result['data'] : [];
            echo json_encode(array_map('bc_emergency_contact_row_to_api', $rows), JSON_UNESCAPED_UNICODE);
        } else {
            $query = ['select' => '*', 'order' => 'sort_order.asc,title.asc'];
            if (!$admin) $query['active'] = 'eq.true';
            $result = bc_sb_rest('emergency_procedures', 'GET', $query);
            $rows = is_array($result['data']) ? $result['data'] : [];
            echo json_encode(array_map('bc_emergency_procedure_row_to_api', $rows), JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($method === 'GET' && in_array($action, ['contact', 'procedure'], true)) {
        $id = bc_em_id();
        if ($action === 'contact') {
            $row = bc_emergency_contact_fetch_by_id($id);
            if (!$row || (!$admin && empty($row['active']))) bc_em_json_error(404, 'Contact not found');
            echo json_encode(bc_emergency_contact_row_to_api($row), JSON_UNESCAPED_UNICODE);
        } else {
            $row = bc_emergency_procedure_fetch_by_id($id);
            if (!$row || (!$admin && empty($row['active']))) bc_em_json_error(404, 'Procedure not found');
            echo json_encode(bc_emergency_procedure_row_to_api($row), JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($method === 'POST' && $action === 'contact') {
        bc_em_admin_write();
        $body = bc_em_read_json_body();
        $name = trim((string) ($body['serviceName'] ?? ''));
        $number = trim((string) ($body['contactNumber'] ?? ''));
        if ($name === '' || $number === '') bc_em_json_error(400, 'serviceName and contactNumber are required');
        $payload = [
            'name' => $name, 'organization' => trim((string) ($body['organization'] ?? '')) ?: null,
            'contact_number' => $number, 'description' => trim((string) ($body['description'] ?? '')),
            'icon' => trim((string) ($body['icon'] ?? '')) ?: bc_emergency_icon_for_service($name),
            'is_critical' => !empty($body['isCritical']), 'sort_order' => (int) ($body['sortOrder'] ?? 0), 'active' => true,
        ];
        $result = bc_sb_rest('emergency_contacts', 'POST', [], $payload, ['return=representation']);
        $row = is_array($result['data']) ? ($result['data'][0] ?? null) : null;
        echo json_encode(['success' => true, 'contact' => is_array($row) ? bc_emergency_contact_row_to_api($row) : null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && $action === 'procedure') {
        bc_em_admin_write();
        $body = bc_em_read_json_body();
        $title = trim((string) ($body['title'] ?? ''));
        $steps = bc_emergency_steps_from_body($body['steps'] ?? []);
        if ($title === '' || !$steps) bc_em_json_error(400, 'title and steps are required');
        $payload = ['title' => $title, 'steps' => $steps, 'icon' => trim((string) ($body['icon'] ?? '')) ?: bc_emergency_icon_for_procedure($title), 'sort_order' => (int) ($body['sortOrder'] ?? 0), 'active' => true];
        $result = bc_sb_rest('emergency_procedures', 'POST', [], $payload, ['return=representation']);
        $row = is_array($result['data']) ? ($result['data'][0] ?? null) : null;
        echo json_encode(['success' => true, 'procedure' => is_array($row) ? bc_emergency_procedure_row_to_api($row) : null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PUT' && in_array($action, ['contact', 'procedure'], true)) {
        bc_em_admin_write();
        $id = bc_em_id();
        $body = bc_em_read_json_body();
        if ($action === 'contact') {
            $name = trim((string) ($body['serviceName'] ?? ''));
            $number = trim((string) ($body['contactNumber'] ?? ''));
            if ($name === '' || $number === '') bc_em_json_error(400, 'serviceName and contactNumber are required');
            $payload = [
                'name' => $name, 'organization' => trim((string) ($body['organization'] ?? '')) ?: null,
                'contact_number' => $number, 'description' => trim((string) ($body['description'] ?? '')),
                'icon' => trim((string) ($body['icon'] ?? '')) ?: bc_emergency_icon_for_service($name),
                'is_critical' => !empty($body['isCritical']), 'sort_order' => (int) ($body['sortOrder'] ?? 0),
                'active' => !array_key_exists('active', $body) || !empty($body['active']),
            ];
            $result = bc_sb_rest('emergency_contacts', 'PATCH', ['id' => 'eq.' . $id], $payload, ['return=representation']);
            $row = is_array($result['data']) ? ($result['data'][0] ?? null) : null;
            if (!is_array($row)) bc_em_json_error(404, 'Contact not found');
            echo json_encode(['success' => true, 'contact' => bc_emergency_contact_row_to_api($row)], JSON_UNESCAPED_UNICODE);
        } else {
            $title = trim((string) ($body['title'] ?? ''));
            $steps = bc_emergency_steps_from_body($body['steps'] ?? []);
            if ($title === '' || !$steps) bc_em_json_error(400, 'title and steps are required');
            $payload = ['title' => $title, 'steps' => $steps, 'icon' => trim((string) ($body['icon'] ?? '')) ?: bc_emergency_icon_for_procedure($title), 'sort_order' => (int) ($body['sortOrder'] ?? 0), 'active' => !array_key_exists('active', $body) || !empty($body['active'])];
            $result = bc_sb_rest('emergency_procedures', 'PATCH', ['id' => 'eq.' . $id], $payload, ['return=representation']);
            $row = is_array($result['data']) ? ($result['data'][0] ?? null) : null;
            if (!is_array($row)) bc_em_json_error(404, 'Procedure not found');
            echo json_encode(['success' => true, 'procedure' => bc_emergency_procedure_row_to_api($row)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($method === 'DELETE' && in_array($action, ['contact', 'procedure'], true)) {
        bc_em_admin_write();
        $id = bc_em_id();
        $table = $action === 'contact' ? 'emergency_contacts' : 'emergency_procedures';
        $result = bc_sb_rest($table, 'DELETE', ['id' => 'eq.' . $id], null, ['return=representation']);
        if (!is_array($result['data']) || !$result['data']) bc_em_json_error(404, 'Record not found');
        echo json_encode(['success' => true]);
        exit;
    }

    bc_em_json_error(400, 'Invalid action or method');
} catch (Throwable $e) {
    error_log('Supabase emergency API: ' . $e->getMessage());
    bc_em_json_error(503, 'Emergency service is unavailable. Check Supabase configuration and schema.');
}
