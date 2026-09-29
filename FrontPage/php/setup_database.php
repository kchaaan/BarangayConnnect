<?php
declare(strict_types=1);
require_once __DIR__ . '/supabase_client.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    bc_sb_env('SUPABASE_URL');
    bc_sb_env('SUPABASE_ANON_KEY');
    bc_sb_env('SUPABASE_SERVICE_ROLE_KEY');
    $result = bc_sb_rest('profiles', 'GET', ['select' => 'id', 'limit' => '1']);
    echo json_encode(['ok' => true, 'backend' => 'supabase', 'configured' => true, 'database_reachable' => $result['status'] === 200]);
} catch (Throwable $e) {
    http_response_code(503);
    error_log('Supabase setup check: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'backend' => 'supabase', 'configured' => false, 'error' => 'Supabase is not configured or the target schema has not been installed.']);
}
