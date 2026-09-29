<?php
/** Emergency contacts and procedures transformations for Supabase. */
require_once __DIR__ . '/supabase_client.php';

function bc_emergency_icon_for_service(string $name): string
{
    $s = strtolower(trim($name));
    foreach ([['medical', 'fa-ambulance'], ['ambulance', 'fa-ambulance'], ['fire', 'fa-fire'], ['police', 'fa-shield-alt'], ['barangay', 'fa-building'], ['red cross', 'fa-heart'], ['public works', 'fa-tools']] as [$needle, $icon]) {
        if (str_contains($s, $needle)) return $icon;
    }
    return 'fa-phone';
}

function bc_emergency_icon_for_procedure(string $title): string
{
    $t = strtolower(trim($title));
    foreach ([['fire', 'fa-fire'], ['burn', 'fa-fire'], ['medical', 'fa-first-aid'], ['injury', 'fa-first-aid'], ['earthquake', 'fa-house-damage'], ['quake', 'fa-house-damage'], ['flood', 'fa-water'], ['water', 'fa-water'], ['storm', 'fa-cloud-showers-heavy'], ['typhoon', 'fa-cloud-showers-heavy']] as [$needle, $icon]) {
        if (str_contains($t, $needle)) return $icon;
    }
    return 'fa-exclamation-circle';
}

function bc_emergency_format_updated(?string $datetime): string
{
    if (!$datetime || !($ts = strtotime($datetime))) return '';
    return date('F j, Y', $ts);
}

function bc_emergency_contact_row_to_api(array $row): array
{
    $updated = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');
    return [
        'id' => (string) ($row['id'] ?? ''),
        'serviceName' => (string) ($row['name'] ?? ''),
        'contactNumber' => (string) ($row['contact_number'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'icon' => (string) ($row['icon'] ?? 'fa-phone'),
        'isCritical' => !empty($row['is_critical']),
        'sortOrder' => (int) ($row['sort_order'] ?? 0),
        'lastUpdated' => bc_emergency_format_updated($updated),
        'updatedAt' => $updated && strtotime($updated) ? date('c', strtotime($updated)) : null,
    ];
}

function bc_emergency_steps_from_body(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map(static fn($s) => trim((string) $s), $raw), static fn($s) => $s !== ''));
    }
    if (is_string($raw)) {
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) return bc_emergency_steps_from_body($decoded);
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
    }
    return [];
}

function bc_emergency_procedure_row_to_api(array $row): array
{
    $updated = (string) ($row['updated_at'] ?? '');
    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'steps' => bc_emergency_steps_from_body($row['steps'] ?? []),
        'icon' => (string) ($row['icon'] ?? 'fa-exclamation-circle'),
        'sortOrder' => (int) ($row['sort_order'] ?? 0),
        'updatedAt' => $updated && strtotime($updated) ? date('c', strtotime($updated)) : null,
    ];
}

function bc_emergency_contact_fetch_by_id(string $id): ?array
{
    $result = bc_sb_rest('emergency_contacts', 'GET', ['select' => '*', 'id' => 'eq.' . $id, 'limit' => '1']);
    return is_array($result['data']) && isset($result['data'][0]) ? $result['data'][0] : null;
}

function bc_emergency_procedure_fetch_by_id(string $id): ?array
{
    $result = bc_sb_rest('emergency_procedures', 'GET', ['select' => '*', 'id' => 'eq.' . $id, 'limit' => '1']);
    return is_array($result['data']) && isset($result['data'][0]) ? $result['data'][0] : null;
}
