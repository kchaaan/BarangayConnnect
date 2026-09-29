<?php
declare(strict_types=1);
require_once __DIR__ . '/supabase_client.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$localTestMode = bc_sb_is_loopback_request() && strtolower((string)(getenv('TURNSTILE_LOCAL_TEST_MODE') ?: 'true')) !== 'false';
echo json_encode([
    'siteKey' => $localTestMode ? '' : (getenv('TURNSTILE_SITE_KEY') ?: ''),
    'localTestMode' => $localTestMode,
], JSON_UNESCAPED_SLASHES);
