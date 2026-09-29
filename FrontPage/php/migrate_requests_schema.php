<?php
/**
 * One-click migration: add readable columns to service_requests and backfill from payload_json.
 * Open: http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/FrontPage/php/migrate_requests_schema.php
 */
require_once __DIR__ . '/requests_lib.php';

header('Content-Type: text/html; charset=utf-8');

$conn = bc_db_connect();
if (!$conn) {
    echo '<p style="color:red">Database connection failed.</p>';
    exit;
}

bc_ensure_requests_tables($conn);
$backfilled = bc_backfill_request_columns_from_json($conn);
$conn->close();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Migrate service_requests</title></head><body style="font-family:sans-serif;padding:2rem;">';
echo '<h1>service_requests table updated</h1>';
echo '<p>Added dedicated columns (first_name, last_name, address, etc.) and view <strong>v_service_requests_detail</strong>.</p>';
echo '<p>Backfilled <strong>' . (int) $backfilled . '</strong> existing row(s) from payload_json.</p>';
echo '<h2>In phpMyAdmin</h2>';
echo '<ol>';
echo '<li>Open database <strong>barangayconnect</strong></li>';
echo '<li>Browse table <strong>service_requests</strong> — each field has its own column</li>';
echo '<li>Or browse view <strong>v_service_requests_detail</strong> (no payload_json column)</li>';
echo '<li>Attachments remain in <strong>service_request_files</strong></li>';
echo '</ol>';
echo '<p><a href="../../database/view_database.php">View database helper</a></p>';
echo '</body></html>';
