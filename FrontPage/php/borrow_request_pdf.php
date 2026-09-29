<?php
declare(strict_types=1);
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/inventory_lib.php';
require_once __DIR__ . '/supabase_client.php';

$session = bc_sb_require_role(['resident']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Invalid request data.']);
    exit;
}

function bc_pdf_ascii(string $value): string
{
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted)) $value = $converted;
    }
    $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function bc_pdf_text(string $text, float $x, float $top, int $size = 9, bool $bold = false): string
{
    $font = $bold ? 'F2' : 'F1';
    $y = 420 - $top;
    return "BT /{$font} {$size} Tf 1 0 0 1 {$x} {$y} Tm (" . bc_pdf_ascii($text) . ") Tj ET\n";
}

function bc_pdf_line(float $x1, float $top1, float $x2, float $top2): string
{
    return ("{$x1} " . (420 - $top1) . " m {$x2} " . (420 - $top2) . " l S\n");
}

function bc_pdf_brand_mark(float $x, float $top): string
{
    $cmd = "q 0.16 0.38 0.24 RG 1 w\n";
    foreach ([
        [0, 8, 4, 4], [4, 4, 8, 8], [0, 8, 0, 15], [8, 8, 8, 15], [0, 15, 8, 15],
        [8, 8, 13, 3], [13, 3, 18, 8], [8, 8, 8, 15], [18, 8, 18, 15], [8, 15, 18, 15],
        [18, 8, 22, 4], [22, 4, 26, 8], [18, 8, 18, 15], [26, 8, 26, 15], [18, 15, 26, 15],
        [3, 12, 5, 12], [5, 12, 5, 15], [11, 11, 14, 11], [14, 11, 14, 15], [21, 12, 23, 12], [23, 12, 23, 15],
    ] as [$x1, $y1, $x2, $y2]) {
        $cmd .= bc_pdf_line($x + $x1, $top + $y1, $x + $x2, $top + $y2);
    }
    return $cmd . "Q\n";
}

function bc_pdf_wrapped(string $value, int $width, int $maxLines = 3): array
{
    $plain = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($plain === '') return ['—'];
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $plain);
        if (is_string($converted)) $plain = $converted;
    }
    $lines = explode("\n", wordwrap($plain, $width, "\n", true));
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $lines[$maxLines - 1] = rtrim(substr($lines[$maxLines - 1], 0, max(1, $width - 3))) . '...';
    }
    return $lines;
}

function bc_pdf_field(string $label, string $value, float $top, int $maxLines = 2): array
{
    $commands = bc_pdf_text(strtoupper($label), 22, $top, 6, true);
    $lines = bc_pdf_wrapped($value, 36, $maxLines);
    foreach ($lines as $i => $line) $commands .= bc_pdf_text($line, 22, $top + 10 + ($i * 10), 8);
    return [$commands, $top + 15 + (max(1, count($lines)) - 1) * 10];
}

function bc_pdf_format_datetime(string $value): string
{
    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
    if ($date instanceof DateTime) return $date->format('M j, Y - g:i A');
    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y - g:i A', $timestamp) : $value;
}

function bc_build_compact_borrow_pdf(array $data): string
{
    $cmd = "0.25 w\n";
    $cmd .= "q 40 0 0 40 22 367 cm /Im1 Do Q\n";
    $cmd .= "q 0.16 0.38 0.24 RG 0.8 w 22 367 40 40 re S Q\n";
    $cmd .= "0.16 0.38 0.24 rg\n";
    $cmd .= bc_pdf_text('BARANGAY TAMBO', 70, 25, 12, true);
    $cmd .= "0 0 0 rg\n";
    $cmd .= bc_pdf_brand_mark(70, 31);
    $cmd .= bc_pdf_text('BARANGAYCONNECT  |  EQUIPMENT BORROW REQUEST', 101, 40, 6, true);
    $cmd .= bc_pdf_line(22, 57, 276, 57);
    $cmd .= bc_pdf_text('REQUEST COPY - SUBJECT TO BARANGAY APPROVAL', 22, 68, 6, true);
    $cmd .= bc_pdf_text('Prepared: ' . date('M d, Y h:i A'), 22, 78, 7);
    $cmd .= bc_pdf_line(22, 86, 276, 86);

    $top = 97;
    foreach ([
        ['Item', $data['item'], 2], ['Category', $data['category'], 1],
        ['Quantity', $data['quantity'], 1], ['Purpose', $data['purpose'], 3],
        ['Pickup schedule', $data['pickup'], 1], ['Expected return', $data['return'], 1],
        ['Event / activity', $data['event'], 2], ['Venue', $data['location'], 2],
        ['Resident', $data['resident'], 1], ['Resident ID', $data['residentCode'], 1],
        ['Contact number', $data['contact'], 1], ['Notes', $data['notes'], 2],
    ] as [$label, $value, $maxLines]) {
        [$fieldCmd, $nextTop] = bc_pdf_field($label, (string) $value, $top, (int) $maxLines);
        $cmd .= $fieldCmd;
        $top = $nextTop + 3;
    }

    $signTop = max(333, min(360, $top + 10));
    $cmd .= bc_pdf_line(22, $signTop, 134, $signTop);
    $cmd .= bc_pdf_line(164, $signTop, 276, $signTop);
    $cmd .= bc_pdf_text('Applicant signature / date', 22, $signTop + 10, 6);
    $cmd .= bc_pdf_text('Barangay staff / date', 164, $signTop + 10, 6);
    $cmd .= bc_pdf_line(22, 398, 276, 398);
    $cmd .= bc_pdf_text('Bring a valid ID. This slip is not an approval or release permit.', 22, 409, 6);

    $stream = function_exists('gzcompress') ? gzcompress($cmd, 9) : false;
    $compressed = is_string($stream);
    if (!$compressed) $stream = $cmd;
    $filter = $compressed ? '/Filter /FlateDecode ' : '';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 298 420] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> /XObject << /Im1 7 0 R >> >> /Contents 6 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        "<< {$filter}/Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
    ];
    $sealPath = dirname(__DIR__, 2) . '/Shared/images/barangay-tambo-seal.jpg';
    $sealBytes = @file_get_contents($sealPath);
    if (!is_string($sealBytes) || $sealBytes === '') {
        throw new RuntimeException('The Barangay Tambo seal image is missing.');
    }
    $objects[] = "<< /Type /XObject /Subtype /Image /Width 180 /Height 180 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($sealBytes) . " >>\nstream\n" . $sealBytes . "\nendstream";
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    $pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    return $pdf;
}

