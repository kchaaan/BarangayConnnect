<?php
declare(strict_types=1);
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'success' => true,
    'email' => true,
    // Enable this only after phone auth and an SMS provider are configured in Supabase.
    'sms' => getenv('BC_SUPABASE_SMS_OTP_ENABLED') === '1',
], JSON_UNESCAPED_UNICODE);
