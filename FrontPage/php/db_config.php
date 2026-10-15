<?php
/** Server-only configuration. Existing process environment variables take precedence over .env. */
if (!defined('BC_SUPABASE_CONFIG_LOADED')) {
    define('BC_SUPABASE_CONFIG_LOADED', true);
    $bcEnvFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (is_file($bcEnvFile) && is_readable($bcEnvFile)) {
        foreach (file($bcEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $bcEnvLine) {
            $bcEnvLine = trim($bcEnvLine);
            if ($bcEnvLine === '' || str_starts_with($bcEnvLine, '#')) continue;
            $bcSeparator = strpos($bcEnvLine, '=');
            if ($bcSeparator === false) continue;
            $bcName = trim(substr($bcEnvLine, 0, $bcSeparator));
            $bcValue = trim(substr($bcEnvLine, $bcSeparator + 1));
            if ($bcName === '' || getenv($bcName) !== false) continue;
            if (strlen($bcValue) >= 2 && (($bcValue[0] === '"' && str_ends_with($bcValue, '"')) || ($bcValue[0] === "'" && str_ends_with($bcValue, "'")))) {
                $bcValue = substr($bcValue, 1, -1);
            }
            putenv($bcName . '=' . $bcValue);
            $_ENV[$bcName] = $bcValue;
        }
    }
    unset($bcEnvFile, $bcEnvLine, $bcSeparator, $bcName, $bcValue);
}
function bc_supabase_configured(): bool { return (bool)(getenv('SUPABASE_URL') && getenv('SUPABASE_ANON_KEY') && getenv('SUPABASE_SERVICE_ROLE_KEY')); }
function bc_supabase_config_status(): array { return ['configured'=>(bool)bc_supabase_configured(),'storage'=>function_exists('curl_init'),'turnstile'=>(bool)getenv('TURNSTILE_SECRET_KEY')]; }

/**
 * Legacy resident/admin data APIs still use the local MySQL database.
 * Supabase currently handles Auth; it does not replace these MySQL endpoints yet.
 * Hosted MySQL add-ons (including Clever Cloud) expose MYSQL_ADDON_* variables.
 * Explicit BC_DB_* values take precedence; XAMPP defaults are local-only.
 */
if (!defined('BC_DB_CONFIG_LOADED')) {
    define('BC_DB_CONFIG_LOADED', true);
    $bcHttpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $bcIsLocalHost = in_array($bcHttpHost, ['localhost', 'localhost:8000', '127.0.0.1', '127.0.0.1:8000'], true);
    define('BC_DB_HOST', getenv('BC_DB_HOST') ?: (getenv('MYSQL_ADDON_HOST') ?: ($bcIsLocalHost ? '127.0.0.1' : '')));
    define('BC_DB_PORT', (int) (getenv('BC_DB_PORT') ?: (getenv('MYSQL_ADDON_PORT') ?: 3306)));
    define('BC_DB_USER', getenv('BC_DB_USER') ?: (getenv('MYSQL_ADDON_USER') ?: ($bcIsLocalHost ? 'root' : '')));
    define('BC_DB_PASS', getenv('BC_DB_PASS') ?: (getenv('MYSQL_ADDON_PASSWORD') ?: ''));
    define('BC_DB_NAME', getenv('BC_DB_NAME') ?: (getenv('MYSQL_ADDON_DB') ?: ($bcIsLocalHost ? 'barangayconnect' : '')));
    unset($bcHttpHost, $bcIsLocalHost);
    $GLOBALS['BC_DB_LAST_ERROR'] = null;
}

/** @return mysqli|null */
function bc_db_connect()
{
    $GLOBALS['BC_DB_LAST_ERROR'] = null;
    if (BC_DB_HOST === '' || BC_DB_USER === '' || BC_DB_NAME === '') {
        $GLOBALS['BC_DB_LAST_ERROR'] = 'Database host, user, or database name is not configured.';
        return null;
    }
    if (!extension_loaded('mysqli')) {
        $GLOBALS['BC_DB_LAST_ERROR'] = 'PHP mysqli extension is not enabled.';
        return null;
    }

    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = new mysqli(BC_DB_HOST, BC_DB_USER, BC_DB_PASS, BC_DB_NAME, BC_DB_PORT);
    } catch (Throwable $e) {
        $GLOBALS['BC_DB_LAST_ERROR'] = $e->getMessage();
        return null;
    }
    if ($conn->connect_error) {
        $GLOBALS['BC_DB_LAST_ERROR'] = $conn->connect_error;
        return null;
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function bc_db_error_response(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $isLocal = in_array($host, ['localhost', 'localhost:8000', '127.0.0.1', '127.0.0.1:8000'], true);
    $isConfigured = BC_DB_HOST !== '' && BC_DB_USER !== '' && BC_DB_NAME !== '';
    $message = !$isConfigured
        ? 'Database is not configured. Link the hosting MySQL add-on or set the BC_DB_* environment variables.'
        : ($isLocal
            ? 'MySQL connection failed. Start MySQL in XAMPP and make sure the barangayconnect database is installed.'
            : 'MySQL connection failed. Check the hosting database add-on and its environment variables.');
    $detail = (string) ($GLOBALS['BC_DB_LAST_ERROR'] ?? '');
    if ($detail !== '' && (getenv('BC_DEBUG') === '1' || $isLocal)) {
        $message .= ' (' . $detail . ')';
    }
    return $message;
}
