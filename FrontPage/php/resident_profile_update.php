<?php
/**
 * Legacy alias — updates are handled by resident_profile.php (POST/PUT).
 */
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';
require __DIR__ . '/resident_profile.php';
