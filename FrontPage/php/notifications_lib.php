<?php
/**
 * Resident notifications (request/report updates, admin alerts).
 * Announcements are NOT wired here.
 */
require_once __DIR__ . '/db_config.php';

function bc_ensure_resident_notifications_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS resident_notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        resident_user_id INT NOT NULL DEFAULT 0,
        resident_auth_id CHAR(36) NULL,
        type VARCHAR(32) NOT NULL,
        priority VARCHAR(16) NOT NULL DEFAULT 'normal',
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        link_section VARCHAR(32) NULL,
        reference_id INT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rn_resident (resident_user_id),
        INDEX idx_rn_unread (resident_user_id, is_read),
        INDEX idx_rn_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $authColumn = $conn->query("SHOW COLUMNS FROM resident_notifications LIKE 'resident_auth_id'");
    if ($authColumn && $authColumn->num_rows === 0) {
        $conn->query('ALTER TABLE resident_notifications ADD COLUMN resident_auth_id CHAR(36) NULL AFTER resident_user_id');
    }
}

/**
 * @return array<string, mixed>|null
 */
function bc_notification_row_to_api(array $row): array
{
    $ts = strtotime((string) ($row['created_at'] ?? ''));
    return [
        'notificationId' => (int) ($row['notification_id'] ?? 0),
        'residentUserId' => (int) ($row['resident_user_id'] ?? 0),
        'type' => (string) ($row['type'] ?? ''),
        'priority' => (string) ($row['priority'] ?? 'normal'),
        'title' => (string) ($row['title'] ?? ''),
        'message' => (string) ($row['message'] ?? ''),
        'linkSection' => $row['link_section'] ?? null,
        'referenceId' => isset($row['reference_id']) ? (int) $row['reference_id'] : null,
        'isRead' => !empty($row['is_read']),
        'createdAt' => $ts ? date('c', $ts) : date('c'),
    ];
}

