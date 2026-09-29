<?php
/**
 * Simple database browser (when phpMyAdmin is hard to open).
 * http://localhost/BarangayConnect-ITG6-main/BarangayConnec-G6-Main/database/view_database.php
 */
header('Content-Type: text/html; charset=utf-8');

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbName = 'barangayconnect';
$port = 3306;

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>BarangayConnect — Database</title>';
echo '<style>
body{font-family:Segoe UI,Arial,sans-serif;margin:24px;background:#f4f6f8;color:#222}
.box{max-width:1100px;margin:0 auto;background:#fff;padding:24px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h1{color:#0b5ed7;margin-top:0}
.ok{color:#198754}.err{color:#c00;background:#fee;padding:12px;border-radius:6px}
table{width:100%;border-collapse:collapse;margin:16px 0;font-size:14px}
th,td{border:1px solid #ddd;padding:8px;text-align:left}
th{background:#0b5ed7;color:#fff}
a{color:#0b5ed7}
.tip{background:#e7f1ff;padding:12px;border-radius:6px;margin:16px 0}
code{background:#eee;padding:2px 6px;border-radius:4px}
</style></head><body><div class="box">';

echo '<h1>BarangayConnect database (XAMPP)</h1>';

if (!extension_loaded('mysqli')) {
    echo '<p class="err"><strong>mysqli is off.</strong> XAMPP Control Panel → Apache Config → php.ini → uncomment <code>extension=mysqli</code> → restart Apache.</p></div></body></html>';
    exit;
}

try {
    $conn = new mysqli($host, $user, $pass, $dbName, $port);
} catch (Throwable $e) {
    echo '<p class="err"><strong>Cannot connect to MySQL.</strong><br>' . h($e->getMessage()) . '</p>';
    echo '<div class="tip"><strong>Fix:</strong><ol>';
    echo '<li>Open <strong>XAMPP Control Panel</strong></li>';
    echo '<li>Click <strong>Start</strong> next to <strong>MySQL</strong> (and Apache)</li>';
    echo '<li>Refresh this page</li>';
    echo '<li>Or run <code>database\\install_xampp.bat</code></li></ol></div>';
    echo '</div></body></html>';
    exit;
}

if ($conn->connect_error) {
    echo '<p class="err">' . h($conn->connect_error) . '</p></div></body></html>';
    exit;
}

echo '<p class="ok"><strong>Connected.</strong> Database <code>' . h($dbName) . '</code> on <code>127.0.0.1</code> (user <code>root</code>, no password).</p>';

echo '<div class="tip"><strong>phpMyAdmin (full editor):</strong><br>';
echo '<a href="http://127.0.0.1/phpmyadmin/index.php?route=/database/structure&amp;db=barangayconnect" target="_blank">Open phpMyAdmin → barangayconnect</a><br>';
echo 'If that fails, use this page or run <code>database/install_xampp.bat</code>.</div>';
echo '<div class="tip"><strong>Readable tables in phpMyAdmin:</strong> ';
echo 'Residents → <code>v_users_resident_detail</code> (<a href="../FrontPage/php/migrate_users_schema.php">migrate users</a>). ';
echo 'Requests → <code>v_service_requests_detail</code> (<a href="../FrontPage/php/migrate_requests_schema.php">migrate requests</a>).</div>';

$tables = ['admins', 'v_users_resident_detail', 'users_resident', 'v_service_requests_detail', 'service_request_files'];
foreach ($tables as $table) {
    $safe = $conn->real_escape_string($table);
    $check = $conn->query("SHOW TABLES LIKE '$safe'");
    if (!$check || $check->num_rows === 0) {
        echo '<h2>' . h($table) . '</h2><p class="err">Table missing. <a href="../FrontPage/php/setup_database.php">Run setup</a></p>';
        continue;
    }

    $countRes = $conn->query("SELECT COUNT(*) AS c FROM `$safe`");
    $count = $countRes ? (int) $countRes->fetch_assoc()['c'] : 0;

    echo '<h2>Table: ' . h($table) . ' <small>(' . $count . ' rows)</small></h2>';

    $res = $conn->query("SELECT * FROM `$safe` ORDER BY 1 DESC LIMIT 50");
    if (!$res) {
        echo '<p class="err">' . h($conn->error) . '</p>';
        continue;
    }

    if ($res->num_rows === 0) {
        echo '<p><em>No rows yet.</em></p>';
        continue;
    }

    echo '<div style="overflow-x:auto"><table><thead><tr>';
    $fields = $res->fetch_fields();
    foreach ($fields as $f) {
        echo '<th>' . h($f->name) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $res->data_seek(0);
    while ($row = $res->fetch_assoc()) {
        echo '<tr>';
        foreach ($row as $col => $val) {
            if ($col === 'password') {
                $val = '[hidden]';
            }
            echo '<td>' . h($val) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

$conn->close();

echo '<p><a href="../FrontPage/html/Front.html">← Back to website</a> · ';
echo '<a href="../FrontPage/php/connection_test.php">Connection test (JSON)</a> · ';
echo '<a href="../FrontPage/php/setup_database.php">Setup / repair database</a></p>';
echo '</div></body></html>';
