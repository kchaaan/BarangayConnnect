<?php
/**
 * Community reports (resident submit → admin A_Reports.html).
 */
require_once __DIR__ . '/db_config.php';

function bc_ensure_reports_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS community_reports (
        report_id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        category VARCHAR(128) NOT NULL,
        location VARCHAR(512) NOT NULL,
        description TEXT NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'Pending',
        image_path VARCHAR(512) NULL,
        resident_user_id INT NULL,
        resident_auth_id CHAR(36) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL DEFAULT NULL,
        archived_at DATETIME NULL DEFAULT NULL,
        INDEX idx_cr_status (status),
        INDEX idx_cr_created (created_at),
        INDEX idx_cr_archived (archived_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $authColumn = $conn->query("SHOW COLUMNS FROM community_reports LIKE 'resident_auth_id'");
    if ($authColumn && $authColumn->num_rows === 0) {
        $conn->query('ALTER TABLE community_reports ADD COLUMN resident_auth_id CHAR(36) NULL AFTER resident_user_id');
    }
}

function bc_ensure_reports_archive_columns(mysqli $conn): void
{
    bc_ensure_reports_table($conn);

    foreach (['resolved_at' => 'DATETIME NULL DEFAULT NULL', 'archived_at' => 'DATETIME NULL DEFAULT NULL'] as $col => $def) {
        $r = $conn->query("SHOW COLUMNS FROM community_reports LIKE '{$col}'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE community_reports ADD COLUMN {$col} {$def}");
        }
    }

    $conn->query(
        "UPDATE community_reports
         SET archived_at = COALESCE(updated_at, created_at)
         WHERE archived_at IS NULL
           AND LOWER(status) IN ('cancelled', 'canceled')"
    );

    $conn->query(
        "UPDATE community_reports
         SET resolved_at = COALESCE(updated_at, created_at)
         WHERE resolved_at IS NULL
           AND status = 'Resolved'
           AND archived_at IS NULL"
    );
}

function bc_auto_archive_expired_resolved(mysqli $conn): void
{
    bc_ensure_reports_archive_columns($conn);
    $conn->query(
        "UPDATE community_reports
         SET archived_at = NOW()
         WHERE archived_at IS NULL
           AND status = 'Resolved'
           AND resolved_at IS NOT NULL
           AND resolved_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
}

function bc_reports_select_sql(): string
{
    return 'SELECT report_id, title, category, location, description, status, image_path,
            resident_user_id, resident_auth_id, created_at, updated_at, resolved_at, archived_at
            FROM community_reports';
}

function bc_reports_upload_dir(): string
{
    $dir = __DIR__ . '/../uploads/reports';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return realpath($dir) ?: $dir;
}

/**
 * @param array<string, mixed> $row
 * @param bool $includeImage
 * @return array<string, mixed>
 */
function bc_report_row_to_api(array $row, bool $includeImage = false): array
{
    $created = $row['created_at'] ?? '';
    $updated = $row['updated_at'] ?? $created;
    $ts = $created ? strtotime((string) $created) : false;
    $iso = $ts ? date('c', $ts) : date('c');
    $tsUp = $updated ? strtotime((string) $updated) : $ts;
    $isoUp = $tsUp ? date('c', $tsUp) : $iso;

    $archivedRaw = $row['archived_at'] ?? null;
    $resolvedRaw = $row['resolved_at'] ?? null;
    $archTs = $archivedRaw ? strtotime((string) $archivedRaw) : false;
    $resTs = $resolvedRaw ? strtotime((string) $resolvedRaw) : false;

    $out = [
        'reportId' => (int) ($row['report_id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'category' => (string) ($row['category'] ?? ''),
        'location' => (string) ($row['location'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'status' => (string) ($row['status'] ?? 'Pending'),
        'dateSubmitted' => $iso,
        'dateUpdated' => $isoUp,
        'dateResolved' => $resTs ? date('c', $resTs) : null,
        'dateArchived' => $archTs ? date('c', $archTs) : null,
        'isArchived' => !empty($archivedRaw),
    ];

    if ($includeImage && !empty($row['image_path'])) {
        $uploadRoot = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
        $abs = $uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['image_path']);
        if (is_readable($abs)) {
            $bin = file_get_contents($abs);
            if ($bin !== false) {
                $out['imageBase64'] = base64_encode($bin);
            }
        }
    }

    return $out;
}

function bc_normalize_report_status(string $status): string
{
    $s = trim($status);
    $map = [
        'pending' => 'Pending',
        'in progress' => 'In Progress',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'completed' => 'Resolved',
        'cancelled' => 'Cancelled',
        'canceled' => 'Cancelled',
    ];
    $key = strtolower($s);

    return $map[$key] ?? ($s !== '' ? $s : 'Pending');
}
