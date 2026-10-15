<?php
/**
 * Transparency — annual budget, projects & programs (admin + resident).
 */
require_once __DIR__ . '/db_config.php';

function bc_ensure_transparency_tables(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS transparency_budget (
        budget_id INT AUTO_INCREMENT PRIMARY KEY,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS transparency_budget_history (
        history_id INT AUTO_INCREMENT PRIMARY KEY,
        previous_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        new_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tbh_changed (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS transparency_items (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'Project',
        budget DECIMAL(14,2) NOT NULL DEFAULT 0,
        spent DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'Ongoing',
        description TEXT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ti_status (status),
        INDEX idx_ti_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS transparency_documents (
        document_id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        file_path VARCHAR(512) NOT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_td_item (item_id),
        CONSTRAINT fk_td_item FOREIGN KEY (item_id) REFERENCES transparency_items(item_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    bc_seed_transparency_defaults($conn);
    bc_replace_transparency_placeholder_items($conn);
}

/**
 * Replace old test/placeholder transparency rows with real barangay content.
 */
function bc_replace_transparency_placeholder_items(mysqli $conn): void
{
    $placeholderNames = ['asada'];
    $replacement = [
        'name' => 'Solid Waste Management & Clean-up Drive',
        'type' => 'Program',
        'budget' => 280000.00,
        'spent' => 168000.00,
        'status' => 'Ongoing',
        'description' => 'Weekly barangay clean-up, segregation drives, and collection schedules for puroks and coastal areas in Barangay Tambo.',
        'start_date' => '2026-03-01',
        'end_date' => '2026-12-15',
    ];

    $stmt = $conn->prepare(
        'UPDATE transparency_items
         SET name = ?, type = ?, budget = ?, spent = ?, status = ?, description = ?, start_date = ?, end_date = ?
         WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))'
    );
    if (!$stmt) {
        return;
    }

    foreach ($placeholderNames as $oldName) {
        $stmt->bind_param(
            'ssddsssss',
            $replacement['name'],
            $replacement['type'],
            $replacement['budget'],
            $replacement['spent'],
            $replacement['status'],
            $replacement['description'],
            $replacement['start_date'],
            $replacement['end_date'],
            $oldName
        );
        $stmt->execute();
    }

    $stmt->close();
}

/**
 * Ensure default barangay projects/programs exist (inserts any that are missing by name).
 *
 * @return array<int, string>
 */
function bc_transparency_default_items(): array
{
    return [
        [
            'name' => 'Street & Drainage Rehabilitation',
            'type' => 'Project',
            'budget' => 850000.00,
            'spent' => 412500.00,
            'status' => 'Ongoing',
            'description' => 'Phase 1 repair of drainage and walkways along T.M Gabriel and Riverside areas in Barangay Tambo to reduce flooding during the rainy season.',
            'start_date' => '2026-01-15',
            'end_date' => '2026-08-30',
        ],
        [
            'name' => 'Community Health & Immunization Program',
            'type' => 'Program',
            'budget' => 320000.00,
            'spent' => 185400.00,
            'status' => 'Ongoing',
            'description' => 'Free vaccines, medical check-ups, and medicines for residents through the Barangay Health Center and partner LGU clinics.',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ],
        [
            'name' => 'Youth Skills & Livelihood Training',
            'type' => 'Program',
            'budget' => 450000.00,
            'spent' => 276800.00,
            'status' => 'Ongoing',
            'description' => 'Skills workshops and TESDA certification support for out-of-school youth and displaced workers in Barangay Tambo.',
            'start_date' => '2026-02-01',
            'end_date' => '2026-11-30',
        ],
    ];
}

function bc_seed_transparency_defaults(mysqli $conn): void
{
    $checkStmt = $conn->prepare('SELECT item_id FROM transparency_items WHERE name = ? LIMIT 1');
    $insertStmt = $conn->prepare(
        'INSERT INTO transparency_items (name, type, budget, spent, status, description, start_date, end_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$checkStmt || !$insertStmt) {
        return;
    }

    foreach (bc_transparency_default_items() as $row) {
        $name = $row['name'];
        $checkStmt->bind_param('s', $name);
        $checkStmt->execute();
        $exists = $checkStmt->get_result();
        if ($exists && $exists->num_rows > 0) {
            continue;
        }

        $insertStmt->bind_param(
            'ssddssss',
            $row['name'],
            $row['type'],
            $row['budget'],
            $row['spent'],
            $row['status'],
            $row['description'],
            $row['start_date'],
            $row['end_date']
        );
        $insertStmt->execute();
    }

    $checkStmt->close();
    $insertStmt->close();

    $budgetRes = $conn->query('SELECT COUNT(*) AS n FROM transparency_budget');
    if ($budgetRes && (int) ($budgetRes->fetch_assoc()['n'] ?? 0) === 0) {
        $annualBudget = 3500000.00;
        $budgetStmt = $conn->prepare('INSERT INTO transparency_budget (amount) VALUES (?)');
        if ($budgetStmt) {
            $budgetStmt->bind_param('d', $annualBudget);
            $budgetStmt->execute();
            $budgetStmt->close();
        }
    }
}

function bc_transparency_upload_dir(): string
{
    $dir = __DIR__ . '/../uploads/transparency';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return realpath($dir) ?: $dir;
}

/**
 * @param array<string, mixed> $row
 * @param array<int, array<string, mixed>> $documents
 * @return array<string, mixed>
 */
function bc_transparency_item_to_api(array $row, array $documents = []): array
{
    return [
        'id' => (int) ($row['item_id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'type' => (string) ($row['type'] ?? 'Project'),
        'budget' => (float) ($row['budget'] ?? 0),
        'spent' => (float) ($row['spent'] ?? 0),
        'status' => (string) ($row['status'] ?? 'Ongoing'),
        'description' => (string) ($row['description'] ?? ''),
        'startDate' => $row['start_date'] ?? null,
        'endDate' => $row['end_date'] ?? null,
        'documents' => $documents,
    ];
}

function bc_transparency_fetch_documents(mysqli $conn, int $itemId): array
{
    $stmt = $conn->prepare(
        'SELECT document_id, file_path FROM transparency_documents WHERE item_id = ? ORDER BY uploaded_at DESC'
    );
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $docs = [];
    $uploadDir = bc_transparency_upload_dir();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $fileName = basename((string) $row['file_path']);
            $fileOnDisk = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
            // Do not publish a download link when a previous deployment removed the uploaded file.
            if (!is_file($fileOnDisk) || !is_readable($fileOnDisk)) {
                continue;
            }
            $docs[] = [
                'id' => (int) $row['document_id'],
                'filePath' => 'FrontPage/uploads/transparency/' . $fileName,
            ];
        }
    }
    $stmt->close();

    return $docs;
}
function bc_transparency_get_current_budget(mysqli $conn): ?float
{
    $res = $conn->query('SELECT amount FROM transparency_budget ORDER BY budget_id DESC LIMIT 1');
    if (!$res || $res->num_rows === 0) {
        return null;
    }
    $row = $res->fetch_assoc();

    return (float) ($row['amount'] ?? 0);
}
