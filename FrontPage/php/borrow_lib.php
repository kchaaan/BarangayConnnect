<?php
/**
 * Borrow requests for barangay inventory items.
 */
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/inventory_lib.php';

function bc_ensure_borrow_tables(mysqli $conn): void
{
    bc_ensure_inventory_tables($conn);

    $conn->query("CREATE TABLE IF NOT EXISTS borrow_requests (
        borrow_pk INT AUTO_INCREMENT PRIMARY KEY,
        borrow_code VARCHAR(32) NOT NULL UNIQUE,
        resident_user_id INT NOT NULL,
        auth_user_id CHAR(36) NULL,
        item_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        date_needed DATE NOT NULL,
        return_date DATE NOT NULL,
        purpose TEXT NOT NULL,
        remarks TEXT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        admin_remarks TEXT NULL,
        rejection_reason VARCHAR(255) NULL,
        return_condition VARCHAR(128) NULL,
        return_remarks TEXT NULL,
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        released_at DATETIME NULL,
        returned_at DATETIME NULL,
        applicant_name VARCHAR(255) NULL,
        applicant_resident_id VARCHAR(64) NULL,
        applicant_contact VARCHAR(64) NULL,
        INDEX idx_br_status (status),
        INDEX idx_br_resident (resident_user_id),
        INDEX idx_br_auth_user (auth_user_id),
        INDEX idx_br_item (item_id),
        INDEX idx_br_return_date (return_date),
        CONSTRAINT fk_br_item FOREIGN KEY (item_id)
            REFERENCES inventory_items(item_id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    bc_upgrade_borrow_schema($conn);
}

function bc_upgrade_borrow_schema(mysqli $conn): void
{
    $columns = [
        'applicant_name' => 'VARCHAR(255) NULL',
        'applicant_resident_id' => 'VARCHAR(64) NULL',
        'applicant_contact' => 'VARCHAR(64) NULL',
    ];
    foreach ($columns as $name => $definition) {
        $r = $conn->query("SHOW COLUMNS FROM borrow_requests LIKE '{$name}'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE borrow_requests ADD COLUMN {$name} {$definition}");
        }
    }
    $authColumn = $conn->query("SHOW COLUMNS FROM borrow_requests LIKE 'auth_user_id'");
    if ($authColumn && $authColumn->num_rows === 0) {
        $conn->query('ALTER TABLE borrow_requests ADD COLUMN auth_user_id CHAR(36) NULL AFTER resident_user_id');
    }
    $authIndex = $conn->query("SHOW INDEX FROM borrow_requests WHERE Key_name = 'idx_br_auth_user'");
    if ($authIndex && $authIndex->num_rows === 0) {
        $conn->query('ALTER TABLE borrow_requests ADD INDEX idx_br_auth_user (auth_user_id)');
    }
}

function bc_generate_borrow_code(mysqli $conn): string
{
    $year = date('Y');
    $r = $conn->query("SELECT MAX(borrow_pk) AS m FROM borrow_requests");
    $n = 1;
    if ($r) {
        $n = ((int) ($r->fetch_assoc()['m'] ?? 0)) + 1;
    }

    return 'BR-' . $year . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

function bc_borrow_select_sql(): string
{
    return 'SELECT b.*,
            i.name AS item_name, i.unit AS item_unit, i.available_qty AS item_available_qty,
            c.name AS category_name,
            u.first_name, u.middle_name, u.last_name, u.resident_id AS resident_code,
            u.contact_number, u.email, u.full_address
            FROM borrow_requests b
            INNER JOIN inventory_items i ON i.item_id = b.item_id
            LEFT JOIN inventory_categories c ON c.category_id = i.category_id
            LEFT JOIN users_resident u ON u.user_id = b.resident_user_id';
}

/**
 * @return array<string, mixed>
 */
function bc_borrow_row_to_api(array $row): array
{
    $nameParts = array_filter([
        trim((string) ($row['first_name'] ?? '')),
        trim((string) ($row['middle_name'] ?? '')),
        trim((string) ($row['last_name'] ?? '')),
    ]);
    $profileName = trim(implode(' ', $nameParts));
    $applicantName = trim((string) ($row['applicant_name'] ?? ''));
    $applicantCode = trim((string) ($row['applicant_resident_id'] ?? ''));
    $applicantContact = trim((string) ($row['applicant_contact'] ?? ''));

    return [
        'borrowPk' => (int) ($row['borrow_pk'] ?? 0),
        'borrowCode' => (string) ($row['borrow_code'] ?? ''),
        'residentUserId' => (int) ($row['resident_user_id'] ?? 0),
        'residentCode' => $applicantCode !== '' ? $applicantCode : (string) ($row['resident_code'] ?? ''),
        'residentName' => $applicantName !== '' ? $applicantName : $profileName,
        'contactNumber' => $applicantContact !== '' ? $applicantContact : (string) ($row['contact_number'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'address' => (string) ($row['full_address'] ?? ''),
        'itemId' => (int) ($row['item_id'] ?? 0),
        'itemName' => (string) ($row['item_name'] ?? ''),
        'categoryName' => (string) ($row['category_name'] ?? ''),
        'itemUnit' => (string) ($row['item_unit'] ?? 'pcs'),
        'itemAvailableQty' => (int) ($row['item_available_qty'] ?? 0),
        'quantity' => (int) ($row['quantity'] ?? 1),
        'dateNeeded' => (string) ($row['date_needed'] ?? ''),
        'returnDate' => (string) ($row['return_date'] ?? ''),
        'purpose' => (string) ($row['purpose'] ?? ''),
        'remarks' => (string) ($row['remarks'] ?? ''),
        'status' => (string) ($row['status'] ?? 'pending'),
        'adminRemarks' => (string) ($row['admin_remarks'] ?? ''),
        'rejectionReason' => (string) ($row['rejection_reason'] ?? ''),
        'returnCondition' => (string) ($row['return_condition'] ?? ''),
        'returnRemarks' => (string) ($row['return_remarks'] ?? ''),
        'submittedAt' => (string) ($row['submitted_at'] ?? ''),
        'updatedAt' => $row['updated_at'] ?? null,
        'releasedAt' => $row['released_at'] ?? null,
        'returnedAt' => $row['returned_at'] ?? null,
    ];
}

/**
 * @param array<int, string> $statuses
 * @return array<int, array<string, mixed>>
 */
function bc_fetch_borrow_requests(
    mysqli $conn,
    array $statuses = [],
    ?int $residentUserId = null,
    ?int $categoryId = null,
    ?string $search = null,
    ?string $authUserId = null
): array {
    $sql = bc_borrow_select_sql() . ' WHERE 1=1';
    $types = '';
    $params = [];

    if ($authUserId !== null && $authUserId !== '') {
        if ($residentUserId && $residentUserId > 0) {
            $sql .= ' AND (b.auth_user_id = ? OR b.resident_user_id = ?)';
            $types .= 'si';
            $params[] = $authUserId;
            $params[] = $residentUserId;
        } else {
            $sql .= ' AND b.auth_user_id = ?';
            $types .= 's';
            $params[] = $authUserId;
        }
    } elseif ($residentUserId && $residentUserId > 0) {
        $sql .= ' AND b.resident_user_id = ?';
        $types .= 'i';
        $params[] = $residentUserId;
    }
    if ($statuses) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql .= " AND b.status IN ({$placeholders})";
        $types .= str_repeat('s', count($statuses));
        foreach ($statuses as $s) {
            $params[] = $s;
        }
    }
    if ($categoryId && $categoryId > 0) {
        $sql .= ' AND i.category_id = ?';
        $types .= 'i';
        $params[] = $categoryId;
    }
    if ($search !== null && trim($search) !== '') {
        $sql .= ' AND (b.borrow_code LIKE ? OR i.name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
        $types .= 'ssss';
        $like = '%' . trim($search) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY b.submitted_at DESC';

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $out = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $out[] = bc_borrow_row_to_api($row);
        }
    }
    $stmt->close();

    return $out;
}

/**
 * @return array<string, int>
 */
function bc_borrow_stats(mysqli $conn): array
{
    $stats = [
        'total' => 0,
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'released' => 0,
        'returned' => 0,
        'overdue' => 0,
        'damaged_lost' => 0,
    ];
    $r = $conn->query('SELECT status, COUNT(*) AS c FROM borrow_requests GROUP BY status');
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $c = (int) $row['c'];
            $stats['total'] += $c;
            $key = (string) $row['status'];
            if (isset($stats[$key])) {
                $stats[$key] = $c;
            }
        }
    }

    bc_mark_overdue_borrows($conn);
    $r2 = $conn->query("SELECT COUNT(*) AS c FROM borrow_requests WHERE status = 'overdue'");
    if ($r2) {
        $stats['overdue'] = (int) ($r2->fetch_assoc()['c'] ?? 0);
    }

    return $stats;
}

function bc_mark_overdue_borrows(mysqli $conn): void
{
    $conn->query(
        "UPDATE borrow_requests
         SET status = 'overdue', updated_at = NOW()
         WHERE status = 'released'
           AND return_date < CURDATE()"
    );
}

function bc_get_borrow_request(mysqli $conn, int $borrowPk): ?array
{
    $stmt = $conn->prepare(bc_borrow_select_sql() . ' WHERE b.borrow_pk = ? LIMIT 1');
    $stmt->bind_param('i', $borrowPk);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? bc_borrow_row_to_api($row) : null;
}

function bc_adjust_item_available(mysqli $conn, int $itemId, int $delta): bool
{
    $stmt = $conn->prepare(
        'UPDATE inventory_items SET available_qty = available_qty + ? WHERE item_id = ? AND available_qty + ? >= 0'
    );
    $stmt->bind_param('iii', $delta, $itemId, $delta);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    if ($ok) {
        bc_sync_item_status($conn, $itemId);
    }

    return $ok;
}
