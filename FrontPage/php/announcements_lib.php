<?php
/** Announcement transformations for the Supabase announcements table. */
require_once __DIR__ . '/supabase_client.php';

function bc_announcement_row_to_api(array $row): array
{
    $created = strtotime((string) ($row['created_at'] ?? ''));
    $updated = strtotime((string) ($row['updated_at'] ?? ''));
    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'description' => (string) ($row['content'] ?? ''),
        'type' => (string) ($row['category'] ?? 'general'),
        'status' => (string) ($row['status'] ?? (!empty($row['active']) ? 'published' : 'draft')),
        'author' => (string) ($row['author'] ?? ''),
        'announcementDate' => $row['announcement_date'] ?? null,
        'featured' => !empty($row['featured']),
        'createdAt' => $created ? date('c', $created) : null,
        'updatedAt' => $updated ? date('c', $updated) : null,
    ];
}

function bc_normalize_announcement_type(string $type): string
{
    $type = strtolower(trim($type));
    return in_array($type, ['general', 'emergency', 'event', 'maintenance', 'important'], true) ? $type : 'general';
}

function bc_normalize_announcement_status(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['published', 'draft', 'scheduled', 'archived'], true) ? $status : 'draft';
}

function bc_announcement_payload_from_body(array $body): array
{
    $date = trim((string) ($body['announcementDate'] ?? $body['announcement_date'] ?? ''));
    return [
        'title' => trim((string) ($body['title'] ?? '')),
        'content' => trim((string) ($body['description'] ?? $body['content'] ?? '')),
        'category' => bc_normalize_announcement_type((string) ($body['type'] ?? $body['category'] ?? 'general')),
        'status' => bc_normalize_announcement_status((string) ($body['status'] ?? 'draft')),
        'author' => trim((string) ($body['author'] ?? '')),
        'announcement_date' => $date !== '' ? $date : null,
        'featured' => !empty($body['featured']),
        'active' => strtolower((string) ($body['status'] ?? 'draft')) === 'published',
    ];
}

function bc_announcement_fetch_by_id(string $id): ?array
{
    $result = bc_sb_rest('announcements', 'GET', [
        'select' => '*', 'id' => 'eq.' . $id, 'limit' => '1',
    ]);
    $rows = $result['data'];
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}
