<?php
/**
 * Admin accounts — schema upgrades and strict uniqueness validation.
 */
require_once __DIR__ . '/db_config.php';

function bc_admins_table(): string
{
    return 'admins';
}

function bc_admin_identity_columns(): array
{
    return [
        'employee_id' => 'VARCHAR(32) NULL',
        'staff_id' => 'VARCHAR(32) NULL',
    ];
}

function bc_normalize_admin_email(?string $email): string
{
    return strtolower(trim((string) $email));
}

function bc_normalize_admin_id(?string $value): string
{
    $v = strtoupper(trim((string) $value));
    $v = preg_replace('/[\s\-]+/', '', $v) ?? $v;

    return $v;
}

function bc_upgrade_admins_schema(mysqli $conn): void
{
    $table = bc_admins_table();
    $exists = $conn->query("SHOW TABLES LIKE '{$table}'");
    if (!$exists || $exists->num_rows === 0) {
        return;
    }

    foreach (bc_admin_identity_columns() as $name => $definition) {
        $safe = $conn->real_escape_string($name);
        $check = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safe}'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN {$name} {$definition}");
        }
    }

    require_once __DIR__ . '/users_lib.php';
    bc_users_try_add_unique_index($conn, $table, 'uk_admins_email', 'email');
    bc_users_try_add_unique_index($conn, $table, 'uk_admins_employee_id', 'employee_id');
    bc_users_try_add_unique_index($conn, $table, 'uk_admins_staff_id', 'staff_id');
}

/**
 * @param array<string, mixed> $data
 * @return array{ok:bool,error:?string,http_status:int}
 */
function bc_check_admin_registration_conflicts(mysqli $conn, array $data, ?int $excludeAdminId = null): array
{
    $table = bc_admins_table();
    $username = trim((string) ($data['username'] ?? ''));
    $email = bc_normalize_admin_email($data['email'] ?? '');
    $employeeId = bc_normalize_admin_id($data['employee_id'] ?? '');
    $staffId = bc_normalize_admin_id($data['staff_id'] ?? '');

    if ($username === '') {
        return ['ok' => false, 'error' => 'Username is required', 'http_status' => 400];
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'A valid official barangay email address is required', 'http_status' => 400];
    }

    if ($employeeId === '' && $staffId === '') {
        return ['ok' => false, 'error' => 'Employee ID or official staff ID number is required', 'http_status' => 400];
    }

    $checks = [
        ['column' => 'username', 'value' => $username, 'label' => 'Admin username is already registered'],
        ['column' => 'email', 'value' => $email, 'label' => 'This official barangay email is already registered', 'lower' => true],
        ['column' => 'employee_id', 'value' => $employeeId, 'label' => 'This Employee ID is already registered'],
        ['column' => 'staff_id', 'value' => $staffId, 'label' => 'This official staff ID is already registered'],
    ];

    foreach ($checks as $check) {
        $value = (string) $check['value'];
        if ($value === '') {
            continue;
        }

        $column = $check['column'];
        if (!empty($check['lower'])) {
            $sql = "SELECT admin_id FROM `{$table}` WHERE LOWER(TRIM(`{$column}`)) = ?";
        } else {
            $sql = "SELECT admin_id FROM `{$table}` WHERE `{$column}` = ?";
        }
        if ($excludeAdminId !== null && $excludeAdminId > 0) {
            $sql .= ' AND admin_id <> ?';
        }
        $sql .= ' LIMIT 1';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Server error (admin duplicate check)', 'http_status' => 500];
        }

        if ($excludeAdminId !== null && $excludeAdminId > 0) {
            $stmt->bind_param('si', $value, $excludeAdminId);
        } else {
            $stmt->bind_param('s', $value);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows > 0;
        $stmt->close();

        if ($exists) {
            return ['ok' => false, 'error' => (string) $check['label'], 'http_status' => 409];
        }
    }

    return ['ok' => true, 'error' => null, 'http_status' => 200];
}
