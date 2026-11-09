<?php
declare(strict_types=1);
/** Resident/admin community reports API backed by Supabase. */
require_once __DIR__ . '/supabase_client.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function bc_reports_reply(int $status, mixed $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function bc_report_status(string $value): string
{
    $map = ['pending'=>'Pending','in progress'=>'In Progress','in_progress'=>'In Progress','resolved'=>'Resolved','completed'=>'Resolved','cancelled'=>'Cancelled','canceled'=>'Cancelled'];
    return $map[strtolower(trim($value))] ?? '';
}
function bc_report_archived(array $row): bool
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if (!empty($row['archived_at']) || in_array($status, ['cancelled','canceled'], true)) return true;
    if ($status === 'resolved' && !empty($row['resolved_at'])) {
        $resolved = strtotime((string) $row['resolved_at']);
        return $resolved !== false && $resolved <= time() - 86400;
    }
    return false;
}
function bc_report_to_api(array $row, bool $includeAttachment = false): array
{
    $status = bc_report_status((string) ($row['status'] ?? '')) ?: 'Pending';
    $out = [
        'reportId' => (string) ($row['id'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'category' => (string) ($row['category'] ?? ''),
        'location' => (string) ($row['location'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'status' => $status,
        'dateSubmitted' => (string) ($row['created_at'] ?? ''),
        'dateUpdated' => (string) ($row['updated_at'] ?? $row['created_at'] ?? ''),
        'dateResolved' => $row['resolved_at'] ?? null,
        'dateArchived' => $row['archived_at'] ?? null,
        'isArchived' => bc_report_archived($row),
    ];
    if ($includeAttachment && !empty($row['attachment_path'])) {
        try { $out['imageUrl'] = bc_sb_storage_signed_url('resident-private', (string) $row['attachment_path'], 300); }
        catch (Throwable $e) { error_log('BarangayConnect report attachment URL failed: ' . $e->getMessage()); }
    }
    return $out;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string) ($_GET['action'] ?? ''));
$id = trim((string) ($_GET['id'] ?? ''));
try {
    $session = bc_sb_require_role(['resident', 'admin']);
    $role = (string) ($session['role'] ?? '');
    $isAdmin = bc_sb_role_has_admin_access($role);
    $residentId = trim((string) ($session['resident']['id'] ?? ''));
    if (!$isAdmin && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $residentId)) {
        $userId = trim((string) ($session['user_id'] ?? ''));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $userId)) {
            $residentResult = bc_sb_rest('residents', 'GET', [
                'user_id' => 'eq.' . $userId,
                'select' => 'id,resident_id,first_name,last_name,contact_number',
                'limit' => '1',
            ]);
            $resident = $residentResult['data'][0] ?? [];
            $residentId = trim((string) ($resident['id'] ?? ''));
            if ($residentId !== '') {
                bc_sb_session_start();
                $_SESSION['bc_supabase']['resident'] = $resident;
            }
        }
    }
    if (!$isAdmin && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $residentId)) {
        bc_reports_reply(403, ['success'=>false,'error'=>'Your resident account is missing its linked Supabase resident record.']);
    }

    $select = 'id,resident_id,title,category,location,description,status,attachment_path,admin_remarks,created_at,updated_at,resolved_at,archived_at';
    if ($method === 'GET' && in_array($action, ['all','list','archived','mine'], true)) {
        if ($action === 'mine' && $isAdmin) bc_reports_reply(403, ['success'=>false,'error'=>'Resident access is required.']);
        $query = ['select'=>$select, 'order'=>'created_at.desc'];
        if (!$isAdmin) $query['resident_id'] = 'eq.' . $residentId;
        $response = bc_sb_rest('community_reports', 'GET', $query);
        $rows = is_array($response['data']) ? $response['data'] : [];
        if ($action !== 'mine') {
            $wantArchived = $action === 'archived';
            $rows = array_values(array_filter($rows, static fn($row) => is_array($row) && bc_report_archived($row) === $wantArchived));
        }
        bc_reports_reply(200, array_map(static fn($row) => bc_report_to_api($row), $rows));
    }

    if ($method === 'GET' && $action === 'get') {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) bc_reports_reply(400, ['success'=>false,'error'=>'Invalid report ID.']);
        $query = ['id'=>'eq.' . $id, 'select'=>$select, 'limit'=>'1'];
        if (!$isAdmin) $query['resident_id'] = 'eq.' . $residentId;
        $response = bc_sb_rest('community_reports', 'GET', $query);
        $row = $response['data'][0] ?? null;
        if (!is_array($row)) bc_reports_reply(404, ['success'=>false,'error'=>'Report not found.']);
        bc_reports_reply(200, bc_report_to_api($row, true));
    }

    if ($method === 'PUT' && $action === 'cancel') {
        if ($isAdmin || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) { http_response_code(400); echo 'ERROR'; exit; }
        $query = ['id'=>'eq.' . $id, 'resident_id'=>'eq.' . $residentId, 'select'=>'id,status,archived_at,resolved_at', 'limit'=>'1'];
        $current = bc_sb_rest('community_reports', 'GET', $query)['data'][0] ?? null;
        if (!is_array($current) || bc_report_archived($current) || strtolower((string) ($current['status'] ?? '')) === 'resolved') { http_response_code(409); echo 'ERROR'; exit; }
        bc_sb_rest('community_reports', 'PATCH', ['id'=>'eq.' . $id, 'resident_id'=>'eq.' . $residentId], ['status'=>'Cancelled','archived_at'=>gmdate('c'),'resolved_at'=>null,'updated_at'=>gmdate('c')], ['return=minimal']);
        header('Content-Type: text/plain; charset=utf-8'); echo 'SUCCESS'; exit;
    }

    if ($method === 'PUT' && $action === 'status') {
        if (!$isAdmin || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) { http_response_code(403); echo 'ERROR'; exit; }
        $newStatus = bc_report_status(trim((string) file_get_contents('php://input')));
        if (!in_array($newStatus, ['Pending','In Progress','Resolved','Cancelled'], true)) { http_response_code(400); echo 'ERROR'; exit; }
        $old = bc_sb_rest('community_reports', 'GET', ['id'=>'eq.' . $id,'select'=>'id,resident_id,title,status','limit'=>'1'])['data'][0] ?? null;
        if (!is_array($old)) { http_response_code(404); echo 'ERROR'; exit; }
        $now = gmdate('c');
        $patch = ['status'=>$newStatus,'updated_at'=>$now,'resolved_at'=>null,'archived_at'=>null];
        if ($newStatus === 'Resolved') $patch['resolved_at'] = $now;
        if ($newStatus === 'Cancelled') $patch['archived_at'] = $now;
        bc_sb_rest('community_reports', 'PATCH', ['id'=>'eq.' . $id], $patch, ['return=minimal']);
        if (strcasecmp((string) ($old['status'] ?? ''), $newStatus) !== 0 && !empty($old['resident_id'])) {
            $label = 'RPT-' . strtoupper(substr((string) $old['id'], 0, 8));
            try {
                bc_sb_rest('resident_notifications', 'POST', [], [
                    'resident_id'=>$old['resident_id'], 'title'=>'Report status updated',
                    'message'=>trim((string) ($old['title'] ?? 'Community report')) . ' (' . $label . ') is now ' . $newStatus . '.',
                    'type'=>'report_update', 'priority'=>in_array($newStatus,['Resolved','Cancelled'],true)?'urgent':'normal',
                    'link_section'=>'reports', 'is_read'=>false,
                ], ['return=minimal']);
            } catch (Throwable $e) { error_log('BarangayConnect report status notification failed: ' . $e->getMessage()); }
        }
        header('Content-Type: text/plain; charset=utf-8'); echo 'SUCCESS'; exit;
    }
    bc_reports_reply(400, ['success'=>false,'error'=>'Invalid report action.']);
} catch (Throwable $e) {
    error_log('BarangayConnect reports API failed: ' . $e->getMessage());
    if (in_array($method, ['PUT'], true)) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); echo 'ERROR'; exit; }
    $code = (int) $e->getCode();
    $status = in_array($code, [400,401,403,404], true) ? $code : 503;
    bc_reports_reply($status, ['success'=>false,'error'=>$status===403?'Your account is not allowed to view these reports.':'Reports are temporarily unavailable. Please try again.']);
}

