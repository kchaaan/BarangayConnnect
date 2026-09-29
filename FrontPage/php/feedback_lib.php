<?php
/**
 * Resident feedback — users submit; admin view-only (no edit/delete of content).
 */
require_once __DIR__ . '/db_config.php';

function bc_ensure_feedback_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS resident_feedback (
        feedback_id INT AUTO_INCREMENT PRIMARY KEY,
        resident_user_id INT NULL DEFAULT NULL,
        submitter_name VARCHAR(150) NULL,
        status VARCHAR(32) NOT NULL,
        message TEXT NOT NULL,
        source VARCHAR(32) NOT NULL DEFAULT 'resident',
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fb_status (status),
        INDEX idx_fb_read (is_read),
        INDEX idx_fb_resident (resident_user_id),
        INDEX idx_fb_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * @return array<string, mixed>
 */
function bc_feedback_row_to_api(array $row): array
{
    $created = strtotime((string) ($row['created_at'] ?? ''));

    return [
        'id' => (int) ($row['feedback_id'] ?? 0),
        'residentUserId' => isset($row['resident_user_id']) ? (int) $row['resident_user_id'] : 0,
        'submitterName' => (string) ($row['submitter_name'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'message' => (string) ($row['message'] ?? ''),
        'source' => (string) ($row['source'] ?? 'resident'),
        'readStatus' => !empty($row['is_read']),
        'date' => $created ? date('c', $created) : null,
        'createdAt' => $created ? date('c', $created) : null,
    ];
}

function bc_normalize_feedback_status(string $status): string
{
    $s = trim($status);
    if (strcasecmp($s, 'Satisfied') === 0) {
        return 'Satisfied';
    }
    if (strcasecmp($s, 'Not Satisfied') === 0) {
        return 'Not Satisfied';
    }
    return '';
}

/**
 * @param array<string, mixed> $body
 * @return array{resident_user_id:?int,submitter_name:string,status:string,message:string,source:string}
 */
function bc_feedback_payload_from_body(array $body): array
{
    $status = bc_normalize_feedback_status((string) ($body['status'] ?? ''));
    $message = trim((string) ($body['message'] ?? ''));
    $residentUserId = (int) ($body['residentUserId'] ?? $body['resident_user_id'] ?? 0);
    $submitterName = trim((string) ($body['submitterName'] ?? $body['submitter_name'] ?? ''));
    $source = trim((string) ($body['source'] ?? 'resident'));
    if ($source === '') {
        $source = 'resident';
    }

    return [
        'resident_user_id' => $residentUserId > 0 ? $residentUserId : null,
        'submitter_name' => $submitterName,
        'status' => $status,
        'message' => $message,
        'source' => $source,
    ];
}

function bc_feedback_fetch_by_id(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare(
        'SELECT feedback_id, resident_user_id, submitter_name, status, message, source, is_read, created_at
         FROM resident_feedback WHERE feedback_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}
