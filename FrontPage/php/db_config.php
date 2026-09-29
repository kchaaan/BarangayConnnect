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
 * Local XAMPP defaults match database/install_xampp.bat. BC_DB_* env vars override them.
 */
if (!defined('BC_DB_CONFIG_LOADED')) {
    define('BC_DB_CONFIG_LOADED', true);
    define('BC_DB_HOST', getenv('BC_DB_HOST') ?: '127.0.0.1');
    define('BC_DB_PORT', (int) (getenv('BC_DB_PORT') ?: 3306));
    define('BC_DB_USER', getenv('BC_DB_USER') ?: 'root');
    define('BC_DB_PASS', getenv('BC_DB_PASS') ?: '');
    define('BC_DB_NAME', getenv('BC_DB_NAME') ?: 'barangayconnect');
    $GLOBALS['BC_DB_LAST_ERROR'] = null;
}

/** @return mysqli|null */
function bc_db_connect()
{
    $GLOBALS['BC_DB_LAST_ERROR'] = null;
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
    $message = 'MySQL connection failed. Start MySQL in XAMPP and make sure the barangayconnect database is installed.';
    $detail = (string) ($GLOBALS['BC_DB_LAST_ERROR'] ?? '');
    if ($detail !== '' && (getenv('BC_DEBUG') === '1' || in_array(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), ['localhost', 'localhost:8000', '127.0.0.1:8000'], true))) {
        $message .= ' (' . $detail . ')';
    }
    return $message;
}
