<?php
/**
 * Expand users table with readable columns; backfill from profile_extra JSON.
 * http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/FrontPage/php/migrate_users_schema.php
 */
require_once __DIR__ . '/users_lib.php';

header('Content-Type: text/html; charset=utf-8');

$conn = bc_db_connect();
if (!$conn) {
    echo '<p style="color:red">Database connection failed.</p>';
    exit;
}

bc_upgrade_users_schema($conn);
$backfilled = bc_backfill_users_from_profile_extra($conn);
$conn->close();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Migrate users</title></head><body style="font-family:sans-serif;padding:2rem;">';
echo '<h1>users table updated</h1>';
echo '<p>Added dedicated columns for registration details (religion, place of birth, employment, etc.).</p>';
echo '<p>Backfilled <strong>' . (int) $backfilled . '</strong> row(s) from <code>profile_extra</code> JSON.</p>';
echo '<h2>In phpMyAdmin</h2>';
echo '<ol>';
echo '<li>Open database <strong>barangayconnect</strong></li>';
echo '<li>Browse view <strong>v_users_resident_detail</strong> (all fields, no JSON blob)</li>';
echo '<li>Or browse table <strong>users_resident</strong> — scroll to new columns</li>';
echo '<li><code>profile_extra</code> is kept as backup only (like <code>payload_json</code> on requests)</li>';
echo '</ol>';
echo '<p><a href="migrate_requests_schema.php">Also migrate service_requests</a> · ';
echo '<a href="../../database/view_database.php">Database browser</a></p>';
echo '</body></html>';