function bc_insert_resident_notification(
    mysqli $conn,
    int $residentUserId,
    string $type,
    string $priority,
    string $title,
    string $message,
    ?string $linkSection = null,
    ?int $referenceId = null,
    ?string $authUserId = null
): void {
    $authUserId = trim((string) $authUserId);
    if (($residentUserId <= 0 && !preg_match('/^[0-9a-f-]{36}$/i', $authUserId))
        || $title === '' || $message === '') {
        return;
    }

    bc_ensure_resident_notifications_table($conn);

    $stmt = $conn->prepare(
        'INSERT INTO resident_notifications
         (resident_user_id, resident_auth_id, type, priority, title, message, link_section, reference_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ref = $referenceId ?? 0;
    $stmt->bind_param('issssssi', $residentUserId, $authUserId, $type, $priority, $title, $message, $linkSection, $ref);
    $stmt->execute();
    $stmt->close();
}

function bc_request_status_label(string $status): string
{
    $s = strtolower(trim($status));
    $map = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'rejected' => 'Rejected',
    ];
    return $map[$s] ?? ucfirst(str_replace('_', ' ', $s));
}

/**
 * @param array<string, mixed> $row service_requests row
 */
function bc_notify_request_update(
    mysqli $conn,
    array $row,
    string $newStatus,
    ?string $remarks,
    string $oldStatus
): void {
    $residentId = (int) ($row['resident_user_id'] ?? 0);
    $authUserId = trim((string) ($row['resident_auth_id'] ?? ''));
    if ($residentId <= 0 && !preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        return;
    }

    $requestPk = (int) ($row['request_pk'] ?? 0);
    $doc = trim((string) ($row['document_type'] ?? 'Service request'));
    $code = trim((string) ($row['request_code'] ?? ''));
    $label = $code !== '' ? $code : ('Request #' . $requestPk);

    $statusChanged = strtolower(trim($oldStatus)) !== strtolower(trim($newStatus));
    $hasRemarks = $remarks !== null && trim($remarks) !== '';

    if (!$statusChanged && !$hasRemarks) {
        return;
    }

    if ($statusChanged) {
        $statusLabel = bc_request_status_label($newStatus);
        $priority = in_array(strtolower($newStatus), ['completed', 'rejected'], true) ? 'urgent' : 'urgent';
        $title = 'Request status updated';
        $message = $doc . ' (' . $label . ') is now ' . $statusLabel . '.';
        if ($hasRemarks) {
            $message .= ' Message: ' . trim($remarks);
        }
        bc_insert_resident_notification(
            $conn,
            $residentId,
            'request_update',
            $priority,
            $title,
            $message,
            'requests',
            $requestPk,
            $authUserId
        );
        return;
    }

    if ($hasRemarks) {
        $oldRemarks = trim((string) ($row['remarks'] ?? ''));
        if (trim($remarks) === $oldRemarks) {
            return;
        }
        bc_insert_resident_notification(
            $conn,
            $residentId,
            'request_update',
            'urgent',
            'Message from barangay',
            $doc . ' (' . $label . '): ' . trim($remarks),
            'requests',
            $requestPk,
            $authUserId
        );
    }
}

/**
 * @param array<string, mixed> $row community_reports row
 */
function bc_notify_report_update(mysqli $conn, array $row, string $newStatus, string $oldStatus): void
{
    $residentId = (int) ($row['resident_user_id'] ?? 0);
    $authUserId = trim((string) ($row['resident_auth_id'] ?? ''));
    if ($residentId <= 0 && !preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        return;
    }

    if (strcasecmp(trim($oldStatus), trim($newStatus)) === 0) {
        return;
    }

    $reportId = (int) ($row['report_id'] ?? 0);
    $titleText = trim((string) ($row['title'] ?? 'Community report'));
    $reportLabel = 'RPT-' . str_pad((string) $reportId, 4, '0', STR_PAD_LEFT);

    $priority = in_array($newStatus, ['Resolved', 'Cancelled'], true) ? 'urgent' : 'normal';
    $title = 'Report status updated';
    $message = $titleText . ' (' . $reportLabel . ') is now ' . $newStatus . '.';

    bc_insert_resident_notification(
        $conn,
        $residentId,
        'report_update',
        $priority,
        $title,
        $message,
        'reports',
        $reportId,
        $authUserId
    );
}

function bc_notification_exists_for_reference(
    mysqli $conn,
    int $residentId,
    string $type,
    int $referenceId,
    ?string $authUserId = null
): bool {
    if (($residentId <= 0 && !preg_match('/^[0-9a-f-]{36}$/i', (string) $authUserId)) || $referenceId <= 0) {
        return false;
    }
    $authId = (string) ($authUserId ?? '');
    if ($residentId > 0) {
        $stmt = $conn->prepare(
            'SELECT 1 FROM resident_notifications
             WHERE (resident_user_id = ? OR resident_auth_id = ?) AND type = ? AND reference_id = ?
             LIMIT 1'
        );
        $stmt->bind_param('issi', $residentId, $authId, $type, $referenceId);
    } else {
        $stmt = $conn->prepare(
            'SELECT 1 FROM resident_notifications
             WHERE resident_auth_id = ? AND type = ? AND reference_id = ?
             LIMIT 1'
        );
        $stmt->bind_param('ssi', $authId, $type, $referenceId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Create notifications for existing request updates that pre-date the notification system.
 */
function bc_sync_request_notifications_for_resident(mysqli $conn, int $residentId, ?string $authUserId = null): void
{
    $authUserId = trim((string) $authUserId);
    if ($residentId <= 0 && !preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        return;
    }

    bc_ensure_resident_notifications_table($conn);

    if ($residentId > 0) {
        $stmt = $conn->prepare(
            'SELECT request_pk, request_code, resident_user_id, resident_auth_id, document_type, status, remarks
             FROM service_requests
             WHERE resident_user_id = ? OR resident_auth_id = ?
             ORDER BY request_pk DESC
             LIMIT 20'
        );
        $stmt->bind_param('is', $residentId, $authUserId);
    } else {
        $stmt = $conn->prepare(
            'SELECT request_pk, request_code, resident_user_id, resident_auth_id, document_type, status, remarks
             FROM service_requests
             WHERE resident_auth_id = ?
             ORDER BY request_pk DESC
             LIMIT 20'
        );
        $stmt->bind_param('s', $authUserId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        return;
    }

    while ($row = $result->fetch_assoc()) {
        $requestPk = (int) ($row['request_pk'] ?? 0);
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if ($requestPk <= 0 || $status === '' || $status === 'pending') {
            continue;
        }
        if (bc_notification_exists_for_reference($conn, $residentId, 'request_update', $requestPk, $authUserId)) {
            continue;
        }

        $row['request_pk'] = $requestPk;
        bc_notify_request_update($conn, $row, (string) ($row['status'] ?? ''), null, 'pending');
    }

    $stmt->close();
}

function bc_notification_counts_for_resident(mysqli $conn, int $residentId, ?string $authUserId = null): array
{
    bc_ensure_resident_notifications_table($conn);
    $authId = (string) ($authUserId ?? '');
    if ($residentId > 0) {
        $stmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total_unread,
                SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) AS urgent_unread
             FROM resident_notifications
             WHERE (resident_user_id = ? OR resident_auth_id = ?) AND is_read = 0"
        );
        $stmt->bind_param('is', $residentId, $authId);
    } else {
        $stmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total_unread,
                SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) AS urgent_unread
             FROM resident_notifications
             WHERE resident_auth_id = ? AND is_read = 0"
        );
        $stmt->bind_param('s', $authId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $total = (int) ($row['total_unread'] ?? 0);
    $urgent = (int) ($row['urgent_unread'] ?? 0);

    return [
        'totalUnread' => $total,
        'urgentUnread' => $urgent,
        'normalUnread' => max(0, $total - $urgent),
    ];
}
