<?php
declare(strict_types=1);
/** Resident community report submission. Supabase is the system of record. */
require_once __DIR__ . '/supabase_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bc_report_submit_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST, OPTIONS');
    bc_report_submit_reply(405, ['success' => false, 'error' => 'Method not allowed.']);
}

try {
    $session = bc_sb_require_role(['resident']);
    $resident = $session['resident'] ?? [];
    $residentId = trim((string) ($resident['id'] ?? ''));
    $authUserId = trim((string) ($session['user_id'] ?? ''));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $residentId)) {
        bc_report_submit_reply(403, ['success' => false, 'error' => 'Your resident account is missing its Supabase resident record. Please ask the barangay administrator to link the account, then try again.']);
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $authUserId)) {
        bc_report_submit_reply(401, ['success' => false, 'error' => 'Your login session expired. Please sign in again and retry.']);
    }

    $raw = (string) ($_POST['data'] ?? '');
    $payload = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($payload)) bc_report_submit_reply(400, ['success' => false, 'error' => 'The report form data could not be read. Please review the form and try again.']);

    $title = trim((string) ($payload['title'] ?? ''));
    $category = trim((string) ($payload['category'] ?? ''));
    $location = trim((string) ($payload['location'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    if ($title === '' || $category === '' || $location === '' || $description === '') {
        bc_report_submit_reply(400, ['success' => false, 'error' => 'Complete the issue title, category, location, and description.']);
    }
    if (strlen($title) > 1020 || strlen($category) > 512 || strlen($location) > 2048 || strlen($description) > 40000) {
        bc_report_submit_reply(400, ['success' => false, 'error' => 'One or more report fields are too long. Please shorten them and try again.']);
    }

    $report = [
        'resident_id' => $residentId,
        'title' => $title,
        'category' => $category,
        'location' => $location,
        'description' => $description,
        'status' => 'Pending',
    ];

    $image = $_FILES['image'] ?? null;
    $objectPath = '';
    if (is_array($image) && ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($image['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($image['tmp_name'] ?? ''))) {
            bc_report_submit_reply(400, ['success' => false, 'error' => 'The photo could not be uploaded. Please choose it again.']);
        }
        if ((int) ($image['size'] ?? 0) > 10 * 1024 * 1024) bc_report_submit_reply(400, ['success' => false, 'error' => 'The attachment must be 10 MB or smaller.']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $image['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
        if (!isset($extensions[$mime])) bc_report_submit_reply(400, ['success' => false, 'error' => 'Attach a JPG, PNG, or PDF file.']);
        $objectPath = $authUserId . '/reports/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $bytes = file_get_contents((string) $image['tmp_name']);
        if ($bytes === false) bc_report_submit_reply(400, ['success' => false, 'error' => 'The attachment could not be read. Please choose it again.']);
        bc_sb_storage_upload('resident-private', $objectPath, $bytes, $mime);
        $report['attachment_path'] = $objectPath;
    }

    $insert = bc_sb_rest('community_reports', 'POST', [], $report, ['return=representation']);
    $row = $insert['data'][0] ?? null;
    if (!is_array($row) || empty($row['id'])) throw new RuntimeException('Supabase did not return the saved report.');
    bc_report_submit_reply(201, [
        'success' => true,
        'reportId' => (string) $row['id'],
        'message' => 'Your report was saved to the resident reports database.',
    ]);
} catch (Throwable $e) {
    error_log('BarangayConnect Supabase report submission failed: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $status = in_array($code, [400, 401, 403, 404, 413, 415], true) ? $code : 503;
    $message = $status === 403
        ? 'Your account does not have permission to submit this report. Sign in as a resident and try again.'
        : 'The report could not be saved to Supabase. Please try again later.';
    bc_report_submit_reply($status, ['success' => false, 'error' => $message]);
}

