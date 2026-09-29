<?php
/**
 * Inventory categories & items (barangay borrowable equipment).
 */
require_once __DIR__ . '/db_config.php';

function bc_inventory_item_status(int $available, int $total): string
{
    if ($total <= 0 || $available <= 0) {
        return 'unavailable';
    }
    if ($available <= max(1, (int) floor($total * 0.2))) {
        return 'low_stock';
    }

    return 'available';
}

function bc_ensure_inventory_tables(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS inventory_categories (
        category_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(128) NOT NULL,
        description VARCHAR(512) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_inv_cat_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS inventory_items (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        total_qty INT NOT NULL DEFAULT 0,
        available_qty INT NOT NULL DEFAULT 0,
        unit VARCHAR(32) NOT NULL DEFAULT 'pcs',
        status VARCHAR(32) NOT NULL DEFAULT 'available',
        image_path VARCHAR(512) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_inv_item_category (category_id),
        INDEX idx_inv_item_status (status),
        CONSTRAINT fk_inv_item_category FOREIGN KEY (category_id)
            REFERENCES inventory_categories(category_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    bc_seed_inventory_demo($conn);
    bc_repair_inventory_units($conn);
}

function bc_seed_inventory_demo(mysqli $conn): void
{
    // Seed the public catalog once, with zero stock until staff enter verified counts.
    $conn->query("CREATE TABLE IF NOT EXISTS bc_seed_registry (
        seed_key VARCHAR(128) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $seedKey = 'borrow_equipment_catalog_v1';
    $claim = $conn->prepare('INSERT IGNORE INTO bc_seed_registry (seed_key) VALUES (?)');
    if (!$claim) {
        return;
    }
    $claim->bind_param('s', $seedKey);
    $claim->execute();
    $firstRun = $claim->affected_rows === 1;
    $claim->close();
    if (!$firstRun) return;

    $categories = [
        ['Events & Setup', 'Official documents, event spaces, and event setup'],
        ['Furniture', 'Seating, tables, cots, bins, and serving equipment'],
        ['Audio & Visual', 'Sound, presentation, and recording equipment'],
        ['Electrical', 'Power cables and lighting equipment'],
        ['Cleaning & Utilities', 'Cleaning supplies and utility equipment'],
        ['Emergency & Medical', 'First aid, accessibility, and rescue equipment'],
        ['Safety & Traffic', 'Crowd control, traffic, and personal safety equipment'],
        ['Tools & Maintenance', 'Hand tools and maintenance equipment'],
        ['Sports', 'Sports and recreation equipment'],
    ];

    $catIds = [];
    $stmt = $conn->prepare('INSERT INTO inventory_categories (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)');
    if (!$stmt) return;
    foreach ($categories as $cat) {
        $stmt->bind_param('ss', $cat[0], $cat[1]);
        $stmt->execute();
    }
    $stmt->close();

    $catLookup = $conn->query('SELECT category_id, name FROM inventory_categories');
    if ($catLookup) {
        while ($row = $catLookup->fetch_assoc()) $catIds[$row['name']] = (int) $row['category_id'];
    }

    $items = [
        ['Events & Setup', 'Barangay Logo / Official Barangay Seal', 'Official seal; staff-assisted checkout and return.', 'unit'],
        ['Furniture', 'Monoblock Chairs', 'Stackable chairs for meetings and events.', 'pcs'],
        ['Furniture', 'Folding Chairs', 'Portable folding chairs.', 'pcs'],
        ['Events & Setup', 'Tent / Canopy', 'Portable event canopy. Confirm setup needs with staff.', 'unit'],
        ['Furniture', 'Folding Tables', 'Portable folding tables.', 'pcs'],
        ['Audio & Visual', 'Sound System / Speaker', 'Portable sound system or speaker set.', 'set'],
        ['Audio & Visual', 'Microphone', 'Wired or wireless microphone.', 'pcs'],
        ['Audio & Visual', 'Projector', 'Presentation projector and required accessories.', 'unit'],
        ['Audio & Visual', 'Projector Screen', 'Portable projection screen.', 'unit'],
        ['Electrical', 'Extension Cord', 'Check cable condition and electrical rating before use.', 'pcs'],
        ['Electrical', 'Power Strip / Multi-purpose Outlet', 'Use within the rated electrical load.', 'pcs'],
        ['Cleaning & Utilities', 'Walis / Cleaning Tools', 'Broom and other approved cleaning tools.', 'set'],
        ['Cleaning & Utilities', 'Timba / Water Container', 'Bucket or portable water container.', 'pcs'],
        ['Emergency & Medical', 'Fire Extinguisher', 'Emergency equipment; staff-assisted release and inspection.', 'unit'],
        ['Emergency & Medical', 'First Aid Kit', 'Portable first-aid kit; report used or missing supplies.', 'kit'],
        ['Emergency & Medical', 'Wheelchair', 'Mobility assistance equipment.', 'unit'],
        ['Emergency & Medical', 'Crutches', 'Mobility assistance pair.', 'pair'],
        ['Furniture', 'Folding Bed / Cot', 'Portable folding cot.', 'unit'],
        ['Emergency & Medical', 'Emergency Rescue Kit', 'Barangay-approved emergency response kit.', 'kit'],
        ['Safety & Traffic', 'Traffic Cones', 'Traffic and event crowd-control cones.', 'pcs'],
        ['Safety & Traffic', 'Barricade', 'Portable safety barricade.', 'pcs'],
        ['Safety & Traffic', 'Safety Vest', 'High-visibility safety vest.', 'pcs'],
        ['Safety & Traffic', 'Flashlight', 'Portable flashlight; return with accessories.', 'pcs'],
        ['Safety & Traffic', 'Megaphone', 'Portable public-address megaphone.', 'unit'],
        ['Audio & Visual', 'Portable Camera', 'Barangay activity documentation camera; staff-assisted checkout.', 'unit'],
        ['Electrical', 'Emergency Lights', 'Portable emergency lighting.', 'pcs'],
        ['Tools & Maintenance', 'Hammer', 'Hand tool; staff-assisted checkout.', 'pcs'],
        ['Tools & Maintenance', 'Hand Saw', 'Hand tool; staff-assisted checkout.', 'pcs'],
        ['Tools & Maintenance', 'Tool Kit', 'Basic maintenance tool kit.', 'kit'],
        ['Tools & Maintenance', 'Ladder', 'Portable ladder; follow safe-use guidance.', 'unit'],
        ['Cleaning & Utilities', 'Water Pump', 'Portable water pump; confirm power and hose requirements.', 'unit'],
        ['Cleaning & Utilities', 'Electric Fan', 'Portable electric fan.', 'unit'],
        ['Cleaning & Utilities', 'Cooler / Ice Box', 'Portable cooler or insulated ice box.', 'unit'],
        ['Furniture', 'Food Trays / Serving Equipment', 'Reusable food trays and approved serving equipment.', 'set'],
        ['Sports', 'Sports Equipment', 'General sports equipment set; list contents in request notes.', 'set'],
        ['Sports', 'Basketball', 'Basketball for community activities.', 'pcs'],
        ['Sports', 'Volleyball', 'Volleyball for community activities.', 'pcs'],
        ['Sports', 'Football', 'Football for community activities.', 'pcs'],
        ['Sports', 'Badminton Set', 'Rackets, shuttlecocks, and any listed accessories.', 'set'],
        ['Events & Setup', 'Event Decorations / Tarpaulin Stand', 'Portable display stand and approved event decorations.', 'set'],
        ['Cleaning & Utilities', 'Garbage Bins', 'Portable waste bins for community activities.', 'pcs'],
    ];

    $stmt = $conn->prepare('SELECT item_id FROM inventory_items WHERE name = ? LIMIT 1');
    $insert = $conn->prepare("INSERT INTO inventory_items (category_id, name, description, total_qty, available_qty, unit, status) VALUES (?, ?, ?, 0, 0, ?, 'unavailable')");
    if (!$stmt || !$insert) return;
    foreach ($items as $item) {
        if (!isset($catIds[$item[0]])) continue;
        $categoryId = $catIds[$item[0]];
        $stmt->bind_param('s', $item[1]);
        $stmt->execute();
        $existing = $stmt->get_result();
        if ($existing && $existing->num_rows > 0) continue;
        $insert->bind_param('isss', $categoryId, $item[1], $item[2], $item[3]);
        $insert->execute();
    }
    $stmt->close();
    $insert->close();
}

/**
 * Fix units corrupted by incorrect mysqli bind types (stored as "0").
 */
function bc_repair_inventory_units(mysqli $conn): void
{
    $byName = [
        'Monoblock Chair (White)' => 'pcs',
        'Folding Table (6ft)' => 'pcs',
        'Sound System Set' => 'set',
        'Portable Generator' => 'unit',
        'Volleyball Net' => 'set',
        'First Aid Kit' => 'kit',
    ];

    $stmt = $conn->prepare('UPDATE inventory_items SET unit = ? WHERE name = ? AND (unit = ? OR unit = ? OR unit = ?)');
    $zero = '0';
    $empty = '';
    foreach ($byName as $name => $unit) {
        $stmt->bind_param('sssss', $unit, $name, $zero, $empty, $zero);
        $stmt->execute();
    }
    $stmt->close();

    $conn->query("UPDATE inventory_items SET unit = 'pcs' WHERE unit = '0' OR unit = ''");
}

/**
 * @return array<string, mixed>
 */
function bc_inventory_category_row_to_api(array $row): array
{
    return [
        'categoryId' => (int) ($row['category_id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'itemCount' => (int) ($row['item_count'] ?? 0),
        'createdAt' => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>
 */
function bc_inventory_item_row_to_api(array $row): array
{
    $available = (int) ($row['available_qty'] ?? 0);
    $total = (int) ($row['total_qty'] ?? 0);
    $status = (string) ($row['status'] ?? bc_inventory_item_status($available, $total));

    return [
        'itemId' => (int) ($row['item_id'] ?? 0),
        'categoryId' => isset($row['category_id']) ? (int) $row['category_id'] : null,
        'categoryName' => (string) ($row['category_name'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'totalQty' => $total,
        'availableQty' => $available,
        'unit' => (string) ($row['unit'] ?? 'pcs'),
        'status' => $status,
        'imagePath' => $row['image_path'] ?? null,
        'createdAt' => (string) ($row['created_at'] ?? ''),
        'updatedAt' => $row['updated_at'] ?? null,
    ];
}

function bc_inventory_items_select_sql(): string
{
    return 'SELECT i.*, c.name AS category_name
            FROM inventory_items i
            LEFT JOIN inventory_categories c ON c.category_id = i.category_id';
}

/**
 * @return array<int, array<string, mixed>>
 */
function bc_fetch_inventory_items(mysqli $conn, ?string $statusFilter = null, ?int $categoryId = null, ?string $search = null): array
{
    $sql = bc_inventory_items_select_sql() . ' WHERE 1=1';
    $types = '';
    $params = [];

    if ($statusFilter && $statusFilter !== 'all') {
        $sql .= ' AND i.status = ?';
        $types .= 's';
        $params[] = $statusFilter;
    }
    if ($categoryId && $categoryId > 0) {
        $sql .= ' AND i.category_id = ?';
        $types .= 'i';
        $params[] = $categoryId;
    }
    if ($search !== null && trim($search) !== '') {
        $sql .= ' AND (i.name LIKE ? OR i.description LIKE ?)';
        $types .= 'ss';
        $like = '%' . trim($search) . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY i.name ASC';

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $out = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $out[] = bc_inventory_item_row_to_api($row);
        }
    }
    $stmt->close();

    return $out;
}

/**
 * @return array<string, int>
 */
function bc_inventory_stats(mysqli $conn): array
{
    $stats = ['total' => 0, 'available' => 0, 'low_stock' => 0, 'unavailable' => 0];
    $r = $conn->query(
        "SELECT status, COUNT(*) AS c FROM inventory_items GROUP BY status"
    );
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $stats['total'] += (int) $row['c'];
            $key = str_replace(' ', '_', (string) $row['status']);
            if (isset($stats[$key])) {
                $stats[$key] = (int) $row['c'];
            } elseif ($row['status'] === 'low_stock') {
                $stats['low_stock'] = (int) $row['c'];
            }
        }
    }
  if ($stats['total'] === 0) {
        $r2 = $conn->query('SELECT COUNT(*) AS c FROM inventory_items');
        if ($r2) {
            $stats['total'] = (int) ($r2->fetch_assoc()['c'] ?? 0);
        }
    }

    return $stats;
}

function bc_sync_item_status(mysqli $conn, int $itemId): void
{
    $stmt = $conn->prepare('SELECT total_qty, available_qty FROM inventory_items WHERE item_id = ? LIMIT 1');
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return;
    }
    $status = bc_inventory_item_status((int) $row['available_qty'], (int) $row['total_qty']);
    $upd = $conn->prepare('UPDATE inventory_items SET status = ? WHERE item_id = ?');
    $upd->bind_param('si', $status, $itemId);
    $upd->execute();
    $upd->close();
}
