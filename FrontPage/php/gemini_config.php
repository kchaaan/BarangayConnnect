<?php
/**
 * Gemini API settings — key is loaded from env or gemini_secrets.local.php (not committed).
 */
if (defined('BC_GEMINI_CONFIG_LOADED')) {
    return;
}
define('BC_GEMINI_CONFIG_LOADED', true);

require_once __DIR__ . '/db_config.php';

$localSecrets = __DIR__ . '/gemini_secrets.local.php';
if (is_file($localSecrets)) {
    require_once $localSecrets;
}

if (!defined('BC_GEMINI_API_KEY')) {
    define('BC_GEMINI_API_KEY', bc_env_or_default('BC_GEMINI_API_KEY', ''));
}

if (!defined('BC_GEMINI_MODEL')) {
    define('BC_GEMINI_MODEL', bc_env_or_default('BC_GEMINI_MODEL', 'gemini-2.5-flash'));
}

if (!defined('BC_GEMINI_ENABLED')) {
    $enabled = bc_env_or_default('BC_GEMINI_ENABLED', '');
    if ($enabled === '') {
        define('BC_GEMINI_ENABLED', BC_GEMINI_API_KEY !== '');
    } else {
        define('BC_GEMINI_ENABLED', in_array(strtolower($enabled), ['1', 'true', 'yes'], true));
    }
}

function bc_gemini_is_configured(): bool
{
    return BC_GEMINI_ENABLED && trim((string) BC_GEMINI_API_KEY) !== '';
}