$itemId = filter_var($body['itemId'] ?? null, FILTER_VALIDATE_INT);
$quantity = filter_var($body['quantity'] ?? null, FILTER_VALIDATE_INT);
$purpose = trim((string) ($body['purpose'] ?? ''));
$pickup = trim((string) ($body['dateNeeded'] ?? ''));
$returnDate = trim((string) ($body['returnDate'] ?? ''));
if (!$itemId || !$quantity || $quantity < 1 || $purpose === '' || $pickup === '' || $returnDate === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Complete the required borrow details before creating the PDF.']);
    exit;
}

$conn = bc_db_connect();
if (!$conn) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => bc_db_error_response()]);
    exit;
}
bc_ensure_inventory_tables($conn);
$itemStmt = $conn->prepare('SELECT i.name, i.unit, i.available_qty, c.name AS category FROM inventory_items i LEFT JOIN inventory_categories c ON c.category_id = i.category_id WHERE i.item_id = ? LIMIT 1');
$itemStmt->bind_param('i', $itemId);
$itemStmt->execute();
$item = $itemStmt->get_result()->fetch_assoc();
$itemStmt->close();
$conn->close();
if (!$item || (int) $item['available_qty'] < $quantity) {
    http_response_code(409);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'The selected item or requested quantity is no longer available.']);
    exit;
}

$resident = [];
try {
    $residentResult = bc_sb_rest('residents', 'GET', [
        'user_id' => 'eq.' . (string) $session['user_id'],
        'select' => 'resident_id,first_name,middle_name,last_name,contact_number',
        'limit' => '1',
    ]);
    $resident = $residentResult['data'][0] ?? [];
} catch (Throwable $e) {
    error_log('Borrow PDF could not load resident: ' . $e->getMessage());
}
if (!$resident) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Your resident profile is missing. Please sign in again.']);
    exit;
}

$fullName = trim(implode(' ', array_filter([
    $resident['first_name'] ?? '', $resident['middle_name'] ?? '', $resident['last_name'] ?? '',
])));
$data = [
    'item' => (string) $item['name'],
    'category' => (string) ($item['category'] ?? 'General'),
    'quantity' => $quantity . ' ' . (string) ($item['unit'] ?? 'pcs'),
    'purpose' => $purpose,
    'pickup' => bc_pdf_format_datetime($pickup),
    'return' => bc_pdf_format_datetime($returnDate),
    'event' => trim((string) ($body['event'] ?? '')) . (trim((string) ($body['eventAt'] ?? '')) !== '' ? ' - ' . bc_pdf_format_datetime(trim((string) $body['eventAt'])) : ''),
    'location' => trim((string) ($body['location'] ?? '')),
    'resident' => $fullName,
    'residentCode' => (string) ($resident['resident_id'] ?? ''),
    'contact' => trim((string) ($resident['contact_number'] ?? '')) ?: trim((string) ($body['contact'] ?? '')),
    'notes' => trim((string) ($body['remarks'] ?? '')),
];
$pdf = bc_build_compact_borrow_pdf($data);
$filename = 'borrow-request-' . date('Ymd-His') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store, max-age=0');
echo $pdf;
